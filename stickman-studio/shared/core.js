/* Stickman Animation Studio — shared core math/colour utilities.
 * Loaded identically by the browser preview and the Node/FFmpeg renderer.
 * Deterministic: no Math.random(), no Date.now() anywhere in this file.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else { root.SAS = root.SAS || {}; root.SAS.Core = factory(); }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  var TAU = Math.PI * 2;
  var DEG = Math.PI / 180;

  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function invLerp(a, b, v) { return b === a ? 0 : (v - a) / (b - a); }
  function round(v, p) { var m = Math.pow(10, p || 0); return Math.round(v * m) / m; }

  // ---- easing -------------------------------------------------------------
  function smoothstep(t) { t = clamp(t, 0, 1); return t * t * (3 - 2 * t); }
  function smootherstep(t) { t = clamp(t, 0, 1); return t * t * t * (t * (t * 6 - 15) + 10); }
  function easeInQuad(t) { t = clamp(t, 0, 1); return t * t; }
  function easeOutQuad(t) { t = clamp(t, 0, 1); return t * (2 - t); }
  function easeInCubic(t) { t = clamp(t, 0, 1); return t * t * t; }
  function easeOutCubic(t) { t = clamp(t, 0, 1); var u = 1 - t; return 1 - u * u * u; }
  function easeInOutCubic(t) { t = clamp(t, 0, 1); return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }
  function easeOutQuint(t) { t = clamp(t, 0, 1); return 1 - Math.pow(1 - t, 5); }
  function easeInOutQuad(t) { t = clamp(t, 0, 1); return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
  function easeOutBack(t) { t = clamp(t, 0, 1); var c1 = 1.70158, c3 = c1 + 1; return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2); }
  function easeOutElastic(t) {
    t = clamp(t, 0, 1);
    if (t === 0 || t === 1) return t;
    var c4 = TAU / 3;
    return Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1;
  }
  function easeOutBounce(t) {
    t = clamp(t, 0, 1);
    var n1 = 7.5625, d1 = 2.75;
    if (t < 1 / d1) return n1 * t * t;
    if (t < 2 / d1) { t -= 1.5 / d1; return n1 * t * t + 0.75; }
    if (t < 2.5 / d1) { t -= 2.25 / d1; return n1 * t * t + 0.9375; }
    t -= 2.625 / d1; return n1 * t * t + 0.984375;
  }
  // damped sine used for shakes / springs
  function spring(t, freq, damp) {
    t = clamp(t, 0, 1);
    return Math.sin(t * TAU * (freq || 2)) * Math.exp(-t * (damp === undefined ? 6 : damp));
  }
  var EASINGS = {
    linear: function (t) { return clamp(t, 0, 1); },
    smoothstep: smoothstep, smootherstep: smootherstep,
    inQuad: easeInQuad, outQuad: easeOutQuad, inOutQuad: easeInOutQuad,
    inCubic: easeInCubic, outCubic: easeOutCubic, inOutCubic: easeInOutCubic,
    outQuint: easeOutQuint, outBack: easeOutBack, outElastic: easeOutElastic, outBounce: easeOutBounce
  };
  function ease(name, t) { var f = EASINGS[name] || smoothstep; return f(t); }

  // ---- deterministic RNG --------------------------------------------------
  function hashString(s) {
    var h = 2166136261 >>> 0;
    for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619) >>> 0; }
    return h >>> 0;
  }
  function mulberry32(seed) {
    var a = (seed === undefined ? 1 : seed) >>> 0;
    return function () {
      a = (a + 0x6D2B79F5) >>> 0;
      var t = a;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  // value-noise in 1D, seeded — used for hand-held camera drift, dust, flicker
  function noise1(seed, x) {
    var i = Math.floor(x), f = x - i;
    function h(n) { var r = mulberry32((seed >>> 0) ^ Math.imul(n + 374761393, 668265263)); return r(); }
    var a = h(i), b = h(i + 1);
    var u = f * f * (3 - 2 * f);
    return a + (b - a) * u;
  }
  function fbm1(seed, x, octaves) {
    var s = 0, amp = 0.5, freq = 1, norm = 0;
    for (var o = 0; o < (octaves || 3); o++) { s += noise1(seed + o * 7919, x * freq) * amp; norm += amp; amp *= 0.5; freq *= 2; }
    return s / (norm || 1);
  }

  // ---- colour -------------------------------------------------------------
  // colours are [r,g,b,a] with r,g,b 0..255 ints and a 0..1 float
  function hex(h) {
    if (typeof h !== 'string') return [255, 255, 255, 1];
    var s = h.trim().replace('#', '');
    if (s.length === 3) s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    var n = parseInt(s.slice(0, 6), 16);
    if (isNaN(n)) return [255, 255, 255, 1];
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255, 1];
  }
  function rgba(c, a) { return [c[0], c[1], c[2], a === undefined ? (c[3] === undefined ? 1 : c[3]) : a]; }
  function mixColor(a, b, t) {
    t = clamp(t, 0, 1);
    return [Math.round(lerp(a[0], b[0], t)), Math.round(lerp(a[1], b[1], t)), Math.round(lerp(a[2], b[2], t)), lerp(a[3] === undefined ? 1 : a[3], b[3] === undefined ? 1 : b[3], t)];
  }
  function scaleColor(c, k) { return [clamp(Math.round(c[0] * k), 0, 255), clamp(Math.round(c[1] * k), 0, 255), clamp(Math.round(c[2] * k), 0, 255), c[3] === undefined ? 1 : c[3]]; }
  function toCss(c) {
    return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + round(c[3] === undefined ? 1 : c[3], 3) + ')';
  }
  function toHex(c) { return '#' + [c[0], c[1], c[2]].map(function (v) { return ('0' + clamp(v | 0, 0, 255).toString(16)).slice(-2); }).join(''); }

  // ---- text markup --------------------------------------------------------
  // "**WORD**" marks red emphasis; "_WORD_" marks dim; returns runs
  // [{text, emphasis: 0|1|2}]
  function parseMarkup(text) {
    var s = String(text == null ? '' : text);
    var runs = [], buf = '', mode = 0;
    for (var i = 0; i < s.length; i++) {
      if (s[i] === '*' && s[i + 1] === '*') {
        if (buf) { runs.push({ text: buf, emphasis: mode }); buf = ''; }
        mode = mode === 1 ? 0 : 1;
        i++;
      } else if (s[i] === '_') {
        if (buf) { runs.push({ text: buf, emphasis: mode }); buf = ''; }
        mode = mode === 2 ? 0 : 2;
      } else buf += s[i];
    }
    if (buf) runs.push({ text: buf, emphasis: mode });
    return runs;
  }
  function stripMarkup(text) { return String(text == null ? '' : text).replace(/\*\*|_/g, ''); }

  function pad2(n) { n = Math.max(0, Math.floor(n)); return (n < 10 ? '0' : '') + n; }
  function formatTime(sec) {
    sec = Math.max(0, sec || 0);
    var m = Math.floor(sec / 60), s = Math.floor(sec % 60), f = Math.floor((sec - Math.floor(sec)) * 30);
    return pad2(m) + ':' + pad2(s) + '.' + pad2(f);
  }
  function formatClock(sec) { sec = Math.max(0, sec || 0); return pad2(Math.floor(sec / 60)) + ':' + pad2(Math.floor(sec % 60)); }

  return {
    TAU: TAU, DEG: DEG, clamp: clamp, lerp: lerp, invLerp: invLerp, round: round,
    smoothstep: smoothstep, smootherstep: smootherstep,
    easeInQuad: easeInQuad, easeOutQuad: easeOutQuad, easeInCubic: easeInCubic,
    easeOutCubic: easeOutCubic, easeInOutCubic: easeInOutCubic, easeOutQuint: easeOutQuint,
    easeInOutQuad: easeInOutQuad, easeOutBack: easeOutBack, easeOutElastic: easeOutElastic,
    easeOutBounce: easeOutBounce, spring: spring, ease: ease, EASINGS: EASINGS,
    hashString: hashString, mulberry32: mulberry32, noise1: noise1, fbm1: fbm1,
    hex: hex, rgba: rgba, mixColor: mixColor, scaleColor: scaleColor, toCss: toCss, toHex: toHex,
    parseMarkup: parseMarkup, stripMarkup: stripMarkup,
    pad2: pad2, formatTime: formatTime, formatClock: formatClock
  };
});
