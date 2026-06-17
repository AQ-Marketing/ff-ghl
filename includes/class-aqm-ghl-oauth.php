<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth flow handler for the AQM GHL Marketplace App (Sub-Account target).
 *
 * Per-install flow:
 *   1. Admin pastes client_secret in settings.
 *   2. Admin clicks "Connect to GoHighLevel" → we redirect to GHL's choose-location screen.
 *   3. Admin picks the sub-account and clicks Allow.
 *   4. GHL redirects back to admin-ajax.php?action=aqm_oauth_callback with `code` and `locationId`.
 *   5. We exchange the code for access_token + refresh_token (good for ~24h / ~1 year respectively).
 *   6. Tokens are stored in settings. Future API calls go through aqm_ghl_get_oauth_access_token()
 *      which transparently refreshes when nearing expiry.
 *
 * Why sub-account target (not agency)? Empirically confirmed: GHL only exposes
 * the scopes we need (contacts/workflows/customFields) for sub-account-target
 * Marketplace Apps. Agency-target apps cannot declare those scopes at all, and
 * Agency PITs can neither hit sub-account endpoints directly nor exchange for
 * sub-account tokens via /oauth/locationToken (that requires Agency-Access-Only
 * OAuth, not PITs). The only path that grants the scopes we need is OAuth into
 * a sub-account install, which is what this class implements.
 */
class AQM_GHL_OAuth {

	const REFRESH_BUFFER_SECONDS = 300; // Refresh access_token 5 min before expiry.

	public function __construct() {
		add_action( 'admin_post_aqm_oauth_start',                  array( $this, 'handle_start' ) );
		add_action( 'wp_ajax_'        . AQM_GHL_OAUTH_CALLBACK_ACTION, array( $this, 'handle_callback' ) );
		add_action( 'wp_ajax_nopriv_' . AQM_GHL_OAUTH_CALLBACK_ACTION, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_aqm_oauth_disconnect',             array( $this, 'handle_disconnect' ) );
	}

	/**
	 * The redirect URI sent to GHL in BOTH the authorize request and the token
	 * exchange (OAuth requires them to match). This is the central broker — the
	 * single URL registered in the GHL Marketplace app — NOT this site's own
	 * domain. The broker forwards the code back to this site's callback (URL
	 * carried in the signed `state`). See AQM_GHL_OAUTH_REDIRECT_URI.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		// Per-site override: if a site has its OWN callback URL already approved
		// in the GHL app's redirect list, it can skip the broker and use that
		// directly (set settings['oauth_redirect_uri']). Defaults to the broker
		// for everyone else. The signed-state verification in handle_callback is
		// identical either way, so this is safe for both modes.
		$settings = function_exists( 'aqm_ghl_get_settings' ) ? aqm_ghl_get_settings() : array();
		$override = isset( $settings['oauth_redirect_uri'] ) ? trim( (string) $settings['oauth_redirect_uri'] ) : '';
		if ( '' !== $override ) {
			return $override;
		}
		/**
		 * Filter the OAuth redirect URI (advanced; normally the broker).
		 *
		 * @param string $uri The redirect URI sent to GHL for authorize + token exchange.
		 */
		return (string) apply_filters( 'aqm_ghl_oauth_redirect_uri', AQM_GHL_OAUTH_REDIRECT_URI );
	}

	/**
	 * This specific site's callback endpoint. GHL never redirects here directly
	 * (its registered redirect is the broker); the broker forwards here after
	 * verifying the signed state. Travels inside the OAuth `state` payload.
	 *
	 * @return string
	 */
	public static function get_site_callback_uri() {
		return admin_url( 'admin-ajax.php?action=' . AQM_GHL_OAUTH_CALLBACK_ACTION );
	}

	/**
	 * URL-safe base64 (no padding) — matches the broker's encoding so HMAC
	 * signatures and payloads round-trip identically on both sides.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private static function b64url_encode( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Inverse of b64url_encode().
	 *
	 * @param string $s Encoded string.
	 * @return string Raw bytes ('' on failure).
	 */
	private static function b64url_decode( $s ) {
		$s   = strtr( $s, '-_', '+/' );
		$pad = strlen( $s ) % 4;
		if ( $pad ) {
			$s .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $s, true );
		return false === $decoded ? '' : $decoded;
	}

	/**
	 * HMAC-SHA256 of the payload using the shared client_secret, base64url'd.
	 * The broker recomputes this to prove the state wasn't tampered with, so it
	 * can't be abused as an open redirector.
	 *
	 * @param string $payload_b64   The base64url JSON payload.
	 * @param string $client_secret Shared marketplace app secret.
	 * @return string
	 */
	private static function sign_state( $payload_b64, $client_secret ) {
		return self::b64url_encode( hash_hmac( 'sha256', $payload_b64, self::derive_signing_key( $client_secret ), true ) );
	}

	/**
	 * Derive the broker's state-signing key from the raw client_secret. The
	 * broker holds ONLY this derived key (least privilege): it's sufficient to
	 * verify state signatures but useless for exchanging OAuth codes for tokens
	 * (which requires the raw client_secret, kept on the WP sites only). So a
	 * broker compromise cannot be escalated into token theft.
	 *
	 * @param string $client_secret Raw marketplace app secret.
	 * @return string Raw 32-byte key.
	 */
	private static function derive_signing_key( $client_secret ) {
		return hash_hmac( 'sha256', AQM_GHL_OAUTH_STATE_KEY_CONTEXT, $client_secret, true );
	}

	/**
	 * Build the authorize URL the "Connect" button redirects to.
	 *
	 * @param string $state Random CSRF token, stored briefly for verification on callback.
	 *
	 * @return string
	 */
	public static function build_authorize_url( $state ) {
		// IMPORTANT: build the query string by hand with rawurlencode() on every
		// value. We must NOT use add_query_arg() here — WordPress builds its query
		// string with urlencoding disabled (build_query() passes $urlencode=false),
		// so it would drop redirect_uri in raw. Because redirect_uri itself contains
		// a nested query string (…/admin-ajax.php?action=aqm_oauth_callback), GHL
		// then parses that inner "?action=…" as its OWN query parameter and
		// truncates redirect_uri to "…/admin-ajax.php" — which breaks the chooser
		// page load and the OAuth callback. rawurlencode() percent-encodes the
		// nested "?" / "=" / "/" so the whole redirect_uri arrives intact, and
		// encodes the space-delimited scope as %20 (OAuth-spec correct).
		$params = array(
			'response_type' => 'code',
			'redirect_uri'  => self::get_redirect_uri(),
			'client_id'     => AQM_GHL_OAUTH_CLIENT_ID,
			'scope'         => AQM_GHL_OAUTH_SCOPES,
			'state'         => $state,
		);

		$pairs = array();
		foreach ( $params as $key => $value ) {
			$pairs[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		return AQM_GHL_OAUTH_AUTHORIZE_URL . '?' . implode( '&', $pairs );
	}

	/**
	 * Handle the admin's click on "Connect to GoHighLevel". Generates a CSRF
	 * state, stashes it in a short-lived transient, then 302s the browser to
	 * GHL's authorize URL.
	 */
	public function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_admin_referer( 'aqm_oauth_start' );

		// Require client_secret in settings before we even start.
		$settings      = aqm_ghl_get_settings();
		$client_secret = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
		if ( '' === trim( $client_secret ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => 'aqm-ghl-connector',
						'aqm_oauth_err' => 'missing_secret',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Build a signed state that carries THIS site's callback URL so the
		// broker knows where to forward the code. The nonce is the CSRF token:
		// we stash it in a short-lived transient and re-check it on callback.
		$nonce = wp_generate_password( 20, false );
		set_transient( 'aqm_oauth_state_' . $nonce, get_current_user_id(), 10 * MINUTE_IN_SECONDS );

		$payload = array(
			's' => self::get_site_callback_uri(),
			'n' => $nonce,
			't' => time(),
		);

		// Optional "lock to this sub-account" guard. GHL's chooser has NO way to
		// pre-select a location, so we can't auto-pick one — but we CAN refuse a
		// wrong pick. The admin supplies the expected GHL locationId (via the
		// ?aqm_expect_loc= URL param surfaced on the Connect form, or the
		// Reconnect button which pins the currently-connected sub-account). We
		// fold it into the SIGNED state so it can't be stripped or swapped
		// mid-flight, then enforce it on callback once GHL tells us which
		// sub-account was actually chosen. Empty = no lock (unchanged behaviour).
		if ( isset( $_POST['aqm_expect_loc'] ) && is_string( $_POST['aqm_expect_loc'] ) ) {
			// GHL locationIds are alphanumeric; allow _ and - too so a future
			// nanoid-style ID can never be silently mangled into a false mismatch.
			$expected_loc = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['aqm_expect_loc'] ) ), 0, 64 );
			if ( '' !== $expected_loc ) {
				$payload['el'] = $expected_loc;
			}
		}

		$payload_b64 = self::b64url_encode( wp_json_encode( $payload ) );
		$state = $payload_b64 . '.' . self::sign_state( $payload_b64, $client_secret );

		wp_redirect( self::build_authorize_url( $state ) );
		exit;
	}

	/**
	 * Handle GHL's redirect back after the user clicks Allow. Verifies the
	 * state, exchanges `code` for tokens, stores them, then redirects the
	 * browser back to the plugin settings page with a notice.
	 */
	public function handle_callback() {
		$code     = isset( $_GET['code'] )     ? sanitize_text_field( wp_unslash( $_GET['code'] ) )     : '';
		$state    = isset( $_GET['state'] )    ? sanitize_text_field( wp_unslash( $_GET['state'] ) )    : '';
		$err      = isset( $_GET['error'] )    ? sanitize_text_field( wp_unslash( $_GET['error'] ) )    : '';
		$err_desc = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

		// GHL also forwards the chosen sub-account as a `locationId` query param on
		// the redirect. We keep it as a fallback source for the location ID because
		// the token-exchange response sometimes omits it (just like it sometimes
		// omits the refresh_token) — and without a location ID the plugin ends up
		// "connected" with no idea which sub-account to send leads to. Alphanumeric
		// (+ _ and -) only, matching handle_start()'s sanitisation.
		$loc_param = isset( $_GET['locationId'] ) && is_string( $_GET['locationId'] )
			? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_GET['locationId'] ) ), 0, 64 )
			: '';

		if ( $err ) {
			aqm_ghl_log(
				'OAuth callback returned an error from GHL.',
				array( 'error' => $err, 'description' => $err_desc )
			);
			$this->redirect_to_settings( 'denied', $err_desc ?: $err );
			return;
		}

		if ( ! $state || ! $code ) {
			$this->redirect_to_settings( 'invalid_callback', 'Missing code or state.' );
			return;
		}

		// State arrives as "payload.signature" (forwarded verbatim by the broker).
		$dot = strrpos( $state, '.' );
		if ( false === $dot || $dot < 1 || $dot >= strlen( $state ) - 1 ) {
			$this->redirect_to_settings( 'state_mismatch', 'Malformed state. Try Connect again.' );
			return;
		}
		$payload_b64 = substr( $state, 0, $dot );
		$signature   = substr( $state, $dot + 1 );

		$settings      = aqm_ghl_get_settings();
		$client_secret = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
		if ( '' === trim( $client_secret ) ) {
			$this->redirect_to_settings( 'missing_secret', 'Plugin lost the client_secret between Connect and callback. Re-enter and try again.' );
			return;
		}

		// Re-verify the signature with our own copy of the client_secret (the
		// broker already checked it; this defends against a compromised broker
		// or a forged forward).
		$expected = self::sign_state( $payload_b64, $client_secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			$this->redirect_to_settings( 'state_mismatch', 'State signature did not verify. Try Connect again.' );
			return;
		}

		$payload = json_decode( self::b64url_decode( $payload_b64 ), true );
		$nonce   = is_array( $payload ) && isset( $payload['n'] ) ? (string) $payload['n'] : '';
		// Optional sub-account lock (see handle_start). Trustworthy because the
		// whole payload is HMAC-verified above — a user can't edit 'el' in flight.
		$expected_loc = is_array( $payload ) && isset( $payload['el'] ) ? (string) $payload['el'] : '';
		if ( '' === $nonce ) {
			$this->redirect_to_settings( 'state_mismatch', 'State missing nonce. Try Connect again.' );
			return;
		}

		// One-time CSRF nonce: proves THIS site initiated the flow. An injected
		// code for a site that never started a connection has no matching nonce.
		$transient_key = 'aqm_oauth_state_' . $nonce;
		$user_id       = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! $user_id ) {
			$this->redirect_to_settings( 'state_mismatch', 'State token expired or invalid. Try Connect again.' );
			return;
		}

		// Re-establish the admin session context for the redirect target.
		if ( ! is_user_logged_in() ) {
			wp_set_current_user( (int) $user_id );
		}

		$result = $this->exchange_code_for_tokens( $code, $client_secret );
		if ( is_wp_error( $result ) ) {
			$this->redirect_to_settings( 'token_exchange_failed', $result->get_error_message() );
			return;
		}

		// Resolve which sub-account this install actually landed on. GHL usually
		// puts it in the token response, but some exchanges omit it — fall back to
		// the locationId on the callback URL, then to the ID baked into the access
		// token JWT. This single resolved value feeds both the sub-account lock
		// check and store_tokens(), so a connection can never be saved without it
		// when it's knowable.
		$actual_loc = isset( $result['locationId'] ) ? (string) $result['locationId'] : '';
		if ( '' === $actual_loc && '' !== $loc_param ) {
			$actual_loc = $loc_param;
		}
		if ( '' === $actual_loc && ! empty( $result['access_token'] ) ) {
			$actual_loc = self::location_id_from_token( (string) $result['access_token'] );
		}

		// Enforce the optional sub-account lock. We do it BEFORE store_tokens() so
		// a mismatched install never clobbers an existing good connection. Fails
		// OPEN when we can't determine the sub-account at all (no locationId from
		// any source): we must never block a valid connect we simply cannot
		// verify — only a CONFIRMED different sub-account is rejected.
		if ( '' !== $expected_loc ) {
			if ( '' !== $actual_loc && $expected_loc !== $actual_loc ) {
				aqm_ghl_log(
					'OAuth install rejected: chosen sub-account did not match the expected lock.',
					array( 'expected' => $expected_loc, 'got' => '' !== $actual_loc ? $actual_loc : '(none)' )
				);
				$this->redirect_to_settings(
					'wrong_location',
					sprintf(
						/* translators: 1: expected GHL locationId, 2: locationId actually installed */
						'Expected sub-account %1$s but the install landed on %2$s. Nothing was saved — click Connect again and pick %1$s.',
						$expected_loc,
						'' !== $actual_loc ? $actual_loc : '(unknown)'
					)
				);
				return;
			}
		}

		$this->store_tokens( $result, $actual_loc );
		$this->redirect_to_settings( 'connected' );
	}

	/**
	 * Handle the admin's click on "Disconnect from GoHighLevel". Clears all
	 * OAuth state. The legacy PIT is preserved separately.
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_admin_referer( 'aqm_oauth_disconnect' );

		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		foreach ( array( 'oauth_access_token', 'oauth_refresh_token', 'oauth_token_expires_at', 'oauth_location_id', 'oauth_location_name', 'oauth_user_id', 'oauth_connected_at' ) as $key ) {
			unset( $settings[ $key ] );
		}

		update_option( AQM_GHL_OPTION_KEY, $settings, false );

		// Tokens are gone — the cached "yes, you're connected" verdict must die.
		self::invalidate_verification_cache();

		$this->redirect_to_settings( 'disconnected' );
	}

	/**
	 * Exchange an OAuth authorization code for an access + refresh token pair.
	 *
	 * @param string $code          The `code` query parameter from GHL's redirect.
	 * @param string $client_secret The plugin-stored client_secret.
	 *
	 * @return array|\WP_Error Token response decoded from JSON, or WP_Error on failure.
	 */
	private function exchange_code_for_tokens( $code, $client_secret ) {
		$response = wp_remote_post(
			AQM_GHL_OAUTH_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => array(
					'client_id'     => AQM_GHL_OAUTH_CLIENT_ID,
					'client_secret' => $client_secret,
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'user_type'     => 'Location',
					'redirect_uri'  => self::get_redirect_uri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_resp = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code_resp < 200 || $code_resp >= 300 || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			return new \WP_Error( 'oauth_exchange_failed', sprintf( 'Token endpoint returned HTTP %d: %s', (int) $code_resp, $msg ) );
		}

		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'oauth_no_access_token', 'Token response did not include access_token.' );
		}

		return $body;
	}

	/**
	 * Persist tokens from a fresh exchange or refresh into plugin settings.
	 *
	 * @param array  $token_response Decoded JSON from /oauth/token.
	 * @param string $location_hint  Optional sub-account ID resolved by the caller
	 *                               (e.g. from the callback's locationId param) to
	 *                               use when the token response omits it.
	 */
	private function store_tokens( $token_response, $location_hint = '' ) {
		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$expires_in = isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 86400;

		$settings['oauth_access_token']      = (string) $token_response['access_token'];
		$settings['oauth_refresh_token']     = isset( $token_response['refresh_token'] ) ? (string) $token_response['refresh_token'] : ( isset( $settings['oauth_refresh_token'] ) ? $settings['oauth_refresh_token'] : '' );
		$settings['oauth_token_expires_at']  = time() + $expires_in;
		$settings['oauth_user_id']           = isset( $token_response['userId'] )     ? (string) $token_response['userId']     : ( isset( $settings['oauth_user_id'] )     ? $settings['oauth_user_id']     : '' );
		$settings['oauth_connected_at']      = current_time( 'mysql' );

		// Resolve the sub-account (location) ID from the most reliable source
		// available: the token response, then the caller's hint (callback param),
		// then the ID embedded in the access token JWT, then any previously-stored
		// value. Never let a successful connect overwrite a known location with an
		// empty one — that was the root cause of "connected but nothing sends".
		$resolved_loc = isset( $token_response['locationId'] ) ? (string) $token_response['locationId'] : '';
		if ( '' === $resolved_loc && '' !== (string) $location_hint ) {
			$resolved_loc = (string) $location_hint;
		}
		if ( '' === $resolved_loc ) {
			$resolved_loc = self::location_id_from_token( $settings['oauth_access_token'] );
		}
		if ( '' === $resolved_loc && ! empty( $settings['oauth_location_id'] ) ) {
			$resolved_loc = (string) $settings['oauth_location_id'];
		}
		$settings['oauth_location_id'] = $resolved_loc;

		// First-time connect or unchanged Location: try to backfill the
		// human-readable location name via a one-shot GET /locations/{id} call.
		if ( empty( $settings['oauth_location_name'] ) && ! empty( $settings['oauth_location_id'] ) ) {
			$name = $this->fetch_location_name( $settings['oauth_access_token'], $settings['oauth_location_id'] );
			if ( $name ) {
				$settings['oauth_location_name'] = $name;
			}
		}

		update_option( AQM_GHL_OPTION_KEY, $settings, false );

		// Some hosts run a persistent object cache that doesn't always reflect an
		// update_option() write on the *next* request (the helpers.php notes call
		// out Pressable + filtered get_option specifically). When that happens the
		// settings page reads stale, token-less settings right after a successful
		// Connect — which is exactly the "✓ Connected" notice + "Not connected"
		// eyebrow contradiction. Bust the option caches so the redirect target
		// reads the just-written values.
		wp_cache_delete( AQM_GHL_OPTION_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// Fresh tokens — any prior "is this connected?" verdict is now stale.
		self::invalidate_verification_cache();

		// store_tokens only ever runs after a SUCCESSFUL token exchange or
		// refresh, so the token we just wrote is known-good right now. Prime the
		// positive verdict so the immediate post-Connect render shows "connected"
		// even when Pressable's object cache hasn't yet reflected the option
		// write (the stale-read window that otherwise made the first render lie).
		set_transient( self::VERIFY_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );

		// Forced diagnostic (writes to wp-content/aqm-ghl-diag.log regardless of
		// the debug-logging toggle) so we can confirm what the token endpoint
		// returned and that the write actually stuck — without ever logging the
		// secret values themselves.
		if ( function_exists( 'aqm_ghl_diag_log' ) ) {
			$verify = get_option( AQM_GHL_OPTION_KEY, array() );
			$verify = is_array( $verify ) ? $verify : array();
			aqm_ghl_diag_log( sprintf(
				'store_tokens: response had access_token=%s refresh_token=%s expires_in=%s locationId=%s | after-write access_token=%s refresh_token=%s',
				! empty( $token_response['access_token'] ) ? 'yes' : 'NO',
				! empty( $token_response['refresh_token'] ) ? 'yes' : 'NO',
				isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 'absent',
				! empty( $token_response['locationId'] ) ? 'yes' : 'NO',
				! empty( $verify['oauth_access_token'] ) ? 'yes' : 'NO',
				! empty( $verify['oauth_refresh_token'] ) ? 'yes' : 'NO'
			) );
		}
	}

	/**
	 * Best-effort fetch of the location's display name. Failure is non-fatal.
	 *
	 * @param string $access_token Bearer token for the location.
	 * @param string $location_id  Sub-account ID.
	 *
	 * @return string Empty string on failure.
	 */
	private function fetch_location_name( $access_token, $location_id ) {
		$response = wp_remote_get(
			'https://services.leadconnectorhq.com/locations/' . rawurlencode( $location_id ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Version'       => '2021-07-28',
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) && isset( $body['location']['name'] ) ) {
			return sanitize_text_field( (string) $body['location']['name'] );
		}
		return '';
	}

	/**
	 * Refresh the access_token using the stored refresh_token. GHL rotates
	 * refresh tokens on each exchange — the old one becomes invalid.
	 *
	 * @return true|\WP_Error
	 */
	public function refresh_tokens() {
		$settings      = aqm_ghl_get_settings();
		$refresh       = isset( $settings['oauth_refresh_token'] ) ? (string) $settings['oauth_refresh_token'] : '';
		$client_secret = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';

		if ( '' === $refresh || '' === $client_secret ) {
			return new \WP_Error( 'oauth_missing_refresh', 'No refresh token or client_secret available — reconnect required.' );
		}

		$response = wp_remote_post(
			AQM_GHL_OAUTH_TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => array(
					'client_id'     => AQM_GHL_OAUTH_CLIENT_ID,
					'client_secret' => $client_secret,
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
					'user_type'     => 'Location',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_resp = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code_resp < 200 || $code_resp >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			aqm_ghl_log(
				'OAuth refresh failed.',
				array( 'status' => $code_resp, 'message' => $msg )
			);
			return new \WP_Error( 'oauth_refresh_failed', sprintf( 'Refresh failed (HTTP %d): %s', (int) $code_resp, $msg ) );
		}

		$this->store_tokens( $body );
		aqm_ghl_log( 'OAuth tokens refreshed successfully.', array( 'expires_in' => isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 0 ) );
		return true;
	}

	/**
	 * Centralized accessor for "give me a usable access token now". Refreshes
	 * if the stored token is within REFRESH_BUFFER_SECONDS of expiring.
	 *
	 * @return string|\WP_Error Access token, or WP_Error if refresh failed / no connection.
	 */
	public function get_access_token() {
		$settings   = aqm_ghl_get_settings();
		$access     = isset( $settings['oauth_access_token'] ) ? (string) $settings['oauth_access_token'] : '';
		$expires_at = isset( $settings['oauth_token_expires_at'] ) ? (int) $settings['oauth_token_expires_at'] : 0;

		if ( '' === $access ) {
			return new \WP_Error( 'oauth_not_connected', 'Plugin is not connected to GoHighLevel via OAuth.' );
		}

		if ( $expires_at - time() <= self::REFRESH_BUFFER_SECONDS ) {
			$result = $this->refresh_tokens();
			if ( is_wp_error( $result ) ) {
				// The refresh failed — but this branch fires up to 5 min BEFORE
				// the token actually expires, so the current access token is very
				// often still valid right now. GHL rotates the refresh token on
				// every use, so a benign cause (a concurrent request already
				// consumed it, a transient 5xx, or a stale-cache expiry read on
				// Pressable) must NOT make a working connection read as "lost".
				// Only surface the error when the current token has GENUINELY
				// expired; otherwise keep using it. A truly expired/revoked token
				// (expires_at in the past, or unknown=0) still returns the error,
				// so this can't revive the original "green while sends fail" bug.
				if ( $expires_at > 0 && ( $expires_at - time() ) > 0 ) {
					return $access;
				}
				return $result;
			}
			// Re-read from settings after refresh.
			$settings = aqm_ghl_get_settings();
			$access   = isset( $settings['oauth_access_token'] ) ? (string) $settings['oauth_access_token'] : '';
		}

		return $access;
	}

	/**
	 * Convenience static accessor.
	 *
	 * @return string|\WP_Error
	 */
	public static function token() {
		$instance = new self();
		return $instance->get_access_token();
	}

	/**
	 * Whether the plugin currently has OAuth tokens stored. PASSIVE check —
	 * doesn't verify that the tokens still work against GoHighLevel. Kept for
	 * callers that just need to know "has the user gone through Connect yet?"
	 * (e.g. settings persistence, the auth router's "do we have any creds at
	 * all" gate). For UI state — "should we tell the user they're connected?"
	 * — use `is_truly_connected()` instead.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$settings = aqm_ghl_get_settings();
		// Presence of an access_token means OAuth completed and we can talk to
		// GHL right now. We deliberately do NOT also require a refresh_token:
		// some GHL token responses (depending on app/scope config) don't return
		// one, and its absence was making the UI claim "Not connected" the
		// instant after a successful Connect — directly contradicting the
		// "Connected" success notice. A missing refresh_token only affects
		// long-term auto-refresh (surfaced separately by is_truly_connected()).
		return ! empty( $settings['oauth_access_token'] );
	}

	/**
	 * Cache key for the live-verification result. Bumped automatically whenever
	 * tokens are stored (see `store_tokens`) or cleared (see `disconnect`) so
	 * the cached verdict can't get stale across (re)connect cycles.
	 */
	const VERIFY_TRANSIENT = 'aqm_ghl_oauth_verified';

	/**
	 * ACTIVE check — verifies the stored tokens still work. Calls
	 * `get_access_token()`, which refreshes via the GHL token endpoint if the
	 * access_token is past its expiry buffer. If the refresh fails (revoked
	 * app, rotated company secret, deleted sub-account, etc.), we know we are
	 * NOT truly connected.
	 *
	 * Cached for 5 minutes via a transient so we don't ping the GHL token
	 * endpoint on every admin page render. The cache is invalidated whenever
	 * tokens are stored or disconnected.
	 *
	 * @param bool $force_fresh Skip the cache and re-verify now.
	 * @return bool
	 */
	public static function is_truly_connected( $force_fresh = false ) {
		// Cheap short-circuit: if no tokens at all, no need to ping anything.
		if ( ! self::is_connected() ) {
			return false;
		}

		// Honor only a cached POSITIVE verdict. We deliberately never trust a
		// cached '0': a single benign blip (a proactive-refresh failure, a
		// momentary network error, a stale object-cache read on Pressable) must
		// not be able to paint the UI "lost" for 5 minutes. Ignoring negatives
		// means the status self-heals on the very next render once the token
		// verifies again — while a positive verdict is still cached so we don't
		// ping GHL's token endpoint on every page load.
		if ( ! $force_fresh ) {
			if ( '1' === (string) get_transient( self::VERIFY_TRANSIENT ) ) {
				return true;
			}
		}

		$instance = new self();
		$result   = $instance->get_access_token();
		$ok       = ! is_wp_error( $result ) && '' !== (string) $result;

		if ( $ok ) {
			set_transient( self::VERIFY_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );
		} else {
			// Don't cache the failure — clear any prior verdict and re-verify
			// next render rather than locking in a false "lost".
			delete_transient( self::VERIFY_TRANSIENT );
		}

		return $ok;
	}

	/**
	 * Public hook so other code (token refresh, disconnect, store) can drop
	 * the cached verification verdict.
	 */
	public static function invalidate_verification_cache() {
		delete_transient( self::VERIFY_TRANSIENT );
	}

	/**
	 * Extract the sub-account (location) ID embedded in a GHL OAuth access token.
	 *
	 * GHL access tokens are JWTs whose payload carries the sub-account in
	 * `authClassId` (with `authClass` = "Location"). This is the last-resort
	 * source for the location ID when neither the token response nor the OAuth
	 * callback supplied one — which otherwise leaves the plugin "connected" with
	 * no idea which sub-account to send leads to. We only trust the ID for a
	 * Location-class token: an Agency/Company token's authClassId is the company,
	 * not a sub-account.
	 *
	 * @param string $access_token JWT access token.
	 * @return string Location ID, or '' if it can't be extracted.
	 */
	private static function location_id_from_token( $access_token ) {
		$parts = explode( '.', (string) $access_token );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$payload = json_decode( self::b64url_decode( $parts[1] ), true );
		if ( ! is_array( $payload ) ) {
			return '';
		}
		$class = isset( $payload['authClass'] ) ? (string) $payload['authClass'] : '';
		$id    = isset( $payload['authClassId'] ) ? (string) $payload['authClassId'] : '';
		if ( '' !== $id && ( '' === $class || 0 === strcasecmp( $class, 'Location' ) ) ) {
			return $id;
		}
		return '';
	}

	/**
	 * The connected sub-account (location) ID. Returns the stored value, and if
	 * that's missing but we have a working access token, backfills it from the
	 * token's JWT and persists it — so sites that connected before this fix (and
	 * ended up with tokens but no location) self-heal on the next request without
	 * a reconnect. Returns '' only when the ID genuinely can't be determined.
	 *
	 * @return string
	 */
	public static function location_id() {
		$settings = aqm_ghl_get_settings();
		$stored   = isset( $settings['oauth_location_id'] ) ? (string) $settings['oauth_location_id'] : '';
		if ( '' !== $stored ) {
			return $stored;
		}

		$access = isset( $settings['oauth_access_token'] ) ? (string) $settings['oauth_access_token'] : '';
		if ( '' === $access ) {
			return '';
		}

		$resolved = self::location_id_from_token( $access );
		if ( '' === $resolved ) {
			return '';
		}

		// Persist so dependent calls (sending leads, fetching the location name,
		// the test panel) stop coming up empty.
		$opt = get_option( AQM_GHL_OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$opt['oauth_location_id'] = $resolved;
		update_option( AQM_GHL_OPTION_KEY, $opt, false );
		wp_cache_delete( AQM_GHL_OPTION_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return $resolved;
	}

	/**
	 * The OAuth connection is genuinely usable: the tokens verify AND we know
	 * which sub-account to talk to. This is what the UI should mean by
	 * "Connected" — it mirrors what aqm_ghl_get_active_auth() can actually
	 * resolve, so the status badge can never claim "Connected" while real sends
	 * would fail for lack of a sub-account.
	 *
	 * @param bool $force_fresh Skip the verification cache and re-verify now.
	 * @return bool
	 */
	public static function is_ready( $force_fresh = false ) {
		return self::is_truly_connected( $force_fresh ) && '' !== self::location_id();
	}

	/**
	 * Redirect the browser back to the plugin settings page, with a status
	 * code (and optional message) that the admin renderer surfaces as a notice.
	 *
	 * @param string $status Short status slug e.g. "connected", "denied".
	 * @param string $msg    Optional human-readable detail.
	 */
	private function redirect_to_settings( $status, $msg = '' ) {
		$args = array(
			'page'           => 'aqm-ghl-connector',
			'aqm_oauth_status' => $status,
		);
		if ( '' !== $msg ) {
			$args['aqm_oauth_message'] = rawurlencode( mb_substr( $msg, 0, 240 ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
