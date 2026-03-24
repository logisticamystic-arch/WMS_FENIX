/**
 * Prooriente WMS - Módulo de Recepción
 * Citas + Recepción Modo Ciego + Recepción vinculada a cita
 */
window.Recepcion = {
    currentRecepcion: null,
    _lastProductoId: null,
    _lastProductoNombre: null,
    _scanTimer: null,

    /* ====================================================================
       GESTIÓN DE CITAS
    ==================================================================== */
    getCitasHTML() {
        return `
        <div style="background:white; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h4 style="margin:0; color:#0f172a;">Agendamiento de Citas</h4>
                <button class="btn-primary" style="padding:8px 16px; width:auto; border-radius:8px; font-size:0.9rem;" onclick="window.Recepcion.showCitaForm()">
                    <i class="fa-solid fa-calendar-plus"></i> Nueva Cita
                </button>
            </div>
            ${typeof filterBarHTML === 'function' ? filterBarHTML('citas-tbody', '🔍 Buscar por proveedor, ODC o estado...') : ''}
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.9rem;">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0; color:#64748b;">
                            <th style="padding:10px 8px;">Fecha/Hora</th>
                            <th style="padding:10px 8px;">Proveedor</th>
                            <th style="padding:10px 8px;">ODC</th>
                            <th style="padding:10px 8px;">Estado</th>
                            <th style="padding:10px 8px; width:100px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="citas-tbody">
                        <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i> Cargando citas...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="form-cita-container" style="display:none; background:white; border-radius:12px; padding:25px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); border:1px solid #e2e8f0; max-width:600px; margin:0 auto 30px;">
            <h4 style="margin-top:0; color:#0f172a; margin-bottom:20px;">Programar Cita</h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="input-group" style="grid-column:span 2;">
                    <label>Proveedor *</label>
                    <input type="text" id="cita-prov" class="input-field" placeholder="Nombre del proveedor">
                </div>
                <div class="input-group">
                    <label>Fecha *</label>
                    <input type="date" id="cita-fecha" class="input-field" onchange="window.Recepcion.checkDisponibilidad()">
                </div>
                <div class="input-group">
                    <label>Hora *</label>
                    <input type="time" id="cita-hora" class="input-field">
                    <div id="cita-info-cupos" style="font-size:0.7rem; color:#6366f1; margin-top:4px;">Seleccione una fecha para ver disponibilidad</div>
                </div>
                <div class="input-group">
                    <label>Tipo de Vehículo</label>
                    <input type="text" id="cita-tipo-carro" class="input-field" placeholder="Ej: Camión NHR, Turbo...">
                </div>
                <div class="input-group">
                    <label>Peso Estimado (Kg)</label>
                    <input type="number" id="cita-peso" class="input-field" placeholder="0.00">
                </div>
                <div class="input-group">
                    <label>Orden de Compra</label>
                    <input type="text" id="cita-odc" class="input-field" placeholder="ODC-123">
                </div>
                <div class="input-group">
                    <label>Cant. Cajas (Est.)</label>
                    <input type="number" id="cita-cajas" class="input-field" placeholder="0">
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button class="btn-primary" style="flex:1;" onclick="window.Recepcion.saveCita()">Guardar</button>
                <button class="btn-primary" style="flex:1; background:#cbd5e1; color:#334155;" onclick="document.getElementById('form-cita-container').style.display='none'">Cancelar</button>
            </div>
        </div>`;
    },

    async loadCitas() {
        const tbody = document.getElementById('citas-tbody');
        if (!tbody) return;
        try {
            const res = await window.api.get('/citas');
            let html = '';
            (res.data || []).forEach(c => {
                const badgeColor = c.estado === 'Programada' ? '#3b82f6' : (c.estado === 'EnCurso' ? '#f59e0b' : '#10b981');
                html += `
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:12px 8px;">
                        <div style="font-weight:600;">${c.fecha}</div>
                        <div style="font-size:0.75rem; color:#64748b;">${c.hora_programada}</div>
                    </td>
                    <td style="padding:12px 8px; color:#475569;">${c.proveedor}</td>
                    <td style="padding:12px 8px; font-family:monospace;">${c.odc || '—'}</td>
                    <td style="padding:12px 8px;"><span style="color:white; background:${badgeColor}; padding:2px 8px; border-radius:10px; font-size:0.75rem;">${c.estado}</span></td>
                    <td style="padding:12px 8px; text-align:right;">
                        ${c.estado === 'Programada'
                            ? `<button onclick="window.Recepcion.iniciarDesdeCita(${c.id})" class="btn-primary" style="padding:4px 8px; font-size:0.7rem; width:auto; background:#10b981;">
                                <i class="fa-solid fa-play"></i> Recibir
                               </button>`
                            : ''}
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html || '<tr><td colspan="5" style="text-align:center; padding:20px;">No hay citas pendientes.</td></tr>';
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#ef4444; padding:20px;">Error cargando citas.</td></tr>';
        }
    },

    showCitaForm() {
        document.getElementById('form-cita-container').style.display = 'block';
    },

    async saveCita() {
        const payload = {
            proveedor:      document.getElementById('cita-prov').value,
            fecha:          document.getElementById('cita-fecha').value,
            hora_programada:document.getElementById('cita-hora').value,
            odc:            document.getElementById('cita-odc').value,
            cantidad_cajas: document.getElementById('cita-cajas').value,
            tipo_vehiculo:  document.getElementById('cita-tipo-carro').value,
            kilos:          document.getElementById('cita-peso').value,
        };
        if (!payload.proveedor || !payload.fecha || !payload.hora_programada) {
            return window.showToast('Proveedor, Fecha y Hora son requeridos', 'error');
        }
        try {
            await window.api.post('/citas', payload);
            window.showToast('Cita agendada correctamente', 'success');
            document.getElementById('form-cita-container').style.display = 'none';
            this.loadCitas();
        } catch (e) { window.showToast(e.message, 'error'); }
    },

    async checkDisponibilidad() {
        const fecha = document.getElementById('cita-fecha').value;
        if (!fecha) return;
        const info = document.getElementById('cita-info-cupos');
        info.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Consultando...';
        try {
            const res = await window.api.get(`/citas/disponibilidad?fecha=${fecha}`);
            if (res.ocupacion?.length > 0) {
                let txt = 'Ocupado: ' + res.ocupacion.map(o => `${o.hora_programada}(${o.total}/${res.max_por_hora})`).join(', ');
                info.innerText = txt;
                info.style.color = '#ef4444';
            } else {
                info.innerText = 'Disponible (máx ' + res.max_por_hora + ' por hora)';
                info.style.color = '#10b981';
            }
        } catch (e) { info.innerText = 'Error al consultar'; }
    },

    /* ====================================================================
       OPERACIÓN DE RECEPCIÓN
    ==================================================================== */
    getRecepcionNuevaHTML() {
        return `
        <div style="max-width:800px; margin:0 auto;">
            <div id="recepcion-setup" style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; text-align:center;">
                <i class="fa-solid fa-boxes-packing" style="font-size:3rem; color:#6366f1; margin-bottom:20px;"></i>
                <h3 style="margin:0; color:#0f172a;">Nueva Recepción de Mercancía</h3>
                <p style="color:#64748b; margin-bottom:30px;">Inicie una descarga manual o vincule a una cita existente</p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                    <button class="btn-primary" style="height:auto; padding:25px; background:#f8fafc; color:#334155; border:1px solid #e2e8f0;" onclick="window.Recepcion.iniciarNueva(true)">
                        <i class="fa-solid fa-eye-slash" style="display:block; font-size:1.5rem; margin-bottom:10px; color:#6366f1;"></i>
                        <strong>Modo Ciego</strong>
                        <div style="font-size:0.75rem; color:#64748b; font-weight:400; margin-top:5px;">Contar sin saber lo esperado (más precisión)</div>
                    </button>
                    <button class="btn-primary" style="height:auto; padding:25px; background:#f8fafc; color:#334155; border:1px solid #e2e8f0;" onclick="window.Recepcion.abrirSelectorCita()">
                        <i class="fa-solid fa-calendar-check" style="display:block; font-size:1.5rem; margin-bottom:10px; color:#10b981;"></i>
                        <strong>Vincular Cita</strong>
                        <div style="font-size:0.75rem; color:#64748b; font-weight:400; margin-top:5px;">Verificar contra orden de compra / proveedor</div>
                    </button>
                </div>
            </div>

            <div id="recepcion-active" style="display:none; background:white; border-radius:12px; padding:20px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); border:1px solid #e2e8f0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #f1f5f9; padding-bottom:15px;">
                    <div>
                        <span id="label-n-recep" style="font-size:0.75rem; color:#64748b; text-transform:uppercase;">Recepción</span>
                        <h4 id="active-recepcion-num" style="margin:0; color:#0f172a;">Cargando...</h4>
                        <div id="active-recepcion-meta" style="font-size:0.78rem; color:#64748b; margin-top:2px;"></div>
                    </div>
                    <button class="btn-primary" style="background:#ef4444; width:auto; font-size:0.8rem;" onclick="window.Recepcion.confirmarFinal()">
                        <i class="fa-solid fa-check-double"></i> Finalizar Recepción
                    </button>
                </div>

                <!-- Buscador inteligente -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:15px; margin-bottom:20px;">
                    <label style="font-size:0.8rem; font-weight:700; color:#475569; display:block; margin-bottom:8px; text-transform:uppercase;">
                        <i class="fa-solid fa-magnifying-glass" style="color:#6366f1;"></i> Buscar Producto (EAN / Código / Nombre)
                    </label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="recep-scan" class="input-field" placeholder="Escanee EAN o escriba nombre/código..."
                            style="flex:1;" oninput="window.Recepcion._scanDebounce()">
                        <button class="btn-primary" style="width:auto; padding:0 16px; background:#6366f1;" onclick="window.Recepcion.buscarProducto()">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>
                    <!-- Tabla de coincidencias -->
                    <div id="recep-search-results" style="display:none; margin-top:10px; border:1px solid #e2e8f0; border-radius:8px; background:white; max-height:220px; overflow-y:auto;"></div>
                    <!-- Producto seleccionado -->
                    <div id="recep-producto-sel" style="display:none; margin-top:10px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <div id="recep-prod-nombre" style="font-weight:700; color:#166534; font-size:0.9rem;"></div>
                                <div id="recep-prod-codigo" style="font-size:0.75rem; color:#15803d;"></div>
                            </div>
                            <button onclick="window.Recepcion._limpiarProducto()" style="background:none; border:none; color:#64748b; cursor:pointer; font-size:1rem;">
                                <i class="fa-solid fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Campos de línea -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="input-group">
                        <label>Cantidad Recibida *</label>
                        <input type="number" id="recep-cant" class="input-field" value="1" min="1">
                    </div>
                    <div class="input-group">
                        <label>Lote</label>
                        <input type="text" id="recep-lote" class="input-field" placeholder="LOTE123">
                    </div>
                    <div class="input-group">
                        <label>Fecha de Vencimiento</label>
                        <input type="date" id="recep-vence" class="input-field">
                    </div>
                    <div class="input-group">
                        <label>Estado Mercancía</label>
                        <select id="recep-estado" class="input-field">
                            <option value="BuenEstado">Buen Estado</option>
                            <option value="Averia">Avería</option>
                            <option value="Cuarentena">Cuarentena</option>
                        </select>
                    </div>
                </div>
                <button class="btn-primary" style="background:#0f172a; margin-bottom:20px;" onclick="window.Recepcion.agregarLinea()">
                    <i class="fa-solid fa-plus-circle"></i> Agregar Item a Recepción
                </button>

                <!-- Resumen de items -->
                <div style="border-top:1px solid #f1f5f9; padding-top:15px;">
                    <h5 style="margin:0 0 10px; color:#475569; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-list-check"></i> Items Recibidos
                        <span id="recep-count-badge" style="background:#6366f1; color:white; border-radius:999px; padding:2px 8px; font-size:0.72rem; font-weight:700;">0</span>
                    </h5>
                    <div id="recep-detalles-list" style="max-height:300px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:8px;">
                        <div style="padding:20px; text-align:center; color:#94a3b8; font-size:0.85rem;">Sin items agregados aún.</div>
                    </div>
                </div>
            </div>
        </div>`;
    },

    /* ── Selector de cita ────────────────────────────────────────────────── */
    async abrirSelectorCita() {
        let citas = [];
        try {
            const res = await window.api.get('/citas');
            citas = (res.data || []).filter(c => c.estado === 'Programada');
        } catch (e) {}

        const modal = document.createElement('div');
        modal.id = 'cita-selector-modal';
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9992; display:flex; align-items:center; justify-content:center; padding:16px;';

        const filas = citas.length
            ? citas.map(c => `
                <div style="display:flex; align-items:center; justify-content:space-between; padding:12px; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:8px; background:#f8fafc; cursor:pointer;"
                     onclick="window.Recepcion._seleccionarCita(${c.id}, '${c.proveedor.replace(/'/g, "\\'")}'); document.getElementById('cita-selector-modal').remove();">
                    <div>
                        <div style="font-weight:700; color:#0f172a; font-size:0.9rem;">${c.proveedor}</div>
                        <div style="font-size:0.75rem; color:#64748b;">${c.fecha} ${c.hora_programada} · ${c.odc || 'Sin ODC'}</div>
                    </div>
                    <span style="background:#3b82f620; color:#3b82f6; border-radius:8px; padding:4px 10px; font-size:0.75rem; font-weight:700;">Programada</span>
                </div>`).join('')
            : `<div style="text-align:center; padding:40px; color:#94a3b8;">
                <i class="fa-solid fa-calendar-xmark" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                No hay citas programadas pendientes.
               </div>`;

        modal.innerHTML = `
        <div style="background:white; border-radius:16px; width:100%; max-width:500px; max-height:80vh; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3); display:flex; flex-direction:column;">
            <div style="padding:16px 20px; background:#0f172a; color:white; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <h3 style="margin:0; font-size:1rem;"><i class="fa-solid fa-calendar-check"></i> Seleccionar Cita</h3>
                <button onclick="document.getElementById('cita-selector-modal').remove()"
                    style="width:32px; height:32px; background:#374151; border:none; border-radius:8px; color:white; cursor:pointer; font-size:1rem;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="padding:16px 20px; overflow-y:auto; flex:1;">
                ${filas}
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e2e8f0; flex-shrink:0;">
                <button onclick="window.Recepcion.iniciarNueva(false); document.getElementById('cita-selector-modal').remove();"
                    style="width:100%; padding:10px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; color:#475569; cursor:pointer;">
                    Sin cita — Recepción directa
                </button>
            </div>
        </div>`;

        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    _seleccionarCita(citaId, proveedor) {
        this.iniciarNueva(false, citaId, proveedor);
    },

    async iniciarNueva(ciego, citaId = null, proveedor = null) {
        try {
            const res = await window.api.post('/recepciones', { modo_ciego: ciego ? 1 : 0, cita_id: citaId });
            this.currentRecepcion = res.data;
            document.getElementById('recepcion-setup').style.display = 'none';
            document.getElementById('recepcion-active').style.display = 'block';
            document.getElementById('active-recepcion-num').innerText = res.data.numero_recepcion || 'RC-' + res.data.id;
            document.getElementById('active-recepcion-meta').innerText = ciego
                ? 'Modo: Ciego'
                : (proveedor ? `Cita: ${proveedor}` : 'Sin cita vinculada');
            this._localItems = [];
            this.renderResumen();
        } catch (e) { window.showToast(e.message, 'error'); }
    },

    iniciarDesdeCita(id) {
        this.iniciarNueva(false, id);
    },

    /* ── Búsqueda inteligente de productos ──────────────────────────────── */
    _scanTimer: null,
    _scanDebounce() {
        clearTimeout(this._scanTimer);
        this._scanTimer = setTimeout(() => this.buscarProducto(), 350);
    },

    async buscarProducto() {
        const query = (document.getElementById('recep-scan')?.value || '').trim();
        const resultsEl = document.getElementById('recep-search-results');
        const selEl = document.getElementById('recep-producto-sel');
        if (!resultsEl) return;

        if (query.length < 2) {
            resultsEl.style.display = 'none';
            return;
        }

        resultsEl.style.display = 'block';
        resultsEl.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';

        try {
            const res = await window.api.get('/param/productos/buscar?q=' + encodeURIComponent(query));
            const productos = res.data || [];

            if (!productos.length) {
                resultsEl.innerHTML = '<div style="padding:16px; text-align:center; color:#94a3b8; font-size:0.85rem;">Sin coincidencias para "' + query + '"</div>';
                return;
            }

            resultsEl.innerHTML = productos.map(p => {
                const eans = (p.eans || []).map(e => `<code style="background:#eff6ff; color:#3b82f6; padding:1px 5px; border-radius:4px; font-size:0.7rem;">${e.codigo_ean}</code>`).join(' ');
                return `
                <div style="padding:10px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background 0.1s;"
                     onmouseover="this.style.background='#f0f9ff'" onmouseout="this.style.background=''"
                     onclick="window.Recepcion._seleccionarProducto(${p.id}, '${p.nombre.replace(/'/g, "\\'")}', '${p.codigo_interno}', ${!!p.controla_vencimiento})">
                    <div style="font-weight:700; color:#0f172a; font-size:0.88rem;">${p.nombre}</div>
                    <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                        <span style="font-size:0.72rem; color:#64748b;"><strong>Cód:</strong> ${p.codigo_interno}</span>
                        <span style="font-size:0.72rem; color:#64748b;">·</span>
                        <span style="font-size:0.72rem; color:#64748b;"><strong>UM:</strong> ${p.unidad_medida || 'UN'}</span>
                        ${p.marca ? `<span style="font-size:0.72rem; color:#64748b;">· ${p.marca.nombre}</span>` : ''}
                    </div>
                    ${eans ? `<div style="margin-top:4px;">${eans}</div>` : ''}
                </div>`;
            }).join('');

        } catch (e) {
            resultsEl.innerHTML = '<div style="padding:12px; color:#ef4444; font-size:0.85rem;">Error al buscar</div>';
        }
    },

    _seleccionarProducto(id, nombre, codigo, controlaVencimiento) {
        this._lastProductoId = id;
        this._lastProductoNombre = nombre;

        document.getElementById('recep-scan').value = nombre;
        document.getElementById('recep-search-results').style.display = 'none';

        const selEl = document.getElementById('recep-producto-sel');
        selEl.style.display = 'block';
        document.getElementById('recep-prod-nombre').innerText = nombre;
        document.getElementById('recep-prod-codigo').innerText = 'Código: ' + codigo;

        document.getElementById('recep-cant').focus();
        if (controlaVencimiento) document.getElementById('recep-vence').focus();
    },

    _limpiarProducto() {
        this._lastProductoId = null;
        this._lastProductoNombre = null;
        document.getElementById('recep-scan').value = '';
        document.getElementById('recep-search-results').style.display = 'none';
        document.getElementById('recep-producto-sel').style.display = 'none';
        document.getElementById('recep-scan').focus();
    },

    /* ── Agregar línea ────────────────────────────────────────────────────── */
    _localItems: [],

    async agregarLinea() {
        if (!this.currentRecepcion) return;
        if (!this._lastProductoId) return window.showToast('Primero seleccione un producto', 'error');

        const payload = {
            producto_id:      this._lastProductoId,
            cantidad_recibida:parseInt(document.getElementById('recep-cant').value) || 1,
            lote:             document.getElementById('recep-lote').value,
            fecha_vencimiento:document.getElementById('recep-vence').value || null,
            estado_mercancia: document.getElementById('recep-estado').value,
        };

        try {
            await window.api.post(`/recepciones/${this.currentRecepcion.id}/detalle`, payload);
            // Track locally for display
            this._localItems.push({
                nombre:   this._lastProductoNombre,
                cantidad: payload.cantidad_recibida,
                lote:     payload.lote || '—',
                estado:   payload.estado_mercancia,
            });
            window.showToast('Item agregado correctamente', 'success');
            this._limpiarProducto();
            document.getElementById('recep-cant').value = '1';
            document.getElementById('recep-lote').value = '';
            document.getElementById('recep-vence').value = '';
            this.renderResumen();
        } catch (e) { window.showToast(e.message, 'error'); }
    },

    renderResumen() {
        const list = document.getElementById('recep-detalles-list');
        const badge = document.getElementById('recep-count-badge');
        if (!list) return;

        if (!this._localItems?.length) {
            list.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8; font-size:0.85rem;">Sin items agregados aún.</div>';
            if (badge) badge.innerText = '0';
            return;
        }

        if (badge) badge.innerText = this._localItems.length;

        const estadoColor = { BuenEstado: '#22c55e', Averia: '#f59e0b', Cuarentena: '#ef4444' };
        list.innerHTML = this._localItems.map((item, i) => `
        <div style="display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #f1f5f9;">
            <div style="width:24px; height:24px; background:#6366f120; color:#6366f1; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700; flex-shrink:0;">${i + 1}</div>
            <div style="flex:1; min-width:0;">
                <div style="font-weight:600; font-size:0.88rem; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${item.nombre}</div>
                <div style="font-size:0.75rem; color:#64748b;">Lote: ${item.lote}</div>
            </div>
            <div style="text-align:right; flex-shrink:0;">
                <div style="font-weight:700; color:#0f172a;">${item.cantidad}</div>
                <div style="font-size:0.7rem; color:${estadoColor[item.estado] || '#64748b'};">${item.estado}</div>
            </div>
        </div>`).join('');
    },

    async confirmarFinal() {
        if (!this.currentRecepcion) return;
        if (!this._localItems?.length) return window.showToast('Agregue al menos un item antes de finalizar', 'error');
        if (!confirm('¿Seguro que desea cerrar la recepción? Se actualizará el inventario.')) return;
        try {
            await window.api.post(`/recepciones/${this.currentRecepcion.id}/confirm`);
            window.showToast('Recepción completada e inventario actualizado', 'success');
            this.currentRecepcion = null;
            this._localItems = [];
            window.goToHome?.();
        } catch (e) { window.showToast(e.message, 'error'); }
    },
};
