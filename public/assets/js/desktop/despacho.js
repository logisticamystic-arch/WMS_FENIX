/* ============================================================
   WMS Desktop — Módulo DESPACHO & TMS
   Sub-vistas: certificacion | cargue | dashboard | tms
   ============================================================ */
WMS_MODULES.despacho = {
  load(sub) {
    WMS.setBreadcrumb('despacho', this.subLabel(sub));
    WMS.renderSidebar('despacho');
    const s = sub || 'certificacion';
    const fn = {
      certificacion: this.show_certificacion, cargue: this.show_cargue,
      dashboard: this.show_dashboard, tms: this.show_tms,
    };
    (fn[s]?.bind(this) || fn.certificacion.bind(this))();
    // Certificación es proceso crítico: auto-refresh activo
    if (s === 'certificacion') this.startAutoRefresh();
    else this.stopAutoRefresh();
  },

  // ── Auto-refresh certificación (proceso crítico, máx 5 usuarios) ──────────
  _certInterval: null,
  startAutoRefresh() {
    this.stopAutoRefresh();
    this._certInterval = setInterval(() => {
      if (WMS.currentModule !== 'despacho') { this.stopAutoRefresh(); return; }
      if (WMS.currentSubModule === 'certificacion') this.show_certificacion(true);
      else this.stopAutoRefresh();
    }, 30000);
    this._updateAutoRefreshBadge(true);
  },
  stopAutoRefresh() {
    if (this._certInterval) { clearInterval(this._certInterval); this._certInterval = null; }
    this._updateAutoRefreshBadge(false);
  },
  _updateAutoRefreshBadge(active) {
    const badge = document.getElementById('cert-refresh-badge');
    if (badge) badge.style.display = active ? 'inline-flex' : 'none';
  },

  subLabel(s) {
    const m = { certificacion:'Certificación de Pedidos', cargue:'Planilla de Cargue',
      dashboard:'Dashboard Certificación', tms:'Integración TMS' };
    return m[s] || s || 'Panel';
  },

  // ── CERTIFICACIÓN ─────────────────────────────────────────────
  async show_certificacion(silent = false) {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.importarPlanillas()"><i class="fa-solid fa-file-import"></i> Importar Planillas</button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <span id="cert-refresh-badge" style="display:inline-flex;align-items:center;gap:5px;background:#198754;color:#fff;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;">
        <span style="width:7px;height:7px;border-radius:50%;background:#fff;animation:pulse-dot 1.2s infinite;display:inline-block;"></span> Auto 30s
      </span>`);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/planillas', 'limit=100');
      const items = r.data || r || [];
      const stChip = s => {
        const m = { Creada:'status-creada', Asignada:'status-confirmada', 'En Proceso':'status-en-proceso',
          Cerrada:'status-cerrada', Cancelada:'status-cancelada' };
        return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
      };
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar planilla, cliente..." oninput="WMS_MODULES.despacho.filterTable(this.value,'cert-table')">
          </div>
          <select class="form-control" style="max-width:160px;" onchange="WMS_MODULES.despacho.filterEstadoCert(this.value)">
            <option value="">Todos los estados</option>
            <option>Creada</option><option>Asignada</option>
            <option>En Proceso</option><option>Cerrada</option>
          </select>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Planillas de Certificación (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="cert-table">
              <thead><tr><th>Planilla</th><th>Cliente</th><th>Ruta</th><th>Líneas</th><th>Estado</th><th>Certificador</th><th>Fecha</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(p => `<tr data-estado-cert="${p.estado||''}">
                <td><span class="badge badge-info">${WMS.esc(p.numero_planilla||p.planilla_numero||('#'+p.id))}</span></td>
                <td><strong>${WMS.esc(p.cliente||p.cliente_nombre||'-')}</strong></td>
                <td>${WMS.esc(p.ruta||'-')}</td>
                <td class="text-center">${p.total_lineas||p.lineas||0}</td>
                <td>${stChip(p.estado||'Creada')}</td>
                <td>${WMS.esc(p.certificador||p.auxiliar||'-')}</td>
                <td>${WMS.formatDate(p.fecha||p.created_at)||'-'}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho.verPlanilla(${p.id})"><i class="fa-solid fa-eye"></i></button>
                  ${p.estado==='Creada'||p.estado==='Asignada' ? `<button class="btn btn-sm btn-primary" onclick="WMS_MODULES.despacho.asignarCert(${p.id})"><i class="fa-solid fa-user-check"></i></button>` : ''}
                  ${p.estado==='Cerrada' ? `<button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.generarCargue(${p.id})"><i class="fa-solid fa-truck-loading"></i> Cargue</button>` : ''}
                </div></td>
              </tr>`).join('') || '<tr><td colspan="8" class="table-empty">Sin planillas de certificación</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  filterTable(q, tableId) {
    const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
    const f = q.toLowerCase();
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none'; });
  },

  filterEstadoCert(estado) {
    document.querySelectorAll('#cert-table tbody tr').forEach(r => {
      r.style.display = (!estado || r.dataset.estadoCert === estado) ? '' : 'none';
    });
  },

  async verPlanilla(id) {
    WMS.spinner();
    try {
      const [r, ra] = await Promise.all([
        API.get('/planillas/cert/' + id),
        API.get('/planillas/cert/' + id + '/analytics')
      ]);
      const p = r.data || r;
      const analytics = ra.data || ra;
      const lineas = p.detalles || [];
      
      const stats = analytics.overview || {};
      const kpis  = analytics.kpis || {};

      WMS.showModal('Análisis de Planilla #' + (p.numero_planilla || id), `
        <div class="inv-commander-root" style="padding:0; background:transparent;">
          <div class="kpi-dashboard-row" style="grid-template-columns: repeat(3, 1fr); gap:12px; margin-bottom:15px;">
            <div class="kpi-dashboard-card gold" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Eficiencia</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${kpis.lines_per_minute || 0} L/min</span>
              </div>
            </div>
            <div class="kpi-dashboard-card blue" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Exactitud</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${stats.accuracy || 0}%</span>
              </div>
            </div>
            <div class="kpi-dashboard-card green" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Progreso</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${kpis.progress_pct || 0}%</span>
              </div>
            </div>
          </div>

          <div class="table-container" style="max-height:400px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:4px;">
            <table class="erp-table">
              <thead style="position:sticky; top:0; background:#f8fafc; z-index:10;">
                <tr>
                  <th>Producto / SKU</th>
                  <th class="text-center">Sist.</th>
                  <th class="text-center">Cert.</th>
                  <th class="text-center">Diff.</th>
                  <th class="text-center">Estado</th>
                  <th class="text-center">Acción</th>
                </tr>
              </thead>
              <tbody>${lineas.map(l => {
                const diff = (l.cantidad_esperada||0) - (l.cantidad_certificada||0);
                return `
                <tr class="${diff !== 0 && l.cantidad_certificada > 0 ? 'diff-detected' : ''}">
                  <td>
                    <div class="fw-600">${WMS.esc(l.producto_nombre)}</div>
                    <div class="text-muted text-sm" style="font-family:monospace;">${WMS.esc(l.producto_codigo)}</div>
                  </td>
                  <td class="text-center fw-700">${WMS.formatNum(l.cantidad_esperada)}</td>
                  <td class="text-center fw-700" style="color:#1a56db;">${WMS.formatNum(l.cantidad_certificada)}</td>
                  <td class="text-center">
                    ${diff === 0 ? '<span class="status-badge success"><i class="fa-solid fa-check"></i></span>' : `<span class="badge badge-danger">${diff > 0 ? '-' : '+'}${WMS.formatNum(Math.abs(diff))}</span>`}
                  </td>
                  <td class="text-center">
                    <span class="badge ${l.es_correcto ? 'badge-success' : 'badge-warning'}">${l.es_correcto ? 'Validado' : 'Pendiente'}</span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho.adminOverride(${p.id}, ${l.id}, '${WMS.esc(l.producto_nombre)}', ${l.cantidad_certificada})">
                      <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                  </td>
                </tr>`;
              }).join('') || '<tr><td colspan="6" class="table-empty">Sin líneas en este documento</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar Ventana</button>
         ${p.estado === 'ConNovedad' ? `<button class="btn btn-warning" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-triangle-exclamation"></i> Forzar Salida</button>` : ''}
         ${p.estado === 'Completada' ? `<button class="btn btn-primary" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-truck-loading"></i> Proceder a Cargue</button>` : ''}`);
    } catch(e) { 
        console.error(e);
        WMS.toast('error', 'Error cargando analítica de planilla'); 
    }
  },

  async adminOverride(certId, detId, nombre, actual) {
    const nueva = prompt(`[ADMIN OVERRIDE] Corregir cantidad para:\n${nombre}\n\nCantidad actual registrada: ${actual}\nIngrese la cantidad real:`, actual);
    if (nueva === null || nueva === "" || isNaN(nueva)) return;
    
    WMS.spinner();
    try {
        const r = await API.post('/planillas/cert/' + certId + '/editar', {
            detalle_id: detId,
            cantidad: parseFloat(nueva)
        });
        if (r.error) WMS.toast('error', r.message);
        else {
            WMS.toast('success', 'Cantidad corregida exitosamente');
            this.verPlanilla(certId); // Refresh modal
        }
    } catch(e) { WMS.toast('error', 'Error en el override'); }
  },

  async asignarCert(planillaId) {
    let personal = [];
    try {
      const r = await API.get('/param/personal', 'activo=1&limit=100');
      personal = r.data || r || [];
    } catch(e) {}
    WMS.showModal('Asignar Certificador', `
      <div class="form-group"><label class="form-label">Certificador <span class="required">*</span></label>
        <select id="cert-personal" class="form-control">
          <option value="">Seleccionar...</option>
          ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre||'')} — ${WMS.esc(p.rol||'')}</option>`).join('')}
        </select></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.confirmarAsigCert(${planillaId})"><i class="fa-solid fa-user-check"></i> Asignar</button>`);
  },

  async confirmarAsigCert(id) {
    const pid = document.getElementById('cert-personal')?.value;
    if (!pid) { WMS.toast('warning', 'Seleccione un certificador'); return; }
    try {
      const r = await API.post('/planillas/asignar', { planilla_id: id, personal_id: parseInt(pid) });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Certificador asignado'); WMS.closeModal('generic-modal'); this.show_certificacion(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async importarPlanillas() {
    WMS.showModal('Importar Planillas de Certificación', `
      <p class="text-muted" style="margin-bottom:12px;">Suba un CSV con: numero_planilla, cliente, ruta, codigo_ean, cantidad</p>
      <div class="form-group">
        <a href="/WMS_FENIX/public/api/param/import-export/template/planillas" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Descargar Plantilla</a>
      </div>
      <div class="form-group"><label class="form-label">Archivo CSV</label>
        <input type="file" id="plan-csv" class="form-control" accept=".csv"></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.uploadPlanillas()"><i class="fa-solid fa-upload"></i> Importar</button>`);
  },

  async uploadPlanillas() {
    const file = document.getElementById('plan-csv')?.files[0];
    if (!file) { WMS.toast('warning', 'Seleccione un archivo CSV'); return; }
    const fd = new FormData(); fd.append('file', file);
    try {
      const r = await fetch('/WMS_FENIX/public/api/planillas/importar', {
        method: 'POST', headers: { Authorization: 'Bearer ' + localStorage.getItem('wms_token') }, body: fd
      });
      const j = await r.json();
      if (j.error) WMS.toast('error', j.message);
      else { WMS.toast('success', 'Importación: ' + (j.importadas||0) + ' planilla(s)'); WMS.closeModal('generic-modal'); this.show_certificacion(); }
    } catch(e) { WMS.toast('error', 'Error importando'); }
  },

  // ── PLANILLA DE CARGUE ────────────────────────────────────────
  async show_cargue() {
    WMS.setToolbar(`<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.nuevoPlanillaCargue()"><i class="fa-solid fa-plus"></i> Nuevo Cargue</button>`);
    WMS.spinner();
    try {
      const r = await API.get('/despachos', 'limit=100');
      const items = r.data || r || [];
      const stChip = s => {
        const m = { Pendiente:'status-creada', 'En Cargue':'status-en-proceso', Despachado:'status-cerrada', Cancelado:'status-cancelada' };
        return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
      };
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar placa, conductor, ruta..." oninput="WMS_MODULES.despacho.filterTable(this.value,'cargue-table')">
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-loading"></i> Planillas de Cargue (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="cargue-table">
              <thead><tr><th>N° Planilla</th><th>Placa</th><th>Conductor</th><th>Ruta</th><th>Planillas Cert.</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(d => `<tr>
                <td><span class="badge badge-info">${WMS.esc(d.planilla_numero||d.numero||('#'+d.id))}</span></td>
                <td><strong>${WMS.esc(d.placa||'-')}</strong></td>
                <td>${WMS.esc(d.conductor||'-')}</td>
                <td>${WMS.esc(d.ruta||'-')}</td>
                <td class="text-center">${d.total_planillas||d.planillas||0}</td>
                <td>${stChip(d.estado||'Pendiente')}</td>
                <td>${WMS.formatDate(d.created_at)||'-'}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho.verCargue(${d.id})"><i class="fa-solid fa-eye"></i></button>
                  ${d.estado==='En Cargue'||d.estado==='Pendiente' ? `<button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.despacharCargue(${d.id})"><i class="fa-solid fa-truck"></i> Despachar</button>` : ''}
                </div></td>
              </tr>`).join('') || '<tr><td colspan="8" class="table-empty">Sin planillas de cargue</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  nuevoPlanillaCargue() {
    WMS.showModal('Nueva Planilla de Cargue', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">Placa del Vehículo <span class="required">*</span></label><input id="car-placa" class="form-control" placeholder="ABC-123"></div>
        <div class="form-group"><label class="form-label">Conductor <span class="required">*</span></label><input id="car-conductor" class="form-control" placeholder="Nombre del conductor"></div>
        <div class="form-group"><label class="form-label">Ruta <span class="required">*</span></label><input id="car-ruta" class="form-control" placeholder="Ej: Bogotá Norte"></div>
        <div class="form-group"><label class="form-label">N° Precinto</label><input id="car-precinto" class="form-control" placeholder="PRE-001"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Observaciones</label><input id="car-obs" class="form-control" placeholder="Notas adicionales"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.saveCargue()"><i class="fa-solid fa-save"></i> Crear Cargue</button>`);
  },

  async saveCargue() {
    const placa = document.getElementById('car-placa')?.value.trim();
    const conductor = document.getElementById('car-conductor')?.value.trim();
    const ruta = document.getElementById('car-ruta')?.value.trim();
    if (!placa || !conductor || !ruta) { WMS.toast('warning', 'Placa, Conductor y Ruta son requeridos'); return; }
    try {
      const r = await API.post('/despachos', {
        placa, conductor, ruta,
        numero_precinto: document.getElementById('car-precinto')?.value.trim()||null,
        observaciones: document.getElementById('car-obs')?.value.trim()||null,
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Cargue creado'); WMS.closeModal('generic-modal'); this.show_cargue(); }
    } catch(e) { WMS.toast('error', 'Error guardando'); }
  },

  async verCargue(id) {
    try {
      const r = await API.get('/despachos/' + id);
      const d = r.data || r;
      const planillas = d.planillas || d.detalles || [];
      WMS.showModal('Cargue #' + (d.planilla_numero || id), `
        <div class="form-grid form-grid-2" style="margin-bottom:16px;">
          <div><label class="form-label">Placa</label><p>${WMS.esc(d.placa||'-')}</p></div>
          <div><label class="form-label">Conductor</label><p>${WMS.esc(d.conductor||'-')}</p></div>
          <div><label class="form-label">Ruta</label><p>${WMS.esc(d.ruta||'-')}</p></div>
          <div><label class="form-label">Estado</label><p><span class="badge badge-info">${WMS.esc(d.estado||'')}</span></p></div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Planilla</th><th>Cliente</th><th>Ruta</th><th>Estado</th></tr></thead>
            <tbody>${planillas.map(p => `<tr>
              <td>${WMS.esc(p.numero_planilla||('-'))}</td>
              <td>${WMS.esc(p.cliente||'-')}</td>
              <td>${WMS.esc(p.ruta||'-')}</td>
              <td><span class="badge badge-success">${WMS.esc(p.estado||'')}</span></td>
            </tr>`).join('') || '<tr><td colspan="4" class="table-empty">Sin planillas</td></tr>'}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
    } catch(e) { WMS.toast('error', 'Error cargando detalle'); }
  },

  generarCargue(planillaId) {
    this.nuevoPlanillaCargue();
    // Pre-populate could be done here in a real flow
  },

  async despacharCargue(id) {
    if (!confirm('¿Confirmar despacho? El vehículo saldrá con las planillas asignadas.')) return;
    try {
      const r = await API.put('/despachos/' + id, { estado: 'Despachado' });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Despacho confirmado'); this.show_cargue(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── DASHBOARD CERTIFICACIÓN — Professional Command Center ───────────────────
  async show_dashboard() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.despacho.show_dashboard()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);
    WMS.spinner();
    try {
      const [certDash, planillas] = await Promise.all([
        API.get('/planillas/cert/dashboard'),
        API.get('/planillas/progreso'),
      ]);
      const d       = certDash.data || certDash || {};
      const progreso = planillas.data || planillas || [];

      const totalP    = d.total      || progreso.length || 0;
      const accurateP = d.completadas || progreso.filter(p => p.archivo?.estado==='Certificada').length || 0;
      const iraCert   = totalP > 0 ? Math.round((accurateP / totalP) * 100) : 100;
      const iraColor  = iraCert >= 95 ? 'accent-green' : iraCert >= 80 ? 'accent-amber' : 'accent-red';

      WMS.setContent(`
<div class="pro-dashboard">

  <!-- KPIs -->
  <div class="pro-kpi-grid" style="grid-template-columns:repeat(4,1fr)">

    <div class="pro-kpi-card accent-blue">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-clipboard-check"></i></div>
        <span class="pro-kpi-trend neu">Total</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(totalP)}</div>
      <div class="pro-kpi-label">Planillas en ciclo</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-file-lines" style="color:#0070f2;margin-right:4px"></i>Documentos activos</div>
    </div>

    <div class="pro-kpi-card accent-amber">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-spinner"></i></div>
        <span class="pro-kpi-trend neu">Ahora</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(d.en_proceso||0)}</div>
      <div class="pro-kpi-label">En certificación</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-bolt" style="color:#e8a000;margin-right:4px"></i>Operación activa</div>
    </div>

    <div class="pro-kpi-card ${iraColor}">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-bullseye"></i></div>
        <span class="pro-kpi-trend ${iraCert>=95?'up':iraCert>=80?'neu':'down'}">${iraCert>=95?'Excelente':iraCert>=80?'Normal':'Bajo'}</span>
      </div>
      <div class="pro-kpi-value">${iraCert}%</div>
      <div class="pro-kpi-label">IRA Certificación</div>
      <div class="pro-kpi-sub">
        <div class="pro-progress-bar-bg" style="margin-top:4px">
          <div class="pro-progress-bar-fill ${iraCert>=95?'green':iraCert>=80?'':'red'}" style="width:${iraCert}%"></div>
        </div>
      </div>
    </div>

    <div class="pro-kpi-card accent-red">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <span class="pro-kpi-trend ${(d.con_novedad||0)>0?'down':'up'}">${(d.con_novedad||0)>0?'Alerta':'OK'}</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(d.con_novedad||0)}</div>
      <div class="pro-kpi-label">Con novedades</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-bell" style="color:#e03030;margin-right:4px"></i>Requieren atención</div>
    </div>
  </div>

  <!-- Tabla progreso planillas (expandible) -->
  <div class="pro-table-card" id="cert-table-card">
    <div class="pro-table-header" onclick="document.getElementById('cert-table-card').classList.toggle('collapsed')">
      <div class="pro-table-header-left">
        <span class="pro-table-title"><i class="fa-solid fa-microchip" style="margin-right:8px;color:#7c3aed"></i>Monitoreo de Procesos en Tiempo Real</span>
        <span class="pro-table-count">${progreso.length}</span>
      </div>
      <span class="pro-table-toggle"><i class="fa-solid fa-chevron-down"></i></span>
    </div>
    <div class="pro-table-body">
      <div class="pro-table-toolbar">
        <input class="pro-table-search" placeholder="Buscar planilla, documento…"
               oninput="WMS_MODULES.despacho._filterCertTable(this.value)">
        <select class="pro-table-filter-select" onchange="WMS_MODULES.despacho._filterCertEstado(this.value)">
          <option value="">Todos los estados</option>
          <option value="Certificada">Certificada</option>
          <option value="En Proceso">En Proceso</option>
          <option value="Pendiente">Pendiente</option>
        </select>
      </div>
      <div class="pro-table-wrap">
        <table class="erp-table" id="cert-table">
          <thead><tr>
            <th>Planilla / Documento</th>
            <th>Estado</th>
            <th style="text-align:center">Líneas</th>
            <th style="text-align:center">Unidades</th>
            <th style="min-width:160px">Progreso</th>
            <th style="text-align:center">Acción</th>
          </tr></thead>
          <tbody id="cert-tbody">
            ${progreso.map(p => {
              const pct     = p.pct_archivo || 0;
              const estado  = p.archivo?.estado || 'Pendiente';
              const fillCls = pct>=100?'green':pct>=70?'':'amber';
              const stCls   = estado==='Certificada'?'ok':estado==='En Proceso'?'warn':'info';
              return `<tr data-estado="${WMS.esc(estado)}">
                <td>
                  <div style="font-weight:700">${WMS.esc(p.archivo?.nombre_archivo||'Documento')}</div>
                  <div class="muted" style="font-size:.72rem">ID ${p.archivo?.id||'–'} · ${WMS.formatDate(p.archivo?.created_at)||'–'}</div>
                </td>
                <td><span class="pro-badge ${stCls}">${WMS.esc(estado)}</span></td>
                <td style="text-align:center;font-weight:700">${WMS.formatNum(p.total_lineas||0)}</td>
                <td style="text-align:center;font-weight:700">${WMS.formatNum(p.total_unidades||0)}</td>
                <td>
                  <div class="pro-progress-wrap">
                    <div class="pro-progress-bar-bg">
                      <div class="pro-progress-bar-fill ${fillCls}" style="width:${pct}%"></div>
                    </div>
                    <span class="pro-progress-label">${pct}%</span>
                  </div>
                </td>
                <td style="text-align:center">
                  <button class="btn btn-sm btn-secondary"
                          onclick="WMS_MODULES.despacho.verPlanilla(${p.archivo?.id||0})">
                    <i class="fa-solid fa-magnifying-glass-chart"></i> Detalle
                  </button>
                </td>
              </tr>`;
            }).join('') || '<tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No hay procesos activos en este momento</td></tr>'}
          </tbody>
        </table>
      </div>
      <div class="pro-table-footer">
        <span>${progreso.length} documentos</span>
        <span>IRA Global: <strong>${iraCert}%</strong></span>
      </div>
    </div>
  </div>

</div>`);
    } catch(e) {
      console.error(e);
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando dashboard de certificación</p></div>');
    }
  },

  _filterCertTable(q) {
    const rows = Array.from(document.querySelectorAll('#cert-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !q || tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
  },

  _filterCertEstado(val) {
    const rows = Array.from(document.querySelectorAll('#cert-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !val || tr.dataset.estado === val ? '' : 'none';
    });
  },

  // ── INTEGRACIÓN TMS ───────────────────────────────────────────
  async show_tms() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_tms()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.gestionarApiKeys()"><i class="fa-solid fa-key"></i> API Keys</button>`);
    WMS.spinner();
    try {
      const [ordenes, stock, despachos] = await Promise.all([
        API.get('/tms/ordenes'),
        API.get('/tms/stock'),
        API.get('/tms/despachos'),
      ]);
      const ords = ordenes.data || ordenes || [];
      const stk = stock.data || stock || [];
      const desp = despachos.data || despachos || [];
      WMS.setContent(`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
          <div class="card" style="border-left:4px solid #10b981;">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-satellite-dish" style="color:#10b981;"></i> Estado TMS</span></div>
            <div class="card-body">
              <div style="display:flex;align-items:center;gap:10px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;"></span>
                <span style="font-weight:600;color:#10b981;">Conectado</span>
              </div>
              <p class="text-muted text-sm" style="margin-top:8px;">Sincronización bidireccional activa</p>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Stock Expuesto</span></div>
            <div class="card-body">
              <div class="kpi-value" style="font-size:1.5rem;">${WMS.formatNum(stk.length||0)}</div>
              <div class="kpi-label">referencias disponibles</div>
            </div>
          </div>
        </div>
        <div class="card mb-16">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-file-import"></i> Órdenes desde TMS (${ords.length})</span>
          </div>
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>N° Orden</th><th>Cliente</th><th>Ruta</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>${ords.slice(0,20).map(o => `<tr>
                <td><span class="badge badge-info">${WMS.esc(o.numero||o.id||'-')}</span></td>
                <td>${WMS.esc(o.cliente||'-')}</td>
                <td>${WMS.esc(o.ruta||'-')}</td>
                <td>${WMS.formatDate(o.fecha||o.created_at)||'-'}</td>
                <td><span class="badge badge-warning">${WMS.esc(o.estado||'-')}</span></td>
                <td><button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho.sincTMS(${o.id||0})"><i class="fa-solid fa-sync"></i> Sync</button></td>
              </tr>`).join('') || '<tr><td colspan="6" class="table-empty">Sin órdenes del TMS</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-truck-fast"></i> Despachos Enviados al TMS (${desp.length})</span>
          </div>
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>Planilla</th><th>Placa</th><th>Ruta</th><th>Estado TMS</th><th>Acciones</th></tr></thead>
              <tbody>${desp.slice(0,20).map(d => `<tr>
                <td>${WMS.esc(d.planilla_numero||d.numero||('-'))}</td>
                <td>${WMS.esc(d.placa||'-')}</td>
                <td>${WMS.esc(d.ruta||'-')}</td>
                <td><span class="badge ${d.estado_tms==='Entregado'?'badge-success':'badge-warning'}">${WMS.esc(d.estado_tms||d.estado||'-')}</span></td>
                <td>${d.estado==='Despachado'&&d.estado_tms!=='En Tránsito' ? `<button class="btn btn-sm btn-primary" onclick="WMS_MODULES.despacho.marcarEnTransito(${d.id})"><i class="fa-solid fa-truck-moving"></i> En Tránsito</button>` : ''}</td>
              </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin despachos enviados</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión con TMS</p></div>'); }
  },

  async marcarEnTransito(id) {
    if (!confirm('¿Marcar este despacho como En Tránsito en el TMS?')) return;
    try {
      const r = await API.post('/tms/despacho/' + id + '/transportar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Marcado como En Tránsito'); this.show_tms(); }
    } catch(e) { WMS.toast('error', 'Error sincronizando con TMS'); }
  },

  sincTMS(id) { WMS.toast('info', 'Sincronizando con TMS...'); },

  async gestionarApiKeys() {
    try {
      const r = await API.get('/tms/keys');
      const keys = r.data || r || [];
      WMS.showModal('Gestión de API Keys TMS', `
        <div class="d-flex justify-between align-center" style="margin-bottom:12px;">
          <span class="text-muted text-sm">Las API Keys permiten que el TMS externo acceda a este WMS</span>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.crearApiKey()"><i class="fa-solid fa-plus"></i> Nueva Key</button>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Nombre</th><th>Key (parcial)</th><th>Permisos</th><th>Creada</th><th>Acciones</th></tr></thead>
            <tbody id="api-keys-tbody">${keys.map(k => `<tr>
              <td>${WMS.esc(k.nombre||'-')}</td>
              <td style="font-family:monospace;">${WMS.esc(k.key_partial||k.api_key?.substring(0,12)+'...'||'-')}</td>
              <td>${WMS.esc(k.permisos||'lectura')}</td>
              <td>${WMS.formatDate(k.created_at)||'-'}</td>
              <td><button class="btn btn-sm btn-danger" onclick="WMS_MODULES.despacho.revocarKey(${k.id})"><i class="fa-solid fa-ban"></i> Revocar</button></td>
            </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin API Keys activas</td></tr>'}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
    } catch(e) { WMS.toast('error', 'Error cargando API Keys'); }
  },

  async crearApiKey() {
    const nombre = prompt('Nombre para la nueva API Key (ej: TMS-Externo-1):');
    if (!nombre?.trim()) return;
    try {
      const r = await API.post('/tms/keys', { nombre: nombre.trim(), permisos: 'lectura,escritura' });
      if (r.error) WMS.toast('error', r.message);
      else {
        alert('API Key creada:\n' + (r.api_key || r.data?.api_key || 'Ver en la lista'));
        this.gestionarApiKeys();
      }
    } catch(e) { WMS.toast('error', 'Error creando API Key'); }
  },

  async revocarKey(id) {
    if (!confirm('¿Revocar esta API Key? El TMS perderá acceso inmediatamente.')) return;
    try {
      const r = await API.delete('/tms/keys/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'API Key revocada'); this.gestionarApiKeys(); }
    } catch(e) { WMS.toast('error', 'Error revocando API Key'); }
  },

};
