// Módulo Maestros JS (Empresas, Marcas, Productos)

/** Global Smart Filter: attach to any table by tbodyId */
window.handleSmartFilter = function(inputElem, tbodyId) {
    const term = inputElem.value.toLowerCase().trim();
    const rows = document.querySelectorAll(`#${tbodyId} tr`);
    rows.forEach(row => {
        // Skip filtering if it's the "No data" or "Loading" row
        if(row.cells.length === 1 && row.cells[0].colSpan > 1) return;
        
        const text = row.textContent.toLowerCase();
        row.style.display = term === '' || text.includes(term) ? '' : 'none';
    });
};

/** Helper to generate import/export buttons */
function importExportButtonsHTML(tipo) {
    return `
        <div style="display:flex; gap:10px; margin-bottom:10px;">
            <button class="btn-primary" style="background:#f8fafc; color:#475569; border:1px solid #e2e8f0; font-size:0.75rem; padding:6px 10px; width:auto;" onclick="window.Maestros.downloadTemplate('${tipo}')">
                <i class="fa-solid fa-download"></i> Plantilla CSV
            </button>
            <label class="btn-primary" style="background:#f8fafc; color:#475569; border:1px solid #e2e8f0; font-size:0.75rem; padding:6px 10px; width:auto; cursor:pointer;">
                <i class="fa-solid fa-file-import"></i> Importar CSV
                <input type="file" style="display:none;" accept=".csv" onchange="window.Maestros.handleBulkImport(this, '${tipo}')">
            </label>
        </div>
    `;
}

/** Reusable search bar HTML snippet */
function filterBarHTML(tbodyId, placeholder) {
    return `<div style="margin-bottom:14px; position:relative;">
        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
        <input type="text" class="input-field" placeholder="${placeholder}" onkeyup="window.handleSmartFilter(this, '${tbodyId}')"
            style="padding-left:36px; border-radius:8px; height:40px; font-size:0.85rem; border:1px solid #e2e8f0; width:100%; box-sizing:border-box;">
    </div>`;
}

window.Maestros = {

    /* --- EMPRESAS --- */
    getEmpresasHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Directorio de Empresas</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showEmpresaForm()"><i class="fa-solid fa-plus"></i> Crear</button>
                </div>
                ${importExportButtonsHTML('empresas')}
                ${filterBarHTML('empresas-tbody', '🔍 Buscar por NIT, razón social...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">NIT</th>
                                <th style="padding:10px 8px;">Razón Social</th>
                                <th style="padding:10px 8px;">Estado</th>
                            </tr>
                        </thead>
                        <tbody id="empresas-tbody">
                            <tr><td colspan="3" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template (Hidden by default) -->
            <div id="form-empresa-container" style="display:none; background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
                <h4 style="margin-top:0; color:#0f172a; margin-bottom: 16px;">Nueva Empresa / Sucursal</h4>
                <div class="input-group">
                    <label>NIT / Identificación</label>
                    <input type="text" id="emp-nit" class="input-field" placeholder="Ej: 900.xxx.xxx">
                </div>
                <div class="input-group">
                    <label>Razón Social</label>
                    <input type="text" id="emp-razon" class="input-field" placeholder="Nombre completo de la empresa">
                </div>
                <div class="input-group">
                    <label>Dirección Principal</label>
                    <input type="text" id="emp-dir" class="input-field" placeholder="Avenida / Calle...">
                </div>
                <div class="input-group">
                    <label>Teléfono</label>
                    <input type="text" id="emp-tel" class="input-field" placeholder="300xxxxxxx">
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:20px;">
                    <button class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveEmpresa()">Guardar</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideEmpresaForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadEmpresas: async function() {
        const tbody = document.getElementById('empresas-tbody');
        if(!tbody) return;

        try {
            const res = await window.api.get('/param/empresas'); 
            if (res && res.data && res.data.length > 0) {
                let html = '';
                res.data.forEach(e => {
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px 8px; font-weight:600; color:#334155;">${e.nit || '-'}</td>
                        <td style="padding:12px 8px; color:#475569;">${e.razon_social}</td>
                        <td style="padding:12px 8px;"><span style="color:#10b981; font-weight:500;">Activo</span></td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:20px; color:#94a3b8;">No hay empresas registradas.</td></tr>`;
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:20px; color:#ef4444;">Error de conexión API.</td></tr>`;
        }
    },

    showEmpresaForm: function() {
        document.getElementById('form-empresa-container').style.display = 'block';
        window.scrollTo({ top: document.getElementById('form-empresa-container').offsetTop, behavior: 'smooth' });
    },

    hideEmpresaForm: function() {
        document.getElementById('form-empresa-container').style.display = 'none';
        
        // Clear inputs
        document.getElementById('emp-nit').value = '';
        document.getElementById('emp-razon').value = '';
        document.getElementById('emp-dir').value = '';
        document.getElementById('emp-tel').value = '';
    },

    saveEmpresa: async function() {
        const nit = document.getElementById('emp-nit').value.trim();
        const razon = document.getElementById('emp-razon').value.trim();
        const dir = document.getElementById('emp-dir').value.trim();
        const tel = document.getElementById('emp-tel').value.trim();

        if (!nit || !razon) {
            window.showToast('NIT y Razón Social son requeridos', 'error');
            return;
        }

        try {
            const result = await window.api.post('/param/empresas', {
                nit: nit,
                razon_social: razon,
                direccion: dir,
                telefono: tel
            });

            window.showToast('Empresa guardada con éxito', 'success');
            this.hideEmpresaForm();
            this.loadEmpresas(); // Reload table
        } catch (e) {
            window.showToast('Error: ' + e.message, 'error');
        }
    },

    /* --- MARCAS --- */
    getMarcasHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Catálogo de Marcas</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showMarcaForm()"><i class="fa-solid fa-plus"></i> Añadir</button>
                </div>
                ${importExportButtonsHTML('marcas')}
                ${filterBarHTML('marcas-tbody', '🔍 Buscar marca...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">ID</th>
                                <th style="padding:10px 8px;">Nombre Marca</th>
                            </tr>
                        </thead>
                        <tbody id="marcas-tbody">
                            <tr><td colspan="2" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-marca-container" style="display:none; background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0;">
                <h4 style="margin-top:0; color:#0f172a; margin-bottom: 16px;">Registrar Marca</h4>
                <div class="input-group">
                    <label>Nombre de la Marca</label>
                    <input type="text" id="marca-nombre" class="input-field" placeholder="Ej: Coca-Cola, HP...">
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:20px;">
                    <button class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveMarca()">Guardar</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideMarcaForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadMarcas: async function() {
        const tbody = document.getElementById('marcas-tbody');
        if(!tbody) return;

        try {
            const res = await window.api.get('/param/marcas'); 
            if (res && res.data && res.data.length > 0) {
                let html = '';
                res.data.forEach(m => {
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px 8px; font-weight:600; color:#334155;">#${m.id}</td>
                        <td style="padding:12px 8px; color:#475569;">${m.nombre}</td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="2" style="text-align:center; padding:20px; color:#94a3b8;">No hay marcas registradas.</td></tr>`;
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="2" style="text-align:center; padding:20px; color:#ef4444;">Error de conexión API.</td></tr>`;
        }
    },

    showMarcaForm: function() {
        document.getElementById('form-marca-container').style.display = 'block';
    },

    hideMarcaForm: function() {
        document.getElementById('form-marca-container').style.display = 'none';
        document.getElementById('marca-nombre').value = '';
    },

    saveMarca: async function() {
        const nombre = document.getElementById('marca-nombre').value.trim();

        if (!nombre) {
            window.showToast('El nombre de la marca es requerido', 'error');
            return;
        }

        try {
            await window.api.post('/param/marcas', { nombre });
            window.showToast('Marca guardada con éxito', 'success');
            this.hideMarcaForm();
            this.loadMarcas(); 
        } catch (e) {
            window.showToast('Error: ' + e.message, 'error');
        }
    },

    /* --- PRODUCTOS --- */
    getProductosHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Catálogo de Productos</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showProductoForm()"><i class="fa-solid fa-plus"></i> Añadir</button>
                </div>
                ${importExportButtonsHTML('productos')}
                ${filterBarHTML('productos-tbody', '🔍 Buscar por código, nombre, marca...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">Código Interno</th>
                                <th style="padding:10px 8px;">Nombre</th>
                                <th style="padding:10px 8px;">UM</th>
                                <th style="padding:10px 8px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="productos-tbody">
                            <tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-producto-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); border:1px solid #e2e8f0; max-width:1100px; margin:0 auto 30px;">
                <h4 id="prod-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Registrar Producto</h4>
                <input type="hidden" id="prod-id" value="">
                
                <div style="display:flex; gap:25px; flex-wrap:wrap; margin-bottom:20px;">
                    <!-- Photo Section -->
                    <div style="flex:0 0 180px; background:#f8fafc; padding:20px; border-radius:12px; border:1px dashed #cbd5e1; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                        <div id="prod-photo-preview" style="width:140px; height:140px; background:#e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:12px; box-shadow:inset 0 2px 4px rgba(0,0,0,0.05);">
                            <i class="fa-solid fa-camera" style="font-size:2.5rem; color:#94a3b8;"></i>
                        </div>
                        <label class="btn-primary" style="background:#64748b; font-size:0.75rem; width:auto; padding:8px 12px; cursor:pointer; border-radius:6px; transition:all 0.2s;">
                            <i class="fa-solid fa-camera-retro"></i> Capturar Foto
                            <input type="file" id="prod-photo-input" accept="image/*" capture="environment" style="display:none;" onchange="window.Maestros.handleProductPhoto(this)">
                        </label>
                        <input type="hidden" id="prod-photo-base64" value="">
                    </div>

                    <!-- Main Identifiers -->
                    <div style="flex:1; min-width:300px; display:flex; flex-direction:column; gap:15px;">
                        <div style="display:flex; gap:15px;">
                            <div class="input-group" style="flex:1;">
                                <label style="font-weight:600; color:#475569;">Código EAN Principal (Opcional)</label>
                                <input type="text" id="prod-ean" class="input-field" placeholder="Ej: 7701234567890" style="font-size:1.1rem; letter-spacing:2px; font-family:monospace; border-color:#cbd5e1;">
                            </div>
                            <div class="input-group" style="flex:1;">
                                <label style="font-weight:600; color:#475569;">Código Interno</label>
                                <input type="text" id="prod-interno" class="input-field" placeholder="Dejar vacío para usar EAN" style="border-color:#cbd5e1;">
                            </div>
                        </div>
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Nombre del Producto *</label>
                            <input type="text" id="prod-nombre" class="input-field" placeholder="Nombre descriptivo completo" style="border-color:#cbd5e1;">
                        </div>
                    </div>
                </div>

                <div class="input-group" style="margin-bottom:20px;">
                    <label style="font-weight:600; color:#475569;">Descripción detallada</label>
                    <textarea id="prod-desc" class="input-field" rows="3" placeholder="Características técnicas, materiales, usos..." style="border-color:#cbd5e1;"></textarea>
                </div>
                
                <!-- Logistics Grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:20px; background:#f8fafc; padding:20px; border-radius:12px; border:1px solid #e2e8f0;">
                    
                    <!-- Column 1 -->
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Marca</label>
                            <select id="prod-marca" class="input-field" style="height:48px;">
                                <option value="">Seleccione marca...</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Categoría</label>
                            <select id="prod-categoria" class="input-field" style="height:48px;">
                                <option value="">Seleccione categoría...</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Peso (Kg)</label>
                            <input type="number" step="0.001" id="prod-peso" class="input-field" placeholder="0.000">
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; padding-top:10px;">
                            <input type="checkbox" id="prod-lotes" style="width:20px; height:20px; cursor:pointer;">
                            <label for="prod-lotes" style="cursor:pointer; font-weight:500; color:#334155;">Controla Lotes</label>
                        </div>
                    </div>

                    <!-- Column 2 -->
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Unidad de Medida</label>
                            <select id="prod-um" class="input-field" style="height:48px;">
                                <option value="UN">Unidad (UN)</option>
                                <option value="KG">Kilogramo (KG)</option>
                                <option value="LT">Litro (LT)</option>
                                <option value="CAJA">Caja</option>
                                <option value="PAQUETE">Paquete</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Volumen (m3)</label>
                            <input type="number" step="0.0001" id="prod-vol" class="input-field" placeholder="0.0000">
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; padding-top:10px;">
                            <input type="checkbox" id="prod-venc" style="width:20px; height:20px; cursor:pointer;">
                            <label for="prod-venc" style="cursor:pointer; font-weight:500; color:#334155;">Controla Vencimiento</label>
                        </div>
                    </div>

                    <!-- Column 3 -->
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Vida Útil (Días)</label>
                            <input type="number" id="prod-vida" class="input-field" placeholder="Ej: 365">
                        </div>
                        <div class="input-group">
                            <label style="font-weight:600; color:#475569;">Temperatura de Almacén</label>
                            <select id="prod-temp" class="input-field" style="height:48px;">
                                <option value="">Ambiente</option>
                                <option value="Refrigerado">Refrigerado (2-8°C)</option>
                                <option value="Congelado">Congelado (<-18°C)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:20px;">
                    <button class="btn-primary" style="background:#0f172a; flex:1;" id="prod-save-btn" onclick="window.Maestros.saveProducto()">Guardar</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideProductoForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadProductos: async function() {
        const tbody = document.getElementById('productos-tbody');
        if(!tbody) return;

        try {
            const res = await window.api.get('/param/productos');
            const userData = localStorage.getItem('user_data');
            const currentUser = userData ? JSON.parse(userData) : null;
            const isAdmin = currentUser && currentUser.rol && currentUser.rol.toLowerCase() === 'admin';

            if (res && res.data && res.data.length > 0) {
                let html = '';
                res.data.forEach(p => {
                    const deleteBtn = isAdmin
                        ? `<button onclick="window.Maestros.deleteProducto(${p.id}, '${p.nombre.replace(/'/g, "\\'")}')" style="background:#fee2e2; color:#dc2626; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Eliminar">
                                <i class="fa-solid fa-trash"></i>
                           </button>`
                        : '';
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:12px 8px; font-weight:600; color:#334155;">${p.codigo_interno}</td>
                        <td style="padding:12px 8px; color:#475569;">${p.nombre}</td>
                        <td style="padding:12px 8px; color:#64748b;">${p.unidad_medida || 'UN'}</td>
                        <td style="padding:12px 8px; display:flex; gap:5px;">
                            <button onclick="window.Maestros.editProducto(${p.id})" style="background:#f1f5f9; color:#475569; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Editar">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button onclick="window.Maestros.showEanManager(${p.id}, '${p.nombre.replace(/'/g, "\\'")}')" style="background:#e0e7ff; color:#4338ca; border:none; padding:6px 10px; border-radius:4px; cursor:pointer;" title="EANs">
                                <i class="fa-solid fa-barcode"></i>
                            </button>
                            ${deleteBtn}
                        </td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;">No hay productos registrados.</td></tr>`;
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:#ef4444;">Error de conexión API.</td></tr>`;
        }
    },

    deleteProducto: async function(id, nombre) {
        if (!confirm(`¿Eliminar el producto "${nombre}"? Esta acción lo desactivará del sistema.`)) return;
        try {
            await window.api.delete(`/param/productos/${id}`);
            window.showToast('Producto eliminado correctamente', 'success');
            this.loadProductos();
        } catch (e) {
            window.showToast('Error: ' + e.message, 'error');
        }
    },

    handleProductPhoto: function(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('prod-photo-preview').innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
                document.getElementById('prod-photo-base64').value = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    },

    showEanManager: async function(prodId, prodName) {
        // Create modal container if it doesn't exist
        let modal = document.getElementById('ean-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'ean-modal';
            modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;';
            document.body.appendChild(modal);
        }

        modal.innerHTML = `
            <div style="background:#fff; width:100%; max-width:500px; border-radius:12px; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.1); position:relative;">
                <button onclick="document.getElementById('ean-modal').style.display='none'" style="position:absolute; right:15px; top:15px; background:none; border:none; font-size:1.2rem; cursor:pointer; color:#64748b;"><i class="fa-solid fa-xmark"></i></button>
                <h3 style="margin-top:0; color:#0f172a; font-size:1.2rem; margin-bottom:5px;"><i class="fa-solid fa-barcode"></i> Códigos EAN</h3>
                <p style="color:#64748b; font-size:0.9rem; margin-bottom:20px;">Producto: <b>${prodName}</b></p>
                
                <div style="display:flex; gap:10px; margin-bottom:20px;">
                    <div style="flex:1; position:relative;">
                        <input type="text" id="new-ean-input" class="input-field" placeholder="Escanear o digitar EAN..." style="width:100%; min-width:0; padding-right:40px;">
                        <input type="hidden" id="editing-ean-id" value="">
                        <button id="cancel-ean-edit" onclick="window.Maestros.cancelEanEdit()" style="display:none; position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer;"><i class="fa-solid fa-circle-xmark"></i></button>
                    </div>
                    <button id="ean-submit-btn" onclick="window.Maestros.submitEan(${prodId})" style="background:#22c55e; color:white; border:none; border-radius:8px; width:60px; height:55px; cursor:pointer; font-size:1.2rem; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 4px rgba(0,0,0,0.1);"><i class="fa-solid fa-plus"></i></button>
                </div>

                <div style="background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; max-height:250px; overflow-y:auto;">
                    <table style="width:100%; text-align:left; border-collapse:collapse; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:1px solid #e2e8f0; background:#f1f5f9; color:#475569;">
                                <th style="padding:10px;">Código EAN</th>
                                <th style="padding:10px;">Principal</th>
                                <th style="padding:10px; width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="ean-tbody-${prodId}">
                            <tr><td colspan="3" style="text-align:center; padding:15px; color:#94a3b8;"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
        modal.style.display = 'flex';

        this.loadEans(prodId);
    },

    loadEans: async function(prodId) {
        const tbody = document.getElementById(`ean-tbody-${prodId}`);
        if(!tbody) return;

        try {
            const res = await window.api.get(`/param/productos/${prodId}/eans`);
            if (res.data && res.data.length > 0) {
                let html = '';
                res.data.forEach(ean => {
                    const badge = ean.es_principal ? `<span style="background:#dbeafe; color:#1d4ed8; font-size:0.7rem; padding:2px 6px; border-radius:12px; font-weight:600;">Principal</span>` : `<span style="color:#94a3b8;">Adicional</span>`;
                    const actions = ean.es_principal ? '' : `
                        <div style="display:flex; gap:8px; justify-content:center;">
                            <button onclick="window.Maestros.editEan(${ean.id}, '${ean.codigo_ean}')" style="color:#6366f1; background:none; border:none; padding:5px; cursor:pointer;" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                            <button onclick="window.Maestros.deleteEan(${prodId}, ${ean.id})" style="color:#ef4444; background:none; border:none; padding:5px; cursor:pointer;" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    `;
                    
                    html += `
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:10px; font-family:monospace; font-size:1rem; font-weight:600; color:#334155;">${ean.codigo_ean}</td>
                            <td style="padding:10px;">${badge}</td>
                            <td style="padding:10px; text-align:center;">${actions}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:15px; color:#94a3b8;">No asociados</td></tr>`;
            }
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:15px; color:#ef4444;">Error cargando EANs</td></tr>`;
        }
    },

    submitEan: async function(prodId) {
        const input = document.getElementById('new-ean-input');
        const editingId = document.getElementById('editing-ean-id').value;
        const code = input.value.trim();
        
        if(!code) return window.showToast('Digite un código EAN válido', 'error');

        try {
            if (editingId) {
                // Update
                await window.api.put(`/param/productos/${prodId}/eans/${editingId}`, { codigo_ean: code });
                window.showToast('EAN actualizado exitosamente', 'success');
            } else {
                // Create
                await window.api.post(`/param/productos/${prodId}/eans`, { codigo_ean: code, tipo: 'EAN13' });
                window.showToast('EAN agregado exitosamente', 'success');
            }
            
            this.cancelEanEdit(); // Reset UI
            this.loadEans(prodId);
        } catch (e) {
            window.showToast('No se pudo guardar: ' + e.message, 'error');
        }
    },

    editEan: function(eanId, code) {
        document.getElementById('new-ean-input').value = code;
        document.getElementById('editing-ean-id').value = eanId;
        document.getElementById('ean-submit-btn').innerHTML = '<i class="fa-solid fa-check"></i>';
        document.getElementById('ean-submit-btn').style.background = '#6366f1';
        document.getElementById('cancel-ean-edit').style.display = 'block';
        document.getElementById('new-ean-input').focus();
    },

    cancelEanEdit: function() {
        document.getElementById('new-ean-input').value = '';
        document.getElementById('editing-ean-id').value = '';
        document.getElementById('ean-submit-btn').innerHTML = '<i class="fa-solid fa-plus"></i>';
        document.getElementById('ean-submit-btn').style.background = '#22c55e';
        document.getElementById('cancel-ean-edit').style.display = 'none';
    },

    deleteEan: async function(prodId, eanId) {
        if(!confirm('¿Seguro que desea eliminar este código de barras?')) return;
        try {
            await window.api.delete(`/param/productos/${prodId}/eans/${eanId}`);
            window.showToast('EAN eliminado', 'success');
            this.loadEans(prodId);
        } catch (e) {
            window.showToast('Error al eliminar: ' + e.message, 'error');
        }
    },

    showProductoForm: async function() {
        document.getElementById('form-producto-container').style.display = 'block';
        window.scrollTo({ top: document.getElementById('form-producto-container').offsetTop, behavior: 'smooth' });
        
        // Load marcas y categorias into selects
        try {
            const [resMarcas, resCats] = await Promise.all([
                window.api.get('/param/marcas'),
                window.api.get('/param/categorias'),
            ]);
            const select = document.getElementById('prod-marca');
            select.innerHTML = '<option value="">Seleccione marca...</option>';
            if(resMarcas.data) {
                resMarcas.data.forEach(m => {
                    select.innerHTML += `<option value="${m.id}">${m.nombre}</option>`;
                });
            }
            const selCat = document.getElementById('prod-categoria');
            selCat.innerHTML = '<option value="">Seleccione categoría...</option>';
            if(resCats.data) {
                resCats.data.forEach(c => {
                    selCat.innerHTML += `<option value="${c.id}">${c.nombre}</option>`;
                });
            }
        } catch (e) {}
    },

    hideProductoForm: function() {
        document.getElementById('form-producto-container').style.display = 'none';
        document.getElementById('prod-id').value = '';
        document.getElementById('prod-ean').value = '';
        document.getElementById('prod-interno').value = '';
        document.getElementById('prod-nombre').value = '';
        document.getElementById('prod-desc').value = '';
        document.getElementById('prod-marca').value = '';
        document.getElementById('prod-categoria').value = '';
        document.getElementById('prod-um').value = 'UN';
        document.getElementById('prod-peso').value = '';
        document.getElementById('prod-vol').value = '';
        document.getElementById('prod-vida').value = '';
        document.getElementById('prod-temp').value = '';
        document.getElementById('prod-lotes').checked = false;
        document.getElementById('prod-venc').checked = false;
        
        document.getElementById('prod-save-btn').innerText = 'Guardar';
        document.getElementById('prod-ean').disabled = false;
        document.getElementById('prod-photo-preview').innerHTML = '<i class="fa-solid fa-camera" style="font-size:2rem; color:#94a3b8;"></i>';
        document.getElementById('prod-photo-base64').value = '';
    },

    editProducto: async function(id) {
        try {
            // Find product in last loaded list. We can fetch it or just read from memory.
            // Better to re-fetch to ensure fresh data
            const res = await window.api.get('/param/productos');
            const prod = res.data.find(p => p.id === id);
            if(!prod) return window.showToast('Producto no encontrado', 'error');

            this.showProductoForm();
            document.getElementById('prod-form-title').innerText = 'Editar Producto';
            document.getElementById('prod-save-btn').innerText = 'Actualizar';
            
            document.getElementById('prod-id').value = prod.id;
            
            // To get main EAN, we should fetch it or pass it. 
            // In the table it was mapped to codigo_interno... wait, we have codigo_interno.
            document.getElementById('prod-interno').value = prod.codigo_interno || '';
            document.getElementById('prod-ean').value = ''; // We don't load main EAN here directly unless we join it
            document.getElementById('prod-ean').placeholder = '* EAN Principal *';
            
            document.getElementById('prod-nombre').value = prod.nombre || '';
            document.getElementById('prod-desc').value = prod.descripcion || '';
            document.getElementById('prod-marca').value = prod.marca_id || '';
            document.getElementById('prod-categoria').value = prod.categoria_id || '';
            document.getElementById('prod-um').value = prod.unidad_medida || 'UN';
            document.getElementById('prod-peso').value = prod.peso_unitario || '';
            document.getElementById('prod-vol').value = prod.volumen_unitario || '';
            document.getElementById('prod-vida').value = prod.vida_util_dias || '';
            document.getElementById('prod-temp').value = prod.temperatura_almacen || '';
            document.getElementById('prod-venc').checked = prod.controla_vencimiento === 1;

            if (prod.imagen_url) {
                document.getElementById('prod-photo-preview').innerHTML = `<img src="${prod.imagen_url}" style="width:100%; height:100%; object-fit:cover;">`;
            } else {
                document.getElementById('prod-photo-preview').innerHTML = '<i class="fa-solid fa-camera" style="font-size:2rem; color:#94a3b8;"></i>';
            }

            window.scrollTo({ top: document.getElementById('form-producto-container').offsetTop, behavior: 'smooth' });
        } catch(e) {
            window.showToast('Error cargando producto', 'error');
        }
    },

    saveProducto: async function() {
        const id = document.getElementById('prod-id').value;
        const ean = document.getElementById('prod-ean').value.trim();
        const interno = document.getElementById('prod-interno').value.trim();
        const nombre = document.getElementById('prod-nombre').value.trim();
        const desc = document.getElementById('prod-desc').value.trim();
        const marca_id = document.getElementById('prod-marca').value;
        const um = document.getElementById('prod-um').value;
        const peso = document.getElementById('prod-peso').value;
        const vol = document.getElementById('prod-vol').value;
        const vida = document.getElementById('prod-vida').value;
        const temp = document.getElementById('prod-temp').value;
        const lotes = document.getElementById('prod-lotes').checked;
        const venc = document.getElementById('prod-venc').checked;

        if (!nombre) {
            window.showToast('El nombre es requerido', 'error');
            return;
        }

        const payload = {
            codigo_ean: ean,
            codigo_interno: interno,
            nombre: nombre,
            descripcion: desc,
            marca_id: marca_id || null,
            categoria_id: document.getElementById('prod-categoria').value || null,
            unidad_medida: um,
            peso_unitario: peso,
            volumen_unitario: vol,
            vida_util_dias: vida,
            temperatura_almacen: temp,
            maneja_lotes: lotes,
            controla_vencimiento: venc,
            imagen_url: document.getElementById('prod-photo-base64').value || null
        };

        const btn = document.getElementById('prod-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Guardando...'; }

        try {
            if (id) {
                // Edit
                await window.api.put(`/param/productos/${id}`, payload);
                window.showToast('Producto actualizado con éxito', 'success');
            } else {
                // Create
                await window.api.post('/param/productos', payload);
                window.showToast('Producto guardado con éxito', 'success');
            }
            this.hideProductoForm();
            this.loadProductos();
        } catch (e) {
            window.showToast('Error: ' + e.message, 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Guardar'; }
        }
    },

    /* --- SUCURSALES --- */
    getSucursalesHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Bodegas y Sucursales</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showSucursalForm()"><i class="fa-solid fa-plus"></i> Crear</button>
                </div>
                ${importExportButtonsHTML('sucursales')}
                ${filterBarHTML('sucursales-tbody', '🔍 Buscar por código, nombre, ciudad...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">Código</th>
                                <th style="padding:10px 8px;">Nombre</th>
                                <th style="padding:10px 8px;">Ciudad</th>
                                <th style="padding:10px 8px;">Tipo</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="sucursales-tbody">
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-sucursal-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="suc-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Gestionar Sucursal</h4>
                <input type="hidden" id="suc-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Código de la Sede</label>
                        <input type="text" id="suc-codigo" class="input-field" placeholder="Ej: BOG-01, MED-02...">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nombre Descriptivo</label>
                        <input type="text" id="suc-nombre" class="input-field" placeholder="Ej: Bodega Central Cali">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Tipo de Instalación</label>
                        <select id="suc-tipo" class="input-field">
                            <option value="Bodega">Bodega / Almacén</option>
                            <option value="CEDI">Centro de Distribución (CEDI)</option>
                            <option value="Sucursal">Sucursal de Venta</option>
                            <option value="Planta">Planta de Producción</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Ciudad</label>
                        <input type="text" id="suc-ciudad" class="input-field" placeholder="Ej: Bogotá, Medellín...">
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label style="font-weight:600; color:#475569;">Dirección Física</label>
                        <input type="text" id="suc-dir" class="input-field" placeholder="Calle/Avenida # ...">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Teléfono de Contacto</label>
                        <input type="text" id="suc-tel" class="input-field" placeholder="+57 ...">
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; padding-top:10px;">
                        <input type="checkbox" id="suc-activo" checked style="width:20px; height:20px; cursor:pointer;">
                        <label for="suc-activo" style="cursor:pointer; font-weight:500; color:#334155;">Sucursal Activa</label>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="suc-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveSucursal()">Guardar Cambios</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideSucursalForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadSucursales: async function() {
        const tbody = document.getElementById('sucursales-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/sucursales');
            let html = '';
            res.data.forEach(s => {
                const status = s.activo ? '<span style="color:#10b981; font-weight:600;">Activa</span>' : '<span style="color:#94a3b8;">Inactiva</span>';
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${s.codigo}</td>
                    <td style="padding:12px 8px; color:#475569;">${s.nombre}</td>
                    <td style="padding:12px 8px; color:#64748b;">${s.ciudad || '-'}</td>
                    <td style="padding:12px 8px; color:#64748b;">${s.tipo}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <button onclick="window.Maestros.editSucursal(${s.id})" style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Editar"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">No hay sucursales registradas.</td></tr>';
        } catch (e) {}
    },

    showSucursalForm: function() {
        document.getElementById('form-sucursal-container').style.display = 'block';
    },

    hideSucursalForm: function() {
        document.getElementById('form-sucursal-container').style.display = 'none';
        document.getElementById('suc-id').value = '';
        document.getElementById('suc-codigo').value = '';
        document.getElementById('suc-nombre').value = '';
        document.getElementById('suc-ciudad').value = '';
        document.getElementById('suc-dir').value = '';
        document.getElementById('suc-tel').value = '';
        document.getElementById('suc-activo').checked = true;
        document.getElementById('suc-save-btn').innerText = 'Guardar';
        document.getElementById('suc-form-title').innerText = 'Nueva Bodega / Sucursal';
    },

    editSucursal: async function(id) {
        // Fetch specific or find in memory
        const res = await window.api.get('/param/sucursales');
        const s = res.data.find(x => x.id === id);
        if(!s) return;
        this.showSucursalForm();
        document.getElementById('suc-form-title').innerText = 'Editar Sucursal';
        document.getElementById('suc-save-btn').innerText = 'Actualizar Cambios';
        
        document.getElementById('suc-id').value = s.id;
        document.getElementById('suc-codigo').value = s.codigo;
        document.getElementById('suc-nombre').value = s.nombre;
        document.getElementById('suc-tipo').value = s.tipo;
        document.getElementById('suc-ciudad').value = s.ciudad || '';
        document.getElementById('suc-dir').value = s.direccion || '';
        document.getElementById('suc-tel').value = s.telefono || '';
        document.getElementById('suc-activo').checked = s.activo == 1;
        
        window.scrollTo({ top: document.getElementById('form-sucursal-container').offsetTop, behavior: 'smooth' });
    },

    saveSucursal: async function() {
        const id = document.getElementById('suc-id').value;
        const payload = {
            codigo: document.getElementById('suc-codigo').value.trim(),
            nombre: document.getElementById('suc-nombre').value.trim(),
            tipo: document.getElementById('suc-tipo').value,
            ciudad: document.getElementById('suc-ciudad').value.trim(),
            direccion: document.getElementById('suc-dir').value.trim(),
            telefono: document.getElementById('suc-tel').value.trim(),
            activo: document.getElementById('suc-activo').checked ? 1 : 0
        };
        
        if (!payload.codigo || !payload.nombre) {
            return window.showToast('Código y Nombre son obligatorios', 'error');
        }

        try {
            if(id) await window.api.put(`/param/sucursales/${id}`, payload);
            else await window.api.post('/param/sucursales', payload);
            window.showToast('Sucursal guardada');
            this.hideSucursalForm();
            this.loadSucursales();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* --- UBICACIONES --- */
    getUbicacionesHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Gestión de Ubicaciones</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showUbicacionForm()"><i class="fa-solid fa-plus"></i> Crear Ubicación</button>
                </div>
                ${importExportButtonsHTML('ubicaciones')}
                ${filterBarHTML('ubicaciones-tbody', '🔍 Buscar por código, zona, tipo...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">Código</th>
                                <th style="padding:10px 8px;">Sede</th>
                                <th style="padding:10px 8px;">Zona</th>
                                <th style="padding:10px 8px;">Tipo</th>
                                <th style="padding:10px 8px;">Estado</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="ubicaciones-tbody">
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-ubic-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="ubic-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Configurar Ubicación</h4>
                <input type="hidden" id="ubic-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Sede / Bodega *</label>
                        <select id="ubic-suc" class="input-field"></select>
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Código Generado (Auto) *</label>
                        <input type="text" id="ubic-cod" class="input-field" disabled placeholder="P-MM-NN" style="background:#f8fafc; font-weight:bold; color:#0f172a;">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Pasillo (Letras/Núm) *</label>
                        <input type="text" id="ubic-pasillo" class="input-field" placeholder="Ej: A, B, 01..." oninput="window.Maestros.updateUbicCode()">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Módulo (Numérico 2 dig) *</label>
                        <input type="number" id="ubic-modulo" class="input-field" placeholder="01, 02..." oninput="window.Maestros.updateUbicCode()">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nivel (Numérico 2 dig) *</label>
                        <input type="number" id="ubic-nivel" class="input-field" placeholder="01, 02..." oninput="window.Maestros.updateUbicCode()">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Zona / Área</label>
                        <input type="text" id="ubic-zona" class="input-field" placeholder="Ej: General, Fríos...">
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label style="font-weight:600; color:#475569;">Tipo de Ubicación</label>
                        <select id="ubic-tipo" class="input-field">
                            <option value="Almacenamiento">Almacenamiento (Rack)</option>
                            <option value="Picking">Picking (Nivel Bajo)</option>
                            <option value="Muelle">Muelle / Patio</option>
                        </select>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="ubic-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveUbicacion()">Guardar Ubicación</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="document.getElementById('form-ubic-container').style.display='none'">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadUbicaciones: async function() {
        const tbody = document.getElementById('ubicaciones-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/ubicaciones');
            let html = '';
            res.data.forEach(u => {
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${u.codigo}</td>
                    <td style="padding:12px 8px; color:#475569;">Sede:${u.sucursal_id}</td>
                    <td style="padding:12px 8px; color:#64748b;">${u.zona || '-'}</td>
                    <td style="padding:12px 8px; color:#64748b;">${u.tipo_ubicacion}</td>
                    <td style="padding:12px 8px;"><span style="color:#10b981;">●</span> ${u.estado}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <!-- Edit placeholder -->
                        <button style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; opacity:0.5; cursor:not-allowed;"><i class="fa-solid fa-pen"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="6" style="text-align:center; padding:20px;">No hay ubicaciones registradas.</td></tr>';
        } catch (e) {}
    },

    showUbicacionForm: async function() {
        document.getElementById('form-ubic-container').style.display = 'block';
        try {
            const res = await window.api.get('/param/sucursales');
            const sel = document.getElementById('ubic-suc');
            sel.innerHTML = res.data.map(s => `<option value="${s.id}">${s.nombre}</option>`).join('');
        } catch(e) {}
        window.scrollTo({ top: document.getElementById('form-ubic-container').offsetTop, behavior: 'smooth' });
    },

    updateUbicCode: function() {
        const pasillo = document.getElementById('ubic-pasillo').value.trim().toUpperCase();
        const modulo = document.getElementById('ubic-modulo').value.trim();
        const nivel = document.getElementById('ubic-nivel').value.trim();
        
        if (!pasillo || !modulo || !nivel) return;
        
        const m = modulo.padStart(2, '0');
        const n = nivel.padStart(2, '0');
        document.getElementById('ubic-cod').value = `${pasillo}-${m}-${n}`;
    },

    saveUbicacion: async function() {
        const payload = {
            sucursal_id: document.getElementById('ubic-suc').value,
            zona: document.getElementById('ubic-zona').value.trim(),
            pasillo: document.getElementById('ubic-pasillo').value.trim(),
            modulo: document.getElementById('ubic-modulo').value.trim(),
            nivel: document.getElementById('ubic-nivel').value.trim(),
            tipo_ubicacion: document.getElementById('ubic-tipo').value
        };
        if(!payload.pasillo || !payload.modulo || !payload.nivel) {
            return window.showToast('Pasillo, Módulo y Nivel son requeridos', 'error');
        }
        try {
            const id = document.getElementById('ubic-id').value;
            if(id) await window.api.put(`/param/ubicaciones/${id}`, payload);
            else await window.api.post('/param/ubicaciones', payload);
            
            window.showToast('Ubicación guardada con éxito');
            document.getElementById('form-ubic-container').style.display = 'none';
            this.loadUbicaciones();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* --- RUTAS --- */
    getRutasHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Planificación de Rutas</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showRutaForm()"><i class="fa-solid fa-route"></i> Nueva Ruta</button>
                </div>
                ${importExportButtonsHTML('rutas')}
                ${filterBarHTML('rutas-tbody', '🔍 Buscar por nombre, comercial...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">Nombre Ruta</th>
                                <th style="padding:10px 8px;">Comercial</th>
                                <th style="padding:10px 8px;">Frecuencia</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="rutas-tbody">
                            <tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-ruta-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="ruta-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Configurar Ruta</h4>
                <input type="hidden" id="ruta-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nombre de la Ruta *</label>
                        <input type="text" id="ruta-nombre" class="input-field" placeholder="Ej: Ruta Norte, Centro...">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Comercial / Responsable</label>
                        <input type="text" id="ruta-comercial" class="input-field" placeholder="Ej: Pedro Navaja">
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label style="font-weight:600; color:#475569;">Frecuencia (Días de visita)</label>
                        <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;">
                            ${['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'].map(d => `
                                <label style="display:flex; align-items:center; background:#f8fafc; padding:8px 12px; border-radius:8px; border:1px solid #e2e8f0; cursor:pointer;">
                                    <input type="checkbox" name="ruta-days" value="${d}" style="margin-right:8px;"> ${d}
                                </label>
                            `).join('')}
                        </div>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="ruta-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveRuta()">Guardar Ruta</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="document.getElementById('form-ruta-container').style.display='none'">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadRutas: async function() {
        const tbody = document.getElementById('rutas-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/rutas');
            let html = '';
            res.data.forEach(r => {
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${r.nombre}</td>
                    <td style="padding:12px 8px; color:#475569;">${r.comercial || '-'}</td>
                    <td style="padding:12px 8px; color:#64748b;">${r.frecuencia || '-'}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <button onclick="window.Maestros.editRuta(${r.id})" style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; cursor:pointer;"><i class="fa-solid fa-pen"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="4" style="text-align:center; padding:20px;">No hay rutas registradas.</td></tr>';
        } catch (e) {}
    },

    showRutaForm: function() {
        document.getElementById('form-ruta-container').style.display = 'block';
        window.scrollTo({ top: document.getElementById('form-ruta-container').offsetTop, behavior: 'smooth' });
    },

    editRuta: async function(id) {
        const res = await window.api.get('/param/rutas');
        const r = res.data.find(x => x.id === id);
        if(!r) return;
        this.showRutaForm();
        document.getElementById('ruta-id').value = r.id;
        document.getElementById('ruta-nombre').value = r.nombre;
        document.getElementById('ruta-comercial').value = r.comercial || '';
        
        // Check days
        const days = (r.frecuencia || '').split(', ');
        const checks = document.querySelectorAll('input[name="ruta-days"]');
        checks.forEach(c => c.checked = days.includes(c.value));
    },

    saveRuta: async function() {
        const selectedDays = Array.from(document.querySelectorAll('input[name="ruta-days"]:checked')).map(c => c.value);
        const payload = {
            nombre: document.getElementById('ruta-nombre').value.trim(),
            comercial: document.getElementById('ruta-comercial').value.trim(),
            frecuencia: selectedDays.join(', ')
        };
        if(!payload.nombre) return window.showToast('Nombre de ruta es requerido', 'error');
        try {
            const id = document.getElementById('ruta-id').value;
            if(id) await window.api.put(`/param/rutas/${id}`, payload);
            else await window.api.post('/param/rutas', payload);
            window.showToast('Ruta guardada');
            document.getElementById('form-ruta-container').style.display='none';
            this.loadRutas();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* --- PERSONAL --- */
    getPersonalHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Gestión de Personal</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showPersonalForm()"><i class="fa-solid fa-user-plus"></i> Añadir Miembro</button>
                </div>
                ${importExportButtonsHTML('personal')}
                ${filterBarHTML('personal-tbody', '🔍 Buscar por documento, nombre, rol...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">Documento</th>
                                <th style="padding:10px 8px;">Nombre Completo</th>
                                <th style="padding:10px 8px;">Rol</th>
                                <th style="padding:10px 8px;">Sede Asignada</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="personal-tbody">
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-personal-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="per-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Perfil de Colaborador</h4>
                <input type="hidden" id="per-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Documento de Identidad *</label>
                        <input type="text" id="per-doc" class="input-field" placeholder="C.C. / Passport">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nombre Completo *</label>
                        <input type="text" id="per-nombre" class="input-field" placeholder="Ej: Juan Pérez">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Rol / Cargo</label>
                        <select id="per-rol" class="input-field">
                            <option value="Auxiliar">Auxiliar de Bodega</option>
                            <option value="Supervisor">Supervisor / Jefe</option>
                            <option value="Admin">Administrador Sistema</option>
                            <option value="Conductor">Conductor / Distribuidor</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Sede de Trabajo</label>
                        <select id="per-suc" class="input-field">
                            <option value="">Cualquier Sede (Global)</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">PIN de Acceso</label>
                        <input type="password" id="per-pin" class="input-field" placeholder="Mínimo 4 dígitos">
                        <small style="color:#64748b;">Deje en blanco para no cambiar (Edición)</small>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; padding-top:10px;">
                        <input type="checkbox" id="per-activo" checked style="width:20px; height:20px; cursor:pointer;">
                        <label for="per-activo" style="cursor:pointer; font-weight:500; color:#334155;">Colaborador Activo</label>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="per-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.savePersonal()">Guardar Datos</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hidePersonalForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadPersonal: async function() {
        const tbody = document.getElementById('personal-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/personal');
            let html = '';
            res.data.forEach(p => {
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${p.documento}</td>
                    <td style="padding:12px 8px; color:#475569;">${p.nombre}</td>
                    <td style="padding:12px 8px;"><span style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-size:0.8rem;">${p.rol}</span></td>
                    <td style="padding:12px 8px; color:#64748b;">${p.sucursal_id || 'Global'}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <button onclick="window.Maestros.editPersonal(${p.id})" style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; cursor:pointer;" title="Editar"><i class="fa-solid fa-user-pen"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="5" style="text-align:center; padding:20px;">No hay personal registrado.</td></tr>';
        } catch (e) {}
    },

    showPersonalForm: async function() {
        document.getElementById('form-personal-container').style.display = 'block';
        // Load sucursales
        try {
            const res = await window.api.get('/param/sucursales');
            const select = document.getElementById('per-suc');
            select.innerHTML = '<option value="">Cualquier Sede (Global)</option>';
            res.data.forEach(s => {
                select.innerHTML += `<option value="${s.id}">${s.nombre}</option>`;
            });
        } catch(e) {}
        window.scrollTo({ top: document.getElementById('form-personal-container').offsetTop, behavior: 'smooth' });
    },

    hidePersonalForm: function() {
        document.getElementById('form-personal-container').style.display = 'none';
        document.getElementById('per-id').value = '';
        document.getElementById('per-doc').value = '';
        document.getElementById('per-nombre').value = '';
        document.getElementById('per-rol').value = 'Auxiliar';
        document.getElementById('per-suc').value = '';
        document.getElementById('per-pin').value = '';
        document.getElementById('per-activo').checked = true;
    },

    editPersonal: async function(id) {
        const res = await window.api.get('/param/personal');
        const p = res.data.find(x => x.id === id);
        if(!p) return;
        this.showPersonalForm();
        document.getElementById('per-form-title').innerText = 'Editar Colaborador';
        document.getElementById('per-save-btn').innerText = 'Actualizar Datos';
        document.getElementById('per-id').value = p.id;
        document.getElementById('per-doc').value = p.documento;
        document.getElementById('per-nombre').value = p.nombre;
        document.getElementById('per-rol').value = p.rol;
        document.getElementById('per-suc').value = p.sucursal_id || '';
        document.getElementById('per-pin').value = ''; 
        document.getElementById('per-activo').checked = p.activo == 1;
    },

    savePersonal: async function() {
        const id = document.getElementById('per-id').value;
        const payload = {
            documento: document.getElementById('per-doc').value.trim(),
            nombre: document.getElementById('per-nombre').value.trim(),
            rol: document.getElementById('per-rol').value,
            sucursal_id: document.getElementById('per-suc').value || null,
            pin: document.getElementById('per-pin').value,
            activo: document.getElementById('per-activo').checked ? 1 : 0
        };
        if (!payload.documento || !payload.nombre || (!id && !payload.pin)) {
            return window.showToast('Documento, Nombre y PIN (nuevo) son requeridos', 'error');
        }
        try {
            if(id) await window.api.put(`/param/personal/${id}`, payload);
            else await window.api.post('/param/personal', payload);
            window.showToast('Personal guardado');
            this.hidePersonalForm();
            this.loadPersonal();
        } catch(e) { window.showToast('Error: ' + e.message, 'error'); }
    },

    /* --- PROVEEDORES --- */
    getProveedoresHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Directorio de Proveedores</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showProveedorForm()"><i class="fa-solid fa-truck-field"></i> Nuevo Proveedor</button>
                </div>
                ${importExportButtonsHTML('proveedores')}
                ${filterBarHTML('proveedores-tbody', '🔍 Buscar por NIT, razón social, contacto...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">NIT / Identificación</th>
                                <th style="padding:10px 8px;">Razón Social</th>
                                <th style="padding:10px 8px;">Contacto Principal</th>
                                <th style="padding:10px 8px;">Teléfono</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="proveedores-tbody">
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-prov-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="prov-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Información del Proveedor</h4>
                <input type="hidden" id="prov-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">NIT / RUT *</label>
                        <input type="text" id="prov-nit" class="input-field" placeholder="Ex: 900.123.456-7">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Razón Social *</label>
                        <input type="text" id="prov-razon" class="input-field" placeholder="Nombre legal de la empresa">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nombre de Contacto</label>
                        <input type="text" id="prov-contacto" class="input-field" placeholder="Persona encargada">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Correo Electrónico</label>
                        <input type="email" id="prov-email" class="input-field" placeholder="proveedor@empresa.com">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Teléfono / Celular</label>
                        <input type="text" id="prov-tel" class="input-field" placeholder="+57 ...">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Dirección Oficina</label>
                        <input type="text" id="prov-dir" class="input-field" placeholder="Ciudad, Dirección...">
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; padding-top:10px;">
                        <input type="checkbox" id="prov-activo" checked style="width:20px; height:20px; cursor:pointer;">
                        <label for="prov-activo" style="cursor:pointer; font-weight:500; color:#334155;">Proveedor Habilitado</label>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="prov-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveProveedor()">Guardar Proveedor</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideProveedorForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadProveedores: async function() {
        const tbody = document.getElementById('proveedores-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/proveedores');
            let html = '';
            res.data.forEach(p => {
                html += `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${p.nit}</td>
                    <td style="padding:12px 8px; color:#475569;">${p.razon_social}</td>
                    <td style="padding:12px 8px; color:#64748b;">${p.contacto_nombre || '-'}</td>
                    <td style="padding:12px 8px; color:#64748b;">${p.telefono || '-'}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <button onclick="window.Maestros.editProveedor(${p.id})" style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; cursor:pointer;"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="5" style="text-align:center; padding:20px;">No hay proveedores registrados.</td></tr>';
        } catch (e) {}
    },

    showProveedorForm: function() {
        document.getElementById('form-prov-container').style.display = 'block';
        window.scrollTo({ top: document.getElementById('form-prov-container').offsetTop, behavior: 'smooth' });
    },

    hideProveedorForm: function() {
        document.getElementById('form-prov-container').style.display = 'none';
        document.getElementById('prov-id').value = '';
        document.getElementById('prov-nit').value = '';
        document.getElementById('prov-razon').value = '';
        document.getElementById('prov-contacto').value = '';
        document.getElementById('prov-email').value = '';
        document.getElementById('prov-tel').value = '';
        document.getElementById('prov-dir').value = '';
        document.getElementById('prov-activo').checked = true;
        document.getElementById('prov-save-btn').innerText = 'Guardar';
        document.getElementById('prov-form-title').innerText = 'Información del Proveedor';
    },

    editProveedor: async function(id) {
        const res = await window.api.get('/param/proveedores');
        const p = res.data.find(x => x.id === id);
        if(!p) return;
        this.showProveedorForm();
        document.getElementById('prov-form-title').innerText = 'Editar Proveedor';
        document.getElementById('prov-save-btn').innerText = 'Actualizar Datos';
        document.getElementById('prov-id').value = p.id;
        document.getElementById('prov-nit').value = p.nit;
        document.getElementById('prov-razon').value = p.razon_social;
        document.getElementById('prov-contacto').value = p.contacto_nombre || '';
        document.getElementById('prov-email').value = p.email || '';
        document.getElementById('prov-tel').value = p.telefono || '';
        document.getElementById('prov-dir').value = p.direccion || '';
        document.getElementById('prov-activo').checked = p.activo == 1;
    },

    saveProveedor: async function() {
        const id = document.getElementById('prov-id').value;
        const payload = {
            nit: document.getElementById('prov-nit').value.trim(),
            razon_social: document.getElementById('prov-razon').value.trim(),
            contacto_nombre: document.getElementById('prov-contacto').value.trim(),
            email: document.getElementById('prov-email').value.trim(),
            telefono: document.getElementById('prov-tel').value.trim(),
            direccion: document.getElementById('prov-dir').value.trim(),
            activo: document.getElementById('prov-activo').checked ? 1 : 0
        };
        if (!payload.nit || !payload.razon_social) {
            return window.showToast('NIT y Razón Social son requeridos', 'error');
        }
        try {
            if(id) await window.api.put(`/param/proveedores/${id}`, payload);
            else await window.api.post('/param/proveedores', payload);
            window.showToast('Proveedor guardado con éxito');
            this.hideProveedorForm();
            this.loadProveedores();
        } catch(e) { window.showToast('Error: ' + e.message, 'error'); }
    },

    /* --- CLIENTES --- */
    getClientesHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h4 style="margin:0; color:#0f172a;">Directorio de Clientes</h4>
                    <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Maestros.showClienteForm()"><i class="fa-solid fa-users-rectangle"></i> Nuevo Cliente</button>
                </div>
                ${importExportButtonsHTML('clientes')}
                ${filterBarHTML('clientes-tbody', '🔍 Buscar por NIT, razón social, contacto...')}
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                                <th style="padding:10px 8px;">NIT / Identificación</th>
                                <th style="padding:10px 8px;">Razón Social</th>
                                <th style="padding:10px 8px;">Contacto</th>
                                <th style="padding:10px 8px;">Ciudad</th>
                                <th style="padding:10px 8px; width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="clientes-tbody">
                            <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Form Template -->
            <div id="form-cliente-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:800px; margin:0 auto 30px;">
                <h4 id="cli-form-title" style="margin-top:0; color:#0f172a; margin-bottom: 24px; font-size:1.25rem; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Información del Cliente</h4>
                <input type="hidden" id="cli-id" value="">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Documento / NIT *</label>
                        <input type="text" id="cli-nit" class="input-field" placeholder="12345678-9">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Razón Social / Nombre Completo *</label>
                        <input type="text" id="cli-razon" class="input-field" placeholder="Nombre de la empresa o persona">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Ruta de Entrega / Despacho</label>
                        <select id="cli-ruta" class="input-field">
                            <option value="">Sin Ruta Asignada</option>
                        </select>
                    </div>
                    <div class="input-group" style="grid-column: span 2;">
                        <label style="font-weight:600; color:#475569;">Dirección</label>
                        <input type="text" id="cli-dir" class="input-field" placeholder="Av. Principal 123">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Ciudad</label>
                        <input type="text" id="cli-ciudad" class="input-field" placeholder="Ej: Bogotá">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Teléfono</label>
                        <input type="text" id="cli-tel" class="input-field" placeholder="300 000 0000">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Correo Electrónico</label>
                        <input type="email" id="cli-email" class="input-field" placeholder="contacto@cliente.com">
                    </div>
                    <div class="input-group">
                        <label style="font-weight:600; color:#475569;">Nombre de Contacto</label>
                        <input type="text" id="cli-contacto" class="input-field" placeholder="Juan Pérez">
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; padding-top:10px; grid-column: span 2;">
                        <input type="checkbox" id="cli-activo" checked style="width:20px; height:20px; cursor:pointer;">
                        <label for="cli-activo" style="cursor:pointer; font-weight:500; color:#334155;">Cliente Activo</label>
                    </div>
                </div>
                
                <div style="display:flex; gap: 10px; margin-top:30px;">
                    <button id="cli-save-btn" class="btn-primary" style="background:#0f172a; flex:1;" onclick="window.Maestros.saveCliente()">Guardar Cliente</button>
                    <button class="btn-primary" style="background:#cbd5e1; flex:1; color:#334155" onclick="window.Maestros.hideClienteForm()">Cancelar</button>
                </div>
            </div>
        `;
    },

    loadClientes: async function() {
        const tbody = document.getElementById('clientes-tbody');
        if(!tbody) return;
        try {
            const res = await window.api.get('/param/clientes');
            let html = '';
            res.data.forEach(c => {
                html += `<tr style="border-bottom:1px solid #f1f5f9; ${c.activo ? '' : 'opacity:0.6;'}">
                    <td style="padding:12px 8px; font-weight:600; color:#334155;">${c.nit}</td>
                    <td style="padding:12px 8px; color:#475569;">${c.razon_social}</td>
                    <td style="padding:12px 8px; color:#64748b;">${c.contacto_nombre || '-'}</td>
                    <td style="padding:12px 8px; color:#64748b;">${c.ciudad || '-'}</td>
                    <td style="padding:12px 8px; text-align:center;">
                        <button onclick="window.Maestros.editCliente(${c.id})" style="border:none; background:#f1f5f9; color:#475569; padding:6px 10px; border-radius:4px; cursor:pointer;"><i class="fa-solid fa-pen-to-square"></i></button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="5" style="text-align:center; padding:20px;">No hay clientes registrados.</td></tr>';
        } catch (e) {}
    },

    showClienteForm: async function() {
        document.getElementById('form-cliente-container').style.display = 'block';
        // Load Rutas
        try {
            const res = await window.api.get('/param/rutas');
            const selBit = document.getElementById('cli-ruta');
            selBit.innerHTML = '<option value="">Sin Ruta Asignada</option>' + 
                res.data.map(r => `<option value="${r.id}">${r.nombre}</option>`).join('');
        } catch(e) {}
        window.scrollTo({ top: document.getElementById('form-cliente-container').offsetTop, behavior: 'smooth' });
    },

    hideClienteForm: function() {
        document.getElementById('form-cliente-container').style.display = 'none';
        document.getElementById('cli-id').value = '';
        document.getElementById('cli-nit').value = '';
        document.getElementById('cli-razon').value = '';
        document.getElementById('cli-ruta').value = '';
        document.getElementById('cli-dir').value = '';
        document.getElementById('cli-ciudad').value = '';
        document.getElementById('cli-tel').value = '';
        document.getElementById('cli-email').value = '';
        document.getElementById('cli-contacto').value = '';
        document.getElementById('cli-activo').checked = true;
        document.getElementById('cli-save-btn').innerText = 'Guardar';
        document.getElementById('cli-form-title').innerText = 'Información del Cliente';
    },

    editCliente: async function(id) {
        const res = await window.api.get('/param/clientes');
        const c = res.data.find(x => x.id === id);
        if(!c) return;
        await this.showClienteForm();
        document.getElementById('cli-form-title').innerText = 'Editar Cliente';
        document.getElementById('cli-save-btn').innerText = 'Actualizar Datos';
        document.getElementById('cli-id').value = c.id;
        document.getElementById('cli-nit').value = c.nit;
        document.getElementById('cli-razon').value = c.razon_social;
        document.getElementById('cli-ruta').value = c.ruta_id || '';
        document.getElementById('cli-dir').value = c.direccion || '';
        document.getElementById('cli-ciudad').value = c.ciudad || '';
        document.getElementById('cli-tel').value = c.telefono || '';
        document.getElementById('cli-email').value = c.email || '';
        document.getElementById('cli-contacto').value = c.contacto_nombre || '';
        document.getElementById('cli-activo').checked = c.activo == 1;
    },

    saveCliente: async function() {
        const id = document.getElementById('cli-id').value;
        const payload = {
            nit: document.getElementById('cli-nit').value.trim(),
            razon_social: document.getElementById('cli-razon').value.trim(),
            ruta_id: document.getElementById('cli-ruta').value || null,
            direccion: document.getElementById('cli-dir').value.trim(),
            ciudad: document.getElementById('cli-ciudad').value.trim(),
            telefono: document.getElementById('cli-tel').value.trim(),
            email: document.getElementById('cli-email').value.trim(),
            contacto_nombre: document.getElementById('cli-contacto').value.trim(),
            activo: document.getElementById('cli-activo').checked ? 1 : 0
        };
        if(!payload.nit || !payload.razon_social) return window.showToast('NIT y Nombre son requeridos', 'error');
        try {
            if(id) await window.api.put(`/param/clientes/${id}`, payload);
            else await window.api.post('/param/clientes', payload);
            window.showToast('Cliente guardado');
            this.hideClienteForm();
            this.loadClientes();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* --- BULK IMPORT/EXPORT --- */
    downloadTemplate: function(tipo) {
        window.location.href = window.api.baseUrl + `/param/import-export/template/${tipo}${window.api.getToken() ? '?token=' + window.api.getToken() : ''}`;
    },

    handleBulkImport: async function(input, tipo) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        
        const formData = new FormData();
        formData.append('file', file);

        try {
            window.showToast('Procesando archivo...', 'info');
            const res = await window.api.post(`/param/import-export/upload/${tipo}`, formData, true); // true for multipart
            if (res.error) {
                window.showToast(res.message, 'error');
            } else {
                window.showToast(res.message, 'success');
                // Reload current view
                const loadFn = 'load' + tipo.charAt(0).toUpperCase() + tipo.slice(1);
                if (this[loadFn]) this[loadFn]();
            }
        } catch (e) {
            window.showToast('Error al importar: ' + e.message, 'error');
        } finally {
            input.value = ''; // Reset input
        }
    }
};

