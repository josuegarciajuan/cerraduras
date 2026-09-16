/**
 * cal-walktest.js — F44+ (RF-52.4): lógica PURA del modo "prueba de paseo" del
 * calibrador de presencia.
 *
 * El 24G V3 no reporta distancia útil (`target_dis_closest` siempre 0), así que
 * el radio se elige empíricamente: el técnico se coloca en el límite deseado
 * (fase `boundary`) y luego se aleja (fase `away`). Con esas lecturas se
 * recomienda mantener, subir o bajar `far_detection` un paso.
 *
 * UMD: global `CalWalkTest` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.CalWalkTest = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  var ACTION = {
    KEEP:     'keep',      // radio correcto
    INCREASE: 'increase',  // no detecta ni en el límite → subir un paso
    DECREASE: 'decrease',  // sigue detectando al alejarse → bajar un paso
    SHIELD:   'shield',    // no hay paso útil → reorientar/apantallar
    NO_DATA:  'no_data'    // sin lecturas
  };

  /** Presencia efectiva: `presence` (estático) y `move` cuentan como detectado. */
  function isDetected(state) {
    return state === 'presence' || state === 'move';
  }

  /**
   * Resume una fase de lecturas `[{t, state}]`.
   * @returns {{total:number, detectedCount:number, noneCount:number,
   *            detected:boolean, allNone:boolean, firstNoneAt:number, lastState:(string|null)}}
   */
  function summarizeReadings(readings) {
    var rs = Array.isArray(readings) ? readings : [];
    var detectedCount = 0, noneCount = 0, firstNoneAt = 0, lastState = null;
    for (var i = 0; i < rs.length; i++) {
      var st = rs[i] && rs[i].state;
      lastState = st;
      if (isDetected(st)) {
        detectedCount++;
      } else if (st === 'none') {
        noneCount++;
        if (!firstNoneAt) firstNoneAt = rs[i].t || 0;
      }
    }
    return {
      total:         rs.length,
      detectedCount: detectedCount,
      noneCount:     noneCount,
      detected:      detectedCount > 0,
      allNone:       rs.length > 0 && noneCount === rs.length,
      firstNoneAt:   firstNoneAt,
      lastState:     lastState
    };
  }

  /**
   * Recomienda una acción sobre `far_detection` a partir de las dos fases.
   *
   * @param {{boundary:Array, away:Array, currentFar:number, caps:Object,
   *          effectiveMin:(number|undefined)}} input
   * @returns {{action:string, far:(number|null), reason:string, summary:Object}}
   */
  function suggestFarAction(input) {
    input = input || {};
    var caps = input.caps || {};
    var lo   = (caps.far_min != null) ? caps.far_min : 0;
    var hi   = (caps.far_max != null) ? caps.far_max : 900;
    var step = Math.max(1, caps.far_step || 1);
    // Piso efectivo del firmware (24G V3 rechaza 75 → 150). Se puede pasar
    // explícito; si no, se toma el mínimo declarado por el dispositivo.
    var effMin = (input.effectiveMin != null) ? input.effectiveMin
               : (caps.far_effective_min != null ? caps.far_effective_min : lo);
    var floor  = Math.max(lo, effMin);
    var currentFar = (input.currentFar != null) ? input.currentFar : floor;

    var boundary = summarizeReadings(input.boundary);
    var away     = summarizeReadings(input.away);
    var summary  = { boundary: boundary, away: away, floor: floor, currentFar: currentFar };

    if (boundary.total === 0 && away.total === 0) {
      return { action: ACTION.NO_DATA, far: null, reason: 'Sin lecturas del sensor.', summary: summary };
    }

    var boundaryDetected = boundary.detected;
    var awayDetected     = away.detected;

    // Caso ideal: detecta en el límite y deja de detectar al alejarse.
    if (boundaryDetected && !awayDetected) {
      return {
        action: ACTION.KEEP, far: currentFar, summary: summary,
        reason: 'Detecta en el límite y deja de detectar al alejarte. El radio es correcto.'
      };
    }

    // No detecta ni en el límite → radio demasiado corto.
    if (!boundaryDetected) {
      if (currentFar + step > hi) {
        return {
          action: ACTION.SHIELD, far: null, summary: summary,
          reason: 'No detecta ni en el límite y el radio ya está al máximo. Revisa orientación o apantalla el radar.'
        };
      }
      return {
        action: ACTION.INCREASE, far: Math.min(hi, currentFar + step), summary: summary,
        reason: 'No detecta en el límite. Sube el radio un paso.'
      };
    }

    // Detecta en el límite Y sigue detectando al alejarse → radio demasiado largo.
    if (currentFar - step < floor) {
      return {
        action: ACTION.SHIELD, far: null, summary: summary,
        reason: 'Sigue detectando al alejarte y el radio ya está en el mínimo efectivo (' +
                floor + ' cm). Reorienta o apantalla el radar.'
      };
    }
    return {
      action: ACTION.DECREASE, far: Math.max(floor, currentFar - step), summary: summary,
      reason: 'Sigue detectando al alejarte. Baja el radio un paso.'
    };
  }

  return {
    isDetected:        isDetected,
    summarizeReadings: summarizeReadings,
    suggestFarAction:  suggestFarAction,
    ACTION:            ACTION
  };
});
