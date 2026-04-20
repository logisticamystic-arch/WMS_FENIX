  async show_gerencial() {
    WMS.setToolbar(`<button class="btn btn-sm btn-outline-secondary" onclick="WMS.nav('inteligencia','vencimientos')"><i class="fa-solid fa-brain"></i> Ver Inteligencia ML</button>`);
    WMS.spinner();

    // Recuperar filtros actuales si existen
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

    const { metrics, pickingPorCategoria, ventasMesAMes, bajaRotacion, mlForecast, filtros } = data;
    
    // Crear opciones
    const mesOpts = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
        .map((m,i)=>`<option value="${i+1}" ${fMes==(i+1)?'selected':''}>${m}</option>`).join('');
    const catOpts = \`<option value="">Todas las Categorías</option>\` + (filtros?.categorias||[]).map(c=>`<option value="${c.id}" ${fCat==c.id?'selected':''}>${WMS.esc(c.nombre)}</option>`).join('');
    const prodOpts = \`<option value="">Todos los Productos</option>\` + (filtros?.productos||[]).map(p=>`<option value="${p.id}" ${fProd==p.id?'selected':''}>${WMS.esc(p.codigo_interno)} - ${WMS.esc(p.nombre)}</option>`).join('');

    WMS.setContent(`
      <div class="container-fluid py-3" style="background:#f8fafc; min-height:100vh;">
        <!-- Header BI -->
        <div class="d-flex align-items-center justify-content-between mb-3 bg-white p-3 rounded shadow-sm">
          <div>
            <h5 class="mb-0 fw-700" style="color:#1e293b;"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Dashboard Analítico Gerencial ML</h5>
            <small class="text-muted">Desempeño interactivo y proyecciones IA</small>
          </div>
          <div class="d-flex gap-2">
            <select id="bi-mes" class="form-select form-select-sm" style="width:130px" onchange="WMS_MODULES.reportes.show_gerencial()">${mesOpts}</select>
            <select id="bi-categoria" class="form-select form-select-sm" style="width:180px" onchange="WMS_MODULES.reportes.show_gerencial()">${catOpts}</select>
            <select id="bi-producto" class="form-select form-select-sm" style="width:200px" onchange="WMS_MODULES.reportes.show_gerencial()">${prodOpts}</select>
          </div>
        </div>

        <!-- Fila 1: C-Level KPIs -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
          <div class="card border-0 shadow-sm px-4 py-3" style="border-left: 4px solid #10b981;">
            <div class="text-muted" style="font-size:0.75rem;font-weight:700;text-transform:uppercase">Total Picks (Mes Filtrado)</div>
            <div style="font-size:2.2rem;font-weight:800;color:#0f172a">${metrics.totalPicksMes ? Number(metrics.totalPicksMes).toLocaleString('es-CO') : 0}</div>
          </div>
          <div class="card border-0 shadow-sm px-4 py-3" style="border-left: 4px solid ${metrics.crecimientoPct>=0?'#3b82f6':'#ef4444'};">
            <div class="text-muted" style="font-size:0.75rem;font-weight:700;text-transform:uppercase">Crecimiento / Variación MoM</div>
            <div style="font-size:2.2rem;font-weight:800;color:${metrics.crecimientoPct>=0?'#3b82f6':'#ef4444'}">
              ${metrics.crecimientoPct>0?'+':''}${metrics.crecimientoPct}%
            </div>
          </div>
          <div class="card border-0 shadow-sm px-4 py-3" style="border-left: 4px solid #f59e0b;">
            <div class="text-muted" style="font-size:0.75rem;font-weight:700;text-transform:uppercase">Items en Baja Rotación (>90d)</div>
            <div style="font-size:2.2rem;font-weight:800;color:#b45309">${metrics.bajaRotacionCount||0}</div>
          </div>
        </div>

        <!-- Fila 2: Forecast ML y Dona de Categorias -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
          <div class="card border-0 shadow-sm">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold"><i class="fa-solid fa-chart-line text-primary me-2"></i>Pronóstico Venta IA vs Real (Año)</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartBiForecast"></canvas>
             </div>
          </div>
          <div class="card border-0 shadow-sm">
             <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold"><i class="fa-solid fa-layer-group text-success me-2"></i>Total Picking por Categoría</span>
             </div>
             <div class="card-body" style="height:300px;position:relative;">
                <canvas id="chartBiCategorias"></canvas>
             </div>
          </div>
        </div>

        <!-- Fila 3: Crecimiento Matriz y Baja Rotacion -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 1rem;">
           <div class="card border-0 shadow-sm">
              <div class="card-header bg-white py-3 border-0">
                <span class="fw-bold"><i class="fa-solid fa-battery-quarter text-warning me-2"></i>Nivel Crítico: Baja Rotación</span>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.8rem">
                    <thead class="table-light"><tr><th>Cód</th><th>Producto</th><th>Categoría</th><th class="text-end">Stock Inmovilizado</th></tr></thead>
                    <tbody>
                      ${(bajaRotacion||[]).map(b=>`<tr>
                        <td class="text-muted fw-bold">${WMS.esc(b.codigo_interno)}</td>
                        <td class="text-truncate" style="max-width:180px">${WMS.esc(b.producto)}</td>
                        <td>${WMS.esc(b.categoria||'Sin Cat')}</td>
                        <td class="text-end text-danger fw-bold">${Number(b.stock_inmovilizado||0).toLocaleString('es-CO')}</td>
                      </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted py-4">Inventario Saludable - Sin inmovilizados</td></tr>'}
                    </tbody>
                  </table>
                </div>
              </div>
           </div>
        </div>
      </div>
    `);

    // Inits Charts
    setTimeout(() => {
        // Line chart ML Forecast
        const ctxFore = document.getElementById('chartBiForecast');
        if (ctxFore && window.Chart) {
            new Chart(ctxFore, {
                type: 'line',
                data: {
                    labels: ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
                    datasets: [
                        {
                            label: 'Datos Reales',
                            data: mlForecast.reales,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3
                        },
                        {
                            label: 'IA Forecast (Proyección)',
                            data: mlForecast.forecast,
                            borderColor: '#10b981',
                            borderDash: [5, 5],
                            borderWidth: 2,
                            tension: 0.3,
                            fill: false
                        }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { mode: 'index', intersect: false } } }
            });
        }

        // Doughnut Chart Categorias
        const ctxCat = document.getElementById('chartBiCategorias');
        if (ctxCat && window.Chart) {
            const catLabels = (pickingPorCategoria||[]).map(i=>i.categoria||'Sin Categoría');
            const catData = (pickingPorCategoria||[]).map(i=>i.total);
            new Chart(ctxCat, {
                type: 'doughnut',
                data: {
                    labels: catLabels.length ? catLabels : ['Sin Datos'],
                    datasets: [{
                        data: catData.length ? catData : [1],
                        backgroundColor: catData.length ? ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4'] : ['#e2e8f0'],
                        borderWidth: 0
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }
                    },
                    cutout: '70%'
                }
            });
        }
    }, 200);
  },
