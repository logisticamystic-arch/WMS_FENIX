/* ============================================================
   Chart.js — colores corporativos (sistema de diseño bee)
   Un solo punto de verdad para TODOS los gráficos del WMS: cualquier
   color que un módulo pase a Chart.js se normaliza aquí a la paleta
   corporativa (conservando la transparencia), y ejes/leyenda/tooltip
   siguen el tema claro/oscuro. Los módulos no necesitan cambiar.
   Paleta documentada en Skills/CONFIGURACION WMS/WMS_FENIX_BRIEF.md §5.6
   ============================================================ */
(function () {
  if (!window.Chart) return;

  // Paleta corporativa (orden = orden de series cuando un módulo pide "la paleta")
  const CORP = {
    azul: '#2b5fc7', azulReal: '#1d4ed8', info: '#2563eb', verde: '#10b981', ok: '#16a34a',
    naranja: '#f97316', ambar: '#d97706', rojo: '#dc2626', morado: '#7c3aed',
    teal: '#0d9488', rosa: '#db2777', gris: '#64748b',
  };
  window.WMS_CHART_PALETTE = [CORP.azul, CORP.verde, CORP.naranja, CORP.rojo, CORP.morado, CORP.teal, CORP.ambar, CORP.rosa, CORP.gris];
  window.WMS_CHART_COLORS = CORP;

  // Colores heredados → corporativos (claves en minúscula, 6 dígitos)
  const MAP = {
    // verdes
    '00b300': CORP.ok, '00800e': CORP.ok, '22c55e': CORP.ok, '15803d': CORP.ok, '059669': CORP.verde, '34d399': CORP.verde,
    // rojos
    'e03030': CORP.rojo, 'ef4444': CORP.rojo, 'b00020': CORP.rojo, 'b91c1c': CORP.rojo, 'f87171': CORP.rojo,
    // ámbar / naranja
    'e8a000': CORP.ambar, 'f59e0b': CORP.ambar, 'bf7300': CORP.ambar, 'fbbf24': CORP.ambar,
    'ea580c': CORP.naranja, 'fb923c': CORP.naranja,
    // azules (SAP #0070f2, marca vieja #0f4c81, etc.)
    '0070f2': CORP.azul, '0052cc': CORP.azulReal, '0f4c81': CORP.azul, '3b82f6': CORP.azul, '1a56db': CORP.azul,
    '1e40af': CORP.azulReal, '0284c7': CORP.info, '0369a1': CORP.info, '60a5fa': CORP.azul, '4f46e5': CORP.azulReal,
    // teal / cian
    '0891b2': CORP.teal, '14b8a6': CORP.teal, '0e7490': CORP.teal, '06b6d4': CORP.teal,
    // morados / rosas
    '9333ea': CORP.morado, '8b5cf6': CORP.morado, '6d28d9': CORP.morado, '5b21b6': CORP.morado, 'a78bfa': CORP.morado, '6366f1': CORP.morado,
    'c026d3': CORP.rosa, 'ec4899': CORP.rosa, 'd946ef': CORP.rosa,
  };
  // Neutros que dependen del tema (texto, rejilla, separadores de dona)
  const TEXT = new Set(['1a2340', '1e293b', '0f172a', '334155', '111827']);
  const MUTED = new Set(['475569', '6b7a99', '64748b', '94a3b8', '6b7280']);
  const SOFT = new Set(['f0f2f8', 'f1f5f9', 'e2e8f0', 'f8fafc', 'e5e7eb']);
  const WHITE = new Set(['fff', 'ffffff']);

  const css = (v, fb) => {
    const r = getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    return r || fb;
  };
  // La TV (tv-picking.html) no carga bee-theme.css: ahí solo se normaliza la paleta.
  const HAS_THEME = () => !!getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
  const theme = () => ({
    text: css('--text', '#1e293b'), muted: css('--muted', '#64748b'),
    tick: css('--tick-color', '#94a3b8'), grid: css('--grid-line', '#e2e8f0'),
    surface: css('--surface', '#ffffff'), border: css('--border', '#e2e8f0'),
    soft: css('--border', '#e2e8f0'), themed: HAS_THEME(),
  });
  const hexToRgb = h => [0, 2, 4].map(i => parseInt(h.slice(i, i + 2), 16)).join(',');
  const RGB = {}; // "r,g,b" → corporativo
  Object.keys(MAP).forEach(k => { RGB[hexToRgb(k)] = MAP[k]; });
  RGB['15,76,129'] = CORP.azul; // rgba(15,76,129,x) marca vieja

  function norm(c, t, key) {
    if (typeof c !== 'string') return c;
    let m = c.match(/^#([0-9a-f]{3}|[0-9a-f]{6})([0-9a-f]{2})?$/i);
    if (m) {
      let h = m[1].toLowerCase(); const a = m[2] || '';
      if (WHITE.has(h)) return (!a && t.themed && /^border/i.test(key || '')) ? t.surface : c; // borde blanco de dona → superficie
      if (h.length === 3) h = h.split('').map(x => x + x).join('');
      if (MAP[h]) return MAP[h] + a;
      if (!a && t.themed && TEXT.has(h)) return t.text;
      if (!a && t.themed && MUTED.has(h)) return t.muted;
      if (!a && t.themed && SOFT.has(h)) return t.soft;
      return c;
    }
    m = c.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i);
    if (m) {
      const corp = RGB[`${m[1]},${m[2]},${m[3]}`];
      if (!corp) return c;
      const al = m[4] === undefined ? 1 : parseFloat(m[4]);
      return al >= 1 ? corp : `rgba(${hexToRgb(corp.slice(1))},${al})`;
    }
    return c;
  }
  const COLOR_KEYS = /color$|^backgroundColor$|^borderColor$|^pointBackgroundColor$|^pointBorderColor$|^hoverBackgroundColor$|^hoverBorderColor$/i;
  function walk(obj, t, depth) {
    if (!obj || typeof obj !== 'object' || depth > 6) return;
    for (const k of Object.keys(obj)) {
      if (k[0] === '$') continue;            // $wmsOrig: copia intacta de los originales
      const v = obj[k];
      if (COLOR_KEYS.test(k)) {
        if (typeof v === 'string') obj[k] = norm(v, t, k);
        else if (Array.isArray(v)) obj[k] = v.map(x => norm(x, t, k));
      } else if (v && typeof v === 'object' && !(v instanceof HTMLElement) && k !== 'data' && k !== '_meta') {
        walk(v, t, depth + 1);
      }
    }
  }

  Chart.register({
    id: 'wmsCorporativo',
    beforeInit(chart) {
      const t = theme();
      (chart.config.data.datasets || []).forEach(ds => {
        // guardar colores originales para recalcular al cambiar claro/oscuro
        ds.$wmsOrig = {};
        Object.keys(ds).forEach(k => { if (COLOR_KEYS.test(k)) ds.$wmsOrig[k] = Array.isArray(ds[k]) ? ds[k].slice() : ds[k]; });
        walk(ds, t, 0);
      });
      walk(chart.config.options, t, 0);
    },
  });

  // Defaults corporativos (cada gráfico los hereda salvo que los fije)
  const applyDefaults = () => {
    const t = theme();
    if (!t.themed) return;
    Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = t.tick;
    Chart.defaults.borderColor = t.grid;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;
    Chart.defaults.plugins.legend.labels.color = t.muted;
    Object.assign(Chart.defaults.plugins.tooltip, {
      backgroundColor: t.surface, titleColor: t.text, bodyColor: t.muted,
      borderColor: t.border, borderWidth: 1, cornerRadius: 8, padding: 10,
      titleFont: { weight: '600', size: 11 }, bodyFont: { size: 11 }, boxPadding: 4, usePointStyle: true,
    });
    Chart.defaults.elements.bar.borderRadius = 4;
    Chart.defaults.elements.line.tension = 0.3;
    Chart.defaults.elements.point.radius = 2;
    Chart.defaults.elements.arc.borderColor = t.surface;
    if (Chart.defaults.scale) {
      Chart.defaults.scale.grid.color = t.grid;
      Chart.defaults.scale.ticks.color = t.tick;
    }
  };
  applyDefaults();
  // El script carga en <head> antes que bee-theme.css: reaplicar cuando ya existan los tokens
  document.addEventListener('DOMContentLoaded', applyDefaults);
  window.addEventListener('load', applyDefaults);

  // Al cambiar claro/oscuro: nuevos defaults + repintar los gráficos abiertos
  new MutationObserver(() => {
    applyDefaults();
    const t = theme();
    if (!t.themed) return;
    Object.values(Chart.instances || {}).forEach(ch => {
      const o = ch.options;
      Object.values(o.scales || {}).forEach(s => {
        if (s.ticks) s.ticks.color = t.tick;
        if (s.grid) s.grid.color = t.grid;
        if (s.title) s.title.color = t.muted;
      });
      if (o.plugins?.legend?.labels) o.plugins.legend.labels.color = t.muted;
      if (o.plugins?.tooltip) Object.assign(o.plugins.tooltip, { backgroundColor: t.surface, titleColor: t.text, bodyColor: t.muted, borderColor: t.border });
      (ch.data.datasets || []).forEach(ds => {
        if (ds.$wmsOrig) { Object.assign(ds, ds.$wmsOrig); ds.$wmsOrig = { ...ds.$wmsOrig }; walk(ds, t, 0); }
      });
      ch.update('none');
    });
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
})();
