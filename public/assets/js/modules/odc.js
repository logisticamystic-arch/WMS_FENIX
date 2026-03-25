/**
 * Prooriente WMS — Órdenes de Compra (ODC)
 * Lista, creación, edición y búsqueda interactiva de productos.
 */
window.ODC = {

    _currentODC: null, // ODC activa en el modal de detalle

    init() {
        console.log('ODC inicializado');
    },

    // ── HTML del listado principal ────────────────────────────────────────────
    getODCHTML() {
        return `
        <div style="padding:12px;">
            <!-- Encabezado -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <div>
                    <span style="font-weight:700; color:#0f172a; font-size:1rem;">Órdenes de Compra</span>
                    <p style="color:#64748b; font-size:0.78rem; margin:2px 0 0;">Gestión ODC a proveedores</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <button onclick="window.ODC.abrirImportarODC()"
                        style="padding:8px 12px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:8px; font-size:0.82rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-file-import"></i> Importar
                    </button>
                    <button onclick="window.ODC.abrirFormNueva()"
                        style="padding:8px 14px; background:#0f172a; color:white; border:none; border-radius:8px; font-size:0.82rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-plus"></i> Nueva ODC
                    </button>
                </div>
            </div>

            <!-- Filtros -->
            <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                <select id="odc-filtro-estado" onchange="window.ODC.loadODCs()"
                    style="flex:1; min-width:130px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; background:white;">
                    <option value="">Todos los estados</option>
                    <option value="Borrador">Borrador</option>
                    <option value="Confirmada">Confirmada</option>
                    <option value="Cerrada">Cerrada</option>
                    <option value="Cancelada">Cancelada</option>
                </select>
                <input type="text" id="odc-filtro-buscar" placeholder="Buscar número o proveedor..."
                    oninput="window.ODC._debounceSearch()"
                    style="flex:2; min-width:150px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem;">
            </div>

            <!-- Lista -->
            <div id="odc-list">
                <div style="text-align:center; padding:40px; color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i>
                </div>
            </div>
        </div>`;
    },

    // ── Debounce búsqueda ─────────────────────────────────────────────────────
    _debounceTimer: null,
    _debounceSearch() {
        clearTimeout(this._debounceTimer);
        this._debounceTimer = setTimeout(() => this.loadODCs(), 400);
    },

    // ── Cargar lista ──────────────────────────────────────────────────────────
    async loadODCs() {
        const list = document.getElementById('odc-list');
        if (!list) return;

        const estado = document.getElementById('odc-filtro-estado')?.value || '';
        const buscar = document.getElementById('odc-filtro-buscar')?.value || '';

        let url = '/odc?limit=50';
        if (estado) url += `&estado=${encodeURIComponent(estado)}`;
        if (buscar) url += `&buscar=${encodeURIComponent(buscar)}`;

        list.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';

        try {
            const data = await window.api.get(url);
            const odcs = Array.isArray(data) ? data : (data?.data || []);

            if (!odcs.length) {
                list.innerHTML = `<div style="text-align:center; padding:40px; color:#94a3b8;">
                    <i class="fa-solid fa-file-invoice" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                    No hay órdenes de compra registradas
                </div>`;
                return;
            }

            const stateColors = { Borrador:'#64748b', Confirmada:'#3b82f6', Cerrada:'#22c55e', Cancelada:'#dc2626' };

            list.innerHTML = odcs.map(o => {
                const color = stateColors[o.estado] || '#64748b';
                const proveedor = o.proveedor?.razon_social || o.proveedor?.nombre || o.proveedor_nombre || ('Prov. #' + o.proveedor_id);
                return `
                <div style="background:white; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:10px; cursor:pointer; active:background:#f8fafc;"
                    onclick="window.ODC.verDetalle(${parseInt(o.id)})">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                        <span style="font-weight:700; color:#0f172a; font-size:0.92rem;">${escHTML(o.numero_odc) || 'ODC-'+parseInt(o.id)}</span>
                        <span style="font-size:0.7rem; background:${color}20; color:${color}; border-radius:999px; padding:2px 10px; font-weight:700;">${escHTML(o.estado)}</span>
                    </div>
                    <p style="color:#475569; font-size:0.82rem; margin:0 0 8px;">
                        <i class="fa-solid fa-truck-field" style="color:#94a3b8; margin-right:4px;"></i>${escHTML(proveedor)}
                    </p>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:0.75rem; color:#94a3b8;">${escHTML(o.fecha) || ''}</span>
                        <i class="fa-solid fa-chevron-right" style="color:#cbd5e1; font-size:0.85rem;"></i>
                    </div>
                </div>`;
            }).join('');
        } catch (err) {
            list.innerHTML = `<div style="color:#ef4444; padding:20px; text-align:center;">Error al cargar las órdenes de compra.</div>`;
        }
    },

    // ── Ver detalle ───────────────────────────────────────────────────────────
    async verDetalle(id) {
        try {
            const data = await window.api.get(`/odc/${id}`);
            const odc = data?.data || data;
            this._currentODC = odc;
            this._abrirModalDetalle(odc);
        } catch (err) {
            window.Toast?.error(err.message || 'Error al cargar ODC');
        }
    },

    // ── Modal detalle / edición ───────────────────────────────────────────────
    _abrirModalDetalle(odc) {
        document.getElementById('odc-detalle-modal')?.remove();

        const stateColors = { Borrador:'#64748b', Confirmada:'#3b82f6', Cerrada:'#22c55e', Cancelada:'#dc2626' };
        const color = stateColors[odc.estado] || '#64748b';
        const esBorrador = odc.estado === 'Borrador';
        const detalles = odc.detalles || [];
        const proveedor = odc.proveedor?.razon_social || odc.proveedor?.nombre || ('Prov. #' + odc.proveedor_id);

        const modal = document.createElement('div');
        modal.id = 'odc-detalle-modal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9990; display:flex; align-items:flex-start; justify-content:center; padding:16px; overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white; border-radius:16px; width:100%; max-width:600px; margin:auto; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <!-- Header -->
            <div style="padding:16px 20px; background:#0f172a; color:white; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 4px; font-size:1rem;">${escHTML(odc.numero_odc) || 'ODC-'+parseInt(odc.id)}</h3>
                    <span style="font-size:0.7rem; background:${color}50; color:${color}; border-radius:999px; padding:2px 10px; font-weight:700;">${escHTML(odc.estado)}</span>
                </div>
                <button onclick="document.getElementById('odc-detalle-modal').remove()"
                    style="width:32px; height:32px; background:#374151; border:none; border-radius:8px; color:white; cursor:pointer; font-size:1rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Info -->
            <div style="padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div>
                    <div style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:2px;">Proveedor</div>
                    <div style="font-size:0.9rem; color:#0f172a; font-weight:700;">${proveedor}</div>
                </div>
                <div>
                    <div style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:2px;">Fecha</div>
                    <div style="font-size:0.9rem; color:#0f172a;">${odc.fecha || '—'}</div>
                </div>
                ${odc.observaciones ? `<div style="grid-column:span 2;">
                    <div style="font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:2px;">Observaciones</div>
                    <div style="font-size:0.82rem; color:#475569;">${odc.observaciones}</div>
                </div>` : ''}
            </div>

            <!-- Productos -->
            <div style="padding:16px 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <span style="font-weight:700; color:#0f172a; font-size:0.9rem;">Productos <span style="color:#94a3b8; font-weight:400;">(${detalles.length})</span></span>
                    ${esBorrador ? `<button onclick="window.ODC.abrirBuscadorProducto(${odc.id})"
                        style="padding:7px 14px; background:#3b82f6; color:white; border:none; border-radius:8px; font-size:0.8rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-plus"></i> Agregar Producto
                    </button>` : ''}
                </div>

                <div id="odc-detalles-list">
                    ${detalles.length === 0
                        ? `<div style="text-align:center; padding:30px; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:10px;">
                            <i class="fa-solid fa-box-open" style="font-size:1.5rem; margin-bottom:8px; display:block;"></i>
                            ${esBorrador ? 'Usa el botón "Agregar Producto" para comenzar.' : 'Sin líneas de detalle.'}
                           </div>`
                        : detalles.map(d => this._renderDetalleRow(d, esBorrador, odc.id)).join('')
                    }
                </div>
            </div>

            <!-- Acciones -->
            <div style="padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; gap:8px; flex-wrap:wrap;">
                ${esBorrador ? `
                    <button onclick="window.ODC.confirmarODC(${odc.id})"
                        style="flex:1; padding:10px; background:#3b82f6; color:white; border:none; border-radius:8px; font-size:0.85rem; cursor:pointer; font-weight:600; min-width:120px;">
                        <i class="fa-solid fa-check"></i> Confirmar ODC
                    </button>
                    <button onclick="window.ODC.eliminarODC(${odc.id})"
                        style="padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; font-size:0.85rem; color:#dc2626; cursor:pointer;">
                        <i class="fa-solid fa-trash"></i>
                    </button>` : ''}
                ${odc.estado === 'Confirmada' ? `
                    <button onclick="window.ODC.cerrarODC(${odc.id})"
                        style="flex:1; padding:10px; background:#22c55e; color:white; border:none; border-radius:8px; font-size:0.85rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-box-archive"></i> Cerrar ODC
                    </button>` : ''}
            </div>
        </div>`;

        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    // ── Render fila de detalle ────────────────────────────────────────────────
    _renderDetalleRow(d, esBorrador, odcId) {
        const nombre = d.producto?.nombre || d.producto_nombre || ('Producto #' + d.producto_id);
        const codigo = d.producto?.codigo_interno || '';
        return `
        <div style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9;" id="odc-det-row-${d.id}">
            <div style="flex:1; min-width:0;">
                <div style="font-weight:600; font-size:0.88rem; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${nombre}</div>
                ${codigo ? `<div style="font-size:0.73rem; color:#94a3b8;">${codigo}</div>` : ''}
                ${d.cantidad_recibida > 0 ? `<div style="font-size:0.73rem; color:#22c55e;">Recibido: ${d.cantidad_recibida}</div>` : ''}
            </div>
            <div style="text-align:right; flex-shrink:0;">
                ${esBorrador
                    ? `<input type="number" value="${d.cantidad_solicitada}" min="1"
                        onchange="window.ODC._actualizarCantidadLocal(${odcId}, ${d.id}, this.value)"
                        style="width:68px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; text-align:center; font-size:0.9rem; font-weight:700;">`
                    : `<span style="font-weight:700; color:#0f172a; font-size:1rem;">${d.cantidad_solicitada}</span>`
                }
            </div>
            ${esBorrador ? `
            <button onclick="window.ODC._quitarLinea(${odcId}, ${d.id})"
                style="width:32px; height:32px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; cursor:pointer; flex-shrink:0; font-size:0.85rem;">
                <i class="fa-solid fa-trash"></i>
            </button>` : ''}
        </div>`;
    },

    // ── Nueva ODC ─────────────────────────────────────────────────────────────
    async abrirFormNueva() {
        let proveedores = [];
        try {
            const data = await window.api.get('/param/proveedores');
            proveedores = Array.isArray(data) ? data : (data?.data || []);
        } catch (_) {}

        document.getElementById('odc-nueva-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'odc-nueva-modal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9991; display:flex; align-items:center; justify-content:center; padding:16px;';
        modal.innerHTML = `
        <div style="background:white; border-radius:16px; width:100%; max-width:480px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px; background:#0f172a; color:white; display:flex; align-items:center; justify-content:space-between;">
                <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-file-invoice"></i> Nueva Orden de Compra</h3>
                <button onclick="document.getElementById('odc-nueva-modal').remove()"
                    style="width:32px; height:32px; background:#374151; border:none; border-radius:8px; color:white; cursor:pointer; font-size:1rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding:20px;">
                <div style="margin-bottom:14px;">
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:6px; text-transform:uppercase;">Proveedor *</label>
                    <select id="nw-proveedor_id"
                        style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.9rem; background:white; box-sizing:border-box;">
                        <option value="">Seleccionar proveedor...</option>
                        ${proveedores.map(p => `<option value="${p.id}">${p.razon_social || p.nombre || p.id}</option>`).join('')}
                    </select>
                </div>
                <div style="margin-bottom:14px;">
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:6px; text-transform:uppercase;">Número ODC (opcional)</label>
                    <input type="text" id="nw-numero_odc" placeholder="Se genera automáticamente si se deja vacío"
                        style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.88rem; box-sizing:border-box;">
                </div>
                <div style="margin-bottom:14px;">
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:6px; text-transform:uppercase;">Fecha</label>
                    <input type="date" id="nw-fecha" value="${new Date().toISOString().substring(0,10)}"
                        style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.9rem; box-sizing:border-box;">
                </div>
                <div style="margin-bottom:20px;">
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:6px; text-transform:uppercase;">Observaciones</label>
                    <textarea id="nw-observaciones" rows="2" placeholder="Opcional..."
                        style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.88rem; resize:vertical; box-sizing:border-box;"></textarea>
                </div>
                <button onclick="window.ODC._crearODC()"
                    style="width:100%; padding:12px; background:#0f172a; color:white; border:none; border-radius:10px; font-size:0.92rem; cursor:pointer; font-weight:700;">
                    <i class="fa-solid fa-save"></i> Crear Orden de Compra
                </button>
            </div>
        </div>`;

        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    async _crearODC() {
        const proveedor_id = document.getElementById('nw-proveedor_id')?.value;
        if (!proveedor_id) { window.Toast?.error('Selecciona un proveedor'); return; }

        const body = {
            proveedor_id:  parseInt(proveedor_id),
            numero_odc:    document.getElementById('nw-numero_odc')?.value?.trim() || undefined,
            fecha:         document.getElementById('nw-fecha')?.value,
            observaciones: document.getElementById('nw-observaciones')?.value?.trim() || undefined,
        };

        try {
            const data = await window.api.post('/odc', body);
            window.Toast?.success('ODC creada correctamente');
            document.getElementById('odc-nueva-modal')?.remove();
            this.loadODCs();
            const newId = data?.id || data?.data?.id;
            if (newId) setTimeout(() => this.verDetalle(newId), 300);
        } catch (err) {
            window.Toast?.error(err.message || 'Error al crear ODC');
        }
    },

    // ── Modal buscador de productos (bottom sheet) ────────────────────────────
    abrirBuscadorProducto(odcId) {
        document.getElementById('odc-buscador-modal')?.remove();

        const modal = document.createElement('div');
        modal.id = 'odc-buscador-modal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9995; display:flex; align-items:flex-end; justify-content:center;';
        modal.innerHTML = `
        <div style="background:white; border-radius:20px 20px 0 0; width:100%; max-width:600px; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 -10px 40px rgba(0,0,0,0.2);">
            <!-- Handle drag -->
            <div style="padding:10px; display:flex; justify-content:center; flex-shrink:0;">
                <div style="width:40px; height:4px; background:#e2e8f0; border-radius:2px;"></div>
            </div>

            <!-- Encabezado -->
            <div style="padding:0 20px 14px; border-bottom:1px solid #f1f5f9; flex-shrink:0;">
                <h3 style="margin:0 0 12px; font-size:1rem; font-weight:700; color:#0f172a;">
                    <i class="fa-solid fa-magnifying-glass" style="color:#3b82f6;"></i> Buscar Producto
                </h3>
                <div style="position:relative;">
                    <i class="fa-solid fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none;"></i>
                    <input type="text" id="prod-search-input"
                        placeholder="Nombre, código interno o EAN..."
                        oninput="window.ODC._buscarProductoDebounce(${odcId})"
                        style="width:100%; padding:12px 12px 12px 40px; border:2px solid #3b82f6; border-radius:12px; font-size:0.95rem; outline:none; box-sizing:border-box; background:#f0f9ff;">
                </div>
            </div>

            <!-- Resultados (scroll) -->
            <div id="prod-search-results" style="flex:1; overflow-y:auto; padding:12px 20px; min-height:100px;">
                <div style="text-align:center; color:#94a3b8; padding:40px 0;">
                    <i class="fa-solid fa-magnifying-glass" style="font-size:2.5rem; margin-bottom:10px; display:block; opacity:0.3;"></i>
                    <p style="margin:0; font-size:0.88rem;">Escribe para buscar productos</p>
                </div>
            </div>

            <!-- Footer -->
            <div style="padding:14px 20px; border-top:1px solid #f1f5f9; flex-shrink:0;">
                <button onclick="document.getElementById('odc-buscador-modal').remove()"
                    style="width:100%; padding:12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:10px; font-size:0.9rem; color:#64748b; cursor:pointer; font-weight:600;">
                    Cancelar
                </button>
            </div>
        </div>`;

        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
        setTimeout(() => document.getElementById('prod-search-input')?.focus(), 250);
    },

    // ── Debounce búsqueda de productos ────────────────────────────────────────
    _searchTimer: null,
    _buscarProductoDebounce(odcId) {
        clearTimeout(this._searchTimer);
        this._searchTimer = setTimeout(() => this._ejecutarBusqueda(odcId), 320);
    },

    // ── Ejecutar búsqueda ─────────────────────────────────────────────────────
    async _ejecutarBusqueda(odcId) {
        const q = document.getElementById('prod-search-input')?.value?.trim();
        const resultsEl = document.getElementById('prod-search-results');
        if (!resultsEl) return;

        if (!q || q.length < 2) {
            resultsEl.innerHTML = `<div style="text-align:center; color:#94a3b8; padding:40px 0; font-size:0.88rem;">Escribe mínimo 2 caracteres para buscar</div>`;
            return;
        }

        resultsEl.innerHTML = `<div style="text-align:center; padding:30px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>`;

        try {
            const data = await window.api.get('/odc/buscar-producto?q=' + encodeURIComponent(q));
            const productos = Array.isArray(data) ? data : (data?.data || []);

            if (!productos.length) {
                resultsEl.innerHTML = `<div style="text-align:center; padding:40px; color:#94a3b8;">
                    <i class="fa-solid fa-box-open" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                    Sin resultados para <strong style="color:#475569;">"${q}"</strong>
                </div>`;
                return;
            }

            resultsEl.innerHTML = `<p style="font-size:0.75rem; color:#94a3b8; margin:0 0 10px;">${productos.length} resultado${productos.length !== 1 ? 's' : ''}</p>` +
                productos.map(p => `
                <div style="display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:8px; cursor:pointer; transition:all 0.15s; background:white;"
                    onmouseover="this.style.background='#eff6ff'; this.style.borderColor='#3b82f6';"
                    onmouseout="this.style.background='white'; this.style.borderColor='#e2e8f0';"
                    onclick="window.ODC._seleccionarProducto(${odcId}, ${p.id}, '${(p.nombre || '').replace(/'/g, "\\'")}')">
                    <div style="width:44px; height:44px; background:#eff6ff; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fa-solid fa-box" style="color:#3b82f6; font-size:1.1rem;"></i>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:700; font-size:0.9rem; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${p.nombre}</div>
                        <div style="font-size:0.73rem; color:#94a3b8; margin-top:2px;">
                            ${p.codigo_interno ? `<span style="background:#f1f5f9; border-radius:4px; padding:1px 6px; margin-right:4px; color:#64748b;">${p.codigo_interno}</span>` : ''}
                            ${p.unidad_medida ? `<span>${p.unidad_medida}</span>` : ''}
                        </div>
                    </div>
                    <div style="width:36px; height:36px; background:#3b82f6; color:white; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                </div>`).join('');
        } catch (err) {
            resultsEl.innerHTML = `<div style="color:#ef4444; padding:20px; text-align:center; font-size:0.88rem;">${err.message || 'Error en la búsqueda'}</div>`;
        }
    },

    // ── Seleccionar producto → pedir cantidad ─────────────────────────────────
    _seleccionarProducto(odcId, productoId, productoNombre) {
        document.getElementById('odc-cantidad-overlay')?.remove();

        const overlay = document.createElement('div');
        overlay.id = 'odc-cantidad-overlay';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; display:flex; align-items:center; justify-content:center; padding:20px;';
        overlay.innerHTML = `
        <div style="background:white; border-radius:18px; padding:26px; width:100%; max-width:300px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="width:56px; height:56px; background:#eff6ff; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <i class="fa-solid fa-cubes" style="font-size:1.5rem; color:#3b82f6;"></i>
            </div>
            <h4 style="margin:0 0 4px; color:#0f172a; font-size:0.95rem;">${productoNombre}</h4>
            <p style="color:#64748b; font-size:0.8rem; margin:0 0 18px;">¿Cuántas unidades?</p>
            <input type="number" id="odc-cant-input" value="1" min="1"
                style="width:100%; padding:14px; border:2px solid #3b82f6; border-radius:10px; font-size:1.5rem; text-align:center; margin-bottom:16px; box-sizing:border-box; font-weight:700; outline:none;"
                onkeydown="if(event.key==='Enter') window.ODC._confirmarAgregarProducto(${odcId}, ${productoId})">
            <div style="display:flex; gap:10px;">
                <button onclick="document.getElementById('odc-cantidad-overlay').remove()"
                    style="flex:1; padding:12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:10px; font-size:0.88rem; color:#64748b; cursor:pointer;">
                    Cancelar
                </button>
                <button onclick="window.ODC._confirmarAgregarProducto(${odcId}, ${productoId})"
                    style="flex:2; padding:12px; background:#3b82f6; color:white; border:none; border-radius:10px; font-size:0.88rem; cursor:pointer; font-weight:700;">
                    <i class="fa-solid fa-plus"></i> Agregar
                </button>
            </div>
        </div>`;

        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
        setTimeout(() => {
            const inp = document.getElementById('odc-cant-input');
            if (inp) { inp.focus(); inp.select(); }
        }, 150);
    },

    // ── Confirmar agregar producto a la ODC ───────────────────────────────────
    async _confirmarAgregarProducto(odcId, productoId) {
        const cant = parseInt(document.getElementById('odc-cant-input')?.value) || 1;
        if (cant < 1) { window.Toast?.error('Cantidad inválida'); return; }

        // Construir nueva lista de detalles: actuales + nuevo
        const odc = this._currentODC;
        const detallesActuales = (odc?.detalles || []).map(d => ({
            producto_id: d.producto_id,
            cantidad: d.cantidad_solicitada,
            cantidad_recibida: d.cantidad_recibida || 0,
        }));
        const nuevosDetalles = [...detallesActuales, { producto_id: productoId, cantidad: cant }];

        try {
            await window.api.put(`/odc/${odcId}`, { detalles: nuevosDetalles });
            window.Toast?.success('Producto agregado');
            document.getElementById('odc-cantidad-overlay')?.remove();
            document.getElementById('odc-buscador-modal')?.remove();
            document.getElementById('odc-detalle-modal')?.remove();
            setTimeout(() => this.verDetalle(odcId), 200);
        } catch (err) {
            window.Toast?.error(err.message || 'Error al agregar producto');
        }
    },

    // ── Actualizar cantidad de línea (in-place) ───────────────────────────────
    async _actualizarCantidadLocal(odcId, detalleId, nuevaCantidad) {
        const odc = this._currentODC;
        if (!odc) return;
        const detalles = (odc.detalles || []).map(d => ({
            producto_id: d.producto_id,
            cantidad: d.id === detalleId ? parseInt(nuevaCantidad) : d.cantidad_solicitada,
            cantidad_recibida: d.cantidad_recibida || 0,
        }));
        try {
            await window.api.put(`/odc/${odcId}`, { detalles });
            window.Toast?.success('Cantidad actualizada');
            // Actualizar estado local
            const det = odc.detalles.find(d => d.id === detalleId);
            if (det) det.cantidad_solicitada = parseInt(nuevaCantidad);
        } catch (err) {
            window.Toast?.error(err.message || 'Error');
        }
    },

    // ── Quitar línea ──────────────────────────────────────────────────────────
    async _quitarLinea(odcId, detalleId) {
        const odc = this._currentODC;
        if (!odc) return;
        const detalles = (odc.detalles || [])
            .filter(d => d.id !== detalleId)
            .map(d => ({
                producto_id: d.producto_id,
                cantidad: d.cantidad_solicitada,
                cantidad_recibida: d.cantidad_recibida || 0,
            }));
        try {
            await window.api.put(`/odc/${odcId}`, { detalles });
            document.getElementById(`odc-det-row-${detalleId}`)?.remove();
            odc.detalles = odc.detalles.filter(d => d.id !== detalleId);
            window.Toast?.success('Línea eliminada');
        } catch (err) {
            window.Toast?.error(err.message || 'Error');
        }
    },

    // ── Confirmar ODC ─────────────────────────────────────────────────────────
    async confirmarODC(id) {
        try {
            await window.api.post(`/odc/${id}/confirmar`, {});
            window.Toast?.success('ODC confirmada exitosamente');
            document.getElementById('odc-detalle-modal')?.remove();
            this.loadODCs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error al confirmar');
        }
    },

    // ── Cerrar ODC ────────────────────────────────────────────────────────────
    async cerrarODC(id) {
        try {
            await window.api.post(`/odc/${id}/cerrar`, {});
            window.Toast?.success('ODC cerrada');
            document.getElementById('odc-detalle-modal')?.remove();
            this.loadODCs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error al cerrar');
        }
    },

    // ── Eliminar ODC (solo Admin) ─────────────────────────────────────────────
    async eliminarODC(id) {
        if (!confirm('¿Eliminar esta ODC? Esta acción no se puede deshacer.')) return;
        try {
            await window.api.delete(`/odc/${id}`);
            window.Toast?.success('ODC eliminada');
            document.getElementById('odc-detalle-modal')?.remove();
            this.loadODCs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error al eliminar');
        }
    },

    // ── Importar ODC desde CSV ────────────────────────────────────────────────
    abrirImportarODC() {
        document.getElementById('odc-import-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'odc-import-modal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9992; display:flex; align-items:center; justify-content:center; padding:16px;';
        modal.innerHTML = `
        <div style="background:white; border-radius:16px; width:100%; max-width:480px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px; background:#0f172a; color:white; display:flex; align-items:center; justify-content:space-between;">
                <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-file-import"></i> Importar ODC desde CSV</h3>
                <button onclick="document.getElementById('odc-import-modal').remove()"
                    style="width:32px; height:32px; background:#374151; border:none; border-radius:8px; color:white; cursor:pointer; font-size:1rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding:20px;">
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px; margin-bottom:16px; font-size:0.82rem; color:#166534;">
                    <strong><i class="fa-solid fa-circle-info"></i> Formato CSV requerido (separador ; o ,):</strong><br>
                    <code style="display:block; margin-top:6px; font-size:0.78rem;">numero_odc;proveedor_id;fecha;codigo_interno;cantidad</code>
                    <code style="display:block; font-size:0.78rem;">ODC-001;5;2026-03-24;PROD001;100</code>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:0.75rem; font-weight:700; color:#475569; display:block; margin-bottom:6px; text-transform:uppercase;">Archivo CSV *</label>
                    <input type="file" id="odc-import-file" accept=".csv,.txt"
                        style="width:100%; padding:10px 12px; border:2px dashed #cbd5e1; border-radius:8px; font-size:0.88rem; box-sizing:border-box; cursor:pointer; background:#f8fafc;">
                </div>
                <button onclick="window.ODC._subirImportarODC()"
                    style="width:100%; padding:12px; background:#0f172a; color:white; border:none; border-radius:10px; font-size:0.92rem; cursor:pointer; font-weight:700;">
                    <i class="fa-solid fa-upload"></i> Importar ODC
                </button>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    async _subirImportarODC() {
        const fileInput = document.getElementById('odc-import-file');
        if (!fileInput?.files?.length) {
            window.Toast?.error('Selecciona un archivo CSV');
            return;
        }
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        const token = localStorage.getItem('jwt_token') || localStorage.getItem('token');
        const base  = window.api?.baseUrl || '/api';
        try {
            const r = await fetch(`${base}/odc/importar`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${token}` },
                body: formData,
            });
            const data = await r.json();
            if (data.error) throw new Error(data.message);
            window.Toast?.success(data.message || 'Importación completada');
            if (data.advertencias?.length) {
                console.warn('Advertencias importación ODC:', data.advertencias);
            }
            document.getElementById('odc-import-modal')?.remove();
            this.loadODCs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error al importar');
        }
    },

    // ── API helper (compatibilidad) ───────────────────────────────────────────
    buscarProducto(q) {
        return window.api.get('/odc/buscar-producto?q=' + encodeURIComponent(q));
    },
};
