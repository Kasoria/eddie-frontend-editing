/**
 * Eddie – frontend editor.
 *
 * Marked elements carry data-eddie (a signed field token). The script never knows which meta key or builder
 * element is behind a token; it only sends tokens and new values to the REST API.
 */
(function () {
	'use strict';

	var settings = window.eddieSettings;
	if (!settings) {
		return;
	}

	var strings = settings.strings || {};
	var TEXT_TYPES = ['text', 'plain', 'richtext', 'textarea', 'number', 'email', 'url'];
	var PLAIN_TYPES = ['plain', 'textarea', 'number', 'email', 'url'];
	var PLAINTEXT_SUPPORTED = (function () {
		var probe = document.createElement('div');
		probe.setAttribute('contenteditable', 'plaintext-only');
		return probe.contentEditable === 'plaintext-only';
	})();

	var state = {
		editing: false,
		dirty: new Map(), // token -> { el, value? }
		lastApplied: [], // change ids from the last live save
		needsReload: false,
	};

	var ui = {};

	function t(key) {
		return strings[key] || key;
	}

	function h(tag, attributes, children) {
		var node = document.createElement(tag);
		Object.keys(attributes || {}).forEach(function (name) {
			if (name === 'text') {
				node.textContent = attributes[name];
			} else if (name === 'class') {
				node.className = attributes[name];
			} else if (name.slice(0, 2) === 'on') {
				node.addEventListener(name.slice(2), attributes[name]);
			} else if (attributes[name] !== false && attributes[name] !== null && attributes[name] !== undefined) {
				node.setAttribute(name, attributes[name]);
			}
		});
		(children || []).forEach(function (child) {
			if (child) {
				node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
			}
		});
		return node;
	}

	function escapeHtml(text) {
		return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	function typeOf(el) {
		return el.getAttribute('data-eddie-type');
	}

	function isUi(node) {
		return !!(node && node.closest && node.closest('[data-eddie-ui]'));
	}

	/* ------------------------------------------------------------------ values */

	var ALLOWED = {
		text: { br: [], strong: [], b: [], em: [], i: [] },
		inline: { br: [], strong: [], b: [], em: [], i: [], a: ['href', 'target', 'rel'] },
		rich: { p: [], br: [], strong: [], b: [], em: [], i: [], ul: [], ol: [], li: [], h2: [], h3: [], h4: [], blockquote: [], a: ['href', 'target', 'rel'] },
	};

	function cleanHtml(html, mode) {
		var allowed = ALLOWED[mode];
		var template = document.createElement('template');
		template.innerHTML = html;

		(function walk(parent) {
			Array.prototype.slice.call(parent.childNodes).forEach(function (child) {
				if (child.nodeType !== 1) {
					return;
				}
				walk(child);
				var tag = child.tagName.toLowerCase();
				if (tag === 'script' || tag === 'style') {
					child.remove();
				} else if (tag === 'div' && allowed.p) {
					var paragraph = document.createElement('p');
					while (child.firstChild) {
						paragraph.appendChild(child.firstChild);
					}
					child.replaceWith(paragraph);
				} else if (!allowed[tag]) {
					var isBlock = tag === 'div' || tag === 'p' || tag === 'li';
					while (child.firstChild) {
						parent.insertBefore(child.firstChild, child);
					}
					if (isBlock) {
						parent.insertBefore(document.createElement('br'), child);
					}
					child.remove();
				} else {
					Array.prototype.slice.call(child.attributes).forEach(function (attribute) {
						if (allowed[tag].indexOf(attribute.name) === -1) {
							child.removeAttribute(attribute.name);
						}
					});
				}
			});
		})(template.content);

		return template.innerHTML
			.replace(/&nbsp;/g, ' ')
			.replace(/(\s*<br\s*\/?>\s*)+$/i, '')
			.trim();
	}

	function readText(el) {
		var type = typeOf(el);
		if (type === 'text') {
			return cleanHtml(el.innerHTML, 'text');
		}
		if (type === 'richtext') {
			return cleanHtml(el.innerHTML, el.hasAttribute('data-eddie-inline') ? 'inline' : 'rich');
		}
		if (type === 'textarea') {
			return el.innerText.replace(/ /g, ' ').replace(/\n{3,}/g, '\n\n').trim();
		}
		return el.textContent.replace(/ /g, ' ').trim();
	}

	function currentValue(el) {
		var type = typeOf(el);
		return TEXT_TYPES.indexOf(type) !== -1 ? readText(el) : el.__eddieValue;
	}

	function sameToken(token) {
		return Array.prototype.slice.call(document.querySelectorAll('[data-eddie]')).filter(function (el) {
			return el.getAttribute('data-eddie') === token;
		});
	}

	function syncSiblings(source) {
		var token = source.getAttribute('data-eddie');
		sameToken(token).forEach(function (el) {
			if (el !== source) {
				el.innerHTML = source.innerHTML;
			}
		});
	}

	/* ------------------------------------------------------------------ dirty state */

	function markDirty(el) {
		var token = el.getAttribute('data-eddie');
		var value = currentValue(el);
		if (JSON.stringify(value) === el.__eddieInitial) {
			state.dirty.delete(token);
			el.classList.remove('eddie-dirty');
		} else {
			state.dirty.set(token, { el: el });
			el.classList.add('eddie-dirty');
		}
		renderBar();
	}

	/* ------------------------------------------------------------------ applying values to the page */

	function applyImage(el, attachment) {
		var url = attachment.url;
		if (attachment.sizes && attachment.sizes.large) {
			url = attachment.sizes.large.url;
		}
		el.setAttribute('src', url);
		['srcset', 'sizes', 'data-srcset', 'data-sizes'].forEach(function (name) {
			el.removeAttribute(name);
		});
		if (el.hasAttribute('data-src')) {
			el.setAttribute('data-src', url);
		}
		el.classList.remove('bricks-lazy-hidden', 'lazyload', 'lazy');
		if (!el.getAttribute('alt') && attachment.alt) {
			el.setAttribute('alt', attachment.alt);
		}
	}

	function textNodeOf(el) {
		var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
		var node;
		while ((node = walker.nextNode())) {
			if (node.nodeValue.trim() !== '') {
				return node;
			}
		}
		return null;
	}

	function linkValue(el) {
		var anchor = el.tagName === 'A' ? el : el.closest('a');
		return {
			text: el.textContent.replace(/\s+/g, ' ').trim(),
			url: anchor ? anchor.getAttribute('href') || '' : '',
			target: anchor && anchor.getAttribute('target') === '_blank' ? '_blank' : '',
		};
	}

	function applyLink(el, value) {
		var node = textNodeOf(el);
		var plain = new DOMParser().parseFromString(value.text, 'text/html').body.textContent;
		if (node) {
			node.nodeValue = node.nodeValue.replace(node.nodeValue.trim(), plain);
		} else {
			el.textContent = plain;
		}
		var anchor = el.tagName === 'A' ? el : el.closest('a');
		if (anchor && !el.hasAttribute('data-eddie-link-readonly')) {
			if (value.url) {
				anchor.setAttribute('href', value.url);
			}
			if (value.target === '_blank') {
				anchor.setAttribute('target', '_blank');
			} else {
				anchor.removeAttribute('target');
			}
		}
	}

	function applyPreview(el, pending) {
		var preview = pending.preview || {};
		var type = typeOf(el);
		if (preview.image && type === 'image') {
			applyImage(el, { url: preview.image.url });
		} else if (preview.link && type === 'link') {
			applyLink(el, preview.link);
		} else if (typeof preview.html === 'string') {
			el.innerHTML = preview.html;
		} else if (typeof preview.text === 'string' && TEXT_TYPES.indexOf(type) !== -1) {
			el.textContent = preview.text;
		}
		el.classList.add('eddie-is-pending');
		el.setAttribute('data-eddie-pending', pending.id);
	}

	/* ------------------------------------------------------------------ editing: text */

	/** Galleries marked by the server carry their stored image ids in an attribute. */
	function readGalleryIds(el) {
		if (typeOf(el) !== 'gallery' || typeof el.__eddieValue !== 'undefined') {
			return;
		}
		try {
			var ids = JSON.parse(el.getAttribute('data-eddie-ids') || '[]');
			el.__eddieValue = Array.isArray(ids) ? ids.map(Number) : [];
		} catch (error) {
			el.__eddieValue = [];
		}
	}

	function prepareField(el) {
		var type = typeOf(el);
		readGalleryIds(el);
		if (TEXT_TYPES.indexOf(type) !== -1) {
			el.setAttribute('contenteditable', PLAIN_TYPES.indexOf(type) !== -1 && PLAINTEXT_SUPPORTED ? 'plaintext-only' : 'true');
			el.setAttribute('spellcheck', 'true');
			if (type === 'textarea') {
				el.style.whiteSpace = 'pre-wrap';
			}
		}
		if (typeof el.__eddieInitial === 'undefined') {
			el.__eddieInitial = JSON.stringify(currentValue(el));
		}
	}

	function releaseField(el) {
		el.removeAttribute('contenteditable');
		el.removeAttribute('spellcheck');
		if (typeOf(el) === 'textarea') {
			el.style.whiteSpace = '';
		}
	}

	function onInput(event) {
		var el = event.target.closest && event.target.closest('[data-eddie]');
		if (!el || !state.editing || TEXT_TYPES.indexOf(typeOf(el)) === -1) {
			return;
		}
		if (['text', 'richtext'].indexOf(typeOf(el)) !== -1) {
			syncSiblings(el);
		}
		markDirty(el);
	}

	function onKeydown(event) {
		var el = event.target.closest && event.target.closest('[data-eddie]');
		if ((event.metaKey || event.ctrlKey) && event.key === 's' && state.editing) {
			event.preventDefault();
			save();
			return;
		}
		if (event.key === 'Escape') {
			closePopover();
			return;
		}
		if (!el || !state.editing || TEXT_TYPES.indexOf(typeOf(el)) === -1 || event.key !== 'Enter') {
			return;
		}
		var type = typeOf(el);
		if (type === 'textarea') {
			return;
		}
		if (type === 'text' || (type === 'richtext' && el.hasAttribute('data-eddie-inline'))) {
			event.preventDefault();
			document.execCommand('insertLineBreak');
		} else if (type === 'richtext') {
			document.execCommand('defaultParagraphSeparator', false, 'p');
		} else {
			event.preventDefault();
			el.blur();
		}
	}

	function onPaste(event) {
		var el = event.target.closest && event.target.closest('[data-eddie]');
		if (!el || !state.editing || TEXT_TYPES.indexOf(typeOf(el)) === -1) {
			return;
		}
		event.preventDefault();
		var text = (event.clipboardData || window.clipboardData).getData('text/plain');
		document.execCommand('insertText', false, text);
	}

	/* ------------------------------------------------------------------ rich text toolbar */

	function showToolbar(el) {
		var type = typeOf(el);
		if (type !== 'text' && type !== 'richtext') {
			hideToolbar();
			return;
		}
		var inline = type === 'text' || el.hasAttribute('data-eddie-inline');
		var buttons = [['bold', 'B', t('bold')], ['italic', 'I', t('italic')]];
		if (type === 'richtext') {
			buttons.push(['link', '↗', t('link')]);
			if (!inline) {
				buttons.push(['insertUnorderedList', '•', t('bulletList')], ['insertOrderedList', '1.', t('numberedList')]);
			}
		}
		ui.toolbar.innerHTML = '';
		buttons.forEach(function (definition) {
			ui.toolbar.appendChild(
				h('button', {
					type: 'button',
					title: definition[2],
					'aria-label': definition[2],
					class: 'eddie-tool eddie-tool-' + definition[0],
					text: definition[1],
					onmousedown: function (event) {
						event.preventDefault();
						runTool(definition[0]);
					},
				})
			);
		});
		var rect = el.getBoundingClientRect();
		ui.toolbar.hidden = false;
		ui.toolbar.style.top = Math.max(8, rect.top - 44) + 'px';
		ui.toolbar.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 220)) + 'px';
	}

	function hideToolbar() {
		ui.toolbar.hidden = true;
	}

	function runTool(command) {
		if (command === 'link') {
			var selection = window.getSelection();
			var anchor = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
			var existing = anchor && anchor.closest ? anchor.closest('a') : null;
			var range = selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
			var url = window.prompt(t('enterUrl'), existing ? existing.getAttribute('href') : 'https://');
			if (url === null) {
				return;
			}
			if (range) {
				selection.removeAllRanges();
				selection.addRange(range);
			}
			if (url.trim() === '') {
				document.execCommand('unlink');
			} else {
				document.execCommand('createLink', false, url.trim());
			}
		} else {
			document.execCommand(command);
		}
		var active = document.activeElement && document.activeElement.closest('[data-eddie]');
		if (active) {
			markDirty(active);
		}
	}

	/* ------------------------------------------------------------------ editing: image, link, choice */

	function pickImage(el) {
		if (!window.wp || !wp.media) {
			return;
		}
		var frame = wp.media({
			title: t('chooseImage'),
			library: { type: 'image' },
			multiple: false,
			button: { text: t('chooseImage') },
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			sameToken(el.getAttribute('data-eddie')).forEach(function (sibling) {
				applyImage(sibling, attachment);
				sibling.__eddieValue = attachment.id;
			});
			markDirty(el);
		});
		frame.open();
	}

	/**
	 * The whole gallery is edited in WordPress' own gallery editor (add, remove, reorder), preselected with the
	 * stored images. The page cannot rebuild a theme's gallery markup, so the new list is shown after a reload.
	 */
	function pickGallery(el) {
		readGalleryIds(el);
		var ids = Array.isArray(el.__eddieValue) ? el.__eddieValue : [];
		var frame = window.wp && wp.media && wp.media.gallery && ids.length ? wp.media.gallery.edit('[gallery ids="' + ids.join(',') + '"]') : null;
		if (!frame) {
			toast(t('galleryError'), true);
			return;
		}
		frame.$el.addClass('eddie-gallery-frame');
		frame.state('gallery-edit').on('update', function (library) {
			var next = library.pluck('id').map(Number);
			sameToken(el.getAttribute('data-eddie')).forEach(function (sibling) {
				sibling.__eddieValue = next;
			});
			state.needsReload = true;
			markDirty(el);
		});
	}

	function openLinkPopover(el) {
		var value = el.__eddieValue || linkValue(el);
		var locked = el.hasAttribute('data-eddie-link-readonly');
		var textInput = h('input', { type: 'text', value: value.text, id: 'eddie-link-text' });
		var urlInput = h('input', { type: 'url', value: value.url, id: 'eddie-link-url', disabled: locked ? 'disabled' : false });
		var targetInput = h('input', { type: 'checkbox', id: 'eddie-link-target', disabled: locked ? 'disabled' : false });
		targetInput.checked = value.target === '_blank';

		var form = h(
			'form',
			{
				class: 'eddie-form',
				onsubmit: function (event) {
					event.preventDefault();
					var next = {
						text: escapeHtml(textInput.value.trim()),
						url: locked ? value.url : urlInput.value.trim(),
						target: !locked && targetInput.checked ? '_blank' : '',
					};
					sameToken(el.getAttribute('data-eddie')).forEach(function (sibling) {
						applyLink(sibling, next);
						sibling.__eddieValue = next;
					});
					closePopover();
					markDirty(el);
				},
			},
			[
				h('label', { for: 'eddie-link-text', text: t('linkText') }),
				textInput,
				h('label', { for: 'eddie-link-url', text: t('linkUrl') }),
				urlInput,
				locked ? h('p', { class: 'eddie-note', text: t('linkLocked') }) : h('label', { class: 'eddie-check' }, [targetInput, ' ' + t('linkNewTab')]),
				formActions(),
			]
		);
		showPopover(el, form);
		textInput.focus();
	}

	function openChoicePopover(el) {
		var choices = {};
		try {
			choices = JSON.parse(el.getAttribute('data-eddie-choices') || '{}');
		} catch (error) {
			choices = {};
		}
		var select = h('select', { id: 'eddie-choice' });
		Object.keys(choices).forEach(function (value) {
			select.appendChild(h('option', { value: value, text: choices[value] }));
		});
		if (typeof el.__eddieValue !== 'undefined') {
			select.value = el.__eddieValue;
		}
		var form = h(
			'form',
			{
				class: 'eddie-form',
				onsubmit: function (event) {
					event.preventDefault();
					el.__eddieValue = select.value;
					sameToken(el.getAttribute('data-eddie')).forEach(function (sibling) {
						sibling.textContent = choices[select.value];
						sibling.__eddieValue = select.value;
					});
					state.needsReload = true;
					closePopover();
					markDirty(el);
				},
			},
			[h('label', { for: 'eddie-choice', text: el.getAttribute('data-eddie-label') || '' }), select, formActions()]
		);
		showPopover(el, form);
		select.focus();
	}

	function formActions() {
		return h('div', { class: 'eddie-actions' }, [
			h('button', { type: 'submit', class: 'eddie-btn eddie-btn-primary', text: t('apply') }),
			h('button', { type: 'button', class: 'eddie-btn', text: t('cancel'), onclick: closePopover }),
		]);
	}

	function showPopover(el, content) {
		ui.popover.innerHTML = '';
		ui.popover.appendChild(content);
		ui.popover.hidden = false;
		var rect = el.getBoundingClientRect();
		var width = ui.popover.offsetWidth;
		ui.popover.style.top = Math.min(window.innerHeight - ui.popover.offsetHeight - 12, rect.bottom + 8) + 'px';
		ui.popover.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px';
	}

	function closePopover() {
		ui.popover.hidden = true;
		ui.popover.innerHTML = '';
	}

	/* ------------------------------------------------------------------ clicking and hovering */

	function onClick(event) {
		if (!state.editing || isUi(event.target)) {
			return;
		}
		var el = event.target.closest && event.target.closest('[data-eddie],[data-eddie-readonly]');
		var link = event.target.closest && event.target.closest('a');
		if (!el) {
			if (link && !isUi(link)) {
				// Links keep working for navigation unless they wrap an editable element.
				return;
			}
			closePopover();
			return;
		}
		if (link || el.tagName === 'A' || el.tagName === 'BUTTON') {
			event.preventDefault();
		}
		if (el.hasAttribute('data-eddie-readonly')) {
			showChip(el, true);
			return;
		}
		var type = typeOf(el);
		if (type === 'image') {
			event.preventDefault();
			pickImage(el);
		} else if (type === 'gallery') {
			event.preventDefault();
			pickGallery(el);
		} else if (type === 'link') {
			event.preventDefault();
			openLinkPopover(el);
		} else if (type === 'choice') {
			event.preventDefault();
			openChoicePopover(el);
		}
	}

	var chipTimer = null;

	function showChip(el, pinned) {
		window.clearTimeout(chipTimer);
		var readonly = el.hasAttribute('data-eddie-readonly');
		var shared = el.hasAttribute('data-eddie-shared');
		var link = el.getAttribute('data-eddie-link');
		ui.chip.innerHTML = '';
		ui.chip.appendChild(h('strong', { text: el.getAttribute('data-eddie-label') || t('edit') }));
		if (el.hasAttribute('data-eddie-pending')) {
			ui.chip.appendChild(h('span', { class: 'eddie-chip-note', text: t('pending') }));
		}
		if (readonly) {
			var reason = el.getAttribute('data-eddie-reason');
			var note = reason === 'acf' ? t('acfField') : reason === 'ambiguous' ? t('ambiguous') : reason === 'complex' ? t('complex') : t('dynamic');
			ui.chip.appendChild(h('span', { class: 'eddie-chip-note', text: note }));
		} else if (el.getAttribute('data-eddie-label').indexOf('ACF') === 0 && link) {
			ui.chip.appendChild(h('span', { class: 'eddie-chip-note', text: t('acfEditable') }));
		}
		if (shared) {
			ui.chip.appendChild(h('span', { class: 'eddie-chip-note', text: t('shared') }));
		}
		if (link) {
			ui.chip.appendChild(h('a', { class: 'eddie-chip-link', href: link, target: '_blank', rel: 'noopener', text: t(link.indexOf('eddie_field=') !== -1 ? 'openField' : 'editInBackend') + ' ↗' }));
		}
		ui.chip.hidden = false;
		var rect = el.getBoundingClientRect();
		var top = rect.top - ui.chip.offsetHeight - 6;
		ui.chip.style.top = (top < 8 ? Math.min(rect.bottom + 6, window.innerHeight - 60) : top) + 'px';
		ui.chip.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - ui.chip.offsetWidth - 8)) + 'px';
		if (!pinned) {
			el.__chipHover = true;
		}
	}

	function hideChip(delay) {
		window.clearTimeout(chipTimer);
		chipTimer = window.setTimeout(function () {
			ui.chip.hidden = true;
		}, delay || 0);
	}

	function onMouseOver(event) {
		if (!state.editing || isUi(event.target)) {
			return;
		}
		var el = event.target.closest && event.target.closest('[data-eddie],[data-eddie-readonly]');
		if (el) {
			showChip(el, false);
		} else {
			hideChip(250);
		}
	}

	/* ------------------------------------------------------------------ saving */

	function request(path, body) {
		return fetch(settings.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
			body: JSON.stringify(body),
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error(data && data.message ? data.message : t('error'));
				}
				return data;
			});
		});
	}

	function save() {
		if (!state.dirty.size) {
			toast(t('nothingToSave'));
			return;
		}
		var changes = [];
		state.dirty.forEach(function (entry, token) {
			changes.push({ token: token, value: currentValue(entry.el) });
		});

		setBusy(true);
		toast(t('saving'));
		request('/save', { changes: changes, page: window.location.href })
			.then(function (data) {
				handleResults(data);
			})
			.catch(function (error) {
				toast(error.message || t('error'), true);
			})
			.then(function () {
				setBusy(false);
			});
	}

	function handleResults(data) {
		var applied = 0;
		var pending = 0;
		var failures = [];
		state.lastApplied = [];

		data.results.forEach(function (result) {
			var entry = state.dirty.get(result.token);
			var elements = sameToken(result.token);
			if (result.status === 'applied' || result.status === 'pending' || result.status === 'unchanged') {
				state.dirty.delete(result.token);
				elements.forEach(function (el) {
					el.classList.remove('eddie-dirty', 'eddie-error');
					el.__eddieInitial = JSON.stringify(currentValue(el));
					if (result.status === 'applied' && result.token_after) {
						el.setAttribute('data-eddie', result.token_after);
						el.setAttribute('data-eddie-key', result.key_after);
					}
					if (result.status === 'pending') {
						el.classList.add('eddie-is-pending');
						el.setAttribute('data-eddie-pending', result.id);
					}
				});
				if (result.status === 'applied') {
					applied++;
					state.lastApplied.push(result.id);
				} else if (result.status === 'pending') {
					pending++;
				}
			} else {
				failures.push(result.message || t('error'));
				elements.forEach(function (el) {
					el.classList.add('eddie-error');
				});
				if (entry && entry.el) {
					entry.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
				}
			}
		});

		renderBar();
		if (failures.length) {
			toast(failures[0], true);
		} else if (state.needsReload && applied) {
			toast(t('reloadToSee'), false, true);
		} else {
			toast(pending ? t('submitted') : t('saved'));
		}
	}

	function undo() {
		if (!state.lastApplied.length) {
			return;
		}
		setBusy(true);
		request('/undo', { ids: state.lastApplied })
			.then(function (data) {
				var failed = data.results.filter(function (result) {
					return !result.ok;
				});
				if (failed.length) {
					toast(failed[0].message || t('error'), true);
					setBusy(false);
					return;
				}
				state.lastApplied = [];
				toast(t('undone'));
				window.setTimeout(function () {
					window.location.reload();
				}, 700);
			})
			.catch(function (error) {
				toast(error.message || t('error'), true);
				setBusy(false);
			});
	}

	/* ------------------------------------------------------------------ interface */

	function setBusy(busy) {
		ui.bar.classList.toggle('eddie-busy', busy);
	}

	var toastTimer = null;

	function toast(message, isError, withReload) {
		ui.toast.innerHTML = '';
		ui.toast.appendChild(document.createTextNode(message));
		if (withReload) {
			ui.toast.appendChild(
				h('button', {
					type: 'button',
					class: 'eddie-btn eddie-btn-small',
					text: t('reload'),
					onclick: function () {
						window.location.reload();
					},
				})
			);
		}
		ui.toast.classList.toggle('eddie-toast-error', !!isError);
		ui.toast.hidden = false;
		window.clearTimeout(toastTimer);
		if (!withReload) {
			toastTimer = window.setTimeout(function () {
				ui.toast.hidden = true;
			}, isError ? 7000 : 3500);
		}
	}

	function allFields() {
		var seen = {};
		var fields = [];
		Array.prototype.slice.call(document.querySelectorAll('[data-eddie],[data-eddie-readonly]')).forEach(function (el) {
			if (isUi(el)) {
				return;
			}
			var identity = el.getAttribute('data-eddie-key') || 'ro-' + fields.length;
			if (!seen[identity]) {
				seen[identity] = true;
				fields.push(el);
			}
		});
		return fields;
	}

	var ICONS = {
		edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
		check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 010 12h-3"/>',
		external: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/>',
	};

	function icon(name) {
		var wrapper = document.createElement('span');
		wrapper.className = 'eddie-icon';
		wrapper.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
		return wrapper;
	}

	/** Sets a bar button's icon, optional visible label and optional count badge. */
	function setButton(button, iconName, label, count) {
		button.innerHTML = '';
		if (iconName) {
			button.appendChild(icon(iconName));
		}
		if (label) {
			button.appendChild(h('span', { class: 'eddie-label', text: label }));
		}
		if (count) {
			button.appendChild(h('span', { class: 'eddie-count', text: String(count) }));
		}
	}

	function renderBar() {
		var fields = allFields();
		var editableCount = fields.filter(function (el) {
			return el.hasAttribute('data-eddie');
		}).length;
		var dirtyCount = state.dirty.size;

		setButton(ui.toggle, state.editing ? 'check' : 'edit', state.editing ? t('done') : t('edit'));
		ui.toggle.classList.toggle('eddie-btn-primary', !state.editing);
		ui.bar.classList.toggle('eddie-is-editing', state.editing);

		setButton(ui.fieldsButton, 'list', '', fields.length);
		setButton(ui.saveButton, '', settings.mode === 'review' ? t('saveForReview') : t('save'), dirtyCount);
		ui.saveButton.disabled = !dirtyCount;
		setButton(ui.undoButton, 'undo', '');
		ui.undoButton.hidden = !state.lastApplied.length;
		ui.bar.setAttribute('data-count', editableCount);
		if (!state.editing) {
			ui.panel.hidden = true;
		} else if (!ui.panel.hidden) {
			renderPanel(fields);
		}
	}

	/** Every ACF value the page read, with a link to its field. Works even when no element could be matched. */
	function renderAcfList() {
		var records = window.eddieAcf || [];
		var stats = window.eddieAcfStats;
		if (!stats) {
			return;
		}
		ui.panel.appendChild(h('h2', { class: 'eddie-panel-sub', text: t('acfFields') }));

		var matched = records.filter(function (record) {
			return record.matched;
		}).length;
		var summary = stats.read === 0 ? t('acfNone') : t('acfStats').replace('%1$s', stats.read).replace('%2$s', matched);
		ui.panel.appendChild(h('p', { class: 'eddie-note', text: summary }));

		var seen = {};
		var list = h('ul');
		records.forEach(function (record) {
			var identity = record.link + '|' + (record.v || (record.img && record.img.id) || '');
			if (seen[identity] || !record.link) {
				return;
			}
			seen[identity] = true;
			var snippet = record.img ? '[' + (record.label || 'image') + ']' : (record.text || '');
			list.appendChild(
				h('li', {}, [
					h(
						'a',
						{
							class: 'eddie-field eddie-field-acf',
							href: record.link,
							target: '_blank',
							rel: 'noopener',
							title: t('openField'),
						},
						[
							h('span', { class: 'eddie-field-label', text: (record.label || '') + ' \u2197' }),
							h('span', { class: 'eddie-field-snippet', text: snippet || (record.matched ? '' : '\u2014') }),
						]
					),
				])
			);
		});
		ui.panel.appendChild(list);
	}

	function renderPanel(fields) {
		ui.panel.innerHTML = '';
		ui.panel.appendChild(h('h2', { text: t('fields') }));
		if (!fields.length) {
			ui.panel.appendChild(h('p', { class: 'eddie-note', text: t('noFields') }));
			renderAcfList();
			return;
		}
		var list = h('ul');
		fields.forEach(function (el) {
			var readonly = el.hasAttribute('data-eddie-readonly');
			var snippet = (el.tagName === 'IMG' ? el.getAttribute('alt') || el.getAttribute('src') || '' : el.textContent).replace(/\s+/g, ' ').trim().slice(0, 48);
			list.appendChild(
				h('li', {}, [
					h(
						'button',
						{
							type: 'button',
							class: 'eddie-field' + (readonly ? ' eddie-field-readonly' : ''),
							onclick: function () {
								el.scrollIntoView({ block: 'center', behavior: 'smooth' });
								el.classList.add('eddie-flash');
								window.setTimeout(function () {
									el.classList.remove('eddie-flash');
								}, 1400);
							},
						},
						[h('span', { class: 'eddie-field-label', text: (readonly ? '🔒 ' : '') + (el.getAttribute('data-eddie-label') || '') }), h('span', { class: 'eddie-field-snippet', text: snippet })]
					),
				])
			);
		});
		ui.panel.appendChild(list);
		renderAcfList();
	}

	function setEditing(on) {
		state.editing = on;
		document.documentElement.classList.toggle('eddie-editing', on);
		Array.prototype.slice.call(document.querySelectorAll('[data-eddie]')).forEach(function (el) {
			if (isUi(el)) {
				return;
			}
			if (on) {
				prepareField(el);
			} else {
				releaseField(el);
			}
		});
		if (!on) {
			closePopover();
			hideToolbar();
			hideChip(0);
		}
		renderBar();
	}

	function buildUi() {
		var root = document.getElementById('eddie-root');
		if (!root) {
			return false;
		}

		ui.toggle = h('button', { type: 'button', class: 'eddie-btn', onclick: function () { setEditing(!state.editing); } });
		ui.fieldsButton = h('button', {
			type: 'button',
			title: t('fields'),
			'aria-label': t('fields'),
			class: 'eddie-btn eddie-btn-ghost eddie-only-editing',
			onclick: function () {
				ui.panel.hidden = !ui.panel.hidden;
				renderBar();
				if (!ui.panel.hidden) {
					renderPanel(allFields());
				}
			},
		});
		ui.saveButton = h('button', { type: 'button', class: 'eddie-btn eddie-btn-primary eddie-only-editing', onclick: save });
		ui.undoButton = h('button', { type: 'button', class: 'eddie-btn eddie-btn-ghost eddie-only-editing', title: t('undo'), 'aria-label': t('undo'), hidden: 'hidden', onclick: undo });

		var links = [];
		if (settings.adminUrl) {
			var backend = h('a', { class: 'eddie-btn eddie-btn-ghost eddie-only-editing', href: settings.adminUrl, title: t('editPage'), 'aria-label': t('editPage') });
			backend.appendChild(icon('external'));
			links.push(backend);
		}
		if (settings.reviewUrl && settings.mode === 'review') {
			var review = h('a', { class: 'eddie-btn eddie-btn-ghost eddie-only-editing', href: settings.reviewUrl, title: t('reviewChanges'), 'aria-label': t('reviewChanges') });
			review.appendChild(icon('external'));
			links.push(review);
		}

		ui.bar = h('div', { class: 'eddie-bar', 'data-eddie-ui': '1', role: 'toolbar' }, [ui.toggle, ui.fieldsButton, ui.saveButton, ui.undoButton].concat(links));
		ui.panel = h('div', { class: 'eddie-panel', 'data-eddie-ui': '1', hidden: 'hidden' });
		ui.chip = h('div', {
			class: 'eddie-chip',
			'data-eddie-ui': '1',
			hidden: 'hidden',
			onmouseenter: function () { window.clearTimeout(chipTimer); },
			onmouseleave: function () { hideChip(150); },
		});
		ui.popover = h('div', { class: 'eddie-popover', 'data-eddie-ui': '1', hidden: 'hidden' });
		ui.toolbar = h('div', { class: 'eddie-toolbar', 'data-eddie-ui': '1', hidden: 'hidden' });
		ui.toast = h('div', { class: 'eddie-toast', 'data-eddie-ui': '1', hidden: 'hidden', role: 'status', 'aria-live': 'polite' });

		[ui.bar, ui.panel, ui.chip, ui.popover, ui.toolbar, ui.toast].forEach(function (node) {
			root.appendChild(node);
		});
		return true;
	}

	/* ------------------------------------------------------------------ ACF values found by text */

	var SKIP_SELECTOR = 'script,style,noscript,template,svg,[data-eddie-ui],[data-eddie],[data-eddie-readonly]';
	var INLINE_TAGS = { A: 1, SPAN: 1, EM: 1, STRONG: 1, B: 1, I: 1, BR: 1, SMALL: 1, MARK: 1, U: 1, SUB: 1, SUP: 1, ABBR: 1, CODE: 1, S: 1, DEL: 1, INS: 1, TIME: 1, WBR: 1 };

	function squash(text) {
		return text.replace(/[\s ]+/g, '');
	}

	function isInlineOnly(el) {
		for (var child = el.firstElementChild; child; child = child.nextElementSibling) {
			if (!INLINE_TAGS[child.tagName]) {
				return false;
			}
		}
		return true;
	}

	function isMarked(el) {
		return !!el.closest('[data-eddie],[data-eddie-readonly]');
	}

	function markAcf(el, record, editable, reason) {
		record.matched = true;
		var label = 'ACF · ' + (record.label || '');
		el.setAttribute('data-eddie-label', label);
		if (record.link) {
			el.setAttribute('data-eddie-link', record.link);
		}
		if (editable) {
			el.setAttribute('data-eddie', record.tok);
			el.setAttribute('data-eddie-key', record.fkey);
			el.setAttribute('data-eddie-type', record.ty);
			if (record.ch) {
				el.setAttribute('data-eddie-choices', JSON.stringify(record.ch));
			}
		} else {
			el.setAttribute('data-eddie-readonly', '1');
			el.setAttribute('data-eddie-reason', reason || 'acf');
		}
	}

	/**
	 * The server lists every ACF value the page read (text, or the image it shows). Elements whose text equals
	 * such a value get linked to the field in the backend, and edited in place when that is safe: only when the
	 * text belongs to exactly one field.
	 */
	function matchAcf() {
		var records = window.eddieAcf;
		if (!records || !records.length) {
			return;
		}
		var wantsRich = records.some(function (record) {
			return record.ty === 'richtext';
		});

		var index = {};
		var leaves = [];
		var all = document.body.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			var el = all[i];
			if (el.closest(SKIP_SELECTOR)) {
				continue;
			}
			var inlineOnly = isInlineOnly(el);
			if (!inlineOnly && !wantsRich) {
				continue;
			}
			var text = squash(el.textContent);
			if (text.length < 3 || text.length > 4000) {
				continue;
			}
			(index[text] = index[text] || []).push({ el: el, rich: !inlineOnly });
			if (inlineOnly) {
				leaves.push({ el: el, text: text });
			}
		}

		// Prefer the innermost element when wrappers carry the same text.
		Object.keys(index).forEach(function (text) {
			var entries = index[text];
			index[text] = entries.filter(function (entry) {
				return !entries.some(function (other) {
					return other !== entry && entry.el.contains(other.el);
				});
			});
		});

		var owners = {};
		var seen = {};
		var pool = {};
		records.forEach(function (record) {
			if (record.v) {
				owners[record.v] = (owners[record.v] || 0) + 1;
			}
		});

		// A gallery lists its stored image ids once, on one of its records; every image of it shares them.
		var galleryIds = {};
		records.forEach(function (record) {
			if (record.ty === 'gallery' && record.ids) {
				galleryIds[record.fkey] = record.ids;
			}
		});

		records.forEach(function (record) {
			if (record.img) {
				Array.prototype.slice.call(document.querySelectorAll('img')).forEach(function (image) {
					if (image.closest(SKIP_SELECTOR)) {
						return;
					}
					var source = [image.currentSrc, image.getAttribute('src'), image.getAttribute('data-src')].join(' ');
					var byId = record.img.id && new RegExp('\\bwp-image-' + record.img.id + '\\b').test(image.className);
					var byFile = record.img.base && source.indexOf(record.img.base) !== -1;
					if (byId || byFile) {
						var gallery = record.ty === 'gallery' && !!galleryIds[record.fkey];
						markAcf(image, record, !!record.tok && (record.ty === 'image' || gallery), 'acf');
						if (gallery) {
							image.__eddieValue = galleryIds[record.fkey];
						}
					}
				});
				return;
			}
			if (!record.v) {
				return;
			}

			var ambiguous = owners[record.v] > 1;
			// Shared text: every record of the group sees the same list of elements, found before any was marked.
			var found = ambiguous && pool[record.v] ? pool[record.v] : (index[record.v] || []).filter(function (entry) {
				return !isMarked(entry.el) && (!entry.rich || record.ty === 'richtext');
			});
			if (ambiguous) {
				pool[record.v] = found;
			}
			if (found.length) {
				if (!ambiguous) {
					found.forEach(function (entry) {
						markAcf(entry.el, record, !!record.tok && record.ty !== 'image', 'acf');
					});
					return;
				}
				// Several fields show the same text: pair them with the elements in page order when the counts
				// agree (loops usually do), otherwise link every element to the first field. Never editable.
				var position = seen[record.v] || 0;
				seen[record.v] = position + 1;
				if (found.length === owners[record.v]) {
					markAcf(found[position].el, record, false, 'ambiguous');
				} else if (position === 0) {
					found.forEach(function (entry) {
						markAcf(entry.el, record, false, 'ambiguous');
					});
				}
				return;
			}

			// The text is part of something larger ("Hello {name}"): link it, but never edit it.
			if (record.v.length >= 5) {
				var hits = 0;
				leaves.some(function (leaf) {
					if (hits >= 5 || leaf.text.length > record.v.length * 3 + 40 || leaf.text.indexOf(record.v) === -1 || isMarked(leaf.el)) {
						return hits >= 5;
					}
					markAcf(leaf.el, record, false, 'acf');
					hits++;
					return false;
				});
			}
		});
	}

	function applyPending() {
		var pending = window.eddiePending || {};
		Object.keys(pending).forEach(function (key) {
			Array.prototype.slice.call(document.querySelectorAll('[data-eddie-key="' + key + '"]')).forEach(function (el) {
				applyPreview(el, pending[key]);
			});
		});
	}

	/**
	 * Opened from the change log (?eddie_focus=<field key>): scroll to the exact spot and highlight it.
	 */
	function focusFromUrl() {
		var params = new URLSearchParams(window.location.search);
		var key = (params.get('eddie_focus') || '').replace(/[^a-f0-9]/gi, '');
		if (!key) {
			return;
		}
		params.delete('eddie_focus');
		var query = params.toString();
		try {
			window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
		} catch (error) {
			// Not critical: the parameter simply stays in the address bar.
		}

		var attempts = 0;
		(function look() {
			var el = document.querySelector('[data-eddie-key="' + key + '"]');
			if (!el) {
				if (++attempts < 12) {
					window.setTimeout(look, 250);
				} else {
					toast(t('focusMissing'), true);
				}
				return;
			}
			el.scrollIntoView({ block: 'center', behavior: 'smooth' });
			el.classList.add('eddie-focus');
			window.setTimeout(function () {
				showChip(el, false);
			}, 500);
			window.setTimeout(function () {
				el.classList.remove('eddie-focus');
			}, 5000);
		})();
	}

	function init() {
		if (!buildUi()) {
			return;
		}
		matchAcf();
		applyPending();

		document.addEventListener('input', onInput, true);
		document.addEventListener('keydown', onKeydown, true);
		document.addEventListener('paste', onPaste, true);
		document.addEventListener('click', onClick, true);
		document.addEventListener('mouseover', onMouseOver);
		document.addEventListener('focusin', function (event) {
			var el = event.target.closest && event.target.closest('[data-eddie]');
			if (state.editing && el && !isUi(el)) {
				showToolbar(el);
			}
		});
		document.addEventListener('focusout', function () {
			window.setTimeout(function () {
				var active = document.activeElement;
				if (!active || !active.closest || !active.closest('[data-eddie]')) {
					hideToolbar();
				}
			}, 150);
		});
		window.addEventListener('beforeunload', function (event) {
			if (state.dirty.size) {
				event.preventDefault();
				event.returnValue = t('discardPrompt');
			}
		});
		window.addEventListener('scroll', function () {
			hideToolbar();
			hideChip(0);
		}, { passive: true });

		renderBar();
		focusFromUrl();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
