/* ============================================================
   WMS Fénix — Módulo RÓTULOS
   Impresión de rótulos de productos y ubicaciones con código
   de barras CODE128 (JsBarcode). Dimensiones configurables.
   ============================================================ */
WMS_MODULES.rotulos = {
  _sub: null,
  _productosCache: [],

  load(sub) {
    this._sub = sub || 'productos';
    WMS.renderSidebar('rotulos');
    WMS.setBreadcrumb('rotulos', this.subLabel(this._sub));
    const fn = 'show_' + this._sub.replace(/-/g, '_');
    if (typeof this[fn] === 'function') this[fn].call(this);
    else this.show_productos();
  },

  subLabel(sub) {
    return { productos: 'Rótulos de Producto', ubicaciones: 'Rótulos de Ubicación' }[sub] || sub;
  },

  // ── Vista: Rótulos de Producto ────────────────────────────────────────────

  show_productos() {
    WMS.setBreadcrumb('rotulos', 'Rótulos de Producto');
    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-barcode"></i> Rótulos de Producto</h5>
          <span style="font-size:.78rem;color:#64748b;">Seleccione el producto y configure el tamaño del rótulo</span>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:20px;">

          <!-- Configuración de tamaño -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
            <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
              <i class="fa-solid fa-ruler-combined" style="color:#0F4C81;"></i> Dimensiones del Rótulo
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:500px;">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Ancho (mm)</label>
                <input id="rot-ancho" type="number" class="form-control" value="80" min="20" max="300"
                       oninput="WMS_MODULES.rotulos._actualizarPreviewProd()">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label">Alto (mm)</label>
                <input id="rot-alto" type="number" class="form-control" value="50" min="10" max="300"
                       oninput="WMS_MODULES.rotulos._actualizarPreviewProd()">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label">Copias</label>
                <input id="rot-copias" type="number" class="form-control" value="1" min="1" max="200">
              </div>
            </div>
          </div>

          <!-- Búsqueda de producto -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
            <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
              <i class="fa-solid fa-search" style="color:#0F4C81;"></i> Buscar Producto
            </div>
            <div style="display:flex;gap:8px;max-width:500px;">
              <input id="rot-busqueda" type="text" class="form-control" placeholder="EAN, código o descripción..."
                     oninput="WMS_MODULES.rotulos._onBusquedaInput()">
              <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.rotulos._ejecutarBusqueda()">
                <i class="fa-solid fa-search"></i>
              </button>
            </div>
            <div id="rot-resultados" style="display:none;position:relative;max-width:500px;">
              <div id="rot-resultados-list" style="
                border:1px solid #e2e8f0;border-radius:8px;max-height:220px;overflow-y:auto;
                background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.1);
                position:absolute;width:100%;z-index:100;top:2px;">
              </div>
            </div>

            <!-- Producto seleccionado -->
            <div id="rot-prod-panel" style="display:none;margin-top:14px;">
              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:12px;">
                <div style="font-weight:700;color:#1d4ed8;" id="rot-prod-nombre">—</div>
                <div style="font-size:.8rem;color:#64748b;" id="rot-prod-codigo">—</div>
              </div>
              <div class="form-group" style="max-width:400px;">
                <label class="form-label">EAN / Código a imprimir <span class="required">*</span></label>
                <select id="rot-ean-sel" class="form-control" onchange="WMS_MODULES.rotulos._actualizarPreviewProd()">
                  <option value="">Seleccione...</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Acciones -->
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn btn-outline-primary" onclick="WMS_MODULES.rotulos._previsualizarProd()">
              <i class="fa-solid fa-eye"></i> Previsualizar
            </button>
            <button class="btn btn-primary" onclick="WMS_MODULES.rotulos._imprimirProd()">
              <i class="fa-solid fa-print"></i> Imprimir Rótulo
            </button>
          </div>

          <!-- Área de preview -->
          <div id="rot-preview-area" style="display:none;">
            <div style="font-weight:700;font-size:.75rem;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:1px;">
              <i class="fa-solid fa-eye"></i> Vista Previa (escala de pantalla)
            </div>
            <div id="rot-preview-container" style="
              background:#f1f5f9;padding:20px;border-radius:4px;
              display:inline-block;border:2px dashed #cbd5e1;">
            </div>
          </div>

        </div>
      </div>`);
  },

  // ── Vista: Rótulos de Ubicación ───────────────────────────────────────────

  async show_ubicaciones() {
    WMS.setBreadcrumb('rotulos', 'Rótulos de Ubicación');
    WMS.spinner();
    try {
      const r = await API.get('/param/ubicaciones', 'activo=all&limit=1000');
      const ubis = r.data || r.ubicaciones || r || [];
      const opts = ubis.map(u =>
        `<option value="${u.id}"
           data-codigo="${(u.codigo||'').replace(/"/g,'&quot;')}"
           data-zona="${(u.zona||'').replace(/"/g,'&quot;')}"
           data-tipo="${(u.tipo_ubicacion||'').replace(/"/g,'&quot;')}">
          ${WMS.esc(u.codigo)} — Zona: ${WMS.esc(u.zona||'-')} (${WMS.esc(u.tipo_ubicacion||'-')})
        </option>`).join('');

      WMS.setContent(`
        <div class="card animate-fade-in">
          <div class="card-header">
            <h5 class="card-title"><i class="fa-solid fa-location-dot"></i> Rótulos de Ubicación</h5>
            <span style="font-size:.78rem;color:#64748b;">Código de barras con la dirección de la ubicación</span>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:20px;">

            <!-- Dimensiones -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
              <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
                <i class="fa-solid fa-ruler-combined" style="color:#0F4C81;"></i> Dimensiones del Rótulo
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:500px;">
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Ancho (mm)</label>
                  <input id="rotub-ancho" type="number" class="form-control" value="70" min="20" max="300"
                         oninput="WMS_MODULES.rotulos._actualizarPreviewUbi()">
                </div>
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Alto (mm)</label>
                  <input id="rotub-alto" type="number" class="form-control" value="40" min="10" max="300"
                         oninput="WMS_MODULES.rotulos._actualizarPreviewUbi()">
                </div>
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Copias</label>
                  <input id="rotub-copias" type="number" class="form-control" value="1" min="1" max="200">
                </div>
              </div>
            </div>

            <!-- Selección ubicación -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
              <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
                <i class="fa-solid fa-map-pin" style="color:#0F4C81;"></i> Seleccionar Ubicación
              </div>
              <div style="max-width:500px;">
                <select id="rotub-sel" class="form-control" onchange="WMS_MODULES.rotulos._actualizarPreviewUbi()">
                  <option value="">— Seleccione una ubicación —</option>
                  ${opts}
                </select>
              </div>
            </div>

            <!-- Acciones -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button class="btn btn-outline-primary" onclick="WMS_MODULES.rotulos._previsualizarUbi()">
                <i class="fa-solid fa-eye"></i> Previsualizar
              </button>
              <button class="btn btn-primary" onclick="WMS_MODULES.rotulos._imprimirUbi()">
                <i class="fa-solid fa-print"></i> Imprimir Rótulo
              </button>
            </div>

            <!-- Preview -->
            <div id="rotub-preview-area" style="display:none;">
              <div style="font-weight:700;font-size:.75rem;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:1px;">
                <i class="fa-solid fa-eye"></i> Vista Previa
              </div>
              <div id="rotub-preview-container" style="
                background:#f1f5f9;padding:20px;border-radius:4px;
                display:inline-block;border:2px dashed #cbd5e1;">
              </div>
            </div>

          </div>
        </div>`);
    } catch(e) {
      WMS.toast('error', 'Error cargando ubicaciones');
    }
  },

  // ── Búsqueda de producto ──────────────────────────────────────────────────

  _searchTimer: null,
  _selectedProd: null,

  _onBusquedaInput() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this._ejecutarBusqueda(), 350);
  },

  async _ejecutarBusqueda() {
    const input = document.getElementById('rot-busqueda');
    const q = input?.value.trim();
    const resDiv = document.getElementById('rot-resultados');
    const listDiv = document.getElementById('rot-resultados-list');
    if (!resDiv || !listDiv) return;
    if (!q) { resDiv.style.display = 'none'; return; }

    try {
      const r = await API.get('/param/productos/buscar?q=' + encodeURIComponent(q) + '&limit=12');
      const items = r.data || r || [];
      this._productosCache = items;

      if (!items.length) {
        listDiv.innerHTML = '<div style="padding:12px 16px;color:#94a3b8;font-style:italic;">Sin resultados</div>';
      } else {
        listDiv.innerHTML = items.map((p, idx) => `
          <div class="rot-result-item"
               style="padding:10px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
               onmouseover="this.style.background='#f0f9ff'"
               onmouseout="this.style.background=''"
               onclick="WMS_MODULES.rotulos._seleccionarProducto(${idx})">
            <div style="font-weight:600;color:#1e293b;">${WMS.esc(p.nombre||p.descripcion||'')}</div>
            <div style="font-size:.75rem;color:#64748b;">
              Cod: ${WMS.esc(p.codigo_interno||'')}
              ${p.ean_principal ? ' · EAN: '+WMS.esc(p.ean_principal) : ''}
            </div>
          </div>`).join('');
      }
      resDiv.style.display = 'block';

      // Cerrar al hacer click fuera
      document.addEventListener('click', function closeRes(e) {
        if (!e.target.closest('#rot-resultados') && !e.target.closest('#rot-busqueda')) {
          resDiv.style.display = 'none';
          document.removeEventListener('click', closeRes);
        }
      });
    } catch(e) { resDiv.style.display = 'none'; }
  },

  async _seleccionarProducto(idx) {
    const p = this._productosCache[idx];
    if (!p) return;

    document.getElementById('rot-resultados').style.display = 'none';
    document.getElementById('rot-busqueda').value = p.nombre || p.descripcion || '';

    document.getElementById('rot-prod-nombre').textContent = p.nombre || p.descripcion || '';
    document.getElementById('rot-prod-codigo').textContent = 'Código interno: ' + (p.codigo_interno||'');
    document.getElementById('rot-prod-panel').style.display = 'block';

    // Cargar EANs
    const sel = document.getElementById('rot-ean-sel');
    sel.innerHTML = '<option value="">Cargando EANs...</option>';
    try {
      const r = await API.get('/param/productos/' + p.id);
      const prod = r.data || r || {};
      const eans = prod.eans || [];

      let options = '';
      if (prod.ean_principal) {
        options += `<option value="${WMS.esc(prod.ean_principal)}" selected>EAN Principal: ${WMS.esc(prod.ean_principal)}</option>`;
      }
      eans.forEach(e => {
        if (e.codigo_ean !== prod.ean_principal) {
          options += `<option value="${WMS.esc(e.codigo_ean)}">${WMS.esc(e.tipo||'EAN')}: ${WMS.esc(e.codigo_ean)}</option>`;
        }
      });
      if (!options) {
        options = `<option value="${WMS.esc(prod.codigo_interno||'')}">Código Interno: ${WMS.esc(prod.codigo_interno||'')}</option>`;
      }
      sel.innerHTML = options;
    } catch(e) {
      sel.innerHTML = `<option value="${WMS.esc(p.codigo_interno||'')}">Código: ${WMS.esc(p.codigo_interno||'')}</option>`;
    }

    this._selectedProd = p;
    this._actualizarPreviewProd();
  },

  // ── Construcción HTML de rótulos ──────────────────────────────────────────

  _buildRotuloProd(nombre, codigoInterno, ean, anchomm, altomm) {
    const px_ratio = 3.7795; // mm to px approx
    const anchoStyle = anchomm + 'mm';
    const altoStyle = altomm + 'mm';
    return `
      <div style="
        width:${anchoStyle}; min-height:${altoStyle};
        display:flex; flex-direction:column;
        align-items:center; justify-content:space-between;
        padding:3mm; box-sizing:border-box;
        border:0.5mm solid #000;
        font-family:Arial,sans-serif;
        page-break-after:always;
        background:#fff; overflow:hidden;">
        <div style="
          font-size:min(7pt, calc(${anchomm}mm * 0.08));
          font-weight:700; text-align:center;
          color:#000; line-height:1.3;
          max-width:100%; word-break:break-word;
          max-height:30%; overflow:hidden;">
          ${WMS.esc(nombre||'')}
        </div>
        <div style="font-size:min(5.5pt, calc(${anchomm}mm * 0.065)); color:#333; text-align:center; margin:1mm 0;">
          COD: <strong>${WMS.esc(codigoInterno||'')}</strong>
        </div>
        <svg class="rot-barcode" data-value="${WMS.esc(ean)}"
             style="max-width:95%; height:auto; max-height:55%;"></svg>
      </div>`;
  },

  _buildRotuloUbi(codigo, zona, tipo, anchomm, altomm) {
    return `
      <div style="
        width:${anchomm}mm; min-height:${altomm}mm;
        display:flex; flex-direction:column;
        align-items:center; justify-content:space-between;
        padding:3mm; box-sizing:border-box;
        border:0.5mm solid #000;
        font-family:Arial,sans-serif;
        page-break-after:always;
        background:#fff; overflow:hidden;">
        <div style="font-size:min(7pt,calc(${anchomm}mm*0.09)); font-weight:700; color:#000; text-align:center; text-transform:uppercase;">
          ZONA: ${WMS.esc(zona||'')} ${tipo ? '· '+WMS.esc(tipo) : ''}
        </div>
        <svg class="rot-barcode" data-value="${WMS.esc(codigo)}"
             style="max-width:95%; height:auto; max-height:55%;"></svg>
        <div style="
          font-size:min(10pt,calc(${anchomm}mm*0.12));
          font-weight:900; color:#000; text-align:center;
          letter-spacing:2px; font-family:monospace;">
          ${WMS.esc(codigo||'')}
        </div>
      </div>`;
  },

  // ── Renderizar códigos de barras ──────────────────────────────────────────

  _renderBarcodes(container) {
    if (typeof JsBarcode === 'undefined') {
      console.warn('JsBarcode no cargado');
      container.querySelectorAll('svg.rot-barcode').forEach(svg => {
        svg.innerHTML = `<text x="5" y="20" font-size="12" fill="#ef4444">[barcode]</text>`;
      });
      return;
    }
    container.querySelectorAll('svg.rot-barcode[data-value]').forEach(svg => {
      const val = svg.dataset.value?.trim();
      if (!val) return;
      try {
        JsBarcode(svg, val, {
          format: 'CODE128',
          width: 1.5,
          height: 50,
          displayValue: true,
          fontSize: 9,
          margin: 3,
          background: '#ffffff',
          lineColor: '#000000',
          textMargin: 2,
        });
      } catch(e) {
        svg.innerHTML = `<text x="5" y="30" font-size="12">${WMS.esc(val)}</text>`;
      }
    });
  },

  // ── Preview dinámico ──────────────────────────────────────────────────────

  _actualizarPreviewProd() {
    const sel = document.getElementById('rot-ean-sel');
    const ean = sel?.value;
    if (!ean || !this._selectedProd) return;
    const nombre = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo = this._selectedProd.codigo_interno || '';
    const ancho = parseInt(document.getElementById('rot-ancho')?.value || 80);
    const alto  = parseInt(document.getElementById('rot-alto')?.value  || 50);

    const area = document.getElementById('rot-preview-area');
    const cont = document.getElementById('rot-preview-container');
    if (!area || !cont) return;
    cont.innerHTML = this._buildRotuloProd(nombre, codigo, ean, ancho, alto);
    this._renderBarcodes(cont);
    area.style.display = 'block';
  },

  _previsualizarProd() {
    const ean = document.getElementById('rot-ean-sel')?.value;
    if (!ean) { WMS.toast('warning', 'Seleccione un EAN'); return; }
    if (!this._selectedProd) { WMS.toast('warning', 'Seleccione un producto primero'); return; }
    this._actualizarPreviewProd();
    document.getElementById('rot-preview-area')?.scrollIntoView({ behavior:'smooth' });
  },

  _actualizarPreviewUbi() {
    const sel = document.getElementById('rotub-sel');
    const opt = sel?.options[sel.selectedIndex];
    if (!opt?.value) return;
    const codigo = opt.dataset.codigo || '';
    const zona   = opt.dataset.zona   || '';
    const tipo   = opt.dataset.tipo   || '';
    const ancho  = parseInt(document.getElementById('rotub-ancho')?.value || 70);
    const alto   = parseInt(document.getElementById('rotub-alto')?.value  || 40);

    const area = document.getElementById('rotub-preview-area');
    const cont = document.getElementById('rotub-preview-container');
    if (!area || !cont) return;
    cont.innerHTML = this._buildRotuloUbi(codigo, zona, tipo, ancho, alto);
    this._renderBarcodes(cont);
    area.style.display = 'block';
  },

  _previsualizarUbi() {
    const sel = document.getElementById('rotub-sel');
    if (!sel?.value) { WMS.toast('warning', 'Seleccione una ubicación'); return; }
    this._actualizarPreviewUbi();
    document.getElementById('rotub-preview-area')?.scrollIntoView({ behavior:'smooth' });
  },

  // ── Impresión ─────────────────────────────────────────────────────────────

  _imprimirProd() {
    const ean = document.getElementById('rot-ean-sel')?.value;
    if (!ean) { WMS.toast('warning', 'Seleccione un EAN para imprimir'); return; }
    if (!this._selectedProd) { WMS.toast('warning', 'Seleccione un producto primero'); return; }

    const nombre = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo = this._selectedProd.codigo_interno || '';
    const ancho  = parseInt(document.getElementById('rot-ancho')?.value  || 80);
    const alto   = parseInt(document.getElementById('rot-alto')?.value   || 50);
    const copias = parseInt(document.getElementById('rot-copias')?.value || 1);

    let html = '';
    for (let i = 0; i < copias; i++) html += this._buildRotuloProd(nombre, codigo, ean, ancho, alto);
    this._imprimir(html, ancho, alto);
  },

  _imprimirUbi() {
    const sel = document.getElementById('rotub-sel');
    const opt = sel?.options[sel.selectedIndex];
    if (!opt?.value) { WMS.toast('warning', 'Seleccione una ubicación'); return; }

    const codigo = opt.dataset.codigo || '';
    const zona   = opt.dataset.zona   || '';
    const tipo   = opt.dataset.tipo   || '';
    const ancho  = parseInt(document.getElementById('rotub-ancho')?.value  || 70);
    const alto   = parseInt(document.getElementById('rotub-alto')?.value   || 40);
    const copias = parseInt(document.getElementById('rotub-copias')?.value || 1);

    let html = '';
    for (let i = 0; i < copias; i++) html += this._buildRotuloUbi(codigo, zona, tipo, ancho, alto);
    this._imprimir(html, ancho, alto);
  },

  _imprimir(labelsHTML, anchomm, altomm) {
    // Limpiar áreas de impresión previas
    document.getElementById('wms-print-area')?.remove();
    document.getElementById('wms-print-style')?.remove();

    // CSS de impresión con tamaño de página dinámico
    const style = document.createElement('style');
    style.id = 'wms-print-style';
    style.textContent = `
      @media print {
        body > *:not(#wms-print-area) { display: none !important; }
        #wms-print-area {
          display: flex !important;
          flex-wrap: wrap;
          align-items: flex-start;
        }
        @page { size: ${anchomm}mm ${altomm}mm; margin: 0; }
      }
    `;
    document.head.appendChild(style);

    const printArea = document.createElement('div');
    printArea.id = 'wms-print-area';
    printArea.style.display = 'none';
    printArea.innerHTML = labelsHTML;
    document.body.appendChild(printArea);

    // Renderizar códigos de barras en el área de impresión
    this._renderBarcodes(printArea);

    // Pequeño delay para que los SVGs terminen de renderizar
    setTimeout(() => {
      window.print();
      // Cleanup tras cerrar el diálogo de impresión
      setTimeout(() => {
        printArea.remove();
        style.remove();
      }, 600);
    }, 200);
  },
};
