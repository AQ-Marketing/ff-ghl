(function ($) {
	'use strict';

	const settings = window.aqmGhlSettings || {};
	const mappingFields = ['email', 'phone', 'first_name', 'last_name'];
	const endpoint = 'https://services.leadconnectorhq.com/contacts/';
	const formsList = settings.forms || [];
	let formFieldsCache = {};
	let ghlCustomFields = Array.isArray(settings.ghlFields) ? settings.ghlFields : [];

	// Names that are handled by the core mapping section — never auto-map these.
	const coreFieldNames = ['email', 'phone', 'phonenumber', 'firstname', 'first name',
		'lastname', 'last name', 'fullname', 'full name', 'name'];

	function normalize(str) {
		return (str || '').toLowerCase().replace(/[_\-\s]+/g, '');
	}

	function isCoreField(name) {
		return coreFieldNames.indexOf(normalize(name)) !== -1;
	}

	/**
	 * Find the best matching Formidable field for a GHL custom field.
	 * Matches against name and fieldKey (e.g. "contact.utm_source" → "utm_source").
	 * Returns the Formidable field id or '' if no match.
	 */
	function findMatchingFormField(ghlField, formFields) {
		if (!formFields.length) return '';

		// Build candidate search terms from the GHL field.
		var terms = [];
		if (ghlField.name) terms.push(normalize(ghlField.name));
		if (ghlField.fieldKey) {
			// fieldKey is like "contact.utm_source" — use the part after the dot.
			var parts = ghlField.fieldKey.split('.');
			var keyPart = parts[parts.length - 1];
			terms.push(normalize(keyPart));
		}

		// Exact normalized match against any term.
		for (var t = 0; t < terms.length; t++) {
			if (!terms[t]) continue;
			for (var i = 0; i < formFields.length; i++) {
				if (normalize(formFields[i].label) === terms[t]) return formFields[i].id;
			}
		}
		// Substring match: one contains the other.
		for (var t = 0; t < terms.length; t++) {
			if (!terms[t]) continue;
			for (var i = 0; i < formFields.length; i++) {
				var fl = normalize(formFields[i].label);
				if (fl && (fl.indexOf(terms[t]) !== -1 || terms[t].indexOf(fl) !== -1)) return formFields[i].id;
			}
		}
		return '';
	}

	function setSelectOptions($select, fields, selected) {
		$select.empty();
		$select.append(new Option(settings.labels.select, '', false, false));

		fields.forEach((field) => {
			const isSelected = selected && parseInt(selected, 10) === parseInt(field.id, 10);
			$select.append(new Option(field.label, field.id, isSelected, isSelected));
		});
	}

	function renderMapping(fields) {
		mappingFields.forEach((key) => {
			const selected = settings.mapping && settings.mapping[key] ? settings.mapping[key] : '';
			setSelectOptions($(`#aqm-ghl-map-${key.replace('_', '-')}`), fields, selected);
		});
	}

	function buildGhlFieldControl(nameAttr, selectedId) {
		if (ghlCustomFields.length > 0) {
			const $select = $('<select>')
				.attr('name', nameAttr)
				.addClass('regular-text aqm-ghl-custom-ghl-select');
			$select.append(new Option(settings.labels.selectGhl || 'Select a GHL field', '', false, false));
			ghlCustomFields.forEach(function (cf) {
				const key = cf.fieldKey ? '{{ ' + cf.fieldKey + ' }}' : cf.id;
				const label = cf.name + ' — ' + key;
				const isSelected = selectedId && selectedId === cf.id;
				$select.append(new Option(label, cf.id, isSelected, isSelected));
			});
			return $select;
		}
		const escaped = selectedId ? $('<div>').text(selectedId).html() : '';
		return $('<input>')
			.attr('type', 'text')
			.attr('name', nameAttr)
			.attr('placeholder', 'GHL Custom Field ID')
			.val(escaped)
			.addClass('regular-text aqm-ghl-custom-ghl');
	}

	function addCustomFieldRow(formId, container, data, fields) {
		const formIdInt = parseInt(formId, 10);
		const ghlFieldId = data && data.ghl_field_id ? data.ghl_field_id : '';
		const formFieldId = data && data.form_field_id ? data.form_field_id : '';
		const optKey = settings.optionKey || 'aqm_ghl_connector_settings';

		const row = $('<div class="aqm-ghl-custom-field-row"></div>');
		const ghlControl = buildGhlFieldControl(
			optKey + '[custom_fields][' + formIdInt + '][][ghl_field_id]',
			ghlFieldId
		);
		const formSelect = $('<select>')
			.attr('name', optKey + '[custom_fields][' + formIdInt + '][][form_field_id]')
			.addClass('regular-text aqm-ghl-custom-select');
		const removeBtn = $('<button type="button" class="button-link-delete aqm-ghl-remove-custom-field">Remove</button>');

		row.append(ghlControl).append(formSelect).append(removeBtn);
		setSelectOptions(formSelect, fields, formFieldId);

		row.on('click', '.aqm-ghl-remove-custom-field', function (e) {
			e.preventDefault();
			row.remove();
		});

		container.find('.aqm-ghl-custom-fields').append(row);
	}

	function renderCustomFields(formId, container, fields) {
		const formIdInt = parseInt(formId, 10);
		container.find('.aqm-ghl-custom-fields').empty();
		const existingByForm = settings.customFields || {};
		const existing = existingByForm[formIdInt] || [];

		// Build a set of GHL field IDs that already have a saved mapping.
		const mappedGhlIds = new Set();
		existing.forEach(function (field) {
			if (field.ghl_field_id) mappedGhlIds.add(field.ghl_field_id);
		});

		// Render saved mappings first.
		existing.forEach((field) => addCustomFieldRow(formId, container, field, fields));

		// Auto-map: for each GHL custom field NOT already mapped, try to match.
		if (ghlCustomFields.length > 0) {
			let added = 0;
			ghlCustomFields.forEach(function (cf) {
				if (mappedGhlIds.has(cf.id)) return;   // already mapped
				if (isCoreField(cf.name)) return;      // handled by core mapping
				const matchedFormField = findMatchingFormField(cf, fields);
				addCustomFieldRow(formId, container, {
					ghl_field_id: cf.id,
					form_field_id: matchedFormField,
				}, fields);
				added++;
			});
			if (added > 0) {
				console.log('[AQM GHL] Auto-mapped ' + added + ' GHL fields for form ' + formIdInt);
			}
		}

		// Always show at least one empty row if nothing was rendered.
		if (container.find('.aqm-ghl-custom-field-row').length === 0) {
			addCustomFieldRow(formId, container, null, fields);
		}
	}

	function loadFields(formId) {
		const formIdInt = parseInt(formId, 10);
		if (formFieldsCache[formIdInt]) {
			return Promise.resolve(formFieldsCache[formIdInt]);
		}

		const data = new URLSearchParams();
		data.append('action', 'aqm_ghl_get_form_fields');
		data.append('nonce', settings.nonce);
		data.append('form_id', formIdInt);

		return fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: data.toString(),
		})
			.then((response) => response.json())
			.then((json) => {
				if (!json || !json.success || !json.data || !Array.isArray(json.data.fields)) {
					throw new Error('Unable to load fields');
				}
				formFieldsCache[formIdInt] = json.data.fields;
				return json.data.fields;
			});
	}

	function buildMappingContainer(formId) {
		const formIdInt = parseInt(formId, 10);
		const form = formsList.find((f) => parseInt(f.id, 10) === formIdInt);
		const formName = form ? form.name : `Form ${formId}`;
		const mappingByForm = settings.mapping || {};
		const customByForm = settings.customFields || {};
		// Use integer key (normalized from PHP)
		const existingMap = mappingByForm[formIdInt] || {};

		const container = $(`
			<div class="aqm-ghl-form-block" data-form-id="${formIdInt}">
				<h3>${formName}</h3>
				<div class="aqm-ghl-mapping-rows">
					<label>Email (required)
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][email]" class="regular-text aqm-ghl-field-select" data-map-key="email"></select>
					</label>
					<label>Phone (optional)
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][phone]" class="regular-text aqm-ghl-field-select" data-map-key="phone"></select>
					</label>
					<label>First Name
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][first_name]" class="regular-text aqm-ghl-field-select" data-map-key="first_name"></select>
					</label>
					<label>Last Name
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][last_name]" class="regular-text aqm-ghl-field-select" data-map-key="last_name"></select>
					</label>
				</div>
				<h4>Custom Fields</h4>
				<div class="aqm-ghl-custom-fields"></div>
				<p><button type="button" class="button aqm-ghl-add-custom-field" data-form-id="${formIdInt}">Add Custom Field</button></p>
			</div>
		`);

		loadFields(formIdInt)
			.then((fields) => {
				console.log(`[AQM GHL] Loading fields for form ${formIdInt}, existing map:`, existingMap);
				container.find('select.aqm-ghl-field-select').each(function () {
					const key = $(this).data('map-key');
					const selected = existingMap && existingMap[key] ? existingMap[key] : '';
					console.log(`[AQM GHL] Setting ${key} to ${selected} for form ${formIdInt}`);
					setSelectOptions($(this), fields, selected);
				});
				renderCustomFields(formIdInt, container, fields);
			})
			.catch(() => {
				container.find('select.aqm-ghl-field-select').each(function () {
					setSelectOptions($(this), [], '');
				});
				renderCustomFields(formIdInt, container, []);
			});

		container.on('click', '.aqm-ghl-add-custom-field', function (e) {
			e.preventDefault();
			const fields = formFieldsCache[formIdInt] || [];
			addCustomFieldRow(formIdInt, container, null, fields);
		});

		container.on('click', '.aqm-ghl-remove-custom-field', function (e) {
			e.preventDefault();
			$(this).closest('.aqm-ghl-custom-field-row').remove();
		});

		return container;
	}

	$(function () {
		const $formCheckboxes = $('.aqm-ghl-form-checkbox');
		const $testButton = $('#aqm-ghl-test-connection');
		const $testResult = $('#aqm-ghl-test-result');
		const $mappingContainers = $('#aqm-ghl-form-mapping-containers');

		console.info('[AQM GHL] Admin script initialized', {
			hasTestButton: !!$testButton.length,
			page: window.location.href,
			forms: settings.selectedForms || [],
		});

		// Store existing blocks to preserve settings
		const existingBlocks = {};

		function getSelectedFormIds() {
			return $formCheckboxes.filter(':checked').map(function() {
				return parseInt($(this).val(), 10);
			}).get();
		}

		function refreshFormBlocks() {
			const selected = getSelectedFormIds();
			const selectedSet = new Set(selected);
			
			// Hide and remove blocks for unchecked forms
			Object.keys(existingBlocks).forEach((fid) => {
				const fidInt = parseInt(fid, 10);
				if (!selectedSet.has(fidInt)) {
					// Form is unchecked - hide and remove the block
					const block = existingBlocks[fidInt];
					if (block && block.length) {
						block.hide();
						block.remove();
						delete existingBlocks[fidInt];
					}
				}
			});
			
			// Show or create blocks for selected forms
			selected.forEach((fid) => {
				const fidInt = parseInt(fid, 10);
				let block = existingBlocks[fidInt];
				if (!block || !block.length) {
					// Create new block if it doesn't exist
					block = buildMappingContainer(fidInt);
					existingBlocks[fidInt] = block;
					$mappingContainers.append(block);
				} else {
					// Show existing block
					block.show();
				}
			});
		}

		// Handle checkbox changes using event delegation
		$(document).on('change', '.aqm-ghl-form-checkbox', function () {
			refreshFormBlocks();
		});

		// Initial load - build blocks for all selected forms and call refresh to ensure proper state
		refreshFormBlocks();

		$testButton.on('click', function (e) {
			e.preventDefault();
			if ($testButton.prop('disabled')) {
				return;
			}

			console.log('[AQM GHL] Sending test contact...', {
				ajaxUrl: settings.ajaxUrl,
				endpoint,
			});

			$testResult.hide().removeClass('notice-success notice-error').text('');
			$testButton.prop('disabled', true).text('Sending...');

			const data = new URLSearchParams();
			data.append('action', 'aqm_ghl_test_connection');
			data.append('nonce', settings.nonce);

			fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: data.toString(),
			})
				.then(async (response) => {
					console.log('[AQM GHL] Test response received', { status: response.status });
					let json;
					try {
						json = await response.json();
					} catch (err) {
						console.warn('[AQM GHL] Response was not JSON; reading as text', err);
						const text = await response.text();
						return {
							success: false,
							data: {
								message: 'Unexpected response from server.',
								status: response.status,
								response_body: text,
							},
						};
					}
					return json;
				})
				.then((json) => {
					console.log('[AQM GHL] Parsed test result', json);
					const payload = json && json.data ? json.data.payload : null;
					const responseBody = json && json.data ? json.data.response_body : null;
					const status = json && json.data ? json.data.status : null;

					let detail = '';
					if (payload) {
						detail += `<p><strong>Request URL:</strong> ${endpoint}</p>`;
						detail += `<p><strong>Request Payload:</strong></p><pre>${JSON.stringify(payload, null, 2)}</pre>`;
					}
					if (status) {
						detail += `<p><strong>Status:</strong> ${status}</p>`;
					}
					if (responseBody) {
						detail += `<p><strong>Response Body:</strong></p><pre>${responseBody}</pre>`;
					}

					if (json && json.success) {
						console.log('[AQM GHL] Test contact succeeded');
						$testResult
							.addClass('notice-success')
							.removeClass('notice-error')
							.html((json.data.message || 'Success') + detail)
							.show();
					} else {
						const msg = (json && json.data && json.data.message) ? json.data.message : 'Test failed.';
						console.error('[AQM GHL] Test contact failed', { message: msg, json });
						$testResult
							.addClass('notice-error')
							.removeClass('notice-success')
							.html(msg + detail)
							.show();
					}
				})
				.catch((err) => {
					console.error('[AQM GHL] Test contact request failed', err);
					$testResult.addClass('notice-error').removeClass('notice-success').text('Test failed. Please check console/network.').show();
				})
				.finally(() => {
					$testButton.prop('disabled', false).text('Send Test Contact');
				});
		});

		// Handle refresh GHL custom fields button
		$('#aqm-ghl-fetch-ghl-fields').on('click', function (e) {
			e.preventDefault();
			const $button = $(this);
			const $result = $('#aqm-ghl-fetch-result');

			if ($button.prop('disabled')) {
				return;
			}

			$result.hide().removeClass('aqm-ghl-fetch-success aqm-ghl-fetch-error').text('');
			$button.prop('disabled', true).text('Refreshing...');

			fetchGhlFields()
				.then(function (count) {
					$result
						.addClass('aqm-ghl-fetch-success')
						.removeClass('aqm-ghl-fetch-error')
						.text('Loaded ' + count + ' GHL fields. Auto-mapped to forms.')
						.show();
				})
				.catch(function (msg) {
					$result
						.addClass('aqm-ghl-fetch-error')
						.removeClass('aqm-ghl-fetch-success')
						.text(msg)
						.show();
				})
				.finally(function () {
					$button.prop('disabled', false).text('Refresh GHL Fields');
				});
		});

		/**
		 * Fetch GHL fields from API, update ghlCustomFields, and rebuild form blocks.
		 * Returns a promise that resolves with the field count.
		 */
		function fetchGhlFields() {
			const data = new URLSearchParams();
			data.append('action', 'aqm_ghl_fetch_ghl_fields');
			data.append('nonce', settings.nonce);

			return fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: data.toString(),
			})
				.then(function (response) { return response.json(); })
				.then(function (json) {
					if (json && json.success && Array.isArray(json.data.fields)) {
						ghlCustomFields = json.data.fields;
						rebuildAllCustomFieldSections();
						return ghlCustomFields.length;
					}
					throw (json && json.data && json.data.message) ? json.data.message : 'Failed to fetch GHL fields.';
				});
		}

		/**
		 * Re-render the custom-fields section of every visible form block
		 * so auto-mapping runs against the latest GHL field list.
		 */
		function rebuildAllCustomFieldSections() {
			$('.aqm-ghl-form-block').each(function () {
				const $block = $(this);
				const formIdInt = parseInt($block.data('form-id'), 10);
				const formFields = formFieldsCache[formIdInt] || [];
				renderCustomFields(formIdInt, $block, formFields);
			});
		}

		// Handle provision fields button
		$('#aqm-ghl-provision-fields').on('click', function(e) {
			e.preventDefault();
			const $button = $(this);
			const $result = $('#aqm-ghl-provision-result');
			
			if ($button.prop('disabled')) {
				return;
			}

			$result.hide().removeClass('notice-success notice-error').text('');
			$button.prop('disabled', true).text('Provisioning...');

			const data = new URLSearchParams();
			data.append('action', 'aqm_ghl_provision_fields');
			data.append('nonce', settings.nonce);

			fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: data.toString(),
			})
				.then(async (response) => {
					let json;
					try {
						json = await response.json();
					} catch (err) {
						return {
							success: false,
							data: {
								message: 'Unexpected response from server.',
							},
						};
					}
					return json;
				})
				.then((json) => {
					if (json && json.success) {
						let message = json.data.message || 'Fields provisioned successfully.';
						if (json.data.field_count) {
							message += ' (' + json.data.field_count + ' fields)';
						}
						$result
							.addClass('notice-success')
							.removeClass('notice-error')
							.html(message)
							.show();
					} else {
						const msg = (json && json.data && json.data.message) ? json.data.message : 'Provisioning failed.';
						$result
							.addClass('notice-error')
							.removeClass('notice-success')
							.html(msg)
							.show();
					}
				})
				.catch((err) => {
					console.error('[AQM GHL] Provision fields request failed', err);
					$result.addClass('notice-error').removeClass('notice-success').text('Request failed. Please check console/network.').show();
				})
				.finally(() => {
					$button.prop('disabled', false).text('Refresh/Provision Custom Fields');
				});
		});
	});
})(jQuery);


