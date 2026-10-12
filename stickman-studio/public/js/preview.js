/* Preview player: draws the shared deterministic renderer into a canvas and
 * keeps an <audio> element (the server-rendered mix of the very same cues) in sync.
 */
(function (root) {
  'use strict';
  var C = root.SAS.Core, R = root.SAS.Render;

  function Preview(canvas, opts) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d', { alpha: false });
    this.W = canvas.width;
    this.H = canvas.height;
    this.project = null;
    this.time = 0;
    this.playing = false;
    this.rate = 1;
    this.lastFrameTime = 0;
    this.audio = opts && opts.audio ? opts.audio : null;   // HTMLAudioElement
    this.audioEnabled = true;
    this.onTime = null;
    this.onEnded = null;
    this._raf = null;
    this._dpr = Math.min(2, window.devicePixelRatio || 1);
    this.quality = 'medium';
    this._looping = false;
  }

  Preview.QUALITY = { low: 640, medium: 960, high: 1280 };

  Preview.prototype.setQuality = function (q) {
    if (!Preview.QUALITY[q]) q = 'medium';
    this.quality = q;
    var w = Math.round(Preview.QUALITY[q]);
    var h = Math.round(w * 9 / 16);
    if (this.canvas.width !== w) {
      this.canvas.width = w; this.canvas.height = h;
      this.W = w; this.H = h;
      this.render();
    }
  };

  Preview.prototype.setProject = function (project) {
    this.project = project;
    var total = R.totalDuration(project);
    if (this.time > total) this.time = 0;
    this.render();
  };

  Preview.prototype.duration = function () {
    return this.project ? R.totalDuration(this.project) : 0;
  };

  Preview.prototype.render = function () {
    if (!this.project) return;
    var ctx = this.ctx;
    ctx.save();
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, this.W, this.H);
    R.renderFrame(ctx, this.project, this.time, { W: this.W, H: this.H, fontFamily: 'DejaVu Sans', grain: true });
    ctx.restore();
  };

  Preview.prototype.seek = function (t, opts) {
    var d = this.duration();
    this.time = C.clamp(t, 0, d);
    this.render();
    if (this.audio && this.audioEnabled) {
      try {
        if (Math.abs(this.audio.currentTime - this.time) > 0.08) this.audio.currentTime = this.time;
      } catch (e) { /* not seekable yet */ }
    }
    if (this.onTime) this.onTime(this.time, d);
    void opts;
  };

  Preview.prototype.play = function () {
    if (!this.project) return;
    var d = this.duration();
    if (this.time >= d - 0.001) this.time = 0;
    this.playing = true;
    this.lastFrameTime = performance.now();
    if (this.audio && this.audioEnabled) {
      try { this.audio.currentTime = this.time; } catch (e) { }
      var p = this.audio.play();
      if (p && p.catch) p.catch(function () { });
    }
    var self = this;
    var tick = function (now) {
      if (!self.playing) return;
      var dt = (now - self.lastFrameTime) / 1000;
      self.lastFrameTime = now;
      dt = Math.min(dt, 0.25) * self.rate;
      self.time += dt;
      var total = self.duration();
      var ended = false;
      if (self.time >= total) {
        self.time = total;
        self.playing = false;
        ended = true;
      }
      self.render();
      if (self.audio && self.audioEnabled && !ended) {
        var drift = self.audio.currentTime - self.time;
        if (Math.abs(drift) > 0.18) { try { self.audio.currentTime = self.time; } catch (e) { } }
        else if (Math.abs(drift) > 0.05) { self.audio.playbackRate = C.clamp(1 - drift * 0.5, 0.95, 1.05); }
        else self.audio.playbackRate = 1;
      }
      if (self.onTime) self.onTime(self.time, total);
      if (ended) { self.stopAudio(); if (self.onEnded) self.onEnded(); return; }
      self._raf = requestAnimationFrame(tick);
    };
    this._raf = requestAnimationFrame(tick);
  };

  Preview.prototype.pause = function () {
    this.playing = false;
    if (this._raf) cancelAnimationFrame(this._raf);
    this._raf = null;
    this.stopAudio();
    if (this.audio && this.audioEnabled) { try { this.audio.pause(); } catch (e) { } }
  };

  Preview.prototype.stopAudio = function () {
    if (this.audio) { try { this.audio.pause(); } catch (e) { } }
  };

  Preview.prototype.toggle = function () { this.playing ? this.pause() : this.play(); };

  Preview.prototype.setAudioEnabled = function (on) {
    this.audioEnabled = on;
    if (!on) this.stopAudio();
    else if (this.playing && this.audio) { try { this.audio.currentTime = this.time; this.audio.play(); } catch (e) { } }
  };

  Preview.prototype.setAudioSource = function (url) {
    if (!this.audio) return;
    this.audio.src = url;
    try { this.audio.load(); } catch (e) { }
  };

  root.SAS.Preview = Preview;
})(window);
