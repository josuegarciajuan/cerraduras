/* =========================================================================
   F119 — Panel CRM v2
   Capa de presentación que envuelve el componente histórico `crm()`.
   Mantiene intactos los métodos CRUD existentes y sustituye las vistas.
   ========================================================================= */
(function () {
  'use strict';

  if (typeof window.crm !== 'function') return;
  var baseCrm = window.crm;
  var F = window.PanelFormat;
  var C = window.PanelCharts;
  var AI = window.AnomalyInfo || { catalog: {}, info: function () { return { title: '', desc: '', action: '' }; }, title: function (x) { return x; }, severityLabel: function (x) { return x; }, stateLabel: function (x) { return x; }, cell: function (x) { return x; } };

  var TITLES = {
    db: ['Dashboard', 'Resumen operativo en tiempo real'],
    ro: ['Habitaciones', 'Estado, ocupación y actividad por sala'],
    pk: ['Packs', 'Agrupaciones de hardware por habitación'],
    dv: ['Dispositivos', 'Inventario físico, batería y firmware'],
    st: ['Estancias', 'Ciclos de alquiler y ocupación'],
    qr: ['QRs', 'Credenciales emitidas y su vigencia'],
    dbts: ['Deudas', 'Excesos y sincronización con VB6'],
    w: ['Trabajadores', 'Personas, accesos y actividad por sala'],
    ky: ['Llaves', 'Llaves QR y fichas NFC con permisos'],
    nc: ['Fichas NFC', 'Inventario y asignación de fichas'],
    ac: ['Accesos', 'Intentos, resultados y patrones'],
    fd: ['Fábrica', 'Equipos anunciados antes del montaje'],
    wr: ['Roles', 'Permisos de acceso por tipo de sala'],
    mon: ['Monitor', 'Eventos recientes y cola VB6'],
    us: ['Usuarios', 'Cuentas del panel CRM'],
    cl: ['API Clients', 'Credenciales de integración'],
    an: ['Anomalías', 'Detección y seguimiento de incidencias'],
    ana: ['Analítica', 'Explorador de datos de uso'],
    he: ['Salud', 'Estado de servicios e integraciones'],
    cfg: ['Configuración', 'Parámetros en tiempo de ejecución']
  };

  window.crm = function () {
    var o = baseCrm();

    /* ── Estado v2 ─────────────────────────────────────────────────── */
    o.sidebarOpen = false;
    o.globalSearch = '';
    o.searchResults = null;
    o.analyticsDays = 7;
    o.analytics = null;
    o.analyticsLoading = false;
    o.live = null;
    o.liveOk = false;
    o.analyticsTab = 'resumen';
    o.workersAnalytics = null;
    o.roomsAnalytics = null;
    o.accessAnalytics = null;
    o.anomalyAnalytics = null;
    // La vista por defecto muestra las anomalías todavía operativas:
    // pendientes (OPEN) y en seguimiento (ACKNOWLEDGED). Reconocer no las
    // oculta; solo descartar las saca de esta vista.
    o.anomalyFilters = { status: 'ACTIVE', type: '', severity: '', room_id: '' };
    o.deviceAnalytics = null;
    o.warehouseAnalytics = null;
    o.keysAnalytics = null;
    o.workerQ = '';
    o.roomQ = '';
    o.roomStatusFilter = '';
    o.stayStatusFilter = '';
    o.occupations = null;
    o.occupationFilters = { kind: '', status: '', room_id: '', q: '' };
    o.warehouseLogs = null;
    o.warehouseVisit = null;
    o.whRoomId = '';
    o.whLogKind = '';
    o.whLogApplied = '';
    o.accessResultFilter = '';
    // F121 — filtros de listados.
    o.accessFilters = { worker_id: '', key_id: '', result: '', room_id: '', type: '', from: '', to: '' };
    o.qrFilters = { room_id: '', status: '', q: '' };
    o.debtFilters = { status: '', room_id: '', from: '', to: '' };
    o.keyFilters = { worker_id: '', status: '', q: '', key_type: '' };
    o.monitorFilters = { room_id: '', kind: '', result: '' };
    o.drawer = { show: false, title: '', subtitle: '', content: '', footer: '' };
    o._v2Timer = null;
    o._liveClock = null;

    /* ── Utilidades de render ──────────────────────────────────────── */
    o.pageTitle = function () { return (TITLES[this.p] || ['Panel', ''])[0]; };
    o.pageSubtitle = function () { return (TITLES[this.p] || ['', ''])[1]; };

    // F121 — tipos de acceso: credenciales y eventos de puerta comparten filtro.
    o.accessTypeLabel = function (type) {
      var map = {
        QR: 'QR de llave',
        NFC: 'Ficha NFC',
        QR_VALIDATE: 'QR validado',
        OPEN: 'Apertura',
        AUTO_LOCK: 'Cierre automático',
        MANUAL_LOCK: 'Cierre manual',
        DENIED: 'Denegado',
        WORKER_EXIT: 'Salida de empleado'
      };
      return map[type] || (type || '—');
    };

    o.accessTypeOptions = function (selected) {
      var groups = [
        ['Credenciales', [['QR', 'QR de llave'], ['NFC', 'Ficha NFC']]],
        ['Eventos de puerta', [
          ['QR_VALIDATE', 'QR validado'],
          ['OPEN', 'Apertura'],
          ['AUTO_LOCK', 'Cierre automático'],
          ['MANUAL_LOCK', 'Cierre manual'],
          ['DENIED', 'Denegado'],
          ['WORKER_EXIT', 'Salida de empleado']
        ]]
      ];
      return groups.map(function (g) {
        return '<optgroup label="' + F.escape(g[0]) + '">' + g[1].map(function (item) {
          return '<option value="' + item[0] + '"' + (selected === item[0] ? ' selected' : '') + '>' + F.escape(item[1]) + '</option>';
        }).join('') + '</optgroup>';
      }).join('');
    };

    o.roomFilterOptions = function (selected, allLabel) {
      return '<option value="">' + F.escape(allLabel || 'Todas las salas') + '</option>'
        + (this.rooms || []).map(function (r) {
          return '<option value="' + r.id + '"' + (String(selected) === String(r.id) ? ' selected' : '') + '>' + F.escape(r.code) + '</option>';
        }).join('');
    };

    o.dtLocalValue = function (value) {
      if (!value) return '';
      return String(value).replace(' ', 'T').slice(0, 16);
    };

    o.v2Kpi = function (label, value, sub, color, icon) {
      return '<div class="kpi" style="--kc:' + (color || 'var(--accent)') + '">'
        + '<div class="kpi-label">' + (icon ? '<span>' + icon + '</span>' : '') + F.escape(label) + '</div>'
        + '<div class="kpi-value">' + value + '</div>'
        + '<div class="kpi-sub">' + (sub || '') + '</div></div>';
    };
    o.v2Card = function (title, subtitle, body, actions) {
      return '<section class="card"><div class="card-head"><div><h3>' + F.escape(title) + '</h3>'
        + (subtitle ? '<p>' + subtitle + '</p>' : '') + '</div><div class="row">' + (actions || '') + '</div></div>'
        + '<div class="card-body">' + body + '</div></section>';
    };
    o.v2Rank = function (items, labelKey, valueKey, onClick) {
      if (!items || !items.length) return '<div class="table-empty">Sin datos</div>';
      var max = 1;
      items.forEach(function (x) { max = Math.max(max, Number(x[valueKey]) || 0); });
      return '<div class="rank-list">' + items.map(function (x, i) {
        var v = Number(x[valueKey]) || 0;
        return '<div class="rank-item" ' + (onClick ? 'style="cursor:pointer" @click="' + onClick + '(' + x.id + ')"' : '') + '>'
          + '<span class="rank-n">' + (i + 1) + '</span>'
          + '<div class="rank-main"><strong>' + F.escape(x[labelKey] || '—') + '</strong>'
          + '<div class="rank-bar"><i style="width:' + Math.max(4, v / max * 100) + '%"></i></div></div>'
          + '<span class="rank-val">' + v + '</span></div>';
      }).join('') + '</div>';
    };
    o.v2Table = function (head, rows, empty) {
      var body = Array.isArray(rows) ? rows.join('') : String(rows || '');
      if (!body) return '<div class="table-empty">' + F.escape(empty || 'Sin datos en el periodo') + '</div>';
      return '<div class="table-wrap"><table><thead><tr>' + head.map(function (h) { return '<th>' + F.escape(h) + '</th>'; }).join('')
        + '</tr></thead><tbody>' + body + '</tbody></table></div>';
    };
    o.roomTypeCode = function (roomId) {
      var room = (this.rooms || []).find(function (r) { return r.id === roomId; });
      if (!room) return null;
      var t = (this.roomTypes || []).find(function (x) { return x.id === room.room_type_id; });
      return t ? String(t.code || '').toUpperCase() : null;
    };
    o.panelUrlForRoom = function (roomId) {
      var code = this.roomTypeCode(roomId);
      if (code === 'ALMACEN_BEBIDAS') return '/almacen?room=' + roomId;
      if (code === 'TIMBRADO') return '/timbre?room=' + roomId;
      return '/dashboard?room=' + roomId;
    };
    o.panelLabelForRoom = function (roomId) {
      var code = this.roomTypeCode(roomId);
      if (code === 'ALMACEN_BEBIDAS') return 'Ver almacén';
      if (code === 'TIMBRADO') return 'Ver timbre';
      return 'Ver dashboard';
    };
    o.occupationLabel = function (kind) { return kind === 'worker' ? 'Empleado' : 'Huésped'; };
    o.accessMediumLabel = function (m) {
      return {
        QR_STAY: 'QR de estancia',
        QR_KEY: 'QR de llave',
        NFC: 'Ficha NFC',
        WORKER_QR: 'QR maestro',
        UNKNOWN: 'No identificado'
      }[m] || 'No identificado';
    };
    o.openEntity = function (kind, id) {
      if (kind === 'room') { this.nav('ro'); this.openRoomDrawer(id); return; }
      if (kind === 'stay') { this.openOccupationDrawer('stay', id); return; }
      if (kind === 'worker') { this.nav('w'); this.openWorkerDrawer(id); return; }
      if (kind === 'anomaly') { this.nav('an'); this.openAnomalyDrawer(id); return; }
      if (kind === 'visit') { this.openWarehouseVisit(id); return; }
    };
    o.v2Badge = function (status, label) { return F.badge(status, label); };
    o.v2Avatar = function (name) {
      return '<span class="avatar" style="width:28px;height:28px;border-radius:50%;display:inline-grid;place-items:center;background:#e8efff;color:var(--accent);font-weight:700;font-size:10.5px;flex:none">'
        + F.escape(F.initials(name)) + '</span>';
    };

    /* ── Arranque ──────────────────────────────────────────────────── */
    var baseInit = o.init;
    o.init = async function () {
      try { await baseInit.call(this); } catch (e) { return; }
      this.bindGlobalKeys();
      this.startLiveClock();
      this.bindFocusRestore();
      await this.applyHash();
      await this.refreshAll();
      this._v2Timer = setInterval(function () {
        if (document.hidden) return;
        this.loadLiveV2();
      }.bind(this), 20000);
    };

    o.bindGlobalKeys = function () {
      document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 'k') {
          e.preventDefault();
          var el = document.querySelector('.search-wrap input');
          if (el) el.focus();
        }
        if (e.key === 'Escape') o.drawer.show = false;
      });
      window.addEventListener('hashchange', function () { o.applyHash(); });
    };

    o.parseHash = function () {
      var raw = String(location.hash || '').replace(/^#/, '');
      var parts = raw.split('?');
      var query = {};
      if (parts[1]) {
        try { new URLSearchParams(parts[1]).forEach(function (v, k) { query[k] = v; }); } catch (e) { query = {}; }
      }
      return { page: parts[0] || '', query: query };
    };

    o.setHash = function (page, params) {
      var qs = [];
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] !== '' && params[k] != null) qs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
      });
      var hash = '#' + page + (qs.length ? '?' + qs.join('&') : '');
      if (location.hash !== hash) history.replaceState(null, '', hash);
    };

    o.applyHash = async function () {
      var h = this.parseHash();
      var page = h.page;
      if (!page || !TITLES[page]) return;
      if (page !== this.p) this.nav(page);
      var q = h.query;
      if (q.room) this.openRoomDrawer(parseInt(q.room, 10));
      else if (q.stay) this.openOccupationDrawer('stay', parseInt(q.stay, 10));
      else if (q.occ && String(q.occ).indexOf(':') > 0) {
        var occ = String(q.occ).split(':');
        this.openOccupationDrawer(occ[0] === 'worker' ? 'worker' : 'stay', parseInt(occ[1], 10));
      }
      else if (q.worker) this.openWorkerDrawer(parseInt(q.worker, 10));
      else if (q.anomaly) this.openAnomalyDrawer(parseInt(q.anomaly, 10));
      else if (q.visit) this.openWarehouseVisit(parseInt(q.visit, 10));
    };

    o.bindFocusRestore = function () {
      if (!this.$watch) return;
      var that = this;
      this.$watch('drawer.show', function (open) {
        if (open) {
          that._focusBefore = document.activeElement;
          setTimeout(function () {
            var el = document.querySelector('.drawer [aria-label="Cerrar detalle"]');
            if (el) el.focus();
          }, 30);
        } else if (that._focusBefore && that._focusBefore.focus) {
          that._focusBefore.focus();
        }
      });
      this.$watch('modal.show', function (open) {
        if (open) {
          that._focusBeforeModal = document.activeElement;
          setTimeout(function () {
            var el = document.querySelector('.modal [autofocus], .modal input, .modal select, .modal button');
            if (el) el.focus();
          }, 30);
        } else if (that._focusBeforeModal && that._focusBeforeModal.focus) {
          that._focusBeforeModal.focus();
        }
      });
    };

    o.startLiveClock = function () {
      if (this._liveClock) return;
      this._liveClock = setInterval(function () {
        document.querySelectorAll('[data-live-entered]').forEach(function (el) {
          var d = F.toDate(el.getAttribute('data-live-entered'));
          if (!d) return;
          el.textContent = F.duration(Math.max(0, Math.round((Date.now() - d.getTime()) / 60000)));
        });
      }, 1000);
    };

    o.refreshAll = async function () {
      await Promise.all([this.loadAnalyticsV2(), this.loadLiveV2()]);
      if (this.p === 'w') await this.loadWorkersAnalyticsV2();
      if (this.p === 'ro') await this.loadRoomsAnalyticsV2();
      if (this.p === 'ac') await this.loadAccessAnalyticsV2();
      if (this.p === 'an') await this.loadAnomalyAnalyticsV2();
      if (this.p === 'dv' || this.p === 'pk') await this.loadDeviceAnalyticsV2();
      if (this.p === 'mon') await this.loadWarehouseAnalyticsV2();
      if (this.p === 'st') await this.loadOccupationsV2();
    };

    o.setDays = function (days) {
      this.analyticsDays = Number(days) || 7;
      this.refreshAll();
    };

    /* ── Carga de datos ────────────────────────────────────────────── */
    o.loadAnalyticsV2 = async function () {
      if (!this.analytics) this.analyticsLoading = true;
      try {
        this.analytics = await this.api('/api/v1/analytics/overview?days=' + this.analyticsDays);
        this.liveOk = true;
      } catch (e) {
        this.liveOk = false;
      } finally {
        this.analyticsLoading = false;
      }
    };

    o.loadLiveV2 = async function () {
      try {
        this.live = await this.api('/api/v1/analytics/live');
        this.liveOk = true;
      } catch (e) {
        this.liveOk = false;
      }
    };

    o.loadWorkersAnalyticsV2 = async function () {
      try {
        this.workersAnalytics = await this.api('/api/v1/analytics/workers?days=' + this.analyticsDays
          + (this.workerQ ? '&q=' + encodeURIComponent(this.workerQ) : ''));
      } catch (e) { this.workersAnalytics = null; }
    };
    o.loadRoomsAnalyticsV2 = async function () {
      try {
        this.roomsAnalytics = await this.api('/api/v1/analytics/rooms?days=' + this.analyticsDays
          + (this.roomQ ? '&q=' + encodeURIComponent(this.roomQ) : '')
          + (this.roomStatusFilter ? '&status=' + encodeURIComponent(this.roomStatusFilter) : ''));
      } catch (e) { this.roomsAnalytics = null; }
    };
    o.loadAccessAnalyticsV2 = async function () {
      var f = this.accessFilters || {};
      var params = ['days=' + this.analyticsDays];
      ['room_id', 'type', 'worker_id', 'result', 'from', 'to'].forEach(function (k) {
        if (f[k] !== undefined && f[k] !== null && f[k] !== '') {
          params.push(k + '=' + encodeURIComponent(f[k]));
        }
      });
      try { this.accessAnalytics = await this.api('/api/v1/analytics/accesses?' + params.join('&')); }
      catch (e) { this.accessAnalytics = null; }
    };
    o.setAccessFilterV2 = function (key, value) {
      if (!this.accessFilters) this.accessFilters = {};
      this.accessFilters[key] = value;
      this.loadAccessAnalyticsV2();
    };
    o.clearAccessFiltersV2 = function () {
      this.accessFilters = { worker_id: '', key_id: '', result: '', room_id: '', type: '', from: '', to: '' };
      this.loadAccessAnalyticsV2();
    };

    // F121 — QRs: sala, estado real y JTI. El endpoint ya acepta estos filtros.
    o.loadQrs = async function () {
      var f = this.qrFilters || {};
      var p = new URLSearchParams();
      if (f.room_id) p.set('room_id', f.room_id);
      if (f.status) p.set('status', f.status);
      if (f.q) p.set('jti', f.q);
      p.set('limit', '200');
      try { var d = await this.api('/api/v1/qr?' + p.toString()); this.qrs = d.items || []; }
      catch (e) { this.qrs = []; }
    };
    o.setQrFilter = function (key, value) {
      if (!this.qrFilters) this.qrFilters = {};
      this.qrFilters[key] = value;
      this.loadQrs();
    };
    o.clearQrFilters = function () {
      this.qrFilters = { room_id: '', status: '', q: '' };
      this.loadQrs();
    };

    // F121 — Deudas: estado, sala y fechas. Límite visible de 200.
    o.loadDebts = async function () {
      var f = this.debtFilters || {};
      var p = new URLSearchParams();
      if (f.status) p.set('status', f.status);
      if (f.room_id) p.set('room_id', f.room_id);
      if (f.from) p.set('from', String(f.from).replace('T', ' '));
      if (f.to) p.set('to', String(f.to).replace('T', ' '));
      p.set('limit', '200');
      try { var d = await this.api('/api/v1/debts?' + p.toString()); this.debts = d.items || []; }
      catch (e) { this.debts = []; }
    };
    o.setDebtFilter = function (key, value) {
      if (!this.debtFilters) this.debtFilters = {};
      this.debtFilters[key] = value;
      this.loadDebts();
    };
    o.clearDebtFilters = function () {
      this.debtFilters = { status: '', room_id: '', from: '', to: '' };
      this.loadDebts();
    };

    // F121 — Llaves: el endpoint ya acepta key_type y worker_id.
    o.loadKeys = async function () {
      var f = this.keyFilters || {};
      var p = new URLSearchParams();
      ['worker_id', 'status', 'q', 'key_type'].forEach(function (k) {
        if (f[k]) p.set(k, f[k]);
      });
      try { var d = await this.api('/api/v1/keys' + (p.toString() ? '?' + p.toString() : '')); this.accessKeys = d.data || []; }
      catch (e) { this.toastErr(e.message); }
    };

    // F121 — Monitor: sala, tipo de evento y resultado.
    o.loadMonitor = async function () {
      var f = this.monitorFilters || {};
      var p = new URLSearchParams();
      if (f.room_id) p.set('room_id', f.room_id);
      if (f.kind) p.set('kind', f.kind);
      if (f.result) p.set('result', f.result);
      p.set('limit', '200');
      try { var d = await this.api('/api/v1/admin/access-events?' + p.toString()); this.events = d.items || []; }
      catch (e) { this.events = []; }
      try { var o = await this.api('/api/v1/admin/outbox'); this.outbox = o.items || []; }
      catch (e) { /* la cola no bloquea el monitor */ }
    };
    o.loadAnomalyAnalyticsV2 = async function () {
      try { this.anomalyAnalytics = await this.api('/api/v1/analytics/anomalies?days=' + this.analyticsDays); }
      catch (e) { this.anomalyAnalytics = null; }
    };
    o.loadDeviceAnalyticsV2 = async function () {
      try { this.deviceAnalytics = await this.api('/api/v1/analytics/devices'); }
      catch (e) { this.deviceAnalytics = null; }
    };
    o.loadWarehouseAnalyticsV2 = async function () {
      try {
        this.warehouseAnalytics = await this.api('/api/v1/analytics/warehouse?days=' + this.analyticsDays
          + (this.whRoomId ? '&room_id=' + encodeURIComponent(this.whRoomId) : ''));
      } catch (e) { this.warehouseAnalytics = null; }
      this.loadWarehouseLogsV2();
    };
    o.loadWarehouseLogsV2 = async function () {
      try {
        this.warehouseLogs = await this.api('/api/v1/analytics/warehouse/logs?days=' + this.analyticsDays
          + (this.whRoomId ? '&room_id=' + encodeURIComponent(this.whRoomId) : '')
          + (this.whLogKind ? '&kind=' + encodeURIComponent(this.whLogKind) : '')
          + (this.whLogApplied !== '' ? '&applied=' + encodeURIComponent(this.whLogApplied) : ''));
      } catch (e) { this.warehouseLogs = null; }
    };
    o.loadKeysAnalyticsV2 = async function () {
      try { this.keysAnalytics = await this.api('/api/v1/analytics/keys?days=' + this.analyticsDays); }
      catch (e) { this.keysAnalytics = null; }
    };

    /* ── Búsqueda global ───────────────────────────────────────────── */
    o.doSearch = async function () {
      var q = (this.globalSearch || '').trim();
      if (q.length < 2) { this.searchResults = null; return; }
      try { this.searchResults = await this.api('/api/v1/analytics/search?q=' + encodeURIComponent(q)); }
      catch (e) { this.searchResults = null; }
    };
    o.openSearchResult = function (group, item) {
      this.searchResults = null;
      this.globalSearch = '';
      if (group === 'rooms') { this.nav('ro'); this.openRoomDrawer(item.id); return; }
      if (group === 'workers') { this.nav('w'); this.openWorkerDrawer(item.id); return; }
      if (group === 'keys') { this.nav('ky'); return; }
      if (group === 'devices') { this.nav('dv'); return; }
      if (group === 'packs') { this.nav('pk'); return; }
      if (group === 'anomalies') { this.nav('an'); this.openAnomalyDrawer(item.id); return; }
      if (group === 'stays') { this.nav('st'); this.openOccupationDrawer('stay', item.id); return; }
    };

    /* ── Navegación y render ───────────────────────────────────────── */
    var baseNav = o.nav;
    o.nav = function (p) {
      this.p = p;
      this.drawer.show = false;
      this.sidebarOpen = false;
      if (this.parseHash().page !== p) history.replaceState(null, '', '#' + p);
      baseNav.call(this, p);
      if (p === 'st') this.loadOccupationsV2();
      if (p === 'db') this.refreshAll();
      if (p === 'ana') this.refreshAll();
      if (p === 'w') this.loadWorkersAnalyticsV2();
      if (p === 'ro') this.loadRoomsAnalyticsV2();
      if (p === 'ac') this.loadAccessAnalyticsV2();
      if (p === 'an') this.loadAnomalyAnalyticsV2();
      if (p === 'dv' || p === 'pk') this.loadDeviceAnalyticsV2();
      if (p === 'mon') this.loadWarehouseAnalyticsV2();
      if (p === 'ky') this.loadKeysAnalyticsV2();
    };

    o.render = function () {
      if (this.p === 'db' && !this.analytics) return this.v2SkeletonDashboard();
      switch (this.p) {
        case 'db': return this.dbV2();
        case 'ro': return this.roomsPageV2();
        case 'pk': return this.packPage();
        case 'dv': return this.devPage();
        case 'st': return this.stayPageV2();
        case 'qr': return this.qrPageV2();
        case 'dbts': return this.debtPageV2();
        case 'w': return this.workersPageV2();
        case 'ky': return this.keysPageV2();
        case 'nc': return this.nfcPageV2();
        case 'ac': return this.accessesPageV2();
        case 'fd': return this.factoryPageV2();
        case 'wr': return this.workerRolesPageV2();
        case 'mon': return this.monitorPageV2();
        case 'us': return this.crmUserPageV2();
        case 'cl': return this.apiClientPageV2();
        case 'an': return this.anomalyPageV2();
        case 'ana': return this.analyticsPageV2();
        case 'he': return this.healthPageV2();
        case 'cfg': return this.settingsPageV2();
        default: return this.dbV2();
      }
    };

    o.v2SkeletonDashboard = function () {
      var cards = '';
      for (var i = 0; i < 8; i++) cards += '<div class="skeleton skeleton-kpi"></div>';
      return '<div class="ph"><div><h1>Dashboard</h1><p>Cargando datos…</p></div></div>'
        + '<div class="kpi-grid">' + cards + '</div>'
        + '<div class="grid-2"><div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div></div>';
    };

    /* ═══════════════════════════════════════════════════════════════
       DASHBOARD
       ═══════════════════════════════════════════════════════════════ */
    o.dbV2 = function () {
      var a = this.analytics || {};
      var k = a.kpis || {};
      var l = this.live || { rooms: [], workers_inside: [], alerts: [], counts: {} };
      var act = a.activity_series || [];
      var labels = act.map(function (x) { return x.label; });
      var occ = a.occupancy_series || [];
      var occLabels = occ.map(function (x) { return String(x.date).slice(5); });
      var occValues = occ.map(function (x) { return x.occupancy_pct; });

      var statusItems = (a.status_distribution || []).map(function (s) {
        var colors = { FREE: '#16a34a', OCCUPIED: '#2563eb', OVERSTAY: '#dc2626', RESERVED: '#d97706', CLEANING: '#d97706', OUT_OF_SERVICE: '#64748b' };
        return { label: s.label, value: s.count, color: colors[s.status] || '#94a3b8' };
      });

      var kpis = ''
        + this.v2Kpi('Ocupación', F.pct(k.occupancy_pct || 0), (k.rooms_occupied || 0) + ' de ' + (k.rooms_total || 0) + ' ocupadas', '#2563eb', '🏨')
        + this.v2Kpi('Personas dentro', k.workers_inside || 0, 'trabajadores ahora', '#16a34a', '👤')
        + this.v2Kpi('Accesos hoy', F.number(k.accesses_today || 0), (k.denied_today || 0) + ' denegados', '#0ea5e9', '🚪')
        + this.v2Kpi('Escaneos hoy', F.number(k.scans_today || 0), 'QR + fichas', '#7c3aed', '📷')
        + this.v2Kpi('Anomalías abiertas', k.anomalies_open || 0, 'requieren revisión', '#dc2626', '⚠️')
        + this.v2Kpi('Estancia media', F.duration(k.avg_stay_minutes || 0), 'en el periodo', '#d97706', '⏱️')
        + this.v2Kpi('Visitas almacén', k.warehouse_visits_today || 0, 'hoy', '#0d9488', '📦')
        + this.v2Kpi('Dispositivos', (k.devices_online || 0) + '/' + ((k.devices_online || 0) + (k.devices_offline || 0) + (k.devices_unknown || 0)), (k.devices_offline || 0) + ' offline · ' + (k.devices_unknown || 0) + ' sin verificar', '#64748b', '🔌');

      var alerts = this.v2Alerts(a, l);

      var occupancyCard = this.v2Card('Ocupación ahora', 'Distribución por estado', ''
        + '<div class="donut-wrap">' + C.donut(statusItems, { size: 180, stroke: 18, centerLabel: F.pct(k.occupancy_pct || 0, 0), centerSub: 'ocupación', aria: 'Distribución de habitaciones por estado' }) + '</div>');

      var trendCard = this.v2Card('Tendencia de ocupación', 'Últimos ' + this.analyticsDays + ' días', ''
        + C.line(occLabels, [{ name: 'Ocupación %', values: occValues, color: '#2563eb' }], { height: 190, area: true, legend: false, aria: 'Evolución de la ocupación' }));

      var activityCard = this.v2Card('Actividad', 'Entradas, salidas y accesos', ''
        + C.bars(labels, [
          { name: 'Entradas', values: act.map(function (x) { return x.entries; }), color: '#2563eb' },
          { name: 'Salidas', values: act.map(function (x) { return x.exits; }), color: '#93c5fd' },
          { name: 'Accesos', values: act.map(function (x) { return x.accesses; }), color: '#0ea5e9' }
        ], { height: 200, aria: 'Actividad por periodo' }), '<button class="btn btn-ghost btn-sm" @click="nav(\'ana\')">Ver analítica →</button>');

      var roomTiles = (l.rooms || []).map(function (r) {
        var m = F.statusMeta(r.status);
        return '<div class="room-tile" style="--rc:' + m[1] + '" @click="openRoomDrawer(' + r.id + ')">'
          + '<div class="rt-code">' + F.escape(r.code) + '</div>'
          + '<div class="rt-type">' + F.escape(r.room_type || '—') + '</div>'
          + '<div class="rt-foot"><span class="rt-status">' + F.escape(m[0]) + '</span>'
          + '<span class="rt-people">' + (r.workers_inside ? '👤 ' + r.workers_inside : '') + '</span></div></div>';
      }).join('');
      var roomsCard = this.v2Card('Mapa de habitaciones', (l.rooms || []).length + ' salas · clic para ver detalle', ''
        + (roomTiles ? '<div class="room-grid">' + roomTiles + '</div>' : '<div class="table-empty">Sin habitaciones</div>'),
        '<button class="btn btn-o btn-sm" @click="nav(\'ro\')">Ver todas</button>');

      var liveRows = (l.workers_inside || []).map(function (w) {
        return '<div class="live-item">' + this.v2Avatar(w.worker_name)
          + '<div class="li-main"><strong>' + F.escape(w.worker_name) + '</strong>'
          + '<small>' + F.escape(w.room_code) + ' · ' + F.escape(w.role || 'Sin rol') + '</small></div>'
          + '<span class="li-time" data-live-entered="' + F.escape(w.entered_at) + '">' + F.duration(w.duration_minutes) + '</span></div>';
      }.bind(this)).join('');
      var liveCard = this.v2Card('Ahora mismo', 'Personas dentro de las salas', ''
        + (liveRows ? '<div class="live-list">' + liveRows + '</div>' : '<div class="table-empty">Nadie dentro ahora</div>'),
        '<span class="badge badge-live"><i></i>En vivo</span>');

      var topWorkers = this.v2Card('Trabajadores más activos', 'Sesiones en el periodo', this.v2Rank(a.top_workers || [], 'name', 'sessions', 'openWorkerDrawer'));
      var topRooms = this.v2Card('Habitaciones con más actividad', 'Sesiones + accesos', this.v2Rank(a.top_rooms || [], 'code', 'accesses', 'openRoomDrawer'));

      var access = a.access_summary || { ok: 0, fail: 0, success_rate: 0 };
      var accessCard = this.v2Card('Accesos', 'Concedidos vs denegados', ''
        + '<div class="donut-wrap">' + C.donut([
          { label: 'Concedidos', value: access.ok || 0, color: '#16a34a' },
          { label: 'Denegados', value: access.fail || 0, color: '#dc2626' }
        ], { size: 150, stroke: 16, centerLabel: F.pct(access.success_rate || 0, 0), centerSub: 'éxito', legend: false })
        + '<div class="stack" style="flex:1;min-width:120px">'
        + '<div class="stat-line"><span>Concedidos</span><span style="color:#16a34a">' + F.number(access.ok) + '</span></div>'
        + '<div class="stat-line"><span>Denegados</span><span style="color:#dc2626">' + F.number(access.fail) + '</span></div>'
        + '<div class="stat-line"><span>Tasa de éxito</span><span>' + F.pct(access.success_rate) + '</span></div>'
        + '</div></div>');

      var anomalyItems = (a.anomaly_summary || []).map(function (s) {
        var colors = { CRITICAL: '#b91c1c', HIGH: '#dc2626', MEDIUM: '#d97706', LOW: '#64748b' };
        return { label: AI.severityLabel(s.severity), value: s.count, color: colors[s.severity] || '#64748b' };
      });
      var anomalyCard = this.v2Card('Anomalías abiertas', 'Por severidad', ''
        + (anomalyItems.some(function (x) { return x.value > 0; })
          ? '<div class="donut-wrap">' + C.donut(anomalyItems, { size: 150, stroke: 16, centerLabel: String(k.anomalies_open || 0), centerSub: 'abiertas', legend: false })
          + '<div class="stack" style="flex:1;min-width:120px">' + anomalyItems.map(function (x) {
            return '<div class="stat-line"><span>' + F.escape(x.label) + '</span><span>' + x.value + '</span></div>';
          }).join('') + '</div></div>'
          : '<div class="table-empty">Sin anomalías abiertas 🎉</div>'),
        '<button class="btn btn-o btn-sm" @click="nav(\'an\')">Revisar</button>');

      var w = a.warehouse || {};
      var warehouseCard = this.v2Card('Almacén', 'Visitas del periodo', ''
        + '<div class="row" style="gap:18px;flex-wrap:wrap">'
        + '<div><div class="kpi-label">Visitas</div><div class="kpi-value" style="font-size:22px">' + F.number(w.visits || 0) + '</div></div>'
        + '<div><div class="kpi-label">Entradas</div><div class="kpi-value" style="font-size:22px;color:#16a34a">' + F.number(w.entered || 0) + '</div></div>'
        + '<div><div class="kpi-label">Sin entrada</div><div class="kpi-value" style="font-size:22px;color:#64748b">' + F.number(w.no_show || 0) + '</div></div>'
        + '<div><div class="kpi-label">Grabaciones</div><div class="kpi-value" style="font-size:22px;color:#7c3aed">' + F.number(w.recordings || 0) + '</div></div>'
        + '</div>', '<button class="btn btn-o btn-sm" @click="nav(\'mon\')">Ver monitor</button>');

      var quick = this.v2Card('Acciones rápidas', '', ''
        + '<div class="quick-actions">'
        + '<button class="quick-action" @click="nav(\'qr\')"><span class="qa-ic">🎫</span><strong>Gestionar QRs</strong><small>Emitir y revisar</small></button>'
        + '<button class="quick-action" @click="nav(\'w\')"><span class="qa-ic">👤</span><strong>Trabajadores</strong><small>Historial y accesos</small></button>'
        + '<button class="quick-action" @click="nav(\'ro\')"><span class="qa-ic">🚪</span><strong>Habitaciones</strong><small>Estado en vivo</small></button>'
        + '<button class="quick-action" @click="nav(\'ana\')"><span class="qa-ic">📊</span><strong>Analítica</strong><small>Gráficas y tendencias</small></button>'
        + '<button class="quick-action" @click="nav(\'an\')"><span class="qa-ic">⚠️</span><strong>Anomalías</strong><small>Revisar incidencias</small></button>'
        + '<button class="quick-action" @click="refreshAll()"><span class="qa-ic">↻</span><strong>Actualizar</strong><small>Datos locales</small></button>'
        + '</div>');

      return '<div class="ph"><div><h1>Hola, ' + F.escape((this.user && (this.user.display_name || this.user.username)) || '') + '</h1>'
        + '<p>Resumen de los últimos ' + this.analyticsDays + ' días · actualizado ' + F.relative(a.generated_at) + '</p></div>'
        + '<div class="page-actions"><span class="badge badge-live"><i></i>En vivo</span>'
        + '<button class="btn btn-o btn-sm" title="Exportar gráfica PNG" @click="exportChartPng()">PNG</button>'
        + '<button class="btn btn-o btn-sm" @click="refreshAll()">↻ Actualizar</button></div></div>'
        + alerts
        + '<div class="kpi-grid">' + kpis + '</div>'
        + '<div class="grid-12">'
        + '<div class="col-4">' + occupancyCard + '</div>'
        + '<div class="col-8">' + activityCard + '</div>'
        + '<div class="col-7">' + roomsCard + '</div>'
        + '<div class="col-5">' + liveCard + '</div>'
        + '<div class="col-6">' + trendCard + '</div>'
        + '<div class="col-6">' + accessCard + '</div>'
        + '<div class="col-4">' + topWorkers + '</div>'
        + '<div class="col-4">' + topRooms + '</div>'
        + '<div class="col-4">' + anomalyCard + '</div>'
        + '<div class="col-6">' + warehouseCard + '</div>'
        + '<div class="col-6">' + quick + '</div>'
        + '</div>';
    };

    o.v2Alerts = function (a, l) {
      var k = a.kpis || {};
      var out = [];
      if ((k.anomalies_open || 0) > 0) {
        out.push('<div class="alert danger"><span>⚠️</span><span><b>' + k.anomalies_open + '</b> anomalías abiertas requieren revisión.</span><button class="btn btn-o btn-sm" @click="nav(\'an\')">Revisar</button></div>');
      }
      if ((k.devices_offline || 0) > 0) {
        out.push('<div class="alert"><span>🔌</span><span><b>' + k.devices_offline + '</b> dispositivo(s) offline.</span><button class="btn btn-o btn-sm" @click="nav(\'dv\')">Ver dispositivos</button></div>');
      }
      if ((k.low_battery || 0) > 0) {
        out.push('<div class="alert"><span>🔋</span><span><b>' + k.low_battery + '</b> dispositivo(s) con batería baja.</span><button class="btn btn-o btn-sm" @click="nav(\'dv\')">Ver batería</button></div>');
      }
      (l.alerts || []).forEach(function (al) {
        if (al.type === 'door_open') {
          out.push('<div class="alert info"><span>🚪</span><span><b>' + al.text + '</b>.</span><button class="btn btn-o btn-sm" @click="nav(\'ro\')">Ver salas</button></div>');
        }
      });
      return out.length ? '<div class="alert-strip">' + out.join('') + '</div>' : '';
    };

    /* ═══════════════════════════════════════════════════════════════
       ANALÍTICA
       ═══════════════════════════════════════════════════════════════ */
    o.analyticsPageV2 = function () {
      var tabs = [['resumen', 'Resumen'], ['workers', 'Trabajadores'], ['rooms', 'Habitaciones'], ['accesses', 'Accesos'], ['anomalies', 'Anomalías'], ['devices', 'Dispositivos'], ['warehouse', 'Almacén']];
      var tabBar = '<div class="tabs" role="tablist" aria-label="Secciones de analítica">' + tabs.map(function (t) {
        var act = this.analyticsTab === t[0];
        return '<button type="button" role="tab" aria-selected="' + (act ? 'true' : 'false') + '" class="tab' + (act ? ' act' : '') + '" @click="analyticsTab=\'' + t[0] + '\';loadAnalyticsV2();loadWorkersAnalyticsV2();loadRoomsAnalyticsV2();loadAccessAnalyticsV2();loadAnomalyAnalyticsV2();loadDeviceAnalyticsV2();loadWarehouseAnalyticsV2()">' + t[1] + '</button>';
      }.bind(this)).join('') + '</div>';

      var body = '';
      if (this.analyticsTab === 'workers') body = this.v2AnalyticsWorkers();
      else if (this.analyticsTab === 'rooms') body = this.v2AnalyticsRooms();
      else if (this.analyticsTab === 'accesses') body = this.v2AnalyticsAccesses();
      else if (this.analyticsTab === 'anomalies') body = this.v2AnalyticsAnomalies();
      else if (this.analyticsTab === 'devices') body = this.v2AnalyticsDevices();
      else if (this.analyticsTab === 'warehouse') body = this.v2AnalyticsWarehouse();
      else body = this.v2AnalyticsOverview();

      return '<div class="ph"><div><h1>Analítica</h1><p>Explorador de uso · últimos ' + this.analyticsDays + ' días</p></div>'
        + '<div class="page-actions"><button class="btn btn-o btn-sm" title="Exportar gráfica PNG" @click="exportChartPng()">PNG</button>'
        + '<button class="btn btn-o btn-sm" title="Exportar tabla CSV" @click="exportCsv()">CSV</button>'
        + '<button class="btn btn-o btn-sm" @click="refreshAll()">↻ Actualizar</button></div></div>'
        + tabBar + body;
    };

    o.v2AnalyticsOverview = function () {
      var a = this.analytics || {};
      var act = a.activity_series || [];
      var access = a.access_summary || {};
      var k = a.kpis || {};
      return '<div class="kpi-grid">'
        + this.v2Kpi('Sesiones', F.number(k.sessions_today || 0), 'hoy', '#2563eb', '👤')
        + this.v2Kpi('Accesos', F.number(k.accesses_today || 0), 'hoy', '#0ea5e9', '🚪')
        + this.v2Kpi('Éxito de acceso', F.pct(access.success_rate || 0), (access.ok || 0) + ' OK / ' + (access.fail || 0) + ' KO', '#16a34a', '✅')
        + this.v2Kpi('Ocupación', F.pct(k.occupancy_pct || 0), (k.rooms_occupied || 0) + '/' + (k.rooms_total || 0), '#7c3aed', '🏨')
        + '</div>'
        + '<div class="grid-2">'
        + this.v2Card('Actividad', 'Entradas, salidas y accesos', C.bars(act.map(function (x) { return x.label; }), [
          { name: 'Entradas', values: act.map(function (x) { return x.entries; }), color: '#2563eb' },
          { name: 'Salidas', values: act.map(function (x) { return x.exits; }), color: '#93c5fd' },
          { name: 'Accesos', values: act.map(function (x) { return x.accesses; }), color: '#0ea5e9' }
        ], { height: 240 }))
        + this.v2Card('Ocupación', 'Evolución del periodo', C.line((a.occupancy_series || []).map(function (x) { return String(x.date).slice(5); }), [
          { name: 'Ocupación %', values: (a.occupancy_series || []).map(function (x) { return x.occupancy_pct; }), color: '#2563eb' }
        ], { height: 240, area: true, legend: false }))
        + '</div>'
        + '<div class="grid-2" style="margin-top:16px">'
        + this.v2Card('Top trabajadores', 'Por sesiones', this.v2Rank(a.top_workers || [], 'name', 'sessions', 'openWorkerDrawer'))
        + this.v2Card('Top habitaciones', 'Por accesos', this.v2Rank(a.top_rooms || [], 'code', 'accesses', 'openRoomDrawer'))
        + '</div>';
    };

    o.v2AnalyticsWorkers = function () {
      var d = this.workersAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var items = d.items || [];
      var rows = items.map(function (w) {
        return '<tr><td><div class="row">' + this.v2Avatar(w.name) + '<div><strong>' + F.escape(w.name) + '</strong><div class="small muted">' + F.escape(w.role || '—') + '</div></div></div></td>'
          + '<td>' + F.badge(w.status, w.status === 'INSIDE' ? 'Dentro' : 'Fuera') + '</td>'
          + '<td>' + (w.current_room ? '<span class="badge badge-soft">' + F.escape(w.current_room) + '</span>' : '<span class="muted">—</span>') + '</td>'
          + '<td>' + w.sessions + '</td><td>' + F.duration(w.minutes) + '</td><td>' + w.rooms_visited + '</td>'
          + '<td>' + w.keys_active + '</td><td>' + (w.denials ? '<span style="color:#dc2626">' + w.denials + '</span>' : '0') + '</td>'
          + '<td>' + F.relative(w.last_seen) + '</td>'
          + '<td><button class="btn btn-o btn-sm" @click="openWorkerDrawer(' + w.id + ')">Ver</button></td></tr>';
      }.bind(this)).join('');
      return '<div class="kpi-grid">'
        + this.v2Kpi('Trabajadores', items.length, 'en el filtro', '#2563eb', '👥')
        + this.v2Kpi('Dentro ahora', items.filter(function (x) { return x.status === 'INSIDE'; }).length, 'con sesión abierta', '#16a34a', '🟢')
        + this.v2Kpi('Sesiones', items.reduce(function (a, x) { return a + x.sessions; }, 0), 'en el periodo', '#0ea5e9', '🚪')
        + this.v2Kpi('Tiempo total', F.duration(items.reduce(function (a, x) { return a + x.minutes; }, 0)), 'acumulado', '#d97706', '⏱️')
        + '</div>'
        + '<div class="fbar"><input class="grow" type="search" placeholder="Buscar trabajador…" x-model="workerQ" @keydown.enter="loadWorkersAnalyticsV2()"><button class="btn btn-o" @click="loadWorkersAnalyticsV2()">Buscar</button></div>'
        + this.v2Table(['Trabajador', 'Estado', 'Sala actual', 'Sesiones', 'Tiempo', 'Salas', 'Llaves', 'Deneg.', 'Último'], rows, 'Sin trabajadores');
    };

    o.v2AnalyticsRooms = function () {
      var d = this.roomsAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var items = d.items || [];
      var rows = items.map(function (r) {
        return '<tr><td><strong>' + F.escape(r.code) + '</strong><div class="small muted">' + F.escape(r.room_type || '—') + '</div></td>'
          + '<td>' + F.badge(r.status) + '</td>'
          + '<td>' + (r.pack_name ? F.escape(r.pack_name) : '<span class="muted">—</span>') + '</td>'
          + '<td>' + r.sessions + '</td><td>' + r.accesses + '</td>'
          + '<td>' + (r.denied ? '<span style="color:#dc2626">' + r.denied + '</span>' : '0') + '</td>'
          + '<td>' + r.anomalies + '</td><td>' + r.visits + '</td>'
          + '<td>' + F.relative(r.last_access_at) + '</td>'
          + '<td><button class="btn btn-o btn-sm" @click="openRoomDrawer(' + r.id + ')">Ver</button></td></tr>';
      }.bind(this)).join('');
      var statusOpts = ['', 'FREE', 'OCCUPIED', 'OVERSTAY', 'RESERVED', 'CLEANING', 'OUT_OF_SERVICE'].map(function (s) {
        var m = s ? F.statusMeta(s) : ['Todos los estados'];
        return '<option value="' + s + '"' + (this.roomStatusFilter === s ? ' selected' : '') + '>' + F.escape(m[0]) + '</option>';
      }.bind(this)).join('');
      return '<div class="kpi-grid">'
        + this.v2Kpi('Habitaciones', items.length, 'en el filtro', '#2563eb', '🚪')
        + this.v2Kpi('Ocupadas', items.filter(function (x) { return x.status === 'OCCUPIED' || x.status === 'OVERSTAY'; }).length, 'ahora', '#16a34a', '🟢')
        + this.v2Kpi('Accesos', items.reduce(function (a, x) { return a + x.accesses; }, 0), 'en el periodo', '#0ea5e9', '📷')
        + this.v2Kpi('Anomalías', items.reduce(function (a, x) { return a + x.anomalies; }, 0), 'detectadas', '#dc2626', '⚠️')
        + '</div>'
        + '<div class="fbar"><input class="grow" type="search" placeholder="Buscar sala o tipo…" x-model="roomQ" @keydown.enter="loadRoomsAnalyticsV2()"><select x-model="roomStatusFilter" @change="loadRoomsAnalyticsV2()">' + statusOpts + '</select><button class="btn btn-o" @click="loadRoomsAnalyticsV2()">Aplicar</button></div>'
        + this.v2Table(['Sala', 'Estado', 'Pack', 'Sesiones', 'Accesos', 'Deneg.', 'Anomalías', 'Visitas', 'Último'], rows, 'Sin habitaciones');
    };

    o.v2AnalyticsAccesses = function () {
      var d = this.accessAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var s = d.summary || {};
      var series = d.series || [];
      var rows = (d.recent || []).slice(0, 40).map(function (e) {
        return '<tr><td class="small">' + F.dateTime(e.occurred_at) + '</td>'
          + '<td>' + (e.room_code ? '<span class="badge badge-soft">' + F.escape(e.room_code) + '</span>' : '—') + '</td>'
          + '<td>' + F.escape(e.type || '—') + '</td>'
          + '<td>' + (e.worker_name ? F.escape(e.worker_name) : '<span class="muted">—</span>') + '</td>'
          + '<td>' + (e.key_label ? F.escape(e.key_label) : '<span class="muted">—</span>') + '</td>'
          + '<td>' + F.badge(e.result) + '</td><td class="small muted">' + F.escape(e.reason || '') + '</td></tr>';
      });
      return '<div class="kpi-grid">'
        + this.v2Kpi('Total', F.number(s.total || 0), 'intentos', '#2563eb', '📜')
        + this.v2Kpi('Concedidos', F.number(s.ok || 0), F.pct(s.success_rate || 0), '#16a34a', '✅')
        + this.v2Kpi('Denegados', F.number(s.fail || 0), 'revisar motivos', '#dc2626', '⛔')
        + this.v2Kpi('Tasa de éxito', F.pct(s.success_rate || 0), 'global', '#0ea5e9', '📈')
        + '</div>'
        + '<div class="grid-2">'
        + this.v2Card('Evolución', 'Accesos y llaves', C.bars(series.map(function (x) { return x.label; }), [
          { name: 'Accesos', values: series.map(function (x) { return x.accesses; }), color: '#2563eb' },
          { name: 'Llaves', values: series.map(function (x) { return x.keys; }), color: '#0d9488' }
        ], { height: 230 }))
        + this.v2Card('Resultado', 'Concedidos vs denegados', '<div class="donut-wrap">' + C.donut([
          { label: 'Concedidos', value: s.ok || 0, color: '#16a34a' },
          { label: 'Denegados', value: s.fail || 0, color: '#dc2626' }
        ], { size: 170, stroke: 18, centerLabel: F.pct(s.success_rate || 0, 0), centerSub: 'éxito' }) + '</div>')
        + '</div>'
        + '<div class="grid-2" style="margin-top:16px">'
        + this.v2Card('Top llaves', 'Por uso', this.v2Rank((d.top_keys || []).map(function (k) { return { id: k.id, label: k.label, c: k.c }; }), 'label', 'c'))
        + this.v2Card('Top trabajadores', 'Por accesos con llave', this.v2Rank((d.top_workers || []).map(function (w) { return { id: w.id, name: w.name, c: w.c }; }), 'name', 'c', 'openWorkerDrawer'))
        + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Últimos accesos', 'Máximo 40', this.v2Table(['Fecha', 'Sala', 'Tipo', 'Trabajador', 'Llave', 'Resultado', 'Motivo'], rows, 'Sin accesos')) + '</div>';
    };

    o.v2AnalyticsAnomalies = function () {
      var d = this.anomalyAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var sev = d.by_severity || {};
      var sevItems = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].map(function (s) {
        var colors = { CRITICAL: '#b91c1c', HIGH: '#dc2626', MEDIUM: '#d97706', LOW: '#64748b' };
        return { label: s, value: sev[s] || 0, color: colors[s] };
      });
      var series = d.series || [];
      var rows = (d.recent || []).slice(0, 40).map(function (a) {
        return '<tr><td>#' + a.id + '</td><td><span class="badge badge-soft">' + F.escape(a.room_code || '—') + '</span></td>'
          + '<td>' + AI.cell(a.anomaly_type) + '</td><td>' + F.badge(a.severity, AI.severityLabel(a.severity)) + '</td>'
          + '<td>' + F.badge(a.status, AI.stateLabel(a.status)) + '</td><td class="small">' + F.dateTime(a.detected_at) + '</td></tr>';
      });
      return '<div class="kpi-grid">'
        + this.v2Kpi('Total', F.number((d.by_status ? Object.values(d.by_status).reduce(function (a, b) { return a + b; }, 0) : 0)), 'detectadas', '#2563eb', '⚠️')
        + this.v2Kpi('Abiertas', (d.by_status || {}).OPEN || 0, 'pendientes', '#dc2626', '🔴')
        + this.v2Kpi('Críticas', sev.CRITICAL || 0, 'prioridad máxima', '#b91c1c', '🚨')
        + this.v2Kpi('Tiempo medio', d.mtta_minutes != null ? F.duration(d.mtta_minutes) : '—', 'hasta reconocer', '#d97706', '⏱️')
        + '</div>'
        + '<div class="grid-2">'
        + this.v2Card('Evolución', 'Anomalías por día', C.bars(series.map(function (x) { return x.label; }), [{ name: 'Anomalías', values: series.map(function (x) { return x.value; }), color: '#dc2626' }], { height: 230, legend: false }))
        + this.v2Card('Severidad', 'Distribución', '<div class="donut-wrap">' + C.donut(sevItems, { size: 170, stroke: 18, centerLabel: String((d.by_status || {}).OPEN || 0), centerSub: 'abiertas' }) + '</div>')
        + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Últimas anomalías', 'Máximo 40', this.v2Table(['ID', 'Sala', 'Tipo', 'Severidad', 'Estado', 'Detectada'], rows, 'Sin anomalías')) + '</div>';
    };

    o.v2AnalyticsDevices = function () {
      var d = this.deviceAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var s = d.summary || {};
      var kindRows = (d.by_kind || []).map(function (k) {
        return '<tr><td><b>' + F.escape(k.kind) + '</b></td><td>' + k.c + '</td><td style="color:#16a34a">' + k.online + '</td><td style="color:#dc2626">' + k.offline + '</td><td class="muted">' + k.unknown + '</td></tr>';
      });
      var lowRows = (d.low_battery || []).map(function (b) {
        return '<tr><td>' + F.escape(b.label || b.kind) + '</td><td>' + F.escape(b.kind) + '</td><td>' + (b.room_code || '—') + '</td><td><b style="color:#dc2626">' + b.battery_pct + '%</b></td></tr>';
      });
      var packRows = (d.packs || []).map(function (p) {
        return '<tr><td><b>' + F.escape(p.name) + '</b><div class="small muted">' + F.escape(p.code) + '</div></td><td>' + (p.room_code || '—') + '</td><td>' + p.devices + '</td></tr>';
      });
      return '<div class="kpi-grid">'
        + this.v2Kpi('Dispositivos', s.total || 0, 'inventario', '#2563eb', '🔌')
        + this.v2Kpi('Online', s.online || 0, 'verificados', '#16a34a', '🟢')
        + this.v2Kpi('Offline', s.offline || 0, 'revisar', '#dc2626', '🔴')
        + this.v2Kpi('Sin verificar', s.unknown || 0, 'sin sonda', '#d97706', '🟠')
        + '</div>'
        + '<div class="grid-2">'
        + this.v2Card('Por tipo', 'Estado de conexión', this.v2Table(['Tipo', 'Total', 'Online', 'Offline', 'Sin verificar'], kindRows, 'Sin dispositivos'))
        + this.v2Card('Batería baja', '≤ 20%', this.v2Table(['Dispositivo', 'Tipo', 'Sala', 'Batería'], lowRows, 'Sin batería baja'))
        + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Packs', 'Dispositivos por pack', this.v2Table(['Pack', 'Sala', 'Dispositivos'], packRows, 'Sin packs')) + '</div>';
    };

    o.v2AnalyticsWarehouse = function () {
      var d = this.warehouseAnalytics;
      if (!d) return '<div class="skeleton skeleton-card"></div>';
      var trig = (d.by_trigger || []).map(function (x) { return { label: x.entry_trigger, value: x.c, color: x.entry_trigger === 'QR' ? '#2563eb' : (x.entry_trigger === 'DOOR' ? '#0d9488' : '#7c3aed') }; });
      var out = (d.by_outcome || []).map(function (x) { return { label: AI.stateLabel(x.outcome), value: x.c, color: x.outcome === 'ENTERED' ? '#16a34a' : (x.outcome === 'NO_SHOW' ? '#64748b' : '#d97706') }; });
      var rec = d.recordings || {};
      var recTotal = 0, recBytes = 0, recSeconds = 0;
      Object.keys(rec).forEach(function (k) { recTotal += rec[k].count; recBytes += rec[k].bytes; recSeconds += rec[k].seconds; });
      var series = d.series || [];
      var vs = d.visit_summary || {};
      var cam = d.cameras || {};
      var roomOpts = '<option value="">Todos los almacenes</option>' + (d.rooms || []).map(function (r) {
        return '<option value="' + r.id + '"' + (String(this.whRoomId) === String(r.id) ? ' selected' : '') + '>' + F.escape(r.code) + ' · ' + F.escape(r.room_type || '') + '</option>';
      }.bind(this)).join('');
      var rows = (d.recent || []).slice(0, 40).map(function (v) {
        return '<tr><td><button class="link-cell" @click="openWarehouseVisit(' + v.id + ')">#' + v.id + '</button></td>'
          + '<td>' + F.escape(v.room_code || '—') + '</td><td>' + F.escape(v.worker_name || 'Anónimo') + '</td>'
          + '<td>' + F.escape(v.entry_trigger) + '</td><td>' + F.badge(v.outcome, AI.stateLabel(v.outcome)) + '</td>'
          + '<td class="small">' + (v.entered_at ? F.dateTime(v.entered_at) : '—') + '</td>'
          + '<td>' + (v.recordings || 0) + ' · ' + F.bytes(v.recording_bytes || 0) + '</td>'
          + '<td class="small">' + F.dateTime(v.created_at) + '</td></tr>';
      });
      var recRows = Object.keys(rec).map(function (k) {
        return '<tr><td>' + F.badge(k === 'SAVED' ? 'SYNCED' : k, { RECORDING: 'Grabando', SAVED: 'Guardada', DISCARDED: 'Descartada', FAILED: 'Fallida', PENDING: 'Pendiente' }[k] || k) + '</td>'
          + '<td>' + rec[k].count + '</td><td>' + F.seconds(rec[k].seconds) + '</td><td>' + F.bytes(rec[k].bytes) + '</td></tr>';
      });
      var logs = (this.warehouseLogs && this.warehouseLogs.items) || [];
      var logRows = logs.slice(0, 120).map(function (e) {
        var applied = e.applied === null ? '<span class="muted">—</span>' : (e.applied ? '<span class="badge badge-ok">Aplicado</span>' : '<span class="badge badge-danger">Descartado</span>');
        return '<tr><td class="small">' + F.dateTime(e.occurred_at) + '</td>'
          + '<td>' + F.escape(e.room_code || '—') + '</td>'
          + '<td><span class="entity-chip">' + F.escape(e.kind) + '</span></td>'
          + '<td><b>' + F.escape(e.label || e.value || '—') + '</b>' + (e.source ? '<div class="small muted">' + F.escape(e.source) + '</div>' : '') + '</td>'
          + '<td>' + applied + '</td><td class="small muted">' + F.escape(e.discard_reason || '') + '</td></tr>';
      });
      return '<div class="fbar"><select x-model="whRoomId" @change="loadWarehouseAnalyticsV2()">' + roomOpts + '</select>'
        + '<button class="btn btn-o" @click="loadWarehouseAnalyticsV2()">Aplicar</button>'
        + '<a class="btn btn-o" href="' + (this.whRoomId ? ('/almacen?room=' + this.whRoomId) : '/almacen') + '" target="_blank" rel="noopener">Abrir /almacen</a></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Visitas', F.number(vs.total || 0), 'en el periodo', '#2563eb', '📦')
        + this.v2Kpi('Entradas', F.number(vs.entered || 0), 'confirmadas', '#16a34a', '✅')
        + this.v2Kpi('Ruido / sin entrada', F.number((vs.noise || 0) + (vs.no_show || 0)), 'descartadas', '#64748b', '🚫')
        + this.v2Kpi('Estancia media', vs.avg_seconds != null ? F.seconds(vs.avg_seconds) : '—', 'dentro', '#d97706', '⏱️')
        + this.v2Kpi('Cámaras online', (cam.online || 0) + '/' + (cam.total || 0), (cam.offline || 0) + ' offline · ' + (cam.unknown || 0) + ' sin verificar', '#0ea5e9', '🎥')
        + this.v2Kpi('Grabaciones', recTotal, F.bytes(recBytes), '#7c3aed', '🎬')
        + '</div>'
        + '<div class="grid-2">'
        + this.v2Card('Disparador', 'Cómo empezó la visita', '<div class="donut-wrap">' + C.donut(trig, { size: 170, stroke: 18 }) + '</div>')
        + this.v2Card('Resultado', 'Qué ocurrió', '<div class="donut-wrap">' + C.donut(out, { size: 170, stroke: 18 }) + '</div>')
        + '</div>'
        + '<div class="grid-2" style="margin-top:16px">'
        + this.v2Card('Visitas por día', 'Evolución', C.bars(series.map(function (x) { return x.label; }), [{ name: 'Visitas', values: series.map(function (x) { return x.value; }), color: '#0d9488' }], { height: 220, legend: false }))
        + this.v2Card('Grabaciones','Por estado (periodo)', this.v2Table(['Estado', 'Clips', 'Duración', 'Tamaño'], recRows, 'Sin grabaciones'))
        + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Visitas recientes', 'Máximo 40 · clic para ver y reproducir', this.v2Table(['ID', 'Sala', 'Trabajador', 'Disparador', 'Resultado', 'Entrada', 'Grabaciones', 'Inicio'], rows, 'Sin visitas')) + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Eventos físicos y decisiones',
          'Puerta, presencia y luz · sin payloads técnicos',
          '<div class="fbar"><select x-model="whLogKind" @change="loadWarehouseLogsV2()"><option value="">Todos los eventos</option><option value="door">Puerta</option><option value="presence">Presencia</option><option value="light">Luz</option></select>'
          + '<select x-model="whLogApplied" @change="loadWarehouseLogsV2()"><option value="">Aplicados y descartados</option><option value="1">Solo aplicados</option><option value="0">Solo descartados</option></select>'
          + '<button class="btn btn-o btn-sm" @click="loadWarehouseLogsV2()">Actualizar</button></div>'
          + this.v2Table(['Fecha', 'Sala', 'Tipo', 'Evento', 'Decisión', 'Motivo'], logRows, 'Sin eventos en el periodo')) + '</div>';
    };

    o.openWarehouseVisit = async function (id) {
      this.setHash('ana', { visit: id });
      this.drawer = { show: true, title: 'Visita #' + id, subtitle: 'Cargando…', content: '<div class="skeleton skeleton-card"></div>', footer: '' };
      try {
        var d = await this.api('/api/v1/analytics/warehouse/visits/' + id);
        var v = d.visit || {};
        this.drawer.title = 'Visita #' + id + ' · ' + (v.room_code || '');
        this.drawer.subtitle = (v.room_type || '') + ' · ' + AI.stateLabel(v.outcome || '') + ' · ' + F.escape(v.entry_trigger || '');
        var recs = (d.recordings || []).map(function (r) {
          return '<tr><td>' + F.escape(r.position || '—') + '</td><td>' + F.escape(r.episode || '—') + '</td>'
            + '<td>' + F.badge(r.status === 'SAVED' ? 'SYNCED' : r.status, { RECORDING: 'Grabando', SAVED: 'Guardada', DISCARDED: 'Descartada', FAILED: 'Fallida', PENDING: 'Pendiente' }[r.status] || r.status) + '</td>'
            + '<td>' + (r.duration_s != null ? F.seconds(r.duration_s) : '—') + '</td><td>' + F.bytes(r.size_bytes || 0) + '</td>'
            + '<td class="small">' + F.dateTime(r.requested_at) + '</td><td class="small muted">' + F.escape(r.error || '') + '</td></tr>';
        });
        this.drawer.content = '<div class="kpi-grid">'
          + this.v2Kpi('Sala', F.escape(v.room_code || '—'), v.room_type || '', '#2563eb', '🚪')
          + this.v2Kpi('Trabajador', F.escape(v.worker_name || 'Anónimo'), v.worker_session_id ? ('Sesión #' + v.worker_session_id) : 'sin sesión', '#7c3aed', '👤')
          + this.v2Kpi('Entrada', v.entered_at ? F.dateTime(v.entered_at) : '—', v.entry_trigger || '', '#16a34a', '➡️')
          + this.v2Kpi('Salida', v.exited_at ? F.dateTime(v.exited_at) : '—', v.outcome || '', '#0ea5e9', '⬅️')
          + '</div>'
          + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Grabaciones</h3><p>' + (d.recordings || []).length + ' clips</p></div><div class="card-body">'
          + this.v2Table(['Cámara', 'Episodio', 'Estado', 'Duración', 'Tamaño', 'Solicitada', 'Error'], recs, 'Sin grabaciones registradas') + '</div></div>';
        this.drawer.footer = '<button class="btn btn-o" @click="drawer.show=false">Cerrar</button>'
          + '<a class="btn btn-p" href="/almacen?room=' + (v.room_id || '') + '" target="_blank" rel="noopener">Abrir /almacen</a>';
      } catch (e) {
        this.drawer.content = '<div class="empty"><h2>No se pudo cargar</h2><p>' + F.escape(e.message) + '</p></div>';
      }
    };

    /* ═══════════════════════════════════════════════════════════════
       TRABAJADORES
       ═══════════════════════════════════════════════════════════════ */
    o.workersPageV2 = function () {
      var d = this.workersAnalytics;
      var items = d ? (d.items || []) : (this.workers || []).map(function (w) {
        return {
          id: w.id, name: w.name, role: (w.role || {}).name, active: w.active,
          status: w.current_room ? 'INSIDE' : 'OUTSIDE',
          current_room: w.current_room ? w.current_room.code : null,
          sessions: w.sessions_today || 0, minutes: 0, rooms_visited: 0,
          keys_active: 0, denials: 0, last_seen: w.last_access_at
        };
      });
      var rows = items.map(function (w) {
        return '<tr><td><div class="row">' + this.v2Avatar(w.name) + '<div><strong>' + F.escape(w.name) + '</strong><div class="small muted">' + F.escape(w.role || 'Sin rol') + '</div></div></div></td>'
          + '<td>' + F.badge(w.active === false ? 'DISABLED' : w.status, w.active === false ? 'Inactivo' : (w.status === 'INSIDE' ? 'Dentro' : 'Fuera')) + '</td>'
          + '<td>' + (w.current_room ? '<span class="badge badge-soft">' + F.escape(w.current_room) + '</span>' : '<span class="muted">—</span>') + '</td>'
          + '<td>' + (w.sessions || 0) + '</td><td>' + F.duration(w.minutes || 0) + '</td>'
          + '<td>' + (w.rooms_visited || 0) + '</td><td>' + (w.keys_active || 0) + '</td>'
          + '<td>' + (w.denials ? '<span style="color:#dc2626">' + w.denials + '</span>' : '0') + '</td>'
          + '<td class="small">' + F.relative(w.last_seen) + '</td>'
          + '<td style="white-space:nowrap"><button class="btn btn-o btn-sm" @click="openWorkerDrawer(' + w.id + ')">Ver</button> '
          + '<button class="btn btn-o btn-sm" title="Llaves" @click="openWorkerKeys(' + w.id + ')">🔑</button> '
          + '<button class="btn btn-o btn-sm" @click="openWorkerForm(' + w.id + ')">Editar</button></td></tr>';
      }.bind(this)).join('');
      return '<div class="ph"><div><h1>Trabajadores</h1><p>' + items.length + ' personas · historial por sala y accesos</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openWorkerForm()">+ Nuevo trabajador</button></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', items.length, 'personas', '#2563eb', '👥')
        + this.v2Kpi('Dentro', items.filter(function (x) { return x.status === 'INSIDE'; }).length, 'ahora', '#16a34a', '🟢')
        + this.v2Kpi('Sesiones', items.reduce(function (a, x) { return a + (x.sessions || 0); }, 0), 'en el periodo', '#0ea5e9', '🚪')
        + this.v2Kpi('Tiempo', F.duration(items.reduce(function (a, x) { return a + (x.minutes || 0); }, 0)), 'acumulado', '#d97706', '⏱️')
        + '</div>'
        + '<div class="fbar"><input class="grow" type="search" placeholder="Buscar por nombre…" x-model="workerQ" @keydown.enter="loadWorkersAnalyticsV2()"><button class="btn btn-o" @click="loadWorkersAnalyticsV2()">Buscar</button>'
        + (this.workerAlerts && this.workerAlerts.length ? '<span class="badge badge-danger">' + this.workerAlerts.length + ' alertas</span>' : '') + '</div>'
        + (this.workerAlerts && this.workerAlerts.length ? '<div class="alert-strip">' + this.workerAlerts.map(function (al) { return '<div class="alert danger">' + al.msg + '</div>'; }).join('') + '</div>' : '')
        + this.v2Table(['Trabajador', 'Estado', 'Sala actual', 'Sesiones', 'Tiempo', 'Salas', 'Llaves', 'Deneg.', 'Último', ''], rows, 'Sin trabajadores');
    };

    o.openWorkerDrawer = async function (id) {
      this.drawer = { show: true, title: 'Trabajador', subtitle: 'Cargando…', content: '<div class="skeleton skeleton-card"></div>', footer: '' };
      try {
        var d = await this.api('/api/v1/analytics/workers/' + id + '?days=' + this.analyticsDays);
        this.workerDetailData = d;
        this.drawer.title = d.worker.name;
        this.drawer.subtitle = (d.worker.role || 'Sin rol') + ' · ' + (d.worker.active ? 'Activo' : 'Inactivo');
        this.drawer.content = this.renderWorkerDrawer(d);
        this.drawer.footer = '<button class="btn btn-o" @click="drawer.show=false">Cerrar</button>'
          + '<button class="btn btn-o" @click="openWorkerKeys(' + id + ')">🔑 Llaves</button>'
          + '<button class="btn btn-p" @click="openWorkerForm(' + id + ')">Editar</button>';
      } catch (e) {
        this.drawer.content = '<div class="empty"><h2>No se pudo cargar</h2><p>' + F.escape(e.message) + '</p></div>';
      }
    };

    o.renderWorkerDrawer = function (d) {
      var t = d.totals || {};
      var daily = (d.daily || []).map(function (x) { return x.value.sessions; });
      var dailyLabels = (d.daily || []).map(function (x) { return x.label; });
      var perRoom = (d.per_room || []).map(function (r) { return { id: r.room_id, label: r.room_code, minutes: r.minutes, sessions: r.sessions }; });
      var sessions = (d.sessions || []).slice(0, 30).map(function (s) {
        return '<tr><td><button class="link-cell" @click="openRoomDrawer(' + s.room_id + ')"><b>' + F.escape(s.room_code || '—') + '</b></button></td><td class="small">' + F.dateTime(s.entered_at) + '</td>'
          + '<td class="small">' + (s.exited_at ? F.dateTime(s.exited_at) : '<span class="badge badge-live"><i></i>Activo</span>') + '</td>'
          + '<td>' + F.duration(s.duration_minutes) + '</td><td class="small muted">' + F.escape(s.exit_kind || '—') + '</td></tr>';
      });
      var keys = (d.keys || []).map(function (k) {
        return '<tr><td>' + F.escape(k.label) + '</td><td>' + F.escape(k.key_type) + '</td><td>' + F.badge(k.status) + '</td>'
          + '<td class="small">' + F.date(k.valid_until) + '</td><td>' + k.uses + '</td><td>' + (k.denials || 0) + '</td></tr>';
      });
      var keyEvents = (d.key_events || []).slice(0, 12).map(function (e) {
        return '<tr><td class="small">' + F.dateTime(e.occurred_at) + '</td><td>' + F.escape(e.room_code || '—') + '</td>'
          + '<td>' + F.escape(e.key_label || '—') + '</td><td>' + F.badge(e.result) + '</td><td class="small muted">' + F.escape(e.reason || '') + '</td></tr>';
      });
      var visits = (d.visits || []).map(function (v) {
        return '<tr><td><button class="link-cell" @click="openWarehouseVisit(' + v.id + ')">#' + v.id + '</button></td>'
          + '<td>' + F.escape(v.room_code || '—') + '</td><td>' + F.escape(v.entry_trigger) + '</td>'
          + '<td>' + F.badge(v.outcome, AI.stateLabel(v.outcome)) + '</td><td>' + (v.recordings || 0) + '</td>'
          + '<td class="small">' + F.dateTime(v.created_at) + '</td></tr>';
      });
      var heat = d.heatmap || {};
      var matrix = [1, 2, 3, 4, 5, 6, 7].map(function (dw) {
        var row = [];
        for (var h = 0; h < 24; h++) row.push((heat[dw] || {})[h] || 0);
        return row;
      });
      return '<div class="kpi-grid">'
        + this.v2Kpi('Sesiones', t.sessions || 0, 'en el periodo', '#2563eb', '🚪')
        + this.v2Kpi('Tiempo', F.duration(t.minutes || 0), 'acumulado', '#d97706', '⏱️')
        + this.v2Kpi('Salas', t.rooms_visited || 0, 'distintas', '#0d9488', '🚪')
        + this.v2Kpi('Denegaciones', t.denials || 0, 'con llave', '#dc2626', '⛔')
        + '</div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Actividad diaria</h3></div><div class="card-body">'
        + C.bars(dailyLabels, [{ name: 'Sesiones', values: daily, color: '#2563eb' }], { height: 190, legend: false })
        + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Salas visitadas</h3></div><div class="card-body">'
        + this.v2Rank(perRoom, 'label', 'minutes') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Mapa de calor</h3><p>Entradas por día y hora</p></div><div class="card-body">'
        + C.heatmap(matrix) + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Sesiones</h3><p>Últimas 30</p></div><div class="card-body">'
        + this.v2Table(['Sala', 'Entrada', 'Salida', 'Duración', 'Motivo'], sessions, 'Sin sesiones') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Llaves</h3></div><div class="card-body">'
        + this.v2Table(['Etiqueta', 'Tipo', 'Estado', 'Validez', 'Usos', 'Deneg.'], keys, 'Sin llaves') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Últimos accesos con llave</h3></div><div class="card-body">'
        + this.v2Table(['Fecha', 'Sala', 'Llave', 'Resultado', 'Motivo'], keyEvents, 'Sin accesos') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Visitas de almacén</h3><p>' + visits.length + ' visitas</p></div><div class="card-body">'
        + this.v2Table(['Visita', 'Sala', 'Disparador', 'Resultado', 'Grabaciones', 'Fecha'], visits, 'Sin visitas de almacén') + '</div></div>';
    };

    /* ═══════════════════════════════════════════════════════════════
       HABITACIONES
       ═══════════════════════════════════════════════════════════════ */
    o.roomsPageV2 = function () {
      var d = this.roomsAnalytics;
      var items = d ? (d.items || []) : (this.rooms || []).map(function (r) {
        var t = this.roomTypes.find(function (x) { return x.id === r.room_type_id; });
        return { id: r.id, code: r.code, status: r.status, room_type: t ? t.name : null, pack_name: null, sessions: 0, accesses: 0, denied: 0, anomalies: 0, visits: 0, last_access_at: null };
      }.bind(this));
      var statusOpts = ['', 'FREE', 'OCCUPIED', 'OVERSTAY', 'RESERVED', 'CLEANING', 'OUT_OF_SERVICE'].map(function (s) {
        var m = s ? F.statusMeta(s) : ['Todos los estados'];
        return '<option value="' + s + '"' + (this.roomStatusFilter === s ? ' selected' : '') + '>' + F.escape(m[0]) + '</option>';
      }.bind(this)).join('');
      var rows = items.map(function (r) {
        var panelUrl = this.panelUrlForRoom(r.id);
        return '<tr><td><button class="link-cell" @click="openRoomDrawer(' + r.id + ')"><strong>' + F.escape(r.code) + '</strong></button><div class="small muted">' + F.escape(r.room_type || '—') + '</div></td>'
          + '<td>' + F.badge(r.status) + '</td><td>' + (r.pack_name || '<span class="muted">—</span>') + '</td>'
          + '<td><button class="link-cell" @click="openRoomOccupations(' + r.id + ')">' + (r.sessions || 0) + '</button></td>'
          + '<td><button class="link-cell" @click="nav(\'ac\')">' + (r.accesses || 0) + '</button></td>'
          + '<td><button class="link-cell" @click="openRoomAnomalies(' + r.id + ')">' + (r.anomalies || 0) + '</button></td>'
          + '<td class="small">' + F.relative(r.last_access_at) + '</td>'
          + '<td><div class="row-actions">'
          + '<button class="btn btn-o btn-sm" @click="openRoomDrawer(' + r.id + ')">Ver</button> '
          + '<a class="btn btn-o btn-sm" href="' + panelUrl + '" target="_blank" rel="noopener">' + F.escape(this.panelLabelForRoom(r.id)) + '</a> '
          + '<a class="btn btn-o btn-sm" href="/sensores?room=' + r.id + '">Calibrar</a> '
          + '<button class="btn btn-o btn-sm" @click="openRoomForm(' + r.id + ')">Editar</button>'
          + '</div></td></tr>';
      }.bind(this)).join('');
      var tiles = (this.live && this.live.rooms ? this.live.rooms : []).map(function (r) {
        var m = F.statusMeta(r.status);
        return '<div class="room-tile" style="--rc:' + m[1] + '" @click="openRoomDrawer(' + r.id + ')"><div class="rt-code">' + F.escape(r.code) + '</div><div class="rt-type">' + F.escape(r.room_type || '') + '</div><div class="rt-foot"><span class="rt-status">' + F.escape(m[0]) + '</span><span class="rt-people">' + (r.workers_inside ? '👤' + r.workers_inside : '') + '</span></div></div>';
      }).join('');
      return '<div class="ph"><div><h1>Habitaciones</h1><p>' + items.length + ' salas · estado, ocupación y actividad</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openRoomForm()">+ Nueva sala</button></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', items.length, 'salas', '#2563eb', '🚪')
        + this.v2Kpi('Ocupadas', items.filter(function (x) { return x.status === 'OCCUPIED' || x.status === 'OVERSTAY'; }).length, 'ahora', '#16a34a', '🟢')
        + this.v2Kpi('Libres', items.filter(function (x) { return x.status === 'FREE'; }).length, 'disponibles', '#0ea5e9', '🔓')
        + this.v2Kpi('Anomalías', items.reduce(function (a, x) { return a + (x.anomalies || 0); }, 0), 'en el periodo', '#dc2626', '⚠️')
        + '</div>'
        + (tiles ? '<div class="card" style="margin-bottom:16px"><div class="card-head"><h3>Mapa en vivo</h3><p>Estado actual</p></div><div class="card-body"><div class="room-grid">' + tiles + '</div></div></div>' : '')
        + '<div class="fbar"><input class="grow" type="search" placeholder="Buscar sala o tipo…" x-model="roomQ" @keydown.enter="loadRoomsAnalyticsV2()"><select x-model="roomStatusFilter" @change="loadRoomsAnalyticsV2()">' + statusOpts + '</select><button class="btn btn-o" @click="loadRoomsAnalyticsV2()">Aplicar</button></div>'
        + this.v2Table(['Sala', 'Estado', 'Pack', 'Sesiones', 'Accesos', 'Anomalías', 'Último', ''], rows, 'Sin habitaciones');
    };

    o.openRoomOccupations = function (roomId) {
      this.nav('st');
      this.occupationFilters.room_id = String(roomId);
      this.loadOccupationsV2();
    };

    o.openRoomAnomalies = function (roomId) {
      this.nav('an');
      this.anomalyFilters.room_id = String(roomId);
      this.loadAnomalies();
    };

    o.openRoomDrawer = async function (id) {
      this.setHash('ro', { room: id });
      this.drawer = { show: true, title: 'Habitación', subtitle: 'Cargando…', content: '<div class="skeleton skeleton-card"></div>', footer: '' };
      try {
        var d = await this.api('/api/v1/analytics/rooms/' + id + '?days=' + this.analyticsDays);
        this.roomDetailData = d;
        this.drawer.title = 'Sala ' + d.room.code;
        this.drawer.subtitle = (d.room.room_type || '—') + ' · ' + F.statusMeta(d.room.status)[0];
        this.drawer.content = this.renderRoomDrawer(d);
        this.drawer.footer = '<button class="btn btn-o" @click="drawer.show=false">Cerrar</button>'
          + '<button class="btn btn-p" @click="openRoomForm(' + id + ')">Editar / pack</button>';
      } catch (e) {
        this.drawer.content = '<div class="empty"><h2>No se pudo cargar</h2><p>' + F.escape(e.message) + '</p></div>';
      }
    };

    o.renderRoomDrawer = function (d) {
      var r = d.room || {};
      var daily = d.daily || [];
      var sessions = (d.sessions || []).slice(0, 25).map(function (s) {
        return '<tr><td><b>' + F.escape(s.worker_name || '—') + '</b></td><td class="small">' + F.dateTime(s.entered_at) + '</td>'
          + '<td class="small">' + (s.exited_at ? F.dateTime(s.exited_at) : '<span class="badge badge-live"><i></i>Activo</span>') + '</td>'
          + '<td>' + F.duration(s.duration_minutes) + '</td></tr>';
      });
      var accesses = (d.accesses || []).slice(0, 25).map(function (a) {
        return '<tr><td class="small">' + F.dateTime(a.occurred_at) + '</td><td>' + F.escape(a.kind) + '</td><td>' + F.badge(a.result) + '</td><td class="small muted">' + F.escape(a.reason || '') + '</td></tr>';
      });
      var stays = (d.stays || []).slice(0, 15).map(function (s) {
        return '<tr><td>#' + s.id + '</td><td>' + F.badge(s.status) + '</td><td>' + F.duration(s.duracion_minutos) + '</td><td class="small">' + F.dateTime(s.reserved_at) + '</td><td class="small">' + (s.closed_at ? F.dateTime(s.closed_at) : '—') + '</td></tr>';
      });
      var visits = (d.visits || []).slice(0, 15).map(function (v) {
        return '<tr><td>#' + v.id + '</td><td>' + F.escape(v.entry_trigger) + '</td><td>' + F.badge(v.outcome) + '</td><td class="small">' + F.dateTime(v.created_at) + '</td></tr>';
      });
      var anomalies = (d.anomalies || []).slice(0, 15).map(function (a) {
        return '<tr><td>#' + a.id + '</td><td>' + F.escape(a.anomaly_type) + '</td><td>' + F.badge(a.severity) + '</td><td>' + F.badge(a.status) + '</td><td class="small">' + F.dateTime(a.detected_at) + '</td></tr>';
      });
      var devices = (d.devices || []).map(function (x) {
        var online = x.online_state === 1 ? 'online' : (x.online_state === 0 ? 'offline' : 'unknown');
        return '<tr><td><b>' + F.escape(x.label || x.kind) + '</b><div class="small muted">' + F.escape(x.external_id) + '</div></td>'
          + '<td>' + F.escape(x.kind) + '</td><td>' + F.badge(online) + '</td><td>' + (x.battery_pct != null ? x.battery_pct + '%' : '—') + '</td>'
          + '<td class="small">' + F.escape(x.transport || '—') + '</td><td class="small">' + F.escape(x.firmware_version || '—') + '</td></tr>';
      });
      return '<div class="kpi-grid">'
        + this.v2Kpi('Estado', F.statusMeta(r.status)[0], r.pack_name || 'Sin pack', F.statusMeta(r.status)[1], '🚪')
        + this.v2Kpi('Sesiones', (d.sessions || []).length, 'en el periodo', '#2563eb', '👤')
        + this.v2Kpi('Accesos', (d.accesses || []).length, 'en el periodo', '#0ea5e9', '📷')
        + this.v2Kpi('Anomalías', (d.anomalies || []).length, 'detectadas', '#dc2626', '⚠️')
        + '</div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Actividad diaria</h3></div><div class="card-body">'
        + C.bars(daily.map(function (x) { return x.label; }), [
          { name: 'Sesiones', values: daily.map(function (x) { return x.value.sessions; }), color: '#2563eb' },
          { name: 'Accesos', values: daily.map(function (x) { return x.value.accesses; }), color: '#0ea5e9' }
        ], { height: 190 })
        + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Sesiones de trabajadores</h3></div><div class="card-body">'
        + this.v2Table(['Trabajador', 'Entrada', 'Salida', 'Duración'], sessions, 'Sin sesiones') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Accesos</h3></div><div class="card-body">'
        + this.v2Table(['Fecha', 'Tipo', 'Resultado', 'Motivo'], accesses, 'Sin accesos') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Estancias</h3></div><div class="card-body">'
        + this.v2Table(['ID', 'Estado', 'Duración', 'Reservada', 'Cerrada'], stays, 'Sin estancias') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Visitas de almacén</h3></div><div class="card-body">'
        + this.v2Table(['ID', 'Disparador', 'Resultado', 'Fecha'], visits, 'Sin visitas') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Anomalías</h3></div><div class="card-body">'
        + this.v2Table(['ID', 'Tipo', 'Severidad', 'Estado', 'Detectada'], anomalies, 'Sin anomalías') + '</div></div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Dispositivos</h3></div><div class="card-body">'
        + this.v2Table(['Dispositivo', 'Tipo', 'Conexión', 'Batería', 'Transporte', 'Firmware'], devices, 'Sin dispositivos') + '</div></div>';
    };

    /* ═══════════════════════════════════════════════════════════════
       ESTANCIAS / QR / DEUDAS
       ═══════════════════════════════════════════════════════════════ */
    o.loadOccupationsV2 = async function () {
      var f = this.occupationFilters || {};
      var params = ['days=' + this.analyticsDays];
      if (f.kind) params.push('kind=' + encodeURIComponent(f.kind));
      if (f.status) params.push('status=' + encodeURIComponent(f.status));
      if (f.room_id) params.push('room_id=' + encodeURIComponent(f.room_id));
      if (f.q) params.push('q=' + encodeURIComponent(f.q));
      try { this.occupations = await this.api('/api/v1/analytics/occupations?' + params.join('&')); }
      catch (e) { this.occupations = null; }
    };

    o.stayPageV2 = function () {
      var d = this.occupations;
      var f = this.occupationFilters || {};
      var items = d ? (d.items || []) : [];
      var s = d ? (d.summary || {}) : {};
      var roomOpts = '<option value="">Todas las salas</option>' + (this.rooms || []).map(function (r) {
        return '<option value="' + r.id + '"' + (String(f.room_id) === String(r.id) ? ' selected' : '') + '>' + F.escape(r.code) + '</option>';
      }).join('');
      var rows = items.map(function (o) {
        var kindBadge = '<span class="entity-chip">' + (o.kind === 'worker' ? '👤 Empleado' : '🎫 Huésped') + '</span>';
        var person = o.kind === 'worker' ? F.escape(o.worker_name || '—') : '<span class="muted">Huésped</span>';
        var reserved = o.kind === 'worker' ? '<span class="muted">No aplica</span>' : F.duration(o.reserved_minutes);
        var real = o.real_minutes == null ? '<span class="muted">—</span>' : F.duration(o.real_minutes);
        return '<tr><td>' + kindBadge + '<div class="small muted">#' + o.id + '</div></td>'
          + '<td><button class="link-cell" @click="openRoomDrawer(' + o.room_id + ')"><strong>' + F.escape(o.room_code || '—') + '</strong></button>'
          + '<div class="small muted">' + F.escape(o.room_type || '') + '</div></td>'
          + '<td>' + person + '</td>'
          + '<td>' + F.badge(o.status, AI.stateLabel(o.status)) + '</td>'
          + '<td><span class="entity-chip">' + F.escape(this.accessMediumLabel(o.access_medium)) + '</span></td>'
          + '<td><div class="duration-pair"><span>Reservada: ' + reserved + '</span><small>Real: ' + real + '</small></div></td>'
          + '<td class="small">' + F.dateTime(o.entered_at || o.reserved_at) + '</td>'
          + '<td class="small">' + (o.exited_at ? F.dateTime(o.exited_at) : (o.status === 'INSIDE' ? '<span class="badge badge-live"><i></i>Dentro</span>' : '—')) + '</td>'
          + '<td><div class="row-actions"><button class="btn btn-o btn-sm" @click="openOccupationDrawer(\'' + o.kind + '\',' + o.id + ')">Expediente</button>'
          + (o.kind === 'stay' && o.status !== 'CLOSED' && o.status !== 'CANCELED' ? ' <button class="btn btn-o btn-sm" @click="closeStayV2(' + o.id + ')">Cerrar</button>' : '')
          + '</div></td></tr>';
      }.bind(this)).join('');

      return '<div class="ph"><div><h1>Estancias</h1><p>Huéspedes y empleados · duración reservada vs real · últimos ' + this.analyticsDays + ' días</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Ocupaciones', F.number(s.total || 0), 'en el periodo', '#2563eb', '⏱️')
        + this.v2Kpi('Huéspedes', F.number(s.stays || 0), 'estancias', '#0ea5e9', '🎫')
        + this.v2Kpi('Empleados', F.number(s.workers || 0), 'sesiones', '#7c3aed', '👤')
        + this.v2Kpi('Excedidas', F.number(s.overstay || 0), 'revisar', '#dc2626', '⚠️')
        + '</div>'
        + '<div class="fbar">'
        + '<input class="grow" type="search" placeholder="Buscar sala o empleado…" x-model="occupationFilters.q" @keydown.enter="loadOccupationsV2()">'
        + '<select x-model="occupationFilters.kind" @change="loadOccupationsV2()"><option value="">Todos los tipos</option><option value="stay">Huéspedes</option><option value="worker">Empleados</option></select>'
        + '<select x-model="occupationFilters.status" @change="loadOccupationsV2()"><option value="">Todos los estados</option><option value="RESERVED">Reservada</option><option value="OCCUPIED">Ocupada</option><option value="OVERSTAY">Excedida</option><option value="EXITED">Salida</option><option value="CLOSED">Cerrada</option><option value="CANCELED">Cancelada</option><option value="INSIDE">Dentro (empleado)</option><option value="OUTSIDE">Fuera (empleado)</option></select>'
        + '<select x-model="occupationFilters.room_id" @change="loadOccupationsV2()">' + roomOpts + '</select>'
        + '<button class="btn btn-o" @click="loadOccupationsV2()">Aplicar</button>'
        + '<button class="btn btn-o" @click="occupationFilters={kind:\'\',status:\'\',room_id:\'\',q:\'\'};loadOccupationsV2()">Limpiar</button>'
        + '</div>'
        + this.v2Table(['Ocupación', 'Sala', 'Persona', 'Estado', 'Acceso', 'Duración', 'Entrada', 'Salida', ''], rows, 'Sin ocupaciones en el periodo');
    };

    o.closeStayV2 = function (id) {
      var that = this;
      var occ = ((this.occupations && this.occupations.items) || []).find(function (x) { return x.kind === 'stay' && x.id === id; });
      var label = occ ? ('la estancia #' + id + ' de la sala ' + occ.room_code) : ('la estancia #' + id);
      if (!confirm('¿Cerrar ' + label + '?')) return;
      var key = 'stay-close-' + id + '-' + Date.now();
      this.api('/api/v1/stays/' + id + '/close', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key },
        body: JSON.stringify({ reason: 'checkout' })
      }).then(function () { that.loadOccupationsV2(); that.toastOk('Estancia cerrada'); })
        .catch(function (e) { that.toastErr(e.message); });
    };

    o.openOccupationDrawer = async function (kind, id) {
      this.setHash('st', kind === 'stay' ? { stay: id } : { occ: kind + ':' + id });
      this.drawer = { show: true, title: kind === 'worker' ? 'Sesión de empleado' : 'Estancia', subtitle: 'Cargando…', content: '<div class="skeleton skeleton-card"></div>', footer: '' };
      try {
        var d = await this.api('/api/v1/analytics/occupations/' + encodeURIComponent(kind) + '/' + id + '?days=' + this.analyticsDays);
        var o = d.occupation || {};
        this.drawer.title = kind === 'worker'
          ? ((o.worker_name || 'Empleado') + ' · ' + (o.room_code || ''))
          : ('Estancia #' + id + ' · ' + (o.room_code || ''));
        this.drawer.subtitle = (o.room_type || '') + ' · ' + AI.stateLabel(o.status || '');
        this.drawer.content = this.renderOccupationDrawer(d);
        this.drawer.footer = '<button class="btn btn-o" @click="drawer.show=false">Cerrar</button>'
          + '<button class="btn btn-o" @click="openRoomDrawer(' + (o.room_id || 0) + ')">Ver sala</button>';
      } catch (e) {
        this.drawer.content = '<div class="empty"><h2>No se pudo cargar</h2><p>' + F.escape(e.message) + '</p></div>';
      }
    };

    o.renderOccupationDrawer = function (d) {
      var o = d.occupation || {};
      var tl = (d.timeline || []).map(function (t) {
        return '<li><span class="tl-dot"></span><div class="tl-body"><strong>' + F.escape(t.label || '—') + '</strong>'
          + '<small>' + F.dateTime(t.at) + (t.detail ? ' · ' + F.escape(t.detail) : '') + '</small></div></li>';
      }).join('');
      var qr = (d.qr || []).map(function (q) {
        return '<tr><td class="mono small">' + F.escape(String(q.jti || '').slice(0, 14)) + '…</td>'
          + '<td>' + F.badge(q.revoked_at ? 'REVOKED' : (q.expires_at && F.toDate(q.expires_at) < new Date() ? 'EXPIRED' : (q.first_used_at ? 'ACTIVE' : 'PENDING')), q.first_used_at ? 'En uso' : 'Emitido') + '</td>'
          + '<td class="small">' + F.dateTime(q.issued_at) + '</td><td class="small">' + F.dateTime(q.valid_until || q.expires_at) + '</td></tr>';
      });
      var debts = (d.debts || []).map(function (x) {
        return '<tr><td>#' + x.id + '</td><td>' + x.exceso_minutos + ' min</td><td>' + F.badge(x.status, AI.stateLabel(x.status)) + '</td><td class="small">' + F.dateTime(x.created_at) + '</td></tr>';
      });
      var anomalies = (d.anomalies || []).map(function (x) {
        return '<tr><td>#' + x.id + '</td><td>' + AI.cell(x.anomaly_type) + '</td><td>' + F.badge(x.severity, AI.severityLabel(x.severity)) + '</td><td>' + F.badge(x.status, AI.stateLabel(x.status)) + '</td></tr>';
      });
      var keys = (d.keys || []).map(function (x) {
        return '<tr><td class="small">' + F.dateTime(x.occurred_at) + '</td><td>' + F.escape(x.credential_type || '—') + '</td><td>' + F.badge(x.result) + '</td><td class="small muted">' + F.escape(x.reason || '') + '</td></tr>';
      });
      var visits = (d.visits || []).map(function (x) {
        return '<tr><td><button class="link-cell" @click="openWarehouseVisit(' + x.id + ')">#' + x.id + '</button></td><td>' + F.escape(x.entry_trigger) + '</td><td>' + F.badge(x.outcome, AI.stateLabel(x.outcome)) + '</td><td class="small">' + F.dateTime(x.created_at) + '</td></tr>';
      });
      var reserved = o.reserved_minutes == null ? 'No aplica' : F.duration(o.reserved_minutes);
      var real = o.real_minutes == null ? '—' : F.duration(o.real_minutes);
      return '<div class="kpi-grid">'
        + this.v2Kpi('Estado', AI.stateLabel(o.status || ''), o.room_code || '', '#2563eb', '🚪')
        + this.v2Kpi('Duración reservada', reserved, o.kind === 'worker' ? 'sesión de empleado' : 'contratada', '#0ea5e9', '🗓️')
        + this.v2Kpi('Duración real', real, o.first_entry_at || o.entered_at ? 'desde la entrada' : 'sin entrada', '#d97706', '⏱️')
        + this.v2Kpi('Acceso', this.accessMediumLabel(o.access_medium || (d.kind === 'stay' ? 'QR_STAY' : 'UNKNOWN')), 'medio registrado', '#7c3aed', '🔑')
        + '</div>'
        + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Cronología</h3><p>Más reciente primero</p></div><div class="card-body">'
        + (tl ? '<ul class="timeline">' + tl + '</ul>' : '<p class="muted small">Sin eventos registrados.</p>') + '</div></div>'
        + (qr.length ? '<div class="card" style="margin-top:12px"><div class="card-head"><h3>QR de la estancia</h3></div><div class="card-body">' + this.v2Table(['JTI', 'Estado', 'Emitido', 'Válido hasta'], qr, '') + '</div></div>' : '')
        + (debts.length ? '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Deudas</h3></div><div class="card-body">' + this.v2Table(['ID', 'Exceso', 'Estado', 'Creada'], debts, '') + '</div></div>' : '')
        + (anomalies.length ? '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Anomalías</h3></div><div class="card-body">' + this.v2Table(['ID', 'Tipo', 'Severidad', 'Estado'], anomalies, '') + '</div></div>' : '')
        + (keys.length ? '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Accesos con llave</h3></div><div class="card-body">' + this.v2Table(['Fecha', 'Credencial', 'Resultado', 'Motivo'], keys, '') + '</div></div>' : '')
        + (visits.length ? '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Visitas de almacén</h3></div><div class="card-body">' + this.v2Table(['Visita', 'Disparador', 'Resultado', 'Fecha'], visits, '') + '</div></div>' : '');
    };

    o.qrState = function (q) {
      var now = new Date();
      var valid = q.valid_until ? F.toDate(q.valid_until) : null;
      var arrival = q.expires_at ? F.toDate(q.expires_at) : null;
      if (q.revoked_at) return { status: 'REVOKED', label: 'Revocado' };
      if (valid && valid < now) return { status: 'EXPIRED', label: 'Caducado' };
      if (!q.first_used_at && arrival && arrival < now) return { status: 'EXPIRED', label: 'Caducado para llegada' };
      if (q.first_used_at) return { status: 'ACTIVE', label: 'En uso' };
      return { status: 'RESERVED', label: 'Emitido · sin usar' };
    };

    o.qrPageV2 = function () {
      var qrs = this.qrs || [];
      var rows = qrs.map(function (q) {
        var st = this.qrState(q);
        return '<tr><td class="mono small">' + F.escape(String(q.jti || '').slice(0, 18)) + '…</td>'
          + '<td><button class="link-cell" @click="openRoomDrawer(' + q.room_id + ')"><strong>' + F.escape(q.room_code || ('#' + q.room_id)) + '</strong></button></td>'
          + '<td>' + (q.stay_id ? '<button class="link-cell" @click="openOccupationDrawer(\'stay\',' + q.stay_id + ')">#' + q.stay_id + '</button>' : '<span class="muted">—</span>') + '</td>'
          + '<td>' + F.badge(st.status, st.label) + '</td><td class="small">' + F.dateTime(q.issued_at) + '</td>'
          + '<td class="small">' + (q.valid_until ? F.dateTime(q.valid_until) : F.dateTime(q.expires_at)) + '</td>'
          + '<td><button class="btn btn-o btn-sm" @click="openOccupationDrawer(\'stay\',' + (q.stay_id || 0) + ')" ' + (q.stay_id ? '' : 'disabled') + '>Ver estancia</button></td></tr>';
      }.bind(this));
      var now = new Date();
      var active = qrs.filter(function (q) { return !q.revoked_at && (!q.valid_until || F.toDate(q.valid_until) >= now); }).length;
      return '<div class="ph"><div><h1>QRs</h1><p>' + qrs.length + ' credenciales emitidas</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', qrs.length, 'emitidos', '#2563eb', '🎫')
        + this.v2Kpi('Utilizables', active, 'no caducados ni revocados', '#16a34a', '🟢')
        + this.v2Kpi('En uso', qrs.filter(function (q) { return !!q.first_used_at && !q.revoked_at; }).length, 'primer uso registrado', '#0ea5e9', '✅')
        + this.v2Kpi('Revocados', qrs.filter(function (q) { return !!q.revoked_at; }).length, 'invalidados', '#dc2626', '⛔')
        + '</div>'
        + '<div class="fbar"><select @change="setQrFilter(\'room_id\',$event.target.value)">' + this.roomFilterOptions((this.qrFilters || {}).room_id) + '</select>'
        + '<select @change="setQrFilter(\'status\',$event.target.value)"><option value="">Todos los estados</option>'
        + '<option value="active"' + ((this.qrFilters || {}).status === 'active' ? ' selected' : '') + '>Utilizables</option>'
        + '<option value="consumed"' + ((this.qrFilters || {}).status === 'consumed' ? ' selected' : '') + '>Consumidos</option>'
        + '<option value="revoked"' + ((this.qrFilters || {}).status === 'revoked' ? ' selected' : '') + '>Revocados</option>'
        + '<option value="expired"' + ((this.qrFilters || {}).status === 'expired' ? ' selected' : '') + '>Caducados</option></select>'
        + '<input type="search" placeholder="Buscar JTI…" x-model="qrFilters.q" @keydown.enter="loadQrs()">'
        + '<button class="btn btn-o" @click="loadQrs()">Aplicar</button>'
        + '<button class="btn btn-o" @click="clearQrFilters()">Limpiar</button>'
        + '<span class="small muted">Máx. 200 resultados</span></div>'
        + this.v2Table(['JTI', 'Sala', 'Estancia', 'Estado', 'Emitido', 'Válido hasta', ''], rows, 'Sin QRs');
    };

    o.debtPageV2 = function () {
      var debts = this.debts || [];
      var labels = {
        PENDING_SYNC: 'Pendiente de envío',
        SYNCING: 'Enviando',
        SYNCED: 'Sincronizada',
        FAILED: 'Error de sincronización'
      };
      var retryable = function (st) { return st === 'PENDING_SYNC' || st === 'FAILED'; };
      var rows = debts.map(function (d) {
        return '<tr><td>#' + d.id + '</td>'
          + '<td>' + (d.stay_id ? '<button class="link-cell" @click="openOccupationDrawer(\'stay\',' + d.stay_id + ')">#' + d.stay_id + '</button>' : '<span class="muted">—</span>') + '</td>'
          + '<td><button class="link-cell" @click="openRoomDrawer(' + d.room_id + ')"><strong>' + F.escape(this.roomCodeById(d.room_id)) + '</strong></button></td>'
          + '<td><b>' + (d.exceso_minutos || 0) + ' min</b></td><td>' + (d.amount_eur_snapshot != null ? Number(d.amount_eur_snapshot).toFixed(2) + ' €' : '—') + '</td>'
          + '<td>' + F.badge(d.status, labels[d.status] || d.status) + '</td><td>' + (d.attempts || 0) + '</td>'
          + '<td class="small">' + F.dateTime(d.created_at) + (d.last_error ? '<div class="form-error">' + F.escape(d.last_error) + '</div>' : '') + '</td>'
          + '<td><div class="row-actions">' + (retryable(d.status)
            ? '<button class="btn btn-o btn-sm" @click="resyncDebt(' + d.id + ')">Reintentar</button>'
            : '<span class="muted small">No requiere acción</span>') + '</div></td></tr>';
      }.bind(this)).join('');
      var pending = debts.filter(function (d) { return d.status === 'PENDING_SYNC' || d.status === 'SYNCING'; }).length;
      return '<div class="ph"><div><h1>Deudas</h1><p>' + debts.length + ' registros de exceso</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', debts.length, 'deudas', '#2563eb', '💰')
        + this.v2Kpi('Pendientes', pending, 'de envío', '#d97706', '🟡')
        + this.v2Kpi('Sincronizadas', debts.filter(function (d) { return d.status === 'SYNCED'; }).length, 'en VB6', '#16a34a', '✅')
        + this.v2Kpi('Con error', debts.filter(function (d) { return d.status === 'FAILED'; }).length, 'revisar', '#dc2626', '⛔')
        + '</div>'
        + '<div class="fbar"><select @change="setDebtFilter(\'room_id\',$event.target.value)">' + this.roomFilterOptions((this.debtFilters || {}).room_id) + '</select>'
        + '<select @change="setDebtFilter(\'status\',$event.target.value)"><option value="">Todos los estados</option>'
        + Object.keys(labels).map(function (st) {
          return '<option value="' + st + '"' + ((this.debtFilters || {}).status === st ? ' selected' : '') + '>' + F.escape(labels[st]) + '</option>';
        }.bind(this)).join('')
        + '</select>'
        + '<input type="datetime-local" title="Desde" @change="setDebtFilter(\'from\',$event.target.value)" value="' + F.escape(this.dtLocalValue((this.debtFilters || {}).from)) + '">'
        + '<input type="datetime-local" title="Hasta" @change="setDebtFilter(\'to\',$event.target.value)" value="' + F.escape(this.dtLocalValue((this.debtFilters || {}).to)) + '">'
        + '<button class="btn btn-o" @click="clearDebtFilters()">Limpiar</button>'
        + '<span class="small muted">Máx. 200 resultados</span></div>'
        + this.v2Table(['ID', 'Estancia', 'Sala', 'Exceso', 'Importe', 'Estado', 'Intentos', 'Creada', ''], rows, 'Sin deudas');
    };

    o.resyncDebt = function (id) {
      var that = this;
      this.api('/api/v1/debts/' + id + '/resync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .then(function () { that.loadDebts(); that.toastOk('Deuda reencolada'); })
        .catch(function (e) { that.toastErr(e.message); });
    };

    o.copyText = function (text) {
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { }, function () { });
      this.toastOk('Copiado');
    };

    o.exportCsv = function () {
      var table = document.querySelector('.drawer .table-wrap table') || document.querySelector('.main table');
      if (!table) { this.toastErr('No hay tabla para exportar'); return; }
      var lines = [];
      table.querySelectorAll('tr').forEach(function (tr) {
        var cols = Array.prototype.slice.call(tr.querySelectorAll('th,td')).map(function (c) {
          return '"' + String(c.innerText || '').replace(/"/g, '""').replace(/\s+/g, ' ').trim() + '"';
        });
        if (cols.length) lines.push(cols.join(','));
      });
      if (!lines.length) { this.toastErr('La tabla está vacía'); return; }
      var blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'panel-' + this.p + '-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1500);
      this.toastOk('CSV exportado');
    };

    o.exportChartPng = function () {
      var svg = document.querySelector('.drawer svg.chart') || document.querySelector('.main svg.chart');
      if (!svg) { this.toastErr('No hay gráfica para exportar'); return; }
      var xml = new XMLSerializer().serializeToString(svg);
      var b64 = btoa(unescape(encodeURIComponent(xml)));
      var img = new Image();
      var that = this;
      img.onload = function () {
        var canvas = document.createElement('canvas');
        canvas.width = (img.width || 640) * 2;
        canvas.height = (img.height || 220) * 2;
        var ctx = canvas.getContext('2d');
        ctx.scale(2, 2);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0);
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'grafica-' + that.p + '-' + Date.now() + '.png';
        document.body.appendChild(a); a.click(); a.remove();
        that.toastOk('PNG exportado');
      };
      img.onerror = function () { that.toastErr('No se pudo generar el PNG'); };
      img.src = 'data:image/svg+xml;base64,' + b64;
    };

    /* ═══════════════════════════════════════════════════════════════
       LLAVES / NFC
       ═══════════════════════════════════════════════════════════════ */
    o.keysPageV2 = function () {
      var keys = this.accessKeys || [];
      var ka = this.keysAnalytics;
      var rows = keys.map(function (k) {
        return '<tr><td><b>' + F.escape(k.label) + '</b><div class="small muted">' + F.escape(this.keyOwner(k)) + '</div></td>'
          + '<td>' + (k.key_type === 'QR' ? '🎫 QR' : '💳 NFC') + '</td><td>' + F.escape(this.keyPerms(k)) + '</td>'
          + '<td class="small">' + F.dateTime(k.valid_until) + '</td><td>' + this.keyStatusBadge(k) + '</td>'
          + '<td style="white-space:nowrap"><button class="btn btn-o btn-sm" @click="openKeyDetail(' + k.id + ')">Ver</button> '
          + '<button class="btn btn-o btn-sm" @click="openKeyForm(' + k.id + ')" ' + (k.status !== 'ACTIVE' ? 'disabled' : '') + '>Editar</button> '
          + '<button class="btn btn-d btn-sm" @click="revokeKey(' + k.id + ')" ' + (k.status !== 'ACTIVE' ? 'disabled' : '') + '>Revocar</button></td></tr>';
      }.bind(this)).join('');
      return '<div class="ph"><div><h1>Llaves</h1><p>' + keys.length + ' llaves QR y fichas NFC</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openKeyForm()">+ Nueva llave</button></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', ka ? ka.counts.total : keys.length, 'llaves', '#2563eb', '🔑')
        + this.v2Kpi('Activas', ka ? ka.counts.active : keys.filter(function (k) { return (k.effective_status || k.status) === 'ACTIVE'; }).length, 'vigentes', '#16a34a', '🟢')
        + this.v2Kpi('Caducadas', ka ? ka.counts.expired : 0, 'revisar', '#d97706', '🟡')
        + this.v2Kpi('Revocadas', ka ? ka.counts.revoked : 0, 'anuladas', '#dc2626', '⛔')
        + '</div>'
        + this.v2Card('Uso de llaves', 'Top por accesos', ka ? this.v2Rank((ka.top_keys || []).map(function (k) { return { id: k.id, label: k.label, uses: k.uses }; }), 'label', 'uses') : '<div class="skeleton skeleton-card"></div>')
        + '<div class="fbar" style="margin-top:16px">'
        + '<input class="grow" type="search" placeholder="Buscar etiqueta, titular, ficha…" @keydown.enter="setKeyFilter(\'q\',$event.target.value)">'
        + '<select @change="setKeyFilter(\'key_type\',$event.target.value)"><option value="">Todos los tipos</option>'
        + '<option value="QR"' + (this.keyFilters.key_type === 'QR' ? ' selected' : '') + '>🎫 QR</option>'
        + '<option value="NFC"' + (this.keyFilters.key_type === 'NFC' ? ' selected' : '') + '>💳 Ficha NFC</option></select>'
        + '<select @change="setKeyFilter(\'worker_id\',$event.target.value)"><option value="">Todos los trabajadores</option>'
        + (this.workers || []).map(function (w) {
          return '<option value="' + w.id + '"' + (String(this.keyFilters.worker_id) === String(w.id) ? ' selected' : '') + '>' + F.escape(w.name) + '</option>';
        }.bind(this)).join('') + '</select>'
        + '<select @change="setKeyFilter(\'status\',$event.target.value)"><option value="">Todos los estados</option>'
        + '<option value="ACTIVE"' + (this.keyFilters.status === 'ACTIVE' ? ' selected' : '') + '>Activas</option>'
        + '<option value="EXPIRED"' + (this.keyFilters.status === 'EXPIRED' ? ' selected' : '') + '>Caducadas</option>'
        + '<option value="REVOKED"' + (this.keyFilters.status === 'REVOKED' ? ' selected' : '') + '>Revocadas</option></select>'
        + '<button class="btn btn-o" @click="clearKeyFilters()">Limpiar</button></div>'
        + this.v2Table(['Etiqueta', 'Tipo', 'Permisos', 'Validez', 'Estado', ''], rows, 'Sin llaves');
    };

    o.nfcPageV2 = function () {
      var cards = this.nfcCards || [];
      var rows = cards.map(function (c) {
        var st = { AVAILABLE: ['Disponible', 'ACTIVE'], ASSIGNED: ['Asignada', 'OCCUPIED'], DISABLED: ['Desactivada', 'DISABLED'] }[c.status] || [c.status, 'DISABLED'];
        return '<tr><td class="mono"><b>' + F.escape(c.number) + '</b></td><td class="mono">' + F.escape(c.code_masked || '—') + '</td>'
          + '<td>' + F.badge(st[1], st[0]) + '</td><td>' + F.escape(c.current_key_label || '—') + '</td>'
          + '<td class="small muted">' + F.escape(c.notes || '') + '</td>'
          + '<td><button class="btn btn-o btn-sm" @click="openNfcForm(' + c.id + ')" ' + (c.status === 'ASSIGNED' ? 'disabled' : '') + '>Editar</button> '
          + '<button class="btn btn-d btn-sm" @click="disableNfcCard(' + c.id + ')" ' + (c.status !== 'AVAILABLE' ? 'disabled' : '') + '>Desactivar</button></td></tr>';
      });
      return '<div class="ph"><div><h1>Fichas NFC</h1><p>' + cards.length + ' fichas registradas</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openNfcForm()">+ Nueva ficha</button></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', cards.length, 'fichas', '#2563eb', '💳')
        + this.v2Kpi('Disponibles', cards.filter(function (c) { return c.status === 'AVAILABLE'; }).length, 'sin asignar', '#16a34a', '🟢')
        + this.v2Kpi('Asignadas', cards.filter(function (c) { return c.status === 'ASSIGNED'; }).length, 'en uso', '#0ea5e9', '🔗')
        + this.v2Kpi('Desactivadas', cards.filter(function (c) { return c.status === 'DISABLED'; }).length, 'fuera de servicio', '#64748b', '⛔')
        + '</div>'
        + '<div class="fbar"><input class="grow" type="search" placeholder="Buscar número, código, llave o notas…" x-model="nfcFilter.q" @keydown.enter="loadNfcCards()">'
        + '<select @change="setNfcFilter(\'status\',$event.target.value)"><option value="">Todos los estados</option>'
        + '<option value="AVAILABLE"' + (this.nfcFilter.status === 'AVAILABLE' ? ' selected' : '') + '>Disponibles</option>'
        + '<option value="ASSIGNED"' + (this.nfcFilter.status === 'ASSIGNED' ? ' selected' : '') + '>Asignadas</option>'
        + '<option value="DISABLED"' + (this.nfcFilter.status === 'DISABLED' ? ' selected' : '') + '>Desactivadas</option></select>'
        + '<button class="btn btn-o" @click="loadNfcCards()">Aplicar</button>'
        + '<button class="btn btn-o" @click="nfcFilter={status:\'\',q:\'\'};loadNfcCards()">Limpiar</button></div>'
        + this.v2Table(['Número', 'Código', 'Estado', 'Llave', 'Notas', ''], rows, 'Sin fichas');
    };

    /* ═══════════════════════════════════════════════════════════════
       ACCESOS
       ═══════════════════════════════════════════════════════════════ */
    o.accessesPageV2 = function () {
      var a = this.accessAnalytics;
      var f = this.accessFilters || {};
      var filtered = !!(f.worker_id || f.key_id || f.result || f.room_id || f.type || f.from || f.to);
      var source = a ? (a.recent || []) : (this.keyAccesses || []);
      var rows = source.map(function (e) {
        return '<tr><td class="small">' + F.dateTime(e.occurred_at) + '</td>'
          + '<td>' + (e.room_code ? '<button class="link-cell" @click="openRoomDrawer(' + e.room_id + ')">' + F.escape(e.room_code) + '</button>' : '—') + '</td>'
          + '<td>' + F.escape(this.accessTypeLabel(e.type || e.credential_type)) + '</td>'
          + '<td>' + (e.worker_id ? '<button class="link-cell" @click="openWorkerDrawer(' + e.worker_id + ')">' + F.escape(e.worker_name || ('#' + e.worker_id)) + '</button>' : (e.owner_type === 'ROLE' ? 'Rol compartido' : '<span class="muted">—</span>')) + '</td>'
          + '<td>' + (e.key_label ? F.escape(e.key_label) : '<span class="muted">—</span>') + '</td>'
          + '<td>' + F.badge(e.result) + '</td><td class="small muted">' + F.escape(e.reason || '') + '</td></tr>';
      }.bind(this)).join('');
      var s = a ? a.summary : null;
      var workerOpts = '<option value="">Todos los trabajadores</option>' + (this.workers || []).map(function (w) {
        return '<option value="' + w.id + '"' + (String(f.worker_id) === String(w.id) ? ' selected' : '') + '>' + F.escape(w.name) + '</option>';
      }).join('');
      return '<div class="ph"><div><h1>Accesos</h1><p>Intentos concedidos y denegados' + (filtered ? ' · filtros activos' : '') + '</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', s ? F.number(s.total) : (this.keyAccesses || []).length, 'intentos', '#2563eb', '📜')
        + this.v2Kpi('Concedidos', s ? F.number(s.ok) : '—', s ? F.pct(s.success_rate) : '', '#16a34a', '✅')
        + this.v2Kpi('Denegados', s ? F.number(s.fail) : '—', 'revisar', '#dc2626', '⛔')
        + this.v2Kpi('Tasa de éxito', s ? F.pct(s.success_rate) : '—', 'global', '#0ea5e9', '📈')
        + '</div>'
        + (a && a.series && !filtered ? this.v2Card('Evolución', 'Accesos y llaves', C.bars(a.series.map(function (x) { return x.label; }), [
          { name: 'Accesos', values: a.series.map(function (x) { return x.accesses; }), color: '#2563eb' },
          { name: 'Llaves', values: a.series.map(function (x) { return x.keys; }), color: '#0d9488' }
        ], { height: 220 })) : '')
        + '<div class="fbar" style="margin-top:16px">'
        + '<select @change="setAccessFilterV2(\'room_id\',$event.target.value)">' + this.roomFilterOptions(f.room_id) + '</select>'
        + '<select @change="setAccessFilterV2(\'type\',$event.target.value)"><option value="">Todos los tipos</option>' + this.accessTypeOptions(f.type) + '</select>'
        + '<select id="ac_worker" @change="setAccessFilterV2(\'worker_id\',$event.target.value)">' + workerOpts + '</select>'
        + '<select @change="setAccessFilterV2(\'result\',$event.target.value)"><option value="">Todos</option><option value="OK"' + (f.result === 'OK' ? ' selected' : '') + '>Concedidos</option><option value="FAIL"' + (f.result === 'FAIL' ? ' selected' : '') + '>Denegados</option></select>'
        + '<input type="datetime-local" @change="setAccessFilterV2(\'from\',$event.target.value)" title="Desde" value="' + F.escape(this.dtLocalValue(f.from)) + '">'
        + '<input type="datetime-local" @change="setAccessFilterV2(\'to\',$event.target.value)" title="Hasta" value="' + F.escape(this.dtLocalValue(f.to)) + '">'
        + '<button class="btn btn-o btn-sm" @click="clearAccessFiltersV2()">Limpiar</button></div>'
        + this.v2Table(['Fecha', 'Sala', 'Tipo', 'Trabajador', 'Llave', 'Resultado', 'Motivo'], rows, 'Sin accesos');
    };

    /* ═══════════════════════════════════════════════════════════════
       FÁBRICA / ROLES / MONITOR / USUARIOS / API CLIENTS
       ═══════════════════════════════════════════════════════════════ */
    o.factoryPageV2 = function () {
      var items = this.factoryDevices || [];
      var rows = items.map(function (d) {
        return '<tr><td class="mono"><b>' + F.escape(d.chip_id) + '</b></td><td>' + F.badge(d.status) + '</td>'
          + '<td>' + (d.device_id ? '<span class="badge badge-soft">RPI #' + d.device_id + '</span>' : '<span class="muted">—</span>') + '</td>'
          + '<td class="small">' + F.dateTime(d.first_announced_at) + '</td><td class="small">' + F.dateTime(d.last_announced_at) + '</td>'
          + '<td>' + (d.status === 'PENDING' ? '<button class="btn btn-p btn-sm" @click="openFactoryClaim(' + d.id + ')">Reclamar</button>' : (d.device_id ? '<button class="btn btn-o btn-sm" @click="nav(\'dv\')">Asignar pack</button>' : '<span class="muted small">Reclamado por ' + F.escape(d.claimed_by || '—') + '</span>')) + '</td></tr>';
      });
      return '<div class="ph"><div><h1>Fábrica</h1><p>' + items.length + ' equipos anunciados</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Total', items.length, 'equipos', '#2563eb', '🏭')
        + this.v2Kpi('Pendientes', items.filter(function (d) { return d.status === 'PENDING'; }).length, 'por reclamar', '#d97706', '🟡')
        + this.v2Kpi('Reclamados', items.filter(function (d) { return d.status === 'CLAIMED'; }).length, 'asignados', '#16a34a', '✅')
        + '</div>'
        + this.v2Table(['chip_id', 'Estado', 'Dispositivo', 'Primer anuncio', 'Último anuncio', ''], rows, 'Sin equipos');
    };

    o.workerRolesPageV2 = function () {
      var roles = this.workerRoles || [];
      var rows = roles.map(function (r) {
        return '<tr><td><b>' + F.escape(r.name) + '</b></td><td class="small muted">' + F.escape(r.description || '—') + '</td>'
          + '<td>' + (r.room_type_ids || []).length + '</td><td>' + (r.workers_count || 0) + '</td>'
          + '<td><button class="btn btn-o btn-sm" @click="openWorkerRoleForm(' + r.id + ')">Editar</button> '
          + '<button class="btn btn-d btn-sm" @click="deleteWorkerRole(' + r.id + ')" ' + ((r.workers_count || 0) > 0 ? 'disabled' : '') + '>Eliminar</button></td></tr>';
      });
      return '<div class="ph"><div><h1>Roles</h1><p>' + roles.length + ' roles operativos</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openWorkerRoleForm()">+ Nuevo rol</button></div></div>'
        + this.v2Table(['Rol', 'Descripción', 'Tipos de sala', 'Trabajadores', ''], rows, 'Sin roles');
    };

    o.monitorPageV2 = function () {
      var events = (this.events || []).map(function (e) {
        return '<tr><td class="small">' + F.dateTime(e.occurred_at) + '</td><td><b>' + F.escape(e.room_code || e.room_id) + '</b></td>'
          + '<td>' + F.escape(e.kind || '—') + '</td><td>' + F.badge(e.result) + '</td><td class="small muted">' + F.escape(e.reason || '') + '</td></tr>';
      });
      var w = this.warehouseAnalytics;
      var wRows = w ? (w.recent || []).slice(0, 20).map(function (v) {
        return '<tr><td>#' + v.id + '</td><td>' + F.escape(v.room_code || '—') + '</td><td>' + F.escape(v.worker_name || '—') + '</td><td>' + F.escape(v.entry_trigger) + '</td><td>' + F.badge(v.outcome) + '</td><td class="small">' + F.dateTime(v.created_at) + '</td></tr>';
      }) : [];
      var outbox = this.outbox || [];
      var mf = this.monitorFilters || {};
      var monitorBar = '<div class="fbar" style="margin-bottom:12px">'
        + '<select @change="monitorFilters.room_id=$event.target.value;loadMonitor()">' + this.roomFilterOptions(mf.room_id) + '</select>'
        + '<select @change="monitorFilters.kind=$event.target.value;loadMonitor()"><option value="">Todos los tipos</option>' + this.accessTypeOptions(mf.kind) + '</select>'
        + '<select @change="monitorFilters.result=$event.target.value;loadMonitor()"><option value="">Todos</option>'
        + '<option value="OK"' + (mf.result === 'OK' ? ' selected' : '') + '>OK</option>'
        + '<option value="FAIL"' + (mf.result === 'FAIL' ? ' selected' : '') + '>FAIL</option></select>'
        + '<button class="btn btn-o" @click="monitorFilters={room_id:\'\',kind:\'\',result:\'\'};loadMonitor()">Limpiar</button>'
        + '<span class="small muted">Máx. 200 eventos</span></div>';
      return '<div class="ph"><div><h1>Monitor</h1><p>Eventos recientes y cola VB6</p></div>'
        + '<div class="page-actions"><button class="btn btn-o btn-sm" @click="loadMonitor();loadAccessAnalyticsV2()">↻ Actualizar</button></div></div>'
        + '<div class="grid-2">'
        + this.v2Card('Accesos recientes', 'Últimos 200', monitorBar + this.v2Table(['Fecha', 'Sala', 'Tipo', 'Resultado', 'Motivo'], events, 'Sin eventos'))
        + this.v2Card('Cola VB6', outbox.length + ' elementos', this.v2Table(['ID', 'Estado', 'Tema', 'Intentos'], outbox.slice(0, 20).map(function (x) {
          return '<tr><td>#' + x.id + '</td><td>' + F.badge(x.status) + '</td><td class="mono small">' + F.escape(x.topic || '—') + '</td><td>' + (x.attempts || 0) + '</td></tr>';
        }), 'Cola vacía'))
        + '</div>'
        + '<div style="margin-top:16px">' + this.v2Card('Visitas de almacén', 'Últimas 20', this.v2Table(['ID', 'Sala', 'Trabajador', 'Disparador', 'Resultado', 'Fecha'], wRows, 'Sin visitas')) + '</div>';
    };

    o.crmUserPageV2 = function () {
      var users = this.crmUsers || [];
      var rows = users.map(function (u) {
        return '<tr><td><div class="row">' + this.v2Avatar(u.display_name || u.username) + '<div><strong>' + F.escape(u.display_name || u.username) + '</strong><div class="small muted">' + F.escape(u.username) + '</div></div></div></td>'
          + '<td><span class="badge badge-soft">' + F.escape(u.role) + '</span></td>'
          + '<td>' + (u.active ? '<span class="badge badge-ok">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td>'
          + '<td class="small">' + F.relative(u.last_login_at) + '</td></tr>';
      }.bind(this));
      return '<div class="ph"><div><h1>Usuarios</h1><p>' + users.length + ' cuentas CRM</p></div>'
        + '<div class="page-actions"><button class="btn btn-p" @click="openCrmUserForm()">+ Nuevo usuario</button></div></div>'
        + this.v2Table(['Usuario', 'Rol', 'Estado', 'Último acceso'], rows, 'Sin usuarios');
    };

    o.apiClientPageV2 = function () {
      var items = this.apiClients || [];
      var rows = items.map(function (c) {
        return '<tr><td class="mono"><b>' + F.escape(c.code) + '</b></td><td><span class="badge badge-soft">' + F.escape(c.kind) + '</span></td>'
          + '<td class="small">' + (c.scopes || []).length + ' scopes</td>'
          + '<td>' + (c.active ? '<span class="badge badge-ok">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td></tr>';
      });
      return '<div class="ph"><div><h1>API Clients</h1><p>' + items.length + ' credenciales de integración</p></div></div>'
        + this.v2Table(['Código', 'Tipo', 'Permisos', 'Estado'], rows, 'Sin API clients');
    };

    /* ═══════════════════════════════════════════════════════════════
       ANOMALÍAS
       ═══════════════════════════════════════════════════════════════ */
    o.anomalyPageV2 = function () {
      var a = this.anomalyAnalytics;
      var f = this.anomalyFilters || { status: 'ACTIVE', type: '', severity: '', room_id: '' };
      var cat = AI.catalog;
      var rows = (this.anomalies || []).map(function (x) {
        var actions = '';
        if (x.status === 'OPEN') {
          actions = '<button class="btn btn-p btn-xs" title="Marcar como vista: sigue visible en «En seguimiento» y se cerrará sola cuando desaparezca la causa" @click="acknowledgeAnomaly(' + x.id + ')">Reconocer</button> '
            + '<button class="btn btn-o btn-xs" title="Cerrar la incidencia: sale de las vistas operativas; un humano puede reabrirla con motivo" @click="dismissAnomaly(' + x.id + ')">Descartar</button>';
        } else if (x.status === 'ACKNOWLEDGED') {
          actions = '<button class="btn btn-o btn-xs" title="Cerrar la incidencia: sale de las vistas operativas; un humano puede reabrirla con motivo" @click="dismissAnomaly(' + x.id + ')">Descartar</button>';
        } else if (x.status === 'DISMISSED' && x.dismissed_by !== 'system') {
          actions = '<button class="btn btn-o btn-xs" @click="reopenAnomaly(' + x.id + ')">Reabrir incidencia</button>';
        } else if (x.status === 'DISMISSED') {
          actions = '<span class="muted small">Resuelta automáticamente</span>';
        }
        return '<tr><td><button class="link-cell" @click="openAnomalyDrawer(' + x.id + ')">#' + x.id + '</button></td>'
          + '<td><button class="link-cell" @click="openRoomDrawer(' + x.room_id + ')"><strong>' + F.escape(x.room_code || this.roomCodeById(x.room_id)) + '</strong></button></td>'
          + '<td>' + AI.cell(x.anomaly_type) + '</td>'
          + '<td>' + F.badge(x.severity, AI.severityLabel(x.severity)) + '</td>'
          + '<td>' + F.badge(x.status, AI.stateLabel(x.status)) + '</td><td class="small">' + F.dateTime(x.detected_at) + '</td>'
          + '<td><div class="row-actions">' + actions + '</div></td></tr>';
      }.bind(this));
      var sevItems = a ? ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].map(function (s) {
        var colors = { CRITICAL: '#b91c1c', HIGH: '#dc2626', MEDIUM: '#d97706', LOW: '#64748b' };
        return { label: AI.severityLabel(s), value: (a.by_severity || {})[s] || 0, color: colors[s] };
      }) : [];
      var roomOpts = '<option value="">Todas las salas</option>' + (this.rooms || []).map(function (r) {
        return '<option value="' + r.id + '"' + (String(f.room_id) === String(r.id) ? ' selected' : '') + '>' + F.escape(r.code) + '</option>';
      }).join('');
      return '<div class="ph"><div><h1>Anomalías</h1><p>' + (this.anomalies || []).length + ' en la vista · ' + (this.anomalyCount || 0) + ' pendientes</p></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Pendientes', (a ? (a.by_status || {}).OPEN : this.anomalyCount) || 0, 'abiertas', '#dc2626', '🔴')
        + this.v2Kpi('Críticas', a ? ((a.by_severity || {}).CRITICAL || 0) : 0, 'prioridad máxima', '#b91c1c', '🚨')
        + this.v2Kpi('En seguimiento', a ? ((a.by_status || {}).ACKNOWLEDGED || 0) : 0, 'reconocidas', '#d97706', '👁️')
        + this.v2Kpi('Tiempo medio', a && a.mtta_minutes != null ? F.duration(a.mtta_minutes) : '—', 'hasta reconocer', '#2563eb', '⏱️')
        + '</div>'
        + (a ? '<div class="grid-2">' + this.v2Card('Severidad', 'Distribución', '<div class="donut-wrap">' + C.donut(sevItems, { size: 170, stroke: 18, centerLabel: String((a.by_status || {}).OPEN || 0), centerSub: 'abiertas' }) + '</div>')
          + this.v2Card('Evolución', 'Anomalías por día', C.bars((a.series || []).map(function (x) { return x.label; }), [{ name: 'Anomalías', values: (a.series || []).map(function (x) { return x.value; }), color: '#dc2626' }], { height: 200, legend: false })) + '</div>' : '')
        + '<div class="fbar" style="margin-top:16px">'
        + '<select @change="anomalyFilters.type=$event.target.value;loadAnomalies()"><option value="">Todos los tipos</option>' + Object.keys(cat).map(function (code) { return '<option value="' + code + '"' + (f.type === code ? ' selected' : '') + '>' + F.escape(cat[code].title) + '</option>'; }).join('') + '</select>'
        + '<select @change="anomalyFilters.severity=$event.target.value;loadAnomalies()"><option value="">Todas las severidades</option>' + ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].map(function (s) { return '<option value="' + s + '"' + (f.severity === s ? ' selected' : '') + '>' + AI.severityLabel(s) + '</option>'; }).join('') + '</select>'
        + '<select @change="anomalyFilters.status=$event.target.value;loadAnomalies()"><option value="ACTIVE"' + (f.status === 'ACTIVE' ? ' selected' : '') + '>Pendientes y en seguimiento</option><option value="OPEN"' + (f.status === 'OPEN' ? ' selected' : '') + '>Solo pendientes</option><option value="ACKNOWLEDGED"' + (f.status === 'ACKNOWLEDGED' ? ' selected' : '') + '>Solo en seguimiento</option><option value="DISMISSED"' + (f.status === 'DISMISSED' ? ' selected' : '') + '>Resueltas/descartadas</option><option value="ALL"' + (f.status === 'ALL' ? ' selected' : '') + '>Todas</option></select>'
        + '<select @change="anomalyFilters.room_id=$event.target.value;loadAnomalies()">' + roomOpts + '</select>'
        + '<button class="btn btn-o" @click="anomalyFilters={status:\'ACTIVE\',type:\'\',severity:\'\',room_id:\'\'};loadAnomalies()">Limpiar</button>'
        + '</div>'
        + '<p class="small muted" style="margin:-4px 0 12px">'
        + '<b>Reconocer</b>: la incidencia sigue operativa y visible, queda «En seguimiento» y se cerrará sola cuando desaparezca la causa. '
        + '<b>Descartar</b>: cierra la incidencia (sale de esta vista); si la descartó una persona puede reabrirse con motivo.'
        + '</p>'
        + this.v2Table(['ID', 'Sala', 'Tipo', 'Severidad', 'Estado', 'Detectada', ''], rows, 'Sin anomalías');
    };

    o.openAnomalyDrawer = async function (id) {
      this.setHash('an', { anomaly: id });
      this.drawer = { show: true, title: 'Anomalía #' + id, subtitle: 'Cargando…', content: '<div class="skeleton skeleton-card"></div>', footer: '' };
      try {
        var a = await this.api('/api/v1/anomalies/' + id);
        var meta = AI.info(a.anomaly_type);
        this.drawer.title = meta.title;
        this.drawer.subtitle = 'Anomalía ' + a.anomaly_type + ' · ' + AI.severityLabel(a.severity) + ' · ' + AI.stateLabel(a.status);
        var ctx = a.context_data && Object.keys(a.context_data).length
          ? '<pre class="mono small" style="white-space:pre-wrap;background:#f7f9fc;border:1px solid var(--border);border-radius:8px;padding:10px">' + F.escape(JSON.stringify(a.context_data, null, 2)) + '</pre>'
          : '<p class="muted small">Sin contexto adicional.</p>';
        this.drawer.content = '<div class="card"><div class="card-head"><h3>Qué significa</h3></div><div class="card-body">'
          + '<p>' + F.escape(meta.desc) + '</p><p class="small muted" style="margin-top:6px">' + F.escape(meta.action) + '</p></div></div>'
          + '<div class="kpi-grid" style="margin-top:12px">'
          + this.v2Kpi('Sala', F.escape(a.room_code || a.room_id), 'origen', '#2563eb', '🚪')
          + this.v2Kpi('Detectada', F.dateTime(a.detected_at), 'UTC→local', '#d97706', '🕐')
          + this.v2Kpi('Estancia', a.stay_id ? ('#' + a.stay_id) : 'Sin vínculo', 'relación durable', '#7c3aed', '🔗')
          + this.v2Kpi('Estado', AI.stateLabel(a.status), a.reopened_at ? 'reabierta' : 'actual', '#0ea5e9', 'ℹ️')
          + '</div>'
          + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Auditoría</h3></div><div class="card-body">'
          + '<p class="small">Reconocida: <b>' + (a.acknowledged_at ? F.dateTime(a.acknowledged_at) + ' · ' + F.escape(a.acknowledged_by || '') : '—') + '</b></p>'
          + '<p class="small">Descartada: <b>' + (a.dismissed_at ? F.dateTime(a.dismissed_at) + ' · ' + F.escape(a.dismissed_by || '') : '—') + '</b></p>'
          + '<p class="small">Reabierta: <b>' + (a.reopened_at ? F.dateTime(a.reopened_at) + ' · ' + F.escape(a.reopened_by || '') + ' · ' + F.escape(a.reopen_reason || '') : '—') + '</b></p>'
          + '</div></div>'
          + '<div class="card" style="margin-top:12px"><div class="card-head"><h3>Contexto</h3></div><div class="card-body">' + ctx + '</div></div>';
        var footer = '<button class="btn btn-o" @click="drawer.show=false">Cerrar</button>'
          + '<button class="btn btn-o" @click="openRoomDrawer(' + a.room_id + ')">Ver sala</button>';
        if (a.stay_id) footer += '<button class="btn btn-o" @click="openOccupationDrawer(\'stay\',' + a.stay_id + ')">Ver estancia</button>';
        if (a.status === 'OPEN') footer += '<button class="btn btn-p" @click="acknowledgeAnomaly(' + a.id + ')">Reconocer</button>';
        if (a.status !== 'DISMISSED') footer += '<button class="btn btn-o" @click="dismissAnomaly(' + a.id + ')">Descartar</button>';
        if (a.status === 'DISMISSED' && a.dismissed_by !== 'system') footer += '<button class="btn btn-p" @click="reopenAnomaly(' + a.id + ')">Reabrir incidencia</button>';
        this.drawer.footer = footer;
      } catch (e) {
        this.drawer.content = '<div class="empty"><h2>No se pudo cargar</h2><p>' + F.escape(e.message) + '</p></div>';
      }
    };

    o.reopenAnomaly = function (id) {
      var reason = window.prompt('Motivo de la reapertura (obligatorio):', '');
      if (reason == null) return;
      reason = String(reason).trim();
      if (!reason) { this.toastErr('El motivo es obligatorio'); return; }
      var that = this;
      this.api('/api/v1/anomalies/' + id + '/reopen', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason: reason, actor: (this.user && (this.user.username || this.user.display_name)) || 'admin' })
      }).then(function () {
        that.toastOk('Incidencia reabierta');
        that.loadAnomalies(); that.loadAnomalySummary(); that.loadAnomalyAnalyticsV2();
        that.drawer.show = false;
      }).catch(function (e) { that.toastErr(e.message); });
    };

    /* ═══════════════════════════════════════════════════════════════
       SALUD / CONFIGURACIÓN
       ═══════════════════════════════════════════════════════════════ */
    o.healthPageV2 = function () {
      var h = this.health;
      if (!h) return '<div class="ph"><div><h1>Salud</h1><p>Cargando…</p></div></div><div class="skeleton skeleton-card"></div>';
      var checks = h.checks || {};
      var db = checks.database || {};
      var ws = checks.ws_vb6 || {};
      var dbOk = db.status === 'ok' || db.status === 'running' || db.status === 'reachable';
      var wsOk = ws === 'ok' || ws === 'connected' || ws === 'running' || ws === 'reachable';
      return '<div class="ph"><div><h1>Salud del sistema</h1><p>' + (h.status === 'healthy' ? '✅ Sistema saludable' : '⚠️ Sistema degradado') + '</p></div>'
        + '<div class="page-actions"><button class="btn btn-o btn-sm" @click="loadHealth()">↻ Actualizar</button></div></div>'
        + '<div class="kpi-grid">'
        + this.v2Kpi('Estado', h.status === 'healthy' ? 'OK' : 'Degradado', F.relative(h.time), h.status === 'healthy' ? '#16a34a' : '#dc2626', '❤️')
        + this.v2Kpi('Base de datos', dbOk ? 'OK' : 'Fallo', F.escape(db.status || '—'), dbOk ? '#16a34a' : '#dc2626', '🗄️')
        + this.v2Kpi('WS-VB6', wsOk ? 'OK' : 'Fallo', F.escape(String(ws)), wsOk ? '#16a34a' : '#dc2626', '🔗')
        + '</div>'
        + this.v2Card('Comprobaciones', 'Última verificación ' + F.dateTime(h.time), '<div class="stack">'
          + '<div class="stat-line"><span>Database</span><span>' + F.escape(String(db.status || '—')) + '</span></div>'
          + '<div class="stat-line"><span>WS-VB6</span><span>' + F.escape(String(ws)) + '</span></div>'
          + '</div>');
    };

    o.settingsPageV2 = function () {
      var data = this.cfgSettings || {};
      var cats = Object.keys(data);
      if (!cats.length) {
        return '<div class="ph"><div><h1>Configuración</h1><p>Parámetros en tiempo de ejecución</p></div>'
          + '<div class="page-actions"><button class="btn btn-p" @click="loadSettingsV2()">Cargar ajustes</button></div></div>'
          + '<div class="empty"><h2>Ajustes no cargados</h2><p>Carga los parámetros actuales del servicio API.</p><button class="btn btn-p" @click="loadSettingsV2()">Cargar</button></div>';
      }
      var html = cats.map(function (cat) {
        var items = data[cat] || [];
        return '<section class="card" style="margin-bottom:14px"><div class="card-head"><h3>' + F.escape(cat) + '</h3><p>' + items.length + ' parámetros</p></div><div class="card-body">'
          + items.map(function (s) {
            var sensitive = !!s.is_sensitive;
            return '<div class="field" style="margin-bottom:10px"><label>' + F.escape(s.key) + '</label>'
              + '<input data-setting-key="' + F.escape(s.key) + '" value="' + (sensitive ? '' : F.escape(s.value == null ? '' : s.value)) + '" placeholder="' + (sensitive ? '•••• valor protegido' : '') + '" ' + (sensitive ? 'disabled' : '') + '>'
              + (sensitive ? '<p class="field-hint">Valor protegido: no se muestra ni se modifica desde el panel.</p>' : '') + '</div>';
          }).join('') + '</div></section>';
      }).join('');
      return '<div class="ph"><div><h1>Configuración</h1><p>Parámetros del servicio API</p></div>'
        + '<div class="page-actions"><button class="btn btn-o" @click="loadSettingsV2()">↻ Recargar</button><button class="btn btn-p" @click="saveSettingsV2()">Guardar cambios</button></div></div>'
        + html;
    };

    o.loadSettingsV2 = async function () {
      try {
        var d = await this.api('/api/v1/admin/settings?service=api');
        this.cfgSettings = d.settings || {};
        this.loadSettings();
      } catch (e) { this.toastErr(e.message); }
    };

    o.saveSettingsV2 = function () {
      var values = [];
      document.querySelectorAll('[data-setting-key]').forEach(function (el) {
        if (el.disabled) return;
        values.push({ key: el.getAttribute('data-setting-key'), value: el.value });
      });
      if (!values.length) { this.toastErr('No hay cambios que guardar'); return; }
      var that = this;
      this.api('/api/v1/admin/settings', {
        method: 'PUT', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ service: 'api', settings: values })
      }).then(function () { that.toastOk('Configuración guardada'); that.loadSettingsV2(); })
        .catch(function (e) { that.toastErr(e.message); });
    };

    return o;
  };
})();
