/**
 * Prooriente WMS - Inventario & Conteos Module
 */
window.Inventario = {
    currentConteo: null,
    timerInterval: null,
    _conteoItems: [],
    _conteoScanTimer: null,

    /* ====================================================================
       UI — INICIO CONTEO
    ==================================================================== */
    getNuevoConteoHTML() {
        return `
        <div style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:640px; margin:0 auto;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="width:60px; height:60px; background:#f0fdf4; color:#22c55e; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:1.5rem;">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <h3 style="margin:0; color:#0f172a;">Iniciar Nuevo Conteo</h3>
                <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Seleccione el tipo de inventario a realizar</p>
            </div>

            <!-- Opciones de conteo -->
            <div id="conteo-tipo-panel" style="display:grid; gap:15px; margin-bottom:25px;">
                <button class="btn-primary" style="background:#f8fafc; color:#334155; border:1px solid #e2e8f0; height:auto; padding:20px; text-align:left; display:flex; align-items:center; gap:15px;"
                    onclick="window.Inventario.startConteo('General')">
                    <div style="width:40px; height:40px; background:#eff6ff; color:#3b82f6; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                        <i class="fa-solid fa-boxes-stacked"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:1rem;">Conteo General</div>
                        <div style="font-size:0.8rem; color:#64748b; font-weight:400;">Inventario total de todos los productos y ubicaciones.</div>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="color:#cbd5e1;"></i>
                </button>

                <button class="btn-primary" id="btn-conteo-ubicacion" style="background:#f8fafc; color:#334155; border:1px solid #e2e8f0; height:auto; padding:20px; text-align:left; display:flex; align-items:center; gap:15px;"
                    onclick="window.Inventario.showConteoUbicacionForm()">
                    <div style="width:40px; height:40px; background:#f5f3ff; color:#8b5cf6; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:1rem;">Por Ubicación</div>
                        <div style="font-size:0.8rem; color:#64748b; font-weight:400;">Cíclico dirigido a zonas o pasillos específicos.</div>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="color:#cbd5e1;"></i>
                </button>

                <button class="btn-primary" id="btn-conteo-referencia" style="background:#f8fafc; color:#334155; border:1px solid #e2e8f0; height:auto; padding:20px; text-align:left; display:flex; align-items:center; gap:15px;"
                    onclick="window.Inventario.showConteoReferenciaForm()">
                    <div style="width:40px; height:40px; background:#fff7ed; color:#f97316; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                        <i class="fa-solid fa-barcode"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:1rem;">Por Referencia</div>
                        <div style="font-size:0.8rem; color:#64748b; font-weight:400;">Conteo selectivo de productos específicos.</div>
                    </div>
                    <i class="fa-solid fa-chevron-right" style="color:#cbd5e1;"></i>
                </button>
            </div>

            <!-- Panel ubicación -->
            <div id="conteo-ubicacion-form" style="display:none; border-top:2px dashed #e2e8f0; padding-top:20px; margin-bottom:20px;">
                <h4 style="margin:0 0 12px; color:#8b5cf6;"><i class="fa-solid fa-location-dot"></i> Conteo por Ubicación</h4>
                <div class="input-group" style="margin-bottom:12px;">
                    <label>Zona / Pasillo (filtrar ubicaciones)</label>
                    <input type="text" id="conteo-ubic-filtro" class="input-field" placeholder="Ej: A, B, Pasillo-3...">
                </div>
                <button class="btn-primary" style="background:#8b5cf6;" onclick="window.Inventario.startConteo('PorUbicacion', document.getElementById('conteo-ubic-filtro').value)">
                    <i class="fa-solid fa-play"></i> Iniciar Conteo por Ubicación
                </button>
            </div>

            <!-- Panel referencia / producto -->
            <div id="conteo-referencia-form" style="display:none; border-top:2px dashed #e2e8f0; padding-top:20px; margin-bottom:20px;">
                <h4 style="margin:0 0 12px; color:#f97316;"><i class="fa-solid fa-barcode"></i> Conteo por Referencia</h4>
                <div class="input-group" style="margin-bottom:8px;">
                    <label>Buscar Producto</label>
                    <input type="text" id="conteo-ref-buscar" class="input-field" placeholder="Nombre, código o EAN..."
                        oninput="window.Inventario._conteoRefBuscarDebounce()">
                    <div id="conteo-ref-resultados" style="display:none; border:1px solid #e2e8f0; border-radius:8px; background:white; max-height:180px; overflow-y:auto; margin-top:6px;"></div>
                </div>
                <div id="conteo-ref-sel" style="display:none; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:10px; margin-bottom:12px;">
                    <div id="conteo-ref-nombre" style="font-weight:700; color:#c2410c; font-size:0.9rem;"></div>
                </div>
                <button class="btn-primary" style="background:#f97316;" onclick="window.Inventario.startConteo('PorReferencia')">
                    <i class="fa-solid fa-play"></i> Iniciar Conteo por Referencia
                </button>
            </div>

            <!-- Panel activo (conteo en curso) -->
            <div id="conteo-active-panel" style="display:none; border-top:2px dashed #e2e8f0; padding-top:20px;">
                <div style="background:#0f172a; border-radius:12px; padding:20px; color:white; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Conteo en Progreso</div>
                            <div id="active-conteo-type" style="font-size:1.1rem; font-weight:700;">GENERAL</div>
                            <div id="active-conteo-meta" style="font-size:0.75rem; color:#94a3b8; margin-top:2px;"></div>
                        </div>
                        <div style="text-align:right;">
                            <div id="conteo-timer" style="font-family:monospace; font-size:1.5rem; font-weight:700;">00:00:00</div>
                            <div style="font-size:0.7rem; color:#94a3b8;">Duración</div>
                        </div>
                    </div>
                </div>

                <!-- Buscador inteligente para conteos -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:15px; margin-bottom:15px;">
                    <label style="font-size:0.8rem; font-weight:700; color:#475569; display:block; margin-bottom:8px; text-transform:uppercase;">
                        <i class="fa-solid fa-magnifying-glass" style="color:#22c55e;"></i> Buscar / Escanear Producto
                    </label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="conteo-scan-field" class="input-field" placeholder="EAN, código o nombre del producto..."
                            style="flex:1;" oninput="window.Inventario._conteoScanDebounce()" autofocus>
                        <button class="btn-primary" style="width:auto; padding:0 14px; background:#22c55e;" onclick="window.Inventario.buscarProductoConteo()">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>
                    <div id="conteo-scan-results" style="display:none; margin-top:8px; border:1px solid #e2e8f0; border-radius:8px; background:white; max-height:200px; overflow-y:auto;"></div>
                    <div id="conteo-prod-sel" style="display:none; margin-top:8px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px;">
                        <div style="font-weight:700; color:#166534; font-size:0.88rem;" id="conteo-prod-nombre"></div>
                        <div style="font-size:0.75rem; color:#15803d;" id="conteo-prod-codigo"></div>
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-bottom:15px;">
                    <div class="input-group" style="flex:1;">
                        <label>Cantidad Contada</label>
                        <input type="number" id="conteo-cant" class="input-field" value="1" min="0">
                    </div>
                    <div class="input-group" style="flex:1;">
                        <label>Ubicación (opcional)</label>
                        <input type="text" id="conteo-ubic" class="input-field" placeholder="A-01-01">
                    </div>
                </div>

                <button class="btn-primary" style="background:#0f172a; margin-bottom:15px;" onclick="window.Inventario.agregarItemConteo()">
                    <i class="fa-solid fa-plus-circle"></i> Registrar Item
                </button>

                <!-- Lista de items contados -->
                <div style="border-top:1px solid #e2e8f0; padding-top:12px;">
                    <h5 style="margin:0 0 8px; color:#475569; display:flex; align-items:center; gap:8px;">
                        Items Contados
                        <span id="conteo-count-badge" style="background:#22c55e; color:white; border-radius:999px; padding:2px 8px; font-size:0.72rem; font-weight:700;">0</span>
                    </h5>
                    <div id="conteo-items-list" style="max-height:250px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc;">
                        <div style="padding:30px; text-align:center; color:#94a3b8; font-size:0.85rem;">
                            Comience a escanear o buscar productos
                        </div>
                    </div>
                </div>

                <button class="btn-primary" style="background:#ef4444; margin-top:15px;" onclick="window.Inventario.finishConteo()">
                    <i class="fa-solid fa-stop"></i> Finalizar y Guardar Conteo
                </button>
            </div>
        </div>`;
    },

    showConteoUbicacionForm() {
        document.getElementById('conteo-ubicacion-form').style.display = 'block';
        document.getElementById('conteo-referencia-form').style.display = 'none';
    },

    showConteoReferenciaForm() {
        document.getElementById('conteo-referencia-form').style.display = 'block';
        document.getElementById('conteo-ubicacion-form').style.display = 'none';
    },

    /* ── Búsqueda para conteo por referencia ──────────────────────────── */
    _refBuscarTimer: null,
    _conteoRefBuscarDebounce() {
        clearTimeout(this._refBuscarTimer);
        this._refBuscarTimer = setTimeout(() => this._buscarProductoRef(), 350);
    },

    async _buscarProductoRef() {
        const q = document.getElementById('conteo-ref-buscar')?.value?.trim();
        const resEl = document.getElementById('conteo-ref-resultados');
        if (!resEl || q?.length < 2) { resEl && (resEl.style.display = 'none'); return; }
        resEl.style.display = 'block';
        resEl.innerHTML = '<div style="padding:12px; text-align:center; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const res = await window.api.get('/param/productos/buscar?q=' + encodeURIComponent(q));
            const prods = res.data || [];
            if (!prods.length) { resEl.innerHTML = '<div style="padding:12px; color:#94a3b8; font-size:0.85rem;">Sin resultados</div>'; return; }
            resEl.innerHTML = prods.map(p => `
            <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer;"
                 onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background=''"
                 onclick="window.Inventario._selRefProducto(${p.id}, '${p.nombre.replace(/'/g, "\\'")}')">
                <div style="font-weight:600; font-size:0.85rem; color:#0f172a;">${p.nombre}</div>
                <div style="font-size:0.72rem; color:#64748b;">${p.codigo_interno} · ${p.unidad_medida || 'UN'}</div>
            </div>`).join('');
        } catch (e) { resEl.innerHTML = '<div style="padding:12px; color:#ef4444; font-size:0.85rem;">Error</div>'; }
    },

    _selRefProductoId: null,
    _selRefProducto(id, nombre) {
        this._selRefProductoId = id;
        document.getElementById('conteo-ref-resultados').style.display = 'none';
        document.getElementById('conteo-ref-buscar').value = nombre;
        const sel = document.getElementById('conteo-ref-sel');
        sel.style.display = 'block';
        document.getElementById('conteo-ref-nombre').innerText = nombre;
    },

    /* ── Iniciar conteo ───────────────────────────────────────────────── */
    async startConteo(tipo, filtro = null) {
        try {
            const payload = { tipo };
            if (filtro) payload.filtro = filtro;
            if (tipo === 'PorReferencia' && this._selRefProductoId) {
                payload.producto_id = this._selRefProductoId;
            }
            const res = await window.api.post('/inventario/conteo/nuevo', payload);
            if (res.error) return window.showToast(res.message, 'error');
            this.currentConteo = res.data;
            this._conteoItems = [];
            document.getElementById('conteo-tipo-panel').style.display = 'none';
            document.getElementById('conteo-ubicacion-form').style.display = 'none';
            document.getElementById('conteo-referencia-form').style.display = 'none';
            document.getElementById('conteo-active-panel').style.display = 'block';
            document.getElementById('active-conteo-type').innerText = tipo.replace('Por', 'Por ').toUpperCase();
            document.getElementById('active-conteo-meta').innerText = filtro ? `Zona: ${filtro}` : '';
            window.showToast('Conteo ' + tipo + ' iniciado', 'success');
            this.startTimer();
            document.getElementById('conteo-scan-field')?.focus();
        } catch (e) { window.showToast('Error al iniciar: ' + e.message, 'error'); }
    },

    /* ── Búsqueda inteligente durante conteo ──────────────────────────── */
    _conteoScanDebounce() {
        clearTimeout(this._conteoScanTimer);
        this._conteoScanTimer = setTimeout(() => this.buscarProductoConteo(), 350);
    },

    async buscarProductoConteo() {
        const query = (document.getElementById('conteo-scan-field')?.value || '').trim();
        const resEl = document.getElementById('conteo-scan-results');
        if (!resEl || query.length < 2) { resEl && (resEl.style.display = 'none'); return; }

        resEl.style.display = 'block';
        resEl.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';

        try {
            const res = await window.api.get('/param/productos/buscar?q=' + encodeURIComponent(query));
            const prods = res.data || [];
            if (!prods.length) { resEl.innerHTML = '<div style="padding:12px; color:#94a3b8; font-size:0.85rem; text-align:center;">Sin coincidencias</div>'; return; }

            resEl.innerHTML = prods.map(p => {
                const eans = (p.eans || []).map(e => `<code style="background:#f0fdf4; color:#16a34a; padding:1px 5px; border-radius:4px; font-size:0.68rem;">${e.codigo_ean}</code>`).join(' ');
                return `
                <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer;"
                     onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''"
                     onclick="window.Inventario._selConteoProducto(${p.id}, '${p.nombre.replace(/'/g, "\\'")}', '${p.codigo_interno}')">
                    <div style="font-weight:700; color:#0f172a; font-size:0.88rem;">${p.nombre}</div>
                    <div style="margin-top:3px; display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                        <span style="font-size:0.72rem; color:#64748b;">${p.codigo_interno} · ${p.unidad_medida || 'UN'}</span>
                    </div>
                    ${eans ? `<div style="margin-top:3px;">${eans}</div>` : ''}
                </div>`;
            }).join('');
        } catch (e) { resEl.innerHTML = '<div style="padding:12px; color:#ef4444; font-size:0.85rem;">Error al buscar</div>'; }
    },

    _conteoProductoId: null,
    _selConteoProducto(id, nombre, codigo) {
        this._conteoProductoId = id;
        document.getElementById('conteo-scan-field').value = nombre;
        document.getElementById('conteo-scan-results').style.display = 'none';
        const sel = document.getElementById('conteo-prod-sel');
        sel.style.display = 'block';
        document.getElementById('conteo-prod-nombre').innerText = nombre;
        document.getElementById('conteo-prod-codigo').innerText = 'Cód: ' + codigo;
        document.getElementById('conteo-cant').focus();
    },

    /* ── Agregar item al conteo ───────────────────────────────────────── */
    async agregarItemConteo() {
        if (!this.currentConteo) return;
        if (!this._conteoProductoId) return window.showToast('Seleccione un producto', 'error');

        const cantidad = parseFloat(document.getElementById('conteo-cant').value) || 0;
        const ubicacion = document.getElementById('conteo-ubic')?.value?.trim() || null;

        try {
            await window.api.post(`/inventario/conteo/${this.currentConteo.id}/linea`, {
                producto_id: this._conteoProductoId,
                cantidad_contada: cantidad,
                ubicacion_codigo: ubicacion,
            });
            this._conteoItems.push({
                nombre:   document.getElementById('conteo-prod-nombre').innerText,
                cantidad,
                ubicacion: ubicacion || '—',
            });
            window.showToast('Item registrado', 'success');
            // Limpiar
            this._conteoProductoId = null;
            document.getElementById('conteo-scan-field').value = '';
            document.getElementById('conteo-prod-sel').style.display = 'none';
            document.getElementById('conteo-cant').value = '1';
            document.getElementById('conteo-ubic').value = '';
            this._renderConteoItems();
            document.getElementById('conteo-scan-field').focus();
        } catch (e) { window.showToast(e.message, 'error'); }
    },

    _renderConteoItems() {
        const list = document.getElementById('conteo-items-list');
        const badge = document.getElementById('conteo-count-badge');
        if (!list) return;
        if (badge) badge.innerText = this._conteoItems.length;
        if (!this._conteoItems.length) {
            list.innerHTML = '<div style="padding:30px; text-align:center; color:#94a3b8; font-size:0.85rem;">Comience a escanear o buscar productos</div>';
            return;
        }
        list.innerHTML = this._conteoItems.map((item, i) => `
        <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #f1f5f9;">
            <div style="width:22px; height:22px; background:#22c55e20; color:#16a34a; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:700; flex-shrink:0;">${i + 1}</div>
            <div style="flex:1; min-width:0;">
                <div style="font-weight:600; font-size:0.85rem; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${item.nombre}</div>
                <div style="font-size:0.72rem; color:#64748b;">Ubic: ${item.ubicacion}</div>
            </div>
            <div style="font-weight:700; color:#0f172a;">${item.cantidad}</div>
        </div>`).join('');
    },

    startTimer() {
        let seconds = 0;
        const display = document.getElementById('conteo-timer');
        if (this.timerInterval) clearInterval(this.timerInterval);
        this.timerInterval = setInterval(() => {
            seconds++;
            const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
            const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            if (display) display.innerText = `${h}:${m}:${s}`;
        }, 1000);
    },

    async finishConteo() {
        if (!this.currentConteo) return;
        if (!this._conteoItems.length) return window.showToast('Registre al menos un item', 'error');
        if (!confirm('¿Finalizar el conteo? Se guardarán las diferencias.')) return;
        try {
            const res = await window.api.post(`/inventario/conteo/${this.currentConteo.id}/finalizar`);
            if (res.error) return window.showToast(res.message, 'error');
            clearInterval(this.timerInterval);
            window.showToast('Conteo finalizado y guardado', 'success');
            window.openSubView?.('conteos_historial', 'Historial Conteos');
        } catch (e) { window.showToast('Error al finalizar: ' + e.message, 'error'); }
    },

    /* ====================================================================
       HISTORIAL DE CONTEOS
    ==================================================================== */
    getHistorialConteosHTML() {
        return `
        <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
            <h4 style="margin:0; color:#0f172a; margin-bottom:16px;">Historial de Conteos</h4>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                            <th style="padding:10px 8px;">#</th>
                            <th style="padding:10px 8px;">Fecha</th>
                            <th style="padding:10px 8px;">Tipo</th>
                            <th style="padding:10px 8px;">Estado</th>
                            <th style="padding:10px 8px;">Duración</th>
                            <th style="padding:10px 8px; width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="conteos-history-tbody">
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">Cargando historial...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>`;
    },

    async loadHistorialConteos() {
        const tbody = document.getElementById('conteos-history-tbody');
        if (!tbody) return;
        try {
            const res = await window.api.get('/inventario/conteos');
            if (res.data?.length) {
                tbody.innerHTML = res.data.map(c => `
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600;">#${c.id}</td>
                    <td style="padding:12px 8px; color:#475569;">${c.fecha_movimiento || '—'}</td>
                    <td style="padding:12px 8px;"><span style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-size:0.8rem;">${c.tipo_conteo}</span></td>
                    <td style="padding:12px 8px;"><span style="color:${c.estado === 'Abierto' ? '#f59e0b' : '#10b981'}; font-weight:600;">${c.estado}</span></td>
                    <td style="padding:12px 8px; color:#64748b; font-family:monospace;">${(c.hora_inicio || '—')} - ${(c.hora_fin || 'En curso')}</td>
                    <td style="padding:12px 8px;">
                        <button class="btn-primary" style="background:#f1f5f9; color:#475569; width:30px; height:30px; padding:0; border-radius:4px;" title="Ver">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </td>
                </tr>`).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No se registran conteos previos.</td></tr>';
            }
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Error al cargar historial.</td></tr>';
        }
    },

    initNuevoConteo() {
        this.currentConteo = null;
        this._conteoItems = [];
        this._conteoProductoId = null;
        if (this.timerInterval) clearInterval(this.timerInterval);
    },
};
