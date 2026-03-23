<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles sending Formidable entries to GoHighLevel.
 */
class AQM_GHL_Handler {

	/**
	 * UTM Tracker instance.
	 *
	 * @var AQM_GHL_UTM_Tracker
	 */
	private $utm_tracker;

	/**
	 * Custom Field Provisioner instance.
	 *
	 * @var AQM_GHL_Custom_Field_Provisioner
	 */
	private $provisioner;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'frm_after_create_entry', array( $this, 'maybe_send_to_ghl' ), 20, 2 );
		$this->utm_tracker  = new AQM_GHL_UTM_Tracker();
		$this->provisioner = new AQM_GHL_Custom_Field_Provisioner();
	}

	/**
	 * Process the entry and send to GoHighLevel when applicable.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 */
	public function maybe_send_to_ghl( $entry_id, $form_id ) {
		$settings = aqm_ghl_get_settings();

		$form_ids = ! empty( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? array_map( 'absint', $settings['form_ids'] ) : array();

		if ( empty( $form_ids ) || ! in_array( (int) $form_id, $form_ids, true ) ) {
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'message' => __( 'Form submission skipped: form is not enabled for GHL.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
					),
				)
			);
			return;
		}

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			aqm_ghl_log( 'Missing configuration. Aborting send.' );
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'message' => __( 'Submission aborted: missing Location ID or Private Integration Token.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
					),
				)
			);
			return;
		}

		if ( ! class_exists( 'FrmEntry' ) ) {
			aqm_ghl_log( 'Formidable Forms not available when processing entry.' );
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'message' => __( 'Submission aborted: Formidable Forms is not available.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
					),
				)
			);
			return;
		}

		$entry = FrmEntry::getOne( $entry_id, true );

		if ( ! $entry || empty( $entry->metas ) || ! is_array( $entry->metas ) ) {
			aqm_ghl_log( 'Unable to load entry metas.', array( 'entry_id' => $entry_id ) );
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'message' => __( 'Submission aborted: entry data could not be loaded.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
					),
				)
			);
			return;
		}

		$metas = $entry->metas;
		$map_all = isset( $settings['mapping'] ) ? $settings['mapping'] : array();
		$map     = isset( $map_all[ $form_id ] ) ? $map_all[ $form_id ] : array();

		$email      = $this->resolve_frm_get_stored_value( $this->get_meta_value( $metas, isset( $map['email'] ) ? $map['email'] : 0 ) );
		$raw_phone  = $this->resolve_frm_get_stored_value( $this->get_meta_value( $metas, isset( $map['phone'] ) ? $map['phone'] : 0 ) );
		$first_name = $this->resolve_frm_get_stored_value( $this->get_meta_value( $metas, isset( $map['first_name'] ) ? $map['first_name'] : 0 ) );
		$last_name  = $this->resolve_frm_get_stored_value( $this->get_meta_value( $metas, isset( $map['last_name'] ) ? $map['last_name'] : 0 ) );

		$phone = aqm_ghl_normalize_phone( $raw_phone );

		if ( empty( $email ) && empty( $phone ) ) {
			aqm_ghl_log( 'Email or phone required; both missing.', array( 'entry_id' => $entry_id ) );
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'message' => __( 'Submission aborted: email and phone were both empty.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
					),
				)
			);
			return;
		}

		$utm_params = $this->utm_tracker->get_tracked_parameters();
		if ( empty( $utm_params ) ) {
			aqm_ghl_log(
				'No UTM parameters found for this submission.',
				array(
					'entry_id' => (int) $entry_id,
					'form_id'  => (int) $form_id,
				)
			);
		}

		$payload = array(
			'locationId' => $settings['location_id'],
			'email'      => is_array( $email ) ? reset( $email ) : $email,
			'phone'      => $phone,
			'firstName'  => is_array( $first_name ) ? reset( $first_name ) : $first_name,
			'lastName'   => is_array( $last_name ) ? reset( $last_name ) : $last_name,
		);

		if ( ! empty( $settings['tags'] ) ) {
			$tags = array_filter(
				array_map(
					'trim',
					explode( ',', $settings['tags'] )
				)
			);
			if ( ! empty( $tags ) ) {
				$payload['tags'] = array_values( $tags );
			}
		}

		$custom_fields = $this->prepare_custom_fields( $settings, $metas, $form_id );
		if ( ! empty( $custom_fields ) ) {
			$payload['customFields'] = $custom_fields;
		}

		// Inject UTM parameters and GCLID using provisioned field IDs
		$payload = $this->inject_utm_data( $payload, $settings['location_id'], $settings['private_token'] );

		$payload = aqm_ghl_clean_payload( $payload );

		$response = aqm_ghl_send_contact_payload( $payload, $settings['private_token'] );

		if ( is_wp_error( $response ) ) {
			aqm_ghl_log(
				'Error sending to GoHighLevel.',
				array(
					'error'     => $response->get_error_message(),
					'entry_id'  => $entry_id,
					'form_id'   => $form_id,
				)
			);
			aqm_ghl_store_last_submission_result(
				array(
					'success' => false,
					'status'  => 0,
					'payload' => $payload,
					'response' => $response->get_error_message(),
					'message' => __( 'Submission failed: request error when calling GoHighLevel.', 'aqm-ghl' ),
					'context' => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
						'utm_params' => $utm_params,
					),
				)
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			aqm_ghl_log(
				'Non-2xx response from GoHighLevel.',
				array(
					'status'   => $code,
					'body'     => $body,
					'entry_id' => $entry_id,
				)
			);
			aqm_ghl_store_last_submission_result(
				array(
					'success'  => false,
					'status'   => $code,
					'payload'  => $payload,
					'response' => $body,
					'message'  => __( 'Submission failed: non-2xx response from GoHighLevel.', 'aqm-ghl' ),
					'context'  => array(
						'entry_id' => (int) $entry_id,
						'form_id'  => (int) $form_id,
						'utm_params' => $utm_params,
					),
				)
			);
			return;
		}

		aqm_ghl_log(
			'Successfully sent contact to GoHighLevel.',
			array(
				'entry_id' => $entry_id,
				'status'   => $code,
			)
		);
		aqm_ghl_store_last_submission_result(
			array(
				'success'  => true,
				'status'   => $code,
				'payload'  => $payload,
				'response' => wp_remote_retrieve_body( $response ),
				'message'  => __( 'Submission sent successfully to GoHighLevel.', 'aqm-ghl' ),
				'context'  => array(
					'entry_id' => (int) $entry_id,
					'form_id'  => (int) $form_id,
					'utm_params' => $utm_params,
				),
			)
		);
	}

	/**
	 * Prepare custom fields payload.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $metas    Entry metas.
	 * @param int   $form_id  Current form ID.
	 *
	 * @return array
	 */
	private function prepare_custom_fields( $settings, $metas, $form_id ) {
		if ( empty( $settings['custom_fields'] ) || ! is_array( $settings['custom_fields'] ) ) {
			return array();
		}

		$form_custom_fields = isset( $settings['custom_fields'][ $form_id ] ) ? $settings['custom_fields'][ $form_id ] : array();

		$prepared = array();

		foreach ( $form_custom_fields as $custom ) {
			$ghl_id = isset( $custom['ghl_field_id'] ) ? $custom['ghl_field_id'] : '';
			$field  = isset( $custom['form_field_id'] ) ? (int) $custom['form_field_id'] : 0;

			if ( ! $ghl_id || ! $field ) {
				continue;
			}

			$destination = $this->parse_ghl_custom_field_destination( $ghl_id );
			if ( empty( $destination['type'] ) || empty( $destination['value'] ) ) {
				aqm_ghl_log(
					'Custom field mapping skipped: invalid GHL destination identifier.',
					array(
						'form_id'         => (int) $form_id,
						'form_field_id'   => (int) $field,
						'field_key'       => $this->get_formidable_field_key( $field ),
						'ghl_destination' => $ghl_id,
					)
				);
				continue;
			}

			$raw_value   = $this->get_meta_value( $metas, $field );
			$raw_value   = $this->resolve_frm_get_stored_value( $raw_value );
			$final_value = $this->sanitize_custom_field_value( $raw_value );

			// Never send literal merge tags / placeholders as custom field values.
			if ( $this->is_merge_token_like_value( $final_value ) ) {
				aqm_ghl_log(
					'Custom field mapping skipped: merge token-like value detected.',
					array(
						'form_id'            => (int) $form_id,
						'form_field_id'      => (int) $field,
						'field_key'          => $this->get_formidable_field_key( $field ),
						'raw_saved_value'    => $raw_value,
						'ghl_destination'    => $destination,
						'final_payload_value'=> $final_value,
					)
				);
				continue;
			}

			if ( '' === $final_value ) {
				aqm_ghl_log(
					'Custom field mapping skipped: empty saved entry value.',
					array(
						'form_id'            => (int) $form_id,
						'form_field_id'      => (int) $field,
						'field_key'          => $this->get_formidable_field_key( $field ),
						'raw_saved_value'    => $raw_value,
						'ghl_destination'    => $destination,
						'final_payload_value'=> $final_value,
					)
				);
				continue;
			}

			$field_payload = array(
				'value' => $final_value,
			);
			$field_payload[ $destination['type'] ] = $destination['value'];
			$prepared[] = $field_payload;

			aqm_ghl_log(
				'Custom field mapping prepared.',
				array(
					'form_id'            => (int) $form_id,
					'form_field_id'      => (int) $field,
					'field_key'          => $this->get_formidable_field_key( $field ),
					'raw_saved_value'    => $raw_value,
					'ghl_destination'    => $destination,
					'final_payload_value'=> $final_value,
				)
			);
		}

		return $prepared;
	}

	/**
	 * Fetch a single meta value by field ID.
	 *
	 * @param array $metas    Entry metas.
	 * @param int   $field_id Field ID.
	 *
	 * @return mixed|null
	 */
	private function get_meta_value( $metas, $field_id ) {
		if ( ! $field_id ) {
			return null;
		}

		return isset( $metas[ $field_id ] ) ? $metas[ $field_id ] : null;
	}

	/**
	 * When Formidable saves a default like [frm_get param="ad_name"] as literal text in entry meta,
	 * resolve it from the current request query string (same request as frm_after_create_entry).
	 *
	 * @param mixed $value Raw meta value (string or array).
	 * @return mixed Resolved value or original if not a frm_get shortcode.
	 */
	private function resolve_frm_get_stored_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'resolve_frm_get_stored_value' ), $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return $value;
		}

		// Whole value is a Formidable frm_get / frm-get shortcode (optional extra attributes before ]).
		if ( ! preg_match( '/^\[\s*(frm_get|frm-get)\b/i', $trimmed ) || ! preg_match( '/\]$/', $trimmed ) ) {
			return $value;
		}

		if ( ! preg_match( '/\bparam\s*=\s*(["\']?)([a-zA-Z0-9_-]+)\1/i', $trimmed, $m ) ) {
			return $value;
		}

		$param = $m[2];
		$resolved = $this->lookup_request_query_param( $param );

		if ( '' !== $resolved ) {
			aqm_ghl_log(
				'Resolved frm_get shortcode from URL for GHL payload.',
				array(
					'param'   => $param,
					'preview' => is_string( $resolved ) ? substr( $resolved, 0, 120 ) : '',
				)
			);
			return $resolved;
		}

		// Let Formidable render the shortcode if registered (covers edge cases).
		if ( function_exists( 'do_shortcode' ) ) {
			$rendered = do_shortcode( $trimmed );
			if ( is_string( $rendered ) && $rendered !== $trimmed && '' !== trim( $rendered ) ) {
				aqm_ghl_log(
					'Resolved frm_get via do_shortcode for GHL payload.',
					array( 'param' => $param )
				);
				return $rendered;
			}
		}

		aqm_ghl_log(
			'frm_get shortcode in entry meta but URL param empty; sending empty for GHL.',
			array( 'param' => $param )
		);

		return '';
	}

	/**
	 * Read a query parameter from the current request (GET then REQUEST, case-insensitive key).
	 *
	 * @param string $param Parameter name from shortcode.
	 * @return string Raw string (not sanitized); caller sanitizes.
	 */
	private function lookup_request_query_param( $param ) {
		$param = (string) $param;
		if ( '' === $param ) {
			return '';
		}

		$sources = array();
		if ( ! empty( $_GET ) && is_array( $_GET ) ) {
			$sources[] = wp_unslash( $_GET );
		}
		if ( ! empty( $_REQUEST ) && is_array( $_REQUEST ) ) {
			$sources[] = wp_unslash( $_REQUEST );
		}

		foreach ( $sources as $bag ) {
			if ( isset( $bag[ $param ] ) && ! is_array( $bag[ $param ] ) ) {
				return (string) $bag[ $param ];
			}
			foreach ( $bag as $key => $val ) {
				if ( is_string( $key ) && strcasecmp( $key, $param ) === 0 && ! is_array( $val ) ) {
					return (string) $val;
				}
			}
		}

		return '';
	}

	/**
	 * Inject UTM parameters and GCLID into the payload using provisioned field IDs.
	 *
	 * @param array  $payload     Existing payload array.
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @return array Modified payload with UTM/GCLID data.
	 */
	private function inject_utm_data( $payload, $location_id, $token ) {
		$params = $this->utm_tracker->get_tracked_parameters();

		if ( empty( $params ) ) {
			return $payload;
		}

		// Get field mapping for this location (provisions if needed)
		$field_mapping = $this->provisioner->get_field_mapping( $location_id, $token );

		if ( empty( $field_mapping ) ) {
			aqm_ghl_log(
				'No field mapping available for UTM injection. Fields may not be provisioned.',
				array( 'location_id' => $location_id )
			);
			// Continue without UTM data rather than failing the entire submission
			return $payload;
		}

		// Extract UTM parameters
		$utm_params = array(
			'gclid'        => isset( $params['gclid'] ) ? $params['gclid'] : '',
			'utm_source'   => isset( $params['utm_source'] ) ? $params['utm_source'] : '',
			'utm_medium'   => isset( $params['utm_medium'] ) ? $params['utm_medium'] : '',
			'utm_campaign' => isset( $params['utm_campaign'] ) ? $params['utm_campaign'] : '',
			'utm_term'     => isset( $params['utm_term'] ) ? $params['utm_term'] : '',
			'utm_content'  => isset( $params['utm_content'] ) ? $params['utm_content'] : '',
		);

		// Initialize customFields array if needed
		if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
			$payload['customFields'] = array();
		}

		// Add each UTM parameter if we have a field ID and value
		foreach ( $utm_params as $param_key => $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );
			if ( $this->is_merge_token_like_value( $value ) ) {
				aqm_ghl_log(
					'UTM injection skipped: merge token-like value detected.',
					array(
						'location_id' => $location_id,
						'param_key'   => $param_key,
						'value'       => $value,
					)
				);
				continue;
			}

			// Get the provisioned field ID for this parameter
			if ( ! isset( $field_mapping[ $param_key ] ) || empty( $field_mapping[ $param_key ] ) ) {
				aqm_ghl_log(
					'Field mapping missing for UTM parameter.',
					array(
						'location_id' => $location_id,
						'param_key'   => $param_key,
					)
				);
				continue;
			}

			$field_id = $field_mapping[ $param_key ];

			// Check if field already exists (don't overwrite)
			$field_exists = false;
			foreach ( $payload['customFields'] as $field ) {
				if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
					$field_exists = true;
					break;
				}
			}

			if ( ! $field_exists ) {
				$payload['customFields'][] = array(
					'id'    => $field_id,
					'value' => $value,
				);
			}
		}

		return $payload;
	}

	/**
	 * Parse configured destination identifier into a GHL custom field id or key.
	 *
	 * @param string $destination Raw destination value from settings.
	 * @return array{type:string,value:string}
	 */
	private function parse_ghl_custom_field_destination( $destination ) {
		$destination = is_string( $destination ) ? trim( $destination ) : '';
		if ( '' === $destination ) {
			return array( 'type' => '', 'value' => '' );
		}

		// Support mappings configured as {{ contact.some_key }}.
		if ( preg_match( '/^\{\{\s*contact\.([a-zA-Z0-9_]+)\s*\}\}$/', $destination, $matches ) ) {
			return array(
				'type'  => 'key',
				'value' => sanitize_key( $matches[1] ),
			);
		}

		// Support mappings configured as contact.some_key.
		if ( preg_match( '/^contact\.([a-zA-Z0-9_]+)$/', $destination, $matches ) ) {
			return array(
				'type'  => 'key',
				'value' => sanitize_key( $matches[1] ),
			);
		}

		// Default behavior: treat as custom field ID.
		return array(
			'type'  => 'id',
			'value' => sanitize_text_field( $destination ),
		);
	}

	/**
	 * Convert and sanitize Formidable meta value for GHL custom fields.
	 *
	 * @param mixed $value Raw saved entry value.
	 * @return string
	 */
	private function sanitize_custom_field_value( $value ) {
		if ( null === $value ) {
			return '';
		}

		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$item = wp_json_encode( $item );
				}
				if ( is_scalar( $item ) ) {
					$clean = sanitize_text_field( (string) $item );
					if ( '' !== $clean ) {
						$flat[] = $clean;
					}
				}
			}
			return implode( ', ', $flat );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Detect merge-token / placeholder-like values that should never be sent as payload values.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private function is_merge_token_like_value( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return false;
		}

		if ( preg_match( '/^\{\{\s*contact\.[^}]+\}\}$/i', $trimmed ) ) {
			return true;
		}

		return (bool) preg_match( '/^contact\.[a-zA-Z0-9_]+$/i', $trimmed );
	}

	/**
	 * Get a Formidable field key from field ID for debug logs.
	 *
	 * @param int $field_id Formidable field ID.
	 * @return string
	 */
	private function get_formidable_field_key( $field_id ) {
		$field_id = absint( $field_id );
		if ( ! $field_id || ! class_exists( 'FrmField' ) ) {
			return '';
		}

		$field = FrmField::getOne( $field_id );
		if ( ! $field ) {
			return '';
		}

		$key = isset( $field->field_key ) ? (string) $field->field_key : '';
		return sanitize_key( $key );
	}

}


