<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aqm_ghl_get_auth_mode' ) ) {
	/**
	 * Determine which auth path the plugin is currently configured to use.
	 *
	 * @return string 'oauth' when the OAuth Marketplace App is connected, else 'pit'.
	 */
	function aqm_ghl_get_auth_mode() {
		$settings = aqm_ghl_get_settings();
		$mode     = isset( $settings['auth_mode'] ) ? (string) $settings['auth_mode'] : '';

		// Explicit override wins.
		if ( 'oauth' === $mode || 'pit' === $mode ) {
			return $mode;
		}

		// Auto-detect: an access token alone means OAuth completed and we can talk
		// to GHL right now. We deliberately do NOT also require a refresh_token here
		// — this MUST mirror AQM_GHL_OAuth::is_connected(), which dropped that
		// requirement because some GHL token responses don't return a refresh_token.
		// When the two checks disagreed, a refresh-token-less connection showed
		// "Connected" in the UI (is_connected: access token present) yet silently
		// routed every send through PIT mode (this function previously needed BOTH
		// tokens) — so test sends and form submissions aborted on OAuth-only sites
		// with no legacy PIT. aqm_ghl_get_active_auth() still falls back to PIT
		// gracefully if the OAuth token can't actually be resolved at call time.
		if ( ! empty( $settings['oauth_access_token'] ) ) {
			return 'oauth';
		}
		return 'pit';
	}
}

if ( ! function_exists( 'aqm_ghl_get_active_auth' ) ) {
	/**
	 * Return the bearer token + location_id the rest of the plugin should use
	 * for a GHL API request, based on the configured auth mode.
	 *
	 * @return array|\WP_Error array{token:string, location_id:string, mode:string} on success.
	 */
	function aqm_ghl_get_active_auth() {
		$mode     = aqm_ghl_get_auth_mode();
		$settings = aqm_ghl_get_settings();

		// Resolve the legacy PIT credentials up front (top-level scalars, falling
		// back to the first multi-location entry — post-migration sites may have
		// empty top-level scalars). OAuth mode reuses these as a safety net below.
		$pit_token       = isset( $settings['private_token'] ) ? (string) $settings['private_token'] : '';
		$pit_location_id = isset( $settings['location_id'] )   ? (string) $settings['location_id']   : '';
		if ( ( '' === $pit_token || '' === $pit_location_id ) && ! empty( $settings['locations'] ) && is_array( $settings['locations'] ) ) {
			$first = reset( $settings['locations'] );
			if ( is_array( $first ) ) {
				if ( '' === $pit_token && ! empty( $first['private_token'] ) ) {
					$pit_token = (string) $first['private_token'];
				}
				if ( '' === $pit_location_id && ! empty( $first['location_id'] ) ) {
					$pit_location_id = (string) $first['location_id'];
				}
			}
		}
		$pit_available = ( '' !== $pit_token && '' !== $pit_location_id );

		if ( 'oauth' === $mode ) {
			$oauth_error = null;
			if ( ! class_exists( 'AQM_GHL_OAuth' ) ) {
				$oauth_error = new \WP_Error( 'oauth_class_missing', 'AQM_GHL_OAuth class not loaded.' );
			} else {
				$token = AQM_GHL_OAuth::token();
				if ( is_wp_error( $token ) ) {
					$oauth_error = $token;
				} else {
					// Resolve via the self-healing accessor so a site connected before
					// the location-ID fix (tokens stored, but no sub-account) backfills
					// the ID from the access token JWT and sends instead of erroring.
					$location_id = class_exists( 'AQM_GHL_OAuth' )
						? AQM_GHL_OAuth::location_id()
						: ( isset( $settings['oauth_location_id'] ) ? (string) $settings['oauth_location_id'] : '' );
					if ( '' === $location_id ) {
						$oauth_error = new \WP_Error( 'oauth_no_location', 'OAuth-connected but no location ID stored — reconnect to GoHighLevel.' );
					} else {
						return array(
							'mode'        => 'oauth',
							'token'       => $token,
							'location_id' => $location_id,
						);
					}
				}
			}

			// OAuth is the active mode but its token couldn't be resolved (e.g. an
			// access_token that expired with no refresh_token to renew it, a revoked
			// app, or a missing location ID). If a legacy PIT is still configured,
			// use it rather than aborting the submission — the safety net for
			// hybrid / mid-migration sites. Otherwise surface the OAuth error.
			if ( $pit_available ) {
				aqm_ghl_log(
					'OAuth token unavailable; falling back to legacy PIT for this request.',
					array( 'oauth_error' => $oauth_error ? $oauth_error->get_error_message() : 'unknown' )
				);
				return array(
					'mode'        => 'pit',
					'token'       => $pit_token,
					'location_id' => $pit_location_id,
				);
			}

			return $oauth_error ? $oauth_error : new \WP_Error( 'oauth_unresolved', 'OAuth token could not be resolved.' );
		}

		// Legacy PIT path.
		if ( ! $pit_available ) {
			return new \WP_Error( 'pit_not_configured', 'Plugin is in PIT mode but no Private Integration Token or Location ID is set.' );
		}
		return array(
			'mode'        => 'pit',
			'token'       => $pit_token,
			'location_id' => $pit_location_id,
		);
	}
}

if ( ! function_exists( 'aqm_ghl_get_settings' ) ) {
	/**
	 * Retrieve plugin settings with defaults.
	 *
	 * @return array
	 */
	function aqm_ghl_get_settings() {
		$defaults = array(
			'locations'      => array(), // Multi-location support: array of location configs
			'location_id'   => '',      // Legacy single location (deprecated, migrated to locations)
			'private_token' => '',      // Legacy single token (deprecated, migrated to locations)
			'github_token'  => '',
			'form_ids'       => array(), // Legacy (deprecated, now per-location)
			'mapping'        => array(), // per form: [form_id] => [email, phone, first_name, last_name]
			'custom_fields'  => array(), // per form: [form_id] => [ [ghl_field_id, form_field_id], ... ]
			'tags'           => '',     // Legacy (deprecated, now per-location)
			'enable_logging' => false,
		);

		// Recursion guard — aqm_ghl_log() calls aqm_ghl_is_logging_enabled() which
		// calls back here. If a hosting environment's object cache doesn't reflect
		// our update_option() write before the recursive read (Pressable, sites with
		// pre_option_* filters, etc.), the migration block below would re-fire on
		// every recursive call and OOM the request.
		static $in_progress = false;
		if ( $in_progress ) {
			$cached = get_option( AQM_GHL_OPTION_KEY, array() );
			return wp_parse_args( is_array( $cached ) ? $cached : array(), $defaults );
		}
		$in_progress = true;

		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Migrate old single-location settings to new multi-location format.
		// The `_migrated_to_multi_location` flag short-circuits re-migration even
		// when the persisted `locations` value can't be read back (cache layer
		// issues, filtered get_option, etc.).
		if (
			empty( $settings['_migrated_to_multi_location'] )
			&& ! empty( $settings['location_id'] )
			&& empty( $settings['locations'] )
		) {
			$settings = aqm_ghl_migrate_to_multi_location( $settings );
		}

		// Migrate old form_id (singular) to form_ids (plural array)
		if ( ! empty( $settings['form_id'] ) && empty( $settings['form_ids'] ) ) {
			$settings['form_ids'] = array( absint( $settings['form_id'] ) );
			unset( $settings['form_id'] );
			update_option( AQM_GHL_OPTION_KEY, $settings );
		}

		$in_progress = false;
		return wp_parse_args( $settings, $defaults );
	}
}

if ( ! function_exists( 'aqm_ghl_migrate_to_multi_location' ) ) {
	/**
	 * Migrate old single-location settings to new multi-location format.
	 *
	 * @param array $settings Existing settings.
	 * @return array Migrated settings.
	 */
	function aqm_ghl_migrate_to_multi_location( $settings ) {
		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			return $settings;
		}

		// Create default location from old settings
		$default_location = array(
			'name'         => __( 'Default Location', 'aqm-ghl' ),
			'location_id'  => $settings['location_id'],
			'private_token' => $settings['private_token'],
			'form_ids'     => ! empty( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? $settings['form_ids'] : array(),
			'tags'         => ! empty( $settings['tags'] ) ? $settings['tags'] : '',
		);

		$settings['locations'] = array( $default_location );

		// Keep legacy fields for backwards compatibility but mark as migrated
		$settings['_migrated_to_multi_location'] = true;

		// Save migrated settings. Do NOT call aqm_ghl_log() here — it goes through
		// aqm_ghl_is_logging_enabled() → aqm_ghl_get_settings(), which would recurse
		// into this migration if a hosting cache layer doesn't reflect the write.
		update_option( AQM_GHL_OPTION_KEY, $settings );

		return $settings;
	}
}

if ( ! function_exists( 'aqm_ghl_get_location_for_form' ) ) {
	/**
	 * Get the location configuration for a specific form ID.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null Location config or null if not found.
	 */
	function aqm_ghl_get_location_for_form( $form_id ) {
		$settings = aqm_ghl_get_settings();
		$form_id  = absint( $form_id );

		// Check multi-location format first
		if ( ! empty( $settings['locations'] ) && is_array( $settings['locations'] ) ) {
			foreach ( $settings['locations'] as $location ) {
				if ( ! empty( $location['form_ids'] ) && is_array( $location['form_ids'] ) ) {
					if ( in_array( $form_id, array_map( 'absint', $location['form_ids'] ), true ) ) {
						return $location;
					}
				}
			}

			// No match found, return default (first) location if available
			if ( ! empty( $settings['locations'][0] ) ) {
				return $settings['locations'][0];
			}
		}

		// Fallback to legacy single-location format
		if ( ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] ) ) {
			return array(
				'name'         => __( 'Default Location', 'aqm-ghl' ),
				'location_id'  => $settings['location_id'],
				'private_token' => $settings['private_token'],
				'form_ids'     => ! empty( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? $settings['form_ids'] : array(),
				'tags'         => ! empty( $settings['tags'] ) ? $settings['tags'] : '',
			);
		}

		return null;
	}
}

if ( ! function_exists( 'aqm_ghl_get_setting' ) ) {
	/**
	 * Helper to fetch a single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default if missing.
	 *
	 * @return mixed
	 */
	function aqm_ghl_get_setting( $key, $default = '' ) {
		$settings = aqm_ghl_get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}
}

if ( ! function_exists( 'aqm_ghl_is_logging_enabled' ) ) {
	/**
	 * Determine if logging is enabled.
	 *
	 * @return bool
	 */
	function aqm_ghl_is_logging_enabled() {
		$settings = aqm_ghl_get_settings();

		return ! empty( $settings['enable_logging'] );
	}
}

if ( ! function_exists( 'aqm_ghl_log' ) ) {
	/**
	 * Log a message to the PHP error log when enabled.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context array.
	 */
	function aqm_ghl_log( $message, $context = array() ) {
		if ( ! aqm_ghl_is_logging_enabled() ) {
			return;
		}

		$line = '[AQM GHL] ' . ( is_scalar( $message ) ? $message : wp_json_encode( $message ) );

		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

if ( ! function_exists( 'aqm_ghl_normalize_phone' ) ) {
	/**
	 * Normalize a phone number toward E.164 when feasible.
	 *
	 * @param string $phone Input phone.
	 *
	 * @return string
	 */
	function aqm_ghl_normalize_phone( $phone ) {
		$phone = trim( (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		// Preserve leading + then strip all non-digits.
		$has_plus  = substr( $phone, 0, 1 ) === '+';
		$digits    = preg_replace( '/\D+/', '', $phone );
		$normalized = $digits;

		if ( ! $normalized ) {
			return '';
		}

		// Assume US/Canada if 10 digits without country code.
		if ( strlen( $normalized ) === 10 ) {
			$normalized = '1' . $normalized;
		}

		// If length already includes country code.
		if ( $has_plus && substr( $normalized, 0, 1 ) !== '+' ) {
			$normalized = '+' . $normalized;
		} elseif ( substr( $normalized, 0, 1 ) !== '+' ) {
			$normalized = '+' . $normalized;
		}

		return $normalized;
	}
}

if ( ! function_exists( 'aqm_ghl_clean_payload' ) ) {
	/**
	 * Remove empty values from the payload recursively.
	 *
	 * @param array $payload Payload data.
	 *
	 * @return array
	 */
	function aqm_ghl_clean_payload( $payload ) {
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = aqm_ghl_clean_payload( $value );

				if ( empty( $payload[ $key ] ) ) {
					unset( $payload[ $key ] );
				}
			} elseif ( '' === $value || null === $value ) {
				unset( $payload[ $key ] );
			}
		}

		return $payload;
	}
}

if ( ! function_exists( 'aqm_ghl_get_formidable_forms' ) ) {
	/**
	 * Get published Formidable forms.
	 *
	 * @return array
	 */
	function aqm_ghl_get_formidable_forms() {
		if ( ! class_exists( 'FrmForm' ) ) {
			return array();
		}

		$forms = FrmForm::getAll(
			array(
				'status' => 'published',
			)
		);

		return is_array( $forms ) ? $forms : array();
	}
}

if ( ! function_exists( 'aqm_ghl_get_formidable_form_fields' ) ) {
	/**
	 * Get fields for a specific Formidable form.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	function aqm_ghl_get_formidable_form_fields( $form_id ) {
		if ( ! class_exists( 'FrmField' ) || ! $form_id ) {
			return array();
		}

		$fields = FrmField::getAll(
			array(
				'fi.form_id' => absint( $form_id ),
				'fi.type not' => array( 'divider', 'html', 'break', 'captcha', 'end_divider' ),
			)
		);

		$prepared = array();

		if ( empty( $fields ) ) {
			return $prepared;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field->id ) ) {
				continue;
			}

			$prepared[] = array(
				'id'    => (int) $field->id,
				'label' => isset( $field->name ) ? $field->name : '',
			);
		}

		return $prepared;
	}
}

if ( ! function_exists( 'aqm_ghl_autodetect_mapping_for_form' ) ) {
	/**
	 * Auto-detect the Formidable field IDs for email / phone / first name /
	 * last name on a form, by field type first then label heuristics. Used at
	 * settings-save time so submissions resolve contact fields with no manual
	 * mapping. Returns only the keys it could confidently match.
	 *
	 * @param int $form_id Formidable form ID.
	 * @return array Map of email|phone|first_name|last_name => Formidable field ID (int).
	 */
	function aqm_ghl_autodetect_mapping_for_form( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id || ! class_exists( 'FrmField' ) ) {
			return array();
		}

		$fields = FrmField::getAll(
			array(
				'fi.form_id'  => $form_id,
				'fi.type not' => array( 'divider', 'html', 'break', 'captcha', 'end_divider', 'hidden' ),
			)
		);
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return array();
		}

		$norm = function ( $s ) {
			return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $s ) ) );
		};

		$map = array();

		// 1) Type-based detection (most reliable).
		foreach ( $fields as $f ) {
			$fid  = isset( $f->id ) ? (int) $f->id : 0;
			$type = isset( $f->type ) ? strtolower( (string) $f->type ) : '';
			if ( ! $fid ) {
				continue;
			}
			if ( 'email' === $type && empty( $map['email'] ) ) {
				$map['email'] = $fid;
			} elseif ( 'phone' === $type && empty( $map['phone'] ) ) {
				$map['phone'] = $fid;
			}
		}

		// 2) Label heuristics for anything not found by type (priority order).
		// email/phone/name are matched before the address fields so a field like
		// "Email Address" claims the email slot before "address" can grab it.
		$label_rules = array(
			'email'       => array( '/\bemail\b/', '/\be mail\b/' ),
			'phone'       => array( '/\bphone\b/', '/\bmobile\b/', '/\bcell\b/', '/\btel\b/' ),
			'first_name'  => array( '/\bfirst name\b/', '/\bfirst\b/', '/\bgiven name\b/', '/\bfname\b/' ),
			'last_name'   => array( '/\blast name\b/', '/\blast\b/', '/\bsurname\b/', '/\bfamily name\b/', '/\blname\b/' ),
			'address1'    => array( '/\bstreet address\b/', '/\baddress line 1\b/', '/\bmailing address\b/', '/\bstreet\b/', '/\baddress\b/' ),
			'city'        => array( '/\bcity\b/', '/\btown\b/', '/\bsuburb\b/' ),
			'state'       => array( '/\bstate\b/', '/\bprovince\b/', '/\bregion\b/' ),
			'postal_code' => array( '/\bzip code\b/', '/\bzip\b/', '/\bpostal code\b/', '/\bpostcode\b/', '/\bpostal\b/' ),
		);
		foreach ( $label_rules as $key => $patterns ) {
			if ( ! empty( $map[ $key ] ) ) {
				continue;
			}
			foreach ( $patterns as $pattern ) {
				foreach ( $fields as $f ) {
					$fid = isset( $f->id ) ? (int) $f->id : 0;
					if ( ! $fid || in_array( $fid, $map, true ) ) {
						continue;
					}
					if ( preg_match( $pattern, $norm( isset( $f->name ) ? $f->name : '' ) ) ) {
						$map[ $key ] = $fid;
						continue 3;
					}
				}
			}
		}

		// 3) Fallback: a single "name" / "full name" field maps to first name
		// (GHL accepts a full name in firstName) when no first/last were found.
		if ( empty( $map['first_name'] ) && empty( $map['last_name'] ) ) {
			foreach ( $fields as $f ) {
				$fid = isset( $f->id ) ? (int) $f->id : 0;
				if ( ! $fid || in_array( $fid, $map, true ) ) {
					continue;
				}
				$type  = isset( $f->type ) ? strtolower( (string) $f->type ) : '';
				$label = $norm( isset( $f->name ) ? $f->name : '' );
				if ( 'name' === $type || preg_match( '/\b(full )?name\b/', $label ) ) {
					$map['first_name'] = $fid;
					break;
				}
			}
		}

		return $map;
	}
}

if ( ! function_exists( 'aqm_ghl_autodetect_custom_fields_for_form' ) ) {
	/**
	 * Auto-map a form's fields to GHL custom fields by matching labels, so custom
	 * fields flow through with no manual mapping UI. Skips core contact fields and
	 * UTM/GCLID fields (those are injected automatically at submission time).
	 *
	 * @param int $form_id Formidable form ID.
	 * @return array List of array{ghl_field_id:string, form_field_id:int}.
	 */
	function aqm_ghl_autodetect_custom_fields_for_form( $form_id ) {
		$form_id = absint( $form_id );
		if ( ! $form_id || ! class_exists( 'FrmField' ) ) {
			return array();
		}

		$ghl_fields = aqm_ghl_get_cached_ghl_custom_fields();
		if ( empty( $ghl_fields ) || ! is_array( $ghl_fields ) ) {
			return array();
		}

		$frm_fields = FrmField::getAll(
			array(
				'fi.form_id'  => $form_id,
				'fi.type not' => array( 'divider', 'html', 'break', 'captcha', 'end_divider' ),
			)
		);
		if ( empty( $frm_fields ) || ! is_array( $frm_fields ) ) {
			return array();
		}

		$norm = function ( $s ) {
			return preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $s ) );
		};

		// Core + UTM/GCLID fields are handled elsewhere — never auto-map them here.
		$skip = array( 'email', 'phone', 'phonenumber', 'firstname', 'lastname', 'name', 'fullname', 'gclid', 'utmsource', 'utmmedium', 'utmcampaign', 'utmterm', 'utmcontent' );

		// Index Formidable fields by normalized label (first occurrence wins).
		$by_label = array();
		foreach ( $frm_fields as $f ) {
			$fid = isset( $f->id ) ? (int) $f->id : 0;
			$key = $norm( isset( $f->name ) ? $f->name : '' );
			if ( $fid && '' !== $key && ! isset( $by_label[ $key ] ) ) {
				$by_label[ $key ] = $fid;
			}
		}

		$out  = array();
		$used = array();
		foreach ( $ghl_fields as $cf ) {
			$cf_id = isset( $cf['id'] ) ? (string) $cf['id'] : '';
			if ( '' === $cf_id ) {
				continue;
			}
			// Candidate keys: the GHL field name (minus an "AQM -" prefix) and the
			// fieldKey suffix (e.g. contact.company -> company).
			$name_key = $norm( str_replace( 'AQM -', '', isset( $cf['name'] ) ? $cf['name'] : '' ) );
			$fk_key   = '';
			if ( ! empty( $cf['fieldKey'] ) ) {
				$parts  = explode( '.', (string) $cf['fieldKey'] );
				$fk_key = $norm( end( $parts ) );
			}
			if ( in_array( $name_key, $skip, true ) || in_array( $fk_key, $skip, true ) ) {
				continue;
			}
			$match = 0;
			if ( $name_key && isset( $by_label[ $name_key ] ) ) {
				$match = $by_label[ $name_key ];
			} elseif ( $fk_key && isset( $by_label[ $fk_key ] ) ) {
				$match = $by_label[ $fk_key ];
			}
			if ( $match && empty( $used[ $match ] ) ) {
				$out[]          = array( 'ghl_field_id' => $cf_id, 'form_field_id' => $match );
				$used[ $match ] = true;
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'aqm_ghl_send_contact_payload' ) ) {
	/**
	 * Send a contact payload to GoHighLevel.
	 *
	 * @param array  $payload Payload array.
	 * @param string $token   Private integration token.
	 *
	 * @return array|\WP_Error
	 */
	function aqm_ghl_send_contact_payload( $payload, $token ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Version'       => '2021-07-28',
			),
			'timeout' => 15,
			'body'    => wp_json_encode( $payload ),
		);

		return wp_remote_post( 'https://services.leadconnectorhq.com/contacts/', $args );
	}
}

if ( ! function_exists( 'aqm_ghl_create_note' ) ) {
	/**
	 * Create a Note on a GHL contact. Best-effort — callers should not block the
	 * submission flow on the result.
	 *
	 * Endpoint: POST https://services.leadconnectorhq.com/contacts/{id}/notes
	 * Scope:    contacts.write
	 *
	 * @param string $contact_id GHL contact ID.
	 * @param string $body       Note text.
	 * @param string $token      Bearer token (PIT or OAuth access token).
	 * @param string $user_id    Optional GHL user ID (some sub-accounts require it).
	 *
	 * @return array|\WP_Error array{status:int, body:string} on transport success.
	 */
	function aqm_ghl_create_note( $contact_id, $body, $token, $user_id = '' ) {
		$payload = array( 'body' => (string) $body );
		if ( '' !== (string) $user_id ) {
			$payload['userId'] = (string) $user_id;
		}

		$response = wp_remote_post(
			sprintf( 'https://services.leadconnectorhq.com/contacts/%s/notes', rawurlencode( (string) $contact_id ) ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Version'       => '2021-07-28',
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}

if ( ! function_exists( 'aqm_ghl_store_last_test_result' ) ) {
	/**
	 * Store the last test result for display in admin.
	 *
	 * @param array $data Result data.
	 */
	function aqm_ghl_store_last_test_result( $data ) {
		$payload = array(
			'timestamp' => current_time( 'mysql' ),
			'success'   => isset( $data['success'] ) ? (bool) $data['success'] : false,
			'status'    => isset( $data['status'] ) ? (int) $data['status'] : 0,
			'payload'   => isset( $data['payload'] ) ? $data['payload'] : array(),
			'response'  => isset( $data['response'] ) ? $data['response'] : '',
			'message'   => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '',
		);

		update_option( AQM_GHL_TEST_RESULT_KEY, $payload, false );
	}
}

if ( ! function_exists( 'aqm_ghl_get_last_test_result' ) ) {
	/**
	 * Retrieve the last stored test result.
	 *
	 * @return array
	 */
	function aqm_ghl_get_last_test_result() {
		$defaults = array(
			'timestamp' => '',
			'success'   => false,
			'status'    => 0,
			'payload'   => array(),
			'response'  => '',
			'message'   => '',
		);

		$saved = get_option( AQM_GHL_TEST_RESULT_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}
}

if ( ! function_exists( 'aqm_ghl_store_last_submission_result' ) ) {
	/**
	 * Store the last live submission result for display in admin.
	 *
	 * @param array $data Result data.
	 */
	function aqm_ghl_store_last_submission_result( $data ) {
		$payload = array(
			'timestamp' => current_time( 'mysql' ),
			'success'   => isset( $data['success'] ) ? (bool) $data['success'] : false,
			'status'    => isset( $data['status'] ) ? (int) $data['status'] : 0,
			'payload'   => isset( $data['payload'] ) ? $data['payload'] : array(),
			'response'  => isset( $data['response'] ) ? $data['response'] : '',
			'message'   => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '',
			'context'   => isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : array(),
		);

		update_option( AQM_GHL_LAST_SUBMISSION_RESULT_KEY, $payload, false );
	}
}

if ( ! function_exists( 'aqm_ghl_get_last_submission_result' ) ) {
	/**
	 * Retrieve the last stored live submission result.
	 *
	 * @return array
	 */
	function aqm_ghl_get_last_submission_result() {
		$defaults = array(
			'timestamp' => '',
			'success'   => false,
			'status'    => 0,
			'payload'   => array(),
			'response'  => '',
			'message'   => '',
			'context'   => array(),
		);

		$saved = get_option( AQM_GHL_LAST_SUBMISSION_RESULT_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}
}

if ( ! function_exists( 'aqm_ghl_sanitize_custom_fields' ) ) {
	/**
	 * Sanitize custom field mappings.
	 *
	 * @param array $custom_fields Raw input (per form or flat).
	 *
	 * @return array
	 */
	function aqm_ghl_sanitize_custom_fields( $custom_fields ) {
		if ( empty( $custom_fields ) || ! is_array( $custom_fields ) ) {
			return array();
		}

		// Detect per-form structure.
		$is_per_form = false;
		foreach ( $custom_fields as $key => $value ) {
			if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
				$is_per_form = true;
				break;
			}
		}

		// Helper to clean a list.
		$clean_list = function ( $list ) {
			$out = array();
			foreach ( $list as $custom_field ) {
				if ( empty( $custom_field['ghl_field_id'] ) && empty( $custom_field['form_field_id'] ) ) {
					continue;
				}
				$ghl_field_id  = isset( $custom_field['ghl_field_id'] ) ? sanitize_text_field( $custom_field['ghl_field_id'] ) : '';
				$form_field_id = isset( $custom_field['form_field_id'] ) ? absint( $custom_field['form_field_id'] ) : 0;
				if ( ! $ghl_field_id || ! $form_field_id ) {
					continue;
				}
				$out[] = array(
					'ghl_field_id'  => $ghl_field_id,
					'form_field_id' => $form_field_id,
				);
			}
			return $out;
		};

		if ( ! $is_per_form ) {
			return $clean_list( $custom_fields );
		}

		$clean = array();
		foreach ( $custom_fields as $form_id => $list ) {
			$form_id = absint( $form_id );
			if ( ! $form_id ) {
				continue;
			}
			$cleaned = $clean_list( $list );
			if ( ! empty( $cleaned ) ) {
				$clean[ $form_id ] = $cleaned;
			}
		}

		return $clean;
	}
}

if ( ! function_exists( 'aqm_ghl_fetch_ghl_custom_fields' ) ) {
	/**
	 * Fetch custom fields from the GoHighLevel API for a location.
	 *
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @param bool   $force       Force refresh (ignore cache).
	 *
	 * @return array|\WP_Error Array of custom field objects or WP_Error.
	 */
	function aqm_ghl_fetch_ghl_custom_fields( $location_id, $token, $force = false ) {
		$cache_key = 'aqm_ghl_cf_' . md5( $location_id );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = 'https://services.leadconnectorhq.com/locations/' . rawurlencode( $location_id ) . '/customFields';

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Version'       => '2021-07-28',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			return new \WP_Error( 'ghl_api_error', sprintf( 'GHL API returned %d: %s', $code, $msg ) );
		}

		if ( ! isset( $body['customFields'] ) || ! is_array( $body['customFields'] ) ) {
			return new \WP_Error( 'ghl_invalid_response', 'Unexpected response format from GHL custom fields endpoint.' );
		}

		$fields = array();
		foreach ( $body['customFields'] as $field ) {
			if ( empty( $field['id'] ) ) {
				continue;
			}
			$fields[] = array(
				'id'       => sanitize_text_field( $field['id'] ),
				'name'     => isset( $field['name'] ) ? sanitize_text_field( $field['name'] ) : '',
				'fieldKey' => isset( $field['fieldKey'] ) ? sanitize_text_field( $field['fieldKey'] ) : '',
				'dataType' => isset( $field['dataType'] ) ? sanitize_text_field( $field['dataType'] ) : '',
			);
		}

		set_transient( $cache_key, $fields, HOUR_IN_SECONDS );

		return $fields;
	}
}

if ( ! function_exists( 'aqm_ghl_get_cached_ghl_custom_fields' ) ) {
	/**
	 * Return cached GHL custom fields (no API call if cache is empty).
	 *
	 * @return array
	 */
	function aqm_ghl_get_cached_ghl_custom_fields() {
		// Resolve through the auth router so this works in OAuth mode too. Reading the
		// legacy PIT scalars directly returned empty on OAuth-only sites, which left
		// the field-mapping UI and the test contact's custom fields blank.
		$auth = aqm_ghl_get_active_auth();
		if ( is_wp_error( $auth ) ) {
			return array();
		}
		$location_id = $auth['location_id'];
		$token       = $auth['token'];

		$cache_key = 'aqm_ghl_cf_' . md5( $location_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Auto-fetch from API when cache is empty and credentials exist.
		$fields = aqm_ghl_fetch_ghl_custom_fields( $location_id, $token, false );
		return is_array( $fields ) ? $fields : array();
	}
}

if ( ! function_exists( 'aqm_ghl_fetch_pipelines' ) ) {
	/**
	 * Fetch the list of opportunity pipelines (with their stages) for a location.
	 *
	 * Endpoint: GET https://services.leadconnectorhq.com/opportunities/pipelines?locationId=...
	 * Required scope: opportunities.readonly
	 *
	 * @param string $location_id GHL location ID.
	 * @param string $token       Bearer token (PIT or OAuth access token).
	 * @param bool   $force       Skip cache and hit the API.
	 *
	 * @return array|\WP_Error Array of { id, name, stages: [{id, name, position}] } or WP_Error.
	 */
	function aqm_ghl_fetch_pipelines( $location_id, $token, $force = false ) {
		$cache_key = 'aqm_ghl_pipelines_' . md5( (string) $location_id );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$url = add_query_arg(
			array( 'locationId' => $location_id ),
			'https://services.leadconnectorhq.com/opportunities/pipelines'
		);
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Version'       => '2021-07-28',
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? $body['message'] : wp_remote_retrieve_body( $response );
			if ( 401 === (int) $code || 403 === (int) $code ) {
				$msg = sprintf(
					/* translators: 1: HTTP status, 2: raw error */
					__( 'GHL refused the request (HTTP %1$d). Your token may be missing the "opportunities.readonly" scope. For OAuth, click Disconnect then Connect again to refresh tokens with the new scope. (Raw: %2$s)', 'aqm-ghl' ),
					(int) $code,
					$msg
				);
			}
			return new \WP_Error( 'ghl_pipelines_error', $msg );
		}
		if ( ! is_array( $body ) || ! isset( $body['pipelines'] ) || ! is_array( $body['pipelines'] ) ) {
			return new \WP_Error( 'ghl_pipelines_invalid', __( 'Unexpected response from GHL pipelines endpoint.', 'aqm-ghl' ) );
		}
		$out = array();
		foreach ( $body['pipelines'] as $pipeline ) {
			if ( empty( $pipeline['id'] ) ) {
				continue;
			}
			$stages = array();
			if ( ! empty( $pipeline['stages'] ) && is_array( $pipeline['stages'] ) ) {
				foreach ( $pipeline['stages'] as $stage ) {
					if ( empty( $stage['id'] ) ) {
						continue;
					}
					$stages[] = array(
						'id'       => sanitize_text_field( (string) $stage['id'] ),
						'name'     => isset( $stage['name'] ) ? sanitize_text_field( (string) $stage['name'] ) : '',
						'position' => isset( $stage['position'] ) ? (int) $stage['position'] : 0,
					);
				}
			}
			$out[] = array(
				'id'     => sanitize_text_field( (string) $pipeline['id'] ),
				'name'   => isset( $pipeline['name'] ) ? sanitize_text_field( (string) $pipeline['name'] ) : '',
				'stages' => $stages,
			);
		}
		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'aqm_ghl_get_cached_pipelines' ) ) {
	/**
	 * Return cached pipelines without forcing an API call.
	 *
	 * @param string $location_id GHL location ID.
	 *
	 * @return array
	 */
	function aqm_ghl_get_cached_pipelines( $location_id ) {
		if ( empty( $location_id ) ) {
			return array();
		}
		$cached = get_transient( 'aqm_ghl_pipelines_' . md5( (string) $location_id ) );
		return is_array( $cached ) ? $cached : array();
	}
}

if ( ! function_exists( 'aqm_ghl_create_opportunity' ) ) {
	/**
	 * Create an opportunity in GHL.
	 *
	 * Endpoint: POST https://services.leadconnectorhq.com/opportunities/
	 * Required scope: opportunities.write
	 *
	 * @param array  $payload Opportunity payload.
	 * @param string $token   Bearer token.
	 *
	 * @return array|\WP_Error array{status:int, body:string} on success, WP_Error on transport failure.
	 */
	function aqm_ghl_create_opportunity( $payload, $token ) {
		$response = wp_remote_post(
			'https://services.leadconnectorhq.com/opportunities/',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Version'       => '2021-07-28',
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}

if ( ! function_exists( 'aqm_ghl_get_opportunity_binding_for_form' ) ) {
	/**
	 * Retrieve the opportunity-creation binding for a given WordPress form.
	 *
	 * Stored at $settings['opportunity_form_binding'][ $wp_form_id ] with shape:
	 *   - enabled, pipeline_id, stage_id, name_template, status, monetary_value
	 *
	 * @param int $wp_form_id Formidable form ID.
	 *
	 * @return array Empty array when nothing is configured.
	 */
	function aqm_ghl_get_opportunity_binding_for_form( $wp_form_id ) {
		$wp_form_id = absint( $wp_form_id );
		if ( ! $wp_form_id ) {
			return array();
		}
		$settings = aqm_ghl_get_settings();
		if ( empty( $settings['opportunity_form_binding'] ) || ! is_array( $settings['opportunity_form_binding'] ) ) {
			return array();
		}
		$binding = isset( $settings['opportunity_form_binding'][ $wp_form_id ] ) ? $settings['opportunity_form_binding'][ $wp_form_id ] : null;
		if ( ! is_array( $binding ) ) {
			return array();
		}
		return wp_parse_args(
			$binding,
			array(
				'enabled'        => false,
				'pipeline_id'    => '',
				'stage_id'       => '',
				'name_template'  => '{first_name} {last_name} — {form_name}',
				'status'         => 'open',
				'monetary_value' => '',
			)
		);
	}
}

if ( ! function_exists( 'aqm_ghl_sanitize_opportunity_form_binding' ) ) {
	/**
	 * Sanitize the per-WP-form opportunity binding map for storage.
	 *
	 * @param array $raw Raw input.
	 *
	 * @return array
	 */
	function aqm_ghl_sanitize_opportunity_form_binding( $raw ) {
		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array();
		}
		$valid_statuses = array( 'open', 'won', 'lost', 'abandoned' );
		$clean = array();
		$count = 0;
		foreach ( $raw as $wp_form_id => $binding ) {
			if ( $count++ > 200 || ! is_array( $binding ) ) {
				continue;
			}
			$wp_form_id = absint( $wp_form_id );
			if ( ! $wp_form_id ) {
				continue;
			}
			$status = isset( $binding['status'] ) ? (string) $binding['status'] : 'open';
			if ( ! in_array( $status, $valid_statuses, true ) ) {
				$status = 'open';
			}
			$monetary = '';
			if ( isset( $binding['monetary_value'] ) && '' !== trim( (string) $binding['monetary_value'] ) ) {
				$num = (float) str_replace( array( ',', '$' ), '', (string) $binding['monetary_value'] );
				if ( is_finite( $num ) && $num >= 0 ) {
					$monetary = (string) $num;
				}
			}
			$clean[ $wp_form_id ] = array(
				'enabled'        => ! empty( $binding['enabled'] ),
				'pipeline_id'    => isset( $binding['pipeline_id'] ) ? sanitize_text_field( (string) $binding['pipeline_id'] ) : '',
				'stage_id'       => isset( $binding['stage_id'] ) ? sanitize_text_field( (string) $binding['stage_id'] ) : '',
				'name_template'  => isset( $binding['name_template'] ) ? sanitize_text_field( (string) $binding['name_template'] ) : '{first_name} {last_name} — {form_name}',
				'status'         => $status,
				'monetary_value' => $monetary,
			);
		}
		return $clean;
	}
}

