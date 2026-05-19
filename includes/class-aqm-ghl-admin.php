<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for plugin settings.
 */
class AQM_GHL_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_aqm_ghl_get_form_fields', array( $this, 'ajax_get_form_fields' ) );
		add_action( 'wp_ajax_aqm_ghl_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_aqm_ghl_clear_update_cache', array( $this, 'ajax_clear_update_cache' ) );
		add_action( 'wp_ajax_aqm_ghl_provision_fields', array( $this, 'ajax_provision_fields' ) );
		add_action( 'wp_ajax_aqm_ghl_fetch_ghl_fields', array( $this, 'ajax_fetch_ghl_fields' ) );
		add_action( 'wp_ajax_aqm_ghl_fetch_workflows', array( $this, 'ajax_fetch_workflows' ) );
		add_action( 'admin_post_aqm_ghl_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_aqm_ghl_import_settings', array( $this, 'handle_import_settings' ) );
	}

	/**
	 * Register the plugin page as a top-level admin menu (just after Formidable).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'GHL + Formidable', 'aqm-ghl' ),
			__( 'GHL + Formidable', 'aqm-ghl' ),
			'manage_options',
			'aqm-ghl-connector',
			array( $this, 'render_settings_page' ),
			'dashicons-forms',
			27.1 // Aim to appear immediately after Formidable.
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'aqm_ghl_connector',
			AQM_GHL_OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Enqueue admin assets for the settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only load on the plugin's settings page — prevents broadcasting nonces,
		// GHL field metadata, and form lists to every admin page.
		if ( ! is_string( $hook ) || strpos( $hook, 'aqm-ghl-connector' ) === false ) {
			return;
		}

		$_diag = function_exists( 'aqm_ghl_diag_log' );
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: ENTER (hook=' . $hook . ')' ); }

		wp_enqueue_style(
			'aqm-ghl-admin',
			AQM_GHL_CONNECTOR_URL . 'assets/css/admin.css',
			array(),
			AQM_GHL_CONNECTOR_VERSION
		);

		wp_enqueue_script(
			'aqm-ghl-admin',
			AQM_GHL_CONNECTOR_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			AQM_GHL_CONNECTOR_VERSION,
			true
		);

		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: after wp_enqueue_style/script' ); }

		$current_settings = aqm_ghl_get_settings();
		if ( $_diag ) {
			$opt_size = strlen( wp_json_encode( $current_settings ) );
			$cf_count = isset( $current_settings['custom_fields'] ) && is_array( $current_settings['custom_fields'] ) ? count( $current_settings['custom_fields'], COUNT_RECURSIVE ) : 0;
			$loc_count = isset( $current_settings['locations'] ) && is_array( $current_settings['locations'] ) ? count( $current_settings['locations'] ) : 0;
			aqm_ghl_diag_log( 'enqueue_assets: got settings (json_size=' . $opt_size . 'B custom_fields_recursive=' . $cf_count . ' locations=' . $loc_count . ')' );
		}

		$forms = aqm_ghl_get_formidable_forms();
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: got forms (count=' . count( $forms ) . ')' ); }

		$form_options = array();
		foreach ( $forms as $form ) {
			$form_options[] = array(
				'id'   => (int) $form->id,
				'name' => $form->name,
			);
		}
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: built form_options' ); }

		// Normalize mapping keys to integers for consistent JavaScript access
		$mapping_normalized = array();
		if ( ! empty( $current_settings['mapping'] ) && is_array( $current_settings['mapping'] ) ) {
			foreach ( $current_settings['mapping'] as $fid => $map ) {
				$fid_int = absint( $fid );
				$mapping_normalized[ $fid_int ] = $map;
			}
		}
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: normalized mapping (count=' . count( $mapping_normalized ) . ')' ); }

		// Normalize custom fields keys to integers
		$custom_fields_normalized = array();
		if ( ! empty( $current_settings['custom_fields'] ) && is_array( $current_settings['custom_fields'] ) ) {
			foreach ( $current_settings['custom_fields'] as $fid => $fields ) {
				$fid_int = absint( $fid );
				$custom_fields_normalized[ $fid_int ] = $fields;
			}
		}
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: normalized custom_fields (count=' . count( $custom_fields_normalized ) . ')' ); }

		$ghl_cached = aqm_ghl_get_cached_ghl_custom_fields();
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: got ghl_cached (count=' . ( is_array( $ghl_cached ) ? count( $ghl_cached ) : -1 ) . ')' ); }

		wp_localize_script(
			'aqm-ghl-admin',
			'aqmGhlSettings',
			array(
				'nonce'         => wp_create_nonce( 'aqm_ghl_admin' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'selectedForms' => isset( $current_settings['form_ids'] ) && is_array( $current_settings['form_ids'] ) ? array_map( 'absint', $current_settings['form_ids'] ) : array(),
				'mapping'       => $mapping_normalized,
				'customFields'  => $custom_fields_normalized,
				'ghlFields'     => $ghl_cached,
				'forms'         => $form_options,
				'optionKey'     => AQM_GHL_OPTION_KEY,
				'labels'        => array(
					'loading'    => __( 'Loading fields…', 'aqm-ghl' ),
					'select'     => __( 'Select a field', 'aqm-ghl' ),
					'selectGhl'  => __( 'Select a GHL field', 'aqm-ghl' ),
				),
			)
		);
		if ( $_diag ) { aqm_ghl_diag_log( 'enqueue_assets: EXIT' ); }
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Diagnostic wrapper: capture any Throwable and surface it on the page
		// instead of letting WP show the generic critical-error screen.
		try {
			$this->render_settings_page_inner();
		} catch ( \Throwable $e ) {
			echo '<div class="wrap"><h1>AQM GHL Connector — diagnostic</h1>';
			echo '<div class="notice notice-error"><p><strong>Caught exception while rendering settings page</strong></p>';
			echo '<pre style="white-space:pre-wrap; background:#fff; padding:10px; border:1px solid #ccc;">';
			echo esc_html( get_class( $e ) ) . ': ' . esc_html( $e->getMessage() ) . "\n";
			echo 'in ' . esc_html( $e->getFile() ) . ':' . (int) $e->getLine() . "\n\n";
			echo esc_html( $e->getTraceAsString() );
			echo '</pre></div></div>';
		}
	}

	/**
	 * Original settings page render (wrapped by render_settings_page for diagnostics).
	 */
	private function render_settings_page_inner() {
		$settings          = aqm_ghl_get_settings();
		$forms             = aqm_ghl_get_formidable_forms();
		$last_test         = aqm_ghl_get_last_test_result();
		$last_payload      = ! empty( $last_test['payload'] ) ? wp_json_encode( $last_test['payload'], JSON_PRETTY_PRINT ) : '';
		$last_submission   = aqm_ghl_get_last_submission_result();
		$last_submission_payload = ! empty( $last_submission['payload'] ) ? wp_json_encode( $last_submission['payload'], JSON_PRETTY_PRINT ) : '';
		$last_submission_context = ! empty( $last_submission['context'] ) ? wp_json_encode( $last_submission['context'], JSON_PRETTY_PRINT ) : '';
		$import_status = isset( $_GET['aqm_ghl_import'] ) ? sanitize_text_field( wp_unslash( $_GET['aqm_ghl_import'] ) ) : '';
		?>
		<div class="wrap aqm-ghl-wrap">
			<h1><?php esc_html_e( 'GHL + Formidable', 'aqm-ghl' ); ?></h1>
			<?php settings_errors(); ?>
			<?php if ( 'success' === $import_status ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings imported successfully. All settings including GHL Location ID and Private Integration Token have been restored.', 'aqm-ghl' ); ?></p>
				</div>
			<?php elseif ( 'error_nonce' === $import_status ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Import failed: security check failed.', 'aqm-ghl' ); ?></p>
				</div>
			<?php elseif ( 'error_empty' === $import_status ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Import failed: no file uploaded and no JSON pasted.', 'aqm-ghl' ); ?></p>
				</div>
			<?php elseif ( 'error_json' === $import_status ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Import failed: invalid JSON.', 'aqm-ghl' ); ?></p>
				</div>
			<?php elseif ( 'error' === $import_status && ! empty( $_GET['aqm_ghl_message'] ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['aqm_ghl_message'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! class_exists( 'FrmForm' ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Formidable Forms is not active. Install and activate it to configure this integration.', 'aqm-ghl' ); ?></p>
				</div>
			<?php endif; ?>
			<?php
			// Configuration completeness check: have an auth method (OAuth OR legacy PIT) AND at least one form picked.
			$oauth_connected = class_exists( 'AQM_GHL_OAuth' ) && AQM_GHL_OAuth::is_connected();
			$pit_configured  = ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] );
			$has_auth        = $oauth_connected || $pit_configured;
			$has_forms       = ! empty( $settings['form_ids'] );
			?>
			<?php if ( ! $has_auth || ! $has_forms ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Setup needed:', 'aqm-ghl' ); ?></strong>
						<?php if ( ! $has_auth && ! $has_forms ) : ?>
							<?php esc_html_e( 'Connect to GoHighLevel below and select at least one Formidable form to start sending submissions.', 'aqm-ghl' ); ?>
						<?php elseif ( ! $has_auth ) : ?>
							<?php esc_html_e( 'Connect to GoHighLevel below to enable form submissions.', 'aqm-ghl' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Select at least one Formidable form below to send submissions to GoHighLevel.', 'aqm-ghl' ); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_authentication_section( $settings ); ?>

			<form method="post" action="options.php" class="aqm-ghl-form">
				<?php
				settings_fields( 'aqm_ghl_connector' );
				?>

				<?php // Legacy Private Integration Token fields are no longer surfaced in the UI
				// since AQM has fully migrated to the OAuth Marketplace App flow. The underlying
				// values still persist in settings (so v1.x sites that haven't migrated yet keep
				// working), and the auth router auto-detects which mode to use. Should we ever
				// need to expose PIT fields again for debugging, restore the previous <details>
				// block from git history. ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Which forms to send', 'aqm-ghl' ); ?></label></th>
						<td>
							<div class="aqm-ghl-form-checkboxes">
								<?php if ( ! empty( $forms ) ) : ?>
									<?php foreach ( $forms as $form ) : ?>
										<?php $is_checked = in_array( (int) $form->id, isset( $settings['form_ids'] ) ? (array) $settings['form_ids'] : array(), true ); ?>
										<label class="aqm-ghl-form-checkbox-item">
											<input
												type="checkbox"
												name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[form_ids][]"
												value="<?php echo esc_attr( $form->id ); ?>"
												class="aqm-ghl-form-checkbox"
												data-form-id="<?php echo esc_attr( $form->id ); ?>"
												<?php checked( $is_checked ); ?>
											/>
											<span class="aqm-ghl-form-checkbox-label">
												<?php echo esc_html( (string) $form->name ); ?>
												<span class="description" style="font-size: 11px; color: #646970;">(ID: <?php echo (int) $form->id; ?>)</span>
											</span>
										</label>
									<?php endforeach; ?>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No Formidable Forms found yet. Create a form in Formidable, then come back here.', 'aqm-ghl' ); ?></p>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Tick each form whose submissions you want sent to GoHighLevel. Forms you don\'t tick are ignored.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top: 2em;"><?php esc_html_e( 'Field mapping', 'aqm-ghl' ); ?></h2>
				<p class="description" style="margin-bottom: 1em;">
					<?php esc_html_e( 'Tell the plugin which Formidable field goes into which GoHighLevel contact field. Custom fields are auto-detected.', 'aqm-ghl' ); ?>
				</p>
				<?php if ( empty( $settings['form_ids'] ) ) : ?>
					<p style="padding: 14px; background: #f6f7f7; border-left: 3px solid #c3c4c7; color: #50575e;">
						<em><?php esc_html_e( 'Pick at least one form above to start mapping fields.', 'aqm-ghl' ); ?></em>
					</p>
				<?php else : ?>
					<p>
						<button type="button" class="button button-secondary" id="aqm-ghl-fetch-ghl-fields"><?php esc_html_e( 'Refresh GHL custom fields', 'aqm-ghl' ); ?></button>
						<button type="button" class="button button-secondary" id="aqm-ghl-provision-fields"><?php esc_html_e( 'Create UTM / GCLID fields in GHL', 'aqm-ghl' ); ?></button>
						<span id="aqm-ghl-fetch-result" class="aqm-ghl-fetch-result" style="display:none;"></span>
						<span id="aqm-ghl-provision-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
					</p>
					<p class="description" style="margin-top: 0.25em;">
						<?php esc_html_e( 'Click "Refresh" if you added or renamed fields in GHL. "Create UTM / GCLID fields" makes new GHL custom fields for tracking attribution.', 'aqm-ghl' ); ?>
					</p>
				<?php endif; ?>
				<div id="aqm-ghl-form-mapping-containers">
					<!-- Per-form mapping containers injected by JS -->
				</div>

				<?php $this->render_workflows_section( $settings, $forms ); ?>

				<h2 style="margin-top: 2em;"><?php esc_html_e( 'Optional settings', 'aqm-ghl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-tags"><?php esc_html_e( 'Contact tags', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[tags]" id="aqm-ghl-tags" type="text" value="<?php echo esc_attr( $settings['tags'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. website-lead, contact-form', 'aqm-ghl' ); ?>" />
							<p class="description"><?php esc_html_e( 'Tags to apply to every contact created from this site. Separate multiple tags with commas.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-logging"><?php esc_html_e( 'Debug logging', 'aqm-ghl' ); ?></label></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[enable_logging]" id="aqm-ghl-logging" type="checkbox" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?> />
								<?php esc_html_e( 'Write detailed activity to the WordPress error log for troubleshooting.', 'aqm-ghl' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave off unless you\'re debugging something — the log gets noisy.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'aqm-ghl' ) ); ?>

				<!-- ── Diagnostics & Admin Tools ── -->
				<!-- Wrapped in collapsed disclosures so non-tech users aren't overwhelmed by debug output by default. -->

				<h2 style="margin-top: 2.5em; border-top: 1px solid #dcdcde; padding-top: 1.5em;"><?php esc_html_e( 'Diagnostics & admin tools', 'aqm-ghl' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These tools are for troubleshooting and don\'t need to be touched in normal use.', 'aqm-ghl' ); ?></p>

				<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
					<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
						<?php esc_html_e( 'Test connection', 'aqm-ghl' ); ?>
					</summary>
					<div style="padding: 12px 18px;">
						<p><?php esc_html_e( 'Send a mock "John Doe" contact to your GoHighLevel sub-account to verify the connection works.', 'aqm-ghl' ); ?></p>
						<p>
							<button type="button" class="button button-secondary" id="aqm-ghl-test-connection"><?php esc_html_e( 'Send Test Contact', 'aqm-ghl' ); ?></button>
						</p>
						<div id="aqm-ghl-test-result" class="notice inline" style="display:none;"></div>
					</div>
				</details>

				<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
					<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
						<?php
						/* translators: %s: current version */
						printf( esc_html__( 'Plugin updates (current version: %s)', 'aqm-ghl' ), esc_html( AQM_GHL_CONNECTOR_VERSION ) );
						?>
					</summary>
					<div style="padding: 12px 18px;">
						<p><?php esc_html_e( 'Plugin auto-updates from a release feed. If a new version isn\'t appearing in the WordPress updates page, clear the update cache.', 'aqm-ghl' ); ?></p>
						<p>
							<button type="button" class="button button-secondary" id="aqm-ghl-clear-cache"><?php esc_html_e( 'Clear Update Cache', 'aqm-ghl' ); ?></button>
							<span id="aqm-ghl-cache-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
						</p>
					</div>
				</details>

				<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
					<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
						<?php esc_html_e( 'Last test result', 'aqm-ghl' ); ?>
						<?php if ( ! empty( $last_test['timestamp'] ) ) : ?>
							<span style="font-weight: normal; color: #646970; font-size: 12px;">— <?php echo esc_html( $last_test['timestamp'] ); ?></span>
						<?php endif; ?>
					</summary>
					<div style="padding: 12px 18px;">
						<?php if ( ! empty( $last_test['timestamp'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Status:', 'aqm-ghl' ); ?></strong> <?php echo esc_html( $last_test['status'] ); ?></p>
							<p><strong><?php esc_html_e( 'Message:', 'aqm-ghl' ); ?></strong> <?php echo esc_html( $last_test['message'] ); ?></p>
							<?php if ( $last_payload ) : ?>
								<p><strong><?php esc_html_e( 'Request payload:', 'aqm-ghl' ); ?></strong></p>
								<pre style="max-height: 240px; overflow: auto;"><?php echo esc_html( $last_payload ); ?></pre>
							<?php endif; ?>
							<?php if ( ! empty( $last_test['response'] ) ) : ?>
								<p><strong><?php esc_html_e( 'Response body:', 'aqm-ghl' ); ?></strong></p>
								<pre style="max-height: 240px; overflow: auto;"><?php echo esc_html( $last_test['response'] ); ?></pre>
							<?php endif; ?>
						<?php else : ?>
							<p><em><?php esc_html_e( 'No test run yet. Use "Test connection" above.', 'aqm-ghl' ); ?></em></p>
						<?php endif; ?>
					</div>
				</details>

				<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
					<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
						<?php esc_html_e( 'Last live form submission', 'aqm-ghl' ); ?>
						<?php if ( ! empty( $last_submission['timestamp'] ) ) : ?>
							<span style="font-weight: normal; color: #646970; font-size: 12px;">— <?php echo esc_html( $last_submission['timestamp'] ); ?></span>
						<?php endif; ?>
					</summary>
					<div style="padding: 12px 18px;">
						<?php if ( ! empty( $last_submission['timestamp'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Status:', 'aqm-ghl' ); ?></strong> <?php echo esc_html( $last_submission['status'] ); ?></p>
							<p><strong><?php esc_html_e( 'Message:', 'aqm-ghl' ); ?></strong> <?php echo esc_html( $last_submission['message'] ); ?></p>
							<?php if ( $last_submission_context ) : ?>
								<p><strong><?php esc_html_e( 'Context:', 'aqm-ghl' ); ?></strong></p>
								<pre style="max-height: 240px; overflow: auto;"><?php echo esc_html( $last_submission_context ); ?></pre>
							<?php endif; ?>
							<?php if ( $last_submission_payload ) : ?>
								<p><strong><?php esc_html_e( 'Request payload:', 'aqm-ghl' ); ?></strong></p>
								<pre style="max-height: 240px; overflow: auto;"><?php echo esc_html( $last_submission_payload ); ?></pre>
							<?php endif; ?>
							<?php if ( ! empty( $last_submission['response'] ) ) : ?>
								<p><strong><?php esc_html_e( 'Response body:', 'aqm-ghl' ); ?></strong></p>
								<pre style="max-height: 240px; overflow: auto;"><?php echo esc_html( $last_submission['response'] ); ?></pre>
							<?php endif; ?>
						<?php else : ?>
							<p><em><?php esc_html_e( 'No live form submissions recorded yet.', 'aqm-ghl' ); ?></em></p>
						<?php endif; ?>
					</div>
				</details>
			</form>

			<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
				<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
					<?php esc_html_e( 'Backup / restore settings', 'aqm-ghl' ); ?>
				</summary>
				<div style="padding: 12px 18px;">
					<p><?php esc_html_e( 'Export all settings (including credentials) to a JSON file you can save or use to migrate to another WordPress install.', 'aqm-ghl' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Export', 'aqm-ghl' ); ?></th>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aqm_ghl_export_settings' ), 'aqm_ghl_export' ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Download settings (JSON)', 'aqm-ghl' ); ?>
								</a>
								<p class="description"><?php esc_html_e( 'Saves a copy of all current settings including credentials. Store the file securely.', 'aqm-ghl' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Import', 'aqm-ghl' ); ?></th>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="aqm-ghl-import-form">
									<input type="hidden" name="action" value="aqm_ghl_import_settings" />
									<?php wp_nonce_field( 'aqm_ghl_import', 'aqm_ghl_import_nonce' ); ?>
									<p>
										<label for="aqm-ghl-import-file"><?php esc_html_e( 'Upload JSON file:', 'aqm-ghl' ); ?></label>
										<input type="file" name="aqm_ghl_import_file" id="aqm-ghl-import-file" accept=".json,application/json" />
									</p>
									<p class="description"><?php esc_html_e( 'Or paste JSON below (from a previous export).', 'aqm-ghl' ); ?></p>
									<p>
										<textarea name="aqm_ghl_import_json" id="aqm-ghl-import-json" class="large-text code" rows="6" placeholder='{"version":1,"plugin":"aqm-ghl-connector","settings":{...}}'></textarea>
									</p>
									<p>
										<button type="submit" class="button button-secondary"><?php esc_html_e( 'Import settings', 'aqm-ghl' ); ?></button>
									</p>
								</form>
								<p class="description"><?php esc_html_e( 'Import replaces all current settings. Use only trusted export files.', 'aqm-ghl' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render the Authentication section — OAuth Connect flow + legacy PIT
	 * fields side-by-side. Surfaces the result of any in-flight OAuth handoff
	 * via the `aqm_oauth_status` URL parameter set by AQM_GHL_OAuth.
	 *
	 * @param array $settings Current plugin settings.
	 */
	private function render_authentication_section( $settings ) {
		$status  = isset( $_GET['aqm_oauth_status'] )  ? sanitize_text_field( wp_unslash( $_GET['aqm_oauth_status'] ) )  : '';
		$msg     = isset( $_GET['aqm_oauth_message'] ) ? sanitize_text_field( wp_unslash( $_GET['aqm_oauth_message'] ) ) : '';
		$err     = isset( $_GET['aqm_oauth_err'] )     ? sanitize_text_field( wp_unslash( $_GET['aqm_oauth_err'] ) )     : '';

		$is_connected     = class_exists( 'AQM_GHL_OAuth' ) ? AQM_GHL_OAuth::is_connected() : false;
		$location_name    = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';
		$location_id      = isset( $settings['oauth_location_id'] )   ? (string) $settings['oauth_location_id']   : '';
		$expires_at       = isset( $settings['oauth_token_expires_at'] ) ? (int) $settings['oauth_token_expires_at'] : 0;
		$active_mode      = function_exists( 'aqm_ghl_get_auth_mode' ) ? aqm_ghl_get_auth_mode() : 'pit';
		?>

		<?php // Status notices from the OAuth flow redirects ?>
		<?php if ( 'connected' === $status ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<strong><?php esc_html_e( '✓ Connected to GoHighLevel.', 'aqm-ghl' ); ?></strong>
				<?php esc_html_e( 'Tokens will auto-refresh — you don\'t need to reconnect.', 'aqm-ghl' ); ?>
			</p></div>
		<?php elseif ( 'disconnected' === $status ) : ?>
			<div class="notice notice-info is-dismissible"><p>
				<?php esc_html_e( 'Disconnected from GoHighLevel. Reconnect below when you\'re ready.', 'aqm-ghl' ); ?>
			</p></div>
		<?php elseif ( '' !== $status ) : ?>
			<div class="notice notice-error is-dismissible"><p>
				<strong><?php esc_html_e( 'GoHighLevel connection failed.', 'aqm-ghl' ); ?></strong>
				<?php if ( '' !== $msg ) : ?>
					<br><code style="white-space: pre-wrap;"><?php echo esc_html( $msg ); ?></code>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>

		<?php if ( 'missing_secret' === $err ) : ?>
			<div class="notice notice-error is-dismissible"><p>
				<?php esc_html_e( 'Paste the AQM Client Secret below and save before clicking Connect.', 'aqm-ghl' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( $is_connected ) : ?>
			<!-- COMPACT CONNECTED STATUS CARD -->
			<div style="margin: 1em 0; border-left: 4px solid #00a32a; background: #fff; padding: 14px 18px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
				<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
					<span style="font-size: 22px; line-height: 1;">✓</span>
					<div style="flex: 1; min-width: 220px;">
						<strong style="font-size: 15px;"><?php esc_html_e( 'Connected to GoHighLevel', 'aqm-ghl' ); ?></strong>
						<div style="color: #50575e; font-size: 13px; margin-top: 2px;">
							<?php
							if ( $location_name ) {
								printf(
									/* translators: %s: sub-account display name */
									esc_html__( 'Sub-account: %s', 'aqm-ghl' ),
									'<strong>' . esc_html( $location_name ) . '</strong>'
								);
							} else {
								echo '<code style="font-size: 11px;">' . esc_html( $location_id ) . '</code>';
							}
							?>
						</div>
					</div>
					<div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
							<input type="hidden" name="action" value="aqm_oauth_start" />
							<?php wp_nonce_field( 'aqm_oauth_start' ); ?>
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Reconnect', 'aqm-ghl' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-left: 4px;">
							<input type="hidden" name="action" value="aqm_oauth_disconnect" />
							<?php wp_nonce_field( 'aqm_oauth_disconnect' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'aqm-ghl' ); ?></button>
						</form>
					</div>
				</div>
			</div>
		<?php else : ?>
			<!-- CONNECT SETUP FORM -->
			<div style="margin: 1em 0; border: 2px solid #2271b1; background: #fff; padding: 18px 22px;">
				<h2 style="margin: 0 0 8px;"><?php esc_html_e( 'Connect to GoHighLevel', 'aqm-ghl' ); ?></h2>
				<p style="margin: 0 0 14px; color: #50575e;">
					<?php esc_html_e( 'Click Connect below, sign in to GoHighLevel, and pick which sub-account this WordPress site should send form submissions to.', 'aqm-ghl' ); ?>
				</p>

				<?php
				// Show the last 4 chars of the saved secret so the user can verify
				// which value is active without exposing the full secret in the DOM.
				$saved_secret  = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
				$secret_hint   = '';
				if ( '' !== $saved_secret ) {
					$tail        = substr( $saved_secret, -4 );
					$secret_hint = str_repeat( '•', 12 ) . $tail . ' (saved)';
				}
				?>
				<form method="post" action="options.php" style="margin: 0 0 14px;">
					<?php settings_fields( 'aqm_ghl_connector' ); ?>
					<p style="margin: 0 0 8px;">
						<label style="font-weight: 600; display: block; margin-bottom: 4px;"><?php esc_html_e( 'AQM Client Secret', 'aqm-ghl' ); ?></label>
						<input type="password" name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[oauth_client_secret]" value="" placeholder="<?php echo $secret_hint ? esc_attr( $secret_hint ) : esc_attr__( 'Paste the secret provided by AQM', 'aqm-ghl' ); ?>" class="regular-text" style="width: 100%; max-width: 460px;" autocomplete="new-password" />
						<span class="description" style="display: block; font-size: 12px; margin-top: 4px;">
							<?php if ( $secret_hint ) : ?>
								<?php
								/* translators: %s: last 4 characters of the saved secret */
								printf( esc_html__( 'Saved secret ends in %s. Leave blank to keep it, or paste a new value to replace.', 'aqm-ghl' ), '<code>' . esc_html( $tail ) . '</code>' );
								?>
							<?php else : ?>
								<?php esc_html_e( 'Same value for every client install. Ask Justin / your AQM contact if you don\'t have it.', 'aqm-ghl' ); ?>
							<?php endif; ?>
						</span>
					</p>
					<p style="margin: 0;">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save Secret', 'aqm-ghl' ); ?></button>
					</p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0; border-top: 1px solid #f0f0f1; padding-top: 14px;">
					<input type="hidden" name="action" value="aqm_oauth_start" />
					<?php wp_nonce_field( 'aqm_oauth_start' ); ?>
					<button type="submit" class="button button-primary button-hero" <?php disabled( empty( $settings['oauth_client_secret'] ) ); ?>>
						<?php esc_html_e( 'Connect to GoHighLevel →', 'aqm-ghl' ); ?>
					</button>
					<?php if ( empty( $settings['oauth_client_secret'] ) ) : ?>
						<span class="description" style="display: block; margin-top: 6px;">
							<?php esc_html_e( 'Save the AQM Client Secret above first.', 'aqm-ghl' ); ?>
						</span>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( 'pit' === $active_mode ) : ?>
				<div style="margin: 1em 0; padding: 10px 14px; background: #fffbeb; border-left: 3px solid #d39e00; font-size: 13px;">
					<strong><?php esc_html_e( 'Currently using legacy Private Integration Token mode.', 'aqm-ghl' ); ?></strong>
					<?php esc_html_e( 'It still works — but switching to OAuth above is recommended.', 'aqm-ghl' ); ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the "GHL Workflows" settings section.
	 *
	 * For each selected WP form, the user picks one or more GHL workflows
	 * from a multi-select checkbox list populated via GET /workflows/.
	 * On submission, the plugin adds the newly-created contact to each
	 * selected workflow via POST /contacts/{id}/workflow/{wfId}. No URL
	 * paste required — uses the same PIT the plugin already has configured.
	 *
	 * Requires the PIT to have the `workflows.readonly` scope (in addition
	 * to the existing `contacts.write` etc.) so the dropdown can be populated.
	 *
	 * @param array $settings Current plugin settings.
	 * @param array $forms    Formidable form objects.
	 */
	private function render_workflows_section( $settings, $forms ) {
		$bindings   = isset( $settings['workflow_form_binding'] ) && is_array( $settings['workflow_form_binding'] ) ? $settings['workflow_form_binding'] : array();
		$selected   = isset( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? array_map( 'absint', $settings['form_ids'] ) : array();
		$opt_key    = AQM_GHL_OPTION_KEY;
		$location   = isset( $settings['location_id'] ) ? (string) $settings['location_id'] : '';
		$workflows  = $location ? aqm_ghl_get_cached_workflows( $location ) : array();
		$form_index = array();
		foreach ( $forms as $form ) {
			$form_index[ (int) $form->id ] = $form;
		}
		?>
		<h2><?php esc_html_e( 'GHL Workflows (Per-Form)', 'aqm-ghl' ); ?></h2>
		<p class="description" style="margin-bottom: 1em;">
			<?php esc_html_e( 'Optional. When enabled per form, the plugin will add the newly-created GHL contact to one or more workflows you select below — in addition to creating the contact. No URL paste needed; uses the same Private Integration Token you\'ve already configured.', 'aqm-ghl' ); ?>
		</p>
		<p class="description" style="margin-bottom: 1em; padding: 8px 12px; background:#e7f5ff; border-left:3px solid #2271b1;">
			<strong><?php esc_html_e( 'One-time GHL setup:', 'aqm-ghl' ); ?></strong>
			<?php esc_html_e( 'Ensure your Private Integration Token has the "View Workflows" (workflows.readonly) scope in addition to the existing scopes. Then click "Refresh Workflows from GHL" below to populate the list.', 'aqm-ghl' ); ?>
		</p>
		<p>
			<button type="button" class="button button-secondary" id="aqm-ghl-fetch-workflows"><?php esc_html_e( 'Refresh Workflows from GHL', 'aqm-ghl' ); ?></button>
			<span id="aqm-ghl-fetch-workflows-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
		</p>

		<?php if ( empty( $workflows ) ) : ?>
			<p><em><?php esc_html_e( 'No workflows loaded yet. Click "Refresh Workflows from GHL" above. (If you get an error, your token likely needs the "View Workflows" scope.)', 'aqm-ghl' ); ?></em></p>
		<?php endif; ?>

		<?php if ( empty( $selected ) ) : ?>
			<p><em><?php esc_html_e( 'Select one or more Formidable forms above to configure which GHL workflows to attach.', 'aqm-ghl' ); ?></em></p>
			<?php return; ?>
		<?php endif; ?>

		<?php foreach ( $selected as $wp_form_id ) :
			if ( ! isset( $form_index[ $wp_form_id ] ) ) { continue; }
			$wp_form    = $form_index[ $wp_form_id ];
			$binding    = isset( $bindings[ $wp_form_id ] ) ? $bindings[ $wp_form_id ] : array();
			$enabled    = ! empty( $binding['enabled'] );
			$picked_ids = isset( $binding['workflow_ids'] ) && is_array( $binding['workflow_ids'] ) ? $binding['workflow_ids'] : array();
			$base_name  = $opt_key . '[workflow_form_binding][' . (int) $wp_form_id . ']';
		?>
			<div class="aqm-ghl-workflow-binding" style="margin: 12px 0; border: 1px solid #c3c4c7; background:#fff; padding: 12px 16px;">
				<h3 style="margin-top:0;">
					<?php
					printf(
						/* translators: 1: form name, 2: form ID */
						esc_html__( '%1$s (WP form ID: %2$d)', 'aqm-ghl' ),
						esc_html( (string) $wp_form->name ),
						(int) $wp_form_id
					);
					?>
				</h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Attach to workflows', 'aqm-ghl' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $base_name ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Add the new contact to the selected workflows on submission.', 'aqm-ghl' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Workflows', 'aqm-ghl' ); ?></th>
						<td>
							<?php if ( ! empty( $workflows ) ) : ?>
								<div class="aqm-ghl-workflow-checkboxes" style="max-height: 240px; overflow-y: auto; border: 1px solid #dcdcde; padding: 8px 12px; background: #fdfdfd;">
									<?php foreach ( $workflows as $wf ) :
										$wf_id     = isset( $wf['id'] ) ? (string) $wf['id'] : '';
										$wf_name   = isset( $wf['name'] ) ? (string) $wf['name'] : $wf_id;
										$wf_status = isset( $wf['status'] ) ? (string) $wf['status'] : '';
										if ( '' === $wf_id ) { continue; }
										$is_checked = in_array( $wf_id, $picked_ids, true );
									?>
										<label style="display:block; margin: 3px 0;">
											<input type="checkbox" name="<?php echo esc_attr( $base_name ); ?>[workflow_ids][]" value="<?php echo esc_attr( $wf_id ); ?>" <?php checked( $is_checked ); ?> />
											<?php echo esc_html( $wf_name ); ?>
											<?php if ( $wf_status ) : ?>
												<span class="description" style="color:#646970;">(<?php echo esc_html( $wf_status ); ?>)</span>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e( 'Tick one or more workflows. On submission, the new GHL contact will be added to each one.', 'aqm-ghl' ); ?></p>
							<?php else : ?>
								<?php /* Render hidden inputs so existing picks survive saves before a successful refresh. */ ?>
								<?php foreach ( $picked_ids as $kept_id ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $base_name ); ?>[workflow_ids][]" value="<?php echo esc_attr( $kept_id ); ?>" />
								<?php endforeach; ?>
								<p><em><?php esc_html_e( 'Workflow list is empty — click "Refresh Workflows from GHL" above to load it.', 'aqm-ghl' ); ?></em></p>
								<?php if ( ! empty( $picked_ids ) ) : ?>
									<p class="description"><?php
										/* translators: %d: number of workflow IDs still saved */
										printf( esc_html__( '(%d workflow ID(s) previously saved are preserved.)', 'aqm-ghl' ), count( $picked_ids ) );
									?></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div>
		<?php endforeach; ?>
		<script>
		(function(){
			// The settings page renders this section mid-body, but
			// aqmGhlSettings is injected by wp_localize_script attached to a
			// footer-enqueued script handle — so it doesn't exist yet during
			// the initial HTML parse. Defer to DOMContentLoaded so we run
			// after the footer scripts execute.
			function wire() {
				var btn = document.getElementById('aqm-ghl-fetch-workflows');
				var out = document.getElementById('aqm-ghl-fetch-workflows-result');
				if ( ! btn || ! out ) {
					console.warn('[AQM GHL] Refresh button or output element missing in DOM.');
					return;
				}
				if ( typeof aqmGhlSettings === 'undefined' ) {
					console.error('[AQM GHL] aqmGhlSettings is not defined when wiring the Refresh button. Admin script may have failed to load.');
					out.style.display = 'inline';
					out.className = 'notice notice-error inline';
					out.textContent = <?php echo wp_json_encode( __( 'Plugin admin script failed to load. Check the browser console.', 'aqm-ghl' ) ); ?>;
					return;
				}
				btn.addEventListener('click', function(){
					out.style.display = 'inline';
					out.className = 'notice inline';
					out.textContent = <?php echo wp_json_encode( __( 'Fetching workflows…', 'aqm-ghl' ) ); ?>;
					var body = new FormData();
					body.append( 'action', 'aqm_ghl_fetch_workflows' );
					body.append( 'nonce', aqmGhlSettings.nonce );
					fetch( aqmGhlSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function(r){ return r.json(); } )
						.then( function(json){
							if ( json && json.success ) {
								out.className = 'notice notice-success inline';
								out.textContent = ( json.data && json.data.message ) ? json.data.message : <?php echo wp_json_encode( __( 'Done. Reloading…', 'aqm-ghl' ) ); ?>;
								setTimeout(function(){ window.location.reload(); }, 600);
							} else {
								out.className = 'notice notice-error inline';
								out.textContent = ( json && json.data && json.data.message ) ? json.data.message : <?php echo wp_json_encode( __( 'Failed to fetch workflows.', 'aqm-ghl' ) ); ?>;
								console.error('[AQM GHL] fetch workflows failed:', json);
							}
						} )
						.catch(function(e){
							out.className = 'notice notice-error inline';
							out.textContent = String(e);
							console.error('[AQM GHL] fetch error:', e);
						});
				});
			}
			if ( document.readyState === 'loading' ) {
				document.addEventListener('DOMContentLoaded', wire);
			} else {
				// Already loaded (rare on settings pages, but safe).
				wire();
			}
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: Refresh workflows from GHL (writes to the 1h transient cache).
	 */
	public function ajax_fetch_workflows() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$settings    = aqm_ghl_get_settings();
		$location_id = isset( $settings['location_id'] ) ? (string) $settings['location_id'] : '';
		$token       = isset( $settings['private_token'] ) ? (string) $settings['private_token'] : '';

		if ( '' === $location_id || '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Save your GHL Location ID and Private Integration Token first.', 'aqm-ghl' ) ), 400 );
		}

		$result = aqm_ghl_fetch_workflows( $location_id, $token, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: count */
					_n( 'Loaded %d workflow from GHL. Save settings to keep your picks.', 'Loaded %d workflows from GHL. Save settings to keep your picks.', count( $result ), 'aqm-ghl' ),
					count( $result )
				),
				'workflows' => $result,
				'count'     => count( $result ),
			)
		);
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$raw_existing = get_option( AQM_GHL_OPTION_KEY, array() );
		$raw_existing = is_array( $raw_existing ) ? $raw_existing : array();
		$existing     = aqm_ghl_get_settings();
		$sanitized    = array();

		// Single location configuration (simplified from multi-location)
		$sanitized['location_id'] = isset( $input['location_id'] ) ? sanitize_text_field( $input['location_id'] ) : '';

		// Tokens are opaque secrets; strip tags + trim but preserve all other characters.
		$token = isset( $input['private_token'] ) ? trim( wp_strip_all_tags( (string) wp_unslash( $input['private_token'] ) ) ) : '';
		if ( '' === $token ) {
			$sanitized['private_token'] = isset( $raw_existing['private_token'] ) && is_string( $raw_existing['private_token'] ) ? $raw_existing['private_token'] : '';
		} else {
			$sanitized['private_token'] = $token;
		}

		$github_token = isset( $input['github_token'] ) ? trim( wp_strip_all_tags( (string) wp_unslash( $input['github_token'] ) ) ) : '';
		if ( '' === $github_token ) {
			$sanitized['github_token'] = isset( $raw_existing['github_token'] ) && is_string( $raw_existing['github_token'] ) ? $raw_existing['github_token'] : '';
		} else {
			$sanitized['github_token'] = $github_token;
		}

		// Handle form_ids - can be array or empty
		$form_ids = array();
		if ( isset( $input['form_ids'] ) ) {
			if ( is_array( $input['form_ids'] ) ) {
				foreach ( $input['form_ids'] as $fid ) {
					$fid = absint( $fid );
					if ( $fid ) {
						$form_ids[] = $fid;
					}
				}
			} elseif ( ! empty( $input['form_ids'] ) ) {
				$fid = absint( $input['form_ids'] );
				if ( $fid ) {
					$form_ids[] = $fid;
				}
			}
		}
		$sanitized['form_ids'] = $form_ids;

		// Preserve existing mappings and custom fields for all forms, merge with new data
		$existing_mapping = isset( $existing['mapping'] ) && is_array( $existing['mapping'] ) ? $existing['mapping'] : array();
		$existing_custom_fields = isset( $existing['custom_fields'] ) && is_array( $existing['custom_fields'] ) ? $existing['custom_fields'] : array();
		
		// Process new mapping data from form submission
		$mapping = isset( $input['mapping'] ) && is_array( $input['mapping'] ) ? $input['mapping'] : array();
		$sanitized['mapping'] = $existing_mapping; // Start with existing - preserve all
		
		if ( ! empty( $mapping ) ) {
			foreach ( $mapping as $fid => $map_values ) {
				$fid = absint( $fid );
				if ( ! $fid ) {
					continue;
				}
				// Only update if form is selected
				if ( ! in_array( $fid, $form_ids, true ) ) {
					if ( isset( $existing_mapping[ $fid ] ) ) {
						$sanitized['mapping'][ $fid ] = $existing_mapping[ $fid ];
					}
					continue;
				}
				// Update this form's mapping
				$sanitized['mapping'][ $fid ] = array(
					'email'      => isset( $map_values['email'] ) ? absint( $map_values['email'] ) : '',
					'phone'      => isset( $map_values['phone'] ) ? absint( $map_values['phone'] ) : '',
					'first_name' => isset( $map_values['first_name'] ) ? absint( $map_values['first_name'] ) : '',
					'last_name'  => isset( $map_values['last_name'] ) ? absint( $map_values['last_name'] ) : '',
				);
			}
		}
		
		// Ensure all selected forms have mapping entries (even if empty)
		foreach ( $form_ids as $fid ) {
			if ( ! isset( $sanitized['mapping'][ $fid ] ) ) {
				$sanitized['mapping'][ $fid ] = isset( $existing_mapping[ $fid ] ) 
					? $existing_mapping[ $fid ] 
					: array(
						'email'      => '',
						'phone'      => '',
						'first_name' => '',
						'last_name'  => '',
					);
			}
		}

		// Process new custom fields data from form submission
		$custom_fields = isset( $input['custom_fields'] ) ? $input['custom_fields'] : array();
		$sanitized_custom_fields = $existing_custom_fields; // Start with existing
		if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
			foreach ( $custom_fields as $fid => $fields_list ) {
				$fid = absint( $fid );
				if ( ! $fid ) {
					continue;
				}
				// Sanitize the fields list for this form
				if ( is_array( $fields_list ) ) {
					$cleaned = array();
					foreach ( $fields_list as $field ) {
						if ( empty( $field['ghl_field_id'] ) && empty( $field['form_field_id'] ) ) {
							continue;
						}
						$ghl_field_id  = isset( $field['ghl_field_id'] ) ? sanitize_text_field( $field['ghl_field_id'] ) : '';
						$form_field_id = isset( $field['form_field_id'] ) ? absint( $field['form_field_id'] ) : 0;
						if ( $ghl_field_id && $form_field_id ) {
							$cleaned[] = array(
								'ghl_field_id'  => $ghl_field_id,
								'form_field_id' => $form_field_id,
							);
						}
					}
					if ( ! empty( $cleaned ) ) {
						$sanitized_custom_fields[ $fid ] = $cleaned;
					} else {
						unset( $sanitized_custom_fields[ $fid ] );
					}
				}
			}
		}
		$sanitized['custom_fields'] = $sanitized_custom_fields;

		$sanitized['tags'] = isset( $input['tags'] ) ? sanitize_text_field( $input['tags'] ) : '';

		// Per-WP-form binding to one or more GHL workflows, used by
		// AQM_GHL_Form_Submitter to add the new contact to each selected
		// workflow via POST /contacts/{id}/workflow/{wfId}. Preserved across
		// saves for forms not currently selected so removing/re-adding a form
		// doesn't lose its binding.
		$existing_bindings = isset( $existing['workflow_form_binding'] ) && is_array( $existing['workflow_form_binding'] ) ? $existing['workflow_form_binding'] : array();
		$new_bindings      = isset( $input['workflow_form_binding'] ) ? aqm_ghl_sanitize_workflow_form_binding( $input['workflow_form_binding'] ) : array();
		$sanitized['workflow_form_binding'] = array_replace( $existing_bindings, $new_bindings );

		$sanitized['enable_logging'] = ! empty( $input['enable_logging'] ) ? 1 : 0;

		// OAuth client_secret (per-install paste, same value across all client sites).
		// Treat as a token: strip tags + trim, but preserve all other characters.
		// Blank submission keeps existing value (so saving other settings doesn't wipe it).
		if ( array_key_exists( 'oauth_client_secret', $input ) ) {
			$incoming = is_string( $input['oauth_client_secret'] ) ? trim( wp_strip_all_tags( (string) wp_unslash( $input['oauth_client_secret'] ) ) ) : '';
			if ( '' !== $incoming ) {
				$sanitized['oauth_client_secret'] = $incoming;
			} elseif ( isset( $raw_existing['oauth_client_secret'] ) && is_string( $raw_existing['oauth_client_secret'] ) ) {
				$sanitized['oauth_client_secret'] = $raw_existing['oauth_client_secret'];
			}
		}

		// Auth mode is auto-detected by aqm_ghl_get_auth_mode() based on whether
		// OAuth tokens exist. We don't expose a manual radio for now — the active
		// mode badge in the UI just reflects detection. If we ever need a manual
		// override, the input would land here.
		if ( isset( $input['auth_mode'] ) && in_array( (string) $input['auth_mode'], array( 'oauth', 'pit', 'auto' ), true ) ) {
			$sanitized['auth_mode'] = (string) $input['auth_mode'];
		}

		// Merge with existing so we never wipe keys (e.g. locations); ensures token update persists
		$to_save = array_merge( $raw_existing, $sanitized );

		// When token is updated, sync into first location so all code paths use the new token
		if ( ! empty( $to_save['private_token'] ) && ! empty( $to_save['locations'] ) && is_array( $to_save['locations'] ) ) {
			if ( isset( $to_save['locations'][0] ) && is_array( $to_save['locations'][0] ) ) {
				$to_save['locations'][0]['private_token'] = $to_save['private_token'];
			}
		}

		add_settings_error(
			'aqm-ghl-connector',
			'aqm-ghl-connector-saved',
			__( 'Settings saved.', 'aqm-ghl' ),
			'updated'
		);

		return $to_save;
	}


	/**
	 * AJAX handler to fetch fields for a form.
	 */
	public function ajax_get_form_fields() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing form ID.', 'aqm-ghl' ) ), 400 );
		}

		$fields = aqm_ghl_get_formidable_form_fields( $form_id );

		wp_send_json_success(
			array(
				'fields' => $fields,
			)
		);
	}

	/**
	 * AJAX handler to test the connection by sending a mock contact.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Add Location ID and Private Integration Token, then save settings before testing.', 'aqm-ghl' ),
				),
				400
			);
		}

		// First, ensure custom fields are provisioned
		$provisioner = new AQM_GHL_Custom_Field_Provisioner();
		
		// Clear cache and force provision fields
		$provisioner->clear_cache( $settings['location_id'] );
		$field_mapping = $provisioner->get_field_mapping( $settings['location_id'], $settings['private_token'], true );
		
		// Enhanced error detection - try to fetch fields directly and attempt creation
		$provisioning_errors = array();
		$provisioning_details = array();
		
		if ( empty( $field_mapping ) ) {
			// Try to fetch fields directly to diagnose the issue
			$test_response = wp_remote_get(
				sprintf( 'https://services.leadconnectorhq.com/locations/%s/customFields', $settings['location_id'] ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $settings['private_token'],
						'Content-Type'  => 'application/json',
						'Version'       => '2021-07-28',
					),
					'timeout' => 15,
				)
			);
			
			if ( is_wp_error( $test_response ) ) {
				$provisioning_errors[] = 'Network error: ' . $test_response->get_error_message();
			} else {
				$test_code = wp_remote_retrieve_response_code( $test_response );
				$test_body = wp_remote_retrieve_body( $test_response );
				
				if ( $test_code < 200 || $test_code >= 300 ) {
					$provisioning_errors[] = sprintf( 'API returned status %d: %s', $test_code, $test_body );
				} else {
					$test_data = json_decode( $test_body, true );
					if ( is_array( $test_data ) ) {
						$field_count = isset( $test_data['customFields'] ) && is_array( $test_data['customFields'] ) ? count( $test_data['customFields'] ) : 0;
						$provisioning_errors[] = sprintf( 'API call succeeded but found %d fields. Response structure: %s', $field_count, wp_json_encode( array_keys( $test_data ) ) );
						
						// Try to create a test field to see if creation works
						$create_test = wp_remote_post(
							sprintf( 'https://services.leadconnectorhq.com/locations/%s/customFields', $settings['location_id'] ),
							array(
								'headers' => array(
									'Authorization' => 'Bearer ' . $settings['private_token'],
									'Content-Type'  => 'application/json',
									'Version'       => '2021-07-28',
								),
								'timeout' => 15,
								'body'    => wp_json_encode( array(
									'name'     => 'AQM - Test Field',
									'dataType' => 'TEXT',
								) ),
							)
						);
						
						if ( ! is_wp_error( $create_test ) ) {
							$create_code = wp_remote_retrieve_response_code( $create_test );
							$create_body = wp_remote_retrieve_body( $create_test );
							$create_data = json_decode( $create_body, true );
							
							$provisioning_details['create_test'] = array(
								'status_code' => $create_code,
								'response_keys' => is_array( $create_data ) ? array_keys( $create_data ) : 'not_array',
								'response_sample' => substr( $create_body, 0, 300 ),
							);
							
							if ( $create_code >= 200 && $create_code < 300 ) {
								if ( is_array( $create_data ) && ! empty( $create_data['id'] ) ) {
									$provisioning_errors[] = sprintf( 'Field creation test succeeded! Field ID: %s. Response structure: %s', $create_data['id'], wp_json_encode( array_keys( $create_data ) ) );
								} else {
									$provisioning_errors[] = sprintf( 'Field creation test returned success but no field ID found. Response: %s', substr( $create_body, 0, 300 ) );
								}
							} else {
								$provisioning_errors[] = sprintf( 'Field creation test failed with status %d: %s', $create_code, substr( $create_body, 0, 200 ) );
							}
						} else {
							$provisioning_errors[] = 'Field creation test network error: ' . $create_test->get_error_message();
						}
					} else {
						$provisioning_errors[] = sprintf( 'API call succeeded but invalid JSON response: %s', substr( $test_body, 0, 200 ) );
					}
				}
			}
		}
		
		// Log field mapping for debugging — omit raw API response bodies (may echo back token-bound data).
		aqm_ghl_log(
			'Test contact: Field mapping retrieved after provisioning.',
			array(
				'mapping_count'             => count( $field_mapping ),
				'provisioning_error_count'  => count( $provisioning_errors ),
			)
		);
		
		// Build initial payload
		$unique_email = sprintf( 'john.doe+ghl-test-%s@example.com', substr( wp_generate_uuid4(), 0, 8 ) );
		$payload = array(
			'locationId'   => $settings['location_id'],
			'email'        => $unique_email,
			'phone'        => '+15555550123',
			'firstName'    => 'John',
			'lastName'     => 'Doe',
			'tags'         => array( 'Test', 'AQM Connector' ),
			'customFields' => array(),
		);

		// Add ALL GHL custom fields with test values so the test contact shows them.
		$ghl_fields = aqm_ghl_get_cached_ghl_custom_fields();
		$core_skip  = array( 'email', 'phone', 'phonenumber', 'firstname', 'lastname', 'name', 'fullname' );
		foreach ( $ghl_fields as $cf ) {
			$norm = strtolower( str_replace( array( '_', '-', ' ' ), '', $cf['name'] ) );
			if ( in_array( $norm, $core_skip, true ) ) {
				continue;
			}
			$payload['customFields'][] = array(
				'id'    => $cf['id'],
				'value' => 'test_' . ( ! empty( $cf['fieldKey'] ) ? str_replace( 'contact.', '', $cf['fieldKey'] ) : $cf['id'] ),
			);
		}

		// Also inject test UTM parameters via provisioned field IDs (may overlap, GHL dedupes by id).
		$payload = $this->inject_test_utm_data( $payload, $settings['location_id'], $settings['private_token'] );

		$payload = aqm_ghl_clean_payload( $payload );

		$response = aqm_ghl_send_contact_payload( $payload, $settings['private_token'] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Request error: %s', 'aqm-ghl' ),
						$response->get_error_message()
					),
				),
				500
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$body = is_string( $body ) ? $body : wp_json_encode( $body );

		if ( $code < 200 || $code >= 300 ) {
			aqm_ghl_store_last_test_result(
				array(
					'success'  => false,
					'status'   => $code,
					'payload'  => $payload,
					'response' => $body,
					'message'  => sprintf(
						/* translators: 1: status code, 2: response body */
						__( 'Non-2xx response (%1$s): %2$s', 'aqm-ghl' ),
						$code,
						$body
					),
				)
			);

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: status code, 2: response body */
						__( 'Non-2xx response (%1$s): %2$s', 'aqm-ghl' ),
						$code,
						$body
					),
					'status'  => $code,
					'payload' => $payload,
					'response_body' => $body,
				),
				$code
			);
		}

		aqm_ghl_store_last_test_result(
			array(
				'success'  => true,
				'status'   => $code,
				'payload'  => $payload,
				'response' => $body,
				'message'  => __( 'Test contact sent successfully. Check GoHighLevel contacts.', 'aqm-ghl' ),
			)
		);

		// Include field mapping info in response for debugging
		$message = __( 'Test contact sent successfully. Check GoHighLevel contacts.', 'aqm-ghl' );
		
		// Add provisioning status to message
		if ( ! empty( $field_mapping ) && count( $field_mapping ) >= 6 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of fields */
				__( 'All %d UTM/GCLID custom fields were provisioned and included in the test contact.', 'aqm-ghl' ),
				count( $field_mapping )
			);
		} elseif ( ! empty( $field_mapping ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of fields */
				__( 'Warning: Only %d of 6 expected custom fields were provisioned. Some UTM parameters may be missing.', 'aqm-ghl' ),
				count( $field_mapping )
			);
		} else {
			$message .= ' ' . __( 'Warning: Custom fields were not provisioned. UTM parameters were not included in the test contact.', 'aqm-ghl' );
			if ( ! empty( $provisioning_errors ) ) {
				$error_text = implode( '; ', $provisioning_errors );
				$message .= ' ' . __( 'Errors:', 'aqm-ghl' ) . ' ' . $error_text;
				
				// Check for 401/403 scope error and provide helpful guidance
				if ( strpos( $error_text, '401' ) !== false || strpos( $error_text, '403' ) !== false ) {
					$message .= ' ' . __( 'Your token may lack Custom Fields scope. In GoHighLevel go to Settings → API Keys → edit your Private Integration token and enable the Custom Fields scope, then paste the new token in the plugin. Form submissions still create contacts; only UTM/GCLID custom fields are skipped until the token has this scope.', 'aqm-ghl' );
				}
			} else {
				$message .= ' ' . __( 'Please use the "Refresh/Provision Custom Fields" button first, or check debug logs for details.', 'aqm-ghl' );
			}
		}
		
		$response_data = array(
			'message' => $message,
			'status'  => $code,
			'payload' => $payload,
			'response_body' => $body,
		);
		
		// Add field mapping info if available
		if ( isset( $field_mapping ) ) {
			$response_data['field_mapping'] = $field_mapping;
			$response_data['field_mapping_count'] = count( $field_mapping );
		}
		
		// Add provisioning errors and details for debugging
		if ( ! empty( $provisioning_errors ) ) {
			$response_data['provisioning_errors'] = $provisioning_errors;
		}
		if ( ! empty( $provisioning_details ) ) {
			$response_data['provisioning_details'] = $provisioning_details;
		}
		
		wp_send_json_success( $response_data );
	}

	/**
	 * Inject test UTM parameters and GCLID into the test payload using provisioned field IDs.
	 *
	 * @param array  $payload     Existing payload array.
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @return array Modified payload with test UTM/GCLID data.
	 */
	private function inject_test_utm_data( $payload, $location_id, $token ) {
		$provisioner = new AQM_GHL_Custom_Field_Provisioner();

		// Force refresh to ensure fields are provisioned (provisions if needed)
		$field_mapping = $provisioner->get_field_mapping( $location_id, $token, true );

		if ( empty( $field_mapping ) ) {
			// Log the issue for debugging
			aqm_ghl_log(
				'Test UTM injection: No field mapping available. Fields may need to be provisioned manually.',
				array(
					'location_id' => $location_id,
					'field_mapping' => $field_mapping,
				)
			);
			// Continue without UTM data but log it
			return $payload;
		}

		// Test UTM parameters
		$test_utm_params = array(
			'gclid'        => 'test_gclid_123456789',
			'utm_source'   => 'test_source',
			'utm_medium'   => 'test_medium',
			'utm_campaign' => 'test_campaign',
			'utm_term'     => 'test_term',
			'utm_content'  => 'test_content',
		);

		// Initialize customFields array if needed
		if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
			$payload['customFields'] = array();
		}

		// Add each test UTM parameter if we have a field ID
		foreach ( $test_utm_params as $param_key => $value ) {
			// Get the provisioned field ID for this parameter
			if ( ! isset( $field_mapping[ $param_key ] ) || empty( $field_mapping[ $param_key ] ) ) {
				aqm_ghl_log(
					'Test UTM injection: Missing field mapping for parameter.',
					array(
						'param_key' => $param_key,
						'field_mapping' => $field_mapping,
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
	 * AJAX handler to clear update cache.
	 */
	public function ajax_clear_update_cache() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'aqm-ghl' ) ), 403 );
		}

		// Clear GitHub update cache
		if ( class_exists( 'AQM_GHL_Updater' ) ) {
			AQM_GHL_Updater::clear_cache();
		}

		// Also clear WordPress update transients
		delete_site_transient( 'update_plugins' );
		wp_clean_plugins_cache( true );

		wp_send_json_success(
			array(
				'message' => __( 'Update cache cleared successfully. Visit the Plugins page to check for updates.', 'aqm-ghl' ),
			)
		);
	}

	/**
	 * AJAX handler to provision custom fields for all locations.
	 */
	public function ajax_provision_fields() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Location ID and Private Integration Token must be configured first.', 'aqm-ghl' ),
				),
				400
			);
		}

		$provisioner = new AQM_GHL_Custom_Field_Provisioner();

		// Clear cache and force refresh
		$provisioner->clear_cache( $settings['location_id'] );
		$mapping = $provisioner->get_field_mapping( $settings['location_id'], $settings['private_token'], true );

		if ( ! empty( $mapping ) ) {
			// Keep Formidable hidden-field defaults and mappings in sync after provisioning.
			$sync = aqm_ghl_sync_ghl_fields_to_forms();
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of fields */
						__( 'Successfully provisioned %d custom fields.', 'aqm-ghl' ),
						count( $mapping )
					),
					'field_count' => count( $mapping ),
					'sync'        => $sync,
				)
			);
		} else {
			// Try to get more specific error information
			$test_response = wp_remote_get(
				sprintf( 'https://services.leadconnectorhq.com/locations/%s/customFields', $settings['location_id'] ),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $settings['private_token'],
						'Content-Type'  => 'application/json',
						'Version'       => '2021-07-28',
					),
					'timeout' => 15,
				)
			);
			
			$error_message = __( 'Failed to provision fields. Check logs for details.', 'aqm-ghl' );
			
			if ( ! is_wp_error( $test_response ) ) {
				$test_code = wp_remote_retrieve_response_code( $test_response );
				$test_body = wp_remote_retrieve_body( $test_response );
				
				if ( $test_code === 401 || $test_code === 403 ) {
					$error_message = __( 'Failed to provision fields: The saved token was rejected by GoHighLevel (401/403). If your integration already has "View Custom Fields" and "Edit Custom Fields" scopes, copy the token again from that same integration in GoHighLevel, paste it in the Private Integration Token field above, click Save Settings, then try Refresh/Provision again. Otherwise add locations/customFields.readonly and locations/customFields.write to your token scopes and paste the new token here.', 'aqm-ghl' );
				} elseif ( $test_code >= 400 ) {
					$error_message = sprintf(
						/* translators: 1: status code, 2: error body */
						__( 'Failed to provision fields: API returned status %1$d: %2$s', 'aqm-ghl' ),
						$test_code,
						substr( $test_body, 0, 200 )
					);
				}
			}
			
			wp_send_json_error(
				array(
					'message' => $error_message,
				),
				500
			);
		}
	}

	/**
	 * AJAX handler to fetch all custom fields from the GoHighLevel API.
	 */
	public function ajax_fetch_ghl_fields() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Save your GHL Location ID and Private Integration Token before fetching custom fields.', 'aqm-ghl' ),
				),
				400
			);
		}

		$fields = aqm_ghl_fetch_ghl_custom_fields( $settings['location_id'], $settings['private_token'], true );

		if ( is_wp_error( $fields ) ) {
			wp_send_json_error(
				array(
					'message' => $fields->get_error_message(),
				),
				500
			);
		}

		// Sync: create hidden fields in Formidable forms and auto-map.
		$sync = aqm_ghl_sync_ghl_fields_to_forms();

		wp_send_json_success(
			array(
				'fields'  => $fields,
				'count'   => count( $fields ),
				'sync'    => $sync,
			)
		);
	}

	/**
	 * Handle export: output JSON file with all settings (including credentials).
	 */
	public function handle_export_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export settings.', 'aqm-ghl' ), 403 );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aqm_ghl_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'aqm-ghl' ), 403 );
		}

		$data  = aqm_ghl_export_settings_data();
		$json  = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$filename = 'aqm-ghl-connector-settings-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Expires: 0' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON export, not HTML
		echo $json;
		exit;
	}

	/**
	 * Handle import: validate JSON and save all settings.
	 */
	public function handle_import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import settings.', 'aqm-ghl' ), 403 );
		}
		$redirect_url = admin_url( 'admin.php?page=aqm-ghl-connector' );

		if ( ! isset( $_POST['aqm_ghl_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aqm_ghl_import_nonce'] ) ), 'aqm_ghl_import' ) ) {
			wp_safe_redirect( add_query_arg( 'aqm_ghl_import', 'error_nonce', $redirect_url ) );
			exit;
		}

		$raw = '';
		if ( ! empty( $_FILES['aqm_ghl_import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['aqm_ghl_import_file']['tmp_name'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw = file_get_contents( $_FILES['aqm_ghl_import_file']['tmp_name'] );
		} elseif ( ! empty( $_POST['aqm_ghl_import_json'] ) && is_string( $_POST['aqm_ghl_import_json'] ) ) {
			$raw = wp_unslash( $_POST['aqm_ghl_import_json'] );
		}

		if ( '' === $raw ) {
			wp_safe_redirect( add_query_arg( 'aqm_ghl_import', 'error_empty', $redirect_url ) );
			exit;
		}

		$data = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			wp_safe_redirect( add_query_arg( 'aqm_ghl_import', 'error_json', $redirect_url ) );
			exit;
		}

		$result = aqm_ghl_import_settings_data( $data );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'aqm_ghl_import' => 'error', 'aqm_ghl_message' => rawurlencode( $result->get_error_message() ) ), $redirect_url ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'aqm_ghl_import', 'success', $redirect_url ) );
		exit;
	}
}


