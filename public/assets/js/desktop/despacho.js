/* ============================================================
   WMS Desktop — Módulo DESPACHO & TMS
   Sub-vistas: certificacion | packing | cargue | dashboard | tms
   ============================================================ */
WMS_MODULES.despacho = {
  load(sub) {
    WMS.setBreadcrumb('despacho', this.subLabel(sub));
    WMS.renderSidebar('despacho');
    const s = sub || 'certificacion';
    const fn = {
      certificacion: this.show_certificacion, packing: this.show_packing_menu, cargue: this.show_cargue,
      dashboard: this.show_dashboard, tms: this.show_tms,
    };
    (fn[s]?.bind(this) || fn.certificacion.bind(this))();
  },

  _expiryPollTimer: null,
  _expiryAprobacionId: null,
  _expiryOnApproved: null,

  subLabel(s) {
    const m = { certificacion:'Certificación de Pedidos', packing:'Packing', cargue:'Planilla de Cargue',
      dashboard:'Dashboard Certificación', tms:'Integración TMS' };
    return m[s] || s || 'Panel';
  },

  // Filtros de fecha para certificaciones
  _certFechaInicio: null,
  _certFechaFin: null,

  _certFechaParams() {
    const fi = this._certFechaInicio;
    const ff = this._certFechaFin;
    const parts = [];
    if (fi) parts.push('fecha_inicio=' + encodeURIComponent(fi));
    if (ff) parts.push('fecha_fin='    + encodeURIComponent(ff));
    return parts.length ? '?' + parts.join('&') : '';
  },

  _certSetFechaRapida(tipo) {
    const hoy = new Date();
    const fmt = d => d.toISOString().slice(0,10);
    if (tipo === 'hoy') {
      this._certFechaInicio = fmt(hoy);
      this._certFechaFin    = fmt(hoy);
    } else if (tipo === 'semana') {
      const lun = new Date(hoy); lun.setDate(hoy.getDate() - hoy.getDay() + 1);
      this._certFechaInicio = fmt(lun);
      this._certFechaFin    = fmt(hoy);
    } else if (tipo === 'todo') {
      this._certFechaInicio = null;
      this._certFechaFin    = null;
    }
    // Sync inputs
    const fi = document.getElementById('cert-fi');
    const ff = document.getElementById('cert-ff');
    if (fi) fi.value = this._certFechaInicio || '';
    if (ff) ff.value = this._certFechaFin    || '';
    this.show_certificacion();
  },

  // ── CERTIFICACIÓN (POR SUCURSAL) ───────────────────────────────
  async show_certificacion(silent = false) {
    // Inicializar sin filtro de fecha para mostrar todos los pedidos pendientes de certificación
    // (null/null = modo "Todo"; el usuario puede filtrar con los botones Hoy / Esta semana)
    if (this._certFechaInicio === null || this._certFechaInicio === undefined) {
      this._certFechaInicio = null;
      this._certFechaFin    = null;
    }
    const fi = this._certFechaInicio || '';
    const ff = this._certFechaFin    || '';

    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>
      <button class="btn btn-outline-success btn-sm" onclick="WMS_MODULES.despacho.imprimirRemisionesDirectasSeleccionadas()">
        <i class="fa-solid fa-mobile-screen"></i> Imprimir móvil sel.
      </button>
      <span style="display:flex;align-items:center;gap:6px;margin-left:8px;">
        <i class="fa-solid fa-calendar-days" style="color:#6b7280;font-size:12px;"></i>
        <input type="date" id="cert-fi" value="${WMS.esc(fi)}"
          style="font-size:11px;padding:2px 6px;border:1px solid #d1d5db;border-radius:5px;"
          onchange="WMS_MODULES.despacho._certFechaInicio=this.value">
        <span style="color:#9ca3af;font-size:11px;">—</span>
        <input type="date" id="cert-ff" value="${WMS.esc(ff)}"
          style="font-size:11px;padding:2px 6px;border:1px solid #d1d5db;border-radius:5px;"
          onchange="WMS_MODULES.despacho._certFechaFin=this.value">
        <button class="btn btn-sm btn-primary" style="font-size:10px;padding:2px 9px;"
          onclick="WMS_MODULES.despacho.show_certificacion()">
          <i class="fa-solid fa-search"></i> Buscar
        </button>
        <button class="btn btn-sm btn-outline-secondary" style="font-size:10px;padding:2px 7px;"
          onclick="WMS_MODULES.despacho._certSetFechaRapida('hoy')">Hoy</button>
        <button class="btn btn-sm btn-outline-secondary" style="font-size:10px;padding:2px 7px;"
          onclick="WMS_MODULES.despacho._certSetFechaRapida('semana')">Esta semana</button>
        <button class="btn btn-sm btn-outline-secondary" style="font-size:10px;padding:2px 7px;"
          onclick="WMS_MODULES.despacho._certSetFechaRapida('todo')">Todo</button>
      </span>`);

    if (!silent) WMS.spinner();
    try {
      const params   = this._certFechaParams();
      const qSes     = new URLSearchParams({ ctx: 'cert' });
      if (this._certFechaInicio) qSes.append('fm_desde', this._certFechaInicio);
      if (this._certFechaFin)    qSes.append('fm_hasta', this._certFechaFin);
      const paramsSes = '?' + qSes.toString();

      const fechaVista = this._certFechaInicio || new Date().toISOString().slice(0,10);
      const [rPend, rSes, rCert, rVista, rDespDirecto] = await Promise.all([
        API.get('/picking/certificacion/pendientes' + params),
        API.get('/packing/sesiones' + paramsSes),
        API.get('/picking/certificacion/certificadas' + params),
        API.get('/picking/certificacion/vista-hoy?fecha=' + fechaVista),
        API.get('/picking/certificacion/despachados-directo' + params),
      ]);
      const pendientes  = rPend.data  || [];
      const todasSes    = rSes.data   || [];
      const certDirect  = rCert.data  || [];
      const vistaHoy    = rVista.data || [];
      const despachadosDirecto = rDespDirecto.data || [];
      const activas     = todasSes.filter(s => s.estado === 'EnProceso');
      const completadas = todasSes.filter(s => s.estado === 'Completada');

      const huerfanas = completadas.filter(s =>
        pendientes.find(p => p.sucursal_entrega === s.sucursal_entrega) &&
        !activas.find(a => a.sucursal_entrega === s.sucursal_entrega)
      );
      const sucHuerfanas  = new Set(huerfanas.map(s => s.sucursal_entrega));
      // Incluir huérfanas en la tabla de pendientes (sesión completada pero sin certificar)
      const sinSesion     = pendientes.filter(p =>
        !activas.find(s => s.sucursal_entrega === p.sucursal_entrega)
      );
      const completadasOk = completadas.filter(s => !sucHuerfanas.has(s.sucursal_entrega));
      const certDirectMap = {};
      certDirect.forEach(c => { certDirectMap[c.sucursal_entrega] = c; });
      const certSinSesion = certDirect.filter(
        c => !completadasOk.find(s => s.sucursal_entrega === c.sucursal_entrega)
      );
      const esAdmin = ['Admin','SuperAdmin'].includes(WMS.user?.rol);
      const totalCert = completadasOk.length + certSinSesion.length;

      // Sucursales distintas visibles con los filtros de fecha actuales — permite
      // aislar de inmediato "las planillas/pedidos de ESTE cliente en esta fecha"
      // en vez de buscar por texto libre entre todos los clientes mezclados.
      const sucursalesDisponibles = [...new Set([
        ...sinSesion.map(p => p.sucursal_entrega),
        ...completadasOk.map(s => s.sucursal_entrega),
        ...certSinSesion.map(c => c.sucursal_entrega),
      ].filter(Boolean))].sort((a,b) => a.localeCompare(b));

      WMS.setContent(`
        <!-- ── Buscador global dinámico + selector de sucursal ── -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
          <div style="flex:1;min-width:220px;position:relative;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;"></i>
            <input id="cert-search" type="text" placeholder="Buscar cliente, sucursal o producto..."
              style="width:100%;padding:8px 12px 8px 34px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;outline:none;transition:border .15s;"
              oninput="WMS_MODULES.despacho._certFiltrarGlobal(this.value)"
              onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e5e7eb'">
          </div>
          <select id="cert-sucursal-select"
            style="padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;min-width:220px;background:#fff;"
            onchange="WMS_MODULES.despacho._certFiltrarPorSucursal(this.value)">
            <option value="">— Todas las sucursales (${sucursalesDisponibles.length}) —</option>
            ${sucursalesDisponibles.map(s => `<option value="${WMS.esc(s)}">${WMS.esc(s)}</option>`).join('')}
          </select>
          <span id="cert-search-count" style="font-size:12px;color:#6b7280;white-space:nowrap;"></span>
        </div>

        ${activas.length ? `
        <div class="card" style="margin-bottom:14px;border-left:4px solid #f59e0b;">
          <div class="card-header" style="background:#fefce8;padding:10px 14px;">
            <span class="card-title" style="color:#92400e;font-size:13px;">
              <i class="fa-solid fa-spinner fa-spin"></i>&nbsp; En Proceso — ${activas.length} sesión(es) activa(s)
            </span>
          </div>
          <div class="table-container">
            <table class="erp-table cert-searchable">
              <thead><tr>
                <th>Sucursal</th>
                <th class="text-center">Canastas</th>
                <th class="text-center">Uds. Empacadas</th>
                <th style="width:180px;">Acciones</th>
              </tr></thead>
              <tbody>${activas.map(s => `<tr>
                <td><strong>${WMS.esc(s.sucursal_entrega)}</strong></td>
                <td class="text-center">${s.num_unidades}</td>
                <td class="text-center">${s.total_empacado}</td>
                <td>
                  <button class="btn btn-sm btn-warning" onclick="WMS_MODULES.despacho.show_packing(${s.id})" style="margin-right:4px;">
                    <i class="fa-solid fa-play"></i> Continuar
                  </button>
                  <button class="btn btn-sm btn-outline-danger" onclick="WMS_MODULES.despacho.cancelarSesionPacking(${s.id},'${WMS.esc(s.sucursal_entrega)}')">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </td>
              </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>` : ''}

        <div class="card" style="margin-bottom:14px;">
          <div class="card-header" style="padding:10px 14px;display:flex;align-items:center;gap:10px;">
            <span class="card-title" style="font-size:13px;">
              <i class="fa-solid fa-clipboard-list" style="color:#6366f1;"></i>&nbsp; Pendientes de Certificar
            </span>
            <span style="background:#e0e7ff;color:#4338ca;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:700;">${sinSesion.length}</span>
          </div>
          <div class="table-container">
            <table class="erp-table cert-searchable" id="cert-table">
              <thead><tr>
                <th>Cliente / Planilla</th>
                <th class="text-center">Ambientes</th>
                <th class="text-center">Pedidos</th>
                <th class="text-center">Líneas Cert.</th>
                <th style="width:220px;">Acciones</th>
              </tr></thead>
              <tbody>${sinSesion.map(s => `<tr>
                <td>
                  <strong style="font-size:13px;">${WMS.esc(s.sucursal_entrega || 'Sin Cliente')}</strong>
                  ${s.planilla_numero ? `<br><span style="display:inline-block;background:#1e3a8a;color:#fff;border-radius:4px;padding:1px 7px;font-size:10px;font-family:monospace;font-weight:700;margin-top:2px;">${WMS.esc(s.planilla_numero)}</span>` : ''}
                  ${s.planillas && s.planillas.length ? `<br><span style="font-size:10px;color:#64748b;">${s.planillas.map(p => WMS.esc(p)).join(' · ')}</span>` : ''}
                </td>
                <td class="text-center">
                  ${(s.ambientes || 'Desconocido').split(',').map(a =>
                    `<span style="display:inline-block;background:#dbeafe;color:#1e40af;border-radius:4px;padding:1px 7px;font-size:10px;margin:1px;">${a.trim()}</span>`
                  ).join('')}
                </td>
                <td class="text-center"><strong>${s.total_pedidos || '—'}</strong></td>
                <td class="text-center"><strong>${s.total_lineas_cert || s.total_lineas || '—'}</strong></td>
                <td>
                  <button class="btn btn-sm btn-info" onclick="WMS_MODULES.despacho.verDetallesPendientes('${WMS.esc(s.sucursal_entrega)}')" style="margin-right:4px;">
                    <i class="fa-solid fa-list"></i> Ver
                  </button>
                  <button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.autoCertificar('${WMS.esc(s.sucursal_entrega)}')">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Cert.
                  </button>
                </td>
              </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin sucursales pendientes de certificar</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>

        ${huerfanas.length ? `
        <div class="card" style="margin-bottom:14px;border:2px solid #f59e0b;">
          <div class="card-header" style="background:#fffbeb;padding:10px 14px;">
            <span class="card-title" style="color:#92400e;font-size:13px;">
              <i class="fa-solid fa-triangle-exclamation"></i>&nbsp; Packing finalizado sin certificar (${huerfanas.length})
            </span>
            <span style="font-size:11px;color:#78350f;margin-left:8px;">La sesión terminó pero la certificación no se completó</span>
          </div>
          <div class="table-container">
            <table class="erp-table cert-searchable">
              <thead><tr><th>Sucursal</th><th class="text-center">Canastas</th><th style="width:140px;">Acciones</th></tr></thead>
              <tbody>${huerfanas.map(s => `<tr>
                <td><strong>${WMS.esc(s.sucursal_entrega)}</strong></td>
                <td class="text-center">${s.num_unidades}</td>
                <td>
                  <button class="btn btn-sm btn-warning" onclick="WMS_MODULES.despacho.recertificarSesion(${s.id},'${WMS.esc(s.sucursal_entrega)}')">
                    <i class="fa-solid fa-rotate"></i> Recertificar
                  </button>
                </td>
              </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>` : ''}

        ${totalCert ? `
        <div class="card" style="margin-bottom:14px;border-left:4px solid #22c55e;">
          <div class="card-header" style="background:#f0fdf4;padding:10px 14px;display:flex;align-items:center;gap:10px;">
            <span class="card-title" style="color:#065f46;font-size:13px;">
              <i class="fa-solid fa-check-double"></i>&nbsp; Certificaciones Completadas
            </span>
            <span style="background:#dcfce7;color:#15803d;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:700;">${totalCert}</span>
            ${esAdmin ? `<span style="margin-left:auto;font-size:11px;color:#6b7280;">
              <i class="fa-solid fa-circle-info"></i> Haz clic en <strong>Editar Cert.</strong> para modificar cantidades certificadas
            </span>` : ''}
          </div>
          <div class="table-container">
            <table class="erp-table cert-searchable" id="cert-table-done">
              <thead><tr>
                <th style="width:36px;text-align:center;">
                  <input type="checkbox" id="cert-sel-all" title="Seleccionar todas (solo filas visibles)"
                    onchange="document.querySelectorAll('.cert-remision-check,.cert-remision-check-directa').forEach(cb=>{ if(cb.closest('tr')?.style.display!=='none') cb.checked=this.checked; })">
                </th>
                <th>Cliente / Sucursal</th>
                <th class="text-center">Tipo</th>
                <th class="text-center">Total ref.</th>
                <th class="text-center">Fecha</th>
                <th style="width:220px;">Acciones</th>
              </tr></thead>
              <tbody>
              ${completadasOk.map(s => {
                const fechaStr = (s.fecha_movimiento_pedido || certDirectMap[s.sucursal_entrega]?.fecha_movimiento || s.created_at || '').slice(0,10) || '—';
                return `<tr>
                  <td style="text-align:center;">
                    <input type="checkbox" class="cert-remision-check" data-sesion-id="${s.id}">
                  </td>
                  <td>
                    <strong style="font-size:13px;">${WMS.esc(s.sucursal_entrega)}</strong>
                    <div style="font-size:10px;color:#6b7280;margin-top:1px;">${s.total_empacado || 0} uds empacadas</div>
                    ${s.planillas && s.planillas.length > 1 ? `<div style="font-size:10px;color:#b45309;margin-top:2px;"><i class="fa-solid fa-triangle-exclamation"></i> ${s.planillas.length} planillas mezcladas: ${s.planillas.map(p => WMS.esc(p)).join(' · ')}</div>` : ''}
                  </td>
                  <td class="text-center">
                    <span style="background:#dcfce7;color:#15803d;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:700;">
                      <i class="fa-solid fa-box"></i> Packing
                    </span>
                  </td>
                  <td class="text-center"><strong>${s.total_refs || 0}</strong></td>
                  <td class="text-center" style="font-size:12px;color:#6b7280;">${fechaStr}</td>
                  <td>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                      ${s.planillas && s.planillas.length > 1 ? s.planillas.map(p => `
                      <button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.imprimirRemision(${s.id},'${WMS.esc(p)}')" title="Imprimir remisión de ${WMS.esc(p)}">
                        <i class="fa-solid fa-print"></i> ${WMS.esc(p)}
                      </button>`).join('') : `
                      <button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.imprimirRemision(${s.id})" title="Imprimir remisión">
                        <i class="fa-solid fa-print"></i> Remisión
                      </button>`}
                      <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.despacho.imprimirTodosStickers(${s.id})" title="Stickers">
                        <i class="fa-solid fa-tags"></i>
                      </button>
                      ${esAdmin ? `<button class="btn btn-sm btn-warning" onclick="WMS_MODULES.despacho.adminEditCert('${WMS.esc(s.sucursal_entrega)}')" title="Editar certificación">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </button>` : ''}
                      <button class="btn btn-sm btn-outline-danger" onclick="WMS_MODULES.despacho.resetearCert(${s.id},'${WMS.esc(s.sucursal_entrega)}')" title="Resetear">
                        <i class="fa-solid fa-rotate-left"></i>
                      </button>
                    </div>
                  </td>
                </tr>`;
              }).join('')}
              ${certSinSesion.map(s => {
                const fechaStr = (s.fecha_movimiento || '').slice(0,10) || '—';
                const ordenIdsAttr = WMS.esc(JSON.stringify(s.orden_ids || []));
                const sinPlanilla = !s.planilla_numero || s.planilla_numero === 'Sin planilla';
                return `<tr>
                  <td style="text-align:center;">
                    <input type="checkbox" class="cert-remision-check-directa" data-sucursal="${WMS.esc(s.sucursal_entrega)}" data-orden-ids="${ordenIdsAttr}">
                  </td>
                  <td>
                    <strong style="font-size:13px;">${WMS.esc(s.sucursal_entrega || 'Sin Sucursal')}</strong>
                    ${sinPlanilla
                      ? `<span style="display:inline-block;margin-left:5px;background:#f1f5f9;color:#64748b;border-radius:4px;padding:1px 7px;font-size:10px;font-family:monospace;">Sin planilla</span>`
                      : `<span style="display:inline-block;margin-left:5px;background:#1e3a8a;color:#fff;border-radius:4px;padding:1px 7px;font-size:10px;font-family:monospace;font-weight:700;">${WMS.esc(s.planilla_numero)}</span>`}
                    ${s.total_pedidos > 1 ? `<span style="display:inline-block;margin-left:5px;background:#fef9c3;color:#854d0e;border:1px solid #fde047;border-radius:10px;font-size:10px;font-weight:700;padding:1px 7px;">Re-cert.</span>` : ''}
                    <div style="font-size:10px;color:#6b7280;margin-top:1px;">
                      ${(s.ambientes || '').split(',').map(a => a.trim()).filter(Boolean).join(' · ') || 'Sin ambiente'}
                      ${s.pedidos_numeros?.length ? ` · Pedidos: ${s.pedidos_numeros.map(n=>WMS.esc(n)).join(', ')}` : ''}
                    </div>
                    ${s.observaciones ? `<div style="font-size:10px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;padding:2px 6px;margin-top:3px;"><i class="fa-solid fa-note-sticky"></i> ${WMS.esc(s.observaciones)}</div>` : ''}
                  </td>
                  <td class="text-center">
                    <span style="background:#e0e7ff;color:#4338ca;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:700;">
                      <i class="fa-solid fa-mobile-screen"></i> Móvil
                    </span>
                  </td>
                  <td class="text-center"><strong>${s.total_lineas || 0}</strong></td>
                  <td class="text-center" style="font-size:12px;color:#6b7280;">${fechaStr}</td>
                  <td>
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                      <button class="btn btn-sm btn-success" onclick='WMS_MODULES.despacho.imprimirRemisionPorOrdenes(${ordenIdsAttr}, ${s.total_pedidos} > 1 ? "${s.total_pedidos} pedidos" : "1 pedido")' title="Imprimir remisión de esta planilla">
                        <i class="fa-solid fa-print"></i> Remisión
                      </button>
                      ${esAdmin ? `<button class="btn btn-sm btn-warning" onclick="WMS_MODULES.despacho.adminEditCert('${WMS.esc(s.sucursal_entrega)}')" title="Editar certificación">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </button>` : ''}
                      <button class="btn btn-sm btn-outline-danger" onclick="WMS_MODULES.despacho.resetearCertDirecta('${WMS.esc(s.sucursal_entrega)}')" title="Resetear">
                        <i class="fa-solid fa-rotate-left"></i>
                      </button>
                    </div>
                  </td>
                </tr>`;
              }).join('')}
              </tbody>
            </table>
          </div>
        </div>` : ''}

        ${vistaHoy.length ? `
        <div class="card" style="margin-bottom:14px;border-left:4px solid #6366f1;">
          <div class="card-header" style="background:#f5f3ff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title" style="font-size:13px;cursor:pointer;" onclick="document.getElementById('vista-hoy-body').style.display=document.getElementById('vista-hoy-body').style.display==='none'?'':'none'">
              <i class="fa-solid fa-chart-gantt" style="color:#6366f1;"></i>&nbsp; Vista General del Día
            </span>
            <span style="display:flex;gap:6px;align-items:center;">
              <span style="background:#ede9fe;color:#5b21b6;border-radius:12px;padding:2px 10px;font-size:11px;font-weight:700;">${vistaHoy.length} planillas</span>
              <button class="btn btn-sm btn-outline-secondary" style="font-size:10px;padding:2px 7px;" onclick="document.getElementById('vista-hoy-body').style.display=document.getElementById('vista-hoy-body').style.display==='none'?'':'none'">
                <i class="fa-solid fa-chevron-down"></i>
              </button>
            </span>
          </div>
          <div id="vista-hoy-body" style="display:none;overflow-x:auto;">
            <table class="erp-table" style="font-size:12px;">
              <thead><tr>
                <th>Cliente / Planilla</th>
                <th>Ambientes</th>
                <th class="text-center">Estado Picking</th>
                <th class="text-center">Estado Cert.</th>
                <th class="text-center"># Órdenes</th>
              </tr></thead>
              <tbody>${vistaHoy.map(v => {
                const colorGlobal = v.estado_global === 'Certificado' ? '#059669'
                  : v.estado_global === 'ListoCert' ? '#2563eb'
                  : v.estado_global === 'EnPicking' ? '#d97706'
                  : '#6b7280';
                const iconGlobal = v.estado_global === 'Certificado'
                  ? '<i class="fa-solid fa-circle-check" style="color:#059669;"></i>'
                  : v.estado_global === 'ListoCert'
                  ? '<i class="fa-solid fa-circle-dot" style="color:#2563eb;"></i>'
                  : v.estado_global === 'EnPicking'
                  ? '<i class="fa-solid fa-spinner fa-spin" style="color:#d97706;"></i>'
                  : '<i class="fa-solid fa-circle" style="color:#9ca3af;"></i>';
                return `<tr style="border-left:3px solid ${colorGlobal};">
                  <td>
                    <strong>${WMS.esc(v.sucursal_entrega)}</strong>
                    ${v.planilla_numero ? `<br><span style="font-size:10px;font-family:monospace;background:#e0e7ff;color:#3730a3;border-radius:3px;padding:1px 5px;">${WMS.esc(v.planilla_numero)}</span>` : ''}
                  </td>
                  <td style="font-size:11px;color:#64748b;max-width:200px;">${WMS.esc(v.ambientes || '—')}</td>
                  <td class="text-center">
                    ${v.estados.map(e => `<span style="display:inline-block;font-size:10px;border-radius:10px;padding:1px 7px;margin:1px;background:${e.picking==='Completada'?'#dcfce7':e.picking==='EnProceso'?'#fef9c3':'#f1f5f9'};color:${e.picking==='Completada'?'#065f46':e.picking==='EnProceso'?'#92400e':'#475569'};">${WMS.esc(e.picking)} (${e.cantidad})</span>`).join('')}
                  </td>
                  <td class="text-center">
                    ${v.estados.map(e => `<span style="display:inline-block;font-size:10px;border-radius:10px;padding:1px 7px;margin:1px;background:${e.cert==='Certificada'?'#dcfce7':'#fef9c3'};color:${e.cert==='Certificada'?'#065f46':'#92400e'};">${WMS.esc(e.cert)} (${e.cantidad})</span>`).join('')}
                  </td>
                  <td class="text-center"><strong>${v.total_ordenes}</strong></td>
                </tr>`;
              }).join('')}</tbody>
            </table>
          </div>
        </div>` : ''}
      `);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  // Selector estructurado de sucursal — reutiliza el filtro de texto (la sucursal
  // completa siempre es un match preciso, ninguna fila de otra sucursal la contiene)
  // pero se presenta como un <select> en vez de texto libre, y sincroniza el buscador
  // para que ambos filtros queden consistentes.
  _certFiltrarPorSucursal(sucursal) {
    const search = document.getElementById('cert-search');
    if (search) search.value = sucursal || '';
    this._certFiltrarGlobal(sucursal || '');
  },

  _certFiltrarGlobal(q) {
    const f = (q || '').toLowerCase().trim();
    let visible = 0, total = 0;
    document.querySelectorAll('.cert-searchable tbody tr').forEach(tr => {
      if (tr.querySelector('td[colspan]')) return;
      total++;
      const match = !f || tr.textContent.toLowerCase().includes(f);
      tr.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    const count = document.getElementById('cert-search-count');
    if (count) count.textContent = f ? `${visible} de ${total} resultado(s)` : '';
  },

  filterTable(q, _tableId) { this._certFiltrarGlobal(q); },

  _openPrint(url, titulo, autoPrint = false) {
    const token = localStorage.getItem('wms_token') || localStorage.getItem('token') || '';
    const win = window.open('', '_blank');
    if (!win) { WMS.toast('warning', 'Permite ventanas emergentes para imprimir'); return null; }
    win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${titulo}...</title></head>
      <body style="font-family:sans-serif;padding:20px;color:#555;"><p>&#9203; Cargando ${titulo}...</p></body></html>`);
    win.document.close();
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(html => {
        if (win.closed) return;
        win.document.open(); win.document.write(html); win.document.close();
        if (autoPrint) setTimeout(() => { if (!win.closed) win.print(); }, 700);
      })
      .catch(e => {
        if (!win.closed) {
          win.document.open();
          win.document.write(`<!DOCTYPE html><html><body style="font-family:sans-serif;padding:20px;">
            <h3 style="color:#dc2626;">Error al cargar ${titulo}</h3><p>${e.message}</p>
            <p style="font-size:.8rem;color:#6b7280;">${url}</p></body></html>`);
          win.document.close();
        }
        WMS.toast('error', 'Error: ' + e.message);
      });
    return win;
  },

  async imprimirRemision(sesionId, planilla = null) {
    const qs = planilla ? `?planilla=${encodeURIComponent(planilla)}` : '';
    this._openPrint(`${API_BASE}/packing/sesion/${sesionId}/remision${qs}`, 'Remisión');
  },

  async imprimirRemisionDirecta(sucursal) {
    const fecha = this._certFechaInicio || new Date().toISOString().slice(0, 10);
    this._openPrint(`${API_BASE}/picking/certificacion/remision/${encodeURIComponent(sucursal)}?fecha=${fecha}`, 'Remisión');
  },

  // Imprime la remisión de exactamente los pedidos indicados (por id) — usado por
  // el botón "Remisión" de cada fila (una planilla) y por la selección múltiple.
  // Reemplaza el filtrado implícito por sucursal+fecha, que mezclaba todas las
  // planillas certificadas de un cliente en el mismo día.
  async imprimirRemisionPorOrdenes(ordenIds, label = '') {
    if (!ordenIds || !ordenIds.length) {
      WMS.toast('warning', 'No hay pedidos para imprimir en esta selección');
      return;
    }
    const params = new URLSearchParams();
    ordenIds.forEach(id => params.append('orden_ids[]', id));
    WMS.toast('info', `Generando remisión (${label || ordenIds.length + ' pedido(s)'})...`);
    this._openPrint(`${API_BASE}/picking/certificacion/remision-multiple?${params}`, 'Remisión');
  },

  async imprimirRemisionesDirectasSeleccionadas() {
    // Solo cuentan los checkboxes de filas VISIBLES: si el usuario filtró por
    // sucursal/búsqueda y había marcado filas de otra sucursal antes de cambiar el
    // filtro, esas quedan ocultas pero seguían "checked" en el DOM — se ignoran
    // para que la remisión combine solo lo que se ve y se seleccionó a propósito.
    const checks = Array.from(document.querySelectorAll('.cert-remision-check-directa:checked'))
      .filter(cb => cb.closest('tr')?.style.display !== 'none');
    if (!checks.length) {
      WMS.toast('warning', 'Selecciona al menos una planilla visible para imprimir');
      return;
    }
    // Combina EXACTAMENTE los pedidos de las filas marcadas — el usuario elige
    // dinámicamente qué planillas salen juntas en la remisión, en vez de que el
    // sistema las agrupe automáticamente por sucursal+fecha (lo que las mezclaba
    // sin que se pidiera).
    let ordenIds = [];
    checks.forEach(cb => {
      try { ordenIds = ordenIds.concat(JSON.parse(cb.dataset.ordenIds || '[]')); } catch(_) {}
    });
    if (!ordenIds.length) {
      WMS.toast('warning', 'La selección no tiene pedidos asociados');
      return;
    }
    const n = checks.length;
    WMS.toast('info', `Generando remisión consolidada de ${n} planilla(s) seleccionada(s)...`);
    this.imprimirRemisionPorOrdenes(ordenIds, `${n} planilla(s)`);
  },

  async imprimirRemisionesSeleccionadas() {
    const checkboxes = document.querySelectorAll('.cert-remision-check:checked');
    if (!checkboxes.length) {
      WMS.toast('warning', 'Selecciona al menos una remisión para imprimir');
      return;
    }
    const ids = Array.from(checkboxes).map(cb => cb.dataset.sesionId);
    const token = localStorage.getItem('wms_token') || localStorage.getItem('token') || '';

    for (let i = 0; i < ids.length; i++) {
      WMS.toast('info', `Imprimiendo ${i + 1} de ${ids.length}...`);
      await new Promise(resolve => {
        const url = `${API_BASE}/packing/sesion/${ids[i]}/remision`;
        const win = window.open('', '_blank');
        if (!win) { WMS.toast('warning', 'Permite ventanas emergentes para imprimir'); resolve(); return; }
        win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Remisión ${i + 1}/${ids.length}</title></head>
          <body style="font-family:sans-serif;padding:20px;color:#555;"><p>&#9203; Cargando remisión ${i + 1} de ${ids.length}...</p></body></html>`);
        win.document.close();
        let settled = false;
        const done = () => { if (!settled) { settled = true; setTimeout(resolve, 300); } };
        fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
          .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
          .then(html => {
            if (win.closed) { done(); return; }
            win.document.open(); win.document.write(html); win.document.close();
            setTimeout(() => {
              if (!win.closed) {
                win.addEventListener('afterprint', done, { once: true });
                setTimeout(done, 8000);
                win.print();
              } else { done(); }
            }, 700);
          })
          .catch(() => done());
      });
    }
    WMS.toast('success', `${ids.length} remisión(es) enviada(s) a imprimir`);
  },

  async imprimirTodosStickers(sesionId) {
    try {
      const r = await API.get(`/packing/sesion/${sesionId}`);
      const data = r.data || r;
      const sesion   = data.sesion || {};
      const unidades = (data.unidades || []).filter(u => u.estado === 'Cerrada');
      if (!unidades.length) { WMS.toast('warning', 'No hay canastas cerradas'); return; }
      const parts = unidades.map(u =>
        this._buildStickerBlock(u, sesion, u.items || [])
        + '<div style="page-break-after:always;"></div>'
      ).join('');
      const html = this._wrapPrintPage(parts, 'media_carta', true);
      const win = window.open('', '_blank', 'width=700,height=600');
      if (win) { win.document.write(html); win.document.close(); }
      else WMS.toast('warning', 'Permite ventanas emergentes para imprimir');
    } catch(e) { WMS.toast('error', 'Error al imprimir stickers: ' + e.message); }
  },

  async recertificarSesion(sesionId, sucursal) {
    if (!confirm(`¿Recertificar "${sucursal}"?\n\nLa sesión de packing ya estaba finalizada. Se marcarán las órdenes como Certificadas.`)) return;
    try {
      const r = await API.post(`/packing/sesion/${sesionId}/recertificar`);
      const huboOmitidas = (r.data?.ordenes_omitidas || []).length > 0;
      WMS.toast(huboOmitidas ? 'warning' : 'success', r.message || 'Recertificación completada');
      this.show_certificacion();
    } catch(e) { WMS.toast('error', e.message || 'Error al recertificar'); }
  },

  async resetearCert(sesionId, sucursal) {
    const ok = await Swal.fire({
      title: '¿Resetear certificación?',
      html: `Se borrará la sesión de packing de <b>${WMS.esc(sucursal)}</b>.<br>Los pedidos quedarán <b>Pendientes</b> de certificar de nuevo.`,
      icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Sí, resetear', cancelButtonText: 'Cancelar',
      confirmButtonColor: '#dc2626',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post(`/packing/sesion/${sesionId}/reset`);
      WMS.toast('success', r.message || 'Certificación reseteada');
      this.show_certificacion();
    } catch(e) {
      Swal.fire('Error al resetear', e.message || 'Error desconocido', 'error');
    }
  },

  async resetearCertDirecta(sucursal) {
    const ok = await Swal.fire({
      title: '¿Resetear certificación?',
      html: `Los pedidos de <b>${WMS.esc(sucursal)}</b> quedarán <b>Pendientes</b> de certificar de nuevo.`,
      icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Sí, resetear', cancelButtonText: 'Cancelar',
      confirmButtonColor: '#dc2626',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post(`/picking/certificacion/resetear/${encodeURIComponent(sucursal)}`);
      WMS.toast('success', r.message || 'Certificación reseteada');
      this.show_certificacion();
    } catch(e) {
      Swal.fire('Error al resetear', e.message || 'Error desconocido', 'error');
    }
  },

  async cancelarSesionPacking(sesionId, sucursal) {
    const ok = await Swal.fire({
      title: '¿Cancelar sesión de packing?',
      html: `Se eliminarán las canastas y los ítems de <b>${WMS.esc(sucursal)}</b>.<br><small>Las órdenes ya certificadas <b>no serán afectadas</b>.</small>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, cancelar sesión',
      cancelButtonText: 'No',
      confirmButtonColor: '#dc2626',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post(`/packing/sesion/${sesionId}/cancelar`);
      WMS.toast('success', r.message || 'Sesión cancelada');
      this.show_certificacion();
    } catch(e) { WMS.toast('error', e.message || 'Error al cancelar sesión'); }
  },

  show_packing_menu() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_packing_menu()"><i class="fa-solid fa-rotate"></i> Refrescar</button>
    `);
    WMS.setContent(`
      <div class="card" style="max-width:960px;margin:0 auto;">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-packing"></i> Packing</span></div>
        <div style="padding:18px;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
          <button class="btn btn-primary" onclick="WMS_MODULES.despacho.show_certificacion()"><i class="fa-solid fa-play"></i> Iniciar/Continuar Certificación</button>
          <button class="btn btn-outline-primary" onclick="WMS_MODULES.despacho.show_packing_reprint()"><i class="fa-solid fa-print"></i> Reimprimir Rótulos / Buscar</button>
        </div>
      </div>`);
  },

  show_packing_reprint() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_packing_menu()"><i class="fa-solid fa-arrow-left"></i> Volver</button>
    `);
    WMS.setContent(`
      <div class="card" style="max-width:1100px;margin:0 auto;">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-print"></i> Reimprimir Rótulos</span></div>
        <div style="padding:18px;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;align-items:center;">
            <div><label class="fw-700">Desde</label><input type="date" id="pk-filter-desde" class="form-control"></div>
            <div><label class="fw-700">Hasta</label><input type="date" id="pk-filter-hasta" class="form-control"></div>
            <div style="min-width:220px;"><label class="fw-700">Sucursal entrega</label><input type="text" id="pk-filter-sucursal" class="form-control" placeholder="Nombre sucursal"></div>
            <div><label class="fw-700">Pedido</label><input type="text" id="pk-filter-pedido" class="form-control" placeholder="N° pedido"></div>
            <div><label class="fw-700">N° Packing (ID)</label><input type="text" id="pk-filter-sesion" class="form-control" placeholder="ID sesión"></div>
            <div style="display:flex;align-items:flex-end;"><button class="btn btn-primary" onclick="WMS_MODULES.despacho._searchPackingSessions()">Buscar</button></div>
          </div>
          <div id="pk-search-results"></div>
        </div>
      </div>`);
  },

  async _searchPackingSessions() {
    const desde = document.getElementById('pk-filter-desde').value;
    const hasta = document.getElementById('pk-filter-hasta').value;
    const sucursal = document.getElementById('pk-filter-sucursal').value.trim();
    const pedido = document.getElementById('pk-filter-pedido').value.trim();
    const sesion = document.getElementById('pk-filter-sesion').value.trim();
    WMS.spinner();
    try {
      const q = new URLSearchParams();
      if (desde) q.append('desde', desde);
      if (hasta) q.append('hasta', hasta);
      if (sucursal) q.append('sucursal', sucursal);
      if (pedido) q.append('pedido', pedido);
      if (sesion) q.append('sesion_id', sesion);
      const r = await API.get('/packing/sesiones?' + q.toString());
      const rows = r.data || [];
      this._renderPackingSearchResults(rows);
    } catch(e) { WMS.toast('error', 'Error buscando sesiones'); }
    finally { WMS.spinner(false); }
  },

  _renderPackingSearchResults(rows) {
    if (!rows || rows.length === 0) {
      document.getElementById('pk-search-results').innerHTML = '<div class="m-empty"><i class="fa-solid fa-boxes-packing"></i><p>No se encontraron sesiones</p></div>';
      return;
    }
    const html = [`<div class="table-container"><table class="erp-table"><thead><tr><th>ID</th><th>Sucursal</th><th>Tipo</th><th>Estado</th><th>Unidad(s)</th><th>Pedidos</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>`];
    rows.forEach(r => {
      html.push(`<tr>
        <td>${r.id}</td>
        <td>${WMS.esc(r.sucursal_entrega)}</td>
        <td>${WMS.esc(r.tipo_empaque)}</td>
        <td>${WMS.esc(r.estado)}</td>
        <td class="text-center">${r.num_unidades||0}</td>
        <td class="text-center">${r.num_pedidos||0}</td>
        <td>${WMS.esc(r.created_at||r.updated_at||'')}</td>
        <td>
          <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.despacho._openPackingSession(${r.id})"><i class="fa-solid fa-eye"></i></button>
          <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho._printPackingSession(${r.id})"><i class="fa-solid fa-print"></i> Stickers</button>
        </td>
      </tr>`);
    });
    html.push('</tbody></table></div>');
    document.getElementById('pk-search-results').innerHTML = html.join('');
  },

  async _openPackingSession(id) {
    WMS.spinner();
    try {
      const r = await API.get('/packing/sesion/' + id);
      if (!r.data) { WMS.toast('error', 'Sesión no encontrada'); return; }
      this._packingState.sesionId  = id;
      this._packingState.sesionData = r.data;
      (r.data.unidades || []).forEach(u => {
        this._packingState.unitsWithItems[u.id] = u.items || [];
      });
      if (r.data.sesion?.estado === 'Completada') {
        this._mostrarPanelDocumento(r.data);
      } else {
        this._renderPackingScreen(r.data);
      }
    } catch(e) { WMS.toast('error', 'Error cargando sesión'); }
    finally { WMS.spinner(false); }
  },

  async _printPackingSession(id) {
    WMS.spinner();
    try {
      const r = await API.get('/packing/sesion/' + id);
      if (!r.data) return WMS.toast('error', 'Sesión no encontrada');
      const data = r.data;
      const closed = (data.unidades||[]).filter(u => u.estado === 'Cerrada');
      const parts = closed.map(u => {
        const items = (u.items||[]);
        return this._buildStickerBlock(u, data.sesion, items) + '<div style="page-break-after:always;"></div>';
      }).join('');
      const html = this._wrapPrintPage(parts, 'letter');
      const win = window.open('', '_blank', 'width=680,height=500');
      if (win) { win.document.write(html); win.document.close(); }
      else WMS.toast('error', 'Popup bloqueado, permita ventanas emergentes');
    } catch(e) { WMS.toast('error', 'Error imprimiendo sesión'); }
    finally { WMS.spinner(false); }
  },

  async iniciarCertificacion(sucursal) {
    WMS.spinner();
    try {
      const imps = (await API.get('/impresoras')).data || [];
      this._showPackingDialog(sucursal, imps);
    } catch(e) { WMS.toast('error', 'Error al cargar impresoras'); }
  },

  async autoCertificar(sucursal) {
    const ok = await Swal.fire({
      title: '¿Auto-Certificar a una sola canasta?',
      html: `Se creará una sesión, se empacarán todos los productos pendientes de <b>${WMS.esc(sucursal)}</b> en una sola canasta, y se finalizará automáticamente.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, Auto-Certificar',
      cancelButtonText: 'Cancelar'
    });
    if (!ok.isConfirmed) return;
    
    WMS.spinner();
    try {
      const r = await API.post('/packing/autopack', { sucursal_entrega: sucursal, tipo_empaque: 'canasta' });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Certificación automática completada');
      this.show_certificacion(); // recargar
    } catch(e) {
      WMS.toast('error', e.message || 'Error en Auto-Certificación');
    }
  },

  _showPackingDialog(sucursal, impresoras) {
    const mkOpts = (tipo) => impresoras
      .filter(i => !i.tipos_trabajo?.length || i.tipos_trabajo.includes(tipo))
      .map(i => `<option value="${i.id}">${WMS.esc(i.nombre)}</option>`)
      .join('');

    const html = `
      <div id="packing-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px 32px;min-width:420px;max-width:500px;box-shadow:0 8px 40px rgba(0,0,0,.25);">
          <h3 style="margin:0 0 20px;color:#1e293b;font-size:17px;">
            <i class="fa-solid fa-boxes-packing"></i> Iniciar Packing — <span style="color:#1e40af;">${WMS.esc(sucursal)}</span>
          </h3>
          <div style="margin-bottom:16px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:8px;">Tipo de empaque</label>
            <div style="display:flex;gap:16px;">
              ${['canasta','caja','paquete'].map((t,i) => `
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                  <input type="radio" name="pk-tipo" value="${t}" ${i===0?'checked':''}> ${t.charAt(0).toUpperCase()+t.slice(1)}
                </label>`).join('')}
            </div>
          </div>
          <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora stickers</label>
            <select id="pd-imp-sticker" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('sticker_packing')}
            </select>
          </div>
          <div style="margin-bottom:22px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora documento</label>
            <select id="pd-imp-doc" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('documento_packing')}
            </select>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('packing-dialog-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" data-sucursal="${WMS.esc(sucursal)}" onclick="WMS_MODULES.despacho._confirmarDialogPacking(this.dataset.sucursal)">
              <i class="fa-solid fa-play"></i> Iniciar
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async _confirmarDialogPacking(sucursal) {
    const tipo       = document.querySelector('input[name="pk-tipo"]:checked')?.value || 'caja';
    const impSticker = document.getElementById('pd-imp-sticker')?.value || null;
    const impDoc     = document.getElementById('pd-imp-doc')?.value || null;
    document.getElementById('packing-dialog-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion', {
        sucursal_entrega:     sucursal,
        tipo_empaque:         tipo,
        impresora_sticker_id: impSticker || null,
        impresora_doc_id:     impDoc || null,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      await this.show_packing(r.data.sesion_id);
    } catch(e) { WMS.toast('error', 'Error al iniciar sesión de packing'); }
  },

  _renderCertInterface(sucursal, lineas) {
    const totalLines = lineas.length;
    const certLines  = lineas.filter(l => l.cantidad_certificada > 0).length;
    const progress   = totalLines > 0 ? Math.round((certLines / totalLines) * 100) : 0;

    const ambients = [...new Set(lineas.map(l => l.ambiente_nombre || 'Sin ambiente'))];
    const ambientProgress = ambients.map(a => {
       const lins = lineas.filter(l => (l.ambiente_nombre || 'Sin ambiente') === a);
       const t = lins.length;
       const c = lins.filter(l => l.cantidad_certificada > 0).length;
       return { name: a, total: t, cert: c };
    });

    WMS.setContent(`
      <div class="cert-workflow-container">
        <div class="cert-header">
          <div class="cert-header-left">
            <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
              <i class="fa-solid fa-arrow-left"></i> Volver
            </button>
            <h2 class="cert-title">Certificando: <strong>${WMS.esc(sucursal)}</strong></h2>
          </div>
          <div class="cert-progress-box">
             <div class="cert-progress-info">
               <span>Progreso: <strong>${certLines} / ${totalLines}</strong> líneas</span>
               <span>${progress}%</span>
             </div>
             <div class="pro-progress-bar-bg"><div class="pro-progress-bar-fill ${progress>=100?'green':''}" style="width:${progress}%"></div></div>
          </div>
        </div>

        <div class="cert-body">
          <div class="cert-scanner-box">
            <div class="scanner-input-wrap">
              <i class="fa-solid fa-barcode"></i>
              <input type="text" id="cert-scanner" placeholder="Escanee producto o ingrese código..." 
                     onkeyup="if(event.key==='Enter') WMS_MODULES.despacho.procesarEscaneo('${WMS.esc(sucursal)}')">
              <button class="btn btn-primary" onclick="WMS_MODULES.despacho.procesarEscaneo('${WMS.esc(sucursal)}')">Validar</button>
            </div>
            <p class="text-muted text-sm" style="margin-top:8px;"><i class="fa-solid fa-keyboard"></i> También puede seleccionar un producto de la lista para certificarlo manualmente.</p>
          </div>

          <div style="margin-top:15px; margin-bottom:10px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-sm btn-primary cert-amb-tab" id="cert-tab-all" onclick="WMS_MODULES.despacho.filterCertAmbiente('')">
              <i class="fa-solid fa-layer-group"></i> Todos (${certLines}/${totalLines})
            </button>
            ${ambientProgress.map(ap => `
              <button class="btn btn-sm btn-outline-primary cert-amb-tab" id="cert-tab-${WMS.esc(ap.name)}" onclick="WMS_MODULES.despacho.filterCertAmbiente('${WMS.esc(ap.name)}')">
                <i class="fa-solid fa-box"></i> ${WMS.esc(ap.name)} (${ap.cert}/${ap.total})
              </button>
            `).join('')}
          </div>

          <div class="card" style="margin-top:20px;">
            <div class="table-container" style="max-height:calc(100vh - 350px); overflow-y:auto;">
              <table class="erp-table" id="table-cert-lines">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-center">Ambiente</th>
                    <th class="text-center">EAN/Código</th>
                    <th class="text-center">Pickeado</th>
                    <th class="text-center">Certificado</th>
                    <th class="text-center">Diferencia</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acción</th>
                  </tr>
                </thead>
                <tbody>
                  ${lineas.map(l => {
                    const diff = l.cantidad_pickeada - l.cantidad_certificada;
                    const st = l.cantidad_certificada === 0 ? 'pendiente' : (diff === 0 ? 'ok' : 'error');
                    return `
                    <tr id="cert-row-${l.producto_id}" class="cert-row-${st}" data-ean="${WMS.esc(l.ean)}" data-codigo="${WMS.esc(l.codigo)}" data-ambiente="${WMS.esc(l.ambiente_nombre || 'Sin ambiente')}">
                      <td>
                        <div class="fw-700">${WMS.esc(l.nombre)}</div>
                      </td>
                      <td class="text-center"><span class="badge" style="background-color:${l.ambiente_color||'#64748b'};color:#fff;">${WMS.esc(l.ambiente_nombre || 'Sin ambiente')}</span></td>
                      <td class="text-center"><code style="font-size:11px;">${WMS.esc(l.ean)}</code></td>
                      <td class="text-center fw-700" style="font-size:1.1rem;">${WMS.formatNum(l.cantidad_pickeada)}</td>
                      <td class="text-center fw-700" style="font-size:1.1rem; color:var(--primary);">${WMS.formatNum(l.cantidad_certificada)}</td>
                      <td class="text-center">
                         ${l.cantidad_certificada > 0 ? (diff === 0 ? '<span class="status-badge success"><i class="fa-solid fa-check"></i></span>' : `<span class="badge badge-danger">${diff > 0 ? '-' : '+'}${WMS.formatNum(Math.abs(diff))}</span>`) : '—'}
                      </td>
                      <td class="text-center">
                         <span class="pro-badge ${st === 'ok' ? 'ok' : st === 'error' ? 'warn' : 'info'}">
                           ${st === 'ok' ? 'Correcto' : st === 'error' ? 'Diferencia' : 'Pendiente'}
                         </span>
                      </td>
                      <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho.manualCert('${WMS.esc(sucursal)}', ${l.producto_id}, '${WMS.esc(l.nombre)}', ${l.cantidad_pickeada}, ${l.cantidad_certificada})">
                          <i class="fa-solid fa-edit"></i>
                        </button>
                      </td>
                    </tr>`;
                  }).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="cert-footer">
          <div class="cert-footer-left">
            <span class="text-muted">Sucursal: ${WMS.esc(sucursal)}</span>
          </div>
          <div class="cert-footer-actions">
            <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">Cancelar Proceso</button>
            <button class="btn btn-success" onclick="WMS_MODULES.despacho.finalizarCertificacion('${WMS.esc(sucursal)}')" ${progress < 100 ? 'disabled title="Certifique todas las líneas antes de finalizar"' : ''}>
              <i class="fa-solid fa-check-double"></i> Finalizar y Generar PDF
            </button>
          </div>
        </div>
      </div>
    `);
    
    // Auto-focus scanner
    setTimeout(() => document.getElementById('cert-scanner')?.focus(), 200);
  },

  filterCertAmbiente(amb) {
    document.querySelectorAll('.cert-amb-tab').forEach(b => {
      b.classList.remove('btn-primary'); b.classList.add('btn-outline-primary');
    });
    const btn = amb ? document.getElementById('cert-tab-'+amb) : document.getElementById('cert-tab-all');
    if (btn) { btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-primary'); }
    
    document.querySelectorAll('#table-cert-lines tbody tr').forEach(tr => {
      if (!amb || tr.dataset.ambiente === amb) tr.style.display = '';
      else tr.style.display = 'none';
    });
  },

  async procesarEscaneo(sucursal) {
    const input = document.getElementById('cert-scanner');
    const val   = input.value.trim();
    if (!val) return;
    
    // Buscar en la tabla por EAN o Código
    const rows = document.querySelectorAll('#table-cert-lines tbody tr');
    let match = null;
    rows.forEach(r => {
        if (r.dataset.ean === val || r.dataset.codigo === val) match = r;
    });

    if (match) {
        const pid = match.id.replace('cert-row-', '');
        // For simplicity, we ask for quantity even on scan if it's not a single unit scan flow
        // Or we can just cert the whole picked qty
        const nombre = match.querySelector('div.fw-700').textContent;
        const pick   = parseFloat(match.cells[2].textContent);
        const cert   = parseFloat(match.cells[3].textContent);
        
        input.value = '';
        this.manualCert(sucursal, pid, nombre, pick, cert);
    } else {
        WMS.toast('error', 'Producto no encontrado en este despacho');
        input.select();
    }
  },

  manualCert(sucursal, pid, nombre, pick, actual) {
    const nueva = prompt(`Certificando: ${nombre}\n\nCantidad Pickeada: ${pick}\nIngrese la cantidad encontrada:`, actual || pick);
    if (nueva === null || nueva === "" || isNaN(nueva)) return;

    this.confirmarLineaCert(sucursal, pid, parseFloat(nueva));
  },

  async confirmarLineaCert(sucursal, pid, cantidad) {
    WMS.spinner();
    try {
        const r = await API.post('/picking/certificacion/confirmar', {
            sucursal_entrega: sucursal,
            producto_id: pid,
            cantidad: cantidad
        });
        if (r.error) WMS.toast('error', r.message);
        else {
            WMS.toast('success', 'Línea certificada');
            this.iniciarCertificacion(sucursal); // Refresh
        }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async finalizarCertificacion(sucursal) {
    if (!confirm('¿Desea finalizar la certificación de ' + sucursal + '? Se generarán las novedades si existen diferencias.')) return;

    try {
      const miscCheck = await API.get('/miscelaneos?pendientes_cliente=' + encodeURIComponent(sucursal));
      const miscPend = miscCheck.data || [];
      if (miscPend.length) {
        const lista = miscPend.map(m => `• ${m.articulo} (${m.cantidad} ${m.unidad_medida||'UN'}) — Prov: ${m.proveedor}`).join('\n');
        const incluir = confirm(`⚠️ MISCELÁNEOS PENDIENTES\n\nEste cliente tiene ${miscPend.length} misceláneo(s) pendiente(s) de envío:\n\n${lista}\n\n¿Desea continuar y despachar los misceláneos junto con este envío?`);
        if (incluir) {
          for (const m of miscPend) {
            await API.post('/miscelaneos/' + m.id + '/despachar', {});
          }
        }
      }
    } catch(e) { /* continuar sin verificar misceláneos */ }

    WMS.spinner();
    try {
        const r = await API.post('/picking/certificacion/finalizar', { sucursal_entrega: sucursal });
        if (r.error) { WMS.toast('error', r.message); return; }

        const data       = r.data  || {};
        const totalFalt  = data.faltantes_detectados || 0;
        const nuevosFalt = data.nuevos_faltantes     || 0;

        WMS.toast('success', 'Certificación finalizada exitosamente');

        // Intentar imprimir automáticamente
        try {
            const rp = await API.get('/picking/certificacion/imprimir/' + encodeURIComponent(sucursal));
            if (rp.error) WMS.toast('warning', 'Certificado finalizado pero error en impresión: ' + rp.message);
            else {
                const labelMsg = rp.label?.error ? 'Error Etiqueta: ' + rp.label.message : 'Etiqueta impresa OK';
                const docMsg   = rp.document?.error ? 'Error Documento: ' + rp.document.message : 'Documento impreso OK';
                WMS.toast('success', `Impresión: ${labelMsg} | ${docMsg}`);
            }
        } catch(e) { WMS.toast('warning', 'Error al intentar imprimir'); }

        // Mostrar modal de faltantes si los hay
        if (totalFalt > 0) {
            WMS.showModal(
                '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;margin-right:8px;"></i>Faltantes Detectados en Certificación',
                `<div style="padding:14px 18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;margin-bottom:14px;">
                  <div style="font-size:16px;font-weight:800;color:#92400e;margin-bottom:6px;">
                    <i class="fa-solid fa-box-open" style="color:#f59e0b;margin-right:8px;"></i>
                    ${totalFalt} referencia(s) con faltante en este despacho
                  </div>
                  <div style="font-size:13px;color:#78350f;line-height:1.6;">
                    ${nuevosFalt > 0 ? `<strong>${nuevosFalt}</strong> faltante(s) recién capturado(s) al certificar.<br>` : ''}
                    Estos artículos quedan registrados en el <strong>módulo de Agotados</strong> y aparecerán en la sección de agotados de la remisión.<br>
                    Cuando llegue inventario puede liberarlos mediante el proceso de <strong>backorder</strong>.
                  </div>
                </div>
                <div style="padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;color:#1e40af;">
                  <i class="fa-solid fa-lightbulb" style="margin-right:6px;"></i>
                  <strong>Próximo paso:</strong> Revise el módulo <strong>Agotados</strong> en Picking para gestionar backorders o el módulo <strong>Faltantes</strong> para reprocesar.
                </div>`,
                `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
                 <button class="btn btn-warning" onclick="WMS.closeModal('generic-modal');WMS_MODULES.picking&&WMS_MODULES.picking.load('agotados');">
                   <i class="fa-solid fa-box-open"></i> Ver Agotados
                 </button>`,
                { width: '600px' }
            );
        }

        this.show_certificacion();
    } catch(e) { WMS.toast('error', 'Error finalizando'); }
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

      WMS.showRightPanel('Análisis de Planilla #' + (p.numero_planilla || id), `
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
        `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cerrar Ventana</button>
         ${p.estado === 'ConNovedad' ? `<button class="btn btn-warning" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-triangle-exclamation"></i> Forzar Salida</button>` : ''}
         ${p.estado === 'Completada' ? `<button class="btn btn-primary" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-truck-loading"></i> Proceder a Cargue</button>` : ''}`);
    } catch(e) { 
        console.error(e);
        WMS.toast('error', 'Error cargando analítica de planilla'); 
    }
  },

  async adminEditCert(sucursal) {
    WMS.spinner();
    try {
      const r = await API.get('/picking/certificacion/admin-detalle/' + encodeURIComponent(sucursal));
      const pedidos = r.data || [];
      if (!pedidos.length) { WMS.toast('warning', 'No se encontraron líneas para esta sucursal'); return; }

      const rows = [];
      pedidos.forEach(p => {
        (p.lineas || []).forEach(l => {
          rows.push({
            det_id: l.id, orden_id: p.id,
            numero: p.numero_pedido || `#${p.id}`,
            codigo: l.producto_codigo || '',
            nombre: l.producto_nombre || '',
            pickeado: parseFloat(l.cantidad_pickeada) || 0,
            certificado: parseFloat(l.cantidad_certificada) || 0,
            estado_cert: l.estado_certificacion || '',
          });
        });
      });

      this._certEditRows     = rows;
      this._certEditSucursal = sucursal;

      const totalLineas = rows.length;
      const totalUds    = rows.reduce((s, r) => s + r.certificado, 0);

      const bodyHTML = `
        <div style="display:flex;align-items:center;gap:12px;padding:0 0 14px;border-bottom:1px solid #e5e7eb;margin-bottom:16px;">
          <div style="flex:1;">
            <div style="font-size:11px;color:#6b7280;margin-bottom:2px;">Sucursal</div>
            <div style="font-size:15px;font-weight:700;color:#111827;">${WMS.esc(sucursal)}</div>
          </div>
          <div style="text-align:center;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 16px;">
            <div style="font-size:11px;color:#6b7280;">Líneas</div>
            <div style="font-size:18px;font-weight:800;color:#15803d;">${totalLineas}</div>
          </div>
          <div style="text-align:center;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 16px;">
            <div style="font-size:11px;color:#6b7280;">Uds. Certif.</div>
            <div style="font-size:18px;font-weight:800;color:#1d4ed8;">${WMS.formatNum(totalUds)}</div>
          </div>
          <span id="cert-edit-badge" style="background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;border-radius:20px;font-size:11px;font-weight:700;padding:4px 12px;">
            Sin cambios
          </span>
        </div>

        <div style="overflow-x:auto;max-height:55vh;overflow-y:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead style="position:sticky;top:0;z-index:2;">
              <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:10px 8px;text-align:left;color:#475569;font-weight:600;white-space:nowrap;">Pedido</th>
                <th style="padding:10px 8px;text-align:left;color:#475569;font-weight:600;">Código</th>
                <th style="padding:10px 8px;text-align:left;color:#475569;font-weight:600;">Producto</th>
                <th style="padding:10px 8px;text-align:center;color:#475569;font-weight:600;">Pickeado</th>
                <th style="padding:10px 8px;text-align:center;color:#475569;font-weight:600;">Certificado</th>
                <th style="padding:10px 8px;text-align:center;color:#475569;font-weight:600;min-width:120px;">Nueva Cant.</th>
                <th style="padding:10px 8px;text-align:center;color:#475569;font-weight:600;">Diferencia</th>
              </tr>
            </thead>
            <tbody id="cert-edit-tbody">
              ${rows.map((row, i) => `
              <tr id="cert-row-${i}" style="border-bottom:1px solid #f1f5f9;transition:background .15s,border-left .15s;border-left:3px solid transparent;">
                <td style="padding:8px;font-size:11px;color:#6b7280;white-space:nowrap;">${WMS.esc(row.numero)}</td>
                <td style="padding:8px;font-family:monospace;font-size:11px;color:#64748b;">${WMS.esc(row.codigo)}</td>
                <td style="padding:8px;color:#1e293b;font-weight:500;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${WMS.esc(row.nombre)}">${WMS.esc(row.nombre)}</td>
                <td style="padding:8px;text-align:center;font-weight:700;color:#2563eb;">${WMS.formatNum(row.pickeado)}</td>
                <td style="padding:8px;text-align:center;color:${row.certificado>0?'#15803d':'#dc2626'};">${WMS.formatNum(row.certificado)}</td>
                <td style="padding:8px;text-align:center;">
                  <input id="ceq-${i}" type="number" min="0" step="0.001"
                    value="${row.certificado}" data-orig="${row.certificado}" data-saved="0"
                    style="width:100px;padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;text-align:center;font-size:12px;outline:none;transition:border .15s;"
                    oninput="WMS_MODULES.despacho._certEditOnInput(${i})"
                    onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
                </td>
                <td id="cert-diff-${i}" style="padding:8px;text-align:center;font-size:11px;color:#9ca3af;">—</td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>`;

      const footerHTML = `
        <button class="btn btn-secondary btn-sm" onclick="WMS.closeModal('generic-modal')">
          <i class="fa-solid fa-xmark"></i> Cerrar
        </button>
        <button id="cert-edit-save-btn" class="btn btn-primary btn-sm" disabled style="opacity:.5;margin-left:6px;"
          onclick="WMS_MODULES.despacho._certEditGuardarLote()">
          <i class="fa-solid fa-floppy-disk"></i> Sin cambios
        </button>`;

      WMS.showModal(`Editar Certificación`, bodyHTML, footerHTML, 'xl');

    } catch(e) { WMS.toast('error', 'Error cargando detalle: ' + e.message); }
    finally { WMS.spinner(false); }
  },

  _certEditOnInput(idx) {
    const inp = document.getElementById(`ceq-${idx}`);
    if (!inp) return;
    inp.dataset.saved = '0';
    const orig = parseFloat(inp.dataset.orig);
    const cur  = parseFloat(inp.value);
    const diff = cur - orig;
    const changed = Math.abs(diff) > 0.001;

    const row = document.getElementById(`cert-row-${idx}`);
    if (row) {
      row.style.background  = changed ? '#fffbeb' : '';
      row.style.borderLeft  = changed ? '3px solid #f59e0b' : '3px solid transparent';
    }
    const diffEl = document.getElementById(`cert-diff-${idx}`);
    if (diffEl) {
      if (!changed) { diffEl.textContent = '—'; diffEl.style.color = '#9ca3af'; }
      else {
        diffEl.textContent = (diff > 0 ? '+' : '') + WMS.formatNum(diff);
        diffEl.style.color  = diff > 0 ? '#dc2626' : '#15803d';
        diffEl.style.fontWeight = '700';
      }
    }

    const rows = this._certEditRows || [];
    const total = rows.filter((_, i) => {
      const el = document.getElementById(`ceq-${i}`);
      return el && Math.abs(parseFloat(el.value) - _.certificado) > 0.001;
    }).length;

    const badge = document.getElementById('cert-edit-badge');
    if (badge) {
      badge.textContent  = total ? `${total} cambio(s) pendiente(s)` : 'Sin cambios';
      badge.style.background  = total ? '#fef3c7' : '#f3f4f6';
      badge.style.color       = total ? '#92400e' : '#6b7280';
      badge.style.borderColor = total ? '#fcd34d' : '#e5e7eb';
    }
    const saveBtn = document.getElementById('cert-edit-save-btn');
    if (saveBtn) {
      saveBtn.innerHTML = total
        ? `<i class="fa-solid fa-floppy-disk"></i> Guardar ${total} cambio(s)`
        : '<i class="fa-solid fa-floppy-disk"></i> Sin cambios';
      saveBtn.disabled     = total === 0;
      saveBtn.style.opacity = total ? '1' : '0.5';
    }
  },

  async _certEditGuardarLote() {
    const rows    = this._certEditRows || [];
    const sucursal = this._certEditSucursal || '';
    const lineas  = [];

    rows.forEach((row, i) => {
      const inp   = document.getElementById(`ceq-${i}`);
      if (!inp) return;
      const nueva = parseFloat(inp.value);
      if (isNaN(nueva) || nueva < 0) return;
      if (Math.abs(nueva - row.certificado) > 0.001) {
        lineas.push({ det_id: row.det_id, cantidad_certificada: nueva, _prev: row.certificado, _nombre: row.nombre });
      }
    });

    if (!lineas.length) { WMS.toast('warning', 'No hay cambios para guardar'); return; }

    const saveBtn = document.getElementById('cert-edit-save-btn');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...'; }

    try {
      const r = await API.put(`/picking/certificacion/admin-lote/${encodeURIComponent(sucursal)}`, { lineas });
      const d = r.data || {};

      // Actualizar estado en memoria y DOM
      rows.forEach((row, i) => {
        const inp = document.getElementById(`ceq-${i}`);
        if (!inp) return;
        const cambio = lineas.find(l => l.det_id === row.det_id);
        if (!cambio) return;
        const nueva = parseFloat(inp.value);
        inp.dataset.saved = '1';
        inp.dataset.orig  = nueva;
        row.certificado   = nueva;
        const tr = document.getElementById(`cert-row-${i}`);
        if (tr) { tr.style.background = '#f0fdf4'; tr.style.borderLeft = '3px solid #22c55e'; }
        const diffEl = document.getElementById(`cert-diff-${i}`);
        if (diffEl) { diffEl.textContent = '✓'; diffEl.style.color = '#22c55e'; }
        // Actualizar celda Certificado
        const cells = tr ? tr.querySelectorAll('td') : [];
        if (cells[4]) { cells[4].textContent = WMS.formatNum(nueva); cells[4].style.color = nueva > 0 ? '#15803d' : '#dc2626'; }
      });

      const badge = document.getElementById('cert-edit-badge');
      if (badge) { badge.textContent = 'Guardado ✓'; badge.style.background = '#dcfce7'; badge.style.color = '#15803d'; badge.style.borderColor = '#86efac'; }
      if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> Guardado'; saveBtn.style.opacity = '0.6'; }

      // Resumen de auditoría
      const resumenLineas = lineas.map(l => {
        const diff = l.cantidad_certificada - l._prev;
        return `<tr style="font-size:12px;">
          <td style="padding:4px 8px;">${WMS.esc(l._nombre)}</td>
          <td style="padding:4px 8px;text-align:center;">${WMS.formatNum(l._prev)}</td>
          <td style="padding:4px 8px;text-align:center;font-weight:700;">${WMS.formatNum(l.cantidad_certificada)}</td>
          <td style="padding:4px 8px;text-align:center;color:${diff>0?'#dc2626':'#15803d'};font-weight:700;">${diff>0?'+':''}${WMS.formatNum(diff)}</td>
        </tr>`;
      }).join('');

      WMS.toast('success',
        `${d.actualizadas || 0} línea(s) actualizada(s)` +
        (d.inventario_ajustado ? ` · ${d.inventario_ajustado} ajuste(s) de inventario` : '') +
        (d.ordenes_afectadas   ? ` · ${d.ordenes_afectadas} orden(es) recalculada(s)` : '')
      );
      this.show_certificacion();

    } catch(e) {
      WMS.toast('error', e.message || 'Error al guardar');
      if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reintentar'; saveBtn.style.opacity = '1'; }
    }
  },

  async _guardarCertEdit(ordenId, detId, idx) {
    // Método legacy — redirige al flujo lote
    this._certEditGuardarLote();
  },

  async _recalcularRemisionSucursal(sucursal) {
    WMS.spinner();
    try {
      const r = await API.get('/picking/certificacion/admin-detalle/' + encodeURIComponent(sucursal));
      const pedidos = r.data || [];
      // Llamar en paralelo a todos los pedidos
      const results = await Promise.allSettled(
        pedidos.map(p => API.post(`/picking/${p.id}/recalcular-remision`, {}))
      );
      const ok = results.filter(r => r.status === 'fulfilled').length;
      WMS.toast('success', `Remisión recalculada para ${ok} de ${pedidos.length} pedido(s).`);
      WMS.closeRightPanel();
      this.show_certificacion();
    } catch(e) { WMS.toast('error', 'Error al recalcular: ' + e.message); }
    finally { WMS.spinner(false); }
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
    WMS.showRightPanel('Asignar Certificador', `
      <div class="form-group"><label class="form-label">Certificador <span class="required">*</span></label>
        <select id="cert-personal" class="form-control">
          <option value="">Seleccionar...</option>
          ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre||'')} — ${WMS.esc(p.rol||'')}</option>`).join('')}
        </select></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.confirmarAsigCert(${planillaId})"><i class="fa-solid fa-user-check"></i> Asignar</button>`);
  },

  async confirmarAsigCert(id) {
    const pid = document.getElementById('cert-personal')?.value;
    if (!pid) { WMS.toast('warning', 'Seleccione un certificador'); return; }
    try {
      const r = await API.post('/planillas/asignar', { planilla_id: id, personal_id: parseInt(pid) });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Certificador asignado'); WMS.closeRightPanel(); this.show_certificacion(); }
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
    WMS.setToolbar(`
      <div class="btn-group">
        <button class="btn btn-primary btn-sm" id="tab-cargue-pedidos" onclick="WMS_MODULES.despacho._renderPedidosPendientes()"><i class="fa-solid fa-box-open"></i> Pedidos Pendientes</button>
        <button class="btn btn-outline-primary btn-sm" id="tab-cargue-planillas" onclick="WMS_MODULES.despacho._renderPlanillasCreadas()"><i class="fa-solid fa-truck-loading"></i> Planillas Creadas</button>
      </div>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.despacho.exportCargueExcel()"><i class="fa-solid fa-file-excel"></i> Exportar</button>
    `);
    
    WMS.setContent(`<div id="cargue-content-wrap"></div>`);
    this._renderPedidosPendientes();
  },

  async _renderPedidosPendientes() {
    document.getElementById('tab-cargue-pedidos').className = 'btn btn-primary btn-sm';
    document.getElementById('tab-cargue-planillas').className = 'btn btn-outline-primary btn-sm';
    
    WMS.spinner();
    try {
      const r = await API.get('/picking', 'estado_certificacion=Certificada&sin_despacho=1&limit=500&incluir_finalizados=1');
      this._pedidosCarguePendientes = r.data || r || [];
      
      const wrap = document.getElementById('cargue-content-wrap');
      if(!wrap) return;
      
      wrap.innerHTML = `
        <div class="filter-bar" style="flex-wrap:wrap;gap:12px;background:#f8fafc;padding:12px;border-radius:6px;margin-bottom:12px;border:1px solid #e2e8f0;">
          <div style="flex:1;min-width:150px;">
            <label style="font-size:0.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block;">Fecha Desde</label>
            <input type="date" id="cp-f-desde" class="form-control form-control-sm" onchange="WMS_MODULES.despacho._filtrarPedidosCargue()">
          </div>
          <div style="flex:1;min-width:150px;">
            <label style="font-size:0.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block;">Fecha Hasta</label>
            <input type="date" id="cp-f-hasta" class="form-control form-control-sm" onchange="WMS_MODULES.despacho._filtrarPedidosCargue()">
          </div>
          <div style="flex:2;min-width:200px;">
            <label style="font-size:0.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block;">Buscador (Pedido, Cliente o Sucursal)</label>
            <div class="search-bar" style="margin:0;"><i class="fa-solid fa-search"></i>
              <input type="text" id="cp-f-texto" placeholder="Escriba para filtrar en tiempo real..." oninput="WMS_MODULES.despacho._filtrarPedidosCargue()">
            </div>
          </div>
          <div style="flex:1;min-width:150px;">
            <label style="font-size:0.75rem;font-weight:600;color:#64748b;margin-bottom:4px;display:block;">Planilla Picking</label>
            <input type="text" id="cp-f-planilla" class="form-control form-control-sm" placeholder="# Planilla" oninput="WMS_MODULES.despacho._filtrarPedidosCargue()">
          </div>
        </div>
        
        <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
          <span style="font-weight:bold;color:#334155;"><i class="fa-solid fa-cubes"></i> <span id="cp-counter">0</span> pedidos listos</span>
          <button class="btn btn-success" onclick="WMS_MODULES.despacho.nuevoPlanillaCargueMasivo()"><i class="fa-solid fa-truck"></i> Crear Planilla con Seleccionados</button>
        </div>
        
        <div class="card">
          <div class="table-container">
            <table class="erp-table">
              <thead>
                <tr>
                  <th><input type="checkbox" id="cp-chk-all" onchange="document.querySelectorAll('.cp-chk').forEach(c => { if(c.offsetParent !== null) c.checked=this.checked })"></th>
                  <th>Planilla Picking</th>
                  <th>Pedido / Factura</th>
                  <th>Cliente / Sucursal</th>
                  <th>Fecha</th>
                </tr>
              </thead>
              <tbody id="cp-tbody">
              </tbody>
            </table>
          </div>
        </div>
      `;
      
      this._filtrarPedidosCargue();
      WMS.closeSpinner();
    } catch(e) {
      WMS.toast('error', 'Error cargando pedidos pendientes');
      WMS.closeSpinner();
    }
  },

  _filtrarPedidosCargue() {
    const pedidos = this._pedidosCarguePendientes || [];
    const tbody = document.getElementById('cp-tbody');
    if(!tbody) return;
    
    const fDesde = document.getElementById('cp-f-desde')?.value || '';
    const fHasta = document.getElementById('cp-f-hasta')?.value || '';
    const fTexto = (document.getElementById('cp-f-texto')?.value || '').toLowerCase();
    const fPlan = (document.getElementById('cp-f-planilla')?.value || '').toLowerCase();
    
    let html = '';
    let count = 0;
    
    for(const p of pedidos) {
      if (fDesde && p.fecha_movimiento < fDesde) continue;
      if (fHasta && p.fecha_movimiento > fHasta) continue;
      if (fPlan && !(p.planilla_numero||'').toLowerCase().includes(fPlan)) continue;
      if (fTexto) {
        const textStr = ( (p.numero_orden||'') + ' ' + (p.numero_factura||'') + ' ' + (p.cliente||'') + ' ' + (p.sucursal_entrega||'') ).toLowerCase();
        if (!textStr.includes(fTexto)) continue;
      }
      
      html += `<tr>
        <td><input type="checkbox" class="cp-chk" value="${p.id}"></td>
        <td><span class="badge badge-info">${WMS.esc(p.planilla_numero||'-')}</span></td>
        <td><strong>${WMS.esc(p.numero_orden || p.numero_factura || ('#'+p.id))}</strong></td>
        <td>${WMS.esc(p.cliente || p.sucursal_entrega || '-')}</td>
        <td>${WMS.formatDate(p.fecha_movimiento)}</td>
      </tr>`;
      count++;
    }
    
    if (count === 0) html = '<tr><td colspan="5" class="table-empty">No se encontraron pedidos con estos filtros</td></tr>';
    tbody.innerHTML = html;
    const cEl = document.getElementById('cp-counter');
    if(cEl) cEl.innerText = count;
  },

  async _renderPlanillasCreadas() {
    document.getElementById('tab-cargue-pedidos').className = 'btn btn-outline-primary btn-sm';
    document.getElementById('tab-cargue-planillas').className = 'btn btn-primary btn-sm';

    let sucursalOpts = '<option value="">Mi sucursal</option>';
    try {
      const rs = await API.get('/param/sucursales');
      const sucursales = rs.data || rs || [];
      sucursalOpts += sucursales.map(s => `<option value="${s.id}">${WMS.esc(s.nombre)}</option>`).join('');
    } catch(e) {}

    const hoy = new Date().toISOString().substring(0, 10);
    this._cargueFiltros = { desde: hoy, hasta: hoy, sucursal_id: '', estado: '' };

    const wrap = document.getElementById('cargue-content-wrap');
    if(!wrap) return;

    wrap.innerHTML = `
      <div class="filter-bar" style="flex-wrap:wrap;gap:8px;align-items:flex-end;">
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Desde</label>
          <input type="date" id="cargue-f-desde" class="form-control form-control-sm" value="${hoy}" style="width:150px">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Hasta</label>
          <input type="date" id="cargue-f-hasta" class="form-control form-control-sm" value="${hoy}" style="width:150px">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Sucursal</label>
          <select id="cargue-f-sucursal" class="form-control form-control-sm" style="width:170px">${sucursalOpts}</select>
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Estado</label>
          <select id="cargue-f-estado" class="form-control form-control-sm" style="width:150px">
            <option value="">Todos</option>
            <option value="Preparando">Preparando</option>
            <option value="Certificado">Certificado</option>
            <option value="Despachado">Despachado</option>
            <option value="Entregado">Entregado</option>
            <option value="Cancelado">Cancelado</option>
          </select>
        </div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho._aplicarFiltrosCargue()"><i class="fa-solid fa-filter"></i> Filtrar</button>
        <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.despacho._hoyFiltrosCargue()"><i class="fa-solid fa-calendar-day"></i> Hoy</button>
        <div class="search-bar" style="flex:1;min-width:200px;"><i class="fa-solid fa-search"></i>
          <input placeholder="Buscar placa, conductor, ruta..." oninput="WMS_MODULES.despacho.filterTable(this.value,'cargue-table')">
        </div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.nuevoPlanillaCargue()"><i class="fa-solid fa-plus"></i> Nuevo Cargue Vacío</button>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title" id="cargue-count"><i class="fa-solid fa-truck-loading"></i> Planillas de Cargue</span></div>
        <div class="table-container" id="cargue-table-wrap"><div class="spinner sm" style="margin:20px auto;display:block;"></div></div>
      </div>`;

    this._loadCargueTabla();
  },

  _hoyFiltrosCargue() {
    const hoy = new Date().toISOString().substring(0, 10);
    document.getElementById('cargue-f-desde').value = hoy;
    document.getElementById('cargue-f-hasta').value = hoy;
    this._aplicarFiltrosCargue();
  },

  _aplicarFiltrosCargue() {
    this._cargueFiltros = {
      desde: document.getElementById('cargue-f-desde')?.value || '',
      hasta: document.getElementById('cargue-f-hasta')?.value || '',
      sucursal_id: document.getElementById('cargue-f-sucursal')?.value || '',
      estado: document.getElementById('cargue-f-estado')?.value || '',
    };
    this._loadCargueTabla();
  },

  _cargueQueryString() {
    const f = this._cargueFiltros || {};
    const p = new URLSearchParams();
    if (f.desde) p.append('fecha_inicio', f.desde);
    if (f.hasta) p.append('fecha_fin', f.hasta);
    if (f.sucursal_id) p.append('sucursal_id', f.sucursal_id);
    if (f.estado) p.append('estado', f.estado);
    return p.toString();
  },

  async _loadCargueTabla() {
    const wrap = document.getElementById('cargue-table-wrap');
    if (wrap) wrap.innerHTML = '<div class="spinner sm" style="margin:20px auto;display:block;"></div>';
    try {
      const r = await API.get('/despachos', this._cargueQueryString());
      const items = r.data || r || [];
      const stChip = s => {
        const m = {
          Preparando: 'status-creada',
          Certificado: 'status-en-proceso',
          Despachado: 'status-cerrada',
          Entregado: 'status-completada',
          Cancelado: 'status-cancelada',
        };
        return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
      };
      const certChip = d => {
        const r = d.estado_certificacion_resumen || 'Sin pedidos';
        const cls = r === 'Certificado' ? 'status-completada' : (r === 'Sin pedidos' ? 'status-creada' : 'status-en-proceso');
        return `<span class="status-chip ${cls}">${WMS.esc(r)}</span>`;
      };
      const countEl = document.getElementById('cargue-count');
      if (countEl) countEl.innerHTML = `<i class="fa-solid fa-truck-loading"></i> Planillas de Cargue (${items.length})`;
      if (wrap) wrap.innerHTML = `
            <table class="erp-table" id="cargue-table">
              <thead><tr><th>N° Despacho</th><th>Placa</th><th>Conductor</th><th>Ruta</th><th>Estado</th><th>Certificación</th><th>Fecha</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(d => `<tr>
                <td><span class="badge badge-info">${WMS.esc(d.numero_despacho||('#'+d.id))}</span></td>
                <td><strong>${WMS.esc(d.placa||'-')}</strong></td>
                <td>${WMS.esc(d.conductor||'-')}</td>
                <td>${WMS.esc(d.ruta||'-')}</td>
                <td>${stChip(d.estado||'Preparando')}</td>
                <td>${certChip(d)}</td>
                <td>${WMS.formatDate(d.fecha_movimiento)||'-'}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" title="Ver detalle" onclick="WMS_MODULES.despacho.verCargue(${d.id})"><i class="fa-solid fa-eye"></i></button>
                  ${d.estado !== 'Entregado' ? `<button class="btn btn-sm btn-info" title="Agregar pedidos" onclick="WMS_MODULES.despacho.agregarPedidosCargue(${d.id})"><i class="fa-solid fa-box-open"></i> Pedidos</button>` : ''}
                  ${d.estado === 'Preparando' || d.estado === 'Certificado' ? `<button class="btn btn-sm btn-success" title="Despachar" onclick="WMS_MODULES.despacho.despacharCargue(${d.id})"><i class="fa-solid fa-truck"></i> Despachar</button>` : ''}
                  ${d.estado === 'Despachado' ? `<button class="btn btn-sm btn-warning" title="Liquidar - marcar como Entregado" onclick="WMS_MODULES.despacho.liquidarCargue(${d.id})"><i class="fa-solid fa-clipboard-check"></i> Liquidar</button>` : ''}
                </div></td>
              </tr>`).join('') || '<tr><td colspan="8" class="table-empty">Sin planillas de cargue en el rango/filtro seleccionado</td></tr>'}
              </tbody>
            </table>`;
    } catch(e) {
      if (wrap) wrap.innerHTML = '<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>';
    }
  },

  exportCargueExcel() {
    const token = localStorage.getItem('wms_token') || '';
    const qs = this._cargueQueryString();
    window.open(`${API_BASE}/despachos?export=excel${qs ? '&' + qs : ''}&token=${encodeURIComponent(token)}`, '_blank');
  },

  async nuevoPlanillaCargue() {
    // Carga rutas para el selector
    let rutasOpts = '<option value="">Sin ruta específica</option>';
    try {
      const rr = await API.get('/param/rutas');
      const rutas = rr.data || rr || [];
      rutasOpts += rutas.map(rt => `<option value="${rt.id}">${WMS.esc(rt.nombre)}</option>`).join('');
    } catch(e) { /* no rutas disponibles */ }

    WMS.showRightPanel('Nueva Planilla de Cargue', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">Placa del Vehículo <span class="required">*</span></label><input id="car-placa" class="form-control" placeholder="ABC-123"></div>
        <div class="form-group"><label class="form-label">Conductor <span class="required">*</span></label><input id="car-conductor" class="form-control" placeholder="Nombre del conductor"></div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">Ruta <span class="required">*</span></label>
          <select id="car-ruta-id" class="form-control">${rutasOpts}</select>
        </div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Observaciones</label><textarea id="car-obs" class="form-control" rows="2" placeholder="Notas adicionales"></textarea></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.saveCargue()"><i class="fa-solid fa-save"></i> Crear Cargue</button>`);
  },

  async nuevoPlanillaCargueMasivo() {
    const ids = Array.from(document.querySelectorAll('.cp-chk:checked')).map(c => parseInt(c.value));
    if (!ids.length) { WMS.toast('warning', 'Selecciona al menos un pedido para crear la planilla'); return; }
    
    this._cargueSelectedIds = ids;
    
    let rutasOpts = '<option value="">Sin ruta específica</option>';
    try {
      const rr = await API.get('/param/rutas');
      const rutas = rr.data || rr || [];
      rutasOpts += rutas.map(rt => `<option value="${rt.id}">${WMS.esc(rt.nombre)}</option>`).join('');
    } catch(e) { }

    WMS.showRightPanel(`Nueva Planilla (${ids.length} pedidos)`, `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">Placa del Vehículo <span class="required">*</span></label><input id="car-placa" class="form-control" placeholder="ABC-123"></div>
        <div class="form-group"><label class="form-label">Conductor <span class="required">*</span></label><input id="car-conductor" class="form-control" placeholder="Nombre del conductor"></div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">Ruta <span class="required">*</span></label>
          <select id="car-ruta-id" class="form-control">${rutasOpts}</select>
        </div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Observaciones</label><textarea id="car-obs" class="form-control" rows="2" placeholder="Notas adicionales"></textarea></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.saveCargueMasivo()"><i class="fa-solid fa-save"></i> Crear Cargue y Asociar</button>`);
  },

  async saveCargueMasivo() {
    const placa     = document.getElementById('car-placa')?.value.trim();
    const conductor = document.getElementById('car-conductor')?.value.trim();
    const rutaId    = document.getElementById('car-ruta-id')?.value || null;
    const ordenIds  = this._cargueSelectedIds || [];
    
    if (!placa || !conductor) { WMS.toast('warning', 'Placa y Conductor son requeridos'); return; }
    
    try {
      WMS.spinner();
      const r = await API.post('/despachos', {
        placa, conductor,
        ruta_id: rutaId ? parseInt(rutaId) : null,
        observaciones: document.getElementById('car-obs')?.value.trim() || null,
      });
      if (r.error) { WMS.toast('error', r.message); WMS.closeSpinner(); return; }
      
      const despachoId = r.data.id;
      
      if (ordenIds.length > 0) {
        const r2 = await API.post(`/despachos/${despachoId}/pedidos`, { orden_ids: ordenIds });
        if (r2.error) {
           WMS.toast('error', 'Cargue creado pero hubo un error asociando pedidos: ' + r2.message);
        } else {
           WMS.toast('success', `Planilla creada y ${ordenIds.length} pedidos asociados`);
        }
      } else {
        WMS.toast('success', 'Planilla de cargue creada');
      }
      WMS.closeRightPanel(); 
      WMS.closeSpinner();
      this.show_cargue(); 
    } catch(e) { 
      WMS.toast('error', 'Error guardando'); 
      WMS.closeSpinner();
    }
  },

  async saveCargue() {
    const placa     = document.getElementById('car-placa')?.value.trim();
    const conductor = document.getElementById('car-conductor')?.value.trim();
    const rutaId    = document.getElementById('car-ruta-id')?.value || null;
    if (!placa || !conductor) { WMS.toast('warning', 'Placa y Conductor son requeridos'); return; }
    try {
      const r = await API.post('/despachos', {
        placa, conductor,
        ruta_id: rutaId ? parseInt(rutaId) : null,
        observaciones: document.getElementById('car-obs')?.value.trim() || null,
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Planilla de cargue creada'); WMS.closeRightPanel(); this._renderPlanillasCreadas(); }
    } catch(e) { WMS.toast('error', 'Error guardando'); }
  },

  async verCargue(id) {
    try {
      const r = await API.get('/despachos/' + id);
      const d = r.data || r;
      const ordenes = d.ordenes || [];
      const stOrd = s => {
        if (!s) return '-';
        const col = { Despachado: '#3b82f6', Entregado: '#059669' };
        return `<span style="color:${col[s]||'#64748b'};font-weight:700;">${WMS.esc(s)}</span>`;
      };
      const esEditable = d.estado !== 'Entregado';
      WMS.showRightPanel(`Cargue: ${WMS.esc(d.numero_despacho||'#'+id)}`, `
        <div class="form-grid form-grid-2" style="margin-bottom:16px;">
          <div><label class="form-label">Placa</label><p><b>${WMS.esc(d.placa||'-')}</b></p></div>
          <div><label class="form-label">Conductor</label><p>${WMS.esc(d.conductor||'-')}</p></div>
          <div><label class="form-label">Ruta</label><p>${WMS.esc(d.ruta_obj?.nombre||d.ruta||'-')}</p></div>
          <div><label class="form-label">Estado</label><p><b>${WMS.esc(d.estado||'')}</b></p></div>
          ${d.observaciones ? `<div style="grid-column:1/-1;"><label class="form-label">Observaciones</label><p>${WMS.esc(d.observaciones)}</p></div>` : ''}
        </div>
        <b style="display:block;margin-bottom:8px;">Pedidos asociados (${ordenes.length})</b>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Planilla</th><th>Cliente / Sucursal</th><th>Estado despacho</th>${esEditable ? '<th></th>' : ''}</tr></thead>
            <tbody>${ordenes.map(o => `<tr>
              <td><span class="badge badge-info">${WMS.esc(o.planilla_numero||'#'+o.id)}</span></td>
              <td>${WMS.esc(o.cliente||o.sucursal_entrega||'-')}</td>
              <td>${stOrd(o.estado_despacho)}</td>
              ${esEditable ? `<td><button class="btn btn-sm btn-danger" title="Quitar pedido" onclick="WMS_MODULES.despacho.quitarPedidoCargue(${id},${o.id})"><i class="fa-solid fa-xmark"></i></button></td>` : ''}
            </tr>`).join('') || `<tr><td colspan="${esEditable?4:3}" class="table-empty">Sin pedidos asociados</td></tr>`}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cerrar</button>
         ${esEditable ? `<button class="btn btn-info" onclick="WMS.closeRightPanel();WMS_MODULES.despacho.agregarPedidosCargue(${id})"><i class="fa-solid fa-box-open"></i> Agregar Pedidos</button>` : ''}
         ${d.estado==='Despachado' ? `<button class="btn btn-warning" onclick="WMS.closeRightPanel();WMS_MODULES.despacho.liquidarCargue(${id})"><i class="fa-solid fa-clipboard-check"></i> Liquidar</button>` : ''}
         ${d.estado==='Preparando'||d.estado==='Certificado' ? `<button class="btn btn-success" onclick="WMS.closeRightPanel();WMS_MODULES.despacho.despacharCargue(${id})"><i class="fa-solid fa-truck"></i> Despachar</button>` : ''}`);
    } catch(e) { WMS.toast('error', 'Error cargando detalle'); }
  },

  async agregarPedidosCargue(despachoId) {
    WMS.spinner();
    try {
      // Carga ordenes certificadas y no despachadas aún
      const [rDesp, rOrd] = await Promise.all([
        API.get('/despachos/' + despachoId),
        API.get('/picking', 'estado_certificacion=Certificada&sin_despacho=1&limit=200&incluir_finalizados=1'),
      ]);
      const despacho  = rDesp.data || rDesp;
      const yaAsoc    = new Set((despacho.ordenes || []).map(o => o.id));
      const ordenes   = (rOrd.data || rOrd || []).filter(o =>
        !yaAsoc.has(o.id) && (!o.estado_despacho || o.estado_despacho === null)
      );

      WMS.setContent(`
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-box-open"></i> Agregar Pedidos a Cargue: ${WMS.esc(despacho.numero_despacho)}</span>
          </div>
          <div class="card-body">
            <p style="color:#64748b;margin-bottom:12px;">Selecciona los pedidos certificados para asociar a esta planilla. El estado de los pedidos quedará como <b>Despachado</b>.</p>
            <div class="filter-bar" style="margin-bottom:12px;">
              <div class="search-bar"><i class="fa-solid fa-search"></i>
                <input placeholder="Filtrar pedidos..." oninput="WMS_MODULES.despacho.filterTable(this.value,'add-ord-table')">
              </div>
            </div>
            <div class="table-container">
              <table class="erp-table" id="add-ord-table">
                <thead><tr><th><input type="checkbox" id="chk-all-ord" onchange="document.querySelectorAll('.chk-ord').forEach(c=>c.checked=this.checked)"></th>
                  <th>Planilla</th><th>Cliente / Sucursal</th><th>Fecha</th><th>Estado cert.</th></tr></thead>
                <tbody>${ordenes.map(o => `<tr>
                  <td><input type="checkbox" class="chk-ord" value="${o.id}"></td>
                  <td><span class="badge badge-info">${WMS.esc(o.planilla_numero||'#'+o.id)}</span></td>
                  <td>${WMS.esc(o.cliente||o.sucursal_entrega||'-')}</td>
                  <td>${WMS.formatDate(o.fecha_movimiento)||'-'}</td>
                  <td>${WMS.esc(o.estado_certificacion||'-')}</td>
                </tr>`).join('') || '<tr><td colspan="5" class="table-empty">No hay pedidos disponibles para agregar</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end;">
          <button class="btn btn-secondary" onclick="WMS_MODULES.despacho.show_cargue()">Cancelar</button>
          <button class="btn btn-primary" onclick="WMS_MODULES.despacho._confirmarAgregarPedidos(${despachoId})">
            <i class="fa-solid fa-save"></i> Asociar Seleccionados
          </button>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error cargando pedidos'); this.show_cargue(); }
  },

  async _confirmarAgregarPedidos(despachoId) {
    const ids = Array.from(document.querySelectorAll('.chk-ord:checked')).map(c => parseInt(c.value));
    if (!ids.length) { WMS.toast('warning', 'Selecciona al menos un pedido'); return; }
    try {
      const r = await API.post(`/despachos/${despachoId}/pedidos`, { orden_ids: ids });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', `${ids.length} pedido(s) asociados — estado: Despachado`);
      this.verCargue(despachoId);
    } catch(e) { WMS.toast('error', 'Error al asociar pedidos'); }
  },

  async quitarPedidoCargue(despachoId, ordenId) {
    const ok = await Swal.fire({
      title: 'Quitar pedido',
      text: '¿Quitar este pedido del cargue? Su estado de despacho se revertirá.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, quitar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#dc2626',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.delete(`/despachos/${despachoId}/pedidos/${ordenId}`);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Pedido removido del cargue');
      this.verCargue(despachoId);
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async despacharCargue(id) {
    const ok = await Swal.fire({
      title: 'Confirmar despacho',
      text: 'El vehículo saldrá. Los pedidos quedarán con estado Despachado.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Sí, despachar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#059669',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post('/despachos/' + id + '/cerrar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Despacho confirmado'); this.show_cargue(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async liquidarCargue(id) {
    const ok = await Swal.fire({
      title: 'Liquidar planilla de cargue',
      text: 'Confirma que los pedidos fueron entregados. Esta acción cambia el estado a Entregado y no se puede revertir.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, liquidar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#d97706',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post('/despachos/' + id + '/liquidar', {});
      if (r.error) { await Swal.fire('Error', r.message, 'error'); return; }
      WMS.toast('success', 'Planilla liquidada — pedidos marcados como Entregados');
      this.show_cargue();
    } catch(e) { WMS.toast('error', 'Error al liquidar'); }
  },

  generarCargue(planillaId) {
    this.nuevoPlanillaCargue();
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
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_tms()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>
      <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.despacho.guiaTms()">
        <i class="fa-solid fa-book"></i> Guía de Conexión
      </button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.gestionarApiKeys()">
        <i class="fa-solid fa-key"></i> API Keys
      </button>`);
    WMS.spinner();
    try {
      const [stockR, despR] = await Promise.all([
        API.get('/tms/stock?per_page=1'),
        API.get('/tms/despachos'),
      ]);
      const stk  = stockR.meta?.total ?? (stockR.data?.length ?? 0);
      const desp = Array.isArray(despR.data) ? despR.data : [];

      WMS.setContent(`
        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:18px;"><i class="fa-solid fa-satellite-dish"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;">Estado API</div>
              <div style="font-size:16px;font-weight:800;color:#166534;">Endpoints activos</div>
            </div>
          </div>
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#dbeafe;display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-size:18px;"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.5px;">Ítems en stock</div>
              <div style="font-size:22px;font-weight:900;color:#1e3a5f;">${WMS.formatNum(stk)}</div>
            </div>
          </div>
          <div style="background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#fef08a;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:18px;"><i class="fa-solid fa-truck-fast"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.5px;">Despachos hoy</div>
              <div style="font-size:22px;font-weight:900;color:#1e3a5f;">${WMS.formatNum(desp.length)}</div>
            </div>
          </div>
        </div>

        <!-- Despachos -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
          <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:800;color:#1e3a5f;font-size:13px;">
            <i class="fa-solid fa-truck-fast" style="color:#d97706;margin-right:6px;"></i>Despachos del día
          </div>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead><tr style="background:#f8fafc;">
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">N° Despacho</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Cliente</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Operador</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Estado</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Tracking</th>
                <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Acción</th>
              </tr></thead>
              <tbody>
                ${desp.length ? desp.slice(0,30).map(d => {
                  const enTransito = d.tms_estado === 'EnTransito' || d.estado === 'En Tránsito';
                  const entregado  = d.tms_estado === 'Entregado'  || d.estado === 'Entregado';
                  const badge = entregado
                    ? 'background:#dcfce7;color:#166534'
                    : enTransito
                      ? 'background:#dbeafe;color:#1e40af'
                      : 'background:#fef9c3;color:#854d0e';
                  const label = entregado ? 'Entregado' : enTransito ? 'En Tránsito' : (d.estado||'Pendiente');
                  return `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px 12px;font-weight:700;color:#0F4C81;">${WMS.esc(d.numero_despacho||'-')}</td>
                    <td style="padding:8px 12px;">${WMS.esc(d.cliente_nombre||d.cliente||'-')}</td>
                    <td style="padding:8px 12px;">${WMS.esc(d.operador||'-')}</td>
                    <td style="padding:8px 12px;"><span style="${badge};padding:2px 8px;border-radius:3px;font-size:.72rem;font-weight:600;">${WMS.esc(label)}</span></td>
                    <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#64748b;">${WMS.esc(d.tms_tracking_code||'—')}</td>
                    <td style="padding:8px 12px;text-align:center;">
                      ${d.estado === 'Cerrado' && !enTransito && !entregado
                        ? `<button class="btn btn-sm btn-primary" style="font-size:.7rem;" onclick="WMS_MODULES.despacho.marcarEnTransito(${d.id})">
                             <i class="fa-solid fa-truck-moving"></i> En Tránsito
                           </button>`
                        : `<span style="color:#94a3b8;font-size:11px;">—</span>`}
                    </td>
                  </tr>`;
                }).join('') : '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">Sin despachos registrados hoy</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-plug-circle-xmark"></i><p>Error conectando con el módulo TMS</p></div>');
    }
  },

  async marcarEnTransito(id) {
    WMS.showModal('Marcar En Tránsito', `
      <div class="form-group">
        <label class="form-label">Transportista</label>
        <input id="tms-trans" class="form-control" placeholder="Nombre del transportista o empresa">
      </div>
      <div class="form-group">
        <label class="form-label">Código de Tracking</label>
        <input id="tms-track" class="form-control" placeholder="Ej: TRACK-2026-001">
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho._confirmarEnTransito(${id})">
         <i class="fa-solid fa-truck-moving"></i> Confirmar
       </button>`);
  },

  async _confirmarEnTransito(id) {
    const transportista = document.getElementById('tms-trans')?.value.trim();
    const tracking      = document.getElementById('tms-track')?.value.trim();
    try {
      const r = await API.post('/tms/despacho/' + id + '/transportar', { transportista, tracking_code: tracking });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.closeModal('generic-modal');
      WMS.toast('success', 'Despacho marcado como En Tránsito');
      this.show_tms();
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', 'Error al sincronizar con TMS');
    }
  },

  async gestionarApiKeys() {
    try {
      const r    = await API.get('/tms/keys');
      const keys = Array.isArray(r.data) ? r.data : [];
      WMS.showModal(
        '<i class="fa-solid fa-key" style="margin-right:6px;color:#1d4ed8;"></i>API Keys TMS',
        `<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
           <p style="margin:0;font-size:12px;color:#64748b;">Cada key autoriza al TMS externo a consultar los endpoints del WMS.</p>
           <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.crearApiKey()">
             <i class="fa-solid fa-plus"></i> Nueva Key
           </button>
         </div>
         <div style="overflow-x:auto;">
           <table style="width:100%;border-collapse:collapse;font-size:12px;">
             <thead><tr style="background:#f8fafc;">
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Nombre</th>
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Key (hash parcial)</th>
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Último uso</th>
               <th style="padding:7px 10px;text-align:center;color:#64748b;font-weight:700;width:80px;"></th>
             </tr></thead>
             <tbody id="tms-keys-tbody">
               ${keys.length ? keys.map(k => `
                 <tr style="border-bottom:1px solid #f1f5f9;" id="tms-key-row-${k.id}">
                   <td style="padding:8px 10px;font-weight:600;">${WMS.esc(k.nombre||'-')}</td>
                   <td style="padding:8px 10px;font-family:monospace;font-size:11px;color:#64748b;">${(k.key_hash||'').substring(0,12)}…</td>
                   <td style="padding:8px 10px;color:#64748b;">${k.ultimo_uso ? WMS.formatDate(k.ultimo_uso) : '<span style="color:#94a3b8">Nunca</span>'}</td>
                   <td style="padding:8px 10px;text-align:center;">
                     <button class="btn btn-xs" style="background:#fee2e2;color:#991b1b;border:none;border-radius:3px;padding:3px 8px;cursor:pointer;"
                             onclick="WMS_MODULES.despacho.revocarKey(${k.id})">
                       <i class="fa-solid fa-ban"></i> Revocar
                     </button>
                   </td>
                 </tr>`).join('')
               : '<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">Sin API Keys activas</td></tr>'}
             </tbody>
           </table>
         </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', 'Error cargando API Keys');
    }
  },

  async crearApiKey() {
    WMS.showModal(
      '<i class="fa-solid fa-plus" style="margin-right:6px;color:#16a34a;"></i>Nueva API Key TMS',
      `<p style="font-size:12px;color:#64748b;margin-bottom:14px;">
         La clave se mostrará <strong>una sola vez</strong>. Cópiala al servidor TMS antes de cerrar este cuadro.
       </p>
       <div class="form-group">
         <label class="form-label">Nombre identificador <span style="color:#dc2626;">*</span></label>
         <input id="tms-key-nombre" class="form-control" placeholder="Ej: TMS-Hostinger-Prod" autofocus>
       </div>
       <div id="tms-key-result" style="display:none;margin-top:14px;">
         <label class="form-label" style="color:#16a34a;font-weight:700;">
           <i class="fa-solid fa-circle-check"></i> Key generada — cópiala ahora
         </label>
         <div style="display:flex;gap:8px;align-items:center;">
           <input id="tms-key-value" class="form-control" readonly
                  style="font-family:monospace;font-size:12px;background:#f0fdf4;border-color:#86efac;">
           <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho._copiarKey()"
                   style="white-space:nowrap;flex-shrink:0;">
             <i class="fa-solid fa-copy"></i> Copiar
           </button>
         </div>
         <p style="font-size:11px;color:#dc2626;margin-top:6px;">
           <i class="fa-solid fa-triangle-exclamation"></i> No se almacena en texto plano. Si la pierdes deberás crear una nueva.
         </p>
       </div>`,
      `<button id="tms-btn-crear" class="btn btn-primary" onclick="WMS_MODULES.despacho._submitCrearKey()">
         <i class="fa-solid fa-key"></i> Generar Key
       </button>
       <button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal');WMS_MODULES.despacho.gestionarApiKeys()">
         Cerrar
       </button>`);
  },

  async _submitCrearKey() {
    const nombre = document.getElementById('tms-key-nombre')?.value.trim();
    if (!nombre) { WMS.toast('warning', 'Ingresa un nombre para la key'); return; }
    const btn = document.getElementById('tms-btn-crear');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generando...'; }
    try {
      const r = await API.post('/tms/keys', { nombre, permisos: ['read', 'write'] });
      const plainKey = r.data?.api_key || r.api_key || '';
      document.getElementById('tms-key-value').value = plainKey;
      document.getElementById('tms-key-result').style.display = 'block';
      document.getElementById('tms-key-nombre').disabled = true;
      if (btn) { btn.style.display = 'none'; }
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', e.message || 'Error generando API Key');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-key"></i> Generar Key'; }
    }
  },

  _copiarKey() {
    const val = document.getElementById('tms-key-value')?.value;
    if (!val) return;
    navigator.clipboard?.writeText(val).then(() => WMS.toast('success', 'Key copiada al portapapeles'))
      .catch(() => { document.getElementById('tms-key-value').select(); document.execCommand('copy'); WMS.toast('success', 'Key copiada'); });
  },

  async revocarKey(id) {
    WMS.confirm('Revocar API Key', '¿Revocar esta API Key? El TMS perderá acceso inmediatamente.', async () => {
      try {
        await API.delete('/tms/keys/' + id);
        WMS.toast('success', 'API Key revocada');
        WMS.closeModal('generic-modal');
        this.gestionarApiKeys();
      } catch(e) {
        if (e.isSessionExpired) return;
        WMS.toast('error', 'Error revocando API Key');
      }
    });
  },

  guiaTms() {
    const base = (window.location.origin + '/WMS_FENIX/public/api/tms').replace(/([^:])\/\//g, '$1/');
    const endpoints = [
      { method:'GET',  path:'/stock',                   desc:'Inventario disponible (paginado)',         params:'?page=1&per_page=100&codigo=ABC' },
      { method:'GET',  path:'/ordenes',                 desc:'Órdenes de picking activas',               params:'?estado=EnProceso' },
      { method:'GET',  path:'/despachos',               desc:'Despachos del período',                    params:'?fecha_inicio=2026-05-01&fecha_fin=2026-05-31' },
      { method:'POST', path:'/despacho/{id}/transportar',desc:'Marcar despacho en tránsito',             params:'Body: {"tracking_code":"T001","transportista":"TransCo"}' },
      { method:'POST', path:'/webhook',                  desc:'Receptor de eventos del TMS',             params:'Body: {"evento":"ENTREGA_CONFIRMADA","payload":{...}}' },
    ];
    const methodColor = { GET:'#16a34a', POST:'#1d4ed8', DELETE:'#dc2626' };
    WMS.showModal(
      '<i class="fa-solid fa-book" style="margin-right:6px;color:#7c3aed;"></i>Guía de Conexión — TMS',
      `<!-- URL base -->
       <div style="margin-bottom:18px;">
         <label class="form-label" style="color:#7c3aed;font-weight:700;">URL Base del WMS</label>
         <div style="display:flex;gap:8px;align-items:center;">
           <input id="tms-base-url" class="form-control" readonly value="${base}"
                  style="font-family:monospace;font-size:12px;background:#f5f3ff;border-color:#c4b5fd;">
           <button class="btn btn-sm btn-secondary" onclick="navigator.clipboard?.writeText(document.getElementById('tms-base-url').value).then(()=>WMS.toast('success','URL copiada'))" style="white-space:nowrap;flex-shrink:0;">
             <i class="fa-solid fa-copy"></i> Copiar
           </button>
         </div>
       </div>

       <!-- Auth -->
       <div style="background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:12px 14px;margin-bottom:18px;font-size:12px;">
         <div style="font-weight:700;color:#78350f;margin-bottom:6px;"><i class="fa-solid fa-shield-halved"></i> Autenticación</div>
         <p style="margin:0 0 6px;color:#713f12;">Incluye el header en cada request:</p>
         <code style="display:block;background:#fef08a;padding:6px 10px;border-radius:3px;font-size:11px;">X-API-Key: wms_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code>
         <p style="margin:6px 0 0;color:#713f12;font-size:11px;">Genera la key en el botón <strong>API Keys</strong> de este panel.</p>
       </div>

       <!-- Endpoints -->
       <div style="font-weight:700;color:#1e3a5f;font-size:13px;margin-bottom:10px;">Endpoints disponibles</div>
       <div style="display:flex;flex-direction:column;gap:8px;">
         ${endpoints.map(ep => `
           <div style="border:1px solid #e2e8f0;border-radius:4px;padding:10px 12px;background:#f8fafc;">
             <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
               <span style="background:${methodColor[ep.method]||'#64748b'};color:#fff;padding:1px 7px;border-radius:3px;font-size:10px;font-weight:800;font-family:monospace;">${ep.method}</span>
               <code style="font-size:12px;color:#1e293b;font-weight:600;">${ep.path}</code>
             </div>
             <div style="font-size:11px;color:#64748b;margin-bottom:3px;">${ep.desc}</div>
             <code style="font-size:10px;color:#94a3b8;word-break:break-all;">${ep.params}</code>
           </div>`).join('')}
       </div>

       <!-- Ejemplo cURL -->
       <div style="margin-top:18px;">
         <div style="font-weight:700;color:#1e3a5f;font-size:12px;margin-bottom:6px;">Ejemplo PHP/cURL (servidor TMS)</div>
         <pre style="background:#1e293b;color:#e2e8f0;padding:12px;border-radius:4px;font-size:11px;overflow-x:auto;line-height:1.6;">\$ch = curl_init('${base}/stock');\ncurl_setopt_array(\$ch, [\n  CURLOPT_RETURNTRANSFER =&gt; true,\n  CURLOPT_HTTPHEADER =&gt; ['X-API-Key: wms_TU_KEY_AQUI']\n]);\n\$json = json_decode(curl_exec(\$ch));\n// \$json-&gt;ok === true\n// \$json-&gt;data = [...items de inventario]</pre>
       </div>

       <!-- Conexión Hostinger → Local -->
       <div style="margin-top:18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:4px;padding:14px;">
         <div style="font-weight:700;color:#c2410c;margin-bottom:8px;font-size:12px;">
           <i class="fa-solid fa-cloud-arrow-up"></i> Conectar servidor Hostinger → WMS local (XAMPP)
         </div>
         <p style="font-size:12px;color:#7c2d12;margin:0 0 10px;">
           Hostinger está en internet público; tu XAMPP está en red privada. El servidor TMS no puede acceder a <code>localhost</code> directamente.
           Usa <strong>ngrok</strong> para crear un túnel seguro.
         </p>
         <div style="font-weight:600;color:#9a3412;font-size:12px;margin-bottom:6px;">Pasos con ngrok (gratis):</div>
         <ol style="font-size:12px;color:#7c2d12;margin:0 0 10px;padding-left:18px;line-height:1.9;">
           <li>Descarga ngrok: <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">ngrok.com/download</code></li>
           <li>Inicia el túnel en tu máquina: <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">ngrok http 80</code></li>
           <li>Ngrok genera una URL pública como <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">https://abc123.ngrok-free.app</code></li>
           <li>Desde Hostinger usa esa URL como base:
             <code style="display:block;background:#fef9c3;padding:4px 8px;border-radius:3px;margin-top:4px;word-break:break-all;">https://abc123.ngrok-free.app/WMS_FENIX/public/api/tms/stock</code>
           </li>
         </ol>
         <div style="font-size:11px;color:#9a3412;background:#fef3c7;padding:8px;border-radius:3px;">
           <i class="fa-solid fa-circle-info"></i>
           <strong>Para producción</strong>: el WMS debe estar en un servidor público (Hostinger, VPS, etc.) con dominio propio.
           ngrok es solo para desarrollo y pruebas — la URL cambia en cada reinicio (plan gratuito).
         </div>
       </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
  },

  // ── PACKING SCREEN ─────────────────────────────────────────────────────────
  _packingState: { sesionId: null, sesionData: null, unitsWithItems: {} },

  async show_packing(sesionId) {
    WMS.spinner();
    this._packingState = { sesionId: null, sesionData: null, unitsWithItems: {} };
    try {
      const r = await API.get('/packing/sesion/' + sesionId);
      if (r.error) { WMS.toast('error', r.message); return; }
      this._packingState.sesionId  = sesionId;
      this._packingState.sesionData = r.data;
      // Seed all units' items from API response
      (r.data.unidades || []).forEach(u => {
        this._packingState.unitsWithItems[u.id] = u.items || [];
      });
      this._renderPackingScreen(r.data);
    } catch(e) { WMS.toast('error', 'Error al cargar sesión de packing'); }
  },

  _renderPackingScreen(data) {
    const { sesion, totales, productos, unidades, unidad_abierta } = data;
    const tipo      = sesion.tipo_empaque;
    const tipoUp    = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const unitAb    = unidades.find(u => u.id === unidad_abierta);
    const consec    = unitAb ? String(unitAb.consecutivo).padStart(3,'0') : '---';
    const pendiente = totales.pendiente;
    const btnFin    = pendiente > 0 ? 'disabled' : '';

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>`);

    WMS.setContent(`
      <div id="packing-wrap">
        <!-- TOP BAR -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
          <div style="flex:1;display:flex;gap:16px;font-size:13px;align-items:center;flex-wrap:wrap;">
            <span>Pendiente: <strong id="pk-stat-pend" style="color:${pendiente>0?'#dc2626':'#16a34a'};">${WMS.formatNum(pendiente)}</strong></span>
            <span>Empacado: <strong id="pk-stat-emp">${WMS.formatNum(totales.total_empacado)}</strong></span>
            <span>Total pick: <strong>${WMS.formatNum(totales.total_pickeado)}</strong></span>
            ${totales.num_unidades > 0 ? `
            <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho._showCanastasDetalle(${sesion.id})" style="font-size:11px;">
              <i class="fa-solid fa-boxes-stacked"></i> Ver ${totales.num_unidades} unidad${totales.num_unidades!==1?'es':''} cerrada${totales.num_unidades!==1?'s':''}
            </button>` : ''}
          </div>
          <button id="pk-btn-finalizar" class="btn btn-success btn-sm" ${btnFin}
            onclick="WMS_MODULES.despacho.finalizarPacking(${sesion.id})">
            <i class="fa-solid fa-flag-checkered"></i> Finalizar Certificación
          </button>
        </div>

        <!-- RESUMEN POR AMBIENTE -->
        ${(() => {
          const ambs = {};
          productos.forEach(p => {
            const nombre = p.ambiente_nombre || 'Sin ambiente';
            const color  = p.ambiente_color  || '#64748b';
            if (!ambs[nombre]) ambs[nombre] = { nombre, color, pickeado: 0, empacado: 0 };
            ambs[nombre].pickeado += Number(p.total_pickeado) || 0;
            ambs[nombre].empacado += Number(p.total_empacado) || 0;
          });
          const cards = Object.values(ambs).map(amb => {
            const pct = amb.pickeado > 0 ? Math.min(100, Math.round((amb.empacado / amb.pickeado) * 100)) : 0;
            return `<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;min-width:160px;">
              <div style="font-size:11px;font-weight:700;color:${amb.color};margin-bottom:4px;">${WMS.esc(amb.nombre)}</div>
              <div style="font-size:12px;">Pick: ${WMS.formatNum(amb.pickeado)} | Pack: ${WMS.formatNum(amb.empacado)}</div>
              <div style="height:4px;background:#e2e8f0;border-radius:2px;margin-top:6px;">
                <div style="height:100%;width:${pct}%;background:${amb.color};border-radius:2px;"></div>
              </div>
            </div>`;
          }).join('');
          return cards ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">${cards}</div>` : '';
        })()}

        <!-- TWO PANELS -->
        <div style="display:grid;grid-template-columns:1fr 400px;gap:14px;align-items:start;">
          <!-- LEFT: productos pendientes -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
              <span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Productos Pendientes</span>
              <span style="font-size:12px;color:#64748b;">${tipoUp} actual: <strong>#${consec}</strong></span>
            </div>
            <div style="padding:8px 10px;border-bottom:1px solid #e2e8f0;">
              <div class="search-bar" style="margin:0;">
                <i class="fa-solid fa-search"></i>
                <input id="pk-search" placeholder="Buscar producto o código..."
                  oninput="WMS_MODULES.despacho._pkFiltrar(this.value)">
              </div>
            </div>
            <div id="pk-left-content" style="padding:0 0 8px;">
              ${this._buildProductosList(productos, sesion.id)}
            </div>
          </div>

          <!-- RIGHT: unidad actual -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;" id="pk-right-header">
              <span class="card-title"><i class="fa-solid fa-box"></i> ${tipoUp} #${consec}</span>
              <span class="status-chip status-creada">Abierta</span>
            </div>
            <div id="pk-right-content" style="padding:0 8px;">
              ${this._buildItemsTable(unitAb?.items || [])}
            </div>
            <div style="padding:10px 12px;border-top:1px solid #e2e8f0;">
              ${(unitAb?.items?.length || 0) > 0
                ? `<button class="btn btn-warning btn-sm" style="width:100%;"
                    onclick="WMS_MODULES.despacho.cerrarUnidadPacking(${unidad_abierta})">
                    <i class="fa-solid fa-box-archive"></i> Cerrar unidad e imprimir sticker
                   </button>`
                : `<button class="btn btn-secondary btn-sm" style="width:100%;opacity:.5;cursor:not-allowed;" disabled>
                    <i class="fa-solid fa-box-archive"></i> Agrega ítems antes de cerrar
                   </button>`}
            </div>
          </div>
        </div>

      </div>`);
  },

  _buildProductosList(productos, sesionId) {
    if (!productos.length) return '<p class="table-empty">Sin productos</p>';

    const pendientes = productos.filter(p => p.pendiente > 0);
    const completos  = productos.filter(p => p.pendiente <= 0);

    // Agrupar pendientes por ambiente
    const grupos = {};
    pendientes.forEach(p => {
      const key = p.ambiente_nombre || 'Sin ambiente';
      if (!grupos[key]) grupos[key] = { color: p.ambiente_color || '#64748b', items: [] };
      grupos[key].items.push(p);
    });

    let html = '';

    if (pendientes.length === 0) {
      html += '<div style="padding:12px;text-align:center;color:#16a34a;font-weight:700;font-size:13px;"><i class="fa-solid fa-check-double"></i> Todos los productos empacados</div>';
    }

    for (const [ambNombre, grupo] of Object.entries(grupos)) {
      html += `
        <div class="pk-amb-header" style="padding:5px 10px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;border-top:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;">
          <span style="width:10px;height:10px;border-radius:50%;background:${grupo.color};display:inline-block;flex-shrink:0;"></span>
          <span style="font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.5px;">${WMS.esc(ambNombre)}</span>
          <span style="font-size:10px;color:#94a3b8;margin-left:auto;">${grupo.items.length} ref(s)</span>
        </div>`;
      grupo.items.forEach(p => {
        html += `
          <div class="pk-prod-row" id="pk-prod-${p.producto_id}"
            data-nombre="${WMS.esc((p.nombre||'').toLowerCase())}" data-codigo="${WMS.esc((p.codigo||'').toLowerCase())}"
            data-amb="${WMS.esc(ambNombre)}"
            style="padding:8px 10px 8px 18px;border-bottom:1px solid #f1f5f9;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(p.nombre)}</div>
                <div style="font-size:11px;color:#64748b;">${WMS.esc(p.codigo||'-')}</div>
              </div>
              <div style="text-align:right;font-size:12px;flex-shrink:0;margin-left:8px;">
                <div>Pick: <strong>${WMS.formatNum(p.total_pickeado)}</strong></div>
                <div style="color:#64748b;">Emp: ${WMS.formatNum(p.total_empacado)}</div>
                <div style="color:#dc2626;font-weight:700;">Pend: ${WMS.formatNum(p.pendiente)}</div>
              </div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;">
              <input type="number" id="pk-qty-${p.producto_id}" min="0.001" max="${p.pendiente}" step="0.001"
                value="${p.pendiente}" style="width:88px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;">
              <button class="btn btn-primary btn-sm" style="font-size:11px;"
                data-sesion="${sesionId}" data-producto="${p.producto_id}"
                onclick="WMS_MODULES.despacho.agregarItemPacking(+this.dataset.sesion, +this.dataset.producto)">
                <i class="fa-solid fa-plus"></i> Agregar
              </button>
            </div>
          </div>`;
      });
    }

    if (completos.length) {
      html += `<div style="padding:6px 10px;background:#f0fdf4;border-top:2px solid #bbf7d0;margin-top:4px;">
        <span style="font-size:11px;color:#16a34a;font-weight:700;">
          <i class="fa-solid fa-check-double"></i> ${completos.length} producto(s) ya completados
        </span>
      </div>`;
    }

    return html;
  },

  _pkFiltrar(q) {
    const term = (q || '').toLowerCase().trim();
    document.querySelectorAll('.pk-prod-row').forEach(el => {
      const match = !term || (el.dataset.nombre||'').includes(term) || (el.dataset.codigo||'').includes(term);
      el.style.display = match ? '' : 'none';
    });
    // Ocultar cabeceras de ambiente si todos sus hijos están ocultos
    document.querySelectorAll('.pk-amb-header').forEach(header => {
      let next = header.nextElementSibling;
      let hasVisible = false;
      while (next && next.classList.contains('pk-prod-row')) {
        if (next.style.display !== 'none') hasVisible = true;
        next = next.nextElementSibling;
      }
      header.style.display = hasVisible ? '' : 'none';
    });
  },

  _buildItemsTable(items) {
    if (!items.length) return '<p style="padding:12px;color:#94a3b8;font-size:12px;text-align:center;">Unidad vacía</p>';
    return `<table class="erp-table" style="font-size:11px;">
      <thead><tr><th>Ref.</th><th>Producto</th><th class="text-center">Cant.</th><th>Lote</th><th></th></tr></thead>
      <tbody>${items.map(i => `<tr>
        <td><code>${WMS.esc(i.codigo||'-')}</code></td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td class="text-center fw-700">${WMS.formatNum(i.cantidad)}</td>
        <td style="font-size:10px;">${WMS.esc(i.lote||'-')}</td>
        <td><button class="btn btn-danger" style="padding:2px 6px;font-size:10px;"
          onclick="WMS_MODULES.despacho.eliminarItemPacking(${i.id})">
          <i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('')}</tbody>
    </table>`;
  },

  _buildClosedList(unidades, tipoUp, sesionId) {
    const closed = unidades.filter(u => u.estado === 'Cerrada');
    if (!closed.length) return '';
    return `<div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-layer-group"></i> Unidades Cerradas (${closed.length})</span>
        <button class="btn btn-sm btn-outline-primary" data-tipo="${tipoUp}" onclick="WMS_MODULES.despacho._imprimirTodasPacking(this.dataset.tipo)">
          <i class="fa-solid fa-print"></i> Imprimir Todas</button>
      </div>
      <div class="table-container">
        <table class="erp-table" style="font-size:12px;">
          <thead><tr><th>Unidad</th><th class="text-center">Ítems</th><th class="text-center">Total Uds.</th><th>Hora cierre</th><th>Acciones</th></tr></thead>
          <tbody>${closed.map(u => {
            const items = u.items || [];
            return `
              <tr>
                <td><strong>${tipoUp} #${String(u.consecutivo).padStart(3,'0')}</strong></td>
                <td class="text-center">${items.length}</td>
                <td class="text-center fw-700">${WMS.formatNum(u.total_unidades)}</td>
                <td style="font-size:11px;">${u.closed_at ? u.closed_at.substring(11,16) : '-'}</td>
                <td><div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.despacho.toggleClosedUnitItems(${u.id})">
                      <i class="fa-solid fa-eye"></i> Ver ítems</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.despacho._imprimirStickerUnidad(${u.id}, 'letter')">
                      <i class="fa-solid fa-print"></i> Sticker</button>
                  </div></td>
              </tr>
              <tr id="pk-closed-items-${u.id}" style="display:none;">
                <td colspan="5" style="padding:0;border:none;">
                  <div style="padding:10px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                    ${items.length ? `
                      <table class="erp-table" style="width:100%;font-size:11px;margin:0;">
                        <thead><tr><th>Ref.</th><th>Producto</th><th class="text-center">Cant.</th><th>Lote</th><th>Vence</th></tr></thead>
                        <tbody>${items.map(i => `<tr>
                            <td><code>${WMS.esc(i.codigo||'-')}</code></td>
                            <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
                            <td class="text-center fw-700">${WMS.formatNum(i.cantidad)}</td>
                            <td>${WMS.esc(i.lote||'-')}</td>
                            <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'2-digit'}) : '-'}</td>
                          </tr>`).join('')}</tbody>
                      </table>` : '<div style="font-size:12px;color:#475569;">Esta unidad no tiene ítems registrados.</div>'}
                  </div>
                </td>
              </tr>`;
          }).join('')}</tbody>
        </table>
      </div>
    </div>`;
  },

  async _showCanastasDetalle(sesionId) {
    try {
      const r = await API.get('/packing/sesion/' + sesionId);
      if (r.error) { WMS.toast('error', r.message); return; }
      const { sesion, unidades, productos } = r.data;
      const tipoUp   = (sesion.tipo_empaque || 'canasta').charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
      const cerradas = unidades.filter(u => u.estado === 'Cerrada').sort((a, b) => a.consecutivo - b.consecutivo);
      const abierta  = unidades.find(u => u.estado === 'Abierta');
      const pendientes = productos.filter(p => p.pendiente > 0.001);

      const fmtDesglose = it => {
        const cj = it.cantidad_cajas || 0;
        const sl = it.saldo || 0;
        if (cj > 0 || sl > 0)
          return `${cj > 0 ? cj + ' cj' : ''}${cj > 0 && sl > 0 ? ' + ' : ''}${sl > 0 ? sl + ' suelt.' : ''}`;
        return WMS.formatNum(it.cantidad) + ' uds';
      };

      const rowsHtml = cerradas.map(u => {
        const items    = u.items || [];
        const totalUds = u.total_unidades ?? items.reduce((s, i) => s + (i.cantidad || 0), 0);
        const horaC    = u.closed_at ? u.closed_at.substring(11, 16) : '—';
        const detalleRows = items.length
          ? items.map(i => `<tr>
              <td><code style="font-size:11px;">${WMS.esc(i.codigo || '-')}</code></td>
              <td style="font-size:12px;">${WMS.esc(i.producto_nombre || i.nombre || '-')}</td>
              <td class="text-center fw-700" style="font-size:12px;">${fmtDesglose(i)}</td>
              <td style="font-size:11px;color:#64748b;">${WMS.esc(i.lote || '-')}</td>
            </tr>`).join('')
          : `<tr><td colspan="4" style="text-align:center;color:#94a3b8;font-size:12px;">Sin ítems</td></tr>`;

        return `
          <div style="border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;overflow:hidden;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 14px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;">
              <div style="display:flex;align-items:center;gap:10px;">
                <strong style="font-size:13px;color:#065f46;">${tipoUp} #${String(u.consecutivo).padStart(3,'0')}</strong>
                <span style="font-size:11px;color:#6b7280;">${horaC}</span>
                <span style="font-size:11px;background:#dcfce7;color:#15803d;border-radius:4px;padding:1px 6px;">${items.length} ítem(s)</span>
              </div>
              <div style="display:flex;gap:6px;align-items:center;">
                <span style="font-size:13px;font-weight:800;color:#059669;">${WMS.formatNum(totalUds)} uds</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.despacho._imprimirStickerUnidad(${u.id},'letter')">
                  <i class="fa-solid fa-print"></i> Sticker
                </button>
              </div>
            </div>
            <div style="padding:0 12px 8px;">
              <table class="erp-table" style="font-size:12px;margin-top:8px;">
                <thead><tr><th>Ref.</th><th>Producto</th><th class="text-center">Cant.</th><th>Lote</th></tr></thead>
                <tbody>${detalleRows}</tbody>
              </table>
            </div>
          </div>`;
      }).join('');

      const abHtml = abierta ? `
        <div style="border:1.5px dashed #d97706;border-radius:8px;padding:10px 14px;margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <strong style="font-size:13px;color:#d97706;">${tipoUp} #${String(abierta.consecutivo).padStart(3,'0')} — Abierta</strong>
            <span style="font-size:12px;color:#64748b;">${(abierta.items||[]).length} ítem(s) · ${pendientes.length} ref(s) pendiente(s)</span>
          </div>
          ${(abierta.items||[]).length ? this._buildItemsTable(abierta.items) : '<p style="font-size:12px;color:#94a3b8;margin:0;">Unidad vacía</p>'}
        </div>` : '';

      await Swal.fire({
        title: `Detalle de ${tipoUp}s — ${WMS.esc(sesion.sucursal_entrega)}`,
        width: 820,
        html: `<div style="text-align:left;max-height:60vh;overflow-y:auto;padding-right:4px;">
          ${abHtml}
          ${cerradas.length ? `<div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">${cerradas.length} unidad${cerradas.length!==1?'es':''} cerrada${cerradas.length!==1?'s':''}</div>` : ''}
          ${rowsHtml || '<p style="text-align:center;color:#94a3b8;">No hay unidades cerradas</p>'}
        </div>`,
        showConfirmButton: false,
        showCloseButton: true,
      });
    } catch(e) {
      WMS.toast('error', e.message || 'Error al cargar detalle');
    }
  },

  async agregarItemPacking(sesionId, productoId) {
    const qty = parseFloat(document.getElementById('pk-qty-' + productoId)?.value || 0);
    if (!qty || qty <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/item', {
        producto_id: productoId,
        cantidad:    qty,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      if (r.status === 'pending_approval') {
        this._showExpiryWaitModal(r.aprobacion_id, r.message, async () => {
          const r2 = await API.post('/packing/sesion/' + sesionId + '/item', {
            producto_id: productoId,
            cantidad:    qty,
          });
          if (!r2.error) { WMS.toast('success', 'Ítem agregado'); await this.show_packing(sesionId); }
          else WMS.toast('error', r2.message);
        });
        return;
      }
      WMS.toast('success', 'Ítem agregado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al agregar'); }
  },

  async eliminarItemPacking(itemId) {
    const { sesionId } = this._packingState;
    try {
      const r = await API.delete('/packing/item/' + itemId);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Ítem eliminado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al eliminar'); }
  },

  async cerrarUnidadPacking(unidadId) {
    const { sesionId, sesionData } = this._packingState;
    // Save current items before closing (for sticker generation)
    const currentUnit = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (currentUnit) {
      this._packingState.unitsWithItems[unidadId] = currentUnit.items || [];
    }
    try {
      const r = await API.post('/packing/unidad/' + unidadId + '/cerrar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      // Auto-print sticker (media carta) al cerrar canasta
      this._imprimirStickerUnidad(unidadId, 'media_carta');
      WMS.toast('success', r.message || 'Unidad cerrada');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', e.message || 'Error al cerrar unidad'); }
  },

  _imprimirStickerUnidad(unidadId, size) {
    const { sesionData, unitsWithItems } = this._packingState;
    const unidad = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (!unidad) { WMS.toast('error', 'Unidad no encontrada'); return; }
    const items = unitsWithItems[unidadId] || unidad.items || [];
    const html  = this._buildStickerHtml(unidad, sesionData.sesion, items, size);
    const win   = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

  _imprimirTodasPacking(tipoUp) {
    const { sesionData, unitsWithItems } = this._packingState;
    const closed = (sesionData.unidades || []).filter(u => u.estado === 'Cerrada');
    const parts  = closed.map(u => {
      const items = unitsWithItems[u.id] || u.items || [];
      return this._buildStickerBlock(u, sesionData.sesion, items)
           + '<div style="page-break-after:always;"></div>';
    }).join('');
    const html = this._wrapPrintPage(parts, 'letter');
    const win  = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

  toggleClosedUnitItems(unitId) {
    const row = document.getElementById('pk-closed-items-' + unitId);
    if (!row) return;
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
  },

  _buildStickerHtml(unidad, sesion, items, size) {
    return this._wrapPrintPage(this._buildStickerBlock(unidad, sesion, items), size, true);
  },

  _buildStickerBlock(unidad, sesion, items) {
    const tipo    = sesion.tipo_empaque;
    const tipoUp  = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const consec  = String(unidad.consecutivo).padStart(3, '0');
    const cert    = WMS.esc(sesion.certificador_nombre || '-');
    const fecha   = new Date().toLocaleDateString('es-CO');
    const hora    = new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    const total   = (unidad.total_unidades || items.reduce((s, i) => s + (parseFloat(i.cantidad)||0), 0)).toFixed(2);
    const rows    = items.map(i => `
      <tr>
        <td>${WMS.esc(i.codigo||'-')}</td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td style="text-align:right;font-weight:700;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
        <td>${WMS.esc(i.lote||'-')}</td>
        <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'2-digit'}) : '-'}</td>
      </tr>`).join('');

    return `<div class="sticker">
      <div class="st-header">
        <span class="st-tipo">${tipoUp} #${consec}</span>
        <img src="${location.origin}/WMS_FENIX/logo.jpg" style="height:26px;object-fit:contain;vertical-align:middle;" alt="Logo">
      </div>
      <div class="st-suc">Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></div>
      <table>
        <thead><tr><th>Ref.</th><th>Descripción</th><th>Cant.</th><th>Lote</th><th>Vence</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="st-footer">
        <div class="st-total">Total unidades: ${total}</div>
        <div>Certificador: ${cert}</div>
        <div>Fecha: ${fecha} &nbsp; Hora: ${hora}</div>
      </div>
    </div>`;
  },

  _wrapPrintPage(content, size, autoprint) {
    // Rótulos de canasta → media carta (5.5×8.5 in) | Remisión → carta (8.5×11 in)
    const pageSize = size === 'media_carta' ? '5.5in 8.5in' : (size === 'a5' ? 'A5' : 'letter');
    const margin   = size === 'media_carta' ? '10mm 12mm' : '15mm 18mm';
    const script   = autoprint !== false ? '<script>window.onload=()=>{window.focus();window.print();};<\/script>' : '';
    return `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>${size === 'media_carta' ? 'Rótulo Canasta' : 'Documento Packing'}</title>
<style>
@page { size: ${pageSize}; margin: ${margin}; }
@media print { .no-print { display:none!important; } body { margin:0; } }
body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; color: #1e293b; }
.sticker { border: 2px solid #1e293b; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; page-break-inside: avoid; }
.st-header { display:flex; justify-content:space-between; align-items:baseline; font-weight:bold; font-size:14px; border-bottom:2px solid #1e3a5f; padding-bottom:6px; margin-bottom:6px; }
.st-tipo { font-size:18px; font-weight:900; color:#1e40af; }
.st-emp  { font-size:12px; color:#334155; }
.st-suc  { font-size:12px; color:#475569; margin-bottom:6px; }
.st-suc strong { color:#1e293b; font-size:13px; }
table { width:100%; border-collapse:collapse; margin:6px 0; }
th { background:#1e3a5f; color:#fff; font-size:10px; padding:4px 5px; text-align:left; }
td { padding:3px 5px; border-bottom:1px solid #e2e8f0; font-size:10px; }
tr:nth-child(even) td { background:#f8fafc; }
.st-footer { border-top:2px solid #1e3a5f; padding-top:5px; margin-top:6px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:4px; font-size:10px; color:#475569; }
.st-total { font-size:14px; font-weight:900; color:#1e293b; }
.no-print { padding:10px; text-align:center; background:#f1f5f9; }
.no-print button { padding:8px 20px; font-size:14px; cursor:pointer; background:#1e3a5f; color:#fff; border:none; border-radius:6px; }
</style>${script}
</head><body>${content}
<div class="no-print"><button onclick="window.print()">&#128424; Imprimir / Guardar PDF</button></div>
</body></html>`;
  },

  async finalizarPacking(sesionId) {
    if (!confirm('¿Finalizar la certificación de packing? Esta acción no se puede deshacer.')) return;
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/finalizar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Certificación finalizada — ' + r.data.total_unidades + ' unidades de empaque');
      // Refresh packing screen to show document panel
      const sr = await API.get('/packing/sesion/' + sesionId);
      if (!sr.error) {
        this._packingState.sesionData = sr.data;
        this._mostrarPanelDocumento(sr.data);
      } else {
        this.show_certificacion();
      }
    } catch(e) { WMS.toast('error', 'Error al finalizar'); }
  },

  async _mostrarAgotadosSesion(sesionId) {
    try {
      const r = await API.get('/packing/sesion/' + sesionId + '/agotados');
      const agotados = r.data || [];
      const el = document.getElementById('pk-agotados-panel');
      if (!el) return;
      if (!agotados.length) {
        el.innerHTML = '<p style="color:#16a34a;font-size:12px;text-align:center;padding:8px;"><i class="fa-solid fa-check-circle"></i> Sin productos agotados</p>';
        return;
      }
      const rows = agotados.map(a => `<tr>
        <td><code style="font-size:11px;">${WMS.esc(a.codigo || '-')}</code></td>
        <td>${WMS.esc(a.producto_nombre || '-')}</td>
        <td class="text-center fw-700">${WMS.formatNum(a.cantidad_solicitada)}</td>
        <td class="text-center fw-700" style="color:#dc2626;">${WMS.formatNum(a.cantidad_faltante)}</td>
        <td style="font-size:11px;color:#64748b;">${WMS.esc(a.causa || 'Sin stock')}</td>
      </tr>`).join('');
      el.innerHTML = `
        <div class="table-container" style="max-height:260px;overflow-y:auto;">
          <table class="erp-table" style="font-size:12px;">
            <thead>
              <tr>
                <th>Código</th>
                <th>Producto</th>
                <th class="text-center">Solicitado</th>
                <th class="text-center">Faltante</th>
                <th>Causa</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;
    } catch(_) {}
  },

  _mostrarPanelDocumento(data) {
    const { sesion, totales } = data;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    WMS.setContent(`
      <div style="max-width:700px;margin:0 auto;display:flex;flex-direction:column;gap:14px;">
        <div class="card">
          <div class="card-header" style="background:#16a34a;color:#fff;">
            <span class="card-title"><i class="fa-solid fa-circle-check"></i> Packing Completado</span>
          </div>
          <div style="padding:24px;text-align:center;">
            <div style="font-size:48px;color:#16a34a;margin-bottom:12px;">✓</div>
            <h3 style="margin:0 0 6px;">Certificación Finalizada</h3>
            <p style="color:#475569;margin:0 0 20px;">
              <strong>${WMS.esc(sesion.sucursal_entrega)}</strong> — ${totales.num_unidades} ${tipoUp}(s)
            </p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
              <button class="btn btn-primary" onclick="WMS_MODULES.despacho._abrirDocumento(${sesion.id})">
                <i class="fa-solid fa-file-alt"></i> Ver Documento de Packing
              </button>
              <button class="btn btn-outline-primary" data-tipo="${tipoUp}" onclick="WMS_MODULES.despacho._imprimirTodasPacking(this.dataset.tipo)">
                <i class="fa-solid fa-print"></i> Imprimir Todos los Stickers
              </button>
              <button class="btn btn-success" onclick="WMS_MODULES.despacho.imprimirRemision(${sesion.id})">
                <i class="fa-solid fa-print"></i> Imprimir Remisión
              </button>
              <button class="btn btn-secondary" onclick="WMS_MODULES.despacho.show_certificacion()">
                <i class="fa-solid fa-arrow-left"></i> Volver a Certificación
              </button>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-header" style="background:#fef2f2;border-bottom:2px solid #fecaca;">
            <span class="card-title" style="color:#991b1b;">
              <i class="fa-solid fa-circle-exclamation"></i> Productos Agotados en este Despacho
            </span>
          </div>
          <div id="pk-agotados-panel" style="padding:8px 12px;">
            <p style="color:#94a3b8;font-size:12px;text-align:center;padding:8px;">
              <i class="fa-solid fa-spinner fa-spin"></i> Cargando agotados...
            </p>
          </div>
        </div>
      </div>`);
    this._mostrarAgotadosSesion(sesion.id);
  },

  _abrirDocumento(sesionId) {
    const { sesionData, unitsWithItems } = this._packingState;
    const { sesion, unidades } = sesionData;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    const cert   = WMS.esc(sesion.certificador_nombre || '-');
    const emp    = WMS.esc(sesion.empresa_nombre || 'WMS Fénix');
    const fecha  = new Date().toLocaleString('es-CO');

    const closed = (unidades || []).filter(u => u.estado === 'Cerrada');

    // Collect unique separadores
    const seps = new Set();
    closed.forEach(u => {
      (unitsWithItems[u.id] || u.items || []).forEach(i => {
        if (i.separador_nombre?.trim()) seps.add(i.separador_nombre.trim());
      });
    });
    const sepStr = [...seps].join(', ') || 'N/A';

    // Build table rows
    let prevConsec = null;
    let rowClass   = 'even';
    const rows = closed.flatMap(u => {
      if (u.consecutivo !== prevConsec) {
        rowClass   = rowClass === 'even' ? 'odd' : 'even';
        prevConsec = u.consecutivo;
      }
      const consec = String(u.consecutivo).padStart(3,'0');
      return (unitsWithItems[u.id] || u.items || []).map(i => `
        <tr class="${rowClass}">
          <td>#${consec}</td><td>${tipoUp}</td>
          <td>${WMS.esc(i.codigo||'-')}</td>
          <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
          <td style="text-align:right;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
          <td>${WMS.esc(i.lote||'-')}</td>
          <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'numeric'}) : '-'}</td>
        </tr>`);
    }).join('');

    const totalUnidades = closed.length;
    const totalProd     = closed.reduce((s, u) => s + (u.total_unidades || 0), 0).toFixed(2);
    const allCodigos    = new Set(closed.flatMap(u => (unitsWithItems[u.id] || u.items || []).map(i => i.codigo)));
    const totalRefs     = allCodigos.size;

    const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@page { size: letter; margin: 12mm; }
@media print { .no-print { display:none; } }
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; }
.doc-header { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 12px; display:flex; justify-content:space-between; align-items:flex-start; }
.doc-title { font-size:16px; font-weight:bold; color:#1e40af; margin:0 0 4px; }
.doc-meta { font-size:10px; color:#475569; margin-top:3px; }
.doc-meta span { display:inline-block; margin-right:12px; }
table { width:100%; border-collapse:collapse; margin:10px 0; }
th { background:#1e40af; color:#fff; font-size:10px; padding:4px 6px; text-align:left; }
td { padding:3px 6px; font-size:10px; border-bottom:1px solid #e2e8f0; }
tr.even td { background:#f8faff; } tr.odd td { background:#fff; }
.doc-footer { border-top:2px solid #1e40af; margin-top:12px; padding-top:8px; display:flex; gap:16px; flex-wrap:wrap; }
.foot-box .label { font-size:9px; color:#64748b; }
.foot-box .val   { font-weight:bold; font-size:13px; }
.no-print { margin:12px 0; text-align:center; }
</style>
<script>
function toggleLandscape() {
  const rule = Array.from(document.styleSheets[0].cssRules).find(r => r.cssText?.includes('@page'));
  if (rule) rule.style.cssText = rule.style.cssText.includes('landscape')
    ? rule.style.cssText.replace('landscape','portrait')
    : rule.style.cssText.replace('portrait','landscape');
}
<\/script>
</head><body>
<div class="doc-header">
  <div>
    <div class="doc-title">DOCUMENTO DE PACKING</div>
    <div class="doc-meta"><span><img src="${location.origin}/WMS_FENIX/logo.jpg" style="height:38px;object-fit:contain;vertical-align:middle;" alt="Logo"></span><span>Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></span><span>Tipo: <strong>${tipoUp}</strong></span></div>
    <div class="doc-meta"><span>Fecha/Hora: ${fecha}</span><span>Certificador: <strong>${cert}</strong></span><span>Separadores: ${WMS.esc(sepStr)}</span></div>
  </div>
  <div class="no-print">
    <button onclick="toggleLandscape()" style="margin-right:6px;">Girar</button>
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>
<table>
  <thead><tr><th>Unidad</th><th>Tipo</th><th>Referencia</th><th>Descripción</th><th>Cantidad</th><th>Lote</th><th>Vence</th></tr></thead>
  <tbody>${rows}</tbody>
</table>
<div class="doc-footer">
  <div class="foot-box"><div class="label">Unidades de empaque</div><div class="val">${totalUnidades}</div></div>
  <div class="foot-box"><div class="label">Total uds. producto</div><div class="val">${totalProd}</div></div>
  <div class="foot-box"><div class="label">Referencias distintas</div><div class="val">${totalRefs}</div></div>
  <div class="foot-box"><div class="label">Separó</div><div class="val" style="font-size:11px;">${WMS.esc(sepStr)}</div></div>
  <div class="foot-box"><div class="label">Certificó</div><div class="val" style="font-size:11px;">${cert}</div></div>
</div>
</body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

  // ── EXPIRY APPROVAL WAITING MODAL ──────────────────────────────────────────
  _showExpiryWaitModal(aprobacionId, message, onApproved) {
    this._expiryAprobacionId = aprobacionId;
    this._expiryOnApproved   = onApproved;
    const modalId = 'expiry-wait-modal';
    document.getElementById(modalId)?.remove();
    document.body.insertAdjacentHTML('beforeend', `
      <div id="${modalId}" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);
        display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);">
          <div style="font-size:2.5rem;margin-bottom:12px;">⏳</div>
          <div style="font-weight:700;font-size:1rem;margin-bottom:8px;">Esperando aprobación del supervisor</div>
          <div style="font-size:.85rem;color:#64748b;margin-bottom:20px;">${WMS.esc(message)}</div>
          <button onclick="WMS_MODULES.despacho._cancelarExpiryWait()" style="background:#ef4444;color:#fff;
            border:none;border-radius:6px;padding:8px 20px;cursor:pointer;font-size:.85rem;">
            Cancelar solicitud
          </button>
        </div>
      </div>`);
    this._expiryPollTimer = setInterval(() => this._pollExpiryWait(), 10000);
  },

  async _pollExpiryWait() {
    if (!this._expiryAprobacionId) return;
    const idSnapshot = this._expiryAprobacionId;
    try {
      const r = await API.get('/aprobaciones/' + idSnapshot + '/estado');
      if (this._expiryAprobacionId !== idSnapshot) return; // cancelled mid-flight
      if (r.data?.estado === 'aprobada') {
        const cb = this._expiryOnApproved;
        this._closeExpiryWaitModal();
        WMS.toast('success', 'Solicitud aprobada. Continuando...');
        if (cb) await cb();
      } else if (r.data?.estado === 'rechazada') {
        this._closeExpiryWaitModal();
        WMS.toast('error', 'Solicitud rechazada por el supervisor.');
      }
    } catch(_) {}
  },

  _closeExpiryWaitModal() {
    clearInterval(this._expiryPollTimer);
    this._expiryPollTimer    = null;
    this._expiryAprobacionId = null;
    this._expiryOnApproved   = null;
    document.getElementById('expiry-wait-modal')?.remove();
  },

  async _cancelarExpiryWait() {
    if (this._expiryAprobacionId) {
      try { await API.delete('/aprobaciones/' + this._expiryAprobacionId); } catch(_) {}
    }
    this._closeExpiryWaitModal();
    WMS.toast('warning', 'Solicitud de vencimiento cancelada');
  },

  async verDetallesPendientes(sucursal) {
    WMS.spinner();
    try {
      const r = await API.get('/picking/certificacion/detalle/' + encodeURIComponent(sucursal));
      const items = r.data || [];
      
      const porAmbiente = {};
      items.forEach(it => {
        const amb = it.ambiente_nombre || 'Sin Ambiente';
        if(!porAmbiente[amb]) porAmbiente[amb] = [];
        porAmbiente[amb].push(it);
      });

      let html = `<div style="max-height:60vh;overflow-y:auto;padding-right:10px;">`;
      for(const [amb, prods] of Object.entries(porAmbiente)) {
        html += `<h4 style="background:#e2e8f0;padding:6px;border-radius:4px;color:#1e293b;margin-top:10px;">${WMS.esc(amb)} <span class="badge" style="float:right;background:#0f4c81;">${prods.length} Refs</span></h4>
        <table class="erp-table table-sm" style="margin-bottom:10px;">
          <thead><tr><th>Producto</th><th class="text-center">Cant. Solicitada</th><th class="text-center">Cant. Pickeada</th></tr></thead>
          <tbody>`;
        prods.forEach(p => {
          html += `<tr>
            <td><div style="font-weight:700;">${WMS.esc(p.nombre)}</div><div style="font-size:11px;color:#64748b;">${WMS.esc(p.codigo)}</div></td>
            <td class="text-center">${p.cantidad_esperada || 0}</td>
            <td class="text-center" style="font-weight:700;color:#059669;">${p.cantidad_pickeada || 0}</td>
          </tr>`;
        });
        html += `</tbody></table>`;
      }
      html += `</div>`;

      const result = await Swal.fire({
        title: 'Detalles por Ambiente: ' + sucursal,
        html: html,
        width: 800,
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Certificar',
        denyButtonText: '<i class="fa-solid fa-box-open"></i> Iniciar Packing',
        cancelButtonText: 'Cerrar',
        confirmButtonColor: '#059669',
        denyButtonColor: '#0F4C81',
      });
      if (result.isConfirmed) {
        WMS_MODULES.despacho.autoCertificar(sucursal);
      } else if (result.isDenied) {
        WMS_MODULES.despacho.iniciarCertificacion(sucursal);
      }
    } catch(e) {
      WMS.showError('Error al obtener detalles', e);
    }
  }
};
