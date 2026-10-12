/* Stickman Animation Studio — story layer.
 *  * createDemoProject(): the 15 second "5:00 AM" demo, scene by scene.
 *  * parseScript(): turns a plain English script into editable scenes.
 *  * normalizeProject(): fills defaults so any JSON blob becomes renderable.
 * Shared by browser and server, so everything stays editable JSON.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./core.js'), require('./render.js'));
  } else {
    root.SAS = root.SAS || {};
    root.SAS.Story = factory(root.SAS.Core, root.SAS.Render);
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (C, R) {
  'use strict';

  var SCHEMA = 'stickman-studio/project@1';

  function uid(prefix) {
    uid.n = (uid.n || 0) + 1;
    return prefix + '_' + (uid.n).toString(36) + Math.floor(C.mulberry32(C.hashString(prefix) + uid.n)() * 1296).toString(36);
  }

  function newText(o) {
    return Object.assign({
      id: uid('text'), text: 'ON SCREEN TEXT',
      x: 0.5, y: 0.5, align: 'center',
      size: 0, weight: 'bold',
      color: '#ffffff', emphasisColor: '#ff2f45', dimColor: '#8c9aac',
      anim: 'fadeUp', animDuration: 0.7, glow: 0.35,
      maxWidthPct: 0.84, maxHeightPct: 0.32,
      start: 0, end: null, fadeOut: 0.35, panel: false, underline: false
    }, o || {});
  }
  function newProp(o) {
    return Object.assign({
      id: uid('prop'), type: 'bed', x: 3, y: 1.35, scale: 1, layer: 'back', opacity: 1, rot: 0
    }, o || {});
  }
  function newActor(o) {
    return Object.assign({
      id: uid('actor'), name: 'stickman', x: 5, y: 1.35, facing: 1, size: 1, opacity: 1,
      clips: [{ action: 'stand', start: 0, end: 3, loop: false }], attach: []
    }, o || {});
  }
  function newScene(o) {
    return Object.assign({
      id: uid('scene'), title: 'Scene', duration: 3,
      description: '', narration: '', background: 'void',
      camera: { x: 5, y: 2.5, zoom: 1 }, props: [], actors: [], texts: [], lights: [], effects: [], shakes: [],
      audio: [], transitionIn: { type: 'fadeFromBlack', duration: 0.4 },
      transitionOut: { type: 'cut', duration: 0 }, seed: 7
    }, o || {});
  }

  // ===========================================================================
  // The first demo — 15.0 s total, 5 scenes
  // ===========================================================================
  function createDemoProject() {
    // ---- stage layout constants (world units, 10 units = frame width at zoom 1)
    var FLOOR = 1.35;                      // floor line of the bedroom
    var BED = { x: 3.0, w: 3.2 };          // bed spans 1.4 .. 4.6, headboard on the left
    var BED_TOP = FLOOR + R.PROP_SURFACE.bed;   // mattress surface -> 1.81
    var NS = { x: 5.72, w: 1.05 };         // nightstand sits at the foot side of the bed
    var NS_TOP = FLOOR + R.PROP_SURFACE.nightstand; // 1.97
    // lying poses keep the hip 0.32 above the actor anchor: put that hip 0.10 above
    // the mattress so the body rests ON the bed instead of floating over it
    var LIE_Y = BED_TOP + 0.10 - 0.32;          // anchor while lying on the mattress
    var SIT_Y = BED_TOP + 0.05 - 0.44;          // anchor while sitting on the mattress edge

    // ---- scene 1: the alarm reads 5:00 and rings ---------------------------
    var s1 = newScene({
      title: 'Scene 1 — 5:00 AM',
      duration: 3.0,
      description: 'A digital alarm clock reads 5:00 AM on the nightstand and rings while he sleeps on.',
      narration: 'Five in the morning. The alarm starts screaming.',
      background: 'bedroom',
      seed: 11,
      transitionIn: { type: 'fadeFromBlack', duration: 0.5 },
      transitionOut: { type: 'cut', duration: 0 },
      props: [
        newProp({ type: 'window', x: BED.x + 0.4, y: FLOOR + 0.30, layer: 'back', w: 1.8, h: 1.9 }),
        newProp({ type: 'bed', x: BED.x, y: FLOOR, layer: 'back', w: BED.w }),
        newProp({ type: 'nightstand', x: NS.x, y: FLOOR, layer: 'back', w: NS.w }),
        newProp({ type: 'alarm_clock', x: NS.x - 0.05, y: NS_TOP, layer: 'front', w: 0.68, h: 0.40, time: '5:00', ringing: true }),
        // blanket covering the lower half of the sleeping body (in front of the actor)
        newProp({ type: 'blanket', x: BED.x + 0.15, y: BED_TOP + 0.07, layer: 'front', w: 1.55, h: 0.22, lift: 0.13 })
      ],
      actors: [newActor({
        name: 'sleeper', x: BED.x - 1.05, y: LIE_Y, facing: 1,
        clips: [{ action: 'lie_back_sleep', start: 0, end: 3.0, loop: true, period: 4.2 }]
      })],
      lights: [
        { type: 'godray', x: BED.x + 0.4, y: FLOOR + 2.4, len: 7, w: 1.5, rays: 4, angle: -68, spread: 9, color: 'rgba(150,190,245,1)', intensity: 0.85 },
        { type: 'spot', x: NS.x, y: NS_TOP + 0.12, r: 0.5, color: 'rgba(255,90,100,1)', intensityTrack: { keys: [{ t: 0.8, v: 0 }, { t: 0.95, v: 0.7 }] } }
      ],
      camera: {
        x: 3.8, y: 2.35, zoom: 1.35,
        xTrack: { keys: [{ t: 0, v: 3.9 }, { t: 1.1, v: 4.55, ease: 'inOutQuad' }, { t: 2.4, v: 5.62, ease: 'inOutCubic' }] },
        yTrack: { keys: [{ t: 0, v: 2.35 }, { t: 1.1, v: 2.28 }, { t: 2.4, v: 2.12 }] },
        zoomTrack: { keys: [{ t: 0, v: 1.35 }, { t: 1.1, v: 1.45, ease: 'inOutQuad' }, { t: 2.4, v: 1.95, ease: 'inOutCubic' }] }
      },
      shakes: [{ start: 0.95, end: 2.6, amp: 0.020 }],
      effects: [
        { type: 'impactRing', start: 0.92, duration: 0.7, color: '#ff2f45', alpha: 0.5, x: 0.78, y: 0.40 },
        { type: 'impactRing', start: 1.32, duration: 0.7, color: '#ff2f45', alpha: 0.36, x: 0.78, y: 0.40 },
        { type: 'textCallout', start: 1.05, duration: 1.5, text: 'WAKE UP', x: 0.5, y: 0.20, size: 0.042, color: '#ff2f45', alpha: 0.95 }
      ],
      audio: [
        { type: 'roomtone', at: 0, dur: 3.0, gain: 0.5 },
        { type: 'alarmBeeps', at: 0.9, dur: 2.1, gain: 0.95 }
      ]
    });

    // ---- scene 2: reach for the phone and crawl back to bed ----------------
    var s2 = newScene({
      title: 'Scene 2 — The phone',
      duration: 2.6,
      description: 'He wakes, swings up, reaches across to the nightstand for his phone and slides back under the blanket with it.',
      narration: 'One hand out of the blanket. Straight for the phone.',
      background: 'bedroom',
      seed: 11,
      transitionIn: { type: 'cut', duration: 0 },
      transitionOut: { type: 'dipToBlack', duration: 0.35 },
      props: [
        newProp({ type: 'window', x: BED.x + 0.4, y: FLOOR + 0.30, layer: 'back', w: 1.8, h: 1.9 }),
        newProp({ type: 'bed', x: BED.x, y: FLOOR, layer: 'back', w: BED.w }),
        newProp({ type: 'nightstand', x: NS.x, y: FLOOR, layer: 'back', w: NS.w }),
        newProp({ type: 'alarm_clock', x: NS.x - 0.30, y: NS_TOP, layer: 'front', w: 0.58, h: 0.34, time: '5:01' }),
        newProp({
          type: 'phone', x: NS.x + 0.22, y: NS_TOP, layer: 'front', w: 0.32, h: 0.58,
          opacityTrack: { keys: [{ t: 1.12, v: 1 }, { t: 1.20, v: 0 }] }
        }),
        newProp({
          type: 'blanket', x: BED.x + 0.55, y: BED_TOP + 0.07, layer: 'front', w: 1.7, h: 0.22, lift: 0.13,
          opacityTrack: { keys: [{ t: 0, v: 1 }, { t: 0.7, v: 0 }] }
        }),
        newProp({
          type: 'blanket', x: BED.x + 0.15, y: BED_TOP + 0.09, layer: 'front', w: 1.9, h: 0.24, lift: 0.15,
          opacityTrack: { keys: [{ t: 1.75, v: 0 }, { t: 2.0, v: 1 }] }
        })
      ],
      actors: [newActor({
        name: 'sleeper', x: BED.x - 1.05, y: LIE_Y, facing: 1,
        yTrack: { keys: [{ t: 0, v: LIE_Y }, { t: 0.45, v: LIE_Y }, { t: 1.25, v: SIT_Y }, { t: 1.70, v: SIT_Y }, { t: 2.30, v: LIE_Y }, { t: 2.6, v: LIE_Y }] },
        clips: [
          { from: 'lie_back_sleep', to: 'wake_up', start: 0, end: 0.7, morphEase: 'outCubic' },
          { from: 'wake_up', to: 'sit_reach', start: 0.7, end: 1.25, morphEase: 'inOutQuad' },
          { from: 'sit_reach', to: 'hold_phone_sit', start: 1.25, end: 1.62, morphEase: 'outCubic' },
          { from: 'hold_phone_sit', to: 'lie_back_phone', start: 1.62, end: 2.25, morphEase: 'inOutCubic' },
          { action: 'lie_back_phone', start: 2.25, end: 2.6, loop: true, period: 3.4 }
        ],
        attach: [{ type: 'phone', hand: 'right', scale: 0.85, from: 1.14, to: 2.35, w: 0.26, h: 0.50, on: true }]
      })],
      lights: [
        { type: 'godray', x: BED.x + 0.4, y: FLOOR + 2.4, len: 7, w: 1.5, rays: 4, angle: -68, spread: 9, color: 'rgba(150,190,245,1)', intensity: 0.75 },
        { type: 'spot', x: NS.x, y: NS_TOP + 0.12, r: 1.5, color: 'rgba(120,200,245,1)', intensity: 0.5 }
      ],
      camera: {
        x: 4.9, y: 2.15, zoom: 1.45,
        xTrack: { keys: [{ t: 0, v: 5.05 }, { t: 0.9, v: 4.6, ease: 'inOutCubic' }, { t: 1.9, v: 4.2, ease: 'inOutQuad' }, { t: 2.6, v: 4.0 }] },
        yTrack: { keys: [{ t: 0, v: 2.15 }, { t: 1.3, v: 2.3 }, { t: 2.6, v: 2.4 }] },
        zoomTrack: { keys: [{ t: 0, v: 1.45 }, { t: 1.2, v: 1.3, ease: 'inOutQuad' }, { t: 2.6, v: 1.14, ease: 'inOutCubic' }] }
      },
      effects: [],
      audio: [
        { type: 'roomtone', at: 0, dur: 2.6, gain: 0.42 },
        { type: 'blanket', at: 0.08, dur: 0.8, gain: 0.5 },
        { type: 'chime', at: 1.16, dur: 0.5, gain: 0.45 },
        { type: 'blanket', at: 1.85, dur: 0.7, gain: 0.4 }
      ]
    });

    // ---- scene 3: ANOTHER DAY WASTED ----------------------------------------
    var s3 = newScene({
      title: 'Scene 3 — ANOTHER DAY WASTED',
      duration: 2.6,
      description: 'Hard cut to black. Giant red text slams on screen while he slumps in the dark.',
      narration: 'Another day wasted.',
      background: 'void',
      seed: 23,
      transitionIn: { type: 'fadeFromBlack', duration: 0.15 },
      transitionOut: { type: 'cut', duration: 0 },
      props: [],
      actors: [newActor({
        name: 'stickman', x: 1.55, y: 0.70, facing: 1, opacity: 0.30,
        clips: [{ from: 'stand', to: 'fail_slump', start: 0, end: 0.7, morphEase: 'inCubic' },
        { action: 'fail_slump', start: 0.7, end: 2.6, loop: false }]
      })],
      lights: [],
      texts: [newText({
        text: 'ANOTHER DAY WASTED', x: 0.5, y: 0.45, size: 200, anim: 'slam', animDuration: 0.45,
        color: '#ff2f45', emphasisColor: '#ffb020', glow: 0.5, maxWidthPct: 0.72, maxHeightPct: 0.5,
        start: 0.2, end: 2.6, fadeOut: 0.3
      })],
      camera: { x: 5, y: 2.15, zoom: 1.08, zoomTrack: { keys: [{ t: 0, v: 1.08 }, { t: 2.6, v: 1.0 }] }, handheld: 0.5 },
      shakes: [{ start: 0.2, end: 0.55, amp: 0.05 }],
      effects: [
        { type: 'flash', start: 0.2, duration: 0.22, color: '#ffffff', alpha: 0.5 },
        { type: 'redPulse', start: 0, end: 2.6, persistent: true, alpha: 0.12, hz: 0.9, color: '#ff2f45' },
        { type: 'heartbeat', start: 0.6, end: 2.6, persistent: true, alpha: 0.22, hz: 1.15, color: '#ff2f45' },
        { type: 'speedLines', start: 0.25, duration: 0.6, color: '#ff5566', alpha: 0.16, count: 18 }
      ],
      audio: [
        { type: 'boom', at: 0.2, dur: 1.6, gain: 0.9 },
        { type: 'heartbeat', at: 0.6, dur: 2.0, gain: 0.55 },
        { type: 'riser', at: 0.25, dur: 0.6, gain: 0.35 }
      ]
    });

    // ---- scene 4: get up, put the phone away, start exercising --------------
    var s4 = newScene({
      title: 'Scene 4 — Get up and train',
      duration: 4.3,
      description: 'He gets off the bed, pockets the phone and starts curling dumbbells in front of the mirror.',
      narration: 'He gets up. He puts the phone away. He starts.',
      background: 'mirror_room',
      seed: 41,
      transitionIn: { type: 'barsOut', duration: 0.45 },
      transitionOut: { type: 'cut', duration: 0 },
      props: [
        newProp({ type: 'bed', x: 0.85, y: FLOOR, layer: 'back', w: 3.0 }),
        newProp({ type: 'mirror', x: 8.45, y: FLOOR + 0.05, layer: 'back', w: 1.7, h: 2.45, reflectOpacity: 0.42 }),
        newProp({ type: 'dumbbells', x: 6.45, y: FLOOR, layer: 'front', count: 2 }),
        newProp({
          type: 'dumbbells', x: 6.45, y: FLOOR, layer: 'front', count: 2,
          opacityTrack: { keys: [{ t: 2.45, v: 1 }, { t: 2.55, v: 0 }] }
        })
      ],
      actors: [newActor({
        name: 'stickman', x: 2.55, y: LIE_Y, facing: 1,
        xTrack: { keys: [{ t: 0, v: 2.55 }, { t: 0.80, v: 2.55 }, { t: 1.86, v: 5.55, ease: 'inOutQuad' }, { t: 4.3, v: 5.55 }] },
        yTrack: { keys: [{ t: 0, v: LIE_Y }, { t: 0.30, v: LIE_Y }, { t: 0.50, v: SIT_Y }, { t: 0.80, v: SIT_Y }, { t: 1.0, v: FLOOR }, { t: 4.3, v: FLOOR }] },
        clips: [
          { from: 'lie_back_sleep', to: 'sit', start: 0, end: 0.55, morphEase: 'outCubic' },
          { from: 'sit', to: 'stand', start: 0.55, end: 1.0, morphEase: 'outCubic' },
          { action: 'walk', start: 1.0, end: 1.95, loop: true, period: 1.05, params: { stride: 26, lift: 34 } },
          { from: 'walk', to: 'pocket_phone', start: 1.95, end: 2.32, morphEase: 'outCubic' },
          { from: 'pocket_phone', to: 'dumbbell_curl', start: 2.32, end: 2.62, morphEase: 'inOutCubic' },
          { action: 'dumbbell_curl', start: 2.62, end: 4.3, loop: true, period: 1.7 }
        ],
        attach: [
          { type: 'phone', hand: 'right', scale: 0.85, from: 0, to: 2.35, w: 0.26, h: 0.50, on: true },
          { type: 'dumbbell', hand: 'left', scale: 1.0, from: 2.52, worldAngle: true, angleOffset: -12 },
          { type: 'dumbbell', hand: 'right', scale: 1.0, from: 2.56, worldAngle: true, angleOffset: -12 }
        ]
      })],
      lights: [
        { type: 'spot', x: 5.4, y: FLOOR + 3.4, r: 3.4, color: 'rgba(255,240,215,1)', intensity: 0.95 },
        { type: 'flicker', x: 8.45, y: FLOOR + 1.6, r: 3.6, color: 'rgba(160,200,250,1)', intensity: 0.45, seed: 9 }
      ],
      camera: {
        x: 3.6, y: 2.35, zoom: 1.28,
        xTrack: { keys: [{ t: 0, v: 3.35 }, { t: 1.7, v: 4.3, ease: 'inOutCubic' }, { t: 2.7, v: 5.35, ease: 'inOutQuad' }, { t: 4.3, v: 5.5 }] },
        yTrack: { keys: [{ t: 0, v: 2.5 }, { t: 1.0, v: 2.4 }, { t: 2.7, v: 2.28 }, { t: 4.3, v: 2.26 }] },
        zoomTrack: { keys: [{ t: 0, v: 1.3 }, { t: 2.0, v: 1.10, ease: 'inOutQuad' }, { t: 4.3, v: 1.30, ease: 'inOutCubic' }] },
        handheld: 0.35
      },
      effects: [
        { type: 'speedLines', start: 0.05, duration: 0.6, color: '#cfe3ff', alpha: 0.14, count: 20 },
        { type: 'impactRing', start: 2.5, duration: 0.55, color: '#ffffff', alpha: 0.32, x: 0.55, y: 0.5, scale: 0.13 },
        { type: 'textCallout', start: 0.9, duration: 1.2, text: 'ONE REP', x: 0.5, y: 0.18, size: 0.040, color: '#57d7ff', alpha: 0.9 }
      ],
      audio: [
        { type: 'roomtone', at: 0, dur: 4.3, gain: 0.4 },
        { type: 'whoosh', at: 0.02, dur: 0.6, gain: 0.5 },
        { type: 'footsteps', at: 1.05, dur: 0.95, gain: 0.45, bpm: 108 },
        { type: 'clink', at: 2.52, dur: 0.35, gain: 0.4 },
        { type: 'music', at: 2.45, dur: 1.85, gain: 0.75, bpm: 92 }
      ]
    });

    // ---- scene 5: DISCIPLINE STARTS WITH ONE CHOICE -------------------------
    var s5 = newScene({
      title: 'Scene 5 — The line',
      duration: 2.5,
      description: 'White title card: DISCIPLINE STARTS WITH ONE CHOICE, with him standing behind it, fists up.',
      narration: 'Discipline starts with one choice.',
      background: 'void',
      seed: 57,
      transitionIn: { type: 'fadeFromBlack', duration: 0.3 },
      transitionOut: { type: 'fade', duration: 0.5 },
      props: [],
      actors: [newActor({
        name: 'stickman', x: 5.0, y: 0.45, facing: 1, opacity: 0.40,
        clips: [
          { from: 'stand', to: 'celebrate_tuck', start: 0, end: 0.35, morphEase: 'outQuad' },
          { from: 'celebrate_tuck', to: 'celebrate_up', start: 0.35, end: 0.85, morphEase: 'outBack' },
          { action: 'celebrate_up', start: 0.85, end: 2.5, loop: false }
        ]
      })],
      texts: [newText({
        text: 'DISCIPLINE STARTS WITH **ONE CHOICE**', x: 0.5, y: 0.38, size: 155,
        anim: 'wordPop', animDuration: 1.4, glow: 0.4, underline: true,
        color: '#ffffff', emphasisColor: '#ff2f45',
        maxWidthPct: 0.78, maxHeightPct: 0.40, start: 0.25, end: 2.5, fadeOut: 0.35
      })],
      camera: { x: 5, y: 2.35, zoom: 1.18, zoomTrack: { keys: [{ t: 0, v: 1.18 }, { t: 2.5, v: 1.0, ease: 'outCubic' }] } },
      lights: [{ type: 'spot', x: 5, y: 3.2, r: 3.8, color: 'rgba(255,255,255,1)', intensity: 0.5 }],
      effects: [
        { type: 'flash', start: 0.22, duration: 0.3, color: '#ffffff', alpha: 0.32 },
        { type: 'spotlight', start: 0, end: 2.5, persistent: true, alpha: 0.3, x: 0.5, y: 0.45, r: 0.75 }
      ],
      audio: [
        { type: 'boom', at: 0.22, dur: 1.2, gain: 0.7 },
        { type: 'music', at: 0, dur: 2.5, gain: 0.8, bpm: 92 }
      ]
    });

    return normalizeProject({
      schema: SCHEMA,
      id: 'demo-5am',
      title: 'ANOTHER DAY WASTED — 5:00 AM',
      fps: 30, width: 1920, height: 1080, aspect: '16:9',
      createdAt: '2026-01-01T00:00:00.000Z',
      sourceScript: [
        'Scene 1 (3s): A digital alarm clock reads 5:00 AM on the nightstand and rings while he sleeps.',
        'Scene 2 (2.6s): He reaches for his phone and returns to bed.',
        'Scene 3 (2.6s): Large red text reads ANOTHER DAY WASTED.',
        'Scene 4 (4.3s): The character gets up, puts the phone away and starts exercising.',
        'Scene 5 (2.5s): White text reads DISCIPLINE STARTS WITH ONE CHOICE.'
      ].join('\n'),
      look: { vignette: true, vignetteStrength: 0.5, grain: true },
      audio: { master: 0.9, music: 0.7, sfx: 0.85, narration: 1.0, muteMusic: false, muteSfx: false },
      music: { style: 'dark-pulse', bpm: 92, gain: 0.7 },
      scenes: [s1, s2, s3, s4, s5]
    });
  }

  // ===========================================================================
  // Script -> scenes
  // ===========================================================================
  var KEYWORDS = [
    { re: /\b(alarm|clock|5:00|5 am|ring|rings|ringing)\b/i, pose: 'wake_up', bg: 'bedroom', props: ['alarm_clock'] },
    { re: /\b(sleep|sleeping|asleep|bed|blanket|doze)\b/i, pose: 'lie_back', bg: 'bedroom', props: ['bed'] },
    { re: /\b(wake|wakes|woke|eyes open|stands up)\b/i, pose: 'wake_up', bg: 'bedroom', props: ['bed'] },
    { re: /\b(phone|scroll|scrolling|screen|social media|doom)\b/i, pose: 'hold_phone', props: ['phone'] },
    { re: /\b(exercise|exercising|workout|train|training|gym|dumbbell|curl|weights)\b/i, pose: 'dumbbell_curl', bg: 'mirror_room', props: ['dumbbells'] },
    { re: /\b(push-?up|pushups)\b/i, pose: 'pushup_up', props: [] },
    { re: /\b(squat|squats)\b/i, pose: 'squat', props: [] },
    { re: /\b(jumping jacks?|jacks)\b/i, pose: 'jumping_jack', props: [] },
    { re: /\b(run|runs|running|sprint|jog)\b/i, pose: 'run', bg: 'road_dawn' },
    { re: /\b(walk|walks|walking|steps)\b/i, pose: 'walk', props: [] },
    { re: /\b(stairs|staircase|climb|climbs|climbing)\b/i, pose: 'climb_stairs', bg: 'stairwell', props: ['stairs'] },
    { re: /\b(mirror)\b/i, pose: 'look_mirror', props: ['mirror'], bg: 'mirror_room' },
    { re: /\b(desk|work|works|working|laptop|job|study|studies|grind)\b/i, pose: 'desk_type', bg: 'office', props: ['desk', 'laptop', 'chair'] },
    { re: /\b(celebrate|celebrates|won|win|wins|success|succeed|victory|proud|done)\b/i, pose: 'celebrate_up', props: [] },
    { re: /\b(fail|fails|failed|failure|quit|quits|quit|gave up|give up|lost|lose|lazy|wasted)\b/i, pose: 'fail_slump', props: [] },
    { re: /\b(collapse|collapses|cries|crying|broken|defeat)\b/i, pose: 'lie_face_down', props: [] },
    { re: /\b(stretch|stretches|warm-?up)\b/i, pose: 'stretch', props: [] },
    { re: /\b(water|drink|hydrate|bottle)\b/i, pose: 'drink_water', props: ['water_bottle'] },
    { re: /\b(think|thinks|thinking|decide|decision|choice)\b/i, pose: 'think', props: [] },
    { re: /\b(window|sunrise|dawn|night sky|moon)\b/i, pose: 'stand', props: ['window'] },
    { re: /\b(sit|sits|sitting|chair)\b/i, pose: 'sit', props: ['chair'] }
  ];

  var IMPORTANT_WORD = /\b(never|always|again|another|wasted|discipline|choice|choice\.|now|today|start|starts|stop|stopping|pain|easy|hard|weak|strong|you|your|one|time|minutes|seconds|alarm|phone|bed|win|won|lose|lost|quit|give|up|rise|built|earned)\b/gi;

  // strip card intros ("Text card: …", "Title: …") before turning a line into a card
  function stripCardIntro(line) {
    return String(line || '')
      .replace(/^\s*(?:text card|title card|on[- ]screen text|onscreen text|card|quote|subtitle|caption|text|title)\s*[:\-–—]\s*/i, '')
      .replace(/^\s*["'“”]\s*/, '')
      .trim();
  }

  function titleCasePunch(line) {
    line = stripCardIntro(line);
    var s = line.trim().replace(/[.。]+$/, '');
    s = s.replace(/^(he|she|they|it|the stickman|stickman|character)\s+/i, '');
    s = s.toUpperCase();
    // keep it short enough to read in one or two beats on a phone
    var words = s.split(/\s+/);
    if (words.length > 6) words = words.slice(0, 6);
    return words.join(' ');
  }

  function emphasisePunch(punch) {
    var words = punch.split(/\s+/);
    var out = [];
    words.forEach(function (w, i) {
      var isKey = IMPORTANT_WORD.test(w);
      IMPORTANT_WORD.lastIndex = 0;
      if (isKey && i >= Math.max(0, words.length - 3) && words.length > 2) out.push('**' + w + '**');
      else out.push(w);
    });
    return out.join(' ').replace(/\*\*\*\*/g, '**');
  }

  function splitScriptBlocks(text) {
    var raw = String(text || '').replace(/\r\n/g, '\n').trim();
    if (!raw) return [];
    var lines = raw.split('\n');
    var blocks = [], cur = null;
    function stripDurationPrefix(str) {
      return str.replace(/^\s*[\(\[]?\s*\d+(?:\.\d+)?\s*(?:s\b|sec\b|secs\b|seconds\b)?\s*[\)\]]?\s*[:\-–—]?\s*/i, '');
    }
    lines.forEach(function (line) {
      var m = /^\s*(?:scene|shot|beat)\s*(\d+)\s*[:.\-–—)]?\s*(.*)$/i.exec(line);
      if (m) {
        if (cur) blocks.push(cur);
        var rest = (m[2] || '').trim();
        var bodyLine = stripDurationPrefix(rest);
        cur = { index: parseInt(m[1], 10), header: rest, body: bodyLine ? [bodyLine] : [] };
        return;
      }
      if (!line.trim()) {
        if (cur && cur.body.length) { blocks.push(cur); cur = null; }
        return;
      }
      if (!cur) cur = { index: blocks.length + 1, header: '', body: [] };
      cur.body.push(line.trim());
    });
    if (cur) blocks.push(cur);
    // no "Scene n" headers anywhere? split paragraphs on blank lines instead
    if (blocks.length <= 1 && raw.split(/\n\s*\n/).length > 1) {
      blocks = raw.split(/\n\s*\n/).map(function (chunk, i) {
        return { index: i + 1, header: '', body: chunk.split('\n').map(function (l) { return l.trim(); }).filter(Boolean) };
      });
    }
    return blocks.filter(function (b) { return b.body.length; });
  }

  function sentencesOf(text) {
    var parts = String(text || '').split(/(?<=[.!?;])\s+/).map(function (s) { return s.trim(); }).filter(function (s) { return s.length > 2; });
    if (!parts.length) parts = [String(text || '').trim()];
    // long sentences without punctuation: split on ", and" / ", then" / " and then"
    var out = [];
    parts.forEach(function (p) {
      if (p.length > 90) {
        p.split(/,?\s+(?:and then|then|and|after that|before)\s+/i).forEach(function (q) { if (q.trim().length > 2) out.push(q.trim()); });
      } else out.push(p);
    });
    return out.length ? out : parts;
  }

  function poseForSentence(sentence) {
    for (var i = 0; i < KEYWORDS.length; i++) {
      if (KEYWORDS[i].re.test(sentence)) {
        KEYWORDS[i].re.lastIndex = 0;
        return { pose: KEYWORDS[i].pose, keyword: KEYWORDS[i] };
      }
      KEYWORDS[i].re.lastIndex = 0;
    }
    return null;
  }

  function parseDuration(block, fallback) {
    var text = block.header + ' ' + block.body.join(' ');
    var m = /(\d+(?:\.\d+)?)\s*(?:s\b|sec\b|secs\b|seconds\b)/i.exec(text);
    if (m) return C.clamp(parseFloat(m[1]), 0.8, 30);
    var m2 = /(?:duration|length|dur)\s*[:=]\s*(\d+(?:\.\d+)?)/i.exec(text);
    if (m2) return C.clamp(parseFloat(m2[1]), 0.8, 30);
    return fallback;
  }

  function parseScript(text, options) {
    options = options || {};
    var blocks = splitScriptBlocks(text);
    var target = options.totalDuration || 0;
    var scenes = [];
    var prevBg = null;

    if (!blocks.length) return createDemoProject();

    blocks.forEach(function (block, bi) {
      var bodyText = block.body.join(' ').replace(/\s+/g, ' ').trim();
      var duration = parseDuration(block, 3);
      var bg = null, props = [];

      // scene-level keyword pass (background + props for the whole beat)
      KEYWORDS.forEach(function (k) {
        if (k.re.test(bodyText)) {
          if (k.bg && !bg) bg = k.bg;
          (k.props || []).forEach(function (p) { if (props.indexOf(p) < 0) props.push(p); });
        }
        k.re.lastIndex = 0;
      });

      // one pose per sentence, de-duplicated and capped so a beat stays readable
      var sentences = sentencesOf(bodyText);
      var poses = [];
      sentences.forEach(function (sentence) {
        var hit = poseForSentence(sentence);
        if (hit && poses[poses.length - 1] !== hit.pose) poses.push(hit.pose);
      });
      if (!poses.length) poses = ['stand'];
      if (poses.length > 4) poses = poses.slice(0, 4);

      var clips = [];
      if (poses.length === 1) {
        var loopSingle = !!R.ACTION_LOOP[poses[0]];
        clips.push({ action: poses[0], start: 0, end: duration, loop: loopSingle, period: R.ACTION_PERIOD[poses[0]] });
      } else {
        var per = duration / poses.length;
        poses.forEach(function (poseId, i) {
          var isLast = i === poses.length - 1;
          var loopable = !!R.ACTION_LOOP[poseId];
          if (i === 0) {
            clips.push({ action: poseId, start: 0, end: per, loop: loopable, period: R.ACTION_PERIOD[poseId] });
          } else {
            // morph from the previous pose: predictable, smooth, and easy to edit
            clips.push({
              from: poses[i - 1], to: poseId,
              start: +(i * per).toFixed(3), end: +((i + 1) * per).toFixed(3),
              morphEase: 'inOutCubic', target: isLast ? undefined : undefined
            });
          }
        });
      }

      // background continuity: keep the previous set unless this beat says otherwise
      var background = bg || prevBg || (bi === 0 ? 'bedroom' : 'void');

      // punch line from the last sentence of the beat
      // the card should show the punch line, not the sentence that introduces it
      var cardSource = sentences.slice().reverse().filter(function (x) { return stripCardIntro(x).length > 2; })[0] || sentences[sentences.length - 1];
      var punch = titleCasePunch(cardSource);
      var isFinal = bi === blocks.length - 1;
      var wantsCard = isFinal || /text|title|card|line|quote/i.test(block.header || '');
      var texts = [];
      if (wantsCard && punch) {
        texts.push(newText({
          text: emphasisePunch(punch),
          x: 0.5, y: isFinal ? 0.38 : 0.45, size: isFinal ? 155 : 190,
          anim: isFinal ? 'wordPop' : 'slam', animDuration: isFinal ? 1.3 : 0.5,
          glow: 0.4, color: '#ffffff', emphasisColor: '#ff2f45',
          maxWidthPct: isFinal ? 0.78 : 0.8, maxHeightPct: 0.42,
          start: 0.2, end: duration, underline: isFinal
        }));
      }

      var propList = props.map(function (propId, idx) {
        var meta = R.PROP_LIST.filter(function (q) { return q.id === propId; })[0] || { id: propId };
        var layer = (propId === 'mirror' || propId === 'window' || propId === 'chair' || propId === 'stairs' || propId === 'bed') ? 'back' : 'front';
        var px = 3.0 + idx * 1.7;
        if (propId === 'window') px = 2.0;
        var py = 1.35;
        if (propId === 'alarm_clock' || propId === 'phone') py = 1.97;      // on a nightstand
        if (propId === 'laptop') py = 2.07;                                  // on a desk top
        if (propId === 'mirror') py = 1.40;
        return newProp({ type: propId, x: px, y: py, layer: layer, w: meta.w, h: meta.h, count: meta.count });
      });

      var scene = newScene({
        title: 'Scene ' + (block.index || bi + 1),
        duration: +duration.toFixed(3),
        description: bodyText,
        narration: (/^\s*Scene\s+\d+/i.test(block.header || '') ? '' : (block.header ? block.header + '. ' : '')) + bodyText,
        background: background,
        seed: 7 + bi * 13,
        props: propList,
        actors: [newActor({ name: 'stickman', x: 5, y: 1.35, clips: clips })],
        texts: texts,
        camera: {
          x: 5, y: 2.4, zoom: 1.05,
          zoomTrack: { keys: bi % 2 ? [{ t: 0, v: 1.16 }, { t: duration, v: 1.04, ease: 'inOutQuad' }] : [{ t: 0, v: 1.04 }, { t: duration, v: 1.14, ease: 'inOutQuad' }] },
          handheld: 0.4
        },
        lights: background === 'gym' ? [{ type: 'spot', x: 5, y: 4.4, r: 3.4, color: 'rgba(255,240,215,1)', intensity: 1 }]
          : (background === 'bedroom' ? [{ type: 'godray', x: 2.5, y: 2.6, len: 7, w: 1.5, rays: 4, angle: -68, spread: 9, color: 'rgba(150,190,245,1)', intensity: 0.75 }] : []),
        effects: background === 'void' ? [{ type: 'redPulse', start: 0, end: duration, persistent: true, alpha: 0.14, hz: 0.8, color: '#ff2f45' }] : [],
        audio: [{ type: 'roomtone', at: 0, dur: duration, gain: 0.35 }],
        transitionIn: bi === 0 ? { type: 'fadeFromBlack', duration: 0.4 } : { type: 'cut', duration: 0 },
        transitionOut: isFinal ? { type: 'fade', duration: 0.5 } : { type: 'cut', duration: 0 }
      });
      scenes.push(scene);
      prevBg = background;
    });

    var project = normalizeProject({
      schema: SCHEMA, id: 'script-' + Date.now().toString(36), title: (options.title || 'Untitled stickman story'),
      fps: 30, width: 1920, height: 1080, aspect: '16:9',
      sourceScript: text, look: { vignette: true, vignetteStrength: 0.5, grain: true },
      audio: { master: 0.9, music: 0.7, sfx: 0.85, narration: 1.0 },
      music: { style: 'dark-pulse', bpm: 92, gain: 0.7 },
      scenes: scenes
    });

    if (target > 0 && scenes.length) {
      var total = R.totalDuration(project);
      var k = target / total;
      project.scenes.forEach(function (s) { s.duration = +(s.duration * k).toFixed(3); });
      project.scenes.forEach(function (s) { fitSceneTimings(s); });
    }
    return project;
  }

  // re-fit clips / texts / tracks after a duration change so nothing dangles
  function fitSceneTimings(scene) {
    var d = scene.duration;
    (scene.actors || []).forEach(function (a) {
      a.clips = (a.clips || []).map(function (c, i, arr) {
        return Object.assign({}, c, { start: C.clamp(c.start, 0, d), end: C.clamp(c.end, 0.01, d) });
      }).filter(function (c) { return c.end > c.start; });
      if (!a.clips.length) a.clips = [{ action: 'stand', start: 0, end: d, loop: false }];
    });
    (scene.texts || []).forEach(function (t) { t.end = (t.end === null || t.end > d) ? d : t.end; });
    ['xTrack', 'yTrack', 'zoomTrack', 'rotTrack'].forEach(function (key) {
      var tr = scene.camera && scene.camera[key];
      if (tr && tr.keys) tr.keys = tr.keys.map(function (k) { return Object.assign({}, k, { t: C.clamp(k.t, 0, d) }); });
    });
    (scene.shakes || []).forEach(function (s) { s.end = Math.min(s.end === undefined ? d : s.end, d); });
    (scene.effects || []).forEach(function (f) { f.end = Math.min(f.end === undefined ? (f.start || 0) + (f.duration || 1) : f.end, d); });
  }

  // ===========================================================================
  // Normalisation / defaults / validation
  // ===========================================================================
  function normalizeProject(input) {
    var p = Object.assign({}, input || {});
    p.schema = p.schema || SCHEMA;
    p.id = p.id || 'project';
    p.title = p.title || 'Untitled stickman story';
    p.fps = p.fps || 30;
    p.width = p.width || 1920;
    p.height = p.height || 1080;
    p.aspect = '16:9';
    p.look = Object.assign({ vignette: true, vignetteStrength: 0.5, grain: true }, p.look || {});
    p.audio = Object.assign({ master: 0.9, music: 0.7, sfx: 0.85, narration: 1.0 }, p.audio || {});
    p.music = Object.assign({ style: 'dark-pulse', bpm: 92, gain: 0.7 }, p.music || {});
    p.scenes = (p.scenes || []).map(function (sc, i) { return normalizeScene(sc, i); });
    if (!p.scenes.length) p.scenes = [normalizeScene({}, 0)];
    return p;
  }

  function normalizeScene(sc, i) {
    var s = Object.assign({}, sc || {});
    s.id = s.id || 'scene_' + (i + 1);
    s.title = s.title || ('Scene ' + (i + 1));
    s.duration = C.clamp(s.duration || 3, 0.5, 120);
    s.background = s.background || 'void';
    s.seed = s.seed === undefined ? 7 + i * 13 : s.seed;
    s.camera = Object.assign({ x: 5, y: 2.5, zoom: 1 }, s.camera || {});
    s.props = (s.props || []).map(function (pr, k) {
      var meta = R.PROP_LIST.filter(function (q) { return q.id === pr.type; })[0] || {};
      return Object.assign({
        id: pr.id || ('prop_' + i + '_' + k), type: pr.type || 'bed', x: 3, y: 1.35,
        scale: 1, layer: 'back', opacity: 1, rot: 0, w: meta.w, h: meta.h, count: meta.count
      }, pr);
    });
    s.actors = (s.actors || []).map(function (a, k) {
      var act = Object.assign({
        id: a.id || ('actor_' + i + '_' + k), name: a.name || 'stickman',
        x: 5, y: 1.35, facing: 1, size: 1, opacity: 1, attach: []
      }, a);
      act.clips = (act.clips || []).filter(Boolean).map(function (c) {
        var base = { start: 0, end: 3, loop: false };
        // morph clips describe themselves with from/to instead of action
        if (!c.morph && !c.from && !c.to) base.action = 'stand';
        return Object.assign(base, c);
      }).sort(function (x, y) { return x.start - y.start; });
      if (!act.clips.length) act.clips = [{ action: 'stand', start: 0, end: s.duration, loop: false }];
      // keep clips inside the scene
      act.clips = act.clips.map(function (c) {
        return Object.assign({}, c, { start: C.clamp(c.start, 0, s.duration), end: C.clamp(c.end, 0.01, Math.max(s.duration, c.end)) });
      });
      act.attach = (act.attach || []).map(function (at) { return Object.assign({ type: 'phone', hand: 'right', scale: 1 }, at); });
      return act;
    });
    s.texts = (s.texts || []).map(function (t, k) { return newText(Object.assign({ id: 'text_' + i + '_' + k }, t)); });
    s.lights = s.lights || [];
    s.effects = s.effects || [];
    s.shakes = s.shakes || [];
    s.audio = s.audio || [];
    s.transitionIn = s.transitionIn || { type: 'cut', duration: 0 };
    s.transitionOut = s.transitionOut || { type: 'cut', duration: 0 };
    s.description = s.description || '';
    s.narration = s.narration || '';
    return s;
  }

  function projectSummary(p) {
    var scenes = (p.scenes || []).map(function (s, i) {
      return { index: i, id: s.id, title: s.title, duration: s.duration, start: R.sceneAt(p, 0) ? sceneStart(p, i) : 0, background: s.background };
    });
    return { id: p.id, title: p.title, fps: p.fps, width: p.width, height: p.height, totalDuration: R.totalDuration(p), scenes: scenes };
  }
  function sceneStart(p, i) {
    var acc = 0;
    for (var k = 0; k < i; k++) acc += (p.scenes[k].duration || 3);
    return acc;
  }

  return {
    SCHEMA: SCHEMA, uid: uid,
    createDemoProject: createDemoProject,
    createEmptyProject: function () {
      return normalizeProject({
        schema: SCHEMA, id: 'untitled', title: 'Untitled stickman story', fps: 30, width: 1920, height: 1080,
        look: { vignette: true, vignetteStrength: 0.5, grain: true },
        audio: { master: 0.9, music: 0.7, sfx: 0.85, narration: 1.0 },
        music: { style: 'dark-pulse', bpm: 92, gain: 0.7 },
        scenes: [newScene({ title: 'Scene 1', duration: 3, description: 'Describe the first beat here.', background: 'bedroom' })]
      });
    },
    parseScript: parseScript, splitScriptBlocks: splitScriptBlocks,
    normalizeProject: normalizeProject, normalizeScene: normalizeScene,
    newScene: newScene, newActor: newActor, newProp: newProp, newText: newText,
    fitSceneTimings: fitSceneTimings, projectSummary: projectSummary, sceneStart: sceneStart,
    titleCasePunch: titleCasePunch, emphasisePunch: emphasisePunch, stripCardIntro: stripCardIntro
  };
});
