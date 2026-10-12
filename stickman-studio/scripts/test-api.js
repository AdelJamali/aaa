/* API flow test against a running server (npm start):
 *   health → demo → narration upload → render WITH narration → cancel a render →
 *   download the MP4 → exports list → source zip.
 * usage: node scripts/test-api.js [serverUrl]
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const BASE = (process.argv[2] || 'http://127.0.0.1:4173').replace(/\/$/, '');
const results = [];
function check(name, cond, extra) {
  results.push({ name, ok: !!cond });
  console.log((cond ? '  PASS  ' : '  FAIL  ') + name + (cond || extra === undefined ? '' : '  → ' + extra));
}
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function json(url, opts) {
  const r = await fetch(BASE + url, opts);
  const text = await r.text();
  let body = null;
  try { body = JSON.parse(text); } catch (e) { body = text; }
  return { status: r.status, body, headers: r.headers };
}

(async () => {
  // 1. health
  const health = await json('/api/health');
  check('GET /api/health reports a usable FFmpeg', health.body && health.body.ffmpeg && health.body.ffmpeg.available,
    JSON.stringify(health.body && health.body.ffmpeg));
  check('health lists no paid services', Array.isArray(health.body.paidServicesRequired) && health.body.paidServicesRequired.length === 0);

  // 2. demo project
  const demo = await json('/api/demo');
  check('GET /api/demo returns the 5-scene demo', demo.body.scenes.length === 5, demo.body.scenes.length);
  const project = demo.body;

  // 3. narration upload (a real stereo tone file, generated with the app's own FFmpeg)
  const ffmpegPath = health.body.ffmpeg.path;
  const narr = path.join(os.tmpdir(), 'narration-test.wav');
  await new Promise((resolve, reject) => {
    const p = spawn(ffmpegPath, ['-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
      '-i', 'sine=frequency=180:duration=14', '-af', 'volume=0.5', '-ar', '44100', '-ac', '2', narr], { stdio: 'ignore' });
    p.on('close', (c) => c === 0 ? resolve() : reject(new Error('tone generation failed')));
  });
  const up = await fetch(BASE + '/api/narration/' + encodeURIComponent(project.id) + '?ext=wav', {
    method: 'POST', headers: { 'Content-Type': 'audio/wav' }, body: fs.readFileSync(narr)
  });
  const upBody = await up.json();
  check('POST /api/narration/:id stores the file', up.ok && upBody.ok, JSON.stringify(upBody));
  const narrFile = await fetch(BASE + '/api/narration/' + encodeURIComponent(project.id) + '/file');
  check('GET /api/narration/:id/file serves it back', narrFile.ok && (await narrFile.arrayBuffer()).byteLength > 1000);

  // 4. render WITH narration (exercises the amix filter graph)
  const start = await json('/api/render', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      project,
      options: { width: 640, height: 360, fps: 30, includeAudio: true, label: 'narr-test', preset: 'ultrafast', crf: 26 },
      narration: { uploaded: true, volume: 0.8, offset: 0.5 }
    })
  });
  check('POST /api/render accepts a narration mix', start.body.ok === true, JSON.stringify(start.body));
  let job = null;
  for (let i = 0; i < 90; i++) {
    await wait(1000);
    job = (await json('/api/render/' + start.body.jobId)).body;
    if (job.status !== 'running') break;
  }
  check('narration render finished', job && job.status === 'done', JSON.stringify(job && job.error));
  check('narration render reports the mux step', job && job.result && job.result.steps.some((s) => s.step === 'mux-audio' && s.narration === true),
    JSON.stringify(job && job.result && job.result.steps));
  if (job && job.result) {
    const narrMp4 = await fetch(BASE + job.result.downloadUrl);
    const narrBytes = (await narrMp4.arrayBuffer()).byteLength;
    check('narration MP4 downloads', narrMp4.ok && narrBytes > 100000, narrBytes + ' bytes');
    check('narration MP4 has an audio track', !!(job.result.probe && job.result.probe.audio), JSON.stringify(job.result.probe && job.result.probe.audio));
  } else {
    check('narration MP4 downloads', false, 'no result to download');
    check('narration MP4 has an audio track', false, 'no result to inspect');
  }

  // 5. cancel a render mid-way
  const start2 = await json('/api/render', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ project, options: { width: 1920, height: 1080, fps: 30, includeAudio: true, label: 'cancel-test' } })
  });
  await wait(2500);
  const cancel = await json('/api/render/' + start2.body.jobId + '/cancel', { method: 'POST' });
  check('POST /api/render/:id/cancel is accepted', cancel.body.ok === true);
  let cancelled = null;
  for (let i = 0; i < 30; i++) {
    await wait(1000);
    cancelled = (await json('/api/render/' + start2.body.jobId)).body;
    if (cancelled.status !== 'running') break;
  }
  check('cancelled job stops without a result', cancelled && cancelled.status === 'cancelled' && !cancelled.result,
    JSON.stringify(cancelled && cancelled.status));

  // 6. happy path 540p download once more through the public URL
  const list = await json('/api/exports');
  check('GET /api/exports lists rendered files', list.body.exports.length >= 1, list.body.exports.length);
  const first = list.body.exports[0];
  const dl = await fetch(BASE + first.url);
  const cd = dl.headers.get('content-disposition') || '';
  check('export download sends an attachment with an .mp4 name', /attachment; filename=".*\.mp4"/.test(cd), cd);
  check('export download is video/mp4', (dl.headers.get('content-type') || '').includes('video/mp4'));

  // 7. project save / load round trip
  const saved = await json('/api/projects', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ project, file: 'api-test-project.json' }) });
  check('POST /api/projects saves JSON', saved.body.ok === true, saved.body.file);
  const loaded = await json('/api/projects/api-test-project.json');
  check('saved project reopens with identical scene count', loaded.body.scenes.length === project.scenes.length);
  check('reopened project keeps clip data', JSON.stringify(loaded.body.scenes[1].actors[0].clips).length > 50);
  await fetch(BASE + '/api/projects/api-test-project.json', { method: 'DELETE' });

  // 8. source zip
  const zip = await fetch(BASE + '/api/source.zip');
  const zipBytes = (await zip.arrayBuffer()).byteLength;
  check('GET /api/source.zip returns a real archive', zip.ok && zipBytes > 200000, zipBytes + ' bytes');

  const failed = results.filter((r) => !r.ok);
  console.log('\n' + (failed.length ? 'FAILURES: ' + failed.map((f) => f.name).join('; ') : 'ALL ' + results.length + ' API CHECKS PASSED'));
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error('test-api crashed:', e); process.exit(1); });
