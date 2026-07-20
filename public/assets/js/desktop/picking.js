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
      reporte: this.show_reporte, pendientes: this.show_productos_pendientes,
      reservas: this.show_reservas, agotados: this.show_agotados,
      consulta: this.show_consulta, novedades: this.show_novedades,
    };
    (fn[s]?.bind(this) || fn.pedidos.bind(this))();
    this.stopAutoRefresh();
  },

  // ── Auto-refresh picking (proceso crítico, máx 5 usuarios) ───────────────
  _pickInterval: null,
  startAutoRefresh(sub) {
    this.stopAutoRefresh();
    this._pickInterval = setInterval(() => {
      if (WMS.currentModule !== 'picking') { this.stopAutoRefresh(); return; }
      const cur = WMS.currentSubModule;
      if (cur === 'dashboard') this.show_dashboard(true);
      else                     this.stopAutoRefresh();
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
      faltantes:'Faltantes de Stock', dashboard:'Dashboard Picking', reporte:'Reporte Picking',
      pendientes:'Prod. Sin Codificar', reservas:'Reservas', agotados:'Módulo de Agotados',
      consulta:'Consulta de Picking', novedades:'Novedades de Picking' };
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

  /**
   * Formatea un valor que YA viene en CAJAS (convención real de picking_detalles.
   * cantidad_solicitada/cantidad_pickeada y picking_faltantes.cantidad_solicitada/
   * cantidad_faltante — confirmado contra datos reales, NO dividir de nuevo por upc)
   * mostrando "X cj + Y suelt." y el total en UND/TOTAL debajo.
   */
  _fmtCajasDesglose(valorCajas, unidades_caja, esBadge = false) {
    const upc = Math.max(1, parseInt(unidades_caja) || 1);
    const v   = Math.max(0, parseFloat(valorCajas) || 0);
    const und = v * upc;
    if (upc <= 1) return `<strong>${WMS.formatNum(v)}</strong>`;
    const cajas = Math.floor(v);
    const saldo = Math.round((v - cajas) * upc * 1000) / 1000;
    const txt = cajas <= 0 && saldo > 0
      ? `${WMS.formatNum(saldo)} suelt.`
      : `${WMS.formatNum(cajas)} cj${saldo > 0 ? ' + ' + WMS.formatNum(saldo) + ' suelt.' : ''}`;
    const linea1 = esBadge ? `<span class="badge badge-danger">${txt}</span>` : `<strong>${txt}</strong>`;
    return `${linea1}<div style="font-size:10px;color:#64748b;">${WMS.formatNum(und)} und</div>`;
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
          clientes: new Set(),
          observaciones: new Set(),
          prioridad: 0,
          productos: {},
          primer_pick_ts: null,
          primer_pick_str: null,
          estados: new Set(),
        };
      }
      grupos[key].ordenes.push(p);
      if (p.cliente) grupos[key].clientes.add(p.cliente);
      if (p.observaciones && p.observaciones.trim()) grupos[key].observaciones.add(p.observaciones.trim());
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
        // Recopilar auxiliares asignados a nivel de detalle sin importar si está completado o no
        if (d.auxiliar?.nombre) {
            pr.auxiliares.add(d.auxiliar.nombre);
            grupos[key].auxiliares.add(d.auxiliar.nombre);
        }
        
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
    const isDash   = options.isDashboard || false;
    const planKey  = g.planilla.replace(/[^a-zA-Z0-9]/g, '_');
    const auxArr  = [...g.auxiliares];
    const auxList = auxArr.length
      ? auxArr.map(n => `<span style="display:inline-flex;align-items:center;gap:3px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;padding:1px 6px;font-size:.68rem;font-weight:600;color:#166534;margin:1px;"><i class="fa-solid fa-user" style="font-size:.6rem;"></i>${WMS.esc(n)}</span>`).join(' ')
      : '<span style="color:#94a3b8;font-size:.72rem;font-style:italic;">Sin asignar</span>';
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
        <td style="padding:5px 8px;text-align:center;font-weight:600;">${this._fmtCajasDesglose(pr.cantidad_total, pr.unidades_caja)}</td>
        <td style="padding:5px 8px;text-align:center;">${this._fmtCajasDesglose(Math.max(0, (parseFloat(pr.cantidad_total)||0) - (parseFloat(pr.cantidad_pendiente)||0)), pr.unidades_caja)}</td>
        <td style="padding:5px 8px;text-align:center;color:#dc3545;font-weight:600;">${pr.cantidad_pendiente > 0 ? this._fmtCajasDesglose(pr.cantidad_pendiente, pr.unidades_caja) : '<span style="color:#94a3b8;">0</span>'}</td>
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
      <td style="white-space:nowrap;font-size:12px;font-weight:600;color:#1d4ed8;">${g.ordenes?.[0]?.fecha_requerida ? WMS.formatDate(g.ordenes[0].fecha_requerida) : '—'}</td>
      <td style="font-size:11px;font-weight:600;">
        ${WMS.esc([...g.clientes].join(', ') || '-')}
        ${g.observaciones.size ? `<i class="fa-solid fa-note-sticky" style="color:#f59e0b;margin-left:5px;cursor:help;" title="${WMS.esc([...g.observaciones].join(' | '))}"></i>` : ''}
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
      <td style="font-size:11px;">${auxList}</td>
      <td class="text-center"><span style="font-family:monospace;font-size:11px;">${inicioOp || '-'}</span></td>
      <td>
        ${!isDash ? `
          <div class="actions" style="gap:4px;flex-wrap:wrap;">
            ${g.estado === 'EnProceso' ? `<button class="btn btn-sm btn-success" onclick="WMS_MODULES.picking._cerrarPlanilla('${WMS.esc(g.planilla)}')"><i class="fa-solid fa-check-double"></i> Cerrar</button>` : ''}
            ${g.estado === 'Completado' ? `<button class="btn btn-sm btn-warning" onclick="WMS_MODULES.picking._reabrirPlanilla(${ordenIdsJson})"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>` : ''}
          </div>
        ` : `
          <div class="text-right">
             ${g.prioridad ? '<span class="badge badge-danger">Alta</span>' : '<span class="badge badge-light">Norm.</span>'}
          </div>
        `}
      </td>
    </tr>
    <tr id="sub-plan-${WMS.esc(g.planilla)}" style="display:none;background:#f8fafc;" data-estado="${g.estado}">
      <td colspan="11" style="padding:0 8px 10px 42px;">
        <div style="border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;background:#fff;box-shadow:inset 0 2px 4px rgba(0,0,0,.02)">
          ${g.ordenes && g.ordenes.length > 0 ? (() => {
            const asignados = g.ordenes.filter(o => o.auxiliar?.nombre).length;
            const sinAsig   = g.ordenes.length - asignados;
            return `
          <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:8px 12px;">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
              <span style="font-size:10px;font-weight:700;color:#1e40af;text-transform:uppercase;">Pedidos de la planilla:</span>
              ${sinAsig > 0 ? `<span style="background:#fef3c7;color:#92400e;border-radius:3px;padding:1px 7px;font-size:10px;font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> ${sinAsig} sin asignar</span>` : ''}
              ${asignados > 0 ? `<span style="background:#dcfce7;color:#166534;border-radius:3px;padding:1px 7px;font-size:10px;font-weight:700;"><i class="fa-solid fa-check"></i> ${asignados} asignado(s)</span>` : ''}
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              ${g.ordenes.map(o => {
                const aux       = o.auxiliar?.nombre || null;
                const auxId     = parseInt(o.id) || 0;
                const nf        = o.numero_factura ? ` · ${WMS.esc(o.numero_factura)}` : '';
                const esPend    = o.estado === 'Pendiente';
                const esProceso = o.estado === 'EnProceso';
                return `<div style="background:#fff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 10px;min-width:190px;max-width:260px;display:flex;flex-direction:column;gap:5px;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                  <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                    <span style="font-weight:700;font-size:11px;color:#1e40af;">${WMS.esc(String(o.numero_orden || o.id))}${nf}</span>
                    <span style="font-size:9px;font-weight:600;padding:1px 6px;border-radius:3px;white-space:nowrap;
                      ${esPend ? 'background:#fef9c3;color:#854d0e;' : esProceso ? 'background:#dbeafe;color:#1e40af;' : 'background:#dcfce7;color:#166534;'}">
                      ${WMS.esc(o.estado||'—')}
                    </span>
                  </div>
                  <div style="font-size:9px;font-weight:600;white-space:nowrap;${aux ? 'color:#166534;' : 'color:#92400e;font-style:italic;'}">
                    <i class="fa-solid ${aux ? 'fa-user' : 'fa-user-slash'}" style="font-size:8px;"></i>
                    ${aux ? WMS.esc(aux) : 'Sin asignar'}
                  </div>
                  <div style="display:flex;gap:4px;margin-top:2px;">
                    <button title="Ver / editar líneas del pedido" onclick="WMS_MODULES.picking._abrirEditorInline(${auxId},'${planKey}')"
                      style="flex:1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:3px;padding:3px 8px;cursor:pointer;color:#1e40af;font-size:.7rem;font-weight:600;">
                      <i class="fa-solid fa-list-check"></i> Ver / Editar
                    </button>
                    <button title="Eliminar pedido" onclick="WMS_MODULES.picking._anularPedido(${auxId})"
                      style="background:#fff1f2;border:1px solid #fecdd3;border-radius:3px;padding:3px 8px;cursor:pointer;color:#991b1b;font-size:.7rem;">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>`;
              }).join('')}
            </div>
          </div>`;
          })() : ''}
          <div id="ied-${planKey}" style="display:none;padding:10px 14px;border-top:1px solid #bfdbfe;background:#f0f7ff;"></div>
          <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead style="background:#f1f5f9;color:#64748b;font-weight:700;text-transform:uppercase;font-size:10px;">
              <tr>
                <th style="padding:6px 8px;">Producto</th>
                <th style="padding:6px 8px;text-align:center;" title="Cantidad total solicitada en cajas y su equivalente UND/TOTAL">Total Solicitado</th>
                <th style="padding:6px 8px;text-align:center;" title="Cantidad efectivamente separada">Separado</th>
                <th style="padding:6px 8px;text-align:center;color:#dc3545;" title="Restante por separar (por agotado o pendiente de picking)">Total Faltante</th>
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
    if (!silent) {
      WMS.setBreadcrumb('picking', 'Pedidos');
      WMS.spinner();
    }
    this._pedidosFiltros = this._pedidosFiltros || {
      q: '', solo_hoy: 1, estado: '', ruta: '', sucursal_entrega: '', fecha_desde: '', fecha_hasta: ''
    };
    await this._cargarPedidos();
  },

  _todayStr() {
    return new Date().toISOString().split('T')[0];
  },

  async _cargarPedidos() {
    if (typeof WMS !== 'undefined' && WMS.currentModule !== 'picking') return;
    const f = this._pedidosFiltros || {};
    const params = new URLSearchParams();
    if (f.solo_hoy)            params.set('solo_hoy', '1');
    if (f.incluir_finalizados) params.set('incluir_finalizados', '1');
    if (f.q)                   params.set('q', f.q);
    if (f.estado)              params.set('estado', f.estado);
    if (f.ruta)                params.set('ruta', f.ruta);
    if (f.sucursal_entrega)    params.set('sucursal_entrega', f.sucursal_entrega);
    if (f.fecha_desde)         params.set('fecha_desde', f.fecha_desde);
    if (f.fecha_hasta)         params.set('fecha_hasta', f.fecha_hasta);
    params.set('limit', '500');

    try {
      if (!this._ambientesCache) {
        const ar = await API.get('/param/ambientes');
        this._ambientesCache = ar.data || ar || [];
      }
      const r = await API.get('/picking?' + params.toString());
      const ordenes = r.data || r || [];
      this._renderPedidosTabla(ordenes);
    } catch(e) {
      WMS.toast('error', 'Error cargando pedidos');
    }
  },

  _renderPedidosTabla(ordenes) {
    // Ordenar: prioridad desc (alta primero), luego fecha_pedido asc (más antigua primero)
    ordenes = [...ordenes].sort((a, b) => {
      const pa = parseInt(a.prioridad) || 0;
      const pb = parseInt(b.prioridad) || 0;
      if (pb !== pa) return pb - pa;
      const fa = a.fecha_pedido || a.created_at || '';
      const fb = b.fecha_pedido || b.created_at || '';
      return fa < fb ? -1 : fa > fb ? 1 : 0;
    });
    const f = this._pedidosFiltros || {};
    const defaultIconos = {seco:'🌡️',refrigerado:'❄️',congelado:'🧊'};
    const defaultColores = {seco:'#92400e',refrigerado:'#0369a1',congelado:'#7c3aed'};
    const ambientes = (this._ambientesCache || []).length > 0
      ? this._ambientesCache
      : [{codigo:'SECO',color:'#92400e',icono:''},{codigo:'REFRIGERADO',color:'#0369a1',icono:''},{codigo:'CONGELADO',color:'#7c3aed',icono:''}];

    const estadoBadge = (e) => {
      const map = {
        'Pendiente': 'background:#fef9c3;color:#854d0e',
        'EnProceso': 'background:#dbeafe;color:#1e40af',
        'Completada':'background:#dcfce7;color:#166534',
        'Cancelada': 'background:#fee2e2;color:#991b1b',
        'Anulado':   'background:#f1f5f9;color:#64748b',
      };
      const s = map[e] || 'background:#f1f5f9;color:#64748b';
      return `<span style="${s};padding:2px 8px;border-radius:3px;font-size:.72rem;font-weight:600;">${WMS.esc(e)}</span>`;
    };

    const grupos = this._agruparPorPlanilla(ordenes);
    const rows = grupos.map(g => this._renderPlanillaRow(g)).join('');

    const rutasUnicas     = [...new Set(ordenes.map(o=>o.ruta).filter(Boolean))];
    const sucursalesUnicas = [...new Set(ordenes.map(o=>o.sucursal_entrega||o.cliente).filter(Boolean))];

    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Pedidos de Picking</h5>
          <div class="card-actions">
            <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.nuevoPedidoManual()">
              <i class="fa-solid fa-pencil"></i> Nuevo Manual
            </button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking.importarPedidos()">
              <i class="fa-solid fa-file-arrow-up"></i> Importar CSV
            </button>
          </div>
        </div>
        <div class="card-body" style="padding:0;">
          <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
              <input id="pick-q" type="text" class="form-control" placeholder="🔍 Buscar ruta, sucursal, N° pedido..."
                     value="${WMS.esc(f.q||'')}"
                     oninput="WMS_MODULES.picking._pedidosFiltros.q=this.value;clearTimeout(WMS_MODULES.picking._qt);WMS_MODULES.picking._qt=setTimeout(()=>WMS_MODULES.picking._cargarPedidos(),350)">
            </div>
            <div>
              <select id="pick-ruta" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.ruta=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Ruta: Todas</option>
                ${rutasUnicas.map(r=>`<option value="${WMS.esc(r)}" ${f.ruta===r?'selected':''}>${WMS.esc(r)}</option>`).join('')}
              </select>
            </div>
            <div>
              <select id="pick-suc" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.sucursal_entrega=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Sucursal: Todas</option>
                ${sucursalesUnicas.map(s=>`<option value="${WMS.esc(s)}" ${f.sucursal_entrega===s?'selected':''}>${WMS.esc(s)}</option>`).join('')}
              </select>
            </div>
            <div>
              <select id="pick-est" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.estado=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Estado: Activos</option>
                <option value="Pendiente" ${f.estado==='Pendiente'?'selected':''}>Pendiente</option>
                <option value="EnProceso" ${f.estado==='EnProceso'?'selected':''}>En Proceso</option>
                <option value="Completada,Cancelada" ${f.estado==='Completada,Cancelada'?'selected':''}>Finalizados</option>
              </select>
            </div>
            <div style="display:flex;gap:4px;align-items:center;">
              <input id="pick-desde" type="date" class="form-control" style="width:140px;" value="${f.fecha_desde||''}"
                     onchange="WMS_MODULES.picking._pedidosFiltros.fecha_desde=this.value;WMS_MODULES.picking._pedidosFiltros.solo_hoy=0;WMS_MODULES.picking._cargarPedidos()">
              <span style="color:#64748b;font-size:.78rem;">—</span>
              <input id="pick-hasta" type="date" class="form-control" style="width:140px;" value="${f.fecha_hasta||''}"
                     onchange="WMS_MODULES.picking._pedidosFiltros.fecha_hasta=this.value;WMS_MODULES.picking._pedidosFiltros.solo_hoy=0;WMS_MODULES.picking._cargarPedidos()">
            </div>
            <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.picking._pedidosFiltros={solo_hoy:1,q:'',estado:'',ruta:'',sucursal_entrega:'',fecha_desde:'',fecha_hasta:''};WMS_MODULES.picking._cargarPedidos()">
              <i class="fa-solid fa-rotate-left"></i> Hoy
            </button>
          </div>
          <div style="overflow-x:auto;">
            <table class="erp-table">
              <thead>
                <tr>
                  <th style="padding:10px 12px;width:30px;"></th>
                  <th style="padding:10px 12px;">Planilla</th>
                  <th style="padding:10px 12px;">Fecha</th>
                  <th style="padding:10px 12px;">Cliente</th>
                  <th style="padding:10px 12px;">Ruta</th>
                  <th style="padding:10px 12px;text-align:center;">Progreso</th>
                  <th style="padding:10px 12px;text-align:center;">%</th>
                  <th style="padding:10px 12px;">Estado</th>
                  <th style="padding:10px 12px;">Auxiliar</th>
                  <th style="padding:10px 12px;text-align:center;">Hr. Inicio</th>
                  <th style="padding:10px 12px;text-align:center;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                ${rows || `<tr><td colspan="11" style="text-align:center;padding:32px;color:#94a3b8;">Sin pedidos activos hoy. Use los filtros para buscar.</td></tr>`}
              </tbody>
            </table>
          </div>
        </div>
      </div>`);
  },

  async _toggleExpandRow(tr, ordenId) {
    const expandRow = document.getElementById('expand-' + ordenId);
    if (!expandRow) return;
    if (expandRow.style.display !== 'none') {
      expandRow.style.display = 'none';
      tr.classList.remove('selected');
      return;
    }
    expandRow.style.display = '';
    tr.classList.add('selected');
    await this._recargarExpandRow(ordenId);
  },

  async _recargarExpandRow(ordenId) {
    const content = document.getElementById('expand-content-' + ordenId);
    if (!content) return;
    content.innerHTML = '<div style="color:#64748b;font-size:.78rem;padding:8px;">Cargando detalle...</div>';
    try {
      const r = await API.get('/picking/' + ordenId);
      const o = r.data || r;
      // Pendiente: Supervisor o Admin pueden editar/eliminar líneas.
      // EnProceso (ya asignada): solo Admin — coincide con el backend (requireAdmin).
      const rol = WMS.user?.rol || '';
      const editable = o.estado === 'Pendiente'
        ? (rol === 'Admin' || rol === 'Supervisor')
        : (o.estado === 'EnProceso' && rol === 'Admin');
      const lineas = (o.detalles || []).map(d => {
        const lineaEditable = ['Pendiente', 'EnProceso', 'Faltante'].includes(d.estado);
        const puedeEliminar  = (parseFloat(d.cantidad_pickeada) || 0) === 0;
        return `<tr>
          <td style="padding:5px 10px;font-size:.78rem;">
            ${WMS.esc(d.producto?.nombre||d.producto?.codigo_interno||'—')}
            ${d.numero_pedido_ref ? `<div style="font-size:.68rem;color:#64748b;">Ref: ${WMS.esc(d.numero_pedido_ref)}</div>` : ''}
          </td>
          <td style="padding:5px 10px;font-size:.78rem;text-align:center;">
            ${editable && lineaEditable
              ? `<input type="number" min="0.001" step="0.001" id="qty-${parseInt(d.id)||0}" value="${parseFloat(d.cantidad_solicitada)||0}"
                   style="width:70px;text-align:center;border:1px solid #cbd5e1;border-radius:3px;padding:2px 4px;font-size:.78rem;"
                   onkeydown="if(event.key==='Enter')WMS_MODULES.picking._guardarCantidadLinea(${parseInt(o.id)||0},${parseInt(d.id)||0})">`
              : WMS.esc(d.cantidad_solicitada ?? 0)
            }
          </td>
          <td style="padding:5px 10px;font-size:.78rem;text-align:center;">${WMS.esc(d.cantidad_pickeada ?? 0)}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.ambiente||'—')}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.auxiliar?.nombre||'—')}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.estado||'')}</td>
          ${editable ? `<td style="padding:5px 10px;text-align:center;white-space:nowrap;">
            ${lineaEditable
              ? `<button title="Guardar" onclick="WMS_MODULES.picking._guardarCantidadLinea(${parseInt(o.id)||0},${parseInt(d.id)||0})"
                   style="background:#dcfce7;border:none;border-radius:3px;padding:3px 7px;cursor:pointer;color:#166534;font-size:.72rem;margin-right:2px;">
                   <i class="fa-solid fa-check"></i>
                 </button>
                 ${puedeEliminar
                   ? `<button title="Eliminar línea" onclick="WMS_MODULES.picking._eliminarLinea(${parseInt(o.id)||0},${parseInt(d.id)||0})"
                        style="background:#fee2e2;border:none;border-radius:3px;padding:3px 7px;cursor:pointer;color:#991b1b;font-size:.72rem;">
                        <i class="fa-solid fa-trash-can"></i>
                      </button>`
                   : `<span title="Ya tiene unidades separadas, no se puede eliminar" style="font-size:.68rem;color:#94a3b8;"><i class="fa-solid fa-lock"></i></span>`
                 }`
              : `<span style="font-size:.68rem;color:#94a3b8;">—</span>`
            }
          </td>` : ''}
        </tr>`;
      }).join('');
      content.innerHTML = `
        <div style="font-size:.78rem;font-weight:700;color:#0F4C81;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
          <span>Pedido: <strong>${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</strong>
          · Asesor: ${WMS.esc(o.asesor_comercial||'—')}
          · Área: ${WMS.esc(o.area_comercial||'—')}</span>
          ${editable ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:3px;font-size:.68rem;font-weight:600;">Editable</span>' : ''}
        </div>
        <table class="erp-table" style="margin:0;">
          <thead><tr>
            <th style="padding:5px 10px;font-size:.7rem;">Producto</th>
            <th style="padding:5px 10px;font-size:.7rem;text-align:center;">Solicitado</th>
            <th style="padding:5px 10px;font-size:.7rem;text-align:center;">Pickeado</th>
            <th style="padding:5px 10px;font-size:.7rem;">Ambiente</th>
            <th style="padding:5px 10px;font-size:.7rem;">Auxiliar</th>
            <th style="padding:5px 10px;font-size:.7rem;">Estado</th>
            ${editable ? '<th style="padding:5px 10px;font-size:.7rem;text-align:center;">Acciones</th>' : ''}
          </tr></thead>
          <tbody>${lineas||`<tr><td colspan="${editable?7:6}" style="text-align:center;color:#94a3b8;padding:12px;">Sin líneas</td></tr>`}</tbody>
        </table>
        ${editable ? `<div style="margin-top:8px;text-align:right;">
          <button onclick="WMS_MODULES.picking._dlgAgregarRef(${parseInt(o.id)||0}, true)"
            style="background:#059669;color:#fff;border:none;border-radius:4px;padding:5px 14px;font-size:.78rem;cursor:pointer;font-weight:600;">
            <i class="fa-solid fa-plus"></i> Agregar referencia
          </button>
        </div>` : ''}`;
    } catch(e) {
      content.innerHTML = '<div style="color:#ef4444;font-size:.78rem;padding:8px;">Error cargando detalle</div>';
    }
  },

  async _asignarRutaInline(ordenId, btn) {
    const ruta = prompt('Nombre de la ruta para este pedido:');
    if (ruta === null) return;
    try {
      await API.put('/picking/' + ordenId + '/ruta', { ruta });
      WMS.toast('success', 'Ruta asignada');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', 'Error asignando ruta');
    }
  },

  async _abrirCambiarAuxiliar(ordenId, auxNombreActual) {
    let personal = [];
    try {
      const r = await API.get('/param/personal?rol=Auxiliar&activo=1');
      personal = r.data || r || [];
    } catch(e) { if (e.isSessionExpired) return; }

    WMS.showRightPanel('Cambiar Auxiliar', `
      <div style="margin-bottom:14px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:13px;color:#1e40af;">
        <i class="fa-solid fa-circle-info"></i> El pedido aún no ha iniciado — se puede reasignar a otro auxiliar.
      </div>
      ${auxNombreActual ? `<div style="margin-bottom:14px;padding:10px 14px;background:#fef9c3;border:1px solid #fde68a;border-radius:4px;font-size:13px;">
        <b>Auxiliar actual:</b> ${WMS.esc(auxNombreActual)}
      </div>` : ''}
      <div class="form-group">
        <label class="form-label">Nuevo Auxiliar <span class="required">*</span></label>
        <select id="ca-aux" class="form-control">
          <option value="">Seleccionar auxiliar...</option>
          ${personal.map(p => `<option value="${parseInt(p.id)||0}">${WMS.esc(p.nombre)}</option>`).join('')}
        </select>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.picking._confirmarCambiarAuxiliar(${parseInt(ordenId)||0})">
         <i class="fa-solid fa-user-check"></i> Confirmar Cambio
       </button>`);
  },

  async _confirmarCambiarAuxiliar(ordenId) {
    const auxId = document.getElementById('ca-aux')?.value;
    if (!auxId) { WMS.toast('warning', 'Seleccione un auxiliar'); return; }
    try {
      await API.put('/picking/' + ordenId + '/auxiliar', { auxiliar_id: parseInt(auxId) });
      WMS.closeRightPanel();
      WMS.toast('success', 'Auxiliar actualizado correctamente');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error actualizando auxiliar');
    }
  },

  async _abrirAgregarAuxiliar(ordenId) {
    let personal = [];
    try {
      const r = await API.get('/param/personal?rol=Auxiliar&activo=1');
      personal = r.data || r || [];
    } catch(e) { if (e.isSessionExpired) return; }

    WMS.showRightPanel('Agregar Auxiliar al Picking', `
      <div style="margin-bottom:14px;padding:12px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:13px;color:#166534;">
        <i class="fa-solid fa-code-branch"></i>
        <strong>División inteligente:</strong> el sistema asignará la mitad de las líneas pendientes al nuevo auxiliar, ordenadas por zona y pasillo para evitar duplicar recorridos. No habrá productos duplicados entre auxiliares.
      </div>
      <div class="form-group">
        <label class="form-label">Auxiliar Adicional <span class="required">*</span></label>
        <select id="aa-aux" class="form-control">
          <option value="">Seleccionar auxiliar...</option>
          ${personal.map(p => `<option value="${parseInt(p.id)||0}">${WMS.esc(p.nombre)}</option>`).join('')}
        </select>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-success" onclick="WMS_MODULES.picking._confirmarAgregarAuxiliar(${parseInt(ordenId)||0})">
         <i class="fa-solid fa-user-plus"></i> Asignar Líneas
       </button>`);
  },

  async _confirmarAgregarAuxiliar(ordenId) {
    const auxId = document.getElementById('aa-aux')?.value;
    if (!auxId) { WMS.toast('warning', 'Seleccione un auxiliar'); return; }
    try {
      const r = await API.post('/picking/' + ordenId + '/auxiliar', { auxiliar_id: parseInt(auxId) });
      WMS.closeRightPanel();
      WMS.toast('success', r.message || 'Auxiliar asignado con éxito');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error asignando auxiliar');
    }
  },

  async _eliminarOrden(ordenId) {
    if (!confirm('¿Eliminar este pedido? Se revertirán las reservas de inventario.')) return;
    try {
      await API.delete('/picking/' + ordenId);
      WMS.toast('success', 'Pedido eliminado');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error eliminando pedido');
    }
  },

  _abrirEditar(ordenId) {
    const expandRow = document.getElementById('expand-' + ordenId);
    if (expandRow && expandRow.style.display === 'none') {
      const mainRow = document.getElementById('main-row-' + ordenId);
      if (mainRow) this._toggleExpandRow(mainRow, ordenId);
    } else {
      const content = document.getElementById('expand-content-' + ordenId);
      if (content) content.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  },

  async _guardarCantidadLinea(ordenId, lineaId) {
    const input = document.getElementById('qty-' + lineaId);
    if (!input) return;
    const cantidad = parseFloat(input.value);
    if (!cantidad || cantidad <= 0) { WMS.toast('warning', 'Cantidad inválida'); return; }
    try {
      await API.patch('/picking/' + ordenId + '/linea/' + lineaId, { cantidad_solicitada: cantidad });
      WMS.toast('success', 'Cantidad actualizada');
      await this._recargarExpandRow(ordenId);
    } catch(e) {
      WMS.toast('error', e.message || 'Error actualizando cantidad');
    }
  },

  async _eliminarLinea(ordenId, lineaId) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Eliminar línea?',
      text: 'Se revertirá la reserva de inventario para esta referencia.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Eliminar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#dc2626',
    });
    if (!isConfirmed) return;
    try {
      const r = await API.delete('/picking/' + ordenId + '/linea/' + lineaId);
      WMS.toast('success', r.message || 'Línea eliminada');
      if (r.data?.orden_eliminada) {
        const expandRow = document.getElementById('expand-' + ordenId);
        if (expandRow) expandRow.style.display = 'none';
        const mainRow = document.getElementById('main-row-' + ordenId);
        if (mainRow) mainRow.classList.remove('selected');
        WMS.closeRightPanel();
        this._cargarPedidos();
      } else {
        const p = this._detallePicking;
        if (p?.id) { const updated = await API.get('/picking/' + p.id); this._detallePicking = updated.data || updated; this._renderDetallePicking(p.id, this._detallePicking); }
        await this._recargarExpandRow(ordenId);
      }
    } catch(e) {
      WMS.toast('error', e.message || 'Error eliminando línea');
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
  _detallePicking: null,
  async verDetalle(id) {
    try {
      const r = await API.get('/picking/' + id);
      const p = r.data || r;
      this._detallePicking = p;
      this._renderDetallePicking(id, p);
    } catch(e) { WMS.toast('error', 'Error cargando detalle'); }
  },

  _renderDetallePicking(id, p) {
    const lineas = p.lineas || p.detalles || [];
    const self = this;
    const esEditable = ['Pendiente','Asignado','EnProceso'].includes(p.estado);
    const esCompletada = p.estado === 'Completada';
    const puedeReabrir = esCompletada && p.estado_certificacion !== 'Certificada';

    const filas = lineas.map((l, idx) => {
      const upc = l.unidades_caja || l.producto?.unidades_caja || 1;
      const prodNombre = l.producto?.nombre || l.descripcion || '-';
      const cantSol = l.cantidad_solicitada || l.cantidad_pedida || l.cantidad || 0;
      const cantPick = l.cantidad_pickeada || l.cantidad_confirmada || 0;
      const lEst = l.estado || '';
      const isOpen = lEst === 'Pendiente' || lEst === 'EnProceso';
      const badgeCls = lEst === 'Completado' ? 'badge-success' : lEst === 'Faltante' ? 'badge-danger' : 'badge-warning';
      const acciones = '';
      return `<tr>
        <td>
          <div style="font-size:12px;font-weight:600;">${WMS.esc(prodNombre)}</div>
          <div style="font-size:10px;color:#94a3b8;">${WMS.esc(l.ubicacion?.codigo || l.ubicacion || '—')}</div>
          ${acciones}
        </td>
        <td style="text-align:center;">${WMS.formatNum(cantSol)}</td>
        <td style="text-align:center;">${self._fmtCajasDesglose(cantSol, upc)}</td>
        <td style="text-align:center;font-weight:700;color:${cantPick>0?'#059669':'#94a3b8'}">${WMS.formatNum(cantPick)}</td>
        <td><span class="badge ${badgeCls}">${lEst||'Pendiente'}</span></td>
      </tr>`;
    }).join('') || '<tr><td colspan="5" class="table-empty">Sin líneas</td></tr>';

    const despachadoDirecto = !!p.despachado_directo;

    WMS.showRightPanel('Detalle Picking — ' + (p.planilla_numero || ('#' + id)), `
      <div class="form-grid form-grid-2" style="margin-bottom:14px;">
        <div><label class="form-label">Planilla</label><p><span class="badge badge-info">${WMS.esc(p.planilla_numero||'N/A')}</span></p></div>
        <div><label class="form-label">Cliente</label><p>${WMS.esc(p.cliente||'-')}</p></div>
        <div><label class="form-label">Ruta</label><p>${WMS.esc(p.ruta||'-')}</p></div>
        <div><label class="form-label">Estado</label><p><span class="badge badge-info">${WMS.esc(p.estado||'')}</span></p></div>
        <div><label class="form-label">Auxiliar</label><p>${WMS.esc(p.auxiliar||p.usuario||'-')}</p></div>
        ${p.sucursal_entrega ? `<div><label class="form-label">Sucursal Entrega</label><p>${WMS.esc(p.sucursal_entrega)}
          ${p.observaciones ? `<i class="fa-solid fa-note-sticky" style="color:#f59e0b;margin-left:5px;cursor:help;" title="${WMS.esc(p.observaciones)}"></i>` : ''}
        </p></div>` : ''}
      </div>
      ${despachadoDirecto ? `<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;margin-bottom:12px;">
        <span class="badge" style="background:#dc2626;color:#fff;font-weight:700;"><i class="fa-solid fa-hand"></i> RETIRO DIRECTO — NO INCLUIR EN REMISIÓN</span>
        <div style="font-size:11px;color:#991b1b;margin-top:4px;">El cliente ya recogió este pedido directamente en bodega. Se excluye automáticamente de la certificación/remisión.</div>
      </div>` : ''}
      <div class="form-group" style="margin-bottom:14px;">
        <label class="form-label">Observaciones</label>
        <textarea id="pk-det-obs" class="form-control" rows="2"
          placeholder="Notas visibles en picking, certificación y despacho...">${WMS.esc(p.observaciones||'')}</textarea>
        <button class="btn btn-sm btn-secondary" style="margin-top:6px;" onclick="WMS_MODULES.picking._guardarObservaciones(${id})">
          <i class="fa-solid fa-save"></i> Guardar observaciones
        </button>
      </div>
      ${esEditable ? `<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:11px;color:#92400e;">
        <i class="fa-solid fa-circle-info"></i> Usa los botones por línea para separar cantidades o marcar agotados.
      </div>` : ''}
      <div class="table-container" style="overflow-x:auto;">
        <table class="erp-table" style="font-size:12px;">
          <thead><tr>
            <th>Producto / Ubicación</th>
            <th style="text-align:center;">Pedido</th>
            <th style="text-align:center;">Cajas+Picos</th>
            <th style="text-align:center;">Pickeado</th>
            <th>Estado</th>
          </tr></thead>
          <tbody>${filas}</tbody>
        </table>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cerrar</button>
       ${puedeReabrir ? `<button class="btn btn-warning" onclick="WMS_MODULES.picking._reabrirOrden(${id})"><i class="fa-solid fa-rotate-left"></i> Reabrir</button>` : ''}
       ${esEditable ? `<button class="btn btn-danger" onclick="WMS_MODULES.picking._anularPedido(${id})"><i class="fa-solid fa-trash"></i> Anular</button>` : ''}
       ${esEditable ? `<button class="btn btn-info" onclick="WMS_MODULES.picking._dlgAgregarRef(${id})"><i class="fa-solid fa-plus"></i> Agregar Ref.</button>` : ''}
       ${esEditable ? `<button class="btn btn-success" onclick="WMS_MODULES.picking.completarPicking(${id})"><i class="fa-solid fa-check-double"></i> Cerrar Picking</button>` : ''}
       ${esCompletada ? (despachadoDirecto
          ? `<button class="btn btn-sm" style="background:#fff;border:1px solid #dc2626;color:#dc2626;" onclick="WMS_MODULES.picking._toggleDespachadoDirecto(${id}, false)"><i class="fa-solid fa-rotate-left"></i> Desmarcar retiro directo</button>`
          : `<button class="btn" style="background:#dc2626;color:#fff;" onclick="WMS_MODULES.picking._toggleDespachadoDirecto(${id}, true)"><i class="fa-solid fa-hand"></i> Despachado (retiro directo)</button>`
       ) : ''}`);
  },

  async _guardarObservaciones(id) {
    const texto = document.getElementById('pk-det-obs')?.value.trim() || '';
    try {
      await API.put('/picking/' + id, { observaciones: texto });
      WMS.toast('success', 'Observaciones guardadas');
      if (this._detallePicking) this._detallePicking.observaciones = texto;
    } catch(e) { WMS.toast('error', e.message || 'Error al guardar observaciones'); }
  },

  // "Despachado" (retiro directo): el cliente recogió el pedido directamente en
  // bodega, fuera del flujo normal de cargue/despacho. Se marca con etiqueta roja
  // y el backend lo excluye automáticamente de certificación/remisión para que no
  // se mezcle con las planillas que sí hay que imprimir.
  async _toggleDespachadoDirecto(id, marcar) {
    const msg = marcar
      ? '¿Marcar este pedido como retirado directamente por el cliente? Se excluirá de la certificación/remisión y no se imprimirá con la planilla.'
      : '¿Desmarcar el retiro directo? El pedido volverá a incluirse en la certificación/remisión normal.';
    if (!confirm(msg)) return;
    try {
      const r = await API.post('/picking/' + id + '/despachado-directo', { marcar });
      WMS.toast('success', r.message || 'Actualizado');
      const updated = await API.get('/picking/' + id);
      this._detallePicking = updated.data || updated;
      this._renderDetallePicking(id, this._detallePicking);
    } catch(e) { WMS.toast('error', e.message || 'Error al actualizar'); }
  },

  async _dlgConfirmarLinea(lineaId, nombre, cantSol, upc) {
    const { value, isConfirmed } = await Swal.fire({
      title: 'Separar producto',
      html: `<p style="font-size:13px;margin-bottom:10px;"><b>${WMS.esc(nombre)}</b></p>
             <p style="font-size:12px;color:#64748b;margin-bottom:8px;">Solicitado: <b>${WMS.formatNum(cantSol)}</b> und (${this._fmtCantidad(cantSol,upc)})</p>
             <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Cantidad separada (unidades):</label>
             <input id="pk-sep-qty" type="number" min="0" step="0.01" value="${cantSol}"
               class="swal2-input" style="margin:0;width:100%;box-sizing:border-box;">`,
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-check"></i> Confirmar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#059669',
      preConfirm: () => {
        const v = parseFloat(document.getElementById('pk-sep-qty')?.value);
        if (!v || v <= 0) { Swal.showValidationMessage('Ingresa una cantidad mayor a 0'); return false; }
        return v;
      }
    });
    if (!isConfirmed || !value) return;
    try {
      const r = await API.post('/picking/confirmar-consolidado', { ids: String(lineaId), cantidad_tomada: value });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Línea confirmada');
      const p = this._detallePicking;
      if (p?.id) { const updated = await API.get('/picking/' + p.id); this._detallePicking = updated.data || updated; this._renderDetallePicking(p.id, this._detallePicking); }
    } catch(e) { WMS.toast('error', e.message || 'Error al confirmar'); }
  },

  async _dlgAgotadoLinea(lineaId, nombre) {
    const { value: obs, isConfirmed } = await Swal.fire({
      title: '<span style="color:#dc2626;"><i class="fa-solid fa-ban"></i> Marcar Agotado</span>',
      html: `<p style="font-size:13px;margin-bottom:10px;"><b>${WMS.esc(nombre)}</b></p>
             <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Observación (opcional):</label>
             <input id="pk-ago-obs" class="swal2-input" placeholder="Sin stock físico en bodega"
               style="margin:0;width:100%;box-sizing:border-box;">`,
      showCancelButton: true,
      confirmButtonText: 'Confirmar Agotado',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#7f1d1d',
      preConfirm: () => document.getElementById('pk-ago-obs')?.value?.trim() || 'Sin stock físico'
    });
    if (!isConfirmed) return;
    try {
      const r = await API.post('/picking/marcar-agotado-consolidado', { ids: String(lineaId), observacion: obs });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Línea marcada como agotada');
      const p = this._detallePicking;
      if (p?.id) { const updated = await API.get('/picking/' + p.id); this._detallePicking = updated.data || updated; this._renderDetallePicking(p.id, this._detallePicking); }
    } catch(e) { WMS.toast('error', e.message || 'Error al marcar agotado'); }
  },

  async _reabrirOrden(id) {
    if (!confirm('¿Reabrir esta orden de picking? Volverá a estado EnProceso para continuar el alistamiento.')) return;
    try {
      const r = await API.post('/picking/' + id + '/reabrir', {});
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Orden reabierta');
      WMS.closeRightPanel();
      this.show_pedidos();
    } catch(e) { WMS.toast('error', e.message || 'Error al reabrir'); }
  },

  async _dlgEditarCantidad(lineaId, ordenId, nombre, cantSolActual, upc) {
    const upcVal = Math.max(1, parseInt(upc) || 1);
    const { value: nuevaCant, isConfirmed } = await Swal.fire({
      title: 'Editar cantidad solicitada',
      html: `<p style="font-size:13px;margin-bottom:10px;"><b>${WMS.esc(nombre)}</b></p>
             <p style="font-size:12px;color:#64748b;margin-bottom:8px;">
               Actual: <b>${WMS.formatNum(cantSolActual)}</b> cajas
               ${upcVal > 1 ? `&nbsp;·&nbsp;<b>${WMS.formatNum(cantSolActual * upcVal)}</b> und` : ''}
             </p>
             <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Nueva cantidad (cajas):</label>
             <input id="pk-edit-qty" type="number" min="0.001" step="0.001" value="${cantSolActual}"
               class="swal2-input" style="margin:0;width:100%;box-sizing:border-box;">
             ${upcVal > 1 ? '<div style="font-size:11px;color:#64748b;margin-top:6px;">1 caja = ' + upcVal + ' unidades</div>' : ''}`,
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-floppy-disk"></i> Guardar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#1d4ed8',
      preConfirm: () => {
        const v = parseFloat(document.getElementById('pk-edit-qty')?.value);
        if (!v || v <= 0) { Swal.showValidationMessage('Ingresa una cantidad mayor a 0'); return false; }
        return v;
      }
    });
    if (!isConfirmed || !nuevaCant) return;
    try {
      const r = await API.patch('/picking/' + ordenId + '/linea/' + lineaId, { cantidad_solicitada: nuevaCant });
      if (r.error) throw new Error(r.message);
      WMS.toast('success', 'Cantidad actualizada');
      const p = this._detallePicking;
      if (p?.id) { const u = await API.get('/picking/' + p.id); this._detallePicking = u.data || u; this._renderDetallePicking(p.id, this._detallePicking); }
    } catch(e) { WMS.toast('error', e.message || 'Error actualizando cantidad'); }
  },

  async _dlgAgregarRef(ordenId, desdeExpand = false) {
    const { value: payload, isConfirmed } = await Swal.fire({
      title: '<i class="fa-solid fa-plus" style="color:#059669;"></i> Agregar referencia',
      html: '<div style="text-align:left;">' +
              '<label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Producto <span style="color:red">*</span></label>' +
              '<input id="ar-search" class="swal2-input" style="margin:0 0 4px;width:100%;box-sizing:border-box;" placeholder="Buscar por código o nombre...">' +
              '<div id="ar-res" style="max-height:150px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:4px;margin-bottom:8px;display:none;font-size:12px;"></div>' +
              '<div id="ar-sel" style="font-size:11px;color:#059669;font-weight:700;min-height:18px;margin-bottom:8px;"></div>' +
              '<label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Cantidad (cajas) <span style="color:red">*</span></label>' +
              '<input id="ar-qty" type="number" min="0.001" step="0.001" class="swal2-input" style="margin:0;width:100%;box-sizing:border-box;" placeholder="0">' +
            '</div>',
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-plus"></i> Agregar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#059669',
      didOpen: () => {
        window._arProdId = null;
        let t;
        const res = document.getElementById('ar-res');
        // Delegación de clicks en el contenedor — evita el bug de comillas en onclick inline
        res.addEventListener('click', (e) => {
          const item = e.target.closest('[data-prod-id]');
          if (!item) return;
          window._arProdId = parseInt(item.dataset.prodId);
          document.getElementById('ar-sel').textContent = '✓ ' + item.dataset.prodNombre;
          res.style.display = 'none';
        });
        document.getElementById('ar-search').addEventListener('input', (e) => {
          clearTimeout(t);
          const q = e.target.value.trim();
          if (q.length < 2) { res.style.display = 'none'; res.innerHTML = ''; return; }
          t = setTimeout(async () => {
            try {
              const r = await API.get('/param/productos/buscar', 'q=' + encodeURIComponent(q) + '&limit=10');
              const items = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []);
              res.style.display = items.length ? 'block' : 'none';
              res.innerHTML = items.map(pr =>
                `<div data-prod-id="${pr.id}" data-prod-nombre="${WMS.esc(pr.nombre||'')}"
                   style="padding:6px 10px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
                   onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='#fff'">
                  <b>${WMS.esc(pr.codigo||pr.codigo_interno||String(pr.id))}</b> — ${WMS.esc(pr.nombre||'')}
                </div>`
              ).join('') || '<div style="padding:8px;color:#94a3b8;">Sin resultados</div>';
            } catch {}
          }, 300);
        });
      },
      preConfirm: () => {
        const prodId = parseInt(window._arProdId);
        const qty    = parseFloat(document.getElementById('ar-qty')?.value);
        if (!prodId) { Swal.showValidationMessage('Selecciona un producto de la lista'); return false; }
        if (!qty || qty <= 0) { Swal.showValidationMessage('Ingresa una cantidad mayor a 0'); return false; }
        return { producto_id: prodId, cantidad: qty };
      }
    });
    delete window._arProdId;
    if (!isConfirmed || !payload) return;
    try {
      const r = await API.post('/picking/' + ordenId + '/lineas', payload);
      if (r.error) throw new Error(r.message);
      WMS.toast('success', r.message || 'Referencia agregada al picking');
      // Refrescar editor inline si está abierto para este pedido
      if (this._inlineEditorOrdenId === ordenId && this._inlineEditorPlanKey) {
        await this._abrirEditorInline(ordenId, this._inlineEditorPlanKey);
        return;
      }
      // Actualizar fila expandida si está abierta (compatibilidad)
      if (desdeExpand || document.getElementById('expand-' + ordenId)?.style.display !== 'none') {
        await this._recargarExpandRow(ordenId);
      }
      // Actualizar panel derecho si está abierto con este pedido
      const p = this._detallePicking;
      if (p?.id === ordenId || (p?.id && !desdeExpand)) {
        const u = await API.get('/picking/' + (p.id || ordenId));
        this._detallePicking = u.data || u;
        this._renderDetallePicking(p.id || ordenId, this._detallePicking);
      }
    } catch(e) { WMS.toast('error', e.message || 'Error agregando referencia'); }
  },

  // ── EDITOR INLINE DE PEDIDO (dentro del sub-panel de planilla) ────────────
  _inlineEditorOrdenId: null,
  _inlineEditorPlanKey: null,

  async _abrirEditorInline(ordenId, planKey) {
    const cont = document.getElementById('ied-' + planKey);
    if (!cont) { this.verDetalle(ordenId); return; }
    // Mismo pedido ya visible → cerrar
    if (this._inlineEditorOrdenId === ordenId && cont.style.display !== 'none') {
      cont.style.display = 'none';
      this._inlineEditorOrdenId = null;
      this._inlineEditorPlanKey = null;
      return;
    }
    this._inlineEditorOrdenId = ordenId;
    this._inlineEditorPlanKey = planKey;
    cont.style.display = '';
    cont.innerHTML = '<div style="padding:12px;color:#64748b;font-size:.78rem;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando pedido...</div>';
    try {
      const r = await API.get('/picking/' + ordenId);
      this._renderEditorInline(r.data || r, planKey, cont);
    } catch(e) {
      cont.innerHTML = '<div style="padding:12px;color:#dc2626;font-size:.78rem;"><i class="fa-solid fa-circle-exclamation"></i> Error cargando el pedido</div>';
    }
  },

  _renderEditorInline(o, planKey, cont) {
    const id = o.id;
    // Pendiente: Supervisor o Admin. EnProceso (ya asignada): solo Admin — igual que el backend.
    const rolIed = WMS.user?.rol || '';
    const esEditable = o.estado === 'Pendiente'
      ? (rolIed === 'Admin' || rolIed === 'Supervisor')
      : (o.estado === 'EnProceso' && rolIed === 'Admin');
    const lineas = o.detalles || o.lineas || [];

    // cantidad_solicitada = CAJAS; cantidad_pickeada = CAJAS
    // UPC (unidades_caja) viene del producto; se usa para mostrar la conversión a unidades
    const filas = lineas.map(d => {
      const nom   = d.producto?.nombre || d.descripcion || '—';
      const upc   = Math.max(1, parseInt(d.producto?.unidades_caja || d.unid_pedido_empaque) || 1);
      const csol  = parseFloat(d.cantidad_solicitada) || 0;   // cajas
      const cpick = parseFloat(d.cantidad_pickeada)   || 0;   // cajas
      const cpend = Math.max(0, csol - cpick);                // cajas pendientes
      const isPend = ['Pendiente', 'EnProceso', 'Faltante'].includes(d.estado);
      const puedeEliminar = (parseFloat(d.cantidad_pickeada) || 0) === 0;
      const bStyle = d.estado === 'Completado' ? 'background:#dcfce7;color:#166534;'
                   : d.estado === 'Faltante'   ? 'background:#fee2e2;color:#991b1b;'
                   :                             'background:#fef9c3;color:#854d0e;';
      // Helper visual: convierte cajas a unidades para el operario
      const cajasAUnd = (caj) => upc > 1 ? `<span style="font-size:.65rem;color:#64748b;">${caj * upc} und</span>` : '';

      return `<tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:6px 10px;font-size:.78rem;font-weight:600;">${WMS.esc(nom)}
          ${upc > 1 ? `<div style="font-size:.65rem;color:#64748b;">UPC: ${upc} und/caj</div>` : ''}
        </td>
        <td style="padding:6px 8px;text-align:center;">
          ${esEditable && isPend
            ? `<div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                 <input type="number" min="0.001" step="0.001" id="ied-qty-${d.id}" value="${csol}"
                   onkeydown="if(event.key==='Enter')WMS_MODULES.picking._guardarLineaInline(${id},${d.id},'${planKey}')"
                   style="width:72px;text-align:center;border:1px solid #cbd5e1;border-radius:4px;padding:3px 6px;font-size:.8rem;font-weight:700;">
                 <span style="font-size:.63rem;color:#64748b;font-weight:600;">cajas</span>
                 ${cajasAUnd(csol)}
               </div>`
            : `<div style="font-weight:700;font-size:.8rem;">${WMS.formatNum(csol)} caj</div>
               ${cajasAUnd(csol)}`}
        </td>
        <td style="padding:6px 8px;text-align:center;font-size:.78rem;">
          <div style="font-weight:700;color:${cpick>0?'#059669':'#94a3b8'};">${WMS.formatNum(cpick)} caj</div>
          ${cajasAUnd(cpick)}
        </td>
        <td style="padding:6px 8px;text-align:center;font-size:.78rem;">
          <div style="font-weight:700;color:${cpend>0?'#dc2626':'#94a3b8'};">${WMS.formatNum(cpend)} caj</div>
          ${cajasAUnd(cpend)}
        </td>
        <td style="padding:6px 8px;font-size:.75rem;color:#64748b;">${WMS.esc(d.ambiente||'—')}</td>
        <td style="padding:6px 8px;"><span style="font-size:.7rem;font-weight:600;padding:1px 6px;border-radius:3px;${bStyle}">${WMS.esc(d.estado||'Pendiente')}</span></td>
        <td style="padding:6px 8px;text-align:center;white-space:nowrap;">
          ${esEditable && isPend ? `
            <button title="Guardar cantidad" onclick="WMS_MODULES.picking._guardarLineaInline(${id},${d.id},'${planKey}')"
              style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:3px;padding:3px 8px;cursor:pointer;color:#166534;font-size:.7rem;margin-right:3px;">
              <i class="fa-solid fa-check"></i>
            </button>
            ${puedeEliminar
              ? `<button title="Eliminar línea" onclick="WMS_MODULES.picking._eliminarLineaInline(${id},${d.id},'${planKey}')"
                   style="background:#fee2e2;border:1px solid #fecaca;border-radius:3px;padding:3px 8px;cursor:pointer;color:#991b1b;font-size:.7rem;">
                   <i class="fa-solid fa-trash-can"></i>
                 </button>`
              : `<span title="Ya tiene unidades separadas, no se puede eliminar" style="color:#94a3b8;font-size:.7rem;"><i class="fa-solid fa-lock"></i></span>`
            }` : '<span style="color:#94a3b8;font-size:.7rem;">—</span>'}
        </td>
      </tr>`;
    }).join('') || `<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;font-size:.78rem;">Sin líneas</td></tr>`;

    cont.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <div style="font-weight:700;font-size:.78rem;color:#1e40af;">
          <i class="fa-solid fa-list-check"></i> ${WMS.esc(o.numero_orden||'')} — ${WMS.esc(o.sucursal_entrega||o.cliente||'')}
          ${esEditable ? `<span style="font-size:.68rem;background:#dcfce7;color:#166534;padding:1px 6px;border-radius:3px;margin-left:6px;">Editable</span>` : ''}
        </div>
        <button onclick="document.getElementById('ied-${planKey}').style.display='none';WMS_MODULES.picking._inlineEditorOrdenId=null;"
          style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:.9rem;" title="Cerrar">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:4px;background:#fff;">
        <table style="width:100%;border-collapse:collapse;">
          <thead style="background:#f1f5f9;font-size:.7rem;text-transform:uppercase;color:#64748b;font-weight:700;">
            <tr>
              <th style="padding:6px 10px;text-align:left;">Producto / UPC</th>
              <th style="padding:6px 8px;text-align:center;">Solicitado<br><span style="font-weight:400;text-transform:none;font-size:.62rem;">(ingresa en cajas)</span></th>
              <th style="padding:6px 8px;text-align:center;">Pickeado</th>
              <th style="padding:6px 8px;text-align:center;color:#dc2626;">Pendiente</th>
              <th style="padding:6px 8px;">Ambiente</th>
              <th style="padding:6px 8px;">Estado</th>
              <th style="padding:6px 8px;text-align:center;">Acciones</th>
            </tr>
          </thead>
          <tbody>${filas}</tbody>
        </table>
      </div>
      ${esEditable ? `<div style="margin-top:8px;text-align:right;">
        <button onclick="WMS_MODULES.picking._dlgAgregarRef(${id},false)"
          style="background:#059669;color:#fff;border:none;border-radius:4px;padding:5px 14px;font-size:.78rem;cursor:pointer;font-weight:600;">
          <i class="fa-solid fa-plus"></i> Agregar referencia
        </button>
      </div>` : ''}`;
  },

  async _guardarLineaInline(ordenId, lineaId, planKey) {
    const input = document.getElementById('ied-qty-' + lineaId);
    if (!input) return;
    const cantidad = parseFloat(input.value);
    if (!cantidad || cantidad <= 0) { WMS.toast('warning', 'Cantidad inválida'); return; }
    try {
      await API.patch('/picking/' + ordenId + '/linea/' + lineaId, { cantidad_solicitada: cantidad });
      WMS.toast('success', 'Cantidad actualizada');
      // Recargar toda la tabla para actualizar progreso/pendiente en la fila de planilla
      await this._cargarPedidos();
      // Re-abrir el editor inline en la planilla recién recargada
      setTimeout(() => {
        const cont = document.getElementById('ied-' + planKey);
        if (cont) this._abrirEditorInline(ordenId, planKey);
      }, 150);
    } catch(e) { WMS.toast('error', e.message || 'Error actualizando cantidad'); }
  },

  async _eliminarLineaInline(ordenId, lineaId, planKey) {
    const { isConfirmed } = await Swal.fire({
      title: '¿Eliminar línea?', text: 'Se revertirá la reserva de inventario para esta referencia.',
      icon: 'warning', showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-trash-can"></i> Eliminar',
      cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626',
    });
    if (!isConfirmed) return;
    try {
      const r = await API.delete('/picking/' + ordenId + '/linea/' + lineaId);
      WMS.toast('success', r.message || 'Línea eliminada');
      if (r.data?.orden_eliminada) {
        const cont = document.getElementById('ied-' + planKey);
        if (cont) cont.style.display = 'none';
        this._inlineEditorOrdenId = null;
        this._inlineEditorPlanKey = null;
        this._cargarPedidos();
      } else {
        await this._abrirEditorInline(ordenId, planKey);
      }
    } catch(e) { WMS.toast('error', e.message || 'Error eliminando línea'); }
  },

  async _cerrarPlanilla(planilla) {
    const ok = await Swal.fire({
      title: '¿Cerrar planilla #' + planilla + '?',
      html: 'Todas las líneas deben estar <b>separadas</b> o marcadas como <b>Faltante/Agotado</b>.<br>' +
            'Líneas pendientes <b>bloquearán el cierre</b>.',
      icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Sí, cerrar planilla', cancelButtonText: 'Cancelar',
      confirmButtonColor: '#059669',
    });
    if (!ok.isConfirmed) return;
    try {
      await API.post('/picking/planilla/' + planilla + '/completar', {});
      WMS.toast('success', 'Planilla #' + planilla + ' cerrada');
      this.show_pedidos();
    } catch(e) {
      const d = e.data || {};
      const lineas = d.lineas_bloqueadas || [];
      if (lineas.length) {
        // Mostrar tabla con detalle de líneas bloqueantes
        const filas = lineas.map(l =>
          `<tr>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;">${WMS.esc(l.numero_orden||'')}</td>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;">${WMS.esc(l.codigo||'')}</td>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(l.nombre_producto||'')}</td>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;text-align:center;">${l.cantidad_solicitada}</td>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;text-align:center;color:${l.cantidad_pickeada>0?'#16a34a':'#dc2626'};">${l.cantidad_pickeada}</td>
            <td style="padding:3px 7px;border-bottom:1px solid #e5e7eb;font-size:11px;"><span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;">${WMS.esc(l.estado||'')}</span></td>
          </tr>`
        ).join('');

        const html = `<p style="color:#6b7280;margin-bottom:8px;font-size:12px;">${WMS.esc(e.message||'')}</p>
          <div style="overflow-x:auto;max-height:250px;">
          <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f9fafb;">
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:left;">Pedido</th>
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:left;">Código</th>
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:left;">Producto</th>
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:center;">Solicitado</th>
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:center;">Pickeado</th>
              <th style="padding:4px 7px;font-size:10px;color:#6b7280;text-align:left;">Estado</th>
            </tr></thead>
            <tbody>${filas}</tbody>
          </table></div>`;

        if (d.puede_forzar) {
          const opciones = await Swal.fire({
            title: `${lineas.length} línea(s) sin separar`,
            html,
            icon: 'warning',
            width: '680px',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-lock-open"></i> Liberar líneas (reabrir)',
            denyButtonText: '<i class="fa-solid fa-xmark-circle"></i> Forzar cierre (→ Agotado)',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#2563eb',
            denyButtonColor: '#dc2626',
          });
          if (opciones.isConfirmed) {
            // Liberar líneas para volver a separar
            try {
              const r2 = await API.post('/picking/planilla/' + planilla + '/liberar-vacias', {});
              WMS.toast('success', `${r2.data?.liberadas||0} línea(s) liberadas — ya pueden separarse`);
              this.show_pedidos();
            } catch(e2) { WMS.toast('error', e2.message || 'Error al liberar'); }
          } else if (opciones.isDenied) {
            // Forzar cierre marcando todo como Agotado
            try {
              await API.post('/picking/planilla/' + planilla + '/completar?forzar=1', {});
              WMS.toast('success', 'Planilla #' + planilla + ' cerrada (líneas → Agotado)');
              this.show_pedidos();
            } catch(e2) { WMS.toast('error', e2.message || 'Error al forzar cierre'); }
          }
        } else {
          // Solo informar — el auxiliar debe resolver
          await Swal.fire({
            title: `${lineas.length} línea(s) sin separar`,
            html,
            icon: 'warning',
            width: '680px',
            confirmButtonText: 'Entendido',
          });
        }
      } else {
        WMS.toast('error', e.message || 'Error al cerrar planilla');
      }
    }
  },

  async _reabrirPlanilla(ordenIds) {
    if (!Array.isArray(ordenIds) || !ordenIds.length) return;
    const ok = await Swal.fire({
      title: '¿Reabrir planilla?',
      text: 'Todas las órdenes de la planilla volverán a estado EnProceso.',
      icon: 'warning', showCancelButton: true,
      confirmButtonText: 'Sí, reabrir', cancelButtonText: 'Cancelar',
      confirmButtonColor: '#d97706',
    });
    if (!ok.isConfirmed) return;
    const results = await Promise.allSettled(ordenIds.map(id => API.post('/picking/' + id + '/reabrir', {})));
    const ok2 = results.filter(r => r.status === 'fulfilled').length;
    if (ok2 > 0) { WMS.toast('success', 'Planilla reabierta (' + ok2 + ' orden(es))'); this.show_pedidos(); }
    else WMS.toast('error', results[0]?.reason?.message || 'No se pudo reabrir la planilla');
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
    } catch(e) { if (e.isSessionExpired) return; console.error('Error cargando parámetros', e); }

    // Guardamos los IDs para usarlos en confirmarAsignacionPlanilla
    window._assignPlanillaIds = ordenIds;
    window._assignPlanillaKey = planilla;

    WMS.showRightPanel(`Asignar Planilla: ${planilla}`, `
      <div style="margin-bottom:12px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:13px;color:#1e40af;">
        <i class="fa-solid fa-layer-group"></i>
        <strong>${ordenIds.length}</strong> pedido(s) en esta planilla serán asignados al auxiliar seleccionado.
        <div style="margin-top:4px;font-size:11px;color:#3730a3;">Los pedidos ya asignados previamente serán reasignados.</div>
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

      <div id="split-config" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:14px;margin-top:8px;">
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
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; max-height:150px; overflow-y:auto; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
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
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
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
        const data = r.data || {};
        const resMsg = [`Líneas asignadas: ${data.asignadas || 0}`];
        if (data.inventario_reservado > 0) resMsg.push(`${data.inventario_reservado} unidades reservadas`);
        if (data.faltantes_detectados > 0) resMsg.push(`⚠ ${data.faltantes_detectados} agotados`);
        WMS.toast(data.faltantes_detectados > 0 ? 'warning' : 'success', resMsg.join(' • '));
        if ((data.faltantes_detectados || 0) > 0) this._mostrarAlertaAgotados(data);
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

      WMS.closeRightPanel();
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

    WMS.showRightPanel('Asignar Orden #' + id, `
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
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
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
      else { WMS.toast('success', 'Picking asignado'); WMS.closeRightPanel(); this.show_pedidos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── ASIGNAR RUTA A PLANILLA ──────────────────────────────────────────────
  async asignarRutaPlanilla(planilla, ordenIds) {
    let rutas = [];
    try {
      const rr = await API.get('/param/rutas');
      rutas = (rr.data || rr || []).filter(r => r.activo);
    } catch(e) { if (e.isSessionExpired) return; console.error('Error cargando rutas', e); }

    WMS.showRightPanel(`Asignar Ruta — Planilla ${planilla}`, `
      <div style="margin-bottom:12px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:13px;color:#1e40af;">
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
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
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
      WMS.closeRightPanel();
      this.show_pedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error asignando ruta');
    }
  },

  async completarPicking(id) {
    const ok = await Swal.fire({
      title: '¿Cerrar orden de picking?',
      html: 'Todas las líneas deben estar <b>Separadas</b> o marcadas como <b>Faltante/Agotado</b>.<br>' +
            'Líneas pendientes o en proceso <b>bloquearán el cierre</b>.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, cerrar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#059669',
    });
    if (!ok.isConfirmed) return;
    try {
      const r = await API.post('/picking/' + id + '/completar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Picking cerrado');
      WMS.closeRightPanel();
      this.show_pedidos();
    } catch(e) { WMS.toast('error', e.message || 'Error al cerrar picking'); }
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

  // ── PEDIDO MANUAL ────────────────────────────────────────────────────────

  nuevoPedidoManual() {
    WMS.setBreadcrumb('picking', 'Nuevo Pedido Manual');
    WMS.setToolbar('');
    WMS.setContent(`
      <div style="padding:20px;overflow:auto;height:calc(100vh - 120px);">
        <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);max-width:960px;margin:0 auto;">

          <!-- Header -->
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #dbeafe;">
            <div style="width:44px;height:44px;border-radius:4px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-size:20px;">
              <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <div>
              <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e3a5f;">Nuevo Pedido Manual</h2>
              <p style="margin:0;font-size:12px;color:#64748b;">La fecha se asigna automáticamente al día de hoy</p>
            </div>
          </div>

          <!-- Campos cabecera -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:24px;">
            <div class="form-group">
              <label class="form-label">N° Pedido <span style="color:#94a3b8;font-weight:400;">(opcional)</span></label>
              <input id="pm-numero" class="form-control" placeholder="Ej: FAC-2026-001">
              <div style="font-size:10px;color:#94a3b8;margin-top:3px;">Si se deja vacío se genera automáticamente</div>
            </div>
            <div class="form-group">
              <label class="form-label">Cliente / Sucursal <span style="color:#dc2626;">*</span></label>
              <div style="position:relative;">
                <input id="pm-cliente" class="form-control" placeholder="Buscar por nombre, NIT o ciudad..."
                       autocomplete="off" oninput="WMS_MODULES.picking._pmClienteInput()">
                <input type="hidden" id="pm-cliente-id" value="">
                <div id="pm-cliente-drop"
                     style="display:none;position:absolute;left:0;right:0;z-index:400;background:#fff;
                            border:1px solid #e2e8f0;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.12);
                            max-height:220px;overflow-y:auto;top:calc(100% + 2px);min-width:320px;"></div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Ruta</label>
              <select id="pm-ruta" class="form-control">
                <option value="">— Cargando rutas… —</option>
              </select>
            </div>
          </div>

          <!-- Tabla de productos -->
          <div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:800;color:#1e3a5f;font-size:14px;">
              <i class="fa-solid fa-list" style="color:#1d4ed8;margin-right:6px;"></i>Productos del Pedido
            </span>
            <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.picking._pmAgregarLinea()">
              <i class="fa-solid fa-plus"></i> Agregar Producto
            </button>
          </div>

          <div id="pm-lines-wrap" style="border:1px solid #e2e8f0;border-radius:4px;overflow:visible;margin-bottom:20px;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead>
                <tr style="background:#eff6ff;">
                  <th style="padding:8px 10px;text-align:left;color:#64748b;font-weight:700;">Producto</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:100px;">Ambiente</th>
                  <th style="padding:8px 10px;text-align:center;color:#64748b;font-weight:700;width:100px;">Cantidad</th>
                  <th style="padding:8px 10px;width:36px;"></th>
                </tr>
              </thead>
              <tbody id="pm-lines-body">
                <tr id="pm-empty-row">
                  <td colspan="3" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">
                    Haz clic en "Agregar Producto" para añadir líneas al pedido
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="form-group" style="margin-bottom:20px;">
            <label class="form-label">Observaciones <span style="color:#94a3b8;font-weight:400;">(opcional)</span></label>
            <textarea id="pm-observaciones" class="form-control" rows="2"
              placeholder="Notas visibles durante todo el proceso: picking, certificación y despacho..."></textarea>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button class="btn btn-ghost" onclick="WMS_MODULES.picking.show_pedidos()">
              <i class="fa-solid fa-arrow-left"></i> Cancelar
            </button>
            <button class="btn btn-primary" onclick="WMS_MODULES.picking._pmGuardar()">
              <i class="fa-solid fa-save"></i> Guardar Pedido
            </button>
          </div>

        </div>
      </div>`);

    // Agregar la primera línea vacía automáticamente
    this._pmAgregarLinea();
    // Cargar rutas y clientes maestros en paralelo
    this._pmCargarDatosMaestros();
  },

  _pmClientes: [],
  _pmClienteMatches: [],
  _pmTimers: {},
  _pmCache: {},

  async _pmCargarDatosMaestros() {
    try {
      const [rR, cR] = await Promise.all([
        API.get('/param/rutas'),
        API.get('/param/clientes'),
      ]);
      // Poblar select de rutas
      const sel = document.getElementById('pm-ruta');
      if (sel) {
        const rutas = Array.isArray(rR.data) ? rR.data : [];
        sel.innerHTML = '<option value="">— Sin ruta —</option>' +
          rutas.map(r => `<option value="${WMS.esc(r.nombre)}">${WMS.esc(r.nombre)}</option>`).join('');
      }
      // Cachear clientes para autocompletado
      this._pmClientes = Array.isArray(cR.data) ? cR.data : [];
    } catch(e) {
      if (e.isSessionExpired) return;
      const sel = document.getElementById('pm-ruta');
      if (sel) sel.innerHTML = '<option value="">— Sin ruta —</option>';
    }
  },

  _pmClienteInput() {
    const q = (document.getElementById('pm-cliente')?.value || '').trim().toLowerCase();
    const drop = document.getElementById('pm-cliente-drop');
    const idInp = document.getElementById('pm-cliente-id');
    if (!drop) return;
    if (idInp) idInp.value = '';
    if (q.length < 1) { drop.style.display = 'none'; this._pmClienteMatches = []; return; }

    const matches = (this._pmClientes || []).filter(c =>
      (c.razon_social || '').toLowerCase().includes(q) ||
      (c.nit          || '').toLowerCase().includes(q) ||
      (c.ciudad       || '').toLowerCase().includes(q)
    ).slice(0, 15);

    this._pmClienteMatches = matches;
    if (!matches.length) { drop.style.display = 'none'; return; }

    drop.innerHTML = matches.map((c, i) => `
      <div style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12px;"
           onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''"
           onclick="WMS_MODULES.picking._pmSelectCliente(${i})">
        <div style="font-weight:600;color:#1e293b;">${WMS.esc(c.razon_social)}</div>
        <div style="color:#64748b;font-size:11px;">
          ${c.nit   ? 'NIT: ' + WMS.esc(c.nit) : ''}
          ${c.ciudad ? ' · ' + WMS.esc(c.ciudad) : ''}
          ${c.ruta  ? ' · <span style="color:#1d4ed8;">Ruta: ' + WMS.esc(c.ruta.nombre) + '</span>' : ''}
        </div>
      </div>`).join('');
    drop.style.display = 'block';

    const closeHandler = e => {
      if (!e.target.closest('#pm-cliente-drop') && !e.target.closest('#pm-cliente')) {
        drop.style.display = 'none';
        document.removeEventListener('click', closeHandler);
      }
    };
    document.addEventListener('click', closeHandler);
  },

  _pmSelectCliente(i) {
    const c = this._pmClienteMatches[i];
    if (!c) return;
    const inp   = document.getElementById('pm-cliente');
    const idInp = document.getElementById('pm-cliente-id');
    const drop  = document.getElementById('pm-cliente-drop');
    if (inp)   inp.value   = c.razon_social;
    if (idInp) idInp.value = c.id;
    if (drop)  drop.style.display = 'none';
    // Auto-seleccionar ruta si el cliente tiene una asignada
    if (c.ruta?.nombre) {
      const sel = document.getElementById('pm-ruta');
      if (sel) sel.value = c.ruta.nombre;
    }
  },

  _pmAgregarLinea() {
    const tbody   = document.getElementById('pm-lines-body');
    const emptyRow = document.getElementById('pm-empty-row');
    if (emptyRow) emptyRow.remove();
    const idx = Date.now();
    const tr  = document.createElement('tr');
    tr.id = 'pm-line-' + idx;
    tr.style.borderBottom = '1px solid #f1f5f9';
    tr.innerHTML = `
      <td style="padding:6px 8px;position:relative;">
        <input class="form-control pm-prod-name" id="pm-pn-${idx}" style="font-size:12px;"
               placeholder="Buscar producto por nombre o código..." autocomplete="off"
               oninput="WMS_MODULES.picking._pmProdInput('${idx}')">
        <input type="hidden" class="pm-pid" id="pm-pid-${idx}" value="">
        <input type="hidden" class="pm-factor-udm" id="pm-fudm-${idx}" value="">
        <input type="hidden" class="pm-unidad-cont" id="pm-ucont-${idx}" value="">
        <input type="hidden" class="pm-ambiente" id="pm-amb-${idx}" value="">
        <div id="pm-drop-${idx}"
             style="display:none;position:absolute;left:0;right:0;z-index:300;background:#fff;
                    border:1px solid #e2e8f0;border-radius:4px;box-shadow:0 8px 24px rgba(0,0,0,.12);
                    max-height:220px;overflow-y:auto;top:calc(100% + 2px);min-width:340px;"></div>
      </td>
      <td style="padding:6px 8px;text-align:center;">
        <span id="pm-amb-label-${idx}" style="font-size:11px;font-weight:600;padding:3px 6px;border-radius:12px;background:#f1f5f9;color:#64748b;">—</span>
      </td>
      <td style="padding:6px 8px;text-align:center;min-width:160px;">
        <div id="pm-qty-wrap-${idx}">
          <input type="number" class="form-control pm-qty"
                 style="width:85px;margin:0 auto;text-align:center;font-size:12px;" min="0.001" step="0.001" value="1"
                 oninput="WMS_MODULES.picking._pmActualizarUe('${idx}')">
          <div id="pm-ue-row-${idx}" style="display:none;margin-top:4px;">
            <div style="font-size:10px;color:#64748b;text-align:center;margin-bottom:2px;" id="pm-ue-label-${idx}">U/E:</div>
            <input type="number" class="form-control pm-qty-ue"
                   style="width:85px;margin:0 auto;text-align:center;font-size:12px;border-color:#86efac;" min="0.001" step="0.001"
                   placeholder="U/E" id="pm-qty-ue-${idx}"
                   oninput="WMS_MODULES.picking._pmUeToUnidades('${idx}')">
          </div>
        </div>
      </td>
      <td style="text-align:center;padding:6px;">
        <button class="btn btn-xs btn-danger-soft" onclick="this.closest('tr').remove()"
                title="Quitar línea"><i class="fa-solid fa-times"></i></button>
      </td>`;
    tbody.appendChild(tr);
    document.getElementById('pm-pn-' + idx)?.focus();
  },

  _pmProdInput(idx) {
    clearTimeout(this._pmTimers[idx]);
    this._pmTimers[idx] = setTimeout(() => this._pmSearchProduct(idx), 300);
  },

  async _pmSearchProduct(idx) {
    const input = document.getElementById('pm-pn-' + idx);
    const drop  = document.getElementById('pm-drop-' + idx);
    if (!input || !drop) return;
    const q = input.value.trim();
    if (q.length < 2) { drop.style.display = 'none'; return; }
    try {
      const r = await API.get('/param/productos/buscar?q=' + encodeURIComponent(q) + '&limit=200');
      const items = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []);
      this._pmCache[idx] = items;
      if (!items.length) {
        drop.innerHTML = '<div style="padding:10px 14px;color:#94a3b8;font-size:12px;font-style:italic;">Sin resultados para "' + WMS.esc(q) + '"</div>';
      } else {
        drop.innerHTML = items.map((p, i) => `
          <div style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12px;"
               onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''"
               onclick="WMS_MODULES.picking._pmSelectProduct('${idx}', ${i})">
            <div style="font-weight:600;color:#1e293b;">${WMS.esc(p.nombre || p.descripcion || '')}</div>
            <div style="color:#64748b;font-size:11px;">
              Cód: ${WMS.esc(p.codigo_interno || '—')}
              ${p.ean_principal ? ' · EAN: ' + WMS.esc(p.ean_principal) : ''}
              ${p.unidades_caja > 1 ? ' · ' + p.unidades_caja + ' und/cj' : ''}
            </div>
          </div>`).join('');
      }
      drop.style.display = 'block';
      const closeHandler = (e) => {
        if (!e.target.closest('#pm-drop-' + idx) && !e.target.closest('#pm-pn-' + idx)) {
          drop.style.display = 'none';
          document.removeEventListener('click', closeHandler);
        }
      };
      document.addEventListener('click', closeHandler);
    } catch(e) { if (e.isSessionExpired) return; }
  },

  _pmSelectProduct(idx, i) {
    const p     = (this._pmCache[idx] || [])[i];
    if (!p) return;
    const input = document.getElementById('pm-pn-' + idx);
    const pid   = document.getElementById('pm-pid-' + idx);
    const drop  = document.getElementById('pm-drop-' + idx);
    const amb   = document.getElementById('pm-amb-' + idx);
    const ambLbl= document.getElementById('pm-amb-label-' + idx);
    
    if (input) input.value = p.nombre || p.descripcion || '';
    if (pid)   pid.value   = p.id;
    if (amb)   amb.value   = p.ambiente_nombre || 'SECO';
    if (ambLbl) {
      const a = p.ambiente_nombre || 'SECO';
      ambLbl.textContent = a;
      if (a === 'REFRIGERADO') { ambLbl.style.background = '#dbeafe'; ambLbl.style.color = '#1e40af'; }
      else if (a === 'CONGELADO') { ambLbl.style.background = '#f3e8ff'; ambLbl.style.color = '#6b21a8'; }
      else { ambLbl.style.background = '#ffedd5'; ambLbl.style.color = '#9a3412'; }
    }
    if (drop)  drop.style.display = 'none';

    // Poblar factor_udm y mostrar campo U/E si aplica
    const fudmInp  = document.getElementById('pm-fudm-' + idx);
    const ucontInp = document.getElementById('pm-ucont-' + idx);
    const ueRow    = document.getElementById('pm-ue-row-' + idx);
    const ueLabel  = document.getElementById('pm-ue-label-' + idx);
    const factorUdm     = parseFloat(p.factor_udm || 0);
    const unidContenido = p.unidad_contenido || '';
    if (fudmInp)  fudmInp.value  = factorUdm || '';
    if (ucontInp) ucontInp.value = unidContenido;
    if (ueRow) {
      ueRow.style.display = factorUdm > 0 ? 'block' : 'none';
      if (ueLabel && factorUdm > 0) ueLabel.textContent = `en ${unidContenido || 'U/E'} (×${factorUdm}):`;
    }
    // Enfocar campo U/E si existe, sino cantidad normal
    if (factorUdm > 0) {
      document.getElementById('pm-qty-ue-' + idx)?.select();
    } else {
      const tr = document.getElementById('pm-line-' + idx);
      if (tr) tr.querySelector('.pm-qty')?.select();
    }
  },

  _pmUeToUnidades(idx) {
    const factorUdm = parseFloat(document.getElementById('pm-fudm-' + idx)?.value || '0') || 0;
    const cantUe    = parseFloat(document.getElementById('pm-qty-ue-' + idx)?.value || '0') || 0;
    const qtyInput  = document.getElementById('pm-line-' + idx)?.querySelector('.pm-qty');
    if (!factorUdm || !qtyInput) return;
    const unidades = cantUe > 0 ? cantUe / factorUdm : 0;
    qtyInput.value = unidades > 0 ? unidades.toFixed(4).replace(/\.?0+$/, '') : '';
  },

  _pmActualizarUe(idx) {
    const ueInput = document.getElementById('pm-qty-ue-' + idx);
    if (ueInput) ueInput.value = '';
  },

  async _pmGuardar() {
    const numero   = document.getElementById('pm-numero')?.value.trim();
    const cliente  = document.getElementById('pm-cliente')?.value.trim();
    const ruta     = document.getElementById('pm-ruta')?.value.trim();
    const observaciones = document.getElementById('pm-observaciones')?.value.trim();

    if (!cliente) {
      WMS.toast('warning', 'El campo Cliente / Sucursal es obligatorio');
      document.getElementById('pm-cliente')?.focus();
      return;
    }

    const detalles = [];
    document.querySelectorAll('#pm-lines-body tr[id^="pm-line-"]').forEach(tr => {
      const pid = tr.querySelector('.pm-pid')?.value;
      const qty = parseFloat(tr.querySelector('.pm-qty')?.value || '0');
      if (pid && qty > 0) detalles.push({ producto_id: parseInt(pid), cantidad: qty });
    });

    if (!detalles.length) {
      WMS.toast('warning', 'Agrega al menos un producto con cantidad mayor a 0');
      return;
    }

    const btn = document.querySelector('button[onclick*="_pmGuardar"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...'; }

    try {
      await API.post('/picking', {
        numero_pedido    : numero || null,
        cliente,
        sucursal_entrega : cliente,
        ruta             : ruta || null,
        fecha_requerida  : new Date().toISOString().split('T')[0],
        observaciones    : observaciones || null,
        detalles,
      });
      WMS.toast('success', 'Pedido creado correctamente');
      this.show_pedidos();
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', e.message || 'Error al guardar el pedido');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Guardar Pedido'; }
    }
  },

  // ── IMPORTACIÓN MASIVA DE PEDIDOS (Archivo Plano) ────────────────────────
  _importPreviewData: null,

  async importarPedidos() {
    const CAMPOS_REQUERIDOS = [
      { key: 'numero_factura',      label: 'Num Pedido',          icon: 'fa-file-invoice', color: '#6366f1' },
      { key: 'cliente',             label: 'Sucursal Entrega',    icon: 'fa-building',     color: '#0ea5e9' },
      { key: 'producto',            label: 'Referencia (EAN)',    icon: 'fa-barcode',      color: '#14b8a6' },
      { key: 'cantidad',            label: 'UNID Pedido',         icon: 'fa-cubes',        color: '#f97316' },
      { key: 'unid_pedido_empaque', label: 'UNID Pedido Empaque', icon: 'fa-box',          color: '#8b5cf6' },
      { key: 'unid_pedido_total',   label: 'UNID Pedido Total',   icon: 'fa-layer-group',  color: '#10b981' },
    ];

    const camposGrid = CAMPOS_REQUERIDOS.map(c => `
      <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:${c.color}10;border:1px solid ${c.color}30;border-radius:4px;">
        <i class="fa-solid ${c.icon}" style="color:${c.color};font-size:14px;width:18px;text-align:center;"></i>
        <span style="font-size:12px;font-weight:600;color:#1e293b;">${c.label}</span>
      </div>
    `).join('');

    WMS.showModal('Importar Pedidos para Picking', `
      <div style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border:1px solid #bfdbfe;border-radius:4px;margin-bottom:14px;">
          <i class="fa-solid fa-file-lines" style="font-size:22px;color:#3b82f6;"></i>
          <div>
            <div style="font-weight:700;color:#1e40af;font-size:14px;">Importación Masiva — Archivo Plano</div>
            <div style="font-size:12px;color:#3b82f6;margin-top:2px;">Suba un archivo CSV o TXT separado por punto y coma (;) o coma (,)</div>
          </div>
        </div>

        <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:12px;color:#166534;margin-bottom:14px;">
          <i class="fa-solid fa-table" style="margin-right:6px;color:#10b981;"></i>
          <strong>Mapeo de Campos (Sistema ← Archivo):</strong>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px 20px;margin-top:6px;font-size:11px;">
            <span>Numero Factura ← <strong>Num Pedido</strong></span>
            <span>Cliente ← <strong>Sucursal Entrega</strong></span>
            <span>Planilla ← <strong>Num Pedido</strong></span>
            <span>Producto ← <strong>Referencia (EAN)</strong></span>
            <span>Cantidad ← <strong>UNID Pedido</strong></span>
            <span>Empaque / Total ← <strong>UNID Pedido Empaque / Total</strong></span>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">
            <i class="fa-solid fa-diagram-project" style="margin-right:6px;color:#6366f1;"></i>Columnas requeridas en el archivo
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px;">
            ${camposGrid}
          </div>
        </div>

        <div style="padding:10px 14px;background:#fefce8;border:1px solid #fde68a;border-radius:4px;font-size:12px;color:#92400e;margin-bottom:14px;">
          <i class="fa-solid fa-lightbulb" style="margin-right:6px;color:#f59e0b;"></i>
          <strong>Nota:</strong> El archivo puede tener columnas adicionales — solo se leerán las 6 listadas arriba.
          Los pedidos se agrupan por <strong>Num Pedido</strong>: varias filas del mismo número forman una sola orden con múltiples referencias. Al asignar, el sistema reserva inventario y detecta faltantes automáticamente.
        </div>

        <div class="form-group">
          <label class="form-label" style="font-weight:700;">
            <i class="fa-solid fa-cloud-arrow-up" style="color:#3b82f6;margin-right:6px;"></i>Archivo CSV / TXT
            <span class="required">*</span>
          </label>
          <div id="pick-dropzone" style="border:2px dashed #cbd5e1;border-radius:4px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;
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

        <div id="pick-file-info" style="display:none;margin-top:10px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;">
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
        <div id="pick-preview-table" style="max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:4px;"></div>
        <div id="pick-preview-summary" style="margin-top:10px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:12px;color:#1e40af;"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <a href="/WMS_FENIX/public/api/picking/template?token=${encodeURIComponent(localStorage.getItem('wms_token'))}" target="_blank" class="btn btn-secondary" style="gap:6px;"><i class="fa-solid fa-download"></i> Plantilla</a>
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

      // Auto-detect column mappings — solo campos requeridos, aliases exactos para evitar falsos positivos
      const MAPEO = {
        numero_factura:      ['num pedido', 'numero pedido', 'nro pedido', 'num factura', 'numero factura', 'nro factura', 'pedido', 'factura'],
        cliente:             ['sucursal entrega', 'sucursal', 'cliente', 'nombre cliente', 'punto entrega', 'destino', 'razon social'],
        producto:            ['referencia', 'ean', 'codigo barras', 'codigo_barras', 'codigo producto', 'cod producto', 'cod_producto'],
        cantidad:            ['unid pedido', 'unid_pedido', 'cantidad', 'cant', 'qty', 'unidades pedido', 'unidades'],
        unid_pedido_empaque: ['unid pedido empaque', 'unid_pedido_empaque', 'cajas pedidas', 'cajas pedido', 'unidades empaque', 'empaque'],
        unid_pedido_total:   ['unid pedido total', 'unid_pedido_total', 'total unidades pedidas', 'unid total pedidas'],
      };

      // Match: exacto primero; si no, partial solo para aliases largos (≥8 chars) para evitar colisiones
      const colMap = {};
      for (const [field, aliases] of Object.entries(MAPEO)) {
        const idx = headers.findIndex(h => {
          const hl = h.toLowerCase().trim();
          return aliases.some(a => hl === a || (a.length >= 8 && hl.includes(a)));
        });
        if (idx >= 0) colMap[field] = idx;
      }

      // Count mapped fields
      const mapped = Object.keys(colMap).length;
      const total = Object.keys(MAPEO).length; // 6

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
        numero_factura:      'Num Pedido',
        cliente:             'Sucursal Entrega',
        producto:            'Referencia (EAN)',
        cantidad:            'UNID Pedido',
        unid_pedido_empaque: 'UNID Pedido Empaque',
        unid_pedido_total:   'UNID Pedido Total',
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
      const allOk = mapped === total;
      document.getElementById('pick-preview-summary').innerHTML = `
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
          <span>
            <i class="fa-solid ${allOk ? 'fa-circle-check' : 'fa-triangle-exclamation'}" style="color:${allOk ? '#10b981' : '#f59e0b'};margin-right:4px;"></i>
            <strong>${mapped}</strong> / ${total} columnas requeridas detectadas
          </span>
          <span><i class="fa-solid fa-file-lines" style="color:#3b82f6;margin-right:4px;"></i><strong>${allDataRows.length}</strong> líneas de datos</span>
          <span><i class="fa-solid fa-file-invoice" style="color:#6366f1;margin-right:4px;"></i><strong>${facturas.size}</strong> pedidos únicos (= órdenes picking)</span>
          <span><i class="fa-solid fa-grip-lines" style="color:#94a3b8;margin-right:4px;"></i>Separador: <code>${sep === ';' ? 'punto y coma (;)' : 'coma (,)'}</code></span>
        </div>
        ${!allOk ? `<div style="margin-top:8px;padding:6px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;color:#dc2626;font-size:11px;">
          <i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>
          <strong>Columnas no detectadas:</strong> ${Object.keys(MAPEO).filter(f => colMap[f] === undefined).map(f => fieldLabels[f]).join(', ')}. Verifique que los encabezados del archivo coincidan.
        </div>` : ''}
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
      const r = await fetch('/WMS_FENIX/public/api/picking/importar', {
        method: 'POST',
        headers: { Authorization: 'Bearer ' + localStorage.getItem('wms_token') },
        body: fd
      });
      if (!r.ok) throw new Error('HTTP ' + r.status + ' — ' + r.statusText);
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
        const porSuc = au.por_sucursal || {};
        const sucArch = porSuc.archivo || {};
        const sucSis  = porSuc.sistema || {};
        const diffColor = v => v > 0 ? 'color:#dc2626;font-weight:700;' : (v < 0 ? 'color:#f59e0b;font-weight:700;' : 'color:#10b981;font-weight:700;');
        const fmtVal = v => typeof v === 'number' ? v.toLocaleString('es-CO') : (v || '0');
        const hasDiff = (diff.lineas > 0 || diff.cantidad > 0);
        const zeroPedidos = (j.importadas || 0) === 0;
        const errList = data.errores || [];
        const noProd = data.productos_no_encontrados || 0;
        const campos = data.campos_detectados || [];
        const actualizadas = data.actualizadas || 0;
        const lineasActualizadas = data.lineas_actualizadas || 0;
        const lineasNuevas = data.lineas_nuevas || 0;
        const lineasSinCambio = data.lineas_sin_cambio || 0;
        const productosPendientes = data.productos_pendientes || [];

        // Per-sucursal breakdown table
        const allSucs = [...new Set([...Object.keys(sucArch), ...Object.keys(sucSis)])].sort();
        const sucTable = allSucs.length > 0 ? `
          <div style="margin-bottom:12px;">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;">📦 Líneas por Sucursal de Entrega</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th style="padding:5px 10px;text-align:left;">Sucursal de Entrega</th>
                  <th style="padding:5px 10px;text-align:right;">📄 Archivo</th>
                  <th style="padding:5px 10px;text-align:right;">💾 Cargadas</th>
                  <th style="padding:5px 10px;text-align:right;">Excluidas</th>
                </tr>
              </thead>
              <tbody>
                ${allSucs.map(s => {
                  const a = sucArch[s] || 0, si = sucSis[s] || 0, ex = a - si;
                  return `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:5px 10px;">${WMS.esc(s)}</td>
                    <td style="padding:5px 10px;text-align:right;">${a}</td>
                    <td style="padding:5px 10px;text-align:right;color:${si>0?'#16a34a':'#dc2626'};font-weight:600;">${si}</td>
                    <td style="padding:5px 10px;text-align:right;${ex>0?'color:#dc2626;font-weight:700;':''}">${ex > 0 ? '+'+ex : ex}</td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>` : '';

        // ML Análisis de reconciliación autónoma (debe declararse antes de summaryHtml)
        const lineasArchivo = arch.lineas_archivo || 0;
        const lineasSistema = sys.lineas_sistema || 0;
        const excluidas = lineasArchivo - lineasSistema;
        const mlIntegro = excluidas === 0 && noProd === 0 && errList.length === 0;
        const mlBg     = mlIntegro ? '#f0fdf4' : '#fefce8';
        const mlBorder = mlIntegro ? '#bbf7d0' : '#fde68a';
        const mlColor  = mlIntegro ? '#166534' : '#92400e';
        const mlLineas = excluidas > 0
          ? `⚠ Líneas archivo: <strong>${lineasArchivo}</strong> → cargadas: <strong>${lineasSistema}</strong> <span style="color:#dc2626;">(${excluidas} excluidas)</span>`
          : `✅ Líneas archivo: <strong>${lineasArchivo}</strong> → cargadas: <strong>${lineasSistema}</strong> <span style="color:#16a34a;">OK</span>`;
        const mlRefs = noProd > 0
          ? `⚠ Referencias sin codificar: <strong style="color:#d97706;">${noProd}</strong> — requieren creación`
          : `✅ Referencias sin codificar: <strong>0</strong> <span style="color:#16a34a;">OK</span>`;
        const mlConc = mlIntegro
          ? '<div style="margin-top:6px;color:#166534;font-weight:600;">✅ Importación íntegra — líneas y referencias concuerdan.</div>'
          : '<div style="margin-top:6px;color:#92400e;">⚠ Acción requerida: revise referencias sin codificar o líneas excluidas antes de asignar.</div>';
        const mlHtml = `
          <div style="margin-top:12px;padding:10px 14px;background:${mlBg};border:1px solid ${mlBorder};border-radius:6px;font-size:12px;">
            <div style="font-weight:700;color:${mlColor};margin-bottom:6px;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-robot" style="font-size:14px;"></i> Análisis ML — Reconciliación de Importación
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;">
              <span>${mlLineas}</span>
              <span>${mlRefs}</span>
              <span>${(j.importadas||0)>0?'✅':'ℹ'} Planillas nuevas: <strong>${j.importadas||0}</strong> · Actualizadas: <strong>${actualizadas}</strong></span>
              <span>${errList.length===0?'✅':'❌'} Errores: <strong style="${errList.length>0?'color:#dc2626;':''}">${errList.length}</strong></span>
            </div>
            ${mlConc}
          </div>`;

        // Build the professional summary HTML
        const summaryHtml = `
          <div style="text-align:left;font-size:13px;max-height:70vh;overflow-y:auto;">
            ${zeroPedidos ? `<div style="padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;margin-bottom:14px;">
              <div style="font-size:14px;font-weight:700;color:#991b1b;margin-bottom:6px;"><i class="fa-solid fa-circle-xmark" style="margin-right:6px;"></i>No se crearon pedidos</div>
              <div style="font-size:12px;color:#7f1d1d;">El archivo no generó órdenes de picking. Revise los motivos a continuación.</div>
            </div>` : ''}

            <!-- KPI Cards -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;">
              <div style="padding:10px;background:${zeroPedidos?'#fef2f2':'#f0fdf4'};border-radius:6px;text-align:center;border:1px solid ${zeroPedidos?'#fca5a5':'#bbf7d0'};">
                <div style="font-size:22px;font-weight:800;color:${zeroPedidos?'#dc2626':'#16a34a'};">${j.importadas || 0}</div>
                <div style="font-size:9px;color:${zeroPedidos?'#dc2626':'#16a34a'};font-weight:600;text-transform:uppercase;">Planillas Nuevas</div>
              </div>
              <div style="padding:10px;background:${actualizadas>0?'#eff6ff':'#f8fafc'};border-radius:6px;text-align:center;border:1px solid ${actualizadas>0?'#bfdbfe':'#e2e8f0'};">
                <div style="font-size:22px;font-weight:800;color:${actualizadas>0?'#2563eb':'#94a3b8'};">${actualizadas}</div>
                <div style="font-size:9px;color:${actualizadas>0?'#2563eb':'#94a3b8'};font-weight:600;text-transform:uppercase;">Actualizadas</div>
              </div>
              <div style="padding:10px;background:#eff6ff;border-radius:6px;text-align:center;border:1px solid #bfdbfe;">
                <div style="font-size:22px;font-weight:800;color:#2563eb;">${data.total_lineas || 0}</div>
                <div style="font-size:9px;color:#2563eb;font-weight:600;text-transform:uppercase;">Líneas Cargadas</div>
              </div>
              <div style="padding:10px;background:${noProd>0?'#fefce8':'#f0fdf4'};border-radius:6px;text-align:center;border:1px solid ${noProd>0?'#fde68a':'#bbf7d0'};">
                <div style="font-size:22px;font-weight:800;color:${noProd>0?'#d97706':'#16a34a'};">${noProd}</div>
                <div style="font-size:9px;color:${noProd>0?'#d97706':'#16a34a'};font-weight:600;text-transform:uppercase;">Sin Codificar</div>
              </div>
            </div>
            ${(actualizadas > 0) ? `<div style="padding:7px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:11px;color:#1e40af;margin-bottom:10px;">
              <i class="fa-solid fa-pen-to-square"></i> <strong>${actualizadas}</strong> planilla(s) actualizadas —
              <strong>${lineasActualizadas}</strong> línea(s) con cantidad modificada ·
              <strong>${lineasNuevas}</strong> línea(s) nueva(s) agregadas ·
              <strong>${lineasSinCambio}</strong> sin cambio
            </div>` : ''}

            <!-- Campos detectados -->
            <div style="margin-bottom:12px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
              <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Campos Detectados en el Archivo</div>
              <div style="display:flex;flex-wrap:wrap;gap:4px;">
                ${campos.map(c => '<span style="padding:2px 8px;background:#e0e7ff;color:#4338ca;border-radius:10px;font-size:10px;font-weight:600;">' + WMS.esc(c) + '</span>').join('')}
                ${!campos.includes('producto') ? '<span style="padding:2px 8px;background:#fee2e2;color:#991b1b;border-radius:10px;font-size:10px;font-weight:600;">⚠ producto NO detectado</span>' : ''}
                ${!campos.includes('cantidad') ? '<span style="padding:2px 8px;background:#fee2e2;color:#991b1b;border-radius:10px;font-size:10px;font-weight:600;">⚠ cantidad NO detectada</span>' : ''}
              </div>
            </div>

            <!-- Audit Table -->
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;">📊 Auditoría — Archivo vs Sistema</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px;border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:10px;">
              <thead>
                <tr style="background:#f1f5f9;">
                  <th style="padding:6px 10px;text-align:left;">Concepto</th>
                  <th style="padding:6px 10px;text-align:right;">📄 Archivo</th>
                  <th style="padding:6px 10px;text-align:right;">💾 Sistema</th>
                  <th style="padding:6px 10px;text-align:right;">Δ Diferencia</th>
                </tr>
              </thead>
              <tbody>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Líneas de datos</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.lineas_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.lineas_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.lineas)}">${diff.lineas > 0 ? '+' : ''}${fmtVal(diff.lineas)}</td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Sucursales / Clientes</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.clientes_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.clientes_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.clientes)}">${diff.clientes > 0 ? '+' : ''}${fmtVal(diff.clientes)}</td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:5px 10px;">Unidades totales</td><td style="padding:5px 10px;text-align:right;">${fmtVal(arch.cantidad_archivo)}</td><td style="padding:5px 10px;text-align:right;">${fmtVal(sys.cantidad_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.cantidad)}">${diff.cantidad > 0 ? '+' : ''}${fmtVal(diff.cantidad)}</td></tr>
                <tr><td style="padding:5px 10px;">Valor monetario</td><td style="padding:5px 10px;text-align:right;">$${fmtVal(arch.valor_archivo)}</td><td style="padding:5px 10px;text-align:right;">$${fmtVal(sys.valor_sistema)}</td><td style="padding:5px 10px;text-align:right;${diffColor(diff.valor)}">${diff.valor > 0 ? '+$' : diff.valor < 0 ? '-$' : '$'}${fmtVal(Math.abs(diff.valor || 0))}</td></tr>
              </tbody>
            </table>

            ${sucTable}

            <!-- Status Banner -->
            ${zeroPedidos
              ? ''
              : diff.lineas > 0
                ? '<div style="padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;color:#dc2626;font-size:11px;margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i><strong>' + diff.lineas + ' línea(s)</strong> del archivo no se cargaron. Causas: productos no encontrados o datos incompletos.</div>'
                : '<div style="padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;color:#166534;font-size:11px;margin-bottom:6px;"><i class="fa-solid fa-check-circle" style="margin-right:4px;"></i><strong>Importación exitosa.</strong> Todas las líneas del archivo fueron cargadas correctamente.</div>'}

            ${productosPendientes.length > 0 ? `
            <div style="padding:8px 12px;background:#fefce8;border:1px solid #fde68a;border-radius:4px;color:#78350f;font-size:11px;margin-bottom:6px;">
              <div style="font-weight:700;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fa-solid fa-triangle-exclamation" style="margin-right:4px;"></i>Productos sin codificar — guardados en tabla de pendientes (${productosPendientes.length})</span>
                <button class="btn btn-xs" style="background:#92400e;color:#fff;padding:2px 10px;font-size:10px;" onclick="WMS.closeModal('generic-modal');WMS_MODULES.picking.show_productos_pendientes();">Ver tabla</button>
              </div>
              <table style="width:100%;border-collapse:collapse;font-size:11px;">
                <thead><tr style="background:#fef08a;">
                  <th style="padding:3px 8px;text-align:left;">EAN / Código</th>
                  <th style="padding:3px 8px;text-align:left;">N° Factura</th>
                  <th style="padding:3px 8px;text-align:left;">Sucursal</th>
                  <th style="padding:3px 8px;text-align:right;">Cant.</th>
                </tr></thead>
                <tbody>
                  ${productosPendientes.slice(0,15).map(p => `<tr style="border-top:1px solid #fde68a;">
                    <td style="padding:3px 8px;font-weight:700;font-family:monospace;">${WMS.esc(p.ean || '')}</td>
                    <td style="padding:3px 8px;">${WMS.esc(p.numero_factura || '-')}</td>
                    <td style="padding:3px 8px;">${WMS.esc(p.sucursal || '')}</td>
                    <td style="padding:3px 8px;text-align:right;">${p.cantidad || 1}</td>
                  </tr>`).join('')}
                  ${productosPendientes.length > 15 ? `<tr><td colspan="4" style="padding:3px 8px;color:#92400e;">... y ${productosPendientes.length - 15} más en la tabla de pendientes</td></tr>` : ''}
                </tbody>
              </table>
            </div>` : ''}

            ${errList.length > 0 ? '<div style="padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;color:#dc2626;font-size:11px;margin-bottom:6px;"><strong>Detalle de errores (' + errList.length + '):</strong><ul style="margin:4px 0 0;padding-left:16px;">' + errList.slice(0,15).map(e => '<li>' + WMS.esc(e) + '</li>').join('') + (errList.length > 15 ? '<li>... y ' + (errList.length-15) + ' más</li>' : '') + '</ul></div>' : ''}
            ${mlHtml}
          </div>`;

        const modalTitle = zeroPedidos
          ? '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;margin-right:6px;"></i>Importación sin resultados'
          : hasDiff
            ? '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;margin-right:6px;"></i>Resultado de Importación'
            : '<i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:6px;"></i>Importación Completada';
        const modalFooter = zeroPedidos
          ? `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal');WMS_MODULES.picking.importarPedidos()">
               <i class="fa-solid fa-rotate-left"></i> Intentar de nuevo
             </button>
             <button class="btn btn-primary" onclick="WMS.closeModal('generic-modal')">
               <i class="fa-solid fa-xmark"></i> Cerrar
             </button>`
          : `<button class="btn btn-primary" onclick="WMS.closeModal('generic-modal');WMS_MODULES.picking.show_pedidos();">
               <i class="fa-solid fa-check"></i> Aceptar y ver pedidos
             </button>`;
        WMS.showModal(modalTitle, summaryHtml, modalFooter);
      }
    } catch(e) { if (e.isSessionExpired) return; console.error('[picking] uploadCsv error:', e); WMS.toast('error', 'Error al procesar la importación: ' + (e.message || 'Error desconocido')); if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Pedidos'; } }
  },

  async _anularPedido(id) {
    WMS.confirm('Anular Pedido', '¿Está seguro de anular este pedido? Se revertirá la reserva de inventario y el stock pickeado regresará al sistema.', async () => {
      try {
        const r = await API.delete('/picking/' + id);
        if (r.error) WMS.toast('error', r.message);
        else {
          WMS.toast('success', 'Pedido anulado correctamente');
          WMS.closeRightPanel();
          this.show_pedidos(true);
        }
      } catch(e) { WMS.toast('error', 'Error al anular'); }
    });
  },

  async _editarPedido(id) {
    try {
      const r = await API.get('/picking/' + id);
      const o = r.data || r;
      WMS.showModal('Editar Pedido ' + (o.numero_orden || o.id), `
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Cliente / Sucursal</label>
            <input type="text" id="edit-ped-cliente" class="form-control" value="${WMS.esc(o.cliente || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">Prioridad (1-10)</label>
            <input type="number" id="edit-ped-prioridad" class="form-control" value="${o.prioridad || 5}" min="1" max="10">
          </div>
          <div class="form-group">
            <label class="form-label">Fecha Requerida</label>
            <input type="date" id="edit-ped-fecha" class="form-control" value="${o.fecha_requerida || ''}">
          </div>
          <div class="form-group">
            <label class="form-label">Ruta / Área Comercial</label>
            <input type="text" id="edit-ped-area" class="form-control" value="${WMS.esc(o.area_comercial || '')}">
          </div>
        </div>
      `, `
        <button class="btn btn-secondary" onclick="WMS.closeModal()">Cancelar</button>
        <button class="btn btn-primary" onclick="WMS_MODULES.picking._guardarEdicionPedido(${id})">Guardar Cambios</button>
      `);
    } catch(e) { WMS.toast('error', 'Error al cargar pedido'); }
  },

  async _guardarEdicionPedido(id) {
    const payload = {
      cliente: document.getElementById('edit-ped-cliente').value,
      prioridad: document.getElementById('edit-ped-prioridad').value,
      fecha_requerida: document.getElementById('edit-ped-fecha').value,
      area_comercial: document.getElementById('edit-ped-area').value,
    };
    try {
      const r = await API.put('/picking/' + id, payload);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', 'Pedido actualizado');
        WMS.closeModal();
        WMS.closeRightPanel();
        this.show_pedidos(true);
      }
    } catch(e) { WMS.toast('error', 'Error al actualizar'); }
  },

  async _mostrarAgregarLineaPedido(id) {
    try {
      const r = await API.get('/param/productos');
      const productos = r.data || r || [];
      WMS.showModal('Agregar Línea a Pedido #' + id, `
        <div class="form-group">
          <label class="form-label">Producto</label>
          <select id="add-line-prod" class="form-control select2" style="width:100%">
            <option value="">Seleccione un producto...</option>
            ${productos.map(p => `<option value="${p.id}">${WMS.esc(p.nombre)} [${WMS.esc(p.codigo_interno || '')}]</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Cantidad</label>
          <input type="number" id="add-line-qty" class="form-control" value="1" min="0.1" step="0.1">
        </div>
      `, `
        <button class="btn btn-secondary" onclick="WMS.closeModal()">Cancelar</button>
        <button class="btn btn-success" onclick="WMS_MODULES.picking._confirmarAgregarLinea(${id})">Agregar Línea</button>
      `);
      // Si select2 está disponible, activarlo
      if (window.$ && $.fn.select2) $('#add-line-prod').select2({ dropdownParent: $('#generic-modal') });
    } catch(e) { WMS.toast('error', 'Error cargando productos'); }
  },

  async _confirmarAgregarLinea(id) {
    const payload = {
      producto_id: document.getElementById('add-line-prod').value,
      cantidad: document.getElementById('add-line-qty').value,
    };
    if (!payload.producto_id) return WMS.toast('warn', 'Seleccione un producto');
    try {
      const r = await API.post(`/picking/${id}/lineas`, payload);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', 'Línea agregada correctamente');
        WMS.closeModal();
        WMS.closeRightPanel();
        this.show_pedidos(true);
      }
    } catch(e) { WMS.toast('error', 'Error al agregar línea'); }
  },

  // ── ASIGNACIÓN DE PICKING ─────────────────────────────────────────────────

  async show_asignacion(silent = false) {
    if (!silent) {
      WMS.setBreadcrumb('picking', 'Asignación');
      WMS.spinner();
    }
    this._asigFiltros = { solo_hoy: 1, q: '', ruta: '', sucursal_entrega: '', fecha_desde: '', fecha_hasta: '' };
    this._asigSeleccionados = new Set();
    this._asigOrdenes = [];
    this._asigAuxiliares = [];
    this._rangoIdx = 1;
    await this._cargarAuxiliares();
    await this._cargarAsignacion();
  },

  async _cargarAuxiliares() {
    try {
      const r = await API.get('/param/personal?rol=Auxiliar&limit=100');
      this._asigAuxiliares = r.data || r || [];
    } catch(e) { this._asigAuxiliares = []; }
  },

  async _cargarAsignacion() {
    if (typeof WMS !== 'undefined' && WMS.currentModule !== 'picking') return;
    const f = this._asigFiltros || {};
    const params = new URLSearchParams({ limit: 300 });
    if (f.fecha_desde && f.fecha_hasta) {
      params.set('fecha_desde', f.fecha_desde);
      params.set('fecha_hasta', f.fecha_hasta);
      params.set('estado', 'Pendiente');
    } else if (f.solo_hoy) {
      params.set('solo_hoy', '1');
      params.set('estado', 'Pendiente');
    }
    if (f.q)                params.set('q', f.q);
    if (f.ruta)             params.set('ruta', f.ruta);
    if (f.sucursal_entrega) params.set('sucursal_entrega', f.sucursal_entrega);
    params.set('sin_auxiliar', '1');
    try {
      if (!this._ambientesCache) {
        const ar = await API.get('/param/ambientes');
        this._ambientesCache = ar.data || ar || [];
      }
      const r = await API.get('/picking?' + params.toString());
      this._asigOrdenes = r.data || r || [];
      const validIds = new Set((this._asigOrdenes).map(o => o.id));
      for (const id of this._asigSeleccionados) {
        if (!validIds.has(id)) this._asigSeleccionados.delete(id);
      }
      this._renderAsignacion();
    } catch(e) { WMS.toast('error', 'Error cargando pedidos'); }
  },

  _renderAsignacion() {
    const ordenes = this._asigOrdenes;  // ya filtrados: Pendiente + sin_auxiliar
    const f = this._asigFiltros || {};
    const defaultColores = {seco:'#92400e',refrigerado:'#0369a1',congelado:'#7c3aed'};
    const defaultIconos  = {seco:'🌡️',refrigerado:'❄️',congelado:'🧊'};
    const ambientes = (this._ambientesCache || []).length > 0
      ? this._ambientesCache
      : [{codigo:'SECO',color:'#92400e'},{codigo:'REFRIGERADO',color:'#0369a1'},{codigo:'CONGELADO',color:'#7c3aed'}];
    const auxOpts = this._asigAuxiliares.map(a =>
      `<option value="${parseInt(a.id)||0}">${WMS.esc(a.nombre)}</option>`).join('');

    // ── Agrupar pedidos pendientes por planilla_numero ───────────────────────
    const planillasMap = {};
    ordenes.forEach(o => {
      const key = o.planilla_numero || o.numero_orden;
      if (!planillasMap[key]) {
        planillasMap[key] = {
          planilla : key,
          planKey  : key.replace(/[^a-zA-Z0-9]/g, '_'),
          cliente  : o.sucursal_entrega || o.cliente || '—',
          ruta     : o.ruta || '',
          ordenes  : [],
          ordenIds : [],
          totalLineas: 0,
        };
        ambientes.forEach(a => { planillasMap[key][a.codigo.toLowerCase().replace(/ /g,'_') + '_count'] = 0; });
      }
      planillasMap[key].ordenes.push(o);
      planillasMap[key].ordenIds.push(o.id);
      planillasMap[key].totalLineas += (o.total_count || 0);
      ambientes.forEach(a => {
        const k = a.codigo.toLowerCase().replace(/ /g,'_') + '_count';
        planillasMap[key][k] += (o[k] || 0);
      });
    });
    const planillas  = Object.values(planillasMap);
    const sucursales = [...new Set(ordenes.map(o=>o.sucursal_entrega||o.cliente).filter(Boolean))];
    const rutas      = [...new Set(ordenes.map(o=>o.ruta).filter(Boolean))];

    // ── Filas: una por planilla ──────────────────────────────────────────────
    const rows = planillas.map(p => {
      const todosSel  = p.ordenIds.length > 0 && p.ordenIds.every(id => this._asigSeleccionados.has(id));
      const algunoSel = p.ordenIds.some(id => this._asigSeleccionados.has(id));
      const ordenIdsJson = JSON.stringify(p.ordenIds);
      const rowBg = todosSel ? 'background:#eff6ff;' : algunoSel ? 'background:#f0fdf4;' : '';
      return `
        <tr style="${rowBg}" id="asig-plan-${p.planKey}">
          <td style="padding:8px 12px;text-align:center;">
            <input type="checkbox" ${todosSel?'checked':''} title="Seleccionar planilla completa"
              onchange="WMS_MODULES.picking._toggleAsigPlanilla('${p.planKey}',${ordenIdsJson},this.checked)">
          </td>
          <td style="padding:8px 12px;">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:3px;">
              <span style="background:#1e3a8a;color:#fff;font-family:monospace;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:3px;">${WMS.esc(p.planilla)}</span>
              ${p.ordenes.length > 1
                ? `<span style="background:#fef3c7;color:#92400e;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:3px;"><i class="fa-solid fa-triangle-exclamation"></i> ${p.ordenes.length} pedidos pendientes</span>`
                : `<span style="background:#f1f5f9;color:#64748b;font-size:.65rem;padding:1px 6px;border-radius:3px;">1 pedido</span>`}
            </div>
            <div style="font-size:.78rem;font-weight:600;color:#1e293b;">${WMS.esc(p.cliente)}</div>
          </td>
          <td style="padding:8px 12px;">
            ${p.ruta
              ? `<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:3px;font-size:.72rem;">${WMS.esc(p.ruta)}</span>`
              : '<span style="color:#94a3b8;font-size:.72rem;">—</span>'}
          </td>
          ${ambientes.map(a => {
            const k   = a.codigo.toLowerCase().replace(/ /g,'_') + '_count';
            const val = p[k] || 0;
            const color = a.color || defaultColores[a.codigo.toLowerCase()] || '#475569';
            return `<td style="padding:8px 12px;text-align:center;font-weight:700;color:${val>0?color:'#94a3b8'};">${val||'—'}</td>`;
          }).join('')}
          <td style="padding:8px 12px;text-align:center;font-weight:700;">${p.totalLineas}</td>
          <td style="padding:8px 12px;text-align:center;">
            <button title="Seleccionar y asignar esta planilla"
              onclick="WMS_MODULES.picking._seleccionarYAsignarPlanilla('${p.planKey}',${ordenIdsJson})"
              style="background:#0F4C81;color:#fff;border:none;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:.72rem;font-weight:600;white-space:nowrap;">
              <i class="fa-solid fa-user-check"></i> Asignar
            </button>
          </td>
        </tr>`;
    }).join('');

    WMS.setContent(`
      <div style="display:flex;min-height:calc(100vh - 140px);position:relative;">
        <div style="flex:1;overflow:hidden;display:flex;flex-direction:column;">
          <div class="card" style="margin:0;border-radius:0;flex:1;overflow:hidden;display:flex;flex-direction:column;">
            <div class="card-header" style="flex-shrink:0;">
              <h5 class="card-title"><i class="fa-solid fa-user-check"></i> Asignación por Planilla</h5>
              <span style="font-size:.78rem;color:#64748b;">${f.fecha_desde && f.fecha_hasta ? WMS.esc(f.fecha_desde) + ' → ' + WMS.esc(f.fecha_hasta) : 'Hoy'} — ${planillas.length} planilla(s) con pedidos pendientes</span>
            </div>
            <div style="padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0;">
              <input type="text" class="form-control" style="flex:1;min-width:180px;" placeholder="🔍 Buscar planilla o cliente..."
                     value="${WMS.esc(f.q||'')}"
                     oninput="WMS_MODULES.picking._asigFiltros.q=this.value;clearTimeout(WMS_MODULES.picking._aqt);WMS_MODULES.picking._aqt=setTimeout(()=>WMS_MODULES.picking._cargarAsignacion(),350)">
              <input type="date" class="form-control" style="width:140px;" title="Fecha desde"
                     value="${WMS.esc(f.fecha_desde||'')}"
                     onchange="WMS_MODULES.picking._asigFiltros.fecha_desde=this.value;WMS_MODULES.picking._asigFiltros.solo_hoy=0;WMS_MODULES.picking._cargarAsignacion()">
              <input type="date" class="form-control" style="width:140px;" title="Fecha hasta"
                     value="${WMS.esc(f.fecha_hasta||'')}"
                     onchange="WMS_MODULES.picking._asigFiltros.fecha_hasta=this.value;WMS_MODULES.picking._asigFiltros.solo_hoy=0;WMS_MODULES.picking._cargarAsignacion()">
              <button class="btn btn-xs btn-secondary" title="Volver a hoy"
                      onclick="WMS_MODULES.picking._asigFiltros={solo_hoy:1,q:'',ruta:'',sucursal_entrega:'',fecha_desde:'',fecha_hasta:''};WMS_MODULES.picking._cargarAsignacion()">
                <i class="fa-solid fa-rotate-left"></i> Hoy
              </button>
              <select class="form-control" style="width:170px;" onchange="WMS_MODULES.picking._asigFiltros.sucursal_entrega=this.value;WMS_MODULES.picking._cargarAsignacion()">
                <option value="">Cliente: Todos</option>
                ${sucursales.map(s=>`<option value="${WMS.esc(s)}" ${f.sucursal_entrega===s?'selected':''}>${WMS.esc(s)}</option>`).join('')}
              </select>
              ${this._asigSeleccionados.size > 0
                ? `<span style="background:#0F4C81;color:#fff;padding:4px 12px;border-radius:4px;font-weight:600;font-size:.8rem;">${this._asigSeleccionados.size} pedido(s) seleccionados</span>`
                : ''}
            </div>
            <div style="overflow:auto;flex:1;">
              <table class="erp-table">
                <thead>
                  <tr>
                    <th style="padding:10px 12px;width:40px;">
                      <input type="checkbox" title="Seleccionar todas las planillas"
                        onchange="WMS_MODULES.picking._toggleAsigTodos(this.checked)">
                    </th>
                    <th style="padding:10px 12px;">Planilla / Cliente</th>
                    <th style="padding:10px 12px;">Ruta</th>
                    ${ambientes.map(a => {
                      const icon  = a.icono ? `<i class="fa-solid ${a.icono}"></i> ` : (defaultIconos[a.codigo.toLowerCase()]||'') + ' ';
                      const color = a.color || defaultColores[a.codigo.toLowerCase()] || '#475569';
                      return `<th style="padding:10px 12px;text-align:center;color:${color};">${icon}${WMS.esc(a.codigo)}</th>`;
                    }).join('')}
                    <th style="padding:10px 12px;text-align:center;">Líneas</th>
                    <th style="padding:10px 12px;text-align:center;">Acción</th>
                  </tr>
                </thead>
                <tbody>
                  ${rows || `<tr><td colspan="${6 + ambientes.length}" style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa-solid fa-check-circle" style="color:#10b981;font-size:1.5rem;display:block;margin-bottom:8px;"></i>No hay planillas pendientes de asignar hoy.</td></tr>`}
                </tbody>
                ${this._asigSeleccionados.size > 0 ? `
                <tfoot>
                  <tr style="background:#f0fdf4;border-top:2px solid #86efac;">
                    <td colspan="3" style="padding:8px 12px;font-weight:700;font-size:.78rem;color:#166534;">
                      ∑ ${this._asigSeleccionados.size} pedido(s) en selección
                    </td>
                    ${ambientes.map(a => {
                      const color = a.color || defaultColores[a.codigo.toLowerCase()] || '#475569';
                      return `<td style="padding:8px 12px;text-align:center;font-weight:900;color:${color};font-size:.9rem;" id="asig-tot-${a.codigo.toLowerCase().replace(/ /g,'_')}">—</td>`;
                    }).join('')}
                    <td style="padding:8px 12px;text-align:center;font-weight:900;font-size:.9rem;" id="asig-tot-total">—</td>
                    <td></td>
                  </tr>
                </tfoot>` : ''}
              </table>
            </div>
          </div>
        </div>
        ${this._asigSeleccionados.size > 0 ? this._buildDrawerAsignacion(auxOpts) : ''}
      </div>`);

    if (this._asigSeleccionados.size > 0) this._actualizarTotalesAsig();
  },

  _buildDrawerAsignacion(auxOpts) {
    const totales = this._calcularTotalesAmbiente();
    const defaultIconos = {seco:'🌡️',refrigerado:'❄️',congelado:'🧊'};
    const defaultColores = {seco:'#92400e',refrigerado:'#0369a1',congelado:'#7c3aed'};
    const ambientes = (this._ambientesCache || []).length > 0
      ? this._ambientesCache
      : [{codigo:'SECO',color:'#92400e'},{codigo:'REFRIGERADO',color:'#0369a1'},{codigo:'CONGELADO',color:'#7c3aed'}];

    return `
      <div id="asig-drawer" style="width:260px;flex-shrink:0;border-left:2px solid #0F4C81;background:#fff;display:flex;flex-direction:column;max-height:calc(100vh - 140px);overflow-y:auto;">
        <div style="background:#0F4C81;color:#fff;padding:12px 14px;flex-shrink:0;">
          <div style="font-weight:700;font-size:.85rem;">⚡ Asignar Separación</div>
          <div style="font-size:.75rem;opacity:.85;">${this._asigSeleccionados.size} pedidos · ${totales.total} líneas</div>
        </div>
        <div style="padding:14px;display:flex;flex-direction:column;gap:14px;flex:1;">
          <div>
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:6px;">Modo Asignación</div>
            <div style="display:flex;gap:4px;">
              <button id="modo-amb" onclick="WMS_MODULES.picking._setModoAsig('ambiente')"
                style="flex:1;padding:6px;border-radius:4px;border:none;cursor:pointer;font-size:.75rem;background:#0F4C81;color:#fff;">
                🌡️ Ambiente
              </button>
              <button id="modo-pas" onclick="WMS_MODULES.picking._setModoAsig('pasillo')"
                style="flex:1;padding:6px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;background:#f8fafc;color:#64748b;">
                🛒 Pasillo
              </button>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            ${ambientes.map(a => {
              const key = a.codigo.toLowerCase().replace(/ /g,'_');
              const color = a.color || defaultColores[a.codigo.toLowerCase()] || '#475569';
              const icon = a.icono ? `<i class="fa-solid ${a.icono}"></i> ` : (defaultIconos[a.codigo.toLowerCase()] || '');
              const count = totales[key] || 0;
              return `<div style="flex:1;min-width:70px;background:${color}15;border:1px solid ${color}40;border-radius:4px;padding:8px;text-align:center;">
                <div style="font-size:.65rem;color:${color};font-weight:600;">${icon} ${WMS.esc(a.codigo)}</div>
                <div style="font-size:1.3rem;font-weight:900;color:${color};" id="kpi-${key}">${count}</div>
                <div style="font-size:.6rem;color:${color};">líneas</div>
              </div>`;
            }).join('')}
          </div>
          <div id="config-ambiente">
            ${ambientes.map(a => {
              const key = a.codigo.toLowerCase().replace(/ /g,'_');
              const color = a.color || defaultColores[a.codigo.toLowerCase()] || '#475569';
              const icon = a.icono ? `<i class="fa-solid ${a.icono}"></i> ` : (defaultIconos[a.codigo.toLowerCase()] || '');
              const count = totales[key] || 0;
              return `<div style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                  <span style="font-size:.75rem;font-weight:700;color:${color};">${icon} ${WMS.esc(a.codigo)}</span>
                  <span style="font-size:.68rem;background:${color}15;color:${color};border:1px solid ${color}40;padding:1px 6px;border-radius:10px;">${count} lín.</span>
                </div>
                <select class="form-control" id="aux-${key}"
                  style="border-color:${color}40;background:${color}08;font-size:.78rem;"
                  data-ambiente="${WMS.esc(a.codigo)}">
                  <option value="">— Sin asignar —</option>
                  ${auxOpts}
                </select>
              </div>`;
            }).join('')}
          </div>
          <div id="config-pasillo" style="display:none;">
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:8px;">Rangos de Pasillo</div>
            <div id="rangos-pasillo">
              ${this._buildRangoPasillo(0, auxOpts)}
            </div>
            <button onclick="WMS_MODULES.picking._agregarRangoPasillo()" class="btn btn-outline-primary btn-sm" style="width:100%;margin-top:4px;font-size:.75rem;">
              + Agregar rango
            </button>
          </div>
          <div style="padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;">
            <div style="font-size:.7rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;margin-bottom:3px;">
              <i class="fa-solid fa-user-shield"></i> Auxiliar de Respaldo
            </div>
            <div style="font-size:.63rem;color:#3b82f6;margin-bottom:5px;">Garantiza cobertura 100%: recibe líneas sin ambiente asignado.</div>
            <select class="form-control" id="asig-fallback-aux" style="font-size:.78rem;">
              <option value="">— Sin respaldo —</option>
              ${auxOpts}
            </select>
          </div>
          <div>
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;">Nombre de Ruta</div>
            <input type="text" id="asig-ruta-nombre" class="form-control" placeholder="Ej: Ruta 01" style="font-size:.82rem;">
          </div>
        </div>
        <div style="padding:12px 14px;border-top:1px solid #e2e8f0;display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
          <button class="btn btn-primary" id="btn-confirmar-asig" onclick="WMS_MODULES.picking._confirmarAsignacion()"
            style="width:100%;background:#059669;border-color:#059669;font-weight:700;">
            <i class="fa-solid fa-check"></i> Confirmar Asignación
          </button>
          <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.picking._asigSeleccionados.clear();WMS_MODULES.picking._renderAsignacion()"
            style="width:100%;font-size:.78rem;">
            ✕ Cancelar
          </button>
        </div>
      </div>`;
  },

  _buildRangoPasillo(idx, auxOpts) {
    return `
      <div style="display:flex;gap:4px;margin-bottom:8px;align-items:center;" id="rango-${parseInt(idx)||0}">
        <input type="text" placeholder="P01" class="form-control" style="width:60px;font-size:.75rem;" data-rango-desde="${parseInt(idx)||0}">
        <span style="color:#64748b;font-size:.8rem;">—</span>
        <input type="text" placeholder="P10" class="form-control" style="width:60px;font-size:.75rem;" data-rango-hasta="${parseInt(idx)||0}">
        <select class="form-control" style="flex:1;font-size:.75rem;" data-rango-aux="${parseInt(idx)||0}">
          <option value="">Auxiliar</option>${auxOpts}
        </select>
      </div>`;
  },

  _rangoIdx: 1,

  _agregarRangoPasillo() {
    const cont = document.getElementById('rangos-pasillo');
    if (!cont) return;
    const auxOpts = this._asigAuxiliares.map(a=>`<option value="${parseInt(a.id)||0}">${WMS.esc(a.nombre)}</option>`).join('');
    cont.insertAdjacentHTML('beforeend', this._buildRangoPasillo(this._rangoIdx++, auxOpts));
  },

  _setModoAsig(modo) {
    const ce = document.getElementById('config-ambiente');
    const cp = document.getElementById('config-pasillo');
    const ba = document.getElementById('modo-amb');
    const bp = document.getElementById('modo-pas');
    if (ce) ce.style.display = modo === 'ambiente' ? '' : 'none';
    if (cp) cp.style.display = modo === 'pasillo'  ? '' : 'none';
    const on  = 'flex:1;padding:6px;border-radius:4px;border:none;cursor:pointer;font-size:.75rem;background:#0F4C81;color:#fff;';
    const off = 'flex:1;padding:6px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;background:#f8fafc;color:#64748b;';
    if (ba) ba.style.cssText = modo === 'ambiente' ? on : off;
    if (bp) bp.style.cssText = modo === 'pasillo'  ? on : off;
  },

  _toggleAsig(ordenId, checked) {
    if (checked) this._asigSeleccionados.add(ordenId);
    else         this._asigSeleccionados.delete(ordenId);
    this._renderAsignacion();
  },

  _toggleAsigPlanilla(planKey, ordenIds, checked) {
    ordenIds.forEach(id => {
      if (checked) this._asigSeleccionados.add(id);
      else         this._asigSeleccionados.delete(id);
    });
    this._renderAsignacion();
  },

  _seleccionarYAsignarPlanilla(planKey, ordenIds) {
    ordenIds.forEach(id => this._asigSeleccionados.add(id));
    this._renderAsignacion();
    setTimeout(() => {
      const drawer = document.getElementById('asig-drawer');
      if (drawer) drawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, 150);
  },

  _toggleAsigTodos(checked) {
    if (checked) this._asigOrdenes.forEach(o => this._asigSeleccionados.add(o.id));
    else         this._asigSeleccionados.clear();
    this._renderAsignacion();
  },

  _calcularTotalesAmbiente() {
    const ambientes = (this._ambientesCache || []).length > 0
      ? this._ambientesCache
      : [{codigo:'SECO'},{codigo:'REFRIGERADO'},{codigo:'CONGELADO'}];
    const result = { total: 0 };
    this._asigOrdenes
      .filter(o => this._asigSeleccionados.has(o.id))
      .forEach(o => {
        ambientes.forEach(a => {
          const key = a.codigo.toLowerCase().replace(/ /g,'_');
          result[key] = (result[key] || 0) + (o[key + '_count'] || 0);
        });
        result.total += o.total_count || 0;
      });
    return result;
  },

  _actualizarTotalesAsig() {
    const t = this._calcularTotalesAmbiente();
    const set = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v||'—'; };
    const ambientes = (this._ambientesCache || []).length > 0
      ? this._ambientesCache
      : [{codigo:'SECO'},{codigo:'REFRIGERADO'},{codigo:'CONGELADO'}];
    ambientes.forEach(a => {
      const key = a.codigo.toLowerCase().replace(/ /g,'_');
      set('asig-tot-' + key, t[key] || 0);
      set('kpi-' + key, t[key] || 0);
    });
    set('asig-tot-total', t.total);
  },

  async _confirmarAsignacion() {
    const btn = document.getElementById('btn-confirmar-asig');
    if (btn) btn.disabled = true;

    const ordenIds = [...this._asigSeleccionados];
    const ruta     = document.getElementById('asig-ruta-nombre')?.value.trim() || '';
    const modoPasEl = document.getElementById('config-pasillo');
    const modo     = (modoPasEl && modoPasEl.style.display !== 'none') ? 'pasillo' : 'ambiente';

    let config = {};
    if (modo === 'ambiente') {
      const ambientes = (this._ambientesCache || []).length > 0
        ? this._ambientesCache
        : [{codigo:'SECO'},{codigo:'REFRIGERADO'},{codigo:'CONGELADO'}];
      ambientes.forEach(a => {
        const key = a.codigo.toLowerCase().replace(/ /g,'_');
        const sel = document.getElementById('aux-' + key);
        const auxId = sel?.value ? parseInt(sel.value) : null;
        config[a.codigo] = { auxiliar_id: auxId };
      });
    } else {
      const rangos = [];
      document.querySelectorAll('[data-rango-desde]').forEach(el => {
        const idx   = el.dataset.rangoDesde;
        const desde = el.value.trim();
        const hasta = document.querySelector(`[data-rango-hasta="${idx}"]`)?.value.trim();
        const auxId = parseInt(document.querySelector(`[data-rango-aux="${idx}"]`)?.value);
        if (desde && hasta && auxId) rangos.push({ pasillo_desde: desde, pasillo_hasta: hasta, auxiliar_id: auxId });
      });
      config = { rangos };
    }

    const fallbackAuxId = parseInt(document.getElementById('asig-fallback-aux')?.value || 0) || null;

    // Pre-flight: verificar cobertura antes de confirmar
    if (modo === 'ambiente' && !fallbackAuxId) {
      try {
        const pf = await API.post('/picking/validar-cobertura', { orden_ids: ordenIds, modo, config });
        const pd = pf.data || pf;
        if (!pd.cobertura_completa) {
          const sinCubrir = pd.sin_cubrir || 0;
          const ambsSin   = (pd.ambientes_sin_auxiliar || []).join(', ') || 'N/A';
          const ok = confirm(
            `⚠ COBERTURA INCOMPLETA\n\n` +
            `${sinCubrir} línea(s) quedarán SIN auxiliar asignado.\n` +
            `Ambientes sin auxiliar: ${ambsSin}\n\n` +
            `Seleccione un "Auxiliar de Respaldo" para garantizar 100% de cobertura.\n\n` +
            `¿Continuar de todas formas sin respaldo?`
          );
          if (!ok) {
            if (btn) btn.disabled = false;
            return;
          }
        }
      } catch (_pf) { /* pre-flight no disponible — continuar */ }
    }

    try {
      const r = await API.post('/picking/asignar-ambiente', { orden_ids: ordenIds, modo, config, ruta, auxiliar_fallback_id: fallbackAuxId });
      const d = r.data || r;
      const partes = [`✓ ${d.asignadas || 0} líneas asignadas a ${d.ordenes || 0} pedidos`];
      if (d.ya_asignadas > 0) partes.push(`${d.ya_asignadas} ya tenían auxiliar (omitidas)`);
      if (d.sin_auxiliar > 0) {
        partes.push(`⚠ ${d.sin_auxiliar} SIN auxiliar`);
        WMS.toast('warning', partes.join(' · '));
        this._mostrarAlertaSinAuxiliar(d);
      } else {
        WMS.toast('success', partes.join(' · '));
      }
      this._asigSeleccionados.clear();
      await this._cargarAsignacion();
    } catch(e) {
      WMS.toast('error', e.message || 'Error en asignación');
      if (btn) btn.disabled = false;
    }
  },

  // ── ALERTA LÍNEAS SIN AUXILIAR ────────────────────────────────────────────
  async _mostrarAlertaSinAuxiliar(d) {
    this._sinAuxiliarLineas = d.lineas_sin_auxiliar || [];  // guardado para _asignarFallback
    const lineas = this._sinAuxiliarLineas;
    const byAmb = {};
    lineas.forEach(l => {
      if (!byAmb[l.ambiente]) byAmb[l.ambiente] = [];
      byAmb[l.ambiente].push(l);
    });
    const colores = { Seco: '#d97706', Refrigerado: '#0ea5e9', Congelado: '#8b5cf6' };
    let tableHtml = lineas.length > 0 ? `
      <div style="max-height:280px;overflow-y:auto;border:1px solid #fca5a5;border-radius:6px;margin-top:10px;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead style="background:#fef2f2;position:sticky;top:0;">
            <tr>
              <th style="padding:6px 10px;text-align:left;color:#991b1b;">Ambiente</th>
              <th style="padding:6px 10px;text-align:left;color:#991b1b;">Producto</th>
              <th style="padding:6px 10px;text-align:left;color:#991b1b;">Código</th>
            </tr>
          </thead>
          <tbody>
            ${lineas.map(l => `<tr style="border-top:1px solid #fca5a5;">
              <td style="padding:5px 10px;">
                <span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;background:${colores[l.ambiente]||'#64748b'}20;color:${colores[l.ambiente]||'#64748b'};">${WMS.esc(l.ambiente)}</span>
              </td>
              <td style="padding:5px 10px;font-weight:600;color:#1e293b;">${WMS.esc(l.producto_nombre)}</td>
              <td style="padding:5px 10px;font-family:monospace;font-size:11px;color:#64748b;">${WMS.esc(l.producto_codigo)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>` : '';

    // Cargar auxiliares disponibles para seleccionar respaldo
    let auxOptions = '<option value="">-- Seleccionar auxiliar de respaldo --</option>';
    try {
      const ra = await API.get('/param/personal?rol=Auxiliar&activo=1');
      (ra.data || ra || []).forEach(a => {
        auxOptions += `<option value="${a.id}">${WMS.esc(a.nombre)}</option>`;
      });
    } catch(_) {}

    WMS.showModal(
      '<i class="fa-solid fa-user-slash" style="color:#dc2626;margin-right:8px;"></i>Líneas sin Auxiliar Asignado',
      `<div style="padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;margin-bottom:10px;">
        <div style="font-size:15px;font-weight:700;color:#991b1b;margin-bottom:4px;">
          <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;margin-right:6px;"></i>
          ${d.sin_auxiliar} línea(s) quedaron SIN auxiliar asignado
        </div>
        <div style="font-size:12px;color:#7f1d1d;">
          Estas líneas no serán separadas. Debe asignar un auxiliar de respaldo o configurar el ambiente correspondiente.
        </div>
      </div>
      ${tableHtml}
      <div style="margin-top:14px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;">
        <label style="font-size:12px;font-weight:700;color:#1e40af;display:block;margin-bottom:6px;">
          <i class="fa-solid fa-user-plus"></i> Asignar auxiliar de respaldo para estas ${d.sin_auxiliar} línea(s):
        </label>
        <div style="display:flex;gap:8px;align-items:center;">
          <select id="modal-fallback-aux" class="form-select" style="flex:1;">
            ${auxOptions}
          </select>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking._asignarFallback()">
            <i class="fa-solid fa-check"></i> Asignar
          </button>
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`,
      { width: '680px' }
    );
  },

  async _asignarFallback() {
    const auxId = parseInt(document.getElementById('modal-fallback-aux')?.value || 0);
    if (!auxId) return WMS.toast('warning', 'Seleccione un auxiliar de respaldo');
    const lineasArr = Array.isArray(this._sinAuxiliarLineas) ? this._sinAuxiliarLineas : [];
    if (!lineasArr.length) return WMS.toast('warning', 'Sin líneas pendientes');
    const ordenIds = [...new Set(lineasArr.map(l => l.orden_picking_id).filter(Boolean))];
    if (!ordenIds.length) return WMS.toast('warning', 'No se pudieron determinar los pedidos');
    try {
      const r = await API.post('/picking/asignar-ambiente', {
        orden_ids: ordenIds,
        modo: 'ambiente',
        config: {},
        auxiliar_fallback_id: auxId,
      });
      const d = r.data || r;
      WMS.toast('success', `${d.asignadas || lineasArr.length} líneas asignadas al auxiliar de respaldo`);
      WMS.closeModal('generic-modal');
      await this._cargarAsignacion();
    } catch(e) {
      WMS.toast('error', e.message || 'Error al asignar respaldo');
    }
  },

  // ── ALERTA VISUAL DE AGOTADOS ─────────────────────────────────────────────
  async _mostrarAlertaAgotados(data) {
    const faltantes = data.faltantes_detectados || 0;
    let detallesHtml = '';
    try {
      const hoy = new Date().toISOString().slice(0,10);
      const r = await API.get('/picking/novedades-stock', `fecha_inicio=${hoy}&fecha_fin=${hoy}&limit=50`);
      const rows = (r.data?.rows || r.rows || []);
      if (rows.length > 0) {
        detallesHtml = `
          <div style="margin-top:12px;max-height:260px;overflow-y:auto;border:1px solid #fed7aa;border-radius:4px;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead style="background:#fff7ed;position:sticky;top:0;">
                <tr>
                  <th style="padding:6px 10px;text-align:left;color:#92400e;">Producto</th>
                  <th style="padding:6px 10px;text-align:right;color:#92400e;">Solicitado</th>
                  <th style="padding:6px 10px;text-align:right;color:#92400e;">Disponible</th>
                  <th style="padding:6px 10px;text-align:right;color:#dc2626;">Faltante</th>
                  <th style="padding:6px 10px;text-align:right;color:#92400e;">Stock Actual</th>
                </tr>
              </thead>
              <tbody>
                ${rows.map(f => `<tr style="border-top:1px solid #fed7aa;">
                  <td style="padding:5px 10px;">
                    <div style="font-weight:600;color:#1e293b;">${WMS.esc(f.producto_nombre || '')}</div>
                    <div style="font-size:10px;color:#64748b;font-family:monospace;">${WMS.esc(f.producto_codigo || '')}</div>
                  </td>
                  <td style="padding:5px 10px;text-align:right;">${WMS.formatNum(f.cantidad_solicitada)}</td>
                  <td style="padding:5px 10px;text-align:right;color:#16a34a;">${WMS.formatNum(f.stock_disponible)}</td>
                  <td style="padding:5px 10px;text-align:right;font-weight:700;color:#dc2626;">${WMS.formatNum(f.cantidad_faltante)}</td>
                  <td style="padding:5px 10px;text-align:right;${(f.stock_actual||0)>0?'color:#0ea5e9;':'color:#dc2626;font-weight:700;'}">${WMS.formatNum(f.stock_actual||0)}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>`;
      }
    } catch(_) {}

    WMS.showModal(
      '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;margin-right:8px;"></i>Alerta de Agotados en Picking',
      `<div style="padding:12px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;margin-bottom:12px;">
        <div style="font-size:15px;font-weight:700;color:#92400e;margin-bottom:4px;">
          <i class="fa-solid fa-box-open" style="margin-right:6px;color:#f59e0b;"></i>
          ${faltantes} referencia(s) con inventario insuficiente
        </div>
        <div style="font-size:12px;color:#78350f;">
          Estas referencias fueron enviadas a la tabla de agotados. El auxiliar no podrá separar estas unidades hasta que se realice un reabastecimiento.
        </div>
      </div>
      ${detallesHtml}
      <div style="margin-top:12px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:12px;color:#1e40af;">
        <i class="fa-solid fa-lightbulb" style="margin-right:6px;"></i>
        <strong>Siguiente paso:</strong> Vaya a <strong>Faltantes de Stock</strong> para procesar el backorder cuando llegue inventario.
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
       <button class="btn btn-warning" onclick="WMS.closeModal('generic-modal');WMS_MODULES.picking.load('faltantes');">
         <i class="fa-solid fa-arrow-right"></i> Ver Faltantes
       </button>`,
      { width: '680px' }
    );
  },

  // ── FALTANTES ─────────────────────────────────────────────────────────────
  _faltFilters: { ini: '', fin: '', planilla: '', producto: '', sucursal_entrega: '', showAll: false, vista: 'detalle' },

  async show_faltantes(filters = null) {
    if (filters) Object.assign(this._faltFilters, filters);
    const f = this._faltFilters;
    if (!f.ini) f.ini = WMS.getToday();
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.show_faltantes()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.picking._procesarBackorder()" id="btn-backorder" style="display:none;">
        <i class="fa-solid fa-arrow-rotate-left"></i> Procesar Backorder (<span id="bo-count">0</span>)
      </button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.picking._exportFaltantes()"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
      ${(WMS.user?.rol === 'Admin' || WMS.user?.rol === 'Supervisor') ? `
      <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.picking._limpiarFaltantes()">
        <i class="fa-solid fa-trash"></i> Limpiar Faltantes
      </button>` : ''}
    `);
    WMS.spinner();
    try {
      const limit = f.showAll ? '' : '&limit=50';
      const qs = `fecha_inicio=${f.ini}&fecha_fin=${f.fin}&numero_planilla=${encodeURIComponent(f.planilla||'')}&producto=${encodeURIComponent(f.producto||'')}&sucursal_entrega=${encodeURIComponent(f.sucursal_entrega||'')}${limit}`;
      const [faltR, reabast] = await Promise.all([
        API.get('/picking/novedades-stock', qs),
        API.get('/picking/reabastecimientos'),
      ]);
      const resp  = faltR.data || faltR || {};
      const falt  = Array.isArray(resp.rows) ? resp.rows : (Array.isArray(resp) ? resp : []);
      const total = resp.total ?? falt.length;
      const rea   = reabast.data || reabast || [];
      const conStock = falt.filter(r => {
        const upc = r.unidades_caja || 1;
        return (r.stock_actual||0) >= (r.cantidad_faltante||0) * upc;
      }).length;

      // Consolidado: agrupa el detalle ya filtrado por producto, sumando el faltante
      // (en cajas, puede quedar fraccionario) y contando planillas/pedidos afectados.
      const consolidado = (() => {
        const map = new Map();
        falt.forEach(r => {
          const key = r.producto_id;
          if (!map.has(key)) {
            map.set(key, {
              producto_id: r.producto_id,
              producto_nombre: r.producto_nombre,
              producto_codigo: r.producto_codigo,
              unidades_caja: r.unidades_caja || 1,
              cantidad_solicitada: 0,
              cantidad_separada: 0,
              cantidad_faltante: 0,
              stock_actual: r.stock_actual || 0,
              planillas: new Set(),
              sucursales: new Set(),
              motivos: new Set(),
            });
          }
          const g = map.get(key);
          g.cantidad_solicitada += (r.cantidad_solicitada || 0);
          g.cantidad_separada += (r.cantidad_separada || 0);
          g.cantidad_faltante += (r.cantidad_faltante || 0);
          g.stock_actual = Math.max(g.stock_actual, r.stock_actual || 0);
          if (r.numero_planilla) g.planillas.add(r.numero_planilla);
          if (r.sucursal_entrega) g.sucursales.add(r.sucursal_entrega);
          if (r.causal_nombre) g.motivos.add(r.causal_nombre);
        });
        return Array.from(map.values()).sort((a,b) => b.cantidad_faltante - a.cantidad_faltante);
      })();

      WMS.setContent(`
        <div style="display:flex;flex-direction:column;gap:16px;">
          <!-- Filtros -->
          <div class="card" style="padding:14px 18px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">DESDE</label>
                <input type="date" id="falt-ini" class="form-control" style="width:135px;" value="${f.ini}">
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">HASTA</label>
                <input type="date" id="falt-fin" class="form-control" style="width:135px;" value="${f.fin}">
              </div>
              <div style="flex:1;min-width:160px;">
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">PLANILLA</label>
                <input id="falt-plan" class="form-control" placeholder="Nro planilla..." value="${WMS.esc(f.planilla||'')}">
              </div>
              ${(WMS.user?.rol === 'Admin' || WMS.user?.rol === 'Supervisor') ? `
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">SUCURSAL</label>
                <select id="falt-suc" class="form-control" style="min-width:160px;">
                  <option value="">Todas</option>
                </select>
              </div>` : ''}
              <div style="flex:2;min-width:200px;">
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">PRODUCTO</label>
                <div class="search-bar" style="margin:0;"><i class="fa-solid fa-search"></i>
                  <input id="falt-prod" placeholder="Código o nombre..." value="${WMS.esc(f.producto||'')}">
                </div>
              </div>
              <button class="btn btn-primary" style="height:38px;padding:0 18px;" onclick="WMS_MODULES.picking._applyFaltFilters()">
                <i class="fa-solid fa-filter"></i> Filtrar
              </button>
              <button class="btn btn-secondary" style="height:38px;padding:0 14px;" onclick="WMS_MODULES.picking._clearFaltFilters()">
                <i class="fa-solid fa-broom"></i>
              </button>
            </div>
          </div>

          ${!f.showAll && falt.length >= 50 ? `<div style="padding:10px 16px;background:#fef9c3;border:1px solid #fde047;border-radius:6px;font-size:12px;color:#854d0e;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:15px;color:#f59e0b;"></i>
            <span>Mostrando los primeros <strong>50</strong> faltantes. Puede haber mas registros.</span>
            <button class="btn btn-xs btn-warning" style="margin-left:auto;" onclick="WMS_MODULES.picking.show_faltantes({showAll:true})">Ver Todos</button>
          </div>` : ''}

          ${conStock > 0 ? `<div style="padding:10px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:12px;color:#166534;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-circle-check" style="font-size:16px;color:#22c55e;"></i>
            <strong>${conStock} producto(s)</strong> ahora tienen stock disponible. Selecciónelos y haga clic en <strong>Procesar Backorder</strong> para reasignarlos al picking.
          </div>` : ''}

          <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;">
            <div class="card">
              <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <span class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> Faltantes de Stock
                  <span style="font-size:11px;color:#64748b;font-weight:400;margin-left:6px;">
                    ${f.vista === 'consolidado' ? `${consolidado.length} producto(s)` : `${falt.length} de ${total}`}
                  </span>
                </span>
                <div style="display:flex;gap:6px;align-items:center;">
                  <div class="btn-group" style="display:flex;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;">
                    <button class="btn btn-xs ${f.vista !== 'consolidado' ? 'btn-primary' : 'btn-secondary'}" style="border-radius:0;" onclick="WMS_MODULES.picking._toggleFaltVista('detalle')">
                      <i class="fa-solid fa-list"></i> Detalle
                    </button>
                    <button class="btn btn-xs ${f.vista === 'consolidado' ? 'btn-primary' : 'btn-secondary'}" style="border-radius:0;" onclick="WMS_MODULES.picking._toggleFaltVista('consolidado')">
                      <i class="fa-solid fa-layer-group"></i> Consolidado
                    </button>
                  </div>
                  ${f.vista !== 'consolidado' && conStock > 0 ? `<button class="btn btn-xs btn-success" onclick="WMS_MODULES.picking._selFaltConStock()" title="Seleccionar solo los que tienen stock">
                    <i class="fa-solid fa-check-double"></i> Sel. con stock (${conStock})
                  </button>` : ''}
                  ${f.vista !== 'consolidado' && !f.showAll && total > 50 ? `<button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking.show_faltantes({showAll:true})"><i class="fa-solid fa-list"></i> Todos (${total})</button>` : ''}
                  ${f.vista !== 'consolidado' && f.showAll ? `<button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.picking.show_faltantes({showAll:false})"><i class="fa-solid fa-compress"></i> Mostrar 50</button>` : ''}
                </div>
              </div>
              <div class="table-container">
                ${f.vista === 'consolidado' ? `
                <table class="erp-table">
                  <thead><tr>
                    <th>Producto</th>
                    <th style="text-align:center;">Solicitado</th>
                    <th style="text-align:center;">Separado</th>
                    <th style="text-align:center;">Faltante Total</th>
                    <th style="text-align:center;">Stock Disponible (cj/und)</th>
                    <th>Motivo</th>
                    <th style="text-align:center;">Planillas Afectadas</th>
                    <th>Sucursales Destino</th>
                  </tr></thead>
                  <tbody>${consolidado.map(g => {
                    const sa = g.stock_actual;
                    const upc = g.unidades_caja || 1;
                    const cf = g.cantidad_faltante;
                    const cfUnd = cf * upc;
                    return `<tr>
                    <td>
                      <div style="font-weight:700;font-size:12px;">${WMS.esc(g.producto_nombre||'-')}</div>
                      <div style="font-size:10px;color:#64748b;">${WMS.esc(g.producto_codigo||'')}</div>
                    </td>
                    <td style="text-align:center;">${this._fmtCajasDesglose(g.cantidad_solicitada, upc)}</td>
                    <td style="text-align:center;color:#059669;">${this._fmtCajasDesglose(g.cantidad_separada, upc)}</td>
                    <td style="text-align:center;">${this._fmtCajasDesglose(cf, upc, true)}</td>
                    <td style="text-align:center;">
                      ${sa >= cfUnd
                        ? `<span class="badge badge-success" title="Stock disponible para backorder"><i class="fa-solid fa-check" style="margin-right:3px;"></i>${(sa/upc).toFixed(2)} cj</span>
                           <div style="font-size:10px;color:#059669;">${WMS.formatNum(sa)} und</div>`
                        : `<span class="badge badge-danger" style="opacity:.7;">${(sa/upc).toFixed(2)} cj</span>
                           <div style="font-size:10px;color:#94a3b8;">${WMS.formatNum(sa)} und</div>`}
                    </td>
                    <td style="font-size:11px;">${Array.from(g.motivos).map(m => `<span class="pro-badge info" style="margin:1px;">${WMS.esc(m)}</span>`).join('') || '<span class="muted">Sin causal</span>'}</td>
                    <td style="text-align:center;">${g.planillas.size}</td>
                    <td style="font-size:11px;">${Array.from(g.sucursales).map(s => WMS.esc(s)).join(', ') || '-'}</td>
                  </tr>`;}).join('') || '<tr><td colspan="8" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin faltantes en el período</td></tr>'}
                  </tbody>
                </table>
                ` : `
                <table class="erp-table">
                  <thead><tr>
                    <th style="width:32px;text-align:center;">
                      <input type="checkbox" id="falt-sel-all" onchange="WMS_MODULES.picking._toggleAllFalt(this.checked)"
                             style="accent-color:#0070f2;width:14px;height:14px;">
                    </th>
                    <th>Fecha</th><th>Planilla</th><th>Auxiliar</th>
                    <th>Producto</th>
                    <th style="text-align:center;">Solicitado</th>
                    <th style="text-align:center;">Separado</th>
                    <th style="text-align:center;">Faltante</th>
                    <th style="text-align:center;">Stock Disponible (cj/und)</th>
                    <th>Motivo</th>
                    <th>Sucursal Destino</th><th>Cliente</th>
                  </tr></thead>
                  <tbody>${falt.map(r => {
                    const sa   = r.stock_actual || 0;
                    const cf   = r.cantidad_faltante || 0;
                    const upc  = r.unidades_caja || 1;
                    const cfUnd = cf * upc; // cajas → unidades para comparar con stock_actual (und)
                    const ok   = sa >= cfUnd;
                    return `<tr data-falt-id="${r.id}" data-has-stock="${ok?1:0}" style="${ok?'background:#f0fdf4;':''}">
                    <td style="text-align:center;" onclick="event.stopPropagation()">
                      <input type="checkbox" class="falt-sel" value="${r.producto_id}" onchange="WMS_MODULES.picking._onFaltCheck()"
                             style="accent-color:#0070f2;width:14px;height:14px;">
                    </td>
                    <td style="font-size:11px;white-space:nowrap;">${WMS.formatDate(r.created_at?.slice(0,10)||'-')}</td>
                    <td><span class="badge badge-info" style="font-size:11px;">${WMS.esc(r.numero_planilla||'-')}</span></td>
                    <td style="font-size:12px;">${WMS.esc(r.auxiliar||'-')}</td>
                    <td>
                      <div style="font-weight:700;font-size:12px;">${WMS.esc(r.producto_nombre||'-')}</div>
                      <div style="font-size:10px;color:#64748b;">${WMS.esc(r.producto_codigo||'')}</div>
                    </td>
                    <td style="text-align:center;">${this._fmtCajasDesglose(r.cantidad_solicitada, upc)}</td>
                    <td style="text-align:center;color:#059669;">${this._fmtCajasDesglose(r.cantidad_separada, upc)}</td>
                    <td style="text-align:center;">${this._fmtCajasDesglose(cf, upc, true)}</td>
                    <td style="text-align:center;">
                      ${ok
                        ? `<span class="badge badge-success" title="Stock disponible para backorder"><i class="fa-solid fa-check" style="margin-right:3px;"></i>${(sa/upc).toFixed(2)} cj</span>
                           <div style="font-size:10px;color:#059669;">${WMS.formatNum(sa)} und</div>`
                        : `<span class="badge badge-danger" style="opacity:.7;" title="Stock insuficiente">${(sa/upc).toFixed(2)} cj</span>
                           <div style="font-size:10px;color:#94a3b8;">${WMS.formatNum(sa)} und</div>`}
                    </td>
                    <td style="font-size:11px;">
                      ${r.causal_nombre ? `<span class="pro-badge info">${WMS.esc(r.causal_nombre)}</span><br>` : ''}
                      <span style="color:#64748b;">${WMS.esc(r.causa || 'Sin causal')}</span>
                    </td>
                    <td style="font-size:11px;">${WMS.esc(r.sucursal_entrega||'-')}</td>
                    <td style="font-size:11px;">${WMS.esc(r.cliente||'-')}</td>
                  </tr>`;}).join('') || '<tr><td colspan="12" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin faltantes en el período</td></tr>'}
                  </tbody>
                </table>
                `}
              </div>
            </div>

            <!-- Reabastecimientos -->
            <div class="card">
              <div class="card-header"><span class="card-title"><i class="fa-solid fa-rotate" style="color:#3b82f6;"></i> Reabastecimientos (${rea.length})</span></div>
              <div class="table-container">
                <table class="erp-table">
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
          </div>
        </div>`);
      if (WMS.user?.rol === 'Admin' || WMS.user?.rol === 'Supervisor') {
        await this._loadSucursales();
      }
    } catch(e) { if (e.isSessionExpired) return; console.error(e); WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando faltantes</p></div>'); }
  },

  _toggleFaltVista(v) {
    // El consolidado suma por producto sobre el detalle ya cargado; si venía
    // limitado a 50 filas los totales quedarían incompletos, así que se fuerza
    // a traer todo el período filtrado antes de agrupar.
    this.show_faltantes({ vista: v, showAll: v === 'consolidado' ? true : this._faltFilters.showAll });
  },

  _toggleAllFalt(checked) {
    document.querySelectorAll('.falt-sel').forEach(cb => { cb.checked = checked; });
    this._onFaltCheck();
  },

  _selFaltConStock() {
    document.querySelectorAll('.falt-sel').forEach(cb => { cb.checked = false; });
    document.querySelectorAll('tr[data-has-stock="1"] .falt-sel').forEach(cb => { cb.checked = true; });
    this._onFaltCheck();
  },

  _onFaltCheck() {
    const cnt = document.querySelectorAll('.falt-sel:checked').length;
    const btn = document.getElementById('btn-backorder');
    const span = document.getElementById('bo-count');
    if (btn) btn.style.display = cnt > 0 ? '' : 'none';
    if (span) span.textContent = cnt;
  },

  async _procesarBackorder() {
    const ids = Array.from(document.querySelectorAll('.falt-sel:checked')).map(cb => parseInt(cb.value));
    if (!ids.length) { WMS.toast('warning', 'Seleccione al menos un faltante'); return; }

    WMS.confirm('Procesar Backorder',
      `¿Reasignar <strong>${ids.length} faltante(s)</strong> al picking?<br><small>Solo se procesarán los que tengan stock disponible. Se reservará inventario y se reactivarán las líneas de picking.</small>`,
      async () => {
        try {
          const r = await API.post('/picking/backorder', { producto_ids: ids });
          if (r.error) { WMS.toast('error', r.message); return; }
          const d = r.data || {};
          const msgs = [];
          if (d.procesados > 0) msgs.push(`${d.procesados} reasignado(s)`);
          if (d.reservados > 0) msgs.push(`${d.reservados} unds reservadas`);
          if (d.sin_stock > 0) msgs.push(`${d.sin_stock} sin stock aún`);
          WMS.toast(d.sin_stock > 0 ? 'warning' : 'success', msgs.join(' • ') || 'Backorder procesado');
          this.show_faltantes();
        } catch(e) { if (e.isSessionExpired) return; WMS.toast('error', 'Error al procesar backorder'); console.error(e); }
      });
  },

  _applyFaltFilters() {
    const ini        = document.getElementById('falt-ini')?.value  || '';
    const fin        = document.getElementById('falt-fin')?.value  || '';
    const plan       = document.getElementById('falt-plan')?.value || '';
    const prod       = document.getElementById('falt-prod')?.value || '';
    const sucursal_entrega = document.getElementById('falt-suc')?.value || '';
    this.show_faltantes({ ini, fin, planilla: plan, producto: prod, sucursal_entrega, showAll: false });
  },

  _clearFaltFilters() {
    this._faltFilters = { ini: '', fin: '', planilla: '', producto: '', sucursal_entrega: '', showAll: false, vista: 'detalle' };
    this.show_faltantes();
  },

  async _limpiarFaltantes() {
    const f = this._faltFilters;
    const ini = f.ini || WMS.getToday();
    const fin = f.fin || WMS.getToday();
    const { isConfirmed } = await Swal.fire({
      title: '¿Limpiar faltantes?',
      text: `Eliminará los faltantes del período ${ini} al ${fin}. Esta acción no se puede deshacer.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, limpiar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#ef4444'
    });
    if (!isConfirmed) return;
    try {
      await API.delete(`/picking/faltantes?fecha_inicio=${ini}&fecha_fin=${fin}&sucursal_entrega=${encodeURIComponent(f.sucursal_entrega||'')}`);
      WMS.toast('success', 'Faltantes eliminados del período');
      this.show_faltantes();
    } catch(e) { WMS.toast('error', 'Error al limpiar faltantes'); }
  },

  async _exportFaltantes() {
    const f = this._faltFilters;
    const ini = f.ini || WMS.getToday();
    const fin = f.fin || WMS.getToday();
    const token = localStorage.getItem('wms_token') || '';
    const base = (window.API_BASE || '/WMS_FENIX/public/api');
    const url = `${base}/picking/novedades-stock?fecha_inicio=${ini}&fecha_fin=${fin}&numero_planilla=${encodeURIComponent(f.planilla||'')}&producto=${encodeURIComponent(f.producto||'')}&sucursal_entrega=${encodeURIComponent(f.sucursal_entrega||'')}&export=excel`;
    const a = document.createElement('a');
    a.href = url + '&_token=' + encodeURIComponent(token);
    a.download = `faltantes_${ini}_${fin}.csv`;
    a.click();
  },

  async _loadSucursales() {
    try {
      const resp = await API.get('/param/sucursales');
      const sucursales = Array.isArray(resp) ? resp : (resp.data || []);
      const sel = document.getElementById('falt-suc');
      if (!sel) return;
      const currentVal = this._faltFilters.sucursal_entrega || '';
      const opts = sucursales.map(s => {
        const nombre = s.nombre || s.name || String(s.id);
        return `<option value="${WMS.esc(nombre)}" ${nombre === currentVal ? 'selected' : ''}>${WMS.esc(nombre)}</option>`;
      }).join('');
      sel.innerHTML = `<option value="">Todas</option>${opts}`;
    } catch(e) {
      console.error('Error cargando sucursales:', e);
    }
  },

  async completarReabast(id) {
    try {
      const r = await API.post('/picking/reabast/' + id + '/completar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Reabastecimiento completado'); this.show_faltantes(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── MÓDULO AGOTADOS ───────────────────────────────────────────────────────
  _agotFilters: { ini: '', fin: '', sucursal: '', referencia: '' },

  async show_agotados(filters = null) {
    if (filters) Object.assign(this._agotFilters, filters);
    const f = this._agotFilters;
    if (!f.ini) f.ini = new Date(Date.now() - 30*24*3600*1000).toISOString().slice(0,10);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.show_agotados()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.picking._exportAgotados()">
        <i class="fa-solid fa-file-csv"></i> Exportar CSV
      </button>
    `);
    WMS.spinner();
    try {
      const qs  = `fecha_inicio=${f.ini}&fecha_fin=${f.fin}&sucursal_entrega=${encodeURIComponent(f.sucursal||'')}&referencia=${encodeURIComponent(f.referencia||'')}`;
      const r   = await API.get('/picking/agotados', qs);
      const d   = r.data || {};
      const rows = Array.isArray(d.rows) ? d.rows : [];
      const sucs = Array.isArray(d.sucursales) ? d.sucursales : [];

      const sucOptions = `<option value="">Todas las sucursales</option>` +
        sucs.map(s => `<option value="${WMS.esc(s)}"${f.sucursal===s?' selected':''}>${WMS.esc(s)}</option>`).join('');

      const totalCajas = rows.reduce((acc, row) => acc + (parseFloat(row.cantidad_faltante) || 0), 0);
      const sucAfect   = new Set(rows.map(row => row.sucursal_entrega)).size;

      WMS.setContent(`
        <div style="display:flex;flex-direction:column;gap:16px;">

          <div class="card" style="padding:14px 18px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">DESDE</label>
                <input type="date" id="agot-ini" class="form-control" style="width:135px;" value="${f.ini}">
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">HASTA</label>
                <input type="date" id="agot-fin" class="form-control" style="width:135px;" value="${f.fin}">
              </div>
              <div style="min-width:180px;">
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">SUCURSAL</label>
                <select id="agot-suc" class="form-control">${sucOptions}</select>
              </div>
              <div style="flex:2;min-width:200px;">
                <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">REFERENCIA</label>
                <div class="search-bar" style="margin:0;"><i class="fa-solid fa-search"></i>
                  <input id="agot-ref" placeholder="Código o nombre..." value="${WMS.esc(f.referencia||'')}">
                </div>
              </div>
              <button class="btn btn-primary" style="height:38px;padding:0 18px;" onclick="WMS_MODULES.picking._applyAgotFilters()">
                <i class="fa-solid fa-filter"></i> Filtrar
              </button>
              <button class="btn btn-secondary" style="height:38px;padding:0 14px;" onclick="WMS_MODULES.picking._clearAgotFilters()" title="Limpiar">
                <i class="fa-solid fa-broom"></i>
              </button>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
            <div class="card" style="padding:14px 18px;text-align:center;">
              <div style="font-size:2rem;font-weight:900;color:#dc2626;">${rows.length}</div>
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Registros Agotados</div>
            </div>
            <div class="card" style="padding:14px 18px;text-align:center;">
              <div style="font-size:2rem;font-weight:900;color:#f59e0b;">${WMS.formatNum(totalCajas)}</div>
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Total Cajas Faltantes</div>
            </div>
            <div class="card" style="padding:14px 18px;text-align:center;">
              <div style="font-size:2rem;font-weight:900;color:#0ea5e9;">${sucAfect}</div>
              <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Sucursales Afectadas</div>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <span class="card-title">
                <i class="fa-solid fa-box-open" style="color:#dc2626;"></i> Agotados de Picking
                <span style="font-size:11px;color:#64748b;font-weight:400;margin-left:6px;">${rows.length} registro(s)</span>
              </span>
            </div>
            <div class="table-container" style="overflow-x:auto;">
              <table class="erp-table" style="font-size:12px;">
                <thead><tr>
                  <th>Fecha</th>
                  <th>Sucursal</th>
                  <th>Pedido</th>
                  <th>Planilla</th>
                  <th>Cliente</th>
                  <th>Producto</th>
                  <th style="text-align:center;">Solicitado (cj)</th>
                  <th style="text-align:center;">Faltante (cj)</th>
                  <th>Causa</th>
                </tr></thead>
                <tbody>
                  ${rows.map(row => `<tr>
                    <td style="white-space:nowrap;font-size:11px;">${WMS.formatDate((row.fecha||'').slice(0,10))}</td>
                    <td><span class="badge badge-info" style="font-size:11px;">${WMS.esc(row.sucursal_entrega||'-')}</span></td>
                    <td style="font-family:monospace;font-size:11px;">${WMS.esc(row.numero_orden||'-')}</td>
                    <td><span class="badge badge-secondary" style="font-size:11px;">${WMS.esc(row.planilla_numero||row.planilla_lote||'-')}</span></td>
                    <td style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${WMS.esc(row.cliente||'')}">${WMS.esc(row.cliente||'-')}</td>
                    <td>
                      <div style="font-weight:700;color:#1e293b;">${WMS.esc(row.producto_nombre||'-')}</div>
                      <div style="font-size:10px;color:#64748b;font-family:monospace;">${WMS.esc(row.producto_codigo||'')}</div>
                    </td>
                    <td style="text-align:center;">${WMS.formatNum(row.cantidad_solicitada)}</td>
                    <td style="text-align:center;"><span class="badge badge-danger">${WMS.formatNum(row.cantidad_faltante)}</span></td>
                    <td style="font-size:11px;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${WMS.esc(row.causa||'')}">${WMS.esc(row.causa||'-')}</td>
                  </tr>`).join('') || '<tr><td colspan="9" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin agotados en el período seleccionado</td></tr>'}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch(e) {
      if (e.isSessionExpired) return;
      console.error(e);
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando agotados</p></div>');
    }
  },

  _applyAgotFilters() {
    const ini = document.getElementById('agot-ini')?.value || '';
    const fin = document.getElementById('agot-fin')?.value || '';
    const suc = document.getElementById('agot-suc')?.value || '';
    const ref = document.getElementById('agot-ref')?.value || '';
    this.show_agotados({ ini, fin, sucursal: suc, referencia: ref });
  },

  _clearAgotFilters() {
    this._agotFilters = { ini: '', fin: '', sucursal: '', referencia: '' };
    this.show_agotados();
  },

  _exportAgotados() {
    const f   = this._agotFilters;
    const ini = f.ini || new Date(Date.now()-30*24*3600*1000).toISOString().slice(0,10);
    const fin = f.fin || WMS.getToday();
    const token = localStorage.getItem('wms_token') || '';
    const base  = window.API_BASE || '/WMS_FENIX/public/api';
    const url   = `${base}/picking/agotados?fecha_inicio=${ini}&fecha_fin=${fin}&sucursal_entrega=${encodeURIComponent(f.sucursal||'')}&referencia=${encodeURIComponent(f.referencia||'')}&export=csv&_token=${encodeURIComponent(token)}`;
    const a = document.createElement('a');
    a.href = url;
    a.download = `agotados_${ini}_${fin}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  },

  // ── DASHBOARD PICKING  ·  Command Center Logístico ───────────────────────
  _pickingChart: null,
  _pickDonutChart: null,
  _timerInterval: null,

  _currentRankingMetric: 'unidades',
  _currentRankingData: [],
  
  _renderPodiumHtml(rankingArray) {
    if (!rankingArray || !rankingArray.length) return '<div class="table-empty">No hay datos</div>';
    
    // Create a copy to sort safely
    const r = [...rankingArray];
    const metric = this._currentRankingMetric || 'unidades';
    
    r.sort((a, b) => {
        if (metric === 'avg_minutos') {
            const aVal = parseFloat(a.avg_minutos) || 999999;
            const bVal = parseFloat(b.avg_minutos) || 999999;
            return aVal - bVal; // fastest first
        } else {
            return (b[metric] || 0) - (a[metric] || 0); // highest first
        }
    });
    
    const top1 = r[0];
    const top2 = r.length > 1 ? r[1] : null;
    const top3 = r.length > 2 ? r[2] : null;
    
    const formatMetric = (item) => {
        if (metric === 'avg_minutos') {
            const m = parseFloat(item.avg_minutos) || 0;
            if (m >= 60) {
                const hrs = Math.floor(m / 60);
                const mins = Math.round(m % 60);
                return `${hrs}h ${mins}m`;
            }
            return `${Math.round(m)} min`;
        }
        if (metric === 'promedio_linea') {
            const m = parseFloat(item.avg_minutos) || 0;
            const l = parseInt(item.lineas) || 0;
            const avg = l > 0 ? (m / l) : 0;
            return `${avg.toFixed(2)} min/l`;
        }
        if (metric === 'pedidos') return `${WMS.formatNum(item.pedidos || 0)} pds`;
        if (metric === 'lineas') return `${WMS.formatNum(item.lineas || 0)} lín`;
        return `${WMS.formatNum(item.unidades || 0)} unds`;
    };
    
    return `<div style="display:flex; justify-content:center; align-items:flex-end; gap:10px; margin:20px 0; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
      <!-- Puesto 2 -->
      ${top2 ? `<div style="display:flex; flex-direction:column; align-items:center; width:120px;">
        <div style="font-size:10px; font-weight:700; color:#64748b; margin-bottom:5px; text-align:center;">${WMS.esc(top2.nombre)}</div>
        <div style="font-size:11px; color:#10b981; font-weight:bold; margin-bottom:5px;">${formatMetric(top2)}</div>
        <div style="width:100%; background:linear-gradient(180deg, #94a3b8, #cbd5e1); height:70px; border-radius:8px 8px 0 0; display:flex; justify-content:center; align-items:flex-start; padding-top:10px; color:#fff; font-size:24px; font-weight:900; text-shadow:0 2px 4px rgba(0,0,0,0.2); box-shadow:0 -4px 10px rgba(0,0,0,0.1);">2</div>
      </div>` : '<div style="width:120px;"></div>'}
      
      <!-- Puesto 1 -->
      ${top1 ? `<div style="display:flex; flex-direction:column; align-items:center; width:130px; z-index:2;">
        <div style="position:relative; top:5px;"><i class="fa-solid fa-crown" style="color:#f59e0b; font-size:24px; filter:drop-shadow(0 2px 2px rgba(245,158,11,0.4));"></i></div>
        <div style="font-size:11px; font-weight:900; color:#1e293b; margin-top:10px; margin-bottom:5px; text-align:center;">${WMS.esc(top1.nombre)}</div>
        <div style="font-size:12px; color:#10b981; font-weight:bold; margin-bottom:5px;">${formatMetric(top1)}</div>
        <div style="width:100%; background:linear-gradient(180deg, #fbbf24, #f59e0b); height:100px; border-radius:8px 8px 0 0; display:flex; justify-content:center; align-items:flex-start; padding-top:10px; color:#fff; font-size:32px; font-weight:900; text-shadow:0 2px 4px rgba(0,0,0,0.2); box-shadow:0 -4px 15px rgba(245,158,11,0.3);">1</div>
      </div>` : ''}
      
      <!-- Puesto 3 -->
      ${top3 ? `<div style="display:flex; flex-direction:column; align-items:center; width:120px;">
        <div style="font-size:10px; font-weight:700; color:#64748b; margin-bottom:5px; text-align:center;">${WMS.esc(top3.nombre)}</div>
        <div style="font-size:11px; color:#10b981; font-weight:bold; margin-bottom:5px;">${formatMetric(top3)}</div>
        <div style="width:100%; background:linear-gradient(180deg, #d97706, #b45309); height:50px; border-radius:8px 8px 0 0; display:flex; justify-content:center; align-items:flex-start; padding-top:10px; color:#fff; font-size:20px; font-weight:900; text-shadow:0 2px 4px rgba(0,0,0,0.2); box-shadow:0 -4px 10px rgba(0,0,0,0.1);">3</div>
      </div>` : '<div style="width:120px;"></div>'}
    </div>`;
  },
  
  _renderRankingTbody(rankingArray) {
    if (!rankingArray || !rankingArray.length) return '<tr><td colspan="6" class="table-empty">No hay actividad registrada</td></tr>';
    
    const r = [...rankingArray];
    const metric = this._currentRankingMetric || 'unidades';
    
    r.sort((a, b) => {
        if (metric === 'avg_minutos') {
            const aVal = parseFloat(a.avg_minutos) || 999999;
            const bVal = parseFloat(b.avg_minutos) || 999999;
            return aVal - bVal; 
        } else if (metric === 'promedio_linea') {
            const aVal = (parseInt(a.lineas) > 0) ? (parseFloat(a.avg_minutos) / parseInt(a.lineas)) : 999999;
            const bVal = (parseInt(b.lineas) > 0) ? (parseFloat(b.avg_minutos) / parseInt(b.lineas)) : 999999;
            return aVal - bVal; // fastest first
        } else {
            return (b[metric] || 0) - (a[metric] || 0); 
        }
    });

    const maxVal = r.length ? (
        metric === 'avg_minutos' ? Math.max(...r.map(x=>parseFloat(x.avg_minutos)||0)) : 
        (metric === 'promedio_linea' ? Math.max(...r.map(x=> (parseInt(x.lineas) > 0 ? parseFloat(x.avg_minutos)/parseInt(x.lineas) : 0))) : r[0][metric])
    ) : 1;
    
    return r.map((a,i) => {
        let val = 0;
        if (metric === 'avg_minutos') val = parseFloat(a.avg_minutos) || 0;
        else if (metric === 'promedio_linea') val = (parseInt(a.lineas) > 0) ? (parseFloat(a.avg_minutos) / parseInt(a.lineas)) : 0;
        else val = a[metric] || 0;
        
        let pct = maxVal > 0 ? Math.round((val / maxVal) * 100) : 0;
        
        return `<tr>
          <td class="text-center"><span style="font-weight:900; color:#94a3b8">${i+1}</span></td>
          <td><b>${WMS.esc(a.nombre)}</b></td>
          <td class="text-center"><b>${a.pedidos}</b></td>
          <td class="text-center"><b>${a.lineas}</b></td>
          <td class="text-center"><span class="badge badge-info">${WMS.formatNum(a.unidades)}</span></td>
          <td>
            <div style="background:#f1f5f9; height:6px; border-radius:99px; overflow:hidden;">
              <div style="width:${pct}%; background:linear-gradient(to right, ${metric==='avg_minutos'?'#ef4444, #f59e0b':'#3b82f6, #10b981'}); height:100%;"></div>
            </div>
          </td>
        </tr>`;
    }).join('');
  },
  
  _renderMatrixHtml(rankingArray) {
    if (!rankingArray || !rankingArray.length) return '';
    
    // Calculate average minutes per line and sort fastest first (lowest avgPerLine)
    const r = [...rankingArray].map(a => {
        const totalMin = parseFloat(a.avg_minutos) || 0;
        const lineas = parseInt(a.lineas) || 0;
        const avgPerLine = lineas > 0 ? (totalMin / lineas) : 0;
        return { ...a, avgPerLine };
    }).filter(a => a.lineas > 0).sort((a, b) => a.avgPerLine - b.avgPerLine).slice(0, 5); // top 5
    
    if (r.length === 0) return '';
    
    return `<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; height:100%; min-width:200px;">
      <div style="font-size:11px; font-weight:700; color:#475569; margin-bottom:8px; text-align:center;"><i class="fa-solid fa-stopwatch" style="color:#10b981;"></i> Promedio por Línea</div>
      <table style="width:100%; font-size:11px; border-collapse:collapse;">
        <tbody>
          ${r.map((a, i) => `
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:4px 0; color:#64748b; font-weight:600;">${i+1}. ${WMS.esc(a.nombre.split(' ')[0])}</td>
              <td style="padding:4px 0; text-align:right; font-weight:700; color:${a.avgPerLine < 2 ? '#10b981' : (a.avgPerLine < 4 ? '#f59e0b' : '#ef4444')}">${a.avgPerLine.toFixed(1)} min/lín</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>`;
  },
  
  _setRankingMetric(metric) {
    this._currentRankingMetric = metric;
    const pCont = document.getElementById('podium-container');
    const tCont = document.getElementById('ranking-tbody');
    const mCont = document.getElementById('matrix-container');
    if (pCont && tCont && this._currentRankingData) {
      pCont.innerHTML = this._renderPodiumHtml(this._currentRankingData);
      tCont.innerHTML = this._renderRankingTbody(this._currentRankingData);
      if (mCont) mCont.innerHTML = this._renderMatrixHtml(this._currentRankingData);
    }
  },

  async show_dashboard(silent = false) {
    WMS.currentSubModule = 'dashboard';
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
      const suc_fil  = document.getElementById('dash-f-suc')?.value  || '';
      const aux_fil  = document.getElementById('dash-f-aux')?.value  || '';
      const plan_fil = document.getElementById('dash-f-plan')?.value || '';
      const ref_fil  = (document.getElementById('dash-f-ref')?.value || '').toLowerCase().trim();

      const query = `fecha_inicio=${f_inicio}&fecha_fin=${f_fin}&estado=${st_fil}`;
      const [dash, allPicking] = await Promise.all([
        API.get('/picking/dashboard', query),
        API.get('/picking', query + '&limit=1000'),
      ]);

      const d   = dash.data || dash || {};
      const all = allPicking.data || allPicking || [];
      const grupos = this._agruparPorPlanilla(all);

      // Build filter option lists from full dataset
      const sucursales = [...new Set(all.map(o => o.sucursal_entrega).filter(Boolean))].sort();
      
      const auxSet = new Set();
      grupos.forEach(g => g.auxiliares.forEach(a => auxSet.add(a)));
      const auxiliares = [...auxSet].sort();
      const planillas  = [...new Set(all.map(o => o.planilla_numero || o.planilla_lote).filter(Boolean))].sort();

      // Aplicar filtros al array de órdenes y detalles (Deep filter)
      let filteredAll = all;
      if (suc_fil) {
          filteredAll = filteredAll.filter(o => (o.sucursal_entrega||'') === suc_fil);
      }
      if (plan_fil) {
          filteredAll = filteredAll.filter(o => (o.planilla_numero || o.planilla_lote) === plan_fil);
      }
      if (ref_fil) {
          filteredAll = filteredAll.filter(o => 
              (o.planilla_numero||'').toLowerCase().includes(ref_fil) ||
              (o.numero_orden||'').toLowerCase().includes(ref_fil) || 
              (o.numero_pedido||'').toLowerCase().includes(ref_fil) || 
              (o.ruta||'').toLowerCase().includes(ref_fil)
          );
      }
      
      if (aux_fil) {
          filteredAll = filteredAll.map(o => {
              const oAux = o.auxiliar?.nombre || o.usuario;
              const matchDetails = o.detalles?.filter(dt => dt.auxiliar?.nombre === aux_fil) || [];
              
              if (matchDetails.length > 0) {
                  // Si tiene detalles explícitos para este auxiliar, mostrar SOLO esas líneas.
                  return { ...o, detalles: matchDetails };
              } else if (oAux === aux_fil) {
                  // Si toda la orden es de este auxiliar pero las líneas no tienen un auxiliar específico
                  return o;
              }
              return null;
          }).filter(Boolean);
      }
      
      const gruposVis = this._agruparPorPlanilla(filteredAll);

      // KPIs
      let totalL = 0, pendL = 0, okL = 0, pctG = 0;
      let stCount = { Pendiente: 0, EnProceso: 0, Completado: 0 };
      
      if (aux_fil || suc_fil || plan_fil || ref_fil) {
          // Si hay filtros, calcular KPIs basados en las planillas filtradas
          gruposVis.forEach(g => {
              totalL += g.total_lineas;
              pendL += g.lineas_pendientes;
              if (g.estado === 'Pendiente' || g.estado === 'Asignado') stCount.Pendiente++;
              else if (g.estado === 'EnProceso') stCount.EnProceso++;
              else if (g.estado === 'Completado') stCount.Completado++;
          });
          okL = Math.max(0, totalL - pendL);
          pctG = totalL > 0 ? Math.round((okL / totalL) * 100) : 0;
      } else {
          // Sin filtros, usar métricas globales reales del backend
          totalL  = parseInt(d.total_lineas_activas || 0);
          pendL   = parseInt(d.lineas_pendientes || 0);
          okL     = Math.max(0, totalL - pendL);
          pctG    = totalL > 0 ? Math.round((okL / totalL) * 100) : 0;
          stCount = { Pendiente: d.pendientes||0, EnProceso: d.en_proceso||0, Completado: d.completadas||0 };
      }

      const tableRows = gruposVis.map(g => this._renderPlanillaRow(g, { isDashboard: true })).join('');

      this._currentRankingData = d.ranking_auxiliares || [];

      WMS.setContent(`
<div class="pro-dashboard">
  <div class="filter-bar dashboard-filters" style="background:#fff;padding:16px;border-radius:4px;margin-bottom:20px;box-shadow:0 4px 20px rgba(0,0,0,.04);display:flex;flex-wrap:wrap;gap:18px;align-items:center;border:1px solid #f1f5f9;">
    <div class="filter-group"><label>PERIODO</label>
      <div style="display:flex;gap:5px;">
        <input type="date" id="dash-f-ini" class="form-control" style="width:130px;" value="${f_inicio}" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <input type="date" id="dash-f-fin" class="form-control" style="width:130px;" value="${f_fin}" onchange="WMS_MODULES.picking.show_dashboard(true)">
      </div>
    </div>
    <div class="filter-group"><label>SUCURSAL ENTREGA</label>
      <select id="dash-f-suc" class="form-control" style="width:180px;" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <option value="">Todas</option>
        ${sucursales.map(s=>`<option value="${WMS.esc(s)}" ${suc_fil===s?'selected':''}>${WMS.esc(s)}</option>`).join('')}
      </select>
    </div>
    <div class="filter-group"><label>AUXILIAR</label>
      <select id="dash-f-aux" class="form-control" style="width:160px;" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <option value="">Todos</option>
        ${auxiliares.map(a=>`<option value="${WMS.esc(a)}" ${aux_fil===a?'selected':''}>${WMS.esc(a)}</option>`).join('')}
      </select>
    </div>
    <div class="filter-group"><label>PLANILLA</label>
      <select id="dash-f-plan" class="form-control" style="width:160px;" onchange="WMS_MODULES.picking.show_dashboard(true)">
        <option value="">Todas</option>
        ${planillas.map(p=>`<option value="${WMS.esc(p)}" ${plan_fil===p?'selected':''}>${WMS.esc(p)}</option>`).join('')}
      </select>
    </div>
    <div class="filter-group" style="flex:1;min-width:160px;"><label>REFERENCIA / PEDIDO</label>
      <div class="search-bar" style="margin:0;min-width:160px;"><i class="fa-solid fa-search"></i>
        <input id="dash-f-ref" placeholder="Pedido, ruta, planilla..." value="${WMS.esc(ref_fil)}" onkeypress="if(event.key==='Enter')WMS_MODULES.picking.show_dashboard(true)">
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
        <table class="erp-table" style="font-size:11px;">
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
        <div style="display:flex; gap:10px; font-size:11px; color:#94a3b8; font-weight:600; align-items:center;">
            <span>ORDENAR POR:</span>
            <select class="form-control form-control-sm" style="width:120px; padding:2px 8px; height:26px; font-size:11px;" onchange="WMS_MODULES.picking._setRankingMetric(this.value)">
                <option value="unidades" ${this._currentRankingMetric==='unidades'||!this._currentRankingMetric?'selected':''}>UNIDADES</option>
                <option value="pedidos" ${this._currentRankingMetric==='pedidos'?'selected':''}>PEDIDOS</option>
                <option value="lineas" ${this._currentRankingMetric==='lineas'?'selected':''}>LÍNEAS</option>
                <option value="avg_minutos" ${this._currentRankingMetric==='avg_minutos'?'selected':''}>TIEMPO TOTAL</option>
                <option value="promedio_linea" ${this._currentRankingMetric==='promedio_linea'?'selected':''}>PROMEDIO X LÍNEA</option>
            </select>
        </div>
      </div>
      
      <!-- Visual Podium y Matrix -->
      <div style="display:flex; flex-wrap:wrap; gap:20px; align-items:center; justify-content:center; padding: 0 10px;">
        <div id="podium-container" style="flex:1; min-width:350px;">
          ${this._renderPodiumHtml(this._currentRankingData)}
        </div>
        <div id="matrix-container" style="width:250px;">
          ${this._renderMatrixHtml(this._currentRankingData)}
        </div>
      </div>

      <div class="table-container" style="max-height:300px;">
        <table class="erp-table">
          <thead><tr><th>#</th><th>Auxiliar</th><th class="text-center">Pedidos</th><th class="text-center">Líneas</th><th class="text-center">Unid. Pick</th><th style="width:100px;">Desempeño</th></tr></thead>
          <tbody id="ranking-tbody">${this._renderRankingTbody(this._currentRankingData)}</tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-list-check"></i> Proceso por Planilla y Tiempos Operacionales</span></div>
    <div class="table-container">
      <table class="erp-table" id="dash-table">
        <thead><tr>
          <th>Planilla</th><th>Fecha Sep.</th><th>Clientes / Sucursal</th><th>Ruta</th><th class="text-center">Avance</th>
          <th style="min-width:130px;">Progreso</th><th>Estado</th><th>Encargado(s)</th>
          <th class="text-center">H. Inicio</th><th class="text-right">Obs</th>
        </tr></thead>
        <tbody>${tableRows || '<tr><td colspan="10" class="table-empty">Sin planillas en este rango</td></tr>'}</tbody>
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

  // ── PRODUCTOS PENDIENTES DE CODIFICACIÓN ─────────────────────────────────
  async show_productos_pendientes(silent = false) {
    WMS.setBreadcrumb('picking', 'Productos Pendientes');
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking.show_productos_pendientes()">
        <i class="fa-solid fa-sync"></i> Actualizar
      </button>
      <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.picking._limpiarPendientes()">
        <i class="fa-solid fa-trash"></i> Limpiar Todo
      </button>`);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/picking/productos-pendientes');
      const items = r.data || [];
      this._pendientesCache = items;
      WMS.setContent(`
        <div class="px-20 py-16">
          <div class="card shadow-soft">
            <div class="card-header d-flex justify-between align-center">
              <span class="card-title fw-900 color-primary">
                <i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Productos sin Codificar
              </span>
              <span class="text-xs text-muted">${items.length} EAN(s) pendiente(s)</span>
            </div>
            <div style="padding:10px 16px;background:#fefce8;border-bottom:1px solid #fde68a;font-size:12px;color:#78350f;">
              <i class="fa-solid fa-info-circle"></i> Estos EAN aparecieron en importaciones pero no existen en la maestra de productos.
              Use <strong>Crear Producto</strong> para codificarlos directamente aquí, luego reimporte el CSV para incluirlos en las planillas.
            </div>
            <div class="table-container">
              <table class="erp-table">
                <thead><tr>
                  <th>COD</th><th>Descripción</th><th>Sucursal</th>
                  <th>Fecha Importación</th><th class="text-center">Cantidad</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                  ${items.length === 0
                    ? '<tr><td colspan="6" class="table-empty"><i class="fa-solid fa-check-circle" style="color:#16a34a;"></i> No hay productos pendientes de codificación</td></tr>'
                    : items.map(it => `<tr>
                        <td class="fw-800" style="font-family:monospace;">${WMS.esc(it.ean_codigo)}</td>
                        <td style="max-width:220px;white-space:normal;font-size:.8rem;">${WMS.esc(it.descripcion || '—')}</td>
                        <td style="font-size:.8rem;">${WMS.esc(it.sucursal_entrega || '—')}</td>
                        <td style="font-size:.8rem;">${WMS.formatDate(it.fecha_importacion)}</td>
                        <td class="text-center">${it.cantidad || 1}</td>
                        <td style="white-space:nowrap;">
                          <button title="Crear producto en catálogo"
                            onclick="WMS_MODULES.picking._crearProductoDesdePendiente(${parseInt(it.id)||0})"
                            style="background:#dcfce7;color:#166534;border:none;border-radius:3px;padding:3px 10px;cursor:pointer;font-size:.72rem;font-weight:600;margin-right:4px;">
                            <i class="fa-solid fa-plus"></i> Crear
                          </button>
                          <button title="Eliminar de la lista"
                            onclick="WMS_MODULES.picking._eliminarPendiente(${parseInt(it.id)||0})"
                            style="background:#fee2e2;color:#991b1b;border:none;border-radius:3px;padding:3px 8px;cursor:pointer;font-size:.72rem;">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </td>
                      </tr>`).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>`);
    } catch (e) { WMS.toast('error', 'Error cargando pendientes'); }
  },

  async _crearProductoDesdePendiente(pendienteId) {
    const it = (this._pendientesCache || []).find(x => x.id == pendienteId);
    if (!it) { WMS.toast('error', 'Registro no encontrado'); return; }
    WMS.spinner();
    try {
      const [cs, ms] = await Promise.all([API.get('/param/categorias'), API.get('/param/marcas')]);
      const cats  = cs.data || cs || [];
      const marcas = ms.data || ms || [];
      WMS.showModal('Codificar Producto desde Pendiente', `
        <div style="background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.8rem;color:#78350f;display:flex;gap:8px;align-items:flex-start;">
          <i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;flex-shrink:0;"></i>
          <span>EAN <strong>${WMS.esc(it.ean_codigo)}</strong> encontrado en pedidos de picking (Factura: ${WMS.esc(it.numero_factura||'—')}, Sucursal: ${WMS.esc(it.sucursal_entrega||'—')}).
          Tras crear el producto, <strong>reimporte el CSV</strong> para incluirlo en las planillas automáticamente.</span>
        </div>
        <div class="form-grid form-grid-2">
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">EAN / CÓDIGO INTERNO <span class="required">*</span></label>
            <input id="pp-ean" class="form-control" value="${WMS.esc(it.ean_codigo)}" placeholder="Código de barras o código interno">
          </div>
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">NOMBRE DEL PRODUCTO <span class="required">*</span></label>
            <input id="pp-nombre" class="form-control" value="${WMS.esc(it.descripcion || '')}" placeholder="Nombre completo del producto">
          </div>
          <div class="form-group">
            <label class="form-label">CATEGORÍA</label>
            <select id="pp-cat" class="form-control">
              <option value="">-- Sin categoría --</option>
              ${cats.map(c => `<option value="${parseInt(c.id)||0}">${WMS.esc(c.nombre || c.marca || '')}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">MARCA</label>
            <select id="pp-marca" class="form-control">
              <option value="">-- Sin marca --</option>
              ${marcas.map(m => `<option value="${parseInt(m.id)||0}">${WMS.esc(m.nombre)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">AMBIENTE DE ALMACÉN</label>
            <select id="pp-amb" class="form-control">
              <option value="Seco">🌡️ Seco (temperatura ambiente)</option>
              <option value="Refrigerado">❄️ Refrigerado (0°–8°C)</option>
              <option value="Congelado">🧊 Congelado (bajo 0°C)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">UNIDAD DE MEDIDA</label>
            <select id="pp-um" class="form-control">
              ${['UN','KG','LT','ML','GR','CJ','BL','PQ','RO','PA'].map(u => `<option>${u}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">PESO UNITARIO (kg)</label>
            <input id="pp-peso" class="form-control" type="number" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="form-group">
            <label class="form-label">UNIDADES POR CAJA</label>
            <input id="pp-uxc" class="form-control" type="number" value="1" min="1">
          </div>
          <div style="grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#f8fafc;padding:12px;border-radius:6px;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div>
                <div style="font-size:.78rem;font-weight:600;color:#475569;">Maneja Lotes</div>
                <div style="font-size:.68rem;color:#94a3b8;">Control de trazabilidad por lote</div>
              </div>
              <label class="wms-switch sm"><input type="checkbox" id="pp-lote"><span class="slider"></span></label>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div>
                <div style="font-size:.78rem;font-weight:600;color:#475569;">Controla Vencimiento</div>
                <div style="font-size:.68rem;color:#94a3b8;">FEFO en picking y despacho</div>
              </div>
              <label class="wms-switch sm"><input type="checkbox" id="pp-venc"><span class="slider"></span></label>
            </div>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.picking._saveProductoPendiente(${parseInt(pendienteId)||0})">
           <i class="fa-solid fa-box-open"></i> Crear Producto
         </button>`
      );
    } catch(e) {
      WMS.toast('error', 'Error cargando formulario: ' + (e.message||''));
    }
  },

  async _saveProductoPendiente(pendienteId) {
    const pv = s => { if (!s) return 0; const v = parseFloat(String(s).replace(',','.')); return isNaN(v) ? 0 : v; };
    const ean    = document.getElementById('pp-ean')?.value.trim()   || '';
    const nombre = document.getElementById('pp-nombre')?.value.trim() || '';
    if (!ean)    { WMS.toast('warning', 'El EAN / Código es obligatorio');    return; }
    if (!nombre) { WMS.toast('warning', 'El Nombre del producto es obligatorio'); return; }

    const data = {
      codigo_interno:       ean,
      codigo_ean:           ean,
      nombre,
      descripcion:          nombre,
      categoria_id:         document.getElementById('pp-cat')?.value   || null,
      marca_id:             document.getElementById('pp-marca')?.value  || null,
      temperatura_almacen:  document.getElementById('pp-amb')?.value   || 'Seco',
      unidad_medida:        document.getElementById('pp-um')?.value     || 'UN',
      peso_unitario:        pv(document.getElementById('pp-peso')?.value),
      unidades_caja:        parseInt(document.getElementById('pp-uxc')?.value  || 1),
      maneja_lotes:         document.getElementById('pp-lote')?.checked  ? 1 : 0,
      controla_vencimiento: document.getElementById('pp-venc')?.checked  ? 1 : 0,
    };

    try {
      const r = await API.post('/param/productos', data);
      if (r.error) { WMS.toast('error', r.message || 'Error al crear el producto'); return; }
      // Auto-eliminar de la tabla de pendientes
      try { await API.delete('/picking/productos-pendientes/' + pendienteId); } catch(_) {}
      WMS.closeModal('generic-modal');
      WMS.toast('success', `Producto "${nombre}" creado. Reimporte el CSV para incluirlo en las planillas.`);
      this.show_productos_pendientes(true);
    } catch(e) {
      WMS.toast('error', e.message || 'Error de conexión al crear el producto');
    }
  },

  async _eliminarPendiente(id) {
    try {
      await API.delete('/picking/productos-pendientes/' + id);
      WMS.toast('success', 'Eliminado');
      this.show_productos_pendientes(true);
    } catch (e) { WMS.toast('error', e.message); }
  },

  async _limpiarPendientes() {
    if (!confirm('¿Eliminar TODOS los productos pendientes? Esta acción no se puede deshacer.')) return;
    try {
      await API.delete('/picking/productos-pendientes');
      WMS.toast('success', 'Tabla limpiada');
      this.show_productos_pendientes(true);
    } catch (e) { WMS.toast('error', e.message); }
  },

  // ── MÓDULO RESERVAS ───────────────────────────────────────────────────────
  _reservasData: [],          // cache de datos originales
  _reservasSort: { col: 'diferencia', dir: 1 },  // columna activa y dirección

  _reservasFiltros: { fecha_desde: '', fecha_hasta: '', sucursal_id: '' },

  async show_reservas() {
    WMS.setBreadcrumb('picking', 'Reservas');
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking._cargarReservas()">
        <i class="fa-solid fa-sync"></i> Actualizar
      </button>
      <button class="btn btn-sm" style="background:#16a34a;color:#fff;border:none;" onclick="WMS_MODULES.picking._exportarReservasCSV()">
        <i class="fa-solid fa-file-csv"></i> Exportar CSV
      </button>`);
    const today = this.getToday();
    const hace30 = new Date(Date.now() - 30*24*60*60*1000).toISOString().split('T')[0];
    this._reservasFiltros = { fecha_desde: hace30, fecha_hasta: today, sucursal_id: '' };
    await this._cargarReservas();
  },

  async _cargarReservas() {
    WMS.spinner();
    try {
      const f = this._reservasFiltros || {};
      const params = new URLSearchParams();
      if (f.fecha_desde)  params.append('fecha_desde', f.fecha_desde);
      if (f.fecha_hasta)  params.append('fecha_hasta', f.fecha_hasta);
      if (f.sucursal_id)  params.append('sucursal_id', f.sucursal_id);
      const qs = params.toString() ? '?' + params.toString() : '';
      const r = await API.get('/picking/reservas' + qs);
      // ok() envuelve: r.data = {data:[grupos], pagination:{}}  ⟶ extraer r.data.data
      // Grupos: [{nombre_sucursal, referencias:[{...}]}]  ⟶ aplanar a items planos
      const _rvGrupos = Array.isArray(r.data) ? r.data : (r.data?.data || []);
      this._reservasData = _rvGrupos.flatMap(grupo =>
        (grupo.referencias || []).map(ref => ({
          codigo:            ref.codigo_producto,
          descripcion:       ref.nombre_producto,
          sucursal:          grupo.nombre_sucursal,
          ubicacion:         ref.ubicacion_asignada || '',
          cantidad_solicitada: ref.cantidad_solicitada,
          separada:          ref.cantidad_separada,
          pendiente:         ref.pendiente,
          fecha_vencimiento: ref.fecha_vencimiento || '',
          stock_total:       ref.stock_total,
          stock_reservado:   (ref.stock_total || 0) - (ref.stock_disponible || 0),
          stock_disponible:  ref.stock_disponible,
          total_pedido:      ref.pendiente,
          diferencia:        (ref.stock_disponible || 0) - (ref.pendiente || 0),
        }))
      );
      this._reservasSort = { col: 'diferencia', dir: 1 };
      this._renderReservas();
    } catch (e) { WMS.toast('error', 'Error cargando reservas'); }
  },

  _exportarReservasCSV() {
    const data = this._reservasData || [];
    if (!data.length) { WMS.toast('warning', 'No hay datos para exportar'); return; }
    const headers = ['Código','Descripción','Sucursal','Ubicación','Cant. Solicitada','Separada','Pendiente','Fecha Vencimiento','Stock Total','Stock Reservado','Stock Disponible','En Pedidos','Diferencia','Estado'];
    const rows = data.map(it => {
      const d = parseFloat(it.diferencia);
      const estado = d < 0 ? 'AGOTADO' : d < parseFloat(it.total_pedido||0)*0.2 ? 'RIESGO' : 'OK';
      return [
        it.codigo||'', it.descripcion||'', it.sucursal||'', it.ubicacion||'',
        it.cantidad_solicitada||'', it.separada||'', it.pendiente||'', it.fecha_vencimiento||'',
        it.stock_total||'', it.stock_reservado||'', it.stock_disponible||'',
        it.total_pedido||'', it.diferencia||'', estado
      ].map(v => '"' + String(v).replace(/"/g,'""') + '"');
    });
    const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'Reservas_Picking_' + this.getToday() + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
    WMS.toast('success', 'CSV generado correctamente');
  },

  _renderReservas() {
    const allItems = this._reservasData;
    const agotados = allItems.filter(x => parseFloat(x.diferencia) <  0);
    const riesgo   = allItems.filter(x => parseFloat(x.diferencia) >= 0 && parseFloat(x.diferencia) < parseFloat(x.total_pedido) * 0.2);
    const ok       = allItems.filter(x => parseFloat(x.diferencia) >= parseFloat(x.total_pedido) * 0.2);

    const estadoVal = it => {
      const d = parseFloat(it.diferencia);
      if (d < 0) return 0;
      if (d < parseFloat(it.total_pedido) * 0.2) return 1;
      return 2;
    };
    const estadoBadge = it => {
      const v = estadoVal(it);
      if (v === 0) return '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">AGOTADO</span>';
      if (v === 1) return '<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">RIESGO</span>';
      return '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">OK</span>';
    };

    const colDefs = [
      { key:'codigo',          label:'COD',          type:'str',  align:'left'   },
      { key:'descripcion',     label:'Descripción',  type:'str',  align:'left'   },
      { key:'stock_total',     label:'Stock Total',  type:'num',  align:'right'  },
      { key:'stock_reservado', label:'Reservado',    type:'num',  align:'right'  },
      { key:'stock_disponible',label:'Disponible',   type:'num',  align:'right'  },
      { key:'total_pedido',    label:'En Pedidos',   type:'num',  align:'right'  },
      { key:'diferencia',      label:'Diferencia',   type:'num',  align:'right'  },
      { key:'_estado',         label:'Estado',       type:'ord',  align:'center' },
    ];

    const sortIcon = col => {
      if (this._reservasSort.col !== col) return '<i class="fa-solid fa-sort" style="opacity:.3;font-size:9px;margin-left:3px;"></i>';
      return this._reservasSort.dir === 1
        ? '<i class="fa-solid fa-sort-up" style="color:#6366f1;font-size:9px;margin-left:3px;"></i>'
        : '<i class="fa-solid fa-sort-down" style="color:#6366f1;font-size:9px;margin-left:3px;"></i>';
    };

    const thStyle = align => {
      const base = 'cursor:pointer;user-select:none;white-space:nowrap;padding:8px 10px;';
      if (align === 'right')  return base + 'text-align:right;';
      if (align === 'center') return base + 'text-align:center;';
      return base + 'text-align:left;';
    };

    WMS.setContent(`
      <div class="px-20 py-16">
        <!-- KPIs -->
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
          <div style="flex:1;min-width:130px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;text-align:center;cursor:pointer;" onclick="WMS_MODULES.picking._filtrarReservasPorEstado('AGOTADO')">
            <div style="font-size:26px;font-weight:900;color:#991b1b;" id="rv-cnt-agotado">${agotados.length}</div>
            <div style="font-size:11px;color:#b91c1c;font-weight:600;">AGOTADOS</div>
          </div>
          <div style="flex:1;min-width:130px;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;text-align:center;cursor:pointer;" onclick="WMS_MODULES.picking._filtrarReservasPorEstado('RIESGO')">
            <div style="font-size:26px;font-weight:900;color:#854d0e;" id="rv-cnt-riesgo">${riesgo.length}</div>
            <div style="font-size:11px;color:#92400e;font-weight:600;">EN RIESGO</div>
          </div>
          <div style="flex:1;min-width:130px;background:#dcfce7;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;text-align:center;cursor:pointer;" onclick="WMS_MODULES.picking._filtrarReservasPorEstado('OK')">
            <div style="font-size:26px;font-weight:900;color:#166534;" id="rv-cnt-ok">${ok.length}</div>
            <div style="font-size:11px;color:#15803d;font-weight:600;">CON STOCK</div>
          </div>
          <div style="flex:1;min-width:130px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;text-align:center;cursor:pointer;" onclick="WMS_MODULES.picking._filtrarReservasPorEstado('')">
            <div style="font-size:26px;font-weight:900;color:#1e293b;" id="rv-cnt-total">${allItems.length}</div>
            <div style="font-size:11px;color:#475569;font-weight:600;">TOTAL REFS</div>
          </div>
        </div>

        <div class="card shadow-soft">
          <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px;">
            <span class="card-title fw-900 color-primary" style="flex-shrink:0;">
              <i class="fa-solid fa-boxes-stacked" style="color:#6366f1;"></i> Reservas vs Inventario
            </span>
            <!-- Filtros -->
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <label style="font-size:11px;color:#64748b;white-space:nowrap;">Desde
                <input id="rv-fecha-desde" type="date" value="${(this._reservasFiltros||{}).fecha_desde||''}"
                  style="margin-left:4px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:11px;"
                  onchange="WMS_MODULES.picking._reservasFiltros.fecha_desde=this.value;WMS_MODULES.picking._cargarReservas()" />
              </label>
              <label style="font-size:11px;color:#64748b;white-space:nowrap;">Hasta
                <input id="rv-fecha-hasta" type="date" value="${(this._reservasFiltros||{}).fecha_hasta||''}"
                  style="margin-left:4px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:11px;"
                  onchange="WMS_MODULES.picking._reservasFiltros.fecha_hasta=this.value;WMS_MODULES.picking._cargarReservas()" />
              </label>
              <input id="rv-sucursal-filter" type="text" placeholder="Sucursal..."
                style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;width:120px;"
                value="${(this._reservasFiltros||{}).sucursal_id||''}"
                oninput="WMS_MODULES.picking._reservasFiltros.sucursal_id=this.value;WMS_MODULES.picking._aplicarFiltrosReservas()" />
              <div style="position:relative;">
                <i class="fa-solid fa-search" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;"></i>
                <input id="rv-search" type="text" placeholder="Buscar por código o descripción..."
                  style="padding:5px 10px 5px 26px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;width:220px;"
                  oninput="WMS_MODULES.picking._aplicarFiltrosReservas()" />
              </div>
              <select id="rv-estado-filter" style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;"
                onchange="WMS_MODULES.picking._aplicarFiltrosReservas()">
                <option value="">Todos los estados</option>
                <option value="AGOTADO">AGOTADO</option>
                <option value="RIESGO">EN RIESGO</option>
                <option value="OK">OK</option>
              </select>
              <button class="btn btn-sm" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;font-size:11px;padding:4px 10px;"
                onclick="WMS_MODULES.picking._limpiarVistareservas()">
                <i class="fa-solid fa-filter-circle-xmark"></i> Limpiar filtros
              </button>
            </div>
          </div>
          <div style="padding:7px 16px;background:#f0f9ff;border-bottom:1px solid #bae6fd;font-size:11px;color:#0369a1;">
            <i class="fa-solid fa-circle-info"></i>
            <strong>Disponible</strong> = stock menos reservas activas.
            <strong>Diferencia negativa</strong> = más pedidos que stock disponible.
            Haz clic en los encabezados para ordenar.
          </div>
          <div class="table-container" style="overflow-x:auto;">
            <table class="erp-table" id="rv-table" style="width:100%;border-collapse:collapse;">
              <thead><tr id="rv-thead">
                ${colDefs.map(c => `
                  <th style="${thStyle(c.align)}" onclick="WMS_MODULES.picking._sortReservas('${c.key}')">
                    ${c.label}${sortIcon(c.key)}
                  </th>`).join('')}
              </tr></thead>
              <tbody id="rv-tbody"></tbody>
            </table>
          </div>
          <div id="rv-footer" style="padding:6px 14px;font-size:11px;color:#64748b;border-top:1px solid #f1f5f9;"></div>
        </div>
      </div>`);

    this._aplicarFiltrosReservas();
  },

  _sortReservas(col) {
    if (this._reservasSort.col === col) {
      this._reservasSort.dir *= -1;
    } else {
      this._reservasSort.col = col;
      this._reservasSort.dir = col === 'descripcion' || col === 'codigo' ? 1 : 1;
    }
    this._aplicarFiltrosReservas();
    // Actualizar iconos en encabezados
    const thead = document.getElementById('rv-thead');
    if (!thead) return;
    const cols = ['codigo','descripcion','stock_total','stock_reservado','stock_disponible','total_pedido','diferencia','_estado'];
    thead.querySelectorAll('th').forEach((th, i) => {
      const c = cols[i];
      const icon = th.querySelector('i');
      if (!icon) return;
      if (c !== this._reservasSort.col) {
        icon.className = 'fa-solid fa-sort';
        icon.style.opacity = '.3';
        icon.style.color = '';
      } else {
        icon.className = this._reservasSort.dir === 1 ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';
        icon.style.opacity = '1';
        icon.style.color = '#6366f1';
      }
    });
  },

  _filtrarReservasPorEstado(estado) {
    const sel = document.getElementById('rv-estado-filter');
    const inp = document.getElementById('rv-search');
    if (sel) sel.value = estado;
    if (inp) inp.value = '';
    this._aplicarFiltrosReservas();
  },

  _limpiarVistareservas() {
    const sel = document.getElementById('rv-estado-filter');
    const inp = document.getElementById('rv-search');
    if (sel) sel.value = '';
    if (inp) inp.value = '';
    this._reservasSort = { col: 'diferencia', dir: 1 };
    this._aplicarFiltrosReservas();
    // Resetear iconos
    const thead = document.getElementById('rv-thead');
    if (thead) {
      thead.querySelectorAll('th i').forEach(icon => {
        icon.className = 'fa-solid fa-sort';
        icon.style.opacity = '.3';
        icon.style.color = '';
      });
      const icons = thead.querySelectorAll('th i');
      if (icons[6]) { icons[6].className = 'fa-solid fa-sort-up'; icons[6].style.opacity = '1'; icons[6].style.color = '#6366f1'; }
    }
  },

  _aplicarFiltrosReservas() {
    const busqueda  = (document.getElementById('rv-search')?.value || '').toLowerCase().trim();
    const estadoFil = (document.getElementById('rv-estado-filter')?.value || '');

    const estadoVal = it => {
      const d = parseFloat(it.diferencia);
      if (d < 0) return 'AGOTADO';
      if (d < parseFloat(it.total_pedido) * 0.2) return 'RIESGO';
      return 'OK';
    };
    const estadoBadge = it => {
      const v = estadoVal(it);
      if (v === 'AGOTADO') return '<span style="display:inline-block;background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">AGOTADO</span>';
      if (v === 'RIESGO')  return '<span style="display:inline-block;background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">RIESGO</span>';
      return '<span style="display:inline-block;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">OK</span>';
    };

    let items = this._reservasData.filter(it => {
      if (busqueda && !(
        (it.codigo||'').toLowerCase().includes(busqueda) ||
        (it.descripcion||'').toLowerCase().includes(busqueda)
      )) return false;
      if (estadoFil && estadoVal(it) !== estadoFil) return false;
      return true;
    });

    // Ordenar
    const { col, dir } = this._reservasSort;
    items = [...items].sort((a, b) => {
      let va, vb;
      if (col === '_estado') {
        const ord = { AGOTADO: 0, RIESGO: 1, OK: 2 };
        va = ord[estadoVal(a)] ?? 3;
        vb = ord[estadoVal(b)] ?? 3;
      } else if (col === 'codigo' || col === 'descripcion') {
        va = (a[col] || '').toLowerCase();
        vb = (b[col] || '').toLowerCase();
        return dir * va.localeCompare(vb);
      } else {
        va = parseFloat(a[col]) || 0;
        vb = parseFloat(b[col]) || 0;
      }
      return dir * (va < vb ? -1 : va > vb ? 1 : 0);
    });

    const tbody = document.getElementById('rv-tbody');
    const footer = document.getElementById('rv-footer');
    if (!tbody) return;

    if (items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" class="table-empty" style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa-solid fa-search"></i> Sin resultados para los filtros aplicados</td></tr>';
      if (footer) footer.textContent = '0 referencias';
      return;
    }

    tbody.innerHTML = items.map(it => {
      const dif = parseFloat(it.diferencia) || 0;
      const difColor = dif < 0 ? '#dc2626' : dif < parseFloat(it.total_pedido)*0.2 ? '#d97706' : '#16a34a';
      return `<tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:7px 10px;font-family:monospace;font-weight:700;font-size:11px;text-align:left;white-space:nowrap;">${WMS.esc(it.codigo||'—')}</td>
        <td style="padding:7px 10px;font-size:.8rem;text-align:left;max-width:240px;white-space:normal;word-break:break-word;">${WMS.esc(it.descripcion||'—')}</td>
        <td style="padding:7px 10px;text-align:right;white-space:nowrap;">${WMS.formatNum(it.stock_total)}</td>
        <td style="padding:7px 10px;text-align:right;color:#7c3aed;white-space:nowrap;">${WMS.formatNum(it.stock_reservado)}</td>
        <td style="padding:7px 10px;text-align:right;color:#0369a1;font-weight:700;white-space:nowrap;">${WMS.formatNum(it.stock_disponible)}</td>
        <td style="padding:7px 10px;text-align:right;color:#d97706;font-weight:700;white-space:nowrap;">${WMS.formatNum(it.total_pedido)}</td>
        <td style="padding:7px 10px;text-align:right;color:${difColor};font-weight:800;white-space:nowrap;">${dif >= 0 ? '+' : ''}${WMS.formatNum(dif)}</td>
        <td style="padding:7px 10px;text-align:center;white-space:nowrap;">${estadoBadge(it)}</td>
      </tr>`;
    }).join('');

    if (footer) {
      const total = this._reservasData.length;
      footer.textContent = items.length === total
        ? `${total} referencias`
        : `${items.length} de ${total} referencias (filtrado)`;
    }
  },

  // ── REPORTE HISTÓRICO ─────────────────────────────────────────────────────
  async show_reporte() {
    WMS.setBreadcrumb('picking', 'Reporte');
    this._reporteData = [];
    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-chart-bar"></i> Historial de Separaciones</h5>
        </div>
        <div class="card-body">
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Desde <span style="color:#dc2626;">*</span></label>
              <input type="date" id="rep-desde" class="form-control">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Hasta <span style="color:#dc2626;">*</span></label>
              <input type="date" id="rep-hasta" class="form-control" value="${new Date().toISOString().split('T')[0]}">
            </div>
            <div class="form-group" style="margin:0;min-width:160px;">
              <label class="form-label">Ruta</label>
              <select id="rep-ruta" class="form-control"><option value="">Todas las rutas</option></select>
            </div>
            <div class="form-group" style="margin:0;min-width:180px;">
              <label class="form-label">Sucursal Entrega</label>
              <select id="rep-suc" class="form-control"><option value="">Todas las sucursales</option></select>
            </div>
            <div style="display:flex;gap:6px;align-items:flex-end;padding-bottom:2px;">
              <button class="btn btn-primary" id="btn-buscar-reporte" onclick="WMS_MODULES.picking._buscarReporte()">
                <i class="fa-solid fa-search"></i> Buscar
              </button>
              <button class="btn btn-outline-success" id="btn-export-excel" onclick="WMS_MODULES.picking._exportarExcel()" style="display:none;">
                <i class="fa-solid fa-file-excel"></i> Excel
              </button>
            </div>
          </div>
          <div id="rep-kpis" style="display:none;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Pedidos</div>
              <div id="rep-k-total" style="font-size:1.5rem;font-weight:900;color:#0F4C81;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Completadas</div>
              <div id="rep-k-comp" style="font-size:1.5rem;font-weight:900;color:#059669;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Faltantes</div>
              <div id="rep-k-falt" style="font-size:1.5rem;font-weight:900;color:#dc2626;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Dur. Prom.</div>
              <div id="rep-k-dur" style="font-size:1.5rem;font-weight:900;color:#7c3aed;">—</div>
            </div>
          </div>
          <div id="rep-tabla" style="display:none;overflow-x:auto;">
            <table class="erp-table">
              <thead>
                <tr>
                  <th style="padding:10px 12px;">Fecha</th>
                  <th style="padding:10px 12px;">N° Pedido</th>
                  <th style="padding:10px 12px;">Sucursal Entrega</th>
                  <th style="padding:10px 12px;">Ruta</th>
                  <th style="padding:10px 12px;text-align:center;">Total Lín.</th>
                  <th style="padding:10px 12px;text-align:center;">Completadas</th>
                  <th style="padding:10px 12px;text-align:center;">Faltantes</th>
                  <th style="padding:10px 12px;text-align:center;">% Cumpl.</th>
                  <th style="padding:10px 12px;">Auxiliar(es)</th>
                  <th style="padding:10px 12px;">Inicio</th>
                  <th style="padding:10px 12px;">Fin</th>
                  <th style="padding:10px 12px;text-align:center;">Dur.(min)</th>
                </tr>
              </thead>
              <tbody id="rep-tbody"></tbody>
            </table>
          </div>
          <div id="rep-empty" style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
            Seleccione un rango de fechas y haga clic en Buscar para ver el historial.
          </div>
        </div>
      </div>`);
  },

  async _buscarReporte() {
    const desde = document.getElementById('rep-desde')?.value;
    const hasta = document.getElementById('rep-hasta')?.value;
    if (!desde || !hasta) { WMS.toast('warning', 'Seleccione fecha desde y hasta'); return; }

    const ruta = document.getElementById('rep-ruta')?.value.trim();
    const suc  = document.getElementById('rep-suc')?.value.trim();

    const params = new URLSearchParams({ fecha_desde: desde, fecha_hasta: hasta });
    if (ruta) params.set('ruta', ruta);
    if (suc)  params.set('sucursal_entrega', suc);

    const buscarBtn = document.getElementById('btn-buscar-reporte');
    if (buscarBtn) { buscarBtn.disabled = true; buscarBtn.textContent = 'Buscando...'; }

    try {
      const r = await API.get('/picking/reporte?' + params.toString());
      const d = r.data || r;
      this._reporteData = d.ordenes || [];

      // Populate ruta and sucursal_entrega selects from results
      const rutasSel = document.getElementById('rep-ruta');
      const sucsSel  = document.getElementById('rep-suc');
      if (rutasSel && sucsSel) {
        rutasSel.length = 1;
        sucsSel.length  = 1;
        const seenR = new Set(), seenS = new Set();
        this._reporteData.forEach(row => {
          if (row.ruta && !seenR.has(row.ruta)) {
            seenR.add(row.ruta);
            const opt = document.createElement('option');
            opt.value = opt.text = row.ruta;
            rutasSel.appendChild(opt);
          }
          const s = row.sucursal_entrega || row.cliente;
          if (s && !seenS.has(s)) {
            seenS.add(s);
            const opt = document.createElement('option');
            opt.value = opt.text = s;
            sucsSel.appendChild(opt);
          }
        });
      }

      const res = d.resumen || {};

      const kpis = document.getElementById('rep-kpis');
      if (kpis) kpis.style.display = 'flex';
      const setKpi = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v; };
      setKpi('rep-k-total', res.total || 0);
      setKpi('rep-k-comp',  res.completadas || 0);
      setKpi('rep-k-falt',  res.faltantes || 0);
      setKpi('rep-k-dur',   res.duracion_prom_min ? res.duracion_prom_min + ' min' : '—');

      const tbody     = document.getElementById('rep-tbody');
      const emptyDiv  = document.getElementById('rep-empty');
      const tablaDiv  = document.getElementById('rep-tabla');
      const exportBtn = document.getElementById('btn-export-excel');

      if (!this._reporteData.length) {
        if (tablaDiv)  tablaDiv.style.display  = 'none';
        if (emptyDiv)  { emptyDiv.style.display = 'block'; emptyDiv.innerHTML = '<i class="fa-solid fa-magnifying-glass" style="font-size:2rem;margin-bottom:10px;display:block;"></i>Sin resultados para los filtros seleccionados.'; }
        if (exportBtn) exportBtn.style.display = 'none';
        return;
      }

      if (emptyDiv)  emptyDiv.style.display  = 'none';
      if (tablaDiv)  tablaDiv.style.display  = 'block';
      if (exportBtn) exportBtn.style.display = '';

      if (tbody) tbody.innerHTML = this._reporteData.map(o => `
        <tr>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.fecha||'—')}</td>
          <td style="padding:8px 12px;font-weight:700;color:#0F4C81;">${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</td>
          <td style="padding:8px 12px;">${WMS.esc(o.sucursal_entrega||o.cliente||'—')}</td>
          <td style="padding:8px 12px;">${o.ruta ? `<span style="background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:3px;font-size:.72rem;">${WMS.esc(o.ruta)}</span>` : '—'}</td>
          <td style="padding:8px 12px;text-align:center;">${WMS.esc(String(o.total_lineas||0))}</td>
          <td style="padding:8px 12px;text-align:center;color:#059669;font-weight:700;">${WMS.esc(String(o.completadas||0))}</td>
          <td style="padding:8px 12px;text-align:center;color:#dc2626;font-weight:700;">${WMS.esc(String(o.faltantes||0))}</td>
          <td style="padding:8px 12px;text-align:center;">
            <span style="font-weight:700;${(o.pct_cumplimiento||0)>=90?'color:#059669;':(o.pct_cumplimiento||0)>=70?'color:#d97706;':'color:#dc2626;'}">${WMS.esc(String(o.pct_cumplimiento||0))}%</span>
          </td>
          <td style="padding:8px 12px;font-size:.78rem;">${WMS.esc(o.auxiliares||'—')}</td>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.hora_inicio||'—')}</td>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.hora_fin||'—')}</td>
          <td style="padding:8px 12px;text-align:center;">${WMS.esc(String(o.duracion_min||'—'))}</td>
        </tr>`).join('');

    } catch(e) {
      console.error('[picking] _buscarReporte:', e);
      WMS.toast('error', 'Error generando reporte');
    } finally {
      if (buscarBtn) {
        buscarBtn.disabled = false;
        buscarBtn.innerHTML = '<i class="fa-solid fa-search"></i> Buscar';
      }
    }
  },

  async _exportarExcel() {
    if (!this._reporteData?.length) { WMS.toast('warning', 'Sin datos para exportar'); return; }
    const btn = document.getElementById('btn-export-excel');
    if (btn) { btn.disabled = true; btn.textContent = 'Generando...'; }
    try {
      if (typeof XLSX === 'undefined') {
        await new Promise((resolve, reject) => {
          const s = document.createElement('script');
          s.src = 'https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js';
          s.onload = resolve;
          s.onerror = reject;
          document.head.appendChild(s);
        });
      }
      const headers = ['Fecha','N° Pedido','Sucursal Entrega','Ruta','Total Líneas','Completadas','Faltantes','% Cumplimiento','Auxiliar(es)','Hora Inicio','Hora Fin','Duración (min)'];
      const data = this._reporteData.map(o => [
        o.fecha,
        o.numero_pedido || o.numero_orden,
        o.sucursal_entrega || o.cliente,
        o.ruta || '',
        o.total_lineas || 0,
        o.completadas || 0,
        o.faltantes || 0,
        o.pct_cumplimiento || 0,
        o.auxiliares || '',
        o.hora_inicio || '',
        o.hora_fin || '',
        o.duracion_min || '',
      ]);
      const ws = XLSX.utils.aoa_to_sheet([headers, ...data]);
      ws['!cols'] = headers.map(() => ({ wch: 18 }));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Picking');
      const hoy = new Date().toISOString().split('T')[0];
      XLSX.writeFile(wb, `Picking_Reporte_${hoy}.xlsx`);
    } catch(e) {
      WMS.toast('error', 'Error generando Excel: ' + WMS.esc(e.message));
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-excel"></i> Excel'; }
    }
  },

  // ── CONSULTA DE PICKING ───────────────────────────────────────────────────
  _consultaPage: 1,
  _consultaData: [],
  _consultaTotal: 0,
  _consultaPageSize: 50,
  _consultaFiltros: { q: '', fecha_desde: '', fecha_hasta: '', estado: '' },

  async show_consulta() {
    WMS.setBreadcrumb('picking', 'Consulta de Picking');
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking._cargarConsulta(1)">
        <i class="fa-solid fa-sync"></i> Actualizar
      </button>`);
    const today = this.getToday();
    const hace7 = new Date(Date.now() - 7*24*60*60*1000).toISOString().split('T')[0];
    this._consultaFiltros = { q: '', fecha_desde: hace7, fecha_hasta: today, estado: '' };
    this._consultaPage = 1;
    WMS.setContent(`
      <div class="px-20 py-16">
        <!-- Filtros -->
        <div class="card shadow-soft" style="margin-bottom:14px;">
          <div class="card-header" style="padding:10px 16px;">
            <span class="card-title fw-700"><i class="fa-solid fa-filter" style="color:#6366f1;"></i> Filtros</span>
          </div>
          <div style="padding:12px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Búsqueda libre</label>
              <div style="position:relative;">
                <i class="fa-solid fa-search" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;"></i>
                <input id="cq-q" type="text" placeholder="Código, descripción, planilla..."
                  style="padding:6px 10px 6px 28px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;width:220px;"
                  onkeydown="if(event.key==='Enter')WMS_MODULES.picking._cargarConsulta(1)" />
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Fecha desde</label>
              <input id="cq-desde" type="date" value="${hace7}"
                style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;" />
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Fecha hasta</label>
              <input id="cq-hasta" type="date" value="${today}"
                style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;" />
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Estado</label>
              <select id="cq-estado" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                <option value="">Todos</option>
                <option value="pendiente">Pendiente</option>
                <option value="parcial">Parcial</option>
                <option value="completo">Completo</option>
              </select>
            </div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking._cargarConsulta(1)">
              <i class="fa-solid fa-magnifying-glass"></i> Buscar
            </button>
            <button class="btn btn-sm" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#475569;"
              onclick="WMS_MODULES.picking._limpiarConsulta()">
              <i class="fa-solid fa-filter-circle-xmark"></i> Limpiar
            </button>
          </div>
        </div>
        <!-- Tabla -->
        <div class="card shadow-soft">
          <div class="card-header d-flex justify-between align-center" style="flex-wrap:wrap;gap:8px;">
            <span class="card-title fw-900 color-primary">
              <i class="fa-solid fa-table" style="color:#6366f1;"></i> Productos en Picking
            </span>
            <span id="cq-resumen" style="font-size:11px;color:#64748b;"></span>
          </div>
          <div id="cq-contenido" style="padding:16px;">
            <div style="text-align:center;padding:32px;color:#94a3b8;">
              <i class="fa-solid fa-magnifying-glass" style="font-size:28px;"></i>
              <div style="margin-top:8px;">Aplica los filtros y pulsa Buscar</div>
            </div>
          </div>
          <!-- Paginación -->
          <div id="cq-paginacion" style="display:flex;justify-content:space-between;align-items:center;padding:8px 16px;border-top:1px solid #f1f5f9;font-size:12px;color:#475569;"></div>
        </div>
      </div>`);
  },

  _limpiarConsulta() {
    const today = this.getToday();
    const hace7 = new Date(Date.now() - 7*24*60*60*1000).toISOString().split('T')[0];
    const q = document.getElementById('cq-q'); if (q) q.value = '';
    const d = document.getElementById('cq-desde'); if (d) d.value = hace7;
    const h = document.getElementById('cq-hasta'); if (h) h.value = today;
    const e = document.getElementById('cq-estado'); if (e) e.value = '';
    const c = document.getElementById('cq-contenido');
    if (c) c.innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa-solid fa-magnifying-glass" style="font-size:28px;"></i><div style="margin-top:8px;">Aplica los filtros y pulsa Buscar</div></div>';
    const p = document.getElementById('cq-paginacion'); if (p) p.innerHTML = '';
    const r = document.getElementById('cq-resumen'); if (r) r.textContent = '';
  },

  async _cargarConsulta(page) {
    this._consultaPage = page || 1;
    const q      = document.getElementById('cq-q')?.value?.trim() || '';
    const desde  = document.getElementById('cq-desde')?.value || '';
    const hasta  = document.getElementById('cq-hasta')?.value || '';
    const estado = document.getElementById('cq-estado')?.value || '';
    const params = new URLSearchParams({ page: this._consultaPage, limit: this._consultaPageSize });
    if (q)      params.append('q', q);
    if (desde)  params.append('fecha_desde', desde);
    if (hasta)  params.append('fecha_hasta', hasta);
    if (estado) params.append('estado', estado);

    const contenido = document.getElementById('cq-contenido');
    if (contenido) contenido.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>';

    try {
      const r = await API.get('/picking/consulta?' + params.toString());
      this._consultaData  = r.data || [];
      this._consultaTotal = r.total || this._consultaData.length;
      this._renderConsulta();
    } catch (e) {
      if (contenido) contenido.innerHTML = '<div style="text-align:center;padding:24px;color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Error cargando datos</div>';
      WMS.toast('error', 'Error en consulta de picking');
    }
  },

  _renderConsulta() {
    const data = this._consultaData;
    const total = this._consultaTotal;
    const page  = this._consultaPage;
    const limit = this._consultaPageSize;
    const resumen = document.getElementById('cq-resumen');
    const pag     = document.getElementById('cq-paginacion');
    const cont    = document.getElementById('cq-contenido');
    if (!cont) return;

    if (resumen) resumen.textContent = total + ' producto(s) encontrado(s)';

    if (!data.length) {
      cont.innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa-solid fa-box-open" style="font-size:28px;"></i><div style="margin-top:8px;">Sin resultados para los filtros aplicados</div></div>';
      if (pag) pag.innerHTML = '';
      return;
    }

    const fmtN = v => { const n = parseFloat(v); return isNaN(n) ? '-' : n.toLocaleString('es-CO'); };
    const estadoBadge = (sol, sep) => {
      const s = parseFloat(sol)||0, p = parseFloat(sep)||0;
      if (p <= 0)          return '<span style="background:#fee2e2;color:#991b1b;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;">PENDIENTE</span>';
      if (p >= s)          return '<span style="background:#dcfce7;color:#166534;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;">COMPLETO</span>';
      return '<span style="background:#fef9c3;color:#854d0e;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;">PARCIAL</span>';
    };

    const rows = data.map((prod, i) => {
      const suc = prod.sucursales || [];
      const detId = 'cq-det-' + i;
      const sucRows = suc.map(s => `
        <tr style="background:#f8fafc;">
          <td style="padding:5px 10px 5px 36px;font-size:11px;color:#475569;">${WMS.esc ? WMS.esc(s.sucursal||'') : (s.sucursal||'')}</td>
          <td style="padding:5px 10px;font-size:11px;text-align:center;color:#6366f1;">${WMS.esc ? WMS.esc(s.planilla||'') : (s.planilla||'')}</td>
          <td style="padding:5px 10px;font-size:11px;text-align:right;">${fmtN(s.solicitada)}</td>
          <td style="padding:5px 10px;font-size:11px;text-align:right;">${fmtN(s.separada)}</td>
          <td style="padding:5px 10px;font-size:11px;text-align:right;">${fmtN((parseFloat(s.solicitada)||0)-(parseFloat(s.separada)||0))}</td>
          <td style="padding:5px 10px;font-size:11px;text-align:center;">${estadoBadge(s.solicitada, s.separada)}</td>
        </tr>`).join('');

      const solTotal = prod.cant_solicitada_total || prod.sucursales?.reduce((a,s)=>a+(parseFloat(s.solicitada)||0),0) || 0;
      const sepTotal = prod.cant_separada_total   || prod.sucursales?.reduce((a,s)=>a+(parseFloat(s.separada)||0),0)   || 0;
      const pendTotal = (parseFloat(solTotal)||0) - (parseFloat(sepTotal)||0);
      return `
        <tr style="background:#fff;border-bottom:1px solid #f1f5f9;">
          <td style="padding:8px 10px;font-size:12px;font-weight:700;">
            <button onclick="WMS_MODULES.picking._toggleConsultaDet('${detId}')" style="background:none;border:none;cursor:pointer;padding:0 6px 0 0;color:#6366f1;" title="Expandir sucursales">
              <i class="fa-solid fa-chevron-right" id="${detId}-icon" style="transition:transform .2s;font-size:11px;"></i>
            </button>
            ${WMS.esc ? WMS.esc(prod.codigo||'') : (prod.codigo||'')}
          </td>
          <td style="padding:8px 10px;font-size:12px;">${WMS.esc ? WMS.esc(prod.descripcion||'') : (prod.descripcion||'')}</td>
          <td style="padding:8px 10px;font-size:12px;text-align:center;">${suc.length}</td>
          <td style="padding:8px 10px;font-size:12px;text-align:right;">${fmtN(solTotal)}</td>
          <td style="padding:8px 10px;font-size:12px;text-align:right;">${fmtN(sepTotal)}</td>
          <td style="padding:8px 10px;font-size:12px;text-align:right;${pendTotal>0?'color:#ef4444;font-weight:700;':''}">${fmtN(pendTotal)}</td>
        </tr>
        <tr id="${detId}" style="display:none;">
          <td colspan="6" style="padding:0;">
            <table style="width:100%;border-collapse:collapse;border-top:1px solid #e2e8f0;">
              <thead>
                <tr style="background:#f0f4ff;">
                  <th style="padding:5px 10px 5px 36px;font-size:10px;font-weight:700;text-align:left;color:#6366f1;">Sucursal</th>
                  <th style="padding:5px 10px;font-size:10px;font-weight:700;text-align:center;color:#6366f1;">Planilla</th>
                  <th style="padding:5px 10px;font-size:10px;font-weight:700;text-align:right;color:#6366f1;">Solicitada</th>
                  <th style="padding:5px 10px;font-size:10px;font-weight:700;text-align:right;color:#6366f1;">Separada</th>
                  <th style="padding:5px 10px;font-size:10px;font-weight:700;text-align:right;color:#6366f1;">Pendiente</th>
                  <th style="padding:5px 10px;font-size:10px;font-weight:700;text-align:center;color:#6366f1;">Estado</th>
                </tr>
              </thead>
              <tbody>${sucRows || '<tr><td colspan="6" style="padding:8px 16px;font-size:11px;color:#94a3b8;">Sin detalle de sucursales</td></tr>'}</tbody>
            </table>
          </td>
        </tr>`;
    }).join('');

    cont.innerHTML = `
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:left;white-space:nowrap;">Código</th>
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:left;">Descripción</th>
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:center;white-space:nowrap;">Total Sucursales</th>
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:right;white-space:nowrap;">Cant. Solicitada</th>
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:right;white-space:nowrap;">Cant. Separada</th>
              <th style="padding:9px 10px;font-size:11px;font-weight:700;text-align:right;white-space:nowrap;">Pendiente</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;

    // Paginación
    if (pag) {
      const totalPags = Math.ceil(total / limit);
      const desde = ((page-1)*limit)+1;
      const hasta  = Math.min(page*limit, total);
      pag.innerHTML = `
        <span>Mostrando ${desde}-${hasta} de ${total} registros (pág. ${page}/${totalPags||1})</span>
        <div style="display:flex;gap:6px;">
          <button class="btn btn-sm" style="font-size:11px;padding:3px 10px;" ${page<=1?'disabled':''} onclick="WMS_MODULES.picking._cargarConsulta(${page-1})">
            <i class="fa-solid fa-chevron-left"></i> Anterior
          </button>
          <button class="btn btn-sm" style="font-size:11px;padding:3px 10px;" ${page>=totalPags?'disabled':''} onclick="WMS_MODULES.picking._cargarConsulta(${page+1})">
            Siguiente <i class="fa-solid fa-chevron-right"></i>
          </button>
        </div>`;
    }
  },

  _toggleConsultaDet(id) {
    const row  = document.getElementById(id);
    const icon = document.getElementById(id + '-icon');
    if (!row) return;
    const hidden = row.style.display === 'none';
    row.style.display = hidden ? '' : 'none';
    if (icon) icon.style.transform = hidden ? 'rotate(90deg)' : '';
  },

  // ── NOVEDADES DE PICKING ──────────────────────────────────────────────────
  _novedadesPage: 1,
  _novedadesData: [],

  async show_novedades() {
    const rol = (WMS.user||{}).rol || '';
    if (rol !== 'Admin' && rol !== 'SuperAdmin') {
      WMS.setContent(`
        <div class="px-20 py-16">
          <div style="text-align:center;padding:48px;color:#ef4444;">
            <i class="fa-solid fa-lock" style="font-size:36px;"></i>
            <div style="margin-top:12px;font-size:16px;font-weight:700;">Acceso restringido</div>
            <div style="margin-top:6px;font-size:13px;color:#64748b;">Este módulo es exclusivo para Administradores.</div>
          </div>
        </div>`);
      return;
    }
    WMS.setBreadcrumb('picking', 'Novedades de Picking');
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking._cargarNovedades(1)">
        <i class="fa-solid fa-sync"></i> Actualizar
      </button>`);
    const today = this.getToday();
    const hace30 = new Date(Date.now() - 30*24*60*60*1000).toISOString().split('T')[0];
    WMS.setContent(`
      <div class="px-20 py-16">
        <div class="card shadow-soft" style="margin-bottom:14px;">
          <div class="card-header" style="padding:10px 16px;">
            <span class="card-title fw-700"><i class="fa-solid fa-filter" style="color:#6366f1;"></i> Filtros</span>
          </div>
          <div style="padding:12px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Estado</label>
              <select id="nv-estado" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">
                <option value="">Todos</option>
                <option value="Pendiente">Pendiente</option>
                <option value="Resuelta">Resuelta</option>
                <option value="Ignorada">Ignorada</option>
              </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Fecha desde</label>
              <input id="nv-desde" type="date" value="${hace30}" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;" />
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;">
              <label style="font-size:11px;color:#64748b;font-weight:600;">Fecha hasta</label>
              <input id="nv-hasta" type="date" value="${today}" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;" />
            </div>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking._cargarNovedades(1)">
              <i class="fa-solid fa-magnifying-glass"></i> Buscar
            </button>
          </div>
        </div>
        <div class="card shadow-soft">
          <div class="card-header d-flex justify-between align-center">
            <span class="card-title fw-900 color-primary">
              <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> Novedades de Picking
              <span id="nv-badge" style="display:none;background:#ef4444;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:8px;"></span>
            </span>
            <span id="nv-resumen" style="font-size:11px;color:#64748b;"></span>
          </div>
          <div id="nv-contenido" style="padding:16px;">
            <div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>
          </div>
        </div>
        <!-- Modal resolución/ignorar -->
        <div id="nv-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:12px;padding:24px;width:440px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.18);">
            <h3 id="nv-modal-title" style="margin:0 0 12px;font-size:15px;font-weight:700;color:#1e293b;"></h3>
            <textarea id="nv-modal-nota" placeholder="Nota (opcional)..." rows="3"
              style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;resize:vertical;"></textarea>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
              <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.picking._cerrarNvModal()">Cancelar</button>
              <button id="nv-modal-btn" class="btn btn-primary btn-sm" onclick="WMS_MODULES.picking._confirmarNvAccion()">Confirmar</button>
            </div>
          </div>
        </div>
      </div>`);
    await this._cargarNovedades(1);
  },

  _nvAccion: null,
  _nvId: null,

  async _cargarNovedades(page) {
    this._novedadesPage = page || 1;
    const estado = document.getElementById('nv-estado')?.value || '';
    const desde  = document.getElementById('nv-desde')?.value || '';
    const hasta  = document.getElementById('nv-hasta')?.value || '';
    const params = new URLSearchParams({ page: this._novedadesPage, limit: 50 });
    if (estado) params.append('estado', estado);
    if (desde)  params.append('fecha_desde', desde);
    if (hasta)  params.append('fecha_hasta', hasta);

    const cont = document.getElementById('nv-contenido');
    if (cont) cont.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</div>';

    try {
      const r = await API.get('/picking/novedades?' + params.toString());
      // ok() envuelve en data: { data:[...], pagination:{} } — extraer el array interno
      const _nvPayload = r.data || {};
      this._novedadesData = Array.isArray(_nvPayload) ? _nvPayload : (_nvPayload.data || []);
      const total = _nvPayload.pagination?.total ?? r.total ?? this._novedadesData.length;
      const pendientes = this._novedadesData.filter(n => n.estado === 'Pendiente').length;

      // Badge en resumen
      const badge = document.getElementById('nv-badge');
      if (badge) { badge.textContent = pendientes; badge.style.display = pendientes > 0 ? 'inline' : 'none'; }
      const resumen = document.getElementById('nv-resumen');
      if (resumen) resumen.textContent = total + ' novedad(es) encontrada(s)';

      this._renderNovedades();
    } catch (e) {
      if (cont) cont.innerHTML = '<div style="text-align:center;padding:24px;color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Error cargando novedades</div>';
      WMS.toast('error', 'Error cargando novedades');
    }
  },

  _renderNovedades() {
    const data = this._novedadesData;
    const cont = document.getElementById('nv-contenido');
    if (!cont) return;
    if (!data.length) {
      cont.innerHTML = '<div style="text-align:center;padding:32px;color:#94a3b8;"><i class="fa-solid fa-check-circle" style="font-size:28px;color:#22c55e;"></i><div style="margin-top:8px;">Sin novedades para los filtros aplicados</div></div>';
      return;
    }
    const estadoBadge = e => {
      if (e === 'Pendiente') return '<span style="background:#fef9c3;color:#854d0e;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">PENDIENTE</span>';
      if (e === 'Resuelta')  return '<span style="background:#dcfce7;color:#166534;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">RESUELTA</span>';
      return '<span style="background:#f1f5f9;color:#475569;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;">IGNORADA</span>';
    };
    const esc = s => WMS.esc ? WMS.esc(s||'') : (s||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const rows = data.map(n => `
      <tr style="border-bottom:1px solid #f1f5f9;">
        <td style="padding:7px 10px;font-size:11px;white-space:nowrap;">${esc(n.fecha||'')}</td>
        <td style="padding:7px 10px;font-size:11px;">${esc(n.archivo||'')}</td>
        <td style="padding:7px 10px;font-size:11px;">${esc(n.planilla||'')}</td>
        <td style="padding:7px 10px;font-size:11px;">${esc(n.sucursal||'')}</td>
        <td style="padding:7px 10px;font-size:11px;font-weight:700;">${esc(n.codigo_producto||'')}</td>
        <td style="padding:7px 10px;font-size:11px;">${esc(n.descripcion||'')}</td>
        <td style="padding:7px 10px;font-size:11px;text-align:right;color:#ef4444;font-weight:700;">${n.cantidad_invalida||''}</td>
        <td style="padding:7px 10px;font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(n.motivo||'')}">${esc(n.motivo||'')}</td>
        <td style="padding:7px 10px;text-align:center;">${estadoBadge(n.estado)}</td>
        <td style="padding:7px 10px;white-space:nowrap;">
          ${n.estado === 'Pendiente' ? `
            <button class="btn btn-sm" style="background:#22c55e;color:#fff;border:none;font-size:10px;padding:3px 8px;margin-right:4px;"
              onclick="WMS_MODULES.picking._abrirNvModal(${n.id},'resolver')">
              <i class="fa-solid fa-check"></i> Resolver
            </button>
            <button class="btn btn-sm" style="background:#94a3b8;color:#fff;border:none;font-size:10px;padding:3px 8px;"
              onclick="WMS_MODULES.picking._abrirNvModal(${n.id},'ignorar')">
              <i class="fa-solid fa-eye-slash"></i> Ignorar
            </button>` : '<span style="color:#94a3b8;font-size:10px;">—</span>'}
        </td>
      </tr>`).join('');

    cont.innerHTML = `
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;white-space:nowrap;">Fecha</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Archivo</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Planilla</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Sucursal</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Cód. Producto</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Descripción</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:right;white-space:nowrap;">Cant. Inválida</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:left;">Motivo</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:center;">Estado</th>
              <th style="padding:8px 10px;font-size:10px;font-weight:700;text-align:center;">Acciones</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  },

  _abrirNvModal(id, accion) {
    this._nvId     = id;
    this._nvAccion = accion;
    const modal = document.getElementById('nv-modal');
    const title = document.getElementById('nv-modal-title');
    const nota  = document.getElementById('nv-modal-nota');
    const btn   = document.getElementById('nv-modal-btn');
    if (!modal) return;
    if (title) title.textContent = accion === 'resolver' ? 'Resolver novedad' : 'Ignorar novedad';
    if (nota)  nota.value = '';
    if (btn) {
      btn.style.background = accion === 'resolver' ? '#22c55e' : '#94a3b8';
      btn.textContent = accion === 'resolver' ? 'Confirmar resolución' : 'Confirmar ignorar';
    }
    modal.style.display = 'flex';
  },

  _cerrarNvModal() {
    const modal = document.getElementById('nv-modal');
    if (modal) modal.style.display = 'none';
    this._nvId = null; this._nvAccion = null;
  },

  async _confirmarNvAccion() {
    if (!this._nvId || !this._nvAccion) return;
    const nota = document.getElementById('nv-modal-nota')?.value?.trim() || '';
    const btn  = document.getElementById('nv-modal-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }
    try {
      await API.put(`/picking/novedades/${this._nvId}/resolver`, { accion: this._nvAccion, nota });
      WMS.toast('success', this._nvAccion === 'resolver' ? 'Novedad resuelta' : 'Novedad ignorada');
      this._cerrarNvModal();
      await this._cargarNovedades(this._novedadesPage);
    } catch (e) {
      WMS.toast('error', 'Error al procesar la novedad');
      if (btn) { btn.disabled = false; btn.textContent = 'Confirmar'; }
    }
  },

  // ── TV DASHBOARD ─────────────────────────────────────────────────────────
  _openTVDashboard() {
    const token = localStorage.getItem('wms_token') || '';
    const base  = window.location.pathname.replace(/\/public\/.*/, '/public');
    const url   = base + '/tv-picking.html';
    const win   = window.open(url, 'wms_tv_picking', 'width=1920,height=1080,menubar=no,toolbar=no');
    if (win) {
      // Pasar token vía postMessage una vez que la ventana cargue
      win.addEventListener ? null : null;
      setTimeout(() => {
        try { win.postMessage({ type: 'WMS_TOKEN', token }, '*'); } catch(e) {}
      }, 1500);
    }
  },

}; // fin WMS_MODULES.picking