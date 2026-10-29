/* F98 — Panel de gestión de llaves. Vanilla JS, no build step. */
(function () {
  'use strict';

  // -------------------------------------------------------------------------
  // Icons (static, decorative; every action also has text)
  // -------------------------------------------------------------------------

  var ICONS = {
    key: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="4"></circle><path d="m11 11 9 9"></path><path d="m17 17 2-2"></path><path d="m20 20 2-2"></path></svg>',
    user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>',
    users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.4"></circle><path d="M2.5 20a6.5 6.5 0 0 1 13 0"></path><path d="M16 5.2a3.4 3.4 0 0 1 0 6.4"></path><path d="M18 14.7a6.5 6.5 0 0 1 3.5 5.3"></path></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.4-3.4"></path></svg>',
    refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"></path><path d="M3 21v-5h5"></path><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"></path><path d="M21 3v5h-5"></path></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12"></path><path d="M18 6 6 18"></path></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6"></path><path d="M12 17h.01"></path></svg>',
    info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path></svg>',
    qr: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3h-3z"></path><path d="M20 14h1"></path><path d="M14 20h1"></path><path d="M18 18h3v3h-3z"></path></svg>',
    card: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.5"></rect><path d="M6 10h4"></path><path d="M6 14h7"></path><circle cx="17" cy="12" r="1.4"></circle></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4l11-11a2.8 2.8 0 0 0-4-4L4 16v4Z"></path><path d="m13.5 6.5 4 4"></path></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"></path><path d="M9 7V4h6v3"></path><path d="M6 7l1 13h10l1-13"></path><path d="M10 11v5"></path><path d="M14 11v5"></path></svg>',
    arrowLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="m11 18-6-6 6-6"></path></svg>',
    arrowRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M4 21h16"></path></svg>',
    lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2.5"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path><path d="M12 14v3"></path></svg>'
  };

  // -------------------------------------------------------------------------
  // Utilities
  // -------------------------------------------------------------------------

  var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ESC_MAP[ch];
    });
  }

  function icon(name) {
    return ICONS[name] || '';
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function selectedAttr(value, current) {
    return String(value) === String(current) ? ' selected' : '';
  }

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(null, args); }, wait);
    };
  }

  // The API stores valid_until in UTC as "YYYY-MM-DD HH:MM:SS".
  function parseUtcDate(value) {
    if (!value) return null;
    var raw = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(raw)) {
      raw = raw.replace(' ', 'T') + 'Z';
    } else if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(raw)) {
      raw += 'Z';
    } else {
      raw = raw.replace(' ', 'T');
    }
    var date = new Date(raw);
    return isNaN(date.getTime()) ? null : date;
  }

  function toLocalInput(date) {
    if (!date) return '';
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
      'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  function toUtcString(localValue) {
    var date = new Date(localValue);
    if (isNaN(date.getTime())) return '';
    return date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate()) +
      ' ' + pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes()) + ':' + pad(date.getUTCSeconds());
  }

  function formatLocal(value) {
    var date = parseUtcDate(value);
    if (!date) return 'Sin fecha';
    try {
      return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric', month: 'long', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      }).format(date);
    } catch (err) {
      return date.toLocaleString();
    }
  }

  function nowLocalInput() {
    return toLocalInput(new Date());
  }

  function suggestedLocalInput() {
    var date = new Date(Date.now() + 30 * 86400000);
    date.setSeconds(0, 0);
    return toLocalInput(date);
  }

  // -------------------------------------------------------------------------
  // API
  // -------------------------------------------------------------------------

  function ApiError(message, status, code, body) {
    this.name = 'ApiError';
    this.message = message;
    this.status = status;
    this.code = code;
    this.body = body;
  }
  ApiError.prototype = Object.create(Error.prototype);

  async function api(path, options) {
    var opts = Object.assign({ credentials: 'same-origin' }, options || {});
    var response;
    try {
      response = await fetch(path, opts);
    } catch (err) {
      throw new ApiError('No se pudo conectar. Revisa la conexión e inténtalo de nuevo.', 0, 'network', null);
    }

    var body = null;
    try {
      body = await response.json();
    } catch (err) {
      body = null;
    }

    if (response.status === 401) {
      if (window.location.pathname !== '/llaves/login') {
        window.location.replace('/llaves/login');
      }
      throw new ApiError('Tu sesión ha caducado. Vuelve a entrar.', 401, 'unauthorized', body);
    }

    if (!response.ok) {
      var message = (body && body.error && body.error.message) ||
        (body && body.message) ||
        'No se pudo completar la acción. Inténtalo de nuevo.';
      var code = (body && body.error && body.error.code) || (body && body.code) || '';
      throw new ApiError(message, response.status, code, body);
    }

    return body || {};
  }

  function jsonOptions(method, payload) {
    return {
      method: method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    };
  }

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  var state = {
    user: null,
    tab: 'keys',
    keys: [],
    keysSeq: 0,
    workers: [],
    roles: [],
    roomTypes: [],
    metaLoaded: false,
    metaError: '',
    keyFilters: { q: '', status: '', key_type: '' },
    empFilters: { q: '', active: '1' }
  };

  var metaPromise = null;
  var toastTimer = null;
  var modalSeq = 0;
  var modalLayers = [];
  var wizard = null;
  var wizardLayer = null;
  var wizardClose = null;

  var tabKeys = document.getElementById('tab-keys');
  var tabEmployees = document.getElementById('tab-employees');
  var viewKeys = document.getElementById('view-keys');
  var viewEmployees = document.getElementById('view-employees');
  var modalRoot = document.getElementById('modal-root');
  var toastEl = document.getElementById('toast');

  // -------------------------------------------------------------------------
  // Toast + small UI helpers
  // -------------------------------------------------------------------------

  function showToast(message, type) {
    if (!toastEl) return;
    clearTimeout(toastTimer);
    toastEl.textContent = message;
    toastEl.className = 'toast show ' + (type === 'error' ? 'toast--error' : 'toast--ok');
    toastEl.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    toastTimer = setTimeout(function () {
      toastEl.classList.remove('show');
    }, type === 'error' ? 6500 : 4200);
  }

  function showInlineError(container, message) {
    if (!container) return;
    container.innerHTML = icon('alert') + '<span>' + esc(message) + '</span>';
    container.hidden = false;
  }

  function hideInlineError(container) {
    if (!container) return;
    container.hidden = true;
    container.textContent = '';
  }

  function setFieldError(input, errorEl, message) {
    if (input) {
      input.setAttribute('aria-invalid', 'true');
      if (errorEl && errorEl.id) input.setAttribute('aria-describedby', errorEl.id);
    }
    if (errorEl) {
      errorEl.innerHTML = icon('alert') + '<span>' + esc(message) + '</span>';
      errorEl.hidden = false;
    }
  }

  function clearFieldError(input, errorEl) {
    if (input) {
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
    }
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
  }

  function focusById(id) {
    var el = document.getElementById(id);
    if (el) el.focus();
  }

  // -------------------------------------------------------------------------
  // Modals (stacked, focus-trapped, Escape closes)
  // -------------------------------------------------------------------------

  function openModal(options) {
    var opts = options || {};
    var id = ++modalSeq;
    var titleId = 'modal-title-' + id;
    var previousFocus = document.activeElement;

    var layer = document.createElement('div');
    layer.className = 'modal-layer';
    layer.innerHTML =
      '<div class="modal-backdrop" data-modal-close="1"></div>' +
      '<section class="modal-card ' + (opts.wide ? 'modal-card--wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="' + titleId + '">' +
        '<div class="modal-head">' +
          '<div>' +
            '<h2 class="modal-title" id="' + titleId + '">' + esc(opts.title || '') + '</h2>' +
            (opts.subtitle ? '<p class="modal-subtitle">' + esc(opts.subtitle) + '</p>' : '') +
          '</div>' +
          '<button class="modal-close" type="button" data-modal-close="1" aria-label="Cerrar">' + icon('close') + '</button>' +
        '</div>' +
        '<div class="modal-body" id="modal-body-' + id + '">' + (opts.body || '') + '</div>' +
        '<div class="modal-footer" id="modal-footer-' + id + '">' + (opts.footer || '') + '</div>' +
      '</section>';

    var card = layer.querySelector('.modal-card');
    var closed = false;

    function close() {
      if (closed) return;
      closed = true;
      layer.remove();
      modalLayers = modalLayers.filter(function (entry) { return entry.layer !== layer; });
      if (!modalLayers.length) {
        document.body.classList.remove('modal-open');
      }
      if (previousFocus && document.contains(previousFocus)) {
        previousFocus.focus();
      } else if (modalLayers.length) {
        focusFirst(modalLayers[modalLayers.length - 1].layer);
      }
      if (typeof opts.onClose === 'function') opts.onClose();
    }

    // Delegated so dynamically replaced footers keep working.
    layer.addEventListener('click', function (event) {
      if (event.target.closest('[data-modal-close]')) close();
    });

    layer.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      var focusables = getFocusable(layer);
      if (!focusables.length) return;
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    modalRoot.appendChild(layer);
    modalLayers.push({ layer: layer, close: close });
    document.body.classList.add('modal-open');

    requestAnimationFrame(function () {
      var target = opts.autofocus ? layer.querySelector(opts.autofocus) : null;
      if (!target) target = layer.querySelector('[data-autofocus]');
      if (!target) {
        var focusables = getFocusable(layer);
        target = focusables.length ? focusables[0] : card;
      }
      if (target) target.focus();
    });

    if (typeof opts.onMount === 'function') opts.onMount(layer, close);

    return { layer: layer, close: close };
  }

  function getFocusable(container) {
    return Array.prototype.slice.call(container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function focusFirst(layer) {
    var focusables = getFocusable(layer);
    if (focusables.length) focusables[0].focus();
  }

  function closeAllModals() {
    var open = modalLayers.slice();
    for (var i = open.length - 1; i >= 0; i--) {
      open[i].close();
    }
  }

  function confirmDialog(options) {
    var opts = options || {};
    return new Promise(function (resolve) {
      var confirmed = false;
      openModal({
        title: opts.title || 'Confirmar',
        body: '<p class="lead">' + esc(opts.message || '') + '</p>',
        footer:
          '<button class="btn btn-secondary" type="button" data-cancel="1">Cancelar</button>' +
          '<button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" type="button" data-confirm="1">' +
            esc(opts.confirmText || 'Confirmar') +
          '</button>',
        autofocus: '[data-cancel]',
        onMount: function (layer, close) {
          layer.querySelector('[data-cancel]').addEventListener('click', function () { close(); });
          layer.querySelector('[data-confirm]').addEventListener('click', function () {
            confirmed = true;
            close();
            resolve(true);
          });
        },
        onClose: function () {
          if (!confirmed) resolve(false);
        }
      });
    });
  }

  // -------------------------------------------------------------------------
  // Metadata + session
  // -------------------------------------------------------------------------

  async function ensureMeta(force) {
    if (state.metaLoaded && !force) return state;
    if (metaPromise) return metaPromise;

    metaPromise = (async function () {
      var results = await Promise.all([
        api('/api/v1/workers'),
        api('/api/v1/worker-roles'),
        api('/api/v1/room-types')
      ]);
      state.workers = results[0].data || results[0].items || [];
      state.roles = results[1].data || results[1].items || [];
      state.roomTypes = results[2].data || results[2].items || [];
      state.metaLoaded = true;
      state.metaError = '';
      return state;
    })();

    try {
      return await metaPromise;
    } catch (err) {
      state.metaError = err.message || 'No se pudieron cargar los datos.';
      throw err;
    } finally {
      metaPromise = null;
    }
  }

  async function refreshWorkers() {
    var response = await api('/api/v1/workers');
    state.workers = response.data || response.items || [];
  }

  function renderUser() {
    var chip = document.getElementById('user-chip');
    var name = document.getElementById('user-name');
    if (!chip || !name) return;
    if (state.user) {
      name.textContent = state.user.display_name || state.user.username || '';
      chip.hidden = false;
    } else {
      chip.hidden = true;
    }
  }

  function workerName(id) {
    var worker = state.workers.find(function (w) { return Number(w.id) === Number(id); });
    return worker ? worker.name : '';
  }

  function roleName(id) {
    var role = state.roles.find(function (r) { return Number(r.id) === Number(id); });
    return role ? role.name : '';
  }

  function roomTypeName(id) {
    var roomType = state.roomTypes.find(function (t) { return Number(t.id) === Number(id); });
    return roomType ? roomType.name : ('Tipo de sala ' + id);
  }

  function roomTypeNames(ids) {
    if (!Array.isArray(ids) || !ids.length) return '';
    return ids.map(roomTypeName).join(', ');
  }

  // -------------------------------------------------------------------------
  // Tabs
  // -------------------------------------------------------------------------

  function setTab(tab) {
    state.tab = tab;
    var keysActive = tab === 'keys';
    tabKeys.setAttribute('aria-selected', keysActive ? 'true' : 'false');
    tabEmployees.setAttribute('aria-selected', keysActive ? 'false' : 'true');
    tabKeys.setAttribute('tabindex', keysActive ? '0' : '-1');
    tabEmployees.setAttribute('tabindex', keysActive ? '-1' : '0');
    viewKeys.hidden = !keysActive;
    viewEmployees.hidden = keysActive;
    if (keysActive) {
      renderKeysPage();
    } else {
      renderEmployeesPage();
    }
  }

  function handleTabKeys(event, index) {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft' && event.key !== 'Home' && event.key !== 'End') return;
    event.preventDefault();
    var tabs = [tabKeys, tabEmployees];
    var next = index;
    if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    setTab(next === 0 ? 'keys' : 'employees');
    tabs[next].focus();
  }

  // -------------------------------------------------------------------------
  // Keys: listing
  // -------------------------------------------------------------------------

  function keyStatus(key) {
    if (key.status === 'REVOKED') return 'revoked';
    if (key.effective_status === 'EXPIRED' || key.status === 'EXPIRED') return 'expired';
    return 'active';
  }

  function isExpiredKey(key) {
    if (key.effective_status === 'EXPIRED' || key.status === 'EXPIRED') return true;
    var until = parseUtcDate(key.valid_until);
    return until ? until.getTime() <= Date.now() : false;
  }

  function statusBadge(kind) {
    var meta = {
      active: { className: 'status--active', label: 'Activa' },
      expired: { className: 'status--expired', label: 'Caducada' },
      revoked: { className: 'status--revoked', label: 'Desactivada' }
    }[kind] || { className: 'status--revoked', label: 'Desactivada' };
    return '<span class="status ' + meta.className + '">' + meta.label + '</span>';
  }

  function keyOwner(key) {
    if (key.owner_type === 'WORKER') {
      return key.worker_name || workerName(key.worker_id) || 'Empleado';
    }
    return 'Compartida: ' + (key.role_name || roleName(key.role_id) || 'Puesto');
  }

  function keyOwnerPhrase(key) {
    if (key.owner_type === 'WORKER') {
      return key.worker_name || workerName(key.worker_id) || 'este empleado';
    }
    return 'el puesto ' + (key.role_name || roleName(key.role_id) || 'compartido');
  }

  function keyPermissions(key) {
    if (key.all_room_types) return 'Todas las salas';
    var names = roomTypeNames(key.room_type_ids);
    return names || 'Ninguna sala';
  }

  function keyTypeLabel(key) {
    if (key.key_type === 'NFC') {
      return 'Ficha física' + (key.nfc_card_number ? ' · ' + key.nfc_card_number : '');
    }
    return 'Código QR';
  }

  function keyCard(key) {
    var kind = keyStatus(key);
    var canRevoke = kind === 'active';
    var canReactivate = kind === 'revoked' && !isExpiredKey(key);
    return '' +
      '<article class="key-card">' +
        '<div class="card-top">' +
          '<div class="card-headline">' +
            '<span class="card-icon">' + (key.key_type === 'NFC' ? icon('card') : icon('qr')) + '</span>' +
            '<div>' +
              '<h3 class="card-title">' + esc(key.label) + '</h3>' +
              '<p class="card-owner">' + esc(keyOwner(key)) + '</p>' +
            '</div>' +
          '</div>' +
          statusBadge(kind) +
        '</div>' +
        '<dl class="meta-list">' +
          '<div><dt>Tipo</dt><dd>' + esc(keyTypeLabel(key)) + '</dd></div>' +
          '<div><dt>Caduca</dt><dd>' + esc(formatLocal(key.valid_until)) + '</dd></div>' +
          '<div class="span-2"><dt>Puede abrir</dt><dd>' + esc(keyPermissions(key)) + '</dd></div>' +
        '</dl>' +
        '<div class="card-actions">' +
          '<button class="btn btn-secondary" type="button" data-action="key-detail" data-id="' + key.id + '">' +
            icon('edit') + ' Ver / Editar' +
          '</button>' +
          (canRevoke
            ? '<button class="btn btn-danger" type="button" data-action="key-revoke" data-id="' + key.id + '">' +
                icon('lock') + ' Desactivar' +
              '</button>'
            : '') +
          (canReactivate
            ? '<button class="btn btn-secondary" type="button" data-action="key-reactivate" data-id="' + key.id + '">' +
                icon('refresh') + ' Reactivar' +
              '</button>'
            : '') +
        '</div>' +
      '</article>';
  }

  function renderKeysPage() {
    viewKeys.innerHTML =
      '<header class="page-head">' +
        '<div>' +
          '<h1>Llaves</h1>' +
          '<p>Crea llaves QR o entrega fichas físicas. Cada llave indica qué puede abrir y hasta cuándo.</p>' +
        '</div>' +
        '<button class="primary-action" type="button" data-action="create-key">' + icon('plus') + ' Crear llave</button>' +
      '</header>' +
      '<div class="toolbar">' +
        '<div class="field search-field">' +
          '<label for="key-search">Buscar</label>' +
          '<div class="search-wrap">' + icon('search') +
            '<input id="key-search" type="search" placeholder="Etiqueta, empleado o puesto" autocomplete="off" value="' + esc(state.keyFilters.q) + '">' +
          '</div>' +
        '</div>' +
        '<button class="btn btn-secondary refresh-btn" type="button" data-action="refresh-keys">' + icon('refresh') + ' Actualizar</button>' +
      '</div>' +
      '<div class="filters">' +
        '<div class="field field--tight">' +
          '<label for="key-status">Estado</label>' +
          '<select id="key-status">' +
            '<option value="">Todas</option>' +
            '<option value="ACTIVE"' + selectedAttr('ACTIVE', state.keyFilters.status) + '>Activas</option>' +
            '<option value="EXPIRED"' + selectedAttr('EXPIRED', state.keyFilters.status) + '>Caducadas</option>' +
            '<option value="REVOKED"' + selectedAttr('REVOKED', state.keyFilters.status) + '>Desactivadas</option>' +
          '</select>' +
        '</div>' +
        '<div class="field field--tight">' +
          '<label for="key-type">Tipo</label>' +
          '<select id="key-type">' +
            '<option value="">Todas</option>' +
            '<option value="QR"' + selectedAttr('QR', state.keyFilters.key_type) + '>QR</option>' +
            '<option value="NFC"' + selectedAttr('NFC', state.keyFilters.key_type) + '>Ficha</option>' +
          '</select>' +
        '</div>' +
      '</div>' +
      '<div id="keys-results" aria-live="polite"></div>';

    var search = document.getElementById('key-search');
    var status = document.getElementById('key-status');
    var type = document.getElementById('key-type');

    if (search) {
      search.addEventListener('input', debounce(function () {
        state.keyFilters.q = search.value.trim();
        loadKeys();
      }, 260));
    }
    if (status) {
      status.addEventListener('change', function () {
        state.keyFilters.status = status.value;
        loadKeys();
      });
    }
    if (type) {
      type.addEventListener('change', function () {
        state.keyFilters.key_type = type.value;
        loadKeys();
      });
    }

    loadKeys();
  }

  async function loadKeys() {
    var seq = ++state.keysSeq;
    var results = document.getElementById('keys-results');
    if (!results) return;

    results.innerHTML =
      '<div class="loading-grid" aria-hidden="true">' +
        '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>' +
      '</div>' +
      '<p class="visually-hidden">Cargando llaves…</p>';

    var params = new URLSearchParams();
    if (state.keyFilters.q) params.set('q', state.keyFilters.q);
    if (state.keyFilters.status) params.set('status', state.keyFilters.status);
    if (state.keyFilters.key_type) params.set('key_type', state.keyFilters.key_type);
    var suffix = params.toString() ? '?' + params.toString() : '';

    try {
      var response = await api('/api/v1/keys' + suffix);
      if (seq !== state.keysSeq) return;
      state.keys = response.data || [];
      renderKeyResults();
    } catch (err) {
      if (seq !== state.keysSeq) return;
      state.keys = [];
      results.innerHTML =
        '<div class="inline-alert" role="alert">' + icon('alert') + '<span>' + esc(err.message) + '</span></div>' +
        '<button class="btn btn-secondary" type="button" data-action="retry-keys">' + icon('refresh') + ' Reintentar</button>';
    }
  }

  function renderKeyResults() {
    var results = document.getElementById('keys-results');
    if (!results) return;

    if (!state.keys.length) {
      var filtered = state.keyFilters.q || state.keyFilters.status || state.keyFilters.key_type;
      results.innerHTML =
        '<div class="empty-state">' +
          '<div class="empty-icon">' + icon('key') + '</div>' +
          '<h2>' + (filtered ? 'No hay llaves con estos filtros' : 'Todavía no hay llaves') + '</h2>' +
          '<p>' + (filtered
            ? 'Prueba a quitar el buscador o los filtros para ver todas las llaves.'
            : 'Crea la primera llave para un empleado o para un puesto compartido.') + '</p>' +
          (filtered
            ? '<button class="btn btn-secondary" type="button" data-action="clear-key-filters">Quitar filtros</button>'
            : '<button class="primary-action" type="button" data-action="create-key">' + icon('plus') + ' Crear llave</button>') +
        '</div>';
      return;
    }

    results.innerHTML =
      '<p class="result-summary"><strong>' + state.keys.length + '</strong> ' +
        (state.keys.length === 1 ? 'llave' : 'llaves') + '</p>' +
      '<div class="card-grid">' + state.keys.map(keyCard).join('') + '</div>';
  }

  // -------------------------------------------------------------------------
  // Keys: detail, edit, revoke, QR
  // -------------------------------------------------------------------------

  function detailHtml(key) {
    var kind = keyStatus(key);
    var note = key.key_type === 'QR'
      ? '<div class="note-box">' + icon('info') +
          '<span>El QR se muestra al crearlo. Si lo pierdes, puedes generar uno nuevo.</span></div>'
      : '<div class="note-box">' + icon('card') +
          '<span>Entrega la ficha física <strong>' + esc(key.nfc_card_number || '—') + '</strong> al titular.</span></div>';

    return '' +
      '<dl class="detail-grid">' +
        '<div><dt>Titular</dt><dd>' + esc(keyOwner(key)) + '</dd></div>' +
        '<div><dt>Estado</dt><dd>' + statusBadge(kind) + '</dd></div>' +
        '<div><dt>Tipo</dt><dd>' + esc(keyTypeLabel(key)) + '</dd></div>' +
        '<div><dt>Caduca</dt><dd>' + esc(formatLocal(key.valid_until)) + '</dd></div>' +
        '<div class="span-2"><dt>Puede abrir</dt><dd>' + esc(keyPermissions(key)) + '</dd></div>' +
        (key.notes ? '<div class="span-2"><dt>Notas</dt><dd>' + esc(key.notes) + '</dd></div>' : '') +
      '</dl>' +
      note;
  }

  function detailFooter(key) {
    var kind = keyStatus(key);
    var canEdit = kind !== 'revoked';
    var canQr = key.key_type === 'QR' && kind === 'active';
    var canRevoke = kind === 'active';
    var canReactivate = kind === 'revoked' && !isExpiredKey(key);
    return '' +
      '<button class="btn btn-secondary" type="button" data-modal-close="1">Cerrar</button>' +
      (canEdit ? '<button class="btn btn-secondary" type="button" data-modal-action="detail-edit">' + icon('edit') + ' Editar</button>' : '') +
      (canQr ? '<button class="btn btn-primary" type="button" data-modal-action="detail-qr">' + icon('qr') + ' Generar QR nuevo</button>' : '') +
      (canReactivate ? '<button class="btn btn-secondary" type="button" data-modal-action="detail-reactivate">' + icon('refresh') + ' Reactivar</button>' : '') +
      (canRevoke ? '<button class="btn btn-danger" type="button" data-modal-action="detail-revoke">' + icon('lock') + ' Desactivar</button>' : '');
  }

  async function openKeyDetail(id) {
    var modal = openModal({
      title: 'Detalle de la llave',
      body: '<p class="lead">Cargando…</p>',
      footer: '<button class="btn btn-secondary" type="button" data-modal-close="1">Cerrar</button>'
    });

    var layer = modal.layer;
    var key = null;

    layer.addEventListener('click', function (event) {
      var button = event.target.closest('[data-modal-action]');
      if (!button || !key) return;
      var action = button.getAttribute('data-modal-action');
      if (action === 'detail-edit') {
        openEditKey(key.id);
      } else if (action === 'detail-qr') {
        regenerateQr(key);
      } else if (action === 'detail-revoke') {
        revokeKey(key);
      } else if (action === 'detail-reactivate') {
        reactivateKey(key);
      }
    });

    try {
      var response = await api('/api/v1/keys/' + id);
      key = response.data || response;
      var title = layer.querySelector('.modal-title');
      if (title) title.textContent = key.label;
      layer.querySelector('.modal-body').innerHTML = detailHtml(key);
      layer.querySelector('.modal-footer').innerHTML = detailFooter(key);
    } catch (err) {
      layer.querySelector('.modal-body').innerHTML =
        '<div class="inline-alert" role="alert">' + icon('alert') + '<span>' + esc(err.message) + '</span></div>';
    }
  }

  function nfcOptionsHtml(available, currentId, currentNumber) {
    var html = '<option value="">Elige una ficha</option>';
    if (currentId) {
      html += '<option value="' + esc(currentId) + '" selected>Ficha actual: ' + esc(currentNumber || ('#' + currentId)) + '</option>';
    }
    (available || []).forEach(function (card) {
      if (currentId && Number(card.id) === Number(currentId)) return;
      html += '<option value="' + esc(card.id) + '">' + esc(card.number) + '</option>';
    });
    return html;
  }

  async function openEditKey(id) {
    var modal = openModal({
      title: 'Editar llave',
      body: '<p class="lead">Cargando…</p>',
      footer: '<button class="btn btn-secondary" type="button" data-modal-close="1">Cerrar</button>'
    });
    var layer = modal.layer;
    var key = null;
    var available = [];

    try {
      var response = await api('/api/v1/keys/' + id);
      key = response.data || response;
      if (key.key_type === 'NFC') {
        try {
          var cards = await api('/api/v1/llaves/nfc-available');
          available = cards.data || [];
        } catch (err) {
          available = [];
        }
      }
      renderEditForm(layer, key, available);
    } catch (err) {
      layer.querySelector('.modal-body').innerHTML =
        '<div class="inline-alert" role="alert">' + icon('alert') + '<span>' + esc(err.message) + '</span></div>';
    }

    layer.addEventListener('click', function (event) {
      var button = event.target.closest('[data-modal-action]');
      if (!button) return;
      if (button.getAttribute('data-modal-action') === 'save-key-edit') {
        saveKeyEdit(layer, key);
      }
    });

    layer.addEventListener('change', function (event) {
      var target = event.target;
      if (target && target.id === 'edit-all-rooms') {
        var disabled = target.checked;
        layer.querySelectorAll('input[name="edit-room-type"]').forEach(function (input) {
          input.disabled = disabled;
          var tile = input.closest('.check-tile');
          if (tile) tile.classList.toggle('is-disabled', disabled);
        });
      }
    });
  }

  function renderEditForm(layer, key, available) {
    var title = layer.querySelector('.modal-title');
    if (title) title.textContent = 'Editar ' + key.label;

    var checkboxes = state.roomTypes.map(function (roomType) {
      var checked = (key.room_type_ids || []).map(Number).indexOf(Number(roomType.id)) !== -1;
      var disabled = !!key.all_room_types;
      return '<label class="check-tile' + (checked ? ' is-selected' : '') + (disabled ? ' is-disabled' : '') + '">' +
        '<input type="checkbox" name="edit-room-type" value="' + roomType.id + '"' +
          (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>' +
        '<span>' + esc(roomType.name) + '</span>' +
      '</label>';
    }).join('');

    var nfcField = '';
    if (key.key_type === 'NFC') {
      nfcField =
        '<div class="field">' +
          '<label for="edit-nfc">Ficha física</label>' +
          '<select id="edit-nfc">' + nfcOptionsHtml(available, key.nfc_card_id, key.nfc_card_number) + '</select>' +
          '<p class="hint">Solo aparecen fichas disponibles. La ficha actual se mantiene si no la cambias.</p>' +
        '</div>';
    }

    layer.querySelector('.modal-body').innerHTML =
      '<div id="edit-key-error" class="inline-alert" role="alert" hidden></div>' +
      '<div class="note-box" style="margin-bottom:18px">' + icon('info') +
        '<span>Para cambiar el titular o el tipo, crea una llave nueva.</span></div>' +
      '<dl class="detail-grid">' +
        '<div><dt>Titular</dt><dd>' + esc(keyOwner(key)) + '</dd></div>' +
        '<div><dt>Tipo</dt><dd>' + esc(keyTypeLabel(key)) + '</dd></div>' +
      '</dl>' +
      '<div class="form-stack">' +
        '<div class="field">' +
          '<label for="edit-label">Etiqueta</label>' +
          '<input id="edit-label" type="text" maxlength="120" value="' + esc(key.label) + '">' +
        '</div>' +
        '<div class="field">' +
          '<label for="edit-valid-until">Caduca el</label>' +
          '<input id="edit-valid-until" type="datetime-local" required value="' + esc(toLocalInput(parseUtcDate(key.valid_until))) + '" min="' + esc(nowLocalInput()) + '">' +
          '<p class="hint">La llave dejará de abrir en cuanto llegue esta fecha y hora.</p>' +
        '</div>' +
        nfcField +
        '<div class="field">' +
          '<label class="switch-row' + (key.all_room_types ? ' is-selected' : '') + '">' +
            '<input id="edit-all-rooms" type="checkbox"' + (key.all_room_types ? ' checked' : '') + '>' +
            '<span><strong>Todas las salas</strong><small>La llave abrirá cualquier puerta del hotel.</small></span>' +
          '</label>' +
        '</div>' +
        '<div class="field">' +
          '<span class="field-label">Tipos de sala</span>' +
          '<div class="check-grid">' + checkboxes + '</div>' +
        '</div>' +
      '</div>';

    layer.querySelector('.modal-footer').innerHTML =
      '<button class="btn btn-secondary" type="button" data-modal-close="1">Cancelar</button>' +
      '<button class="btn btn-primary" type="button" data-modal-action="save-key-edit">Guardar cambios</button>';
  }

  async function saveKeyEdit(layer, key) {
    if (!key) return;
    var errorBox = layer.querySelector('#edit-key-error');
    hideInlineError(errorBox);

    var labelInput = layer.querySelector('#edit-label');
    var dateInput = layer.querySelector('#edit-valid-until');
    var allInput = layer.querySelector('#edit-all-rooms');
    var label = (labelInput.value || '').trim();
    var dateValue = dateInput.value;
    var allRooms = allInput.checked;
    var roomIds = Array.prototype.slice.call(layer.querySelectorAll('input[name="edit-room-type"]:checked'))
      .map(function (input) { return Number(input.value); });

    if (!label) {
      showInlineError(errorBox, 'Escribe una etiqueta para reconocer la llave.');
      labelInput.focus();
      return;
    }
    if (!dateValue || new Date(dateValue).getTime() <= Date.now()) {
      showInlineError(errorBox, 'Elige una fecha y hora futuras.');
      dateInput.focus();
      return;
    }
    if (!allRooms && !roomIds.length) {
      showInlineError(errorBox, 'Marca al menos una sala o activa Todas las salas.');
      return;
    }

    var payload = {
      label: label,
      valid_until: toUtcString(dateValue),
      all_room_types: allRooms,
      room_type_ids: allRooms ? [] : roomIds
    };
    if (key.key_type === 'NFC') {
      var nfcSelect = layer.querySelector('#edit-nfc');
      if (!nfcSelect || !nfcSelect.value) {
        showInlineError(errorBox, 'Elige una ficha física.');
        if (nfcSelect) nfcSelect.focus();
        return;
      }
      payload.nfc_card_id = Number(nfcSelect.value);
    }

    var buttons = layer.querySelectorAll('.modal-footer .btn');
    buttons.forEach(function (button) { button.disabled = true; });
    try {
      await api('/api/v1/keys/' + key.id, jsonOptions('PATCH', payload));
      closeAllModals();
      await loadKeys();
      showToast('Llave actualizada.');
    } catch (err) {
      buttons.forEach(function (button) { button.disabled = false; });
      if (err.status === 409 && err.code === 'nfc_card_unavailable') {
        showInlineError(errorBox, 'Esa ficha ya no está disponible. Elige otra ficha.');
      } else {
        showInlineError(errorBox, err.message);
      }
    }
  }

  async function revokeKey(key) {
    var text = key.owner_type === 'WORKER'
      ? '¿Desactivar la llave de ' + keyOwnerPhrase(key) + '? Dejará de abrir puertas.'
      : '¿Desactivar la llave compartida de ' + (key.role_name || roleName(key.role_id) || 'este puesto') + '? Dejará de abrir puertas.';
    var ok = await confirmDialog({
      title: 'Desactivar llave',
      message: text,
      confirmText: 'Sí, desactivar',
      danger: true
    });
    if (!ok) return;

    try {
      await api('/api/v1/keys/' + key.id, { method: 'DELETE' });
      closeAllModals();
      await loadKeys();
      showToast('Llave desactivada.');
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async function reactivateKey(key) {
    var text = key.owner_type === 'WORKER'
      ? '¿Reactivar la llave de ' + keyOwnerPhrase(key) + '? Volverá a abrir puertas con la misma credencial.'
      : '¿Reactivar la llave compartida de ' + (key.role_name || roleName(key.role_id) || 'este puesto') + '? Volverá a abrir puertas con la misma credencial.';
    var ok = await confirmDialog({
      title: 'Reactivar llave',
      message: text,
      confirmText: 'Sí, reactivar'
    });
    if (!ok) return;

    try {
      await api('/api/v1/keys/' + key.id + '/reactivate', { method: 'POST' });
      closeAllModals();
      await loadKeys();
      showToast('Llave reactivada.');
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  async function regenerateQr(key) {
    var target = key.owner_type === 'WORKER'
      ? 'la llave de ' + keyOwnerPhrase(key)
      : 'la llave compartida de ' + (key.role_name || roleName(key.role_id) || 'este puesto');
    var ok = await confirmDialog({
      title: 'Generar un QR nuevo',
      message: '¿Generar un QR nuevo para ' + target + '? El código anterior dejará de funcionar inmediatamente.',
      confirmText: 'Sí, generar QR'
    });
    if (!ok) return;

    try {
      var response = await api('/api/v1/keys/' + key.id + '/qr', { method: 'POST' });
      var data = response.data || response;
      closeAllModals();
      await loadKeys();
      showQrModal(data.qr_token, data.label || key.label);
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  function qrDownloadName(label) {
    var base = String(label || 'llave-qr').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return (base || 'llave-qr') + '.png';
  }

  function showQrModal(token, label) {
    if (!token) {
      showToast('No se ha podido generar el QR.', 'error');
      return;
    }

    openModal({
      title: 'Guarda este código QR',
      body:
        '<div id="qr-box" class="qr-box" aria-label="Código QR de la llave"></div>' +
        '<div class="qr-caption"><strong>' + esc(label || 'Llave') + '</strong>' +
          '<span>Este es el único momento en el que se muestra. Descárgalo ahora.</span></div>' +
        '<div class="note-box" style="margin-top:18px">' + icon('alert') +
          '<span>No se podrá volver a ver. Si lo pierdes, genera un QR nuevo desde la llave.</span></div>',
      footer:
        '<button class="btn btn-secondary" type="button" data-modal-close="1">Cerrar</button>' +
        '<button class="btn btn-primary" type="button" data-download-qr="1">' + icon('download') + ' Descargar QR</button>',
      onMount: function (layer) {
        var box = layer.querySelector('#qr-box');
        setTimeout(function () {
          if (!box) return;
          if (!window.QRCode) {
            box.innerHTML = '<p class="field-error">' + icon('alert') + '<span>No se pudo cargar el generador de QR.</span></p>';
            return;
          }
          box.innerHTML = '';
          new window.QRCode(box, {
            text: token,
            width: 300,
            height: 300,
            correctLevel: window.QRCode.CorrectLevel.M
          });
        }, 30);

        var download = layer.querySelector('[data-download-qr]');
        if (download) {
          download.addEventListener('click', function () {
            var canvas = box ? box.querySelector('canvas') : null;
            var image = box ? box.querySelector('img') : null;
            var link = document.createElement('a');
            link.download = qrDownloadName(label);
            if (canvas) {
              link.href = canvas.toDataURL('image/png');
            } else if (image) {
              link.href = image.src;
            } else {
              showToast('El QR aún no está listo. Espera un momento.', 'error');
              return;
            }
            document.body.appendChild(link);
            link.click();
            link.remove();
          });
        }
      }
    });
  }

  function showFichaSuccess(key) {
    var number = key.nfc_card_number || (key.nfc_card_id ? '#' + key.nfc_card_id : '');
    openModal({
      title: 'Llave creada',
      body:
        '<p class="lead">Ya puedes entregar la ficha física a ' + esc(key.owner_type === 'WORKER' ? (key.worker_name || 'el empleado') : ('el puesto ' + (key.role_name || 'compartido'))) + '.</p>' +
        '<div class="note-box">' + icon('card') +
          '<span>Entrega la ficha <strong>' + esc(number) + '</strong>. Sin la ficha, la llave no puede usarse.</span></div>',
      footer: '<button class="btn btn-primary" type="button" data-modal-close="1">Entendido</button>',
      autofocus: '[data-modal-close]'
    });
  }

  // -------------------------------------------------------------------------
  // Wizard: create key
  // -------------------------------------------------------------------------

  function activeWorkers() {
    return state.workers.filter(function (worker) { return !!worker.active; });
  }

  function wizardOwnerKey() {
    if (!wizard) return '';
    if (wizard.ownerType === 'WORKER') {
      return wizard.workerId ? 'worker:' + wizard.workerId : '';
    }
    return wizard.roleId ? 'role:' + wizard.roleId : '';
  }

  function wizardOwnerName() {
    if (!wizard) return '';
    if (wizard.ownerType === 'WORKER') return workerName(wizard.workerId);
    return roleName(wizard.roleId);
  }

  function wizardDefaultLabel() {
    var name = wizardOwnerName();
    if (!name) return 'Llave nueva';
    return wizard.ownerType === 'WORKER' ? 'Llave de ' + name : 'Llave ' + name;
  }

  function wizardNfcNumber() {
    if (!wizard || !wizard.nfcCardId) return '';
    var card = (wizard.nfcAvailable || []).find(function (item) {
      return Number(item.id) === Number(wizard.nfcCardId);
    });
    return card ? card.number : ('#' + wizard.nfcCardId);
  }

  function wizardErrorHtml() {
    if (!wizard || !wizard.error) return '';
    return '<div class="inline-alert" role="alert" aria-live="assertive">' + icon('alert') +
      '<span>' + esc(wizard.error) + '</span></div>';
  }

  function wizardProgressHtml() {
    var step = wizard.step;
    var labels = ['Titular', 'Tipo', 'Permisos', 'Revisar'];
    var items = labels.map(function (label, index) {
      var number = index + 1;
      var cls = number < step ? 'done' : (number === step ? 'current' : '');
      return '<li class="' + cls + '"></li>';
    }).join('');
    return '<div class="progress-track" aria-hidden="true">' + items + '</div>' +
      '<p class="progress-label">Paso <strong>' + step + '</strong> de 4 · ' + esc(labels[step - 1]) + '</p>';
  }

  function wizardStepOneHtml() {
    var workers = activeWorkers();
    var workerOptions = '<option value="">Elige un empleado</option>' + workers.map(function (worker) {
      return '<option value="' + worker.id + '"' + selectedAttr(worker.id, wizard.workerId) + '>' + esc(worker.name) + '</option>';
    }).join('');
    var roleOptions = '<option value="">Elige un puesto</option>' + state.roles.map(function (role) {
      return '<option value="' + role.id + '"' + selectedAttr(role.id, wizard.roleId) + '>' + esc(role.name) + '</option>';
    }).join('');

    return '' +
      '<div class="wizard-step">' +
        '<h2>¿Para quién es?</h2>' +
        '<p>Elige una persona o un puesto compartido.</p>' +
        wizardErrorHtml() +
        '<div class="choice-grid" role="radiogroup" aria-label="Titular de la llave">' +
          '<label class="choice' + (wizard.ownerType === 'WORKER' ? ' is-selected' : '') + '">' +
            '<input type="radio" name="wiz-owner" value="WORKER"' + (wizard.ownerType === 'WORKER' ? ' checked' : '') + '>' +
            '<span class="choice-text"><span class="choice-icon">' + icon('user') + '</span>' +
              '<strong>Un empleado</strong><small>La llave quedará a nombre de una persona.</small></span>' +
          '</label>' +
          '<label class="choice' + (wizard.ownerType === 'ROLE' ? ' is-selected' : '') + '">' +
            '<input type="radio" name="wiz-owner" value="ROLE"' + (wizard.ownerType === 'ROLE' ? ' checked' : '') + '>' +
            '<span class="choice-text"><span class="choice-icon">' + icon('users') + '</span>' +
              '<strong>Un puesto compartido</strong><small>Varias personas usarán la misma llave.</small></span>' +
          '</label>' +
        '</div>' +
        (wizard.ownerType === 'WORKER'
          ? '<div class="field"><label for="wiz-worker">Empleado</label><select id="wiz-worker">' + workerOptions + '</select>' +
            (workers.length ? '<p class="hint">Solo aparecen empleados activos.</p>' : '<p class="field-error">' + icon('alert') + '<span>No hay empleados activos. Añade uno en la pestaña Empleados.</span></p>') +
            '</div>'
          : '<div class="field"><label for="wiz-role">Puesto compartido</label><select id="wiz-role">' + roleOptions + '</select>' +
            '<p class="hint">Una llave compartida no identifica qué persona la usa.</p></div>') +
      '</div>';
  }

  function wizardStepTwoHtml() {
    var nfcField = '';
    if (wizard.keyType === 'NFC') {
      if (wizard.nfcLoading) {
        nfcField = '<div class="note-box">' + icon('refresh') + '<span>Buscando fichas disponibles…</span></div>';
      } else if (wizard.nfcError) {
        nfcField = '<div class="inline-alert" role="alert">' + icon('alert') + '<span>' + esc(wizard.nfcError) + '</span></div>';
      } else if (!wizard.nfcAvailable.length) {
        nfcField = '<div class="inline-alert inline-alert--warn" role="status">' + icon('info') +
          '<span>No hay fichas disponibles. Pide al administrador que registre más.</span></div>';
      } else {
        nfcField = '<div class="field"><label for="wiz-nfc">Ficha física</label>' +
          '<select id="wiz-nfc"><option value="">Elige una ficha</option>' +
          wizard.nfcAvailable.map(function (card) {
            return '<option value="' + card.id + '"' + selectedAttr(card.id, wizard.nfcCardId) + '>' + esc(card.number) + '</option>';
          }).join('') +
          '</select><p class="hint">Solo se muestran fichas libres. El código de la ficha nunca se muestra aquí.</p></div>';
      }
    }

    return '' +
      '<div class="wizard-step">' +
        '<h2>¿Qué tipo de llave?</h2>' +
        '<p>Elige cómo se va a usar la llave.</p>' +
        wizardErrorHtml() +
        '<div class="choice-grid" role="radiogroup" aria-label="Tipo de llave">' +
          '<label class="choice' + (wizard.keyType === 'QR' ? ' is-selected' : '') + '">' +
            '<input type="radio" name="wiz-keytype" value="QR"' + (wizard.keyType === 'QR' ? ' checked' : '') + '>' +
            '<span class="choice-text"><span class="choice-icon">' + icon('qr') + '</span>' +
              '<strong>Código QR</strong><small>Se muestra una vez para guardarlo o imprimirlo.</small></span>' +
          '</label>' +
          '<label class="choice' + (wizard.keyType === 'NFC' ? ' is-selected' : '') + '">' +
            '<input type="radio" name="wiz-keytype" value="NFC"' + (wizard.keyType === 'NFC' ? ' checked' : '') + '>' +
            '<span class="choice-text"><span class="choice-icon">' + icon('card') + '</span>' +
              '<strong>Ficha física</strong><small>Se entrega una ficha NFC ya registrada.</small></span>' +
          '</label>' +
        '</div>' +
        nfcField +
      '</div>';
  }

  function wizardStepThreeHtml() {
    var roomChecks = state.roomTypes.map(function (roomType) {
      var checked = wizard.roomTypeIds.map(Number).indexOf(Number(roomType.id)) !== -1;
      var disabled = !!wizard.allRoomTypes;
      return '<label class="check-tile' + (checked ? ' is-selected' : '') + (disabled ? ' is-disabled' : '') + '">' +
        '<input type="checkbox" name="wiz-room-type" value="' + roomType.id + '"' +
          (checked ? ' checked' : '') + (disabled ? ' disabled' : '') + '>' +
        '<span>' + esc(roomType.name) + '</span>' +
      '</label>';
    }).join('');

    var defaultsNote = '';
    if (wizard.defaultsLoading) {
      defaultsNote = '<p class="hint">Cargando los permisos del puesto…</p>';
    } else if (wizard.defaultsError) {
      defaultsNote = '<p class="field-error">' + icon('alert') + '<span>' + esc(wizard.defaultsError) + '</span></p>';
    }

    return '' +
      '<div class="wizard-step">' +
        '<h2>¿Qué puede abrir y hasta cuándo?</h2>' +
        '<p>Estos permisos deciden qué puertas abrirá la llave.</p>' +
        wizardErrorHtml() +
        '<div class="form-stack">' +
          '<div class="field">' +
            '<button class="btn btn-secondary" type="button" data-wiz="use-defaults">' + icon('refresh') + ' Usar permisos del puesto</button>' +
            defaultsNote +
          '</div>' +
          '<div class="field">' +
            '<label class="switch-row' + (wizard.allRoomTypes ? ' is-selected' : '') + '">' +
              '<input id="wiz-all" type="checkbox"' + (wizard.allRoomTypes ? ' checked' : '') + '>' +
              '<span><strong>Todas las salas</strong><small>La llave abrirá cualquier puerta del hotel.</small></span>' +
            '</label>' +
          '</div>' +
          '<div class="field">' +
            '<span class="field-label">Tipos de sala</span>' +
            '<div class="check-grid">' + roomChecks + '</div>' +
          '</div>' +
          '<div class="field">' +
            '<label for="wiz-valid-until">Caduca el</label>' +
            '<input id="wiz-valid-until" type="datetime-local" required value="' + esc(wizard.validUntilLocal) + '" min="' + esc(nowLocalInput()) + '">' +
            '<p class="hint">La fecha se guarda y la llave dejará de abrir en cuanto llegue ese momento.</p>' +
          '</div>' +
          '<div class="field">' +
            '<label for="wiz-label">Etiqueta</label>' +
            '<input id="wiz-label" type="text" maxlength="120" value="' + esc(wizard.label) + '" placeholder="Llave de Ana">' +
            '<p class="hint">Un nombre corto para reconocerla en el listado.</p>' +
          '</div>' +
          '<div class="field">' +
            '<label for="wiz-notes">Notas (opcional)</label>' +
            '<textarea id="wiz-notes" maxlength="500">' + esc(wizard.notes) + '</textarea>' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function wizardStepFourHtml() {
    var owner = wizard.ownerType === 'WORKER'
      ? (wizardOwnerName() + ' (empleado)')
      : (wizardOwnerName() + ' (puesto compartido)');
    var type = wizard.keyType === 'QR'
      ? 'Código QR'
      : 'Ficha física' + (wizardNfcNumber() ? ' · ' + wizardNfcNumber() : '');
    var permissions = wizard.allRoomTypes
      ? 'Todas las salas'
      : (roomTypeNames(wizard.roomTypeIds) || 'Ninguna sala');
    var expiry = formatLocal(toUtcString(wizard.validUntilLocal));

    return '' +
      '<div class="wizard-step">' +
        '<h2>Revisar y crear</h2>' +
        '<p>Comprueba que todo es correcto antes de crear la llave.</p>' +
        '<dl class="summary">' +
          '<div class="summary-row"><dt>Titular</dt><dd>' + esc(owner) + '</dd></div>' +
          '<div class="summary-row"><dt>Tipo</dt><dd>' + esc(type) + '</dd></div>' +
          '<div class="summary-row"><dt>Etiqueta</dt><dd>' + esc(wizard.label || 'Llave nueva') + '</dd></div>' +
          '<div class="summary-row"><dt>Puede abrir</dt><dd>' + esc(permissions) + '</dd></div>' +
          '<div class="summary-row"><dt>Caduca</dt><dd>' + esc(expiry) + '</dd></div>' +
          (wizard.notes ? '<div class="summary-row"><dt>Notas</dt><dd>' + esc(wizard.notes) + '</dd></div>' : '') +
        '</dl>' +
        '<div class="note-box">' + icon('info') +
          '<span>' + (wizard.keyType === 'QR'
            ? 'El QR se mostrará una sola vez al crear la llave.'
            : 'Al crear la llave, entrega la ficha física indicada.') + '</span></div>' +
      '</div>';
  }

  function wizardFooterHtml() {
    var step = wizard.step;
    var backDisabled = step === 1 || wizard.saving;
    var nextDisabled = wizard.saving;
    if (step === 2 && wizard.keyType === 'NFC' && !wizard.nfcCardId) nextDisabled = true;
    if (step === 2 && wizard.keyType === 'NFC' && !wizard.nfcLoading && !wizard.nfcAvailable.length) nextDisabled = true;
    if (step === 4 && wizard.keyType === 'NFC' && !wizard.nfcCardId) nextDisabled = true;

    var footerError = '';
    if (step === 4 && wizard.error) {
      footerError = '<div class="inline-alert" role="alert" aria-live="assertive" style="width:100%;margin:0">' +
        icon('alert') + '<span>' + esc(wizard.error) + '</span>' +
        (wizard.keyType === 'NFC'
          ? '<button class="btn btn-secondary btn-sm" type="button" data-wiz="pick-card">Elegir otra ficha</button>'
          : '') +
      '</div>';
    }

    return footerError + '<div class="wizard-footer-actions">' +
      '<button class="btn btn-secondary btn-lg" type="button" data-wiz="back"' + (backDisabled ? ' disabled' : '') + '>' +
        icon('arrowLeft') + ' Atrás' +
      '</button>' +
      '<button class="btn btn-primary btn-lg" type="button" data-wiz="' + (step === 4 ? 'create' : 'next') + '"' + (nextDisabled ? ' disabled' : '') + '>' +
        (wizard.saving ? 'Creando llave…' : (step === 4 ? 'Crear llave' : 'Siguiente')) +
        (step === 4 || wizard.saving ? '' : ' ' + icon('arrowRight')) +
      '</button>' +
    '</div>';
  }

  function renderWizard() {
    if (!wizard || !wizardLayer) return;
    var progress = wizardLayer.querySelector('.wizard-progress');
    var stepHost = wizardLayer.querySelector('#wizard-step');
    if (!progress || !stepHost) return;

    progress.innerHTML = wizardProgressHtml();
    if (wizard.step === 1) stepHost.innerHTML = wizardStepOneHtml();
    else if (wizard.step === 2) stepHost.innerHTML = wizardStepTwoHtml();
    else if (wizard.step === 3) stepHost.innerHTML = wizardStepThreeHtml();
    else stepHost.innerHTML = wizardStepFourHtml();

    var footer = wizardLayer.querySelector('.modal-footer');
    if (footer) footer.innerHTML = wizardFooterHtml();

    if (wizard.focusId) {
      var id = wizard.focusId;
      wizard.focusId = '';
      setTimeout(function () { focusById(id); }, 0);
    }
  }

  async function wizardLoadNfc() {
    var owner = wizard;
    if (!owner || owner.nfcLoading) return;
    owner.nfcLoading = true;
    owner.nfcError = '';
    renderWizard();
    try {
      var response = await api('/api/v1/llaves/nfc-available');
      if (wizard !== owner) return;
      owner.nfcAvailable = response.data || [];
      if (owner.nfcCardId && !owner.nfcAvailable.some(function (card) {
        return Number(card.id) === Number(owner.nfcCardId);
      })) {
        owner.nfcCardId = '';
      }
    } catch (err) {
      if (wizard === owner) owner.nfcError = err.message;
    } finally {
      if (wizard === owner) {
        owner.nfcLoading = false;
        renderWizard();
      }
    }
  }

  async function wizardLoadDefaults(force) {
    var owner = wizard;
    if (!owner) return;
    var ownerKey = wizardOwnerKey();
    if (!ownerKey) return;
    if (!force && owner.defaultsKey === ownerKey) return;

    owner.defaultsLoading = true;
    owner.defaultsError = '';
    renderWizard();

    var query = owner.ownerType === 'WORKER'
      ? '?worker_id=' + encodeURIComponent(owner.workerId)
      : '?role_id=' + encodeURIComponent(owner.roleId);

    try {
      var response = await api('/api/v1/keys/defaults' + query);
      if (wizard !== owner) return;
      var data = response.data || {};
      owner.roomTypeIds = (data.room_type_ids || []).map(Number);
      owner.defaultsKey = ownerKey;
      if (!owner.labelTouched) owner.label = wizardDefaultLabel();
    } catch (err) {
      if (wizard === owner) owner.defaultsError = err.message;
    } finally {
      if (wizard === owner) {
        owner.defaultsLoading = false;
        renderWizard();
      }
    }
  }

  function wizardValidate(step) {
    if (!wizard) return false;
    wizard.error = '';
    if (step === 1) {
      if (wizard.ownerType === 'WORKER' && !wizard.workerId) {
        wizard.error = 'Elige un empleado.';
        wizard.focusId = 'wiz-worker';
        return false;
      }
      if (wizard.ownerType === 'ROLE' && !wizard.roleId) {
        wizard.error = 'Elige un puesto compartido.';
        wizard.focusId = 'wiz-role';
        return false;
      }
    }
    if (step === 2) {
      if (wizard.keyType === 'NFC' && !wizard.nfcCardId) {
        wizard.error = 'Elige una ficha disponible.';
        wizard.focusId = 'wiz-nfc';
        return false;
      }
    }
    if (step === 3) {
      if (!wizard.label.trim()) {
        wizard.error = 'Escribe una etiqueta para reconocer la llave.';
        wizard.focusId = 'wiz-label';
        return false;
      }
      if (!wizard.validUntilLocal || new Date(wizard.validUntilLocal).getTime() <= Date.now()) {
        wizard.error = 'Elige una fecha y hora futuras.';
        wizard.focusId = 'wiz-valid-until';
        return false;
      }
      if (!wizard.allRoomTypes && !wizard.roomTypeIds.length) {
        wizard.error = 'Marca al menos una sala o activa Todas las salas.';
        return false;
      }
    }
    return true;
  }

  async function wizardNext() {
    if (!wizard || wizard.saving) return;
    if (!wizardValidate(wizard.step)) {
      renderWizard();
      return;
    }
    if (wizard.step === 3) {
      wizard.step = 4;
      renderWizard();
      return;
    }
    wizard.step += 1;
    if (wizard.step === 3 && !wizard.defaultsKey) {
      renderWizard();
      await wizardLoadDefaults(false);
    } else {
      renderWizard();
    }
  }

  function wizardBack() {
    if (!wizard || wizard.saving || wizard.step <= 1) return;
    wizard.error = '';
    wizard.step -= 1;
    renderWizard();
  }

  async function wizardCreate() {
    if (!wizard || wizard.saving) return;
    if (wizard.keyType === 'NFC' && !wizard.nfcCardId) {
      wizard.step = 2;
      wizard.error = 'Elige una ficha disponible.';
      renderWizard();
      if (!wizard.nfcAvailable.length) wizardLoadNfc();
      return;
    }
    if (!wizardValidate(3)) {
      wizard.step = 3;
      renderWizard();
      return;
    }
    wizard.saving = true;
    wizard.error = '';
    renderWizard();

    var payload = {
      label: wizard.label.trim(),
      owner_type: wizard.ownerType,
      key_type: wizard.keyType,
      valid_until: toUtcString(wizard.validUntilLocal),
      all_room_types: !!wizard.allRoomTypes,
      room_type_ids: wizard.allRoomTypes ? [] : wizard.roomTypeIds.map(Number),
      notes: wizard.notes.trim() || null
    };
    if (wizard.ownerType === 'WORKER') payload.worker_id = Number(wizard.workerId);
    else payload.role_id = Number(wizard.roleId);
    if (wizard.keyType === 'NFC') payload.nfc_card_id = Number(wizard.nfcCardId);

    try {
      var response = await api('/api/v1/keys', jsonOptions('POST', payload));
      var created = response.data || response;
      if (wizardClose) wizardClose();
      await loadKeys();
      showToast('Llave creada.');
      if (created.key_type === 'QR' && created.qr_token) {
        showQrModal(created.qr_token, created.label);
      } else {
        showFichaSuccess(created);
      }
    } catch (err) {
      if (!wizard) return;
      wizard.saving = false;
      if (err.status === 409 && err.code === 'nfc_card_unavailable') {
        wizard.error = 'Esa ficha ya no está disponible. Hemos actualizado la lista: elige otra ficha.';
        await wizardLoadNfc();
      } else {
        wizard.error = err.message;
      }
      renderWizard();
    }
  }

  function bindWizard(layer) {
    layer.addEventListener('change', function (event) {
      var target = event.target;
      if (!target || !wizard) return;

      if (target.name === 'wiz-owner') {
        wizard.ownerType = target.value;
        wizard.error = '';
        wizard.defaultsKey = '';
        renderWizard();
      } else if (target.id === 'wiz-worker') {
        wizard.workerId = target.value;
        wizard.error = '';
        wizard.defaultsKey = '';
        if (!wizard.labelTouched) wizard.label = wizardDefaultLabel();
        renderWizard();
      } else if (target.id === 'wiz-role') {
        wizard.roleId = target.value;
        wizard.error = '';
        wizard.defaultsKey = '';
        if (!wizard.labelTouched) wizard.label = wizardDefaultLabel();
        renderWizard();
      } else if (target.name === 'wiz-keytype') {
        wizard.keyType = target.value;
        wizard.error = '';
        if (wizard.keyType === 'NFC' && !wizard.nfcAvailable.length && !wizard.nfcLoading) {
          wizardLoadNfc();
        } else {
          renderWizard();
        }
      } else if (target.id === 'wiz-nfc') {
        wizard.nfcCardId = target.value;
        wizard.error = '';
        renderWizard();
      } else if (target.id === 'wiz-all') {
        wizard.allRoomTypes = target.checked;
        renderWizard();
      } else if (target.name === 'wiz-room-type') {
        wizard.roomTypeIds = Array.prototype.slice.call(layer.querySelectorAll('input[name="wiz-room-type"]:checked'))
          .map(function (input) { return Number(input.value); });
      }
    });

    layer.addEventListener('input', function (event) {
      if (!wizard) return;
      var target = event.target;
      if (target.id === 'wiz-label') {
        wizard.label = target.value;
        wizard.labelTouched = true;
      } else if (target.id === 'wiz-notes') {
        wizard.notes = target.value;
      } else if (target.id === 'wiz-valid-until') {
        wizard.validUntilLocal = target.value;
      }
    });

    layer.addEventListener('click', function (event) {
      var button = event.target.closest('[data-wiz]');
      if (!button || !wizard) return;
      var action = button.getAttribute('data-wiz');
      if (action === 'back') wizardBack();
      if (action === 'next') wizardNext();
      if (action === 'create') wizardCreate();
      if (action === 'use-defaults') {
        wizardLoadDefaults(true);
      }
      if (action === 'pick-card') {
        wizard.step = 2;
        wizard.error = '';
        renderWizard();
        if (!wizard.nfcAvailable.length) wizardLoadNfc();
      }
    });
  }

  async function openWizard(options) {
    var opts = options || {};
    try {
      await ensureMeta(false);
    } catch (err) {
      showToast(err.message || 'No se pudieron cargar los datos necesarios.', 'error');
      return;
    }

    wizard = {
      step: opts.step || 1,
      ownerType: opts.ownerType || 'WORKER',
      workerId: opts.workerId ? String(opts.workerId) : '',
      roleId: opts.roleId ? String(opts.roleId) : '',
      keyType: 'QR',
      nfcCardId: '',
      nfcAvailable: [],
      nfcLoading: false,
      nfcError: '',
      roomTypeIds: [],
      allRoomTypes: false,
      defaultsKey: '',
      defaultsLoading: false,
      defaultsError: '',
      validUntilLocal: suggestedLocalInput(),
      label: opts.workerId ? ('Llave de ' + (workerName(opts.workerId) || '')) : 'Llave nueva',
      labelTouched: false,
      notes: '',
      saving: false,
      error: '',
      focusId: ''
    };

    var modal = openModal({
      title: 'Crear llave',
      wide: true,
      body:
        '<div class="wizard-progress">' + wizardProgressHtml() + '</div>' +
        '<div id="wizard-step"></div>',
      footer: wizardFooterHtml(),
      autofocus: 'input, select',
      onMount: function (layer) {
        wizardLayer = layer;
        bindWizard(layer);
        renderWizard();
        if (wizard && wizard.step === 3) wizardLoadDefaults(false);
        if (wizard && wizard.step === 2 && wizard.keyType === 'NFC') wizardLoadNfc();
      },
      onClose: function () {
        wizard = null;
        wizardLayer = null;
        wizardClose = null;
      }
    });
    wizardClose = modal.close;
  }

  // -------------------------------------------------------------------------
  // Employees
  // -------------------------------------------------------------------------

  function employeeStatus(worker) {
    return worker.active
      ? '<span class="status status--active">Activo</span>'
      : '<span class="status status--revoked">De baja</span>';
  }

  function employeeCard(worker) {
    var role = worker.role && worker.role.name ? worker.role.name : (roleName(worker.role_id) || 'Sin puesto');
    return '' +
      '<article class="person-card">' +
        '<div class="card-top">' +
          '<div class="card-headline">' +
            '<span class="card-icon">' + icon('user') + '</span>' +
            '<div>' +
              '<h3 class="card-title">' + esc(worker.name) + '</h3>' +
              '<p class="card-owner">' + esc(role) + '</p>' +
            '</div>' +
          '</div>' +
          employeeStatus(worker) +
        '</div>' +
        (worker.notes ? '<p class="hint">' + esc(worker.notes) + '</p>' : '') +
        '<div class="card-actions">' +
          '<button class="btn btn-secondary" type="button" data-action="employee-edit" data-id="' + worker.id + '">' +
            icon('edit') + ' Editar' +
          '</button>' +
          (worker.active
            ? '<button class="btn btn-secondary" type="button" data-action="employee-create-key" data-id="' + worker.id + '">' +
                icon('key') + ' Crear llave' +
              '</button>' +
              '<button class="btn btn-danger" type="button" data-action="employee-deactivate" data-id="' + worker.id + '">' +
                icon('trash') + ' Dar de baja' +
              '</button>'
            : '') +
        '</div>' +
      '</article>';
  }

  function renderEmployeesPage() {
    if (!state.metaLoaded) {
      viewEmployees.innerHTML = fatalHtml(state.metaError || 'No se pudieron cargar los empleados.');
      return;
    }

    viewEmployees.innerHTML =
      '<header class="page-head">' +
        '<div>' +
          '<h1>Empleados</h1>' +
          '<p>Añade al personal y crea sus llaves personales.</p>' +
        '</div>' +
        '<button class="primary-action" type="button" data-action="create-employee">' + icon('plus') + ' Añadir empleado</button>' +
      '</header>' +
      '<div class="toolbar">' +
        '<div class="field search-field">' +
          '<label for="emp-search">Buscar por nombre</label>' +
          '<div class="search-wrap">' + icon('search') +
            '<input id="emp-search" type="search" placeholder="Nombre del empleado" autocomplete="off" value="' + esc(state.empFilters.q) + '">' +
          '</div>' +
        '</div>' +
        '<button class="btn btn-secondary refresh-btn" type="button" data-action="refresh-employees">' + icon('refresh') + ' Actualizar</button>' +
      '</div>' +
      '<div class="filters">' +
        '<div class="field field--tight">' +
          '<label for="emp-active">Estado</label>' +
          '<select id="emp-active">' +
            '<option value="1"' + selectedAttr('1', state.empFilters.active) + '>Activos</option>' +
            '<option value="0"' + selectedAttr('0', state.empFilters.active) + '>Dados de baja</option>' +
            '<option value=""' + selectedAttr('', state.empFilters.active) + '>Todos</option>' +
          '</select>' +
        '</div>' +
      '</div>' +
      '<div id="emp-results" aria-live="polite"></div>';

    var search = document.getElementById('emp-search');
    var active = document.getElementById('emp-active');
    if (search) {
      search.addEventListener('input', function () {
        state.empFilters.q = search.value.trim();
        renderEmployeeResults();
      });
    }
    if (active) {
      active.addEventListener('change', function () {
        state.empFilters.active = active.value;
        renderEmployeeResults();
      });
    }

    renderEmployeeResults();
  }

  function renderEmployeeResults() {
    var results = document.getElementById('emp-results');
    if (!results) return;

    var query = state.empFilters.q.toLowerCase();
    var activeFilter = state.empFilters.active;
    var list = state.workers.filter(function (worker) {
      if (activeFilter !== '' && !!worker.active !== (activeFilter === '1')) return false;
      if (!query) return true;
      var haystack = (worker.name + ' ' + ((worker.role && worker.role.name) || '')).toLowerCase();
      return haystack.indexOf(query) !== -1;
    });

    if (!list.length) {
      var filtered = query || activeFilter !== '1';
      results.innerHTML =
        '<div class="empty-state">' +
          '<div class="empty-icon">' + icon('users') + '</div>' +
          '<h2>' + (filtered ? 'No hay empleados con estos filtros' : 'Todavía no hay empleados') + '</h2>' +
          '<p>' + (filtered
            ? 'Prueba a cambiar el buscador o el estado.'
            : 'Añade a la primera persona para poder crear sus llaves.') + '</p>' +
          (filtered
            ? '<button class="btn btn-secondary" type="button" data-action="clear-emp-filters">Quitar filtros</button>'
            : '<button class="primary-action" type="button" data-action="create-employee">' + icon('plus') + ' Añadir empleado</button>') +
        '</div>';
      return;
    }

    results.innerHTML =
      '<p class="result-summary"><strong>' + list.length + '</strong> ' +
        (list.length === 1 ? 'empleado' : 'empleados') + '</p>' +
      '<div class="card-grid">' + list.map(employeeCard).join('') + '</div>';
  }

  function openEmployeeForm(id) {
    var worker = id ? state.workers.find(function (item) { return Number(item.id) === Number(id); }) : null;
    var roleOptions = '<option value="">Elige un puesto</option>' + state.roles.map(function (role) {
      return '<option value="' + role.id + '"' + selectedAttr(role.id, worker ? worker.role_id : '') + '>' + esc(role.name) + '</option>';
    }).join('');

    var modal = openModal({
      title: worker ? 'Editar empleado' : 'Añadir empleado',
      body:
        '<div id="emp-form-error" class="inline-alert" role="alert" hidden></div>' +
        '<div class="form-stack">' +
          '<div class="field">' +
            '<label for="emp-name">Nombre</label>' +
            '<input id="emp-name" type="text" maxlength="100" value="' + esc(worker ? worker.name : '') + '" autocomplete="off">' +
            '<p id="emp-name-error" class="field-error" role="alert" hidden></p>' +
          '</div>' +
          '<div class="field">' +
            '<label for="emp-role">Puesto</label>' +
            '<select id="emp-role">' + roleOptions + '</select>' +
            '<p id="emp-role-error" class="field-error" role="alert" hidden></p>' +
          '</div>' +
          '<div class="field">' +
            '<label for="emp-notes">Notas (opcional)</label>' +
            '<textarea id="emp-notes" maxlength="500">' + esc(worker ? (worker.notes || '') : '') + '</textarea>' +
          '</div>' +
        '</div>',
      footer:
        '<button class="btn btn-secondary" type="button" data-modal-close="1">Cancelar</button>' +
        '<button class="btn btn-primary" type="button" data-modal-action="save-employee">' +
          (worker ? 'Guardar cambios' : 'Añadir empleado') +
        '</button>',
      autofocus: '#emp-name'
    });

    var layer = modal.layer;
    layer.addEventListener('click', function (event) {
      var button = event.target.closest('[data-modal-action]');
      if (!button || button.getAttribute('data-modal-action') !== 'save-employee') return;
      saveEmployee(layer, worker, modal.close);
    });
  }

  async function saveEmployee(layer, worker, close) {
    var nameInput = layer.querySelector('#emp-name');
    var roleSelect = layer.querySelector('#emp-role');
    var notesInput = layer.querySelector('#emp-notes');
    var nameError = layer.querySelector('#emp-name-error');
    var roleError = layer.querySelector('#emp-role-error');
    var formError = layer.querySelector('#emp-form-error');

    clearFieldError(nameInput, nameError);
    clearFieldError(roleSelect, roleError);
    hideInlineError(formError);

    var name = (nameInput.value || '').trim();
    var roleId = roleSelect.value;
    if (!name) {
      setFieldError(nameInput, nameError, 'Escribe el nombre del empleado.');
      nameInput.focus();
      return;
    }
    if (!roleId) {
      setFieldError(roleSelect, roleError, 'Elige un puesto.');
      roleSelect.focus();
      return;
    }

    var payload = {
      name: name,
      role_id: Number(roleId),
      notes: (notesInput.value || '').trim() || null
    };

    var buttons = layer.querySelectorAll('.modal-footer .btn');
    buttons.forEach(function (button) { button.disabled = true; });

    var response;
    try {
      response = worker
        ? await api('/api/v1/workers/' + worker.id, jsonOptions('PATCH', payload))
        : await api('/api/v1/workers', jsonOptions('POST', payload));
    } catch (err) {
      buttons.forEach(function (button) { button.disabled = false; });
      showInlineError(formError, err.message);
      return;
    }

    var saved = (response && (response.data || response)) || {};
    close();
    try {
      await refreshWorkers();
      if (state.tab === 'employees') renderEmployeeResults();
    } catch (err) {
      showToast('Se guardó el empleado, pero no se pudo actualizar la lista.', 'error');
    }
    if (worker) {
      showToast('Empleado actualizado.');
    } else {
      showEmployeeCreated(saved);
    }
  }

  function showEmployeeCreated(worker) {
    var name = (worker && worker.name) || 'el empleado';
    openModal({
      title: 'Empleado añadido',
      body:
        '<p class="lead">' + esc(name) + ' ya forma parte del equipo.</p>' +
        '<div class="note-box">' + icon('key') +
          '<span>Puedes crear ahora una llave personal para esta persona.</span></div>',
      footer:
        '<button class="btn btn-secondary" type="button" data-modal-close="1">Cerrar</button>' +
        '<button class="btn btn-primary" type="button" data-created-key="1">' + icon('plus') + ' Crear llave para ' + esc(name) + '</button>',
      onMount: function (layer, close) {
        var button = layer.querySelector('[data-created-key]');
        if (!button) return;
        button.addEventListener('click', function () {
          close();
          openWizard({ ownerType: 'WORKER', workerId: (worker && worker.id) || '', step: 2 });
        });
      }
    });
  }

  async function deactivateEmployee(worker) {
    var ok = await confirmDialog({
      title: 'Dar de baja al empleado',
      message: '¿Dar de baja a ' + worker.name + '? Sus llaves personales dejarán de abrir.',
      confirmText: 'Sí, dar de baja',
      danger: true
    });
    if (!ok) return;

    try {
      await api('/api/v1/workers/' + worker.id, { method: 'DELETE' });
      await refreshWorkers();
      if (state.tab === 'employees') renderEmployeeResults();
      showToast(worker.name + ' ya está de baja.');
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  // -------------------------------------------------------------------------
  // Actions + boot
  // -------------------------------------------------------------------------

  function findKey(id) {
    return state.keys.find(function (key) { return Number(key.id) === Number(id); }) || null;
  }

  async function handleAction(action, element) {
    var id = element.getAttribute('data-id');

    if (action === 'create-key') {
      openWizard();
      return;
    }
    if (action === 'key-detail') {
      openKeyDetail(Number(id));
      return;
    }
    if (action === 'key-revoke') {
      var key = findKey(id);
      if (!key) {
        try {
          var response = await api('/api/v1/keys/' + id);
          key = response.data || response;
        } catch (err) {
          showToast(err.message, 'error');
          return;
        }
      }
      revokeKey(key);
      return;
    }
    if (action === 'key-reactivate') {
      var reactivateTarget = findKey(id);
      if (!reactivateTarget) {
        try {
          var reactivateResponse = await api('/api/v1/keys/' + id);
          reactivateTarget = reactivateResponse.data || reactivateResponse;
        } catch (err) {
          showToast(err.message, 'error');
          return;
        }
      }
      reactivateKey(reactivateTarget);
      return;
    }
    if (action === 'refresh-keys' || action === 'retry-keys') {
      loadKeys();
      return;
    }
    if (action === 'clear-key-filters') {
      state.keyFilters = { q: '', status: '', key_type: '' };
      renderKeysPage();
      return;
    }
    if (action === 'create-employee') {
      openEmployeeForm();
      return;
    }
    if (action === 'employee-edit') {
      openEmployeeForm(Number(id));
      return;
    }
    if (action === 'employee-create-key') {
      openWizard({ ownerType: 'WORKER', workerId: Number(id), step: 2 });
      return;
    }
    if (action === 'employee-deactivate') {
      var worker = state.workers.find(function (item) { return Number(item.id) === Number(id); });
      if (worker) deactivateEmployee(worker);
      return;
    }
    if (action === 'refresh-employees') {
      try {
        await refreshWorkers();
        if (state.tab === 'employees') renderEmployeeResults();
        showToast('Lista de empleados actualizada.');
      } catch (err) {
        showToast(err.message, 'error');
      }
      return;
    }
    if (action === 'clear-emp-filters') {
      state.empFilters = { q: '', active: '1' };
      renderEmployeesPage();
      return;
    }
    if (action === 'init-retry') {
      init();
    }
  }

  function bindStaticEvents() {
    document.addEventListener('click', function (event) {
      var element = event.target.closest('[data-action]');
      if (!element) return;
      if (element.closest('#modal-root')) return;
      handleAction(element.getAttribute('data-action'), element);
    });

    tabKeys.addEventListener('click', function () { setTab('keys'); });
    tabEmployees.addEventListener('click', function () { setTab('employees'); });
    tabKeys.addEventListener('keydown', function (event) { handleTabKeys(event, 0); });
    tabEmployees.addEventListener('keydown', function (event) { handleTabKeys(event, 1); });

    var logout = document.getElementById('logout-btn');
    if (logout) {
      logout.addEventListener('click', async function () {
        logout.disabled = true;
        try {
          await fetch('/api/v1/crm/logout', { method: 'POST', credentials: 'same-origin' });
        } catch (err) {
          // Redirect anyway: the session cookie is HttpOnly and server-side.
        }
        window.location.href = '/llaves/login';
      });
    }
  }

  function fatalHtml(message) {
    return '<div class="empty-state">' +
      '<div class="empty-icon">' + icon('alert') + '</div>' +
      '<h2>No se pudo cargar el panel</h2>' +
      '<p>' + esc(message || 'Revisa la conexión e inténtalo de nuevo.') + '</p>' +
      '<button class="btn btn-secondary" type="button" data-action="init-retry">' + icon('refresh') + ' Reintentar</button>' +
    '</div>';
  }

  async function init() {
    viewKeys.innerHTML =
      '<div class="empty-state" aria-live="polite">' +
        '<div class="empty-icon">' + icon('key') + '</div>' +
        '<h2>Cargando llaves…</h2>' +
        '<p>Estamos preparando la información.</p>' +
      '</div>';

    try {
      var me = await api('/api/v1/crm/me');
      state.user = me.user;
      renderUser();
    } catch (err) {
      if (err.status === 401) return;
      viewKeys.innerHTML = fatalHtml(err.message);
      return;
    }

    try {
      await ensureMeta();
    } catch (err) {
      showToast(err.message, 'error');
    }

    setTab('keys');
  }

  bindStaticEvents();
  init();
})();
