<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forwards Formidable Forms submissions to a per-form GHL Workflow Inbound
 * Webhook URL. Each WordPress form can be bound to its own GHL workflow so
 * the workflow editor handles routing (create contact, tag, pipeline, etc.).
 *
 * Runs on `frm_after_create_entry` at priority 30 — AFTER AQM_GHL_Handler
 * (priority 20) creates the contact, so any workflow that looks up the
 * contact by email finds it already present.
 *
 * Why a webhook instead of GHL's native form-submit endpoint? GHL's
 * /forms/submit endpoint requires a reCAPTCHA Enterprise v3 token that's
 * cryptographically tied to a real browser session — impossible to generate
 * server-side. The Inbound Webhook trigger is GHL's officially supported
 * path for external systems and has no captcha.
 */
class AQM_GHL_Form_Submitter {

	const HOOK_PRIORITY = 30;

	public function __construct() {
		add_action( 'frm_after_create_entry', array( $this, 'maybe_forward_to_webhook' ), self::HOOK_PRIORITY, 2 );
	}

	/**
	 * Build a JSON payload from the entry and POST it to the configured
	 * webhook URL. Silent no-op when the form is unconfigured or disabled —
	 * never fatal, never blocks Formidable's own success flow.
	 *
	 * @param int $entry_id Formidable entry ID.
	 * @param int $form_id  Formidable form ID.
	 */
	public function maybe_forward_to_webhook( $entry_id, $form_id ) {
		if ( ! empty( $_POST['frm_saving_draft'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! class_exists( 'FrmEntry' ) ) {
			return;
		}

		$form_id  = absint( $form_id );
		$entry_id = absint( $entry_id );
		if ( ! $form_id || ! $entry_id ) {
			return;
		}

		$binding = aqm_ghl_get_webhook_binding_for_form( $form_id );
		if ( empty( $binding['enabled'] ) || empty( $binding['webhook_url'] ) ) {
			return;
		}

		$entry = FrmEntry::getOne( $entry_id, true );
		if ( ! $entry ) {
			aqm_ghl_log(
				'Webhook forward aborted: entry could not be loaded.',
				array( 'entry_id' => $entry_id, 'form_id' => $form_id )
			);
			return;
		}

		$payload = $this->build_payload( $entry, $form_id );
		$result  = aqm_ghl_post_to_webhook( $binding['webhook_url'], $payload );

		if ( is_wp_error( $result ) ) {
			aqm_ghl_log(
				'Webhook forward failed (transport error).',
				array(
					'entry_id' => $entry_id,
					'form_id'  => $form_id,
					'error'    => $result->get_error_message(),
				)
			);
			return;
		}

		$code = isset( $result['status'] ) ? (int) $result['status'] : 0;
		aqm_ghl_log(
			( $code >= 200 && $code < 300 ) ? 'Webhook forward succeeded.' : 'Webhook forward returned non-2xx.',
			array(
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'status'   => $code,
				'body'     => isset( $result['body'] ) ? mb_substr( (string) $result['body'], 0, 500 ) : '',
			)
		);
	}

	/**
	 * Assemble the JSON payload sent to GHL.
	 *
	 * Shape:
	 *   {
	 *     entry_id, form_id, form_name, submitted_at, source_url, site_url, ip,
	 *     fields: { "Field Label": "value", ... },
	 *     field_keys: { "field_key": "value", ... },   // when keys exist on the form
	 *     utm: { gclid, utm_source, utm_medium, utm_campaign, utm_term, utm_content }
	 *   }
	 *
	 * The GHL workflow editor maps these JSON keys to contact fields, custom
	 * fields, tags, pipeline stages, etc.
	 *
	 * @param object $entry   Formidable entry object (from FrmEntry::getOne).
	 * @param int    $form_id Formidable form ID.
	 *
	 * @return array
	 */
	private function build_payload( $entry, $form_id ) {
		$metas      = is_array( $entry->metas ) ? $entry->metas : array();
		$frm_fields = class_exists( 'FrmField' ) ? FrmField::getAll( array( 'fi.form_id' => $form_id ) ) : array();

		$fields_by_label = array();
		$fields_by_key   = array();
		$skip_types      = array( 'divider', 'html', 'break', 'captcha', 'end_divider', 'submit' );

		foreach ( (array) $frm_fields as $field ) {
			if ( empty( $field->id ) || ( isset( $field->type ) && in_array( $field->type, $skip_types, true ) ) ) {
				continue;
			}

			$raw = isset( $metas[ $field->id ] ) ? $metas[ $field->id ] : null;
			$val = $this->stringify_value( $raw );
			if ( '' === $val ) {
				continue;
			}

			$label = isset( $field->name ) ? (string) $field->name : '';
			$key   = isset( $field->field_key ) ? (string) $field->field_key : '';

			if ( '' !== $label ) {
				$fields_by_label[ $label ] = $val;
			}
			if ( '' !== $key ) {
				$fields_by_key[ $key ] = $val;
			}
		}

		$form_name = '';
		if ( class_exists( 'FrmForm' ) ) {
			$form = FrmForm::getOne( $form_id );
			if ( $form && isset( $form->name ) ) {
				$form_name = (string) $form->name;
			}
		}

		$utm = array();
		// AQM_GHL_UTM_Tracker is instantiated by AQM_GHL_Handler, but we may
		// run after its scope; pull fresh from the global tracker if present.
		if ( class_exists( 'AQM_GHL_UTM_Tracker' ) ) {
			$tracker = new AQM_GHL_UTM_Tracker();
			$params  = $tracker->get_tracked_parameters();
			if ( is_array( $params ) ) {
				foreach ( $params as $k => $v ) {
					if ( '' !== (string) $v ) {
						$utm[ $k ] = sanitize_text_field( (string) $v );
					}
				}
			}
		}

		return array(
			'entry_id'     => (int) $entry->id,
			'form_id'      => (int) $form_id,
			'form_name'    => $form_name,
			'submitted_at' => isset( $entry->created_at ) ? (string) $entry->created_at : current_time( 'mysql' ),
			'source_url'   => $this->detect_source_url(),
			'site_url'     => home_url(),
			'ip'           => isset( $entry->ip ) ? (string) $entry->ip : '',
			'fields'       => $fields_by_label,
			'field_keys'   => $fields_by_key,
			'utm'          => $utm,
		);
	}

	/**
	 * Coerce a Formidable meta value into a single-line string. Arrays
	 * (checkbox/multi-select/address/name) are flattened with ", ". Numeric
	 * values that look like attachment IDs are converted to URLs.
	 *
	 * @param mixed $value Raw meta value.
	 *
	 * @return string
	 */
	private function stringify_value( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $item ) {
				$piece = $this->stringify_value( $item );
				if ( '' !== $piece ) {
					$flat[] = $piece;
				}
			}
			return implode( ', ', $flat );
		}
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( (int) $value );
			if ( $url ) {
				return esc_url_raw( $url );
			}
			return (string) $value;
		}
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		return '';
	}

	/**
	 * Best-effort source URL of the page that hosted the form.
	 *
	 * @return string
	 */
	private function detect_source_url() {
		$referer = wp_get_referer();
		if ( $referer ) {
			return esc_url_raw( $referer );
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		return '';
	}
}
