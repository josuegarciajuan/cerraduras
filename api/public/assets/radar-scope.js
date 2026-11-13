/**
 * radar-scope.js — F125/RF-350.
 *
 * Lógica PURA y dibujo del "Radar Studio". No lee el DOM, no hace red y no
 * depende del reloj: recibe el bloque `scope` del servidor y devuelve una vista
 * cenital honesta.
 *
 * El radar Zigbee solo informa DISTANCIA (DP 9), no ángulo. Por eso:
 *  - el círculo grande es el alcance configurado, no una detección real;
 *  - los ecos se dibujan como anillos de densidad radial, sin inventar dirección;
 *  - el último eco se marca sobre un eje de referencia, etiquetado como distancia.
 *
 * UMD: global `RadarScope` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.RadarScope = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  var SIZE = 400;
  var CENTER = 200;
  var RADIUS = 168;
  var MAX_AXIS_M = 12;

  function num(v, fallback) {
    var n = Number(v);
    return isFinite(n) ? n : fallback;
  }

  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
  }

  /** Rampa de sensibilidad: azul (baja) → verde → ámbar → rojo (máxima). */
  function sensitivityColor(value) {
    var s = clamp(num(value, 7), 0, 9);
    if (s <= 2) return '#448aff';
    if (s <= 4) return '#18ffff';
    if (s <= 6) return '#00e676';
    if (s <= 8) return '#ffd740';
    return '#ff5252';
  }

  function sensitivityLabel(value) {
    var s = clamp(num(value, 7), 0, 9);
    if (s <= 2) return 'Baja';
    if (s <= 4) return 'Suave';
    if (s <= 6) return 'Normal';
    if (s <= 8) return 'Alta';
    return 'Máxima';
  }

  function formatM(v) {
    if (v === null || v === undefined || v === '') return '—';
    var n = Number(v);
    return isFinite(n) ? n.toFixed(2) + ' m' : '—';
  }

  function scaleFor(scope) {
    var axes = (scope && scope.axes) || {};
    return clamp(num(axes.max_m, 2), 1.5, MAX_AXIS_M);
  }

  function pxPerM(scope) {
    return RADIUS / scaleFor(scope);
  }

  function presenceVisual(scope) {
    var raw = (scope && scope.raw) || {};
    var stats = (scope && scope.stats) || {};
    var state = String(raw.state || 'UNKNOWN').toUpperCase();
    if (raw.stale) {
      return { state: 'STALE', label: 'SIN DATOS', tone: 'bad', motion: false };
    }
    if (state === 'PRESENT') {
      return { state: 'PRESENT', label: 'PRESENTE', tone: 'ok', motion: true };
    }
    if (stats.motion_recent) {
      return { state: 'MOTION', label: 'MOVIMIENTO sin presencia', tone: 'warn', motion: true };
    }
    if (state === 'ABSENT') {
      return { state: 'ABSENT', label: 'AUSENTE', tone: 'dim', motion: false };
    }
    return { state: 'UNKNOWN', label: 'SIN DATOS', tone: 'warn', motion: false };
  }

  /**
   * F125.1.6: distancia positiva que puede considerarse ACTUAL. Se descarta
   * cualquier lectura fuera del umbral de frescura del radar.
   */
  function freshDistance(scope) {
    if (!scope) return null;
    var maxAge = Number(scope.stale_seconds);
    if (!isFinite(maxAge) || maxAge <= 0) maxAge = 30;
    function accept(value, age) {
      var d = Number(value);
      if (!isFinite(d) || d <= 0) return null;
      var a = age != null ? Number(age) : null;
      if (a != null && (!isFinite(a) || a < 0 || a > maxAge)) return null;
      return { value: d, ageSeconds: a };
    }
    var direct = accept(scope.last_distance, scope.last_distance_age_s);
    if (direct) return direct;
    var stats = scope.stats || {};
    var fromStats = accept(stats.last_distance, stats.last_distance_age_s);
    if (fromStats) return fromStats;
    var series = scope.series || [];
    for (var i = series.length - 1; i >= 0; i--) {
      var point = series[i] || {};
      var fromSeries = accept(point.distance_m, point.age_s);
      if (fromSeries) return fromSeries;
    }
    return null;
  }

  /**
   * F130: evaluación de alcance de la última decisión operativa. El eco bruto
   * puede quedar fuera del máximo, pero la presencia solo se acredita dentro.
   */
  function rangeEvaluation(scope) {
    if (!scope) return null;
    if (scope.range_evaluation && typeof scope.range_evaluation === 'object') return scope.range_evaluation;
    if (scope.range_gate && typeof scope.range_gate === 'object') return scope.range_gate;
    var decision = scope.presence_decision || {};
    return decision.range_gate && typeof decision.range_gate === 'object' ? decision.range_gate : null;
  }

  /**
   * F132: normaliza el resultado operativo final. Acepta payloads nuevos
   * (`outcome`) y antiguos (`apply` + `discard_reason`).
   */
  function outcomeOf(decision) {
    if (!decision) return null;
    var outcome = String(decision.outcome || '').toLowerCase();
    if (outcome) return outcome;
    if (decision.apply === true) return 'applied';
    var reason = String(decision.discard_reason || '');
    if (reason === 'candidate_absent') return 'pending_absent';
    if (reason === 'entry_pending') return 'pending_present';
    if (reason === '' || reason === 'noop' || reason === 'refresh'
        || reason === 'duplicate' || reason === 'stale') return 'noop';
    return 'blocked';
  }

  /** Explicación humana del motivo operativo; el código queda en diagnóstico. */
  function reasonText(reason) {
    switch (String(reason || '')) {
      case 'no_context':
        return 'La política de esta habitación no permite aplicar la presencia.';
      case 'uncorroborated':
        return 'Falta corroboración física (puerta o cámara).';
      case 'candidate':
        return 'Esperando evidencia suficiente para confirmar la presencia.';
      case 'entry_pending':
        return 'La detección se está confirmando antes de aplicarla.';
      case 'candidate_absent':
        return 'La ausencia se está confirmando; no se ha cerrado la presencia.';
      case 'range_unverified':
      case 'no_dp9':
        return 'Aún no hay una distancia válida para este episodio.';
      case 'zero_dp9':
        return 'La distancia llegó a cero; se espera una lectura válida.';
      case 'dp1_without_dp9_door_corroborated':
        return 'La puerta abierta corrobora la presencia; el radar no envía distancia.';
      case 'dp1_without_distance':
        return 'Presencia por DP1; el radar no envía una distancia utilizable.';
      case 'config_mismatch':
        return 'La distancia no corresponde a la configuración actual del radar.';
      case 'evidence_outside_episode':
        return 'La distancia disponible es anterior a este episodio.';
      case 'cached_evidence':
        return 'La distancia es una lectura ya utilizada; se espera una nueva.';
      case 'range_exceeded':
        return 'El eco supera el alcance operativo configurado.';
      default:
        return 'La decisión operativa no se aplicó.';
    }
  }

  function pendingHint(decision) {
    var pending = decision && decision.pending;
    if (!pending || !pending.due_at) return '';
    var due = Date.parse(String(pending.due_at).replace(' ', 'T') + 'Z');
    if (!isFinite(due)) return '';
    var seconds = Math.max(0, Math.ceil((due - Date.now()) / 1000));
    return seconds > 0 ? ' Listo en ~' + seconds + ' s.' : '';
  }

  /**
   * F134: el pipeline Zigbee puede estar parado aunque el sensor siga
   * conectado. En ese caso no se puede concluir "sensor desconectado".
   */
  function pipelineDown(scope) {
    var pipeline = scope && scope.pipeline;
    if (!pipeline || pipeline.enabled !== true) return false;
    if (pipeline.healthy === false) return true;
    var state = String(pipeline.state || '').toLowerCase();
    return state === 'stopped' || state === 'disconnected'
      || state === 'stale' || state === 'unknown';
  }

  function decisionAgeSeconds(scope, decision) {
    if (!decision) return null;
    if (decision.age_seconds != null) {
      var direct = Number(decision.age_seconds);
      if (isFinite(direct) && direct >= 0) return direct;
    }
    var raw = decision.decided_at || decision.event_at;
    if (!raw) return null;
    var normalized = String(raw).trim().replace(' ', 'T');
    if (!/[zZ]$|[+-]\d\d:?\d\d$/.test(normalized)) normalized += 'Z';
    var ts = Date.parse(normalized);
    if (!isFinite(ts)) return null;
    var now = scope && scope.server_ts ? Date.parse(scope.server_ts) : Date.now();
    if (!isFinite(now)) now = Date.now();
    return Math.max(0, (now - ts) / 1000);
  }

  /**
   * F135: el banner principal representa SOLO la detección del sensor.
   * La política operativa (aplicada/bloqueada/alcance) vive en
   * operationalModel() y no debe hacer parpadear el color de este banner.
   */
  function detectionModel(scope) {
    var visual = presenceVisual(scope);
    var raw = (scope && scope.raw) || {};
    var stats = (scope && scope.stats) || {};
    var age = raw.age_seconds != null
      ? Number(raw.age_seconds)
      : (stats.last_age_s != null ? Number(stats.last_age_s) : null);
    var distance = freshDistance(scope);
    var lastDistance = distance ? distance.value : null;
    var distanceAge = distance ? distance.ageSeconds : null;
    var distanceText = lastDistance != null
      ? 'distancia ' + lastDistance.toFixed(2) + ' m'
        + (distanceAge != null && distanceAge >= 5 ? ' · hace ' + Math.round(distanceAge) + ' s' : '')
      : 'distancia no disponible';

    if (visual.state === 'STALE' || raw.stale === true) {
      if (pipelineDown(scope)) {
        return {
          state: 'PIPELINE_DOWN', tone: 'warn', icon: '⇄',
          title: 'INGESTA ZIGBEE DETENIDA',
          subtitle: 'El sensor puede estar conectado; no llegan reportes al sistema.',
          ageSeconds: age, lastDistance: lastDistance
        };
      }
      return {
        state: 'NO_DATA', tone: 'dim', icon: '…', title: 'RADAR SIN REPORTES',
        subtitle: 'El radar no envía reportes recientes.',
        ageSeconds: age, lastDistance: lastDistance
      };
    }
    if (visual.state === 'PRESENT') {
      return {
        state: 'PRESENT', tone: 'ok', icon: '✓', title: 'TE DETECTA',
        subtitle: 'Presencia detectada · ' + distanceText,
        ageSeconds: age, lastDistance: lastDistance
      };
    }
    if (visual.state === 'MOTION') {
      return {
        state: 'MOTION', tone: 'warn', icon: '≈', title: 'MOVIMIENTO SIN PRESENCIA',
        subtitle: lastDistance != null
          ? 'El radar ve ecos a ' + lastDistance.toFixed(2) + ' m, pero no ha confirmado presencia.'
          : 'El radar ve ecos, pero no ha confirmado presencia.',
        ageSeconds: age, lastDistance: lastDistance
      };
    }
    if (visual.state === 'ABSENT') {
      return {
        state: 'ABSENT', tone: 'bad', icon: '✕', title: 'NO TE DETECTA',
        subtitle: 'Sin presencia ni movimiento reciente.',
        ageSeconds: age, lastDistance: lastDistance
      };
    }
    return {
      state: 'NO_DATA', tone: 'dim', icon: '…', title: 'SIN DATOS',
      subtitle: 'Esperando al radar…',
      ageSeconds: age, lastDistance: lastDistance
    };
  }

  /**
   * F135: modelo separado de la aplicación operativa. Explica qué hizo el
   * sistema con la detección sin alterar el banner del sensor.
   */
  function operationalModel(scope) {
    var decision = (scope && scope.presence_decision) || null;
    var gate = rangeEvaluation(scope);
    if (!decision && !gate) {
      return {
        state: 'NONE', tone: 'dim', title: 'SIN DECISIÓN',
        detail: 'El radar aún no ha publicado una decisión operativa.'
      };
    }
    var outcome = outcomeOf(decision);
    var effective = String((decision && decision.effective_presence) || '').toUpperCase();
    var applied = !!(decision && decision.apply === true);
    var reason = (decision && (decision.outcome_reason || decision.discard_reason))
      || (gate && gate.reason) || '';
    var gateStatus = gate && gate.status ? String(gate.status) : '';
    var age = scope && scope.decision_age_seconds != null
      ? Number(scope.decision_age_seconds)
      : decisionAgeSeconds(scope, decision);
    var staleLimit = Number((scope && scope.stale_seconds) || 30);
    if (age != null && isFinite(age) && age > staleLimit) {
      return {
        state: 'STALE', tone: 'dim', title: 'SIN DECISIÓN RECIENTE',
        detail: 'La última decisión operativa es antigua; el radar no reporta.',
        ageSeconds: age
      };
    }

    if (applied || outcome === 'applied'
        || (effective === 'PRESENT' && outcome === 'maintained')) {
      return {
        state: 'APPLIED', tone: 'ok', title: 'APLICADA',
        detail: effective === 'PRESENT'
          ? 'Presencia aplicada a la sesión.'
          : 'Decisión aplicada.',
        ageSeconds: age
      };
    }
    if (outcome === 'blocked' || reason === 'no_context' || reason === 'uncorroborated') {
      return {
        state: 'BLOCKED', tone: 'warn', title: 'BLOQUEADA POR POLÍTICA',
        detail: reasonText(reason),
        ageSeconds: age
      };
    }
    if (gateStatus === 'unverified' || outcome === 'unverified') {
      return {
        state: 'UNVERIFIED', tone: 'warn', title: 'COMPROBANDO ALCANCE',
        detail: reasonText(reason),
        ageSeconds: age
      };
    }
    if (gateStatus === 'out_of_range' || outcome === 'out_of_range') {
      return {
        state: 'OUT_OF_RANGE', tone: 'bad', title: 'FUERA DE ALCANCE',
        detail: reasonText(reason || 'range_exceeded'),
        ageSeconds: age
      };
    }
    if (effective === 'ABSENT' && outcome === 'maintained') {
      return {
        state: 'ABSENT', tone: 'dim', title: 'AUSENCIA MANTENIDA',
        detail: 'La sesión sigue en ausencia.',
        ageSeconds: age
      };
    }
    return {
      state: 'PENDING', tone: 'dim', title: 'NO APLICADA',
      detail: reasonText(reason),
      ageSeconds: age
    };
  }

  function histogram(series, binM) {
    var bins = {};
    var max = 0;
    (series || []).forEach(function (p) {
      var d = num(p.distance_m, 0);
      if (d <= 0) return;
      var key = Math.round(d / binM) * binM;
      bins[key] = (bins[key] || 0) + 1;
      if (bins[key] > max) max = bins[key];
    });
    return { bins: bins, max: max };
  }

  function buildSvg(scope) {
    if (!scope) return '';
    var settings = scope.settings || {};
    var maxM = scaleFor(scope);
    var ppm = pxPerM(scope);
    var sens = num(settings.radar_sensitivity, 7);
    var color = sensitivityColor(sens);
    var maxRange = clamp(num(settings.maximum_range, maxM), 0.15, maxM);
    var minRange = clamp(num(settings.minimum_range, 0), 0, maxM);
    var visual = presenceVisual(scope);
    var gate = rangeEvaluation(scope);
    var outside = gate && String(gate.status) === 'out_of_range';
    var parts = [];
    var labelEvery = maxM <= 6 ? 1 : 2;

    parts.push('<svg viewBox="0 0 400 400" role="group" aria-label="Vista cenital del radar">');
    parts.push('<rect x="0" y="0" width="400" height="400" rx="18" fill="#07070c"/>');

    // Rejilla radial cada 0,5 m.
    for (var m = 0.5; m <= maxM + 0.001; m += 0.5) {
      var r = m * ppm;
      parts.push('<circle cx="200" cy="200" r="' + r.toFixed(2) + '" fill="none" stroke="#2a2a40" stroke-width="1" stroke-dasharray="3 5"/>');
    }
    // Ejes X/Y con etiquetas en metros.
    parts.push('<line x1="' + (CENTER - RADIUS) + '" y1="200" x2="' + (CENTER + RADIUS) + '" y2="200" stroke="#66668a" stroke-width="1"/>');
    parts.push('<line x1="200" y1="' + (CENTER - RADIUS) + '" x2="200" y2="' + (CENTER + RADIUS) + '" stroke="#66668a" stroke-width="1"/>');
    for (var t = labelEvery; t <= Math.floor(maxM); t += labelEvery) {
      var off = t * ppm;
      parts.push('<text x="' + (CENTER + off).toFixed(1) + '" y="' + (CENTER - 5) + '" fill="#9a9ab0" font-size="10" text-anchor="middle">' + t + 'm</text>');
      parts.push('<text x="' + (CENTER + 6) + '" y="' + (CENTER - off + 3).toFixed(1) + '" fill="#9a9ab0" font-size="10">' + t + 'm</text>');
    }

    // Huella radial de ecos DP 9 (sin ángulo real).
    var hist = histogram(scope.series || [], 0.25);
    Object.keys(hist.bins).forEach(function (key) {
      var d = Number(key);
      if (!isFinite(d) || d <= 0 || d > maxM) return;
      var count = hist.bins[key];
      var opacity = 0.12 + 0.55 * (count / Math.max(1, hist.max));
      var ringColor = d > maxRange ? '#ff9100' : color;
      var dash = d > maxRange ? ' stroke-dasharray="4 4"' : '';
      parts.push('<circle cx="200" cy="200" r="' + (d * ppm).toFixed(2)
        + '" fill="none" stroke="' + ringColor + '" stroke-width="2" stroke-opacity="' + opacity.toFixed(2) + '"' + dash + '/>');
    });

    // Hueco del mínimo: lo que está demasiado cerca no se detecta.
    if (minRange > 0) {
      parts.push('<circle cx="200" cy="200" r="' + (minRange * ppm).toFixed(2) + '" fill="none" stroke="#ff9100" stroke-width="1.5" stroke-dasharray="5 4" opacity="0.85"/>');
    }

    // Alcance configurado (no es el borde real).
    parts.push('<circle cx="200" cy="200" r="' + (maxRange * ppm).toFixed(2) + '" fill="none" stroke="' + color
      + '" stroke-width="3" stroke-dasharray="8 5" opacity="0.95"/>');
    parts.push('<text x="12" y="24" fill="' + color + '" font-size="12" font-weight="700">Alcance ' + formatM(maxRange) + '</text>');
    parts.push('<text x="12" y="40" fill="#9a9ab0" font-size="11">Sensibilidad ' + Math.round(sens) + '/9 · ' + sensitivityLabel(sens) + '</text>');

    // Borde medido por paseo (si existe).
    var boundary = scope.boundary || {};
    if (boundary.found && boundary.distance_m != null) {
      var bd = clamp(num(boundary.distance_m, 0), 0, maxM);
      parts.push('<circle cx="200" cy="200" r="' + (bd * ppm).toFixed(2) + '" fill="none" stroke="#18ffff" stroke-width="1.5" stroke-dasharray="2 4"/>');
      parts.push('<text x="200" y="' + (CENTER - bd * ppm - 6).toFixed(1) + '" fill="#18ffff" font-size="10" text-anchor="middle">borde ' + formatM(bd) + '</text>');
    }

    // Último eco: solo distancia, sobre el eje de referencia +X.
    var series = scope.series || [];
    if (series.length) {
      var last = series[series.length - 1];
      var ld = clamp(num(last.distance_m, 0), 0, maxM);
      var lx = CENTER + ld * ppm;
      var lastOutside = ld > maxRange;
      var lastColor = lastOutside ? '#ff9100' : '#e0e0e8';
      parts.push('<circle cx="' + lx.toFixed(2) + '" cy="200" r="4" fill="' + lastColor + '"/>');
      parts.push('<line x1="200" y1="200" x2="' + lx.toFixed(2) + '" y2="200" stroke="' + lastColor + '" stroke-width="1" stroke-opacity="0.4"/>');
      parts.push('<text x="' + (lx + 6).toFixed(1) + '" y="216" fill="' + lastColor + '" font-size="10">eco ' + formatM(ld) + (lastOutside ? ' · fuera' : '') + '</text>');
    }

    // Presencia: verde solo si DP 1 es PRESENT y el alcance la acredita.
    var gateBlocks = gate && (String(gate.status) === 'out_of_range' || String(gate.status) === 'unverified');
    if (visual.state === 'PRESENT' && gateBlocks) {
      var blockColor = String(gate.status) === 'out_of_range' ? '#ff9100' : '#8a8aa0';
      parts.push('<circle class="scope-range-blocked" cx="200" cy="200" r="' + Math.min(RADIUS, maxRange * ppm).toFixed(2)
        + '" fill="none" stroke="' + blockColor + '" stroke-width="3" stroke-dasharray="6 5" opacity="0.9"/>');
    } else if (visual.state === 'PRESENT') {
      parts.push('<circle class="scope-presence" cx="200" cy="200" r="' + (Math.min(RADIUS, maxRange * ppm + 10)).toFixed(2) + '" fill="rgba(0,230,118,0.16)"/>');
      parts.push('<circle cx="200" cy="200" r="' + Math.min(RADIUS, maxRange * ppm).toFixed(2) + '" fill="none" stroke="#00e676" stroke-width="3" opacity="0.9"/>');
    } else if (visual.state === 'MOTION') {
      parts.push('<circle class="scope-motion" cx="200" cy="200" r="' + (Math.min(RADIUS, maxRange * ppm) - 4).toFixed(2) + '" fill="none" stroke="#ff9100" stroke-width="3" opacity="0.9"/>');
    } else if (visual.state === 'ABSENT') {
      var absentR = Math.min(RADIUS, maxRange * ppm + 6);
      parts.push('<circle cx="200" cy="200" r="' + absentR.toFixed(2) + '" fill="none" stroke="#ff5252" stroke-width="2" stroke-opacity="0.35" stroke-dasharray="4 7"/>');
      parts.push('<circle cx="200" cy="200" r="18" fill="none" stroke="#ff5252" stroke-width="2" stroke-opacity="0.30"/>');
    } else if (visual.state === 'STALE' || visual.state === 'UNKNOWN') {
      var staleR = Math.min(RADIUS, maxRange * ppm + 6);
      parts.push('<circle cx="200" cy="200" r="' + staleR.toFixed(2) + '" fill="none" stroke="#8a8aa0" stroke-width="2" stroke-opacity="0.30" stroke-dasharray="4 7"/>');
      parts.push('<circle cx="200" cy="200" r="18" fill="none" stroke="#8a8aa0" stroke-width="2" stroke-opacity="0.25"/>');
    }

    // Sensor.
    parts.push('<circle cx="200" cy="200" r="13" fill="#0a0a0f" stroke="' + color + '" stroke-width="3"/>');
    parts.push('<circle cx="200" cy="200" r="4" fill="' + color + '"/>');
    parts.push('<text x="200" y="' + (CENTER + 30) + '" fill="#9a9ab0" font-size="10" text-anchor="middle">RADAR</text>');

    // Leyenda inferior.
    parts.push('<text x="12" y="378" fill="#9a9ab0" font-size="10">Anillos = densidad de ecos (distancia). El radar no informa ángulo.</text>');
    parts.push('<text x="12" y="392" fill="#9a9ab0" font-size="10">Círculo grande = alcance configurado. Borde = pérdida real de presencia.</text>');
    if (outside) {
      parts.push('<text x="12" y="364" fill="#ff9100" font-size="10">Ecos ámbar = fuera del alcance: evidencia, no presencia operativa.</text>');
    }

    parts.push('</svg>');
    return parts.join('');
  }

  return {
    SIZE: SIZE,
    CENTER: CENTER,
    RADIUS: RADIUS,
    MAX_AXIS_M: MAX_AXIS_M,
    clamp: clamp,
    num: num,
    sensitivityColor: sensitivityColor,
    sensitivityLabel: sensitivityLabel,
    formatM: formatM,
    scaleFor: scaleFor,
    pxPerM: pxPerM,
    presenceVisual: presenceVisual,
    rangeEvaluation: rangeEvaluation,
    detectionModel: detectionModel,
    operationalModel: operationalModel,
    outcomeOf: outcomeOf,
    reasonText: reasonText,
    histogram: histogram,
    buildSvg: buildSvg
  };
});
