/* WebiSearch admin – charts and small UI helpers (no jQuery needed). */
(function () {
	'use strict';

	// Confirm before destructive actions.
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('a.asl-confirm') : null;
		if (a && !window.confirm(a.getAttribute('data-confirm'))) {
			e.preventDefault();
		}
	});
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (f.classList && f.classList.contains('asl-confirm-form') && !window.confirm(f.getAttribute('data-confirm'))) {
			e.preventDefault();
		}
	});

	// Rule form: show the description matching the rule type.
	var type = document.getElementById('asl_type');
	if (type) {
		var sync = function () {
			var redirect = type.value === 'redirect';
			document.querySelectorAll('.asl-desc-redirect').forEach(function (el) { el.style.display = redirect ? '' : 'none'; });
			document.querySelectorAll('.asl-desc-synonym').forEach(function (el) { el.style.display = redirect ? 'none' : ''; });
			var target = document.getElementById('asl_target');
			if (target) {
				target.setAttribute('dir', redirect ? 'ltr' : 'auto');
				target.setAttribute('placeholder', redirect ? 'https://… /product-category/…' : '');
			}
		};
		type.addEventListener('change', sync);
		sync();
	}

	// Failed-searches review: run the check in small AJAX batches with a progress bar.
	var rv = window.aslReview;
	var runBtn = document.getElementById('asl-review-run');
	if (rv && runBtn) {
		var box = document.getElementById('asl-review-progress');
		var bar = box.querySelector('.asl-progress__bar');
		var txt = box.querySelector('.asl-progress__text');
		var total = 0;
		var step = function (start) {
			var fd = new FormData();
			fd.append('action', 'asl_review_batch');
			fd.append('nonce', rv.nonce);
			if (start) { fd.append('start', '1'); }
			fetch(rv.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.success) { throw new Error('failed'); }
					if (res.data.total) { total = res.data.total; }
					var doneCount = total - res.data.remaining;
					bar.style.width = (total ? Math.round(100 * doneCount / total) : 100) + '%';
					txt.textContent = rv.running.replace('%1$s', doneCount).replace('%2$s', total);
					if (res.data.remaining > 0) {
						step(false);
					} else {
						txt.textContent = rv.done;
						window.location.reload();
					}
				})
				.catch(function () { runBtn.disabled = false; txt.textContent = 'Error'; });
		};
		runBtn.addEventListener('click', function () {
			runBtn.disabled = true;
			box.hidden = false;
			step(true);
		});
		if (rv.auto) { runBtn.click(); }
	}

	var data = window.aslDashboard;
	if (!data || typeof window.ApexCharts === 'undefined') {
		return;
	}

	var css = getComputedStyle(document.body);
	var font = css.fontFamily || 'inherit';
	var palette = { primary: '#2271b1', bad: '#d63638', soft: '#72aee6', good: '#00a32a' };

	function fmtDate(iso, opts) {
		var d = new Date(iso + 'T12:00:00');
		try {
			var loc = data.jalali ? 'fa-IR-u-ca-persian' : (data.locale || undefined);
			return new Intl.DateTimeFormat(loc, opts).format(d);
		} catch (e) {
			return iso;
		}
	}

	function fmtNum(n) {
		try {
			return new Intl.NumberFormat(data.locale || undefined).format(n);
		} catch (e) {
			return String(n);
		}
	}

	function base(type, height) {
		return {
			chart: { type: type, height: height, fontFamily: font, toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 300 } },
			dataLabels: { enabled: false },
			grid: { borderColor: '#e5e5e5', strokeDashArray: 3 },
			tooltip: { y: { formatter: fmtNum } },
			noData: { text: data.i18n.noData }
		};
	}

	function render(id, options) {
		var el = document.getElementById(id);
		if (el) {
			new window.ApexCharts(el, options).render();
		}
	}

	// Daily trend: total and failed searches.
	var daily = base('area', 300);
	daily.series = [
		{ name: data.i18n.searches, data: data.daily.map(function (d) { return d.count; }) },
		{ name: data.i18n.noResults, data: data.daily.map(function (d) { return d.zero; }) }
	];
	daily.colors = [palette.primary, palette.bad];
	daily.stroke = { curve: 'smooth', width: [2.5, 2] };
	daily.fill = { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } };
	daily.xaxis = {
		categories: data.daily.map(function (d) { return fmtDate(d.date, { month: 'short', day: 'numeric' }); }),
		tickAmount: Math.min(12, data.daily.length),
		labels: { rotate: 0, hideOverlappingLabels: true }
	};
	daily.yaxis = { min: 0, forceNiceScale: true, labels: { formatter: function (v) { return fmtNum(Math.round(v)); } } };
	daily.tooltip = { x: { formatter: function (v, o) { return fmtDate(data.daily[o.dataPointIndex].date, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }); } }, y: { formatter: fmtNum } };
	daily.legend = { position: 'top', horizontalAlign: data.rtl ? 'right' : 'left' };
	render('asl-chart-daily', daily);

	// Top terms: horizontal bars keep long Persian phrases readable (v2 rotated them 45°).
	var top = base('bar', Math.max(260, data.top.length * 34));
	top.series = [{ name: data.i18n.searches, data: data.top.map(function (t) { return +t.count; }) }];
	top.xaxis = { categories: data.top.map(function (t) { return t.term; }), labels: { formatter: function (v) { return fmtNum(v); } } };
	top.yaxis = { opposite: !!data.rtl, labels: { maxWidth: 220 } };
	top.plotOptions = { bar: { horizontal: true, borderRadius: 3, barHeight: '65%', dataLabels: { position: 'center' } } };
	top.colors = [function (o) { return +data.top[o.dataPointIndex].zero === +data.top[o.dataPointIndex].count ? palette.bad : palette.primary; }];
	top.dataLabels = { enabled: true, formatter: fmtNum, style: { colors: ['#fff'] } };
	render('asl-chart-top', top);

	// Hour of day.
	var hours = base('bar', 240);
	hours.series = [{ name: data.i18n.searches, data: data.hours }];
	hours.xaxis = { categories: data.hours.map(function (_, i) { return fmtNum(i); }), title: { text: data.i18n.hour } };
	hours.plotOptions = { bar: { borderRadius: 2, columnWidth: '70%' } };
	hours.colors = [palette.soft];
	hours.yaxis = { labels: { formatter: function (v) { return fmtNum(Math.round(v)); } } };
	render('asl-chart-hours', hours);

	// Sources.
	var src = base('donut', 260);
	src.series = data.sources.map(function (s) { return s.count; });
	src.labels = data.sources.map(function (s) { return s.label; });
	src.colors = [palette.primary, palette.soft, '#dba617', palette.good, '#8c8f94'];
	src.legend = { position: 'bottom' };
	src.dataLabels = { enabled: true };
	render('asl-chart-sources', src);
})();
