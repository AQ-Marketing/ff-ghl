<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optionally creates an opportunity in GHL for every Formidable submission,
 * linked to the newly-created contact. Listens on the `aqm_ghl_contact_created`
 * action fired by AQM_GHL_Handler — so we always have a contactId in hand.
 *
 * Per-form binding:
 *   - enabled
 *   - pipeline_id (required)
 *   - stage_id (required)
 *   - name_template (default: "{first_name} {last_name} — {form_name}")
 *   - status (open|won|lost|abandoned)
 *   - monetary_value (optional)
 *
 * Template tokens: {first_name}, {last_name}, {email}, {phone},
 * {form_name}, {form_id}, {entry_id}, {site_name}
 */
class AQM_GHL_Opportunity_Pusher {

	public function __construct() {
		add_action( 'aqm_ghl_contact_created', array( $this, 'maybe_create_opportunity' ), 20, 4 );
	}

	/**
	 * Create the opportunity if configured for this form.
	 *
	 * @param string $contact_id GHL contact ID.
	 * @param int    $entry_id   Formidable entry ID.
	 * @param int    $form_id    Formidable form ID.
	 * @param array  $location   { location_id, private_token } — resolved by the auth router.
	 */
	public function maybe_create_opportunity( $contact_id, $entry_id, $form_id, $location ) {
		$contact_id = (string) $contact_id;
		$form_id    = (int) $form_id;

		if ( '' === $contact_id || ! $form_id ) {
			return;
		}

		$binding = aqm_ghl_get_opportunity_binding_for_form( $form_id );
		if ( empty( $binding['enabled'] ) || empty( $binding['pipeline_id'] ) || empty( $binding['stage_id'] ) ) {
			return;
		}

		$token       = isset( $location['private_token'] ) ? (string) $location['private_token'] : '';
		$location_id = isset( $location['location_id'] )   ? (string) $location['location_id']   : '';
		if ( '' === $token || '' === $location_id ) {
			aqm_ghl_log(
				'Opportunity push skipped: missing token or location_id from auth router.',
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
			return;
		}

		// Resolve template tokens from the entry data + form metadata.
		$tokens = $this->build_token_data( $entry_id, $form_id );
		$name   = $this->render_template( $binding['name_template'], $tokens );
		if ( '' === $name ) {
			$name = $tokens['form_name'] ?: 'Opportunity';
		}

		$payload = array(
			'locationId'      => $location_id,
			'pipelineId'      => $binding['pipeline_id'],
			'pipelineStageId' => $binding['stage_id'],
			'name'            => $name,
			'status'          => $binding['status'] ?: 'open',
			'contactId'       => $contact_id,
			'source'          => $tokens['form_name'] ?: 'WordPress',
		);

		if ( '' !== $binding['monetary_value'] ) {
			$payload['monetaryValue'] = (float) $binding['monetary_value'];
		}

		$result = aqm_ghl_create_opportunity( $payload, $token );

		if ( is_wp_error( $result ) ) {
			aqm_ghl_log(
				'Opportunity creation failed (transport error).',
				array(
					'entry_id'    => $entry_id,
					'form_id'     => $form_id,
					'contact_id'  => $contact_id,
					'pipeline_id' => $binding['pipeline_id'],
					'stage_id'    => $binding['stage_id'],
					'error'       => $result->get_error_message(),
				)
			);
			return;
		}

		$code = isset( $result['status'] ) ? (int) $result['status'] : 0;
		$ok   = ( $code >= 200 && $code < 300 );
		aqm_ghl_log(
			$ok ? 'Opportunity created successfully.' : 'Opportunity creation returned non-2xx.',
			array(
				'entry_id'    => $entry_id,
				'form_id'     => $form_id,
				'contact_id'  => $contact_id,
				'pipeline_id' => $binding['pipeline_id'],
				'stage_id'    => $binding['stage_id'],
				'status'      => $code,
				'body'        => isset( $result['body'] ) ? mb_substr( (string) $result['body'], 0, 500 ) : '',
			)
		);
	}

	/**
	 * Build the token dictionary used for name template substitution. Pulls
	 * mapped Formidable values (first/last/email/phone) plus form + site
	 * metadata.
	 *
	 * @param int $entry_id Formidable entry ID.
	 * @param int $form_id  Formidable form ID.
	 *
	 * @return array<string,string>
	 */
	private function build_token_data( $entry_id, $form_id ) {
		$data = array(
			'first_name' => '',
			'last_name'  => '',
			'email'      => '',
			'phone'      => '',
			'form_id'    => (string) $form_id,
			'entry_id'   => (string) $entry_id,
			'form_name'  => '',
			'site_name'  => get_bloginfo( 'name' ),
		);

		if ( class_exists( 'FrmEntry' ) ) {
			$entry = FrmEntry::getOne( $entry_id, true );
			if ( $entry && ! empty( $entry->metas ) && is_array( $entry->metas ) ) {
				$settings = aqm_ghl_get_settings();
				$mapping  = isset( $settings['mapping'][ $form_id ] ) ? $settings['mapping'][ $form_id ] : array();
				foreach ( array( 'first_name', 'last_name', 'email', 'phone' ) as $key ) {
					$frm_id = isset( $mapping[ $key ] ) ? absint( $mapping[ $key ] ) : 0;
					if ( ! $frm_id || ! isset( $entry->metas[ $frm_id ] ) ) {
						continue;
					}
					$val = $entry->metas[ $frm_id ];
					if ( is_array( $val ) ) {
						$val = reset( $val );
					}
					$data[ $key ] = trim( (string) $val );
				}
			}
		}

		if ( class_exists( 'FrmForm' ) ) {
			$form = FrmForm::getOne( $form_id );
			if ( $form && isset( $form->name ) ) {
				$data['form_name'] = (string) $form->name;
			}
		}

		return $data;
	}

	/**
	 * Substitute {token} placeholders in a string with values from $tokens.
	 * Unknown tokens are stripped. Trailing separators ("— ", " - ", etc.)
	 * left dangling by empty tokens get cleaned up.
	 *
	 * @param string $template Template containing {token} placeholders.
	 * @param array  $tokens   Token → value map.
	 *
	 * @return string
	 */
	private function render_template( $template, $tokens ) {
		$out = (string) $template;
		foreach ( $tokens as $key => $val ) {
			$out = str_replace( '{' . $key . '}', (string) $val, $out );
		}
		// Strip any unmatched {something} tokens.
		$out = preg_replace( '/\{[^}]+\}/', '', $out );
		// Clean up dangling separators (e.g. "  — " left over from missing names).
		$out = preg_replace( '/\s+/u', ' ', $out );
		$out = trim( $out, " \t\n\r\0\x0B-—|·:" );
		return $out;
	}
}
