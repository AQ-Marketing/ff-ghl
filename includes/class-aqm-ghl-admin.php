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

		// Auto-sync GHL fields → Formidable hidden fields on page load.
		aqm_ghl_sync_ghl_fields_to_forms();

		$current_settings = aqm_ghl_get_settings();
		$forms            = aqm_ghl_get_formidable_forms();
		$form_options     = array();
		foreach ( $forms as $form ) {
			$form_options[] = array(
				'id'   => (int) $form->id,
				'name' => $form->name,
			);
		}

		// Normalize mapping keys to integers for consistent JavaScript access
		$mapping_normalized = array();
		if ( ! empty( $current_settings['mapping'] ) && is_array( $current_settings['mapping'] ) ) {
			foreach ( $current_settings['mapping'] as $fid => $map ) {
				$fid_int = absint( $fid );
				$mapping_normalized[ $fid_int ] = $map;
			}
		}

		// Normalize custom fields keys to integers
		$custom_fields_normalized = array();
		if ( ! empty( $current_settings['custom_fields'] ) && is_array( $current_settings['custom_fields'] ) ) {
			foreach ( $current_settings['custom_fields'] as $fid => $fields ) {
				$fid_int = absint( $fid );
				$custom_fields_normalized[ $fid_int ] = $fields;
			}
		}

		wp_localize_script(
			'aqm-ghl-admin',
			'aqmGhlSettings',
			array(
				'nonce'         => wp_create_nonce( 'aqm_ghl_admin' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'selectedForms' => isset( $current_settings['form_ids'] ) && is_array( $current_settings['form_ids'] ) ? array_map( 'absint', $current_settings['form_ids'] ) : array(),
				'mapping'       => $mapping_normalized,
				'customFields'  => $custom_fields_normalized,
				'ghlFields'     => aqm_ghl_get_cached_ghl_custom_fields(),
				'forms'         => $form_options,
				'optionKey'     => AQM_GHL_OPTION_KEY,
				'labels'        => array(
					'loading'    => __( 'Loading fields…', 'aqm-ghl' ),
					'select'     => __( 'Select a field', 'aqm-ghl' ),
					'selectGhl'  => __( 'Select a GHL field', 'aqm-ghl' ),
				),
			)
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

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
			<?php if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) || empty( $settings['form_ids'] ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Configuration incomplete. Add your GoHighLevel credentials and select at least one Formidable form.', 'aqm-ghl' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" class="aqm-ghl-form">
				<?php
				settings_fields( 'aqm_ghl_connector' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-location-id"><?php esc_html_e( 'GHL Location ID', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[location_id]" id="aqm-ghl-location-id" type="text" value="<?php echo esc_attr( $settings['location_id'] ); ?>" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Paste the GoHighLevel Location ID.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-private-token"><?php esc_html_e( 'GHL Private Integration Token', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[private_token]" id="aqm-ghl-private-token" type="password" value="" placeholder="••••••••" class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Token is masked after save. Leave blank to keep the current token. If you paste a new token (e.g. with Custom Fields scope), click Save Settings so it is stored before using Refresh/Provision Custom Fields.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Formidable Forms', 'aqm-ghl' ); ?></label></th>
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
												<?php
												printf(
													/* translators: 1: form name, 2: form ID */
													esc_html__( '%1$s (ID: %2$d)', 'aqm-ghl' ),
													esc_html( $form->name ),
													(int) $form->id
												);
												?>
											</span>
										</label>
									<?php endforeach; ?>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No forms found. Please create a form in Formidable Forms first.', 'aqm-ghl' ); ?></p>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Select one or more forms to send to GoHighLevel.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Field Mapping', 'aqm-ghl' ); ?></h2>
				<p class="description" style="margin-bottom: 1em;">
					<?php esc_html_e( 'GHL custom fields are auto-detected and mapped to matching Formidable fields. Use "Refresh GHL Fields" if you have added or changed fields in GHL.', 'aqm-ghl' ); ?>
				</p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-fetch-ghl-fields"><?php esc_html_e( 'Refresh GHL Fields', 'aqm-ghl' ); ?></button>
					<button type="button" class="button button-secondary" id="aqm-ghl-provision-fields"><?php esc_html_e( 'Provision UTM/GCLID Fields', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-fetch-result" class="aqm-ghl-fetch-result" style="display:none;"></span>
					<span id="aqm-ghl-provision-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
				</p>
				<div id="aqm-ghl-form-mapping-containers">
					<!-- Per-form mapping containers injected by JS -->
				</div>

				<h2><?php esc_html_e( 'Optional Settings', 'aqm-ghl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-tags"><?php esc_html_e( 'Tags', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[tags]" id="aqm-ghl-tags" type="text" value="<?php echo esc_attr( $settings['tags'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated tags to apply to the contact.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-logging"><?php esc_html_e( 'Enable logging', 'aqm-ghl' ); ?></label></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[enable_logging]" id="aqm-ghl-logging" type="checkbox" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?> />
								<?php esc_html_e( 'Log requests and errors to the PHP error log.', 'aqm-ghl' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Connection Test', 'aqm-ghl' ); ?></h2>
				<p><?php esc_html_e( 'Send a mock "John Doe" contact to your GoHighLevel location to verify credentials.', 'aqm-ghl' ); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-test-connection"><?php esc_html_e( 'Send Test Contact', 'aqm-ghl' ); ?></button>
				</p>
				<div id="aqm-ghl-test-result" class="notice inline" style="display:none;"></div>

				<h2><?php esc_html_e( 'Update Management', 'aqm-ghl' ); ?></h2>
				<p><?php esc_html_e( 'Updates are pulled from a public release feed. If a new version is not appearing, clear the update cache.', 'aqm-ghl' ); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-clear-cache"><?php esc_html_e( 'Clear Update Cache', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-cache-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: current version */
						esc_html__( 'Current version: %s', 'aqm-ghl' ),
						esc_html( AQM_GHL_CONNECTOR_VERSION )
					);
					?>
				</p>

				<h2><?php esc_html_e( 'Last Test Result', 'aqm-ghl' ); ?></h2>
				<?php if ( ! empty( $last_test['timestamp'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Timestamp:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['timestamp'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Status:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['status'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Message:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['message'] ); ?>
					</p>
					<?php if ( $last_payload ) : ?>
						<p><strong><?php esc_html_e( 'Request Payload:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_payload ); ?></pre>
					<?php endif; ?>
					<?php if ( ! empty( $last_test['response'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Response Body:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_test['response'] ); ?></pre>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No test run yet.', 'aqm-ghl' ); ?></p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Last Live Submission', 'aqm-ghl' ); ?></h2>
				<?php if ( ! empty( $last_submission['timestamp'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Timestamp:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_submission['timestamp'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Status:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_submission['status'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Message:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_submission['message'] ); ?>
					</p>
					<?php if ( $last_submission_context ) : ?>
						<p><strong><?php esc_html_e( 'Context:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_submission_context ); ?></pre>
					<?php endif; ?>
					<?php if ( $last_submission_payload ) : ?>
						<p><strong><?php esc_html_e( 'Request Payload:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_submission_payload ); ?></pre>
					<?php endif; ?>
					<?php if ( ! empty( $last_submission['response'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Response Body:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_submission['response'] ); ?></pre>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No live submissions recorded yet.', 'aqm-ghl' ); ?></p>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Import / Export', 'aqm-ghl' ); ?></h2>
			<p><?php esc_html_e( 'Export or import all settings (including GHL Location ID and Private Integration Token) as a JSON file.', 'aqm-ghl' ); ?></p>

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

		$sanitized['enable_logging'] = ! empty( $input['enable_logging'] ) ? 1 : 0;

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


