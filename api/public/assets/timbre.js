/* Timbre — video portero de oficina (F116, revisa F115).
 * Sin dependencias. SSE con watchdog y fallback a polling.
 * No llama a Tuya: la presencia llega por push y la apertura es explícita. */
(function () {
  'use strict';

  var L = window.TimbreLogic;
  var COOLDOWN_MS = (L && L.OPEN_COOLDOWN_MS) || 3000;
  var CONFIRM_TIMEOUT_MS = 8000;
  var SSE_WATCHDOG_MS = 15000;
  var REQUEST_TIMEOUT_MS = 8000;
  var OPENINGS_REFRESH_MS = 10000;

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
  var openingsList = $('openings-list');
  var btnCreateKey = $('btn-create-key');
  var modalBackdrop = $('modal-backdrop');
  var modalTitle = $('modal-title');
  var modalBody = $('modal-body');
  var modalClose = $('modal-close');

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

  var openings = [];
  var openingsTimer = null;
  var lastMovementId = null;
  var modalFocus = null;

  // ── utilidades ────────────────────────────────────────────────────────
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

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

  function applyCamera(cam) {
    currentCamera = cam || null;
    if (!currentCamera) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'no-camera';
      showFallback('Sin cámara configurada para la oficina.', false, false);
      return;
    }
    if (currentCamera.enabled === false) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'off';
      showFallback('Cámara apagada. El timbre sigue activo.', true, false);
      return;
    }
    if (!currentCamera.mjpeg_url) {
      releaseStream();
      camFailed = false;
      app.dataset.camera = 'no-url';
      showFallback('Directo no disponible para esta cámara.', false, false);
      return;
    }
    if (camFailed) {
      app.dataset.camera = 'no-signal';
      showFallback('Sin señal de la cámara.', false, true);
      return;
    }
    app.dataset.camera = 'live';
    var sig = L.cameraSignature(currentCamera) + '|' + retryToken;
    if (img.dataset.sig !== sig) {
      img.dataset.sig = sig;
      img.hidden = false;
      fallback.hidden = true;
      btnEnable.hidden = true;
      btnRetry.hidden = true;
      stage.classList.remove('is-nosignal', 'is-off');
      img.src = urlWithRetry(currentCamera.mjpeg_url, retryToken);
    } else if (!fallback.hidden && !camFailed) {
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
      showEmpty('Timbre no asignado a una oficina.');
      return;
    }
    if (L.roomMismatch(ROOM_ID, s)) {
      snapshot = null;
      showEmpty('La oficina solicitada no está disponible.');
      setNet('Oficina no disponible', 'bad');
      return;
    }

    snapshot = s;
    lastStateAt = Date.now();
    setNet('En línea', 'ok');

    if (s.room.code && roomSelect) {
      var options = roomSelect.options || [];
      for (var i = 0; i < options.length; i++) {
        if (String(options[i].value) === String(s.room.id)) { roomSelect.value = options[i].value; break; }
      }
    }

    applyPresence(s.live || {});
    applyDoor(s.live || {});
    applyCamera(s.camera || null);
    renderButton();

    if (s.last_movement_id !== undefined && s.last_movement_id !== lastMovementId) {
      lastMovementId = s.last_movement_id;
      loadOpenings();
    }
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
    showFallback(message || 'Sin oficina asignada.', false, false);
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
      toast('Cámara encendida');
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
    if (snapshot) applyCamera(snapshot.camera || null);
  }

  // ── apertura ──────────────────────────────────────────────────────────
  function openDoor() {
    if (!snapshot || !snapshot.room) {
      toast('No hay una oficina seleccionada', 'bad');
      return;
    }
    if (!L.canOpen(Date.now(), cooldownUntil - COOLDOWN_MS, inflight, COOLDOWN_MS)) return;

    inflight = true;
    pendingOpen = true;
    btnOpen.classList.remove('is-ok', 'is-error');
    setAction('Solicitando apertura…', 'busy');
    renderButton();

    request('/timbre-api/door/open', {
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
      loadOpenings();
      if (confirmTimer) clearTimeout(confirmTimer);
      confirmTimer = setTimeout(function () {
        if (!pendingOpen) return;
        pendingOpen = false;
        confirmTimer = null;
        setAction('Pedido enviado · sin confirmación del sensor', 'warn');
        toast('La puerta no confirmó la apertura', 'warn');
        renderButton();
      }, CONFIRM_TIMEOUT_MS);

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

  // ── aperturas ─────────────────────────────────────────────────────────
  function renderOpenings() {
    if (!openingsList) return;
    if (!openings.length) {
      openingsList.innerHTML = '<p class="openings-empty">Sin aperturas registradas.</p>';
      return;
    }
    var html = openings.map(function (o) {
      var badges = '';
      badges += '<span class="badge" data-tone="' + esc(L.openingStatusTone(o.status)) + '">'
        + esc(L.openingStatusLabel(o.status)) + '</span>';
      badges += '<span class="badge">' + esc(L.directionLabel(o.direction)) + '</span>';
      if (o.door_left_open && o.door_open_seconds != null) {
        badges += '<span class="badge" data-tone="warn">Puerta abierta ' + Math.round(o.door_open_seconds) + ' s</span>';
      }
      if (o.presence_before) {
        badges += '<span class="badge">Presencia previa</span>';
      }
      var saved = L.firstVideo(o.recordings);
      var recording = L.hasRecording(o.recordings);
      var videoLabel = saved ? 'Ver vídeo' : (recording ? 'Grabando…' : 'Sin vídeo');
      var disabled = saved ? '' : ' disabled';
      return '<div class="opening">'
        + '<span class="opening-type" data-type="' + esc(o.type) + '">' + esc(L.openingTypeLabel(o.type)) + '</span>'
        + '<div class="opening-main">'
        + '<strong>' + esc(o.owner || 'Sin llave') + '</strong>'
        + '<div class="opening-meta"><span>' + esc(L.openingTime(o.occurred_at)) + '</span></div>'
        + '<div class="opening-badges">' + badges + '</div>'
        + '</div>'
        + '<button class="opening-video" type="button" data-video="' + o.id + '"' + disabled + '>'
        + videoLabel + '</button>'
        + '</div>';
    }).join('');
    openingsList.innerHTML = html;
  }

  function loadOpenings() {
    if (!ROOM_ID) return Promise.resolve();
    return request('/timbre-api/openings?room_id=' + encodeURIComponent(ROOM_ID) + '&limit=50')
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        openings = (res.body && res.body.openings) || [];
        renderOpenings();
        return openings;
      })
      .catch(function () {
        if (!openings.length) {
          openingsList.innerHTML = '<p class="openings-empty">No se pudieron cargar las aperturas.</p>';
        }
      });
  }

  function startOpeningsRefresh() {
    if (openingsTimer) return;
    openingsTimer = setInterval(function () {
      if (!document.hidden) loadOpenings();
    }, OPENINGS_REFRESH_MS);
  }

  function openVideo(id) {
    var opening = null;
    for (var i = 0; i < openings.length; i++) {
      if (String(openings[i].id) === String(id)) { opening = openings[i]; break; }
    }
    if (!opening) return;
    var rec = L.firstVideo(opening.recordings);
    if (!rec) {
      openModal('Vídeo de la apertura', '<p class="video-empty">Esta apertura no tiene vídeo disponible.</p>');
      return;
    }
    var body = '<div class="video-box">'
      + '<video controls preload="metadata"' + (rec.poster_url ? ' poster="' + esc(rec.poster_url) + '"' : '') + ' src="' + esc(rec.video_url) + '"></video>'
      + '<p class="form-hint">' + esc(L.openingTypeLabel(opening.type)) + ' · '
      + esc(L.directionLabel(opening.direction)) + ' · ' + esc(L.openingTime(opening.occurred_at)) + '</p>'
      + '</div>';
    openModal('Vídeo de la apertura', body);
  }

  // ── modal ─────────────────────────────────────────────────────────────
  function openModal(title, bodyHtml) {
    modalFocus = document.activeElement;
    modalTitle.textContent = title;
    modalBody.innerHTML = bodyHtml;
    modalBackdrop.hidden = false;
    setTimeout(function () { if (modalClose) modalClose.focus(); }, 20);
  }

  function closeModal() {
    modalBackdrop.hidden = true;
    modalBody.innerHTML = '';
    if (modalFocus && modalFocus.focus) modalFocus.focus();
    modalFocus = null;
  }

  function toLocalInput(date) {
    function pad(n) { return String(n).padStart(2, '0'); }
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
      + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  function openKeyModal() {
    if (!snapshot || !snapshot.room) {
      toast('No hay una oficina seleccionada', 'bad');
      return;
    }
    var defaultValid = toLocalInput(new Date(Date.now() + 30 * 86400000));
    var loading = '<p class="form-hint">Cargando empleados y roles…</p>';
    var form = ''
      + '<div class="form-row"><label for="key-owner-type">Titular</label>'
      + '<select id="key-owner-type">'
      + '<option value="WORKER">Empleado</option>'
      + '<option value="ROLE">Rol</option>'
      + '<option value="FREE">Nombre libre</option>'
      + '</select></div>'
      + '<div class="form-row" id="row-worker"><label for="key-worker">Empleado</label><select id="key-worker"><option value="">Elige empleado</option></select></div>'
      + '<div class="form-row" id="row-role" hidden><label for="key-role">Rol</label><select id="key-role"><option value="">Elige rol</option></select></div>'
      + '<div class="form-row" id="row-free" hidden><label for="key-free">Nombre del titular</label><input id="key-free" type="text" maxlength="120" autocomplete="off"></div>'
      + '<div class="form-row"><label for="key-label">Nombre de la llave (opcional)</label><input id="key-label" type="text" maxlength="120" autocomplete="off"></div>'
      + '<div class="form-row"><label for="key-valid">Caducidad</label><input id="key-valid" type="datetime-local" value="' + esc(defaultValid) + '"></div>'
      + '<p class="form-error" id="key-error" hidden></p>'
      + '<div class="modal-actions"><button class="btn-secondary" type="button" data-close="1">Cancelar</button>'
      + '<button class="btn-primary" type="button" id="key-submit">Crear llave QR</button></div>'
      + '<div id="key-resource-note">' + loading + '</div>';
    openModal('Crear llave QR', form);

    var typeEl = $('key-owner-type');
    var workerEl = $('key-worker');
    var roleEl = $('key-role');
    var freeEl = $('key-free');
    var labelEl = $('key-label');
    var validEl = $('key-valid');
    var errorEl = $('key-error');
    var submit = $('key-submit');

    function syncRows() {
      var t = typeEl.value;
      $('row-worker').hidden = t !== 'WORKER';
      $('row-role').hidden = t !== 'ROLE';
      $('row-free').hidden = t !== 'FREE';
    }
    typeEl.addEventListener('change', syncRows);
    syncRows();

    var roomTypeId = (snapshot.room && snapshot.room.room_type_id) || 0;
    if (roomTypeId) {
      request('/almacen-api/access?room_type_id=' + encodeURIComponent(roomTypeId))
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          var body = res.body || {};
          var workers = (body.workers || []).filter(function (w) { return w.effective === true; });
          var roles = (body.roles || []).filter(function (r) { return r.allowed === true; });
          workerEl.innerHTML = '<option value="">Elige empleado</option>' + workers.map(function (w) {
            return '<option value="' + w.id + '">' + esc(w.name) + '</option>';
          }).join('');
          roleEl.innerHTML = '<option value="">Elige rol</option>' + roles.map(function (r) {
            return '<option value="' + r.id + '">' + esc(r.name) + '</option>';
          }).join('');
          $('key-resource-note').innerHTML = '<p class="form-hint">Empleados y roles con acceso a esta oficina.</p>';
        })
        .catch(function () {
          $('key-resource-note').innerHTML = '<p class="form-hint">No se pudo cargar la lista; puedes usar “Nombre libre”.</p>';
        });
    }

    submit.addEventListener('click', function () {
      errorEl.hidden = true;
      var built = L.buildKeyPayload({
        roomId: snapshot.room.id,
        ownerType: typeEl.value,
        workerId: workerEl.value,
        roleId: roleEl.value,
        ownerName: freeEl.value,
        label: labelEl.value,
        validUntilLocal: validEl.value,
      });
      if (!built.ok) {
        errorEl.textContent = built.error;
        errorEl.hidden = false;
        return;
      }
      submit.disabled = true;
      request('/timbre-api/keys', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(built.payload),
      }).then(function (res) {
        submit.disabled = false;
        if (!res.ok) {
          var msg = (res.body && res.body.message) || (res.body && res.body.error) || ('HTTP ' + res.status);
          throw new Error(msg);
        }
        var created = (res.body && res.body.data) || {};
        showQr(created);
      }).catch(function (err) {
        submit.disabled = false;
        errorEl.textContent = err.message;
        errorEl.hidden = false;
      });
    });
  }

  function showQr(created) {
    if (!created || !created.qr_token) {
      toast('No se pudo generar el QR', 'bad');
      return;
    }
    var label = created.label || 'Llave QR';
    var html = '<div class="qr-box" id="qr-box"></div>'
      + '<p class="form-hint"><strong>' + esc(label) + '</strong><br>Este QR se muestra una sola vez. Descárgalo ahora.</p>'
      + '<div class="modal-actions"><button class="btn-secondary" type="button" data-close="1">Cerrar</button>'
      + '<button class="btn-primary" type="button" id="qr-download">Descargar PNG</button></div>';
    openModal('Llave QR creada', html);
    var box = $('qr-box');
    if (!window.QRCode) {
      box.innerHTML = '<p class="form-error">No se pudo cargar el generador de QR.</p>';
      return;
    }
    new window.QRCode(box, {
      text: created.qr_token,
      width: 280,
      height: 280,
      correctLevel: window.QRCode.CorrectLevel.M,
    });
    var download = $('qr-download');
    if (download) {
      download.addEventListener('click', function () {
        var canvas = box.querySelector('canvas');
        var image = box.querySelector('img');
        var link = document.createElement('a');
        link.download = L.qrDownloadName(label);
        if (canvas) link.href = canvas.toDataURL('image/png');
        else if (image) link.href = image.src;
        else { toast('El QR aún no está listo', 'warn'); return; }
        document.body.appendChild(link);
        link.click();
        link.remove();
      });
    }
  }

  // ── datos / conexión ──────────────────────────────────────────────────
  function pollState() {
    if (!ROOM_ID) return Promise.resolve();
    return request('/timbre-api/state?room_id=' + encodeURIComponent(ROOM_ID))
      .then(function (res) {
        if (res.status === 404) { setNet('Oficina no disponible', 'bad'); return; }
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
    var url = '/timbre-api/event-stream?room_id=' + encodeURIComponent(ROOM_ID);
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
      opt.textContent = 'Sin oficinas';
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
      if (!res.ok || !Array.isArray(res.body)) throw new Error('No se pudo cargar la lista de oficinas');
      rooms = L.filterWarehouses(res.body);
      renderRoomOptions(rooms);
      var requested = parseInt((new URLSearchParams(location.search)).get('room') || '0', 10) || 0;
      var selected = L.pickDefaultRoom(rooms, requested, 'oficina');
      if (!selected) {
        showEmpty('No hay oficinas configuradas en el proyecto.');
        setNet('Sin oficina', 'bad');
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
      showEmpty('No se pudo cargar la lista de oficinas.');
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
    openings = [];
    lastMovementId = null;
    if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
    if (cooldownTimer) { clearTimeout(cooldownTimer); cooldownTimer = null; }
    cooldownUntil = 0;
    if (sseRetryTimer) { clearTimeout(sseRetryTimer); sseRetryTimer = null; }
    stopPolling();
    closeSse();
    showEmpty('Conectando con la oficina…');
    pollState();
    connectSse();
    loadOpenings();
  }

  // ── eventos ───────────────────────────────────────────────────────────
  btnEnable.addEventListener('click', enableCamera);
  btnRetry.addEventListener('click', retryCamera);
  btnOpen.addEventListener('click', openDoor);
  roomSelect.addEventListener('change', function () { switchRoom(this.value); });
  btnCreateKey.addEventListener('click', openKeyModal);

  openingsList.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('[data-video]') : null;
    if (btn && !btn.disabled) openVideo(btn.getAttribute('data-video'));
  });

  modalClose.addEventListener('click', closeModal);
  modalBackdrop.addEventListener('click', function (ev) {
    if (ev.target === modalBackdrop) closeModal();
  });
  modalBody.addEventListener('click', function (ev) {
    var close = ev.target && ev.target.closest ? ev.target.closest('[data-close]') : null;
    if (close) closeModal();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modalBackdrop.hidden) closeModal();
  });

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
    showFallback('Sin señal de la cámara.', false, true);
    stage.classList.add('is-nosignal');
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
    } else {
      pollState();
      loadOpenings();
      if (!sse) connectSse();
    }
  });

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
  showEmpty('Conectando con la oficina…');
  startOpeningsRefresh();
  loadRooms().then(function (selected) {
    if (!selected) return;
    pollState();
    connectSse();
    loadOpenings();
  });
})();
