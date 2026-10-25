/*! WebiSearch click tracker – no dependencies, ~1.5 KB minified. */
(function () {
	'use strict';

	var cfg = window.aslFront || {};
	if (!cfg.ajax || !window.URL || !document.addEventListener) {
		return;
	}

	var EXCLUDED = 'header,nav,footer,aside,.widget,.sidebar,.pagination,.page-numbers,.woocommerce-pagination,.breadcrumb,.woocommerce-breadcrumb,.wd-breadcrumbs,.widget_layered_nav,.wd-filter-wrapper,[role="navigation"]';
	var sent = {};

	function sid() {
		var m = document.cookie.match(/(?:^|;\s*)asl_sid=([^;]+)/);
		return m ? decodeURIComponent(m[1]) : '';
	}

	function closest(el, sel) {
		if (!sel || !el || !el.closest) {
			return null;
		}
		try {
			return el.closest(sel);
		} catch (e) {
			return null;
		}
	}

	function parse(href) {
		try {
			var u = new URL(href, window.location.href);
			if (u.origin !== window.location.origin || !/^https?:$/.test(u.protocol)) {
				return null;
			}
			if (u.searchParams.has('s') || u.searchParams.has('add-to-cart') || u.searchParams.has('add_to_wishlist')) {
				return null;
			}
			if (/\/(wp-admin|wp-login\.php|cart|checkout|my-account)\b/.test(u.pathname)) {
				return null;
			}
			u.hash = '';
			return u;
		} catch (e) {
			return null;
		}
	}

	// A live-search dropdown container (never the whole page: <body class="search-results"> must not count).
	function liveContainer(a) {
		var c = closest(a, cfg.live);
		while (c && (c === document.body || c === document.documentElement || c.tagName === 'MAIN')) {
			c = closest(c.parentElement, cfg.live);
		}
		return c;
	}

	function qualifies(a, live) {
		var u = parse(a.getAttribute('href'));
		if (!u) {
			return null;
		}
		if (!live && closest(a, EXCLUDED)) {
			return null;
		}
		return u;
	}

	// 1-based rank of the clicked result among the distinct result links of the container.
	function position(ctx, a, live) {
		var links = ctx.querySelectorAll('a[href]');
		var seen = {};
		var rank = 0;
		for (var i = 0; i < links.length; i++) {
			var u = qualifies(links[i], live);
			if (!u) {
				continue;
			}
			var key = u.origin + u.pathname;
			if (!seen[key]) {
				seen[key] = 1;
				rank++;
			}
			if (links[i] === a) {
				return rank;
			}
		}
		return 0;
	}

	function send(data) {
		var fd = new FormData();
		for (var k in data) {
			if (Object.prototype.hasOwnProperty.call(data, k)) {
				fd.append(k, data[k]);
			}
		}
		if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajax, fd)) {
			return;
		}
		if (window.fetch) {
			window.fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true });
		}
	}

	function onClick(e) {
		if (e.type === 'auxclick' && e.button !== 1) {
			return;
		}
		var a = closest(e.target, 'a[href]');
		var id = sid();
		if (!a || !id) {
			return;
		}
		var live = liveContainer(a);
		var u = qualifies(a, live);
		if (!u) {
			return;
		}
		var ctx = live;
		if (!ctx) {
			if (!cfg.isSearch) {
				return;
			}
			ctx = closest(a, cfg.results) || document.body;
		}
		var pos = position(ctx, a, live);
		if (!live && cfg.isSearch && pos > 0) {
			pos += (parseInt(cfg.paged, 10) - 1) * parseInt(cfg.perPage, 10);
		}
		var key = id + '|' + u.href;
		if (sent[key]) {
			return;
		}
		sent[key] = 1;
		send({ action: 'asl_click', sid: id, url: u.href, pos: pos });
	}

	document.addEventListener('click', onClick, true);
	document.addEventListener('auxclick', onClick, true);
})();
