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

		// Auto-detect: if OAuth tokens are populated, use OAuth; otherwise fall back to PIT.
		if ( ! empty( $settings['oauth_access_token'] ) && ! empty( $settings['oauth_refresh_token'] ) ) {
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

		if ( 'oauth' === $mode ) {
			if ( ! class_exists( 'AQM_GHL_OAuth' ) ) {
				return new \WP_Error( 'oauth_class_missing', 'AQM_GHL_OAuth class not loaded.' );
			}
			$token = AQM_GHL_OAuth::token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$location_id = isset( $settings['oauth_location_id'] ) ? (string) $settings['oauth_location_id'] : '';
			if ( '' === $location_id ) {
				return new \WP_Error( 'oauth_no_location', 'OAuth-connected but no location ID stored — reconnect to GoHighLevel.' );
			}
			return array(
				'mode'        => 'oauth',
				'token'       => $token,
				'location_id' => $location_id,
			);
		}

		// Legacy PIT path.
		$token       = isset( $settings['private_token'] ) ? (string) $settings['private_token'] : '';
		$location_id = isset( $settings['location_id'] )   ? (string) $settings['location_id']   : '';
		if ( '' === $token || '' === $location_id ) {
			return new \WP_Error( 'pit_not_configured', 'Plugin is in PIT mode but no Private Integration Token or Location ID is set.' );
		}
		return array(
			'mode'        => 'pit',
			'token'       => $token,
			'location_id' => $location_id,
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
		$settings = aqm_ghl_get_settings();
		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			return array();
		}
		$cache_key = 'aqm_ghl_cf_' . md5( $settings['location_id'] );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Auto-fetch from API when cache is empty and credentials exist.
		$fields = aqm_ghl_fetch_ghl_custom_fields( $settings['location_id'], $settings['private_token'], false );
		return is_array( $fields ) ? $fields : array();
	}
}

if ( ! function_exists( 'aqm_ghl_sync_ghl_fields_to_forms' ) ) {
	/**
	 * Create hidden fields in selected Formidable forms for each GHL custom field
	 * and set the Default Value to Formidable URL shortcode (e.g. [frm_get param="utm_source"]).
	 * Also auto-maps the new fields in plugin settings.
	 *
	 * @return array Summary with created/updated counts.
	 */
	function aqm_ghl_sync_ghl_fields_to_forms() {
		if ( ! class_exists( 'FrmField' ) ) {
			return array( 'created' => 0, 'updated' => 0, 'mapped' => 0 );
		}

		$settings   = aqm_ghl_get_settings();
		$form_ids   = ! empty( $settings['form_ids'] ) ? (array) $settings['form_ids'] : array();
		$ghl_fields = aqm_ghl_get_cached_ghl_custom_fields();

		if ( empty( $form_ids ) || empty( $ghl_fields ) ) {
			return array( 'created' => 0, 'updated' => 0, 'mapped' => 0 );
		}

		// Core field names handled by the standard mapping — never auto-create these.
		$core_names = array( 'email', 'phone', 'phonenumber', 'firstname', 'lastname', 'name', 'fullname' );

		$created_count  = 0;
		$updated_count  = 0;
		$mapped_count   = 0;
		$settings_dirty = false;

		if ( ! isset( $settings['custom_fields'] ) || ! is_array( $settings['custom_fields'] ) ) {
			$settings['custom_fields'] = array();
		}

		foreach ( $form_ids as $form_id ) {
			$form_id = absint( $form_id );
			if ( ! $form_id ) {
				continue;
			}

			// Get all existing Formidable fields for this form.
			$existing = FrmField::getAll( array( 'fi.form_id' => $form_id ) );

			// Index by normalised default-value and normalised label.
			$by_default = array();
			$by_label   = array();
			$max_order  = 0;

			foreach ( $existing as $f ) {
				// default_value can be a string or array (e.g. some field types); never pass arrays to trim().
				$raw_dv = isset( $f->default_value ) ? $f->default_value : '';
				if ( is_array( $raw_dv ) ) {
					$raw_dv = implode( ' ', array_filter( array_map( 'strval', $raw_dv ) ) );
				} elseif ( is_scalar( $raw_dv ) ) {
					$raw_dv = (string) $raw_dv;
				} else {
					$raw_dv = '';
				}
				$dv = '' !== $raw_dv ? strtolower( trim( $raw_dv ) ) : '';
				if ( $dv ) {
					$by_default[ $dv ] = $f;
				}
				$raw_label = isset( $f->name ) ? $f->name : '';
				if ( is_array( $raw_label ) ) {
					$raw_label = implode( ' ', array_filter( array_map( 'strval', $raw_label ) ) );
				} elseif ( is_scalar( $raw_label ) ) {
					$raw_label = (string) $raw_label;
				} else {
					$raw_label = '';
				}
				$label = '' !== $raw_label ? strtolower( trim( $raw_label ) ) : '';
				if ( $label ) {
					$by_label[ $label ] = $f;
				}
				if ( isset( $f->field_order ) && (int) $f->field_order > $max_order ) {
					$max_order = (int) $f->field_order;
				}
			}

			// Ensure mapping array for this form.
			if ( ! isset( $settings['custom_fields'][ $form_id ] ) ) {
				$settings['custom_fields'][ $form_id ] = array();
			}

			// Build set of GHL IDs already mapped for this form.
			$mapped_ghl = array();
			foreach ( $settings['custom_fields'][ $form_id ] as $m ) {
				if ( ! empty( $m['ghl_field_id'] ) ) {
					$mapped_ghl[ $m['ghl_field_id'] ] = true;
				}
			}

			foreach ( $ghl_fields as $ghl ) {
				// Skip core fields.
				$norm = strtolower( str_replace( array( '_', '-', ' ' ), '', $ghl['name'] ) );
				if ( in_array( $norm, $core_names, true ) ) {
					continue;
				}
				if ( empty( $ghl['fieldKey'] ) ) {
					continue;
				}

				$field_key_raw = (string) $ghl['fieldKey']; // e.g. contact.utm_source
				$field_key_parts = explode( '.', $field_key_raw );
				$query_param_key = sanitize_key( end( $field_key_parts ) ); // e.g. utm_source
				if ( '' === $query_param_key ) {
					continue;
				}

				$default_val_legacy = '{{ ' . $field_key_raw . ' }}';
				$default_val        = '[frm_get param="' . $query_param_key . '"]';
				$default_key = strtolower( $default_val );
				$legacy_key  = strtolower( $default_val_legacy );
				$label_key   = strtolower( trim( $ghl['name'] ) );
				$frm_field_id = null;

				// 1. Field with the new shortcode default exists.
				if ( isset( $by_default[ $default_key ] ) ) {
					$frm_field_id = (int) $by_default[ $default_key ]->id;
				}
				// 1b. Field with old merge-token default exists - migrate it.
				elseif ( isset( $by_default[ $legacy_key ] ) ) {
					$frm_field_id = (int) $by_default[ $legacy_key ]->id;
					FrmField::update( $frm_field_id, array( 'default_value' => $default_val ) );
					$updated_count++;
				}
				// 2. Field with matching label exists — update its default value.
				elseif ( isset( $by_label[ $label_key ] ) ) {
					$frm_field_id = (int) $by_label[ $label_key ]->id;
					FrmField::update( $frm_field_id, array( 'default_value' => $default_val ) );
					$updated_count++;
				}
				// 3. Create a new hidden field.
				else {
					$max_order++;
					$new_id = FrmField::create(
						array(
							'form_id'       => $form_id,
							'name'          => $ghl['name'],
							'type'          => 'hidden',
							'default_value' => $default_val,
							'field_order'   => $max_order,
						)
					);
					if ( $new_id ) {
						$frm_field_id = (int) $new_id;
						$created_count++;
					}
				}

				// Ensure the mapping exists.
				if ( $frm_field_id && ! isset( $mapped_ghl[ $ghl['id'] ] ) ) {
					$settings['custom_fields'][ $form_id ][] = array(
						'ghl_field_id'  => $ghl['id'],
						'form_field_id' => (string) $frm_field_id,
					);
					$mapped_ghl[ $ghl['id'] ] = true;
					$mapped_count++;
					$settings_dirty = true;
				}
			}
		}

		if ( $settings_dirty ) {
			update_option( AQM_GHL_OPTION_KEY, $settings );
		}

		return array(
			'created' => $created_count,
			'updated' => $updated_count,
			'mapped'  => $mapped_count,
		);
	}
}

if ( ! function_exists( 'aqm_ghl_export_settings_data' ) ) {
	/**
	 * Get all settings as stored in the database (including private_token) for export.
	 *
	 * @return array Export payload with version and settings.
	 */
	function aqm_ghl_export_settings_data() {
		$raw      = get_option( AQM_GHL_OPTION_KEY, array() );
		$settings = is_array( $raw ) ? $raw : array();

		return array(
			'version'  => 1,
			'exported' => current_time( 'mysql' ),
			'plugin'   => 'aqm-ghl-connector',
			'settings' => $settings,
		);
	}
}

if ( ! function_exists( 'aqm_ghl_import_settings_data' ) ) {
	/**
	 * Validate and import settings from an exported JSON payload.
	 * Retains ALL settings including location_id, private_token, locations, form_ids, etc.
	 *
	 * @param array $data Decoded export payload (must have 'settings' key).
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	function aqm_ghl_import_settings_data( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new \WP_Error( 'invalid_format', __( 'Invalid export file: missing or invalid settings data.', 'aqm-ghl' ) );
		}

		$settings = $data['settings'];
		$existing = get_option( AQM_GHL_OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();

		// Sanitize known string keys. Tokens use strip-tags-only; other strings get full sanitize.
		$token_keys  = array( 'private_token', 'github_token' );
		$string_keys = array( 'location_id', 'tags' );
		foreach ( $token_keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$existing[ $key ] = is_string( $settings[ $key ] ) ? trim( wp_strip_all_tags( $settings[ $key ] ) ) : '';
			}
		}
		foreach ( $string_keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$existing[ $key ] = is_string( $settings[ $key ] ) ? sanitize_text_field( $settings[ $key ] ) : '';
			}
		}
		if ( array_key_exists( 'enable_logging', $settings ) ) {
			$existing['enable_logging'] = ! empty( $settings['enable_logging'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'form_ids', $settings ) && is_array( $settings['form_ids'] ) ) {
			$existing['form_ids'] = array_map( 'absint', $settings['form_ids'] );
		}
		if ( array_key_exists( 'form_id', $settings ) ) {
			$existing['form_id'] = absint( $settings['form_id'] );
		}
		if ( array_key_exists( 'mapping', $settings ) && is_array( $settings['mapping'] ) ) {
			$clean_mapping = array();
			$mapping_count = 0;
			foreach ( $settings['mapping'] as $fid => $map ) {
				if ( $mapping_count++ > 200 || ! is_array( $map ) ) {
					continue;
				}
				$fid_int = absint( $fid );
				if ( ! $fid_int ) {
					continue;
				}
				$clean_mapping[ $fid_int ] = array();
				foreach ( $map as $key => $value ) {
					$clean_key = sanitize_key( (string) $key );
					if ( '' === $clean_key ) {
						continue;
					}
					$clean_mapping[ $fid_int ][ $clean_key ] = is_scalar( $value ) ? absint( $value ) : 0;
				}
			}
			$existing['mapping'] = $clean_mapping;
		}
		if ( array_key_exists( 'custom_fields', $settings ) && is_array( $settings['custom_fields'] ) ) {
			$clean_custom = array();
			$cf_count = 0;
			foreach ( $settings['custom_fields'] as $fid => $rows ) {
				if ( $cf_count++ > 200 || ! is_array( $rows ) ) {
					continue;
				}
				$fid_int = absint( $fid );
				if ( ! $fid_int ) {
					continue;
				}
				$clean_custom[ $fid_int ] = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean_custom[ $fid_int ][] = array(
						'form_field_id' => isset( $row['form_field_id'] ) ? absint( $row['form_field_id'] ) : 0,
						'ghl_field_id'  => isset( $row['ghl_field_id'] ) ? sanitize_text_field( (string) $row['ghl_field_id'] ) : '',
					);
				}
			}
			$existing['custom_fields'] = $clean_custom;
		}
		if ( array_key_exists( 'locations', $settings ) && is_array( $settings['locations'] ) ) {
			$clean_locations = array();
			$loc_count = 0;
			foreach ( $settings['locations'] as $loc ) {
				if ( $loc_count++ > 50 || ! is_array( $loc ) ) {
					continue;
				}
				$clean_locations[] = array(
					'location_id'   => isset( $loc['location_id'] ) ? sanitize_text_field( (string) $loc['location_id'] ) : '',
					'private_token' => isset( $loc['private_token'] ) ? trim( wp_strip_all_tags( (string) $loc['private_token'] ) ) : '',
					'label'         => isset( $loc['label'] ) ? sanitize_text_field( (string) $loc['label'] ) : '',
				);
			}
			$existing['locations'] = $clean_locations;
		}

		update_option( AQM_GHL_OPTION_KEY, $existing, false );

		return true;
	}
}

if ( ! function_exists( 'aqm_ghl_get_workflow_binding_for_form' ) ) {
	/**
	 * Retrieve the GHL Workflow binding for a given WordPress form.
	 *
	 * Stored at $settings['workflow_form_binding'][ $wp_form_id ] with shape:
	 *   - enabled:      bool
	 *   - workflow_ids: array of GHL workflow IDs (multi-select)
	 *
	 * @param int $wp_form_id Formidable form ID.
	 *
	 * @return array Empty array when nothing is configured.
	 */
	function aqm_ghl_get_workflow_binding_for_form( $wp_form_id ) {
		$wp_form_id = absint( $wp_form_id );
		if ( ! $wp_form_id ) {
			return array();
		}

		$settings = aqm_ghl_get_settings();
		if ( empty( $settings['workflow_form_binding'] ) || ! is_array( $settings['workflow_form_binding'] ) ) {
			return array();
		}

		$binding = isset( $settings['workflow_form_binding'][ $wp_form_id ] ) ? $settings['workflow_form_binding'][ $wp_form_id ] : null;
		if ( ! is_array( $binding ) ) {
			return array();
		}

		return wp_parse_args(
			$binding,
			array(
				'enabled'      => false,
				'workflow_ids' => array(),
			)
		);
	}
}

if ( ! function_exists( 'aqm_ghl_fetch_workflows' ) ) {
	/**
	 * Fetch the list of workflows for a location from the GHL v2 API.
	 *
	 * Endpoint: GET https://services.leadconnectorhq.com/workflows/?locationId=...
	 * Required PIT scope: workflows.readonly
	 *
	 * Result is cached for 1 hour in a transient keyed by md5(location_id).
	 * Pass $force=true to bypass cache (e.g. user-clicked "Refresh").
	 *
	 * @param string $location_id GHL location ID.
	 * @param string $token       Private Integration Token.
	 * @param bool   $force       Skip cache and hit the API.
	 *
	 * @return array|\WP_Error Array of { id, name, status, version } objects, or WP_Error.
	 */
	function aqm_ghl_fetch_workflows( $location_id, $token, $force = false ) {
		$cache_key = 'aqm_ghl_wf_' . md5( (string) $location_id );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = add_query_arg(
			array( 'locationId' => $location_id ),
			'https://services.leadconnectorhq.com/workflows/'
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
			// Surface scope problems with a clear pointer rather than the raw GHL error.
			if ( 401 === (int) $code || 403 === (int) $code ) {
				$msg = sprintf(
					/* translators: %s: GHL error message */
					__( 'GHL refused the request (HTTP %1$d). Your Private Integration Token may be missing the "workflows.readonly" scope. In GoHighLevel go to Settings → Private Integrations → edit the token → add "View Workflows", then paste the new token in the plugin and save. (Raw: %2$s)', 'aqm-ghl' ),
					(int) $code,
					$msg
				);
			}
			return new \WP_Error( 'ghl_workflows_error', $msg );
		}

		if ( ! is_array( $body ) || ! isset( $body['workflows'] ) || ! is_array( $body['workflows'] ) ) {
			return new \WP_Error( 'ghl_workflows_invalid', __( 'Unexpected response from GHL workflows endpoint.', 'aqm-ghl' ) );
		}

		$out = array();
		foreach ( $body['workflows'] as $wf ) {
			if ( empty( $wf['id'] ) ) {
				continue;
			}
			$out[] = array(
				'id'      => sanitize_text_field( (string) $wf['id'] ),
				'name'    => isset( $wf['name'] ) ? sanitize_text_field( (string) $wf['name'] ) : '',
				'status'  => isset( $wf['status'] ) ? sanitize_text_field( (string) $wf['status'] ) : '',
				'version' => isset( $wf['version'] ) ? (int) $wf['version'] : 0,
			);
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );

		return $out;
	}
}

if ( ! function_exists( 'aqm_ghl_get_cached_workflows' ) ) {
	/**
	 * Return cached workflows for the location without forcing an API call.
	 * Used by admin UI rendering — if cache is cold the user can click
	 * "Refresh Workflows" to populate it.
	 *
	 * @param string $location_id GHL location ID.
	 *
	 * @return array
	 */
	function aqm_ghl_get_cached_workflows( $location_id ) {
		if ( empty( $location_id ) ) {
			return array();
		}
		$cached = get_transient( 'aqm_ghl_wf_' . md5( (string) $location_id ) );
		return is_array( $cached ) ? $cached : array();
	}
}

if ( ! function_exists( 'aqm_ghl_add_contact_to_workflow' ) ) {
	/**
	 * Add a GHL contact to a workflow.
	 *
	 * Endpoint: POST https://services.leadconnectorhq.com/contacts/{contactId}/workflow/{workflowId}
	 * Required PIT scope: contacts.write
	 *
	 * @param string $contact_id   GHL contact ID.
	 * @param string $workflow_id  GHL workflow ID.
	 * @param string $token        Private Integration Token.
	 *
	 * @return array|\WP_Error array{status:int, body:string} on transport success, WP_Error on transport failure.
	 */
	function aqm_ghl_add_contact_to_workflow( $contact_id, $workflow_id, $token ) {
		$url = sprintf(
			'https://services.leadconnectorhq.com/contacts/%s/workflow/%s',
			rawurlencode( (string) $contact_id ),
			rawurlencode( (string) $workflow_id )
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Version'       => '2021-07-28',
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
				// Endpoint accepts no body, but send an empty JSON object to be safe.
				'body'    => '{}',
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

if ( ! function_exists( 'aqm_ghl_sanitize_workflow_form_binding' ) ) {
	/**
	 * Sanitize the per-WP-form workflow binding map for storage.
	 *
	 * @param array $raw Raw input from the settings form.
	 *
	 * @return array Cleaned [ wp_form_id => { enabled, workflow_ids } ] map.
	 */
	function aqm_ghl_sanitize_workflow_form_binding( $raw ) {
		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array();
		}

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

			$ids = array();
			if ( isset( $binding['workflow_ids'] ) && is_array( $binding['workflow_ids'] ) ) {
				$id_count = 0;
				foreach ( $binding['workflow_ids'] as $wf_id ) {
					if ( $id_count++ > 50 ) {
						break;
					}
					$wf_id = sanitize_text_field( (string) $wf_id );
					if ( '' !== $wf_id ) {
						$ids[] = $wf_id;
					}
				}
				$ids = array_values( array_unique( $ids ) );
			}

			$clean[ $wp_form_id ] = array(
				'enabled'      => ! empty( $binding['enabled'] ),
				'workflow_ids' => $ids,
			);
		}

		return $clean;
	}
}

