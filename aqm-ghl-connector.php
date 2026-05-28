<?php
/**
 * Plugin Name: AQM GHL Formidable Connector
 * Description: Sends Formidable Forms submissions to GoHighLevel (LeadConnector) as Contacts. Supports two auth modes: legacy Private Integration Token (per sub-account) or OAuth via the AQM Marketplace App (per-install Connect button, tokens auto-refresh forever).
 * Version:     2.4.8
 * Author: AQMarketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AQM_GHL_CONNECTOR_VERSION', '2.4.8' );
define( 'AQM_GHL_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AQM_GHL_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'AQM_GHL_OPTION_KEY', 'aqm_ghl_connector_settings' );
define( 'AQM_GHL_TEST_RESULT_KEY', 'aqm_ghl_last_test_result' );
define( 'AQM_GHL_LAST_SUBMISSION_RESULT_KEY', 'aqm_ghl_last_submission_result' );

// OAuth Marketplace App identity. client_id is public per the OAuth spec; safe to ship.
// client_secret is pasted by AQM per WP install — never embedded here.
define( 'AQM_GHL_OAUTH_CLIENT_ID',    '6a0c6ed1f95594032b8df9c2-mpcq4xq8' );
define( 'AQM_GHL_OAUTH_AUTHORIZE_URL', 'https://marketplace.gohighlevel.com/v2/oauth/chooselocation' );
define( 'AQM_GHL_OAUTH_TOKEN_URL',     'https://services.leadconnectorhq.com/oauth/token' );
define( 'AQM_GHL_OAUTH_SCOPES',        'contacts.readonly contacts.write workflows.readonly locations/customFields.readonly locations/customFields.write opportunities.readonly opportunities.write' );
define( 'AQM_GHL_OAUTH_CALLBACK_ACTION', 'aqm_oauth_callback' ); // No 'ghl' in name per GHL whitelabel rule.

// DIAGNOSTIC: capture fatal + memory usage at each WP boot phase on settings page.
// Gated behind WP_DEBUG so it never writes a log file (or responds to the page
// query param) on production sites.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['page'] ) && 'aqm-ghl-connector' === $_GET['page'] && defined( 'WP_CONTENT_DIR' ) ) {
	if ( ! function_exists( 'aqm_ghl_diag_log' ) ) {
		function aqm_ghl_diag_log( $msg ) {
			$path = WP_CONTENT_DIR . '/aqm-ghl-diag.log';
			$mem  = sprintf( 'mem=%.1fMB peak=%.1fMB', memory_get_usage( true ) / 1048576, memory_get_peak_usage( true ) / 1048576 );
			@file_put_contents( $path, '[' . date( 'c' ) . '] ' . $mem . ' | ' . $msg . "\n", FILE_APPEND );
		}
	}
	register_shutdown_function( function () {
		$err = error_get_last();
		if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			aqm_ghl_diag_log( sprintf( 'FATAL type=%d msg=%s file=%s line=%d', $err['type'], $err['message'], $err['file'], $err['line'] ) );
		} else {
			aqm_ghl_diag_log( 'shutdown — clean' );
		}
	} );
	aqm_ghl_diag_log( 'request start — v' . AQM_GHL_CONNECTOR_VERSION );

	add_action( 'plugins_loaded',           function () { aqm_ghl_diag_log( 'hook: plugins_loaded' ); }, 999 );
	add_action( 'init',                     function () { aqm_ghl_diag_log( 'hook: init' ); }, 999 );
	add_action( 'wp_loaded',                function () { aqm_ghl_diag_log( 'hook: wp_loaded' ); }, 999 );
	add_action( 'admin_init',               function () { aqm_ghl_diag_log( 'hook: admin_init' ); }, 999 );
	add_filter( 'pre_set_site_transient_update_plugins', function ( $t ) { aqm_ghl_diag_log( 'hook: pre_set_site_transient_update_plugins' ); return $t; }, 999 );
	add_action( 'admin_menu',               function () { aqm_ghl_diag_log( 'hook: admin_menu' ); }, 999 );
	add_action( 'admin_enqueue_scripts',    function () { aqm_ghl_diag_log( 'hook: admin_enqueue_scripts' ); }, 1 );
	add_action( 'admin_print_scripts',      function () { aqm_ghl_diag_log( 'hook: admin_print_scripts' ); }, 999 );
	add_action( 'admin_head',               function () { aqm_ghl_diag_log( 'hook: admin_head' ); }, 999 );
}

/**
 * Emergency option-bloat protection.
 *
 * On some sites the `aqm_ghl_connector_settings` option grew to hundreds of MB
 * (the pre-v1.8.5 sync ran on every page load and appended to `custom_fields`
 * forever). Loading it via `get_option` would OOM before any plugin code could
 * run. This runs before any helper file is even loaded — it queries the raw
 * option size with $wpdb (no unserialize) and resets the bloated array fields
 * if the option is over 1 MB. The size-based reset fires automatically when the
 * option exceeds 1 MB. A manual ?aqm_ghl_emergency_reset trigger is also
 * supported, but now requires an authenticated admin request with a valid
 * 'aqm_ghl_emergency_reset' nonce — previously it was unauthenticated, which let
 * anyone wipe the plugin configuration (including OAuth tokens) on any site.
 */
if ( is_admin() && function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', function () {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}
		// Query ONLY the length — never fetch the value (it's the OOM trigger).
		$size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'aqm_ghl_connector_settings'
			)
		);

		// The manual ?aqm_ghl_emergency_reset trigger must be an authenticated admin
		// request with a valid nonce. plugins_loaded can run before pluggable.php on
		// some stacks, so guard the auth calls with function_exists — if they aren't
		// available yet the manual trigger is simply ignored (the size-based auto-heal
		// below still protects against a genuinely bloated option).
		$manual_reset = false;
		if ( ! empty( $_GET['aqm_ghl_emergency_reset'] ) && function_exists( 'wp_verify_nonce' ) && function_exists( 'current_user_can' ) ) {
			$nonce_ok     = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aqm_ghl_emergency_reset' );
			$manual_reset = $nonce_ok && current_user_can( 'manage_options' );
		}

		if ( $size <= 1048576 && ! $manual_reset ) {
			return;
		}

		// Try to extract small scalars (location_id, private_token, tags) using the index
		// trick: SUBSTRING the option_value around the position where each key appears.
		// LOCATE() returns the byte offset; we read at most 4KB after the key — never
		// pulls the bloated array fields into memory.
		$kept = array(
			'location_id'    => '',
			'private_token'  => '',
			'tags'           => '',
			'enable_logging' => 0,
			'form_ids'       => array(),
			'mapping'        => array(),
			'custom_fields'  => array(),
			'locations'      => array(),
		);

		foreach ( array( 'location_id', 'private_token', 'tags' ) as $key ) {
			$needle = 's:' . strlen( $key ) . ':"' . $key . '";';
			$snippet = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUBSTRING(option_value, LOCATE(%s, option_value), 4096) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$needle,
					'aqm_ghl_connector_settings'
				)
			);
			if ( $snippet && preg_match( '/s:\d+:"' . preg_quote( $key, '/' ) . '";s:(\d+):"((?:[^"\\\\]|\\\\.){0,2048})"/', $snippet, $m ) ) {
				$kept[ $key ] = $m[2];
			}
		}

		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $kept ) ),
			array( 'option_name'  => 'aqm_ghl_connector_settings' )
		);
		wp_cache_delete( 'aqm_ghl_connector_settings', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( function_exists( 'aqm_ghl_diag_log' ) ) {
			aqm_ghl_diag_log( sprintf( 'EMERGENCY RESET: option was %d bytes; preserved location_id+private_token+tags', $size ) );
		}
	}, 1 );
}

require_once AQM_GHL_CONNECTOR_DIR . 'includes/helpers.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-utm-tracker.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-custom-field-provisioner.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-admin.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-handler.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-opportunity-pusher.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-oauth.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-updater.php';

/**
 * Initialize UTM tracker early to capture URL parameters.
 */
function aqm_ghl_connector_init_utm_tracker() {
	new AQM_GHL_UTM_Tracker();
}
add_action( 'plugins_loaded', 'aqm_ghl_connector_init_utm_tracker', 5 );

/**
 * Bootstrap the plugin components.
 */
function aqm_ghl_connector_init() {
	new AQM_GHL_Admin();
	new AQM_GHL_Handler();
	new AQM_GHL_Opportunity_Pusher();
	new AQM_GHL_OAuth();

	// Updates pull from a public release-mirror repo — no token required.
	// Source repo (private) publishes built ZIP releases here on each tag push.
	new AQM_GHL_Updater(
		__FILE__,
		'AQ-Marketing',
		'aqm-ghl-connector-releases',
		''
	);
}
add_action( 'plugins_loaded', 'aqm_ghl_connector_init' );


