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
      parts.push('<circle cx="200" cy="200" r="' + (d * ppm).toFixed(2)
        + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-opacity="' + opacity.toFixed(2) + '"/>');
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
      parts.push('<circle cx="' + lx.toFixed(2) + '" cy="200" r="4" fill="#e0e0e8"/>');
      parts.push('<line x1="200" y1="200" x2="' + lx.toFixed(2) + '" y2="200" stroke="#e0e0e8" stroke-width="1" stroke-opacity="0.4"/>');
      parts.push('<text x="' + (lx + 6).toFixed(1) + '" y="216" fill="#9a9ab0" font-size="10">eco ' + formatM(ld) + '</text>');
    }

    // Presencia: verde si DP 1 es PRESENT; ámbar si solo hay movimiento.
    if (visual.state === 'PRESENT') {
      parts.push('<circle class="scope-presence" cx="200" cy="200" r="' + (Math.min(RADIUS, maxRange * ppm + 10)).toFixed(2) + '" fill="rgba(0,230,118,0.16)"/>');
      parts.push('<circle cx="200" cy="200" r="' + Math.min(RADIUS, maxRange * ppm).toFixed(2) + '" fill="none" stroke="#00e676" stroke-width="3" opacity="0.9"/>');
    } else if (visual.state === 'MOTION') {
      parts.push('<circle class="scope-motion" cx="200" cy="200" r="' + (Math.min(RADIUS, maxRange * ppm) - 4).toFixed(2) + '" fill="none" stroke="#ff9100" stroke-width="3" opacity="0.9"/>');
    }

    // Sensor.
    parts.push('<circle cx="200" cy="200" r="13" fill="#0a0a0f" stroke="' + color + '" stroke-width="3"/>');
    parts.push('<circle cx="200" cy="200" r="4" fill="' + color + '"/>');
    parts.push('<text x="200" y="' + (CENTER + 30) + '" fill="#9a9ab0" font-size="10" text-anchor="middle">RADAR</text>');

    // Leyenda inferior.
    parts.push('<text x="12" y="378" fill="#9a9ab0" font-size="10">Anillos = densidad de ecos (distancia). El radar no informa ángulo.</text>');
    parts.push('<text x="12" y="392" fill="#9a9ab0" font-size="10">Círculo grande = alcance configurado. Borde = pérdida real de presencia.</text>');

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
    histogram: histogram,
    buildSvg: buildSvg
  };
});
