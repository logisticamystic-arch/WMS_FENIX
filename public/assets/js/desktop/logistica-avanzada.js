/* ============================================================
   WMS Desktop — Módulo LOGÍSTICA AVANZADA  v2.0
   Cross-Docking · Yard Management · Wave Picking · Replenishment
   ============================================================ */
WMS_MODULES.logistica = {
  _sub: 'crossdock',

  load(sub) {
    this._sub = sub || 'crossdock';
    WMS.setBreadcrumb('logistica');
    WMS.renderSidebar('logistica');
    this._renderSub(this._sub);
  },

  destroy() {},

  subLabel(sub) {
    return {
      crossdock:     'Cross-Docking',
      yard:          'Yard Management',
      wave:          'Wave Picking',
      replenishment: 'Reabastecimiento',
    }[sub] || sub;
  },

  _renderSub(sub) {
    this._sub = sub;
    WMS._activateSidebarItem && WMS._activateSidebarItem(sub);
    if      (sub === 'crossdock')     this.renderCrossDock();
    else if (sub === 'yard')          this.renderYard();
    else if (sub === 'wave')          this.renderWave();
    else if (sub === 'replenishment') this.renderReplenishment();
    else this.renderCrossDock();
  },

  /* ── Helpers ─────────────────────────────────────────── */
  _fmtDate(v)  { return v ? new Date(v).toLocaleDateString('es-CO') : '—'; },
  _fmtDT(v)    { return v ? new Date(v).toLocaleString('es-CO')     : '—'; },
  _esc(s)      { return WMS.esc ? WMS.esc(s) : String(s||'').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c])); },

  /* Extrae datos del envelope {error,message,data:{...}} */
  _unpack(raw, key) {
    const payload = raw?.data ?? raw;          // quita el envelope HTTP si lo hay
    if (key) return payload?.[key] ?? [];
    return payload ?? {};
  },

  /* Genera HTML de KPI card */
  _kpiCard(accent, icon, value, label) {
    return `<div class="pro-kpi-card ${accent}">
      <div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="${icon}"></i></div></div>
      <div class="pro-kpi-value">${value ?? '—'}</div>
      <div class="pro-kpi-label">${label}</div>
    </div>`;
  },

  /* Genera bloque de filtros de fecha */
  _dateFilters(id, onRefresh) {
    const today = new Date().toISOString().split('T')[0];
    const d7    = new Date(Date.now() - 7*86400000).toISOString().split('T')[0];
    return `<div class="d-flex gap-2 align-items-center flex-wrap mb-3">
      <label class="text-muted small mb-0">Desde</label>
      <input type="date" id="${id}_ini" class="form-control form-control-sm" style="width:145px" value="${d7}">
      <label class="text-muted small mb-0">Hasta</label>
      <input type="date" id="${id}_fin" class="form-control form-control-sm" style="width:145px" value="${today}">
      <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.logistica.${onRefresh}()">
        <i class="fa-solid fa-filter"></i> Filtrar
      </button>
    </div>`;
  },

  _getDateParams(id) {
    const ini = document.getElementById(`${id}_ini`)?.value || '';
    const fin = document.getElementById(`${id}_fin`)?.value || '';
    const p   = new URLSearchParams();
    if (ini) p.append('ini', ini);
    if (fin) p.append('fin', fin);
    return p.toString() ? '?' + p.toString() : '';
  },

  /* Estado badges — normaliza case */
  _estadoBadge(estadoRaw, mapa) {
    const e = (estadoRaw || '').toLowerCase().replace(/\s+/g, '_');
    return mapa[e] || mapa[(estadoRaw||'').toLowerCase()]
        || `<span class="badge" style="background:#94a3b8;color:#fff">${this._esc(estadoRaw||'—')}</span>`;
  },

  /* ─────────────────────────────────────────────────────── */
  /* ── CROSS-DOCKING ───────────────────────────────────── */
  /* ─────────────────────────────────────────────────────── */
  async renderCrossDock() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderCrossDock()">
        <i class="fa-solid fa-rotate-right"></i> Actualizar
      </button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.crearCrossDock()">
        <i class="fa-solid fa-plus"></i> Nueva Orden CD
      </button>
      <button class="btn btn-sm btn-outline-secondary ms-2" onclick="window.open(API.base+'/crossdock/export/csv','_blank')">
        <i class="fa-solid fa-file-csv"></i> Exportar
      </button>
    `);
    WMS.spinner();
    try {
      const qs = this._getDateParams('cd');
      const [rList, rKpi] = await Promise.all([
        API.get('/crossdock' + qs),
        API.get('/crossdock/kpis/resumen' + qs),
      ]);

      /* La respuesta es {error,message,data:{ordenes:[],total:N}} */
      const ordenes = this._unpack(rList, 'ordenes');
      const kpis    = this._unpack(rKpi, 'kpis') ?? this._unpack(rKpi);

      const badgeMapa = {
        programado:  '<span class="status-badge sb-pending">Programado</span>',
        recibiendo:  '<span class="status-badge sb-active">Recibiendo</span>',
        clasificando:'<span class="status-badge sb-active">Clasificando</span>',
        despachando: '<span class="status-badge sb-active" style="background:#7c3aed">Despachando</span>',
        completado:  '<span class="status-badge sb-complete">Completado</span>',
        cancelado:   '<span class="status-badge sb-error">Cancelado</span>',
      };

      const rows = ordenes.length ? ordenes.slice(0, 50).map(o => {
        const estado = (o.estado || '').toLowerCase();
        const acciones = [
          (estado === 'programado')
            ? `<button class="btn btn-xs btn-success me-1" onclick="WMS_MODULES.logistica._cdAction(${o.id},'recibir')" title="Registrar recepción"><i class="fa-solid fa-arrow-down"></i></button>` : '',
          (estado === 'recibiendo' || estado === 'clasificando')
            ? `<button class="btn btn-xs btn-primary me-1" onclick="WMS_MODULES.logistica._cdAction(${o.id},'transferir')" title="Transferir a despacho"><i class="fa-solid fa-arrows-turn-right"></i></button>` : '',
          (estado === 'despachando' || estado === 'recibiendo' || estado === 'clasificando')
            ? `<button class="btn btn-xs btn-success" onclick="WMS_MODULES.logistica._cdAction(${o.id},'completar')" title="Completar orden"><i class="fa-solid fa-check"></i></button>` : '',
        ].join('');
        return `<tr>
          <td class="ps-3">
            <div style="font-weight:700">#${o.id}</div>
            <div style="font-size:.7rem;color:#64748b">${this._esc(o.numero||'')}</div>
          </td>
          <td class="text-center">${this._estadoBadge(o.estado, badgeMapa)}</td>
          <td>${this._esc(o.muelle_entrada||'—')}</td>
          <td>${this._esc(o.muelle_salida||'—')}</td>
          <td class="text-end">${o.total_lineas||0}</td>
          <td class="text-end">
            <span style="color:#16a34a;font-weight:600">${o.total_recibido||0}</span>
            <span style="color:#94a3b8"> / ${o.total_esperado||0}</span>
          </td>
          <td style="font-size:.78rem;color:#64748b">${this._fmtDate(o.created_at)}</td>
          <td class="text-center">${acciones||'<span class="text-muted">—</span>'}</td>
        </tr>`;
      }).join('') : `<tr><td colspan="8" class="text-center py-5">
        <i class="fa-solid fa-right-left fa-2x text-muted mb-2 d-block"></i>
        <span class="text-muted">Sin órdenes de cross-docking en el período seleccionado</span>
      </td></tr>`;

      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div>
            <h5 class="mb-0 fw-bold" style="color:#0f172a"><i class="fa-solid fa-right-left me-2" style="color:#0277BD"></i>Cross-Docking</h5>
            <small class="text-muted">Transferencia directa entre muelles sin almacenamiento intermedio</small>
          </div>
        </div>

        ${this._dateFilters('cd', 'renderCrossDock')}

        <div class="pro-kpi-grid mb-4">
          ${this._kpiCard('accent-blue',   'fa-solid fa-right-left',  kpis.total_ordenes||ordenes.length, 'Órdenes')}
          ${this._kpiCard('accent-amber',  'fa-solid fa-clock',       kpis.en_proceso||0,                 'En Proceso')}
          ${this._kpiCard('accent-green',  'fa-solid fa-check-double',kpis.completadas||0,                'Completadas')}
          ${this._kpiCard('accent-purple', 'fa-solid fa-stopwatch',
            kpis.avg_tiempo_suelo_min ? Math.round(kpis.avg_tiempo_suelo_min)+' min' : '—',
            'T° Suelo Prom.')}
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="pro-section-title"><i class="fa-solid fa-list me-2" style="color:#0277BD"></i>Órdenes de Cross-Docking</div>
            <span class="badge bg-light text-dark border">${ordenes.length} registros</span>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc">
                  <th class="ps-3">ORDEN</th>
                  <th class="text-center">ESTADO</th>
                  <th>MUELLE ENTRADA</th>
                  <th>MUELLE SALIDA</th>
                  <th class="text-end">LÍNEAS</th>
                  <th class="text-end">RECIBIDO / ESP.</th>
                  <th>FECHA</th>
                  <th class="text-center">ACCIONES</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      </div>`);

    } catch(e) {
      WMS.setContent(`<div class="m-empty">
        <i class="fa-solid fa-triangle-exclamation fa-2x text-danger mb-3"></i>
        <p class="fw-bold">Error cargando Cross-Docking</p>
        <p class="text-muted small">${this._esc(e.message)}</p>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="WMS_MODULES.logistica.renderCrossDock()">
          <i class="fa-solid fa-rotate-right"></i> Reintentar
        </button>
      </div>`);
    }
  },

  async crearCrossDock() {
    WMS.toast('Formulario de creación Cross-Dock en desarrollo', 'info');
  },

  async _cdAction(id, action) {
    const labels = { recibir: 'Recepción registrada', transferir: 'Transferencia marcada', completar: 'Orden completada' };
    try {
      await API.post(`/crossdock/${id}/${action}`);
      WMS.toast(labels[action] || `Acción ${action} ejecutada`, 'success');
      this.renderCrossDock();
    } catch(e) {
      WMS.toast('Error: ' + e.message, 'danger');
    }
  },

  /* ─────────────────────────────────────────────────────── */
  /* ── YARD MANAGEMENT ─────────────────────────────────── */
  /* ─────────────────────────────────────────────────────── */
  async renderYard() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderYard()">
        <i class="fa-solid fa-rotate-right"></i> Actualizar
      </button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.crearCitaYard()">
        <i class="fa-solid fa-plus"></i> Nueva Cita
      </button>
      <button class="btn btn-sm btn-outline-secondary ms-2" onclick="window.open(API.base+'/yard/export/csv','_blank')">
        <i class="fa-solid fa-file-csv"></i> Exportar
      </button>
    `);
    WMS.spinner();
    try {
      const qs = this._getDateParams('yard');
      const [rList, rKpi, rMuelles] = await Promise.all([
        API.get('/yard' + qs),
        API.get('/yard/kpis/resumen' + qs),
        API.get('/yard/muelles/estado'),
      ]);

      /* {error,message,data:{citas:[],total:N}} */
      const citas   = this._unpack(rList,    'citas');
      const kpis    = this._unpack(rKpi,     'kpis') ?? this._unpack(rKpi);
      const muellesPayload = this._unpack(rMuelles);
      /* muelles/estado devuelve {muelles_activos:{muelleKey:[...]}, cola_proximas:[]} */
      const muellesActivos = muellesPayload?.muelles_activos ?? {};
      const cola           = muellesPayload?.cola_proximas ?? [];

      const badgeMapa = {
        programado: '<span class="status-badge sb-pending">Programado</span>',
        en_patio:   '<span class="status-badge sb-active">En Patio</span>',
        operando:   '<span class="status-badge sb-active" style="background:#7c3aed">Operando</span>',
        completado: '<span class="status-badge sb-complete">Completado</span>',
        cancelado:  '<span class="status-badge sb-error">Cancelado</span>',
        no_show:    '<span class="status-badge sb-error">No Show</span>',
      };

      /* Tarjetas de muelles */
      const muelleKeys = Object.keys(muellesActivos);
      const muelleCards = muelleKeys.slice(0, 12).map(key => {
        const citasMuelle = muellesActivos[key] || [];
        const citaActiva  = citasMuelle[0];
        const ocupado     = citaActiva && ['En Patio','Operando'].includes(citaActiva.estado);
        const bg     = ocupado ? '#fef2f2' : '#f0fdf4';
        const border = ocupado ? '#fca5a5' : '#86efac';
        const label  = ocupado
          ? `<div style="font-size:.72rem;font-weight:600;margin-top:4px;color:#dc2626">
               <i class="fa-solid fa-truck me-1"></i>${this._esc(citaActiva.transportista||citaActiva.placa_vehiculo||'Ocupado')}
             </div>`
          : `<div style="font-size:.72rem;color:#16a34a;margin-top:4px">Disponible</div>`;
        return `<div style="background:${bg};border:1px solid ${border};border-radius:10px;padding:12px;text-align:center">
          <div style="font-weight:800;font-size:.85rem">${this._esc(key)}</div>
          ${label}
        </div>`;
      }).join('');

      const totalMuelles  = muelleKeys.length;
      const muellesOcupados = muelleKeys.filter(k => {
        const c = (muellesActivos[k]||[])[0];
        return c && ['En Patio','Operando'].includes(c.estado);
      }).length;

      /* Filas de citas */
      const rows = citas.length ? citas.slice(0, 40).map(c => {
        const estado = (c.estado || '').toLowerCase().replace(/\s+/g, '_');
        const acciones = [
          (estado === 'programado')
            ? `<button class="btn btn-xs btn-success me-1" onclick="WMS_MODULES.logistica._yardAction(${c.id},'entrada')" title="Registrar entrada al patio"><i class="fa-solid fa-arrow-right-to-bracket"></i></button>` : '',
          (estado === 'en_patio')
            ? `<button class="btn btn-xs btn-primary me-1" onclick="WMS_MODULES.logistica._yardAction(${c.id},'inicio-operacion')" title="Iniciar operación en muelle"><i class="fa-solid fa-play"></i></button>` : '',
          (estado === 'operando')
            ? `<button class="btn btn-xs btn-warning me-1" onclick="WMS_MODULES.logistica._yardAction(${c.id},'fin-operacion')" title="Fin de operación"><i class="fa-solid fa-stop"></i></button>` : '',
          (estado === 'en_patio' || estado === 'operando')
            ? `<button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.logistica._yardAction(${c.id},'salida')" title="Registrar salida del patio"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>` : '',
        ].join('');
        const tipoBadge = c.tipo === 'Entrada' || c.tipo === 'entrada'
          ? '<span class="badge bg-success text-white">Entrada</span>'
          : '<span class="badge bg-info text-white">Salida</span>';
        return `<tr>
          <td class="ps-3">
            <div style="font-weight:700">${this._esc(c.numero||'#'+c.id)}</div>
          </td>
          <td class="text-center">${this._estadoBadge(c.estado, badgeMapa)}</td>
          <td>${this._esc(c.transportista||'—')}</td>
          <td><code>${this._esc(c.placa_vehiculo||'—')}</code></td>
          <td>${this._esc(c.muelle||'—')}</td>
          <td class="text-center">${tipoBadge}</td>
          <td style="font-size:.78rem">${this._fmtDT(c.fecha_cita)}</td>
          <td class="text-center" style="white-space:nowrap">${acciones||'<span class="text-muted">—</span>'}</td>
        </tr>`;
      }).join('') : `<tr><td colspan="8" class="text-center py-5">
        <i class="fa-solid fa-truck fa-2x text-muted mb-2 d-block"></i>
        <span class="text-muted">Sin citas programadas en el período seleccionado</span>
      </td></tr>`;

      /* Cola de próximas citas */
      const colaRows = cola.slice(0, 5).map(c =>
        `<li class="list-group-item py-1 px-3 d-flex justify-content-between align-items-center" style="font-size:.8rem">
          <span><i class="fa-solid fa-clock text-warning me-1"></i>${this._fmtDT(c.fecha_cita)}</span>
          <span class="fw-bold">${this._esc(c.muelle||'Sin muelle')}</span>
          <span class="text-muted">${this._esc(c.transportista||'—')}</span>
        </li>`
      ).join('');

      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div>
            <h5 class="mb-0 fw-bold" style="color:#0f172a"><i class="fa-solid fa-warehouse me-2" style="color:#7c3aed"></i>Yard Management</h5>
            <small class="text-muted">Control de patio, muelles y tiempos de turnaround de camiones</small>
          </div>
        </div>

        ${this._dateFilters('yard', 'renderYard')}

        <div class="pro-kpi-grid mb-4">
          ${this._kpiCard('accent-blue',   'fa-solid fa-truck',         kpis.total_citas||citas.length,                            'Citas Período')}
          ${this._kpiCard('accent-green',  'fa-solid fa-clock',
            kpis.avg_turnaround_min ? Math.round(kpis.avg_turnaround_min)+' min' : '—',
            'Turnaround Prom.')}
          ${this._kpiCard('accent-amber',  'fa-solid fa-hourglass-half', kpis.en_proceso||0,                                        'En Patio/Operando')}
          ${this._kpiCard('accent-purple', 'fa-solid fa-gauge',
            `${muellesOcupados}/${totalMuelles}`,
            'Muelles Ocupados')}
        </div>

        ${totalMuelles ? `
        <div class="row g-3 mb-4">
          <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-white py-3">
                <div class="pro-section-title"><i class="fa-solid fa-warehouse me-2" style="color:#7c3aed"></i>Estado de Muelles</div>
              </div>
              <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">
                  ${muelleCards}
                </div>
              </div>
            </div>
          </div>
          ${colaRows ? `<div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-white py-3">
                <div class="pro-section-title" style="font-size:.85rem"><i class="fa-solid fa-hourglass me-2 text-warning"></i>Próximas (4h)</div>
              </div>
              <ul class="list-group list-group-flush">${colaRows}</ul>
            </div>
          </div>` : ''}
        </div>` : ''}

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="pro-section-title"><i class="fa-solid fa-calendar-check me-2" style="color:#1a56db"></i>Citas de Camiones</div>
            <span class="badge bg-light text-dark border">${citas.length} registros</span>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc">
                  <th class="ps-3">N° CITA</th>
                  <th class="text-center">ESTADO</th>
                  <th>TRANSPORTISTA</th>
                  <th>PLACA</th>
                  <th>MUELLE</th>
                  <th class="text-center">TIPO</th>
                  <th>FECHA CITA</th>
                  <th class="text-center">ACCIONES</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      </div>`);

    } catch(e) {
      WMS.setContent(`<div class="m-empty">
        <i class="fa-solid fa-triangle-exclamation fa-2x text-danger mb-3"></i>
        <p class="fw-bold">Error cargando Yard Management</p>
        <p class="text-muted small">${this._esc(e.message)}</p>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="WMS_MODULES.logistica.renderYard()">
          <i class="fa-solid fa-rotate-right"></i> Reintentar
        </button>
      </div>`);
    }
  },

  async crearCitaYard() {
    WMS.toast('Formulario de nueva cita en desarrollo', 'info');
  },

  async _yardAction(id, action) {
    const labels = {
      'entrada':          'Entrada al patio registrada',
      'inicio-operacion': 'Inicio de operación registrado',
      'fin-operacion':    'Fin de operación registrado',
      'salida':           'Salida del patio registrada',
    };
    try {
      await API.post(`/yard/${id}/${action}`);
      WMS.toast(labels[action] || `Acción ${action} registrada`, 'success');
      this.renderYard();
    } catch(e) {
      WMS.toast('Error: ' + e.message, 'danger');
    }
  },

  /* ─────────────────────────────────────────────────────── */
  /* ── WAVE PICKING ────────────────────────────────────── */
  /* ─────────────────────────────────────────────────────── */
  async renderWave() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderWave()">
        <i class="fa-solid fa-rotate-right"></i> Actualizar
      </button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.autoGenerarWave()">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Generar Waves
      </button>
      <button class="btn btn-sm btn-outline-secondary ms-2" onclick="window.open(API.base+'/wave/export/csv','_blank')">
        <i class="fa-solid fa-file-csv"></i> Exportar
      </button>
    `);
    WMS.spinner();
    try {
      const qs = this._getDateParams('wave');
      const [rList, rKpi] = await Promise.all([
        API.get('/wave' + qs),
        API.get('/wave/kpis/resumen' + qs),
      ]);

      /* {error,message,data:{waves:[],total:N}} */
      const waves = this._unpack(rList, 'waves');
      const kpis  = this._unpack(rKpi,  'kpis') ?? this._unpack(rKpi);

      const badgeMapa = {
        preparando: '<span class="status-badge sb-pending">Preparando</span>',
        pendiente:  '<span class="status-badge sb-pending">Pendiente</span>',
        en_proceso: '<span class="status-badge sb-active">En Proceso</span>',
        completado: '<span class="status-badge sb-complete">Completado</span>',
        cancelado:  '<span class="status-badge sb-error">Cancelado</span>',
      };

      const rows = waves.length ? waves.slice(0, 40).map(w => {
        const estadoNorm = (w.estado || '').toLowerCase().replace(/\s+/g, '_');
        const planillas  = w.planillas_count ?? w.total_planillas ?? 0;
        const lineas     = w.lineas_count    ?? w.total_lineas    ?? 0;
        const lineasComp = w.lineas_completadas ?? 0;
        const pct = lineas > 0 ? Math.round(lineasComp / lineas * 100) : 0;
        const wpClass = pct >= 80 ? 'wp-green' : pct >= 40 ? 'wp-blue' : 'wp-amber';

        const acciones = [
          (estadoNorm === 'preparando' || estadoNorm === 'pendiente')
            ? `<button class="btn btn-xs btn-success me-1" onclick="WMS_MODULES.logistica._waveAction(${w.id},'iniciar')" title="Iniciar wave"><i class="fa-solid fa-play"></i></button>` : '',
          (estadoNorm === 'en_proceso')
            ? `<button class="btn btn-xs btn-primary me-1" onclick="WMS_MODULES.logistica._waveAction(${w.id},'completar')" title="Completar wave"><i class="fa-solid fa-check"></i></button>` : '',
          (!['completado','cancelado'].includes(estadoNorm))
            ? `<button class="btn btn-xs btn-outline-danger" onclick="WMS_MODULES.logistica._waveAction(${w.id},'cancelar')" title="Cancelar wave"><i class="fa-solid fa-ban"></i></button>` : '',
        ].join('');

        return `<tr>
          <td class="ps-3">
            <div style="font-weight:700">${this._esc(w.numero||'Wave #'+w.id)}</div>
            <div style="font-size:.68rem;color:#64748b">${this._esc(w.nombre||w.criterio||'—')}</div>
          </td>
          <td class="text-center">${this._estadoBadge(w.estado, badgeMapa)}</td>
          <td class="text-center">
            <span class="badge bg-light text-dark border">${planillas}</span>
          </td>
          <td class="text-end">${lineas}</td>
          <td style="min-width:150px">
            <div class="wave-progress">
              <div class="wave-progress-bar">
                <div class="wave-progress-fill ${wpClass}" style="width:${pct}%"></div>
              </div>
              <span class="wave-progress-pct">${pct}%</span>
            </div>
          </td>
          <td style="font-size:.78rem;color:#64748b">${this._fmtDT(w.created_at)}</td>
          <td class="text-center" style="white-space:nowrap">${acciones||'<span class="text-muted">—</span>'}</td>
        </tr>`;
      }).join('') : `<tr><td colspan="7" class="text-center py-5">
        <i class="fa-solid fa-layer-group fa-2x text-muted mb-2 d-block"></i>
        <span class="text-muted">Sin waves en el período. Use <strong>Auto-Generar</strong> para crear waves automáticamente.</span>
      </td></tr>`;

      const avgDur = kpis?.avg_duracion_min ? Math.round(kpis.avg_duracion_min)+' min' : '—';

      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div>
            <h5 class="mb-0 fw-bold" style="color:#0f172a"><i class="fa-solid fa-layer-group me-2" style="color:#1a56db"></i>Wave Picking</h5>
            <small class="text-muted">Agrupación de planillas por zona o criterio para optimizar rutas de picking</small>
          </div>
        </div>

        ${this._dateFilters('wave', 'renderWave')}

        <div class="pro-kpi-grid mb-4">
          ${this._kpiCard('accent-blue',   'fa-solid fa-layer-group',   kpis?.total_waves||waves.length,    'Waves Totales')}
          ${this._kpiCard('accent-amber',  'fa-solid fa-spinner',        kpis?.en_proceso||0,                'En Proceso')}
          ${this._kpiCard('accent-green',  'fa-solid fa-check-double',   kpis?.completadas||0,               'Completadas')}
          ${this._kpiCard('accent-purple', 'fa-solid fa-boxes-stacked',  kpis?.total_lineas||0,              'Líneas Totales')}
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
            <div class="pro-section-title"><i class="fa-solid fa-layer-group me-2" style="color:#1a56db"></i>Waves de Picking</div>
            <div class="d-flex align-items-center gap-2">
              ${avgDur !== '—' ? `<span class="badge bg-light text-dark border"><i class="fa-solid fa-stopwatch me-1 text-muted"></i>Dur. prom: ${avgDur}</span>` : ''}
              <span class="badge bg-light text-dark border">${waves.length} waves</span>
            </div>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc">
                  <th class="ps-3">WAVE</th>
                  <th class="text-center">ESTADO</th>
                  <th class="text-center">PLANILLAS</th>
                  <th class="text-end">LÍNEAS</th>
                  <th>PROGRESO</th>
                  <th>CREADA</th>
                  <th class="text-center">ACCIONES</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>
      </div>`);

    } catch(e) {
      WMS.setContent(`<div class="m-empty">
        <i class="fa-solid fa-triangle-exclamation fa-2x text-danger mb-3"></i>
        <p class="fw-bold">Error cargando Wave Picking</p>
        <p class="text-muted small">${this._esc(e.message)}</p>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="WMS_MODULES.logistica.renderWave()">
          <i class="fa-solid fa-rotate-right"></i> Reintentar
        </button>
      </div>`);
    }
  },

  async autoGenerarWave() {
    const criterio = prompt('Criterio de agrupación:\n  zona      — por zona de almacenamiento\n  auxiliar  — por auxiliar asignado\n  prioridad — por prioridad de la planilla\n  cliente   — por cliente\n\nEscribe el criterio:', 'zona');
    if (criterio === null) return;
    if (!confirm(`¿Auto-generar waves agrupando planillas por "${criterio.trim() || 'zona'}"?`)) return;
    try {
      const r = await API.post('/wave/auto-generar', { criterio: criterio.trim() || 'zona' });
      const d = r?.data ?? r;
      WMS.toast(`${d?.waves_creadas ?? 'N'} wave(s) creadas correctamente por ${d?.criterio||criterio}`, 'success');
      this.renderWave();
    } catch(e) {
      WMS.toast('Error generando waves: ' + e.message, 'danger');
    }
  },

  async _waveAction(id, action) {
    const labels = { iniciar: 'Wave iniciada', completar: 'Wave completada', cancelar: 'Wave cancelada' };
    if (action === 'cancelar' && !confirm('¿Confirmar cancelación de esta wave?')) return;
    try {
      await API.post(`/wave/${id}/${action}`);
      WMS.toast(labels[action] || `Wave ${action}`, 'success');
      this.renderWave();
    } catch(e) {
      WMS.toast('Error: ' + e.message, 'danger');
    }
  },

  /* ─────────────────────────────────────────────────────── */
  /* ── REABASTECIMIENTO ────────────────────────────────── */
  /* ─────────────────────────────────────────────────────── */
  async renderReplenishment() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderReplenishment()">
        <i class="fa-solid fa-rotate-right"></i> Actualizar
      </button>
      <button class="btn btn-sm btn-success ms-2" onclick="WMS_MODULES.logistica.ejecutarAutoReplenishment()">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Ejecutar Auto-Replenishment
      </button>
    `);
    WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
      <div class="d-flex align-items-center gap-3 mb-4">
        <div>
          <h5 class="mb-0 fw-bold" style="color:#0f172a">
            <i class="fa-solid fa-arrows-rotate me-2" style="color:#16a34a"></i>Reabastecimiento Automático
          </h5>
          <small class="text-muted">Motor de auto-replenishment que genera tareas para montacarguistas</small>
        </div>
      </div>
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <i class="fa-solid fa-arrows-rotate fa-3x text-success mb-3"></i>
          <h6 class="fw-bold">Motor de Reabastecimiento</h6>
          <p class="text-muted mb-3" style="max-width:450px;margin:0 auto">
            Al ejecutar, el sistema analiza los niveles de stock y genera tareas de reabastecimiento
            notificando automáticamente a los montacarguistas activos de la sucursal.
          </p>
          <button class="btn btn-success" onclick="WMS_MODULES.logistica.ejecutarAutoReplenishment()">
            <i class="fa-solid fa-play me-2"></i>Ejecutar Ahora
          </button>
        </div>
      </div>
    </div>`);
  },

  async ejecutarAutoReplenishment() {
    if (!confirm('¿Ejecutar motor de auto-replenishment? Se notificará a los montacarguistas activos.')) return;
    try {
      await API.post('/reabastecimiento/auto');
      WMS.toast('Auto-replenishment ejecutado. Montacarguistas notificados.', 'success');
    } catch(e) {
      WMS.toast('Error en replenishment: ' + e.message, 'danger');
    }
  },
};
