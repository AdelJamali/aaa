/* End-to-end render test: real 1080p30 MP4 with audio, then verify the file.
 * usage: node scripts/test-render.js [width] [height] [label]
 */
const fs = require('fs');
const path = require('path');
const renderer = require('../server/render.js');
const ffmpeg = require('../server/ffmpeg.js');

const W = parseInt(process.argv[2] || '1920', 10);
const H = parseInt(process.argv[3] || '1080', 10);
const label = process.argv[4] || 'test';

(async () => {
  const project = renderer.Story.createDemoProject();
  const outDir = path.join(__dirname, '..', 'data', 'exports');
  fs.mkdirSync(outDir, { recursive: true });
  const out = path.join(outDir, `${project.id}-${label}-${W}x${H}.mp4`);

  console.log(`[test] project "${project.title}" — ${renderer.Render.totalDuration(project)}s @ ${W}x${H} 30fps`);
  let lastLog = 0;
  const t0 = Date.now();
  const result = await renderer.renderToMp4(project, out, {
    width: W, height: H, fps: 30, includeAudio: true, crf: 18, preset: 'veryfast'
  }, (p) => {
    if (Date.now() - lastLog > 4000 || p.frame === p.totalFrames) {
      lastLog = Date.now();
      process.stdout.write(`\r[test] frame ${p.frame}/${p.totalFrames} (${(p.ratio * 100).toFixed(1)}%)  ${((Date.now() - t0) / 1000).toFixed(1)}s`);
    }
  });
  process.stdout.write('\n');
  const probe = await renderer.probeWithFfmpeg(result.video.ffmpeg.path, out);
  // prove the soundtrack actually made it into the container (not a silent track)
  const audioStats = await new Promise((resolve) => {
    const { spawn } = require('child_process');
    const proc = spawn(result.video.ffmpeg.path, ['-hide_banner', '-i', out, '-map', '0:a:0', '-af', 'volumedetect', '-f', 'null', '-'], { stdio: ['ignore', 'ignore', 'pipe'] });
    let out2 = '';
    proc.stderr.on('data', (d) => { out2 += d.toString(); });
    proc.on('close', () => {
      const mean = /mean_volume:\s*(-?[\d.]+) dB/.exec(out2);
      const max = /max_volume:\s*(-?[\d.]+) dB/.exec(out2);
      resolve({ meanVolumeDb: mean ? parseFloat(mean[1]) : null, maxVolumeDb: max ? parseFloat(max[1]) : null });
    });
  });
  const report = {
    file: out, bytes: result.file.bytes, humanBytes: (result.file.bytes / 1048576).toFixed(2) + ' MB',
    durationSec: result.file.durationSec, renderMs: result.ms, ffmpeg: result.video.ffmpeg.version, ffmpegSource: result.video.ffmpeg.source,
    steps: result.steps, probe, audioStats
  };
  fs.writeFileSync(path.join(outDir, 'last-report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  const checks = {
    'playable H.264 video': !!(probe && probe.video && /h264/.test(probe.video.codec)),
    'requested resolution': !!(probe && probe.video && probe.video.width === W && probe.video.height === H),
    'duration is 15.00s (within 0.3s)': Math.abs(((probe && probe.duration) || 0) - 15) < 0.3,
    'audio track present (AAC)': !!(probe && probe.audio && /aac/i.test(probe.audio.codec)),
    'audio is not silent (mean > -50 dB)': audioStats.meanVolumeDb !== null && audioStats.meanVolumeDb > -50,
    'file is a real download (> 200 kB)': result.file.bytes > 200 * 1024
  };
  Object.keys(checks).forEach((k) => console.log('   ' + (checks[k] ? 'PASS' : 'FAIL') + '  ' + k));
  const ok = Object.keys(checks).every((k) => checks[k]);
  console.log(ok ? '\n[test] PASS — playable H.264 MP4 with audio at the requested size' : '\n[test] FAIL — see the checks above');
  process.exit(ok ? 0 : 1);
})().catch((e) => { console.error('[test] error', e); process.exit(1); });
