/* Headless integration test for the client app: loads public/index.html in jsdom
 * with a stubbed 2D canvas, boots app.js against the real server, and drives the
 * same code paths a finger would (play, seek, edit, build storyboard, export).
 *
 * usage: node scripts/test-ui.js [serverUrl]
 */
const { JSDOM, VirtualConsole } = require('jsdom');

const BASE = process.argv[2] || 'http://127.0.0.1:4173';
const calls = { fillText: 0, arcs: 0, gradients: 0, saves: 0 };

function stubContext() {
  const ctx = {
    globalAlpha: 1, fillStyle: '#000', strokeStyle: '#000', lineWidth: 1, lineCap: 'butt',
    font: '10px sans-serif', textAlign: 'left', textBaseline: 'alphabetic',
    shadowColor: '', shadowBlur: 0,
    save() { calls.saves++; }, restore() { }, translate() { }, scale() { }, rotate() { },
    setTransform() { }, getTransform() { return { a: 1, b: 0, c: 0, d: 1, e: 0, f: 0 }; },
    clearRect() { }, fillRect() { }, strokeRect() { }, beginPath() { }, closePath() { },
    moveTo() { }, lineTo() { }, quadraticCurveTo() { }, bezierCurveTo() { }, rect() { },
    clip() { }, arc() { calls.arcs++; }, ellipse() { }, fill() { }, stroke() { },
    fillText() { calls.fillText++; }, strokeText() { }, measureText(t) { return { width: String(t || '').length * 8 }; },
    createLinearGradient() { calls.gradients++; return { addColorStop() { } }; },
    createRadialGradient() { calls.gradients++; return { addColorStop() { } }; },
    createPattern() { return null; }, drawImage() { }, getImageData() { return { data: new Uint8ClampedArray(4) }; },
    putImageData() { }, setLineDash() { }
  };
  return ctx;
}

(async () => {
  const vc = new VirtualConsole();
  const errors = [];
  vc.on('jsdomError', (e) => errors.push('jsdomError: ' + e.message));
  vc.on('error', (...a) => errors.push('console.error: ' + a.join(' ')));

  const dom = await JSDOM.fromURL(BASE + '/', {
    runScripts: 'dangerously',
    resources: 'usable',
    pretendToBeVisual: true,
    virtualConsole: vc,
    beforeParse(window) {
      window.HTMLCanvasElement.prototype.getContext = function () { return stubContext(); };
      window.HTMLCanvasElement.prototype.toDataURL = function () { return 'data:image/png;base64,'; };
      window.HTMLElement.prototype.scrollIntoView = function () { };
      window.HTMLElement.prototype.scrollTo = function () { };
      // jsdom has no fetch: bridge to Node's global fetch
      const nodeFetch = (...args) => fetch(...args);
      window.__fetchCalls = [];
      window.fetch = function (url, opts) {
        const u = String(url);
        window.__fetchCalls.push(u);
        return nodeFetch(/^https?:/.test(u) ? u : BASE + '/' + u.replace(/^\//, ''), opts);
      };
      // jsdom does not implement media playback
      window.HTMLMediaElement.prototype.play = function () { this.__playing = true; return Promise.resolve(); };
      window.HTMLMediaElement.prototype.pause = function () { this.__playing = false; };
      window.HTMLMediaElement.prototype.load = function () { };
      window.URL.createObjectURL = function () { return 'blob:stub'; };
      window.URL.revokeObjectURL = function () { };
    }
  });

  const { window } = dom;
  const doc = window.document;
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const fail = [];
  function check(name, cond, extra) {
    console.log((cond ? '  PASS  ' : '  FAIL  ') + name + (extra !== undefined && !cond ? '  → ' + extra : ''));
    if (!cond) fail.push(name);
  }

  // let scripts load (index.html pulls shared/*.js + js/*.js)
  for (let i = 0; i < 40 && !window.SAS; i++) await wait(100);
  check('SAS namespace loaded in the page', !!window.SAS);
  if (!window.SAS) { report(); return; }

  for (let i = 0; i < 60 && !(window.SAS_APP && window.SAS_APP.state); i++) await wait(100);
  const app = window.SAS_APP;
  check('app booted (SAS_APP.state exists)', !!(app && app.state && app.state.project));
  if (!app) { report(); return; }

  const P = () => app.state.project;
  check('demo project has 5 scenes', P().scenes.length === 5, P().scenes.length);
  check('total duration is 15s', Math.abs(window.SAS.Render.totalDuration(P()) - 15) < 0.001,
    window.SAS.Render.totalDuration(P()));
  for (let i = 0; i < 50 && !app.state.health; i++) await wait(100);
  check('server health was fetched', !!(app.state.health && app.state.health.ffmpeg && app.state.health.ffmpeg.available),
    JSON.stringify(app.state.health && app.state.health.ffmpeg));

  const chips = doc.querySelectorAll('.scene-chip');
  check('timeline rendered one chip per scene', chips.length === 5, chips.length);
  check('scene chips drew thumbnails', calls.saves > 0 && calls.arcs > 0, JSON.stringify(calls));
  check('total time label filled', /00:15/.test(doc.querySelector('#timeTotal').textContent), doc.querySelector('#timeTotal').textContent);

  // play / pause through the real buttons
  const playBtn = doc.querySelector('#btnPlay');
  playBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('play button starts playback', app.preview.playing === true);
  playBtn.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('play button pauses playback', app.preview.playing === false);

  // seek
  const seek = doc.querySelector('#seek');
  seek.value = '7.4';
  seek.dispatchEvent(new window.Event('input', { bubbles: true }));
  check('seek moves the playhead', Math.abs(app.preview.time - 7.4) < 0.05, app.preview.time);
  const atScene = window.SAS.Render.sceneAt(P(), app.preview.time);
  check('playhead 7.4s lands in scene 3', atScene.index === 2, atScene.index);

  // scene navigation
  doc.querySelector('#btnNext').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('next-scene button moves forward', app.state.sceneIndex >= 2, app.state.sceneIndex);

  // scene editor writes through to the project
  const dur = doc.querySelector('#scDuration');
  dur.value = '4.5';
  dur.dispatchEvent(new window.Event('input', { bubbles: true }));
  check('duration slider updates the scene', Math.abs(P().scenes[app.state.sceneIndex].duration - 4.5) < 0.001,
    P().scenes[app.state.sceneIndex].duration);

  const titleInput = doc.querySelector('#scTitle');
  titleInput.value = 'Edited beat';
  titleInput.dispatchEvent(new window.Event('input', { bubbles: true }));
  check('scene title edits the project', P().scenes[app.state.sceneIndex].title === 'Edited beat');

  // add / delete scene
  const before = P().scenes.length;
  doc.querySelector('#btnAddScene').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('add scene inserts a scene', P().scenes.length === before + 1, P().scenes.length);
  doc.querySelector('#btnDelScene').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('delete scene removes it again', P().scenes.length === before, P().scenes.length);

  // select the first demo scene again, which has props, lights and an actor
  doc.querySelector('#btnDemo').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await wait(250);
  doc.querySelectorAll('.scene-chip')[0].dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await wait(150);
  const scene = P().scenes[0];
  check('actor cards rendered', doc.querySelectorAll('#actorList .card').length >= 1,
    doc.querySelectorAll('#actorList .card').length);
  check('clip editors rendered for the actor', doc.querySelectorAll('#actorList .card .card').length >= 1);
  check('prop cards rendered', doc.querySelectorAll('#propList .card').length === scene.props.length,
    doc.querySelectorAll('#propList .card').length + ' vs ' + scene.props.length);
  check('light editors rendered', doc.querySelectorAll('#lightList .card').length === (scene.lights || []).length,
    doc.querySelectorAll('#lightList .card').length + ' vs ' + (scene.lights || []).length);
  check('effect editors rendered', doc.querySelectorAll('#effectList .card').length === (scene.effects || []).length);
  check('audio cue editors rendered', doc.querySelectorAll('#cueList .card').length >= 1);

  // script -> storyboard (the main generator path)
  doc.querySelector('#btnExample').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  doc.querySelector('#btnBuild').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await wait(300);
  check('building from the example script replaces the project', app.state.project.scenes.length === 5,
    app.state.project.scenes.length);
  check('built project is 15s', Math.abs(window.SAS.Render.totalDuration(app.state.project) - 15) < 1.2,
    window.SAS.Render.totalDuration(app.state.project));

  // back to the demo, then export through the UI
  doc.querySelector('#btnDemo').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await wait(300);
  check('demo restored after the script round-trip', P().scenes.length === 5, P().scenes.length);
  const select = doc.querySelector('#expSize');
  select.value = '960x540';
  doc.querySelector('#btnRender').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await wait(600);
  check('render request was sent to /api/render', window.__fetchCalls.some((u) => /api\/render/.test(u)),
    JSON.stringify(window.__fetchCalls.slice(0, 12)));
  let done = false;
  for (let i = 0; i < 120 && !done; i++) {
    await wait(1000);
    const result = doc.querySelector('#renderResult');
    if (result && !result.hidden) done = true;
  }
  check('render finished and the result card is visible', done);
  const link = doc.querySelector('#btnDownloadMp4');
  check('download link points at a real mp4 url', /\.mp4$/i.test(link.getAttribute('href') || ''), link.getAttribute('href'));
  const bodyText = doc.querySelector('#renderResultBody').textContent;
  check('report shows h264 and 960x540', /h264/.test(bodyText) && /960×540/.test(bodyText), bodyText.slice(0, 120));
  check('report shows an audio codec', /aac/i.test(bodyText), bodyText.slice(0, 200));

  // static audit: every element id the client looks up must exist in the markup
  const fs = require('fs');
  const path = require('path');
  const appSrc = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'app.js'), 'utf8');
  const idRe = /\$\('#([A-Za-z0-9_-]+)'\)/g;
  const ids = [];
  let m2;
  while ((m2 = idRe.exec(appSrc)) !== null) if (ids.indexOf(m2[1]) < 0) ids.push(m2[1]);
  const missing = ids.filter(function (id) { return !doc.getElementById(id); });
  check('all ' + ids.length + ' element ids used by app.js exist in index.html', missing.length === 0, missing.join(', '));

  const html = fs.readFileSync(path.join(__dirname, '..', 'public', 'index.html'), 'utf8');
  const scriptTags = (html.match(/<script src="([^"]+)"/g) || []).map(function (x) { return x.replace(/.*src="|"/g, ''); });
  check('index.html loads all shared + client scripts', scriptTags.length === 6, scriptTags.join(', '));

  check('no jsdom/runtime errors during the session', errors.filter((e) => !/HTMLMediaElement/.test(e)).length === 0,
    errors.slice(0, 3).join(' | '));

  report();
  function report() {
    console.log('\n' + (fail.length ? 'FAILURES (' + fail.length + '): ' + fail.join('; ') : 'ALL UI CHECKS PASSED'));
    dom.window.close();
    process.exit(fail.length ? 1 : 0);
  }
})().catch((e) => { console.error('test-ui crashed:', e); process.exit(1); });
