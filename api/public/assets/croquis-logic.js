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
    var phase;
    if (personInside) {
      phase = 'inside';
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
    } else if (presence === 'PRESENT') {
      presenceChip = chip('PRESENCIA', 'warn');
    } else if (presence === 'ABSENT') {
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

  return {
    deriveCroquis: deriveCroquis,
    parseTime: parseTime,
    resolveDoor: resolveDoor,
    DOOR_PULSE_MS: DOOR_PULSE_MS
  };
});
