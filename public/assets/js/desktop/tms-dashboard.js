// public/assets/js/desktop/tms-dashboard.js
'use strict';
WMS_MODULES.tms = {

  _chartInstances: {},
  _paletteVivid: ['#0F4C81','#e03030','#e8a000','#00b300','#7c3aed','#0891b2','#c026d3','#64748b'],
  _map: null,
  _mapTimer: null,

  load(sub) {
    sub = sub || 'resumen';
    if (sub === 'mapa') return this.loadMapa();
    if (sub === 'reabrir') return this.loadReabrirPedidos();
    return this.loadResumen();
  },

  _navBar(activa) {
    const tabs = [
      { id: 'resumen', label: 'Dashboard KPI', icon: 'fa-chart-pie' },
      { id: 'mapa',    label: 'Mapa en Vivo',  icon: 'fa-map-location-dot' },
      { id: 'reabrir', label: 'Reabrir Pedidos', icon: 'fa-unlock' },
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
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Desde</label>
              <input type="date" id="tmsd-desde" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._aplicarFiltros()">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Hasta</label>
              <input type="date" id="tmsd-hasta" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._aplicarFiltros()">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Sucursal</label>
              <select id="tmsd-sucursal" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.tms._aplicarFiltros()">
                <option value="">Todas las sucursales</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Auxiliar</label>
              <select id="tmsd-auxiliar" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.tms._aplicarFiltros()">
                <option value="">Todos los auxiliares</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Referencia</label>
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
              <div style="background:#f1f5f9;border-radius:8px;height:60px;animation:pulse 1.5s ease-in-out infinite;"></div>
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
              <i class="fa-solid fa-clock" style="margin-right:6px;color:#0F4C81;"></i> Tiempo de demora promedio por día (min)
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
              <i class="fa-solid fa-stopwatch" style="margin-right:6px;color:#e8a000;"></i> Matriz de tiempos de demora por sucursal
            </div>
            <div style="flex:1;overflow-y:auto;max-height:220px;">
              <table class="erp-table" style="margin:0;width:100%;font-size:11px;">
                <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                  <tr>
                    <th>Sucursal</th>
                    <th class="text-center">Entregas</th>
                    <th class="text-center">Promedio</th>
                    <th class="text-center">Mín.</th>
                    <th class="text-center">Máx.</th>
                  </tr>
                </thead>
                <tbody id="tmsd-matriz-demora"><tr><td colspan="5" class="text-center" style="padding:16px;color:#94a3b8;">Cargando...</td></tr></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card" style="padding:16px;">
          <div class="pro-section-title" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span><i class="fa-solid fa-box-open" style="margin-right:6px;color:#7c3aed;"></i> Detalle de novedades</span>
            <span id="tmsd-top-refs" style="display:flex;gap:6px;flex-wrap:wrap;"></span>
          </div>
          <div style="max-height:320px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">
            <table class="erp-table" style="margin:0;width:100%;font-size:11px;">
              <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                <tr>
                  <th>Fecha</th><th>Sucursal</th><th>Auxiliar</th><th>Referencia</th>
                  <th>Causal</th><th class="text-center">Cantidad</th><th class="text-center">Devolución #</th>
                </tr>
              </thead>
              <tbody id="tmsd-detalle-novedades"><tr><td colspan="7" class="text-center" style="padding:16px;color:#94a3b8;">Cargando...</td></tr></tbody>
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
      { label: 'Total Entregas', value: fmt(kpis.total_entregas || 0), sub: 'En el período', icon: 'fa-truck', accent: 'accent-blue' },
      { label: 'Nivel de Servicio', value: (kpis.ns_referencias_pct ?? '—') + (kpis.ns_referencias_pct !== null ? '%' : ''), sub: `${fmt(kpis.refs_sin_novedad || 0)} / ${fmt(kpis.total_refs_aptas || 0)} refs sin novedad`, icon: 'fa-gauge-high', accent: 'accent-green' },
      { label: 'Sin Novedad', value: fmt(kpis.sin_novedad || 0), sub: 'Pedidos entregados OK', icon: 'fa-circle-check', accent: 'accent-green' },
      { label: 'Con Novedad', value: fmt(kpis.con_novedad || 0), sub: `${fmt(kpis.total_novedades || 0)} novedades reportadas`, icon: 'fa-triangle-exclamation', accent: 'accent-gray' },
      { label: 'Tiempo Promedio', value: kpis.tiempo_promedio_min !== null && kpis.tiempo_promedio_min !== undefined ? fmt(kpis.tiempo_promedio_min) + ' min' : '—', sub: 'Demora en punto de entrega', icon: 'fa-hourglass-half', accent: 'accent-amber' },
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
          datasets: [{ label: 'Minutos', data: (d.tiempos_por_dia || []).map(x => x.promedio), borderColor: '#0F4C81', backgroundColor: 'rgba(15,76,129,.15)', fill: true, tension: .3 }]
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

  _renderMatrizDemora(filas) {
    const tbody = document.getElementById('tmsd-matriz-demora');
    if (!tbody) return;
    if (!filas.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="padding:16px;color:#94a3b8;">Sin datos en el período</td></tr>';
      return;
    }
    tbody.innerHTML = filas.map(f => `
      <tr>
        <td>${WMS.esc(f.sucursal || '-')}</td>
        <td class="text-center">${f.entregas}</td>
        <td class="text-center"><b>${f.promedio ?? '-'}</b> min</td>
        <td class="text-center">${f.minimo ?? '-'}</td>
        <td class="text-center">${f.maximo ?? '-'}</td>
      </tr>`).join('');
  },

  _renderDetalleNovedades(filas, topReferencias) {
    const topEl = document.getElementById('tmsd-top-refs');
    if (topEl) {
      topEl.innerHTML = (topReferencias || []).map(r => `
        <span class="badge" style="background:#fef3c7;color:#92400e;" title="${WMS.esc(r.nombre)}">
          ${WMS.esc(r.codigo)} · ${r.veces}
        </span>`).join('') || '<span style="font-size:11px;color:#94a3b8;">Sin referencias recurrentes</span>';
    }

    const tbody = document.getElementById('tmsd-detalle-novedades');
    if (!tbody) return;
    if (!filas.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:16px;color:#94a3b8;">Sin novedades en el período</td></tr>';
      return;
    }
    tbody.innerHTML = filas.map(f => `
      <tr>
        <td>${f.fecha ? WMS.formatDate(f.fecha) : '-'}</td>
        <td>${WMS.esc(f.sucursal || '-')}</td>
        <td>${WMS.esc(f.auxiliar || '-')}</td>
        <td><b>${WMS.esc(f.codigo || '-')}</b><br><span style="color:#64748b;">${WMS.esc(f.nombre || '')}</span></td>
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
    WMS.setToolbar(this._navBar('mapa'));

    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="padding:0;overflow:hidden;">
          <div style="padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between;">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
              <span class="pro-section-title" style="margin:0;"><i class="fa-solid fa-map-location-dot" style="margin-right:6px;color:#0F4C81;"></i> Entregas — recorrido en vivo</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Fecha</label>
                <input type="date" id="tmsm-fecha" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._refrescarMapa()">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Vehículo</label>
                <select id="tmsm-vehiculo" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.tms._refrescarMapa()">
                  <option value="">Todos</option>
                </select>
              </div>
              <span style="font-size:11px;color:#64748b;align-self:center;">Se actualiza cada 30s · el recorrido conecta los puntos conocidos (llegada/salida de cada parada) — no es GPS continuo.</span>
            </div>
          </div>
          <div style="display:flex;">
            <div id="tmsd-map-panel" style="width:260px;flex-shrink:0;border-right:1px solid #e2e8f0;max-height:560px;overflow-y:auto;background:#f8fafc;"></div>
            <div style="flex:1;position:relative;">
              <div id="tmsd-map" style="height:560px;"></div>
              <div id="tmsd-map-error" style="display:none;position:absolute;inset:0;background:#f8fafc;align-items:center;justify-content:center;text-align:center;padding:24px;">
                <div>
                  <i class="fa-solid fa-map-location-dot" style="font-size:32px;color:#cbd5e1;"></i>
                  <p style="color:#64748b;font-size:13px;margin-top:8px;">No se pudo cargar el mapa (sin conexión al servicio de mapas).<br>Reintentando automáticamente...</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>`);

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
      this._renderFiltrosSelect('tmsm-vehiculo', d.filtros?.vehiculos || []);

      const bounds = [];
      const panel = [];
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
          L.polyline(latlngs, { color: '#1e293b', weight: 6, opacity: .25, lineCap: 'round', lineJoin: 'round' }).addTo(this._lineas);
          L.polyline(latlngs, { color, weight: 4, opacity: .9, lineCap: 'round', lineJoin: 'round' }).addTo(this._lineas);
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

      enCursoFiltrado.forEach(v => {
        const p = v.ultimo_punto;
        if (!p) return;
        const color = v.estado === 'en_ruta' ? '#e8a000' : '#64748b';
        L.marker([p.lat, p.lng], { icon: this._iconoVehiculo(color, { enCurso: true }) })
          .bindPopup(`<div style="font-size:12.5px;"><b>${WMS.esc(v.auxiliar_nombre || 'Auxiliar')}</b><br>${WMS.esc(v.sucursal)}<br><span style="color:${color};font-weight:700;">${v.estado === 'en_ruta' ? 'En ruta' : 'Pendiente'}</span>${v.hora_llegada ? '<br>Llegada: ' + v.hora_llegada : ''}</div>`)
          .addTo(this._markers);
        bounds.push([p.lat, p.lng]);
        panel.push({ nombre: v.auxiliar_nombre || 'Auxiliar', sub: v.sucursal, color, estado: 'En ruta' });
      });

      (d.confirmadas || []).forEach(c => {
        const puntos = Object.values(c.puntos || {});
        const ultimo = puntos[puntos.length - 1];
        if (!ultimo) return;
        const color = c.tiene_novedad ? '#e03030' : '#00b300';
        L.marker([ultimo.lat, ultimo.lng], { icon: this._iconoVehiculo(color) })
          .bindPopup(`<div style="font-size:12.5px;"><b>${WMS.esc(c.auxiliar_nombre || 'Auxiliar')}</b>${c.placa ? ' · ' + WMS.esc(c.placa) : ''}<br>${WMS.esc(c.sucursal)}<br><span style="color:${color};font-weight:700;">Entregado${c.tiene_novedad ? ' — con novedad' : ' — sin novedad'}</span></div>`)
          .addTo(this._markers);
        bounds.push([ultimo.lat, ultimo.lng]);
        panel.push({ nombre: c.auxiliar_nombre || 'Auxiliar', sub: `${c.sucursal}${c.placa ? ' · ' + c.placa : ''}`, color, estado: c.tiene_novedad ? 'Con novedad' : 'Entregado sin novedad' });
      });

      this._renderPanelVehiculos(panel);

      if (bounds.length) this._map.flyToBounds(bounds, { maxZoom: 13, padding: [40, 40], duration: .6 });
    } catch (e) {
      if (errBox) errBox.style.display = 'flex';
    }
  },

  _renderPanelVehiculos(items) {
    const el = document.getElementById('tmsd-map-panel');
    if (!el) return;
    if (!items.length) {
      el.innerHTML = '<div style="padding:16px;font-size:12px;color:#94a3b8;text-align:center;">Sin vehículos para mostrar en el rango seleccionado.</div>';
      return;
    }
    el.innerHTML = `<div style="padding:10px 14px;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;">Vehículos (${items.length})</div>` +
      items.map(it => `
        <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;align-items:flex-start;">
          <div style="width:10px;height:10px;border-radius:50%;background:${it.color};margin-top:4px;flex-shrink:0;"></div>
          <div style="min-width:0;">
            <div style="font-size:12.5px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(it.nombre)}</div>
            <div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(it.sub || '')}</div>
            <div style="font-size:10.5px;font-weight:700;color:${it.color};margin-top:2px;">${WMS.esc(it.estado)}</div>
          </div>
        </div>`).join('');
  },

  /* ═══════════════════════════════════════════════════════════════════════
     C) REABRIR PEDIDOS
  ═══════════════════════════════════════════════════════════════════════ */
  async loadReabrirPedidos() {
    this._activeTab = 'reabrir';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('reabrir'));

    const hoy = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);
    const hace7 = new Date(Date.now() - 7 * 86400000).toISOString().substring(0, 10);

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Desde</label>
              <input type="date" id="tmsr-desde" class="form-control form-control-sm" value="${hace7}" onchange="WMS_MODULES.tms._aplicarFiltrosReabrir()">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Hasta</label>
              <input type="date" id="tmsr-hasta" class="form-control form-control-sm" value="${hoy}" onchange="WMS_MODULES.tms._aplicarFiltrosReabrir()">
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Ruta</label>
              <select id="tmsr-ruta" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.tms._aplicarFiltrosReabrir()">
                <option value="">Todas las rutas</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Sucursal</label>
              <select id="tmsr-sucursal" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.tms._aplicarFiltrosReabrir()">
                <option value="">Todas las sucursales</option>
              </select>
            </div>
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Auxiliar</label>
              <input type="text" id="tmsr-auxiliar" class="form-control form-control-sm" placeholder="Nombre" style="min-width:140px;" oninput="WMS_MODULES.tms._aplicarFiltrosReabrirDebounced()">
            </div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.tms._aplicarFiltrosReabrir()">
              <i class="fa-solid fa-filter"></i> Aplicar
            </button>
          </div>
        </div>

        <div class="card" style="padding:0;overflow:hidden;">
          <div class="table-container" style="max-height:560px;overflow-y:auto;">
            <table class="erp-table" style="margin:0;width:100%;font-size:12px;">
              <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                <tr>
                  <th>Fecha</th><th>Planilla</th><th>Sucursal</th><th>Ruta</th><th>Auxiliar</th>
                  <th class="text-center">Líneas</th><th>Estado</th><th class="text-center">Acciones</th>
                </tr>
              </thead>
              <tbody id="tmsr-tbody"><tr><td colspan="8" class="text-center" style="padding:20px;color:#94a3b8;">Cargando...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>
      <div id="tmsr-modal"></div>`);

    await this._aplicarFiltrosReabrir();
  },

  _aplicarFiltrosReabrirDebounced() {
    clearTimeout(this._reabrirFilterTimer);
    this._reabrirFilterTimer = setTimeout(() => this._aplicarFiltrosReabrir(), 400);
  },

  async _aplicarFiltrosReabrir() {
    try {
      const params = new URLSearchParams();
      const desde    = document.getElementById('tmsr-desde')?.value;
      const hasta    = document.getElementById('tmsr-hasta')?.value;
      const ruta     = document.getElementById('tmsr-ruta')?.value;
      const sucursal = document.getElementById('tmsr-sucursal')?.value;
      const auxiliar = document.getElementById('tmsr-auxiliar')?.value;
      if (desde)    params.set('fecha_desde', desde);
      if (hasta)    params.set('fecha_hasta', hasta);
      if (ruta)     params.set('ruta', ruta);
      if (sucursal) params.set('sucursal', sucursal);
      if (auxiliar) params.set('auxiliar', auxiliar);

      const r = await API.get('/tms/dashboard/reabrir-pedidos?' + params.toString());
      const d = r.data || {};
      this._renderTablaReabrir(d.pedidos || []);
      this._renderFiltrosSelect('tmsr-ruta', d.filtros?.rutas || []);
      this._renderFiltrosSelect('tmsr-sucursal', d.filtros?.sucursales || []);
    } catch (e) {
      WMS.toast('error', 'Error al cargar los pedidos');
    }
  },

  _renderTablaReabrir(pedidos) {
    const tbody = document.getElementById('tmsr-tbody');
    if (!tbody) return;
    if (!pedidos.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center" style="padding:20px;color:#94a3b8;">Sin pedidos en el período</td></tr>';
      return;
    }
    const colorEstado = { 'Entregado': '#059669', 'En Ruta': '#e8a000', 'Visita Iniciada': '#0891b2', 'Pendiente': '#64748b' };
    tbody.innerHTML = pedidos.map(p => `
      <tr>
        <td>${p.fecha_planilla ? WMS.formatDate(p.fecha_planilla) : '-'}</td>
        <td>${WMS.esc(p.planilla_numero || p.numero_pedido || '-')}</td>
        <td>${WMS.esc(p.sucursal_entrega || '-')}</td>
        <td>${WMS.esc(p.ruta_nombre || '-')}</td>
        <td>${WMS.esc(p.auxiliar_nombre || '-')}</td>
        <td class="text-center">${p.total_lineas}</td>
        <td><span style="color:${colorEstado[p.estado] || '#64748b'};font-weight:700;">${WMS.esc(p.estado)}</span></td>
        <td class="text-center" style="white-space:nowrap;">
          <button class="btn btn-xs btn-outline-primary" title="Ver detalle" onclick="WMS_MODULES.tms._verDetallePedido(${p.orden_picking_id})"><i class="fa-solid fa-eye"></i></button>
          ${p.entregado || p.despacho_liquidado ? `<button class="btn btn-xs btn-warning" title="Reabrir pedido" onclick="WMS_MODULES.tms._reabrirPedido(${p.orden_picking_id})"><i class="fa-solid fa-unlock"></i></button>` : ''}
        </td>
      </tr>`).join('');
  },

  async _reabrirPedido(ordenId) {
    if (!confirm('¿Reabrir este pedido? El auxiliar podrá volver a tomarlo y registrar la entrega de nuevo desde cero en el TMS.')) return;
    try {
      const r = await API.post(`/tms/dashboard/reabrir-pedidos/${ordenId}/reabrir`, {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Pedido reabierto');
      this._aplicarFiltrosReabrir();
    } catch (e) {
      WMS.toast('error', e.message || 'Error al reabrir el pedido');
    }
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
        </tr>`).join('') || '<tr><td colspan="7" class="text-center" style="color:#94a3b8;">Sin referencias</td></tr>';

      const filasNovedades = novedades.map(n => `
        <tr>
          <td>${WMS.esc(n.producto_codigo)}</td><td>${WMS.esc(n.producto_nombre)}</td>
          <td>${WMS.esc(n.motivo_nombre)}</td><td class="text-center">${n.cantidad}</td>
          <td>${n.wms_consecutivo ? '#' + n.wms_consecutivo : (n.estado_envio_wms === 'error' ? 'Error de envío' : 'Pendiente')}</td>
        </tr>`).join('') || '<tr><td colspan="5" class="text-center" style="color:#94a3b8;">Sin novedades reportadas</td></tr>';

      document.getElementById('tmsr-modal').innerHTML = `
        <div style="position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;" onclick="if(event.target===this) this.remove()">
          <div style="background:#fff;border-radius:10px;width:min(880px,100%);max-height:calc(100vh - 80px);display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden;">
            <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#0F4C81;color:#fff;">
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
