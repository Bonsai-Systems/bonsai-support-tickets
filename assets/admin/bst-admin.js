/**
 * Support Desk — admin screens.
 *
 * Ticket screen: switches the reply box between "Reply to client" and
 * "Internal note": the box colour, the hint text and the button label
 * follow the choice, so it is always obvious whether the client will see
 * the message.
 *
 * Any screen: buttons with data-bst-confirm ask before submitting.
 *
 * Settings → Appearance: core colour pickers and the logo media picker.
 */
(function ($) {
	'use strict';

	if ($.fn.wpColorPicker) {
		$('.bst-color-field').wpColorPicker();
	}

	// Logo picker: fills the URL field from the media library and shows a preview.
	$(document).on('click.bonsai_bst', '.bst-media-field__choose', function (e) {
		e.preventDefault();
		if (!window.wp || !wp.media) {
			return;
		}
		var $field = $(this).closest('.bst-media-field');
		var frame = wp.media({
			title: $(this).data('title'),
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var image = frame.state().get('selection').first().toJSON();
			$field.find('.bst-media-field__url').val(image.url).trigger('change');
		});
		frame.open();
	});

	$(document).on('change.bonsai_bst input.bonsai_bst', '.bst-media-field__url', function () {
		var url = $.trim($(this).val());
		$(this).closest('.bst-media-field').find('.bst-media-field__preview').attr('src', url).prop('hidden', !url);
	});

	// Confirm destructive actions (e.g. Reject on Sign-ups).
	$(document).on('click.bonsai_bst', '[data-bst-confirm]', function (e) {
		if (!window.confirm($(this).attr('data-bst-confirm'))) {
			e.preventDefault();
		}
	});

	// Uptime monitoring: select the whole URL/secret on focus, ready to copy.
	$(document).on('focus.bonsai_bst', '.bst-copy-field', function () {
		$(this).trigger('select');
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
