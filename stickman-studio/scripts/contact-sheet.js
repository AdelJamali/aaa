/* Renders a contact sheet PNG so the whole story can be eyeballed at a glance.
 * usage: node scripts/contact-sheet.js [outFile]
 */
const fs = require('fs');
const path = require('path');
const R = require('../server/render.js');
const { createCanvas } = require('@napi-rs/canvas');

const project = process.argv[2] && process.argv[2].endsWith('.json')
  ? JSON.parse(fs.readFileSync(process.argv[2], 'utf8'))
  : R.Story.createDemoProject();
R.registerFonts();

const W = 480, H = 270, cols = 4;
// pick beats: absolute times across the whole timeline
const total = R.Render.totalDuration(project);
const marks = [];
let acc = 0;
project.scenes.forEach((s, i) => {
  const d = s.duration || 3;
  marks.push({ t: acc + d * 0.25, label: `S${i + 1} 25%` });
  marks.push({ t: acc + d * 0.72, label: `S${i + 1} 72%` });
  acc += d;
});
const rows = Math.ceil(marks.length / cols);
const canvas = createCanvas(W * cols, H * rows);
const ctx = canvas.getContext('2d');
ctx.fillStyle = '#000'; ctx.fillRect(0, 0, W * cols, H * rows);
marks.forEach((m, i) => {
  const col = i % cols, row = Math.floor(i / cols);
  ctx.save();
  ctx.translate(col * W, row * H);
  ctx.beginPath(); ctx.rect(0, 0, W, H); ctx.clip();
  R.Render.renderFrame(ctx, project, m.t, { W, H, fontFamily: R.FONT_FAMILY, grain: false });
  ctx.fillStyle = 'rgba(0,0,0,0.65)'; ctx.fillRect(0, H - 22, W, 22);
  ctx.fillStyle = '#7fe7ff'; ctx.font = 'bold 14px "DejaVu Sans"';
  ctx.fillText(`${m.label}  t=${m.t.toFixed(2)}s`, 8, H - 6);
  ctx.restore();
});
const out = process.argv[3] || 'data/test/contact-sheet.png';
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, canvas.toBuffer('image/png'));
console.log('wrote', out, `${W * cols}x${H * rows}`, 'total', total.toFixed(2) + 's');
