/* F119 — gráficas SVG puras (sin dependencias) */
(function () {
  'use strict';

  var C = {
    blue: '#2563eb',
    sky: '#0ea5e9',
    teal: '#0d9488',
    green: '#16a34a',
    amber: '#d97706',
    red: '#dc2626',
    violet: '#7c3aed',
    slate: '#64748b',
    pink: '#db2777'
  };
  var PALETTE = [C.blue, C.teal, C.amber, C.violet, C.green, C.sky, C.red, C.slate, C.pink];

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function maxOf(values) {
    var m = 0;
    (values || []).forEach(function (v) { if (Number(v) > m) m = Number(v); });
    return m || 1;
  }

  function niceMax(v) {
    if (v <= 5) return 5;
    var pow = Math.pow(10, Math.floor(Math.log10(v)));
    var n = Math.ceil(v / pow * 2) / 2 * pow;
    return Math.max(5, n);
  }

  function empty(text) {
    return '<div class="chart-empty">' + esc(text || 'Sin datos en el periodo') + '</div>';
  }

  /* ── line / area ─────────────────────────────────────────────────── */
  function line(labels, series, opts) {
    opts = opts || {};
    labels = labels || [];
    series = (series || []).filter(Boolean);
    if (!labels.length || !series.length) return empty(opts.empty);
    var w = opts.width || 640, h = opts.height || 220;
    var padL = 34, padR = 10, padT = 12, padB = 24;
    var innerW = w - padL - padR, innerH = h - padT - padB;
    var all = [];
    series.forEach(function (s) { (s.values || []).forEach(function (v) { all.push(Number(v) || 0); }); });
    var top = niceMax(maxOf(all));
    var n = Math.max(labels.length, 1);
    var step = n > 1 ? innerW / (n - 1) : innerW;

    function x(i) { return padL + (n > 1 ? i * step : innerW / 2); }
    function y(v) { return padT + innerH - (Number(v) || 0) / top * innerH; }

    var svg = '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + esc(opts.aria || 'Gráfica de evolución') + '">';
    for (var g = 0; g <= 4; g++) {
      var gy = padT + innerH - (innerH / 4) * g;
      svg += '<line class="grid-line" x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (w - padR) + '" y2="' + gy.toFixed(1) + '"/>';
      svg += '<text x="' + (padL - 6) + '" y="' + (gy + 3).toFixed(1) + '" text-anchor="end">' + esc(Math.round(top / 4 * g)) + '</text>';
    }
    var tickEvery = Math.ceil(n / 7);
    labels.forEach(function (lb, i) {
      if (i % tickEvery === 0 || i === n - 1) {
        svg += '<text x="' + x(i).toFixed(1) + '" y="' + (h - 6) + '" text-anchor="middle">' + esc(lb) + '</text>';
      }
    });
    series.forEach(function (s, si) {
      var color = s.color || PALETTE[si % PALETTE.length];
      var pts = (s.values || []).map(function (v, i) { return x(i).toFixed(1) + ',' + y(v).toFixed(1); }).join(' ');
      if (opts.area !== false && si === 0) {
        var area = 'M' + x(0).toFixed(1) + ',' + (padT + innerH) + ' L' + pts.replace(/ /g, ' L') + ' L' + x((s.values || []).length - 1).toFixed(1) + ',' + (padT + innerH) + ' Z';
        svg += '<path class="series-area" d="' + area + '" fill="' + color + '"/>';
      }
      svg += '<polyline class="series-line" points="' + pts + '" stroke="' + color + '"' + (s.dashed ? ' stroke-dasharray="4 4"' : '') + '/>';
      var last = (s.values || []).length - 1;
      if (last >= 0) svg += '<circle cx="' + x(last).toFixed(1) + '" cy="' + y(s.values[last]).toFixed(1) + '" r="3" fill="#fff" stroke="' + color + '" stroke-width="2"/>';
    });
    svg += '</svg>';
    if (opts.legend !== false && series.length > 1) {
      svg += '<div class="legend">' + series.map(function (s, i) {
        return '<span class="legend-item" style="--c:' + (s.color || PALETTE[i % PALETTE.length]) + '"><i></i>' + esc(s.name || ('Serie ' + (i + 1))) + '</span>';
      }).join('') + '</div>';
    }
    return svg;
  }

  /* ── grouped bars ────────────────────────────────────────────────── */
  function bars(labels, series, opts) {
    opts = opts || {};
    labels = labels || [];
    series = (series || []).filter(Boolean);
    if (!labels.length || !series.length) return empty(opts.empty);
    var w = opts.width || 640, h = opts.height || 220;
    var padL = 34, padR = 10, padT = 12, padB = 24;
    var innerW = w - padL - padR, innerH = h - padT - padB;
    var all = [];
    series.forEach(function (s) { (s.values || []).forEach(function (v) { all.push(Number(v) || 0); }); });
    var top = niceMax(maxOf(all));
    var n = labels.length;
    var groupW = innerW / n;
    var barW = Math.max(3, (groupW * 0.72) / series.length);
    var svg = '<svg class="chart" viewBox="0 0 ' + w + ' ' + h + '" role="img" aria-label="' + esc(opts.aria || 'Gráfica de actividad') + '">';
    for (var g = 0; g <= 4; g++) {
      var gy = padT + innerH - (innerH / 4) * g;
      svg += '<line class="grid-line" x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (w - padR) + '" y2="' + gy.toFixed(1) + '"/>';
      svg += '<text x="' + (padL - 6) + '" y="' + (gy + 3).toFixed(1) + '" text-anchor="end">' + esc(Math.round(top / 4 * g)) + '</text>';
    }
    var tickEvery = Math.ceil(n / 8);
    labels.forEach(function (lb, i) {
      if (i % tickEvery === 0 || i === n - 1) {
        svg += '<text x="' + (padL + groupW * i + groupW / 2).toFixed(1) + '" y="' + (h - 6) + '" text-anchor="middle">' + esc(lb) + '</text>';
      }
    });
    labels.forEach(function (lb, i) {
      var gx = padL + groupW * i + (groupW - barW * series.length) / 2;
      series.forEach(function (s, si) {
        var v = Number((s.values || [])[i]) || 0;
        var bh = v / top * innerH;
        var color = s.color || PALETTE[si % PALETTE.length];
        svg += '<rect class="bar" x="' + (gx + si * barW).toFixed(1) + '" y="' + (padT + innerH - bh).toFixed(1) + '" width="' + Math.max(2, barW - 3).toFixed(1) + '" height="' + bh.toFixed(1) + '" rx="3" fill="' + color + '"><title>' + esc(lb + ': ' + v) + '</title></rect>';
      });
    });
    svg += '</svg>';
    if (opts.legend !== false && series.length > 1) {
      svg += '<div class="legend">' + series.map(function (s, i) {
        return '<span class="legend-item" style="--c:' + (s.color || PALETTE[i % PALETTE.length]) + '"><i></i>' + esc(s.name || ('Serie ' + (i + 1))) + '</span>';
      }).join('') + '</div>';
    }
    return svg;
  }

  /* ── donut ───────────────────────────────────────────────────────── */
  function donut(items, opts) {
    opts = opts || {};
    items = (items || []).filter(function (x) { return Number(x.value) > 0; });
    if (!items.length) return empty(opts.empty);
    var size = opts.size || 170;
    var stroke = opts.stroke || 16;
    var r = (size - stroke) / 2;
    var c = size / 2;
    var total = items.reduce(function (a, b) { return a + (Number(b.value) || 0); }, 0) || 1;
    var circ = 2 * Math.PI * r;
    var offset = 0;
    var svg = '<svg class="chart" viewBox="0 0 ' + size + ' ' + size + '" width="' + size + '" height="' + size + '" role="img" aria-label="' + esc(opts.aria || 'Distribución') + '">';
    svg += '<circle cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="#eef2f7" stroke-width="' + stroke + '"/>';
    items.forEach(function (item, i) {
      var frac = (Number(item.value) || 0) / total;
      var len = frac * circ;
      var color = item.color || PALETTE[i % PALETTE.length];
      svg += '<circle cx="' + c + '" cy="' + c + '" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="' + stroke + '" stroke-dasharray="' + len.toFixed(2) + ' ' + (circ - len).toFixed(2) + '" stroke-dashoffset="' + (-offset).toFixed(2) + '" transform="rotate(-90 ' + c + ' ' + c + ')" stroke-linecap="butt"><title>' + esc(item.label + ': ' + item.value) + '</title></circle>';
      offset += len;
    });
    if (opts.centerLabel) {
      svg += '<text x="' + c + '" y="' + (c - 2) + '" text-anchor="middle" class="donut-center">' + esc(opts.centerLabel) + '</text>';
      if (opts.centerSub) svg += '<text x="' + c + '" y="' + (c + 14) + '" text-anchor="middle">' + esc(opts.centerSub) + '</text>';
    }
    svg += '</svg>';
    if (opts.legend !== false) {
      svg += '<div class="legend" style="flex-direction:column;align-items:flex-start;gap:6px">' + items.map(function (item, i) {
        return '<span class="legend-item" style="--c:' + (item.color || PALETTE[i % PALETTE.length]) + '"><i></i>' + esc(item.label) + ' <b style="margin-left:4px">' + esc(item.value) + '</b></span>';
      }).join('') + '</div>';
    }
    return svg;
  }

  /* ── sparkline ───────────────────────────────────────────────────── */
  function sparkline(values, opts) {
    opts = opts || {};
    values = (values || []).map(function (v) { return Number(v) || 0; });
    if (values.length < 2) return '';
    var w = opts.width || 92, h = opts.height || 28;
    var max = maxOf(values), min = Math.min.apply(null, values);
    var span = max - min || 1;
    var pts = values.map(function (v, i) {
      return (i / (values.length - 1) * w).toFixed(1) + ',' + (h - 2 - (v - min) / span * (h - 4)).toFixed(1);
    }).join(' ');
    var color = opts.color || C.blue;
    return '<svg class="spark" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" aria-hidden="true"><polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  /* ── heatmap (rows x 24) ─────────────────────────────────────────── */
  function heatmap(matrix, opts) {
    opts = opts || {};
    var rows = opts.rows || ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
    matrix = matrix || [];
    var max = 0;
    matrix.forEach(function (row) { (row || []).forEach(function (v) { if (Number(v) > max) max = Number(v); }); });
    var html = '<div class="heatmap">';
    html += '<div></div>';
    for (var h = 0; h < 24; h += 2) html += '<div class="hm-label" style="text-align:center">' + h + '</div>';
    for (var r = 0; r < rows.length; r++) {
      html += '<div class="hm-label">' + esc(rows[r]) + '</div>';
      for (var c = 0; c < 24; c += 2) {
        var a = Number((matrix[r] || [])[c]) || 0;
        var b = Number((matrix[r] || [])[c + 1]) || 0;
        var v = a + b;
        var level = max > 0 ? Math.min(5, Math.max(0, Math.ceil(v / max * 5))) : 0;
        html += '<div class="hm-cell hm-' + level + '" title="' + esc(rows[r] + ' ' + c + ':00 · ' + v) + '"><span>' + v + '</span></div>';
      }
    }
    html += '</div>';
    return html;
  }

  /* ── progress / gauge ────────────────────────────────────────────── */
  function progress(pct, opts) {
    opts = opts || {};
    var p = Math.max(0, Math.min(100, Number(pct) || 0));
    var color = opts.color || C.blue;
    return '<div style="height:' + (opts.height || 8) + 'px;background:#eef2f7;border-radius:20px;overflow:hidden"><div style="height:100%;width:' + p + '%;background:' + color + ';border-radius:20px;transition:width .4s"></div></div>';
  }

  window.PanelCharts = {
    colors: C,
    palette: PALETTE,
    line: line,
    bars: bars,
    donut: donut,
    sparkline: sparkline,
    heatmap: heatmap,
    progress: progress,
    empty: empty,
    esc: esc
  };
})();
