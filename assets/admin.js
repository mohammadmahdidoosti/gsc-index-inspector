/* GSC Index Inspector — admin UI (vanilla JS) */
(function () {
	'use strict';

	if (typeof window.GSCII === 'undefined') { return; }
	var cfg = window.GSCII;
	var t = cfg.i18n;

	/* ---------- helpers ---------- */

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) { v.forEach(function (i) { body.append(k + '[]', i); }); }
			else { body.append(k, v); }
		});
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) {
				return r.json().catch(function () { throw new Error('پاسخ نامعتبر از سرور (HTTP ' + r.status + ')'); });
			})
			.then(function (json) {
				if (json && json.data) { syncQuota(json.data); }
				if (!json || !json.success) {
					throw new Error(json && json.data && json.data.message ? json.data.message : t.error);
				}
				return json.data;
			});
	}

	function toast(msg, type) {
		var box = $('.gscii-toasts');
		if (!box) {
			box = document.createElement('div');
			box.className = 'gscii-toasts';
			document.body.appendChild(box);
		}
		var el = document.createElement('div');
		el.className = 'gscii-toast' + (type ? ' is-' + type : '');
		el.setAttribute('role', type === 'bad' ? 'alert' : 'status');
		el.textContent = msg;
		box.appendChild(el);
		setTimeout(function () {
			el.classList.add('is-out');
			setTimeout(function () { el.remove(); }, 300);
		}, type === 'bad' ? 7000 : 4000);
	}

	function loading(btn, on) {
		if (!btn) { return; }
		btn.classList.toggle('is-loading', !!on);
		btn.disabled = !!on;
		btn.setAttribute('aria-busy', on ? 'true' : 'false');
	}

	function set(key, value, asHtml) {
		$$('[data-gscii="' + key + '"]').forEach(function (el) {
			if (asHtml) { el.innerHTML = value; } else { el.textContent = value; }
		});
	}

	function syncQuota(d) {
		if (d.quota_used !== undefined) { set('quota-used', d.quota_used); }
		if (d.quota_pct !== undefined) {
			$$('[data-gscii="quota-bar"]').forEach(function (el) { el.style.width = d.quota_pct + '%'; });
		}
	}

	function errorCard(msg) {
		var d = document.createElement('div');
		d.className = 'gscii-result gscii-tone-fail';
		d.innerHTML = '<div class="gscii-result-hero"><span class="gscii-result-ico">!</span><div class="gscii-result-text"><strong>بررسی انجام نشد</strong><span></span></div></div>';
		d.querySelector('.gscii-result-text span').textContent = msg;
		return d;
	}

	/* ---------- single inspect ---------- */

	var form = $('#gscii-inspect-form');
	var urlInput = $('#gscii-url');
	var slot = $('#gscii-result');

	function inspect(url, force, btn) {
		slot.innerHTML = '';
		loading(btn, true);
		return post('gscii_inspect', { url: url, force: force ? 1 : 0 })
			.then(function (d) {
				slot.innerHTML = d.html;
				if (d.cached) { toast('نتیجه از کش نمایش داده شد. برای درخواست تازه گزینه‌ی «تازه» را روشن کنید.'); }
			})
			.catch(function (e) { slot.appendChild(errorCard(e.message)); })
			.finally(function () { loading(btn, false); });
	}

	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var url = urlInput.value.trim();
			// اجازه‌ی وارد کردن مسیر نسبی مثل /about
			if (url && url.charAt(0) === '/') { url = location.origin + url; urlInput.value = url; }
			inspect(url, $('#gscii-force').checked, form.querySelector('button[type=submit]'));
		});

		// اگر کاربر مسیر نسبی بنویسد، input[type=url] اعتبارسنجی نکند
		urlInput.addEventListener('input', function () {
			urlInput.type = urlInput.value.charAt(0) === '/' ? 'text' : 'url';
		});
		urlInput.focus();
	}

	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.gscii-reinspect');
		if (!b || !form) { return; }
		urlInput.value = b.dataset.url;
		window.scrollTo({ top: 0, behavior: 'smooth' });
		b.classList.add('is-loading');
		inspect(b.dataset.url, true, form.querySelector('button[type=submit]'))
			.finally(function () { b.classList.remove('is-loading'); });
	});

	/* ---------- posts list column ---------- */

	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.gscii-col-check');
		if (!b) { return; }
		e.preventDefault();
		if (b.classList.contains('is-loading')) { return; }
		var badge = b.parentNode.querySelector('.gscii-col-badge');
		b.classList.add('is-loading');
		post('gscii_inspect', b.dataset.term ? { term_id: b.dataset.term } : { post_id: b.dataset.post })
			.then(function (d) {
				badge.innerHTML = d.badge;
				toast(d.label, d.verdict === 'PASS' ? 'ok' : (d.verdict === 'FAIL' ? 'bad' : ''));
			})
			.catch(function (err) { toast(err.message, 'bad'); })
			.finally(function () { b.classList.remove('is-loading'); });
	});

	/* ---------- meta box ---------- */

	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.gscii-mb-check');
		if (!b) { return; }
		var box = b.closest('.gscii-mb');
		loading(b, true);
		post('gscii_inspect', { post_id: b.dataset.post })
			.then(function (d) {
				$('.gscii-mb-result', box).innerHTML = d.html;
				$('span', b).textContent = t.recheck;
			})
			.catch(function (err) { toast(err.message, 'bad'); })
			.finally(function () { loading(b, false); });
	});

	/* ---------- settings: drag & drop key ---------- */

	var drop = $('#gscii-drop');
	var fileInput = $('#gscii-file');
	var sa = $('#gscii-sa');
	var preview = $('#gscii-drop-preview');

	function readKey(file) {
		if (!file) { return; }
		var reader = new FileReader();
		reader.onload = function () {
			var text = String(reader.result || '');
			var data;
			try { data = JSON.parse(text); } catch (err) { data = null; }
			preview.hidden = false;
			if (!data || !data.client_email || !data.private_key) {
				preview.className = 'gscii-drop-preview is-bad';
				preview.textContent = t.badJson;
				sa.value = '';
				return;
			}
			sa.value = text;
			preview.className = 'gscii-drop-preview';
			preview.innerHTML = '✓ <span>کلید آماده‌ی ذخیره است:</span> <code dir="ltr"></code>';
			preview.querySelector('code').textContent = data.client_email;
			toast('کلید خوانده شد؛ «ذخیره‌ی تنظیمات» را بزنید.', 'ok');
		};
		reader.readAsText(file);
	}

	if (drop) {
		drop.addEventListener('click', function () { fileInput.click(); });
		drop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
		});
		fileInput.addEventListener('change', function () { readKey(fileInput.files[0]); });
		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
		});
		drop.addEventListener('drop', function (e) {
			readKey(e.dataTransfer.files && e.dataTransfer.files[0]);
		});
	}

	// fill property suggestions
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-fill]');
		if (!b) { return; }
		var input = $(b.dataset.fill);
		if (input) { input.value = b.dataset.value; input.focus(); }
	});

	// copy to clipboard
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-copy]');
		if (!b) { return; }
		var src = $(b.dataset.copy);
		if (!src) { return; }
		var text = src.textContent.trim();
		var done = function () { toast(t.copied, 'ok'); };
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var ta = document.createElement('textarea');
			ta.value = text; document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); done(); } catch (err) {}
			ta.remove();
		}
	});

	// test connection
	var testBtn = $('#gscii-test');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var out = $('#gscii-test-result');
			out.textContent = '';
			out.className = 'gscii-test-result';
			loading(testBtn, true);
			post('gscii_test', {})
				.then(function (d) {
					out.textContent = '✓ ' + d.message;
					out.classList.add('is-ok');
					toast(d.message, 'ok');
					setTimeout(function () { location.reload(); }, 1600);
				})
				.catch(function (err) {
					out.textContent = err.message;
					out.classList.add('is-bad');
				})
				.finally(function () { loading(testBtn, false); });
		});
	}

	/* ---------- bulk queue ---------- */

	var progress = $('#gscii-progress');
	var runBtn = $('#gscii-run');
	var clearBtn = $('#gscii-clear');
	var enqueueForm = $('#gscii-enqueue-form');
	var running = false;

	function applyQueue(d) {
		set('done', d.done);
		set('remaining', d.remaining);
		set('quota-left', d.quota);
		set('queue-state', running ? t.running : d.state_label);
		set('last', d.last_html, true);
		if (d.priority_html !== undefined) { set('priority', d.priority_html, true); }
		set('queue-count-tab', d.remaining);
		$$('[data-gscii="queue-count-tab"]').forEach(function (el) { el.hidden = !d.remaining_raw; });
		$$('[data-gscii="progress-bar"]').forEach(function (el) { el.style.width = d.pct + '%'; });
		if (progress) { progress.dataset.state = d.remaining_raw ? 'queued' : 'idle'; }
		if (runBtn) { runBtn.disabled = !d.remaining_raw && !running; }
		if (clearBtn) { clearBtn.disabled = !d.remaining_raw; }
	}

	function stopRun() {
		running = false;
		if (progress) { progress.classList.remove('is-running'); }
	}

	function loop() {
		if (!running) { return; }
		post('gscii_queue_run', {})
			.then(function (d) {
				applyQueue(d);
				if (d.fatal) {
					stopRun(); applyQueue(d);
					toast((d.errors && d.errors[0]) || t.error, 'bad');
					return;
				}
				if (d.errors && d.errors.length) { toast(d.errors[0], 'bad'); }
				if (!d.remaining_raw) {
					stopRun(); applyQueue(d);
					toast(t.done, 'ok');
					return;
				}
				if (d.quota_left <= 0) {
					stopRun(); applyQueue(d);
					toast('سهمیه‌ی امروز تمام شد.', 'bad');
					return;
				}
				setTimeout(loop, d.locked ? 5000 : 1200);
			})
			.catch(function (err) {
				stopRun();
				toast(err.message, 'bad');
			});
	}

	if (runBtn) {
		runBtn.addEventListener('click', function () {
			if (running) { stopRun(); set('queue-state', '—'); post('gscii_queue_status', {}).then(applyQueue); return; }
			running = true;
			progress.classList.add('is-running');
			set('queue-state', t.running);
			loop();
		});
		window.addEventListener('beforeunload', function (e) {
			if (running) { e.preventDefault(); e.returnValue = ''; }
		});
	}

	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			if (!window.confirm(t.confirm)) { return; }
			stopRun();
			post('gscii_queue_clear', {}).then(function (d) { applyQueue(d); toast('صف خالی شد.'); })
				.catch(function (err) { toast(err.message, 'bad'); });
		});
	}

	if (enqueueForm) {
		enqueueForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = enqueueForm.querySelector('button[type=submit]');
			var pts = $$('input[name="post_types[]"]:checked', enqueueForm).map(function (i) { return i.value; });
			var taxes = $$('input[name="taxonomies[]"]:checked', enqueueForm).map(function (i) { return i.value; });
			var scope = $('input[name=scope]:checked', enqueueForm);
			loading(btn, true);
			post('gscii_enqueue', { post_types: pts, taxonomies: taxes, scope: scope ? scope.value : 'stale' })
				.then(function (d) {
					applyQueue(d);
					toast(d.message, 'ok');
					if (progress) { progress.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
				})
				.catch(function (err) { toast(err.message, 'bad'); })
				.finally(function () { loading(btn, false); });
		});
	}

	if (progress) {
		setInterval(function () {
			if (document.hidden || running) { return; }
			post('gscii_queue_status', {}).then(applyQueue).catch(function () {});
		}, 20000);
	}
})();
