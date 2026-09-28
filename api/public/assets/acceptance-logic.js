/**
 * acceptance-logic.js — F55 (RF-62): lógica PURA del panel de aceptación manual
 * (`/pruebas`).
 *
 * Sin DOM, sin red, sin BD: el HTML solo pinta; aquí vive el cálculo de
 * progreso, "siguiente pendiente", criterio de verde, export Markdown y diff
 * entre corridas.
 *
 * UMD: global `AcceptanceLogic` en el navegador, `module.exports` en Node.
 */
(function (root, factory) {
  'use strict';
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.AcceptanceLogic = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : this), function () {
  'use strict';

  var STATUS = {
    PASS: 'PASS',
    FAIL: 'FAIL',
    NA: 'NA',
    PENDING: 'PENDING'
  };

  var DEFAULT_CONFIG = {
    naCountsAsGreen: true,
    naRequiresNote: true
  };

  function isStatus(s) {
    return s === STATUS.PASS || s === STATUS.FAIL || s === STATUS.NA || s === STATUS.PENDING;
  }

  /** Normaliza cualquier valor a un estado válido (default PENDING). */
  function normalizeStatus(s) {
    return isStatus(s) ? s : STATUS.PENDING;
  }

  function mergeConfig(config) {
    var cfg = {};
    for (var k in DEFAULT_CONFIG) {
      if (Object.prototype.hasOwnProperty.call(DEFAULT_CONFIG, k)) cfg[k] = DEFAULT_CONFIG[k];
    }
    if (config && typeof config === 'object') {
      for (var j in config) {
        if (Object.prototype.hasOwnProperty.call(config, j)) cfg[j] = config[j];
      }
    }
    return cfg;
  }

  /**
   * Resume una lista de pruebas `[{id, block, status}]` (en orden).
   * @returns {{pass,fail,na,pending,total,pct,green,byBlock:Object}}
   */
  function summarize(tests, config) {
    var cfg = mergeConfig(config);
    var list = Array.isArray(tests) ? tests : [];
    var out = { pass: 0, fail: 0, na: 0, pending: 0, total: list.length, pct: 0, green: false, byBlock: {} };
    for (var i = 0; i < list.length; i++) {
      var t = list[i] || {};
      var st = normalizeStatus(t.status);
      if (st === STATUS.PASS) out.pass++;
      else if (st === STATUS.FAIL) out.fail++;
      else if (st === STATUS.NA) out.na++;
      else out.pending++;

      var b = (t.block != null) ? String(t.block) : '_';
      if (!out.byBlock[b]) out.byBlock[b] = { pass: 0, fail: 0, na: 0, pending: 0, total: 0 };
      out.byBlock[b].total++;
      if (st === STATUS.PASS) out.byBlock[b].pass++;
      else if (st === STATUS.FAIL) out.byBlock[b].fail++;
      else if (st === STATUS.NA) out.byBlock[b].na++;
      else out.byBlock[b].pending++;
    }
    var considered = out.pass + (cfg.naCountsAsGreen ? out.na : 0);
    out.pct = out.total > 0 ? Math.round((considered / out.total) * 100) : 0;
    out.green = isGreen(out, cfg);
    return out;
  }

  /** Criterio de "todo en verde" a partir de un resumen (o de counts equivalentes). */
  function isGreen(summary, config) {
    var cfg = mergeConfig(config);
    var s = summary || {};
    var total = s.total || 0;
    if (total === 0) return false;
    if ((s.pending || 0) > 0) return false;
    if ((s.fail || 0) > 0) return false;
    if (!cfg.naCountsAsGreen && (s.na || 0) > 0) return false;
    return true;
  }

  /** Siguiente prueba con estado PENDING tras `currentId` (con wrap). null si no hay. */
  function nextPending(tests, currentId) {
    return _nextMatching(tests, currentId, function (st) { return st === STATUS.PENDING; });
  }

  /** Siguiente prueba no resuelta (PENDING o FAIL) tras `currentId` (con wrap). */
  function nextOpen(tests, currentId) {
    return _nextMatching(tests, currentId, function (st) {
      return st === STATUS.PENDING || st === STATUS.FAIL;
    });
  }

  function _nextMatching(tests, currentId, predicate) {
    var list = Array.isArray(tests) ? tests : [];
    if (list.length === 0) return null;
    var start = -1;
    for (var i = 0; i < list.length; i++) {
      if (list[i] && list[i].id === currentId) { start = i; break; }
    }
    for (var k = 1; k <= list.length; k++) {
      var idx = (start + k) % list.length;
      var t = list[idx];
      if (t && predicate(normalizeStatus(t.status))) return t.id;
    }
    return null;
  }

  /** Celdas de la tira de progreso en el orden del catálogo. */
  function progressCells(tests) {
    var list = Array.isArray(tests) ? tests : [];
    return list.map(function (t) {
      return { id: t.id, block: t.block, status: normalizeStatus(t.status) };
    });
  }

  /** Diff entre dos corridas (`{tests:{id:{status}}}`). */
  function diffRuns(prev, curr) {
    var p = (prev && prev.tests) ? prev.tests : {};
    var c = (curr && curr.tests) ? curr.tests : {};
    var ids = [];
    for (var id in c) if (Object.prototype.hasOwnProperty.call(c, id)) ids.push(id);
    ids.sort(_naturalId);
    var changes = [];
    for (var i = 0; i < ids.length; i++) {
      var key = ids[i];
      var to = normalizeStatus((c[key] || {}).status);
      var hadPrev = p[key] != null;
      var from = hadPrev ? normalizeStatus((p[key] || {}).status) : null;
      if (hadPrev && from === to) continue;
      var kind = 'change';
      if (!hadPrev) kind = 'new';
      else if (from === STATUS.PASS && to === STATUS.FAIL) kind = 'regression';
      else if (from === STATUS.FAIL && to === STATUS.PASS) kind = 'fix';
      changes.push({ id: key, from: from, to: to, kind: kind });
    }
    return changes;
  }

  function _naturalId(a, b) {
    var na = parseInt(String(a).replace(/\D/g, ''), 10);
    var nb = parseInt(String(b).replace(/\D/g, ''), 10);
    if (!isNaN(na) && !isNaN(nb) && na !== nb) return na - nb;
    return String(a) < String(b) ? -1 : (String(a) > String(b) ? 1 : 0);
  }

  var _LABEL = { PASS: 'PASS', FAIL: 'FAIL', NA: 'N/A', PENDING: 'Pendiente' };

  /** Export Markdown de una corrida, agrupado por bloque. */
  function toMarkdown(run, catalog) {
    run = run || {};
    catalog = catalog || {};
    var tests = (run.tests) || {};
    var blocks = (catalog.blocks || []).slice().sort(function (a, b) { return (a.order || 0) - (b.order || 0); });
    var catTests = (catalog.tests || []);
    var summary = summarize(catTests.map(function (ct) {
      return { id: ct.id, block: ct.block, status: (tests[ct.id] || {}).status };
    }), run.config);

    var lines = [];
    lines.push('# Aceptación manual PROTO2 — ' + (run.run_id || '(sin id)'));
    lines.push('');
    lines.push('- **Operador**: ' + (run.operator || '—'));
    lines.push('- **Sala**: ' + (run.room_id != null ? run.room_id : '—'));
    lines.push('- **Commit**: ' + (run.commit || '—'));
    lines.push('- **Inicio**: ' + (run.started_at || '—'));
    lines.push('- **Actualizado**: ' + (run.updated_at || '—'));
    lines.push('- **Resultado**: ' + (summary.green ? '✅ TODO EN VERDE' : '⚠️ ' + summary.fail + ' FAIL · ' + summary.pending + ' pendientes') +
      ' (' + summary.pass + ' PASS · ' + summary.fail + ' FAIL · ' + summary.na + ' N/A · ' + summary.pending + ' pendientes de ' + summary.total + ')');
    lines.push('');

    for (var bi = 0; bi < blocks.length; bi++) {
      var block = blocks[bi];
      var blockTests = catTests.filter(function (ct) { return String(ct.block) === String(block.id); });
      if (blockTests.length === 0) continue;
      lines.push('## Bloque ' + block.id + ' — ' + block.name);
      lines.push('');
      lines.push('| ID | Título | Estado | Notas |');
      lines.push('|----|--------|--------|-------|');
      for (var ti = 0; ti < blockTests.length; ti++) {
        var ct2 = blockTests[ti];
        var entry = tests[ct2.id] || {};
        var note = (entry.notes || '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
        lines.push('| ' + ct2.id + ' | ' + (ct2.title || '') + ' | ' + _LABEL[normalizeStatus(entry.status)] + ' | ' + note + ' |');
      }
      lines.push('');
    }
    return lines.join('\n');
  }

  return {
    STATUS: STATUS,
    DEFAULT_CONFIG: DEFAULT_CONFIG,
    isStatus: isStatus,
    normalizeStatus: normalizeStatus,
    summarize: summarize,
    isGreen: isGreen,
    nextPending: nextPending,
    nextOpen: nextOpen,
    progressCells: progressCells,
    diffRuns: diffRuns,
    toMarkdown: toMarkdown
  };
});
