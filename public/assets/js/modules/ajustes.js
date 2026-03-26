/**
 * Prooriente WMS - Ajustes & Permisos Module
 */
window.Ajustes = {
    
    /* --- GESTOR DE PERMISOS --- */
    getPermisosHTML: function() {
        return `
            <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:15px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px;">
                        <h4 style="margin:0; color:#0f172a; margin-bottom:4px;">Gestor de Permisos por Rol</h4>
                        <p style="margin:0; font-size:0.85rem; color:#64748b;">Configure el acceso a módulos y acciones para cada cargo.</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <label style="font-weight:600; color:#475569; font-size:0.9rem;">Seleccionar Rol:</label>
                        <select id="perm-rol-select" class="input-field" style="width:200px; margin:0;" onchange="window.Ajustes.loadPermissionsMatrix()">
                            <option value="">Cargando roles...</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:14px; position:relative;">
                    <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.85rem;"></i>
                    <input type="text" class="input-field" placeholder="🔍 Buscar por módulo, acción o descripción..." onkeyup="window.handleSmartFilter(this, 'permisos-matrix-tbody')"
                        style="padding-left:36px; border-radius:8px; height:40px; font-size:0.85rem; border:1px solid #e2e8f0; width:100%; box-sizing:border-box;">
                </div>

                <div style="overflow-x:auto; border:1px solid #f1f5f9; border-radius:8px;">
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem;">
                        <thead style="background:#f8fafc;">
                            <tr style="border-bottom:2px solid #e2e8f0; color:#475569;">
                                <th style="padding:12px 15px; width:150px;">Módulo</th>
                                <th style="padding:12px 15px;">Acción / Permiso</th>
                                <th style="padding:12px 15px;">Descripción</th>
                                <th style="padding:12px 15px; text-align:center; width:100px;">Concedido</th>
                            </tr>
                        </thead>
                        <tbody id="permisos-matrix-tbody">
                            <tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;"><i class="fa-solid fa-shield-halved fa-beat" style="font-size:2rem; margin-bottom:10px;"></i><br>Seleccione un rol para ver la matriz</td></tr>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:20px; padding:15px; background:#f0f9ff; border-radius:8px; border:1px solid #bae6fd; display:flex; gap:10px; align-items:center;">
                    <i class="fa-solid fa-circle-info" style="color:#0ea5e9;"></i>
                    <p style="margin:0; font-size:0.85rem; color:#0369a1;">Los cambios se guardan automáticamente al marcar/desmarcar cada casilla.</p>
                </div>
            </div>
        `;
    },

    loadRoles: async function() {
        const select = document.getElementById('perm-rol-select');
        if(!select) return;
        try {
            const res = await window.api.get('/param/roles');
            select.innerHTML = '<option value="">-- Seleccione un Rol --</option>';
            res.data.forEach(r => {
                select.innerHTML += `<option value="${r.id}">${r.nombre}</option>`;
            });
        } catch(e) { console.error(e); }
    },

    loadPermissionsMatrix: async function() {
        const rol = document.getElementById('perm-rol-select').value;
        const tbody = document.getElementById('permisos-matrix-tbody');
        if(!rol) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">Seleccione un rol para continuar</td></tr>';
            return;
        }

        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:40px;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando matriz de permisos...</td></tr>';
        
        try {
            const res = await window.api.get(`/param/permisos-matriz/${rol}`);
            let html = '';
            let currentModulo = '';

            res.data.forEach(p => {
                const sameModulo = p.modulo === currentModulo;
                html += `<tr style="border-bottom:1px solid #f1f5f9; ${!sameModulo ? 'border-top:2px solid #f1f5f9;' : ''}">
                    <td style="padding:12px 15px; font-weight:700; color:#0f172a; text-transform:capitalize;">${sameModulo ? '' : p.modulo}</td>
                    <td style="padding:12px 15px;"><span style="background:#f1f5f9; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:600;">${p.accion}</span></td>
                    <td style="padding:12px 15px; color:#64748b;">${p.descripcion}</td>
                    <td style="padding:12px 15px; text-align:center;">
                        <input type="checkbox" ${p.concedido ? 'checked' : ''} 
                            style="width:20px; height:20px; cursor:pointer;" 
                            onchange="window.Ajustes.togglePermiso('${rol}', ${p.id}, this.checked)">
                    </td>
                </tr>`;
                currentModulo = p.modulo;
            });
            tbody.innerHTML = html || '<tr><td colspan="4" style="text-align:center; padding:20px;">No se encontraron permisos definidos.</td></tr>';
        } catch(e) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:20px; color:red;">Error: ${e.message}</td></tr>`;
        }
    },

    togglePermiso: async function(rol, permisoId, concedido) {
        try {
            await window.api.post('/param/permisos-toggle', {
                rol: rol,
                permiso_id: permisoId,
                concedido: concedido
            });
            window.showToast('Permiso actualizado');
            // We don't reload matrix to keep scroll position and UX
        } catch (e) {
            window.showToast('Error al actualizar permiso', 'error');
            // Revert checkbox if failed? maybe too complex for simple UI
        }
    },

    /* --- PROFILE & COMPANY DATA --- */
    getProfileHTML: function() {
        const ud = (() => { try { return JSON.parse(localStorage.getItem('user_data') || '{}'); } catch(_) { return {}; } })();
        const rolColors = { Admin:'#dc2626', Supervisor:'#f59e0b', Auxiliar:'#3b82f6', Montacarguista:'#8b5cf6', Analista:'#22c55e' };
        const rolColor = rolColors[ud.rol] || '#64748b';
        return `
        <div style="max-width:560px; margin:0 auto; padding:4px;">
            <!-- Tarjeta de identidad -->
            <div style="background:white; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,0.05);">
                <div style="background:linear-gradient(135deg,#0f172a,#1e293b); padding:24px 20px; display:flex; align-items:center; gap:16px;">
                    <div style="width:56px; height:56px; background:${rolColor}30; color:${rolColor}; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.5rem; flex-shrink:0;">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <div style="font-size:1.1rem; font-weight:700; color:white;">${escHTML(ud.nombre || 'Usuario')}</div>
                        <div style="display:inline-block; margin-top:4px; padding:3px 10px; background:${rolColor}25; color:${rolColor}; border-radius:999px; font-size:0.75rem; font-weight:700; border:1px solid ${rolColor}40;">${escHTML(ud.rol || '—')}</div>
                    </div>
                </div>
                <div style="padding:16px 20px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div style="font-size:0.8rem; color:#64748b;">ID de usuario</div>
                    <div style="font-size:0.8rem; font-weight:600; color:#0f172a;">#${parseInt(ud.id || 0)}</div>
                    <div style="font-size:0.8rem; color:#64748b;">Empresa ID</div>
                    <div style="font-size:0.8rem; font-weight:600; color:#0f172a;">#${parseInt(ud.empresa_id || 0)}</div>
                    <div style="font-size:0.8rem; color:#64748b;">Sucursal ID</div>
                    <div style="font-size:0.8rem; font-weight:600; color:#0f172a;">#${parseInt(ud.sucursal_id || 0)}</div>
                </div>
            </div>

            <!-- Cambiar PIN -->
            <div style="background:white; border:1px solid #e2e8f0; border-radius:16px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.05);">
                <h4 style="margin:0 0 4px; font-size:0.95rem; color:#0f172a;">
                    <i class="fa-solid fa-key" style="color:#f59e0b; margin-right:6px;"></i>Cambiar PIN de Acceso
                </h4>
                <p style="margin:0 0 16px; font-size:0.8rem; color:#64748b;">El PIN se usa para iniciar sesión. Mínimo 4 dígitos numéricos.</p>
                <div style="display:grid; gap:12px;">
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">PIN ACTUAL</label>
                        <input type="password" id="pin-actual" inputmode="numeric" maxlength="8" placeholder="••••"
                            style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:1rem; letter-spacing:0.2em; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">PIN NUEVO</label>
                        <input type="password" id="pin-nuevo" inputmode="numeric" maxlength="8" placeholder="••••"
                            style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:1rem; letter-spacing:0.2em; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.78rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">CONFIRMAR PIN NUEVO</label>
                        <input type="password" id="pin-confirmar" inputmode="numeric" maxlength="8" placeholder="••••"
                            style="width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:1rem; letter-spacing:0.2em; box-sizing:border-box;">
                    </div>
                    <button onclick="window.Ajustes.cambiarPin()"
                        style="width:100%; padding:11px; background:#0f172a; color:white; border:none; border-radius:10px; font-size:0.9rem; font-weight:600; cursor:pointer;">
                        <i class="fa-solid fa-lock"></i> Actualizar PIN
                    </button>
                </div>
            </div>
        </div>`;
    },

    cambiarPin: async function() {
        const actual    = document.getElementById('pin-actual')?.value?.trim();
        const nuevo     = document.getElementById('pin-nuevo')?.value?.trim();
        const confirmar = document.getElementById('pin-confirmar')?.value?.trim();
        if (!actual || !nuevo || !confirmar) return window.showToast('Complete todos los campos', 'error');
        if (nuevo !== confirmar)             return window.showToast('Los PINs nuevos no coinciden', 'error');
        if (nuevo.length < 4)               return window.showToast('El PIN debe tener al menos 4 dígitos', 'error');
        try {
            await window.api.put('/auth/pin', { pin_actual: actual, pin_nuevo: nuevo });
            window.showToast('PIN actualizado correctamente', 'success');
            document.getElementById('pin-actual').value    = '';
            document.getElementById('pin-nuevo').value     = '';
            document.getElementById('pin-confirmar').value = '';
        } catch(e) {
            window.showToast(e.message || 'Error al actualizar PIN', 'error');
        }
    },

    getCompanyConfigHTML: function() {
        return `
        <div style="max-width:600px; margin:0 auto; padding:4px;">
            <div style="background:white; border:1px solid #e2e8f0; border-radius:16px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.05);">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
                    <div style="width:44px; height:44px; background:#f0fdf4; color:#22c55e; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                        <i class="fa-solid fa-building"></i>
                    </div>
                    <div>
                        <h4 style="margin:0; font-size:1rem; color:#0f172a;">Datos de la Empresa</h4>
                        <p style="margin:0; font-size:0.78rem; color:#64748b;">Información legal y de contacto</p>
                    </div>
                </div>
                <div id="empresa-config-form" style="text-align:center; padding:20px; color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Cargando...
                </div>
            </div>
        </div>`;
    },

    initCompanyConfig: async function() {
        const el = document.getElementById('empresa-config-form');
        if (!el) return;
        try {
            const res = await window.api.get('/param/empresas');
            const empresas = res.data || [];
            const e = empresas[0];
            if (!e) {
                el.innerHTML = '<p style="color:#94a3b8;">No se encontró empresa asociada.</p>';
                return;
            }
            window.Ajustes._empresaId = e.id;
            el.innerHTML = `
            <div style="display:grid; gap:14px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                    <div>
                        <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">NIT</label>
                        <input id="emp-nit" class="input-field" value="${escHTML(e.nit || '')}" placeholder="900123456-7">
                    </div>
                    <div>
                        <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">RAZÓN SOCIAL</label>
                        <input id="emp-razon" class="input-field" value="${escHTML(e.razon_social || '')}" placeholder="Nombre Legal S.A.S">
                    </div>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">DIRECCIÓN</label>
                    <input id="emp-dir" class="input-field" value="${escHTML(e.direccion || '')}" placeholder="Calle 123 # 45-67">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                    <div>
                        <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">TELÉFONO</label>
                        <input id="emp-tel" class="input-field" value="${escHTML(e.telefono || '')}" placeholder="+57 300 000 0000">
                    </div>
                    <div>
                        <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">EMAIL</label>
                        <input id="emp-email" class="input-field" type="email" value="${escHTML(e.email || '')}" placeholder="info@empresa.com">
                    </div>
                </div>
                <div>
                    <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">URL LOGO</label>
                    <input id="emp-logo" class="input-field" value="${escHTML(e.logo_url || '')}" placeholder="https://...">
                </div>
                <button onclick="window.Ajustes.guardarEmpresaConfig()"
                    style="width:100%; padding:11px; background:#22c55e; color:white; border:none; border-radius:10px; font-size:0.9rem; font-weight:600; cursor:pointer; margin-top:4px;">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Cambios
                </button>
            </div>`;
        } catch(e) {
            el.innerHTML = `<p style="color:#ef4444;">Error al cargar: ${escHTML(e.message)}</p>`;
        }
    },

    guardarEmpresaConfig: async function() {
        const id = this._empresaId;
        if (!id) return;
        const payload = {
            nit:          document.getElementById('emp-nit')?.value?.trim(),
            razon_social: document.getElementById('emp-razon')?.value?.trim(),
            direccion:    document.getElementById('emp-dir')?.value?.trim()   || null,
            telefono:     document.getElementById('emp-tel')?.value?.trim()   || null,
            email:        document.getElementById('emp-email')?.value?.trim() || null,
            logo_url:     document.getElementById('emp-logo')?.value?.trim()  || null,
        };
        if (!payload.nit || !payload.razon_social) return window.showToast('NIT y Razón Social son requeridos', 'error');
        try {
            await window.api.put(`/param/empresas/${id}`, payload);
            window.showToast('Datos de empresa guardados', 'success');
        } catch(e) {
            window.showToast(e.message || 'Error al guardar', 'error');
        }
    },
};
