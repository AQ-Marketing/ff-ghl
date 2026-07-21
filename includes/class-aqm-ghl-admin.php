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
		// Backfill / resend past submissions to GoHighLevel.
		add_action( 'wp_ajax_aqm_ghl_backfill_list', array( $this, 'ajax_backfill_list' ) );
		add_action( 'wp_ajax_aqm_ghl_backfill_check', array( $this, 'ajax_backfill_check' ) );
		add_action( 'wp_ajax_aqm_ghl_backfill_push', array( $this, 'ajax_backfill_push' ) );
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
		<?php
		// ── Connection status eyebrow ──
		// Single source of truth for the badge under the page title. Reflects the
		// ACTIVE verification (tokens that exist but no longer work read as "lost",
		// not "connected"). PIT-only legacy sites still read as connected.
		// "Connected" requires usable tokens AND a known sub-account (is_ready) —
		// not just valid tokens. Tokens that verify but have no resolvable
		// sub-account can't actually send leads, so they read as "lost" (reconnect)
		// rather than falsely green. This keeps the badge in agreement with the
		// Test connection panel below, which has always required a sub-account.
		$conn_state         = function_exists( 'aqm_ghl_connection_state' ) ? aqm_ghl_connection_state() : array( 'can_send' => false, 'has_oauth_tokens' => false, 'oauth_class' => '', 'mode' => '' );
		$eyebrow_can_send   = ! empty( $conn_state['can_send'] );
		$eyebrow_oauth_has  = ! empty( $conn_state['has_oauth_tokens'] );
		$eyebrow_agency     = ( 'Company' === ( isset( $conn_state['oauth_class'] ) ? $conn_state['oauth_class'] : '' ) );
		$eyebrow_loc_name   = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';

		if ( $eyebrow_can_send ) {
			$eyebrow_state = 'connected';
		} elseif ( $eyebrow_oauth_has ) {
			$eyebrow_state = 'lost'; // tokens stored but can't resolve a usable send path
		} else {
			$eyebrow_state = 'disconnected';
		}
		?>
		<div class="wrap aqm-ghl-wrap">
			<h1 style="margin-bottom: 4px;"><?php esc_html_e( 'GHL + Formidable', 'aqm-ghl' ); ?></h1>

			<?php
			$eyebrow_styles = array(
				'connected'    => array( 'bg' => '#edfaef', 'border' => '#00a32a', 'dot' => '#00a32a', 'text' => '#0a4f1c' ),
				'lost'         => array( 'bg' => '#fcf0f1', 'border' => '#d63638', 'dot' => '#d63638', 'text' => '#8a1f21' ),
				'disconnected' => array( 'bg' => '#f6f7f7', 'border' => '#c3c4c7', 'dot' => '#a7aaad', 'text' => '#50575e' ),
			);
			$es = $eyebrow_styles[ $eyebrow_state ];
			?>
			<div style="margin: 0 0 16px;">
				<span style="display: inline-flex; align-items: center; gap: 7px; padding: 4px 12px; border: 1px solid <?php echo esc_attr( $es['border'] ); ?>; background: <?php echo esc_attr( $es['bg'] ); ?>; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: <?php echo esc_attr( $es['text'] ); ?>;">
					<span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo esc_attr( $es['dot'] ); ?>; <?php echo 'connected' === $eyebrow_state ? 'box-shadow: 0 0 0 3px ' . esc_attr( $es['bg'] ) . ', 0 0 6px ' . esc_attr( $es['dot'] ) . ';' : ''; ?>"></span>
					<?php
					if ( 'connected' === $eyebrow_state ) {
						if ( '' !== $eyebrow_loc_name ) {
							/* translators: %s: GHL sub-account name */
							printf( esc_html__( 'Connected to GoHighLevel · %s', 'aqm-ghl' ), esc_html( $eyebrow_loc_name ) );
						} else {
							esc_html_e( 'Connected to GoHighLevel', 'aqm-ghl' );
						}
					} elseif ( 'lost' === $eyebrow_state ) {
						esc_html_e( 'GoHighLevel connection lost — reconnect', 'aqm-ghl' );
					} else {
						esc_html_e( 'Not connected to GoHighLevel', 'aqm-ghl' );
					}
					?>
				</span>
			</div>

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
			// Uses the ACTIVE verification — tokens that exist but no longer work shouldn't count as "set up".
			$oauth_connected = class_exists( 'AQM_GHL_OAuth' ) && AQM_GHL_OAuth::is_truly_connected();
			$pit_configured  = ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] );
			$has_auth        = $oauth_connected || $pit_configured;
			$has_forms       = ! empty( $settings['form_ids'] );
			?>
			<?php // Only nag about forms here — the "Connect to GoHighLevel" card below
			// is already a loud CTA when auth isn't set up, so a redundant yellow
			// banner above it is just noise. ?>
			<?php if ( $has_auth && ! $has_forms ) : ?>
				<div class="notice notice-warning">
					<p>
						<strong><?php esc_html_e( 'Setup needed:', 'aqm-ghl' ); ?></strong>
						<?php esc_html_e( 'Select at least one Formidable form below to send submissions to GoHighLevel.', 'aqm-ghl' ); ?>
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

				<?php
				// ── Field mapping ──
				// Let the user confirm/adjust how each selected form's fields fill the
				// GHL contact. Values are auto-detected on first save; the dropdowns are
				// pre-selected from the saved mapping (or the auto-detected guess for a
				// form with no saved mapping yet). Saved by the main form via
				// sanitize_settings(), which treats a submitted form's mapping as
				// authoritative (so "— Not mapped —" sticks).
				$map_selected = isset( $settings['form_ids'] ) ? array_map( 'absint', (array) $settings['form_ids'] ) : array();
				$contact_fields = array(
					'email'       => __( 'Email', 'aqm-ghl' ),
					'first_name'  => __( 'First name', 'aqm-ghl' ),
					'last_name'   => __( 'Last name', 'aqm-ghl' ),
					'phone'       => __( 'Phone', 'aqm-ghl' ),
					'address1'    => __( 'Street address', 'aqm-ghl' ),
					'city'        => __( 'City', 'aqm-ghl' ),
					'state'       => __( 'State / province', 'aqm-ghl' ),
					'postal_code' => __( 'ZIP / postal code', 'aqm-ghl' ),
				);
				$form_names = array();
				if ( ! empty( $forms ) ) {
					foreach ( $forms as $f ) {
						$form_names[ (int) $f->id ] = (string) $f->name;
					}
				}
				?>
				<h2 style="margin-top: 2em;"><?php esc_html_e( 'Field mapping', 'aqm-ghl' ); ?></h2>
				<p class="description" style="max-width: 780px;">
					<?php esc_html_e( 'How each form\'s fields fill the GoHighLevel contact. These are auto-detected — review and adjust any that look wrong, or choose “— Not mapped —” to skip one. Custom fields are matched automatically by label. Click Save Changes below to apply.', 'aqm-ghl' ); ?>
				</p>

				<?php if ( empty( $map_selected ) ) : ?>
					<p class="description"><?php esc_html_e( 'Select at least one form above and click Save Changes to configure its field mapping.', 'aqm-ghl' ); ?></p>
				<?php else : ?>
					<?php foreach ( $map_selected as $fid ) : ?>
						<?php
						$fid       = absint( $fid );
						$form_lbl  = isset( $form_names[ $fid ] ) ? $form_names[ $fid ] : sprintf( /* translators: %d: form ID */ __( 'Form %d', 'aqm-ghl' ), $fid );
						$ff        = function_exists( 'aqm_ghl_get_formidable_form_fields' ) ? aqm_ghl_get_formidable_form_fields( $fid ) : array();
						$cur       = isset( $settings['mapping'][ $fid ] ) && is_array( $settings['mapping'][ $fid ] ) ? $settings['mapping'][ $fid ] : array();
						// Only fall back to a fresh auto-detect when this form has no saved
						// mapping at all — otherwise respect what's stored (incl. blanks).
						$auto      = ( empty( $cur ) && function_exists( 'aqm_ghl_autodetect_mapping_for_form' ) ) ? aqm_ghl_autodetect_mapping_for_form( $fid ) : array();
						?>
						<details style="margin: 0 0 10px; border: 1px solid #dcdcde; background: #fff;" <?php echo ( count( $map_selected ) === 1 ) ? 'open' : ''; ?>>
							<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
								<?php echo esc_html( $form_lbl ); ?>
								<span class="description" style="font-weight: normal;">(ID: <?php echo (int) $fid; ?>)</span>
							</summary>
							<div style="padding: 6px 14px 12px;">
								<?php if ( empty( $ff ) ) : ?>
									<p class="description"><?php esc_html_e( 'No editable fields found on this form.', 'aqm-ghl' ); ?></p>
								<?php else : ?>
									<table class="form-table" role="presentation" style="margin-top: 0;">
										<?php foreach ( $contact_fields as $ckey => $clabel ) : ?>
											<?php
											$selected_val = ! empty( $cur[ $ckey ] )
												? (int) $cur[ $ckey ]
												: ( ( empty( $cur ) && ! empty( $auto[ $ckey ] ) ) ? (int) $auto[ $ckey ] : 0 );
											?>
											<tr>
												<th scope="row" style="width: 170px; padding: 8px 10px;"><label><?php echo esc_html( $clabel ); ?></label></th>
												<td style="padding: 8px 10px;">
													<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[mapping][<?php echo (int) $fid; ?>][<?php echo esc_attr( $ckey ); ?>]">
														<option value=""><?php esc_html_e( '— Not mapped —', 'aqm-ghl' ); ?></option>
														<?php foreach ( $ff as $field ) : ?>
															<option value="<?php echo (int) $field['id']; ?>" <?php selected( $selected_val, (int) $field['id'] ); ?>>
																<?php echo esc_html( ( '' !== $field['label'] ) ? $field['label'] : sprintf( /* translators: %d: field ID */ __( 'Field %d', 'aqm-ghl' ), $field['id'] ) ); ?> (ID: <?php echo (int) $field['id']; ?>)
															</option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
										<?php endforeach; ?>
									</table>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>

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
						$test_state  = function_exists( 'aqm_ghl_connection_state' ) ? aqm_ghl_connection_state() : array( 'can_send' => false, 'mode' => '', 'location_id' => '' );
						$test_ready  = ! empty( $test_state['can_send'] ); // mirrors the real send path
						$test_mode   = isset( $test_state['mode'] ) ? (string) $test_state['mode'] : '';
						$test_loc_id = isset( $test_state['location_id'] ) ? (string) $test_state['location_id'] : '';
						$test_loc_nm = '';
						if ( 'oauth' === $test_mode ) {
							$test_loc_nm = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';
						} elseif ( ! empty( $settings['locations'][0]['name'] ) ) {
							$test_loc_nm = (string) $settings['locations'][0]['name'];
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

				<?php $this->render_diagnostics_panel( $settings ); ?>

			</form>

			<?php $this->render_backfill_section( $settings, $forms ); ?>
		</div>
		<?php
	}

	/**
	 * Render a "Connection diagnostics" panel — a copy-paste, plain-language
	 * snapshot of the OAuth connection state (token presence, expiry, last
	 * auto-renewal outcome and WHY it failed, last submission). Contains NO token
	 * values — only presence/lengths and non-secret reasons — so it's safe to
	 * share. This is what lets us diagnose recurring "connection lost" issues
	 * without server log access.
	 *
	 * @param array $settings Current plugin settings.
	 */
	private function render_diagnostics_panel( $settings ) {
		$has_oauth = class_exists( 'AQM_GHL_OAuth' );

		$access   = isset( $settings['oauth_access_token'] ) ? (string) $settings['oauth_access_token'] : '';
		$refresh  = isset( $settings['oauth_refresh_token'] ) ? (string) $settings['oauth_refresh_token'] : '';
		$secret   = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
		$exp      = isset( $settings['oauth_token_expires_at'] ) ? (int) $settings['oauth_token_expires_at'] : 0;
		$loc_id   = $has_oauth ? AQM_GHL_OAuth::location_id() : ( isset( $settings['oauth_location_id'] ) ? (string) $settings['oauth_location_id'] : '' );
		$loc_name = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';
		$conn_at  = isset( $settings['oauth_connected_at'] ) ? (string) $settings['oauth_connected_at'] : '';
		$mode     = function_exists( 'aqm_ghl_get_auth_mode' ) ? aqm_ghl_get_auth_mode() : 'pit';

		// Human-friendly expiry (absolute UTC + relative to now).
		$now = time();
		if ( $exp > 0 ) {
			$delta = $exp - $now;
			$abs   = gmdate( 'Y-m-d H:i:s', $exp ) . ' UTC';
			if ( $delta >= 0 ) {
				$rel = sprintf( 'expires in %s', human_time_diff( $now, $exp ) );
			} else {
				$rel = sprintf( 'EXPIRED %s ago', human_time_diff( $exp, $now ) );
			}
			$exp_str = $abs . ' (' . $rel . ')';
		} else {
			$exp_str = '(none stored)';
		}

		$diag        = $has_oauth ? AQM_GHL_OAuth::get_diag() : array();
		$last_refresh = isset( $diag['last_refresh'] ) && is_array( $diag['last_refresh'] ) ? $diag['last_refresh'] : array();
		$last_store   = isset( $diag['last_store'] ) && is_array( $diag['last_store'] ) ? $diag['last_store'] : array();

		$last_sub = function_exists( 'aqm_ghl_get_last_submission_result' ) ? aqm_ghl_get_last_submission_result() : array();

		$yn = function ( $v ) {
			return $v ? 'yes' : 'NO';
		};
		$kv = function ( $arr ) {
			if ( empty( $arr ) ) {
				return '(never)';
			}
			$parts = array();
			foreach ( $arr as $k => $v ) {
				if ( is_bool( $v ) ) {
					$v = $v ? 'yes' : 'no';
				}
				$parts[] = $k . '=' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
			}
			return implode( '  ', $parts );
		};

		$lines   = array();
		$lines[] = 'AQM GHL — Connection diagnostics';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = 'Plugin version: ' . AQM_GHL_CONNECTOR_VERSION;
		$lines[] = 'Site: ' . home_url();
		$lines[] = '';
		$lines[] = 'Auth mode: ' . $mode;
		$lines[] = 'Client secret saved: ' . $yn( '' !== $secret ) . ( '' !== $secret ? ' (…' . substr( $secret, -4 ) . ', len ' . strlen( $secret ) . ')' : '' );
		$lines[] = 'Access token stored: ' . $yn( '' !== $access ) . ( '' !== $access ? ' (len ' . strlen( $access ) . ')' : '' );
		$lines[] = 'Refresh token stored: ' . $yn( '' !== $refresh ) . ( '' !== $refresh ? ' (len ' . strlen( $refresh ) . ')' : '   <-- if NO, the connection cannot auto-renew' );
		$lines[] = 'Access token expiry: ' . $exp_str;
		$lines[] = 'Sub-account (location) id: ' . ( '' !== $loc_id ? $loc_id : '(none resolved)' );
		$lines[] = 'Sub-account name: ' . ( '' !== $loc_name ? $loc_name : '(unknown)' );
		$lines[] = 'Connected at: ' . ( '' !== $conn_at ? $conn_at : '(unknown)' );
		$lines[] = '';
		$lines[] = 'Last token store: ' . $kv( $last_store );
		$lines[] = 'Last auto-renewal: ' . $kv( $last_refresh );
		$lines[] = '';
		if ( ! empty( $last_sub ) ) {
			$lines[] = 'Last submission attempt: ' . ( ! empty( $last_sub['timestamp'] ) ? $last_sub['timestamp'] : '(none)' )
				. ' — ' . ( ! empty( $last_sub['success'] ) ? 'SUCCESS' : 'failed' )
				. ' (HTTP ' . ( isset( $last_sub['status'] ) ? (int) $last_sub['status'] : 0 ) . ')';
			if ( ! empty( $last_sub['message'] ) ) {
				$lines[] = '  message: ' . $last_sub['message'];
			}
			if ( ! empty( $last_sub['context']['source'] ) ) {
				$lines[] = '  source: ' . $last_sub['context']['source'];
			}
		} else {
			$lines[] = 'Last submission attempt: (none recorded)';
		}

		$report = implode( "\n", $lines );
		?>
		<details style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff;">
			<summary style="cursor: pointer; padding: 10px 14px; background: #f6f7f7; font-weight: 600;">
				<?php esc_html_e( 'Connection diagnostics', 'aqm-ghl' ); ?>
			</summary>
			<div style="padding: 12px 18px;">
				<p><?php esc_html_e( 'A safe, copy-paste snapshot of the GoHighLevel connection — what’s stored, when the login expires, and why the last auto-renewal succeeded or failed. It contains no passwords or tokens. If support asks, click Copy and paste it to them.', 'aqm-ghl' ); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-copy-diag"><?php esc_html_e( 'Copy diagnostics', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-copy-diag-done" style="display:none; margin-left: 10px; color: #0a4f1c;"><?php esc_html_e( 'Copied ✓', 'aqm-ghl' ); ?></span>
				</p>
				<textarea id="aqm-ghl-diag-text" readonly rows="18" style="width: 100%; font-family: monospace; font-size: 12px; white-space: pre;"><?php echo esc_textarea( $report ); ?></textarea>
			</div>
		</details>
		<?php
	}

	/**
	 * Render the "Backfill / resend submissions" section. Lets the admin push
	 * past Formidable submissions (e.g. ones created before the connection
	 * worked) to GoHighLevel: pick a form, choose a date range, load the
	 * submissions, see which are already in GHL, and push the missing ones.
	 *
	 * Rendered OUTSIDE the settings <form> so its controls never submit to
	 * options.php. All work happens over AJAX (see ajax_backfill_* handlers).
	 *
	 * @param array $settings Current plugin settings.
	 * @param array $forms    All published Formidable forms.
	 */
	private function render_backfill_section( $settings, $forms ) {
		$form_ids = isset( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? array_map( 'absint', $settings['form_ids'] ) : array();

		// Only the forms that are actually enabled for GHL are eligible.
		$enabled = array();
		if ( ! empty( $forms ) ) {
			foreach ( $forms as $form ) {
				$fid = (int) $form->id;
				if ( in_array( $fid, $form_ids, true ) ) {
					$enabled[ $fid ] = (string) $form->name;
				}
			}
		}

		// Default date range: last 30 days through today, in the site's timezone.
		// wp_date() takes a real UTC timestamp (time()) and formats it in the site
		// tz — avoids the double-offset bug of feeding current_time('timestamp').
		$today        = current_time( 'Y-m-d' );
		$default_to   = $today;
		$default_from = wp_date( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
		?>
		<h2 style="margin-top: 2em;"><?php esc_html_e( 'Backfill / resend submissions', 'aqm-ghl' ); ?></h2>
		<p class="description" style="max-width: 820px;">
			<?php esc_html_e( 'Send past form submissions to GoHighLevel — useful for entries created before the connection was working. Pick a form, choose a date range, and load the submissions. Each row shows whether that contact is already in GoHighLevel; tick the ones you want and push them. Submissions already in GoHighLevel are skipped, so nothing is overwritten or duplicated.', 'aqm-ghl' ); ?>
		</p>

		<?php if ( empty( $enabled ) ) : ?>
			<div class="notice notice-info inline" style="margin: 1em 0;"><p>
				<?php esc_html_e( 'No forms are enabled for GoHighLevel yet. Tick at least one form under "Which forms to send" above and save, then come back here.', 'aqm-ghl' ); ?>
			</p></div>
		<?php else : ?>
			<div class="aqm-ghl-backfill" style="margin: 1em 0; border: 1px solid #dcdcde; background: #fff; padding: 16px 18px; max-width: 1100px;">
				<table class="form-table" role="presentation" style="margin-top: 0;">
					<tr>
						<th scope="row"><label for="aqm-ghl-bf-form"><?php esc_html_e( 'Form', 'aqm-ghl' ); ?></label></th>
						<td>
							<select id="aqm-ghl-bf-form">
								<?php foreach ( $enabled as $fid => $fname ) : ?>
									<option value="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( $fname ); ?> <?php /* translators: %d: form ID */ printf( esc_html__( '(ID: %d)', 'aqm-ghl' ), (int) $fid ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date range', 'aqm-ghl' ); ?></th>
						<td>
							<label><?php esc_html_e( 'From', 'aqm-ghl' ); ?>
								<input type="date" id="aqm-ghl-bf-from" value="<?php echo esc_attr( $default_from ); ?>" max="<?php echo esc_attr( $today ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'To', 'aqm-ghl' ); ?>
								<input type="date" id="aqm-ghl-bf-to" value="<?php echo esc_attr( $default_to ); ?>" max="<?php echo esc_attr( $today ); ?>">
							</label>
							<p class="description"><?php esc_html_e( 'Filters by the date each submission was created. Dates use this site\'s time zone.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-bf-load"><?php esc_html_e( 'Load submissions', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-bf-load-status" style="margin-left: 10px; color: #50575e;"></span>
				</p>

				<div id="aqm-ghl-bf-results" style="display:none; margin-top: 12px;">
					<table class="widefat striped aqm-ghl-bf-table">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="aqm-ghl-bf-select-all" title="<?php esc_attr_e( 'Select all sendable', 'aqm-ghl' ); ?>"></td>
								<th><?php esc_html_e( 'Date', 'aqm-ghl' ); ?></th>
								<th><?php esc_html_e( 'Name', 'aqm-ghl' ); ?></th>
								<th><?php esc_html_e( 'Email', 'aqm-ghl' ); ?></th>
								<th><?php esc_html_e( 'In GoHighLevel?', 'aqm-ghl' ); ?></th>
								<th><?php esc_html_e( 'Result', 'aqm-ghl' ); ?></th>
							</tr>
						</thead>
						<tbody id="aqm-ghl-bf-rows"></tbody>
					</table>

					<p style="margin-top: 12px;">
						<button type="button" class="button button-primary" id="aqm-ghl-bf-push" disabled><?php esc_html_e( 'Push selected to GoHighLevel', 'aqm-ghl' ); ?></button>
						<span id="aqm-ghl-bf-push-status" style="margin-left: 10px; color: #50575e;"></span>
					</p>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Shared guard for the backfill AJAX endpoints: verify the nonce and the
	 * manage_options capability, then validate that the posted form_id is one of
	 * the forms actually enabled for GoHighLevel (so the tool can't be pointed at
	 * arbitrary forms). On any failure it sends a JSON error and halts the
	 * request (wp_send_json_error → wp_die), so callers only ever see an int.
	 *
	 * @return int The validated form_id (or the request has already exited).
	 */
	private function backfill_guard() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}
		$form_id  = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$settings = aqm_ghl_get_settings();
		$form_ids = isset( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? array_map( 'absint', $settings['form_ids'] ) : array();
		if ( ! $form_id || ! in_array( $form_id, $form_ids, true ) ) {
			wp_send_json_error( array( 'message' => __( 'That form is not enabled for GoHighLevel.', 'aqm-ghl' ) ), 400 );
		}
		return $form_id;
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $d Date string.
	 * @return bool
	 */
	private function is_valid_ymd( $d ) {
		if ( ! is_string( $d ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return false;
		}
		return checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
	}

	/**
	 * AJAX: list a form's submissions within a date range (DB only — no GHL
	 * calls, so it's fast). Returns rows with date/name/email; the GHL "already
	 * there?" status is filled in afterward by ajax_backfill_check in batches.
	 */
	public function ajax_backfill_list() {
		$form_id = $this->backfill_guard();

		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] )   ? sanitize_text_field( wp_unslash( $_POST['to'] ) )   : '';
		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;

		if ( ! $this->is_valid_ymd( $from ) || ! $this->is_valid_ymd( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid From and To date.', 'aqm-ghl' ) ), 400 );
		}
		if ( $from > $to ) {
			wp_send_json_error( array( 'message' => __( 'The From date must be on or before the To date.', 'aqm-ghl' ) ), 400 );
		}

		// Convert the site-timezone day bounds to UTC, because Formidable stores
		// created_at in UTC. Whole-day inclusive: 00:00:00 → 23:59:59 local.
		$from_utc = get_gmt_from_date( $from . ' 00:00:00', 'Y-m-d H:i:s' );
		$to_utc   = get_gmt_from_date( $to . ' 23:59:59', 'Y-m-d H:i:s' );

		$page_size = 200;
		$offset    = ( $page - 1 ) * $page_size;
		$total     = aqm_ghl_count_entries_by_date( $form_id, $from_utc, $to_utc );
		$entries   = aqm_ghl_query_entries_by_date( $form_id, $from_utc, $to_utc, $page_size, $offset );

		$rows = array();
		foreach ( $entries as $e ) {
			$fields = aqm_ghl_get_entry_summary_fields( $e['id'], $form_id );
			$rows[] = array(
				'entry_id'   => (int) $e['id'],
				'date'       => get_date_from_gmt( $e['created_at'], 'M j, Y g:i a' ),
				'name'       => $fields['name'],
				'email'      => $fields['email'],
				'sendable'   => ( '' !== $fields['email'] ), // need an email to dedupe + a contact key
			);
		}

		wp_send_json_success(
			array(
				'rows'      => $rows,
				'total'     => (int) $total,
				'page'      => (int) $page,
				'page_size' => (int) $page_size,
				'returned'  => count( $rows ),
			)
		);
	}

	/**
	 * AJAX: for a small batch of entry IDs, check whether each one's email
	 * already exists as a contact in GoHighLevel. Drives the per-row status badge
	 * AND the skip-if-exists behaviour. The browser calls this in batches.
	 */
	public function ajax_backfill_check() {
		$form_id = $this->backfill_guard();

		$ids = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['entry_ids'] ) ) ) ) )
			: array();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No submissions to check.', 'aqm-ghl' ) ), 400 );
		}
		$ids = array_slice( $ids, 0, 25 ); // defensive cap

		$auth = aqm_ghl_get_active_auth();
		if ( is_wp_error( $auth ) ) {
			wp_send_json_error( array( 'message' => __( 'Not connected to GoHighLevel.', 'aqm-ghl' ) . ' ' . $auth->get_error_message() ), 400 );
		}

		$results = array();
		// Some connections (legacy Private Integration tokens) can WRITE contacts
		// but have no contacts-READ scope, so every lookup 401s. Detecting that on
		// the first lookup lets us stop hammering the API and mark the rest
		// "unverified" instead of showing an alarming "Couldn't check" on every row.
		// Pushing is still safe — GHL blocks/updates duplicates on send.
		$scope_blocked = false;
		foreach ( $ids as $id ) {
			$fields = aqm_ghl_get_entry_summary_fields( $id, $form_id );
			if ( '' === $fields['email'] ) {
				$results[] = array( 'entry_id' => $id, 'status' => 'no_email', 'contact_id' => '' );
				continue;
			}
			if ( $scope_blocked ) {
				$results[] = array( 'entry_id' => $id, 'status' => 'unverified', 'contact_id' => '' );
				continue;
			}
			$lookup = aqm_ghl_find_contact_by_email( $auth['location_id'], $fields['email'], $auth['token'] );
			if ( is_wp_error( $lookup ) ) {
				$emsg = $lookup->get_error_message();
				if ( $this->is_ghl_scope_error( $emsg ) ) {
					$scope_blocked = true;
					$results[]     = array( 'entry_id' => $id, 'status' => 'unverified', 'contact_id' => '' );
				} else {
					$results[] = array( 'entry_id' => $id, 'status' => 'error', 'contact_id' => '', 'message' => $emsg );
				}
			} elseif ( ! empty( $lookup['found'] ) ) {
				$results[] = array( 'entry_id' => $id, 'status' => 'in_ghl', 'contact_id' => (string) $lookup['contact_id'] );
			} else {
				$results[] = array( 'entry_id' => $id, 'status' => 'not_in_ghl', 'contact_id' => '' );
			}
			usleep( 120000 ); // ~120ms between lookups — stay under GHL rate limits
		}

		$response = array( 'results' => $results );
		if ( $scope_blocked ) {
			$response['scope_blocked'] = true;
			$response['scope_message'] = __( 'Can’t pre-check GoHighLevel — this site’s connection can add contacts but doesn’t have permission to read them, so we can’t tell in advance which are already there. You can still push: GoHighLevel automatically skips or updates contacts that already exist, so nothing is duplicated. (To enable the pre-check, reconnect this site to GoHighLevel, or give its token the “View Contacts” permission.)', 'aqm-ghl' );
		}
		wp_send_json_success( $response );
	}

	/**
	 * Whether a GHL API error message indicates a missing OAuth/token scope
	 * (as opposed to a transient network or server error). Used to degrade the
	 * backfill "already in GHL?" pre-check gracefully instead of spamming errors.
	 *
	 * @param string $message Error message from a lookup.
	 * @return bool
	 */
	private function is_ghl_scope_error( $message ) {
		$message = strtolower( (string) $message );
		if ( false !== strpos( $message, 'not authorized for this scope' ) ) {
			return true;
		}
		if ( false !== strpos( $message, 'authclass' ) ) {
			return true;
		}
		// "returned 401" / "returned 403" from aqm_ghl_find_contact_by_email().
		return ( false !== strpos( $message, '401' ) || false !== strpos( $message, '403' ) );
	}

	/**
	 * AJAX: push a batch of selected entries to GoHighLevel. Each entry is
	 * re-checked by email first; if a contact already exists it's SKIPPED
	 * (nothing overwritten). Otherwise it runs through the exact same pipeline a
	 * live submission uses (field mapping, custom fields, tags, opportunity), via
	 * AQM_GHL_Handler::send_stored_entry(). The browser sends entries in small
	 * batches and renders each result as it returns.
	 */
	public function ajax_backfill_push() {
		$form_id = $this->backfill_guard();

		$ids = isset( $_POST['entry_ids'] ) && is_array( $_POST['entry_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['entry_ids'] ) ) ) ) )
			: array();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No submissions selected.', 'aqm-ghl' ) ), 400 );
		}
		if ( count( $ids ) > 25 ) {
			wp_send_json_error( array( 'message' => __( 'Too many at once — push in smaller batches.', 'aqm-ghl' ) ), 400 );
		}

		// Resolve auth once; bail early on a dead connection rather than looping.
		$auth = aqm_ghl_get_active_auth();
		if ( is_wp_error( $auth ) ) {
			wp_send_json_error( array( 'message' => __( 'Not connected to GoHighLevel.', 'aqm-ghl' ) . ' ' . $auth->get_error_message() ), 400 );
		}

		if ( ! class_exists( 'AQM_GHL_Handler' ) ) {
			wp_send_json_error( array( 'message' => __( 'Send handler unavailable.', 'aqm-ghl' ) ), 500 );
		}
		$handler = new AQM_GHL_Handler();

		$results = array();
		foreach ( $ids as $id ) {
			$fields = aqm_ghl_get_entry_summary_fields( $id, $form_id );

			// Skip-if-exists: when we have an email and GHL already has a contact
			// with it, leave the existing contact untouched.
			if ( '' !== $fields['email'] ) {
				$lookup = aqm_ghl_find_contact_by_email( $auth['location_id'], $fields['email'], $auth['token'] );
				if ( ! is_wp_error( $lookup ) && ! empty( $lookup['found'] ) ) {
					$results[] = array(
						'entry_id'   => $id,
						'outcome'    => 'skipped',
						'contact_id' => (string) $lookup['contact_id'],
						'message'    => __( 'Already in GoHighLevel — skipped.', 'aqm-ghl' ),
					);
					continue;
				}
			}

			$res = $handler->send_stored_entry( $id, $form_id, array( 'source' => 'backfill' ) );

			if ( ! empty( $res['success'] ) ) {
				$results[] = array(
					'entry_id'   => $id,
					'outcome'    => 'sent',
					'contact_id' => isset( $res['contact_id'] ) ? (string) $res['contact_id'] : '',
					'message'    => __( 'Sent to GoHighLevel.', 'aqm-ghl' ),
				);
			} elseif ( isset( $res['status'] ) && 400 === (int) $res['status'] ) {
				// A 400 here almost always means GHL already has this contact
				// (duplicate) — treat as skipped rather than a hard failure.
				$results[] = array(
					'entry_id'   => $id,
					'outcome'    => 'skipped',
					'contact_id' => '',
					'message'    => __( 'Looks like it\'s already in GoHighLevel — skipped.', 'aqm-ghl' ),
				);
			} else {
				$results[] = array(
					'entry_id'   => $id,
					'outcome'    => 'failed',
					'contact_id' => '',
					'message'    => isset( $res['message'] ) ? (string) $res['message'] : __( 'Could not send.', 'aqm-ghl' ),
				);
			}
			usleep( 150000 ); // ~150ms between sends — be gentle on GHL rate limits
		}

		wp_send_json_success( array( 'results' => $results ) );
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

		// Optional sub-account lock, pre-filled from a ?aqm_expect_loc= URL param
		// (e.g. a Connect link AQM hands a client) and carried into the Connect
		// form below. Same alphanumeric-only sanitisation as AQM_GHL_OAuth::handle_start().
		$expect_loc = '';
		if ( isset( $_GET['aqm_expect_loc'] ) && is_string( $_GET['aqm_expect_loc'] ) ) {
			$expect_loc = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_GET['aqm_expect_loc'] ) ), 0, 64 );
		}

		// Two different "connected" checks:
		//   - has_tokens: passive — are OAuth tokens persisted at all?
		//   - is_connected: active — do those tokens still work against GHL?
		// We only flip the UI to the "Connected" card when BOTH are true. If
		// tokens exist but verification fails (revoked app, rotated secret,
		// deleted sub-account, etc.) we show a "Connection lost" state with
		// a fresh Connect button instead of falsely claiming connected status.
		$state            = function_exists( 'aqm_ghl_connection_state' ) ? aqm_ghl_connection_state() : array( 'can_send' => false, 'mode' => '', 'has_oauth_tokens' => false, 'oauth_class' => '', 'location_id' => '' );
		$can_send         = ! empty( $state['can_send'] );
		$active_send_mode = isset( $state['mode'] ) ? (string) $state['mode'] : '';
		$has_tokens       = ! empty( $state['has_oauth_tokens'] );
		$is_agency_token  = ( 'Company' === ( isset( $state['oauth_class'] ) ? $state['oauth_class'] : '' ) );
		$is_connected     = $can_send; // green 'Connected' card whenever leads can be delivered (matches the top badge)
		$tokens_broken    = $has_tokens && ! $can_send; // OAuth tokens exist but nothing can send
		$location_name    = isset( $settings['oauth_location_name'] ) ? (string) $settings['oauth_location_name'] : '';
		$location_id      = ( '' !== (string) ( isset( $state['location_id'] ) ? $state['location_id'] : '' ) ) ? (string) $state['location_id'] : ( isset( $settings['oauth_location_id'] ) ? (string) $settings['oauth_location_id'] : '' );
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
		<?php elseif ( 'wrong_location' === $status ) : ?>
			<div class="notice notice-error is-dismissible"><p>
				<strong><?php esc_html_e( 'Wrong sub-account — nothing was saved.', 'aqm-ghl' ); ?></strong>
				<?php if ( '' !== $msg ) : ?>
					<br><?php echo esc_html( $msg ); ?>
				<?php endif; ?>
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
						<?php if ( $is_agency_token ) : ?>
							<div style="margin-top: 8px; padding: 8px 10px; background: #fffbeb; border-left: 3px solid #d39e00; font-size: 12px; color: #50575e;">
								<?php esc_html_e( 'Heads up: your one-click GoHighLevel app is linked at the agency level, so leads are being delivered with your saved key instead. To use the app directly, click Reconnect and choose this sub-account — not the agency.', 'aqm-ghl' ); ?>
							</div>
						<?php endif; ?>
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
			<?php
			// Show the last 4 chars of the saved secret so the user can verify
			// which value is active without exposing the full secret in the DOM.
			$saved_secret = isset( $settings['oauth_client_secret'] ) ? (string) $settings['oauth_client_secret'] : '';
			$has_secret   = '' !== $saved_secret;
			$tail         = $has_secret ? substr( $saved_secret, -4 ) : '';
			$secret_hint  = $has_secret ? str_repeat( '•', 8 ) . $tail . ' (saved)' : '';
			?>

			<?php if ( $tokens_broken ) : ?>
				<!-- Tokens exist in DB but live verification failed — could be a revoked app,
				     rotated company client_secret, or the sub-account being deleted on GHL. -->
				<div class="notice notice-error" style="margin: 1em 0;">
					<p>
						<?php if ( $is_agency_token ) : ?>
							<strong><?php esc_html_e( 'Connected at the agency level — pick a sub-account.', 'aqm-ghl' ); ?></strong>
							<?php esc_html_e( 'The GoHighLevel app was authorized for your agency, which has no sub-account to send leads to, and no fallback key is configured. Click Connect and choose the specific sub-account (not the agency).', 'aqm-ghl' ); ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'GoHighLevel connection lost.', 'aqm-ghl' ); ?></strong>
							<?php esc_html_e( 'Stored credentials are no longer valid (the app may have been revoked or the secret rotated). Click Connect below to re-authorize.', 'aqm-ghl' ); ?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<!-- COMPACT CONNECT SETUP CARD -->
			<div style="margin: 1em 0; border: 1px solid #2271b1; background: #fff; padding: 12px 16px;">
				<div style="display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap;">
					<div style="flex: 1; min-width: 260px;">
						<strong style="font-size: 14px; display: block; margin-bottom: 2px;"><?php esc_html_e( 'Connect to GoHighLevel', 'aqm-ghl' ); ?></strong>
						<span style="color: #50575e; font-size: 12px;">
							<?php esc_html_e( 'Save the AQM Client Secret, then click Connect to pick the sub-account.', 'aqm-ghl' ); ?>
						</span>
					</div>

					<form method="post" action="options.php" style="display: flex; align-items: center; gap: 6px; margin: 0; flex-wrap: wrap;">
						<?php settings_fields( 'aqm_ghl_connector' ); ?>
						<label for="aqm-ghl-oauth-secret" style="font-size: 12px; font-weight: 600;"><?php esc_html_e( 'Client Secret', 'aqm-ghl' ); ?></label>
						<input
							type="password"
							id="aqm-ghl-oauth-secret"
							name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[oauth_client_secret]"
							value=""
							placeholder="<?php echo $has_secret ? esc_attr( $secret_hint ) : esc_attr__( 'Paste secret', 'aqm-ghl' ); ?>"
							style="width: 200px;"
							autocomplete="new-password"
						/>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save', 'aqm-ghl' ); ?></button>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
						<input type="hidden" name="action" value="aqm_oauth_start" />
						<?php wp_nonce_field( 'aqm_oauth_start' ); ?>
						<button type="submit" class="button button-primary" <?php disabled( ! $has_secret ); ?>>
							<?php esc_html_e( 'Connect →', 'aqm-ghl' ); ?>
						</button>
						<details style="margin-top: 8px;" <?php echo '' !== $expect_loc ? 'open' : ''; ?>>
							<summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: #2271b1;">
								<?php
								echo '' !== $expect_loc
									? esc_html__( '🔒 This install is locked to a specific sub-account', 'aqm-ghl' )
									: esc_html__( 'Lock this install to a specific sub-account (optional)', 'aqm-ghl' );
								?>
							</summary>
							<div style="font-size: 12px; color: #50575e; margin-top: 8px; line-height: 1.6;">
								<p style="margin: 0 0 6px;">
									<?php esc_html_e( 'GoHighLevel can’t pre-select a sub-account on its chooser, so this is a safety check: enter the target sub-account’s locationId (or open this page with ?aqm_expect_loc=THE_ID appended to the URL) and the install will be rejected unless you pick that exact sub-account — so the wrong account can never be connected by mistake. Leave blank for the normal flow.', 'aqm-ghl' ); ?>
								</p>
								<input
									type="text"
									name="aqm_expect_loc"
									value="<?php echo esc_attr( $expect_loc ); ?>"
									placeholder="<?php esc_attr_e( 'e.g. ve9EPM428h8vShlRW1KT', 'aqm-ghl' ); ?>"
									style="width: 260px; font-family: monospace; font-size: 11px;"
									autocomplete="off"
									spellcheck="false"
								/>
							</div>
						</details>
					</form>
				</div>
				<?php if ( ! $has_secret ) : ?>
					<p style="margin: 8px 0 0; font-size: 12px; color: #646970;">
						<?php esc_html_e( 'Same secret for every client install — ask Justin / your AQM contact if you don\'t have it.', 'aqm-ghl' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( $has_secret ) : ?>
					<?php
					// Recovery hint for the #1 stuck state: the user clicks Connect, lands
					// on GHL's chooser, sees every sub-account already marked "installed",
					// and is never redirected back — so this page just keeps showing
					// "Connect →" with no explanation. GHL treats an already-installed
					// app as a no-op instead of re-issuing an authorization code.
					$redirect_uri = class_exists( 'AQM_GHL_OAuth' ) ? AQM_GHL_OAuth::get_redirect_uri() : admin_url( 'admin-ajax.php?action=aqm_oauth_callback' );
					?>
					<details style="margin: 10px 0 0; border-top: 1px solid #f0f0f1; padding-top: 10px;">
						<summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: #2271b1;">
							<?php esc_html_e( 'Clicked Connect but GHL says every sub-account is “already connected” and never sent you back?', 'aqm-ghl' ); ?>
						</summary>
						<div style="font-size: 12px; color: #50575e; margin-top: 8px; line-height: 1.6;">
							<p style="margin: 0 0 8px;">
								<?php esc_html_e( 'GoHighLevel won’t re-issue authorization when the app is already installed on a sub-account — so it never redirects back here. Re-authorize the one sub-account this site should target:', 'aqm-ghl' ); ?>
							</p>
							<ol style="margin: 0 0 8px 18px;">
								<li><?php esc_html_e( 'In GoHighLevel, switch into the target sub-account.', 'aqm-ghl' ); ?></li>
								<li><?php esc_html_e( 'Go to Settings → Integrations → My Apps (or Marketplace → Installed Apps).', 'aqm-ghl' ); ?></li>
								<li><?php esc_html_e( 'Find the AQM GHL Connector app and click Uninstall.', 'aqm-ghl' ); ?></li>
								<li><?php esc_html_e( 'Come back here and click Connect again — you’ll get the Allow screen and be redirected back with a live connection.', 'aqm-ghl' ); ?></li>
							</ol>
							<p style="margin: 0;">
								<?php esc_html_e( 'Still stuck? Make sure this exact redirect URL is allowed in the GHL Marketplace app settings:', 'aqm-ghl' ); ?>
								<br>
								<code style="font-size: 11px; word-break: break-all;"><?php echo esc_html( $redirect_uri ); ?></code>
							</p>
						</div>
					</details>
				<?php endif; ?>
			</div>

			<?php
			// "Legacy PIT mode" notice — only relevant for true legacy sites that
			// have a PIT token saved AND haven't started the OAuth migration yet.
			// Once the OAuth secret is saved, the user is mid-migration and the
			// Connect button above is the obvious next step; re-nagging about PIT
			// just adds noise.
			$pit_token = isset( $settings['private_token'] ) && '' !== (string) $settings['private_token']
				? (string) $settings['private_token']
				: ( ! empty( $settings['locations'][0]['private_token'] ) ? (string) $settings['locations'][0]['private_token'] : '' );
			?>
			<?php if ( 'pit' === $active_mode && '' !== $pit_token && ! $has_secret ) : ?>
				<div style="margin: 1em 0; padding: 8px 12px; background: #fffbeb; border-left: 3px solid #d39e00; font-size: 12px;">
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
		
		// Process new mapping data from the Field mapping UI. The UI submits all
		// standard contact-field keys for each selected form (blank = intentionally
		// "Not mapped"), so a submitted form's mapping is authoritative.
		$std_map_keys      = array( 'email', 'first_name', 'last_name', 'phone', 'address1', 'city', 'state', 'postal_code' );
		$mapping           = isset( $input['mapping'] ) && is_array( $input['mapping'] ) ? $input['mapping'] : array();
		$mapping_submitted = array(); // Form IDs whose mapping came from the UI this save.
		$sanitized['mapping'] = $existing_mapping; // Start with existing - preserve all

		if ( ! empty( $mapping ) ) {
			foreach ( $mapping as $fid => $map_values ) {
				$fid = absint( $fid );
				if ( ! $fid || ! is_array( $map_values ) ) {
					continue;
				}
				// Only update if form is selected; otherwise keep existing.
				if ( ! in_array( $fid, $form_ids, true ) ) {
					if ( isset( $existing_mapping[ $fid ] ) ) {
						$sanitized['mapping'][ $fid ] = $existing_mapping[ $fid ];
					}
					continue;
				}
				// Honor the user's explicit selection for every standard field.
				$row = array();
				foreach ( $std_map_keys as $sk ) {
					$row[ $sk ] = isset( $map_values[ $sk ] ) ? absint( $map_values[ $sk ] ) : '';
				}
				$sanitized['mapping'][ $fid ] = $row;
				$mapping_submitted[]          = $fid;
			}
		}

		// Ensure all selected forms have a mapping entry (even if empty).
		foreach ( $form_ids as $fid ) {
			if ( ! isset( $sanitized['mapping'][ $fid ] ) ) {
				$sanitized['mapping'][ $fid ] = isset( $existing_mapping[ $fid ] )
					? $existing_mapping[ $fid ]
					: array_fill_keys( $std_map_keys, '' );
			}
		}

		// Auto-detect contact fields ONLY for selected forms the user did NOT
		// configure via the UI this save (e.g. a form just ticked, or a legacy
		// save with no mapping panel). Forms whose mapping was explicitly submitted
		// are left exactly as chosen — so an intentional "Not mapped" sticks instead
		// of being silently re-filled by autodetect.
		foreach ( $form_ids as $fid ) {
			if ( in_array( $fid, $mapping_submitted, true ) ) {
				continue;
			}
			if ( ! function_exists( 'aqm_ghl_autodetect_mapping_for_form' ) ) {
				break;
			}
			$detected = aqm_ghl_autodetect_mapping_for_form( $fid );
			if ( empty( $detected ) ) {
				continue;
			}
			// Fill any missing keys (incl. address1/city/state/postal_code) without
			// overwriting values already set.
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

		// CRITICAL: register_setting() applies this sanitizer to EVERY update_option()
		// for this option — including AQM_GHL_OAuth::store_tokens() persisting a
		// freshly-exchanged access/refresh token from the OAuth callback. Those
		// OAuth runtime keys are NOT part of the settings form, so $sanitized never
		// contains them, and the array_merge above (which uses the PRE-write
		// $raw_existing) would silently DROP a brand-new token — the exact reason a
		// Connect could report "connected" yet never persist. Carry these keys
		// through explicitly: prefer the incoming value ($input — a fresh token, or
		// an intentional disconnect clearing it to ''), else keep what's stored.
		foreach ( array(
			'oauth_access_token',
			'oauth_refresh_token',
			'oauth_token_expires_at',
			'oauth_location_id',
			'oauth_location_name',
			'oauth_user_id',
			'oauth_connected_at',
			'oauth_redirect_uri',
		) as $oauth_key ) {
			if ( is_array( $input ) && array_key_exists( $oauth_key, $input ) ) {
				$to_save[ $oauth_key ] = $input[ $oauth_key ];
			} elseif ( array_key_exists( $oauth_key, $raw_existing ) ) {
				$to_save[ $oauth_key ] = $raw_existing[ $oauth_key ];
			}
		}

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

		// A real 2xx (or a duplicate-contact 400, both handled above) is hard
		// proof the OAuth token + sub-account work right now — the strongest
		// possible evidence of a live connection. Honor it: mark the connection
		// verified so the badge and notices reflect reality on the next render,
		// overriding any stale negative the page may have computed earlier. Only
		// fires in OAuth mode after a send that genuinely reached and
		// authenticated to GHL, so it can't assert a false "connected".
		if ( 'oauth' === ( isset( $auth['mode'] ) ? $auth['mode'] : '' ) && class_exists( 'AQM_GHL_OAuth' ) ) {
			set_transient( AQM_GHL_OAuth::VERIFY_TRANSIENT, '1', 5 * MINUTE_IN_SECONDS );
		}

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


