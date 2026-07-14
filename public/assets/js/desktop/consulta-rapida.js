/* ============================================================
   WMS Desktop — Módulo CONSULTA RÁPIDA DE PRODUCTO
   Vista única: búsqueda dinámica + tablero de inteligencia
   ============================================================ */
WMS_MODULES['consulta-rapida'] = {

  _chartComportamiento: null,
  _chartClientes: null,
  _debounceTimer: null,
  _productoActual: null,

  load() {
    WMS.setBreadcrumb('consulta-rapida', 'Consulta Rápida de Producto');
    if (typeof WMS.renderSidebar === 'function') WMS.renderSidebar('consulta-rapida');
    WMS.setToolbar('');
    this._chartComportamiento = null;
    this._chartClientes = null;
    this._productoActual = null;
    this._renderShell();
  },

  // ── HTML base ──────────────────────────────────────────────────────────────
  _renderShell() {
    WMS.setContent(`
<style>
.cr-wrap{padding:16px;display:flex;flex-direction:column;gap:14px;max-width:1400px;margin:0 auto;}
.cr-search-row{display:flex;gap:10px;align-items:center;position:relative;}
.cr-search-box{position:relative;flex:1;}
.cr-search-input{width:100%;padding:10px 14px 10px 40px;border:2px solid #e2e8f0;border-radius:10px;
  font-size:14px;color:#1e293b;background:#fff;outline:none;transition:border .2s;}
.cr-search-input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.cr-search-icon{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;}
.cr-dropdown{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1px solid #e2e8f0;
  border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:999;overflow:hidden;display:none;}
.cr-dropdown.open{display:block;}
.cr-drop-item{padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;align-items:center;}
.cr-drop-item:last-child{border-bottom:none;}
.cr-drop-item:hover{background:#f8fafc;}
.cr-drop-code{font-size:11px;font-weight:700;color:#2563eb;background:#eff6ff;padding:2px 7px;border-radius:5px;white-space:nowrap;}
.cr-drop-name{font-size:13px;color:#1e293b;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cr-drop-um{font-size:11px;color:#94a3b8;white-space:nowrap;}
.cr-drop-empty{padding:14px;text-align:center;color:#94a3b8;font-size:13px;}
.cr-btn-clear{padding:9px 16px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;
  color:#64748b;font-size:13px;cursor:pointer;white-space:nowrap;transition:all .2s;}
.cr-btn-clear:hover{background:#e2e8f0;color:#1e293b;}

.cr-prod-header{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 20px;
  display:none;align-items:center;gap:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.cr-prod-header.visible{display:flex;}
.cr-prod-badge{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:6px 14px;
  font-size:13px;font-weight:800;color:#1d4ed8;white-space:nowrap;}
.cr-prod-name{font-size:16px;font-weight:700;color:#1e293b;flex:1;}
.cr-prod-meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.cr-prod-chip{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;}
.cr-chip-um{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.cr-chip-min{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;}
.cr-chip-lote{background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;}

.cr-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.cr-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 16px;
  display:flex;align-items:center;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.cr-kpi-icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.cr-kpi-body{flex:1;min-width:0;}
.cr-kpi-val{font-size:22px;font-weight:800;line-height:1;color:#1e293b;}
.cr-kpi-label{font-size:10px;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cr-kpi-sub{font-size:10px;font-weight:600;margin-top:1px;}

.cr-chart-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.cr-card-title{font-size:13px;font-weight:700;color:#1e293b;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.cr-card-title i{color:#64748b;}
.cr-chart-wrap{position:relative;height:200px;}
.cr-no-data{display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:160px;gap:8px;color:#94a3b8;font-size:13px;}
.cr-no-data i{font-size:28px;opacity:.4;}

.cr-tables-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.cr-table-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.cr-table-wrap{max-height:200px;overflow-y:auto;}
.cr-table{width:100%;border-collapse:collapse;font-size:12px;}
.cr-table thead th{padding:6px 8px;text-align:left;font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #f1f5f9;
  position:sticky;top:0;background:#fff;z-index:1;}
.cr-table tbody td{padding:6px 8px;border-bottom:1px solid #f8fafc;color:#1e293b;}
.cr-table tbody tr:last-child td{border-bottom:none;}
.cr-table tbody tr:hover td{background:#f8fafc;}
.cr-lote-badge{display:inline-block;background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;
  border-radius:4px;padding:1px 6px;font-size:10px;font-weight:600;}
.cr-zona-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;}
.cr-zona-oro{background:#fefce8;color:#854d0e;border:1px solid #fde68a;}
.cr-zona-plata{background:#f8fafc;color:#475569;border:1px solid #cbd5e1;}
.cr-zona-bronce{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;}
.cr-zona-other{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}

.cr-skeleton{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);
  background-size:200% 100%;animation:cr-shimmer 1.4s infinite;border-radius:6px;}
@keyframes cr-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
</style>

<div class="cr-wrap" id="cr-wrap">

  <!-- Búsqueda -->
  <div class="cr-search-row">
    <div class="cr-search-box">
      <i class="fa-solid fa-magnifying-glass cr-search-icon"></i>
      <input type="text" class="cr-search-input" id="cr-input"
             placeholder="Buscar por referencia o nombre del producto..."
             autocomplete="off" oninput="WMS_MODULES['consulta-rapida']._onInput(this.value)">
      <div class="cr-dropdown" id="cr-dropdown"></div>
    </div>
    <button class="cr-btn-clear" onclick="WMS_MODULES['consulta-rapida']._limpiar()">
      <i class="fa-solid fa-xmark"></i> Limpiar
    </button>
  </div>

  <!-- Header producto -->
  <div class="cr-prod-header" id="cr-prod-header">
    <div class="cr-prod-badge" id="cr-prod-codigo">—</div>
    <div class="cr-prod-name" id="cr-prod-nombre">—</div>
    <div class="cr-prod-meta">
      <span class="cr-prod-chip cr-chip-um" id="cr-prod-um">UM: —</span>
      <span class="cr-prod-chip cr-chip-min" id="cr-prod-min" style="display:none;"></span>
      <span class="cr-prod-chip cr-chip-lote" id="cr-prod-lote" style="display:none;"></span>
    </div>
  </div>

  <!-- Dashboard (oculto hasta selección) -->
  <div id="cr-dashboard" style="display:none;flex-direction:column;gap:14px;">

    <!-- KPIs -->
    <div class="cr-kpi-grid" id="cr-kpis">
      ${['','','','','','','',''].map(()=>`<div class="cr-kpi-card"><div class="cr-skeleton" style="width:38px;height:38px;border-radius:9px;flex-shrink:0;"></div><div style="flex:1"><div class="cr-skeleton" style="height:22px;width:60%;margin-bottom:6px;"></div><div class="cr-skeleton" style="height:10px;width:80%;"></div></div></div>`).join('')}
    </div>

    <!-- Gráfico comportamiento 30d -->
    <div class="cr-chart-card">
      <div class="cr-card-title">
        <i class="fa-solid fa-chart-line"></i>
        Comportamiento de movimientos — últimos 30 días
      </div>
      <div class="cr-chart-wrap">
        <canvas id="cr-chart-comportamiento"></canvas>
        <div class="cr-no-data" id="cr-no-movimientos" style="display:none;">
          <i class="fa-solid fa-chart-line"></i>Sin movimientos en los últimos 30 días
        </div>
      </div>
    </div>

    <!-- Tablas lote + ubicación -->
    <div class="cr-tables-row">
      <div class="cr-table-card" id="cr-card-lotes">
        <div class="cr-card-title"><i class="fa-solid fa-tag"></i>Stock por Lote</div>
        <div class="cr-table-wrap">
          <table class="cr-table">
            <thead><tr><th>Lote</th><th>Vencimiento</th><th style="text-align:right">UND/TOTAL</th><th style="text-align:right">Cajas</th><th style="text-align:right">Sueltos</th><th style="text-align:right">Reservado</th></tr></thead>
            <tbody id="cr-tbody-lote"></tbody>
          </table>
        </div>
      </div>
      <div class="cr-table-card">
        <div class="cr-card-title"><i class="fa-solid fa-location-dot"></i>Stock por Ubicación</div>
        <div class="cr-table-wrap">
          <table class="cr-table">
            <thead><tr><th>Ubicación</th><th>Zona</th><th style="text-align:right">UND/TOTAL</th><th style="text-align:right">Reservado</th></tr></thead>
            <tbody id="cr-tbody-ubic"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Gráfico clientes -->
    <div class="cr-chart-card">
      <div class="cr-card-title">
        <i class="fa-solid fa-users"></i>
        Promedio de venta por cliente — últimos 30 días
      </div>
      <div class="cr-chart-wrap" style="height:240px;">
        <canvas id="cr-chart-clientes"></canvas>
        <div class="cr-no-data" id="cr-no-clientes" style="display:none;">
          <i class="fa-solid fa-users"></i>Sin ventas registradas en los últimos 30 días
        </div>
      </div>
    </div>

  </div><!-- /cr-dashboard -->

</div><!-- /cr-wrap -->
`);
    // Cerrar dropdown al hacer click fuera
    document.addEventListener('click', this._onDocClick.bind(this));
  },

  // ── Búsqueda ───────────────────────────────────────────────────────────────
  _onInput(val) {
    clearTimeout(this._debounceTimer);
    const dd = document.getElementById('cr-dropdown');
    if (!dd) return;
    if (val.length < 2) { dd.innerHTML = ''; dd.classList.remove('open'); return; }
    this._debounceTimer = setTimeout(() => this._buscar(val), 300);
  },

  async _buscar(q) {
    const dd = document.getElementById('cr-dropdown');
    if (!dd) return;
    dd.innerHTML = '<div class="cr-drop-empty"><i class="fa-solid fa-spinner fa-spin"></i> Buscando...</div>';
    dd.classList.add('open');
    try {
      const data = await API.get('/consulta-rapida/buscar', 'q=' + encodeURIComponent(q));
      const items = Array.isArray(data) ? data : (data?.data ?? []);
      if (!items.length) {
        dd.innerHTML = '<div class="cr-drop-empty"><i class="fa-solid fa-circle-xmark"></i> Sin resultados</div>';
        return;
      }
      dd.innerHTML = items.map(p => `
        <div class="cr-drop-item" onclick="WMS_MODULES['consulta-rapida']._seleccionar(${p.id})">
          <span class="cr-drop-code">${this._esc(p.codigo_interno)}</span>
          <span class="cr-drop-name">${this._esc(p.nombre)}</span>
          <span class="cr-drop-um">${this._esc(p.unidad_medida || 'UN')}</span>
        </div>`).join('');
    } catch (e) {
      dd.innerHTML = `<div class="cr-drop-empty" style="color:#dc2626;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${e.message}</div>`;
    }
  },

  async _seleccionar(productoId) {
    const dd = document.getElementById('cr-dropdown');
    if (dd) { dd.innerHTML = ''; dd.classList.remove('open'); }

    const dash = document.getElementById('cr-dashboard');
    if (dash) dash.style.display = 'flex';

    this._showSkeletonKPIs();
    try {
      const data = await API.get('/consulta-rapida/' + productoId);
      const d = data?.data ?? data;
      this._productoActual = d;
      this._renderHeader(d.producto);
      this._renderKPIs(d.inventario, d.kpis);
      this._renderComportamiento(d.movimientos_30d || []);
      this._renderTablaLote(d.producto, d.inventario?.por_lote || []);
      this._renderTablaUbic(d.inventario?.por_ubicacion || []);
      this._renderClientes(d.ventas_por_cliente || []);
    } catch (e) {
      WMS.toast?.('Error al cargar: ' + e.message, 'error');
    }
  },

  _limpiar() {
    const inp = document.getElementById('cr-input');
    const dd = document.getElementById('cr-dropdown');
    const hdr = document.getElementById('cr-prod-header');
    const dash = document.getElementById('cr-dashboard');
    if (inp) inp.value = '';
    if (dd) { dd.innerHTML = ''; dd.classList.remove('open'); }
    if (hdr) hdr.classList.remove('visible');
    if (dash) dash.style.display = 'none';
    if (this._chartComportamiento) { this._chartComportamiento.destroy(); this._chartComportamiento = null; }
    if (this._chartClientes) { this._chartClientes.destroy(); this._chartClientes = null; }
    this._productoActual = null;
  },

  _onDocClick(e) {
    const box = document.querySelector('.cr-search-box');
    const dd = document.getElementById('cr-dropdown');
    if (box && dd && !box.contains(e.target)) { dd.classList.remove('open'); }
  },

  // ── Renders ────────────────────────────────────────────────────────────────
  _renderHeader(p) {
    const hdr = document.getElementById('cr-prod-header');
    if (!hdr) return;
    document.getElementById('cr-prod-codigo').textContent = p.codigo_interno || '—';
    document.getElementById('cr-prod-nombre').textContent = p.nombre || '—';

    const umEl  = document.getElementById('cr-prod-um');
    const minEl = document.getElementById('cr-prod-min');
    const lotEl = document.getElementById('cr-prod-lote');
    umEl.textContent = 'UM: ' + (p.unidad_medida || 'UN');
    if (p.stock_minimo && parseFloat(p.stock_minimo) > 0) {
      minEl.textContent = 'Mín: ' + this._fmt(p.stock_minimo);
      minEl.style.display = '';
    } else { minEl.style.display = 'none'; }
    if (p.controla_lote) { lotEl.textContent = 'Controla Lote'; lotEl.style.display = ''; }
    else { lotEl.style.display = 'none'; }
    hdr.classList.add('visible');
  },

  _showSkeletonKPIs() {
    const el = document.getElementById('cr-kpis');
    if (!el) return;
    el.innerHTML = ['','','','','','','',''].map(() => `
      <div class="cr-kpi-card">
        <div class="cr-skeleton" style="width:38px;height:38px;border-radius:9px;flex-shrink:0;"></div>
        <div style="flex:1">
          <div class="cr-skeleton" style="height:22px;width:55%;margin-bottom:6px;"></div>
          <div class="cr-skeleton" style="height:10px;width:75%;"></div>
        </div>
      </div>`).join('');
  },

  _renderKPIs(inv, kpis) {
    const el = document.getElementById('cr-kpis');
    if (!el) return;
    const disp  = parseInt(inv?.total_disponible || 0);
    const res   = parseInt(inv?.total_reservado  || 0);
    const cuar  = parseInt(inv?.total_cuarentena || 0);
    const min   = parseFloat(this._productoActual?.producto?.stock_minimo || 0);
    const alertMin = min > 0 && disp < min;

    const cards = [
      { icon:'fa-boxes',              bg:'#f0fdf4', ic:'#16a34a', val: this._fmt(disp),  label:'UND/TOTAL',              sub: alertMin ? '<span style="color:#dc2626">⚠ Bajo mínimo</span>' : '' },
      { icon:'fa-lock',               bg:'#eff6ff', ic:'#2563eb', val: this._fmt(res),   label:'Stock Reservado',         sub: '' },
      { icon:'fa-hourglass-half',     bg:'#fff7ed', ic:'#ea580c', val: this._fmt(cuar),  label:'En Cuarentena',           sub: '' },
      { icon:'fa-arrow-down-wide-short', bg:'#f8fafc', ic:'#64748b', val: min > 0 ? this._fmt(min) : '—', label:'Stock Mínimo configurado', sub: '' },
      { icon:'fa-cart-shopping',      bg:'#ecfeff', ic:'#0891b2', val: this._fmt(kpis?.promedio_por_pedido || 0), label:'Promedio / Pedido (30d)',  sub: 'unidades' },
      { icon:'fa-truck-ramp-box',     bg:'#f0fdf4', ic:'#16a34a', val: this._fmt(kpis?.promedio_ingreso   || 0), label:'Promedio por Ingreso (30d)', sub: 'unidades' },
      { icon:'fa-file-invoice',       bg:'#eff6ff', ic:'#2563eb', val: this._fmt(kpis?.total_pedidos_30d  || 0), label:'Pedidos completados 30d',  sub: kpis?.unidades_vendidas_30d ? this._fmt(kpis.unidades_vendidas_30d) + ' und' : '' },
      { icon:'fa-chart-line',         bg:'#fff7ed', ic:'#ea580c', val: this._fmt(kpis?.unidades_vendidas_30d || 0), label:'Unidades vendidas 30d',   sub: kpis?.total_ingresos_30d ? kpis.total_ingresos_30d + ' ingresos' : '' },
    ];

    el.innerHTML = cards.map(c => `
      <div class="cr-kpi-card">
        <div class="cr-kpi-icon" style="background:${c.bg};color:${c.ic};">
          <i class="fa-solid ${c.icon}"></i>
        </div>
        <div class="cr-kpi-body">
          <div class="cr-kpi-val" style="color:${c.ic};">${c.val}</div>
          <div class="cr-kpi-label">${c.label}</div>
          ${c.sub ? `<div class="cr-kpi-sub" style="color:${c.ic};">${c.sub}</div>` : ''}
        </div>
      </div>`).join('');
  },

  _renderComportamiento(movs) {
    const canvas = document.getElementById('cr-chart-comportamiento');
    const noData = document.getElementById('cr-no-movimientos');
    if (!canvas) return;

    if (this._chartComportamiento) { this._chartComportamiento.destroy(); this._chartComportamiento = null; }

    if (!movs || !movs.length) {
      canvas.style.display = 'none';
      if (noData) noData.style.display = 'flex';
      return;
    }
    canvas.style.display = '';
    if (noData) noData.style.display = 'none';

    const labels   = movs.map(m => this._fmtFecha(m.fecha));
    const entradas = movs.map(m => parseInt(m.entradas) || 0);
    const salidas  = movs.map(m => parseInt(m.salidas)  || 0);

    this._chartComportamiento = new Chart(canvas, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Entradas',
            data: entradas,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22,163,74,.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 3,
            pointBackgroundColor: '#16a34a',
            borderWidth: 2,
          },
          {
            label: 'Salidas',
            data: salidas,
            borderColor: '#ea580c',
            backgroundColor: 'rgba(234,88,12,.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 3,
            pointBackgroundColor: '#ea580c',
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'top',
            labels: { font: { size: 11 }, boxWidth: 12, padding: 12 },
          },
          tooltip: {
            backgroundColor: '#1e293b',
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,.8)',
            padding: 10,
            cornerRadius: 8,
            callbacks: { label: c => ` ${this._fmt(c.parsed.y)} unidades` },
          },
        },
        scales: {
          x: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#64748b', maxRotation: 45 }, border: { display: false } },
          y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#64748b' }, border: { display: false } },
        },
      },
    });
  },

  _renderTablaLote(producto, lotes) {
    const tbody = document.getElementById('cr-tbody-lote');
    const card  = document.getElementById('cr-card-lotes');
    if (!tbody) return;

    if (!producto?.controla_lote) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">
        <i class="fa-solid fa-circle-info"></i> Este producto no controla lote</td></tr>`;
      return;
    }
    if (!lotes.length) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">Sin lotes en inventario</td></tr>`;
      return;
    }

    tbody.innerHTML = lotes.map(l => {
      const fv   = l.fecha_vencimiento ? new Date(l.fecha_vencimiento + 'T00:00:00') : null;
      const dias = fv ? Math.round((fv - Date.now()) / 86400000) : null;
      const fvColor = dias !== null ? (dias < 0 ? '#dc2626' : dias <= 15 ? '#ea580c' : dias <= 30 ? '#d97706' : '#16a34a') : '#64748b';
      const hasCajas = parseInt(l.cantidad_cajas || 0) > 0;
      const upc = parseInt(l.unidades_caja || 1);
      const saldos = parseFloat(l.saldos || 0);
      const cajas = parseInt(l.cantidad_cajas || 0);
      const desgloseTitle = hasCajas ? `${cajas} cajas × ${upc} u/e + ${saldos} sueltos` : '';
      return `<tr>
        <td><span class="cr-lote-badge">${this._esc(l.lote || '—')}</span></td>
        <td style="color:${fvColor};font-size:11px;">
          ${fv ? fv.toLocaleDateString('es-CO', {day:'2-digit',month:'short',year:'numeric'}) : '—'}
          ${dias !== null ? `<br><span style="font-size:10px;">(${dias}d)</span>` : ''}
        </td>
        <td style="text-align:right;font-weight:700;color:#16a34a;">
          ${hasCajas
            ? `<span title="${desgloseTitle}" style="cursor:help">${this._fmt(l.cantidad)}</span>
               <div style="font-size:9px;color:#64748b">${cajas}×${upc}+${saldos}</div>`
            : this._fmt(l.cantidad)}
        </td>
        <td style="text-align:right;color:#0070f2;">${hasCajas ? cajas : '—'}</td>
        <td style="text-align:right;color:#6d28d9;">${hasCajas ? saldos : '—'}</td>
        <td style="text-align:right;color:#2563eb;">${this._fmt(l.cantidad_reservada)}</td>
      </tr>`;
    }).join('');
  },

  _renderTablaUbic(ubics) {
    const tbody = document.getElementById('cr-tbody-ubic');
    if (!tbody) return;
    if (!ubics.length) {
      tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;">Sin stock en ubicaciones</td></tr>`;
      return;
    }
    tbody.innerHTML = ubics.map(u => {
      const zona = (u.zona || '').toLowerCase();
      const zonaCls = zona === 'oro' ? 'cr-zona-oro' : zona === 'plata' ? 'cr-zona-plata' : zona === 'bronce' ? 'cr-zona-bronce' : 'cr-zona-other';
      return `<tr>
        <td style="font-weight:700;color:#1e293b;">${this._esc(u.ubicacion_codigo || '—')}</td>
        <td>${u.zona ? `<span class="cr-zona-badge ${zonaCls}">${this._esc(u.zona)}</span>` : '—'}</td>
        <td style="text-align:right;font-weight:700;color:#16a34a;">${this._fmt(u.cantidad)}</td>
        <td style="text-align:right;color:#2563eb;">${this._fmt(u.cantidad_reservada)}</td>
      </tr>`;
    }).join('');
  },

  _renderClientes(clientes) {
    const canvas = document.getElementById('cr-chart-clientes');
    const noData = document.getElementById('cr-no-clientes');
    if (!canvas) return;

    if (this._chartClientes) { this._chartClientes.destroy(); this._chartClientes = null; }

    if (!clientes || !clientes.length) {
      canvas.style.display = 'none';
      if (noData) noData.style.display = 'flex';
      return;
    }
    canvas.style.display = '';
    if (noData) noData.style.display = 'none';

    const labels = clientes.map(c => (c.cliente || 'Sin identificar').substring(0, 30));
    const totales = clientes.map(c => parseInt(c.total_unidades) || 0);
    const promedios = clientes.map(c => parseFloat(c.promedio_por_pedido) || 0);

    this._chartClientes = new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          {
            label: 'Total unidades',
            data: totales,
            backgroundColor: 'rgba(37,99,235,.8)',
            borderRadius: 5,
            borderSkipped: false,
          },
          {
            label: 'Prom / pedido',
            data: promedios,
            backgroundColor: 'rgba(22,163,74,.7)',
            borderRadius: 5,
            borderSkipped: false,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 12 } },
          tooltip: {
            backgroundColor: '#1e293b',
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,.8)',
            padding: 10,
            cornerRadius: 8,
            callbacks: { label: c => ` ${this._fmt(c.parsed.x)} unidades` },
          },
        },
        scales: {
          x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#64748b' }, border: { display: false } },
          y: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#1e293b' }, border: { display: false } },
        },
      },
    });
  },

  // ── Utilidades ─────────────────────────────────────────────────────────────
  _fmt(n) {
    return new Intl.NumberFormat('es-CO').format(Math.round(Number(n) || 0));
  },
  _fmtFecha(f) {
    if (!f) return '—';
    try { return new Date(f + 'T00:00:00').toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }); }
    catch { return f; }
  },
  _esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  },
};

// Alias de compatibilidad (por si init(container) es llamado externamente)
window.ConsultaRapida = {
  init() { WMS_MODULES['consulta-rapida'].load(); },
};

if (typeof WMS !== 'undefined' && typeof WMS.registerModule === 'function') {
  WMS.registerModule('consulta-rapida', ConsultaRapida);
}
