/* FFmpeg acquisition — no paid account, no API key.
 *
 * Resolution order:
 *   1. $FFMPEG_PATH
 *   2. ffmpeg on PATH
 *   3. node_modules/@ffmpeg-installer/ffmpeg (npm optionalDependency)
 *   4. the local vendor cache (auto-downloaded once from the public npm registry,
 *      the same static build the @ffmpeg-installer package ships)
 */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const https = require('https');
const zlib = require('zlib');
const { spawn, execFile } = require('child_process');

const VENDOR_DIR = path.join(__dirname, '..', 'vendor');
const CACHE_DIR = path.join(os.homedir(), '.cache', 'stickman-studio');
const PKG = '@ffmpeg-installer/linux-x64';
const PKG_VERSION = '4.1.0';
const REGISTRY = 'https://registry.npmjs.org';

let cached = null;

function exists(p) { try { return !!p && fs.existsSync(p); } catch (e) { return false; } }

function probe(bin) {
  return new Promise((resolve) => {
    if (!bin || !exists(bin)) return resolve(null);
    execFile(bin, ['-version'], { timeout: 15000 }, (err, stdout) => {
      if (err) return resolve(null);
      const first = String(stdout).split('\n')[0] || '';
      const has = (flag) => String(stdout).includes(flag);
      resolve({ path: bin, version: first.replace(/^ffmpeg version\s*/, '').split(' ')[0], banner: first });
    });
  });
}

function currentPlatformFolder() {
  const plat = process.platform;
  const arch = process.arch;
  if (plat === 'linux' && arch === 'x64') return { pkg: '@ffmpeg-installer/linux-x64', version: '4.1.0' };
  if (plat === 'linux' && arch === 'arm64') return { pkg: '@ffmpeg-installer/linux-arm64', version: '4.1.0' };
  if (plat === 'darwin' && arch === 'x64') return { pkg: '@ffmpeg-installer/darwin-x64', version: '4.1.5' };
  if (plat === 'darwin' && arch === 'arm64') return { pkg: '@ffmpeg-installer/darwin-arm64', version: '4.1.0' };
  if (plat === 'win32') return { pkg: '@ffmpeg-installer/win32-x64', version: '4.1.0' };
  return { pkg: null, version: null };
}

function download(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https.get(url, { timeout: 120000 }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        file.close(); fs.unlinkSync(dest);
        return download(res.headers.location, dest).then(resolve, reject);
      }
      if (res.statusCode !== 200) {
        file.close();
        return reject(new Error('download failed: HTTP ' + res.statusCode + ' for ' + url));
      }
      res.pipe(file);
      file.on('finish', () => file.close(() => resolve(dest)));
    }).on('error', (e) => { try { file.close(); } catch (_) { } reject(e); });
  });
}

// minimal tar extractor (ustar), enough for npm tarballs
function extractTarEntry(tarBuf, wantedSuffix, outPath) {
  let off = 0;
  let found = false;
  while (off + 512 <= tarBuf.length) {
    const name = tarBuf.toString('utf8', off, off + 100).replace(/\0.*$/, '');
    if (!name) { off += 512; continue; }
    const sizeStr = tarBuf.toString('utf8', off + 124, off + 136).replace(/\0.*$/, '').trim();
    const size = parseInt(sizeStr, 8) || 0;
    const type = tarBuf.toString('utf8', off + 156, off + 157);
    const dataStart = off + 512;
    if (name.endsWith(wantedSuffix) && (type === '0' || type === '\0' || type === '')) {
      fs.writeFileSync(outPath, tarBuf.subarray(dataStart, dataStart + size));
      fs.chmodSync(outPath, 0o755);
      found = true;
      break;
    }
    off = dataStart + Math.ceil(size / 512) * 512;
  }
  return found;
}

async function autoInstall(log) {
  const folder = currentPlatformFolder();
  if (!folder.pkg) throw new Error('no prebuilt FFmpeg for ' + process.platform + '/' + process.arch + ' — set FFMPEG_PATH');
  for (const dir of [CACHE_DIR, VENDOR_DIR]) {
    const target = path.join(dir, process.platform === 'win32' ? 'ffmpeg.exe' : 'ffmpeg');
    if (exists(target)) {
      const ok = await probe(target);
      if (ok) return Object.assign(ok, { source: 'cache' });
    }
    try {
      fs.mkdirSync(dir, { recursive: true });
    } catch (e) { continue; }
    const url = REGISTRY + '/' + folder.pkg + '/-/' + folder.pkg.replace('@', '').replace('/', '-') + '-' + folder.version + '.tgz';
    const tmp = path.join(dir, 'ffmpeg.tgz');
    if (log) log('downloading FFmpeg build from ' + url);
    await download(url, tmp);
    const gz = fs.readFileSync(tmp);
    const tar = zlib.gunzipSync(gz);
    const ok = extractTarEntry(tar, process.platform === 'win32' ? 'ffmpeg.exe' : '/ffmpeg', target);
    fs.unlinkSync(tmp);
    if (!ok) continue;
    const probed = await probe(target);
    if (probed) return Object.assign(probed, { source: 'downloaded' });
  }
  throw new Error('could not obtain an FFmpeg binary');
}

async function resolve(log) {
  if (cached) return cached;
  const envPath = process.env.FFMPEG_PATH;
  let r = await probe(envPath);
  if (r) { cached = Object.assign(r, { source: 'FFMPEG_PATH' }); return cached; }

  // PATH
  const pathDirs = (process.env.PATH || '').split(path.delimiter);
  for (const d of pathDirs) {
    const candidate = path.join(d, process.platform === 'win32' ? 'ffmpeg.exe' : 'ffmpeg');
    r = await probe(candidate);
    if (r) { cached = Object.assign(r, { source: 'PATH' }); return cached; }
  }

  // npm-installed static build
  try {
    const installer = require('@ffmpeg-installer/ffmpeg');
    r = await probe(installer.path);
    if (r) { cached = Object.assign(r, { source: 'npm:@ffmpeg-installer' }); return cached; }
  } catch (e) { /* optional dependency not installed */ }

  cached = Object.assign(await autoInstall(log), { ffmpegVersion: PKG_VERSION, pkg: PKG });
  return cached;
}

async function ensure(log) { return resolve(log); }

/* Filter capabilities differ between FFmpeg builds (the bundled static build is
 * 4.1 era: it has no `normalize` option on amix). Probe once per binary. */
const capsCache = new Map();
function capabilities(binPath) {
  if (capsCache.has(binPath)) return Promise.resolve(capsCache.get(binPath));
  return new Promise((resolve) => {
    execFile(binPath, ['-hide_banner', '-h', 'filter=amix'], { timeout: 10000 }, (err, stdout) => {
      const help = String(stdout || '');
      const caps = {
        amixNormalize: !err && /normalize/.test(help),
        amixWeights: !err && /weights/.test(help),
        rawversion: null
      };
      capsCache.set(binPath, caps);
      resolve(caps);
    });
  });
}

module.exports = { ensure, resolve, capabilities, VENDOR_DIR, CACHE_DIR, PKG };
