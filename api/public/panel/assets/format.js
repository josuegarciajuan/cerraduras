/* F119 — formato y utilidades de presentación del panel */
(function () {
  'use strict';

  function pad(n) { return String(n).padStart(2, '0'); }

  function toDate(value) {
    if (!value) return null;
    if (value instanceof Date) return isNaN(value.getTime()) ? null : value;
    var s = String(value).trim();
    if (!s) return null;
    // SQL UTC values arrive as "YYYY-MM-DD HH:MM:SS(.mmm)".
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(s) && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
      s = s.replace(' ', 'T') + 'Z';
    }
    var d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  }

  function fmtDate(value, fallback) {
    var d = toDate(value);
    if (!d) return fallback == null ? '—' : fallback;
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  function fmtTime(value, fallback) {
    var d = toDate(value);
    if (!d) return fallback == null ? '—' : fallback;
    return d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  }

  function fmtDateTime(value, fallback) {
    var d = toDate(value);
    if (!d) return fallback == null ? '—' : fallback;
    return d.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function fmtShort(value, fallback) {
    var d = toDate(value);
    if (!d) return fallback == null ? '—' : fallback;
    return d.toLocaleString('es-ES', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  function fmtDuration(minutes) {
    var m = Math.max(0, Math.round(Number(minutes) || 0));
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60);
    var rest = m % 60;
    if (h < 24) return rest ? (h + ' h ' + rest + ' min') : (h + ' h');
    var d = Math.floor(h / 24);
    var hr = h % 24;
    return hr ? (d + ' d ' + hr + ' h') : (d + ' d');
  }

  function fmtSeconds(seconds) {
    return fmtDuration((Number(seconds) || 0) / 60);
  }

  function fmtPct(value, digits) {
    var v = Number(value);
    if (!isFinite(v)) v = 0;
    return v.toFixed(digits == null ? 1 : digits) + '%';
  }

  function fmtNumber(value) {
    var v = Number(value) || 0;
    return v.toLocaleString('es-ES');
  }

  function fmtBytes(bytes) {
    var b = Number(bytes) || 0;
    if (b < 1024) return b + ' B';
    var units = ['KB', 'MB', 'GB', 'TB'];
    var i = -1;
    do { b /= 1024; i++; } while (b >= 1024 && i < units.length - 1);
    return b.toFixed(b >= 10 ? 0 : 1) + ' ' + units[i];
  }

  function relative(value, fallback) {
    var d = toDate(value);
    if (!d) return fallback == null ? '—' : fallback;
    var diff = Math.round((Date.now() - d.getTime()) / 1000);
    if (diff < 0) diff = 0;
    if (diff < 45) return 'ahora';
    if (diff < 90) return 'hace 1 min';
    if (diff < 3600) return 'hace ' + Math.round(diff / 60) + ' min';
    if (diff < 86400) return 'hace ' + Math.round(diff / 3600) + ' h';
    if (diff < 604800) return 'hace ' + Math.round(diff / 86400) + ' d';
    return fmtDate(value);
  }

  function ageLabel(seconds) {
    var s = Number(seconds);
    if (!isFinite(s) || s < 0) return '—';
    if (s < 60) return s + ' s';
    if (s < 3600) return Math.round(s / 60) + ' min';
    if (s < 86400) return Math.round(s / 3600) + ' h';
    return Math.round(s / 86400) + ' d';
  }

  function initials(name) {
    var parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  var STATUS = {
    FREE: ['Libre', '#16a34a', 'rgba(22,163,74,.10)'],
    OCCUPIED: ['Ocupada', '#2563eb', 'rgba(37,99,235,.10)'],
    OVERSTAY: ['Excedida', '#dc2626', 'rgba(220,38,38,.10)'],
    RESERVED: ['Reservada', '#d97706', 'rgba(217,119,6,.10)'],
    EXITED: ['Salida', '#64748b', 'rgba(100,116,139,.10)'],
    CLEANING: ['Limpieza', '#d97706', 'rgba(217,119,6,.10)'],
    OUT_OF_SERVICE: ['Fuera de servicio', '#64748b', 'rgba(100,116,139,.10)'],
    CLOSED: ['Cerrada', '#64748b', 'rgba(100,116,139,.10)'],
    CANCELED: ['Cancelada', '#64748b', 'rgba(100,116,139,.10)'],
    OPEN: ['Pendiente', '#dc2626', 'rgba(220,38,38,.10)'],
    ACKNOWLEDGED: ['Reconocida', '#d97706', 'rgba(217,119,6,.10)'],
    DISMISSED: ['Descartada', '#16a34a', 'rgba(22,163,74,.10)'],
    ACTIVE: ['Activa', '#16a34a', 'rgba(22,163,74,.10)'],
    EXPIRED: ['Caducada', '#d97706', 'rgba(217,119,6,.10)'],
    REVOKED: ['Revocada', '#dc2626', 'rgba(220,38,38,.10)'],
    AVAILABLE: ['Disponible', '#16a34a', 'rgba(22,163,74,.10)'],
    ASSIGNED: ['Asignada', '#2563eb', 'rgba(37,99,235,.10)'],
    DISABLED: ['Desactivada', '#64748b', 'rgba(100,116,139,.10)'],
    PENDING: ['Pendiente', '#d97706', 'rgba(217,119,6,.10)'],
    CLAIMED: ['Reclamado', '#16a34a', 'rgba(22,163,74,.10)'],
    INSIDE: ['Dentro', '#16a34a', 'rgba(22,163,74,.10)'],
    OUTSIDE: ['Fuera', '#64748b', 'rgba(100,116,139,.10)'],
    OK: ['Concedido', '#16a34a', 'rgba(22,163,74,.10)'],
    FAIL: ['Denegado', '#dc2626', 'rgba(220,38,38,.10)'],
    ENTERED: ['Entró', '#16a34a', 'rgba(22,163,74,.10)'],
    NO_SHOW: ['Sin entrada', '#64748b', 'rgba(100,116,139,.10)'],
    NOISE: ['Ruido', '#64748b', 'rgba(100,116,139,.10)'],
    DENIED: ['Denegado', '#dc2626', 'rgba(220,38,38,.10)'],
    ANONYMOUS: ['Anónimo', '#d97706', 'rgba(217,119,6,.10)']
  };

  function statusMeta(status) {
    return STATUS[status] || [status || '—', '#64748b', 'rgba(100,116,139,.10)'];
  }

  function badge(status, label) {
    var m = statusMeta(status);
    return '<span class="badge" style="color:' + m[1] + ';background:' + m[2] + '">' + escape(label || m[0]) + '</span>';
  }

  function escape(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  window.PanelFormat = {
    toDate: toDate,
    date: fmtDate,
    time: fmtTime,
    dateTime: fmtDateTime,
    short: fmtShort,
    duration: fmtDuration,
    seconds: fmtSeconds,
    pct: fmtPct,
    number: fmtNumber,
    bytes: fmtBytes,
    relative: relative,
    age: ageLabel,
    initials: initials,
    statusMeta: statusMeta,
    badge: badge,
    escape: escape
  };
})();
