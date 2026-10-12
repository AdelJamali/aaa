/* Stickman Animation Studio — deterministic audio engine.
 * Everything is synthesised from code: no sample files, no API keys.
 * The exact same function renders the browser preview (Web Audio) and the
 * audio track that FFmpeg muxes into the exported MP4, so the sound matches.
 *
 * Cue types: music, alarmBeeps, roomtone, blanket, chime, boom, heartbeat,
 * riser, whoosh, footsteps, clink, thunder, horn.
 * Narration is intentionally NOT synthesised here: it is an optional uploaded
 * file (see docs) with its own volume, because real TTS needs a paid service.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory(require('./core.js'));
  else { root.SAS = root.SAS || {}; root.SAS.Audio = factory(root.SAS.Core); }
})(typeof globalThis !== 'undefined' ? globalThis : this, function (C) {
  'use strict';

  var SR = 44100;

  function Cue(type, start, dur, gain, opts) {
    return Object.assign({ type: type, start: start, dur: dur, gain: gain === undefined ? 1 : gain, pan: 0 }, opts || {});
  }

  // ---------------------------------------------------------------------------
  // Collect all cues of a project on the absolute timeline
  // ---------------------------------------------------------------------------
  function buildCues(project) {
    var cues = [], acc = 0;
    (project.scenes || []).forEach(function (scene) {
      var d = scene.duration || 3;
      (scene.audio || []).forEach(function (a) {
        if (!a || !a.type) return;
        cues.push(Cue(a.type, acc + (a.at || 0), a.dur === undefined ? d : a.dur, a.gain, a));
      });
      acc += d;
    });
    return cues.sort(function (a, b) { return a.start - b.start; });
  }

  // ---------------------------------------------------------------------------
  // Synth primitives — all write additively into an interleaved-free stereo pair
  // ---------------------------------------------------------------------------
  function mix(buf, ch, i, v) {
    if (i < 0 || i >= buf.length) return;
    buf[i] += v;
  }
  function pan2(cue) {
    var p = C.clamp(cue.pan || 0, -1, 1);
    return [Math.cos((p + 1) * Math.PI / 4), Math.sin((p + 1) * Math.PI / 4)];
  }
  function env(t, dur, a, d, s, r, sus) {
    if (t < 0 || t > dur) return 0;
    if (t < a) return t / Math.max(0.0001, a);
    if (t < a + d) return 1 - (1 - (sus === undefined ? 0.7 : sus)) * ((t - a) / Math.max(0.0001, d));
    if (t < dur - r) return (sus === undefined ? 0.7 : sus);
    return Math.max(0, (sus === undefined ? 0.7 : sus) * (1 - (t - (dur - r)) / Math.max(0.0001, r)));
  }
  function sr(seed) { return C.mulberry32(seed >>> 0); }

  var INSTRUMENTS = {
    alarmBeeps: function (L, R, cue, sr0) {
      // classic digital alarm: pairs of short square blips
      var beepDur = 0.085, gap = 0.075, period = 0.5;
      var t = 0;
      while (t < cue.dur) {
        for (var b = 0; b < 2; b++) {
          var s0 = cue.start + t + b * (beepDur + gap);
          var n = Math.floor(beepDur * sr0);
          for (var i = 0; i < n; i++) {
            var tt = i / sr0;
            var e = env(tt, beepDur, 0.004, 0.02, 0.75, 0.03, 0.75);
            var f = 1750 + Math.sin(tt * 60) * 40;
            var v = Math.sign(Math.sin(2 * Math.PI * f * tt)) * 0.5 + Math.sin(2 * Math.PI * f * 2 * tt) * 0.18;
            var idx = Math.floor(s0 * sr0) + i;
            var g = cue.gain * 0.22 * e;
            mix(L, 0, idx, v * g * 0.9); mix(R, 0, idx, v * g * 1.0);
          }
        }
        t += period;
      }
    },

    roomtone: function (L, R, cue, sr0) {
      var rnd = sr(9182);
      var n = Math.floor(cue.dur * sr0);
      var start = Math.floor(cue.start * sr0);
      var lp = 0, lp2 = 0;
      for (var i = 0; i < n; i++) {
        var w = rnd() * 2 - 1;
        lp += (w - lp) * 0.020; lp2 += (lp - lp2) * 0.05;
        var g = cue.gain * 0.16 * lp2;
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.96);
      }
      // faint 50 Hz hum
      for (var j = 0; j < n; j++) {
        var tt = j / sr0;
        var h = Math.sin(2 * Math.PI * 50 * tt) * 0.02 * cue.gain * env(tt, cue.dur, 0.4, 0.2, 0.9, 0.4);
        mix(L, 0, start + j, h); mix(R, 0, start + j, -h * 0.8);
      }
    },

    blanket: function (L, R, cue, sr0) {
      var rnd = sr(3311);
      var n = Math.floor(cue.dur * sr0), start = Math.floor(cue.start * sr0);
      var lp = 0, hp = 0;
      for (var i = 0; i < n; i++) {
        var tt = i / sr0, u = tt / cue.dur;
        var w = rnd() * 2 - 1;
        lp += (w - lp) * 0.28;
        hp = lp - hp * 0.02;
        var e = Math.sin(Math.PI * C.clamp(u, 0, 1)) * (0.6 + 0.4 * Math.sin(tt * 22));
        var g = cue.gain * 0.20 * e * hp;
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.85);
      }
    },

    chime: function (L, R, cue, sr0) {
      var partials = [1244.5, 1864.7, 2489.0, 3720.5];
      var amps = [1, 0.5, 0.28, 0.14];
      var n = Math.floor(Math.min(cue.dur, 1.6) * sr0), start = Math.floor(cue.start * sr0);
      for (var i = 0; i < n; i++) {
        var tt = i / sr0;
        var v = 0;
        for (var k = 0; k < partials.length; k++) {
          v += Math.sin(2 * Math.PI * partials[k] * tt) * amps[k] * Math.exp(-tt * (2.2 + k * 1.4));
        }
        var g = cue.gain * 0.14 * v;
        mix(L, 0, start + i, g * 0.9); mix(R, 0, start + i, g);
      }
    },

    boom: function (L, R, cue, sr0) {
      // low sine drop + short noise transient + simple room tail
      var rnd = sr(5150);
      var n = Math.floor(Math.min(cue.dur, 3.0) * sr0), start = Math.floor(cue.start * sr0);
      var bufL = new Float32Array(n), bufR = new Float32Array(n);
      for (var i = 0; i < n; i++) {
        var tt = i / sr0, u = tt / Math.max(0.4, cue.dur);
        var f = 92 * Math.pow(0.42, tt * 2.4) + 34;
        var e = Math.exp(-tt * 1.5) * C.clamp(1 - u * 0.4, 0, 1);
        var v = Math.sin(2 * Math.PI * f * tt) * e;
        v += (rnd() * 2 - 1) * Math.exp(-tt * 22) * 0.35;
        bufL[i] = v;
        bufR[i] = v * 0.94;
      }
      // comb tail (cheap reverb)
      var taps = [[0.075, 0.42], [0.145, 0.30], [0.235, 0.22], [0.33, 0.15]];
      for (var t2 = 0; t2 < taps.length; t2++) {
        var off = Math.floor(taps[t2][0] * sr0), g2 = taps[t2][1];
        for (var j = 0; j + off < n; j++) { bufL[j + off] += bufL[j] * g2 * 0.6; bufR[j + off] += bufR[j] * g2 * 0.55; }
      }
      for (var m = 0; m < n; m++) {
        mix(L, 0, start + m, bufL[m] * cue.gain * 0.55);
        mix(R, 0, start + m, bufR[m] * cue.gain * 0.55);
      }
    },

    heartbeat: function (L, R, cue, sr0) {
      var period = 60 / (cue.bpm || 72);
      var t = 0;
      while (t < cue.dur) {
        for (var b = 0; b < 2; b++) {
          var s0 = cue.start + t + b * 0.17;
          var dur = b === 0 ? 0.24 : 0.20;
          var amp = b === 0 ? 1 : 0.72;
          var n = Math.floor(dur * sr0), start = Math.floor(s0 * sr0);
          for (var i = 0; i < n; i++) {
            var tt = i / sr0;
            var f = 58 * Math.pow(0.5, tt * 6) + 26;
            var e = Math.exp(-tt * 9) * env(tt, dur, 0.006, 0.03, 0.6, 0.08, 0.6);
            var v = Math.sin(2 * Math.PI * f * tt) * e;
            var g = cue.gain * 0.5 * amp * v;
            mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.92);
          }
        }
        t += period;
      }
    },

    riser: function (L, R, cue, sr0) {
      var rnd = sr(7777);
      var n = Math.floor(cue.dur * sr0), start = Math.floor(cue.start * sr0);
      var lp = 0;
      for (var i = 0; i < n; i++) {
        var tt = i / sr0, u = tt / cue.dur;
        var w = rnd() * 2 - 1;
        lp += (w - lp) * (0.02 + 0.55 * Math.pow(u, 2.2));
        var e = Math.pow(u, 1.6);
        var tone = Math.sin(2 * Math.PI * (180 + 900 * u * u) * tt) * 0.35 * e;
        var g = cue.gain * 0.24 * (lp * 0.9 + tone);
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.9);
      }
    },

    whoosh: function (L, R, cue, sr0) {
      var rnd = sr(2468);
      var n = Math.floor(cue.dur * sr0), start = Math.floor(cue.start * sr0);
      var lp = 0, lp2 = 0;
      for (var i = 0; i < n; i++) {
        var tt = i / sr0, u = tt / cue.dur;
        var w = rnd() * 2 - 1;
        var cut = 0.03 + 0.5 * Math.sin(Math.PI * C.clamp(u, 0, 1));
        lp += (w - lp) * cut; lp2 += (lp - lp2) * cut;
        var e = Math.sin(Math.PI * C.clamp(u, 0, 1));
        var g = cue.gain * 0.30 * e * lp2 * 2.2;
        mix(L, 0, start + i, g * (1 - u * 0.3)); mix(R, 0, start + i, g * 0.9);
      }
    },

    footsteps: function (L, R, cue, sr0) {
      var bpm = cue.bpm || 104;
      var period = 60 / bpm;
      var rnd = sr(1357);
      var t = 0;
      while (t < cue.dur) {
        var s0 = cue.start + t;
        var n = Math.floor(0.11 * sr0), start = Math.floor(s0 * sr0);
        var lp = 0;
        for (var i = 0; i < n; i++) {
          var tt = i / sr0;
          var w = rnd() * 2 - 1;
          lp += (w - lp) * 0.35;
          var e = Math.exp(-tt * 34);
          var thud = Math.sin(2 * Math.PI * 78 * tt) * Math.exp(-tt * 26) * 0.5;
          var g = cue.gain * 0.30 * (lp * 0.5 + thud) * e;
          mix(L, 0, start + i, g * (i % 2 ? 0.9 : 1)); mix(R, 0, start + i, g * (i % 2 ? 1 : 0.9));
        }
        t += period;
      }
    },

    clink: function (L, R, cue, sr0) {
      var partials = [2140, 3180, 4620, 5880];
      var n = Math.floor(Math.min(cue.dur, 0.6) * sr0), start = Math.floor(cue.start * sr0);
      for (var i = 0; i < n; i++) {
        var tt = i / sr0;
        var v = 0;
        for (var k = 0; k < partials.length; k++) v += Math.sin(2 * Math.PI * partials[k] * tt) * Math.exp(-tt * (12 + k * 6)) / (k + 1);
        var g = cue.gain * 0.16 * v;
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.88);
      }
    },

    thunder: function (L, R, cue, sr0) {
      var rnd = sr(6060);
      var n = Math.floor(cue.dur * sr0), start = Math.floor(cue.start * sr0);
      var lp = 0, lp2 = 0;
      for (var i = 0; i < n; i++) {
        var tt = i / sr0;
        var w = rnd() * 2 - 1;
        lp += (w - lp) * 0.035; lp2 += (lp - lp2) * 0.035;
        var e = Math.exp(-tt * 0.7) * (1 - Math.exp(-tt * 6));
        var g = cue.gain * 0.5 * lp2 * e * 4;
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.95);
      }
    },

    horn: function (L, R, cue, sr0) {
      var n = Math.floor(cue.dur * sr0), start = Math.floor(cue.start * sr0);
      var f = cue.freq || 110;
      for (var i = 0; i < n; i++) {
        var tt = i / sr0;
        var e = env(tt, cue.dur, 0.03, 0.08, 0.75, 0.25, 0.75);
        var v = Math.sin(2 * Math.PI * f * tt) * 0.5 + Math.sin(2 * Math.PI * f * 2 * tt) * 0.22 + Math.sin(2 * Math.PI * f * 3 * tt) * 0.1;
        var g = cue.gain * 0.22 * e * v;
        mix(L, 0, start + i, g); mix(R, 0, start + i, g * 0.95);
      }
    },

    music: function (L, R, cue, sr0) {
      // dark motivational pulse: kick, sub bass, hats, minor pad
      var bpm = cue.bpm || 92;
      var beat = 60 / bpm;
      var rnd = sr(4242);
      var startSample = Math.floor(cue.start * sr0);
      var total = Math.floor(cue.dur * sr0);

      function add(i, l, r) { mix(L, 0, startSample + i, l); mix(R, 0, startSample + i, r); }

      // pad: A minor (A2, C3, E3) + E4 shimmer, slow swell
      var padF = [110, 130.81, 164.81, 329.63];
      var padA = [0.16, 0.12, 0.10, 0.045];

      for (var i = 0; i < total; i++) {
        var tt = i / sr0;
        var u = tt / cue.dur;
        var padE = Math.min(1, tt / 0.6) * (1 - Math.max(0, (u - 0.86) / 0.14));
        var v = 0;
        for (var k = 0; k < padF.length; k++) {
          var det = 1 + (k % 2 ? 0.0025 : -0.002);
          v += Math.sin(2 * Math.PI * padF[k] * det * tt) * padA[k] * padE;
        }
        var lfo = 0.9 + 0.1 * Math.sin(2 * Math.PI * 0.21 * tt);
        add(i, v * lfo, v * lfo * 0.96);
      }

      // drums
      var beats = Math.ceil(cue.dur / beat);
      for (var b = 0; b < beats; b++) {
        var s0 = b * beat;
        if ((b % 4) === 0 || (b % 4) === 2) {
          var n = Math.floor(0.26 * sr0), st = Math.floor(s0 * sr0);
          for (var d = 0; d < n; d++) {
            var td = d / sr0;
            var f = 118 * Math.pow(0.4, td * 7) + 42;
            var e = Math.exp(-td * 10);
            var vv = Math.sin(2 * Math.PI * f * td) * e * 0.85;
            add(st + d, vv * cue.gain * 0.5, vv * cue.gain * 0.48);
          }
        }
        // hats on the off-beat eighths
        for (var hh = 0; hh < 2; hh++) {
          var hs = s0 + hh * beat + beat * 0.5;
          if (hs > cue.dur - 0.05) continue;
          var hn = Math.floor(0.05 * sr0), hst = Math.floor(hs * sr0);
          for (var q = 0; q < hn; q++) {
            var tq = q / sr0;
            var w = rnd() * 2 - 1;
            var e2 = Math.exp(-tq * 90) * 0.55;
            add(hst + q, w * e2 * cue.gain * 0.10, w * e2 * cue.gain * 0.11);
          }
        }
        // sub bass root/fifth every bar
        if ((b % 2) === 0) {
          var notes = [55, 41.2, 61.74, 41.2];
          var f2 = notes[(Math.floor(b / 2)) % notes.length];
          var bn = Math.floor(beat * 1.6 * sr0), bst = Math.floor(s0 * sr0);
          for (var z = 0; z < bn; z++) {
            var tz = z / sr0;
            var ez = Math.min(1, tz / 0.02) * Math.exp(-tz * 1.4);
            var vz = Math.sin(2 * Math.PI * f2 * tz) * ez * 0.5;
            add(bst + z, vz * cue.gain * 0.42, vz * cue.gain * 0.42);
          }
        }
      }
    }
  };

  function renderPCM(project, sampleRate, channels) {
    sampleRate = sampleRate || SR;
    channels = channels || 2;
    var dur = 0;
    (project.scenes || []).forEach(function (s) { dur += (s.duration || 3); });
    var n = Math.max(1, Math.ceil(dur * sampleRate));
    var L = new Float32Array(n), Rb = new Float32Array(n);
    var cues = buildCues(project);
    var audio = project.audio || {};
    var hasAny = false;

    cues.forEach(function (cue) {
      var fn = INSTRUMENTS[cue.type];
      if (!fn) return;
      var gain = (cue.gain === undefined ? 1 : cue.gain);
      if (cue.type === 'music') gain *= (audio.muteMusic ? 0 : (audio.music === undefined ? 0.7 : audio.music));
      else gain *= (audio.muteSfx ? 0 : (audio.sfx === undefined ? 0.85 : audio.sfx));
      fn(L, Rb, Object.assign({}, cue, { gain: gain }), sampleRate);
      if (gain > 0) hasAny = true;
    });

    if (!hasAny) return { left: null, right: null, sampleRate: sampleRate, duration: dur, empty: true };

    var master = audio.master === undefined ? 0.9 : audio.master;
    for (var i = 0; i < n; i++) {
      var l = L[i] * master, r = Rb[i] * master;
      // soft clip
      l = Math.tanh(l * 1.05) * 0.98;
      r = Math.tanh(r * 1.05) * 0.98;
      L[i] = l; Rb[i] = r;
    }
    return { left: L, right: Rb, sampleRate: sampleRate, duration: dur, empty: false };
  }

  function toWav(pcm) {
    var n = pcm.left.length, ch = 2, sr = pcm.sampleRate;
    var dataBytes = n * ch * 2;
    var buf = Buffer.alloc(44 + dataBytes);
    buf.write('RIFF', 0, 'ascii');
    buf.writeUInt32LE(36 + dataBytes, 4);
    buf.write('WAVE', 8, 'ascii');
    buf.write('fmt ', 12, 'ascii');
    buf.writeUInt32LE(16, 16);
    buf.writeUInt16LE(1, 20);
    buf.writeUInt16LE(ch, 22);
    buf.writeUInt32LE(sr, 24);
    buf.writeUInt32LE(sr * ch * 2, 28);
    buf.writeUInt16LE(ch * 2, 32);
    buf.writeUInt16LE(16, 34);
    buf.write('data', 36, 'ascii');
    buf.writeUInt32LE(dataBytes, 40);
    var off = 44;
    for (var i = 0; i < n; i++) {
      var l = C.clamp(pcm.left[i], -1, 1), r = C.clamp(pcm.right[i], -1, 1);
      buf.writeInt16LE(Math.round(l * 32767), off); off += 2;
      buf.writeInt16LE(Math.round(r * 32767), off); off += 2;
    }
    return buf;
  }

  var CUE_TYPES = ['alarmBeeps', 'roomtone', 'blanket', 'chime', 'boom', 'heartbeat', 'riser', 'whoosh', 'footsteps', 'clink', 'thunder', 'horn', 'music'];

  return {
    SR: SR, CUE_TYPES: CUE_TYPES, INSTRUMENTS: INSTRUMENTS,
    buildCues: buildCues, renderPCM: renderPCM, toWav: toWav
  };
});
