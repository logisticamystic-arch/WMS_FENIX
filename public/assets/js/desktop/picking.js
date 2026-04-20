/* ============================================================
   WMS Desktop — Módulo PICKING v2
   Sub-vistas: pedidos | asignacion | faltantes | dashboard | reporte
   Mejoras v2:
   - Agrupación por planilla con expansión por línea
   - Asignación por planilla (no por orden individual)
   - División por pasillo y múltiples auxiliares por planilla
   - Visualización cajas + picos (unidades residuales)
   - Auto numeración de planilla si no viene en documento
   - Dashboard rediseñado: progreso global, drill-down por planilla
   - Ranking de auxiliares con tiempos por línea
   ============================================================ */
WMS_MODULES.picking = {
  getToday() { return new Date().toISOString().split('T')[0]; },

  load(sub) {
    if (!WMS.getToday) WMS.getToday = () => this.getToday();
    WMS.setBreadcrumb('picking', this.subLabel(sub));
    WMS.renderSidebar('picking');
    const s = sub || 'pedidos';
    const fn = {
      pedidos: this.show_pedidos, asignacion: this.show_asignacion,
      faltantes: this.show_faltantes, dashboard: this.show_dashboard,
      reporte: this.show_reporte,
    };
    (fn[s]?.bind(this) || fn.pedidos.bind(this))();
    // Picking es proceso crítico: auto-refresh activo en pedidos, asignación y dashboard
    if (['pedidos','asignacion','dashboard'].includes(s)) this.startAutoRefresh(s);
    else this.stopAutoRefresh();
  },

  // ── Auto-refresh picking (proceso crítico, máx 5 usuarios) ───────────────
  _pickInterval: null,
  startAutoRefresh(sub) {
    this.stopAutoRefresh();
    this._pickInterval = setInterval(() => {
      if (WMS.currentModule !== 'picking') { this.stopAutoRefresh(); return; }
      const cur = WMS.currentSubModule;
      if (cur === 'pedidos')         this.show_pedidos(true);
      else if (cur === 'asignacion') this.show_asignacion(true);
      else if (cur === 'dashboard')  this.show_dashboard(true);
      else                           this.stopAutoRefresh();
    }, 30000);
    this._updateAutoRefreshBadge(true);
  },
  stopAutoRefresh() {
    if (this._pickInterval) { clearInterval(this._pickInterval); this._pickInterval = null; }
    this._updateAutoRefreshBadge(false);
  },
  _updateAutoRefreshBadge(active) {
    const badge = document.getElementById('pick-refresh-badge');
    if (badge) badge.style.display = active ? 'inline-flex' : 'none';
  },

  subLabel(s) {
    const m = { pedidos:'Pedidos / Planillas', asignacion:'Asignación de Picking',
      faltantes:'Faltantes de Stock', dashboard:'Dashboard Picking', reporte:'Reporte Picking' };
    return m[s] || s || 'Panel';
  },

  // ── UTILIDADES ────────────────────────────────────────────────────────────

  /** Convierte unidades a cajas completas + picos residuales */
  _cajasYPicos(cantidad, unidades_caja) {
    const upc = Math.max(1, parseInt(unidades_caja) || 1);
    const cant = Math.max(0, parseFloat(cantidad) || 0);
    const cajas = Math.floor(cant / upc);
    const picos = Math.round((cant % upc) * 100) / 100;
    return { cajas, picos, upc };
  },

  /** Renderiza la cantidad como "13 cj + 10 und" o solo el número si upc=1 */
  _fmtCantidad(cantidad, unidades_caja) {
    const upc = Math.max(1, parseInt(unidades_caja) || 1);
    if (upc <= 1) return WMS.formatNum(cantidad);
    const { cajas, picos } = this._cajasYPicos(cantidad, upc);
    const partes = [];
    if (cajas > 0) partes.push(`<strong>${cajas}</strong> cj`);
    if (picos > 0) partes.push(`<span style="color:#64748b;">${picos} und</span>`);
    return partes.length ? partes.join(' + ') : '0';
  },

  /** Agrupa array de órdenes por planilla_numero / planilla_lote */
  _agruparPorPlanilla(items) {
    const grupos = {};
    items.forEach(p => {
      const key = p.planilla_numero || p.planilla_lote || ('DOC-' + String(p.id).padStart(5,'0'));
      if (!grupos[key]) {
        grupos[key] = {
          planilla: key,
          ruta:     p.area_comercial || p.ruta || '-',
          estado:   p.estado  || 'Creado',
          ordenes:  [],
          total_lineas: 0,
          lineas_pendientes: 0,
          total_unidades: 0,
          auxiliares: new Set(),
          prioridad: 0,
          productos: {},
          primer_pick_ts: null,
          primer_pick_str: null,
          estados: new Set(),
        };
      }
      grupos[key].ordenes.push(p);
      const detalles = p.detalles || [];
      grupos[key].total_lineas += detalles.length || parseInt(p.total_lineas || p.lineas || 0);
      let pendThisOrder = 0;
      detalles.forEach(d => {
        const prodId = d.producto_id || d.id;
        const barcode = d.producto?.codigo_barras || d.producto?.codigo_interno || '';
        const prodName = (barcode ? `[${barcode}] ` : '') + (d.producto?.nombre || d.descripcion || 'Producto #' + prodId);
        
        if (!grupos[key].productos[prodId]) {
          grupos[key].productos[prodId] = {
            nombre: prodName,
            cantidad_total: 0,
            cantidad_pendiente: 0,
            unidades_caja: d.producto?.unidades_caja || 1,
            clientes: new Set(),
            pedidosSet: new Set(),
            auxiliares: new Set(),
            hora_inicio: p.hora_inicio || null,
            hora_fin: null,
            estados: new Set(),
          };
        }
        const pr = grupos[key].productos[prodId];
        pr.cantidad_total += parseFloat(d.cantidad_solicitada || 0);
        pr.cantidad_pendiente += (parseFloat(d.cantidad_solicitada || 0) - parseFloat(d.cantidad_pickeada || 0));
        pr.clientes.add(p.cliente || '-');
        pr.pedidosSet.add(p.id);
        if (d.auxiliar?.nombre) pr.auxiliares.add(d.auxiliar.nombre);
        else if (p.auxiliar?.nombre) pr.auxiliares.add(p.auxiliar.nombre);
        
        // Registrar estado en el grupo para calcular progreso global
        grupos[key].estados.add(d.estado);
        
        // Tiempos: inicio de la orden, fin de la línea (updated_at)
        const parseTime = ts => {
          if (!ts) return null;
          if (ts.includes('T')) return ts.split('T')[1].split('.')[0]; // ISO
          if (ts.includes(' ')) return ts.split(' ')[1]; // SQL
          return ts;
        };

        const lineaFinRaw = d.updated_at || d.hora_fin || null;
        const lineaFin = parseTime(lineaFinRaw);
        
        if (p.hora_inicio && (!pr.hora_inicio || p.hora_inicio < pr.hora_inicio)) pr.hora_inicio = p.hora_inicio;
        
        if (['Completado','Faltante'].includes(d.estado)) {
          if (lineaFin && (!pr.hora_fin || lineaFin > pr.hora_fin)) pr.hora_fin = lineaFin;
          // Guardar el timestamp completo para calcular el inicio de la planilla (primer producto)
          if (lineaFinRaw) {
            const tsFull = new Date(lineaFinRaw.includes(' ') ? lineaFinRaw : lineaFinRaw.replace('T',' ').split('.')[0]);
            if (!grupos[key].primer_pick_ts || tsFull < grupos[key].primer_pick_ts) {
              grupos[key].primer_pick_ts = tsFull;
              grupos[key].primer_pick_str = lineaFin;
            }
          }
        }
        pr.estados.add(d.estado);

        if (['Pendiente', 'Asignado', 'EnProceso', 'Creado'].includes(d.estado)) pendThisOrder++;
      });
      grupos[key].lineas_pendientes += (detalles.length ? pendThisOrder : (parseInt(p.lineas_pendientes || p.total_lineas || p.lineas || 0)));
      if (p.auxiliar?.nombre || p.usuario) grupos[key].auxiliares.add(p.auxiliar?.nombre || p.usuario);
      if (p.prioridad) grupos[key].prioridad = 1;
      
      // El estado del grupo será recalculado después de procesar todos los registros
    });

    const res = Object.values(grupos);
    res.forEach(g => {
      const pct = g.total_lineas > 0 ? ((g.total_lineas - g.lineas_pendientes) / g.total_lineas) * 100 : 0;
      const isActuallyBusy = g.estados.has('EnProceso') || g.estados.has('Confirmado') || g.estados.has('Asignado');
      
      if (pct >= 100) {
        g.estado = 'Completado';
      } else if (pct > 0 || isActuallyBusy) {
        // Si hay avance O si ya hay un auxiliar moviendo líneas, está "En Proceso"
        g.estado = 'EnProceso';
      } else {
        // 0% progreso y sin movimientos
        g.estado = g.auxiliares.size > 0 ? 'Asignado' : 'Pendiente';
      }
    });
    return res;
  },

  /** 
   * Calcula duración entre dos fechas/horas.
   * Si no hay fin, usa el tiempo actual para el timer.
   */
  _getDuration(start, end) {
    if (!start) return { str: '-', totalMs: 0, finished: false };
    const sDate = new Date(start.includes(' ') ? start : (WMS.getToday() + ' ' + start));
    if (isNaN(sDate)) return { str: '-', totalMs: 0, finished: false };
    
    const eDate = end ? new Date(end.includes(' ') ? end : (WMS.getToday() + ' ' + end)) : new Date();
    const diff = eDate - sDate;
    if (diff < 0) return { str: '00:00:00', totalMs: 0, finished: !!end };
    
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    const s = Math.floor((diff % 60000) / 1000);
    const str = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    return { str, totalMs: diff, finished: !!end };
  },

  /** Renderiza una fila de planilla de forma reutilizable */
  _renderPlanillaRow(g, options = {}) {
    const isDash = options.isDashboard || false;
    const auxList = [...g.auxiliares].join(', ') || '-';
    const pct = g.total_lineas > 0 ? Math.round(((g.total_lineas - g.lineas_pendientes) / g.total_lineas) * 100) : 100;
    const ordenIdsJson = JSON.stringify(g.ordenes.map(o=>o.id));
    
    // Tiempos Operacionales Centralizados
    // Punto de partida: La hora del primer producto separado (g.primer_pick_str)
    const inicioOp = g.primer_pick_str || g.ordenes[0]?.hora_inicio;
    const durGlobal = this._getDuration(inicioOp, g.estado === 'Completado' ? (g.ordenes[0]?.updated_at || g.ordenes[0]?.hora_fin) : null);

    const stChip = s => {
      const m = { Creado:'status-creada', Pendiente:'status-creada', Asignado:'status-confirmada',
        EnProceso:'status-en-proceso', Completado:'status-cerrada', Cancelado:'status-cancelada',
        'En Proceso':'status-en-proceso', Cumplida:'status-cerrada', Parcial:'status-confirmada' };
      return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
    };

    const prodRows = Object.values(g.productos).map(pr => {
      // Duración individual relativa al primer producto
      const durLine = this._getDuration(inicioOp, pr.hora_fin);
      let estadoFinal = "Pendiente";
      if (pr.estados.has('EnProceso')) estadoFinal = "En Proceso";
      if (pr.estados.has('Completado') || pr.estados.has('Faltante')) {
         estadoFinal = (pr.estados.size === 1 || (pr.estados.size === 2 && pr.estados.has('Completado'))) ? "Cumplida" : "Parcial";
      }
      return `
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:5px 8px;"><b style="color:#1e293b">${WMS.esc(pr.nombre)}</b></td>
        <td style="padding:5px 8px;text-align:center;font-weight:600;">${WMS.formatNum(pr.cantidad_total)}</td>
        <td style="padding:5px 8px;text-align:center;">${this._fmtCantidad(pr.cantidad_total, pr.unidades_caja)}</td>
        <td style="padding:5px 8px;text-align:center;color:#dc3545;font-weight:600;">${WMS.formatNum(pr.cantidad_pendiente)}</td>
        <td style="padding:5px 8px;text-align:center;font-size:11px;">${WMS.esc([...pr.auxiliares].join(', ') || '-')}</td>
        <td style="padding:5px 8px;text-align:center;font-size:11px;color:#2563eb;font-weight:700;">${pr.hora_fin || '-'}</td>
        <td style="padding:5px 8px;text-align:center;font-size:11px;color:#64748b;font-family:monospace;">${pr.hora_fin ? (durLine.str || '00:00:00') : '-'}</td>
        <td style="padding:5px 8px;text-align:center;">${stChip(estadoFinal)}</td>
      </tr>`;
    }).join('');

    return `
    <tr data-estado="${g.estado}" data-planilla="${WMS.esc(g.planilla)}">
      <td>
        <div style="display:flex;align-items:center;gap:8px;">
          <button class="btn btn-xs btn-light" onclick="WMS_MODULES.picking._togglePlanilla('${WMS.esc(g.planilla)}')" title="Ver detalle">
            <i class="fa-solid fa-chevron-right" id="icon-plan-${WMS.esc(g.planilla)}" style="transition:.2s"></i>
          </button>
          <span class="badge badge-info" style="font-size:11.5px;font-weight:700;">#${WMS.esc(g.planilla)}</span>
        </div>
      </td>
      <td><span style="font-size:11px;font-weight:600;color:#64748b;">${WMS.esc(g.ruta)}</span></td>
      <td class="text-center"><b>${g.total_lineas - g.lineas_pendientes}</b> / ${g.total_lineas}</td>
      <td>
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="flex:1;background:#e2e8f0;border-radius:99px;height:5px;overflow:hidden;">
            <div style="width:${pct}%;background:${pct===100?'#10b981':'#3b82f6'};height:100%;"></div>
          </div>
          <span style="font-size:10px;font-weight:700;color:#64748b;">${pct}%</span>
        </div>
      </td>
      <td>${stChip(g.estado)}</td>
      <td style="font-size:11px;font-weight:600;">${WMS.esc(auxList)}</td>
      <td class="text-center"><span style="font-family:monospace;font-size:11px;">${inicioOp || '-'}</span></td>
      <td>
        ${!isDash ? `
          <div class="actions" style="gap:4px;">
            <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking.asignarRutaPlanilla('${WMS.esc(g.planilla)}',${ordenIdsJson})"><i class="fa-solid fa-route"></i></button>
            <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.picking.asignarPlanilla('${WMS.esc(g.planilla)}',${ordenIdsJson})"><i class="fa-solid fa-user-check"></i> Asignar</button>
          </div>
        ` : `
          <div class="text-right">
             ${g.prioridad ? '<span class="badge badge-danger">Alta</span>' : '<span class="badge badge-light">Norm.</span>'}
          </div>
        `}
      </td>
    </tr>
    <tr id="sub-plan-${WMS.esc(g.planilla)}" style="display:none;background:#f8fafc;">
      <td colspan="9" style="padding:0 8px 10px 42px;">
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;box-shadow:inset 0 2px 4px rgba(0,0,0,.02)">
          <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead style="background:#f1f5f9;color:#64748b;font-weight:700;text-transform:uppercase;font-size:10px;">
              <tr>
                <th style="padding:6px 8px;">Producto</th>
                <th style="padding:6px 8px;text-align:center;">Total</th>
                <th style="padding:6px 8px;text-align:center;">Separación</th>
                <th style="padding:6px 8px;text-align:center;color:#dc3545;">Pend.</th>
                <th style="padding:6px 8px;text-align:center;">Auxiliar</th>
                <th style="padding:6px 8px;text-align:center;color:#2563eb;">Hr. Separado</th>
                <th style="padding:6px 8px;text-align:center;">Duración</th>
                <th style="padding:6px 8px;text-align:center;">Estado</th>
              </tr>
            </thead>
            <tbody>${prodRows}</tbody>
          </table>
        </div>
      </td>
    </tr>`;
  },

  // ── PEDIDOS / PLANILLAS ───────────────────────────────────────────────────
  async show_pedidos(silent = false) {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.importarPedidos()"><i class="fa-solid fa-file-import"></i> Importar</button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking.show_pedidos()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <span id="pick-refresh-badge" style="display:inline-flex;align-items:center;gap:5px;background:#198754;color:#fff;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;">
        <span style="width:7px;height:7px;border-radius:50%;background:#fff;animation:pulse-dot 1.2s infinite;display:inline-block;"></span> Auto 30s
      </span>`);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/picking', 'estado=Pendiente&limit=200');
      const items = r.data || r || [];

      // Agrupar por planilla
      const grupos = this._agruparPorPlanilla(items);

      const stChip = s => {
        const m = { Creado:'status-creada', Pendiente:'status-creada', Asignado:'status-confirmada',
          EnProceso:'status-en-proceso', Completado:'status-cerrada', Cancelado:'status-cancelada' };
        return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
      };

      const filas = grupos.map(g => {
        return this._renderPlanillaRow(g);
      }).join('');

      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar planilla, ruta..." oninput="WMS_MODULES.picking.filterTable(this.value,'pick-table')">
          </div>
          <select class="form-control" style="max-width:160px;" onchange="WMS_MODULES.picking.filterEstado(this.value)">
            <option value="Pendiente" selected>Pendiente</option>
            <option value="Asignado">Asignado</option>
            <option>EnProceso</option><option>Completado</option>
          </select>
          <span class="badge badge-info" style="padding:6px 12px;">${grupos.length} planilla(s) — ${items.length} orden(es)</span>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-layer-group"></i> Planillas de Picking (${grupos.length})</span>
          </div>
          <div class="table-container">
            <table class="data-table" id="pick-table">
              <thead><tr>
                <th>Planilla</th><th>Ruta</th>
                <th style="text-align:center;">Líneas</th>
                <th style="text-align:center;">Pendientes</th>
                <th style="min-width:120px;">Progreso</th>
                <th>Estado</th><th>Auxiliar(es)</th><th>Hora Inicio</th><th>Acciones</th>
              </tr></thead>
              <tbody>${filas || '<tr><td colspan="8" class="table-empty">Sin órdenes de picking</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) {
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>');
    }
  },

  _togglePlanilla(planilla) {
    const row  = document.getElementById('sub-plan-' + planilla);
    const icon = document.getElementById('icon-plan-' + planilla);
    if (!row) return;
    const isHidden = row.style.display === 'none';
    row.style.display = isHidden ? 'table-row' : 'none';
    row.setAttribute('data-open', isHidden ? 'true' : 'false');
    if (icon) icon.style.transform = isHidden ? 'rotate(90deg)' : '';
  },

  filterTable(q, tableId) {
    const rows = document.querySelectorAll('#' + (tableId || 'pick-table') + ' tbody > tr');
    const f = q.toLowerCase();
    let lastMainMatch = false;
    
    rows.forEach(r => {
      if (r.id && r.id.startsWith('sub-plan-')) {
        r.style.display = (lastMainMatch && r.getAttribute('data-open') === 'true') ? 'table-row' : 'none';
        return;
      }
      const match = r.textContent.toLowerCase().includes(f);
      r.style.display = match ? '' : 'none';
      lastMainMatch = match;
    });
  },

  filterEstado(estado) {
    const rows = document.querySelectorAll('#pick-table tbody > tr');
    let lastMainMatch = false;
    
    rows.forEach(r => {
      if (r.id && r.id.startsWith('sub-plan-')) {
        r.style.display = (lastMainMatch && r.getAttribute('data-open') === 'true') ? 'table-row' : 'none';
        return;
      }
      const itemEstado = r.dataset.estado;
      const match = !estado || itemEstado === estado;
      r.style.display = match ? '' : 'none';
      lastMainMatch = match;
    });
  },

  // ── VER DETALLE ORDEN ─────────────────────────────────────────────────────
  async verDetalle(id) {
    try {
      const r = await API.get('/picking/' + id);
      const p = r.data || r;
      const lineas = p.lineas || p.detalles || [];
      const self = this;
      WMS.showModal('Detalle Picking — ' + (p.planilla_numero || ('#' + id)), `
        <div class="form-grid form-grid-2" style="margin-bottom:16px;">
          <div><label class="form-label">Planilla</label><p><span class="badge badge-info">${WMS.esc(p.planilla_numero||'N/A')}</span></p></div>
          <div><label class="form-label">Cliente</label><p>${WMS.esc(p.cliente||'-')}</p></div>
          <div><label class="form-label">Ruta</label><p>${WMS.esc(p.ruta||'-')}</p></div>
          <div><label class="form-label">Estado</label><p><span class="badge badge-info">${WMS.esc(p.estado||'')}</span></p></div>
          <div><label class="form-label">Auxiliar</label><p>${WMS.esc(p.auxiliar||p.usuario||'-')}</p></div>
          ${p.observaciones ? `<div style="grid-column:1/-1;"><label class="form-label">Observaciones</label><p>${WMS.esc(p.observaciones)}</p></div>` : ''}
        </div>
        <div class="table-container">
          <table class="data-table">
            <thead><tr>
              <th>Producto</th><th>Ubicación</th>
              <th style="text-align:center;">Pedido</th>
              <th style="text-align:center;">Cajas + Picos</th>
              <th style="text-align:center;">Confirmado</th>
              <th>Estado Línea</th>
            </tr></thead>
            <tbody>${lineas.map(l => {
              const upc = l.unidades_caja || l.producto?.unidades_caja || 1;
              const prodNombre = l.producto?.nombre || l.descripcion || '-';
              const cantSol = l.cantidad_solicitada || l.cantidad_pedida || l.cantidad || 0;
              return `<tr>
                <td>${WMS.esc(prodNombre)}</td>
                <td><span class="badge badge-info">${WMS.esc(l.ubicacion?.codigo || l.ubicacion || '-')}</span></td>
                <td style="text-align:center;">${WMS.formatNum(cantSol)}</td>
                <td style="text-align:center;">${self._fmtCantidad(cantSol, upc)}</td>
                <td style="text-align:center;">${WMS.formatNum(l.cantidad_pickeada||l.cantidad_confirmada||0)}</td>
                <td><span class="badge ${(l.estado==='Completado'||l.completada)?'badge-success':((l.estado==='Faltante'||l.faltante)?'badge-danger':'badge-warning')}">
                  ${(l.estado==='Completado'||l.completada)?'Completado':((l.estado==='Faltante'||l.faltante)?'Faltante':'Pendiente')}
                </span></td>
              </tr>`;
            }).join('') || '<tr><td colspan="6" class="table-empty">Sin líneas</td></tr>'}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
         ${p.estado==='Asignado'||p.estado==='EnProceso' ? `<button class="btn btn-warning" onclick="WMS_MODULES.picking.transferir(${p.id})"><i class="fa-solid fa-right-left"></i> Transferir</button>` : ''}
         ${p.estado==='EnProceso' ? `<button class="btn btn-success" onclick="WMS_MODULES.picking.completarPicking(${id})"><i class="fa-solid fa-check-double"></i> Completar</button>` : ''}`);
    } catch(e) { WMS.toast('error', 'Error cargando detalle'); }
  },

  // ── ASIGNACIÓN POR PLANILLA ───────────────────────────────────────────────
  async asignarPlanilla(planilla, ordenIds) {
    let personal = [];
    let categorias = [];
    try {
      const [rPers, rCat] = await Promise.all([
        API.get('/param/personal', 'rol=Auxiliar&activo=1'),
        API.get('/param/categorias')
      ]);
      personal = rPers.data || rPers || [];
      categorias = rCat.data || rCat || [];
    } catch(e) { console.error('Error cargando parámetros', e); }

    // Guardamos los IDs para usarlos en confirmarAsignacionPlanilla
    window._assignPlanillaIds = ordenIds;
    window._assignPlanillaKey = planilla;

    WMS.showModal(`Asignar Planilla: ${planilla}`, `
      <div style="margin-bottom:12px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;color:#1e40af;">
        <i class="fa-solid fa-layer-group"></i>
        <strong>${ordenIds.length}</strong> orden(es) en esta planilla serán asignadas.
      </div>

      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label class="form-label">Auxiliar Principal <span class="required">*</span></label>
          <select id="asig-personal" class="form-control">
            <option value="">Seleccionar auxiliar...</option>
            ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Prioridad</label>
          <select id="asig-prio" class="form-control">
            <option value="0">Normal</option>
            <option value="1">Alta</option>
          </select>
        </div>
      </div>

      <div class="form-group" style="margin-top:12px;">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" id="asig-split-pasillo" onchange="document.getElementById('split-config').style.display=this.checked?'block':'none'">
          <span><i class="fa-solid fa-code-branch"></i> Dividir por pasillo</span>
        </label>
      </div>

      <div id="split-config" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-top:8px;">
        <div style="font-size:13px;font-weight:700;color:#1e3a5f;margin-bottom:10px;"><i class="fa-solid fa-person-walking-arrow-right"></i> Configuración de División por Pasillo</div>
        <div id="split-rows">
          ${[1,2].map(i => `
          <div class="split-row" style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-end;">
            <div class="form-group" style="flex:1;margin:0;">
              <label class="form-label" style="font-size:11px;">Auxiliar ${i}</label>
              <select class="form-control split-aux" style="font-size:12px;">
                <option value="">Seleccionar...</option>
                ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre)}</option>`).join('')}
              </select>
            </div>
            <div class="form-group" style="flex:1;margin:0;">
              <label class="form-label" style="font-size:11px;">Pasillos (ej: 01, 02)</label>
              <input class="form-control split-pass" style="font-size:12px;" placeholder="01-03">
            </div>
          </div>`).join('')}
        </div>
        <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking._addSplitRow()" style="margin-top:4px;">
          <i class="fa-solid fa-plus"></i> Agregar auxiliar
        </button>
      </div>

      <div style="margin-top:12px; border-top:1px solid #e2e8f0; padding-top:12px;">
        <label class="form-label"><i class="fa-solid fa-tags"></i> Filtrar por Categoría (Opcional - Múltiples)</label>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; max-height:150px; overflow-y:auto; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
           ${categorias.map(c => `
             <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;margin:0;">
               <input type="checkbox" class="asig-cat-check" value="${c.id}">
               <span>${WMS.esc(c.nombre)}</span>
             </label>
           `).join('')}
           ${categorias.length === 0 ? '<div style="grid-column:1/-1;text-align:center;color:#94a3b8;">No hay categorías configuradas</div>' : ''}
        </div>
      </div>

      <div class="form-group" style="margin-top:12px;">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" id="asig-separar-consolidado">
          <span><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Separar Consolidado (Sólo almacenamiento)</span>
        </label>
      </div>

      <div class="form-group" style="margin-top:12px;">
        <label class="form-label">Observaciones</label>
        <textarea id="asig-obs" class="form-control" rows="2" placeholder="Instrucciones adicionales..."></textarea>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.picking.confirmarAsignacionPlanilla()">
         <i class="fa-solid fa-user-check"></i> Confirmar Asignación
       </button>`);
  },

  _splitRowCount: 2,
  _addSplitRow() {
    this._splitRowCount++;
    const i = this._splitRowCount;
    const container = document.getElementById('split-rows');
    if (!container) return;
    const firstSel = container.querySelector('.split-aux');
    const optsCopy = firstSel ? firstSel.innerHTML : '<option value="">Seleccionar...</option>';
    const div = document.createElement('div');
    div.className = 'split-row';
    div.style.cssText = 'display:flex;gap:10px;margin-bottom:8px;align-items:flex-end;';
    div.innerHTML = `
      <div class="form-group" style="flex:1;margin:0;">
        <label class="form-label" style="font-size:11px;">Auxiliar ${i}</label>
        <select class="form-control split-aux" style="font-size:12px;">${optsCopy}</select>
      </div>
      <div class="form-group" style="flex:1;margin:0;">
        <label class="form-label" style="font-size:11px;">Pasillos (ej: 04, 05)</label>
        <input class="form-control split-pass" style="font-size:12px;" placeholder="04-06">
      </div>`;
    container.appendChild(div);
  },

  async confirmarAsignacionPlanilla() {
    const ordenIds  = window._assignPlanillaIds || [];
    const personalId = document.getElementById('asig-personal')?.value;
    const prioridad  = parseInt(document.getElementById('asig-prio')?.value || '0');
    const obs        = document.getElementById('asig-obs')?.value?.trim() || null;
    const splitOn    = document.getElementById('asig-split-pasillo')?.checked;
    const sepCons    = document.getElementById('asig-separar-consolidado')?.checked;
    
    // Categorías seleccionadas
    const catIds = Array.from(document.querySelectorAll('.asig-cat-check:checked')).map(cb => parseInt(cb.value));

    if (!personalId && !splitOn) {
      return WMS.toast('warning', 'Seleccione un auxiliar principal');
    }
    if (!ordenIds.length) return WMS.toast('error', 'Sin órdenes para asignar');

    WMS.spinner();
    try {
      if (!splitOn) {
        // Asignación simple o por categoría
        const r = await API.post('/picking/assign', {
          orden_ids:  ordenIds,
          auxiliar_id: parseInt(personalId),
          categorias: catIds, // Enviamos el array de categorías
          prioridad,
          observaciones: obs,
          separar_consolidado: sepCons
        });
        if (r.error) throw new Error(r.message);
        WMS.toast('success', `Líneas asignadas (${r.data?.asignadas || 0})`);
      } else {
        // Asignación dividida por pasillo
        const rows = document.querySelectorAll('.split-row');
        let totalCount = 0;
        for (const row of rows) {
          const auxSel = row.querySelector('.split-aux');
          const passSel = row.querySelector('.split-pass');
          if (auxSel?.value && passSel?.value) {
            // Dividir el string de pasillos (ej: "01, 02" -> ["01", "02"])
            const pasillos = passSel.value.split(',').map(p => p.trim()).filter(p => p);
            const r = await API.post('/picking/assign', {
              orden_ids:    ordenIds,
              auxiliar_id:  parseInt(auxSel.value),
              pasillos:     pasillos,
              categorias:   catIds,
              prioridad,
              observaciones: obs,
              separar_consolidado: sepCons
            });
            totalCount += (r.data?.asignadas || 0);
          }
        }
        WMS.toast('success', `Líneas divididas: ${totalCount}`);
      }

      WMS.closeModal('generic-modal');
      this.show_pedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error en asignación');
    }
  },

  // ── LEGACY: asignar orden individual ─────────────────────────────────────
  async asignar(id) {
    let personal = [];
    try {
      const rPers = await API.get('/param/personal', 'rol=Auxiliar&activo=1');
      personal = rPers.data || rPers || [];
    } catch(e) {}

    WMS.showModal('Asignar Orden #' + id, `
      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label class="form-label">Auxiliar <span class="required">*</span></label>
          <select id="asig-personal" class="form-control">
            <option value="">Seleccionar...</option>
            ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Prioridad</label>
          <select id="asig-prio" class="form-control">
            <option value="0">Normal</option><option value="1">Alta</option>
          </select>
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.picking.confirmarAsignacion(${id})">Asignar</button>`);
  },

  async confirmarAsignacion(id) {
    const personal_id = document.getElementById('asig-personal')?.value;
    if (!personal_id) { WMS.toast('warning', 'Seleccione un auxiliar'); return; }
    try {
      const r = await API.post('/picking/asignar-multiple', {
        orden_ids: [id],
        personal_id: parseInt(personal_id),
        prioridad: parseInt(document.getElementById('asig-prio')?.value || 0),
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Picking asignado'); WMS.closeModal('generic-modal'); this.show_pedidos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── ASIGNAR RUTA A PLANILLA ──────────────────────────────────────────────
  async asignarRutaPlanilla(planilla, ordenIds) {
    let rutas = [];
    try {
      const rr = await API.get('/param/rutas');
      rutas = (rr.data || rr || []).filter(r => r.activo);
    } catch(e) { console.error('Error cargando rutas', e); }

    WMS.showModal(`Asignar Ruta — Planilla ${planilla}`, `
      <div style="margin-bottom:12px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:13px;color:#1e40af;">
        <i class="fa-solid fa-route" style="margin-right:6px;"></i>
        Asigne una ruta a las <strong>${ordenIds.length}</strong> orden(es) de esta planilla.
      </div>
      <div class="form-group">
        <label class="form-label">Ruta <span class="required">*</span></label>
        ${rutas.length > 0 ? `
          <select id="ruta-select" class="form-control" onchange="document.getElementById('ruta-input').value=this.value">
            <option value="">Seleccionar ruta existente...</option>
            ${rutas.map(r => `<option value="${WMS.esc(r.nombre)}">${WMS.esc(r.nombre)}${r.comercial ? ' — ' + WMS.esc(r.comercial) : ''}</option>`).join('')}
          </select>
          <div style="text-align:center;font-size:11px;color:#94a3b8;margin:6px 0;">— o escriba una nueva —</div>
        ` : ''}
        <input type="text" id="ruta-input" class="form-control" placeholder="Nombre de la ruta..." style="font-weight:600;">
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.picking._confirmarRuta(${JSON.stringify(ordenIds)})">
         <i class="fa-solid fa-check"></i> Asignar Ruta
       </button>`);
  },

  async _confirmarRuta(ordenIds) {
    const ruta = document.getElementById('ruta-input')?.value?.trim();
    if (!ruta) return WMS.toast('warning', 'Ingrese un nombre de ruta');
    try {
      const r = await API.post('/picking/asignar-ruta', { orden_ids: ordenIds, ruta });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', r.data?.message || 'Ruta asignada');
      WMS.closeModal('generic-modal');
      this.show_pedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error asignando ruta');
    }
  },

  async completarPicking(id) {
    if (!confirm('¿Marcar este picking como completado?')) return;
    try {
      const r = await API.post('/picking/' + id + '/completar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Picking completado'); WMS.closeModal('generic-modal'); this.show_pedidos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async deletePicking(id) {
    if (!confirm('¿Eliminar esta orden de picking?')) return;
    try {
      const r = await API.delete('/picking/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Picking eliminado'); this.show_pedidos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async transferir(id) { WMS.toast('info', 'Función de transferencia próximamente'); },

  // ── IMPORTACIÓN MASIVA DE PEDIDOS (Archivo Plano) ────────────────────────
  _importPreviewData: null,

  async importarPedidos() {
    const CAMPOS_REQUERIDOS = [
      { key: 'numero_factura', label: 'Num Factura',  icon: 'fa-file-invoice', color: '#6366f1' },
      { key: 'cliente',        label: 'Cliente',      icon: 'fa-user',         color: '#0ea5e9' },
      { key: 'documento',      label: 'Documento',    icon: 'fa-id-card',      color: '#8b5cf6' },
      { key: 'direccion',      label: 'Dirección',    icon: 'fa-location-dot', color: '#f59e0b' },
      { key: 'planilla',       label: 'Planilla',     icon: 'fa-layer-group',  color: '#10b981' },
      { key: 'asesor',         label: 'Asesor',       icon: 'fa-user-tie',     color: '#ec4899' },
      { key: 'producto',       label: 'Producto (EAN)', icon: 'fa-barcode',    color: '#14b8a6' },
      { key: 'cantidad',       label: 'Cantidad',     icon: 'fa-cubes',        color: '#f97316' },
      { key: 'costo',          label: 'Costo',        icon: 'fa-dollar-sign',  color: '#22c55e' },
      { key: 'descuento',      label: 'Descuento %',  icon: 'fa-percent',      color: '#ef4444' },
    ];

    const camposGrid = CAMPOS_REQUERIDOS.map(c => `
      <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:${c.color}10;border:1px solid ${c.color}30;border-radius:8px;">
        <i class="fa-solid ${c.icon}" style="color:${c.color};font-size:14px;width:18px;text-align:center;"></i>
        <span style="font-size:12px;font-weight:600;color:#1e293b;">${c.label}</span>
      </div>
    `).join('');

    WMS.showModal('Importar Pedidos para Picking', `
      <div style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border:1px solid #bfdbfe;border-radius:10px;margin-bottom:14px;">
          <i class="fa-solid fa-file-lines" style="font-size:22px;color:#3b82f6;"></i>
          <div>
            <div style="font-weight:700;color:#1e40af;font-size:14px;">Importación Masiva — Archivo Plano</div>
            <div style="font-size:12px;color:#3b82f6;margin-top:2px;">Suba un archivo CSV o TXT separado por punto y coma (;) o coma (,)</div>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">
            <i class="fa-solid fa-diagram-project" style="margin-right:6px;color:#6366f1;"></i>Campos que se extraerán del archivo
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
            ${camposGrid}
          </div>
        </div>

        <div style="padding:10px 14px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;margin-bottom:14px;">
          <i class="fa-solid fa-lightbulb" style="margin-right:6px;color:#f59e0b;"></i>
          <strong>Nota:</strong> El archivo puede contener más columnas — el sistema solo extraerá los campos listados arriba.
          Los pedidos se agrupan por <strong>Numero Factura</strong> para crear las órdenes de picking.
        </div>

        <div class="form-group">
          <label class="form-label" style="font-weight:700;">
            <i class="fa-solid fa-cloud-arrow-up" style="color:#3b82f6;margin-right:6px;"></i>Archivo CSV / TXT
            <span class="required">*</span>
          </label>
          <div id="pick-dropzone" style="border:2px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;
               background:#f8fafc;" onclick="document.getElementById('pick-csv-file').click()"
               ondragover="event.preventDefault();this.style.borderColor='#3b82f6';this.style.background='#eff6ff';"
               ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';"
               ondrop="event.preventDefault();this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';document.getElementById('pick-csv-file').files=event.dataTransfer.files;WMS_MODULES.picking._onFileSelect();">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size:32px;color:#94a3b8;margin-bottom:8px;display:block;"></i>
            <div style="font-size:13px;font-weight:600;color:#475569;">Haga clic o arrastre el archivo aquí</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Formatos: .csv, .txt — Separadores: ; o ,</div>
          </div>
          <input type="file" id="pick-csv-file" style="display:none;" accept=".csv,.txt" onchange="WMS_MODULES.picking._onFileSelect()">
        </div>

        <div id="pick-file-info" style="display:none;margin-top:10px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-file-csv" style="font-size:20px;color:#16a34a;"></i>
            <div style="flex:1;">
              <div id="pick-file-name" style="font-size:13px;font-weight:600;color:#166534;"></div>
              <div id="pick-file-meta" style="font-size:11px;color:#4ade80;"></div>
            </div>
            <button class="btn btn-xs btn-secondary" onclick="document.getElementById('pick-csv-file').value='';document.getElementById('pick-file-info').style.display='none';document.getElementById('pick-preview-section').style.display='none';document.getElementById('pick-dropzone').style.display='block';">
              <i class="fa-solid fa-xmark"></i>
            </button>
          </div>
        </div>
      </div>

      <div id="pick-preview-section" style="display:none;">
        <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">
          <i class="fa-solid fa-table" style="margin-right:6px;color:#0ea5e9;"></i>Vista Previa (primeras 5 filas)
        </div>
        <div id="pick-preview-table" style="max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;"></div>
        <div id="pick-preview-summary" style="margin-top:10px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;color:#1e40af;"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <a href="/WMS_PROORIENTE/public/api/picking/template?token=${encodeURIComponent(localStorage.getItem('wms_token'))}" target="_blank" class="btn btn-secondary" style="gap:6px;"><i class="fa-solid fa-download"></i> Plantilla</a>
       <button class="btn btn-primary" id="btn-importar-pick" onclick="WMS_MODULES.picking.uploadCsv()" disabled><i class="fa-solid fa-upload"></i> Importar Pedidos</button>`,
      { width: '680px' }
    );
  },

  _onFileSelect() {
    const fileInput = document.getElementById('pick-csv-file');
    const file = fileInput?.files[0];
    if (!file) return;

    document.getElementById('pick-dropzone').style.display = 'none';
    document.getElementById('pick-file-info').style.display = 'block';
    document.getElementById('pick-file-name').textContent = file.name;
    document.getElementById('pick-file-meta').textContent = `${(file.size/1024).toFixed(1)} KB — ${file.type || 'texto plano'}`;

    // Parse preview
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const lines = text.split(/\r?\n/).filter(l => l.trim());
      if (lines.length < 2) {
        WMS.toast('warning', 'El archivo no contiene datos suficientes');
        return;
      }

      const sep = lines[0].includes(';') ? ';' : ',';
      const headers = lines[0].split(sep).map(h => h.trim().replace(/^\uFEFF/, ''));

      // Auto-detect column mappings (case-insensitive, partial match)
      const MAPEO = {
        numero_factura: ['numero factura', 'num factura', 'factura', 'nro factura', 'no. factura'],
        cliente:        ['cliente', 'nombre cliente', 'razon social'],
        documento:      ['documento', 'nit', 'cedula', 'cc', 'rut'],
        direccion:      ['direccion', 'dirección', 'dir', 'address'],
        planilla:       ['planilla', 'planilla numero', 'num planilla', 'nro planilla'],
        asesor:         ['asesor', 'comercial', 'vendedor', 'asesor comercial'],
        producto:       ['barras', 'ean', 'codigo barras', 'codigo_barras', 'producto', 'codigo producto'],
        cantidad:       ['cantidad', 'cant', 'qty', 'unidades'],
        costo:          ['costo', 'precio', 'valor', 'cost', 'precio unitario'],
        descuento:      ['descuento', 'desc', 'descto', 'discount', 'dcto'],
      };

      const colMap = {};
      for (const [field, aliases] of Object.entries(MAPEO)) {
        const idx = headers.findIndex(h => {
          const hl = h.toLowerCase().trim();
          return aliases.some(a => hl === a || hl.includes(a));
        });
        if (idx >= 0) colMap[field] = idx;
      }

      // Count mapped fields
      const mapped = Object.keys(colMap).length;
      const total = Object.keys(MAPEO).length;

      // Parse preview rows
      const previewRows = lines.slice(1, 6).map(l => l.split(sep));

      // Count total data rows and unique facturas
      const allDataRows = lines.slice(1);
      const facturas = new Set();
      allDataRows.forEach(l => {
        const cols = l.split(sep);
        if (colMap.numero_factura !== undefined) facturas.add((cols[colMap.numero_factura] || '').trim());
      });

      // Build preview table showing only the mapped columns
      const mappedFields = Object.entries(colMap);
      const fieldLabels = {
        numero_factura: 'Num Factura', cliente: 'Cliente', documento: 'Documento',
        direccion: 'Dirección', planilla: 'Planilla', asesor: 'Asesor',
        producto: 'Producto (EAN)', cantidad: 'Cantidad', costo: 'Costo', descuento: 'Descuento'
      };

      let tableHtml = `<table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead style="background:#f1f5f9;position:sticky;top:0;">
          <tr>${mappedFields.map(([f]) => `<th style="padding:6px 8px;text-align:left;font-weight:700;color:#334155;white-space:nowrap;border-bottom:2px solid #e2e8f0;">${fieldLabels[f] || f}</th>`).join('')}</tr>
        </thead><tbody>`;

      previewRows.forEach((cols, i) => {
        tableHtml += `<tr style="border-bottom:1px solid #f1f5f9;${i % 2 ? 'background:#fafbfc;' : ''}">`;
        mappedFields.forEach(([f, idx]) => {
          let val = (cols[idx] || '').trim();
          if (val.length > 35) val = val.substring(0, 35) + '…';
          const style = f === 'cantidad' || f === 'costo' || f === 'descuento'
            ? 'text-align:right;font-weight:600;'
            : '';
          tableHtml += `<td style="padding:5px 8px;${style}">${WMS.esc(val)}</td>`;
        });
        tableHtml += '</tr>';
      });
      tableHtml += '</tbody></table>';

      document.getElementById('pick-preview-table').innerHTML = tableHtml;
      document.getElementById('pick-preview-summary').innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
          <span><i class="fa-solid fa-check-circle" style="color:#10b981;margin-right:4px;"></i><strong>${mapped}</strong> / ${total} campos detectados</span>
          <span><i class="fa-solid fa-file-lines" style="color:#3b82f6;margin-right:4px;"></i><strong>${allDataRows.length}</strong> líneas de datos</span>
          <span><i class="fa-solid fa-file-invoice" style="color:#6366f1;margin-right:4px;"></i><strong>${facturas.size}</strong> facturas únicas (= órdenes picking)</span>
          <span><i class="fa-solid fa-grip-lines" style="color:#94a3b8;margin-right:4px;"></i>Separador: <code>${sep === ';' ? 'punto y coma (;)' : 'coma (,)'}</code></span>
        </div>
        ${mapped < 5 ? `<div style="margin-top:8px;padding:6px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#dc2626;font-size:11px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>Advertencia: Se detectaron pocos campos. Verifique que el archivo tenga los encabezados correctos.</div>` : ''}
      `;

      document.getElementById('pick-preview-section').style.display = 'block';
      document.getElementById('btn-importar-pick').disabled = false;

      this._importPreviewData = { colMap, sep, totalRows: allDataRows.length, facturas: facturas.size };
    };
    reader.readAsText(file, 'UTF-8');
  },

  async uploadCsv() {
    const file = document.getElementById('pick-csv-file')?.files[0];
    if (!file) { WMS.toast('warning', 'Seleccione un archivo'); return; }

    const btn = document.getElementById('btn-importar-pick');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importando…'; }

    const fd = new FormData();
    fd.append('file', file);

    try {
      const r = await fetch('/WMS_PROORIENTE/public/api/picking/importar', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + localStorage.getItem('wms_token') },
        body: fd
      });
      const j = await r.json();
      if (j.error) {
        WMS.toast('error', j.message || 'Error en importación');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Pedidos'; }
      } else {
        const data = j.data || {};
        const au = j.audit || {};
        const arch = au.archivo || {};
        const sys  = au.sistema || {};
        const diff = au.diferencias || {};
        const diffColor = v => v > 0 ? 'color:#dc2626;font-weight:700;' : (v < 0 ? 'color:#f59e0b;font-weight:700;' : 'color:#10b981;font-weight:700;');
        const fmtVal = v => typeof v === 'number' ? v.toLocaleString('es-CO') : (v || '0');

        await Swal.fire({
          icon: (diff.lineas > 0 || diff.cantidad > 0) ? 'warning' : 'success',
          title: 'Resultado de Importación',
          width: 580,
          html: `
            <div style="text-align:left;font-size:13px;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
                <div style="padding:10px;background:#f0fdf4;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#16a34a;">${j.importadas || 0}</div>
                  <div style="font-size:11px;color:#4ade80;">Órdenes Creadas</div>
                </div>
                <div style="padding:10px;background:#eff6ff;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#2563eb;">${data.total_lineas || 0}</div>
                  <div style="font-size:11px;color:#60a5fa;">Líneas Procesadas</div>
                </div>
              </div>

              <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;">📊 Auditoría de Importación</div>
              <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <thead>
                  <tr style="background:#f1f5f9;">
                    <th style="padding:6px 10px;text-align:left;">Concepto</th>
                    <th style="padding:6px 10px;text-align:right;">Archivo</th>
                    <th style="padding:6px 10px;text-align:right;">Sistema</th>
                    <th style="padding:6px 10px;text-align:right;">Diferencia</th>
                  </tr>
                </thead>
                <tbody>
                  <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Líneas de datos</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.lineas_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.lineas_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.lineas)}">${diff.lineas > 0 ? '+' : ''}${fmtVal(diff.lineas)}</td></tr>
                  <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Clientes únicos</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.clientes_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.clientes_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.clientes)}">${diff.clientes > 0 ? '+' : ''}${fmtVal(diff.clientes)}</td></tr>
                  <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Cantidad total</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.cantidad_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.cantidad_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.cantidad)}">${diff.cantidad > 0 ? '+' : ''}${fmtVal(diff.cantidad)}</td></tr>
                  <tr><td style="padding:5px 10px;">Valor total</td><td style="padding:5px 10px;text-align:right;">$${fmtVal(arch.valor_archivo)}</td><td style="padding:5px 10px;text-align:right;">$${fmtVal(sys.valor_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.valor)}">${diff.valor > 0 ? '+$' : '-$'}${fmtVal(Math.abs(diff.valor))}</td></tr>
                </tbody>
              </table>

              ${diff.lineas > 0 ? `<div style="margin-top:10px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;color:#dc2626;font-size:11px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i><strong>${diff.lineas} línea(s)</strong> del archivo no se cargaron. Posibles causas: productos no encontrados en el catálogo.</div>` : `<div style="margin-top:10px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;color:#166534;font-size:11px;"><i class="fa-solid fa-check-circle" style="margin-right:4px;"></i>Todas las líneas fueron importadas correctamente.</div>`}

              ${(data.errores||[]).length ? `<div style="margin-top:8px;padding:8px;background:#fef2f2;border-radius:6px;color:#dc2626;font-size:11px;"><strong>Errores (${data.errores.length}):</strong><br>${data.errores.slice(0,5).join('<br>')}</div>` : ''}
              ${(data.productos_no_encontrados||0) > 0 ? `<div style="margin-top:8px;padding:8px;background:#fefce8;border-radius:6px;color:#92400e;font-size:11px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>${data.productos_no_encontrados} producto(s) no encontrado(s) en el catálogo</div>` : ''}
            </div>`,
          confirmButtonText: 'Aceptar',
          confirmButtonColor: '#3b82f6',
        });

        WMS.closeModal('generic-modal');
        this.show_pedidos();
      }
    } catch(e) {
      WMS.toast('error', 'Error de conexión al importar');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Pedidos'; }
    }
  },

  // ── ASIGNACIÓN DE PICKING ─────────────────────────────────────────────────
  _asigData: { grupos:[], staff:[] },

  async show_asignacion(silent = false) {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.picking.show_asignacion()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
      <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(0,179,0,.15);color:#007000;padding:4px 10px;border-radius:12px;font-size:.72rem;font-weight:700;margin-left:8px">
        <span class="pick-live-dot"></span> Auto 30s
      </span>`);
    if (!silent) WMS.spinner();
    try {
      const [picking, personal] = await Promise.all([
        // SOLO planillas activas: Creado o Asignado (excluir Completado, Cerrado, Cancelado)
        API.get('/picking', 'estado=Pendiente,Asignado&limit=500'),
        API.get('/param/personal', 'activo=1&limit=100'),
      ]);
      const rawItems = picking.data || picking || [];
      const staff    = personal.data || personal || [];

      // Filtrar explícitamente cualquier planilla cerrada/completada que pueda venir
      const ESTADOS_EXCLUIDOS = ['Completado','Completada','Cerrado','Cerrada','Cancelado','Cancelada'];
      const items = rawItems.filter(p => !ESTADOS_EXCLUIDOS.includes(p.estado));

      const grupos = this._agruparPorPlanilla(items);
      this._asigData = { grupos, staff };

      /* Calcular carga actual por auxiliar */
      const cargaMap = {};
      grupos.forEach(g => {
        if (g.auxiliar) {
          cargaMap[g.auxiliar] = (cargaMap[g.auxiliar]||0) + 1;
        }
      });

      WMS.setContent(`
<div class="pro-dashboard" style="padding:0">

  <!-- Action bar -->
  <div class="asig-action-bar">
    <input class="pro-table-search" style="max-width:260px" placeholder="Buscar planilla, cliente, ruta…"
           oninput="WMS_MODULES.picking._filterAsig(this.value)">
    <select class="pro-table-filter-select" onchange="WMS_MODULES.picking._filterAsigEstado(this.value)">
      <option value="">Todos los estados</option>
      <option value="Creado">Sin asignar</option>
      <option value="Asignado">Asignados</option>
    </select>
    <div style="flex:1"></div>
    <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking._selAllPlanillas(true)">
      <i class="fa-solid fa-check-double"></i> Sel. Todo
    </button>
    <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking._selAllPlanillas(false)">
      <i class="fa-solid fa-square-minus"></i> Limpiar
    </button>
    <span id="asig-sel-count" style="font-size:.78rem;color:#0070f2;font-weight:700;padding:0 8px"></span>
  </div>

  <!-- Layout: tabla + panel auxiliares -->
  <div style="display:grid;grid-template-columns:1fr 300px;gap:0;height:calc(100vh - 106px - 56px)">

    <!-- Tabla de planillas -->
    <div style="overflow-y:auto;border-right:1px solid #e2e8f0">
      <table class="pro-table" id="asig-table" style="border-radius:0">
        <thead style="position:sticky;top:0;z-index:10">
          <tr>
            <th style="width:36px;text-align:center">
              <input type="checkbox" id="sel-all-pick" onchange="WMS_MODULES.picking._selAllPlanillas(this.checked)"
                     style="accent-color:#0070f2;width:15px;height:15px">
            </th>
            <th>Planilla</th>
            <th>Cliente</th>
            <th>Ruta</th>
            <th style="text-align:center">Líneas</th>
            <th style="text-align:center">Pend.</th>
            <th style="min-width:130px">Progreso</th>
            <th>Auxiliar</th>
            <th>Estado</th>
            <th style="min-width:140px;text-align:center">Acciones</th>
          </tr>
        </thead>
        <tbody id="asig-tbody">
          ${grupos.length ? grupos.map(g => {
            const hechas = g.total_lineas - g.lineas_pendientes;
            const pct    = g.total_lineas>0 ? Math.round((hechas/g.total_lineas)*100) : 0;
            const fillCls= pct===100?'green':pct>=60?'':'amber';
            const stCls  = g.estado==='Asignado'?'warn':'info';
            return `<tr data-plan="${WMS.esc(g.planilla)}" data-estado="${WMS.esc(g.estado)}"
                        data-ids='${JSON.stringify((g.ordenes||[]).map(o=>o.id))}'
                        onclick="WMS_MODULES.picking._toggleRowSel(this)"
                        style="cursor:pointer">
              <td style="text-align:center" onclick="event.stopPropagation()">
                <input type="checkbox" class="plan-sel"
                       value="${WMS.esc(g.planilla)}"
                       data-ids='${JSON.stringify((g.ordenes||[]).map(o=>o.id))}'
                       onchange="WMS_MODULES.picking._onRowCheck(this)"
                       style="accent-color:#0070f2;width:15px;height:15px">
              </td>
              <td><span class="pro-badge info">${WMS.esc(g.planilla)}</span></td>
              <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="${WMS.esc(g.cliente)}">${WMS.esc(g.cliente||'–')}</td>
              <td class="muted">${WMS.esc(g.ruta||'–')}</td>
              <td style="text-align:center;font-weight:700">${g.total_lineas}</td>
              <td style="text-align:center">
                ${g.lineas_pendientes>0
                  ? `<span class="pro-badge alert">${g.lineas_pendientes}</span>`
                  : `<span class="pro-badge ok">✓ 0</span>`}
              </td>
              <td>
                <div class="pro-progress-wrap">
                  <div class="pro-progress-bar-bg">
                    <div class="pro-progress-bar-fill ${fillCls}" style="width:${pct}%"></div>
                  </div>
                  <span class="pro-progress-label">${pct}%</span>
                </div>
              </td>
              <td>
                ${g.auxiliar
                  ? `<div style="display:flex;align-items:center;gap:6px">
                       <div style="width:26px;height:26px;border-radius:50%;background:var(--pro-grad-blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;flex-shrink:0">${(g.auxiliar||'?').substring(0,2).toUpperCase()}</div>
                       <span style="font-size:.78rem;font-weight:600">${WMS.esc(g.auxiliar)}</span>
                     </div>`
                  : `<span class="muted" style="font-size:.75rem"><i class="fa-solid fa-circle-minus" style="color:#e8a000;margin-right:4px"></i>Sin asignar</span>`}
              </td>
              <td><span class="pro-badge ${stCls}">${WMS.esc(g.estado)}</span></td>
              <td style="text-align:center" onclick="event.stopPropagation()">
                <div style="display:flex;gap:4px;justify-content:center">
                  <button class="btn btn-xs btn-secondary" title="Ver detalle"
                          onclick="WMS_MODULES.picking._verDetallePlanilla('${WMS.esc(g.planilla)}')">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="btn btn-xs btn-primary" title="Asignar auxiliar"
                          onclick="WMS_MODULES.picking._mostrarAsignarModal('${WMS.esc(g.planilla)}',${JSON.stringify((g.ordenes||[]).map(o=>o.id))})">
                    <i class="fa-solid fa-user-plus"></i>
                  </button>
                  <button class="btn btn-xs btn-danger" title="Eliminar planilla"
                          onclick="WMS_MODULES.picking._eliminarPlanilla('${WMS.esc(g.planilla)}',${JSON.stringify((g.ordenes||[]).map(o=>o.id))})">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>`;
          }).join('') : '<tr><td colspan="10" class="muted" style="text-align:center;padding:32px"><i class="fa-solid fa-circle-check" style="color:#00b300;font-size:1.4rem;display:block;margin-bottom:8px"></i>Sin planillas pendientes de asignación</td></tr>'}
        </tbody>
      </table>
    </div>

    <!-- Panel auxiliares (sticky) -->
    <div style="overflow-y:auto;background:#f8faff;padding:16px;display:flex;flex-direction:column;gap:10px">
      <div class="pro-section-title" style="margin-bottom:4px">
        <span style="margin-left:12px;font-size:.82rem"><i class="fa-solid fa-users" style="margin-right:6px;color:#0070f2"></i>Auxiliares Disponibles</span>
      </div>
      <div style="margin-bottom:6px;background:#fff;padding:8px;border-radius:6px;border:1px solid #e2e8f0;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.75rem;margin:0;font-weight:600;color:#1e3a5f;">
          <input type="checkbox" id="asig-sel-solo-alm" onchange="window._asigSelSoloAlm = this.checked">
          <span><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Separar Consolidado (Solo Almacen.)</span>
        </label>
      </div>
      <p style="font-size:.72rem;color:#6b7a99;margin-bottom:6px">
        <i class="fa-solid fa-hand-pointer" style="margin-right:4px"></i>
        Selecciona planillas y haz clic en un auxiliar para asignar
      </p>
      ${staff.map(p => {
        const carga = cargaMap[p.nombre||''] || cargaMap[p.id] || 0;
        return `
        <div class="asig-aux-card" id="aux-card-${p.id}"
             onclick="WMS_MODULES.picking._asignarSeleccionAPersonal(${p.id},'${WMS.esc(p.nombre||'')}')">
          <div class="asig-aux-avatar">${(p.nombre||'??').substring(0,2).toUpperCase()}</div>
          <div style="flex:1;min-width:0">
            <div class="asig-aux-name">${WMS.esc(p.nombre||'')}</div>
            <div class="asig-aux-role">${WMS.esc(p.rol||'Auxiliar')}</div>
            <span class="asig-aux-load">${carga} planilla${carga===1?'':'s'} activa${carga===1?'':'s'}</span>
          </div>
          <i class="fa-solid fa-user-plus" style="color:#0070f2;font-size:.85rem"></i>
        </div>`;
      }).join('') || '<div class="pro-empty-state"><div class="icon">👥</div><p>Sin auxiliares activos</p></div>'}
    </div>

  </div>
</div>`);

    } catch(e) {
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>');
      console.error(e);
    }
  },

  _toggleRowSel(tr) {
    const cb = tr.querySelector('.plan-sel');
    if (cb) { cb.checked = !cb.checked; this._onRowCheck(cb); }
  },

  _onRowCheck(cb) {
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('asig-row-selected', cb.checked);
    // Actualizar contador
    const cnt = document.querySelectorAll('.plan-sel:checked').length;
    const el  = document.getElementById('asig-sel-count');
    if (el) el.textContent = cnt > 0 ? `${cnt} seleccionada${cnt>1?'s':''}` : '';
  },

  _selAllPlanillas(checked) {
    document.querySelectorAll('.plan-sel').forEach(cb => {
      cb.checked = checked;
      const tr = cb.closest('tr');
      if (tr) tr.classList.toggle('asig-row-selected', checked);
    });
    const selAll = document.getElementById('sel-all-pick');
    if (selAll) selAll.checked = checked;
    const cnt = checked ? document.querySelectorAll('.plan-sel').length : 0;
    const el  = document.getElementById('asig-sel-count');
    if (el) el.textContent = cnt > 0 ? `${cnt} seleccionada${cnt>1?'s':''}` : '';
  },

  _filterAsig(q) {
    const rows = Array.from(document.querySelectorAll('#asig-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !q || tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
  },

  _filterAsigEstado(val) {
    const rows = Array.from(document.querySelectorAll('#asig-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !val || tr.dataset.estado === val ? '' : 'none';
    });
  },

  _verDetallePlanilla(planilla) {
    const g = this._asigData.grupos.find(x => x.planilla === planilla);
    if (!g) return;
    const rows = (g.ordenes||[]).map(o => `
      <tr>
        <td><span class="pro-badge info">${WMS.esc(o.id||'–')}</span></td>
        <td>${WMS.esc(o.codigo_producto||o.ean||'–')}</td>
        <td>${WMS.esc(o.descripcion||o.producto||'–')}</td>
        <td style="text-align:center;font-weight:700">${o.cantidad||0}</td>
        <td style="text-align:center">${o.lineas_pendientes>0?`<span class="pro-badge alert">${o.lineas_pendientes}</span>`:'<span class="pro-badge ok">0</span>'}</td>
        <td><span class="pro-badge ${o.estado==='Completado'?'ok':o.estado==='Asignado'?'warn':'info'}">${WMS.esc(o.estado||'–')}</span></td>
      </tr>`).join('') || '<tr><td colspan="6" class="muted" style="text-align:center;padding:16px">Sin líneas</td></tr>';
    WMS.showModal(`Detalle Planilla ${planilla}`, `
      <div class="pro-mini-kpi-row" style="margin-bottom:16px">
        <div class="pro-mini-kpi">
          <div class="pro-mini-kpi-icon"><i class="fa-solid fa-layer-group"></i></div>
          <div><div class="pro-mini-kpi-value">${g.total_lineas}</div><div class="pro-mini-kpi-label">Total Líneas</div></div>
        </div>
        <div class="pro-mini-kpi">
          <div class="pro-mini-kpi-icon" style="background:rgba(232,160,0,.1);color:#e8a000"><i class="fa-solid fa-hourglass-half"></i></div>
          <div><div class="pro-mini-kpi-value">${g.lineas_pendientes}</div><div class="pro-mini-kpi-label">Pendientes</div></div>
        </div>
        <div class="pro-mini-kpi">
          <div class="pro-mini-kpi-icon" style="background:rgba(0,179,0,.1);color:#00b300"><i class="fa-solid fa-check"></i></div>
          <div><div class="pro-mini-kpi-value">${g.total_lineas-g.lineas_pendientes}</div><div class="pro-mini-kpi-label">Completadas</div></div>
        </div>
      </div>
      <div class="pro-table-wrap" style="max-height:340px;overflow-y:auto">
        <table class="pro-table">
          <thead><tr><th>#Ord</th><th>Código</th><th>Producto</th><th style="text-align:center">Cant.</th><th style="text-align:center">Pend.</th><th>Estado</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`);
  },

  _mostrarAsignarModal(planilla, ordenIds) {
    const staff = this._asigData.staff;
    const cards = staff.map(p => `
      <div class="asig-aux-card" onclick="WMS_MODULES.picking._asignarPlanillaAPersonal('${WMS.esc(planilla)}',${JSON.stringify(ordenIds)},${p.id},'${WMS.esc(p.nombre||'')}', window._asigModalSoloAlm);WMS.closeModal('generic-modal')">
        <div class="asig-aux-avatar">${(p.nombre||'??').substring(0,2).toUpperCase()}</div>
        <div>
          <div class="asig-aux-name">${WMS.esc(p.nombre||'')}</div>
          <div class="asig-aux-role">${WMS.esc(p.rol||'Auxiliar')}</div>
        </div>
        <i class="fa-solid fa-arrow-right" style="color:#0070f2;margin-left:auto"></i>
      </div>`).join('') || '<div class="pro-empty-state"><div class="icon">👥</div><p>Sin auxiliares</p></div>';
    
    window._asigModalSoloAlm = false; // reset state
    WMS.showModal(`Asignar Planilla ${planilla}`, `
      <p style="font-size:.82rem;color:#6b7a99;margin-bottom:8px">Selecciona el auxiliar que ejecutará esta planilla:</p>
      <div style="margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:6px;border:1px solid #e2e8f0;">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.8rem;margin:0;font-weight:600;color:#1e3a5f;">
          <input type="checkbox" onchange="window._asigModalSoloAlm = this.checked">
          <span><i class="fa-solid fa-boxes-stacked" style="color:#0ea5e9;"></i> Separar Consolidado (Solo Almacen.)</span>
        </label>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;max-height:360px;overflow-y:auto">${cards}</div>`);
  },

  async _asignarPlanillaAPersonal(planilla, ordenIds, personalId, nombre, soloAlm = false) {
    try {
      const payload = { orden_ids: ordenIds, personal_id: personalId };
      if (soloAlm) payload.separar_consolidado = true;
      const r = await API.post('/picking/asignar-multiple', payload);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', `Planilla ${planilla} asignada a ${nombre}`); this.show_asignacion(); }
    } catch(e) { WMS.toast('error', 'Error al asignar'); }
  },

  async _eliminarPlanilla(planilla, ordenIds) {
    WMS.confirm('Eliminar Planilla', `¿Está seguro de eliminar la planilla <strong>${planilla}</strong>? Esta acción no se puede deshacer.`, async () => {
      try {
        // Intentar eliminar cada orden de la planilla
        const promises = ordenIds.map(id => API.delete('/picking/' + id).catch(() => {}));
        await Promise.all(promises);
        WMS.toast('success', `Planilla ${planilla} eliminada`);
        this.show_asignacion();
      } catch(e) { WMS.toast('error', 'Error al eliminar'); }
      });
  },

  async _asignarSeleccionAPersonal(personalId, nombre) {
    const cbs = Array.from(document.querySelectorAll('.plan-sel:checked'));
    if (!cbs.length) { WMS.toast('warning', 'Seleccione al menos una planilla'); return; }
    const ids = [];
    cbs.forEach(cb => { try { JSON.parse(cb.dataset.ids||'[]').forEach(id => ids.push(id)); } catch(e){} });
    const planCount = cbs.length;

    WMS.confirm('Asignar Planillas', `¿Asignar <strong>${planCount} planilla(s)</strong> a <strong>${nombre}</strong>?`, async () => {
        try {
        const payload = { orden_ids: ids, personal_id: personalId };
        if (window._asigSelSoloAlm) payload.separar_consolidado = true;
        const r = await API.post('/picking/asignar-multiple', payload);
        if (r.error) WMS.toast('error', r.message);
        else { WMS.toast('success', `${planCount} planilla(s) asignadas a ${nombre}`); this.show_asignacion(); }
      } catch(e) { WMS.toast('error', 'Error'); }
      });
  },

  // ── FALTANTES ─────────────────────────────────────────────────────────────
  async show_faltantes() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.show_faltantes()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const [faltantes, reabast] = await Promise.all([
        API.get('/picking/novedades-stock'),
        API.get('/picking/reabastecimientos'),
      ]);
      const falt = faltantes.data || faltantes || [];
      const rea  = reabast.data  || reabast  || [];
      const self = this;
      WMS.setContent(`
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div class="card">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> Faltantes de Stock (${falt.length})</span></div>
            <div class="table-container">
              <table class="data-table">
                <thead><tr><th>Producto</th><th style="text-align:center;">Pedido</th><th style="text-align:center;">Disponible</th><th style="text-align:center;">Déficit</th><th>Reserva</th><th>Acción</th></tr></thead>
                <tbody>${falt.map(f => {
                  const ped   = f.cantidad_pedida      || 0;
                  const disp  = f.cantidad_disponible  || 0;
                  const reser = f.cantidad_en_reserva  || 0;
                  const def   = Math.abs(ped - disp);
                  return `<tr>
                    <td>
                      <div style="font-weight:700;">${WMS.esc(f.producto||'-')}</div>
                      <div style="font-size:10px;color:#64748b;">Planilla: ${WMS.esc(f.numero_planilla||'-')}</div>
                    </td>
                    <td style="text-align:center;">${WMS.formatNum(ped)}</td>
                    <td style="text-align:center;">${WMS.formatNum(disp)}</td>
                    <td style="text-align:center;"><span class="badge badge-danger">${WMS.formatNum(def)}</span></td>
                    <td style="text-align:center;">
                        <span class="badge ${reser > 0 ? 'badge-success' : 'badge-secondary'}">${WMS.formatNum(reser)}</span>
                    </td>
                    <td style="text-align:center;">
                        ${reser > 0 ? `
                        <button class="btn btn-xs btn-primary" onclick="WMS_MODULES.picking.autoReabastecer(${f.producto_id})" title="Sugerir Reabastecimiento">
                            <i class="fa-solid fa-truck-moving"></i> Reabastecer
                        </button>` : '<small style="color:#94a3b8;font-size:10px;">Sin reserva</small>'}
                    </td>
                  </tr>`;
                }).join('') || '<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin faltantes críticos</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-rotate" style="color:#3b82f6;"></i> Tareas de Reabastecimiento (${rea.length})</span></div>
            <div class="table-container">
              <table class="data-table">
                <thead><tr><th>Producto</th><th>Desde</th><th>Hacia</th><th>Cant.</th><th>Estado</th><th></th></tr></thead>
                <tbody>${rea.map(t => `<tr>
                  <td style="font-size:.8rem;font-weight:600;">${WMS.esc(t.producto||'-')}</td>
                  <td><code>${WMS.esc(t.ubicacion_origen||'-')}</code></td>
                  <td><code>${WMS.esc(t.ubicacion_destino||'-')}</code></td>
                  <td class="text-center">${WMS.formatNum(t.cantidad||0)}</td>
                  <td><span class="badge ${t.completada?'badge-success':'badge-warning'}">${t.completada?'Completado':'Pendiente'}</span></td>
                  <td>${!t.completada?`<button class="btn btn-xs btn-success" onclick="WMS_MODULES.picking.completarReabast(${t.id})"><i class="fa-solid fa-check"></i></button>`:''}</td>
                </tr>`).join('') || '<tr><td colspan="6" class="table-empty">Sin tareas activas</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error</p></div>'); }
  },

  async completarReabast(id) {
    try {
      const r = await API.post('/picking/reabast/' + id + '/completar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Reabastecimiento completado'); this.show_faltantes(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── DASHBOARD PICKING  ·  Command Center Logístico ───────────────────────
  _pickingChart: null,
  _pickDonutChart: null,
  _timerInterval: null,

  async show_dashboard(silent = false) {
    const isStandalone = document.body.classList.contains('standalone-mode');
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.picking.show_dashboard()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
      <span style="font-size:.72rem;color:rgba(255,255,255,.5);margin-left:8px">
        <span class="pick-live-dot"></span> Monitoreo en vivo ${isStandalone ? '(Auto 5 min)' : ''}
      </span>
      ${!isStandalone ? `
      <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.picking._openTVDashboard()" style="margin-left:10px;font-weight:700;">
        <i class="fa-solid fa-tv"></i> MODO TV
      </button>` : ''}
      <button class="btn btn-secondary btn-sm" onclick="WMS.toggleFullscreen()" style="margin-left:5px;">
        <i class="fa-solid fa-expand"></i>
      </button>
    `);
    
    if (!silent) { 
      WMS.spinner(); 
      this._startTimers(); 
      
      // Si es standalone (TV), programar refresco automático cada 5 minutos (300,000 ms)
      if (isStandalone) {
        if (this._tvRefreshInterval) clearInterval(this._tvRefreshInterval);
        this._tvRefreshInterval = setInterval(() => {
          this.show_dashboard(true);
        }, 300000);
      }
    }
    try {
      const f_inicio = document.getElementById('dash-f-ini')?.value  || WMS.getToday();
      const f_fin    = document.getElementById('dash-f-fin')?.value  || WMS.getToday();
      const st_fil   = document.getElementById('dash-f-st')?.value   || '';
      const plan_fil = document.getElementById('dash-f-plan')?.value || '';

      const query = `fecha_inicio=${f_inicio}&fecha_fin=${f_fin}&estado=${st_fil}&planilla=${plan_fil}`;
      const [dash, allPicking] = await Promise.all([
        API.get('/picking/dashboard', query),
        API.get('/picking', query + '&limit=1000'),
      ]);

      const d   = dash.data || dash || {};
      const all = allPicking.data || allPicking || [];
      const grupos = this._agruparPorPlanilla(all);

      // KPIs
      const totalL  = all.reduce((a,p) => a + parseInt(p.total_lineas||0), 0);
      const pendL   = all.reduce((a,p) => a + parseInt(p.lineas_pendientes||0), 0);
      const okL     = totalL - pendL;
      const pctG    = totalL > 0 ? Math.round((okL / totalL) * 100) : 100;
      const stCount = { Pendiente: d.pendientes||0, EnProceso: d.en_proceso||0, Completado: d.completadas||0 };

      const tableRows = grupos.map(g => this._renderPlanillaRow(g, { isDashboard: true })).join('');

      WMS.setContent(`
<div class="pro-dashboard">
  <div class="filter-bar dashboard-filters" style="background:#fff;padding:16px;border-radius:12px;margin-bottom:20px;box-shadow:0 4px 20px rgba(0,0,0,.04);display:flex;flex-wrap:wrap;gap:18px;align-items:center;border:1px solid #f1f5f9;">
    <div class="filter-group"><label>PERIODO</label>
      <div style="display:flex;gap:5px;">
        <input type="date" id="dash-f-ini" class="form-control" style="width:130px;" value="${f_inicio}" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <input type="date" id="dash-f-fin" class="form-control" style="width:130px;" value="${f_fin}" onchange="WMS_MODULES.picking.show_dashboard(true)">
      </div>
    </div>
    <div class="filter-group" style="flex:1;"><label>BÚSQUEDA OPERACIONAL</label>
      <div class="search-bar" style="max-width:400px;margin:0;"><i class="fa-solid fa-search"></i>
        <input id="dash-f-plan" value="${plan_fil}" placeholder="Planilla, Ruta, Auxiliar..." onkeypress="if(event.key==='Enter')WMS_MODULES.picking.show_dashboard(true)">
      </div>
    </div>
    <div class="filter-group"><label>ESTADO</label>
      <select id="dash-f-st" class="form-control" style="width:150px;" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <option value="">TODOS</option>
        <option value="Pendiente" ${st_fil==='Pendiente'?'selected':''}>PENDIENTE</option>
        <option value="Asignado" ${st_fil==='Asignado'?'selected':''}>ASIGNADO</option>
        <option value="EnProceso" ${st_fil==='EnProceso'?'selected':''}>EN PROCESO</option>
        <option value="Completado" ${st_fil==='Completado'?'selected':''}>COMPLETADO</option>
      </select>
    </div>
    <button class="btn btn-primary" style="height:38px;padding:0 20px;margin-top:20px;" onclick="WMS_MODULES.picking.show_dashboard(true)"><i class="fa-solid fa-filter"></i> FILTRAR</button>
  </div>

  <div class="pro-kpi-grid" style="grid-template-columns:repeat(6,1fr);gap:14px">
    <div class="pro-kpi-card accent-blue" style="grid-column: span 2; display:flex; flex-direction:row; align-items:center; gap:20px; padding:20px;">
       <div style="position:relative; width:80px; height:80px;">
         <svg width="80" height="80" viewBox="0 0 100 100" style="transform:rotate(-90deg)">
            <circle cx="50" cy="50" r="45" fill="none" stroke="#e2e8f0" stroke-width="8"/>
            <circle cx="50" cy="50" r="45" fill="none" stroke="${pctG===100?'#10b981':'#3b82f6'}" stroke-width="8" stroke-dasharray="283" stroke-dashoffset="${283 - (pctG/100)*283}" stroke-linecap="round" style="transition:1s ease-out"/>
         </svg>
         <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:18px; font-weight:900; color:#1e293b;">${pctG}%</div>
       </div>
       <div>
         <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Progreso Operación</div>
         <div style="font-size:24px; font-weight:900; color:#1e293b; margin:4px 0;">${WMS.formatNum(okL)} / ${WMS.formatNum(totalL)}</div>
         <div style="font-size:11px; color:#94a3b8;">Líneas procesadas hoy</div>
       </div>
    </div>
    <div class="pro-kpi-card accent-amber">
      <div class="pro-kpi-value">${stCount.Pendiente}</div>
      <div class="pro-kpi-label">POR INICIAR</div>
      <div class="pro-kpi-sub">Planillas pendientes</div>
    </div>
    <div class="pro-kpi-card accent-blue">
      <div class="pro-kpi-value">${stCount.EnProceso}</div>
      <div class="pro-kpi-label">EN EJECUCIÓN</div>
      <div class="pro-kpi-sub">Picking activo</div>
    </div>
    <div class="pro-kpi-card accent-green">
      <div class="pro-kpi-value">${stCount.Completado}</div>
      <div class="pro-kpi-label">TERMINADAS</div>
      <div class="pro-kpi-sub">Finalizadas hoy</div>
    </div>
    <div class="pro-kpi-card accent-red">
      <div class="pro-kpi-value">${(d.alertas_faltantes||[]).length}</div>
      <div class="pro-kpi-label">FALTANTES</div>
      <div class="pro-kpi-sub">Alertas críticas</div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;margin:20px 0;">
    <!-- Alertas de Faltantes -->
    <div class="card" style="border:1px solid #fee2e2;">
      <div class="card-header" style="background:#fef2f2;border-bottom:1px solid #fecaca;"><span class="card-title text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Alerta de Faltantes Críticos</span></div>
      <div class="table-container" style="max-height:300px;">
        <table class="data-table" style="font-size:11px;">
          <thead style="background:#fff;"><tr><th>Producto</th><th class="text-center">Solic.</th><th class="text-center">H.Ini</th><th class="text-center">Planilla</th></tr></thead>
          <tbody>${(d.alertas_faltantes||[]).length ? d.alertas_faltantes.map(f => `<tr>
            <td><b style="color:#1e293b">${WMS.esc(f.producto)}</b><br><span style="color:#94a3b8">${WMS.esc(f.ean)}</span></td>
            <td class="text-center"><span class="badge badge-danger">${f.solic}</span></td>
            <td class="text-center" style="font-family:monospace">${f.hora_ini ? f.hora_ini.substr(11,5) : '-'}</td>
            <td class="text-center"><span class="badge badge-light" style="border:1px solid #e2e8f0;">#${f.planilla}</span></td>
          </tr>`).join('') : '<tr><td colspan="4" class="table-empty">Sin alertas de stock</td></tr>'}</tbody>
        </table>
      </div>
    </div>

    <!-- Ranking Auxiliares (Multimétrica) -->
    <div class="card">
      <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title"><i class="fa-solid fa-ranking-star" style="color:#f59e0b;"></i> Ranking de Productividad Auxiliar</span>
        <span style="font-size:11px; color:#94a3b8; font-weight:600;">UNIDADES · LÍNEAS · PEDIDOS</span>
      </div>
      <div class="table-container" style="max-height:300px;">
        <table class="data-table">
          <thead><tr><th>#</th><th>Auxiliar</th><th class="text-center">Pedidos</th><th class="text-center">Líneas</th><th class="text-center">Unid. Pick</th><th style="width:100px;">Desempeño</th></tr></thead>
          <tbody>${(d.ranking_auxiliares||[]).length ? d.ranking_auxiliares.map((a,i) => {
            const maxL = d.ranking_auxiliares[0].lineas || 1;
            const pct = Math.round((a.lineas / maxL) * 100);
            return `<tr>
              <td class="text-center"><span style="font-weight:900; color:#94a3b8">${i+1}</span></td>
              <td><b>${WMS.esc(a.nombre)}</b></td>
              <td class="text-center"><b>${a.pedidos}</b></td>
              <td class="text-center"><b>${a.lineas}</b></td>
              <td class="text-center"><span class="badge badge-info">${WMS.formatNum(a.unidades)}</span></td>
              <td>
                <div style="background:#f1f5f9; height:6px; border-radius:99px; overflow:hidden;">
                  <div style="width:${pct}%; background:linear-gradient(to right, #3b82f6, #10b981); height:100%;"></div>
                </div>
              </td>
            </tr>`;
          }).join('') : '<tr><td colspan="6" class="table-empty">No hay actividad registrada</td></tr>'}</tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-list-check"></i> Proceso por Planilla y Tiempos Operacionales</span></div>
    <div class="table-container">
      <table class="data-table" id="dash-table">
        <thead><tr>
          <th>Planilla</th><th>Ruta</th><th class="text-center">Avance Líneas</th><th style="min-width:130px;">Progreso</th>
          <th>Estado</th><th>Encargado(s)</th><th class="text-center">H. Inicio</th><th class="text-center">T. Demora</th><th class="text-right">Obs</th>
        </tr></thead>
        <tbody>${tableRows || '<tr><td colspan="9" class="table-empty">Sin planillas en este rango</td></tr>'}</tbody>
      </table>
    </div>
  </div>
</div>`);
      this._initDashboardCharts(d.series || null, stCount);
    } catch(e) {
      console.error(e);
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>Error cargando dashboard</p></div>');
    }
  },

  _startTimers() {
    this.stopTimers();
    this._timerInterval = setInterval(() => {
      const timers = document.querySelectorAll('.active-timer');
      timers.forEach(t => {
        const start = t.getAttribute('data-start');
        const end   = t.getAttribute('data-end');
        const dur = this._getDuration(start, end);
        t.innerText = dur.str;
      });
    }, 1000);
  },
  stopTimers() {
    if (this._timerInterval) { clearInterval(this._timerInterval); this._timerInterval = null; }
  },

  _initDashboardCharts(series, stCount) {
    if (this._pickingChart) this._pickingChart.destroy();
    if (this._pickDonutChart) this._pickDonutChart.destroy();
    
    // Gráfico de Barras - Productividad (Diaria)
    const ctxBar = document.getElementById('pickingProductivityChart');
    if (ctxBar && series && series.diario) {
      const labels = series.diario.map(i => i.label);
      const data   = series.diario.map(i => i.total);
      this._pickingChart = new Chart(ctxBar, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Unidades',
            data: data,
            backgroundColor: '#3b82f6',
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
          }
        }
      });
    }

    // Gráfico de Dona - Estados
    const ctxDonut = document.getElementById('pickDonutChart');
    if (ctxDonut) {
      const labels = ['Pendientes', 'En Proceso', 'Completadas'];
      const data   = [stCount.Pendiente, stCount.EnProceso, stCount.Completado];
      this._pickDonutChart = new Chart(ctxDonut, {
        type: 'doughnut',
        data: {
          labels: labels,
          datasets: [{
            data: data,
            backgroundColor: ['#f59e0b', '#3b82f6', '#10b981'],
            borderWidth: 0
          }]
        },
        options: {
          responsive: false,
          cutout: '75%',
          plugins: { legend: { display: false } }
        }
      });
    }
  },

  _toggleTable(id) {
    document.getElementById(id)?.classList.toggle('collapsed');
  },

  _filterPlanillasTable(q) {
    const tbody = document.getElementById('planillas-tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let vis = 0;
    rows.forEach(tr => {
      const match = !q || tr.textContent.toLowerCase().includes(q.toLowerCase());
      tr.style.display = match ? '' : 'none';
      if (match) vis++;
    });
    const cnt = document.querySelector('#planilla-table-card .pro-table-count');
    if (cnt) cnt.textContent = vis;
  },

  _filterPlanillaEstado(estado) {
    const tbody = document.getElementById('planillas-tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    let vis = 0;
    rows.forEach(tr => {
      const match = !estado || tr.dataset.estado === estado;
      tr.style.display = match ? '' : 'none';
      if (match) vis++;
    });
    const cnt = document.querySelector('#planilla-table-card .pro-table-count');
    if (cnt) cnt.textContent = vis;
  },

  viewPlanillaDetails(planilla) {
    WMS_MODULES.picking.show_pedidos();
    setTimeout(() => {
        const input = document.getElementById('pick-f-plan');
        if (input) {
            input.value = planilla;
            const event = new Event('keypress'); event.key = 'Enter';
            input.dispatchEvent(event);
            WMS_MODULES.picking.show_pedidos();
            setTimeout(() => { WMS_MODULES.picking._togglePlanilla(planilla); }, 600);
        }
    }, 400);
  },

  _fmtCantidad(cant, unidCaja) {
    const u = parseFloat(cant);
    const c = parseInt(unidCaja || 1);
    if (c <= 1) return `${WMS.formatNum(u)} Unid.`;
    const cajas = Math.floor(u / c), sueltas = u % c;
    let txt = '';
    if (cajas > 0) txt += `${cajas} Cj. `;
    if (sueltas > 0) txt += `${WMS.formatNum(sueltas)} Unid.`;
    return txt || '0';
  },

  _togglePlanilla(planilla) {
    const row  = document.getElementById('sub-plan-' + planilla);
    const icon = document.getElementById('icon-plan-' + planilla);
    if (row && icon) {
      const isHidden = row.style.display === 'none';
      row.style.display = isHidden ? 'table-row' : 'none';
      icon.style.transform = isHidden ? 'rotate(90deg)' : 'rotate(0deg)';
    }
  },

  async filterEstado(val) {
      const el = document.getElementById('pick-f-st');
      if (el) { el.value = val; this.show_pedidos(); }
  },

  filterTable(val) {
      const el = document.getElementById('pick-f-plan');
      if (el) { el.value = val; this.show_pedidos(); }
  },

  _verDetallePlanilla(planilla) {
    WMS_MODULES.picking.show_pedidos();
    setTimeout(() => {
        const input = document.getElementById('pick-f-plan');
        if (input) {
            input.value = planilla;
            this.show_pedidos();
            setTimeout(() => {
                this._togglePlanilla(planilla);
            }, 500);
        }
    }, 300);
  },

  // ── REPORTE ───────────────────────────────────────────────────────────────
  async show_reporte() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.show_reporte()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.picking.exportarReporte()"><i class="fa-solid fa-file-excel"></i> Exportar</button>
    `);
    WMS.setContent('<div class="pro-loading"><i class="fa-solid fa-spinner fa-spin"></i> Cargando reporte...</div>');
    try {
      const params = new URLSearchParams({ fecha_desde: this.getToday(), fecha_hasta: this.getToday() });
      const data = await API.get('/picking/reporte?' + params.toString());
      WMS.setContent(data.html || '<p>Sin datos para el período seleccionado.</p>');
    } catch(e) {
      WMS.toast('error', 'Error cargando reporte: ' + e.message);
    }
  },

  _openTVDashboard() {
    const url = `index.html?view=picking-dash&standalone=1`;
    window.open(url, '_blank');
  },
  _tvRefreshInterval: null,
};