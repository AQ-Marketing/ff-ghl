(function ($) {
	'use strict';

	const settings = window.aqmGhlSettings || {};
	const endpoint = 'https://services.leadconnectorhq.com/contacts/';

	// Safe HTML escape for any untrusted strings we interpolate into markup.
	function escHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	$(function () {
		const $testButton = $('#aqm-ghl-test-connection');
		const $testResult = $('#aqm-ghl-test-result');

		if ($testButton.length) {
			$testButton.on('click', function (e) {
				e.preventDefault();
				if ($testButton.prop('disabled')) {
					return;
				}

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
						let json;
						try {
							json = await response.json();
						} catch (err) {
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
						const payload = json && json.data ? json.data.payload : null;
						const responseBody = json && json.data ? json.data.response_body : null;
						const status = json && json.data ? json.data.status : null;

						let detail = '';
						if (payload) {
							detail += '<p><strong>Request URL:</strong> ' + escHtml(endpoint) + '</p>';
							detail += '<p><strong>Request Payload:</strong></p><pre>' + escHtml(JSON.stringify(payload, null, 2)) + '</pre>';
						}
						if (status) {
							detail += '<p><strong>Status:</strong> ' + escHtml(status) + '</p>';
						}
						if (responseBody) {
							detail += '<p><strong>Response Body:</strong></p><pre>' + escHtml(responseBody) + '</pre>';
						}

						if (json && json.success) {
							$testResult
								.addClass('notice-success')
								.removeClass('notice-error')
								.html(escHtml(json.data.message || 'Success') + detail)
								.show();
						} else {
							const msg = (json && json.data && json.data.message) ? json.data.message : 'Test failed.';
							$testResult
								.addClass('notice-error')
								.removeClass('notice-success')
								.html(escHtml(msg) + detail)
								.show();
						}
					})
					.catch(() => {
						$testResult.addClass('notice-error').removeClass('notice-success').text('Test failed. Please check console/network.').show();
					})
					.finally(() => {
						$testButton.prop('disabled', false).text('Send Test Contact');
					});
			});
		}

		// Clear plugin-update cache so a freshly published release shows up.
		const $cacheButton = $('#aqm-ghl-clear-cache');
		const $cacheResult = $('#aqm-ghl-cache-result');

		if ($cacheButton.length) {
			$cacheButton.on('click', function (e) {
				e.preventDefault();
				if ($cacheButton.prop('disabled')) {
					return;
				}

				$cacheResult.hide().removeClass('notice-success notice-error').text('');
				$cacheButton.prop('disabled', true).text('Clearing...');

				const data = new URLSearchParams();
				data.append('action', 'aqm_ghl_clear_update_cache');
				data.append('nonce', settings.nonce);

				fetch(settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: data.toString(),
				})
					.then((response) => response.json())
					.then((json) => {
						const ok = !!(json && json.success);
						const msg = (json && json.data && json.data.message) ? json.data.message : (ok ? 'Update cache cleared.' : 'Failed to clear cache.');
						$cacheResult
							.addClass(ok ? 'notice-success' : 'notice-error')
							.removeClass(ok ? 'notice-error' : 'notice-success')
							.text(msg)
							.show();
					})
					.catch(() => {
						$cacheResult.addClass('notice-error').removeClass('notice-success').text('Request failed. Please check console/network.').show();
					})
					.finally(() => {
						$cacheButton.prop('disabled', false).text('Clear Update Cache');
					});
			});
		}

		// ---- Backfill / resend past submissions to GoHighLevel ----
		const $bfForm = $('#aqm-ghl-bf-form');
		if ($bfForm.length) {
			const $from = $('#aqm-ghl-bf-from');
			const $to = $('#aqm-ghl-bf-to');
			const $load = $('#aqm-ghl-bf-load');
			const $loadStatus = $('#aqm-ghl-bf-load-status');
			const $results = $('#aqm-ghl-bf-results');
			const $rows = $('#aqm-ghl-bf-rows');
			const $selectAll = $('#aqm-ghl-bf-select-all');
			const $push = $('#aqm-ghl-bf-push');
			const $pushStatus = $('#aqm-ghl-bf-push-status');
			const BATCH = 10;

			// POST helper: appends array params as key[]=value, parses JSON safely.
			function bfPost(action, params) {
				const data = new URLSearchParams();
				data.append('action', action);
				data.append('nonce', settings.nonce);
				Object.keys(params || {}).forEach((k) => {
					const v = params[k];
					if (Array.isArray(v)) {
						v.forEach((item) => data.append(k + '[]', item));
					} else {
						data.append(k, v);
					}
				});
				return fetch(settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: data.toString(),
				}).then(async (r) => {
					try { return await r.json(); } catch (err) { return { success: false, data: { message: 'Unexpected response from server.' } }; }
				});
			}

			function badge(status) {
				const map = {
					in_ghl: ['Already in GoHighLevel', 'aqm-ghl-bf-badge--in'],
					not_in_ghl: ['Not in GoHighLevel', 'aqm-ghl-bf-badge--out'],
					no_email: ['No email — can’t send', 'aqm-ghl-bf-badge--na'],
					error: ['Couldn’t check', 'aqm-ghl-bf-badge--err'],
					checking: ['Checking…', 'aqm-ghl-bf-badge--checking'],
				};
				const m = map[status] || map.checking;
				return '<span class="aqm-ghl-bf-badge ' + m[1] + '">' + escHtml(m[0]) + '</span>';
			}

			function updatePushEnabled() {
				$push.prop('disabled', $rows.find('input.aqm-ghl-bf-cb:checked').length === 0);
			}

			function renderRows(rows) {
				$rows.empty();
				if (!rows.length) {
					$rows.append('<tr><td colspan="6">' + escHtml('No submissions found in that date range.') + '</td></tr>');
					$selectAll.prop('disabled', true).prop('checked', false);
					return;
				}
				rows.forEach((row) => {
					const sendable = !!row.sendable;
					const cb = sendable
						? '<input type="checkbox" class="aqm-ghl-bf-cb" value="' + escHtml(row.entry_id) + '">'
						: '<input type="checkbox" disabled title="' + escHtml('Needs an email address to send') + '">';
					const statusCell = sendable ? badge('checking') : badge('no_email');
					$rows.append(
						'<tr data-entry="' + escHtml(row.entry_id) + '">' +
						'<td class="check-column">' + cb + '</td>' +
						'<td>' + escHtml(row.date) + '</td>' +
						'<td>' + escHtml(row.name || '—') + '</td>' +
						'<td>' + escHtml(row.email || '—') + '</td>' +
						'<td class="aqm-ghl-bf-status">' + statusCell + '</td>' +
						'<td class="aqm-ghl-bf-result"></td>' +
						'</tr>'
					);
				});
				$selectAll.prop('disabled', false).prop('checked', false);
			}

			$rows.on('change', 'input.aqm-ghl-bf-cb', updatePushEnabled);
			$selectAll.on('change', function () {
				const checked = $(this).prop('checked');
				$rows.find('input.aqm-ghl-bf-cb').prop('checked', checked);
				updatePushEnabled();
			});

			// After listing, check each sendable row against GHL in batches and
			// pre-select the ones that are missing (the common "push the missing" case).
			async function runChecks() {
				const ids = [];
				$rows.find('tr').each(function () {
					const $tr = $(this);
					if ($tr.find('input.aqm-ghl-bf-cb').length) { ids.push($tr.attr('data-entry')); }
				});
				for (let i = 0; i < ids.length; i += BATCH) {
					const batch = ids.slice(i, i + BATCH);
					$loadStatus.text('Checking GoHighLevel ' + Math.min(i + batch.length, ids.length) + ' of ' + ids.length + '…');
					const json = await bfPost('aqm_ghl_backfill_check', { form_id: $bfForm.val(), entry_ids: batch });
					if (json && json.success && json.data && json.data.results) {
						json.data.results.forEach((res) => {
							const $tr = $rows.find('tr[data-entry="' + res.entry_id + '"]');
							$tr.find('.aqm-ghl-bf-status').html(badge(res.status));
							if (res.status === 'not_in_ghl') { $tr.find('input.aqm-ghl-bf-cb').prop('checked', true); }
						});
						updatePushEnabled();
					}
				}
				$loadStatus.text(ids.length + ' sendable submission(s) checked. Missing ones are pre-selected — review and push.');
			}

			$load.on('click', function (e) {
				e.preventDefault();
				if ($load.prop('disabled')) { return; }
				$load.prop('disabled', true).text('Loading…');
				$loadStatus.text('');
				$push.prop('disabled', true);
				$pushStatus.text('');
				bfPost('aqm_ghl_backfill_list', { form_id: $bfForm.val(), from: $from.val(), to: $to.val() })
					.then((json) => {
						if (!json || !json.success) {
							const msg = (json && json.data && json.data.message) ? json.data.message : 'Could not load submissions.';
							$loadStatus.text(msg);
							$results.hide();
							return;
						}
						renderRows(json.data.rows || []);
						$results.show();
						let note = 'Found ' + json.data.total + ' submission(s).';
						if (json.data.total > json.data.returned) { note += ' Showing the most recent ' + json.data.returned + ' — narrow the date range to see older ones.'; }
						$loadStatus.text(note);
						return runChecks();
					})
					.catch(() => { $loadStatus.text('Request failed. Please check console/network.'); })
					.finally(() => { $load.prop('disabled', false).text('Load submissions'); });
			});

			$push.on('click', async function (e) {
				e.preventDefault();
				if ($push.prop('disabled')) { return; }
				const ids = [];
				$rows.find('input.aqm-ghl-bf-cb:checked').each(function () { ids.push($(this).val()); });
				if (!ids.length) { return; }
				$push.prop('disabled', true);
				$selectAll.prop('disabled', true);
				let sent = 0, skipped = 0, failed = 0;
				for (let i = 0; i < ids.length; i += BATCH) {
					const batch = ids.slice(i, i + BATCH);
					$pushStatus.text('Pushing ' + Math.min(i + batch.length, ids.length) + ' of ' + ids.length + '…');
					const json = await bfPost('aqm_ghl_backfill_push', { form_id: $bfForm.val(), entry_ids: batch });
					if (json && json.success && json.data && json.data.results) {
						json.data.results.forEach((res) => {
							const $tr = $rows.find('tr[data-entry="' + res.entry_id + '"]');
							let html, cls;
							if (res.outcome === 'sent') {
								sent++; html = '✓ ' + escHtml(res.message); cls = 'aqm-ghl-bf-ok';
								$tr.find('.aqm-ghl-bf-status').html(badge('in_ghl'));
							} else if (res.outcome === 'skipped') {
								skipped++; html = escHtml(res.message); cls = 'aqm-ghl-bf-skip';
								$tr.find('.aqm-ghl-bf-status').html(badge('in_ghl'));
							} else {
								failed++; html = '✗ ' + escHtml(res.message); cls = 'aqm-ghl-bf-fail';
							}
							$tr.find('.aqm-ghl-bf-result').html('<span class="' + cls + '">' + html + '</span>');
							$tr.find('input.aqm-ghl-bf-cb').prop('checked', false);
						});
					} else {
						const msg = (json && json.data && json.data.message) ? json.data.message : 'Batch failed.';
						batch.forEach((id) => {
							failed++;
							$rows.find('tr[data-entry="' + id + '"] .aqm-ghl-bf-result').html('<span class="aqm-ghl-bf-fail">✗ ' + escHtml(msg) + '</span>');
						});
					}
				}
				$pushStatus.text('Done — sent ' + sent + ', skipped ' + skipped + ', failed ' + failed + '.');
				$selectAll.prop('disabled', false).prop('checked', false);
				updatePushEnabled();
			});
		}
	});
})(jQuery);
