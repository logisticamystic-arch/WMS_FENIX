/* ============================================================
   WMS Desktop — Módulo LOGÍSTICA AVANZADA
   Cross-Docking · Yard Management · Wave Picking
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
    return { crossdock:'Cross-Docking', yard:'Yard Management', wave:'Wave Picking' }[sub] || sub;
  },
  _renderSub(sub) {
    this._sub = sub;
    WMS._activateSidebarItem && WMS._activateSidebarItem(sub);
    if (sub === 'crossdock') this.renderCrossDock();
    else if (sub === 'yard') this.renderYard();
    else if (sub === 'wave') this.renderWave();
    else this.renderCrossDock();
  },

  // ── CROSS-DOCKING ────────────────────────────────────────
  async renderCrossDock() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderCrossDock()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.crearCrossDock()"><i class="fa-solid fa-plus"></i> Nueva Orden CD</button>
    `);
    WMS.spinner();
    try {
      const [rl, rk] = await Promise.all([API.get('/crossdock'), API.get('/crossdock/kpis/resumen')]);
      const ordenes = (rl.data||rl).data || [];
      const kpis = (rk.data||rk).data || (rk.data||rk) || {};
      const estadoBadge = e => ({pendiente:'<span class="status-badge sb-pending">Pendiente</span>',en_proceso:'<span class="status-badge sb-active">En Proceso</span>',completado:'<span class="status-badge sb-complete">Completado</span>',cancelado:'<span class="status-badge sb-error">Cancelado</span>'}[e]||`<span class="badge badge-gray">${e}</span>`);
      const rows = ordenes.slice(0,50).map(o => `<tr>
        <td class="ps-3"><div style="font-weight:700">#${o.id}</div><div style="font-size:.7rem;color:#64748b">${o.referencia_externa||''}</div></td>
        <td class="text-center">${estadoBadge(o.estado)}</td>
        <td>${WMS.esc(o.proveedor||o.origen||'—')}</td>
        <td>${WMS.esc(o.destino||o.cliente||'—')}</td>
        <td class="text-end fw-bold">${o.total_items||o.items_count||0}</td>
        <td class="text-end" style="font-size:.78rem;color:#64748b">${o.created_at?new Date(o.created_at).toLocaleDateString('es'):'—'}</td>
        <td class="text-center">
          ${o.estado==='pendiente'?`<button class="btn btn-xs btn-success" onclick="WMS_MODULES.logistica._cdAction(${o.id},'recibir')"><i class="fa-solid fa-arrow-down"></i></button>`:''}
          ${o.estado==='en_proceso'?`<button class="btn btn-xs btn-primary" onclick="WMS_MODULES.logistica._cdAction(${o.id},'transferir')"><i class="fa-solid fa-arrows-turn-right"></i></button> <button class="btn btn-xs btn-success" onclick="WMS_MODULES.logistica._cdAction(${o.id},'completar')"><i class="fa-solid fa-check"></i></button>`:''}
        </td>
      </tr>`).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-right-left"></i></div></div><div class="pro-kpi-value">${kpis.total||ordenes.length}</div><div class="pro-kpi-label">Órdenes Cross-Dock</div></div>
          <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-clock"></i></div></div><div class="pro-kpi-value">${kpis.en_proceso||0}</div><div class="pro-kpi-label">En Proceso</div></div>
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-check-double"></i></div></div><div class="pro-kpi-value">${kpis.completadas||0}</div><div class="pro-kpi-label">Completadas</div></div>
          <div class="pro-kpi-card accent-purple"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-stopwatch"></i></div></div><div class="pro-kpi-value">${kpis.tiempo_promedio_min||'—'}</div><div class="pro-kpi-label">Tiempo Prom. (min)</div></div>
        </div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-right-left me-2" style="color:#0277BD"></i>Órdenes de Cross-Docking</div></div>
        <div class="table-responsive"><table class="erp-table"><thead><tr style="background:#f8fafc"><th class="ps-3">ORDEN</th><th class="text-center">ESTADO</th><th>ORIGEN</th><th>DESTINO</th><th class="text-end">ITEMS</th><th class="text-end">FECHA</th><th class="text-center">ACCIONES</th></tr></thead>
        <tbody>${rows||'<tr><td colspan="7" class="text-center py-5 text-muted">Sin órdenes de cross-docking</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async crearCrossDock() { WMS.toast('Formulario de creación Cross-Dock en desarrollo','info'); },
  async _cdAction(id,action) {
    try { await API.post(`/crossdock/${id}/${action}`); WMS.toast(`Acción ${action} ejecutada`,'success'); this.renderCrossDock(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },

  // ── YARD MANAGEMENT ──────────────────────────────────────
  async renderYard() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderYard()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.crearCitaYard()"><i class="fa-solid fa-plus"></i> Nueva Cita</button>
    `);
    WMS.spinner();
    try {
      const [rl, rk, rm] = await Promise.all([API.get('/yard'), API.get('/yard/kpis/resumen'), API.get('/yard/muelles/estado')]);
      const citas = (rl.data||rl).data || [];
      const kpis = (rk.data||rk).data || (rk.data||rk) || {};
      const muelles = (rm.data||rm).data || (rm.data||rm).muelles || [];
      const estadoBadge = e => ({programada:'<span class="status-badge sb-pending">Programada</span>',en_patio:'<span class="status-badge sb-active">En Patio</span>',en_muelle:'<span class="status-badge sb-active">En Muelle</span>',completada:'<span class="status-badge sb-complete">Completada</span>',cancelada:'<span class="status-badge sb-error">Cancelada</span>'}[e]||`<span class="badge badge-gray">${e||'—'}</span>`);
      const muelleCards = muelles.slice(0,8).map(m => `<div style="background:${m.estado==='ocupado'?'#fef2f2':m.estado==='disponible'?'#f0fdf4':'#f1f5f9'};border:1px solid ${m.estado==='ocupado'?'#fca5a5':m.estado==='disponible'?'#86efac':'#cbd5e1'};border-radius:10px;padding:12px;text-align:center">
        <div style="font-weight:800;font-size:.85rem">${WMS.esc(m.nombre||m.codigo||m.muelle)}</div>
        <div style="font-size:.68rem;color:#64748b;margin-top:2px">${WMS.esc(m.estado||'—')}</div>
        ${m.vehiculo?`<div style="font-size:.72rem;font-weight:600;margin-top:4px"><i class="fa-solid fa-truck me-1"></i>${WMS.esc(m.vehiculo)}</div>`:''}
      </div>`).join('');
      const rows = citas.slice(0,30).map(c => `<tr>
        <td class="ps-3"><div style="font-weight:700">#${c.id}</div></td>
        <td class="text-center">${estadoBadge(c.estado)}</td>
        <td>${WMS.esc(c.transportista||'—')}</td>
        <td><code>${WMS.esc(c.placa||c.vehiculo||'—')}</code></td>
        <td>${WMS.esc(c.muelle||'—')}</td>
        <td class="text-center"><span class="badge badge-${c.tipo==='entrada'?'success':'info'}">${WMS.esc(c.tipo||'—')}</span></td>
        <td style="font-size:.78rem">${c.fecha_cita?new Date(c.fecha_cita).toLocaleString('es'):'—'}</td>
        <td class="text-center">
          ${c.estado==='programada'?`<button class="btn btn-xs btn-success" onclick="WMS_MODULES.logistica._yardAction(${c.id},'entrada')" title="Registrar entrada"><i class="fa-solid fa-arrow-right-to-bracket"></i></button>`:''}
          ${c.estado==='en_patio'?`<button class="btn btn-xs btn-primary" onclick="WMS_MODULES.logistica._yardAction(${c.id},'inicio-operacion')" title="Iniciar operación"><i class="fa-solid fa-play"></i></button>`:''}
          ${c.estado==='en_muelle'?`<button class="btn btn-xs btn-primary" onclick="WMS_MODULES.logistica._yardAction(${c.id},'fin-operacion')" title="Fin operación"><i class="fa-solid fa-stop"></i></button>`:''}
        </td>
      </tr>`).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-truck"></i></div></div><div class="pro-kpi-value">${kpis.total_hoy||citas.length}</div><div class="pro-kpi-label">Citas Hoy</div></div>
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-clock"></i></div></div><div class="pro-kpi-value">${kpis.turnaround_promedio||'—'}</div><div class="pro-kpi-label">Turnaround Prom.</div></div>
          <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-hourglass-half"></i></div></div><div class="pro-kpi-value">${kpis.en_patio||0}</div><div class="pro-kpi-label">En Patio Ahora</div></div>
          <div class="pro-kpi-card accent-purple"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-gauge"></i></div></div><div class="pro-kpi-value">${kpis.muelles_ocupados||0}/${kpis.muelles_total||muelles.length}</div><div class="pro-kpi-label">Muelles Ocupados</div></div>
        </div>
        ${muelles.length?`<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-warehouse me-2" style="color:#7c3aed"></i>Estado de Muelles</div></div>
        <div class="card-body"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px">${muelleCards}</div></div></div>`:''}
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-calendar-check me-2" style="color:#1a56db"></i>Citas Programadas</div></div>
        <div class="table-responsive"><table class="erp-table"><thead><tr style="background:#f8fafc"><th class="ps-3">ID</th><th class="text-center">ESTADO</th><th>TRANSPORTISTA</th><th>PLACA</th><th>MUELLE</th><th class="text-center">TIPO</th><th>FECHA CITA</th><th class="text-center">ACCIONES</th></tr></thead>
        <tbody>${rows||'<tr><td colspan="8" class="text-center py-5 text-muted">Sin citas programadas</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async crearCitaYard() { WMS.toast('Formulario de nueva cita en desarrollo','info'); },
  async _yardAction(id,action) {
    try { await API.post(`/yard/${id}/${action}`); WMS.toast(`Acción ${action} registrada`,'success'); this.renderYard(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },

  // ── WAVE PICKING ─────────────────────────────────────────
  async renderWave() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.logistica.renderWave()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.logistica.autoGenerarWave()"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Generar Waves</button>
    `);
    WMS.spinner();
    try {
      const [rl, rk] = await Promise.all([API.get('/wave'), API.get('/wave/kpis/resumen')]);
      const waves = (rl.data||rl).data || [];
      const kpis = (rk.data||rk).data || (rk.data||rk) || {};
      const estadoBadge = e => ({pendiente:'<span class="status-badge sb-pending">Pendiente</span>',en_proceso:'<span class="status-badge sb-active">En Proceso</span>',completada:'<span class="status-badge sb-complete">Completada</span>',cancelada:'<span class="status-badge sb-error">Cancelada</span>'}[e]||`<span class="badge badge-gray">${e||'—'}</span>`);
      const rows = waves.slice(0,30).map(w => {
        const pct = w.total_lineas ? Math.round((w.lineas_completadas||0)/w.total_lineas*100) : 0;
        return `<tr>
          <td class="ps-3"><div style="font-weight:700">Wave #${w.id}</div><div style="font-size:.68rem;color:#64748b">${w.zona||'Todas las zonas'}</div></td>
          <td class="text-center">${estadoBadge(w.estado)}</td>
          <td class="text-end fw-bold">${w.planillas_count||w.total_planillas||0}</td>
          <td class="text-end">${w.total_lineas||0}</td>
          <td style="min-width:140px"><div class="wave-progress"><div class="wave-progress-bar"><div class="wave-progress-fill ${pct>=80?'wp-green':pct>=40?'wp-blue':'wp-amber'}" style="width:${pct}%"></div></div><span class="wave-progress-pct">${pct}%</span></div></td>
          <td style="font-size:.78rem">${w.created_at?new Date(w.created_at).toLocaleString('es'):'—'}</td>
          <td class="text-center">
            ${w.estado==='pendiente'?`<button class="btn btn-xs btn-success" onclick="WMS_MODULES.logistica._waveAction(${w.id},'iniciar')"><i class="fa-solid fa-play"></i></button>`:''}
            ${w.estado==='en_proceso'?`<button class="btn btn-xs btn-primary" onclick="WMS_MODULES.logistica._waveAction(${w.id},'completar')"><i class="fa-solid fa-check"></i></button>`:''}
          </td>
        </tr>`;
      }).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-layer-group"></i></div></div><div class="pro-kpi-value">${kpis.total||waves.length}</div><div class="pro-kpi-label">Waves Totales</div></div>
          <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-spinner"></i></div></div><div class="pro-kpi-value">${kpis.en_proceso||0}</div><div class="pro-kpi-label">En Proceso</div></div>
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-check-double"></i></div></div><div class="pro-kpi-value">${kpis.completadas||0}</div><div class="pro-kpi-label">Completadas</div></div>
          <div class="pro-kpi-card accent-purple"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></div></div><div class="pro-kpi-value">${kpis.lineas_totales||0}</div><div class="pro-kpi-label">Líneas Totales</div></div>
        </div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-layer-group me-2" style="color:#1a56db"></i>Waves de Picking</div></div>
        <div class="table-responsive"><table class="erp-table"><thead><tr style="background:#f8fafc"><th class="ps-3">WAVE</th><th class="text-center">ESTADO</th><th class="text-end">PLANILLAS</th><th class="text-end">LÍNEAS</th><th>PROGRESO</th><th>FECHA</th><th class="text-center">ACCIONES</th></tr></thead>
        <tbody>${rows||'<tr><td colspan="7" class="text-center py-5 text-muted">Sin waves. Use Auto-Generar para crear.</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async autoGenerarWave() {
    if(!confirm('¿Auto-generar waves agrupando planillas por zona?')) return;
    try { const r = await API.post('/wave/auto-generar'); WMS.toast(`Wave(s) generada(s) correctamente`,'success'); this.renderWave(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },
  async _waveAction(id,action) {
    try { await API.post(`/wave/${id}/${action}`); WMS.toast(`Wave ${action} exitosamente`,'success'); this.renderWave(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  }
};
