/* Timbre — video portero del almacén (F115/RF-286…RF-292).
 * Sin dependencias. Estado por SSE con watchdog y fallback a polling.
 * No llama a Tuya: la presencia llega por push y la apertura es explícita. */
(function () {
  'use strict';

  var L = window.TimbreLogic;
  var COOLDOWN_MS = (L && L.OPEN_COOLDOWN_MS) || 3000;
  var CONFIRM_TIMEOUT_MS = 8000;
  var SSE_WATCHDOG_MS = 15000;
  var REQUEST_TIMEOUT_MS = 8000;

  var $ = function (id) { return document.getElementById(id); };
  var app = $('app');
  var stage = $('stage');
  var img = $('cam-interior');
  var fallback = $('cam-fallback');
  var camMessage = $('cam-message');
  var btnEnable = $('btn-cam-enable');
  var btnRetry = $('btn-cam-retry');
  var btnOpen = $('btn-open');
  var openText = $('open-text');
  var presenceLabel = $('presence-label');
  var doorVisual = $('door-visual');
  var doorTitle = $('door-title');
  var doorLabel = $('door-label');
  var lastOpenEl = $('last-open');
  var actionEl = $('action-status');
  var netEl = $('net-status');
  var roomSelect = $('room-select');
  var toastEl = $('toast');

  var snapshot = null;
  var rooms = [];
  var ROOM_ID = 0;
  var sse = null;
  var pollTimer = null;
  var sseRetryTimer = null;
  var lastStateAt = 0;
  var currentCamera = null;
  var camFailed = false;
  var retryToken = 0;

  var inflight = false;
  var pendingOpen = false;
  var confirmTimer = null;
  var cooldownUntil = 0;
  var cooldownTimer = null;
  var okTimer = null;

  // ── utilidades ────────────────────────────────────────────────────────
  function request(path, opts) {
    var options = opts || {};
    options.cache = 'no-store';
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = null;
    if (ctrl) {
      options.signal = ctrl.signal;
      timer = setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, REQUEST_TIMEOUT_MS);
    }
    return fetch(path, options).then(function (res) {
      return res.text().then(function (txt) {
        var body = {};
        try { body = JSON.parse(txt); } catch (e) { body = {}; }
        return { status: res.status, ok: res.ok, body: body };
      });
    }).then(function (out) {
      if (timer) clearTimeout(timer);
      return out;
    }, function (err) {
      if (timer) clearTimeout(timer);
      throw err;
    });
  }

  function toast(message, tone) {
    toastEl.textContent = message;
    toastEl.dataset.tone = tone || '';
    toastEl.classList.add('is-visible');
    clearTimeout(toastEl._h);
    toastEl._h = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 3200);
  }

  function setNet(label, tone) {
    netEl.textContent = label;
    netEl.dataset.tone = tone || 'warn';
  }

  function setAction(text, tone) {
    actionEl.textContent = text || '';
    actionEl.dataset.tone = tone || '';
  }

  function formatClock(value) {
    var ms = L.parseTime(value);
    if (!ms) return '—';
    try {
      return new Date(ms).toLocaleTimeString('es-ES', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    } catch (e) {
      return '—';
    }
  }

  function urlWithRetry(base, token) {
    if (!token) return base;
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + '_r=' + encodeURIComponent(String(token));
  }

  // ── render ────────────────────────────────────────────────────────────
  function applyPresence(live) {
    var p = L.derivePresence(live);
    app.dataset.presence = p.state;
    presenceLabel.textContent = p.label;
    var dot = $('presence-dot');
    if (dot) dot.dataset.tone = p.tone;
  }

  function confirmDoor() {
    pendingOpen = false;
    if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
    setAction('Puerta abierta', 'ok');
    btnOpen.classList.remove('is-error', 'is-busy');
    btnOpen.classList.add('is-ok');
    if (okTimer) clearTimeout(okTimer);
    okTimer = setTimeout(function () {
      btnOpen.classList.remove('is-ok');
      renderButton();
    }, 2400);
    renderButton();
  }

  function applyDoor(live) {
    var d = L.deriveDoor(live, Date.now());
    app.dataset.door = d.state;
    doorVisual.classList.toggle('is-open', d.state === 'open');
    doorVisual.classList.toggle('is-closed', d.state === 'closed');
    doorVisual.classList.toggle('is-unknown', d.state === 'unknown');
    doorLabel.textContent = d.label;
    doorTitle.textContent = 'Estado de la puerta: ' + d.label.toLowerCase();
    lastOpenEl.textContent = formatClock(live && live.last_open_at);

    if (pendingOpen && d.state === 'open') {
      confirmDoor();
    }
  }

  function releaseStream() {
    if (img.hasAttribute('src')) img.removeAttribute('src');
    img.dataset.sig = '';
    img.hidden = true;
  }

  function showFallback(message, showEnable, showRetry) {
    camMessage.textContent = message;
    fallback.hidden = false;
    btnEnable.hidden = !showEnable;
    btnRetry.hidden = !showRetry;
    img.hidden = true;
  }

  function applyCamera(cameras) {
    var cam = L.pickInteriorCamera(cameras);
    currentCamera = cam;

    if (!cam) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'no-camera';
      $('cam-label').textContent = 'cámara interior';
      showFallback('Sin cámara interior configurada para este almacén.', false, false);
      return;
    }

    $('cam-label').textContent = 'Cámara interior';
    $('cam-label').title = cam.label || '';

    if (cam.enabled === false) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'off';
      showFallback('Cámara interior apagada. El timbre sigue activo.', true, false);
      return;
    }

    if (!cam.mjpeg_url) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'no-url';
      showFallback('Directo no disponible para esta cámara.', false, false);
      return;
    }

    if (camFailed) {
      app.dataset.camera = 'no-signal';
      showFallback('Sin señal de la cámara interior.', false, true);
      return;
    }

    app.dataset.camera = 'live';
    var sig = L.cameraSignature(cam) + '|' + retryToken;
    if (img.dataset.sig !== sig) {
      img.dataset.sig = sig;
      img.hidden = false;
      fallback.hidden = true;
      btnEnable.hidden = true;
      btnRetry.hidden = true;
      stage.classList.remove('is-nosignal', 'is-off');
      img.src = urlWithRetry(cam.mjpeg_url, retryToken);
    } else if (!fallback.hidden && !camFailed) {
      // El estado ya es "live": no se recrea el <img>, solo se asegura el visor.
      img.hidden = false;
      fallback.hidden = true;
    }
  }

  function renderButton() {
    var now = Date.now();
    var cooling = now < cooldownUntil;
    var busy = inflight || pendingOpen;
    btnOpen.classList.toggle('is-busy', busy);
    btnOpen.classList.toggle('is-cooldown', !busy && cooling);
    btnOpen.disabled = busy || cooling;
    btnOpen.setAttribute('aria-busy', inflight ? 'true' : 'false');
    if (busy) openText.textContent = 'Abriendo…';
    else if (cooling) openText.textContent = 'Espera';
    else openText.textContent = 'Abrir';
  }

  function startCooldown() {
    cooldownUntil = Date.now() + COOLDOWN_MS;
    if (cooldownTimer) clearTimeout(cooldownTimer);
    cooldownTimer = setTimeout(function () {
      cooldownTimer = null;
      renderButton();
    }, COOLDOWN_MS + 40);
    renderButton();
  }

  function applyState(s) {
    if (!s || !s.room) {
      snapshot = null;
      showEmpty('Timbre no asignado a un almacén.');
      return;
    }
    if (L.roomMismatch(ROOM_ID, s)) {
      snapshot = null;
      showEmpty('La sala solicitada no está disponible.');
      setNet('Sala no disponible', 'bad');
      return;
    }

    snapshot = s;
    lastStateAt = Date.now();
    setNet('En línea', 'ok');

    if (s.room.code && roomSelect) {
      // Mantiene el desplegable alineado con el snapshot real.
      var options = roomSelect.options || [];
      for (var i = 0; i < options.length; i++) {
        if (String(options[i].value) === String(s.room.id)) { roomSelect.value = options[i].value; break; }
      }
    }

    applyPresence(s.live || {});
    applyDoor(s.live || {});
    applyCamera(s.cameras || []);
    renderButton();
  }

  function showEmpty(message) {
    releaseStream();
    app.dataset.camera = 'empty';
    app.dataset.presence = 'unknown';
    app.dataset.door = 'unknown';
    presenceLabel.textContent = 'SIN DATOS';
    doorLabel.textContent = 'SIN DATOS';
    doorVisual.classList.remove('is-open', 'is-closed');
    doorVisual.classList.add('is-unknown');
    doorTitle.textContent = 'Estado de la puerta: sin datos';
    lastOpenEl.textContent = '—';
    $('cam-label').textContent = 'cámara interior';
    showFallback(message || 'Sin almacén asignado.', false, false);
    btnOpen.disabled = true;
    setAction('', '');
  }

  // ── cámara ────────────────────────────────────────────────────────────
  function enableCamera() {
    if (!currentCamera || !currentCamera.id) return;
    btnEnable.disabled = true;
    btnEnable.setAttribute('aria-busy', 'true');
    setAction('Encendiendo cámara…', 'busy');
    request('/almacen-api/cameras/' + encodeURIComponent(currentCamera.id), {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ enabled: true }),
    }).then(function (res) {
      if (!res.ok) throw new Error((res.body && (res.body.message || res.body.error)) || ('HTTP ' + res.status));
      toast('Cámara interior encendida');
      setAction('', '');
      camFailed = false;
      retryToken = 0;
      btnEnable.disabled = false;
      btnEnable.removeAttribute('aria-busy');
      pollState();
      setTimeout(pollState, 1500);
    }).catch(function (err) {
      btnEnable.disabled = false;
      btnEnable.removeAttribute('aria-busy');
      setAction('No se pudo encender la cámara', 'bad');
      toast('Cámara: ' + err.message, 'bad');
    });
  }

  function retryCamera() {
    camFailed = false;
    retryToken = Date.now();
    if (snapshot) applyCamera(snapshot.cameras || []);
  }

  // ── apertura ──────────────────────────────────────────────────────────
  function openDoor() {
    if (!snapshot || !snapshot.room) {
      toast('No hay un almacén seleccionado', 'bad');
      return;
    }
    if (!L.canOpen(Date.now(), cooldownUntil - COOLDOWN_MS, inflight, COOLDOWN_MS)) return;

    inflight = true;
    pendingOpen = true;
    btnOpen.classList.remove('is-ok', 'is-error');
    setAction('Solicitando apertura…', 'busy');
    renderButton();

    request('/almacen-api/door/open', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ room_id: snapshot.room.id }),
    }).then(function (res) {
      inflight = false;
      var outcome = L.doorOutcome(res.status, res.body);
      if (!outcome.ok) {
        pendingOpen = false;
        setAction(outcome.message, 'bad');
        btnOpen.classList.add('is-error');
        toast('No se pudo abrir: ' + outcome.message, 'bad');
        startCooldown();
        setTimeout(function () { btnOpen.classList.remove('is-error'); renderButton(); }, 2400);
        return;
      }

      setAction('Apertura solicitada · esperando sensor', 'busy');
      pollState();
      if (confirmTimer) clearTimeout(confirmTimer);
      confirmTimer = setTimeout(function () {
        if (!pendingOpen) return;
        pendingOpen = false;
        confirmTimer = null;
        setAction('Pedido enviado · sin confirmación del sensor', 'warn');
        toast('La puerta no confirmó la apertura', 'warn');
        renderButton();
      }, CONFIRM_TIMEOUT_MS);

      // Si ya estaba abierta, no habrá transición: se confirma con el snapshot.
      var d = L.deriveDoor(snapshot.live || {}, Date.now());
      if (d.state === 'open') confirmDoor();
      startCooldown();
    }).catch(function (err) {
      inflight = false;
      pendingOpen = false;
      setAction('No se pudo contactar con la cerradura', 'bad');
      btnOpen.classList.add('is-error');
      toast('Cerraduras: ' + err.message, 'bad');
      startCooldown();
      setTimeout(function () { btnOpen.classList.remove('is-error'); renderButton(); }, 2400);
    });
  }

  // ── datos / conexión ──────────────────────────────────────────────────
  function pollState() {
    if (!ROOM_ID) return Promise.resolve();
    return request('/almacen-api/state?room_id=' + encodeURIComponent(ROOM_ID))
      .then(function (res) {
        if (res.status === 404) { setNet('Sala no disponible', 'bad'); return; }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        applyState(res.body);
        return res.body;
      })
      .catch(function () {
        if (!sse) setNet('Sin conexión', 'bad');
      });
  }

  function startPolling() {
    if (pollTimer) return;
    pollState();
    pollTimer = setInterval(function () {
      if (!document.hidden) pollState();
    }, 2000);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function closeSse() {
    if (sse) { try { sse.close(); } catch (e) {} sse = null; }
  }

  function scheduleReconnect(ms) {
    if (sseRetryTimer) return;
    closeSse();
    sseRetryTimer = setTimeout(function () {
      sseRetryTimer = null;
      connectSse();
    }, ms);
  }

  function onStreamAlive() {
    lastStateAt = Date.now();
    stopPolling();
    setNet('En línea', 'ok');
  }

  function connectSse() {
    if (!ROOM_ID) return;
    closeSse();
    var url = '/almacen-api/event-stream?room_id=' + encodeURIComponent(ROOM_ID);
    try {
      sse = new EventSource(url);
    } catch (e) {
      setNet('Sin directo', 'warn');
      startPolling();
      return;
    }
    sse.addEventListener('connected', onStreamAlive);
    sse.addEventListener('ping', onStreamAlive);
    sse.addEventListener('state', function (ev) {
      onStreamAlive();
      try { applyState(JSON.parse(ev.data)); } catch (e) {}
    });
    sse.addEventListener('close', function () {
      scheduleReconnect(300);
    });
    sse.onerror = function () {
      setNet('Reconectando', 'warn');
      startPolling();
      scheduleReconnect(2000);
    };
  }

  function renderRoomOptions(list) {
    roomSelect.innerHTML = '';
    if (!list.length) {
      var opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Sin almacenes';
      roomSelect.appendChild(opt);
      return;
    }
    list.forEach(function (r) {
      var o = document.createElement('option');
      o.value = String(r.id);
      o.textContent = String(r.code || ('Sala ' + r.id));
      roomSelect.appendChild(o);
    });
  }

  function loadRooms() {
    return request('/dashboard-api/rooms').then(function (res) {
      if (!res.ok || !Array.isArray(res.body)) throw new Error('No se pudo cargar la lista de almacenes');
      rooms = L.filterWarehouses(res.body);
      renderRoomOptions(rooms);
      var requested = parseInt((new URLSearchParams(location.search)).get('room') || '0', 10) || 0;
      var selected = L.pickDefaultRoom(rooms, requested, 'almacenZigbee');
      if (!selected) {
        showEmpty('No hay almacenes configurados en el proyecto.');
        setNet('Sin almacén', 'bad');
        return null;
      }
      ROOM_ID = parseInt(selected.id, 10) || 0;
      roomSelect.value = String(ROOM_ID);
      if (!requested) {
        try { history.replaceState(null, '', '?room=' + ROOM_ID); } catch (e) {}
      }
      return selected;
    }).catch(function (err) {
      setNet('Sin conexión', 'bad');
      showEmpty('No se pudo cargar la lista de almacenes.');
      toast(err.message, 'bad');
      return null;
    });
  }

  function switchRoom(id) {
    var next = parseInt(id, 10) || 0;
    if (!next || next === ROOM_ID) return;
    ROOM_ID = next;
    try { history.replaceState(null, '', '?room=' + ROOM_ID); } catch (e) {}
    snapshot = null;
    camFailed = false;
    retryToken = 0;
    pendingOpen = false;
    inflight = false;
    if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
    if (cooldownTimer) { clearTimeout(cooldownTimer); cooldownTimer = null; }
    cooldownUntil = 0;
    if (sseRetryTimer) { clearTimeout(sseRetryTimer); sseRetryTimer = null; }
    stopPolling();
    closeSse();
    showEmpty('Conectando con el almacén…');
    pollState();
    connectSse();
  }

  // ── eventos ───────────────────────────────────────────────────────────
  btnEnable.addEventListener('click', enableCamera);
  btnRetry.addEventListener('click', retryCamera);
  btnOpen.addEventListener('click', openDoor);
  roomSelect.addEventListener('change', function () { switchRoom(this.value); });

  img.addEventListener('load', function () {
    camFailed = false;
    img.hidden = false;
    fallback.hidden = true;
    stage.classList.remove('is-nosignal', 'is-off');
  });
  img.addEventListener('error', function () {
    if (!currentCamera || currentCamera.enabled === false) return;
    camFailed = true;
    app.dataset.camera = 'no-signal';
    showFallback('Sin señal de la cámara interior.', false, true);
    stage.classList.add('is-nosignal');
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
    } else {
      pollState();
      if (!sse) connectSse();
    }
  });

  // Watchdog: el SSE puede quedarse mudo sin disparar onerror.
  setInterval(function () {
    if (!ROOM_ID || document.hidden) return;
    if (Date.now() - lastStateAt > SSE_WATCHDOG_MS) {
      setNet('Reconectando', 'warn');
      startPolling();
      scheduleReconnect(1000);
    }
  }, 5000);

  // ── arranque ──────────────────────────────────────────────────────────
  btnOpen.disabled = true;
  showEmpty('Conectando con el almacén…');
  loadRooms().then(function (selected) {
    if (!selected) return;
    pollState();
    connectSse();
  });
})();
