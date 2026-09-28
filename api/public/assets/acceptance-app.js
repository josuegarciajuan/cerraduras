/**
 * acceptance-app.js — F55 (RF-62): controlador del panel de aceptación manual.
 *
 * Depende de acceptance-logic.js (global `AcceptanceLogic`). Sin frameworks.
 * Estado local en localStorage (offline-first) + autosave al servidor.
 */
(function () {
  'use strict';
  var L = window.AcceptanceLogic;
  var catalog = null;
  var order = [];          // ids en orden de catálogo
  var tests = {};          // id -> {status, notes, snapshot}
  var run = {};            // metadatos + config
  var currentId = null;
  var mode = 'focus';      // 'focus' | 'panel'
  var saveTimer = null;
  var lastSaved = null;
  var snapshotBusy = false;

  var LS_STATE = 'acceptance:state';
  var LS_OP = 'acceptance:operator';
  var LS_MODE = 'acceptance:mode';

  // ── utilidades ───────────────────────────────────────────────────────
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function testList() { return order.map(function (id) { return catalogTest(id); }); }
  function catalogTest(id) { return catalog.tests.filter(function (t) { return t.id === id; })[0]; }
  function blockOf(id) { var t = catalogTest(id); return t ? t.block : null; }
  function blockName(bid) {
    var b = catalog.blocks.filter(function (x) { return String(x.id) === String(bid); })[0];
    return b ? b.name : String(bid);
  }
  function statusOf(id) { return L.normalizeStatus((tests[id] || {}).status); }

  function nowIso() { return new Date().toISOString(); }
  function shortTs(iso) { return iso ? iso.replace('T', ' ').replace(/\..+/, '') : '—'; }

  function toast(msg, undoFn) {
    var el = $('toast');
    el.innerHTML = esc(msg) + (undoFn ? ' <button id="toastUndo">Deshacer</button>' : '');
    el.classList.remove('hidden');
    if (undoFn) $('toastUndo').onclick = function () { undoFn(); el.classList.add('hidden'); };
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.classList.add('hidden'); }, undoFn ? 6000 : 2500);
  }

  // ── estado / persistencia ────────────────────────────────────────────
  function newRunId() {
    var stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, 'Z');
    return String(run.room_id || 12) + '-' + stamp + '-' + Math.random().toString(36).slice(2, 6);
  }

  function initState() {
    tests = {};
    order.forEach(function (id) { tests[id] = { status: 'PENDING', notes: '', snapshot: null }; });
    run = {
      run_id: null,
      operator: localStorage.getItem(LS_OP) || '',
      room_id: (catalog && catalog.room_id) || 12,
      commit: '',
      started_at: nowIso(),
      updated_at: nowIso(),
      revision: 0,
      config: { naCountsAsGreen: true, naRequiresNote: true }
    };
    run.run_id = newRunId();
  }

  function saveLocal() {
    try {
      localStorage.setItem(LS_STATE, JSON.stringify({ run: run, tests: tests, currentId: currentId, mode: mode }));
    } catch (e) { /* localStorage lleno */ }
  }

  function loadLocal() {
    try {
      var raw = localStorage.getItem(LS_STATE);
      if (!raw) return false;
      var s = JSON.parse(raw);
      if (!s || !s.tests || !s.run) return false;
      var sameCatalog = order.every(function (id) { return Object.prototype.hasOwnProperty.call(s.tests, id); });
      if (!sameCatalog) return false;
      tests = s.tests;
      run = s.run;
      currentId = s.currentId && tests[s.currentId] ? s.currentId : order[0];
      mode = s.mode || mode;
      return true;
    } catch (e) { return false; }
  }

  function scheduleSave() {
    setSaveIndicator('dirty');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 800);
  }

  function saveNow() {
    run.updated_at = nowIso();
    run.revision = (run.revision || 0) + 1;
    saveLocal();
    setSaveIndicator('saving');
    fetch('/dashboard-api/acceptance/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload())
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      lastSaved = new Date();
      setSaveIndicator('saved');
    }).catch(function () { setSaveIndicator('error'); });
  }

  function payload() {
    return {
      run_id: run.run_id, operator: run.operator, room_id: run.room_id, commit: run.commit,
      started_at: run.started_at, updated_at: run.updated_at, revision: run.revision,
      config: run.config,
      tests: tests,
      summary: summary()
    };
  }

  function setSaveIndicator(kind) {
    var el = $('saveIndicator');
    if (!el) return;
    if (kind === 'dirty') { el.className = 'save-ind dirty'; el.textContent = '● sin guardar'; }
    else if (kind === 'saving') { el.className = 'save-ind saving'; el.textContent = '↻ guardando…'; }
    else if (kind === 'saved') { el.className = 'save-ind saved'; el.textContent = '✓ guardado ' + new Date().toLocaleTimeString(); }
    else if (kind === 'error') { el.className = 'save-ind error'; el.textContent = '✕ sin guardar (offline)'; }
    else { el.className = 'save-ind'; el.textContent = '—'; }
  }

  function summary() { return L.summarize(testList(), run.config); }

  // ── acciones ─────────────────────────────────────────────────────────
  function setStatus(id, status) {
    var prev = statusOf(id);
    tests[id].status = status;
    if (status === 'NA' && run.config.naRequiresNote && !(tests[id].notes || '').trim()) {
      toast('N/A requiere una nota');
    }
    scheduleSave();
    render();
    if (status !== 'PENDING') {
      var target = L.nextOpen(testList(), id);
      if (target) { currentId = target; renderDetail(); renderIndex(); }
      toast(id + ' ' + status + (target ? ' · siguiente ' + target : ' · última'), function () {
        tests[id].status = prev; currentId = id; scheduleSave(); render();
      });
    }
  }

  function setNote(id, note) { tests[id].notes = note; scheduleSave(); }

  function goTo(id) {
    if (!tests[id]) return;
    currentId = id;
    renderDetail(); renderIndex(); saveLocal();
    var card = $('testTitle'); if (card) card.focus();
  }

  function goNextPending() {
    var t = L.nextOpen(testList(), currentId);
    if (t) goTo(t); else toast('No quedan pruebas pendientes 🎉');
  }

  function captureState() {
    if (snapshotBusy) return;
    snapshotBusy = true;
    var btn = $('btnCapture'); if (btn) { btn.disabled = true; btn.textContent = '⏳ capturando…'; }
    var room = run.room_id || 12;
    var errors = [];
    var pLive = fetch('/api/v1/rooms/' + room + '/live').then(function (r) { if (!r.ok) throw new Error('live ' + r.status); return r.json(); })
      .catch(function (e) { errors.push(String(e.message || e)); return null; });
    var pHealth = fetch('/api/v1/health/deep').then(function (r) { if (!r.ok) throw new Error('health ' + r.status); return r.json(); })
      .catch(function (e) { errors.push(String(e.message || e)); return null; });
    Promise.all([pLive, pHealth]).then(function (res) {
      tests[currentId].snapshot = { captured_at: nowIso(), room_id: room, live: res[0], health: res[1], errors: errors };
      snapshotBusy = false;
      scheduleSave();
      renderDetail();
      toast(errors.length ? 'Captura parcial: ' + errors.join(', ') : 'Estado capturado ✓');
    });
  }

  function exportJson() {
    download('aceptacion-' + run.run_id + '.json', JSON.stringify(payload(), null, 2), 'application/json');
  }
  function exportMd() {
    var md = L.toMarkdown({ run_id: run.run_id, operator: run.operator, room_id: run.room_id, commit: run.commit, started_at: run.started_at, updated_at: run.updated_at, tests: tests, config: run.config }, catalog);
    download('aceptacion-' + run.run_id + '.md', md, 'text/markdown');
  }
  function download(name, content, type) {
    var blob = new Blob([content], { type: type + ';charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }

  function newRun() {
    if (!confirm('¿Empezar una nueva corrida? Se guardará la actual en el servidor.')) return;
    saveNow();
    var op = run.operator;
    initState();
    run.operator = op;
    currentId = order[0];
    saveNow(); render();
    toast('Nueva corrida: ' + run.run_id);
  }

  function listRuns() {
    fetch('/dashboard-api/acceptance/list').then(function (r) { return r.json(); }).then(function (list) {
      if (!Array.isArray(list) || list.length === 0) { toast('No hay corridas guardadas'); return; }
      var html = '<h3>Corridas guardadas</h3><table class="runs"><tr><th>Run</th><th>Operador</th><th>Sala</th><th>Resultado</th><th></th></tr>';
      list.slice(0, 30).forEach(function (x) {
        var res = x.green ? '✅ verde' : ((x.fail || 0) + ' FAIL · ' + (x.pending || 0) + ' pend');
        html += '<tr><td>' + esc(x.run_id) + '</td><td>' + esc(x.operator || '—') + '</td><td>' + esc(x.room_id) +
          '</td><td>' + esc(res) + '</td><td><button data-load="' + esc(x.run_id) + '">Abrir</button></td></tr>';
      });
      html += '</table>';
      openModal(html);
      Array.prototype.forEach.call(document.querySelectorAll('[data-load]'), function (b) {
        b.onclick = function () { loadRun(b.getAttribute('data-load')); closeModal(); };
      });
    }).catch(function () { toast('No se pudo listar corridas'); });
  }

  function loadRun(runId) {
    fetch('/dashboard-api/acceptance/get?id=' + encodeURIComponent(runId)).then(function (r) { return r.json(); }).then(function (s) {
      if (!s || !s.tests) { toast('Corrida no encontrada'); return; }
      run = { run_id: s.run_id, operator: s.operator || '', room_id: s.room_id || 12, commit: s.commit || '', started_at: s.started_at || nowIso(), updated_at: s.updated_at || nowIso(), revision: s.revision || 0, config: s.config || run.config };
      tests = s.tests;
      order.forEach(function (id) { if (!tests[id]) tests[id] = { status: 'PENDING', notes: '', snapshot: null }; });
      currentId = L.nextOpen(testList(), null) || order[0];
      saveLocal(); render();
      toast('Corrida cargada: ' + s.run_id);
    }).catch(function () { toast('No se pudo cargar la corrida'); });
  }

  function openModal(html) { $('modal').innerHTML = '<div class="modal-box">' + html + '<div class="modal-actions"><button id="modalClose">Cerrar</button></div></div>'; $('modal').classList.remove('hidden'); $('modalClose').onclick = closeModal; }
  function closeModal() { $('modal').classList.add('hidden'); }

  // ── render ───────────────────────────────────────────────────────────
  function render() {
    renderHeader();
    renderStrip();
    renderIndex();
    renderDetail();
    renderBanners();
    renderVerdictBar();
  }

  function renderHeader() {
    $('roomId').value = run.room_id;
    $('operator').value = run.operator || '';
    $('commit').value = run.commit || '';
    $('runId').textContent = run.run_id;
    $('startedAt').textContent = shortTs(run.started_at);
    var s = summary();
    $('globalCounts').innerHTML = '<b>' + s.pass + '</b>✔ <b class="c-fail">' + s.fail + '</b>✕ <b class="c-na">' + s.na + '</b>N <b>' + s.pending + '</b>○ · ' + (s.pass + s.na + s.fail + s.pending) + '/' + s.total + ' · ' + s.pct + '%';
    $('blockChip').textContent = currentId ? ('Bloque ' + blockOf(currentId) + ' · ' + blockName(blockOf(currentId))) : '';
  }

  function renderStrip() {
    var cells = L.progressCells(testList());
    var html = cells.map(function (c) {
      return '<span class="cell st-' + c.status + (c.id === currentId ? ' current' : '') + '" data-goto="' + c.id + '" title="' + c.id + ' · ' + c.status + '"></span>';
    }).join('');
    $('progressStrip').innerHTML = html;
    Array.prototype.forEach.call($('progressStrip').children, function (el) {
      el.onclick = function () { goTo(el.getAttribute('data-goto')); };
    });
    $('progressStrip').setAttribute('role', 'progressbar');
    $('progressStrip').setAttribute('aria-valuenow', summary().pct);
  }

  function renderIndex() {
    var s = summary();
    var blocks = catalog.blocks.slice().sort(function (a, b) { return a.order - b.order; });
    var html = '';
    blocks.forEach(function (b) {
      var bt = catalog.tests.filter(function (t) { return String(t.block) === String(b.id); });
      var bs = s.byBlock[String(b.id)] || { pass: 0, fail: 0, na: 0, pending: 0, total: bt.length };
      var done = bs.pass + bs.fail + bs.na;
      var open = bs.pending > 0 || bs.fail > 0;
      html += '<div class="blk"><div class="blk-head">' +
        '<span class="blk-id">' + esc(b.id) + '</span><span class="blk-name">' + esc(b.name) + '</span>' +
        '<span class="blk-count">' + done + '/' + bs.total + (bs.fail ? ' ✕' + bs.fail : '') + '</span></div>';
      html += '<ul>';
      bt.forEach(function (t) {
        var st = statusOf(t.id);
        html += '<li class="item st-' + st + (t.id === currentId ? ' current' : '') + '" data-goto="' + t.id + '">' +
          '<span class="ic">' + statusIcon(st) + '</span><span class="tid">' + t.id + '</span><span class="ttl">' + esc(t.title) + '</span></li>';
      });
      html += '</ul></div>';
    });
    $('index').innerHTML = html;
    Array.prototype.forEach.call($('index').querySelectorAll('[data-goto]'), function (el) {
      el.onclick = function () { goTo(el.getAttribute('data-goto')); };
    });
  }

  function statusIcon(st) {
    return st === 'PASS' ? '✔' : st === 'FAIL' ? '✕' : st === 'NA' ? '—' : '○';
  }
  function statusLabel(st) {
    return st === 'PASS' ? 'PASS' : st === 'FAIL' ? 'FAIL' : st === 'NA' ? 'N/A' : 'PEND';
  }

  function renderDetail() {
    if (!currentId) return;
    var t = catalogTest(currentId);
    var st = statusOf(currentId);
    var entry = tests[currentId] || {};
    var idx = order.indexOf(currentId) + 1;
    var typeLabel = { fisico: '◉ físico', nav: '⌖ navegador', shell: '▤ shell', mixto: '◈ mixto' }[t.type] || t.type;
    var snap = entry.snapshot;
    var snapHtml = snap
      ? '<div class="snap"><b>Capturado</b> ' + esc(shortTs(snap.captured_at)) +
        (snap.errors && snap.errors.length ? ' · <span class="c-fail">⚠ ' + esc(snap.errors.join(', ')) + '</span>' : ' · <span class="c-pass">live ' + esc(String(snap.live ? snap.live.status : '—')) + '</span>') +
        '<details><summary>ver JSON</summary><pre>' + esc(JSON.stringify({ live: snap.live, health: snap.health, errors: snap.errors }, null, 2)) + '</pre></details></div>'
      : '<div class="snap muted">Sin captura de estado.</div>';

    $('detail').innerHTML =
      '<article class="card st-' + st + '">' +
        '<div class="card-top"><span class="pid">' + t.id + '</span><span class="type">' + typeLabel + '</span><span class="pill st-' + st + '">' + statusLabel(st) + '</span></div>' +
        '<div class="eyebrow">bloque ' + esc(t.block) + ' · ' + esc(blockName(t.block)) + ' · ' + idx + '/' + order.length + '</div>' +
        '<h2 id="testTitle" tabindex="-1">' + esc(t.title) + '</h2>' +
        '<h4>¿Qué hago?</h4><p>' + esc(t.action) + '</p>' +
        '<h4>Esperado</h4><p class="exp">' + esc(t.expected) + '</p>' +
        '<h4>Evidencia</h4><p>' + esc(t.evidence) + '</p>' +
        '<div class="snap-row"><button id="btnCapture">📸 Capturar estado</button>' + snapHtml + '</div>' +
        '<h4>Notas</h4><textarea id="notes" placeholder="Observaciones, hora, incidencias…">' + esc(entry.notes || '') + '</textarea>' +
      '</article>';

    $('notes').addEventListener('input', function () { setNote(currentId, this.value); });
    $('btnCapture').onclick = captureState;
  }

  function renderVerdictBar() {
    var st = statusOf(currentId);
    $('verdictBar').innerHTML =
      '<button class="v fail' + (st === 'FAIL' ? ' on' : '') + '" data-v="FAIL">✕ FAIL</button>' +
      '<button class="v na' + (st === 'NA' ? ' on' : '') + '" data-v="NA">— N/A</button>' +
      '<button class="v pass' + (st === 'PASS' ? ' on' : '') + '" data-v="PASS">▶ PASS</button>' +
      '<button class="v pend' + (st === 'PENDING' ? ' on' : '') + '" data-v="PENDING">○ Pend</button>' +
      '<button class="v nav" id="vPrev">◀</button>' +
      '<button class="v nav" id="vNext">Siguiente ▸</button>';
    Array.prototype.forEach.call($('verdictBar').querySelectorAll('[data-v]'), function (b) {
      b.onclick = function () { setStatus(currentId, b.getAttribute('data-v')); };
    });
    $('vPrev').onclick = function () { var i = order.indexOf(currentId); if (i > 0) goTo(order[i - 1]); };
    $('vNext').onclick = goNextPending;
  }

  function renderBanners() {
    var s = summary();
    var g = $('greenBanner');
    if (s.green) {
      g.classList.remove('hidden');
      g.innerHTML = '✓ TODO EN VERDE — ' + s.total + '/' + s.total + ' · ' + esc(run.run_id) +
        (s.na ? ' · Incluye ' + s.na + ' N/A con nota' : '');
    } else { g.classList.add('hidden'); }
  }

  // ── boot / eventos ───────────────────────────────────────────────────
  function bindGlobal() {
    $('btnNextPending').onclick = goNextPending;
    $('btnExportJson').onclick = exportJson;
    $('btnExportMd').onclick = exportMd;
    $('btnPrint').onclick = function () { window.print(); };
    $('btnRuns').onclick = listRuns;
    $('btnNew').onclick = newRun;
    $('modeToggle').onclick = function () {
      mode = mode === 'focus' ? 'panel' : 'focus';
      document.body.setAttribute('data-mode', mode);
      localStorage.setItem(LS_MODE, mode);
      $('modeToggle').textContent = mode === 'focus' ? 'Vista: Foco' : 'Vista: Panel';
      saveLocal();
    };
    $('operator').oninput = function () { run.operator = this.value; localStorage.setItem(LS_OP, this.value); scheduleSave(); };
    $('commit').oninput = function () { run.commit = this.value; scheduleSave(); };
    $('roomId').onchange = function () { run.room_id = parseInt(this.value, 10) || 12; scheduleSave(); };

    document.addEventListener('keydown', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      if (tag === 'textarea' || tag === 'input') return;
      if (e.metaKey || e.ctrlKey) return;
      var k = e.key.toLowerCase();
      if (k === 'p') setStatus(currentId, 'PASS');
      else if (k === 'f') setStatus(currentId, 'FAIL');
      else if (k === 'n') setStatus(currentId, 'NA');
      else if (k === 'u' || k === 'backspace') setStatus(currentId, 'PENDING');
      else if (k === 'j' || k === 'arrowright') { var i = order.indexOf(currentId); if (i < order.length - 1) goTo(order[i + 1]); }
      else if (k === 'k' || k === 'arrowleft') { var j = order.indexOf(currentId); if (j > 0) goTo(order[j - 1]); }
      else if (k === 's') goNextPending();
      else if (k === 'c') captureState();
    });
  }

  function boot() {
    mode = localStorage.getItem(LS_MODE) || 'focus';
    document.body.setAttribute('data-mode', mode);
    $('modeToggle').textContent = mode === 'focus' ? 'Vista: Foco' : 'Vista: Panel';

    fetch('assets/acceptance-tests.json').then(function (r) {
      if (!r.ok) throw new Error('catálogo HTTP ' + r.status);
      return r.json();
    }).then(function (cat) {
      catalog = cat;
      order = cat.tests.map(function (t) { return t.id; });
      if (!loadLocal()) { initState(); currentId = order[0]; }
      bindGlobal();
      setSaveIndicator(null);
      render();
    }).catch(function (e) {
      $('detail').innerHTML = '<div class="empty"><h3>No se pudo cargar el catálogo</h3><p>' +
        esc(e.message) + '</p><p>Ruta esperada: <code>assets/acceptance-tests.json</code></p>' +
        '<button onclick="location.reload()">Recargar</button></div>';
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
