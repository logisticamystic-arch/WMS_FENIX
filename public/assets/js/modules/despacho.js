/**
 * Prooriente WMS — Módulo de Despacho
 * Gestión completa: crear → certificar → cerrar → reportar
 */
window.Despacho = {

    _despachoActivo: null,
    _detalles:       [],
    _scaneados:      new Set(),

    /* ===================================================================
       GESTIÓN DE DESPACHOS — Vista 360°
    =================================================================== */
    getGestionHTML() {
        return `
        <div style="padding:12px; max-width:1100px; margin:0 auto;">
            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h3 style="margin:0;color:#0f172a;font-size:1.05rem;font-weight:800;">
                        <i class="fa-solid fa-truck-fast" style="color:#06b6d4;margin-right:8px;"></i>Gestión de Despachos
                    </h3>
                    <p style="color:#64748b;font-size:0.78rem;margin:3px 0 0;">Control de salida de mercancía</p>
                </div>
                <div style="display:flex;gap:8px;">
                    <button onclick="window.Despacho.initGestion()"
                        style="padding:7px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem;cursor:pointer;">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                    <button onclick="window.Despacho.abrirCrear()"
                        style="padding:8px 16px;background:#06b6d4;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:700;">
                        <i class="fa-solid fa-plus"></i> Nuevo Despacho
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div id="desp-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:18px;"></div>

            <!-- Filtros -->
            <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
                <select id="desp-f-estado" onchange="window.Despacho.loadLista()"
                    style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;min-width:140px;">
                    <option value="">Todos los estados</option>
                    <option value="Preparando">Preparando</option>
                    <option value="Certificado">Certificado</option>
                    <option value="Despachado">Despachado</option>
                </select>
                <input type="date" id="desp-f-ini"
                    style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;"
                    onchange="window.Despacho.loadLista()">
                <input type="date" id="desp-f-fin"
                    style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;"
                    onchange="window.Despacho.loadLista()">
                <input type="text" id="desp-f-buscar" placeholder="Buscar N° o cliente..."
                    oninput="clearTimeout(window._despT);window._despT=setTimeout(()=>window.Despacho.loadLista(),400)"
                    style="flex:1;min-width:160px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;">
            </div>

            <!-- Lista -->
            <div id="desp-lista">
                <div style="text-align:center;padding:40px;color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:1.5rem;"></i>
                </div>
            </div>
        </div>`;
    },

    async initGestion() {
        // Set default date range (today)
        const hoy = new Date().toISOString().slice(0, 10);
        const ini = document.getElementById('desp-f-ini');
        const fin = document.getElementById('desp-f-fin');
        if (ini && !ini.value) ini.value = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);
        if (fin && !fin.value) fin.value = hoy;
        await Promise.all([this._loadKPIs(), this.loadLista()]);
    },

    async _loadKPIs() {
        const el = document.getElementById('desp-kpis');
        if (!el) return;
        try {
            const hoy = new Date().toISOString().slice(0, 10);
            const res = await window.api.get(`/despachos?ini=${hoy}&fin=${hoy}`);
            const todos = res.data || res || [];
            const prep  = todos.filter(d => d.estado === 'Preparando').length;
            const cert  = todos.filter(d => d.estado === 'Certificado').length;
            const desp  = todos.filter(d => d.estado === 'Despachado').length;
            const bultos = todos.reduce((s, d) => s + (parseInt(d.total_bultos) || 0), 0);
            el.innerHTML = [
                { l:'Preparando', v:prep,  c:'#f59e0b', i:'fa-boxes-packing' },
                { l:'Certificados', v:cert, c:'#3b82f6', i:'fa-clipboard-check' },
                { l:'Despachados hoy', v:desp, c:'#22c55e', i:'fa-truck-fast' },
                { l:'Bultos hoy', v:bultos, c:'#06b6d4', i:'fa-cube' },
            ].map(c => `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:10px;padding:14px;text-align:center;">
                <i class="fa-solid ${c.i}" style="color:${c.c};font-size:1rem;display:block;margin-bottom:6px;"></i>
                <div style="font-size:1.6rem;font-weight:800;color:${c.c};">${c.v}</div>
                <div style="font-size:0.7rem;color:#64748b;margin-top:2px;">${c.l}</div>
            </div>`).join('');
        } catch(e) { el.innerHTML = ''; }
    },

    async loadLista() {
        const box = document.getElementById('desp-lista');
        if (!box) return;
        box.innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';

        const estado  = document.getElementById('desp-f-estado')?.value || '';
        const ini     = document.getElementById('desp-f-ini')?.value || '';
        const fin     = document.getElementById('desp-f-fin')?.value || '';
        const buscar  = document.getElementById('desp-f-buscar')?.value?.trim() || '';

        try {
            let url = '/despachos?limit=100';
            if (estado) url += `&estado=${encodeURIComponent(estado)}`;
            if (ini)    url += `&ini=${ini}`;
            if (fin)    url += `&fin=${fin}`;

            const res = await window.api.get(url);
            let lista = res.data || res || [];
            if (buscar) {
                const q = buscar.toLowerCase();
                lista = lista.filter(d =>
                    (d.numero_despacho || '').toLowerCase().includes(q) ||
                    (d.cliente || '').toLowerCase().includes(q)
                );
            }

            if (!lista.length) {
                box.innerHTML = `<div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:40px;text-align:center;color:#94a3b8;">
                    <i class="fa-solid fa-truck" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#cbd5e1;"></i>
                    No hay despachos con los filtros aplicados.</div>`;
                return;
            }

            const sc = { Preparando:'#f59e0b', Certificado:'#3b82f6', Despachado:'#22c55e' };
            box.innerHTML = `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#475569;font-size:0.75rem;font-weight:700;">
                        <th style="padding:10px 12px;text-align:left;">N° Despacho</th>
                        <th style="padding:10px 12px;text-align:left;">Cliente</th>
                        <th style="padding:10px 12px;text-align:left;">Ruta</th>
                        <th style="padding:10px 12px;text-align:center;">Bultos</th>
                        <th style="padding:10px 12px;text-align:center;">Estado</th>
                        <th style="padding:10px 12px;text-align:center;">Fecha</th>
                        <th style="padding:10px 12px;text-align:center;">Acciones</th>
                    </tr></thead>
                    <tbody>${lista.map((d, i) => {
                        const color = sc[d.estado] || '#64748b';
                        return `<tr style="border-bottom:1px solid #f1f5f9;${i%2?'background:#fafafa;':''}">
                            <td style="padding:10px 12px;font-weight:700;color:#0f172a;">${escHTML(d.numero_despacho)}</td>
                            <td style="padding:10px 12px;color:#475569;">${escHTML(d.cliente || '—')}</td>
                            <td style="padding:10px 12px;color:#64748b;">${escHTML(d.ruta || '—')}</td>
                            <td style="padding:10px 12px;text-align:center;font-weight:600;">${d.total_bultos || 0}</td>
                            <td style="padding:10px 12px;text-align:center;">
                                <span style="font-size:0.72rem;background:${color}20;color:${color};border-radius:99px;padding:3px 10px;font-weight:700;">${d.estado}</span>
                            </td>
                            <td style="padding:10px 12px;text-align:center;color:#64748b;font-size:0.8rem;">${d.fecha_movimiento || '—'}</td>
                            <td style="padding:10px 12px;text-align:center;">
                                <div style="display:flex;gap:4px;justify-content:center;">
                                    ${d.estado !== 'Despachado' ? `
                                    <button onclick="window.Despacho.abrirCertificar(${parseInt(d.id)})"
                                        style="padding:5px 10px;background:#3b82f6;color:white;border:none;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;">
                                        <i class="fa-solid fa-clipboard-check"></i> Certificar
                                    </button>` : ''}
                                    <button onclick="window.Despacho.verReporte(${parseInt(d.id)},'${escHTML(d.numero_despacho)}')"
                                        style="padding:5px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:0.75rem;cursor:pointer;">
                                        <i class="fa-solid fa-file-csv"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
            </div>`;
        } catch(e) {
            box.innerHTML = `<div style="color:#ef4444;padding:20px;text-align:center;background:white;border-radius:12px;">${escHTML(e.message)}</div>`;
        }
    },

    /* ===================================================================
       CREAR DESPACHO
    =================================================================== */
    abrirCrear() {
        document.getElementById('desp-crear-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'desp-crear-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9990;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:520px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-plus"></i> Nuevo Despacho</h3>
                <button onclick="document.getElementById('desp-crear-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div style="grid-column:span 2;">
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Cliente *</label>
                        <input type="text" id="nc-cliente" placeholder="Nombre del cliente"
                            style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Ruta</label>
                        <select id="nc-ruta" style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;box-sizing:border-box;">
                            <option value="">Sin ruta</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Fecha</label>
                        <input type="date" id="nc-fecha" value="${new Date().toISOString().slice(0,10)}"
                            style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Auxiliar</label>
                        <select id="nc-auxiliar" style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.84rem;background:white;box-sizing:border-box;">
                            <option value="">Sin asignar</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Total Bultos</label>
                        <input type="number" id="nc-bultos" value="0" min="0"
                            style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="font-size:0.72rem;font-weight:700;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;">Peso Total (kg)</label>
                        <input type="number" id="nc-peso" value="0" min="0" step="0.1"
                            style="width:100%;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.88rem;box-sizing:border-box;">
                    </div>
                </div>
                <button onclick="window.Despacho._guardarNuevo()"
                    style="width:100%;padding:12px;background:#06b6d4;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:pointer;font-weight:700;">
                    <i class="fa-solid fa-truck-fast"></i> Crear Despacho
                </button>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
        // Load rutas and personal
        Promise.all([
            window.api.get('/param/rutas').catch(() => ({ data: [] })),
            window.api.get('/param/personal').catch(() => ({ data: [] })),
        ]).then(([rutasRes, persRes]) => {
            const rutaSel = document.getElementById('nc-ruta');
            const persSel = document.getElementById('nc-auxiliar');
            if (rutaSel) (rutasRes.data || []).forEach(r => {
                rutaSel.innerHTML += `<option value="${escHTML(r.nombre)}">${escHTML(r.nombre)}</option>`;
            });
            if (persSel) (persRes.data || persRes || []).forEach(p => {
                persSel.innerHTML += `<option value="${parseInt(p.id)}">${escHTML(p.nombre)}</option>`;
            });
        });
    },

    async _guardarNuevo() {
        const cliente = document.getElementById('nc-cliente')?.value.trim();
        if (!cliente) return window.showToast('El cliente es requerido', 'error');
        const payload = {
            cliente,
            ruta:        document.getElementById('nc-ruta')?.value || null,
            fecha:       document.getElementById('nc-fecha')?.value || null,
            auxiliar_id: parseInt(document.getElementById('nc-auxiliar')?.value) || null,
            total_bultos:parseFloat(document.getElementById('nc-bultos')?.value) || 0,
            peso_total:  parseFloat(document.getElementById('nc-peso')?.value) || 0,
        };
        try {
            const res = await window.api.post('/despachos', payload);
            const d = res.data || res;
            window.showToast(`Despacho ${d.numero_despacho} creado`, 'success');
            document.getElementById('desp-crear-modal')?.remove();
            this.loadLista();
            this._loadKPIs();
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* ===================================================================
       CERTIFICACIÓN DE DESPACHO — abrir desde gestión
    =================================================================== */
    async abrirCertificar(id) {
        try {
            const res = await window.api.get('/despachos/' + id);
            const d   = res.data || res;
            this._despachoActivo = d;
            this._detalles       = d.detalles || d.lineas || d.certificaciones || [];
            this._scaneados      = new Set();
            this._mostrarModalCert(d);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    _mostrarModalCert(d) {
        document.getElementById('desp-cert-modal')?.remove();
        const total = this._detalles.length;
        const modal = document.createElement('div');
        modal.id = 'desp-cert-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9991;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:560px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div>
                        <h3 style="margin:0;font-size:1rem;">${escHTML(d.numero_despacho)}</h3>
                        <div style="font-size:0.75rem;color:#94a3b8;">${escHTML(d.cliente || '—')} · ${escHTML(d.ruta || 'Sin ruta')} · ${d.total_bultos || 0} bultos</div>
                    </div>
                    <button onclick="document.getElementById('desp-cert-modal').remove()"
                        style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <!-- Barra de progreso -->
                <div style="height:8px;background:#374151;border-radius:4px;overflow:hidden;">
                    <div id="cert-bar" style="width:0%;height:100%;background:#22c55e;border-radius:4px;transition:width 0.3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:#94a3b8;margin-top:4px;">
                    <span id="cert-txt">0 / ${total} productos</span>
                    <span id="cert-pct">0%</span>
                </div>
            </div>
            <div style="padding:16px 20px;">
                <!-- Escáner -->
                <div style="display:flex;gap:8px;margin-bottom:14px;">
                    <input type="text" id="cert-scan" placeholder="Escanear EAN o código interno..."
                        onkeydown="if(event.key==='Enter')window.Despacho.escanearBulto()"
                        style="flex:1;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;"
                        autofocus>
                    <button onclick="window.Despacho.escanearBulto()"
                        style="padding:10px 16px;background:#3b82f6;color:white;border:none;border-radius:8px;cursor:pointer;font-size:0.9rem;">
                        <i class="fa-solid fa-barcode"></i>
                    </button>
                </div>
                <!-- Líneas -->
                <div id="cert-lineas" style="max-height:300px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:8px;">
                    ${total ? this._detalles.map(l => `
                    <div id="cert-row-${parseInt(l.id)}" style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-bottom:1px solid #f8fafc;">
                        <div>
                            <div style="font-weight:600;font-size:0.85rem;color:#0f172a;">${escHTML(l.producto_nombre || l.producto?.nombre || '—')}</div>
                            <div style="font-size:0.72rem;color:#64748b;">${escHTML(l.codigo_interno || l.producto?.codigo_interno || '')}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:700;color:#0f172a;">${l.cantidad_solicitada || l.cantidad || 0} uds</div>
                            <div id="cert-st-${parseInt(l.id)}" style="font-size:0.72rem;color:#94a3b8;">Pendiente</div>
                        </div>
                    </div>`).join('') : '<div style="text-align:center;padding:20px;color:#94a3b8;font-size:0.85rem;">Sin líneas registradas — certificación libre</div>'}
                </div>
            </div>
            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;">
                <button id="btn-cerrar-desp" onclick="window.Despacho.cerrarDespacho()"
                    style="width:100%;padding:12px;background:#94a3b8;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:not-allowed;font-weight:700;" disabled>
                    <i class="fa-solid fa-truck-fast"></i> Cerrar y Despachar
                </button>
                <p id="cert-aviso" style="font-size:0.75rem;color:#94a3b8;margin-top:6px;text-align:center;">
                    ${total ? 'Certifique el 100% para habilitar el cierre' : 'Pulse el botón para cerrar el despacho'}
                </p>
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
        if (!total) this._habilitarCierre();
    },

    getCertificacionHTML() {
        return this.getGestionHTML();
    },

    loadDespachos: async function () {
        await this.initGestion();
    },

    /* ===================================================================
       ESCANEO Y PROGRESO
    =================================================================== */
    escanearBulto() {
        const input  = document.getElementById('cert-scan');
        const codigo = (input?.value || '').trim();
        if (!codigo) return;
        if (!this._despachoActivo) { window.showToast('No hay despacho activo.', 'error'); return; }

        // Si no hay líneas, certificación libre: registrar directamente
        if (!this._detalles.length) {
            window.showToast('Código registrado: ' + codigo, 'success');
            input.value = '';
            this._habilitarCierre();
            return;
        }

        const linea = this._detalles.find(l => {
            const eans = l.eans || l.producto?.eans || [];
            const cod  = l.codigo_interno || l.producto?.codigo_interno || '';
            return cod === codigo || eans.some(e => e.codigo_ean === codigo);
        });

        if (!linea) {
            window.showToast(`'${codigo}' no corresponde a ninguna línea.`, 'error');
            input.value = ''; return;
        }

        if (this._scaneados.has(linea.id)) {
            window.showToast('Esta línea ya fue certificada.', 'error');
            input.value = ''; return;
        }

        this._scaneados.add(linea.id);
        const row = document.getElementById('cert-row-' + parseInt(linea.id));
        const st  = document.getElementById('cert-st-' + parseInt(linea.id));
        if (row) row.style.background = '#f0fdf4';
        if (st)  { st.textContent = '✓ Certificado'; st.style.color = '#22c55e'; }
        window.showToast('✓ ' + (linea.producto_nombre || linea.producto?.nombre || codigo), 'success');
        input.value = '';
        this._actualizarProgreso();
    },

    _actualizarProgreso() {
        const total = this._detalles.length;
        const cert  = this._scaneados.size;
        const pct   = total > 0 ? Math.round((cert / total) * 100) : 100;

        const bar = document.getElementById('cert-bar');
        const txt = document.getElementById('cert-txt');
        const pctEl = document.getElementById('cert-pct');
        if (bar) bar.style.width = pct + '%';
        if (txt) txt.textContent = `${cert} / ${total} productos`;
        if (pctEl) pctEl.textContent = pct + '%';

        if (pct === 100) this._habilitarCierre();
    },

    _habilitarCierre() {
        const btn    = document.getElementById('btn-cerrar-desp');
        const aviso  = document.getElementById('cert-aviso');
        if (btn) {
            btn.disabled = false;
            btn.style.background = '#22c55e';
            btn.style.cursor = 'pointer';
        }
        if (aviso) aviso.style.display = 'none';
    },

    /* ===================================================================
       CERRAR DESPACHO
    =================================================================== */
    async cerrarDespacho() {
        if (!this._despachoActivo) { window.showToast('No hay despacho activo.', 'error'); return; }
        const num = this._despachoActivo.numero_despacho || ('DSP-' + this._despachoActivo.id);
        if (!confirm(`¿Confirma el cierre de "${num}"? El despacho quedará marcado como DESPACHADO.`)) return;

        const btn = document.getElementById('btn-cerrar-desp');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Procesando...'; }

        try {
            await window.api.post('/despachos/' + this._despachoActivo.id + '/cerrar', {
                certificado: true,
                lineas_certificadas: Array.from(this._scaneados),
            });
            window.showToast(`Despacho ${num} cerrado exitosamente.`, 'success');
            document.getElementById('desp-cert-modal')?.remove();
            this._despachoActivo = null;
            this._detalles = [];
            this._scaneados = new Set();
            this.loadLista();
            this._loadKPIs();
        } catch(e) {
            window.showToast(e.message || 'Error al cerrar.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-truck-fast"></i> Cerrar y Despachar'; }
        }
    },

    /* ===================================================================
       REPORTE
    =================================================================== */
    verReporte(id, numero) {
        const token = localStorage.getItem('jwt_token') || localStorage.getItem('token');
        const base  = window.api?.baseUrl || '/api';
        window.open(`${base}/despachos/${id}/reporte?token=${token}`, '_blank');
    },
};
