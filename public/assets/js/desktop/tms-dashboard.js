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
    return this.loadResumen();
  },

  _navBar(activa) {
    const tabs = [
      { id: 'resumen', label: 'Dashboard KPI', icon: 'fa-chart-pie' },
      { id: 'mapa',    label: 'Mapa en Vivo',  icon: 'fa-map-location-dot' },
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

    const hoy   = WMS.getToday ? WMS.getToday() : new Date().toISOString().substring(0, 10);
    const desde = new Date(Date.now() - 29 * 86400000).toISOString().substring(0, 10);

    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div>
              <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;color:#64748b;">Desde</label>
              <input type="date" id="tmsd-desde" class="form-control form-control-sm" value="${desde}" onchange="WMS_MODULES.tms._aplicarFiltros()">
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
          ${[0,1,2,3].map(() => `
            <div class="pro-kpi-card" style="min-height:90px;">
              <div style="background:#f1f5f9;border-radius:8px;height:60px;animation:pulse 1.5s ease-in-out infinite;"></div>
            </div>`).join('')}
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-clock" style="margin-right:6px;color:#0F4C81;"></i> Tiempo de demora promedio por día (min)
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-tiempos"></canvas>
            </div>
          </div>
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-building" style="margin-right:6px;color:#e03030;"></i> Sucursales con más novedades
            </div>
            <div style="height:220px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-sucursales"></canvas>
            </div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr;gap:16px;">
          <div class="card" style="padding:16px;">
            <div class="pro-section-title" style="margin-bottom:12px;">
              <i class="fa-solid fa-box-open" style="margin-right:6px;color:#e8a000;"></i> Referencias con más novedades
            </div>
            <div style="height:240px;position:relative;overflow:hidden;">
              <canvas id="tmsd-chart-referencias"></canvas>
            </div>
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
      { label: 'Tiempo Promedio', value: kpis.tiempo_promedio_min !== null && kpis.tiempo_promedio_min !== undefined ? fmt(kpis.tiempo_promedio_min) + ' min' : '—', sub: 'Demora en punto de entrega', icon: 'fa-hourglass-half', accent: 'accent-amber' },
      { label: 'Con Novedad', value: fmt(kpis.con_novedad || 0), sub: `${fmt(kpis.total_novedades || 0)} novedades reportadas`, icon: 'fa-triangle-exclamation', accent: 'accent-gray' },
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

    const ctxR = document.getElementById('tmsd-chart-referencias');
    if (ctxR) {
      destroyChart('referencias');
      this._chartInstances['referencias'] = new Chart(ctxR, {
        type: 'bar',
        data: {
          labels: (d.top_referencias || []).map(x => `${x.codigo} — ${x.nombre}`),
          datasets: [{ label: 'Veces con novedad', data: (d.top_referencias || []).map(x => x.veces), backgroundColor: '#e8a000' }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false }, datalabels: { display: true, color: '#0f172a', anchor: 'end', align: 'end', font: { weight: '700', size: 11 }, formatter: v => v } }
        }
      });
    }
  },

  /* ═══════════════════════════════════════════════════════════════════════
     B) MAPA EN VIVO
  ═══════════════════════════════════════════════════════════════════════ */
  async loadMapa() {
    this._activeTab = 'mapa';
    if (this._mapTimer) { clearInterval(this._mapTimer); this._mapTimer = null; }
    WMS.setToolbar(this._navBar('mapa'));
    WMS.setContent(`
      <div style="padding:4px 0 16px;">
        <div class="card" style="padding:0;overflow:hidden;">
          <div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;">
            <span class="pro-section-title"><i class="fa-solid fa-map-location-dot" style="margin-right:6px;color:#0F4C81;"></i> Entregas — posición conocida</span>
            <span style="font-size:11px;color:#64748b;">Se actualiza cada 30s. Muestra el último punto reportado por el auxiliar (no es un GPS continuo).</span>
          </div>
          <div id="tmsd-map" style="height:520px;"></div>
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

  async _refrescarMapa() {
    if (!this._map) {
      this._map = L.map('tmsd-map').setView([4.6097, -74.0817], 6);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 18,
      }).addTo(this._map);
      this._markers = L.layerGroup().addTo(this._map);
    }

    try {
      const r = await API.get('/tms/dashboard/mapa');
      const d = r.data || {};
      this._markers.clearLayers();

      const bounds = [];
      const icono = (color) => L.divIcon({
        html: `<i class="fa-solid fa-truck" style="color:${color};font-size:20px;filter:drop-shadow(0 1px 2px rgba(0,0,0,.4));"></i>`,
        className: '', iconSize: [20, 20], iconAnchor: [10, 10],
      });

      (d.en_curso || []).forEach(v => {
        const p = v.ultimo_punto;
        if (!p) return;
        const color = v.estado === 'en_ruta' ? '#e8a000' : '#64748b';
        L.marker([p.lat, p.lng], { icon: icono(color) })
          .bindPopup(`<b>${WMS.esc(v.sucursal)}</b><br>${WMS.esc(v.auxiliar_nombre || '')}<br>Estado: ${v.estado}${v.hora_llegada ? '<br>Llegada: ' + v.hora_llegada : ''}`)
          .addTo(this._markers);
        bounds.push([p.lat, p.lng]);
      });

      (d.confirmadas || []).forEach(c => {
        const puntos = Object.values(c.puntos || {});
        const ultimo = puntos[puntos.length - 1];
        if (!ultimo) return;
        const color = c.tiene_novedad ? '#e03030' : '#00b300';
        L.marker([ultimo.lat, ultimo.lng], { icon: icono(color) })
          .bindPopup(`<b>${WMS.esc(c.sucursal)}</b><br>${WMS.esc(c.auxiliar_nombre || '')}<br>Entregado${c.tiene_novedad ? ' — con novedad' : ' — sin novedad'}`)
          .addTo(this._markers);
        bounds.push([ultimo.lat, ultimo.lng]);
      });

      if (bounds.length) this._map.fitBounds(bounds, { maxZoom: 13, padding: [30, 30] });
    } catch (e) { /* silencioso — se reintenta en el próximo tick */ }
  },
};
