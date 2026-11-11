/**
 * radar-wizard.js — F125.1.
 *
 * Lógica PURA de presentación del wizard de calibración guiada. Decide qué
 * botones puede pulsar el operador en cada paso y cómo se pinta el estado.
 * No hace red, no lee el DOM y no conoce el sensor.
 *
 * UMD: global `RadarWizard` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.RadarWizard = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  var STATUS = {
    PENDING: 'pending',
    ACTIVE: 'active',
    AWAITING: 'awaiting_decision',
    PASSED: 'passed',
    FAILED: 'failed',
    LOCKED: 'locked'
  };

  function visualStatus(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'pending') return 'active';
    if (s === 'awaiting_decision') return 'awaiting_decision';
    if (s === 'active' || s === 'passed' || s === 'failed' || s === 'locked') return s;
    return 'locked';
  }

  function statusLabel(status) {
    var s = visualStatus(status);
    if (s === 'active') return 'En curso';
    if (s === 'awaiting_decision') return 'Decisión';
    if (s === 'passed') return 'Superado';
    if (s === 'failed') return 'No superado';
    return 'Bloqueado';
  }

  function statusIcon(status) {
    var s = visualStatus(status);
    if (s === 'passed') return '✓';
    if (s === 'failed') return '✕';
    if (s === 'awaiting_decision') return '?';
    if (s === 'active') return '●';
    return '·';
  }

  function formatCountdown(value) {
    var n = Number(value);
    if (!isFinite(n) || n <= 0) return '';
    return Math.ceil(n) + ' s';
  }

  /**
   * Acciones disponibles para un paso. Solo el paso activo devuelve botones.
   * @returns {{buttons:Array<{action:string,label:string,primary:boolean,value?:*}>,
   *            note:string}}
   */
  function actionModel(step) {
    var out = { buttons: [], note: '' };
    if (!step || typeof step !== 'object') return out;
    var status = String(step.status || 'locked').toLowerCase();
    var kind = String(step.kind || '');
    var detail = (step.detail && typeof step.detail === 'object') ? step.detail : {};
    var started = !!(step.started_at || detail.started_at);

    if (status === 'passed' || status === 'locked') {
      return out;
    }
    if (kind === 'done') {
      return out;
    }
    if (kind === 'profile') {
      out.note = 'Usa los controles de «Ajuste manual» y pulsa «Aplicar al radar».';
      return out;
    }
    if (status === 'failed') {
      out.note = detail.message || 'Paso no superado.';
      out.buttons.push({ action: 'retry', label: 'Reintentar', primary: true });
      out.buttons.push({ action: 'reset', label: 'Volver a ajustar', primary: false });
      return out;
    }
    if (status === 'awaiting_decision') {
      if (kind === 'sensitivity') {
        var rec = detail.recommendation || (step.recommendation || null);
        var value = rec && rec.value != null ? rec.value : null;
        out.note = detail.message || 'Acepta o mantén la sensibilidad actual.';
        out.buttons.push({
          action: 'sensitivity_accept',
          label: value != null ? 'Aceptar ' + value + '/9' : 'Aceptar sugerencia',
          primary: true,
          value: value
        });
        out.buttons.push({ action: 'sensitivity_keep', label: 'Mantener actual', primary: false });
      }
      return out;
    }

    // pending/active
    if (kind === 'manual_walk') {
      out.note = detail.message || '';
      if (!started) {
        out.buttons.push({ action: 'start', label: 'Empezar paseo', primary: true });
      } else {
        out.buttons.push({ action: 'walk_pass', label: 'Prueba buena', primary: true });
        out.buttons.push({ action: 'start', label: 'Reiniciar paseo', primary: false });
      }
      return out;
    }
    if (kind === 'sensitivity') {
      out.note = detail.message || '';
      if (!started) {
        out.buttons.push({ action: 'start', label: 'Medir 15 s', primary: true });
      }
      return out;
    }
    // present / present_reentry / absent
    out.note = detail.message || '';
    if (!started) {
      out.buttons.push({ action: 'start', label: 'Empezar', primary: true });
    }
    return out;
  }

  function detailLines(step) {
    var lines = [];
    if (!step || typeof step !== 'object') return lines;
    var detail = (step.detail && typeof step.detail === 'object') ? step.detail : {};
    // La pérdida, el alcance y el countdown ya vienen en `detail.message`;
    // aquí solo añadimos el último eco para no repetir texto.
    if (step.kind === 'manual_walk' && detail.last_distance != null) {
      lines.push('Último eco: ' + Number(detail.last_distance).toFixed(2) + ' m');
    }
    return lines;
  }

  return {
    STATUS: STATUS,
    visualStatus: visualStatus,
    statusLabel: statusLabel,
    statusIcon: statusIcon,
    formatCountdown: formatCountdown,
    actionModel: actionModel,
    detailLines: detailLines
  };
});
