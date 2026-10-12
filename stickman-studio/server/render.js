/* Real MP4 export: node-canvas renders every frame deterministically at
 * 1920x1080, streams raw RGBA into FFmpeg (libx264, yuv420p, +faststart) and
 * then muxes the code-generated audio track (AAC) plus optional narration.
 *
 * There is no "placeholder" button anywhere: if this succeeds, the file on disk
 * is a playable .mp4 the browser can download.
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const { createCanvas, GlobalFonts } = require('@napi-rs/canvas');
const ffmpeg = require('./ffmpeg');

const ROOT = path.join(__dirname, '..');
const Core = require('../shared/core.js');
const Render = require('../shared/render.js');
const Audio = require('../shared/audio.js');
const Story = require('../shared/story.js');

const FONT_FAMILY = '"DejaVu Sans", sans-serif';
let fontsRegistered = false;

function registerFonts() {
  if (fontsRegistered) return;
  const dir = path.join(ROOT, 'public', 'fonts');
  ['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'].forEach((f) => {
    const p = path.join(dir, f);
    if (fs.existsSync(p)) {
      try { GlobalFonts.registerFromPath(p); } catch (e) { /* already registered */ }
    }
  });
  fontsRegistered = true;
}

function log(...args) { console.log('[render]', ...args); }

async function renderSilentVideo(project, outPath, opts, onProgress, job) {
  const W = opts.width || project.width || 1920;
  const H = opts.height || project.height || 1080;
  const fps = opts.fps || project.fps || 30;
  const totalDuration = Render.totalDuration(project);
  const totalFrames = Math.max(1, Math.round(totalDuration * fps));

  const bin = await ffmpeg.ensure(log);
  registerFonts();

  const canvas = createCanvas(W, H);
  const ctx = canvas.getContext('2d');

  const args = [
    '-hide_banner', '-loglevel', 'error', '-y',
    '-f', 'rawvideo', '-pix_fmt', 'rgba', '-s', `${W}x${H}`, '-r', String(fps),
    '-i', 'pipe:0',
    '-c:v', 'libx264', '-preset', opts.preset || 'veryfast', '-crf', String(opts.crf === undefined ? 18 : opts.crf),
    '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.1',
    '-x264-params', 'keyint=' + Math.max(1, Math.round(fps * 2)) + ':min-keyint=' + Math.max(1, Math.round(fps * 2)) + ':scenecut=0',
    '-movflags', '+faststart',
    outPath
  ];

  return new Promise((resolve, reject) => {
    const proc = spawn(bin.path, args, { stdio: ['pipe', 'inherit', 'pipe'] });
    let stderr = '';
    proc.stderr.on('data', (d) => { stderr += d.toString(); });
    proc.on('error', reject);
    proc.on('close', (code) => {
      if (code === 0) resolve({ frames: totalFrames, width: W, height: H, fps, ffmpeg: bin });
      else reject(new Error('ffmpeg exited with code ' + code + ': ' + stderr.slice(-800)));
    });
    if (job) job.process = proc;

    let frame = 0;
    const pump = () => {
      while (frame < totalFrames) {
        if (job && job.cancelRequested) { proc.kill('SIGKILL'); return reject(new Error('cancelled')); }
        const t = frame / fps;
        ctx.save();
        ctx.clearRect(0, 0, W, H);
        Render.renderFrame(ctx, project, t, { W, H, fontFamily: FONT_FAMILY });
        ctx.restore();
        const buf = canvas.data();          // raw RGBA, W*H*4 bytes
        frame++;
        const ok = proc.stdin.write(buf);
        if (onProgress && (frame % 5 === 0 || frame === totalFrames)) {
          onProgress({ frame, totalFrames, ratio: frame / totalFrames, time: t });
        }
        if (!ok) {
          proc.stdin.once('drain', pump);
          return;
        }
      }
      proc.stdin.end();
    };
    pump();
  });
}

function writeAudioWav(project, wavPath) {
  const pcm = Audio.renderPCM(project, 44100, 2);
  if (pcm.empty) return null;
  fs.writeFileSync(wavPath, Audio.toWav(pcm));
  return { path: wavPath, duration: pcm.duration, bytes: fs.statSync(wavPath).size };
}

function muxAudio(videoPath, audioPath, outPath, opts, narration) {
  return new Promise((resolve, reject) => {
    const args = ['-hide_banner', '-loglevel', 'error', '-y', '-i', videoPath, '-i', audioPath];
    const haveNarration = !!(narration && fs.existsSync(narration.path));
    if (haveNarration) {
      args.push('-i', narration.path);
      const offset = Math.max(0, narration.offset || 0);
      const vol = narration.volume === undefined ? 1 : narration.volume;
      const delay = Math.round(offset * 1000);
      const amixOpts = 'inputs=2:duration=longest:dropout_transition=0';
      let graph;
      if (opts.amixNormalize) {
        // FFmpeg >= 4.4: keep both sources at their own level
        graph = `[2:a]adelay=${delay}|${delay},volume=${vol}[narr];` +
          `[1:a][narr]amix=${amixOpts}:normalize=0[aout]`;
      } else {
        // older builds normalise by the input count; pre-compensate by the same
        // factor so the generated mix keeps its level (2 inputs → ×2, ÷2 = 1×)
        graph = `[2:a]adelay=${delay}|${delay},volume=${vol * 2}[narr];` +
          `[1:a]volume=2[mix];[mix][narr]amix=${amixOpts}[aout]`;
      }
      args.push('-filter_complex', graph);
      args.push('-map', '0:v:0', '-map', '[aout]');
    } else {
      args.push('-map', '0:v:0', '-map', '1:a:0');
    }
    args.push('-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-ar', '44100', '-ac', '2', '-shortest');
    args.push('-movflags', '+faststart', outPath);
    const proc = spawn(opts.ffmpegPath, args, { stdio: ['ignore', 'inherit', 'pipe'] });
    let stderr = '';
    proc.stderr.on('data', (d) => { stderr += d.toString(); });
    proc.on('error', reject);
    proc.on('close', (code) => code === 0 ? resolve(outPath) : reject(new Error('mux failed (' + code + '): ' + stderr.slice(-600))));
  });
}

/**
 * Render a project to MP4.
 * opts: { width, height, fps, includeAudio, narration:{path,volume,offset}, onProgress, job }
 */
async function renderToMp4(project, outPath, opts = {}, onProgress, job) {
  project = Story.normalizeProject(project);
  const started = Date.now();
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sas-'));
  const silent = path.join(tmpDir, 'silent.mp4');
  const result = { steps: [] };

  const video = await renderSilentVideo(project, silent, opts, onProgress, job);
  result.video = video;
  result.steps.push({ step: 'encode-video', frames: video.frames, ms: Date.now() - started, ffmpeg: video.ffmpeg.source });

  let includeAudio = opts.includeAudio !== false;
  const wav = includeAudio ? writeAudioWav(project, path.join(tmpDir, 'audio.wav')) : null;
  const narration = opts.narration && opts.narration.path && fs.existsSync(opts.narration.path) ? opts.narration : null;

  if (wav || narration) {
    const mixPath = (narration && !wav) ? (() => {
      // narration only: build a silent bed so amix has two inputs consistent
      const silentWav = path.join(tmpDir, 'silent.wav');
      const pcm = { left: new Float32Array(Math.ceil(Render.totalDuration(project) * 44100)), right: new Float32Array(Math.ceil(Render.totalDuration(project) * 44100)), sampleRate: 44100, duration: Render.totalDuration(project), empty: false };
      fs.writeFileSync(silentWav, Audio.toWav(pcm));
      return silentWav;
    })() : wav.path;
    const caps = await ffmpeg.capabilities(video.ffmpeg.path);
    await muxAudio(silent, mixPath, outPath, { ffmpegPath: video.ffmpeg.path, amixNormalize: caps.amixNormalize }, narration);
    result.steps.push({ step: 'mux-audio', audio: wav ? 'generated' : 'narration-only', narration: !!narration, amixNormalize: caps.amixNormalize });
  } else {
    fs.copyFileSync(silent, outPath);
    result.steps.push({ step: 'video-only' });
  }

  try { fs.rmSync(tmpDir, { recursive: true, force: true }); } catch (e) { }
  const stat = fs.statSync(outPath);
  result.file = { path: outPath, bytes: stat.size, durationSec: Render.totalDuration(project), fps: video.fps, width: video.width, height: video.height };
  result.ms = Date.now() - started;
  return result;
}

/** Render a single frame to PNG — used by the API for thumbnails and by tests. */
function renderStill(project, timeSec, opts = {}) {
  registerFonts();
  const W = opts.width || 960, H = opts.height || 540;
  const canvas = createCanvas(W, H);
  const ctx = canvas.getContext('2d');
  Render.renderFrame(ctx, Story.normalizeProject(project), timeSec, { W, H, fontFamily: FONT_FAMILY });
  return canvas.toBuffer('image/png');
}

/** Render the storyboard strip: one small PNG per scene at its mid-point. */
function renderStoryboard(project, opts = {}) {
  const p = Story.normalizeProject(project);
  const W = opts.width || 480, H = opts.height || 270;
  const canvas = createCanvas(W, H * p.scenes.length);
  const ctx = canvas.getContext('2d');
  registerFonts();
  let start = 0;
  p.scenes.forEach((s, i) => {
    const t = s.duration * 0.55;
    ctx.save();
    ctx.translate(0, i * H);
    ctx.beginPath(); ctx.rect(0, 0, W, H); ctx.clip();
    Render.renderScene(ctx, p, i, t, { W, H, fontFamily: FONT_FAMILY, sceneAlpha: 1 });
    ctx.restore();
    start += s.duration;
  });
  return canvas.toBuffer('image/png');
}

function probeWithFfmpeg(ffmpegPath, file) {
  return new Promise((resolve) => {
    const proc = spawn(ffmpegPath, ['-hide_banner', '-i', file], { stdio: ['ignore', 'ignore', 'pipe'] });
    let out = '';
    proc.stderr.on('data', (d) => { out += d.toString(); });
    proc.on('close', () => {
      const dur = /Duration:\s*(\d+):(\d+):(\d+\.\d+)/.exec(out);
      const size = /(\d{2,5})x(\d{2,5})/.exec(out);
      const fps = /([\d.]+) fps/.exec(out);
      const vcodec = /Video:\s*([^,(]+)/.exec(out);
      const pix = /(yuv\w+|rgba|nv12|rgb24)/.exec(out);
      const aud = /Audio:\s*([^,]+)/.exec(out);
      resolve({
        duration: dur ? (+dur[1]) * 3600 + (+dur[2]) * 60 + parseFloat(dur[3]) : null,
        video: size ? {
          codec: vcodec ? vcodec[1].trim() : 'h264', pixFmt: pix ? pix[1] : null,
          width: +size[1], height: +size[2], fps: fps ? parseFloat(fps[1]) : null
        } : null,
        audio: aud ? { codec: aud[1].trim() } : null,
        raw: out.split('\n').filter((l) => /Duration|Stream/.test(l)).join('\n')
      });
    });
  });
}

module.exports = { renderToMp4, renderStill, renderStoryboard, probeWithFfmpeg, registerFonts, FONT_FAMILY, Core, Render, Audio, Story };
