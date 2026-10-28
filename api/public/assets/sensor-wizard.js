/**
 * sensor-wizard.js — F94/RF-159: asistente de verificación física de sensores.
 *
 * La UI solo presenta pasos y botones; la validación real la hace el servidor
 * (`/dashboard-api/sensores/step/check` → SensorCalibrationValidator).
 * Sin frameworks, sin procesos de fondo. El refresco de eventos es una lectura
 * local a la BD y solo ocurre con la pestaña visible.
 */
(function () {
  'use strict';

  var LS_OP = 'sensores:operator';
  var LS_RUN = 'sensores:run:';

  var catalog = null;
  var run = null;
  var currentId = null;
  var ctx = null;
  var eventTimer = null;
  var busy = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function roomId() { return parseInt($('roomId').value, 10) || 0; }
  function operator() { return ($('operator').value || '').trim(); }
  function stepById(id) {
    return (catalog.tests || []).filter(function (t) { return t.id === id; })[0] || null;
  }
  function blockName(id) {
    var b = (catalog.blocks || []).filter(function (x) { return String(x.id) === String(id); })[0];
    return b ? b.name : String(id);
  }
  function statusOf(id) {
    var s = run && run.steps && run.steps[id];
    return s && s.status ? s.status : 'PENDING';
  }
  function pendingIds() {
    return (catalog.tests || []).map(function (t) { return t.id; })
      .filter(function (id) { return statusOf(id) === 'PENDING'; });
  }
  function nextFrom(id) {
    var ids = (catalog.tests || []).map(function (t) { return t.id; });
    var i = ids.indexOf(id);
    for (var k = i + 1; k < ids.length; k++) {
      if (statusOf(ids[k]) === 'PENDING') return ids[k];
    }
    return pendingIds()[0] || null;
  }
  function prevFrom(id) {
    var ids = (catalog.tests || []).map(function (t) { return t.id; });
    var i = ids.indexOf(id);
    for (var k = i - 1; k >= 0; k--) return ids[k];
    return null;
  }

  function toast(msg) {
    var el = $('toast');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.add('hidden'); }, 3500);
  }

  async function api(url, opts) {
    var res = await fetch(url, opts || {});
    var data = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  }

  // ── catálogo / corrida ───────────────────────────────────────────────
  async function loadCatalog() {
    catalog = await api('assets/sensor-tests.json', { cache: 'no-store' });
    $('roomId').value = catalog.room_id || 14;
    $('operator').value = localStorage.getItem(LS_OP) || '';
  }

  async function loadRun() {
    var id = localStorage.getItem(LS_RUN + roomId());
    if (!id) return null;
    try {
      var data = await api('/dashboard-api/sensores/get?id=' + encodeURIComponent(id));
      if (!data || data.room_id !== roomId()) return null;
      return data;
    } catch (e) { return null; }
  }

  async function startRun() {
    if (!roomId()) { toast('Indica la sala'); return; }
    localStorage.setItem(LS_OP, operator());
    var data = await api('/dashboard-api/sensores/run/start', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_id: roomId(), operator: operator() })
    });
    run = data.run;
    localStorage.setItem(LS_RUN + roomId(), run.run_id);
    currentId = (catalog.tests || [])[0].id;
    await refreshContext();
    await ensureStepStarted(currentId);
    render();
    toast('Corrida iniciada: ' + run.run_id);
  }

  async function ensureStepStarted(id) {
    if (!run || !id) return;
    var st = run.steps && run.steps[id];
    if (st && st.started_at) return;
    try {
      var data = await api('/dashboard-api/sensores/step/start', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: run.run_id, step_id: id })
      });
      run.steps = run.steps || {};
      run.steps[id] = data.step;
    } catch (e) { toast('No se pudo iniciar el paso: ' + e.message); }
  }

  async function resetStep() {
    if (!run || !currentId) return;
    if (!confirm('¿Reiniciar el paso ' + currentId + '? Se descarta su resultado y empieza la ventana ahora.')) return;
    var data = await api('/dashboard-api/sensores/step/start', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ run_id: run.run_id, step_id: currentId, reset: true })
    });
    run.steps[currentId] = data.step;
    render();
    toast('Paso reiniciado');
  }

  // ── validación ───────────────────────────────────────────────────────
  async function checkStep(override) {
    if (!run || !currentId || busy) return;
    busy = true;
    setButtons(false);
    try {
      var data = await api('/dashboard-api/sensores/step/check', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          run_id: run.run_id, step_id: currentId,
          override: override || null,
          notes: ($('notes') ? $('notes').value : '')
        })
      });
      await reloadRun();
      render();
      var v = data.verdict || {};
      toast(data.status + ' · ' + (v.reason || ''));
    } catch (e) {
      toast('Error al validar: ' + e.message);
    } finally {
      busy = false;
      setButtons(true);
    }
  }

  async function reloadRun() {
    run = await api('/dashboard-api/sensores/get?id=' + encodeURIComponent(run.run_id));
  }

  function setButtons(on) {
    ['btnValidate', 'btnFail', 'btnSkip', 'btnNext', 'btnPrev', 'btnReset'].forEach(function (id) {
      var el = $(id);
      if (el) el.disabled = !on;
    });
  }

  // ── render ───────────────────────────────────────────────────────────
  function render() {
    renderHeader();
    renderStrip();
    renderDetail();
    renderSummary();
  }

  function renderHeader() {
    $('runId').textContent = run ? run.run_id : '—';
    var t = (ctx && ctx.transport) || (run && run.calibration && run.calibration.devices && run.calibration.devices.transport) || '';
    $('transportBadge').textContent = 'transporte: ' + (t || '—');
    $('transportBadge').className = 'badge ' + (t === 'ZIGBEE2MQTT' ? 'ok' : (t ? 'warn' : 'dim'));
    var room = (ctx && ctx.room) || {};
    if (room.room_type_code) {
      $('transportBadge').textContent += ' · ' + room.room_type_code + (room.presence_mode ? ' / ' + room.presence_mode : '');
    }
    var d = ctx && ctx.devices ? ctx.devices : null;
    var p = d && d.presence ? d.presence : null;
    var q = d && d.proximity ? d.proximity : null;
    $('deviceBadge').textContent = 'presencia: ' + (p ? (p.last_seen_at ? 'visto ' + p.last_seen_at : 'sin push') : '—')
      + ' · puerta: ' + (q ? (q.last_seen_at ? 'visto ' + q.last_seen_at : 'sin push') : '—');
    $('deviceBadge').className = 'badge ' + (p && q ? 'ok' : 'warn');
    if (ctx && ctx.live) {
      var l = ctx.live;
      $('liveBadge').textContent = 'puerta ' + (l.door_state || '?') + ' · presencia ' + (l.presence_state || '?')
        + (l.presence_stale ? ' (stale)' : '');
      $('liveBadge').className = 'badge ' + (l.presence_stale ? 'warn' : 'dim');
    }
  }

  function renderStrip() {
    var el = $('strip');
    el.innerHTML = '';
    (catalog.tests || []).forEach(function (t) {
      var c = document.createElement('span');
      c.className = 'cell ' + statusOf(t.id) + (t.id === currentId ? ' current' : '');
      c.title = t.id + ' · ' + t.title + ' · ' + statusOf(t.id);
      c.style.cursor = 'pointer';
      c.onclick = function () { goTo(t.id); };
      el.appendChild(c);
    });
  }

  function renderSummary() {
    var s = run && run.summary ? run.summary : { pass: 0, fail: 0, skip: 0, pending: 0, total: 0 };
    $('summaryText').innerHTML = '<b class="c-pass">' + s.pass + '</b> PASS · <b class="c-fail">' + s.fail + '</b> FAIL · '
      + s.skip + ' SKIP · ' + s.pending + ' pendientes';
    // F94: siempre se puede empezar una corrida nueva; la anterior queda
    // guardada en el servidor y accesible desde "Corridas".
    var btn = $('btnStartRun');
    btn.disabled = false;
    btn.textContent = run ? '➕ Nueva corrida' : '▶ Empezar corrida';
  }

  function renderDetail() {
    var host = $('detail');
    if (!run) {
      host.innerHTML = '<div class="card empty">Pulsa <b>▶ Empezar corrida</b> para iniciar la verificación de la sala seleccionada.</div>';
      return;
    }
    if (!currentId) {
      host.innerHTML = '<div class="card empty">Corrida sin pasos pendientes. Pulsa <b>✔ Finalizar</b> para generar el informe.</div>';
      return;
    }
    var t = stepById(currentId);
    if (!t) { host.innerHTML = '<div class="card empty">Paso no encontrado</div>'; return; }
    var st = run.steps[currentId] || {};
    var v = st.verdict || null;
    var html = '';
    html += '<div class="card">';
    html += '<div class="blockchip">' + esc(t.id) + ' · ' + esc(blockName(t.block)) + ' · ' + esc(t.type || '') + '</div>';
    html += '<h2>' + esc(t.title) + '</h2>';
    html += '<div class="action">' + esc(t.action) + '</div>';
    html += '<div class="expect"><b>Esperado:</b> ' + esc(t.expected) + '</div>';
    if (st.started_at) html += '<div class="hint">Ventana abierta: ' + esc(st.started_at) + '</div>';
    html += '<textarea id="notes" placeholder="Notas opcionales (se guardan en el informe)" style="width:100%;min-height:56px;margin-top:10px;background:#000;border:1px solid var(--line);color:var(--text);border-radius:6px;padding:8px">' + esc(st.notes || '') + '</textarea>';
    html += '<div class="actions">';
    html += '<button id="btnValidate" class="primary">✅ Hecho, validar</button>';
    html += '<button id="btnFail" class="danger">✖ Marcar fallo</button>';
    html += '<button id="btnSkip">⤼ Omitir</button>';
    html += '</div>';
    if (v) {
      html += '<div class="verdict ' + esc(v.status) + '">';
      html += '<h3>' + esc(v.status) + ' · ' + esc(v.reason || '') + '</h3>';
      html += '<div class="hint">Validado: ' + esc(st.checked_at || '—') + ' · ' + (st.elapsed_seconds != null ? st.elapsed_seconds + ' s' : '') + (st.override ? ' · override manual' : '') + '</div>';
      html += '<details><summary>Detalle y evidencia</summary><pre>' + esc(JSON.stringify(v.details || {}, null, 2)) + '</pre>';
      if (st.evidence) html += '<pre>' + esc(JSON.stringify(st.evidence, null, 2)) + '</pre>';
      html += '</details></div>';
    }
    html += '</div>';
    host.innerHTML = html;
    var bv = $('btnValidate'); if (bv) bv.onclick = function () { checkStep(null); };
    var bf = $('btnFail'); if (bf) bf.onclick = function () { checkStep('FAIL'); };
    var bs = $('btnSkip'); if (bs) bs.onclick = function () { checkStep('SKIP'); };
    if (v) { $('btnValidate').textContent = '🔁 Validar de nuevo'; }
  }

  async function goTo(id) {
    if (!run || !stepById(id)) return;
    currentId = id;
    await ensureStepStarted(id);
    render();
  }

  async function goNext() {
    var n = nextFrom(currentId);
    if (!n) { toast('No quedan pasos pendientes'); return; }
    await goTo(n);
  }
  function goPrev() {
    var p = prevFrom(currentId);
    if (p) goTo(p);
  }

  // ── contexto / eventos ───────────────────────────────────────────────
  async function refreshContext() {
    if (!roomId()) return;
    try {
      ctx = await api('/dashboard-api/sensores/context?room_id=' + roomId(), { cache: 'no-store' });
      renderCalibration();
      renderEvents(ctx.events || [], ctx.live || {}, ctx.settings || {});
    } catch (e) {
      toast('Contexto no disponible: ' + e.message);
    }
  }

  async function refreshEvents() {
    if (!roomId()) return;
    try {
      var data = await api('/dashboard-api/sensores/events?room_id=' + roomId(), { cache: 'no-store' });
      ctx = ctx || {};
      ctx.live = data.live;
      renderEvents(data.events || [], data.live || {}, (data.settings && data.settings.settings) || {});
      renderHeader();
    } catch (e) { /* silencioso: es solo diagnóstico */ }
  }

  function fmtTime(ts) {
    if (!ts) return '—';
    var s = String(ts).replace('T', ' ');
    return s.replace(/\..*/, '').replace(/^\d{4}-\d{2}-\d{2} /, '');
  }

  function renderEvents(events, live, settings) {
    var body = $('eventsBody');
    body.innerHTML = '';
    events.forEach(function (e) {
      var tr = document.createElement('tr');
      var applied = e.applied === true;
      var state = applied ? 'aplicado' : (e.applied === false ? 'descartado' : 'pendiente');
      tr.innerHTML = '<td>' + esc(fmtTime(e.occurred_at)) + '</td>'
        + '<td>' + esc(e.sensor) + '</td>'
        + '<td>' + esc(e.value) + (e.radar_state ? '<br><span class="hint">' + esc(e.radar_state) + '</span>' : '') + '</td>'
        + '<td><span class="dot ' + (applied ? 'applied' : (e.applied === false ? 'discarded' : 'pending')) + '"></span> ' + state + '</td>'
        + '<td>' + esc(e.discard_reason || '') + '</td>';
      body.appendChild(tr);
    });
    if (!events.length) {
      body.innerHTML = '<tr><td colspan="5" class="hint">Sin eventos recientes</td></tr>';
    }
    $('eventsMeta').textContent = 'puerta ' + (live.door_state || '?') + ' · presencia ' + (live.presence_state || '?')
      + (live.presence_stale ? ' (stale)' : '')
      + (settings && settings.age_seconds != null ? ' · config ' + settings.age_seconds + ' s' : '');
  }

  // ── calibración ──────────────────────────────────────────────────────
  function renderCalibration() {
    var st = (ctx && ctx.settings) || {};
    var t = (ctx && ctx.transport) || '';
    $('calibHint').textContent = t === 'ZIGBEE2MQTT'
      ? 'Valores reales del radar (último push, ' + (ctx.settings_age_seconds != null ? ctx.settings_age_seconds + ' s' : 'sin datos') + ').'
      : 'Calibración Zigbee no disponible para este transporte.';
    if (st.maximum_range != null) $('calMax').value = st.maximum_range;
    if (st.minimum_range != null) $('calMin').value = st.minimum_range;
    if (st.radar_sensitivity != null) $('calSens').value = st.radar_sensitivity;
    if (st.detection_delay != null) $('calDelay').value = st.detection_delay;
    if (st.fading_time != null) $('calFading').value = st.fading_time;
    var disabled = t !== 'ZIGBEE2MQTT';
    ['calMax', 'calMin', 'calSens', 'calDelay', 'calFading', 'btnCalApply'].forEach(function (id) {
      if ($(id)) $(id).disabled = disabled;
    });
  }

  async function applyCalibration() {
    if (!roomId()) return;
    var body = {
      room_id: roomId(), persist: true,
      far_detection: Math.round(parseFloat($('calMax').value) * 100),
      sensitivity: parseInt($('calSens').value, 10),
      minimum_range_m: parseFloat($('calMin').value),
      detection_delay_s: parseFloat($('calDelay').value),
      fading_time_s: parseFloat($('calFading').value)
    };
    $('calMsg').textContent = 'Aplicando y esperando reporte…';
    try {
      var data = await api('/dashboard-api/presence-calibrate/set', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
      });
      $('calMsg').textContent = data.applied
        ? 'Aplicado y confirmado por el radar.'
        : ('Sin confirmar: ' + (data.warning || 'sin reporte nuevo'));
      await refreshContext();
    } catch (e) {
      $('calMsg').textContent = 'Error: ' + e.message;
    }
  }

  // ── corridas / exportación ───────────────────────────────────────────
  async function finishRun() {
    if (!run) return;
    if (!confirm('¿Finalizar la corrida y generar el informe?')) return;
    try {
      var data = await api('/dashboard-api/sensores/run/finish', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: run.run_id })
      });
      run = data.run;
      render();
      toast('Corrida finalizada · ' + (run.summary.green ? 'VERDE' : 'REVISAR'));
    } catch (e) { toast('No se pudo finalizar: ' + e.message); }
  }

  function exportRun(format) {
    if (!run) { toast('No hay corrida'); return; }
    window.location = '/dashboard-api/sensores/export?id=' + encodeURIComponent(run.run_id) + '&format=' + format;
  }

  async function showRuns() {
    try {
      var list = await api('/dashboard-api/sensores/runs');
      var html = '<h3>Corridas guardadas</h3>';
      if (!list.length) { html += '<p class="hint">Todavía no hay corridas.</p>'; }
      else {
        html += '<table><tr><th>Run</th><th>Sala</th><th>Operador</th><th>Resultado</th><th></th></tr>';
        list.forEach(function (r) {
          var s = r.summary || {};
          html += '<tr><td>' + esc(r.run_id) + '</td><td>' + esc(r.room_id) + '</td><td>' + esc(r.operator || '—')
            + '</td><td>' + (s.green ? '✅ verde' : ((s.fail || 0) + ' FAIL · ' + (s.pending || 0) + ' pend'))
            + '</td><td><button data-load="' + esc(r.run_id) + '">Abrir</button></td></tr>';
        });
        html += '</table>';
      }
      html += '<div style="text-align:right;margin-top:12px"><button id="modalClose">Cerrar</button></div>';
      $('modal').innerHTML = '<div class="box">' + html + '</div>';
      $('modal').classList.remove('hidden');
      $('modalClose').onclick = function () { $('modal').classList.add('hidden'); };
      Array.prototype.forEach.call(document.querySelectorAll('[data-load]'), function (b) {
        b.onclick = async function () {
          var data = await api('/dashboard-api/sensores/get?id=' + encodeURIComponent(b.getAttribute('data-load')));
          run = data;
          localStorage.setItem(LS_RUN + roomId(), run.run_id);
          currentId = pendingIds()[0] || (catalog.tests || [])[0].id;
          $('modal').classList.add('hidden');
          render();
        };
      });
    } catch (e) { toast('No se pudieron listar las corridas'); }
  }

  // ── auto-refresco de eventos ─────────────────────────────────────────
  function scheduleEvents() {
    if (eventTimer) clearInterval(eventTimer);
    eventTimer = setInterval(function () {
      if (document.hidden) return;
      if (!$('autoRefresh').checked) return;
      refreshEvents();
    }, 3000);
  }

  // ── arranque ─────────────────────────────────────────────────────────
  async function boot() {
    try {
      await loadCatalog();
    } catch (e) {
      $('detail').innerHTML = '<div class="card empty">No se pudo cargar el catálogo: ' + esc(e.message) + '</div>';
      return;
    }
    run = await loadRun();
    if (run) {
      currentId = pendingIds()[0] || (catalog.tests || [])[0].id;
      await refreshContext();
      await ensureStepStarted(currentId);
      render();
    } else {
      render();
      renderSummary();
      await refreshContext();
    }
    scheduleEvents();

    $('btnStartRun').onclick = function () {
      if (run && !confirm('¿Empezar una nueva corrida desde cero? La actual queda guardada.')) return;
      startRun().catch(function (e) { toast(e.message); });
    };
    $('btnValidate'); // creado en renderDetail
    $('btnNext').onclick = goNext;
    $('btnPrev').onclick = goPrev;
    $('btnReset').onclick = function () { resetStep().catch(function (e) { toast(e.message); }); };
    $('btnFinish').onclick = finishRun;
    $('btnRuns').onclick = showRuns;
    $('btnExportJson').onclick = function () { exportRun('json'); };
    $('btnExportMd').onclick = function () { exportRun('md'); };
    $('btnCalRefresh').onclick = refreshContext;
    $('btnCalApply').onclick = function () { applyCalibration().catch(function (e) { $('calMsg').textContent = e.message; }); };
    $('btnRefreshEvents').onclick = refreshEvents;
    $('roomId').onchange = function () {
      run = null; currentId = null;
      loadRun().then(function (r) { run = r; if (run) currentId = pendingIds()[0]; refreshContext().then(render); });
    };
    $('operator').onchange = function () { localStorage.setItem(LS_OP, operator()); };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
