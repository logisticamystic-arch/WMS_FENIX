/**
 * Prooriente WMS - Devoluciones Module
 */
window.Devoluciones = {
    _selectedProducto: null,
    _buscarTimer: null,

    getDevolucionesHTML() {
        return `
            <div style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:700px; margin:0 auto;">
                <div style="text-align:center; margin-bottom:24px;">
                    <div style="width:60px; height:60px; background:#fff7ed; color:#f97316; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:1.5rem;">
                        <i class="fa-solid fa-rotate-left"></i>
                    </div>
                    <h3 style="margin:0; color:#0f172a;">Registro de Devolución</h3>
                    <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Reingreso de mercancía por avería, desistimiento o error</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label>Tipo de Devolución</label>
                        <select id="dev-tipo" class="input-field">
                            <option value="ReingresoBuenEstado">Reingreso Buen Estado</option>
                            <option value="Averia">Avería / Mal Estado</option>
                            <option value="Vencido">Producto Vencido</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Proveedor / Origen</label>
                        <input type="text" id="dev-prov" class="input-field" placeholder="Nombre">
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label>Producto a Devolver</label>
                        <input type="text" id="dev-prod-search" class="input-field"
                            placeholder="Escanee EAN, código o nombre..."
                            oninput="window.Devoluciones._buscarDebounce()">
                        <div id="dev-prod-resultados" style="display:none; border:1px solid #e2e8f0; border-radius:8px; background:white; max-height:180px; overflow-y:auto; margin-top:4px;"></div>
                        <div id="dev-prod-info" style="margin-top:6px; font-size:0.85rem; color:#16a34a; display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; padding:8px;"></div>
                    </div>
                    <div class="input-group">
                        <label>Cantidad</label>
                        <input type="number" id="dev-cant" class="input-field" value="1" min="1">
                    </div>
                    <div class="input-group">
                        <label>Destino Interno</label>
                        <select id="dev-dest" class="input-field">
                            <option value="Patio">Patio Recepción</option>
                            <option value="InventarioObsoleto">Zona de Bajas / Obsoletos</option>
                        </select>
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label>Motivo / Observaciones</label>
                        <textarea id="dev-notas" class="input-field" style="height:80px;"></textarea>
                    </div>
                </div>

                <button class="btn-primary" style="background:#f97316; margin-top:20px;"
                    onclick="window.Devoluciones.guardarDevolucion()">
                    <i class="fa-solid fa-save"></i> Procesar Devolución
                </button>
            </div>`;
    },

    /* ── Historial ─────────────────────────────────────────────────────────── */
    getHistorialHTML() {
        return `
        <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
            <h4 style="margin:0 0 16px; color:#0f172a;">Historial de Devoluciones</h4>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.88rem;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                            <th style="padding:10px 8px;">#</th>
                            <th style="padding:10px 8px;">Fecha</th>
                            <th style="padding:10px 8px;">Tipo</th>
                            <th style="padding:10px 8px;">Proveedor</th>
                            <th style="padding:10px 8px;">Estado</th>
                            <th style="padding:10px 8px;"></th>
                        </tr>
                    </thead>
                    <tbody id="dev-historial-tbody">
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>`;
    },

    async loadHistorial() {
        const tbody = document.getElementById('dev-historial-tbody');
        if (!tbody) return;
        try {
            const res = await window.api.get('/devoluciones');
            const rows = res.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">Sin devoluciones registradas.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(d => `
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px 8px; font-weight:600;">#${escHTML(String(d.id))}</td>
                <td style="padding:10px 8px; color:#475569;">${escHTML(d.created_at || '—')}</td>
                <td style="padding:10px 8px;">
                    <span style="background:#fff7ed; color:#c2410c; padding:3px 8px; border-radius:6px; font-size:0.78rem; font-weight:600;">${escHTML(d.tipo || '—')}</span>
                </td>
                <td style="padding:10px 8px; color:#475569;">${escHTML(d.proveedor || '—')}</td>
                <td style="padding:10px 8px;">
                    <span style="color:${d.estado === 'Procesada' ? '#22c55e' : '#f59e0b'}; font-weight:600;">${escHTML(d.estado || '—')}</span>
                </td>
                <td style="padding:10px 8px;">
                    <button data-dev-id="${parseInt(d.id)}"
                        onclick="window.Devoluciones.verDetalle(parseInt(this.dataset.devId))"
                        style="padding:4px 10px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; font-size:0.78rem; color:#334155; cursor:pointer;">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </td>
            </tr>`).join('');
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Error al cargar historial.</td></tr>';
        }
    },

    async verDetalle(id) {
        try {
            const res = await window.api.get(`/devoluciones/${id}`);
            const d = res.data || res;
            const detalles = d.detalles || [];
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
            modal.innerHTML = `
            <div style="background:white;border-radius:16px;width:100%;max-width:560px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-rotate-left"></i> Devolución #${parseInt(d.id)}</h3>
                    <button onclick="this.closest('[style*=fixed]').remove()"
                        style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div style="padding:16px 20px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;font-size:0.85rem;">
                        <div><span style="color:#64748b;">Tipo:</span> <strong>${escHTML(d.tipo || '—')}</strong></div>
                        <div><span style="color:#64748b;">Estado:</span> <strong>${escHTML(d.estado || '—')}</strong></div>
                        <div><span style="color:#64748b;">Proveedor:</span> <strong>${escHTML(d.proveedor || '—')}</strong></div>
                        <div><span style="color:#64748b;">Fecha:</span> <strong>${escHTML(d.created_at || '—')}</strong></div>
                        ${d.motivo_general ? `<div style="grid-column:span 2;"><span style="color:#64748b;">Motivo:</span> ${escHTML(d.motivo_general)}</div>` : ''}
                    </div>
                    <h4 style="margin:0 0 10px;font-size:0.85rem;color:#475569;text-transform:uppercase;letter-spacing:0.04em;">Productos</h4>
                    ${detalles.length ? detalles.map(det => `
                    <div style="display:flex;gap:10px;padding:10px;border:1px solid #f1f5f9;border-radius:8px;margin-bottom:8px;background:#f8fafc;">
                        <div style="flex:1;">
                            <div style="font-weight:600;font-size:0.88rem;">${escHTML(det.producto?.nombre || 'Producto #' + det.producto_id)}</div>
                            <div style="font-size:0.75rem;color:#64748b;">${escHTML(det.motivo || '—')} · Destino: ${escHTML(det.destino || '—')}</div>
                        </div>
                        <div style="font-weight:700;color:#f97316;">× ${parseFloat(det.cantidad)}</div>
                    </div>`).join('') : '<div style="color:#94a3b8;font-size:0.85rem;text-align:center;padding:16px;">Sin detalle</div>'}
                </div>
            </div>`;
            modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
            document.body.appendChild(modal);
        } catch (e) {
            window.showToast('Error al cargar detalle', 'error');
        }
    },

    /* ── Búsqueda con debounce ─────────────────────────────────────────────── */
    _buscarDebounce() {
        clearTimeout(this._buscarTimer);
        this._buscarTimer = setTimeout(() => this._buscarProducto(), 350);
    },

    async _buscarProducto() {
        const q = (document.getElementById('dev-prod-search')?.value || '').trim();
        const resEl = document.getElementById('dev-prod-resultados');
        if (!resEl || q.length < 2) { resEl && (resEl.style.display = 'none'); return; }
        resEl.style.display = 'block';
        resEl.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const res = await window.api.get('/param/productos/buscar?q=' + encodeURIComponent(q));
            const prods = res.data || [];
            if (!prods.length) {
                resEl.innerHTML = '<div style="padding:12px;color:#94a3b8;font-size:0.85rem;">Sin resultados</div>';
                return;
            }
            resEl.innerHTML = prods.map(p => `
            <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;"
                 onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background=''"
                 data-prod-id="${parseInt(p.id)}" data-prod-nombre="${escHTML(p.nombre)}" data-prod-codigo="${escHTML(p.codigo_interno)}"
                 onclick="window.Devoluciones._selProducto(parseInt(this.dataset.prodId), this.dataset.prodNombre, this.dataset.prodCodigo)">
                <div style="font-weight:600;font-size:0.88rem;color:#0f172a;">${escHTML(p.nombre)}</div>
                <div style="font-size:0.75rem;color:#64748b;">${escHTML(p.codigo_interno)} · ${escHTML(p.unidad_medida) || 'UN'}</div>
            </div>`).join('');
        } catch (e) {
            resEl.innerHTML = '<div style="padding:12px;color:#ef4444;font-size:0.85rem;">Error al buscar</div>';
        }
    },

    _selProducto(id, nombre, codigo) {
        this._selectedProducto = { id, nombre, codigo };
        document.getElementById('dev-prod-search').value = nombre;
        document.getElementById('dev-prod-resultados').style.display = 'none';
        const infoEl = document.getElementById('dev-prod-info');
        infoEl.textContent = '✓ ' + nombre + (codigo ? ' (' + codigo + ')' : '');
        infoEl.style.display = 'block';
        document.getElementById('dev-cant')?.focus();
    },

    /* ── Guardar devolución ────────────────────────────────────────────────── */
    async guardarDevolucion() {
        if (!this._selectedProducto) {
            return window.showToast('Seleccione un producto primero', 'error');
        }
        const cantidad = parseFloat(document.getElementById('dev-cant')?.value) || 0;
        if (cantidad <= 0) {
            return window.showToast('La cantidad debe ser mayor a 0', 'error');
        }
        const payload = {
            tipo:            document.getElementById('dev-tipo')?.value,
            proveedor:       document.getElementById('dev-prov')?.value?.trim() || null,
            motivo_general:  document.getElementById('dev-notas')?.value?.trim() || null,
            detalles: [{
                producto_id: this._selectedProducto.id,
                cantidad,
                destino: document.getElementById('dev-dest')?.value,
                motivo:  document.getElementById('dev-tipo')?.value,
            }],
        };
        try {
            await window.api.post('/devoluciones', payload);
            this._selectedProducto = null;
            window.showToast('Devolución registrada con éxito', 'success');
            // Reset form
            document.getElementById('dev-prod-search').value = '';
            document.getElementById('dev-prod-info').style.display = 'none';
            document.getElementById('dev-cant').value = '1';
            document.getElementById('dev-notas').value = '';
        } catch (e) {
            window.showToast(e.message || 'Error al registrar devolución', 'error');
        }
    },
};
