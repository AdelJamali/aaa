/* Stickman Animation Studio — server.
 * Serves the mobile-first studio UI and runs the real render/export pipeline.
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const express = require('express');
const ffmpeg = require('./ffmpeg');
const renderer = require('./render');
const Story = require('../shared/story.js');
const Audio = require('../shared/audio.js');
const Render = require('../shared/render.js');

const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const PROJECTS = path.join(DATA, 'projects');
const EXPORTS = path.join(DATA, 'exports');
const MEDIA = path.join(DATA, 'media');
[PROJECTS, EXPORTS, MEDIA].forEach((d) => fs.mkdirSync(d, { recursive: true }));

const app = express();
app.use(express.json({ limit: '12mb' }));
app.use(express.static(path.join(ROOT, 'public'), { maxAge: '1h', index: 'index.html' }));
app.use('/shared', express.static(path.join(ROOT, 'shared'), { maxAge: '1h' }));

const jobs = new Map();
let jobSeq = 0;

function newJob(kind, meta) {
  const id = 'job_' + Date.now().toString(36) + '_' + (++jobSeq);
  const job = { id, kind, meta, status: 'running', progress: 0, frame: 0, totalFrames: 0, startedAt: Date.now(), cancelRequested: false, logs: [] };
  jobs.set(id, job);
  return job;
}
function publicJob(j) {
  return {
    id: j.id, kind: j.kind, status: j.status, progress: Math.round(j.progress * 1000) / 10,
    frame: j.frame, totalFrames: j.totalFrames, elapsedMs: Date.now() - j.startedAt,
    error: j.error || null, result: j.result || null, logs: j.logs.slice(-8)
  };
}

// ---------------------------------------------------------------------------
// Health / capability discovery
// ---------------------------------------------------------------------------
app.get('/api/health', async (req, res) => {
  let ff = null, ffError = null;
  try {
    ff = await ffmpeg.ensure((m) => console.log('[ffmpeg]', m));
  } catch (e) { ffError = e.message; }
  res.json({
    ok: true,
    service: 'Stickman Animation Studio',
    version: '1.0.0',
    node: process.version,
    ffmpeg: ff ? { available: true, version: ff.version, source: ff.source, path: ff.path } : { available: false, error: ffError },
    renderer: 'node-canvas (@napi-rs/canvas) + libx264',
    paidServicesRequired: [],
    activeJobs: [...jobs.values()].filter((j) => j.status === 'running').length
  });
});

// ---------------------------------------------------------------------------
// Project library (editable JSON on disk)
// ---------------------------------------------------------------------------
app.get('/api/projects', (req, res) => {
  const files = fs.readdirSync(PROJECTS).filter((f) => f.endsWith('.json'));
  const list = files.map((f) => {
    try {
      const p = JSON.parse(fs.readFileSync(path.join(PROJECTS, f), 'utf8'));
      return { file: f, id: p.id, title: p.title, scenes: (p.scenes || []).length, duration: Render.totalDuration(Story.normalizeProject(p)), savedAt: fs.statSync(path.join(PROJECTS, f)).mtime.toISOString() };
    } catch (e) { return { file: f, error: 'unreadable' }; }
  }).filter((p) => !p.error);
  res.json({ projects: list.sort((a, b) => (b.savedAt || '').localeCompare(a.savedAt || '')) });
});

app.get('/api/projects/:file', (req, res) => {
  const safe = path.basename(req.params.file);
  const p = path.join(PROJECTS, safe);
  if (!fs.existsSync(p)) return res.status(404).json({ error: 'not found' });
  res.json(JSON.parse(fs.readFileSync(p, 'utf8')));
});

app.post('/api/projects', (req, res) => {
  const project = Story.normalizeProject(req.body && req.body.project ? req.body.project : req.body);
  const name = (req.body && req.body.file ? path.basename(req.body.file) : null) || (project.id || 'project') + '.json';
  const file = name.endsWith('.json') ? name : name + '.json';
  const target = path.join(PROJECTS, file);
  fs.writeFileSync(target, JSON.stringify(project, null, 2));
  res.json({ ok: true, file, savedAt: new Date().toISOString() });
});

app.delete('/api/projects/:file', (req, res) => {
  const target = path.join(PROJECTS, path.basename(req.params.file));
  if (fs.existsSync(target)) fs.unlinkSync(target);
  res.json({ ok: true });
});

// ---------------------------------------------------------------------------
// Demo project + script parsing (server-side fallback / shared logic)
// ---------------------------------------------------------------------------
app.get('/api/demo', (req, res) => res.json(Story.createDemoProject()));
app.post('/api/script', (req, res) => {
  const { script, options } = req.body || {};
  res.json(Story.parseScript(script || '', options || {}));
});

// ---------------------------------------------------------------------------
// Audio: deterministic PCM preview (WAV) for <audio> playback + verification
// ---------------------------------------------------------------------------
app.post('/api/audio.wav', (req, res) => {
  const project = Story.normalizeProject(req.body && req.body.project ? req.body.project : req.body);
  const pcm = Audio.renderPCM(project, 44100, 2);
  if (pcm.empty) return res.status(204).end();
  const buf = Audio.toWav(pcm);
  res.setHeader('Content-Type', 'audio/wav');
  res.setHeader('Content-Length', buf.length);
  res.setHeader('Cache-Control', 'no-store');
  res.end(buf);
});

// ---------------------------------------------------------------------------
// Optional narration upload (a real user-supplied file; no TTS service needed)
// ---------------------------------------------------------------------------
app.post('/api/narration/:projectId', express.raw({ type: '*/*', limit: '64mb' }), (req, res) => {
  const id = path.basename(req.params.projectId || 'project');
  const ext = (req.query.ext || 'mp3').toString().replace(/[^a-z0-9]/gi, '');
  const target = path.join(MEDIA, id + '-narration.' + ext);
  if (!req.body || !req.body.length) return res.status(400).json({ error: 'empty body' });
  fs.writeFileSync(target, req.body);
  res.json({ ok: true, path: target, bytes: req.body.length, url: '/api/narration/' + id + '/file' });
});
app.get('/api/narration/:projectId/file', (req, res) => {
  const id = path.basename(req.params.projectId);
  const files = fs.readdirSync(MEDIA).filter((f) => f.startsWith(id + '-narration.'));
  if (!files.length) return res.status(404).json({ error: 'no narration uploaded' });
  res.sendFile(path.join(MEDIA, files[0]));
});

// ---------------------------------------------------------------------------
// Real render: 1080p30 MP4 with AAC audio
// ---------------------------------------------------------------------------
app.post('/api/render', async (req, res) => {
  const body = req.body || {};
  const project = Story.normalizeProject(body.project);
  const opts = Object.assign({
    width: 1920, height: 1080, fps: 30, includeAudio: true,
    preset: 'veryfast', crf: 18, label: 'final'
  }, body.options || {});

  let narration = null;
  if (body.narration && body.narration.uploaded) {
    const id = path.basename(project.id || 'project');
    const files = fs.readdirSync(MEDIA).filter((f) => f.startsWith(id + '-narration.'));
    if (files.length) narration = { path: path.join(MEDIA, files[0]), volume: body.narration.volume === undefined ? 1 : body.narration.volume, offset: body.narration.offset || 0 };
  }

  const job = newJob('render', { title: project.title, width: opts.width, height: opts.height, fps: opts.fps, includeAudio: opts.includeAudio });
  job.totalFrames = Math.max(1, Math.round(Render.totalDuration(project) * opts.fps));
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const file = `${(project.id || 'project').replace(/[^a-z0-9_-]/gi, '')}-${opts.label}-${stamp}.mp4`;
  const outPath = path.join(EXPORTS, file);
  res.json({ ok: true, jobId: job.id, totalFrames: job.totalFrames, file, width: opts.width, height: opts.height, fps: opts.fps, includeAudio: !!opts.includeAudio });

  (async () => {
    try {
      const result = await renderer.renderToMp4(project, outPath, Object.assign({}, opts, { narration }), (p) => {
        job.progress = p.ratio; job.frame = p.frame;
      }, job);
      const probe = await renderer.probeWithFfmpeg(result.video.ffmpeg.path, outPath);
      job.status = 'done';
      job.progress = 1;
      job.result = {
        file, bytes: result.file.bytes, durationSec: result.file.durationSec,
        width: result.file.width, height: result.file.height, fps: result.file.fps,
        ms: result.ms, steps: result.steps, probe,
        downloadUrl: '/api/export/' + encodeURIComponent(file)
      };
    } catch (e) {
      job.status = e.message === 'cancelled' ? 'cancelled' : 'error';
      job.error = e.message;
      console.error('[render] failed', e);
    }
  })();
});

app.get('/api/render/:id', (req, res) => {
  const job = jobs.get(req.params.id);
  if (!job) return res.status(404).json({ error: 'unknown job' });
  res.json(publicJob(job));
});
app.post('/api/render/:id/cancel', (req, res) => {
  const job = jobs.get(req.params.id);
  if (!job) return res.status(404).json({ error: 'unknown job' });
  job.cancelRequested = true;
  res.json({ ok: true });
});
app.get('/api/render/:id/download', (req, res) => {
  const job = jobs.get(req.params.id);
  if (!job || !job.result) return res.status(404).json({ error: 'not ready' });
  res.download(path.join(EXPORTS, job.result.file), job.result.file);
});

app.get('/api/exports', (req, res) => {
  const files = fs.readdirSync(EXPORTS).filter((f) => f.endsWith('.mp4')).map((f) => {
    const st = fs.statSync(path.join(EXPORTS, f));
    return { file: f, bytes: st.size, modified: st.mtime.toISOString(), url: '/api/export/' + encodeURIComponent(f) };
  }).sort((a, b) => b.modified.localeCompare(a.modified));
  res.json({ exports: files });
});
app.get('/api/export/:file', (req, res) => {
  const target = path.join(EXPORTS, path.basename(req.params.file));
  if (!fs.existsSync(target)) return res.status(404).json({ error: 'not found' });
  res.setHeader('Content-Type', 'video/mp4');
  res.setHeader('Content-Disposition', 'attachment; filename="' + path.basename(target) + '"');
  fs.createReadStream(target).pipe(res);
});

// ---------------------------------------------------------------------------
// Stills / storyboard (thumbnails, verification screenshots)
// ---------------------------------------------------------------------------
app.post('/api/still.png', (req, res) => {
  const { project, time, width, height } = req.body || {};
  const png = renderer.renderStill(project, time || 0, { width: width || 960, height: height || 540 });
  res.setHeader('Content-Type', 'image/png');
  res.end(png);
});
app.post('/api/storyboard.png', (req, res) => {
  const { project, width, height } = req.body || {};
  const png = renderer.renderStoryboard(project, { width: width || 480, height: height || 270 });
  res.setHeader('Content-Type', 'image/png');
  res.end(png);
});

// ---------------------------------------------------------------------------
// Full source download (everything that makes the film: code, fonts, docs)
// ---------------------------------------------------------------------------
app.get('/api/source.zip', (req, res) => {
  try {
    const AdmZip = require('adm-zip');
    const zip = new AdmZip();
    const include = ['shared', 'server', 'public', 'scripts', 'docs'];
    const rootFiles = ['package.json', 'README.md', 'LICENSE.txt'];
    const skip = /(^|\/)(node_modules|data|vendor|\.git)(\/|$)/;
    function addDir(dir, prefix) {
      fs.readdirSync(dir).forEach((name) => {
        const full = path.join(dir, name);
        const rel = prefix ? prefix + '/' + name : name;
        if (skip.test(rel)) return;
        const st = fs.statSync(full);
        if (st.isDirectory()) addDir(full, rel);
        else zip.addLocalFile(full, path.dirname(rel) === '.' ? '' : path.dirname(rel));
      });
    }
    include.forEach((d) => {
      const p = path.join(ROOT, d);
      if (fs.existsSync(p)) addDir(p, d);
    });
    rootFiles.forEach((f) => {
      const p = path.join(ROOT, f);
      if (fs.existsSync(p)) zip.addLocalFile(p);
    });
    const buf = zip.toBuffer();
    res.setHeader('Content-Type', 'application/zip');
    res.setHeader('Content-Length', buf.length);
    res.setHeader('Content-Disposition', 'attachment; filename="stickman-animation-studio.zip"');
    res.end(buf);
  } catch (e) {
    res.status(500).json({ error: 'could not build source zip: ' + e.message });
  }
});

const PORT = process.env.PORT || 4173;
if (require.main === module) {
  app.listen(PORT, '0.0.0.0', async () => {
    console.log(`Stickman Animation Studio listening on http://0.0.0.0:${PORT}`);
    try {
      const ff = await ffmpeg.ensure((m) => console.log('[ffmpeg]', m));
      console.log('[startup] FFmpeg ready:', ff.version, '(' + ff.source + ')', ff.path);
    } catch (e) {
      console.error('[startup] FFmpeg unavailable:', e.message);
    }
  });
}
module.exports = app;
