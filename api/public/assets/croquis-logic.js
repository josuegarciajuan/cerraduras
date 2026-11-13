/**
 * croquis-logic.js — F67 (RF-77): lógica PURA del croquis en vivo del almacén.
 *
 * Trazabilidad: RF-77.1/77.2/77.5 · F83/RF-106 (fidelidad al radar) ·
 * design.md §31.4 · tasks.md TSK-F67-03.
 *
 * Función PURA `deriveCroquis(snapshot, now)`:
 *   - no lee el DOM ni globals mutables;
 *   - no llama a la red ni a Tuya;
 *   - reutiliza `Choreography.resolveDoorOpen` (F56) para decidir si la puerta
 *     está abierta, con un pulso anti-colapso derivado de `last_open_at`.
 *
 * `snapshot` esperado (bloque `live` de `/almacen-api/state`):
 *   { occupied, live: { door_state, presence_state, switch_state,
 *                       last_open_at, last_close_at, last_absent_since } }
 *
 * UMD: global `CroquisLogic` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./choreography.js'));
  } else {
    root.CroquisLogic = factory(root.Choreography);
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function (Choreography) {
  'use strict';

  /** Duración del pulso anti-colapso tras un OPEN real (ms). Igual que el panel. */
  var DOOR_PULSE_MS = 1200;

  /** F80/RF-103.4: ventana (ms) para considerar reciente un PRESENT/ABSENT. */
  var LIVE_PRESENCE_WINDOW_MS = 10000;
  /** F80/RF-103.4: ventana (ms) tras un OPEN para la fase "cerca". */
  var DOOR_NEAR_WINDOW_MS = 6000;

  /**
   * F118: ventana visual (ms) tras un acceso con ficha/NFC. Sirve para que el
   * monigote del dashboard reaccione al escaneo aunque aún no haya llegado el
   * evento OPEN del sensor de puerta. Pasada la ventana manda la presencia.
   */
  var KEY_ACCESS_WINDOW_MS = 15000;

  /**
   * F118: tiempo mínimo en el umbral desde que la puerta se abre antes de poder
   * pasar a INSIDE. Evita el salto al centro "nada más abrir" cuando el radar ya
   * tenía presencia (p. ej. detección a través de la puerta).
   */
  var CROSSING_HOLD_MS = 1800;

  /**
   * F118: ausencia sostenida necesaria para afirmar OUTSIDE. Evita saltos por
   * pérdidas puntuales del radar mientras el huésped está dentro o en la puerta.
   */
  var ABSENCE_HOLD_MS = 3000;

  /**
   * F118.2: en un ciclo de salida, si la puerta se cierra y el radar no emite
   * ningún reporte de presencia POSTERIOR al cierre en este tiempo, el monigote
   * pasa a OUTSIDE aunque el radar siga diciendo PRESENT (objetivo del pasillo
   * dentro de su alcance). Si luego llega un PRESENT posterior, vuelve a INSIDE.
   */
  var EXIT_VISUAL_HOLD_MS = 3000;

  /**
   * F118.3: máximo visual de verificación de salida desde el cierre. Al
   * agotarse, el monigote pasa a OUTSIDE aunque el radar siga en PRESENT.
   */
  var EXIT_MAX_VISUAL_MS = 15000;

  /**
   * F118.4: tras un CLOSED, el monigote se queda en el umbral este tiempo antes
   * de decidir dentro/fuera. Da margen al radar lento y usa la hora del servidor.
   */
  var CLOSE_DECISION_MS = 2300;

  /**
   * F118: estados cuyo monigote NO debe seguir a los sensores (se mantiene la
   * posición semántica del estado: QR junto al lector, limpieza, avería...).
   * La presencia dentro (`phase==='inside'`) NO los sobreescribe. Así un
   * PRESENT previo a la entrada no manda al centro en QR_DISPONIBLE/QR_OK.
   */
  var DASHBOARD_STATE_ONLY = {
    SIN_CONFIGURAR: 1, SENSORES_DESCONECTADOS: 1, LIMPIEZA: 1,
    QR_DISPONIBLE: 1, ESPERANDO_HUESPED: 1, QR_OK: 1,
    QR_ESPERANDO: 1, QR_RECHAZADO: 1
  };

  /** F118: estados donde nunca se debe mover el monigote por presencia. */
  var DASHBOARD_NEVER_SENSOR = { LOADING: 1, SIN_CONFIGURAR: 1, FUERA_SERVICIO: 1 };

  /** Parseo tolerante (ISO/Z/naive UTC/epoch ms) → ms. 0 si no es válido. */
  function parseTime(v) {
    if (Choreography && typeof Choreography.parseTime === 'function') {
      return Choreography.parseTime(v);
    }
    if (v === null || v === undefined || v === '') return 0;
    if (typeof v === 'number') return isFinite(v) && v > 0 ? v : 0;
    var s = String(v);
    if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(s)) s += 'Z';
    var t = Date.parse(s);
    return isNaN(t) ? 0 : t;
  }

  /** Resolución de puerta: regla F56 compartida, con fallback local equivalente. */
  function resolveDoor(door, pulseUntilMs, nowMs) {
    if (Choreography && typeof Choreography.resolveDoorOpen === 'function') {
      return Choreography.resolveDoorOpen(door, pulseUntilMs, nowMs);
    }
    var pulse = (typeof pulseUntilMs === 'number' && pulseUntilMs > 0)
      && (typeof nowMs === 'number' && nowMs < pulseUntilMs);
    return door === 'OPEN' || pulse;
  }

  function chip(text, mod) {
    return { text: text, mod: mod || '' };
  }

  /** F77.5: antigüedad legible corta ("hace 5 min", "hace 3 h", "hace 2 d"). */
  function formatAge(seconds) {
    if (typeof seconds !== 'number' || !isFinite(seconds) || seconds < 0) return '';
    if (seconds < 60) return 'hace ' + Math.floor(seconds) + ' s';
    if (seconds < 3600) return 'hace ' + Math.floor(seconds / 60) + ' min';
    if (seconds < 86400) return 'hace ' + Math.floor(seconds / 3600) + ' h';
    return 'hace ' + Math.floor(seconds / 86400) + ' d';
  }

  /**
   * F80/RF-103.4: instante (ms) del último evento de presencia aplicado con un
   * `value` dado ('PRESENT'|'ABSENT'); 0 si no hay. `recent_presence` viene del
   * bloque `live` de `/almacen-api/state` (aditivo, solo eventos aplicados).
   */
  function lastPresenceEventMs(recent, value) {
    var best = 0;
    if (!recent || !recent.length) return best;
    for (var i = 0; i < recent.length; i++) {
      var e = recent[i] || {};
      if (String(e.sensor) !== 'PRESENCE') continue;
      if (value && String(e.value) !== value) continue;
      var t = parseTime(e.occurred_at);
      if (t > best) best = t;
    }
    return best;
  }

  /**
   * @param {object} snapshot
   * @param {number} now epoch ms
   * @returns {{door:string,presence:string,light:string,occupied:boolean,
   *            personInside:boolean,doorOpen:boolean,unknown:boolean,
   *            recentPresent:boolean,phase:string,classes:object,chips:object,
   *            desc:string}}
   *
   * F83/RF-106:
   *   - `personInside` = presencia conocida ? (PRESENT) : `occupied` (fallback).
   *     `ABSENT` siempre manda; `UNKNOWN` nunca afirma presencia.
   *   - `phase` no se recoloca a 'inside' por un PRESENT anterior.
   *   - el chip de presencia es honesto (sin forzar 'PRESENTE' por `occupied`).
   */
  function deriveCroquis(snapshot, now) {
    var s = snapshot || {};
    var live = s.live || {};
    var occupied = !!s.occupied;

    var door = live.door_state || 'UNKNOWN';
    var presence = live.presence_state || 'UNKNOWN';
    var light = live.switch_state || 'UNKNOWN';

    var nowMs = (typeof now === 'number' && isFinite(now)) ? now : Date.now();
    var lastOpenMs = parseTime(live.last_open_at);
    var lastCloseMs = parseTime(live.last_close_at);
    var pulseUntil = lastOpenMs + DOOR_PULSE_MS;

    // F77.5: si el servidor marca el estado de puerta como viejo (sensor mudo o
    // valor cacheado), un OPEN no se pinta como "abierta": no es fiable. El
    // CLOSED sigue siendo válido (el MC400D es edge-triggered y puede no emitir
    // durante horas con la puerta cerrada).
    var doorStale = live.door_stale === true;
    var doorAge = (typeof live.door_age_seconds === 'number') ? live.door_age_seconds : null;
    var staleOpen = doorStale && door === 'OPEN';
    var doorOpen = !staleOpen && resolveDoor(door, pulseUntil, nowMs);

    // F87/RF-116.2: si el radar lleva demasiado sin reportar (presence_stale),
    // un PRESENT viejo NO acredita presencia. La visita del motor no se cierra,
    // pero el panel no debe afirmar "dentro".
    var presenceStale = live.presence_stale === true;
    var presenceDecision = live.presence_decision || null;
    var presenceDecisionState = presenceDecision && presenceDecision.state
      ? String(presenceDecision.state) : '';

    // F83/RF-106.1/106.2: el radar manda. ABSENT gana sobre la visita enlazada
    // y sobre cualquier PRESENT anterior; UNKNOWN (el radar no ha reportado) no
    // afirma presencia, solo cae al fallback de visita activa sin salida.
    var presenceKnown = (presence === 'PRESENT' || presence === 'ABSENT');
    var personInside = presenceStale
      ? false
      : (presenceKnown ? (presence === 'PRESENT') : occupied);
    var unknown = door === 'UNKNOWN' && presence === 'UNKNOWN';

    // F80/RF-103.4: fase en vivo del monigote (acercamiento → dentro → fuera).
    // F81/RF-104.5: ya no hay "crossing" en vivo; el cruce es solo de la
    // reproducción de visitas (F68).
    // F83/RF-106.2: la fase NO se recoloca a 'inside' por un PRESENT anterior;
    // manda `personInside` (presencia conocida o fallback de visita activa) y,
    // en su defecto, la puerta. `recentPresent` queda solo como diagnóstico.
    var recentPresentMs = lastPresenceEventMs(live.recent_presence, 'PRESENT');
    var recentOpen = lastOpenMs > 0 && (nowMs - lastOpenMs) <= DOOR_NEAR_WINDOW_MS;
    var recentPresent = recentPresentMs > 0 && (nowMs - recentPresentMs) <= LIVE_PRESENCE_WINDOW_MS;
    var presenceCandidate = presenceDecisionState === 'CANDIDATE_PRESENT';
    // F114/RF-282: la ausencia confirmada (o una presencia sin datos) manda
    // sobre la puerta abierta. Antes, con `door_state=OPEN` la fase quedaba
    // siempre en `near` y el monigote no salía nunca al dejar la puerta abierta.
    // `near` se conserva solo para una aproximación real: puerta recién abierta
    // o presencia candidata/pendiente de confirmar.
    var absenceSettled = presenceStale
      || presenceDecisionState === 'ABSENT_CONFIRMED'
      || (presence === 'ABSENT' && !recentOpen && !presenceCandidate);
    var phase;
    if (personInside) {
      phase = 'inside';
    } else if (absenceSettled) {
      phase = 'outside';
    } else if (doorOpen || recentOpen) {
      phase = 'near';
    } else {
      phase = 'outside';
    }

    // F72 (RF-83) + F77.5: el último estado persiste hasta el evento contrario,
    // salvo que el servidor lo marque como STALE (entonces "SIN DATOS").
    var doorChip = staleOpen
      ? chip('PUERTA SIN DATOS' + (formatAge(doorAge) ? ' · ' + formatAge(doorAge) : ''), 'warn')
      : doorOpen ? chip('PUERTA ABIERTA', 'ok')
      : door === 'CLOSED' ? chip('PUERTA CERRADA', 'dim')
      : chip('PUERTA SIN DATOS', 'dim');

    // F83/RF-106.2: el chip es honesto con el radar; `occupied` no fuerza
    // 'PRESENTE'. UNKNOWN nunca afirma presencia (chip "SIN DATOS").
    var presenceChip;
    if (presenceStale) {
      // F87/RF-116.2: el último PRESENT es viejo; no es prueba de nadie dentro.
      presenceChip = chip('PRESENCIA SIN DATOS', 'warn');
    } else if (presenceDecisionState === 'CANDIDATE_PRESENT') {
      // F91/RF-149.3: radar con evidencia insuficiente. No es ocupación.
      presenceChip = chip('PRESENCIA NO CONFIRMADA', 'warn');
    } else if (presenceDecisionState === 'CANDIDATE_ABSENT') {
      // F93/RF-154.2: un negativo aún sin evidencia suficiente no convierte el
      // chip en "VACÍO" ni contradice la ocupación vigente; se muestra como
      // ausencia pendiente de confirmar.
      presenceChip = chip('AUSENCIA NO CONFIRMADA', 'dim');
    } else if (presenceDecisionState === 'PRESENT_CONFIRMED' || presence === 'PRESENT') {
      presenceChip = chip(presenceDecisionState === 'PRESENT_CONFIRMED'
        ? 'PRESENCIA CONFIRMADA' : 'PRESENCIA', 'warn');
    } else if (presence === 'ABSENT' || presenceDecisionState === 'ABSENT_CONFIRMED') {
      presenceChip = chip('VACÍO', 'dim');
    } else {
      presenceChip = chip('SIN DATOS', 'dim');
    }

    // F77.7: sin estado real por push, se muestra el último comando como "?"
    // (nunca se enciende el foco del croquis con una estimación).
    var lightInferred = (light === 'UNKNOWN'
      && (live.switch_state_inferred === 'ON' || live.switch_state_inferred === 'OFF'))
      ? live.switch_state_inferred : null;
    var lightChip = light === 'ON' ? chip('LUZ ON', 'warn')
      : light === 'OFF' ? chip('LUZ OFF', 'dim')
      : lightInferred ? chip('LUZ ' + lightInferred + '?', 'dim')
      : chip('LUZ —', 'dim');

    var doorText = staleOpen ? 'Estado de puerta no fiable (sensor sin datos)'
      : doorOpen ? 'Puerta abierta'
      : door === 'CLOSED' ? 'Puerta cerrada'
      : 'Puerta sin datos';
    var presenceText = presenceStale ? 'Presencia sin datos'
      : presenceDecisionState === 'CANDIDATE_PRESENT' ? 'Presencia no confirmada (sin evidencia suficiente)'
      : personInside ? 'Persona dentro' : 'Almacén vacío';
    var desc = doorText + '. ' + presenceText
      + '. Luz ' + (light === 'ON' ? 'encendida' : light === 'OFF' ? 'apagada' : 'sin datos') + '.';

    return {
      door: door,
      presence: presence,
      light: light,
      occupied: occupied,
      personInside: personInside,
      doorOpen: doorOpen,
      unknown: unknown,
      // F83/RF-106.2: diagnóstico; NO interviene en `phase`.
      recentPresent: recentPresent,
      // F80/RF-103.4: 'outside' | 'near' | 'inside'. F81/RF-104.5: 'crossing'
      // y 'qr' quedan reservados a la reproducción de visitas (F68), no al modo
      // en vivo. F83/RF-106.2: 'inside' solo con presencia conocida PRESENT o
      // fallback de visita activa; un PRESENT anterior no recoloca dentro.
      phase: phase,
      classes: {
        open: doorOpen,
        occupied: personInside,
        outside: !personInside,
        unknown: unknown,
        lightOn: light === 'ON'
      },
      chips: { door: doorChip, presence: presenceChip, light: lightChip },
      desc: desc
    };
  }

  /**
   * F118: detecta un acceso reciente con ficha/NFC en `recent_events`.
   *
   * El backend de llaves inserta `QR_VALIDATE` y `OPEN` con `meta.key_id`,
   * `meta.owner_type` y, si procede, `meta.worker_id` (AccessKeyService). El QR
   * de huésped lleva `meta.jti` y NO debe tratarse como ficha.
   *
   * @param {Array<object>} events eventos `recent_events` del SSE/`/live`.
   * @param {number} now epoch ms.
   * @param {number} [windowMs] ventana visual (default KEY_ACCESS_WINDOW_MS).
   * @returns {{active:boolean, at:number, event:object|null}}
   */
  function recentKeyAccess(events, now, windowMs) {
    var win = (typeof windowMs === 'number' && windowMs > 0)
      ? windowMs : KEY_ACCESS_WINDOW_MS;
    var nowMs = (typeof now === 'number' && isFinite(now)) ? now : Date.now();
    var best = null;
    var bestAt = 0;
    if (!events || !events.length) return { active: false, at: 0, event: null };

    for (var i = 0; i < events.length; i++) {
      var e = events[i] || {};
      if (e.kind !== 'QR_VALIDATE' && e.kind !== 'OPEN') continue;
      if (String(e.result) !== 'OK') continue;
      var meta = e.meta || {};
      // QR de huésped: token con jti y sin llave. No es una ficha.
      if (meta.key_id === undefined || meta.key_id === null) {
        if (meta.jti || !meta.owner_type) continue;
      }
      var at = parseTime(e.occurred_at);
      if (!at || (nowMs - at) > win || (nowMs - at) < -5000) continue;
      if (at > bestAt) {
        bestAt = at;
        best = e;
      }
    }
    return { active: best !== null, at: bestAt, event: best };
  }

  /**
   * F118: posición del monigote del dashboard a partir de la fase física y del
   * episodio de acceso.
   *
   * Reglas clave:
   *   - Los estados QR/limpieza (DASHBOARD_STATE_ONLY) conservan su posición.
   *   - La presencia NO pasa a INSIDE hasta que la puerta de la entrada se ha
   *     abierto y hay un reporte de presencia posterior a esa apertura.
   *   - Al abrir la puerta se muestra umbral (CROSSING/WAITING) durante
   *     CROSSING_HOLD_MS; después, INSIDE si hay evidencia interior.
   *   - OUTSIDE exige ausencia sostenida (ABSENCE_HOLD_MS) salvo estados de
   *     salida confirmada.
   *   - OCUPADA con puerta cerrada es INSIDE estable (no parpadea por un ABSENT
   *     puntual del radar).
   *
   * 'state' significa: usa `STATES[stateName].moni` en dashboard.html.
   *
   * @param {object} input {stateName, phase, doorOpen, keyAccessActive,
   *                        entryConfirmed, hasEntryContext, hasKeyEpisode,
   *                        keyEpisodePhase, presenceState,
   *                        lastOpenAt, lastCloseAt, lastPresenceAt,
   *                        lastAbsentSince, now,
   *                        crossingHoldMs, absenceHoldMs}
   * @returns {string} 'INSIDE'|'CROSSING'|'WAITING'|'AT_QR'|'OUTSIDE'|'state'
   */
  function resolveDashboardMonigote(input) {
    var s = input || {};
    var stateName = String(s.stateName || '');
    var phase = String(s.phase || 'outside');
    var doorOpen = !!s.doorOpen;
    var keyAccess = !!s.keyAccessActive;
    var entryConfirmed = !!s.entryConfirmed;
    var presenceState = String(s.presenceState || 'UNKNOWN');
    var nowMs = (typeof s.now === 'number' && isFinite(s.now)) ? s.now : Date.now();
    var crossingHold = (typeof s.crossingHoldMs === 'number' && s.crossingHoldMs >= 0)
      ? s.crossingHoldMs : CROSSING_HOLD_MS;
    var closeDecision = (typeof s.closeDecisionMs === 'number' && s.closeDecisionMs >= 0)
      ? s.closeDecisionMs : CLOSE_DECISION_MS;
    var absenceHold = (typeof s.absenceHoldMs === 'number' && s.absenceHoldMs >= 0)
      ? s.absenceHoldMs : ABSENCE_HOLD_MS;

    var lastOpenAt = parseTime(s.lastOpenAt);
    var lastCloseAt = parseTime(s.lastCloseAt);
    var lastPresenceAt = parseTime(s.lastPresenceAt);
    var lastAbsentSince = parseTime(s.lastAbsentSince);

    // Estados que nunca se mueven por sensores.
    if (DASHBOARD_NEVER_SENSOR[stateName]) return 'state';
    // Estados de QR/limpieza: la presencia previa no los sobreescribe. Una
    // ficha sí puede iniciar el movimiento aunque haya un QR de huésped
    // disponible (pero no sustituye a un QR_OK recién escaneado).
    var keyCanStart = keyAccess
      && (stateName === 'QR_DISPONIBLE' || stateName === 'ESPERANDO_HUESPED');
    if (DASHBOARD_STATE_ONLY[stateName] && !keyCanStart) return 'state';

    // F118.4: gate de decisión tras cerrar. Mientras dura, ni INSIDE ni OUTSIDE:
    // el monigote permanece en el umbral. Excluye estados de salida ya confirmada.
    var closeHold = !doorOpen && lastCloseAt > 0
      && (nowMs - lastCloseAt) < closeDecision;
    if (closeHold) {
      if (stateName === 'SALIDA_DETECTADA' || stateName === 'HUESPED_HA_SALIDO') {
        return 'OUTSIDE';
      }
      return 'WAITING';
    }

    var recentOpen = doorOpen && lastOpenAt > 0 && (nowMs - lastOpenAt) < crossingHold;
    var presenceAfterOpen = lastPresenceAt > 0 && lastOpenAt > 0
      && lastPresenceAt >= lastOpenAt;
    var insideAfterOpen = (phase === 'inside') && presenceAfterOpen;
    var presenceAfterClose = lastPresenceAt > 0 && lastCloseAt > 0
      && lastPresenceAt > lastCloseAt;
    var visualExit = lastCloseAt > 0
      && (nowMs - lastCloseAt) >= EXIT_VISUAL_HOLD_MS
      && !presenceAfterClose;
    var maxExit = lastCloseAt > 0
      && (nowMs - lastCloseAt) >= EXIT_MAX_VISUAL_MS;
    var absenceSettled = presenceState === 'ABSENT'
      && lastAbsentSince > 0 && (nowMs - lastAbsentSince) >= absenceHold;

    var hasEntryContext = !!s.hasEntryContext;
    var hasKeyEpisode = !!s.hasKeyEpisode;
    var keyPhase = String(s.keyEpisodePhase || '');

    // F118.3 — ficha/NFC persistente (sin caducidad): se comporta como una
    // estancia en su fase pending/inside/exit.
    if (hasKeyEpisode) {
      if (keyPhase === 'inside') {
        if (!doorOpen) return 'INSIDE';
        if (recentOpen) return 'WAITING';
        if (insideAfterOpen) return 'INSIDE';
        return 'WAITING';
      }
      if (keyPhase === 'exit') {
        if (doorOpen) return 'WAITING';
        if (maxExit) return 'OUTSIDE';
        if (absenceSettled) return 'OUTSIDE';
        if (insideAfterOpen) return 'INSIDE';
        if (visualExit) return 'OUTSIDE';
        return 'WAITING';
      }
      // pending
      if (doorOpen) {
        if (recentOpen) return 'CROSSING';
        return insideAfterOpen ? 'INSIDE' : 'CROSSING';
      }
      return insideAfterOpen ? 'INSIDE' : 'AT_QR';
    }

    // Acceso con ficha/NFC reciente (ventana corta, solo inicio).
    if (keyAccess) {
      if (doorOpen) {
        if (recentOpen) return 'CROSSING';
        return insideAfterOpen ? 'INSIDE' : 'CROSSING';
      }
      return insideAfterOpen ? 'INSIDE' : 'AT_QR';
    }

    // F118.3 — entrada/salida con puerta abierta: NUNCA OUTSIDE.
    if (doorOpen) {
      if (stateName === 'HUESPED_EN_PUERTA') {
        if (recentOpen) return 'CROSSING';
        return insideAfterOpen ? 'INSIDE' : 'CROSSING';
      }
      if (stateName === 'PUERTA_ABIERTA' || stateName === 'OCUPADA'
          || stateName === 'VERIFICANDO_PRESENCIA'
          || stateName === 'VERIFICANDO_ENTRADA') {
        if (recentOpen) return 'WAITING';
        return insideAfterOpen ? 'INSIDE' : 'WAITING';
      }
      if (stateName === 'SALIDA_DETECTADA' || stateName === 'HUESPED_HA_SALIDO') {
        return 'OUTSIDE';
      }
      // ESPERANDO / ANOMALIA / estados FREE: umbral mientras la puerta esté abierta.
      return 'CROSSING';
    }

    // Puerta cerrada.
    if (stateName === 'HUESPED_EN_PUERTA') {
      if (insideAfterOpen || entryConfirmed) return 'INSIDE';
      return 'WAITING';
    }

    if (stateName === 'PUERTA_ABIERTA' || stateName === 'VERIFICANDO_PRESENCIA') {
      if (maxExit) return 'OUTSIDE';
      if (absenceSettled) return 'OUTSIDE';
      if (phase === 'inside' && (presenceAfterClose || insideAfterOpen)) return 'INSIDE';
      if (visualExit) return 'OUTSIDE';
      return 'WAITING';
    }

    if (stateName === 'VERIFICANDO_ENTRADA') {
      if (insideAfterOpen || entryConfirmed) return 'INSIDE';
      return 'WAITING';
    }

    if (stateName === 'OCUPADA' || stateName === 'ANTI_REENTRADA'
        || stateName === 'EXCESO_TIEMPO') {
      return 'INSIDE';
    }

    if (stateName === 'SALIDA_DETECTADA' || stateName === 'HUESPED_HA_SALIDO') {
      return 'OUTSIDE';
    }

    if (stateName === 'ESPERANDO') {
      // Sin estancia ni ficha: la presencia sola NO acredita huésped dentro.
      if (!hasEntryContext) return 'OUTSIDE';
      if (maxExit) return 'OUTSIDE';
      if (absenceSettled) return 'OUTSIDE';
      if (insideAfterOpen) return 'INSIDE';
      if (visualExit) return 'OUTSIDE';
      return 'INSIDE';
    }

    if (stateName === 'ANOMALIA_RP' || stateName === 'ANOMALIA_PA') {
      if (!hasEntryContext) return 'OUTSIDE';
      if (maxExit) return 'OUTSIDE';
      if (absenceSettled) return 'OUTSIDE';
      if (insideAfterOpen) return 'INSIDE';
      if (visualExit) return 'OUTSIDE';
      return 'WAITING';
    }

    // Fallback para estados no contemplados.
    if (phase === 'inside' && hasEntryContext) return 'INSIDE';
    if (phase === 'near') return 'CROSSING';
    return 'state';
  }

  /**
   * F138: posición del monigote cuando el servidor manda un estado de
   * ocupación STANDARD. La UI no reinterpreta la señal cruda.
   */
  function resolveStandardMonigote(occupancyState, doorOpen) {
    var state = String(occupancyState || '').toUpperCase();
    if (state === 'VACANT_CONFIRMED') return 'OUTSIDE';
    if (state === 'ACCESS_PENDING') return 'AT_QR';
    if (state === 'DOOR_OPEN') return doorOpen ? 'CROSSING' : 'WAITING';
    if (state === 'OCCUPIED') return doorOpen ? 'WAITING' : 'INSIDE';
    if (state === 'ENTRY_VERIFY' || state === 'EXIT_VERIFY'
        || state === 'OCCUPANCY_UNCERTAIN') return 'WAITING';
    return 'state';
  }

  return {
    deriveCroquis: deriveCroquis,
    resolveStandardMonigote: resolveStandardMonigote,
    parseTime: parseTime,
    resolveDoor: resolveDoor,
    recentKeyAccess: recentKeyAccess,
    resolveDashboardMonigote: resolveDashboardMonigote,
    DOOR_PULSE_MS: DOOR_PULSE_MS,
    KEY_ACCESS_WINDOW_MS: KEY_ACCESS_WINDOW_MS,
    CROSSING_HOLD_MS: CROSSING_HOLD_MS,
    ABSENCE_HOLD_MS: ABSENCE_HOLD_MS,
    EXIT_VISUAL_HOLD_MS: EXIT_VISUAL_HOLD_MS,
    EXIT_MAX_VISUAL_MS: EXIT_MAX_VISUAL_MS,
    CLOSE_DECISION_MS: CLOSE_DECISION_MS
  };
});
