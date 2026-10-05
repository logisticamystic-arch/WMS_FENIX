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
    sucursales: [],
    fotos:      [],
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
    if (sub === 'dashboard')   return this.loadDevoluciones();
    if (sub === 'lista')       return this.showLista();
    if (sub === 'causales')    return this.showCausales();
    if (sub === 'estados')     return this.showEstadosCRM();
    if (sub === 'auxiliares')  return this.showAuxiliaresCalidad();
    if (sub === 'nueva')       return this.showFormDevolucion();
    if (sub === 'recogidas')   return this.showRecogidas();
    if (sub === 'recogida-nueva') return this.showRecogidaForm();
    return this.loadDevoluciones();
  },

  /* ─────────────────────────────────────────────────────────────────────────
     NAVEGACIÓN — barra de pestañas presente en todas las vistas
  ───────────────────────────────────────────────────────────────────────── */
  _navBar(activa) {
    const tabs = [
      { id: 'dashboard',  label: 'Dashboard KPI',    icon: 'fa-chart-pie' },
      { id: 'lista',      label: 'Listado',           icon: 'fa-list' },
      { id: 'causales',   label: 'Causales',          icon: 'fa-tags' },
      { id: 'estados',    label: 'Estados CRM',       icon: 'fa-list-check' },
      { id: 'auxiliares', label: 'Auxiliares Calidad',icon: 'fa-user-shield' },
      { id: 'nueva',      label: 'Nueva Devolución',  icon: 'fa-plus-circle' },
      { id: 'recogidas',  label: 'Recogidas PDV',     icon: 'fa-truck-arrow-right' },
      { id: 'recogida-nueva', label: 'Nueva Recogida', icon: 'fa-file-circle-plus' },
    ];
    return `
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;width:100%;">
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
          ${tabs.map(t => `
            <button class="btn btn-sm ${activa === t.id ? 'btn-primary' : 'btn-secondary'}"
              onclick="WMS_MODULES.devoluciones.load('${t.id}')">
              <i class="fa-solid ${t.icon}"></i> ${t.label}
            </button>`).join('')}
        </div>
        <div style="display:flex;gap:4px;align-items:center;">
          <input type="number" id="dv-nav-consecutivo" class="form-control form-control-sm" placeholder="# Consecutivo"
            style="width:120px;" onkeydown="if(event.key==='Enter'){WMS_MODULES.devoluciones._buscarPorConsecutivoNav();event.preventDefault();}">
          <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._buscarPorConsecutivoNav()" title="Buscar devolución por consecutivo">
            <i class="fa-solid fa-magnifying-glass"></i>
          </button>
        </div>
      </div>`;
  },

  /* Búsqueda rápida por consecutivo, disponible desde cualquier vista del módulo */
  async _buscarPorConsecutivoNav() {
    const input = document.getElementById('dv-nav-consecutivo');
    const num = parseInt(input?.value || '');
    if (!num || num <= 0) { WMS.toast('error', 'Ingrese un número de consecutivo válido'); return; }
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/buscar-consecutivo?consecutivo=' + num);
      WMS.spinnerHide();
      if (r.error) { WMS.toast('error', r.message || `No se encontró la devolución #${num}`); return; }
      const d = r.data;
      if (!d?.id) { WMS.toast('error', `No se encontró la devolución #${num}`); return; }
      this.showDetalle(d.id);
    } catch(e) {
      WMS.spinnerHide();
      WMS.toast('error', 'Error al buscar por consecutivo');
    }
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
    const hasta = WMS.getToday();

    /* Skeleton mientras carga */
    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <!-- Filtros -->
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Desde</label>
              <input type="date" id="dvd-desde" class="form-control form-control-sm" value="${desde}" onchange="WMS_MODULES.devoluciones._aplicarDashboard()">
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Hasta</label>
              <input type="date" id="dvd-hasta" class="form-control form-control-sm" value="${hasta}" onchange="WMS_MODULES.devoluciones._aplicarDashboard()">
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Causal</label>
              <select id="dvd-causal" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.devoluciones._aplicarDashboard()">
                <option value="">Todas las causales</option>
              </select>
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Responsable</label>
              <select id="dvd-responsable" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.devoluciones._aplicarDashboard()">
                <option value="">Todos los responsables</option>
              </select>
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Consecutivo</label>
              <input type="number" id="dvd-consecutivo" class="form-control form-control-sm" placeholder="# Consecutivo" style="width:120px;" oninput="WMS_MODULES.devoluciones._aplicarDashboardDebounced()">
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Referencia</label>
              <input type="text" id="dvd-referencia" class="form-control form-control-sm" placeholder="N° referencia ERP" style="min-width:140px;" oninput="WMS_MODULES.devoluciones._aplicarDashboardDebounced()">
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
              <div style="background:var(--btn-neutral);border-radius:8px;height:60px;animation:pulse 1.5s ease-in-out infinite;"></div>
            </div>`).join('')}
        </div>

        <!-- Gráficos Fila 1 -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
              <span><i class="fa-solid fa-chart-line" style="margin-right:6px;color:var(--accent);"></i> Devoluciones por fecha</span>
              <span style="display:flex;gap:4px;">
                <button class="btn btn-xs btn-primary" id="dvd-hist-btn-referencias" onclick="WMS_MODULES.devoluciones._toggleHistoricoMetric('referencias')">Referencias</button>
                <button class="btn btn-xs btn-outline-secondary" id="dvd-hist-btn-unidades" onclick="WMS_MODULES.devoluciones._toggleHistoricoMetric('unidades')">Unidades</button>
              </span>
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="dvd-chart-historico"></canvas>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-chart-pie" style="margin-right:6px;color:#7c3aed;"></i> Distribución por causal
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="dvd-chart-causales"></canvas>
            </div>
          </div>
        </div>

        <!-- Gráficos Fila 2 -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-building" style="margin-right:6px;color:#00b300;"></i> Devoluciones por Sucursal
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="dvd-chart-sucursales"></canvas>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
              <span><i class="fa-solid fa-table-cells" style="margin-right:6px;color:var(--warn);"></i> Matriz de Productos Devueltos</span>
              <span class="badge" style="background:var(--warn-bg);color:var(--warn);" id="dvd-matriz-count">0 Refs</span>
            </div>
            <div style="height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;">
              <table class="erp-table" style="margin:0;width:100%;font-size:11px;">
                <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;">
                  <tr>
                    <th>Referencia / Producto</th>
                    <th class="text-center">Veces</th>
                    <th class="text-center">Unidades</th>
                    <th class="text-center" title="Participación por cantidad de veces devuelto (no por volumen en unidades)">% Part. (Nº veces)</th>
                  </tr>
                </thead>
                <tbody id="dvd-matriz-productos">
                  <tr><td colspan="4" class="text-center" style="padding:20px;color:var(--text-muted);">Cargando...</td></tr>
                </tbody>
              </table>
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

    /* Cargar causales y responsables (auxiliares de calidad) para los selects */
    this._cargarCausalesSelect('dvd-causal');
    this._cargarResponsablesSelect('dvd-responsable');

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

  /* Filtro dinámico de "Responsable" — a pedido explícito (2026-09-17) debe
     permitir SELECCIONAR, no escribir texto libre; se llena del mismo
     catálogo de Auxiliares de Calidad usado en el tracking CRM. */
  async _cargarResponsablesSelect(selectId) {
    try {
      const r = await API.get('/devoluciones/auxiliares-calidad?activo=1');
      const auxiliares = r.data || [];
      const sel = document.getElementById(selectId);
      if (!sel) return;
      const current = sel.value;
      const extras = auxiliares.map(a =>
        `<option value="${WMS.esc(a.nombre)}" ${current === a.nombre ? 'selected' : ''}>${WMS.esc(a.nombre)}</option>`
      ).join('');
      sel.innerHTML = `<option value="">Todos los responsables</option>${extras}`;
    } catch(e) { /* silencioso */ }
  },

  _aplicarDashboardDebounced() {
    clearTimeout(this._dashboardFilterTimer);
    this._dashboardFilterTimer = setTimeout(() => this._aplicarDashboard(), 400);
  },

  async _aplicarDashboard() {
    try {
      const params = new URLSearchParams();
      const desde       = document.getElementById('dvd-desde')?.value;
      const hasta        = document.getElementById('dvd-hasta')?.value;
      const causal       = document.getElementById('dvd-causal')?.value;
      const responsable  = document.getElementById('dvd-responsable')?.value;
      const consecutivo  = document.getElementById('dvd-consecutivo')?.value;
      const referencia   = document.getElementById('dvd-referencia')?.value;
      if (desde)       params.set('fecha_desde', desde);
      if (hasta)        params.set('fecha_hasta', hasta);
      if (causal)       params.set('causal_id', causal);
      if (responsable)  params.set('responsable', responsable);
      if (consecutivo)  params.set('consecutivo', consecutivo);
      if (referencia)   params.set('referencia', referencia);

      const rStats = await API.get('/devoluciones/dashboard-stats?' + params.toString());
      const stats = rStats.data || {};

      this._renderKPIsStats(stats);
      this._renderChartsStats(stats);

      const rUltimas = await API.get('/devoluciones?limit=30&' + params.toString());
      this._renderTablaUltimas(rUltimas.data || []);
    } catch(e) {
      WMS.toast('error', 'Error al cargar dashboard');
    }
  },

  _renderKPIsStats(stats) {
    const el = document.getElementById('dvd-kpis');
    if (!el) return;
    
    const estados = stats.estados || [];
    const aprobadas = estados.find(e => e.estado === 'Aprobada')?.total || 0;
    const procesadas = estados.find(e => e.estado === 'Procesada')?.total || 0;
    const pendientes = estados.find(e => e.estado === 'PendienteAprobacion')?.total || 0;
    const anuladas = estados.find(e => e.estado === 'Anulada')?.total || 0;
    const total = estados.reduce((sum, e) => sum + e.total, 0);

    const cards = [
      { label: 'Total Devoluciones', value: total, sub: 'Últimos 30 días', icon: 'fa-rotate-left', accent: 'accent-blue' },
      { label: 'Pendientes Aprob.', value: pendientes, sub: 'Requieren revisión', icon: 'fa-clock', accent: 'accent-amber' },
      { label: 'Procesadas', value: procesadas, sub: 'Ya en inventario', icon: 'fa-check-double', accent: 'accent-green' },
      { label: 'Anuladas', value: anuladas, sub: 'Canceladas', icon: 'fa-ban', accent: 'accent-gray' },
    ];

    el.innerHTML = cards.map(c => `
      <div class="pro-kpi-card ${c.accent}">
        <div class="pro-kpi-header">
          <div class="pro-kpi-icon"><i class="fa-solid ${c.icon}"></i></div>
        </div>
        <div class="pro-kpi-value">${WMS.formatNum ? WMS.formatNum(c.value) : c.value}</div>
        <div class="pro-kpi-label">${c.label}</div>
        <div class="pro-kpi-sub">${c.sub}</div>
      </div>`).join('');
  },

  _chartInstances: {},
  _paletteVivid: ['#2b5fc7','#e03030','#e8a000','#00b300','#7c3aed','#0891b2','#c026d3','#64748b'],
  _renderChartsStats(stats) {
    this._lastStats = stats; // para poder re-dibujar el histórico al alternar Referencias/Unidades
    const destroyChart = (id) => {
      if (this._chartInstances[id]) {
        this._chartInstances[id].destroy();
      }
    };

    // 1. Histórico por fecha (Líneas) — por defecto "Referencias" (cantidad de
    // productos distintos devueltos por día); alterna a "Unidades" con los
    // botones del encabezado (ver _toggleHistoricoMetric).
    this._historicoMetric = this._historicoMetric || 'referencias';
    this._dibujarHistorico(stats.historico || []);

    // 2. Por Causal (Doughnut) — agrupado por la causal real del encabezado
    // (devoluciones.causal_devolucion_id), no por el motivo fijo de la línea.
    const ctxCausal = document.getElementById('dvd-chart-causales');
    if (ctxCausal) {
      destroyChart('causal');
      this._chartInstances['causal'] = new Chart(ctxCausal, {
        type: 'doughnut',
        data: {
          labels: (stats.causales || []).map(d => d.causal),
          datasets: [{
            data: (stats.causales || []).map(d => d.total),
            backgroundColor: this._paletteVivid
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { position: 'right' },
            datalabels: { display: true, color: '#fff', font: { weight: '700', size: 12 }, formatter: v => v, clamp: true }
          }
        }
      });
    }

    // 3. Por Sucursal (Barras) — muestra todas las sucursales de la empresa
    // con devoluciones en el período; si solo aparece una barra es porque la
    // empresa solo tiene una sucursal con movimientos, no un filtro del código.
    const ctxSuc = document.getElementById('dvd-chart-sucursales');
    if (ctxSuc) {
      destroyChart('suc');
      this._chartInstances['suc'] = new Chart(ctxSuc, {
        type: 'bar',
        data: {
          labels: (stats.por_sucursal || []).map(d => d.nombre),
          datasets: [{
            label: 'Devoluciones',
            data: (stats.por_sucursal || []).map(d => d.total),
            backgroundColor: this._paletteVivid
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            datalabels: { display: true, color: '#fff', anchor: 'end', align: 'start', offset: 4, font: { weight: '700', size: 12 }, formatter: v => v, clamp: true }
          }
        }
      });
    }

    // 4. Matriz de Productos (Tabla en lugar de gráfico)
    const tbMatriz = document.getElementById('dvd-matriz-productos');
    const elCount = document.getElementById('dvd-matriz-count');
    if (tbMatriz) {
      const matriz = stats.matriz_productos || [];
      if (elCount) elCount.textContent = matriz.length + ' Refs';
      
      if (!matriz.length) {
        tbMatriz.innerHTML = '<tr><td colspan="4" class="text-center" style="padding:20px;color:var(--text-muted);">Sin datos en el período</td></tr>';
      } else {
        tbMatriz.innerHTML = matriz.map(m => `
          <tr>
            <td>
              <strong style="color:var(--text);">${WMS.esc(m.codigo_interno)}</strong><br>
              <span style="color:var(--muted);font-size:10px;">${WMS.esc(m.nombre)}</span>
            </td>
            <td class="text-center"><span class="badge" style="background:var(--info-bg);color:var(--info);">${m.veces_devuelto}</span></td>
            <td class="text-center"><b>${m.total_unidades}</b></td>
            <td class="text-center">
              <div style="display:flex;align-items:center;gap:6px;">
                <div style="flex:1;height:4px;background:var(--border);border-radius:2px;overflow:hidden;">
                  <div style="height:100%;background:var(--warn);width:${m.porcentaje_participacion}%;"></div>
                </div>
                <span style="font-weight:600;min-width:35px;text-align:right;">${m.porcentaje_participacion}%</span>
              </div>
            </td>
          </tr>
        `).join('');
      }
    }
  },

  /* Dibuja/re-dibuja el gráfico "Devoluciones por fecha" con la métrica activa
     (referencias o unidades) — llamado al cargar el dashboard y al alternar. */
  _dibujarHistorico(historico) {
    const ctxHist = document.getElementById('dvd-chart-historico');
    if (!ctxHist) return;
    if (this._chartInstances['hist']) this._chartInstances['hist'].destroy();

    const esUnidades = this._historicoMetric === 'unidades';
    this._chartInstances['hist'] = new Chart(ctxHist, {
      type: 'line',
      data: {
        labels: historico.map(d => d.fecha),
        datasets: [{
          label: esUnidades ? 'Unidades devueltas' : 'Referencias devueltas',
          data: historico.map(d => esUnidades ? d.unidades : d.referencias),
          borderColor: '#2b5fc7',
          backgroundColor: 'rgba(0,112,242,0.1)',
          fill: true,
          tension: 0.3
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        layout: { padding: { top: 18 } },
        plugins: {
          legend: { display: false },
          datalabels: { display: true, color: '#2b5fc7', align: 'top', font: { weight: '700', size: 11 }, formatter: v => v, clamp: true }
        }
      }
    });
  },

  _toggleHistoricoMetric(metric) {
    this._historicoMetric = metric;
    const btnRef = document.getElementById('dvd-hist-btn-referencias');
    const btnUnd = document.getElementById('dvd-hist-btn-unidades');
    if (btnRef) btnRef.className = 'btn btn-xs ' + (metric === 'referencias' ? 'btn-primary' : 'btn-outline-secondary');
    if (btnUnd) btnUnd.className = 'btn btn-xs ' + (metric === 'unidades' ? 'btn-primary' : 'btn-outline-secondary');
    this._dibujarHistorico((this._lastStats?.historico) || []);
  },

  _renderTablaUltimas(rows) {
    const el = document.getElementById('dvd-tabla');
    if (!el) return;
    const badgeColor = {
      PendienteAprobacion:'#f59e0b', Aprobada:'#3b82f6', Procesada:'#10b981',
      Rechazada:'#ef4444', Anulada:'#94a3b8', Borrador:'#64748b',
    };
    if (!rows.length) {
      el.innerHTML = '<p style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px;">Sin registros en el período seleccionado.</p>';
      return;
    }
    el.innerHTML = `
      <table class="erp-table">
        <thead><tr>
          <th>Consecutivo</th><th>N° Interno</th><th>Fecha</th><th>Proveedor / Cliente</th><th>Sucursal Origen</th>
          <th>Causal</th><th>Responsable</th><th>Ref</th>
          <th class="text-center">Cant.</th><th>Estado</th>
        </tr></thead>
        <tbody>
          ${rows.map(d => {
            const color = badgeColor[d.estado] || '#94a3b8';
            const cons  = d.consecutivo_devolucion ? ('#' + d.consecutivo_devolucion) : '-';
            return `<tr style="cursor:pointer;" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
              <td><span class="badge" style="background:var(--accent);color:#fff;font-weight:700;font-size:12px;">${WMS.esc(cons)}</span></td>
              <td><strong>${WMS.esc(d.numero_devolucion || ('#' + d.id))}</strong></td>
              <td style="font-size:11px;">${(d.created_at||'').substring(0,10) || '-'}</td>
              <td>${WMS.esc(d.tercero_nombre || d.tercero || d.cliente || d.proveedor || '-')}</td>
              <td style="font-size:11px;">${WMS.esc(d.sucursal_origen?.nombre || d.sucursal_origen_nombre || '-')}</td>
              <td style="font-size:11px;">${WMS.esc(d.causal?.causal || d.causal || d.motivo_general || '-')}</td>
              <td style="font-size:11px;">${WMS.esc(d.responsable_devolucion || d.responsable || d.solicitado_por_nombre || '-')}</td>
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
      PendienteAprobacion:'#f59e0b', Aprobada:'#3b82f6', Procesada:'#10b981',
      Rechazada:'#ef4444', Anulada:'#94a3b8', Borrador:'#64748b',
    };
    const tipoLabel = {
      BuenEstado:'Buen Estado', MalEstado:'Mal Estado',
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
            <input type="number" id="dv-f-consecutivo" class="form-control form-control-sm" placeholder="N° Consecutivo" style="width:120px;" oninput="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="text" id="dv-f-q" class="form-control form-control-sm" placeholder="Buscar Ref. interna/externa..." style="min-width:180px;"
              oninput="WMS_MODULES.devoluciones._aplicarFiltros()">
            <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._exportarCSVLista()">
              <i class="fa-solid fa-file-csv"></i> CSV
            </button>
          </div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>Consecutivo</th><th>N° Interno</th><th>Tipo</th><th>Estado</th><th>Sucursal Origen</th><th>Referencia ERP</th>
              <th class="text-center">Ítems</th><th>Fotos</th><th>Fecha</th><th>Solicitado por</th><th>Acciones</th>
            </tr></thead>
            <tbody id="dv-tbody">
              ${rows.length ? rows.map(d => {
                const cons  = d.consecutivo_devolucion ? ('#' + d.consecutivo_devolucion) : '-';
                const fotosCount = Array.isArray(d.fotos_json) ? d.fotos_json.length : (typeof d.fotos_json === 'string' ? JSON.parse(d.fotos_json || '[]').length : 0);
                return `
                <tr>
                  <td><span class="badge" style="background:var(--accent);color:#fff;font-weight:700;font-size:12px;">${WMS.esc(cons)}</span></td>
                  <td><strong>${WMS.esc(d.numero_devolucion)}</strong></td>
                  <td><span class="badge" style="background:var(--info-bg);color:var(--info);">${WMS.esc(tipoLabel[d.tipo]||d.tipo)}</span></td>
                  <td><span class="badge" style="background:${badgeColor[d.estado]||'#94a3b8'}20;color:${badgeColor[d.estado]||'#94a3b8'};border:1px solid ${badgeColor[d.estado]||'#94a3b8'};">${WMS.esc(d.estado)}</span></td>
                  <td style="font-size:11px;">${WMS.esc(d.sucursal_origen?.nombre || '-')}</td>
                  <td>${WMS.esc(d.referencia_externa||'-')}</td>
                  <td class="text-center">${(d.detalles||[]).length}</td>
                  <td class="text-center">${fotosCount > 0 ? `<span class="badge" style="background:#fdf4ff;color:#c026d3;border:1px solid #f5d0fe;"><i class="fa-solid fa-camera"></i> ${fotosCount}</span>` : '<span style="color:var(--text-muted);">0</span>'}</td>
                  <td style="font-size:11px;">${d.created_at ? d.created_at.substring(0,10) : '-'}</td>
                  <td style="font-size:11px;">${WMS.esc(d.solicitado_por_nombre||'-')}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
                      <i class="fa-solid fa-eye"></i> Ver
                    </button>
                  </td>
                </tr>`;
              }).join('') : '<tr><td colspan="11" class="table-empty">Sin devoluciones registradas</td></tr>'}
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
        consecutivo: document.getElementById('dv-f-consecutivo')?.value || '',
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
     DETALLE (conservado del original + consecutivo + fotos + sucursal)
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
        PendienteAprobacion:'#f59e0b',Aprobada:'#3b82f6',Procesada:'#10b981',
        Rechazada:'#ef4444',Anulada:'#94a3b8',Borrador:'#64748b',
      };

      let fotosList = Array.isArray(d.fotos_json)
        ? d.fotos_json
        : (typeof d.fotos_json === 'string' ? JSON.parse(d.fotos_json || '[]') : []);
      fotosList = fotosList.map(f => f.startsWith('/uploads/') ? '/WMS_FENIX/public' + f : f);

      const consecutivoTxt = d.consecutivo_devolucion ? `#${d.consecutivo_devolucion}` : 'Sin consecutivo';

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
          <i class="fa-solid fa-arrow-left"></i> Volver
        </button>
        ${isSup && canAprobar  ? `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.devoluciones.aprobar(${d.id})"><i class="fa-solid fa-check"></i> Aprobar</button>` : ''}
        ${isSup && canAprobar  ? `<button class="btn btn-danger btn-sm" onclick="WMS_MODULES.devoluciones.rechazar(${d.id})"><i class="fa-solid fa-times"></i> Rechazar</button>` : ''}
        ${canProcesar           ? `<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.abrirProcesar(${d.id})"><i class="fa-solid fa-gears"></i> Procesar</button>` : ''}
        ${isSup && canAnular    ? `<button class="btn btn-outline-danger btn-sm" onclick="WMS_MODULES.devoluciones.anular(${d.id})"><i class="fa-solid fa-ban"></i> Anular</button>` : ''}`);

      WMS.setContent(`
        <div class="card" style="border:none;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -1px rgba(0,0,0,0.06);overflow:hidden;">
          <div class="card-header" style="background:linear-gradient(135deg, var(--accent) 0%, #17365C 100%);padding:12px 24px;border:none;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div style="display:flex;flex-direction:column;gap:8px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <span style="background:#f97316;color:#fff;font-size:1.4rem;font-weight:900;padding:4px 16px;border-radius:8px;box-shadow:0 4px 6px rgba(249, 115, 22, 0.3);">
                  <i class="fa-solid fa-hashtag"></i> ${WMS.esc(consecutivoTxt)}
                </span>
                <span style="color:var(--text-muted);font-size:13px;font-weight:600;background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:6px;">(${WMS.esc(d.numero_devolucion)})</span>
              </div>
              <div style="display:flex;gap:10px;margin-top:4px;">
                <span class="badge" style="background:${badgeColor[estado]||'#94a3b8'};color:#fff;font-size:14px;padding:6px 12px;border-radius:6px;box-shadow:0 2px 4px rgba(0,0,0,0.2);">${WMS.esc(estado)}</span>
                <span class="badge" style="background:rgba(255,255,255,0.15);color:#fff;font-size:13px;padding:6px 12px;border-radius:6px;"><i class="fa-solid fa-building"></i> ${WMS.esc(d.tipo)}</span>
              </div>
            </div>
            
            ${fotosList.length > 0 ? `
              <div style="background:var(--surface);padding:4px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.4);transform:rotate(2deg);transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05) rotate(0deg)'" onmouseout="this.style.transform='scale(1) rotate(2deg)'">
                <img src="${fotosList[0]}" style="width:120px;height:80px;object-fit:cover;border-radius:8px;cursor:pointer;" onclick="window.open('${fotosList[0]}', '_blank')" title="Ver evidencia inicial">
              </div>
            ` : `
              <div style="width:120px;height:80px;border-radius:12px;background:rgba(255,255,255,0.1);border:2px dashed rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.6);font-size:12px;text-align:center;padding:10px;">
                <div><i class="fa-solid fa-image" style="font-size:24px;margin-bottom:6px;display:block;"></i> Sin foto</div>
              </div>
            `}
          </div>
          
          <div style="background:var(--surface);padding:20px;border-bottom:1px dashed var(--border);">
            <div style="color:var(--accent);font-size:12px;text-transform:uppercase;letter-spacing:1px;font-weight:800;margin-bottom:8px;"><i class="fa-solid fa-quote-left"></i> Motivo General</div>
            <div style="font-size:15px;color:var(--text);line-height:1.6;font-weight:500;padding:12px;background:var(--table-header-bg);border-left:4px solid #f97316;border-radius:0 8px 8px 0;">
              ${WMS.esc(d.motivo_general)}
            </div>
          </div>
          
          <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:20px;padding:24px;background:var(--table-header-bg);border-bottom:1px solid var(--border);">
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Referencia ERP</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.referencia_externa||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Cliente / Origen</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.clienteOrigen?.razon_social||d.cliente_origen?.razon_social||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Sucursal Origen</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.sucursalOrigen?.nombre||d.sucursal_origen?.nombre||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Solicitado por</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.solicitante?.nombre||d.solicitado_por||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Aprobado por</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.aprobador?.nombre||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Procesado por</div><div style="font-weight:600;color:var(--text);">${WMS.esc(d.procesador?.nombre||'-')}</div></div>
            <div style="background:var(--surface);padding:12px;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);"><div style="color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;font-weight:700;margin-bottom:4px;">Fecha Registro</div><div style="font-weight:600;color:var(--text);">${d.created_at?d.created_at.substring(0,10):'-'}</div></div>
          </div>
          <div class="table-container">
            <table class="erp-table" style="font-size:12px;">
              <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th><th>Nota</th></tr></thead>
              <tbody>
                ${(d.detalles||[]).map(det => `<tr>
                  <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
                  <td><code>${WMS.esc(det.lote||'-')}</code></td>
                  <td style="font-size:11px;">${det.fecha_vencimiento ? WMS.formatDate(det.fecha_vencimiento) : '-'}</td>
                  <td class="text-center fw-700">${WMS.formatNum(det.cantidad)}</td>
                  <td>${WMS.esc(det.condicion||'-')}</td>
                  <td>${det.destino ? `<span class="badge" style="background:var(--ok-bg);color:var(--ok);">${WMS.esc(det.destino)}</span>` : '<span style="color:var(--text-muted);">—</span>'}</td>
                  <td style="font-size:11px;color:var(--muted);">${WMS.esc(det.detalle_motivo||det.motivo_item||'-')}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>
        
        <!-- CRM TIMELINE, CHART & FORM -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-top:20px;">
          <!-- Timeline -->
          <div class="card" style="padding:20px;">
            <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;">
              <i class="fa-solid fa-timeline" style="color:#6366f1;"></i> Historial y Seguimiento (CRM)
            </div>
            <div id="dv-crm-timeline">
              <div style="color:var(--text-muted);font-size:12px;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando historial...</div>
            </div>
          </div>
          
          <!-- Galeria -->
          <div class="card" style="padding:20px;display:flex;flex-direction:column;">
            <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;">
              <i class="fa-solid fa-images" style="color:#0ea5e9;"></i> Evidencias Registradas
            </div>
            <div style="flex:1;position:relative;min-height:220px;display:flex;flex-wrap:wrap;gap:12px;align-content:flex-start;" id="dv-crm-galeria">
              ${fotosList.length > 0 ? fotosList.map(f => `<a href="${f}" target="_blank" style="display:block;width:100px;height:100px;border-radius:8px;overflow:hidden;border:1px solid var(--border);box-shadow:0 2px 4px rgba(0,0,0,0.05);transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'"><img src="${f}" style="width:100%;height:100%;object-fit:cover;"></a>`).join('') : '<div style="color:var(--text-muted);font-size:12px;width:100%;text-align:center;padding:20px;">Sin fotos iniciales</div>'}
            </div>
          </div>
          
          <!-- Nuevo Registro Tracking -->
          <div class="card" style="padding:20px;background:var(--table-header-bg);border:1px dashed var(--border);">
            <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:16px;">
              <i class="fa-solid fa-comment-medical" style="color:var(--ok);"></i> Registrar Seguimiento / Novedad
            </div>
            ${estado === 'Finalizada' ? `
              <div style="padding:20px;text-align:center;color:var(--danger);font-weight:700;background:var(--danger-bg);border-radius:8px;">
                <i class="fa-solid fa-lock"></i> La devolución se encuentra Finalizada. No admite más cambios.
              </div>
            ` : `
            <div>
              <label class="form-label">Cambiar Estado (Opcional)</label>
              <select id="dv-crm-nuevo-estado" class="form-control" style="margin-bottom:12px;">
                <option value="">Mantener estado actual (${WMS.esc(estado)})</option>
              </select>
              
              <label class="form-label">Responsable del movimiento <span style="color:var(--danger);">*</span></label>
              <select id="dv-crm-responsable" class="form-control" style="margin-bottom:12px;">
                <option value="">Cargando auxiliares de calidad...</option>
              </select>
              
              <label class="form-label">Observaciones / Novedad</label>
              <textarea id="dv-crm-observacion" class="form-control" rows="3" placeholder="Escriba aquí los detalles..." style="margin-bottom:12px;"></textarea>
              
              <label class="form-label">Adjuntar Evidencias (Fotos)</label>
              <input type="file" id="dv-crm-fotos" multiple accept="image/*" capture="environment" class="form-control" style="margin-bottom:12px;">
              
              <div style="text-align:right;">
                <button class="btn btn-primary" onclick="WMS_MODULES.devoluciones.guardarTracking(${id})">
                  <i class="fa-solid fa-paper-plane"></i> Guardar Registro
                </button>
              </div>
            </div>
            `}
          </div>
        </div>
      `);
      
      // Load CRM Data asynchronously
      this._loadCRM(id, estado);
      
    } catch(e) { WMS.toast('error', 'Error al cargar detalle'); }
  },
  
  async _loadCRM(id, currentState) {
    try {
      const [rEstados, rTrack, rAux] = await Promise.all([
        API.get('/devoluciones/crm/estados'),
        API.get('/devoluciones/' + id + '/tracking'),
        API.get('/devoluciones/auxiliares-calidad?activo=1')
      ]);

      const estados = rEstados.data || [];
      const trackings = rTrack.data || [];
      const auxiliares = rAux.data || [];

      // Populate select
      const sel = document.getElementById('dv-crm-nuevo-estado');
      if (sel) {
        sel.innerHTML += estados.map(e => `<option value="${WMS.esc(e.nombre)}" ${e.nombre===currentState?'disabled':''}>→ ${WMS.esc(e.nombre)}</option>`).join('');
      }

      // Populate responsable (Auxiliares de Calidad)
      // BUG CORREGIDO 2026-09-17 (real, reproducido con Playwright): esta carga
      // es asíncrona y se dispara sin esperar desde showDetalle() — si el
      // usuario ya había seleccionado un responsable antes de que esta
      // respuesta llegara (red lenta, o simplemente rápido para escribir), el
      // innerHTML se reemplazaba por completo y el <select> volvía a quedar
      // vacío justo antes de guardar, haciendo fallar el tracking con "Debe
      // seleccionar el responsable" pese a que el usuario sí lo había elegido
      // — esta era la causa real de "no deja hacer modificaciones" reportada.
      // Se preserva la selección actual, mismo criterio que _cargarCausalesSelect().
      const selResp = document.getElementById('dv-crm-responsable');
      if (selResp) {
        const currentResp = selResp.value;
        selResp.innerHTML = '<option value="">-- Seleccionar responsable --</option>' +
          auxiliares.map(a => `<option value="${WMS.esc(a.nombre)}" ${currentResp === a.nombre ? 'selected' : ''}>${WMS.esc(a.nombre)}${a.cargo ? ' (' + WMS.esc(a.cargo) + ')' : ''}</option>`).join('') +
          (!auxiliares.length ? '<option value="" disabled>Sin auxiliares configurados — vaya a "Auxiliares Calidad"</option>' : '');
      }

      // Render timeline
      const tl = document.getElementById('dv-crm-timeline');
      if (tl) {
        if (!trackings.length) {
          tl.innerHTML = '<div style="padding:10px;color:var(--text-muted);font-size:12px;border:1px solid var(--border);border-radius:6px;background:var(--table-header-bg);text-align:center;">No hay registros de seguimiento.</div>';
          return;
        }
        
        tl.innerHTML = trackings.map(t => {
          const evHtml = (t.evidencias || []).map(e => {
            const url = e.ruta_archivo.startsWith('/uploads/') ? '/WMS_FENIX/public' + e.ruta_archivo : e.ruta_archivo;
            return `
            <a href="${url}" target="_blank" style="display:inline-block;margin-right:8px;margin-top:8px;">
              <img src="${url}" style="width:60px;height:60px;object-fit:cover;border-radius:4px;border:1px solid var(--border);">
            </a>
          `}).join('');
          
          if (t.evidencias && t.evidencias.length) {
            const gal = document.getElementById('dv-crm-galeria');
            if (gal) {
              if (gal.innerHTML.includes('Sin fotos iniciales')) gal.innerHTML = '';
              gal.innerHTML += (t.evidencias).map(e => {
                const url = e.ruta_archivo.startsWith('/uploads/') ? '/WMS_FENIX/public' + e.ruta_archivo : e.ruta_archivo;
                return `<a href="${url}" target="_blank" style="display:block;width:100px;height:100px;border-radius:8px;overflow:hidden;border:1px solid var(--border);box-shadow:0 2px 4px rgba(0,0,0,0.05);transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'"><img src="${url}" style="width:100%;height:100%;object-fit:cover;"></a>`;
              }).join('');
            }
          }
          
          return `
            <div style="display:flex;gap:12px;margin-bottom:16px;">
              <div style="display:flex;flex-direction:column;align-items:center;">
                <div style="width:10px;height:10px;border-radius:50%;background:var(--info);margin-top:4px;"></div>
                <div style="flex:1;width:2px;background:var(--border);margin-top:4px;"></div>
              </div>
              <div style="flex:1;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:12px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <strong style="color:var(--text);">${WMS.esc(t.usuario_nombre || 'Usuario')}</strong>
                  <span style="color:var(--muted);">${t.created_at}</span>
                </div>
                ${t.responsable ? `<div style="font-size:11px;color:#0ea5e9;font-weight:700;margin-bottom:6px;"><i class="fa-solid fa-user-tag"></i> Responsable: ${WMS.esc(t.responsable)}</div>` : ''}
                ${t.estado_anterior !== t.estado_nuevo ? `<div style="margin-bottom:6px;color:#6366f1;font-weight:600;"><i class="fa-solid fa-arrow-right-arrow-left"></i> ${WMS.esc(t.estado_anterior)} → ${WMS.esc(t.estado_nuevo)}</div>` : ''}
                <div style="color:var(--text);white-space:pre-wrap;">${WMS.esc(t.observacion||'')}</div>
                ${evHtml ? `<div>${evHtml}</div>` : ''}
              </div>
            </div>
          `;
        }).join('');
      }
    } catch(e) { console.error("Error CRM", e); }
  },
  
  async guardarTracking(id) {
    const estado = document.getElementById('dv-crm-nuevo-estado')?.value || '';
    const obs = document.getElementById('dv-crm-observacion')?.value || '';
    const responsable = document.getElementById('dv-crm-responsable')?.value || '';
    const input = document.getElementById('dv-crm-fotos');

    if (!estado && !obs.trim()) {
      WMS.toast('error', 'Debes escribir una observación o cambiar el estado');
      return;
    }
    if (!responsable) {
      WMS.toast('error', 'Seleccione el responsable del movimiento');
      return;
    }

    WMS.spinner();
    try {
      const fotosBase64 = [];
      if (input && input.files.length > 0) {
        for (let file of input.files) {
          const compressed = await this._compressImage(file);
          const reader = new FileReader();
          const p = new Promise(resolve => {
            reader.onload = e => resolve(e.target.result);
            reader.readAsDataURL(compressed);
          });
          fotosBase64.push(await p);
        }
      }
      
      const payload = {
        estado_nuevo: estado,
        observacion: obs,
        fotos: fotosBase64,
        responsable // reutiliza el valor ya validado arriba, no relee el <select>
      };
      
      const r = await API.post('/devoluciones/' + id + '/tracking', payload);
      
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Registro guardado exitosamente');
      this.showDetalle(id); // recargar detalle
    } catch(e) {
      WMS.toast('error', 'Error al guardar tracking');
    }
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
        <div style="background:var(--surface);border-radius:12px;padding:24px 28px;min-width:600px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 16px;font-size:16px;"><i class="fa-solid fa-gears"></i> Procesar Devolución — ${WMS.esc(d.numero_devolucion)}</h3>
          <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Asigna el destino de cada ítem antes de confirmar.</p>
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
      if (!s.value) { valid = false; s.style.borderColor = 'var(--danger)'; }
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
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._abrirModalCausal(null)">
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
                  <td style="font-size:12px;color:var(--muted);">${WMS.esc(c.descripcion||'-')}</td>
                  <td class="text-center">
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
                      <input type="checkbox" ${c.activo ? 'checked' : ''}
                        onchange="WMS_MODULES.devoluciones._toggleCausal(${c.id}, this.checked)"
                        style="width:15px;height:15px;accent-color:#3b82f6;">
                      <span style="color:${c.activo ? 'var(--ok)' : 'var(--text-muted)'};">
                        ${c.activo ? 'Sí' : 'No'}
                      </span>
                    </label>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._modalEditarCausal(${c.id})">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="WMS_MODULES.devoluciones._eliminarCausal(${c.id})">
                      <i class="fa-solid fa-trash"></i>
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
      Proveedor:  'background:var(--info-bg);color:var(--accent);',
      Operacion:  'background:var(--warn-bg);color:var(--warn);',
      Transporte: 'background:var(--ok-bg);color:var(--ok);',
      Cliente:    'background:#fdf2f8;color:#9d174d;',
    };
    return map[responsable] || 'background:var(--btn-neutral);color:var(--muted);';
  },

  /* ═══════════════════════════════════════════════════════════════════════
     D.3) AUXILIARES DE CALIDAD — personal responsable seleccionable en el
     tracking/estados CRM (Camilo, 2026-09-17)
  ═══════════════════════════════════════════════════════════════════════ */
  async showAuxiliaresCalidad() {
    this._state.vista = 'auxiliares';
    WMS.setToolbar(this._navBar('auxiliares'));
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/auxiliares-calidad');
      this._state.auxiliaresCalidad = r.data || [];
      this._renderAuxiliaresCalidad(this._state.auxiliaresCalidad);
    } catch(e) { WMS.toast('error', 'Error al cargar auxiliares de calidad'); }
  },

  _renderAuxiliaresCalidad(rows) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-user-shield"></i> Auxiliares de Calidad</span>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._abrirModalAuxiliarCalidad()">
            <i class="fa-solid fa-plus"></i> Nuevo Auxiliar
          </button>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Nombre</th><th>Cargo</th><th class="text-center">Activo</th><th>Acciones</th></tr></thead>
            <tbody>
              ${rows.length ? rows.map(a => `
                <tr>
                  <td><strong>${WMS.esc(a.nombre)}</strong></td>
                  <td style="font-size:12px;color:var(--muted);">${WMS.esc(a.cargo||'-')}</td>
                  <td class="text-center">
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-size:12px;">
                      <input type="checkbox" ${a.activo ? 'checked' : ''}
                        onchange="WMS_MODULES.devoluciones._toggleAuxiliarCalidad(${a.id}, this.checked)"
                        style="width:15px;height:15px;accent-color:#2b5fc7;">
                      <span style="color:${a.activo ? '#00b300' : 'var(--text-muted)'};">${a.activo ? 'Sí' : 'No'}</span>
                    </label>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones._abrirModalAuxiliarCalidad(${a.id})">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                  </td>
                </tr>`).join('') : '<tr><td colspan="4" class="table-empty">Sin auxiliares de calidad configurados</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _abrirModalAuxiliarCalidad(id = null) {
    const a = id ? (this._state.auxiliaresCalidad || []).find(x => x.id === id) : null;
    const titulo = a ? 'Editar Auxiliar de Calidad' : 'Nuevo Auxiliar de Calidad';
    const html = `
      <div id="aux-cal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:var(--surface);border-radius:12px;padding:24px 28px;width:420px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 18px;font-size:16px;color:var(--text);"><i class="fa-solid fa-user-shield"></i> ${titulo}</h3>
          <div style="display:grid;gap:14px;">
            <div>
              <label class="form-label">Nombre <span style="color:var(--danger);">*</span></label>
              <input type="text" id="aux-cal-nombre" class="form-control" placeholder="Nombre completo" value="${WMS.esc(a?.nombre||'')}">
            </div>
            <div>
              <label class="form-label">Cargo</label>
              <input type="text" id="aux-cal-cargo" class="form-control" placeholder="Ej: Analista de Calidad" value="${WMS.esc(a?.cargo||'')}">
            </div>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('aux-cal-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._guardarAuxiliarCalidad(${a?.id||'null'})"><i class="fa-solid fa-save"></i> Guardar</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('aux-cal-nombre')?.focus();
  },

  async _guardarAuxiliarCalidad(id) {
    const nombre = document.getElementById('aux-cal-nombre')?.value?.trim();
    const cargo  = document.getElementById('aux-cal-cargo')?.value?.trim();
    if (!nombre) { WMS.toast('error', 'Ingrese el nombre del auxiliar'); return; }
    document.getElementById('aux-cal-overlay')?.remove();
    WMS.spinner();
    try {
      const payload = { nombre, cargo: cargo || null };
      const r = id ? await API.put('/devoluciones/auxiliares-calidad/' + id, payload)
                   : await API.post('/devoluciones/auxiliares-calidad', payload);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', id ? 'Auxiliar actualizado' : 'Auxiliar creado');
      this.showAuxiliaresCalidad();
    } catch(e) { WMS.toast('error', 'Error al guardar auxiliar de calidad'); }
  },

  async _toggleAuxiliarCalidad(id, activo) {
    try {
      const r = await API.put('/devoluciones/auxiliares-calidad/' + id, { activo: activo ? 1 : 0 });
      if (r.error) { WMS.toast('error', r.message); return; }
      const idx = (this._state.auxiliaresCalidad||[]).findIndex(a => a.id === id);
      if (idx >= 0) this._state.auxiliaresCalidad[idx].activo = activo ? 1 : 0;
      WMS.toast('success', activo ? 'Auxiliar activado' : 'Auxiliar desactivado');
    } catch(e) { WMS.toast('error', 'Error al actualizar auxiliar'); }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     D.2) GESTIÓN DE ESTADOS CRM
  ═══════════════════════════════════════════════════════════════════════ */
  async showEstadosCRM() {
    this._state.vista = 'estados';
    WMS.setToolbar(this._navBar('estados'));
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/crm/estados/all');
      this._state.estadosCRM = r.data || [];
      this._renderEstadosCRM(this._state.estadosCRM);
    } catch(e) { WMS.toast('error', 'Error al cargar estados'); }
  },

  _renderEstadosCRM(estados) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-list-check"></i> Estados CRM (Calidad)</span>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._modalEstadoCRM()">
            <i class="fa-solid fa-plus"></i> Nuevo Estado
          </button>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>Nombre</th>
              <th class="text-center">Color</th>
              <th class="text-center">Orden</th>
              <th class="text-center">Activo</th>
              <th>Acciones</th>
            </tr></thead>
            <tbody>
              ${estados.map(e => `
                <tr>
                  <td><strong>${WMS.esc(e.nombre)}</strong></td>
                  <td class="text-center"><span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:${WMS.esc(e.color||'#94a3b8')};"></span></td>
                  <td class="text-center">${e.orden}</td>
                  <td class="text-center">${e.activo ? '<span class="badge" style="background:var(--ok-bg);color:var(--ok);">SÍ</span>' : '<span class="badge" style="background:var(--danger-bg);color:var(--danger);">NO</span>'}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.devoluciones._modalEstadoCRM(${e.id})"><i class="fa-solid fa-pen"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="WMS_MODULES.devoluciones._eliminarEstadoCRM(${e.id})"><i class="fa-solid fa-trash"></i></button>
                  </td>
                </tr>
              `).join('') || '<tr><td colspan="5" class="text-center" style="padding:20px;color:var(--text-muted);">Sin estados registrados</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>
    `);
  },

  _modalEstadoCRM(id = null) {
    let est = null;
    if (id) est = this._state.estadosCRM.find(e => e.id === id);
    
    const html = `
      <div id="est-modal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:var(--surface);border-radius:12px;padding:24px 28px;width:100%;max-width:400px;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 16px;font-size:16px;">${id ? 'Editar Estado' : 'Nuevo Estado'}</h3>
          
          <label class="form-label">Nombre del Estado</label>
          <input type="text" id="est-nombre" class="form-control" value="${est ? WMS.esc(est.nombre) : ''}" style="margin-bottom:12px;">
          
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
              <label class="form-label">Color (Hex)</label>
              <input type="color" id="est-color" class="form-control" value="${est ? est.color : '#94a3b8'}" style="height:36px;padding:2px;">
            </div>
            <div>
              <label class="form-label">Orden (0, 1, 2...)</label>
              <input type="number" id="est-orden" class="form-control" value="${est ? est.orden : '0'}">
            </div>
          </div>
          
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:20px;cursor:pointer;">
            <input type="checkbox" id="est-activo" ${(!est || est.activo) ? 'checked' : ''}> Estado Activo
          </label>
          
          <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('est-modal').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._guardarEstadoCRM(${id || 'null'})">Guardar</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async _guardarEstadoCRM(id) {
    const data = {
      nombre: document.getElementById('est-nombre').value.trim(),
      color: document.getElementById('est-color').value,
      orden: parseInt(document.getElementById('est-orden').value) || 0,
      activo: document.getElementById('est-activo').checked
    };
    
    if (!data.nombre) { WMS.toast('error', 'El nombre es obligatorio'); return; }
    
    document.getElementById('est-modal')?.remove();
    WMS.spinner();
    try {
      let r;
      if (id) {
        r = await API.put('/devoluciones/crm/estados/' + id, data);
      } else {
        r = await API.post('/devoluciones/crm/estados', data);
      }
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Estado guardado');
      this.showEstadosCRM();
    } catch(e) { WMS.toast('error', 'Error al guardar estado'); }
  },

  async _eliminarEstadoCRM(id) {
    if (!confirm('¿Eliminar este estado? Si ya está en uso no se podrá borrar.')) return;
    WMS.spinner();
    try {
      const r = await API.delete('/devoluciones/crm/estados/' + id);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Estado eliminado');
      this.showEstadosCRM();
    } catch(e) { WMS.toast('error', 'Error al eliminar estado'); }
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
        <div style="background:var(--surface);border-radius:12px;padding:24px 28px;width:480px;max-width:95vw;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 18px;font-size:16px;color:var(--text);">
            <i class="fa-solid fa-tag"></i> ${titulo}
          </h3>
          <div style="display:grid;gap:14px;">
            <div>
              <label class="form-label">Causal <span style="color:var(--danger);">*</span></label>
              <input type="text" id="mc-causal" class="form-control" placeholder="Ej: Producto dañado en tránsito"
                value="${WMS.esc(c?.causal||'')}">
            </div>
            <div>
              <label class="form-label">Responsable <span style="color:var(--danger);">*</span></label>
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

  async _eliminarCausal(id) {
    if (!confirm('¿Eliminar esta causal? Si ya está en uso en alguna devolución no se podrá borrar.')) return;
    WMS.spinner();
    try {
      const r = await API.delete('/devoluciones/causales/' + id);
      WMS.spinnerHide();
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Causal eliminada');
      this.showCausales();
    } catch(e) { WMS.spinnerHide(); WMS.toast('error', 'Error al eliminar causal'); }
  },

  _initGlobalEvents() {
    if (this._globalEventsBound) return;
    document.addEventListener('click', (e) => {
      const dropCliente = document.getElementById('dv-cliente-origen-drop');
      const dropTercero = document.getElementById('dv-tercero-drop');
      const dropUbic = document.getElementById('dv-ubic-drop');
      const dropProd = document.getElementById('dv-prod-drop');
      
      if (dropCliente && e.target.closest('#dv-new-cliente-q') === null) dropCliente.style.display = 'none';
      if (dropTercero && e.target.closest('#dv-new-tercero-q') === null) dropTercero.style.display = 'none';
      if (dropUbic && e.target.closest('#dv-new-ubic-q') === null) dropUbic.style.display = 'none';
      if (dropProd && e.target.closest('#dv-prod-nombre') === null) dropProd.style.display = 'none';
    });
    this._globalEventsBound = true;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     E) FORMULARIO NUEVA DEVOLUCIÓN (enriquecido)
  ═══════════════════════════════════════════════════════════════════════ */
  async showFormDevolucion() {
    this._initGlobalEvents();
    this._state.vista = 'nueva';
    this._state.items  = [];
    this._state.fotos  = [];
    this._state.qrProd = null;

    WMS.setToolbar(this._navBar('nueva'));

    /* Cargar causales si no las tenemos */
    if (!this._state.causales.length) {
      try {
        const rc = await API.get('/devoluciones/causales?activo=1');
        this._state.causales = rc.data || [];
      } catch(e) { /* sin causales */ }
    }

    // Buen Estado (opción por defecto del select) solo ofrece estas 3 causales;
    // Mal Estado sigue mostrando el listado completo de causales activas de siempre.
    const causalesOpts = this._causalesParaTipo('BuenEstado').map(c =>
      `<option value="${c.id}">${WMS.esc(c.causal)} (${WMS.esc(c.responsable||'?')})</option>`
    ).join('');

    WMS.setContent(`
      <div class="card" style="max-width:920px;">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-plus"></i> Nueva Devolución</span>
        </div>

        <!-- SECCIÓN 1: Datos generales -->
        <div style="padding:20px;border-bottom:1px solid var(--border);">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px;">
            Datos generales
          </div>
          <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:14px;">
            <div>
              <label class="form-label">Tipo de Devolución <span style="color:var(--danger);">*</span></label>
              <select id="dv-new-tipo" class="form-control" onchange="WMS_MODULES.devoluciones._onTipoDevolucionChange()">
                <option value="BuenEstado">Buen Estado</option>
                <option value="MalEstado">Mal Estado</option>
              </select>
            </div>
            <div style="position:relative;">
              <label class="form-label">Cliente / Sucursal que Devuelve (Origen) <span style="color:var(--danger);">*</span></label>
              <input type="text" id="dv-new-cliente-q" class="form-control"
                placeholder="Escriba para buscar cliente por nombre, NIT o código..."
                oninput="WMS_MODULES.devoluciones._buscarClienteOrigen(this.value)"
                onfocus="WMS_MODULES.devoluciones._buscarClienteOrigen(this.value)"
                onclick="WMS_MODULES.devoluciones._buscarClienteOrigen(this.value)"
                autocomplete="off">
              <div id="dv-cliente-origen-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:300;
                background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);
                max-height:220px;overflow-y:auto;font-size:13px;"></div>
              <input type="hidden" id="dv-new-cliente-origen">
              <div id="dv-cliente-origen-selected" style="display:none;margin-top:4px;"></div>
            </div>
            <div>
              <label class="form-label">Causal <span style="color:var(--danger);">*</span></label>
              <select id="dv-new-causal" class="form-control">
                <option value="">-- Seleccionar causal --</option>
                ${causalesOpts}
              </select>
            </div>
          </div>
        </div>

        <!-- SECCIÓN 2: Cliente / Proveedor & Referencia ERP -->
        <div style="padding:20px;border-bottom:1px solid var(--border);">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px;">
            Tercero ERP & Referencia
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="position:relative;">
              <label class="form-label">Buscar Tercero (Cliente / Proveedor ERP)</label>
              <input type="text" id="dv-new-tercero-q" class="form-control"
                placeholder="Escribe para buscar..."
                oninput="WMS_MODULES.devoluciones._buscarTercero(this.value)"
                onfocus="WMS_MODULES.devoluciones._buscarTercero(this.value)"
                onclick="WMS_MODULES.devoluciones._buscarTercero(this.value)"
                autocomplete="off">
              <div id="dv-tercero-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);
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
        <div style="padding:20px;border-bottom:1px solid var(--border);">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px;">
            Ubicación en patio & Motivo
          </div>
          <div style="display:grid;grid-template-columns:1fr 2fr;gap:14px;align-items:start;">
            <div style="position:relative;">
              <label class="form-label">Código de ubicación</label>
              <input type="text" id="dv-new-ubic-q" class="form-control"
                placeholder="Ej: PT-A-01"
                oninput="WMS_MODULES.devoluciones._buscarUbicacion(this.value)"
                autocomplete="off">
              <div id="dv-ubic-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                max-height:180px;overflow-y:auto;font-size:13px;"></div>
              <input type="hidden" id="dv-new-ubic-id">
            </div>
            <div>
              <label class="form-label">Motivo general <span style="color:var(--danger);">*</span></label>
              <input type="text" id="dv-new-motivo" class="form-control"
                placeholder="Descripción general de la devolución">
            </div>
          </div>
        </div>

        <!-- SECCIÓN 4: Registro Fotográfico -->
        <div style="padding:20px;border-bottom:1px solid var(--border);background:#faf5ff;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#7e22ce;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
            <i class="fa-solid fa-camera"></i> Registro Fotográfico del Producto (Fotos de evidencia)
          </div>
          <div>
            <label class="btn btn-outline-primary btn-sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-cloud-arrow-up"></i> Seleccionar Fotos del Producto...
              <input type="file" id="dv-fotos-input" multiple accept="image/*" style="display:none;" onchange="WMS_MODULES.devoluciones._onFotosSelected(this)">
            </label>
            <span style="font-size:11px;color:var(--muted);margin-left:10px;">Puedes subir múltiples fotos del estado físico del producto</span>
          </div>
          <div id="dv-fotos-preview" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;"></div>
        </div>

        <!-- SECCIÓN 5: Productos -->
        <div style="padding:20px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:12px;">
            Productos a devolver
          </div>

          <!-- Buscador producto -->
          <div style="background:var(--table-header-bg);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px;">
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
              <div style="flex:1;min-width:220px;position:relative;">
                <label class="form-label" style="display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-qrcode"></i> Escanear QR / EAN / Código
                </label>
                <input type="text" id="dv-qr-input" class="form-control"
                  placeholder="Escanee QR o escriba código..."
                  onkeydown="if(event.key==='Enter'){WMS_MODULES.devoluciones.buscarQr();event.preventDefault();}">
              </div>
              <div style="flex:1;min-width:200px;position:relative;">
                <label class="form-label" style="display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-magnifying-glass"></i> Buscar por nombre o referencia
                </label>
                <input type="text" id="dv-prod-nombre" class="form-control"
                  placeholder="Nombre o código del producto..."
                  oninput="WMS_MODULES.devoluciones._buscarProducto(this.value)"
                  autocomplete="off">
                <div id="dv-prod-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
                  background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
                  max-height:180px;overflow-y:auto;font-size:13px;"></div>
              </div>
              <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.devoluciones.buscarQr()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar QR
              </button>
            </div>

            <!-- Producto encontrado -->
            <div id="dv-qr-found" style="display:none;background:var(--ok-bg);border:1px solid #86efac;border-radius:6px;padding:8px 12px;margin-top:10px;margin-bottom:10px;font-size:12px;">
              <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i>
              <strong id="dv-qr-nombre"></strong> — Lote: <span id="dv-qr-lote">-</span> / Vence: <span id="dv-qr-fv">-</span>
            </div>

            <!-- Campos adicionales ítem -->
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;align-items:end;margin-top:8px;">
              <div>
                <label id="dv-item-lote-label" class="form-label" style="display:block;margin-bottom:3px;">Lote</label>
                <input type="text" id="dv-item-lote" class="form-control form-control-sm" placeholder="Lote">
              </div>
              <div>
                <label id="dv-item-fv-label" class="form-label" style="display:block;margin-bottom:3px;">Fecha Venc.</label>
                <input type="date" id="dv-item-fv" class="form-control form-control-sm">
              </div>
              <div>
                <label id="dv-cant-label" class="form-label" style="display:block;margin-bottom:3px;">Cantidad</label>
                <div style="display:flex;gap:4px;">
                  <input type="number" id="dv-item-cant" class="form-control form-control-sm" min="0.001" step="0.001" placeholder="0" oninput="WMS_MODULES.devoluciones._updDvTotal()">
                  <input type="number" id="dv-item-saldo" class="form-control form-control-sm" min="0" step="0.001" placeholder="Saldo" style="display:none;" oninput="WMS_MODULES.devoluciones._updDvTotal()">
                </div>
                <div id="dv-cant-total" style="font-size:10px;color:var(--muted);display:none;"></div>
              </div>
            </div>
            <!-- Ubicación origen (solo si no está en Patio) -->
            <div id="dv-item-ubic-wrap" style="display:none;margin-top:8px;background:var(--warn-bg);border:1px solid #fbbf24;border-radius:6px;padding:8px 12px;">
              <div style="font-size:11px;font-weight:700;color:var(--warn);margin-bottom:6px;">
                <i class="fa-solid fa-triangle-exclamation"></i> Mercancía ubicada — indique la ubicación origen
              </div>
              <div style="display:flex;gap:8px;align-items:flex-end;">
                <div style="flex:1;position:relative;">
                  <input type="text" id="dv-item-ubic-q" class="form-control form-control-sm"
                    placeholder="Código ubicación (ej: A-01-01)"
                    oninput="WMS_MODULES.devoluciones._buscarUbicItemOrigen(this.value)"
                    autocomplete="off">
                  <div id="dv-item-ubic-drop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:300;
                    background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);
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

          <!-- Botón para agregar el producto capturado arriba a la tabla -->
          <div style="margin-top:14px;display:flex;justify-content:flex-end;">
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.agregarItem()">
              <i class="fa-solid fa-plus"></i> Agregar Producto
            </button>
          </div>
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
    this._actualizarCamposLoteVenc();
  },

  /* Búsqueda dinámica autocompletada de Cliente Origen */
  _buscarClienteOrigen(q) {
    clearTimeout(this._debounceClienteOrigen);
    const drop = document.getElementById('dv-cliente-origen-drop');
    if (!drop) return;
    this._debounceClienteOrigen = setTimeout(async () => {
      try {
        const url = (q && q.trim().length > 0)
          ? '/param/clientes?q=' + encodeURIComponent(q.trim())
          : '/param/clientes?limit=20';
        const r = await API.get(url);
        this._state.clientesSearchData = r.data || r || [];
        if (!this._state.clientesSearchData.length) {
          drop.innerHTML = '<div style="padding:10px;color:var(--text-muted);font-size:12px;">Sin clientes que coincidan</div>';
          drop.style.display = 'block';
          return;
        }
        drop.innerHTML = this._state.clientesSearchData.map((c, idx) => `
          <div onclick="WMS_MODULES.devoluciones._selClienteOrigenIdx(${idx})"
            style="padding:10px 12px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--table-header-bg)'" onmouseout="this.style.background=''">
            <strong style="font-size:13px;color:var(--text);">${WMS.esc(c.nombre_comercial || c.razon_social)}</strong>
            <span style="color:var(--muted);font-size:11px;display:block;">NIT: ${WMS.esc(c.nit||'S/N')} | Código: ${WMS.esc(c.codigo||'-')} ${c.ruta ? ' | Ruta: ' + WMS.esc(c.ruta.nombre||'') : ''}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 200);
  },

  _selClienteOrigenIdx(idx) {
    const c = this._state.clientesSearchData?.[idx];
    if (!c) return;
    document.getElementById('dv-new-cliente-origen').value = c.id;
    document.getElementById('dv-new-cliente-q').value      = c.nombre_comercial || c.razon_social;
    const drop = document.getElementById('dv-cliente-origen-drop');
    if (drop) drop.style.display = 'none';
    const sel = document.getElementById('dv-cliente-origen-selected');
    if (sel) {
      sel.innerHTML = `<div style="display:flex;align-items:center;justify-content:space-between;background:var(--ok-bg);border:1px solid #86efac;border-radius:6px;padding:6px 10px;margin-top:6px;font-size:12px;color:var(--ok);">
        <span><i class="fa-solid fa-circle-check" style="color:var(--ok);"></i> <strong>${WMS.esc(c.nombre_comercial || c.razon_social)}</strong> (NIT: ${WMS.esc(c.nit || 'S/N')}) ${c.codigo ? ' · Cód: ' + WMS.esc(c.codigo) : ''}</span>
        <button type="button" class="btn btn-xs btn-outline-secondary" onclick="WMS_MODULES.devoluciones._limpiarClienteOrigen()"><i class="fa-solid fa-xmark"></i> Cambiar</button>
      </div>`;
      sel.style.display = 'block';
    }
  },

  _limpiarClienteOrigen() {
    document.getElementById('dv-new-cliente-origen').value = '';
    document.getElementById('dv-new-cliente-q').value      = '';
    const sel = document.getElementById('dv-cliente-origen-selected');
    if (sel) sel.style.display = 'none';
    const input = document.getElementById('dv-new-cliente-q');
    if (input) { input.focus(); this._buscarClienteOrigen(''); }
  },

  async _onFotosSelected(input) {
    if (!input.files || !input.files.length) return;
    WMS.spinner();
    try {
      for (const file of input.files) {
        if (this._state.fotos.length >= 10) {
          WMS.toast('warning', 'Máximo 10 fotos por devolución');
          break;
        }
        const compressed = await this._compressImage(file);
        this._state.fotos.push(compressed);
      }
      input.value = '';
      this._renderFotosPreview();
    } catch(e) {
      WMS.toast('error', 'Error al procesar imágenes');
    } finally {
      WMS.spinnerHide();
    }
  },

  _compressImage(file) {
    return new Promise((resolve) => {
      if (!file.type.match(/image.*/)) {
        return resolve(file);
      }
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = new Image();
        img.onload = () => {
          const canvas = document.createElement('canvas');
          let width = img.width;
          let height = img.height;
          const MAX_SIZE = 1200;

          if (width > height) {
            if (width > MAX_SIZE) {
              height = Math.round(height * (MAX_SIZE / width));
              width = MAX_SIZE;
            }
          } else {
            if (height > MAX_SIZE) {
              width = Math.round(width * (MAX_SIZE / height));
              height = MAX_SIZE;
            }
          }
          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);

          canvas.toBlob((blob) => {
            if (blob) {
              resolve(new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() }));
            } else {
              resolve(file);
            }
          }, 'image/jpeg', 0.85);
        };
        img.onerror = () => resolve(file);
        img.src = e.target.result;
      };
      reader.onerror = () => resolve(file);
      reader.readAsDataURL(file);
    });
  },

  _renderFotosPreview() {
    const el = document.getElementById('dv-fotos-preview');
    if (!el) return;
    if (!this._state.fotos.length) {
      el.innerHTML = '';
      return;
    }
    el.innerHTML = this._state.fotos.map((file, i) => {
      const url = URL.createObjectURL(file);
      return `
        <div style="position:relative;width:90px;height:90px;border-radius:8px;overflow:hidden;border:1px solid var(--border);box-shadow:0 2px 6px rgba(0,0,0,0.1);">
          <img src="${url}" style="width:100%;height:100%;object-fit:cover;">
          <button type="button" onclick="WMS_MODULES.devoluciones._removeFoto(${i})"
            style="position:absolute;top:3px;right:3px;background:rgba(220,38,38,0.85);color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>`;
    }).join('');
  },

  _removeFoto(i) {
    this._state.fotos.splice(i, 1);
    this._renderFotosPreview();
  },

  _quitarItem(i) {
    this._state.items.splice(i, 1);
    this._renderItemsTable();
  },

  async guardarNueva() {
    const tipo             = document.getElementById('dv-new-tipo')?.value;
    const cliente_origen_id= document.getElementById('dv-new-cliente-origen')?.value;
    const causal_id        = document.getElementById('dv-new-causal')?.value;
    const ref              = document.getElementById('dv-new-ref')?.value?.trim() || null;
    const motivo           = document.getElementById('dv-new-motivo')?.value?.trim();
    const tercero_id       = document.getElementById('dv-new-tercero-id')?.value || null;
    const ubic_id          = document.getElementById('dv-new-ubic-id')?.value || null;

    if (!causal_id)    { WMS.toast('error', 'Seleccione la causal de devolución'); return; }
    if (!motivo)       { WMS.toast('error', 'Ingrese el motivo general'); return; }
    if (!this._state.items.length) { WMS.toast('error', 'Agregue al menos un producto'); return; }

    WMS.spinner();
    try {
      const formData = new FormData();
      formData.append('tipo', tipo);
      if (cliente_origen_id) {
        formData.append('cliente_origen_id', cliente_origen_id);
        formData.append('tercero_id', cliente_origen_id);
      }
      formData.append('causal_devolucion_id', parseInt(causal_id));
      if (ref) formData.append('referencia_externa', ref);
      formData.append('motivo_general', motivo);
      if (tercero_id && !cliente_origen_id) formData.append('tercero_id', parseInt(tercero_id));
      if (ubic_id) formData.append('ubicacion_patio_id', parseInt(ubic_id));

      const detallesPayload = this._state.items.map(it => ({
        producto_id:         it.producto_id,
        lote:                it.lote,
        fecha_vencimiento:   it.fecha_vencimiento,
        cantidad:            it.cantidad,
        cantidad_cajas:      it.cantidad_cajas || 0,
        cantidad_saldo:      it.cantidad_saldo || 0,
        // Sin campo "condición" por ítem: el tipo de devolución (Buen/Mal Estado),
        // ya viaja en `tipo` arriba, y el backend deriva la condición por defecto
        // de cada línea a partir de ese tipo (ver DevolucionController::store).
        motivo:              'Otro',
        ubicacion_origen_id: it.ubicacion_origen_id || null,
      }));
      formData.append('detalles', JSON.stringify(detallesPayload));

      this._state.fotos.forEach((file, idx) => {
        formData.append('fotos[]', file);
      });

      const res = await fetch(API_BASE + '/devoluciones', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + _wmsToken },
        body: formData
      });
      const r = await res.json();
      WMS.spinnerHide();

      if (r.error) { WMS.toast('error', r.message); return; }

      const devId       = r.data?.devolucion_id || r.data?.id;
      const consecutivo = r.data?.consecutivo_devolucion || r.data?.consecutivo;
      const consecTxt   = consecutivo ? `#${consecutivo}` : (r.data?.numero || '');

      // Modal de éxito con consecutivo prominente para marcado
      const modalHtml = `
        <div id="modal-consecutivo-success" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;">
          <div style="background:var(--surface);border-radius:16px;padding:28px;width:480px;max-width:92vw;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,0.3);animation:popIn 0.3s ease;">
            <div style="width:64px;height:64px;background:var(--ok-bg);color:var(--ok);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px;">
              <i class="fa-solid fa-check"></i>
            </div>
            <h3 style="margin:0 0 8px;font-size:20px;color:var(--text);">¡Devolución Registrada!</h3>
            <p style="margin:0 0 16px;color:var(--muted);font-size:13px;">Consecutivo asignado para el marcado físico del producto:</p>

            <div style="background:var(--info-bg);border:2px dashed var(--info);border-radius:12px;padding:16px;margin-bottom:20px;">
              <div style="font-size:11px;font-weight:700;color:var(--info);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Consecutivo de Marcado</div>
              <div style="font-size:2.4rem;font-weight:900;color:var(--accent);letter-spacing:1px;" id="val-consecutivo">${WMS.esc(consecTxt)}</div>
              <div style="font-size:11px;color:var(--muted);margin-top:4px;">N° Registro Interno: ${WMS.esc(r.data?.numero || '-')}</div>
            </div>

            <div style="display:flex;gap:10px;justify-content:center;">
              <button class="btn btn-outline-primary btn-sm" onclick="navigator.clipboard.writeText('${consecutivo || r.data?.numero}');WMS.toast('success','Consecutivo copiado al portapapeles');">
                <i class="fa-solid fa-copy"></i> Copiar Consecutivo
              </button>
              <button class="btn btn-primary btn-sm" onclick="document.getElementById('modal-consecutivo-success').remove();WMS_MODULES.devoluciones.showDetalle(${devId});">
                <i class="fa-solid fa-eye"></i> Ver Devolución
              </button>
            </div>
          </div>
        </div>`;

      document.body.insertAdjacentHTML('beforeend', modalHtml);
      WMS.toast('success', 'Devolución ' + consecTxt + ' creada correctamente');

    } catch(e) {
      WMS.spinnerHide();
      WMS.toast('error', 'Error al registrar la devolución');
    }
  },

  /* Búsqueda dinámica de terceros (clientes/proveedores) */
  _buscarTercero(q) {
    clearTimeout(this._debounceCliente);
    const drop = document.getElementById('dv-tercero-drop');
    if (!drop) return;
    this._debounceCliente = setTimeout(async () => {
      try {
        const url = (q && q.trim().length > 0)
          ? '/terceros?q=' + encodeURIComponent(q.trim()) + '&limit=10'
          : '/terceros?limit=10';
        const r = await API.get(url);
        const data = r.data || r || [];
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:var(--text-muted);font-size:12px;">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(t => `
          <div onclick="WMS_MODULES.devoluciones._selTercero(${t.id},'${WMS.esc(t.nombre||t.razon_social||'').replace(/'/g,"\\'")}')"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--table-header-bg)'" onmouseout="this.style.background=''">
            <strong style="font-size:13px;color:var(--text);">${WMS.esc(t.nombre||t.razon_social||t.codigo)}</strong>
            <span style="color:var(--text-muted);font-size:11px;"> ${WMS.esc(t.nit||t.codigo||'')}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 200);
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
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:var(--text-muted);">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(u => `
          <div onmousedown="WMS_MODULES.devoluciones._selUbicacion(${u.id},'${WMS.esc(u.codigo||u.nombre||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--table-header-bg)'" onmouseout="this.style.background=''">
            <code>${WMS.esc(u.codigo)}</code>
            <span style="color:var(--muted);font-size:11px;"> ${WMS.esc(u.nombre||u.descripcion||'')}</span>
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

  /* Búsqueda de producto por nombre o código interno (index-based) */
  _buscarProducto(q) {
    clearTimeout(this._debounceProd);
    const drop = document.getElementById('dv-prod-drop');
    if (!q || q.length < 2) { if(drop) drop.style.display='none'; return; }
    this._debounceProd = setTimeout(async () => {
      try {
        const r = await API.get('/param/productos/buscar?q=' + encodeURIComponent(q));
        const data = r.data || [];
        if (!drop) return;
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:var(--text-muted);">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.slice(0, 10).map(p => `
          <div onmousedown="WMS_MODULES.devoluciones._selProducto(${p.id},'${WMS.esc(p.nombre||'').replace(/'/g,"\\'")}','${WMS.esc(p.codigo_interno||'').replace(/'/g,"\\'")}')"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--table-header-bg)'" onmouseout="this.style.background=''">
            <strong style="font-size:12px;">${WMS.esc(p.nombre)}</strong>
            <span style="color:var(--text-muted);font-size:11px;"> [${WMS.esc(p.codigo_interno||'')}] UxC: ${parseInt(p.unidades_caja||1)}</span>
          </div>`).join('');
        drop.style.display = 'block';
      } catch(e) { /* sin resultados */ }
    }, 300);
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
    this._cargarUpcQrProd(id);
  },

  // Trae unidades_caja + controla_lote/controla_vencimiento del producto
  // seleccionado: unidades_caja decide si se captura cajas+saldo por separado;
  // controla_lote/controla_vencimiento deciden (solo para tipo Buen Estado) si
  // Lote/Fecha Venc. se muestran y exigen, según la condición propia del producto.
  async _cargarUpcQrProd(productoId) {
    const label   = document.getElementById('dv-cant-label');
    const saldoEl = document.getElementById('dv-item-saldo');
    const totalEl = document.getElementById('dv-cant-total');
    if (label) label.textContent = 'Cantidad';
    if (saldoEl) saldoEl.style.display = 'none';
    if (totalEl) totalEl.style.display = 'none';
    if (!this._state.qrProd) return;
    this._state.qrProd.unidades_caja = 1;
    this._state.qrProd.controla_lote = false;
    this._state.qrProd.controla_vencimiento = false;
    try {
      const r = await API.get('/param/productos/' + productoId);
      const p = r.data || r;
      const factor = parseInt(p?.unidades_caja) || 1;
      if (!this._state.qrProd) return;
      this._state.qrProd.unidades_caja = factor;
      this._state.qrProd.controla_lote = !!p?.controla_lote;
      this._state.qrProd.controla_vencimiento = !!p?.controla_vencimiento;
      if (factor > 1 && label && saldoEl) {
        label.textContent = `Cajas (${factor} und/caja)`;
        saldoEl.placeholder = 'Saldo';
        saldoEl.style.display = '';
        if (totalEl) totalEl.style.display = '';
        this._updDvTotal();
      }
    } catch(e) { /* si falla, se captura como cantidad única (comportamiento actual) */ }
    this._actualizarCamposLoteVenc();
  },

  // Solo aplica la exigencia condicional de Lote/Fecha Venc. cuando el tipo de
  // devolución es Buen Estado; Mal Estado conserva el comportamiento de siempre
  // (ambos campos visibles, nunca obligatorios).
  _actualizarCamposLoteVenc() {
    const tipo       = document.getElementById('dv-new-tipo')?.value;
    const loteInput  = document.getElementById('dv-item-lote');
    const fvInput    = document.getElementById('dv-item-fv');
    const loteWrap   = loteInput?.closest('div');
    const fvWrap     = fvInput?.closest('div');
    const loteLabel  = document.getElementById('dv-item-lote-label');
    const fvLabel    = document.getElementById('dv-item-fv-label');
    if (!loteInput || !fvInput) return;

    if (tipo !== 'BuenEstado') {
      if (loteWrap) loteWrap.style.display = '';
      if (fvWrap)   fvWrap.style.display   = '';
      if (loteLabel) loteLabel.innerHTML = 'Lote';
      if (fvLabel)   fvLabel.innerHTML   = 'Fecha Venc.';
      return;
    }

    const p       = this._state.qrProd || {};
    const reqLote = !!p.controla_lote;
    const reqFv   = !!p.controla_vencimiento;
    if (loteWrap) loteWrap.style.display = reqLote ? '' : 'none';
    if (fvWrap)   fvWrap.style.display   = reqFv   ? '' : 'none';
    if (loteLabel) loteLabel.innerHTML = reqLote ? 'Lote <span style="color:var(--danger);">*</span>' : 'Lote';
    if (fvLabel)   fvLabel.innerHTML   = reqFv   ? 'Fecha Venc. <span style="color:var(--danger);">*</span>' : 'Fecha Venc.';
  },

  // Filtra las causales disponibles según el tipo de devolución. Buen Estado
  // solo ofrece estas 3 (a pedido explícito de Camilo); Mal Estado sigue
  // mostrando el listado completo de causales activas de siempre.
  _BUEN_ESTADO_CAUSALES: ['exceso de inventario', 'no pedido', 'error en pedido'],
  _causalesParaTipo(tipo) {
    if (tipo !== 'BuenEstado') return this._state.causales;
    return this._state.causales.filter(c =>
      this._BUEN_ESTADO_CAUSALES.includes((c.causal || '').trim().toLowerCase())
    );
  },

  _onTipoDevolucionChange() {
    const tipo = document.getElementById('dv-new-tipo')?.value;
    const sel  = document.getElementById('dv-new-causal');
    if (sel) {
      const valorPrevio = sel.value;
      const opts = this._causalesParaTipo(tipo).map(c =>
        `<option value="${c.id}">${WMS.esc(c.causal)} (${WMS.esc(c.responsable||'?')})</option>`
      ).join('');
      sel.innerHTML = `<option value="">-- Seleccionar causal --</option>${opts}`;
      if ([...sel.options].some(o => o.value === valorPrevio)) sel.value = valorPrevio;
    }
    this._actualizarCamposLoteVenc();
  },

  _updDvTotal() {
    const factor = parseInt(this._state.qrProd?.unidades_caja) || 1;
    const totalEl = document.getElementById('dv-cant-total');
    if (factor <= 1 || !totalEl) return;
    const cj = parseFloat(document.getElementById('dv-item-cant')?.value || 0);
    const sl = parseFloat(document.getElementById('dv-item-saldo')?.value || 0);
    totalEl.textContent = `= ${WMS.formatNum((cj * factor) + sl)} und`;
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
      document.getElementById('dv-qr-fv').textContent     = r.data.fecha_vencimiento ? WMS.formatDate(r.data.fecha_vencimiento) : '-';
      document.getElementById('dv-qr-found').style.display       = 'block';
      document.getElementById('dv-item-ubic-wrap').style.display  = 'block';
      document.getElementById('dv-item-ubic-q').value             = '';
      document.getElementById('dv-item-ubic-id').value            = '';
      document.getElementById('dv-item-cant').focus();
      this._cargarUpcQrProd(p.id);
      WMS.toast('success', 'Producto: ' + p.nombre);
    } catch(e) { WMS.toast('error', 'Producto no encontrado'); }
  },

  agregarItem() {
    const prod = this._state.qrProd;
    if (!prod) { WMS.toast('error', 'Busque un producto primero'); return; }
    const factor = parseInt(prod.unidades_caja) || 1;
    const inputCant = parseFloat(document.getElementById('dv-item-cant')?.value || 0);
    let cant, cantCajas, cantSaldo;
    if (factor > 1) {
      cantCajas = inputCant;
      cantSaldo = parseFloat(document.getElementById('dv-item-saldo')?.value || 0);
      cant = (cantCajas * factor) + cantSaldo;
    } else {
      cant = inputCant;
      // factor=1: se manda como "1 caja" para que el recálculo server-side
      // (cajas*upc+saldo) dé el mismo total, nunca 0.
      cantCajas = cant;
      cantSaldo = 0;
    }
    if (!cant || cant <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    const loteVal = document.getElementById('dv-item-lote')?.value?.trim() || '';
    const fvVal   = document.getElementById('dv-item-fv')?.value || '';
    const tipoSel = document.getElementById('dv-new-tipo')?.value;
    if (tipoSel === 'BuenEstado') {
      if (prod.controla_lote && !loteVal) { WMS.toast('error', 'Este producto exige indicar el Lote'); return; }
      if (prod.controla_vencimiento && !fvVal) { WMS.toast('error', 'Este producto exige indicar la Fecha de Vencimiento'); return; }
    }
    const ubicOrigenId = parseInt(document.getElementById('dv-item-ubic-id')?.value || 0) || null;
    const ubicOrigenCod = document.getElementById('dv-item-ubic-q')?.value?.trim() || null;
    this._state.items.push({
      producto_id:         prod.id,
      producto_nombre:     prod.nombre,
      codigo:              prod.codigo,
      lote:                loteVal || null,
      fecha_vencimiento:   fvVal || null,
      cantidad:            cant,
      cantidad_cajas:      cantCajas,
      cantidad_saldo:      cantSaldo,
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
    document.getElementById('dv-item-saldo').value  = '';
    document.getElementById('dv-item-saldo').style.display = 'none';
    document.getElementById('dv-cant-total').style.display  = 'none';
    document.getElementById('dv-cant-label').textContent    = 'Cantidad';
    document.getElementById('dv-item-ubic-q').value = '';
    document.getElementById('dv-item-ubic-id').value= '';
    this._actualizarCamposLoteVenc();
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
        if (!data.length) { drop.innerHTML='<div style="padding:10px;color:var(--text-muted);">Sin resultados</div>'; drop.style.display='block'; return; }
        drop.innerHTML = data.map(u => `
          <div onclick="WMS_MODULES.devoluciones._selUbicItemOrigen(${u.id},'${WMS.esc(u.codigo||'').replace(/'/g,"\\'")}' )"
            style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--table-header-bg)'" onmouseout="this.style.background=''">
            <code>${WMS.esc(u.codigo)}</code>
            <span style="color:var(--muted);font-size:11px;"> ${WMS.esc(u.nombre||u.descripcion||'')}</span>
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
      el.innerHTML = '<p style="color:var(--text-muted);font-size:12px;text-align:center;padding:12px 0;">Sin ítems. Busque un producto arriba.</p>';
      return;
    }
    el.innerHTML = `
      <table class="erp-table" style="font-size:12px;">
        <thead><tr>
          <th>Producto</th><th>Lote</th><th>Vence</th>
          <th class="text-center">Cant.</th><th>Ubicación origen</th><th></th>
        </tr></thead>
        <tbody>
          ${this._state.items.map((it, i) => `<tr>
            <td>${WMS.esc(it.producto_nombre)}</td>
            <td><code>${WMS.esc(it.lote||'-')}</code></td>
            <td style="font-size:11px;">${it.fecha_vencimiento ? WMS.formatDate(it.fecha_vencimiento) : '-'}</td>
            <td class="text-center fw-700">${WMS.formatNum(it.cantidad)}</td>
            <td style="font-size:11px;">
              ${it.ubicacion_origen_cod
                ? `<code style="background:var(--warn-bg);color:var(--warn);">${WMS.esc(it.ubicacion_origen_cod)}</code>`
                : '<span style="color:var(--text-muted);">Patio/auto</span>'}
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
      <p style="font-size:11px;color:var(--muted);margin-top:4px;">
        Total: <strong>${this._state.items.length} SKU(s)</strong>  —
        <strong>${WMS.formatNum(this._state.items.reduce((s,x)=>s+x.cantidad,0))}</strong> unidades
      </p>`;
  },

  _quitarItem(i) {
    this._state.items.splice(i, 1);
    this._renderItemsTable();
  },

  /* ═══════════════════════════════════════════════════════════════════════
     RECOGIDAS DESDE PUNTO DE VENTA (a pedido de Camilo, 2026-09-30)
     Política Interna de Gestión de Logística Inversa y Devoluciones:
     el PDV monta la solicitud en BEE → aquí se registra la recogida con su
     consecutivo BEE, causal por referencia y se imprime la Remisión de Recogida
     (media carta, firmas de quien entrega / quien recibe y sello).
  ═══════════════════════════════════════════════════════════════════════ */
  _rg: { causales: [], lineas: [], pdv: null, seq: 0, filtros: {} },

  async _rgCausales() {
    if (this._rg.causales.length) return this._rg.causales;
    try { this._rg.causales = (await API.get('/devoluciones/recogidas/causales')).data || []; } catch (_) {}
    return this._rg.causales;
  },

  _rgImprimir(id) {
    const token = WMS_TOKEN() || '';
    window.open(`${API_BASE}/devoluciones/recogidas/${id}/imprimir?token=${encodeURIComponent(token)}`, '_blank');
  },

  async showRecogidas(filtros = null) {
    this._state.vista = 'recogidas';
    if (filtros) Object.assign(this._rg.filtros, filtros);
    const f = this._rg.filtros;
    f.ini = f.ini || WMS.getPastDate(30);
    f.fin = f.fin || WMS.getToday();
    WMS.setToolbar(this._navBar('recogidas'));
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/recogidas', `fecha_inicio=${f.ini}&fecha_fin=${f.fin}&q=${encodeURIComponent(f.q || '')}`);
      const rows = r.data || [];
      WMS.setContent(`
        <div class="card" style="padding:12px 16px;margin-bottom:14px;">
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div><label class="form-label" style="font-size:.7rem;">Desde</label><input type="date" id="rg-f-ini" class="form-control form-control-sm" value="${f.ini}"></div>
            <div><label class="form-label" style="font-size:.7rem;">Hasta</label><input type="date" id="rg-f-fin" class="form-control form-control-sm" value="${f.fin}"></div>
            <div style="flex:1;min-width:220px;"><label class="form-label" style="font-size:.7rem;">Buscar</label>
              <input id="rg-f-q" class="form-control form-control-sm" placeholder="Punto de venta, consecutivo BEE o RG-..." value="${WMS.esc(f.q || '')}"
                onkeydown="if(event.key==='Enter')WMS_MODULES.devoluciones._rgFiltrar()"></div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones._rgFiltrar()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            <button class="btn btn-success btn-sm" onclick="WMS_MODULES.devoluciones.load('recogida-nueva')"><i class="fa-solid fa-plus"></i> Nueva Recogida</button>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-arrow-right" style="margin-right:6px;color:var(--accent)"></i>Recogidas desde Punto de Venta <span style="font-weight:400;color:var(--muted);font-size:12px;">(${rows.length})</span></span></div>
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>N.º</th><th>Consecutivo BEE</th><th>Punto de venta</th><th>Solicitud</th><th>Recogida</th><th style="text-align:center">Refs.</th><th>Causales</th><th>Estado</th><th>Elaboró</th><th></th></tr></thead>
              <tbody>${rows.length ? rows.map(x => `
                <tr style="${x.estado === 'Anulada' ? 'opacity:.55;' : ''}">
                  <td><b>${WMS.esc(x.numero)}</b></td>
                  <td><span class="badge" style="background:var(--danger-bg);color:var(--danger);font-weight:800;">${WMS.esc(x.consecutivo_bee)}</span></td>
                  <td>${WMS.esc(x.punto_venta)}</td>
                  <td class="muted">${WMS.formatDate(x.fecha_solicitud)}</td>
                  <td class="muted">${x.fecha_recogida ? WMS.formatDate(x.fecha_recogida) : '—'}</td>
                  <td style="text-align:center;">${x.referencias}</td>
                  <td style="font-size:.75rem;">${WMS.esc(x.causales || '')}</td>
                  <td><span class="badge ${x.estado === 'Anulada' ? 'badge-danger' : 'badge-info'}">${WMS.esc(x.estado)}</span></td>
                  <td class="muted">${WMS.esc(x.creado_por_nombre || '—')}</td>
                  <td style="white-space:nowrap;">
                    <button class="btn btn-xs btn-primary" title="Imprimir remisión de recogida" onclick="WMS_MODULES.devoluciones._rgImprimir(${x.id})"><i class="fa-solid fa-print"></i></button>
                    ${x.estado !== 'Anulada' ? `<button class="btn btn-xs btn-outline-danger" title="Anular" onclick="WMS_MODULES.devoluciones._rgAnular(${x.id})"><i class="fa-solid fa-ban"></i></button>` : ''}
                  </td>
                </tr>`).join('') : '<tr><td colspan="10" class="muted" style="text-align:center;padding:24px;">Sin recogidas en el período</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch (e) {
      WMS.toast('error', e.message || 'Error cargando recogidas');
    } finally { WMS.spinnerHide(); }
  },

  _rgFiltrar() {
    this.showRecogidas({
      ini: document.getElementById('rg-f-ini')?.value, fin: document.getElementById('rg-f-fin')?.value,
      q: document.getElementById('rg-f-q')?.value?.trim() || '',
    });
  },

  async _rgAnular(id) {
    const r = await Swal.fire({ title: 'Anular recogida', input: 'text', inputLabel: 'Motivo de la anulación',
      inputValidator: v => !v?.trim() && 'Indique el motivo', showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#dc2626' });
    if (!r.isConfirmed) return;
    try {
      await API.post(`/devoluciones/recogidas/${id}/anular`, { motivo: r.value.trim() });
      WMS.toast('success', 'Recogida anulada');
      this.showRecogidas();
    } catch (e) { WMS.toast('error', e.message || 'No se pudo anular'); }
  },

  // ── Formulario dinámico ──────────────────────────────────────────────
  async showRecogidaForm() {
    this._state.vista = 'recogida-nueva';
    WMS.setToolbar(this._navBar('recogida-nueva'));
    const causales = await this._rgCausales();
    this._rg.lineas = []; this._rg.pdv = null; this._rg.seq = 0;
    const manana = (() => { const d = new Date(); d.setDate(d.getDate() + 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();

    WMS.setContent(`
      <div class="card" style="margin-bottom:14px;">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-arrow-right" style="margin-right:6px;color:var(--accent)"></i>Nueva Recogida desde Punto de Venta</span></div>
        <div style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;">
          <div class="form-group" style="margin:0;position:relative;">
            <label class="form-label">Punto de venta / Sucursal <span class="required">*</span></label>
            <input id="rg-pdv" class="form-control" placeholder="Escriba para buscar..." autocomplete="off"
              oninput="WMS_MODULES.devoluciones._rgBuscarPdv(this.value)">
            <div id="rg-pdv-res" style="display:none;position:absolute;z-index:50;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 6px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;"></div>
            <div id="rg-pdv-info" style="font-size:.72rem;color:var(--muted);margin-top:3px;"></div>
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Consecutivo BEE <span class="required">*</span></label>
            <input id="rg-bee" class="form-control" placeholder="N.º de la solicitud en BEE" style="font-weight:800;color:var(--danger);">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Fecha de recogida</label>
            <input type="date" id="rg-fecha" class="form-control" value="${manana}">
            <div style="font-size:.7rem;color:var(--muted);margin-top:3px;">La solicitud debe registrarse mínimo 24 h antes de la ruta del PDV.</div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:14px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-boxes-stacked" style="margin-right:6px;color:var(--accent)"></i>Referencias a recoger</span>
          <button class="btn btn-sm btn-success" onclick="WMS_MODULES.devoluciones._rgAgregarLinea()"><i class="fa-solid fa-plus"></i> Agregar referencia</button>
        </div>
        <div id="rg-lineas" style="padding:12px 16px;display:flex;flex-direction:column;gap:10px;"></div>
      </div>

      <div class="card" style="margin-bottom:14px;">
        <div style="padding:14px 16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <div class="form-group" style="margin:0;" id="rg-aut-wrap">
            <label class="form-label">Autorizado por <span id="rg-aut-req" class="required" style="display:none;">*</span></label>
            <input id="rg-autorizado" class="form-control" placeholder="Calidad / Líder del CDP (nombre)">
          </div>
          <div class="form-group" style="margin:0;">
            <label class="form-label">Observaciones <span id="rg-obs-req" class="required" style="display:none;">*</span></label>
            <textarea id="rg-obs" class="form-control" rows="2" placeholder="Describa la autorización y novedades de la recogida"></textarea>
          </div>
        </div>
        <div id="rg-aviso" style="display:none;margin:0 16px 12px;padding:8px 12px;border-radius:6px;background:var(--warn-bg);border:1px solid var(--warn-bg);color:var(--warn);font-size:.78rem;"></div>
        <div style="padding:0 16px 14px;display:flex;gap:8px;justify-content:flex-end;">
          <button class="btn btn-secondary" onclick="WMS_MODULES.devoluciones.load('recogidas')">Cancelar</button>
          <button class="btn btn-primary" id="rg-guardar" onclick="WMS_MODULES.devoluciones._rgGuardar()"><i class="fa-solid fa-save"></i> Guardar e imprimir</button>
        </div>
      </div>`);
    this._rgAgregarLinea();
  },

  _rgBuscarPdv(q) {
    clearTimeout(this._debounceCliente);
    this._rg.pdv = null;
    document.getElementById('rg-pdv-info').textContent = '';
    const box = document.getElementById('rg-pdv-res');
    if ((q || '').trim().length < 2) { box.style.display = 'none'; return; }
    this._debounceCliente = setTimeout(async () => {
      try {
        const r = await API.get('/devoluciones/recogidas/puntos-venta', 'q=' + encodeURIComponent(q.trim()));
        const lst = r.data || [];
        this._rgPdvLista = lst;
        box.innerHTML = lst.length ? lst.map((p, i) => `
          <div onclick="WMS_MODULES.devoluciones._rgElegirPdv(${i})" style="padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--border-light);"
            onmouseover="this.style.background='var(--info-bg)'" onmouseout="this.style.background=''">
            <div style="font-weight:700;font-size:.82rem;">${WMS.esc(p.nombre)}</div>
            <div style="font-size:.7rem;color:var(--muted);">${WMS.esc(p.detalle || '')}</div>
          </div>`).join('')
          : '<div style="padding:10px;font-size:.78rem;color:var(--text-muted);">Sin coincidencias</div>';
        box.style.display = 'block';
      } catch (_) {}
    }, 300);
  },

  _rgElegirPdv(i) {
    const p = (this._rgPdvLista || [])[i];
    if (!p) return;
    this._rg.pdv = p;
    document.getElementById('rg-pdv').value = p.nombre;
    document.getElementById('rg-pdv-res').style.display = 'none';
    document.getElementById('rg-pdv-info').innerHTML = p.frecuencia
      ? `<i class="fa-solid fa-route"></i> Frecuencia de ruta: <b>${WMS.esc(p.frecuencia)}</b>` : WMS.esc(p.detalle || '');
  },

  _rgAgregarLinea() {
    const id = ++this._rg.seq;
    this._rg.lineas.push({ id, prod: null });
    const causales = this._rg.causales;
    const div = document.createElement('div');
    div.id = `rg-l-${id}`;
    div.style.cssText = 'border:1px solid var(--border);border-left:4px solid var(--accent);border-radius:8px;padding:10px 12px;background:var(--surface);';
    div.innerHTML = `
      <div style="display:grid;grid-template-columns:minmax(240px,2.2fr) repeat(2,minmax(90px,.6fr)) minmax(120px,.9fr) minmax(130px,.9fr) minmax(190px,1.4fr) 34px;gap:8px;align-items:end;">
        <div><label class="form-label" style="font-size:.68rem;">Referencia <span class="required">*</span></label>
          <input id="rg-prod-${id}" class="form-control form-control-sm" placeholder="EAN, código o nombre..." autocomplete="off"></div>
        <div><label class="form-label" style="font-size:.68rem;">Cajas</label>
          <input type="number" id="rg-cj-${id}" class="form-control form-control-sm" min="0" step="1" value="0" oninput="WMS_MODULES.devoluciones._rgTotal(${id})"></div>
        <div><label class="form-label" style="font-size:.68rem;">Saldos</label>
          <input type="number" id="rg-sd-${id}" class="form-control form-control-sm" min="0" step="0.001" value="0" oninput="WMS_MODULES.devoluciones._rgTotal(${id})"></div>
        <div><label class="form-label" style="font-size:.68rem;">Lote</label>
          <input id="rg-lote-${id}" class="form-control form-control-sm" placeholder="Opcional"></div>
        <div><label class="form-label" style="font-size:.68rem;">F. Vencimiento</label>
          <input type="date" id="rg-fv-${id}" class="form-control form-control-sm"></div>
        <div><label class="form-label" style="font-size:.68rem;">Causal <span class="required">*</span></label>
          <select id="rg-causal-${id}" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._rgCausalCambio(${id})">
            <option value="">Seleccione...</option>
            ${[...new Set(causales.map(c => c.grupo))].map(g => `<optgroup label="${WMS.esc(g)}">${causales.filter(c => c.grupo === g).map(c => `<option value="${c.codigo}">${WMS.esc(c.nombre)}</option>`).join('')}</optgroup>`).join('')}
          </select></div>
        <button class="btn btn-sm btn-outline-danger" title="Quitar" onclick="WMS_MODULES.devoluciones._rgQuitarLinea(${id})"><i class="fa-solid fa-trash"></i></button>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:6px;">
        <span id="rg-total-${id}" style="font-size:.75rem;font-weight:700;color:var(--info);background:var(--info-bg);border:1px solid var(--info-bg);border-radius:5px;padding:3px 8px;">Seleccione la referencia</span>
        <span id="rg-disp-wrap-${id}" style="display:none;font-size:.75rem;">
          <label style="margin:0;font-weight:700;"><input type="checkbox" id="rg-destruir-${id}"> Autorizado destruir en PDV (numeral 5 — requiere registro fotográfico)</label>
        </span>
        <input id="rg-lobs-${id}" class="form-control form-control-sm" style="flex:1;min-width:200px;" placeholder="Observación de la línea (opcional)">
      </div>
      <div id="rg-req-${id}" style="display:none;font-size:.72rem;color:var(--warn);margin-top:5px;"></div>`;
    document.getElementById('rg-lineas').appendChild(div);
    setTimeout(() => WMS.initProductAutocomplete(document.getElementById(`rg-prod-${id}`), p => {
      const l = this._rg.lineas.find(x => x.id === id);
      if (l) l.prod = p;
      document.getElementById(`rg-prod-${id}`).value = `${p.codigo_interno || ''} — ${p.descripcion || p.nombre || ''}`;
      this._rgTotal(id);
    }), 50);
  },

  _rgQuitarLinea(id) {
    this._rg.lineas = this._rg.lineas.filter(l => l.id !== id);
    document.getElementById(`rg-l-${id}`)?.remove();
    this._rgAvisoAutorizacion();
  },

  _rgUpc(p) { return (+p?.factor_udm > 0) ? +p.factor_udm : Math.max(1, +p?.unidades_caja || 1); },

  // Total dinámico: cajas × U/E + saldos = UND/TOTAL (mismo formato del resto del sistema)
  _rgTotal(id) {
    const el = document.getElementById(`rg-total-${id}`);
    const l = this._rg.lineas.find(x => x.id === id);
    if (!el || !l) return;
    if (!l.prod) { el.textContent = 'Seleccione la referencia'; return; }
    const upc = this._rgUpc(l.prod);
    const cj = parseFloat(document.getElementById(`rg-cj-${id}`)?.value) || 0;
    const sd = parseFloat(document.getElementById(`rg-sd-${id}`)?.value) || 0;
    const total = Math.round((cj * upc + sd) * 1000) / 1000;
    el.textContent = upc > 1 ? `${cj} ${cj === 1 ? 'caja' : 'cajas'} × ${upc} + ${sd} sueltos = ${total} UND/TOTAL` : `${total} UND/TOTAL`;
  },

  _rgCausalCambio(id) {
    const cod = document.getElementById(`rg-causal-${id}`)?.value;
    const c = this._rg.causales.find(x => x.codigo === cod);
    const req = document.getElementById(`rg-req-${id}`);
    if (req) { req.style.display = c ? 'block' : 'none'; req.innerHTML = c ? `<i class="fa-solid fa-circle-info"></i> ${WMS.esc(c.requisito)}` : ''; }
    const disp = document.getElementById(`rg-disp-wrap-${id}`);
    if (disp) disp.style.display = c?.permite_destruccion ? 'inline' : 'none';
    if (!c?.permite_destruccion) { const chk = document.getElementById(`rg-destruir-${id}`); if (chk) chk.checked = false; }
    this._rgAvisoAutorizacion();
  },

  // Política 4.2: Calidad / Mal Estado / Caducado exigen autorización previa
  // (Calidad o Líder CDP) descrita en Observaciones.
  _rgAvisoAutorizacion() {
    const requiere = this._rg.lineas.some(l => {
      const c = this._rg.causales.find(x => x.codigo === document.getElementById(`rg-causal-${l.id}`)?.value);
      return c?.requiere_autorizacion;
    });
    ['rg-aut-req', 'rg-obs-req'].forEach(i => { const el = document.getElementById(i); if (el) el.style.display = requiere ? 'inline' : 'none'; });
    const av = document.getElementById('rg-aviso');
    if (av) {
      av.style.display = requiere ? 'block' : 'none';
      av.innerHTML = requiere ? '<i class="fa-solid fa-triangle-exclamation"></i> Hay referencias con causal de <b>Calidad, Mal Estado o Caducado</b>: según la política requieren <b>autorización previa de Calidad o del Líder del CDP</b>. Indique quién autorizó y descríbalo en Observaciones.' : '';
    }
  },

  async _rgGuardar() {
    const bee = document.getElementById('rg-bee')?.value.trim();
    const pdvTxt = document.getElementById('rg-pdv')?.value.trim();
    if (!pdvTxt) return WMS.toast('warning', 'Seleccione el punto de venta');
    if (!bee) return WMS.toast('warning', 'Digite el consecutivo BEE');
    const lineas = [];
    for (const l of this._rg.lineas) {
      const g = s => document.getElementById(`rg-${s}-${l.id}`);
      if (!l.prod) return WMS.toast('warning', 'Hay una línea sin referencia seleccionada');
      if (!g('causal')?.value) return WMS.toast('warning', `Seleccione la causal de ${l.prod.descripcion || l.prod.codigo_interno}`);
      lineas.push({
        producto_id: l.prod.id,
        cantidad_cajas: parseFloat(g('cj')?.value) || 0,
        saldos: parseFloat(g('sd')?.value) || 0,
        lote: g('lote')?.value.trim() || null,
        fecha_vencimiento: g('fv')?.value || null,
        causal: g('causal').value,
        disposicion: g('destruir')?.checked ? 'Destruir en PDV' : 'Recoger',
        observacion: g('lobs')?.value.trim() || null,
      });
    }
    if (!lineas.length) return WMS.toast('warning', 'Agregue al menos una referencia');
    const btn = document.getElementById('rg-guardar');
    if (btn) btn.disabled = true;
    try {
      const r = await API.post('/devoluciones/recogidas', {
        punto_venta: this._rg.pdv?.nombre || pdvTxt,
        cliente_id: this._rg.pdv?.cliente_id || null,
        consecutivo_bee: bee,
        fecha_recogida: document.getElementById('rg-fecha')?.value || null,
        autorizado_por: document.getElementById('rg-autorizado')?.value.trim() || null,
        observaciones: document.getElementById('rg-obs')?.value.trim() || null,
        lineas,
      });
      const id = r.data?.recogida?.id;
      WMS.toast('success', `Recogida ${r.data?.recogida?.numero || ''} registrada`);
      if (id) this._rgImprimir(id);
      this.showRecogidas();
    } catch (e) {
      WMS.toast('error', e.message || 'No se pudo guardar la recogida');
      if (btn) btn.disabled = false;
    }
  },

}; // end WMS_MODULES.devoluciones
