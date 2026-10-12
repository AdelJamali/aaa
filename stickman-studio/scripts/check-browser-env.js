/* Emulates a browser global scope (globalThis === window) and exercises the
 * client-side modules the way Chrome loads them, catching browser-only bugs.
 */
const vm = require('vm'), fs = require('fs'), path = require('path');
const root = path.join(__dirname, '..');
const sandbox = { console, setTimeout, clearTimeout };
sandbox.window = sandbox;
sandbox.self = sandbox;
sandbox.devicePixelRatio = 1;
sandbox.document = {
  addEventListener() { }, readyState: 'loading',
  querySelector: () => null, querySelectorAll: () => [],
  createElement: () => ({ style: {}, classList: { add() { }, toggle() { } }, appendChild() { }, addEventListener() { }, getContext: () => null }),
  body: { appendChild() { } }
};
sandbox.performance = { now: () => 0 };
sandbox.requestAnimationFrame = () => 0;
sandbox.cancelAnimationFrame = () => { };
sandbox.localStorage = { getItem: () => null, setItem() { }, removeItem() { } };
sandbox.URL = { createObjectURL: () => 'blob:x', revokeObjectURL() { } };
sandbox.AbortController = function () { this.abort = () => { }; };
sandbox.Blob = function () { };
sandbox.Option = function (t, v) { this.text = t; this.value = v; };
sandbox.fetch = () => Promise.resolve({ json: () => Promise.resolve({ ffmpeg: { available: true }, projects: [], exports: [] }) });
const ctx = vm.createContext(sandbox);
['shared/core.js', 'shared/render.js', 'shared/story.js', 'shared/audio.js'].forEach((f) => {
  vm.runInContext(fs.readFileSync(path.join(root, f), 'utf8'), ctx, { filename: f });
});
const SAS = sandbox.SAS;
if (!SAS) throw new Error('SAS namespace missing — UMD wrapper failed in a browser-like scope');
console.log('browser globals:', Object.keys(SAS).join(', '));
const p = SAS.Story.createDemoProject();
const R = SAS.Render;
console.log('demo:', p.scenes.length, 'scenes ·', R.totalDuration(p), 's ·', Math.round(R.totalDuration(p) * 30), 'frames');
console.log('library: actions', R.ACTIONS.length, '| props', R.PROP_LIST.length, '| backgrounds', R.BG_LIST.length, '| effects', R.FX_LIST.length);
const cues = SAS.Audio.buildCues(p);
console.log('audio cues:', cues.length, '→', Array.from(new Set(cues.map((c) => c.type))).join(', '));
const parsed = SAS.Story.parseScript(
  'Scene 1 (2s): He wakes up. He reaches for his phone.\nScene 2 (3s): He runs up the stairs and celebrates.',
  { totalDuration: 5, title: 'Parser check' });
console.log('parser:', parsed.scenes.map((s) => s.duration.toFixed(2) + 's/' + s.background + ' [' + s.actors[0].clips.map((c) => (c.morph || c.from) ? ((c.from || c.morph[0]) + '→' + (c.to || c.morph[1])) : c.action).join(' | ') + ']').join('  ||  '));
console.log('parser total after target fit:', R.totalDuration(parsed).toFixed(2) + 's');
// every advertised action must resolve to a pose without throwing
let bad = [];
R.ACTION_IDS.forEach((id) => {
  try {
    for (let t = 0; t < 2; t += 0.1) {
      const sk = R.buildSkeleton(R.resolvePose(id, { t, local: t, phase: t % 1, u: t / 2, params: {} }));
      if (!isFinite(sk.head.x) || !isFinite(sk.footR.y)) bad.push(id + '@' + t.toFixed(1));
    }
  } catch (e) { bad.push(id + ': ' + e.message); }
});
console.log(bad.length ? 'BROKEN POSES: ' + bad.join(', ') : 'all ' + R.ACTION_IDS.length + ' actions produce finite skeletons');
