/**
 * choreography.js — Fase 41: máquina de estados del monigote por EPISODIOS.
 *
 * Trazabilidad: RF-46, RF-47, RF-49 · design.md §5 (T1–T18) · TSK-F41-13.
 *
 * Función PURA `deriveChoreography(snapshot, episodes, now)`:
 *   - no lee `Date.now()`, ni el DOM, ni variables globales;
 *   - no muta la entrada: clona los episodios y devuelve el siguiente estado;
 *   - devuelve `{ state, logical, episodeUpdates }` donde `state` es la clave UI
 *     existente en `dashboard.html` (STATES) y `logical` el estado lógico del diseño.
 *
 * Modelo de episodios (sustituye la bandera pegajosa `_presenceDetectedDuringOpen`):
 *   entryPresenceSeen  -> presencia vista con la puerta abierta durante una ENTRADA;
 *                         se limpia al consolidar DENTRO (RF-46).
 *   entryConsolidatedAt-> instante en que el panel consolidó "dentro" localmente
 *                         (fallback cuando el backend aún no expone entry_confirmed_at).
 *   exitActive         -> hay una salida en curso (apertura posterior a la confirmación).
 *
 * UMD: global `Choreography` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.Choreography = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  // Estado lógico (design.md §5.2) → clave UI existente (dashboard.html STATES).
  var LOGICAL_TO_UI = {
    LOADING:              'LOADING',
    FREE:                 'ESPERANDO',
    ESPERANDO_HUESPED:    'ESPERANDO_HUESPED',
    QR_DISPONIBLE:        'QR_DISPONIBLE',
    QR_OK:                'QR_OK',
    ESPERANDO_APERTURA:   'QR_ESPERANDO',
    EN_UMBRAL:            'HUESPED_EN_PUERTA',
    DENTRO:               'OCUPADA',
    POSIBLE_SALIDA:       'PUERTA_ABIERTA',
    VERIFICANDO:          'VERIFICANDO_PRESENCIA',
    SALIDA_CONFIRMADA:    'HUESPED_HA_SALIDO',
    SALIDA_DETECTADA:     'SALIDA_DETECTADA',
    QR_RECHAZADO:         'QR_RECHAZADO',
    ANOMALIA_PUERTA:      'ANOMALIA_RP',
    ANOMALIA_PRESENCIA:   'ANOMALIA_PA',
    EXCESO_TIEMPO:        'EXCESO_TIEMPO',
    LIMPIEZA:             'LIMPIEZA',
    FUERA_SERVICIO:       'FUERA_SERVICIO',
    SIN_CONFIGURAR:       'SIN_CONFIGURAR',
    SENSORES_DESCONECTADOS: 'SENSORES_DESCONECTADOS',
    ANTI_REENTRADA:       'ANTI_REENTRADA'
  };

  /** Episodios vacíos, listos para un nuevo `stayId` (o ninguno). */
  function emptyEpisodes(stayId) {
    return {
      stayId:              (stayId === undefined ? null : stayId),
      // Entrada en curso (QR_OK → EN_UMBRAL → consolidación)
      entryActive:         false,
      entryQrAt:           0,
      entryDoorOpenedAt:   0,
      entryPresenceSeen:   false,
      entryConsolidatedAt: 0,
      // Salida en curso (POSIBLE_SALIDA → VERIFICANDO → SALIDA_CONFIRMADA)
      exitActive:          false,
      exitDoorOpenedAt:    0,
      exitClosedAt:        0,
      exitPresenceSeen:    false,
      exitAbsentAt:        0,
      exitDeadline:        0
    };
  }

  function normalizeEpisodes(ep) {
    var base = emptyEpisodes(null);
    if (!ep || typeof ep !== 'object') return base;
    for (var k in base) {
      if (Object.prototype.hasOwnProperty.call(base, k)) base[k] = ep[k];
    }
    return base;
  }

  /**
   * Parseo tolerante (ISO/Z/naive UTC/epoch ms) → ms. 0 si no es válido.
   * El backend emite algunos timestamps UTC sin sufijo de zona; se normaliza a Z.
   */
  function parseTime(v) {
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return isFinite(v) && v > 0 ? v : 0;
    var s = String(v);
    if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(s)) s += 'Z';
    var t = Date.parse(s);
    return isNaN(t) ? 0 : t;
  }

  /**
   * Máquina de estados pura.
   *
   * snapshot: {
   *   roomStatus, door, presence, stayId, stayStatus,
   *   entryConfirmedAt, lastOpenAt, lastCloseAt, lastAbsentSince,
   *   exitDeadline, gapSeconds, cooldown,
   *   qrScannable, qrConsumed, qrRevoked, qrExpired,
   *   qrRecentAt, qrRecentResult,  // QR_VALIDATE en ventana de 10 s
   *   qrBroadAt,                   // QR_VALIDATE OK en ventana de 120 s
   *   qrDeniedAt,                  // DENIED en ventana de 10 s
   *   sensorsEverSeen, anomalies, anyDeviceReachable,
   *   prevDoor, prevPresence, prevStayStatus, freshPresenceAfterClose
   * }
   * episodes: objeto devuelto en la llamada anterior (o `emptyEpisodes()`).
   * now: epoch en ms.
   */
  function deriveChoreography(snapshot, episodes, now) {
    var s = snapshot || {};
    var nowMs = (typeof now === 'number' && isFinite(now)) ? now : parseTime(now);
    var ep = normalizeEpisodes(episodes);

    var door = s.door || 'UNKNOWN';
    var presence = s.presence || 'UNKNOWN';
    var roomStatus = s.roomStatus || 'FREE';
    var stayId = (s.stayId === undefined) ? null : s.stayId;
    var stayStatus = s.stayStatus || null;
    var prevStayStatus = s.prevStayStatus || null;
    var prevDoor = s.prevDoor || null;

    var entryConfirmedMs = parseTime(s.entryConfirmedAt);
    var lastOpenMs = parseTime(s.lastOpenAt);
    var lastCloseMs = parseTime(s.lastCloseAt);
    var lastAbsentMs = parseTime(s.lastAbsentSince);
    var deadlineMs = parseTime(s.exitDeadline);
    var gapSecs = (typeof s.gapSeconds === 'number' && s.gapSeconds > 0) ? s.gapSeconds : 15;
    var holdMs = (gapSecs + 10) * 1000;

    var qrRecentMs = s.qrRecentAt || 0;
    var qrRecentResult = s.qrRecentResult || null;
    var qrBroadMs = s.qrBroadAt || 0;
    var qrDeniedMs = s.qrDeniedAt || 0;
    var freshPresenceAfterClose = !!s.freshPresenceAfterClose;

    // Un cambio de estancia reinicia el episodio (RF-46 / RF-47.5).
    if (stayId !== ep.stayId) {
      ep = emptyEpisodes(stayId);
    }

    // Autoridad del backend + consolidación local como respaldo.
    if (entryConfirmedMs && !ep.entryConsolidatedAt) {
      ep.entryConsolidatedAt = entryConfirmedMs;
    }
    var insideSince = entryConfirmedMs || ep.entryConsolidatedAt || 0;
    var hasBeenInside = insideSince > 0;

    function out(logical) {
      return {
        state: LOGICAL_TO_UI[logical] || logical,
        logical: logical,
        episodeUpdates: ep
      };
    }

    function startEntry(qrAt) {
      ep.entryActive = true;
      ep.entryQrAt = qrAt || nowMs;
      ep.entryDoorOpenedAt = 0;
      ep.entryPresenceSeen = false;
      ep.entryConsolidatedAt = 0;
    }

    function consolidateEntry(atMs) {
      ep.entryConsolidatedAt = entryConfirmedMs || atMs || nowMs;
      ep.entryPresenceSeen = false; // RF-46: la bandera muere al consolidar DENTRO
      ep.entryActive = false;
      ep.entryQrAt = 0;
      ep.entryDoorOpenedAt = 0;
    }

    function clearExit() {
      ep.exitActive = false;
      ep.exitDoorOpenedAt = 0;
      ep.exitClosedAt = 0;
      ep.exitPresenceSeen = false;
      ep.exitAbsentAt = 0;
      ep.exitDeadline = 0;
    }

    // ── T18: las anomalías A1–A8 son informativas, no alteran la coreografía ──
    var anomalies = s.anomalies || [];
    if (anomalies.length) {
      var hasA3 = false, hasA7 = false, hasPresenceAnomaly = false;
      for (var i = 0; i < anomalies.length; i++) {
        var t = anomalies[i] && anomalies[i].anomaly_type;
        if (t === 'A3') hasA3 = true;
        else if (t === 'A7') hasA7 = true;
        else if (t === 'A1' || t === 'A2' || t === 'A6') hasPresenceAnomaly = true;
      }
      if (hasA3 && door === 'OPEN') return out('ANOMALIA_PUERTA');
      if (hasA7 && door === 'OPEN' && presence === 'ABSENT') return out('ANOMALIA_PUERTA');
      if (hasPresenceAnomaly && presence === 'PRESENT' && !qrBroadMs) return out('ANOMALIA_PRESENCIA');
    }

    if (qrDeniedMs) return out('QR_RECHAZADO');

    if (roomStatus === 'OVERSTAY') return out('EXCESO_TIEMPO');
    if (roomStatus === 'CLEANING') return out('LIMPIEZA');
    if (roomStatus === 'OUT_OF_SERVICE') return out('FUERA_SERVICIO');

    // ── T15/T17: el dominio ya cerró la estancia ──
    if (stayStatus === 'EXITED' && prevStayStatus === 'OCCUPIED') {
      ep = emptyEpisodes(stayId);
      return out('SALIDA_CONFIRMADA');
    }
    if (roomStatus === 'FREE' && prevStayStatus === 'OCCUPIED') {
      clearExit();
      ep = emptyEpisodes(stayId);
      return out('SALIDA_DETECTADA');
    }

    // ── Sensores aún sin datos ──
    if (!s.sensorsEverSeen && (door === 'UNKNOWN' || presence === 'UNKNOWN')) return out('LOADING');
    if (door === 'UNKNOWN' && presence === 'UNKNOWN' && roomStatus === 'FREE') {
      if (!s.anyDeviceReachable) return out('SIN_CONFIGURAR');
    }
    if (door === 'UNKNOWN' || presence === 'UNKNOWN') {
      if (roomStatus === 'OCCUPIED' || roomStatus === 'RESERVED') {
        var sensorsEntryInProgress = (door === 'CLOSED' && (prevDoor === 'OPEN' || qrBroadMs));
        if (!sensorsEntryInProgress && !s.anyDeviceReachable) return out('SENSORES_DESCONECTADOS');
      }
    }

    if (s.cooldown && (roomStatus === 'OCCUPIED' || roomStatus === 'RESERVED')) return out('ANTI_REENTRADA');

    // ── Grupo A: sin estancia ──
    if (!stayStatus) {
      if (roomStatus === 'FREE' && door === 'OPEN') return out('ANOMALIA_PUERTA');
      return out('FREE');
    }

    // ── Grupo B: RESERVED ──
    if (stayStatus === 'RESERVED') {
      if (door === 'OPEN') return out('ANOMALIA_PUERTA');
      if (qrRecentMs && qrRecentResult === 'OK') {
        startEntry(qrRecentMs);
        return out('QR_OK');
      }
      if (s.qrScannable && !s.qrConsumed && !s.qrRevoked && !s.qrExpired) return out('QR_DISPONIBLE');
      return out('ESPERANDO_HUESPED');
    }

    // ── Grupo C: OCCUPIED ──
    if (stayStatus === 'OCCUPIED') {
      // T3: QR recién validado, aún no confirmado dentro y sin apertura previa.
      // `!hasBeenInside` evita que un huésped ya dentro vuelva a QR_OK (RC-3).
      if (qrRecentMs && qrRecentResult === 'OK' && !hasBeenInside && door !== 'OPEN' && prevDoor !== 'OPEN') {
        startEntry(qrRecentMs);
        return out('QR_OK');
      }

      if (!hasBeenInside) {
        // ── ENTRADA EN CURSO: nunca se salta de acceso concedido a interior ──

        // T4/T6/T7: puerta abierta → umbral (CROSSING). No es salida.
        if (door === 'OPEN') {
          ep.entryActive = true;
          if (!ep.entryDoorOpenedAt) ep.entryDoorOpenedAt = lastOpenMs || nowMs;
          if (presence === 'PRESENT') ep.entryPresenceSeen = true;
          return out('EN_UMBRAL');
        }

        // T5: QR validado pero aún no llega el OPEN → se espera en el lector.
        if (door === 'UNKNOWN') {
          return out('ESPERANDO_APERTURA');
        }

        // door === CLOSED
        var openingEvidenced = ep.entryDoorOpenedAt > 0 || prevDoor === 'OPEN' || entryConfirmedMs > 0;
        if (entryConfirmedMs || (openingEvidenced && (ep.entryPresenceSeen || presence === 'PRESENT'))) {
          // T8: consolidar entrada al cerrar con presencia confirmada o vista al abrir.
          consolidateEntry(lastCloseMs);
          return out('DENTRO');
        }
        if (ep.entryActive || qrBroadMs) return out('ESPERANDO_APERTURA');
        // Sin evento de apertura (recarga del panel): reconciliar con el dominio real.
        if (presence === 'PRESENT') {
          consolidateEntry(lastCloseMs);
          return out('DENTRO');
        }
        return out('VERIFICANDO');
      }

      // ── HUÉSPED YA DENTRO (entry_confirmed_at / consolidación local) ──

      if (door === 'OPEN') {
        // T9/T12: nueva apertura posterior a la confirmación → posible salida.
        if (lastOpenMs > insideSince) {
          ep.exitActive = true;
          ep.exitDoorOpenedAt = lastOpenMs;
          ep.exitClosedAt = 0;
          ep.exitPresenceSeen = presence === 'PRESENT';
          ep.exitAbsentAt = 0;
          ep.exitDeadline = deadlineMs;
          return out('POSIBLE_SALIDA');
        }
        // Apertura sin ciclo nuevo: el huésped sigue dentro.
        return out('DENTRO');
      }

      if (door === 'CLOSED') {
        if (ep.exitActive) {
          ep.exitClosedAt = lastCloseMs || nowMs;
          // T11 hold: puerta recién cerrada y radar aún PRESENT sin evento fresco.
          var holding = presence === 'PRESENT' && !freshPresenceAfterClose
            && ((nowMs - (lastCloseMs || nowMs)) < holdMs);
          if (presence === 'PRESENT' && !holding) {
            // T13: la presencia vuelve a asentarse dentro → se cancela la salida.
            clearExit();
            return out('DENTRO');
          }
          if (presence === 'ABSENT' || presence === 'UNKNOWN' || holding) {
            if (presence === 'ABSENT') ep.exitAbsentAt = lastAbsentMs || nowMs;
            ep.exitDeadline = deadlineMs;
            return out('VERIFICANDO');
          }
        }
        if (deadlineMs && presence === 'ABSENT') {
          // T14: verificación activa, el conteo local corre en el panel.
          ep.exitDeadline = deadlineMs;
          return out('VERIFICANDO');
        }
        // T10: ausencia sin apertura acreditada no cierra la estancia.
        return out('DENTRO');
      }

      // Puerta sin estado conocido: el huésped sigue dentro.
      return out('DENTRO');
    }

    return out('FREE');
  }

  return {
    deriveChoreography: deriveChoreography,
    parseTime: parseTime,
    emptyEpisodes: emptyEpisodes,
    LOGICAL_TO_UI: LOGICAL_TO_UI
  };
});
