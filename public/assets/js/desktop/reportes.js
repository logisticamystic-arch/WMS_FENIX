/* ============================================================
   WMS Desktop - Módulo REPORTES
   ============================================================ */
WMS_MODULES.reportes = {
  load(sub) {
    WMS.setBreadcrumb('reportes', this.subLabel(sub));
    WMS.renderSidebar('reportes');
    const s = sub || 'gerencial';
    const fn = {
      gerencial:    this.show_gerencial,
      kardex:       this.show_kardex,
      recepciones:  this.show_recepciones,
      despachos:    this.show_despachos,
      picking:      this.show_picking,
      devoluciones: this.show_devoluciones,
      proveedores:  this.show_proveedores,
      audit:        this.show_audit,
      odc:          this.show_odc,
      contingencia: this.show_contingencia,
      agotados:     this.show_agotados,
    };
    (fn[s]?.bind(this) || fn.gerencial.bind(this))();
  },

  subLabel(s) {
    const m = {
      gerencial:'Dashboard Gerencial', kardex:'Kardex', recepciones:'Recepciones',
      despachos:'Despachos', picking:'Picking', devoluciones:'Devoluciones',
      proveedores:'Evaluación Proveedores', audit:'Log de Auditoría',
      odc:'Recibo Detallado (ODC)', contingencia:'Plan de Contingencia',
      agotados:'Agotados por Demanda',
    };
    return m[s] || s || 'Panel';
  },

  // ── Botón exportar CSV genérico ───────────────────────────────────────────
  exportBtn(endpoint, label) {
    return `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportar('${endpoint}')"><i class="fa-solid fa-file-csv"></i> ${label}</button>`;
  },

  async exportar(endpoint) {
    WMS.toast('info', 'Generando exportación...');
    try {
      const sep   = endpoint.includes('?') ? '&' : '?';
      const token = localStorage.getItem('wms_token') || '';
      const url   = `${API_BASE}${endpoint}${sep}export=excel&token=${encodeURIComponent(token)}`;
      window.open(url, '_blank');
    } catch(e) { WMS.toast('error', 'Error generando reporte'); }
  },

  // ── Helper: autocomplete de ubicación con debounce 350 ms ────────────────
  _ubicDebounce: {},

  initUbicacionAutocomplete(inputId, hiddenIdField, hiddenCodigoField) {
    const input = document.getElementById(inputId);
    if (!input) return;

    // Crear contenedor dropdown
    let dd = document.getElementById(inputId + '-dd');
    if (!dd) {
      dd = document.createElement('div');
      dd.id = inputId + '-dd';
      dd.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #cbd5e1;border-radius:4px;max-height:180px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;';
      input.parentElement.style.position = 'relative';
      input.parentElement.appendChild(dd);
    }

    input.addEventListener('input', () => {
      clearTimeout(this._ubicDebounce[inputId]);
      const val = input.value.trim();
      if (val.length < 2) { dd.style.display = 'none'; return; }

      this._ubicDebounce[inputId] = setTimeout(async () => {
        try {
          const res  = await API.get('/param/ubicaciones', `codigo=${encodeURIComponent(val)}&limit=10`);
          const list = res.data || res || [];
          if (!list.length) { dd.innerHTML = '<div style="padding:8px 12px;color:#94a3b8;font-size:.82rem;">Sin resultados</div>'; dd.style.display = 'block'; return; }
          dd.innerHTML = list.map(u =>
            `<div data-id="${u.id}" data-codigo="${WMS.esc(u.codigo)}"
                  style="padding:8px 12px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f1f5f9;"
                  onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''"
                  onclick="WMS_MODULES.reportes._selectUbicacion('${inputId}','${hiddenIdField}','${hiddenCodigoField}',${u.id},'${WMS.esc(u.codigo)}')">
              <b>${WMS.esc(u.codigo)}</b>${u.nombre ? ' — ' + WMS.esc(u.nombre) : ''}
            </div>`
          ).join('');
          dd.style.display = 'block';
        } catch(_) { dd.style.display = 'none'; }
      }, 350);
    });

    // Cerrar dropdown al hacer click fuera
    document.addEventListener('click', (e) => {
      if (!input.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
    });
  },

  _selectUbicacion(inputId, hiddenIdField, hiddenCodigoField, id, codigo) {
    const input = document.getElementById(inputId);
    if (input) input.value = codigo;
    const hId  = document.getElementById(hiddenIdField);
    if (hId)  hId.value = id;
    const hCod = document.getElementById(hiddenCodigoField);
    if (hCod) hCod.value = codigo;
    const dd = document.getElementById(inputId + '-dd');
    if (dd) dd.style.display = 'none';
  },

  // ── Filtros comunes HTML ──────────────────────────────────────────────────
  _filtroBarra({id='', desde='', hasta='', referencia='', ubicacion='', extra='', onFiltrar='', labelFiltrar='Filtrar'} = {}) {
    return `
      <div class="filter-bar" style="flex-wrap:wrap;gap:8px;">
        <div class="search-bar">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input id="${id}-search" placeholder="Buscar en tabla..." oninput="WMS_MODULES.reportes.filterTable(this.value,'${id}-table')">
        </div>
        <input type="date" class="form-control" id="${id}-desde" style="max-width:148px;" value="${desde}" title="Fecha desde">
        <input type="date" class="form-control" id="${id}-hasta" style="max-width:148px;" value="${hasta}" title="Fecha hasta">
        <input type="text"  class="form-control" id="${id}-ref" placeholder="Referencia / EAN" style="max-width:160px;" value="${referencia}">
        <div style="position:relative;max-width:160px;">
          <input type="text" class="form-control" id="${id}-ubic-input" placeholder="Ubicación" style="width:160px;" value="${ubicacion}">
          <input type="hidden" id="${id}-ubic-id">
          <input type="hidden" id="${id}-ubic-codigo">
        </div>
        ${extra}
        <button class="btn btn-primary btn-sm" onclick="${onFiltrar}">
          <i class="fa-solid fa-search"></i> ${labelFiltrar}
        </button>
      </div>`;
  },

  _getParams(id) {
    const hoy    = new Date().toISOString().substring(0,10);
    const hace30 = new Date(Date.now() - 30*86400000).toISOString().substring(0,10);
    return {
      desde:     document.getElementById(`${id}-desde`)?.value     || hace30,
      hasta:     document.getElementById(`${id}-hasta`)?.value     || hoy,
      ref:       document.getElementById(`${id}-ref`)?.value.trim() || '',
      ubicCodigo: document.getElementById(`${id}-ubic-codigo`)?.value || document.getElementById(`${id}-ubic-input`)?.value || '',
    };
  },

  // ── Gate "no cargar hasta filtrar": los reportes no consultan el backend
  // hasta que el usuario presiona Filtrar/Buscar la primera vez. ────────────
  _buscadoMap: {},
  _buscar(id, fnName) {
    this._buscadoMap[id] = true;
    this[fnName]();
  },
  _estadoInicialReporte(msg) {
    return `<div class="m-empty" style="padding:40px;"><i class="fa-solid fa-filter"></i><p>${msg || 'Aplique los filtros deseados y presione "Filtrar" para consultar el reporte'}</p></div>`;
  },

  // ── DASHBOARD GERENCIAL ───────────────────────────────────────────────────
  async show_gerencial() {
    WMS.setToolbar(`<button class="btn btn-sm btn-outline-secondary" onclick="WMS.nav('inteligencia','vencimientos')"><i class="fa-solid fa-brain"></i> Análisis ML Predictivo</button>`);
    WMS.spinner();

    const fMes  = document.getElementById('dash-filter-mes')       ? document.getElementById('dash-filter-mes').value       : new Date().getMonth() + 1;
    const fCat  = document.getElementById('dash-filter-categoria')  ? document.getElementById('dash-filter-categoria').value  : '';
    const fProd = document.getElementById('dash-filter-producto')   ? document.getElementById('dash-filter-producto').value   : '';

    let data = {};
    try {
      const qs = new URLSearchParams({ mes: fMes, categoria: fCat, producto: fProd });
      const rs = await API.get('/reportes/dashboard-bi', qs.toString());
      data = rs.data || rs || {};
    } catch(e) { console.error('Error Dashboard BI:', e); }

    let metrics            = data.metrics            || {},
        pickingPorCategoria = data.pickingPorCategoria || [],
        ventasMesAMes       = data.ventasMesAMes       || [],
        tendenciaCat        = data.tendenciaCat        || [],
        bajaRotacion        = data.bajaRotacion        || [],
        mlForecast          = data.mlForecast          || {reales:[], forecast:[]},
        filtros             = data.filtros             || {};

    const mesOpts  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
        .map((m,i) => `<option value="${i+1}" ${fMes==(i+1)?'selected':''}>${m}</option>`).join('');
    const catOpts  = `<option value="">Todos</option>` + (filtros?.categorias||[]).map(c => `<option value="${c.id}" ${fCat==c.id?'selected':''}>${WMS.esc(c.nombre)}</option>`).join('');
    const prodOpts = `<option value="">Todos</option>` + (filtros?.productos||[]).map(p => `<option value="${p.id}" ${fProd==p.id?'selected':''}>${WMS.esc(p.codigo_interno)} - ${WMS.esc(p.nombre)}</option>`).join('');

    const primaryColors = ['#1a56db','#0891b2','#4f46e5','#2563eb','#0284c7','#475569'];

    WMS.setContent(`
      <div class="inv-commander-root animate-fade-in" style="padding:20px;background:#f8fafc;min-height:calc(100vh - 120px);overflow:auto;">

        <div style="background:#fff;border-radius:4px;padding:20px;border:1px solid #e2e8f0;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
          <div style="font-weight:900;color:#0f172a;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;">
            <i class="fa-solid fa-sliders" style="color:var(--cmd-blue,#2563eb);"></i> Control de Filtros
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
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

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-bottom:24px;">
          <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.05);min-height:350px;">
            <div style="font-weight:900;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;">
              <i class="fa-regular fa-calendar-check" style="color:var(--cmd-blue,#2563eb);"></i> Total Unidades Separadas Por Mes
            </div>
            <div style="position:relative;height:280px;"><canvas id="chartGeneralPicks"></canvas></div>
          </div>
          <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.05);min-height:350px;">
            <div style="font-weight:900;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;">
              <i class="fa-solid fa-layer-group" style="color:#0891b2;"></i> Picking Volumétrico por Categoría
            </div>
            <div style="position:relative;height:280px;"><canvas id="chartBiCategoriasBars"></canvas></div>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:20px;margin-bottom:24px;">
          <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.05);min-height:350px;">
            <div style="font-weight:900;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;">
              <i class="fa-solid fa-chart-area" style="color:#475569;"></i> Tendencia Mensual Picking Por Categoría
            </div>
            <div style="position:relative;height:280px;"><canvas id="chartTendenciaCat"></canvas></div>
          </div>
          <div style="background:#fff;border-radius:4px;padding:24px;border:1px solid #e2e8f0;border-top:4px solid #059669;box-shadow:0 1px 3px rgba(0,0,0,.05);min-height:350px;">
            <div style="font-weight:900;color:#0f172a;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:.9rem;text-transform:uppercase;letter-spacing:.5px;">
              <i class="fa-solid fa-brain" style="color:#059669;"></i> Forecast ML: Cierre de Año
            </div>
            <div style="position:relative;height:280px;"><canvas id="chartBiForecast"></canvas></div>
          </div>
        </div>

        <div style="background:#fff;border-radius:4px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.06);">
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
                ${(bajaRotacion||[]).map((b,i) => `<tr style="border-bottom:1px solid #f1f5f9;background:${i%2?'#f8fafc':'#fff'};">
                  <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#64748b;font-weight:bold;">${WMS.esc(b.codigo_interno)}</td>
                  <td style="padding:8px 12px;font-weight:600;color:#1e3a5f;">${WMS.esc(b.producto)}</td>
                  <td style="padding:8px 12px;">${WMS.esc(b.categoria||'Sin Cat')}</td>
                  <td style="padding:8px 12px;text-align:right;font-weight:700;color:#dc2626;">${Number(b.stock_inmovilizado||0).toLocaleString('es-CO')}</td>
                </tr>`).join('') || '<tr><td colspan="4" style="text-align:center;padding:24px;color:#94a3b8;">Inventario Saludable - Sin inmovilizados</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>

      </div>
    `);

    setTimeout(() => {
      if (window.jQuery && $.fn.select2) {
        const $prod = $('#dash-filter-producto');
        if ($prod.data('select2')) $prod.select2('destroy');
        $prod.select2({ theme:'bootstrap-5', width:'100%', placeholder:'Buscar ODC o SKU...', allowClear:true })
             .on('change', () => { WMS_MODULES.reportes.show_gerencial(); });
      }

      const labelsMeses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

      const ctxGen = document.getElementById('chartGeneralPicks');
      if (ctxGen && window.Chart) {
        new Chart(ctxGen, {
          type:'bar',
          data:{ labels:labelsMeses, datasets:[{ label:'Unidades', data:mlForecast.reales, backgroundColor:'#1a56db', borderRadius:4 }] },
          options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,grid:{borderDash:[2,4]}},x:{grid:{display:false}}} }
        });
      }

      const ctxCat = document.getElementById('chartBiCategoriasBars');
      if (ctxCat && window.Chart) {
        const catLabels = (pickingPorCategoria||[]).map(i => i.categoria||'Sin Categoría');
        const catData   = (pickingPorCategoria||[]).map(i => i.total);
        new Chart(ctxCat, {
          type:'bar',
          data:{ labels:catLabels.length?catLabels:['Sin Datos'], datasets:[{ label:'Volumen', data:catData.length?catData:[1], backgroundColor:'#0891b2', borderRadius:4 }] },
          options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{grid:{borderDash:[2,4]}},y:{grid:{display:false}}} }
        });
      }

      const ctxTend = document.getElementById('chartTendenciaCat');
      if (ctxTend && window.Chart) {
        const datasets = (tendenciaCat||[]).map((cat, idx) => ({
          label: cat.categoria, data: cat.data,
          borderColor: primaryColors[idx % primaryColors.length],
          backgroundColor: primaryColors[idx % primaryColors.length] + '20',
          borderWidth:2, tension:0.3, fill:false
        }));
        new Chart(ctxTend, {
          type:'line',
          data:{ labels:labelsMeses, datasets },
          options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom',labels:{boxWidth:12,usePointStyle:true,font:{size:10}}}}, scales:{y:{grid:{borderDash:[2,4]}},x:{grid:{display:false}}} }
        });
      }

      const ctxFore = document.getElementById('chartBiForecast');
      if (ctxFore && window.Chart) {
        new Chart(ctxFore, {
          type:'line',
          data:{
            labels:labelsMeses,
            datasets:[
              { label:'Datos Reales', data:mlForecast.reales, borderColor:'#475569', backgroundColor:'rgba(71,85,105,.1)', borderWidth:2, fill:true, tension:0.2 },
              { label:'Proyección IA', data:mlForecast.forecast, borderColor:'#059669', borderDash:[5,5], borderWidth:2, tension:0.2, fill:false }
            ]
          },
          options:{ responsive:true, maintainAspectRatio:false, plugins:{tooltip:{mode:'index',intersect:false}}, scales:{y:{grid:{borderDash:[2,4]}},x:{grid:{display:false}}} }
        });
      }
    }, 250);
  },

  // ── KARDEX ────────────────────────────────────────────────────────────────
  // ── KARDEX (búsqueda dinámica por producto + rango de fechas) ──────────────
  async show_kardex() {
    WMS.setToolbar('');
    const hoy = new Date().toISOString().substring(0,10);
    const hace30 = new Date(Date.now() - 30*86400000).toISOString().substring(0,10);
    const prodId = this._kProdId || '';
    const prodNom = this._kProdNombre || '';
    const desde = this._kDesde || hace30;
    const hasta = this._kHasta || hoy;

    WMS.setContent(`
      <div class="filter-bar" style="flex-wrap:wrap;gap:8px;align-items:flex-end;">
        <div class="form-group" style="margin:0;min-width:220px;">
          <label class="form-label" style="font-size:.7rem;">Producto <span class="required">*</span></label>
          <input type="text" id="k-prod-ac" class="form-control" placeholder="Escriba EAN, código o nombre..." autocomplete="off" value="${WMS.esc(prodNom)}">
          <input type="hidden" id="k-prod-id" value="${prodId}">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Desde</label>
          <input type="date" id="k-desde" class="form-control form-control-sm" value="${desde}" style="width:150px">
        </div>
        <div class="form-group" style="margin:0;">
          <label class="form-label" style="font-size:.7rem;">Hasta</label>
          <input type="date" id="k-hasta" class="form-control form-control-sm" value="${hasta}" style="width:150px">
        </div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes._kBuscar()"><i class="fa-solid fa-search"></i> Buscar</button>
        <button class="btn btn-success btn-sm" id="k-btn-export" onclick="WMS_MODULES.reportes._kExportar()" ${prodId?'':'disabled'}><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
      </div>

      <div class="pro-kpi-grid mb-4" id="k-kpis" style="display:none;margin-top:14px;">
        <div class="pro-kpi-card accent-blue"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-arrow-down"></i></div></div><div class="pro-kpi-value" id="k-kpi-entradas">0</div><div class="pro-kpi-label">Entradas</div></div>
        <div class="pro-kpi-card accent-amber"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-arrow-up"></i></div></div><div class="pro-kpi-value" id="k-kpi-salidas">0</div><div class="pro-kpi-label">Salidas</div></div>
        <div class="pro-kpi-card accent-green"><div class="pro-kpi-header"><div class="pro-kpi-icon"><i class="fa-solid fa-scale-balanced"></i></div></div><div class="pro-kpi-value" id="k-kpi-saldo">0</div><div class="pro-kpi-label">Saldo Final</div></div>
      </div>

      <div class="card mt-16">
        <div class="card-header"><span class="card-title" id="k-count"><i class="fa-solid fa-file-invoice"></i> Movimientos de Kardex</span></div>
        <div class="table-container" id="k-table-wrap">
          <div class="m-empty" style="padding:40px;"><i class="fa-solid fa-magnifying-glass"></i><p>Busque un producto para ver su Kardex</p></div>
        </div>
      </div>`);

    setTimeout(() => {
      const inp = document.getElementById('k-prod-ac');
      if (inp) {
        WMS.initProductAutocomplete(inp, (p) => {
          document.getElementById('k-prod-id').value = p.id;
          this._kProdId = p.id;
          this._kProdNombre = p.descripcion || p.nombre;
          const btn = document.getElementById('k-btn-export');
          if (btn) btn.disabled = false;
          this._kBuscar();
        });
      }
    }, 150);

    if (prodId) this._kBuscar();
  },

  async _kBuscar() {
    const prodId = document.getElementById('k-prod-id')?.value;
    const desde  = document.getElementById('k-desde')?.value;
    const hasta  = document.getElementById('k-hasta')?.value;
    this._kDesde = desde; this._kHasta = hasta;
    const wrap = document.getElementById('k-table-wrap');
    if (!prodId) { WMS.toast('warning', 'Seleccione un producto para consultar su Kardex'); return; }
    if (wrap) wrap.innerHTML = '<div class="spinner sm" style="margin:24px auto;display:block;"></div>';
    try {
      const qs = `producto_id=${prodId}&fecha_inicio=${desde}&fecha_fin=${hasta}`;
      const r  = await API.get('/v2/inventario/kardex', qs);
      const d  = r.data || {};
      const movs = d.movimientos || [];

      const kpis = document.getElementById('k-kpis');
      if (kpis) kpis.style.display = 'grid';
      document.getElementById('k-kpi-entradas').textContent = WMS.formatNum(d.total_entradas || 0);
      document.getElementById('k-kpi-salidas').textContent  = WMS.formatNum(d.total_salidas || 0);
      document.getElementById('k-kpi-saldo').textContent    = WMS.formatNum(d.saldo_final || 0);

      const countEl = document.getElementById('k-count');
      if (countEl) countEl.innerHTML = `<i class="fa-solid fa-file-invoice"></i> Movimientos de Kardex (${movs.length})`;

      const tipoBadge = t => {
        const m = { Entrada:'badge-success', AjustePositivo:'badge-success', Devolucion:'badge-success', Reabastecimiento:'badge-success',
                    Salida:'badge-danger', AjusteNegativo:'badge-danger', Picking:'badge-danger', Traslado:'badge-info' };
        return `<span class="badge ${m[t]||'badge-secondary'}">${WMS.esc(t)}</span>`;
      };

      if (wrap) wrap.innerHTML = `
        <table class="erp-table" id="k-table">
          <thead><tr>
            <th>Fecha</th><th>Hora</th><th>Tipo</th><th>Sucursal Pedido</th>
            <th class="text-center">Entradas</th><th class="text-center">Salidas</th>
            <th class="text-center">Cajas</th><th class="text-center">Saldos</th><th class="text-center">UND/TOTAL</th>
            <th class="text-center">Saldo Ant.</th><th class="text-center">Saldo Acum.</th>
            <th>Lote / Venc.</th><th>Origen</th><th>Destino</th><th>Usuario</th><th>Observaciones</th>
          </tr></thead>
          <tbody>${movs.map(m => `<tr>
            <td>${WMS.formatDate(m.fecha)}</td>
            <td><small>${(m.hora||'').substring(0,5)}</small></td>
            <td>${tipoBadge(m.tipo)}</td>
            <td>${WMS.esc(m.sucursal_pedido || '—')}</td>
            <td class="text-center" style="color:#10b981;font-weight:700">${m.entradas ? WMS.formatNum(m.entradas) : '—'}</td>
            <td class="text-center" style="color:#ef4444;font-weight:700">${m.salidas ? WMS.formatNum(m.salidas) : '—'}</td>
            <td class="text-center">${m.cantidad_cajas ?? '—'}</td>
            <td class="text-center">${m.saldos ?? '—'}</td>
            <td class="text-center"><b>${WMS.formatNum(m.cantidad)}</b></td>
            <td class="text-center" style="color:#64748b">${WMS.formatNum(m.saldo_anterior ?? 0)}</td>
            <td class="text-center" style="font-weight:700">${WMS.formatNum(m.saldo)}</td>
            <td><small>${WMS.esc(m.lote||'-')}${m.fecha_vencimiento?' · '+WMS.formatDate(m.fecha_vencimiento):''}</small></td>
            <td><small>${WMS.esc(m.ubicacion_origen||'-')}</small></td>
            <td><small>${WMS.esc(m.ubicacion_destino||'-')}</small></td>
            <td><small>${WMS.esc(m.usuario||'-')}</small></td>
            <td><small>${WMS.esc(m.observaciones||'')}</small></td>
          </tr>`).join('') || '<tr><td colspan="16" class="table-empty">Sin movimientos en el rango seleccionado</td></tr>'}
          </tbody></table>`;
    } catch(e) {
      if (wrap) wrap.innerHTML = '<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando Kardex</p></div>';
    }
  },

  _kExportar() {
    const prodId = document.getElementById('k-prod-id')?.value;
    if (!prodId) return WMS.toast('warning', 'Seleccione un producto primero');
    const desde = document.getElementById('k-desde')?.value;
    const hasta = document.getElementById('k-hasta')?.value;
    const token = localStorage.getItem('wms_token') || '';
    const url = `${API_BASE}/v2/inventario/kardex?export=excel&producto_id=${prodId}&fecha_inicio=${desde}&fecha_fin=${hasta}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── RECEPCIONES ───────────────────────────────────────────────────────────
  async show_recepciones() {
    const p = this._getParams('rec');
    const odc = document.getElementById('rec-odc')?.value.trim() || '';
    const prov = document.getElementById('rec-prov')?.value.trim() || '';

    if (!this._buscadoMap.rec) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'rec', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:p.ubicCodigo,
          extra:`<input type="text" class="form-control" id="rec-odc" placeholder="N° ODC" style="max-width:120px;" value="${WMS.esc(odc)}">
                 <input type="text" class="form-control" id="rec-prov" placeholder="Proveedor" style="max-width:140px;" value="${WMS.esc(prov)}">`,
          onFiltrar:"WMS_MODULES.reportes._buscar('rec','show_recepciones')"})}
        ${this._estadoInicialReporte()}`);
      this.initUbicacionAutocomplete('rec-ubic-input','rec-ubic-id','rec-ubic-codigo');
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarRecepciones()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&ubicacion_codigo=${encodeURIComponent(p.ubicCodigo)}&numero_odc=${encodeURIComponent(odc)}&proveedor=${encodeURIComponent(prov)}`;
      const r     = await API.get('/reportes/recepciones', qs);
      const items = r.data || r || [];
      WMS.setContent(`
        ${this._filtroBarra({id:'rec', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:p.ubicCodigo,
          extra:`<input type="text" class="form-control" id="rec-odc" placeholder="N° ODC" style="max-width:120px;" value="${WMS.esc(odc)}">
                 <input type="text" class="form-control" id="rec-prov" placeholder="Proveedor" style="max-width:140px;" value="${WMS.esc(prov)}">`,
          onFiltrar:'WMS_MODULES.reportes.show_recepciones()'})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-ramp-box"></i> Histórico de Recepciones (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="rec-table">
          <thead><tr><th>Fecha</th><th>N° Recepción</th><th>Proveedor</th><th>ODC</th><th>Auxiliar</th><th>Estado</th><th>Total Unid.</th></tr></thead>
          <tbody>${items.map(i => `<tr>
            <td>${WMS.formatDate(i.created_at)}</td>
            <td><strong>${WMS.esc(i.numero_recepcion)}</strong></td>
            <td>${WMS.esc(i.proveedor||'-')}</td>
            <td>${WMS.esc(i.odc_numero||'-')}</td>
            <td>${WMS.esc(i.auxiliar?.nombre||i.auxiliar_nombre||'-')}</td>
            <td><span class="status-chip status-cerrada">${i.estado}</span></td>
            <td>${i.total_productos||0}</td>
          </tr>`).join('')||'<tr><td colspan="7" class="table-empty">Sin recepciones</td></tr>'}
          </tbody></table></div></div>`);
      this.initUbicacionAutocomplete('rec-ubic-input','rec-ubic-id','rec-ubic-codigo');
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Recepciones</div>'); }
  },

  exportarRecepciones() {
    const p    = this._getParams('rec');
    const odc  = document.getElementById('rec-odc')?.value.trim() || '';
    const prov = document.getElementById('rec-prov')?.value.trim() || '';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/recepciones?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&ubicacion_codigo=${encodeURIComponent(p.ubicCodigo)}&numero_odc=${encodeURIComponent(odc)}&proveedor=${encodeURIComponent(prov)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── DESPACHOS ─────────────────────────────────────────────────────────────
  async show_despachos() {
    const p = this._getParams('des');

    if (!this._buscadoMap.des) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'des', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'', onFiltrar:"WMS_MODULES.reportes._buscar('des','show_despachos')"})}
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarDespachos()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}`;
      const r     = await API.get('/reportes/despachos', qs);
      const items = r.data || r || [];
      WMS.setContent(`
        ${this._filtroBarra({id:'des', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'', onFiltrar:'WMS_MODULES.reportes.show_despachos()'})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-fast"></i> Despachos Consolidados (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="des-table">
          <thead><tr><th>Fecha</th><th>N° Despacho</th><th>Cliente</th><th>Ruta</th><th>Estado</th><th>Bultos</th></tr></thead>
          <tbody>${items.map(i => `<tr>
            <td>${WMS.formatDate(i.fecha_movimiento||i.created_at)}</td>
            <td><strong>${WMS.esc(i.numero_despacho)}</strong></td>
            <td>${WMS.esc(i.cliente?.razon_social||i.cliente||'-')}</td>
            <td>${WMS.esc(i.ruta||'-')}</td>
            <td><span class="badge badge-success">${i.estado}</span></td>
            <td>${i.total_bultos||0}</td>
          </tr>`).join('')||'<tr><td colspan="6" class="table-empty">Sin despachos</td></tr>'}
          </tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Despachos</div>'); }
  },

  exportarDespachos() {
    const p = this._getParams('des');
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/despachos?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── PICKING ───────────────────────────────────────────────────────────────
  async show_picking() {
    const p     = this._getParams('pick');
    const plan  = document.getElementById('pick-planilla')?.value.trim() || '';
    const ruta  = document.getElementById('pick-ruta')?.value.trim() || '';

    if (!this._buscadoMap.pick) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'pick', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:p.ubicCodigo,
          extra:`<input type="text" class="form-control" id="pick-planilla" placeholder="Planilla" style="max-width:110px;" value="${WMS.esc(plan)}">
                 <input type="text" class="form-control" id="pick-ruta" placeholder="Ruta" style="max-width:110px;" value="${WMS.esc(ruta)}">`,
          onFiltrar:"WMS_MODULES.reportes._buscar('pick','show_picking')"})}
        ${this._estadoInicialReporte()}`);
      this.initUbicacionAutocomplete('pick-ubic-input','pick-ubic-id','pick-ubic-codigo');
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarPicking()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&ubicacion_codigo=${encodeURIComponent(p.ubicCodigo)}&planilla_numero=${encodeURIComponent(plan)}&ruta=${encodeURIComponent(ruta)}`;
      const r     = await API.get('/reportes/picking', qs);
      const items = r.data || r || [];
      WMS.setContent(`
        ${this._filtroBarra({id:'pick', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:p.ubicCodigo,
          extra:`<input type="text" class="form-control" id="pick-planilla" placeholder="Planilla" style="max-width:110px;" value="${WMS.esc(plan)}">
                 <input type="text" class="form-control" id="pick-ruta" placeholder="Ruta" style="max-width:110px;" value="${WMS.esc(ruta)}">`,
          onFiltrar:'WMS_MODULES.reportes.show_picking()'})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Reporte de Picking por Línea (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="pick-table">
          <thead><tr><th>Planilla</th><th>Ruta</th><th>Producto (EAN)</th><th>Solicitado</th><th>Separado</th><th>Ubicación</th><th>Auxiliar</th><th>Estado</th></tr></thead>
          <tbody>${items.map(i => `<tr>
            <td><strong>${WMS.esc(i.planilla_numero||'-')}</strong></td>
            <td>${WMS.esc(i.ruta||'-')}</td>
            <td><code style="font-size:.75rem;">${WMS.esc(i.ean||'-')}</code> ${WMS.esc(i.producto||'-')}</td>
            <td>${i.cantidad_solicitada}</td>
            <td>${i.cantidad_pickeada}</td>
            <td>${WMS.esc(i.ubicacion||'-')}</td>
            <td>${WMS.esc(i.auxiliar||'-')}</td>
            <td><span class="badge badge-info">${WMS.esc(i.linea_estado||'-')}</span></td>
          </tr>`).join('')||'<tr><td colspan="8" class="table-empty">Sin líneas de picking</td></tr>'}
          </tbody></table></div></div>`);
      this.initUbicacionAutocomplete('pick-ubic-input','pick-ubic-id','pick-ubic-codigo');
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Picking</div>'); }
  },

  exportarPicking() {
    const p    = this._getParams('pick');
    const plan = document.getElementById('pick-planilla')?.value.trim() || '';
    const ruta = document.getElementById('pick-ruta')?.value.trim() || '';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/picking?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&ubicacion_codigo=${encodeURIComponent(p.ubicCodigo)}&planilla_numero=${encodeURIComponent(plan)}&ruta=${encodeURIComponent(ruta)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── DEVOLUCIONES ──────────────────────────────────────────────────────────
  async show_devoluciones() {
    const p = this._getParams('dev');

    if (!this._buscadoMap.dev) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'dev', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'', onFiltrar:"WMS_MODULES.reportes._buscar('dev','show_devoluciones')"})}
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarDevoluciones()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}`;
      const data  = await API.get('/reportes/devoluciones', qs);
      const items = data.data || data || [];
      const rows  = items.map(d => `
        <tr>
          <td><strong>${WMS.esc(d.numero_devolucion || d.id)}</strong></td>
          <td><span class="badge border text-dark bg-light">${WMS.esc(d.tipo)}</span></td>
          <td>${WMS.esc(d.proveedor || '—')}</td>
          <td>${WMS.formatDateTime(d.created_at)}</td>
          <td><span class="badge badge-info">${WMS.esc(d.estado)}</span></td>
          <td>
            <ul class="mb-0 ps-3" style="font-size:.75rem;color:#475569;">
              ${(d.detalles||[]).map(det => `<li>${WMS.esc(det.producto?.nombre||'Prod')} (${det.cantidad})</li>`).join('')}
            </ul>
          </td>
        </tr>`).join('');

      WMS.setContent(`
        ${this._filtroBarra({id:'dev', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'', onFiltrar:'WMS_MODULES.reportes.show_devoluciones()'})}
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-reply"></i> Reporte de Devoluciones (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="dev-table">
              <thead><tr><th># Devolución</th><th>Tipo</th><th>Proveedor</th><th>Fecha</th><th>Estado</th><th>Detalle Productos</th></tr></thead>
              <tbody>${rows||'<tr><td colspan="6" class="table-empty">No hay devoluciones</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error cargando Devoluciones'); }
  },

  exportarDevoluciones() {
    const p = this._getParams('dev');
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/devoluciones?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── PROVEEDORES ───────────────────────────────────────────────────────────
  async show_proveedores() {
    const p    = this._getParams('prov');
    const nombre = document.getElementById('prov-nombre')?.value.trim() || '';

    if (!this._buscadoMap.prov) {
      WMS.setToolbar('');
      WMS.setContent(`
        <div class="filter-bar" style="flex-wrap:wrap;gap:8px;">
          <input type="date" class="form-control" id="prov-desde" style="max-width:148px;" value="${p.desde}">
          <input type="date" class="form-control" id="prov-hasta" style="max-width:148px;" value="${p.hasta}">
          <input type="text"  class="form-control" id="prov-nombre" placeholder="Nombre proveedor" style="max-width:200px;" value="${WMS.esc(nombre)}">
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes._buscar('prov','show_proveedores')"><i class="fa-solid fa-search"></i> Filtrar</button>
        </div>
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarProveedores()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&proveedor=${encodeURIComponent(nombre)}`;
      const data  = await API.get('/reportes/evaluacion-proveedores', qs);
      const items = data.data || data || [];
      const rows  = items.map(p => `
        <tr>
          <td><strong>${WMS.esc(p.proveedor)}</strong><br><small class="text-muted">NIT: ${WMS.esc(p.nit)}</small></td>
          <td class="text-center">${p.total_odc}</td>
          <td class="text-center">${p.pct_cumplimiento_odc !== null ? p.pct_cumplimiento_odc + '%' : '—'}</td>
          <td class="text-center">${p.total_recepciones}</td>
          <td class="text-center">${p.novedades_recepcion}</td>
          <td class="text-center fw-bold" style="color:#7c3aed;">${p.avg_demora_atencion !== null ? p.avg_demora_atencion + ' m' : '—'}</td>
          <td class="text-center fw-bold" style="color:#0ea5e9;">${p.avg_tiempo_operacion !== null ? p.avg_tiempo_operacion + ' m' : '—'}</td>
          <td class="text-center">
            <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
              <div style="flex:1;max-width:60px;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                <div style="width:${p.pct_cumplimiento_citas||0}%;height:100%;background:#22c55e;"></div>
              </div>
              <span style="font-size:.75rem;font-weight:600;">${p.pct_cumplimiento_citas||0}%</span>
            </div>
          </td>
        </tr>`).join('');

      WMS.setContent(`
        <div class="filter-bar" style="flex-wrap:wrap;gap:8px;">
          <input type="date" class="form-control" id="prov-desde" style="max-width:148px;" value="${p.desde}">
          <input type="date" class="form-control" id="prov-hasta" style="max-width:148px;" value="${p.hasta}">
          <input type="text"  class="form-control" id="prov-nombre" placeholder="Nombre proveedor" style="max-width:200px;" value="${WMS.esc(nombre)}">
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes.show_proveedores()"><i class="fa-solid fa-search"></i> Filtrar</button>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-star"></i> Evaluación de Proveedores (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="prov-table">
              <thead><tr>
                <th>Proveedor</th><th class="text-center">ODC</th><th class="text-center">% Cumpl. ODC</th>
                <th class="text-center">Recepciones</th><th class="text-center">Novedades</th>
                <th class="text-center">Demora Atenc.</th><th class="text-center">Operación</th><th class="text-center">Cumpl. Citas</th>
              </tr></thead>
              <tbody>${rows||'<tr><td colspan="8" class="table-empty">Sin datos</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error cargando Evaluación de Proveedores'); }
  },

  exportarProveedores() {
    const desde  = document.getElementById('prov-desde')?.value || '';
    const hasta  = document.getElementById('prov-hasta')?.value || '';
    const nombre = document.getElementById('prov-nombre')?.value.trim() || '';
    const token  = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/evaluacion-proveedores?export=excel&fecha_desde=${desde}&fecha_hasta=${hasta}&proveedor=${encodeURIComponent(nombre)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── AUDIT LOG ─────────────────────────────────────────────────────────────
  async show_audit() {
    const p = this._getParams('aud');

    if (!this._buscadoMap.aud) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'aud', desde:p.desde, hasta:p.hasta, referencia:'', ubicacion:'', onFiltrar:"WMS_MODULES.reportes._buscar('aud','show_audit')"})}
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarAudit()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&limit=200`;
      const r     = await API.get('/reportes/audit-log', qs);
      const items = r.data || r || [];
      WMS.setContent(`
        ${this._filtroBarra({id:'aud', desde:p.desde, hasta:p.hasta, referencia:'', ubicacion:'', onFiltrar:'WMS_MODULES.reportes.show_audit()'})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-scroll"></i> Log de Auditoría (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="aud-table">
          <thead><tr><th>Fecha/Hora</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Detalle</th></tr></thead>
          <tbody>${items.map(a => `<tr>
            <td class="text-sm">${WMS.formatDateTime(a.created_at)}</td>
            <td>${WMS.esc(a.usuario_nombre||a.usuario||a.personal||'-')}</td>
            <td><span class="badge badge-info">${WMS.esc(a.accion||a.tipo||'')}</span></td>
            <td>${WMS.esc(a.modulo||'-')}</td>
            <td class="truncate" style="max-width:250px;">${WMS.esc(a.descripcion||a.detalle||'-')}</td>
          </tr>`).join('')||'<tr><td colspan="5" class="table-empty">Sin registros</td></tr>'}
          </tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error de conexión</div>'); }
  },

  exportarAudit() {
    const p = this._getParams('aud');
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/audit-log?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── ODC REPORTE ───────────────────────────────────────────────────────────
  async show_odc() {
    const p   = this._getParams('odc');
    const num = document.getElementById('odc-num')?.value.trim() || '';

    if (!this._buscadoMap.odc) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._filtroBarra({id:'odc', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'',
          extra:`<input type="text" class="form-control" id="odc-num" placeholder="N° ODC" style="max-width:120px;" value="${WMS.esc(num)}">`,
          onFiltrar:"WMS_MODULES.reportes._buscar('odc','show_odc')"})}
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarODC('pallet')"><i class="fa-solid fa-file-csv"></i> Detalle por Pallet</button>
      <button class="btn btn-info btn-sm"    onclick="WMS_MODULES.reportes.exportarODC('resumen')"><i class="fa-solid fa-file-csv"></i> Resumen ODC</button>
    `);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&numero_odc=${encodeURIComponent(num)}&referencia=${encodeURIComponent(p.ref)}`;
      const r     = await API.get('/reportes/odc', qs);
      const items = r.data || r || [];
      WMS.setContent(`
        ${this._filtroBarra({id:'odc', desde:p.desde, hasta:p.hasta, referencia:p.ref, ubicacion:'',
          extra:`<input type="text" class="form-control" id="odc-num" placeholder="N° ODC" style="max-width:120px;" value="${WMS.esc(num)}">`,
          onFiltrar:'WMS_MODULES.reportes.show_odc()'})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-file-invoice"></i> Reporte Detallado de ODC (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="odc-table">
          <thead><tr><th>Fecha</th><th>N° ODC</th><th>Proveedor</th><th>Estado</th><th>Productos</th><th>Recibido</th></tr></thead>
          <tbody>${items.map(o => `<tr>
            <td>${WMS.formatDate(o.created_at)}</td>
            <td><strong>${WMS.esc(o.numero_odc)}</strong></td>
            <td>${WMS.esc(o.proveedor?.razon_social||'-')}</td>
            <td><span class="status-chip status-${o.estado?.toLowerCase()}">${WMS.esc(o.estado)}</span></td>
            <td>${o.detalles?.length||0} items</td>
            <td>${o.detalles?.reduce((acc,curr) => acc+(parseFloat(curr.cantidad_recibida)||0),0)} un.</td>
          </tr>`).join('')||'<tr><td colspan="6" class="table-empty">Sin órdenes</td></tr>'}
          </tbody></table></div></div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando reporte</div>'); }
  },

  exportarODC(group) {
    const p   = this._getParams('odc');
    const num = document.getElementById('odc-num')?.value.trim() || '';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/odc?export=excel&group=${group}&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&numero_odc=${encodeURIComponent(num)}&referencia=${encodeURIComponent(p.ref)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── AGOTADOS POR DEMANDA ──────────────────────────────────────────────────
  async show_agotados() {
    const p    = this._getParams('agot');
    const tipo = document.getElementById('agot-tipo')?.value || 'todos';

    if (!this._buscadoMap.agot) {
      WMS.setToolbar('');
      const tipoOptsInicial = `
        <option value="todos"        ${tipo==='todos'  ?'selected':''}>Todos</option>
        <option value="agotado_total"   ${tipo==='agotado_total'  ?'selected':''}>Solo Agotado Total</option>
        <option value="agotado_parcial" ${tipo==='agotado_parcial'?'selected':''}>Solo Agotado Parcial</option>`;
      WMS.setContent(`
        <div class="filter-bar" style="flex-wrap:wrap;gap:8px;">
          <input type="date" class="form-control" id="agot-desde" style="max-width:148px;" value="${p.desde}">
          <input type="date" class="form-control" id="agot-hasta" style="max-width:148px;" value="${p.hasta}">
          <input type="text"  class="form-control" id="agot-ref" placeholder="Referencia / EAN" style="max-width:160px;" value="${WMS.esc(p.ref)}">
          <select class="form-control" id="agot-tipo" style="max-width:160px;">${tipoOptsInicial}</select>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes._buscar('agot','show_agotados')"><i class="fa-solid fa-search"></i> Filtrar</button>
        </div>
        ${this._estadoInicialReporte()}`);
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarAgotados()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&tipo=${tipo}`;
      const r     = await API.get('/reportes/agotados-demanda', qs);
      const items = r.data || r || [];

      const totalCount   = items.filter(i => i.tipo_agotado === 'agotado_total').length;
      const parcialCount = items.filter(i => i.tipo_agotado === 'agotado_parcial').length;

      const rows = items.map(i => {
        const esTotal   = i.tipo_agotado === 'agotado_total';
        const rowBg     = esTotal ? 'background:#fef2f2;' : 'background:#fffbeb;';
        const badgeHtml = esTotal
          ? '<span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;">Agotado Total</span>'
          : '<span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600;">Agotado Parcial</span>';
        return `<tr style="${rowBg}">
          <td><code style="font-size:.75rem;">${WMS.esc(i.codigo_interno)}</code></td>
          <td>${WMS.esc(i.nombre)}</td>
          <td class="text-center">${i.cantidad_solicitada}</td>
          <td class="text-center" style="font-weight:600;color:${esTotal?'#dc2626':'#d97706'};">${i.cantidad_disponible}</td>
          <td class="text-center" style="font-weight:700;color:#dc2626;">-${i.deficit}</td>
          <td>${badgeHtml}</td>
        </tr>`;
      }).join('');

      WMS.setContent(`
        <div class="filter-bar" style="flex-wrap:wrap;gap:8px;">
          <input type="date" class="form-control" id="agot-desde" style="max-width:148px;" value="${p.desde}">
          <input type="date" class="form-control" id="agot-hasta" style="max-width:148px;" value="${p.hasta}">
          <input type="text"  class="form-control" id="agot-ref" placeholder="Referencia / EAN" style="max-width:160px;" value="${WMS.esc(p.ref)}">
          <select class="form-control" id="agot-tipo" style="max-width:160px;">
            <option value="todos"        ${tipo==='todos'  ?'selected':''}>Todos</option>
            <option value="agotado_total"   ${tipo==='agotado_total'  ?'selected':''}>Solo Agotado Total</option>
            <option value="agotado_parcial" ${tipo==='agotado_parcial'?'selected':''}>Solo Agotado Parcial</option>
          </select>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes.show_agotados()"><i class="fa-solid fa-search"></i> Filtrar</button>
        </div>

        <!-- KPIs rápidos -->
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
          <div style="background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;border-radius:6px;padding:12px 20px;min-width:150px;">
            <div style="font-size:.72rem;font-weight:700;color:#991b1b;text-transform:uppercase;">Agotado Total</div>
            <div style="font-size:1.6rem;font-weight:900;color:#dc2626;">${totalCount}</div>
            <div style="font-size:.72rem;color:#64748b;">Stock = 0</div>
          </div>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #f59e0b;border-radius:6px;padding:12px 20px;min-width:150px;">
            <div style="font-size:.72rem;font-weight:700;color:#92400e;text-transform:uppercase;">Agotado Parcial</div>
            <div style="font-size:1.6rem;font-weight:900;color:#f59e0b;">${parcialCount}</div>
            <div style="font-size:.72rem;color:#64748b;">Stock insuficiente</div>
          </div>
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #22c55e;border-radius:6px;padding:12px 20px;min-width:150px;">
            <div style="font-size:.72rem;font-weight:700;color:#14532d;text-transform:uppercase;">Total Productos</div>
            <div style="font-size:1.6rem;font-weight:900;color:#16a34a;">${items.length}</div>
            <div style="font-size:.72rem;color:#64748b;">Con déficit activo</div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Productos Agotados por Demanda (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="agot-table">
              <thead><tr>
                <th>Referencia</th><th>Nombre</th>
                <th class="text-center">Solicitado</th>
                <th class="text-center">Disponible</th>
                <th class="text-center">Déficit</th>
                <th>Estado</th>
              </tr></thead>
              <tbody>${rows||'<tr><td colspan="6" class="table-empty" style="color:#22c55e;"><i class="fa-solid fa-circle-check"></i> Sin productos agotados en el período</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando reporte de agotados</div>'); }
  },

  exportarAgotados() {
    const p    = this._getParams('agot');
    const tipo = document.getElementById('agot-tipo')?.value || 'todos';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/agotados-demanda?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&tipo=${tipo}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── PLAN DE CONTINGENCIA ──────────────────────────────────────────────────
  async show_contingencia() {
    const today = new Date().toISOString().slice(0, 10);
    WMS.setToolbar(`
      <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.reportes.abrirSeparacion()"><i class="fa-solid fa-print"></i> Imprimir Separación</button>
      <button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.abrirCertificacion()"><i class="fa-solid fa-print"></i> Imprimir Certificación</button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.reportes.exportarSeparacionCSV()"><i class="fa-solid fa-file-csv"></i> CSV Separación</button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.reportes.exportarCertCSV()"><i class="fa-solid fa-file-csv"></i> CSV Certificación</button>`);
    WMS.setContent(`
      <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:20px">
        <div class="card" style="border-left:4px solid #f59e0b;padding:16px">
          <div style="font-size:1.2rem;font-weight:700;color:#92400e"><i class="fa-solid fa-triangle-exclamation"></i> Plan de Contingencia Sin Internet</div>
          <p style="color:#555;font-size:.9rem;margin:8px 0 0">Cuando no haya conectividad, imprima las planillas antes de iniciar operaciones. El sistema funciona localmente desde XAMPP.</p>
        </div>
        <div class="card" style="border-left:4px solid #10b981;padding:16px;display:flex;align-items:center;gap:16px;position:relative;">
          <div style="background:#f1f5f9;padding:4px;border-radius:8px;flex-shrink:0;" id="contingencia-qr">
            <div style="width:80px;height:80px;background:#e2e8f0;"></div>
          </div>
          <div style="flex:1;padding-right:30px;">
            <button class="btn btn-sm btn-icon" onclick="WMS.updateConnectionInfo()" style="position:absolute;top:12px;right:12px;background:rgba(16,185,129,.1);color:#065f46;border:none;" title="Refrescar IP">
              <i class="fa-solid fa-sync"></i>
            </button>
            <div style="font-weight:700;color:#065f46;margin-bottom:4px;"><i class="fa-solid fa-network-wired"></i> Acceso Local XAMPP</div>
            <div style="font-family:monospace;font-size:.85rem;background:#f1f5f9;padding:4px 8px;border-radius:4px;word-break:break-all;" id="contingencia-url">Cargando...</div>
            <div style="font-size:.8rem;color:#64748b;margin-top:4px;" id="contingencia-ip"><i class="fa-solid fa-circle-info"></i> IP: ---</div>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-list"></i> Procedimiento Manual — Separación de Pedidos</span></div>
        <div style="padding:16px;font-size:.9rem">
          <div style="display:flex;gap:24px;flex-wrap:wrap">
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px">1. Antes del turno</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Admin/Supervisor imprime planilla de separación del día</li>
                <li>Se verifica el stock físico contra lo impreso</li>
                <li>Se asigna manualmente cada orden a un picker</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px">2. Durante la operación</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Picker anota la cantidad alistada en «Cant. Alistada»</li>
                <li>Si hay faltante: anota en «Observación» y avisa al supervisor</li>
                <li>Supervisor firma cada orden completada</li>
              </ol>
            </div>
            <div style="flex:1;min-width:280px">
              <h4 style="color:#1e3a5f;margin:0 0 8px">3. Al recuperar internet</h4>
              <ol style="padding-left:20px;line-height:1.9">
                <li>Ingresar al sistema con la planilla impresa como guía</li>
                <li>Confirmar cada línea de picking en el módulo</li>
                <li>Archivar planillas firmadas (auditoría)</li>
              </ol>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-top:16px">
        <label style="font-weight:600;font-size:.9rem">Fecha para planillas:</label>
        <input type="date" id="cont-fecha" value="${today}" style="margin-left:8px;padding:4px 8px;border:1px solid #ccc;border-radius:4px">
      </div>`);
    WMS.updateConnectionInfo();
  },

  abrirSeparacion() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    this._abrirReporteHtml(`${API_BASE}/reportes/contingencia/separacion?formato=html&fecha=${fecha}`, 'Separacion_' + fecha);
  },

  abrirCertificacion() {
    const fecha = document.getElementById('cont-fecha')?.value || new Date().toISOString().slice(0,10);
    this._abrirReporteHtml(`${API_BASE}/reportes/contingencia/certificacion?formato=html&fecha=${fecha}`, 'Certificacion_' + fecha);
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
      const sep   = apiUrl.includes('?') ? '&' : '?';
      const urlWithToken = `${apiUrl}${sep}token=${encodeURIComponent(token)}`;
      const resp  = await fetch(urlWithToken, { headers: { Authorization: 'Bearer ' + token } });
      if (!resp.ok) { WMS.toast('error', 'Error al generar reporte'); return; }
      const html  = await resp.text();
      const win   = window.open('', title, 'width=1100,height=800');
      if (win) { win.document.write(html); win.document.close(); }
      else { WMS.toast('warning', 'El navegador bloqueó la ventana emergente'); }
    } catch(e) {
      WMS.toast('error', 'No se pudo abrir el reporte: ' + e.message);
    }
  },

  // ── Utilidad: filtro de tabla en cliente ──────────────────────────────────
  filterTable(val, tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const term = val.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(tr => {
      tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
  },
};
