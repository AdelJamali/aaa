/* Pre-fetch the FFmpeg binary so the first export does not wait.
 * usage: npm run setup-ffmpeg
 */
const ffmpeg = require('../server/ffmpeg.js');
ffmpeg.ensure((m) => console.log('[ffmpeg]', m)).then((r) => {
  console.log('FFmpeg ready');
  console.log('  path   :', r.path);
  console.log('  version:', r.version);
  console.log('  source :', r.source);
}).catch((e) => {
  console.error('Could not prepare FFmpeg:', e.message);
  console.error('Install ffmpeg yourself and set FFMPEG_PATH=/path/to/ffmpeg');
  process.exit(1);
});
