<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles provisioning and caching of GoHighLevel custom fields per location.
 *
 * This class ensures required UTM/GCLID custom fields exist in each GHL location
 * and caches the field ID mappings to avoid repeated API calls.
 */
class AQM_GHL_Custom_Field_Provisioner {

	/**
	 * Required custom fields that must exist in each location.
	 *
	 * @var array
	 */
	private $required_fields = array(
		'gclid'        => array(
			'name'     => 'AQM - gclid',
			'alt_name' => 'gclid',
			'dataType' => 'TEXT',
		),
		'utm_source'   => array(
			'name'     => 'AQM - utm_source',
			'alt_name' => 'utm_source',
			'dataType' => 'TEXT',
		),
		'utm_medium'   => array(
			'name'     => 'AQM - utm_medium',
			'alt_name' => 'utm_medium',
			'dataType' => 'TEXT',
		),
		'utm_campaign' => array(
			'name'     => 'AQM - utm_campaign',
			'alt_name' => 'utm_campaign',
			'dataType' => 'TEXT',
		),
		'utm_term'     => array(
			'name'     => 'AQM - utm_term',
			'alt_name' => 'utm_term',
			'dataType' => 'TEXT',
		),
		'utm_content'  => array(
			'name'     => 'AQM - utm_content',
			'alt_name' => 'utm_content',
			'dataType' => 'TEXT',
		),
	);

	/**
	 * Cache expiration time in seconds (6 hours).
	 *
	 * @var int
	 */
	private $cache_expiration = 21600;

	/**
	 * Get the field ID mapping for a location, provisioning if necessary.
	 *
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @param bool   $force_refresh Force refresh of cache.
	 *
	 * @return array Mapping of param_key => field_id (e.g., ['gclid' => 'custom_abc123', ...]).
	 */
	public function get_field_mapping( $location_id, $token, $force_refresh = false ) {
		if ( empty( $location_id ) || empty( $token ) ) {
			aqm_ghl_log( 'Custom field provisioner: Missing location_id or token.', array( 'location_id' => $location_id ) );
			return array();
		}

		$cache_key = $this->get_cache_key( $location_id );
		$transient_key = $this->get_transient_key( $location_id );

		// Check transient first (throttling)
		if ( ! $force_refresh ) {
			$transient = get_transient( $transient_key );
			if ( false !== $transient ) {
				// Transient exists, use cached mapping from options
				$mapping = get_option( $cache_key, array() );
				if ( ! empty( $mapping ) && is_array( $mapping ) ) {
					return $mapping;
				}
			}
		}

		// Need to refresh - get current fields from API
		$existing_fields = $this->fetch_custom_fields( $location_id, $token );

		if ( is_wp_error( $existing_fields ) ) {
			aqm_ghl_log(
				'Custom field provisioner: Failed to fetch existing fields.',
				array(
					'location_id' => $location_id,
					'error'       => $existing_fields->get_error_message(),
				)
			);
			// Return cached mapping if available, even if expired
			$cached = get_option( $cache_key, array() );
			return is_array( $cached ) ? $cached : array();
		}

		$mapping = array();

		// Build mapping from existing fields
		foreach ( $existing_fields as $field ) {
			$field_name = isset( $field['name'] ) ? $field['name'] : '';
			$field_id   = isset( $field['id'] ) ? $field['id'] : '';

			if ( empty( $field_name ) || empty( $field_id ) ) {
				continue;
			}

			// Match by our naming convention
			foreach ( $this->required_fields as $param_key => $required ) {
				$match_primary = isset( $required['name'] ) && $field_name === $required['name'];
				$match_alt     = isset( $required['alt_name'] ) && $field_name === $required['alt_name'];
				if ( $match_primary || $match_alt ) {
					$mapping[ $param_key ] = $field_id;
					break;
				}
			}
		}

		// Create missing fields
		foreach ( $this->required_fields as $param_key => $required ) {
			if ( ! isset( $mapping[ $param_key ] ) ) {
				aqm_ghl_log(
					'Custom field provisioner: Attempting to create missing field.',
					array(
						'location_id' => $location_id,
						'param_key'   => $param_key,
						'field_name'  => $required['name'],
					)
				);
				
				$field_id = $this->create_custom_field( $location_id, $token, $required );
				
				if ( ! is_wp_error( $field_id ) && ! empty( $field_id ) ) {
					$mapping[ $param_key ] = $field_id;
					aqm_ghl_log(
						'Custom field provisioner: Successfully created field.',
						array(
							'location_id' => $location_id,
							'param_key'   => $param_key,
							'field_id'    => $field_id,
						)
					);
				} else {
					aqm_ghl_log(
						'Custom field provisioner: Failed to create field.',
						array(
							'location_id' => $location_id,
							'param_key'   => $param_key,
							'field_name'  => $required['name'],
							'error'       => is_wp_error( $field_id ) ? $field_id->get_error_message() : 'Unknown error',
							'field_id_response' => $field_id,
						)
					);
				}
			}
		}

		// Cache the mapping
		update_option( $cache_key, $mapping, false );
		set_transient( $transient_key, true, $this->cache_expiration );

		return $mapping;
	}

	/**
	 * Fetch all custom fields for a location from GHL API.
	 *
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 *
	 * @return array|\WP_Error Array of field objects or WP_Error on failure.
	 */
	private function fetch_custom_fields( $location_id, $token ) {
		$url = sprintf( 'https://services.leadconnectorhq.com/locations/%s/customFields', $location_id );

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Version'       => '2021-07-28',
			),
			'timeout' => 15,
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'ghl_api_error',
				sprintf( 'GHL API returned status %d: %s', $code, $body ),
				array( 'status' => $code, 'body' => $body )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			aqm_ghl_log(
				'Custom field provisioner: Invalid JSON response from GHL API.',
				array(
					'location_id' => $location_id,
					'response_body' => $body,
				)
			);
			return new WP_Error( 'ghl_api_invalid_response', 'Invalid JSON response from GHL API.' );
		}

		// GHL API may return fields in different structures
		// Try 'customFields' key first
		if ( isset( $data['customFields'] ) && is_array( $data['customFields'] ) ) {
			aqm_ghl_log(
				'Custom field provisioner: Found fields in customFields key.',
				array(
					'location_id' => $location_id,
					'field_count' => count( $data['customFields'] ),
				)
			);
			return $data['customFields'];
		}
		
		// Try 'fields' key
		if ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) {
			aqm_ghl_log(
				'Custom field provisioner: Found fields in fields key.',
				array(
					'location_id' => $location_id,
					'field_count' => count( $data['fields'] ),
				)
			);
			return $data['fields'];
		}
		
		// If data is directly an array, return it
		if ( is_array( $data ) ) {
			aqm_ghl_log(
				'Custom field provisioner: Using data as direct array.',
				array(
					'location_id' => $location_id,
					'field_count' => count( $data ),
					'data_keys' => array_keys( $data ),
				)
			);
			return $data;
		}

		aqm_ghl_log(
			'Custom field provisioner: No fields found in response.',
			array(
				'location_id' => $location_id,
				'response_data' => $data,
			)
		);
		
		return array();
	}

	/**
	 * Create a custom field in GHL.
	 *
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @param array  $field_data  Field data (name, dataType).
	 *
	 * @return string|\WP_Error Field ID on success, WP_Error on failure.
	 */
	private function create_custom_field( $location_id, $token, $field_data ) {
		$url = sprintf( 'https://services.leadconnectorhq.com/locations/%s/customFields', $location_id );

		$payload = array(
			'name'     => $field_data['name'],
			'dataType' => $field_data['dataType'],
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Version'       => '2021-07-28',
			),
			'timeout' => 15,
			'body'    => wp_json_encode( $payload ),
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'ghl_api_error',
				sprintf( 'GHL API returned status %d: %s', $code, $body ),
				array( 'status' => $code, 'body' => $body )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Log the response for debugging
		aqm_ghl_log(
			'Custom field provisioner: Create field API response.',
			array(
				'location_id' => $location_id,
				'field_name'  => $field_data['name'],
				'status_code' => $code,
				'response_body' => $body,
				'parsed_data' => $data,
			)
		);

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ghl_api_invalid_response', 'Invalid JSON response from GHL API when creating field: ' . substr( $body, 0, 200 ) );
		}

		// Check for field ID in various possible response structures
		if ( ! empty( $data['id'] ) ) {
			return $data['id'];
		}
		
		// Some APIs return the field object directly
		if ( ! empty( $data['customField'] ) && ! empty( $data['customField']['id'] ) ) {
			return $data['customField']['id'];
		}
		
		// Check if the response contains a field object
		if ( ! empty( $data['field'] ) && ! empty( $data['field']['id'] ) ) {
			return $data['field']['id'];
		}

		return new WP_Error( 'ghl_api_invalid_response', 'No field ID found in response. Response structure: ' . wp_json_encode( array_keys( $data ) ) );
	}

	/**
	 * Get the cache key for a location's field mapping.
	 *
	 * @param string $location_id GHL Location ID.
	 *
	 * @return string
	 */
	private function get_cache_key( $location_id ) {
		return 'aqm_ghl_field_mapping_' . md5( $location_id );
	}

	/**
	 * Get the transient key for a location (throttling).
	 *
	 * @param string $location_id GHL Location ID.
	 *
	 * @return string
	 */
	private function get_transient_key( $location_id ) {
		return 'aqm_ghl_field_mapping_transient_' . md5( $location_id );
	}

	/**
	 * Clear cache for a specific location.
	 *
	 * @param string $location_id GHL Location ID.
	 */
	public function clear_cache( $location_id ) {
		$cache_key = $this->get_cache_key( $location_id );
		$transient_key = $this->get_transient_key( $location_id );

		delete_option( $cache_key );
		delete_transient( $transient_key );
	}

	/**
	 * Clear cache for all locations.
	 */
	public function clear_all_caches() {
		global $wpdb;

		// Delete all field mapping options
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'aqm_ghl_field_mapping_%'
			)
		);

		// Delete all transients
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_aqm_ghl_field_mapping_transient_%',
				'_transient_timeout_aqm_ghl_field_mapping_transient_%'
			)
		);
	}
}
