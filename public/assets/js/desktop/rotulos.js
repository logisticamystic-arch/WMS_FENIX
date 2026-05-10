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
              <i class="fa-solid fa-print"></i> Imprimir Rótulo
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

  _buildRotuloProd(nombre, codigoInterno, ean, anchomm, altomm) {
    return `
      <div style="width:${anchomm}mm;min-height:${altomm}mm;display:flex;flex-direction:column;
        align-items:center;justify-content:space-between;padding:3mm;box-sizing:border-box;
        border:0.5mm solid #000;font-family:Arial,sans-serif;page-break-after:always;
        background:#fff;overflow:hidden;">
        <div style="font-size:min(7pt,calc(${anchomm}mm*0.08));font-weight:700;text-align:center;
          color:#000;line-height:1.3;max-width:100%;word-break:break-word;max-height:30%;overflow:hidden;">
          ${WMS.esc(nombre||'')}
        </div>
        <div style="font-size:min(5.5pt,calc(${anchomm}mm*0.065));color:#333;text-align:center;margin:1mm 0;">
          COD: <strong>${WMS.esc(codigoInterno||'')}</strong>
        </div>
        <svg class="rot-barcode" data-value="${WMS.esc(ean)}" style="max-width:95%;height:auto;max-height:55%;"></svg>
      </div>`;
  },

  _actualizarPreviewProd() {
    const sel  = document.getElementById('rot-ean-sel');
    const ean  = sel?.value;
    if (!ean || !this._selectedProd) return;
    const nombre = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo = this._selectedProd.codigo_interno || '';
    const ancho  = parseInt(document.getElementById('rot-ancho')?.value  || 80);
    const alto   = parseInt(document.getElementById('rot-alto')?.value   || 50);
    const area   = document.getElementById('rot-preview-area');
    const cont   = document.getElementById('rot-preview-container');
    if (!area || !cont) return;
    cont.innerHTML = this._buildRotuloProd(nombre, codigo, ean, ancho, alto);
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
    const nombre = document.getElementById('rot-prod-nombre')?.textContent || '';
    const codigo = this._selectedProd.codigo_interno || '';
    const ancho  = parseInt(document.getElementById('rot-ancho')?.value  || 80);
    const alto   = parseInt(document.getElementById('rot-alto')?.value   || 50);
    const copias = parseInt(document.getElementById('rot-copias')?.value || 1);
    let html = '';
    for (let i = 0; i < copias; i++) html += this._buildRotuloProd(nombre, codigo, ean, ancho, alto);
    this._imprimir(html, ancho, alto);
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

            <!-- Dimensiones y copias -->
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
                <i class="fa-solid fa-print"></i> Imprimir Rótulo
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

            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:flex-end;flex-wrap:wrap;">
              <!-- Tipo de agrupación -->
              <div class="form-group" style="margin:0;">
                <label class="form-label"><i class="fa-solid fa-filter"></i> Agrupar por</label>
                <select id="rot-mas-tipo" class="form-control" onchange="WMS_MODULES.rotulos._actualizarFiltroMasivo()">
                  <option value="cedi">Todo el CEDI (${ubis.length} ubicaciones)</option>
                  <option value="pasillo">Por Pasillo</option>
                  <option value="modulo">Por Módulo</option>
                  <option value="nivel">Por Nivel</option>
                  <option value="ambiente">Por Ambiente / Zona</option>
                </select>
              </div>
              <!-- Valor del filtro -->
              <div class="form-group" style="margin:0;" id="rot-mas-valor-wrap" style="display:none;">
                <label class="form-label"><i class="fa-solid fa-sliders"></i> Valor</label>
                <select id="rot-mas-valor" class="form-control" onchange="WMS_MODULES.rotulos._actualizarConteoMasivo()">
                  <option value="">— Seleccione —</option>
                </select>
              </div>
              <!-- Botón -->
              <div>
                <button class="btn btn-primary" onclick="WMS_MODULES.rotulos._imprimirMasivo()" style="white-space:nowrap;">
                  <i class="fa-solid fa-print"></i> Imprimir Masivo
                </button>
              </div>
            </div>

            <!-- Contador de ubicaciones -->
            <div id="rot-mas-info" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
              <i class="fa-solid fa-location-dot" style="color:#0F4C81;font-size:1.1rem;"></i>
              <span style="font-weight:700;color:#1e293b;" id="rot-mas-count">${ubis.length}</span>
              <span style="color:#64748b;">ubicaciones seleccionadas</span>
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
    const tipo     = document.getElementById('rot-mas-tipo')?.value || 'cedi';
    const wrap     = document.getElementById('rot-mas-valor-wrap');
    const valorSel = document.getElementById('rot-mas-valor');

    if (tipo === 'cedi') {
      if (wrap) wrap.style.display = 'none';
      this._actualizarConteoMasivo();
      return;
    }

    if (wrap) wrap.style.display = '';
    const values = this._getUniqueValues(tipo);
    if (valorSel) {
      valorSel.innerHTML = '<option value="">— Seleccione —</option>' +
        values.map(v => `<option value="${WMS.esc(v)}">${WMS.esc(v)}</option>`).join('');
    }
    this._actualizarConteoMasivo();
  },

  _actualizarConteoMasivo() {
    const tipo  = document.getElementById('rot-mas-tipo')?.value || 'cedi';
    const valor = document.getElementById('rot-mas-valor')?.value || '';
    const countEl = document.getElementById('rot-mas-count');
    const count = (tipo === 'cedi' || valor)
      ? this._filtrarUbis(tipo, valor).length
      : 0;
    if (countEl) countEl.textContent = count;
  },

  _imprimirMasivo() {
    const tipo  = document.getElementById('rot-mas-tipo')?.value  || 'cedi';
    const valor = document.getElementById('rot-mas-valor')?.value || '';

    if (tipo !== 'cedi' && !valor) {
      WMS.toast('warning', 'Seleccione un valor de filtro antes de imprimir');
      return;
    }

    const ubis = this._filtrarUbis(tipo, valor);
    if (!ubis.length) {
      WMS.toast('warning', 'No hay ubicaciones con ese filtro');
      return;
    }

    const ancho  = parseInt(document.getElementById('rotub-ancho')?.value || 70);
    const alto   = parseInt(document.getElementById('rotub-alto')?.value  || 40);
    const formato = this._getFormatoUbi();

    WMS.toast('info', `Generando ${ubis.length} rótulo(s)...`);

    // Ordenar: pasillo → módulo → nivel
    const sorted = [...ubis].sort((a, b) => (a.codigo||'').localeCompare(b.codigo||''));

    let html = '';
    sorted.forEach(u => {
      html += this._buildRotuloUbi(u.codigo||'', u.zona||'', u.tipo_ubicacion||'', ancho, alto, formato);
    });

    this._imprimir(html, ancho, alto);
  },

  // ══════════════════════════════════════════════════════════════
  //  CONSTRUCCIÓN HTML DE RÓTULOS DE UBICACIÓN
  // ══════════════════════════════════════════════════════════════

  _buildRotuloUbi(codigo, zona, tipo, anchomm, altomm, formato = 'horizontal') {
    return formato === 'vertical'
      ? this._buildRotuloUbiVertical(codigo, zona, tipo, anchomm, altomm)
      : this._buildRotuloUbiHorizontal(codigo, zona, tipo, anchomm, altomm);
  },

  /* ── Horizontal (layout original mejorado) ── */
  _buildRotuloUbiHorizontal(codigo, zona, tipo, anchomm, altomm) {
    const infoLine = [zona, tipo].filter(Boolean).map(WMS.esc).join(' · ');
    return `
      <div style="width:${anchomm}mm;min-height:${altomm}mm;
        display:flex;flex-direction:column;
        align-items:center;justify-content:space-between;
        padding:3mm;box-sizing:border-box;
        border:0.5mm solid #000;font-family:Arial,sans-serif;
        page-break-after:always;background:#fff;overflow:hidden;">
        ${infoLine ? `<div style="font-size:min(6pt,calc(${anchomm}mm*0.08));color:#555;text-align:center;flex-shrink:0;">${infoLine}</div>` : '<div></div>'}
        <div style="width:100%;flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:1mm 0;">
          <svg class="rot-barcode" data-value="${WMS.esc(codigo)}" data-anchomm="${anchomm}"
               style="display:block;"></svg>
        </div>
        <div style="font-size:min(10pt,calc(${anchomm}mm*0.12));font-weight:900;color:#000;text-align:center;
          letter-spacing:2px;font-family:monospace;flex-shrink:0;">
          ${WMS.esc(codigo||'')}
        </div>
      </div>`;
  },

  /* ── Vertical (código de barras rotado 90° + código en texto vertical) ── */
  _buildRotuloUbiVertical(codigo, zona, tipo, anchomm, altomm) {
    /* Franja izquierda para el texto del código en posición vertical.
       ~25% del ancho, mínimo 6mm, máximo 10mm. */
    const textW    = Math.max(6, Math.min(10, Math.round(anchomm * 0.25)));
    const barcodeW = anchomm - textW - 0.5; // 0.5mm de separador
    return `
      <div style="width:${anchomm}mm;height:${altomm}mm;
        display:flex;flex-direction:row;
        box-sizing:border-box;
        border:0.5mm solid #000;font-family:Arial,sans-serif;
        page-break-after:always;background:#fff;overflow:hidden;">

        <!-- Franja izquierda: código de ubicación en posición vertical -->
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
            ${WMS.esc(codigo||'')}
          </div>
        </div>

        <!-- Área del código de barras vertical (ocupa el resto del ancho) -->
        <div style="flex:1;height:100%;
          display:flex;align-items:center;justify-content:center;
          overflow:hidden;position:relative;">
          <svg class="rot-barcode rot-barcode-v"
               data-value="${WMS.esc(codigo)}"
               data-vertical="1"
               data-anchomm="${barcodeW}"
               data-altomm="${altomm}">
          </svg>
        </div>
      </div>`;
  },

  // ══════════════════════════════════════════════════════════════
  //  RENDERIZADO DE CÓDIGOS DE BARRAS
  // ══════════════════════════════════════════════════════════════

  _renderBarcodes(container) {
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

          JsBarcode(svg, val, {
            format:       'CODE128',
            width:        1.4,          // grosor de barra
            height:       targetHeight, // altura del svg = futura anchura visual
            displayValue: false,        // texto se muestra arriba como código grande
            margin:       2,
            background:   '#ffffff',
            lineColor:    '#000000',
          });

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
          const hAncho = parseFloat(svg.dataset.anchomm || 70);
          /* Grosor de barra adaptativo: más angosto en etiquetas pequeñas */
          const barW   = Math.max(0.8, Math.min(1.5, hAncho * 0.016));
          JsBarcode(svg, val, {
            format:       'CODE128',
            width:        barW,
            height:       44,
            displayValue: true,
            fontSize:     9,
            margin:       3,
            background:   '#ffffff',
            lineColor:    '#000000',
            textMargin:   2,
          });
          /* Hacer el SVG fluido: establecer viewBox y dejar que el contenedor
             padre controle el ancho real via CSS width:100% */
          const bW = svg.getAttribute('width');
          const bH = svg.getAttribute('height');
          if (bW && bH) {
            svg.setAttribute('viewBox', `0 0 ${bW} ${bH}`);
            svg.setAttribute('width', '100%');
            svg.style.maxWidth  = Math.min(parseFloat(bW), hAncho * 3.3) + 'px';
            svg.style.height    = 'auto';
            svg.style.display   = 'block';
            svg.style.margin    = '0 auto';
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
    const ancho   = parseInt(document.getElementById('rotub-ancho')?.value || 70);
    const alto    = parseInt(document.getElementById('rotub-alto')?.value  || 40);
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
    const ancho   = parseInt(document.getElementById('rotub-ancho')?.value  || 70);
    const alto    = parseInt(document.getElementById('rotub-alto')?.value   || 40);
    const copias  = parseInt(document.getElementById('rotub-copias')?.value || 1);
    const formato = this._getFormatoUbi();

    let html = '';
    for (let i = 0; i < copias; i++) html += this._buildRotuloUbi(codigo, zona, tipo, ancho, alto, formato);
    this._imprimir(html, ancho, alto);
  },

  _imprimir(labelsHTML, anchomm, altomm) {
    document.getElementById('wms-print-area')?.remove();
    document.getElementById('wms-print-style')?.remove();

    const style = document.createElement('style');
    style.id = 'wms-print-style';
    style.textContent = `
      @media print {
        body > *:not(#wms-print-area) { display: none !important; }
        #wms-print-area { display: flex !important; flex-wrap: wrap; align-items: flex-start; }
        @page { size: ${anchomm}mm ${altomm}mm; margin: 0; }
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
};
