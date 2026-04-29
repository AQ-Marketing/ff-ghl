<?php
/**
 * Plugin Name: AQM GHL Formidable Connector
 * Description: Sends Formidable Forms submissions to GoHighLevel (LeadConnector) as Contacts using a Private Integration token.
 * Version: 1.8.2
 * Author: AQMarketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AQM_GHL_CONNECTOR_VERSION', '1.8.2' );
define( 'AQM_GHL_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AQM_GHL_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'AQM_GHL_OPTION_KEY', 'aqm_ghl_connector_settings' );
define( 'AQM_GHL_TEST_RESULT_KEY', 'aqm_ghl_last_test_result' );
define( 'AQM_GHL_LAST_SUBMISSION_RESULT_KEY', 'aqm_ghl_last_submission_result' );

require_once AQM_GHL_CONNECTOR_DIR . 'includes/helpers.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-utm-tracker.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-custom-field-provisioner.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-admin.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-handler.php';
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

	// Updates pull from a public release-mirror repo — no token required.
	// Source repo (private) publishes built ZIP releases here on each tag push.
	new AQM_GHL_Updater(
		__FILE__,
		'JustCasey76',
		'aqm-ghl-connector-releases',
		''
	);
}
add_action( 'plugins_loaded', 'aqm_ghl_connector_init' );


