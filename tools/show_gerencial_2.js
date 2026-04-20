  async show_gerencial() {
    WMS.setToolbar(`<button class="btn btn-sm btn-outline-secondary" onclick="WMS.nav('inteligencia','vencimientos')"><i class="fa-solid fa-brain"></i> Análisis ML Predictivo</button>`);
    WMS.spinner();

    // Recuperar filtros actuales
    const fMes = document.getElementById('bi-mes') ? document.getElementById('bi-mes').value : new Date().getMonth() + 1;
    const fCat = document.getElementById('bi-categoria') ? document.getElementById('bi-categoria').value : '';
    const fProd = document.getElementById('bi-producto') ? document.getElementById('bi-producto').value : '';

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
      <div class="container-fluid py-3" style="background:#f8fafc; min-height:100vh;">
        
        <!-- HEADER / CONTROL DE FILTROS (DISEÑO WMS CLÁSICO) -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 8px;">
          <div class="card-body py-3">
            <h6 class="mb-3 fw-bold" style="color:#1e293b; font-size: 0.85rem; letter-spacing: 0.5px;">
              <i class="fa-solid fa-sliders text-primary me-2"></i> CONTROL DE FILTROS BI
            </h6>
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label" style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-bottom: 0.2rem;">Mes</label>
                <select id="bi-mes" class="form-select form-select-sm shadow-none w-100">${mesOpts}</select>
              </div>
              <div class="col-md-3">
                <label class="form-label" style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-bottom: 0.2rem;">Categoría</label>
                <select id="bi-categoria" class="form-select form-select-sm shadow-none w-100">${catOpts}</select>
              </div>
              <div class="col-md-4">
                <label class="form-label" style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-bottom: 0.2rem;">Producto</label>
                <div style="width:100%">
                  <select id="bi-producto" class="form-select form-select-sm select2-bi shadow-none w-100">${prodOpts}</select>
                </div>
              </div>
              <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-sm btn-primary w-100" onclick="WMS_MODULES.reportes.show_gerencial()">
                  <i class="fa-solid fa-rotate-right me-1"></i> Filtrar
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Fila 1: KPIs Estilo Tarjetas WMS Recepción -->
        <div class="row g-3 mb-4">
          <!-- Total Picks -->
          <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 8px; border-bottom: 4px solid #1a56db !important;">
              <div class="card-body d-flex align-items-center px-3 py-3">
                <div class="d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: #eff6ff; border-radius: 12px; margin-right: 15px;">
                  <i class="fa-solid fa-boxes-stacked" style="color: #1a56db; font-size: 1.5rem;"></i>
                </div>
                <div>
                  <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">UNIDADES SEPARADAS</div>
                  <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1.1;">${metrics.totalPicksMes ? Number(metrics.totalPicksMes).toLocaleString('es-CO') : 0}</div>
                  <div style="font-size: 0.7rem; color: #94a3b8;">Volumen total en el mes</div>
                </div>
              </div>
            </div>
          </div>
          <!-- Variación -->
          <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 8px; border-bottom: 4px solid ${metrics.crecimientoPct>=0?'#10b981':'#ef4444'} !important;">
              <div class="card-body d-flex align-items-center px-3 py-3">
                <div class="d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: ${metrics.crecimientoPct>=0?'#ecfdf5':'#fef2f2'}; border-radius: 12px; margin-right: 15px;">
                  <i class="fa-solid ${metrics.crecimientoPct>=0?'fa-arrow-trend-up':'fa-arrow-trend-down'}" style="color: ${metrics.crecimientoPct>=0?'#10b981':'#ef4444'}; font-size: 1.5rem;"></i>
                </div>
                <div>
                  <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">VARIACIÓN M.O.M</div>
                  <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1.1;">${metrics.crecimientoPct>0?'+':''}${metrics.crecimientoPct}%</div>
                  <div style="font-size: 0.7rem; color: #94a3b8;">Crecimiento / Caída</div>
                </div>
              </div>
            </div>
          </div>
          <!-- Baja Rotación -->
          <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 8px; border-bottom: 4px solid #f59e0b !important;">
              <div class="card-body d-flex align-items-center px-3 py-3">
                <div class="d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; background-color: #fffbeb; border-radius: 12px; margin-right: 15px;">
                  <i class="fa-solid fa-boxes-packing" style="color: #f59e0b; font-size: 1.5rem;"></i>
                </div>
                <div>
                  <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase;">BAJA ROTACIÓN</div>
                  <div style="font-size: 1.8rem; font-weight: 800; color: #0f172a; line-height: 1.1;">${metrics.bajaRotacionCount||0}</div>
                  <div style="font-size: 0.7rem; color: #94a3b8;">Productos inmovilizados > 90d</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Fila 2: Total Unidades Separadas por Mes y Picking por Categoría -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem;">
          <div class="card border-0 shadow-sm" style="border-radius: 8px;">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold" style="color:#1e293b;"><i class="fa-regular fa-calendar-check text-primary me-2"></i>Total Unidades Separadas Por Mes</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartGeneralPicks"></canvas>
             </div>
          </div>

          <div class="card border-0 shadow-sm" style="border-radius: 8px;">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold" style="color:#1e293b;"><i class="fa-solid fa-layer-group text-info me-2"></i>Picking Volumétrico por Categoría (Barras)</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartBiCategoriasBars"></canvas>
             </div>
          </div>
        </div>

        <!-- Fila 3: Tendencia Mensual y Forecast ML -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
          <div class="card border-0 shadow-sm" style="border-radius: 8px;">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold" style="color:#1e293b;"><i class="fa-solid fa-chart-area text-secondary me-2"></i>Tendencia Mensual Picking Por Categoría</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartTendenciaCat"></canvas>
             </div>
          </div>

          <div class="card border-0 shadow-sm" style="border-radius: 8px;">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold" style="color:#1e293b;"><i class="fa-solid fa-brain text-success me-2"></i>Forecast ML: Cierre de Año</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartBiForecast"></canvas>
             </div>
          </div>
        </div>

        <!-- Fila 4: Matriz Cero Rotación -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 8px;">
           <div class="card-header bg-white py-3 border-0">
             <span class="fw-bold" style="color:#1e293b;"><i class="fa-solid fa-battery-quarter text-warning me-2"></i>Alerta: Stock Inmovilizado y Baja Rotación</span>
           </div>
           <div class="card-body p-0">
             <div class="table-responsive">
               <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.85rem">
                 <thead class="table-light"><tr><th class="ps-3">Cód</th><th>Producto</th><th>Categoría</th><th class="text-end pe-3">Stock Inmovilizado</th></tr></thead>
                 <tbody>
                   ${(bajaRotacion||[]).map(b=>`<tr>
                     <td class="text-muted fw-bold ps-3">${WMS.esc(b.codigo_interno)}</td>
                     <td>${WMS.esc(b.producto)}</td>
                     <td>${WMS.esc(b.categoria||'Sin Cat')}</td>
                     <td class="text-end text-danger fw-bold pe-3">${Number(b.stock_inmovilizado||0).toLocaleString('es-CO')}</td>
                   </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted py-4">Inventario Saludable - Sin inmovilizados</td></tr>'}
                 </tbody>
               </table>
             </div>
           </div>
        </div>
      </div>
    `);

    // Inits Charts y UI Plugins
    setTimeout(() => {
        
        // 1. Init Select2 en el cuerpo del documento asegurandose que se adhiere al modal o vista si es necesario.
        // Se desvincula primero para prevenir colisiones, y se inicia sobre el elemento estricto.
        if (window.jQuery && $.fn.select2) {
            const $prod = $('#bi-producto');
            if ($prod.data('select2')) {
                $prod.select2('destroy');
            }
            $prod.select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Buscar SKU...',
                allowClear: true
            });
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
