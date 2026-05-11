/* ============================================================
   WMS Desktop — Módulo RECEPCIÓN & YMS (Enterprise Edition)
   ============================================================ */
WMS_MODULES.recepcion = {
  statusChart: null,
  trendChart: null,

  load(sub) {
    this._sub = sub;
    WMS.renderSidebar('recepcion');
    
    if (!sub) {
      this.show_landing();
      return;
    }

    WMS.setBreadcrumb('recepcion', this.subLabel(sub));
    const fn = {
      odc:        this.show_odc,
      citas:      this.show_citas,
      operativa:  this.show_operativa,
      sin_odc:    this.show_sin_odc,
      devoluciones:this.show_devoluciones,
      dashboard:  this.show_dashboard,
      informe:    this.show_informe,
    };
    (fn[sub]?.bind(this) || this.show_landing.bind(this))();
    this.stopAutoRefresh();
  },

  async show_landing() {
    WMS.setBreadcrumb('recepcion');
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.recepcion.show_landing()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);

    /* KPIs rápidos */
    let kpi = { hoy: 0, pendientes: 0, citas_hoy: 0, pallets: 0 };
    try {
      const r = await API.get('/recepcion/kpis');
      const d = r.data || r;
      kpi.hoy        = d.rec_hoy       || d.hoy       || 0;
      kpi.pendientes = d.pendientes    || 0;
      kpi.citas_hoy  = d.citas_hoy     || 0;
      kpi.pallets    = d.pallets_patio || 0;
    } catch(_) {}

    const modulos = [
      { id:'odc',          icon:'fa-file-invoice',    color:'blue',   bg:'rgba(0,112,242,.09)',   title:'Órdenes de Compra',    desc:'Sincronizar y auditar pedidos pendientes de proveedores.' },
      { id:'citas',        icon:'fa-calendar-check',  color:'green',  bg:'rgba(0,179,0,.09)',     title:'Citas YMS',            desc:'Control de agenda para muelles y entrada de patios.' },
      { id:'operativa',    icon:'fa-truck-ramp-box',  color:'teal',   bg:'rgba(8,145,178,.09)',   title:'Recibo Operativo',     desc:'Ejecución descarga, conteo y verificación de mercancía.' },
      { id:'sin_odc',      icon:'fa-box-open',        color:'emerald',bg:'rgba(5,150,105,.09)',   title:'Recepción sin ODC',    desc:'Ingreso de mercancía sin orden de compra previa. Soporte QR.' },
      { id:'devoluciones', icon:'fa-rotate-left',     color:'amber',  bg:'rgba(232,160,0,.09)',   title:'Devoluciones',         desc:'Gestión de devoluciones a proveedores y novedades.' },
      { id:'dashboard',    icon:'fa-gauge',           color:'purple', bg:'rgba(124,58,237,.09)',  title:'Monitor de Recibo',    desc:'KPIs en tiempo real, ocupación y productividad.' },
      { id:'informe',      icon:'fa-file-excel',      color:'red',    bg:'rgba(224,48,48,.09)',   title:'Informes Detallados',  desc:'Reportes de descarga, lotes y sellos de seguridad.' },
    ];
    const colorHex = { blue:'#0070f2', green:'#00b300', teal:'#0891b2', amber:'#e8a000', purple:'#7c3aed', red:'#e03030', emerald:'#059669' };

    WMS.setContent(`
<div class="pro-dashboard">

  <!-- Mini KPIs -->
  <div class="pro-mini-kpi-row">
    <div class="pro-mini-kpi">
      <div class="pro-mini-kpi-icon" style="background:rgba(0,179,0,.1);color:#00b300"><i class="fa-solid fa-truck-ramp-box"></i></div>
      <div>
        <div class="pro-mini-kpi-value" id="rkpi-hoy">${WMS.formatNum(kpi.hoy)}</div>
        <div class="pro-mini-kpi-label">Recepciones hoy</div>
      </div>
    </div>
    <div class="pro-mini-kpi">
      <div class="pro-mini-kpi-icon" style="background:rgba(0,112,242,.1);color:#0070f2"><i class="fa-solid fa-calendar-check"></i></div>
      <div>
        <div class="pro-mini-kpi-value" id="rkpi-citas">${WMS.formatNum(kpi.citas_hoy)}</div>
        <div class="pro-mini-kpi-label">Citas del día</div>
      </div>
    </div>
    <div class="pro-mini-kpi">
      <div class="pro-mini-kpi-icon" style="background:rgba(232,160,0,.1);color:#e8a000"><i class="fa-solid fa-hourglass-half"></i></div>
      <div>
        <div class="pro-mini-kpi-value" id="rkpi-pend">${WMS.formatNum(kpi.pendientes)}</div>
        <div class="pro-mini-kpi-label">Pendientes de recibo</div>
      </div>
    </div>
    <div class="pro-mini-kpi">
      <div class="pro-mini-kpi-icon" style="background:rgba(8,145,178,.1);color:#0891b2"><i class="fa-solid fa-pallet"></i></div>
      <div>
        <div class="pro-mini-kpi-value" id="rkpi-pallets">${WMS.formatNum(kpi.pallets)}</div>
        <div class="pro-mini-kpi-label">Pallets en patio</div>
      </div>
    </div>
  </div>

  <!-- Título de sección -->
  <div class="pro-section-header" style="margin-bottom:20px">
    <div class="pro-section-title"><span>Módulos de Recepción &amp; YMS</span></div>
    <span style="font-size:.78rem;color:#6b7a99">Selecciona un módulo para continuar</span>
  </div>

  <!-- Grid de módulos -->
  <div class="pro-module-grid">
    ${modulos.map(m => `
      <div class="pro-module-card" style="--mod-color:${colorHex[m.color]};--mod-icon-bg:${m.bg};--mod-icon-color:${colorHex[m.color]}"
           onclick="WMS.nav('recepcion','${m.id}')">
        <div class="pro-module-icon"><i class="fa-solid ${m.icon}"></i></div>
        <div class="pro-module-name">${m.title}</div>
        <div class="pro-module-desc">${m.desc}</div>
        <div class="pro-module-badge">
          <i class="fa-solid fa-arrow-right" style="font-size:.65rem;margin-right:4px"></i>Acceder
        </div>
      </div>`).join('')}
  </div>

</div>`);
  },

  _refreshInterval: null,
  startAutoRefresh() {
    this.stopAutoRefresh();
    this._refreshInterval = setInterval(() => {
      if (WMS.currentModule === 'recepcion' && WMS.currentSubModule === 'operativa')
        this.show_operativa(true);
      else if (WMS.currentModule === 'recepcion' && WMS.currentSubModule === 'dashboard')
        this.show_dashboard(true);
      else this.stopAutoRefresh();
    }, 30000);
  },
  stopAutoRefresh() {
    if (this._refreshInterval) { clearInterval(this._refreshInterval); this._refreshInterval = null; }
  },

  subLabel(s) {
    const m = { odc:'Órdenes de Compra', citas:'Citas YMS', operativa:'Recepción Operativa',
                sin_odc:'Recepción sin ODC', devoluciones:'Devolución Proveedor',
                dashboard:'Dashboard Recepción', informe:'Informe de Recibo' };
    return m[s] || s || 'Panel';
  },

  _dashboardFilters: { odc_id:'', auxiliar_id:'', proveedor_id:'', categoria_id:'' },

  // ══════════════════════════════════════════════════════════════════════
  // LISTADO ODC
  // ══════════════════════════════════════════════════════════════════════
  _odcFilters: { ini: '', fin: '', q: '', estado: '' },

  async show_odc(filters = null) {
    if (filters) Object.assign(this._odcFilters, filters);
    const f = this._odcFilters;
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_odc()"><i class="fa-solid fa-sync"></i> Refrescar</button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.recepcion._exportODC()"><i class="fa-solid fa-file-excel"></i> Exportar</button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.recepcion.nuevaODC()"><i class="fa-solid fa-plus"></i> Nueva ODC</button>`);
    WMS.spinner();
    try {
      const qs = [
        f.ini   ? 'fecha_desde='   + f.ini   : '',
        f.fin   ? 'fecha_hasta='   + f.fin   : '',
        f.estado ? 'estado='       + encodeURIComponent(f.estado) : '',
        'limit=200'
      ].filter(Boolean).join('&');
      const r = await API.get('/odc', qs);
      let items = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []);

      // Filtro local por texto (número ODC, proveedor, producto)
      if (f.q) {
        const fq = f.q.toLowerCase();
        items = items.filter(o =>
          (o.numero_odc||'').toLowerCase().includes(fq) ||
          (o.proveedor?.razon_social||'').toLowerCase().includes(fq) ||
          (o.detalles||[]).some(d => (d.producto?.nombre||'').toLowerCase().includes(fq) ||
                                     (d.producto?.codigo_interno||'').toLowerCase().includes(fq))
        );
      }

      const stChip = s => {
        const map = { Borrador:'status-creada', Confirmada:'status-confirmada',
          'En Proceso':'status-en-proceso', Cerrada:'status-cerrada', Cancelada:'status-cancelada' };
        return `<span class="status-chip ${map[s]||'status-creada'}" style="border-radius:4px;">${WMS.esc(s)}</span>`;
      };
      
      WMS.setContent(`
        <div class="md-container">
          <!-- Master View -->
          <div class="md-master">
            <!-- Filtros ODC -->
            <div class="erp-card" style="padding:14px 18px;margin-bottom:12px; height:auto;">
              <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <div>
                  <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">DESDE</label>
                  <input type="date" id="odc-f-ini" class="form-control" style="width:135px; border-radius:4px;" value="${f.ini||''}">
                </div>
                <div>
                  <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">HASTA</label>
                  <input type="date" id="odc-f-fin" class="form-control" style="width:135px; border-radius:4px;" value="${f.fin||''}">
                </div>
                <div>
                  <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">ESTADO</label>
                  <select id="odc-f-est" class="form-control" style="width:150px; border-radius:4px;">
                    <option value="" ${!f.estado?'selected':''}>Todos</option>
                    ${['Borrador','Confirmada','En Proceso','Cerrada','Cancelada'].map(s => `<option value="${s}" ${f.estado===s?'selected':''}>${s}</option>`).join('')}
                  </select>
                </div>
                <div style="flex:2;min-width:220px;">
                  <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">BUSCAR (ODC / PROVEEDOR / PRODUCTO)</label>
                  <div class="search-bar" style="margin:0; border-radius:4px;"><i class="fa-solid fa-search"></i>
                    <input id="odc-q" placeholder="Número ODC, proveedor, producto..." value="${WMS.esc(f.q||'')}">
                  </div>
                </div>
                <button class="btn btn-primary" style="height:38px;padding:0 18px; border-radius:4px;" onclick="WMS_MODULES.recepcion._applyODCFilters()">
                  <i class="fa-solid fa-filter"></i> Filtrar
                </button>
                <button class="btn btn-secondary" style="height:38px;padding:0 14px; border-radius:4px;" onclick="WMS_MODULES.recepcion._clearODCFilters()">
                  <i class="fa-solid fa-broom"></i>
                </button>
              </div>
            </div>

            <div class="erp-card" style="height: calc(100% - 110px);">
              <div style="padding:10px 16px;font-size:12px;color:#64748b;border-bottom:1px solid #f1f5f9;">
                <i class="fa-solid fa-list"></i> ${items.length} ODC(s) encontradas
              </div>
              <div style="flex:1; overflow-y:auto;">
                <table class="erp-table" id="odc-table">
                  <thead style="position: sticky; top: 0; background: #f8f9fa;">
                    <tr><th>N° ODC</th><th>Proveedor</th><th>Fecha</th><th>Líneas</th><th>Estado</th><th style="width:280px">Acciones</th></tr>
                  </thead>
                  <tbody>${items.map(o=>`<tr class="main-row" id="row-odc-${o.id}">
                    <td><span class="badge badge-info" style="border-radius:4px; font-family:monospace; font-weight:600;">${WMS.esc(o.numero_odc)}</span></td>
                    <td><strong style="color:#1e3a5f;">${WMS.esc(o.proveedor?.razon_social||'-')}</strong></td>
                    <td style="color:#64748b;">${WMS.formatDate(o.fecha)}</td>
                    <td class="text-center fw-700" style="color:#0f172a;">${o.detalles_count||o.detalles?.length||0}</td>
                    <td>${stChip(o.estado)}</td>
                    <td onclick="event.stopPropagation()">
                      <div class="actions" style="gap:6px;">
                        <button class="btn btn-sm btn-primary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.verODC(${o.id})" title="Ver Matriz"><i class="fa-solid fa-table-cells"></i> Matriz</button>
                        ${o.estado==='Confirmada'?`<button class="btn btn-sm btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.asignar_odc(${o.id})" title="Asignar auxiliar"><i class="fa-solid fa-user-plus"></i></button>`:''}
                        ${o.estado==='Borrador'?`<button class="btn btn-sm btn-success" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.confirmarODC(${o.id})" title="Confirmar ODC"><i class="fa-solid fa-check"></i></button>`:''}
                        ${!['Cerrada','Cancelada'].includes(o.estado)?`<button class="btn btn-sm btn-danger" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.cerrarODC(${o.id})" title="Cerrar ODC"><i class="fa-solid fa-lock"></i></button>`:''}
                        <button class="btn btn-sm btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.abrirPDF(${o.id})" title="Imprimir"><i class="fa-solid fa-file-pdf"></i></button>
                        ${o.estado!=='Cerrada'?`<button class="btn btn-sm btn-danger" style="border-radius:4px; opacity:0.8;" onclick="WMS_MODULES.recepcion.deleteODC(${o.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>`:''}
                        ${o.estado==='Cerrada'?`<button class="btn btn-sm btn-warning" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.reabrirODC(${o.id})" title="Reabrir ODC"><i class="fa-solid fa-folder-open"></i></button>`:''}
                      </div>
                    </td>
                  </tr>`).join('')||'<tr><td colspan="6" class="table-empty" style="text-align:center; padding:30px;">No se encontraron órdenes</td></tr>'}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          
          <!-- Side Panel / Drawer -->
          <div id="odc-drawer" class="md-drawer">
            <div class="drawer-header">
              <h3 class="drawer-title"><i class="fa-solid fa-file-invoice" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-odc-title">Nueva ODC</span></h3>
              <button class="drawer-close" onclick="WMS_MODULES.recepcion.closeDrawerODC()"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="drawer-body" id="drawer-odc-content">
              <!-- Content injected -->
            </div>
            <div class="drawer-footer" id="drawer-odc-actions">
              <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion.closeDrawerODC()">Cancelar</button>
              <button id="btn-save-odc-drawer" class="btn btn-primary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion._saveManualODC()">Guardar ODC</button>
            </div>
          </div>
        </div>
      `);
    } catch(e) { console.error(e); WMS.toast('error','Error cargando ODCs'); }
  },
  
  closeDrawerODC() {
    const drawer = document.getElementById('odc-drawer');
    if (drawer) drawer.classList.remove('open');
    document.querySelectorAll('#odc-table tr').forEach(r => r.style.background = '');
  },

  _applyODCFilters() {
    const ini   = document.getElementById('odc-f-ini')?.value  || '';
    const fin   = document.getElementById('odc-f-fin')?.value  || '';
    const est   = document.getElementById('odc-f-est')?.value  || '';
    const q     = document.getElementById('odc-q')?.value      || '';
    this.show_odc({ ini, fin, estado: est, q });
  },

  _clearODCFilters() {
    this._odcFilters = { ini: '', fin: '', q: '', estado: '' };
    this.show_odc();
  },

  _exportODC() {
    const rows = document.querySelectorAll('#odc-table tbody tr');
    let csv = 'N° ODC,Proveedor,Fecha,Líneas,Estado\n';
    rows.forEach(tr => {
      if (tr.style.display === 'none') return;
      const cells = tr.querySelectorAll('td');
      if (cells.length < 5) return;
      csv += [0,1,2,3,4].map(i => '"' + (cells[i]?.textContent?.trim()||'').replace(/"/g,'""') + '"').join(',') + '\n';
    });
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv);
    a.download = 'ordenes_compra_' + WMS.getToday() + '.csv';
    a.click();
  },

  async cerrarODC(id) {
    if (!confirm('¿Cerrar esta ODC? Esta acción registrará el cierre definitivo del documento.')) return;
    try {
      const r = await API.post('/odc/' + id + '/cerrar', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'ODC cerrada correctamente');
      this.show_odc();
    } catch(e) { WMS.toast('error', 'Error al cerrar ODC: ' + e.message); }
  },

  filterODC(q) {
    const rows = document.querySelectorAll('#odc-table tbody tr');
    const fq = q.toLowerCase();
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(fq)?'':'none'; });
  },

  // ══════════════════════════════════════════════════════════════════════
  // MATRIZ FULL-SCREEN — Tipo Excel con expand/colapso por pallet
  // ══════════════════════════════════════════════════════════════════════
  async verODC(id) {
    WMS.spinner();
    WMS.setToolbar('');
    try {
      const r   = await API.get('/odc/'+id);
      const odc = r.data || r;

      // Mapear capturas por producto
      const capMap = {};
      (odc.recepciones||[]).forEach(rec => {
        (rec.detalles||[]).forEach(det => {
          if (!capMap[det.producto_id]) capMap[det.producto_id] = [];
          capMap[det.producto_id].push({
            ...det,
            recepcion_id:  rec.id,
            num_recepcion: rec.numero_recepcion,
            auxiliar:      rec.auxiliar?.nombre || 'N/A',
            fecha_cap:     det.created_at,
          });
        });
      });

      // Mapear devoluciones por producto
      const devMap = {};
      let devolucionId = null;
      try {
        const rDev = await API.get('/devoluciones/odc/'+id);
        const devs = Array.isArray(rDev.data) ? rDev.data : (rDev.data ? [rDev.data] : []);
        devs.forEach(dev => {
          devolucionId = dev.id; // tomar el último ID de devolucion para referencia
          (dev.detalles || dev.devolucion_detalles || []).forEach(det => {
            if (!devMap[det.producto_id]) devMap[det.producto_id] = { total: 0, devId: dev.id, numero: dev.numero_devolucion };
            devMap[det.producto_id].total += parseFloat(det.cantidad || 0);
          });
        });
      } catch(e) { /* sin devoluciones = OK */ }

      const stColor = { Borrador:'#f59e0b', Confirmada:'#2563eb', 'En Proceso':'#7c3aed', Cerrada:'#059669', Cancelada:'#dc2626' };
      const totalSol = (odc.detalles||[]).reduce((a,d)=>a+Number(d.cantidad_solicitada||0),0);
      const totalRec = (odc.detalles||[]).reduce((a,d)=>a+Number(d.cantidad_recibida||0),0);
      const pctGlobal = totalSol>0?Math.min(100,Math.round(totalRec/totalSol*100)):0;

      const html = `
        <div style="display:flex;flex-direction:column;height:calc(100vh - 120px);overflow:hidden;">

          <!-- KPI banner -->
          <div style="display:flex;align-items:center;gap:24px;padding:12px 20px;background:#fff;border-bottom:2px solid #e2e8f0;flex-shrink:0;">
            <div>
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase">Orden de Compra</div>
              <div style="font-size:20px;font-weight:900;color:#1e3a5f">${odc.numero_odc}</div>
            </div>
            <div style="border-left:2px solid #e2e8f0;padding-left:20px;">
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase">Proveedor</div>
              <div style="font-size:14px;font-weight:700">${WMS.esc(odc.proveedor?.razon_social||'N/A')}</div>
            </div>
            <div style="border-left:2px solid #e2e8f0;padding-left:20px;">
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase">Estado</div>
              <span style="font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;color:#fff;background:${stColor[odc.estado]||'#64748b'}">${odc.estado}</span>
            </div>
            <div style="border-left:2px solid #e2e8f0;padding-left:20px;">
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase">Avance Global</div>
              <div style="font-size:14px;font-weight:900;color:${pctGlobal>=100?'#059669':'#1e3a5f'}">${pctGlobal}% <span style="font-size:11px;font-weight:400;color:#64748b">(${WMS.formatNum(totalRec)} / ${WMS.formatNum(totalSol)})</span></div>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;">
              <button class="btn btn-sm btn-info-soft" onclick="WMS_MODULES.recepcion.abrirPDF(${id})"><i class="fa-solid fa-file-pdf"></i> PDF</button>
              <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.recepcion.verODC(${id})"><i class="fa-solid fa-sync"></i></button>
              ${odc.estado==='En Proceso'?`<button class="btn btn-sm btn-warning" onclick="WMS_MODULES.recepcion._agregarLinea(${id})"><i class="fa-solid fa-plus"></i> Agregar Línea</button>`:''}
              <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.recepcion.show_odc()"><i class="fa-solid fa-arrow-left"></i> Volver</button>
              ${odc.estado==='Cerrada'?`<button class="btn btn-sm btn-warning fw-700" onclick="WMS_MODULES.recepcion.reabrirODC(${id})"><i class="fa-solid fa-folder-open"></i> REABRIR ODC</button>`:''}
              ${odc.estado!=='Cerrada'?`<button class="btn btn-sm btn-success fw-700" onclick="WMS_MODULES.recepcion.aprobarODCTodo(${id})"><i class="fa-solid fa-check-double"></i> APROBAR TODO</button>`:''}
            </div>
          </div>

          <!-- Tabla Excel scrollable -->
          <div style="flex:1;overflow:auto;padding:0 12px 12px;">
            <table id="matrix-table-${id}" style="width:100%;border-collapse:collapse;min-width:900px;">
              <thead style="position:sticky;top:0;z-index:10;">
                <tr style="background:#1e3a5f;color:#fff;">
                  <th style="width:36px;padding:8px;text-align:center"></th>
                  <th style="padding:8px 12px;text-align:left">PRODUCTO / SKU</th>
                  <th style="padding:8px;text-align:center;width:110px">SOLICITADO</th>
                  <th style="padding:8px;text-align:center;width:110px">RECIBIDO</th>
                  <th style="padding:8px;text-align:center;width:100px">PENDIENTE</th>
                  <th style="padding:8px;text-align:center;width:90px">% AVANCE</th>
                  <th style="padding:8px;text-align:center;width:60px">PALLETS</th>
                  <th style="padding:8px;text-align:center;width:80px;background:#dc2626;">DEVOL.</th>
                  <th style="padding:8px;text-align:center;width:260px">ACCIONES LÍNEA</th>
                </tr>
              </thead>
              <tbody id="matrix-body-${id}">
                ${(odc.detalles||[]).map((d,idx) => {
                  const pct = d.cantidad_solicitada>0 ? Math.min(100,Math.round(d.cantidad_recibida/d.cantidad_solicitada*100)) : 0;
                  const pend = d.cantidad_solicitada - d.cantidad_recibida;
                  const caps = capMap[d.producto_id] || [];
                  const isApp = !!d.aprobado_admin;
                  const rowBg = idx%2===0 ? '#fff' : '#f8fafc';
                  const barColor = pct>=100 ? '#059669' : (pct>50 ? '#2563eb' : '#f59e0b');

                  return `
                  <tr id="line-row-${d.id}" style="background:${isApp?'#f0fdf4':rowBg};border-bottom:1px solid #e2e8f0;">
                    <td style="text-align:center;padding:4px;">
                      <button onclick="WMS_MODULES.recepcion._toggleRow('${d.id}')"
                        style="background:none;border:none;cursor:pointer;width:26px;height:26px;border-radius:4px;color:#1e3a5f;background:#e8f0fe;"
                        title="${caps.length} pallet(s)">
                        <i class="fa-solid fa-chevron-right" id="icon-${d.id}"></i>
                      </button>
                    </td>
                    <td style="padding:8px 12px;">
                      <div style="font-weight:800;color:#1e3a5f;font-size:13px;">${WMS.esc(d.producto?.nombre)}</div>
                      <div style="font-size:11px;color:#64748b;">SKU: ${WMS.esc(d.producto?.codigo_interno||'-')} ${d.producto?.referencia?'| Ref: '+WMS.esc(d.producto.referencia):''}</div>
                    </td>
                    <td style="text-align:center;padding:6px;">
                      <input type="number" id="sol-${d.id}" value="${d.cantidad_solicitada}" min="0"
                        ${isApp?'disabled':''}
                        style="width:80px;text-align:center;padding:4px;border:1px solid ${isApp?'transparent':'#cbd5e1'};border-radius:4px;font-weight:700;background:${isApp?'transparent':'#fff'};">
                    </td>
                    <td style="text-align:center;padding:6px;">
                      <input type="number" id="rec-${d.id}" value="${d.cantidad_recibida}" min="0"
                        ${isApp?'disabled':''}
                        style="width:80px;text-align:center;padding:4px;border:1px solid ${isApp?'transparent':'#bfdbfe'};border-radius:4px;font-weight:700;color:#2563eb;background:${isApp?'transparent':'#eff6ff'};">
                    </td>
                    <td style="text-align:center;padding:6px;font-weight:700;color:${pend>0?'#d97706':'#059669'};">
                      ${WMS.formatNum(pend)}
                    </td>
                    <td style="text-align:center;padding:6px;">
                      <div style="background:#e2e8f0;border-radius:99px;height:8px;margin-bottom:4px;overflow:hidden;">
                        <div style="background:${barColor};height:100%;width:${pct}%;border-radius:99px;transition:width .4s;"></div>
                      </div>
                      <div style="font-size:11px;font-weight:700;color:${barColor};">${pct}%</div>
                    </td>
                     <td style="text-align:center;padding:6px;">
                       <span style="font-size:13px;font-weight:700;color:#1e3a5f;">${caps.length}</span>
                     </td>
                     <td style="text-align:center;padding:6px;">
                       ${devMap[d.producto_id]
                         ? `<button onclick="WMS_MODULES.recepcion.verFotosDevolucion(${devMap[d.producto_id].devId})" title="${devMap[d.producto_id].numero}" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;cursor:pointer;"><i class='fa-solid fa-rotate-left'></i> ${devMap[d.producto_id].total}</button>`
                         : '<span style="color:#e2e8f0;">—</span>'}
                     </td>
                     <td style="text-align:center;padding:6px;">
                       <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                         ${isApp
                           ? '<span style="background:#dcfce7;color:#059669;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;"><i class="fa-solid fa-check"></i> APROBADA</span>'
                           : `<button class="btn btn-xs btn-primary" onclick="WMS_MODULES.recepcion._guardarLinea(${id},${d.id})" title="Guardar cambios"><i class="fa-solid fa-save"></i></button>
                              <button class="btn btn-xs btn-success" onclick="WMS_MODULES.recepcion._aprobarLinea(${id},${d.id})" title="Aprobar linea"><i class="fa-solid fa-check"></i> Aprobar</button>
                              <button class="btn btn-xs btn-warning-soft" onclick="WMS_MODULES.recepcion._novedadLinea(${id},${d.id},'${WMS.esc(d.producto?.nombre)}')" title="Registrar novedad"><i class="fa-solid fa-exclamation-triangle"></i></button>
                              <button class="btn btn-xs btn-danger-soft" onclick="WMS_MODULES.recepcion._eliminarLinea(${id},${d.id})" title="Eliminar linea"><i class="fa-solid fa-trash"></i></button>`}
                       </div>
                     </td>
                   </tr>
                  <!-- FILA EXPANDIBLE DE PALLETS -->
                  <tr id="pallet-row-${d.id}" style="display:none;">
                    <td colspan="8" style="padding:0;background:#f1f5f9;border-bottom:2px solid #cbd5e1;">
                      <div style="padding:10px 20px 10px 54px;" id="pallet-content-${d.id}">
                        ${WMS_MODULES.recepcion._buildPalletTable(caps, d.id, id)}
                      </div>
                    </td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>`;

      WMS.setContent(html);

    } catch(e) { console.error(e); WMS.toast('error','Error al cargar matriz ODC'); }
  },

  _buildPalletTable(caps, detId, odcId) {
    if (!caps.length) return '<div style="padding:8px;font-size:12px;color:#64748b;font-style:italic">Sin pallets/ingresos registrados para esta línea.</div>';
    return `
      <table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead>
          <tr style="background:#2e75b6;color:#fff;">
            <th style="padding:6px 8px"># Recepción</th>
            <th style="padding:6px 8px;text-align:center"># Pallet</th>
            <th style="padding:6px 8px">H. Captura</th>
            <th style="padding:6px 8px;text-align:center">Cantidad</th>
            <th style="padding:6px 8px">Lote</th>
            <th style="padding:6px 8px">Vencimiento</th>
            <th style="padding:6px 8px">Auxiliar</th>
            <th style="padding:6px 8px;text-align:center">Estado</th>
            <th style="padding:6px 8px;text-align:center">Acciones Pallet</th>
          </tr>
        </thead>
        <tbody>
          ${caps.map((c,ci) => `
          <tr id="pallet-tr-${c.id}" style="background:${ci%2===0?'#fff':'#f8fafc'};border-bottom:1px solid #e2e8f0;">
            <td style="padding:5px 8px;font-weight:700;color:#1e3a5f">${WMS.esc(c.num_recepcion||c.recepcion_id)}</td>
            <td style="padding:5px 8px;text-align:center;"><span class="badge badge-info">${c.numero_pallet || '-'}</span></td>
            <td style="padding:5px 8px;color:#64748b">${WMS.formatDateTime(c.fecha_cap)}</td>
            <td style="padding:5px 8px;text-align:center;font-weight:800;font-size:14px;">
              <span id="pallet-qty-span-${c.id}">${WMS.formatNum(c.cantidad_recibida)}</span>
              ${c.cantidad_cajas>0&&c.cajas_por_unidad>1?`<br><span style="font-size:10px;color:#64748b;font-weight:400;">${c.cantidad_cajas} caja${c.cantidad_cajas!==1?'s':''} × ${c.cajas_por_unidad}</span>`:''}
              <input id="pallet-qty-${c.id}" type="number" value="${c.cantidad_recibida}" min="0"
                style="display:none;width:70px;text-align:center;padding:3px;border:1px solid #93c5fd;border-radius:4px;"
                onblur="WMS_MODULES.recepcion._savePalletQty(${c.id},${odcId})">
            </td>
            <td style="padding:5px 8px;"><span style="background:#e2e8f0;padding:2px 8px;border-radius:99px;font-size:10px;">${WMS.esc(c.lote||'N/A')}</span></td>
            <td style="padding:5px 8px;color:${c.fecha_vencimiento?'':'#94a3b8'}">${c.fecha_vencimiento?WMS.formatDate(c.fecha_vencimiento):'-'}</td>
            <td style="padding:5px 8px">${WMS.esc(c.auxiliar)}</td>
            <td style="padding:5px 8px;text-align:center;">
              ${c.aprobado_admin
                ? '<span style="background:#dcfce7;color:#059669;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">✓ Disponible</span>'
                : '<span style="background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;">⏳ En Patio</span>'}
            </td>
            <td style="padding:5px 8px;text-align:center;">
              <div style="display:flex;gap:4px;justify-content:center;">
                ${!c.aprobado_admin ? `
                  <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.recepcion._editarPalletQty(${c.id})" title="Editar cantidad"><i class="fa-solid fa-pencil"></i></button>
                ` : ''}
                <button class="btn btn-xs btn-danger-soft" onclick="WMS_MODULES.recepcion._eliminarPallet(${odcId},${c.id},${detId})" title="Eliminar pallet"><i class="fa-solid fa-trash"></i></button>
              </div>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>`;
  },

  _toggleRow(detId) {
    const row  = document.getElementById('pallet-row-'+detId);
    const icon = document.getElementById('icon-'+detId);
    if (!row) return;
    const open = row.style.display !== 'none';
    row.style.display = open ? 'none' : 'table-row';
    icon.className = open ? 'fa-solid fa-chevron-right' : 'fa-solid fa-chevron-down';
  },

  // ── Guardar cambios a línea (cantidades) ─────────────────────────────────
  async _guardarLinea(odcId, detId) {
    const qSol = parseInt(document.getElementById('sol-'+detId)?.value)||0;
    const qRec = parseInt(document.getElementById('rec-'+detId)?.value)||0;
    try {
      await API.put('/odc/'+odcId, { detalles:[{id:detId, cantidad:qSol, cantidad_recibida:qRec}] });
      WMS.toast('success','Línea actualizada correctamente');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error al guardar: '+e.message); }
  },

  // ── Aprobar línea completa ────────────────────────────────────────────────
  async _aprobarLinea(odcId, detId) {
    if (!confirm('¿Aprobar esta línea? Moverá todas sus capturas a "Disponible" para ubicar.')) return;
    try {
      const r = await API.post('/odc/detalle/'+detId+'/aprobar', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success','Línea aprobada');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  async aprobarODCTodo(id) {
    if (!confirm('¿Aprobar TODA la Orden de Compra? Esto marcará todas las líneas como completadas y disponibles para ubicar.')) return;
    try {
      const r = await API.post('/odc/'+id+'/aprobar-todo', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success','Orden de compra aprobada exitosamente');
      this.verODC(id);
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  // ── Registrar novedad / diferencia en línea ───────────────────────────────
  _novedadLinea(odcId, detId, nombre) {
    WMS.showModal('Registrar Novedad — '+nombre, `
      <div style="padding:16px;">
        <div class="alert alert-warning" style="margin-bottom:12px;">Registre la diferencia entre lo solicitado y lo recibido.</div>
        <div class="form-group">
          <label class="fw-700">Motivo de la novedad:</label>
          <select id="nov-motivo" class="form-control mt-8">
            <option value="Faltante">Faltante de mercancía</option>
            <option value="Dañado">Producto dañado / en mal estado</option>
            <option value="VencimientoProximo">Vencimiento próximo inaceptable</option>
            <option value="EANNoCoincide">EAN no coincide con ODC</option>
            <option value="CantidadExcede">Cantidad supera lo solicitado</option>
            <option value="Otro">Otro</option>
          </select>
        </div>
        <div class="form-group mt-12">
          <label class="fw-700">Observación:</label>
          <textarea id="nov-obs" class="form-control mt-8" rows="3" placeholder="Describa detalladamente la novedad..."></textarea>
        </div>
        <div class="form-group mt-12">
          <label class="fw-700">Cantidad con novedad:</label>
          <input type="number" id="nov-qty" class="form-control mt-8" min="0" value="0" placeholder="Unidades afectadas">
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-warning fw-700" onclick="WMS_MODULES.recepcion._guardarNovedad(${odcId},${detId})">Guardar Novedad</button>`
    );
  },

  async _guardarNovedad(odcId, detId) {
    const motivo = document.getElementById('nov-motivo')?.value;
    const obs    = document.getElementById('nov-obs')?.value?.trim();
    const qty    = parseInt(document.getElementById('nov-qty')?.value)||0;
    try {
      // Actualizamos la línea con la novedad anotada
      await API.put('/odc/'+odcId, {
        detalles:[{ id:detId, novedad_motivo:motivo, novedad_observacion:obs, cantidad_novedad:qty }]
      });
      WMS.toast('success','Novedad registrada');
      WMS.closeModal('generic-modal');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  // ── Eliminar línea de ODC ─────────────────────────────────────────────────
  async _eliminarLinea(odcId, detId) {
    if (!confirm('¿Eliminar esta línea de la ODC? Esta acción no se puede deshacer.')) return;
    try {
      // Enviamos el PUT con esa línea excluida del array
      // La lógica de updateOrdenCompra elimina las líneas no incluidas en la lista
      const r = await API.get('/odc/'+odcId);
      const odc = r.data || r;
      const detallesFiltrados = (odc.detalles||[])
        .filter(d => d.id !== detId)
        .map(d => ({ id:d.id, cantidad:d.cantidad_solicitada, cantidad_recibida:d.cantidad_recibida }));
      await API.put('/odc/'+odcId, { detalles: detallesFiltrados });
      WMS.toast('success','Línea eliminada');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error al eliminar: '+e.message); }
  },

  // ── Agregar nueva línea a ODC (disponible en "En Proceso") ────────────────
  async _agregarLinea(odcId) {
    WMS.spinner();
    try {
      const odcR = await API.get('/odc/'+odcId);
      const odc = odcR.data||odcR;

      // Filtrar productos ya en la ODC para evitar duplicados
      const existentes = new Set((odc.detalles||[]).map(d=>d.producto_id));
      const disponibles = prods.filter(p=>!existentes.has(p.id));

      WMS.showModal('Agregar Nueva Línea — '+odc.numero_odc, `
        <div style="padding:16px;">
          <div class="alert alert-info" style="margin-bottom:12px;"><i class="fa-solid fa-info-circle"></i> Busque y seleccione un producto para agregar a esta orden de compra.</div>
          <div class="form-group">
            <label class="fw-700">Producto / EAN *</label>
            <input id="new-product-ac" class="form-control" placeholder="Escriba EAN o nombre...">
            <input type="hidden" id="new-product-id">
          </div>
          <div class="form-group mt-12">
            <label class="fw-700">Cantidad Solicitada *</label>
            <input type="number" id="new-qty" class="form-control" min="1" value="1" placeholder="Cantidad">
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary fw-900" onclick="WMS_MODULES.recepcion._guardarLineaNueva(${odcId})"><i class="fa-solid fa-plus"></i> Agregar Línea</button>`
      );

      WMS.initProductAutocomplete(document.getElementById('new-product-ac'), (p) => {
        document.getElementById('new-product-id').value = p.id;
      });
    } catch(e) { WMS.toast('error','Error preparando agregar línea'); }
  },

  async _guardarLineaNueva(odcId) {
    const productoId = parseInt(document.getElementById('new-product-id')?.value||'0');
    const cantidad = parseInt(document.getElementById('new-qty')?.value||'0');

    if (!productoId || cantidad <= 0) {
      return WMS.toast('warning','Debe seleccionar un producto de la lista y una cantidad válida');
    }

    try {
      const r = await API.get('/odc/'+odcId);
      const odc = r.data || r;
      
      // Crear nuevo detalle
      const nuevoDetalle = {
        producto_id: productoId,
        cantidad_solicitada: cantidad,
        cantidad_recibida: 0,
        aprobado_admin: false
      };

      // Mantener detalles existentes y agregar el nuevo
      const detallesActualizados = [
        ...(odc.detalles||[]).map(d => ({ 
          id: d.id, 
          cantidad: d.cantidad_solicitada, 
          cantidad_recibida: d.cantidad_recibida 
        })),
        nuevoDetalle
      ];

      await API.put('/odc/'+odcId, { detalles: detallesActualizados });
      WMS.toast('success','Línea agregada correctamente');
      WMS.closeModal('generic-modal');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error al agregar línea: '+e.message); }
  },

  // ── Aprobar pallet individual ─────────────────────────────────────────────
  async _aprobarPallet(odcId, captureId) {
    if (!confirm('¿Aprobar este pallet? Pasará a "Disponible" para almacenamiento.')) return;
    try {
      const r = await API.post('/recepciones/detalle/'+captureId+'/aprobar', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success','Pallet aprobado');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  // ── Editar cantidad de pallet inline ─────────────────────────────────────
  _editarPalletQty(captureId) {
    const span = document.getElementById('pallet-qty-span-'+captureId);
    const inp  = document.getElementById('pallet-qty-'+captureId);
    if (!span || !inp) return;
    span.style.display = 'none';
    inp.style.display  = 'inline-block';
    inp.focus();
  },

  async _savePalletQty(captureId, odcId) {
    const inp = document.getElementById('pallet-qty-'+captureId);
    const qty = parseInt(inp?.value||'0');
    try {
      await API.put('/recepcion-detalle/'+captureId, { cantidad_recibida: qty });
      WMS.toast('success','Cantidad actualizada');
      this.verODC(odcId);
    } catch(e) {
      WMS.toast('warning','No se pudo actualizar la cantidad directamente (actualice la línea completa)');
      const span = document.getElementById('pallet-qty-span-'+captureId);
      if (span) { span.style.display = 'inline'; inp.style.display = 'none'; }
    }
  },

  // ── Eliminar pallet/captura ───────────────────────────────────────────────
  async _eliminarPallet(odcId, captureId, detId) {
    if (!confirm('¿Eliminar este pallet/ingreso? Se revertirá la cantidad en la línea.')) return;
    try {
      await API.delete('/recepciones/detalle/'+captureId);
      WMS.toast('success','Pallet eliminado');
      this.verODC(odcId);
    } catch(e) { WMS.toast('error','Error al eliminar pallet: '+e.message); }
  },

  // ── Aprobar ODC completa ──────────────────────────────────────────────────
  async aprobarODCTodo(id) {
    if (!confirm('¿Cerrar y APROBAR la recepción total? Moverá todo el stock a Disponible.')) return;
    try {
      const r = await API.post('/odc/'+id+'/aprobar-todo', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success','ODC cerrada y stock disponible para ubicar');
      this.show_odc();
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  // ── PDF / Imprimir ────────────────────────────────────────────────────────
  abrirPDF(id) {
    const token = localStorage.getItem('wms_token') || '';
    const url   = `${API.BASE_URL}/odc/${id}/imprimir?token=${token}`;
    window.open(url, '_blank', 'width=1100,height=820');
  },

  // ══════════════════════════════════════════════════════════════════════
  // LIFECYCLE ODC
  // ══════════════════════════════════════════════════════════════════════
  async nuevaODC() {
    this.closeDrawerODC();
    const provR = await API.get('/param/proveedores');
    const provs = provR.data||provR||[];

    const drawer = document.getElementById('odc-drawer');
    const content = document.getElementById('drawer-odc-content');
    const btnSave = document.getElementById('btn-save-odc-drawer');
    
    if (!drawer || !content) return;

    content.innerHTML = `
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div style="background:#eff6ff; border-left:4px solid #3b82f6; padding:12px; border-radius:0 4px 4px 0; margin-bottom:8px;">
           <span style="font-size:0.85rem; color:#1e3a5f;">La ODC se creará directamente en estado <b>Confirmada</b> para iniciar el recibo inmediato.</span>
        </div>
        <div class="form-group">
          <label class="form-label">Proveedor <span class="required" style="color:#ef4444;">*</span></label>
          <select id="mo-prov" class="form-control">
            <option value="">Seleccione...</option>
            ${provs.map(p=>`<option value="${p.id}">${WMS.esc(p.razon_social||p.nombre)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Observaciones</label>
          <textarea id="mo-obs" class="form-control" rows="2" placeholder="Nota o referencia adicional"></textarea>
        </div>
        <div style="border-top:1px solid #e2e8f0; margin-top:8px; padding-top:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-weight:700; color:#0f172a; font-size:0.95rem;"><i class="fa-solid fa-list-ul" style="color:#64748b; margin-right:6px;"></i> Ítems de la Orden</span>
            <button class="btn btn-sm btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.recepcion._addManualItem()"><i class="fa-solid fa-plus"></i> Agregar Item</button>
          </div>
          <div style="border:1px solid #cbd5e1; border-radius:4px; overflow:hidden;">
            <table class="erp-table" style="font-size:0.85rem;">
              <thead style="background:#f1f5f9;">
                <tr>
                  <th style="padding:8px 10px;">Producto</th>
                  <th style="width:80px; padding:8px 10px; text-align:center;">Cant.</th>
                  <th style="width:40px; padding:8px 10px;"></th>
                </tr>
              </thead>
              <tbody id="mo-items"></tbody>
            </table>
          </div>
        </div>
      </div>
    `;

    btnSave.innerHTML = '<i class="fa-solid fa-save"></i> Crear ODC Confirmada';
    btnSave.onclick = () => WMS_MODULES.recepcion._saveManualODC();
    
    drawer.classList.add('open');
    this._tempProds = window.prods || [];
    this._addManualItem();
  },

  _tempProds: [],
  _addManualItem() {
    const tbody = document.getElementById('mo-items');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="padding:6px;">
        <input class="form-control mo-p-ac" style="font-size:0.85rem; padding:4px 8px; border-radius:4px;" placeholder="Buscar producto...">
        <input type="hidden" class="mo-pid">
      </td>
      <td style="padding:6px; text-align:center;">
        <input type="number" class="form-control mo-qty" value="1" min="1" style="font-size:0.85rem; padding:4px; border-radius:4px; text-align:center;">
      </td>
      <td style="padding:6px; text-align:center;">
        <button class="btn btn-sm btn-danger" style="border-radius:4px; padding:2px 6px;" onclick="this.closest('tr').remove()"><i class="fa-solid fa-times"></i></button>
      </td>
    `;
    tbody.appendChild(tr);
    
    // Iniciar autocomplete en el nuevo input
    WMS.initProductAutocomplete(tr.querySelector('.mo-p-ac'), (p) => {
      tr.querySelector('.mo-pid').value = p.id;
    });
  },

  async _saveManualODC() {
    const provId = document.getElementById('mo-prov')?.value;
    const obs    = document.getElementById('mo-obs')?.value;
    const rows   = document.querySelectorAll('#mo-items tr');
    const items  = [];
    rows.forEach(r => {
      const pid = r.querySelector('.mo-pid')?.value;
      const qty = parseInt(r.querySelector('.mo-qty')?.value)||0;
      if (pid && qty > 0) items.push({ producto_id: pid, cantidad: qty });
    });
    if (!provId) return WMS.toast('warning','Seleccione el proveedor');
    if (!items.length) return WMS.toast('warning','Agregue al menos un producto');

    const btn = document.getElementById('btn-save-odc-drawer');
    if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spinner sm"></div> Procesando...'; }

    try {
      await API.post('/odc', { proveedor_id: provId, observaciones: obs, detalles: items });
      WMS.toast('success','Orden de Compra creada y confirmada');
      this.closeDrawerODC();
      this.show_odc();
    } catch(e) { 
      WMS.toast('error','Error al crear ODC: ' + e.message); 
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Crear ODC Confirmada'; }
    }
  },

  async confirmarODC(id) {
    if (!confirm('¿Confirmar esta ODC para iniciar recepción?')) return;
    try { await API.post(`/odc/${id}/confirmar`); WMS.toast('success','ODC Confirmada'); this.show_odc(); }
    catch(e) { WMS.toast('error','Error'); }
  },

  async deleteODC(id) {
    if (!confirm('¿Eliminar esta Orden de Compra? Se perderán todos sus datos.')) return;
    try { await API.delete(`/odc/${id}`); WMS.toast('success','Eliminada'); this.show_odc(); }
    catch(e) { WMS.toast('error','No se pudo eliminar'); }
  },

  async asignar_odc(id) {
    WMS.spinner();
    try {
      const [rOdc, rPersonal] = await Promise.all([API.get('/odc/'+id), API.get('/param/personal','rol=Auxiliar')]);
      const odc = rOdc.data||rOdc;
      const per = rPersonal.data||rPersonal||[];
      
      const auxActuales = new Set((odc.auxiliares||[]).map(a => a.id));

      WMS.showModal('Asignar Equipo Operativo — '+odc.numero_odc, `
        <div class="p-20">
          <div class="alert alert-info" style="margin-bottom:20px; border-left:4px solid var(--primary);">
            <i class="fa-solid fa-people-group"></i> Seleccione los auxiliares que realizarán la recepción de esta orden.
          </div>
          <div style="display:flex; flex-direction:column; gap:8px; max-height:450px; overflow-y:auto; padding-right:8px;" id="aux-list-container">
            ${per.map(p => `
              <label style="display:flex; align-items:center; gap:12px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#eff6ff'; this.style.borderColor='#bfdbfe';" onmouseout="this.style.background='#f8fafc'; this.style.borderColor='#e2e8f0';">
                <input type="checkbox" name="aux-assign" value="${p.id}" ${auxActuales.has(p.id) ? 'checked' : ''} style="width:18px; height:18px; cursor:pointer;">
                <div style="display:flex; flex-direction:column;">
                  <span style="font-weight:700; color:#1e3a5f; font-size:0.9rem;">${WMS.esc(p.nombre)}</span>
                  <span style="font-size:0.75rem; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">${WMS.esc(p.rol || 'Auxiliar')}</span>
                </div>
              </label>
            `).join('') || '<div class="text-center p-20 text-muted">No se encontraron auxiliares activos</div>'}
          </div>
        </div>`,
        `<button class="btn btn-ghost" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary fw-900" onclick="WMS_MODULES.recepcion.saveAsignacion(${id})"><i class="fa-solid fa-save"></i> Guardar Asignación</button>`
      );
    } catch(e) { console.error(e); WMS.toast('error','Error al cargar personal'); }
  },

  async saveAsignacion(id) {
    const checks = document.querySelectorAll('input[name="aux-assign"]:checked');
    const ids = Array.from(checks).map(c=>parseInt(c.value));
    if (!ids.length) return WMS.toast('warning','Seleccione al menos uno');
    try {
      await API.post(`/odc/${id}/asignar`, { auxiliar_ids:ids });
      WMS.toast('success','Asignación guardada');
      WMS.closeModal('generic-modal');
      this.show_odc();
    } catch(e) { WMS.toast('error','Error al asignar'); }
  },

  // ─────────────────────────────────────────────────────────────────
  // INFORME DE RECIBO — Matriz tipo Excel con filtros y exportación
  // ─────────────────────────────────────────────────────────────────
  async show_informe() {
    WMS.setToolbar(`
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.recepcion.exportarInforme()"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_informe()"><i class="fa-solid fa-sync"></i> Actualizar</button>
    `);
    WMS.spinner();
    try {
      const [recepcR] = await Promise.all([
        API.get('/reportes/recepciones','limit=1000')
      ]);
      const recepciones = recepcR.data||recepcR||[];
      
      // Expandir filas: una fila por cada detalle de recepción
      const items = [];
      recepciones.forEach(rec => {
        (rec.detalles||[]).forEach(det => {
          items.push({
            numero_odc: rec.odc_numero || '-',
            numero_recepcion: rec.numero_recepcion,
            numero_pallet: det.numero_pallet || '-',
            fecha: det.created_at,
            producto_nombre: det.producto?.nombre || '-',
            cantidad_recibida: det.cantidad_recibida || 0,
            lote: det.lote || 'N/A',
            fecha_vencimiento: det.fecha_vencimiento || '',
            estado: rec.estado || 'Pendiente',
            observaciones: rec.observaciones || '',
            odc_id: rec.odc_id,
            proveedor: rec.proveedor || '—'
          });
        });
      });

      WMS.setContent(`
        <div style="padding:20px;overflow:auto;height:calc(100vh - 120px);">
          <!-- Filtros -->
          <div style="background:#fff;border-radius:4px;padding:16px;border:1px solid #e2e8f0;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
              <div>
                <label style="font-size:12px;font-weight:700;color:#1e3a5f;display:block;margin-bottom:6px;">N° ODC</label>
                <input type="text" id="f-odc" class="form-control form-control-sm" placeholder="Ej: ODC-202603001" oninput="WMS_MODULES.recepcion.filterInforme()">
              </div>
              <div>
                <label style="font-size:12px;font-weight:700;color:#1e3a5f;display:block;margin-bottom:6px;">Producto</label>
                <input type="text" id="f-prod" class="form-control form-control-sm" placeholder="Nombre o código" oninput="WMS_MODULES.recepcion.filterInforme()">
              </div>
              <div>
                <label style="font-size:12px;font-weight:700;color:#1e3a5f;display:block;margin-bottom:6px;">Desde</label>
                <input type="date" id="f-desde" class="form-control form-control-sm" onchange="WMS_MODULES.recepcion.filterInforme()">
              </div>
              <div>
                <label style="font-size:12px;font-weight:700;color:#1e3a5f;display:block;margin-bottom:6px;">Hasta</label>
                <input type="date" id="f-hasta" class="form-control form-control-sm" onchange="WMS_MODULES.recepcion.filterInforme()">
              </div>
            </div>
          </div>

          <!-- Tabla -->
          <div style="background:#fff;border-radius:4px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
            <div style="overflow-x:auto;">
              <table class="erp-table" id="informe-table" style="width:100%;border-collapse:collapse;">
                <thead style="background:#f8fafc;position:sticky;top:0;z-index:10;">
                  <tr>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">N° Recepción</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;"># Pallet</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">N° ODC</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Fecha</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Producto</th>
                    <th style="padding:12px;text-align:center;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Qty Recibida</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Lote</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">F. Vencimiento</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Estado</th>
                    <th style="padding:12px;text-align:left;color:#64748b;font-weight:700;border-bottom:2px solid #e2e8f0;">Observaciones</th>
                  </tr>
                </thead>
                <tbody id="informe-tbody">
                  ${items.length === 0 ? '<tr><td colspan="10" style="text-align:center;padding:30px;color:#94a3b8;">Sin registros</td></tr>' : items.map((r,i)=>`
                    <tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                      <td style="padding:12px;">${WMS.esc(r.numero_recepcion||'')}</td>
                      <td style="padding:12px;"><span class="badge badge-info">${WMS.esc(r.numero_pallet||'-')}</span></td>
                      <td style="padding:12px;"><strong>${WMS.esc(r.numero_odc||'')}</strong></td>
                      <td style="padding:12px;font-size:12px;color:#64748b;">${WMS.formatDateTime(r.fecha||'')}</td>
                      <td style="padding:12px;">${WMS.esc(r.producto_nombre||'')}</td>
                      <td style="padding:12px;text-align:center;font-weight:700;">${r.cantidad_recibida||0}</td>
                      <td style="padding:12px;font-size:12px;">${r.lote||'N/A'}</td>
                      <td style="padding:12px;font-size:12px;">${WMS.formatDate(r.fecha_vencimiento||'')}</td>
                      <td style="padding:12px;"><span class="badge ${r.estado==='Cerrada'?'badge-success':'badge-warning'}">${r.estado||'Pendiente'}</span></td>
                      <td style="padding:12px;font-size:12px;color:#64748b;">${WMS.esc(r.observaciones||'—')}</td>
                    </tr>`).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch(e) { console.error(e); WMS.toast('error','Error cargando informe'); }
  },

  filterInforme() {
    const tbody = document.getElementById('informe-tbody');
    if (!tbody) return;
    const f_odc = (document.getElementById('f-odc')?.value||'').toUpperCase();
    const f_prod = (document.getElementById('f-prod')?.value||'').toUpperCase();
    const f_desde = document.getElementById('f-desde')?.value||'';
    const f_hasta = document.getElementById('f-hasta')?.value||'';

    Array.from(tbody.querySelectorAll('tr')).forEach(tr=>{
      const cells = tr.querySelectorAll('td');
      const odc = (cells[1]?.textContent||'').toUpperCase();
      const fecha = cells[2]?.textContent||'';
      const prod = (cells[3]?.textContent||'').toUpperCase();

      const match = (!f_odc || odc.includes(f_odc))
                 && (!f_prod || prod.includes(f_prod))
                 && (!f_desde || fecha >= f_desde)
                 && (!f_hasta || fecha <= f_hasta);

      tr.style.display = match ? '' : 'none';
    });
  },

  async exportarInforme() {
    try {
      const f_odc = document.getElementById('f-odc')?.value||'';
      const f_prod = document.getElementById('f-prod')?.value||'';
      const f_desde = document.getElementById('f-desde')?.value||'';
      const f_hasta = document.getElementById('f-hasta')?.value||'';
      const params = [];
      if (f_odc) params.push('numero_odc=' + encodeURIComponent(f_odc));
      if (f_prod) params.push('producto=' + encodeURIComponent(f_prod));
      if (f_desde) params.push('fecha_inicio=' + encodeURIComponent(f_desde));
      if (f_hasta) params.push('fecha_fin=' + encodeURIComponent(f_hasta));
      const token = localStorage.getItem('token')||'';
      const url = `${API_BASE}/reportes/recepciones?export=excel${params.length?'&'+params.join('&'):''}&token=${encodeURIComponent(token)}`;
      window.open(url, '_blank');
    } catch(e) { WMS.toast('error','Error exportando'); }
  },



  // ══════════════════════════════════════════════════════════════════════
  // RECEPCIÓN OPERATIVA
  // ══════════════════════════════════════════════════════════════════════
  async show_operativa(silent=false) {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.recepcion.iniciarConsolaOperativa()"><i class="fa-solid fa-play"></i> Iniciar Captura ODC</button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_operativa()"><i class="fa-solid fa-sync"></i> Actualizar</button>
    `);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/recepciones','limit=100');
      const items = r.data||r||[];
      WMS.setContent(`
        <div class="px-20 py-16">
          <div class="card shadow-soft">
            <div class="card-header d-flex justify-between align-center">
              <span class="card-title fw-900 color-primary"><i class="fa-solid fa-history"></i> Historial de Recepciones</span>
              <div class="text-xs text-muted">Auto-refresh cada 30s</div>
            </div>
            <div class="table-container">
              <table class="erp-table">
                <thead><tr><th>ID</th><th>N° Recepción</th><th>ODC Vinc.</th><th>Estado</th><th>F. Inicio</th><th>F. Fin</th><th>Auxiliar</th><th>Acciones</th></tr></thead>
                <tbody>${items.map(rc=>`<tr>
                  <td>${rc.id}</td>
                  <td class="fw-800">${rc.numero_recepcion}</td>
                  <td><span class="badge badge-info">${WMS.esc(rc.odc?.numero_odc||rc.odc_id||'-')}</span></td>
                  <td><span class="status-chip ${rc.estado==='Cerrada'?'status-cerrada':'status-en-proceso'}">${rc.estado}</span></td>
                  <td>${rc.hora_inicio}</td><td>${rc.hora_fin||'...'}</td>
                  <td><strong>${WMS.esc(rc.auxiliar?.nombre||'-')}</strong></td>
                  <td>
                    ${(rc.estado!=='Cerrada' && rc.odc?.estado!=='Cerrada' && rc.odc?.estado!=='Completada' && rc.odc_id) 
                        ? `<button class="btn btn-xs btn-primary-soft" onclick="WMS_MODULES.recepcion.abrirConsolaRecepcion(${rc.odc_id})"><i class="fa-solid fa-play"></i> Retomar</button>`
                        : (rc.odc_id ? '<span class="text-success text-xs fw-bold"><i class="fa-solid fa-check-double"></i> Finalizada</span>' : '')}
                  </td>
                </tr>`).join('')||'<tr><td colspan="8" class="table-empty">Sin ingresos recientes</td></tr>'}</tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch(e) {}
  },

  async iniciarConsolaOperativa() {
      WMS.spinner();
      try {
          // Buscar ODC pendientes o en proceso
          const r = await API.get('/odc','estado=Confirmada,En Proceso');
          const odcs = r.data||r||[];
          if(odcs.length === 0) return WMS.toast('info', 'No hay Ordenes de Compra en estado Confirmada o En Proceso para recibir.');
          
          WMS.showModal('Seleccionar ODC para Recepción', `
            <div style="padding:20px;">
                <label class="form-label">Seleccione la ODC a recibir</label>
                <select id="op-odc-sel" class="form-control">
                    ${odcs.map(o => `<option value="${o.id}">${o.numero_odc} - ${WMS.esc(o.proveedor?.razon_social||'')}</option>`).join('')}
                </select>
            </div>
          `, `<button class="btn btn-secondary" onclick="WMS.closeModal()">Cancelar</button>
              <button class="btn btn-primary" onclick="
                    const id=document.getElementById('op-odc-sel').value; 
                    WMS.closeModal(); 
                    WMS_MODULES.recepcion.abrirConsolaRecepcion(id);
              "><i class="fa-solid fa-arrow-right"></i> Iniciar Recepción</button>`);
      } catch(e) {
          WMS.toast('error', 'Error al cargar lista de ODCs');
      }
  },

  async abrirConsolaRecepcion(odcId) {
      WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_operativa()"><i class="fa-solid fa-arrow-left"></i> Volver a Monitor</button>`);
      WMS.spinner();
      try {
          const [odcR, prodR] = await Promise.all([
              API.get('/odc/' + odcId),
              API.get('/param/productos')
          ]);
          const odc = odcR.data||odcR;
          window._opProds = prodR.data||prodR||[];
          window._opOdcDetalles = odc.detalles||[];
          window._opCurrentOdcId = odc.id;

          const summaryLines = window._opOdcDetalles.map(d => {
              const pend = d.cantidad_solicitada - d.cantidad_recibida;
              return `<tr id="op-row-${d.producto_id}">
                <td>${WMS.esc(d.producto?.nombre||'')}</td>
                <td style="text-align:center;">${d.cantidad_solicitada}</td>
                <td style="text-align:center;font-weight:bold;color:#2563eb;" id="op-rec-${d.producto_id}">${d.cantidad_recibida}</td>
                <td style="text-align:center;color:${pend>0?'#d97706':'#059669'};" id="op-pend-${d.producto_id}">${pend}</td>
              </tr>`;
          }).join('') || '<tr><td colspan="4" class="text-center">Sin detalles en la ODC</td></tr>';

          WMS.setContent(`
            <div style="display:grid;grid-template-columns:350px 1fr;gap:20px;padding:20px;height:calc(100vh - 120px);overflow:hidden;">
                
                <!-- Panel de Captura Manual (Desktop) -->
                <div style="background:#fff;border-radius:4px;border:1px solid #e2e8f0;padding:20px;display:flex;flex-direction:column;gap:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);overflow-y:auto;">
                    <div style="font-weight:900;font-size:16px;color:#1e3a5f;border-bottom:2px solid #e2e8f0;padding-bottom:10px;margin-bottom:10px;"><i class="fa-solid fa-barcode"></i> Capturar Producto</div>
                    
                    <div class="form-group">
                        <label class="form-label">Producto *</label>
                        <select id="op-prod" class="form-control" onchange="WMS_MODULES.recepcion._onProductoCaptura(this)">
                            <option value="">Seleccione o busque...</option>
                            ${window._opOdcDetalles.map(d=>`<option value="${d.producto_id}" data-upc="${d.producto?.unidades_caja||1}">${WMS.esc(d.producto?.nombre)}</option>`).join('')}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" id="op-cant-label">Cajas a Recibir *</label>
                        <input type="number" id="op-cant" class="form-control" value="1" min="1"
                               oninput="WMS_MODULES.recepcion._actualizarPreviewUnidades()">
                        <!-- Preview de conversión cajas → unidades -->
                        <div id="op-conv-preview" style="display:none;margin-top:6px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:13px;color:#1e40af;font-weight:600;">
                            <i class="fa-solid fa-calculator"></i>
                            <span id="op-conv-text">1 caja × 1 = 1 unidad</span>
                        </div>
                        <input type="hidden" id="op-upc" value="1"> <!-- unidades_caja del producto seleccionado -->
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lote (Opcional)</label>
                        <input type="text" id="op-lote" class="form-control" placeholder="Lote del producto">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Fecha de Vencimiento</label>
                        <input type="date" id="op-fecha-venc" class="form-control">
                    </div>

                    <button class="btn btn-success" style="padding:14px;font-size:16px;font-weight:800;margin-top:10px;" onclick="WMS_MODULES.recepcion._enviarCapturaOperativa()">
                       <i class="fa-solid fa-check"></i> GUARDAR CAPTURA
                    </button>
                </div>

                <!-- Panel Resumen de la ODC -->
                <div style="background:#fff;border-radius:4px;border:1px solid #e2e8f0;display:flex;flex-direction:column;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);overflow:hidden;">
                    <div style="background:#f8fafc;padding:16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                        <h3 style="margin:0;font-size:16px;color:#1e3a5f;"><i class="fa-solid fa-clipboard-list"></i> Resumen ODC: ${odc.numero_odc}</h3>
                        <div style="display:flex;gap:8px;align-items:center;">
                           <span class="badge badge-info">${odc.estado}</span>
                           ${odc.estado !== 'Cerrada' ? `<button class="btn btn-xs btn-danger" onclick="WMS_MODULES.recepcion.cerrarDocumentoRecepcion(${odc.id})"><i class="fa-solid fa-lock"></i> CERRAR DOCUMENTO</button>` : ''}
                        </div>
                    </div>
                    <div style="flex:1;overflow:auto;padding:16px;">
                        <table style="width:100%;border-collapse:collapse;font-size:13px;">
                            <thead style="background:#e2e8f0;">
                                <tr>
                                    <th style="padding:10px;text-align:left;">Producto</th>
                                    <th style="padding:10px;text-align:center;">Solicitado</th>
                                    <th style="padding:10px;text-align:center;">Recibido</th>
                                    <th style="padding:10px;text-align:center;">Pendiente</th>
                                </tr>
                            </thead>
                            <tbody id="op-summary-body">
                                ${summaryLines}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
          `);
      } catch(e) { WMS.toast('error', 'Error al cargar consola'); }
  },

  // ── Cuando cambia el producto seleccionado → actualizar unidades_caja ──────
  _onProductoCaptura(sel) {
      const opt = sel.options[sel.selectedIndex];
      const upc = parseInt(opt?.getAttribute('data-upc') || '1') || 1;
      const upcInput = document.getElementById('op-upc');
      if (upcInput) upcInput.value = upc;

      const label    = document.getElementById('op-cant-label');
      const preview  = document.getElementById('op-conv-preview');
      if (label)   label.textContent = upc > 1 ? 'Cajas a Recibir *' : 'Cantidad a Recibir (unidades) *';
      if (preview) preview.style.display = upc > 1 ? 'block' : 'none';

      this._actualizarPreviewUnidades();
  },

  // ── Actualizar el preview de conversión cajas → unidades ─────────────────
  _actualizarPreviewUnidades() {
      const cajas   = parseInt(document.getElementById('op-cant')?.value || '0') || 0;
      const upc     = parseInt(document.getElementById('op-upc')?.value  || '1') || 1;
      const unidades = cajas * upc;
      const span     = document.getElementById('op-conv-text');
      const preview  = document.getElementById('op-conv-preview');
      if (span && upc > 1) {
          const plural = cajas === 1 ? 'caja' : 'cajas';
          span.textContent = `${cajas} ${plural} × ${upc} = ${WMS.formatNum(unidades)} unidades`;
      }
      if (preview) preview.style.display = upc > 1 ? 'block' : 'none';
  },

  async _enviarCapturaOperativa() {
      const btn = event.currentTarget;
      const originalText = btn.innerHTML;

      const prodId = document.getElementById('op-prod')?.value;
      const cantCajas = parseInt(document.getElementById('op-cant')?.value || 0);
      const upc = parseInt(document.getElementById('op-upc')?.value || '1') || 1;
      const lote = document.getElementById('op-lote')?.value || '';
      const venc = document.getElementById('op-fecha-venc')?.value || '';

      if (!prodId) return WMS.toast('warning', 'Seleccione un producto a capturar.');
      if (cantCajas <= 0) return WMS.toast('warning', 'Cantidad debe ser mayor a cero.');

      const totalUnidades = cantCajas * upc;

      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Guardando...';

      try {
          // Enviamos cantidad_cajas + modo_cajas=1 para que el backend haga la conversión.
          // También enviamos cantidad (unidades calculadas) como fallback legacy.
          const payload = {
              odc_id:         window._opCurrentOdcId,
              producto_id:    prodId,
              cantidad_cajas: cantCajas,          // cajas físicas (nuevo campo)
              modo_cajas:     1,                  // flag explícito de conversión
              cantidad:       totalUnidades,       // unidades calculadas (compatibilidad)
              lote,
              fecha_vencimiento: venc,
          };

          const r = await API.post('/recepciones/detalles-operativa', payload);
          if (r.error) throw new Error(r.message);

          const conv = r.data?.conversion;
          const msgCajas = upc > 1
              ? ` (${cantCajas} caja${cantCajas!==1?'s':''} × ${upc} = ${conv?.total_unidades||totalUnidades} und)`
              : ` (${totalUnidades} und)`;
          WMS.toast('success', `Captura guardada${msgCajas}`);

          // Actualizar celdas del resumen en pantalla
          const newDetalle = r.data?.odc_detalle;
          if (newDetalle) {
              const recCell  = document.getElementById(`op-rec-${prodId}`);
              const pendCell = document.getElementById(`op-pend-${prodId}`);
              if (recCell && pendCell) {
                  recCell.innerHTML = newDetalle.cantidad_recibida;
                  const pend = newDetalle.cantidad_solicitada - newDetalle.cantidad_recibida;
                  pendCell.innerHTML = pend;
                  pendCell.style.color = pend > 0 ? '#d97706' : '#059669';
              }
          }

          // Reset form
          document.getElementById('op-cant').value = '1';
          document.getElementById('op-lote').value = '';
          document.getElementById('op-fecha-venc').value = '';
          this._actualizarPreviewUnidades();

      } catch(e) {
          WMS.toast('error', e.message || 'Error guardando captura');
      } finally {
          btn.disabled = false;
          btn.innerHTML = originalText;
      }
  },

  // ══════════════════════════════════════════════════════════════════════
  // RECEPCIÓN SIN ODC
  // ══════════════════════════════════════════════════════════════════════
  async show_sin_odc(silent = false) {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.recepcion.abrirConsolaSinODC()">
        <i class="fa-solid fa-plus"></i> Nueva Captura Sin ODC
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_sin_odc()">
        <i class="fa-solid fa-sync"></i> Actualizar
      </button>`);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/recepciones', 'odc_id=null&limit=100');
      const items = (r.data || r || []).filter(rc => !rc.odc_id);
      WMS.setContent(`
        <div class="px-20 py-16">
          <div class="card shadow-soft">
            <div class="card-header d-flex justify-between align-center">
              <span class="card-title fw-900 color-primary">
                <i class="fa-solid fa-box-open"></i> Recepciones sin Orden de Compra
              </span>
              <span class="text-xs text-muted">${items.length} registro(s)</span>
            </div>
            <div class="table-container">
              <table class="erp-table">
                <thead><tr>
                  <th>N° Recepción</th><th>Auxiliar</th><th>Estado</th>
                  <th>Fecha</th><th>Líneas</th><th>Acciones</th>
                </tr></thead>
                <tbody>${items.map(rc => `<tr>
                  <td class="fw-800">${WMS.esc(rc.numero_recepcion)}</td>
                  <td>${WMS.esc(rc.auxiliar?.nombre || '-')}</td>
                  <td><span class="status-chip ${rc.estado === 'Cerrada' ? 'status-cerrada' : 'status-en-proceso'}">${rc.estado}</span></td>
                  <td>${WMS.formatDate(rc.fecha_movimiento)}</td>
                  <td class="text-center">${rc.detalles?.length || rc.detalles_count || '-'}</td>
                  <td>
                    ${rc.estado === 'Borrador' ? `
                      <button class="btn btn-xs btn-primary-soft" onclick="WMS_MODULES.recepcion.abrirConsolaSinODC('${rc.id}')">
                        <i class="fa-solid fa-play"></i> Retomar
                      </button>` : ''}
                  </td>
                </tr>`).join('') || '<tr><td colspan="6" class="table-empty">Sin recepciones sin ODC registradas</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch (e) { WMS.toast('error', 'Error cargando recepciones'); }
  },

  // _sinOdcProds: caché de productos cargados para el autocompletado
  _sinOdcProds: [],
  _sinOdcRecepcionId: null,

  async abrirConsolaSinODC(recepcionId = null) {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_sin_odc()">
        <i class="fa-solid fa-arrow-left"></i> Volver al Monitor
      </button>`);
    WMS.spinner();
    try {
      const prodR = await API.get('/param/productos');
      this._sinOdcProds = prodR.data || prodR || [];
      this._sinOdcRecepcionId = recepcionId || null;

      // Historial de capturas del día (recepcion en borrador sin ODC)
      let lineas = [];
      if (recepcionId) {
        try {
          const recR = await API.get('/recepciones/' + recepcionId);
          lineas = (recR.data?.detalles || []).slice().reverse();
        } catch (_) {}
      }

      WMS.setContent(`
        <div style="display:grid;grid-template-columns:400px 1fr;gap:20px;padding:20px;height:calc(100vh - 120px);overflow:hidden;">

          <!-- Panel de Captura -->
          <div style="background:#fff;border-radius:6px;border:1px solid #e2e8f0;padding:20px;display:flex;flex-direction:column;gap:14px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);overflow-y:auto;">

            <!-- Header -->
            <div style="font-weight:900;font-size:16px;color:#1e3a5f;border-bottom:2px solid #e2e8f0;padding-bottom:10px;">
              <i class="fa-solid fa-box-open" style="color:#059669;"></i> Captura Sin ODC
            </div>

            <!-- QR Scanner -->
            <div style="background:#f0fdf4;border:1px solid #a7f3d0;border-radius:6px;padding:12px;">
              <div style="font-size:11px;font-weight:700;color:#065f46;margin-bottom:6px;text-transform:uppercase;">
                <i class="fa-solid fa-qrcode"></i> Escanear QR (Código/FechaVencimiento)
              </div>
              <div style="display:flex;gap:8px;">
                <input type="text" id="sodc-qr-input" class="form-control" placeholder="Escanee o ingrese QR (ej: 7702003/20261231)"
                  style="flex:1;font-family:monospace;font-size:13px;"
                  onkeydown="if(event.key==='Enter'||event.key==='Tab'){event.preventDefault();WMS_MODULES.recepcion._procesarQrSinODC();}">
                <button class="btn btn-success" style="padding:0 14px;" onclick="WMS_MODULES.recepcion._procesarQrSinODC()" title="Buscar QR">
                  <i class="fa-solid fa-magnifying-glass"></i>
                </button>
              </div>
              <div style="font-size:10px;color:#6b7280;margin-top:4px;">
                Formato: <code>CODIGO_PRODUCTO/FECHA_VENCIMIENTO</code> — El sistema extrae el producto y parsea la fecha automáticamente.
              </div>
            </div>

            <!-- Producto -->
            <div class="form-group">
              <label class="form-label">Producto <span style="color:#ef4444">*</span></label>
              <div style="position:relative;">
                <input type="text" id="sodc-prod-search" class="form-control" placeholder="Buscar por nombre, EAN o código..."
                  autocomplete="off" oninput="WMS_MODULES.recepcion._buscarProdSinODC(this.value)">
                <div id="sodc-prod-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:4px;box-shadow:0 8px 16px rgba(0,0,0,.12);z-index:1000;max-height:200px;overflow-y:auto;"></div>
              </div>
              <input type="hidden" id="sodc-prod-id">
              <div id="sodc-prod-info" style="display:none;margin-top:6px;padding:8px 12px;background:#f1f5f9;border-radius:4px;font-size:12px;"></div>
            </div>

            <!-- Cantidad + Conversión -->
            <div class="form-group">
              <label class="form-label" id="sodc-cant-label">Cantidad a Recibir *</label>
              <input type="number" id="sodc-cant" class="form-control" value="1" min="1"
                oninput="WMS_MODULES.recepcion._actualizarPreviewSinODC()">
              <div id="sodc-conv-preview" style="display:none;margin-top:6px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:13px;color:#1e40af;font-weight:600;">
                <i class="fa-solid fa-calculator"></i> <span id="sodc-conv-text"></span>
              </div>
              <input type="hidden" id="sodc-upc" value="1">
            </div>

            <!-- Lote -->
            <div class="form-group">
              <label class="form-label">Lote (Opcional)</label>
              <input type="text" id="sodc-lote" class="form-control" placeholder="Número de lote">
            </div>

            <!-- Fecha Vencimiento -->
            <div class="form-group">
              <label class="form-label">Fecha de Vencimiento</label>
              <input type="date" id="sodc-fecha-venc" class="form-control">
              <div id="sodc-fecha-info" style="display:none;font-size:11px;color:#059669;margin-top:3px;"></div>
            </div>

            <!-- Estado Mercancía -->
            <div class="form-group">
              <label class="form-label">Estado Mercancía</label>
              <select id="sodc-estado" class="form-control">
                <option value="BuenEstado">Buen Estado</option>
                <option value="Averia">Avería</option>
                <option value="Vencido">Vencido</option>
              </select>
            </div>

            <!-- Botón Guardar -->
            <button class="btn btn-success" style="padding:14px;font-size:16px;font-weight:800;margin-top:4px;"
              onclick="WMS_MODULES.recepcion._enviarCapturaSinODC()">
              <i class="fa-solid fa-check"></i> GUARDAR CAPTURA
            </button>
          </div>

          <!-- Panel Historial -->
          <div style="background:#fff;border-radius:6px;border:1px solid #e2e8f0;display:flex;flex-direction:column;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);overflow:hidden;">
            <div style="background:#f8fafc;padding:16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
              <h3 style="margin:0;font-size:15px;color:#1e3a5f;"><i class="fa-solid fa-history"></i> Capturas Registradas</h3>
              <span id="sodc-counter" style="font-size:12px;color:#64748b;">${lineas.length} líneas</span>
            </div>
            <div style="flex:1;overflow:auto;" id="sodc-lines-container">
              ${lineas.length === 0
                ? '<div style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;"><i class="fa-solid fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>Sin capturas aún. Use el panel izquierdo para registrar.</div>'
                : `<table style="width:100%;border-collapse:collapse;font-size:13px;">
                  <thead style="background:#f1f5f9;"><tr>
                    <th style="padding:10px;text-align:left;">Producto</th>
                    <th style="padding:10px;text-align:center;">Cant.</th>
                    <th style="padding:10px;text-align:center;">Cajas</th>
                    <th style="padding:10px;text-align:left;">Lote</th>
                    <th style="padding:10px;text-align:left;">F. Venc.</th>
                    <th style="padding:10px;text-align:left;">Estado</th>
                  </tr></thead>
                  <tbody id="sodc-lines-body">
                    ${lineas.map(l => `<tr style="border-bottom:1px solid #f1f5f9;">
                      <td style="padding:9px 10px;font-weight:600;">${WMS.esc(l.producto?.nombre || '-')}</td>
                      <td style="padding:9px 10px;text-align:center;font-weight:800;color:#059669;">${l.cantidad_recibida}</td>
                      <td style="padding:9px 10px;text-align:center;">${l.cantidad_cajas || '-'}</td>
                      <td style="padding:9px 10px;">${WMS.esc(l.lote || 'N/A')}</td>
                      <td style="padding:9px 10px;">${l.fecha_vencimiento ? WMS.formatDate(l.fecha_vencimiento) : '-'}</td>
                      <td style="padding:9px 10px;"><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#f0fdf4;color:#166534;">${WMS.esc(l.estado_mercancia || 'BuenEstado')}</span></td>
                    </tr>`).join('')}
                  </tbody>
                </table>`}
            </div>
          </div>

        </div>`);
    } catch (e) { WMS.toast('error', 'Error cargando consola sin ODC: ' + e.message); }
  },

  async _procesarQrSinODC() {
    const input = document.getElementById('sodc-qr-input');
    const qr = (input?.value || '').trim();
    if (!qr) return;

    try {
      const r = await API.get('/recepciones/buscar-qr', 'q=' + encodeURIComponent(qr));
      if (r.error) { WMS.toast('warning', r.message || 'Producto no encontrado'); return; }

      const p = r.data.producto;
      const fechaVenc = r.data.fecha_vencimiento;

      // Rellenar campo producto
      const searchInput = document.getElementById('sodc-prod-search');
      const hiddenId    = document.getElementById('sodc-prod-id');
      const prodInfo    = document.getElementById('sodc-prod-info');
      const upcInput    = document.getElementById('sodc-upc');

      if (searchInput) searchInput.value = p.nombre;
      if (hiddenId)    hiddenId.value    = p.id;
      if (upcInput)    upcInput.value    = p.unidades_caja || 1;

      if (prodInfo) {
        prodInfo.style.display = 'block';
        prodInfo.innerHTML = `<i class="fa-solid fa-check-circle" style="color:#059669;"></i> <b>${WMS.esc(p.nombre)}</b> · EAN: ${WMS.esc(p.codigo_interno || '-')} · UxC: ${p.unidades_caja || 1}`;
      }

      // Rellenar fecha de vencimiento si viene en el QR
      const fechaInput  = document.getElementById('sodc-fecha-venc');
      const fechaInfo   = document.getElementById('sodc-fecha-info');
      if (fechaVenc && fechaInput) {
        fechaInput.value = fechaVenc;
        if (fechaInfo) {
          fechaInfo.style.display = 'block';
          fechaInfo.innerHTML = `<i class="fa-solid fa-calendar-check"></i> Fecha parseada del QR: <b>${WMS.formatDate(fechaVenc)}</b> (texto original: "${WMS.esc(r.data.fecha_raw || '')}"))`;
        }
      }

      this._actualizarPreviewSinODC();
      input.value = '';
      document.getElementById('sodc-cant')?.focus();
      WMS.toast('success', 'Producto identificado: ' + p.nombre);
    } catch (e) {
      WMS.toast('error', 'Error al procesar QR: ' + (e.message || 'No encontrado'));
    }
  },

  _buscarProdSinODC(q) {
    const dd = document.getElementById('sodc-prod-dropdown');
    if (!dd) return;
    if (q.length < 2) { dd.style.display = 'none'; return; }
    const ql = q.toLowerCase();
    const matches = this._sinOdcProds.filter(p =>
      (p.nombre || '').toLowerCase().includes(ql) ||
      (p.codigo_interno || '').toLowerCase().includes(ql) ||
      (p.ean || '').toLowerCase().includes(ql)
    ).slice(0, 10);
    if (!matches.length) { dd.style.display = 'none'; return; }
    dd.style.display = 'block';
    dd.innerHTML = matches.map(p => `
      <div onclick="WMS_MODULES.recepcion._seleccionarProdSinODC(${p.id})"
        style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;"
        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
        <div style="font-weight:600;">${WMS.esc(p.nombre)}</div>
        <div style="font-size:11px;color:#64748b;">${WMS.esc(p.codigo_interno || '')} · UxC: ${p.unidades_caja || 1}</div>
      </div>`).join('');
  },

  _seleccionarProdSinODC(id) {
    const p = this._sinOdcProds.find(x => x.id === id || x.id === String(id));
    if (!p) return;
    const searchInput = document.getElementById('sodc-prod-search');
    const hiddenId    = document.getElementById('sodc-prod-id');
    const prodInfo    = document.getElementById('sodc-prod-info');
    const upcInput    = document.getElementById('sodc-upc');
    const dd          = document.getElementById('sodc-prod-dropdown');

    if (searchInput) searchInput.value = p.nombre;
    if (hiddenId)    hiddenId.value    = p.id;
    if (upcInput)    upcInput.value    = p.unidades_caja || 1;
    if (dd)          dd.style.display  = 'none';
    if (prodInfo) {
      prodInfo.style.display = 'block';
      prodInfo.innerHTML = `<i class="fa-solid fa-check-circle" style="color:#059669;"></i> <b>${WMS.esc(p.nombre)}</b> · Cód: ${WMS.esc(p.codigo_interno || '-')} · UxC: ${p.unidades_caja || 1}`;
    }
    this._actualizarPreviewSinODC();
    document.getElementById('sodc-cant')?.focus();
  },

  _actualizarPreviewSinODC() {
    const cajas   = parseInt(document.getElementById('sodc-cant')?.value || '0') || 0;
    const upc     = parseInt(document.getElementById('sodc-upc')?.value  || '1') || 1;
    const preview = document.getElementById('sodc-conv-preview');
    const span    = document.getElementById('sodc-conv-text');
    const label   = document.getElementById('sodc-cant-label');
    if (label)   label.textContent = upc > 1 ? 'Cajas a Recibir *' : 'Cantidad a Recibir (unidades) *';
    if (preview) preview.style.display = upc > 1 ? 'block' : 'none';
    if (span && upc > 1) {
      const plural = cajas === 1 ? 'caja' : 'cajas';
      span.textContent = `${cajas} ${plural} × ${upc} = ${WMS.formatNum(cajas * upc)} unidades`;
    }
  },

  async _enviarCapturaSinODC() {
    const btn = event.currentTarget;
    const prodId    = document.getElementById('sodc-prod-id')?.value;
    const cantCajas = parseInt(document.getElementById('sodc-cant')?.value || '0');
    const upc       = parseInt(document.getElementById('sodc-upc')?.value  || '1') || 1;
    const lote      = document.getElementById('sodc-lote')?.value || '';
    const venc      = document.getElementById('sodc-fecha-venc')?.value || '';
    const estado    = document.getElementById('sodc-estado')?.value || 'BuenEstado';

    if (!prodId) return WMS.toast('warning', 'Seleccione un producto');
    if (cantCajas <= 0) return WMS.toast('warning', 'Cantidad debe ser mayor a cero');

    const totalUnidades = cantCajas * upc;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Guardando...';

    try {
      const r = await API.post('/recepciones/sin-odc', {
        producto_id:    prodId,
        cantidad_cajas: cantCajas,
        cantidad:       totalUnidades,
        lote:           lote || undefined,
        fecha_vencimiento: venc || undefined,
        estado_mercancia: estado,
      });
      if (r.error) throw new Error(r.message);

      // Guardar id de recepción para retomarla
      this._sinOdcRecepcionId = r.data?.recepcion?.id || null;

      // Agregar fila al panel de historial
      this._agregarLineaSinODC(r.data);

      // Limpiar campos (no el producto, para captura rápida del mismo ítem)
      const cantInput = document.getElementById('sodc-cant');
      const loteInput = document.getElementById('sodc-lote');
      const fechaInput = document.getElementById('sodc-fecha-venc');
      const fechaInfo  = document.getElementById('sodc-fecha-info');
      if (cantInput)  cantInput.value  = '1';
      if (loteInput)  loteInput.value  = '';
      if (fechaInput) fechaInput.value = '';
      if (fechaInfo)  { fechaInfo.style.display = 'none'; }

      const conv = r.data?.conversion;
      const msgCajas = upc > 1
        ? ` (${cantCajas} caja${cantCajas !== 1 ? 's' : ''} × ${upc} = ${conv?.total_unidades || totalUnidades} und)`
        : ` (${totalUnidades} und)`;
      WMS.toast('success', 'Captura registrada' + msgCajas);
    } catch (e) {
      WMS.toast('error', e.message || 'Error al guardar captura');
    } finally {
      btn.disabled = false;
      btn.innerHTML = original;
    }
  },

  _agregarLineaSinODC(data) {
    const container = document.getElementById('sodc-lines-container');
    const counter   = document.getElementById('sodc-counter');
    if (!container) return;

    const det  = data?.detalle || {};
    const prod = data?.recepcion ? null : null; // producto viene en detalle.producto (not loaded eagerly)
    const prodName = document.getElementById('sodc-prod-search')?.value || '-';

    // Si el contenedor tiene el mensaje vacío, reemplazar con tabla
    if (container.querySelector('.fa-inbox')) {
      container.innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead style="background:#f1f5f9;"><tr>
          <th style="padding:10px;text-align:left;">Producto</th>
          <th style="padding:10px;text-align:center;">Cant.</th>
          <th style="padding:10px;text-align:center;">Cajas</th>
          <th style="padding:10px;text-align:left;">Lote</th>
          <th style="padding:10px;text-align:left;">F. Venc.</th>
          <th style="padding:10px;text-align:left;">Estado</th>
        </tr></thead>
        <tbody id="sodc-lines-body"></tbody>
      </table>`;
    }

    const tbody = document.getElementById('sodc-lines-body');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.style.borderBottom = '1px solid #f1f5f9';
    tr.style.background = '#f0fdf4';
    tr.innerHTML = `
      <td style="padding:9px 10px;font-weight:600;">${WMS.esc(prodName)}</td>
      <td style="padding:9px 10px;text-align:center;font-weight:800;color:#059669;">${det.cantidad_recibida || '-'}</td>
      <td style="padding:9px 10px;text-align:center;">${det.cantidad_cajas || '-'}</td>
      <td style="padding:9px 10px;">${WMS.esc(det.lote || 'N/A')}</td>
      <td style="padding:9px 10px;">${det.fecha_vencimiento ? WMS.formatDate(det.fecha_vencimiento) : '-'}</td>
      <td style="padding:9px 10px;"><span style="font-size:11px;padding:2px 8px;border-radius:10px;background:#f0fdf4;color:#166534;">${WMS.esc(det.estado_mercancia || 'BuenEstado')}</span></td>`;
    tbody.insertBefore(tr, tbody.firstChild);
    setTimeout(() => { tr.style.background = ''; }, 1500);

    // Actualizar contador
    const rows = tbody.querySelectorAll('tr').length;
    if (counter) counter.textContent = rows + ' línea' + (rows !== 1 ? 's' : '');
  },

  // ══════════════════════════════════════════════════════════════════════
  // ══════════════════════════════════════════════════════════════════════
  // CITAS YMS — Gestión completa de citas de patio
  // ══════════════════════════════════════════════════════════════════════
  async show_citas() {
    const now = new Date();
    window._ymsMonth = window._ymsMonth !== undefined ? window._ymsMonth : now.getMonth();
    window._ymsYear  = window._ymsYear  !== undefined ? window._ymsYear  : now.getFullYear();

    const viewSwitch = window._ymsView === 'lista' ?
        `<button class="btn btn-info btn-sm" onclick="window._ymsView='cal'; WMS_MODULES.recepcion.show_citas()"><i class="fa-solid fa-calendar-days"></i> Vista Calendario</button>` :
        `<button class="btn btn-info btn-sm" onclick="window._ymsView='lista'; WMS_MODULES.recepcion.show_citas()"><i class="fa-solid fa-list-ul"></i> Vista Línea de Tiempo</button>`;
        
    const viewClosedBtn = window._viewClosedCitas 
        ? `<button class="btn btn-secondary-soft btn-sm" onclick="window._viewClosedCitas=false; WMS_MODULES.recepcion.show_citas()"><i class="fa-solid fa-eye-slash"></i> Ocultar Cerradas</button>`
        : `<button class="btn btn-secondary-soft btn-sm" onclick="window._viewClosedCitas=true; WMS_MODULES.recepcion.show_citas()"><i class="fa-solid fa-eye"></i> Ver Cerradas</button>`;

    WMS.setToolbar(`
      <div style="display:flex;gap:8px;align-items:center;">
        ${window._ymsView === 'cal' ? `
          <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion._changeYmsMonth(-1)"><i class="fa-solid fa-chevron-left"></i></button>
          <span style="font-weight:800;min-width:120px;text-align:center;text-transform:capitalize;">${new Date(window._ymsYear, window._ymsMonth).toLocaleDateString('es-CO', {month:'long', year:'numeric'})}</span>
          <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion._changeYmsMonth(1)"><i class="fa-solid fa-chevron-right"></i></button>
        ` : ''}
        ${viewSwitch}
        ${viewClosedBtn}
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_citas()"><i class="fa-solid fa-sync"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.recepcion.nuevaCita()"><i class="fa-solid fa-calendar-plus"></i> Nueva Cita</button>
      </div>`);
    WMS.spinner();
    try {
      const hoy = new Date().toISOString().split('T')[0];
      const r = await API.get('/citas');
      window._allYmsCitas = (Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []))
        .map(c => ({ ...c, fecha: (c.fecha || '').substring(0, 10) }));

      const citasFinales = window._viewClosedCitas 
        ? window._allYmsCitas 
        : window._allYmsCitas.filter(c => c.estado !== 'Completada' && c.estado !== 'Cancelada');

      const stColor = { Programada:'#2563eb', EnPatio:'#d97706', EnCurso:'#7c3aed', Completada:'#059669', Cancelada:'#dc2626' };
      const stBg    = { Programada:'#eff6ff', EnPatio:'#fffbeb', EnCurso:'#f5f3ff', Completada:'#f0fdf4', Cancelada:'#fef2f2' };
      const stLabel = { Programada:'Programada', EnPatio:'En Patio', EnCurso:'En Descargue', Completada:'Completada', Cancelada:'Cancelada' };

      const chip = st => `<span style="background:${stBg[st]||'#f1f5f9'};color:${stColor[st]||'#64748b'};padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;">${stLabel[st]||st}</span>`;

      // Agrupar por fecha
      const byFecha = {};
      citasFinales.forEach(c => {
        const f = c.fecha || 'Sin fecha';
        if (!byFecha[f]) byFecha[f] = [];
        byFecha[f].push(c);
      });

      const fechasOrdenadas = Object.keys(byFecha).sort();

      const citasHoy = window._allYmsCitas.filter(c => c.fecha === hoy);
      const programadas = window._allYmsCitas.filter(c => c.estado === 'Programada').length;
      const enPatio = window._allYmsCitas.filter(c => c.estado === 'EnPatio' || c.estado === 'EnCurso').length;
      const completadas = window._allYmsCitas.filter(c => c.estado === 'Completada').length;

      WMS.setContent(`
        <div style="padding:20px; height:calc(100vh - 120px); overflow:auto;">
          <!-- KPI strip YMS -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">
            ${[
              {icon:'fa-calendar-check', val:window._allYmsCitas.length, lab:'Total Citas (Mes)', color:'#2563eb', bg:'#eff6ff'},
              {icon:'fa-clock',          val:programadas,               lab:'Programadas',       color:'#d97706', bg:'#fffbeb'},
              {icon:'fa-truck-ramp-box', val:enPatio,                  lab:'En Patio / Descargue', color:'#7c3aed', bg:'#f5f3ff'},
              {icon:'fa-circle-check',   val:completadas,               lab:'Completadas',       color:'#059669', bg:'#f0fdf4'},
            ].map(k=>`
              <div style="background:#fff;border-radius:4px;padding:16px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:4px;background:${k.bg};display:flex;align-items:center;justify-content:center;color:${k.color};font-size:20px;"><i class="fa-solid ${k.icon}"></i></div>
                <div><div style="font-size:24px;font-weight:900;color:#1e3a5f;">${k.val}</div><div style="font-size:11px;font-weight:600;color:#64748b;">${k.lab}</div></div>
              </div>`).join('')}
          </div>

          <!-- Timeline o Modo Slicer/Calendario -->
          ${window._ymsView === 'cal' ? this._renderCalendario7x5(window._allYmsCitas) : (
            fechasOrdenadas.length === 0 ? '<div style="text-align:center;padding:40px;color:#94a3b8;font-style:italic;">No hay citas registradas para esta vista</div>' :
            fechasOrdenadas.map(fecha => {
              const esHoy = fecha === hoy;
              const d = fecha === 'Sin fecha' ? null : new Date(fecha.split('T')[0] + 'T00:00:00');
              const label = !d || isNaN(d.getTime()) ? 'Pendientes / Sin Fecha' :
                (esHoy ? '🟢 HOY — ' + d.toLocaleDateString('es-CO',{weekday:'long',day:'numeric',month:'long'})
                       : d.toLocaleDateString('es-CO',{weekday:'long',day:'numeric',month:'long',year:'numeric'}));
              return `
              <div style="background:#fff;border-radius:4px;border:1px solid ${esHoy?'#2563eb':'#e2e8f0'};box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:16px;">
                <div style="padding:12px 20px;border-bottom:1px solid ${esHoy?'#dbeafe':'#f1f5f9'};background:${esHoy?'#eff6ff':'#f8fafc'};border-radius:4px 4px 0 0;">
                  <span style="font-weight:800;color:${esHoy?'#2563eb':'#1e3a5f'};font-size:13px;">${label}</span>
                  <span style="margin-left:10px;font-size:11px;color:#64748b;">${byFecha[fecha].length} cita(s)</span>
                </div>
                <div style="overflow-x:auto;">
                  <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead><tr style="background:#f8fafc;">
                      <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Hora</th>
                      <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Proveedor</th>
                      <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">ODC Vinculada</th>
                      <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Vehículo</th>
                      <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Cajas / Kg</th>
                      <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Estado</th>
                      <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Acciones</th>
                    </tr></thead>
                    <tbody>
                      ${byFecha[fecha].map((c,i) => `
                        <tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                          <td style="padding:8px 12px;font-weight:800;color:#1e3a5f;font-size:14px;">${(c.hora_programada||'--:--').substring(0,5)}</td>
                          <td style="padding:8px 12px;"><strong>${WMS.esc(c.proveedor||'-')}</strong>${c.notas?`<br><span style="font-size:10px;color:#64748b;">${WMS.esc(c.notas)}</span>`:''}</td>
                          <td style="padding:8px 12px;">${c.odc?`<span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">${WMS.esc(c.odc)}</span>`:'<span style="color:#94a3b8">—</span>'}</td>
                          <td style="padding:8px 12px;text-align:center;">${WMS.esc(c.tipo_vehiculo||'—')}</td>
                          <td style="padding:8px 12px;text-align:center;">${c.cantidad_cajas||0} / ${c.kilos||0}kg</td>
                          <td style="padding:8px 12px;text-align:center;">${chip(c.estado)}</td>
                          <td style="padding:8px 12px;text-align:center;">
                            <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                              ${c.estado==='Programada'?`<button class="btn btn-xs btn-warning" onclick="WMS_MODULES.recepcion.marcarLlegadaCita(${c.id})" title="Registrar llegada a patio"><i class="fa-solid fa-truck-arrow-right"></i></button>`:''}
                              ${c.estado==='EnPatio'?`<button class="btn btn-xs btn-success" onclick="WMS_MODULES.recepcion.completarCita(${c.id})" title="Completar descargue"><i class="fa-solid fa-flag-checkered"></i></button>`:''}
                              ${c.estado!=='Completada'&&c.estado!=='Cancelada'?`<button class="btn btn-xs btn-primary-soft" onclick="WMS_MODULES.recepcion.editarCita(${c.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>`:''}
                              ${c.estado!=='Completada'&&c.estado!=='Cancelada'?`<button class="btn btn-xs btn-danger-soft" onclick="WMS_MODULES.recepcion.cancelarCita(${c.id})" title="Cancelar"><i class="fa-solid fa-ban"></i></button>`:''}
                              ${c.odc_id?`<button class="btn btn-xs btn-info-soft" onclick="WMS_MODULES.recepcion.verODC(${c.odc_id})" title="Ver ODC"><i class="fa-solid fa-table-cells"></i></button>`:''}
                            </div>
                          </td>
                        </tr>`).join('')}
                    </tbody>
                  </table>
                </div>
              </div>`;
            }).join('')
          )}
        </div>`);
    } catch(e) { console.error(e); WMS.toast('error','Error cargando citas'); }
  },

  _changeYmsMonth(dir) {
    window._ymsMonth += dir;
    if (window._ymsMonth > 11) { window._ymsMonth = 0; window._ymsYear++; }
    if (window._ymsMonth < 0) { window._ymsMonth = 11; window._ymsYear--; }
    this.show_citas();
  },
  _renderCalendario7x5(citas) {
    const firstDay = new Date(window._ymsYear, window._ymsMonth, 1).getDay(); // 0=Sun, 1=Mon...
    const daysInMonth = new Date(window._ymsYear, window._ymsMonth + 1, 0).getDate();
    const todayStr = new Date().toISOString().split('T')[0];

    // Ajustamos para que empiece en lunes (1)
    let startOffset = firstDay === 0 ? 6 : firstDay - 1;

    let html = `
      <div class="card shadow-soft" style="padding:0; overflow:hidden; border:1px solid #e2e8f0; border-radius:4px;">
        <div style="display:grid; grid-template-columns:repeat(7, 1fr); background:#f8fafc; border-bottom:1px solid #e2e8f0; grid-auto-rows: 40px;">
          ${['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'].map(d=>`<div style="display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase;">${d}</div>`).join('')}
        </div>
        <div style="display:grid; grid-template-columns:repeat(7, 1fr); grid-auto-rows:minmax(130px, auto); background:#e2e8f0; gap:1px;">`;

    // Celdas vacías iniciales
    for (let i=0; i<startOffset; i++) {
        html += `<div style="background:#f8fafc; opacity:0.5;"></div>`;
    }

    // Días del mes
    for (let day=1; day<=daysInMonth; day++) {
        const dateStr = `${window._ymsYear}-${String(window._ymsMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        const esHoy = dateStr === todayStr;
        const dayCitas = citas.filter(c => c.fecha === dateStr && (window._viewClosedCitas || (c.estado !== 'Completada' && c.estado !== 'Cancelada')));
        
        html += `
        <div style="background:#fff; padding:10px; display:flex; flex-direction:column; gap:6px; position:relative; ${esHoy?'background:linear-gradient(to bottom, #f0f9ff, #fff);':''}">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
            <span style="font-weight:900; font-size:16px; color:${esHoy?'#2563eb':'#1e3a5f'};">${day}</span>
            ${esHoy ? '<span style="font-size:9px; font-weight:900; background:#2563eb; color:#fff; padding:2px 6px; border-radius:6px; box-shadow:0 2px 4px rgba(37,99,235,0.2);">HOY</span>' : ''}
          </div>
          <div style="flex:1; display:flex; flex-direction:column; gap:4px; overflow:hidden;">
            ${dayCitas.sort((a,b)=>(a.hora_programada||'').localeCompare(b.hora_programada||'')).slice(0, 4).map(c => {
               const stColor = { Programada:'#2563eb', EnPatio:'#d97706', EnCurso:'#7c3aed', Completada:'#059669', Cancelada:'#dc2626' };
               const stBg    = { Programada:'#eff6ff', EnPatio:'#fffbeb', EnCurso:'#f5f3ff', Completada:'#f0fdf4', Cancelada:'#fef2f2' };
               return `
               <div onclick="WMS_MODULES.recepcion.verDetalleCita(${c.id})" style="cursor:pointer; background:${stBg[c.estado]||'#fff'}; border-left:3px solid ${stColor[c.estado]||'#64748b'}; padding:4px 8px; border-radius:4px; font-size:10px; box-shadow:0 1px 2px rgba(0,0,0,0.03); transition:all 0.2s;" class="yms-cal-item" title="${WMS.esc(c.proveedor)}">
                 <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
                    <strong style="color:#1e3a5f;">${(c.hora_programada||'').substring(0,5)}</strong>
                    <span style="font-size:9px; color:${stColor[c.estado]}; font-weight:700;">${c.estado === 'EnPatio' ? 'PATIO' : c.estado === 'EnCurso' ? 'DESC' : ''}</span>
                 </div>
                 <div style="color:#475569; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">${WMS.esc(c.proveedor)}</div>
               </div>`;
            }).join('') || `<div style="flex:1; display:flex; align-items:center; justify-content:center; opacity:0.1; font-size:40px; color:#cbd5e1;"><i class="fa-solid fa-truck"></i></div>`}
            ${dayCitas.length > 4 ? `<div style="font-size:10px; color:#64748b; text-align:center; font-weight:800; background:#f1f5f9; padding:2px; border-radius:4px;">+ ${dayCitas.length - 4} citas</div>` : ''}
          </div>
          <button class="btn-ghost" style="font-size:18px; padding:2px; color:#cbd5e1; align-self:flex-end; transition:color 0.2s;" onmouseover="this.style.color='#2563eb'" onmouseout="this.style.color='#cbd5e1'" onclick="WMS_MODULES.recepcion.nuevaCitaEnFecha('${dateStr}')"><i class="fa-solid fa-circle-plus"></i></button>
        </div>`;
    }

    // Celdas vacías finales (cuadrícula 7x5 o 7x6)
    const totalCells = startOffset + daysInMonth;
    const gridCells = totalCells <= 35 ? 35 : 42;
    const remainingCells = gridCells - totalCells;
    for (let i=0; i<remainingCells; i++) {
        html += `<div style="background:#f8fafc; opacity:0.5;"></div>`;
    }

    html += `</div></div>`;
    return html;
  },

  nuevaCitaEnFecha(f) {
      this.nuevaCita();
      setTimeout(() => {
          const el = document.getElementById('cy-fecha');
          if (el) { el.value = f; this._recalcHorasYMS(); }
      }, 200);
  },

  _recalcHorasYMS() {
      const cyFecha = document.getElementById('cy-fecha')?.value;
      const cyHora = document.getElementById('cy-hora');
      if (!cyFecha || !cyHora) return;
      
      // Asumimos atención estándar de 8 AM a 5 PM cada hora.
      const horasDisponibles = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00'];
      
      // Buscar citas activas (no canceladas) para ese dia.
      const ocupadas = (window._allYmsCitas || []).filter(c => c.fecha === cyFecha && c.estado !== 'Cancelada' && c.estado !== 'Completada').map(c => (c.hora_programada||'').substring(0,5));
      
      // Armamos options
      let html = '';
      horasDisponibles.forEach(h => {
          const count = ocupadas.filter(x => x === h).length;
          // Si hay 2 o mas citas a la misma hora asume limite de bahias ocupado (logica base: max 2 por hora)
          if (count >= 2) {
              html += `<option value="${h}" disabled>${h} - Ocupado (Sin Bahías)</option>`;
          } else {
              html += `<option value="${h}">${h} - ${count>0 ? count+' cita(s)' : 'Disponible'}</option>`;
          }
      });
      cyHora.innerHTML = html;
  },

  nuevaCita() {
    WMS.showModal('Programar Nueva Cita YMS', `
      <div class="modal-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">Proveedor *</label>
            <input id="cy-prov" class="form-control" placeholder="Nombre del proveedor">
          </div>
          <div class="form-group">
            <label class="form-label">Fecha *</label>
            <input id="cy-fecha" type="date" class="form-control" value="${new Date().toISOString().split('T')[0]}">
          </div>
          <div class="form-group">
            <label class="form-label">Hora y Franja Disponible *</label>
            <select id="cy-hora" class="form-control">
              <!-- Cargado dinámicamente -->
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">ODC Vinculada</label>
            <select id="cy-odc" class="form-control">
              <option value="">Sin vincular</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo de Vehículo</label>
            <select id="cy-vehiculo" class="form-control">
              <option>Camión Sencillo</option><option>Camión Doble Troque</option>
              <option>Furgón</option><option>Camioneta</option><option>Tractomula</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Cantidad Cajas Est.</label>
            <input id="cy-cajas" type="number" class="form-control" value="0" min="0">
          </div>
          <div class="form-group">
            <label class="form-label">Kilos Est.</label>
            <input id="cy-kilos" type="number" class="form-control" value="0" min="0">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">Notas / Observaciones</label>
            <textarea id="cy-notas" class="form-control" rows="2" placeholder="Observaciones opcionales..."></textarea>
          </div>
        </div>
      </div>`,
      `<button class="btn btn-ghost" onclick="WMS.closeModal()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.recepcion._guardarCita()"><i class="fa-solid fa-save"></i> Guardar Cita</button>`
    );
    // Bindear select dinámico de horas basado en la fecha escogida
    this._recalcHorasYMS();
    setTimeout(() => {
       const cyFechaInput = document.getElementById('cy-fecha');
       if (cyFechaInput) cyFechaInput.addEventListener('change', () => this._recalcHorasYMS());
    }, 100);

    // Cargar ODCs disponibles
    API.get('/odc','estado=Confirmada,Borrador').then(r=>{
      const sel = document.getElementById('cy-odc');
      if (!sel) return;
      (r.data||r||[]).forEach(o=>{
        const opt = document.createElement('option');
        opt.value = o.id;
        opt.dataset.prov = o.proveedor?.razon_social||'';
        opt.textContent = `${o.numero_odc} — ${o.proveedor?.razon_social||''}`;
        sel.appendChild(opt);
      });
      sel.addEventListener('change', ()=>{
        const opt = sel.options[sel.selectedIndex];
        const prov = document.getElementById('cy-prov');
        if (opt.dataset.prov && prov && !prov.value) prov.value = opt.dataset.prov;
      });
    });
  },

  async _guardarCita(id = null) {
    const proveedor = document.getElementById('cy-prov')?.value?.trim();
    const fecha     = document.getElementById('cy-fecha')?.value;
    const hora      = document.getElementById('cy-hora')?.value;
    const odc_id    = document.getElementById('cy-odc')?.value;
    const odcEl     = document.getElementById('cy-odc');
    const odc_txt   = odcEl ? odcEl.options[odcEl.selectedIndex]?.text : '';
    if (!proveedor || !fecha || !hora) return WMS.toast('warning','Proveedor, Fecha y Hora son requeridos');
    const body = {
      proveedor, fecha, hora_programada: hora,
      odc_id: odc_id||null,
      odc: odc_id ? odc_txt.split(' — ')[0] : null,
      tipo_vehiculo: document.getElementById('cy-vehiculo')?.value,
      cantidad_cajas: parseInt(document.getElementById('cy-cajas')?.value||'0'),
      kilos: parseFloat(document.getElementById('cy-kilos')?.value||'0'),
      notas: document.getElementById('cy-notas')?.value,
      estado: document.getElementById('cy-estado')?.value || 'Programada',
    };
    try {
      if (id) await API.put('/citas/'+id, body);
      else     await API.post('/citas', body);
      WMS.closeModal();
      WMS.toast('success', id ? 'Cita actualizada' : 'Cita programada');
      this.show_citas();
    } catch(e) { WMS.toast('error','Error guardando cita'); }
  },

  async editarCita(id) {
    const r = await API.get('/citas');
    const citas = r.data||r||[];
    const c = citas.find(x=>x.id==id);
    if (!c) return WMS.toast('error','Cita no encontrada');
    
    // Sanitizar fecha para input type="date" (ISO 8601 -> YYYY-MM-DD)
    const fechaLimpia = (c.fecha || '').split('T')[0];

    WMS.showModal('Editar Cita YMS', `
      <div class="modal-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">Proveedor *</label>
            <input id="cy-prov" class="form-control" value="${WMS.esc(c.proveedor||'')}">
          </div>
          <div class="form-group">
            <label class="form-label">Fecha *</label>
            <input id="cy-fecha" type="date" class="form-control" value="${fechaLimpia}">
          </div>
          <div class="form-group">
            <label class="form-label">Hora *</label>
            <input id="cy-hora" type="time" class="form-control" value="${(c.hora_programada||'08:00').substring(0,5)}">
          </div>
          <div class="form-group">
            <label class="form-label">ODC Vinculada</label>
            <select id="cy-odc" class="form-control">
              <option value="">Sin vincular</option>
              ${c.odc_id?`<option value="${c.odc_id}" selected>${WMS.esc(c.odc||'ODC Seleccionada')}</option>`:''}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Tipo de Vehículo</label>
            <select id="cy-vehiculo" class="form-control">
              ${['Camión Sencillo','Camión Doble Troque','Furgón','Camioneta','Tractomula'].map(v=>`<option${v===c.tipo_vehiculo?' selected':''}>${v}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Estado</label>
            <select id="cy-estado" class="form-control" ${c.estado==='Completada'?'disabled':''}>
              ${['Programada','EnPatio','EnCurso','Completada','Cancelada'].map(s=>`<option value="${s}"${s===c.estado?' selected':''}>${s}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Cajas Est.</label>
            <input id="cy-cajas" type="number" class="form-control" value="${c.cantidad_cajas||0}">
          </div>
          <div class="form-group">
            <label class="form-label">Kilos Est.</label>
            <input id="cy-kilos" type="number" class="form-control" value="${c.kilos||0}">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">Notas</label>
            <textarea id="cy-notas" class="form-control" rows="2">${WMS.esc(c.notas||'')}</textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" onclick="WMS.closeModal()">Cancelar</button>
        <button class="btn btn-primary" onclick="WMS_MODULES.recepcion._guardarCita(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>
      </div>`);
    
    // Cargar ODCs disponibles para el combo en edición
    if (c.estado === 'Programada') {
      API.get('/odc','estado=Confirmada,Borrador').then(r=>{
        const sel = document.getElementById('cy-odc');
        if (!sel) return;
        const currentId = c.odc_id;
        (r.data||r||[]).forEach(o=>{
          if (o.id == currentId) return;
          const opt = document.createElement('option');
          opt.value = o.id;
          opt.textContent = `${o.numero_odc} — ${o.proveedor?.razon_social||''}`;
          sel.appendChild(opt);
        });
      });
    }
  },

  async marcarLlegadaCita(id) {
    if (!confirm('¿Confirmar llegada a patio del proveedor?')) return;
    try {
      await API.post('/citas/'+id+'/llegada', {});
      WMS.toast('success','Llegada a patio registrada');
      this.show_citas();
    } catch(e) { WMS.toast('error','Error registrando llegada'); }
  },

  async completarCita(id) {
    WMS.showModal(
      'Completar Descargue — Cita YMS',
      `<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group">
            <label class="form-label">Tipo de Descargue</label>
            <select id="cc-tipo" class="form-control">
              <option>Paletizado</option><option>Granel</option><option>Mixto</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Evaluación Proveedor (1-5)</label>
            <select id="cc-eval" class="form-control">
              <option value="5">⭐⭐⭐⭐⭐ Excelente</option>
              <option value="4">⭐⭐⭐⭐ Bueno</option>
              <option value="3">⭐⭐⭐ Regular</option>
              <option value="2">⭐⭐ Deficiente</option>
              <option value="1">⭐ Muy Deficiente</option>
            </select>
          </div>
        </div>`,
      `<button class="btn btn-ghost" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-success" onclick="WMS_MODULES.recepcion._completarCitaOK(${id})"><i class="fa-solid fa-check"></i> Completar</button>`
    );
  },

  async _completarCitaOK(id) {
    try {
      await API.post('/citas/'+id+'/completar', {
        tipo_descargue: document.getElementById('cc-tipo')?.value,
        evaluacion:     parseInt(document.getElementById('cc-eval')?.value||'5'),
      });
      WMS.closeModal();
      WMS.toast('success','Cita completada exitosamente');
      this.show_citas();
    } catch(e) { WMS.toast('error','Error completando cita'); }
  },

  async cancelarCita(id) {
    if (!confirm('¿Cancelar esta cita?')) return;
    try {
      await API.delete('/citas/'+id);
      WMS.toast('success','Cita cancelada');
      this.show_citas();
    } catch(e) { WMS.toast('error','Error'); }
  },

  // ══════════════════════════════════════════════════════════════════════
  // DASHBOARD RECEPCIÓN — Info operativa en tiempo real (sin tendencias)
  // ══════════════════════════════════════════════════════════════════════
  async show_dashboard(silent=false) {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_dashboard()"><i class="fa-solid fa-sync"></i> Actualizar</button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion._resetDashboardFilters()"><i class="fa-solid fa-broom"></i> Limpiar filtros</button>
    `);
    if (!silent) WMS.spinner();
    try {
      const query = this._dashboardQuery();
      const [panelR, dashR, connR] = await Promise.all([
        API.get('/recepcion/control-panel', query),
        API.get('/recepcion/dashboard'),
        API.get('/system/connection-info')
      ]);
      const panel = panelR.data || panelR || {};
      const dash = dashR.data || dashR || {};
      const conn = connR.data || connR || {};
      const kpis = panel.kpis || {};
      const odcs = Array.isArray(panel.odcs) ? panel.odcs : [];
      const ranking = Array.isArray(panel.ranking_auxiliares) ? panel.ranking_auxiliares : [];
      const filters = panel.filters || {};
      const activas = Array.isArray(dash.activas) ? dash.activas : [];
      const tendencia = dash.tendencia || [];
      const categoriasStats = dash.categorias_stats || [];
      const qrUrl = conn.mobile_url ? `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(conn.mobile_url)}` : '';

      WMS.setContent(`
        <div class="inv-commander-root animate-fade-in" style="padding:20px; background:#f8fafc; min-height:calc(100vh - 120px); overflow:auto;">
          
          <!-- Dashboard Filters -->
          <div style="background:#fff; border-radius:4px; padding:20px; border:1px solid #e2e8f0; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
              <div style="font-weight:900; color:#0f172a; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-sliders" style="color:var(--cmd-blue);"></i> Control de Filtros
              </div>
              <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
                  ${this._renderDashboardFilter('odc', filters.odcs || [], 'numero_odc')}
                  ${this._renderDashboardFilter('auxiliar', filters.auxiliares || [], 'nombre')}
                  ${this._renderDashboardFilter('proveedor', filters.proveedores || [], 'nombre')}
                  ${this._renderDashboardFilter('categoria', filters.categorias || [], 'nombre')}
              </div>
          </div>

          <!-- KPI Cards Row -->
          <div class="kpi-dashboard-row">
            <div class="kpi-dashboard-card blue">
              <div class="kpi-dash-icon"><i class="fa-solid fa-file-invoice"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">ODCs Abiertas</span>
                <span class="kpi-dash-value">${WMS.formatNum(kpis.total_odcs||0)}</span>
                <span class="kpi-dash-sub">Pendientes de cierre</span>
              </div>
            </div>
            <div class="kpi-dashboard-card purple">
              <div class="kpi-dash-icon"><i class="fa-solid fa-layer-group"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">Líneas en Proceso</span>
                <span class="kpi-dash-value">${WMS.formatNum(kpis.total_lineas||0)}</span>
                <span class="kpi-dash-sub">Operación activa</span>
              </div>
            </div>
            <div class="kpi-dashboard-card green">
              <div class="kpi-dash-icon"><i class="fa-solid fa-truck-ramp-box"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">% Recibo / Ref</span>
                <span class="kpi-dash-value">${kpis.pct_recibo_referencia || 0}%</span>
                <span class="kpi-dash-sub">Avance por referencia</span>
              </div>
            </div>
            <div class="kpi-dashboard-card magenta">
              <div class="kpi-dash-icon"><i class="fa-solid fa-percent"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">% Recibo / Cant</span>
                <span class="kpi-dash-value">${kpis.pct_recibo_cantidad || 0}%</span>
                <span class="kpi-dash-sub">Eficiencia de volumen</span>
              </div>
            </div>
            <div class="kpi-dashboard-card amber">
              <div class="kpi-dash-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">Líneas Faltantes</span>
                <span class="kpi-dash-value">${WMS.formatNum(kpis.total_lineas_faltantes||0)}</span>
                <span class="kpi-dash-sub">Diferencias de stock</span>
              </div>
            </div>
            <div class="kpi-dashboard-card red">
              <div class="kpi-dash-icon"><i class="fa-solid fa-calendar-day"></i></div>
              <div class="kpi-dash-info">
                <span class="kpi-dash-label">Próximos Vencer</span>
                <span class="kpi-dash-value">${WMS.formatNum(kpis.proximos_vencer||0)}</span>
                <span class="kpi-dash-sub">Plazo < 60 días</span>
              </div>
            </div>
          </div>

          <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(350px, 1fr)); gap:20px; margin-bottom:24px;">
            <div style="background:#fff; border-radius:4px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
              <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-chart-pie" style="color:#7c3aed;"></i> Distribución por Categoría
              </div>
              <canvas id="categoryReceivedChart" style="max-height:280px;"></canvas>
            </div>
            <div style="background:#fff; border-radius:4px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
              <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-chart-line" style="color:var(--cmd-green);"></i> Tendencia de Recepción (7d)
              </div>
              <canvas id="recepcionTrendChart" style="max-height:280px;"></canvas>
            </div>
            <div style="background:#fff; border-radius:4px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
              <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-trophy" style="color:#f59e0b;"></i> Líderes de Recepción
              </div>
              <div style="display:flex; flex-direction:column; gap:12px;">
                ${ranking.slice(0, 5).map((aux, idx) => `
                  <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:#f8fafc; border-radius:4px; border-left:4px solid ${idx===0?'#f59e0b':'#e2e8f0'};">
                    <div style="display:flex; align-items:center; gap:10px;">
                      <span style="font-weight:900; color:#64748b; font-size:0.8rem;">#${idx+1}</span>
                      <div style="font-weight:800; color:#0f172a; font-size:0.9rem;">${WMS.esc(aux.nombre)}</div>
                    </div>
                    <div style="font-size:0.85rem; color:var(--cmd-blue); font-weight:900;">${WMS.formatNum(aux.total_unidades_recibidas||0)} <span style="font-size:0.7rem; font-weight:600; color:#94a3b8; text-transform:uppercase;">und</span></div>
                  </div>`).join('')}
              </div>
            </div>
          </div>

          <div style="background:#fff; border-radius:4px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #f1f5f9;">
              <div style="font-weight:900; color:#0f172a; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                  <i class="fa-solid fa-list-check" style="color:#7c3aed;"></i> Monitoreo de Órdenes (ODC)
              </div>
              <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <div class="search-bar" style="max-width:280px; background:#f8fafc;">
                  <i class="fa-solid fa-search"></i>
                  <input id="dash-filter" placeholder="Buscar ODC o proveedor..." oninput="WMS_MODULES.recepcion._filterDashTable(this.value)">
                </div>
                <select id="dash-estado" class="form-control" style="width:180px; background:#f8fafc;" onchange="WMS_MODULES.recepcion._filterDashTable(document.getElementById('dash-filter').value)">
                  <option value="">Todos los estados</option>
                  <option>Confirmada</option><option>En Proceso</option>
                </select>
                <span style="font-size:0.75rem; color:#6366f1; font-weight:800; padding:6px 14px; background:#e0e7ff; border-radius:99px; text-transform:uppercase; letter-spacing:0.5px;">Panel Operativo</span>
              </div>
            </div>
            ${WMS.hasPermiso('recepcion', 'admin')?`
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:12px;" id="dash-odc-table">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">N° ODC</th>
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Proveedor</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Estado</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Líneas</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">% Avance</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Recibido</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Faltante</th>
                </tr></thead>
                <tbody id="dash-odc-tbody">
                  ${odcs.length === 0
                    ? '<tr><td colspan="8" style="text-align:center;padding:24px;color:#94a3b8;">Sin órdenes</td></tr>'
                    : odcs.map((o,i) => {
                        const det    = o.detalles||[];
                        const sol    = det.reduce((a,d)=>a+Number(d.cantidad_solicitada||0),0);
                        const rec    = det.reduce((a,d)=>a+Number(d.cantidad_recibida||0),0);
                        const falt   = Math.max(0, sol-rec);
                        const pct    = sol>0 ? Math.round((rec/sol)*100) : (o.estado==='Cerrada'?100:0);
                        const stCol  = {Confirmada:'#7c3aed',Borrador:'#64748b','En Proceso':'#2563eb',Cerrada:'#059669'}[o.estado]||'#64748b';
                        const stBg   = {Confirmada:'#f5f3ff',Borrador:'#f1f5f9','En Proceso':'#eff6ff',Cerrada:'#f0fdf4'}[o.estado]||'#f1f5f9';
                        return `<tr class="dash-row" data-odc="${(o.numero_odc||'').toLowerCase()}" data-prov="${(o.proveedor?.nombre||'').toLowerCase()}" data-estado="${o.estado||''}" style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                          <td style="padding:8px 12px;"><span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">${WMS.esc(o.numero_odc||'-')}</span></td>
                          <td style="padding:8px 12px;font-weight:600;color:#1e3a5f;">${WMS.esc(o.proveedor?.nombre||'-')}</td>
                          <td style="padding:8px 12px;text-align:center;"><span style="background:${stBg};color:${stCol};padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;">${WMS.esc(o.estado)}</span></td>
                          <td style="padding:8px 12px;text-align:center;font-weight:700;">${o.detalles_count||(det.length)||0}</td>
                          <td style="padding:8px 12px;">
                            <div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden;margin-bottom:2px;">
                              <div style="background:${pct>=100?'#059669':pct>50?'#2563eb':'#f59e0b'};height:100%;width:${pct}%;border-radius:99px;"></div>
                            </div>
                            <div style="text-align:center;font-size:10px;font-weight:700;color:#1e3a5f;">${pct}%</div>
                          </td>
                          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#059669;">${rec.toLocaleString()}</td>
                          <td style="padding:8px 12px;text-align:center;font-weight:700;color:${falt>0?'#dc2626':'#059669'};">${falt>0?falt.toLocaleString():'✓'}</td>
                        </tr>`;
                      }).join('')}
                </tbody>
              </table>
            </div>
            ` : `<div class="p-20 text-center text-muted">Panel restringido al Administrador</div>`}
          </div>

          <div style="background:#fff;border-radius:4px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
              <div style="font-weight:800;color:#1e3a5f;"><i class="fa-solid fa-magnifying-glass-chart" style="color:#059669;margin-right:6px;"></i>Análisis por ODC — Tiempos y Faltantes por Línea</div>
              <select id="dash-odc-f" class="form-control" style="max-width:370px;" onchange="WMS_MODULES.recepcion.renderAnalyticsDetail(this.value)">
                <option value="">Seleccione una ODC...</option>
                ${odcs.map(o=>`<option value="${o.id}">${WMS.esc(o.numero_odc)} — ${WMS.esc(o.proveedor?.nombre||'')}</option>`).join('')}
              </select>
            </div>
            <div id="analytics-detail-container"><div style="text-align:center;padding:30px;color:#94a3b8;font-style:italic;">Seleccione una orden para ver sus métricas por línea.</div></div>
          </div>
        </div>`);

      this.buildCategoryReceivedChart(categoriasStats);
      this.buildRecepcionTrendChart(tendencia);
    } catch(e) { console.error(e); WMS.toast('error','Error cargando dashboard'); }
  },

  _dashboardQuery() {
    const params = new URLSearchParams();
    Object.entries(this._dashboardFilters).forEach(([key, value]) => {
      if (value) params.append(key, value);
    });
    return params.toString();
  },

  _setDashboardFilter(key, value) {
    this._dashboardFilters[key] = value || '';
    this.show_dashboard();
  },

  _resetDashboardFilters() {
    this._dashboardFilters = { odc_id:'', auxiliar_id:'', proveedor_id:'', categoria_id:'' };
    this.show_dashboard();
  },

  _renderDashboardFilter(key, items, labelKey) {
    const labels = { odc:'ODC', auxiliar:'Auxiliar', proveedor:'Proveedor', categoria:'Categoría' };
    const selected = this._dashboardFilters[`${key}_id`] || '';
    return `
      <div>
        <label style="display:block;margin-bottom:6px;font-size:.75rem;font-weight:600;color:#475569;">${labels[key] || key}</label>
        <select id="dash-filter-${key}" class="form-control" onchange="WMS_MODULES.recepcion._setDashboardFilter('${key}_id', this.value)">
          <option value="">Todos</option>
          ${items.map(item => `<option value="${item.id}" ${item.id == selected ? 'selected' : ''}>${WMS.esc(item[labelKey] || item.nombre || item.numero_odc || '')}</option>`).join('')}
        </select>
      </div>`;
  },

  _filterDashTable(q) {
    const estado = document.getElementById('dash-estado')?.value?.toLowerCase() || '';
    const rows = document.querySelectorAll('#dash-odc-tbody .dash-row');
    rows.forEach(row => {
      const odc  = row.dataset.odc  || '';
      const prov = row.dataset.prov || '';
      const est  = row.dataset.estado?.toLowerCase() || '';
      const matchQ = !q || odc.includes(q.toLowerCase()) || prov.includes(q.toLowerCase());
      const matchE = !estado || est === estado;
      row.style.display = matchQ && matchE ? '' : 'none';
    });
  },

  buildCategoryReceivedChart(stats) {
    const ctx = document.getElementById('categoryReceivedChart');
    if (!ctx) return;
    if (this._catChart) this._catChart.destroy();
    
    // Solo mostrar top 8 para no saturar si hay muchas categorías
    const topStats = stats.slice(0, 8);
    
    this._catChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: topStats.map(s => s.categoria),
        datasets: [{
          label: 'Unidades',
          data: topStats.map(s => s.total),
          backgroundColor: '#3b82f6',
          borderRadius: 4
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, grid: { display: false } },
          y: { grid: { display: false } }
        }
      }
    });
  },

  buildRecepcionTrendChart(tendencia) {
    const ctx = document.getElementById('recepcionTrendChart');
    if (!ctx) return;
    if (this.trendChart) this.trendChart.destroy();
    const labels = tendencia.map(item => item.fecha);
    const values = tendencia.map(item => Number(item.total) || 0);
    this.trendChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Recepciones cerradas',
          data: values,
          borderColor: '#059669',
          backgroundColor: 'rgba(16, 185, 129, 0.2)',
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointBackgroundColor: '#059669'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { stepSize: 1 } },
          x: { grid: { display: false } }
        }
      }
    });
  },

  async renderAnalyticsDetail(odcId) {
    if (!odcId) return;
    const cont = document.getElementById('analytics-detail-container');
    if (!cont) return;
    cont.innerHTML = '<div style="text-align:center;padding:20px;"><div class="spinner"></div></div>';
    try {
      const r = await API.get('/odc/'+odcId);
      const odc = r.data||r||{};
      const detalles = odc.detalles||[];

      const totalSol   = detalles.reduce((a,d)=>a+Number(d.cantidad_solicitada||0),0);
      const totalRec   = detalles.reduce((a,d)=>a+Number(d.cantidad_recibida||0),0);
      const totalFalt  = Math.max(0, totalSol - totalRec);
      const pctGlobal  = totalSol > 0 ? Math.round((totalRec/totalSol)*100) : 0;
      const lineasOK   = detalles.filter(d=>Number(d.cantidad_recibida||0)>=Number(d.cantidad_solicitada||0)).length;
      const lineasFalt = detalles.length - lineasOK;

      cont.innerHTML = `
        <!-- Resumen ODC -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px;">
          ${[
            {icon:'fa-hashtag',          val:detalles.length,                    lab:'Total Líneas',          color:'#2563eb', bg:'#eff6ff'},
            {icon:'fa-circle-check',     val:lineasOK,                           lab:'Líneas Completas',      color:'#059669', bg:'#f0fdf4'},
            {icon:'fa-triangle-exclamation', val:lineasFalt,                     lab:'Líneas con Faltante',   color:'#dc2626', bg:'#fef2f2'},
            {icon:'fa-boxes-stacked',    val:totalRec.toLocaleString(),           lab:'Total Recibido',        color:'#7c3aed', bg:'#f5f3ff'},
            {icon:'fa-ban',              val:totalFalt.toLocaleString(),          lab:'Total Faltante',        color:'#d97706', bg:'#fffbeb'},
          ].map(k=>`
            <div style="background:#f8fafc;border-radius:4px;padding:14px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:10px;">
              <div style="width:38px;height:38px;border-radius:4px;background:${k.bg};display:flex;align-items:center;justify-content:center;color:${k.color};font-size:16px;"><i class="fa-solid ${k.icon}"></i></div>
              <div><div style="font-size:20px;font-weight:900;color:#1e3a5f;">${k.val}</div><div style="font-size:10px;font-weight:600;color:#64748b;">${k.lab}</div></div>
            </div>`).join('')}
        </div>
        <!-- Barra progreso global -->
        <div style="margin-bottom:18px;padding:14px;background:#f8fafc;border-radius:4px;border:1px solid #e2e8f0;">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
            <span style="font-weight:700;color:#1e3a5f;font-size:13px;">Avance Global de Recepción</span>
            <span style="font-weight:900;color:${pctGlobal>=100?'#059669':'#2563eb'};font-size:16px;">${pctGlobal}%</span>
          </div>
          <div style="background:#e2e8f0;border-radius:99px;height:14px;overflow:hidden;">
            <div style="background:${pctGlobal>=100?'#059669':pctGlobal>70?'#2563eb':'#f59e0b'};height:100%;width:${pctGlobal}%;border-radius:99px;transition:width .5s;"></div>
          </div>
        </div>
        <!-- Tabla por línea -->
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="background:#1e3a5f;color:#fff;">
              <th style="padding:8px 12px;text-align:left;">SKU</th>
              <th style="padding:8px 12px;text-align:left;">Producto</th>
              <th style="padding:8px 12px;text-align:center;">Solicitado</th>
              <th style="padding:8px 12px;text-align:center;">Recibido</th>
              <th style="padding:8px 12px;text-align:center;">Faltante</th>
              <th style="padding:8px 12px;text-align:center;">% Avance</th>
              <th style="padding:8px 12px;text-align:center;">Aprobado</th>
              <th style="padding:8px 12px;text-align:center;">Novedad</th>
            </tr></thead>
            <tbody>
              ${detalles.map((d,i)=>{
                const sol  = Number(d.cantidad_solicitada||0);
                const rec  = Number(d.cantidad_recibida||0);
                const falt = Math.max(0, sol-rec);
                const pct  = sol>0 ? Math.round((rec/sol)*100) : 0;
                return `<tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                  <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#64748b;">${WMS.esc(d.producto?.codigo_interno||'-')}</td>
                  <td style="padding:8px 12px;font-weight:600;color:#1e3a5f;">${WMS.esc(d.producto?.nombre||'-')}</td>
                  <td style="padding:8px 12px;text-align:center;">${sol.toLocaleString()}</td>
                  <td style="padding:8px 12px;text-align:center;font-weight:700;color:#059669;">${rec.toLocaleString()}</td>
                  <td style="padding:8px 12px;text-align:center;font-weight:700;color:${falt>0?'#dc2626':'#059669'};">${falt>0?falt.toLocaleString():'✓'}</td>
                  <td style="padding:8px 12px;">
                    <div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden;">
                      <div style="background:${pct>=100?'#059669':pct>50?'#2563eb':'#f59e0b'};height:100%;width:${pct}%;border-radius:99px;"></div>
                    </div>
                    <div style="text-align:center;font-size:10px;font-weight:700;color:#1e3a5f;">${pct}%</div>
                  </td>
                  <td style="padding:8px 12px;text-align:center;">${d.aprobado_admin?'<span style="color:#059669;font-weight:700;">✓ Aprobado</span>':'<span style="color:#94a3b8;">Pendiente</span>'}</td>
                  <td style="padding:8px 12px;text-align:center;font-size:11px;color:#d97706;">${d.novedad_motivo?WMS.esc(d.novedad_motivo):'—'}</td>
                </tr>`;
              }).join('')}
            </tbody>
            <tfoot>
              <tr style="background:#f0fdf4;font-weight:900;border-top:2px solid #bbf7d0;">
                <td colspan="2" style="padding:8px 12px;color:#1e3a5f;">TOTALES</td>
                <td style="padding:8px 12px;text-align:center;">${totalSol.toLocaleString()}</td>
                <td style="padding:8px 12px;text-align:center;color:#059669;">${totalRec.toLocaleString()}</td>
                <td style="padding:8px 12px;text-align:center;color:${totalFalt>0?'#dc2626':'#059669'};">${totalFalt>0?totalFalt.toLocaleString():'✓'}</td>
                <td colspan="3" style="padding:8px 12px;text-align:center;color:${pctGlobal>=100?'#059669':'#2563eb'};font-size:14px;">${pctGlobal}%</td>
              </tr>
            </tfoot>
          </table>
        </div>`;
    } catch(e) { console.error(e); cont.innerHTML = '<div style="padding:20px;color:#dc2626;">Error cargando detalle</div>'; }
  },

  // ══════════════════════════════════════════════════════════════════════
  // DEVOLUCIÓN PROVEEDOR — Amarrada a la ODC que se estaba recibiendo
  // ══════════════════════════════════════════════════════════════════════
  async show_devoluciones() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.recepcion.show_devoluciones()"><i class="fa-solid fa-sync"></i> Actualizar</button>
      <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.recepcion.nuevaDevolucion()"><i class="fa-solid fa-rotate-left"></i> Nueva Devolución</button>`);
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones');
      const items = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []);
      WMS.setContent(`
        <div style="padding:20px;overflow:auto;height:calc(100vh - 120px);">
          <!-- KPI strip -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">
            ${[
              {icon:'fa-rotate-left',   val:items.length,                                        lab:'Total Devoluciones',  color:'#dc2626', bg:'#fef2f2'},
              {icon:'fa-triangle-exclamation', val:items.filter(d=>d.tipo==='AProveedorAveria').length,  lab:'Por Avería',          color:'#d97706', bg:'#fffbeb'},
              {icon:'fa-clock-rotate-left', val:items.filter(d=>d.tipo==='AProveedorVencido').length, lab:'Por Vencimiento',     color:'#7c3aed', bg:'#f5f3ff'},
              {icon:'fa-arrow-rotate-right',val:items.filter(d=>d.tipo==='ReingresoBuenEstado').length,lab:'Reingreso',          color:'#059669', bg:'#f0fdf4'},
            ].map(k=>`
              <div style="background:#fff;border-radius:4px;padding:16px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:4px;background:${k.bg};display:flex;align-items:center;justify-content:center;color:${k.color};font-size:20px;"><i class="fa-solid ${k.icon}"></i></div>
                <div><div style="font-size:24px;font-weight:900;color:#1e3a5f;">${k.val}</div><div style="font-size:11px;font-weight:600;color:#64748b;">${k.lab}</div></div>
              </div>`).join('')}
          </div>

          <!-- Tabla de devoluciones -->
          <div style="background:#fff;border-radius:4px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);">
            <div style="font-weight:800;color:#1e3a5f;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
              <i class="fa-solid fa-list" style="color:#dc2626;margin-right:6px;"></i>Historial de Devoluciones a Proveedor
            </div>
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr style="background:#f8fafc;">
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">N° Devolución</th>
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Fecha</th>
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Proveedor</th>
                  <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">ODC Vinculada</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Tipo</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Fotos</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Estado</th>
                  <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Acciones</th>
                </tr></thead>
                <tbody>
                  ${items.length === 0
                    ? '<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;font-style:italic;">No hay devoluciones registradas</td></tr>'
                    : items.map((d,i) => {
                        const tipoLabel = {Faltante:'Faltante',Averia:'Avería',Calidad:'Calidad',AProveedorAveria:'Avería Proveedor',DevolucionRecepcion:'Recepción'}[d.tipo]||d.tipo;
                        const tipoCol   = {Faltante:'#d97706',Averia:'#dc2626',Calidad:'#7c3aed',AProveedorAveria:'#dc2626',DevolucionRecepcion:'#0891b2'}[d.tipo]||'#64748b';
                        const tipoBg    = {Faltante:'#fffbeb',Averia:'#fef2f2',Calidad:'#f5f3ff',AProveedorAveria:'#fef2f2',DevolucionRecepcion:'#e0f2fe'}[d.tipo]||'#f1f5f9';
                        const fotos = d.fotos_json ? (Array.isArray(d.fotos_json) ? d.fotos_json : JSON.parse(d.fotos_json||'[]')) : [];
                        return `<tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                          <td style="padding:8px 12px;"><span style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;">${WMS.esc(d.numero_devolucion||'-')}</span></td>
                          <td style="padding:8px 12px;color:#64748b;">${WMS.formatDate(d.fecha_movimiento||d.created_at)}</td>
                          <td style="padding:8px 12px;font-weight:600;color:#1e3a5f;">${WMS.esc(d.proveedor||'-')}</td>
                          <td style="padding:8px 12px;">${d.odc_id ? `<span style="background:#e0f2fe;color:#0891b2;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;cursor:pointer;" onclick="WMS.nav('recepcion','odc')">${WMS.esc(d.numero_odc_vinculada||'ODC #'+d.odc_id)}</span>` : '<span style="color:#94a3b8;">—</span>'}</td>
                          <td style="padding:8px 12px;text-align:center;"><span style="background:${tipoBg};color:${tipoCol};padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;">${tipoLabel}</span></td>
                          <td style="padding:8px 12px;text-align:center;">${fotos.length > 0 ? `<button class="btn btn-xs btn-warning-soft" onclick="WMS_MODULES.recepcion.verFotosDevolucion(${d.id})" title="${fotos.length} foto(s)"><i class="fa-solid fa-camera"></i> ${fotos.length}</button>` : '<span style="color:#94a3b8;">—</span>'}</td>
                          <td style="padding:8px 12px;text-align:center;"><span style="background:#f0fdf4;color:#059669;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;">${WMS.esc(d.estado||'-')}</span></td>
                          <td style="text-align:center;padding:8px 12px;">
                            <div style="display:flex;gap:4px;justify-content:center;">
                              <button class="btn btn-xs btn-primary-soft" onclick="WMS_MODULES.recepcion.verDevolucion(${d.id})" title="Ver detalle"><i class="fa-solid fa-eye"></i></button>
                              <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.recepcion.imprimirDevolucion(${d.id})" title="Imprimir"><i class="fa-solid fa-print"></i></button>
                            </div>
                          </td>
                        </tr>`;
                      }).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch(e) { console.error(e); WMS.toast('error','Error cargando devoluciones'); }
  },

  async verDevolucion(id) {
    WMS.spinner();
    try {
      const r   = await API.get('/devoluciones/' + id);
      const dev = r.data || r;
      const detalles = dev.detalles || dev.devolucion_detalles || [];
      const stColor  = { Pendiente:'#f59e0b', Procesada:'#10b981', Rechazada:'#ef4444', EnRevision:'#3b82f6' };
      WMS.showModal(`Devolución — ${WMS.esc(dev.numero_devolucion || '#' + id)}`, `
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
          <div><label class="form-label">N° Devolución</label><p style="font-weight:700;">${WMS.esc(dev.numero_devolucion||'-')}</p></div>
          <div><label class="form-label">Proveedor</label><p>${WMS.esc(dev.proveedor||dev.proveedor_nombre||'-')}</p></div>
          <div><label class="form-label">Fecha</label><p>${WMS.formatDate(dev.fecha_movimiento||dev.fecha||dev.created_at)}</p></div>
          <div><label class="form-label">Estado</label>
            <p><span style="background:${stColor[dev.estado]||'#64748b'};color:#fff;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:700;">${WMS.esc(dev.estado||'-')}</span></p>
          </div>
          <div><label class="form-label">ODC Relacionada</label><p>${WMS.esc(dev.numero_odc||'-')}</p></div>
          <div><label class="form-label">Observaciones</label><p style="font-size:12px;">${WMS.esc(dev.observaciones||'-')}</p></div>
        </div>
        <div class="table-container" style="max-height:320px;overflow-y:auto;">
          <table class="erp-table">
            <thead><tr><th>Producto</th><th style="text-align:center;">Cant. Devuelta</th><th>Motivo</th></tr></thead>
            <tbody>
              ${detalles.length ? detalles.map(d => `<tr>
                <td>
                  <div style="font-weight:700;">${WMS.esc(d.producto?.nombre || d.producto_nombre || '-')}</div>
                  <div style="font-size:10px;color:#64748b;">${WMS.esc(d.producto?.codigo_interno || d.codigo || '')}</div>
                </td>
                <td style="text-align:center;font-weight:700;">${WMS.formatNum(d.cantidad||0)}</td>
                <td style="font-size:12px;">${WMS.esc(d.motivo||d.observaciones||'-')}</td>
              </tr>`).join('') : '<tr><td colspan="3" class="table-empty">Sin detalles</td></tr>'}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
         <button class="btn btn-info-soft" onclick="WMS.closeModal('generic-modal');WMS_MODULES.recepcion.verFotosDevolucion(${id})"><i class="fa-solid fa-images"></i> Ver Fotos</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.recepcion.imprimirDevolucion(${id})"><i class="fa-solid fa-print"></i> Imprimir</button>`
      );
    } catch(e) { WMS.toast('error', 'Error cargando devolución: ' + e.message); }
  },

  async verFotosDevolucion(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/'+id);
      const dev = r.data||r;
      const fotos = Array.isArray(dev.fotos_json) ? dev.fotos_json : JSON.parse(dev.fotos_json||'[]');
      if (!fotos.length) return WMS.toast('info','Esta devolución no tiene fotos');
      WMS.showModal('Evidencia Fotográfica — '+WMS.esc(dev.numero_devolucion), `
        <div style="padding:16px;">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">
            ${fotos.map((f,i) => `
              <div style="border-radius:4px;overflow:hidden;border:2px solid #e2e8f0;">
                <img src="/WMS_FENIX/public/${f}" style="width:100%;height:150px;object-fit:cover;cursor:pointer;" onclick="window.open(this.src,'_blank')" onerror="this.src='data:image/svg+xml,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;150&quot; height=&quot;150&quot;><rect fill=&quot;%23f1f5f9&quot; width=&quot;150&quot; height=&quot;150&quot;/><text x=&quot;50%&quot; y=&quot;50%&quot; text-anchor=&quot;middle&quot; dy=&quot;.3em&quot; fill=&quot;%2394a3b8&quot;>Foto ${i+1}</text></svg>'" alt="Foto ${i+1}">
                <div style="padding:4px 8px;font-size:10px;color:#64748b;font-weight:600;">Foto ${i+1}</div>
              </div>`).join('')}
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.recepcion.imprimirFotosDevolucion(${id})"><i class="fa-solid fa-print"></i> Imprimir Fotos</button>`
      );
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },

  async imprimirDevolucion(id) {
    const token = localStorage.getItem('wms_token')||'';
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/'+id);
      const dev = r.data||r;
      const detalles = dev.detalles||dev.devolucion_detalles||[];
      const fotos = Array.isArray(dev.fotos_json) ? dev.fotos_json : JSON.parse(dev.fotos_json||'[]');
      const w = window.open('','_blank','width=900,height=700');
      const css = `body{font-family:Arial,sans-serif;padding:20px;color:#1e293b;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #e2e8f0;padding:8px;font-size:12px;} th{background:#1e3a5f;color:#fff;} .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:3px solid #dc2626;padding-bottom:12px;} .title{font-size:22px;font-weight:800;color:#dc2626;} .firm{margin-top:40px;display:grid;grid-template-columns:1fr 1fr;gap:40px;} .firm-box{border-top:2px solid #1e293b;padding-top:6px;text-align:center;font-size:11px;color:#64748b;}`;
      const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Devolución ${dev.numero_devolucion}</title><style>${css}</style></head><body>
        <div class="header">
          <div><div class="title"><i>⟲</i> Devolución a Proveedor</div><div style="font-size:13px;font-weight:700;">N°: ${dev.numero_devolucion}</div></div>
          <div style="text-align:right;font-size:11px;color:#64748b;"><div>Fecha: ${WMS.formatDate(dev.fecha_movimiento||dev.created_at)}</div><div>Proveedor: ${WMS.esc(dev.proveedor||'')}</div>${dev.odc_id?'<div>ODC: #'+dev.odc_id+'</div>':''}</div>
        </div>
        <table><thead><tr><th>Producto</th><th>Lote</th><th>Cantidad</th><th>Motivo</th><th>Observación</th></tr></thead>
        <tbody>${detalles.map(d=>`<tr><td>${WMS.esc(d.producto?.nombre||d.nombre||'-')}</td><td>${WMS.esc(d.lote||'N/A')}</td><td style="text-align:center;font-weight:700;">${d.cantidad}</td><td>${WMS.esc(d.motivo||'-')}</td><td>${WMS.esc(d.detalle_motivo||'-')}</td></tr>`).join('')}</tbody></table>
        ${fotos.length?'<div style="margin-top:16px;font-size:11px;color:#dc2626;font-weight:700;">⚠ Esta devolución tiene '+fotos.length+' foto(s) de evidencia adjunta(s). Ver documento de evidencia fotográfica.</div>':''}
        <div class="firm">
          <div class="firm-box">Auxiliar de bodega<br><br><br>______________________</div>
          <div class="firm-box">Supervisión / Admin<br><br><br>______________________</div>
        </div>
      </body></html>`;
      w.document.write(html);
      w.document.close();
      w.focus();
      setTimeout(()=>w.print(),500);
    } catch(e) { WMS.toast('error','Error al preparar impresión: '+e.message); }
  },

  async imprimirFotosDevolucion(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/'+id);
      const dev = r.data||r;
      const fotos = Array.isArray(dev.fotos_json) ? dev.fotos_json : JSON.parse(dev.fotos_json||'[]');
      if (!fotos.length) return WMS.toast('info','Sin fotos para imprimir');
      const baseUrl = window.location.origin + '/WMS_FENIX/public/';
      const w = window.open('','_blank','width=900,height=700');
      const css = `body{font-family:Arial,sans-serif;padding:20px;color:#1e293b;} .header{text-align:center;border-bottom:3px solid #dc2626;padding-bottom:12px;margin-bottom:20px;} .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;} .foto-box{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;} .foto-box img{width:100%;height:200px;object-fit:cover;} .foto-label{padding:4px 8px;font-size:10px;color:#64748b;background:#f8fafc;text-align:center;font-weight:700;}`;
      const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Evidencia — ${dev.numero_devolucion}</title><style>${css}</style></head><body>
        <div class="header"><h2 style="color:#dc2626;margin:0;">Evidencia Fotográfica</h2><p style="margin:4px 0;font-size:13px;">Devolución: ${dev.numero_devolucion} | Proveedor: ${WMS.esc(dev.proveedor||'')} | Fecha: ${WMS.formatDate(dev.fecha_movimiento)}</p></div>
        <div class="grid">${fotos.map((f,i)=>`<div class="foto-box"><img src="${baseUrl+f}" alt="Foto ${i+1}" onerror="this.style.background='#f1f5f9';this.alt='Foto no disponible';"><div class="foto-label">Foto ${i+1} de ${fotos.length}</div></div>`).join('')}</div>
      </body></html>`;
      w.document.write(html);
      w.document.close();
      w.focus();
      setTimeout(()=>w.print(),800);
    } catch(e) { WMS.toast('error','Error: '+e.message); }
  },


  async nuevaDevolucion(odcIdPreload = null) {
    // Cargar ODCs para vincular
    WMS.spinner();
    try {
      const odcR = await API.get('/odc','limit=100&estado=En Proceso,Confirmada,Cerrada');
      const odcs = Array.isArray(odcR.data) ? odcR.data : (Array.isArray(odcR) ? odcR : []);
      const odcSelOpts = odcs.map(o => `<option value="${o.id}" data-prov="${WMS.esc(o.proveedor?.razon_social||'')}"${o.id==odcIdPreload?' selected':''}>${WMS.esc(o.numero_odc)} — ${WMS.esc(o.proveedor?.razon_social||'')}</option>`).join('');

      WMS.setContent(`
        <div style="padding:20px;overflow:auto;height:calc(100vh - 120px);">
          <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);max-width:960px;margin:0 auto;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #fecaca;">
              <div style="width:44px;height:44px;border-radius:4px;background:#fef2f2;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:20px;"><i class="fa-solid fa-rotate-left"></i></div>
              <div><h2 style="margin:0;font-size:18px;font-weight:800;color:#1e3a5f;">Nueva Devolución a Proveedor</h2><p style="margin:0;font-size:12px;color:#64748b;">Vincula la devolución con la ODC que se estaba recibiendo</p></div>
            </div>

            <!-- Cabecera -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
              <div class="form-group">
                <label class="form-label">ODC Vinculada *</label>
                <select id="dev-odc" class="form-control" onchange="WMS_MODULES.recepcion._onDevOdcChange(this)">
                  <option value="">Sin ODC (devolución libre)</option>
                  ${odcSelOpts}
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Proveedor *</label>
                <input id="dev-prov" class="form-control" placeholder="Nombre del proveedor">
              </div>
              <div class="form-group">
                <label class="form-label">Tipo de Devolución *</label>
                <select id="dev-tipo" class="form-control">
                  <option value="Faltante">Faltante</option>
                  <option value="Averia">Avería / Daño</option>
                  <option value="Calidad">Calidad (Vencido/Mal Estado)</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Motivo General</label>
                <textarea id="dev-motivo" class="form-control" rows="2" placeholder="Describe brevemente el motivo de la devolución..."></textarea>
              </div>
            </div>

            <!-- Líneas de devolución -->
            <div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
              <span style="font-weight:800;color:#1e3a5f;font-size:14px;"><i class="fa-solid fa-list" style="color:#dc2626;margin-right:6px;"></i>Productos a Devolver</span>
              <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.recepcion._addDevLine()"><i class="fa-solid fa-plus"></i> Agregar Línea</button>
            </div>
            <div id="dev-lines" style="border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:20px;">
              <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr style="background:#fef2f2;">
                  <th style="padding:8px 10px;text-align:left;color:#64748b;font-weight:700;">Producto</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:90px;">Cantidad</th>
                  <th style="padding:8px 10px;text-align:left;color:#64748b;font-weight:700;width:120px;">Lote</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:130px;">Motivo</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:130px;">Destino</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:40px;"></th>
                </tr></thead>
                <tbody id="dev-lines-body">
                  <tr id="dev-empty-row"><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">
                    Seleccione una ODC arriba para cargar sus líneas, o agregue productos manualmente
                  </td></tr>
                </tbody>
              </table>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
              <button class="btn btn-ghost" onclick="WMS_MODULES.recepcion.show_devoluciones()"><i class="fa-solid fa-arrow-left"></i> Cancelar</button>
              <button class="btn btn-danger" onclick="WMS_MODULES.recepcion._guardarDevolucion()"><i class="fa-solid fa-save"></i> Registrar Devolución</button>
            </div>
          </div>
        </div>`);

      // Cargar automáticamente si viene con ODC preseleccionada
      if (odcIdPreload) {
        const sel = document.getElementById('dev-odc');
        if (sel) {
          sel.value = odcIdPreload;
          this._onDevOdcChange(sel);
        }
      }
    } catch(e) { console.error(e); WMS.toast('error','Error iniciando devolución'); }
  },

  async _onDevOdcChange(sel) {
    const odcId = sel.value;
    const provInput = document.getElementById('dev-prov');
    const opt = sel.options[sel.selectedIndex];
    if (opt.dataset.prov && provInput) provInput.value = opt.dataset.prov;

    const tbody = document.getElementById('dev-lines-body');
    if (!odcId || !tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;"><div class="spinner"></div></td></tr>';
    try {
      const r = await API.get('/odc/'+odcId);
      const odc = r.data||r||{};
      const detalles = odc.detalles||[];

      if (detalles.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">Esta ODC no tiene líneas</td></tr>';
        return;
      }

      tbody.innerHTML = detalles.map((d,i) => `
        <tr id="dev-line-${i}" data-pid="${d.producto_id||''}" style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
          <td style="padding:8px 10px;">
            <div style="font-weight:700;color:#1e3a5f;font-size:12px;">${WMS.esc(d.producto?.nombre||'-')}</div>
            <div style="font-size:10px;color:#64748b;">${WMS.esc(d.producto?.codigo_interno||'')} | Recibido: ${d.cantidad_recibida||0} / ${d.cantidad_solicitada||0}</div>
            <input type="hidden" class="dev-pid" value="${d.producto_id||''}">
          </td>
          <td style="padding:6px 8px;text-align:center;">
            <input type="number" class="form-control dev-qty" style="width:80px;margin:0 auto;text-align:center;font-size:12px;" min="0" max="${d.cantidad_recibida||0}" value="0">
          </td>
          <td style="padding:6px 8px;">
            <input class="form-control dev-lote" style="font-size:12px;" value="${WMS.esc(d.lote||'')}">
          </td>
          <td style="padding:6px 8px;text-align:center;">
            <select class="form-control dev-motivo" style="font-size:11px;">
              <option value="Averia">Avería</option>
              <option value="Vencido">Vencido</option>
              <option value="ErrorProveedor">Error Proveedor</option>
              <option value="CalidadDeficiente">Baja Calidad</option>
              <option value="Otro">Otro</option>
            </select>
          </td>
          <td style="padding:6px 8px;text-align:center;">
            <select class="form-control dev-destino" style="font-size:11px;">
              <option value="DevolucionProveedor">Dev. Proveedor</option>
              <option value="InventarioObsoleto">Inventario Obsoleto</option>
              <option value="Reingreso">Reingreso</option>
            </select>
          </td>
          <td style="text-align:center;padding:6px;">
            <button class="btn btn-xs btn-danger-soft" onclick="this.closest('tr').remove()" title="Quitar línea"><i class="fa-solid fa-times"></i></button>
          </td>
        </tr>`).join('');
    } catch(e) { tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#dc2626;">Error cargando líneas</td></tr>'; }
  },

  _addDevLine() {
    const tbody = document.getElementById('dev-lines-body');
    const emptyRow = document.getElementById('dev-empty-row');
    if (emptyRow) emptyRow.remove();
    const idx = Date.now();
    const tr = document.createElement('tr');
    tr.id = 'dev-manual-'+idx;
    tr.style.borderBottom = '1px solid #f1f5f9';
    tr.innerHTML = `
      <td style="padding:6px 8px;">
        <input class="form-control dev-prod-name" style="font-size:12px;" placeholder="Nombre del producto">
        <input type="hidden" class="dev-pid" value="">
      </td>
      <td style="padding:6px 8px;text-align:center;">
        <input type="number" class="form-control dev-qty" style="width:80px;margin:0 auto;text-align:center;font-size:12px;" min="1" value="1">
      </td>
      <td style="padding:6px 8px;">
        <input class="form-control dev-lote" style="font-size:12px;" placeholder="Lote">
      </td>
      <td style="padding:6px 8px;text-align:center;">
        <select class="form-control dev-motivo" style="font-size:11px;">
          <option value="Averia">Avería</option>
          <option value="Vencido">Vencido</option>
          <option value="ErrorProveedor">Error Proveedor</option>
          <option value="CalidadDeficiente">Baja Calidad</option>
          <option value="Otro">Otro</option>
        </select>
      </td>
      <td style="padding:6px 8px;text-align:center;">
        <select class="form-control dev-destino" style="font-size:11px;">
          <option value="DevolucionProveedor">Dev. Proveedor</option>
          <option value="InventarioObsoleto">Inventario Obsoleto</option>
          <option value="Reingreso">Reingreso</option>
        </select>
      </td>
      <td style="text-align:center;padding:6px;">
        <button class="btn btn-xs btn-danger-soft" onclick="this.closest('tr').remove()"><i class="fa-solid fa-times"></i></button>
      </td>`;
    tbody.appendChild(tr);
  },

  async _guardarDevolucion() {
    const odcId       = document.getElementById('dev-odc')?.value;
    const razon       = document.getElementById('dev-razon')?.value?.trim() || 'Novedad en recepción';
    const rows        = document.querySelectorAll('#dev-lines-body tr');

    const detalles = [];
    rows.forEach(tr => {
      const qty = parseFloat(tr.querySelector('.dev-qty')?.value || '0');
      if (qty <= 0) return;
      detalles.push({
        producto_id : tr.querySelector('.dev-pid')?.value || null,
        nombre      : tr.querySelector('.dev-prod-name')?.value || '',
        cantidad    : qty,
        lote        : tr.querySelector('.dev-lote')?.value || '',
        motivo      : tr.querySelector('.dev-motivo')?.value || 'Otro',
        destino     : tr.querySelector('.dev-destino')?.value || 'DevolucionProveedor',
      });
    });

    if (!detalles.length) {
      WMS.toast('warning', 'Ingresa al menos una línea con cantidad mayor a 0');
      return;
    }

    WMS.spinner();
    try {
      await API.post('/devoluciones', {
        odc_id        : odcId || null,
        tipo          : 'DevolucionRecepcion',
        motivo_general: razon,
        detalles,
      });
      WMS.toast('success', 'Devolución registrada correctamente');
      WMS.closeModal('generic-modal');
      this.show_devoluciones();
    } catch(e) {
      WMS.toast('error', e.message || 'Error al guardar devolución');
    } finally {

    }
  },

  async reabrirODC(id) {
    if (!confirm('¿Está seguro de reabrir esta Orden de Compra? Volverá al estado "En Proceso" y se podrá recibir más mercancía.')) return;
    try {
      const r = await API.post(`/odc/${id}/reabrir`);
      WMS.toast('success', r.message || 'Orden reabierta');
      if (this._sub === 'odc') this.show_odc();
      else this.verODC(id);
    } catch(e) { WMS.toast('error', 'Error al reabrir: ' + e.message); }
  }
};
