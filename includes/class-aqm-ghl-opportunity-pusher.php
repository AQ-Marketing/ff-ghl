<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates an opportunity in GHL for every Formidable submission, linked to
 * the newly-created contact. Listens on the `aqm_ghl_contact_created` action
 * fired by AQM_GHL_Handler.
 *
 * No per-form config — auto-picks the FIRST pipeline + FIRST stage in the
 * sub-account. Agency manages the opportunity from there (move stages,
 * change status, etc.).
 *
 * Opportunity name format: "{first_name} {last_name} — {form_name}"
 * Status: open
 *
 * Requires the auth token to have opportunities.write + opportunities.readonly.
 */
class AQM_GHL_Opportunity_Pusher {

	public function __construct() {
		add_action( 'aqm_ghl_contact_created', array( $this, 'create_opportunity' ), 20, 4 );
	}

	/**
	 * @param string $contact_id GHL contact ID.
	 * @param int    $entry_id   Formidable entry ID.
	 * @param int    $form_id    Formidable form ID.
	 * @param array  $location   { location_id, private_token } — resolved by the auth router.
	 */
	public function create_opportunity( $contact_id, $entry_id, $form_id, $location ) {
		$contact_id  = (string) $contact_id;
		$form_id     = (int) $form_id;
		$token       = isset( $location['private_token'] ) ? (string) $location['private_token'] : '';
		$location_id = isset( $location['location_id'] )   ? (string) $location['location_id']   : '';

		if ( '' === $contact_id || ! $form_id || '' === $token || '' === $location_id ) {
			return;
		}

		// Auto-discover first pipeline + first stage. Cached for an hour so
		// every submission doesn't hit GHL.
		$pipelines = aqm_ghl_get_cached_pipelines( $location_id );
		if ( empty( $pipelines ) ) {
			$fetched = aqm_ghl_fetch_pipelines( $location_id, $token, true );
			if ( is_wp_error( $fetched ) ) {
				aqm_ghl_log(
					'Opportunity push skipped: could not load pipelines.',
					array(
						'entry_id'   => $entry_id,
						'form_id'    => $form_id,
						'contact_id' => $contact_id,
						'error'      => $fetched->get_error_message(),
					)
				);
				return;
			}
			$pipelines = $fetched;
		}

		if ( empty( $pipelines ) || empty( $pipelines[0]['id'] ) || empty( $pipelines[0]['stages'][0]['id'] ) ) {
			aqm_ghl_log(
				'Opportunity push skipped: sub-account has no pipelines with stages set up.',
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
			return;
		}

		$pipeline    = $pipelines[0];
		$pipeline_id = (string) $pipeline['id'];
		$stage_id    = (string) $pipeline['stages'][0]['id'];

		// Build the opportunity name from the entry data.
		$tokens = $this->build_token_data( $entry_id, $form_id );
		$name   = trim( sprintf( '%s %s', $tokens['first_name'], $tokens['last_name'] ) );
		if ( '' === $name ) {
			$name = $tokens['email'] ?: ( $tokens['form_name'] ?: 'New Lead' );
		}
		if ( '' !== $tokens['form_name'] ) {
			$name .= ' — ' . $tokens['form_name'];
		}

		$payload = array(
			'locationId'      => $location_id,
			'pipelineId'      => $pipeline_id,
			'pipelineStageId' => $stage_id,
			'name'            => $name,
			'status'          => 'open',
			'contactId'       => $contact_id,
			'source'          => $tokens['form_name'] ?: 'WordPress',
		);

		$result = aqm_ghl_create_opportunity( $payload, $token );

		if ( is_wp_error( $result ) ) {
			aqm_ghl_log(
				'Opportunity creation failed (transport error).',
				array(
					'entry_id'    => $entry_id,
					'form_id'     => $form_id,
					'contact_id'  => $contact_id,
					'pipeline_id' => $pipeline_id,
					'stage_id'    => $stage_id,
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
				'pipeline_id' => $pipeline_id,
				'stage_id'    => $stage_id,
				'status'      => $code,
				'body'        => isset( $result['body'] ) ? mb_substr( (string) $result['body'], 0, 500 ) : '',
			)
		);
	}

	/**
	 * Pull first_name / last_name / email / phone / form_name from the entry.
	 *
	 * @return array<string,string>
	 */
	private function build_token_data( $entry_id, $form_id ) {
		$data = array(
			'first_name' => '',
			'last_name'  => '',
			'email'      => '',
			'phone'      => '',
			'form_name'  => '',
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
}
