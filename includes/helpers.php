<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		
		// Migrate old single-location settings to new multi-location format
		if ( ! empty( $settings['location_id'] ) && empty( $settings['locations'] ) ) {
			$settings = aqm_ghl_migrate_to_multi_location( $settings );
		}
		
		// Migrate old form_id (singular) to form_ids (plural array)
		if ( ! empty( $settings['form_id'] ) && empty( $settings['form_ids'] ) ) {
			$settings['form_ids'] = array( absint( $settings['form_id'] ) );
			unset( $settings['form_id'] );
			update_option( AQM_GHL_OPTION_KEY, $settings );
		}

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
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

		// Save migrated settings
		update_option( AQM_GHL_OPTION_KEY, $settings );

		aqm_ghl_log( 'Migrated single-location settings to multi-location format.' );

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

		// Sanitize known string keys
		$string_keys = array( 'location_id', 'private_token', 'tags', 'github_token' );
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
			$existing['mapping'] = $settings['mapping'];
		}
		if ( array_key_exists( 'custom_fields', $settings ) && is_array( $settings['custom_fields'] ) ) {
			$existing['custom_fields'] = $settings['custom_fields'];
		}
		if ( array_key_exists( 'locations', $settings ) && is_array( $settings['locations'] ) ) {
			$existing['locations'] = $settings['locations'];
		}

		update_option( AQM_GHL_OPTION_KEY, $existing, false );

		return true;
	}
}

