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

  /* ── últimos 7 días en formato YYYY-MM-DD ────────────────────── */
  _last7() {
    const days = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      days.push(d.toISOString().slice(0, 10));
    }
    return days;
  },

  /* ── render principal ────────────────────────────────────────── */
  async render() {
    // Destruir gráficas anteriores si existieran
    this.destroy();

    WMS.spinner();
    let stats = {}, trend = [], d = {}, availability = {}, occupancy = {};
    try {
      const r = await API.get('/dashboard/summary');
      d = r.data || r;
      stats    = d.stats    || d;
      trend    = d.trend    || [];
      availability = d.availability || d.inv_state || {};
      occupancy    = d.occupancy    || {};
    } catch(e) { console.warn('dashboard/summary', e.message); }

    /* Extraer valores con fallbacks */
    const productos   = stats.productos   || stats.total_productos  || 0;
    const recepciones = stats.recepciones || stats.rec_hoy          || 0;
    const pickings    = stats.pickings     || stats.despachos   || stats.picking_hoy || 0;
    const alertas     = stats.alertas     || stats.bajo_stock       || 0;
    const ubicaciones = stats.ubicaciones || stats.total_ubicaciones || 0;

    /* Tendencia últimos 7 días */
    const days7 = this._last7();
    const trendData = days7.map(d => {
      const found = trend.find(t => t.fecha === d || t.date === d);
      return found ? (found.valor || found.cantidad || found.total || found.count || 0) : 0;
    });
    const trendLabels = days7.map(d => {
      const [,m,dd] = d.split('-');
      return `${dd}/${m}`;
    });

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

  <!-- BANNER -->
  <div class="pro-welcome-banner">
    <div>
      <h2><i class="fa-solid fa-warehouse" style="margin-right:10px;opacity:.9"></i>${saludo}, bienvenido al WMS</h2>
      <p><i class="fa-regular fa-calendar" style="margin-right:6px"></i>${now.charAt(0).toUpperCase() + now.slice(1)}</p>
    </div>
    <div class="pro-welcome-badge"><i class="fa-solid fa-circle-check" style="margin-right:6px;color:#7fffb2"></i>Sistema Operativo</div>
  </div>

  <!-- KPI GRID -->
  <div class="pro-kpi-grid">
    <div class="pro-kpi-card accent-blue">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
        <span class="pro-kpi-trend neu">Catálogo</span>
      </div>
      <div class="pro-kpi-value" id="kpi-productos">0</div>
      <div class="pro-kpi-label">Productos activos</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-circle-dot" style="color:#0070f2;margin-right:4px"></i>En maestro de artículos</div>
    </div>

    <div class="pro-kpi-card accent-green">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
        <span class="pro-kpi-trend up">Hoy</span>
      </div>
      <div class="pro-kpi-value" id="kpi-recepciones">0</div>
      <div class="pro-kpi-label">Recepciones del día</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-arrow-trend-up" style="color:#00b300;margin-right:4px"></i>Entradas procesadas</div>
    </div>

    <div class="pro-kpi-card accent-purple">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-dolly"></i></div>
        <span class="pro-kpi-trend neu">Hoy</span>
      </div>
      <div class="pro-kpi-value" id="kpi-pickings">0</div>
      <div class="pro-kpi-label">Tareas de picking</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-list-check" style="color:#7c3aed;margin-right:4px"></i>Órdenes asignadas</div>
    </div>

    <div class="pro-kpi-card accent-amber">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-location-dot"></i></div>
        <span class="pro-kpi-trend neu">Total</span>
      </div>
      <div class="pro-kpi-value" id="kpi-ubicaciones">0</div>
      <div class="pro-kpi-label">Ubicaciones de almacén</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-warehouse" style="color:#e8a000;margin-right:4px"></i>Slots disponibles</div>
    </div>

    <div class="pro-kpi-card accent-red">
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

    <!-- Línea: tendencia recepciones -->
    <div class="pro-chart-card">
      <div class="pro-chart-title">
        <span><i class="fa-solid fa-chart-line" style="color:#0070f2;margin-right:8px"></i>Recepciones – Últimos 7 días</span>
        <span class="pro-chart-badge">Tendencia</span>
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
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:16px">
        
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

  <!-- BOTTOM ROW: actividad + accesos directos -->
  <div class="pro-bottom-grid">

    <!-- Actividad reciente -->
    <div class="pro-activity-card">
      <div class="pro-section-title">
        <span style="margin-left:12px"><i class="fa-solid fa-clock-rotate-left" style="margin-right:8px;color:#0070f2"></i>Actividad Reciente</span>
      </div>
      <div class="pro-activity-list" id="activity-list">
        ${this._skeletonActivity()}
      </div>
    </div>

    <!-- Accesos directos -->
    <div class="pro-activity-card">
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

  </div>

</div>`);

    /* Animar KPI counters */
    this._animateCounter('kpi-productos',   productos);
    this._animateCounter('kpi-recepciones', recepciones);
    this._animateCounter('kpi-pickings',    pickings);
    this._animateCounter('kpi-ubicaciones', ubicaciones);
    this._animateCounter('kpi-alertas',     alertas);

    /* Renderizar gráficas */
    this._renderTrend(trendLabels, trendData);
    
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

    /* Actividad reciente */
    this._loadActivity();
  },

  /* ── Gráfica de tendencia (línea) ──────────────────────────── */
  _renderTrend(labels, data) {
    if (typeof Chart === 'undefined') {
      console.warn('Chart.js no está disponible. No se pudo renderizar la tendencia.');
      return;
    }
    const ctx = document.getElementById('chart-trend');
    if (!ctx) return;
    if (this._charts.trend) this._charts.trend.destroy();

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(0,112,242,.25)');
    gradient.addColorStop(1, 'rgba(0,112,242,.01)');

    this._charts.trend = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Recepciones',
          data,
          borderColor: '#0070f2',
          backgroundColor: gradient,
          borderWidth: 2.5,
          pointBackgroundColor: '#0070f2',
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
              label: ctx => ` ${ctx.parsed.y} recepciones`,
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
            grid: { color: '#f0f2f8', drawBorder: false },
            ticks: {
              stepSize: 1,
              font: { size: 11 },
              color: '#6b7a99',
              padding: 8,
            },
            border: { display: false, dash: [4,4] }
          }
        },
        interaction: { intersect: false, mode: 'index' },
      }
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

  /* ── Actividad reciente ────────────────────────────────────── */
  async _loadActivity() {
    let items = [];
    try {
      const r = await API.get('/dashboard/actividad');
      items = (r.data || r || []).slice(0, 10);
    } catch(_) {}

    const el = document.getElementById('activity-list');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = `<div class="pro-empty-state"><div class="icon">📭</div><p>Sin actividad reciente</p></div>`;
      return;
    }

    const colorMap = { recepcion:'blue', 'recepción':'blue', picking:'purple', despacho:'red', inventario:'teal', maestro:'green', ajuste:'amber' };
    const iconMap  = { recepcion:'fa-truck-ramp-box', 'recepción':'fa-truck-ramp-box', picking:'fa-dolly', despacho:'fa-truck-fast', inventario:'fa-boxes-stacked', maestro:'fa-box', ajuste:'fa-pen-to-square' };

    el.innerHTML = items.map(it => {
      const tipo  = (it.tipo || 'inventario').toLowerCase();
      const color = colorMap[tipo] || 'gray';
      const icon  = iconMap[tipo]  || 'fa-circle';
      const fecha = it.fecha ? new Date(it.fecha).toLocaleString('es-CO') : '';
      return `<div class="pro-activity-item activity-${color}">
        <i class="fa-solid ${icon} act-icon"></i>
        <div class="act-body">
          <span class="act-tipo">${it.tipo || 'Actividad'}</span>
          <span class="act-texto">${it.texto || ''}</span>
          <span class="act-fecha">${fecha}</span>
        </div>
      </div>`;
    }).join('');
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

  /* ── Skeleton loading para actividad ────────────────────────── */
  _skeletonActivity() {
    return Array(3).fill(0).map(() => `
      <div class="pro-activity-item" style="opacity:0.6;animation:pulse 1.5s infinite">
        <div style="width:32px;height:32px;border-radius:8px;background:#e2e8f0"></div>
        <div class="act-body" style="flex:1">
          <div style="width:40%;height:12px;background:#e2e8f0;border-radius:4px;margin-bottom:6px"></div>
          <div style="width:80%;height:10px;background:#f1f5f9;border-radius:4px"></div>
        </div>
      </div>
    `).join('');
  },
};