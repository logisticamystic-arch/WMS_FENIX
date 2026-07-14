// public/assets/js/desktop/devoluciones.js
'use strict';
WMS_MODULES.devoluciones = {

  /* ─────────────────────────────────────────────────────────────────────────
     ESTADO INTERNO
  ───────────────────────────────────────────────────────────────────────── */
  _state: {
    lista:      [],
    detalle:    null,
    qrProd:     null,
    items:      [],
    causales:   [],
    vista:      'dashboard',   // 'dashboard' | 'lista' | 'causales' | 'nueva'
  },
  _filterTimer: null,
  _debounceUbic: null,
  _debounceCliente: null,

  /* ─────────────────────────────────────────────────────────────────────────
     PUNTO DE ENTRADA
  ───────────────────────────────────────────────────────────────────────── */
  load(sub) {
    sub = sub || 'dashboard';
    if (sub === 'dashboard') return this.loadDevoluciones();
    if (sub === 'lista')     return this.showLista();
    if (sub === 'causales')  return this.showCausales();
    if (sub === 'nueva')     return this.showFormDevolucion();
    return this.loadDevoluciones();
  },

  /* ─────────────────────────────────────────────────────────────────────────
     NAVEGACIÓN — barra de pestañas presente en todas las vistas
  ───────────────────────────────────────────────────────────────────────── */
  _navBar(activa) {
    const tabs = [
      { id: 'dashboard', label: 'Dashboard KPI',    icon: 'fa-chart-pie' },
      { id: 'lista',     label: 'Listado',           icon: 'fa-list' },
      { id: 'causales',  label: 'Causales',          icon: 'fa-tags' },
      { id: 'nueva',     label: 'Nueva Devolución',  icon: 'fa-plus-circle' },
    ];
    return `
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
        ${tabs.map(t => `
          <button class="btn btn-sm ${activa === t.id ? 'btn-primary' : 'btn-secondary'}"
            onclick="WMS_MODULES.devoluciones.load('${t.id}')">
            <i class="fa-solid ${t.icon}"></i> ${t.label}
          </button>`).join('')}
      </div>`;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     A) DASHBOARD KPI
  ═══════════════════════════════════════════════════════════════════════ */
  async loadDevoluciones() {
    this._state.vista = 'dashboard';
    WMS.setToolbar(this._navBar('dashboard'));

    /* Filtros iniciales */
    const hoy   = new Date();
    const desde = new Date(hoy.getFullYear(), hoy.getMonth() - 5, 1)
                    .toISOString().substring(0, 10);
    const hasta = hoy.toISOString().substring(0, 10);

    /* Skeleton mientras carga */
    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <!-- Filtros -->
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Desde</label>
              <input type="date" id="dvd-desde" class="form-control form-control-sm" value="${desde}">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Hasta</label>
              <input type="date" id="dvd-hasta" class="form-control form-control-sm" value="${hasta}">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Causal</label>
              <select id="dvd-causal" class="form-control form-control-sm" style="min-width:160px;">
                <option value="">Todas las causales</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Responsable</label>
              <input type="text" id="dvd-responsable" class="form-control form-control-sm" placeholder="Nombre responsable" style="min-width:160px;">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Referencia</label>
              <input type="text" id="dvd-referencia" class="form-control form-control-sm" placeholder="N° referencia ERP" style="min-width:140px;">
            </div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._aplicarDashboard()">
              <i class="fa-solid fa-filter"></i> Aplicar
            </button>
          </div>
        </div>

        <!-- KPIs placeholder -->
        <div id="dvd-kpis" class="pro-kpi-grid" style="margin-bottom:16px;">
          ${[0,1,2,3].map(() => `
            <div class="pro-kpi-card" style="min-height:90px;">
              <div style="background:#f1f5f9;border-radius:8px;height:60px;animation:pulse 1.5s ease-in-out infinite;"></div>
            </div>`).join('')}
        </div>

        <!-- Gráficos -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="dvd-charts-row">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-chart-bar" style="margin-right:6px;color:#3b82f6;"></i> Devoluciones por mes
            </div>
            <div id="dvd-bar-chart" style="min-height:180px;display:flex;align-items:center;justify-content:center;">
              <span style="color:#94a3b8;font-size:12px;">Cargando...</span>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-chart-pie" style="margin-right:6px;color:#8b5cf6;"></i> Distribución por causal
            </div>
            <div id="dvd-pie-chart" style="min-height:180px;display:flex;align-items:center;justify-content:center;">
              <span style="color:#94a3b8;font-size:12px;">Cargando...</span>
            </div>
          </div>
        </div>

        <!-- Tabla últimas -->
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Últimas devoluciones</span>
            <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._exportarCSV()">
              <i class="fa-solid fa-file-csv"></i> Exportar CSV
            </button>
          </div>
          <div class="table-container" id="dvd-tabla"></div>
        </div>
      </div>`);

    /* Cargar causales para el select */
    this._cargarCausalesSelect('dvd-causal');

    /* Cargar datos del dashboard */
    await this._aplicarDashboard();
  },

  async _cargarCausalesSelect(selectId) {
    try {
      const r = await API.get('/devoluciones/causales?activo=1');
      this._state.causales = r.data || [];
      const sel = document.getElementById(selectId);
      if (!sel) return;
      const current = sel.value;
      /* Mantener primera opción vacía */
      const extras = this._state.causales.map(c =>
        `<option value="${c.id}" ${current == c.id ? 'selected' : ''}>${WMS.esc(c.causal)}</option>`
      ).join('');
      sel.innerHTML = `<option value="">Todas las causales</option>${extras}`;
    } catch(e) { /* silencioso */ }
  },

  async _aplicarDashboard() {
    const desde      = document.getElementById('dvd-desde')?.value || '';
    const hasta      = document.getElementById('dvd-hasta')?.value || '';
    const causal     = document.getElementById('dvd-causal')?.value || '';
    const responsable= document.getElementById('dvd-responsable')?.value || '';
    const referencia = document.getElementById('dvd-referencia')?.value || '';

    const params = new URLSearchParams();
    if (desde)      params.set('desde', desde);
    if (hasta)      params.set('hasta', hasta);
    if (causal)     params.set('causal_id', causal);
    if (responsable)params.set('responsable', responsable);
    if (referencia) params.set('referencia', referencia);

    try {
      const r = await API.get('/devoluciones/dashboard?' + params.toString());
      const d = r.data || {};
      this._renderKPIs(d.kpis || {});
      this._renderBarChart(d.por_mes || [], 'dvd-bar-chart');
      this._renderPieChart(d.por_causal || [], 'dvd-pie-chart');
      this._renderTablaUltimas(d.ultimas || []);
    } catch(e) {
      /* Fallback: cargar lista normal para la tabla */
      try {
        const r2 = await API.get('/devoluciones?' + params.toString());
        const rows = r2.data || [];
        this._renderKPIs(this._calcKPIsLocales(rows));
        this._renderBarChart(this._calcPorMesLocal(rows), 'dvd-bar-chart');
        this._renderPieChart(this._calcPorCausalLocal(rows), 'dvd-pie-chart');
        this._renderTablaUltimas(rows.slice(0, 30));
      } catch(e2) {
        WMS.toast('error', 'Error al cargar dashboard');
      }
    }
  },

  /* Cálculos locales cuando no existe endpoint /dashboard */
  _calcKPIsLocales(rows) {
    const total = rows.length;
    const defecto  = rows.filter(r => /defecto|daño|averi/i.test(r.causal || r.motivo_general || '')).length;
    const errorOp  = rows.filter(r => /error|operac/i.test(r.causal || r.motivo_general || '')).length;
    const refSet   = new Set(rows.flatMap(r => (r.detalles||[]).map(d => d.producto_id)));
    return { total, defecto, error_operacion: errorOp, referencias: refSet.size };
  },
  _calcPorMesLocal(rows) {
    const map = {};
    rows.forEach(r => {
      const mes = (r.created_at || '').substring(0, 7);
      if (mes) map[mes] = (map[mes] || 0) + 1;
    });
    return Object.entries(map).sort().slice(-6).map(([mes, cantidad]) => ({ mes, cantidad }));
  },
  _calcPorCausalLocal(rows) {
    const map = {};
    rows.forEach(r => {
      const clave = r.causal || r.motivo_general || 'Sin causal';
      map[clave] = (map[clave] || 0) + 1;
    });
    return Object.entries(map).map(([causal, cantidad]) => ({ causal, cantidad }));
  },

  _renderKPIs(kpis) {
    const el = document.getElementById('dvd-kpis');
    if (!el) return;
    const cards = [
      {
        label: 'Total devoluciones',
        value: kpis.total ?? 0,
        sub:   'Período seleccionado',
        icon:  'fa-rotate-left',
        accent:'accent-blue',
        trend: null,
      },
      {
        label: 'Defecto / Daño',
        value: kpis.defecto ?? 0,
        sub:   'Producto defectuoso o dañado',
        icon:  'fa-triangle-exclamation',
        accent:'accent-red',
        trend: null,
      },
      {
        label: 'Error de operación',
        value: kpis.error_operacion ?? 0,
        sub:   'Error en picking/despacho',
        icon:  'fa-clipboard-list',
        accent:'accent-amber',
        trend: null,
      },
      {
        label: 'Referencias afectadas',
        value: kpis.referencias ?? 0,
        sub:   'SKUs involucrados',
        icon:  'fa-boxes-stacked',
        accent:'accent-purple',
        trend: null,
      },
    ];

    el.innerHTML = cards.map(c => `
      <div class="pro-kpi-card ${c.accent}">
        <div class="pro-kpi-header">
          <div class="pro-kpi-icon"><i class="fa-solid ${c.icon}"></i></div>
          ${c.trend ? `<span class="pro-kpi-trend ${c.trend.dir}">${c.trend.label}</span>` : ''}
        </div>
        <div class="pro-kpi-value">${WMS.formatNum ? WMS.formatNum(c.value) : c.value}</div>
        <div class="pro-kpi-label">${c.label}</div>
        <div class="pro-kpi-sub">${c.sub}</div>
      </div>`).join('');
  },

  _renderTablaUltimas(rows) {
    const el = document.getElementById('dvd-tabla');
    if (!el) return;
    const badgeColor = {
      PendienteAprobacion:'#f59e0b', Aprobada:'#3b82f6', Procesada:'#16a34a',
      Rechazada:'#dc2626', Anulada:'#94a3b8', Borrador:'#64748b',
    };
    if (!rows.length) {
      el.innerHTML = '<p style="padding:20px;text-align:center;color:#94a3b8;font-size:13px;">Sin registros en el período seleccionado.</p>';
      return;
    }
    el.innerHTML = `
      <table class="erp-table">
        <thead><tr>
          <th>#</th><th>Fecha</th><th>Proveedor / Cliente</th>
          <th>Causal</th><th>Responsable</th><th>Ref</th>
          <th class="text-center">Cant.</th><th>Estado</th>
        </tr></thead>
        <tbody>
          ${rows.map(d => {
            const color = badgeColor[d.estado] || '#94a3b8';
            return `<tr style="cursor:pointer;" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
              <td><strong>${WMS.esc(d.numero_devolucion || ('#' + d.id))}</strong></td>
              <td style="font-size:11px;">${(d.created_at||'').substring(0,10) || '-'}</td>
              <td>${WMS.esc(d.tercero_nombre || d.tercero || d.cliente || d.proveedor || '-')}</td>
              <td style="font-size:11px;">${WMS.esc(d.causal || d.motivo_general || '-')}</td>
              <td style="font-size:11px;">${WMS.esc(d.responsable || d.solicitado_por_nombre || '-')}</td>
              <td style="font-size:11px;"><code>${WMS.esc(d.referencia_externa || '-')}</code></td>
              <td class="text-center">${(d.detalles||[]).length || d.cantidad || '-'}</td>
              <td><span class="badge" style="background:${color}20;color:${color};border:1px solid ${color};white-space:nowrap;">
                ${WMS.esc(d.estado || '-')}
              </span></td>
            </tr>`;
          }).join('')}
        </tbody>
      </table>`;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     B) GRÁFICO DE BARRAS SVG
  ═══════════════════════════════════════════════════════════════════════ */
  _renderBarChart(datos, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;

    if (!datos || !datos.length) {
      el.innerHTML = '<p style="text-align:center;color:#94a3b8;font-size:12px;padding:40px 0;">Sin datos para el período</p>';
      return;
    }

    const W = 460, H = 200, PAD = { top: 24, right: 16, bottom: 40, left: 40 };
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;

    const maxVal  = Math.max(...datos.map(d => d.cantidad), 1);
    const barW    = Math.floor(innerW / datos.length * 0.55);
    const gap     = innerW / datos.length;
    const barColor= '#3b82f6';

    /* Etiquetas de mes amigables */
    const mesLabel = (s) => {
      if (!s) return '';
      const [y, m] = s.split('-');
      const meses  = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
      return (meses[parseInt(m,10)-1] || m) + ' ' + (y||'').slice(2);
    };

    /* Líneas de grilla Y (4 niveles) */
    const gridLines = [0, 0.25, 0.5, 0.75, 1].map(p => {
      const y = PAD.top + innerH * (1 - p);
      const v = Math.round(maxVal * p);
      return `<line x1="${PAD.left}" y1="${y}" x2="${PAD.left + innerW}" y2="${y}"
                stroke="#e2e8f0" stroke-width="1" stroke-dasharray="${p===0?'0':'4 3'}"/>
              <text x="${PAD.left - 6}" y="${y + 4}" text-anchor="end"
                font-size="9" fill="#94a3b8">${v}</text>`;
    }).join('');

    /* Barras */
    const bars = datos.map((d, i) => {
      const x    = PAD.left + gap * i + gap / 2 - barW / 2;
      const bH   = Math.max(2, (d.cantidad / maxVal) * innerH);
      const y    = PAD.top + innerH - bH;
      const lx   = x + barW / 2;
      return `
        <rect x="${x}" y="${y}" width="${barW}" height="${bH}" rx="3" fill="${barColor}" opacity=".9">
          <title>${mesLabel(d.mes)}: ${d.cantidad}</title>
        </rect>
        <text x="${lx}" y="${y - 5}" text-anchor="middle" font-size="9" font-weight="700" fill="${barColor}">${d.cantidad}</text>
        <text x="${lx}" y="${PAD.top + innerH + 14}" text-anchor="middle" font-size="9" fill="#64748b">${mesLabel(d.mes)}</text>`;
    }).join('');

    el.innerHTML = `
      <svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block;">
        ${gridLines}
        ${bars}
        <!-- Eje Y -->
        <line x1="${PAD.left}" y1="${PAD.top}" x2="${PAD.left}" y2="${PAD.top+innerH}"
          stroke="#cbd5e1" stroke-width="1.5"/>
        <!-- Eje X -->
        <line x1="${PAD.left}" y1="${PAD.top+innerH}" x2="${PAD.left+innerW}" y2="${PAD.top+innerH}"
          stroke="#cbd5e1" stroke-width="1.5"/>
      </svg>`;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     C) GRÁFICO DE TORTA SVG
  ═══════════════════════════════════════════════════════════════════════ */
  _renderPieChart(datos, containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;

    if (!datos || !datos.length) {
      el.innerHTML = '<p style="text-align:center;color:#94a3b8;font-size:12px;padding:40px 0;">Sin datos</p>';
      return;
    }

    const COLORS = ['#3b82f6','#ef4444','#f59e0b','#10b981','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
    const total  = datos.reduce((s, d) => s + (d.cantidad || 0), 0) || 1;

    /* Segmentos */
    const CX = 80, CY = 80, R = 65;
    let angle = -Math.PI / 2;
    const segs = datos.map((d, i) => {
      const pct   = d.cantidad / total;
      const sweep = pct * 2 * Math.PI;
      const x1    = CX + R * Math.cos(angle);
      const y1    = CY + R * Math.sin(angle);
      angle      += sweep;
      const x2    = CX + R * Math.cos(angle);
      const y2    = CY + R * Math.sin(angle);
      const large = sweep > Math.PI ? 1 : 0;
      const color = COLORS[i % COLORS.length];
      return `<path d="M ${CX} ${CY} L ${x1} ${y1} A ${R} ${R} 0 ${large} 1 ${x2} ${y2} Z"
                fill="${color}" stroke="#fff" stroke-width="2" opacity=".92">
                <title>${WMS.esc(d.causal||'Sin causal')}: ${d.cantidad} (${Math.round(pct*100)}%)</title>
              </path>`;
    }).join('');

    /* Leyenda */
    const leyenda = datos.map((d, i) => {
      const pct   = Math.round(d.cantidad / total * 100);
      const color = COLORS[i % COLORS.length];
      const label = (d.causal || 'Sin causal').length > 22
        ? (d.causal || 'Sin causal').substring(0, 22) + '…'
        : (d.causal || 'Sin causal');
      return `
        <div style="display:flex;align-items:center;gap:7px;font-size:11px;margin-bottom:5px;">
          <span style="width:10px;height:10px;border-radius:2px;background:${color};flex-shrink:0;"></span>
          <span style="color:#1e293b;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(label)}</span>
          <span style="color:#64748b;font-weight:600;white-space:nowrap;">${d.cantidad} (${pct}%)</span>
        </div>`;
    }).join('');

    el.innerHTML = `
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
        <svg viewBox="0 0 160 160" style="width:160px;height:160px;flex-shrink:0;">
          ${segs}
          <circle cx="${CX}" cy="${CY}" r="28" fill="white"/>
          <text x="${CX}" y="${CY - 4}" text-anchor="middle" font-size="11" font-weight="700" fill="#1e293b">${total}</text>
          <text x="${CX}" y="${CY + 12}" text-anchor="middle" font-size="8" fill="#64748b">Total</text>
        </svg>
        <div style="flex:1;min-width:150px;">${leyenda}</div>
      </div>`;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     EXPORTAR CSV
  ═══════════════════════════════════════════════════════════════════════ */
  async _exportarCSV() {
    try {
      WMS.spinner();
      const desde      = document.getElementById('dvd-desde')?.value || '';
      const hasta      = document.getElementById('dvd-hasta')?.value || '';
      const causal     = document.getElementById('dvd-causal')?.value || '';
      const responsable= document.getElementById('dvd-responsable')?.value || '';
      const referencia = document.getElementById('dvd-referencia')?.value || '';
      const params     = new URLSearchParams();
      if (desde)      params.set('desde', desde);
      if (hasta)      params.set('hasta', hasta);
      if (causal)     params.set('causal_id', causal);
      if (responsable)params.set('responsable', responsable);
      if (referencia) params.set('referencia', referencia);

      const r    = await API.get('/devoluciones?' + params.toString());
      const rows = r.data || [];

      const header = ['N°','Fecha','Proveedor/Cliente','Causal','Responsable','Referencia','Items','Estado'];
      const lineas = [header.join(';')];
      rows.forEach(d => {
        lineas.push([
          d.numero_devolucion || d.id,
          (d.created_at||'').substring(0,10),
          d.tercero_nombre || d.tercero || d.cliente || d.proveedor || '',
          d.causal || d.motivo_general || '',
          d.responsable || d.solicitado_por_nombre || '',
          d.referencia_externa || '',
          (d.detalles||[]).length,
          d.estado || '',
        ].map(v => `"${String(v).replace(/"/g,'""')}"`).join(';'));
      });

      const blob = new Blob(['﻿' + lineas.join('\n')], { type:'text/csv;charset=utf-8;' });
      const url  = URL.createObjectURL(blob);
      const a    = document.createElement('a');
      a.href     = url;
      a.download = `devoluciones_${desde}_${hasta}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      WMS.toast('success', `CSV exportado — ${rows.length} registros`);
    } catch(e) { WMS.toast('error', 'Error al exportar CSV'); }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     LISTADO (vista clásica mantenida + export CSV)
  ═══════════════════════════════════════════════════════════════════════ */
  async showLista(filtros = {}) {
    this._state.vista = 'lista';
    WMS.setToolbar(this._navBar('lista'));
    WMS.spinner();
    const qs = new URLSearchParams(filtros).toString();
    try {
      const r = await API.get('/devoluciones' + (qs ? '?' + qs : ''));
      this._state.lista = r.data || [];
      this._renderLista(this._state.lista);
    } catch(e) { WMS.toast('error', 'Error al cargar devoluciones'); }
  },

  _renderLista(rows) {
    const badgeColor = {
      PendienteAprobacion:'#f59e0b', Aprobada:'#3b82f6', Procesada:'#16a34a',
      Rechazada:'#dc2626', Anulada:'#94a3b8', Borrador:'#64748b',
    };
    const tipoLabel = {
      cliente:'Cliente→WMS', proveedor:'WMS→Proveedor', interna:'Interna',
      AProveedorAveria:'Proveedor (Avería)', AProveedorVencido:'Proveedor (Vencido)',
      ReingresoBuenEstado:'Reingreso', Borrador:'Sin tipo',
    };

    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
          <span class="card-title"><i class="fa-solid fa-rotate-left"></i> Devoluciones</span>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select id="dv-f-tipo" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los tipos</option>
              <option value="cliente">Cliente→WMS</option>
              <option value="proveedor">WMS→Proveedor</option>
              <option value="interna">Interna</option>
            </select>
            <select id="dv-f-estado" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los estados</option>
              <option value="PendienteAprobacion">Pendiente Aprobación</option>
              <option value="Aprobada">Aprobada</option>
              <option value="Procesada">Procesada</option>
              <option value="Rechazada">Rechazada</option>
              <option value="Anulada">Anulada</option>
            </select>
            <input type="date" id="dv-f-desde" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="date" id="dv-f-hasta" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="text" id="dv-f-q" class="form-control form-control-sm" placeholder="Buscar N°, referencia..." style="min-width:180px;"
              oninput="WMS_MODULES.devoluciones._aplicarFiltros()">
            <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._exportarCSVLista()">
              <i class="fa-solid fa-file-csv"></i> CSV
            </button>
          </div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>N°</th><th>Tipo</th><th>Estado</th><th>Referencia ERP</th>
              <th class="text-center">Ítems</th><th>Fecha</th><th>Solicitado por</th><th>Acciones</th>
            </tr></thead>
            <tbody id="dv-tbody">
              ${rows.length ? rows.map(d => `
                <tr>
                  <td><strong>${WMS.esc(d.numero_devolucion)}</strong></td>
                  <td><span class="badge" style="background:#e0f2fe;color:#0369a1;">${WMS.esc(tipoLabel[d.tipo]||d.tipo)}</span></td>
                  <td><span class="badge" style="background:${badgeColor[d.estado]||'#94a3b8'}20;color:${badgeColor[d.estado]||'#94a3b8'};border:1px solid ${badgeColor[d.estado]||'#94a3b8'};">${WMS.esc(d.estado)}</span></td>
                  <td>${WMS.esc(d.referencia_externa||'-')}</td>
                  <td class="text-center">${(d.detalles||[]).length}</td>
                  <td style="font-size:11px;">${d.created_at ? d.created_at.substring(0,10) : '-'}</td>
                  <td style="font-size:11px;">${WMS.esc(d.solicitado_por_nombre||'-')}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
                      <i class="fa-solid fa-eye"></i> Ver
                    </button>
                  </td>
                </tr>`).join('') : '<tr><td colspan="8" class="table-empty">Sin devoluciones registradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _aplicarFiltros() {
    clearTimeout(this._filterTimer);
    this._filterTimer = setTimeout(() => {
      const f = {
        tipo:   document.getElementById('dv-f-tipo')?.value   || '',
        estado: document.getElementById('dv-f-estado')?.value || '',
        desde:  document.getElementById('dv-f-desde')?.value  || '',
        hasta:  document.getElementById('dv-f-hasta')?.value  || '',
        q:      document.getElementById('dv-f-q')?.value      || '',
      };
      Object.keys(f).forEach(k => { if (!f[k]) delete f[k]; });
      this.showLista(f);
    }, 400);
  },

  async _exportarCSVLista() {
    const rows = this._state.lista;
    if (!rows.length) { WMS.toast('error', 'Sin datos para exportar'); return; }
    const header = ['N°','Tipo','Estado','Referencia ERP','Items','Fecha','Solicitado por'];
    const lineas = [header.join(';')];
    rows.forEach(d => {
      lineas.push([
        d.numero_devolucion,
        d.tipo,
        d.estado,
        d.referencia_externa||'',
        (d.detalles||[]).length,
        (d.created_at||'').substring(0,10),
        d.solicitado_por_nombre||'',
      ].map(v=>`"${String(v).replace(/"/g,'""')}"`).join(';'));
    });
    const blob = new Blob(['﻿'+lineas.join('\n')],{type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a'); a.href=url; a.download='devoluciones.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    WMS.toast('success','CSV exportado');
  },

  /* ═══════════════════════════════════════════════════════════════════════
     DETALLE (conservado del original)
  ═══════════════════════════════════════════════════════════════════════ */
  async showDetalle(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/' + id);
      const d = r.data;
      this._state.detalle = d;
      const estado = d.estado;

      const canAprobar  = estado === 'PendienteAprobacion';
      const canProcesar = estado === 'Aprobada';
      const canAnular   = ['PendienteAprobacion','Borrador'].includes(estado);
      const rol  = _wmsUser?.rol ?? '';
      const isSup= ['Admin','Supervisor','SuperAdmin','Jefe'].includes(rol);

      const badgeColor = {
        PendienteAprobacion:'#f59e0b',Aprobada:'#3b82f6',Procesada:'#16a34a',
        Rechazada:'#dc2626',Anulada:'#94a3b8',Borrador:'#64748b',
      };

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
          <i class="fa-solid fa-arrow-left"></i> Volver
        </button>
        ${isSup && canAprobar  ? `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.devoluciones.aprobar(${d.id})"><i class="fa-solid fa-check"></i> Aprobar</button>` : ''}
        ${isSup && canAprobar  ? `<button class="btn btn-danger btn-sm" onclick="WMS_MODULES.devoluciones.rechazar(${d.id})"><i class="fa-solid fa-times"></i> Rechazar</button>` : ''}
        ${canProcesar           ? `<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.abrirProcesar(${d.id})"><i class="fa-solid fa-gears"></i> Procesar</button>` : ''}
        ${isSup && canAnular    ? `<button class="btn btn-outline-danger btn-sm" onclick="WMS_MODULES.devoluciones.anular(${d.id})"><i class="fa-solid fa-ban"></i> Anular</button>` : ''}`);

      WMS.setContent(`
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title"><i class="fa-solid fa-rotate-left"></i> ${WMS.esc(d.numero_devolucion)}</span>
            <span class="badge" style="background:${badgeColor[estado]||'#94a3b8'}20;color:${badgeColor[estado]||'#94a3b8'};border:1px solid ${badgeColor[estado]||'#94a3b8'};font-size:13px;">${WMS.esc(estado)}</span>
          </div>
          <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:13px;">
            <div><div style="color:#64748b;font-size:11px;">Tipo</div><strong>${WMS.esc(d.tipo)}</strong></div>
            <div><div style="color:#64748b;font-size:11px;">Referencia ERP</div>${WMS.esc(d.referencia_externa||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Motivo</div>${WMS.esc(d.motivo_general)}</div>
            <div><div style="color:#64748b;font-size:11px;">Solicitado por</div>${WMS.esc(d.solicitado_por||d.auxiliar_id||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Aprobado por</div>${WMS.esc(d.aprobado_por||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Fecha</div>${d.created_at?d.created_at.substring(0,10):'-'}</div>
          </div>
          <div class="table-container">
            <table class="erp-table" style="font-size:12px;">
              <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th><th>Nota</th></tr></thead>
              <tbody>
                ${(d.detalles||[]).map(det => `<tr>
                  <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
                  <td><code>${WMS.esc(det.lote||'-')}</code></td>
                  <td style="font-size:11px;">${det.fecha_vencimiento||'-'}</td>
                  <td class="text-center fw-700">${WMS.formatNum(det.cantidad)}</td>
                  <td>${WMS.esc(det.condicion||'-')}</td>
                  <td>${det.destino ? `<span class="badge" style="background:#f0fdf4;color:#16a34a;">${WMS.esc(det.destino)}</span>` : '<span style="color:#94a3b8;">—</span>'}</td>
                  <td style="font-size:11px;color:#64748b;">${WMS.esc(det.detalle_motivo||det.motivo_item||'-')}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error al cargar detalle'); }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     ACCIONES (aprobar / rechazar / anular / procesar — del original)
  ═══════════════════════════════════════════════════════════════════════ */
  async aprobar(id) {
    if (!confirm('¿Aprobar esta devolución?')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/aprobar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución aprobada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al aprobar'); }
  },

  async rechazar(id) {
    const motivo = prompt('Motivo del rechazo (opcional):') ?? '';
    try {
      const r = await API.post('/devoluciones/' + id + '/rechazar', { motivo_rechazo: motivo });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución rechazada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al rechazar'); }
  },

  async anular(id) {
    if (!confirm('¿Anular esta devolución? Esta acción no se puede deshacer.')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/anular', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución anulada');
      this.showLista();
    } catch(e) { WMS.toast('error', 'Error al anular'); }
  },

  abrirProcesar(id) {
    const d = this._state.detalle;
    if (!d) return;
    const destOpts = `<option value="">-- Seleccionar --</option><option value="restock">Restock al inventario</option><option value="descarte">Descarte</option><option value="proveedor">→ Proveedor</option>`;
    const rows = (d.detalles||[]).map(det => `
      <tr>
        <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
        <td><code>${WMS.esc(det.lote||'-')}</code></td>
        <td class="text-center">${WMS.formatNum(det.cantidad)}</td>
        <td>${WMS.esc(det.condicion||'-')}</td>
        <td>
          <select class="form-control form-control-sm proc-dest" data-id="${det.id}" style="min-width:160px;">
            ${destOpts}
          </select>
        </td>
      </tr>`).join('');
    const html = `
      <div id="proc-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:24px 28px;min-width:600px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 16px;font-size:16px;"><i class="fa-solid fa-gears"></i> Procesar Devolución — ${WMS.esc(d.numero_devolucion)}</h3>
          <p style="font-size:12px;color:#64748b;margin-bottom:12px;">Asigna el destino de cada ítem antes de confirmar.</p>
          <table class="erp-table" style="font-size:12px;">
            <thead><tr><th>Producto</th><th>Lote</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('proc-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.confirmarProcesar(${id})">
              <i class="fa-solid fa-check"></i> Confirmar Procesamiento
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async confirmarProcesar(id) {
    const selects = document.querySelectorAll('.proc-dest');
    const items = [];
    let valid = true;
    selects.forEach(s => {
      if (!s.value) { valid = false; s.style.borderColor = '#dc2626'; }
      else { s.style.borderColor = ''; }
      items.push({ id: parseInt(s.dataset.id), destino: s.value });
    });
    if (!valid) { WMS.toast('error', 'Todos los ítems deben tener destino'); return; }
    document.getElementById('proc-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/devoluciones/' + id + '/procesar', { items });
      if (r.error) { WMS.toast('error', r.message); return; }
      let msg = 'Devolución procesada correctamente';
      if (r.data?.devolucion_proveedor_id) msg += ` — Se creó automáticamente la devolución al proveedor.`;
      WMS.toast('success', msg);
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al procesar'); }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     D) GESTIÓN DE CAUSALES
  ═══════════════════════════════════════════════════════════════════════ */
  async showCausales() {
    this._state.vista = 'causales';
    WMS.setToolbar(this._navBar('causales'));
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/causales');
      this._state.causales = r.data || [];
      this._renderCausales(this._state.causales);
    } catch(e) { WMS.toast('error', 'Error al cargar causales'); }
  },

  _renderCausales(causales) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-tags"></i> Causales de devolución</span>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._modalNuevaCausal()">
            <i class="fa-solid fa-plus"></i> Nueva Causal
          </button>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>Causal</th><th>Responsable</th><th>Descripción</th>
              <th class="text-center">Activo</th><th>Acciones</th>
            </tr></thead>
            <tbody>
              ${causales.length ? causales.map(c => `
                <tr>
                  <td><strong>${WMS.esc(c.causal)}</strong></td>
                  <td>
                    <span class="badge" style="${this._responsableBadge(c.responsable)}">
                      ${WMS.esc(c.responsable||'-')}
                    </span>
                  </td>
                  <td style="font-size:12px;color:#64748b;">${WMS.esc(c.descripcion||'-')}</td>
                  <td class="text-center">
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
                      <input type="checkbox" ${c.activo ? 'checked' : ''}
                        onchange="WMS_MODULES.devoluciones._toggleCausal(${c.id}, this.checked)"
                        style="width:15px;height:15px;accent-color:#3b82f6;">
                      <span style="color:${c.activo ? '#16a34a' : '#94a3b8'};">
                        ${c.activo ? 'Sí' : 'No'}
                      </span>
                    </label>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._modalEditarCausal(${c.id})">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                  </td>
                </tr>`).join('') : '<tr><td colspan="5" class="table-empty">Sin causales configuradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _responsableBadge(responsable) {
    const map = {
      Proveedor:  'background:#eff6ff;color:#0F4C81;',
      Operacion:  'background:#fffbeb;color:#d97706;',
      Transporte: 'background:#f0fdf4;color:#16a34a;',
      Cliente:    'background:#fdf2f8;color:#9d174d;',
    };
    return map[responsable] || 'background:#f1f5f9;color:#64748b;';
  },

  _modalNuevaCausal() {
    this._abrirModalCausal(null);
  },

  _modalEditarCausal(id) {
    const c = this._state.causales.find(x => x.id === id);
    if (!c) return;
    this._abrirModalCausal(c);
  },

  _abrirModalCausal(c) {
    const titulo = c ? 'Editar Causal' : 'Nueva Causal';
    const html = `
      <div id="causal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:24px 28px;width:480px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 18px;font-size:16px;color:#1e293b;">
            <i class="fa-solid fa-tag"></i> ${titulo}
          </h3>
          <div style="display:grid;gap:14px;">
            <div>
              <label class="form-label">Causal <span style="color:#ef4444;">*</span></label>
              <input type="text" id="mc-causal" class="form-control" placeholder="Ej: Producto dañado en tránsito"
                value="${WMS.esc(c?.causal||'')}">
            </div>
            <div>
              <label class="form-label">Responsable <span style="color:#ef4444;">*</span></label>
              <select id="mc-responsable" class="form-control">
                <option value="">-- Seleccionar --</option>
                ${['Proveedor','Operacion','Transporte','Cliente'].map(op =>
                  `<option value="${op}" ${c?.responsable===op?'selected':''}>${op}</option>`
                ).join('')}
              </select>
            </div>
            <div>
              <label class="form-label">Descripción</label>
              <textarea id="mc-descripcion" class="form-control" rows="3"
                placeholder="Descripción detallada de la causal...">${WMS.esc(c?.descripcion||'')}</textarea>
            </div>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('causal-overlay').remove()">
              Cancelar
            </button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._guardarCausal(${c?.id||'null'})">
              <i class="fa-solid fa-save"></i> Guardar
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('mc-causal')?.focus();
  },

  async _guardarCausal(id) {
    const causal      = document.getElementById('mc-causal')?.value?.trim();
    const responsable = document.getElementById('mc-responsable')?.value;
    const descripcion = document.getElementById('mc-descripcion')?.value?.trim();

    if (!causal)      { WMS.toast('error', 'Ingrese el nombre de la causal'); return; }
    if (!responsable) { WMS.toast('error', 'Seleccione el responsable'); return; }

    const payload = { causal, responsable, descripcion: descripcion || null };
    document.getElementById('causal-overlay')?.remove();
    WMS.spinner();
    try {
      let r;
      if (id) {
        r = await API.put('/devoluciones/causales/' + id, payload);
      } else {
        r = await API.post('/devoluciones/causales', payload);
      }
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', id ? 'Causal actualizada' : 'Causal creada');
      this.showCausales();
    } catch(e) { WMS.toast('error', 'Error al guardar causal'); }
  },

  async _toggleCausal(id, activo) {
    try {
      const r = await API.put('/devoluciones/causales/' + id, { activo: activo ? 1 : 0 });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', activo ? 'Causal activada' : 'Causal desactivada');
      /* Actualiza estado local sin recargar */
      const idx = this._state.causales.findIndex(c => c.id === id);
      if (idx >= 0) this._state.causales[idx].activo = activo ? 1 : 0;
    } catch(e) { WMS.toast('error', 'Error al actualizar causal'); }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     E) FORMULARIO NUEVA DEVOLUCIÓN (enriquecido)
  ═══════════════════════════════════════════════════════════════════════ */
  async showFormDevolucion() {
    this._state.vista = 'nueva';
    this._state.items  = [];
    this._state.qrProd = null;

    WMS.setToolbar(this._navBar('nueva'));

    /* Cargar causales si no las tenemos */
    if (!this._state.causales.length) {
      try {
        const rc = await API.get('/devoluciones/causales?activo=1');
        this._state.causales = rc.data || [];
      } catch(e) { /* sin causales */ }
    }

    const causalesOpts = this._state.causales.map(c =>
      `<option value="${c.id}">${WMS.esc(c.causal)} (${WMS.esc(c.responsable||'?')})</option>`
    ).join('');

    WMS.setContent(`
      <div class="card" style="max-width:900px;">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-plus"></i> Nueva Devolución</span>
        </div>

        <!-- SECCIÓN 1: Datos generales -->
        <div style="padding:20px;border-bottom:1px solid #e2e8f0;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:12px;">
            Datos generales
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
              <label class="form-label">Tipo <span style="color:#ef4444;">*</span></label>
              <select id="dv-new-tipo" class="form-control">
                <option value="cliente">Cliente → WMS</option>
                <option value="proveedor">WMS → Proveedor</option>
                <option value="interna">Interna</option>
              </select>
            </div>
            <div>
              <label class="form-label">Causal <span style="color:#ef4444;">*</span></label>
              <select id="dv-new-causal" class="form-control">
                <option value="">-- Seleccionar causal --</option>
                ${causalesOpts}
              </select>
            </div>
            <div>
              <label class="form-label">Responsable <span style="color:#ef4444;">*</span></label>
              <input type="text" id="dv-new-responsable" class="form-control"
                placeholder="Nombre del responsable">
            </div>
          </div>
        </div>

        <!-- SECCIÓN 2: Cliente / Proveedor -->
        <div style="padding:20px;border-bottom:1px solid #e2e8f0;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:12px;">
            Tercero (Cliente / Proveedor)
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="position:relative;">
              <label class="form-label">Buscar Cliente / Proveedor</label>
              <input type="text" id="dv-new-tercero-q" class="form-control"
                placeholder="Escribe para buscar..."
                oninput="WMS_MODULES.devoluciones._buscarTercero(this.value)"
                autocomplete="off">
              <div id="dv-tercero-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                max-height:200px;overflow-y:auto;font-size:13px;"></div>
              <input type="hidden" id="dv-new-tercero-id">
            </div>
            <div>
              <label class="form-label">Referencia ERP</label>
              <input type="text" id="dv-new-ref" class="form-control" placeholder="Ej: NC-12345 / OC-789">
            </div>
          </div>
        </div>

        <!-- SECCIÓN 3: Ubicación patio -->
        <div style="padding:20px;border-bottom:1px solid #e2e8f0;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:12px;">
            Ubicación en patio
          </div>
          <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;align-items:start;">
            <div style="position:relative;">
              <label class="form-label">Código de ubicación</label>
              <input type="text" id="dv-new-ubic-q" class="form-control"
                placeholder="Ej: PT-A-01"
                oninput="WMS_MODULES.devoluciones._buscarUbicacion(this.value)"
                autocomplete="off">
              <div id="dv-ubic-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                max-height:180px;overflow-y:auto;font-size:13px;"></div>
              <input type="hidden" id="dv-new-ubic-id">
            </div>
            <div>
              <label class="form-label">Motivo general <span style="color:#ef4444;">*</span></label>
              <input type="text" id="dv-new-motivo" class="form-control"
                placeholder="Descripción general de la devolución">
            </div>
          </div>
        </div>

        <!-- SECCIÓN 4: Productos -->
        <div style="padding:20px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:12px;">
            Productos a devolver
          </div>

          <!-- Buscador producto -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:14px;">
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
              <div style="flex:1;min-width:220px;position:relative;">
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-qrcode"></i> Escanear QR / EAN / Código
                </label>
                <input type="text" id="dv-qr-input" class="form-control"
                  placeholder="Escanee QR o escriba código..."
                  onkeydown="if(event.key==='Enter'){WMS_MODULES.devoluciones.buscarQr();event.preventDefault();}">
              </div>
              <div style="flex:1;min-width:200px;position:relative;">
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-magnifying-glass"></i> Buscar por nombre
                </label>
                <input type="text" id="dv-prod-nombre" class="form-control"
                  placeholder="Nombre del producto..."
                  oninput="WMS_MODULES.devoluciones._buscarProducto(this.value)"
                  autocomplete="off">
                <div id="dv-prod-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                  background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                  max-height:180px;overflow-y:auto;font-size:13px;"></div>
              </div>
              <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.devoluciones.buscarQr()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar QR
              </button>
            </div>

            <!-- Producto encontrado -->
            <div id="dv-qr-found" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 12px;margin-top:10px;margin-bottom:10px;font-size:12px;">
              <i class="fa-solid fa-circle-check" style="color:#16a34a;"></i>
              <strong id="dv-qr-nombre"></strong> — Lote: <span id="dv-qr-lote">-</span> / Vence: <span id="dv-qr-fv">-</span>
            </div>

            <!-- Campos adicionales ítem -->
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;margin-top:8px;">
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lote</label>
                <input type="text" id="dv-item-lote" class="form-control form-control-sm" placeholder="Lote">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Fecha Venc.</label>
                <input type="date" id="dv-item-fv" class="form-control form-control-sm">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Cantidad</label>
                <input type="number" id="dv-item-cant" class="form-control form-control-sm" min="0.001" step="0.001" placeholder="0">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Condición</label>
                <select id="dv-item-cond" class="form-control form-control-sm">
                  <option value="bueno">Bueno</option>
                  <option value="dañado">Dañado</option>
                  <option value="vencido">Vencido</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.agregarItem()" style="height:34px;">
                <i class="fa-solid fa-plus"></i> Agregar
              </button>
            </div>
            <!-- Ubicación origen (solo si no está en Patio) -->
            <div id="dv-item-ubic-wrap" style="display:none;margin-top:8px;background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:8px 12px;">
              <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:6px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Mercancía ubicada — indique la ubicación origen
              </div>
              <div style="display:flex;gap:8px;align-items:flex-end;">
                <div style="flex:1;position:relative;">
                  <input type="text" id="dv-item-ubic-q" class="form-control form-control-sm"
                    placeholder="Código ubicación (ej: A-01-01)"
                    oninput="WMS_MODULES.devoluciones._buscarUbicItemOrigen(this.value)"
                    autocomplete="off">
                  <div id="dv-item-ubic-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:300;
                    background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                    max-height:180px;overflow-y:auto;font-size:13px;"></div>
                  <input type="hidden" id="dv-item-ubic-id">
                </div>
                <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.devoluciones._limpiarUbicItemOrigen()">
                  <i class="fa-solid fa-xmark"></i> Sin ubicación
                </button>
              </div>
            </div>
          </div>

          <!-- Tabla de ítems -->
          <div id="dv-items-table"></div>
        </div>

        <!-- Footer -->
        <div style="padding:0 20px 24px;display:flex;justify-content:flex-end;gap:10px;">
          <button class="btn btn-secondary" onclick="WMS_MODULES.devoluciones.showLista()">
            Cancelar
          </button>
          <button class="btn btn-primary" onclick="WMS_MODULES.devoluciones.guardarNueva()">
            <i class="fa-solid fa-save"></i> Registrar Devolución
          </button>
        </div>
      </div>`);

    this._renderItemsTable();
  },

  /* Búsqueda dinámica de terceros (clientes/proveedores) */
  _buscarTercero(q) {
    clearTimeout(this._debounceCliente);
    const drop = document.getElementById('dv-tercero-drop');
    if (!q || q.length < 2) { if(drop) drop.style.display='none'; return; }
    this._debounceCliente = setTimeout(async () => {
      try {
        const r = await API.get('/terceros?q=' + encodeURIComponent(q) + '&limit=8');
        const data = r.data || [];
        if (!drop) return;
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:#94a3b8;">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(t => `
          <div onclick="WMS_MODULES.devoluciones._selTercero(${t.id},'${WMS.esc(t.nombre||t.razon_social||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <strong>${WMS.esc(t.nombre||t.razon_social||t.codigo)}</strong>
            <span style="color:#94a3b8;font-size:11px;"> ${WMS.esc(t.nit||t.codigo||'')}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 350);
  },

  _selTercero(id, nombre) {
    document.getElementById('dv-new-tercero-id').value = id;
    document.getElementById('dv-new-tercero-q').value  = nombre;
    document.getElementById('dv-tercero-drop').style.display = 'none';
  },

  /* Búsqueda dinámica de ubicaciones con debounce */
  _buscarUbicacion(q) {
    clearTimeout(this._debounceUbic);
    const drop = document.getElementById('dv-ubic-drop');
    if (!q || q.length < 1) { if(drop) drop.style.display='none'; return; }
    this._debounceUbic = setTimeout(async () => {
      try {
        const r = await API.get('/param/ubicaciones?codigo=' + encodeURIComponent(q));
        const data = r.data || [];
        if (!drop) return;
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:#94a3b8;">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(u => `
          <div onclick="WMS_MODULES.devoluciones._selUbicacion(${u.id},'${WMS.esc(u.codigo||u.nombre||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <code>${WMS.esc(u.codigo)}</code>
            <span style="color:#64748b;font-size:11px;"> ${WMS.esc(u.nombre||u.descripcion||'')}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 350);
  },

  _selUbicacion(id, codigo) {
    document.getElementById('dv-new-ubic-id').value = id;
    document.getElementById('dv-new-ubic-q').value  = codigo;
    document.getElementById('dv-ubic-drop').style.display = 'none';
  },

  /* Búsqueda de producto por nombre */
  _buscarProducto(q) {
    clearTimeout(this._debounceCliente);
    const drop = document.getElementById('dv-prod-drop');
    if (!q || q.length < 2) { if(drop) drop.style.display='none'; return; }
    this._debounceCliente = setTimeout(async () => {
      try {
        const r = await API.get('/productos?q=' + encodeURIComponent(q) + '&limit=8');
        const data = r.data || [];
        if (!drop) return;
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:#94a3b8;">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(p => `
          <div onclick="WMS_MODULES.devoluciones._selProducto(${p.id},'${WMS.esc(p.nombre||'').replace(/'/g,"\\'")}','${WMS.esc(p.codigo_interno||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <strong style="font-size:12px;">${WMS.esc(p.nombre)}</strong>
            <span style="color:#94a3b8;font-size:11px;"> [${WMS.esc(p.codigo_interno||'')}]</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 350);
  },

  _selProducto(id, nombre, codigo) {
    this._state.qrProd = { id, nombre, codigo };
    document.getElementById('dv-prod-nombre').value             = nombre;
    document.getElementById('dv-prod-drop').style.display       = 'none';
    document.getElementById('dv-qr-nombre').textContent         = nombre;
    document.getElementById('dv-qr-lote').textContent           = '-';
    document.getElementById('dv-qr-fv').textContent             = '-';
    document.getElementById('dv-qr-found').style.display        = 'block';
    document.getElementById('dv-item-ubic-wrap').style.display  = 'block';
    document.getElementById('dv-item-ubic-q').value             = '';
    document.getElementById('dv-item-ubic-id').value            = '';
    document.getElementById('dv-item-cant')?.focus();
  },

  /* ─── QR buscar (conservado) ─── */
  async buscarQr() {
    const qr = document.getElementById('dv-qr-input')?.value?.trim();
    if (!qr) return;
    try {
      const r = await API.get('/recepciones/buscar-qr?q=' + encodeURIComponent(qr));
      if (r.error) { WMS.toast('error', r.message || 'Producto no encontrado'); return; }
      const p = r.data.producto;
      this._state.qrProd = { id: p.id, nombre: p.nombre, codigo: p.codigo_interno };
      document.getElementById('dv-qr-input').value = '';
      document.getElementById('dv-item-lote').value = r.data.lote_raw || '';
      document.getElementById('dv-item-fv').value   = r.data.fecha_vencimiento || '';
      document.getElementById('dv-qr-nombre').textContent = p.nombre;
      document.getElementById('dv-qr-lote').textContent   = r.data.lote_raw || '-';
      document.getElementById('dv-qr-fv').textContent     = r.data.fecha_vencimiento || '-';
      document.getElementById('dv-qr-found').style.display       = 'block';
      document.getElementById('dv-item-ubic-wrap').style.display  = 'block';
      document.getElementById('dv-item-ubic-q').value             = '';
      document.getElementById('dv-item-ubic-id').value            = '';
      document.getElementById('dv-item-cant').focus();
      WMS.toast('success', 'Producto: ' + p.nombre);
    } catch(e) { WMS.toast('error', 'Producto no encontrado'); }
  },

  agregarItem() {
    const prod = this._state.qrProd;
    if (!prod) { WMS.toast('error', 'Busque un producto primero'); return; }
    const cant = parseFloat(document.getElementById('dv-item-cant')?.value || 0);
    if (!cant || cant <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    const ubicOrigenId = parseInt(document.getElementById('dv-item-ubic-id')?.value || 0) || null;
    const ubicOrigenCod = document.getElementById('dv-item-ubic-q')?.value?.trim() || null;
    this._state.items.push({
      producto_id:         prod.id,
      producto_nombre:     prod.nombre,
      codigo:              prod.codigo,
      lote:                document.getElementById('dv-item-lote')?.value || null,
      fecha_vencimiento:   document.getElementById('dv-item-fv')?.value || null,
      cantidad:            cant,
      condicion:           document.getElementById('dv-item-cond')?.value || 'bueno',
      ubicacion_origen_id: ubicOrigenId,
      ubicacion_origen_cod:ubicOrigenCod,
    });
    this._state.qrProd = null;
    document.getElementById('dv-qr-found').style.display    = 'none';
    document.getElementById('dv-item-ubic-wrap').style.display = 'none';
    document.getElementById('dv-qr-input').value   = '';
    document.getElementById('dv-prod-nombre').value = '';
    document.getElementById('dv-item-lote').value   = '';
    document.getElementById('dv-item-fv').value     = '';
    document.getElementById('dv-item-cant').value   = '';
    document.getElementById('dv-item-ubic-q').value = '';
    document.getElementById('dv-item-ubic-id').value= '';
    this._renderItemsTable();
  },

  /* Busca ubicaciones para origen del ítem (con debounce propio) */
  _buscarUbicItemOrigen(q) {
    clearTimeout(this._debounceUbicItem);
    const drop = document.getElementById('dv-item-ubic-drop');
    if (!q || q.length < 1) { if(drop) drop.style.display='none'; return; }
    this._debounceUbicItem = setTimeout(async () => {
      try {
        const r = await API.get('/param/ubicaciones?codigo=' + encodeURIComponent(q) + '&limit=8');
        const data = r.data || [];
        if (!drop) return;
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:#94a3b8;">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(u => `
          <div onclick="WMS_MODULES.devoluciones._selUbicItemOrigen(${u.id},'${WMS.esc(u.codigo||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <code>${WMS.esc(u.codigo)}</code>
            <span style="color:#64748b;font-size:11px;"> ${WMS.esc(u.nombre||u.descripcion||'')}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 350);
  },

  _selUbicItemOrigen(id, codigo) {
    document.getElementById('dv-item-ubic-id').value = id;
    document.getElementById('dv-item-ubic-q').value  = codigo;
    document.getElementById('dv-item-ubic-drop').style.display = 'none';
  },

  _limpiarUbicItemOrigen() {
    document.getElementById('dv-item-ubic-id').value = '';
    document.getElementById('dv-item-ubic-q').value  = '';
    document.getElementById('dv-item-ubic-wrap').style.display = 'none';
  },

  _renderItemsTable() {
    const el = document.getElementById('dv-items-table');
    if (!el) return;
    if (!this._state.items.length) {
      el.innerHTML = '<p style="color:#94a3b8;font-size:12px;text-align:center;padding:12px 0;">Sin ítems. Busque un producto arriba.</p>';
      return;
    }
    el.innerHTML = `
      <table class="erp-table" style="font-size:12px;">
        <thead><tr>
          <th>Producto</th><th>Lote</th><th>Vence</th>
          <th class="text-center">Cant.</th><th>Condición</th><th>Ubicación origen</th><th></th>
        </tr></thead>
        <tbody>
          ${this._state.items.map((it, i) => `<tr>
            <td>${WMS.esc(it.producto_nombre)}</td>
            <td><code>${WMS.esc(it.lote||'-')}</code></td>
            <td style="font-size:11px;">${it.fecha_vencimiento||'-'}</td>
            <td class="text-center fw-700">${WMS.formatNum(it.cantidad)}</td>
            <td>${WMS.esc(it.condicion)}</td>
            <td style="font-size:11px;">
              ${it.ubicacion_origen_cod
                ? `<code style="background:#fffbeb;color:#92400e;">${WMS.esc(it.ubicacion_origen_cod)}</code>`
                : '<span style="color:#94a3b8;">Patio/auto</span>'}
            </td>
            <td>
              <button class="btn btn-danger" style="padding:2px 6px;font-size:10px;"
                onclick="WMS_MODULES.devoluciones._quitarItem(${i})">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
      <p style="font-size:11px;color:#64748b;margin-top:4px;">
        Total: <strong>${this._state.items.length} SKU(s)</strong>  —
        <strong>${WMS.formatNum(this._state.items.reduce((s,x)=>s+x.cantidad,0))}</strong> unidades
      </p>`;
  },

  _quitarItem(i) {
    this._state.items.splice(i, 1);
    this._renderItemsTable();
  },

  async guardarNueva() {
    const tipo       = document.getElementById('dv-new-tipo')?.value;
    const causal_id  = document.getElementById('dv-new-causal')?.value;
    const responsable= document.getElementById('dv-new-responsable')?.value?.trim();
    const ref        = document.getElementById('dv-new-ref')?.value?.trim() || null;
    const motivo     = document.getElementById('dv-new-motivo')?.value?.trim();
    const tercero_id = document.getElementById('dv-new-tercero-id')?.value || null;
    const ubic_id    = document.getElementById('dv-new-ubic-id')?.value || null;

    if (!causal_id)   { WMS.toast('error', 'Seleccione la causal'); return; }
    if (!responsable)  { WMS.toast('error', 'Ingrese el nombre del responsable'); return; }
    if (!motivo)       { WMS.toast('error', 'Ingrese el motivo general'); return; }
    if (!this._state.items.length) { WMS.toast('error', 'Agregue al menos un producto'); return; }

    WMS.spinner();
    try {
      const r = await API.post('/devoluciones', {
        tipo,
        causal_devolucion_id:   parseInt(causal_id),
        responsable_devolucion: responsable,
        referencia_externa: ref,
        motivo_general:     motivo,
        tercero_id:         tercero_id ? parseInt(tercero_id) : null,
        ubicacion_patio_id: ubic_id    ? parseInt(ubic_id)    : null,
        detalles:           this._state.items.map(it => ({
          producto_id:         it.producto_id,
          lote:                it.lote,
          fecha_vencimiento:   it.fecha_vencimiento,
          cantidad:            it.cantidad,
          condicion:           it.condicion,
          motivo:              'Otro',
          ubicacion_origen_id: it.ubicacion_origen_id || null,
        })),
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución ' + (r.data?.numero||'') + ' registrada. Pendiente de aprobación.');
      this.showDetalle(r.data?.devolucion_id || r.data?.id);
    } catch(e) { WMS.toast('error', 'Error al registrar'); }
  },

}; // end WMS_MODULES.devoluciones
