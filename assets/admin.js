/* Comment Draft Replies: panel buttons. Every request goes to the plugin's
 * REST routes with the logged-in cookie, the REST nonce and the plugin nonce.
 */
(function () {
	'use strict';
	var cfg = window.cdrepliesAdmin;
	if (!cfg) {
		return;
	}

	function post(id, action, body) {
		return fetch(cfg.root + id + '/' + action, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce,
				'X-Cdreplies-Nonce': cfg.nonce
			},
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (data) {
				return { ok: r.ok, data: data };
			});
		});
	}

	function say(panel, text) {
		var el = panel.querySelector('.cdreplies-message');
		if (el) {
			el.textContent = text;
		}
	}

	function publish(panel, id, approveParent, confirm) {
		var area = panel.querySelector('.cdreplies-text');
		var body = { approve_parent: approveParent, confirm: confirm };
		if (area && area.value !== area.defaultValue) {
			body.text = area.value;
		}
		return post(id, 'publish', body).then(function (res) {
			if (res.ok) {
				say(panel, cfg.i18n.done);
				panel.setAttribute('data-status', 'published');
				return;
			}
			if (res.data && res.data.code === 'cdreplies_confirm_needed') {
				var warnings = (res.data.data && res.data.data.warnings) || [];
				var question = warnings.indexOf('comment_changed') !== -1
					? cfg.i18n.confirmChanged
					: cfg.i18n.confirmWarnings.replace('%s', warnings.join(', '));
				if (window.confirm(question)) {
					return publish(panel, id, approveParent, confirm.concat(warnings));
				}
				return;
			}
			say(panel, (res.data && res.data.message) || 'Error');
		});
	}

	document.addEventListener('click', function (event) {
		var btn = event.target.closest ? event.target.closest('.cdreplies-btn') : null;
		if (!btn) {
			return;
		}
		event.preventDefault();
		var panel = btn.closest('.cdreplies-panel');
		var id = panel && panel.getAttribute('data-draft');
		if (!id || btn.disabled) {
			return;
		}
		btn.disabled = true;
		var action = btn.getAttribute('data-action');
		var done;
		if (action === 'publish' || action === 'approve_publish') {
			done = publish(panel, id, action === 'approve_publish', []);
		} else if (action === 'discard') {
			var sel = panel.querySelector('.cdreplies-discard-reason');
			done = post(id, 'discard', { reason: sel ? sel.value : '' }).then(function (res) {
				say(panel, res.ok ? cfg.i18n.done : (res.data && res.data.message) || 'Error');
			});
		} else if (action === 'regenerate' || action === 'anyway') {
			var note = window.prompt(cfg.i18n.notePrompt, '');
			if (note === null) {
				btn.disabled = false;
				return;
			}
			done = post(id, 'regenerate', { note: note.slice(0, 300), anyway: action === 'anyway' }).then(function (res) {
				say(panel, res.ok ? cfg.i18n.done : (res.data && res.data.message) || 'Error');
			});
		}
		if (done) {
			done.then(function () {
				btn.disabled = false;
			}, function () {
				btn.disabled = false;
				say(panel, 'Error');
			});
		}
	});
}());
