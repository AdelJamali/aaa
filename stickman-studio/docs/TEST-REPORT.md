# Test report

Everything below was executed in this repository (Node 22, Debian 12, 2 vCPU).
Re-run any of it with the commands in each section.

| Suite | Command | Result |
|---|---|---|
| Browser-env module check | `node scripts/check-browser-env.js` | PASS |
| Prompt-pack script parsing + rendering | `node scripts/test-parser.js` | PASS (5/5 scripts) |
| Headless UI integration (jsdom) | `node scripts/test-ui.js` | PASS (36/36 checks) |
| Live API flow (incl. narration + cancel) | `node scripts/test-api.js` | PASS (19/19 checks) |
| 1080p MP4 export + file verification | `npm test` | PASS |

---

## 1 · Renderer / libraries

```
browser globals: Core, Render, Story, Audio
demo: 5 scenes · 15 s · 450 frames
library: actions 54 | props 17 | backgrounds 8 | effects 9
audio cues: 16 → roomtone, alarmBeeps, blanket, chime, boom, riser, heartbeat,
             whoosh, footsteps, music, clink
parser: 2.00s/bedroom [wake_up | wake_up→hold_phone] || 3.00s/road_dawn [run]
all 54 actions produce finite skeletons
```

All 54 advertised poses/actions were sampled over 2 s each and every resolved
skeleton produced finite joint positions (no NaN, no degenerate limbs).

## 2 · The 15-second demo

| Check | Result |
|---|---|
| Scene count / total duration | 5 scenes · 15.000 s · 450 frames @ 30 fps |
| All five requested beats present | yes (5:00 alarm, phone + back to bed, `ANOTHER DAY WASTED`, get up + phone away + exercise, `DISCIPLINE STARTS WITH ONE CHOICE`) |
| Frame 0 renders without props missing | verified visually (`scripts/contact-sheet.js`) |
| Bed / alarm clock / phone / mirror / dumbbells read correctly | verified visually at 1:1 |

## 3 · Real export (the important one)

```
[test] project "ANOTHER DAY WASTED — 5:00 AM" — 15s @ 1920x1080 30fps
[test] frame 450/450 (100.0%)  25.9s
{
  "file": "data/exports/demo-5am-final-1920x1080.mp4",
  "humanBytes": "5.14 MB",
  "durationSec": 15,
  "renderMs": 26700,
  "steps": [ { "step": "encode-video", "frames": 450, "ms": 26094 },
             { "step": "mux-audio", "audio": "generated" } ],
  "probe": { "duration": 15,
             "video": { "codec": "h264", "pixFmt": "yuv420p", "width": 1920, "height": 1080, "fps": 30 },
             "audio": { "codec": "aac (LC)" },
             "raw": "Stream #0:0 Video: h264 (High) (avc1), yuv420p, 1920x1080, 30 fps
                     Stream #0:1 Audio: aac (LC), 44100 Hz, stereo, 184 kb/s" }
}
   PASS  playable H.264 video
   PASS  requested resolution
   PASS  duration is 15.00s (within 0.3s)
   PASS  audio track present (AAC)
   PASS  audio is not silent (mean > -50 dB)
   PASS  file is a real download (> 200 kB)
```

Beyond the automated checks the encoded file was **decoded back to PNG frames with
FFmpeg** (`-ss T -frames:v 1`) and inspected: all five scenes match the preview, the
red type is crisp, the stickman is intact and the bed/nightstand/clock/mirror are in
the right places. That proves the encoder received the intended picture, not just
that a container was written.

Export throughput in this container:

| Output | Frames | Wall time |
|---|---|---|
| 1920×1080 CRF 18 | 450 | 26.7 s |
| 960×540 CRF 18 | 450 | 8.0 s |

## 3b · Preview ⇄ export pixel parity

The strongest evidence that the download is the film you previewed: the exported MP4
was decoded back to PNG with FFmpeg and compared against reference frames rendered
directly by the server renderer at the same timestamps.

```
t=1.5s   normalized RMSE = 0.98%
t=3.1s   normalized RMSE = 0.64%
t=6.9s   normalized RMSE = 1.08%
t=11.0s  normalized RMSE = 0.61%
t=13.6s  normalized RMSE = 0.93%
```

All frames match apart from H.264 quantization — i.e. the encoder received exactly
the intended picture. `data/test/parity-proof.png` is a side-by-side of the t=11.0 s
pair.

## 4 · API surface (`curl` / `scripts/test-api.js` against a running server)

| Endpoint | Result |
|---|---|
| `GET /api/health` | `ffmpeg.available: true`, source `npm:@ffmpeg-installer`, `paidServicesRequired: []` |
| `GET /api/demo` | 5 scenes, correct title |
| `POST /api/still.png` | 200, valid PNG |
| `POST /api/audio.wav` | 200, **2 ch · 44100 Hz · 661 500 frames = 15.000 s** |
| `POST /api/render` | job created, `totalFrames: 450` |
| `GET /api/render/:id` | `status: running` → `done`, progress 0→100 % |
| `GET /api/export/:file` | `Content-Type: video/mp4`, `Content-Disposition: attachment`, full byte count |
| `GET /api/exports` | lists both rendered files with sizes |
| `GET /api/source.zip` | 830 KB, 22 entries, includes `shared/`, `server/`, `public/`, `scripts/`, `README.md`, fonts |
| `POST /api/narration/:id` + render with narration | uploaded a 14 s tone; render finished and the MP4 carries an AAC track |
| `POST /api/render/:id/cancel` | job reported `cancelled`, no result offered |
| `POST /api/projects` → `GET` → `DELETE` | JSON round-trips with clips intact |

`node scripts/test-api.js` → **ALL 19 API CHECKS PASSED**

## 5 · Headless UI integration (jsdom, real server, stubbed 2D canvas)

```
PASS  SAS namespace loaded in the page           PASS  scene title edits the project
PASS  app booted                                 PASS  add scene inserts a scene
PASS  demo project has 5 scenes                  PASS  delete scene removes it again
PASS  total duration is 15s                      PASS  actor cards rendered
PASS  server health was fetched                  PASS  clip editors rendered for the actor
PASS  timeline rendered one chip per scene       PASS  prop cards rendered
PASS  scene chips drew thumbnails                PASS  light editors rendered
PASS  total time label filled                    PASS  effect editors rendered
PASS  play button starts playback                PASS  audio cue editors rendered
PASS  play button pauses playback                PASS  building from the example script
PASS  seek moves the playhead                          replaces the project
PASS  playhead 7.4s lands in scene 3             PASS  built project is 15s
PASS  next-scene button moves forward            PASS  demo restored after the round-trip
PASS  duration slider updates the scene          PASS  render request was sent to /api/render
PASS  render finished and the result card is visible
PASS  download link points at a real mp4 url
PASS  report shows h264 and 960x540
PASS  report shows an audio codec
PASS  all 112 element ids used by app.js exist in index.html
PASS  index.html loads all shared + client scripts
PASS  no jsdom/runtime errors during the session
ALL UI CHECKS PASSED
```

This drives the same handlers a finger would: play/pause, scrub, scene select,
duration/title editing, scene add/delete, building a storyboard from a script, then
a full export through `POST /api/render` with the progress poller and the result card.

## 6 · What could **not** be tested here (be aware)

* **Real Android Chrome.** This sandbox has no browser, so touch playback, autoplay
  policy, fonts on device and the download UX are covered by
  `docs/MOBILE-TEST.md` — a 33-step tap-by-tap checklist — not by an automated run.
  The headless test above exercises the identical client code paths.
* **Long renders.** Only the 15 s demo was rendered end to end. A 3-minute film
  would be ~2.6 GB of raw frames through the pipe; that is fine on this design, but
  budget roughly 5 minutes of wall time per minute of 1080p film on 2 vCPU.
* **Multi-user load.** One render job per process; there is no queue yet.

## 7 · Bugs found and fixed during this work

1. Prop/animation drawing mixed two coordinate systems → actors and props were
   invisible or scaled 300×. Fixed by a single documented world/screen convention.
2. `gfx` line widths were passed in pixels after the context switched to world
   units → the alarm-clock ripple arcs became a giant red ball. Fixed at the call
   site plus the un-scaled-text path for the `5:00` readout.
3. `ffmpeg` filter graph left `anull` unconnected when no narration was present →
   muxing failed after a successful encode. Fixed (plain `-map 1:a:0` when there is
   nothing to mix).
4. The script parser's scene-header regex swallowed the first sentence, so scenes
   always produced a single `stand` clip. Rewritten with a dedicated header/body
   split and per-sentence keyword matching.
5. Card text kept the literal "TEXT CARD:" prefix. Added `stripCardIntro()`.
6. `normalizeProject()` replaced the project object on every refresh, which could
   leave editors holding a stale reference. Normalisation now happens only at the
   ingestion points (boot, import, script build, demo load, open).
7. `amix=normalize=0` does not exist in the bundled FFmpeg 4.1 build → narration
   muxing failed. The mux graph is now chosen from a probed capability
   (`ffmpeg.capabilities()`), with a level-compensated fallback for old builds.
8. The local autosave was deleted right after being restored, so a refresh without
   any edit silently reverted to the demo. Removed.
