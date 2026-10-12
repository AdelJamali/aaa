/* Stickman Animation Studio — application controller (mobile first).
 * Keeps one editable project object, wires every editor to it, drives the
 * preview and runs the real server-side MP4 export.
 */
(function () {
  'use strict';
  var C = window.SAS.Core, R = window.SAS.Render, Story = window.SAS.Story, A = window.SAS.Audio;

  var $ = function (sel) { return document.querySelector(sel); };
  var $$ = function (sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); };

  var state = {
    project: null,
    sceneIndex: 0,
    serverFile: null,
    audioUrl: null,
    narration: { uploaded: false, volume: 1, offset: 0 },
    jobId: null,
    jobTimer: null,
    health: null,
    dirty: false
  };

  var preview, seekEl, stageOverlay;

  // ---------------------------------------------------------------------------
  // boot
  // ---------------------------------------------------------------------------
  function boot() {
    var audioEl = $('#previewAudioEl') || (function () {
      var a = document.createElement('audio');
      a.id = 'previewAudioEl';
      a.preload = 'auto';
      document.body.appendChild(a);
      return a;
    })();
    preview = new window.SAS.Preview($('#preview'), { audio: audioEl });
    seekEl = $('#seek');
    stageOverlay = $('#stageOverlay');

    fillSelect($('#scBackground'), R.BG_LIST.map(function (b) { return { v: b.id, t: b.label }; }));
    fillSelect($('#musicStyle'), [{ v: 'dark-pulse', t: 'Dark pulse' }, { v: 'none', t: 'No music bed' }]);

    var saved = null;
    try { saved = JSON.parse(localStorage.getItem('sas.autosave') || 'null'); } catch (e) { }
    state.project = saved && saved.scenes ? Story.normalizeProject(saved) : Story.createDemoProject();
    state.dirty = !!saved;

    bindTransport();
    bindTabs();
    bindScriptTab();
    bindSceneTab();
    bindAudioTab();
    bindExportTab();
    bindProjectTab();
    bindDrawer();
    bindKeyboard();
    bindQuality();

    // canvas text needs the webfont before the first draw on some mobile builds
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        preview.render();
        try { refreshTimeline(); } catch (e) { }
      }).catch(function () { });
    }
    window.addEventListener('resize', function () { preview.render(); syncTopbarHeight(); });
    syncTopbarHeight();

    selectScene(0);
    refreshAll();

    preview.onTime = onPreviewTime;
    health();

    if (!saved) {
      setTimeout(function () { toast('Loaded the 15-second demo. Press play, then read the Script tab.'); }, 600);
    }
  }

  // ---------------------------------------------------------------------------
  // server health
  // ---------------------------------------------------------------------------
  function health() {
    fetch('api/health').then(function (r) { return r.json(); }).then(function (h) {
      state.health = h;
      var chip = $('#chipFfmpeg');
      if (h.ffmpeg && h.ffmpeg.available) {
        chip.className = 'chip ok';
        chip.textContent = 'FFmpeg ' + h.ffmpeg.version + ' ready (' + h.ffmpeg.source + ')';
      } else {
        chip.className = 'chip bad';
        chip.textContent = 'FFmpeg unavailable — export disabled';
      }
      $('#footerInfo').textContent = 'deterministic renderer · libx264 MP4 · ' + (h.ffmpeg && h.ffmpeg.available ? 'export ready' : 'export offline');
      refreshExports();
      refreshProjects();
    }).catch(function () {
      var chip = $('#chipFfmpeg');
      chip.className = 'chip bad';
      chip.textContent = 'Server unreachable';
    });
  }

  // ---------------------------------------------------------------------------
  // transport
  // ---------------------------------------------------------------------------
  function bindTransport() {
    $('#btnPlay').addEventListener('click', function () { preview.toggle(); syncPlayIcon(); });
    $('#btnStart').addEventListener('click', function () { preview.pause(); syncPlayIcon(); preview.seek(0); });
    $('#btnEnd').addEventListener('click', function () { preview.pause(); syncPlayIcon(); preview.seek(preview.duration() - 0.001); });
    $('#btnPrev').addEventListener('click', function () { jumpScene(-1); });
    $('#btnNext').addEventListener('click', function () { jumpScene(1); });
    seekEl.addEventListener('input', function () {
      preview.pause(); syncPlayIcon();
      preview.seek(parseFloat(seekEl.value));
    });
    $('#previewAudio').addEventListener('change', function (e) {
      preview.setAudioEnabled(e.target.checked);
      if (e.target.checked && state.audioUrl) playAudioAt(preview.time);
    });
    preview.onEnded = function () { syncPlayIcon(); };
  }

  function syncPlayIcon() {
    $('#icoPlay').hidden = preview.playing;
    $('#icoPause').hidden = !preview.playing;
  }

  function onPreviewTime(t, total) {
    $('#timeCurrent').textContent = C.formatTime(t);
    $('#timeTotal').textContent = C.formatTime(total);
    if (document.activeElement !== seekEl) seekEl.value = t;
    var at = R.sceneAt(state.project, t);
    if (at.index !== state.sceneIndex) {
      state.sceneIndex = at.index;
      highlightSceneChip();
      $('#sceneInfo').textContent = 'Scene ' + (at.index + 1) + ' / ' + state.project.scenes.length;
      renderSceneEditor();
    }
  }

  function jumpScene(dir) {
    var at = R.sceneAt(state.project, preview.time);
    var idx = C.clamp(at.index + dir, 0, state.project.scenes.length - 1);
    if (dir < 0 && at.local > 0.4) idx = at.index;
    var start = Story.sceneStart(state.project, idx);
    preview.pause(); syncPlayIcon();
    preview.seek(start + 0.001);
    selectScene(idx);
  }

  // the sticky tab bar / drawer must sit exactly below the (dynamic) top bar
  function syncTopbarHeight() {
    var bar = document.querySelector('.topbar');
    if (bar) document.documentElement.style.setProperty('--topbar-h', Math.round(bar.offsetHeight) + 'px');
  }

  function bindQuality() {
    var sel = $('#previewQuality');
    if (!sel) return;
    sel.addEventListener('change', function () {
      preview.setQuality(sel.value);
      $('#previewInfo').textContent = 'Preview ' + preview.W + '×' + preview.H;
      preview.seek(preview.time);
    });
  }

  function bindKeyboard() {
    document.addEventListener('keydown', function (e) {
      if (/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) return;
      if (e.code === 'Space') { e.preventDefault(); preview.toggle(); syncPlayIcon(); }
      if (e.code === 'ArrowRight') { preview.seek(preview.time + (e.shiftKey ? 1 : 1 / 30)); }
      if (e.code === 'ArrowLeft') { preview.seek(preview.time - (e.shiftKey ? 1 : 1 / 30)); }
    });
  }

  // ---------------------------------------------------------------------------
  // tabs + drawer
  // ---------------------------------------------------------------------------
  function bindTabs() {
    $$('.tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        $$('.tab').forEach(function (t) { t.classList.toggle('is-active', t === tab); });
        $$('.panel').forEach(function (p) { p.classList.toggle('is-active', p.id === tab.dataset.tab); });
      });
    });
  }
  function openTab(id) {
    var tab = $$('.tab').filter(function (t) { return t.dataset.tab === id; })[0];
    if (tab) tab.click();
  }
  function bindDrawer() {
    $('#menuBtn').addEventListener('click', function () {
      var d = $('#drawer');
      d.hidden = !d.hidden;
      $('#menuBtn').setAttribute('aria-expanded', String(!d.hidden));
    });
    $$('.drawer-item').forEach(function (item) {
      item.addEventListener('click', function () {
        $('#drawer').hidden = true;
        if (item.dataset.jump) {
          openTab(item.dataset.jump);
          document.getElementById(item.dataset.jump).scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      });
    });
    $('#drawerDemo').addEventListener('click', loadDemo);
    $('#drawerHint').addEventListener('click', function () {
      $('#drawer').hidden = true;
      toast('1) Script tab → build a storyboard. 2) Scene tab → tweak poses/props/text. 3) Export tab → Render MP4. Everything is editable JSON.');
    });
  }

  // ---------------------------------------------------------------------------
  // script tab
  // ---------------------------------------------------------------------------
  function bindScriptTab() {
    $('#btnBuild').addEventListener('click', function () {
      var script = $('#scriptInput').value.trim();
      if (!script) { toast('Write a script first (or press “Insert example script”).'); return; }
      var target = parseFloat($('#targetDuration').value) || 0;
      var built = Story.normalizeProject(Story.parseScript(script, { totalDuration: target, title: $('#storyTitle').value || 'Untitled story' }));
      state.project = built;
      state.serverFile = null;
      selectScene(0);
      refreshAll();
      toast('Storyboard built: ' + built.scenes.length + ' scenes, ' + R.totalDuration(built).toFixed(1) + 's.');
    });
    $('#btnDemo').addEventListener('click', loadDemo);
    $('#btnExample').addEventListener('click', function () {
      $('#scriptInput').value = EXAMPLE_SCRIPT;
      $('#storyTitle').value = 'ANOTHER DAY WASTED';
      toast('Example script inserted — press “Build storyboard from script”.');
    });
    $('#btnApplyTitle').addEventListener('click', function () {
      state.project.title = $('#storyTitle').value || 'Untitled story';
      markDirty(); refreshHeader();
    });
    $('#btnNormalize').addEventListener('click', function () {
      state.project.scenes.forEach(Story.fitSceneTimings);
      markDirty(); refreshAll(); toast('Scene timings re-fitted.');
    });
  }

  var EXAMPLE_SCRIPT =
    'Scene 1 (3s): A digital alarm clock reads 5:00 AM and rings while he sleeps in bed.\n' +
    'Scene 2 (2.5s): He reaches for his phone and scrolls in the dark.\n' +
    'Scene 3 (2.5s): Large red text: ANOTHER DAY WASTED.\n' +
    'Scene 4 (4s): He gets up, puts the phone away and exercises with dumbbells.\n' +
    'Scene 5 (3s): White text reads DISCIPLINE STARTS WITH ONE CHOICE.';

  function loadDemo() {
    state.project = Story.normalizeProject(Story.createDemoProject());
    state.serverFile = null;
    selectScene(0);
    refreshAll();
    toast('15-second demo loaded (5 scenes, 450 frames at 30 fps).');
  }

  // ---------------------------------------------------------------------------
  // scene editor
  // ---------------------------------------------------------------------------
  function bindSceneTab() {
    $('#scTitle').addEventListener('input', function (e) { sc().title = e.target.value; markDirty(true); });
    $('#scDescription').addEventListener('input', function (e) { sc().description = e.target.value; markDirty(true); });
    $('#scNarration').addEventListener('input', function (e) { sc().narration = e.target.value; syncScriptFromScenes(); markDirty(true); });
    $('#scDuration').addEventListener('input', function (e) {
      sc().duration = parseFloat(e.target.value);
      Story.fitSceneTimings(sc());
      $('#scDurOut').textContent = sc().duration.toFixed(1) + 's';
      markDirty(); refreshTimeline();
    });
    $('#scBackground').addEventListener('change', function (e) { sc().background = e.target.value; markDirty(); });
    $('#scTransIn').addEventListener('change', function (e) { sc().transitionIn = { type: e.target.value, duration: e.target.value === 'cut' ? 0 : 0.45 }; markDirty(); });
    $('#scTransOut').addEventListener('change', function (e) { sc().transitionOut = { type: e.target.value, duration: e.target.value === 'cut' ? 0 : 0.4 }; markDirty(); });

    $('#btnAddScene').addEventListener('click', function () {
      var s = Story.newScene({ title: 'Scene ' + (state.project.scenes.length + 1), duration: 3, background: sc().background });
      state.project.scenes.splice(state.sceneIndex + 1, 0, s);
      selectScene(state.sceneIndex + 1); refreshAll(); markDirty();
    });
    $('#btnDupScene').addEventListener('click', function () {
      var copy = JSON.parse(JSON.stringify(sc()));
      copy.id = Story.uid('scene'); copy.title = copy.title + ' (copy)';
      state.project.scenes.splice(state.sceneIndex + 1, 0, copy);
      selectScene(state.sceneIndex + 1); refreshAll(); markDirty();
    });
    $('#btnDelScene').addEventListener('click', function () {
      if (state.project.scenes.length <= 1) { toast('A story needs at least one scene.'); return; }
      state.project.scenes.splice(state.sceneIndex, 1);
      selectScene(Math.max(0, state.sceneIndex - 1)); refreshAll(); markDirty();
    });
    $('#btnSceneUp').addEventListener('click', function () { moveScene(-1); });
    $('#btnSceneDown').addEventListener('click', function () { moveScene(1); });

    // camera
    bindCam('camZoomStart', 'camZoomEnd', 'zoomTrack', 'camZoomOut', function (v) { return v.toFixed(2) + '×'; });
    bindCam('camXStart', 'camXEnd', 'xTrack', 'camXOut', function (v) { return v.toFixed(2); });
    bindCam('camYStart', 'camYEnd', 'yTrack', 'camYOut', function (v) { return v.toFixed(2); });
    $('#camHandheld').addEventListener('input', function (e) {
      sc().camera.handheld = parseFloat(e.target.value);
      $('#camHandOut').textContent = sc().camera.handheld.toFixed(2);
      markDirty();
    });
    $('#camShake').addEventListener('change', function (e) {
      var s = sc();
      s.shakes = e.target.checked ? [{ start: 0.9, end: Math.min(s.duration, 2.6), amp: 0.02 }] : [];
      markDirty();
    });

    $('#btnAddClip').addEventListener('click', function () {
      var s = sc(), actor = s.actors[0];
      var last = actor.clips[actor.clips.length - 1];
      var start = last ? last.end : 0;
      actor.clips.push({ action: 'stand', start: start, end: Math.min(s.duration, start + 1.2), loop: false });
      renderSceneEditor(); markDirty();
    });
    $('#btnAddProp').addEventListener('click', function () {
      sc().props.push(Story.newProp({ type: 'chair', x: 7.5, y: 1.35, layer: 'front' }));
      renderSceneEditor(); markDirty();
    });
    $('#btnAddText').addEventListener('click', function () {
      sc().texts.push(Story.newText({ text: 'NEW LINE', start: 0.2, end: sc().duration, size: 160, anim: 'wordPop' }));
      renderSceneEditor(); markDirty();
    });
    $('#btnAddLight').addEventListener('click', function () {
      sc().lights.push({ type: 'spot', x: 5, y: 4.2, r: 3, color: 'rgba(255,240,215,1)', intensity: 0.8 });
      renderSceneEditor(); markDirty();
    });
    $('#btnAddEffect').addEventListener('click', function () {
      sc().effects.push({ type: 'impactRing', start: 0.4, duration: 0.6, color: '#ff2f45', alpha: 0.4, x: 0.5, y: 0.5, scale: 0.16 });
      renderSceneEditor(); markDirty();
    });
  }

  function bindCam(startId, endId, trackKey, outId, fmt) {
    function apply() {
      var s = sc();
      var a = parseFloat($('#' + startId).value), b = parseFloat($('#' + endId).value);
      s.camera[trackKey] = { keys: [{ t: 0, v: a }, { t: Math.max(0.1, s.duration), v: b, ease: 'inOutQuad' }] };
      $('#' + outId).textContent = fmt(a) + ' → ' + fmt(b);
      markDirty();
    }
    $('#' + startId).addEventListener('input', apply);
    $('#' + endId).addEventListener('input', apply);
  }

  function moveScene(dir) {
    var i = state.sceneIndex, j = i + dir;
    if (j < 0 || j >= state.project.scenes.length) return;
    var arr = state.project.scenes;
    var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    selectScene(j); refreshAll(); markDirty();
  }

  function sc() { return state.project.scenes[state.sceneIndex]; }

  function selectScene(i) {
    state.sceneIndex = C.clamp(i, 0, state.project.scenes.length - 1);
    highlightSceneChip();
    renderSceneEditor();
    $('#sceneIndexChip').textContent = 'Scene ' + (state.sceneIndex + 1) + ' / ' + state.project.scenes.length;
  }

  function renderSceneEditor() {
    if (!state.project) return;
    var s = sc();
    $('#scTitle').value = s.title || '';
    $('#scDescription').value = s.description || '';
    $('#scNarration').value = s.narration || '';
    $('#scDuration').value = s.duration;
    $('#scDurOut').textContent = s.duration.toFixed(1) + 's';
    $('#scBackground').value = s.background;
    $('#scTransIn').value = (s.transitionIn && s.transitionIn.type) || 'cut';
    $('#scTransOut').value = (s.transitionOut && s.transitionOut.type) || 'cut';

    var cam = s.camera || {};
    var zt = cam.zoomTrack && cam.zoomTrack.keys, xt = cam.xTrack && cam.xTrack.keys, yt = cam.yTrack && cam.yTrack.keys;
    $('#camZoomStart').value = zt ? zt[0].v : (cam.zoom || 1);
    $('#camZoomEnd').value = zt ? zt[zt.length - 1].v : (cam.zoom || 1);
    $('#camXStart').value = xt ? xt[0].v : (cam.x || 5);
    $('#camXEnd').value = xt ? xt[xt.length - 1].v : (cam.x || 5);
    $('#camYStart').value = yt ? yt[0].v : (cam.y || 2.5);
    $('#camYEnd').value = yt ? yt[yt.length - 1].v : (cam.y || 2.5);
    $('#camHandheld').value = cam.handheld || 0;
    $('#camShake').checked = !!(s.shakes && s.shakes.length);
    ['camZoom', 'camX', 'camY'].forEach(function (p) {
      var a = $('#' + p + 'Start').value, b = $('#' + p + 'End').value;
      $('#' + p + 'Out').textContent = a + ' → ' + b;
    });
    $('#camHandOut').textContent = (cam.handheld || 0).toFixed(2);

    renderActorEditor();
    renderPropList();
    renderTextList();
    renderLightList();
    renderEffectList();
  }

  function renderActorEditor() {
    var host = $('#actorList');
    host.innerHTML = '';
    var s = sc();
    s.actors.forEach(function (actor, ai) {
      var card = el('div', 'card');
      var head = el('div', 'card-head');
      head.appendChild(el('strong', '', 'Stickman ' + (ai + 1)));
      var posBtn = el('button', 'tiny-btn', 'Position');
      posBtn.addEventListener('click', function () { toggleExtra(card, 'pos'); });
      var clipBtn = el('button', 'tiny-btn', '+ clip');
      clipBtn.addEventListener('click', function () {
        var last = actor.clips[actor.clips.length - 1];
        actor.clips.push({ action: 'stand', start: last ? last.end : 0, end: s.duration, loop: false });
        renderSceneEditor(); markDirty();
      });
      head.appendChild(posBtn); head.appendChild(clipBtn);
      card.appendChild(head);

      var posBox = el('div', 'card-grid');
      posBox.dataset.extra = 'pos';
      posBox.hidden = true;
      posBox.appendChild(rangeField('X', actor.x, 0, 10, 0.05, function (v) { actor.x = v; markDirty(); }));
      posBox.appendChild(rangeField('Y', actor.y, 0, 3.2, 0.02, function (v) { actor.y = v; markDirty(); }));
      posBox.appendChild(rangeField('Size', actor.size === undefined ? 1 : actor.size, 0.5, 1.6, 0.02, function (v) { actor.size = v; markDirty(); }));
      posBox.appendChild(rangeField('Opacity', actor.opacity, 0.05, 1, 0.05, function (v) { actor.opacity = v; markDirty(); }));
      var flip = el('button', 'tiny-btn', 'Face ' + (actor.facing < 0 ? '◀ left' : 'right ▶'));
      flip.addEventListener('click', function () {
        actor.facing = actor.facing < 0 ? 1 : -1;
        renderSceneEditor(); markDirty();
      });
      posBox.appendChild(flip);
      card.appendChild(posBox);

      actor.clips.forEach(function (clip, ci) {
        card.appendChild(clipEditor(actor, clip, ci));
      });
      host.appendChild(card);
    });
  }

  function clipEditor(actor, clip, ci) {
    var box = el('div', 'card');
    box.style.background = '#0d141d';
    var head = el('div', 'card-head');
    head.appendChild(el('strong', '', 'Clip ' + (ci + 1)));
    var del = el('button', 'tiny-btn danger', '✕');
    del.addEventListener('click', function () {
      actor.clips.splice(ci, 1);
      renderSceneEditor(); markDirty();
    });
    head.appendChild(del);
    box.appendChild(head);

    var grid = el('div', 'card-grid');
    var modeSel = selectEl([{ v: 'pose', t: 'Hold a pose' }, { v: 'loop', t: 'Loop an action' }, { v: 'morph', t: 'Morph pose → pose' }],
      clip.morph ? 'morph' : (clip.loop ? 'loop' : 'pose'));
    grid.appendChild(labelled('Mode', modeSel));

    var actionSel = selectEl(R.ACTIONS.map(function (a) { return { v: a.id, t: a.group + ' — ' + a.label }; }), clip.action || 'stand');
    grid.appendChild(labelled('Action', actionSel));

    function rebuild() {
      var mode = modeSel.value;
      if (mode === 'morph') {
        clip.morph = [clip.from || clip.action || 'stand', clip.to || 'stand'];
        clip.from = clip.morph[0]; clip.to = clip.morph[1];
        delete clip.action;
      } else {
        delete clip.morph; delete clip.from; delete clip.to;
        clip.action = actionSel.value;
        clip.loop = mode === 'loop';
      }
      renderSceneEditor(); markDirty();
    }
    actionSel.addEventListener('change', rebuild);
    modeSel.addEventListener('change', rebuild);

    var speedHost = el('div', 'card-grid full');
    if (clip.morph) {
      var fromSel = selectEl(R.ACTIONS.map(function (a) { return { v: a.id, t: a.label }; }), clip.morph[0]);
      var toSel = selectEl(R.ACTIONS.map(function (a) { return { v: a.id, t: a.label }; }), clip.morph[1]);
      fromSel.addEventListener('change', function () { clip.morph[0] = fromSel.value; clip.from = fromSel.value; markDirty(); });
      toSel.addEventListener('change', function () { clip.morph[1] = toSel.value; clip.to = toSel.value; markDirty(); });
      speedHost.appendChild(labelled('From pose', fromSel));
      speedHost.appendChild(labelled('To pose', toSel));
    } else if (clip.loop) {
      var period = rangeField('Cycle length (s)', clip.period || (R.ACTION_PERIOD[clip.action] || 1), 0.3, 4, 0.05, function (v) {
        clip.period = v;
        clip.params = Object.assign({}, clip.params, { period: v });
        markDirty();
      });
      speedHost.appendChild(period);
    }
    var startF = numField('Start (s)', clip.start, 0, 60, 0.05, function (v) { clip.start = v; markDirty(); });
    var endF = numField('End (s)', clip.end, 0, 60, 0.05, function (v) { clip.end = v; markDirty(); });
    var blend = rangeField('Blend-in (s)', clip.blendIn || 0, 0, 1.5, 0.05, function (v) { clip.blendIn = v; markDirty(); });
    speedHost.appendChild(startF); speedHost.appendChild(endF); speedHost.appendChild(blend);
    box.appendChild(speedHost);
    box.appendChild(grid);
    return box;
  }

  function renderPropList() {
    var host = $('#propList');
    host.innerHTML = '';
    sc().props.forEach(function (prop, i) {
      var card = el('div', 'card');
      var head = el('div', 'card-head');
      head.appendChild(el('strong', '', 'Prop ' + (i + 1)));
      var del = el('button', 'tiny-btn danger', '✕');
      del.addEventListener('click', function () { sc().props.splice(i, 1); renderSceneEditor(); markDirty(); });
      head.appendChild(del);
      card.appendChild(head);
      var grid = el('div', 'card-grid');
      var typeSel = selectEl(R.PROP_LIST.map(function (p) { return { v: p.id, t: p.label }; }), prop.type);
      typeSel.addEventListener('change', function () {
        prop.type = typeSel.value;
        var meta = R.PROP_LIST.filter(function (q) { return q.id === prop.type; })[0] || {};
        prop.w = meta.w; prop.h = meta.h;
        markDirty();
      });
      grid.appendChild(labelled('Type', typeSel));
      var layerSel = selectEl([{ v: 'back', t: 'Behind stickman' }, { v: 'front', t: 'In front' }], prop.layer || 'back');
      layerSel.addEventListener('change', function () { prop.layer = layerSel.value; markDirty(); });
      grid.appendChild(labelled('Layer', layerSel));
      grid.appendChild(rangeField('X', prop.x, 0, 10, 0.02, function (v) { prop.x = v; markDirty(); }));
      grid.appendChild(rangeField('Y', prop.y, 0, 3.2, 0.01, function (v) { prop.y = v; markDirty(); }));
      grid.appendChild(rangeField('Scale', prop.scale || 1, 0.4, 2.5, 0.02, function (v) { prop.scale = v; markDirty(); }));
      grid.appendChild(rangeField('Opacity', prop.opacity === undefined ? 1 : prop.opacity, 0, 1, 0.05, function (v) { prop.opacity = v; markDirty(); }));
      if (prop.type === 'alarm_clock') {
        grid.appendChild(labelled('Display', textField(prop.time || '5:00', function (v) { prop.time = v; markDirty(); })));
        grid.appendChild(labelled('Ringing', checkbox(prop.ringing !== false, function (v) { prop.ringing = v; markDirty(); })));
      }
      if (prop.type === 'bed' || prop.type === 'stairs' || prop.type === 'desk') {
        grid.appendChild(rangeField('Width', prop.w || 3, 0.6, 5, 0.05, function (v) { prop.w = v; markDirty(); }));
      }
      card.appendChild(grid);
      host.appendChild(card);
    });
  }

  function renderTextList() {
    var host = $('#textList');
    host.innerHTML = '';
    sc().texts.forEach(function (txt, i) {
      var card = el('div', 'card');
      var head = el('div', 'card-head');
      head.appendChild(el('strong', '', 'Text ' + (i + 1)));
      var del = el('button', 'tiny-btn danger', '✕');
      del.addEventListener('click', function () { sc().texts.splice(i, 1); renderSceneEditor(); markDirty(); });
      head.appendChild(del);
      card.appendChild(head);
      var grid = el('div', 'card-grid');
      grid.appendChild(labelled('Content (**red**)', textField(txt.text, function (v) { txt.text = v; markDirty(); })));
      grid.appendChild(labelled('In animation', selectEl([
        { v: 'slam', t: 'Slam in' }, { v: 'wordPop', t: 'Word pop' }, { v: 'fadeUp', t: 'Fade up' },
        { v: 'fadeDown', t: 'Fade down' }, { v: 'typewriter', t: 'Typewriter' }, { v: 'slideLeft', t: 'Slide left' },
        { v: 'slideRight', t: 'Slide right' }, { v: 'scaleIn', t: 'Scale in' }, { v: 'popIn', t: 'Pop in' },
        { v: 'letterspace', t: 'Letter spacing' }, { v: 'shake', t: 'Shake' }, { v: 'glitch', t: 'Glitch' }, { v: 'none', t: 'None' }
      ], txt.anim, function (v) { txt.anim = v; markDirty(); })));
      grid.appendChild(rangeField('Font size', txt.size || 160, 60, 260, 5, function (v) { txt.size = v; markDirty(); }, 'px'));
      grid.appendChild(rangeField('Start (s)', txt.start || 0, 0, sc().duration, 0.05, function (v) { txt.start = v; markDirty(); }, 's'));
      grid.appendChild(rangeField('End (s)', txt.end === null || txt.end === undefined ? sc().duration : txt.end, 0, sc().duration, 0.05, function (v) { txt.end = v; markDirty(); }, 's'));
      grid.appendChild(rangeField('Position Y', txt.y === undefined ? 0.5 : txt.y, 0.05, 0.95, 0.01, function (v) { txt.y = v; markDirty(); }));
      grid.appendChild(rangeField('Position X', txt.x === undefined ? 0.5 : txt.x, 0.1, 0.9, 0.01, function (v) { txt.x = v; markDirty(); }));
      grid.appendChild(rangeField('Glow', txt.glow === undefined ? 0.35 : txt.glow, 0, 1, 0.05, function (v) { txt.glow = v; markDirty(); }));
      grid.appendChild(labelled('Colour', colorField(txt.color || '#ffffff', function (v) { txt.color = v; markDirty(); })));
      grid.appendChild(labelled('Red emphasis', colorField(txt.emphasisColor || '#ff2f45', function (v) { txt.emphasisColor = v; markDirty(); })));
      grid.appendChild(labelled('Underline emphasis', checkbox(!!txt.underline, function (v) { txt.underline = v; markDirty(); })));
      grid.appendChild(labelled('Dark panel behind', checkbox(!!txt.panel, function (v) { txt.panel = v; markDirty(); })));
      card.appendChild(grid);
      host.appendChild(card);
    });
  }

  function renderLightList() {
    var host = $('#lightList');
    host.innerHTML = '';
    (sc().lights || []).forEach(function (L, i) {
      var card = el('div', 'card');
      var head = el('div', 'card-head');
      head.appendChild(el('strong', '', 'Light ' + (i + 1)));
      var del = el('button', 'tiny-btn danger', '✕');
      del.addEventListener('click', function () { sc().lights.splice(i, 1); renderSceneEditor(); markDirty(); });
      head.appendChild(del);
      card.appendChild(head);
      var grid = el('div', 'card-grid');
      grid.appendChild(labelled('Type', selectEl([{ v: 'spot', t: 'Spot glow' }, { v: 'godray', t: 'God rays' }, { v: 'flicker', t: 'Flicker' }], L.type || 'spot', function (v) { L.type = v; markDirty(); })));
      grid.appendChild(rangeField('X', L.x === undefined ? 5 : L.x, 0, 10, 0.05, function (v) { L.x = v; markDirty(); }));
      grid.appendChild(rangeField('Y', L.y === undefined ? 4 : L.y, 0, 6, 0.05, function (v) { L.y = v; markDirty(); }));
      grid.appendChild(rangeField('Radius', L.r === undefined ? 3 : L.r, 0.4, 8, 0.1, function (v) { L.r = v; markDirty(); }));
      grid.appendChild(rangeField('Intensity', L.intensity === undefined ? 0.8 : L.intensity, 0, 2, 0.05, function (v) { L.intensity = v; markDirty(); }));
      card.appendChild(grid);
      host.appendChild(card);
    });
  }

  function renderEffectList() {
    var host = $('#effectList');
    host.innerHTML = '';
    (sc().effects || []).forEach(function (fx, i) {
      var card = el('div', 'card');
      var head = el('div', 'card-head');
      head.appendChild(el('strong', '', 'Effect ' + (i + 1)));
      var del = el('button', 'tiny-btn danger', '✕');
      del.addEventListener('click', function () { sc().effects.splice(i, 1); renderSceneEditor(); markDirty(); });
      head.appendChild(del);
      card.appendChild(head);
      var grid = el('div', 'card-grid');
      grid.appendChild(labelled('Type', selectEl(R.FX_LIST.map(function (f) { return { v: f.id, t: f.label }; }), fx.type, function (v) { fx.type = v; markDirty(); })));
      grid.appendChild(rangeField('Start (s)', fx.start || 0, 0, sc().duration, 0.05, function (v) { fx.start = v; markDirty(); }, 's'));
      grid.appendChild(rangeField('Duration (s)', fx.duration === undefined ? 0.6 : fx.duration, 0.05, sc().duration, 0.05, function (v) { fx.duration = v; markDirty(); }, 's'));
      grid.appendChild(rangeField('Strength', fx.alpha === undefined ? 0.5 : fx.alpha, 0, 1, 0.05, function (v) { fx.alpha = v; markDirty(); }));
      if (fx.type === 'textCallout') grid.appendChild(labelled('Label', textField(fx.text || 'WAKE UP', function (v) { fx.text = v; markDirty(); })));
      card.appendChild(grid);
      host.appendChild(card);
    });
  }

  // ---------------------------------------------------------------------------
  // audio tab
  // ---------------------------------------------------------------------------
  function bindAudioTab() {
    function vol(id, outId, apply) {
      $('#' + id).addEventListener('input', function (e) {
        var v = parseFloat(e.target.value);
        apply(v);
        $('#' + outId).textContent = Math.round(v * 100) + '%';
        markDirty();
      });
    }
    vol('volMaster', 'volMasterOut', function (v) { state.project.audio.master = v; });
    vol('volMusic', 'volMusicOut', function (v) { state.project.audio.music = v; });
    vol('volSfx', 'volSfxOut', function (v) { state.project.audio.sfx = v; });
    vol('volNarr', 'volNarrOut', function (v) { state.narration.volume = v; });
    $('#muteMusic').addEventListener('change', function (e) { state.project.audio.muteMusic = e.target.checked; markDirty(); });
    $('#muteSfx').addEventListener('change', function (e) { state.project.audio.muteSfx = e.target.checked; markDirty(); });
    $('#musicStyle').addEventListener('change', function (e) {
      state.project.music.style = e.target.value;
      state.project.scenes.forEach(function (s) {
        s.audio = (s.audio || []).filter(function (a) { return a.type !== 'music'; });
      });
      if (e.target.value !== 'none') {
        state.project.scenes.forEach(function (s, i) {
          if (i >= state.project.scenes.length - 2) {
            s.audio.push({ type: 'music', at: i === state.project.scenes.length - 1 ? 0 : Math.max(0, s.duration - 1.6), dur: i === state.project.scenes.length - 1 ? s.duration : 1.6, gain: 0.75, bpm: state.project.music.bpm });
          }
        });
      }
      markDirty(); renderCueList(); refreshAudio();
    });
    $('#musicBpm').addEventListener('input', function (e) {
      state.project.music.bpm = parseFloat(e.target.value);
      $('#musicBpmOut').textContent = e.target.value;
      state.project.scenes.forEach(function (s) { (s.audio || []).forEach(function (a) { if (a.type === 'music') a.bpm = state.project.music.bpm; }); });
      markDirty();
    });
    $('#btnPreviewAudio').addEventListener('click', function () {
      playAudioAt(preview.time);
      toast('Playing the generated mix from ' + C.formatTime(preview.time) + '.');
    });
    $('#btnNarrUpload').addEventListener('click', uploadNarration);
    $('#btnNarrRemove').addEventListener('click', function () {
      state.narration.uploaded = false;
      $('#narrStatus').textContent = 'No narration file uploaded.';
      $('#narrPlayer').hidden = true;
      $('#expNarration').checked = false;
    });
  }

  function renderCueList() {
    var host = $('#cueList');
    host.innerHTML = '';
    state.project.scenes.forEach(function (s, si) {
      (s.audio || []).forEach(function (cue, ci) {
        var card = el('div', 'card');
        var head = el('div', 'card-head');
        head.appendChild(el('strong', '', 'Scene ' + (si + 1) + ' · ' + cue.type));
        var del = el('button', 'tiny-btn danger', '✕');
        del.addEventListener('click', function () { s.audio.splice(ci, 1); renderCueList(); markDirty(); });
        head.appendChild(del);
        card.appendChild(head);
        var grid = el('div', 'card-grid');
        grid.appendChild(labelled('Type', selectEl(A.CUE_TYPES.map(function (t) { return { v: t, t: t }; }), cue.type, function (v) { cue.type = v; markDirty(); })));
        grid.appendChild(rangeField('At (s)', cue.at || 0, 0, s.duration, 0.05, function (v) { cue.at = v; markDirty(); }, 's'));
        grid.appendChild(rangeField('Length (s)', cue.dur === undefined ? s.duration : cue.dur, 0.05, s.duration, 0.05, function (v) { cue.dur = v; markDirty(); }, 's'));
        grid.appendChild(rangeField('Gain', cue.gain === undefined ? 0.8 : cue.gain, 0, 1.4, 0.05, function (v) { cue.gain = v; markDirty(); }));
        card.appendChild(grid);
        host.appendChild(card);
      });
      var add = el('button', 'tiny-btn', '+ add cue to scene ' + (si + 1));
      add.addEventListener('click', function () {
        s.audio = s.audio || [];
        s.audio.push({ type: 'whoosh', at: 0, dur: Math.min(0.6, s.duration), gain: 0.5 });
        renderCueList(); markDirty();
      });
      host.appendChild(add);
    });
  }

  function uploadNarration() {
    var input = $('#narrFile');
    if (!input.files || !input.files[0]) { toast('Choose an audio file first.'); return; }
    var file = input.files[0];
    var ext = (file.name.split('.').pop() || 'mp3').toLowerCase();
    $('#narrStatus').textContent = 'Uploading ' + file.name + '…';
    fetch('api/narration/' + encodeURIComponent(state.project.id || 'project') + '?ext=' + ext, {
      method: 'POST', headers: { 'Content-Type': file.type || 'application/octet-stream' }, body: file
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res.error) throw new Error(res.error);
      state.narration.uploaded = true;
      $('#narrStatus').textContent = 'Narration ready: ' + file.name + ' (' + Math.round(res.bytes / 1024) + ' KB). It will be muxed into the MP4 at the narration volume.';
      $('#narrPlayer').src = res.url;
      $('#narrPlayer').hidden = false;
      $('#expNarration').checked = true;
    }).catch(function (e) {
      $('#narrStatus').textContent = 'Upload failed: ' + e.message;
    });
  }

  function refreshAudio() {
    var a = state.project.audio;
    $('#volMaster').value = a.master; $('#volMasterOut').textContent = Math.round(a.master * 100) + '%';
    $('#volMusic').value = a.music; $('#volMusicOut').textContent = Math.round(a.music * 100) + '%';
    $('#volSfx').value = a.sfx; $('#volSfxOut').textContent = Math.round(a.sfx * 100) + '%';
    $('#volNarr').value = state.narration.volume; $('#volNarrOut').textContent = Math.round(state.narration.volume * 100) + '%';
    $('#muteMusic').checked = !!a.muteMusic;
    $('#muteSfx').checked = !!a.muteSfx;
    $('#musicStyle').value = state.project.music.style || 'dark-pulse';
    $('#musicBpm').value = state.project.music.bpm || 92;
    $('#musicBpmOut').textContent = state.project.music.bpm || 92;
    renderCueList();
  }

  // ---------------------------------------------------------------------------
  // audio playback (server-rendered deterministic mix)
  // ---------------------------------------------------------------------------
  var audioReq = null;
  function refreshAudioMix() {
    if (audioReq) audioReq.abort();
    var hasCues = A.buildCues(state.project).length > 0;
    if (!hasCues) {
      state.audioUrl = null;
      $('#btnAudioDownload').removeAttribute('href');
      return;
    }
    var payload = JSON.stringify({ project: state.project });
    audioReq = new AbortController();
    fetch('api/audio.wav', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, signal: audioReq.signal })
      .then(function (r) { if (r.status === 204) throw new Error('empty'); return r.blob(); })
      .then(function (blob) {
        if (state.audioUrl) URL.revokeObjectURL(state.audioUrl);
        state.audioUrl = URL.createObjectURL(blob);
        preview.setAudioSource(state.audioUrl);
        $('#btnAudioDownload').href = state.audioUrl;
      })
      .catch(function () { /* aborted or no audio */ });
  }

  function playAudioAt(t) {
    if (!state.audioUrl) { toast('No audio cues in this project yet.'); return; }
    preview.setAudioEnabled(true);
    $('#previewAudio').checked = true;
    preview.seek(t);
    preview.play();
    syncPlayIcon();
  }

  // ---------------------------------------------------------------------------
  // export tab
  // ---------------------------------------------------------------------------
  function bindExportTab() {
    $('#expGrain').addEventListener('change', function (e) {
      state.project.look = state.project.look || {};
      state.project.look.grain = e.target.checked;
      markDirty();
    });
    $('#btnRender').addEventListener('click', startRender);
    $('#btnCancel').addEventListener('click', function () {
      if (!state.jobId) return;
      fetch('api/render/' + state.jobId + '/cancel', { method: 'POST' });
    });
  }

  function startRender() {
    if (!state.health || !state.health.ffmpeg || !state.health.ffmpeg.available) {
      toast('FFmpeg is not available on the server, so export is disabled. Check /api/health.');
      return;
    }
    var size = $('#expSize').value.split('x');
    var body = {
      project: state.project,
      options: {
        width: parseInt(size[0], 10), height: parseInt(size[1], 10),
        fps: parseInt($('#expFps').value, 10),
        crf: parseInt($('#expCrf').value, 10) || 18,
        includeAudio: $('#expAudio').checked,
        label: parseInt(size[0], 10) >= 1920 ? 'final' : 'preview'
      },
      narration: { uploaded: $('#expNarration').checked && state.narration.uploaded, volume: state.narration.volume, offset: 0 }
    };
    $('#btnRender').disabled = true;
    $('#progressWrap').hidden = false;
    $('#renderResult').hidden = true;
    $('#progressBar').style.width = '0%';
    $('#progressText').textContent = 'Sending project to the renderer…';

    fetch('api/render', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.error) throw new Error(res.error);
        state.jobId = res.jobId;
        $('#btnCancel').hidden = false;
        $('#progressText').textContent = 'Rendering ' + res.totalFrames + ' frames at ' + res.width + '×' + res.height + '…';
        pollJob();
      })
      .catch(function (e) {
        $('#btnRender').disabled = false;
        $('#progressText').textContent = 'Could not start: ' + e.message;
      });
  }

  function pollJob() {
    if (!state.jobId) return;
    fetch('api/render/' + state.jobId).then(function (r) { return r.json(); }).then(function (j) {
      if (j.status === 'running') {
        $('#progressBar').style.width = j.progress + '%';
        var eta = j.progress > 1 ? (j.elapsedMs / j.progress * (100 - j.progress) / 1000).toFixed(0) : '—';
        $('#progressText').textContent = 'Frame ' + j.frame + ' / ' + j.totalFrames + ' · ' + j.progress.toFixed(1) + '% · ' + (j.elapsedMs / 1000).toFixed(1) + 's elapsed · ~' + eta + 's left';
        state.jobTimer = setTimeout(pollJob, 700);
        return;
      }
      clearTimeout(state.jobTimer);
      $('#btnRender').disabled = false;
      $('#btnCancel').hidden = true;
      if (j.status === 'error') {
        $('#progressText').textContent = 'Render failed: ' + j.error;
        return;
      }
      if (j.status === 'cancelled') {
        $('#progressText').textContent = 'Render cancelled.';
        return;
      }
      showRenderResult(j.result);
      refreshExports();
    }).catch(function () { state.jobTimer = setTimeout(pollJob, 1200); });
  }

  function showRenderResult(result) {
    var p = result.probe || {};
    var v = p.video || {};
    var html = '<b>MP4 written and verified</b>';
    html += '<dl>' +
      '<dt>File</dt><dd>' + esc(result.file) + '</dd>' +
      '<dt>Size</dt><dd>' + (result.bytes / 1048576).toFixed(2) + ' MB</dd>' +
      '<dt>Duration</dt><dd>' + (p.duration || result.durationSec).toFixed(2) + ' s</dd>' +
      '<dt>Video</dt><dd>' + esc(v.codec || '') + ' · ' + (v.width || result.width) + '×' + (v.height || result.height) + ' · ' + (v.fps || result.fps) + ' fps · ' + esc(v.pixFmt || '') + '</dd>' +
      '<dt>Audio</dt><dd>' + (p.audio ? esc(p.audio.codec) : 'none') + '</dd>' +
      '<dt>Render time</dt><dd>' + (result.ms / 1000).toFixed(1) + ' s</dd>' +
      '</dl>';
    $('#renderResultBody').innerHTML = html;
    var url = result.downloadUrl || ('/api/export/' + encodeURIComponent(result.file));
    $('#btnDownloadMp4').href = url;
    $('#resultVideo').src = url;
    $('#renderResult').hidden = false;
    $('#progressBar').style.width = '100%';
    $('#progressText').textContent = 'Done — frame-accurate 1080p H.264 file with AAC audio.';
    $('#renderResult').scrollIntoView({ behavior: 'smooth', block: 'center' });
    toast('MP4 ready: ' + (result.bytes / 1048576).toFixed(2) + ' MB. Tap “Download MP4”.');
  }

  function refreshExports() {
    fetch('api/exports').then(function (r) { return r.json(); }).then(function (res) {
      var host = $('#exportList');
      host.innerHTML = '';
      if (!res.exports.length) { host.appendChild(el('p', 'hint', 'No exports yet — render one above.')); return; }
      res.exports.forEach(function (f) {
        var item = el('div', 'list-item');
        var grow = el('div', 'grow');
        grow.appendChild(el('b', '', f.file));
        grow.appendChild(el('small', '', (f.bytes / 1048576).toFixed(2) + ' MB · ' + new Date(f.modified).toLocaleString()));
        item.appendChild(grow);
        var play = el('a', 'tiny-btn', 'Open');
        play.href = f.url; play.target = '_blank';
        var dl = el('a', 'tiny-btn', 'Download');
        dl.href = f.url; dl.download = f.file;
        item.appendChild(play); item.appendChild(dl);
        host.appendChild(item);
      });
    }).catch(function () { });
  }

  // ---------------------------------------------------------------------------
  // project tab
  // ---------------------------------------------------------------------------
  function bindProjectTab() {
    $('#btnSaveProject').addEventListener('click', function () { saveProject(state.serverFile); });
    $('#btnSaveAs').addEventListener('click', function () {
      var name = (state.project.title || 'project').replace(/[^a-z0-9 _-]/gi, '').trim().replace(/\s+/g, '-').toLowerCase();
      saveProject(name + '.json');
    });
    $('#btnExportJson').addEventListener('click', function () {
      var blob = new Blob([JSON.stringify(state.project, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = (state.project.id || 'stickman-project') + '.json';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    });
    $('#importJson').addEventListener('change', function (e) {
      var file = e.target.files && e.target.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function () {
        try {
          state.project = Story.normalizeProject(JSON.parse(reader.result));
          state.serverFile = null;
          selectScene(0); refreshAll();
          toast('Project loaded: ' + state.project.title);
        } catch (err) { toast('That file is not valid project JSON.'); }
      };
      reader.readAsText(file);
    });
    $('#btnNewProject').addEventListener('click', function () {
      state.project = Story.normalizeProject(Story.createEmptyProject());
      state.serverFile = null;
      selectScene(0); refreshAll();
      toast('Empty project created.');
    });
  }

  function saveProject(filename) {
    fetch('api/projects', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project: state.project, file: filename || null })
    }).then(function (r) { return r.json(); }).then(function (res) {
      state.serverFile = res.file;
      state.dirty = false;
      refreshProjects();
      toast('Saved as ' + res.file + ' on the server.');
    }).catch(function (e) { toast('Save failed: ' + e.message); });
  }

  function refreshProjects() {
    fetch('api/projects').then(function (r) { return r.json(); }).then(function (res) {
      var host = $('#projectList');
      host.innerHTML = '';
      if (!res.projects || !res.projects.length) { host.appendChild(el('p', 'hint', 'Nothing saved yet.')); return; }
      res.projects.forEach(function (p) {
        var item = el('div', 'list-item');
        var grow = el('div', 'grow');
        grow.appendChild(el('b', '', p.title || p.file));
        grow.appendChild(el('small', '', p.scenes + ' scenes · ' + (p.duration || 0).toFixed(1) + 's · ' + new Date(p.savedAt).toLocaleString()));
        item.appendChild(grow);
        var open = el('button', 'tiny-btn', 'Open');
        open.addEventListener('click', function () {
          fetch('api/projects/' + encodeURIComponent(p.file)).then(function (r) { return r.json(); }).then(function (project) {
            state.project = Story.normalizeProject(project);
            state.serverFile = p.file;
            selectScene(0); refreshAll();
            toast('Opened ' + p.file);
          });
        });
        var del = el('button', 'tiny-btn danger', '✕');
        del.addEventListener('click', function () {
          fetch('api/projects/' + encodeURIComponent(p.file), { method: 'DELETE' }).then(refreshProjects);
        });
        item.appendChild(open); item.appendChild(del);
        host.appendChild(item);
      });
    }).catch(function () { });
  }

  // ---------------------------------------------------------------------------
  // shared refresh
  // ---------------------------------------------------------------------------
  function refreshAll() {
    // NOTE: the project is normalised when it is ingested (boot, import, script
    // build, demo load, project open) — not here — so editors never hold a
    // reference to an object that was silently replaced.
    refreshHeader();
    preview.setProject(state.project);
    refreshTimeline();
    renderSceneEditor();
    refreshAudio();
    renderCueList();
    refreshAudioMix();
    $('#expGrain').checked = !!(state.project.look && state.project.look.grain);
    $('#targetDuration').value = Math.round(R.totalDuration(state.project));
    $('#storyTitle').value = state.project.title;
    if (state.project.sourceScript) $('#scriptInput').value = state.project.sourceScript;
    else syncScriptFromScenes();
    preview.seek(Math.min(preview.time, R.totalDuration(state.project)));
    $('#previewInfo').textContent = 'Preview ' + preview.W + '×' + preview.H;
  }

  function refreshHeader() {
    $('#projectTitleLabel').textContent = state.project.title + (state.dirty ? ' •' : '');
    var total = R.totalDuration(state.project);
    $('#chipDuration').textContent = state.project.scenes.length + ' scenes · ' + total.toFixed(1) + 's · ' + Math.round(total * (state.project.fps || 30)) + ' frames';
    var chip = $('#chipJobs');
    if (state.jobId) { chip.hidden = false; chip.textContent = 'render job active'; }
    else chip.hidden = true;
  }

  function refreshTimeline() {
    var strip = $('#sceneStrip');
    strip.innerHTML = '';
    state.project.scenes.forEach(function (s, i) {
      var chip = el('button', 'scene-chip');
      chip.dataset.index = i;
      var cv = document.createElement('canvas');
      cv.width = 232; cv.height = 131;
      var cx = cv.getContext('2d');
      try { R.renderScene(cx, state.project, i, s.duration * 0.55, { W: 232, H: 131, fontFamily: 'DejaVu Sans', sceneAlpha: 1 }); } catch (e) { }
      chip.appendChild(cv);
      chip.appendChild(el('b', '', (i + 1) + '. ' + (s.title || 'Scene')));
      chip.appendChild(el('small', '', s.duration.toFixed(1) + 's · ' + (s.actors && s.actors[0] ? (s.actors[0].clips.map(function (c) { return c.morph ? (c.morph[0] + '→' + c.morph[1]) : c.action; })[0] || '') : '')));
      chip.addEventListener('click', function () {
        selectScene(i);
        preview.pause(); syncPlayIcon();
        preview.seek(Story.sceneStart(state.project, i) + 0.001);
      });
      strip.appendChild(chip);
    });
    highlightSceneChip();
    $('#timeTotal').textContent = C.formatTime(R.totalDuration(state.project));
    seekEl.max = R.totalDuration(state.project);
    $('#sceneInfo').textContent = 'Scene ' + (state.sceneIndex + 1) + ' / ' + state.project.scenes.length;
    refreshHeader();
  }

  function highlightSceneChip() {
    $$('.scene-chip').forEach(function (chip) {
      chip.classList.toggle('is-active', parseInt(chip.dataset.index, 10) === state.sceneIndex);
    });
    var active = $$('.scene-chip').filter(function (c) { return c.classList.contains('is-active'); })[0];
    if (active && active.scrollIntoView) {
      var strip = $('#sceneStrip');
      var left = active.offsetLeft - strip.clientWidth / 2 + active.clientWidth / 2;
      strip.scrollTo({ left: Math.max(0, left), behavior: 'smooth' });
    }
  }

  function syncScriptFromScenes() {
    $('#scriptInput').value = state.project.scenes.map(function (s, i) {
      return 'Scene ' + (i + 1) + ' (' + s.duration.toFixed(1) + 's): ' + (s.description || s.narration || s.title);
    }).join('\n');
  }

  // ---------------------------------------------------------------------------
  // small DOM helpers
  // ---------------------------------------------------------------------------
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }
  function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function fillSelect(sel, options) {
    sel.innerHTML = '';
    options.forEach(function (o) { sel.appendChild(new Option(o.t, o.v)); });
  }
  function selectEl(options, value, onChange) {
    var s = document.createElement('select');
    options.forEach(function (o) { s.appendChild(new Option(o.t, o.v)); });
    s.value = value;
    if (onChange) s.addEventListener('change', function () { onChange(s.value); });
    return s;
  }
  function labelled(text, node) {
    var l = document.createElement('label');
    l.appendChild(document.createTextNode(text));
    l.appendChild(node);
    return l;
  }
  function textField(value, onChange) {
    var i = document.createElement('input');
    i.type = 'text'; i.value = value === undefined ? '' : value;
    i.addEventListener('input', function () { onChange(i.value); });
    return i;
  }
  function numField(text, value, min, max, step, onChange) {
    var wrap = document.createElement('label');
    var out = document.createElement('output');
    out.className = 'out';
    var i = document.createElement('input');
    i.type = 'number'; i.min = min; i.max = max; i.step = step; i.value = value;
    out.textContent = value + 's';
    i.addEventListener('input', function () {
      var v = parseFloat(i.value);
      if (!isNaN(v)) { out.textContent = v.toFixed(2) + 's'; onChange(v); }
    });
    wrap.appendChild(document.createTextNode(text));
    wrap.appendChild(out);
    wrap.appendChild(i);
    return wrap;
  }
  function rangeField(text, value, min, max, step, onChange, suffix) {
    var wrap = document.createElement('label');
    var out = document.createElement('output');
    out.className = 'out';
    var i = document.createElement('input');
    i.type = 'range'; i.min = min; i.max = max; i.step = step; i.value = value;
    out.textContent = formatVal(value, suffix);
    i.addEventListener('input', function () {
      var v = parseFloat(i.value);
      out.textContent = formatVal(v, suffix);
      onChange(v);
    });
    wrap.appendChild(document.createTextNode(text));
    wrap.appendChild(out);
    wrap.appendChild(i);
    return wrap;
  }
  function formatVal(v, suffix) {
    if (suffix === 's') return v.toFixed(2) + 's';
    if (suffix === 'px') return Math.round(v) + 'px';
    return (Math.round(v * 100) / 100).toString();
  }
  function checkbox(checked, onChange) {
    var i = document.createElement('input');
    i.type = 'checkbox'; i.checked = !!checked;
    i.addEventListener('change', function () { onChange(i.checked); });
    return i;
  }
  function colorField(value, onChange) {
    var i = document.createElement('input');
    i.type = 'color'; i.value = value;
    i.style.height = 'var(--tap)';
    i.addEventListener('input', function () { onChange(i.value); });
    return i;
  }
  function toggleExtra(card, key) {
    Array.prototype.slice.call(card.querySelectorAll('[data-extra]')).forEach(function (n) {
      if (n.dataset.extra === key) n.hidden = !n.hidden;
    });
  }
  function markDirty(light) {
    state.dirty = true;
    $('#projectTitleLabel').textContent = state.project.title + ' •';
    if (light) { preview.render(); refreshTimelineSoon(); }
    else { preview.render(); refreshTimeline(); }
    autosaveSoon();
  }
  var timeliner = null;
  function refreshTimelineSoon() {
    clearTimeout(timeliner);
    timeliner = setTimeout(function () { refreshTimeline(); refreshHeader(); }, 400);
  }
  var saver = null;
  function autosaveSoon() {
    clearTimeout(saver);
    saver = setTimeout(function () {
      try { localStorage.setItem('sas.autosave', JSON.stringify(state.project)); } catch (e) { }
    }, 800);
  }
  function toast(msg) {
    var t = $('#toast');
    if (!t) {
      t = el('div', 'overlay-card');
      t.id = 'toast';
      t.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:calc(18px + var(--safe-b));z-index:60;max-width:92vw;box-shadow:0 8px 30px #000c';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; }, 4200);
  }

  // expose a couple of hooks for the console / manual testing
  window.SAS_APP = {
    state: state,
    refresh: refreshAll,
    get preview() { return preview; },
    play: function () { preview.play(); syncPlayIcon(); },
    pause: function () { preview.pause(); syncPlayIcon(); }
  };

  document.addEventListener('DOMContentLoaded', boot);
  if (document.readyState !== 'loading') boot();
})();
