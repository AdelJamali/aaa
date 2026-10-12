# Stickman Animation Studio

A mobile-first web app that turns an **English script** into an **original stickman
motivational animation** and exports a **real 1080p MP4 at 30 fps** — built to be
driven entirely from an Android phone in Chrome.

Preview and export use the *same* deterministic renderer, so what you see on the
phone is what FFmpeg encodes.

```
┌─ phone browser ─────────────────────────────┐        ┌─ node server ──────────────────┐
│ Script → storyboard (shared/story.js)       │        │ /api/render                    │
│ canvas preview @ low-res (shared/render.js) │  HTTP  │  → @napi-rs/canvas 1920×1080   │
│ timeline, scene editor, audio mix (WAV)     │ ─────► │  → raw RGBA pipe → FFmpeg      │
│ MP4 download                                │ ◄───── │  → H.264 + AAC .mp4            │
└─────────────────────────────────────────────┘        └────────────────────────────────┘
```

---

## 1 · Run it

```bash
cd stickman-studio
npm install            # express, @napi-rs/canvas, adm-zip, @ffmpeg-installer
npm start              # http://0.0.0.0:4173
```

Open `http://<your-computer-ip>:4173` on the phone (same Wi-Fi) and
“Add to Home screen” for a full-screen app.

```bash
npm run setup-ffmpeg   # optional: pre-fetch FFmpeg so the first export is instant
npm test               # renders the 15s demo to 1080p MP4 and verifies the file
node scripts/contact-sheet.js   # PNG storyboard of every scene (visual QA)
```

### Requirements

| Thing | Why | Cost |
|---|---|---|
| Node 18+ | server + renderer | free |
| FFmpeg | H.264/AAC encoding | free — resolved automatically (see below) |
| `@napi-rs/canvas` | server-side frame drawing (prebuilt, no system Cairo needed) | free |
| Optional: a voice recording | narration track | your mic, or any TTS you already use |

**No paid service and no API key is required anywhere in this project.**

FFmpeg resolution order (`server/ffmpeg.js`):
1. `$FFMPEG_PATH`
2. `ffmpeg` on `PATH`
3. the `@ffmpeg-installer/ffmpeg` npm package (an optionalDependency, installed by default)
4. one-time download of the *same* public static build from the npm registry into
   `vendor/` or `~/.cache/stickman-studio/`

---

## 2 · The workflow

1. **Script tab** — paste an English script (`Scene 1 (3s): …` per line, or plain
   paragraphs). Press **Build storyboard from script**. Keywords such as *alarm,
   phone, bed, work, exercise, run, fail, celebrate, mirror, stairs* select the
   matching pose, prop and background; a punch line becomes on-screen text with
   automatic red emphasis; clips are laid out back-to-back with smooth morphs.
2. **Scene tab** — edit the beat, duration, narration, background (8 coded sets),
   stickman clips (morph / hold / loop), props (16 reusable props), on-screen text
   (animation + red emphasis), camera zoom/pan/handheld, lights, effects, transitions.
3. **Audio tab** — generated music bed, sirens/booms/heartbeats/whooshes… per scene,
   volume mixer, optional narration upload.
4. **Export tab** — Render MP4 → live frame-by-frame progress → **Download MP4**
   (plus an inline player, a verified file report and a list of past exports).
5. **Project tab** — save/reopen editable JSON on the server, download the JSON, or
   grab the whole source as a zip.

### The bundled 15-second demo

Five scenes, 450 frames, 15.00 s exactly:

| # | Beat | Key content |
|---|---|---|
| 1 | 3.0 s | Bedroom. Digital alarm reads **5:00**, ripples, screen-shake, “WAKE UP” callout |
| 2 | 2.6 s | He wakes, sits up, reaches across to the nightstand, takes the phone, slides back under the blanket |
| 3 | 2.6 s | Hard cut to black. **ANOTHER DAY WASTED** slams in red with a heartbeat vignette |
| 4 | 4.3 s | He sits up on the edge, stands, walks, pockets the phone, dumbbells appear in both hands, curls in front of a mirror that reflects him |
| 5 | 2.5 s | He jumps, fists up: **DISCIPLINE STARTS WITH ONE CHOICE** with *ONE CHOICE* in red |

---

## 3 · What is deterministic (and why that matters)

`Render.renderFrame(ctx, project, t)` is a pure function of the project JSON and
the time `t`:

* no `Math.random()`, no `Date.now()` — seeded value noise (`Core.mulberry32`,
  `Core.noise1`) drives dust, grain, flicker and handheld drift;
* audio is synthesised from the same fixed formulas (`shared/audio.js`);
* the server renders `frame = 0…totalFrames-1` at `t = frame / fps`, so a re-export
  is byte-stable in content (identical x264 output for identical settings).

Export settings: H.264 High profile, `yuv420p`, CRF 18, keyframe every 2 s
(`scenecut=0`), `+faststart`, AAC 192 kbps 44.1 kHz stereo.

---

## 4 · Android Chrome notes (important)

* `<audio>` playback needs a user gesture: tap **Play** or **▶ Play the mix** first.
* The phone never renders the MP4 — it only downloads it. See `docs/MOBILE-TEST.md`
  for the exact tap-by-tap test script.
* Low/battery-saver mode throttles the preview canvas a little; the exported file is
  unaffected because rendering happens on the server.
* Video export is intentionally *not* done in the browser: see
  `docs/DEPLOY.md` for why (MediaRecorder gives WebM, and per-frame canvas
  `captureStream` drops frames on mid-range Android).

---

## 5 · Project files

```
stickman-studio/
├── shared/            core.js (math, easing, RNG) · render.js (poses, props, FX, camera, text)
│                      story.js (script → scenes, demo project) · audio.js (synthesis + WAV)
├── server/            index.js (Express API) · render.js (canvas → FFmpeg → MP4) · ffmpeg.js
├── public/            index.html · css/app.css · js/app.js · js/preview.js · fonts/ · manifest
├── scripts/           test-render.js · contact-sheet.js · setup-ffmpeg.js
├── docs/              ARCHITECTURE.md · MOBILE-TEST.md · DEPLOY.md · PROMPT-PACK.md · TEST-REPORT.md
└── data/              projects/ (saved JSON) · exports/ (MP4 + reports) · media/ (narration)
```

License: MIT for the code (`LICENSE.txt`); the bundled DejaVu fonts are under the
Bitstream Vera license and may be redistributed with the app.

---

## 6 · Deploying for real use

Any host that can run Node and FFmpeg works (a $5 VPS, Fly.io, Railway, a Docker
container). `docs/DEPLOY.md` has the Dockerfile, the reverse-proxy notes, the
job/queue behaviour (one render job per process, cancel-supported) and a
client-side rendering variant if you would rather not run a server.

Paid/3rd-party services needed: **none**.
