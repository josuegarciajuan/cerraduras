/**
 * sensor-wizard-v2.js — F95: flujo universal de verificación Zigbee.
 *
 * La UI presenta; el servidor valida. Ventana explícita, intentos inmutables,
 * una sola prueba activa y veredicto por dispositivo.
 */
(function () {
  'use strict';

  var V2 = '/dashboard-api/sensores/v2';
  var LS_ROOM = 'sensores2:room';
  var LS_OP = 'sensores2:operator';
  var LS_RUN = 'sensores2:run:';

  var S = {
    rooms: [], catalog: null, preflight: null, run: null,
    stepId: null, attempt: null, busy: false,
    human: { prep_off: null, on: null, off: null, telemetry_on: null, telemetry_off: null },
    lightPhase: 'idle', attemptData: null, pollTimer: null, pollStep: null, pollBusy: false,
    lastSwitch: null, lastEvents: [], eventsTimer: null
  };

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function roomId() { return parseInt($('roomSel').value, 10) || 0; }
  function operator() { return ($('operator').value || '').trim(); }
  function isFinal(st) { return ['PASS', 'FAIL', 'UNVERIFIED', 'SKIP'].indexOf(st) >= 0; }
  function stepStatus(id) {
    return (S.run && S.run.steps && S.run.steps[id] && S.run.steps[id].status) || 'PENDING';
  }
  function catalogStep(id) {
    var steps = (S.catalog && S.catalog.steps) || [];
    for (var i = 0; i < steps.length; i++) if (steps[i].id === id) return steps[i];
    return null;
  }
  function plan() { return (S.run && S.run.plan) || []; }
  function currentStep() {
    var p = plan();
    for (var i = 0; i < p.length; i++) if (!isFinal(stepStatus(p[i]))) return p[i];
    return null;
  }
  function toast(msg) {
    var el = $('toast');
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.add('hidden'); }, 3500);
  }
  function banner(msg, kind) {
    var el = $('banner');
    if (!msg) { el.classList.add('hidden'); return; }
    el.textContent = msg;
    el.className = 'banner';
    el.style.borderColor = kind === 'bad' ? 'var(--fail)' : (kind === 'ok' ? 'var(--pass)' : 'var(--warn)');
    el.style.color = kind === 'bad' ? 'var(--fail)' : (kind === 'ok' ? 'var(--pass)' : 'var(--warn)');
  }

  async function api(path, opts) {
    opts = opts || {};
    var ctrl = new AbortController();
    var to = setTimeout(function () { ctrl.abort(); }, 15000);
    try {
      var res = await fetch(path, Object.assign({}, opts, { signal: ctrl.signal }));
      var data = await res.json().catch(function () { return {}; });
      if (!res.ok) {
        var err = new Error(data.message || data.error || ('HTTP ' + res.status));
        err.data = data;
        err.status = res.status;
        throw err;
      }
      return data;
    } finally {
      clearTimeout(to);
    }
  }

  // ── carga ────────────────────────────────────────────────────────────
  async function boot() {
    $('operator').value = localStorage.getItem(LS_OP) || '';
    try { S.catalog = await api('assets/sensor-tests-v2.json', { cache: 'no-store' }); }
    catch (e) { S.catalog = { steps: [] }; }
    try { S.rooms = await api(V2 + '/rooms'); }
    catch (e) { S.rooms = []; banner('No se pudieron cargar las salas: ' + e.message, 'bad'); }
    fillRooms();
    await loadPreflight();
    S.run = await loadLocalRun();
    if (S.run) S.stepId = currentStep();
    render();
    bind();
    S.eventsTimer = setInterval(function () {
      if (document.hidden) return;
      if (S.run && S.run.status !== 'finished') refreshEvents();
    }, 3000);
  }

  function fillRooms() {
    var sel = $('roomSel');
    var saved = parseInt(localStorage.getItem(LS_ROOM) || '0', 10);
    sel.innerHTML = '';
    S.rooms.forEach(function (r) {
      var o = document.createElement('option');
      o.value = String(r.id);
      o.textContent = r.code + (r.room_type_code ? ' · ' + r.room_type_code : '');
      sel.appendChild(o);
    });
    if (!S.rooms.length) {
      var o2 = document.createElement('option');
      o2.value = '14'; o2.textContent = 'Sala 14';
      sel.appendChild(o2);
    }
    var exists = S.rooms.some(function (r) { return r.id === saved; });
    var fallback = S.rooms.some(function (r) { return r.id === 14; }) ? 14 : (S.rooms[0] ? S.rooms[0].id : 14);
    sel.value = String(exists ? saved : fallback);
  }

  async function loadPreflight() {
    banner('');
    try {
      S.preflight = await api(V2 + '/preflight?room_id=' + roomId());
      $('connBadge').textContent = S.preflight.can_start ? 'Dispositivos listos' : 'Falta un dispositivo';
      $('connBadge').className = 'badge ' + (S.preflight.can_start ? 'ok' : 'bad');
      renderRoster(); renderTech();
    } catch (e) {
      S.preflight = null;
      $('connBadge').textContent = 'Sin conexión';
      $('connBadge').className = 'badge bad';
      banner('No se pudo leer la sala: ' + e.message, 'bad');
    }
  }

  async function loadLocalRun() {
    var id = localStorage.getItem(LS_RUN + roomId());
    if (!id) return null;
    try {
      var run = await api(V2 + '/get?id=' + encodeURIComponent(id));
      return run && run.room_id === roomId() ? run : null;
    } catch (e) { return null; }
  }

  function saveRunLocal() {
    if (S.run) localStorage.setItem(LS_RUN + roomId(), S.run.run_id);
  }

  // ── render ───────────────────────────────────────────────────────────
  function render() {
    renderRoster(); renderRail();
    var host = $('mainCard');
    if (!S.run) { renderPreflight(host); renderTech(); return; }
    if (S.run.status === 'finished') { renderVerdict(host); renderTech(); return; }
    if (!S.stepId) S.stepId = currentStep();
    if (!S.stepId) { renderFinishCta(host); renderTech(); return; }
    renderStep(host, S.stepId);
    renderTech();
  }

  function deviceState(dev) {
    if (!dev) return { cls: 'bad', text: 'SIN ACCESO', detail: 'No se reconoce el dispositivo' };
    var h = dev.health || (dev.fresh ? 'online' : 'idle');
    if (h === 'missing') return { cls: 'bad', text: 'SIN ACCESO', detail: dev.health_detail || '' };
    if (h === 'stale') return { cls: 'warn', text: 'SIN DATOS', detail: dev.health_detail || 'sin reportes recientes' };
    if (h === 'idle') return { cls: 'ok', text: 'RECONOCIDO', detail: dev.health_detail || 'sin actividad reciente' };
    return { cls: 'ok', text: 'EN LÍNEA', detail: dev.health_detail || 'reportando' };
  }

  function healthLine(dev) {
    if (!dev) return 'sin acceso';
    var h = dev.health || (dev.fresh ? 'online' : 'idle');
    var a = dev.last_seen_age_seconds != null ? age(dev.last_seen_age_seconds) : 'sin señal';
    if (h === 'idle') return 'sin actividad · ' + a;
    if (h === 'stale') return 'sin reportes · ' + a;
    return a;
  }

  function renderRoster() {
    var d = S.preflight && S.preflight.devices ? S.preflight.devices : {};
    var active = S.stepId || '';
    var items = [
      { key: 'puerta', label: 'PUERTA', dev: d.proximity, model: 'SNZB-04P', step: 'door' },
      { key: 'presencia', label: 'PRESENCIA', dev: d.presence, model: 'ZY-M100-S2', step: 'radar_inside' },
      { key: 'luz', label: 'LUZ', dev: d.switch, model: 'ZBMINIL2', step: 'light' }
    ];
    var host = $('roster');
    host.innerHTML = '';
    items.forEach(function (it) {
      var st = deviceState(it.dev);
      var div = document.createElement('button');
      div.type = 'button';
      div.className = 'dev ' + st.cls + (active === it.step ? ' active' : '');
      div.setAttribute('aria-label', it.label + ': ' + st.text);
      div.innerHTML = '<div class="name">' + it.label + '</div>'
        + '<div class="state">' + st.text + '</div>'
        + '<div class="model">' + esc(it.dev && it.dev.model ? it.dev.model : it.model)
        + ' · ' + esc(healthLine(it.dev)) + '</div>';
      div.onclick = function () { showDeviceDetails(it); };
      host.appendChild(div);
    });
  }

  function showDeviceDetails(it) {
    var d = it.dev;
    if (!d) { toast(it.label + ': sin acceso'); return; }
    var parts = [it.label];
    if (d.model) parts.push(d.model);
    if (d.transport) parts.push(d.transport);
    if (d.battery_pct != null) parts.push('pila ' + d.battery_pct + '%');
    parts.push(healthLine(d));
    if (it.key === 'puerta') parts.push('polaridad ' + (d.door_polarity || 'normal'));
    if (it.key === 'luz') parts.push('estado ' + (d.switch_state || '—'));
    toast(parts.join(' · '));
  }

  function age(sec) {
    if (sec == null) return 'sin señal';
    if (sec < 60) return 'hace ' + sec + ' s';
    if (sec < 3600) return 'hace ' + Math.round(sec / 60) + ' min';
    return 'hace ' + Math.round(sec / 3600) + ' h';
  }

  function renderRail() {
    var host = $('rail');
    var p = plan();
    var labels = { door: 'Puerta', radar_inside: 'Dentro', radar_outside: 'Fuera', light: 'Luz' };
    host.innerHTML = '';
    p.forEach(function (id, i) {
      var st = stepStatus(id);
      var el = document.createElement('span');
      el.className = 'station ' + st + (id === S.stepId ? ' current' : '');
      el.innerHTML = '<b>' + (i + 1) + '</b> ' + (labels[id] || id)
        + (st === 'PASS' ? ' ✓' : st === 'FAIL' ? ' ✕' : '');
      host.appendChild(el);
    });
    if (S.run) {
      var v = document.createElement('span');
      v.className = 'station' + (S.run.status === 'finished' ? ' current' : '');
      v.innerHTML = '<b>' + (p.length + 1) + '</b> Veredicto';
      host.appendChild(v);
    }
  }

  function renderPreflight(host) {
    var p = S.preflight;
    if (!p) {
      host.innerHTML = '<div class="card"><h1>Sin conexión</h1><p class="hint">No se pudo leer la sala.</p>'
        + '<div class="actions"><button class="primary" data-action="retry-preflight">Reintentar</button></div></div>';
      return;
    }
    var issues = (p.issues || []).map(function (i) {
      return { door_missing: 'No hay sensor de puerta',
        presence_missing: 'No hay radar de presencia', presence_stale: 'El radar no envía datos recientes' }[i] || i;
    });
    var idle = ['proximity', 'presence', 'switch'].some(function (k) {
      var d = p.devices && p.devices[k];
      return d && d.health === 'idle';
    });
    var active = p.active_attempt;
    var html = '<div class="card">';
    html += '<div class="eyebrow">Antes de empezar</div>';
    html += '<h1>Comprobar los sensores de ' + esc(p.room.code) + '</h1>';
    html += '<p class="hint">' + (p.can_start ? 'Los dispositivos necesarios están disponibles.' : 'Falta un dispositivo necesario.') + '</p>';
    if (issues.length) html += '<div class="expect">' + issues.map(esc).join('<br>') + '</div>';
    if (idle) html += '<p class="hint">La puerta y la luz solo envían al cambiar: “sin actividad” no es un fallo. Se actualizarán al abrirlas o conmutarlas.</p>';
    if (p.settings && p.settings.maximum_range != null) {
      html += '<div class="row" style="margin-top:10px"><span class="pill dim">Alcance ' + p.settings.maximum_range + ' m</span>'
        + '<span class="pill dim">Sensibilidad ' + p.settings.radar_sensitivity + '/9</span></div>';
    }
    html += '<div class="actions">';
    if (active) {
      html += '<button class="primary" data-action="resume" data-run="' + esc(active.run_id) + '">Continuar verificación en curso</button>';
    }
    html += '<button class="' + (p.can_start ? 'primary' : 'ghost') + '" data-action="start-run"' + (p.can_start ? '' : ' disabled') + '>Empezar verificación</button>';
    html += '</div></div>';
    host.innerHTML = html;
  }

  function stepHeader(stepId) {
    var c = catalogStep(stepId) || { title: stepId, action: '', expected: '' };
    var idx = plan().indexOf(stepId) + 1;
    return '<div class="eyebrow">Paso ' + idx + ' de ' + plan().length + ' · ' + esc(c.device || '') + '</div>'
      + '<h1>' + esc(c.title) + '</h1>'
      + '<div class="action">' + esc(c.action) + '</div>'
      + '<div class="expect"><b>Debe pasar:</b> ' + esc(c.expected) + '</div>';
  }

  var STATUS_LABEL = { PASS: 'Verificada', FAIL: 'Necesita revisión', SKIP: 'Omitida', UNVERIFIED: 'No verificada' };
  var REASON_LABEL = {
    door_ok: 'Tres ciclos correctos.', door_must_start_closed: 'La puerta no estaba cerrada al empezar.',
    door_too_few_cycles: 'Faltan ciclos por registrar.', door_too_many_transitions: 'Demasiados ciclos en la ventana.',
    door_sequence_mismatch: 'La secuencia apertura/cierre no coincide.', door_not_closed_at_end: 'La puerta no quedó cerrada.',
    no_door_events: 'No se recibió ningún evento de la puerta.',
    presence_detected_inside: 'Presencia continua durante la prueba.', no_presence_detected: 'No se detectó presencia.',
    presence_not_continuous: 'La presencia se perdió durante la prueba.', hold_too_short: 'Aún no hay 30 s de presencia.',
    step_too_short: 'La prueba terminó antes de tiempo.', presence_stale: 'El radar dejó de enviar datos.',
    no_presence_outside: 'No se detectó presencia fuera.', presence_detected_outside: 'Se detectó presencia fuera de la sala.',
    absence_not_confirmed: 'La ausencia aún no está confirmada.', light_ok: 'Encendido y apagado correctos.',
    lamp_not_prepared_off: 'La lámpara no estaba apagada al empezar.', human_not_confirmed: 'La confirmación física no es positiva.',
    telemetry_mismatch: 'La lámpara y la telemetría no coinciden.', no_switch: 'No hay interruptor asociado.'
  };
  function resultBox(st, verdict) {
    if (!isFinal(st) || !verdict) return '';
    var v = verdict;
    var label = STATUS_LABEL[st] || st;
    var reason = REASON_LABEL[v.reason] || v.reason || '';
    return '<div class="result ' + esc(st) + '" role="status" tabindex="-1"><h3>' + esc(label) + '</h3>'
      + '<div>' + esc(reason) + '</div>'
      + '<details style="margin-top:8px"><summary>Detalle de la prueba</summary><pre style="white-space:pre-wrap;font-size:11px">'
      + esc(JSON.stringify(v.details || {}, null, 2)) + '</pre></details></div>';
  }

  function activeAttemptFor(stepId) {
    var step = S.run && S.run.steps ? S.run.steps[stepId] : null;
    return step && step.active_attempt ? step.active_attempt : null;
  }

  function renderStep(host, stepId) {
    var step = S.run.steps[stepId] || {};
    var st = step.status || 'PENDING';
    var last = (step.attempts && step.attempts.length) ? step.attempts[step.attempts.length - 1] : null;
    var html = '<div class="card">' + stepHeader(stepId);
    html += resultBox(st, last && last.verdict);
    if (isFinal(st)) {
      stopPolling();
      html += '<div class="actions"><button class="primary" data-action="advance">Continuar</button>'
        + '<button class="ghost" data-action="restart" data-step="' + stepId + '">Repetir paso</button></div></div>';
      host.innerHTML = html;
      return;
    }
    if (stepId === 'door') html += renderDoor(step);
    else if (stepId === 'radar_inside' || stepId === 'radar_outside') html += renderRadar(stepId, step);
    else if (stepId === 'light') html += renderLight(step);
    html += '</div>';
    host.innerHTML = html;
    if ((stepId === 'door' || stepId === 'radar_inside' || stepId === 'radar_outside') && activeAttemptFor(stepId)) {
      startPolling(stepId);
    }
  }

  function renderDoor(step) {
    var html = '';
    var pol = S.run.door_polarity || null;
    html += '<div class="divider"></div><h2>1. Polaridad</h2>';
    if (!pol) {
      html += '<p class="hint">Cierra la puerta del todo. Si el imán está montado al revés, se corregirá solo en este dispositivo.</p>'
        + '<div class="actions"><button class="primary" data-action="polarity">Comprobar polaridad</button></div>';
      return html;
    }
    html += '<div class="row"><span class="pill ' + (pol.value === 'inverted' ? 'warn' : 'ok') + '">'
      + (pol.value === 'inverted' ? 'Montaje invertido · corregido' : 'Polaridad correcta') + '</span>'
      + '<span class="timestamp">' + esc(pol.checked_at || '') + '</span></div>';
    var att = activeAttemptFor('door');
    if (!att) {
      html += '<p class="hint">Ahora abre y cierra tres veces: normal, despacio y rápido. La ventana empieza al pulsar el botón.</p>'
        + '<div class="actions"><button class="primary" data-action="start-attempt" data-step="door">Empezar ciclos</button></div>';
      return html;
    }
    var d = (S.attemptData && S.attemptData.door) || { count: 0, target: 3, waiting_for: 'open', complete: false };
    var count = d.count || 0;
    var target = d.target || 3;
    var waiting = d.waiting_for;
    var instruction = waiting === 'close'
      ? 'Apertura recibida. Cierra la puerta.'
      : (count >= target ? 'Tres ciclos registrados. Comprueba que la puerta está cerrada.' : 'Abre la puerta.');
    html += '<div class="divider"></div><h2>2. Ciclos</h2>';
    html += '<div class="readout">' + Math.min(count, target) + ' / ' + target + '</div>';
    html += '<div class="action" style="font-weight:700">' + esc(instruction) + '</div>';
    html += '<div class="checklist">';
    ['Normal', 'Despacio', 'Rápido'].forEach(function (label, i) {
      var done = i < count;
      var current = i === count;
      html += '<div class="check"><span class="dot ' + (done ? 'ok' : (current ? 'warn' : '')) + '"></span> '
        + label + (done ? ' · apertura y cierre recibidos' : (current ? ' · pendiente' : '')) + '</div>';
    });
    html += '</div>';
    html += '<p class="hint">Solo cuentan los eventos de esta prueba. Los anteriores están en «Eventos de la sala» y no se suman.</p>';
    html += '<div class="actions">';
    if (count >= target) {
      html += '<button class="primary" data-action="check" data-step="door">Validar puerta</button>';
    } else {
      html += '<button class="primary" disabled>Esperando ' + (target - count) + ' ciclo' + ((target - count) === 1 ? '' : 's') + '…</button>';
    }
    html += '<button class="ghost" data-action="cancel" data-step="door">Cancelar prueba</button></div>';
    return html;
  }

  function radarSettingsLabel() {
    var st = (S.preflight && S.preflight.settings) || {};
    if (st.maximum_range == null) return 'Ajustes —';
    return 'Alcance ' + st.maximum_range + ' m · sens ' + st.radar_sensitivity + '/9';
  }

  function renderRadar(stepId, step) {
    var att = activeAttemptFor(stepId);
    var html = '';
    if (!att) {
      html += '<div class="actions"><button class="primary" data-action="start-attempt" data-step="' + stepId + '">Empezar prueba</button></div>';
      return html;
    }
    var inside = stepId === 'radar_inside';
    var target = inside ? 30 : 60;
    var a = S.attemptData || { elapsed_seconds: 0, presence: {} };
    var elapsed = Math.max(0, Math.floor(a.elapsed_seconds || 0));
    var remain = Math.max(0, target - elapsed);
    var p = a.presence || {};
    var span = Math.max(0, Math.floor(p.span_seconds || 0));
    html += '<div class="divider"></div>';
    html += '<div class="row"><span class="pill ' + (p.active ? 'ok' : 'dim') + '" id="radarPresence">Presencia: '
      + (p.active ? 'DETECTADA' : 'sin detección') + '</span>'
      + '<span class="pill dim" id="radarSettings">' + radarSettingsLabel() + '</span></div>';
    if (remain > 0) {
      html += '<div style="text-align:center;margin:18px 0">';
      html += '<div class="readout" style="font-size:56px" id="radarTimer" aria-live="off">' + remain + ' s</div>';
      html += '<div class="action" style="font-weight:700">' + (inside ? 'No te muevas' : 'Mantente fuera') + '</div>';
      html += '<div class="hint">' + (inside ? 'Permanece en el punto más lejano.' : 'Pasa 3 veces por delante y espera fuera.') + '</div>';
      html += '</div>';
      html += '<div class="actions"><button class="ghost" data-action="cancel" data-step="' + stepId + '">Cancelar prueba</button></div>';
      return html;
    }
    html += '<div class="hint">' + (inside
      ? 'Tiempo mínimo cumplido. Presencia detectada durante ' + span + ' de 30 s.'
      : 'Tiempo mínimo cumplido.') + '</div>';
    html += '<div class="actions">';
    if (inside) {
      var canValidate = span >= 30 && p.continuous !== false;
      if (canValidate) {
        html += '<button class="primary" data-action="check" data-step="' + stepId + '">Validar radar</button>';
      } else if (!p.presents) {
        html += '<button class="ghost" data-action="tune" data-phase="inside">Ajustar si no detecta</button>';
        html += '<div class="hint">Aún no se detecta presencia. Puedes ajustar el radar y seguir en la posición.</div>';
      } else {
        html += '<div class="hint">Aún no hay 30 s de presencia continua; sigue quieto.</div>';
        html += '<button class="ghost" data-action="tune" data-phase="inside">Ajustar si no detecta</button>';
      }
    } else {
      html += '<button class="primary" data-action="check" data-step="' + stepId + '">Validar radar</button>';
      if (p.active) html += '<button class="ghost" data-action="tune" data-phase="outside">Ajustar si detecta de más</button>';
    }
    html += '<button class="ghost" data-action="cancel" data-step="' + stepId + '">Cancelar prueba</button></div>';
    return html;
  }

  function renderLight(step) {
    var att = activeAttemptFor('light');
    if (!att) {
      return '<div class="actions"><button class="primary" data-action="start-attempt" data-step="light">Empezar prueba</button></div>';
    }
    if (S.lightPhase === 'idle') {
      return '<div class="divider"></div><div class="expect">La prueba de luz ya estaba abierta y las confirmaciones visuales no se conservan al recargar. Cancela y repite para registrar una observación nueva.</div>'
        + '<div class="actions"><button class="danger" data-action="cancel" data-step="light">Cancelar y repetir</button></div>';
    }
    var h = S.human;
    var html = '<div class="divider"></div>';
    html += '<div class="row"><span class="pill ' + (h.prep_off === true ? 'ok' : h.prep_off === false ? 'bad' : 'dim') + '">Apagada al empezar: '
      + (h.prep_off === true ? 'sí' : h.prep_off === false ? 'no' : 'pendiente') + '</span>'
      + '<span class="pill ' + (h.on === true ? 'ok' : h.on === false ? 'bad' : 'dim') + '">Encender: '
      + (h.on === true ? 'confirmado' : h.on === false ? 'falló' : 'pendiente') + '</span>'
      + '<span class="pill ' + (h.off === true ? 'ok' : h.off === false ? 'bad' : 'dim') + '">Apagar: '
      + (h.off === true ? 'confirmado' : h.off === false ? 'falló' : 'pendiente') + '</span></div>';
    if (S.lightPhase === 'prep') {
      html += '<p class="hint">Primero dejamos la lámpara apagada para que el encendido demuestre un cambio real.</p>'
        + '<div class="actions"><button class="primary" data-action="switch" data-on="OFF">Apagar lámpara</button>'
        + '<button class="ghost" data-action="cancel" data-step="light">Cancelar prueba</button></div>';
      return html;
    }
    if (S.lightPhase === 'prep_sent') {
      if (h.prep_off === false) {
        html += '<div class="expect">La lámpara no está apagada. Vuelve a intentarlo.</div>'
          + '<div class="actions"><button class="primary" data-action="switch" data-on="OFF">Reintentar apagado</button>'
          + '<button class="ghost" data-action="cancel" data-step="light">Cancelar prueba</button></div>';
      } else {
        html += '<div class="action">¿La lámpara está apagada?</div>'
          + '<div class="actions"><button class="primary" data-action="human" data-field="prep_off" data-value="1">Sí, está apagada</button>'
          + '<button class="danger" data-action="human" data-field="prep_off" data-value="0">No, sigue encendida</button></div>';
      }
      return html;
    }
    if (S.lightPhase === 'ready_on') {
      html += '<div class="actions"><button class="primary" data-action="switch" data-on="ON">Encender luz</button>'
        + '<button class="ghost" data-action="cancel" data-step="light">Cancelar prueba</button></div>';
      return html;
    }
    if (S.lightPhase === 'on_sent') {
      if (h.telemetry_on === null) html += '<p class="hint">Sin confirmación de telemetría para el encendido; tu observación manda.</p>';
      if (h.telemetry_on === false) html += '<p class="hint">La telemetría dice que sigue apagada; se tendrá en cuenta.</p>';
      html += '<div class="action">¿Se encendió la lámpara?</div>'
        + '<div class="actions"><button class="primary" data-action="human" data-field="on" data-value="1">Sí, está encendida</button>'
        + '<button class="danger" data-action="human" data-field="on" data-value="0">No, sigue apagada</button></div>';
      return html;
    }
    if (S.lightPhase === 'ready_off') {
      html += '<div class="actions"><button class="primary" data-action="switch" data-on="OFF">Apagar luz</button>'
        + '<button class="ghost" data-action="cancel" data-step="light">Cancelar prueba</button></div>';
      return html;
    }
    if (S.lightPhase === 'off_sent') {
      if (h.telemetry_off === null) html += '<p class="hint">Sin confirmación de telemetría para el apagado; tu observación manda.</p>';
      if (h.telemetry_off === false) html += '<p class="hint">La telemetría dice que sigue encendida; se tendrá en cuenta.</p>';
      html += '<div class="action">¿Se apagó la lámpara?</div>'
        + '<div class="actions"><button class="primary" data-action="human" data-field="off" data-value="1">Sí, está apagada</button>'
        + '<button class="danger" data-action="human" data-field="off" data-value="0">No, sigue encendida</button></div>';
      return html;
    }
    html += '<div class="actions"><button class="primary" data-action="check" data-step="light">Validar luz</button>'
      + '<button class="ghost" data-action="cancel" data-step="light">Cancelar prueba</button></div>';
    return html;
  }

  function renderFinishCta(host) {
    host.innerHTML = '<div class="card"><div class="eyebrow">Fin</div><h1>Pasos finalizados</h1>'
      + '<p class="hint">Genera el veredicto con los resultados disponibles.</p>'
      + '<div class="actions"><button class="primary" data-action="finish">Ver veredicto</button></div></div>';
  }

  function renderVerdict(host) {
    var v = S.run.verdict || {};
    var labels = { door: 'Puerta', presence: 'Presencia', light: 'Luz' };
    var icons = { PASS: '✓', FAIL: '✕', UNVERIFIED: '?', SKIP: '—', NOT_INSTALLED: '—' };
    var cls = { PASS: 'ok', FAIL: 'bad', UNVERIFIED: 'warnc', SKIP: 'warnc', NOT_INSTALLED: 'muted' };
    var stLabel = STATUS_LABEL;
    var title = {
      CORRECT: 'Verificación correcta', WITH_RESERVES: 'Correcta con reservas',
      REVIEW: 'Revisar instalación', INCOMPLETE: 'Verificación incompleta', NOT_EVALUABLE: 'No evaluable'
    }[v.verdict || 'INCOMPLETE'] || 'Verificación';
    var html = '<div class="card">';
    html += '<div class="eyebrow">Veredicto</div><h1 class="' + (v.verdict === 'CORRECT' ? 'ok' : v.verdict === 'REVIEW' ? 'bad' : 'warnc') + '">'
      + esc(title) + '</h1>';
    html += '<div class="techgrid">';
    Object.keys(labels).forEach(function (k) {
      var st = (v.devices || {})[k] || 'PENDING';
      html += '<div class="techrow"><span>' + labels[k] + '</span><span class="' + (cls[st] || '') + '">'
        + (icons[st] || '·') + ' ' + esc(stLabel[st] || st) + '</span></div>';
    });
    var soakLabel = { PASS: 'Correcto', FAIL: 'Revisar', PENDING: 'Pendiente' }[v.soak || 'PENDING'] || 'Pendiente';
    html += '<div class="techrow"><span>Reposo</span><span>' + esc(soakLabel) + '</span></div>';
    html += '</div>';
    var settings = (S.run.calibration && S.run.calibration.initial_settings) || {};
    if (settings.maximum_range != null) {
      html += '<p class="hint">Configuración inicial: sensibilidad ' + settings.radar_sensitivity
        + '/9 · alcance ' + settings.maximum_range + ' m · fading ' + settings.fading_time + ' s</p>';
    }
    if (v.soak !== 'PASS') {
      html += '<p class="hint">El reposo revisa los últimos 30 minutos con la sala vacía. Puedes comprobarlo ahora o al volver a abrir la sala.</p>';
    }
    html += '<div class="actions">';
    if (v.verdict === 'REVIEW' || v.verdict === 'INCOMPLETE') {
      html += '<button class="primary" data-action="new">Repetir verificación</button>';
    }
    if (v.soak !== 'PASS') html += '<button data-action="soak">Comprobar reposo</button>';
    html += '<button class="' + (v.verdict === 'CORRECT' ? 'primary' : '') + '" data-action="export">Descargar informe</button>';
    html += '<button class="ghost" data-action="new">Nueva verificación</button>';
    html += '</div></div>';
    host.innerHTML = html;
  }

  function renderTech() {
    var body = $('techBody');
    if (!body) return;
    var p = S.preflight || {};
    var d = (p.devices) || {};
    var rows = [];
    rows.push(['Sala', (p.room && p.room.code) || '—']);
    rows.push(['Actor', (p.room && p.room.room_type_code) || '—']);
    rows.push(['Run', (S.run && S.run.run_id) || '—']);
    rows.push(['Técnico', (S.run && S.run.operator) || operator() || '—']);
    ['proximity', 'presence', 'switch'].forEach(function (k) {
      var dev = d[k];
      if (!dev) return;
      rows.push([dev.label || k, (dev.model || '') + ' · ' + (dev.transport || '') + ' · '
        + (dev.battery_pct != null ? 'pila ' + dev.battery_pct + '%' : '—')
        + ' · ' + healthLine(dev)]);
    });
    var settings = p.settings || {};
    if (settings.maximum_range != null) {
      rows.push(['Radar', 'sens ' + settings.radar_sensitivity + '/9 · alcance ' + settings.maximum_range
        + ' m · mín ' + settings.minimum_range + ' m · delay ' + settings.detection_delay
        + ' s · fading ' + settings.fading_time + ' s']);
    }
    if (p.settings_age_seconds != null) rows.push(['Config recibida', age(p.settings_age_seconds)]);
    if (S.run && S.run.door_polarity) rows.push(['Polaridad puerta', S.run.door_polarity.value]);
    body.innerHTML = rows.map(function (r) {
      return '<div class="techrow"><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
    }).join('');
  }

  // ── acciones ─────────────────────────────────────────────────────────
  async function startRun() {
    if (!operator()) { toast('Escribe el nombre del técnico'); $('operator').focus(); return; }
    localStorage.setItem(LS_OP, operator());
    busy(true);
    try {
      var data = await api(V2 + '/run/start', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room_id: roomId(), operator: operator() })
      });
      S.run = data.run;
      S.stepId = currentStep();
      S.attempt = null;
      S.attemptData = null;
      S.lightPhase = 'idle';
      S.human = { prep_off: null, on: null, off: null, telemetry_on: null, telemetry_off: null };
      saveRunLocal();
      render();
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function startAttempt(stepId) {
    busy(true);
    stopPolling();
    try {
      var data = await api(V2 + '/attempt/start', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: S.run.run_id, step_id: stepId })
      });
      S.attempt = data.attempt;
      S.attemptData = null;
      S.human = { prep_off: null, on: null, off: null, telemetry_on: null, telemetry_off: null };
      S.lastSwitch = null;
      S.lightPhase = stepId === 'light' ? 'prep' : 'idle';
      S.radarKey = null;
      await reloadRun();
      render();
      if (stepId === 'light') {
        await switchTest('OFF');
      }
    } catch (e) {
      banner(e.message, 'bad');
      if (e.data && e.data.active) {
        toast('Hay una prueba en curso en esta sala');
      }
    } finally { busy(false); }
  }

  async function checkAttempt(stepId) {
    var att = activeAttemptFor(stepId);
    if (!att) { toast('Primero empieza la prueba'); return; }
    if (stepId === 'light' && (S.human.prep_off === null || S.human.on === null || S.human.off === null)) {
      toast('Completa la preparación, el encendido y el apagado');
      return;
    }
    stopPolling();
    busy(true);
    try {
      var body = {
        run_id: S.run.run_id, step_id: stepId, attempt_id: att.attempt_id,
        check_id: 'c-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6)
      };
      if (stepId === 'light') body.human = S.human;
      await api(V2 + '/attempt/check', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      await reloadRun();
      await loadPreflight();
      S.attemptData = null;
      render();
      var res = document.querySelector('.result');
      if (res) res.focus();
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function cancelAttempt(stepId) {
    var att = activeAttemptFor(stepId);
    if (!att) return;
    if (!confirm('¿Cancelar esta prueba? La ventana se cerrará sin veredicto.')) return;
    stopPolling();
    busy(true);
    try {
      await api(V2 + '/attempt/cancel', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: S.run.run_id, step_id: stepId, attempt_id: att.attempt_id })
      });
      await reloadRun();
      S.attemptData = null;
      S.lightPhase = 'idle';
      render();
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function polarity() {
    busy(true);
    try {
      var data = await api(V2 + '/door/polarity', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room_id: roomId(), run_id: S.run.run_id, physical: 'CLOSED' })
      });
      await reloadRun();
      await loadPreflight();
      toast(data.message || 'Polaridad comprobada');
      render();
    } catch (e) {
      if (e.status === 422) toast(e.message);
      else banner(e.message, 'bad');
    } finally { busy(false); }
  }

  async function tune(phase) {
    var stepId = phase === 'inside' ? 'radar_inside' : 'radar_outside';
    var att = activeAttemptFor(stepId);
    if (!att) { toast('Primero empieza la prueba'); return; }
    busy(true);
    try {
      var data = await api(V2 + '/radar/tune', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: S.run.run_id, step_id: stepId, attempt_id: att.attempt_id, phase: phase })
      });
      toast(data.message || (data.applied ? 'Ajuste aplicado' : 'Ajuste no confirmado'));
      await refreshEvents();
      await loadPreflight();
      render();
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function switchTest(action) {
    busy(true);
    try {
      var phaseBefore = S.lightPhase;
      var data = await api(V2 + '/switch/test', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room_id: roomId(), action: action })
      });
      S.lastSwitch = data;
      if (!data.ok) {
        banner('No se pudo enviar la orden: ' + (data.error || 'desconocido') + '. Comprueba la lámpara antes de reintentar.', 'bad');
        render();
        return;
      }
      if (action === 'ON') {
        S.lightPhase = 'on_sent';
        S.human.telemetry_on = data.fresh ? (data.reported_state === 'ON') : null;
      } else if (phaseBefore === 'ready_off') {
        S.lightPhase = 'off_sent';
        S.human.telemetry_off = data.fresh ? (data.reported_state === 'OFF') : null;
      } else {
        S.lightPhase = 'prep_sent';
      }
      render();
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function finishRun() {
    busy(true);
    try {
      var data = await api(V2 + '/run/finish', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: S.run.run_id })
      });
      S.run = data.run;
      saveRunLocal();
      render();
      $('mainCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) { banner(e.message, 'bad'); }
    finally { busy(false); }
  }

  async function soak() {
    busy(true);
    try {
      var data = await api(V2 + '/run/soak', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ run_id: S.run.run_id })
      });
      S.run.soak = data.soak;
      S.run.verdict = data.verdict;
      render();
      toast('Reposo: ' + data.soak.status);
    } catch (e) {
      if (e.status === 422 && e.data && e.data.remaining_seconds) {
        var m = Math.ceil(e.data.remaining_seconds / 60);
        toast('Faltan ' + m + ' minutos para poder comprobar el reposo');
      } else banner(e.message, 'bad');
    } finally { busy(false); }
  }

  async function reloadRun() {
    S.run = await api(V2 + '/get?id=' + encodeURIComponent(S.run.run_id));
    if (!S.stepId) S.stepId = currentStep();
  }

  function advance() {
    stopPolling();
    S.attempt = null;
    S.attemptData = null;
    S.lightPhase = 'idle';
    S.radarKey = null;
    S.stepId = currentStep();
    if (!S.stepId) { finishRun(); return; }
    render();
  }

  async function restart(stepId) {
    if (!confirm('¿Repetir este paso? Se abrirá una ventana nueva.')) return;
    stopPolling();
    S.stepId = stepId;
    S.attempt = null;
    S.attemptData = null;
    S.lightPhase = 'idle';
    S.radarKey = null;
    // El check anterior queda en el informe; se permite un intento nuevo.
    var step = S.run.steps[stepId];
    if (step) step.status = 'PENDING';
    render();
  }

  // ── polling de la ventana del intento ────────────────────────────────
  function updateRadarDom() {
    var a = S.attemptData || {};
    var inside = S.stepId === 'radar_inside';
    var target = inside ? 30 : 60;
    var elapsed = Math.max(0, Math.floor(a.elapsed_seconds || 0));
    var remain = Math.max(0, target - elapsed);
    var p = (a.presence) || {};
    var span = Math.max(0, Math.floor(p.span_seconds || 0));
    var timer = $('radarTimer');
    if (timer) timer.textContent = remain + ' s';
    var chip = $('radarPresence');
    if (chip) {
      chip.textContent = 'Presencia: ' + (p.active ? 'DETECTADA' : 'sin detección');
      chip.className = 'pill ' + (p.active ? 'ok' : 'dim');
    }
    var key = remain > 0 ? 'wait' : ('ready:' + (p.active ? '1' : '0') + ':' + (span >= 30 ? '1' : '0') + ':' + (p.presents ? '1' : '0'));
    if (S.radarKey !== key) {
      S.radarKey = key;
      return false;
    }
    return true;
  }

  function stopPolling() {
    if (S.pollTimer) clearInterval(S.pollTimer);
    S.pollTimer = null;
    S.pollStep = null;
  }

  function startPolling(stepId) {
    S.attempt = S.attempt || activeAttemptFor(stepId);
    if (S.pollTimer && S.pollStep === stepId) return;
    stopPolling();
    S.pollStep = stepId;
    S.pollTimer = setInterval(function () {
      if (!document.hidden) pollAttempt();
    }, 1000);
    pollAttempt();
  }

  async function pollAttempt() {
    var att = S.attempt;
    if (!att || S.pollBusy) return;
    S.pollBusy = true;
    try {
      var d = await api(V2 + '/attempt/events?run_id=' + encodeURIComponent(S.run.run_id)
        + '&step_id=' + encodeURIComponent(S.stepId)
        + '&attempt_id=' + encodeURIComponent(att.attempt_id), { cache: 'no-store' });
      if (S.attempt && S.attempt.attempt_id === att.attempt_id) {
        S.attemptData = d;
        if (S.stepId === 'radar_inside' || S.stepId === 'radar_outside') {
          if (!updateRadarDom()) render();
        } else {
          render();
        }
      }
    } catch (e) {
      if (e.status === 409) stopPolling();
    } finally {
      S.pollBusy = false;
    }
  }

  // ── eventos / tech ───────────────────────────────────────────────────
  async function refreshEvents() {
    if (!roomId()) return;
    try {
      var data = await api(V2 + '/events?room_id=' + roomId(), { cache: 'no-store' });
      S.lastEvents = data.events || [];
      S.preflight = S.preflight || {};
      S.preflight.live = data.live;
      renderEvents();
      if (S.stepId && (S.stepId === 'radar_inside' || S.stepId === 'radar_outside')) renderLiveRadar();
      if (S.stepId === 'door') refreshDoorChecks();
      if (S.stepId === 'light') renderTech();
    } catch (e) { /* silencioso */ }
  }

  function renderEvents() {
    var host = $('eventsBody');
    if (!host) return;
    if (!S.lastEvents.length) { host.innerHTML = '<div class="hint">Sin eventos recientes</div>'; return; }
    var rows = S.lastEvents.slice(0, 12).map(function (e) {
      var applied = e.applied === true;
      var st = applied ? 'aplicado' : (e.applied === false ? (e.discard_reason || 'descartado') : 'pendiente');
      return '<tr><td class="timestamp">' + esc((e.occurred_at || '').replace('T', ' ').replace(/\..*/, '').slice(11))
        + '</td><td>' + esc(e.sensor) + '</td><td>' + esc(e.value) + '</td><td>' + esc(st) + '</td></tr>';
    }).join('');
    host.innerHTML = '<table><thead><tr><th>Hora</th><th>Sensor</th><th>Valor</th><th>Estado</th></tr></thead><tbody>'
      + rows + '</tbody></table>';
  }

  function renderLiveRadar() {
    var live = (S.preflight && S.preflight.live) || {};
    var p = $('radarPresence');
    if (p) {
      var st = live.presence_state || 'UNKNOWN';
      p.textContent = 'Presencia: ' + st + (live.presence_stale ? ' (sin datos)' : '');
      p.className = 'pill ' + (st === 'PRESENT' && !live.presence_stale ? 'ok' : (live.presence_stale ? 'warn' : 'dim'));
    }
    var s = $('radarSettings');
    var settings = (S.preflight && S.preflight.settings) || {};
    if (s && settings.maximum_range != null) {
      s.textContent = 'Alcance ' + settings.maximum_range + ' m · sens ' + settings.radar_sensitivity + '/9';
    }
    var att = S.stepId ? activeAttemptFor(S.stepId) : null;
    var el = $('radarElapsed');
    if (el && att) {
      var sec = Math.max(0, Math.floor((Date.now() - Date.parse(att.started_at)) / 1000));
      el.textContent = 'Ventana abierta: ' + sec + ' s';
    }
  }

  function refreshDoorChecks() {
    var host = $('doorChecks');
    if (!host) return;
    var evs = S.lastEvents.filter(function (e) { return e.sensor === 'PROXIMITY' && e.applied === true; }).slice(-6);
    if (!evs.length) {
      host.innerHTML = '<div class="check"><span class="dot"></span> Esperando eventos de la puerta…</div>';
      return;
    }
    host.innerHTML = evs.map(function (e) {
      return '<div class="check"><span class="dot ok"></span> ' + esc(e.value) + ' · '
        + esc((e.occurred_at || '').replace('T', ' ').replace(/\..*/, '').slice(11)) + '</div>';
    }).join('');
  }

  // ── runs ─────────────────────────────────────────────────────────────
  async function showRuns() {
    try {
      var list = await api(V2 + '/runs');
      var html = '';
      if (!list.length) html = '<p class="hint">Todavía no hay verificaciones.</p>';
      else {
        html = '<table class="runs"><thead><tr><th>Run</th><th>Sala</th><th>Técnico</th><th>Resultado</th><th></th></tr></thead><tbody>';
        list.forEach(function (r) {
          var vv = (r.verdict && r.verdict.verdict) || '';
          var label = {
            CORRECT: 'Correcta', WITH_RESERVES: 'Con reservas', REVIEW: 'Revisar',
            INCOMPLETE: 'Incompleta', NOT_EVALUABLE: 'No evaluable'
          }[vv] || (r.status === 'finished' ? 'Finalizada' : 'En curso');
          html += '<tr><td>' + esc(r.run_id) + '</td><td>' + esc(r.room_id) + '</td><td>' + esc(r.operator || '—')
            + '</td><td>' + esc(label) + '</td><td><button data-open="' + esc(r.run_id) + '">Abrir</button></td></tr>';
        });
        html += '</tbody></table>';
      }
      $('runsBody').innerHTML = html;
      $('runsDialog').showModal();
      Array.prototype.forEach.call($('runsBody').querySelectorAll('[data-open]'), function (b) {
        b.onclick = function () { openRun(b.getAttribute('data-open')); };
      });
    } catch (e) { toast('No se pudieron listar las verificaciones'); }
  }

  async function openRun(id) {
    try {
      var run = await api(V2 + '/get?id=' + encodeURIComponent(id));
      S.run = run;
      S.stepId = currentStep();
      S.attempt = null;
      if ($('roomSel').value !== String(run.room_id)) {
        $('roomSel').value = String(run.room_id);
        await loadPreflight();
      }
      saveRunLocal();
      $('runsDialog').close();
      render();
    } catch (e) { toast('No se pudo abrir la verificación'); }
  }

  // ── utilidades ───────────────────────────────────────────────────────
  function busy(on) {
    S.busy = !!on;
    var btns = document.querySelectorAll('#mainCard button');
    Array.prototype.forEach.call(btns, function (b) { b.disabled = on; });
  }

  function bind() {
    $('roomSel').onchange = async function () {
      localStorage.setItem(LS_ROOM, String(roomId()));
      S.run = null; S.stepId = null; S.attempt = null;
      await loadPreflight();
      S.run = await loadLocalRun();
      if (S.run) S.stepId = currentStep();
      render();
    };
    $('operator').onchange = function () { localStorage.setItem(LS_OP, operator()); };
    $('btnRuns').onclick = showRuns;
    $('runsClose').onclick = function () { $('runsDialog').close(); };
    $('btnRefreshEvents').onclick = refreshEvents;
    $('mainCard').addEventListener('click', function (e) {
      var b = e.target.closest('[data-action]');
      if (!b || b.disabled) return;
      var a = b.getAttribute('data-action');
      var step = b.getAttribute('data-step');
      if (a === 'retry-preflight') loadPreflight().then(render);
      else if (a === 'start-run') startRun();
      else if (a === 'resume') openRun(b.getAttribute('data-run'));
      else if (a === 'polarity') polarity();
      else if (a === 'start-attempt') startAttempt(step);
      else if (a === 'check') checkAttempt(step);
      else if (a === 'cancel') cancelAttempt(step);
      else if (a === 'tune') tune(b.getAttribute('data-phase'));
      else if (a === 'switch') switchTest(b.getAttribute('data-on'));
      else if (a === 'human') {
        var f = b.getAttribute('data-field');
        var val = b.getAttribute('data-value') === '1';
        S.human[f] = val;
        if (f === 'prep_off') S.lightPhase = val ? 'ready_on' : 'prep';
        else if (f === 'on') S.lightPhase = 'ready_off';
        else if (f === 'off') S.lightPhase = 'done';
        render();
      } else if (a === 'advance') advance();
      else if (a === 'restart') restart(step);
      else if (a === 'finish') finishRun();
      else if (a === 'soak') soak();
      else if (a === 'export') window.location = V2 + '/export?id=' + encodeURIComponent(S.run.run_id) + '&format=md';
      else if (a === 'new') {
        if (!confirm('¿Empezar una verificación nueva? La actual queda guardada.')) return;
        localStorage.removeItem(LS_RUN + roomId());
        S.run = null; S.stepId = null; S.attempt = null;
        loadPreflight().then(render);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', boot);
  if (document.readyState !== 'loading') boot();
})();
