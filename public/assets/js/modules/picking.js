/**
 * Prooriente WMS — Módulo de Picking (FEFO)
 */
window.Picking = {
    _orden: null,
    _manualItems: [],
    _manualProductoId: null,
    _debounceTimer: null,
    _manualTimer: null,

    getPickingHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                <div>
                    <span style="font-weight:700; color:#0f172a; font-size:1rem;">Módulo de Picking</span>
                    <p style="color:#64748b; font-size:0.78rem; margin:2px 0 0;">Separación y preparación de pedidos (FEFO)</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <button onclick="window.Picking.abrirImportar()"
                        style="padding:8px 12px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:8px; font-size:0.82rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-file-import"></i> Importar
                    </button>
                    <button onclick="window.Picking.abrirCrearManual()"
                        style="padding:8px 14px; background:#0f172a; color:white; border:none; border-radius:8px; font-size:0.82rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-plus"></i> Nuevo Pedido
                    </button>
                </div>
            </div>
            <div id="picking-dashboard" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;"></div>
            <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                <select id="pk-filtro-estado" onchange="window.Picking.loadOrdenes()"
                    style="flex:1; min-width:130px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; background:white;">
                    <option value="">Todos los estados</option>
                    <option value="Pendiente">Pendiente</option>
                    <option value="EnProceso">En Proceso</option>
                    <option value="Completada">Completada</option>
                </select>
                <input type="text" id="pk-filtro-buscar" placeholder="Buscar cliente u orden..."
                    oninput="window.Picking._debounce()"
                    style="flex:2; min-width:150px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem;">
            </div>
            <div id="picking-list">
                <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
            </div>
        </div>`;
    },

    async loadDashboard() {
        const el = document.getElementById('picking-dashboard');
        if (!el) return;
        try {
            const data = await window.api.get('/picking/dashboard');
            const s = data?.data || data || {};
            const cards = [
                { label:'Pendientes',      value: s.pendientes      || 0, color:'#f59e0b', icon:'fa-clock' },
                { label:'En Proceso',      value: s.en_proceso      || 0, color:'#3b82f6', icon:'fa-person-running' },
                { label:'Completadas hoy', value: s.completadas_hoy || 0, color:'#22c55e', icon:'fa-check-circle' },
                { label:'Con Faltantes',   value: s.con_faltantes   || 0, color:'#ef4444', icon:'fa-triangle-exclamation' },
            ];
            el.innerHTML = cards.map(c => `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:14px;text-align:center;">
                <div style="font-size:1.6rem;font-weight:800;color:${c.color};">${c.value}</div>
                <div style="font-size:0.72rem;color:#64748b;margin-top:4px;"><i class="fa-solid ${c.icon}" style="margin-right:3px;"></i>${c.label}</div>
            </div>`).join('');
        } catch(e) { el.innerHTML = ''; }
    },

    _debounce() { clearTimeout(this._debounceTimer); this._debounceTimer = setTimeout(() => this.loadOrdenes(), 400); },

    async loadOrdenes() {
        const list = document.getElementById('picking-list');
        if (!list) return;
        const estado = document.getElementById('pk-filtro-estado')?.value || '';
        list.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            let url = '/picking?limit=50';
            if (estado) url += `&estado=${encodeURIComponent(estado)}`;
            const data = await window.api.get(url);
            const ordenes = data?.data || data || [];
            if (!ordenes.length) {
                list.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Sin órdenes de picking</div>';
                return;
            }
            const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e', Cancelada:'#ef4444' };
            list.innerHTML = ordenes.map(o => {
                const color = sc[o.estado] || '#64748b';
                return `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:10px;cursor:pointer;"
                     onclick="window.Picking.verDetalle(${parseInt(o.id)})">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
                        <span style="font-weight:700;color:#0f172a;font-size:0.92rem;">${escHTML(o.numero_orden)}</span>
                        <span style="font-size:0.7rem;background:${color}20;color:${color};border-radius:999px;padding:2px 10px;font-weight:700;">${escHTML(o.estado)}</span>
                    </div>
                    <div style="font-size:0.82rem;color:#475569;margin-bottom:6px;"><i class="fa-solid fa-user" style="color:#94a3b8;margin-right:4px;"></i>${escHTML(o.cliente) || '—'}</div>
                    <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#94a3b8;">
                        <span>Prioridad: ${parseInt(o.prioridad) || 5}</span>
                        <span>${o.fecha_requerida ? '📅 ' + escHTML(o.fecha_requerida) : ''}</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </div>
                </div>`;
            }).join('');
        } catch(e) { list.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">Error al cargar las órdenes de picking.</div>`; }
    },

    async verDetalle(id) {
        try {
            const data = await window.api.get(`/picking/${id}`);
            this._orden = data?.data || data;
            this._abrirModalDetalle(this._orden);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    _abrirModalDetalle(orden) {
        document.getElementById('pk-detalle-modal')?.remove();
        const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e' };
        const color = sc[orden.estado] || '#64748b';
        const detalles = orden.detalles || [];
        const esPend = orden.estado === 'Pendiente';
        const esProc = orden.estado === 'EnProceso';
        const lc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completado:'#22c55e', Faltante:'#ef4444' };

        const lineasHtml = detalles.length ? detalles.map(d => `
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.85rem;color:#0f172a;">${d.producto?.nombre || 'Producto #'+d.producto_id}</div>
                <div style="font-size:0.72rem;color:#64748b;">
                    ${d.ubicacion?.codigo || (d.ubicacion_id > 0 ? 'Ubic #'+d.ubicacion_id : 'Sin ubicación')}
                    ${d.lote ? ' · Lote: '+d.lote : ''}${d.fecha_vencimiento ? ' · Vence: '+d.fecha_vencimiento : ''}
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="font-size:0.8rem;color:#64748b;">${d.cantidad_pickeada||0} / ${d.cantidad_solicitada}</div>
                <div style="font-size:0.7rem;color:${lc[d.estado]||'#64748b'};font-weight:700;">${d.estado}</div>
            </div>
            ${esProc && d.estado === 'EnProceso' ? `
            <button onclick="window.Picking.confirmarLinea(${orden.id},${d.id},${d.cantidad_solicitada})"
                style="padding:6px 10px;background:#22c55e;color:white;border:none;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;flex-shrink:0;">
                <i class="fa-solid fa-check"></i>
            </button>` : ''}
        </div>`).join('') : '<div style="padding:30px;text-align:center;color:#94a3b8;">Sin líneas</div>';

        const modal = document.createElement('div');
        modal.id = 'pk-detalle-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9990;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:620px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 4px;font-size:1rem;">${orden.numero_orden}</h3>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span style="font-size:0.7rem;background:${color}50;color:${color};border-radius:999px;padding:2px 10px;font-weight:700;">${orden.estado}</span>
                        <span style="font-size:0.75rem;color:#94a3b8;">${orden.cliente||'—'}</span>
                    </div>
                </div>
                <button onclick="document.getElementById('pk-detalle-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding:16px 20px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:700;color:#0f172a;font-size:0.9rem;">Líneas <span style="color:#94a3b8;font-weight:400;">(${detalles.length})</span></span>
                    ${esPend ? `<button onclick="window.Picking.generarRuta(${orden.id})"
                        style="padding:7px 14px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.8rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-route"></i> Generar Ruta FEFO
                    </button>` : ''}
                </div>
                <div style="max-height:350px;overflow-y:auto;">${lineasHtml}</div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;display:flex;gap:8px;">
                ${esProc ? `<button onclick="window.Picking.completarOrden(${orden.id})"
                    style="flex:1;padding:10px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                    <i class="fa-solid fa-flag-checkered"></i> Completar Orden
                </button>` : ''}
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    async generarRuta(ordenId) {
        try {
            window.showToast('Generando ruta FEFO...', 'info');
            const data = await window.api.post(`/picking/${ordenId}/generar-ruta`, {});
            const alertas = data?.data?.alertas_stock || [];
            window.showToast(alertas.length ? `Ruta generada. ${alertas.length} faltante(s)` : 'Ruta FEFO generada', 'success');
            document.getElementById('pk-detalle-modal')?.remove();
            await this.verDetalle(ordenId);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    async confirmarLinea(ordenId, lineaId, cantSol) {
        const cant = prompt(`Cantidad tomada (solicitada: ${cantSol}):`, cantSol);
        if (cant === null) return;
        try {
            await window.api.post(`/picking/${ordenId}/confirmar-linea`, { linea_id: lineaId, cantidad_tomada: parseInt(cant) });
            window.showToast('Línea confirmada', 'success');
            document.getElementById('pk-detalle-modal')?.remove();
            await this.verDetalle(ordenId);
            this.loadOrdenes(); this.loadDashboard();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    async completarOrden(id) {
        if (!confirm('¿Completar esta orden?')) return;
        try {
            await window.api.post(`/picking/${id}/completar`, {});
            window.showToast('Orden completada', 'success');
            document.getElementById('pk-detalle-modal')?.remove();
            this.loadOrdenes(); this.loadDashboard();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    abrirCrearManual() {
        this._manualItems = []; this._manualProductoId = null;
        document.getElementById('pk-manual-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'pk-manual-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9991;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:520px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-plus"></i> Nuevo Pedido Manual</h3>
                <button onclick="document.getElementById('pk-manual-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Cliente</label>
                        <input type="text" id="pk-m-cliente" placeholder="Nombre cliente"
                            style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Fecha Requerida</label>
                        <input type="date" id="pk-m-fecha"
                            style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;box-sizing:border-box;">
                    </div>
                    <div style="grid-column:span 2;">
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Prioridad</label>
                        <select id="pk-m-prior" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;background:white;box-sizing:border-box;">
                            <option value="1">1 — Alta urgencia</option>
                            <option value="3">3 — Media</option>
                            <option value="5" selected>5 — Normal</option>
                            <option value="9">9 — Baja</option>
                        </select>
                    </div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:12px;">
                    <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:6px;text-transform:uppercase;">Buscar Producto</label>
                    <input type="text" id="pk-m-buscar" placeholder="Nombre, código o EAN..."
                        oninput="window.Picking._manualBuscarDebounce()"
                        style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;box-sizing:border-box;margin-bottom:6px;">
                    <div id="pk-m-resultados" style="display:none;border:1px solid #e2e8f0;border-radius:8px;background:white;max-height:150px;overflow-y:auto;margin-bottom:6px;"></div>
                    <div id="pk-m-sel" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px;margin-bottom:8px;font-size:0.85rem;color:#166534;font-weight:600;"></div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="number" id="pk-m-cant" value="1" min="1"
                            style="width:90px;padding:8px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;">
                        <button onclick="window.Picking._agregarItemManual()"
                            style="flex:1;padding:8px;background:#3b82f6;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                            <i class="fa-solid fa-plus"></i> Agregar
                        </button>
                    </div>
                </div>
                <div id="pk-m-lista" style="max-height:140px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:8px;margin-bottom:16px;background:#f8fafc;">
                    <div style="padding:16px;text-align:center;color:#94a3b8;font-size:0.85rem;">Sin productos</div>
                </div>
                <button onclick="window.Picking._crearPedidoManual()"
                    style="width:100%;padding:12px;background:#0f172a;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:pointer;font-weight:700;">
                    <i class="fa-solid fa-save"></i> Crear Pedido de Picking
                </button>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    _manualBuscarDebounce() { clearTimeout(this._manualTimer); this._manualTimer = setTimeout(() => this._manualBuscar(), 350); },

    async _manualBuscar() {
        const q = document.getElementById('pk-m-buscar')?.value?.trim();
        const resEl = document.getElementById('pk-m-resultados');
        if (!resEl || !q || q.length < 2) { resEl && (resEl.style.display = 'none'); return; }
        resEl.style.display = 'block';
        resEl.innerHTML = '<div style="padding:10px;text-align:center;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const res = await window.api.get('/param/productos/buscar?q=' + encodeURIComponent(q));
            const prods = res.data || [];
            if (!prods.length) { resEl.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:0.85rem;text-align:center;">Sin resultados</div>'; return; }
            resEl.innerHTML = prods.map(p => `
            <div style="padding:9px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;"
                 onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''"
                 onclick="window.Picking._selManualProducto(${p.id},'${p.nombre.replace(/'/g,"\\'")}')">
                <div style="font-weight:600;font-size:0.85rem;color:#0f172a;">${p.nombre}</div>
                <div style="font-size:0.72rem;color:#64748b;">${p.codigo_interno} · ${p.unidad_medida||'UN'}</div>
            </div>`).join('');
        } catch(e) { resEl.innerHTML = '<div style="padding:10px;color:#ef4444;font-size:0.85rem;">Error</div>'; }
    },

    _selManualProducto(id, nombre) {
        this._manualProductoId = id;
        document.getElementById('pk-m-buscar').value = nombre;
        document.getElementById('pk-m-resultados').style.display = 'none';
        const sel = document.getElementById('pk-m-sel');
        sel.style.display = 'block'; sel.innerText = '✓ ' + nombre;
        document.getElementById('pk-m-cant').focus();
    },

    _agregarItemManual() {
        if (!this._manualProductoId) return window.showToast('Seleccione un producto', 'error');
        const nombre = document.getElementById('pk-m-buscar').value;
        const cant = parseInt(document.getElementById('pk-m-cant').value) || 1;
        this._manualItems.push({ producto_id: this._manualProductoId, cantidad: cant, nombre });
        this._manualProductoId = null;
        document.getElementById('pk-m-buscar').value = '';
        document.getElementById('pk-m-sel').style.display = 'none';
        document.getElementById('pk-m-cant').value = '1';
        this._renderManualLista();
    },

    _renderManualLista() {
        const el = document.getElementById('pk-m-lista');
        if (!el) return;
        if (!this._manualItems.length) { el.innerHTML = '<div style="padding:16px;text-align:center;color:#94a3b8;font-size:0.85rem;">Sin productos</div>'; return; }
        el.innerHTML = this._manualItems.map((item, i) => `
        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid #f1f5f9;">
            <div style="flex:1;font-size:0.85rem;color:#0f172a;font-weight:600;">${item.nombre}</div>
            <div style="font-weight:700;color:#475569;">× ${item.cantidad}</div>
            <button onclick="window.Picking._manualItems.splice(${i},1);window.Picking._renderManualLista();"
                style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:0.85rem;"><i class="fa-solid fa-trash"></i></button>
        </div>`).join('');
    },

    async _crearPedidoManual() {
        if (!this._manualItems.length) return window.showToast('Agregue al menos un producto', 'error');
        const body = {
            cliente:         document.getElementById('pk-m-cliente')?.value || null,
            fecha_requerida: document.getElementById('pk-m-fecha')?.value   || null,
            prioridad:       parseInt(document.getElementById('pk-m-prior')?.value) || 5,
            detalles:        this._manualItems.map(i => ({ producto_id: i.producto_id, cantidad: i.cantidad })),
        };
        try {
            await window.api.post('/picking', body);
            window.showToast('Pedido de picking creado', 'success');
            document.getElementById('pk-manual-modal')?.remove();
            this.loadOrdenes(); this.loadDashboard();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    abrirImportar() {
        document.getElementById('pk-import-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'pk-import-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9992;display:flex;align-items:center;justify-content:center;padding:16px;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:460px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-file-import"></i> Importar Pedidos CSV</h3>
                <button onclick="document.getElementById('pk-import-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px;">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px;margin-bottom:16px;font-size:0.82rem;color:#166534;">
                    <strong><i class="fa-solid fa-circle-info"></i> Formato CSV:</strong><br>
                    <code style="display:block;margin-top:6px;font-size:0.75rem;">numero_pedido;cliente;codigo;cantidad;fecha_requerida</code>
                    <code style="display:block;font-size:0.75rem;">PED-001;Cliente A;PROD001;50;2026-04-01</code>
                </div>
                <input type="file" id="pk-import-file" accept=".csv,.txt"
                    style="width:100%;padding:10px;border:2px dashed #cbd5e1;border-radius:8px;font-size:0.88rem;box-sizing:border-box;background:#f8fafc;margin-bottom:16px;cursor:pointer;">
                <button onclick="window.Picking._subirImportar()"
                    style="width:100%;padding:12px;background:#0f172a;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:pointer;font-weight:700;">
                    <i class="fa-solid fa-upload"></i> Importar Pedidos
                </button>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    async _subirImportar() {
        const fileInput = document.getElementById('pk-import-file');
        if (!fileInput?.files?.length) return window.showToast('Selecciona un archivo', 'error');
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        const token = localStorage.getItem('jwt_token') || localStorage.getItem('token');
        const base = window.api?.baseUrl || '/api';
        try {
            const r = await fetch(`${base}/picking/importar`, { method:'POST', headers:{ Authorization:`Bearer ${token}` }, body: formData });
            const data = await r.json();
            if (data.error) throw new Error(data.message);
            window.showToast(data.message || 'Importación completada', 'success');
            document.getElementById('pk-import-modal')?.remove();
            this.loadOrdenes(); this.loadDashboard();
        } catch(e) { window.showToast(e.message || 'Error al importar', 'error'); }
    },

    init() { this.loadDashboard(); this.loadOrdenes(); },
    crearBatch(a) { this.abrirImportar(); },
};
