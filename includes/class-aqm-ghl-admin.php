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
		add_action( 'wp_ajax_aqm_ghl_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_aqm_ghl_clear_update_cache', array( $this, 'ajax_clear_update_cache' ) );
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

				<?php $this->render_opportunities_section( $settings, $forms ); ?>

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
							<?php if ( ! empty( $forms ) ) : ?>
								<?php
								$total_forms    = count( $forms );
								$selected_ids   = isset( $settings['form_ids'] ) ? array_map( 'absint', (array) $settings['form_ids'] ) : array();
								$selected_count = count( $selected_ids );
								$all_checked    = $total_forms > 0 && $selected_count >= $total_forms;
								?>
								<label class="aqm-ghl-form-checkbox-item aqm-ghl-form-checkbox-select-all" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #dcdcde; font-weight: 600;">
									<input
										type="checkbox"
										id="aqm-ghl-select-all-forms"
										<?php checked( $all_checked ); ?>
									/>
									<span class="aqm-ghl-form-checkbox-label">
										<?php esc_html_e( 'Select all forms', 'aqm-ghl' ); ?>
										<span class="description" style="font-size: 11px; color: #646970; font-weight: normal;">
											(<?php
											/* translators: 1: selected count, 2: total count */
											printf( esc_html__( '%1$d of %2$d selected', 'aqm-ghl' ), (int) $selected_count, (int) $total_forms );
											?>)
										</span>
									</span>
								</label>
							<?php endif; ?>
							<div class="aqm-ghl-form-checkboxes">
								<?php if ( ! empty( $forms ) ) : ?>
									<?php foreach ( $forms as $form ) : ?>
										<?php $is_checked = in_array( (int) $form->id, $selected_ids, true ); ?>
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
							<?php if ( ! empty( $forms ) ) : ?>
								<script>
								(function(){
									var master = document.getElementById('aqm-ghl-select-all-forms');
									if ( ! master ) { return; }
									var boxes  = document.querySelectorAll('.aqm-ghl-form-checkbox');
									if ( ! boxes.length ) { return; }
									function syncMaster(){
										var checked = 0;
										boxes.forEach(function(b){ if (b.checked) checked++; });
										master.checked       = ( checked === boxes.length );
										master.indeterminate = ( checked > 0 && checked < boxes.length );
									}
									master.addEventListener('change', function(){
										boxes.forEach(function(b){
											if ( b.checked !== master.checked ) {
												b.checked = master.checked;
											}
										});
										master.indeterminate = false;
									});
									boxes.forEach(function(b){ b.addEventListener('change', syncMaster); });
									syncMaster();
								})();
								</script>
							<?php endif; ?>
						</td>
					</tr>
				</table>

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
						<?php
						// Show which sub-account this test will actually hit, so it's obvious the
						// site is wired to the right GHL location before sending. Derived without a
						// network call — mirrors aqm_ghl_get_active_auth()'s resolution order.
						$test_mode   = function_exists( 'aqm_ghl_get_auth_mode' ) ? aqm_ghl_get_auth_mode() : 'pit';
						$test_loc_id = '';
						$test_loc_nm = '';
						$test_ready  = false;
						if ( 'oauth' === $test_mode ) {
							$test_loc_id = isset( $settings['oauth_location_id'] )   ? (string) $settings['oauth_location_id']   : '';
							$test_loc_nm = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';
							$test_ready  = class_exists( 'AQM_GHL_OAuth' ) && AQM_GHL_OAuth::is_connected() && '' !== $test_loc_id;
						} else {
							$test_loc_id = isset( $settings['location_id'] ) ? (string) $settings['location_id'] : '';
							if ( '' === $test_loc_id && ! empty( $settings['locations'][0]['location_id'] ) ) {
								$test_loc_id = (string) $settings['locations'][0]['location_id'];
							}
							if ( ! empty( $settings['locations'][0]['name'] ) ) {
								$test_loc_nm = (string) $settings['locations'][0]['name'];
							}
							$test_ready = ( '' !== $test_loc_id );
						}
						?>
						<?php if ( $test_ready ) : ?>
							<div style="margin: 0 0 12px; padding: 10px 14px; background: #edfaef; border-left: 4px solid #00a32a; font-size: 13px; color: #1d2327;">
								<?php
								if ( '' !== $test_loc_nm ) {
									printf(
										/* translators: 1: sub-account display name, 2: sub-account ID */
										esc_html__( 'This site is connected to: %1$s %2$s', 'aqm-ghl' ),
										'<strong>' . esc_html( $test_loc_nm ) . '</strong>',
										'<code style="font-size: 11px;">' . esc_html( $test_loc_id ) . '</code>'
									);
								} else {
									printf(
										/* translators: %s: sub-account ID */
										esc_html__( 'This site is connected to sub-account %s', 'aqm-ghl' ),
										'<code style="font-size: 11px;">' . esc_html( $test_loc_id ) . '</code>'
									);
								}
								?>
								<br>
								<span style="color: #50575e;"><?php esc_html_e( 'The test contact below will be sent here — make sure it\'s the right sub-account.', 'aqm-ghl' ); ?></span>
							</div>
						<?php else : ?>
							<div style="margin: 0 0 12px; padding: 10px 14px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 13px; color: #1d2327;">
								<strong><?php esc_html_e( 'Not connected to GoHighLevel.', 'aqm-ghl' ); ?></strong>
								<?php esc_html_e( 'Connect in the Authentication section above before testing — the test would fail.', 'aqm-ghl' ); ?>
							</div>
						<?php endif; ?>
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

			</form>
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

			<?php
			// Only call out "legacy PIT mode" when a PIT token is actually saved
			// (a real legacy site). Otherwise the auth detector just defaults to
			// "pit" with nothing configured, and the "Connect" prompt above is the
			// correct guidance — claiming PIT "still works" would be misleading.
			$pit_token = isset( $settings['private_token'] ) && '' !== (string) $settings['private_token']
				? (string) $settings['private_token']
				: ( ! empty( $settings['locations'][0]['private_token'] ) ? (string) $settings['locations'][0]['private_token'] : '' );
			?>
			<?php if ( 'pit' === $active_mode && '' !== $pit_token ) : ?>
				<div style="margin: 1em 0; padding: 10px 14px; background: #fffbeb; border-left: 3px solid #d39e00; font-size: 13px;">
					<strong><?php esc_html_e( 'Currently using legacy Private Integration Token mode.', 'aqm-ghl' ); ?></strong>
					<?php esc_html_e( 'It still works — but switching to OAuth above is recommended.', 'aqm-ghl' ); ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX: Refresh opportunity pipelines from GHL (writes to the 1h transient cache).
	 * Uses the auth router so it works in both OAuth and PIT modes.
	 */
	public function ajax_fetch_pipelines() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$auth = function_exists( 'aqm_ghl_get_active_auth' ) ? aqm_ghl_get_active_auth() : new \WP_Error( 'no_auth_router', 'Auth router not available.' );
		if ( is_wp_error( $auth ) ) {
			wp_send_json_error( array( 'message' => $auth->get_error_message() ), 400 );
		}

		$result = aqm_ghl_fetch_pipelines( $auth['location_id'], $auth['token'], true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: count */
					_n( 'Loaded %d pipeline from GHL. Save settings to keep your picks.', 'Loaded %d pipelines from GHL. Save settings to keep your picks.', count( $result ), 'aqm-ghl' ),
					count( $result )
				),
				'pipelines' => $result,
				'count'     => count( $result ),
			)
		);
	}

	/**
	 * Render the "Create GHL Opportunities" section. Per WP form: enable
	 * toggle, pipeline dropdown, stage dropdown (depends on pipeline),
	 * opportunity name template (with token support), status, monetary value.
	 *
	 * Pipelines are fetched server-side from cache; user can hit "Refresh
	 * pipelines from GHL" to repopulate via the v2 API.
	 *
	 * @param array $settings Current plugin settings.
	 * @param array $forms    Formidable form objects.
	 */
	private function render_opportunities_section( $settings, $forms ) {
		$enabled = ! empty( $settings['create_opportunity'] );
		$opt_key = AQM_GHL_OPTION_KEY;

		$form_index = array();
		foreach ( $forms as $form ) {
			$form_index[ (int) $form->id ] = $form;
		}
		?>
		<h2 style="margin-top: 2em;"><?php esc_html_e( 'Create GHL opportunities (optional)', 'aqm-ghl' ); ?></h2>
		<p class="description" style="margin-bottom: 1em;">
			<?php esc_html_e( 'When enabled, every form submission pushed to GHL also creates an opportunity in your sub-account\'s first pipeline + first stage (status: Open), linked to the new contact. Your agency manages stages and status from inside GHL.', 'aqm-ghl' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Create opportunity?', 'aqm-ghl' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $opt_key ); ?>[create_opportunity]" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Yes — create an opportunity in GHL on every form submission.', 'aqm-ghl' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
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

		// Auto-detect name/email/phone for any selected form that has no usable
		// mapping yet. The manual mapping UI was removed, so submissions resolve
		// contact fields automatically; existing (non-empty) mappings are kept.
		foreach ( $form_ids as $fid ) {
			if ( ! function_exists( 'aqm_ghl_autodetect_mapping_for_form' ) ) {
				break;
			}
			$detected = aqm_ghl_autodetect_mapping_for_form( $fid );
			if ( empty( $detected ) ) {
				continue;
			}
			// Merge: fill any missing keys (incl. address1/city/state/postal_code)
			// without overwriting values already set, so existing forms pick up the
			// address mapping on their next save.
			$current = isset( $sanitized['mapping'][ $fid ] ) && is_array( $sanitized['mapping'][ $fid ] ) ? $sanitized['mapping'][ $fid ] : array();
			foreach ( $detected as $key => $detected_fid ) {
				if ( empty( $current[ $key ] ) && ! empty( $detected_fid ) ) {
					$current[ $key ] = $detected_fid;
				}
			}
			if ( ! empty( $current ) ) {
				$sanitized['mapping'][ $fid ] = $current;
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

		// Auto-map remaining GHL custom fields to matching form fields in the
		// background (no manual UI). Existing mappings are kept; newly-matched
		// GHL fields are appended so custom fields flow through automatically.
		foreach ( $form_ids as $fid ) {
			if ( ! function_exists( 'aqm_ghl_autodetect_custom_fields_for_form' ) ) {
				break;
			}
			$detected = aqm_ghl_autodetect_custom_fields_for_form( $fid );
			if ( empty( $detected ) ) {
				continue;
			}
			$rows     = isset( $sanitized['custom_fields'][ $fid ] ) && is_array( $sanitized['custom_fields'][ $fid ] ) ? $sanitized['custom_fields'][ $fid ] : array();
			$have_ghl = array();
			foreach ( $rows as $row ) {
				if ( ! empty( $row['ghl_field_id'] ) ) {
					$have_ghl[ $row['ghl_field_id'] ] = true;
				}
			}
			foreach ( $detected as $row ) {
				if ( empty( $have_ghl[ $row['ghl_field_id'] ] ) ) {
					$rows[]                           = $row;
					$have_ghl[ $row['ghl_field_id'] ] = true;
				}
			}
			if ( ! empty( $rows ) ) {
				$sanitized['custom_fields'][ $fid ] = $rows;
			}
		}

		$sanitized['tags'] = isset( $input['tags'] ) ? sanitize_text_field( $input['tags'] ) : '';

		// Global "Create opportunity?" toggle — if on, every form submission
		// also creates an opportunity (auto-picks first pipeline + first stage).
		$sanitized['create_opportunity'] = ! empty( $input['create_opportunity'] ) ? 1 : 0;

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
	 * AJAX handler to test the connection by sending a mock contact.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();

		// Resolve credentials through the auth router so this works in both OAuth
		// and legacy PIT modes. The PIT scalars in $settings are empty on
		// OAuth-connected sites, so reading them directly always failed here.
		$auth = aqm_ghl_get_active_auth();

		if ( is_wp_error( $auth ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Connect to GoHighLevel before testing.', 'aqm-ghl' ) . ' ' . $auth->get_error_message(),
				),
				400
			);
		}

		$settings['location_id']   = $auth['location_id'];
		$settings['private_token'] = $auth['token'];

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

		// A "duplicate contact" 400 still proves the connection works: auth
		// succeeded, the sub-account is valid, and GHL matched a contact the test
		// already created (the test reuses a fixed phone, and this sub-account
		// blocks duplicates). Treat it as a successful connection test, not a
		// failure.
		$is_duplicate = false;
		if ( 400 === (int) $code ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$dup_msg = isset( $decoded['message'] ) ? strtolower( (string) $decoded['message'] ) : '';
				if ( false !== strpos( $dup_msg, 'duplicat' ) || ! empty( $decoded['meta']['contactId'] ) ) {
					$is_duplicate = true;
				}
			}
		}

		if ( ! $is_duplicate && ( $code < 200 || $code >= 300 ) ) {
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

		$base_message = $is_duplicate
			? __( 'Connection works! A matching test contact already exists in this sub-account (duplicate contacts are blocked here), so no new one was created — but the request reached GoHighLevel and authenticated correctly.', 'aqm-ghl' )
			: __( 'Test contact sent successfully. Check GoHighLevel contacts.', 'aqm-ghl' );

		aqm_ghl_store_last_test_result(
			array(
				'success'  => true,
				'status'   => $code,
				'payload'  => $payload,
				'response' => $body,
				'message'  => $base_message,
			)
		);

		// Include field mapping info in response for debugging
		$message = $base_message;
		
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

}


