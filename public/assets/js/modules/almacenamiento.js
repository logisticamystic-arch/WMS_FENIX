/**
 * Prooriente WMS — Almacenamiento (Putaway & Traslado)
 * Conectado al backend PutawayController.
 */
window.Almacenamiento = {

    _paProduct:   null,
    _paStockItem: null,

    /* ===================================================================
       PUTAWAY — Acomodo de Patio a Rack
    =================================================================== */
    getPutawayHTML: function () {
        return `
        <div style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:600px; margin:0 auto;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="width:60px; height:60px; background:#f0fdf4; color:#22c55e; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:1.5rem;">
                    <i class="fa-solid fa-pallet"></i>
                </div>
                <h3 style="margin:0; color:#0f172a;">Putaway (Acomodo)</h3>
                <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Mueva mercancía del Patio a ubicaciones de Rack</p>
            </div>

            <div class="input-group">
                <label style="font-weight:700; color:#0f172a;">1. Escanear producto en Patio</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="pa-scan" class="input-field" placeholder="EAN o Código Interno"
                        onkeydown="if(event.key==='Enter') window.Almacenamiento.buscarEnPatio()">
                    <button class="btn-primary" style="width:50px; padding:0;"
                        onclick="window.Almacenamiento.buscarEnPatio()">
                        <i class="fa-solid fa-search"></i>
                    </button>
                </div>
            </div>

            <div id="pa-item-info" style="display:none; margin-top:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
                <div style="font-weight:700; color:#0f172a; font-size:1rem;" id="pa-prod-name">—</div>
                <div style="font-size:0.78rem; color:#64748b; margin-bottom:12px;" id="pa-prod-codigo">—</div>
                <div id="pa-stock-lista" style="margin-bottom:16px;"></div>
                <div id="pa-sugerencias" style="margin-bottom:16px;"></div>
                <div style="border-top:1px solid #e2e8f0; padding-top:16px;">
                    <label style="font-weight:700; color:#22c55e; font-size:0.85rem; display:block; margin-bottom:8px;">2. Confirmar ubicación destino</label>
                    <input type="text" id="pa-dest" class="input-field" placeholder="Código rack (ej: A-01-01)"
                        style="text-transform:uppercase; margin-bottom:10px;"
                        onkeyup="this.value=this.value.toUpperCase()">
                    <div style="display:flex; gap:8px; align-items:flex-end;">
                        <div class="input-group" style="flex:1; margin-bottom:0;">
                            <label style="font-size:0.78rem;">Cantidad a mover</label>
                            <input type="number" id="pa-move-cant" class="input-field" value="1" min="1">
                        </div>
                        <button class="btn-primary" style="background:#22c55e; height:46px; padding:0 18px;"
                            onclick="window.Almacenamiento.ejecutarPutaway()">
                            <i class="fa-solid fa-check"></i> Confirmar
                        </button>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <span style="font-weight:700; color:#475569; font-size:0.85rem;">Stock en Patio</span>
                    <button onclick="window.Almacenamiento.cargarPatio()"
                        style="background:none; border:1px solid #e2e8f0; border-radius:6px; padding:4px 10px; font-size:0.75rem; color:#64748b; cursor:pointer;">
                        <i class="fa-solid fa-rotate-right"></i> Actualizar
                    </button>
                </div>
                <div id="pa-patio-list" style="font-size:0.85rem; color:#94a3b8; text-align:center; padding:16px;">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                </div>
            </div>
        </div>`;
    },

    buscarEnPatio: async function () {
        const ean = (document.getElementById('pa-scan')?.value || '').trim();
        if (!ean) { window.showToast('Ingrese un código para buscar.', 'error'); return; }

        document.getElementById('pa-item-info').style.display = 'none';
        const btn = document.querySelector('#pa-scan + button');
        if (btn) btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            const res = await window.api.get('/putaway/resolver-ean?ean=' + encodeURIComponent(ean));
            const d   = res.data || res;
            this._paProduct   = d.producto;
            this._paStockItem = null;

            document.getElementById('pa-prod-name').textContent  = d.producto?.nombre   || '—';
            document.getElementById('pa-prod-codigo').textContent = d.producto?.codigo_interno || '';

            // Stock en patio
            const stockEl = document.getElementById('pa-stock-lista');
            if (d.stock_patio && d.stock_patio.length > 0) {
                stockEl.innerHTML = `
                <p style="font-size:0.78rem; font-weight:700; color:#475569; margin:0 0 6px;">Stock en Patio (${d.total_patio} uds.)</p>
                ${d.stock_patio.map((s, i) => `
                <div style="background:white; border:1px solid #e2e8f0; border-radius:8px; padding:10px; margin-bottom:6px; cursor:pointer; font-size:0.82rem; transition:border-color .2s;"
                     onclick="window.Almacenamiento._seleccionarStock(this, ${JSON.stringify(s).replace(/"/g, '&quot;')})"
                     data-idx="${i}">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="font-weight:700; color:#0f172a;">${escHTML(s.ubicacion_codigo) || 'Patio'}</span>
                        <span style="background:#fef2f2; color:#dc2626; border-radius:999px; padding:1px 10px; font-weight:700;">${s.cantidad} uds.</span>
                    </div>
                    ${s.lote              ? `<div style="color:#64748b; margin-top:2px;">Lote: ${escHTML(s.lote)}</div>` : ''}
                    ${s.fecha_vencimiento ? `<div style="color:#64748b;">Vence: ${escHTML(s.fecha_vencimiento)}</div>`   : ''}
                </div>`).join('')}`;

                // Autoselect first item
                this._paStockItem = d.stock_patio[0];
                document.getElementById('pa-move-cant').value = d.stock_patio[0].cantidad;
            } else {
                stockEl.innerHTML = `<p style="font-size:0.82rem; color:#f59e0b; margin:0 0 8px;"><i class="fa-solid fa-triangle-exclamation"></i> Sin stock en patio para este producto.</p>`;
            }

            this._cargarSugerencias(d.producto.id);
            document.getElementById('pa-item-info').style.display = 'block';
        } catch (err) {
            window.showToast(err.message || 'Producto no encontrado.', 'error');
        } finally {
            if (btn) btn.innerHTML = '<i class="fa-solid fa-search"></i>';
        }
    },

    _seleccionarStock: function (el, stockItem) {
        document.querySelectorAll('#pa-stock-lista > div').forEach(d => d.style.borderColor = '#e2e8f0');
        el.style.borderColor = '#22c55e';
        this._paStockItem = stockItem;
        document.getElementById('pa-move-cant').value = stockItem.cantidad;
    },

    _cargarSugerencias: async function (productoId) {
        const el = document.getElementById('pa-sugerencias');
        el.innerHTML = '<p style="font-size:0.78rem; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Buscando sugerencias...</p>';
        try {
            const res = await window.api.get('/putaway/sugerir/' + productoId);
            const sug = res.data || [];
            if (!sug.length) { el.innerHTML = ''; return; }
            el.innerHTML = `
            <p style="font-size:0.78rem; font-weight:700; color:#475569; margin:0 0 6px;">Ubicaciones sugeridas:</p>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                ${sug.map(s => `
                <button onclick="document.getElementById('pa-dest').value='${escHTML(s.codigo)}'"
                    title="${escHTML(s.razon)}"
                    style="padding:4px 12px; background:${s.prioridad === 1 ? '#f0fdf4' : '#f8fafc'}; color:${s.prioridad === 1 ? '#166534' : '#475569'}; border:1px solid ${s.prioridad === 1 ? '#bbf7d0' : '#e2e8f0'}; border-radius:6px; font-size:0.78rem; font-weight:700; cursor:pointer;">
                    ${escHTML(s.codigo)} ${s.prioridad === 1 ? '★' : ''}
                </button>`).join('')}
            </div>`;
        } catch (e) { el.innerHTML = ''; }
    },

    ejecutarPutaway: async function () {
        if (!this._paProduct) { window.showToast('Primero escanee un producto.', 'error'); return; }

        const destCodigo = (document.getElementById('pa-dest')?.value || '').trim().toUpperCase();
        const cantidad   = parseInt(document.getElementById('pa-move-cant')?.value || '0', 10);

        if (!destCodigo)   { window.showToast('Ingrese la ubicación de destino.', 'error'); return; }
        if (cantidad <= 0) { window.showToast('La cantidad debe ser mayor a cero.', 'error'); return; }

        // Resolver código → ID de ubicación
        let ubicDestId = null;
        try {
            const ubicRes = await window.api.get('/param/ubicaciones');
            const ubics   = ubicRes.data || [];
            const match   = ubics.find(u => (u.codigo || '').toUpperCase() === destCodigo);
            if (!match) { window.showToast(`Ubicación '${destCodigo}' no encontrada.`, 'error'); return; }
            ubicDestId = match.id;
        } catch (e) { window.showToast('Error al verificar ubicación.', 'error'); return; }

        const payload = {
            producto_id:          this._paProduct.id,
            ubicacion_destino_id: ubicDestId,
            cantidad:             cantidad,
        };
        if (this._paStockItem?.ubicacion_id) {
            payload.ubicacion_origen_id = this._paStockItem.ubicacion_id;
        }
        if (this._paStockItem?.lote) {
            payload.lote = this._paStockItem.lote;
        }
        if (this._paStockItem?.fecha_vencimiento) {
            payload.fecha_vencimiento = this._paStockItem.fecha_vencimiento;
        }

        try {
            await window.api.post('/putaway/ubicar', payload);
            window.showToast(`Acomodo exitoso → ${destCodigo}`, 'success');
            document.getElementById('pa-item-info').style.display = 'none';
            document.getElementById('pa-scan').value = '';
            this._paProduct   = null;
            this._paStockItem = null;
            this.cargarPatio();
        } catch (err) {
            window.showToast(err.message || 'Error al ejecutar putaway.', 'error');
        }
    },

    cargarPatio: async function () {
        const el = document.getElementById('pa-patio-list');
        if (!el) return;
        el.innerHTML = '<div style="text-align:center; padding:16px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const res  = await window.api.get('/putaway/patio');
            const data = res.data || [];
            if (!data.length) {
                el.innerHTML = '<div style="text-align:center; color:#22c55e; padding:16px; font-size:0.85rem;"><i class="fa-solid fa-check-circle"></i> Patio vacío — todo almacenado.</div>';
                return;
            }
            el.innerHTML = data.map(s => `
            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:0.82rem;">
                <div>
                    <div style="font-weight:700; color:#0f172a;">${escHTML(s.producto_nombre)}</div>
                    <div style="color:#64748b;">${escHTML(s.codigo_interno)} · ${escHTML(s.ubicacion_codigo) || 'Patio'}${s.lote ? ' · ' + escHTML(s.lote) : ''}</div>
                </div>
                <span style="background:#fef2f2; color:#dc2626; border-radius:999px; padding:2px 10px; font-weight:700;">${s.cantidad} ${escHTML(s.unidad_medida) || 'uds.'}</span>
            </div>`).join('');
        } catch (e) {
            el.innerHTML = '<div style="text-align:center; color:#ef4444; padding:16px; font-size:0.85rem;">Error al cargar patio.</div>';
        }
    },

    /* ===================================================================
       TRASLADO INTERNO
    =================================================================== */
    getTrasladoHTML: function () {
        return `
        <div style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:600px; margin:0 auto;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="width:60px; height:60px; background:#eff6ff; color:#3b82f6; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:1.5rem;">
                    <i class="fa-solid fa-people-carry-box"></i>
                </div>
                <h3 style="margin:0; color:#0f172a;">Traslado Interno</h3>
                <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Mueva stock entre ubicaciones de la bodega</p>
            </div>

            <div style="display:grid; gap:14px;">
                <div class="input-group" style="margin-bottom:0;">
                    <label>Ubicación Origen</label>
                    <input type="text" id="tr-orig" class="input-field" placeholder="Código origen (ej: A-01-01)"
                        style="text-transform:uppercase;" onkeyup="this.value=this.value.toUpperCase()">
                </div>
                <div class="input-group" style="margin-bottom:0;">
                    <label>Producto / EAN</label>
                    <input type="text" id="tr-ean" class="input-field" placeholder="Escanee EAN o código interno">
                </div>
                <div class="input-group" style="margin-bottom:0;">
                    <label>Cantidad</label>
                    <input type="number" id="tr-cant" class="input-field" value="1" min="1">
                </div>
                <div class="input-group" style="margin-bottom:0;">
                    <label style="color:#3b82f6; font-weight:700;">Ubicación Destino</label>
                    <input type="text" id="tr-dest" class="input-field" placeholder="Código destino (ej: B-02-03)"
                        style="text-transform:uppercase;" onkeyup="this.value=this.value.toUpperCase()">
                </div>
                <button class="btn-primary" style="background:#3b82f6; margin-top:8px;"
                    onclick="window.Almacenamiento.ejecutarTraslado()">
                    <i class="fa-solid fa-right-left"></i> Confirmar Traslado
                </button>
            </div>
        </div>`;
    },

    ejecutarTraslado: async function () {
        const codOrigen  = (document.getElementById('tr-orig')?.value || '').trim().toUpperCase();
        const ean        = (document.getElementById('tr-ean')?.value  || '').trim();
        const cantidad   = parseInt(document.getElementById('tr-cant')?.value || '0', 10);
        const codDestino = (document.getElementById('tr-dest')?.value || '').trim().toUpperCase();

        if (!codOrigen)   { window.showToast('Ingrese la ubicación de origen.', 'error');  return; }
        if (!ean)         { window.showToast('Ingrese el código del producto.', 'error');  return; }
        if (cantidad <= 0){ window.showToast('La cantidad debe ser mayor a cero.', 'error'); return; }
        if (!codDestino)  { window.showToast('Ingrese la ubicación de destino.', 'error'); return; }
        if (codOrigen === codDestino) { window.showToast('Origen y destino no pueden ser iguales.', 'error'); return; }

        try {
            const res = await window.api.post('/putaway/trasladar', {
                codigo_origen:  codOrigen,
                codigo_destino: codDestino,
                ean:            ean,
                cantidad:       cantidad,
            });
            const d = res.data || {};
            window.showToast(`Traslado exitoso: ${d.de} → ${d.hacia} (${d.cantidad} uds.)`, 'success');
            ['tr-orig', 'tr-ean', 'tr-dest'].forEach(id => {
                const el = document.getElementById(id); if (el) el.value = '';
            });
            document.getElementById('tr-cant').value = '1';
        } catch (err) {
            window.showToast(err.message || 'Error al ejecutar traslado.', 'error');
        }
    },
};
