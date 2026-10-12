/* Parses every script in docs/PROMPT-PACK.md and renders one frame per beat,
 * proving the generator output is renderable (not just parseable). */
const fs = require('fs');
const path = require('path');
const { createCanvas } = require('@napi-rs/canvas');
const renderer = require('../server/render.js');
const Story = renderer.Story, Render = renderer.Render;

renderer.registerFonts();
const pack = fs.readFileSync(path.join(__dirname, '..', 'docs', 'PROMPT-PACK.md'), 'utf8');
const blocks = pack.split(/^##\s+\d+.*$/m).slice(1).map((b) => {
  const m = /```\n([\s\S]*?)```/.exec(b);
  return m ? m[1].trim() : null;
}).filter(Boolean);

console.log('scripts found in prompt pack:', blocks.length);
let failures = 0;
blocks.forEach((script, i) => {
  const project = Story.parseScript(script, { totalDuration: 0, title: 'pack ' + (i + 1) });
  const total = Render.totalDuration(project);
  const cell = { W: 320, H: 180 };
  const cols = Math.min(4, project.scenes.length);
  const rows = Math.ceil(project.scenes.length / cols);
  const canvas = createCanvas(cell.W * cols, cell.H * rows);
  const ctx = canvas.getContext('2d');
  let ok = true;
  project.scenes.forEach((s, si) => {
    const col = si % cols, row = Math.floor(si / cols);
    ctx.save();
    ctx.translate(col * cell.W, row * cell.H);
    ctx.beginPath(); ctx.rect(0, 0, cell.W, cell.H); ctx.clip();
    const beats = [0.35, 0.75].map((k) => s.duration * k);
    try {
      Render.renderFrame(ctx, project, Render.sceneAt(project, 0) ? (project.scenes.slice(0, si).reduce((a, x) => a + x.duration, 0) + beats[0]) : 0,
        { W: cell.W, H: cell.H, fontFamily: renderer.FONT_FAMILY, grain: false });
    } catch (e) { ok = false; console.log('  !! render error in scene', si + 1, e.message); }
    ctx.fillStyle = 'rgba(0,0,0,0.6)'; ctx.fillRect(0, cell.H - 18, cell.W, 18);
    ctx.fillStyle = '#7fe7ff'; ctx.font = 'bold 11px "DejaVu Sans"';
    ctx.fillText('S' + (si + 1) + ' ' + s.background + ' ' + s.duration.toFixed(1) + 's', 6, cell.H - 5);
    ctx.restore();
  });
  const out = path.join(__dirname, '..', 'data', 'test', 'pack-' + (i + 1) + '.png');
  fs.writeFileSync(out, canvas.toBuffer('image/png'));
  const clips = project.scenes.map((s) => s.actors[0].clips.map((c) => (c.from ? c.from + '→' + c.to : c.action)).join('/'));
  console.log(`  #${i + 1}: ${project.scenes.length} scenes · ${total.toFixed(1)}s · ${ok ? 'renderable' : 'FAILED'} → ${path.basename(out)}`);
  console.log('      ' + clips.join('  |  '));
  if (!ok) failures++;
});
console.log(failures ? 'FAILURES: ' + failures : 'all prompt-pack scripts parse and render');
process.exit(failures ? 1 : 0);
