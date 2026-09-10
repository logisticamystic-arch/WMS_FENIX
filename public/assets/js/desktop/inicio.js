/* ============================================================
   WMS Desktop - Módulo INICIO  ·  Dashboard Profesional
   ============================================================ */
WMS_MODULES.inicio = {

  _charts: {},   // guarda instancias Chart.js para destroy al salir

  load() {
    WMS.setBreadcrumb('inicio');
    WMS.renderSidebar('inicio');
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inicio.render()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);
    this.render();
  },

  /* ── destruir gráficas al cambiar de módulo ──────────────────── */
  destroy() {
    Object.values(this._charts).forEach(c => { try { c.destroy(); } catch(_){} });
    this._charts = {};
  },

  /* ── render principal ────────────────────────────────────────── */
  async render() {
    // Destruir gráficas anteriores si existieran
    this.destroy();

    WMS.spinner();
    let stats = {}, d = {}, availability = {}, occupancy = {}, alertasList = [];
    let errorCarga = null;
    try {
      const r = await API.get('/dashboard/summary');
      d = r.data || r;
      stats    = d.stats    || d;
      availability = d.availability || d.inv_state || {};
      occupancy    = d.occupancy    || {};
      alertasList  = d.alertas_list || [];
    } catch(e) {
      console.warn('dashboard/summary', e.message);
      errorCarga = e.message || 'No se pudo cargar la información del dashboard';
    }
    this._alertasList = alertasList;

    /* Nivel de servicio — últimos 7 días (reemplaza el gráfico de Recepciones).
       Promedio de NS1 (referencias despachadas completas / solicitadas) y
       NS2 (unidades despachadas / solicitadas), ambos sin contar agotados
       causados por error de digitación del pedido. */
    let nsLabels = [], nsData = [], nsRefs = [], nsUnid = [];
    try {
      const rns = await API.get('/dashboard/nivel-servicio?tipo=dia&dias=7');
      const dns = rns.data || rns;
      const buscar = (label) => (dns.series || []).find(s => s.label === label)?.data || [];
      nsLabels = (dns.labels || []).map(p => {
        const parts = String(p).split('-');
        return parts.length === 3 ? `${parts[2]}/${parts[1]}` : p;
      });
      nsData = buscar('Nivel de Servicio %');
      nsRefs = buscar('NS Referencias %');
      nsUnid = buscar('NS Unidades %');
    } catch(e) { console.warn('dashboard/nivel-servicio', e.message); }

    /* Extraer valores con fallbacks */
    const productos   = stats.productos   || stats.total_productos  || 0;
    const recepciones = stats.recepciones || stats.rec_hoy          || 0;
    const pickings    = stats.pickings     || stats.despachos   || stats.picking_hoy || 0;
    const alertas     = stats.alertas     || stats.bajo_stock       || 0;
    const ubicaciones = stats.ubicaciones || stats.total_ubicaciones || 0;

    /* Estado inventario - Doble métrica */
    const invOk    = availability.ok    || 0;
    const invWarn  = availability.warn  || 0;
    const invEmpty = availability.empty || 0;
    
    const occOccupied = occupancy.occupied || 0;
    const occEmpty    = occupancy.empty    || 0;

    /* Hora de saludo */
    const hora = new Date().getHours();
    const saludo = hora < 12 ? 'Buenos días' : hora < 18 ? 'Buenas tardes' : 'Buenas noches';
    const now = new Date().toLocaleDateString('es-CO', {weekday:'long', day:'numeric', month:'long', year:'numeric'});

    WMS.setContent(`
<div class="pro-dashboard">

  ${errorCarga ? `
  <div class="inv2-alert" style="margin-bottom:16px;">
    <i class="fa-solid fa-triangle-exclamation fa-lg"></i>
    <div>
      <b>No se pudieron cargar los datos del dashboard:</b> ${WMS.esc(errorCarga)}.
      Los indicadores de abajo pueden estar incompletos o en cero.
      <button class="btn btn-xs btn-outline-danger" style="margin-left:8px;" onclick="WMS_MODULES.inicio.render()">Reintentar</button>
    </div>
  </div>` : ''}

  <!-- BANNER -->
  <div class="pro-welcome-banner">
    <div>
      <h2><i class="fa-solid fa-warehouse" style="margin-right:10px;opacity:.9"></i>${saludo}, bienvenido al WMS</h2>
      <p><i class="fa-regular fa-calendar" style="margin-right:6px"></i>${now.charAt(0).toUpperCase() + now.slice(1)}</p>
    </div>
    <div class="pro-welcome-badge"><i class="fa-solid fa-circle-check" style="margin-right:6px;color:#7fffb2"></i>Sistema Operativo</div>
  </div>

  <!-- KPI GRID (tarjetas interactivas: cada una lleva a su tabla) -->
  <div class="pro-kpi-grid">
    <div class="pro-kpi-card accent-blue" title="Ver catálogo de productos" onclick="WMS.nav('maestro','productos')">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <span class="pro-kpi-trend neu">Catálogo</span>
      </div>
      <div class="pro-kpi-value" id="kpi-productos">0</div>
      <div class="pro-kpi-label">Productos activos</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-circle-dot" style="color:#0070f2;margin-right:4px"></i>En maestro de artículos</div>
    </div>

    <div class="pro-kpi-card accent-green" title="Ver recepciones" onclick="WMS.nav('recepcion','landing')">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
        <span class="pro-kpi-trend up">Hoy</span>
      </div>
      <div class="pro-kpi-value" id="kpi-recepciones">0</div>
      <div class="pro-kpi-label">Recepciones del día</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-arrow-trend-up" style="color:#00b300;margin-right:4px"></i>Entradas procesadas</div>
    </div>

    <div class="pro-kpi-card accent-purple" title="Ver tareas de picking" onclick="WMS.nav('picking','dashboard')">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-dolly"></i></div>
        <span class="pro-kpi-trend neu">Hoy</span>
      </div>
      <div class="pro-kpi-value" id="kpi-pickings">0</div>
      <div class="pro-kpi-label">Tareas de picking</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-list-check" style="color:#7c3aed;margin-right:4px"></i>Órdenes asignadas</div>
    </div>

    <div class="pro-kpi-card accent-amber" title="Ver mapa de ubicaciones" onclick="WMS.nav('almacenamiento','mapa')">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-location-dot"></i></div>
        <span class="pro-kpi-trend neu">Total</span>
      </div>
      <div class="pro-kpi-value" id="kpi-ubicaciones">0</div>
      <div class="pro-kpi-label">Ubicaciones de almacén</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-warehouse" style="color:#e8a000;margin-right:4px"></i>Slots disponibles</div>
    </div>

    <div class="pro-kpi-card accent-red" title="Ver referencias en alerta de stock bajo" onclick="WMS_MODULES.inicio._verAlertasStock()">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <span class="pro-kpi-trend ${alertas > 0 ? 'down' : 'up'}">${alertas > 0 ? 'Alerta' : 'OK'}</span>
      </div>
      <div class="pro-kpi-value" id="kpi-alertas">0</div>
      <div class="pro-kpi-label">Alertas de stock bajo</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-bell" style="color:#e03030;margin-right:4px"></i>Requieren reposición</div>
    </div>
  </div>

  <!-- CHARTS ROW -->
  <div class="pro-charts-grid">

    <!-- Línea: nivel de servicio -->
    <div class="pro-chart-card">
      <div class="pro-chart-title">
        <span><i class="fa-solid fa-chart-line" style="color:#0070f2;margin-right:8px"></i>Nivel de Servicio – Últimos 7 días</span>
        <span class="pro-chart-badge">% cumplimiento</span>
      </div>
      <div class="pro-chart-container" style="height:220px">
        <canvas id="chart-trend"></canvas>
      </div>
    </div>

    <!-- Donut Doble: Disponibilidad y Ocupación -->
    <div class="pro-chart-card">
      <div class="pro-chart-title">
        <span><i class="fa-solid fa-chart-pie" style="color:#7c3aed;margin-right:8px"></i>Estado del Inventario</span>
        <span class="pro-chart-badge">Métricas Reales</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:16px;min-height:220px;align-items:center">

        <!-- Columna 1: Disponibilidad -->
        <div>
          <div style="font-size:12px;font-weight:600;color:#6b7a99;margin-bottom:12px;text-align:center;text-transform:uppercase;letter-spacing:0.5px">Disponibilidad de Productos</div>
          <div style="display:flex;align-items:center;gap:16px">
            <div class="pro-donut-wrap" style="position:relative;width:120px;height:120px;flex-shrink:0">
              <canvas id="chart-inv-avail" width="120" height="120"></canvas>
              <div class="pro-donut-center" style="transform:translate(-50%,-50%) scale(0.8)">
                <span class="value" id="donut-pct-avail">–</span>
                <span class="label">catálogo</span>
              </div>
            </div>
            <div class="pro-legend" id="legend-avail" style="flex:1"></div>
          </div>
        </div>

        <!-- Columna 2: Ocupación -->
        <div>
          <div style="font-size:12px;font-weight:600;color:#6b7a99;margin-bottom:12px;text-align:center;text-transform:uppercase;letter-spacing:0.5px">Ocupación de Bodega</div>
          <div style="display:flex;align-items:center;gap:16px">
            <div class="pro-donut-wrap" style="position:relative;width:120px;height:120px;flex-shrink:0">
              <canvas id="chart-inv-occu" width="120" height="120"></canvas>
              <div class="pro-donut-center" style="transform:translate(-50%,-50%) scale(0.8)">
                <span class="value" id="donut-pct-occu">–</span>
                <span class="label">estantes</span>
              </div>
            </div>
            <div class="pro-legend" id="legend-occu" style="flex:1"></div>
          </div>
        </div>

      </div>
    </div>

  </div>

  <!-- Accesos directos -->
  <div class="pro-activity-card" style="max-height:none;margin-bottom:24px">
    <div class="pro-section-title">
      <span style="margin-left:12px"><i class="fa-solid fa-bolt" style="margin-right:8px;color:#e8a000"></i>Accesos Directos</span>
    </div>
    <div class="pro-quick-grid" style="margin-top:16px">
      <div class="pro-quick-card" onclick="WMS.nav('recepcion','landing')">
        <div class="pro-quick-icon" style="background:rgba(0,179,0,.1);color:#00b300"><i class="fa-solid fa-truck-ramp-box"></i></div>
        <div><div class="pro-quick-label">Recepción</div><div class="pro-quick-sub">Citas & recepciones</div></div>
      </div>
      <div class="pro-quick-card" onclick="WMS.nav('picking','dashboard')">
        <div class="pro-quick-icon" style="background:rgba(124,58,237,.1);color:#7c3aed"><i class="fa-solid fa-dolly"></i></div>
        <div><div class="pro-quick-label">Picking</div><div class="pro-quick-sub">Órdenes & planillas</div></div>
      </div>
      <div class="pro-quick-card" onclick="WMS.nav('inventario','stock')">
        <div class="pro-quick-icon" style="background:rgba(8,145,178,.1);color:#0891b2"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div><div class="pro-quick-label">Inventario</div><div class="pro-quick-sub">Stock & ubicaciones</div></div>
      </div>
      <div class="pro-quick-card" onclick="WMS.nav('almacenamiento','dashboard')">
        <div class="pro-quick-icon" style="background:rgba(232,160,0,.1);color:#e8a000"><i class="fa-solid fa-warehouse"></i></div>
        <div><div class="pro-quick-label">Almacenamiento</div><div class="pro-quick-sub">Traslados & celdas</div></div>
      </div>
      <div class="pro-quick-card" onclick="WMS.nav('maestro','productos')">
        <div class="pro-quick-icon" style="background:rgba(0,112,242,.1);color:#0070f2"><i class="fa-solid fa-box"></i></div>
        <div><div class="pro-quick-label">Maestros</div><div class="pro-quick-sub">Productos & proveed.</div></div>
      </div>
      <div class="pro-quick-card" onclick="WMS.nav('despacho','dashboard')">
        <div class="pro-quick-icon" style="background:rgba(224,48,48,.1);color:#e03030"><i class="fa-solid fa-truck-fast"></i></div>
        <div><div class="pro-quick-label">Despacho</div><div class="pro-quick-sub">Salidas & remisiones</div></div>
      </div>
    </div>
  </div>

  <!-- Matrices operativas: cada una separada, en su propia tarjeta -->
  <div style="display:flex;flex-direction:column;gap:16px">
    ${this._matrizCard('mz-sin-inv',  'fa-solid fa-triangle-exclamation', '#e03030', 'Sin Inventario (movimiento últimos 15 días)')}
    ${this._matrizCard('mz-patio',    'fa-solid fa-warehouse',            '#e8a000', 'En Patio sin ubicar')}
    ${this._matrizCard('mz-ajustes',  'fa-solid fa-pen-to-square',        '#7c3aed', 'Top 50 referencias con más ajustes')}
  </div>

</div>`);

    /* Animar KPI counters */
    this._animateCounter('kpi-productos',   productos);
    this._animateCounter('kpi-recepciones', recepciones);
    this._animateCounter('kpi-pickings',    pickings);
    this._animateCounter('kpi-ubicaciones', ubicaciones);
    this._animateCounter('kpi-alertas',     alertas);

    /* Renderizar gráficas */
    this._renderTrend(nsLabels, nsData, nsRefs, nsUnid);

    // Gráfica 1: Disponibilidad de Productos
    this._renderDonut('chart-inv-avail', 'legend-avail', 'donut-pct-avail', [
      { label: 'Disponible', val: invOk,    color: '#00b300' },
      { label: 'Bajo stock', val: invWarn,  color: '#e8a000' },
      { label: 'Agotado',    val: invEmpty, color: '#e03030' },
    ], 'catálogo');

    // Gráfica 2: Ocupación de Bodega
    this._renderDonut('chart-inv-occu', 'legend-occu', 'donut-pct-occu', [
      { label: 'Ocupadas', val: occOccupied, color: '#7c3aed' },
      { label: 'Vacías',   val: occEmpty,    color: '#f0f2f8' },
    ], 'estantes', true);

    /* Matrices operativas */
    this._loadMatrices();
  },

  /* ── Gráfica de nivel de servicio (línea, % 0-100) ───────────── */
  _renderTrend(labels, data, refs = [], unid = []) {
    if (typeof Chart === 'undefined') {
      console.warn('Chart.js no está disponible. No se pudo renderizar el nivel de servicio.');
      return;
    }
    const ctx = document.getElementById('chart-trend');
    if (!ctx) return;
    if (this._charts.trend) this._charts.trend.destroy();

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(0,179,0,.25)');
    gradient.addColorStop(1, 'rgba(0,179,0,.01)');

    this._charts.trend = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Nivel de Servicio',
          data,
          borderColor: '#00b300',
          backgroundColor: gradient,
          borderWidth: 2.5,
          pointBackgroundColor: '#00b300',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.4,
          fill: true,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1a2340',
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,.8)',
            padding: 10,
            cornerRadius: 8,
            callbacks: {
              label: ctx => ` ${ctx.parsed.y}% nivel de servicio`,
              afterLabel: ctx => {
                const i = ctx.dataIndex;
                const r = refs[i], u = unid[i];
                return (r != null && u != null) ? [` NS Referencias: ${r}%`, ` NS Unidades: ${u}%`] : [];
              },
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, color: '#6b7a99' },
            border: { display: false }
          },
          y: {
            beginAtZero: true,
            max: 100,
            grid: { color: '#f0f2f8', drawBorder: false },
            ticks: {
              callback: v => v + '%',
              font: { size: 11 },
              color: '#6b7a99',
              padding: 8,
            },
            border: { display: false, dash: [4,4] }
          }
        },
        interaction: { intersect: false, mode: 'index' },
        layout: { padding: { top: 22 } },
      },
      plugins: [{
        id: 'nsValueLabels',
        afterDatasetsDraw(chart) {
          const { ctx } = chart;
          const meta = chart.getDatasetMeta(0);
          ctx.save();
          ctx.font = '700 11px Arial, sans-serif';
          ctx.fillStyle = '#00800e';
          ctx.textAlign = 'center';
          meta.data.forEach((point, i) => {
            const v = chart.data.datasets[0].data[i];
            if (v == null) return;
            ctx.fillText(`${v}%`, point.x, point.y - 12);
          });
          ctx.restore();
        }
      }]
    });
  },

  /* ── Gráfica donut inventario (Universal) ──────────────────── */
  _renderDonut(canvasId, legendId, pctId, items, label, isBinary = false) {
    if (typeof Chart === 'undefined') return;
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    if (this._charts[canvasId]) this._charts[canvasId].destroy();

    const total = items.reduce((acc, i) => acc + i.val, 0) || 1;
    const okVal = items[0].val; // El primer item suele ser el "OK" o el "Ocupado"
    const rawPct = (okVal / total * 100);
    const pctOk = rawPct > 0 && rawPct < 1 ? rawPct.toFixed(1) : Math.round(rawPct);

    const pctEl = document.getElementById(pctId);
    if (pctEl) pctEl.textContent = pctOk + '%';

    /* Leyenda */
    const legEl = document.getElementById(legendId);
    if (legEl) {
      legEl.innerHTML = items.map(i => {
        const rp = (i.val / total * 100);
        const p = rp > 0 && rp < 1 ? rp.toFixed(1) : Math.round(rp);
        return `
        <div class="pro-legend-item" style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-bottom:2px">
            <span style="display:flex;align-items:center;gap:6px">
              <span class="pro-legend-dot" style="background:${i.color};width:8px;height:8px"></span>
              <span style="color:#6b7a99">${i.label}</span>
            </span>
            <span style="font-weight:700;color:#1a2340">${p}%</span>
          </div>
          <div class="pro-progress-bar-bg" style="height:4px;background:#f0f2f8;border-radius:4px;overflow:hidden">
            <div class="pro-progress-bar-fill" style="background:${i.color};width:${p}%;height:100%"></div>
          </div>
        </div>`;
      }).join('');
    }

    this._charts[canvasId] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: items.map(i => i.label),
        datasets: [{
          data: items.map(i => i.val || 0),
          backgroundColor: items.map(i => i.color),
          borderWidth: 0,
          hoverOffset: 4,
        }]
      },
      options: {
        responsive: false,
        cutout: '75%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1a2340',
            padding: 8,
            cornerRadius: 6,
          }
        },
        animation: { animateRotate: true, duration: 800 }
      }
    });
  },

  /* ── Matrices operativas (reemplazan Actividad Reciente) ─────── */

  /* Tarjeta colapsable vacía (skeleton) — el contenido llega vía _loadMatrices() */
  _matrizCard(id, iconClass, color, titulo, startCollapsed = false) {
    return `
      <div class="pro-table-card${startCollapsed ? ' collapsed' : ''}" id="${id}-card">
        <div class="pro-table-header" onclick="WMS_MODULES.inicio._toggleMatriz('${id}-card')">
          <div class="pro-table-header-left">
            <span class="pro-table-title"><i class="${iconClass}" style="margin-right:8px;color:${color}"></i>${titulo}</span>
            <span class="pro-table-count" id="${id}-count">…</span>
          </div>
          <span class="pro-table-toggle"><i class="fa-solid fa-chevron-down"></i></span>
        </div>
        <div class="pro-table-body">
          <div class="pro-table-wrap table-container-scroll" style="max-height:320px" id="${id}-wrap">
            <div class="pro-empty-state"><div class="icon">⏳</div><p>Cargando...</p></div>
          </div>
        </div>
      </div>`;
  },

  _toggleMatriz(cardId) {
    document.getElementById(cardId)?.classList.toggle('collapsed');
  },

  async _loadMatrices() {
    this._loadMatrizSinInventario();
    this._loadMatrizPatio();
    this._loadMatrizAjustes();
  },

  /* ── Tabla genérica con encabezado fijo (sticky) y orden asc/desc por columna ── */
  _mzState: {},

  _mzSetData(id, rows, columns, footerHtml = '') {
    this._mzState[id] = { rows, columns, footerHtml, sortKey: null, sortDir: 1 };
    this._mzRender(id);
  },

  _mzOrdenar(id, key) {
    const st = this._mzState[id];
    if (!st) return;
    if (st.sortKey === key) st.sortDir *= -1;
    else { st.sortKey = key; st.sortDir = 1; }
    this._mzRender(id);
  },

  _mzRender(id) {
    const st = this._mzState[id];
    const wrap = document.getElementById(`${id}-wrap`);
    if (!st || !wrap) return;

    let rows = st.rows.slice();
    if (st.sortKey) {
      const col = st.columns.find(c => c.key === st.sortKey);
      rows.sort((a, b) => {
        if (col?.numeric) {
          return ((parseFloat(a[st.sortKey]) || 0) - (parseFloat(b[st.sortKey]) || 0)) * st.sortDir;
        }
        const va = String(a[st.sortKey] ?? '').toLowerCase();
        const vb = String(b[st.sortKey] ?? '').toLowerCase();
        return va.localeCompare(vb) * st.sortDir;
      });
    }

    const thead = st.columns.map(c => {
      const activo = st.sortKey === c.key;
      const flecha = activo ? `<i class="fa-solid fa-arrow-${st.sortDir === 1 ? 'up' : 'down'}" style="margin-left:4px;font-size:.65rem"></i>` : '';
      return `<th style="text-align:${c.align};cursor:pointer;user-select:none" onclick="WMS_MODULES.inicio._mzOrdenar('${id}','${c.key}')" title="Ordenar">${c.label}${flecha}</th>`;
    }).join('');

    const tbody = rows.map(row => `<tr>${st.columns.map(c =>
      `<td style="text-align:${c.align}">${c.render ? c.render(row[c.key], row) : WMS.esc(row[c.key] ?? '-')}</td>`
    ).join('')}</tr>`).join('');

    wrap.innerHTML = `<table class="erp-table"><thead><tr>${thead}</tr></thead>`
      + `<tbody>${tbody}</tbody></table>${st.footerHtml || ''}`;
  },

  async _loadMatrizSinInventario() {
    const cols = [
      { key: 'codigo',            label: 'Código',         align: 'left'  },
      { key: 'nombre',            label: 'Producto',       align: 'left'  },
      { key: 'ultimo_movimiento', label: 'Últ. movimiento',align: 'left', render: v => v ? new Date(v).toLocaleString('es-CO') : '-' },
      { key: 'ultimo_tipo',       label: 'Últ. tipo',      align: 'left'  },
      { key: 'movimientos',       label: 'Movs. (15d)',    align: 'right', numeric: true },
    ];
    try {
      const r = await API.get('/dashboard/matriz-sin-inventario');
      const rows = r.data || r || [];
      this._pintarConteo('mz-sin-inv-count', rows.length, 'referencias');
      if (!rows.length) { document.getElementById('mz-sin-inv-wrap').innerHTML = this._matrizVacia('Todas las referencias con movimiento reciente tienen inventario'); return; }
      this._mzSetData('mz-sin-inv', rows, cols);
    } catch(e) { this._matrizError('mz-sin-inv-wrap', 'mz-sin-inv-count', e.message); }
  },

  async _loadMatrizPatio() {
    const cols = [
      { key: 'codigo_interno',   label: 'Código',   align: 'left'  },
      { key: 'producto_nombre',  label: 'Producto', align: 'left'  },
      { key: 'lote',             label: 'Lote',      align: 'left', render: v => WMS.esc(v || '-') },
      { key: 'cantidad',         label: 'Cantidad',  align: 'right', numeric: true },
      { key: 'numero_pallet',    label: 'Pallet',    align: 'left', render: v => WMS.esc(v || '-') },
      { key: 'created_at',       label: 'Ingreso',   align: 'left', render: v => v ? new Date(v).toLocaleDateString('es-CO') : '-' },
    ];
    const footer = `<div style="padding:8px 4px"><button class="btn btn-secondary btn-sm" onclick="WMS.nav('almacenamiento','ubicar')"><i class="fa-solid fa-arrow-right"></i> Ir a Ubicar Mercancía</button></div>`;
    try {
      const r = await API.get('/putaway/patio');
      const rows = r.data || r || [];
      this._pintarConteo('mz-patio-count', rows.length, 'líneas');
      if (!rows.length) { document.getElementById('mz-patio-wrap').innerHTML = this._matrizVacia('No hay inventario pendiente por ubicar en Patio'); return; }
      this._mzSetData('mz-patio', rows, cols, footer);
    } catch(e) { this._matrizError('mz-patio-wrap', 'mz-patio-count', e.message); }
  },

  async _loadMatrizAjustes() {
    const cols = [
      { key: 'codigo',         label: 'Código',        align: 'left'  },
      { key: 'nombre',         label: 'Producto',      align: 'left'  },
      { key: 'total_ajustes',  label: '# Ajustes',     align: 'right', numeric: true, render: v => `<strong>${v}</strong>` },
      { key: 'total_positivo', label: 'Total (+)',     align: 'right', numeric: true, render: v => `<span style="color:#00800e">+${v}</span>` },
      { key: 'total_negativo', label: 'Total (-)',     align: 'right', numeric: true, render: v => `<span style="color:#c00">-${v}</span>` },
      { key: 'ultimo_ajuste',  label: 'Último ajuste', align: 'left',  render: v => v ? new Date(v).toLocaleDateString('es-CO') : '-' },
    ];
    try {
      const r = await API.get('/dashboard/matriz-top-ajustes');
      const rows = r.data || r || [];
      this._pintarConteo('mz-ajustes-count', rows.length, 'referencias');
      if (!rows.length) { document.getElementById('mz-ajustes-wrap').innerHTML = this._matrizVacia('No se registran ajustes de inventario'); return; }
      this._mzSetData('mz-ajustes', rows, cols);
    } catch(e) { this._matrizError('mz-ajustes-wrap', 'mz-ajustes-count', e.message); }
  },

  _pintarConteo(id, n, label) {
    const el = document.getElementById(id);
    if (el) el.textContent = `${n} ${label}`;
  },

  _matrizVacia(msg) {
    return `<div class="pro-empty-state"><div class="icon">✅</div><p>${WMS.esc(msg)}</p></div>`;
  },

  _matrizError(wrapId, countId, msg) {
    this._pintarConteo(countId, 0, 'error');
    const wrap = document.getElementById(wrapId);
    if (wrap) wrap.innerHTML = `<div class="pro-empty-state"><div class="icon">⚠️</div><p>No se pudo cargar</p><small style="color:#94a3b8">${WMS.esc(msg || '')}</small></div>`;
  },

  /* ── Modal: detalle de alertas de stock bajo (tarjeta KPI roja) ─ */
  _verAlertasStock() {
    const rows = this._alertasList || [];
    const body = !rows.length
      ? this._matrizVacia('No hay referencias en alerta de stock bajo')
      : `<div class="pro-table-wrap" style="max-height:60vh;overflow-y:auto">
          <table class="erp-table">
            <thead><tr><th>Código</th><th>Producto</th><th>Stock actual</th><th>Stock mínimo</th></tr></thead>
            <tbody>${rows.map(x => `<tr>
              <td>${WMS.esc(x.codigo || '')}</td>
              <td>${WMS.esc(x.nombre || '')}</td>
              <td style="text-align:right;color:${x.total_stock <= 0 ? '#c00' : '#e8a000'}"><strong>${x.total_stock}</strong></td>
              <td style="text-align:right">${x.stock_minimo}</td>
            </tr>`).join('')}</tbody>
          </table>
        </div>`;
    WMS.showModal('Alertas de Stock Bajo', body, `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
  },

  /* ── Efecto de conteo para KPIs ──────────────────────────────── */
  _animateCounter(id, target) {
    const el = document.getElementById(id);
    if (!el) return;
    const duration = 1000;
    const startTime = performance.now();
    const update = (currentTime) => {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const easeOutQuad = t => t * (2 - t);
      el.textContent = Math.floor(easeOutQuad(progress) * target);
      if (progress < 1) requestAnimationFrame(update);
      else el.textContent = target;
    };
    requestAnimationFrame(update);
  },

};