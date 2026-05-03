/* ============================================================
   WMS Desktop — Módulo ROTACIÓN ABC-XYZ + Forecast + Slotting
   ============================================================ */
WMS_MODULES.rotacion = {
  _sub: 'abc-xyz',
  load(sub) {
    this._sub = sub || 'abc-xyz';
    WMS.setBreadcrumb('rotacion');
    WMS.renderSidebar('rotacion');
    this._renderSub(this._sub);
  },
  destroy() {},
  subLabel(sub) {
    return { 'abc-xyz':'Clasificación ABC-XYZ', forecast:'Predicción Demanda', slotting:'Optimización Ubicaciones', heatmap:'Mapa de Calor' }[sub] || sub;
  },
  _renderSub(sub) {
    this._sub = sub;
    WMS._activateSidebarItem && WMS._activateSidebarItem(sub);
    if (sub === 'abc-xyz') this.renderAbcXyz();
    else if (sub === 'forecast') this.renderForecast();
    else if (sub === 'slotting') this.renderSlotting();
    else if (sub === 'heatmap') this.renderHeatmap();
    else this.renderAbcXyz();
  },

  // ── ABC-XYZ ──────────────────────────────────────────────
  async renderAbcXyz() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.rotacion.renderAbcXyz()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.rotacion.ejecutarAbcXyz()"><i class="fa-solid fa-calculator"></i> Ejecutar ABC-XYZ</button>
      <button class="btn btn-sm btn-secondary ms-2" onclick="WMS_MODULES.rotacion.exportAbcXyz()"><i class="fa-solid fa-download"></i> Exportar CSV</button>
    `);
    WMS.spinner();
    try {
      const r = await API.get('/rotacion/abc-xyz');
      const data = r.data || r;
      const items = data.data || data.clasificaciones || [];
      const matrix = {AX:0,AY:0,AZ:0,BX:0,BY:0,BZ:0,CX:0,CY:0,CZ:0};
      items.forEach(i => { const s = (i.segmento||'').toUpperCase(); if(matrix[s]!==undefined) matrix[s]++; });
      const mCell = (seg,css) => `<div class="abc-matrix-cell ${css}" onclick="WMS_MODULES.rotacion._filterSeg('${seg}')"><div class="cell-count">${matrix[seg]}</div><div class="cell-label">${seg}</div></div>`;
      const rows = items.slice(0,100).map((p,i) => `<tr style="background:${i%2?'#f8fafc':'#fff'}">
        <td class="ps-3"><div style="font-weight:700">${WMS.esc(p.nombre||p.producto_id)}</div><div style="font-size:.7rem;color:#64748b">${WMS.esc(p.codigo_interno||'')}</div></td>
        <td class="text-center"><span class="abc-matrix-cell abc-${(p.segmento||'cz').toLowerCase()}" style="display:inline-flex;min-height:auto;padding:3px 10px;border-radius:6px"><span class="cell-label" style="margin:0;font-size:.72rem">${WMS.esc(p.segmento||'—')}</span></span></td>
        <td class="text-center fw-bold">${p.clase_abc||'—'}</td>
        <td class="text-center fw-bold">${p.clase_xyz||'—'}</td>
        <td class="text-end">$${Number(p.total_valor||0).toLocaleString()}</td>
        <td class="text-end">${Number(p.demanda_media||0).toFixed(1)}</td>
        <td class="text-end">${Number(p.coef_variacion||0).toFixed(3)}</td>
        <td class="text-center"><span class="badge badge-${p.zona_recomendada==='oro'?'warning':p.zona_recomendada==='plata'?'info':'gray'}">${WMS.esc(p.zona_recomendada||'—')}</span></td>
      </tr>`).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="row g-4 mb-4"><div class="col-12 col-lg-5">
          <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-chess-board me-2" style="color:#7c3aed"></i>Matriz ABC-XYZ — ${items.length} productos</div></div>
          <div class="card-body"><div class="abc-matrix">
            <div class="abc-matrix-header"></div><div class="abc-matrix-header">X (Estable)</div><div class="abc-matrix-header">Y (Variable)</div><div class="abc-matrix-header">Z (Errática)</div>
            <div class="abc-matrix-label">A</div>${mCell('AX','abc-ax')}${mCell('AY','abc-ay')}${mCell('AZ','abc-az')}
            <div class="abc-matrix-label">B</div>${mCell('BX','abc-bx')}${mCell('BY','abc-by')}${mCell('BZ','abc-bz')}
            <div class="abc-matrix-label">C</div>${mCell('CX','abc-cx')}${mCell('CY','abc-cy')}${mCell('CZ','abc-cz')}
          </div></div></div>
        </div><div class="col-12 col-lg-7">
          <div class="pro-kpi-grid">
            <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-cubes"></i></div></div><div class="pro-kpi-value">${items.length}</div><div class="pro-kpi-label">Productos Clasificados</div></div>
            <div class="pro-kpi-card accent-red"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-star"></i></div></div><div class="pro-kpi-value">${matrix.AX+matrix.AY+matrix.AZ}</div><div class="pro-kpi-label">Clase A (80% valor)</div></div>
            <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-layer-group"></i></div></div><div class="pro-kpi-value">${matrix.BX+matrix.BY+matrix.BZ}</div><div class="pro-kpi-label">Clase B (15% valor)</div></div>
            <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-box"></i></div></div><div class="pro-kpi-value">${matrix.CX+matrix.CY+matrix.CZ}</div><div class="pro-kpi-label">Clase C (5% valor)</div></div>
          </div>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-table me-2" style="color:#1a56db"></i>Detalle de Clasificación</div></div>
        <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
          <th class="ps-3">PRODUCTO</th><th class="text-center">SEGMENTO</th><th class="text-center">ABC</th><th class="text-center">XYZ</th><th class="text-end">VALOR TOTAL</th><th class="text-end">DEMANDA/MES</th><th class="text-end">CV</th><th class="text-center">ZONA</th>
        </tr></thead><tbody>${rows||'<tr><td colspan="8" class="text-center py-5 text-muted">Ejecute la clasificación ABC-XYZ para generar datos</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async ejecutarAbcXyz() {
    if(!confirm('¿Ejecutar clasificación ABC-XYZ? Esto puede tardar unos segundos.')) return;
    try { await API.post('/rotacion/abc-xyz/ejecutar'); WMS.toast('ABC-XYZ ejecutado correctamente','success'); this.renderAbcXyz(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },
  async exportAbcXyz() {
    try { const r = await API.get('/rotacion/export'); const d = r.data||r; if(d.csv) { const b=new Blob([d.csv],{type:'text/csv'}); const a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download='abc_xyz_export.csv'; a.click(); } }
    catch(e) { WMS.toast('Error exportando: '+e.message,'danger'); }
  },
  _filterSeg(seg) { WMS.toast(`Filtro: segmento ${seg}`,'info'); },

  // ── FORECAST ──────────────────────────────────────────────
  async renderForecast() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.rotacion.renderForecast()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.rotacion.ejecutarForecast()"><i class="fa-solid fa-wand-magic-sparkles"></i> Calcular Predicción</button>
    `);
    WMS.spinner();
    try {
      const [rf, ra] = await Promise.all([API.get('/forecast'), API.get('/forecast/alertas')]);
      const forecasts = (rf.data||rf).data || (rf.data||rf).predicciones || [];
      const alertas = (ra.data||ra).data || (ra.data||ra).alertas || [];
      const rowsF = forecasts.slice(0,50).map(f => `<tr>
        <td class="ps-3"><div style="font-weight:700">${WMS.esc(f.nombre||f.producto_id)}</div></td>
        <td class="text-end fw-bold">${Number(f.demanda_pred||0).toFixed(1)}</td>
        <td class="text-center"><span class="badge badge-${f.modelo_usado==='ensemble'?'purple':f.modelo_usado==='holt_winters'?'info':'gray'}">${WMS.esc(f.modelo_usado||'—')}</span></td>
        <td class="text-center">${f.horizonte_dias||30}d</td>
        <td class="text-end">${f.mape?Number(f.mape).toFixed(1)+'%':'—'}</td>
        <td class="text-center">${f.alerta_quiebre?'<span class="badge badge-danger">⚠ Quiebre</span>':'<span class="badge badge-success">OK</span>'}</td>
        <td class="text-end fw-bold ${(f.dias_hasta_quiebre||999)<14?'text-danger':''}">${f.dias_hasta_quiebre||'—'}</td>
      </tr>`).join('');
      const rowsA = alertas.slice(0,20).map(a => `<tr>
        <td class="ps-3"><div style="font-weight:700;color:#dc2626">${WMS.esc(a.nombre||a.producto_id)}</div></td>
        <td class="text-end fw-bold text-danger">${a.dias_hasta_quiebre||'—'} días</td>
        <td class="text-end">${Number(a.stock_seguridad_sugerido||0).toFixed(0)}</td>
        <td class="text-end">${Number(a.demanda_pred||0).toFixed(1)}</td>
      </tr>`).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-chart-line"></i></div></div><div class="pro-kpi-value">${forecasts.length}</div><div class="pro-kpi-label">Predicciones Activas</div></div>
          <div class="pro-kpi-card accent-red"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><span class="pro-kpi-trend down">Urgente</span></div><div class="pro-kpi-value">${alertas.length}</div><div class="pro-kpi-label">Alertas de Quiebre</div></div>
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-bullseye"></i></div></div><div class="pro-kpi-value">${forecasts.length?Number(forecasts.reduce((a,f)=>a+(f.score_confianza||0),0)/forecasts.length*100).toFixed(0)+'%':'—'}</div><div class="pro-kpi-label">Confianza Promedio</div></div>
        </div>
        ${alertas.length?`<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #ef4444!important"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-bell me-2" style="color:#ef4444"></i>Alertas de Quiebre de Stock</div></div>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr style="background:#fef2f2"><th class="ps-3">PRODUCTO</th><th class="text-end">DÍAS AL QUIEBRE</th><th class="text-end">STOCK SEGURIDAD</th><th class="text-end">DEMANDA PRED.</th></tr></thead><tbody>${rowsA}</tbody></table></div></div>`:''}
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-chart-line me-2" style="color:#1a56db"></i>Predicciones de Demanda</div></div>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr style="background:#f8fafc"><th class="ps-3">PRODUCTO</th><th class="text-end">DEMANDA PRED.</th><th class="text-center">MODELO</th><th class="text-center">HORIZONTE</th><th class="text-end">MAPE</th><th class="text-center">ESTADO</th><th class="text-end">DÍAS A QUIEBRE</th></tr></thead>
        <tbody>${rowsF||'<tr><td colspan="7" class="text-center py-5 text-muted">Ejecute el motor de predicción</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async ejecutarForecast() {
    try { await API.post('/forecast/calcular'); WMS.toast('Predicciones calculadas','success'); this.renderForecast(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },

  // ── SLOTTING ──────────────────────────────────────────────
  async renderSlotting() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.rotacion.renderSlotting()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>
      <button class="btn btn-sm btn-primary ms-2" onclick="WMS_MODULES.rotacion.ejecutarSlotting()"><i class="fa-solid fa-wand-magic-sparkles"></i> Optimizar Ubicaciones</button>
    `);
    WMS.spinner();
    try {
      const r = await API.get('/slotting');
      const data = (r.data||r).data || (r.data||r).asignaciones || [];
      const rows = data.slice(0,80).map(s => `<tr>
        <td class="ps-3"><div style="font-weight:700">${WMS.esc(s.producto_nombre||s.producto_id)}</div></td>
        <td class="text-center"><span class="badge badge-${s.segmento&&s.segmento[0]==='A'?'danger':s.segmento&&s.segmento[0]==='B'?'warning':'gray'}">${WMS.esc(s.segmento||'—')}</span></td>
        <td><code>${WMS.esc(s.ubicacion_codigo||'—')}</code></td>
        <td class="text-center"><span class="badge badge-${s.zona==='oro'?'warning':s.zona==='plata'?'info':'gray'}">${WMS.esc(s.zona||'—')}</span></td>
        <td class="text-end fw-bold">${Number(s.score_asignacion||0).toFixed(1)}/10</td>
        <td class="text-center">${s.vigente?'<span class="status-badge sb-active">Vigente</span>':'<span class="status-badge sb-inactive">Inactiva</span>'}</td>
      </tr>`).join('');
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-purple"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-location-dot"></i></div></div><div class="pro-kpi-value">${data.length}</div><div class="pro-kpi-label">Asignaciones Óptimas</div></div>
          <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-gauge-high"></i></div></div><div class="pro-kpi-value">${data.length?Number(data.reduce((a,s)=>a+(s.score_asignacion||0),0)/data.length).toFixed(1):'—'}</div><div class="pro-kpi-label">Score Promedio</div></div>
        </div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-location-dot me-2" style="color:#7c3aed"></i>Asignaciones de Slotting Óptimo</div></div>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr style="background:#f8fafc"><th class="ps-3">PRODUCTO</th><th class="text-center">SEGMENTO</th><th>UBICACIÓN</th><th class="text-center">ZONA</th><th class="text-end">SCORE</th><th class="text-center">ESTADO</th></tr></thead>
        <tbody>${rows||'<tr><td colspan="6" class="text-center py-5 text-muted">Ejecute la optimización de slotting</td></tr>'}</tbody></table></div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  },
  async ejecutarSlotting() {
    if(!confirm('¿Ejecutar optimización de slotting?')) return;
    try { await API.post('/slotting/ejecutar'); WMS.toast('Slotting optimizado','success'); this.renderSlotting(); }
    catch(e) { WMS.toast('Error: '+e.message,'danger'); }
  },

  // ── HEATMAP ──────────────────────────────────────────────
  async renderHeatmap() {
    WMS.setToolbar(`<button class="pro-btn-refresh" onclick="WMS_MODULES.rotacion.renderHeatmap()"><i class="fa-solid fa-rotate-right"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const r = await API.get('/ubicaciones-ml/mapa/ocupacion');
      const data = (r.data||r).data || (r.data||r).ubicaciones || [];
      const pasillos = [...new Set(data.map(u => u.pasillo))].sort();
      const maxNivel = Math.max(...data.map(u => u.nivel||1), 1);
      let gridHtml = '';
      pasillos.forEach(p => {
        const ubsP = data.filter(u => u.pasillo === p);
        gridHtml += `<div style="margin-bottom:16px"><div style="font-weight:700;font-size:.78rem;color:#475569;margin-bottom:6px">Pasillo ${WMS.esc(p)}</div>`;
        gridHtml += `<div class="wh-heatmap" style="grid-template-columns:repeat(${Math.min(ubsP.length,12)},1fr)">`;
        ubsP.forEach(u => {
          const zone = u.zona || 'bronce';
          const occ = u.estado === 'Ocupado' ? 'occ-high' : u.estado === 'Disponible' ? 'occ-empty' : 'occ-mid';
          gridHtml += `<div class="wh-cell zone-${zone} ${occ}" title="${u.codigo} — ${u.estado}">${u.codigo||''}</div>`;
        });
        gridHtml += '</div></div>';
      });
      const stats = { total: data.length, ocupadas: data.filter(u=>u.estado==='Ocupado').length, disponibles: data.filter(u=>u.estado==='Disponible').length };
      const pctOcup = stats.total ? Math.round(stats.ocupadas/stats.total*100) : 0;
      WMS.setContent(`<div class="pro-dashboard" style="padding:20px">
        <div class="pro-kpi-grid mb-4">
          <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-map"></i></div></div><div class="pro-kpi-value">${stats.total}</div><div class="pro-kpi-label">Ubicaciones Totales</div></div>
          <div class="pro-kpi-card accent-red"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-box-archive"></i></div></div><div class="pro-kpi-value">${pctOcup}%</div><div class="pro-kpi-label">Ocupación</div></div>
          <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-check"></i></div></div><div class="pro-kpi-value">${stats.disponibles}</div><div class="pro-kpi-label">Disponibles</div></div>
        </div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white py-3"><div class="pro-section-title"><i class="fa-solid fa-fire me-2" style="color:#ef4444"></i>Mapa de Calor del Almacén</div>
          <div style="display:flex;gap:12px;font-size:.68rem">
            <span><span class="wh-cell zone-oro" style="display:inline-block;width:14px;height:14px;min-height:14px;vertical-align:middle"></span> Oro</span>
            <span><span class="wh-cell zone-plata" style="display:inline-block;width:14px;height:14px;min-height:14px;vertical-align:middle"></span> Plata</span>
            <span><span class="wh-cell zone-bronce" style="display:inline-block;width:14px;height:14px;min-height:14px;vertical-align:middle"></span> Bronce</span>
          </div>
        </div><div class="card-body">${gridHtml||'<div class="text-center text-muted py-5">No hay ubicaciones registradas. Importe el mapa del almacén.</div>'}</div></div>
      </div>`);
    } catch(e) { WMS.setContent(`<div class="m-empty"><i class="fa-solid fa-triangle-exclamation"></i><p>${e.message}</p></div>`); }
  }
};
