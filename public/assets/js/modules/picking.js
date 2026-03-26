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
        <div style="padding:12px; max-width:1200px; margin:0 auto;">
            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h3 style="margin:0;color:#0f172a;font-size:1.05rem;font-weight:800;">
                        <i class="fa-solid fa-cart-flatbed" style="color:#6366f1;margin-right:8px;"></i>Gestionar Picking — Visión 360°
                    </h3>
                    <p style="color:#64748b;font-size:0.78rem;margin:3px 0 0;">Control total del proceso de separación por planillas</p>
                </div>
                <button onclick="window.Picking.init()"
                    style="padding:7px 14px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem;cursor:pointer;">
                    <i class="fa-solid fa-rotate"></i> Actualizar
                </button>
            </div>

            <!-- KPI Cards -->
            <div id="picking-dashboard" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px;"></div>

            <!-- Tabs -->
            <div style="display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                <button id="pk-tab-planillas" onclick="window.Picking._pkTab('planillas')"
                    style="padding:8px 16px;border:none;background:none;font-weight:700;color:#6366f1;border-bottom:3px solid #6366f1;cursor:pointer;font-size:0.88rem;">
                    <i class="fa-solid fa-file-lines"></i> Planillas
                </button>
                <button id="pk-tab-ordenes" onclick="window.Picking._pkTab('ordenes')"
                    style="padding:8px 16px;border:none;background:none;font-weight:600;color:#94a3b8;border-bottom:3px solid transparent;cursor:pointer;font-size:0.88rem;">
                    <i class="fa-solid fa-list-check"></i> Órdenes
                </button>
            </div>

            <!-- Panel Planillas -->
            <div id="pk-panel-planillas">
                <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                    <select id="pk-filtro-archivo" onchange="window.Picking.loadPlanillasProgreso()"
                        style="flex:2;min-width:200px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;">
                        <option value="">Todos los archivos</option>
                    </select>
                    <span style="font-size:0.8rem;color:#64748b;font-style:italic;">* Solo archivos en proceso de picking</span>
                </div>
                <div id="pk-planillas-list">
                    <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
                </div>
            </div>

            <!-- Panel Órdenes -->
            <div id="pk-panel-ordenes" style="display:none;">
                <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                    <select id="pk-filtro-estado" onchange="window.Picking.loadOrdenes()"
                        style="flex:1;min-width:120px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;">
                        <option value="">Todos los estados</option>
                        <option value="Pendiente">Pendiente</option>
                        <option value="EnProceso">En Proceso</option>
                        <option value="Completada">Completada</option>
                    </select>
                    <select id="pk-filtro-planilla-ord" onchange="window.Picking.loadOrdenes()"
                        style="flex:1;min-width:120px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;">
                        <option value="">Todas las planillas</option>
                    </select>
                    <input type="text" id="pk-filtro-buscar" placeholder="Buscar auxiliar u orden..."
                        oninput="window.Picking._debounce()"
                        style="flex:2;min-width:150px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;">
                </div>
                <div id="picking-list">
                    <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>
                </div>
            </div>
        </div>`;
    },

    _pkTab(tab) {
        ['planillas','ordenes'].forEach(t => {
            const panel = document.getElementById('pk-panel-' + t);
            const btn   = document.getElementById('pk-tab-' + t);
            if (!panel || !btn) return;
            const active = t === tab;
            panel.style.display = active ? 'block' : 'none';
            btn.style.color = active ? '#6366f1' : '#94a3b8';
            btn.style.borderBottom = active ? '3px solid #6366f1' : '3px solid transparent';
            btn.style.fontWeight = active ? '700' : '600';
        });
        if (tab === 'planillas') this.loadPlanillasProgreso();
        if (tab === 'ordenes')   { this._populatePlanillaFiltro(); this.loadOrdenes(); }
    },

    async _populatePlanillaFiltro() {
        const sel = document.getElementById('pk-filtro-planilla-ord');
        if (!sel || sel.options.length > 1) return; // already populated
        try {
            const res = await window.api.get('/planillas').catch(() => ({ data: [] }));
            const archivos = res?.data || [];
            archivos.forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.nombre_archivo;
                opt.textContent = a.nombre_archivo;
                sel.appendChild(opt);
            });
        } catch(e) { /* silent */ }
    },

    async loadPlanillasProgreso() {
        const box = document.getElementById('pk-planillas-list');
        if (!box) return;
        box.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const archivoId = document.getElementById('pk-filtro-archivo')?.value || '';
            const res = await window.api.get('/planillas/progreso' + (archivoId ? '?archivo_id=' + archivoId : ''));
            const archivos = res.data || [];

            // Populate archivo selector
            const sel = document.getElementById('pk-filtro-archivo');
            if (sel && sel.options.length <= 1) {
                archivos.forEach(a => {
                    const opt = document.createElement('option');
                    opt.value = a.archivo.id;
                    opt.textContent = a.archivo.nombre_archivo + ' (' + a.archivo.estado + ')';
                    sel.appendChild(opt);
                });
            }

            if (!archivos.length) {
                box.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fa-solid fa-file-circle-xmark" style="font-size:2rem;margin-bottom:10px;display:block;"></i>No hay archivos en proceso de picking.<br><small>Importe una planilla y asigne el picking desde "Asignación".</small></div>';
                return;
            }

            box.innerHTML = archivos.map(a => {
                const archivo = a.archivo;
                const estadoColor = { Importada:'#f59e0b', EnPicking:'#3b82f6', Separado:'#22c55e', EnCertificacion:'#8b5cf6', Certificada:'#10b981', Anulada:'#ef4444' }[archivo.estado] || '#94a3b8';
                const planillasRows = (a.planillas || []).map(p => {
                    const pct = p.pct_completado || 0;
                    const barColor = pct >= 100 ? '#22c55e' : pct > 0 ? '#3b82f6' : '#e2e8f0';
                    return `<tr style="border-bottom:1px solid #f8fafc;">
                        <td style="padding:8px 10px;font-weight:600;color:#334155;">${p.numero_planilla}</td>
                        <td style="padding:8px 10px;color:#64748b;">${p.total_unidades?.toLocaleString() || 0} uds</td>
                        <td style="padding:8px 10px;">
                            ${p.asignada ? `<div style="display:flex;align-items:center;gap:8px;">
                                <div style="flex:1;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                    <div style="width:${pct}%;height:100%;background:${barColor};border-radius:99px;transition:width 0.4s;"></div>
                                </div>
                                <span style="font-size:0.78rem;font-weight:700;color:${barColor};min-width:36px;">${pct}%</span>
                            </div>`
                            : '<span style="color:#94a3b8;font-size:0.8rem;">Sin asignar</span>'}
                        </td>
                        <td style="padding:8px 10px;">
                            <span style="font-size:0.75rem;padding:2px 8px;border-radius:99px;background:${p.ordenes_comp===p.ordenes_total&&p.ordenes_total>0?'#dcfce7':'#eff6ff'};color:${p.ordenes_comp===p.ordenes_total&&p.ordenes_total>0?'#16a34a':'#2563eb'};">
                                ${p.ordenes_comp}/${p.ordenes_total} órdenes
                            </span>
                        </td>
                    </tr>`;
                }).join('');

                return `<div style="background:white;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                    <div style="padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <div>
                            <span style="font-weight:700;color:#0f172a;">${escHTML(archivo.nombre_archivo)}</span>
                            <span style="font-size:0.75rem;background:${estadoColor}20;color:${estadoColor};padding:2px 8px;border-radius:99px;margin-left:8px;font-weight:700;">${archivo.estado}</span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <span style="font-size:0.8rem;color:#64748b;">${archivo.total_planillas} planillas · ${a.total_lineas?.toLocaleString()} líneas</span>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <div style="width:80px;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                                    <div style="width:${a.pct_archivo}%;height:100%;background:#6366f1;border-radius:99px;"></div>
                                </div>
                                <span style="font-size:0.78rem;font-weight:700;color:#6366f1;">${a.pct_archivo}%</span>
                            </div>
                            ${['Importada','EnPicking'].includes(archivo.estado) ? `
                            <button onclick="window.Picking.habilitarCertificacion(${parseInt(archivo.id)}, '${escHTML(archivo.nombre_archivo)}')"
                                title="Supervisor: habilitar certificación aunque el picking no esté completo"
                                style="padding:4px 10px;background:#f59e0b;color:white;border:none;border-radius:8px;font-size:0.72rem;cursor:pointer;font-weight:700;">
                                <i class="fa-solid fa-unlock"></i> Habilitar Cert.
                            </button>` : ''}
                        </div>
                    </div>
                    <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                        <thead><tr style="background:#fafafa;color:#64748b;font-size:0.75rem;font-weight:700;">
                            <th style="padding:8px 10px;text-align:left;">Planilla</th>
                            <th style="padding:8px 10px;text-align:left;">Unidades</th>
                            <th style="padding:8px 10px;text-align:left;">Progreso Picking</th>
                            <th style="padding:8px 10px;text-align:left;">Órdenes</th>
                        </tr></thead>
                        <tbody>${planillasRows || '<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;">Sin planillas</td></tr>'}</tbody>
                    </table>
                </div>`;
            }).join('');
        } catch(e) {
            box.innerHTML = `<div style="padding:20px;color:#ef4444;text-align:center;">${escHTML(e.message)}</div>`;
        }
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
                            ${o.estado === 'EnProceso' ? `
                            <button onclick="event.stopPropagation();window.Picking.iniciarSeparacion(${parseInt(o.id)})"
                                style="padding:6px 10px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.75rem;cursor:pointer;font-weight:700;">
                                <i class="fa-solid fa-mobile-screen"></i> Separar
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
                <div id="pk-planilla-error" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-bottom:12px;font-size:0.82rem;color:#dc2626;line-height:1.5;"></div>
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

        const btn = document.querySelector('#pk-planilla-modal button[onclick*="_subirPlanilla"]');
        const errBox = document.getElementById('pk-planilla-error');
        if (errBox) errBox.style.display = 'none';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importando...'; }

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
            const data = await r.json().catch(() => ({ error: true, message: 'Respuesta inválida del servidor (status ' + r.status + ')' }));
            if (data.error) {
                // Show detailed error inside modal
                const box = document.getElementById('pk-planilla-error');
                if (box) {
                    box.style.display = 'block';
                    box.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation"></i> Error al importar:</strong><br>' +
                        (data.message || 'Error desconocido') +
                        (data.detail ? '<br><small style="color:#991b1b; font-family:monospace;">'
                            + data.detail.class + '<br>' + data.detail.file + '</small>' : '');
                } else {
                    window.showToast(data.message || 'Error al importar', 'error');
                }
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Planilla'; }
                return;
            }
            window.showToast('✓ ' + (data.message || 'Planilla importada'), 'success');
            document.getElementById('pk-planilla-modal')?.remove();
            if (typeof window.openSubView === 'function') {
                window.openSubView('certificacion_planilla', 'Certificación por Planilla');
            }
        } catch(e) {
            const box = document.getElementById('pk-planilla-error');
            const msg = e.message || 'Error de conexión con el servidor';
            if (box) {
                box.style.display = 'block';
                box.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation"></i> Error:</strong> ' + msg;
            } else {
                window.showToast(msg, 'error');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Planilla'; }
        }
    },

    /* ── Importar Planilla — vista completa (submódulo) ─────────────────── */
    getPlanillaImportHTML() {
        return `
        <div style="padding:16px; max-width:800px; margin:0 auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h3 style="margin:0;color:#0f172a;font-size:1.05rem;font-weight:800;">
                        <i class="fa-solid fa-file-arrow-up" style="color:#6366f1;margin-right:8px;"></i>Importar Archivo de Planilla
                    </h3>
                    <p style="color:#64748b;font-size:0.8rem;margin:3px 0 0;">Carga tu archivo CSV/Excel de picking</p>
                </div>
                <button onclick="window.Picking.abrirCrearManual()"
                    style="padding:8px 14px;background:#0f172a;color:white;border:none;border-radius:8px;font-size:0.82rem;cursor:pointer;font-weight:600;">
                    <i class="fa-solid fa-plus"></i> Pedido Manual
                </button>
            </div>

            <!-- Upload card -->
            <div style="background:white;border-radius:14px;padding:24px;border:1px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,0.05);margin-bottom:20px;">
                <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:18px;font-size:0.82rem;color:#78350f;">
                    <strong><i class="fa-solid fa-circle-info"></i> Columnas requeridas:</strong>
                    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px;">
                        ${['Numero Factura','Documento','Planilla','Asesor','Producto','Cantidad','Costo','Descuento','Valor Producto','Pedido'].map(c =>
                            `<code style="background:#fef08a;padding:2px 6px;border-radius:4px;font-size:0.72rem;">${c}</code>`
                        ).join('')}
                    </div>
                </div>
                <div style="border:2px dashed #cbd5e1;border-radius:10px;padding:32px;text-align:center;cursor:pointer;background:#f8fafc;margin-bottom:14px;"
                     onclick="document.getElementById('planilla-file-input-full').click()">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:2.5rem;color:#6366f1;display:block;margin-bottom:10px;"></i>
                    <div style="font-size:0.92rem;font-weight:600;color:#475569;">Haga clic para seleccionar archivo</div>
                    <div style="font-size:0.75rem;color:#94a3b8;margin-top:4px;">Excel (.xlsx, .xls) o CSV (.csv)</div>
                </div>
                <input type="file" id="planilla-file-input-full" accept=".csv,.xlsx,.xls,.txt" style="display:none;"
                    onchange="document.getElementById('pk-full-file-name').textContent=this.files[0]?.name||''; document.getElementById('pk-full-file-name').style.display=this.files[0]?'block':'none'">
                <div id="pk-full-file-name" style="display:none;font-size:0.85rem;color:#6366f1;text-align:center;margin-bottom:12px;font-weight:600;padding:8px;background:#eff6ff;border-radius:8px;"></div>
                <div id="pk-full-error" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;margin-bottom:12px;font-size:0.82rem;color:#dc2626;line-height:1.5;"></div>
                <button onclick="window.Picking._subirPlanillaFull()"
                    style="width:100%;padding:14px;background:#6366f1;color:white;border:none;border-radius:10px;font-size:0.95rem;cursor:pointer;font-weight:700;">
                    <i class="fa-solid fa-upload"></i> Importar Planilla
                </button>
            </div>

            <!-- Archivos importados -->
            <div style="background:white;border-radius:14px;padding:20px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h4 style="margin:0;color:#0f172a;font-size:0.95rem;">Archivos Importados</h4>
                    <button onclick="window.Picking.loadArchivosImportados()"
                        style="padding:5px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:0.78rem;cursor:pointer;">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
                <div id="pk-archivos-list">
                    <div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>`;
    },

    initPlanillaImport() {
        this.loadArchivosImportados();
    },

    async loadArchivosImportados() {
        const box = document.getElementById('pk-archivos-list');
        if (!box) return;
        try {
            const res = await window.api.get('/planillas');
            const archivos = res.data || [];
            if (!archivos.length) {
                box.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:20px;">No hay archivos importados.</p>';
                return;
            }
            const estadoStyle = { Importada:'background:#fef9c3;color:#854d0e', EnPicking:'background:#dbeafe;color:#1e40af', Separado:'background:#dcfce7;color:#166534', EnCertificacion:'background:#ede9fe;color:#6d28d9', Certificada:'background:#d1fae5;color:#065f46', Anulada:'background:#fee2e2;color:#991b1b' };
            box.innerHTML = archivos.map(a => {
                const s = estadoStyle[a.estado] || 'background:#f1f5f9;color:#475569';
                return `<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#6366f1;">
                            <i class="fa-solid fa-file-csv" style="font-size:1.1rem;"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;color:#334155;font-size:0.9rem;">${escHTML(a.nombre_archivo)}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">${a.total_lineas?.toLocaleString()} líneas · ${a.total_planillas} planillas</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:0.72rem;padding:3px 10px;border-radius:99px;font-weight:700;${s}">${a.estado}</span>
                        ${a.estado === 'Importada' ? `<button onclick="window.Picking._irAsignar(${a.id})"
                            style="padding:5px 12px;background:#6366f1;color:white;border:none;border-radius:6px;font-size:0.78rem;cursor:pointer;font-weight:700;">
                            <i class="fa-solid fa-users-gear"></i> Asignar
                        </button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch(e) {
            box.innerHTML = `<div style="color:#ef4444;padding:14px;">${escHTML(e.message)}</div>`;
        }
    },

    _irAsignar(archivoId) {
        // Navigate to asignación tab with this archivo pre-selected
        if (typeof window.openSubView === 'function') {
            window.openSubView('picking_asignacion', 'Asignación de Picking');
        }
        // Store archivo_id for the asignacion module to pick up
        window._asignarArchivoId = archivoId;
    },

    async _subirPlanillaFull() {
        const input = document.getElementById('planilla-file-input-full');
        if (!input?.files?.length) return window.showToast('Selecciona un archivo', 'error');
        const btn = document.querySelector('button[onclick*="_subirPlanillaFull"]');
        const errBox = document.getElementById('pk-full-error');
        if (errBox) errBox.style.display = 'none';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importando...'; }
        const formData = new FormData();
        formData.append('file', input.files[0]);
        const token = localStorage.getItem('jwt_token') || localStorage.getItem('token');
        const base = window.api?.baseUrl || '/api';
        try {
            const r = await fetch(`${base}/planillas/importar`, {
                method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: formData,
            });
            const data = await r.json().catch(() => ({ error: true, message: 'Error de servidor (status ' + r.status + ')' }));
            if (data.error) {
                if (errBox) { errBox.style.display = 'block'; errBox.innerHTML = '<strong><i class="fa-solid fa-triangle-exclamation"></i> Error:</strong> ' + (data.message || 'Error desconocido') + (data.detail ? '<br><small style="font-family:monospace;">' + data.detail.class + '<br>' + data.detail.file + '</small>' : ''); }
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Planilla'; }
                return;
            }
            window.showToast('✓ ' + (data.message || 'Planilla importada'), 'success');
            input.value = '';
            document.getElementById('pk-full-file-name').style.display = 'none';
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Planilla'; }
            this.loadArchivosImportados();
        } catch(e) {
            if (errBox) { errBox.style.display = 'block'; errBox.innerHTML = '<strong>Error:</strong> ' + (e.message || 'Error de conexión'); }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Importar Planilla'; }
        }
    },

    /* ── Asignación de Picking por Planilla ─────────────────────────────── */
    getAsignacionHTML() {
        return `
        <div style="padding:12px; max-width:900px; margin:0 auto;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h3 style="margin:0;color:#0f172a;font-size:1rem;font-weight:800;">
                        <i class="fa-solid fa-users-gear" style="color:#6366f1;margin-right:8px;"></i>Asignación de Picking
                    </h3>
                    <p style="color:#64748b;font-size:0.78rem;margin:3px 0 0;">Asigna planillas a auxiliares para iniciar la separación</p>
                </div>
            </div>

            <!-- Tabs: Por Planilla | Por Orden -->
            <div style="display:flex;gap:4px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                <button id="asig-tab-planilla" onclick="window.Picking._asigTab('planilla')"
                    style="padding:8px 16px;border:none;background:none;font-weight:700;color:#6366f1;border-bottom:3px solid #6366f1;cursor:pointer;font-size:0.87rem;">
                    <i class="fa-solid fa-file-lines"></i> Por Planilla
                </button>
                <button id="asig-tab-orden" onclick="window.Picking._asigTab('orden')"
                    style="padding:8px 16px;border:none;background:none;font-weight:600;color:#94a3b8;border-bottom:3px solid transparent;cursor:pointer;font-size:0.87rem;">
                    <i class="fa-solid fa-list-check"></i> Por Orden
                </button>
            </div>

            <!-- Panel Por Planilla -->
            <div id="asig-panel-planilla">
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin-bottom:14px;">
                    <div style="font-size:0.78rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:12px;letter-spacing:.5px;">
                        <i class="fa-solid fa-sliders"></i> Configuración de Asignación
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="font-size:0.75rem;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Archivo Planilla *</label>
                            <select id="asig-archivo-id" onchange="window.Picking._cargarPlanillasDelArchivo()"
                                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;box-sizing:border-box;">
                                <option value="">Seleccione archivo...</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Auxiliar *</label>
                            <select id="asig-aux-planilla"
                                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;box-sizing:border-box;">
                                <option value="">Seleccione auxiliar...</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Modo de Separación</label>
                            <select id="asig-modo"
                                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;box-sizing:border-box;">
                                <option value="por_planilla">Por Planilla (una orden por planilla)</option>
                                <option value="consolidado">Consolidado (suma todas en una sola orden)</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.75rem;font-weight:600;color:#64748b;display:block;margin-bottom:4px;">Filtro por Pasillo (opcional)</label>
                            <input type="text" id="asig-filtro-pasillo" placeholder="Ej: A, PA-01..."
                                style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;box-sizing:border-box;">
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <label style="font-size:0.75rem;font-weight:600;color:#64748b;display:block;margin-bottom:6px;">Planillas a asignar (selecciona las que deseas enviar a separar):</label>
                        <div id="asig-planillas-check" style="display:flex;flex-wrap:wrap;gap:6px;min-height:36px;padding:8px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                            <span style="color:#94a3b8;font-size:0.82rem;">Seleccione un archivo primero...</span>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <button onclick="window.Picking._asignarPorPlanilla(false)"
                            style="padding:11px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.87rem;cursor:pointer;font-weight:700;">
                            <i class="fa-solid fa-user-tag"></i> Asignar
                        </button>
                        <button onclick="window.Picking._asignarPorPlanilla(true)"
                            style="padding:11px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.87rem;cursor:pointer;font-weight:700;">
                            <i class="fa-solid fa-route"></i> Asignar + Ruta FEFO
                        </button>
                    </div>
                    <div id="asig-planilla-result" style="display:none;margin-top:12px;"></div>
                </div>
            </div>

            <!-- Panel Por Orden (existing behavior) -->
            <div id="asig-panel-orden" style="display:none;">
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Estado</label>
                            <select id="asig-estado" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:0.83rem;background:white;box-sizing:border-box;">
                                <option value="Pendiente">Pendientes</option>
                                <option value="EnProceso">En Proceso</option>
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Planilla</label>
                            <input type="text" id="asig-f-cliente" placeholder="Número planilla..."
                                style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:0.83rem;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Pasillo</label>
                            <input type="text" id="asig-f-pasillo" placeholder="Ej: A, B..."
                                style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:0.83rem;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="font-size:0.7rem;font-weight:600;color:#64748b;display:block;margin-bottom:3px;">Marca</label>
                            <select id="asig-f-marca" style="width:100%;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;font-size:0.83rem;background:white;box-sizing:border-box;">
                                <option value="">Todas las marcas</option>
                            </select>
                        </div>
                    </div>
                    <button onclick="window.Picking.loadAsignacion()"
                        style="width:100%;padding:8px;background:#0f172a;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-magnifying-glass"></i> Buscar Órdenes
                    </button>
                </div>
                <div id="asig-ordenes" style="margin-bottom:14px;"></div>
                <div id="asig-acciones" style="display:none;position:sticky;bottom:0;background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 -4px 12px rgba(0,0,0,0.08);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <span style="font-weight:700;color:#0f172a;font-size:0.9rem;">Acciones masivas</span>
                        <span id="asig-count" style="font-size:0.8rem;background:#6366f120;color:#6366f1;border-radius:999px;padding:3px 10px;font-weight:700;">0 seleccionadas</span>
                    </div>
                    <div style="margin-bottom:10px;">
                        <select id="asig-auxiliar" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.85rem;background:white;">
                            <option value="">Sin cambio de auxiliar</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                        <button onclick="window.Picking._asignarSeleccionados(false)"
                            style="padding:9px 6px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.78rem;cursor:pointer;font-weight:600;text-align:center;">
                            <i class="fa-solid fa-user-tag"></i><br>Solo Asignar
                        </button>
                        <button onclick="window.Picking._asignarSeleccionados(true)"
                            style="padding:9px 6px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.78rem;cursor:pointer;font-weight:600;text-align:center;">
                            <i class="fa-solid fa-route"></i><br>Asignar + FEFO
                        </button>
                        <button onclick="window.Picking._soloGenerarRutas()"
                            style="padding:9px 6px;background:#f59e0b;color:white;border:none;border-radius:8px;font-size:0.78rem;cursor:pointer;font-weight:600;text-align:center;">
                            <i class="fa-solid fa-bolt"></i><br>Solo FEFO
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    },

    _asigTab(tab) {
        ['planilla','orden'].forEach(t => {
            const panel = document.getElementById('asig-panel-' + t);
            const btn   = document.getElementById('asig-tab-' + t);
            if (!panel || !btn) return;
            const active = t === tab;
            panel.style.display = active ? 'block' : 'none';
            btn.style.color = active ? '#6366f1' : '#94a3b8';
            btn.style.borderBottom = active ? '3px solid #6366f1' : '3px solid transparent';
            btn.style.fontWeight = active ? '700' : '600';
        });
    },

    async _cargarPlanillasDelArchivo() {
        const archivoId = document.getElementById('asig-archivo-id')?.value;
        const box = document.getElementById('asig-planillas-check');
        if (!box) return;
        if (!archivoId) { box.innerHTML = '<span style="color:#94a3b8;font-size:0.82rem;">Seleccione un archivo primero...</span>'; return; }
        try {
            const res = await window.api.get('/planillas/' + archivoId);
            const planillas = res.data?.planillas || [];
            if (!planillas.length) { box.innerHTML = '<span style="color:#94a3b8;">Sin planillas</span>'; return; }
            box.innerHTML = planillas.map(p =>
                `<label style="display:flex;align-items:center;gap:6px;background:white;border:1px solid #e2e8f0;border-radius:8px;padding:5px 10px;cursor:pointer;font-size:0.82rem;">
                    <input type="checkbox" value="${escHTML(p.numero_planilla)}" checked style="cursor:pointer;">
                    <span style="font-weight:600;color:#334155;">Planilla ${escHTML(p.numero_planilla)}</span>
                    <span style="color:#94a3b8;font-size:0.75rem;">(${p.total_unidades?.toLocaleString() || 0} uds)</span>
                    ${p.estado_cert && p.estado_cert !== 'Pendiente' ? `<span style="font-size:0.7rem;background:#dcfce7;color:#16a34a;padding:1px 6px;border-radius:99px;">${p.estado_cert}</span>` : ''}
                </label>`
            ).join('') + `<button onclick="window.Picking._toggleAllPlanillas()"
                style="padding:4px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:0.78rem;cursor:pointer;align-self:center;">
                Todos/Ninguno</button>`;
        } catch(e) { box.innerHTML = `<span style="color:#ef4444;">${escHTML(e.message)}</span>`; }
    },

    _toggleAllPlanillas() {
        const checks = document.querySelectorAll('#asig-planillas-check input[type=checkbox]');
        const allChecked = Array.from(checks).every(c => c.checked);
        checks.forEach(c => c.checked = !allChecked);
    },

    async _asignarPorPlanilla(conRuta) {
        const archivoId  = parseInt(document.getElementById('asig-archivo-id')?.value || '0');
        const auxiliarId = parseInt(document.getElementById('asig-aux-planilla')?.value || '0');
        const modo       = document.getElementById('asig-modo')?.value || 'por_planilla';
        const pasillo    = document.getElementById('asig-filtro-pasillo')?.value?.trim() || '';
        const result     = document.getElementById('asig-planilla-result');

        if (!archivoId)  return window.showToast('Seleccione el archivo de planilla', 'error');
        if (!auxiliarId) return window.showToast('Seleccione el auxiliar', 'error');

        const planillasSeleccionadas = Array.from(
            document.querySelectorAll('#asig-planillas-check input[type=checkbox]:checked')
        ).map(c => c.value);

        if (!planillasSeleccionadas.length) return window.showToast('Seleccione al menos una planilla', 'error');

        const payload = { archivo_id: archivoId, auxiliar_id: auxiliarId, modo, planillas: planillasSeleccionadas, filtro_pasillo: pasillo };
        if (result) { result.style.display = 'block'; result.innerHTML = '<div style="text-align:center;padding:12px;color:#6366f1;"><i class="fa-solid fa-spinner fa-spin"></i> Creando órdenes...</div>'; }

        try {
            const data = await window.api.post('/planillas/asignar', payload);
            if (result) {
                result.innerHTML = `<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;color:#166534;">
                    <i class="fa-solid fa-circle-check"></i> <strong>${data.message || 'Asignado'}</strong>
                </div>`;
            }
            if (conRuta) {
                // Generate FEFO routes for created orders
                for (const id of (data.data?.orden_ids || [])) {
                    await window.api.post(`/picking/${id}/generar-ruta`, {}).catch(() => {});
                }
                window.showToast('Órdenes creadas y rutas FEFO generadas', 'success');
            } else {
                window.showToast(data.message || 'Órdenes creadas', 'success');
            }
        } catch(e) {
            if (result) {
                result.innerHTML = `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px;color:#dc2626;">
                    <i class="fa-solid fa-triangle-exclamation"></i> ${escHTML(e.message)}
                </div>`;
            }
        }
    },

    _asigSelected: [],
    _marcasCache: [],

    async loadAsignacion() {
        const lista = document.getElementById('asig-ordenes');
        if (!lista) return;
        this._asigSelected = [];
        lista.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';

        // Leer filtros
        const estado    = document.getElementById('asig-estado')?.value || '';
        const pasillo   = document.getElementById('asig-f-pasillo')?.value?.trim() || '';
        const ubicacion = document.getElementById('asig-f-ubicacion')?.value?.trim() || '';
        const marcaId   = document.getElementById('asig-f-marca')?.value || '';
        const sinAux    = document.getElementById('asig-f-sinaux')?.value || '';
        const cliente   = document.getElementById('asig-f-cliente')?.value?.trim() || '';

        // Construir query string
        const qp = new URLSearchParams({ limit: '200' });
        if (estado)    qp.set('estado', estado);
        if (pasillo)   qp.set('pasillo', pasillo);
        if (ubicacion) qp.set('ubicacion', ubicacion);
        if (marcaId)   qp.set('marca_id', marcaId);
        if (sinAux)    qp.set('sin_auxiliar', '1');
        if (cliente)   qp.set('cliente', cliente);

        try {
            const [ordenesData, personalData, marcasData] = await Promise.all([
                window.api.get('/picking?' + qp.toString()),
                window.api.get('/param/personal').catch(() => ({ data: [] })),
                this._marcasCache.length ? Promise.resolve({ data: this._marcasCache })
                    : window.api.get('/param/marcas').catch(() => ({ data: [] })),
            ]);

            const ordenes  = ordenesData?.data || ordenesData || [];
            const personal = personalData?.data || personalData || [];
            const marcas   = marcasData?.data || marcasData || [];
            if (marcas.length) this._marcasCache = marcas;

            // Llenar selects
            const marcaSel = document.getElementById('asig-f-marca');
            if (marcaSel && marcaSel.options.length <= 1 && marcas.length) {
                marcaSel.innerHTML = '<option value="">Todas las marcas</option>' +
                    marcas.map(m => `<option value="${parseInt(m.id)}">${escHTML(m.nombre)}</option>`).join('');
            }
            const auxSel = document.getElementById('asig-auxiliar');
            if (auxSel) {
                const cur = auxSel.value;
                auxSel.innerHTML = '<option value="">Sin cambio de auxiliar</option>' +
                    personal.map(p => `<option value="${parseInt(p.id)}">${escHTML(p.nombre)}${p.cargo ? ' — '+escHTML(p.cargo) : ''}</option>`).join('');
                if (cur) auxSel.value = cur;
            }

            if (!ordenes.length) {
                lista.innerHTML = `<div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:40px;text-align:center;color:#94a3b8;">
                    <i class="fa-solid fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                    Sin órdenes con los filtros aplicados</div>`;
                document.getElementById('asig-acciones').style.display = 'none';
                return;
            }

            document.getElementById('asig-acciones').style.display = 'block';
            const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e', Cancelada:'#94a3b8' };

            lista.innerHTML = `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <div style="padding:10px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" id="asig-all" onchange="window.Picking._toggleAllAsig(this.checked)"
                        style="width:16px;height:16px;cursor:pointer;">
                    <label for="asig-all" style="font-size:0.82rem;color:#475569;cursor:pointer;font-weight:600;flex:1;">
                        Seleccionar todo (${ordenes.length} órdenes)
                    </label>
                    <span style="font-size:0.75rem;color:#94a3b8;">Prioridad · Cliente · Auxiliar</span>
                </div>
                ${ordenes.map(o => {
                    const color = sc[o.estado]||'#64748b';
                    const auxNombre = o.auxiliar?.nombre || (o.auxiliar_id ? `#${o.auxiliar_id}` : null);
                    const lineas = o.detalles?.length || 0;
                    return `
                    <div style="padding:11px 14px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" class="asig-chk" value="${parseInt(o.id)}"
                            onchange="window.Picking._updateAsigCount()"
                            style="width:16px;height:16px;flex-shrink:0;cursor:pointer;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px;">
                                <span style="font-weight:700;color:#0f172a;font-size:0.87rem;">${escHTML(o.numero_orden)}</span>
                                <span style="font-size:0.67rem;background:${color}20;color:${color};border-radius:999px;padding:2px 7px;font-weight:700;">${o.estado}</span>
                                ${(o.prioridad||5) <= 2 ? '<span style="font-size:0.65rem;background:#fee2e2;color:#dc2626;border-radius:999px;padding:2px 6px;font-weight:700;">URGENTE</span>' : ''}
                            </div>
                            <div style="font-size:0.77rem;color:#475569;display:flex;gap:10px;flex-wrap:wrap;">
                                <span><i class="fa-solid fa-user" style="color:#cbd5e1;margin-right:3px;"></i>${escHTML(o.cliente||'Sin cliente')}</span>
                                ${auxNombre ? `<span style="color:#6366f1;font-weight:600;"><i class="fa-solid fa-helmet-safety" style="margin-right:3px;"></i>${escHTML(auxNombre)}</span>` : '<span style="color:#f59e0b;font-style:italic;">Sin auxiliar</span>'}
                                <span style="color:#94a3b8;">${lineas} línea${lineas!==1?'s':''}</span>
                            </div>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div style="font-size:0.82rem;font-weight:700;color:#475569;">P${o.prioridad||5}</div>
                            <div style="font-size:0.7rem;color:#94a3b8;">${o.fecha_requerida||'—'}</div>
                        </div>
                    </div>`;
                }).join('')}
            </div>`;
        } catch(e) {
            lista.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;background:white;border-radius:12px;">${escHTML(e.message)}</div>`;
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
        if (el) el.textContent = `${this._asigSelected.length} seleccionadas`;
    },

    async _asignarSeleccionados(generarRuta = false) {
        if (!this._asigSelected.length) return window.showToast('Seleccione al menos una orden','error');
        const auxId = document.getElementById('asig-auxiliar')?.value;
        if (!auxId && !generarRuta) return window.showToast('Seleccione un auxiliar o use "Solo FEFO"','error');

        const accion = generarRuta ? 'asignar + generar ruta FEFO' : 'asignar auxiliar';
        if (!confirm(`¿${accion} a ${this._asigSelected.length} orden(es)?`)) return;

        try {
            const body = {
                orden_ids:    this._asigSelected,
                generar_ruta: generarRuta,
            };
            if (auxId) body.auxiliar_id = parseInt(auxId);

            const data = await window.api.post('/picking/asignar-multiple', body);
            const r = data?.data || data;
            const msg = `Asignadas: ${r.asignadas||0} · Rutas: ${r.rutas_generadas||0}${r.errores?.length ? ` · Errores: ${r.errores.length}` : ''}`;
            window.showToast(msg, (r.errores?.length && !r.asignadas) ? 'error' : 'success');
            this.loadAsignacion();
        } catch(e) { window.showToast(e.message,'error'); }
    },

    async _soloGenerarRutas() {
        if (!this._asigSelected.length) return window.showToast('Seleccione al menos una orden','error');
        if (!confirm(`¿Generar ruta FEFO para ${this._asigSelected.length} orden(es) pendientes?`)) return;
        try {
            const data = await window.api.post('/picking/asignar-multiple', {
                orden_ids: this._asigSelected,
                generar_ruta: true,
            });
            const r = data?.data || data;
            window.showToast(`Rutas generadas: ${r.rutas_generadas||0}`, 'success');
            this.loadAsignacion();
        } catch(e) { window.showToast(e.message,'error'); }
    },

    /* ── Vista Consolidado — agrupa órdenes por cliente ─────────────────── */
    getConsolidadoHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <span style="font-weight:700;color:#0f172a;font-size:1rem;">Picking por Consolidado</span>
                    <p style="color:#64748b;font-size:0.78rem;margin:4px 0 0;">Agrupa todas las órdenes de un cliente para separar en un solo proceso</p>
                </div>
                <button onclick="window.Picking.loadConsolidados()"
                    style="padding:7px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem;cursor:pointer;">
                    <i class="fa-solid fa-rotate"></i>
                </button>
            </div>
            <div id="consol-lista"></div>
        </div>`;
    },

    async loadConsolidados() {
        const lista = document.getElementById('consol-lista');
        if (!lista) return;
        lista.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i></div>';
        try {
            const data = await window.api.get('/picking/consolidados');
            const grupos = data?.data || data || [];
            if (!grupos.length) {
                lista.innerHTML = `<div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:40px;text-align:center;color:#94a3b8;">
                    <i class="fa-solid fa-users" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
                    Sin órdenes pendientes para consolidar</div>`;
                return;
            }
            lista.innerHTML = grupos.map(g => {
                const pct = g.total_ordenes > 0
                    ? Math.round(((g.ordenes_en_proceso) / g.total_ordenes) * 100) : 0;
                const hayUrgentes = g.prioridad_max <= 2;
                return `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                <span style="font-weight:700;color:#0f172a;font-size:0.95rem;">${escHTML(g.cliente)}</span>
                                ${hayUrgentes ? '<span style="font-size:0.68rem;background:#fee2e2;color:#dc2626;border-radius:999px;padding:2px 8px;font-weight:700;">URGENTE</span>' : ''}
                            </div>
                            <div style="font-size:0.78rem;color:#475569;display:flex;gap:12px;flex-wrap:wrap;">
                                <span><i class="fa-solid fa-layer-group" style="color:#94a3b8;margin-right:3px;"></i>${g.total_ordenes} orden(es)</span>
                                <span><i class="fa-solid fa-box" style="color:#94a3b8;margin-right:3px;"></i>${g.total_productos_unicos} producto(s) únicos</span>
                                <span style="color:#f59e0b;">${g.ordenes_pendientes} pendiente(s)</span>
                                <span style="color:#3b82f6;">${g.ordenes_en_proceso} en proceso</span>
                            </div>
                        </div>
                        <div style="flex-shrink:0;text-align:right;">
                            <div style="font-size:1.1rem;font-weight:800;color:#6366f1;">P${g.prioridad_max}</div>
                        </div>
                    </div>
                    ${g.total_ordenes > 0 ? `
                    <div style="margin-bottom:12px;">
                        <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                            <div style="height:100%;width:${pct}%;background:#3b82f6;border-radius:3px;transition:width .3s;"></div>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:#94a3b8;margin-top:3px;">
                            <span>En proceso: ${pct}%</span>
                            <span>${g.ordenes_en_proceso}/${g.total_ordenes}</span>
                        </div>
                    </div>` : ''}
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button onclick="window.Picking._verOrdenesConsolidado('${escHTML(g.cliente)}')"
                            style="flex:1;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:0.8rem;cursor:pointer;font-weight:600;min-width:100px;">
                            <i class="fa-solid fa-list"></i> Ver Órdenes
                        </button>
                        ${g.ordenes_pendientes > 0 ? `
                        <button onclick="window.Picking._generarRutasConsolidado('${escHTML(g.cliente)}', ${JSON.stringify(g.ordenes.filter(o=>o.estado==='Pendiente').map(o=>o.id))})"
                            style="flex:1;padding:8px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:0.8rem;cursor:pointer;font-weight:600;min-width:120px;">
                            <i class="fa-solid fa-route"></i> Generar Rutas FEFO
                        </button>` : ''}
                    </div>
                </div>`;
            }).join('');
        } catch(e) {
            lista.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;background:white;border-radius:12px;">${escHTML(e.message)}</div>`;
        }
    },

    async _verOrdenesConsolidado(cliente) {
        // Abrir modal con órdenes del cliente
        document.getElementById('pk-consol-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'pk-consol-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9990;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
        modal.innerHTML = `<div style="background:white;border-radius:16px;width:100%;max-width:600px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="margin:0;font-size:1rem;">${escHTML(cliente)}</h3>
                <button onclick="document.getElementById('pk-consol-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="pk-consol-body" style="padding:16px 20px;max-height:70vh;overflow-y:auto;">
                <div style="text-align:center;padding:20px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
        try {
            const data = await window.api.get(`/picking?cliente=${encodeURIComponent(cliente)}&limit=100`);
            const ordenes = data?.data || data || [];
            const sc = { Pendiente:'#f59e0b', EnProceso:'#3b82f6', Completada:'#22c55e' };
            document.getElementById('pk-consol-body').innerHTML = ordenes.map(o => {
                const color = sc[o.estado]||'#64748b';
                return `<div style="padding:10px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-weight:700;color:#0f172a;font-size:0.88rem;">${escHTML(o.numero_orden)}</div>
                        <div style="font-size:0.75rem;color:#64748b;">${o.detalles?.length||0} líneas · P${o.prioridad||5}</div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <span style="font-size:0.7rem;background:${color}20;color:${color};border-radius:999px;padding:2px 8px;font-weight:700;">${o.estado}</span>
                        <button onclick="document.getElementById('pk-consol-modal').remove();window.Picking.verDetalle(${parseInt(o.id)})"
                            style="padding:5px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:0.75rem;cursor:pointer;">Ver</button>
                    </div>
                </div>`;
            }).join('') || '<div style="text-align:center;color:#94a3b8;padding:20px;">Sin órdenes</div>';
        } catch(e) { document.getElementById('pk-consol-body').innerHTML = `<div style="color:#ef4444;">${escHTML(e.message)}</div>`; }
    },

    async _generarRutasConsolidado(cliente, ordenIds) {
        if (!ordenIds?.length) return;
        if (!confirm(`¿Generar ruta FEFO para ${ordenIds.length} orden(es) pendientes de "${cliente}"?`)) return;
        try {
            const data = await window.api.post('/picking/asignar-multiple', {
                orden_ids: ordenIds,
                generar_ruta: true,
            });
            const r = data?.data || data;
            window.showToast(`Rutas generadas: ${r.rutas_generadas||0}`, 'success');
            this.loadConsolidados();
        } catch(e) { window.showToast(e.message,'error'); }
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

    /* ── Inicializar Asignación (poblar selects + pre-selección por archivo) */
    async initAsignacion() {
        // Load archivos and personal for "Por Planilla" tab in parallel
        try {
            const [archivosRes, personalRes] = await Promise.all([
                window.api.get('/planillas').catch(() => ({ data: [] })),
                window.api.get('/param/personal').catch(() => ({ data: [] })),
            ]);
            const archivos  = archivosRes?.data || [];
            const personal  = personalRes?.data || personalRes || [];

            // Populate archivo select (only active picking states)
            const archSel = document.getElementById('asig-archivo-id');
            if (archSel) {
                const opciones = archivos.filter(a => ['Importada','EnPicking'].includes(a.estado));
                if (opciones.length) {
                    archSel.innerHTML = '<option value="">Seleccione archivo...</option>' +
                        opciones.map(a => `<option value="${parseInt(a.id)}">${escHTML(a.nombre_archivo)} — ${a.estado}</option>`).join('');
                }
            }

            // Populate auxiliar select for planilla tab
            const auxSel = document.getElementById('asig-aux-planilla');
            if (auxSel && personal.length) {
                auxSel.innerHTML = '<option value="">Seleccione auxiliar...</option>' +
                    personal.map(p => `<option value="${parseInt(p.id)}">${escHTML(p.nombre)}${p.cargo ? ' — ' + escHTML(p.cargo) : ''}</option>`).join('');
            }

            // Also populate auxiliar select for "Por Orden" tab
            const auxSelOrden = document.getElementById('asig-auxiliar');
            if (auxSelOrden && personal.length) {
                auxSelOrden.innerHTML = '<option value="">Sin cambio de auxiliar</option>' +
                    personal.map(p => `<option value="${parseInt(p.id)}">${escHTML(p.nombre)}${p.cargo ? ' — ' + escHTML(p.cargo) : ''}</option>`).join('');
            }

            // If navigated from "Asignar" button in import page, pre-select that archivo
            if (window._asignarArchivoId) {
                if (archSel) {
                    archSel.value = String(window._asignarArchivoId);
                    // If the option wasn't available (might be in a different state), add it
                    if (!archSel.value || archSel.value !== String(window._asignarArchivoId)) {
                        const found = archivos.find(a => a.id == window._asignarArchivoId);
                        if (found) {
                            const opt = document.createElement('option');
                            opt.value = found.id;
                            opt.textContent = found.nombre_archivo + ' — ' + found.estado;
                            archSel.appendChild(opt);
                            archSel.value = String(found.id);
                        }
                    }
                    await this._cargarPlanillasDelArchivo();
                }
                window._asignarArchivoId = null;
            }
        } catch(e) {
            console.error('initAsignacion error:', e);
        }

        // Also load the "Por Orden" tab data
        this.loadAsignacion();
    },

    init() { this.loadDashboard(); this.loadPlanillasProgreso(); },
    crearBatch(a) { this.abrirImportar(); },

    // ── Habilitar certificación anticipada (Supervisor/Admin) ─────────────────
    async habilitarCertificacion(archivoId, nombre) {
        const msg = `¿Habilitar certificación para "${nombre}" aunque el picking no esté al 100%?\n\nSolo se certificarán los productos ya separados. Esta acción queda en el log de auditoría.`;
        if (!confirm(msg)) return;
        try {
            const res = await window.api.post(`/planillas/${archivoId}/habilitar-cert`, {});
            window.showToast(res.message || 'Certificación habilitada', 'success');
            this.loadPlanillasProgreso(); // refrescar el panel
        } catch(e) {
            window.showToast(e.message || 'Error al habilitar', 'error');
        }
    },

    // ═══════════════════════════════════════════════════════════════════════════
    // MODO SEPARACIÓN GUIADO — UI optimizada para móvil
    // Muestra UN producto a la vez con ubicación prominente, lote y vencimiento.
    // El auxiliar escanea/confirma y el sistema avanza al siguiente automáticamente.
    // ═══════════════════════════════════════════════════════════════════════════

    _sepOrdenId: null,
    _sepModal: null,

    async iniciarSeparacion(ordenId) {
        this._sepOrdenId = ordenId;

        // Crear modal de pantalla completa
        const modal = document.createElement('div');
        modal.id = 'sep-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:#0f172a;z-index:99999;display:flex;flex-direction:column;overflow:hidden;';
        modal.innerHTML = `
        <!-- Header -->
        <div style="background:#1e293b;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;border-bottom:1px solid #334155;">
            <div>
                <div id="sep-numero" style="font-weight:700;color:white;font-size:0.95rem;"></div>
                <div id="sep-cliente" style="font-size:0.75rem;color:#94a3b8;margin-top:2px;"></div>
            </div>
            <button onclick="window.Picking._cerrarSeparacion()"
                style="width:36px;height:36px;background:#374151;border:none;border-radius:8px;color:white;font-size:1.1rem;cursor:pointer;">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <!-- Barra de progreso -->
        <div style="background:#1e293b;padding:10px 16px;flex-shrink:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Progreso</span>
                <span id="sep-prog-txt" style="font-size:0.78rem;font-weight:700;color:#94a3b8;"></span>
            </div>
            <div style="background:#334155;border-radius:999px;height:6px;overflow:hidden;">
                <div id="sep-prog-bar" style="height:100%;background:#22c55e;border-radius:999px;transition:width 0.4s;width:0%;"></div>
            </div>
        </div>
        <!-- Contenido principal -->
        <div id="sep-body" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:12px;">
            <div style="text-align:center;padding:40px;color:#64748b;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;"></i>
            </div>
        </div>`;
        document.body.appendChild(modal);
        this._sepModal = modal;

        await this._cargarSiguienteLinea();
    },

    async _cargarSiguienteLinea() {
        const body = document.getElementById('sep-body');
        if (!body) return;
        try {
            const data = await window.api.get(`/picking/${this._sepOrdenId}/siguiente-linea`);

            // Actualizar header
            const orden = data?.orden || {};
            const el = document.getElementById('sep-numero');
            if (el) el.textContent = orden.numero_orden || '';
            const elC = document.getElementById('sep-cliente');
            if (elC) elC.textContent = orden.cliente || '';

            // Actualizar progreso
            const prog = data?.progreso || {};
            const barEl  = document.getElementById('sep-prog-bar');
            const txtEl  = document.getElementById('sep-prog-txt');
            if (barEl) barEl.style.width = (prog.pct || 0) + '%';
            if (txtEl) txtEl.textContent = `${prog.confirmadas || 0} / ${prog.total || 0} ítems`;

            if (data?.completada) {
                this._mostrarCompletadaSeparacion(prog);
                return;
            }

            const l = data.linea;
            const diasVencer = l.fecha_vencimiento
                ? Math.round((new Date(l.fecha_vencimiento) - new Date()) / 86400000)
                : null;
            const colorVencer = diasVencer === null ? '#64748b'
                : diasVencer < 0 ? '#dc2626' : diasVencer <= 30 ? '#ef4444' : diasVencer <= 90 ? '#f59e0b' : '#22c55e';

            body.innerHTML = `
            <!-- Destino: código de ubicación MUY grande -->
            <div style="background:#1e40af;border-radius:16px;padding:20px;text-align:center;">
                <div style="font-size:0.72rem;color:#93c5fd;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">
                    <i class="fa-solid fa-map-pin"></i> Ir a ubicación
                </div>
                <div style="font-size:2.6rem;font-weight:900;color:white;letter-spacing:0.05em;line-height:1;">
                    ${escHTML(l.ubicacion_codigo)}
                </div>
                ${l.pasillo ? `<div style="font-size:0.82rem;color:#bfdbfe;margin-top:4px;">Pasillo ${escHTML(l.pasillo)}${l.nivel ? ' · Nivel ' + escHTML(l.nivel) : ''}</div>` : ''}
            </div>

            <!-- Producto -->
            <div style="background:#1e293b;border-radius:16px;padding:18px;">
                <div style="font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Producto a separar</div>
                <div style="font-size:1.15rem;font-weight:700;color:white;line-height:1.3;margin-bottom:6px;">${escHTML(l.producto_nombre)}</div>
                <div style="font-size:0.8rem;color:#94a3b8;">Código: <strong style="color:#e2e8f0;">${escHTML(l.producto_codigo)}</strong></div>
                ${l.producto_ean ? `<div style="font-size:0.8rem;color:#94a3b8;margin-top:2px;">EAN: <strong style="color:#e2e8f0;">${escHTML(l.producto_ean)}</strong></div>` : ''}
            </div>

            <!-- Lote + Vencimiento + Cantidad -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:#1e293b;border-radius:12px;padding:14px;">
                    <div style="font-size:0.68rem;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Lote</div>
                    <div style="font-size:1rem;font-weight:700;color:white;">${escHTML(l.lote || 'N/A')}</div>
                </div>
                <div style="background:#1e293b;border-radius:12px;padding:14px;">
                    <div style="font-size:0.68rem;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Vencimiento</div>
                    <div style="font-size:0.85rem;font-weight:700;color:${colorVencer};">
                        ${l.fecha_vencimiento ? escHTML(l.fecha_vencimiento.substring(0,10)) : 'Sin fecha'}
                        ${diasVencer !== null ? `<br><span style="font-size:0.7rem;">${diasVencer < 0 ? '⚠ VENCIDO' : diasVencer + ' días'}</span>` : ''}
                    </div>
                </div>
            </div>

            <!-- Cantidad solicitada -->
            <div style="background:#064e3b;border:2px solid #065f46;border-radius:16px;padding:18px;text-align:center;">
                <div style="font-size:0.72rem;color:#6ee7b7;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Separar</div>
                <div style="font-size:3rem;font-weight:900;color:#34d399;line-height:1;">${parseFloat(l.cantidad_solicitada)}</div>
                <div style="font-size:0.82rem;color:#6ee7b7;">unidades</div>
            </div>

            <!-- Input de confirmación -->
            <div style="background:#1e293b;border-radius:16px;padding:16px;">
                <label style="font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;display:block;margin-bottom:8px;">
                    <i class="fa-solid fa-barcode"></i> Escanear código o confirmar cantidad
                </label>
                <input type="text" id="sep-scan-input" inputmode="numeric"
                    placeholder="Escanee EAN o ingrese cantidad..."
                    style="width:100%;padding:14px;background:#0f172a;border:2px solid #334155;border-radius:10px;color:white;font-size:1.1rem;box-sizing:border-box;text-align:center;"
                    onkeydown="if(event.key==='Enter') window.Picking._confirmarYavanzar(${parseInt(l.id)}, ${parseFloat(l.cantidad_solicitada)}, ${l.producto_ean ? `'${escHTML(String(l.producto_ean))}'` : 'null'})">
            </div>

            <!-- Botones -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding-bottom:24px;">
                <button onclick="window.Picking._marcarFaltanteYavanzar(${parseInt(l.id)})"
                    style="padding:14px;background:#374151;border:none;border-radius:12px;color:#94a3b8;font-size:0.88rem;font-weight:600;cursor:pointer;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Faltante
                </button>
                <button onclick="window.Picking._confirmarYavanzar(${parseInt(l.id)}, ${parseFloat(l.cantidad_solicitada)}, ${l.producto_ean ? `'${escHTML(String(l.producto_ean))}'` : 'null'})"
                    style="padding:14px;background:#22c55e;border:none;border-radius:12px;color:white;font-size:0.88rem;font-weight:700;cursor:pointer;">
                    <i class="fa-solid fa-check"></i> Confirmar
                </button>
            </div>`;

            // Auto-focus al input de escaneo
            setTimeout(() => document.getElementById('sep-scan-input')?.focus(), 100);

        } catch(e) {
            if (body) body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;margin-bottom:12px;display:block;"></i>
                ${escHTML(e.message || 'Error al cargar')}</div>`;
        }
    },

    async _confirmarYavanzar(lineaId, cantidadEsperada, ean) {
        const inputEl = document.getElementById('sep-scan-input');
        const val = (inputEl?.value || '').trim();

        // Si ingresó un EAN y coincide con el producto → usar cantidad esperada
        let cantidad = cantidadEsperada;
        if (val !== '' && !isNaN(Number(val)) && Number(val) > 0) {
            cantidad = Number(val);
        } else if (val !== '' && ean && val === String(ean)) {
            cantidad = cantidadEsperada; // escaneo correcto
        } else if (val !== '' && !isNaN(Number(val))) {
            cantidad = Number(val);
        }

        try {
            await window.api.post(`/picking/${this._sepOrdenId}/confirmar-linea`, {
                linea_id:       lineaId,
                cantidad_tomada: Math.max(1, Math.round(cantidad)),
            });
            // Vibración háptica si disponible (móvil)
            if (navigator.vibrate) navigator.vibrate(60);
            await this._cargarSiguienteLinea();
        } catch(e) {
            window.showToast(e.message || 'Error al confirmar', 'error');
        }
    },

    async _marcarFaltanteYavanzar(lineaId) {
        if (!confirm('¿Marcar este producto como FALTANTE y continuar?')) return;
        try {
            await window.api.post(`/picking/${this._sepOrdenId}/confirmar-linea`, {
                linea_id:       lineaId,
                cantidad_tomada: 0,
            });
            await this._cargarSiguienteLinea();
        } catch(e) {
            // Some backends reject qty=0, just mark and move on
            window.showToast('Producto marcado como faltante', 'warning');
            await this._cargarSiguienteLinea();
        }
    },

    _mostrarCompletadaSeparacion(prog) {
        const body = document.getElementById('sep-body');
        if (!body) return;
        if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
        body.innerHTML = `
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px;gap:20px;">
            <div style="width:80px;height:80px;background:#064e3b;color:#34d399;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.5rem;">
                <i class="fa-solid fa-check"></i>
            </div>
            <div>
                <h2 style="color:white;margin:0 0 8px;font-size:1.4rem;">¡Separación completada!</h2>
                <p style="color:#94a3b8;margin:0;font-size:0.9rem;">${prog.confirmadas} de ${prog.total} ítems procesados</p>
            </div>
            <button onclick="window.Picking._cerrarSeparacion()"
                style="padding:16px 32px;background:#22c55e;color:white;border:none;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;width:100%;max-width:280px;">
                <i class="fa-solid fa-door-open"></i> Cerrar
            </button>
        </div>`;
    },

    _cerrarSeparacion() {
        const m = document.getElementById('sep-modal');
        if (m) m.remove();
        this._sepModal = null;
        this._sepOrdenId = null;
        // Refrescar la lista de picking
        this.loadOrdenes?.();
        this.loadDashboard?.();
    },
};
