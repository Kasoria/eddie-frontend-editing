/**
 * Eddie – opens and highlights an ACF field in the backend (?eddie_field=field_xxx&eddie_row=n).
 *
 * ACF renders fields into metaboxes, tabs, accordions and repeater rows that may be closed or hidden, so the
 * script reveals every ancestor first, then scrolls to the field and pulses it. The editor loads late (block
 * editor, ACF init), so it keeps looking for a few seconds.
 */
(function () {
	'use strict';

	var config = window.eddieHighlight;
	if (!config || !config.key) {
		return;
	}

	var attempts = 0;
	var MAX_ATTEMPTS = 40;

	function realFields(selector) {
		return Array.prototype.slice.call(document.querySelectorAll(selector)).filter(function (field) {
			// The hidden template row of a repeater is not a real field.
			return !field.closest('.acf-clone');
		});
	}

	function findField() {
		var fields = realFields('.acf-field[data-key="' + config.key + '"]');
		if (!fields.length) {
			return null;
		}
		return fields[Math.min(config.row || 0, fields.length - 1)];
	}

	function openMetabox(field) {
		var box = field.closest('.postbox');
		if (box && box.classList.contains('closed')) {
			var toggle = box.querySelector('.handlediv, .postbox-header .handlediv');
			if (toggle) {
				toggle.click();
			} else {
				box.classList.remove('closed');
			}
		}
	}

	function openTabs(field) {
		// A field hidden by a tab: click the nearest tab field above it, then repeat for nested groups.
		var current = field;
		while (current) {
			if (current.classList.contains('hidden-by-tab')) {
				var sibling = current.previousElementSibling;
				while (sibling && !sibling.classList.contains('acf-field-tab')) {
					sibling = sibling.previousElementSibling;
				}
				if (sibling) {
					var button = document.querySelector('.acf-tab-button[data-key="' + sibling.getAttribute('data-key') + '"]');
					if (button) {
						button.click();
					}
				}
			}
			var parentField = current.parentElement ? current.parentElement.closest('.acf-field') : null;
			current = parentField;
		}
	}

	function openAccordions(field) {
		var node = field;
		while (node) {
			var accordion = node.closest('.acf-accordion:not(.-open)');
			if (!accordion) {
				break;
			}
			var title = accordion.querySelector('.acf-accordion-title');
			if (title) {
				title.click();
			}
			node = accordion.parentElement;
		}
	}

	function openRows(field) {
		var row = field.closest('.acf-row.-collapsed');
		while (row) {
			var handle = row.querySelector('.acf-row-handle.order, .acf-row-handle');
			if (handle) {
				handle.click();
			}
			row = row.parentElement ? row.parentElement.closest('.acf-row.-collapsed') : null;
		}
	}

	function reveal(field) {
		openMetabox(field);
		openAccordions(field);
		openRows(field);
		openTabs(field);
	}

	function highlight(field) {
		field.classList.add('eddie-acf-highlight');
		field.scrollIntoView({ block: 'center', behavior: 'smooth' });
		var input = field.querySelector('input:not([type=hidden]), textarea, select, .mce-edit-area, [contenteditable]');
		if (input && typeof input.focus === 'function') {
			try {
				input.focus({ preventScroll: true });
			} catch (error) {
				// Some editors refuse programmatic focus; the highlight is enough.
			}
		}
		window.setTimeout(function () {
			field.classList.remove('eddie-acf-highlight');
		}, 6000);
	}

	function attempt() {
		attempts++;
		var field = findField();
		if (field) {
			reveal(field);
			// Revealing may need a moment for ACF to re-layout before scrolling is accurate.
			window.setTimeout(function () {
				highlight(field);
			}, 250);
			return;
		}
		if (attempts < MAX_ATTEMPTS) {
			window.setTimeout(attempt, 300);
		}
	}

	if (document.readyState === 'complete') {
		attempt();
	} else {
		window.addEventListener('load', attempt);
	}
})();
