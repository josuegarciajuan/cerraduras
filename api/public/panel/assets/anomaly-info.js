/* F120 — catálogo canónico de anomalías y presentación accesible.
   El código A1..A8 deja de ser el contenido principal: se muestra el título,
   el código como dato secundario y una explicación accesible por ratón, foco
   y toque. */
(function () {
  'use strict';

  var CATALOG = {
    A1: {
      title: 'Presencia sin apertura',
      desc: 'Se detectó presencia sin una apertura previa de la puerta.',
      action: 'Revisa si alguien entró sin escanear o si el sensor de puerta falló.'
    },
    A2: {
      title: 'Presencia sin estancia',
      desc: 'Se detectó presencia en una sala sin una estancia activa.',
      action: 'Comprueba el acceso y el estado de la sala.'
    },
    A3: {
      title: 'Puerta abierta sin QR',
      desc: 'La puerta se abrió sin un QR válido ni una estancia ocupada.',
      action: 'Revisa accesos denegados y posibles aperturas manuales.'
    },
    A4: {
      title: 'Presencia sin actividad de puerta',
      desc: 'Hubo presencia prolongada sin eventos de la puerta.',
      action: 'Comprueba el sensor de puerta y quién está dentro.'
    },
    A5: {
      title: 'Salida sin apertura',
      desc: 'La estancia quedó como salida sin una apertura de puerta registrada.',
      action: 'Verifica la salida real y el estado del sensor.'
    },
    A6: {
      title: 'Presencia tras la salida',
      desc: 'Se detectó presencia después de cerrar la estancia.',
      action: 'Comprueba si alguien volvió a entrar.'
    },
    A7: {
      title: 'Puerta abierta sin presencia',
      desc: 'La puerta permaneció abierta sin presencia detectada.',
      action: 'Revisa el cierre de la puerta y el radar.'
    },
    A8: {
      title: 'Sensor inestable',
      desc: 'Un sensor emitió demasiadas transiciones en muy poco tiempo.',
      action: 'Revisa alimentación, montaje o interferencias del sensor.'
    }
  };

  var SEVERITY = {
    CRITICAL: 'Crítica',
    HIGH: 'Alta',
    MEDIUM: 'Media',
    LOW: 'Baja'
  };
  var STATE = {
    OPEN: 'Pendiente',
    ACKNOWLEDGED: 'En seguimiento',
    DISMISSED: 'Resuelta/descartada',
    RESERVED: 'Reservada',
    OCCUPIED: 'Ocupada',
    OVERSTAY: 'Excedida',
    INSIDE: 'Dentro',
    OUTSIDE: 'Fuera',
    ENTERED: 'Entrada confirmada',
    NO_SHOW: 'Sin entrada',
    NOISE: 'Descartada por ruido',
    ANONYMOUS: 'Anónima',
    DENIED: 'Denegada',
    PENDING_SYNC: 'Pendiente de envío',
    SYNCING: 'Enviando',
    SYNCED: 'Sincronizada',
    FAILED: 'Error de sincronización'
  };

  function info(code) {
    var key = String(code || '').toUpperCase();
    return CATALOG[key] || {
      title: key || 'Anomalía',
      desc: 'Tipo de anomalía no documentado.',
      action: 'Revisa el contexto del evento.'
    };
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function title(code) {
    return info(code).title;
  }

  function severityLabel(value) {
    return SEVERITY[String(value || '').toUpperCase()] || value || '—';
  }

  function stateLabel(value) {
    return STATE[String(value || '').toUpperCase()] || value || '—';
  }

  /* Título + código secundario + explicación accesible (hover/focus/tap). */
  function cell(code) {
    var key = String(code || '').toUpperCase();
    var d = info(key);
    var tip = d.desc + ' ' + d.action;
    return '<span class="anomaly-cell">'
      + '<strong class="anomaly-title">' + escapeHtml(d.title) + '</strong>'
      + '<span class="anomaly-code">' + escapeHtml(key) + '</span>'
      + '<span class="anomaly-help" tabindex="0" role="note" aria-label="'
      + escapeHtml(d.title + '. ' + tip) + '">'
      + 'i<span class="anomaly-tip" role="tooltip"><b>' + escapeHtml(d.title) + '</b><br>'
      + escapeHtml(d.desc) + '<br><em>' + escapeHtml(d.action) + '</em></span></span>'
      + '</span>';
  }

  window.AnomalyInfo = {
    catalog: CATALOG,
    info: info,
    title: title,
    severityLabel: severityLabel,
    stateLabel: stateLabel,
    cell: cell
  };
})();
