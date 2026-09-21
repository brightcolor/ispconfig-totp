/*
 * totpauth.js — authenticator app in the ISPConfig profile.
 *
 * ISPConfig loads every file in web/js/js.d/ on every panel page, whatever
 * the theme. This script watches the page content and adds:
 *
 *   - in Tools -> Settings (tools/user_settings.php) a row "Authenticator
 *     app" under the two-factor select, with a modal to set it up (QR code,
 *     confirming code, recovery codes) and one to turn it off;
 *   - in System -> Users (admin/users_edit.php) a button to reset the app
 *     of that account, for admins.
 *
 * All state comes from ../totpauth/api.php; texts come with its status
 * answer in the panel language.
 */
(function ($) {
	'use strict';

	if (!$ || window.totpauthLoaded) {
		return;
	}
	window.totpauthLoaded = true;

	var API = 'totpauth/api.php';
	var csrf = null;
	var T = {};

	/* Used before the first answer arrives, or when none arrives. */
	var FALLBACK = {
		totpauth_err_network_txt: 'Der Server war nicht erreichbar. Prüf deine Verbindung und versuch es noch einmal.',
		totpauth_err_unexpected_txt: 'Der Server hat unerwartet geantwortet. Lade die Seite neu und versuch es noch einmal. Bleibt der Fehler, sag dem Administrator Bescheid.'
	};

	function t(key) {
		return T[key] || FALLBACK[key] || key;
	}

	/* ---- talking to the API ----------------------------------------- */

	function call(action, data) {
		var post = action !== 'status';
		var payload = $.extend({ action: action }, data || {});
		if (post && csrf) {
			payload._csrf_id = csrf.id;
			payload._csrf_key = csrf.key;
		}
		return $.ajax({
			url: API,
			type: post ? 'POST' : 'GET',
			data: payload,
			dataType: 'text',
			cache: false
		}).then(function (body) {
			return parse(body);
		}, function (xhr) {
			// Error statuses still carry JSON from the API; anything else
			// (proxy page, timeout, no network) gets a plain sentence.
			var parsed = xhr && xhr.responseText ? parse(xhr.responseText) : null;
			if (parsed && parsed.error) {
				return $.Deferred().reject(parsed.error).promise();
			}
			var key = xhr && xhr.status === 0 ? 'totpauth_err_network_txt' : 'totpauth_err_unexpected_txt';
			var suffix = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
			return $.Deferred().reject(t(key) + suffix).promise();
		}).then(function (json) {
			if (!json) {
				return $.Deferred().reject(t('totpauth_err_unexpected_txt')).promise();
			}
			if (!json.ok) {
				return $.Deferred().reject(json.error || t('totpauth_err_unexpected_txt')).promise();
			}
			return json;
		});
	}

	function parse(body) {
		var json = null;
		try {
			json = JSON.parse(body);
		} catch (e) {
			return null;
		}
		if (json && json.csrf) {
			csrf = json.csrf;
		}
		if (json && json.texts) {
			T = json.texts;
		}
		return json;
	}

	/* ---- small DOM helpers ------------------------------------------- */

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) {
			node.className = cls;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function button(cls, text) {
		var b = el('button', 'btn ' + cls, text);
		b.type = 'button';
		return b;
	}

	function showError(box, message) {
		box.textContent = message;
		box.style.display = message ? '' : 'none';
	}

	function injectStyle() {
		if (document.getElementById('totpauth-style')) {
			return;
		}
		var css = [
			'.totpauth-state{display:inline-block;margin-right:12px;font-weight:600}',
			'.totpauth-state.is-on{color:var(--bc-ok-text,#3c763d)}',
			'.totpauth-row .help-block{margin-top:6px}',
			'.totpauth-qr{display:inline-block;padding:12px;background:#fff;border-radius:8px;border:1px solid var(--bc-line,#ddd)}',
			'.totpauth-qr svg{display:block;width:196px;height:196px}',
			'.totpauth-secret{display:block;margin:6px 0 0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;letter-spacing:.08em;word-break:break-all;user-select:all}',
			'.totpauth-codes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 18px;margin:12px 0;padding:14px 16px;border:1px solid var(--bc-line,#ddd);border-radius:8px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:15px;list-style:none}',
			'.totpauth-code-input{max-width:220px;font-size:20px;letter-spacing:.2em;text-align:center}',
			'.totpauth-modal .modal-body > p{margin-bottom:12px}',
			'.totpauth-modal .alert{margin:12px 0 0}',
			'.totpauth-actions{display:flex;gap:8px;flex-wrap:wrap}'
		].join('\n');
		var style = el('style');
		style.id = 'totpauth-style';
		style.textContent = css;
		document.head.appendChild(style);
	}

	/* ---- modal ------------------------------------------------------- */

	var modal = null;

	/* One Bootstrap modal, refilled for each dialog. */
	function openModal(title, body, footer, focusSelector) {
		injectStyle();
		if (!modal) {
			modal = el('div', 'modal fade totpauth-modal');
			modal.id = 'totpauth-modal';
			modal.setAttribute('tabindex', '-1');
			modal.setAttribute('role', 'dialog');
			modal.setAttribute('aria-modal', 'true');
			modal.setAttribute('aria-labelledby', 'totpauth-modal-title');
			modal.innerHTML = '<div class="modal-dialog" role="document"><div class="modal-content">'
				+ '<div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>'
				+ '<h4 class="modal-title" id="totpauth-modal-title"></h4></div>'
				+ '<div class="modal-body"></div><div class="modal-footer"></div></div></div>';
			document.body.appendChild(modal);
			$(modal).on('shown.bs.modal', function () {
				var target = modal.getAttribute('data-focus');
				var node = target ? modal.querySelector(target) : null;
				(node || modal.querySelector('.modal-footer .btn')).focus();
			});
		}
		modal.querySelector('.close').setAttribute('aria-label', t('totpauth_close_txt'));
		modal.querySelector('.modal-title').textContent = title;
		fill(modal.querySelector('.modal-body'), body);
		fill(modal.querySelector('.modal-footer'), footer);
		modal.setAttribute('data-focus', focusSelector || '');
		if (!$(modal).hasClass('in')) {
			$(modal).modal('show');
		} else {
			var node = focusSelector ? modal.querySelector(focusSelector) : null;
			(node || modal.querySelector('.modal-footer .btn')).focus();
		}
	}

	function fill(target, nodes) {
		target.innerHTML = '';
		nodes.forEach(function (n) {
			target.appendChild(n);
		});
	}

	function closeModal() {
		if (modal) {
			$(modal).modal('hide');
		}
	}

	function codeInput(id) {
		var input = el('input', 'form-control totpauth-code-input');
		input.id = id;
		input.type = 'text';
		input.setAttribute('inputmode', 'numeric');
		input.setAttribute('autocomplete', 'one-time-code');
		input.setAttribute('maxlength', '20');
		input.setAttribute('aria-label', t('totpauth_code_label_txt'));
		input.placeholder = t('totpauth_code_label_txt');
		return input;
	}

	function alertBox() {
		var box = el('div', 'alert alert-danger');
		box.setAttribute('role', 'alert');
		box.style.display = 'none';
		return box;
	}

	/* ---- setup ------------------------------------------------------- */

	function startSetup(refresh) {
		call('begin').then(function (res) {
			showQr(res, refresh);
		}, function (message) {
			window.alert(message);
		});
	}

	function showQr(res, refresh) {
		var qrBox = el('div', 'totpauth-qr');
		try {
			var qr = window.qrcode(0, 'M');
			qr.addData(res.uri);
			qr.make();
			// createSvgTag builds the markup from the link we just received.
			qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
			qrBox.setAttribute('role', 'img');
			qrBox.setAttribute('aria-label', 'QR-Code');
		} catch (e) {
			qrBox.textContent = res.uri;
		}
		var manual = el('p', '', t('totpauth_manual_txt'));
		var secret = el('code', 'totpauth-secret', res.secret);
		manual.appendChild(secret);

		var next = button('btn-primary formbutton-success', t('totpauth_next_txt'));
		next.addEventListener('click', function () {
			askCode(res, refresh);
		});
		var cancel = button('btn-default formbutton-default', t('totpauth_close_txt'));
		cancel.setAttribute('data-dismiss', 'modal');

		openModal(t('totpauth_modal_title_txt'), [el('p', '', t('totpauth_step1_txt')), qrBox, manual], [cancel, next]);
	}

	function askCode(res, refresh) {
		var input = codeInput('totpauth-setup-code');
		var box = alertBox();
		var confirm = button('btn-primary formbutton-success', t('totpauth_confirm_txt'));
		var back = button('btn-default formbutton-default', t('totpauth_back_txt'));
		back.addEventListener('click', function () {
			showQr(res, refresh);
		});

		function send() {
			confirm.disabled = true;
			call('confirm', { code: input.value }).then(function (ok) {
				showCodes(ok.codes, refresh);
			}, function (message) {
				confirm.disabled = false;
				showError(box, message);
				input.select();
			});
		}
		confirm.addEventListener('click', send);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				send();
			}
		});

		var label = el('label', '', t('totpauth_step2_txt'));
		label.setAttribute('for', input.id);
		openModal(t('totpauth_modal_title_txt'), [label, input, box], [back, confirm], '#totpauth-setup-code');
	}

	function showCodes(codes, refresh) {
		var list = el('ul', 'totpauth-codes');
		codes.forEach(function (c) {
			list.appendChild(el('li', '', c));
		});
		var text = codes.join('\n') + '\n';

		var copy = button('btn-default formbutton-default', t('totpauth_copy_txt'));
		copy.addEventListener('click', function () {
			var done = function () {
				copy.textContent = t('totpauth_copied_txt');
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () {
					selectList(list);
				});
			} else {
				selectList(list);
			}
		});
		var save = button('btn-default formbutton-default', t('totpauth_download_txt'));
		save.addEventListener('click', function () {
			var blob = new Blob([text], { type: 'text/plain' });
			var a = el('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'ispconfig-wiederherstellungscodes-' + location.hostname + '.txt';
			document.body.appendChild(a);
			a.click();
			setTimeout(function () {
				URL.revokeObjectURL(a.href);
				a.remove();
			}, 0);
		});
		var actions = el('div', 'totpauth-actions');
		actions.appendChild(copy);
		actions.appendChild(save);

		var done = button('btn-primary formbutton-success', t('totpauth_done_txt'));
		done.addEventListener('click', function () {
			closeModal();
			refresh();
		});
		openModal(t('totpauth_modal_title_txt'), [el('p', '', t('totpauth_step3_txt')), list, actions], [done]);
		$(modal).one('hidden.bs.modal', refresh);
	}

	function selectList(node) {
		var range = document.createRange();
		range.selectNodeContents(node);
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
	}

	/* ---- turn off ---------------------------------------------------- */

	function startDisable(refresh) {
		var input = codeInput('totpauth-disable-code');
		var box = alertBox();
		var ok = button('btn-danger formbutton-danger', t('totpauth_disable_txt'));
		var cancel = button('btn-default formbutton-default', t('totpauth_close_txt'));
		cancel.setAttribute('data-dismiss', 'modal');

		function send() {
			ok.disabled = true;
			call('disable', { code: input.value }).then(function () {
				closeModal();
				refresh();
			}, function (message) {
				ok.disabled = false;
				showError(box, message);
				input.select();
			});
		}
		ok.addEventListener('click', send);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				send();
			}
		});
		var label = el('label', '', t('totpauth_disable_desc_txt'));
		label.setAttribute('for', input.id);
		openModal(t('totpauth_disable_title_txt'), [label, input, box], [cancel, ok], '#totpauth-disable-code');
	}

	/* ---- profile row ------------------------------------------------- */

	function profileRow(select) {
		var group = $(select).closest('.form-group')[0];
		if (!group || group.parentNode.querySelector('.totpauth-row')) {
			return;
		}
		var row = el('div', 'form-group totpauth-row');
		group.parentNode.insertBefore(row, group.nextSibling);

		function render(status) {
			row.innerHTML = '';
			var label = el('label', 'col-sm-3 control-label', t('totpauth_row_label_txt'));
			var col = el('div', 'col-sm-9');
			var state = el('span', 'totpauth-state' + (status.enabled ? ' is-on' : ''),
				status.enabled ? t('totpauth_state_on_txt') : t('totpauth_state_off_txt'));
			col.appendChild(state);

			var act = button('btn-default formbutton-default',
				status.enabled ? t('totpauth_disable_txt') : t('totpauth_setup_txt'));
			act.addEventListener('click', function () {
				if (status.enabled) {
					startDisable(load);
				} else {
					startSetup(load);
				}
			});
			col.appendChild(act);

			var notes = [];
			if (status.enabled) {
				notes.push(t('totpauth_recovery_left_txt').replace('%d', String(status.recovery_left)));
				notes.push(t('totpauth_email_locked_txt'));
			}
			if (status.force_change) {
				notes.push(t('totpauth_force_change_txt'));
			}
			notes.forEach(function (n) {
				col.appendChild(el('p', 'help-block', n));
			});
			row.appendChild(label);
			row.appendChild(col);

			// The email code stays off while the app is active; the plugin
			// enforces it on save, the select shows it.
			if (status.enabled && select.value !== 'none') {
				select.value = 'none';
				$(select).trigger('change');
			}
			select.setAttribute('data-totpauth-lock', status.enabled ? '1' : '');
		}

		function load() {
			call('status').then(render, function (message) {
				row.innerHTML = '';
				row.appendChild(el('label', 'col-sm-3 control-label', t('totpauth_row_label_txt')));
				var col = el('div', 'col-sm-9');
				col.appendChild(el('p', 'help-block text-danger', message));
				row.appendChild(col);
			});
		}

		$(select).on('change.totpauth', function () {
			if (this.getAttribute('data-totpauth-lock') === '1' && this.value !== 'none') {
				this.value = 'none';
				window.alert(t('totpauth_email_locked_txt'));
			}
		});
		load();
	}

	/* ---- admin: System -> Users -------------------------------------- */

	function adminButton(form) {
		var idField = form.querySelector('input[name="id"]');
		var uid = idField ? parseInt(idField.value, 10) : 0;
		var bar = form.querySelector('.clear .right');
		if (!uid || !bar || bar.querySelector('.totpauth-reset')) {
			return;
		}
		call('status').then(function (status) {
			if (!status.is_admin) {
				return null;
			}
			return call('admin_status', { userid: uid });
		}).then(function (res) {
			if (!res || !res.enabled || bar.querySelector('.totpauth-reset')) {
				return;
			}
			var reset = button('btn-default formbutton-default totpauth-reset', t('totpauth_admin_reset_txt'));
			reset.addEventListener('click', function () {
				if (!window.confirm(t('totpauth_admin_confirm_txt'))) {
					return;
				}
				call('admin_reset', { userid: uid }).then(function (ok) {
					window.alert(ok.message);
					reset.remove();
				}, function (message) {
					window.alert(message);
				});
			});
			bar.insertBefore(reset, bar.firstChild);
		}, function () {
			/* No button when the status is unknown; the rest of the form works. */
		});
	}

	/* ---- watching the content ---------------------------------------- */

	function scan() {
		var content = document.getElementById('pageContent');
		if (!content) {
			return;
		}
		var select = content.querySelector('select#otp_type');
		if (select && content.querySelector('[data-form-action^="tools/user_settings.php"]')) {
			profileRow(select);
		}
		var adminForm = content.querySelector('[data-form-action^="admin/users_edit.php"]');
		if (adminForm) {
			adminButton(content);
		}
	}

	$(function () {
		var content = document.getElementById('pageContent');
		if (!content || !window.MutationObserver) {
			return;
		}
		new MutationObserver(scan).observe(content, { childList: true });
		scan();
	});
})(window.jQuery);
