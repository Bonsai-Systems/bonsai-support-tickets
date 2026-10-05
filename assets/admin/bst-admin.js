/**
 * Bonsai Support Tickets — admin screens.
 *
 * Ticket screen: switches the reply box between "Reply to client" and
 * "Internal note": the box colour, the hint text and the button label
 * follow the choice, so it is always obvious whether the client will see
 * the message.
 *
 * Any screen: buttons with data-bst-confirm ask before submitting.
 *
 * Settings → Appearance: core colour pickers on the brand colour fields.
 */
(function ($) {
	'use strict';

	if ($.fn.wpColorPicker) {
		$('.bst-color-field').wpColorPicker();
	}

	// Confirm destructive actions (e.g. Reject on Sign-ups).
	$(document).on('click.bonsai_bst', '[data-bst-confirm]', function (e) {
		if (!window.confirm($(this).attr('data-bst-confirm'))) {
			e.preventDefault();
		}
	});

	var $box = $('.bst-reply-box');
	if (!$box.length) {
		return;
	}

	var $button = $box.find('.bst-send');
	var labels = window.bstAdmin || {};

	function bonsai_bst_set_mode(mode) {
		$box.attr('data-mode', mode);
		if (labels.sendReply && labels.addNote) {
			$button.text('internal' === mode ? labels.addNote : labels.sendReply);
		}
	}

	$box.on('change.bonsai_bst', 'input[name="bst_visibility"]', function () {
		bonsai_bst_set_mode(this.value);
	});

	// Stop a double click sending the same reply twice.
	$('#post').on('submit.bonsai_bst', function () {
		var $form = $(this);
		if ($form.data('bstSubmitting')) {
			return false;
		}
		$form.data('bstSubmitting', true);
		window.setTimeout(function () {
			$form.data('bstSubmitting', false);
		}, 5000);
	});
})(jQuery);
