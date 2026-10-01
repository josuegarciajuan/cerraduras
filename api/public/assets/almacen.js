/* Almacen — panel único del almacén de bebidas (F65/RF-74).
 * Sin dependencias. Estado por SSE con fallback a polling. */
(function () {
  'use strict';

  var ROOM_ID = parseInt((new URLSearchParams(location.search)).get('room') || '0', 10) || 0;
  var state = null;
  var access = null;
  var sse = null;
  var pollTimer = null;

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
    if (!cams.length) {
      box.innerHTML = '<div class="muted">Sin cámaras configuradas para este almacén.</div>';
      return;
    }
    box.innerHTML = cams.map(function (c) {
      var label = c.label || c.position;
      var rec = c.recording ? '<span class="rec"><span class="dot"></span>GRABANDO</span>' : '<span class="muted">—</span>';
      var body = (c.enabled && c.live_url)
        ? '<iframe src="' + esc(c.live_url) + '" allow="autoplay" loading="lazy"></iframe>'
        : '<div class="ph">' + (c.enabled ? 'directo no disponible' : 'cámara desactivada') + '</div>';
      return '<div class="cam"><div class="bar"><span>' + esc(label) + ' <span class="pill">' + esc(c.position) + '</span></span>' + rec + '</div>' + body + '</div>';
    }).join('');
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

  function renderCroquis() {
    var svg = $('croquis-svg');
    if (!svg || !state) { return; }
    var wh = state.warehouse || {};
    var C = window.CroquisLogic;
    if (!C || typeof C.deriveCroquis !== 'function') { return; }
    var d = C.deriveCroquis({ occupied: !!wh.occupied, live: state.live || {} }, Date.now());

    svg.classList.toggle('is-open', d.classes.open);
    svg.classList.toggle('is-occupied', d.classes.occupied);
    svg.classList.toggle('is-outside', d.classes.outside);
    svg.classList.toggle('is-unknown', d.classes.unknown);
    svg.classList.toggle('is-light-on', d.classes.lightOn);

    setChip('croquis-door-chip', d.chips.door.text, d.chips.door.mod);
    setChip('croquis-presence-chip', d.chips.presence.text, d.chips.presence.mod);
    setChip('croquis-light-chip', d.chips.light.text, d.chips.light.mod);

    var room = $('croquis-room');
    if (room) { room.textContent = state.room ? state.room.code : '—'; }
    var desc = $('croquis-desc');
    if (desc) { desc.textContent = d.desc; }

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
          + '<td>' + fmt(v.exited_at) + '</td></tr>';
      }).join('');
      $('visits').innerHTML =
        '<table><thead><tr><th>Fecha</th><th>Empleado</th><th>Disparo</th><th>Resultado</th><th>Entrada</th><th>Salida</th></tr></thead><tbody>'
        + (rows || '<tr><td colspan="6" class="muted">Sin visitas</td></tr>') + '</tbody></table>'
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
    renderCroquis();
    renderCameras();
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
    renderWorkers: renderWorkers
  };

  pollState();
  connectSSE();
  setInterval(function () { if (state && state.room) { loadVisits(); } }, 15000);
  // F67/RF-77: el segundero de "tiempo dentro" avanza sin recargar; solo
  // reescribe el texto de #croquis-meta (fuera del aria-live).
  setInterval(function () {
    if (state && state.warehouse && state.warehouse.occupied) { renderCroquisMeta(); }
  }, 1000);
})();
