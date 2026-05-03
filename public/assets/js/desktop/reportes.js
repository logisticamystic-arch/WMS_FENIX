/* ============================================================
   WMS Desktop - Módulo REPORTES
   ============================================================ */
WMS_MODULES.reportes = {
  load(sub) {
    WMS.setBreadcrumb('reportes', this.subLabel(sub));
    WMS.renderSidebar('reportes');
    const s = sub || 'gerencial';
    const fn = {
      gerencial:this.show_gerencial, kardex:this.show_kardex,
      recepciones:this.show_recepciones, despachos:this.show_despachos,
      picking:this.show_picking, devoluciones:this.show_devoluciones,
      proveedores:this.show_proveedores, audit:this.show_audit,
      odc:this.show_odc, contingencia:this.show_contingencia,
    };
    (fn[s]?.bind(this) || fn.gerencial.bind(this))();
  },
  subLabel(s){const m={gerencial:'Dashboard Gerencial',kardex:'Kardex',recepciones:'Recepciones',despachos:'Despachos',picking:'Picking',devoluciones:'Devoluciones',proveedores:'Evaluación Proveedores',audit:'Log de Auditoría',odc:'Recibo Detallado (ODC)',contingencia:'Plan de Contingencia'};return m[s]||s||'Panel';},

  exportBtn(endpoint,label){return `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportar('${endpoint}')"><i class="fa-solid fa-file-excel"></i> ${label}</button>`;},

  async exportar(endpoint){
    WMS.toast('info','Generando exportación...');
    try {
      const sep = endpoint.includes('?') ? '&' : '?';
      const token = localStorage.getItem('wms_token') || '';
      const url = `${API_BASE}${endpoint}${sep}export=excel&token=${encodeURIComponent(token)}`;
      window.open(url, '_blank');
    } catch(e) { WMS.toast('error','Error generando reporte'); }
  },

  async show_gerencial() {
    WMS.setToolbar(`<button class="btn btn-sm btn-outline-secondary" onclick="WMS.nav('inteligencia','vencimientos')"><i class="fa-solid fa-brain"></i> Análisis ML Predictivo</button>`);
    WMS.spinner();

    const fMes = document.getElementById('dash-filter-mes') ? document.getElementById('dash-filter-mes').value : new Date().getMonth() + 1;
    const fCat = document.getElementById('dash-filter-categoria') ? document.getElementById('dash-filter-categoria').value : '';
    const fProd = document.getElementById('dash-filter-producto') ? document.getElementById('dash-filter-producto').value : '';

    let data = {};
    try {
      const qs = new URLSearchParams({ mes: fMes, categoria: fCat, producto: fProd });
      const rs = await API.get('/reportes/dashboard-bi', qs.toString());
      data = rs.data || rs || {};
    } catch(e) {
      console.error('Error Dashboard BI:', e);
    }

    let metrics = data.metrics || {}, 
        pickingPorCategoria = data.pickingPorCategoria || [], 
        ventasMesAMes = data.ventasMesAMes || [], 
        tendenciaCat = data.tendenciaCat || [], 
        bajaRotacion = data.bajaRotacion || [], 
        mlForecast = data.mlForecast || {reales:[], forecast:[]}, 
        filtros = data.filtros || {};
    
    // Opciones
    const mesOpts = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
        .map((m,i)=>`<option value="${i+1}" ${fMes==(i+1)?'selected':''}>${m}</option>`).join('');
    const catOpts = `<option value="">Todos</option>` + (filtros?.categorias||[]).map(c=>`<option value="${c.id}" ${fCat==c.id?'selected':''}>${WMS.esc(c.nombre)}</option>`).join('');
    const prodOpts = `<option value="">Todos</option>` + (filtros?.productos||[]).map(p=>`<option value="${p.id}" ${fProd==p.id?'selected':''}>${WMS.esc(p.codigo_interno)} - ${WMS.esc(p.nombre)}</option>`).join('');

    const primaryColors = ['#1a56db', '#0891b2', '#4f46e5', '#2563eb', '#0284c7', '#475569'];

    WMS.setContent(`
      <div class="inv-commander-root animate-fade-in" style="padding:20px; background:#f8fafc; min-height:calc(100vh - 120px); overflow:auto;">
        
        <!-- Dashboard Filters EXACTAMENTE IGUAL AL DASHBOARD PRINCIPAL -->
        <div style="background:#fff; border-radius:16px; padding:20px; border:1px solid #e2e8f0; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-weight:900; color:#0f172a; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fa-solid fa-sliders" style="color:var(--cmd-blue, #2563eb);"></i> Control de Filtros
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
                <div>
                  <label style="display:block;margin-bottom:6px;font-size:.75rem;font-weight:600;color:#475569;">Mes</label>
                  <select id="dash-filter-mes" class="form-control" onchange="WMS_MODULES.reportes.show_gerencial()">${mesOpts}</select>
                </div>
                <div>
                  <label style="display:block;margin-bottom:6px;font-size:.75rem;font-weight:600;color:#475569;">Categoría</label>
                  <select id="dash-filter-categoria" class="form-control" onchange="WMS_MODULES.reportes.show_gerencial()">${catOpts}</select>
                </div>
                <div>
                  <label style="display:block;margin-bottom:6px;font-size:.75rem;font-weight:600;color:#475569;">Producto</label>
                  <select id="dash-filter-producto" class="form-control select2-bi">${prodOpts}</select>
                </div>
            </div>
        </div>

        <!-- KPI Cards Row EXACTAMENTE IGUAL AL DASHBOARD PRINCIPAL -->
        <div class="kpi-dashboard-row">
          <div class="kpi-dashboard-card blue">
            <div class="kpi-dash-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="kpi-dash-info">
              <span class="kpi-dash-label">Unidades Separadas</span>
              <span class="kpi-dash-value">${metrics.totalPicksMes ? Number(metrics.totalPicksMes).toLocaleString('es-CO') : 0}</span>
              <span class="kpi-dash-sub">Volumen total en el mes</span>
            </div>
          </div>
          <div class="kpi-dashboard-card ${metrics.crecimientoPct>=0?'green':'red'}">
            <div class="kpi-dash-icon"><i class="fa-solid ${metrics.crecimientoPct>=0?'fa-arrow-trend-up':'fa-arrow-trend-down'}"></i></div>
            <div class="kpi-dash-info">
              <span class="kpi-dash-label">Variación M.O.M</span>
              <span class="kpi-dash-value">${metrics.crecimientoPct>0?'+':''}${metrics.crecimientoPct}%</span>
              <span class="kpi-dash-sub">Crecimiento / Caída</span>
            </div>
          </div>
          <div class="kpi-dashboard-card amber">
            <div class="kpi-dash-icon"><i class="fa-solid fa-boxes-packing"></i></div>
            <div class="kpi-dash-info">
              <span class="kpi-dash-label">Baja Rotación</span>
              <span class="kpi-dash-value">${metrics.bajaRotacionCount||0}</span>
              <span class="kpi-dash-sub">Inmovilizados > 90 días</span>
            </div>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(350px, 1fr)); gap:20px; margin-bottom:24px;">
          <!-- Grafico 1 -->
          <div style="background:#fff; border-radius:16px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
            <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fa-regular fa-calendar-check" style="color:var(--cmd-blue, #2563eb);"></i> Total Unidades Separadas Por Mes
            </div>
            <div style="position:relative; height:280px;">
              <canvas id="chartGeneralPicks"></canvas>
            </div>
          </div>

          <!-- Grafico 2 -->
          <div style="background:#fff; border-radius:16px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
            <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fa-solid fa-layer-group" style="color:#0891b2;"></i> Picking Volumétrico por Categoría
            </div>
            <div style="position:relative; height:280px;">
              <canvas id="chartBiCategoriasBars"></canvas>
            </div>
          </div>
        </div>

        <!-- Fila 3: Tendencia Mensual y Forecast ML -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(350px, 1fr)); gap:20px; margin-bottom:24px;">
          <div style="background:#fff; border-radius:16px; padding:24px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
            <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fa-solid fa-chart-area" style="color:#475569;"></i> Tendencia Mensual Picking Por Categoría
            </div>
            <div style="position:relative; height:280px;">
              <canvas id="chartTendenciaCat"></canvas>
            </div>
          </div>

          <div style="background:#fff; border-radius:16px; padding:24px; border:1px solid #e2e8f0; border-top:4px solid #059669; box-shadow:0 1px 3px rgba(0,0,0,0.05); min-height:350px;">
            <div style="font-weight:900; color:#0f172a; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:0.9rem; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fa-solid fa-brain" style="color:#059669;"></i> Forecast ML: Cierre de Año
            </div>
            <div style="position:relative; height:280px;">
              <canvas id="chartBiForecast"></canvas>
            </div>
          </div>
        </div>

        <!-- Fila 4: Matriz Cero Rotación -->
        <div style="background:#fff;border-radius:12px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
              <div style="font-weight:800;color:#1e3a5f;"><i class="fa-solid fa-battery-quarter" style="color:#f59e0b;margin-right:6px;"></i>Alerta: Stock Inmovilizado y Baja Rotación</div>
            </div>
            <div style="overflow-x:auto;">
               <table style="width:100%;border-collapse:collapse;font-size:12px;">
                 <thead><tr style="background:#f8fafc;">
                   <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Cód</th>
                   <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Producto</th>
                   <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Categoría</th>
                   <th style="padding:8px 12px;text-align:right;color:#64748b;font-weight:700;">Stock Inmovilizado</th>
                 </tr></thead>
                 <tbody>
                   ${(bajaRotacion||[]).map((b,i)=>`<tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                     <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#64748b;font-weight:bold;">${WMS.esc(b.codigo_interno)}</td>
                     <td style="padding:8px 12px;font-weight:600;color:#1e3a5f;">${WMS.esc(b.producto)}</td>
                     <td style="padding:8px 12px;text-align:left;">${WMS.esc(b.categoria||'Sin Cat')}</td>
                     <td style="padding:8px 12px;text-align:right;font-weight:700;color:#dc2626;">${Number(b.stock_inmovilizado||0).toLocaleString('es-CO')}</td>
                   </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">Inventario Saludable - Sin inmovilizados</td></tr>'}
                 </tbody>
               </table>
            </div>
        </div>

      </div>
    `);

    // Inits Charts y UI Plugins
    setTimeout(() => {
        // Init Select2 en el cuerpo del documento
        if (window.jQuery && $.fn.select2) {
            const $prod = $('#dash-filter-producto');
            if ($prod.data('select2')) {
                $prod.select2('destroy');
            }
            $prod.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Buscar ODC o SKU...',
                allowClear: true
            }).on('change', () => { WMS_MODULES.reportes.show_gerencial(); });
        }

        const labelsMeses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        // 2. Bar Chart: Total Unidades Separadas por mes
        const ctxGen = document.getElementById('chartGeneralPicks');
        if (ctxGen && window.Chart) {
            new Chart(ctxGen, {
                type: 'bar',
                data: {
                    labels: labelsMeses,
                    datasets: [{
                        label: 'Unidades',
                        data: mlForecast.reales,
                        backgroundColor: '#1a56db',
                        borderRadius: 4
                    }]
                },
                options: { 
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } }, x: { grid: { display:false } } }
                }
            });
        }

        // 3. Bar Chart: Total Picking por Categoría
        const ctxCat = document.getElementById('chartBiCategoriasBars');
        if (ctxCat && window.Chart) {
            const catLabels = (pickingPorCategoria||[]).map(i=>i.categoria||'Sin Categoría');
            const catData = (pickingPorCategoria||[]).map(i=>i.total);
            new Chart(ctxCat, {
                type: 'bar',
                data: {
                    labels: catLabels.length ? catLabels : ['Sin Datos'],
                    datasets: [{
                        label: 'Volumen',
                        data: catData.length ? catData : [1],
                        backgroundColor: '#0891b2',
                        borderRadius: 4
                    }]
                },
                options: { 
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { grid: { borderDash: [2, 4] } }, y: { grid: { display:false } } }
                }
            });
        }

        // 4. Line Chart Multieje: Tendencia Picking Mensual por Categoría
        const ctxTend = document.getElementById('chartTendenciaCat');
        if (ctxTend && window.Chart) {
            const datasets = (tendenciaCat||[]).map((cat, idx) => ({
                label: cat.categoria,
                data: cat.data,
                borderColor: primaryColors[idx % primaryColors.length],
                backgroundColor: primaryColors[idx % primaryColors.length] + '20',
                borderWidth: 2,
                tension: 0.3,
                fill: false
            }));

            new Chart(ctxTend, {
                type: 'line',
                data: { labels: labelsMeses, datasets: datasets },
                options: { 
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, font: { size: 10 } } } },
                    scales: { y: { grid: { borderDash: [2, 4] } }, x: { grid: { display:false } } }
                }
            });
        }

        // 5. Line Chart: Forecast ML
        const ctxFore = document.getElementById('chartBiForecast');
        if (ctxFore && window.Chart) {
            new Chart(ctxFore, {
                type: 'line',
                data: {
                    labels: labelsMeses,
                    datasets: [
                        {
                            label: 'Datos Reales',
                            data: mlForecast.reales,
                            borderColor: '#475569',
                            backgroundColor: 'rgba(71, 85, 105, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.2
                        },
                        {
                            label: 'Proyección IA',
                            data: mlForecast.forecast,
                            borderColor: '#059669',
                            borderDash: [5, 5],
                            borderWidth: 2,
                            tension: 0.2,
                            fill: false
                        }
                    ]
                },
                options: { 
                   responsive: true, maintainAspectRatio: false, 
                   plugins: { tooltip: { mode: 'index', intersect: false } },
                   scales: { y: { grid: { borderDash: [2, 4] } }, x: { grid: { display:false } } }
                }
            });
        }
    }, 250);
  },

  async show_kardex() {
    WMS.setToolbar(this.exportBtn('/reportes/kardex', 'Exportar Kardex'));
    WMS.spinner();
    try {
      const r = await API.get('/reportes/kardex', 'limit=300');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar"><div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Filtrar kardex..." oninput="WMS_MODULES.reportes.filterTable(this.value,'k-table')"></div></div>
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-file-invoice"></i> Movimientos de Kardex (${items.length})</span></div>
        <div class="table-container"><table class="data-table" id="k-table"><thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Referencia</th><th>Cantidad</th><th>Ubicación</th></tr></thead>
        <tbody>${items.map(k=>`<tr><td>${WMS.formatDateTime(k.created_at)}</td><td>${WMS.esc(k.producto?.nombre||k.producto_nombre||'-')}</td><td><span class="badge ${k.tipo==='Entrada'?'badge-success':'badge-danger'}">${k.tipo}</span></td><td>${WMS.esc(k.referencia||'-')}</td><td>${k.cantidad}</td><td>${WMS.esc(k.ubicacion?.codigo||k.ubicacion_codigo||'-')}</td></tr>`).join('')||'<tr><td colspan="6" class="table-empty">Sin movimientos</td></tr>'}</tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Kardex</div>'); }
  },

  async show_recepciones() {
    WMS.setToolbar(this.exportBtn('/reportes/recepciones', 'Exportar Recepciones'));
    WMS.spinner();
    try {
      const r = await API.get('/reportes/recepciones', 'limit=100');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar"><div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Filtrar recepciones..." oninput="WMS_MODULES.reportes.filterTable(this.value,'r-table')"></div></div>
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-ramp-box"></i> Historico de Recepciones (${items.length})</span></div>
        <div class="table-container"><table class="data-table" id="r-table"><thead><tr><th>Fecha</th><th>N° Recepción</th><th>Auxiliar</th><th>Estado</th><th>Items</th></tr></thead>
        <tbody>${items.map(i=>`<tr><td>${WMS.formatDate(i.created_at)}</td><td><strong>${WMS.esc(i.numero_recepcion)}</strong></td><td>${WMS.esc(i.auxiliar?.nombre||'-')}</td><td><span class="status-chip status-cerrada">${i.estado}</span></td><td>${i.detalles_count || 0}</td></tr>`).join('')||'<tr><td colspan="5" class="table-empty">Sin recepciones</td></tr>'}</tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Recepciones</div>'); }
  },

  async show_despachos() {
    WMS.setToolbar(this.exportBtn('/reportes/despachos', 'Exportar Despachos'));
    WMS.spinner();
    try {
      const r = await API.get('/reportes/despachos', 'limit=100');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-fast"></i> Despachos Consolidados (${items.length})</span></div>
        <div class="table-container"><table class="data-table"><thead><tr><th>Fecha</th><th>N° Despacho</th><th>Cliente</th><th>Estado</th><th>Bultos</th></tr></thead>
        <tbody>${items.map(i=>`<tr><td>${WMS.formatDate(i.created_at)}</td><td><strong>${WMS.esc(i.numero_despacho)}</strong></td><td>${WMS.esc(i.cliente?.razon_social||'-')}</td><td><span class="badge badge-success">${i.estado}</span></td><td>${i.total_bultos||0}</td></tr>`).join('')||'<tr><td colspan="5" class="table-empty">Sin despachos</td></tr>'}</tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Despachos</div>'); }
  },

  async show_picking() {
    WMS.setToolbar(this.exportBtn('/reportes/picking', 'Exportar Picking'));
    WMS.spinner();
    try {
      const r = await API.get('/reportes/picking', 'limit=100');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Reporte Global de Picking (${items.length})</span></div>
        <div class="table-container"><table class="data-table"><thead><tr><th>Fecha</th><th>Orden ID</th><th>Estado</th><th>Unidades</th></tr></thead>
        <tbody>${items.map(i=>`<tr><td>${WMS.formatDate(i.created_at)}</td><td><strong>${i.id}</strong></td><td><span class="badge badge-info">${i.estado}</span></td><td>${i.total_unidades||0}</td></tr>`).join('')||'<tr><td colspan="4" class="table-empty">Sin órdenes de picking</td></tr>'}</tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Picking</div>'); }
  },

  async show_devoluciones() {
    WMS.spinner();
    try {
      const data = await API.get('/reportes/devoluciones');
      const items = data.data || data || [];
      const rows = items.map(d => `
        <tr>
          <td><strong>${WMS.esc(d.numero_devolucion || d.id)}</strong></td>
          <td><span class="badge border text-dark bg-light">${WMS.esc(d.tipo)}</span></td>
          <td>${WMS.esc(d.proveedor || '—')}</td>
          <td>${WMS.formatDateTime(d.created_at)}</td>
          <td><span class="badge badge-info">${WMS.esc(d.estado)}</span></td>
          <td>
            <ul class="mb-0 ps-3" style="font-size:0.75rem; color:#475569;">
              ${(d.detalles || []).map(det => `<li>${WMS.esc(det.producto?.nombre||'Prod')} (Cant: ${det.cantidad})</li>`).join('')}
            </ul>
          </td>
        </tr>
      `).join('');

      WMS.setContent(`
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-reply"></i> Reporte de Devoluciones (${items.length})</span></div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th># Devolución</th><th>Tipo</th><th>Proveedor</th><th>Fecha</th><th>Estado</th><th>Detalle Productos</th>
                </tr>
              </thead>
              <tbody>${rows || '<tr><td colspan="6" class="table-empty">No hay devoluciones registradas</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.toast('error', 'Error cargando Reporte de Devoluciones'); }
  },

  async show_proveedores() {
    WMS.spinner();
    try {
      const data = await API.get('/reportes/evaluacion-proveedores');
      const items = data.data || data || [];
      const rows = items.map(p => `
        <tr>
          <td><strong>${WMS.esc(p.proveedor)}</strong><br><small class="text-muted">NIT: ${WMS.esc(p.nit)}</small></td>
          <td class="text-center">${p.total_odc}</td>
          <td class="text-center">${p.pct_cumplimiento_odc !== null ? p.pct_cumplimiento_odc + '%' : '—'}</td>
          <td class="text-center">${p.total_recepciones}</td>
          <td class="text-center">${p.novedades_recepcion}</td>
          <td class="text-center fw-bold" style="color:#7c3aed;">${p.avg_demora_atencion !== null ? p.avg_demora_atencion + ' m' : '—'}</td>
          <td class="text-center fw-bold" style="color:#0ea5e9;">${p.avg_tiempo_operacion !== null ? p.avg_tiempo_operacion + ' m' : '—'}</td>
          <td class="text-center">
            <div style="display:flex; align-items:center; gap:8px; justify-content:center;">
              <div style="flex:1; max-width:60px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                <div style="width:${p.pct_cumplimiento_citas || 0}%; height:100%; background:#22c55e;"></div>
              </div>
              <span style="font-size:0.75rem; font-weight:600;">${p.pct_cumplimiento_citas || 0}%</span>
            </div>
          </td>
        </tr>
      `).join('');

      WMS.setContent(`
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-star"></i> Evaluación de Proveedores — KPIs de Rendimiento</span></div>
          <div class="table-container">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Proveedor</th>
                  <th class="text-center">ODC Totales</th>
                  <th class="text-center">% Cumpl. ODC</th>
                  <th class="text-center">Recepciones</th>
                  <th class="text-center">Novedades</th>
                  <th class="text-center">⏱ Demora Atenc.</th>
                  <th class="text-center">🏗 Operación</th>
                  <th class="text-center">Cumpl. Citas</th>
                </tr>
              </thead>
              <tbody>${rows || '<tr><td colspan="8" class="table-empty">No hay datos de evaluación disponibles</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.toast('error', 'Error cargando Evaluación de Proveedores'); }
  },

  async show_audit(){WMS.setToolbar('');WMS.spinner();try{const r=await API.get('/reportes/audit-log','limit=200');const items=r.data||r||[];WMS.setContent(`<div class="filter-bar"><div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Filtrar log..." oninput="WMS_MODULES.reportes.filterTable(this.value,'audit-table')"></div></div><div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-scroll"></i> Log de Auditoría (${items.length})</span></div><div class="table-container"><table class="data-table" id="audit-table"><thead><tr><th>Fecha/Hora</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Detalle</th></tr></thead><tbody>${items.map(a=>`<tr><td class="text-sm">${WMS.formatDateTime(a.created_at)}</td><td>${WMS.esc(a.usuario||a.personal||'-')}</td><td><span class="badge badge-info">${WMS.esc(a.accion||a.tipo||'')}</span></td><td>${WMS.esc(a.modulo||'-')}</td><td class="truncate" style="max-width:250px;">${WMS.esc(a.descripcion||a.detalle||'-')}</td></tr>`).join('')||'<tr><td colspan="5" class="table-empty">Sin registros de auditoría</td></tr>'}</tbody></table></div></div>`);}catch(e){WMS.setContent('<div class="m-empty">Error de conexión</div>');}},

  async show_odc(){
    const d=document.getElementById('o-desde')?.value || '';
    const h=document.getElementById('o-hasta')?.value || '';
    const n=document.getElementById('o-num')?.value || '';
    WMS.setToolbar(`
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarODC('pallet')"><i class="fa-solid fa-file-excel"></i> Detalle por Pallet</button>
      <button class="btn btn-info btn-sm" onclick="WMS_MODULES.reportes.exportarODC('resumen')"><i class="fa-solid fa-file-excel"></i> Resumen ODC</button>
    `);
    WMS.spinner();
    try{
      const q = `fecha_inicio=${encodeURIComponent(d)}&fecha_fin=${encodeURIComponent(h)}&numero_odc=${encodeURIComponent(n)}`;
      const r = await API.get('/reportes/odc', q);
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Buscar en tabla..." oninput="WMS_MODULES.reportes.filterTable(this.value,'odc-table')"></div>
          <input type="text" class="form-control" id="o-num" placeholder="N° ODC" style="max-width:120px;" value="${n}">
          <input type="date" class="form-control" id="o-desde" style="max-width:150px;" value="${d}">
          <input type="date" class="form-control" id="o-hasta" style="max-width:150px;" value="${h}">
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes.show_odc()"><i class="fa-solid fa-search"></i> Filtrar</button>
        </div>
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-file-invoice"></i> Reporte Detallado de ODC (${items.length})</span></div>
        <div class="table-container"><table class="data-table" id="odc-table"><thead><tr><th>Fecha</th><th>N° ODC</th><th>Proveedor</th><th>Estado</th><th>Productos</th><th>Recibido</th></tr></thead>
          <tbody>${items.map(o=>`<tr>
            <td>${WMS.formatDate(o.created_at)}</td>
            <td><strong>${WMS.esc(o.numero_odc)}</strong></td>
            <td>${WMS.esc(o.proveedor?.razon_social||'-')}</td>
            <td><span class="status-chip status-${o.estado?.toLowerCase()}">${WMS.esc(o.estado)}</span></td>
            <td>${o.detalles?.length || 0} items</td>
            <td>${o.detalles?.reduce((acc,curr)=>acc+(parseFloat(curr.cantidad_recibida)||0),0)} un.</td>
          </tr>`).join('')||'<tr><td colspan="6" class="table-empty">Sin órdenes encontradas</td></tr>'}
          </tbody></table></div></div>`);
    }catch(e){WMS.setContent('<div class="m-empty">Error cargando reporte</div>');}
  },

  async exportarODC(group){
    const d=document.getElementById('o-desde')?.value || '';
    const h=document.getElementById('o-hasta')?.value || '';
    const n=document.getElementById('o-num')?.value || '';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/odc?export=excel&group=${group}&fecha_inicio=${encodeURIComponent(d)}&fecha_fin=${encodeURIComponent(h)}&numero_odc=${encodeURIComponent(n)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  exportarRecepciones(){
    const odc = document.getElementById('r-numero-odc')?.value.trim() || '';
    const proveedor = document.getElementById('r-proveedor')?.value.trim() || '';
    const fechaDesde = document.getElementById('r-fecha-inicio')?.value || '';
    const fechaHasta = document.getElementById('r-fecha-fin')?.value || '';
    const params = [];
    if (odc) params.push(`numero_odc=${encodeURIComponent(odc)}`);
    if (proveedor) params.push(`proveedor=${encodeURIComponent(proveedor)}`);
    if (fechaDesde) params.push(`fecha_inicio=${encodeURIComponent(fechaDesde)}`);
    if (fechaHasta) params.push(`fecha_fin=${encodeURIComponent(fechaHasta)}`);
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/recepciones?export=excel${params.length ? '&' + params.join('&') : ''}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── PLAN DE CONTINGENCIA (operación sin internet) ──────────────────────────
  async show_contingencia() {
    const today = new Date().toISOString().slice(0,10);
    WMS.setToolbar(`
      <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.reportes.abrirSeparacion()">
        <i class="fa-solid fa-print"></i> Imprimir Separación
      </button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.abrirCertificacion()">
        <i class="fa-solid fa-print"></i> Imprimir Certificación
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.reportes.exportarSeparacionCSV()">
        <i class="fa-solid fa-file-csv"></i> CSV Separación
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.reportes.exportarCertCSV()">
        <i class="fa-solid fa-file-csv"></i> CSV Certificación
      </button>`);
    WMS.setContent(`
      <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px">
        <div class="card" style="border-left:4px solid #f59e0b;padding:16px">
          <div style="font-size:1.2rem;font-weight:700;color:#92400e"><i class="fa-solid fa-triangle-exclamation"></i> Plan de Contingencia Sin Internet</div>
          <p style="color:#555;font-size:.9rem;margin:8px 0 0">Cuando no haya conectividad, imprima las planillas antes de iniciar operaciones. El sistema funciona localmente desde XAMPP: solo requiere el PC del admin encendido y conectado a la red local.</p>
        </div>
        <div class="card" style="border-left:4px solid #10b981;padding:16px; display:flex; align-items:center; gap:16px; position:relative;">
          <div style="background:#f1f5f9; padding:4px; border-radius:8px; flex-shrink:0;" id="contingencia-qr">
            <!-- QR Placeholder -->
            <div style="width:80px;height:80px;background:#e2e8f0;"></div>
          </div>
          <div style="flex:1; padding-right: 30px;">
            <button class="btn btn-sm btn-icon" onclick="WMS.updateConnectionInfo()" style="position:absolute; top:12px; right:12px; background:rgba(16,185,129,0.1); color:#065f46; border:none;" title="Refrescar IP y QR">
              <i class="fa-solid fa-sync"></i>
            </button>
            <div style="font-weight:700;color:#065f46; margin-bottom:4px;"><i class="fa-solid fa-network-wired"></i> Acceso Local XAMPP</div>
            <p style="color:#555;font-size:.9rem;margin:0 0 4px">Otros equipos en la red pueden acceder escaneando el código o ingresando a este enlace:</p>
            <div style="font-family:monospace; font-size:.85rem; background:#f1f5f9; padding:4px 8px; border-radius:4px; word-break:break-all;" id="contingencia-url">Cargando...</div>
            <div style="font-size:.8rem; color:#64748b; margin-top:4px;" id="contingencia-ip"><i class="fa-solid fa-circle-info"></i> IP: ---</div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-list"></i> Procedimiento Manual — Separación de Pedidos</span></div>
        <div style="padding:16px;font-size:.9rem">
          <div style="display:flex;gap:24px;flex-wrap:wrap">
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-1"></i> Antes del turno</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Admin/Supervisor imprime planilla de separación del día (<em>botón «Imprimir Separación»</em>)</li>
                <li>Se verifica el stock físico contra lo impreso</li>
                <li>Se asigna manualmente cada orden a un picker (anotar nombre en la columna «Operario»)</li>
                <li>El picker recibe su copia firmada por el supervisor</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-2"></i> Durante la operación</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Picker anota la cantidad alistada en la columna <strong>«Cant. Alistada ✓»</strong></li>
                <li>Si hay faltante: anota en «Observación» y avisa al supervisor</li>
                <li>Supervisor firma cada orden completada</li>
                <li>Planilla firmada = evidencia de trazabilidad</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-3"></i> Al recuperar internet</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Ingresar al sistema con la planilla impresa como guía</li>
                <li>Confirmar cada línea de picking en el módulo correspondiente</li>
                <li>Registrar faltantes en «Novedades de Stock»</li>
                <li>Archivar planillas firmadas (evidencia de auditoría)</li>
              </ol>
            </div>
          </div>
        </div>
      </div>
      <div class="card" style="margin-top:16px">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Procedimiento Manual — Certificación de Despacho</span></div>
        <div style="padding:16px;font-size:.9rem">
          <div style="display:flex;gap:24px;flex-wrap:wrap">
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-1"></i> Preparación</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Imprimir planilla de certificación (<em>botón «Imprimir Certificación»</em>)</li>
                <li>El certificador recibe planilla + mercancía en muelle de despacho</li>
                <li>Verificar placa del vehículo y datos del conductor</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-2"></i> Verificación línea a línea</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Contar físicamente cada producto vs. columna «Cant. Planilla»</li>
                <li>Anotar cantidad contada en «Cant. Certificada ✓»</li>
                <li>Diferencias → anotar en «Observación» y avisar a supervisor</li>
                <li>Certificador y supervisor firman el documento</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px"><i class="fa-solid fa-3"></i> Cierre de despacho</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Planilla firmada queda en bodega (archivo físico)</li>
                <li>Copia al conductor / transportador</li>
                <li>Al recuperar internet: registrar certificación en el sistema con las diferencias anotadas</li>
              </ol>
            </div>
          </div>
        </div>
      </div>
      <div class="card" style="margin-top:16px;border-left:4px solid #3b82f6">
        <div style="padding:14px">
          <strong><i class="fa-solid fa-database"></i> Backup de Base de Datos</strong>
          <p style="font-size:.85rem;color:#555;margin:6px 0 0">
            El backup automático se ejecuta diariamente a las 2:00 a.m. (si el PC está encendido) usando el Programador de Tareas de Windows.
            Guarda 30 archivos rotativos en <code>WMS_FENIX/backups/</code> — uno por cada día del mes.
            También puede ejecutarse manualmente desde la terminal del servidor:
            <code>C:\\xampp\\php\\php.exe scripts\\backup.php</code>
          </p>
        </div>
      </div>

      <div style="margin-top:16px">
        <label style="font-weight:600;font-size:.9rem">Fecha para planillas:</label>
        <input type="date" id="cont-fecha" value="${today}" style="margin-left:8px;padding:4px 8px;border:1px solid #ccc;border-radius:4px">
      </div>`);
    
    // Autopoblar info de conexión/QR
    WMS.updateConnectionInfo();
  },

  abrirSeparacion() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    const url = `${API_BASE}/reportes/contingencia/separacion?formato=html&fecha=${fecha}`;
    // Abrir con token en header — usar window.open + luego inyectar via fetch
    this._abrirReporteHtml(url, 'Separacion_' + fecha);
  },

  abrirCertificacion() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    const url = `${API_BASE}/reportes/contingencia/certificacion?formato=html&fecha=${fecha}`;
    this._abrirReporteHtml(url, 'Certificacion_' + fecha);
  },

  exportarSeparacionCSV() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    this.exportar(`/reportes/contingencia/separacion?formato=csv&fecha=${fecha}`);
  },

  exportarCertCSV() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    this.exportar(`/reportes/contingencia/certificacion?formato=csv&fecha=${fecha}`);
  },

  async _abrirReporteHtml(apiUrl, title) {
    try {
      const token = localStorage.getItem('wms_token') || '';
      // Ensure url is clean and has token for direct downloads if needed
      const sep = apiUrl.includes('?') ? '&' : '?';
      const urlWithToken = `${apiUrl}${sep}token=${encodeURIComponent(token)}`;
      
      const resp  = await fetch(urlWithToken, { 
        headers: { Authorization: 'Bearer ' + token } 
      });
      if (!resp.ok) { WMS.toast('error', 'Error al generar reporte'); return; }
      const html  = await resp.text();
      const win   = window.open('', title, 'width=1100,height=800');
      if (win) {
        win.document.write(html);
        win.document.close();
      } else {
        WMS.toast('warning', 'El navegador bloqueó la ventana emergente');
      }
    } catch(e) {
      WMS.toast('error', 'No se pudo abrir el reporte: ' + e.message);
    }
  },
};
