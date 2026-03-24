/**
 * Prooriente WMS - Devoluciones Module
 */
window.Devoluciones = {
    _selectedProducto: null,

    getDevolucionesHTML: function() {
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
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="dev-prod-search" class="input-field" placeholder="Escanee o busque producto...">
                            <button class="btn-primary" style="width:50px;" onclick="window.Devoluciones.buscarProducto()"><i class="fa-solid fa-search"></i></button>
                        </div>
                        <div id="dev-prod-info" style="margin-top:6px; font-size:0.85rem; color:#16a34a; display:none;"></div>
                    </div>
                    <div class="input-group">
                        <label>Cantidad</label>
                        <input type="number" id="dev-cant" class="input-field" value="1">
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

                <button class="btn-primary" style="background:#f97316; margin-top:20px;" onclick="window.Devoluciones.guardarDevolucion()">Procesar Devolución</button>
            </div>
        `;
    },

    buscarProducto: async function() {
        const q = document.getElementById('dev-prod-search').value.trim();
        if (!q) return window.showToast('Ingrese un código o nombre de producto', 'error');
        try {
            const res = await window.api.get('/param/productos');
            const p = res.data.find(x =>
                x.codigo_interno === q ||
                (x.ean13 && x.ean13 === q) ||
                x.nombre.toLowerCase().includes(q.toLowerCase())
            );
            if (p) {
                this._selectedProducto = p;
                const infoEl = document.getElementById('dev-prod-info');
                infoEl.textContent = '✓ ' + p.nombre + (p.codigo_interno ? ' (' + p.codigo_interno + ')' : '');
                infoEl.style.display = 'block';
            } else {
                this._selectedProducto = null;
                window.showToast('Producto no encontrado', 'error');
            }
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    guardarDevolucion: async function() {
        if (!this._selectedProducto) {
            return window.showToast('Seleccione un producto primero', 'error');
        }
        const payload = {
            tipo: document.getElementById('dev-tipo').value,
            proveedor: document.getElementById('dev-prov').value,
            motivo_general: document.getElementById('dev-notas').value,
            detalles: [
                {
                    producto_id: this._selectedProducto.id,
                    cantidad: document.getElementById('dev-cant').value,
                    destino: document.getElementById('dev-dest').value,
                    motivo: document.getElementById('dev-tipo').value
                }
            ]
        };
        try {
            await window.api.post('/devoluciones', payload);
            this._selectedProducto = null;
            window.showToast('Devolución registrada con éxito', 'success');
            window.goToHome();
        } catch(e) { window.showToast(e.message, 'error'); }
    }
};
