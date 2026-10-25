/*! WebiSearch product request form – no dependencies. */
(function () {
	'use strict';

	var cfg = window.aslRequest || {};
	if (!cfg.ajax || !window.FormData || !document.addEventListener) {
		return;
	}

	var FA = '۰۱۲۳۴۵۶۷۸۹';
	var AR = '٠١٢٣٤٥٦٧٨٩';

	function latinDigits(s) {
		return String(s).replace(/[۰-۹٠-٩]/g, function (c) {
			var i = FA.indexOf(c);
			return String(i > -1 ? i : AR.indexOf(c));
		});
	}

	function message(form, text, isError) {
		var el = form.querySelector('.asl-req__msg');
		if (!el) {
			return;
		}
		el.textContent = text || '';
		el.className = 'asl-req__msg' + (isError ? ' is-error' : '');
	}

	function onSubmit(e) {
		var form = e.target;
		if (!form || !form.classList || !form.classList.contains('asl-req__form')) {
			return;
		}
		e.preventDefault();

		var phone = form.querySelector('input[name="phone"]');
		var btn = form.querySelector('button[type="submit"]');
		var digits = latinDigits(phone ? phone.value : '').replace(/\D+/g, '');
		if (digits.length < 7 || digits.length > 15) {
			message(form, cfg.invalid, true);
			if (phone) {
				phone.focus();
			}
			return;
		}

		var fd = new FormData(form);
		fd.set('phone', latinDigits(phone.value));
		var label = btn ? btn.textContent : '';
		if (btn) {
			btn.disabled = true;
			btn.textContent = cfg.sending;
		}
		message(form, '', false);

		window.fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) {
				return r.json().catch(function () {
					// Not JSON: usually a firewall / security plugin page. Show the HTTP code to help support.
					return { success: false, data: { message: cfg.error + ' (HTTP ' + r.status + ')' } };
				});
			})
			.then(function (res) {
				if (res && res.success) {
					var done = form.parentNode.querySelector('.asl-req__done');
					if (done) {
						if (res.data && res.data.message) {
							done.lastChild.textContent = res.data.message;
						}
						done.hidden = false;
					}
					form.hidden = true;
					form.style.display = 'none';
					return;
				}
				message(form, (res && res.data && res.data.message) || cfg.error, true);
			})
			.catch(function () {
				message(form, cfg.error, true);
			})
			.then(function () {
				if (btn) {
					btn.disabled = false;
					btn.textContent = label;
				}
			});
	}

	document.addEventListener('submit', onSubmit);

	// Show Latin digits while typing a Persian-keyboard number.
	document.addEventListener('input', function (e) {
		var el = e.target;
		if (el && el.name === 'phone' && el.classList && el.classList.contains('asl-req__input')) {
			var v = latinDigits(el.value);
			if (v !== el.value) {
				el.value = v;
			}
		}
	});
})();
