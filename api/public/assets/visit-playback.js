/**
 * visit-playback.js — F68 (RF-78): lógica PURA de reconstrucción/reproducción de
 * una visita del almacén.
 *
 * Trazabilidad: RF-78.2/78.3/78.6/78.8 · design.md §32 · tasks.md TSK-F68-03.
 *
 * No toca el DOM, ni la red, ni variables globales mutables. Construye una línea
 * de tiempo a partir de los hitos de la visita (`qr_at`, `entered_at`,
 * `exited_at`, `created_at`) y de sus `camera_recordings`, y resuelve el
 * "fotograma" (fase del monigote, puerta, luz, reloj, clip activo) para un
 * instante dado.
 *
 * UMD: global `VisitPlayback` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('./choreography.js'));
  } else {
    root.VisitPlayback = factory(root.Choreography);
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function (Choreography) {
  'use strict';

  /** Posiciones de cámara del almacén. */
  var POSITIONS = ['EXTERIOR', 'INTERIOR'];

  /** Ventana visual del escaneo de QR (ms). */
  var SCAN_MS = 1600;
  /** Duración visual mínima de un cruce de puerta sin datos (ms). */
  var CROSS_MS = 2500;
  /** Cola mínima tras el último hito (ms). */
  var TAIL_MS = 1500;
  /** Antelación visual para mostrar al monigote acercándose al lector QR (ms). */
  var PRE_ROLL_MS = 2500;

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

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  /** Reloj `HH:MM:SS` de un epoch ms, en el huso indicado (min; por defecto local). */
  function formatClock(ms, offsetMinutes) {
    if (!ms) return '--:--:--';
    var off = (typeof offsetMinutes === 'number') ? offsetMinutes : -new Date().getTimezoneOffset();
    var d = new Date(ms + off * 60000);
    return pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes()) + ':' + pad2(d.getUTCSeconds());
  }

  /** Duración `mm:ss` o `h:mm:ss`. */
  function formatDuration(totalSeconds) {
    var s = Math.max(0, Math.floor(totalSeconds || 0));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
    return (h > 0 ? h + ':' + pad2(m) : pad2(m)) + ':' + pad2(x);
  }

  function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

  function chip(text, mod) { return { text: text, mod: mod || '' }; }

  var OUTCOME_LABEL = {
    ENTERED: 'Entró',
    NO_SHOW: 'No entró',
    ANONYMOUS: 'Anónimo',
    DENIED: 'Denegado'
  };

  /**
   * Busca el clip activo de una posición en un instante (ms desde el origen).
   * @returns {object|null}
   */
  function clipAt(clips, position, elapsedMs, videoOnly) {
    for (var i = 0; i < clips.length; i++) {
      var c = clips[i];
      if (c.position !== position) continue;
      if (videoOnly && !c.videoUrl) continue;
      if (elapsedMs >= c.startMs && elapsedMs < c.endMs) return c;
    }
    return null;
  }

  /**
   * Construye la línea de tiempo de una visita.
   * @param {object} visit
   * @returns {object}
   */
  function buildVisitTimeline(visit) {
    var v = visit || {};
    var recs = v.recordings || [];

    var qrMs = parseTime(v.qr_at);
    var enteredMs = parseTime(v.entered_at);
    var exitedMs = parseTime(v.exited_at);
    var createdMs = parseTime(v.created_at);

    var clips = [];
    var originCandidates = [];
    var endCandidates = [];

    for (var i = 0; i < recs.length; i++) {
      var r = recs[i];
      var startRaw = parseTime(r.requested_at) || parseTime(r.started_at);
      if (!startRaw) continue;
      var durMs = (typeof r.duration_s === 'number' && r.duration_s > 0) ? r.duration_s * 1000 : 0;
      var endRaw = parseTime(r.stopped_at) || (startRaw + durMs);
      if (endRaw < startRaw) endRaw = startRaw;
      clips.push({
        id: r.id,
        position: String(r.position || ''),
        episode: String(r.episode || ''),
        trigger: String(r.trigger || ''),
        status: String(r.status || ''),
        hasVideo: r.status === 'SAVED' && !!r.video_url,
        videoUrl: r.video_url || null,
        posterUrl: r.poster_url || null,
        startRaw: startRaw,
        endRaw: endRaw,
        durationMs: Math.max(0, endRaw - startRaw)
      });
      originCandidates.push(startRaw);
      endCandidates.push(endRaw);
    }

    if (qrMs) originCandidates.push(qrMs);
    if (createdMs) originCandidates.push(createdMs);
    var originMs = originCandidates.length ? Math.min.apply(null, originCandidates) : (createdMs || Date.now());
    // Antelación visual: solo si hubo QR, para poder ver el acercamiento al lector.
    if (qrMs) originMs -= PRE_ROLL_MS;

    if (enteredMs) endCandidates.push(enteredMs);
    if (exitedMs) endCandidates.push(exitedMs);
    var endMs = endCandidates.length ? Math.max.apply(null, endCandidates) : originMs;
    if (endMs <= originMs) endMs = originMs + TAIL_MS;

    // Offsets relativos al origen.
    for (var j = 0; j < clips.length; j++) {
      clips[j].startMs = clamp(clips[j].startRaw - originMs, 0, endMs - originMs);
      clips[j].endMs = clamp(clips[j].endRaw - originMs, clips[j].startMs, endMs - originMs);
    }
    clips.sort(function (a, b) { return a.startMs - b.startMs; });

    var durationMs = endMs - originMs;

    var markers = [];
    if (qrMs) markers.push({ type: 'QR', icon: '📱', label: 'QR', ms: clamp(qrMs - originMs, 0, durationMs), wall: formatClock(qrMs) });
    if (enteredMs) markers.push({ type: 'ENTRY', icon: '🟢', label: 'Entrada', ms: clamp(enteredMs - originMs, 0, durationMs), wall: formatClock(enteredMs) });
    if (exitedMs) markers.push({ type: 'EXIT', icon: '🚪', label: 'Salida', ms: clamp(exitedMs - originMs, 0, durationMs), wall: formatClock(exitedMs) });

    var inside = enteredMs
      ? {
          startMs: clamp(enteredMs - originMs, 0, durationMs),
          endMs: clamp((exitedMs || endMs) - originMs, 0, durationMs)
        }
      : null;

    // Inicio del cruce de entrada = primer clip ENTRY; si no, el QR; si no, 0.
    var firstEntry = null;
    for (var k = 0; k < clips.length; k++) {
      if (clips[k].episode === 'ENTRY') { firstEntry = clips[k]; break; }
    }
    var crossInMs = firstEntry ? firstEntry.startMs : (qrMs ? clamp(qrMs - originMs, 0, durationMs) : 0);

    // F77.2: inicio de la salida. El fin del clip INTERIOR ya NO se usa como
    // ancla: con el tope de grabación (p. ej. 60 s en pruebas) termina mucho
    // antes que la estancia y el monigote quedaba "Saliendo" durante el resto.
    // Se ancla a `exited_at` (autoridad del backend) reservando CROSS_MS para el
    // cruce; un clip EXIT explícito sigue teniendo prioridad.
    var exitClip = null;
    for (var m = 0; m < clips.length; m++) {
      if (clips[m].episode === 'EXIT' && !exitClip) exitClip = clips[m];
    }
    var crossOutMs = null;
    if (exitClip) {
      crossOutMs = exitClip.startMs;
    } else if (inside) {
      var exitAt = exitedMs ? clamp(exitedMs - originMs, 0, durationMs) : inside.endMs;
      crossOutMs = Math.max(inside.startMs, Math.min(exitAt, inside.endMs) - CROSS_MS);
    }
    // Garantiza una ventana visible de "saliendo" dentro de la estancia.
    if (inside) {
      if (crossOutMs == null) {
        crossOutMs = Math.max(inside.startMs, inside.endMs - CROSS_MS);
      }
      crossOutMs = clamp(crossOutMs, inside.startMs, inside.endMs);
    }

    var timeline = {
      visitId: v.id != null ? v.id : null,
      outcome: String(v.outcome || ''),
      outcomeLabel: OUTCOME_LABEL[v.outcome] || String(v.outcome || ''),
      entryTrigger: String(v.entry_trigger || ''),
      originMs: originMs,
      endMs: endMs,
      durationMs: durationMs,
      clips: clips,
      markers: markers,
      inside: inside,
      crossInMs: crossInMs,
      crossOutMs: crossOutMs,
      hasRecordings: clips.some(function (c) { return c.hasVideo; }),
      hasCameras: POSITIONS.some(function (p) { return clips.some(function (c) { return c.position === p; }); })
    };
    return timeline;
  }

  /** Fase del monigote para un instante (ms desde el origen). */
  function phaseAt(tl, elapsedMs) {
    var t = clamp(elapsedMs, 0, tl.durationMs);
    var qrAt = null;
    for (var i = 0; i < tl.markers.length; i++) {
      if (tl.markers[i].type === 'QR') { qrAt = tl.markers[i].ms; break; }
    }
    var crossIn = tl.crossInMs;

    if (tl.inside) {
      if (qrAt != null && t < qrAt) return 'approach';
      // Ventana de escaneo: al menos SCAN_MS y hasta el inicio del cruce, sin
      // invadir el momento en que ya está dentro.
      var scanEnd = qrAt != null ? (qrAt + SCAN_MS) : 0;
      if (crossIn > scanEnd) scanEnd = crossIn;
      if (scanEnd > tl.inside.startMs) scanEnd = tl.inside.startMs;
      if (qrAt != null && t < scanEnd) return 'scan';
      if (t < tl.inside.startMs) return 'enter';
      var exitStart = tl.crossOutMs != null ? tl.crossOutMs : tl.inside.endMs;
      if (t < exitStart) return 'inside';
      if (t < tl.inside.endMs) return 'exit';
      return 'after';
    }

    // Sin estancia consolidada (NO_SHOW / denegada / anónima sin entrada).
    if (qrAt != null && t < qrAt) return 'approach';
    if (qrAt != null && t < qrAt + SCAN_MS) return 'scan';
    if (t < crossIn + CROSS_MS) return 'enter';
    return 'after';
  }

  var PHASE_LABEL = {
    approach: 'Acercándose',
    scan: 'Escaneando QR',
    enter: 'Entrando',
    inside: 'Dentro',
    exit: 'Saliendo',
    after: 'Fuera'
  };

  /**
   * Resuelve el fotograma de reproducción para un instante de reloj (epoch ms).
   * @param {object} tl línea de tiempo
   * @param {number} wallMs epoch ms de la hora de la visita
   * @param {number=} offsetMinutes huso para el reloj (por defecto local)
   * @returns {object}
   */
  function frameAt(tl, wallMs, offsetMinutes) {
    var elapsedMs = clamp(wallMs - tl.originMs, 0, tl.durationMs);
    var phase = phaseAt(tl, elapsedMs);
    var personInside = phase === 'inside';
    var doorOpen = phase === 'enter' || phase === 'exit';
    var lightOn = phase === 'enter' || phase === 'inside' || phase === 'exit';

    var insideSeconds = 0;
    if (tl.inside) {
      insideSeconds = clamp(elapsedMs - tl.inside.startMs, 0, tl.inside.endMs - tl.inside.startMs) / 1000;
    }

    var active = {};
    for (var i = 0; i < POSITIONS.length; i++) {
      var pos = POSITIONS[i];
      var c = clipAt(tl.clips, pos, elapsedMs, true);
      active[pos] = c
        ? {
            id: c.id,
            position: pos,
            episode: c.episode,
            videoUrl: c.videoUrl,
            posterUrl: c.posterUrl,
            localTimeMs: clamp(elapsedMs - c.startMs, 0, c.durationMs),
            durationMs: c.durationMs
          }
        : null;
    }

    var doorChip = doorOpen ? chip('PUERTA ABIERTA', 'ok') : chip('PUERTA CERRADA', 'dim');
    var presenceChip = personInside ? chip('PRESENTE', 'ok') : chip('VACÍO', 'dim');
    var lightChip = lightOn ? chip('LUZ ON', 'warn') : chip('LUZ OFF', 'dim');

    var doorText = doorOpen ? 'Puerta abierta' : 'Puerta cerrada';
    var desc = doorText + '. ' + (personInside ? 'Persona dentro' : 'Fuera')
      + '. ' + PHASE_LABEL[phase] + '. Hora ' + formatClock(wallMs, offsetMinutes) + '.';

    return {
      elapsedMs: elapsedMs,
      remainingMs: tl.durationMs - elapsedMs,
      phase: phase,
      phaseLabel: PHASE_LABEL[phase] || phase,
      personPos: phase === 'inside' ? 'inside'
        : phase === 'scan' ? 'qr'
        : (phase === 'enter' || phase === 'exit') ? 'crossing'
        : phase === 'approach' ? 'near'
        : 'outside',
      doorOpen: doorOpen,
      lightOn: lightOn,
      personInside: personInside,
      insideSeconds: insideSeconds,
      wallClockLabel: formatClock(wallMs, offsetMinutes),
      elapsedLabel: formatDuration(elapsedMs / 1000),
      insideLabel: formatDuration(insideSeconds),
      activeClips: active,
      chips: { door: doorChip, presence: presenceChip, light: lightChip },
      desc: desc
    };
  }

  return {
    buildVisitTimeline: buildVisitTimeline,
    frameAt: frameAt,
    phaseAt: phaseAt,
    clipAt: clipAt,
    formatClock: formatClock,
    formatDuration: formatDuration,
    POSITIONS: POSITIONS,
    SCAN_MS: SCAN_MS,
    PRE_ROLL_MS: PRE_ROLL_MS,
    PHASE_LABEL: PHASE_LABEL
  };
});
