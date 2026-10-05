/* Almacen — panel único del almacén de bebidas (F65/RF-74).
 * Sin dependencias. Estado por SSE con fallback a polling. */
(function () {
  'use strict';

  var ROOM_ID = parseInt((new URLSearchParams(location.search)).get('room') || '0', 10) || 0;
  var state = null;
  var access = null;
  var sse = null;
  var pollTimer = null;
  // F68/RF-78: estado de reproducción de una visita (null = en vivo).
  var playback = null;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.style.display = 'none'; }, 2600);
  }
  function api(path, opts) {
    return fetch(path, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error(j.message || j.error || ('HTTP ' + r.status)); }
        return j;
      });
    });
  }

  // ---- render -------------------------------------------------------------
  function renderHeader() {
    var wh = state && state.warehouse;
    var badge = $('wh-badge');
    if (!state || !state.room) {
      $('wh-name').textContent = 'Almacén (sin configurar)';
      badge.className = 'badge off';
      badge.textContent = 'sin configurar';
      $('wh-visit').textContent = '';
      return;
    }
    $('wh-name').textContent = 'Almacén ' + state.room.code;
    if (wh && wh.occupied) {
      badge.className = 'badge occ';
      badge.textContent = 'OCUPADO';
    } else if (wh && wh.state !== 'IDLE') {
      badge.className = 'badge occ';
      badge.textContent = wh.state;
    } else {
      badge.className = 'badge free';
      badge.textContent = 'LIBRE';
    }
    var v = wh && wh.current_visit;
    if (v) {
      var who = v.worker ? v.worker.name : 'anónimo';
      $('wh-visit').textContent = '· ' + who + (v.seconds_inside != null ? ' (' + v.seconds_inside + ' s dentro)' : '');
    } else {
      $('wh-visit').textContent = '';
    }
    var ret = state.retention || {};
    $('wh-retention').textContent = ret.auto
      ? ('retención ' + ret.days + ' d' + (ret.disk_used_pct != null ? ' · disco ' + ret.disk_used_pct + '%' : ''))
      : 'retención: sin borrado';
  }

  function renderCameras() {
    var box = $('cams');
    var cams = (state && state.cameras) || [];
    renderLiveButton(cams);
    // F77.3: el push SSE llega ~1/s; recrear el DOM del directo reiniciaba el
    // <img> MJPEG cada segundo (imagen a tirones). Solo se recrea si cambia algo
    // relevante y no estamos en modo reproducción.
    var sig = cams.map(function (c) {
      return [c.id, c.position, c.label, c.enabled, c.recording, c.mjpeg_url, c.live_url].join('|');
    }).join(';;');
    if (box.dataset && box.dataset.mode === 'live' && box.dataset.sig === sig) {
      return;
    }
    box.dataset.mode = 'live';
    box.dataset.sig = sig;
    if (!cams.length) {
      box.innerHTML = '<div class="muted">Sin cámaras configuradas para este almacén.</div>';
      return;
    }
    var anyOff = cams.some(function (c) { return c.enabled === false; });
    var warn = anyOff
      ? '<div class="cams-warn">Cámaras apagadas. Enciéndelas para ver el directo.'
        + ' <button class="ghost" onclick="Almacen.ensureCamerasLive()">Encender cámaras</button></div>'
      : '';
    box.innerHTML = warn + cams.map(function (c) {
      var label = c.label || c.position;
      var rec = c.recording ? '<span class="rec"><span class="dot"></span>GRABANDO</span>' : '<span class="muted">—</span>';
      var body;
      if (!c.enabled) {
        body = '<div class="ph">cámara desactivada</div>';
      } else if (c.mjpeg_url) {
        // F70/RF-80.5: directo MJPEG con <img>. Si falla, placeholder "sin señal".
        body = '<img class="mjpeg" src="' + esc(c.mjpeg_url) + '" alt="Directo ' + esc(label) + '"'
          + ' onload="this.parentNode.classList.remove(\'is-offline\')"'
          + ' onerror="this.parentNode.classList.add(\'is-offline\')">'
          + '<div class="ph live-off">sin señal</div>';
      } else if (c.live_url) {
        // Fallback (go2rtc) si no hay base MJPEG configurada.
        body = '<iframe src="' + esc(c.live_url) + '" allow="autoplay" loading="lazy"></iframe>';
      } else {
        body = '<div class="ph">directo no disponible</div>';
      }
      return '<div class="cam"><div class="bar"><span>' + esc(label) + ' <span class="pill">' + esc(c.position) + '</span></span>' + rec + '</div>' + body + '</div>';
    }).join('');
  }

  // F69/RF-79: estado visual del botón "Ver en directo".
  function renderLiveButton(cams) {
    var btn = $('btn-live');
    if (!btn) { return; }
    cams = cams || (state && state.cameras) || [];
    var anyOff = cams.some(function (c) { return c.enabled === false; });
    btn.classList.toggle('is-live', !playback && !anyOff);
    btn.classList.toggle('is-off', !playback && anyOff);
    btn.classList.toggle('is-playing', !!playback);
    btn.title = playback
      ? 'Volver al estado actual y al directo'
      : (anyOff ? 'Encender cámaras y ver el directo' : 'Viendo el estado actual en directo');
  }

  // ---- croquis (F67/RF-77) ------------------------------------------------
  function setChip(id, text, mod) {
    var el = $(id);
    if (!el) { return; }
    el.textContent = text;
    el.className = 'chip' + (mod ? ' ' + mod : '');
  }
  function fmtClock(ts) {
    if (!ts) { return '—'; }
    var d = new Date(String(ts).replace(' ', 'T') + (String(ts).indexOf('Z') < 0 ? 'Z' : ''));
    return isNaN(d) ? String(ts) : d.toLocaleTimeString();
  }
  function fmtDur(s) {
    if (s == null) { return '—'; }
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    var mm = (m < 10 ? '0' : '') + m, ss = (x < 10 ? '0' : '') + x;
    return (h > 0 ? h + ':' + mm : mm) + ':' + ss;
  }
  function elapsedSeconds(v) {
    if (!v) { return null; }
    if (v.entered_at) {
      var raw = String(v.entered_at);
      var iso = raw.replace(' ', 'T') + (/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw) ? '' : 'Z');
      var t = Date.parse(iso);
      if (!isNaN(t)) { return Math.max(0, Math.floor((Date.now() - t) / 1000)); }
    }
    return (typeof v.seconds_inside === 'number') ? v.seconds_inside : null;
  }

  var PERSON_CLASSES = ['pos-outside', 'pos-near', 'pos-qr', 'pos-crossing', 'pos-inside'];

  /**
   * Aplica un "view model" del croquis al SVG. Lo comparten el modo en vivo
   * (F67) y la reproducción de una visita (F68).
   */
  function applyCroquisView(vm) {
    var svg = $('croquis-svg');
    if (!svg) { return; }
    for (var i = 0; i < PERSON_CLASSES.length; i++) { svg.classList.remove(PERSON_CLASSES[i]); }
    svg.classList.add('pos-' + (vm.personPos || 'outside'));
    svg.classList.toggle('is-open', !!vm.doorOpen);
    svg.classList.toggle('is-occupied', !!vm.personInside);
    svg.classList.toggle('is-outside', !vm.personInside);
    svg.classList.toggle('is-unknown', !!vm.unknown);
    svg.classList.toggle('is-light-on', !!vm.lightOn);
    svg.classList.toggle('is-scanning', !!vm.scanning);
    if (vm.chips) {
      setChip('croquis-door-chip', vm.chips.door.text, vm.chips.door.mod);
      setChip('croquis-presence-chip', vm.chips.presence.text, vm.chips.presence.mod);
      setChip('croquis-light-chip', vm.chips.light.text, vm.chips.light.mod);
    }
    if (vm.desc != null) {
      var desc = $('croquis-desc');
      if (desc) { desc.textContent = vm.desc; }
    }
  }

  function renderCroquis() {
    if (!state) { return; }
    var wh = state.warehouse || {};
    var C = window.CroquisLogic;
    if (!C || typeof C.deriveCroquis !== 'function') { return; }
    var d = C.deriveCroquis({ occupied: !!wh.occupied, live: state.live || {} }, Date.now());

    applyCroquisView({
      personPos: d.personInside ? 'inside' : 'outside',
      doorOpen: d.doorOpen,
      personInside: d.personInside,
      unknown: d.unknown,
      lightOn: d.classes.lightOn,
      scanning: false,
      chips: d.chips,
      desc: d.desc
    });

    var room = $('croquis-room');
    if (room) { room.textContent = state.room ? state.room.code : '—'; }

    var svg = $('croquis-svg');
    (state.cameras || []).forEach(function (c) {
      var g = svg.querySelector('.croquis-cam[data-position="' + c.position + '"]');
      if (!g) { return; }
      g.style.display = '';
      g.classList.toggle('is-recording', !!c.recording);
      g.classList.toggle('is-off', c.enabled === false);
    });

    renderCroquisMeta();
  }

  function renderCroquisMeta() {
    var el = $('croquis-meta');
    if (!el || !state) { return; }
    var wh = state.warehouse || {};
    var live = state.live || {};
    var v = wh.current_visit;
    if (wh.occupied && v) {
      var who = v.worker ? v.worker.name : 'anónimo';
      el.textContent = 'Dentro: ' + who + ' · ' + fmtDur(elapsedSeconds(v)) + ' dentro';
    } else if (live.last_absent_since || live.last_close_at) {
      el.textContent = 'Última salida: ' + fmtClock(live.last_absent_since || live.last_close_at);
    } else {
      el.textContent = 'Sin actividad reciente';
    }
  }

  // ---- reproducción de visitas (F68/RF-78) --------------------------------
  function chooseMaster(tl) {
    var ext = null, longest = null;
    tl.clips.forEach(function (c) {
      if (!c.hasVideo) { return; }
      if (!longest || c.durationMs > longest.durationMs) { longest = c; }
      if (c.position === 'EXTERIOR' && (!ext || c.durationMs > ext.durationMs)) { ext = c; }
    });
    return ext || longest;
  }

  function elapsedNow() {
    if (!playback) { return 0; }
    if (playback.masterEl) {
      return playback.master.startMs + playback.masterEl.currentTime * 1000;
    }
    return playback.baseElapsed + (playback.playing
      ? (performance.now() - playback.basePerf) * playback.speed : 0);
  }

  function updatePlayButton() {
    var b = $('play-toggle');
    if (b) { b.textContent = (playback && playback.playing) ? '⏸' : '▶'; }
  }

  function updateSpeeds() {
    var wrap = $('play-speeds');
    if (!wrap || !playback) { return; }
    var btns = wrap.querySelectorAll('.speed');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', Number(btns[i].dataset.speed) === playback.speed);
    }
  }

  function renderPlaybackBand(tl, visit) {
    $('play-band').hidden = false;
    var who = visit.worker ? visit.worker.name : 'anónimo';
    $('play-title').textContent = 'Visita #' + tl.visitId + ' · ' + who + (tl.outcomeLabel ? ' · ' + tl.outcomeLabel : '');
    var startWall = tl.markers.length ? tl.markers[0].wall : VisitPlayback.formatClock(tl.originMs);
    var endWall = VisitPlayback.formatClock(tl.endMs);
    $('play-window').textContent = 'Inicio ' + startWall + ' · Salida ' + endWall
      + (tl.hasRecordings ? '' : ' · sin grabación');
    $('play-markers').innerHTML = tl.markers.map(function (m) {
      var pct = tl.durationMs > 0 ? (m.ms / tl.durationMs * 100) : 0;
      return '<button class="play-marker" style="left:' + pct.toFixed(2) + '%"'
        + ' onclick="Almacen.seekToMs(' + Math.round(m.ms) + ')"'
        + ' title="' + esc(m.label + ' ' + m.wall) + '">' + m.icon + ' ' + esc(m.wall) + '</button>';
    }).join('');
    updateSpeeds();
    updatePlayButton();
  }

  function renderCamerasReplay(tl) {
    var box = $('cams');
    var cams = (state && state.cameras) || [];
    playback.videos = {};
    // F77.3: marca el DOM como reproducción para forzar el rebuild al volver al directo.
    if (box.dataset) { box.dataset.mode = 'replay'; }
    box.innerHTML = playback.positions.map(function (pos) {
      var cam = null;
      for (var i = 0; i < cams.length; i++) { if (cams[i].position === pos) { cam = cams[i]; break; } }
      var label = (cam && cam.label) ? cam.label : pos;
      var hasClip = tl.clips.some(function (c) { return c.position === pos && c.hasVideo; });
      var body = (hasClip ? '<video class="replay-screen" muted playsinline preload="auto"></video>' : '')
        + '<div class="replay-empty"' + (hasClip ? ' hidden' : '') + '>Sin grabación en este tramo</div>';
      return '<div class="cam" data-pos="' + pos + '"><div class="bar"><span>' + esc(label)
        + ' <span class="pill">' + pos + '</span></span><span class="muted">visita #' + tl.visitId + '</span></div>'
        + '<div class="replay-body">' + body + '</div></div>';
    }).join('');
    playback.positions.forEach(function (pos) {
      var v = box.querySelector('.cam[data-pos="' + pos + '"] video');
      if (v) { playback.videos[pos] = v; }
    });
    var svg = $('croquis-svg');
    if (svg) {
      var gs = svg.querySelectorAll('.croquis-cam');
      for (var j = 0; j < gs.length; j++) { gs[j].classList.remove('is-recording'); }
    }
  }

  function renderPlaybackFrame(elapsedMs) {
    if (!playback) { return; }
    var tl = playback.timeline;
    var f = VisitPlayback.frameAt(tl, tl.originMs + elapsedMs);
    applyCroquisView({
      personPos: f.personPos,
      doorOpen: f.doorOpen,
      personInside: f.personInside,
      unknown: false,
      lightOn: f.lightOn,
      scanning: f.phase === 'scan',
      chips: f.chips,
      desc: f.desc
    });
    $('play-clock').textContent = f.wallClockLabel;
    $('play-elapsed').textContent = f.elapsedLabel;
    $('play-inside').textContent = f.insideLabel;
    var ph = $('play-phase');
    ph.textContent = f.phaseLabel;
    ph.className = 'chip ' + (f.personInside ? 'ok' : (f.doorOpen ? 'warn' : 'dim'));
    var range = $('play-range');
    range.value = tl.durationMs > 0 ? Math.round(elapsedMs / tl.durationMs * 1000) : 0;
    playback.lastFrame = f;
  }

  function syncReplayVideos(frame) {
    if (!playback || !frame) { return; }
    playback.positions.forEach(function (pos) {
      var vid = playback.videos[pos];
      if (!vid) { return; }
      var act = frame.activeClips[pos];
      var empty = vid.parentNode ? vid.parentNode.querySelector('.replay-empty') : null;
      if (empty) { empty.hidden = !!(act && act.videoUrl); }

      // El clip maestro marca el reloj: no se le toca `src` ni `currentTime`
      // (solo en `seekToMs`), para no corromper la línea de tiempo.
      if (pos === playback.masterPos) {
        if (act && act.videoUrl) {
          if (playback.playing) {
            var pm = vid.play();
            if (pm && pm.catch) { pm.catch(function () {}); }
          } else if (!vid.paused) { vid.pause(); }
        } else if (!vid.paused) { vid.pause(); }
        return;
      }

      if (act && act.videoUrl) {
        if (vid.dataset.clip !== String(act.id)) {
          vid.dataset.clip = String(act.id);
          vid.src = act.videoUrl;
          try { vid.load(); } catch (e) {}
        }
        var target = act.localTimeMs / 1000;
        if (Math.abs((vid.currentTime || 0) - target) > 0.4) {
          try { vid.currentTime = target; } catch (e) {}
        }
        if (vid.playbackRate !== playback.speed) { vid.playbackRate = playback.speed; }
        if (playback.playing) {
          var p = vid.play();
          if (p && p.catch) { p.catch(function () {}); }
        } else if (!vid.paused) { vid.pause(); }
      } else {
        if (!vid.paused) { vid.pause(); }
      }
    });
  }

  function tick() {
    if (!playback) { return; }
    var tl = playback.timeline;
    var elapsed = elapsedNow();
    if (elapsed >= tl.durationMs) {
      elapsed = tl.durationMs;
      if (playback.playing) { pausePlayback(); }
    }
    renderPlaybackFrame(elapsed);
    syncReplayVideos(playback.lastFrame);
    playback.raf = requestAnimationFrame(tick);
  }

  function play() {
    if (!playback) { return; }
    if (playback.masterEl) {
      var p = playback.masterEl.play();
      if (p && p.catch) { p.catch(function () {}); }
    } else {
      playback.basePerf = performance.now();
    }
    playback.playing = true;
    updatePlayButton();
  }

  function pausePlayback() {
    if (!playback) { return; }
    if (playback.masterEl) { playback.masterEl.pause(); }
    else { playback.baseElapsed = elapsedNow(); }
    playback.playing = false;
    playback.positions.forEach(function (pos) {
      var v = playback.videos[pos];
      if (v && !v.paused) { v.pause(); }
    });
    updatePlayButton();
  }

  function seekToMs(ms) {
    if (!playback) { return; }
    var dur = playback.timeline.durationMs;
    ms = Math.max(0, Math.min(ms, dur));
    if (playback.masterEl) {
      try { playback.masterEl.currentTime = (ms - playback.master.startMs) / 1000; } catch (e) {}
    } else {
      playback.baseElapsed = ms;
      playback.basePerf = performance.now();
    }
    renderPlaybackFrame(ms);
    syncReplayVideos(playback.lastFrame);
  }

  function setSpeed(s) {
    if (!playback) { return; }
    if (!playback.masterEl && playback.playing) {
      playback.baseElapsed = elapsedNow();
      playback.basePerf = performance.now();
    }
    playback.speed = s;
    if (playback.masterEl) { playback.masterEl.playbackRate = s; }
    playback.positions.forEach(function (pos) {
      var v = playback.videos[pos];
      if (v) { v.playbackRate = s; }
    });
    updateSpeeds();
  }

  function togglePlay() {
    if (!playback) { return; }
    if (playback.playing) { pausePlayback(); } else { play(); }
  }

  function playVisit(id) {
    exitPlayback();
    api('/almacen-api/visits/' + id).then(function (j) {
      var visit = j.visit;
      var tl = VisitPlayback.buildVisitTimeline(visit);
      playback = {
        visit: visit, timeline: tl,
        positions: ['EXTERIOR', 'INTERIOR'], videos: {},
        master: null, masterEl: null, masterPos: null,
        playing: false, speed: 1, raf: 0, lastFrame: null,
        baseElapsed: 0, basePerf: performance.now()
      };
      renderPlaybackBand(tl, visit);
      renderCamerasReplay(tl);
      renderLiveButton();
      var master = chooseMaster(tl);
      if (master) {
        playback.master = master;
        playback.masterPos = master.position;
        var mv = playback.videos[master.position];
        if (mv) {
          mv.dataset.clip = String(master.id);
          mv.src = master.videoUrl;
          try { mv.load(); } catch (e) {}
          playback.masterEl = mv;
        }
      }
      seekToMs(0);
      play();
      playback.raf = requestAnimationFrame(tick);
      var band = $('play-band');
      if (band && band.scrollIntoView) { band.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    }).catch(function (e) { toast('Reproducir: ' + e.message); });
  }

  function stopPlaybackIfAny() {
    if (!playback) { return; }
    if (playback.raf) { cancelAnimationFrame(playback.raf); }
    playback.positions.forEach(function (pos) {
      var v = playback.videos[pos];
      if (v) {
        if (!v.paused) { v.pause(); }
        v.removeAttribute('src');
        try { v.load(); } catch (e) {}
      }
    });
    playback = null;
    var band = $('play-band');
    if (band) { band.hidden = true; }
    var range = $('play-range');
    if (range) { range.value = 0; }
    renderLiveButton();
  }

  function exitPlayback() {
    if (!playback) { return; }
    stopPlaybackIfAny();
    renderCroquis();
    renderCameras();
  }

  /**
   * F69/RF-79: enciende las cámaras apagadas y sincroniza go2rtc.
   * NO toca `record_enabled` (la política de grabación no cambia).
   */
  function ensureCamerasLive() {
    var cams = (state && state.cameras) || [];
    var off = cams.filter(function (c) { return c.enabled === false; });
    var chain = Promise.resolve();
    if (off.length) {
      chain = Promise.all(off.map(function (c) {
        return api('/almacen-api/cameras/' + c.id, {
          method: 'PATCH', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ enabled: true })
        });
      }));
    }
    return chain
      .then(function () { return api('/almacen-api/cameras/sync', { method: 'POST' }); })
      .then(function () { pollState(); return null; });
  }

  /** F69/RF-79: vuelve al estado actual (croquis de sensores + directo). */
  function goLive() {
    stopPlaybackIfAny();
    var cams = (state && state.cameras) || [];
    var anyOff = cams.some(function (c) { return c.enabled === false; });
    renderLiveButton(cams);
    renderCroquis();
    renderCameras();
    if (anyOff) {
      toast('Encendiendo cámaras…');
      ensureCamerasLive()
        .then(function () { toast('Cámaras encendidas · en directo'); })
        .catch(function (e) { toast('Cámaras: ' + e.message); });
    } else {
      pollState();
      toast('En directo');
    }
    var band = $('play-band');
    if (band) { band.hidden = true; }
  }

  function outcomeTag(o) {
    var cls = o === 'ENTERED' ? 'ok' : (o === 'NO_SHOW' ? 'noshow' : 'discard');
    return '<span class="tag ' + cls + '">' + esc(o) + '</span>';
  }

  function fmt(ts) {
    if (!ts) { return '—'; }
    var d = new Date(String(ts).replace(' ', 'T') + (String(ts).indexOf('Z') < 0 ? 'Z' : ''));
    return isNaN(d) ? ts : d.toLocaleString();
  }

  function loadVisits() {
    if (!state || !state.room) { $('visits').innerHTML = ''; return; }
    var outcome = $('f-outcome') ? $('f-outcome').value : '';
    var q = '?room_id=' + state.room.id + '&limit=50' + (outcome ? '&outcome=' + outcome : '');
    api('/almacen-api/visits' + q).then(function (j) {
      var rows = (j.visits || []).map(function (v) {
        var who = v.worker ? v.worker.name : 'anónimo';
        return '<tr class="click" onclick="Almacen.selectVisit(' + v.id + ')">'
          + '<td>' + fmt(v.created_at) + '</td>'
          + '<td>' + esc(who) + '</td>'
          + '<td>' + esc(v.entry_trigger) + '</td>'
          + '<td>' + outcomeTag(v.outcome) + '</td>'
          + '<td>' + fmt(v.entered_at) + '</td>'
          + '<td>' + fmt(v.exited_at) + '</td>'
          + '<td><button class="ghost visit-play" onclick="event.stopPropagation();Almacen.playVisit('
          + v.id + ')">▶ Reproducir</button></td></tr>';
      }).join('');
      $('visits').innerHTML =
        '<table><thead><tr><th>Fecha</th><th>Empleado</th><th>Disparo</th><th>Resultado</th><th>Entrada</th><th>Salida</th><th></th></tr></thead><tbody>'
        + (rows || '<tr><td colspan="7" class="muted">Sin visitas</td></tr>') + '</tbody></table>'
        + '<div id="visit-detail"></div>';
    }).catch(function (e) { toast('Visitas: ' + e.message); });
  }

  function selectVisit(id) {
    api('/almacen-api/visits/' + id).then(function (j) {
      var v = j.visit;
      var recs = v.recordings || [];
      var clips = recs.map(function (r) {
        var cap = '<div class="cap"><span>' + esc(r.position) + ' · ' + esc(r.episode) + '</span><span>' + esc(r.status) + '</span></div>';
        var media;
        if (r.video_url) {
          media = '<video controls preload="metadata"' + (r.poster_url ? ' poster="' + esc(r.poster_url) + '"' : '') + ' src="' + esc(r.video_url) + '"></video>';
        } else {
          media = '<div class="ph" style="aspect-ratio:16/9;border:1px solid var(--line);border-radius:8px">' + esc(r.status) + '</div>';
        }
        return '<div class="clip">' + media + cap + '</div>';
      }).join('');
      var who = v.worker ? v.worker.name : 'anónimo';
      $('visit-detail').innerHTML =
        '<h3 style="margin:16px 0 6px">Visita #' + v.id + ' · ' + esc(who) + ' ' + outcomeTag(v.outcome) + '</h3>'
        + '<div class="muted">QR: ' + fmt(v.qr_at) + ' · Entrada: ' + fmt(v.entered_at) + ' · Salida: ' + fmt(v.exited_at) + '</div>'
        + '<div style="margin:10px 0"><button onclick="Almacen.playVisit(' + v.id + ')">▶ Reproducir visita</button></div>'
        + '<div class="clip-grid">' + (clips || '<div class="muted">Sin grabaciones</div>') + '</div>';
      $('visit-detail').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function (e) { toast('Visita: ' + e.message); });
  }

  function loadAccess(force) {
    if (!state || !state.room) { return; }
    if (access && !force && access.room_type && access.room_type.id === state.room.room_type_id) { return; }
    api('/almacen-api/access?room_type_id=' + state.room.room_type_id).then(function (j) {
      access = j;
      renderAccess();
    }).catch(function (e) { toast('Permisos: ' + e.message); });
  }

  function renderAccess() {
    if (!access) { return; }
    $('roles').innerHTML = '<div class="muted" style="margin-bottom:6px">Por rol</div>' + (access.roles || []).map(function (r) {
      var cls = r.allowed ? 'on' : 'off';
      var txt = r.allowed ? 'PERMITIDO' : 'DENEGADO';
      return '<div class="roleline"><span>' + esc(r.name) + '</span>'
        + '<span class="switch ' + cls + '" onclick="Almacen.toggleRole(' + r.id + ',' + (!r.allowed) + ')">' + txt + '</span></div>';
    }).join('');
    renderWorkers();
  }

  function renderWorkers() {
    if (!access) { return; }
    var term = ($('w-search').value || '').toLowerCase();
    var rows = (access.workers || []).filter(function (w) { return w.name.toLowerCase().indexOf(term) >= 0; }).map(function (w) {
      var eff = w.effective ? 'on' : 'off';
      return '<div class="roleline"><span>' + esc(w.name)
        + ' <span class="muted" style="font-size:12px">' + (w.override ? ('excepción ' + esc(w.override)) : ('rol ' + (w.role_allowed ? 'permite' : 'deniega'))) + '</span></span>'
        + '<span><select onchange="Almacen.setWorker(' + w.id + ', this.value)">'
        + '<option value="">— rol</option>'
        + '<option value="ALLOW"' + (w.override === 'ALLOW' ? ' selected' : '') + '>Permitir</option>'
        + '<option value="DENY"' + (w.override === 'DENY' ? ' selected' : '') + '>Denegar</option>'
        + '</select> <span class="switch ' + eff + '">' + (w.effective ? '✓' : '✕') + '</span></span></div>';
    }).join('');
    $('workers').innerHTML = rows || '<div class="muted">Sin empleados</div>';
  }

  function toggleRole(roleId, allow) {
    api('/almacen-api/access/role/' + roleId, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_type_id: state.room.room_type_id, allow: allow })
    }).then(function () { return loadAccess(true); })
      .then(function () { toast('Rol actualizado'); })
      .catch(function (e) { toast('Rol: ' + e.message); });
  }

  function setWorker(workerId, effect) {
    api('/almacen-api/access/worker/' + workerId, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_type_id: state.room.room_type_id, effect: effect || null })
    }).then(function () { return loadAccess(true); })
      .then(function () { toast('Empleado actualizado'); })
      .catch(function (e) { toast('Empleado: ' + e.message); });
  }

  function openDoor() {
    if (!state || !state.room) { return; }
    api('/almacen-api/door/open', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_id: state.room.id })
    }).then(function () { toast('Puerta: apertura solicitada'); })
      .catch(function (e) { toast('Puerta: ' + e.message); });
  }

  function syncCameras() {
    api('/almacen-api/cameras/sync', { method: 'POST' })
      .then(function (j) { toast('Cámaras: ' + (j.streams || 0) + ' streams'); })
      .catch(function (e) { toast('Cámaras: ' + e.message); });
  }

  // ---- data flow ----------------------------------------------------------
  function applyState(s) {
    var first = state === null;
    state = s;
    renderHeader();
    // F68: en modo reproducción no se pisa el croquis ni las cámaras con el directo.
    if (!playback) {
      renderCroquis();
      renderCameras();
    }
    if (state && state.room) {
      loadAccess(false);
      if (first) { loadVisits(); }
    }
  }

  function pollState() {
    api('/almacen-api/state' + (ROOM_ID ? '?room_id=' + ROOM_ID : ''))
      .then(applyState)
      .catch(function () { /* keep trying */ });
  }

  // F75/RF-88: relectura puntual de sensores (UNA llamada Tuya con cooldown en
  // servidor). No es periódica: se invoca al abrir el panel y con el botón.
  function refreshSensors(silent) {
    return api('/almacen-api/sensors/refresh', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(ROOM_ID ? { room_id: ROOM_ID } : {})
    }).then(function (r) {
      if (r && r.state) { applyState(r.state); }
      if (!silent) {
        if (r && r.probed) { toast('Sensores actualizados'); }
        else if (r && r.reason === 'throttled') { toast('Lectura reciente (cooldown)'); }
        else { toast('Sensores: ' + ((r && r.reason) || 'sin cambios')); }
      }
      return r;
    }).catch(function (e) { if (!silent) { toast('Sensores: ' + e.message); } });
  }

  function connectSSE() {
    var q = ROOM_ID ? ('?room_id=' + ROOM_ID) : '';
    try { sse = new EventSource('/almacen-api/event-stream' + q); }
    catch (e) { startPolling(); return; }
    sse.addEventListener('state', function (ev) {
      try { applyState(JSON.parse(ev.data)); } catch (e) {}
    });
    sse.addEventListener('ping', function () { stopPolling(); });
    sse.onerror = function () {
      if (sse) { sse.close(); sse = null; }
      startPolling();
      setTimeout(function () { stopPolling(); connectSSE(); }, 5000);
    };
  }

  function startPolling() {
    if (pollTimer) { return; }
    pollState();
    pollTimer = setInterval(pollState, 2000);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  window.Almacen = {
    selectVisit: selectVisit, toggleRole: toggleRole, setWorker: setWorker,
    openDoor: openDoor, syncCameras: syncCameras, loadVisits: loadVisits,
    renderWorkers: renderWorkers,
    playVisit: playVisit, exitPlayback: exitPlayback, togglePlay: togglePlay,
    setSpeed: setSpeed, seekToMs: seekToMs,
    goLive: goLive, ensureCamerasLive: ensureCamerasLive,
    refreshSensors: refreshSensors
  };

  var range = $('play-range');
  if (range) {
    range.addEventListener('input', function () {
      if (!playback) { return; }
      seekToMs((Number(range.value) / 1000) * playback.timeline.durationMs);
    });
  }

  pollState();
  connectSSE();
  // F75/RF-88: UNA lectura de sensores al abrir el panel (cooldown en servidor).
  refreshSensors(true);
  setInterval(function () { if (state && state.room) { loadVisits(); } }, 15000);
  // F67/RF-77: el segundero de "tiempo dentro" avanza sin recargar; solo
  // reescribe el texto de #croquis-meta (fuera del aria-live). En reproducción
  // el reloj lo gobierna `tick()`.
  setInterval(function () {
    if (!playback && state && state.warehouse && state.warehouse.occupied) { renderCroquisMeta(); }
  }, 1000);
})();
