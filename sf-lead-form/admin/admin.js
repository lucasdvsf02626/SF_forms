/**
 * SF Lead Form — admin "Test Connection" handler.
 */
(function ($) {
	'use strict';

	$(function () {
		var $btn = $('#sf-lf-test-connection');
		var $out = $('#sf-lf-test-result');

		if (!$btn.length || typeof sfLeadFormAdmin === 'undefined') {
			return;
		}

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			$out
				.removeClass('is-ok is-error')
				.addClass('is-pending')
				.text(sfLeadFormAdmin.testing);

			$.post(sfLeadFormAdmin.ajaxUrl, {
				action: sfLeadFormAdmin.action,
				nonce: sfLeadFormAdmin.nonce
			})
				.done(function (res) {
					if (res && res.success) {
						$out.removeClass('is-pending is-error').addClass('is-ok')
							.text('✅ ' + (res.data && res.data.message ? res.data.message : 'Connected'));
					} else {
						$out.removeClass('is-pending is-ok').addClass('is-error')
							.text('❌ ' + (res && res.data && res.data.message ? res.data.message : sfLeadFormAdmin.failed));
					}
				})
				.fail(function () {
					$out.removeClass('is-pending is-ok').addClass('is-error')
						.text('❌ ' + sfLeadFormAdmin.failed);
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});
	});
})(jQuery);
