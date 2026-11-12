/**
 * radar-studio.js — F125/RF-350…RF-355.
 *
 * UI del Radar Studio: vista viva, sliders con preview local, aplicación con
 * confirmación por push y asistentes de calibración (paseo, sensibilidad,
 * reposo exterior y reentrada).
 *
 * La UI presenta; el servidor decide. No hay llamadas a Tuya ni pollers.
 */
(function () {
  'use strict';

  var API = '/dashboard-api/sensores/v2/radar';
  var POLL_MS = 1500;
  var LIVE_FALLBACK_MS = 1000;
  var SOAK_MS = 5000;

  var S = {
    scope: null,
    preview: null,
    recommendation: null,
    active: null,
    wizard: null,
    steps: [],
    wizardStatus: 'running',
    live: null,
    liveSource: null,
    liveFallbackTimer: null,
    liveBusy: false,
    scopeBusy: false,
    wizardBusy: false,
    scopeSeq: 0,
    wizardSeq: 0,
    liveSeq: 0,
    busy: false,
    busyTimer: null,
    busyStarted: 0,
    busyMessage: '',
    timer: null,
    wizardTimer: null,
    ageTimer: null,
    soakTimer: null,
    lastSoak: 0,
    roomRetries: 0,
    motionTimer: null,
    tab: 'verify'
  };

  function $(id) { return document.getElementById(id); }
  function roomId() {
    var sel = $('roomSel');
    if (!sel) return 0;
    if (!sel.value && sel.options.length) sel.value = sel.options[0].value;
    return parseInt(sel.value, 10) || 0;
  }
  function operator() { return (($('operator') && $('operator').value) || '').trim(); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function num(v, fallback) { var n = Number(v); return isFinite(n) ? n : fallback; }

  async function api(path, opts) {
    opts = opts || {};
    var ctrl = new AbortController();
    var to = setTimeout(function () { ctrl.abort(); }, 60000);
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

  function toast(msg) {
    var el = $('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.add('hidden'); }, 3800);
  }

  // ── pestañas ─────────────────────────────────────────────────────────
  function setTab(name) {
    S.tab = name === 'radar' ? 'radar' : 'verify';
    Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === S.tab);
      b.setAttribute('aria-selected', b.getAttribute('data-tab') === S.tab ? 'true' : 'false');
    });
    var verify = $('view-verify');
    var radar = $('view-radar');
    if (verify) verify.classList.toggle('hidden', S.tab !== 'verify');
    if (radar) radar.classList.toggle('hidden', S.tab !== 'radar');
    document.body.setAttribute('data-tab', S.tab);
    if (S.tab === 'radar') {
      startPolling();
    } else {
      stopPolling();
    }
  }

  // ── carga y render ───────────────────────────────────────────────────
  function liveFromScope(payload) {
    if (!payload) return null;
    if (payload.scope) {
      var sc = payload.scope;
      return {
        device: payload.device || {},
        settings: sc.settings || {},
        raw: sc.raw || {},
        stats: sc.stats || {},
        series: sc.series || [],
        presence_mode: sc.presence_mode || null,
        door_state: sc.door_state || null,
        stale_seconds: sc.stale_seconds || 30,
        server_ts: sc.server_ts || payload.server_ts || null
      };
    }
    return payload;
  }

  async function fetchLive() {
    if (!roomId() || S.liveBusy || S.tab !== 'radar') return;
    S.liveBusy = true;
    var seq = ++S.liveSeq;
    try {
      var data = await api(API + '/live?room_id=' + roomId(), { cache: 'no-store' });
      if (seq !== S.liveSeq) return;
      S.live = data;
      renderLive();
    } catch (e) {
      // Se conserva el último estado; el tick local lo marca stale.
    } finally {
      S.liveBusy = false;
    }
  }

  async function fetchScope() {
    if (!roomId()) {
      if (S.roomRetries < 10) {
        S.roomRetries++;
        setTimeout(fetchScope, 300);
      }
      return;
    }
    if (S.scopeBusy) return;
    S.roomRetries = 0;
    S.scopeBusy = true;
    var seq = ++S.scopeSeq;
    try {
      var data = await api(API + '/scope?room_id=' + roomId() + '&window=60', { cache: 'no-store' });
      if (seq !== S.scopeSeq) return;
      S.scope = data;
      if (!S.live) {
        S.live = liveFromScope(data);
        renderLive();
      }
      renderScope();
    } catch (e) {
      if (seq === S.scopeSeq && !S.scope) renderError(e.message);
    } finally {
      S.scopeBusy = false;
    }
  }

  async function fetchWizard() {
    if (!roomId() || S.wizardBusy || S.tab !== 'radar') return;
    S.wizardBusy = true;
    var seq = ++S.wizardSeq;
    try {
      var data = await api(API + '/wizard?room_id=' + roomId(), { cache: 'no-store' });
      if (seq !== S.wizardSeq) return;
      applyWizardData(data);
      if (!S.scope && data.scope) {
        S.scope = data.scope;
        renderScope();
      }
      renderWizard();
    } catch (e) {
      // Se conserva el último wizard.
    } finally {
      S.wizardBusy = false;
    }
  }

  function applyWizardData(data) {
    if (!data || typeof data !== 'object') return;
    if (data.wizard !== undefined) S.wizard = data.wizard;
    if (Array.isArray(data.steps)) S.steps = data.steps;
    if (data.wizard_status) S.wizardStatus = data.wizard_status;
    if (data.scope && !S.scope) S.scope = data.scope;
  }

  function startLiveStream() {
    stopLiveStream();
    if (!roomId() || !window.EventSource) {
      startLiveFallback();
      fetchLive();
      return;
    }
    try {
      S.liveSource = new EventSource(API + '/event-stream?room_id=' + roomId());
      S.liveSource.addEventListener('live', function (ev) {
        try {
          var data = JSON.parse(ev.data);
          if (data && data.ok !== false && S.tab === 'radar') {
            S.live = data;
            renderLive();
          }
        } catch (e) { /* ignore */ }
      });
      S.liveSource.onopen = function () { stopLiveFallback(); };
      S.liveSource.onerror = function () { startLiveFallback(); };
    } catch (e) {
      startLiveFallback();
    }
    fetchLive();
  }

  function startLiveFallback() {
    if (S.liveFallbackTimer) return;
    S.liveFallbackTimer = setInterval(function () {
      if (!document.hidden && S.tab === 'radar') fetchLive();
    }, LIVE_FALLBACK_MS);
  }

  function stopLiveFallback() {
    if (S.liveFallbackTimer) clearInterval(S.liveFallbackTimer);
    S.liveFallbackTimer = null;
  }

  function stopLiveStream() {
    if (S.liveSource) {
      try { S.liveSource.close(); } catch (e) { /* ignore */ }
    }
    S.liveSource = null;
    stopLiveFallback();
  }

  function refreshAll() {
    return Promise.all([fetchLive(), fetchScope(), fetchWizard()]);
  }

  function tickLiveAge() {
    if (!S.live || !S.live.raw) return;
    var base = S.live.raw.age_seconds;
    if (base == null) return;
    var serverTs = S.live.server_ts ? Date.parse(S.live.server_ts) : NaN;
    var elapsed = isFinite(serverTs) ? Math.max(0, (Date.now() - serverTs) / 1000) : 0;
    var age = Math.round(Number(base) + elapsed);
    var threshold = Number(S.live.stale_seconds || 30);
    var stale = S.live.raw.stale === true || age > threshold;
    var ageEl = $('detectAge');
    if (ageEl) ageEl.textContent = 'hace ' + age + ' s';
    if (stale && S.live.raw.stale !== true) {
      var clone = Object.assign({}, S.live, {
        raw: Object.assign({}, S.live.raw, { stale: true }),
        stats: Object.assign({}, S.live.stats, { motion_recent: false })
      });
      renderDetection(clone);
    }
  }

  function renderError(message) {
    renderDetection(null);
    var host = $('scopeSvg');
    if (host) host.innerHTML = '<div class="scope-empty">No se pudo leer el radar: ' + esc(message) + '</div>';
    var chips = $('studioChips');
    if (chips) chips.innerHTML = '';
    var diag = $('studioDiagnosis');
    if (diag) diag.innerHTML = '';
  }

  function currentScope() {
    if (!S.scope) return null;
    var sc = S.scope.scope;
    if (!sc) return null;
    if (!S.preview) return sc;
    var copy = JSON.parse(JSON.stringify(sc));
    copy.settings = Object.assign({}, copy.settings, S.preview);
    var maxM = Math.max(1.5,
      num(copy.settings.maximum_range, 2),
      num(copy.stats && copy.stats.p90, 0) + 0.5);
    copy.axes = { max_m: Math.ceil(maxM * 2) / 2, grid_m: 0.5 };
    return copy;
  }

  function render() {
    if (S.live) renderLive();
    if (S.scope && S.scope.scope) renderScope();
    if (!S.live && (!S.scope || !S.scope.scope)) { renderError('sin datos'); return; }
    renderWizard();
  }

  function renderLive() {
    var live = S.live || liveFromScope(S.scope);
    if (!live) return;
    renderDetection(live);
    renderChips(live);
    renderCalibration();
  }

  function renderScope() {
    if (!S.scope || !S.scope.scope) return;
    var sc = S.scope.scope;
    var host = $('scopeSvg');
    if (host && window.RadarScope) host.innerHTML = window.RadarScope.buildSvg(currentScope() || sc);
    renderDiagnosis(sc);
    syncControls(sc);
    renderActive(sc);
    renderCalibration();
  }

  function calibrationInfo() {
    if (S.live && S.live.calibration) return S.live.calibration;
    if (S.scope && S.scope.calibration) return S.scope.calibration;
    return null;
  }

  function renderCalibration() {
    var box = $('calibrationBox');
    if (!box) return;
    var cal = calibrationInfo();
    if (!cal || !cal.status) {
      box.classList.add('hidden');
      return;
    }
    box.classList.remove('hidden');
    var state = cal.state || {};
    var effective = state.status || '';
    var status = cal.status;
    var isError = status === 'error' || effective === 'error' || status === 'missing';
    var isInvalidReported = status === 'invalid_reported';
    var isUnstable = !!cal.reported_unstable;
    var isUnknown = status === 'unknown';
    var isConverging = !isError && !isInvalidReported && !isUnstable && !isUnknown
      && (status === 'mismatch' || effective === 'converging');
    var tone = status === 'applied' && !isError && !isConverging ? 'ok' : (isError ? 'bad' : 'warn');
    box.className = 'calibration-box ' + tone;

    var labels = {
      maximum_range: 'alcance',
      minimum_range: 'mínimo',
      radar_sensitivity: 'sensibilidad',
      detection_delay: 'retardo',
      fading_time: 'fading'
    };
    var differences = cal.differences || [];
    var diffText = differences.map(function (d) { return labels[d.key] || d.key; }).join(', ');
    var title = $('calibrationTitle');
    var badge = $('calibrationBadge');
    var sub = $('calibrationSub');
    var btn = $('btnReapplyCalibration');

    if (status === 'missing') {
      if (title) title.textContent = 'Sin calibración guardada';
      if (badge) { badge.textContent = 'SIN CALIBRACIÓN'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = 'La ficha del radar no tiene una calibración válida; no se inventa ningún valor.';
      if (btn) btn.classList.add('hidden');
      return;
    }
    if (btn) btn.classList.remove('hidden');

    if (isInvalidReported) {
      var maxReported = cal.reported && cal.reported.maximum_range != null
        ? Number(cal.reported.maximum_range) : null;
      var reasonText = cal.invalid_reason ? String(cal.invalid_reason) : 'configuración fuera de rango';
      if (title) title.textContent = 'Reporte inválido del radar';
      if (badge) { badge.textContent = 'REPORTE INVÁLIDO'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = 'El radar publica un valor imposible (' + reasonText
        + (maxReported != null ? ' · máximo ' + maxReported.toFixed(2) + ' m' : '')
        + '). No se modifica la calibración guardada: revisa el broker/converter Zigbee2MQTT.';
      return;
    }
    if (isUnstable) {
      var variants = (cal.reported_variants || []).slice(0, 3).map(function (v) {
        return Number(v.maximum_range).toFixed(2) + ' m';
      }).join(' / ');
      if (title) title.textContent = 'Reportes contradictorios del radar';
      if (badge) { badge.textContent = 'REPORTE INESTABLE'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = 'El radar alterna entre configuraciones distintas'
        + (variants ? ' (' + variants + ')' : '')
        + '. No se evalúa una calibración estable: revisa el dispositivo y el converter Zigbee2MQTT.';
      return;
    }
    if (isUnknown) {
      if (title) title.textContent = 'Sin datos recientes del radar';
      if (badge) { badge.textContent = 'SIN DATOS'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = 'No hay un reporte reciente para comparar con la calibración guardada.';
      return;
    }
    if (status === 'applied' && effective !== 'error' && effective !== 'converging') {
      if (title) title.textContent = 'Calibración aplicada';
      if (badge) { badge.textContent = 'OK'; badge.className = 'badge ok'; }
      if (sub) {
        sub.textContent = 'El radar reporta exactamente la configuración guardada en la BD.';
        if (cal.reported_invalid) {
          sub.textContent += ' Algún reporte aislado trajo un valor inválido ('
            + (cal.reported_invalid_reason || 'fuera de rango')
            + '); se usa la última lectura válida y no se cambia la calibración.';
        }
      }
    } else if (isError) {
      if (title) title.textContent = 'No se pudo aplicar la calibración';
      if (badge) { badge.textContent = 'ERROR'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = (state.last_error ? 'Último error: ' + state.last_error + '. ' : '')
        + 'El sistema no deja el radar con una configuración distinta: revisa el perfil y pulsa «Reaplicar».';
    } else {
      if (title) title.textContent = 'Convergiendo a la calibración guardada';
      if (badge) { badge.textContent = 'CONVERGIENDO'; badge.className = 'badge warn'; }
      if (sub) sub.textContent = 'El radar reporta distinto (' + (diffText || 'configuración') + '). '
        + 'Se está re-aplicando automáticamente la calibración de la BD.';
    }
  }

  async function reapplyCalibration() {
    if (!roomId() || S.busy) return;
    setBusy(true, 'Reaplicando calibración guardada…');
    var out = $('calibrationDirect');
    try {
      var data = await api(API + '/reapply', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room_id: roomId() })
      });
      if (data.calibration) {
        S.live = Object.assign({}, S.live || {}, { calibration: data.calibration });
      }
      if (out) out.textContent = 'Calibración guardada publicada y confirmada por push.';
      var okStatus = $('applyStatus');
      if (okStatus) okStatus.textContent = 'Calibración guardada reaplicada.';
      toast('Calibración guardada reaplicada');
      await refreshAll();
    } catch (e) {
      if (out) out.textContent = 'No se pudo reaplicar: ' + e.message;
      var errStatus = $('applyStatus');
      if (errStatus) errStatus.textContent = 'No se pudo reaplicar: ' + e.message;
      toast(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function directRead() {
    var out = $('calibrationDirect');
    if (!roomId()) return;
    if (out) out.textContent = 'Consultando el estado directo al radar…';
    try {
      var data = await api(API + '/direct?room_id=' + roomId(), { cache: 'no-store' });
      var d = data.direct || {};
      if (!d.ok) {
        if (out) out.textContent = 'Lectura directa: ' + (d.error || 'sin datos');
        return;
      }
      var s = d.settings || {};
      var payload = d.payload || {};
      var parts = [
        'presencia=' + String(d.presence),
        'distancia=' + (d.target_distance != null ? Number(d.target_distance).toFixed(2) + ' m' : '—'),
        'alcance=' + (s.maximum_range != null ? Number(s.maximum_range).toFixed(2) + ' m' : '—'),
        'mínimo=' + (s.minimum_range != null ? Number(s.minimum_range).toFixed(2) + ' m' : '—'),
        'LQI=' + (payload.linkquality != null ? payload.linkquality : '—')
      ];
      if (out) out.textContent = 'Lectura directa: ' + parts.join(' · ');
      if (data.calibration) {
        S.live = Object.assign({}, S.live || {}, { calibration: data.calibration });
        renderCalibration();
      }
    } catch (e) {
      if (out) out.textContent = 'Lectura directa falló: ' + e.message;
    }
  }

  function renderDetection(sc) {
    var banner = $('radarDetection');
    if (!banner) return;
    var model = (window.RadarScope && window.RadarScope.detectionModel)
      ? window.RadarScope.detectionModel(sc)
      : { tone: 'dim', icon: '…', title: 'SIN DATOS', subtitle: 'Sin datos del radar.', ageSeconds: null };
    banner.className = 'detect-banner ' + (model.tone || 'dim');
    var icon = $('detectIcon');
    if (icon) icon.textContent = model.icon || '…';
    var title = $('detectTitle');
    if (title) title.textContent = model.title || 'SIN DATOS';
    var sub = $('detectSub');
    if (sub) sub.textContent = model.subtitle || '';
    var age = $('detectAge');
    if (age) age.textContent = model.ageSeconds != null ? 'hace ' + Math.round(model.ageSeconds) + ' s' : '—';
  }

  function renderChips(sc) {
    var host = $('studioChips');
    if (!host) return;
    var dev = sc.device || (S.scope && S.scope.device) || {};
    var settings = sc.settings || {};
    var age = sc.raw && sc.raw.age_seconds != null ? sc.raw.age_seconds + ' s' : '—';
    var mode = sc.presence_mode || '—';
    var chest = [
      '<span class="pill ' + (dev.health === 'online' ? 'ok' : dev.health === 'stale' ? 'warn' : 'dim') + '">'
        + esc(dev.label || 'RADAR') + ' · ' + esc(dev.health || '—') + '</span>',
      '<span class="pill dim">último push ' + esc(age) + '</span>',
      '<span class="pill dim">modo ' + esc(mode) + '</span>'
    ];
    if (settings.maximum_range != null) {
      chest.push('<span class="pill dim">alcance ' + Number(settings.maximum_range).toFixed(2) + ' m</span>');
    }
    if (settings.radar_sensitivity != null) {
      chest.push('<span class="pill dim">sens ' + Math.round(settings.radar_sensitivity) + '/9</span>');
    }
    if (sc.door_state) {
      chest.push('<span class="pill ' + (sc.door_state === 'OPEN' ? 'warn' : 'dim') + '">puerta ' + esc(sc.door_state) + '</span>');
    }
    host.innerHTML = chest.join('');
  }

  function renderDiagnosis(sc) {
    var host = $('studioDiagnosis');
    if (!host) return;
    var items = sc.diagnosis || [];
    if (!items.length) {
      host.innerHTML = '<div class="check"><span class="dot ok"></span> Sin incidencias detectadas.</div>';
      return;
    }
    host.innerHTML = items.map(function (item) {
      var cls = item.severity === 'error' ? 'bad' : item.severity === 'warn' ? 'warn' : 'ok';
      return '<div class="check"><span class="dot ' + cls + '"></span> ' + esc(item.message) + '</div>';
    }).join('');
  }

  function syncControls(sc) {
    var settings = sc.settings || {};
    var sens = $('sensRange');
    var range = $('rangeRange');
    var min = $('minRange');
    var delay = $('delayRange');
    var fading = $('fadingRange');
    if (!S.preview) {
      if (sens && settings.radar_sensitivity != null) sens.value = String(Math.round(settings.radar_sensitivity));
      if (range && settings.maximum_range != null) range.value = String(settings.maximum_range);
      if (min && settings.minimum_range != null) min.value = String(settings.minimum_range);
      if (delay && settings.detection_delay != null) delay.value = String(settings.detection_delay);
      if (fading && settings.fading_time != null) fading.value = String(settings.fading_time);
    }
    updateSliderLabels();
    var suggestRange = sc.suggestions && sc.suggestions.maximum_range;
    var rangeHint = $('rangeHint');
    if (rangeHint) {
      if (suggestRange && suggestRange.value != null) {
        rangeHint.textContent = 'Sugerido: ' + Number(suggestRange.value).toFixed(2) + ' m ('
          + (suggestRange.reason === 'walk_boundary' ? 'borde medido' : 'huella observada') + ').';
      } else {
        rangeHint.textContent = 'Mueve el slider: el círculo cambia al instante, pero el radar solo recibe el cambio al pulsar Aplicar.';
      }
    }
    var sensSug = sc.suggestions && sc.suggestions.radar_sensitivity;
    var sensHint = $('sensHint');
    if (sensHint) {
      if (sensSug && sensSug.value != null) {
        var reasons = {
          detects_reliably: 'detecta de forma fiable',
          motion_without_presence: 'hay movimiento pero no confirma presencia',
          no_motion_no_presence: 'no se ha visto movimiento ni presencia',
          presence_without_motion: 'hay presencia pero pocos ecos de movimiento',
          marginal: 'detección justa',
          no_motion: 'sin movimiento en la ventana'
        };
        sensHint.textContent = 'Sugerido: ' + sensSug.value + '/9 · ' + (reasons[sensSug.reason] || sensSug.reason) + '.';
      } else {
        sensHint.textContent = 'La sensibilidad alta detecta más, pero también puede disparar falsos positivos.';
      }
    }
    var applyStatus = $('applyStatus');
    if (applyStatus && S.preview) applyStatus.textContent = 'Cambios sin aplicar. Pulsa «Aplicar al radar».';
  }

  function updateSliderLabels() {
    var sens = $('sensRange');
    var range = $('rangeRange');
    var sensOut = $('sensOut');
    var rangeOut = $('rangeOut');
    if (sens && sensOut) {
      var label = window.RadarScope ? window.RadarScope.sensitivityLabel(sens.value) : '';
      sensOut.textContent = Math.round(num(sens.value, 7)) + '/9 · ' + label;
    }
    if (range && rangeOut) rangeOut.textContent = Number(range.value).toFixed(2) + ' m';
    var min = $('minRange');
    var minOut = $('minOut');
    if (min && minOut) minOut.textContent = Number(min.value).toFixed(2) + ' m';
    var delay = $('delayRange');
    var delayOut = $('delayOut');
    if (delay && delayOut) delayOut.textContent = Number(delay.value).toFixed(2) + ' s';
    var fading = $('fadingRange');
    var fadingOut = $('fadingOut');
    if (fading && fadingOut) fadingOut.textContent = Number(fading.value).toFixed(1) + ' s';
  }

  function readControls() {
    var out = {
      radar_sensitivity: Math.round(num($('sensRange') && $('sensRange').value, 7)),
      maximum_range: Number(num($('rangeRange') && $('rangeRange').value, 6)),
      minimum_range: Number(num($('minRange') && $('minRange').value, 0.6)),
      detection_delay: Number(num($('delayRange') && $('delayRange').value, 0.1)),
      fading_time: Number(num($('fadingRange') && $('fadingRange').value, 15.5))
    };
    return out;
  }

  function markPending() {
    S.preview = readControls();
    updateSliderLabels();
    render();
  }

  function renderWizard() {
    var host = $('wizardSteps');
    var summary = $('wizardSummary');
    var statusEl = $('wizardStatus');
    if (!host || !window.RadarWizard) return;
    var steps = S.steps || [];
    if (!steps.length) {
      host.innerHTML = '<div class="hint">Ajusta el perfil y pulsa «Aplicar al radar» para iniciar la calibración guiada.</div>';
      if (summary) summary.innerHTML = '';
      return;
    }
    var current = S.wizard ? (S.wizard.current_step || '') : 'profile';
    var html = '';
    steps.forEach(function (step) {
      var visual = window.RadarWizard.visualStatus(step.status);
      var model = window.RadarWizard.actionModel(step);
      var lines = window.RadarWizard.detailLines(step);
      var isCurrent = step.id === current;
      html += '<div class="wstep ' + esc(visual) + '">'
        + '<div class="wstep-num">' + esc(window.RadarWizard.statusIcon(step.status)) + '</div>'
        + '<div class="wstep-body">'
        + '<div class="wstep-title">' + esc(step.title)
        + ' <span class="pill ' + (visual === 'passed' ? 'ok' : visual === 'failed' ? 'bad' : visual === 'awaiting_decision' ? 'warn' : 'dim') + '">'
        + esc(window.RadarWizard.statusLabel(step.status)) + '</span></div>'
        + '<div class="wstep-desc">' + esc(step.description || '') + '</div>';
      if (isCurrent && model.note) {
        html += '<div class="wstep-detail">' + esc(model.note) + '</div>';
      }
      lines.forEach(function (line) {
        html += '<div class="wstep-detail"><span class="line">' + esc(line) + '</span></div>';
      });
      if (isCurrent && model.buttons.length) {
        html += '<div class="wstep-actions">';
        model.buttons.forEach(function (btn) {
          html += '<button class="' + (btn.primary ? 'primary' : 'ghost') + '" data-wizard="' + esc(btn.action) + '"'
            + (btn.value != null ? ' data-value="' + esc(btn.value) + '"' : '') + '>' + esc(btn.label) + '</button>';
        });
        html += '</div>';
      }
      html += '</div></div>';
    });
    host.innerHTML = html;
    if (statusEl) {
      statusEl.textContent = S.wizard && S.wizard.status === 'config_changed'
        ? 'La configuración del radar ha cambiado. Vuelve a aplicar el perfil para reiniciar.'
        : '';
    }
    if (summary) {
      summary.innerHTML = (S.wizard && S.wizard.status === 'done' && S.wizard.summary)
        ? renderWizardSummary(S.wizard.summary) : '';
    }
  }

  function renderWizardSummary(sum) {
    if (!sum) return '';
    var rows = [];
    if (sum.settings) {
      rows.push(['Perfil', 'sens ' + sum.settings.radar_sensitivity + '/9 · alcance '
        + sum.settings.maximum_range + ' m · mín ' + sum.settings.minimum_range
        + ' m · delay ' + sum.settings.detection_delay + ' s']);
    }
    if (sum.walk && sum.walk.loss_distance != null) {
      rows.push(['Paseo', 'pérdida a ' + Number(sum.walk.loss_distance).toFixed(2) + ' m']);
    }
    if (sum.sensitivity) {
      var dec = sum.sensitivity.decision || '—';
      var rec = sum.sensitivity.recommendation && sum.sensitivity.recommendation.value != null
        ? ' · ' + sum.sensitivity.recommendation.value + '/9' : '';
      rows.push(['Sensibilidad', dec + rec]);
    }
    if (sum.finished_at) rows.push(['Fin', sum.finished_at]);
    return '<div class="wizard-summary"><h3>Radar calibrado</h3>'
      + rows.map(function (r) {
        return '<div class="techrow"><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
      }).join('') + '</div>';
  }

  async function postWizard(action, extra) {
    var body = Object.assign({ room_id: roomId(), action: action, operator: operator() }, extra || {});
    return await api(API + '/wizard', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });
  }

  async function wizardAction(action, extra, statusEl) {
    if (S.busy) return null;
    setBusy(true, 'Ejecutando…');
    try {
      var data = await postWizard(action, extra);
      applyWizardData(data);
      if (data && data.scope) S.scope = data.scope;
      render();
      refreshAll();
      return data;
    } catch (e) {
      if (e.data && typeof e.data === 'object') applyWizardData(e.data);
      if (statusEl) statusEl.textContent = e.message;
      render();
      return null;
    } finally {
      setBusy(false);
    }
  }

  function renderActive(sc) {
    var state = S.active || (sc && sc.active_operation) || null;
    var auto = !!(state && state.action === 'auto_range');
    var soaking = !!(state && state.action === 'soak');
    var reentry = !!(state && state.action === 'reentry');
    toggle('btnAutoRangeStart', !auto);
    toggle('btnAutoRangeFinish', auto);
    toggle('btnAutoRangeCancel', auto);
    toggle('btnSoakStart', !soaking);
    toggle('btnSoakCancel', soaking);
    toggle('btnReentryMark', !reentry);
    toggle('btnReentryCheck', reentry);
    var ar = $('autoRangeStatus');
    if (ar) {
      if (auto) {
        ar.textContent = state.phase === 'walking'
          ? 'Paseo en curso desde ' + shortTime(state.started_at) + '. Aléjate hasta que deje de detectarte.'
          : 'Operación de alcance en curso.';
      } else if (!ar.textContent) {
        ar.textContent = '';
      }
    }
    var sk = $('soakStatus');
    if (sk) {
      if (soaking) sk.textContent = 'Reposo en curso desde ' + shortTime(state.started_at) + '. Reducciones: ' + (state.reductions || 0) + '/6.';
      else if (!sk.textContent) sk.textContent = '';
    }
    var re = $('reentryStatus');
    if (re) {
      if (reentry) re.textContent = 'Esperando una presencia nueva desde ' + shortTime(state.started_at) + '.';
      else if (!re.textContent) re.textContent = '';
    }
  }

  function toggle(id, show) {
    var el = $(id);
    if (el) el.classList.toggle('hidden', !show);
  }

  function shortTime(iso) {
    if (!iso) return '—';
    var s = String(iso).replace('T', ' ').replace('Z', '');
    return s.slice(11, 19) || s;
  }

  // ── acciones ─────────────────────────────────────────────────────────
  function motionTest() {
    if (S.motionTimer) return;
    var el = $('motionTestStatus');
    var btn = $('btnMotionTest');
    var start = Date.now();
    if (btn) btn.disabled = true;
    if (el) el.textContent = 'Muévete delante del radar. Quedan 10 s…';
    S.motionTimer = setInterval(function () {
      var left = Math.max(0, 10 - Math.floor((Date.now() - start) / 1000));
      if (el && left > 0) el.textContent = 'Muévete delante del radar. Quedan ' + left + ' s…';
      if (left <= 0) {
        clearInterval(S.motionTimer);
        S.motionTimer = null;
        evaluateMotionTest();
      }
    }, 1000);
  }

  async function evaluateMotionTest() {
    await fetchScope();
    var sc = S.scope && S.scope.scope;
    var el = $('motionTestStatus');
    var btn = $('btnMotionTest');
    if (btn) btn.disabled = false;
    if (!sc) return;
    var moves = (sc.series || []).filter(function (p) { return Number(p.age_s) <= 10; }).length;
    var pres = (sc.presence_series || []).filter(function (p) { return Number(p.age_s) <= 10; }).length;
    if (moves > 0 || pres > 0) {
      if (el) el.textContent = 'Detectado: ' + moves + ' ecos de distancia y ' + pres + ' reportes de presencia en 10 s.';
    } else {
      if (el) el.textContent = 'No se ha detectado movimiento ni presencia en 10 s. Revisa alimentación, LQI, sensibilidad y colocación.';
    }
  }

  async function applyProfile() {
    if (!S.scope || S.busy) return;
    var settings = S.preview || readControls();
    setBusy(true, 'Aplicando al radar…');
    try {
      var data = await api(API + '/profile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ room_id: roomId(), settings: settings })
      });
      S.scope = data.scope;
      S.preview = null;
      S.active = null;
      try {
        var w = await postWizard('profile_applied');
        applyWizardData(w);
        if (w && w.scope) S.scope = w.scope;
      } catch (we) {
        toast('Perfil aplicado, pero no se pudo iniciar la guía: ' + we.message);
      }
      var st = $('applyStatus');
      if (st) st.textContent = 'Aplicado y confirmado por push.';
      render();
      refreshAll();
      toast('Perfil aplicado; calibración guiada reiniciada');
    } catch (e) {
      if (e.data && e.data.scope) S.scope = e.data.scope;
      var st2 = $('applyStatus');
      if (st2) st2.textContent = e.message;
      render();
      toast(e.message);
    } finally {
      setBusy(false);
    }
  }

  async function restoreBaseline() {
    if (S.busy) return;
    var b = (S.scope && S.scope.baseline) || {
      maximum_range: 6.0, minimum_range: 0.6, radar_sensitivity: 7,
      detection_delay: 0.1, fading_time: 15.5
    };
    S.preview = b;
    render();
    await applyProfile();
  }

  async function autotune(action, extra, statusEl, successText) {
    if (S.busy) return null;
    setBusy(true, 'Ejecutando…');
    try {
      var body = Object.assign({ room_id: roomId(), action: action, operator: operator() }, extra || {});
      var data = await api(API + '/autotune', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      if (data.scope) S.scope = data.scope;
      if (data.state !== undefined) S.active = data.state;
      if (successText && statusEl) statusEl.textContent = successText;
      render();
      return data;
    } catch (e) {
      if (e.data && e.data.scope) S.scope = e.data.scope;
      if (statusEl) statusEl.textContent = e.message;
      render();
      return null;
    } finally {
      setBusy(false);
    }
  }

  function setBusy(on, message) {
    S.busy = !!on;
    if (S.busyTimer) {
      clearInterval(S.busyTimer);
      S.busyTimer = null;
    }
    if (on) {
      S.liveSeq++;
      S.scopeSeq++;
      S.wizardSeq++;
      S.busyStarted = Date.now();
      S.busyMessage = message || '';
      var initial = $('applyStatus');
      if (S.busyMessage && initial) initial.textContent = S.busyMessage;
      if (S.busyMessage) {
        S.busyTimer = setInterval(function () {
          if (!S.busy) return;
          var st = $('applyStatus');
          if (!st) return;
          var seconds = Math.round((Date.now() - S.busyStarted) / 1000);
          if (seconds >= 2) st.textContent = S.busyMessage + ' (' + seconds + ' s)';
        }, 1000);
      }
    } else {
      S.busyMessage = '';
    }
    Array.prototype.forEach.call(document.querySelectorAll('#view-radar button'), function (b) {
      b.disabled = !!on;
    });
  }

  async function autoRangeStart() {
    var el = $('autoRangeStatus');
    var data = await autotune('auto_range_start', {}, el);
    if (data) {
      S.active = data.state;
      if (el) el.textContent = data.instruction || 'Paseo iniciado.';
      render();
    }
  }

  async function autoRangeFinish() {
    var el = $('autoRangeStatus');
    var data = await autotune('auto_range_finish', {}, el);
    if (data) {
      S.active = null;
      if (el) {
        el.textContent = 'Borde medido: ' + Number(data.boundary.distance_m).toFixed(2)
          + ' m · alcance aplicado: ' + Number(data.applied_maximum_range).toFixed(2) + ' m.';
      }
      toast('Alcance calibrado por paseo');
      render();
    }
  }

  async function sensitivityAnalyze() {
    var el = $('sensitivityStatus');
    var data = await autotune('sensitivity_recommend', {}, el);
    if (data && data.recommendation) {
      S.recommendation = data.recommendation;
      var r = data.recommendation;
      if (el) {
        el.textContent = 'Sugerencia: ' + (r.value == null ? '—' : r.value + '/9')
          + ' · ' + r.reason + ' · ' + (r.stats ? r.stats.moves + ' ecos/' + r.stats.window_seconds + ' s' : '');
      }
      toggle('btnSensitivityApply', r.value != null);
      render();
    }
  }

  async function sensitivityApply() {
    if (!S.recommendation || S.recommendation.value == null) return;
    var el = $('sensitivityStatus');
    var data = await autotune('sensitivity_apply', { value: S.recommendation.value }, el);
    if (data) {
      if (el) el.textContent = 'Sensibilidad aplicada: ' + S.recommendation.value + '/9.';
      toggle('btnSensitivityApply', false);
      toast('Sensibilidad aplicada y confirmada');
      render();
    }
  }

  async function soakStart() {
    var el = $('soakStatus');
    var data = await autotune('soak_start', {}, el);
    if (data) {
      S.active = data.state;
      S.lastSoak = Date.now();
      if (el) el.textContent = data.instruction || 'Reposo iniciado.';
      render();
    }
  }

  async function soakCheck() {
    var el = $('soakStatus');
    var data = await autotune('soak_check', {}, el);
    if (!data) return;
    if (data.result === 'reducing') {
      S.active = data.state;
      S.lastSoak = Date.now();
      if (el) el.textContent = data.message || 'Alcance reducido. Sigue fuera.';
      render();
      return;
    }
    if (data.result === 'clear') {
      S.active = null;
      if (el) el.textContent = data.message || 'Reposo correcto.';
      toast('Exterior verificado: no detecta fuera');
      render();
      return;
    }
    if (data.result === 'stopped') {
      S.active = data.state || null;
      if (el) el.textContent = data.message || 'No se pudo dejar de detectar fuera.';
      render();
      return;
    }
    if (data.verdict) {
      var v = data.verdict;
      if (el) {
        el.textContent = v.result === 'inconclusive' && v.reason === 'too_early'
          ? 'Esperando ' + (v.remaining_seconds != null ? v.remaining_seconds : '?') + ' s de reposo…'
          : 'Reposo: ' + (v.reason || v.result) + (v.remaining_seconds != null ? ' · ' + v.remaining_seconds + ' s' : '');
      }
    }
    if (data.state) S.active = data.state;
    render();
  }

  async function reentryMark() {
    var el = $('reentryStatus');
    var data = await autotune('reentry_mark', {}, el);
    if (data) {
      S.active = data.state;
      if (el) el.textContent = data.instruction || 'Salida marcada. Entra ahora.';
      render();
    }
  }

  async function reentryCheck() {
    var el = $('reentryStatus');
    var data = await autotune('reentry_check', {}, el);
    if (data) {
      if (data.result === 'entered') {
        S.active = null;
        if (el) el.textContent = data.message || 'Reentrada verificada.';
        toast('Reentrada verificada');
      } else if (data.result === 'timeout') {
        S.active = null;
        if (el) el.textContent = data.message || 'No ha llegado presencia nueva.';
      } else if (el && data.verdict) {
        el.textContent = 'Esperando presencia… ' + (data.verdict.remaining_seconds != null ? data.verdict.remaining_seconds + ' s' : '');
      }
      render();
    }
  }

  async function cancelActive() {
    var data = await autotune('cancel', {}, $('applyStatus'));
    if (data) {
      S.active = null;
      toast('Operación cancelada');
      render();
    }
  }

  async function restoreFromToolbar() {
    var data = await autotune('restore', {}, $('applyStatus'));
    if (data) {
      S.active = null;
      toast('Ajustes iniciales restaurados');
      render();
    }
  }

  // ── polling ──────────────────────────────────────────────────────────
  function startPolling() {
    stopPolling();
    startLiveStream();
    startLiveFallback();
    fetchLive();
    fetchScope();
    fetchWizard();
    S.timer = setInterval(function () {
      if (document.hidden || S.busy || S.tab !== 'radar') return;
      fetchScope();
    }, POLL_MS);
    S.wizardTimer = setInterval(function () {
      if (document.hidden || S.busy || S.tab !== 'radar') return;
      fetchWizard();
    }, POLL_MS);
    S.ageTimer = setInterval(function () {
      if (document.hidden || S.tab !== 'radar') return;
      tickLiveAge();
    }, 1000);
    S.soakTimer = setInterval(function () {
      if (document.hidden || S.busy || S.tab !== 'radar') return;
      var active = S.active || (S.scope && S.scope.scope && S.scope.scope.active_operation);
      if (!S.wizard && active && active.action === 'soak') {
        S.lastSoak = Date.now();
        soakCheck();
      }
    }, SOAK_MS);
  }

  function stopPolling() {
    stopLiveStream();
    ['timer', 'wizardTimer', 'ageTimer', 'soakTimer'].forEach(function (key) {
      if (S[key]) clearInterval(S[key]);
      S[key] = null;
    });
  }

  // ── arranque ─────────────────────────────────────────────────────────
  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (b) {
      b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); });
    });
    var sel = $('roomSel');
    if (sel) {
      sel.addEventListener('change', function () {
        S.scope = null; S.live = null; S.wizard = null; S.steps = [];
        S.preview = null; S.recommendation = null; S.active = null;
        var directOut = $('calibrationDirect');
        if (directOut) directOut.textContent = '';
        if (S.motionTimer) { clearInterval(S.motionTimer); S.motionTimer = null; }
        if (S.tab === 'radar') { stopPolling(); startPolling(); }
      });
    }
    ['sensRange', 'rangeRange', 'minRange', 'delayRange', 'fadingRange'].forEach(function (id) {
      var el = $(id);
      if (el) el.addEventListener('input', markPending);
    });
    bind('btnMotionTest', motionTest);
    bind('btnApplyProfile', applyProfile);
    bind('btnReapplyCalibration', reapplyCalibration);
    bind('btnDirectRead', directRead);
    bind('btnBaseline', restoreBaseline);
    bind('btnAutoRangeStart', autoRangeStart);
    bind('btnAutoRangeFinish', autoRangeFinish);
    bind('btnAutoRangeCancel', cancelActive);
    bind('btnSensitivity', sensitivityAnalyze);
    bind('btnSensitivityApply', sensitivityApply);
    bind('btnSoakStart', soakStart);
    bind('btnSoakCancel', cancelActive);
    bind('btnReentryMark', reentryMark);
    bind('btnReentryCheck', reentryCheck);
    bind('btnRestore', restoreFromToolbar);
    bind('btnWizardReset', function () { wizardAction('reset', {}, $('wizardStatus')); });
    var wizardHost = $('wizardSteps');
    if (wizardHost) {
      wizardHost.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-wizard]');
        if (!btn || btn.disabled) return;
        var action = btn.getAttribute('data-wizard');
        var current = S.wizard ? (S.wizard.current_step || '') : '';
        if (action === 'start') {
          wizardAction('step_start', { step_id: current }, $('wizardStatus'));
        } else if (action === 'walk_pass') {
          wizardAction('step_pass', {}, $('wizardStatus'));
        } else if (action === 'sensitivity_accept') {
          wizardAction('sensitivity_accept', {}, $('wizardStatus'));
        } else if (action === 'sensitivity_keep') {
          wizardAction('sensitivity_keep', {}, $('wizardStatus'));
        } else if (action === 'retry') {
          wizardAction('retry', {}, $('wizardStatus'));
        } else if (action === 'reset') {
          wizardAction('reset', {}, $('wizardStatus'));
        }
      });
    }
    var fromUrl = '';
    try { fromUrl = new URLSearchParams(location.search).get('tab') || ''; } catch (e) { fromUrl = ''; }
    setTab(fromUrl === 'radar' ? 'radar' : 'verify');
  }

  function bind(id, fn) {
    var el = $(id);
    if (el) el.addEventListener('click', fn);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
