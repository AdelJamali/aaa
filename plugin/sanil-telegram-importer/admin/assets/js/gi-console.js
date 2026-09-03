/**
 * GOLDEN IMPORTER — CONSOLE UI (gi-console.js)
 * Presentation-only helpers. No AJAX, no polling, no backend calls.
 * 10.11-UX
 */
(function () {
	'use strict';

	var root = document.documentElement;
	var consoleEl = typeof document !== 'undefined' ? document.querySelector('.gi-console') : null;
	var LS_KEY = 'gi_theme';

	/* ── Reduced motion ─────────────────────────────────────────────── */
	function reducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	/* ── Theme (auto → light → dark cycle, persisted locally) ────────── */
	function currentTheme() {
		var forced = localStorage.getItem(LS_KEY);
		if (forced === 'light' || forced === 'dark') { return forced; }
		if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) { return 'dark'; }
		return 'light';
	}
	function applyTheme(theme) {
		if (!consoleEl) { return; }
		consoleEl.classList.remove('gi-theme-light', 'gi-theme-dark');
		consoleEl.classList.add('gi-theme-' + theme);
		if (theme === 'light') { root.classList.add('gi-forced-light'); }
	}
	function initTheme() {
		if (!consoleEl) { return; }
		var forced = localStorage.getItem(LS_KEY);
		var btn = document.getElementById('gi-theme-btn');
		applyTheme(forced || currentTheme());
		if (btn) {
			btn.addEventListener('click', function () {
				var next = currentTheme() === 'dark' ? 'light' : 'dark';
				localStorage.setItem(LS_KEY, next);
				applyTheme(next);
				if (next === 'light') { root.classList.add('gi-forced-light'); } else { root.classList.remove('gi-forced-light'); }
				btn.setAttribute('aria-label', next === 'dark' ? 'حالت روشن' : 'حالت تیره');
			});
		}
	}

	/* ── Subtle number transition (subtle highlight + count-up) ──────── */
	function setNumber(el, value) {
		if (!el) { return; }
		var text = String(value);
		if (el.textContent === text) { return; }
		el.textContent = text;
		if (reducedMotion()) { return; }
		el.classList.remove('gi-flash');
		/* reflow to restart animation */
		void el.offsetWidth;
		el.classList.add('gi-flash');
	}
	window.GI = window.GI || {};
	window.GI.setNumber = setNumber;

	/* ── Bottom sheet: open/close + focus trap + Escape + swipe ──────── */
	function trapFocus(sheet, e) {
		if (e.key !== 'Tab') { return; }
		var focusables = sheet.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		if (!focusables.length) { return; }
		var first = focusables[0];
		var last = focusables[focusables.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault(); last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault(); first.focus();
		}
	}

	function closeSheet(sheet, overlay) {
		if (!sheet || !sheet.classList.contains('is-open')) { return; }
		sheet.classList.remove('is-open');
		if (overlay) { overlay.classList.remove('is-open'); }
		sheet.setAttribute('aria-hidden', 'true');
		if (sheet.__lastFocus) { try { sheet.__lastFocus.focus(); } catch (err) { /* noop */ } }
		document.removeEventListener('keydown', sheet.__onKey);
		document.removeEventListener('keydown', sheet.__trap);
	}

	function openSheet(sheet, overlay) {
		if (!sheet) { return; }
		sheet.__lastFocus = document.activeElement;
		sheet.setAttribute('aria-hidden', 'false');
		if (overlay) { overlay.classList.add('is-open'); }
		sheet.classList.add('is-open');

		sheet.__onKey = function (e) {
			if (e.key === 'Escape') { closeSheet(sheet, overlay); }
		};
		sheet.__trap = function (e) { trapFocus(sheet, e); };
		document.addEventListener('keydown', sheet.__onKey);
		document.addEventListener('keydown', sheet.__trap);

		var t = sheet.querySelector('.gi-sheet-title, button, a, input, select, textarea');
		if (t) { setTimeout(function () { t.focus(); }, 60); }
	}

	/* swipe-to-close on the handle area (touch) */
	function initSwipe(sheet) {
		var startY = null;
		var h = sheet.querySelector('.gi-sheet-handle, .gi-sheet-head');
		if (!h) { return; }
		h.style.touchAction = 'pan-x';
		h.addEventListener('touchstart', function (e) {
			startY = e.touches[0].clientY;
		}, { passive: true });
		h.addEventListener('touchmove', function (e) {
			if (startY === null) { return; }
			var dy = e.touches[0].clientY - startY;
			if (dy > 0 && !reducedMotion()) {
				sheet.style.transform = 'translateY(' + dy + 'px)';
				sheet.style.transition = 'none';
			}
		}, { passive: true });
		h.addEventListener('touchend', function (e) {
			var dy = startY !== null ? (e.changedTouches[0].clientY - startY) : 0;
			sheet.style.transform = '';
			sheet.style.transition = '';
			if (dy > 90) { closeSheet(sheet, sheet.parentElement && sheet.parentElement.classList.contains('gi-overlay') ? sheet.parentElement : null); }
			startY = null;
		});
	}

	/* Wire generic sheet markup: [data-gi-sheet="id"] opens #id */
	function initSheets() {
		var sheets = document.querySelectorAll('.gi-sheet');
		Array.prototype.forEach.call(sheets, function (sheet) {
			var overlay = sheet.getAttribute('data-gi-overlay');
			var ov = overlay ? document.getElementById(overlay) : null;
			initSwipe(sheet);
			if (ov) {
				ov.addEventListener('click', function () { closeSheet(sheet, ov); });
			}
			var openers = document.querySelectorAll('[data-gi-open="' + sheet.id + '"]');
			Array.prototype.forEach.call(openers, function (op) {
				op.addEventListener('click', function () { openSheet(sheet, ov); });
			});
			var closers = sheet.querySelectorAll('[data-gi-close]');
			Array.prototype.forEach.call(closers, function (c) {
				c.addEventListener('click', function () { closeSheet(sheet, ov); });
			});
		});
	}

	/* ── Generic: mark gi-stat/gi-num elements for number transitions ──
	   Elements with [data-gi-value] are updated by page scripts via
	   GI.setNumber(el, value). Nothing here touches AJAX. */

	function init() {
		initTheme();
		initSheets();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
