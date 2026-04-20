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
      WMS.setContent('<div class="m-empty"><p>Error cargando data BI</p></div>');
      return;
    }

    const { metrics, pickingPorCategoria, ventasMesAMes, tendenciaCat, bajaRotacion, mlForecast, filtros } = data;
    
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
