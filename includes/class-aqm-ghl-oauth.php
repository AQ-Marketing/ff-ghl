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
	 * Compute this WP site's OAuth redirect URI. Must match exactly one of
	 * the URIs registered in GHL's developer dashboard for the AQM app.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin-ajax.php?action=' . AQM_GHL_OAUTH_CALLBACK_ACTION );
	}

	/**
	 * Build the authorize URL the "Connect" button redirects to.
	 *
	 * @param string $state Random CSRF token, stored briefly for verification on callback.
	 *
	 * @return string
	 */
	public static function build_authorize_url( $state ) {
		return add_query_arg(
			array(
				'response_type' => 'code',
				'redirect_uri'  => self::get_redirect_uri(),
				'client_id'     => AQM_GHL_OAUTH_CLIENT_ID,
				'scope'         => AQM_GHL_OAUTH_SCOPES,
				'state'         => $state,
			),
			AQM_GHL_OAUTH_AUTHORIZE_URL
		);
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

		$state = wp_generate_password( 32, false );
		set_transient( 'aqm_oauth_state_' . $state, get_current_user_id(), 10 * MINUTE_IN_SECONDS );

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

		$transient_key = 'aqm_oauth_state_' . $state;
		$user_id       = get_transient( $transient_key );
		delete_transient( $transient_key ); // One-time use.

		if ( ! $user_id ) {
			$this->redirect_to_settings( 'state_mismatch', 'State token expired or invalid. Try Connect again.' );
			return;
		}

		// Re-establish the admin session context for the redirect target.
		if ( ! is_user_logged_in() ) {
			wp_set_current_user( (int) $user_id );
		}

		$settings      = aqm_ghl_get_settings();
		$client_secret = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
		if ( '' === trim( $client_secret ) ) {
			$this->redirect_to_settings( 'missing_secret', 'Plugin lost the client_secret between Connect and callback. Re-enter and try again.' );
			return;
		}

		$result = $this->exchange_code_for_tokens( $code, $client_secret );
		if ( is_wp_error( $result ) ) {
			$this->redirect_to_settings( 'token_exchange_failed', $result->get_error_message() );
			return;
		}

		$this->store_tokens( $result );
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
	 * @param array $token_response Decoded JSON from /oauth/token.
	 */
	private function store_tokens( $token_response ) {
		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$expires_in = isset( $token_response['expires_in'] ) ? (int) $token_response['expires_in'] : 86400;

		$settings['oauth_access_token']      = (string) $token_response['access_token'];
		$settings['oauth_refresh_token']     = isset( $token_response['refresh_token'] ) ? (string) $token_response['refresh_token'] : ( isset( $settings['oauth_refresh_token'] ) ? $settings['oauth_refresh_token'] : '' );
		$settings['oauth_token_expires_at']  = time() + $expires_in;
		$settings['oauth_location_id']       = isset( $token_response['locationId'] ) ? (string) $token_response['locationId'] : ( isset( $settings['oauth_location_id'] ) ? $settings['oauth_location_id'] : '' );
		$settings['oauth_user_id']           = isset( $token_response['userId'] )     ? (string) $token_response['userId']     : ( isset( $settings['oauth_user_id'] )     ? $settings['oauth_user_id']     : '' );
		$settings['oauth_connected_at']      = current_time( 'mysql' );

		// First-time connect or unchanged Location: try to backfill the
		// human-readable location name via a one-shot GET /locations/{id} call.
		if ( empty( $settings['oauth_location_name'] ) && ! empty( $settings['oauth_location_id'] ) ) {
			$name = $this->fetch_location_name( $settings['oauth_access_token'], $settings['oauth_location_id'] );
			if ( $name ) {
				$settings['oauth_location_name'] = $name;
			}
		}

		update_option( AQM_GHL_OPTION_KEY, $settings, false );
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
	 * Whether the plugin currently has a valid (or refreshable) OAuth connection.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$settings = aqm_ghl_get_settings();
		return ! empty( $settings['oauth_access_token'] ) && ! empty( $settings['oauth_refresh_token'] );
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
