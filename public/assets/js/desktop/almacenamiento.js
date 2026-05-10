/* ============================================================
   WMS Desktop — Módulo ALMACENAMIENTO  (rev 2)
   Fixes:
   - Mapa: filtros por zona/tipo/estado, capacidad_maxima en vez de capacidad,
     tipo_ubicacion==='Picking' en vez de en_picking,
     celdas ocupadas muestran producto/info
   - Transferir: lote y fecha_vencimiento dinámicos desde stock de origen
   ============================================================ */
WMS_MODULES.almacenamiento = {
  load(sub) {
    WMS.setBreadcrumb('almacenamiento', this.subLabel(sub));
    WMS.renderSidebar('almacenamiento');
    const s  = sub || 'ubicar';
    const fn = { ubicar: this.show_ubicar, transferir: this.show_transferir, mapa: this.show_mapa, informe: this.show_informe };
    (fn[s]?.bind(this) || fn.ubicar.bind(this))();
  },

  subLabel(s) {
    const m = { ubicar: 'Ubicar Mercancía', transferir: 'Transferencia', mapa: 'Ubicación Detalle', informe: 'Informe de Traslados' };
    return m[s] || s || 'Panel';
  },

  // ── UBICAR (PUTAWAY) ─────────────────────────────────────────
  async show_ubicar() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_ubicar()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const r     = await API.get('/putaway/patio');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar producto, EAN..." oninput="WMS_MODULES.almacenamiento.filterTable(this.value,'ub-table')">
          </div>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.almacenamiento.escanearUbicar()"><i class="fa-solid fa-barcode"></i> Escanear EAN</button>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Mercancía Pendiente de Ubicar (${items.length})</span>
          </div>
          <div class="table-container">
            <table class="data-table" id="ub-table">
              <thead><tr><th>Producto</th><th>Descripción</th><th>Cantidad</th><th>Unidad</th><th>Fecha Venc.</th><th>Origen</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(item => `<tr>
                <td style="font-family:monospace;font-size:.75rem;">${WMS.esc(item.codigo_interno || item.ean || item.codigo || '-')}</td>
                <td><strong>${WMS.esc(item.producto_nombre || item.descripcion || item.producto || '-')}</strong></td>
                <td class="text-center">${WMS.formatNum(item.cantidad || 0)}</td>
                <td>${WMS.esc(item.unidad_medida || '-')}</td>
                <td>${WMS.formatDate(item.fecha_vencimiento) || '-'}</td>
                <td>${WMS.esc(item.origen || item.recibo || '-')}</td>
                <td>
                  <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.almacenamiento.asignarUbicacion(${item.id || 0},'${WMS.esc(item.producto_nombre || item.descripcion || '')}', ${item.cantidad||0}, '${WMS.esc(item.lote||'')}', '${item.fecha_vencimiento||''}')">
                    <i class="fa-solid fa-map-pin"></i> Ubicar
                  </button>
                </td>
              </tr>`).join('') || '<tr><td colspan="7" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin mercancía pendiente de ubicar</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  filterTable(q, tableId) {
    const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
    const f    = q.toLowerCase();
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none'; });
  },

  escanearUbicar() {
    WMS.showModal('Escanear Producto para Ubicar', `
      <div style="text-align:center;padding:20px 0;">
        <i class="fa-solid fa-barcode" style="font-size:3rem;color:#3b82f6;opacity:.6;margin-bottom:16px;display:block;"></i>
        <p class="text-muted" style="margin-bottom:16px;">Ingrese o escanee el código EAN del producto</p>
        <input id="scan-ean-ub" class="form-control" style="max-width:300px;margin:0 auto;text-align:center;font-size:1.2rem;letter-spacing:4px;" placeholder="0000000000000" autofocus>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.resolverEAN()"><i class="fa-solid fa-search"></i> Buscar</button>`);
    const inp = document.getElementById('scan-ean-ub');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') this.resolverEAN(); });
  },

  async resolverEAN() {
    const ean = document.getElementById('scan-ean-ub')?.value.trim();
    if (!ean) return;
    try {
      const r = await API.get('/putaway/resolver-ean', 'ean=' + encodeURIComponent(ean));
      if (r.error || !r.producto) { WMS.toast('warning', 'EAN no encontrado: ' + ean); return; }
      const p = r.producto;
      WMS.closeModal('generic-modal');
      this.asignarUbicacion(p.id || 0, p.nombre || p.descripcion || ean);
    } catch (e) { WMS.toast('error', 'Error resolviendo EAN'); }
  },

  async asignarUbicacion(id, nombre, maxCant = 0, lote = '', fv = '') {
    let ubis = [];
    try {
      const ru = await API.get('/param/ubicaciones', 'activo=1&limit=200');
      ubis = ru.data || ru || [];
    } catch (e) {}
    WMS.showModal('Asignar Ubicación: ' + WMS.esc(nombre), `
      <div class="form-grid form-grid-2">
        <div class="form-group" style="grid-column: 1/-1;">
           <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:12px; color:#1e40af; font-weight:600;"><i class="fa-solid fa-info-circle"></i> Cantidad en espera:</span>
              <span style="font-size:16px; color:#1e3a8a; font-weight:800;">${WMS.formatNum(maxCant)}</span>
           </div>
        </div>
        <div class="form-group"><label class="form-label">Ubicación Destino <span class="required">*</span></label>
          <select id="ub-ubicacion" class="form-control" autofocus>
            <option value="">Seleccionar ubicación...</option>
            ${ubis.map(u => `<option value="${u.id}">${WMS.esc(u.codigo || '')} — ${WMS.esc(u.zona || '')} (${WMS.esc(u.tipo_ubicacion || '')})</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">Cantidad a Ubicar <span class="required">*</span></label>
          <input id="ub-cantidad" type="number" class="form-control" min="1" max="${maxCant}" value="${maxCant}" placeholder="0"></div>
        <div class="form-group"><label class="form-label">Lote</label><input id="ub-lote" class="form-control" value="${WMS.esc(lote)}" placeholder="LOT-001"></div>
        <div class="form-group"><label class="form-label">Fecha Vencimiento</label><input id="ub-fv" type="date" class="form-control" value="${fv}"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.confirmarUbicacion(${id}, ${maxCant})"><i class="fa-solid fa-map-pin"></i> Confirmar Ubicación</button>`);
  },

  async confirmarUbicacion(id, maxCant) {
    const ubi_id   = document.getElementById('ub-ubicacion')?.value;
    const cantidad = parseFloat(document.getElementById('ub-cantidad')?.value || 0);
    const lote     = document.getElementById('ub-lote')?.value.trim();
    const fv       = document.getElementById('ub-fv')?.value;

    if (!ubi_id || cantidad <= 0) { WMS.toast('warning', 'Seleccione ubicación y cantidad'); return; }
    if (cantidad > maxCant) { WMS.toast('warning', 'La cantidad no puede superar la disponible ('+maxCant+')'); return; }

    try {
      const r = await API.post('/inventario/traslado', {
        putaway_id:           id, // Enviamos el ID del registro de putaway
        ubicacion_destino_id: parseInt(ubi_id),
        cantidad,
        lote:              lote || null,
        fecha_vencimiento: fv || null,
        tipo:              'Putaway',
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Mercancía ubicada'); WMS.closeModal('generic-modal'); this.show_ubicar(); }
    } catch (e) { WMS.toast('error', 'Error guardando'); }
  },

  // ── TRANSFERIR ───────────────────────────────────────────────
  async show_transferir() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_transferir()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const [stock, ubis] = await Promise.all([
        API.get('/inventario/stock', 'limit=200'),
        API.get('/param/ubicaciones', 'activo=1&limit=200'),
      ]);
      const items      = stock.data || stock || [];
      const listaUbis  = ubis.data || ubis || [];
      const ubisOptions = listaUbis.map(u => `<option value="${u.id}">${WMS.esc(u.codigo || '')} — ${WMS.esc(u.zona || '')} (${WMS.esc(u.tipo_ubicacion || '')})</option>`).join('');

      WMS.setContent(`
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Transferencia de Mercancía</span>
          </div>
          <div class="card-body">
            <div class="form-grid form-grid-2">
              <div class="form-group">
                <label class="form-label">Producto / EAN <span class="required">*</span></label>
                <input id="tr-prod-ac" class="form-control" placeholder="Nombre o EAN...">
                <input type="hidden" id="tr-prod-id">
              </div>
              <div class="form-group">
                <label class="form-label">Cantidad <span class="required">*</span></label>
                <input id="tr-cantidad" type="number" class="form-control" min="1" placeholder="0">
              </div>
              <div class="form-group">
                <label class="form-label">Ubicación Origen</label>
                <select id="tr-origen" class="form-control" onchange="WMS_MODULES.almacenamiento.cargarLotesOrigen()">
                  <option value="">Seleccionar origen (opcional)...</option>
                  ${ubisOptions}
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Ubicación Destino <span class="required">*</span></label>
                <select id="tr-destino" class="form-control">
                  <option value="">Seleccionar destino...</option>
                  ${ubisOptions}
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Lote <span class="text-muted" style="font-weight:400;">(lista del origen al seleccionar)</span></label>
                <select id="tr-lote" class="form-control">
                  <option value="">Sin lote / Todos</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Fecha Vencimiento <span class="text-muted" style="font-weight:400;">(del origen)</span></label>
                <select id="tr-fv" class="form-control">
                  <option value="">Sin fecha específica</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Observaciones</label>
                <input id="tr-obs" class="form-control" placeholder="Motivo del traslado...">
              </div>
            </div>
            <div style="text-align:right;margin-top:16px;">
              <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.ejecutarTraslado()">
                <i class="fa-solid fa-arrow-right-arrow-left"></i> Ejecutar Traslado
              </button>
            </div>
          </div>
        </div>
        <div class="card mt-16">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Stock Disponible para Traslado</span></div>
          <div class="filter-bar"><div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Buscar..." oninput="WMS_MODULES.almacenamiento.filterTable(this.value,'tr-stock-table')"></div></div>
          <div class="table-container">
            <table class="data-table" id="tr-stock-table">
              <thead><tr><th>Producto</th><th>Ubicación</th><th>Cantidad</th><th>Lote</th><th>Vencimiento</th></tr></thead>
              <tbody>${items.slice(0, 50).map(s => `<tr>
                <td>${WMS.esc(s.descripcion || s.producto || '-')}</td>
                <td><span class="badge badge-info">${WMS.esc(s.ubicacion || s.ubicacion_codigo || '-')}</span></td>
                <td class="text-center fw-600">${WMS.formatNum(s.cantidad || 0)}</td>
                <td>${WMS.esc(s.lote || '-')}</td>
                <td>${WMS.formatDate(s.fecha_vencimiento) || '-'}</td>
              </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin stock disponible</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);

      WMS.initProductAutocomplete(document.getElementById('tr-prod-ac'), p => {
        document.getElementById('tr-prod-id').value = p.id;
        WMS_MODULES.almacenamiento.cargarLotesOrigen();
      });
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  // FIX: Carga lotes y fechas de vencimiento disponibles desde el stock de origen
  async cargarLotesOrigen() {
    const prod_id = document.getElementById('tr-prod-id')?.value;
    const orig_id = document.getElementById('tr-origen')?.value;
    const loteEl  = document.getElementById('tr-lote');
    const fvEl    = document.getElementById('tr-fv');
    if (!loteEl || !fvEl) return;

    if (!prod_id || !orig_id) {
      loteEl.innerHTML = '<option value="">Sin lote / Todos</option>';
      fvEl.innerHTML   = '<option value="">Sin fecha específica</option>';
      return;
    }

    try {
      const r     = await API.get('/inventario/stock', `producto_id=${prod_id}&ubicacion_id=${orig_id}&limit=50`);
      const items = r.data || r || [];
      if (!items.length) {
        loteEl.innerHTML = '<option value="">⚠ Sin stock en esta ubicación</option>';
        fvEl.innerHTML   = '<option value="">-</option>';
        WMS.toast('warning', 'No hay stock de ese producto en la ubicación seleccionada');
        return;
      }

      // Poblar lotes (únicos)
      const lotes = [...new Set(items.map(s => s.lote || '').filter(Boolean))];
      loteEl.innerHTML = '<option value="">Sin lote específico</option>' +
        lotes.map(l => {
          const totalLote = items.filter(s => s.lote === l).reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
          return `<option value="${WMS.esc(l)}">${WMS.esc(l)} (${WMS.formatNum(totalLote)} uds)</option>`;
        }).join('');

      // Poblar fechas de vencimiento (únicas, ordenadas)
      const fvs = [...new Set(items.map(s => s.fecha_vencimiento || '').filter(Boolean))].sort();
      fvEl.innerHTML = '<option value="">Sin fecha específica</option>' +
        fvs.map(f => {
          const totalFv = items.filter(s => s.fecha_vencimiento === f).reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
          return `<option value="${WMS.esc(f)}">${WMS.formatDate(f)} (${WMS.formatNum(totalFv)} uds)</option>`;
        }).join('');
    } catch (e) {
      loteEl.innerHTML = '<option value="">Error cargando lotes</option>';
      fvEl.innerHTML   = '<option value="">Error cargando fechas</option>';
    }
  },

  async ejecutarTraslado() {
    const prod_id = document.getElementById('tr-prod-id')?.value;
    const cantidad = parseFloat(document.getElementById('tr-cantidad')?.value || 0);
    const dest_id  = document.getElementById('tr-destino')?.value;
    if (!prod_id)              { WMS.toast('warning', 'Seleccione un producto'); return; }
    if (!cantidad || cantidad <= 0) { WMS.toast('warning', 'Ingrese una cantidad válida'); return; }
    if (!dest_id)              { WMS.toast('warning', 'Seleccione la ubicación destino'); return; }
    const orig_id = document.getElementById('tr-origen')?.value;
    const lote    = document.getElementById('tr-lote')?.value || null;
    const fv      = document.getElementById('tr-fv')?.value || null;
    try {
      const r = await API.post('/inventario/traslado', {
        producto_id:          parseInt(prod_id),
        ubicacion_origen_id:  orig_id ? parseInt(orig_id) : null,
        ubicacion_destino_id: parseInt(dest_id),
        cantidad,
        lote,
        fecha_vencimiento:    fv,
        observaciones:        document.getElementById('tr-obs')?.value.trim() || null,
        tipo:                 'Traslado',
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Traslado ejecutado'); this.show_transferir(); }
    } catch (e) { WMS.toast('error', 'Error ejecutando traslado'); }
  },

  // ── UBICACIÓN DETALLE (TABLA) ──────────────────────────
  _mapaData: null,

  async show_mapa() {
    WMS.setToolbar(`
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="position:relative; flex:1;">
          <i class="fa-solid fa-search" style="position:absolute; left:10px; top:10px; color:#94a3b8;"></i>
          <input id="mapa-prod-ac" class="form-control" style="padding-left:32px; border-radius:4px;" placeholder="Localizar producto o ubicación (Buscador Rápido)..." oninput="WMS_MODULES.almacenamiento.filterMapaTable(this.value)">
        </div>
        <select id="mapa-tipo-f" class="form-control" style="max-width:160px; border-radius:4px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">-- Todos los tipos --</option>
          <option value="Almacenamiento">Almacenamiento</option>
          <option value="Picking">Picking</option>
        </select>
        <select id="mapa-ocup-f" class="form-control" style="max-width:180px; border-radius:4px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">-- Todas las ocupaciones --</option>
          <option value="empty">Vacías (0%)</option>
          <option value="partial">Parciales (>0% y <100%)</option>
          <option value="full">Llenas (100%)</option>
        </select>
        <button class="btn btn-secondary btn-sm" style="border-radius:4px;" onclick="WMS_MODULES.almacenamiento.limpiarFiltrosMapa()"><i class="fa-solid fa-eraser"></i> Limpiar</button>
        <button class="btn btn-secondary btn-sm" style="border-radius:4px;" onclick="WMS_MODULES.almacenamiento.show_mapa()"><i class="fa-solid fa-rotate"></i></button>
      </div>`);
    
    WMS.spinner();
    try {
      const prodId = document.getElementById('mapa-prod-id')?.value || '';
      const r = await API.get('/inventario/mapa-detallado', prodId ? `producto_id=${prodId}` : '');
      this._mapaData = r.data || r || [];

      WMS.setContent(`
        <style>
          /* ADN Visual ERP - Square & Professional */
          .erp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; overflow: hidden; }
          .erp-table { width: 100%; border-collapse: collapse; font-family: 'Inter', sans-serif; }
          .erp-table th { background: #f8f9fa; color: #475569; font-weight: 600; padding: 12px; border-bottom: 2px solid #e2e8f0; text-align: left; }
          .erp-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #334155; vertical-align: middle; transition: background 0.2s; }
          .erp-table tr.main-row:hover { background: #f1f5f9; cursor: pointer; }
          
          /* Master-Detail Layout */
          .md-container { display: flex; position: relative; height: calc(100vh - 160px); overflow: hidden; background: #f8f9fa; }
          .md-master { flex: 1; overflow-y: auto; transition: margin-right 0.3s ease; }
          
          /* Side Panel (Drawer) */
          .md-drawer { 
            position: absolute; right: -40%; top: 0; bottom: 0; width: 40%; background: #fff; 
            border-left: 1px solid #cbd5e1; box-shadow: -4px 0 15px rgba(0,0,0,0.05); 
            transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 100; 
            display: flex; flex-direction: column; 
          }
          .md-drawer.open { right: 0; }
          @media (max-width: 768px) { .md-drawer { width: 100%; right: -100%; } .md-drawer.open { right: 0; } }
          
          .drawer-header { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; }
          .drawer-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin: 0; }
          .drawer-close { background: transparent; border: none; font-size: 1.2rem; color: #64748b; cursor: pointer; }
          .drawer-close:hover { color: #ef4444; }
          .drawer-body { padding: 20px; flex: 1; overflow-y: auto; }
          .drawer-footer { padding: 16px 20px; border-top: 1px solid #e2e8f0; background: #f8f9fa; display: flex; gap: 12px; justify-content: flex-end; }
        </style>

        <div class="md-container">
          <!-- Master View -->
          <div class="md-master erp-card">
            <table class="erp-table" id="mapa-table">
              <thead>
                <tr>
                  <th>Posición</th>
                  <th>Ubicación</th>
                  <th>Tipo</th>
                  <th>% Ocupación</th>
                  <th>Cajas</th>
                  <th>Unidades</th>
                  <th>Próximo Venc.</th>
                  <th>Días sin mov.</th>
                </tr>
              </thead>
              <tbody id="mapa-tbody">
                <!-- Se llena en renderMapa -->
              </tbody>
            </table>
          </div>

          <!-- Side Panel / Drawer View -->
          <div id="ubicacion-drawer" class="md-drawer">
            <div class="drawer-header">
              <h3 class="drawer-title"><i class="fa-solid fa-map-pin" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-ubi-code">Detalle de Ubicación</span></h3>
              <button class="drawer-close" onclick="WMS_MODULES.almacenamiento.closeDrawer()"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="drawer-body" id="drawer-content">
              <div class="text-center text-muted" style="margin-top: 40px;">Seleccione una ubicación para ver el detalle</div>
            </div>
            <div class="drawer-footer" id="drawer-actions" style="display:none;">
               <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.almacenamiento.closeDrawer()">Cerrar</button>
               <button class="btn btn-primary" style="border-radius:4px;" id="btn-trasladar-drawer"><i class="fa-solid fa-arrow-right-arrow-left"></i> Trasladar Stock</button>
            </div>
          </div>
        </div>
      `);

      this.renderMapa();

    } catch (e) {
      WMS.toast('error', 'Error cargando ubicación detalle');
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>');
    }
  },

  limpiarFiltrosMapa() {
    const ac = document.getElementById('mapa-prod-ac');
    if (ac) ac.value = '';
    const id = document.getElementById('mapa-prod-id');
    if (id) id.value = '';
    this.show_mapa();
  },

  filterMapaTable(q) {
    const rows = document.querySelectorAll('#mapa-tbody tr.main-row');
    const f = q.toLowerCase();
    rows.forEach(r => {
      r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none';
    });
  },

  renderMapa() {
    const tbody = document.getElementById('mapa-tbody');
    if (!tbody || !this._mapaData) return;

    const tipoF = document.getElementById('mapa-tipo-f')?.value;
    const ocupF = document.getElementById('mapa-ocup-f')?.value;

    let items = this._mapaData;

    if (tipoF) items = items.filter(i => i.tipo === tipoF);
    if (ocupF === 'empty')   items = items.filter(i => i.ocupacion_pct == 0);
    if (ocupF === 'partial') items = items.filter(i => i.ocupacion_pct > 0 && i.ocupacion_pct < 100);
    if (ocupF === 'full')    items = items.filter(i => i.ocupacion_pct >= 100);

    tbody.innerHTML = items.map(i => {
      let colorClass = 'status-pendiente'; // Naranja (Parcial)
      if (i.ocupacion_pct == 0) colorClass = 'status-cerrada'; // Verde (Vacía)
      if (i.ocupacion_pct >= 100) colorClass = 'status-cancelada'; // Roja (Llena)

      return `
        <tr class="main-row" id="row-${i.id}" onclick="WMS_MODULES.almacenamiento.openDrawer(${i.id}, '${i.ubicacion}')">
          <td>${i.posicion}</td>
          <td style="font-weight:700; color:#1e40af;">${WMS.esc(i.ubicacion)}</td>
          <td><span class="badge ${i.tipo === 'Picking' ? 'badge-success' : 'badge-info'}" style="border-radius:4px;">${WMS.esc(i.tipo)}</span></td>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
               <div style="flex:1; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                  <div style="width:${i.ocupacion_pct}%; height:100%; background:${i.ocupacion_pct >= 100 ? '#ef4444' : (i.ocupacion_pct > 0 ? '#f59e0b' : '#10b981')};"></div>
               </div>
               <span class="status-chip ${colorClass}" style="min-width:50px; text-align:center; border-radius:4px;">${i.ocupacion_pct}%</span>
            </div>
          </td>
          <td class="text-center fw-600" style="color:#059669;">${WMS.formatNum(i.total_cajas || 0)}</td>
          <td class="text-center fw-600" style="color:#1e40af;">${WMS.formatNum(i.total_productos)}</td>
          <td class="text-center">
            ${i.proximo_vencimiento ? `<span class="badge badge-warning" style="border-radius:4px;">${WMS.formatDate(i.proximo_vencimiento)}</span>` : '—'}
          </td>
          <td class="text-center">${i.dias_sin_mov === 'N/A' || i.dias_sin_mov === null ? '—' : i.dias_sin_mov + ' días'}</td>
        </tr>
      `;
    }).join('') || '<tr><td colspan="8" class="table-empty">No se encontraron ubicaciones con los filtros aplicados.</td></tr>';
  },

  closeDrawer() {
    const drawer = document.getElementById('ubicacion-drawer');
    if (drawer) {
      drawer.classList.remove('open');
      // Limpiar selección
      document.querySelectorAll('#mapa-tbody tr').forEach(r => r.style.background = '');
    }
  },

  async openDrawer(id, codigo) {
    const drawer = document.getElementById('ubicacion-drawer');
    const content = document.getElementById('drawer-content');
    const title = document.getElementById('drawer-ubi-code');
    const actions = document.getElementById('drawer-actions');
    const btnTrasladar = document.getElementById('btn-trasladar-drawer');
    
    if (!drawer || !content) return;

    // Highlight row
    document.querySelectorAll('#mapa-tbody tr').forEach(r => r.style.background = '');
    const row = document.getElementById(`row-${id}`);
    if (row) row.style.background = '#e0f2fe';

    // Show Drawer
    title.textContent = codigo;
    drawer.classList.add('open');
    content.innerHTML = `<div style="padding:40px; text-align:center;"><div class="spinner"></div><p style="margin-top:10px; color:#64748b;">Cargando inventario...</p></div>`;
    actions.style.display = 'none';

    try {
      const r = await API.get('/inventario/stock', `ubicacion_id=${id}`);
      const stockItems = r.data || r || [];
      
      if (stockItems.length === 0) {
        content.innerHTML = `<div style="padding:30px; text-align:center; color:#64748b; background:#f1f5f9; border-radius:4px;"><i class="fa-solid fa-box-open" style="font-size:2rem; margin-bottom:10px;"></i><br>Ubicación vacía.</div>`;
        return;
      }

      content.innerHTML = `
        <div style="margin-bottom:16px;">
           <span style="font-size:0.85rem; color:#64748b; text-transform:uppercase; font-weight:600;">Inventario Actual</span>
        </div>
        <div style="display:flex; flex-direction:column; gap:12px;">
          ${stockItems.map(s => {
            const units = parseFloat(s.cantidad || 0);
            const factor = parseInt(s.unidades_caja || 1) || 1;
            const boxes = units / factor;
            const fechaRef = s.last_movement_at || s.created_at;
            const diasEnLoc = fechaRef ? Math.floor((Date.now() - new Date(fechaRef).getTime()) / 86400000) : 0;
            return `
              <div style="border:1px solid #e2e8f0; border-radius:4px; padding:12px; background:#fff; display:flex; flex-direction:column; gap:8px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                  <div style="flex:1;">
                    <div style="font-weight:600; color:#0f172a; line-height:1.3;">${WMS.esc(s.producto_nombre)}</div>
                    <div style="font-family:monospace; font-size:0.8rem; color:#64748b; margin-top:2px;">${WMS.esc(s.codigo_interno)}</div>
                  </div>
                  <div style="text-align:right;">
                     <div style="font-weight:700; color:#1e40af; font-size:1.1rem;">${WMS.formatNum(units)} <span style="font-size:0.75rem; color:#64748b; font-weight:400;">ud</span></div>
                     <div style="font-weight:600; color:#059669; font-size:0.9rem;">${boxes % 1 === 0 ? boxes : boxes.toFixed(2)} <span style="font-size:0.75rem; color:#64748b; font-weight:400;">cx</span></div>
                  </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; background:#f8f9fa; padding:8px; border-radius:4px; font-size:0.85rem; margin-top:4px;">
                   <div><span style="color:#64748b;">Lote:</span> <span style="font-family:monospace; color:#1e293b; font-weight:500;">${WMS.esc(s.lote || '—')}</span></div>
                   <div><span style="color:#64748b;">Venc:</span> <span style="color:#1e293b; font-weight:500;">${WMS.formatDate(s.fecha_vencimiento) || 'N/A'}</span></div>
                   <div style="grid-column: 1/-1;"><span style="color:#64748b;">Días Loc:</span> <span style="color:#1e293b; font-weight:500;">${diasEnLoc} días</span></div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;

      btnTrasladar.onclick = () => WMS_MODULES.almacenamiento.trasladarTodo(id, codigo);
      actions.style.display = 'flex';

    } catch (e) {
      content.innerHTML = `<div class="text-danger" style="padding:20px;"><i class="fa-solid fa-triangle-exclamation"></i> Error al cargar productos.</div>`;
    }
  },

  trasladarTodo(id, codigo) {
    // Redirigir a la pestaña de transferir con el origen preseleccionado
    this.load('transferir');
    setTimeout(() => {
      const select = document.getElementById('tr-origen');
      if (select) {
        select.value = id;
        this.cargarLotesOrigen();
      }
    }, 100);
  },

  // ── INFORME TRASLADOS ─────────────────────────────────────────
  async show_informe() {
    const hoy  = new Date().toISOString().substring(0, 10);
    const hace = new Date(Date.now() - 30 * 86400000).toISOString().substring(0, 10);
    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.almacenamiento.exportarInforme()"><i class="fa-solid fa-file-excel"></i> Exportar</button>`);
    WMS.spinner();
    try {
      const r     = await API.get('/inventario/kardex', `tipo=Traslado&desde=${hace}&hasta=${hoy}&limit=200`);
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar en traslados..." oninput="WMS_MODULES.almacenamiento.filterTable(this.value,'tr-inf-table')">
          </div>
          <input type="date" class="form-control" id="tr-inf-desde" style="max-width:160px;" value="${hace}"
            onchange="WMS_MODULES.almacenamiento.recargarInformeTraslados()">
          <input type="date" class="form-control" id="tr-inf-hasta" style="max-width:160px;" value="${hoy}"
            onchange="WMS_MODULES.almacenamiento.recargarInformeTraslados()">
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-lines"></i> Informe de Traslados (${items.length})</span></div>
          <div class="table-container">
            <table class="data-table" id="tr-inf-table">
              <thead><tr><th>Fecha</th><th>Tipo</th><th>Producto</th><th>Cantidad</th><th>Origen</th><th>Destino</th><th>Usuario</th></tr></thead>
              <tbody>${items.map(m => `<tr>
                <td>${WMS.formatDateTime(m.created_at)}</td>
                <td><span class="badge badge-info">${WMS.esc(m.tipo_movimiento || '-')}</span></td>
                <td class="truncate" style="max-width:200px;">${WMS.esc(m.descripcion || m.producto || '-')}</td>
                <td class="text-center">${WMS.formatNum(m.cantidad || 0)}</td>
                <td>${WMS.esc(m.ubicacion_origen || '-')}</td>
                <td>${WMS.esc(m.ubicacion || m.ubicacion_destino || '-')}</td>
                <td>${WMS.esc(m.usuario || '-')}</td>
              </tr>`).join('') || '<tr><td colspan="7" class="table-empty">Sin traslados registrados</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  async recargarInformeTraslados() {
    const desde = document.getElementById('tr-inf-desde')?.value;
    const hasta = document.getElementById('tr-inf-hasta')?.value;
    if (!desde || !hasta) return;
    try {
      const r     = await API.get('/inventario/kardex', `tipo=Traslado&desde=${desde}&hasta=${hasta}&limit=200`);
      const items = r.data || r || [];
      const tbody = document.querySelector('#tr-inf-table tbody');
      if (tbody) {
        tbody.innerHTML = items.map(m => `<tr>
          <td>${WMS.formatDateTime(m.created_at)}</td>
          <td><span class="badge badge-info">${WMS.esc(m.tipo_movimiento || '-')}</span></td>
          <td class="truncate" style="max-width:200px;">${WMS.esc(m.descripcion || m.producto || '-')}</td>
          <td class="text-center">${WMS.formatNum(m.cantidad || 0)}</td>
          <td>${WMS.esc(m.ubicacion_origen || '-')}</td>
          <td>${WMS.esc(m.ubicacion || m.ubicacion_destino || '-')}</td>
          <td>${WMS.esc(m.usuario || '-')}</td>
        </tr>`).join('') || '<tr><td colspan="7" class="table-empty">Sin traslados registrados</td></tr>';
      }
    } catch (e) { WMS.toast('error', 'Error recargando'); }
  },

  async exportarInforme() {
    const desde = document.getElementById('tr-inf-desde')?.value || '';
    const hasta = document.getElementById('tr-inf-hasta')?.value || '';
    WMS.toast('info', 'Generando reporte...');
    try {
      const params = `tipo=Traslado&format=xlsx${desde ? '&desde=' + desde : ''}${hasta ? '&hasta=' + hasta : ''}`;
      const r = await API.get('/reportes/kardex', params);
      if (r.url) window.open(r.url, '_blank');
      else WMS.toast('info', 'Reporte generado');
    } catch (e) { WMS.toast('error', 'Error exportando'); }
  },
};
