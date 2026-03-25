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
                 data-prod-id="${parseInt(p.id)}" data-prod-nombre="${escHTML(p.nombre)}"
                 onclick="window.Picking._selManualProducto(parseInt(this.dataset.prodId), this.dataset.prodNombre)">
                <div style="font-weight:600;font-size:0.85rem;color:#0f172a;">${escHTML(p.nombre)}</div>
                <div style="font-size:0.72rem;color:#64748b;">${escHTML(p.codigo_interno)} · ${escHTML(p.unidad_medida)||'UN'}</div>
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
            <div style="flex:1;font-size:0.85rem;color:#0f172a;font-weight:600;">${escHTML(item.nombre)}</div>
            <div style="font-weight:700;color:#475569;">× ${parseInt(item.cantidad)}</div>
            <button data-idx="${i}" onclick="window.Picking._manualItems.splice(parseInt(this.dataset.idx),1);window.Picking._renderManualLista();"
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

    /* ── Vista de Rutas / Tareas activas ─────────────────────────────────── */
    getPickingRutasHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
                <div>
                    <span style="font-weight:700; color:#0f172a; font-size:1rem;">Rutas de Picking Pendientes</span>
                    <p style="color:#64748b; font-size:0.78rem; margin:2px 0 0;">Órdenes en proceso con ruta FEFO asignada</p>
                </div>
                <div style="display:flex; gap:8px;">
                    <select id="rutas-filtro" onchange="window.Picking.loadPickingRutas()"
                        style="padding:7px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.82rem; background:white;">
                        <option value="EnProceso">En Proceso</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="">Todos</option>
                    </select>
                    <button onclick="window.Picking.loadPickingRutas()"
                        style="padding:7px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:0.82rem; cursor:pointer;">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>

            <!-- KPIs compactos -->
            <div id="rutas-kpis" style="display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:16px;"></div>

            <!-- Lista de órdenes con rutas -->
            <div id="rutas-lista"></div>
        </div>`;
    },

    async loadPickingRutas() {
        const lista = document.getElementById('rutas-lista');
        const kpis  = document.getElementById('rutas-kpis');
        if (!lista) return;
        const estado = document.getElementById('rutas-filtro')?.value || 'EnProceso';
        lista.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
        try {
            const [dashData, ordenesData] = await Promise.all([
                window.api.get('/picking/dashboard').catch(() => ({})),
                window.api.get(`/picking?estado=${encodeURIComponent(estado)}&limit=50`),
            ]);
            const s = dashData?.data || dashData || {};
            if (kpis) {
                const cards = [
                    { l:'Pendientes',  v: s.pendientes||0,      c:'#f59e0b' },
                    { l:'En Proceso',  v: s.en_proceso||0,      c:'#3b82f6' },
                    { l:'Completadas', v: s.completadas_hoy||0,  c:'#22c55e' },
                    { l:'Faltantes',   v: s.con_faltantes||0,   c:'#ef4444' },
                ];
                kpis.innerHTML = cards.map(c => `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:12px;text-align:center;">
                    <div style="font-size:1.5rem;font-weight:800;color:${c.c};">${c.v}</div>
                    <div style="font-size:0.7rem;color:#64748b;margin-top:2px;">${c.l}</div>
                </div>`).join('');
            }

            const ordenes = ordenesData?.data || ordenesData || [];
            if (!ordenes.length) {
                lista.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-route" style="font-size:2rem;display:block;margin-bottom:10px;"></i>No hay rutas de picking activas</div>';
                return;
            }
            const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e' };
            lista.innerHTML = ordenes.map(o => {
                const color = sc[o.estado]||'#64748b';
                const progress = o.detalles
                    ? Math.round((o.detalles.filter(d => d.estado === 'Completado').length / o.detalles.length) * 100)
                    : 0;
                return `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                <span style="font-weight:700;color:#0f172a;">${escHTML(o.numero_orden)}</span>
                                <span style="font-size:0.7rem;background:${color}20;color:${color};border-radius:999px;padding:2px 8px;font-weight:700;">${escHTML(o.estado)}</span>
                                ${o.prioridad <= 3 ? '<span style="font-size:0.7rem;background:#fee2e2;color:#dc2626;border-radius:999px;padding:2px 8px;font-weight:700;">URGENTE</span>' : ''}
                            </div>
                            <div style="font-size:0.82rem;color:#475569;"><i class="fa-solid fa-user" style="color:#94a3b8;margin-right:4px;"></i>${escHTML(o.cliente||'—')}</div>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            ${o.estado === 'Pendiente' ? `
                            <button onclick="window.Picking.generarRuta(${parseInt(o.id)})"
                                style="padding:6px 10px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.75rem;cursor:pointer;font-weight:600;">
                                <i class="fa-solid fa-route"></i> Generar
                            </button>` : ''}
                            <button onclick="window.Picking.verDetalle(${parseInt(o.id)})"
                                style="padding:6px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.75rem;cursor:pointer;font-weight:600;">
                                <i class="fa-solid fa-eye"></i> Ver
                            </button>
                        </div>
                    </div>
                    ${o.estado === 'EnProceso' ? `
                    <div style="margin-top:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:#64748b;margin-bottom:4px;">
                            <span>Progreso de picking</span>
                            <span>${progress}%</span>
                        </div>
                        <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:${progress}%;background:#22c55e;border-radius:3px;transition:width .3s;"></div>
                        </div>
                    </div>` : ''}
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:0.72rem;color:#94a3b8;">
                        <span>Prioridad: ${parseInt(o.prioridad)||5}</span>
                        <span>${o.fecha_requerida ? '📅 ' + escHTML(o.fecha_requerida) : 'Sin fecha requerida'}</span>
                    </div>
                </div>`;
            }).join('');
        } catch(e) {
            lista.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">Error al cargar rutas: ${escHTML(e.message)}</div>`;
        }
    },

    /* ── Importar planilla (CSV del archivo de picking) ─────────────────── */
    abrirImportarPlanilla() {
        document.getElementById('pk-planilla-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'pk-planilla-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9992;display:flex;align-items:center;justify-content:center;padding:16px;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:500px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-file-arrow-up"></i> Importar Archivo Planilla</h3>
                    <p style="margin:4px 0 0;font-size:0.72rem;color:#94a3b8;">Archivo Excel/CSV con columnas amarillas del sistema</p>
                </div>
                <button onclick="document.getElementById('pk-planilla-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px;">
                <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:16px;font-size:0.82rem;color:#78350f;">
                    <strong><i class="fa-solid fa-circle-info"></i> Columnas requeridas (campos en amarillo):</strong>
                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px;">
                        ${['Numero Factura','Documento','Planilla','Asesor','Producto','Cantidad','Costo','Descuento','Valor Producto','Pedido'].map(c =>
                            `<code style="background:#fef08a;padding:2px 6px;border-radius:4px;font-size:0.72rem;">${c}</code>`
                        ).join('')}
                    </div>
                </div>
                <div style="border:2px dashed #cbd5e1;border-radius:10px;padding:24px;text-align:center;margin-bottom:16px;cursor:pointer;background:#f8fafc;"
                     onclick="document.getElementById('planilla-file-input').click()">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:2rem;color:#6366f1;display:block;margin-bottom:8px;"></i>
                    <div style="font-size:0.88rem;font-weight:600;color:#475569;">Haga clic para seleccionar archivo</div>
                    <div style="font-size:0.72rem;color:#94a3b8;margin-top:4px;">Excel (.xlsx, .xls) o CSV (.csv)</div>
                </div>
                <input type="file" id="planilla-file-input" accept=".csv,.xlsx,.xls,.txt" style="display:none;"
                    onchange="document.getElementById('planilla-file-name').textContent=this.files[0]?.name||''">
                <div id="planilla-file-name" style="font-size:0.82rem;color:#6366f1;text-align:center;margin-bottom:12px;font-weight:600;"></div>
                <button onclick="window.Picking._subirPlanilla()"
                    style="width:100%;padding:12px;background:#6366f1;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:pointer;font-weight:700;">
                    <i class="fa-solid fa-upload"></i> Importar Planilla
                </button>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    async _subirPlanilla() {
        const input = document.getElementById('planilla-file-input');
        if (!input?.files?.length) return window.showToast('Selecciona un archivo', 'error');
        const formData = new FormData();
        formData.append('file', input.files[0]);
        const token = localStorage.getItem('jwt_token') || localStorage.getItem('token');
        const base = window.api?.baseUrl || '/api';
        try {
            const r = await fetch(`${base}/planillas/importar`, {
                method: 'POST',
                headers: { Authorization: `Bearer ${token}` },
                body: formData,
            });
            const data = await r.json();
            if (data.error) throw new Error(data.message);
            window.showToast(data.message || 'Planilla importada', 'success');
            document.getElementById('pk-planilla-modal')?.remove();
            // Navigate to certification module
            if (typeof window.openSubView === 'function') {
                window.openSubView('certificacion_planilla', 'Certificación por Planilla');
            }
        } catch(e) { window.showToast(e.message || 'Error al importar', 'error'); }
    },

    /* ── Asignación profesional ──────────────────────────────────────────── */
    getAsignacionHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div>
                    <span style="font-weight:700;color:#0f172a;font-size:1rem;">Asignación de Picking</span>
                    <p style="color:#64748b;font-size:0.78rem;margin:2px 0 0;">Asigne órdenes por auxiliar, pasillo, ubicación o marca</p>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
                <div>
                    <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Tipo de Asignación</label>
                    <select id="asig-tipo" onchange="window.Picking._cambiarTipoAsig()"
                        style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;box-sizing:border-box;">
                        <option value="auxiliar">Por Auxiliar</option>
                        <option value="pasillo">Por Pasillo</option>
                        <option value="ubicacion">Por Ubicación</option>
                        <option value="marca">Por Marca</option>
                        <option value="masivo">Masivo (Todas)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Filtro Estado</label>
                    <select id="asig-estado" onchange="window.Picking.loadAsignacion()"
                        style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;box-sizing:border-box;">
                        <option value="Pendiente">Pendientes</option>
                        <option value="EnProceso">En Proceso</option>
                        <option value="">Todos</option>
                    </select>
                </div>
            </div>
            <div id="asig-filtro-extra" style="margin-bottom:12px;"></div>
            <div id="asig-ordenes" style="margin-bottom:16px;"></div>
            <div id="asig-acciones" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-weight:700;color:#0f172a;font-size:0.9rem;">Asignar seleccionados</span>
                    <span id="asig-count" style="font-size:0.78rem;color:#6366f1;font-weight:700;">0 seleccionados</span>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="asig-auxiliar"
                        style="flex:1;min-width:150px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;">
                        <option value="">Seleccione auxiliar...</option>
                    </select>
                    <button onclick="window.Picking._asignarSeleccionados()"
                        style="padding:8px 16px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-user-tag"></i> Asignar
                    </button>
                    <button onclick="window.Picking._generarRutasMasivas()"
                        style="padding:8px 16px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-route"></i> Generar Rutas FEFO
                    </button>
                </div>
            </div>
        </div>`;
    },

    _asigSelected: [],

    async loadAsignacion() {
        const lista = document.getElementById('asig-ordenes');
        if (!lista) return;
        this._asigSelected = [];
        const estado = document.getElementById('asig-estado')?.value || 'Pendiente';
        lista.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        // Load auxiliares for assignment
        try {
            const [ordenesData, personalData] = await Promise.all([
                window.api.get(`/picking?estado=${encodeURIComponent(estado)}&limit=100`),
                window.api.get('/param/personal').catch(() => ({ data: [] }))
            ]);
            const ordenes = ordenesData?.data || ordenesData || [];
            const personal = personalData?.data || personalData || [];
            // Fill auxiliar select
            const auxSel = document.getElementById('asig-auxiliar');
            if (auxSel) {
                auxSel.innerHTML = '<option value="">Seleccione auxiliar...</option>' +
                    personal.map(p => `<option value="${parseInt(p.id)}">${escHTML(p.nombre)} (${escHTML(p.cargo||p.rol||'')})</option>`).join('');
            }
            if (!ordenes.length) {
                lista.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;"></i>Sin órdenes para asignar</div>';
                document.getElementById('asig-acciones').style.display = 'none';
                return;
            }
            document.getElementById('asig-acciones').style.display = 'block';
            const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e' };
            lista.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <input type="checkbox" id="asig-all" onchange="window.Picking._toggleAllAsig(this.checked)"
                    style="width:16px;height:16px;cursor:pointer;">
                <label for="asig-all" style="font-size:0.82rem;color:#475569;cursor:pointer;font-weight:600;">Seleccionar todas</label>
            </div>` +
            ordenes.map(o => {
                const color = sc[o.estado]||'#64748b';
                return `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:8px;display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" class="asig-chk" value="${parseInt(o.id)}"
                        onchange="window.Picking._updateAsigCount()" style="width:16px;height:16px;flex-shrink:0;cursor:pointer;">
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                            <span style="font-weight:700;color:#0f172a;font-size:0.88rem;">${escHTML(o.numero_orden)}</span>
                            <span style="font-size:0.68rem;background:${color}20;color:${color};border-radius:999px;padding:2px 8px;font-weight:700;">${o.estado}</span>
                            ${o.prioridad <= 3 ? '<span style="font-size:0.65rem;background:#fee2e2;color:#dc2626;border-radius:999px;padding:2px 6px;font-weight:700;">URGENTE</span>' : ''}
                        </div>
                        <div style="font-size:0.78rem;color:#475569;">${escHTML(o.cliente||'Sin cliente')} ${o.auxiliar_id ? '· Aux: #'+o.auxiliar_id : ''}</div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;font-size:0.72rem;color:#94a3b8;">
                        P: ${o.prioridad||5}<br>${o.fecha_requerida||'—'}
                    </div>
                </div>`;
            }).join('');
        } catch(e) {
            lista.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;">${escHTML(e.message)}</div>`;
        }
    },

    _toggleAllAsig(checked) {
        document.querySelectorAll('.asig-chk').forEach(c => c.checked = checked);
        this._updateAsigCount();
    },

    _updateAsigCount() {
        const checked = document.querySelectorAll('.asig-chk:checked');
        this._asigSelected = Array.from(checked).map(c => parseInt(c.value));
        const el = document.getElementById('asig-count');
        if (el) el.textContent = `${this._asigSelected.length} seleccionados`;
    },

    _cambiarTipoAsig() {
        const tipo = document.getElementById('asig-tipo')?.value;
        const extra = document.getElementById('asig-filtro-extra');
        if (!extra) return;
        if (tipo === 'pasillo') {
            extra.innerHTML = `<input type="text" id="asig-pasillo" placeholder="Filtrar por pasillo (ej: A, B, C)..."
                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;box-sizing:border-box;">`;
        } else if (tipo === 'marca') {
            extra.innerHTML = `<input type="text" id="asig-marca" placeholder="Filtrar por marca..."
                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;box-sizing:border-box;">`;
        } else if (tipo === 'ubicacion') {
            extra.innerHTML = `<input type="text" id="asig-ubicacion" placeholder="Filtrar por ubicación (código)..."
                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;box-sizing:border-box;">`;
        } else {
            extra.innerHTML = '';
        }
    },

    async _asignarSeleccionados() {
        if (!this._asigSelected.length) return window.showToast('Seleccione al menos una orden','error');
        const auxId = document.getElementById('asig-auxiliar')?.value;
        if (!auxId) return window.showToast('Seleccione un auxiliar','error');
        let ok = 0, err = 0;
        for (const id of this._asigSelected) {
            try {
                await window.api.post(`/picking/${id}/generar-ruta`, { auxiliar_id: parseInt(auxId) });
                ok++;
            } catch(e) { err++; }
        }
        window.showToast(`Asignados: ${ok}${err ? `, Errores: ${err}` : ''}`, ok ? 'success' : 'error');
        this.loadAsignacion();
    },

    async _generarRutasMasivas() {
        if (!this._asigSelected.length) return window.showToast('Seleccione al menos una orden','error');
        if (!confirm(`Generar ruta FEFO para ${this._asigSelected.length} órdenes?`)) return;
        let ok = 0, err = 0;
        for (const id of this._asigSelected) {
            try {
                await window.api.post(`/picking/${id}/generar-ruta`, {});
                ok++;
            } catch(e) { err++; }
        }
        window.showToast(`Rutas generadas: ${ok}${err ? `, Errores: ${err}` : ''}`, ok ? 'success' : 'error');
        this.loadAsignacion();
    },

    /* ── Dashboard profesional de picking ────────────────────────────────── */
    getDashboardHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <span style="font-weight:700;color:#0f172a;font-size:1.1rem;">Tablero de Control - Picking</span>
                    <p style="color:#64748b;font-size:0.78rem;margin:2px 0 0;">Monitoreo en tiempo real del proceso de separación</p>
                </div>
                <button onclick="window.Picking.loadDashboardCompleto()"
                    style="padding:7px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem;cursor:pointer;">
                    <i class="fa-solid fa-rotate"></i> Actualizar
                </button>
            </div>
            <!-- KPIs principales -->
            <div id="pkd-kpis" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;"></div>
            <!-- Gráficos -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
                    <h4 style="margin:0 0 12px;font-size:0.9rem;color:#0f172a;">Estado de Órdenes</h4>
                    <div id="pkd-chart-estados" style="height:180px;display:flex;align-items:flex-end;gap:8px;justify-content:center;"></div>
                </div>
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
                    <h4 style="margin:0 0 12px;font-size:0.9rem;color:#0f172a;">Productividad por Auxiliar</h4>
                    <div id="pkd-chart-aux" style="max-height:180px;overflow-y:auto;"></div>
                </div>
            </div>
            <!-- Órdenes recientes -->
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;">
                <h4 style="margin:0 0 12px;font-size:0.9rem;color:#0f172a;">Órdenes Recientes</h4>
                <div id="pkd-tabla" style="overflow-x:auto;"></div>
            </div>
        </div>`;
    },

    async loadDashboardCompleto() {
        const kpis = document.getElementById('pkd-kpis');
        if (!kpis) return;
        try {
            const [dashData, ordenesData] = await Promise.all([
                window.api.get('/picking/dashboard').catch(() => ({})),
                window.api.get('/picking?limit=30'),
            ]);
            const s = dashData?.data || dashData || {};
            const ordenes = ordenesData?.data || ordenesData || [];

            // KPIs
            const cards = [
                { l:'Pendientes',      v: s.pendientes||0,      c:'#f59e0b', i:'fa-clock' },
                { l:'En Proceso',      v: s.en_proceso||0,      c:'#3b82f6', i:'fa-person-running' },
                { l:'Completadas Hoy', v: s.completadas_hoy||0, c:'#22c55e', i:'fa-check-circle' },
                { l:'Con Faltantes',   v: s.con_faltantes||0,   c:'#ef4444', i:'fa-exclamation-triangle' },
            ];
            kpis.innerHTML = cards.map(c => `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;text-align:center;">
                <i class="fa-solid ${c.i}" style="font-size:1.2rem;color:${c.c};display:block;margin-bottom:6px;"></i>
                <div style="font-size:1.8rem;font-weight:800;color:${c.c};">${c.v}</div>
                <div style="font-size:0.72rem;color:#64748b;margin-top:4px;">${c.l}</div>
            </div>`).join('');

            // Chart: estados
            const chartEl = document.getElementById('pkd-chart-estados');
            if (chartEl) {
                const totP = s.pendientes||0, totE = s.en_proceso||0, totC = s.completadas_hoy||0, totF = s.con_faltantes||0;
                const mx = Math.max(totP, totE, totC, totF, 1);
                const bars = [
                    { l:'Pend.', v:totP, c:'#f59e0b' },
                    { l:'Proc.', v:totE, c:'#3b82f6' },
                    { l:'Comp.', v:totC, c:'#22c55e' },
                    { l:'Falt.', v:totF, c:'#ef4444' },
                ];
                chartEl.innerHTML = bars.map(b => `
                <div style="flex:1;text-align:center;">
                    <div style="font-size:0.82rem;font-weight:800;color:${b.c};margin-bottom:4px;">${b.v}</div>
                    <div style="height:${Math.max((b.v/mx)*140, 4)}px;background:${b.c};border-radius:6px 6px 0 0;transition:height .3s;"></div>
                    <div style="font-size:0.68rem;color:#64748b;margin-top:6px;">${b.l}</div>
                </div>`).join('');
            }

            // Chart: productividad auxiliar
            const auxEl = document.getElementById('pkd-chart-aux');
            if (auxEl) {
                const auxMap = {};
                ordenes.forEach(o => {
                    const key = o.auxiliar_id || 'Sin asignar';
                    if (!auxMap[key]) auxMap[key] = { total: 0, completadas: 0 };
                    auxMap[key].total++;
                    if (o.estado === 'Completada') auxMap[key].completadas++;
                });
                const entries = Object.entries(auxMap).sort((a,b) => b[1].total - a[1].total);
                auxEl.innerHTML = entries.length ? entries.map(([k,v]) => `
                <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;">
                    <div style="flex:1;font-size:0.82rem;color:#0f172a;font-weight:600;">Aux #${k}</div>
                    <div style="font-size:0.78rem;color:#475569;">${v.completadas}/${v.total}</div>
                    <div style="width:80px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                        <div style="height:100%;width:${v.total > 0 ? (v.completadas/v.total)*100 : 0}%;background:#22c55e;border-radius:3px;"></div>
                    </div>
                </div>`).join('') : '<div style="color:#94a3b8;text-align:center;padding:20px;font-size:0.85rem;">Sin datos</div>';
            }

            // Tabla recientes
            const tablaEl = document.getElementById('pkd-tabla');
            if (tablaEl) {
                if (!ordenes.length) {
                    tablaEl.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:20px;">Sin órdenes</div>';
                } else {
                    const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e', Cancelada:'#ef4444' };
                    tablaEl.innerHTML = `
                    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                        <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                            <th style="padding:8px 10px;text-align:left;font-weight:700;color:#475569;">Orden</th>
                            <th style="padding:8px 10px;text-align:left;font-weight:700;color:#475569;">Cliente</th>
                            <th style="padding:8px 10px;text-align:center;font-weight:700;color:#475569;">Estado</th>
                            <th style="padding:8px 10px;text-align:center;font-weight:700;color:#475569;">Prior.</th>
                            <th style="padding:8px 10px;text-align:center;font-weight:700;color:#475569;">Fecha Req.</th>
                        </tr></thead>
                        <tbody>${ordenes.slice(0,20).map((o,i) => {
                            const color = sc[o.estado]||'#64748b';
                            return `<tr style="border-bottom:1px solid #f1f5f9;${i%2?'background:#fafafa;':''}">
                                <td style="padding:8px 10px;font-weight:600;color:#0f172a;cursor:pointer;" onclick="window.Picking.verDetalle(${parseInt(o.id)})">${escHTML(o.numero_orden)}</td>
                                <td style="padding:8px 10px;color:#475569;">${escHTML(o.cliente||'—')}</td>
                                <td style="padding:8px 10px;text-align:center;"><span style="font-size:0.7rem;background:${color}20;color:${color};border-radius:999px;padding:2px 8px;font-weight:700;">${o.estado}</span></td>
                                <td style="padding:8px 10px;text-align:center;color:#475569;">${o.prioridad||5}</td>
                                <td style="padding:8px 10px;text-align:center;color:#64748b;">${o.fecha_requerida||'—'}</td>
                            </tr>`;
                        }).join('')}</tbody>
                    </table>`;
                }
            }
        } catch(e) {
            kpis.innerHTML = `<div style="grid-column:span 4;color:#ef4444;text-align:center;padding:20px;">${escHTML(e.message)}</div>`;
        }
    },

    init() { this.loadDashboard(); this.loadOrdenes(); },
    crearBatch(a) { this.abrirImportar(); },
};
