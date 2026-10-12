# Architecture

## 1 · Layers

```
public/index.html + css/app.css + js/app.js     ← mobile UI (tabs, editors, timeline)
public/js/preview.js                            ← canvas player + audio sync
        │ uses
        ▼
shared/core.js      math, easing, seeded RNG, colour, text markup
shared/render.js    poses · skeleton · props · backgrounds · camera · text · effects · composer
shared/story.js     script parser, demo project, normalisation, timing fitting
shared/audio.js     deterministic cue synthesis → Float32 PCM → WAV
        │ (identical files are required by)
        ▼
server/index.js     Express API + static hosting + job registry + source zip
server/render.js    @napi-rs/canvas @1920×1080 → raw RGBA pipe → FFmpeg → MP4
server/ffmpeg.js    FFmpeg discovery/auto-provision (no paid service)
```

`shared/*` is UMD: `require()`d by Node, and loaded with `<script>` in the browser.
They attach to `window.SAS.{Core,Render,Story,Audio}`.

## 2 · The render contract

```js
Render.renderFrame(ctx, project, t /* seconds */, { W, H, fontFamily })
```

* **Pure**: the same `(project, t, W, H)` always produces the same pixels.
  Identical output across preview and export is what makes the MP4 trustworthy.
* No `Math.random()`, `Date.now()`, or locale-dependent formatting anywhere in the
  draw path. Randomness is seeded (`Core.mulberry32`, `Core.noise1`, `Core.fbm1`),
  so dust, grain, flicker and handheld drift are reproducible.
* Frames are rendered at `t = frame / fps`, `frame = 0 … round(duration·fps) − 1`.

### Coordinate systems

| Space | Where | Y axis | Origin |
|---|---|---|---|
| World | backgrounds, lights, camera | **up** | floor line of the scene |
| Skeleton | poses and joints | **up**, `y = 0` at the actor's anchor | pelvis at `hip` |
| Screen | actors, props, text | **down** | top-left of the frame |

`makeTransform()` converts world → screen and `drawActor()` flips the skeleton. The
stage is 10 world units wide; at zoom 1 the 1920 px frame is 192 px per unit. A
stickman is ~1.83 units (≈350 px) tall.

### Poses

Every pose is `{ hip, torso, head, headTilt, eyes, armL, armR, legL, legR, scale }`
with absolute joint angles in degrees, CCW from +x:

* `torso` 90 = upright, 0 = lying to the right, 174 = on the back
* `hip.y = LEG_LEN (0.84)` puts the feet on the floor
* `legL/R = { h: hip, k: knee, f: foot }`, `armL/R = { s: shoulder, e: elbow }`

`blendPose(a, b, u)` interpolates on the shortest angular path, so morph clips never
spin the wrong way. `clipAt(actor, t)` resolves a clip list into the pose at a time,
supporting:

| clip form | behaviour |
|---|---|
| `{ action }` | hold one pose |
| `{ action, loop: true }` | procedural action, phase = `(local / period) % 1` |
| `{ from, to }` | smooth morph between two poses |
| `{ action, blendIn }` | ease in from the previous clip |

### Props and backgrounds

Props draw in **screen** space at the prop's base (x, y) and are scaled by
`prop.scale`; `PROP_SURFACE` documents where an actor may stand/sit/lie on each
(`bed: 0.46`, `nightstand: 0.62`, `desk: 0.72`, …). Backgrounds draw in **world**
space through the camera transform. Mirrors render a real, clipped, tinted
reflection of the actors in front of them.

## 3 · Audio

`Audio.renderPCM(project)` walks every scene, converts `scene.audio` cues into an
absolute-time list and synthesises each one with plain maths (no samples):

`alarmBeeps · roomtone · blanket · chime · boom · heartbeat · riser · whoosh ·
footsteps · clink · thunder · horn · music`

The result is a stereo `Float32Array` pair (44.1 kHz) which is:

* posted to the browser as a **WAV** (`POST /api/audio.wav`) for preview playback, so
  preview audio and export audio are the same signal, and downloaded with one tap;
* written to disk during export and muxed with `-c:a aac -b:a 192k`.

Narration is deliberately not synthesised — no fake TTS. `POST /api/narration/:id`
stores a real uploaded file, and the mux step mixes it with the generated bed at the
narration volume.

## 4 · Export pipeline

```
POST /api/render        → validates + normalises the project, returns a jobId immediately
job loop:
  canvas 1920×1080 → renderFrame(t) → canvas.data() (raw RGBA)
                   → ffmpeg.stdin  (rawvideo pipe)
  ffmpeg: -f rawvideo -pix_fmt rgba -s 1920x1080 -r 30 -i pipe:0
          -c:v libx264 -preset veryfast -crf 18 -pix_fmt yuv420p
          -profile:v high -level 4.1 -x264-params keyint=60:scenecut=0
          -movflags +faststart  silent.mp4
  then: ffmpeg -i silent.mp4 -i audio.wav -map 0:v -map 1:a -c:v copy -c:a aac …
  then: probe the output (duration/size/codecs/fps) and hand back a download URL
GET /api/render/:id         → progress (frame, %, ETA) for the UI
POST /api/render/:id/cancel → kills the encoder
GET /api/render/:id/download, GET /api/export/:file → the actual file
```

Throughput measured in this container (2 vCPU, veryfast, CRF 18):

| Output | Frames | Wall time |
|---|---|---|
| 1920×1080 | 450 | ≈ 26 s |
| 960×540 | 450 | ≈ 8 s |

One render at a time is the practical limit per process; the job registry reports
`activeJobs` in `/api/health` so a queue can be layered on top if you deploy this
for several users.

## 5 · Determinism guarantees

* `frame N` is always rendered at `t = N / fps` — no wall-clock coupling.
* Fixed keyframe interval (`keyint=60`, `scenecut=0`) keeps seeking predictable.
* Audio is a pure function of the project, so the waveform lines up with the picture
  on every re-export.
* The preview canvas is *not* used as an export source: the server re-renders every
  frame from the JSON, so a slow phone cannot drop frames from the final file.
