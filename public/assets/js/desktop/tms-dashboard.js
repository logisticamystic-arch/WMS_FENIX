// public/assets/js/desktop/tms-dashboard.js
'use strict';
WMS_MODULES.tms = {

  _chartInstances: {},
  _paletteVivid: ['#2b5fc7','#e03030','#e8a000','#00b300','#7c3aed','#0891b2','#c026d3','#64748b'],
  _map: null,
  _mapTimer: null,

  load(sub) {
    sub = sub || 'resumen';
    if (sub === 'mapa') return this.loadMapa();
    if (sub === 'gestion' || sub === 'reabrir') return this.loadGestionPedidos();
    if (sub === 'paradas') return this.loadParadas();
    if (sub === 'mantenimiento') return this.loadMantenimiento();
    return this.loadResumen();
  },

  _navBar(activa) {
    const tabs = [
      { id: 'resumen', label: 'Dashboard KPI', icon: 'fa-chart-pie' },
      { id: 'mapa',    label: 'Mapa en Vivo',  icon: 'fa-map-location-dot' },
      { id: 'gestion', label: 'Gestión de Pedidos', icon: 'fa-list-check' },
      { id: 'paradas', label: 'Tiempos de Paro', icon: 'fa-stopwatch' },
      { id: 'mantenimiento', label: 'Mantenimiento GPS', icon: 'fa-broom' },
    ];
    return `
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        ${tabs.map(t => `
          <button class="btn btn-sm ${activa === t.id ? 'btn-primary' : 'btn-secondary'}"
            onclick="WMS_MODULES.tms.load('${t.id}')">
            <i class="fa-solid ${t.icon}"></i> ${t.label}
          </button>`).join('')}
      </div>`;
  },

  /* ═══════════════════════════════════════════════════════════════════════
     A) DASHBOARD KPI
  ═══════════════════════════════════════════════════════════════════════ */
  async loadResumen() {
    this._activeTab = 'resumen';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('resumen'));

    // Por defecto la fecha ACTUAL — a pedido explícito de Camilo (2026-09-29).
    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Desde</label>
              <input type="date" id="tmsd-desde" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._aplicarFiltros()">
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Hasta</label>
              <input type="date" id="tmsd-hasta" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._aplicarFiltros()">
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Sucursal</label>
              <select id="tmsd-sucursal" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.tms._aplicarFiltros()">
                <option value="">Todas las sucursales</option>
              </select>
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Auxiliar</label>
              <select id="tmsd-auxiliar" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.tms._aplicarFiltros()">
                <option value="">Todos los auxiliares</option>
              </select>
            </div>
            <div>
              <label class="form-label" style="display:block;margin-bottom:3px;">Referencia</label>
              <input type="text" id="tmsd-referencia" class="form-control form-control-sm" placeholder="Código o nombre" style="min-width:160px;" oninput="WMS_MODULES.tms._aplicarFiltrosDebounced()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.tms._aplicarFiltros()">
              <i class="fa-solid fa-filter"></i> Aplicar
            </button>
          </div>
        </div>

        <div id="tmsd-kpis" class="pro-kpi-grid" style="margin-bottom:16px;">
          ${[0,1,2,3,4].map(() => `
            <div class="pro-kpi-card" style="min-height:90px;">
              <div style="background:var(--btn-neutral);border-radius:8px;height:60px;animation:pulse 1.5s ease-in-out infinite;"></div>
            </div>`).join('')}
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-gauge-high" style="margin-right:6px;color:#00b300;"></i> Nivel de Servicio por día
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-ns"></canvas>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-clock" style="margin-right:6px;color:var(--accent);"></i> Tiempo de demora promedio por día (min)
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-tiempos"></canvas>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-building" style="margin-right:6px;color:#e03030;"></i> Sucursales con más novedades
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-sucursales"></canvas>
            </div>
          </div>
          <div class="card" style="padding:16px;display:flex;flex-direction:column;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-stopwatch" style="margin-right:6px;color:var(--warn);"></i> Matriz de tiempos de demora por sucursal
            </div>
            <div style="flex:1;overflow-y:auto;max-height:220px;">
              <table class="erp-table" style="margin:0;width:100%;font-size:11px;">
                <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;">
                  <tr>
                    <th>Sucursal</th>
                    <th class="text-center">Entregas</th>
                    <th class="text-center">Promedio</th>
                    <th class="text-center">Mín.</th>
                    <th class="text-center">Máx.</th>
                    <th class="text-center" title="Entregas medidas por GPS (el resto con el tiempo registrado en la app)">GPS</th>
                  </tr>
                </thead>
                <tbody id="tmsd-matriz-demora"><tr><td colspan="6" class="text-center" style="padding:16px;color:var(--text-muted);">Cargando...</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card" style="padding:16px;margin-bottom:16px;">
          <div class="pro-section-title" style="margin-bottom:6px;">
            <i class="fa-solid fa-clipboard-list" style="margin-right:6px;color:var(--accent);"></i> Detalle de entregas por planilla
          </div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:8px;">Tiempo en sitio real = primera a última posición GPS del vehículo a menos de 150 m de la sucursal. "App" = lo registrado por el auxiliar (no confiable si confirma en lote).</div>
          <div class="table-container" style="max-height:320px;overflow-y:auto;">
            <table class="erp-table" style="margin:0;width:100%;font-size:11.5px;">
              <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;"><tr>
                <th>Fecha</th><th>Planilla</th><th>Sucursal</th><th>Auxiliar</th><th class="text-center">Pedidos</th>
                <th class="text-center">Llegada GPS</th><th class="text-center">Salida GPS</th><th class="text-center">Tiempo GPS</th>
                <th class="text-center">Registrado app</th><th class="text-center">Novedad</th><th class="text-center">Ubicación sucursal</th>
              </tr></thead>
              <tbody id="tmsd-entregas-det"><tr><td colspan="11" class="text-center" style="padding:16px;color:var(--text-muted);">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="card" style="padding:16px;">
          <div class="pro-section-title" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span><i class="fa-solid fa-box-open" style="margin-right:6px;color:#7c3aed;"></i> Detalle de novedades</span>
            <span id="tmsd-top-refs" style="display:flex;gap:6px;flex-wrap:wrap;"></span>
          </div>
          <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;">
            <table class="erp-table" style="margin:0;width:100%;font-size:11px;">
              <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;">
                <tr>
                  <th>Fecha</th><th>Sucursal</th><th>Auxiliar</th><th>Referencia</th>
                  <th>Causal</th><th class="text-center">Cantidad</th><th class="text-center">Devolución #</th>
                </tr>
              </thead>
              <tbody id="tmsd-detalle-novedades"><tr><td colspan="7" class="text-center" style="padding:16px;color:var(--text-muted);">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>`);

    await this._aplicarFiltros();
  },

  _aplicarFiltrosDebounced() {
    clearTimeout(this._filterTimer);
    this._filterTimer = setTimeout(() => this._aplicarFiltros(), 400);
  },

  async _aplicarFiltros() {
    try {
      const params = new URLSearchParams();
      const desde      = document.getElementById('tmsd-desde')?.value;
      const hasta      = document.getElementById('tmsd-hasta')?.value;
      const sucursal   = document.getElementById('tmsd-sucursal')?.value;
      const auxiliar   = document.getElementById('tmsd-auxiliar')?.value;
      const referencia = document.getElementById('tmsd-referencia')?.value;
      if (desde)      params.set('fecha_desde', desde);
      if (hasta)      params.set('fecha_hasta', hasta);
      if (sucursal)   params.set('sucursal_entrega', sucursal);
      if (auxiliar)   params.set('auxiliar', auxiliar);
      if (referencia) params.set('referencia', referencia);

      const r = await API.get('/tms/dashboard/resumen?' + params.toString());
      const d = r.data || {};

      this._renderKPIs(d.kpis || {});
      this._renderCharts(d);
      this._renderMatrizDemora(d.matriz_demora || []);
      this._renderEntregasDetalle(d.entregas_detalle || []);
      this._renderDetalleNovedades(d.detalle_novedades || [], d.top_referencias || []);
      this._renderFiltrosSelect('tmsd-sucursal', d.filtros?.sucursales || []);
      this._renderFiltrosSelect('tmsd-auxiliar', d.filtros?.auxiliares || []);
    } catch (e) {
      WMS.toast('error', 'Error al cargar el dashboard TMS');
    }
  },

  _renderFiltrosSelect(id, opciones) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const current = sel.value;
    const label = sel.querySelector('option[value=""]')?.textContent || '';
    sel.innerHTML = `<option value="">${label}</option>` +
      opciones.map(o => `<option value="${WMS.esc(o)}" ${current === o ? 'selected' : ''}>${WMS.esc(o)}</option>`).join('');
  },

  _renderKPIs(kpis) {
    const el = document.getElementById('tmsd-kpis');
    if (!el) return;
    const fmt = v => WMS.formatNum ? WMS.formatNum(v) : v;
    const cards = [
      { label: 'Entregas (planillas)', value: fmt(kpis.total_entregas || 0), sub: `${fmt(kpis.total_pedidos || 0)} pedidos`, icon: 'fa-truck', accent: 'accent-blue' },
      { label: 'Nivel de Servicio', value: (kpis.ns_referencias_pct ?? '—') + (kpis.ns_referencias_pct !== null ? '%' : ''), sub: `${fmt(kpis.refs_sin_novedad || 0)} / ${fmt(kpis.total_refs_aptas || 0)} refs sin novedad`, icon: 'fa-gauge-high', accent: 'accent-green' },
      { label: 'Sin Novedad', value: fmt(kpis.entregas_sin_novedad || 0), sub: 'Planillas entregadas OK', icon: 'fa-circle-check', accent: 'accent-green' },
      { label: 'Con Novedad', value: fmt(kpis.entregas_con_novedad || 0), sub: `${fmt(kpis.total_novedades || 0)} novedades reportadas`, icon: 'fa-triangle-exclamation', accent: 'accent-gray' },
      { label: 'Tiempo en sitio (GPS)', value: kpis.tiempo_promedio_gps_min != null ? fmt(kpis.tiempo_promedio_gps_min) + ' min' : '—',
        sub: kpis.entregas_con_gps ? `Promedio de ${kpis.entregas_con_gps} de ${kpis.total_entregas} entregas medidas por GPS` : 'Sin entregas medidas por GPS en el período', icon: 'fa-hourglass-half', accent: 'accent-amber' },
    ];
    el.innerHTML = cards.map(c => `
      <div class="pro-kpi-card ${c.accent}">
        <div class="pro-kpi-header">
          <div class="pro-kpi-icon"><i class="fa-solid ${c.icon}"></i></div>
        </div>
        <div class="pro-kpi-value">${c.value}</div>
        <div class="pro-kpi-label">${c.label}</div>
        <div class="pro-kpi-sub">${c.sub}</div>
      </div>`).join('');
  },

  _renderCharts(d) {
    const destroyChart = (id) => { if (this._chartInstances[id]) this._chartInstances[id].destroy(); };

    const ctxNs = document.getElementById('tmsd-chart-ns');
    if (ctxNs) {
      destroyChart('ns');
      this._chartInstances['ns'] = new Chart(ctxNs, {
        type: 'line',
        data: {
          labels: (d.ns_por_dia || []).map(x => x.fecha),
          datasets: [{ label: 'NS %', data: (d.ns_por_dia || []).map(x => x.ns_pct), borderColor: '#00b300', backgroundColor: 'rgba(0,179,0,.15)', fill: true, tension: .3 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100 } }, plugins: { legend: { display: false } } }
      });
    }

    const ctxT = document.getElementById('tmsd-chart-tiempos');
    if (ctxT) {
      destroyChart('tiempos');
      this._chartInstances['tiempos'] = new Chart(ctxT, {
        type: 'line',
        data: {
          labels: (d.tiempos_por_dia || []).map(x => x.fecha),
          datasets: [{ label: 'Minutos', data: (d.tiempos_por_dia || []).map(x => x.promedio), borderColor: '#2b5fc7', backgroundColor: 'rgba(15,76,129,.15)', fill: true, tension: .3 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });
    }

    const ctxS = document.getElementById('tmsd-chart-sucursales');
    if (ctxS) {
      destroyChart('sucursales');
      this._chartInstances['sucursales'] = new Chart(ctxS, {
        type: 'bar',
        data: {
          labels: (d.top_sucursales || []).map(x => x.sucursal),
          datasets: [{ label: 'Novedades', data: (d.top_sucursales || []).map(x => x.total_novedades), backgroundColor: this._paletteVivid }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, datalabels: { display: true, color: '#fff', anchor: 'end', align: 'start', offset: 4, font: { weight: '700', size: 12 }, formatter: v => v, clamp: true } }
        }
      });
    }
  },

  _renderEntregasDetalle(filas) {
    const tb = document.getElementById('tmsd-entregas-det');
    if (!tb) return;
    const h = t => t ? String(t).substring(11, 16) : '—';
    this._entregasDet = filas;
    tb.innerHTML = filas.length ? filas.map((e, i) => `
      <tr>
        <td>${WMS.formatDate ? WMS.formatDate(e.fecha) : e.fecha}</td>
        <td>${WMS.esc(e.planilla || '-')}</td>
        <td><b>${WMS.esc(e.sucursal || '-')}</b></td>
        <td>${WMS.esc(e.auxiliar || '-')}</td>
        <td class="text-center">${e.pedidos}</td>
        <td class="text-center">${h(e.llegada_gps)}</td>
        <td class="text-center">${h(e.salida_gps)}</td>
        <td class="text-center">${e.tiempo_gps_min != null ? `<b style="color:var(--accent);">${e.tiempo_gps_min} min</b>`
          : `<span style="color:var(--text-muted);" title="${e.puntos_gps ? 'Solo 1 posición GPS en el sitio' : 'Sin posiciones GPS en el sitio'}">${e.puntos_gps ? '1 punto' : 'sin GPS'}</span>`}</td>
        <td class="text-center">${e.tiempo_app_min != null ? `${e.tiempo_app_min} min` : '—'}<div style="font-size:10px;color:var(--text-muted);">${h(e.llegada_app)}–${h(e.salida_app)}</div></td>
        <td class="text-center">${e.tiene_novedad ? `<span style="color:var(--danger);font-weight:700;">${e.total_novedades}</span>` : '<span style="color:var(--ok);">OK</span>'}</td>
        <td class="text-center" style="white-space:nowrap;">${e.coord
          ? `<button class="btn btn-xs ${e.tiene_ubicacion ? 'btn-outline-secondary' : 'btn-outline-success'}" title="Origen del punto: ${WMS.esc(e.coord.fuente)}" onclick="WMS_MODULES.tms._entUbicar(${i})"><i class="fa-solid fa-location-dot"></i> ${e.tiene_ubicacion ? 'Corregir' : 'Asignar'}</button>`
          : '<span style="color:var(--text-muted);">sin coordenadas</span>'}</td>
      </tr>`).join('') : '<tr><td colspan="11" class="text-center" style="padding:16px;color:var(--text-muted);">Sin entregas en el período</td></tr>';
  },

  _renderMatrizDemora(filas) {
    const tbody = document.getElementById('tmsd-matriz-demora');
    if (!tbody) return;
    if (!filas.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center" style="padding:16px;color:var(--text-muted);">Sin datos en el período</td></tr>';
      return;
    }
    tbody.innerHTML = filas.map(f => `
      <tr>
        <td>${WMS.esc(f.sucursal || '-')}</td>
        <td class="text-center">${f.entregas}</td>
        <td class="text-center"><b>${f.promedio ?? '-'}</b> min</td>
        <td class="text-center">${f.minimo ?? '-'}</td>
        <td class="text-center">${f.maximo ?? '-'}</td>
        <td class="text-center">${f.con_gps ?? 0}/${f.entregas}</td>
      </tr>`).join('');
  },

  _renderDetalleNovedades(filas, topReferencias) {
    const topEl = document.getElementById('tmsd-top-refs');
    if (topEl) {
      topEl.innerHTML = (topReferencias || []).map(r => `
        <span class="badge" style="background:var(--warn-bg);color:var(--warn);" title="${WMS.esc(r.nombre)}">
          ${WMS.esc(r.codigo)} · ${r.veces}
        </span>`).join('') || '<span style="font-size:11px;color:var(--text-muted);">Sin referencias recurrentes</span>';
    }

    const tbody = document.getElementById('tmsd-detalle-novedades');
    if (!tbody) return;
    if (!filas.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:16px;color:var(--text-muted);">Sin novedades en el período</td></tr>';
      return;
    }
    tbody.innerHTML = filas.map(f => `
      <tr>
        <td>${f.fecha ? WMS.formatDate(f.fecha) : '-'}</td>
        <td>${WMS.esc(f.sucursal || '-')}</td>
        <td>${WMS.esc(f.auxiliar || '-')}</td>
        <td><b>${WMS.esc(f.codigo || '-')}</b><br><span style="color:var(--muted);">${WMS.esc(f.nombre || '')}</span></td>
        <td>${WMS.esc(f.causal || '-')}</td>
        <td class="text-center">${f.cantidad}</td>
        <td class="text-center">${f.consecutivo ? '#' + f.consecutivo : '-'}</td>
      </tr>`).join('');
  },

  /* ═══════════════════════════════════════════════════════════════════════
     B) MAPA EN VIVO
  ═══════════════════════════════════════════════════════════════════════ */
  async loadMapa() {
    this._activeTab = 'mapa';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    // BUG CORREGIDO 2026-10-01 ("cuando se ingresa de nuevo queda en blanco"):
    // el mapa anterior seguía atado al <div> viejo que setContent() ya borró.
    if (this._map) { try { this._map.remove(); } catch (e) {} this._map = null; }
    WMS.setToolbar(this._navBar('mapa'));

    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="padding:0;overflow:hidden;">
          <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
              <span class="pro-section-title" style="margin:0;"><i class="fa-solid fa-map-location-dot" style="margin-right:6px;color:var(--accent);"></i> Entregas — recorrido en vivo</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
              <div>
                <label class="form-label" style="display:block;margin-bottom:3px;">Fecha</label>
                <input type="date" id="tmsm-fecha" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._mapFit=true;WMS_MODULES.tms._refrescarMapa()">
              </div>
              <div>
                <label class="form-label" style="display:block;margin-bottom:3px;">Vehículo</label>
                <select id="tmsm-vehiculo" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.tms._mapFit=true;WMS_MODULES.tms._refrescarMapa()">
                  <option value="">Todos</option>
                </select>
              </div>
              <span style="font-size:11px;color:var(--muted);align-self:center;max-width:360px;">En tiempo real (cada 30 s) · GPS cada 5 min desde que la planilla se despacha hasta que el vehículo vuelve a la bodega. Sin filtro de vehículo se ven todos. <b id="tmsm-hora"></b></span>
            </div>
          </div>
          <div style="display:flex;">
            <div id="tmsd-map-panel" style="width:260px;flex-shrink:0;border-right:1px solid var(--border);max-height:560px;overflow-y:auto;background:var(--table-header-bg);"></div>
            <div style="flex:1;position:relative;">
              <div id="tmsd-map" style="height:560px;"></div>
              <div id="tmsd-map-error" style="display:none;position:absolute;inset:0;background:var(--table-header-bg);align-items:center;justify-content:center;text-align:center;padding:24px;">
                <div>
                  <i class="fa-solid fa-map-location-dot" style="font-size:32px;color:var(--text-muted);"></i>
                  <p style="color:var(--muted);font-size:13px;margin-top:8px;">No se pudo cargar el mapa (sin conexión al servicio de mapas).<br>Reintentando automáticamente...</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`);

    this._mapFit = true;
    await this._refrescarMapa();
    this._mapTimer = setInterval(() => {
      if (WMS.currentModule !== 'tms' || this._activeTab !== 'mapa') {
        clearInterval(this._mapTimer); this._mapTimer = null; return;
      }
      this._refrescarMapa();
    }, 30000);
  },

  // Ícono estilo Uber/Rappi: círculo de color sólido con el vehículo adentro
  // y un halo animado cuando está en curso (todavía en ruta).
  _iconoVehiculo(color, { enCurso = false, size = 34 } = {}) {
    const halo = enCurso ? `<span style="position:absolute;inset:-6px;border-radius:50%;background:${color};opacity:.35;animation:tmsPulse 1.6s ease-out infinite;"></span>` : '';
    return L.divIcon({
      html: `<div style="position:relative;width:${size}px;height:${size}px;">
        ${halo}
        <div style="position:relative;width:${size}px;height:${size}px;border-radius:50%;background:${color};border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid fa-truck" style="color:#fff;font-size:${size * 0.45}px;"></i>
        </div>
      </div>`,
      className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
    });
  },

  _iconoParada(color) {
    return L.divIcon({
      html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>`,
      className: '', iconSize: [14, 14], iconAnchor: [7, 7],
    });
  },

  async _refrescarMapa() {
    const errBox = document.getElementById('tmsd-map-error');
    try {
      if (!this._map) {
        if (typeof L === 'undefined') throw new Error('Leaflet no disponible');
        // OSM estándar — CartoDB Positron (fondo claro estilo Uber) se probó
        // primero pero ahora exige API key propia en su capa gratuita, así
        // que no sirve sin cuenta; se prioriza que el mapa SIEMPRE cargue.
        this._map = L.map('tmsd-map', { zoomControl: true }).setView([4.6097, -74.0817], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap',
          maxZoom: 19,
        }).addTo(this._map);
        this._markers = L.layerGroup().addTo(this._map);
        this._lineas = L.layerGroup().addTo(this._map);
        if (!document.getElementById('tms-map-pulse-style')) {
          const st = document.createElement('style');
          st.id = 'tms-map-pulse-style';
          st.textContent = '@keyframes tmsPulse{0%{transform:scale(.6);opacity:.5;}100%{transform:scale(1.8);opacity:0;}}';
          document.head.appendChild(st);
        }
      }
      if (errBox) errBox.style.display = 'none';

      const fecha    = document.getElementById('tmsm-fecha')?.value || '';
      const vehiculo = document.getElementById('tmsm-vehiculo')?.value || '';
      const params = new URLSearchParams();
      if (fecha) params.set('fecha', fecha);
      if (vehiculo) params.set('vehiculo', vehiculo);

      const r = await API.get('/tms/dashboard/mapa?' + params.toString());
      const d = r.data || {};
      this._markers.clearLayers();
      this._lineas.clearLayers();
      if (d.bodega) {
        L.circle([d.bodega.lat, d.bodega.lng], { radius: d.bodega.radio_m, color: '#2b5fc7', weight: 1.5, fillOpacity: .08, dashArray: '4 4' }).addTo(this._lineas);
        L.marker([d.bodega.lat, d.bodega.lng], { icon: L.divIcon({
          html: '<div style="width:30px;height:30px;border-radius:8px;background:#2b5fc7;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-warehouse" style="color:#fff;font-size:13px;"></i></div>',
          className: '', iconSize: [30, 30], iconAnchor: [15, 15] }) })
          .bindPopup('<b>Bodega</b><br>El GPS de la ruta se detiene al volver a entrar aquí.')
          .addTo(this._markers);
      }
      this._renderFiltrosSelect('tmsm-vehiculo', d.filtros?.vehiculos || []);

      const bounds = [];
      let ci = 0;
      const colorPorAux = {};

      // Recorrido: casing oscuro + línea de color encima (look Uber) — une
      // los puntos en el orden cronológico que ya viene armado del backend.
      Object.entries(d.rutas || {}).forEach(([aux, puntos]) => {
        if (!puntos || !puntos.length) return;
        const color = this._paletteVivid[ci++ % this._paletteVivid.length];
        colorPorAux[aux] = color;
        const latlngs = puntos.map(p => [p.lat, p.lng]);
        if (latlngs.length > 1) {
          // Mientras llega el trazado por calles (OSRM) se muestra la línea
          // recta punteada; si el servicio no responde, se queda así.
          const capa = this._lineas;
          const recta = L.polyline(latlngs, { color, weight: 3, opacity: .55, dashArray: '6 8' }).addTo(capa);
          this._rutaPorCalles(latlngs).then(geo => {
            if (!geo || !capa.hasLayer(recta)) return;
            capa.removeLayer(recta);
            L.polyline(geo, { color: '#1e293b', weight: 6, opacity: .25, lineCap: 'round', lineJoin: 'round' }).addTo(capa);
            L.polyline(geo, { color, weight: 4, opacity: .9, lineCap: 'round', lineJoin: 'round' }).addTo(capa);
          });
        }
        // Punto de inicio del recorrido (parada más antigua).
        L.marker(latlngs[0], { icon: this._iconoParada(color) })
          .bindPopup(`<b>${WMS.esc(aux)}</b><br>Inicio del recorrido<br>${WMS.esc(puntos[0].sucursal || '')}`)
          .addTo(this._markers);
        puntos.forEach(p => bounds.push([p.lat, p.lng]));
      });

      // Nota: si hay un vehículo específico filtrado, "en curso" se omite —
      // el TMS no sabe qué vehículo tiene cada auxiliar en el momento
      // (relación auxiliar→vehículo solo existe por planilla, no en vivo),
      // así que mostrarlo igual sería mezclar camiones que no son el
      // filtrado. A pedido explícito de Camilo (2026-09-29): "el filtro solo
      // debe mostrar el carro seleccionado".
      const enCursoFiltrado = vehiculo ? [] : (d.en_curso || []);

      // ── Vista tipo Uber/Rappi (2026-10-02): un grupo por vehículo con su
      // estado en vivo y la línea de tiempo del día (salida → entregas y
      // paradas → regreso), pines numerados de entregas y de paradas > 15 min.
      const hhmm = t => t ? new Date(String(t).replace(' ', 'T')).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }) : '-';
      const grupos = {};
      const grupo = (aux) => (grupos[aux] = grupos[aux] || { nombre: aux, color: colorPorAux[aux] || '#2b5fc7', sub: '', estado: '', eventos: [], vivo: null });
      this._mapPuntos = [];
      const registrar = (latlng) => { this._mapPuntos.push(latlng); return this._mapPuntos.length - 1; };

      // Posición actual por GPS (último ping de cada auxiliar). Más de 15
      // min sin señal = app cerrada / sin cobertura: se pinta gris.
      (d.vivos || []).forEach(v => {
        const p = v.ultimo_punto;
        if (!p) return;
        const conSenal = v.ruta_activa && v.minutos_sin_senal <= 15;
        const color = v.regreso_bodega_at ? '#2b5fc7' : (conSenal ? (colorPorAux[v.auxiliar_nombre] || '#2b5fc7') : '#94a3b8');
        const estadoRuta = v.regreso_bodega_at ? `Regresó a bodega ${hhmm(v.regreso_bodega_at)}`
          : (v.ruta_activa ? (conSenal ? 'En ruta' : 'En ruta — sin señal reciente') : 'Sin ruta activa');
        const hace = v.minutos_sin_senal < 1 ? 'hace menos de 1 min' : `hace ${v.minutos_sin_senal} min`;
        L.marker([p.lat, p.lng], { icon: this._iconoVehiculo(color, { enCurso: conSenal, size: 40 }), zIndexOffset: 1000 })
          .bindTooltip(`${WMS.esc(v.placa || v.auxiliar_nombre)}`, { permanent: true, direction: 'top', offset: [0, -20], className: 'tms-lbl-veh' })
          .bindPopup(`<div style="font-size:12.5px;"><b>${WMS.esc(v.auxiliar_nombre)}</b>${v.placa ? ' · ' + WMS.esc(v.placa) : ''}${v.numero_despacho ? '<br>' + WMS.esc(v.numero_despacho) : ''}<br><b style="color:${color};">${estadoRuta}</b><br>Última posición: ${hhmm(v.ultima_hora)} (${hace})<br>${v.total_puntos} punto${v.total_puntos !== 1 ? 's' : ''} de recorrido</div>`)
          .addTo(this._markers);
        bounds.push([p.lat, p.lng]);
        const g = grupo(v.auxiliar_nombre);
        Object.assign(g, { color, sub: `${v.placa ? v.placa + ' · ' : ''}Señal ${hace}`, estado: estadoRuta, vivo: registrar([p.lat, p.lng]) });
        const bod = d.bodega ? registrar([d.bodega.lat, d.bodega.lng]) : null;
        if (v.salio_bodega_at) g.eventos.push({ ts: v.salio_bodega_at, icono: 'fa-truck-fast', color: '#2b5fc7', titulo: 'Salida de bodega', det: hhmm(v.salio_bodega_at), idx: bod });
        if (v.regreso_bodega_at) g.eventos.push({ ts: v.regreso_bodega_at, icono: 'fa-warehouse', color: '#2b5fc7', titulo: 'Regreso a bodega', det: hhmm(v.regreso_bodega_at), idx: bod });
      });

      // Entregas confirmadas: pin de tienda numerado por orden del día.
      const entregasPorAux = {};
      (d.confirmadas || []).forEach(c => {
        const pt = c.puntos?.llegada || c.puntos?.salida || Object.values(c.puntos || {}).pop();
        if (!pt) return;
        (entregasPorAux[c.auxiliar_nombre || 'Auxiliar'] = entregasPorAux[c.auxiliar_nombre || 'Auxiliar'] || []).push({ ...c, pt });
      });
      Object.entries(entregasPorAux).forEach(([aux, lista]) => {
        // Varios pedidos de la misma visita llegan como filas separadas: una sola parada.
        const vistos = {};
        lista = lista.filter(c => { const k = c.sucursal + '|' + (c.hora_salida || ''); if (vistos[k]) return false; vistos[k] = 1; return true; })
          .sort((a, b) => String(a.hora_salida || a.hora_llegada || '').localeCompare(String(b.hora_salida || b.hora_llegada || '')));
        lista.forEach((c, i) => {
          const color = c.tiene_novedad ? '#dc2626' : '#16a34a';
          const demora = c.hora_llegada && c.hora_salida ? Math.round((new Date(c.hora_salida.replace(' ', 'T')) - new Date(c.hora_llegada.replace(' ', 'T'))) / 60000) : null;
          L.marker([c.pt.lat, c.pt.lng], { icon: this._iconoEntrega(color, i + 1), zIndexOffset: 500 })
            .bindTooltip(WMS.esc(c.sucursal), { permanent: true, direction: 'right', offset: [14, -18], className: 'tms-lbl-suc' })
            .bindPopup(`<div style="font-size:12.5px;"><b>Entrega ${i + 1} — ${WMS.esc(c.sucursal)}</b><br>${WMS.esc(aux)}${c.placa ? ' · ' + WMS.esc(c.placa) : ''}<br>${c.hora_llegada ? 'Llegada: ' + hhmm(c.hora_llegada) + ' · ' : ''}Salida: ${hhmm(c.hora_salida)}${demora != null ? `<br>Tiempo en sitio: <b>${demora} min</b>` : ''}<br><span style="color:${color};font-weight:700;">${c.tiene_novedad ? 'Con novedad' : 'Sin novedad'}</span><br><span style="color:#94a3b8;font-size:11px;">${c.pt.lat.toFixed(5)}, ${c.pt.lng.toFixed(5)}</span></div>`)
            .addTo(this._markers);
          bounds.push([c.pt.lat, c.pt.lng]);
          grupo(aux).eventos.push({ ts: c.hora_llegada || c.hora_salida, icono: 'fa-store', color, titulo: `Entrega ${i + 1} · ${c.sucursal}`,
            det: `${c.hora_llegada ? hhmm(c.hora_llegada) + '–' + hhmm(c.hora_salida) : 'Confirmada ' + hhmm(c.hora_salida)}${demora != null ? ' · ' + demora + ' min' : ''}${c.tiene_novedad ? ' · con novedad' : ''}`, idx: registrar([c.pt.lat, c.pt.lng]) });
        });
      });

      // Paradas de más de 15 min del día (fuera de la bodega), numeradas.
      const paradasPorAux = {};
      (d.paradas || []).forEach(pa => (paradasPorAux[pa.auxiliar] = paradasPorAux[pa.auxiliar] || []).push(pa));
      Object.entries(paradasPorAux).forEach(([aux, lista]) => {
        lista.sort((a, b) => a.inicio.localeCompare(b.inicio)).forEach((pa, i) => {
          const c = pa.tipo === 'No programada' ? '#dc2626' : '#0891b2';
          L.marker([pa.lat, pa.lng], { icon: this._iconoParadaLarga(c, pa.duracion_min), zIndexOffset: 700 })
            .bindPopup(`<div style="font-size:12.5px;"><b>Parada ${i + 1} — ${WMS.esc(pa.tipo)}</b>${pa.lugar ? '<br>' + WMS.esc(pa.lugar) : ''}<br>${WMS.esc(aux)}${pa.placa ? ' · ' + WMS.esc(pa.placa) : ''}<br><b>${pa.duracion_min} min</b> detenido${pa.en_curso ? ' (en curso)' : ''} · ${pa.inicio.substring(11, 16)}–${pa.fin.substring(11, 16)}<br><span style="color:#94a3b8;font-size:11px;">${pa.lat}, ${pa.lng}</span></div>`)
            .addTo(this._markers);
          bounds.push([pa.lat, pa.lng]);
          grupo(aux).eventos.push({ ts: pa.inicio, icono: 'fa-stopwatch', color: c, titulo: `Parada ${i + 1} · ${pa.tipo}${pa.lugar ? ' · ' + pa.lugar : ''}`,
            det: `${pa.inicio.substring(11, 16)}–${pa.fin.substring(11, 16)} · ${pa.duracion_min} min${pa.en_curso ? ' (en curso)' : ''}`, idx: registrar([pa.lat, pa.lng]) });
        });
      });

      // Planillas despachadas sin ninguna posición todavía.
      (d.sin_senal || []).forEach(v => {
        const g = grupo(v.auxiliar_nombre);
        Object.assign(g, { color: '#dc2626', sub: `${v.placa ? v.placa + ' · ' : ''}${v.numero_despacho || ''}`, estado: 'Despachado — sin señal GPS (abrir la app TMS)' });
      });

      enCursoFiltrado.forEach(v => {
        const p = v.ultimo_punto;
        if (!p) return;
        const color = v.estado === 'en_ruta' ? '#e8a000' : '#64748b';
        const g = grupo(v.auxiliar_nombre || 'Auxiliar');
        g.eventos.push({ ts: v.hora_llegada, icono: 'fa-person-walking-luggage', color, titulo: `En sitio · ${v.sucursal}`, det: `Llegó ${hhmm(v.hora_llegada)} — entrega en curso`, idx: registrar([p.lat, p.lng]) });
        if (!g.estado) Object.assign(g, { color, sub: v.sucursal, estado: 'En sitio' });
      });

      this._renderPanelVehiculos(Object.values(grupos));

      // Encuadra solo al entrar o al cambiar un filtro — si se hiciera en
      // cada refresco (30 s) le quitaría el zoom/paneo al usuario.
      if (bounds.length && this._mapFit) { this._map.flyToBounds(bounds, { maxZoom: 14, padding: [40, 40], duration: .6 }); this._mapFit = false; }
      const hEl = document.getElementById('tmsm-hora');
      if (hEl) hEl.textContent = 'Actualizado ' + new Date().toLocaleTimeString('es-CO');
    } catch (e) {
      if (errBox) errBox.style.display = 'flex';
    }
  },

  // Pin de entrega estilo Rappi: gota con tienda y número de orden.
  _iconoEntrega(color, n) {
    return L.divIcon({
      html: `<div style="position:relative;width:30px;height:40px;">
        <div style="position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:${color};border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);"></div>
        <div style="position:absolute;left:0;top:4px;width:30px;text-align:center;color:#fff;font-size:11px;font-weight:900;line-height:1;"><i class="fa-solid fa-store" style="font-size:9px;"></i><br>${n}</div>
      </div>`,
      className: '', iconSize: [30, 40], iconAnchor: [15, 38], popupAnchor: [0, -34],
    });
  },

  // Parada larga: chip con reloj y minutos, borde blanco (se lee sobre el mapa).
  _iconoParadaLarga(color, min) {
    return L.divIcon({
      html: `<div style="display:inline-flex;align-items:center;gap:4px;background:${color};color:#fff;border:2px solid #fff;border-radius:14px;padding:3px 8px;font-size:11px;font-weight:900;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.4);"><i class="fa-solid fa-stopwatch"></i>${min} min</div>`,
      className: '', iconSize: null, iconAnchor: [30, 12],
    });
  },

  // Panel lateral: un bloque por vehículo con su estado en vivo y la línea de
  // tiempo del día; clic en un evento lleva el mapa a ese punto.
  _renderPanelVehiculos(grupos) {
    const el = document.getElementById('tmsd-map-panel');
    if (!el) return;
    if (!document.getElementById('tms-map-lbl-style')) {
      const st = document.createElement('style');
      st.id = 'tms-map-lbl-style';
      st.textContent = '.tms-lbl-suc,.tms-lbl-veh{background:#fff;border:none;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.3);font-size:11px;font-weight:800;color:#1e293b;padding:2px 6px;}'
        + '.tms-lbl-suc:before,.tms-lbl-veh:before{display:none;}.tms-lbl-veh{background:#0f172a;color:#fff;}'
        + '.tms-ev:hover{background:#eef2ff;cursor:pointer;}';
      document.head.appendChild(st);
    }
    if (!grupos.length) {
      el.innerHTML = '<div style="padding:16px;font-size:12px;color:var(--text-muted);text-align:center;">Sin vehículos para mostrar en el rango seleccionado.</div>';
      return;
    }
    el.innerHTML = `<div style="padding:10px 14px;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);">Vehículos (${grupos.length})</div>` +
      grupos.map(g => {
        const evs = g.eventos.filter(e => e.ts).sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
        return `
        <div style="border-bottom:1px solid var(--border);">
          <div class="tms-ev" style="padding:10px 14px;display:flex;gap:10px;align-items:flex-start;" ${g.vivo != null ? `onclick="WMS_MODULES.tms._irA(${g.vivo})"` : ''}>
            <div style="width:12px;height:12px;border-radius:50%;background:${g.color};margin-top:3px;flex-shrink:0;box-shadow:0 0 0 3px ${g.color}33;"></div>
            <div style="min-width:0;">
              <div style="font-size:12.5px;font-weight:800;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(g.nombre)}</div>
              <div style="font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(g.sub || '')}</div>
              ${g.estado ? `<div style="font-size:10.5px;font-weight:700;color:${g.color};margin-top:2px;">${WMS.esc(g.estado)}</div>` : ''}
            </div>
          </div>
          ${evs.length ? `<div style="padding:0 14px 10px 20px;">${evs.map((e, i) => `
            <div class="tms-ev" style="display:flex;gap:8px;position:relative;padding:4px 4px 6px;border-radius:6px;" ${e.idx != null ? `onclick="WMS_MODULES.tms._irA(${e.idx})"` : ''}>
              <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
                <div style="width:20px;height:20px;border-radius:50%;background:${e.color};color:#fff;display:flex;align-items:center;justify-content:center;font-size:9px;"><i class="fa-solid ${e.icono}"></i></div>
                ${i < evs.length - 1 ? '<div style="width:2px;flex:1;min-height:10px;background:var(--border);margin-top:2px;"></div>' : ''}
              </div>
              <div style="min-width:0;">
                <div style="font-size:11.5px;font-weight:700;color:var(--text);">${WMS.esc(e.titulo)}</div>
                <div style="font-size:10.5px;color:var(--muted);">${WMS.esc(e.det || '')}</div>
              </div>
            </div>`).join('')}</div>` : ''}
        </div>`;
      }).join('');
  },

  _irA(i) {
    const p = (this._mapPuntos || [])[i];
    if (p && this._map) this._map.flyTo(p, 16, { duration: .6 });
  },

  /* ═══════════════════════════════════════════════════════════════════════
     C) GESTIÓN DE PEDIDOS — a pedido de Camilo (2026-10-01): activar /
     desactivar, limpiar registros (rehacer la entrega) y transferir a otro
     auxiliar los pedidos de las planillas cargadas. Reemplaza "Reabrir
     Pedidos" (lo de reabrir ahora es "Limpiar registros").
  ═══════════════════════════════════════════════════════════════════════ */
  _gpSel: new Set(),
  _gpPedidos: [],
  _gpAuxiliares: [],

  async loadGestionPedidos() {
    this._activeTab = 'gestion';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('gestion'));
    this._gpSel = new Set();

    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);
    const lbl = t => `<label class="form-label" style="display:block;margin-bottom:3px;">${t}</label>`;
    const onF = 'onchange="WMS_MODULES.tms._gpCargar()"';

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="margin-bottom:12px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>${lbl('Desde')}<input type="date" id="gp-desde" class="form-control form-control-sm" value="${hoy}" ${onF}></div>
            <div>${lbl('Hasta')}<input type="date" id="gp-hasta" class="form-control form-control-sm" value="${hoy}" ${onF}></div>
            <div>${lbl('Auxiliar')}<select id="gp-auxiliar" class="form-control form-control-sm" style="min-width:170px;" ${onF}><option value="">Todos los auxiliares</option></select></div>
            <div>${lbl('Sucursal')}<select id="gp-sucursal" class="form-control form-control-sm" style="min-width:170px;" ${onF}><option value="">Todas las sucursales</option></select></div>
            <div>${lbl('Planilla')}<select id="gp-planilla" class="form-control form-control-sm" style="min-width:140px;" ${onF}><option value="">Todas las planillas</option></select></div>
            <div>${lbl('Estado')}<select id="gp-activo" class="form-control form-control-sm" ${onF}>
              <option value="">Activos e inactivos</option><option value="1">Solo activos</option><option value="0">Solo inactivos</option></select></div>
            <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.tms._gpLimpiarFiltros()"><i class="fa-solid fa-eraser"></i> Limpiar filtros</button>
          </div>
        </div>

        <div id="gp-bulk" class="card" style="margin-bottom:12px;display:none;">
          <div style="padding:10px 18px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:var(--info-bg);">
            <b id="gp-bulk-n" style="font-size:13px;color:var(--accent);margin-right:6px;"></b>
            <button class="btn btn-success btn-sm" onclick="WMS_MODULES.tms._gpAccion('activar')"><i class="fa-solid fa-toggle-on"></i> Activar</button>
            <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.tms._gpAccion('desactivar')"><i class="fa-solid fa-toggle-off"></i> Desactivar</button>
            <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.tms._gpAccion('limpiar')"><i class="fa-solid fa-broom"></i> Limpiar registros</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.tms._gpTransferir()"><i class="fa-solid fa-people-arrows"></i> Transferir a otro auxiliar</button>
            <button class="btn btn-link btn-sm" onclick="WMS_MODULES.tms._gpSel.clear();WMS_MODULES.tms._gpRender()">Quitar selección</button>
          </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
          <div class="table-container" style="max-height:600px;overflow-y:auto;">
            <table class="erp-table" style="margin:0;width:100%;font-size:12px;">
              <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;">
                <tr>
                  <th style="width:28px;"><input type="checkbox" id="gp-all" onchange="WMS_MODULES.tms._gpToggleAll(this.checked)"></th>
                  <th>Fecha</th><th>Planilla</th><th>Pedido</th><th>Sucursal</th><th>Auxiliar asignado</th><th>Vehículo</th>
                  <th class="text-center">Líneas</th><th>Entrega</th><th class="text-center">Estado</th><th class="text-center">Acciones</th>
                </tr>
              </thead>
              <tbody id="gp-tbody"><tr><td colspan="11" class="text-center" style="padding:20px;color:var(--text-muted);">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
      <div id="tmsr-modal"></div>`);

    await this._gpCargar();
  },

  _gpLimpiarFiltros() {
    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);
    ['gp-auxiliar', 'gp-sucursal', 'gp-planilla', 'gp-activo'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    document.getElementById('gp-desde').value = hoy;
    document.getElementById('gp-hasta').value = hoy;
    this._gpCargar();
  },

  // Rellena un <select> conservando la opción elegida (las opciones cambian
  // según el rango de fechas — filtro dinámico).
  _gpOpciones(id, items, primera) {
    const sel = document.getElementById(id);
    if (!sel) return;
    const actual = sel.value;
    sel.innerHTML = `<option value="">${primera}</option>` + items.map(it => {
      const v = typeof it === 'object' ? it.id : it, t = typeof it === 'object' ? it.nombre : it;
      return `<option value="${WMS.esc(String(v))}" ${String(v) === actual ? 'selected' : ''}>${WMS.esc(t)}</option>`;
    }).join('');
  },

  async _gpCargar() {
    const val = id => document.getElementById(id)?.value || '';
    const params = new URLSearchParams();
    [['fecha_desde', 'gp-desde'], ['fecha_hasta', 'gp-hasta'], ['auxiliar_id', 'gp-auxiliar'],
     ['sucursal', 'gp-sucursal'], ['planilla', 'gp-planilla'], ['activo', 'gp-activo']]
      .forEach(([k, id]) => { if (val(id) !== '') params.set(k, val(id)); });
    try {
      const r = await API.get('/tms/dashboard/gestion-pedidos?' + params.toString());
      const d = r.data || {};
      this._gpPedidos = d.pedidos || [];
      this._gpAuxiliares = d.auxiliares || [];
      this._gpOpciones('gp-auxiliar', d.filtros?.auxiliares || [], 'Todos los auxiliares');
      this._gpOpciones('gp-sucursal', d.filtros?.sucursales || [], 'Todas las sucursales');
      this._gpOpciones('gp-planilla', d.filtros?.planillas || [], 'Todas las planillas');
      const vivos = new Set(this._gpPedidos.map(p => p.orden_picking_id));
      this._gpSel = new Set([...this._gpSel].filter(id => vivos.has(id)));
      this._gpRender();
    } catch (e) {
      WMS.toast('error', 'Error al cargar los pedidos del TMS');
    }
  },

  _gpRender() {
    const tbody = document.getElementById('gp-tbody');
    if (!tbody) return;
    const ps = this._gpPedidos;
    const colorEntrega = { 'Entregado': 'var(--ok)', 'En Ruta': 'var(--warn)', 'Visita Iniciada': '#0891b2', 'Pendiente': 'var(--muted)' };
    tbody.innerHTML = ps.length ? ps.map(p => {
      const id = p.orden_picking_id;
      return `
      <tr style="${p.activo ? '' : 'opacity:.55;'}">
        <td><input type="checkbox" ${this._gpSel.has(id) ? 'checked' : ''} onchange="WMS_MODULES.tms._gpToggle(${id}, this.checked)"></td>
        <td>${p.fecha_planilla ? WMS.formatDate(p.fecha_planilla) : '-'}</td>
        <td>${WMS.esc(p.planilla_numero || '-')}</td>
        <td><b>${WMS.esc(p.numero_pedido || '#' + id)}</b></td>
        <td>${WMS.esc(p.sucursal_entrega || '-')}</td>
        <td>${p.auxiliar_asignado ? WMS.esc(p.auxiliar_asignado) : '<span style="color:var(--text-muted);">Sin asignar</span>'}</td>
        <td>${WMS.esc(p.placa || '-')}</td>
        <td class="text-center">${p.total_lineas}</td>
        <td><span style="color:${colorEntrega[p.estado] || 'var(--muted)'};font-weight:700;">${WMS.esc(p.estado)}</span>${p.despacho_liquidado ? '<div style="font-size:10px;color:var(--text-muted);">Planilla liquidada</div>' : ''}</td>
        <td class="text-center">${p.activo
          ? '<span style="background:var(--ok-bg);color:var(--ok);padding:2px 8px;border-radius:10px;font-weight:700;font-size:11px;">Activo</span>'
          : '<span style="background:var(--btn-neutral);color:var(--text2);padding:2px 8px;border-radius:10px;font-weight:700;font-size:11px;">Inactivo</span>'}</td>
        <td class="text-center" style="white-space:nowrap;">
          <button class="btn btn-xs btn-outline-primary" title="Ver detalle" onclick="WMS_MODULES.tms._verDetallePedido(${id})"><i class="fa-solid fa-eye"></i></button>
          <button class="btn btn-xs ${p.activo ? 'btn-outline-secondary' : 'btn-outline-success'}" title="${p.activo ? 'Desactivar' : 'Activar'}" onclick="WMS_MODULES.tms._gpAccion('${p.activo ? 'desactivar' : 'activar'}', [${id}])"><i class="fa-solid ${p.activo ? 'fa-toggle-off' : 'fa-toggle-on'}"></i></button>
          <button class="btn btn-xs btn-outline-warning" title="Limpiar registros (rehacer la entrega)" onclick="WMS_MODULES.tms._gpAccion('limpiar', [${id}])"><i class="fa-solid fa-broom"></i></button>
          ${p.entregado ? '' : `<button class="btn btn-xs btn-outline-primary" title="Transferir a otro auxiliar" onclick="WMS_MODULES.tms._gpTransferir([${id}])"><i class="fa-solid fa-people-arrows"></i></button>`}
        </td>
      </tr>`;
    }).join('') : '<tr><td colspan="11" class="text-center" style="padding:20px;color:var(--text-muted);">Sin pedidos con los filtros seleccionados</td></tr>';

    const n = this._gpSel.size;
    const bulk = document.getElementById('gp-bulk');
    if (bulk) bulk.style.display = n ? 'block' : 'none';
    const bn = document.getElementById('gp-bulk-n');
    if (bn) bn.textContent = `${n} pedido${n !== 1 ? 's' : ''} seleccionado${n !== 1 ? 's' : ''}`;
    const all = document.getElementById('gp-all');
    if (all) all.checked = ps.length > 0 && n === ps.length;
  },

  _gpToggle(id, on) { on ? this._gpSel.add(id) : this._gpSel.delete(id); this._gpRender(); },
  _gpToggleAll(on) { this._gpSel = on ? new Set(this._gpPedidos.map(p => p.orden_picking_id)) : new Set(); this._gpRender(); },

  async _gpAccion(accion, ids, extra = {}) {
    ids = ids || [...this._gpSel];
    if (!ids.length) return;
    const n = ids.length, s = n !== 1 ? 's' : '';
    const textos = {
      activar:    `¿Activar ${n} pedido${s}? Volverán a aparecerle al auxiliar en el TMS (los no entregados pasan a la planilla de hoy).`,
      desactivar: `¿Desactivar ${n} pedido${s}? Dejarán de aparecerle al auxiliar en el TMS. No se borra nada; se pueden volver a activar.`,
      limpiar:    `⚠️ Limpiar registros de ${n} pedido${s}.\n\nSe BORRA la entrega registrada (firma, horas, novedades) y el auxiliar tendrá que hacerla de nuevo completa en el TMS.\n\n¿Continuar?`,
    };
    if (textos[accion] && !confirm(textos[accion])) return;
    try {
      const r = await API.post('/tms/dashboard/gestion-pedidos/accion', { accion, orden_ids: ids, ...extra });
      if (r.error) throw new Error(r.message);
      WMS.toast((r.data?.fallidos || []).length ? 'warning' : 'success', r.message || 'Listo');
      ids.forEach(id => this._gpSel.delete(id));
      document.getElementById('gp-transfer-overlay')?.remove();
      this._gpCargar();
    } catch (e) {
      WMS.toast('error', e.message || 'Error aplicando la acción');
    }
  },

  _gpTransferir(ids) {
    ids = ids || [...this._gpSel];
    if (!ids.length) return;
    const actuales = new Set(this._gpPedidos.filter(p => ids.includes(p.orden_picking_id)).map(p => p.auxiliar_personal_id));
    const ov = document.createElement('div');
    ov.id = 'gp-transfer-overlay';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9600;display:flex;align-items:center;justify-content:center;';
    ov.innerHTML = `
      <div style="background:var(--surface);border-radius:10px;padding:22px 26px;width:420px;max-width:94vw;box-shadow:0 12px 40px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 6px;font-size:15px;"><i class="fa-solid fa-people-arrows" style="color:var(--accent);"></i> Transferir ${ids.length} pedido${ids.length !== 1 ? 's' : ''}</h3>
        <p style="font-size:12px;color:var(--muted);margin:0 0 12px;">El pedido le aparecerá al auxiliar seleccionado en su planilla de hoy del TMS y deja de verlo el anterior.</p>
        <select id="gp-transfer-aux" class="form-control">
          <option value="">— Seleccione el auxiliar destino —</option>
          ${this._gpAuxiliares.filter(a => !(actuales.size === 1 && actuales.has(a.id)))
            .map(a => `<option value="${a.id}">${WMS.esc(a.nombre)} — doc. ${WMS.esc(a.documento || 's/n')}</option>`).join('')}
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
          <button class="btn btn-secondary btn-sm" onclick="document.getElementById('gp-transfer-overlay').remove()">Cancelar</button>
          <button class="btn btn-primary btn-sm" id="gp-transfer-ok"><i class="fa-solid fa-check"></i> Transferir</button>
        </div>
      </div>`;
    document.body.appendChild(ov);
    document.getElementById('gp-transfer-ok').onclick = () => {
      const aux = document.getElementById('gp-transfer-aux').value;
      if (!aux) { WMS.toast('warning', 'Seleccione el auxiliar destino'); return; }
      this._gpAccion('transferir', ids, { auxiliar_id: parseInt(aux) });
    };
  },

  /* ═══════════════════════════════════════════════════════════════════════
     D) TIEMPOS DE PARO — a pedido de Camilo (2026-10-01). Parada = posiciones
     GPS dentro de un radio durante al menos el umbral (parámetros editables,
     igual que en plataformas como Satrack/Wialon). Clasificación: Bodega /
     En cliente (visita registrada en el TMS o geocerca del maestro de
     clientes) / No programada.
  ═══════════════════════════════════════════════════════════════════════ */
  async loadParadas() {
    this._activeTab = 'paradas';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('paradas'));
    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);
    const lbl = t => `<label class="form-label" style="display:block;margin-bottom:3px;">${t}</label>`;
    const onF = 'onchange="WMS_MODULES.tms._parCargar()"';
    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="margin-bottom:12px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>${lbl('Desde')}<input type="date" id="par-desde" class="form-control form-control-sm" value="${hoy}" ${onF}></div>
            <div>${lbl('Hasta')}<input type="date" id="par-hasta" class="form-control form-control-sm" value="${hoy}" ${onF}></div>
            <div>${lbl('Vehículo')}<select id="par-vehiculo" class="form-control form-control-sm" style="min-width:120px;" ${onF}><option value="">Todos</option></select></div>
            <div>${lbl('Auxiliar')}<select id="par-auxiliar" class="form-control form-control-sm" style="min-width:160px;" ${onF}><option value="">Todos</option></select></div>
            <div>${lbl('Tipo de parada')}<select id="par-tipo" class="form-control form-control-sm" ${onF}>
              <option value="">Todas</option><option>En cliente</option><option>No programada</option><option>Bodega</option></select></div>
            <div style="border-left:1px solid var(--border);padding-left:10px;display:flex;gap:8px;align-items:flex-end;" title="Parámetros de detección">
              <div>${lbl('Parada desde (min)')}<input type="number" id="par-umbral" class="form-control form-control-sm" value="15" min="1" max="240" style="width:90px;" ${onF}></div>
              <div>${lbl('Radio parada (m)')}<input type="number" id="par-radio" class="form-control form-control-sm" value="150" min="30" max="1000" style="width:90px;" ${onF}></div>
              <div>${lbl('Geocerca cliente (m)')}<input type="number" id="par-radio-cli" class="form-control form-control-sm" value="150" min="30" max="2000" style="width:100px;" ${onF}></div>
            </div>
            <button class="btn btn-success btn-sm" onclick="WMS_MODULES.tms._parExportar()"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
          </div>
          <div style="padding:0 18px 10px;font-size:11px;color:var(--muted);">Con GPS cada 5 min la duración es la mínima comprobada (±5 min). Las paradas en cliente se reconocen por la visita registrada en el TMS o por la ubicación del cliente en el maestro.</div>
        </div>
        <div id="par-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:12px;"></div>
        <div class="card" style="padding:0;overflow:hidden;">
          <div class="table-container" style="max-height:560px;overflow-y:auto;">
            <table class="erp-table" style="margin:0;width:100%;font-size:12px;">
              <thead style="position:sticky;top:0;background:var(--table-header-bg);z-index:1;"><tr>
                <th>Inicio</th><th>Fin</th><th class="text-center">Duración</th><th>Tipo</th><th>Lugar / dirección</th>
                <th>Vehículo</th><th>Planilla</th><th>Auxiliar</th><th class="text-center">Registrado en visita</th><th class="text-center">Acciones</th>
              </tr></thead>
              <tbody id="par-tbody"><tr><td colspan="10" class="text-center" style="padding:20px;color:var(--text-muted);">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>`);
    await this._parCargar();
  },

  _parQs() {
    const v = id => document.getElementById(id)?.value || '';
    const q = new URLSearchParams();
    [['fecha_desde', 'par-desde'], ['fecha_hasta', 'par-hasta'], ['vehiculo', 'par-vehiculo'], ['auxiliar', 'par-auxiliar'],
     ['tipo', 'par-tipo'], ['umbral_min', 'par-umbral'], ['radio_m', 'par-radio'], ['radio_cliente_m', 'par-radio-cli']]
      .forEach(([k, id]) => { if (v(id) !== '') q.set(k, v(id)); });
    return q.toString();
  },

  async _parCargar() {
    try {
      const r = await API.get('/tms/dashboard/paradas?' + this._parQs());
      if (r.error) throw new Error(r.message);
      const d = r.data || {};
      this._gpOpciones('par-vehiculo', d.filtros?.vehiculos || [], 'Todos');
      this._gpOpciones('par-auxiliar', d.filtros?.auxiliares || [], 'Todos');
      const k = d.kpis || {};
      const fmtMin = m => m >= 60 ? `${Math.floor(m / 60)} h ${m % 60} min` : `${m} min`;
      const card = (t, v, c) => `<div class="card" style="padding:14px 16px;"><div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">${t}</div><div style="font-size:22px;font-weight:800;color:${c};margin-top:4px;">${v}</div></div>`;
      document.getElementById('par-kpis').innerHTML =
        card('Paradas detectadas', k.total_paradas ?? 0, 'var(--accent)') +
        card('Tiempo total detenido', fmtMin(k.minutos_detenido ?? 0), 'var(--accent)') +
        card('Paradas no programadas', k.no_programadas ?? 0, (k.no_programadas ? 'var(--danger)' : 'var(--ok)')) +
        card('Tiempo no programado', fmtMin(k.minutos_por_tipo?.['No programada'] ?? 0), 'var(--danger)') +
        card('Promedio en cliente', fmtMin(k.promedio_en_cliente ?? 0), 'var(--warn)');
      this._parFilas = d.paradas || [];
      this._parRender();
    } catch (e) {
      document.getElementById('par-tbody').innerHTML = `<tr><td colspan="10" class="text-center" style="padding:20px;color:var(--danger);">${WMS.esc(e.message || 'Error al cargar')}</td></tr>`;
    }
  },

  _parRender() {
    const tb = document.getElementById('par-tbody');
    if (!tb) return;
    const hora = t => t ? new Date(t.replace(' ', 'T')).toLocaleString('es-CO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '-';
    const col = { 'En cliente': '#0891b2', 'No programada': 'var(--danger)', 'Bodega': 'var(--accent)' };
    tb.innerHTML = this._parFilas.length ? this._parFilas.map((f, i) => `
      <tr>
        <td style="white-space:nowrap;">${hora(f.inicio)}</td>
        <td style="white-space:nowrap;">${hora(f.fin)}${f.en_curso ? ' <span style="color:var(--warn);font-weight:700;">(en curso)</span>' : ''}</td>
        <td class="text-center"><b style="color:${f.duracion_min >= 30 ? 'var(--danger)' : 'var(--text)'};">${f.duracion_min} min</b></td>
        <td><span style="color:${col[f.tipo] || 'var(--muted)'};font-weight:700;">${WMS.esc(f.tipo)}</span>${f.tramo_sin_senal ? '<div style="font-size:10px;color:var(--text-muted);">incluye tramo sin señal</div>' : ''}</td>
        <td>${f.lugar ? `<b>${WMS.esc(f.lugar)}</b>` : ''}<div id="par-dir-${i}" style="font-size:11px;color:var(--muted);">${WMS.esc(f.direccion || '')}</div>
            <div style="font-size:10px;color:var(--text-muted);">${f.lat}, ${f.lng}</div></td>
        <td>${WMS.esc(f.placa || '-')}</td>
        <td>${WMS.esc(f.numero_despacho || '-')}</td>
        <td>${WMS.esc(f.auxiliar || '-')}</td>
        <td class="text-center">${f.visita_registrada_min != null ? f.visita_registrada_min + ' min' : '-'}</td>
        <td class="text-center" style="white-space:nowrap;">
          <a class="btn btn-xs btn-outline-primary" title="Ver en Google Maps" target="_blank" href="https://www.google.com/maps?q=${f.lat},${f.lng}"><i class="fa-solid fa-map-location-dot"></i></a>
          ${f.tipo !== 'Bodega' ? `<button class="btn btn-xs btn-outline-success" title="Guardar esta ubicación como la de una sucursal" onclick="WMS_MODULES.tms._parUbicarCliente(${i})"><i class="fa-solid fa-location-dot"></i> Asignar a sucursal</button>` : ''}
        </td>
      </tr>`).join('') : '<tr><td colspan="10" class="text-center" style="padding:20px;color:var(--text-muted);">Sin paradas que superen el umbral en el rango seleccionado</td></tr>';
    this._parDirecciones();
  },

  // Dirección aproximada (calle/carrera) de las paradas sin dirección en el
  // maestro: geocodificación inversa de OpenStreetMap (Nominatim) — máximo 1
  // consulta por segundo por su política de uso, y en caché local.
  async _parDirecciones() {
    const token = (this._parToken = (this._parToken || 0) + 1);
    let cache = {};
    try { cache = JSON.parse(localStorage.getItem('tms_geo_inv') || '{}'); } catch (e) {}
    for (let i = 0; i < this._parFilas.length && i < 40; i++) {
      if (token !== this._parToken) return;
      const f = this._parFilas[i];
      if (f.direccion) continue;
      const key = f.lat.toFixed(4) + ',' + f.lng.toFixed(4);
      let dir = cache[key];
      if (!dir) {
        try {
          const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&accept-language=es&lat=${f.lat}&lon=${f.lng}`).then(x => x.json());
          const a = r.address || {};
          dir = [a.road, a.house_number].filter(Boolean).join(' # ') || r.display_name?.split(',').slice(0, 2).join(',') || '';
          if (a.suburb || a.neighbourhood) dir += ` — ${a.suburb || a.neighbourhood}`;
          cache[key] = dir;
          try { localStorage.setItem('tms_geo_inv', JSON.stringify(cache)); } catch (e) {}
          await new Promise(r2 => setTimeout(r2, 1100));
        } catch (e) { continue; }
      }
      f.direccion_aprox = dir;
      const el = document.getElementById('par-dir-' + i);
      if (el && dir) el.innerHTML = `${WMS.esc(dir)} <span style="color:var(--text-muted);">(aprox.)</span>`;
    }
  },

  // Asignar (o corregir) la ubicación de una sucursal con la de esta parada —
  // 2026-10-02: antes solo aparecía si la sucursal NO tenía coordenadas, así
  // que una vez cargadas (automáticamente con las entregas) ya no se podían
  // corregir ni asignar a una parada "No programada".
  async _parUbicarCliente(i) {
    const f = this._parFilas[i];
    if (!f) return;
    this._asignarUbicacion({
      lat: f.lat, lng: f.lng, clienteId: f.cliente_id, nombre: f.lugar,
      contexto: `Parada de <b>${WMS.esc(f.auxiliar || '')}</b> · ${String(f.inicio).substring(11, 16)}–${String(f.fin).substring(11, 16)} (${f.duracion_min} min)`,
    }, () => this._parCargar());
  },

  // Desde el detalle de entregas por planilla (dashboard): cualquier entrega,
  // aunque no se haya detectado una parada (GPS escaso o menos de 15 min).
  _entUbicar(i) {
    const e = (this._entregasDet || [])[i];
    if (!e?.coord) return WMS.toast('warning', 'Esta entrega no tiene coordenadas registradas');
    this._asignarUbicacion({
      lat: e.coord.lat, lng: e.coord.lng, nombre: e.sucursal,
      contexto: `Entrega ${WMS.esc(e.planilla || '')} · ${WMS.esc(e.auxiliar || '')}<br>Origen del punto: <b>${WMS.esc(e.coord.fuente)}</b>`,
    }, () => this._aplicarFiltros());
  },

  // Selector de sucursal para guardar/corregir su ubicación (2026-10-02):
  // antes solo se podía si la sucursal NO tenía coordenadas.
  async _asignarUbicacion(o, alGuardar) {
    if (!this._clientesCache) {
      try { this._clientesCache = ((await API.get('/param/clientes')).data || []).filter(c => c.activo !== false && c.activo !== 0); }
      catch (e) { return WMS.toast('error', 'No se pudo cargar la lista de sucursales'); }
    }
    const nom = String(o.nombre || '').trim().toUpperCase();
    const opts = this._clientesCache.slice().sort((a, b) => String(a.razon_social).localeCompare(String(b.razon_social)))
      .map(c => `<option value="${c.id}" ${(c.id === o.clienteId || String(c.razon_social).trim().toUpperCase() === nom) ? 'selected' : ''}>${WMS.esc(c.razon_social)}${c.latitud ? ' (ya tiene ubicación)' : ''}</option>`).join('');
    const r = await Swal.fire({
      title: 'Asignar ubicación a sucursal',
      html: `<div style="text-align:left;font-size:13px;">
          <div style="margin-bottom:8px;color:var(--text2);">${o.contexto || ''}<br>
          Coordenadas: <b>${o.lat}, ${o.lng}</b> · <a href="https://www.google.com/maps?q=${o.lat},${o.lng}" target="_blank">ver en Google Maps</a></div>
          <label class="form-label">Sucursal</label>
          <input id="par-cli-q" class="swal2-input form-control" placeholder="Buscar sucursal..." style="margin:4px 0 6px;width:100%;box-sizing:border-box;"
            oninput="const q=this.value.toLowerCase();[...document.getElementById('par-cli-sel').options].forEach(o=>o.hidden=!o.text.toLowerCase().includes(q))">
          <select class="form-control" id="par-cli-sel" size="7" style="width:100%;border:1px solid var(--border);border-radius:6px;padding:4px;font-size:13px;">${opts}</select>
          <div style="font-size:11px;color:var(--warn);margin-top:6px;">Si la sucursal ya tiene ubicación, se reemplaza (queda en el log de auditoría).</div>
        </div>`,
      showCancelButton: true, confirmButtonText: 'Guardar ubicación', cancelButtonText: 'Cancelar', width: 520,
      didOpen: () => document.querySelector('#par-cli-sel option:checked')?.scrollIntoView({ block: 'center' }),
      preConfirm: () => {
        const v = document.getElementById('par-cli-sel').value;
        if (!v) { Swal.showValidationMessage('Seleccione la sucursal'); return false; }
        return parseInt(v);
      },
    });
    if (!r.isConfirmed) return;
    try {
      const res = await API.post('/tms/dashboard/paradas/ubicar-cliente', { cliente_id: r.value, lat: o.lat, lng: o.lng });
      if (res.error) throw new Error(res.message);
      this._clientesCache = null;
      WMS.toast('success', res.message);
      if (alGuardar) alGuardar();
    } catch (e) { WMS.toast('error', e.message || 'No se pudo guardar'); }
  },

  _parExportar() {
    const token = localStorage.getItem('wms_token');
    window.open(`${API_BASE}/tms/dashboard/paradas?export=excel&${this._parQs()}&token=${encodeURIComponent(token)}`, '_blank');
  },

  // ── Recorrido por calles (no línea recta): OSRM route entre los puntos GPS,
  // en tramos de hasta 25 puntos, con caché (solo se pide el tramo nuevo en
  // cada refresco) y máximo 1 consulta por segundo. Si el servicio no
  // responde se queda la línea recta punteada. Nota: router.project-osrm.org
  // es un servidor de demostración (uso razonable, sin garantía); para
  // operación intensiva conviene montar OSRM propio con el mapa de Colombia.
  _osrmCache: {},
  _osrmCola: Promise.resolve(),
  _rutaPorCalles(latlngs) {
    const TRAMO = 25;
    const tareas = [];
    for (let i = 0; i < latlngs.length - 1; i += TRAMO - 1) {
      const pedazo = latlngs.slice(i, i + TRAMO);
      if (pedazo.length < 2) break;
      const key = pedazo.map(p => p[0].toFixed(5) + ',' + p[1].toFixed(5)).join(';');
      if (!this._osrmCache[key]) {
        this._osrmCache[key] = this._osrmCola = this._osrmCola.then(async () => {
          const coords = pedazo.map(p => `${p[1]},${p[0]}`).join(';');
          const r = await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`).then(x => x.json());
          await new Promise(r2 => setTimeout(r2, 1000));
          if (r.code !== 'Ok' || !r.routes?.[0]) throw new Error('OSRM ' + r.code);
          return r.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
        }).catch(e => { delete this._osrmCache[key]; return null; });
      }
      tareas.push(this._osrmCache[key]);
    }
    return Promise.all(tareas).then(partes => partes.some(x => !x) ? null : partes.flat());
  },

  /* ═══════════════════════════════════════════════════════════════════════
     E) MANTENIMIENTO GPS — a pedido de Camilo (2026-10-03): ver cuánto ocupa
     el tracking, configurar la limpieza automática (retención en días) y
     depurar manualmente un período. Nunca borra rutas abiertas ni los
     últimos 7 días; las entregas confirmadas conservan sus coordenadas.
  ═══════════════════════════════════════════════════════════════════════ */
  async loadMantenimiento() {
    this._activeTab = 'mantenimiento';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('mantenimiento'));
    WMS.spinner();
    let d;
    try {
      const r = await API.get('/tms/dashboard/tracking-mantenimiento');
      if (r.error) throw new Error(r.message);
      d = r.data || {};
    } catch (e) {
      WMS.setContent(`<div class="card" style="padding:24px;color:var(--danger);">${WMS.esc(e.message || 'No se pudo consultar el TMS')}</div>`);
      return;
    }
    const fmt = n => WMS.formatNum ? WMS.formatNum(n) : n;
    const min = d.retencion_min || 7;
    const tope = new Date(Date.now() - min * 86400000).toISOString().substring(0, 10);
    const sugerido = new Date(Date.now() - (d.retencion_dias || 90) * 86400000).toISOString().substring(0, 10);
    const card = (t, v, sub, c) => `<div class="card" style="padding:14px 16px;"><div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;">${t}</div><div style="font-size:22px;font-weight:800;color:${c};margin-top:4px;">${v}</div><div style="font-size:11px;color:var(--text-muted);">${sub}</div></div>`;
    this._mantPorMes = d.por_mes || [];
    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:14px;">
          ${card('Posiciones GPS guardadas', fmt(d.total_puntos || 0), `${fmt(d.total_eventos || 0)} eventos en bitácora`, 'var(--accent)')}
          ${card('Espacio en base de datos', (d.tamano_mb ?? 0) + ' MB', 'Tablas de tracking del TMS', 'var(--accent)')}
          ${card('Registro más antiguo', d.mas_antiguo ? WMS.formatDate(d.mas_antiguo) : '—', 'Primera posición guardada', 'var(--text2)')}
          ${card('Limpieza automática', d.retencion_dias ? d.retencion_dias + ' días' : 'Desactivada', d.ultima_depuracion ? 'Última: ' + WMS.formatDate(d.ultima_depuracion) : 'Aún no se ha ejecutado', d.retencion_dias ? 'var(--ok)' : 'var(--danger)')}
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-gears" style="margin-right:6px;color:var(--accent);"></i> Limpieza automática</div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 10px;">Una vez al día el TMS borra las posiciones GPS más antiguas que el período configurado. Mínimo ${min} días. 0 = desactivada.</p>
            <div style="display:flex;gap:8px;align-items:center;">
              <input type="number" id="mant-dias" class="form-control form-control-sm" value="${d.retencion_dias ?? 90}" min="0" style="width:110px;">
              <span style="font-size:12px;color:var(--text2);">días de historial</span>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.tms._mantConfigurar()"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-broom" style="margin-right:6px;color:var(--danger);"></i> Depurar ahora</div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 10px;">Borra el tracking GPS anterior a la fecha. Afecta el mapa y el reporte de Tiempos de Paro de esos días (las entregas confirmadas conservan sus coordenadas). No se pueden borrar los últimos ${min} días.</p>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <span style="font-size:12px;color:var(--text2);">Borrar anterior a</span>
              <input type="date" id="mant-hasta" class="form-control form-control-sm" value="${sugerido < tope ? sugerido : tope}" max="${tope}" style="width:160px;" oninput="WMS_MODULES.tms._mantPrevia()">
              <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.tms._mantDepurar()"><i class="fa-solid fa-trash-can"></i> Depurar</button>
            </div>
            <div id="mant-previa" style="font-size:12px;color:var(--warn);margin-top:8px;"></div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-calendar" style="margin-right:6px;color:var(--accent);"></i> Posiciones por mes</div>
            <table class="erp-table" style="width:100%;font-size:12px;margin:0;">
              <thead><tr><th>Mes</th><th class="text-center">Posiciones</th><th class="text-center">Descartadas</th><th class="text-center">Auxiliares</th></tr></thead>
              <tbody>${(d.por_mes || []).map(m => `<tr><td>${WMS.esc(m.mes)}</td><td class="text-center">${fmt(m.puntos)}</td><td class="text-center">${fmt(m.descartados || 0)}</td><td class="text-center">${m.auxiliares}</td></tr>`).join('') || '<tr><td colspan="4" class="text-center" style="color:var(--text-muted);">Sin registros</td></tr>'}</tbody>
            </table>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-clock-rotate-left" style="margin-right:6px;color:var(--accent);"></i> Bitácora de depuraciones</div>
            ${(d.bitacora || []).map(b => `<div style="font-size:12px;padding:6px 0;border-bottom:1px solid var(--border-light);"><b>${WMS.formatDateTime ? WMS.formatDateTime(b.created_at) : b.created_at}</b><br><span style="color:var(--text2);">${WMS.esc(b.detalle || '')}</span></div>`).join('') || '<div style="font-size:12px;color:var(--text-muted);">Sin depuraciones todavía</div>'}
          </div>
        </div>
      </div>`);
    this._mantPrevia();
  },

  // Estimado de lo que se borraría (por meses completos anteriores a la fecha).
  _mantPrevia() {
    const hasta = document.getElementById('mant-hasta')?.value;
    const el = document.getElementById('mant-previa');
    if (!el || !hasta) return;
    const mesCorte = hasta.substring(0, 7);
    const n = (this._mantPorMes || []).filter(m => m.mes < mesCorte).reduce((a, m) => a + parseInt(m.puntos || 0), 0);
    el.textContent = n ? `Aproximadamente ${n.toLocaleString('es-CO')} posiciones o más (meses completos anteriores a ${mesCorte}).` : 'Según los meses completos no hay posiciones anteriores a esa fecha (puede haber del mismo mes).';
  },

  async _mantConfigurar() {
    const dias = parseInt(document.getElementById('mant-dias')?.value ?? '-1');
    try {
      const r = await API.post('/tms/dashboard/tracking-mantenimiento', { accion: 'configurar', retencion_dias: dias });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', r.message);
      this.loadMantenimiento();
    } catch (e) { WMS.toast('error', e.message || 'No se pudo guardar'); }
  },

  async _mantDepurar() {
    const hasta = document.getElementById('mant-hasta')?.value;
    if (!hasta) return WMS.toast('warning', 'Seleccione la fecha');
    if (!confirm(`⚠️ Se BORRARÁ definitivamente todo el tracking GPS anterior al ${hasta}.\n\nEl mapa y el reporte de Tiempos de Paro de esos días quedarán sin recorrido. Las entregas confirmadas conservan sus coordenadas.\n\n¿Continuar?`)) return;
    try {
      const r = await API.post('/tms/dashboard/tracking-mantenimiento', { accion: 'depurar', hasta });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', r.message);
      this.loadMantenimiento();
    } catch (e) { WMS.toast('error', e.message || 'No se pudo depurar'); }
  },

  async _verDetallePedido(ordenId) {
    try {
      const r = await API.get(`/tms/dashboard/reabrir-pedidos/${ordenId}`);
      const d = r.data || {};
      const lineas = d.lineas || [];
      const novedades = d.novedades || [];

      const filasLineas = lineas.map(l => `
        <tr>
          <td>${WMS.esc(l.codigo)}</td><td>${WMS.esc(l.nombre)}</td>
          <td class="text-center">${l.cajas}</td><td class="text-center">${l.saldo}</td>
          <td class="text-center">${l.total_unidades}</td>
          <td class="text-center">${parseFloat(l.cantidad_reportada) > 0 ? l.cantidad_reportada : '-'}</td>
          <td><span class="badge">${WMS.esc(l.estado)}</span></td>
        </tr>`).join('') || '<tr><td colspan="7" class="text-center" style="color:var(--text-muted);">Sin referencias</td></tr>';

      const filasNovedades = novedades.map(n => `
        <tr>
          <td>${WMS.esc(n.producto_codigo)}</td><td>${WMS.esc(n.producto_nombre)}</td>
          <td>${WMS.esc(n.motivo_nombre)}</td><td class="text-center">${n.cantidad}</td>
          <td>${n.wms_consecutivo ? '#' + n.wms_consecutivo : (n.estado_envio_wms === 'error' ? 'Error de envío' : 'Pendiente')}</td>
        </tr>`).join('') || '<tr><td colspan="5" class="text-center" style="color:var(--text-muted);">Sin novedades reportadas</td></tr>';

      document.getElementById('tmsr-modal').innerHTML = `
        <div style="position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;" onclick="if(event.target===this) this.remove()">
          <div style="background:var(--surface);border-radius:10px;width:min(880px,100%);max-height:calc(100vh - 80px);display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden;">
            <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:var(--accent);color:#fff;">
              <b>Detalle del pedido — ${WMS.esc(d.pedido?.numero_pedido || '')}</b>
              <button onclick="document.getElementById('tmsr-modal').innerHTML=''" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;">&times;</button>
            </div>
            <div style="padding:16px 20px;overflow-y:auto;">
              <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-box-open"></i> Referencias (cajas / saldos / unidades)</div>
              <table class="erp-table" style="width:100%;font-size:12px;margin-bottom:20px;">
                <thead><tr><th>Código</th><th>Producto</th><th class="text-center">Cajas</th><th class="text-center">Saldo</th><th class="text-center">Total und.</th><th class="text-center">Reportado</th><th>Estado</th></tr></thead>
                <tbody>${filasLineas}</tbody>
              </table>
              <div class="pro-section-title" style="margin-bottom:8px;"><i class="fa-solid fa-triangle-exclamation"></i> Detalle de novedades</div>
              <table class="erp-table" style="width:100%;font-size:12px;">
                <thead><tr><th>Código</th><th>Producto</th><th>Causal</th><th class="text-center">Cantidad</th><th>Devolución</th></tr></thead>
                <tbody>${filasNovedades}</tbody>
              </table>
            </div>
          </div>
        </div>`;
    } catch (e) {
      WMS.toast('error', 'Error al cargar el detalle del pedido');
    }
  },
};
