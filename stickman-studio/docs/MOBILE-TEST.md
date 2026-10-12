# Mobile test script (Android Chrome)

Tap-by-tap checklist. Everything here is a real interaction — there are no stub
buttons in this app: **Render MP4** always produces a file, or reports a real error.

## 0 · Before you start

1. Start the server on your computer: `cd stickman-studio && npm start`
2. Find the computer's LAN address, e.g. `192.168.1.20`.
3. On the phone (same Wi-Fi) open `http://192.168.1.20:4173`.
4. Chrome menu ⋮ → **Add to Home screen**. Launch it from the icon: the header shows
   `FFmpeg … ready` in green inside a few seconds.

> If the chip says *FFmpeg unavailable*, run `npm run setup-ffmpeg` on the server and
> reload. Export needs the binary; preview does not.

## 1 · Playback (the demo loads automatically)

| Step | Action | Expected |
|---|---|---|
| 1 | Tap **▶** | The 15 s story plays at ~30 fps; the red time code advances |
| 2 | Watch scene 1 | Alarm reads `5:00`, ripples ring out, a small camera shake, `WAKE UP` callout |
| 3 | Scene 2 | He sits up, reaches to the nightstand, phone snaps into his hand, blanket returns |
| 4 | Scene 3 | Cut to black; **ANOTHER DAY WASTED** slams in red, heartbeat vignette pulses |
| 5 | Scene 4 | He stands, walks, pockets the phone, dumbbells appear in both hands, mirror reflects him |
| 6 | Scene 5 | **DISCIPLINE STARTS WITH ONE CHOICE** pops word by word; *ONE CHOICE* is red and underlined |
| 7 | Tap **⏸** | Picture freezes instantly |
| 8 | Drag the seek bar | Scrubbing is immediate and correct (no drift, no black frames) |
| 9 | Tap a scene chip | Jumps to that scene's start and highlights the chip |

## 2 · Audio

| Step | Action | Expected |
|---|---|---|
| 10 | Make sure **Audio** is ticked, tap **Play** | Alarm beeps in scene 1, boom at the title card, music bed in scenes 4–5 |
| 11 | Audio tab → **▶ Play the mix** | Playback starts at the current position; the waveform starts exactly there |
| 12 | Move **Music** / **Sound effects** sliders, press **▶ Play** again | The new balance is audible (the mix is re-rendered server-side) |
| 13 | Tap **⬇ Download mix (WAV)** | Chrome downloads a 15 s stereo WAV that plays in any player |
| 14 | (Optional) Audio tab → choose a voice recording, **Upload narration** | Status line confirms the size; the file becomes part of the export |

> Chrome requires a tap before any sound plays. If the first Play is silent, tap
> Play again — that is a browser autoplay rule, not a bug.

## 3 · Editing

| Step | Action | Expected |
|---|---|---|
| 15 | Script tab → **Insert example script** → **Build storyboard** | New scenes appear; the header shows the new length and frame count |
| 16 | Scene tab → drag **Duration** | Chips and total time update; clips are re-fitted so nothing dangles |
| 17 | Change a clip's **Mode** to *Loop an action* and pick `Exercise — dumbbell curl` | He curls continuously for the clip's span |
| 18 | Add a prop (`+ Add prop` → `Chair`) and drag **X** | Prop follows the slider live |
| 19 | Text: edit content with `**WORD**` | That word renders in red |
| 20 | Camera: set zoom start 1.0 → end 1.8 | Preview shows a push-in over the scene |
| 21 | Rotate the phone | Layout reflows; nothing is cut off; all buttons stay ≥ 44 px |

## 4 · Export (the important part)

| Step | Action | Expected |
|---|---|---|
| 22 | Export tab → keep `1080p` + `30 fps` → **Render MP4** | Progress bar with frame counter, %, elapsed and ETA |
| 23 | Keep the phone on screen | After ~30 s (2 vCPU server) status becomes *Done* |
| 24 | Read the report card | Real numbers: file name, MB, duration 15.00 s, `h264 · 1920×1080 · 30 fps · yuv420p`, `aac`, render time |
| 25 | Tap **⬇ Download MP4** | Chrome downloads the file (see the notification) |
| 26 | Open it in the **inline player** on the page | Plays with sound, 16:9, no cropping |
| 27 | Open **Files → Downloads** with any player (VLC / Google Photos / MX Player) | Plays at 1080p30 with audio |
| 28 | Share it to YouTube from the gallery | Uploads without re-encoding warnings |

Faster checks while developing:

* **540p draft** → ~8 s wall time for the same 15 s film.
* **Cancel render** mid-way → job reports *cancelled* and no partial file is offered.

## 5 · Saving and reopening

| Step | Action | Expected |
|---|---|---|
| 29 | Project tab → **Save to server** | Toast names the JSON file; it appears in "Projects on the server" |
| 30 | Refresh the phone browser | The local autosave restores your work (title shows `•`) |
| 31 | **⬇ Download project JSON** | A JSON file lands in Downloads |
| 32 | **⬆ Open JSON file** → pick it | Exact project is restored, including scenes, props, text, audio cues |
| 33 | **⬇ Download source zip** | ~830 KB zip with `shared/`, `server/`, `public/`, `scripts/`, `docs/`, fonts |

## 6 · Known limitations (by design, stated honestly)

* **Browser-side MP4**: this app never tries to record the canvas in the browser.
  `MediaRecorder` cannot produce MP4 in Chrome on Android (WebM only) and per-frame
  `captureStream` drops frames on mid-range phones, which would desynchronise the
  animation. Export therefore runs on the server with FFmpeg and the phone downloads
  a finished file.
* **Narration** is an upload, not synthetic speech — real TTS needs a paid API key,
  which this project deliberately avoids.
* **Preview resolution** is capped (640×360 / 960×540 / 1280×720) to keep scrolling
  smooth on phones; export is always full quality.
* One render job per server process (fine for personal use; add a queue for teams).
