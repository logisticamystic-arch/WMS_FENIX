/* ============================================================
   WMS Desktop — Módulo INTELIGENCIA  ·  ML + FEFO + Anomalías
   ============================================================
   Vistas disponibles:
     vencimientos  — Predicciones ML de productos próximos a vencer
     anomalias     — Anomalías estadísticas detectadas automáticamente
     fefo          — Reporte de rotación FEFO (productos sin movimiento)
     guardlog      — Log de bloqueos de integridad (InventoryGuard)
     performance   — Métricas de endpoints lentos (solo admin)
   ============================================================ */
WMS_MODULES.inteligencia = {

  _sub: 'vencimientos',
  _data: [],        // Caché local para filtrado rápido
  _riskFilter: null, // Filtro activo (critico, alto, medio, bajo o null)
  _diasVenc: 30,     // Ventana de días para "próximos a vencer" — antes cargaba 365 días
                      // (casi todo el catálogo) en cada apertura del módulo.

  load(sub) {
    this._sub = sub || 'vencimientos';
    WMS.setBreadcrumb('inteligencia');
    WMS.renderSidebar('inteligencia');
    this._renderSub(this._sub);
  },

  destroy() {},

  subLabel(sub) {
    const labels = {
      vencimientos: 'Predicción de Vencimientos',
      anomalias:    'Anomalías Detectadas',
      fefo:         'Rotación FEFO',
      guardlog:     'Log de Integridad',
      performance:  'Rendimiento',
    };
    return labels[sub] || sub;
  },

  _renderSub(sub) {
    this._sub = sub;
    WMS._activateSidebarItem && WMS._activateSidebarItem(sub);
    switch (sub) {
      case 'vencimientos': this.renderVencimientos(); break;
      case 'anomalias':    this.renderAnomalias();    break;
      case 'fefo':         this.renderFefo();          break;
      case 'guardlog':     this.renderGuardLog();      break;
      case 'performance':  this.renderPerformance();   break;
      default:             this.renderVencimientos();
    }
  },

  // ══════════════════════════════════════════════════════════════════
  // 1. PREDICCIONES DE VENCIMIENTO
  // ══════════════════════════════════════════════════════════════════
  async renderVencimientos(dias = null) {
    if (dias) this._diasVenc = dias;
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inteligencia.renderVencimientos()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.inteligencia.scanVencimientos()">
        <i class="fa-solid fa-brain"></i> Ejecutar ML
      </button>
      <span style="display:flex;align-items:center;gap:6px;margin-left:10px;">
        <span style="font-size:.75rem;color:#64748b;font-weight:600;">Ventana:</span>
        ${[15, 30, 60, 90, 365].map(d => `
          <button class="btn btn-sm ${d === this._diasVenc ? 'btn-primary' : 'btn-outline-secondary'}"
            style="font-size:.72rem;padding:3px 9px;"
            onclick="WMS_MODULES.inteligencia.renderVencimientos(${d})">${d}d</button>
        `).join('')}
      </span>
    `);
    WMS.spinner();

    try {
      const r = await API.get('/inteligencia/vencimientos', `dias=${this._diasVenc}`);
      const raw = r.predictions || (r.data ? (r.data.predictions || r.data) : []);
      this._data = Array.isArray(raw) ? raw : [];
    } catch (e) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`);
      return;
    }

    if (!this._data.length) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-check-circle" style="color:#22c55e"></i><p>Sin predicciones de riesgo en los próximos ${this._diasVenc} días. Amplía la ventana o ejecuta el ML para recalcular.</p></div>`);
      return;
    }

    this._renderVencimientosUI();
  },

  _renderVencimientosUI() {
    const data = this._riskFilter 
      ? this._data.filter(p => p.nivel_riesgo === this._riskFilter)
      : this._data;

    const riskBadge = r => {
      const map = {
        critico: '<span class="badge badge-danger">Crítico</span>',
        alto:    '<span class="badge badge-warning">Alto</span>',
        medio:   '<span class="badge badge-info">Medio</span>',
        bajo:    '<span class="badge badge-success">Bajo</span>',
        vencido: '<span class="badge bg-dark text-white">Vencido</span>'
      };
      return map[r] || `<span class="badge badge-gray">${r}</span>`;
    };

    const riskBar = (nivel, conf) => {
      const colors = { critico:'#ef4444', alto:'#f59e0b', medio:'#3b82f6', bajo:'#10b981', vencido:'#1e293b' };
      const color  = colors[nivel] || '#94a3b8';
      const pct    = Math.round((conf || 0.5) * 100);
      return `
        <div style="display:flex;align-items:center;gap:6px;">
          <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
            <div style="width:${pct}%;height:100%;background:${color};border-radius:3px;"></div>
          </div>
          <span style="font-size:.7rem;color:#64748b;font-weight:600;min-width:28px;">${pct}%</span>
        </div>`;
    };

    const recLine = (r, isFirst, nivel) => {
      const rU = r.toUpperCase();
      const bgRisk = { critico:'#fef2f2', alto:'#fffbeb', medio:'#eff6ff', bajo:'#f0fdf4', vencido:'#f8fafc' };
      const bdRisk = { critico:'#fca5a5', alto:'#fde68a', medio:'#93c5fd', bajo:'#6ee7b7', vencido:'#cbd5e1' };
      if (isFirst) {
        return `<div style="background:${bgRisk[nivel]||'#f8fafc'};border:1px solid ${bdRisk[nivel]||'#e2e8f0'};border-radius:6px;padding:8px 10px;margin-bottom:6px;font-size:.77rem;font-weight:700;color:#0f172a;line-height:1.5;">${WMS.esc(r)}</div>`;
      }
      if (rU.includes('RUTA') || rU.includes('DESPACHO') || rU.includes('DISTRIBUCIÓN') || rU.includes('DISTRIBUCION')) {
        return `<div style="background:#eff6ff;border-left:3px solid #3b82f6;padding:5px 9px;font-size:.73rem;color:#1e40af;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-store" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      if (rU.includes('OPORTUNIDAD') || rU.includes('FESTIV') || rU.includes('EVENTO') || rU.includes('VENTANA') || rU.includes('PLANIFICACI')) {
        return `<div style="background:#f0fdf4;border-left:3px solid #10b981;padding:5px 9px;font-size:.73rem;color:#065f46;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-calendar-star" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      if (rU.includes('AGRAVANTE') || rU.includes('ATENCIÓN') || rU.includes('ATENCION') || rU.includes('REVISION') || rU.includes('REVISIÓN')) {
        return `<div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:5px 9px;font-size:.73rem;color:#92400e;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      if (rU.includes('ACCION') || rU.includes('ACCIÓN') || rU.includes('TÁCTICA') || rU.includes('TACTICA') || rU.includes('ESTRATEGIA')) {
        return `<div style="background:#faf5ff;border-left:3px solid #9333ea;padding:5px 9px;font-size:.73rem;color:#581c87;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-lightbulb" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      if (rU.includes('SEÑAL') || rU.includes('MITIGANTE') || rU.includes('POSITIVA') || rU.includes('CRECIENTE')) {
        return `<div style="background:#f0fdf4;border-left:3px solid #22c55e;padding:5px 9px;font-size:.73rem;color:#166534;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-arrow-trend-up" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      if (rU.includes('PROTOCOLO') || rU.includes('CUARENTENA') || rU.includes('BAJA') || rU.includes('MERMA')) {
        return `<div style="background:#fef2f2;border-left:3px solid #dc2626;padding:5px 9px;font-size:.73rem;color:#991b1b;margin-bottom:4px;border-radius:0 4px 4px 0;line-height:1.4;"><i class="fa-solid fa-ban" style="margin-right:5px;opacity:.7;"></i>${WMS.esc(r)}</div>`;
      }
      return `<div style="padding:4px 0;font-size:.73rem;color:#334155;display:flex;gap:6px;align-items:flex-start;line-height:1.4;margin-bottom:3px;"><i class="fa-solid fa-circle-dot" style="color:#6366f1;font-size:.55rem;margin-top:4px;flex-shrink:0;"></i><span>${WMS.esc(r)}</span></div>`;
    };

    const rows = data.map((p, idx) => `
      <tr class="${p.nivel_riesgo === 'critico' ? 'bg-danger-light' : ''}"
          style="transition:all .2s; background: ${idx % 2 === 0 ? '#ffffff' : '#f8fafc'};">
        <td class="ps-3 py-3" style="min-width:250px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="font-weight:700; color:#0f172a; font-size:.92rem; line-height:1.2;">${WMS.esc(p.nombre || p.producto_id)}</div>
          <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:5px;">
            <span style="font-size:.65rem; color:#475569; background:#e2e8f0; padding:2px 6px; border-radius:4px;">
              <i class="fa-solid fa-barcode me-1"></i> REF: <strong>${WMS.esc(p.referencia || 'N/A')}</strong>
            </span>
            ${p.lote ? `<span style="font-size:.65rem; color:#475569; background:#e2e8f0; padding:2px 6px; border-radius:4px;"><i class="fa-solid fa-layer-group me-1"></i> Lote: <strong>${WMS.esc(p.lote)}</strong></span>` : ''}
            ${p.categoria_producto ? `<span style="font-size:.62rem; background:#dbeafe; color:#1e40af; padding:2px 6px; border-radius:4px; font-weight:700;">${WMS.esc(p.categoria_producto)}</span>` : ''}
          </div>
          ${p.outlet_primario ? `<div style="margin-top:5px;font-size:.65rem;color:#6d28d9;"><i class="fa-solid fa-store me-1"></i>Outlet: <strong>${WMS.esc(p.outlet_primario)}</strong></div>` : ''}
        </td>
        <td class="text-center" style="width:120px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="display:inline-block; transform:scale(0.9);">${riskBadge(p.nivel_riesgo)}</div>
          ${p.tendencia_demanda ? `<div style="font-size:.6rem;margin-top:4px;color:${p.tendencia_demanda==='creciente'?'#16a34a':p.tendencia_demanda==='decreciente'?'#dc2626':'#64748b'};font-weight:700;">${p.tendencia_demanda==='creciente'?'↑':p.tendencia_demanda==='decreciente'?'↓':'→'} ${p.tendencia_demanda}</div>` : ''}
        </td>
        <td class="text-center" style="width:100px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="font-size:1.15rem; font-weight:800; color:${p.dias_para_vencer < 30 ? '#ef4444' : '#0f172a'}; line-height:1;">
            ${p.dias_para_vencer ?? '—'}
          </div>
          <div style="font-size:.62rem; color:#64748b; text-transform:uppercase; font-weight:700; margin-top:2px;">Días para Venc.</div>
        </td>
        <td class="text-end" style="width:90px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="font-weight:700; color:#1e293b; font-size:.95rem;">${p.stock_actual ?? '—'}</div>
          <div style="font-size:.6rem; color:#94a3b8; text-transform:uppercase;">Stock Total</div>
        </td>
        <td class="text-end" style="width:100px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="font-weight:700; color:${p.unidades_en_riesgo > 0 ? '#dc2626' : '#64748b'}; font-size:.95rem;">
            ${p.unidades_en_riesgo ?? '0'}
          </div>
          <div style="font-size:.6rem; color:#94a3b8; text-transform:uppercase;">Uds en Riesgo</div>
        </td>
        <td class="text-end" style="width:110px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="font-weight:600; color:#0f172a;">${Number(p.consumo_diario || 0).toFixed(2)} <small style="font-weight:400; color:#64748b;">u./d</small></div>
          <div style="font-size:.62rem; color:#94a3b8; text-transform:uppercase;">Consumo Diario</div>
          ${p.consumo_proyectado != null ? `<div style="font-size:.6rem;color:#6b7280;margin-top:2px;">Proy: ${Number(p.consumo_proyectado).toFixed(0)} uds</div>` : ''}
        </td>
        <td style="width:130px; border-right: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1;">
          <div style="margin-bottom:3px; display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:.62rem; color:#64748b; font-weight:700;">CONFIANZA</span>
            <span style="font-size:.68rem; color:#0f172a; font-weight:800;">${Math.round((p.confianza || 0.5) * 100)}%</span>
          </div>
          ${riskBar(p.nivel_riesgo, p.confianza)}
          ${p.eventos_proximos && p.eventos_proximos.length ? `<div style="margin-top:6px;font-size:.58rem;color:#0891b2;font-weight:700;"><i class="fa-solid fa-calendar-check me-1"></i>${WMS.esc(p.eventos_proximos[0].nombre)} en ${p.eventos_proximos[0].dias_hasta}d</div>` : ''}
        </td>
        <td class="ps-3" style="min-width:440px; background:rgba(241,245,249,0.4); border-bottom: 1px solid #cbd5e1; padding:10px 12px;">
          ${(p.recomendaciones || []).length
            ? (p.recomendaciones).map((r, i) => recLine(r, i === 0, p.nivel_riesgo)).join('')
            : '<div style="font-size:.78rem;color:#94a3b8;font-style:italic;">Sin recomendaciones tácticas.</div>'}
        </td>
      </tr>
    `).join('');

    // Resumen Matrix
    const cnt = { critico:0, alto:0, medio:0, bajo:0 };
    this._data.forEach(p => { if (cnt[p.nivel_riesgo] !== undefined) cnt[p.nivel_riesgo]++; });

    WMS.setContent(`
      <div class="pro-dashboard" style="padding:20px;">
        
        <!-- Tarjetas Premium Matrix -->
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-red ${this._riskFilter === 'critico' ? 'active shadow-md' : ''}" 
               style="cursor:pointer;" onclick="WMS_MODULES.inteligencia.setRiskFilter('critico')">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
              <span class="pro-kpi-trend down">&lt; 30 días</span>
            </div>
            <div class="pro-kpi-value">${cnt.critico}</div>
            <div class="pro-kpi-label">Riesgo Crítico</div>
            <div class="pro-kpi-sub">Acción inmediata requerida</div>
          </div>

          <div class="pro-kpi-card accent-amber ${this._riskFilter === 'alto' ? 'active shadow-md' : ''}" 
               style="cursor:pointer;" onclick="WMS_MODULES.inteligencia.setRiskFilter('alto')">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
              <span class="pro-kpi-trend down">&lt; 60 días</span>
            </div>
            <div class="pro-kpi-value">${cnt.alto}</div>
            <div class="pro-kpi-label">Riesgo Alto</div>
            <div class="pro-kpi-sub">Planificar rotación FEFO</div>
          </div>

          <div class="pro-kpi-card accent-blue ${this._riskFilter === 'medio' ? 'active shadow-md' : ''}" 
               style="cursor:pointer;" onclick="WMS_MODULES.inteligencia.setRiskFilter('medio')">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-clock"></i></div>
              <span class="pro-kpi-trend neu">&lt; 90 días</span>
            </div>
            <div class="pro-kpi-value">${cnt.medio}</div>
            <div class="pro-kpi-label">Riesgo Medio</div>
            <div class="pro-kpi-sub">Monitoreo de demanda</div>
          </div>

          <div class="pro-kpi-card accent-green ${this._riskFilter === 'bajo' ? 'active shadow-md' : ''}" 
               style="cursor:pointer;" onclick="WMS_MODULES.inteligencia.setRiskFilter('bajo')">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-check-double"></i></div>
              <span class="pro-kpi-trend up">Estable</span>
            </div>
            <div class="pro-kpi-value">${cnt.bajo}</div>
            <div class="pro-kpi-label">Riesgo Bajo</div>
            <div class="pro-kpi-sub">Rotación garantizada</div>
          </div>
        </div>

        <!-- Tabla Inteligente -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
            <div class="pro-section-title">
              <i class="fa-solid fa-brain me-2" style="color:#7c3aed;"></i>
              Matrix de Predicción — ${this._riskFilter ? `Filtrado por: <span class="text-primary text-uppercase">${this._riskFilter}</span>` : `Total: ${this._data.length} lotes`}
            </div>
            ${this._riskFilter ? `
              <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.inteligencia.setRiskFilter(null)">
                <i class="fa-solid fa-filter-circle-xmark"></i> Ver todo
              </button>` : ''}
          </div>
          <div class="table-responsive">
            <table class="erp-table" style="border: 1px solid #e2e8f0; table-layout: auto;">
              <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                  <th class="ps-3 py-3" style="border-right: 1px solid #e2e8f0;">PRODUCTO / DETALLES</th>
                  <th class="text-center" style="border-right: 1px solid #e2e8f0; width:120px;">NIVEL RIESGO</th>
                  <th class="text-center" style="border-right: 1px solid #e2e8f0; width:110px;">VENCIMIENTO</th>
                  <th class="text-end" style="border-right: 1px solid #e2e8f0; width:100px;">STOCK TOTAL</th>
                  <th class="text-end" style="border-right: 1px solid #e2e8f0; width:110px;">RIESGO UDS</th>
                  <th class="text-end" style="border-right: 1px solid #e2e8f0; width:110px;">AVG CONSUMO</th>
                  <th style="border-right: 1px solid #e2e8f0; width:140px;">CONFIANZA ML</th>
                  <th class="ps-3" style="min-width:400px;">ANÁLISIS & RECOMENDACIÓN TÁCTICA</th>
                </tr>
              </thead>
              <tbody style="border-top:0;">${rows}</tbody>
            </table>
          </div>
        </div>
      </div>
    `);
  },

  setRiskFilter(risk) {
    this._riskFilter = (this._riskFilter === risk) ? null : risk;
    this._renderVencimientosUI();
  },

  async scanVencimientos() {
    const btn = event?.target;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Calculando…'; }
    try {
      await API.get('/inteligencia/vencimientos?recalcular=1');
      WMS.toast('ML ejecutado — predicciones actualizadas', 'success');
      this.renderVencimientos();
    } catch(e) {
      WMS.toast('Error al ejecutar ML: ' + e.message, 'danger');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-brain"></i> Ejecutar ML'; }
    }
  },

  // ══════════════════════════════════════════════════════════════════
  // 2. ANOMALÍAS DETECTADAS
  // ══════════════════════════════════════════════════════════════════
  async renderAnomalias(page = 1, filtroEstado = '', filtroSeveridad = '') {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inteligencia.renderAnomalias()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
      <button class="btn btn-sm btn-warning ms-2" onclick="WMS_MODULES.inteligencia.scanAnomalias()">
        <i class="fa-solid fa-magnifying-glass-chart"></i> Escanear ahora
      </button>
    `);
    WMS.spinner();

    let data = [], meta = {}, kpis = { pendientes:0, criticas:0, confirmadas:0, descartadas:0 };
    try {
      const params = new URLSearchParams({ page, per_page: 20 });
      if (filtroEstado)     params.set('estado',    filtroEstado);
      if (filtroSeveridad)  params.set('severidad', filtroSeveridad);
      const r = await API.get('/inteligencia/anomalias?' + params.toString());
      data = (r.data && Array.isArray(r.data.data)) ? r.data.data : (Array.isArray(r.data) ? r.data : []);
      meta = r.data?.meta || r.meta || {};
      
      // Intentar obtener contadores globales (si la API los da) o sacar de la data cargada (esto es parcial si hay paginación)
      // Para un dashboard real, lo ideal es que la API proporcione estos contadores en meta.
      kpis = meta.kpis || {
        pendientes: data.filter(a => a.estado === 'pendiente').length,
        criticas:   data.filter(a => a.severidad === 'critica').length,
        confirmadas:data.filter(a => a.estado === 'confirmado').length,
        descartadas:data.filter(a => a.estado === 'descartado').length
      };
    } catch (e) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`);
      return;
    }

    const sevBadge = s => {
      const map = {
        critica: '<span class="badge badge-danger">Crítica</span>',
        alta:    '<span class="badge badge-warning">Alta</span>',
        media:   '<span class="badge badge-info text-dark">Media</span>',
        baja:    '<span class="badge badge-gray">Baja</span>',
      };
      return map[s] || `<span class="badge badge-gray">${s}</span>`;
    };

    const estadoBadge = s => {
      const map = {
        pendiente:  '<span class="badge badge-primary">Pendiente</span>',
        revisado:   '<span class="badge badge-success">Revisado</span>',
        confirmado: '<span class="badge badge-danger">Confirmado</span>',
        descartado: '<span class="badge badge-gray">Descartado</span>',
      };
      return map[s] || `<span class="badge badge-gray">${s}</span>`;
    };

    const rows = data.map(a => `
      <tr>
        <td class="text-center">${sevBadge(a.severidad)}</td>
        <td>
          <div style="font-weight:700;color:#1e293b;">${WMS.esc(a.titulo)}</div>
          <div style="font-size:.78rem;color:#64748b;margin-top:2px;">${WMS.esc(a.descripcion)}</div>
        </td>
        <td><span class="badge badge-teal">${WMS.esc(a.tipo)}</span></td>
        <td class="text-center">${estadoBadge(a.estado)}</td>
        <td style="font-size:.78rem;color:#64748b;">
          <i class="fa-regular fa-calendar-check me-1"></i> ${this._fmtDate(a.created_at)}
        </td>
        <td class="text-end pe-3">
          ${a.estado === 'pendiente' ? `
            <div class="btn-group">
              <button class="btn btn-icon btn-xs btn-secondary" title="Revisar" onclick="WMS_MODULES.inteligencia.revisarAnomalia(${a.id},'revisado')">
                <i class="fa-solid fa-check text-success"></i>
              </button>
              <button class="btn btn-icon btn-xs btn-secondary" title="Confirmar como fraude" onclick="WMS_MODULES.inteligencia.revisarAnomalia(${a.id},'confirmado')">
                <i class="fa-solid fa-flag text-danger"></i>
              </button>
              <button class="btn btn-icon btn-xs btn-secondary" title="Descartar" onclick="WMS_MODULES.inteligencia.revisarAnomalia(${a.id},'descartado')">
                <i class="fa-solid fa-xmark text-muted"></i>
              </button>
            </div>
          ` : `<span style="font-size:.7rem;color:#94a3b8;font-style:italic;">Procesado</span>`}
        </td>
      </tr>
    `).join('');

    const totalPages = meta.last_page || 1;
    const pager = totalPages > 1 ? `
      <div class="d-flex justify-content-center mt-3 gap-2">
        ${page > 1 ? `<button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.inteligencia.renderAnomalias(${page-1},'${filtroEstado}','${filtroSeveridad}')">← Anterior</button>` : ''}
        <span class="btn btn-sm btn-light disabled">Página ${page} / ${totalPages}</span>
        ${page < totalPages ? `<button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.inteligencia.renderAnomalias(${page+1},'${filtroEstado}','${filtroSeveridad}')">Siguiente →</button>` : ''}
      </div>
    ` : '';

    WMS.setContent(`
      <div class="pro-dashboard" style="padding:20px;">
        
        <!-- Tarjetas KPIs Anomalías -->
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-magnifying-glass-chart"></i></div>
              <span class="pro-kpi-trend neu">Total</span>
            </div>
            <div class="pro-kpi-value">${meta.total || data.length}</div>
            <div class="pro-kpi-label">Anomalías Detectadas</div>
            <div class="pro-kpi-sub">En el período actual</div>
          </div>

          <div class="pro-kpi-card accent-red">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-bolt"></i></div>
              <span class="pro-kpi-trend down">Urgente</span>
            </div>
            <div class="pro-kpi-value">${kpis.criticas || 0}</div>
            <div class="pro-kpi-label">Críticas</div>
            <div class="pro-kpi-sub">Requieren auditoría física</div>
          </div>

          <div class="pro-kpi-card accent-amber">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
              <span class="pro-kpi-trend up">Pendientes</span>
            </div>
            <div class="pro-kpi-value">${kpis.pendientes || 0}</div>
            <div class="pro-kpi-label">Por revisar</div>
            <div class="pro-kpi-sub">Esperando acción</div>
          </div>

          <div class="pro-kpi-card accent-green">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-circle-check"></i></div>
              <span class="pro-kpi-trend up">Hoy</span>
            </div>
            <div class="pro-kpi-value">${kpis.confirmadas || 0}</div>
            <div class="pro-kpi-label">Cerradas</div>
            <div class="pro-kpi-sub">Gestionadas recientemente</div>
          </div>
        </div>

        <!-- Filtros Matrix -->
        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body py-3">
            <div class="row g-3 align-items-center">
              <div class="col-auto">
                <div class="pro-section-title"><i class="fa-solid fa-filter me-2" style="color:#64748b;"></i>Herramientas de Búsqueda</div>
              </div>
              <div class="col-auto">
                <select class="form-select form-select-sm" id="filtro-estado" onchange="WMS_MODULES.inteligencia.renderAnomalias(1,this.value,document.getElementById('filtro-severidad').value)">
                  <option value="">Estado: Todos</option>
                  <option value="pendiente"  ${filtroEstado==='pendiente'  ?'selected':''}>Pendiente</option>
                  <option value="revisado"   ${filtroEstado==='revisado'   ?'selected':''}>Revisado</option>
                  <option value="confirmado" ${filtroEstado==='confirmado' ?'selected':''}>Confirmado</option>
                  <option value="descartado" ${filtroEstado==='descartado' ?'selected':''}>Descartado</option>
                </select>
              </div>
              <div class="col-auto">
                <select class="form-select form-select-sm" id="filtro-severidad" onchange="WMS_MODULES.inteligencia.renderAnomalias(1,document.getElementById('filtro-estado').value,this.value)">
                  <option value="">Severidad: Todas</option>
                  <option value="critica" ${filtroSeveridad==='critica'?'selected':''}>Crítica</option>
                  <option value="alta"    ${filtroSeveridad==='alta'   ?'selected':''}>Alta</option>
                  <option value="media"   ${filtroSeveridad==='media'  ?'selected':''}>Media</option>
                  <option value="baja"    ${filtroSeveridad==='baja'   ?'selected':''}>Baja</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Tabla de Anomalías -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3">
            <div class="pro-section-title">
              <i class="fa-solid fa-magnifying-glass-chart me-2" style="color:#ef4444;"></i>
              Anomalías Detectadas por el Motor ML
            </div>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                  <th class="ps-3 text-center" style="width:100px;">RIESGO</th>
                  <th>DESCRIPCIÓN DE LA ANOMALÍA</th>
                  <th>TIPO</th>
                  <th class="text-center">ESTADO</th>
                  <th>DETECTADA</th>
                  <th class="text-end pe-3">GESTIÓN</th>
                </tr>
              </thead>
              <tbody style="border-top:0;">
                ${data.length ? rows : `<tr><td colspan="6" class="text-center py-5 text-muted"><i class="fa-solid fa-face-smile fa-3x mb-3 d-block opacity-25"></i>No hay anomalías que coincidan con los criterios</td></tr>`}
              </tbody>
            </table>
          </div>
        </div>
        ${pager}
      </div>
    `);
  },

  async scanAnomalias() {
    const btn = event?.target?.closest('button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Escaneando…'; }
    try {
      const r = await API.get('/inteligencia/anomalias/scan');
      // r.data contiene el $result del controlador (campo: guardadas_en_bd)
      const resData = r.data || r;
      const n = resData.guardadas_en_bd ?? resData.nuevas ?? resData.insertadas ?? 0;
      WMS.toast(`Escaneo completado — ${n} anomalía(s) nueva(s) detectada(s)`, n > 0 ? 'warning' : 'success');
      this.renderAnomalias();
    } catch(e) {
      WMS.toast('Error al escanear: ' + e.message, 'danger');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-magnifying-glass-chart"></i> Escanear ahora'; }
    }
  },

  async revisarAnomalia(id, estado) {
    const labels = { revisado:'revisada', confirmado:'marcada como fraude', descartado:'descartada' };
    try {
      await API.put(`/inteligencia/anomalias/${id}`, { estado });
      WMS.toast(`Anomalía ${labels[estado] || estado}`, 'success');
      this.renderAnomalias();
    } catch(e) {
      WMS.toast('Error: ' + e.message, 'danger');
    }
  },

  // ══════════════════════════════════════════════════════════════════
  // 3. ROTACIÓN FEFO
  // ══════════════════════════════════════════════════════════════════
  async renderFefo() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inteligencia.renderFefo()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
      <div class="d-flex align-items-center gap-2 ms-2">
        <span class="small fw-bold text-muted">Ventana:</span>
        <select class="form-select form-select-sm" id="dias-sin-mov" style="width:auto;"
                onchange="WMS_MODULES.inteligencia._loadFefoData(this.value)">
          <option value="30">30 días</option>
          <option value="60" selected>60 días</option>
          <option value="90">90 días</option>
          <option value="180">180 días</option>
        </select>
      </div>
    `);
    this._loadFefoData(60);
  },

  async _loadFefoData(dias) {
    WMS.spinner();
    let alertas = [], rotacion = [];
    try {
      const [ra, rr] = await Promise.all([
        API.get('/inteligencia/fefo/alertas'),
        API.get(`/inteligencia/fefo/rotacion?dias_sin_movimiento=${dias}`),
      ]);
      const resA = ra.data || ra;
      const resR = rr.data || rr;
      alertas   = resA.alertas         || (Array.isArray(resA) ? resA : []);
      // Bug corregido: el backend (FefoEngine::getRotationReport) devuelve la clave
      // "productos_lentos", no "productos" — antes esta tabla quedaba vacía siempre.
      rotacion  = resR.productos_lentos || (Array.isArray(resR) ? resR : []);
    } catch(e) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`);
      return;
    }

    const nivelBadge = n => {
      const map = {
        vencido: '<span class="badge bg-dark text-white">Vencido</span>',
        critico: '<span class="badge badge-danger">Crítico</span>',
        alto:    '<span class="badge badge-warning">Alto</span>',
        medio:   '<span class="badge badge-info">Medio</span>',
        bajo:    '<span class="badge badge-success">Bajo</span>'
      };
      return map[n] || `<span class="badge badge-gray">${n}</span>`;
    };

    const rowsAlertas = alertas.map(a => `
      <tr>
        <td>
          <div style="font-weight:700;">${WMS.esc(a.nombre || a.producto_id)}</div>
          <div style="font-size:.7rem;color:#64748b;">Lote: ${WMS.esc(a.lote || '—')}</div>
        </td>
        <td class="text-center">${nivelBadge(a.nivel_riesgo)}</td>
        <td class="text-end fw-bold ${a.dias_para_vencer < 30 ? 'text-danger' : ''}">${a.dias_para_vencer ?? '—'}</td>
        <td class="text-end fw-bold">${a.stock_actual ?? '—'}</td>
        <td><span class="badge badge-teal">${WMS.esc(a.ubicacion || '—')}</span></td>
      </tr>
    `).join('');

    const rowsRotacion = rotacion.map(p => `
      <tr>
        <td>
          <div style="font-weight:700;">${WMS.esc(p.nombre || p.producto_id)}</div>
          <div style="font-size:.7rem;color:#64748b;">EAN: ${WMS.esc(p.ean || '—')}</div>
        </td>
        <td class="text-end fw-bold">${p.stock_actual ?? '—'}</td>
        <td class="text-end">
          <span class="badge badge-warning" style="font-size:.85rem;">${p.dias_sin_movimiento != null ? p.dias_sin_movimiento + ' días' : 'Sin registro'}</span>
        </td>
        <td style="font-size:.75rem;color:#64748b;">${p.ultimo_movimiento ? WMS.formatDate(p.ultimo_movimiento) : 'Nunca'}</td>
        <td><i class="fa-solid fa-location-dot me-1 text-muted"></i> ${WMS.esc(p.ubicacion || '—')}</td>
      </tr>
    `).join('');

    // Cálculos KPI
    const stockEnRiesgo = alertas.reduce((acc, a) => acc + (parseFloat(a.stock_actual) || 0), 0);
    const ubicacionesFefo = new Set([...alertas.map(a => a.ubicacion), ...rotacion.map(r => r.ubicacion)]).size;

    WMS.setContent(`
      <div class="pro-dashboard" style="padding:20px;">
        
        <!-- Tarjetas KPIs FEFO -->
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-red">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
              <span class="pro-kpi-trend down">Vencimiento</span>
            </div>
            <div class="pro-kpi-value">${alertas.length}</div>
            <div class="pro-kpi-label">Lotes en Alerta</div>
            <div class="pro-kpi-sub">Próximos 30 días</div>
          </div>

          <div class="pro-kpi-card accent-amber">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-hourglass-start"></i></div>
              <span class="pro-kpi-trend down">&gt; ${dias}d</span>
            </div>
            <div class="pro-kpi-value">${rotacion.length}</div>
            <div class="pro-kpi-label">Inactivos</div>
            <div class="pro-kpi-sub">Sin movimiento reciente</div>
          </div>

          <div class="pro-kpi-card accent-blue">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-boxes-packing"></i></div>
              <span class="pro-kpi-trend neu">Total Unidades</span>
            </div>
            <div class="pro-kpi-value">${Math.round(stockEnRiesgo)}</div>
            <div class="pro-kpi-label">Stock Comprometido</div>
            <div class="pro-kpi-sub">En riesgo de obsolescencia</div>
          </div>

          <div class="pro-kpi-card accent-purple">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-map-location-dot"></i></div>
              <span class="pro-kpi-trend neu">Bodega</span>
            </div>
            <div class="pro-kpi-value">${ubicacionesFefo}</div>
            <div class="pro-kpi-label">Ubicaciones</div>
            <div class="pro-kpi-sub">Áreas con baja rotación</div>
          </div>
        </div>

        <div class="row g-4">
          <!-- Alertas de vencimiento -->
          <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-white py-3">
                <div class="pro-section-title">
                  <i class="fa-solid fa-calendar-xmark me-2" style="color:#ef4444;"></i>
                  Alertas de Vencimiento Críticas
                </div>
              </div>
              <div class="table-responsive">
                <table class="erp-table">
                  <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                      <th class="ps-3">PRODUCTO</th>
                      <th class="text-center">RIESGO</th>
                      <th class="text-end">DÍAS</th>
                      <th class="text-end">STOCK</th>
                      <th>UBICACIÓN</th>
                    </tr>
                  </thead>
                  <tbody style="border-top:0;">${alertas.length ? rowsAlertas : `<tr><td colspan="5" class="text-center py-4 text-muted">Sin alertas críticas</td></tr>`}</tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Productos sin movimiento -->
          <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-header bg-white py-3">
                <div class="pro-section-title">
                  <i class="fa-solid fa-box-open me-2" style="color:#f59e0b;"></i>
                  Lotes Sin Movimiento (&gt; ${dias} días)
                </div>
              </div>
              <div class="table-responsive">
                <table class="erp-table">
                  <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                      <th class="ps-3">PRODUCTO</th>
                      <th class="text-end">STOCK</th>
                      <th class="text-end">ESTANCADO</th>
                      <th>ÚLTIMO MOV.</th>
                      <th>UBICACIÓN</th>
                    </tr>
                  </thead>
                  <tbody style="border-top:0;">${rotacion.length ? rowsRotacion : `<tr><td colspan="5" class="text-center py-4 text-muted">Todo el stock ha rotado</td></tr>`}</tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    `);
  },

  // ══════════════════════════════════════════════════════════════════
  // 4. LOG DE INTEGRIDAD (InventoryGuard)
  // ══════════════════════════════════════════════════════════════════
  async renderGuardLog(page = 1) {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inteligencia.renderGuardLog()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);
    WMS.spinner();

    let data = [], meta = {};
    try {
      const r = await API.get(`/inteligencia/guardlog?page=${page}&per_page=25`);
      data = (r.data && Array.isArray(r.data.data)) ? r.data.data : (Array.isArray(r.data) ? r.data : []);
      meta = r.data?.meta || r.meta || {};
    } catch (e) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`);
      return;
    }

    const opBadge = op => {
      const map = { picking:'#7c3aed', traslado:'#2563eb', ajuste:'#d97706', recepcion:'#059669', despacho:'#ef4444' };
      const color = map[op] || '#64748b';
      return `<span class="badge" style="background:${color};color:#fff;font-size:0.65rem;">${op.toUpperCase()}</span>`;
    };

    const rows = data.map(g => `
      <tr>
        <td class="text-center">${opBadge(g.operacion)}</td>
        <td>
          <div style="font-weight:700;color:#1e293b;">${WMS.esc(g.motivo_bloqueo)}</div>
          <div style="font-size:.72rem;color:#64748b;font-family:monospace;">${WMS.esc(g.endpoint || '—')}</div>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="user-avatar" style="width:24px;height:24px;font-size:.6rem;">${String(g.usuario_id || 'U')[0]}</div>
            <div style="font-size:.8rem;font-weight:600;">ID: ${WMS.esc(String(g.usuario_id || '—'))}</div>
          </div>
        </td>
        <td style="font-size:.78rem;color:#64748b;">${this._fmtDate(g.created_at)}</td>
        <td class="text-end">
          ${g.contexto ? `
            <button class="btn btn-xs btn-secondary" style="font-size:.7rem;"
              onclick="WMS_MODULES.inteligencia._showCtx(${JSON.stringify(JSON.stringify(g.contexto))})">
              <i class="fa-solid fa-eye me-1"></i> Contexto
            </button>
          ` : '<span class="text-muted small">—</span>'}
        </td>
      </tr>
    `).join('');

    // Cálculos KPI
    const hoy = new Date().toISOString().split('T')[0];
    const bloqueosHoy = data.filter(g => g.created_at?.startsWith(hoy)).length;
    const criticos = data.filter(g => ['ajuste','despacho'].includes(g.operacion)).length;
    const usuariosDistintos = new Set(data.map(g => g.usuario_id)).size;

    const totalPages = meta.last_page || 1;
    const pager = totalPages > 1 ? `
      <div class="d-flex justify-content-center mt-3 gap-2">
        ${page > 1 ? `<button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.inteligencia.renderGuardLog(${page-1})">← Anterior</button>` : ''}
        <span class="btn btn-sm btn-light disabled">Pág. ${page} / ${totalPages}</span>
        ${page < totalPages ? `<button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.inteligencia.renderGuardLog(${page+1})">Siguiente →</button>` : ''}
      </div>
    ` : '';

    WMS.setContent(`
      <div class="pro-dashboard" style="padding:20px;">
        
        <!-- Tarjetas KPIs Integridad -->
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-purple">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-shield-halved"></i></div>
              <span class="pro-kpi-trend up">Activo</span>
            </div>
            <div class="pro-kpi-value">${bloqueosHoy}</div>
            <div class="pro-kpi-label">Bloqueos Hoy</div>
            <div class="pro-kpi-sub">Intentos de violación de reglas</div>
          </div>

          <div class="pro-kpi-card accent-red">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-radiation"></i></div>
              <span class="pro-kpi-trend down">Crítico</span>
            </div>
            <div class="pro-kpi-value">${criticos}</div>
            <div class="pro-kpi-label">Ajustes / Salidas</div>
            <div class="pro-kpi-sub">Operaciones de alto riesgo</div>
          </div>

          <div class="pro-kpi-card accent-blue">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-users-gear"></i></div>
              <span class="pro-kpi-trend neu">Alcance</span>
            </div>
            <div class="pro-kpi-value">${usuariosDistintos}</div>
            <div class="pro-kpi-label">Usuarios Detectados</div>
            <div class="pro-kpi-sub">Implicados en este período</div>
          </div>

          <div class="pro-kpi-card accent-amber">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-list-check"></i></div>
              <span class="pro-kpi-trend neu">Escala</span>
            </div>
            <div class="pro-kpi-value">${meta.total || data.length}</div>
            <div class="pro-kpi-label">Total Histórico</div>
            <div class="pro-kpi-sub">Incidentes en base de datos</div>
          </div>
        </div>

        <!-- Tabla de Integridad -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3">
            <div class="pro-section-title">
              <i class="fa-solid fa-shield-halved me-2" style="color:#7c3aed;"></i>
              Log de Integridad — Operaciones Bloqueadas por InventoryGuard
            </div>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                  <th class="ps-3 text-center" style="width:120px;">OPERACIÓN</th>
                  <th>MOTIVO DEL BLOQUEO / ENDPOINT</th>
                  <th>USUARIO</th>
                  <th>FECHA Y HORA</th>
                  <th class="text-end pe-3">DETALLES</th>
                </tr>
              </thead>
              <tbody style="border-top:0;">
                ${data.length ? rows : `<tr><td colspan="5" class="text-center py-5 text-muted">Sin bloqueos registrados en el sistema</td></tr>`}
              </tbody>
            </table>
          </div>
        </div>
        ${pager}
      </div>
    `);
  },

  _showCtx(jsonStr) {
    let obj;
    try { obj = JSON.parse(jsonStr); } catch { obj = jsonStr; }
    const pretty = JSON.stringify(obj, null, 2);
    WMS.showModal('Contexto del Bloqueo', `<pre style="font-size:.78rem;background:#f1f5f9;padding:12px;border-radius:6px;max-height:320px;overflow:auto;">${WMS.esc(pretty)}</pre>`);
  },

  // ══════════════════════════════════════════════════════════════════
  // 5. MÉTRICAS DE RENDIMIENTO
  // ══════════════════════════════════════════════════════════════════
  async renderPerformance() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.inteligencia.renderPerformance()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);
    WMS.spinner();

    let data = [];
    try {
      const r = await API.get('/inteligencia/performance');
      // Controlador devuelve: { dias, total_registros, por_endpoint: [...] }
      const resData = (r.data && typeof r.data === 'object') ? r.data : r;
      data = resData.por_endpoint || (Array.isArray(resData) ? resData : []);
    } catch (e) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`);
      return;
    }

    if (!data.length) {
      WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-gauge" style="color:#10b981"></i><p>Sin registros de latencia alta. ¡El sistema responde de forma óptima!</p></div>`);
      return;
    }

    // Cálculos KPI — API devuelve avg_ms, max_ms, total_llamadas (no llamadas)
    const avgLatency = data.reduce((acc, p) => acc + (parseFloat(p.avg_ms || p.duracion_ms) || 0), 0) / data.length;
    const slowEndpoints = data.filter(p => (p.avg_ms || p.duracion_ms) > 2000).length;
    const totalRequests = data.reduce((acc, p) => acc + (parseInt(p.total_llamadas || p.llamadas) || 1), 0);
    const maxLat = Math.max(...data.map(p => parseFloat(p.max_ms || p.duracion_ms) || 0));

    const durColor = ms => ms >= 5000 ? '#ef4444' : ms >= 3000 ? '#f59e0b' : '#3b82f6';

    // API devuelve: endpoint_pattern, total_llamadas, avg_ms, max_ms, min_ms, avg_memoria_kb
    // No devuelve metodo (no está en GROUP BY del controlador) ni ultima_vez
    const rows = data.map(p => `
      <tr>
        <td class="text-center"><span class="badge badge-gray" style="font-family:monospace;">—</span></td>
        <td>
          <div style="font-family:var(--font-mono);font-size:.75rem;color:#1e293b;word-break:break-all;">${WMS.esc(p.endpoint_pattern || p.endpoint || '—')}</div>
        </td>
        <td class="text-end fw-bold" style="color:${durColor(p.avg_ms || p.duracion_ms)}; font-size:.9rem;">
          ${Math.round(p.avg_ms || p.duracion_ms || 0)} <small>ms</small>
        </td>
        <td class="text-end fw-bold text-muted">${p.total_llamadas || p.llamadas || 1}</td>
        <td class="text-end fw-bold" style="color:${durColor(p.max_ms || p.duracion_ms)};">
          ${Math.round(p.max_ms || p.duracion_ms || 0)} <small>ms</small>
        </td>
        <td style="font-size:.78rem;color:#64748b;">
          <i class="fa-solid fa-memory me-1"></i> ${Math.round(p.avg_memoria_kb || 0)} KB avg
        </td>
      </tr>
    `).join('');

    WMS.setContent(`
      <div class="pro-dashboard" style="padding:20px;">
        
        <!-- Tarjetas KPIs Performance -->
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-gauge-high"></i></div>
              <span class="pro-kpi-trend neu">Promedio</span>
            </div>
            <div class="pro-kpi-value">${Math.round(avgLatency)}<small style="font-size:.8rem;margin-left:2px;">ms</small></div>
            <div class="pro-kpi-label">Latencia Media</div>
            <div class="pro-kpi-sub">Tiempo de respuesta base</div>
          </div>

          <div class="pro-kpi-card accent-amber">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-bolt-lightning"></i></div>
              <span class="pro-kpi-trend down">&gt; 2s</span>
            </div>
            <div class="pro-kpi-value">${slowEndpoints}</div>
            <div class="pro-kpi-label">Rutas Lentas</div>
            <div class="pro-kpi-sub">Requieren optimización SQL</div>
          </div>

          <div class="pro-kpi-card accent-purple">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-signal"></i></div>
              <span class="pro-kpi-trend up">Hits</span>
            </div>
            <div class="pro-kpi-value">${totalRequests}</div>
            <div class="pro-kpi-label">Muestras</div>
            <div class="pro-kpi-sub">Requests monitoreados</div>
          </div>

          <div class="pro-kpi-card accent-red">
            <div class="pro-kpi-header">
              <div class="pro-kpi-icon"><i class="fa-solid fa-temperature-arrow-up"></i></div>
              <span class="pro-kpi-trend down">Pico</span>
            </div>
            <div class="pro-kpi-value">${Math.round(maxLat)}<small style="font-size:.8rem;margin-left:2px;">ms</small></div>
            <div class="pro-kpi-label">Máxima Punta</div>
            <div class="pro-kpi-sub">Peor tiempo de respuesta</div>
          </div>
        </div>

        <!-- Tabla de Performance -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white py-3">
            <div class="pro-section-title">
              <i class="fa-solid fa-gauge me-2" style="color:#3b82f6;"></i>
              Análisis Táctico de Rendimiento API
            </div>
          </div>
          <div class="table-responsive">
            <table class="erp-table">
              <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                  <th class="ps-3 text-center" style="width:80px;">VERBO</th>
                  <th>ENDPOINT / PATRÓN DE RUTA</th>
                  <th class="text-end">LATENCIA (AVG)</th>
                  <th class="text-end">LLAMADAS</th>
                  <th class="text-end">PICO (MAX)</th>
                  <th>ÚLTIMA EJECUCIÓN</th>
                </tr>
              </thead>
              <tbody style="border-top:0;">${rows}</tbody>
            </table>
          </div>
        </div>
        <div class="mt-3 p-3 bg-light border-start border-primary border-4 rounded-end small text-muted">
          <i class="fa-solid fa-circle-info me-1 text-primary"></i>
          Los datos mostrados corresponden a <strong>requests que superaron el umbral de 1500ms</strong> en la última semana. 
          Un sistema saludable debe mantener la mayoría de rutas por debajo de los 800ms.
        </div>
      </div>
    `);
  },

  // ── Utilidades internas ──────────────────────────────────────────
  _fmtDate(str) {
    if (!str) return '—';
    try {
      const d = new Date(str);
      return d.toLocaleDateString('es-CO', { day:'2-digit', month:'short', year:'numeric' })
           + ' ' + d.toLocaleTimeString('es-CO', { hour:'2-digit', minute:'2-digit' });
    } catch { return str; }
  },
};
