/**
 * Support Desk theme — front-end behaviour.
 *
 * Ported from the original theme's main.js, keeping only what the
 * support theme uses: header scroll state, mobile menu, scroll reveal,
 * card stagger and the FAQ accordion. No cursor, sliders or AJAX grids.
 */
(function ($) {
	'use strict';

	var bonsai_reduce_motion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var bonsai_has_observer  = 'IntersectionObserver' in window;

	/**
	 * Header: add .scrolled once the page moves, for the soft shadow.
	 */
	function bonsai_init_header() {
		var $header = $('#bonsai-header');
		if (!$header.length) {
			return;
		}

		var ticking = false;

		function update() {
			$header.toggleClass('scrolled', window.scrollY > 20);
			ticking = false;
		}

		$(window).on('scroll.bonsai_header', function () {
			if (!ticking) {
				window.requestAnimationFrame(update);
				ticking = true;
			}
		});

		update();
	}

	/**
	 * Mobile menu: full-screen overlay. The overlay uses the hidden
	 * attribute when closed (out of tab order and the accessibility
	 * tree); Escape closes it and focus returns to the toggle.
	 */
	function bonsai_init_mobile_menu() {
		var $toggle  = $('#bonsai-menu-toggle');
		var $overlay = $('#bonsai-mobile-overlay');
		if (!$toggle.length || !$overlay.length) {
			return;
		}

		var close_timer = null;

		function focusables() {
			return $overlay.find('a[href], button:not([disabled])').filter(':visible');
		}

		function open_menu() {
			window.clearTimeout(close_timer);
			$overlay.removeAttr('hidden');
			// Next frame, so the opacity transition runs.
			window.requestAnimationFrame(function () {
				$overlay.addClass('active');
			});
			$toggle.attr('aria-expanded', 'true');
			$('body').addClass('bonsai-menu-open');
			focusables().first().trigger('focus');
		}

		function close_menu(return_focus) {
			$overlay.removeClass('active');
			$toggle.attr('aria-expanded', 'false');
			$('body').removeClass('bonsai-menu-open');
			close_timer = window.setTimeout(function () {
				$overlay.attr('hidden', '');
			}, bonsai_reduce_motion ? 0 : 350);
			if (return_focus) {
				$toggle.trigger('focus');
			}
		}

		function is_open() {
			return 'true' === $toggle.attr('aria-expanded');
		}

		$toggle.on('click.bonsai_menu', function () {
			if (is_open()) {
				close_menu(false);
			} else {
				open_menu();
			}
		});

		// Following a link closes the menu (matters for same-page anchors).
		$overlay.on('click.bonsai_menu', 'a', function () {
			close_menu(false);
		});

		$(document).on('keydown.bonsai_menu', function (e) {
			if (!is_open()) {
				return;
			}

			if ('Escape' === e.key) {
				close_menu(true);
				return;
			}

			// Keep Tab inside the toggle + overlay while the menu is open.
			if ('Tab' === e.key) {
				var $items = $toggle.add(focusables());
				var first  = $items.get(0);
				var last   = $items.get($items.length - 1);

				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					first.focus();
				}
			}
		});

		// Close if the viewport grows past the mobile breakpoint.
		$(window).on('resize.bonsai_menu', function () {
			if (is_open() && window.innerWidth > 900) {
				close_menu(false);
			}
		});
	}

	/**
	 * Scroll reveal: .reveal-on-scroll gets .active when it enters the
	 * viewport. Hidden state is gated on html.js in base.css.
	 */
	function bonsai_init_reveal() {
		var $elements = $('.reveal-on-scroll');
		if (!$elements.length) {
			return;
		}

		if (bonsai_reduce_motion || !bonsai_has_observer) {
			$elements.addClass('active');
			return;
		}

		var observer = new IntersectionObserver(function (entries, obs) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('active');
					obs.unobserve(entry.target);
				}
			});
		}, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

		$elements.each(function () {
			observer.observe(this);
		});
	}

	/**
	 * Card stagger: children of [data-reveal-cards="N"] fade in, delayed
	 * by their column position (N = columns).
	 */
	function bonsai_init_card_reveal() {
		$('[data-reveal-cards]').each(function () {
			var $grid    = $(this);
			var columns  = parseInt($grid.attr('data-reveal-cards'), 10) || 3;
			var $cards   = $grid.children().addClass('card-reveal');

			if (bonsai_reduce_motion || !bonsai_has_observer) {
				$cards.addClass('is-inview');
				return;
			}

			var observer = new IntersectionObserver(function (entries, obs) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) {
						return;
					}
					var $card   = $(entry.target);
					var stagger = $card.index() % columns;
					$card.css('transition-delay', (stagger * 0.08) + 's').addClass('is-inview');
					obs.unobserve(entry.target);
				});
			}, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

			$cards.each(function () {
				observer.observe(this);
			});
		});
	}

	/**
	 * FAQ accordion: one open item per list, animated height.
	 * Closed answers are display:none via .js CSS (no .is-open).
	 */
	function bonsai_init_faq() {
		$('.faq-module__list').each(function () {
			var $list = $(this);

			function close_item($button) {
				var $answer = $('#' + $button.attr('aria-controls'));
				$button.attr('aria-expanded', 'false');

				if (bonsai_reduce_motion) {
					$answer.removeClass('is-open').css('height', '');
					return;
				}

				$answer.css('height', $answer[0].scrollHeight + 'px');
				$answer[0].getBoundingClientRect(); // Reflow so the collapse animates.
				$answer.css('height', '0px');
				$answer.one('transitionend', function () {
					if ('false' === $button.attr('aria-expanded')) {
						$answer.removeClass('is-open').css('height', '');
					}
				});
			}

			function open_item($button) {
				var $answer = $('#' + $button.attr('aria-controls'));
				$button.attr('aria-expanded', 'true');
				$answer.addClass('is-open');

				if (bonsai_reduce_motion) {
					return;
				}

				$answer.css('height', '0px');
				$answer[0].getBoundingClientRect();
				$answer.css('height', $answer[0].scrollHeight + 'px');
				$answer.one('transitionend', function () {
					// Back to auto so it reflows with the viewport.
					if ('true' === $button.attr('aria-expanded')) {
						$answer.css('height', '');
					}
				});
			}

			$list.on('click.bonsai_faq', '.faq-module__question', function () {
				var $button = $(this);
				var was_open = 'true' === $button.attr('aria-expanded');

				$list.find('.faq-module__question[aria-expanded="true"]').each(function () {
					close_item($(this));
				});

				if (!was_open) {
					open_item($button);
				}
			});
		});
	}

	$(function () {
		bonsai_init_header();
		bonsai_init_mobile_menu();
		bonsai_init_reveal();
		bonsai_init_card_reveal();
		bonsai_init_faq();
	});
})(jQuery);
