/* ============================================================
   WMS Desktop — Módulo INVENTARIOS
   Sub-vistas: ciclico | general | cargue | dashboard | ajuste | stock | stock-ubi | vencimientos
   ============================================================ */
WMS_MODULES.inventario = {
  load(sub) {
    WMS.setBreadcrumb('inventario', this.subLabel(sub));
    WMS.renderSidebar('inventario');
    const s = sub || 'stock';
    const fn = {
      sesiones: this.show_sesiones,
      ciclico: this.show_ciclico, general: this.show_general, cargue: this.show_cargue,
      dashboard: this.show_dashboard, ajuste: this.show_ajuste, 'ajuste-ubicacion': this.show_ajuste_ubicacion,
      stock: this.show_stock, 'stock-ubi': this.show_stock_ubi, vencimientos: this.show_vencimientos,
    };
    (fn[s]?.bind(this) || fn.stock.bind(this))();
  },

  subLabel(s) {
    const m = {
      sesiones: 'Gestión de Conteos',
      ciclico:'Admin Conteos', general:'Inventario General', cargue:'Cargue Inicial',
      dashboard:'Dashboard Inventario', ajuste:'Ajuste Manual', stock:'Stock General',
      'stock-ubi':'Stock por Ubicación', vencimientos:'Vencimientos',
    };
    return m[s] || s || 'Panel';
  },

  // ── STOCK GENERAL ─────────────────────────────────────────────
  _invStockChart: null,
  _invMovChart: null,
  _stockTab: 'referencia',

  show_stock() {
    WMS.setToolbar('');
    const tab = this._stockTab || 'referencia';
    const tabBtn = (id, label, icon) => {
      const active = tab === id;
      return `<button class="stk-tab-btn" data-tab="${id}" onclick="WMS_MODULES.inventario._stockSetTab('${id}')"
        style="padding:10px 22px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;border-bottom:3px solid ${active ? '#1d4ed8' : 'transparent'};color:${active ? '#1d4ed8' : '#64748b'};transition:all .2s;">
        <i class="fa-solid ${icon}"></i> ${label}
      </button>`;
    };
    WMS.setContent(`
      <div style="display:flex;border-bottom:1px solid #e2e8f0;margin-bottom:16px;">
        ${tabBtn('referencia', 'Por Referencia', 'fa-barcode')}
        ${tabBtn('general', 'Resumen General', 'fa-table-list')}
      </div>
      <div id="stock-tab-body"></div>
    `);
    if (tab === 'referencia') this._renderStockPorReferencia();
    else this._renderStockGeneralTab();
  },

  _stockSetTab(tab) {
    this._stockTab = tab;
    this.show_stock();
  },

  // ── Tab "Por Referencia": filtro dinámico + KPIs + gráfico entradas/salidas + ubicaciones ──
  _renderStockPorReferencia() {
    const body = document.getElementById('stock-tab-body');
    const hoy   = new Date().toISOString().substring(0,10);
    const hace30 = new Date(Date.now() - 30*86400000).toISOString().substring(0,10);
    const prodId  = this._srProdId || '';
    const prodNom = this._srProdNombre || '';
    const desde = this._srDesde || hace30;
    const hasta = this._srHasta || hoy;

    body.innerHTML = `
      <div class="filter-bar" style="flex-wrap:wrap;gap:8px;align-items:flex-end;">
        <div class="form-group" style="margin:0;min-width:260px;">
          <label class="form-label" style="font-size:.7rem;">Referencia <span class="required">*</span></label>
          <input type="text" id="sr-prod-ac" class="form-control" placeholder="Escriba EAN, código o nombre..." autocomplete="off" value="${WMS.esc(prodNom)}">
          <input type="hidden" id="sr-prod-id" value="${prodId}">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Desde</label>
          <input type="date" id="sr-desde" class="form-control form-control-sm" value="${desde}" style="width:150px">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Hasta</label>
          <input type="date" id="sr-hasta" class="form-control form-control-sm" value="${hasta}" style="width:150px">
        </div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._srBuscar()"><i class="fa-solid fa-search"></i> Buscar</button>
        <button class="btn btn-success btn-sm" id="sr-btn-export" onclick="WMS_MODULES.inventario._srExportar()" ${prodId?'':'disabled'}><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
      </div>
      <div id="sr-result" style="margin-top:16px;">
        <div class="m-empty" style="padding:40px;"><i class="fa-solid fa-magnifying-glass"></i><p>Busque una referencia para ver sus movimientos, ubicaciones con stock y tendencia de entradas/salidas</p></div>
      </div>
    `;

    setTimeout(() => {
      const inp = document.getElementById('sr-prod-ac');
      if (inp) {
        WMS.initProductAutocomplete(inp, (p) => {
          document.getElementById('sr-prod-id').value = p.id;
          this._srProdId = p.id;
          this._srProdNombre = p.descripcion || p.nombre;
          const btn = document.getElementById('sr-btn-export');
          if (btn) btn.disabled = false;
          this._srBuscar();
        });
      }
    }, 150);

    if (prodId) this._srBuscar();
  },

  async _srBuscar() {
    const prodId = document.getElementById('sr-prod-id')?.value;
    const desde  = document.getElementById('sr-desde')?.value;
    const hasta  = document.getElementById('sr-hasta')?.value;
    this._srDesde = desde; this._srHasta = hasta;
    const wrap = document.getElementById('sr-result');
    if (!prodId) { WMS.toast('warning', 'Seleccione una referencia para consultar'); return; }
    wrap.innerHTML = '<div class="spinner sm" style="margin:24px auto;display:block;"></div>';
    try {
      const [rKardex, rStock] = await Promise.all([
        API.get('/v2/inventario/kardex', `producto_id=${prodId}&fecha_inicio=${desde}&fecha_fin=${hasta}`),
        API.get('/inventario/stock', `producto_id=${prodId}&limit=1000`),
      ]);
      const kx = rKardex.data || {};
      const movs = kx.movimientos || [];
      const stockItems = rStock.data || rStock || [];

      const totalEntradas = movs.reduce((s,m) => s + (+m.entradas || 0), 0);
      const totalSalidas   = movs.reduce((s,m) => s + (+m.salidas || 0), 0);
      const stockActual    = stockItems.reduce((s,i) => s + (+i.cantidad || 0), 0);
      const numUbicaciones = new Set(stockItems.map(i => i.ubicacion_codigo || i.ubicacion).filter(Boolean)).size;
      const conVencer      = stockItems.filter(i => {
        if (!i.fecha_vencimiento) return false;
        const dias = Math.round((new Date(i.fecha_vencimiento) - Date.now()) / 86400000);
        return dias !== null && dias < 30;
      }).length;

      wrap.innerHTML = `
        <div class="pro-kpi-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px;">
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-arrow-down"></i></div></div><div class="pro-kpi-value">${WMS.formatNum(totalEntradas)}</div><div class="pro-kpi-label">Entradas (período)</div></div>
          <div class="pro-kpi-card accent-red"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-arrow-up"></i></div></div><div class="pro-kpi-value">${WMS.formatNum(totalSalidas)}</div><div class="pro-kpi-label">Salidas (período)</div></div>
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-cubes"></i></div></div><div class="pro-kpi-value">${WMS.formatNum(stockActual)}</div><div class="pro-kpi-label">Stock actual (und)</div></div>
          <div class="pro-kpi-card accent-teal"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-location-dot"></i></div></div><div class="pro-kpi-value">${numUbicaciones}</div><div class="pro-kpi-label">Ubicaciones con stock</div></div>
          <div class="pro-kpi-card ${conVencer>0?'accent-amber':'accent-green'}"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div></div><div class="pro-kpi-value">${conVencer}</div><div class="pro-kpi-label">Lotes próx. a vencer (&lt;30d)</div></div>
        </div>
        <div class="pro-chart-card" style="margin-bottom:20px;">
          <div class="pro-chart-title">
            <span><i class="fa-solid fa-chart-bar" style="color:#0070f2;margin-right:8px"></i>Movimientos: Entradas vs Salidas por día</span>
          </div>
          <div class="pro-chart-container" style="height:260px">
            <canvas id="sr-mov-chart"></canvas>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-warehouse" style="margin-right:6px;color:#0070f2"></i>Ubicaciones con Stock</span></div>
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>Ubicación</th><th>Lote</th><th style="text-align:center">Cajas</th><th style="text-align:center">Sueltos</th><th style="text-align:center">UND/TOTAL</th><th>F.Vencimiento</th><th>Estado</th></tr></thead>
              <tbody>${stockItems.length ? stockItems.map(i => `
                <tr>
                  <td><span class="pro-badge info">${WMS.esc(i.ubicacion_codigo || i.ubicacion || '—')}</span></td>
                  <td class="muted">${WMS.esc(i.lote || '—')}</td>
                  <td style="text-align:center;color:#0070f2">${i.cantidad_cajas ?? '—'}</td>
                  <td style="text-align:center;color:#6d28d9">${i.saldos ?? '—'}</td>
                  <td style="text-align:center;font-weight:700">${WMS.formatNum(i.cantidad)}</td>
                  <td class="muted">${i.fecha_vencimiento ? WMS.formatDate(i.fecha_vencimiento) : 'N/A'}</td>
                  <td><span class="pro-badge ${i.estado==='Disponible'?'ok':'warn'}">${WMS.esc(i.estado || '—')}</span></td>
                </tr>`).join('') : '<tr><td colspan="7" class="muted" style="text-align:center;padding:20px">Sin stock en ninguna ubicación</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>
      `;

      this._renderMovBarChart(movs);
    } catch(e) {
      wrap.innerHTML = '<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando datos de la referencia</p></div>';
      console.error(e);
    }
  },

  _renderMovBarChart(movs) {
    const ctx = document.getElementById('sr-mov-chart');
    if (!ctx) return;
    if (this._invMovChart) { try{this._invMovChart.destroy();}catch(_){} }

    const porDia = {};
    movs.forEach(m => {
      const f = m.fecha;
      if (!porDia[f]) porDia[f] = { entradas:0, salidas:0 };
      porDia[f].entradas += (+m.entradas || 0);
      porDia[f].salidas  += (+m.salidas || 0);
    });
    const fechas = Object.keys(porDia).sort();

    if (!fechas.length) {
      ctx.parentElement.innerHTML = '<div class="m-empty" style="padding:20px"><p>Sin movimientos en el rango seleccionado</p></div>';
      return;
    }

    this._invMovChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: fechas.map(f => WMS.formatDate(f)),
        datasets: [
          { label:'Entradas', data: fechas.map(f=>porDia[f].entradas), backgroundColor:'#22c55e', borderRadius:4 },
          { label:'Salidas',  data: fechas.map(f=>porDia[f].salidas),  backgroundColor:'#ef4444', borderRadius:4 },
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{position:'top'}, tooltip:{
          backgroundColor:'#1a2340', titleColor:'#fff', bodyColor:'rgba(255,255,255,.8)', padding:10, cornerRadius:8
        }},
        scales:{
          x:{ grid:{display:false}, ticks:{font:{size:10},color:'#6b7a99'} },
          y:{ beginAtZero:true, grid:{color:'#f0f2f8'}, ticks:{font:{size:10},color:'#6b7a99'} }
        }
      }
    });
  },

  _srExportar() {
    const prodId = document.getElementById('sr-prod-id')?.value;
    if (!prodId) { WMS.toast('warning', 'Seleccione una referencia'); return; }
    const desde = document.getElementById('sr-desde')?.value || '';
    const hasta = document.getElementById('sr-hasta')?.value || '';
    const token = localStorage.getItem('wms_token') || '';
    WMS.toast('info', 'Generando reporte...');
    const url = `${API_BASE}/v2/inventario/kardex?producto_id=${prodId}&fecha_inicio=${desde}&fecha_fin=${hasta}&export=excel&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── Tab "Resumen General": vista consolidada de todo el stock (bajo demanda) ──
  async _renderStockGeneralTab() {
    const body = document.getElementById('stock-tab-body');
    body.innerHTML = `
      <div class="filter-bar" style="gap:8px;">
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._cargarStockGeneral()"><i class="fa-solid fa-play"></i> Cargar Resumen General</button>
        <button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario.exportarStock()"><i class="fa-solid fa-file-excel"></i> Exportar</button>
      </div>
      <div id="stock-general-body" style="margin-top:16px;">
        <div class="m-empty" style="padding:40px;"><i class="fa-solid fa-table-list"></i><p>Presione "Cargar Resumen General" para ver el consolidado de todas las referencias (puede tardar unos segundos por el volumen de datos)</p></div>
      </div>
    `;
  },

  async _cargarStockGeneral() {
    const body = document.getElementById('stock-general-body');
    body.innerHTML = '<div class="spinner sm" style="margin:24px auto;display:block;"></div>';
    try {
      const r = await API.get('/inventario/stock', 'limit=5000');
      const items = r.data || r || [];

      /* Consolidar por producto */
      const porProducto = {};
      items.forEach(s => {
        const key = s.producto_id || s.ean || s.descripcion || s.codigo_interno;
        if (!porProducto[key]) {
          porProducto[key] = {
            codigo: s.codigo_interno || s.ean,
            descripcion: s.descripcion || s.producto_nombre || s.producto,
            total: 0,
            stock_minimo: parseFloat(s.stock_minimo || 0),
            upc: parseInt(s.unidades_caja || 1),
            prom_venta_mensual: parseFloat(s.promedio_venta_mensual || 0),
            lotes: []
          };
        }
        porProducto[key].total += parseFloat(s.cantidad||0);
        const fv = s.fecha_vencimiento ? new Date(s.fecha_vencimiento) : null;
        const diasFv = fv ? Math.round((fv - Date.now()) / 86400000) : null;
        porProducto[key].lotes.push({
          ubicacion: s.ubicacion_codigo || s.ubicacion || '-',
          cantidad: parseFloat(s.cantidad||0),
          cantidad_cajas: parseInt(s.cantidad_cajas || 0),
          saldos: parseFloat(s.saldos || 0),
          upc: parseInt(s.unidades_caja || 1),
          lote: s.lote || '-',
          fecha_vencimiento: s.fecha_vencimiento || '-',
          dias_vencimiento: diasFv,
          fecha_ingreso: s.created_at ? WMS.formatDate(s.created_at) : '-'
        });
      });

      const consolidado = Object.values(porProducto);

      /* KPIs calculados */
      const totalRefs  = consolidado.length;
      const totalUnits = consolidado.reduce((a,p) => a + p.total, 0);
      const bajoStock  = consolidado.filter(p => p.total > 0 && p.total < (p.stock_minimo > 0 ? p.stock_minimo : 10)).length;
      const sinStock   = consolidado.filter(p => p.total <= 0).length;
      const vencidos   = consolidado.filter(p =>
        p.lotes.some(l => l.dias_vencimiento !== null && l.dias_vencimiento < 0)
      ).length;

      /* Toggle de filas pivot */
      window._togglePivot = function(btn, id, event) {
        if (event) event.stopPropagation();
        const detail = document.getElementById(id);
        const expanded = detail.style.display !== 'none';
        detail.style.display = expanded ? 'none' : 'table-row';
        btn.innerHTML = expanded
          ? '<i class="fa-solid fa-chevron-right"></i>'
          : '<i class="fa-solid fa-chevron-down" style="color:#0070f2"></i>';
      };

      body.innerHTML = `
<div class="pro-dashboard">

  <!-- KPIs -->
  <div class="pro-kpi-grid" style="grid-template-columns:repeat(5,1fr)">
    <div class="pro-kpi-card accent-blue">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <span class="pro-kpi-trend neu">Total</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(totalRefs)}</div>
      <div class="pro-kpi-label">Referencias</div>
    </div>
    <div class="pro-kpi-card accent-teal">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-cubes"></i></div>
        <span class="pro-kpi-trend up">Stock</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(totalUnits)}</div>
      <div class="pro-kpi-label">UND/TOTAL disponible</div>
    </div>
    <div class="pro-kpi-card accent-green">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-circle-check"></i></div>
        <span class="pro-kpi-trend up">OK</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(totalRefs - bajoStock - sinStock)}</div>
      <div class="pro-kpi-label">Disponibles normal</div>
    </div>
    <div class="pro-kpi-card accent-amber">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-arrow-down"></i></div>
        <span class="pro-kpi-trend ${bajoStock>0?'down':'up'}">${bajoStock>0?'Alerta':'OK'}</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(bajoStock)}</div>
      <div class="pro-kpi-label">Bajo stock mínimo</div>
    </div>
    <div class="pro-kpi-card accent-red">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-skull-crossbones"></i></div>
        <span class="pro-kpi-trend ${vencidos>0?'down':'up'}">${vencidos>0?'Alerta':'OK'}</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(vencidos)}</div>
      <div class="pro-kpi-label">Con lotes vencidos</div>
    </div>
  </div>

  <!-- Charts: donut + categorías -->
  <div class="pro-charts-grid" style="grid-template-columns:320px 1fr;margin-bottom:24px">

    <div class="pro-chart-card">
      <div class="pro-chart-title">
        <span><i class="fa-solid fa-chart-pie" style="color:#0891b2;margin-right:8px"></i>Distribución de Stock</span>
      </div>
      <div style="display:flex;align-items:center;gap:20px">
        <div class="pro-donut-wrap" style="position:relative;width:140px;height:140px;flex-shrink:0">
          <canvas id="inv-donut" width="140" height="140"></canvas>
          <div class="pro-donut-center">
            <span class="value" id="inv-pct-ok">–</span>
            <span class="label">OK</span>
          </div>
        </div>
        <div class="pro-legend" id="inv-stock-legend" style="flex:1"></div>
      </div>
    </div>

    <div class="pro-chart-card">
      <div class="pro-chart-title">
        <span><i class="fa-solid fa-chart-bar" style="color:#0070f2;margin-right:8px"></i>Top 10 referencias por cantidad</span>
        <span class="pro-chart-badge">Ranking</span>
      </div>
      <div class="pro-chart-container" style="height:200px">
        <canvas id="inv-top10"></canvas>
      </div>
    </div>
  </div>

  <!-- Tabla expandible -->
  <div class="pro-table-card" id="stock-table-card">
    <div class="pro-table-header" onclick="WMS_MODULES.inventario._toggleStockTable()">
      <div class="pro-table-header-left">
        <span class="pro-table-title"><i class="fa-solid fa-layer-group" style="margin-right:8px;color:#0070f2"></i>Stock General Pivot</span>
        <span class="pro-table-count">${consolidado.length} referencias</span>
      </div>
      <span class="pro-table-toggle"><i class="fa-solid fa-chevron-down"></i></span>
    </div>
    <div class="pro-table-body">
      <div class="pro-table-toolbar">
        <input class="pro-table-search" id="stock-search" placeholder="Buscar producto, código EAN…"
               oninput="WMS_MODULES.inventario._filterStockTable(this.value)">
        <select class="pro-table-filter-select" onchange="WMS_MODULES.inventario._filterStockEstado(this.value)">
          <option value="">Todos</option>
          <option value="normal">Stock normal</option>
          <option value="bajo">Bajo stock</option>
          <option value="sin">Sin stock</option>
          <option value="vencido">Con vencidos</option>
        </select>
      </div>
      <div class="pro-table-wrap" style="max-height:520px;overflow-y:auto">
        <table class="erp-table" id="stock-pro-table">
          <thead><tr>
            <th style="width:36px"></th>
            <th>EAN / Código</th>
            <th>Descripción</th>
            <th style="text-align:center">UND/TOTAL</th>
            <th style="text-align:center">Prom.Venta/mes</th>
            <th style="text-align:center">Días inv.</th>
            <th style="text-align:center">Vida útil prom.</th>
            <th style="text-align:center">Estado</th>
          </tr></thead>
          <tbody id="stock-pro-tbody">
            ${consolidado.map((p, i) => {
              const detId    = 'spd-' + i;
              const promVenta= p.prom_venta_mensual;
              const pctPromo = p.total>0 && promVenta>0 ? ((promVenta/p.total)*100).toFixed(1) : 0;
              const diasInv  = promVenta>0 ? ((p.total/promVenta)*30).toFixed(0) : '∞';
              let sumDias=0, cntDias=0;
              p.lotes.forEach(l => { if (l.dias_vencimiento!==null){sumDias+=l.dias_vencimiento;cntDias++;} });
              const promFv = cntDias>0 ? (sumDias/cntDias).toFixed(0)+' d' : 'N/A';
              const hasVenc= p.lotes.some(l => l.dias_vencimiento!==null && l.dias_vencimiento<0);
              const _minStk = p.stock_minimo > 0 ? p.stock_minimo : 10;
              const stCls  = p.total<=0 ? 'alert' : p.total<_minStk ? 'warn' : 'ok';
              const stLbl  = p.total<=0 ? 'Sin stock' : p.total<_minStk ? 'Bajo stock' : 'Normal';
              const stData = p.total<=0?'sin':p.total<_minStk?'bajo':hasVenc?'vencido':'normal';
              return `
              <tr class="pivot-row" data-stock-estado="${stData}"
                  onclick="window._togglePivot(this.querySelector('.pivot-tog'),'${detId}',event)"
                  style="cursor:pointer">
                <td style="text-align:center">
                  <span class="pivot-tog" style="color:#6b7a99;display:inline-block;transition:transform .2s">
                    <i class="fa-solid fa-chevron-right"></i>
                  </span>
                </td>
                <td style="font-family:monospace;font-size:.78rem;font-weight:700">${WMS.esc(p.codigo||'–')}</td>
                <td><strong>${WMS.esc(p.descripcion||'–')}</strong></td>
                <td style="text-align:center">
                  <span class="${p.total<=0?'stock-alert':p.total<_minStk?'stock-warn':'stock-ok'}" style="font-size:1.05rem"
                        title="UND/TOTAL">${WMS.formatNum(p.total)}</span>
                </td>
                <td style="text-align:center" class="muted">${promVenta>0?WMS.formatNum(promVenta):'–'}</td>
                <td style="text-align:center;font-weight:700;color:#0070f2">${diasInv}</td>
                <td style="text-align:center" class="muted">${promFv}</td>
                <td style="text-align:center"><span class="pro-badge ${stCls}">${stLbl}</span></td>
              </tr>
              <tr id="${detId}" style="display:none">
                <td colspan="8" style="padding:0 24px 16px 48px;background:#f8faff">
                  <div style="border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;margin-top:8px">
                    <table class="erp-table" style="font-size:.77rem">
                      <thead><tr style="background:#f0f4ff">
                        <th>Ubicación</th><th style="text-align:center">UND/TOTAL</th>
                        <th style="text-align:center">Cajas</th><th style="text-align:center">Sueltos</th>
                        <th>Lote</th><th>F.Vencimiento</th>
                        <th style="text-align:center">Días aging</th><th>F.Ingreso</th>
                      </tr></thead>
                      <tbody>
                        ${p.lotes.map(l => {
                          const vBadge = l.dias_vencimiento===null ? 'neutral'
                            : l.dias_vencimiento<0  ? 'alert'
                            : l.dias_vencimiento<30 ? 'warn'
                            : 'ok';
                          const vLbl = l.dias_vencimiento===null ? '–'
                            : l.dias_vencimiento<0 ? 'Vencido'
                            : l.dias_vencimiento+' días';
                          const lUpc = l.upc || 1;
                          const hasCajas = l.cantidad_cajas > 0;
                          const desgloseTitle = hasCajas
                            ? `${l.cantidad_cajas} cajas × ${lUpc} u/e + ${l.saldos} sueltos`
                            : '';
                          return `<tr>
                            <td><span class="pro-badge info">${WMS.esc(l.ubicacion)}</span></td>
                            <td style="text-align:center;font-weight:700">
                              ${hasCajas
                                ? `<span title="${desgloseTitle}" style="cursor:help">${WMS.formatNum(l.cantidad)}</span>
                                   <div style="font-size:10px;color:#64748b">${l.cantidad_cajas}×${lUpc}+${l.saldos}</div>`
                                : WMS.formatNum(l.cantidad)}
                            </td>
                            <td style="text-align:center;color:#0070f2">${hasCajas ? l.cantidad_cajas : '—'}</td>
                            <td style="text-align:center;color:#6d28d9">${hasCajas ? l.saldos : '—'}</td>
                            <td class="muted">${WMS.esc(l.lote)}</td>
                            <td class="muted">${l.fecha_vencimiento!=='-'?WMS.formatDate(l.fecha_vencimiento):'N/A'}</td>
                            <td style="text-align:center"><span class="pro-badge ${vBadge}">${vLbl}</span></td>
                            <td class="muted">${l.fecha_ingreso}</td>
                          </tr>`;
                        }).join('')}
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>`;
            }).join('') || '<tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Sin inventario reportado</td></tr>'  }
          </tbody>
        </table>
      </div>
      <div class="pro-table-footer">
        <span id="stock-count">${consolidado.length} referencias</span>
        <span>UND/TOTAL: ${WMS.formatNum(totalUnits)}</span>
      </div>
    </div>
  </div>

</div>`;

      /* Donut chart */
      this._renderStockDonut(totalRefs-bajoStock-sinStock, bajoStock, sinStock);
      /* Top-10 bar */
      this._renderTop10(consolidado);

    } catch(e) {
      body.innerHTML = '<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>';
      console.error(e);
    }
  },

  _toggleStockTable() {
    document.getElementById('stock-table-card')?.classList.toggle('collapsed');
  },

  _filterStockTable(q) {
    const rows = Array.from(document.querySelectorAll('#stock-pro-tbody tr'));
    let vis = 0;
    const f = q.toLowerCase();
    rows.forEach(tr => {
      if (!tr.dataset.stockEstado) return; // detail rows
      const show = !f || tr.textContent.toLowerCase().includes(f);
      tr.style.display = show ? '' : 'none';
      // also hide corresponding detail row
      const detId = tr.onclick?.toString().match(/'(spd-\d+)'/)?.[1];
      if (detId) document.getElementById(detId)?.style && (document.getElementById(detId).style.display='none');
      if (show) vis++;
    });
    const cnt = document.getElementById('stock-count');
    if (cnt) cnt.textContent = `${vis} referencias`;
  },

  _filterStockEstado(val) {
    const rows = Array.from(document.querySelectorAll('#stock-pro-tbody tr'));
    let vis = 0;
    rows.forEach(tr => {
      if (!tr.dataset.stockEstado) return;
      const show = !val || tr.dataset.stockEstado === val;
      tr.style.display = show ? '' : 'none';
      if (show) vis++;
    });
    const cnt = document.getElementById('stock-count');
    if (cnt) cnt.textContent = `${vis} referencias`;
  },

  _renderStockDonut(ok, bajo, sin) {
    const ctx = document.getElementById('inv-donut');
    if (!ctx) return;
    if (this._invStockChart) { try{this._invStockChart.destroy();}catch(_){} }
    const total = ok + bajo + sin || 1;
    const pctOk = Math.round(ok/total*100);
    const pctEl = document.getElementById('inv-pct-ok');
    if (pctEl) pctEl.textContent = pctOk + '%';

    const legEl = document.getElementById('inv-stock-legend');
    if (legEl) {
      const items = [
        { label:'Normal',     val:ok,   color:'#00b300', pct:Math.round(ok/total*100) },
        { label:'Bajo stock', val:bajo, color:'#e8a000', pct:Math.round(bajo/total*100) },
        { label:'Sin stock',  val:sin,  color:'#e03030', pct:Math.round(sin/total*100) },
      ];
      legEl.innerHTML = items.map(i => `
        <div class="pro-legend-item">
          <span class="pro-legend-dot" style="background:${i.color}"></span>
          <span>${i.label}</span>
          <span class="pro-legend-pct">${i.pct}%</span>
        </div>
        <div class="pro-progress-wrap" style="margin-top:-4px;margin-bottom:4px">
          <div class="pro-progress-bar-bg">
            <div class="pro-progress-bar-fill" style="background:${i.color};width:${i.pct}%"></div>
          </div>
          <span class="pro-progress-label">${WMS.formatNum(i.val)}</span>
        </div>`).join('');
    }

    this._invStockChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['Normal','Bajo stock','Sin stock'],
        datasets: [{ data:[ok,bajo,sin].map(v=>v||0), backgroundColor:['#00b300','#e8a000','#e03030'], borderWidth:0, hoverOffset:6 }]
      },
      options: {
        responsive:false, cutout:'70%',
        plugins: {
          legend:{display:false},
          tooltip:{ backgroundColor:'#1a2340', titleColor:'#fff', bodyColor:'rgba(255,255,255,.8)', padding:10, cornerRadius:8 }
        },
        animation:{ animateRotate:true, duration:800 }
      }
    });
  },

  _renderTop10(consolidado) {
    const ctx = document.getElementById('inv-top10');
    if (!ctx) return;
    const top = [...consolidado].sort((a,b)=>b.total-a.total).slice(0,10);
    const gradient = ctx.getContext('2d').createLinearGradient(0,0,0,200);
    gradient.addColorStop(0,'rgba(0,112,242,.85)');
    gradient.addColorStop(1,'rgba(0,112,242,.3)');
    new Chart(ctx, {
      type:'bar',
      data:{
        labels: top.map(p => (p.descripcion||p.codigo||'?').substring(0,22)),
        datasets:[{ label:'Unidades', data:top.map(p=>p.total), backgroundColor:gradient, borderRadius:6, borderSkipped:false }]
      },
      options:{
        responsive:true, maintainAspectRatio:false, indexAxis:'y',
        plugins:{ legend:{display:false}, tooltip:{
          backgroundColor:'#1a2340', titleColor:'#fff', bodyColor:'rgba(255,255,255,.8)', padding:10, cornerRadius:8,
          callbacks:{ label: c => ` ${WMS.formatNum(c.parsed.x)} unidades` }
        }},
        scales:{
          x:{ beginAtZero:true, grid:{color:'#f0f2f8'}, ticks:{font:{size:10},color:'#6b7a99'}, border:{display:false} },
          y:{ grid:{display:false}, ticks:{font:{size:10},color:'#1a2340'}, border:{display:false} }
        }
      }
    });
  },

  filterTable(q, tableId) {
    const rows = document.querySelectorAll('#' + (tableId||'stock-table') + ' tbody tr');
    const f = q.toLowerCase();
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none'; });
  },

  filterStockEstado(val) {
    // Usa data-stock-estado en lugar de parsear texto DOM (evita problema con separador decimal colombiano)
    const rows = document.querySelectorAll('#stock-table tbody tr, #stock-pro-tbody tr');
    rows.forEach(r => {
      if (!val) { r.style.display = ''; return; }
      const estado = r.dataset.stockEstado;
      if (!estado) return; // filas de detalle
      if (val === 'cero') r.style.display = estado === 'sin' ? '' : 'none';
      else if (val === 'bajo') r.style.display = estado === 'bajo' ? '' : 'none';
      else r.style.display = (estado === 'normal' || estado === 'vencido') ? '' : 'none';
    });
  },

  async exportarStock() {
    WMS.toast('info', 'Generando reporte...');
    try {
      const token = localStorage.getItem('wms_token') || '';
      const url = `${API_BASE}/inventario/stock?export=excel&token=${encodeURIComponent(token)}`;
      window.open(url, '_blank');
    } catch(e) { WMS.toast('error', 'Error exportando'); }
  },

  // ── STOCK POR UBICACIÓN ────────────────────────────────────────
  // Usa /inventario/mapa-detallado → datos reales con SUM(cantidad) desde BD
  // Búsqueda dinámica de ubicación con debounce 350 ms → /param/ubicaciones?codigo=...
  async show_stock_ubi(codigoFiltro = '') {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario.show_stock_ubi()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>`);
    WMS.spinner();

    // Estado de ordenación de la tabla principal
    if (!window._ubiSortState) window._ubiSortState = { col: null, asc: true };

    try {
      // Endpoint que ya hace SUM(cantidad) GROUP BY ubicacion_id en BD
      let qs = 'limit=2000';
      if (codigoFiltro) qs += `&producto_id=`; // se usa solo filtro de búsqueda dinámica client-side
      const resp = await API.get('/inventario/mapa-detallado', qs);
      let lista = resp.data || resp || [];
      if (!Array.isArray(lista)) lista = [];

      // Función que genera las filas a partir de `lista` (se reutiliza al ordenar)
      const renderTabla = (datos) => {
        if (!datos.length) return '<tr><td colspan="5" class="table-empty">Sin stock en ubicaciones</td></tr>';
        return datos.map((u, i) => {
          const detId = 'pivot-ubi-' + i;
          const pctBar = Math.min(100, u.ocupacion_pct || 0);
          const pctColor = pctBar >= 90 ? '#ef4444' : pctBar >= 70 ? '#f97316' : '#10b981';
          return `<tr class="pivot-row" data-ubi="${WMS.esc(u.ubicacion||u.posicion||'')}" data-total="${u.und_total||0}"
                      onclick="window._toggleUbiPivot(this.querySelector('button'),'${detId}',event)">
            <td class="text-center">
              <button class="btn btn-sm btn-outline-secondary" style="padding:2px 8px;border-radius:6px;border-color:#cbd5e1;">
                <i class="fa-solid fa-chevron-down"></i>
              </button>
            </td>
            <td>
              <span class="badge badge-info" style="font-size:.9rem;padding:6px 12px;">${WMS.esc(u.ubicacion||u.posicion||'-')}</span>
              <span style="font-size:.72rem;color:#94a3b8;margin-left:6px">${WMS.esc(u.tipo||'')}</span>
            </td>
            <td class="text-center">
              <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
                <div style="width:60px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                  <div style="width:${pctBar}%;height:100%;background:${pctColor};border-radius:3px"></div>
                </div>
                <span style="font-size:.8rem;color:${pctColor};font-weight:700">${pctBar.toFixed(0)}%</span>
              </div>
            </td>
            <td class="text-center">
              <span style="font-weight:700;font-size:.9rem;color:#0f172a">${WMS.formatNum(u.und_total||0)}</span>
              ${u.proximo_vencimiento ? `<div style="font-size:.7rem;color:#f97316;margin-top:2px">Vence: ${WMS.formatDate(u.proximo_vencimiento)}</div>` : ''}
            </td>
            <td class="text-center">
              <span style="font-size:.8rem;color:#64748b">${u.dias_sin_mov === 'N/A' ? 'N/A' : u.dias_sin_mov + 'd sin mov.'}</span>
            </td>
          </tr>
          <tr class="pivot-detail-row" id="${detId}" style="display:none;" data-ubi-id="${u.id}" data-loaded="0">
            <td colspan="5" style="padding:8px 32px 16px 64px;">
              <div style="font-size:.75rem;color:#64748b;padding:6px 0 10px;">
                <strong>Total UND/TOTAL: ${WMS.formatNum(u.und_total||0)}</strong> &nbsp;|&nbsp;
                Cajas: ${WMS.formatNum(u.total_cajas||0)} &nbsp;|&nbsp;
                Sueltos: ${WMS.formatNum(u.total_sueltos||0)} &nbsp;|&nbsp;
                Capacidad máx.: ${u.capacidad_maxima || 'N/D'}
              </div>
              <div id="ubi-detalle-${detId}"></div>
            </td>
          </tr>`;
        }).join('');
      };

      // Función de ordenación de la tabla
      const sortTabla = (col) => {
        const state = window._ubiSortState;
        state.asc = state.col === col ? !state.asc : true;
        state.col = col;
        const sorted = [...lista].sort((a, b) => {
          let va, vb;
          if (col === 'total') { va = a.und_total||0; vb = b.und_total||0; }
          else { va = (a.ubicacion||'').toLowerCase(); vb = (b.ubicacion||'').toLowerCase(); }
          if (va < vb) return state.asc ? -1 : 1;
          if (va > vb) return state.asc ? 1 : -1;
          return 0;
        });
        const tbody = document.getElementById('stock-ubi-tbody');
        if (tbody) tbody.innerHTML = renderTabla(sorted);
        // Actualizar iconos de cabecera
        document.querySelectorAll('.sort-th').forEach(th => {
          th.querySelector('.sort-icon').textContent = th.dataset.col === col
            ? (state.asc ? ' ↑' : ' ↓') : '';
        });
      };
      window._ubiSort = sortTabla;

      window._toggleUbiPivot = function(btn, id, event) {
        if (event) event.stopPropagation();
        const detail = document.getElementById(id);
        if (detail.style.display === 'none') {
          detail.style.display = 'table-row';
          btn.innerHTML = '<i class="fa-solid fa-chevron-up"></i>';
          if (detail.dataset.loaded !== '1') {
            detail.dataset.loaded = '1';
            WMS_MODULES.inventario._cargarDesgloseUbicacion(detail.dataset.ubiId, 'ubi-detalle-' + id);
          }
        } else {
          detail.style.display = 'none';
          btn.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
        }
      };

      // Debounce para búsqueda dinámica de ubicación (350 ms)
      let _ubiDebounce;
      window._ubiSearchDebounce = function(val) {
        clearTimeout(_ubiDebounce);
        _ubiDebounce = setTimeout(() => {
          const q = val.trim().toLowerCase();
          document.querySelectorAll('#stock-ubi-tbody tr.pivot-row').forEach(tr => {
            const ubi = (tr.dataset.ubi || '').toLowerCase();
            const show = !q || ubi.includes(q);
            tr.style.display = show ? '' : 'none';
            // Ocultar la fila de detalle correspondiente si su padre está oculto
            const next = tr.nextElementSibling;
            if (next && next.classList.contains('pivot-detail-row')) {
              if (!show) next.style.display = 'none';
            }
          });
        }, 350);
      };

      WMS.setContent(`
        <style>
          .pivot-row td { background:#fff; cursor:pointer; transition:background .15s; border-bottom:1px solid #f1f5f9; }
          .pivot-row:hover td { background:#f8fafc; }
          .pivot-detail-row { background:#f8fafc; border-bottom:2px solid #e2e8f0; }
          .sort-th { cursor:pointer; user-select:none; white-space:nowrap; }
          .sort-th:hover { background:#f0f4ff; }
        </style>
        <div class="filter-bar">
          <div class="search-bar">
            <i class="fa-solid fa-search"></i>
            <input id="ubi-search-input" placeholder="Buscar por código de ubicación..."
                   oninput="window._ubiSearchDebounce(this.value)">
          </div>
          <span style="font-size:.78rem;color:#94a3b8;align-self:center">
            ${lista.length} ubicaciones con stock — datos reales desde inventarios
          </span>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-map-location"></i> Stock por Ubicación</span>
            <span style="font-size:.75rem;color:#64748b">Haga clic en el encabezado de columna para ordenar</span>
          </div>
          <div class="table-container">
            <table class="erp-table" id="stock-ubi-table">
              <thead><tr>
                <th style="width:40px;text-align:center;"></th>
                <th class="sort-th" data-col="ubicacion" onclick="window._ubiSort('ubicacion')">
                  Ubicación <span class="sort-icon"></span>
                </th>
                <th style="text-align:center;">Ocupación</th>
                <th class="sort-th text-center" data-col="total" onclick="window._ubiSort('total')" style="text-align:center;">
                  UND/TOTAL <span class="sort-icon"></span>
                </th>
                <th style="text-align:center;">Movimiento</th>
              </tr></thead>
              <tbody id="stock-ubi-tbody">${renderTabla(lista)}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) {
      console.error('show_stock_ubi:', e);
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>');
    }
  },

  // Desglose real por producto/lote al expandir una ubicación (bajo demanda, sin dump previo)
  async _cargarDesgloseUbicacion(ubicacionId, targetId) {
    const target = document.getElementById(targetId);
    if (!target) return;
    target.innerHTML = '<div class="spinner sm" style="margin:10px auto;display:block;"></div>';
    try {
      const r = await API.get('/inventario/stock', `ubicacion_id=${ubicacionId}&limit=500`);
      const items = r.data || r || [];
      if (!items.length) {
        target.innerHTML = '<div class="muted" style="padding:8px 0;font-size:.78rem;">Sin referencias con stock en esta ubicación</div>';
        return;
      }
      target.innerHTML = `
        <table class="erp-table" style="font-size:.78rem;background:#fff;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;">
          <thead><tr style="background:#f0f4ff">
            <th>Referencia</th><th>Lote</th>
            <th style="text-align:center">Cajas</th><th style="text-align:center">Sueltos</th><th style="text-align:center">UND/TOTAL</th>
            <th>F.Vencimiento</th><th>Estado</th><th style="width:100px"></th>
          </tr></thead>
          <tbody>
            ${items.map(i => `
              <tr>
                <td><b>${WMS.esc(i.codigo_interno || i.ean || '—')}</b><div style="font-size:10px;color:#64748b">${WMS.esc(i.descripcion || i.producto_nombre || '')}</div></td>
                <td class="muted">${WMS.esc(i.lote || '—')}</td>
                <td style="text-align:center;color:#0070f2">${i.cantidad_cajas ?? '—'}</td>
                <td style="text-align:center;color:#6d28d9">${i.saldos ?? '—'}</td>
                <td style="text-align:center;font-weight:700">${WMS.formatNum(i.cantidad)}</td>
                <td class="muted">${i.fecha_vencimiento ? WMS.formatDate(i.fecha_vencimiento) : 'N/A'}</td>
                <td><span class="pro-badge ${i.estado==='Disponible'?'ok':'warn'}">${WMS.esc(i.estado || '—')}</span></td>
                <td>
                  <button class="btn btn-xs btn-outline-secondary" style="font-size:10px;padding:3px 8px;"
                    onclick="WMS_MODULES.inventario._verHistorialUbiProducto(${ubicacionId}, ${i.producto_id}, '${WMS.esc((i.descripcion||i.producto_nombre||'').replace(/'/g,"\\'"))}')">
                    <i class="fa-solid fa-clock-rotate-left"></i> Historial
                  </button>
                </td>
              </tr>`).join('')}
          </tbody>
        </table>`;
    } catch(e) {
      target.innerHTML = '<div class="muted" style="padding:8px 0;font-size:.78rem;color:#ef4444;">Error cargando desglose</div>';
      console.error(e);
    }
  },

  // Historial de ingresos/salidas de una referencia puntual en una ubicación puntual (fecha + usuario)
  async _verHistorialUbiProducto(ubicacionId, productoId, nombreProducto) {
    const hoy = new Date().toISOString().substring(0,10);
    const hace90 = new Date(Date.now() - 90*86400000).toISOString().substring(0,10);
    WMS.showModal(`Historial: ${nombreProducto}`, `
      <div class="filter-bar" style="gap:8px;margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Desde</label>
          <input type="date" id="uhp-desde" class="form-control form-control-sm" value="${hace90}" style="width:150px">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Hasta</label>
          <input type="date" id="uhp-hasta" class="form-control form-control-sm" value="${hoy}" style="width:150px">
        </div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._buscarHistorialUbiProducto(${ubicacionId},${productoId})"><i class="fa-solid fa-search"></i> Filtrar</button>
        <button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario._exportarHistorialUbiProducto(${ubicacionId},${productoId})"><i class="fa-solid fa-file-excel"></i> Exportar</button>
      </div>
      <div id="uhp-result"><div class="spinner sm" style="margin:16px auto;display:block;"></div></div>
    `);
    this._buscarHistorialUbiProducto(ubicacionId, productoId);
  },

  async _buscarHistorialUbiProducto(ubicacionId, productoId) {
    const wrap = document.getElementById('uhp-result');
    const desde = document.getElementById('uhp-desde')?.value || '';
    const hasta = document.getElementById('uhp-hasta')?.value || '';
    if (wrap) wrap.innerHTML = '<div class="spinner sm" style="margin:16px auto;display:block;"></div>';
    try {
      const r = await API.get(`/trazabilidad/ubicacion/${ubicacionId}`, `producto_id=${productoId}&f_ini=${desde}&f_fin=${hasta}`);
      const d = r.data || {};
      const movs = d.movimientos || [];
      const tipoBadge = t => {
        const m = { Entrada:'badge-success', AjustePositivo:'badge-success', Devolucion:'badge-success', Reabastecimiento:'badge-success',
                    Salida:'badge-danger', AjusteNegativo:'badge-danger', Picking:'badge-danger', Traslado:'badge-info' };
        return `<span class="badge ${m[t]||'badge-secondary'}">${WMS.esc(t)}</span>`;
      };
      if (!wrap) return;
      wrap.innerHTML = `
        <table class="erp-table" style="font-size:.78rem;">
          <thead><tr><th>Fecha</th><th>Tipo</th><th style="text-align:center">Cajas</th><th style="text-align:center">UND/TOTAL</th><th>Lote</th><th>Origen</th><th>Destino</th><th>Usuario</th></tr></thead>
          <tbody>${movs.length ? movs.map(m => `
            <tr>
              <td>${WMS.formatDate(m.fecha_movimiento)}</td>
              <td>${tipoBadge(m.tipo_movimiento)}</td>
              <td style="text-align:center">${m.cantidad_cajas ?? '—'}</td>
              <td style="text-align:center;font-weight:700">${WMS.formatNum(m.cantidad)}</td>
              <td class="muted">${WMS.esc(m.lote || '—')}</td>
              <td class="muted">${WMS.esc(m.ubicacion_origen || '—')}</td>
              <td class="muted">${WMS.esc(m.ubicacion_destino || '—')}</td>
              <td><small>${WMS.esc(m.responsable || '—')}</small></td>
            </tr>`).join('') : '<tr><td colspan="8" class="muted" style="text-align:center;padding:16px">Sin movimientos en el rango seleccionado</td></tr>'}
          </tbody>
        </table>`;
    } catch(e) {
      if (wrap) wrap.innerHTML = '<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando historial</p></div>';
    }
  },

  _exportarHistorialUbiProducto(ubicacionId, productoId) {
    const desde = document.getElementById('uhp-desde')?.value || '';
    const hasta = document.getElementById('uhp-hasta')?.value || '';
    const token = localStorage.getItem('wms_token') || '';
    WMS.toast('info', 'Generando reporte...');
    const url = `${API_BASE}/trazabilidad/ubicacion/${ubicacionId}?producto_id=${productoId}&f_ini=${desde}&f_fin=${hasta}&export=excel&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── VENCIMIENTOS V2 ──────────────────────────────────────────────────────
  async show_vencimientos() {
    WMS.setToolbar(`
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario.exportVencV2()">
        <i class="fa-solid fa-file-excel"></i> Exportar Excel
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario.show_vencimientos()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>`);
    WMS.spinner();
    try {
      const r = await API.get('/v2/inventario/vencimientos', 'solo_proximos=0');
      const d = r.data || {};
      const items = d.items || [];
      const res   = d.resumen || {};
      const sColor = { VENCIDO:'#ef4444', CRITICO:'#f97316', ALERTA:'#eab308', PROXIMO:'#3b82f6', OK:'#10b981' };
      const sBg    = { VENCIDO:'#fef2f2', CRITICO:'#fff7ed', ALERTA:'#fefce8', PROXIMO:'#eff6ff', OK:'#f0fdf4' };

      WMS.setContent(`
      <style>
        .sem-cards { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
        .sem-card  { border-radius:10px; padding:14px 18px; border-left:5px solid; cursor:pointer; transition:transform .15s; }
        .sem-card:hover { transform:translateY(-2px); }
        .sem-card .sem-num { font-size:1.8rem; font-weight:800; }
        .sem-card .sem-lbl { font-size:.72rem; font-weight:700; text-transform:uppercase; opacity:.7; }
        .venc-row-VENCIDO { background:#fef2f2 !important; }
        .venc-row-CRITICO  { background:#fff7ed !important; }
        .venc-row-ALERTA   { background:#fefce8 !important; }
      </style>

      <div class="sem-cards">
        ${Object.entries(res).map(([k,v]) => `
          <div class="sem-card" style="border-color:${sColor[k]};background:${sBg[k]};"
               onclick="WMS_MODULES.inventario._filterVencV2('${k}')">
            <div class="sem-num" style="color:${sColor[k]}">${v}</div>
            <div class="sem-lbl" style="color:${sColor[k]}">${k}</div>
          </div>`).join('')}
      </div>

      <div class="filter-bar">
        <div class="search-bar"><i class="fa-solid fa-search"></i>
          <input id="venc-q" placeholder="Buscar producto, ubicación, lote..." oninput="WMS_MODULES.inventario._filterVencV2()">
        </div>
        <select id="venc-sem" class="form-control" style="max-width:160px;" onchange="WMS_MODULES.inventario._filterVencV2(this.value)">
          <option value="">Todos</option>
          <option value="VENCIDO">Vencidos</option>
          <option value="CRITICO">Crítico (≤30d)</option>
          <option value="ALERTA">Alerta (≤60d)</option>
          <option value="PROXIMO">Próximo (≤90d)</option>
          <option value="OK">OK (+90d)</option>
        </select>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-calendar-xmark"></i> Control de Vencimientos — ${d.total||0} registros</span>
        </div>
        <div class="table-container">
          <table class="erp-table" id="venc-table">
            <thead>
              <tr>
                <th>Referencia</th><th>Producto</th><th>Marca</th><th>Lote</th>
                <th>Fecha Vencimiento</th><th>Días Restantes</th><th>Estado</th>
                <th class="text-center">Cantidad</th><th>Ubicación</th>
              </tr>
            </thead>
            <tbody>
              ${items.map(v => {
                const color = sColor[v.semaforo] || '#64748b';
                return `<tr class="venc-row-${v.semaforo}" data-sem="${v.semaforo}">
                  <td style="font-family:monospace;font-size:.8rem">${WMS.esc(v.referencia||'-')}</td>
                  <td style="font-weight:700">${WMS.esc(v.producto||'-')}</td>
                  <td>${WMS.esc(v.marca||'-')}</td>
                  <td>${WMS.esc(v.lote||'-')}</td>
                  <td>${WMS.formatDate(v.fecha_vencimiento)||'-'}</td>
                  <td class="text-center">
                    ${v.dias_restantes < 0
                      ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:.72rem;font-weight:800;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;">
                           <i class="fa-solid fa-skull-crossbones" style="font-size:.65rem"></i>
                           VENCIDO ${Math.abs(v.dias_restantes)}d
                         </span>`
                      : `<span style="font-weight:700;color:${color}">${v.dias_restantes}d</span>`
                    }
                  </td>
                  <td>
                    <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;background:${color}20;color:${color}">
                      ${v.semaforo}
                    </span>
                  </td>
                  <td class="text-center">${WMS.formatNum(v.cantidad)}</td>
                  <td><span class="badge badge-light-blue">${WMS.esc(v.ubicacion||'-')}</span></td>
                </tr>`;
              }).join('') || '<tr><td colspan="9" class="table-empty">Sin registros con fecha de vencimiento</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando vencimientos</p></div>'); }
  },

  _filterVencV2(semaforo) {
    const sel = semaforo || document.getElementById('venc-sem')?.value || '';
    if (sel) document.getElementById('venc-sem').value = sel;
    const q = (document.getElementById('venc-q')?.value || '').toLowerCase();
    document.querySelectorAll('#venc-table tbody tr').forEach(row => {
      const matchSem = !sel || row.dataset.sem === sel;
      const matchQ   = !q || row.textContent.toLowerCase().includes(q);
      row.style.display = (matchSem && matchQ) ? '' : 'none';
    });
  },

  async exportVencV2() {
    const token = localStorage.getItem('wms_token') || '';
    window.open(`${API_BASE}/v2/inventario/vencimientos?export=excel&token=${encodeURIComponent(token)}`, '_blank');
  },

  async exportConteoV2(id, ronda) {
    const token = localStorage.getItem('wms_token') || '';
    const rondaParam = ronda ? `&ronda=${ronda}` : '';
    window.open(`${API_BASE}/v2/inventario/sesiones/${id}/dashboard?export=excel${rondaParam}&token=${encodeURIComponent(token)}`, '_blank');
  },

  // ── INVENTARIO CÍCLICO / GENERAL ─────────────────────────────
  async show_ciclico() { this.show_sesiones(); },

  async nuevoConteo(tipo = 'Ciclico') {
    WMS.spinner();
    try {
      const resp = await API.get('/param/personal', 'limit=200');
      const auxiliares = resp.data || [];
      const esGeneral  = tipo === 'General';

      WMS.showModal(`Nueva Sesión de Inventario — ${tipo}`, `
        <div class="form-grid form-grid-2">
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Nombre de la sesión <span class="required">*</span></label>
            <input id="cnt-nombre" class="form-control" placeholder="Ej: Conteo Cíclico Pasillo A — Abril 2026">
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label class="form-label">Descripción / Instrucciones generales</label>
            <input id="cnt-desc" class="form-control" placeholder="Observaciones para los auxiliares...">
          </div>
          ${esGeneral ? `
          <div class="form-group">
            <label class="form-label">Tipo / Número de conteos <span class="required">*</span></label>
            <select id="cnt-num-conteos" class="form-control" onchange="WMS_MODULES.inventario._toggleComparar(this.value)">
              <option value="CargueInicial">Cargue Inicial (saldos de apertura)</option>
              <option value="1">1 conteo (igual que cíclico)</option>
              <option value="2" selected>2 conteos (conteo doble ciego)</option>
              <option value="3">3 conteos (con tercer conteo auditor)</option>
            </select>
          </div>
          <div class="form-group" id="cnt-comparar-group">
            <label class="form-label">Comparación</label>
            <select id="cnt-comparar" class="form-control">
              <option value="1">Comparar contra sistema (inventario vs sistema)</option>
              <option value="0">Solo comparar entre conteos (sin sistema)</option>
            </select>
          </div>
          <div id="cnt-cargue-note" style="display:none;grid-column:1/-1;padding:12px 16px;background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;font-size:.85rem;color:#92400e;">
            <i class="fa-solid fa-box-archive" style="margin-right:6px;"></i>
            <strong>Cargue Inicial:</strong> La fecha de vencimiento es siempre obligatoria en este modo.
          </div>` : ''}
          <div class="form-group" id="cnt-fv-config-group" style="grid-column:1/-1;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <div>
                <div style="font-weight:700;font-size:.85rem;color:#15803d;"><i class="fa-solid fa-calendar-check"></i> Fecha de vencimiento obligatoria</div>
                <div style="font-size:.75rem;color:#64748b;margin-top:2px;">
                  <span id="cnt-fv-mode-desc">Los auxiliares DEBEN digitar la fecha de vencimiento para cada producto que la controle.</span>
                </div>
              </div>
              <label class="wms-switch" style="flex-shrink:0;margin-left:12px;">
                <input type="checkbox" id="cnt-fv-obligatorio" checked onchange="WMS_MODULES.inventario._toggleFvMode(this.checked)">
                <span class="slider"></span>
              </label>
            </div>
          </div>
        </div>

        <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:4px;border:1px solid #e2e8f0;">
          <p style="font-weight:700;font-size:.85rem;color:#1e293b;margin-bottom:8px;">
            <i class="fa-solid fa-user-plus" style="color:#1a56db"></i>
            Asignaciones de conteo (puede agregar más después de iniciar)
          </p>
          <div id="asig-list" style="display:flex;flex-direction:column;gap:10px;max-height:260px;overflow-y:auto;">
            <!-- Se agrega dinámicamente -->
          </div>
          <button class="btn btn-sm btn-outline-primary mt-8" onclick="WMS_MODULES.inventario._addAsigRow(${JSON.stringify(auxiliares).replace(/"/g,'&quot;')})">
            <i class="fa-solid fa-plus"></i> Agregar auxiliar
          </button>
        </div>`,

        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.inventario.saveConteoV2('${tipo}')">
           <i class="fa-solid fa-save"></i> Crear Sesión
         </button>`);

      // Agregar primera fila de asignación automáticamente
      this._addAsigRow(auxiliares);
    } catch(e) { WMS.toast('error', 'Error cargando auxiliares'); }
  },

  _toggleComparar(num) {
    const g    = document.getElementById('cnt-comparar-group');
    const note = document.getElementById('cnt-cargue-note');
    const fvCfg = document.getElementById('cnt-fv-config-group');
    if (num === 'CargueInicial') {
      if (g)     g.style.display     = 'none';
      if (note)  note.style.display  = '';
      if (fvCfg) fvCfg.style.display = 'none'; // CargueInicial siempre obliga
    } else {
      if (g)     g.style.display     = parseInt(num) >= 2 ? '' : 'none';
      if (note)  note.style.display  = 'none';
      if (fvCfg) fvCfg.style.display = '';
    }
  },

  _toggleFvMode(checked) {
    const desc = document.getElementById('cnt-fv-mode-desc');
    if (!desc) return;
    desc.textContent = checked
      ? 'Los auxiliares DEBEN digitar la fecha de vencimiento para cada producto que la controle.'
      : 'Se mostrarán las últimas 3 fechas manejadas para que el auxiliar seleccione una (sin obligar entrada manual).';
  },

  _addAsigRow(auxiliares) {
    const cont = document.getElementById('asig-list');
    if (!cont) return;
    const idx = cont.children.length;
    const div = document.createElement('div');
    div.className = 'asig-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end;background:#fff;padding:8px;border-radius:6px;border:1px solid #e2e8f0';
    div.innerHTML = `
      <div>
        <label style="font-size:.75rem;font-weight:600;color:#64748b;">Auxiliar</label>
        <select class="form-control asig-aux" style="font-size:.8rem">
          <option value="">Seleccionar...</option>
          ${auxiliares.map(a => `<option value="${a.id}">${WMS.esc(a.nombre)}</option>`).join('')}
        </select>
      </div>
      <div>
        <label style="font-size:.75rem;font-weight:600;color:#64748b;">Tipo instrucción</label>
        <select class="form-control asig-tipo" style="font-size:.8rem" onchange="WMS_MODULES.inventario._toggleAsigDetalle(this)">
          <option value="Libre">Libre (sin restricción)</option>
          <option value="Pasillo">Por Pasillo</option>
          <option value="Modulo">Por Módulo</option>
          <option value="Referencia">Por Referencia</option>
        </select>
      </div>
      <div>
        <label style="font-size:.75rem;font-weight:600;color:#64748b;">Detalle / Referencia</label>
        <input class="form-control asig-detalle" placeholder="Pasillo A, Módulo 3..." style="font-size:.8rem">
        <input class="asig-prod-id" type="hidden">
      </div>
      <div style="padding-bottom:2px">
        <button class="btn btn-sm btn-outline-danger" onclick="this.closest('.asig-row').remove()">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>`;
    cont.appendChild(div);
  },

  _toggleAsigDetalle(sel) {
    const row       = sel.closest('.asig-row');
    const inp       = row.querySelector('.asig-detalle');
    const prodIdInp = row.querySelector('.asig-prod-id');
    const tipo      = sel.value;
    inp.placeholder = tipo === 'Pasillo'    ? 'Ej: Pasillo A' :
                      tipo === 'Modulo'     ? 'Ej: Módulo 3' :
                      tipo === 'Referencia' ? 'Buscar producto por nombre o código...' :
                                             'Notas adicionales (opcional)';
    inp.value = '';
    if (prodIdInp) prodIdInp.value = '';
    if (tipo === 'Referencia') {
      setTimeout(() => {
        WMS.initProductAutocomplete(inp, (p) => {
          inp.value = `[${p.codigo_interno}] ${p.descripcion}`;
          if (prodIdInp) prodIdInp.value = p.id;
        });
      }, 50);
    }
  },

  async saveConteoV2(tipo) {
    const numConteosVal = document.getElementById('cnt-num-conteos')?.value || '1';

    // Si el selector dice CargueInicial, sobreescribir tipo
    const tipoFinal = (tipo === 'General' && numConteosVal === 'CargueInicial') ? 'CargueInicial' : tipo;
    const numConteosFinal = tipoFinal === 'CargueInicial' ? 1 : parseInt(numConteosVal) || 1;

    const nombre = document.getElementById('cnt-nombre')?.value.trim();
    if (!nombre) return WMS.toast('warning', 'Ingrese un nombre para la sesión');

    const asigRows = document.querySelectorAll('.asig-row');
    const asignaciones = [];
    let asigError = false;
    for (const row of asigRows) {
      const auxId = row.querySelector('.asig-aux')?.value;
      if (!auxId) continue;
      const tipoInstruccion = row.querySelector('.asig-tipo')?.value || 'Libre';
      const detalle  = row.querySelector('.asig-detalle')?.value.trim() || '';
      const prodId   = row.querySelector('.asig-prod-id')?.value || '';
      if (tipoInstruccion === 'Referencia' && !prodId) {
        WMS.toast('warning', 'Seleccione un producto válido para la asignación de Referencia');
        asigError = true; break;
      }
      asignaciones.push({
        auxiliar_id:       parseInt(auxId),
        tipo_instruccion:  tipoInstruccion,
        pasillo:           tipoInstruccion === 'Pasillo'    ? detalle          : null,
        modulo:            tipoInstruccion === 'Modulo'     ? detalle          : null,
        instruccion_libre: tipoInstruccion === 'Libre'      ? detalle          : null,
        producto_id:       tipoInstruccion === 'Referencia' ? parseInt(prodId) : null,
        ronda: 1,
      });
    }
    if (asigError) return;
    if (asignaciones.length === 0) return WMS.toast('warning', 'Agregue al menos un auxiliar con instrucción');

    const compararSistema = document.getElementById('cnt-comparar')?.value !== '0';

    try {
      const fvObligatorioEl = document.getElementById('cnt-fv-obligatorio');
      const fvObligatorio = tipoFinal === 'CargueInicial' ? true : (fvObligatorioEl ? fvObligatorioEl.checked : true);
      const sesion = await API.post('/v2/inventario/sesiones', {
        nombre,
        descripcion: document.getElementById('cnt-desc')?.value.trim() || null,
        tipo: tipoFinal,
        num_conteos: numConteosFinal,
        comparar_sistema: compararSistema,
        fv_obligatorio: fvObligatorio,
      });
      const sesionId = sesion.data?.id;
      if (!sesionId) throw new Error('No se obtuvo ID de sesión');

      for (const a of asignaciones) {
        await API.post(`/v2/inventario/sesiones/${sesionId}/asignaciones`, a);
      }

      WMS.toast('success', 'Sesión creada. Presione "Iniciar" para notificar a los auxiliares.');
      WMS.closeModal('generic-modal');
      this.show_sesiones();
    } catch(e) { WMS.toast('error', e.message || 'Error creando sesión'); }
  },

  async iniciarSesion(id) {
    if (!confirm('¿Iniciar la sesión? Se notificará a todos los auxiliares asignados.')) return;
    try {
      await API.put(`/v2/inventario/sesiones/${id}/iniciar`, {});
      WMS.toast('success', 'Sesión iniciada — auxiliares notificados');
      this.show_sesiones();
    } catch(e) { WMS.toast('error', e.message); }
  },

  // Compatibilidad legacy
  async finalizarRonda(id, n) {
    if (!confirm(`¿Finalizar la Ronda ${n}?`)) return;
    try {
      await API.post(`/inventario/conteo/${id}/finalizar-ronda`, {});
      WMS.toast('success', 'Ronda finalizada');
    } catch(e) { WMS.toast('error', e.message); }
  },

  // ── DASHBOARD V2 ────────────────────────────────────────────────────────
  async verDashboardV2(id, ronda) {
    const isStandalone = document.body.classList.contains('standalone-mode');
    const rondaParam = ronda ? `ronda=${ronda}` : '';
    try {
      const r = await API.get(`/v2/inventario/sesiones/${id}/dashboard`, rondaParam);
      const d = r.data || {};
      this._dashV2 = d;
      this._dashV2Id = id;

      const { sesion, kpis, consistencia_rondas, necesita_tercer_conteo, ubicaciones_en_cero } = d;
      if (!sesion || !kpis) throw new Error('Respuesta incompleta del servidor');
      const stColor = { Borrador:'#64748b', EnCurso:'#f59e0b', PendienteAjuste:'#ef4444', Ajustado:'#10b981', Cerrado:'#64748b' };

      const iraVal = kpis.total_lineas > 0
        ? (100 - (kpis.lineas_con_diferencia / kpis.total_lineas * 100)).toFixed(1)
        : 100;

      const htmlContent = `
        <style>
          .inv2-wrapper  { width: 100%; padding: 20px; box-sizing: border-box; background: #fff; display: flex; flex-direction: column; gap: 20px; }
          
          /* KPIs compactos */
          .inv2-kpi-row  { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; width: 100%; }
          .inv2-kpi      { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; display: flex; gap: 12px; align-items: center; }
          .inv2-kpi-ic   { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
          .inv2-kpi-v    { font-size: 1.6rem; font-weight: 900; line-height: 1; color: #1e293b; }
          .inv2-kpi-lbl  { font-size: .68rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
          .inv2-kpi-sub  { font-size: .65rem; color: #94a3b8; margin-top: 2px; }

          /* Cabecera sesión mejorada */
          .inv2-header-box { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
          .inv2-title-area { display: flex; flex-direction: column; gap: 4px; }
          .inv2-session-name { font-size: 1.3rem; font-weight: 900; color: #0f172a; }
          .inv2-badges     { display: flex; gap: 6px; align-items: center; }
          .inv2-btn-group  { display: flex; gap: 8px; }

          /* Barra de herramientas unificada */
          .inv2-toolbar    { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 10px; padding: 8px 16px; border: 1px solid #e2e8f0; }
          .inv2-rounds-box { display: flex; gap: 8px; align-items: center; border-right: 2px solid #e2e8f0; padding-right: 16px; margin-right: 16px; }
          
          .inv2-tabs       { display: flex !important; gap: 4px; flex: 1; }
          .inv2-tab        { padding: 8px 16px; font-size: .85rem; font-weight: 700; color: #64748b; border: none; background: none; cursor: pointer; border-radius: 6px; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
          .inv2-tab i      { font-size: .9rem; opacity: .7; }
          .inv2-tab.on     { background: #1e293b; color: #fff; }
          .inv2-tab.on i   { opacity: 1; }
          .inv2-tab:hover:not(.on) { background: #f1f5f9; color: #1e293b; }

          .inv2-content    { width: 100%; min-height: 500px; }
          
          /* Tablas y alertas */
          .inv-mat-scroll  { width: 100%; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
          .inv2-alert      { padding: 12px 16px; border-radius: 10px; border-left: 5px solid #ef4444; background: #fef2f2; color: #991b1b; display: flex; align-items: center; gap: 12px; font-size: .88rem; }
          
          #standalone-side-panel { z-index: 100 !important; }
        </style>

        <div class="inv2-wrapper">
          <!-- Nivel 1: KPIs -->
          <div class="inv2-kpi-row">
            <div class="inv2-kpi">
              <div class="inv2-kpi-ic" style="background:${iraVal>=98?'#dcfce7':'#fee2e2'}">
                <i class="fa-solid fa-bullseye" style="color:${iraVal>=98?'#15803d':'#b91c1c'}"></i>
              </div>
              <div>
                <div class="inv2-kpi-v" style="color:${iraVal>=98?'#15803d':'#b91c1c'}">${iraVal}%</div>
                <div class="inv2-kpi-lbl">Exactitud (IRA)</div>
              </div>
            </div>
            <div class="inv2-kpi">
              <div class="inv2-kpi-ic" style="background:#fee2e2">
                <i class="fa-solid fa-arrow-trend-down" style="color:#b91c1c"></i>
              </div>
              <div>
                <div class="inv2-kpi-v" style="color:#b91c1c">${Math.abs(kpis.faltantes_unidades)}</div>
                <div class="inv2-kpi-lbl">Faltantes (uds)</div>
              </div>
            </div>
            <div class="inv2-kpi">
              <div class="inv2-kpi-ic" style="background:#dcfce7">
                <i class="fa-solid fa-arrow-trend-up" style="color:#15803d"></i>
              </div>
              <div>
                <div class="inv2-kpi-v" style="color:#15803d">${kpis.sobrantes_unidades}</div>
                <div class="inv2-kpi-lbl">Sobrantes (uds)</div>
              </div>
            </div>
            <div class="inv2-kpi">
              <div class="inv2-kpi-ic" style="background:#dbeafe">
                <i class="fa-solid fa-chart-line" style="color:#1d4ed8"></i>
              </div>
              <div>
                <div class="inv2-kpi-v" style="color:#1d4ed8">${kpis.pct_avance}%</div>
                <div class="inv2-kpi-lbl">Avance R${ronda||1}</div>
              </div>
            </div>
            <div class="inv2-kpi" style="${(kpis.ubicaciones_vaciadas||0)>0?'background:#fef3c7;border-color:#fbbf24':''}">
              <div class="inv2-kpi-ic" style="background:${(kpis.ubicaciones_vaciadas||0)>0?'#fde68a':'#f1f5f9'}">
                <i class="fa-solid fa-triangle-exclamation" style="color:${(kpis.ubicaciones_vaciadas||0)>0?'#d97706':'#94a3b8'}"></i>
              </div>
              <div>
                <div class="inv2-kpi-v" style="color:${(kpis.ubicaciones_vaciadas||0)>0?'#d97706':'#64748b'}">${kpis.ubicaciones_vaciadas||0}</div>
                <div class="inv2-kpi-lbl">Ubic. Vaciadas</div>
              </div>
            </div>
          </div>

          <!-- Nivel 2: Cabecera operativa -->
          <div class="inv2-header-box">
             <div class="inv2-title-area">
                <div class="inv2-session-name">${WMS.esc(sesion.nombre)}</div>
                <div class="inv2-badges">
                   <span style="padding:2px 10px; border-radius:20px; font-size:.65rem; font-weight:800; background:${stColor[sesion.estado]}20; color:${stColor[sesion.estado]}">${sesion.estado.toUpperCase()}</span>
                   <span class="badge badge-info" style="font-size:.65rem">${sesion.tipo}</span>
                   ${necesita_tercer_conteo ? '<span class="badge badge-danger" style="font-size:.65rem"><i class="fa-solid fa-exclamation-triangle"></i> Auditoría Pendiente</span>' : ''}
                </div>
             </div>
             <div class="inv2-btn-group">
                ${sesion.tipo === 'Ciclico' && !['Ajustado', 'Cerrado'].includes(sesion.estado) ? `
                <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.inventario._ciclicoRefs(${id})">
                  <i class="fa-solid fa-tags"></i> REFERENCIAS
                </button>` : ''}
                <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._showConteoManualModal(${id})">
                  <i class="fa-solid fa-plus-circle"></i> NUEVO CONTEO
                </button>
                <button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario.exportConteoV2(${id}, ${ronda || 1})">
                  <i class="fa-solid fa-file-excel"></i> EXPORTAR EXCEL
                </button>
                ${['Ajustado', 'EnCurso', 'PendienteAjuste'].includes(sesion.estado) ? `
                <button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario._cerrarSesion(${id})">
                  <i class="fa-solid fa-lock-check"></i> FINALIZAR
                </button>` : ''}
             </div>
          </div>

          <!-- Alertas Críticas -->
          ${consistencia_rondas && !consistencia_rondas.ok ? `
          <div class="inv2-alert">
             <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
             <div>
                <b>Diferencias entre Rondas:</b> Se detectaron ${consistencia_rondas.diferencias.length} inconsistencias.
                ${sesion.num_conteos===3 ? 'Se requiere tercer conteo de validación.' : ''}
             </div>
          </div>` : ''}

          <!-- Panel: Ubicaciones Vaciadas por Auxiliares -->
          ${(ubicaciones_en_cero && ubicaciones_en_cero.length > 0) ? `
          <div style="background:#fef3c7;border:1px solid #d97706;border-left:5px solid #d97706;border-radius:10px;padding:16px 20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
              <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:1.2rem"></i>
              <div>
                <span style="font-size:.9rem;font-weight:800;color:#92400e">Ubicaciones Vaciadas por Auxiliares</span>
                <span style="margin-left:10px;background:#d97706;color:#fff;font-size:.7rem;font-weight:800;padding:2px 10px;border-radius:12px">${ubicaciones_en_cero.length} ubicación${ubicaciones_en_cero.length!==1?'es':''}</span>
              </div>
              <span style="margin-left:auto;font-size:.75rem;color:#92400e;font-style:italic">El auxiliar contó 0 unidades donde el sistema reporta stock. Revise antes de aprobar el ajuste.</span>
            </div>
            <div style="overflow-x:auto;border-radius:6px;border:1px solid #fbbf24">
              <table class="erp-table" style="background:#fff;margin:0">
                <thead>
                  <tr style="background:#fef9c3">
                    <th style="font-size:.72rem;color:#92400e">AUXILIAR</th>
                    <th style="font-size:.72rem;color:#92400e">UBICACIÓN</th>
                    <th style="font-size:.72rem;color:#92400e">PRODUCTO</th>
                    <th style="font-size:.72rem;color:#92400e;text-align:center">STOCK SISTEMA</th>
                    <th style="font-size:.72rem;color:#92400e">HORA CONTEO</th>
                    <th style="font-size:.72rem;color:#92400e;text-align:center">ESTADO AJUSTE</th>
                  </tr>
                </thead>
                <tbody>
                  ${ubicaciones_en_cero.map(u => `
                  <tr style="background:#fffbeb">
                    <td style="font-weight:700;font-size:.8rem">${WMS.esc(u.auxiliar||'-')}</td>
                    <td><span class="badge" style="background:#fde68a;color:#92400e;font-weight:800">${WMS.esc(u.ubicacion||'-')}</span></td>
                    <td>
                      <div style="font-weight:700;font-size:.78rem;color:#1e293b">${WMS.esc(u.codigo||'-')}</div>
                      <div style="font-size:.7rem;color:#64748b">${WMS.esc(u.producto||'-')}</div>
                      ${u.lote ? `<div style="font-size:.68rem;color:#94a3b8">Lote: ${WMS.esc(u.lote)}</div>` : ''}
                    </td>
                    <td style="text-align:center;font-weight:800;font-size:.9rem;color:#b91c1c">${u.stock_sistema}</td>
                    <td style="font-size:.75rem;color:#64748b">${u.hora_conteo ? u.hora_conteo.substring(0,16).replace('T',' ') : '-'}</td>
                    <td style="text-align:center">
                      ${u.ajustado
                        ? `<span class="badge" style="background:#dcfce7;color:#15803d;font-weight:800;font-size:.72rem">Ajustado</span>`
                        : `<span class="badge" style="background:#fee2e2;color:#b91c1c;font-weight:800;font-size:.72rem">Pendiente Ajuste</span>`
                      }
                    </td>
                  </tr>`).join('')}
                </tbody>
              </table>
            </div>
          </div>` : ''}

          <!-- Nivel 3: Barra de Herramientas Unificada -->
          <div class="inv2-toolbar">
             ${sesion.num_conteos > 1 ? `
             <div class="inv2-rounds-box">
                <small style="font-weight:800; color:#64748b; text-transform:uppercase; font-size:.65rem">Ronda:</small>
                ${Array.from({length:sesion.num_conteos},(_,i)=>`
                  <button class="btn btn-xs ${(ronda||1)===(i+1)?'btn-primary':'btn-outline-secondary'}"
                          onclick="WMS_MODULES.inventario.verDashboardV2(${id},${i+1})" 
                          style="min-width:30px; font-weight:800">R${i+1}</button>`).join('')}
             </div>` : ''}

             <div class="inv2-tabs">
                <button class="inv2-tab on" id="t2-mat" onclick="WMS_MODULES.inventario._tab2('mat')">
                  <i class="fa-solid fa-table-cells"></i> Matriz
                </button>
                <button class="inv2-tab" id="t2-general" onclick="WMS_MODULES.inventario._tab2('general')">
                  <i class="fa-solid fa-layer-group"></i> Resumen
                </button>
                <button class="inv2-tab" id="t2-dif" onclick="WMS_MODULES.inventario._tab2('dif')">
                  <i class="fa-solid fa-not-equal"></i> Diferencias
                </button>
                <button class="inv2-tab" id="t2-ml" onclick="WMS_MODULES.inventario._tab2('ml')">
                  <i class="fa-solid fa-robot"></i> Análisis ML
                </button>
                <button class="inv2-tab" id="t2-asig" onclick="WMS_MODULES.inventario._tab2('asig')">
                  <i class="fa-solid fa-users"></i> Asig.
                </button>
                <button class="inv2-tab" id="t2-acc" onclick="WMS_MODULES.inventario._tab2('acc')">
                  <i class="fa-solid fa-shield-halved"></i> Seguridad
                </button>
                <button class="inv2-tab" style="color:#f59e0b" onclick="WMS_MODULES.inventario._resetServerCache()">
                  <i class="fa-solid fa-cloud-arrow-down"></i> Sincronizar Servidor
                </button>
             </div>
          </div>

          <!-- Nivel 4: Contenedor Dinámico -->
          <div id="inv2-content" class="inv2-content"></div>
        </div>
      `;

      const footerContent = `
        <button class="btn btn-outline-success btn-sm" onclick="WMS_MODULES.inventario._imprimirReporte(${id})">
          <i class="fa-solid fa-print"></i> Imprimir Informe
        </button>
        ${isStandalone ? '' : `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`}
      `;

      const title = 'Dashboard Inventario - ' + (sesion?.nombre || 'Sesion #' + id);

      if (isStandalone) {
        WMS.setToolbar(footerContent);
        // En modo standalone, el título va en el breadcrumb o encabezado inyectado
        WMS.setBreadcrumb('inventario', 'DASHBOARD V2 ' + (sesion?.nombre || 'Sesion #' + id));
        WMS.setContent(htmlContent);
      } else {
        WMS.showModal(title, htmlContent, footerContent, 'full');
      }

      this._tab2('mat');
    } catch(e) { WMS.toast('error', 'Error cargando dashboard: ' + e.message); }
  },

  _openDashboardInTab(id, ronda = 1) {
    const url = `index.html?view=inv-dash-v2&id=${id}&standalone=1`;
    window.open(url, '_blank');
  },

  _formatFullDate(iso) {
    if (!iso) return '-';
    try {
      const d = new Date(iso);
      if (isNaN(d.getTime())) return iso;
      const day = d.getDate().toString().padStart(2, '0');
      const month = (d.getMonth() + 1).toString().padStart(2, '0');
      const year = d.getFullYear();
      let hours = d.getHours();
      const mins = d.getMinutes().toString().padStart(2, '0');
      const ampm = hours >= 12 ? 'pm' : 'am';
      hours = hours % 12;
      hours = hours ? hours : 12; 
      return `${day}/${month}/${year} ${hours}:${mins} ${ampm}`;
    } catch(e) { return iso; }
  },
  _tab2(tab) {
    document.querySelectorAll('.inv2-tab').forEach(b => b.classList.remove('on'));
    const btn = document.getElementById('t2-' + tab);
    if (btn) btn.classList.add('on');
    const d = this._dashV2;
    const id = this._dashV2Id;
    const content = document.getElementById('inv2-content');
    if (!content || !d) return;

    if (tab === 'mat') {
      const lineas = d.matriz_conteo || [];
      content.innerHTML = `
        <div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
          <div class="search-bar" style="flex:1;background:#fff;border:1px solid #e2e8f0;padding:4px 10px;border-radius:4px;"><i class="fa-solid fa-search"></i>
            <input placeholder="Filtrar por producto, ubicación, SKU..." oninput="WMS_MODULES.inventario._filterMat(this.value,'inv2-mat-table')" style="border:none;outline:none;margin-left:8px;font-size:.85rem;width:90%">
          </div>
          <div style="display:flex;gap:4px;align-items:center;">
             <small style="font-weight:700;color:#64748b;margin-right:8px;">VER:</small>
             <button class="btn btn-xs btn-outline-secondary" onclick="WMS_MODULES.inventario._filterMatDiff('all')">Todo</button>
             <button class="btn btn-xs btn-outline-danger" onclick="WMS_MODULES.inventario._filterMatDiff('diff')">Diferencias</button>
             <button class="btn btn-xs btn-outline-success" onclick="WMS_MODULES.inventario._filterMatDiff('ok')">Sin dif.</button>
          </div>
        </div>
        <div class="inv-mat-scroll">
          <table class="erp-table" id="inv2-mat-table">
            <thead>
              <tr style="background:#f1f5f9">
                <th>AUXILIAR</th>
                <th>FECHA / HORA</th>
                <th>PRODUCTO / REFERENCIA</th>
                <th>UBICACIÓN</th>
                <th>LOTE / VENC.</th>
                <th class="text-center">DIAS V.U.</th>
                <th class="text-center">CAJAS</th>
                <th class="text-center">U/E</th>
                <th class="text-center">SALDOS</th>
                <th class="text-center">UND/TOTAL</th>
                <th class="text-center">SISTEMA</th>
                <th class="text-center">DIFERENCIA</th>
                ${d.sesion.num_conteos > 1 ? `
                  <th class="text-center">R1</th>
                  <th class="text-center">R2</th>
                ` : ''}
                <th>ACCIONES</th>
              </tr>
            </thead>
            <tbody>
              ${lineas.map(l => {
                const color_vu = l.dias_vida_util <= 15 ? '#ef4444' : l.dias_vida_util <= 30 ? '#f59e0b' : '#10b981';
                const has_diff = (l.diferencia !== 0);
                const dif = parseFloat(l.diferencia || 0);
                const difColor = dif === 0 ? '#10b981' : (dif > 0 ? '#0284c7' : '#ef4444');
                const difTxt   = dif === 0 ? '0' : (dif > 0 ? '+' + dif : dif);
                return `
                  <tr data-diff="${has_diff?'1':'0'}" id="linea-row-${l.id}">
                    <td><small>${WMS.esc(l.auxiliar||'-')}</small></td>
                    <td><small>${l.hora_conteo ? WMS.formatDate(l.hora_conteo.substring(0,10)) + ' ' + l.hora_conteo.substring(11,16) : '-'}</small></td>
                    <td>
                      <div style="font-weight:700;font-size:12px;color:#1e293b">${WMS.esc(l.codigo||'-')}</div>
                      <div style="font-size:10px;color:#64748b;line-height:1.2;margin-top:2px">${WMS.esc(l.producto||'-')}</div>
                    </td>
                    <td><b style="font-size:.8rem;color:#1e293b">${WMS.esc(l.ubicacion||'-')}</b></td>
                    <td><small>${WMS.esc(l.lote)}<br>${WMS.formatDate(l.fecha_vencimiento)}</small></td>
                    <td class="text-center"><b style="color:${color_vu}">${l.dias_vida_util}d</b></td>
                    <td class="text-center">${l.cantidad_cajas ?? '—'}</td>
                    <td class="text-center" style="color:#64748b">${l.unidades_caja ?? '—'}</td>
                    <td class="text-center">${l.saldos ?? '—'}</td>
                    <td class="text-center"><b style="font-size:1.1rem;color:#1d4ed8" title="UND/TOTAL">${parseFloat(l.cantidad_contada)}</b></td>
                    <td class="text-center" style="color:#64748b">${parseFloat(l.cantidad_sistema)}</td>
                    <td class="text-center"><b style="color:${difColor}">${difTxt}</b></td>
                    ${d.sesion.num_conteos > 1 ? `
                      <td class="text-center" style="background:#f8fafc;font-weight:600">${l.ronda===1?l.cantidad_contada:'-'}</td>
                      <td class="text-center" style="background:#f8fafc;font-weight:600">${l.ronda===2?l.cantidad_contada:'-'}</td>
                    ` : ''}
                    <td>
                      <div class="action-btns">
                        <button class="btn btn-xs btn-outline-info" title="Editar Conteo" onclick="WMS_MODULES.inventario._editarLinea(${l.id})" ${l.ajustado ? 'disabled' : ''}>
                          <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <button class="btn btn-xs btn-outline-danger" title="Eliminar" onclick="WMS_MODULES.inventario._eliminarLinea(${l.id},${id})" ${l.ajustado ? 'disabled' : ''}>
                          <i class="fa-solid fa-trash"></i>
                        </button>
                        ${l.diferencia !== 0 && !l.ajustado ? `
                        <button class="btn btn-xs btn-warning" title="Ajustar Inventario" onclick="WMS_MODULES.inventario._ajustarLinea(${l.id},${id})">
                           <i class="fa-solid fa-magic-wand-sparkles"></i>
                        </button>` : ''}
                      </div>
                    </td>
                  </tr>
                `;
              }).join('') || `<tr><td colspan="${d.sesion.num_conteos > 1 ? 14 : 12}" style="padding:40px;text-align:center;color:#94a3b8">No hay líneas registradas</td></tr>`}
            </tbody>
          </table>
        </div>
      `;
    }

    else if (tab === 'general') {
      const consolidated = d.matriz_consolidada || [];
      content.innerHTML = `
        <div style="margin-bottom:10px;"><h3 style="font-size:.85rem;font-weight:700;"><i class="fa-solid fa-list-check"></i> Resumen de Conteo Por Referencia</h3></div>
        <div class="table-container" style="max-height:480px;overflow-y:auto;">
          <table class="data-table compact" id="inv2-gen-table">
            <thead style="position:sticky;top:0;z-index:20;background:#f8fafc">
              <tr>
                <th width="30"></th>
                <th>Referencia</th>
                <th class="text-center">R1</th>
                <th class="text-center">R2</th>
                <th class="text-center">R3</th>
                <th class="text-center" style="background:#eff6ff">Sistema</th>
                <th class="text-center" style="background:#fff7ed">Diferencia</th>
              </tr>
            </thead>
            <tbody>
              ${consolidated.map((m, idx) => {
                const isCons = m.ronda_1 === m.ronda_2;
                return `
                <tr style="background:${!isCons&&m.ronda_2>0?'#fff1f2':'#fff'}">
                  <td class="text-center">
                    <button class="btn btn-xs btn-light" onclick="this.closest('tr').nextElementSibling.toggleAttribute('hidden')">
                       <i class="fa-solid fa-plus"></i>
                    </button>
                  </td>
                  <td>
                    <div style="font-weight:700;font-size:.82rem">${m.codigo} — ${WMS.esc(m.producto)}</div>
                    <div style="font-size:.72rem;color:#475569;margin-top:2px;">${m.ean||'Sin Código de Barras'}</div>
                  </td>
                  <td class="text-center fw-700" style="font-size:.95rem">${m.ronda_1}</td>
                  <td class="text-center fw-700 ${!isCons&&m.ronda_2>0?'text-danger':''}" style="font-size:.95rem">${m.ronda_2||'-'}</td>
                  <td class="text-center fw-700" style="font-size:.95rem">${m.ronda_3||'-'}</td>
                  <td class="text-center fw-700" style="background:#f9fafb;font-size:.9rem">${m.sistema}</td>
                  <td class="text-center fw-800 ${m.diferencia!==0?'text-danger':'text-success'}" style="background:#fffaf5;font-size:.95rem">
                    ${m.diferencia>0?'+':''}${m.diferencia}
                  </td>
                </tr>
                <tr hidden style="background:#f8fafc">
                  <td colspan="8" style="padding:0">
                    <div style="padding:12px 20px; border-left:4px solid #3b82f6">
                      <table style="width:100%;font-size:.75rem" class="detail-subtable">
                        <thead>
                          <tr style="border-bottom:1px solid #cbd5e1;color:#64748b;text-transform:uppercase;font-size:.65rem">
                            <th>Auxiliares</th><th>Ubicación</th><th class="text-center">R1</th><th class="text-center">R2</th><th class="text-center">R3</th><th>F.Venc</th><th>Vencimiento</th><th>Último Registro</th>
                          </tr>
                        </thead>
                        <tbody>
                          ${m.detalles.map(det => `
                            <tr>
                              <td>${WMS.esc(det.auxiliares.join(', '))}</td>
                              <td><span class="badge badge-light-blue">${WMS.esc(det.ubicacion)}</span></td>
                              <td class="text-center fw-700" style="font-size:.85rem">${det.r1||'-'}</td>
                              <td class="text-center fw-700" style="font-size:.85rem">${det.r2||'-'}</td>
                              <td class="text-center fw-700" style="font-size:.85rem">${det.r3||'-'}</td>
                              <td>${WMS.formatDate(det.f_venc)}</td>
                              <td>${det.dias_v_u!==null ? `<b style="color:${det.dias_v_u<30?'#dc2626':'#16a34a'}">${det.dias_v_u}d</b>` : '-'}</td>
                              <td style="color:#64748b;font-style:italic">${WMS_MODULES.inventario._formatFullDate(det.ultimo_c)}</td>
                            </tr>`).join('')}
                        </tbody>
                      </table>
                    </div>
                  </td>
                </tr>`;
              }).join('') || '<tr><td colspan="8" class="table-empty">Sin datos consolidados</td></tr>'}
            </tbody>
          </table>
        </div>`;
    }

    else if (tab === 'ml') {
      // Análisis ML: referencias en sistema no contadas — async via delegación
      content.innerHTML = `
        <div style="text-align:center;padding:30px">
          <div class="spinner"></div>
          <p style="color:#64748b;margin-top:10px;font-size:.85rem">Analizando referencias no contadas...</p>
        </div>`;
      // Delegar a función async para poder usar await
      this._loadMLTab(id, d).catch(e => {
        const c = document.getElementById('inv2-content');
        if (c) c.innerHTML = `<div class="text-danger" style="padding:20px;">Error cargando análisis ML: ${e.message}</div>`;
      });
    }

    else if (tab === 'dif') {
      const difs = (d.matriz_diferencias || []).filter(m => m.diferencia !== 0);
      content.innerHTML = `
        <div class="table-container" style="max-height:420px;overflow-y:auto;">
          <table class="data-table compact">
            <thead style="position:sticky;top:0;z-index:10;background:#f8fafc">
              <tr>
                <th>Referencia</th><th>Ubicación</th><th>Lote</th><th>F.Venc.</th>
                <th class="text-center">Cont. Contada</th>
                <th class="text-center">Cant. Sistema</th>
                <th class="text-center">Cant. Ubic.</th>
                <th class="text-center">Diferencia</th>
                <th>Tipo</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              ${difs.map(m => `
                <tr>
                  <td>
                    <div style="font-weight:700;font-size:.8rem">${WMS.esc(m.codigo||'-')}</div>
                    <div style="font-size:.7rem;color:#64748b">${WMS.esc(m.producto||'-')}</div>
                  </td>
                  <td><span class="badge badge-light-blue">${WMS.esc(m.ubicacion||'-')}</span></td>
                  <td style="font-size:.75rem">${WMS.esc(m.lote||'-')}</td>
                  <td style="font-size:.75rem">${WMS.formatDate(m.fecha_vencimiento)||'-'}</td>
                  <td class="text-center fw-700">${m.cantidad_contada}</td>
                  <td class="text-center">${m.cantidad_sistema_snap}</td>
                  <td class="text-center">${m.cantidad_en_ubicacion||0}</td>
                  <td class="text-center ${m.diferencia>0?'dif-plus':'dif-minus'}">
                    ${m.diferencia>0?'+':''}${m.diferencia}
                  </td>
                  <td>
                    <span style="font-size:.7rem;padding:2px 8px;border-radius:12px;font-weight:700;
                      background:${m.diferencia>0?'#dcfce7':'#fee2e2'};
                      color:${m.diferencia>0?'#16a34a':'#dc2626'}">
                      ${m.tipo_diferencia}
                    </span>
                  </td>
                  <td>
                    <button class="btn btn-xs btn-warning" onclick="WMS_MODULES.inventario._ajustarLinea(${m.id},${id})">
                      <i class="fa-solid fa-sliders"></i> Ajustar
                    </button>
                  </td>
                </tr>`).join('') || '<tr><td colspan="10" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981"></i> Sin diferencias</td></tr>'}
            </tbody>
          </table>
        </div>
        ${difs.length > 0 ? `
        <div style="margin-top:14px;text-align:right">
          <button class="btn btn-danger" onclick="WMS_MODULES.inventario._ajustarTodo(${id})">
            <i class="fa-solid fa-sliders"></i> Ajustar Todas las Diferencias
          </button>
        </div>` : ''}`;
    }

    else if (tab === 'asig') {
      const sesion = d.sesion;
      content.innerHTML = `
        <div style="margin-bottom:12px;text-align:right">
          <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.inventario._addAsigExistente(${id})">
            <i class="fa-solid fa-plus"></i> Agregar Asignación
          </button>
        </div>
        <div class="table-container" style="max-height:380px;overflow-y:auto;">
          <table class="data-table compact">
            <thead>
              <tr><th>Auxiliar</th><th>Ronda</th><th>Instrucción</th><th>Estado</th><th>Notificado</th><th>Finalizado</th><th></th></tr>
            </thead>
            <tbody>
              ${(sesion.asignaciones||[]).map(a => `
                <tr>
                  <td style="font-weight:700">${WMS.esc(a.auxiliar?.nombre||'-')}</td>
                  <td class="text-center"><span class="badge badge-info">R${a.ronda}</span></td>
                  <td style="font-size:.78rem">${WMS.esc(a.tipo_instruccion)} ${a.pasillo?'— '+a.pasillo:''} ${a.modulo?'— '+a.modulo:''}</td>
                  <td>${a.estado}</td>
                  <td style="font-size:.75rem">${a.notificado_at ? WMS.formatDate(a.notificado_at.substring(0,10)) : '-'}</td>
                  <td style="font-size:.75rem">${a.finalizado_at ? WMS.formatDate(a.finalizado_at.substring(0,10)) : '-'}</td>
                  <td>
                    ${a.estado === 'Pendiente' || a.estado === 'Notificado' ? `
                      <button class="btn btn-xs btn-outline-danger" onclick="WMS_MODULES.inventario._deleteAsig(${a.id},${id})">
                        <i class="fa-solid fa-trash"></i>
                      </button>` : ''}
                  </td>
                </tr>`).join('') || '<tr><td colspan="7" class="table-empty">Sin asignaciones</td></tr>'}
            </tbody>
          </table>
        </div>`;
    }

    else if (tab === 'acc') {
      const sesion = d.sesion;
      const enCurso = sesion.estado === 'EnCurso';
      const pendiente = sesion.estado === 'PendienteAjuste';
      content.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:8px 0">
          ${enCurso ? `
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:16px">
            <h4 style="font-size:.85rem;font-weight:800;color:#1e40af;margin:0 0 8px">
              <i class="fa-solid fa-list-check"></i> Estado del conteo
            </h4>
            <p style="font-size:.8rem;color:#1e40af;margin:0">${d.kpis.asignaciones_terminadas} de ${d.kpis.asignaciones_total} auxiliares han finalizado su conteo.</p>
            <div style="margin-top:8px;background:#dbeafe;border-radius:6px;height:8px">
              <div style="width:${d.kpis.pct_avance}%;background:#1a56db;height:8px;border-radius:6px"></div>
            </div>
            <p style="font-size:.75rem;margin:4px 0 0;color:#1e40af">${d.kpis.pct_avance}% completado</p>
          </div>` : ''}
          ${pendiente ? `
          <div style="background:#fff;border:1px solid #e2e8f0;border-left:5px solid #16a34a;border-radius:4px;padding:24px;grid-column:1/-1;box-shadow:0 10px 15px -3px rgba(0,0,0,.05)">
            <h4 style="font-size:1.1rem;font-weight:900;color:#0f172a;margin:0 0 4px">
              <i class="fa-solid fa-shield-halved"></i> Proceso de Ajuste y Conciliación
            </h4>
            <p style="font-size:.85rem;color:#64748b;margin:0 0 20px">
              Analice el impacto en Kardex. Esta auditoría actualizará directamente los saldos del inventario.
            </p>
            
            <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap">
              <div style="flex:1;background:#f8fafc;padding:16px;border-radius:4px;border:1px solid #e2e8f0;text-align:center;min-width:140px">
                 <div style="font-size:1.8rem;font-weight:900;color:#dc2626">${Math.abs(d.kpis.faltantes_unidades)}</div>
                 <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:4px">Faltantes (Uds)</div>
              </div>
              <div style="flex:1;background:#f8fafc;padding:16px;border-radius:4px;border:1px solid #e2e8f0;text-align:center;min-width:140px">
                 <div style="font-size:1.8rem;font-weight:900;color:#16a34a">${d.kpis.sobrantes_unidades}</div>
                 <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:4px">Sobrantes (Uds)</div>
              </div>
              <div style="flex:1;background:#f8fafc;padding:16px;border-radius:4px;border:1px solid #e2e8f0;text-align:center;min-width:140px">
                 <div style="font-size:1.8rem;font-weight:900;color:#f59e0b">${d.kpis.lineas_con_diferencia}</div>
                 <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-top:4px">Líneas Afectadas</div>
              </div>
            </div>

            <div style="padding:12px 14px;background:#fffbeb;border:1px solid #fef3c7;border-radius:4px;margin-bottom:20px;">
              <p style="margin:0;font-size:.75rem;color:#92400e;line-height:1.4">
                 <i class="fa-solid fa-triangle-exclamation"></i> <strong>Aviso Legal y Operativo:</strong> Al proceder con el ajuste masivo, el sistema aplicará automáticamente los movimientos de saldo a las cajas/unidades afectadas en todo el recinto. Esta acción es inmutable y registrará su firma electrónica.
              </p>
            </div>

            <div style="display:flex;gap:10px">
              <button class="btn btn-success btn-lg" style="flex:1;font-size:1rem;font-weight:800;padding:14px" onclick="WMS_MODULES.inventario._ajustarTodo(${id})">
                <i class="fa-solid fa-check-double"></i> APROBAR AJUSTE Y CERRAR INVENTARIO
              </button>
            </div>
          </div>` : ''}
          ${['Ajustado', 'EnCurso', 'PendienteAjuste'].includes(sesion.estado) ? `
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:5px solid #16a34a;border-radius:4px;padding:24px;grid-column:1/-1;box-shadow:0 10px 15px -3px rgba(0,0,0,.05)">
            <h4 style="font-size:1.1rem;font-weight:900;color:#166534;margin:0 0 4px">
              <i class="fa-solid fa-flag-checkered"></i> Concluir Operación
            </h4>
            <p style="font-size:.85rem;color:#166534;margin:0 0 20px">
              ${sesion.estado === 'Ajustado' 
                ? 'Los saldos han sido actualizados. Ahora debe cerrar formalmente el conteo para <strong>liberar las posiciones</strong>.' 
                : '<strong>Aviso:</strong> El inventario no se ha ajustado. Si concluye ahora, las diferencias NO se aplicarán al stock, pero las ubicaciones se liberarán.'}
            </p>
            <div style="display:flex;gap:10px">
              <button class="btn btn-success btn-lg" style="flex:1;font-size:1rem;font-weight:800;padding:14px" onclick="WMS_MODULES.inventario._cerrarSesion(${id})">
                <i class="fa-solid fa-lock-open"></i> CONCLUIR Y LIBERAR POSICIONES
              </button>
            </div>
          </div>` : ''}
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px">
            <h4 style="font-size:.85rem;font-weight:800;color:#1e293b;margin:0 0 8px">
              <i class="fa-solid fa-print"></i> Impresión
            </h4>
            <button class="btn btn-outline-primary full" onclick="WMS_MODULES.inventario._imprimirReporte(${id})">
              <i class="fa-solid fa-file-pdf"></i> Generar Informe del Conteo
            </button>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px">
            <h4 style="font-size:.85rem;font-weight:800;color:#1e293b;margin:0 0 8px">
              <i class="fa-solid fa-table-list"></i> Reporte de Ajustes
            </h4>
            <button class="btn btn-outline-warning full" onclick="WMS_MODULES.inventario.showAjustesReport(${id})">
              <i class="fa-solid fa-receipt"></i> Ver Ajustes Aplicados
            </button>
          </div>
        </div>`;
    }
  },

  async _cerrarSesion(id) {
    if (!confirm('¿Está seguro de cerrar formalmente este conteo? Se liberarán todas las ubicaciones bloqueadas y no se podrán realizar más ajustes.')) return;
    WMS.spinner();
    try {
      const r = await API.post(`/v2/inventario/sesiones/${id}/cerrar`, {});
      WMS.toast('success', r.message || 'Inventario cerrado y ubicaciones liberadas.');
      this.show_dashboard(id);
    } catch(e) {
      WMS.toast('error', e.message || 'Error cerrando sesión');
      this.show_dashboard(id);
    }
  },


  async _loadMLTab(id, d) {
    const content = document.getElementById('inv2-content');
    if (!content) return;
    const rondaParam = d.sesion?.ronda_actual ? `ronda=${d.sesion.ronda_actual}` : '';
    const ml     = await API.get(`/v2/inventario/sesiones/${id}/ml-analisis`, rondaParam);
    const mlData = ml.data || {};
    const refs   = mlData.referencias || [];

    content.innerHTML = `
      <div style="margin-bottom:16px;">
        ${mlData.alerta ? `
        <div style="background:#fff7ed;border:2px solid #fdba74;border-radius:4px;padding:16px;display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;">
          <i class="fa-solid fa-triangle-exclamation fa-2x" style="color:#ea580c;flex-shrink:0;margin-top:2px"></i>
          <div>
            <div style="font-weight:700;color:#9a3412;font-size:.9rem;margin-bottom:4px">ALERTA DE AUSENCIA FÍSICA</div>
            <div style="font-size:.83rem;color:#7c2d12;">${WMS.esc(mlData.alerta)}</div>
          </div>
        </div>` : `
        <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:4px;padding:16px;display:flex;gap:12px;align-items:center;margin-bottom:16px;">
          <i class="fa-solid fa-circle-check fa-2x" style="color:#16a34a;"></i>
          <div style="font-weight:700;color:#166534;font-size:.9rem;">Todas las referencias han sido contadas. ✓</div>
        </div>`}

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:${refs.length > 0 ? '#dc2626' : '#10b981'}">${refs.length}</div>
            <div style="font-size:.72rem;color:#64748b;font-weight:600;text-transform:uppercase;">NO CONTADAS</div>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:#dc2626;">${refs.filter(r => r.impacto === 'alto').length}</div>
            <div style="font-size:.72rem;color:#64748b;font-weight:600;text-transform:uppercase;">IMPACTO ALTO</div>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px;text-align:center;">
            <div style="font-size:1.6rem;font-weight:900;color:#2563eb;">${refs.reduce((s,r) => s + (r.cantidad_sistema||0), 0)}</div>
            <div style="font-size:.72rem;color:#64748b;font-weight:600;text-transform:uppercase;">UNIDADES EN RIESGO</div>
          </div>
        </div>
      </div>

      ${refs.length > 0 ? `
      <div class="table-container" style="max-height:340px;overflow-y:auto;">
        <table class="data-table compact">
          <thead style="position:sticky;top:0;z-index:10;background:#f8fafc;">
            <tr>
              <th>Código</th><th>Referencia</th><th>Ubicación</th>
              <th>Lote</th><th>F. Venc.</th>
              <th class="text-center">Uds. Sistema</th>
              <th class="text-center">Impacto</th>
            </tr>
          </thead>
          <tbody>
            ${refs.map(r => {
              const col   = r.impacto === 'alto' ? '#dc2626' : r.impacto === 'medio' ? '#f59e0b' : '#10b981';
              const label = r.impacto === 'alto' ? 'ALTO' : r.impacto === 'medio' ? 'MEDIO' : 'BAJO';
              return `<tr style="background:${r.impacto==='alto'?'#fef2f2':r.impacto==='medio'?'#fffbeb':'#f0fdf4'}">
                <td style="font-family:monospace;font-size:.75rem;">${WMS.esc(r.codigo_interno||'-')}</td>
                <td style="font-size:.8rem;font-weight:600;">${WMS.esc(r.producto_nombre)}</td>
                <td><span class="badge badge-light-blue">${WMS.esc(r.ubicacion_codigo)}</span></td>
                <td style="font-size:.75rem;font-family:monospace;">${WMS.esc(r.lote||'—')}</td>
                <td style="font-size:.75rem;">${WMS.formatDate(r.fecha_vencimiento)||'—'}</td>
                <td class="text-center" style="font-weight:700;color:#1e40af;">${WMS.formatNum(r.cantidad_sistema)}</td>
                <td class="text-center"><span style="font-weight:800;font-size:.75rem;color:${col};">${label}</span></td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
      <div style="margin-top:12px;padding:12px;background:#fef3c7;border:1px solid #fde68a;border-radius:4px;font-size:.8rem;color:#92400e;">
        <strong><i class="fa-solid fa-robot"></i> Acción automática al ajustar:</strong>
        Al ejecutar "Ajustar Todas las Diferencias", el sistema pondrá en 0 estas <strong>${refs.length}</strong> referencia(s)
        automáticamente (ausencia física confirmada por conteo completo de la ubicación).
      </div>` : ''}
    `;
  },

  _filterMat(q, tableId) {
    const term = q.toLowerCase();
    document.querySelectorAll(`#${tableId} tbody tr`).forEach(r => {
      r.style.display = r.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
  },

  _filterMatDiff(mode) {
    document.querySelectorAll('#inv2-mat-table tbody tr').forEach(r => {
      if (mode === 'all') r.style.display = '';
      else if (mode === 'diff') r.style.display = r.dataset.diff === '1' ? '' : 'none';
      else r.style.display = r.dataset.diff === '0' ? '' : 'none';
    });
  },

  _showConteoManualModal(id) {
    const html = `
      <div style="background:#f8fafc;padding:12px;border-radius:4px;border:1px solid #e2e8f0;margin-bottom:16px;">
        <p style="font-size:.75rem;color:#64748b;margin:0">
          <i class="fa-solid fa-info-circle"></i> Registre productos o ubicaciones que no fueron contemplados originalmente en esta sesión.
        </p>
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Producto (EAN, Código o Nombre) *</label>
        <input type="text" id="m-conteo-p-ac" class="form-control" placeholder="Escriba para buscar..." autocomplete="off">
        <input type="hidden" id="m-conteo-prod-id">
        <input type="hidden" id="m-conteo-prod-upc" value="1">
      </div>

      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Código de Ubicación *</label>
        <input type="text" id="m-conteo-u-ac" class="form-control" placeholder="REC-01, A-01-A..." autocomplete="off" style="text-transform:uppercase;">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="form-group">
          <label class="form-label">Lote</label>
          <input type="text" id="m-conteo-lote" class="form-control" placeholder="Opcional">
        </div>
        <div class="form-group">
          <label class="form-label">F. Vencimiento</label>
          <input type="date" id="m-conteo-venc" class="form-control">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="form-group" id="m-conteo-cantidad-wrap">
          <label class="form-label">Cantidad Contada (Decimal permitido) *</label>
          <input type="number" id="m-conteo-cant" class="form-control" placeholder="0" min="0" step="0.01">
        </div>
        <div class="form-group">
          <label class="form-label">Ronda *</label>
          <select id="m-conteo-ronda" class="form-control">
            <option value="1">Ronda 1</option>
            <option value="2" ${this._dashV2?.sesion?.ronda_actual==2?'selected':''}>Ronda 2</option>
            <option value="3" ${this._dashV2?.sesion?.ronda_actual==3?'selected':''}>Ronda 3</option>
          </select>
        </div>
      </div>
      <div id="m-conteo-preview" style="display:none;font-size:.78rem;color:#475569;margin:-6px 0 14px;"></div>

      <div id="m-conteo-prod-info" class="hidden" style="margin-bottom:16px;padding:12px;background:#f1f5f9;border-radius:4px;font-size:.78rem;border-left:4px solid #3b82f6;">
         <div style="font-weight:700;color:#1e293b;" id="m-conteo-p-name"></div>
         <div id="m-conteo-p-stock" style="color:#64748b;margin-top:2px;font-family:monospace;"></div>
      </div>

      <button id="btn-save-manual" class="btn btn-primary w-full" style="padding:14px;font-weight:800;font-size:.95rem;" onclick="WMS_MODULES.inventario._saveConteoManual(${id})">
        <i class="fa-solid fa-save"></i> REGISTRAR CONTEO
      </button>

      <button class="btn btn-light w-full" style="margin-top:8px;font-size:11px;color:#94a3b8;" onclick="WMS.closeModal()">
        Cerrar
      </button>`;

    WMS.showModal('Conteo Manual Administrativo', html);

    // Inicializar Autocomplete tras render
    setTimeout(() => {
        const pInput = document.getElementById('m-conteo-p-ac');
        if (pInput) {
            WMS.initProductAutocomplete(pInput, (p) => {
                document.getElementById('m-conteo-prod-id').value = p.id;
                document.getElementById('m-conteo-p-name').textContent = p.descripcion;
                document.getElementById('m-conteo-p-stock').textContent = `Saldos sistema: ${p.stock || 0} Uds`;
                document.getElementById('m-conteo-prod-info').classList.remove('hidden');
                const upc = Math.max(1, parseInt(p.unidades_caja) || 1);
                document.getElementById('m-conteo-prod-upc').value = upc;
                this._conteoRenderCantidadInputs(upc);
                document.getElementById('m-conteo-u-ac').focus();
            });
        }
        document.getElementById('m-conteo-p-ac').focus();
    }, 200);
  },

  /** Reemplaza el bloque de cantidad del conteo manual según UPC (mismo criterio que Corrección Manual) */
  _conteoRenderCantidadInputs(upc) {
    const wrap = document.getElementById('m-conteo-cantidad-wrap');
    if (!wrap) return;
    if (upc > 1) {
      wrap.innerHTML = `
        <label class="form-label">Cantidad Contada *</label>
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Cajas</label>
            <input id="m-conteo-cajas" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.inventario._conteoCalcPreview()" placeholder="0">
          </div>
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Saldos</label>
            <input id="m-conteo-saldos" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.inventario._conteoCalcPreview()" placeholder="0">
          </div>
        </div>
        <input type="hidden" id="m-conteo-cant" value="0">`;
    } else {
      wrap.innerHTML = `
        <label class="form-label">Cantidad Contada (Decimal permitido) *</label>
        <input id="m-conteo-cant" type="number" class="form-control" min="0" step="0.01" placeholder="0"
          oninput="WMS_MODULES.inventario._conteoCalcPreview()">
        <input type="hidden" id="m-conteo-cajas" value="0">
        <input type="hidden" id="m-conteo-saldos" value="0">`;
    }
    this._conteoCalcPreview();
  },

  _conteoCalcPreview() {
    const upc = Math.max(1, parseInt(document.getElementById('m-conteo-prod-upc')?.value || '1') || 1);
    const preview = document.getElementById('m-conteo-preview');
    if (!preview) return;
    if (upc > 1) {
      const cajas  = parseFloat(document.getElementById('m-conteo-cajas')?.value  || '0') || 0;
      const saldos = parseFloat(document.getElementById('m-conteo-saldos')?.value || '0') || 0;
      const total  = cajas * upc + saldos;
      const cantEl = document.getElementById('m-conteo-cant');
      if (cantEl) cantEl.value = total;
      preview.style.display = 'block';
      preview.innerHTML = `<b>UND/TOTAL:</b> ${cajas} cajas × ${upc} u/caja + ${saldos} saldos = `
        + `<b style="color:#1e40af;font-size:1.05em;">${total.toFixed(2)}</b>`;
    } else {
      preview.style.display = 'none';
    }
  },

  async _saveConteoManual(id) {
    const prodId = document.getElementById('m-conteo-prod-id')?.value;
    const uCod   = document.getElementById('m-conteo-u-ac')?.value.trim();
    const lote   = document.getElementById('m-conteo-lote')?.value.trim();
    const venc   = document.getElementById('m-conteo-venc')?.value;
    const cant   = document.getElementById('m-conteo-cant')?.value;
    const cajas  = document.getElementById('m-conteo-cajas')?.value;
    const saldos = document.getElementById('m-conteo-saldos')?.value;
    const ronda  = document.getElementById('m-conteo-ronda')?.value;

    if (!prodId || !uCod || cant === '') {
      WMS.toast('warning', 'Complete los campos obligatorios (*)');
      return;
    }

    const btn = document.getElementById('btn-save-manual');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Registrando...'; }

    try {
      const r = await API.post(`/v2/inventario/sesiones/${id}/conteo-manual`, {
        producto_id: prodId,
        ubicacion_codigo: uCod,
        lote: lote || null,
        fecha_vencimiento: venc || null,
        cantidad: cant,
        cantidad_cajas: cajas !== undefined && cajas !== '' ? cajas : null,
        saldos: saldos !== undefined && saldos !== '' ? saldos : null,
        ronda: ronda
      });
      
      WMS.toast('success', r.message || 'Conteo registrado correctamente');
      
      // Limpiar formulario para permitir entrada rápida continua
      document.getElementById('m-conteo-p-ac').value = '';
      document.getElementById('m-conteo-prod-id').value = '';
      document.getElementById('m-conteo-prod-upc').value = '1';
      document.getElementById('m-conteo-u-ac').value = '';
      document.getElementById('m-conteo-lote').value = '';
      document.getElementById('m-conteo-venc').value = '';
      document.getElementById('m-conteo-prod-info').classList.add('hidden');
      this._conteoRenderCantidadInputs(1);
      document.getElementById('m-conteo-p-ac').focus();
      
      // Refrescar dashboard de fondo
      this.verDashboardV2(id);
    } catch(e) {
      WMS.toast('error', e.message || 'Error registrando conteo');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> REGISTRAR CONTEO'; }
    }
  },

  async _editarLinea(lineaId) {
    if (!this._dashV2 || !this._dashV2.matriz_conteo) {
        WMS.toast('error', 'Sesión no cargada completamente. Refrescando...');
        if (this._dashV2Id) this.verDashboardV2(this._dashV2Id);
        return;
    }
    const l = this._dashV2.matriz_conteo.find(x => parseInt(x.id) === parseInt(lineaId));
    if (!l) return WMS.toast('error', 'No se encontró la línea en el dashboard actual');
    const upc = Math.max(1, parseInt(l.unidades_caja) || 1);
    WMS.showModal('Corrección Administrativa de Conteo', `
      <div class="row">
        <div class="col-md-6">
          <div class="form-group">
            <label class="form-label">Código Producto</label>
            <input id="edit-prod" type="text" class="form-control" value="${l ? l.codigo : ''}" placeholder="Ej: 123456">
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-group">
            <label class="form-label">Ubicación</label>
            <input id="edit-ubic" type="text" class="form-control" value="${l ? l.ubicacion : ''}" placeholder="Ej: A-01-A">
          </div>
        </div>
      </div>
      <div class="row">
        <div class="col-md-6">
          <div class="form-group" id="edit-cantidad-wrap"></div>
        </div>
        <div class="col-md-6">
          <div class="form-group">
            <label class="form-label">Fecha Vencimiento</label>
            <input id="edit-f-venc" type="date" class="form-control" value="${l && l.fecha_vencimiento ? l.fecha_vencimiento.substring(0,10) : ''}">
          </div>
        </div>
      </div>
      <div id="edit-preview" style="display:none;font-size:.78rem;color:#475569;margin:-6px 0 14px;"></div>
      <div class="form-group">
        <label class="form-label">Motivo de la corrección <span class="required">*</span></label>
        <input id="edit-motivo" class="form-control" placeholder="Ej: Error de digitación, reconteo físico...">
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal-2')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.inventario._saveEditLinea(${lineaId})">
         <i class="fa-solid fa-save"></i> Guardar Cambios
       </button>`, 'md', 'generic-modal-2');

    this._editRenderCantidadInputs(upc, l ? l.cantidad_contada : 0, l);
  },

  /** Reemplaza el bloque de cantidad de la edición de línea según UPC (mismo criterio que Corrección Manual/Conteo Manual) */
  _editRenderCantidadInputs(upc, cantidadActual, l) {
    const wrap = document.getElementById('edit-cantidad-wrap');
    if (!wrap) return;
    if (upc > 1) {
      // Usa el desglose realmente capturado si existe; solo recalcula (floor/resto)
      // para líneas antiguas que no tienen cantidad_cajas/saldos guardados.
      const tieneDesglose = l && (l.cantidad_cajas !== null && l.cantidad_cajas !== undefined);
      const cajasIni  = tieneDesglose ? l.cantidad_cajas : Math.floor((cantidadActual || 0) / upc);
      const saldosIni = tieneDesglose ? (l.saldos ?? 0)   : (cantidadActual || 0) - (cajasIni * upc);
      wrap.innerHTML = `
        <input type="hidden" id="edit-prod-upc" value="${upc}">
        <label class="form-label">Cantidad Contada <span class="required">*</span></label>
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Cajas</label>
            <input id="edit-cajas" type="number" class="form-control" min="0" step="1" value="${cajasIni}"
              oninput="WMS_MODULES.inventario._editCalcPreview()" placeholder="0">
          </div>
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Saldos</label>
            <input id="edit-saldos" type="number" class="form-control" min="0" step="1" value="${saldosIni}"
              oninput="WMS_MODULES.inventario._editCalcPreview()" placeholder="0">
          </div>
        </div>
        <input type="hidden" id="edit-qty" value="${cantidadActual || 0}">`;
    } else {
      wrap.innerHTML = `
        <input type="hidden" id="edit-prod-upc" value="${upc}">
        <label class="form-label">Cantidad Contada <span class="required">*</span></label>
        <input id="edit-qty" type="number" class="form-control" min="0" step="0.01" value="${cantidadActual || 0}"
          oninput="WMS_MODULES.inventario._editCalcPreview()">
        <input type="hidden" id="edit-cajas" value="0">
        <input type="hidden" id="edit-saldos" value="0">`;
    }
    this._editCalcPreview();
  },

  _editCalcPreview() {
    const upc = Math.max(1, parseInt(document.getElementById('edit-prod-upc')?.value || '1') || 1);
    const preview = document.getElementById('edit-preview');
    if (!preview) return;
    if (upc > 1) {
      const cajas  = parseFloat(document.getElementById('edit-cajas')?.value  || '0') || 0;
      const saldos = parseFloat(document.getElementById('edit-saldos')?.value || '0') || 0;
      const total  = cajas * upc + saldos;
      const qtyEl  = document.getElementById('edit-qty');
      if (qtyEl) qtyEl.value = total;
      preview.style.display = 'block';
      preview.innerHTML = `<b>UND/TOTAL:</b> ${cajas} cajas × ${upc} u/caja + ${saldos} saldos = `
        + `<b style="color:#1e40af;font-size:1.05em;">${total.toFixed(2)}</b>`;
    } else {
      preview.style.display = 'none';
    }
  },

  async _saveEditLinea(lineaId) {
    const qty = document.getElementById('edit-qty')?.value;
    const prod = document.getElementById('edit-prod')?.value.trim();
    const ubic = document.getElementById('edit-ubic')?.value.trim();
    const fvenc = document.getElementById('edit-f-venc')?.value;
    const motivo = document.getElementById('edit-motivo')?.value.trim();
    const cajas = document.getElementById('edit-cajas')?.value;
    const saldos = document.getElementById('edit-saldos')?.value;

    if (qty === '' || qty < 0) return WMS.toast('warning', 'Ingrese una cantidad válida');
    if (!motivo) return WMS.toast('warning', 'Ingrese el motivo de la corrección');

    try {
      await API.put(`/v2/inventario/lineas/${lineaId}`, {
        cantidad_contada: parseFloat(qty),
        cantidad_cajas: cajas !== undefined && cajas !== '' ? cajas : null,
        saldos: saldos !== undefined && saldos !== '' ? saldos : null,
        nuevo_producto_codigo: prod,
        nueva_ubicacion_codigo: ubic,
        fecha_vencimiento: fvenc,
        motivo
      });
      WMS.toast('success', 'Línea actualizada correctamente');
      WMS.closeModal('generic-modal-2');
      this.verDashboardV2(this._dashV2Id);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async _eliminarLinea(lineaId, sesionId) {
    const motivo = prompt('Motivo para eliminar esta línea (requerido):');
    if (motivo === null) return;
    if (!motivo.trim()) return WMS.toast('warning', 'Ingrese un motivo');
    try {
      const r = await API.delete(`/v2/inventario/lineas/${lineaId}`, { motivo });
      if (r && r.error) throw new Error(r.message);
      WMS.toast('success', 'Línea eliminada');
      this.verDashboardV2(sesionId);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async _resetServerCache() {
    WMS.toast('info', 'Solicitando sincronización de archivos...', 2000);
    try {
      const r = await API.post('/sistema/opcache-reset', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Sincronización completada. El servidor ya reconoce las últimas versiones de los archivos PHP.');
      // Forzar recarga de datos del dashboard
      if (this._dashV2Id) this.verDashboardV2(this._dashV2Id);
    } catch(e) {
      WMS.toast('error', 'Error sincronizando: ' + e.message);
    }
  },

  async _ajustarLinea(lineaId, sesionId) {
    if (!lineaId || !sesionId) return WMS.toast('error', 'IDs de operación faltantes');
    WMS.showModal('Validación de Ajuste Individual', `
      <div style="text-align:center;padding:25px">
        <i class="fa-solid fa-sliders fa-3x" style="color:#f59e0b;margin-bottom:16px"></i>
        <h3 style="font-weight:800;font-size:1.2rem;color:#0f172a;margin-bottom:10px">Ajuste de Línea</h3>
        <p style="font-size:.85rem;color:#475569;margin-bottom:20px">Se ejecutará el movimiento de Kardex únicamente para esta referencia. ¿Autoriza corregir esta discrepancia?</p>
        <button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal-2')" style="margin-right:8px;font-weight:600">Cancelar</button>
        <button class="btn btn-warning" onclick="WMS_MODULES.inventario.__execAjustarLinea(${lineaId}, ${sesionId})" style="font-weight:800">
           Autorizar Línea
        </button>
      </div>`, '', 'sm', 'generic-modal-2');
  },

  async __execAjustarLinea(lineaId, sesionId) {
    WMS.closeModal('generic-modal-2');
    WMS.spinner('inv2-content');
    try {
      const r = await API.post(`/v2/inventario/sesiones/${sesionId}/ajustar-linea`, { linea_id: lineaId });
      const d = r.data || {};
      // Mostrar nuevo stock actualizado
      const dif = d.diferencia_real ?? 0;
      const difStr = dif > 0 ? `<span style="color:#10b981;font-weight:700;">+${WMS.formatNum(dif)}</span>`
                             : `<span style="color:#ef4444;font-weight:700;">${WMS.formatNum(dif)}</span>`;
      WMS.toast('success', r.message || 'Ajuste aplicado');
      WMS.showModal('Ajuste Aplicado — Nuevo Stock', `
        <div style="text-align:center;padding:20px">
          <i class="fa-solid fa-circle-check fa-3x" style="color:#10b981;margin-bottom:16px"></i>
          <h3 style="font-weight:800;font-size:1.1rem;margin-bottom:12px">Stock actualizado correctamente</h3>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;text-align:left;max-width:320px;margin:0 auto;">
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #e2e8f0;">
              <span style="color:#64748b;font-size:.85rem;">Contado físicamente:</span>
              <strong style="color:#0f172a;">${WMS.formatNum(d.cantidad_contada ?? 0)}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #e2e8f0;">
              <span style="color:#64748b;font-size:.85rem;">Diferencia aplicada:</span>
              <strong>${difStr}</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;background:#ecfdf5;margin:-0px -4px;border-radius:6px;padding:8px 10px;margin-top:6px;">
              <span style="color:#065f46;font-size:.9rem;font-weight:600;">Nuevo stock sistema:</span>
              <strong style="color:#059669;font-size:1.1rem;">${WMS.formatNum(d.stock_nuevo ?? 0)}</strong>
            </div>
          </div>
          <button class="btn btn-primary" style="margin-top:20px;min-width:140px;" onclick="WMS.closeModal('generic-modal');WMS_MODULES.inventario.verDashboardV2(${sesionId})">
            <i class="fa-solid fa-arrow-left"></i> Volver al Dashboard
          </button>
        </div>`, '', 'sm');
    } catch(e) { WMS.toast('error', e.message); this.verDashboardV2(sesionId); }
  },

  async _ajustarTodo(sesionId) {
    const d = this._dashV2;
    if (!d || !d.kpis) return WMS.toast('error', 'Faltan KPIs para autorizar cierre');
    WMS.showModal('Auditoría de Cierre y Ajuste Masivo', `
      <div style="text-align:center;padding:25px">
        <i class="fa-solid fa-triangle-exclamation fa-4x" style="color:#ef4444;margin-bottom:20px"></i>
        <h3 style="font-weight:900;font-size:1.3rem;color:#0f172a;margin-bottom:14px;text-transform:uppercase">¿Confirmar Ajuste y Cierre?</h3>
        <p style="font-size:.9rem;color:#475569;margin-bottom:24px;line-height:1.5">
          Esta acción autorizará la conciliación afectando <strong style="color:#dc2626;font-size:1.1rem">${d.kpis.lineas_con_diferencia} líneas</strong> discrepantes.<br><br>
          Se registrarán en la tabla de operaciones y Kardex. <strong>No podrá deshacerse de forma masiva.</strong>
        </p>
        <button class="btn btn-secondary btn-lg" onclick="WMS.closeModal('generic-modal-2')" style="width:140px;margin-right:10px">Cancelar</button>
        <button class="btn btn-success btn-lg" onclick="WMS_MODULES.inventario.__execAjustarTodo(${sesionId})" style="width:200px">
          <i class="fa-solid fa-signature"></i> Firmar Autorización
        </button>
      </div>`, '', 'md', 'generic-modal-2');
  },

  async __execAjustarTodo(sesionId) {
    WMS.closeModal('generic-modal-2');
    WMS.spinner('inv2-content');
    try {
      const r = await API.post(`/v2/inventario/sesiones/${sesionId}/ajustar-todo`, { confirm: true });
      const d = r.data || {};
      const resumen   = d.stock_resumen || [];
      const nConteo   = d.ajustes_conteo || 0;
      const nML       = d.ajustes_ml_ausencia || 0;
      const total     = d.ajustes_realizados || 0;

      WMS.closeModal('generic-modal');

      // Mostrar resumen detallado de todos los ajustes aplicados
      const rowsConteo = resumen.filter(x => x.tipo === 'conteo').map(x => {
        const dif = x.diferencia ?? 0;
        const col = dif > 0 ? '#10b981' : (dif < 0 ? '#ef4444' : '#94a3b8');
        const sign = dif > 0 ? '+' : '';
        return `<tr>
          <td style="font-size:.8rem;font-family:monospace;">P-${x.producto_id}</td>
          <td style="font-size:.8rem;">Ubic-${x.ubicacion_id}${x.lote ? ' · ' + WMS.esc(x.lote) : ''}</td>
          <td class="text-center" style="font-weight:700;color:#1e40af;">${WMS.formatNum(x.cantidad_nueva)}</td>
          <td class="text-center" style="font-weight:700;color:${col};">${sign}${WMS.formatNum(dif)}</td>
        </tr>`;
      }).join('');

      const rowsML = resumen.filter(x => x.tipo === 'ml_ausencia').map(x => {
        return `<tr style="background:#fff7ed;">
          <td style="font-size:.8rem;font-family:monospace;">P-${x.producto_id}</td>
          <td style="font-size:.8rem;">Ubic-${x.ubicacion_id}${x.lote ? ' · ' + WMS.esc(x.lote) : ''}</td>
          <td class="text-center" style="font-weight:700;color:#dc2626;">0</td>
          <td class="text-center" style="font-weight:700;color:#ef4444;">${WMS.formatNum(x.diferencia)}</td>
        </tr>`;
      }).join('');

      WMS.showModal('Inventario Cerrado — Resumen de Ajustes', `
        <div style="padding:10px 0">
          <div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
            <div style="flex:1;min-width:120px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:4px;padding:14px;text-align:center;">
              <div style="font-size:1.8rem;font-weight:900;color:#059669;">${total}</div>
              <div style="font-size:.75rem;color:#065f46;font-weight:600;">AJUSTES TOTALES</div>
            </div>
            <div style="flex:1;min-width:120px;background:#eff6ff;border:1px solid #93c5fd;border-radius:4px;padding:14px;text-align:center;">
              <div style="font-size:1.8rem;font-weight:900;color:#2563eb;">${nConteo}</div>
              <div style="font-size:.75rem;color:#1e40af;font-weight:600;">POR CONTEO</div>
            </div>
            ${nML > 0 ? `<div style="flex:1;min-width:120px;background:#fff7ed;border:1px solid #fdba74;border-radius:4px;padding:14px;text-align:center;">
              <div style="font-size:1.8rem;font-weight:900;color:#ea580c;">${nML}</div>
              <div style="font-size:.75rem;color:#9a3412;font-weight:600;">ML AUSENCIAS</div>
            </div>` : ''}
          </div>
          ${rowsConteo || rowsML ? `
          <div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:4px;">
            <table class="erp-table" style="font-size:.8rem;">
              <thead><tr style="background:#f1f5f9;position:sticky;top:0;">
                <th>Producto</th><th>Ubicación</th>
                <th class="text-center">Nuevo Stock</th>
                <th class="text-center">Diferencia</th>
              </tr></thead>
              <tbody>
                ${rowsConteo}
                ${nML > 0 && rowsML ? `<tr><td colspan="4" style="background:#fff7ed;font-weight:700;font-size:.75rem;color:#9a3412;padding:8px 12px;"><i class="fa-solid fa-robot"></i> Referencias eliminadas por ausencia física (ML)</td></tr>${rowsML}` : ''}
              </tbody>
            </table>
          </div>` : '<p style="text-align:center;color:#94a3b8;font-style:italic;margin:20px 0;">Sin diferencias detectadas</p>'}
          <div style="text-align:center;margin-top:16px;">
            <button class="btn btn-primary" onclick="WMS.closeModal('generic-modal');WMS_MODULES.inventario.show_sesiones()">
              <i class="fa-solid fa-check"></i> Finalizar y Ver Sesiones
            </button>
          </div>
        </div>`, '', 'lg');

      WMS.toast('success', `Inventario cerrado: ${total} ajuste(s) aplicados${nML > 0 ? `, ${nML} ausencias ML` : ''}`);
    } catch(e) {
      WMS.toast('error', e.message || 'Error ejecutando ajuste masivo');
      this.verDashboardV2(sesionId);
    }
  },

  async _addAsigExistente(sesionId) {
    try {
      const resp = await API.get('/param/personal', 'limit=200');
      const auxiliares = resp.data || [];
      WMS.showModal('Agregar Asignación', `
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label class="form-label">Auxiliar <span class="required">*</span></label>
            <select id="new-asig-aux" class="form-control">
              <option value="">Seleccionar...</option>
              ${auxiliares.map(a => `<option value="${a.id}">${WMS.esc(a.nombre)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Ronda</label>
            <select id="new-asig-ronda" class="form-control">
              ${[1,2,3].map(i=>`<option value="${i}">Ronda ${i}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo de instrucción</label>
            <select id="new-asig-tipo" class="form-control" onchange="WMS_MODULES.inventario._toggleNewAsigTipo(this)">
              <option value="Libre">Libre</option>
              <option value="Pasillo">Por Pasillo</option>
              <option value="Modulo">Por Módulo</option>
              <option value="Referencia">Por Referencia</option>
            </select>
          </div>
          <div class="form-group" id="new-asig-detalle-group">
            <label class="form-label">Detalle</label>
            <input id="new-asig-detalle" class="form-control" placeholder="Ej: Pasillo B...">
          </div>
          <div class="form-group" id="new-asig-prod-group" style="display:none;grid-column:1/-1">
            <label class="form-label">Producto <span class="required">*</span></label>
            <input id="new-asig-prod-ac" class="form-control" placeholder="Buscar producto por nombre o código...">
            <input id="new-asig-prod-id" type="hidden">
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal-2')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.inventario._saveNewAsig(${sesionId})">
           <i class="fa-solid fa-save"></i> Agregar
         </button>`, 'sm', 'generic-modal-2');
    } catch(e) { WMS.toast('error', 'Error cargando auxiliares'); }
    // Inicializar autocomplete para Referencia después de render
    setTimeout(() => {
      const acInp = document.getElementById('new-asig-prod-ac');
      if (acInp) {
        WMS.initProductAutocomplete(acInp, (p) => {
          acInp.value = `[${p.codigo_interno}] ${p.descripcion}`;
          const hidInp = document.getElementById('new-asig-prod-id');
          if (hidInp) hidInp.value = p.id;
        });
      }
    }, 200);
  },

  _toggleNewAsigTipo(sel) {
    const tipo = sel.value;
    const dg   = document.getElementById('new-asig-detalle-group');
    const pg   = document.getElementById('new-asig-prod-group');
    if (dg) dg.style.display = tipo === 'Referencia' ? 'none' : '';
    if (pg) pg.style.display = tipo === 'Referencia' ? '' : 'none';
    const detInp  = document.getElementById('new-asig-detalle');
    const prodInp = document.getElementById('new-asig-prod-ac');
    const hidInp  = document.getElementById('new-asig-prod-id');
    if (detInp)  detInp.value  = '';
    if (prodInp) prodInp.value = '';
    if (hidInp)  hidInp.value  = '';
  },

  async _saveNewAsig(sesionId) {
    const auxId = document.getElementById('new-asig-aux')?.value;
    if (!auxId) return WMS.toast('warning', 'Seleccione un auxiliar');
    const tipo   = document.getElementById('new-asig-tipo')?.value;
    const detalle = document.getElementById('new-asig-detalle')?.value.trim();
    const prodId  = document.getElementById('new-asig-prod-id')?.value;
    if (tipo === 'Referencia' && !prodId) return WMS.toast('warning', 'Seleccione un producto válido');
    try {
      await API.post(`/v2/inventario/sesiones/${sesionId}/asignaciones`, {
        auxiliar_id:       parseInt(auxId),
        ronda:             parseInt(document.getElementById('new-asig-ronda')?.value || 1),
        tipo_instruccion:  tipo,
        pasillo:           tipo === 'Pasillo'    ? detalle          : null,
        modulo:            tipo === 'Modulo'     ? detalle          : null,
        instruccion_libre: tipo === 'Libre'      ? detalle          : null,
        producto_id:       tipo === 'Referencia' ? parseInt(prodId) : null,
      });
      WMS.toast('success', 'Asignación creada');
      WMS.closeModal('generic-modal-2');
      this.verDashboardV2(sesionId);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async _deleteAsig(asigId, sesionId) {
    if (!confirm('¿Eliminar esta asignación?')) return;
    try {
      await API.delete(`/v2/inventario/asignaciones/${asigId}`, {});
      WMS.toast('success', 'Asignación eliminada');
      this.verDashboardV2(sesionId);
    } catch(e) { WMS.toast('error', e.message); }
  },

  // ── CÍCLICO: GESTIÓN DE REFERENCIAS ───────────────────────────────────────
  async _ciclicoRefs(sesionId) {
    WMS.spinner();
    try {
      const [sesR, auxR] = await Promise.all([
        API.get(`/v2/inventario/sesiones/${sesionId}`),
        API.get('/param/personal', 'limit=200'),
      ]);
      const sesion     = sesR.data || {};
      const asigs      = (sesion.asignaciones || []).filter(a => a.tipo_instruccion === 'Referencia');
      const auxiliares = auxR.data || [];

      WMS.showModal(`Referencias Cíclico: ${WMS.esc(sesion.nombre || '#' + sesionId)}`, `
        <div style="margin-bottom:14px;">
          <div style="font-size:.72rem;font-weight:700;color:#64748b;margin-bottom:8px;letter-spacing:.5px;">
            <i class="fa-solid fa-box-open"></i> REFERENCIAS ASIGNADAS (${asigs.length})
          </div>
          <div id="ciclic-refs-list" style="max-height:220px;overflow-y:auto;">
            ${asigs.length ? asigs.map(a => `
              <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;
                background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:5px;">
                <div>
                  <div style="font-weight:700;font-size:.82rem;color:#1e293b;">
                    ${WMS.esc(a.producto?.nombre || '—')}
                  </div>
                  <div style="font-size:.7rem;color:#64748b;">
                    <i class="fa-solid fa-user" style="color:#94a3b8;"></i>
                    ${WMS.esc(a.auxiliar?.nombre || 'Auxiliar #' + a.auxiliar_id)}
                    <span style="margin-left:6px;font-family:monospace;color:#94a3b8;">${WMS.esc(a.producto?.codigo_interno || '')}</span>
                  </div>
                </div>
                <button class="btn btn-xs btn-outline-danger"
                  onclick="WMS_MODULES.inventario._deleteAsigCiclic(${a.id},${sesionId})">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>`).join('')
            : '<div style="text-align:center;padding:20px;color:#94a3b8;font-size:.8rem;"><i class="fa-solid fa-box-open" style="display:block;font-size:1.5rem;margin-bottom:8px;opacity:.4;"></i>Sin referencias agregadas aún</div>'}
          </div>
        </div>

        <div style="border-top:1px solid #e2e8f0;padding-top:14px;">
          <div style="font-size:.72rem;font-weight:700;color:#64748b;margin-bottom:10px;letter-spacing:.5px;">
            <i class="fa-solid fa-plus" style="color:#1a56db;"></i> AGREGAR REFERENCIAS
          </div>
          <div id="ciclic-ref-rows" style="display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;"></div>
          <button class="btn btn-sm btn-outline-primary mt-8" onclick="WMS_MODULES.inventario._addCiclicRefRow()">
            <i class="fa-solid fa-plus"></i> Agregar otra fila
          </button>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.inventario._guardarCiclicRefs(${sesionId})">
           <i class="fa-solid fa-save"></i> Guardar Referencias
         </button>`);

      this._ciclicAuxCache = auxiliares;
      this._addCiclicRefRow();
    } catch(e) { WMS.toast('error', e.message || 'Error cargando sesión'); }
  },

  _addCiclicRefRow() {
    const cont = document.getElementById('ciclic-ref-rows');
    if (!cont) return;
    const auxiliares = this._ciclicAuxCache || [];
    const div = document.createElement('div');
    div.className = 'ciclic-ref-row';
    div.style.cssText = 'display:grid;grid-template-columns:2fr 1fr auto;gap:8px;align-items:end;background:#f8fafc;padding:8px;border-radius:6px;border:1px solid #e2e8f0';
    div.innerHTML = `
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#64748b;">Producto</label>
        <input class="form-control ciclic-ref-prod-ac" placeholder="Buscar por nombre o código...">
        <input class="ciclic-ref-prod-id" type="hidden">
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#64748b;">Auxiliar</label>
        <select class="form-control ciclic-ref-aux">
          <option value="">Seleccionar...</option>
          ${auxiliares.map(a => `<option value="${a.id}">${WMS.esc(a.nombre)}</option>`).join('')}
        </select>
      </div>
      <div style="padding-bottom:2px">
        <button class="btn btn-sm btn-outline-danger" onclick="this.closest('.ciclic-ref-row').remove()">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>`;
    cont.appendChild(div);
    const ac    = div.querySelector('.ciclic-ref-prod-ac');
    const idInp = div.querySelector('.ciclic-ref-prod-id');
    setTimeout(() => {
      WMS.initProductAutocomplete(ac, p => {
        ac.value = `[${p.codigo_interno}] ${p.descripcion}`;
        idInp.value = p.id;
      });
    }, 50);
  },

  async _guardarCiclicRefs(sesionId) {
    const rows = document.querySelectorAll('.ciclic-ref-row');
    const items = [];
    let filaIncompleta = false;
    rows.forEach(row => {
      const prodId = row.querySelector('.ciclic-ref-prod-id')?.value;
      const auxId  = row.querySelector('.ciclic-ref-aux')?.value;
      if (!prodId && !auxId) return; // fila vacía sin tocar, se ignora
      if (!prodId || !auxId) { filaIncompleta = true; return; }
      items.push({ producto_id: parseInt(prodId), auxiliar_id: parseInt(auxId) });
    });
    if (filaIncompleta) return WMS.toast('warning', 'Complete producto y auxiliar en cada fila, o elimine las que no vaya a usar');
    if (items.length === 0) return WMS.toast('warning', 'Agregue al menos una referencia con producto y auxiliar');

    try {
      for (const item of items) {
        await API.post(`/v2/inventario/sesiones/${sesionId}/asignaciones`, {
          auxiliar_id:      item.auxiliar_id,
          ronda:            1,
          tipo_instruccion: 'Referencia',
          producto_id:      item.producto_id,
        });
      }
      WMS.toast('success', `${items.length} referencia(s) agregada(s)`);
      this._ciclicoRefs(sesionId);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async _deleteAsigCiclic(asigId, sesionId) {
    if (!confirm('¿Eliminar esta referencia?')) return;
    try {
      await API.delete(`/v2/inventario/asignaciones/${asigId}`, {});
      WMS.toast('success', 'Referencia eliminada');
      this._ciclicoRefs(sesionId);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async _eliminarSesion(id, nombre) {
    if (!confirm(`¿Eliminar la sesión "${nombre}"?\n\nEsta acción eliminará todas las líneas y asignaciones. No se puede deshacer.`)) return;
    try {
      await API.delete(`/v2/inventario/sesiones/${id}`, {});
      WMS.toast('success', `Sesión "${nombre}" eliminada`);
      this.show_sesiones();
    } catch(e) { WMS.toast('error', e.message || 'Error al eliminar la sesión'); }
  },

  async _imprimirReporte(sesionId) {
    try {
      const r = await API.get(`/v2/inventario/sesiones/${sesionId}/reporte`);
      const d = r.data || {};
      const s = d.sesion || {};
      const res = d.resumen || {};
      const lineas = d.lineas || [];
      const ajustes = d.ajustes || [];

      const html = `
        <html><head><title>Informe Conteo #${sesionId}</title>
        <style>
          body{font-family:Arial,sans-serif;font-size:11px;color:#1e293b}
          h1{font-size:16px;margin:0 0 4px}h2{font-size:13px;margin:16px 0 6px;color:#1a56db}
          table{width:100%;border-collapse:collapse;margin-bottom:16px}
          th{background:#1e293b;color:#fff;padding:5px 8px;font-size:10px;text-align:left}
          td{padding:4px 8px;border-bottom:1px solid #e2e8f0;font-size:10px}
          .kpi{display:inline-block;padding:4px 12px;border-radius:6px;font-weight:700;margin:0 6px}
          .badge-r{background:#fee2e2;color:#dc2626}.badge-g{background:#dcfce7;color:#16a34a}
          @media print{button{display:none}}
        </style></head><body>
        <h1>Informe de Inventario: ${WMS.esc(s.nombre||'')}</h1>
        <p style="color:#64748b;margin:0">Tipo: ${s.tipo} | Estado: ${s.estado} | Creado por: ${WMS.esc(s.creado_por?.nombre||'-')} | Fecha: ${WMS.formatDate(s.created_at)||'-'}</p>
        <hr style="margin:10px 0">

        <div style="margin-bottom:12px">
          <span class="kpi" style="background:#eff6ff;color:#1d4ed8">IRA: ${res.pct_exactitud}%</span>
          <span class="kpi" style="background:#f8fafc">Total líneas: ${res.total_lineas}</span>
          <span class="kpi badge-g">Exactas: ${res.exactas}</span>
          <span class="kpi badge-r">Con diferencia: ${res.con_diferencia}</span>
          <span class="kpi badge-r">Faltantes: ${res.faltantes_total}</span>
          <span class="kpi badge-g">Sobrantes: ${res.sobrantes_total}</span>
        </div>

        <h2>Detalle del Conteo</h2>
        <table>
          <thead><tr><th>Ronda</th><th>Hora</th><th>Auxiliar</th><th>Referencia</th><th>Producto</th>
            <th>Ubicación</th><th>Lote</th><th>F.Venc</th><th>Días V.U.</th>
            <th>Contado</th><th>Sistema</th><th>Diferencia</th></tr></thead>
          <tbody>
            ${lineas.map(l=>`<tr>
              <td>R${l.ronda}</td>
              <td>${l.hora_conteo?l.hora_conteo.toString().substring(11,16):'-'}</td>
              <td>${WMS.esc(l.auxiliar||'-')}</td>
              <td style="font-family:monospace">${WMS.esc(l.referencia||'-')}</td>
              <td>${WMS.esc(l.producto||'-')}</td>
              <td>${WMS.esc(l.ubicacion||'-')}</td>
              <td>${WMS.esc(l.lote||'-')}</td>
              <td>${WMS.formatDate(l.fecha_vencimiento)||'-'}</td>
              <td>${l.dias_vida_util!==null?l.dias_vida_util+'d':'-'}</td>
              <td style="font-weight:700">${l.cantidad_contada}</td>
              <td>${l.cantidad_sistema}</td>
              <td style="color:${l.diferencia>0?'#16a34a':l.diferencia<0?'#dc2626':'#64748b'};font-weight:700">
                ${l.diferencia>0?'+':''}${l.diferencia}
              </td>
            </tr>`).join('')}
          </tbody>
        </table>

        ${ajustes.length>0?`
        <h2>Ajustes Aplicados</h2>
        <table>
          <thead><tr><th>Fecha</th><th>Hora</th><th>Referencia</th><th>Tipo</th><th>Físico</th><th>Sistema</th><th>Diferencia</th><th>Ubicación</th><th>Ajustado por</th></tr></thead>
          <tbody>
            ${ajustes.map(a=>`<tr>
              <td>${WMS.formatDate(a.fecha)||'-'}</td><td>${a.hora||'-'}</td>
              <td style="font-family:monospace">${WMS.esc(a.referencia||'-')}</td>
              <td>${a.tipo_ajuste}</td>
              <td>${a.cantidad_fisica}</td><td>${a.cantidad_sistema}</td>
              <td style="color:${a.diferencia>0?'#16a34a':'#dc2626'};font-weight:700">${a.diferencia>0?'+':''}${a.diferencia}</td>
              <td>${WMS.esc(a.ubicacion||'-')}</td>
              <td>${WMS.esc(a.ajustado_por||'-')}</td>
            </tr>`).join('')}
          </tbody>
        </table>`:'' }

        <p style="color:#94a3b8;font-size:9px;margin-top:20px">Generado: ${new Date().toLocaleString()} — WMS Fénix</p>
        <script>window.print()</script>
        </body></html>`;
      const w = window.open('', '_blank', 'width=900,height=700');
      w.document.write(html);
      w.document.close();
    } catch(e) { WMS.toast('error', 'Error generando informe'); }
  },

  // ── REPORTE DE AJUSTES ────────────────────────────────────────────────────
  async showAjustesReport(sesionId, desde, hasta) {
    WMS.spinner();
    let qParts = [];
    if (sesionId) qParts.push(`sesion_id=${sesionId}`);
    const d30ago = new Date(); d30ago.setDate(d30ago.getDate()-30);
    const desdeVal = desde || d30ago.toISOString().slice(0,10);
    const hastaVal = hasta || new Date().toISOString().slice(0,10);
    if (!sesionId) {
      qParts.push(`desde=${desdeVal}`);
      qParts.push(`hasta=${hastaVal}`);
    }
    const q = qParts.join('&');
    try {
      const r = await API.get('/v2/inventario/ajustes', q);
      const items = r.data || [];
      WMS.setContent(`
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
          <h3 style="margin:0;font-size:1rem;font-weight:800">
            <i class="fa-solid fa-receipt" style="color:#f59e0b"></i>
            Registro de Ajustes (${items.length})
          </h3>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            ${!sesionId ? `
            <input type="date" id="aj-rep-desde" value="${desdeVal}" class="form-control" style="width:145px">
            <input type="date" id="aj-rep-hasta" value="${hastaVal}" class="form-control" style="width:145px">
            <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.inventario.showAjustesReport(null,document.getElementById('aj-rep-desde').value,document.getElementById('aj-rep-hasta').value)">
              <i class="fa-solid fa-search"></i> Filtrar
            </button>` : ''}
            <button class="btn btn-sm btn-success" onclick="WMS_MODULES.inventario._exportAjustes('${q}')">
              <i class="fa-solid fa-file-excel"></i> Exportar
            </button>
          </div>
        </div>
        <div class="card">
          <div class="table-container" style="max-height:calc(100vh - 220px);overflow-y:auto;">
            <table class="data-table compact" id="ajustes-tbl">
              <thead style="position:sticky;top:0;background:#f8fafc;z-index:10">
                <tr>
                  <th>Fecha</th><th>Hora</th><th>Referencia</th><th>Producto</th>
                  <th>Tipo</th><th class="text-center">Físico</th><th class="text-center">Sistema</th>
                  <th class="text-center">Diferencia</th><th>F. Vencimiento</th>
                  <th>Ubicación</th><th>Auxiliar (contó)</th><th>Ajustado por</th>
                  <th>Motivo</th><th>Origen</th>
                </tr>
              </thead>
              <tbody>
                ${items.map(a => `<tr>
                  <td style="font-size:.75rem">${WMS.formatDate(a.fecha)||'-'}</td>
                  <td style="font-size:.75rem">${a.hora||'-'}</td>
                  <td style="font-family:monospace;font-size:.78rem">${WMS.esc(a.referencia||'-')}</td>
                  <td style="font-weight:700;font-size:.8rem">${WMS.esc(a.producto||'-')}</td>
                  <td>
                    <span style="padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:700;
                      background:${a.tipo_ajuste==='Entrada'?'#dcfce7':'#fee2e2'};
                      color:${a.tipo_ajuste==='Entrada'?'#16a34a':'#dc2626'}">
                      ${a.tipo_ajuste==='Entrada'?'▲ Entrada':'▼ Salida'}
                    </span>
                  </td>
                  <td class="text-center fw-700">${a.fisico}</td>
                  <td class="text-center">${a.sistema}</td>
                  <td class="text-center ${a.dif>0?'dif-plus':'dif-minus'}">
                    ${a.dif>0?'+':''}${a.dif}
                  </td>
                  <td style="font-size:.75rem">${WMS.formatDate(a.fecha_vencimiento)||'-'}</td>
                  <td><span class="badge badge-light-blue">${WMS.esc(a.ubicacion||'-')}</span></td>
                  <td style="font-size:.78rem">${WMS.esc(a.auxiliar||'-')}</td>
                  <td style="font-size:.78rem;font-weight:600">${WMS.esc(a.ajustado_por||'-')}</td>
                  <td style="font-size:.75rem;max-width:160px" title="${WMS.esc(a.motivo||'')}">${WMS.esc((a.motivo||'').substring(0,50))}${(a.motivo||'').length>50?'...':''}</td>
                  <td><span class="badge badge-secondary" style="font-size:.68rem">${a.origen||'-'}</span></td>
                </tr>`).join('') || '<tr><td colspan="14" class="table-empty">Sin ajustes registrados</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><p>Error cargando ajustes</p></div>'); }
  },

  _exportAjustes(extraQ) {
    const token = localStorage.getItem('wms_token') || '';
    const base = extraQ ? `${extraQ}&export=excel` : 'export=excel';
    window.open(`${API_BASE}/v2/inventario/ajustes?${base}&token=${encodeURIComponent(token)}`, '_blank');
  },

  // Compatibilidad legacy
  async verAdministracion(id) { this.verDashboardV2(id); },
  async aprobarAjustes(id) { this._ajustarTodo(id); },

  switchInvTab(tab) {}, // obsoleto, reemplazado por _tab2
  async cerrarConteoMasivo(id) {
    if (!confirm('¿Desea forzar el cierre técnico del conteo sin aplicar ajustes automáticos?')) return;
    this.cerrarConteo(id);
  },

  async verConteo(id) { this.verDashboardV2(id); },

  async cerrarConteo(id) {
    if (!confirm('¿Cerrar este conteo? No se podrán agregar más líneas.')) return;
    try {
      const r = await API.post('/inventario/conteo/' + id + '/finalizar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Conteo cerrado'); WMS.closeModal('generic-modal'); this.show_ciclico(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── INVENTARIO GENERAL V2 (Reemplazado por Gestión Unificada) ──────────────────
  async show_general() { this.show_sesiones(); },

  async show_sesiones() {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario.nuevoConteo('Ciclico')">
        <i class="fa-solid fa-rotate"></i> Nuevo Cíclico
      </button>
      <button class="btn btn-primary btn-sm" style="margin-left:8px;background-color:#6d28d9;border-color:#6d28d9" onclick="WMS_MODULES.inventario.nuevoConteo('General')">
        <i class="fa-solid fa-list-ol"></i> Nuevo General
      </button>
      <button class="btn btn-secondary btn-sm" style="margin-left:8px" onclick="WMS_MODULES.inventario.show_sesiones()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>`);
    WMS.spinner();
    try {
      const r = await API.get('/v2/inventario/sesiones');
      let sesiones = r.data || r || [];
      if (!Array.isArray(sesiones) && sesiones.data) sesiones = sesiones.data;

      const estadoColors = {
        Borrador: '#94a3b8', EnCurso: '#3b82f6', PendienteAjuste: '#f97316',
        Ajustado: '#8b5cf6', Cerrado: '#10b981'
      };
      const estadoLabel = {
        Borrador: 'Borrador', EnCurso: 'En Curso', PendienteAjuste: 'Pend. Ajuste',
        Ajustado: 'Ajustado', Cerrado: 'Cerrado'
      };

      WMS.setContent(`
      <style>
        .gen-estado-chip { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.7rem; font-weight:700; }
        .tipo-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:6px; font-size:.65rem; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; }
        .tipo-ciclico { background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; }
        .tipo-general { background:#f5f3ff; color:#7c3aed; border:1px solid #ddd6fe; }
        .tipo-cargue  { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
      </style>
      <div class="filter-bar">
        <div class="search-bar"><i class="fa-solid fa-search"></i>
          <input placeholder="Buscar nombre, creador..." oninput="WMS_MODULES.inventario._filterSesiones()">
        </div>
        <select id="ses-tipo-sel" class="form-control" style="max-width:180px;" onchange="WMS_MODULES.inventario._filterSesiones()">
          <option value="">Todos los Tipos</option>
          <option value="Ciclico">Cíclico</option>
          <option value="General">General</option>
          <option value="CargueInicial">Cargue Inicial</option>
        </select>
        <select id="ses-estado-sel" class="form-control" style="max-width:180px;" onchange="WMS_MODULES.inventario._filterSesiones()">
          <option value="">Todos los Estados</option>
          <option value="Borrador">Borrador</option>
          <option value="EnCurso">En Curso</option>
          <option value="PendienteAjuste">Pendiente Ajuste</option>
          <option value="Ajustado">Ajustado</option>
          <option value="Cerrado">Cerrado</option>
        </select>
      </div>
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Gestión de Conteos (${sesiones.length})</span>
        </div>
        <div class="table-container">
          <table class="erp-table" id="sesiones-v2-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Nombre / Descripción</th>
                <th class="text-center">Estado</th>
                <th class="text-center">Rondas</th>
                <th class="text-center">Asign.</th>
                <th class="text-center">Líneas</th>
                <th class="text-center">Ajustes</th>
                <th>Creado por</th>
                <th>Fecha</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody id="sesiones-v2-tbody">
              ${sesiones.length ? sesiones.map(s => {
                const color = estadoColors[s.estado] || '#64748b';
                const rondas = [];
                for (let i = 1; i <= (s.num_conteos||1); i++) rondas.push(i);
                
                const tipoBadge = s.tipo === 'Ciclico'
                   ? '<span class="tipo-chip tipo-ciclico"><i class="fa-solid fa-rotate"></i> Cíclico</span>'
                   : s.tipo === 'CargueInicial'
                   ? '<span class="tipo-chip tipo-cargue"><i class="fa-solid fa-box-archive"></i> Cargue Inicial</span>'
                   : '<span class="tipo-chip tipo-general"><i class="fa-solid fa-list-ol"></i> General</span>';
                   
                return `<tr data-estado="${s.estado}" data-tipo="${s.tipo}" data-nombre="${(s.nombre||'').toLowerCase()} ${(s.creado_por?.nombre||s.creado_por_nombre||'').toLowerCase()}">
                  <td><span class="badge badge-secondary">#${s.id}</span></td>
                  <td>${tipoBadge}</td>
                  <td>
                    <div style="font-weight:700;color:#0f172a">${WMS.esc(s.nombre||'Sin nombre')}</div>
                    ${s.descripcion ? `<div style="font-size:.70rem;color:#64748b">${WMS.esc(s.descripcion)}</div>` : ''}
                  </td>
                  <td class="text-center">
                    <span class="gen-estado-chip" style="background:${color}20;color:${color}">
                      ${estadoLabel[s.estado]||s.estado}
                    </span>
                  </td>
                  <td class="text-center">
                    <span style="font-size:.9rem;font-weight:800;color:#0f172a">${s.num_conteos||1}</span>
                  </td>
                  <td class="text-center">${s.asignaciones_count||0}</td>
                  <td class="text-center">${s.lineas_count || 0}</td>
                  <td class="text-center">
                    ${(s.ajustes_count || s.total_ajustes || 0) > 0
                      ? `<span class="badge badge-warning">${s.ajustes_count || s.total_ajustes}</span>`
                      : '<span class="badge badge-secondary">0</span>'}
                  </td>
                  <td style="font-size:.78rem">${WMS.esc(s.creado_por?.nombre || s.creado_por_nombre || '-')}</td>
                  <td style="font-size:.78rem">${WMS.formatDate(s.created_at)||'-'}</td>
                  <td class="text-center" style="white-space:nowrap">
                    ${s.estado === 'Borrador' ? `
                      <button class="btn btn-success btn-sm" style="margin-right:2px"
                        onclick="WMS_MODULES.inventario.iniciarSesion(${s.id})" title="Iniciar">
                        <i class="fa-solid fa-play"></i>
                      </button>` : ''}
                    ${s.tipo === 'Ciclico' && !['Ajustado','Cerrado'].includes(s.estado) ? `
                      <button class="btn btn-warning btn-sm" style="margin-right:2px"
                        onclick="WMS_MODULES.inventario._ciclicoRefs(${s.id})" title="Gestionar referencias a contar">
                        <i class="fa-solid fa-tags"></i> Refs
                      </button>` : ''}
                    <button class="btn btn-primary btn-sm" style="margin-right:2px"
                      onclick="WMS_MODULES.inventario._openDashboardInTab(${s.id},1)" title="Dashboard Detalles">
                      <i class="fa-solid fa-chart-bar"></i>
                    </button>
                    ${(s.num_conteos||1) > 1 ? rondas.map(rnd =>
                      `<button class="btn btn-xs btn-outline-secondary" style="margin-right:2px"
                        onclick="WMS_MODULES.inventario._openDashboardInTab(${s.id},${rnd})" title="Ver Ronda ${rnd}">
                        R${rnd}
                      </button>`).join('') : ''}
                    ${!['Ajustado','Cerrado'].includes(s.estado) ? `
                    <button class="btn btn-danger btn-sm" style="margin-left:4px"
                      onclick="WMS_MODULES.inventario._eliminarSesion(${s.id},'${WMS.esc(s.nombre||'')}')"
                      title="Eliminar sesión">
                      <i class="fa-solid fa-trash"></i>
                    </button>` : ''}
                  </td>
                </tr>`;
              }).join('') : '<tr><td colspan="11" class="table-empty"><i class="fa-solid fa-inbox"></i> No hay sesiones de conteo registradas.</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
    } catch(e) { if (e.isSessionExpired) return; console.error(e); WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  _filterSesiones() {
    const term = (document.querySelector('.search-bar input')?.value || '').toLowerCase();
    const est  = document.getElementById('ses-estado-sel')?.value || '';
    const tipo = document.getElementById('ses-tipo-sel')?.value || '';
    document.querySelectorAll('#sesiones-v2-tbody tr').forEach(row => {
      const matchQ = !term || row.dataset.nombre?.includes(term);
      const matchE = !est  || row.dataset.estado === est;
      const matchT = !tipo || row.dataset.tipo === tipo;
      row.style.display = (matchQ && matchE && matchT) ? '' : 'none';
    });
  },

  filterPivotTable(q, tableId) {
    const rows = document.querySelectorAll('#' + tableId + ' tbody tr.pivot-row');
    const f = q.toLowerCase();
    rows.forEach(r => { 
       const isMatch = r.textContent.toLowerCase().includes(f);
       r.style.display = isMatch ? '' : 'none'; 
       const btn = r.querySelector('button');
       if(!isMatch) {
          const detail = r.nextElementSibling;
          if(detail && detail.classList.contains('pivot-detail-row')) {
              detail.style.display = 'none';
              if(btn) btn.innerHTML = '<i class="fa-solid fa-chevron-down"></i>';
          }
       }
    });
  },

  // ── CARGUE INICIAL DE INVENTARIO ──────────────────────────────
  _cargueProds: [],
  _cargueUbis: [],
  _ciProd: null,
  _ciUbicId: null,

  async show_cargue() {
    this._ciProd   = null;
    this._ciUbicId = null;
    const esAdmin  = ['Admin','Supervisor','SuperAdmin'].includes(WMS.user?.rol || '');
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario.show_cargue()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      ${esAdmin ? `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.inventario._cargueAprobarTodo()" id="btn-aprobar-todo" style="display:none;"><i class="fa-solid fa-check-double"></i> Aprobar Todo</button>` : ''}
    `);

    WMS.spinner();
    try {
      const [prods, ubis, pends] = await Promise.all([
        API.get('/param/productos', 'limit=200').catch(() => ({ data: [] })),
        API.get('/param/ubicaciones', 'activo=all').catch(() => ({ data: [] })),
        API.get('/inventario/cargue-inicial/pendientes').catch(() => ({ data: { lineas: [] } })),
      ]);
      this._cargueProds = prods.data || prods || [];
      this._cargueUbis  = ubis.data  || ubis  || [];
      const pendientes  = pends.data?.lineas || [];

      WMS.setContent(this._ciRenderLayout(esAdmin, pendientes));
      // Cargar "Mis Conteos" después de renderizar el DOM
      await this._ciRefrescarPendientes();
    } catch(e) {
      WMS.toast('error', 'Error cargando datos: ' + (e.message || ''));
    }
  },

  _ciRenderLayout(esAdmin, pendientes = []) {
    const pRows = pendientes.map(l => {
      const sinId = !l.ubicacion_id;
      const fv    = l.fecha_vencimiento ? l.fecha_vencimiento.split('T')[0] : '—';
      return `<tr class="${sinId ? 'row-warning' : ''}">
        <td><strong>${WMS.esc(l.producto||'')}</strong><br><small style="color:#6b7280;">${WMS.esc(l.codigo||'')}</small></td>
        <td>
          <span class="badge ${sinId?'badge-warning':'badge-gray'}" title="${sinId?'Código no encontrado en el sistema':''}">${WMS.esc(l.ubicacion_codigo||'')}</span>
          ${sinId ? '<br><small style="color:#b45309;"><i class="fa-solid fa-triangle-exclamation"></i> Sin ID</small>' : ''}
        </td>
        <td style="font-size:.8rem;">${l.lote||'—'}</td>
        <td style="font-size:.8rem;">${fv}</td>
        <td style="text-align:right;">${l.cantidad_cajas}</td>
        <td style="text-align:right;">${l.saldos}</td>
        <td style="text-align:right;font-weight:700;color:${parseFloat(l.und_total)<=0?'#dc2626':'#1e40af'};">
          ${parseFloat(l.und_total)<=0 ? '<span class="badge" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;">VACIAR</span>' : parseFloat(l.und_total).toFixed(2)}
        </td>
        <td><div style="display:flex;gap:4px;">
          ${esAdmin && !sinId ? `<button class="btn btn-sm btn-success" onclick="WMS_MODULES.inventario._ciAprobarLinea(${l.id})" title="${parseFloat(l.und_total)<=0?'Aprobar vaciado':'Aprobar'}"><i class="fa-solid fa-check"></i></button>` : ''}
          <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.inventario._ciEliminarPend(${l.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
        </div></td>
      </tr>`;
    }).join('');

    return `
      <div style="display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;">

        <!-- Formulario izquierdo -->
        <div class="card" style="position:sticky;top:70px;">
          <div class="card-header" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;">
            <span class="card-title"><i class="fa-solid fa-box-archive"></i> Agregar Producto</span>
          </div>
          <div class="card-body" style="padding:14px;">

            <!-- Producto -->
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-weight:600;">Producto <span style="color:#dc2626;">*</span></label>
              <div style="position:relative;">
                <input id="ci-prod-input" class="form-control" placeholder="Buscar nombre, código o EAN..."
                  autocomplete="off" oninput="WMS_MODULES.inventario._ciSearchProd(this.value)">
                <div id="ci-prod-dd" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;
                  border:1px solid #d1d5db;border-radius:4px;max-height:240px;overflow-y:auto;
                  box-shadow:0 4px 12px rgba(0,0,0,.15);top:100%;left:0;"></div>
              </div>
              <div id="ci-prod-info" style="display:none;margin-top:6px;background:#f0fdf4;
                border:1px solid #bbf7d0;border-radius:4px;padding:7px 10px;font-size:.8rem;"></div>
            </div>

            <!-- Ubicación (texto + autocompletado) -->
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" style="font-weight:600;">Código de Ubicación <span style="color:#dc2626;">*</span></label>
              <div style="position:relative;">
                <input id="ci-ubic-input" class="form-control" placeholder="Ej: A-01-01 o CONG/01-01-01..."
                  autocomplete="off" oninput="WMS_MODULES.inventario._ciSearchUbic(this.value)"
                  style="text-transform:uppercase;">
                <div id="ci-ubic-dd" style="display:none;position:absolute;z-index:998;width:100%;background:#fff;
                  border:1px solid #d1d5db;border-radius:4px;max-height:180px;overflow-y:auto;
                  box-shadow:0 4px 8px rgba(0,0,0,.1);top:100%;left:0;"></div>
              </div>
              <div id="ci-ubic-info" style="font-size:.75rem;margin-top:4px;"></div>
            </div>

            <!-- Lote -->
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label">Lote <span style="color:#9ca3af;font-size:.75rem;">(opcional)</span></label>
              <input id="ci-lote" class="form-control" placeholder="Número de lote...">
            </div>

            <!-- Fecha vencimiento — SIEMPRE VISIBLE -->
            <div class="form-group" style="margin-bottom:10px;">
              <label class="form-label" id="ci-fvenc-label">
                <i class="fa-solid fa-calendar-days"></i> Fecha de Vencimiento
              </label>
              <input id="ci-fvenc" type="date" class="form-control">
              <span id="ci-fvenc-hint" style="font-size:.73rem;color:#6b7280;display:none;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Requerida para este producto
              </span>
            </div>

            <!-- Cajas + Saldos -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
              <div class="form-group">
                <label class="form-label" style="font-weight:600;">Cajas</label>
                <input id="ci-cajas" type="number" class="form-control" value="0" min="0" step="1"
                  oninput="WMS_MODULES.inventario._ciCalcPreview()">
              </div>
              <div class="form-group">
                <label class="form-label" style="font-weight:600;">Sueltos</label>
                <input id="ci-saldos" type="number" class="form-control" value="0" min="0" step="0.001"
                  oninput="WMS_MODULES.inventario._ciCalcPreview()">
              </div>
            </div>

            <!-- Preview UND/TOTAL -->
            <div id="ci-preview" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;
              padding:9px 12px;margin-bottom:12px;font-size:.84rem;display:none;"></div>

            <button class="btn btn-primary" style="width:100%;" onclick="WMS_MODULES.inventario._ciEnviar()">
              <i class="fa-solid fa-paper-plane"></i> Enviar a Pendientes
            </button>

            <hr style="margin:14px 0 10px;border-color:#e2e8f0;">
            <div style="font-size:.75rem;font-weight:600;color:#64748b;margin-bottom:6px;">
              <i class="fa-solid fa-box-open"></i> VACIAR UBICACIÓN
            </div>
            <div style="display:flex;gap:6px;">
              <input id="ci-vaciar-ubic" class="form-control" placeholder="Código ubicación..." style="font-size:.82rem;text-transform:uppercase;">
              <button class="btn btn-danger" style="white-space:nowrap;padding:0 12px;" onclick="WMS_MODULES.inventario._ciVaciarUbicacion()" title="Vaciar toda la ubicación">
                <i class="fa-solid fa-eraser"></i>
              </button>
            </div>
            <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">
              Ajusta a 0 todo el inventario de esa ubicación y registra el movimiento.
            </div>

          </div>
        </div>

        <!-- Panel derecho: Mis Conteos + (admin) Pendientes de aprobación -->
        <div style="display:flex;flex-direction:column;gap:16px;">

          <!-- MIS CONTEOS — siempre visible, filtrado por usuario actual -->
          <div class="card">
            <div class="card-header" style="background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;">
              <span class="card-title"><i class="fa-solid fa-list-check"></i> Mis Conteos
                <span id="ci-mio-badge" class="badge" style="margin-left:6px;background:rgba(255,255,255,.25);color:#fff;">0</span>
              </span>
            </div>
            <div style="background:#f0fdfa;border-bottom:1px solid #99f6e4;padding:6px 14px;font-size:.76rem;color:#0f766e;">
              <i class="fa-solid fa-user-check"></i> Solo tus registros · orden más reciente primero
            </div>
            <div style="overflow-x:auto;">
              <table class="erp-table">
                <thead><tr>
                  <th>Referencia</th>
                  <th>Ubicación</th>
                  <th style="text-align:right;">Cajas</th>
                  <th style="text-align:right;">Sueltos</th>
                  <th style="text-align:right;">UND/TOTAL</th>
                  <th>Acción</th>
                </tr></thead>
                <tbody id="ci-mio-tbody">
                  <tr><td colspan="6" class="table-empty" style="text-align:center;padding:20px;color:#94a3b8;">
                    <i class="fa-solid fa-inbox"></i> Sin conteos registrados</td></tr>
                </tbody>
              </table>
            </div>
            <div id="ci-mio-resumen" style="padding:8px 14px;background:#f0fdfa;border-top:1px solid #99f6e4;font-size:.8rem;display:none;"></div>
          </div>

          <!-- PENDIENTES DE APROBACIÓN — solo admin/supervisor -->
          ${esAdmin ? `
          <div class="card">
            <div class="card-header">
              <span class="card-title">
                <i class="fa-solid fa-hourglass-half" style="color:#f59e0b;"></i>
                Pendientes de Aprobación
                <span id="ci-pend-badge" class="badge badge-warning" style="margin-left:6px;">${pendientes.length}</span>
              </span>
              ${pendientes.length > 0 ? `<button id="btn-aprobar-todo" class="btn btn-sm btn-success" style="margin-left:auto;" onclick="WMS_MODULES.inventario._cargueAprobarTodo()"><i class="fa-solid fa-check-double"></i> Aprobar Todo</button>` : ''}
            </div>
            <div style="background:#fef9c3;border-bottom:1px solid #fde047;padding:7px 14px;font-size:.78rem;color:#854d0e;">
              <i class="fa-solid fa-shield-halved"></i> <b>Admin:</b> Revise y apruebe para registrar en inventario como <b>Inv Inicial</b>.
            </div>
            <div style="overflow-x:auto;">
              <table class="erp-table">
                <thead><tr>
                  <th>Producto</th><th>Ubicación</th><th>Lote</th><th>F.Venc.</th>
                  <th style="text-align:right;">Cajas</th><th style="text-align:right;">Sueltos</th>
                  <th style="text-align:right;">UND/TOTAL</th><th>Acción</th>
                </tr></thead>
                <tbody id="ci-pend-tbody">
                  ${pRows || `<tr><td colspan="8" class="table-empty" style="text-align:center;padding:20px;color:#94a3b8;">
                    <i class="fa-solid fa-inbox"></i> No hay líneas pendientes</td></tr>`}
                </tbody>
              </table>
            </div>
            <div id="ci-pend-resumen" style="padding:8px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:.82rem;${pendientes.length?'':'display:none;'}">
              ${pendientes.length} línea(s) · UND/TOTAL: <b style="color:#1e40af;">${pendientes.reduce((s,l)=>s+parseFloat(l.und_total||0),0).toFixed(2)}</b>
            </div>
          </div>` : ''}

        </div><!-- fin panel derecho -->

      </div>`;
  },

  _ciSearchProd(q) {
    const dd = document.getElementById('ci-prod-dd');
    if (!dd) return;
    if (!q || q.length < 1) { dd.style.display = 'none'; return; }
    clearTimeout(this._ciTimer);
    this._ciTimer = setTimeout(() => {
      const ql = q.toLowerCase();
      const matches = this._cargueProds.filter(p =>
        (p.nombre||'').toLowerCase().includes(ql) ||
        (p.codigo_interno||'').toLowerCase().includes(ql) ||
        (p.ean||'').toLowerCase().includes(ql)
      ).slice(0, 30);
      if (!matches.length) { dd.innerHTML='<div style="padding:10px;color:#94a3b8;font-size:.82rem;">Sin resultados</div>'; dd.style.display='block'; return; }
      dd.innerHTML = `<div style="padding:5px 10px;font-size:.72rem;color:#9ca3af;border-bottom:1px solid #f0f0f0;">${matches.length} resultado(s)</div>` +
        matches.map(p => {
          const upc = p.unidades_caja > 1 ? ` · ${p.unidades_caja} u/caja` : '';
          const cv  = p.control_vencimientos ? ' <span style="color:#dc2626;font-size:.68rem;">📅 venc.</span>' : '';
          return `<div style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f5f5f5;font-size:.83rem;"
            onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''"
            onclick="WMS_MODULES.inventario._ciSelProd(${p.id})">
            <div style="font-weight:600;">${WMS.esc(p.nombre)}${cv}</div>
            <div style="color:#6b7280;font-size:.75rem;">${WMS.esc(p.codigo_interno||'')}${upc}</div>
          </div>`;
        }).join('');
      dd.style.display = 'block';
    }, 150);
  },

  _ciSelProd(id) {
    const p = this._cargueProds.find(x => x.id === id);
    if (!p) return;
    this._ciProd = p;
    const inp  = document.getElementById('ci-prod-input');
    const dd   = document.getElementById('ci-prod-dd');
    const info = document.getElementById('ci-prod-info');
    const lbl  = document.getElementById('ci-fvenc-label');
    const hint = document.getElementById('ci-fvenc-hint');
    const fv   = document.getElementById('ci-fvenc');
    if (inp) inp.value = p.nombre;
    if (dd)  dd.style.display = 'none';
    const upc = Math.max(1, parseInt(p.unidades_caja)||1);
    if (info) {
      info.style.display = 'block';
      info.innerHTML = `<b>${WMS.esc(p.nombre)}</b> · ${upc} u/caja · ${WMS.esc(p.unidad_medida||'UN')}` +
        (p.control_vencimientos ? ' · <span style="color:#dc2626;font-weight:700;">📅 Requiere fecha vencimiento</span>' : '');
    }
    // Fecha vencimiento: siempre visible, resaltar si es requerida
    if (lbl) {
      lbl.style.color = p.control_vencimientos ? '#dc2626' : '';
      lbl.style.fontWeight = p.control_vencimientos ? '700' : '';
      lbl.innerHTML = p.control_vencimientos
        ? '<i class="fa-solid fa-calendar-xmark"></i> Fecha de Vencimiento <span style="color:#dc2626;">*</span>'
        : '<i class="fa-solid fa-calendar-days"></i> Fecha de Vencimiento';
    }
    if (hint) hint.style.display = p.control_vencimientos ? 'block' : 'none';
    if (fv)   fv.style.borderColor = p.control_vencimientos ? '#dc2626' : '';
    this._ciCalcPreview();
  },

  // Normaliza código: quita guiones, barras, espacios → UPPER. "seco/01-02-01" → "SECO010201"
  _ubicNorm(s) { return (s || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase(); },

  _ciSearchUbic(q) {
    const dd   = document.getElementById('ci-ubic-dd');
    const info = document.getElementById('ci-ubic-info');
    this._ciUbicId = null;
    if (info) info.innerHTML = '';
    if (!dd) return;
    const norm = this._ubicNorm(q);
    if (!norm || norm.length < 2) { dd.style.display = 'none'; return; }
    clearTimeout(this._ciUbicTimer);
    this._ciUbicTimer = setTimeout(() => {
      const scored = [];
      for (const u of this._cargueUbis) {
        const un = this._ubicNorm(u.codigo);
        if      (un === norm)           scored.push({ u, score: 3 }); // exacto normalizado
        else if (un.endsWith(norm))     scored.push({ u, score: 2 }); // sin ambiente (ej: "010201" → "SECO010201")
        else if (un.includes(norm))     scored.push({ u, score: 1 }); // parcial
      }
      scored.sort((a, b) => b.score - a.score || (a.u.codigo||'').localeCompare(b.u.codigo||''));

      // Auto-selección si hay exactamente 1 match exacto normalizado
      const exactos = scored.filter(m => m.score === 3);
      if (exactos.length === 1) { this._ciSelUbic(exactos[0].u.id, exactos[0].u.codigo); dd.style.display='none'; return; }

      if (!scored.length) {
        dd.innerHTML = `<div style="padding:8px 12px;font-size:.8rem;color:#9ca3af;"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;margin-right:4px;"></i>Sin coincidencias — se enviará el código tal cual</div>`;
        dd.style.display = 'block'; return;
      }
      dd.innerHTML = scored.slice(0, 20).map(({ u, score }) =>
        `<div style="padding:8px 12px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;"
          onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''"
          onclick="WMS_MODULES.inventario._ciSelUbic(${u.id},'${WMS.esc(u.codigo||'')}')">
          <span>
            <b>${WMS.esc(u.codigo||'')}</b>
            <span style="color:#6b7280;font-size:.73rem;margin-left:6px;">${WMS.esc(u.zona||'')} · ${WMS.esc(u.tipo_ubicacion||'')}</span>
          </span>
          ${score===2 ? '<span style="font-size:.65rem;background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:4px;">sin ambiente</span>'
          : score===1 ? '<span style="font-size:.65rem;background:#f1f5f9;color:#64748b;padding:1px 5px;border-radius:4px;">parcial</span>' : ''}
        </div>`
      ).join('');
      dd.style.display = 'block';
    }, 180);
  },

  _ciSelUbic(id, codigo) {
    this._ciUbicId = id;
    const inp  = document.getElementById('ci-ubic-input');
    const dd   = document.getElementById('ci-ubic-dd');
    const info = document.getElementById('ci-ubic-info');
    if (inp)  inp.value = codigo;
    if (dd)   dd.style.display = 'none';
    if (info) info.innerHTML = `<span style="color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Ubicación validada</span>`;
  },

  _ciCalcPreview() {
    const preview = document.getElementById('ci-preview');
    const p = this._ciProd;
    if (!preview || !p) { if (preview) preview.style.display='none'; return; }
    const upc    = Math.max(1, parseInt(p.unidades_caja)||1);
    const cajas  = parseFloat(document.getElementById('ci-cajas')?.value||'0')||0;
    const saldos = parseFloat(document.getElementById('ci-saldos')?.value||'0')||0;
    const total  = cajas * upc + saldos;
    preview.style.display = 'block';
    preview.innerHTML = upc > 1
      ? `<b>UND/TOTAL:</b> ${cajas} cajas × ${upc} u/caja + ${saldos} sueltos = <b style="color:#1e40af;font-size:1.05em;">${total.toFixed(3)}</b>`
      : `<b>UND/TOTAL:</b> ${cajas} + ${saldos} sueltos = <b style="color:#1e40af;font-size:1.05em;">${total.toFixed(3)}</b>`;
  },

  async _ciEnviar() {
    const p = this._ciProd;
    if (!p) { WMS.toast('warning','Seleccione un producto'); return; }
    const ubicInput = document.getElementById('ci-ubic-input')?.value?.trim();
    if (!ubicInput) { WMS.toast('warning','Ingrese el código de ubicación'); return; }
    const cajas  = parseFloat(document.getElementById('ci-cajas')?.value||'0')||0;
    const saldos = parseFloat(document.getElementById('ci-saldos')?.value||'0')||0;
    const upc    = Math.max(1, parseInt(p.unidades_caja)||1);
    const esVaciado = (cajas * upc + saldos) <= 0;
    if (esVaciado && !confirm(`¿Registrar "${p.nombre}" en esta ubicación con cantidad CERO?\n\nEsto generará un ajuste negativo si había inventario previo.`)) return;
    const fvenc  = document.getElementById('ci-fvenc')?.value || null;
    if (p.control_vencimientos && !fvenc) {
      WMS.toast('warning', `"${p.nombre}" requiere fecha de vencimiento`); return;
    }
    const lote = document.getElementById('ci-lote')?.value?.trim() || null;
    try {
      const r = await API.post('/inventario/cargue-inicial/linea', {
        producto_id:      p.id,
        ubicacion_codigo: ubicInput.toUpperCase(),
        lote,
        fecha_vencimiento: fvenc || null,
        cantidad_cajas:   Math.floor(cajas),
        saldos,
      });
      const adv = r.data?.advertencia;
      WMS.toast(adv ? 'warning' : 'success',
        adv ? `Línea guardada — ADVERTENCIA: ${adv}` : `"${p.nombre}" enviado a pendientes`);
      // Reset
      ['ci-cajas','ci-saldos','ci-lote'].forEach(id => { const el=document.getElementById(id); if(el) el.value = id==='ci-cajas'||id==='ci-saldos'?'0':''; });
      document.getElementById('ci-fvenc').value = '';
      document.getElementById('ci-prod-input').value = '';
      document.getElementById('ci-ubic-input').value = '';
      document.getElementById('ci-prod-info').style.display = 'none';
      document.getElementById('ci-preview').style.display   = 'none';
      document.getElementById('ci-ubic-dd').style.display   = 'none';
      document.getElementById('ci-ubic-info').innerHTML     = '';
      this._ciProd   = null;
      this._ciUbicId = null;
      // Refrescar tabla de pendientes
      await this._ciRefrescarPendientes();
    } catch(e) { WMS.toast('error', e.message || 'Error enviando línea'); }
  },

  async _ciRefrescarPendientes() {
    const esAdmin = ['Admin','Supervisor','SuperAdmin'].includes(WMS.user?.rol||'');
    try {
      // Panel "Mis Conteos" — siempre filtrado por usuario actual, más reciente primero
      const rMio = await API.get('/inventario/cargue-inicial/pendientes', 'mio=1');
      const mios  = rMio.data?.lineas || [];
      const tbMio = document.getElementById('ci-mio-tbody');
      const bdMio = document.getElementById('ci-mio-badge');
      const resMio= document.getElementById('ci-mio-resumen');
      if (bdMio) bdMio.textContent = mios.length;
      if (tbMio) {
        if (!mios.length) {
          tbMio.innerHTML = `<tr><td colspan="6" class="table-empty" style="text-align:center;padding:20px;color:#94a3b8;">
            <i class="fa-solid fa-inbox"></i> Aún no has registrado conteos</td></tr>`;
          if (resMio) resMio.style.display = 'none';
        } else {
          tbMio.innerHTML = mios.map(l => {
            const sinId   = !l.ubicacion_id;
            const esVac   = parseFloat(l.und_total) <= 0;
            const ubic    = WMS.esc(l.ubicacion_codigo || '—');
            const ubicBadge = sinId
              ? `<span class="badge badge-warning" title="Ubicación no encontrada">${ubic}</span> <i class="fa-solid fa-triangle-exclamation" style="color:#b45309;font-size:.7rem;"></i>`
              : `<span class="badge badge-gray">${ubic}</span>`;
            const cantCell = esVac
              ? `<span class="badge" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;font-size:.75rem;">VACIAR</span>`
              : `<b style="color:#1e40af;">${parseFloat(l.und_total).toFixed(2)}</b>`;
            return `<tr>
              <td>
                <strong style="font-size:.85rem;">${WMS.esc(l.producto||'')}</strong>
                <br><small style="color:#6b7280;font-family:monospace;">${WMS.esc(l.codigo||'')}</small>
                ${l.lote ? `<br><small style="color:#9ca3af;">Lote: ${WMS.esc(l.lote)}</small>` : ''}
              </td>
              <td>${ubicBadge}</td>
              <td style="text-align:right;font-size:.85rem;">${l.cantidad_cajas}</td>
              <td style="text-align:right;font-size:.85rem;">${l.saldos}</td>
              <td style="text-align:right;">${cantCell}</td>
              <td>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.inventario._ciEliminarPend(${l.id})" title="Eliminar">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>`;
          }).join('');
          const totMio = mios.reduce((s,l) => s + parseFloat(l.und_total||0), 0);
          if (resMio) {
            resMio.style.display = '';
            resMio.innerHTML = `<i class="fa-solid fa-boxes-stacked" style="color:#0f766e;"></i>
              ${mios.length} referencia(s) contada(s) · UND/TOTAL: <b style="color:#0f766e;">${totMio.toFixed(2)}</b>`;
          }
        }
      }
    } catch(e) { console.error('Error cargando mis conteos', e); }

    // Panel "Pendientes de Aprobación" — solo admin
    if (!esAdmin) return;
    try {
      const r       = await API.get('/inventario/cargue-inicial/pendientes');
      const pends   = r.data?.lineas || [];
      const tbody   = document.getElementById('ci-pend-tbody');
      const badge   = document.getElementById('ci-pend-badge');
      const resumen = document.getElementById('ci-pend-resumen');
      const btnTodo = document.getElementById('btn-aprobar-todo');
      if (badge)  badge.textContent = pends.length;
      if (btnTodo) btnTodo.style.display = pends.length > 0 ? '' : 'none';
      if (!tbody) return;
      if (!pends.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="table-empty" style="text-align:center;padding:20px;color:#94a3b8;">
          <i class="fa-solid fa-inbox"></i> No hay líneas pendientes</td></tr>`;
        if (resumen) resumen.style.display = 'none';
        return;
      }
      tbody.innerHTML = pends.map(l => {
        const sinId = !l.ubicacion_id;
        const esVac = parseFloat(l.und_total) <= 0;
        const fv    = l.fecha_vencimiento ? l.fecha_vencimiento.split('T')[0] : '—';
        return `<tr>
          <td>
            <strong>${WMS.esc(l.producto||'')}</strong>
            <br><small style="color:#6b7280;">${WMS.esc(l.codigo||'')}</small>
            <br><small style="color:#94a3b8;font-size:.7rem;"><i class="fa-solid fa-user"></i> ${WMS.esc(l.nombre_usuario||'')}</small>
          </td>
          <td>
            <span class="badge ${sinId?'badge-warning':'badge-gray'}">${WMS.esc(l.ubicacion_codigo||'')}</span>
            ${sinId?'<br><small style="color:#b45309;font-size:.7rem;"><i class="fa-solid fa-triangle-exclamation"></i> Sin ID</small>':''}
          </td>
          <td style="font-size:.8rem;">${l.lote||'—'}</td>
          <td style="font-size:.8rem;">${fv}</td>
          <td style="text-align:right;">${l.cantidad_cajas}</td>
          <td style="text-align:right;">${l.saldos}</td>
          <td style="text-align:right;font-weight:700;color:${esVac?'#dc2626':'#1e40af'};">
            ${esVac ? '<span class="badge" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;">VACIAR</span>' : parseFloat(l.und_total).toFixed(2)}
          </td>
          <td><div style="display:flex;gap:4px;">
            ${!sinId?`<button class="btn btn-sm btn-success" onclick="WMS_MODULES.inventario._ciAprobarLinea(${l.id})" title="${esVac?'Aprobar vaciado':'Aprobar'}"><i class="fa-solid fa-check"></i></button>`:''}
            <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.inventario._ciEliminarPend(${l.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
          </div></td>
        </tr>`;
      }).join('');
      const tot = pends.reduce((s,l) => s + parseFloat(l.und_total||0), 0);
      if (resumen) {
        resumen.style.display = '';
        resumen.innerHTML = `${pends.length} línea(s) · UND/TOTAL total: <b style="color:#1e40af;">${tot.toFixed(2)}</b>`;
      }
    } catch(e) { WMS.toast('error','Error actualizando pendientes'); }
  },

  async _ciVaciarUbicacion() {
    const codigo = document.getElementById('ci-vaciar-ubic')?.value?.trim().toUpperCase();
    if (!codigo) { WMS.toast('warning', 'Ingrese el código de ubicación a vaciar'); return; }
    if (!confirm(`¿Vaciar TODA la ubicación "${codigo}"?\n\nEsto pondrá en CERO todo el inventario que haya en ella y registrará ajustes negativos en el kardex.`)) return;
    WMS.spinner();
    try {
      const r = await API.post('/inventario/vaciar-ubicacion', { ubicacion_codigo: codigo });
      const ajust = r.data?.ajustados ?? 0;
      WMS.toast(ajust > 0 ? 'success' : 'info',
        ajust > 0 ? `Ubicación "${codigo}" vaciada — ${ajust} registro(s) ajustado(s)` : `La ubicación "${codigo}" ya estaba vacía`);
      document.getElementById('ci-vaciar-ubic').value = '';
    } catch(e) { WMS.toast('error', e.message || 'Error vaciando ubicación'); }
    finally { WMS.spinnerHide(); }
  },

  async _ciAprobarLinea(id) {
    try {
      await API.post(`/inventario/cargue-inicial/${id}/aprobar`, {});
      WMS.toast('success','Línea aprobada — inventario actualizado');
      await this._ciRefrescarPendientes();
    } catch(e) { WMS.toast('error', e.message || 'Error aprobando línea'); }
  },

  async _cargueAprobarTodo() {
    const n = document.getElementById('ci-pend-badge')?.textContent || '?';
    if (!confirm(`¿Aprobar las ${n} líneas pendientes?\nTodas quedarán registradas en inventario como "Inv Inicial".`)) return;
    WMS.spinner();
    try {
      const r = await API.post('/inventario/cargue-inicial/aprobar-todo', {});
      const d = r.data || {};
      let msg = `${d.aprobadas} línea(s) aprobadas`;
      if (d.errores?.length) msg += ` · ${d.errores.length} error(es): ${d.errores[0]}`;
      WMS.toast(d.errores?.length ? 'warning' : 'success', msg);
      await this._ciRefrescarPendientes();
    } catch(e) { WMS.toast('error', e.message||'Error aprobando'); }
  },

  async _ciEliminarPend(id) {
    if (!confirm('¿Eliminar esta línea pendiente?')) return;
    try {
      await API.delete(`/inventario/cargue-inicial/${id}`);
      WMS.toast('success','Línea eliminada');
      await this._ciRefrescarPendientes();
    } catch(e) { WMS.toast('error', e.message||'Error eliminando'); }
  },

  async descargarPlantilla() {
    window.open('/WMS_FENIX/public/api/param/import-export/template/saldo_inicial', '_blank');
  },
  async importarSaldos() { this.show_cargue(); },
  async uploadSaldos()   { this.show_cargue(); },

  // ── DASHBOARD INVENTARIO V2 ────────────────────────────────────
  async show_dashboard() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario.show_dashboard()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const [dash, niveles, sesiones, venc] = await Promise.all([
        API.get('/inventario/dashboard').catch(() => ({})),
        API.get('/inventario/niveles-reposicion').catch(() => ({ data: [] })),
        API.get('/v2/inventario/sesiones', 'limit=50').catch(() => ({ data: [] })),
        API.get('/v2/inventario/vencimientos', 'solo_proximos=1').catch(() => ({ data: {} })),
      ]);
      const d    = dash.data || dash || {};
      const nivs = niveles.data || niveles || [];
      let sess = sesiones.data || sesiones || [];
      if (!Array.isArray(sess) && sess.data) sess = sess.data;
      const vres = venc.data?.resumen || {};
      const bajos = Array.isArray(nivs) ? nivs.filter(n => n.stock_actual < (n.nivel_minimo||0)) : [];

      // Sesiones activas (EnCurso o PendienteAjuste)
      const sesActivas = sess.filter(s => ['EnCurso','PendienteAjuste'].includes(s.estado));
      const sesPendAdj = sess.filter(s => s.estado === 'PendienteAjuste');
      const vencidos = vres.VENCIDO || 0;
      const criticos = (vres.CRITICO||0) + (vres.ALERTA||0);

      WMS.setContent(`
      <style>
        .dash-kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px; margin-bottom:20px; }
        .dash-kpi { background:#fff; border-radius:10px; padding:16px 20px; border:1px solid #e2e8f0; border-left:5px solid var(--kc); }
        .dash-kpi .val { font-size:2rem; font-weight:800; color:var(--kc); line-height:1; }
        .dash-kpi .lbl { font-size:.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-top:4px; }
        .dash-kpi .sub { font-size:.7rem; color:#94a3b8; margin-top:2px; }
        .dash-sess-list { list-style:none; margin:0; padding:0; }
        .dash-sess-list li { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #f1f5f9; }
        .dash-sess-list li:last-child { border:none; }
      </style>

      <!-- KPIs Fila 1: Stock e Inventario general -->
      <div class="dash-kpi-row">
        <div class="dash-kpi" style="--kc:#3b82f6">
          <div class="val">${WMS.formatNum(d.referencias_activas||0)}</div>
          <div class="lbl">Referencias en Stock</div>
        </div>
        <div class="dash-kpi" style="--kc:#10b981">
          <div class="val">${sesActivas.length}</div>
          <div class="lbl">Sesiones Activas</div>
          <div class="sub">${sess.filter(s=>s.tipo==='Ciclico'&&s.estado==='EnCurso').length} cíclicas · ${sess.filter(s=>s.tipo==='General'&&s.estado==='EnCurso').length} generales</div>
        </div>
        <div class="dash-kpi" style="--kc:#f97316">
          <div class="val">${sesPendAdj.length}</div>
          <div class="lbl">Pend. Ajuste</div>
          <div class="sub">sesiones sin ajustar</div>
        </div>
        <div class="dash-kpi" style="--kc:#ef4444">
          <div class="val">${bajos.length}</div>
          <div class="lbl">Bajo Nivel Mínimo</div>
          <div class="sub">referencias críticas</div>
        </div>
        <div class="dash-kpi" style="--kc:#eab308">
          <div class="val">${vencidos}</div>
          <div class="lbl">Vencidos</div>
          <div class="sub">${criticos} en alerta</div>
        </div>
        <div class="dash-kpi" style="--kc:#64748b">
          <div class="val">${WMS.formatNum(d.ubicaciones_vacias||0)}</div>
          <div class="lbl">Ubicaciones Vacías</div>
          <div class="sub">sin stock activo</div>
        </div>
        <div class="dash-kpi" style="--kc:#dc2626;cursor:pointer" onclick="document.getElementById('dash-cero-panel').scrollIntoView({behavior:'smooth'})">
          <div class="val">${WMS.formatNum(d.conteos_cero||0)}</div>
          <div class="lbl">Conteos en Cero</div>
          <div class="sub">en sesiones activas</div>
        </div>
      </div>

      <!-- Fila 2: Sesiones activas + Bajo stock -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

        <div class="card" style="margin:0">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-rotate" style="color:#3b82f6"></i> Sesiones en Curso</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.inventario.show_ciclico()" style="font-size:.75rem">Ver todas</button>
          </div>
          <div style="padding:12px 16px;">
            ${sesActivas.length ? `<ul class="dash-sess-list">
              ${sesActivas.slice(0,6).map(s => `<li>
                <div>
                  <span style="font-weight:700;color:#0f172a;font-size:.85rem">${WMS.esc(s.nombre||'Sin nombre')}</span>
                  <span class="badge" style="margin-left:6px;font-size:.65rem;background:${s.tipo==='Ciclico'?'#eff6ff':'#f0fdf4'};color:${s.tipo==='Ciclico'?'#1e40af':'#166534'}">${s.tipo}</span>
                  <div style="font-size:.72rem;color:#64748b">${s.total_lineas||0} líneas · ${s.total_ajustes||0} ajustes</div>
                </div>
                <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._openDashboardInTab(${s.id},1)">
                  <i class="fa-solid fa-chart-bar"></i>
                </button>
              </li>`).join('')}
            </ul>` : '<div style="text-align:center;padding:24px;color:#94a3b8"><i class="fa-solid fa-circle-check" style="font-size:1.5rem"></i><br>Sin sesiones activas</div>'}
          </div>
        </div>

        <div class="card" style="margin:0">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-arrow-down" style="color:#ef4444"></i> Bajo Nivel Mínimo (${bajos.length})</span>
          </div>
          <div class="table-container" style="max-height:260px;overflow-y:auto;">
            <table class="erp-table">
              <thead><tr><th>Producto</th><th class="text-center">Stock</th><th class="text-center">Mínimo</th><th class="text-center">Déficit</th></tr></thead>
              <tbody>${bajos.slice(0,8).map(n => `<tr>
                <td style="font-size:.8rem">${WMS.esc(n.descripcion||n.producto||'-')}</td>
                <td class="text-center"><span class="badge badge-danger">${WMS.formatNum(n.stock_actual||0)}</span></td>
                <td class="text-center" style="font-size:.8rem">${WMS.formatNum(n.nivel_minimo||0)}</td>
                <td class="text-center"><span class="badge badge-warning">${WMS.formatNum((n.nivel_minimo||0)-(n.stock_actual||0))}</span></td>
              </tr>`).join('') || '<tr><td colspan="4" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin referencias críticas</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- Fila 3: Accesos rápidos -->
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
        <button class="btn btn-primary" onclick="WMS_MODULES.inventario.nuevoConteo('Ciclico')">
          <i class="fa-solid fa-plus"></i> Nuevo Conteo Cíclico
        </button>
        <button class="btn btn-primary" onclick="WMS_MODULES.inventario.nuevoConteo('General')">
          <i class="fa-solid fa-plus"></i> Nuevo Inv. General
        </button>
        <button class="btn btn-secondary" onclick="WMS_MODULES.inventario.show_vencimientos()">
          <i class="fa-solid fa-calendar-xmark"></i> Ver Vencimientos
        </button>
        <button class="btn btn-secondary" onclick="WMS_MODULES.inventario.showAjustesReport(null)">
          <i class="fa-solid fa-history"></i> Registro de Ajustes
        </button>
      </div>

      <!-- Fila 4: Ubicaciones contadas en cero en sesiones activas -->
      <div class="card" style="margin:0 0 16px 0;" id="dash-cero-panel">
        <div class="card-header" style="cursor:pointer;background:#fef2f2;border-bottom:1px solid #fecaca;"
             onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
          <span class="card-title" style="color:#dc2626;">
            <i class="fa-solid fa-circle-minus"></i> Ubicaciones Contadas en Cero
          </span>
          <span id="dash-cero-badge" class="badge" style="background:#fee2e2;color:#dc2626;margin-left:8px;">cargando…</span>
        </div>
        <div id="dash-cero-body">
          <div style="display:flex;gap:10px;flex-wrap:wrap;padding:10px 16px;background:#fafafa;border-bottom:1px solid #e2e8f0;align-items:center;">
            <input id="dash-cero-f-aux"  class="form-control" style="max-width:180px;font-size:.82rem;" placeholder="🔍 Auxiliar…"   oninput="WMS_MODULES.inventario._dashFiltrarCero()">
            <input id="dash-cero-f-ubic" class="form-control" style="max-width:160px;font-size:.82rem;" placeholder="🔍 Ubicación…"  oninput="WMS_MODULES.inventario._dashFiltrarCero()">
            <input id="dash-cero-f-ref"  class="form-control" style="max-width:220px;font-size:.82rem;" placeholder="🔍 Referencia…" oninput="WMS_MODULES.inventario._dashFiltrarCero()">
            <button class="btn btn-sm btn-outline-secondary"
              onclick="['dash-cero-f-aux','dash-cero-f-ubic','dash-cero-f-ref'].forEach(id=>{const el=document.getElementById(id);if(el)el.value=''});WMS_MODULES.inventario._dashFiltrarCero()">
              <i class="fa-solid fa-xmark"></i> Limpiar
            </button>
            <span style="font-size:.75rem;color:#94a3b8;margin-left:auto;">
              <i class="fa-solid fa-info-circle"></i> Al aprobar el ajuste de sesión, la ubicación quedará vacía en inventario y kardex.
            </span>
          </div>
          <div class="table-container" style="max-height:340px;overflow-y:auto;">
            <table class="erp-table" id="dash-cero-table">
              <thead><tr>
                <th>Sesión</th><th>Auxiliar</th><th>Ubicación</th><th>Referencia</th>
                <th class="text-center">Stock Sistema</th><th class="text-center">Contado</th>
                <th>Hora Conteo</th><th class="text-center">Ronda</th><th class="text-center">Acción</th>
              </tr></thead>
              <tbody id="dash-cero-tbody">
                <tr><td colspan="9" class="table-empty"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Fila 5: Registros de Conteo con filtros -->
      <div class="card" style="margin:0">
        <div class="card-header" style="cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
          <span class="card-title"><i class="fa-solid fa-clipboard-list" style="color:#7c3aed"></i> Registros de Conteo (Cargue Inicial)</span>
          <span id="dash-conteo-badge" class="badge" style="background:#ede9fe;color:#7c3aed;margin-left:8px;">cargando…</span>
        </div>
        <div id="dash-conteo-panel" style="display:block">
          <!-- Filtros -->
          <div style="display:flex;gap:10px;flex-wrap:wrap;padding:12px 16px;background:#fafafa;border-bottom:1px solid #e2e8f0;">
            <input id="dash-f-auxiliar"   class="form-control" style="max-width:200px;font-size:.82rem;" placeholder="🔍 Auxiliar…"   oninput="WMS_MODULES.inventario._dashFiltrarConteos()">
            <input id="dash-f-ambiente"   class="form-control" style="max-width:160px;font-size:.82rem;" placeholder="🔍 Ambiente…"   oninput="WMS_MODULES.inventario._dashFiltrarConteos()">
            <input id="dash-f-referencia" class="form-control" style="max-width:220px;font-size:.82rem;" placeholder="🔍 Referencia…" oninput="WMS_MODULES.inventario._dashFiltrarConteos()">
            <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('dash-f-auxiliar').value='';document.getElementById('dash-f-ambiente').value='';document.getElementById('dash-f-referencia').value='';WMS_MODULES.inventario._dashFiltrarConteos()">
              <i class="fa-solid fa-xmark"></i> Limpiar
            </button>
          </div>
          <div class="table-container" style="max-height:380px;overflow-y:auto;">
            <table class="erp-table" id="dash-conteo-table">
              <thead><tr>
                <th>Auxiliar</th><th>Ambiente</th><th>Referencia</th><th>Ubicación</th>
                <th class="text-center">UND/TOTAL</th><th>Fecha</th>
              </tr></thead>
              <tbody id="dash-conteo-tbody">
                <tr><td colspan="6" class="table-empty"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>`);
      /* Cargar registros de conteo en background */
      API.get('/inventario/cargue-inicial/pendientes').then(r => {
        window._dashConteoLineas = r.data?.lineas || [];
        this._dashFiltrarConteos();
      }).catch(() => {
        const tb = document.getElementById('dash-conteo-tbody');
        if (tb) tb.innerHTML = '<tr><td colspan="6" class="table-empty">Error cargando registros</td></tr>';
      });
      /* Cargar ubicaciones contadas en cero en background */
      API.get('/inventario/ubicaciones-en-cero').then(r => {
        window._dashCeroLineas = Array.isArray(r.data) ? r.data : [];
        this._dashFiltrarCero();
      }).catch(() => {
        const tb2 = document.getElementById('dash-cero-tbody');
        if (tb2) tb2.innerHTML = '<tr><td colspan="9" class="table-empty">Error cargando datos</td></tr>';
        const bg2 = document.getElementById('dash-cero-badge');
        if (bg2) bg2.textContent = 'error';
      });
    } catch(e) { if (e.isSessionExpired) return; console.error(e); WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  _dashFiltrarConteos() {
    const lineas  = window._dashConteoLineas || [];
    const fAux    = (document.getElementById('dash-f-auxiliar')?.value   || '').toLowerCase();
    const fAmb    = (document.getElementById('dash-f-ambiente')?.value   || '').toLowerCase();
    const fRef    = (document.getElementById('dash-f-referencia')?.value || '').toLowerCase();
    const tbody   = document.getElementById('dash-conteo-tbody');
    const badge   = document.getElementById('dash-conteo-badge');

    const filtradas = lineas.filter(l => {
      const aux  = (l.nombre_usuario || '').toLowerCase();
      const amb  = (l.ubicacion_codigo || '').split('/')[0].toLowerCase();
      const ref  = (l.producto || '').toLowerCase();
      return (!fAux || aux.includes(fAux))
          && (!fAmb || amb.includes(fAmb))
          && (!fRef || ref.includes(fRef));
    });

    if (badge) badge.textContent = filtradas.length + ' registros';
    if (!tbody) return;
    if (!filtradas.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="table-empty">Sin registros con esos filtros</td></tr>';
      return;
    }
    tbody.innerHTML = filtradas.map(l => {
      const amb     = (l.ubicacion_codigo || '').split('/')[0] || '—';
      const esVac   = parseFloat(l.und_total || 0) <= 0;
      const fecha   = l.created_at ? WMS.formatDate(l.created_at) : '—';
      return `<tr>
        <td style="font-size:.8rem;">${WMS.esc(l.nombre_usuario || '—')}</td>
        <td><span class="badge badge-light-blue" style="text-transform:uppercase;">${WMS.esc(amb)}</span></td>
        <td style="font-size:.82rem;font-weight:600;">${WMS.esc(l.producto || '—')}</td>
        <td><span class="badge badge-gray">${WMS.esc(l.ubicacion_codigo || '—')}</span></td>
        <td class="text-center" style="font-weight:700;color:${esVac?'#dc2626':'#1e40af'};">
          ${esVac ? '<span class="badge" style="background:#fef2f2;color:#dc2626;">VACIAR</span>' : WMS.formatNum(l.und_total)}
        </td>
        <td style="font-size:.78rem;color:#64748b;">${fecha}</td>
      </tr>`;
    }).join('');
  },

  _dashFiltrarCero() {
    const lineas = window._dashCeroLineas || [];
    const fAux   = (document.getElementById('dash-cero-f-aux')?.value  || '').toLowerCase();
    const fUbic  = (document.getElementById('dash-cero-f-ubic')?.value || '').toLowerCase();
    const fRef   = (document.getElementById('dash-cero-f-ref')?.value  || '').toLowerCase();
    const tbody  = document.getElementById('dash-cero-tbody');
    const badge  = document.getElementById('dash-cero-badge');

    const filtradas = lineas.filter(l =>
      (!fAux  || (l.auxiliar_nombre||'').toLowerCase().includes(fAux))
   && (!fUbic || (l.ubicacion_codigo||'').toLowerCase().includes(fUbic))
   && (!fRef  || (l.producto_nombre||'').toLowerCase().includes(fRef) || (l.producto_codigo||'').toLowerCase().includes(fRef))
    );

    if (badge) {
      badge.textContent = filtradas.length + ' líneas';
      badge.style.background = filtradas.length > 0 ? '#fee2e2' : '#f0fdf4';
      badge.style.color = filtradas.length > 0 ? '#dc2626' : '#166534';
    }
    if (!tbody) return;
    if (!filtradas.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin ubicaciones contadas en cero</td></tr>';
      return;
    }
    tbody.innerHTML = filtradas.map(l => {
      const tieneSistema = parseFloat(l.cantidad_sistema || 0) > 0;
      return `<tr id="dash-cero-row-${l.linea_id}" style="${tieneSistema ? 'background:#fff7ed;' : ''}">
        <td style="font-size:.8rem;">
          <span class="badge" style="background:${l.sesion_tipo==='Ciclico'?'#eff6ff':'#f0fdf4'};color:${l.sesion_tipo==='Ciclico'?'#1e40af':'#166534'};font-size:.7rem;">
            ${WMS.esc(l.sesion_tipo||'?')}
          </span>
          <div style="font-size:.75rem;color:#64748b;margin-top:2px;">${WMS.esc(l.sesion_nombre||'-')}</div>
        </td>
        <td style="font-size:.8rem;">${WMS.esc(l.auxiliar_nombre||'—')}</td>
        <td><span class="badge badge-gray">${WMS.esc(l.ubicacion_codigo||'—')}</span>
          ${l.ubicacion_zona ? `<span style="font-size:.7rem;color:#94a3b8;margin-left:4px;">${WMS.esc(l.ubicacion_zona)}</span>` : ''}
        </td>
        <td style="font-size:.8rem;">
          <div style="font-weight:600;">${WMS.esc(l.producto_nombre||'—')}</div>
          <div style="font-size:.72rem;color:#64748b;">${WMS.esc(l.producto_codigo||'')}</div>
        </td>
        <td class="text-center">
          ${tieneSistema
            ? `<span class="badge badge-warning" title="Tiene inventario en sistema">${WMS.formatNum(l.cantidad_sistema)}</span>`
            : `<span style="color:#94a3b8;font-size:.8rem;">0</span>`}
        </td>
        <td class="text-center"><span class="badge" style="background:#fee2e2;color:#dc2626;">0</span></td>
        <td style="font-size:.75rem;color:#64748b;">${l.hora_conteo ? l.hora_conteo.slice(0,16).replace('T',' ') : '—'}</td>
        <td class="text-center" style="font-size:.8rem;">${l.ronda||1}</td>
        <td class="text-center">
          ${tieneSistema
            ? `<button class="btn btn-sm" style="background:#dc2626;color:#fff;font-size:.72rem;padding:3px 8px;"
                 onclick="WMS_MODULES.inventario._aprobarAjusteCero(${l.linea_id}, ${l.sesion_id}, this)">
                 <i class="fa-solid fa-check"></i> Aprobar Ajuste
               </button>`
            : `<span style="color:#94a3b8;font-size:.75rem;">Sin stock</span>`}
        </td>
      </tr>`;
    }).join('');
  },

  async _aprobarAjusteCero(lineaId, sesionId, btnEl) {
    if (!confirm('¿Aprobar ajuste? La ubicación quedará vacía en inventario y se registrará en el kardex.')) return;
    if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    try {
      await API.post(`/v2/inventario/sesiones/${sesionId}/ajustar-linea`, { linea_id: lineaId });
      WMS.toast('Ajuste aprobado — kardex actualizado', 'success');
      const fila = document.getElementById(`dash-cero-row-${lineaId}`);
      if (fila) fila.remove();
      // Actualizar contador en badge
      window._dashCeroLineas = (window._dashCeroLineas || []).filter(l => l.linea_id !== lineaId);
      const badge = document.getElementById('dash-cero-badge');
      if (badge) {
        const n = (window._dashCeroLineas || []).length;
        badge.textContent = n + ' líneas';
        badge.style.background = n > 0 ? '#fee2e2' : '#f0fdf4';
        badge.style.color = n > 0 ? '#dc2626' : '#166534';
      }
      const tbody = document.getElementById('dash-cero-tbody');
      if (tbody && !tbody.children.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin ubicaciones contadas en cero</td></tr>';
      }
    } catch(e) {
      WMS.toast(e?.data?.error || 'Error al aprobar ajuste — verifique su rol', 'error');
      if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fa-solid fa-check"></i> Aprobar Ajuste'; }
    }
  },

  // ── CORRECCIÓN MANUAL V2 ─────────────────────────────────────
  _ajProd: null,   // producto seleccionado en ajuste

  async show_ajuste() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario.showAjustesReport(null)">
        <i class="fa-solid fa-history"></i> Ver Registro de Ajustes
      </button>`);
    WMS.setContent(`
      <div style="max-width:760px;margin:0 auto;">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-sliders"></i> Corrección Manual de Inventario</span>
            <span style="font-size:.78rem;color:#64748b">Registrada en el Kardex — inmutable</span>
          </div>
          <div class="card-body">

            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:4px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
              <i class="fa-solid fa-triangle-exclamation" style="color:#92400e;font-size:1.1rem;"></i>
              <span style="font-size:.85rem;color:#92400e;font-weight:600;">
                Cada corrección genera un movimiento en el Kardex y un registro <strong>inmutable</strong> en la tabla de ajustes.
                Para corregir un error previo, registre un ajuste compensatorio.
              </span>
            </div>

            <!-- Fila 1: Producto -->
            <div class="form-group" style="margin-bottom:16px;">
              <label class="form-label">Producto / EAN <span class="required">*</span></label>
              <input id="aj-prod-ac" class="form-control" placeholder="Escriba nombre o escanee EAN...">
              <input type="hidden" id="aj-prod-id">
              <input type="hidden" id="aj-prod-upc" value="1">
              <div id="aj-stock-preview" style="margin-top:6px;font-size:.8rem;color:#475569;display:none;background:#f8fafc;border-radius:6px;padding:8px 12px;border:1px solid #e2e8f0;"></div>
            </div>

            <div class="form-grid form-grid-2">
              <!-- Tipo ajuste -->
              <div class="form-group">
                <label class="form-label">Tipo de Ajuste <span class="required">*</span></label>
                <select id="aj-tipo" class="form-control" onchange="WMS_MODULES.inventario._ajTipoChanged()">
                  <option value="">Seleccionar...</option>
                  <option value="Entrada">▲ Entrada — suma al stock</option>
                  <option value="Salida">▼ Salida — resta del stock</option>
                </select>
              </div>
              <!-- Cantidad: se reemplaza dinámicamente según UPC del producto -->
              <div id="aj-cantidad-wrap" class="form-group">
                <label class="form-label">Cantidad <span class="required">*</span></label>
                <input id="aj-cantidad" type="number" class="form-control" min="0.01" step="0.01" placeholder="0">
              </div>
              <!-- Ubicación: para Entrada, búsqueda dinámica sobre TODAS las ubicaciones
                   activas (puede no tener stock previo de esta referencia); para Salida,
                   solo se puede elegir entre las ubicaciones que YA tienen stock del
                   producto — de ahí es de donde físicamente se va a sacar. -->
              <div class="form-group" style="position:relative;">
                <label class="form-label">Ubicación <span class="required">*</span></label>
                <input id="aj-ubicacion-input" class="form-control" placeholder="Escriba el código de la ubicación..." autocomplete="off">
                <input type="hidden" id="aj-ubicacion-id">
                <input type="hidden" id="aj-ubicacion-codigo">
                <select id="aj-ubicacion-salida" class="form-control" style="display:none;">
                  <option value="">— Seleccione un producto primero —</option>
                </select>
                <small id="aj-ubicacion-hint" style="color:#64748b;font-size:.75rem;display:block;margin-top:4px;"></small>
              </div>
              <!-- Lote -->
              <div class="form-group">
                <label class="form-label">Lote</label>
                <input id="aj-lote" class="form-control" placeholder="Opcional...">
              </div>
              <!-- Fecha vencimiento -->
              <div class="form-group" id="aj-fv-wrap" style="display:none;">
                <label class="form-label">Fecha Vencimiento <span class="required">*</span></label>
                <input id="aj-fv" type="date" class="form-control">
                <small style="font-size:.75rem;color:#64748b;">Obligatoria para ajuste de Entrada</small>
              </div>
              <!-- Motivo -->
              <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Motivo <span class="required">*</span></label>
                <textarea id="aj-motivo" class="form-control" rows="2" placeholder="Ej: Conteo físico, devolución proveedor, merma..."></textarea>
              </div>
            </div>

            <!-- Preview UND/TOTAL para productos con cajas -->
            <div id="aj-preview" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:9px 14px;margin-bottom:8px;font-size:.84rem;"></div>

            <div style="text-align:right;margin-top:16px;">
              <button class="btn btn-primary btn-lg" onclick="WMS_MODULES.inventario.ejecutarAjuste()">
                <i class="fa-solid fa-check"></i> Aplicar Corrección
              </button>
            </div>

          </div>
        </div>

        <!-- Correcciones de hoy -->
        <div class="card mt-16" id="aj-hoy-card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Correcciones de hoy</span></div>
          <div id="aj-hoy-content"><div class="spinner sm" style="margin:10px auto;display:block;"></div></div>
        </div>
      </div>`);

    this._ajProd  = null;
    this._ajStock = [];

    // Ubicación (Entrada): búsqueda dinámica sobre TODAS las ubicaciones activas de la
    // sucursal (no solo las que ya tienen stock del producto). Reutiliza el helper de
    // reportes.js; se fuerza su carga porque este módulo puede no haberse cargado aún
    // (los scripts de cada módulo se cargan de forma perezosa al navegar a esa sección).
    WMS.loadScript('assets/js/desktop/reportes.js', () => {
      WMS_MODULES.reportes.initUbicacionAutocomplete('aj-ubicacion-input', 'aj-ubicacion-id', 'aj-ubicacion-codigo');
    });

    // Autocomplete producto
    WMS.initProductAutocomplete(document.getElementById('aj-prod-ac'), async p => {
      document.getElementById('aj-prod-id').value = p.id;
      const upc = Math.max(1, parseInt(p.unidades_caja) || 1);
      document.getElementById('aj-prod-upc').value = upc;
      this._ajProd  = p;
      this._ajStock = [];
      this._ajRenderCantidadInputs(upc);

      const selSalida = document.getElementById('aj-ubicacion-salida');
      const prev      = document.getElementById('aj-stock-preview');
      if (selSalida) selSalida.innerHTML = '<option value="">Cargando ubicaciones...</option>';
      if (prev)  { prev.style.display = 'block'; prev.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Cargando stock...'; }
      try {
        const rs    = await API.get('/inventario/stock', `producto_id=${p.id}&limit=50`);
        const stock = rs.data || rs || [];
        this._ajStock = stock;
        // Preview compacto (máx 3)
        if (prev) {
          const top3 = stock.slice(0, 3);
          prev.innerHTML = top3.length
            ? top3.map(s => `<span style="margin-right:8px;"><i class="fa-solid fa-box"></i> <strong>${WMS.formatNum(s.cantidad)}</strong> en ${WMS.esc(s.ubicacion_codigo || '-')}${s.lote ? ' · Lote ' + WMS.esc(s.lote) : ''}</span>`).join('')
            : '<span style="color:#94a3b8;">Sin stock registrado en sistema</span>';
        }
        // Selector de Salida: solo ubicaciones con stock real de este producto —
        // de ahí es de donde el sistema puede físicamente descontar.
        if (selSalida) {
          if (stock.length) {
            selSalida.innerHTML = '<option value="">Seleccione ubicación...</option>' +
              stock.map(s => `<option value="${s.ubicacion_id}" data-fv="${WMS.esc(s.fecha_vencimiento||'')}" data-lote="${WMS.esc(s.lote||'')}">${WMS.esc(s.ubicacion_codigo||('Ubic.'+s.ubicacion_id))} — ${WMS.formatNum(s.cantidad)} und${s.lote?' · Lote '+WMS.esc(s.lote):''}</option>`).join('');
          } else {
            selSalida.innerHTML = '<option value="">Sin stock en ninguna ubicación</option>';
          }
        }
      } catch(e) {
        if (prev) prev.innerHTML = '<span style="color:#94a3b8;">No se pudo cargar el stock</span>';
        if (selSalida) selSalida.innerHTML = '<option value="">Error cargando ubicaciones</option>';
      }
      this._ajTipoChanged();
    });

    // Cargar correcciones de hoy
    this._loadHoyAjustes();
  },

  /** Reemplaza el bloque de cantidad del ajuste según UPC */
  _ajRenderCantidadInputs(upc) {
    const wrap = document.getElementById('aj-cantidad-wrap');
    if (!wrap) return;
    if (upc > 1) {
      wrap.innerHTML = `
        <label class="form-label">Cantidad <span class="required">*</span></label>
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Cajas</label>
            <input id="aj-cajas" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.inventario._ajCalcPreview()" placeholder="0">
          </div>
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Sueltos</label>
            <input id="aj-saldos" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.inventario._ajCalcPreview()" placeholder="0">
          </div>
        </div>
        <input type="hidden" id="aj-cantidad" value="0">`;
    } else {
      wrap.innerHTML = `
        <label class="form-label">Cantidad <span class="required">*</span></label>
        <input id="aj-cantidad" type="number" class="form-control" min="0.01" step="0.01" placeholder="0"
          oninput="WMS_MODULES.inventario._ajCalcPreview()">
        <input type="hidden" id="aj-cajas" value="0">
        <input type="hidden" id="aj-saldos" value="0">`;
    }
    this._ajCalcPreview();
  },

  _ajTipoChanged() {
    const tipo      = document.getElementById('aj-tipo')?.value;
    const wrap      = document.getElementById('aj-fv-wrap');
    const fv        = document.getElementById('aj-fv');
    const ubiInput  = document.getElementById('aj-ubicacion-input');
    const ubiSelect = document.getElementById('aj-ubicacion-salida');
    const hint      = document.getElementById('aj-ubicacion-hint');
    if (wrap) {
      if (tipo === 'Entrada') {
        wrap.style.display = '';
        if (fv) fv.setAttribute('required', 'required');
      } else {
        wrap.style.display = 'none';
        if (fv) { fv.removeAttribute('required'); fv.value = ''; }
      }
    }
    // Entrada: cualquier ubicación activa (búsqueda dinámica) — puede no tener stock aún.
    // Salida: solo se puede elegir entre las ubicaciones que ya tienen stock del producto.
    if (tipo === 'Salida') {
      if (ubiInput)  ubiInput.style.display  = 'none';
      if (ubiSelect) ubiSelect.style.display = '';
      if (hint) hint.textContent = 'Solo se muestran ubicaciones con stock disponible de este producto.';
    } else {
      if (ubiInput)  ubiInput.style.display  = '';
      if (ubiSelect) ubiSelect.style.display = 'none';
      if (hint) hint.textContent = tipo === 'Entrada'
        ? 'Escriba cualquier ubicación activa — puede no tener stock previo de esta referencia.'
        : '';
    }
  },

  _ajCalcPreview() {
    const upc = Math.max(1, parseInt(document.getElementById('aj-prod-upc')?.value || '1') || 1);
    const preview = document.getElementById('aj-preview');
    if (!preview) return;
    if (upc > 1) {
      const cajas  = parseFloat(document.getElementById('aj-cajas')?.value  || '0') || 0;
      const saldos = parseFloat(document.getElementById('aj-saldos')?.value || '0') || 0;
      const total  = cajas * upc + saldos;
      const cantEl = document.getElementById('aj-cantidad');
      if (cantEl) cantEl.value = total;
      preview.style.display = 'block';
      preview.innerHTML = `<b>UND/TOTAL:</b> ${cajas} cajas × ${upc} u/caja + ${saldos} sueltos = `
        + `<b style="color:#1e40af;font-size:1.05em;">${total.toFixed(2)}</b>`;
    } else {
      preview.style.display = 'none';
    }
  },

  async _loadHoyAjustes() {
    const el = document.getElementById('aj-hoy-content');
    if (!el) return;
    try {
      const hoy = new Date().toISOString().substring(0, 10);
      const ra  = await API.get('/v2/inventario/ajustes', `desde=${hoy}&hasta=${hoy}&origen=CorreccionAdmin`);
      const ays = ra.data || ra || [];
      if (!ays.length) {
        el.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:16px;font-style:italic;">Sin correcciones realizadas hoy.</p>';
        return;
      }
      el.innerHTML = `
        <div class="table-container">
          <table class="data-table compact">
            <thead><tr><th>Hora</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Ubicación</th><th>Ajustado por</th></tr></thead>
            <tbody>${ays.slice(0, 15).map(a => `<tr>
              <td style="font-size:.75rem;">${(a.hora||'').substring(0,5)}</td>
              <td style="font-size:.8rem;">${WMS.esc(a.producto||'-')}</td>
              <td><span class="badge ${a.tipo_ajuste==='Entrada'?'badge-success':'badge-danger'}">${WMS.esc(a.tipo_ajuste||'-')}</span></td>
              <td class="text-center fw-600">${WMS.formatNum(Math.abs(a.dif||0))}</td>
              <td style="font-size:.78rem;">${WMS.esc(a.ubicacion||'-')}</td>
              <td style="font-size:.75rem;">${WMS.esc(a.ajustado_por||'-')}</td>
            </tr>`).join('')}
            </tbody>
          </table>
        </div>`;
    } catch(e) { el.innerHTML = '<p class="text-danger" style="padding:12px;">Error cargando correcciones.</p>'; }
  },

  async ejecutarAjuste() {
    const prodId = document.getElementById('aj-prod-id')?.value;
    const tipo   = document.getElementById('aj-tipo')?.value;
    const motivo = document.getElementById('aj-motivo')?.value?.trim();
    const upc    = Math.max(1, parseInt(document.getElementById('aj-prod-upc')?.value || '1') || 1);

    // Leer cajas / saldos / cantidad según modo
    let cajas, saldos, cantidad;
    if (upc > 1) {
      cajas    = Math.floor(parseFloat(document.getElementById('aj-cajas')?.value  || '0') || 0);
      saldos   = parseFloat(document.getElementById('aj-saldos')?.value || '0') || 0;
      cantidad = cajas * upc + saldos;
    } else {
      cajas    = 0;
      saldos   = 0;
      cantidad = parseFloat(document.getElementById('aj-cantidad')?.value || 0);
    }

    if (!prodId)                { WMS.toast('warning', 'Seleccione un producto'); return; }
    if (!tipo)                  { WMS.toast('warning', 'Seleccione el tipo de ajuste'); return; }
    if (!cantidad || cantidad <= 0) { WMS.toast('warning', 'Ingrese una cantidad válida (mayor a 0)'); return; }
    if (!motivo)                { WMS.toast('warning', 'Ingrese el motivo del ajuste'); return; }

    // Entrada: ubicación de búsqueda libre (cualquier ubicación activa).
    // Salida: ubicación tomada del selector restringido a stock existente.
    let ubicacionId = null;
    if (tipo === 'Salida') {
      const selUbi = document.getElementById('aj-ubicacion-salida');
      ubicacionId = parseInt(selUbi?.value || 0) || null;
      if (!ubicacionId) { WMS.toast('warning', 'Seleccione la ubicación de la cual desea hacer la salida'); return; }
    } else {
      ubicacionId = parseInt(document.getElementById('aj-ubicacion-id')?.value || 0) || null;
      if (!ubicacionId) { WMS.toast('warning', 'Seleccione la ubicación donde va a registrar la entrada'); return; }
    }

    let fvFinal = null;
    if (tipo === 'Entrada') {
      fvFinal = document.getElementById('aj-fv')?.value || null;
      if (!fvFinal) { WMS.toast('warning', 'Ingrese la fecha de vencimiento (obligatoria para Entrada)'); return; }
    } else if (tipo === 'Salida') {
      const selUbi = document.getElementById('aj-ubicacion-salida');
      if (selUbi && selUbi.selectedIndex > 0)
        fvFinal = selUbi.options[selUbi.selectedIndex].getAttribute('data-fv') || null;
    }

    try {
      const r = await API.post('/v2/inventario/correccion', {
        producto_id:       parseInt(prodId),
        tipo_ajuste:       tipo,
        cantidad:          cantidad,
        cantidad_cajas:    cajas,
        saldos:            saldos,
        ubicacion_id:      ubicacionId,
        lote:              document.getElementById('aj-lote')?.value?.trim() || null,
        fecha_vencimiento: fvFinal,
        motivo:            motivo,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', `Corrección aplicada correctamente`);
      // Limpiar form
      ['aj-prod-ac','aj-lote','aj-motivo','aj-ubicacion-input','aj-ubicacion-id','aj-ubicacion-codigo'].forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
      ['aj-prod-id','aj-cantidad','aj-prod-upc'].forEach(id => { const el = document.getElementById(id); if(el) el.value = id==='aj-prod-upc'?'1':''; });
      ['aj-cajas','aj-saldos'].forEach(id => { const el = document.getElementById(id); if(el) el.value='0'; });
      const prev = document.getElementById('aj-stock-preview');
      if (prev) prev.style.display = 'none';
      const prew = document.getElementById('aj-preview');
      if (prew) prew.style.display = 'none';
      const selUbiR = document.getElementById('aj-ubicacion-salida');
      if (selUbiR) selUbiR.innerHTML = '<option value="">— Seleccione un producto primero —</option>';
      const hintR = document.getElementById('aj-ubicacion-hint');
      if (hintR) hintR.textContent = '';
      const fvWrapR = document.getElementById('aj-fv-wrap');
      if (fvWrapR) fvWrapR.style.display = 'none';
      this._ajProd  = null;
      this._ajStock = [];
      // Recargar tabla de hoy
      this._loadHoyAjustes();
    } catch(e) { WMS.toast('error', e.message || 'Error ejecutando corrección'); }
  },

  // ══════════════════════════════════════════════════════════════════════════
  // AJUSTES POR UBICACIÓN — Flujo mobile → aprobación escritorio
  // ══════════════════════════════════════════════════════════════════════════

  async show_ajuste_ubicacion() {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.inventario._ajusteUbiRefresh()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>`);

    WMS.setContent(`
      <div style="max-width:960px;margin:0 auto;">
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-location-crosshairs"></i> Ajustes por Ubicación Pendientes de Aprobación</span>
          </div>
          <div class="card-body" style="padding:0;">
            <div id="ajubi-table-wrap"><div class="spinner sm" style="margin:24px auto;display:block;"></div></div>
          </div>
        </div>

        <div class="card mt-16">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Historial de Ajustes x Ubicación</span>
          </div>
          <div class="card-body" style="padding:0;">
            <div id="ajubi-hist-wrap"><div class="spinner sm" style="margin:24px auto;display:block;"></div></div>
          </div>
        </div>
      </div>

      <!-- Modal de detalle/aprobación -->
      <div id="ajubi-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;max-width:720px;width:96%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
          <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <b style="font-size:1rem;"><i class="fa-solid fa-location-crosshairs" style="color:#0891b2;"></i> Detalle del Ajuste</b>
            <button onclick="document.getElementById('ajubi-modal').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#64748b;">&times;</button>
          </div>
          <div id="ajubi-modal-body" style="padding:20px;"></div>
        </div>
      </div>`);

    this._ajusteUbiRefresh();
  },

  async _ajusteUbiRefresh() {
    await Promise.all([
      this._ajusteUbiLoadPendientes(),
      this._ajusteUbiLoadHistorial(),
    ]);
  },

  async _ajusteUbiLoadPendientes() {
    const wrap = document.getElementById('ajubi-table-wrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="spinner sm" style="margin:24px auto;display:block;"></div>';
    try {
      const r = await API.get('/inventario/ajuste-ubicacion', 'estado=Pendiente');
      const rows = r.data || r || [];
      if (!rows.length) {
        wrap.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:24px;font-style:italic;">No hay ajustes pendientes de aprobación.</p>';
        return;
      }
      const tipoBadge = (tipo) => tipo === 'AgregarInventario'
        ? '<span style="background:#dcfce7;color:#065f46;border-radius:99px;font-size:.7rem;font-weight:700;padding:2px 8px;white-space:nowrap;"><i class="fa-solid fa-plus"></i> Agregar</span>'
        : '<span style="background:#fef3c7;color:#92400e;border-radius:99px;font-size:.7rem;font-weight:700;padding:2px 8px;white-space:nowrap;"><i class="fa-solid fa-rotate-left"></i> Ajuste Completo</span>';
      wrap.innerHTML = `
        <div class="table-container">
          <table class="data-table">
            <thead><tr>
              <th>#</th><th>Ubicación</th><th>Tipo</th><th>Auxiliar</th><th>Productos</th><th>Fecha</th><th>Acciones</th>
            </tr></thead>
            <tbody>
              ${rows.map(a => `
              <tr>
                <td>${a.id}</td>
                <td><b>${WMS.esc(a.ubicacion?.codigo || '-')}</b><br><small style="color:#64748b;">${WMS.esc(a.ubicacion?.nombre || '')}</small></td>
                <td>${tipoBadge(a.tipo)}</td>
                <td>${WMS.esc((a.auxiliar?.nombre || '') + ' ' + (a.auxiliar?.apellido || ''))}</td>
                <td>${a.detalles_count ?? '—'} ref.</td>
                <td>${new Date(a.created_at).toLocaleString('es-CO', {dateStyle:'short',timeStyle:'short'})}</td>
                <td>
                  <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario._ajusteUbiDetalle(${a.id})">
                    <i class="fa-solid fa-eye"></i> Ver
                  </button>
                </td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>`;
    } catch(e) { wrap.innerHTML = `<p style="color:red;padding:16px;">${WMS.esc(e.message)}</p>`; }
  },

  async _ajusteUbiLoadHistorial() {
    const wrap = document.getElementById('ajubi-hist-wrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="spinner sm" style="margin:24px auto;display:block;"></div>';
    try {
      const r = await API.get('/inventario/ajuste-ubicacion', '');
      const rows = (r.data || r || []).filter(a => a.estado !== 'Pendiente').slice(0, 50);
      if (!rows.length) {
        wrap.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:20px;font-style:italic;">Sin historial aún.</p>';
        return;
      }
      const estadoColor = { Aprobado: '#059669', Rechazado: '#dc2626' };
      wrap.innerHTML = `
        <div class="table-container">
          <table class="data-table compact">
            <thead><tr>
              <th>#</th><th>Ubicación</th><th>Auxiliar</th><th>Estado</th><th>Aprobado por</th><th>Fecha aprobación</th><th></th>
            </tr></thead>
            <tbody>
              ${rows.map(a => `
              <tr>
                <td>${a.id}</td>
                <td>${WMS.esc(a.ubicacion?.codigo || '-')}</td>
                <td>${WMS.esc((a.auxiliar?.nombre || '') + ' ' + (a.auxiliar?.apellido || ''))}</td>
                <td><span style="color:${estadoColor[a.estado]||'#64748b'};font-weight:700;">${a.estado}</span></td>
                <td>${WMS.esc((a.aprobador?.nombre || '-') + ' ' + (a.aprobador?.apellido || ''))}</td>
                <td>${a.fecha_aprobacion ? new Date(a.fecha_aprobacion).toLocaleString('es-CO', {dateStyle:'short',timeStyle:'short'}) : '-'}</td>
                <td><button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.inventario._ajusteUbiDetalle(${a.id})"><i class="fa-solid fa-eye"></i></button></td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>`;
    } catch(e) { wrap.innerHTML = `<p style="color:red;padding:16px;">${WMS.esc(e.message)}</p>`; }
  },

  async _ajusteUbiDetalle(id) {
    const modal = document.getElementById('ajubi-modal');
    const body  = document.getElementById('ajubi-modal-body');
    if (!modal || !body) return;
    body.innerHTML = '<div class="spinner sm" style="margin:32px auto;display:block;"></div>';
    modal.style.display = 'flex';

    try {
      const r = await API.get(`/inventario/ajuste-ubicacion/${id}`);
      const { ajuste, inv_actual } = r.data ?? r;
      const esPendiente = ajuste.estado === 'Pendiente';

      // Tabla inventario actual
      const invHtml = (inv_actual || []).length
        ? `<table class="data-table compact" style="margin-top:6px;">
            <thead><tr><th>Producto</th><th>Lote</th><th>Cajas</th><th>Sueltos</th><th>UND/T</th></tr></thead>
            <tbody>${(inv_actual || []).map(i => `
              <tr>
                <td>${WMS.esc(i.producto?.nombre || '-')}<br><small>${WMS.esc(i.producto?.codigo_interno || '')}</small></td>
                <td>${WMS.esc(i.lote || 'N/A')}</td>
                <td>${i.cantidad_cajas ?? 0}</td>
                <td>${i.saldos ?? 0}</td>
                <td><b>${WMS.formatNum(i.cantidad)}</b></td>
              </tr>`).join('')}
            </tbody>
          </table>`
        : '<p style="color:#94a3b8;font-style:italic;font-size:.85rem;">Sin inventario actual en esta ubicación.</p>';

      // Tabla conteo auxiliar (editable si Pendiente)
      const detHtml = `<table class="data-table compact" style="margin-top:6px;">
          <thead><tr><th>Producto</th><th>Lote</th><th>Vencimiento</th><th>Cajas</th><th>Sueltos</th><th>UND/T</th></tr></thead>
          <tbody>${(ajuste.detalles || []).map(d => {
            const upc = +(d.producto?.unidades_caja ?? 1) || 1;
            if (esPendiente) {
              return `<tr data-det-id="${d.id}" data-upc="${upc}">
                <td>${WMS.esc(d.producto?.nombre || '-')}<br><small>${WMS.esc(d.producto?.codigo_interno || '')}</small></td>
                <td><input class="det-lote form-control form-control-sm" value="${WMS.esc(d.lote || '')}" placeholder="N/A" style="width:90px;min-width:80px;"></td>
                <td><input class="det-fv form-control form-control-sm" type="date" value="${(d.fecha_vencimiento || '').substring(0, 10)}" style="width:135px;"></td>
                <td><input class="det-cajas form-control form-control-sm" type="number" min="0" step="1" value="${d.cantidad_cajas}" style="width:68px;" oninput="WMS_MODULES.inventario._ajusteUbiRecalc(this)"></td>
                <td><input class="det-saldos form-control form-control-sm" type="number" min="0" step="0.01" value="${d.saldos}" style="width:68px;" oninput="WMS_MODULES.inventario._ajusteUbiRecalc(this)"></td>
                <td><b class="det-total">${WMS.formatNum(d.cantidad)}</b></td>
              </tr>`;
            }
            return `<tr>
              <td>${WMS.esc(d.producto?.nombre || '-')}<br><small>${WMS.esc(d.producto?.codigo_interno || '')}</small></td>
              <td>${WMS.esc(d.lote || 'N/A')}</td>
              <td>${d.fecha_vencimiento || '-'}</td>
              <td>${d.cantidad_cajas}</td>
              <td>${d.saldos}</td>
              <td><b>${WMS.formatNum(d.cantidad)}</b></td>
            </tr>`;
          }).join('')}
          </tbody>
        </table>`;

      const estadoBadge = {
        Pendiente: '<span style="background:#fef3c7;color:#92400e;padding:2px 10px;border-radius:99px;font-weight:700;">Pendiente</span>',
        Aprobado:  '<span style="background:#d1fae5;color:#065f46;padding:2px 10px;border-radius:99px;font-weight:700;">Aprobado</span>',
        Rechazado: '<span style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:99px;font-weight:700;">Rechazado</span>',
      }[ajuste.estado] || ajuste.estado;

      const esAgregar = ajuste.tipo === 'AgregarInventario';
      const tipoLabel = esAgregar
        ? '<span style="background:#dcfce7;color:#065f46;border-radius:99px;font-size:.75rem;font-weight:700;padding:3px 12px;"><i class="fa-solid fa-plus"></i> Agregar Inventario</span>'
        : '<span style="background:#fef3c7;color:#92400e;border-radius:99px;font-size:.75rem;font-weight:700;padding:3px 12px;"><i class="fa-solid fa-rotate-left"></i> Ajuste Completo</span>';
      const invLabel = esAgregar
        ? '<i class="fa-solid fa-database"></i> Inventario Actual (se conservará + se sumará)'
        : '<i class="fa-solid fa-database"></i> Inventario Actual (será eliminado al aprobar)';
      const detLabel = esAgregar
        ? '<i class="fa-solid fa-plus"></i> Stock a Agregar'
        : '<i class="fa-solid fa-clipboard-check"></i> Conteo del Auxiliar';
      const warningHtml = esAgregar
        ? `<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:10px 14px;margin-top:12px;font-size:.8rem;color:#065f46;">
             <i class="fa-solid fa-circle-info"></i> <b>Al aprobar:</b> las referencias indicadas se <b>SUMARÁN</b> al inventario existente en la ubicación. El stock previo <b>no se elimina</b>. Se registra AjustePositivo en Kardex.
           </div>`
        : `<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin-top:12px;font-size:.8rem;color:#92400e;">
             <i class="fa-solid fa-triangle-exclamation"></i> <b>Al aprobar:</b> el inventario actual de la ubicación se eliminará (AjusteSalida en Kardex) y se creará el inventario contado (AjusteEntrada en Kardex). Esta acción es <b>irreversible</b>.
           </div>`;
      const btnAprobarLabel = esAgregar ? 'Aprobar y Agregar' : 'Aprobar Ajuste';

      body.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;font-size:.85rem;">
          <div><b>Ubicación:</b> ${WMS.esc(ajuste.ubicacion?.codigo || '-')}</div>
          <div><b>Estado:</b> ${estadoBadge}</div>
          <div><b>Tipo:</b> ${tipoLabel}</div>
          <div><b>Auxiliar:</b> ${WMS.esc(ajuste.auxiliar?.nombre || '-')}</div>
          <div><b>Fecha:</b> ${new Date(ajuste.created_at).toLocaleString('es-CO', {dateStyle:'medium',timeStyle:'short'})}</div>
          ${ajuste.observaciones ? `<div style="grid-column:1/-1;"><b>Observaciones:</b> ${WMS.esc(ajuste.observaciones)}</div>` : ''}
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <div style="font-weight:700;color:#64748b;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">
              ${invLabel}
            </div>
            ${invHtml}
          </div>
          <div>
            <div style="font-weight:700;color:${esAgregar ? '#059669' : '#0891b2'};font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">
              ${detLabel}
            </div>
            ${detHtml}
          </div>
        </div>

        ${esPendiente ? `
        <div style="border-top:1px solid #e2e8f0;margin-top:20px;padding-top:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:1;min-width:220px;">
            <label style="font-size:.8rem;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Motivo de rechazo (opcional)</label>
            <input id="ajubi-motivo-rechazo" class="form-control" placeholder="Ej: Diferencias inconsistentes..." style="font-size:.85rem;">
          </div>
          <button class="btn btn-danger" onclick="WMS_MODULES.inventario._ajusteUbiRechazar(${id})">
            <i class="fa-solid fa-xmark"></i> Rechazar
          </button>
          <button class="btn btn-success" onclick="WMS_MODULES.inventario._ajusteUbiAprobar(${id},'${ajuste.tipo||'AjusteCompleto'}',this)" style="background:${esAgregar ? '#059669' : '#0F4C81'};">
            <i class="fa-solid fa-check"></i> ${btnAprobarLabel}
          </button>
        </div>
        ${warningHtml}` : ''}
      `;
    } catch(e) { body.innerHTML = `<p style="color:red;">${WMS.esc(e.message)}</p>`; }
  },

  _ajusteUbiRecalc(input) {
    const tr = input.closest('tr');
    const upc = parseFloat(tr.dataset.upc) || 1;
    const cajas = parseInt(tr.querySelector('.det-cajas')?.value || 0) || 0;
    const saldos = parseFloat(tr.querySelector('.det-saldos')?.value || 0) || 0;
    const totalEl = tr.querySelector('.det-total');
    if (totalEl) totalEl.textContent = WMS.formatNum(cajas * upc + saldos);
  },

  async _ajusteUbiAprobar(id, tipo = 'AjusteCompleto', btnEl = null) {
    if (btnEl?.disabled) return; // evita doble envío por doble clic accidental
    if (btnEl) { btnEl.disabled = true; btnEl.dataset.origHtml = btnEl.innerHTML; btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Procesando...'; }

    // Recolectar detalles editados del modal
    const detalles = [];
    document.querySelectorAll('#ajubi-modal-body tr[data-det-id]').forEach(tr => {
      const upc = parseFloat(tr.dataset.upc) || 1;
      const cajas = parseInt(tr.querySelector('.det-cajas')?.value ?? 0) || 0;
      const saldos = parseFloat(tr.querySelector('.det-saldos')?.value ?? 0) || 0;
      detalles.push({
        id: parseInt(tr.dataset.detId),
        cantidad_cajas: cajas,
        saldos: saldos,
        cantidad: cajas * upc + saldos,
        lote: tr.querySelector('.det-lote')?.value?.trim() || '',
        fecha_vencimiento: tr.querySelector('.det-fv')?.value || null,
      });
    });

    try {
      const r = await API.post(`/inventario/ajuste-ubicacion/${id}/aprobar`, { detalles });
      if (r.error) {
        await Swal.fire('Error al aprobar', r.message, 'error');
        return;
      }
      WMS.toast('success', r.message || 'Aprobado correctamente');
      document.getElementById('ajubi-modal').style.display = 'none';
      this.show_ajuste_ubicacion();
    } catch(e) {
      await Swal.fire('Error al aprobar', e.message || 'Error desconocido', 'error');
    } finally {
      if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = btnEl.dataset.origHtml; }
    }
  },

  async _ajusteUbiRechazar(id) {
    const motivo = document.getElementById('ajubi-motivo-rechazo')?.value || '';
    const ok = await Swal.fire({
      title: 'Rechazar ajuste',
      text: '¿Confirma el rechazo del ajuste?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, rechazar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#dc2626',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post(`/inventario/ajuste-ubicacion/${id}/rechazar`, { motivo });
      if (r.error) { await Swal.fire('Error', r.message, 'error'); return; }
      WMS.toast('success', 'Ajuste rechazado');
      document.getElementById('ajubi-modal').style.display = 'none';
      this.show_ajuste_ubicacion();
    } catch(e) { await Swal.fire('Error', e.message || 'Error al rechazar', 'error'); }
  },

}; // fin WMS_MODULES.inventario