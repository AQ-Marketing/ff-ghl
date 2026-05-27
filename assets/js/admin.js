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
	});
})(jQuery);
