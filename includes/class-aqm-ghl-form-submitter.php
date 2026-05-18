<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the freshly-created GHL contact to any GHL workflows configured for
 * the WordPress form. Listens on the `aqm_ghl_contact_created` action fired
 * by AQM_GHL_Handler after a successful POST /contacts/ — so we know the
 * contact exists before we try to attach a workflow to it.
 *
 * Why this approach (instead of webhooks or GHL's native form-submit):
 *   - GHL's /forms/submit endpoint requires a reCAPTCHA Enterprise v3 token
 *     that's cryptographically tied to a real browser session and can't be
 *     generated server-side. Verified by replay.
 *   - Inbound Webhook URLs work but require manual paste per form, which the
 *     user explicitly wants to avoid.
 *   - POST /contacts/{id}/workflow/{wfId} requires no extra config: same PIT
 *     the plugin already uses, and the user just picks from a dropdown of
 *     workflows fetched via GET /workflows/.
 */
class AQM_GHL_Form_Submitter {

	public function __construct() {
		add_action( 'aqm_ghl_contact_created', array( $this, 'add_to_workflows' ), 10, 4 );
	}

	/**
	 * Add the new contact to each workflow configured for the form.
	 *
	 * Each workflow is attempted independently — one failure doesn't block
	 * the others, and none of them block the Formidable success flow (we
	 * fire after the contact handler has already returned).
	 *
	 * @param string $contact_id GHL contact ID.
	 * @param int    $entry_id   Formidable entry ID.
	 * @param int    $form_id    Formidable form ID.
	 * @param array  $location   { location_id, private_token }.
	 */
	public function add_to_workflows( $contact_id, $entry_id, $form_id, $location ) {
		$contact_id = (string) $contact_id;
		$form_id    = (int) $form_id;

		if ( '' === $contact_id || ! $form_id ) {
			return;
		}

		$binding = aqm_ghl_get_workflow_binding_for_form( $form_id );
		if ( empty( $binding['enabled'] ) || empty( $binding['workflow_ids'] ) ) {
			return;
		}

		$token = isset( $location['private_token'] ) ? (string) $location['private_token'] : '';
		if ( '' === $token ) {
			aqm_ghl_log(
				'Workflow attach skipped: location missing private_token.',
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
			return;
		}

		foreach ( (array) $binding['workflow_ids'] as $workflow_id ) {
			$workflow_id = (string) $workflow_id;
			if ( '' === $workflow_id ) {
				continue;
			}

			$result = aqm_ghl_add_contact_to_workflow( $contact_id, $workflow_id, $token );

			if ( is_wp_error( $result ) ) {
				aqm_ghl_log(
					'Workflow attach failed (transport error).',
					array(
						'entry_id'    => $entry_id,
						'form_id'     => $form_id,
						'contact_id'  => $contact_id,
						'workflow_id' => $workflow_id,
						'error'       => $result->get_error_message(),
					)
				);
				continue;
			}

			$code = isset( $result['status'] ) ? (int) $result['status'] : 0;
			$ok   = ( $code >= 200 && $code < 300 );

			aqm_ghl_log(
				$ok ? 'Workflow attach succeeded.' : 'Workflow attach returned non-2xx.',
				array(
					'entry_id'    => $entry_id,
					'form_id'     => $form_id,
					'contact_id'  => $contact_id,
					'workflow_id' => $workflow_id,
					'status'      => $code,
					'body'        => isset( $result['body'] ) ? mb_substr( (string) $result['body'], 0, 500 ) : '',
				)
			);
		}
	}
}
