/* Stickman Animation Studio — shared deterministic renderer.
 * Pure function of (project JSON, scene index, scene time) -> drawing commands.
 * Runs identically in the browser (Canvas2D) and in Node (node-canvas), which is
 * what makes the exported MP4 match the preview frame for frame.
 *
 * Coordinate conventions
 *   world space (backgrounds, lights, camera): y is UP, y=0 is the floor line
 *   screen space (actors, props, text): y is DOWN, drawn after tr.toScreen()
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./core.js'));
  } else {
    root.SAS = root.SAS || {};
    root.SAS.Render = factory(root.SAS.Core);
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (C) {
  'use strict';

  var THEME = {
    bg: '#05070b',
    ink: '#f4f7fb',
    inkDim: '#93a4b8',
    red: '#ff2f45',
    amber: '#ffb020',
    cyan: '#57d7ff',
    prop: '#c9d6e4',
    propDim: '#546274',
    floor: '#0b1017',
    wall: '#080b11',
    fontFamily: '"DejaVu Sans", "Inter", "Segoe UI", system-ui, -apple-system, sans-serif'
  };

  var STAGE_W = 10, STAGE_H = 5.625;          // default camera view, in world units
  var SEG = { torso: 0.62, neck: 0.07, head: 0.175, upperArm: 0.29, foreArm: 0.29, thigh: 0.42, shin: 0.42, foot: 0.17 };
  var LEG_LEN = SEG.thigh + SEG.shin;         // 0.84
  var D2R = Math.PI / 180;

  // ===========================================================================
  // Poses. Angles: absolute, degrees, CCW from +x, in a y-UP skeleton frame.
  // 90 = up, -90 = down, 0 = forward. hip = pelvis offset from the ground anchor.
  // ===========================================================================
  function P(o) {
    return {
      hip: o.hip || { x: 0, y: LEG_LEN },
      torso: o.torso === undefined ? 90 : o.torso,
      head: o.head === undefined ? 0 : o.head,
      headTilt: o.headTilt || 0,
      eyes: o.eyes || 'open',
      armL: o.armL || { s: -96, e: -88 },
      armR: o.armR || { s: -84, e: -92 },
      legL: o.legL || { h: -94, k: -89, f: 4 },
      legR: o.legR || { h: -86, k: -91, f: -4 },
      scale: o.scale === undefined ? 1 : o.scale
    };
  }

  function blendPose(a, b, u) {
    u = C.clamp(u, 0, 1);
    if (u <= 0) return a;
    if (u >= 1) return b;
    function ang(x, y) { var d = ((y - x) % 360 + 540) % 360 - 180; return x + d * u; }
    function pair(x, y, ka, kb) { var o = {}; o[ka] = ang(x[ka], y[ka]); o[kb] = ang(x[kb], y[kb]); return o; }
    return {
      hip: { x: C.lerp(a.hip.x, b.hip.x, u), y: C.lerp(a.hip.y, b.hip.y, u) },
      torso: ang(a.torso, b.torso), head: ang(a.head, b.head), headTilt: ang(a.headTilt, b.headTilt),
      eyes: u < 0.5 ? a.eyes : b.eyes,
      armL: pair(a.armL, b.armL, 's', 'e'), armR: pair(a.armR, b.armR, 's', 'e'),
      legL: pair(a.legL, b.legL, 'h', 'k'), legR: pair(a.legR, b.legR, 'h', 'k'),
      scale: C.lerp(a.scale, b.scale, u)
    };
  }
  // foot angle is handled separately (legs carry h/k; f is cosmetic)
  (function patchFootBlend() {
    var orig = blendPose;
    blendPose = function (a, b, u) {
      var r = orig(a, b, u);
      if (u > 0 && u < 1) {
        r.legL.f = C.lerp(a.legL.f, b.legL.f, u);
        r.legR.f = C.lerp(a.legR.f, b.legR.f, u);
      }
      return r;
    };
  })();

  var BASE_POSES = {
    stand: function () { return P({}); },

    idle: function (p) {
      var b = Math.sin((p.t || 0) * 1.6 * Math.PI * 2 / 3.2);
      var o = P({
        torso: 90 + b * 0.7, head: -1 + b * 0.6,
        armL: { s: -95 + b * 2.4, e: -88 - b * 2 }, armR: { s: -85 - b * 2.4, e: -92 + b * 2 }
      });
      o.hip.y += b * 0.012;
      return o;
    },

    breathe: function (p) {
      var b = Math.sin((p.t || 0) * 0.9 * Math.PI);
      return P({
        torso: 90 + b * 1.2, head: b * 0.8,
        armL: { s: -96 + b * 1.6, e: -88 }, armR: { s: -84 - b * 1.6, e: -92 },
        hip: { x: 0, y: LEG_LEN + b * 0.008 }
      });
    },

    sleep_breath: function (p) {
      var b = Math.sin((p.t || 0) * 0.55 * Math.PI);
      return P({
        torso: 86 + b, head: 7, headTilt: 5 + b,
        armL: { s: -94 + b, e: -86 }, armR: { s: -86 - b, e: -94 }, eyes: 'closed'
      });
    },

    sit: function () {
      return P({
        hip: { x: -0.12, y: 0.56 }, torso: 84, head: 4,
        legL: { h: -6, k: -86, f: 6 }, legR: { h: -10, k: -92, f: -6 },
        armL: { s: -70, e: -78 }, armR: { s: -70, e: -78 }
      });
    },
    sit_slump: function () {
      return P({
        hip: { x: -0.14, y: 0.54 }, torso: 70, head: 28,
        legL: { h: -4, k: -80, f: 6 }, legR: { h: -8, k: -86, f: -6 },
        armL: { s: -78, e: -95 }, armR: { s: -78, e: -95 }, eyes: 'half'
      });
    },
    sit_hands_on_knees: function () {
      return P({
        hip: { x: -0.12, y: 0.56 }, torso: 78, head: 8,
        legL: { h: -6, k: -86, f: 6 }, legR: { h: -10, k: -92, f: -6 },
        armL: { s: -34, e: -84 }, armR: { s: -20, e: -96 }
      });
    },

    lie_back: function () {
      return P({
        hip: { x: 0.0, y: 0.32 }, torso: 174, head: -8, headTilt: -14,
        legL: { h: 4, k: 2, f: 18 }, legR: { h: 6, k: 3, f: 22 },
        armL: { s: 150, e: 172 }, armR: { s: 160, e: 176 }, eyes: 'closed'
      });
    },
    lie_side: function () {
      return P({
        hip: { x: 0, y: 0.34 }, torso: 172, head: -10,
        legL: { h: 6, k: 4, f: 20 }, legR: { h: 3, k: 1, f: 16 },
        armL: { s: 150, e: 176 }, armR: { s: 144, e: 180 }, eyes: 'closed'
      });
    },
    lie_face_down: function () {
      return P({
        hip: { x: 0, y: 0.30 }, torso: 186, head: 14, headTilt: 10,
        legL: { h: 4, k: 2, f: 16 }, legR: { h: 2, k: 0, f: 12 },
        armL: { s: 18, e: 30 }, armR: { s: 8, e: 22 }, eyes: 'closed'
      });
    },
    wake_up: function () {
      return P({
        hip: { x: -0.05, y: 0.40 }, torso: 126, head: 16, headTilt: -6,
        legL: { h: 8, k: -30, f: 10 }, legR: { h: 4, k: -26, f: 6 },
        armL: { s: 168, e: 118 }, armR: { s: 172, e: 126 }, eyes: 'half'
      });
    },

    // sleeping in bed: lying on the back with a slow breathing rise
    lie_back_sleep: function (p) {
      var b = Math.sin((p.t || 0) * 0.42 * Math.PI);
      return P({
        hip: { x: 0.0, y: 0.32 + b * 0.008 }, torso: 174 + b, head: -8, headTilt: -14,
        legL: { h: 4, k: 2, f: 18 }, legR: { h: 6, k: 3, f: 22 },
        armL: { s: 150, e: 172 }, armR: { s: 160, e: 176 }, eyes: 'closed'
      });
    },
    // back under the blanket with the phone clutched to the chest
    lie_back_phone: function (p) {
      var b = Math.sin((p.t || 0) * 0.5 * Math.PI);
      return P({
        hip: { x: 0.05, y: 0.32 + b * 0.006 }, torso: 176, head: -6, headTilt: -10,
        legL: { h: 6, k: 4, f: 18 }, legR: { h: 9, k: 6, f: 22 },
        armL: { s: 26, e: 60 }, armR: { s: 18, e: 54 }, eyes: 'half'
      });
    },
    // sitting up in bed, right arm reaching out to the side for something
    sit_reach: function (p) {
      var u = C.ease('inOutCubic', p.local === undefined ? 0.5 : C.clamp((p.local || 0) / 0.7, 0, 1));
      return P({
        hip: { x: -0.04, y: 0.44 }, torso: 118 - 10 * u, head: 8 + 6 * u, headTilt: -3,
        armR: { s: C.lerp(-24, -8, u), e: C.lerp(-30, 6, u) },
        armL: { s: 34, e: 62 },
        legL: { h: 8, k: -34, f: 10 }, legR: { h: 3, k: -28, f: 6 }
      });
    },
    // sitting up in bed looking at the phone
    hold_phone_sit: function (p) {
      var j = Math.sin((p.t || 0) * 1.3 * Math.PI) * 1.8;
      return P({
        hip: { x: -0.04, y: 0.44 }, torso: 116, head: 18, headTilt: 2, eyes: 'down',
        armL: { s: -20, e: 22 + j }, armR: { s: -14, e: 18 - j },
        legL: { h: 8, k: -34, f: 10 }, legR: { h: 3, k: -28, f: 6 }
      });
    },

    hold_phone: function (p) {
      var j = Math.sin((p.t || 0) * 1.3 * Math.PI) * 1.6;
      return P({
        torso: 84, head: 16, headTilt: 2, eyes: 'down',
        armL: { s: -34, e: 6 + j }, armR: { s: -30, e: 2 - j }
      });
    },
    scroll_phone: function (p) {
      var b = BASE_POSES.hold_phone(p);
      b.armR = { s: -30, e: 0 - Math.sin((p.t || 0) * 5.4 * Math.PI) * 6 };
      b.head = 18;
      return b;
    },
    reach_down: function (p) {
      var u = C.ease('inOutCubic', C.clamp((p.local || 0) / 0.9, 0, 1));
      return P({
        torso: 90 - 34 * u, head: 30 * u, headTilt: 6,
        armL: { s: C.lerp(-96, -6, u), e: C.lerp(-88, -14, u) },
        armR: { s: C.lerp(-84, -14, u), e: C.lerp(-92, -20, u) },
        legL: { h: -92, k: -86, f: 4 }, legR: { h: -84, k: -88, f: -4 }
      });
    },
    phone_to_chest: function () { return P({ torso: 82, head: 12, armL: { s: -58, e: 24 }, armR: { s: -52, e: 30 } }); },
    pocket_phone: function (p) {
      var u = C.ease('outCubic', C.clamp((p.local || 0) / 0.7, 0, 1));
      return P({
        torso: 84, head: 8 * (1 - u),
        armL: { s: -96, e: -88 },
        armR: { s: C.lerp(-40, -84, u), e: C.lerp(-60, -92, u) }
      });
    },

    desk_type: function (p) {
      var k = Math.sin((p.t || 0) * 8.5 * Math.PI) * 3;
      var k2 = Math.sin((p.t || 0) * 7.1 * Math.PI + 1) * 3;
      return P({
        hip: { x: -0.16, y: 0.58 }, torso: 76, head: 14, headTilt: 2, eyes: 'down',
        legL: { h: -8, k: -84, f: 6 }, legR: { h: -12, k: -90, f: -6 },
        armL: { s: -18, e: -22 + k }, armR: { s: -14, e: -26 + k2 }
      });
    },
    work_focus: function (p) {
      var b = Math.sin((p.t || 0) * 1.1 * Math.PI);
      return P({
        hip: { x: -0.16, y: 0.58 }, torso: 74 + b, head: 18, headTilt: 1, eyes: 'down',
        legL: { h: -8, k: -84, f: 6 }, legR: { h: -12, k: -90, f: -6 },
        armL: { s: -16, e: -20 }, armR: { s: -12, e: -24 }
      });
    },

    pushup_down: function () {
      return P({
        hip: { x: 0, y: 0.42 }, torso: 24, head: 40,
        legL: { h: -18, k: -8, f: 12 }, legR: { h: -22, k: -12, f: 8 },
        armL: { s: -78, e: -92 }, armR: { s: -74, e: -96 }
      });
    },
    pushup_up: function () {
      return P({
        hip: { x: 0, y: 0.60 }, torso: 22, head: 44,
        legL: { h: -18, k: -8, f: 12 }, legR: { h: -22, k: -12, f: 8 },
        armL: { s: -84, e: -88 }, armR: { s: -80, e: -92 }
      });
    },
    plank: function () {
      return P({
        hip: { x: 0, y: 0.56 }, torso: 20, head: 44,
        legL: { h: -18, k: -8, f: 12 }, legR: { h: -22, k: -12, f: 8 },
        armL: { s: -84, e: -90 }, armR: { s: -80, e: -86 }
      });
    },
    squat_down: function () {
      return P({
        hip: { x: -0.10, y: 0.46 }, torso: 74, head: 12,
        legL: { h: -24, k: -62, f: 10 }, legR: { h: -30, k: -70, f: -6 },
        armL: { s: -30, e: -84 }, armR: { s: -24, e: -92 }
      });
    },
    squat_up: function () { return P({ torso: 88, head: 2, armL: { s: -30, e: -84 }, armR: { s: -24, e: -92 } }); },

    celebrate_up: function () {
      return P({
        torso: 92, head: -8, armL: { s: 62, e: 74 }, armR: { s: 56, e: 66 },
        legL: { h: -98, k: -92, f: 8 }, legR: { h: -82, k: -88, f: -8 }
      });
    },
    celebrate_tuck: function () {
      return P({
        hip: { x: 0, y: LEG_LEN * 0.80 }, torso: 88, head: -4,
        armL: { s: 58, e: 72 }, armR: { s: 52, e: 64 },
        legL: { h: -62, k: -112, f: 10 }, legR: { h: -58, k: -118, f: -10 }
      });
    },
    arms_up: function () { return P({ torso: 91, head: -6, armL: { s: 64, e: 78 }, armR: { s: 58, e: 70 } }); },
    stretch: function (p) {
      var b = Math.sin((p.t || 0) * 0.9 * Math.PI);
      return P({ torso: 90 + b * 4, head: -10, armL: { s: 70, e: 86 }, armR: { s: 64, e: 80 } });
    },

    fail_slump: function () {
      return P({
        hip: { x: -0.06, y: LEG_LEN * 0.86 }, torso: 64, head: 34, headTilt: 4, eyes: 'half',
        armL: { s: -78, e: -100 }, armR: { s: -74, e: -104 },
        legL: { h: -86, k: -74, f: 6 }, legR: { h: -94, k: -82, f: -6 }
      });
    },
    fail_kneel: function () {
      return P({
        hip: { x: -0.04, y: 0.62 }, torso: 60, head: 40,
        legL: { h: -70, k: -30, f: 8 }, legR: { h: -74, k: -34, f: -8 },
        armL: { s: -84, e: -100 }, armR: { s: -80, e: -104 }
      });
    },
    fail_cover_face: function () {
      return P({
        hip: { x: -0.04, y: LEG_LEN * 0.90 }, torso: 78, head: 24,
        armL: { s: 26, e: 62 }, armR: { s: 20, e: 56 }, eyes: 'closed'
      });
    },

    hands_on_hips: function () { return P({ torso: 90, head: -2, armL: { s: -28, e: -128 }, armR: { s: -22, e: -136 } }); },
    arms_crossed: function () { return P({ torso: 90, head: -3, armL: { s: -34, e: -6 }, armR: { s: -26, e: -14 } }); },
    point_forward: function () { return P({ torso: 88, head: -4, armL: { s: -96, e: -88 }, armR: { s: -4, e: -2 } }); },
    point_up: function () { return P({ torso: 90, head: -8, armL: { s: -96, e: -88 }, armR: { s: 78, e: 82 } }); },
    think: function () { return P({ torso: 88, head: 6, headTilt: 7, armL: { s: -96, e: -88 }, armR: { s: 18, e: 70 } }); },
    look_mirror: function () { return P({ torso: 90, head: 0, armL: { s: -94, e: -88 }, armR: { s: -86, e: -92 } }); },
    drink_water: function () { return P({ torso: 88, head: -14, armL: { s: 20, e: 66 }, armR: { s: -96, e: -88 } }); }
  };

  // ---- procedural poses -----------------------------------------------------
  function walkPose(p, opt) {
    opt = opt || {};
    var stride = opt.stride === undefined ? 26 : opt.stride;
    var lift = opt.lift === undefined ? 34 : opt.lift;
    var bob = opt.bob === undefined ? 0.035 : opt.bob;
    var ph = (p.phase || 0) * Math.PI * 2;
    function leg(off) {
      var q = ph + off;
      return { h: -90 + Math.sin(q) * stride, k: -90 + Math.max(0, -Math.sin(q - 0.5)) * lift, f: 8 + Math.sin(q + 1.2) * 14 };
    }
    function arm(sign, off) {
      var q = ph + off;
      return { s: -90 + Math.sin(q) * stride * 0.9 + sign * 4, e: -90 + Math.sin(q - 0.4) * 12 - 6 };
    }
    return P({
      hip: { x: 0, y: LEG_LEN - Math.abs(Math.cos(ph)) * bob },
      torso: 90 + (opt.lean || 0), head: -(opt.lean || 0) * 0.4, headTilt: Math.sin(ph) * 1.5,
      legL: leg(Math.PI), legR: leg(0), armL: arm(1, 0), armR: arm(-1, Math.PI)
    });
  }
  function walkBackPose(p, opt) {
    var w = walkPose(p, opt);
    w.torso = 88; w.head = 10; w.headTilt = 0;
    return w;
  }
  function runPose(p) {
    var ph = (p.phase || 0) * Math.PI * 2;
    var sw = Math.sin(ph);
    function leg(off) {
      var q = ph + off;
      return { h: -90 + Math.sin(q) * 42, k: -90 + Math.max(0, -Math.sin(q - 0.35)) * 78, f: 10 + Math.sin(q + 1.4) * 16 };
    }
    return P({
      hip: { x: 0, y: LEG_LEN - Math.abs(Math.cos(ph)) * 0.075 + 0.02 },
      torso: 76, head: -14, headTilt: sw * 2,
      legL: leg(Math.PI), legR: leg(0),
      armL: { s: -90 + sw * 52, e: -148 + sw * 20 }, armR: { s: -90 - sw * 52, e: -148 - sw * 20 }
    });
  }
  function climbPose(p) {
    var ph = (p.phase || 0) * Math.PI * 2;
    var s = Math.sin(ph);
    return P({
      torso: 82, head: -8,
      legL: { h: -90 + Math.max(0, s) * 30, k: -90 + Math.max(0, 0.6 - s) * 66, f: 10 },
      legR: { h: -90 + Math.max(0, -s) * 30, k: -90 + Math.max(0, 0.6 + s) * 66, f: 8 },
      armL: { s: -88 - s * 30, e: -84 - Math.max(0, -s) * 40 },
      armR: { s: -88 + s * 30, e: -84 - Math.max(0, s) * 40 }
    });
  }
  function curlPose(p) {
    var u = C.ease('inOutQuad', 0.5 - 0.5 * Math.cos((p.phase || 0) * Math.PI * 2));
    return P({
      torso: 90, head: 2,
      armL: { s: -88 + u * 6, e: C.lerp(-92, -14, u) },
      armR: { s: -84 + u * 6, e: C.lerp(-92, -14, u) }
    });
  }
  function jackPose(p) {
    var u = C.ease('inOutQuad', (Math.sin((p.phase || 0) * Math.PI * 2) + 1) / 2);
    return P({
      hip: { x: 0, y: LEG_LEN - u * 0.03 }, torso: 90, head: -3,
      armL: { s: C.lerp(-96, 62, u), e: C.lerp(-88, 78, u) },
      armR: { s: C.lerp(-84, 56, u), e: C.lerp(-92, 72, u) },
      legL: { h: C.lerp(-96, -106, u), k: -90, f: 6 },
      legR: { h: C.lerp(-84, -74, u), k: -90, f: -6 }
    });
  }
  function squatProcPose(p) {
    var d = C.ease('inOutQuad', 0.5 - 0.5 * Math.cos((p.phase || 0) * Math.PI * 2));
    return P({
      hip: { x: -0.10 * d, y: C.lerp(LEG_LEN, LEG_LEN - 0.42, d) }, torso: 90 - 16 * d, head: 4 + 12 * d,
      legL: { h: -92 - 4 * d, k: -90 - 46 * d, f: 8 + 12 * d },
      legR: { h: -88 + 4 * d, k: -90 - 50 * d, f: -8 + 10 * d },
      armL: { s: -90 - 6 * d, e: -88 - 10 * d },
      armR: { s: -86 + 40 * d, e: -50 + 40 * d }
    });
  }
  function situpPose(p) {
    var d = C.ease('inOutQuad', 0.5 - 0.5 * Math.cos((p.phase || 0) * Math.PI * 2));
    var lies = P({ hip: { x: 0, y: 0.32 }, torso: 174, head: -8, legL: { h: 4, k: 2, f: 18 }, legR: { h: 6, k: 3, f: 22 }, armL: { s: 150, e: 172 }, armR: { s: 160, e: 176 }, eyes: 'open' });
    var sits = P({ hip: { x: 0.02, y: 0.34 }, torso: 112, head: -14, legL: { h: -6, k: -84, f: 16 }, legR: { h: -2, k: -88, f: 12 }, armL: { s: -6, e: -34 }, armR: { s: -14, e: -40 } });
    return blendPose(lies, sits, d);
  }
  function tremblePose(p) {
    var n = C.noise1(1234, (p.t || 0) * 14) - 0.5;
    var q = P({ torso: 88 + n * 3, head: 8 + n * 3, armL: { s: -92 + n * 4, e: -90 }, armR: { s: -88 - n * 4, e: -92 } });
    q.hip.y += n * 0.01;
    return q;
  }
  function shakeHeadPose(p) {
    var n = Math.sin((p.t || 0) * 3.4 * Math.PI);
    return P({ torso: 90, head: n * 9, headTilt: n * 6 });
  }
  function nodPose(p) {
    var n = Math.sin((p.t || 0) * 2.2 * Math.PI);
    return P({ torso: 90 + n * 2, head: 6 + n * 11 });
  }
  function lookAroundPose(p) {
    var n = Math.sin((p.t || 0) * 1.5 * Math.PI);
    return P({ torso: 90, head: n * 12, headTilt: n * 4 });
  }
  function phoneAlarmPose(p) {
    var n = C.noise1(555, (p.t || 0) * 6) - 0.5;
    return P({
      torso: 86 + n * 1.5, head: 14, headTilt: 3, eyes: 'half',
      armL: { s: -34, e: 6 }, armR: { s: -30, e: 2 }
    });
  }

  var POSE_FNS = Object.assign({}, BASE_POSES, {
    walk: walkPose, walk_back: walkBackPose, run: runPose, climb_stairs: climbPose,
    dumbbell_curl: curlPose, jumping_jack: jackPose, squat: squatProcPose, situp: situpPose,
    tremble: tremblePose, shake_head: shakeHeadPose, nod: nodPose,
    look_around: lookAroundPose, phone_alarm: phoneAlarmPose
  });

  function resolvePose(name, p) { return (POSE_FNS[name] || BASE_POSES.stand)(p || {}); }
  function isPose(name) { return !!POSE_FNS[name]; }

  var ACTIONS = [
    { id: 'stand', label: 'Stand', group: 'Basic', loop: false },
    { id: 'idle', label: 'Idle breathing', group: 'Basic', loop: true, period: 3.2 },
    { id: 'breathe', label: 'Breathe', group: 'Basic', loop: true, period: 2.2 },
    { id: 'look_around', label: 'Look around', group: 'Basic', loop: true, period: 2.0 },
    { id: 'sleep_breath', label: 'Sleep (dozing upright)', group: 'Rest', loop: true, period: 3.6 },
    { id: 'lie_back', label: 'Sleep (on back)', group: 'Rest', loop: false },
    { id: 'lie_back_sleep', label: 'Sleep in bed (breathing)', group: 'Rest', loop: true, period: 4.2 },
    { id: 'lie_back_phone', label: 'In bed with phone', group: 'Rest', loop: true, period: 3.4 },
    { id: 'lie_side', label: 'Sleep (on side)', group: 'Rest', loop: false },
    { id: 'wake_up', label: 'Wake up', group: 'Rest', loop: false },
    { id: 'sit_reach', label: 'Sit up and reach', group: 'Rest', loop: false },
    { id: 'hold_phone_sit', label: 'Sit up with phone', group: 'Rest', loop: true, period: 1.5 },
    { id: 'sit', label: 'Sit (on edge)', group: 'Rest', loop: false },
    { id: 'sit_slump', label: 'Sit slumped', group: 'Rest', loop: false },
    { id: 'sit_hands_on_knees', label: 'Sit, hands on knees', group: 'Rest', loop: false },
    { id: 'walk', label: 'Walk', group: 'Locomotion', loop: true, period: 1.0, params: { stride: 26, lift: 34 } },
    { id: 'walk_back', label: 'Walk back / sheepish', group: 'Locomotion', loop: true, period: 1.2 },
    { id: 'run', label: 'Run', group: 'Locomotion', loop: true, period: 0.62 },
    { id: 'climb_stairs', label: 'Climb stairs', group: 'Locomotion', loop: true, period: 1.0 },
    { id: 'desk_type', label: 'Work — type at desk', group: 'Work', loop: true, period: 1.1 },
    { id: 'work_focus', label: 'Work — focused lean-in', group: 'Work', loop: true, period: 1.8 },
    { id: 'hold_phone', label: 'Hold phone', group: 'Work', loop: true, period: 1.5 },
    { id: 'scroll_phone', label: 'Scroll phone', group: 'Work', loop: true, period: 1.6 },
    { id: 'phone_alarm', label: 'Phone alarm ringing', group: 'Work', loop: true, period: 1.2 },
    { id: 'reach_down', label: 'Reach for something', group: 'Work', loop: false },
    { id: 'phone_to_chest', label: 'Clutch phone to chest', group: 'Work', loop: false },
    { id: 'pocket_phone', label: 'Put phone away', group: 'Work', loop: false },
    { id: 'fail_slump', label: 'Fail — slump', group: 'Fail', loop: false },
    { id: 'fail_kneel', label: 'Fail — drop to knees', group: 'Fail', loop: false },
    { id: 'fail_cover_face', label: 'Fail — cover face', group: 'Fail', loop: false },
    { id: 'lie_face_down', label: 'Collapse face down', group: 'Fail', loop: false },
    { id: 'tremble', label: 'Tremble / struggle', group: 'Fail', loop: true, period: 0.5 },
    { id: 'shake_head', label: 'Shake head', group: 'Gestures', loop: true, period: 1.0 },
    { id: 'nod', label: 'Nod', group: 'Gestures', loop: true, period: 1.2 },
    { id: 'celebrate_up', label: 'Celebrate — fists up', group: 'Celebrate', loop: false },
    { id: 'celebrate_tuck', label: 'Celebrate — jump tuck', group: 'Celebrate', loop: false },
    { id: 'arms_up', label: 'Arms up', group: 'Celebrate', loop: false },
    { id: 'stretch', label: 'Stretch', group: 'Celebrate', loop: true, period: 2.4 },
    { id: 'dumbbell_curl', label: 'Exercise — dumbbell curl', group: 'Exercise', loop: true, period: 1.8 },
    { id: 'jumping_jack', label: 'Exercise — jumping jacks', group: 'Exercise', loop: true, period: 1.4 },
    { id: 'squat', label: 'Exercise — squats', group: 'Exercise', loop: true, period: 2.0 },
    { id: 'situp', label: 'Exercise — sit-ups', group: 'Exercise', loop: true, period: 2.4 },
    { id: 'pushup_down', label: 'Exercise — push-up (low)', group: 'Exercise', loop: false },
    { id: 'pushup_up', label: 'Exercise — push-up (high)', group: 'Exercise', loop: false },
    { id: 'plank', label: 'Exercise — plank', group: 'Exercise', loop: false },
    { id: 'squat_down', label: 'Squat (low hold)', group: 'Exercise', loop: false },
    { id: 'squat_up', label: 'Stand tall', group: 'Exercise', loop: false },
    { id: 'hands_on_hips', label: 'Hands on hips', group: 'Gestures', loop: false },
    { id: 'arms_crossed', label: 'Arms crossed', group: 'Gestures', loop: false },
    { id: 'point_forward', label: 'Point forward', group: 'Gestures', loop: false },
    { id: 'point_up', label: 'Point up', group: 'Gestures', loop: false },
    { id: 'think', label: 'Think', group: 'Gestures', loop: false },
    { id: 'look_mirror', label: 'Look in mirror', group: 'Gestures', loop: false },
    { id: 'drink_water', label: 'Drink water', group: 'Gestures', loop: false }
  ];
  var ACTION_IDS = ACTIONS.map(function (a) { return a.id; });
  var ACTION_LOOP = ACTIONS.reduce(function (m, a) { m[a.id] = !!a.loop; return m; }, {});
  var ACTION_PERIOD = ACTIONS.reduce(function (m, a) { if (a.period) m[a.id] = a.period; return m; }, {});

  // ===========================================================================
  // Clip sampling
  // ===========================================================================
  function clipAt(actor, t) {
    var clips = (actor.clips || []).slice().sort(function (a, b) { return a.start - b.start; });
    if (!clips.length) {
      return { pose: resolvePose(actor.baseAction || 'stand', { t: t, local: t, phase: 0, u: 0, params: {} }), name: actor.baseAction || 'stand', phase: 0, clip: null, u: 0 };
    }
    var idx = -1;
    for (var i = 0; i < clips.length; i++) { if (t >= clips[i].start) idx = i; else break; }
    var active = clips[Math.max(0, Math.min(idx, clips.length - 1))];
    var span = Math.max(0.0001, active.end - active.start);
    var beforeFirst = t < clips[0].start;
    var held = idx >= clips.length; // t is past the last clip
    var local = C.clamp(t - active.start, 0, span);
    var u = held ? 1 : local / span;

    var period = (active.params && active.params.period) || ACTION_PERIOD[active.action] || 1;
    var speed = active.speed === undefined ? 1 : active.speed;
    var loop = active.loop === undefined ? ACTION_LOOP[active.action] : active.loop;
    var phase;
    if (active.morph) phase = C.clamp(u, 0, 1);
    else if (loop) phase = held ? ((span / period) * speed) % 1 : ((local / period) * speed) % 1;
    else phase = C.clamp(u, 0, 1);

    var ctxInput = { t: t, local: local, u: u, phase: phase, params: active.params || {}, seed: active.seed || 0 };
    var body;
    if (active.morph || active.from) {
      var fromName = active.from || active.morph[0];
      var toName = active.to || active.morph[1];
      var mx = C.ease(active.morphEase || 'inOutCubic', C.clamp(u, 0, 1));
      body = blendPose(resolvePose(fromName, ctxInput), resolvePose(toName, ctxInput), mx);
    } else {
      body = resolvePose(active.action, ctxInput);
    }
    // explicit blend from the previous clip's pose
    if (active.blendIn && idx > 0 && !active.from) {
      var prevClip = clips[idx - 1];
      var pu = C.ease(active.blendEase || 'inOutCubic', C.clamp(local / active.blendIn, 0, 1));
      body = blendPose(resolvePose(prevClip.action, ctxInput), body, pu);
    }
    if (beforeFirst) u = 0;
    return { pose: body, name: active.action || 'morph', phase: phase, clip: active, u: u, local: local };
  }

  function trackValue(track, t, fallback) {
    if (!track || !track.keys || !track.keys.length) return fallback;
    var keys = track.keys;
    if (t <= keys[0].t) return keys[0].v;
    if (t >= keys[keys.length - 1].t) return keys[keys.length - 1].v;
    for (var i = 0; i < keys.length - 1; i++) {
      var a = keys[i], b = keys[i + 1];
      if (t >= a.t && t <= b.t) {
        var u = (t - a.t) / Math.max(0.0001, b.t - a.t);
        return C.lerp(a.v, b.v, C.ease(b.ease || a.ease || 'inOutQuad', u));
      }
    }
    return fallback;
  }

  // ===========================================================================
  // Skeleton + actor drawing (screen space)
  // ===========================================================================
  function ap(o, len, deg) { var a = deg * D2R; return { x: o.x + Math.cos(a) * len, y: o.y + Math.sin(a) * len }; }

  function buildSkeleton(pose) {
    var hip = { x: pose.hip.x, y: pose.hip.y };
    var shoulder = ap(hip, SEG.torso, pose.torso);
    var neck = ap(hip, SEG.torso + SEG.neck, pose.torso);
    var headAng = pose.torso + pose.head;
    var head = ap(neck, SEG.head + 0.02, headAng);
    var ebL = ap(shoulder, SEG.upperArm, pose.armL.s), hL = ap(ebL, SEG.foreArm, pose.armL.e);
    var ebR = ap(shoulder, SEG.upperArm, pose.armR.s), hR = ap(ebR, SEG.foreArm, pose.armR.e);
    var knL = ap(hip, SEG.thigh, pose.legL.h), ftL = ap(knL, SEG.shin, pose.legL.k), toL = ap(ftL, SEG.foot, pose.legL.f);
    var knR = ap(hip, SEG.thigh, pose.legR.h), ftR = ap(knR, SEG.shin, pose.legR.k), toR = ap(ftR, SEG.foot, pose.legR.f);
    return {
      hip: hip, shoulder: shoulder, neck: neck, head: head, headAng: headAng,
      elbowL: ebL, handL: hL, handLAng: pose.armL.e, elbowR: ebR, handR: hR, handRAng: pose.armR.e,
      kneeL: knL, footL: ftL, toeL: toL, kneeR: knR, footR: ftR, toeR: toR,
      eyes: pose.eyes, headTilt: pose.headTilt, scale: pose.scale
    };
  }

  function seg(ctx, a, b, w, color, alpha) {
    if ((a.x === b.x && a.y === b.y) || w <= 0) return;
    ctx.globalAlpha = alpha === undefined ? 1 : alpha;
    ctx.strokeStyle = color; ctx.lineWidth = w; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
    ctx.globalAlpha = 1;
  }
  function dot(ctx, p, r, color, alpha) {
    ctx.globalAlpha = alpha === undefined ? 1 : alpha;
    ctx.fillStyle = color;
    ctx.beginPath(); ctx.arc(p.x, p.y, r, 0, Math.PI * 2); ctx.fill();
    ctx.globalAlpha = 1;
  }

  function drawActor(ctx, actor, sk, o) {
    var ink = o.ink || THEME.ink, dim = o.dim || THEME.inkDim;
    var unit = o.size, ox = o.x, oy = o.y, flip = o.facing < 0 ? -1 : 1;
    var alpha = o.opacity === undefined ? 1 : o.opacity;
    var sc = sk.scale || 1;
    var lineW = Math.max(1.1, 0.055 * unit * sc);
    var jointR = Math.max(0.5, 0.026 * unit * sc);
    var farAlpha = o.farAlpha === undefined ? 0.5 : o.farAlpha;

    function S(p) { return { x: ox + flip * p.x * unit * sc, y: oy - p.y * unit * sc }; }
    var J = {
      hip: S(sk.hip), shoulder: S(sk.shoulder), neck: S(sk.neck), head: S(sk.head),
      elbowL: S(sk.elbowL), handL: S(sk.handL), elbowR: S(sk.elbowR), handR: S(sk.handR),
      kneeL: S(sk.kneeL), footL: S(sk.footL), toeL: S(sk.toeL),
      kneeR: S(sk.kneeR), footR: S(sk.footR), toeR: S(sk.toeR)
    };

    // contact shadow on the floor
    ctx.globalAlpha = 0.32 * alpha;
    ctx.fillStyle = '#000';
    ctx.beginPath();
    ctx.ellipse(ox, oy + 0.015 * unit, 0.60 * unit * sc, Math.max(1, 0.07 * unit * sc), 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.globalAlpha = 1;

    // far side limbs
    seg(ctx, J.shoulder, J.elbowL, lineW * 0.95, dim, alpha * farAlpha);
    seg(ctx, J.elbowL, J.handL, lineW * 0.9, dim, alpha * farAlpha);
    seg(ctx, J.hip, J.kneeL, lineW, dim, alpha * farAlpha);
    seg(ctx, J.kneeL, J.footL, lineW * 0.98, dim, alpha * farAlpha);
    seg(ctx, J.footL, J.toeL, lineW * 0.8, dim, alpha * farAlpha);

    // torso
    seg(ctx, J.hip, J.shoulder, lineW * 1.35, ink, alpha);
    seg(ctx, J.shoulder, J.neck, lineW * 1.1, ink, alpha);

    // near side limbs
    seg(ctx, J.hip, J.kneeR, lineW, ink, alpha);
    seg(ctx, J.kneeR, J.footR, lineW * 0.98, ink, alpha);
    seg(ctx, J.footR, J.toeR, lineW * 0.8, ink, alpha);
    seg(ctx, J.shoulder, J.elbowR, lineW * 0.95, ink, alpha);
    seg(ctx, J.elbowR, J.handR, lineW * 0.9, ink, alpha);

    dot(ctx, J.hip, jointR * 1.15, ink, alpha * 0.95);
    dot(ctx, J.shoulder, jointR * 1.05, ink, alpha * 0.95);
    dot(ctx, J.kneeR, jointR, ink, alpha * 0.9);
    dot(ctx, J.elbowR, jointR * 0.85, ink, alpha * 0.9);

    // head
    var hr = SEG.head * unit * sc;
    ctx.globalAlpha = alpha;
    ctx.fillStyle = 'rgba(5,7,11,0.97)';
    ctx.beginPath(); ctx.arc(J.head.x, J.head.y, hr, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = ink; ctx.lineWidth = lineW * 1.05;
    ctx.beginPath(); ctx.arc(J.head.x, J.head.y, hr, 0, Math.PI * 2); ctx.stroke();
    ctx.globalAlpha = alpha * 0.45;
    ctx.strokeStyle = 'rgba(190,215,255,0.8)'; ctx.lineWidth = lineW * 0.5;
    ctx.beginPath(); ctx.arc(J.head.x, J.head.y, hr * 1.03, Math.PI * (flip > 0 ? 0.8 : 1.2), Math.PI * (flip > 0 ? 1.5 : 1.9)); ctx.stroke();
    ctx.globalAlpha = 1;

    // eyes — positioned on the facing side of the head
    var ang = (sk.headAng - 90) * D2R;
    var ex = flip * Math.cos(ang), ey = Math.sin(ang);
    var px = -ey * flip, py = ex * flip;
    var eDist = hr * 0.40, eOff = hr * 0.40, eyeR = Math.max(0.6, hr * 0.115);
    var ecx = J.head.x + ex * eDist, ecy = J.head.y + ey * eDist + hr * 0.05;
    var eyes = sk.eyes || 'open';
    var pts = [
      { x: ecx + px * eOff * -0.62, y: ecy + py * eOff * -0.62 },
      { x: ecx + px * eOff * 0.62, y: ecy + py * eOff * 0.62 }
    ];
    ctx.globalAlpha = alpha; ctx.fillStyle = ink; ctx.strokeStyle = ink;
    pts.forEach(function (p) {
      if (eyes === 'closed') {
        ctx.lineWidth = Math.max(1, hr * 0.11);
        ctx.beginPath(); ctx.moveTo(p.x - eyeR * 1.3, p.y); ctx.lineTo(p.x + eyeR * 1.3, p.y); ctx.stroke();
      } else if (eyes === 'half') {
        ctx.beginPath(); ctx.ellipse(p.x, p.y, eyeR * 1.2, eyeR * 0.5, 0, 0, Math.PI * 2); ctx.fill();
      } else if (eyes === 'down' || eyes === 'up') {
        ctx.beginPath(); ctx.arc(p.x, p.y, eyeR * 1.05, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = 'rgba(5,7,11,0.92)';
        ctx.beginPath();
        if (eyes === 'down') ctx.rect(p.x - eyeR * 1.5, p.y - eyeR * 1.35, eyeR * 3, eyeR * 1.35);
        else ctx.rect(p.x - eyeR * 1.5, p.y + eyeR * 0.05, eyeR * 3, eyeR * 1.5);
        ctx.fill();
        ctx.fillStyle = ink;
      } else if (eyes === 'x') {
        ctx.lineWidth = Math.max(1, eyeR * 0.8);
        ctx.beginPath();
        ctx.moveTo(p.x - eyeR, p.y - eyeR); ctx.lineTo(p.x + eyeR, p.y + eyeR);
        ctx.moveTo(p.x + eyeR, p.y - eyeR); ctx.lineTo(p.x - eyeR, p.y + eyeR);
        ctx.stroke();
      } else {
        ctx.beginPath(); ctx.arc(p.x, p.y, eyeR, 0, Math.PI * 2); ctx.fill();
      }
    });
    ctx.globalAlpha = 1;
    return J;
  }

  // ===========================================================================
  // Props (screen space, origin at the prop's base, y negative = up)
  // ===========================================================================
  function roundRectPath(ctx, x, y, w, h, r) {
    r = Math.max(0, Math.min(r, Math.abs(h) / 2, Math.abs(w) / 2));
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
  }

  // gfx draws in *world units* on a context that has already been scaled by `unit`.
  // Line widths are therefore expressed in world units too (0.012 ~ 2.3px at 1080p).
  function gfx(ctx, unit, W, H) {
    return {
      ctx: ctx, unit: unit, W: W, H: H,
      fillRect: function (x, y, w, h, color) { ctx.fillStyle = color; ctx.fillRect(x, y, w, h); },
      strokeRect: function (x, y, w, h, color, lw) {
        ctx.strokeStyle = color; ctx.lineWidth = lw === undefined ? 0.012 : lw;
        ctx.strokeRect(x, y, w, h);
      },
      roundRect: function (x, y, w, h, r, color) { ctx.fillStyle = color; roundRectPath(ctx, x, y, w, h, r); ctx.fill(); },
      circle: function (x, y, r, color) { ctx.fillStyle = color; ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill(); },
      ring: function (x, y, r, color, lw) {
        ctx.strokeStyle = color; ctx.lineWidth = lw === undefined ? 0.012 : lw;
        ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.stroke();
      },
      arcPath: function (x, y, r, a0, a1, color, lw) {
        ctx.strokeStyle = color; ctx.lineWidth = lw === undefined ? 0.012 : lw;
        ctx.beginPath(); ctx.arc(x, y, r, a0, a1); ctx.stroke();
      },
      grad: function (x0, y0, x1, y1, stops) {
        var lg = ctx.createLinearGradient(x0, y0, x1, y1);
        stops.forEach(function (s) { lg.addColorStop(s[0], s[1]); });
        return lg;
      },
      radial: function (x, y, r, stops) {
        var rg = ctx.createRadialGradient(x, y, 0, x, y, Math.max(0.0001, r));
        stops.forEach(function (s) { rg.addColorStop(s[0], s[1]); });
        return rg;
      },
      poly: function (points, color) {
        ctx.fillStyle = color;
        ctx.beginPath();
        points.forEach(function (p, i) { if (i === 0) ctx.moveTo(p[0], p[1]); else ctx.lineTo(p[0], p[1]); });
        ctx.closePath(); ctx.fill();
      },
      glow: function (x, y, r, color) {
        ctx.fillStyle = this.radial(x, y, r, [[0, color], [1, fadeA(color, 0)]]);
        ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
      },
      text: function (str, x, y, o) {
        // text must not be drawn through the unit scale (sub-pixel font sizes get
        // clamped by some engines), so map the point to device space first.
        var size = o.size === undefined ? 0.22 : o.size;
        var m = typeof ctx.getTransform === 'function' ? ctx.getTransform() : null;
        ctx.save();
        if (m) {
          var px = m.a * x + m.c * y + m.e;
          var py = m.b * x + m.d * y + m.f;
          var scale = Math.sqrt(Math.abs(m.a * m.d - m.b * m.c)) || 1;
          if (typeof ctx.setTransform === 'function') ctx.setTransform(1, 0, 0, 1, 0, 0);
          ctx.font = (o.weight || 'bold') + ' ' + Math.max(4, size * scale) + 'px ' + (o.family || THEME.fontFamily);
          ctx.fillStyle = o.color || THEME.prop;
          ctx.textAlign = o.align || 'center';
          ctx.textBaseline = 'alphabetic';
          if (o.glow) { ctx.shadowColor = o.color || '#fff'; ctx.shadowBlur = Math.max(4, size * scale) * o.glow; }
          ctx.fillText(str, px, py);
        } else {
          ctx.font = (o.weight || 'bold') + ' ' + size + 'px ' + (o.family || THEME.fontFamily);
          ctx.fillStyle = o.color || THEME.prop;
          ctx.textAlign = o.align || 'center';
          ctx.fillText(str, x, y);
        }
        ctx.restore();
      }
    };
  }

  function fadeA(color, a) {
    if (/^#[0-9a-f]{6}$/i.test(color)) {
      var n = parseInt(color.slice(1), 16);
      return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    var m = /rgba?\(([^)]+)\)/.exec(color);
    if (m) {
      var p = m[1].split(',').map(parseFloat);
      return 'rgba(' + (p[0] | 0) + ',' + (p[1] | 0) + ',' + (p[2] | 0) + ',' + a + ')';
    }
    return color;
  }
  function scaleA(color, k) {
    var m = /rgba?\(([^)]+)\)/.exec(color);
    if (m) {
      var p = m[1].split(',').map(parseFloat);
      var a = p.length > 3 ? p[3] : 1;
      return 'rgba(' + (p[0] | 0) + ',' + (p[1] | 0) + ',' + (p[2] | 0) + ',' + C.round(Math.min(1, a * k), 4) + ')';
    }
    return fadeA(color, k);
  }

  var PROPS = {
    bed: function (g, o) {
      // bed anchored at the floor; mattress top sits at PROP_SURFACE.bed (0.46)
      var w = o.w || 3.2, baseH = o.baseH || 0.22, x = -w / 2;
      // headboard (left) + footboard
      g.roundRect(x - 0.04, -(baseH + 0.78), 0.12, 0.86, 0.04, '#1b2533');
      g.roundRect(x + w - 0.06, -(baseH + 0.30), 0.10, 0.38, 0.03, '#1b2533');
      g.fillRect(x, -baseH, w, baseH, '#121a23');                       // frame
      g.fillRect(x, -baseH, w, 0.025, 'rgba(160,190,220,0.10)');
      g.fillRect(x + 0.12, -0.10, 0.08, 0.10, '#0e141c');               // legs
      g.fillRect(x + w - 0.20, -0.10, 0.08, 0.10, '#0e141c');
      g.roundRect(x + 0.05, -(baseH + 0.24), w - 0.10, 0.24, 0.06, '#1e2a38'); // mattress
      g.fillRect(x + 0.05, -(baseH + 0.24), w - 0.10, 0.022, 'rgba(210,230,255,0.16)');
      g.roundRect(x + 0.18, -(baseH + 0.42), 0.74, 0.18, 0.07, '#26374c');     // pillow
      g.fillRect(x + 0.20, -(baseH + 0.42), 0.12, 0.18, 'rgba(255,255,255,0.06)');
      // folded blanket over the foot half
      g.roundRect(x + w * 0.42, -(baseH + 0.40), w * 0.54, 0.16, 0.06, '#182131');
      g.fillRect(x + w * 0.42, -(baseH + 0.40), w * 0.54, 0.02, 'rgba(200,225,255,0.10)');
    },
    blanket: function (g, o) {
      // a blanket draped over a body lying in bed (placed above the actor)
      var w = o.w || 1.7, th = o.h || 0.18, lift = o.lift === undefined ? 0.10 : o.lift;
      g.ctx.globalAlpha = 1;
      g.roundRect(-w / 2, -(th + lift), w, th, 0.07, '#1b2635');
      g.fillRect(-w / 2, -(th + lift), w, 0.02, 'rgba(205,228,255,0.12)');
      g.roundRect(-w / 2 + 0.06, -(th + lift + 0.03), 0.3, 0.06, 0.03, '#22303f');
    },
    nightstand: function (g, o) {
      var w = o.w || 1.0, h = o.h || 0.62;
      g.fillRect(-w / 2, -h, w, h, '#131b24');
      g.fillRect(-w / 2, -h - 0.04, w, 0.05, THEME.propDim);
      g.fillRect(-w / 2 + 0.12, -h * 0.62, w - 0.24, 0.02, 'rgba(160,190,220,0.30)');
      g.fillRect(-w / 2 + 0.07, -0.2, 0.06, 0.2, THEME.propDim);
      g.fillRect(w / 2 - 0.13, -0.2, 0.06, 0.2, THEME.propDim);
    },
    alarm_clock: function (g, o) {
      var w = o.w || 0.66, h = o.h || 0.38;
      var x = -w / 2, y = -(o.h0 || 0.20) - h;
      var ringing = !!o.ringing;
      var t = o.t || 0;
      var shake = ringing ? (C.noise1(77, t * 30) - 0.5) * 0.035 : 0;
      var pulse = ringing ? 0.5 + 0.5 * Math.abs(Math.sin(t * 7)) : 0;
      g.ctx.save();
      g.ctx.translate(shake, 0);
      if (ringing) {
        g.glow(0, y + h / 2, 0.5, 'rgba(255,47,69,' + C.round(0.20 * pulse, 3) + ')');
        g.circle(-w * 0.52, y - 0.03, 0.075, '#2b3442');
        g.circle(w * 0.52, y - 0.03, 0.075, '#2b3442');
        for (var i = 0; i < 3; i++) {
          var r = w * (0.62 + i * 0.17) + 0.05 * pulse;
          g.ctx.globalAlpha = (0.55 - i * 0.15) * pulse;
          g.arcPath(0, y + h / 2, r, -Math.PI * 0.95, -Math.PI * 0.05, THEME.red, 0.020);
          g.arcPath(0, y + h / 2, r, Math.PI * 0.05, Math.PI * 0.95, THEME.red, 0.020);
          g.ctx.globalAlpha = 1;
        }
      }
      g.ctx.restore();
      g.fillRect(x, y, w, h, '#1a222c');
      g.strokeRect(x, y, w, h, THEME.propDim, 0.012);
      g.roundRect(x + 0.05, y + 0.07, w - 0.10, h - 0.14, 0.03, 'rgba(255,176,32,0.10)');
      g.text(o.time || '5:00', 0, y + h * 0.72, { size: 0.22, color: o.timeColor || THEME.amber, glow: 0.4 });
      g.fillRect(-w / 2 - 0.02, y + h - 0.005, w + 0.04, 0.03, THEME.propDim);
    },
    phone: function (g, o) {
      var w = o.w || 0.30, h = o.h || 0.56, x = -w / 2, y = -h / 2;
      var on = o.on !== false, t = o.t || 0;
      g.fillRect(x, y, w, h, '#0d131b');
      g.strokeRect(x, y, w, h, THEME.prop, 0.012);
      if (on) {
        g.glow(0, 0, w * 2.8, 'rgba(87,215,255,' + C.round(0.10 + 0.06 * Math.abs(Math.sin(t * 2.2)), 3) + ')');
        g.fillRect(x + 0.035, y + 0.055, w - 0.07, h - 0.11, '#8fc4e0');
        var lines = o.lines === undefined ? 3 : o.lines;
        for (var i = 0; i < lines; i++) {
          g.fillRect(x + 0.06, y + 0.11 + i * 0.115, (w - 0.12) * (i % 2 ? 0.5 : 0.82), 0.032, 'rgba(10,20,30,0.85)');
        }
      }
    },
    desk: function (g, o) {
      var w = o.w || 2.3, h = o.h || 0.72;
      g.fillRect(-w / 2, -h, w, 0.06, THEME.prop);
      g.fillRect(-w / 2, -h + 0.06, w, 0.02, 'rgba(0,0,0,0.4)');
      g.fillRect(-w / 2 + 0.09, -h + 0.08, 0.07, h - 0.08, THEME.propDim);
      g.fillRect(w / 2 - 0.16, -h + 0.08, 0.07, h - 0.08, THEME.propDim);
      g.fillRect(-w / 2 + 0.22, -h + 0.14, w - 0.66, 0.34, '#121a22');
      g.fillRect(-w / 2 + 0.26, -h + 0.18, w - 0.74, 0.02, 'rgba(160,190,220,0.25)');
    },
    laptop: function (g, o) {
      var w = o.w || 0.8, sh = o.h || 0.5, x = -w / 2, t = o.t || 0;
      g.fillRect(x, -0.03, w, 0.04, THEME.prop);
      g.fillRect(x, -0.03, w, 0.01, 'rgba(255,255,255,0.25)');
      g.fillRect(x, -sh, w, 0.035, '#2a3441');
      g.fillRect(x + 0.03, -sh + 0.02, w - 0.06, sh - 0.05, '#0f1620');
      g.fillRect(x + 0.06, -sh + 0.05, w - 0.12, sh - 0.13, 'rgba(120,190,235,' + C.round(0.35 + 0.12 * Math.abs(Math.sin(t * 1.7)), 3) + ')');
      g.glow(0, -sh * 0.6, w * 1.9, 'rgba(120,190,235,0.10)');
    },
    monitor: function (g, o) {
      var w = o.w || 1.0, h = o.h || 0.62, t = o.t || 0;
      g.fillRect(-0.04, -0.1, 0.08, 0.1, THEME.propDim);
      g.fillRect(-w * 0.22, -0.14, w * 0.44, 0.05, THEME.propDim);
      g.fillRect(-w / 2, -(0.14 + h), w, h, '#151d27');
      g.fillRect(-w / 2 + 0.03, -(0.14 + h) + 0.03, w - 0.06, h - 0.06, 'rgba(120,190,235,' + C.round(0.30 + 0.10 * Math.abs(Math.sin(t * 1.3)), 3) + ')');
      for (var i = 0; i < 4; i++) g.fillRect(-w / 2 + 0.09, -(0.14 + h) + 0.10 + i * 0.11, (w - 0.22) * (i % 2 ? 0.5 : 0.8), 0.03, 'rgba(8,16,24,0.6)');
      g.glow(0, -(0.14 + h / 2), w * 1.6, 'rgba(120,190,235,0.09)');
    },
    mirror: function (g, o) {
      var w = o.w || 1.6, h = o.h || 2.5, x = -w / 2, y = -(h + 0.18);
      g.fillRect(x - 0.05, y - 0.05, w + 0.1, h + 0.1, '#1e2937');
      g.fillRect(x, y, w, h, '#0a121c');
      g.fillRect(x + 0.03, y + 0.03, w - 0.06, h - 0.06, 'rgba(120,170,220,0.06)');
      g.fillRect(x + w * 0.16, y + 0.08, w * 0.10, h - 0.16, 'rgba(200,230,255,0.05)');
      g.fillRect(x + w * 0.62, y + h * 0.22, w * 0.22, 0.03, 'rgba(255,255,255,0.05)');
      g.fillRect(x - 0.12, -0.18, 0.08, 0.18, THEME.propDim);
      g.fillRect(x + w + 0.04, -0.18, 0.08, 0.18, THEME.propDim);
    },
    dumbbells: function (g, o) {
      var n = o.count || 2;
      for (var i = 0; i < n; i++) {
        var x = -0.28 + i * 0.56, y = -0.10;
        g.fillRect(x - 0.16, y - 0.02, 0.32, 0.04, '#8fa3b8');
        g.circle(x - 0.20, y, 0.09, '#2b3442');
        g.circle(x + 0.20, y, 0.09, '#2b3442');
        g.ring(x - 0.20, y, 0.09, 'rgba(200,220,240,0.30)', 0.012);
        g.ring(x + 0.20, y, 0.09, 'rgba(200,220,240,0.30)', 0.012);
        g.fillRect(x - 0.20, y - 0.09, 0.40, 0.012, 'rgba(0,0,0,0.35)');
      }
    },
    dumbbell: function (g) {
      g.fillRect(-0.15, -0.022, 0.30, 0.044, '#8fa3b8');       // bar
      g.fillRect(-0.05, -0.03, 0.10, 0.06, '#5d6d80');          // grip
      [-0.19, 0.19].forEach(function (x) {
        g.circle(x, 0, 0.085, '#2b3442');                        // plate
        g.circle(x, 0, 0.085, '#2b3442');
        g.ring(x, 0, 0.085, 'rgba(200,220,240,0.35)', 0.012);
        g.ring(x, 0, 0.045, 'rgba(200,220,240,0.20)', 0.010);
      });
    },
    stairs: function (g, o) {
      var steps = o.steps || 4, sw = o.w || 0.72, sh = o.h || 0.34;
      for (var i = steps - 1; i >= 0; i--) {
        var x = i * sw, top = -(i + 1) * sh;
        g.fillRect(x, top, sw + 0.02, (i + 1) * sh + 0.0001, i % 2 ? '#18212c' : '#141c26');
        g.fillRect(x, top, sw, 0.035, THEME.prop);
      }
    },
    window: function (g, o) {
      var w = o.w || 2.0, h = o.h || 2.1, x = -w / 2, y = -(h + 0.75), t = o.t || 0;
      g.glow(0, y + h * 0.6, w * 1.5, 'rgba(140,180,240,0.07)');
      g.fillRect(x - 0.07, y - 0.07, w + 0.14, h + 0.14, '#1b2532');
      g.fillRect(x, y, w, h, '#060b13');
      var mx = x + w * 0.66, my = y + h * 0.74;
      g.glow(mx, my, 0.65, 'rgba(200,225,255,0.13)');
      g.circle(mx, my, 0.16, 'rgba(226,238,255,0.9)');
      for (var i = 0; i < 18; i++) {
        var rx = C.noise1(4241 + i * 13, 3.1), ry = C.noise1(9811 + i * 7, 7.7);
        var tw = 0.55 + 0.45 * Math.sin(t * 1.2 + i);
        g.circle(x + 0.1 + rx * (w - 0.2), y + 0.1 + ry * (h * 0.62), 0.014 + rx * 0.012, 'rgba(220,235,255,' + C.round(0.30 + 0.35 * tw, 2) + ')');
      }
      g.fillRect(x + w / 2 - 0.025, y, 0.05, h, '#1b2532');
      g.fillRect(x, y + h / 2 - 0.025, w, 0.05, '#1b2532');
      g.fillRect(x - 0.12, y - 0.09, w + 0.24, 0.09, '#22303f');
      // moonlight spill onto the floor
      g.poly([[x, y], [x + w, y], [x + w + 0.9, 0], [x - 0.9, 0]], 'rgba(150,190,245,0.05)');
    },
    door_frame: function (g, o) {
      var w = o.w || 1.1, h = o.h || 2.2, x = -w / 2, y = -h;
      g.fillRect(x - 0.06, y, 0.06, h, '#141d27');
      g.fillRect(x + w, y, 0.06, h, '#141d27');
      g.fillRect(x - 0.06, y - 0.06, w + 0.12, 0.06, '#141d27');
      g.fillRect(x, y, w, h, '#080d14');
    },
    chair: function (g, o) {
      var w = o.w || 0.86, h = o.h || 0.5, bh = o.backH === undefined ? 0.72 : o.backH;
      g.fillRect(-w / 2, -h, w, 0.06, THEME.prop);
      g.fillRect(-w / 2 + 0.06, -h + 0.06, 0.06, h - 0.06, THEME.propDim);
      g.fillRect(w / 2 - 0.12, -h + 0.06, 0.06, h - 0.06, THEME.propDim);
      g.fillRect(-w / 2 + 0.04, -h - bh, 0.07, bh, THEME.propDim);
      g.fillRect(-w / 2 + 0.04, -h - bh, w * 0.55, 0.18, '#16202b');
    },
    lamp: function (g, o) {
      var on = o.on !== false, spread = o.spread || 2.6;
      g.poly([[-0.12, -1.95], [-spread * 0.5, 0], [spread * 0.5, 0], [0.12, -1.95]], on ? 'rgba(255,214,150,0.055)' : 'rgba(0,0,0,0)');
      g.fillRect(-0.03, -1.9, 0.06, 1.9, THEME.propDim);
      g.fillRect(-0.28, -0.06, 0.56, 0.06, THEME.propDim);
      g.fillRect(-0.22, -2.03, 0.44, 0.16, '#2b3442');
      if (on) g.glow(0, -1.95, 0.4, 'rgba(255,214,150,0.30)');
    },
    whiteboard: function (g) {
      var w = 1.9, h = 1.1, y = -(h + 0.9);
      g.fillRect(-w / 2 - 0.04, y - 0.04, w + 0.08, h + 0.08, '#1a2431');
      g.fillRect(-w / 2, y, w, h, '#e8eef5');
      for (var i = 0; i < 5; i++) g.fillRect(-w / 2 + 0.16, y + 0.16 + i * 0.19, (w - 0.42) * (i % 2 ? 0.55 : 0.85), 0.022, 'rgba(30,45,60,0.45)');
    },
    water_bottle: function (g) {
      g.fillRect(-0.07, -0.30, 0.14, 0.30, 'rgba(150,205,235,0.5)');
      g.fillRect(-0.045, -0.36, 0.09, 0.07, THEME.prop);
    },
    plate: function (g) {
      g.fillRect(-0.14, -0.03, 0.28, 0.03, THEME.propDim);
    }
  };
  // height above a prop's own origin where an actor can stand / sit / lie
  var PROP_SURFACE = { bed: 0.46, nightstand: 0.62, desk: 0.72, chair: 0.50, stairs: 0.34, plate: 0.03 };

  var PROP_LIST = [
    { id: 'bed', label: 'Bed', w: 3.2, h: 0.22 },
    { id: 'blanket', label: 'Blanket (over body)', w: 1.7, h: 0.18 },
    { id: 'nightstand', label: 'Nightstand / table', w: 1.0, h: 0.62 },
    { id: 'alarm_clock', label: 'Alarm clock', w: 0.66, h: 0.38 },
    { id: 'phone', label: 'Phone', w: 0.30, h: 0.56 },
    { id: 'desk', label: 'Desk', w: 2.3, h: 0.72 },
    { id: 'laptop', label: 'Laptop', w: 0.8, h: 0.5 },
    { id: 'monitor', label: 'Monitor', w: 1.0, h: 0.62 },
    { id: 'mirror', label: 'Mirror', w: 1.6, h: 2.5 },
    { id: 'dumbbells', label: 'Dumbbells', w: 1.0, h: 0.2 },
    { id: 'stairs', label: 'Stairs', w: 0.72, h: 0.34, count: 4 },
    { id: 'window', label: 'Window', w: 2.0, h: 2.1 },
    { id: 'door_frame', label: 'Door frame', w: 1.1, h: 2.2 },
    { id: 'chair', label: 'Chair', w: 0.86, h: 0.5 },
    { id: 'lamp', label: 'Floor lamp', w: 1.0, h: 2.0 },
    { id: 'whiteboard', label: 'Whiteboard', w: 1.9, h: 1.1 },
    { id: 'water_bottle', label: 'Water bottle', w: 0.2, h: 0.36 }
  ];

  // ===========================================================================
  // Backgrounds — drawn in WORLD space (y up), camera transform applied
  // ===========================================================================
  var BACKGROUNDS = {
    void: function () { },
    bedroom: function (g, o) {
      var t = o.t, horizon = o.horizon === undefined ? 1.35 : o.horizon;
      g.fillRect(-30, horizon, 60, 30, THEME.wall);
      g.fillRect(-30, horizon - 30, 60, 30, THEME.floor);
      g.fillRect(-30, horizon - 0.02, 60, 0.04, 'rgba(150,180,215,0.18)');
      g.fillRect(-30, horizon, 60, 30, g.grad(-6, 0, 4, 0, [[0, 'rgba(90,130,190,0.14)'], [1, 'rgba(90,130,190,0)']]));
      g.fillRect(-30, horizon - 2.4, 60, 2.4, g.grad(0, horizon - 2.4, 0, horizon, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(90,130,190,0.09)']]));
      g.fillRect(-30, horizon + 2.0, 60, 0.03, 'rgba(150,180,215,0.06)');
      dust(g, 0.9, t, 60, horizon);
    },
    gym: function (g, o) {
      var t = o.t, horizon = 1.2;
      g.fillRect(-30, horizon, 60, 30, '#070a10');
      g.fillRect(-30, horizon - 30, 60, 30, '#0a0e14');
      g.fillRect(-30, horizon - 0.03, 60, 0.05, 'rgba(150,180,215,0.16)');
      // overhead spot cone
      g.poly([[-1.1, 6.2], [-4.6, horizon], [4.6, horizon], [1.1, 6.2]], 'rgba(255,240,215,0.035)');
      g.fillRect(-30, horizon, 60, 30, g.grad(0, horizon, 0, horizon + 3, [[0, 'rgba(255,235,205,0.05)'], [1, 'rgba(0,0,0,0)']]));
      for (var i = -6; i <= 6; i++) g.fillRect(i * 1.6, horizon, 0.012, 0.0, 'rgba(0,0,0,0)');
      for (var j = -12; j <= 12; j++) g.fillRect(j * 0.8, horizon - 0.001, 0.008, 0.001, 'rgba(120,150,190,0.0)');
      for (var k = 0; k < 14; k++) g.fillRect(-12 + k * 1.8, horizon - 0.3, 0.02, 0.6, 'rgba(120,150,190,0.05)');
      for (var m = 0; m < 6; m++) g.fillRect(-14, horizon - 0.02 + m * 0.42, 28, 0.012, 'rgba(120,150,190,0.05)');
      dust(g, 1.1, t, 42, horizon);
    },
    office: function (g, o) {
      var t = o.t, horizon = 1.3;
      g.fillRect(-30, horizon, 60, 30, '#080c12');
      g.fillRect(-30, horizon - 30, 60, 30, '#0a0e14');
      g.fillRect(-30, horizon - 0.03, 60, 0.05, 'rgba(150,180,215,0.16)');
      g.fillRect(-30, horizon, 60, 30, g.grad(6, 0, -8, 0, [[0, 'rgba(110,180,230,0.13)'], [1, 'rgba(110,180,230,0)']]));
      for (var i = 0; i < 3; i++) g.fillRect(-6 + i * 5.5, horizon + 2.1, 3.2, 1.5, 'rgba(150,180,215,0.05)');
      dust(g, 0.5, t, 26, horizon);
    },
    stairwell: function (g, o) {
      var t = o.t, horizon = 0.9;
      g.fillRect(-30, horizon, 60, 30, '#060a10');
      g.fillRect(-30, horizon - 30, 60, 30, '#090d13');
      g.fillRect(-30, horizon - 0.03, 60, 0.05, 'rgba(150,180,215,0.12)');
      g.radial(1.5, 4.4, 4.0, [[0, 'rgba(255,225,180,0.10)'], [1, 'rgba(255,225,180,0)']]);
      g.fillRect(-30, -1 + horizon, 60, 1, 'rgba(0,0,0,0)');
      dust(g, 0.8, t, 30, horizon);
    },
    night_city: function (g, o) {
      var t = o.t, horizon = 0.9;
      g.fillRect(-30, horizon, 60, 30, '#050810');
      g.fillRect(-30, horizon - 30, 60, 30, '#070b12');
      for (var i = 0; i < 30; i++) {
        var x = -11 + i * 0.78 + C.noise1(555 + i, 2.0) * 0.2;
        var h = 1.2 + C.noise1(777 + i * 3, 5.0) * 3.6;
        g.fillRect(x, horizon, 0.56, h, 'rgba(11,16,25,0.98)');
        for (var w = 0; w < 6; w++) {
          if (C.noise1(31 + i * 17 + w * 5, 9.0) > 0.55) {
            var fl = 0.6 + 0.4 * Math.sin(t * 0.7 + i + w);
            g.fillRect(x + 0.08 + (w % 2) * 0.27, horizon + 0.28 + Math.floor(w / 2) * 0.44, 0.13, 0.19, 'rgba(255,200,120,' + C.round(0.22 + 0.22 * fl, 2) + ')');
          }
        }
      }
      g.fillRect(-30, horizon - 0.02, 60, 0.04, 'rgba(150,180,215,0.12)');
      dust(g, 0.7, t, 30, horizon);
    },
    road_dawn: function (g, o) {
      var t = o.t, horizon = 0.9;
      g.fillRect(-30, horizon, 60, 30, '#07090f');
      g.fillRect(-30, horizon - 30, 60, 30, '#0a0d14');
      g.fillRect(-30, horizon, 60, 4.2, g.grad(0, horizon, 0, horizon + 4.2, [[0, 'rgba(200,90,60,0.30)'], [0.5, 'rgba(120,60,80,0.18)'], [1, 'rgba(0,0,0,0)']]));
      g.glow(-3.4, horizon + 0.35, 1.6, 'rgba(255,140,90,0.30)');
      g.circle(-3.4, horizon + 0.35, 0.42, 'rgba(255,175,125,0.65)');
      g.fillRect(-30, horizon - 0.02, 60, 0.04, 'rgba(255,170,120,0.22)');
      dust(g, 0.6, t, 24, horizon);
    },
    mirror_room: function (g, o) {
      var t = o.t, horizon = 1.25;
      g.fillRect(-30, horizon, 60, 30, '#080d14');
      g.fillRect(-30, horizon - 30, 60, 30, '#0b1017');
      g.fillRect(-30, horizon - 0.03, 60, 0.05, 'rgba(150,180,215,0.2)');
      for (var i = 0; i < 24; i++) g.fillRect(-12 + i * 1.0, horizon, 0.012, 0.5, 'rgba(120,150,190,0.05)');
      g.radial(0, 3.4, 4.4, [[0, 'rgba(200,225,255,0.07)'], [1, 'rgba(200,225,255,0)']]);
      g.fillRect(-30, horizon - 2.2, 60, 2.2, g.grad(0, horizon - 2.2, 0, horizon, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(140,180,230,0.07)']]));
      dust(g, 0.6, t, 34, horizon);
    }
  };
  var BG_LIST = [
    { id: 'void', label: 'Void black' },
    { id: 'bedroom', label: 'Bedroom (night)' },
    { id: 'mirror_room', label: 'Spare room / gym' },
    { id: 'gym', label: 'Gym (spotlight)' },
    { id: 'office', label: 'Office (cool light)' },
    { id: 'stairwell', label: 'Stairwell' },
    { id: 'night_city', label: 'Night city skyline' },
    { id: 'road_dawn', label: 'Road at dawn' }
  ];

  function dust(g, amount, t, count, horizon) {
    for (var i = 0; i < count; i++) {
      var seed = 1000 + i * 37;
      var baseX = C.noise1(seed, 2) * 22 - 11;
      var baseY = C.noise1(seed + 5, 3) * 5.4;
      var y = ((baseY + t * (0.05 + C.noise1(seed + 9, 4) * 0.14)) % 5.4);
      var x = baseX + Math.sin(t * 0.4 + i) * 0.25;
      var tw = 0.5 + 0.5 * Math.sin(t * 1.4 + i * 1.7);
      var r = 0.008 + C.noise1(seed + 11, 6) * 0.012;
      g.circle(x, y, r, 'rgba(210,228,255,' + C.round(0.05 + 0.13 * tw * amount, 3) + ')');
      void horizon;
    }
  }

  // ===========================================================================
  // Camera
  // ===========================================================================
  function cameraAt(scene, t) {
    var cam = scene.camera || {};
    var c = {
      x: trackValue(cam.xTrack, t, cam.x === undefined ? STAGE_W / 2 : cam.x),
      y: trackValue(cam.yTrack, t, cam.y === undefined ? 2.5 : cam.y),
      zoom: trackValue(cam.zoomTrack, t, cam.zoom === undefined ? 1 : cam.zoom),
      rot: trackValue(cam.rotTrack, t, cam.rot || 0)
    };
    if (cam.handheld) {
      c.x += (C.fbm1(scene.seed || 1, t * 0.35, 3) - 0.5) * 0.20 * cam.handheld;
      c.y += (C.fbm1((scene.seed || 1) + 91, t * 0.31, 3) - 0.5) * 0.14 * cam.handheld;
      c.zoom *= 1 + (C.noise1((scene.seed || 1) + 5, t * 0.5) - 0.5) * 0.010 * cam.handheld;
    }
    (scene.shakes || []).forEach(function (s) {
      if (t < (s.start || 0) || t > (s.end === undefined ? (s.start || 0) + (s.duration || 0.5) : s.end)) return;
      var amp = s.amp === undefined ? 0.06 : s.amp;
      var decay = s.decay === undefined ? 1 : s.decay;
      var k = decay < 1 ? 1 : 1;
      c.x += (C.noise1(31, t * 40) - 0.5) * amp * 2 * k;
      c.y += (C.noise1(77, t * 40) - 0.5) * amp * 2 * k;
      c.rot += (C.noise1(13, t * 60) - 0.5) * amp * 5 * k;
    });
    return c;
  }

  function makeTransform(W, H, cam) {
    var ppu = (W / STAGE_W) * cam.zoom;
    var cx = W / 2, cy = H / 2;
    var cos = Math.cos(cam.rot * D2R), sin = Math.sin(cam.rot * D2R);
    return {
      ppu: ppu,
      toScreen: function (wx, wy) {
        var dx = (wx - cam.x) * ppu, dy = -(wy - cam.y) * ppu;
        return { x: cx + dx * cos - dy * sin, y: cy + dx * sin + dy * cos };
      },
      apply: function (ctx) {
        ctx.translate(cx, cy);
        ctx.rotate(cam.rot * D2R);
        ctx.scale(ppu, -ppu);
        ctx.translate(-cam.x, -cam.y);
      }
    };
  }

  // ===========================================================================
  // Text
  // ===========================================================================
  function layoutText(ctx, text, maxWidthPx, fontSpec) {
    ctx.font = fontSpec;
    var runs = C.parseMarkup(text);
    var words = [];
    runs.forEach(function (r) {
      r.text.split(/(\s+)/).forEach(function (piece) {
        if (piece === '') return;
        if (/^\s+$/.test(piece)) words.push({ space: true, emphasis: 0, text: ' ' });
        else words.push({ space: false, text: piece, emphasis: r.emphasis });
      });
    });
    var spaceW = ctx.measureText(' ').width;
    var lines = [], line = [], lineW = 0;
    words.forEach(function (w) {
      if (w.space) return;
      var width = ctx.measureText(w.text).width;
      var add = line.length ? spaceW + width : width;
      if (line.length && lineW + add > maxWidthPx) {
        lines.push({ items: line, width: lineW });
        line = []; lineW = 0; add = width;
      }
      w.width = width;
      line.push(w); lineW += add;
    });
    if (line.length) lines.push({ items: line, width: lineW });
    lines.forEach(function (l) {
      var x = -l.width / 2;
      l.items.forEach(function (it, i) { it.x = x; it.idx = i; x += it.width + spaceW; });
    });
    return { lines: lines, spaceW: spaceW };
  }

  function fitTextBlock(ctx, text, o) {
    var size = o.size || 100, minSize = o.minSize || 24, weight = o.weight || 'bold';
    var family = o.family || THEME.fontFamily;
    var probe = null, widest = 0, total = 0;
    while (size > minSize) {
      var spec = weight + ' ' + size + 'px ' + family;
      probe = layoutText(ctx, text, o.maxW, spec);
      widest = 0;
      probe.lines.forEach(function (l) { widest = Math.max(widest, l.width); });
      total = probe.lines.length * size * 1.16;
      if (total <= o.maxH && widest <= o.maxW + 1) break;
      size = Math.max(minSize, Math.round(size * 0.94));
    }
    return { layout: probe, size: size, lineHeight: size * 1.16, fontSpec: weight + ' ' + size + 'px ' + family };
  }

  function drawTextBlock(ctx, o) {
    var cfg = o.style || {};
    var W = o.W, H = o.H;
    var maxW = W * (cfg.maxWidthPct === undefined ? 0.84 : cfg.maxWidthPct);
    var maxH = H * (cfg.maxHeightPct === undefined ? 0.32 : cfg.maxHeightPct);
    var refSize = cfg.size && cfg.size > 0 ? cfg.size * (H / 1080) : H * 0.09;
    var fit = fitTextBlock(ctx, o.text, {
      maxW: maxW, maxH: maxH, size: refSize, weight: cfg.weight || 'bold',
      family: cfg.fontFamily || o.fontFamily || THEME.fontFamily,
      minSize: Math.round(H * 0.026)
    });
    var layout = fit.layout, lh = fit.lineHeight;
    var cx = cfg.x === undefined ? 0.5 : cfg.x;
    var cy = cfg.y === undefined ? (cfg.align === 'top' ? 0.16 : cfg.align === 'bottom' ? 0.80 : 0.5) : cfg.y;
    var baseX = W * cx, baseY = H * cy;
    var totalH = layout.lines.length * lh;
    var topY = baseY - totalH / 2 + lh * 0.72;

    var p = C.clamp(o.progress === undefined ? 1 : o.progress, 0, 1);
    var anim = cfg.anim || 'fadeUp';
    var easeP = C.ease(cfg.animEase || 'outCubic', p);
    var baseColor = C.hex(cfg.color || '#ffffff');
    var emphasisColor = C.hex(cfg.emphasisColor || THEME.red);
    var dimColor = C.hex(cfg.dimColor || '#8c9aac');

    var ox = 0, oy = 0, alpha = 1, scale = 1, tracking = 0, perWord = null;
    switch (anim) {
      case 'none': break;
      case 'fade': alpha = easeP; break;
      case 'fadeUp': alpha = easeP; oy = (1 - easeP) * H * 0.055; break;
      case 'fadeDown': alpha = easeP; oy = -(1 - easeP) * H * 0.055; break;
      case 'slideLeft': alpha = easeP; ox = (1 - easeP) * W * 0.14; break;
      case 'slideRight': alpha = easeP; ox = -(1 - easeP) * W * 0.14; break;
      case 'scaleIn': alpha = easeP; scale = C.lerp(0.90, 1, easeP); break;
      case 'popIn': alpha = C.clamp(p * 3, 0, 1); scale = C.lerp(0.60, 1, C.ease('outBack', p)); break;
      case 'slam': alpha = C.clamp(p * 7, 0, 1); scale = C.lerp(1.30, 1, C.ease('outQuint', p)); break;
      case 'typewriter': perWord = 'type'; break;
      case 'wordPop': perWord = 'pop'; break;
      case 'shake': alpha = C.clamp(p * 4, 0, 1); ox = Math.sin(p * 42) * W * 0.008 * Math.pow(1 - p, 2); break;
      case 'glitch': alpha = C.clamp(p * 5, 0, 1); ox = (C.noise1(99, Math.floor((o.t || 0) * 20)) - 0.5) * W * 0.008 * (1 - easeP); break;
      case 'letterspace': alpha = easeP; tracking = (1 - easeP) * fit.size * 0.6; break;
      case 'riseSlow': alpha = C.clamp(p * 1.2, 0, 1); oy = (1 - C.ease('outQuad', p)) * H * 0.10; break;
      default: alpha = easeP;
    }

    ctx.save();
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';

    if (cfg.panel) {
      var pw = 0;
      layout.lines.forEach(function (l) { pw = Math.max(pw, l.width); });
      pw += fit.size * 1.3;
      var ph = totalH + fit.size * 0.7;
      ctx.globalAlpha = alpha * 0.6;
      ctx.fillStyle = cfg.panelColor || 'rgba(4,6,10,0.78)';
      roundRectPath(ctx, baseX - pw / 2 + ox, baseY - ph / 2 + oy, pw, ph, fit.size * 0.16);
      ctx.fill();
      ctx.globalAlpha = 1;
    }

    // reading-order index for typewriter / word pop
    var order = 0, totalItems = 0;
    layout.lines.forEach(function (l) { l.items.forEach(function () { totalItems++; }); });
    var charBudget = Infinity;
    if (perWord === 'type') {
      var totalChars = 0;
      layout.lines.forEach(function (l) { l.items.forEach(function (it) { totalChars += it.text.length + 1; }); });
      charBudget = Math.ceil(p * (totalChars + 1));
    }

    layout.lines.forEach(function (line, li) {
      line.items.forEach(function (it) {
        var col = it.emphasis === 1 ? emphasisColor : (it.emphasis === 2 ? dimColor : baseColor);
        var text = it.text;
        var wAlpha = alpha, wScale = scale, wOffY = 0;
        if (perWord === 'type') {
          if (charBudget <= 0) { charBudget -= text.length + 1; return; }
          text = text.slice(0, Math.max(0, Math.min(text.length, charBudget)));
          charBudget -= (it.text.length + 1);
        }
        if (!text) { order++; return; }
        if (perWord === 'pop') {
          var delay = order * 0.5 / Math.max(1, totalItems);
          var wp = C.clamp((p - delay) / Math.max(0.05, 1 - delay), 0, 1);
          wAlpha = alpha * C.clamp(wp * 3, 0, 1);
          wScale = scale * C.lerp(0.85, 1, C.ease('outBack', wp));
          wOffY = (1 - C.ease('outCubic', wp)) * fit.size * 0.30;
        }
        var lx = baseX + it.x + ox, ly = topY + li * lh + oy + wOffY;
        if (tracking) lx += tracking * (it.idx - (line.items.length - 1) / 2);
        ctx.save();
        ctx.globalAlpha = wAlpha;
        ctx.translate(lx, ly);
        ctx.scale(wScale, wScale);
        ctx.font = fit.fontSpec;
        if (cfg.glow) {
          ctx.shadowColor = C.toCss(C.rgba(col, 0.75));
          ctx.shadowBlur = fit.size * (cfg.glow === true ? 0.4 : cfg.glow);
        }
        ctx.fillStyle = C.toCss(col);
        ctx.fillText(text, 0, 0);
        ctx.restore();
        order++;
      });
    });

    if (cfg.underline && perWord !== 'type') {
      var uy = topY + (layout.lines.length - 1) * lh + fit.size * 0.28 + oy;
      var uw = 0;
      layout.lines.forEach(function (l) {
        var w0 = 0;
        l.items.forEach(function (it) { if (it.emphasis === 1) w0 = Math.max(w0, it.x + it.width); });
        uw = Math.max(uw, w0);
      });
      var sweep = C.ease('outQuint', C.clamp((p - 0.3) / 0.7, 0, 1));
      ctx.globalAlpha = alpha * 0.92;
      ctx.fillStyle = C.toCss(emphasisColor);
      ctx.fillRect(baseX + ox, uy, uw * sweep, Math.max(2, fit.size * 0.045));
      ctx.globalAlpha = 1;
    }
    ctx.restore();
    return { size: fit.size, lines: layout.lines.length };
  }

  // ===========================================================================
  // Effects (screen space)
  // ===========================================================================
  var FX_LIST = [
    { id: 'flash', label: 'Flash' }, { id: 'impactRing', label: 'Impact ring' },
    { id: 'speedLines', label: 'Speed lines' }, { id: 'redPulse', label: 'Red pulse' },
    { id: 'spotlight', label: 'Spotlight' }, { id: 'rain', label: 'Rain' },
    { id: 'shatter', label: 'Shatter debris' }, { id: 'heartbeat', label: 'Heartbeat vignette' },
    { id: 'textCallout', label: 'Text callout' }
  ];

  function drawEffect(ctx, fx, W, H, t) {
    var start = fx.start || 0;
    var dur = fx.duration === undefined ? 1 : fx.duration;
    var end = fx.end === undefined ? start + dur : fx.end;
    if (t < start) return;
    if (!fx.persistent && t > end) return;
    var p = C.clamp((t - start) / Math.max(0.01, (end - start)), 0, 1);
    var color = fx.color || THEME.red;
    var amount = fx.alpha === undefined ? 0.8 : fx.alpha;
    switch (fx.type) {
      case 'flash':
        ctx.globalAlpha = fx.persistent ? amount : Math.pow(1 - p, 2) * amount;
        ctx.fillStyle = color; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1; break;
      case 'impactRing': {
        var r = C.ease('outQuint', p) * W * (fx.scale || 0.16);
        ctx.globalAlpha = Math.pow(1 - p, 1.6) * amount;
        ctx.strokeStyle = color; ctx.lineWidth = Math.max(1, H * 0.006 * (1 - p));
        ctx.beginPath(); ctx.arc(W * (fx.x === undefined ? 0.5 : fx.x), H * (fx.y === undefined ? 0.5 : fx.y), Math.max(1, r), 0, Math.PI * 2); ctx.stroke();
        ctx.globalAlpha = 1; break;
      }
      case 'speedLines': {
        ctx.globalAlpha = amount * Math.sin(Math.PI * C.clamp(p, 0, 1));
        ctx.strokeStyle = color; ctx.lineWidth = Math.max(1, H * 0.0025);
        var n = fx.count || 26;
        for (var i = 0; i < n; i++) {
          var yy = C.noise1(7 + i * 13, Math.floor(t * 24)) * H;
          var len = W * (0.08 + C.noise1(3 + i, Math.floor(t * 18)) * 0.22);
          var xx = ((t * (2400 + i * 40)) % (W * 1.5)) - W * 0.25;
          ctx.beginPath(); ctx.moveTo(xx, yy); ctx.lineTo(xx + len, yy); ctx.stroke();
        }
        ctx.globalAlpha = 1; break;
      }
      case 'redPulse': {
        var pulse = 0.5 + 0.5 * Math.sin(t * (fx.hz || 1.6) * Math.PI * 2);
        ctx.globalAlpha = amount * pulse;
        ctx.fillStyle = ctx.createRadialGradient(W / 2, H / 2, H * 0.10, W / 2, H / 2, H * 0.85);
        ctx.fillStyle.addColorStop ? null : null;
        var rg = ctx.createRadialGradient(W / 2, H / 2, H * 0.10, W / 2, H / 2, H * 0.85);
        rg.addColorStop(0, 'rgba(0,0,0,0)'); rg.addColorStop(1, fadeA(color, 1));
        ctx.fillStyle = rg; ctx.fillRect(0, 0, W, H);
        ctx.globalAlpha = 1; break;
      }
      case 'spotlight': {
        var rg2 = ctx.createRadialGradient(W * (fx.x === undefined ? 0.5 : fx.x), H * (fx.y === undefined ? 0.5 : fx.y), H * 0.01,
          W * (fx.x === undefined ? 0.5 : fx.x), H * (fx.y === undefined ? 0.5 : fx.y), H * (fx.r || 0.7));
        rg2.addColorStop(0, 'rgba(255,255,255,' + (amount * 0.55) + ')');
        rg2.addColorStop(0.4, 'rgba(255,255,255,' + (amount * 0.12) + ')');
        rg2.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = rg2; ctx.fillRect(0, 0, W, H); break;
      }
      case 'rain':
        ctx.globalAlpha = amount;
        ctx.strokeStyle = 'rgba(180,210,240,0.55)'; ctx.lineWidth = Math.max(1, H * 0.0018);
        for (var j = 0; j < 90; j++) {
          var x2 = C.noise1(200 + j, 4) * W;
          var y2 = ((t * (1400 + j * 30) + j * 300) % (H * 1.2)) - H * 0.1;
          ctx.beginPath(); ctx.moveTo(x2, y2); ctx.lineTo(x2 - H * 0.02, y2 + H * 0.075); ctx.stroke();
        }
        ctx.globalAlpha = 1; break;
      case 'shatter':
        ctx.globalAlpha = (1 - p) * amount; ctx.fillStyle = color;
        for (var k = 0; k < 26; k++) {
          var ang = C.noise1(61 + k, 3) * Math.PI * 2;
          var spd = 0.25 + C.noise1(71 + k, 4) * 0.75;
          var dd = C.ease('outQuad', p) * spd;
          var rr = H * 0.05;
          var px = W * (fx.x === undefined ? 0.5 : fx.x) + Math.cos(ang) * dd * W * 0.5;
          var py = H * (fx.y === undefined ? 0.5 : fx.y) + Math.sin(ang) * dd * H * 0.5 - p * p * H * 0.15;
          ctx.save(); ctx.translate(px, py); ctx.rotate(ang + p * 6);
          ctx.fillRect(-rr * 0.16, -rr * 0.16, rr * 0.32, rr * 0.32);
          ctx.restore();
        }
        ctx.globalAlpha = 1; break;
      case 'heartbeat': case 'vignettePulse': {
        var beat = Math.pow(0.5 + 0.5 * Math.sin(t * (fx.hz || 1.1) * Math.PI * 2), 3);
        var rg3 = ctx.createRadialGradient(W / 2, H / 2, H * 0.12, W / 2, H / 2, H * 0.82);
        rg3.addColorStop(0, 'rgba(0,0,0,0)'); rg3.addColorStop(1, fadeA(color, 1));
        ctx.globalAlpha = C.clamp(0.12 + beat * amount, 0, 1);
        ctx.fillStyle = rg3; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1; break;
      }
      case 'textCallout': {
        var label = fx.text || 'WARNING';
        var size = H * (fx.size || 0.045);
        var cxx = W * (fx.x === undefined ? 0.5 : fx.x), cyy = H * (fx.y === undefined ? 0.3 : fx.y);
        var appear = C.ease('outBack', C.clamp(p * 3, 0, 1));
        var vanish = p > 0.82 ? 1 - (p - 0.82) / 0.18 : 1;
        ctx.save();
        ctx.globalAlpha = C.clamp(appear * vanish, 0, 1);
        ctx.translate(cxx, cyy); ctx.scale(appear, appear);
        ctx.font = 'bold ' + size + 'px ' + THEME.fontFamily;
        ctx.textAlign = 'center';
        var tw = ctx.measureText(label).width + size * 1.0;
        ctx.fillStyle = 'rgba(4,6,10,0.75)';
        roundRectPath(ctx, -tw / 2, -size * 0.95, tw, size * 1.5, size * 0.18); ctx.fill();
        ctx.strokeStyle = fadeA(color, 1); ctx.lineWidth = Math.max(1, size * 0.06);
        roundRectPath(ctx, -tw / 2, -size * 0.95, tw, size * 1.5, size * 0.18); ctx.stroke();
        ctx.fillStyle = fadeA(color, 1);
        ctx.fillText(label, 0, size * 0.2);
        ctx.restore(); ctx.globalAlpha = 1; break;
      }
      default: break;
    }
  }

  function drawGrain(ctx, W, H, frameIndex, amount) {
    var n = Math.round(W * H * 0.00030 * (amount === undefined ? 1 : amount));
    var rnd = C.mulberry32((0x9e3779b9 ^ Math.imul(frameIndex + 1, 2654435761)) >>> 0);
    ctx.save();
    ctx.globalAlpha = 0.045;
    ctx.fillStyle = '#ffffff';
    for (var i = 0; i < n; i++) {
      var x = rnd() * W, y = rnd() * H, s = rnd() < 0.85 ? 1 : 2;
      ctx.fillRect(x, y, s, s);
    }
    ctx.restore();
  }

  function drawVignette(ctx, W, H, strength) {
    var rg = ctx.createRadialGradient(W / 2, H / 2, H * 0.30, W / 2, H / 2, H * 0.98);
    rg.addColorStop(0, 'rgba(0,0,0,0)');
    rg.addColorStop(1, 'rgba(0,0,0,' + (strength === undefined ? 0.5 : strength) + ')');
    ctx.fillStyle = rg; ctx.fillRect(0, 0, W, H);
  }

  function drawBars(ctx, W, H, amt) {
    var bh = H * 0.12 * C.clamp(amt, 0, 1);
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, W, bh);
    ctx.fillRect(0, H - bh, W, bh);
  }

  // ===========================================================================
  // Scene + timeline rendering
  // ===========================================================================
  function actorPlacedState(actor, t, tr) {
    var anim = clipAt(actor, t);
    return {
      anim: anim,
      facing: trackValue(actor.facingTrack, t, actor.facing === undefined ? 1 : actor.facing),
      x: trackValue(actor.xTrack, t, actor.x === undefined ? STAGE_W / 2 : actor.x),
      y: trackValue(actor.yTrack, t, actor.y === undefined ? 1.35 : actor.y),
      opacity: C.clamp(trackValue(actor.opacityTrack, t, actor.opacity === undefined ? 1 : actor.opacity), 0, 1),
      scale: trackValue(actor.scaleTrack, t, actor.scale === undefined ? 1 : actor.scale),
      rot: trackValue(actor.rotTrack, t, actor.rot || 0)
    };
  }

  function drawPropAt(ctx, prop, t, tr, W, H) {
    var wx = trackValue(prop.xTrack, t, prop.x === undefined ? STAGE_W / 2 : prop.x);
    var wy = trackValue(prop.yTrack, t, prop.y === undefined ? 1.35 : prop.y);
    var p = tr.toScreen(wx, wy);
    var unit = (prop.scale === undefined ? 1 : prop.scale) * tr.ppu * trackValue(prop.scaleTrack, t, 1);
    ctx.save();
    ctx.translate(p.x, p.y);
    if (prop.rot) ctx.rotate(-prop.rot * D2R);
    ctx.scale(unit, unit);
    var g = gfx(ctx, unit, W, H);
    var fn = PROPS[prop.type];
    if (fn) {
      fn(g, {
        t: t, w: prop.w, h: prop.h, h0: prop.h0, count: prop.count, steps: prop.steps,
        on: prop.on, backH: prop.backH, time: prop.time, ringing: prop.ringing,
        timeColor: prop.timeColor, lines: prop.lines, size: prop.size
      });
    }
    ctx.restore();
  }

  function drawHeldProp(ctx, at, handPt, handAngleDeg, unit, W, H, t) {
    ctx.save();
    ctx.translate(handPt.x, handPt.y);
    var baseAng = at.angleOffset === undefined ? 0 : at.angleOffset;
    if (at.worldAngle) {
      // keep the prop's own orientation (with an optional fixed tilt) instead of
      // following the forearm — used for dumbbells, bottles and trays
      ctx.rotate(baseAng * D2R);
    } else {
      // handAngle is a y-up skeleton angle; screen space is y-down and mirrors with facing
      ctx.rotate((-(handAngleDeg) - 90 + baseAng) * D2R);
    }
    ctx.scale(unit, unit);
    var g = gfx(ctx, unit, W, H);
    var fn = PROPS[at.type] || PROPS.dumbbell;
    fn(g, { t: t, on: at.on !== false, w: at.w, h: at.h, count: at.count, lines: at.lines, size: at.size });
    ctx.restore();
  }

  function renderScene(ctx, project, sceneIndex, t, opts) {
    opts = opts || {};
    var W = opts.W || 1920, H = opts.H || 1080;
    var scene = project.scenes[sceneIndex];
    if (!scene) return;
    var ga = opts.sceneAlpha === undefined ? 1 : C.clamp(opts.sceneAlpha, 0, 1);
    var tr = makeTransform(W, H, cameraAt(scene, t));
    var dur = scene.duration || 3;

    ctx.save();
    ctx.fillStyle = THEME.bg;
    ctx.fillRect(0, 0, W, H);
    ctx.globalAlpha = ga;

    // background (world space)
    ctx.save();
    tr.apply(ctx);
    var bgG = gfx(ctx, tr.ppu, W, H);
    (BACKGROUNDS[scene.background] || BACKGROUNDS.void)(bgG, { t: t, seed: scene.seed || 1, horizon: scene.horizon });
    ctx.restore();

    // props behind actors
    ctx.globalAlpha = ga;
    (scene.props || []).forEach(function (prop) {
      if ((prop.layer || 'front') !== 'back') return;
      ctx.globalAlpha = ga * C.clamp(trackValue(prop.opacityTrack, t, prop.opacity === undefined ? 1 : prop.opacity), 0, 1);
      drawPropAt(ctx, prop, t, tr, W, H);
    });
    ctx.globalAlpha = ga;

    // actors
    var list = (scene.actors || []).map(function (a) { return { a: a, s: actorPlacedState(a, t, tr) }; });
    list.sort(function (x, y) { return x.s.y - y.s.y; });

    // mirror reflections: draw a mirrored copy of the actors clipped to the glass
    (scene.props || []).forEach(function (prop) {
      if (prop.type !== 'mirror' || prop.reflect === false) return;
      var wx = trackValue(prop.xTrack, t, prop.x === undefined ? 5 : prop.x);
      var wy = trackValue(prop.yTrack, t, prop.y === undefined ? 1.35 : prop.y);
      var c = tr.toScreen(wx, wy);
      var unit = (prop.scale === undefined ? 1 : prop.scale) * tr.ppu;
      var w = (prop.w || 1.6) * unit, h = (prop.h || 2.5) * unit;
      var x0 = c.x - w / 2, y0 = c.y - (h + 0.18) * unit, x1 = c.x + w / 2, y1 = c.y - 0.18 * unit;
      ctx.save();
      ctx.beginPath();
      ctx.rect(x0 + 0.03 * unit, y0 + 0.03 * unit, (w - 0.06 * unit) * 0.98, h - 0.06 * unit);
      ctx.clip();
      var cx = (x0 + x1) / 2;
      ctx.translate(cx, 0); ctx.scale(-1, 1); ctx.translate(-cx, 0);
      ctx.globalAlpha = ga * (prop.reflectOpacity === undefined ? 0.34 : prop.reflectOpacity);
      list.forEach(function (item) {
        var actor = item.a, st = item.s;
        var sk = buildSkeleton(st.anim.pose);
        var p = tr.toScreen(st.x, st.y);
        var aunit = tr.ppu * (actor.size === undefined ? 1 : actor.size);
        drawActor(ctx, actor, sk, {
          x: p.x, y: p.y, size: aunit, facing: -st.facing,
          ink: actor.color || THEME.ink, dim: actor.colorDim || THEME.inkDim,
          opacity: st.opacity, farAlpha: actor.farAlpha
        });
      });
      ctx.restore();
      // cold glass tint + vertical fade so the reflection reads as a reflection
      ctx.save();
      ctx.beginPath(); ctx.rect(x0, y0, w, h); ctx.clip();
      var vg = ctx.createLinearGradient(0, y1, 0, y0);
      vg.addColorStop(0, 'rgba(6,10,16,0.86)');
      vg.addColorStop(0.55, 'rgba(8,14,22,0.55)');
      vg.addColorStop(1, 'rgba(10,16,24,0.30)');
      ctx.fillStyle = vg; ctx.fillRect(x0, y0, w, h);
      var hg = ctx.createLinearGradient(x0, 0, x1, 0);
      hg.addColorStop(0, 'rgba(150,190,230,0.05)');
      hg.addColorStop(0.5, 'rgba(150,190,230,0.11)');
      hg.addColorStop(1, 'rgba(150,190,230,0.05)');
      ctx.fillStyle = hg; ctx.fillRect(x0, y0, w, h);
      ctx.restore();
      ctx.globalAlpha = 1;
    });

    list.forEach(function (item) {
      var actor = item.a, st = item.s;
      var sk = buildSkeleton(st.anim.pose);
      var p = tr.toScreen(st.x, st.y);
      var unit = tr.ppu * (actor.size === undefined ? 1 : actor.size);
      ctx.save();
      ctx.translate(p.x, p.y);
      if (st.rot) ctx.rotate(st.rot * D2R);
      ctx.translate(-p.x, -p.y);
      var J = drawActor(ctx, actor, sk, {
        x: p.x, y: p.y, size: unit, facing: st.facing,
        ink: actor.color || THEME.ink, dim: actor.colorDim || THEME.inkDim,
        opacity: st.opacity * ga, farAlpha: actor.farAlpha
      });
      (actor.attach || []).forEach(function (at) {
        if (at.from !== undefined && t < at.from) return;
        if (at.to !== undefined && t > at.to) return;
        var onLeft = at.hand === 'left';
        var handPt = onLeft ? J.handL : J.handR;
        var ang = onLeft ? sk.handLAng : sk.handRAng;
        var flip = st.facing < 0 ? -1 : 1;
        if (flip < 0) {
          // mirror the mount point around the actor's vertical axis
          handPt = { x: p.x - (handPt.x - p.x), y: handPt.y };
          ang = 180 - ang;
        }
        drawHeldProp(ctx, at, handPt, ang, (at.scale === undefined ? 1 : at.scale) * unit * sk.scale, W, H, t);
      });
      ctx.restore();
    });

    // props in front of actors
    (scene.props || []).forEach(function (prop) {
      if ((prop.layer || 'front') !== 'front') return;
      ctx.globalAlpha = ga * C.clamp(trackValue(prop.opacityTrack, t, prop.opacity === undefined ? 1 : prop.opacity), 0, 1);
      drawPropAt(ctx, prop, t, tr, W, H);
    });
    ctx.globalAlpha = ga;

    // in-world lights
    (scene.lights || []).forEach(function (L) {
      var pL = tr.toScreen(L.x, L.y);
      var kind = L.type || 'spot';
      var color = L.color || 'rgba(255,235,205,1)';
      var intensity = C.clamp(trackValue(L.intensityTrack, t, L.intensity === undefined ? 1 : L.intensity), 0, 4);
      if (kind === 'spot') {
        var r = (L.r || 3) * tr.ppu;
        var rg = ctx.createRadialGradient(pL.x, pL.y, 0, pL.x, pL.y, Math.max(1, r));
        rg.addColorStop(0, fadeA(color, C.round(0.45 * intensity, 3)));
        rg.addColorStop(0.45, fadeA(color, C.round(0.13 * intensity, 3)));
        rg.addColorStop(1, fadeA(color, 0));
        ctx.fillStyle = rg;
        ctx.beginPath(); ctx.arc(pL.x, pL.y, r, 0, Math.PI * 2); ctx.fill();
      } else if (kind === 'godray') {
        var rays = L.rays || 4, len = (L.len || 9) * tr.ppu, wdt = (L.w || 0.9) * tr.ppu;
        for (var i = 0; i < rays; i++) {
          var a = (L.angle === undefined ? -62 : L.angle) * D2R + (i - (rays - 1) / 2) * ((L.spread || 6) * D2R);
          ctx.save();
          ctx.translate(pL.x, pL.y);
          ctx.rotate(a + Math.sin(t * 0.5 + i) * 0.02);
          var lg = ctx.createLinearGradient(0, 0, 0, len);
          lg.addColorStop(0, fadeA(color, C.round(0.14 * intensity, 3)));
          lg.addColorStop(1, fadeA(color, 0));
          ctx.fillStyle = lg;
          ctx.beginPath();
          ctx.moveTo(-wdt * 0.16, 0); ctx.lineTo(wdt * 0.16, 0);
          ctx.lineTo(wdt * 0.6, len); ctx.lineTo(-wdt * 0.6, len);
          ctx.closePath(); ctx.fill();
          ctx.restore();
        }
      } else if (kind === 'flicker') {
        var f = 0.75 + 0.25 * C.noise1(L.seed || 3, t * 26) - (C.noise1((L.seed || 3) + 9, t * 3) > 0.94 ? 0.4 : 0);
        var r2 = (L.r || 4) * tr.ppu;
        var rg2 = ctx.createRadialGradient(pL.x, pL.y, 0, pL.x, pL.y, Math.max(1, r2));
        rg2.addColorStop(0, fadeA(color, C.round(0.3 * f * intensity, 3)));
        rg2.addColorStop(1, fadeA(color, 0));
        ctx.fillStyle = rg2; ctx.fillRect(0, 0, W, H);
      }
    });
    ctx.restore();

    // screen-space text (not affected by scene alpha so fades stay crisp)
    ctx.save();
    ctx.globalAlpha = ga;
    (scene.texts || []).forEach(function (txt) {
      var start = txt.start || 0;
      var end = txt.end === undefined ? dur : txt.end;
      if (t < start || t > end + 0.4) return;
      var tt = t - start;
      var len = Math.max(0.05, end - start);
      var inDur = txt.animDuration === undefined ? Math.min(0.9, Math.max(0.25, len * 0.5)) : txt.animDuration;
      var progress = inDur <= 0 ? 1 : C.clamp(tt / inDur, 0, 1);
      var fadeOut = txt.fadeOut === undefined ? 0.35 : txt.fadeOut;
      var outAlpha = fadeOut <= 0 ? 1 : (tt > len - fadeOut ? C.clamp((len - tt) / fadeOut, 0, 1) : 1);
      ctx.globalAlpha = ga * outAlpha;
      drawTextBlock(ctx, {
        text: txt.text, W: W, H: H, progress: progress, t: t, fontFamily: opts.fontFamily,
        style: Object.assign({}, txt, { fontFamily: txt.fontFamily || opts.fontFamily })
      });
    });
    ctx.restore();
  }

  function sceneAt(project, globalTime) {
    var scenes = project.scenes || [];
    var acc = 0;
    for (var i = 0; i < scenes.length; i++) {
      var d = scenes[i].duration || 3;
      if (globalTime < acc + d || i === scenes.length - 1) {
        return { index: i, local: C.clamp(globalTime - acc, 0, d), duration: d, globalStart: acc, globalEnd: acc + d };
      }
      acc += d;
    }
    return { index: 0, local: 0, duration: 3, globalStart: 0, globalEnd: 3 };
  }
  function totalDuration(project) {
    return (project.scenes || []).reduce(function (a, s) { return a + (s.duration || 3); }, 0);
  }

  function transitionDuration(scene) {
    if (!scene) return 0;
    var tIn = scene.transitionIn;
    if (!tIn || !tIn.type || tIn.type === 'cut') return 0;
    return tIn.duration === undefined ? (tIn.type === 'barsOut' ? 0.5 : 0.45) : tIn.duration;
  }

  function renderFrame(ctx, project, globalTime, opts) {
    opts = opts || {};
    var W = opts.W || 1920, H = opts.H || 1080;
    var at = sceneAt(project, globalTime);
    var scene = project.scenes[at.index];
    if (!scene) { ctx.fillStyle = '#000'; ctx.fillRect(0, 0, W, H); return; }
    var t = at.local, dur = scene.duration || 3;
    var tIn = scene.transitionIn;
    var inDur = transitionDuration(scene);

    ctx.save();
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, W, H);

    if (tIn && tIn.type === 'dissolve' && at.index > 0 && t < inDur) {
      var prev = at.index - 1;
      var p = C.clamp(t / Math.max(0.01, inDur), 0, 1);
      renderScene(ctx, project, prev, project.scenes[prev].duration || 3, { W: W, H: H, fontFamily: opts.fontFamily, sceneAlpha: 1 });
      renderScene(ctx, project, at.index, t, { W: W, H: H, fontFamily: opts.fontFamily, sceneAlpha: p });
    } else {
      var enterAlpha = 1;
      if (inDur > 0 && t < inDur) {
        enterAlpha = C.clamp(t / inDur, 0, 1);
        if (tIn.type === 'fadeFromBlack' || tIn.type === 'fade' || tIn.type === 'barsOut') enterAlpha = C.ease('outQuad', enterAlpha);
      }
      renderScene(ctx, project, at.index, t, { W: W, H: H, fontFamily: opts.fontFamily, sceneAlpha: enterAlpha });
      if (inDur > 0 && t < inDur && tIn.type === 'barsOut') {
        ctx.globalAlpha = 1;
        drawBars(ctx, W, H, 1 - C.ease('outCubic', C.clamp(t / inDur, 0, 1)));
      }
    }

    // scene effects (screen space, above the scene, below transitions)
    ctx.globalAlpha = 1;
    (scene.effects || []).forEach(function (fx) { drawEffect(ctx, fx, W, H, t); });

    // outgoing transition (eats the tail of the scene)
    var tOut = scene.transitionOut || project.defaultTransitionOut;
    if (tOut && tOut.type && tOut.type !== 'cut') {
      var od = tOut.duration === undefined ? 0.4 : tOut.duration;
      if (od > 0 && t > dur - od) {
        var op = C.clamp((t - (dur - od)) / od, 0, 1);
        if (tOut.type === 'fade' || tOut.type === 'dipToBlack') {
          ctx.globalAlpha = C.ease('inQuad', op); ctx.fillStyle = '#000'; ctx.fillRect(0, 0, W, H);
        } else if (tOut.type === 'fadeToWhite') {
          ctx.globalAlpha = C.ease('inQuad', op); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
        } else if (tOut.type === 'fadeToRed') {
          ctx.globalAlpha = C.ease('inQuad', op); ctx.fillStyle = C.toCss(C.hex(THEME.red)); ctx.fillRect(0, 0, W, H);
        } else if (tOut.type === 'wipeLeft' || tOut.type === 'wipeRight' || tOut.type === 'wipeUp' || tOut.type === 'wipeDown') {
          var e = C.ease('inOutCubic', op);
          ctx.fillStyle = '#000';
          if (tOut.type === 'wipeLeft') ctx.fillRect(0, 0, W * e, H);
          else if (tOut.type === 'wipeRight') ctx.fillRect(W - W * e, 0, W * e, H);
          else if (tOut.type === 'wipeUp') ctx.fillRect(0, 0, W, H * e);
          else ctx.fillRect(0, H - H * e, W, H * e);
        } else if (tOut.type === 'flash') {
          ctx.globalAlpha = Math.sin(Math.PI * op) * 0.95; ctx.fillStyle = tOut.color || '#fff'; ctx.fillRect(0, 0, W, H);
        } else if (tOut.type === 'barsIn') {
          drawBars(ctx, W, H, C.ease('inOutCubic', op));
        }
        ctx.globalAlpha = 1;
      }
    }
    ctx.restore();

    // global look
    var look = project.look || {};
    if (look.vignette !== false) drawVignette(ctx, W, H, look.vignetteStrength === undefined ? 0.5 : look.vignetteStrength);
    if (look.grain && opts.grain !== false) drawGrain(ctx, W, H, Math.round(globalTime * (project.fps || 30)), 1);
    if (look.letterbox) drawBars(ctx, W, H, look.letterbox);
    if (project.watermark && project.watermark.text) {
      ctx.save();
      ctx.globalAlpha = project.watermark.opacity === undefined ? 0.45 : project.watermark.opacity;
      ctx.font = 'bold ' + Math.round(H * 0.02) + 'px ' + (opts.fontFamily || THEME.fontFamily);
      ctx.fillStyle = '#fff'; ctx.textAlign = 'right';
      ctx.fillText(project.watermark.text, W - H * 0.03, H - H * 0.028);
      ctx.restore();
    }
  }

  return {
    THEME: THEME, SEG: SEG, STAGE_W: STAGE_W, STAGE_H: STAGE_H, LEG_LEN: LEG_LEN, D2R: D2R,
    ACTIONS: ACTIONS, ACTION_IDS: ACTION_IDS, ACTION_LOOP: ACTION_LOOP, ACTION_PERIOD: ACTION_PERIOD,
    PROP_LIST: PROP_LIST, BG_LIST: BG_LIST, FX_LIST: FX_LIST, PROP_SURFACE: PROP_SURFACE,
    PROPS: PROPS, BACKGROUNDS: BACKGROUNDS, POSE_FNS: POSE_FNS, BASE_POSES: BASE_POSES,
    blendPose: blendPose, resolvePose: resolvePose, isPose: isPose, buildSkeleton: buildSkeleton,
    clipAt: clipAt, trackValue: trackValue, cameraAt: cameraAt, makeTransform: makeTransform,
    actorPlacedState: actorPlacedState, drawActor: drawActor,
    sceneAt: sceneAt, totalDuration: totalDuration, transitionDuration: transitionDuration,
    renderScene: renderScene, renderFrame: renderFrame,
    drawTextBlock: drawTextBlock, layoutText: layoutText, drawEffect: drawEffect,
    drawGrain: drawGrain, drawVignette: drawVignette, gfx: gfx, roundRectPath: roundRectPath
  };
});
