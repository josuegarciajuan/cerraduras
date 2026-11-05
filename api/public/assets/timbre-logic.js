/**
 * timbre-logic.js — F115: lógica PURA del video portero del almacén.
 *
 * Trazabilidad: RF-286…RF-292 · design.md §F115 · tasks.md TSK-F115.
 *
 * No toca DOM, red ni Tuya. Reutiliza la semántica de F56/F72/F77.5/F87:
 *   - presencia verde SOLO con PRESENT fresco (nunca por `occupied`);
 *   - puerta fiel: un OPEN stale no se pinta como abierta;
 *   - pulso anti-colapso de 1,2 s desde `last_open_at`.
 *
 * UMD: global `TimbreLogic` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.TimbreLogic = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  var ROOM_TYPE_CODE = 'TIMBRADO';
  var DEFAULT_ROOM_CODE = 'oficina';
  var DOOR_PULSE_MS = 1200;
  var OPEN_COOLDOWN_MS = 3000;

  /** Parseo tolerante (ISO/Z/naive UTC/epoch ms) → ms. 0 si no es válido. */
  function parseTime(v) {
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return isFinite(v) && v > 0 ? v : 0;
    var s = String(v);
    if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(s)) s += 'Z';
    var t = Date.parse(s.replace(' ', 'T'));
    return isNaN(t) ? 0 : t;
  }

  /** Almacenes del listado /dashboard-api/rooms. */
  function filterWarehouses(rooms) {
    return (rooms || []).filter(function (r) {
      return r && r.room_type_code === ROOM_TYPE_CODE;
    });
  }

  /**
   * Sala inicial: `?room=` válido > `oficina` > primera.
   * @param {Array} rooms listado ya filtrado
   * @param {number} requestedId
   * @param {string} preferredCode
   */
  function pickDefaultRoom(rooms, requestedId, preferredCode) {
    var list = rooms || [];
    var wanted = parseInt(requestedId, 10) || 0;
    var i;
    if (wanted > 0) {
      for (i = 0; i < list.length; i++) {
        if (parseInt(list[i].id, 10) === wanted) return list[i];
      }
    }
    var code = String(preferredCode || DEFAULT_ROOM_CODE).toLowerCase();
    for (i = 0; i < list.length; i++) {
      if (String(list[i].code || '').toLowerCase() === code) return list[i];
    }
    return list.length ? list[0] : null;
  }

  /**
   * La vista grande es SIEMPRE la cámara INTERIOR de la oficina.
   * @returns {object|null}
   */
  function pickInteriorCamera(cameras) {
    var list = cameras || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i] && String(list[i].position).toUpperCase() === 'INTERIOR') return list[i];
    }
    return null;
  }

  /**
   * Firma estrecha para NO recrear el <img> MJPEG con cada push SSE.
   * Ignora `recording`, `motion_active`, edades y cualquier campo volátil.
   */
  function cameraSignature(cam) {
    if (!cam) return '';
    return [
      parseInt(cam.id, 10) || 0,
      cam.enabled === false ? 0 : 1,
      cam.mjpeg_url || '',
    ].join('|');
  }

  /**
   * Estado de presencia para el marco.
   * Verde SOLO con PRESENT fresco. Stale → ámbar. Candidato → ámbar.
   * @returns {{state:string, green:boolean, tone:string, label:string}}
   */
  function derivePresence(live) {
    var l = live || {};
    var presence = String(l.presence_state || 'UNKNOWN').toUpperCase();
    var stale = l.presence_stale === true;
    var decision = l.presence_decision && l.presence_decision.state
      ? String(l.presence_decision.state).toUpperCase()
      : '';

    if (stale && presence === 'PRESENT') {
      return { state: 'stale', green: false, tone: 'warn', label: 'SIN DATOS · RADAR' };
    }
    if (presence === 'PRESENT') {
      return { state: 'present', green: true, tone: 'ok', label: 'PRESENCIA' };
    }
    if (decision === 'CANDIDATE_PRESENT') {
      return { state: 'candidate', green: false, tone: 'warn', label: 'DETECTANDO…' };
    }
    if (presence === 'ABSENT') {
      return { state: 'absent', green: false, tone: 'dim', label: 'EN ESPERA' };
    }
    return { state: 'unknown', green: false, tone: 'dim', label: 'SIN DATOS' };
  }

  /**
   * Estado visual de la puerta (F56/F72/F77.5).
   * - OPEN stale → SIN DATOS (no se afirma abierta).
   * - CLOSED stale sigue siendo válido (sensor edge-triggered).
   * @returns {{state:string, open:boolean, stale:boolean, tone:string, label:string}}
   */
  function deriveDoor(live, now) {
    var l = live || {};
    var door = String(l.door_state || 'UNKNOWN').toUpperCase();
    var stale = l.door_stale === true;
    var nowMs = (typeof now === 'number' && isFinite(now)) ? now : Date.now();
    var pulseUntil = parseTime(l.last_open_at) + DOOR_PULSE_MS;

    var staleOpen = stale && door === 'OPEN';
    var open = !staleOpen && (door === 'OPEN' || nowMs < pulseUntil);

    if (staleOpen) {
      return { state: 'unknown', open: false, stale: true, tone: 'warn', label: 'SIN DATOS' };
    }
    if (open) {
      return { state: 'open', open: true, stale: false, tone: 'ok', label: 'ABIERTA' };
    }
    if (door === 'CLOSED') {
      return { state: 'closed', open: false, stale: stale, tone: 'dim', label: 'CERRADA' };
    }
    return { state: 'unknown', open: false, stale: stale, tone: 'dim', label: 'SIN DATOS' };
  }

  /** ¿Se puede pedir otra apertura? Cooldown cliente y sin petición en vuelo. */
  function canOpen(now, lastActionAt, inflight, cooldownMs) {
    if (inflight) return false;
    var cd = typeof cooldownMs === 'number' ? cooldownMs : OPEN_COOLDOWN_MS;
    var last = Number(lastActionAt) || 0;
    return !(last > 0 && (Number(now) || 0) - last < cd);
  }

  /**
   * Interpreta la respuesta de /almacen-api/door/open.
   * HTTP 200 no basta: `result` debe ser `ok`.
   */
  function doorOutcome(status, payload) {
    var body = payload && typeof payload === 'object' ? payload : {};
    var code = parseInt(status, 10) || 0;
    if (code < 200 || code >= 300) {
      var msg = body.message || body.error || ('Error HTTP ' + code);
      return { ok: false, message: String(msg), status: code };
    }
    if (body.result && String(body.result) !== 'ok') {
      return {
        ok: false,
        message: String(body.message || 'La cerradura no confirmó la apertura'),
        status: code,
      };
    }
    return { ok: true, message: '', status: code, visitStarted: body.visit_started === true };
  }

  /** El endpoint cae a la primera sala de almacén si el id no es válido. */
  function roomMismatch(requestedId, snapshot) {
    var wanted = parseInt(requestedId, 10) || 0;
    var room = snapshot && snapshot.room ? snapshot.room : null;
    if (!room) return true;
    return wanted > 0 && parseInt(room.id, 10) !== wanted;
  }

  function formatAge(seconds) {
    if (typeof seconds !== 'number' || !isFinite(seconds) || seconds < 0) return '';
    if (seconds < 60) return 'hace ' + Math.floor(seconds) + ' s';
    if (seconds < 3600) return 'hace ' + Math.floor(seconds / 60) + ' min';
    if (seconds < 86400) return 'hace ' + Math.floor(seconds / 3600) + ' h';
    return 'hace ' + Math.floor(seconds / 86400) + ' d';
  }

  var TYPE_LABELS = { sinllave: 'Sin llave', qr: 'QR', ficha: 'Ficha' };
  var DIRECTION_LABELS = { entry: 'Entrada', exit: 'Salida', crossing: 'Cruce', unknown: 'Desconocido' };
  var STATUS_LABELS = {
    recording: 'Grabando',
    completed: 'Completada',
    no_door: 'Sin apertura',
    no_presence: 'Sin presencia',
    left_open: 'Puerta abierta',
    max_reached: 'Tope alcanzado',
    cancelled: 'Cancelada'
  };

  function openingTypeLabel(type) {
    return TYPE_LABELS[String(type || '').toLowerCase()] || 'Sin llave';
  }

  function directionLabel(direction) {
    return DIRECTION_LABELS[String(direction || '').toLowerCase()] || 'Desconocido';
  }

  function openingStatusLabel(status) {
    return STATUS_LABELS[String(status || '').toLowerCase()] || '—';
  }

  function openingStatusTone(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'completed') return 'ok';
    if (s === 'recording') return 'warn';
    if (s === 'left_open' || s === 'no_door' || s === 'no_presence' || s === 'max_reached') return 'warn';
    if (s === 'cancelled') return 'bad';
    return '';
  }

  function openingTime(ts) {
    var ms = parseTime(ts);
    if (!ms) return '—';
    try {
      return new Date(ms).toLocaleString('es-ES', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
      });
    } catch (e) {
      return '—';
    }
  }

  function firstVideo(recordings) {
    var list = recordings || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i] && list[i].video_url) return list[i];
    }
    return null;
  }

  function hasRecording(recordings) {
    var list = recordings || [];
    for (var i = 0; i < list.length; i++) {
      var rec = list[i];
      if (!rec) continue;
      if (rec.status === 'RECORDING') return true;
      if (rec.status === 'PENDING' && rec.stop_requested !== true) return true;
    }
    return false;
  }

  function qrDownloadName(label) {
    var base = String(label || 'llave-qr').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return (base || 'llave-qr') + '.png';
  }

  /**
   * Construye y valida el payload de creación de llave QR.
   * @returns {{ok:boolean, error?:string, payload?:object}}
   */
  function buildKeyPayload(form) {
    var f = form || {};
    var ownerType = String(f.ownerType || '').toUpperCase();
    var validUntil = String(f.validUntilLocal || '').trim();
    if (!validUntil) {
      return { ok: false, error: 'Elige una fecha de caducidad.' };
    }
    var date = new Date(validUntil);
    if (isNaN(date.getTime()) || date.getTime() <= Date.now()) {
      return { ok: false, error: 'La caducidad debe ser futura.' };
    }
    var payload = {
      room_id: Number(f.roomId) || 0,
      owner_type: ownerType,
      valid_until: date.toISOString(),
      label: String(f.label || '').trim()
    };
    if (ownerType === 'WORKER') {
      if (!Number(f.workerId)) return { ok: false, error: 'Elige un empleado.' };
      payload.worker_id = Number(f.workerId);
    } else if (ownerType === 'ROLE') {
      if (!Number(f.roleId)) return { ok: false, error: 'Elige un rol.' };
      payload.role_id = Number(f.roleId);
    } else if (ownerType === 'FREE') {
      var ownerName = String(f.ownerName || '').trim();
      if (!ownerName) return { ok: false, error: 'Escribe el nombre del titular.' };
      payload.owner_name = ownerName;
      if (!payload.label) payload.label = ownerName;
    } else {
      return { ok: false, error: 'Elige el tipo de titular.' };
    }
    if (!payload.room_id) return { ok: false, error: 'No hay una oficina seleccionada.' };
    return { ok: true, payload: payload };
  }

  return {
    ROOM_TYPE_CODE: ROOM_TYPE_CODE,
    WAREHOUSE_TYPE_CODE: ROOM_TYPE_CODE,
    DEFAULT_ROOM_CODE: DEFAULT_ROOM_CODE,
    DOOR_PULSE_MS: DOOR_PULSE_MS,
    OPEN_COOLDOWN_MS: OPEN_COOLDOWN_MS,
    parseTime: parseTime,
    filterWarehouses: filterWarehouses,
    pickDefaultRoom: pickDefaultRoom,
    pickInteriorCamera: pickInteriorCamera,
    cameraSignature: cameraSignature,
    derivePresence: derivePresence,
    deriveDoor: deriveDoor,
    canOpen: canOpen,
    doorOutcome: doorOutcome,
    roomMismatch: roomMismatch,
    formatAge: formatAge,
    openingTypeLabel: openingTypeLabel,
    directionLabel: directionLabel,
    openingStatusLabel: openingStatusLabel,
    openingStatusTone: openingStatusTone,
    openingTime: openingTime,
    firstVideo: firstVideo,
    hasRecording: hasRecording,
    qrDownloadName: qrDownloadName,
    buildKeyPayload: buildKeyPayload,
  };
});
