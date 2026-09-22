/* ============================================================
   WMS Desktop - Módulo REPORTES
   ============================================================ */
WMS_MODULES.reportes = {
  load(sub) {
    WMS.setBreadcrumb('reportes', this.subLabel(sub));
    WMS.renderSidebar('reportes');
    const s = sub || 'gerencial';
    const fn = {
      multimodal:   this.show_inventario_multimodal,
      gerencial:    this.show_gerencial,
      kardex:       this.show_kardex,
      recepciones:  this.show_recepciones,
      recibo_cdp:   this.show_recibo_cdp,
      despachos:    this.show_despachos,
      picking:      this.show_picking,
      devoluciones: this.show_devoluciones,
      proveedores:  this.show_proveedores,
      audit:        this.show_audit,
      odc:          this.show_odc,
      contingencia: this.show_contingencia,
      agotados:     this.show_agotados,
      conciliacion: this.show_conciliacion,
    };
    (fn[s]?.bind(this) || fn.gerencial.bind(this))();
  },

  subLabel(s) {
    const m = {
      multimodal:'Reporte Inventario Excel',
      gerencial:'Dashboard Gerencial', kardex:'Kardex', recepciones:'Recepciones',
      recibo_cdp:'Recibo CDP', despachos:'Despachos', picking:'Picking', devoluciones:'Devoluciones',
      proveedores:'Evaluación Proveedores', audit:'Log de Auditoría',
      odc:'Recibo Detallado (ODC)', contingencia:'Plan de Contingencia',
      agotados:'Agotados por Demanda', conciliacion:'Conciliación de Trazabilidad',
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

  // ── Helper genérico: dropdown de sugerencias bajo un input ────────────────
  _mostrarDropdown(inputId, items, renderItem) {
    const input = document.getElementById(inputId);
    if (!input) return;
    let dd = document.getElementById(inputId + '-dd');
    if (!dd) {
      dd = document.createElement('div');
      dd.id = inputId + '-dd';
      dd.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #cbd5e1;border-radius:4px;max-height:180px;overflow-y:auto;width:100%;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;';
      input.parentElement.style.position = 'relative';
      input.parentElement.appendChild(dd);
    }
    if (!items.length) {
      dd.innerHTML = '<div style="padding:8px 12px;color:#94a3b8;font-size:.82rem;">Sin resultados</div>';
    } else {
      dd.innerHTML = items.map(renderItem).join('');
    }
    dd.style.display = 'block';
    if (!dd.dataset.bound) {
      dd.dataset.bound = '1';
      document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
      });
    }
    return dd;
  },

  // ── Autocomplete de CLIENTE — carga /param/clientes una sola vez y filtra
  // localmente (la lista de clientes por empresa es pequeña, no requiere
  // búsqueda en servidor como productos/ubicaciones) ───────────────────────
  _clientesCache: null,

  async initClienteAutocomplete(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const buscarYMostrar = async () => {
      const val = input.value.trim();

      if (!this._clientesCache) {
        try {
          const res = await API.get('/param/clientes');
          this._clientesCache = res.data || res || [];
        } catch (_) { this._clientesCache = []; }
      }

      // Sin texto: mostrar los primeros clientes como lista para elegir (combobox).
      // Con texto: filtrar por coincidencia.
      const term = val.toLowerCase();
      const list = (term
        ? this._clientesCache.filter(c => (c.razon_social || '').toLowerCase().includes(term))
        : this._clientesCache
      ).slice(0, 15);

      this._mostrarDropdown(inputId, list, c => `
        <div style="padding:8px 12px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f1f5f9;"
             onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''"
             onclick="WMS_MODULES.reportes._selectTexto('${inputId}','${WMS.esc(c.razon_social).replace(/'/g,"\\'")}')">
          <b>${WMS.esc(c.razon_social)}</b>${c.nit ? ' — ' + WMS.esc(c.nit) : ''}
        </div>`);
    };

    // focus + click cubren tanto "primer clic" (dispara focus) como "clic con
    // el input ya enfocado" (no vuelve a disparar focus, pero sí click) — ambos
    // llaman a la misma función, es idempotente, no hay conflicto de orden.
    input.addEventListener('focus', buscarYMostrar);
    input.addEventListener('click', buscarYMostrar);
    input.addEventListener('input', buscarYMostrar);
  },

  // ── Autocomplete de REFERENCIA/PRODUCTO — búsqueda en servidor con debounce ─
  _refDebounce: {},

  initProductoAutocomplete(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const buscarYMostrar = () => {
      clearTimeout(this._refDebounce[inputId]);
      const val = input.value.trim();

      this._refDebounce[inputId] = setTimeout(async () => {
        try {
          // Sin texto: el backend devuelve los productos más recientes por
          // defecto ("Ver Todos") — sirve como lista inicial del combobox.
          const res  = await API.get('/param/productos/buscar', `q=${encodeURIComponent(val)}&limit=12`);
          const list = res.data || res || [];
          this._mostrarDropdown(inputId, list, p => `
            <div style="padding:8px 12px;cursor:pointer;font-size:.83rem;border-bottom:1px solid #f1f5f9;"
                 onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''"
                 onclick="WMS_MODULES.reportes._selectTexto('${inputId}','${WMS.esc(p.codigo_interno || p.nombre).replace(/'/g,"\\'")}')">
              <b>${WMS.esc(p.codigo_interno || '-')}</b> — ${WMS.esc(p.nombre)}
            </div>`);
        } catch (_) { const dd = document.getElementById(inputId + '-dd'); if (dd) dd.style.display = 'none'; }
      }, val ? 350 : 0);
    };

    input.addEventListener('focus', buscarYMostrar);
    input.addEventListener('click', buscarYMostrar);
    input.addEventListener('input', buscarYMostrar);
  },

  _selectTexto(inputId, valor) {
    const input = document.getElementById(inputId);
    if (input) input.value = valor;
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
    const hoy    = WMS.getToday();
    const hace30 = WMS.getPastDate(30);
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
    const hoy = WMS.getToday();
    const hace30 = WMS.getPastDate(30);
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

  // ── RECIBO CDP — recepciones por QR del proveedor CDP ─────────────────────
  _panelFiltrosReciboCdp({desde='', hasta='', referencia=''} = {}) {
    const campo = (label, inputHtml) => `
      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-bottom:5px;">${label}</label>
        ${inputHtml}
      </div>`;
    // Sin clase .card: su overflow:hidden recortaría la lista del combobox de referencia.
    return `
      <div style="margin-bottom:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-filter"></i> Filtros de búsqueda</span>
          <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.reportes._limpiarFiltrosReciboCdp()">
            <i class="fa-solid fa-eraser"></i> Limpiar
          </button>
        </div>
        <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;align-items:end;">
          ${campo('Fecha desde', `<input type="date" class="form-control" id="cdp-desde" value="${desde}">`)}
          ${campo('Fecha hasta', `<input type="date" class="form-control" id="cdp-hasta" value="${hasta}">`)}
          ${campo('Producto / Referencia', `
            <div style="position:relative;">
              <input type="text" class="form-control" id="cdp-ref" placeholder="Todas las referencias" autocomplete="off"
                     style="padding-right:28px;" value="${WMS.esc(referencia)}">
              <i class="fa-solid fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none;"></i>
            </div>`)}
          <div>
            <button class="btn btn-primary" style="width:100%;" onclick="WMS_MODULES.reportes._buscar('cdp','show_recibo_cdp')">
              <i class="fa-solid fa-magnifying-glass"></i> Filtrar
            </button>
          </div>
        </div>
      </div>`;
  },

  _limpiarFiltrosReciboCdp() {
    ['cdp-desde','cdp-hasta','cdp-ref'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    document.getElementById('cdp-desde').value = WMS.getPastDate(30);
    document.getElementById('cdp-hasta').value = WMS.getToday();
    this.show_recibo_cdp();
  },

  async show_recibo_cdp() {
    const p = this._getParams('cdp');

    if (!this._buscadoMap.cdp) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._panelFiltrosReciboCdp({desde:p.desde, hasta:p.hasta, referencia:p.ref})}
        ${this._estadoInicialReporte()}`);
      this.initProductoAutocomplete('cdp-ref');
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarReciboCdp()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}`;
      const r  = await API.get('/reportes/recibo-cdp', qs);
      const data = r.data || r || {};
      const rows = data.rows || [];
      const tot  = data.totales || {};

      WMS.setContent(`
        ${this._panelFiltrosReciboCdp({desde:p.desde, hasta:p.hasta, referencia:p.ref})}

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">
          <div style="padding:12px;background:#eff6ff;border-radius:8px;text-align:center;border:1px solid #bfdbfe;">
            <div style="font-size:20px;font-weight:800;color:#1e40af;">${tot.total_lineas || 0}</div>
            <div style="font-size:10px;color:#1e40af;font-weight:700;text-transform:uppercase;">Líneas Recibidas</div>
          </div>
          <div style="padding:12px;background:#f0fdf4;border-radius:8px;text-align:center;border:1px solid #bbf7d0;">
            <div style="font-size:20px;font-weight:800;color:#16a34a;">${WMS.formatNum(tot.total_cajas || 0)}</div>
            <div style="font-size:10px;color:#16a34a;font-weight:700;text-transform:uppercase;">Total Cajas</div>
          </div>
          <div style="padding:12px;background:#fefce8;border-radius:8px;text-align:center;border:1px solid #fde68a;">
            <div style="font-size:20px;font-weight:800;color:#d97706;">${WMS.formatNum(tot.total_saldo || 0)}</div>
            <div style="font-size:10px;color:#d97706;font-weight:700;text-transform:uppercase;">Total Saldo</div>
          </div>
          <div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
            <div style="font-size:20px;font-weight:800;color:#334155;">${WMS.formatNum(tot.total_recibido || 0)}</div>
            <div style="font-size:10px;color:#334155;font-weight:700;text-transform:uppercase;">Total Recibido (UND)</div>
          </div>
        </div>

        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-qrcode"></i> Recibo sin ODC — QR o Proveedor CDP (${rows.length})</span></div>
        <div class="table-container-scroll"><table class="erp-table" id="cdp-table">
          <thead style="position:sticky;top:0;background:#f8fafc;z-index:10;"><tr>
            <th>Fecha</th><th># Recepción</th><th>Código</th><th>Producto</th>
            <th>Cant. Recibida (QR)</th><th>F. Vencimiento</th><th>Lote</th>
            <th>Cajas</th><th>Saldo</th><th>Recibido Por</th><th>Ubicación</th>
          </tr></thead>
          <tbody>${rows.map(row => `<tr>
            <td>${WMS.formatDate(row.fecha)}</td>
            <td>${WMS.esc(row.numero_recepcion)}</td>
            <td style="font-family:monospace;">${WMS.esc(row.producto_codigo)}</td>
            <td>${WMS.esc(row.producto_nombre)}</td>
            <td style="text-align:right;font-weight:700;">${WMS.formatNum(row.cantidad_recibida)}</td>
            <td>${row.fecha_vencimiento ? WMS.formatDate(row.fecha_vencimiento) : '—'}</td>
            <td>${WMS.esc(row.lote)}</td>
            <td style="text-align:right;">${WMS.formatNum(row.total_cajas)}</td>
            <td style="text-align:right;">${WMS.formatNum(row.total_saldo)}</td>
            <td>${WMS.esc(row.recibido_por)}</td>
            <td>${WMS.esc(row.ubicacion)}</td>
          </tr>`).join('')||'<tr><td colspan="11" class="table-empty">Sin recepciones sin ODC (QR o CDP) en este rango</td></tr>'}
          </tbody></table></div></div>`);
      this.initProductoAutocomplete('cdp-ref');
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Recibo CDP</div>'); }
  },

  exportarReciboCdp() {
    const p = this._getParams('cdp');
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/recibo-cdp?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── CONCILIACIÓN DE TRAZABILIDAD — Importado → Separado → Remisión ─────────
  // Garantiza que ninguna referencia ni cantidad se pierda entre lo pedido,
  // lo separado físicamente y lo que sale impreso en la remisión.
  _panelFiltrosConciliacion({desde='', hasta='', cliente='', referencia='', soloDif=true} = {}) {
    const campo = (label, inputHtml) => `
      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-bottom:5px;">${label}</label>
        ${inputHtml}
      </div>`;
    return `
      <div style="margin-bottom:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-filter"></i> Filtros de búsqueda</span>
          <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.reportes._limpiarFiltrosConciliacion()">
            <i class="fa-solid fa-eraser"></i> Limpiar
          </button>
        </div>
        <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;align-items:end;">
          ${campo('Fecha desde', `<input type="date" class="form-control" id="conc-desde" value="${desde}">`)}
          ${campo('Fecha hasta', `<input type="date" class="form-control" id="conc-hasta" value="${hasta}">`)}
          ${campo('Cliente', `
            <div style="position:relative;">
              <input type="text" class="form-control" id="conc-cliente" placeholder="Todos los clientes" autocomplete="off"
                     style="padding-right:28px;" value="${WMS.esc(cliente)}">
            </div>`)}
          ${campo('Producto / Referencia', `
            <div style="position:relative;">
              <input type="text" class="form-control" id="conc-ref" placeholder="Todas las referencias" autocomplete="off"
                     style="padding-right:28px;" value="${WMS.esc(referencia)}">
            </div>`)}
          <div>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155;margin-bottom:9px;">
              <input type="checkbox" id="conc-solo-dif" ${soloDif ? 'checked' : ''} style="width:16px;height:16px;">
              Solo mostrar diferencias
            </label>
          </div>
          <div>
            <button class="btn btn-primary" style="width:100%;" onclick="WMS_MODULES.reportes._buscar('conc','show_conciliacion')">
              <i class="fa-solid fa-magnifying-glass"></i> Filtrar
            </button>
          </div>
        </div>
      </div>`;
  },

  _limpiarFiltrosConciliacion() {
    ['conc-desde','conc-hasta','conc-cliente','conc-ref'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    document.getElementById('conc-desde').value = WMS.getPastDate(7);
    document.getElementById('conc-hasta').value = WMS.getToday();
    document.getElementById('conc-solo-dif').checked = true;
    this.show_conciliacion();
  },

  async show_conciliacion() {
    const p = this._getParams('conc');
    const cliente = document.getElementById('conc-cliente')?.value.trim() || '';
    const soloDif = document.getElementById('conc-solo-dif')?.checked ?? true;

    if (!this._buscadoMap.conc) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._panelFiltrosConciliacion({desde:p.desde || WMS.getPastDate(7), hasta:p.hasta, cliente, referencia:p.ref, soloDif:true})}
        <div class="m-empty" style="padding:24px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:16px;">
          <i class="fa-solid fa-shield-halved" style="color:#1e40af;"></i>
          <p style="margin:6px 0 0;color:#1e3a5f;">Compara, línea por línea, lo <b>importado/solicitado</b> vs. lo <b>separado</b> por el auxiliar vs. lo que efectivamente queda en la <b>remisión</b> certificada. Aplique los filtros y presione "Filtrar".</p>
        </div>`);
      this.initClienteAutocomplete('conc-cliente');
      this.initProductoAutocomplete('conc-ref');
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarConciliacion()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&cliente=${encodeURIComponent(cliente)}&referencia=${encodeURIComponent(p.ref)}&solo_diferencias=${soloDif ? '1' : '0'}`;
      const r    = await API.get('/reportes/conciliacion-trazabilidad', qs);
      const data = r.data || r || {};
      const rows = data.rows || [];
      const tot  = data.resumen || {};

      const badge = (estado) => {
        const map = {
          OK:                       {bg:'#f0fdf4', color:'#16a34a', label:'OK'},
          PENDIENTE:                {bg:'#f8fafc', color:'#64748b', label:'Pendiente'},
          DIFERENCIA_SEPARACION:    {bg:'#fefce8', color:'#d97706', label:'Diferencia separación'},
          DIFERENCIA_CERTIFICACION:{bg:'#fff7ed', color:'#c2410c', label:'Diferencia certificación'},
          RIESGO_IMPRESION:        {bg:'#fef2f2', color:'#dc2626', label:'Riesgo de impresión'},
        };
        const s = map[estado] || map.OK;
        return `<span style="background:${s.bg};color:${s.color};border-radius:5px;padding:3px 8px;font-size:11px;font-weight:800;white-space:nowrap;">${s.label}</span>`;
      };

      WMS.setContent(`
        ${this._panelFiltrosConciliacion({desde:p.desde, hasta:p.hasta, cliente, referencia:p.ref, soloDif})}

        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px;">
          <div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center;border:1px solid #e2e8f0;">
            <div style="font-size:20px;font-weight:800;color:#334155;">${tot.total || 0}</div>
            <div style="font-size:10px;color:#334155;font-weight:700;text-transform:uppercase;">Líneas Revisadas</div>
          </div>
          <div style="padding:12px;background:#f0fdf4;border-radius:8px;text-align:center;border:1px solid #bbf7d0;">
            <div style="font-size:20px;font-weight:800;color:#16a34a;">${tot.ok || 0}</div>
            <div style="font-size:10px;color:#16a34a;font-weight:700;text-transform:uppercase;">OK</div>
          </div>
          <div style="padding:12px;background:#fefce8;border-radius:8px;text-align:center;border:1px solid #fde68a;">
            <div style="font-size:20px;font-weight:800;color:#d97706;">${tot.diferencia_separacion || 0}</div>
            <div style="font-size:10px;color:#d97706;font-weight:700;text-transform:uppercase;">Dif. Separación</div>
          </div>
          <div style="padding:12px;background:#fff7ed;border-radius:8px;text-align:center;border:1px solid #fed7aa;">
            <div style="font-size:20px;font-weight:800;color:#c2410c;">${tot.diferencia_certificacion || 0}</div>
            <div style="font-size:10px;color:#c2410c;font-weight:700;text-transform:uppercase;">Dif. Certificación</div>
          </div>
          <div style="padding:12px;background:#fef2f2;border-radius:8px;text-align:center;border:1px solid #fecaca;">
            <div style="font-size:20px;font-weight:800;color:#dc2626;">${tot.riesgo_impresion || 0}</div>
            <div style="font-size:10px;color:#dc2626;font-weight:700;text-transform:uppercase;">Riesgo Impresión</div>
          </div>
        </div>

        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-shield-halved"></i> Conciliación de Trazabilidad (${rows.length})</span></div>
        <div class="table-container"><table class="erp-table" id="conc-table">
          <thead><tr>
            <th>Fecha</th><th>Planilla</th><th>Cliente</th><th>Código</th><th>Producto</th>
            <th>Importado (cj)</th><th>Separado (cj)</th><th>Certificado (cj)</th>
            <th style="background:#eff6ff;">¿Sale en Remisión?</th><th>Estado</th><th>Detalle</th>
          </tr></thead>
          <tbody>${rows.map(row => {
            const rem = row.sale_remision || '';
            const remStyle = rem.startsWith('Sí')
              ? 'background:#f0fdf4;color:#16a34a;'
              : rem.startsWith('NO')
                ? 'background:#fef2f2;color:#dc2626;'
                : 'background:#f8fafc;color:#64748b;';
            return `<tr>
            <td>${WMS.formatDate(row.fecha)}</td>
            <td>${WMS.esc(row.planilla)}</td>
            <td>${WMS.esc(row.cliente)}</td>
            <td style="font-family:monospace;">${WMS.esc(row.codigo)}</td>
            <td>${WMS.esc(row.producto)}</td>
            <td style="text-align:right;">${WMS.formatNum(row.importado_cajas)}</td>
            <td style="text-align:right;">${WMS.formatNum(row.separado_cajas)}</td>
            <td style="text-align:right;">${WMS.formatNum(row.certificado_cajas)}</td>
            <td><span style="${remStyle}border-radius:5px;padding:3px 8px;font-size:11px;font-weight:800;white-space:nowrap;">${WMS.esc(rem)}</span></td>
            <td>${badge(row.estado)}</td>
            <td style="font-size:11px;color:#64748b;">${WMS.esc(row.detalle || '')}</td>
          </tr>`;
          }).join('')||'<tr><td colspan="11" class="table-empty">Sin diferencias en este rango — todo lo separado coincide con lo importado y lo certificado</td></tr>'}
          </tbody></table></div></div>`);
      this.initClienteAutocomplete('conc-cliente');
      this.initProductoAutocomplete('conc-ref');
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Conciliación de Trazabilidad</div>'); }
  },

  exportarConciliacion() {
    const p = this._getParams('conc');
    const cliente = document.getElementById('conc-cliente')?.value.trim() || '';
    const soloDif = document.getElementById('conc-solo-dif')?.checked ?? true;
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/conciliacion-trazabilidad?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&cliente=${encodeURIComponent(cliente)}&referencia=${encodeURIComponent(p.ref)}&solo_diferencias=${soloDif ? '1' : '0'}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // ── Panel de filtros de Despacho — grilla etiquetada, campos Cliente y
  // Referencia como combobox (lista visible al hacer foco, no solo al escribir) ─
  _panelFiltrosDespacho({desde='', hasta='', cliente='', referencia=''} = {}) {
    const campo = (label, inputHtml) => `
      <div>
        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-bottom:5px;">${label}</label>
        ${inputHtml}
      </div>`;
    const comboInput = (id, placeholder, value) => `
      <div style="position:relative;">
        <input type="text" class="form-control" id="${id}" placeholder="${placeholder}" autocomplete="off"
               style="padding-right:28px;" value="${WMS.esc(value)}">
        <i class="fa-solid fa-chevron-down" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none;"></i>
      </div>`;

    // Nota: NO se usa la clase .card aquí — su CSS trae overflow:hidden (para
    // recortar esquinas redondeadas), lo que recortaba el dropdown del combobox
    // justo en el borde inferior de la tarjeta. Mismo look, sin ese recorte.
    return `
      <div style="margin-bottom:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
        <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-filter"></i> Filtros de búsqueda</span>
          <button class="btn btn-sm btn-outline-secondary" onclick="WMS_MODULES.reportes._limpiarFiltrosDespacho()">
            <i class="fa-solid fa-eraser"></i> Limpiar
          </button>
        </div>
        <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;align-items:end;">
          ${campo('Fecha desde', `<input type="date" class="form-control" id="des-desde" value="${desde}">`)}
          ${campo('Fecha hasta', `<input type="date" class="form-control" id="des-hasta" value="${hasta}">`)}
          ${campo('Cliente', comboInput('des-cliente', 'Todos los clientes', cliente))}
          ${campo('Referencia / Producto', comboInput('des-ref', 'Todas las referencias', referencia))}
          <div>
            <button class="btn btn-primary" style="width:100%;" onclick="WMS_MODULES.reportes._buscar('des','show_despachos')">
              <i class="fa-solid fa-magnifying-glass"></i> Filtrar
            </button>
          </div>
        </div>
      </div>`;
  },

  // Chips con los filtros efectivamente aplicados en la última búsqueda —
  // para que quede claro qué se está viendo, sin tener que mirar los inputs.
  _chipsFiltrosDespacho({desde, hasta, cliente, referencia}) {
    const chip = (label, valor) => valor
      ? `<span style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:20px;padding:4px 12px;font-size:11.5px;font-weight:600;">${label}: ${WMS.esc(valor)}</span>`
      : '';
    const chips = [
      chip('Cliente', cliente),
      chip('Referencia', referencia),
      chip('Desde', desde),
      chip('Hasta', hasta),
    ].filter(Boolean);
    if (!chips.length) return '';
    return `<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">
      <span style="font-size:11px;color:#64748b;font-weight:700;align-self:center;">FILTROS APLICADOS:</span>
      ${chips.join('')}
    </div>`;
  },

  _limpiarFiltrosDespacho() {
    ['des-desde','des-hasta','des-cliente','des-ref'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    document.getElementById('des-desde').value = WMS.getPastDate(30);
    document.getElementById('des-hasta').value = WMS.getToday();
    this.show_despachos();
  },

  // ── DESPACHOS ─────────────────────────────────────────────────────────────
  async show_despachos() {
    const p = this._getParams('des');
    const cliente = document.getElementById('des-cliente')?.value.trim() || '';
    this._despachosCache = this._despachosCache || {};

    if (!this._buscadoMap.des) {
      WMS.setToolbar('');
      WMS.setContent(`
        ${this._panelFiltrosDespacho({desde:p.desde, hasta:p.hasta, cliente, referencia:p.ref})}
        ${this._estadoInicialReporte()}`);
      this.initClienteAutocomplete('des-cliente');
      this.initProductoAutocomplete('des-ref');
      return;
    }

    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.reportes.exportarDespachos()"><i class="fa-solid fa-file-csv"></i> Exportar CSV</button>`);
    WMS.spinner();
    try {
      const qs    = `fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&cliente=${encodeURIComponent(cliente)}`;
      const r     = await API.get('/reportes/despachos', qs);
      const items = r.data || r || [];
      this._despachosCache = {};
      items.forEach(i => { this._despachosCache[i.id] = i; });
      WMS.setContent(`
        ${this._panelFiltrosDespacho({desde:p.desde, hasta:p.hasta, cliente, referencia:p.ref})}
        ${this._chipsFiltrosDespacho({desde:p.desde, hasta:p.hasta, cliente, referencia:p.ref})}
        <div class="card"><div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-fast"></i> Despachos Consolidados (${items.length})</span></div>
        <div class="table-container"><table class="erp-table" id="des-table">
          <thead><tr><th>Fecha</th><th>N° Despacho</th><th>Cliente</th><th>Ruta</th><th>Estado</th><th>Bultos</th><th>Detalle</th></tr></thead>
          <tbody>${items.map(i => `<tr>
            <td>${WMS.formatDate(i.fecha_movimiento||i.created_at)}</td>
            <td><strong>${WMS.esc(i.numero_despacho)}</strong></td>
            <td>${WMS.esc(i.cliente?.razon_social||i.cliente||'-')}</td>
            <td>${WMS.esc(i.ruta||'-')}</td>
            <td><span class="badge badge-success">${i.estado}</span></td>
            <td>${i.total_bultos||0}</td>
            <td>
              <button class="btn btn-xs btn-secondary" onclick="WMS_MODULES.reportes.verDetalleDespacho(${i.id})" title="Ver quién separó, hora, lote y ubicación">
                <i class="fa-solid fa-list"></i>
              </button>
              <button class="btn btn-xs btn-success" onclick="WMS_MODULES.reportes.imprimirRemisionDespacho(${i.id})" title="Ver / imprimir remisión">
                <i class="fa-solid fa-print"></i>
              </button>
            </td>
          </tr>`).join('')||'<tr><td colspan="7" class="table-empty">Sin despachos</td></tr>'}
          </tbody></table></div></div>`);
      this.initClienteAutocomplete('des-cliente');
      this.initProductoAutocomplete('des-ref');
    } catch(e) { WMS.setContent('<div class="m-empty">Error cargando Despachos</div>'); }
  },

  exportarDespachos() {
    const p = this._getParams('des');
    const cliente = document.getElementById('des-cliente')?.value.trim() || '';
    const token = localStorage.getItem('wms_token');
    const url = `${API_BASE}/reportes/despachos?export=excel&fecha_desde=${p.desde}&fecha_hasta=${p.hasta}&referencia=${encodeURIComponent(p.ref)}&cliente=${encodeURIComponent(cliente)}&token=${encodeURIComponent(token)}`;
    window.open(url, '_blank');
  },

  // Detalle de un despacho: quién separó cada línea, hora, lote y de qué
  // ubicación se tomó. Usa el detalle ya devuelto por /reportes/despachos
  // (no vuelve a consultar el backend).
  verDetalleDespacho(despachoId) {
    const d = (this._despachosCache || {})[despachoId];
    const lineas = d?.detalle_lineas || [];
    const body = lineas.length ? `
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead><tr style="background:#f1f5f9;">
            <th style="padding:6px 8px;text-align:left;">Pedido</th>
            <th style="padding:6px 8px;text-align:left;">Producto</th>
            <th style="padding:6px 8px;text-align:left;">Lote</th>
            <th style="padding:6px 8px;text-align:right;">Solicitado</th>
            <th style="padding:6px 8px;text-align:right;">Separado</th>
            <th style="padding:6px 8px;text-align:left;">Separado por</th>
            <th style="padding:6px 8px;text-align:left;">Ubicación origen</th>
            <th style="padding:6px 8px;text-align:left;">Hora</th>
          </tr></thead>
          <tbody>
            ${lineas.map(l => `<tr style="border-bottom:1px solid #f1f5f9;">
              <td style="padding:5px 8px;">${WMS.esc(l.numero_pedido || '-')}</td>
              <td style="padding:5px 8px;">${WMS.esc(l.producto_nombre)} <span style="color:#94a3b8;font-family:monospace;">(${WMS.esc(l.producto_codigo)})</span></td>
              <td style="padding:5px 8px;font-family:monospace;">${WMS.esc(l.lote)}</td>
              <td style="padding:5px 8px;text-align:right;">${l.cantidad_solicitada}</td>
              <td style="padding:5px 8px;text-align:right;">${l.cantidad_pickeada}</td>
              <td style="padding:5px 8px;">${WMS.esc(l.separado_por)}</td>
              <td style="padding:5px 8px;">${WMS.esc(l.ubicacion_origen)}</td>
              <td style="padding:5px 8px;">${WMS.formatDateTime ? WMS.formatDateTime(l.hora) : (l.hora || '-')}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>` : '<div class="m-empty">Sin líneas de detalle para este despacho</div>';

    WMS.showModal(`Detalle despacho ${WMS.esc(d?.numero_despacho || '')}`, body,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')"><i class="fa-solid fa-xmark"></i> Cerrar</button>`, 'xl');
  },

  // Reutiliza el mismo endpoint de remisión-múltiple que ya usa el módulo de
  // Despacho (imprimirRemisionPorOrdenes) — self-contenido para no depender de
  // que ese módulo esté cargado en esta pantalla.
  imprimirRemisionDespacho(despachoId) {
    const d = (this._despachosCache || {})[despachoId];
    const ordenIds = d?.orden_ids || [];
    if (!ordenIds.length) {
      WMS.toast('warning', 'Este despacho no tiene pedidos asociados para generar remisión');
      return;
    }
    const token = localStorage.getItem('wms_token') || localStorage.getItem('token') || '';
    const params = new URLSearchParams();
    ordenIds.forEach(id => params.append('orden_ids[]', id));
    const url = `${API_BASE}/picking/certificacion/remision-multiple?${params}`;
    const win = window.open('', '_blank');
    if (!win) { WMS.toast('warning', 'Permite ventanas emergentes para imprimir'); return; }
    win.document.write('<p style="font-family:sans-serif;padding:20px;">Cargando remisión...</p>');
    fetch(url, { headers: { Authorization: 'Bearer ' + token } })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(html => { if (!win.closed) { win.document.open(); win.document.write(html); win.document.close(); } })
      .catch(e => { if (!win.closed) win.document.write('<p style="color:#dc2626;font-family:sans-serif;padding:20px;">Error al cargar remisión: ' + e.message + '</p>'); });
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
          <thead><tr>
            <th>Fecha</th><th>Sucursal Despacho</th><th>Planilla</th><th>Ruta</th>
            <th>Código</th><th>Descripción</th>
            <th>Solicitado (cj)</th><th>Separado (cj)</th><th>Saldo</th><th>Total Unidad</th>
            <th>Auxiliar</th><th>Ubicación Separación</th><th>Hora Separación</th>
            <th>Lote</th><th>F. Venc.</th><th>Estado</th>
          </tr></thead>
          <tbody>${items.map(i => `<tr>
            <td>${i.fecha ? WMS.formatDate(i.fecha) : '-'}</td>
            <td>${WMS.esc(i.sucursal||'-')}</td>
            <td><strong>${WMS.esc(i.planilla_numero||'-')}</strong></td>
            <td>${WMS.esc(i.ruta||'-')}</td>
            <td><code style="font-size:.75rem;">${WMS.esc(i.ean||'-')}</code></td>
            <td>${WMS.esc(i.producto||'-')}</td>
            <td>${i.cantidad_solicitada}</td>
            <td>${i.separado_cajas}</td>
            <td>${i.separado_saldo}</td>
            <td>${i.separado_total_unidad}</td>
            <td>${WMS.esc(i.auxiliar||'-')}</td>
            <td>${WMS.esc(i.ubicacion||'-')}</td>
            <td style="font-family:monospace;">${i.hora_fin_linea ? WMS.esc(i.hora_fin_linea.split(/[ T]/)[1]?.slice(0,8)||'-') : '-'}</td>
            <td>${WMS.esc(i.lote||'-')}</td>
            <td>${i.fecha_vencimiento ? WMS.formatDate(i.fecha_vencimiento) : '-'}</td>
            <td><span class="badge badge-info">${WMS.esc(i.linea_estado||'-')}</span></td>
          </tr>`).join('')||'<tr><td colspan="16" class="table-empty">Sin líneas de picking</td></tr>'}
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
    const today = WMS.getToday();
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
    const fecha = document.getElementById('cont-fecha')?.value || WMS.getToday();
    this._abrirReporteHtml(`${API_BASE}/reportes/contingencia/separacion?formato=html&fecha=${fecha}`, 'Separacion_' + fecha);
  },

  abrirCertificacion() {
    const fecha = document.getElementById('cont-fecha')?.value || WMS.getToday();
    this._abrirReporteHtml(`${API_BASE}/reportes/contingencia/certificacion?formato=html&fecha=${fecha}`, 'Certificacion_' + fecha);
  },

  exportarSeparacionCSV() {
    const fecha = document.getElementById('cont-fecha')?.value || WMS.getToday();
    this.exportar(`/reportes/contingencia/separacion?formato=csv&fecha=${fecha}`);
  },

  exportarCertCSV() {
    const fecha = document.getElementById('cont-fecha')?.value || WMS.getToday();
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

  // ── REPORTE MULTIMODAL DE INVENTARIOS CON EXPORTACIÓN A EXCEL ───────────────
  _invMultimodalModo: 'consolidado_referencia',

  show_inventario_multimodal() {
    WMS.setToolbar('');
    const modo = this._invMultimodalModo || 'consolidado_referencia';

    const pillBtn = (id, label, icon) => {
      const active = modo === id;
      return `<button class="btn btn-sm ${active ? 'btn-primary' : 'btn-outline-secondary'}"
        onclick="WMS_MODULES.reportes._setInvMultimodalModo('${id}')"
        style="font-weight:700;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;">
        <i class="fa-solid ${icon}"></i> ${label}
      </button>`;
    };

    WMS.setContent(`
      <div style="background:#fff;padding:20px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
          <div>
            <h2 style="font-size:1.25rem;font-weight:900;color:#0f172a;margin:0 0 4px;display:flex;align-items:center;gap:8px;">
              <i class="fa-solid fa-file-excel" style="color:#10b981;"></i> Reporte Multimodal de Inventarios
            </h2>
            <p style="font-size:.8rem;color:#64748b;margin:0;">
              Opciones de visualización y exportación directa a Excel con desglose en cajas, U/E y unidades reales.
            </p>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="btn btn-success" onclick="WMS_MODULES.reportes._exportarInvMultimodal()" style="font-weight:800;display:flex;align-items:center;gap:6px;">
              <i class="fa-solid fa-file-excel"></i> Exportar a Excel
            </button>
          </div>
        </div>

        <!-- Selector de Modalidad -->
        <div style="display:flex;gap:8px;margin-bottom:16px;background:#f8fafc;padding:6px;border-radius:8px;border:1px solid #e2e8f0;flex-wrap:wrap;">
          ${pillBtn('consolidado_referencia', 'Consolidado por Referencias', 'fa-barcode')}
          ${pillBtn('consolidado_vencimiento', 'Consolidado por Lote y Vencimiento', 'fa-calendar-days')}
          ${pillBtn('por_ubicacion', 'Por Ubicación', 'fa-map-location-dot')}
        </div>

        <!-- Barra de Búsqueda y Filtro -->
        <div style="display:flex;gap:10px;margin-bottom:16px;align-items:center;">
          <div style="position:relative;flex:1;max-width:400px;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem;"></i>
            <input id="rpt-inv-search" class="form-control" style="padding-left:34px;font-size:.85rem;"
              placeholder="Buscar por código, referencia, lote o ubicación..."
              onkeyup="if(event.key==='Enter') WMS_MODULES.reportes._loadInvMultimodal()">
          </div>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.reportes._loadInvMultimodal()" style="font-weight:700;">
            <i class="fa-solid fa-filter"></i> Filtrar
          </button>
          <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('rpt-inv-search').value='';WMS_MODULES.reportes._loadInvMultimodal()" style="font-weight:600;">
            <i class="fa-solid fa-rotate-left"></i> Limpiar
          </button>
        </div>

        <!-- KPIs Resumen -->
        <div id="rpt-inv-kpis" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:10px 14px;border-radius:8px;">
            <div style="font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;">Total Registros</div>
            <div id="kpi-tot-reg" style="font-size:1.3rem;font-weight:900;color:#1e293b;">-</div>
          </div>
          <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:10px 14px;border-radius:8px;">
            <div style="font-size:.68rem;font-weight:700;color:#1e40af;text-transform:uppercase;">Total Cajas</div>
            <div id="kpi-tot-cajas" style="font-size:1.3rem;font-weight:900;color:#1d4ed8;">-</div>
          </div>
          <div style="background:#fefce8;border:1px solid #fef08a;padding:10px 14px;border-radius:8px;">
            <div style="font-size:.68rem;font-weight:700;color:#854d0e;text-transform:uppercase;">Total Sueltos (Saldos)</div>
            <div id="kpi-tot-sueltos" style="font-size:1.3rem;font-weight:900;color:#a16207;">-</div>
          </div>
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:10px 14px;border-radius:8px;">
            <div style="font-size:.68rem;font-weight:700;color:#166534;text-transform:uppercase;">Total Unidades</div>
            <div id="kpi-tot-unidades" style="font-size:1.3rem;font-weight:900;color:#15803d;">-</div>
          </div>
        </div>

        <!-- Contenedor Tabla -->
        <div id="rpt-inv-table-cont" class="table-container" style="max-height:540px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;">
          <div style="text-align:center;padding:40px;"><div class="spinner"></div></div>
        </div>
      </div>
    `);

    this._loadInvMultimodal();
  },

  _setInvMultimodalModo(modo) {
    this._invMultimodalModo = modo;
    this.show_inventario_multimodal();
  },

  async _loadInvMultimodal() {
    const cont = document.getElementById('rpt-inv-table-cont');
    if (!cont) return;

    const modo = this._invMultimodalModo || 'consolidado_referencia';
    const search = document.getElementById('rpt-inv-search')?.value.trim() || '';

    try {
      const res = await API.get('/reportes/inventario-multimodal', `modo=${modo}&search=${encodeURIComponent(search)}`);
      const payload = res.data || {};
      const list = payload.data || [];
      const kpis = payload.resumen || {};

      // Actualizar KPIs
      document.getElementById('kpi-tot-reg').textContent = WMS.formatNum(kpis.total_registros || 0);
      document.getElementById('kpi-tot-cajas').textContent = WMS.formatNum(kpis.total_cajas || 0);
      document.getElementById('kpi-tot-sueltos').textContent = WMS.formatNum(kpis.total_sueltos || 0);
      document.getElementById('kpi-tot-unidades').textContent = WMS.formatNum(kpis.total_unidades || 0);

      if (!list.length) {
        cont.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-box-open fa-2x" style="margin-bottom:8px;opacity:.5;"></i><br>No se encontraron registros de inventario.</div>';
        return;
      }

      let headerHtml = '';
      let rowsHtml = '';

      if (modo === 'consolidado_referencia') {
        headerHtml = `
          <tr>
            <th>CÓDIGO</th>
            <th>REFERENCIA</th>
            <th class="text-center" style="background:#eff6ff;color:#1e40af;">CANTIDAD CAJAS</th>
            <th class="text-center">U/E</th>
            <th class="text-center" style="background:#fefce8;color:#854d0e;">SUELTOS (SALDOS)</th>
            <th class="text-center" style="background:#f0fdf4;color:#166534;">TOTAL UNIDADES</th>
          </tr>`;
        rowsHtml = list.map(r => `
          <tr>
            <td><b>${WMS.esc(r.codigo)}</b></td>
            <td style="font-weight:600;color:#1e293b;">${WMS.esc(r.referencia)}</td>
            <td class="text-center fw-800" style="background:#eff6ff;color:#1d4ed8;font-size:.95rem;">${WMS.formatNum(r.cantidad_cajas)}</td>
            <td class="text-center fw-600">${WMS.formatNum(r.u_e)}</td>
            <td class="text-center fw-700" style="background:#fefce8;color:#a16207;">${WMS.formatNum(r.sueltos)}</td>
            <td class="text-center fw-900" style="background:#f0fdf4;color:#15803d;font-size:1rem;">${WMS.formatNum(r.total_unidades)}</td>
          </tr>`).join('');
      } else if (modo === 'consolidado_vencimiento') {
        headerHtml = `
          <tr>
            <th>CÓDIGO</th>
            <th>REFERENCIA</th>
            <th>LOTE</th>
            <th>F. VENCIMIENTO</th>
            <th class="text-center">DÍAS ÚTILES</th>
            <th class="text-center" style="background:#eff6ff;color:#1e40af;">CANTIDAD CAJAS</th>
            <th class="text-center">U/E</th>
            <th class="text-center" style="background:#fefce8;color:#854d0e;">SUELTOS (SALDOS)</th>
            <th class="text-center" style="background:#f0fdf4;color:#166534;">TOTAL UNIDADES</th>
          </tr>`;
        rowsHtml = list.map(r => {
          const dias = r.dias_vida_util;
          const badgeColor = dias === '—' ? 'badge-secondary' : (dias < 30 ? 'badge-danger' : (dias < 90 ? 'badge-warning' : 'badge-success'));
          return `
            <tr>
              <td><b>${WMS.esc(r.codigo)}</b></td>
              <td style="font-weight:600;color:#1e293b;">${WMS.esc(r.referencia)}</td>
              <td><span class="badge badge-light" style="font-weight:700;">${WMS.esc(r.lote)}</span></td>
              <td>${WMS.formatDate(r.fecha_vencimiento)}</td>
              <td class="text-center"><span class="badge ${badgeColor}" style="font-weight:700;">${dias !== '—' ? dias + 'd' : '—'}</span></td>
              <td class="text-center fw-800" style="background:#eff6ff;color:#1d4ed8;font-size:.95rem;">${WMS.formatNum(r.cantidad_cajas)}</td>
              <td class="text-center fw-600">${WMS.formatNum(r.u_e)}</td>
              <td class="text-center fw-700" style="background:#fefce8;color:#a16207;">${WMS.formatNum(r.sueltos)}</td>
              <td class="text-center fw-900" style="background:#f0fdf4;color:#15803d;font-size:1rem;">${WMS.formatNum(r.total_unidades)}</td>
            </tr>`;
        }).join('');
      } else { // por_ubicacion
        headerHtml = `
          <tr>
            <th>UBICACIÓN</th>
            <th>PASILLO</th>
            <th>CÓDIGO</th>
            <th>REFERENCIA</th>
            <th>LOTE</th>
            <th>F. VENCIMIENTO</th>
            <th class="text-center" style="background:#eff6ff;color:#1e40af;">CANTIDAD CAJAS</th>
            <th class="text-center">U/E</th>
            <th class="text-center" style="background:#fefce8;color:#854d0e;">SUELTOS (SALDOS)</th>
            <th class="text-center" style="background:#f0fdf4;color:#166534;">TOTAL UNIDADES</th>
          </tr>`;
        rowsHtml = list.map(r => `
          <tr>
            <td><span class="badge badge-light-blue" style="font-weight:800;font-size:.82rem;">${WMS.esc(r.ubicacion)}</span></td>
            <td><span class="badge badge-secondary" style="font-weight:700;">${WMS.esc(r.pasillo)}</span></td>
            <td><b>${WMS.esc(r.codigo)}</b></td>
            <td style="font-weight:600;color:#1e293b;">${WMS.esc(r.referencia)}</td>
            <td><span class="badge badge-light" style="font-weight:700;">${WMS.esc(r.lote)}</span></td>
            <td>${WMS.formatDate(r.fecha_vencimiento)}</td>
            <td class="text-center fw-800" style="background:#eff6ff;color:#1d4ed8;font-size:.95rem;">${WMS.formatNum(r.cantidad_cajas)}</td>
            <td class="text-center fw-600">${WMS.formatNum(r.u_e)}</td>
            <td class="text-center fw-700" style="background:#fefce8;color:#a16207;">${WMS.formatNum(r.sueltos)}</td>
            <td class="text-center fw-900" style="background:#f0fdf4;color:#15803d;font-size:1rem;">${WMS.formatNum(r.total_unidades)}</td>
          </tr>`).join('');
      }

      cont.innerHTML = `
        <table class="data-table compact" style="margin:0;">
          <thead style="position:sticky;top:0;z-index:10;background:#f8fafc;">
            ${headerHtml}
          </thead>
          <tbody>
            ${rowsHtml}
          </tbody>
        </table>`;
    } catch(e) {
      cont.innerHTML = `<div class="text-danger" style="padding:20px;">Error cargando reporte: ${WMS.esc(e.message)}</div>`;
    }
  },

  _exportarInvMultimodal() {
    const modo = this._invMultimodalModo || 'consolidado_referencia';
    const search = document.getElementById('rpt-inv-search')?.value.trim() || '';
    const endpoint = `/reportes/inventario-multimodal?modo=${modo}&search=${encodeURIComponent(search)}`;
    this.exportar(endpoint);
  },
};
