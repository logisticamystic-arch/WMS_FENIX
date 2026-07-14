/* ============================================================
   WMS Fénix — Módulo RÓTULOS  v2
   Rótulos de productos y ubicaciones con CODE128 (JsBarcode).
   Formatos: horizontal / vertical.
   Impresión masiva: pasillo, módulo, nivel, ambiente, CEDI.
   ============================================================ */
WMS_MODULES.rotulos = {
  _sub: null,
  _productosCache: [],
  _ubiData: [],          // cache de todas las ubicaciones

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

  // ══════════════════════════════════════════════════════════════
  //  PRODUCTOS
  // ══════════════════════════════════════════════════════════════

  show_productos() {
    WMS.setBreadcrumb('rotulos', 'Rótulos de Producto');
    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-barcode"></i> Rótulos de Producto</h5>
          <span style="font-size:.78rem;color:#64748b;">Seleccione el producto y configure el tamaño del rótulo</span>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:20px;">

          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
            <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
              <i class="fa-solid fa-ruler-combined" style="color:#0F4C81;"></i> Dimensiones del Rótulo
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:500px;">
              <div class="form-group" style="margin:0;">
                <label class="form-label">Ancho (mm)</label>
                <input id="rot-ancho" type="number" class="form-control" value="60" min="20" max="300"
                       oninput="WMS_MODULES.rotulos._actualizarPreviewProd()">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label">Alto (mm)</label>
                <input id="rot-alto" type="number" class="form-control" value="30" min="10" max="300"
                       oninput="WMS_MODULES.rotulos._actualizarPreviewProd()">
              </div>
              <div class="form-group" style="margin:0;">
                <label class="form-label">Copias</label>
                <input id="rot-copias" type="number" class="form-control" value="1" min="1" max="200">
              </div>
            </div>
          </div>

          <!-- Formato -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
            <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
              <i class="fa-solid fa-rotate" style="color:#0F4C81;"></i> Formato del Rótulo
            </div>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
              <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 16px;border:1.5px solid #0F4C81;border-radius:4px;background:#fff;transition:border-color .2s;">
                <input type="radio" name="rot-prod-formato" value="horizontal" checked
                       onchange="WMS_MODULES.rotulos._onFormatoProdChange(this)">
                <div>
                  <div style="font-weight:700;font-size:.82rem;color:#1e293b;">
                    <i class="fa-solid fa-arrows-left-right" style="color:#0F4C81;margin-right:5px;"></i> Horizontal
                  </div>
                  <div style="font-size:.72rem;color:#64748b;">Código de barras apaisado (ancho &gt; alto)</div>
                </div>
              </label>
              <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 16px;border:1.5px solid #e2e8f0;border-radius:4px;background:#fff;transition:border-color .2s;">
                <input type="radio" name="rot-prod-formato" value="vertical"
                       onchange="WMS_MODULES.rotulos._onFormatoProdChange(this)">
                <div>
                  <div style="font-weight:700;font-size:.82rem;color:#1e293b;">
                    <i class="fa-solid fa-arrows-up-down" style="color:#0F4C81;margin-right:5px;"></i> Vertical
                  </div>
                  <div style="font-size:.72rem;color:#64748b;">Código de barras rotado 90° (para etiquetas de estante)</div>
                </div>
              </label>
            </div>
            <div id="rot-prod-formato-hint" style="margin-top:10px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:.78rem;color:#1e40af;display:none;">
              <i class="fa-solid fa-circle-info"></i>
              En formato vertical el código de barras se imprime girado 90°. Recomendado: Ancho 30-50 mm, Alto 80-120 mm.
            </div>
          </div>

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
                border:1px solid #e2e8f0;border-radius:4px;max-height:220px;overflow-y:auto;
                background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.1);
                position:absolute;width:100%;z-index:100;top:2px;">
              </div>
            </div>

            <div id="rot-prod-panel" style="display:none;margin-top:14px;">
              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:12px 16px;margin-bottom:12px;">
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

          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn btn-outline-primary" onclick="WMS_MODULES.rotulos._previsualizarProd()">
              <i class="fa-solid fa-eye"></i> Previsualizar
            </button>
            <button class="btn btn-primary" onclick="WMS_MODULES.rotulos._imprimirProd()">
              <i class="fa-solid fa-desktop"></i> Imprimir (Navegador)
            </button>
            <button class="btn btn-success" onclick="WMS_MODULES.rotulos._imprimirIP('producto')">
              <i class="fa-solid fa-print"></i> Impresión Térmica IP
            </button>
          </div>

          <div id="rot-preview-area" style="display:none;">
            <div style="font-weight:700;font-size:.75rem;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:1px;">
              <i class="fa-solid fa-eye"></i> Vista Previa
            </div>
            <div id="rot-preview-container" style="background:#f1f5f9;padding:20px;border-radius:4px;display:inline-block;border:2px dashed #cbd5e1;"></div>
          </div>

        </div>
      </div>`);
  },

  // ── Búsqueda de producto ──────────────────────────────────────────────────

  _searchTimer: null,
  _selectedProd: null,

  _onBusquedaInput() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this._ejecutarBusqueda(), 350);
  },

  async _ejecutarBusqueda() {
    const input  = document.getElementById('rot-busqueda');
    const q      = input?.value.trim();
    const resDiv = document.getElementById('rot-resultados');
    const listDiv= document.getElementById('rot-resultados-list');
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
          <div style="padding:10px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;"
               onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''"
               onclick="WMS_MODULES.rotulos._seleccionarProducto(${idx})">
            <div style="font-weight:600;color:#1e293b;">${WMS.esc(p.nombre||p.descripcion||'')}</div>
            <div style="font-size:.75rem;color:#64748b;">Cod: ${WMS.esc(p.codigo_interno||'')}${p.ean_principal?' · EAN: '+WMS.esc(p.ean_principal):''}</div>
          </div>`).join('');
      }
      resDiv.style.display = 'block';
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

    const sel = document.getElementById('rot-ean-sel');
    sel.innerHTML = '<option value="">Cargando EANs...</option>';
    try {
      const r    = await API.get('/param/productos/' + p.id);
      const prod = r.data || r || {};
      const eans = prod.eans || [];
      let options = '';
      if (prod.ean_principal)
        options += `<option value="${WMS.esc(prod.ean_principal)}" selected>EAN Principal: ${WMS.esc(prod.ean_principal)}</option>`;
      eans.forEach(e => {
        if (e.codigo_ean !== prod.ean_principal)
          options += `<option value="${WMS.esc(e.codigo_ean)}">${WMS.esc(e.tipo||'EAN')}: ${WMS.esc(e.codigo_ean)}</option>`;
      });
      if (!options)
        options = `<option value="${WMS.esc(prod.codigo_interno||'')}">Código Interno: ${WMS.esc(prod.codigo_interno||'')}</option>`;
      sel.innerHTML = options;
    } catch(e) {
      sel.innerHTML = `<option value="${WMS.esc(p.codigo_interno||'')}">Código: ${WMS.esc(p.codigo_interno||'')}</option>`;
    }
    this._selectedProd = p;
    this._actualizarPreviewProd();
  },

  _onFormatoProdChange(radio) {
    const hint = document.getElementById('rot-prod-formato-hint');
    if (hint) hint.style.display = radio.value === 'vertical' ? 'block' : 'none';
    document.querySelectorAll('input[name="rot-prod-formato"]').forEach(r => {
      const card = r.closest('label');
      if (card) card.style.borderColor = r.checked ? '#0F4C81' : '#e2e8f0';
    });
    this._actualizarPreviewProd();
  },

  _getFormatoProd() {
    return document.querySelector('input[name="rot-prod-formato"]:checked')?.value || 'horizontal';
  },

  _buildRotuloProd(nombre, codigoInterno, ean, anchomm, altomm, formato = 'horizontal') {
    return formato === 'vertical'
      ? this._buildRotuloProdVertical(nombre, codigoInterno, ean, anchomm, altomm)
      : this._buildRotuloProdHorizontal(nombre, codigoInterno, ean, anchomm, altomm);
  },

  _buildRotuloProdHorizontal(nombre, codigoInterno, ean, anchomm, altomm) {
    const textH    = Math.max(4, Math.round(altomm * 0.18));
    const barcodeH = altomm - textH;
    return `
      <div class="wms-label-single" style="width:${anchomm}mm;height:${altomm}mm;
        display:flex;flex-direction:column;
        align-items:center;justify-content:center;
        box-sizing:border-box;
        font-family:Arial,sans-serif;
        background:#fff;overflow:hidden;flex-shrink:0;
        padding:0;margin:0;">
        <svg class="rot-barcode" data-value="${WMS.esc(ean)}"
             data-anchomm="${anchomm}" data-altomm="${altomm}" data-barcodeh="${barcodeH}"
             style="display:block;width:${anchomm}mm;height:${barcodeH}mm;
             shape-rendering:crispEdges;-webkit-print-color-adjust:exact;print-color-adjust:exact;"></svg>
        <div style="font-size:${Math.max(6, Math.min(12, Math.round(textH * 0.7)))}pt;
          font-weight:900;color:#000;text-align:center;
          letter-spacing:2px;font-family:'Courier New',monospace;
          line-height:${textH}mm;height:${textH}mm;
          margin:0;padding:0;width:100%;box-sizing:border-box;">
          ${WMS.esc(ean||'')}
        </div>
      </div>`;
  },

  _buildRotuloProdVertical(nombre, codigoInterno, ean, anchomm, altomm) {
    const textW    = Math.max(6, Math.min(10, Math.round(anchomm * 0.25)));
    const barcodeW = anchomm - textW - 0.5;
    return `
      <div class="wms-label-single" style="width:${anchomm}mm;height:${altomm}mm;
        display:flex;flex-direction:row;
        box-sizing:border-box;
        border:0.5mm solid #000;font-family:Arial,sans-serif;
        background:#fff;overflow:hidden;flex-shrink:0;">
        <div style="width:${textW}mm;height:100%;
          flex-shrink:0;
          display:flex;align-items:center;justify-content:center;
          border-right:0.3mm solid #ccc;
          overflow:hidden;padding:2mm 0;">
          <div style="
            writing-mode:vertical-rl;
            transform:rotate(180deg);
            font-size:min(9pt,calc(${altomm}mm*0.08));
            font-weight:900;
            color:#000;
            letter-spacing:1px;
            font-family:monospace;
            text-align:center;
            white-space:nowrap;
            overflow:hidden;">
            ${WMS.esc(ean||'')}
          </div>
        </div>
        <div style="flex:1;height:100%;
          display:flex;align-items:center;justify-content:center;
          overflow:hidden;position:relative;">
          <svg class="rot-barcode rot-barcode-v"
               data-value="${WMS.esc(ean)}"
               data-vertical="1"
               data-anchomm="${barcodeW}"
               data-altomm="${altomm}">
          </svg>
        </div>
      </div>`;
  },

  _actualizarPreviewProd() {
    const sel  = document.getElementById('rot-ean-sel');
    const ean  = sel?.value;
    if (!ean || !this._selectedProd) return;
    const nombre  = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo  = this._selectedProd.codigo_interno || '';
    const ancho   = parseInt(document.getElementById('rot-ancho')?.value  || 60);
    const alto    = parseInt(document.getElementById('rot-alto')?.value   || 30);
    const formato = this._getFormatoProd();
    const area   = document.getElementById('rot-preview-area');
    const cont   = document.getElementById('rot-preview-container');
    if (!area || !cont) return;
    cont.innerHTML = this._buildRotuloProd(nombre, codigo, ean, ancho, alto, formato);
    this._renderBarcodes(cont);
    area.style.display = 'block';
  },

  _previsualizarProd() {
    const ean = document.getElementById('rot-ean-sel')?.value;
    if (!ean)               { WMS.toast('warning', 'Seleccione un EAN'); return; }
    if (!this._selectedProd){ WMS.toast('warning', 'Seleccione un producto primero'); return; }
    this._actualizarPreviewProd();
    document.getElementById('rot-preview-area')?.scrollIntoView({ behavior:'smooth' });
  },

  _imprimirProd() {
    const ean = document.getElementById('rot-ean-sel')?.value;
    if (!ean)               { WMS.toast('warning', 'Seleccione un EAN para imprimir'); return; }
    if (!this._selectedProd){ WMS.toast('warning', 'Seleccione un producto primero'); return; }
    const nombre  = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo  = this._selectedProd.codigo_interno || '';
    const ancho   = parseInt(document.getElementById('rot-ancho')?.value  || 60);
    const alto    = parseInt(document.getElementById('rot-alto')?.value   || 30);
    const copias  = parseInt(document.getElementById('rot-copias')?.value || 1);
    const formato = this._getFormatoProd();
    const labels  = [];
    for (let i = 0; i < copias; i++) labels.push({ ean, nombre, codigo });
    const html = this._buildDualColumnHTMLProd(labels, ancho, alto, formato);
    this._imprimir(html, ancho, alto, { dualColumn: true });
  },

  // ══════════════════════════════════════════════════════════════
  //  UBICACIONES
  // ══════════════════════════════════════════════════════════════

  async show_ubicaciones() {
    WMS.setBreadcrumb('rotulos', 'Rótulos de Ubicación');
    WMS.spinner();
    try {
      const r    = await API.get('/param/ubicaciones', 'activo=all&limit=2000');
      const ubis = r.data || r.ubicaciones || r || [];
      this._ubiData = ubis;

      const opts = ubis.map(u =>
        `<option value="${u.id}"
           data-codigo="${(u.codigo||'').replace(/"/g,'&quot;')}"
           data-zona="${(u.zona||'').replace(/"/g,'&quot;')}"
           data-tipo="${(u.tipo_ubicacion||'').replace(/"/g,'&quot;')}">
          ${WMS.esc(u.codigo)} — ${WMS.esc(u.zona||'-')} (${WMS.esc(u.tipo_ubicacion||'-')})
        </option>`).join('');

      WMS.setContent(`
        <!-- ── Rótulo individual ─────────────────────────────── -->
        <div class="card animate-fade-in" style="margin-bottom:20px;">
          <div class="card-header">
            <h5 class="card-title"><i class="fa-solid fa-location-dot"></i> Rótulo de Ubicación Individual</h5>
            <span style="font-size:.78rem;color:#64748b;">Código de barras con la dirección de la ubicación</span>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:18px;">

            <!-- Configuración TSC TE200 -->
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:4px;padding:12px 16px;margin-bottom:4px;">
              <div style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:#065f46;">
                <i class="fa-solid fa-print" style="color:#059669;"></i>
                <strong>TSC TE200</strong> — Rollo de 2 etiquetas: 60 mm × 30 mm c/u
              </div>
            </div>

            <!-- Dimensiones y copias -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
              <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
                <i class="fa-solid fa-ruler-combined" style="color:#0F4C81;"></i> Dimensiones del Rótulo
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;max-width:500px;">
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Ancho (mm)</label>
                  <input id="rotub-ancho" type="number" class="form-control" value="60" min="20" max="300"
                         oninput="WMS_MODULES.rotulos._actualizarPreviewUbi()">
                </div>
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Alto (mm)</label>
                  <input id="rotub-alto" type="number" class="form-control" value="30" min="10" max="300"
                         oninput="WMS_MODULES.rotulos._actualizarPreviewUbi()">
                </div>
                <div class="form-group" style="margin:0;">
                  <label class="form-label">Copias</label>
                  <input id="rotub-copias" type="number" class="form-control" value="1" min="1" max="200">
                </div>
              </div>
            </div>

            <!-- Formato -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:16px;">
              <div style="font-weight:700;font-size:.82rem;text-transform:uppercase;color:#475569;margin-bottom:12px;">
                <i class="fa-solid fa-rotate" style="color:#0F4C81;"></i> Formato del Rótulo
              </div>
              <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 16px;border:1.5px solid #e2e8f0;border-radius:4px;background:#fff;transition:border-color .2s;"
                       onmouseover="this.style.borderColor='#0F4C81'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e2e8f0'">
                  <input type="radio" name="rotub-formato" value="horizontal" checked
                         onchange="WMS_MODULES.rotulos._onFormatoChange(this)">
                  <div>
                    <div style="font-weight:700;font-size:.82rem;color:#1e293b;">
                      <i class="fa-solid fa-arrows-left-right" style="color:#0F4C81;margin-right:5px;"></i> Horizontal
                    </div>
                    <div style="font-size:.72rem;color:#64748b;">Código de barras apaisado (ancho &gt; alto)</div>
                  </div>
                </label>
                <label style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 16px;border:1.5px solid #e2e8f0;border-radius:4px;background:#fff;transition:border-color .2s;"
                       onmouseover="this.style.borderColor='#0F4C81'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e2e8f0'">
                  <input type="radio" name="rotub-formato" value="vertical"
                         onchange="WMS_MODULES.rotulos._onFormatoChange(this)">
                  <div>
                    <div style="font-weight:700;font-size:.82rem;color:#1e293b;">
                      <i class="fa-solid fa-arrows-up-down" style="color:#0F4C81;margin-right:5px;"></i> Vertical
                    </div>
                    <div style="font-size:.72rem;color:#64748b;">Código de barras rotado 90° (para etiquetas de estante)</div>
                  </div>
                </label>
              </div>
              <div id="rotub-formato-hint" style="margin-top:10px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:.78rem;color:#1e40af;display:none;">
                <i class="fa-solid fa-circle-info"></i>
                En formato vertical el código de barras se imprime girado 90°. Recomendado: Ancho 30-50 mm, Alto 80-120 mm.
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
                <i class="fa-solid fa-desktop"></i> Imprimir (Navegador)
              </button>
              <button class="btn btn-success" onclick="WMS_MODULES.rotulos._imprimirIP('ubicacion')">
                <i class="fa-solid fa-print"></i> Impresión Térmica IP
              </button>
            </div>

            <!-- Preview -->
            <div id="rotub-preview-area" style="display:none;">
              <div style="font-weight:700;font-size:.75rem;text-transform:uppercase;color:#64748b;margin-bottom:8px;letter-spacing:1px;">
                <i class="fa-solid fa-eye"></i> Vista Previa
              </div>
              <div id="rotub-preview-container" style="background:#f1f5f9;padding:20px;border-radius:4px;display:inline-block;border:2px dashed #cbd5e1;"></div>
            </div>

          </div>
        </div>

        <!-- ── Impresión Masiva ───────────────────────────────── -->
        <div class="card animate-fade-in">
          <div class="card-header">
            <h5 class="card-title"><i class="fa-solid fa-layer-group"></i> Impresión Masiva de Ubicaciones</h5>
            <span style="font-size:.78rem;color:#64748b;">Imprima todas las etiquetas de un pasillo, módulo, nivel o ambiente</span>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">

            <div style="background:#fff8ed;border:1px solid #fde68a;border-radius:4px;padding:10px 14px;font-size:.78rem;color:#92400e;">
              <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;margin-right:6px;"></i>
              La impresión masiva usa las dimensiones y formato configurados arriba en la sección individual.
            </div>

            <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
              <div class="form-group" style="margin:0;min-width:200px;">
                <label class="form-label"><i class="fa-solid fa-filter"></i> Filtrar por</label>
                <select id="rot-mas-tipo" class="form-control" onchange="WMS_MODULES.rotulos._actualizarFiltroMasivo()">
                  <option value="cedi">Todo el CEDI (${ubis.length} ubicaciones)</option>
                  <option value="pasillo">Por Pasillo</option>
                  <option value="modulo">Por Módulo</option>
                  <option value="nivel">Por Nivel</option>
                  <option value="ambiente">Por Ambiente / Zona</option>
                </select>
              </div>
            </div>

            <div id="rot-mas-valor-wrap" style="display:none;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <label class="form-label" style="margin:0;"><i class="fa-solid fa-sliders"></i> Seleccione los valores a imprimir</label>
                <div style="display:flex;gap:6px;">
                  <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.rotulos._selectAllMasivo(true)" style="font-size:.72rem;padding:2px 8px;">
                    <i class="fa-solid fa-check-double"></i> Todos
                  </button>
                  <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.rotulos._selectAllMasivo(false)" style="font-size:.72rem;padding:2px 8px;">
                    <i class="fa-solid fa-xmark"></i> Ninguno
                  </button>
                </div>
              </div>
              <div id="rot-mas-checks" style="display:flex;flex-wrap:wrap;gap:6px;max-height:180px;overflow-y:auto;
                background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:10px;"></div>
            </div>

            <div id="rot-mas-info" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
              <i class="fa-solid fa-location-dot" style="color:#0F4C81;font-size:1.1rem;"></i>
              <span style="font-weight:700;color:#1e293b;" id="rot-mas-count">${ubis.length}</span>
              <span style="color:#64748b;">ubicaciones seleccionadas</span>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button class="btn btn-primary" onclick="WMS_MODULES.rotulos._imprimirMasivo()" style="white-space:nowrap;">
                <i class="fa-solid fa-desktop"></i> Imprimir (Navegador)
              </button>
              <button class="btn btn-success" onclick="WMS_MODULES.rotulos._imprimirIPMasivo()" style="white-space:nowrap;">
                <i class="fa-solid fa-print"></i> Impresión Térmica TSC
              </button>
            </div>

          </div>
        </div>`);

      // Inicializar estado del filtro masivo
      this._actualizarConteoMasivo();

    } catch(e) {
      WMS.toast('error', 'Error cargando ubicaciones');
    }
  },

  // ── Helpers de formato ────────────────────────────────────────────────────

  _onFormatoChange(radio) {
    const hint = document.getElementById('rotub-formato-hint');
    if (hint) hint.style.display = radio.value === 'vertical' ? 'block' : 'none';
    // Highlight selected card
    document.querySelectorAll('input[name="rotub-formato"]').forEach(r => {
      const card = r.closest('label');
      if (card) card.style.borderColor = r.checked ? '#0F4C81' : '#e2e8f0';
    });
    this._actualizarPreviewUbi();
  },

  _getFormatoUbi() {
    return document.querySelector('input[name="rotub-formato"]:checked')?.value || 'horizontal';
  },

  // ── Helpers de análisis de código de ubicación ────────────────────────────

  _parseCodigo(codigo) {
    const parts = (codigo || '').split('-');
    return {
      pasillo: parts[0]  || '',
      modulo:  parts.length >= 2 ? parts.slice(0, 2).join('-') : (parts[0] || ''),
      nivel:   parts[2]  || '',
    };
  },

  _getUniqueValues(tipo) {
    const ubis = this._ubiData;
    let vals;
    if (tipo === 'ambiente') {
      vals = [...new Set(ubis.map(u => u.zona || '').filter(Boolean))];
    } else {
      const parsed = ubis.map(u => this._parseCodigo(u.codigo));
      if (tipo === 'pasillo') vals = [...new Set(parsed.map(p => p.pasillo).filter(Boolean))];
      else if (tipo === 'modulo') vals = [...new Set(parsed.map(p => p.modulo).filter(Boolean))];
      else if (tipo === 'nivel')  vals = [...new Set(parsed.map(p => p.nivel).filter(Boolean))];
      else vals = [];
    }
    return vals.sort();
  },

  _filtrarUbis(tipo, valor) {
    if (!tipo || tipo === 'cedi') return this._ubiData;
    return this._ubiData.filter(u => {
      if (tipo === 'ambiente') return (u.zona || '') === valor;
      const p = this._parseCodigo(u.codigo);
      if (tipo === 'pasillo') return p.pasillo === valor;
      if (tipo === 'modulo')  return p.modulo  === valor;
      if (tipo === 'nivel')   return p.nivel   === valor;
      return true;
    });
  },

  _actualizarFiltroMasivo() {
    const tipo   = document.getElementById('rot-mas-tipo')?.value || 'cedi';
    const wrap   = document.getElementById('rot-mas-valor-wrap');
    const checks = document.getElementById('rot-mas-checks');

    if (tipo === 'cedi') {
      if (wrap) wrap.style.display = 'none';
      this._actualizarConteoMasivo();
      return;
    }

    if (wrap) wrap.style.display = '';
    const values = this._getUniqueValues(tipo);
    if (checks) {
      checks.innerHTML = values.map(v => `
        <label style="cursor:pointer;display:flex;align-items:center;gap:5px;padding:4px 10px;
          background:#fff;border:1px solid #e2e8f0;border-radius:4px;font-size:.8rem;
          font-family:'Courier New',monospace;font-weight:600;color:#1e293b;
          user-select:none;transition:all .15s;"
          onmouseover="this.style.borderColor='#0F4C81'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e2e8f0'">
          <input type="checkbox" class="rot-mas-check" value="${WMS.esc(v)}"
                 onchange="WMS_MODULES.rotulos._onCheckMasivo(this)">
          ${WMS.esc(v)}
        </label>`).join('');
    }
    this._actualizarConteoMasivo();
  },

  _onCheckMasivo(cb) {
    const label = cb.closest('label');
    if (label) {
      label.style.borderColor = cb.checked ? '#0F4C81' : '#e2e8f0';
      label.style.background = cb.checked ? '#eff6ff' : '#fff';
    }
    this._actualizarConteoMasivo();
  },

  _selectAllMasivo(checked) {
    document.querySelectorAll('.rot-mas-check').forEach(cb => {
      cb.checked = checked;
      this._onCheckMasivo(cb);
    });
  },

  _getSelectedValues() {
    return [...document.querySelectorAll('.rot-mas-check:checked')].map(cb => cb.value);
  },

  _actualizarConteoMasivo() {
    const tipo    = document.getElementById('rot-mas-tipo')?.value || 'cedi';
    const countEl = document.getElementById('rot-mas-count');
    if (tipo === 'cedi') {
      if (countEl) countEl.textContent = this._ubiData.length;
      return;
    }
    const selected = this._getSelectedValues();
    const count = selected.length
      ? selected.reduce((sum, val) => sum + this._filtrarUbis(tipo, val).length, 0)
      : 0;
    if (countEl) countEl.textContent = count;
  },

  _getUbisMasivo() {
    const tipo = document.getElementById('rot-mas-tipo')?.value || 'cedi';
    if (tipo === 'cedi') return this._ubiData;
    const selected = this._getSelectedValues();
    if (!selected.length) return [];
    let ubis = [];
    selected.forEach(val => {
      ubis = ubis.concat(this._filtrarUbis(tipo, val));
    });
    return ubis;
  },

  _imprimirMasivo() {
    const ubis = this._getUbisMasivo();
    if (!ubis.length) {
      WMS.toast('warning', 'Seleccione al menos un valor para imprimir');
      return;
    }

    const ancho  = parseInt(document.getElementById('rotub-ancho')?.value || 60);
    const alto   = parseInt(document.getElementById('rotub-alto')?.value  || 30);
    const formato = this._getFormatoUbi();

    WMS.toast('info', `Generando ${ubis.length} rótulo(s)...`);

    const sorted = [...ubis].sort((a, b) => (a.codigo||'').localeCompare(b.codigo||''));
    let html = '';
    sorted.forEach(u => {
      html += this._buildRotuloUbi(u.codigo || '', u.zona || '', u.tipo_ubicacion || '', ancho, alto, formato);
    });
    this._imprimir(html, ancho, alto);
  },

  // ══════════════════════════════════════════════════════════════
  //  DUAL COLUMN — agrupa etiquetas en pares (TSC TE200: 2×50mm)
  // ══════════════════════════════════════════════════════════════

  _buildDualColumnHTML(labels, anchomm, altomm, formato) {
    let html = '';
    for (let i = 0; i < labels.length; i += 2) {
      const left  = labels[i];
      const right = labels[i + 1];
      html += `<div class="wms-label-row" style="display:flex;flex-wrap:nowrap;page-break-after:always;">`;
      html += this._buildRotuloUbi(left.codigo, left.zona, left.tipo, anchomm, altomm, formato);
      if (right) {
        html += this._buildRotuloUbi(right.codigo, right.zona, right.tipo, anchomm, altomm, formato);
      }
      html += `</div>`;
    }
    return html;
  },

  _buildDualColumnHTMLProd(labels, anchomm, altomm, formato) {
    let html = '';
    for (let i = 0; i < labels.length; i += 2) {
      const left  = labels[i];
      const right = labels[i + 1];
      html += `<div class="wms-label-row" style="display:flex;flex-wrap:nowrap;page-break-after:always;">`;
      html += this._buildRotuloProd(left.nombre, left.codigo, left.ean, anchomm, altomm, formato);
      if (right) {
        html += this._buildRotuloProd(right.nombre, right.codigo, right.ean, anchomm, altomm, formato);
      }
      html += `</div>`;
    }
    return html;
  },

  // ══════════════════════════════════════════════════════════════
  //  CONSTRUCCIÓN HTML DE RÓTULOS DE UBICACIÓN
  // ══════════════════════════════════════════════════════════════

  _buildRotuloUbi(codigo, zona, tipo, anchomm, altomm, formato = 'horizontal') {
    return formato === 'vertical'
      ? this._buildRotuloUbiVertical(codigo, zona, tipo, anchomm, altomm)
      : this._buildRotuloUbiHorizontal(codigo, zona, tipo, anchomm, altomm);
  },

  /* ── Horizontal (QR izquierda + texto derecha en 2 líneas) ── */
  _buildRotuloUbiHorizontal(codigo, zona, tipo, anchomm, altomm) {
    const qrSize = Math.round((altomm - 2) * 0.6);
    const textW  = anchomm - qrSize - 2;
    const fontSize1 = Math.max(6, Math.min(11, Math.round(textW * 0.28)));
    const fontSize2 = Math.max(9, Math.min(18, Math.round(textW * 0.42)));
    const ubicacion = (codigo || '').includes('/') ? codigo.split('/').slice(1).join('/') : codigo;
    return `
      <div class="wms-label-single" style="width:${anchomm}mm;height:${altomm}mm;
        display:flex;flex-direction:row;
        align-items:center;justify-content:center;
        box-sizing:border-box;
        font-family:Arial,sans-serif;
        background:#fff;overflow:hidden;flex-shrink:0;
        padding:1mm;margin:0;page-break-after:always;gap:1mm;">
        <div class="rot-qr" data-value="${WMS.esc(codigo)}"
             style="width:${qrSize}mm;height:${qrSize}mm;flex-shrink:0;display:flex;align-items:center;justify-content:center;"></div>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;
          overflow:hidden;text-align:left;padding:0 1mm;">
          <div style="font-size:${fontSize1}pt;font-weight:700;color:#333;
            font-family:'Courier New',monospace;line-height:1.2;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            ${WMS.esc(zona||'')}
          </div>
          <div style="font-size:${fontSize2}pt;font-weight:900;color:#000;
            letter-spacing:1px;font-family:'Courier New',monospace;line-height:1.2;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            ${WMS.esc(ubicacion||'')}
          </div>
        </div>
      </div>`;
  },

  /* ── Vertical (QR arriba + texto abajo en 2 líneas) ── */
  _buildRotuloUbiVertical(codigo, zona, tipo, anchomm, altomm) {
    const qrSize = Math.round(anchomm * 0.35);
    const textH  = altomm - qrSize - 2;
    const fontSize1 = Math.max(5, Math.min(9, Math.round(anchomm * 0.14)));
    const fontSize2 = Math.max(8, Math.min(14, Math.round(anchomm * 0.24)));
    const ubicacion = (codigo || '').includes('/') ? codigo.split('/').slice(1).join('/') : codigo;
    return `
      <div class="wms-label-single" style="width:${anchomm}mm;height:${altomm}mm;
        display:flex;flex-direction:column;
        align-items:center;justify-content:center;
        box-sizing:border-box;
        font-family:Arial,sans-serif;
        background:#fff;overflow:hidden;flex-shrink:0;
        padding:1mm;margin:0;page-break-after:always;gap:1mm;">
        <div class="rot-qr" data-value="${WMS.esc(codigo)}"
             style="width:${qrSize}mm;height:${qrSize}mm;flex-shrink:0;display:flex;align-items:center;justify-content:center;"></div>
        <div style="display:flex;flex-direction:column;justify-content:center;
          text-align:center;width:100%;overflow:hidden;">
          <div style="font-size:${fontSize1}pt;font-weight:700;color:#333;
            font-family:'Courier New',monospace;line-height:1.2;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            ${WMS.esc(zona||'')}
          </div>
          <div style="font-size:${fontSize2}pt;font-weight:900;color:#000;
            letter-spacing:1px;font-family:'Courier New',monospace;line-height:1.2;
            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            ${WMS.esc(ubicacion||'')}
          </div>
        </div>
      </div>`;
  },

  // ══════════════════════════════════════════════════════════════
  //  RENDERIZADO DE QR (ubicaciones)
  // ══════════════════════════════════════════════════════════════

  _renderQRCodes(container) {
    if (typeof qrcode === 'undefined') return;
    container.querySelectorAll('.rot-qr[data-value]').forEach(el => {
      const val = el.dataset.value?.trim();
      if (!val) return;
      const size = el.offsetWidth || el.getBoundingClientRect().width || 100;
      const typeNumber = val.length <= 10 ? 4 : val.length <= 20 ? 6 : 8;
      const qr = qrcode(typeNumber, 'M');
      qr.addData(val);
      qr.make();
      const cellSize = Math.max(2, Math.floor(size / qr.getModuleCount()));
      const margin = Math.floor((size - cellSize * qr.getModuleCount()) / 2);
      const imgTag = qr.createImgTag(cellSize, margin);
      el.innerHTML = imgTag;
      const img = el.querySelector('img');
      if (img) {
        img.style.width = '100%';
        img.style.height = '100%';
        img.style.imageRendering = 'pixelated';
        img.style.display = 'block';
      }
    });
  },

  // ══════════════════════════════════════════════════════════════
  //  RENDERIZADO DE CÓDIGOS DE BARRAS (productos)
  // ══════════════════════════════════════════════════════════════

  _renderBarcodes(container) {
    this._renderQRCodes(container);
    if (typeof JsBarcode === 'undefined') {
      container.querySelectorAll('svg.rot-barcode').forEach(svg => {
        svg.innerHTML = `<text x="5" y="20" font-size="12" fill="#ef4444">[JsBarcode no cargado]</text>`;
      });
      return;
    }

    container.querySelectorAll('svg.rot-barcode[data-value]').forEach(svg => {
      const val        = svg.dataset.value?.trim();
      if (!val) return;
      const isVertical = svg.dataset.vertical === '1';
      const anchomm    = parseFloat(svg.dataset.anchomm || 30);

      try {
        if (isVertical) {
          /* El barcode se genera con height = ancho_etiqueta_en_px (menos padding).
             Tras rotate(-90deg) ese height pasa a ser la anchura visual del barcode,
             que debe caber dentro del ancho de la etiqueta. */
          const px           = 3.7795;   // 1mm ≈ 3.7795 px a 96 dpi
          const targetHeight = Math.round((anchomm - 7) * px); // ancho disponible → altura del svg

          const charCountV = val.length;
          const barWv      = Math.max(1, Math.min(2.5, targetHeight / (charCountV * 11 + 35)));

          JsBarcode(svg, val, {
            format:       'CODE128',
            width:        barWv,
            height:       targetHeight,
            displayValue: false,
            margin:       2,
            background:   '#ffffff',
            lineColor:    '#000000',
          });
          svg.style.shapeRendering = 'crispEdges';

          /* Aplicar rotación */
          svg.style.display         = 'block';
          svg.style.flexShrink      = '0';
          svg.style.transformOrigin = 'center center';
          svg.style.transform       = 'rotate(-90deg)';

          /* Ajuste de margen: cuando svgW > svgH la caja CSS del SVG sobresale
             verticalmente en el contenedor flex. Compensamos con margin para que
             el contenedor con overflow:hidden lo encuadre centrado. */
          const svgW = parseFloat(svg.getAttribute('width')  || '150');
          const svgH = parseFloat(svg.getAttribute('height') || String(targetHeight));
          if (svgW > svgH) {
            const shift = (svgW - svgH) / 2;
            svg.style.marginTop    = shift + 'px';
            svg.style.marginBottom = shift + 'px';
          }

        } else {
          const hAncho     = parseFloat(svg.dataset.anchomm || 50);
          const hAlto      = parseFloat(svg.dataset.altomm  || 70);
          const barcodeHmm = parseFloat(svg.dataset.barcodeh || (hAlto * 0.82));
          const dpi        = 203;
          const pxPerMm    = dpi / 25.4;
          const targetHpx  = Math.round(barcodeHmm * pxPerMm);
          const targetWpx  = Math.round(hAncho * pxPerMm);
          const charCount  = val.length;
          const barW       = Math.max(1, Math.min(3, targetWpx / (charCount * 11 + 35)));

          JsBarcode(svg, val, {
            format:       'CODE128',
            width:        barW,
            height:       targetHpx,
            displayValue: false,
            margin:       0,
            marginTop:    0,
            marginBottom: 0,
            marginLeft:   0,
            marginRight:  0,
            background:   '#ffffff',
            lineColor:    '#000000',
          });

          const bW = parseFloat(svg.getAttribute('width'));
          const bH = parseFloat(svg.getAttribute('height'));
          if (bW && bH) {
            svg.setAttribute('viewBox', `0 0 ${bW} ${bH}`);
            svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            svg.style.width          = hAncho + 'mm';
            svg.style.height         = barcodeHmm + 'mm';
            svg.style.display        = 'block';
            svg.style.margin         = '0 auto';
            svg.style.shapeRendering = 'crispEdges';
          }
        }
      } catch(e) {
        svg.innerHTML = `<text x="5" y="30" font-size="12">${WMS.esc(val)}</text>`;
      }
    });
  },

  // ══════════════════════════════════════════════════════════════
  //  PREVIEW Y IMPRESIÓN
  // ══════════════════════════════════════════════════════════════

  _actualizarPreviewUbi() {
    const sel    = document.getElementById('rotub-sel');
    const opt    = sel?.options[sel.selectedIndex];
    if (!opt?.value) return;
    const codigo  = opt.dataset.codigo || '';
    const zona    = opt.dataset.zona   || '';
    const tipo    = opt.dataset.tipo   || '';
    const ancho   = parseInt(document.getElementById('rotub-ancho')?.value  || 60);
    const alto    = parseInt(document.getElementById('rotub-alto')?.value   || 30);
    const formato = this._getFormatoUbi();

    const area = document.getElementById('rotub-preview-area');
    const cont = document.getElementById('rotub-preview-container');
    if (!area || !cont) return;
    cont.innerHTML = this._buildRotuloUbi(codigo, zona, tipo, ancho, alto, formato);
    this._renderBarcodes(cont);
    area.style.display = 'block';
  },

  _previsualizarUbi() {
    const sel = document.getElementById('rotub-sel');
    if (!sel?.value) { WMS.toast('warning', 'Seleccione una ubicación'); return; }
    this._actualizarPreviewUbi();
    document.getElementById('rotub-preview-area')?.scrollIntoView({ behavior:'smooth' });
  },

  _imprimirUbi() {
    const sel    = document.getElementById('rotub-sel');
    const opt    = sel?.options[sel.selectedIndex];
    if (!opt?.value) { WMS.toast('warning', 'Seleccione una ubicación'); return; }

    const codigo  = opt.dataset.codigo || '';
    const zona    = opt.dataset.zona   || '';
    const tipo    = opt.dataset.tipo   || '';
    const ancho   = parseInt(document.getElementById('rotub-ancho')?.value  || 60);
    const alto    = parseInt(document.getElementById('rotub-alto')?.value   || 30);
    const copias  = parseInt(document.getElementById('rotub-copias')?.value || 1);
    const formato = this._getFormatoUbi();

    const labels = [];
    for (let i = 0; i < copias; i++) labels.push({ codigo, zona, tipo });
    const html = this._buildDualColumnHTML(labels, ancho, alto, formato);
    this._imprimir(html, ancho, alto, { dualColumn: true });
  },

  _imprimir(labelsHTML, anchomm, altomm, opts = {}) {
    document.getElementById('wms-print-area')?.remove();
    document.getElementById('wms-print-style')?.remove();

    const dualCol = opts.dualColumn || false;
    const pageW   = dualCol ? (anchomm * 2) : anchomm;
    const pageH   = altomm;
    const orientation = pageW >= pageH ? 'landscape' : 'portrait';

    const style = document.createElement('style');
    style.id = 'wms-print-style';
    style.textContent = `
      @media print {
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body { margin: 0 !important; padding: 0 !important; }
        body > *:not(#wms-print-area) { display: none !important; }
        #wms-print-area { display: flex !important; flex-wrap: wrap; align-items: flex-start; margin: 0; padding: 0; }
        @page { size: ${pageW}mm ${pageH}mm ${orientation}; margin: 0; padding: 0; }
        .wms-label-row { display: flex !important; flex-wrap: nowrap; page-break-after: always; margin: 0; padding: 0; }
        .wms-label-single {
          display: flex !important;
          align-items: center !important; justify-content: center !important;
          margin: 0 !important; padding: 0 !important;
          overflow: visible !important;
          page-break-after: always !important;
        }
        .rot-barcode {
          shape-rendering: crispEdges !important;
          image-rendering: -webkit-optimize-contrast !important;
          image-rendering: pixelated !important;
        }
        .rot-qr img {
          image-rendering: pixelated !important;
          -webkit-print-color-adjust: exact !important;
          print-color-adjust: exact !important;
        }
      }
    `;
    document.head.appendChild(style);

    const printArea = document.createElement('div');
    printArea.id    = 'wms-print-area';
    printArea.style.display = 'none';
    printArea.innerHTML     = labelsHTML;
    document.body.appendChild(printArea);

    this._renderBarcodes(printArea);

    setTimeout(() => {
      window.print();
      setTimeout(() => { printArea.remove(); style.remove(); }, 600);
    }, 300);
  },

  async _imprimirIP(tipo) {
    let payload = { tipo };
    if (tipo === 'producto') {
      const ean = document.getElementById('rot-ean-sel')?.value;
      if (!ean || !this._selectedProd) return WMS.toast('warning', 'Seleccione producto y EAN');
      payload.nombre = document.getElementById('rot-prod-nombre')?.textContent || '';
      payload.codigo = this._selectedProd.codigo_interno || '';
      payload.ean = ean;
    } else {
      const sel = document.getElementById('rotub-sel');
      const opt = sel?.options[sel.selectedIndex];
      if (!opt?.value) return WMS.toast('warning', 'Seleccione ubicación');
      const copias = parseInt(document.getElementById('rotub-copias')?.value || 1);
      payload.tipo = 'ubicacion';
      payload.codigo = opt.dataset.codigo || '';
      payload.zona   = opt.dataset.zona   || '';
      payload.copias = copias;
    }

    WMS.spinner();
    try {
      const r = await API.post('/impresoras/imprimir-rotulo', payload);
      WMS.toast('success', r.message || 'Impresión enviada correctamente');
    } catch(e) { WMS.toast('error', e.message || 'Error al conectar con el servidor de impresión'); }
  },

  async _imprimirIPMasivo() {
    const ubis = this._getUbisMasivo();
    if (!ubis.length) {
      WMS.toast('warning', 'Seleccione al menos un valor para imprimir');
      return;
    }

    const sorted = [...ubis].sort((a, b) => (a.codigo||'').localeCompare(b.codigo||''));
    const ubicaciones = sorted.map(u => ({ codigo: u.codigo || '', zona: u.zona || '' }));

    WMS.spinner();
    try {
      const r = await API.post('/impresoras/imprimir-rotulo', {
        tipo: 'ubicacion_masivo',
        ubicaciones,
        copias: 1
      });
      WMS.toast('success', r.message || `${ubicaciones.length} rótulo(s) enviados`);
    } catch(e) { WMS.toast('error', e.message || 'Error al conectar con el servidor de impresión'); }
  },
};
