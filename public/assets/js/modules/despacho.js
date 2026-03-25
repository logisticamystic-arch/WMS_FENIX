/**
 * Prooriente WMS — Módulo de Despacho
 * Carga despachos reales desde la API y permite certificarlos y cerrarlos.
 */
window.Despacho = {

    _despachoActivo: null,
    _detalles:       [],
    _scaneados:      new Set(),

    /* ===================================================================
       CERTIFICACIÓN DE DESPACHO
    =================================================================== */
    getCertificacionHTML: function () {
        return `
        <div style="background:white; border-radius:12px; padding:25px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:620px; margin:0 auto;">
            <div style="text-align:center; margin-bottom:24px;">
                <div style="width:60px; height:60px; background:#fef2f2; color:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:1.5rem;">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <h3 style="margin:0; color:#0f172a;">Certificación de Despacho</h3>
                <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Auditoría final antes de la salida de mercancía</p>
            </div>

            <!-- Selector de despacho -->
            <div class="input-group">
                <label style="font-weight:700;">1. Seleccionar Despacho</label>
                <div style="display:flex; gap:8px;">
                    <select id="desp-active-sel" class="input-field" onchange="window.Despacho.cargarDespacho()" style="flex:1;">
                        <option value="">Cargando despachos...</option>
                    </select>
                    <button onclick="window.Despacho.loadDespachos()"
                        style="background:none; border:1px solid #e2e8f0; border-radius:8px; padding:0 14px; color:#64748b; cursor:pointer; font-size:0.85rem;">
                        <i class="fa-solid fa-rotate-right"></i>
                    </button>
                </div>
            </div>

            <!-- Detalle del despacho -->
            <div id="desp-detalle" style="display:none; margin-top:16px;">
                <div id="desp-info-header" style="background:#0f172a; border-radius:10px; padding:14px 18px; color:white; margin-bottom:16px; font-size:0.85rem;">
                    <div style="font-weight:700; font-size:1rem; margin-bottom:4px;" id="desp-info-numero">—</div>
                    <div style="color:#94a3b8;" id="desp-info-cliente">—</div>
                    <div style="color:#94a3b8; margin-top:2px;" id="desp-info-estado">—</div>
                </div>

                <!-- Líneas del despacho -->
                <div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:16px;">
                    <div style="padding:10px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.78rem; font-weight:700; color:#475569; text-transform:uppercase;">
                        Líneas del Despacho
                    </div>
                    <div id="desp-lineas-lista" style="max-height:220px; overflow-y:auto;">
                        <div style="text-align:center; padding:20px; color:#94a3b8; font-size:0.85rem;">Seleccione un despacho</div>
                    </div>
                </div>

                <!-- Escáner de certificación -->
                <div style="border-top:2px dashed #e2e8f0; padding-top:18px;">
                    <label style="font-weight:700; color:#475569; display:block; margin-bottom:8px;">2. Escanear bultos para certificar</label>
                    <div style="display:flex; gap:8px; margin-bottom:12px;">
                        <input type="text" id="cert-scan" class="input-field" placeholder="Escanee EAN o LP del bulto"
                            onkeydown="if(event.key==='Enter') window.Despacho.escanearBulto()"
                            style="flex:1;">
                        <button class="btn-primary" style="width:50px; padding:0; background:#475569;"
                            onclick="window.Despacho.escanearBulto()">
                            <i class="fa-solid fa-barcode"></i>
                        </button>
                    </div>

                    <!-- Progreso -->
                    <div id="cert-resumen" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:0.85rem;">
                            <span>Unidades certificadas:</span>
                            <strong id="cert-count" style="color:#0f172a;">0 / 0</strong>
                        </div>
                        <div style="height:10px; background:#e2e8f0; border-radius:5px; overflow:hidden;">
                            <div id="cert-progress-bar" style="width:0%; height:100%; background:#ef4444; border-radius:5px; transition:width 0.3s;"></div>
                        </div>
                        <div id="cert-estado-msg" style="font-size:0.78rem; color:#64748b; margin-top:6px; text-align:center;">
                            Escanee los bultos para registrar la certificación
                        </div>
                    </div>

                    <!-- Botón cerrar -->
                    <button id="btn-cerrar-desp" class="btn-primary"
                        style="background:#ef4444; opacity:0.5; cursor:not-allowed;"
                        disabled onclick="window.Despacho.cerrarDespacho()">
                        <i class="fa-solid fa-truck-fast"></i> Cerrar y Despachar
                    </button>
                    <p id="cert-aviso" style="font-size:0.75rem; color:#94a3b8; margin-top:6px; text-align:center;">
                        Certifique el 100% de las unidades para habilitar el cierre
                    </p>
                </div>
            </div>
        </div>`;
    },

    loadDespachos: async function () {
        const sel = document.getElementById('desp-active-sel');
        if (!sel) return;
        sel.innerHTML = '<option value="">Cargando...</option>';
        try {
            const res      = await window.api.get('/despachos?estado=Abierto&limit=50');
            const despachos = Array.isArray(res) ? res : (res.data || []);

            if (!despachos.length) {
                sel.innerHTML = '<option value="">Sin despachos pendientes</option>';
                return;
            }

            sel.innerHTML = '<option value="">Seleccione un despacho...</option>' +
                despachos.map(d => `<option value="${parseInt(d.id)}">${escHTML(d.numero_despacho)} — ${escHTML(d.cliente || d.cliente_nombre || 'Sin cliente')}</option>`).join('');
        } catch (err) {
            sel.innerHTML = '<option value="">Error al cargar despachos</option>';
        }
    },

    cargarDespacho: async function () {
        const sel = document.getElementById('desp-active-sel');
        const id  = parseInt(sel?.value || '0', 10);
        const det = document.getElementById('desp-detalle');
        if (!id) { if (det) det.style.display = 'none'; return; }

        try {
            const res = await window.api.get('/despachos/' + id);
            const d   = res.data || res;
            this._despachoActivo = d;
            this._detalles       = d.detalles || d.lineas || [];
            this._scaneados      = new Set();

            document.getElementById('desp-info-numero').textContent  = d.numero_despacho || ('DSP-' + d.id);
            document.getElementById('desp-info-cliente').textContent  = d.cliente || d.cliente_nombre || 'Sin cliente';
            document.getElementById('desp-info-estado').textContent   = 'Estado: ' + (d.estado || '—');

            // Renderizar líneas
            const listaEl = document.getElementById('desp-lineas-lista');
            if (!this._detalles.length) {
                listaEl.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8; font-size:0.85rem;">Sin líneas en este despacho.</div>';
            } else {
                listaEl.innerHTML = this._detalles.map(l => `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-bottom:1px solid #f1f5f9; font-size:0.82rem;" id="linea-desp-${parseInt(l.id)}">
                    <div>
                        <div style="font-weight:700; color:#0f172a;">${escHTML(l.producto_nombre || l.producto?.nombre || '—')}</div>
                        <div style="color:#64748b; font-size:0.75rem;">${escHTML(l.codigo_interno || l.producto?.codigo_interno || '')}</div>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-weight:700; color:#0f172a;">${l.cantidad_solicitada || l.cantidad || 0} uds.</span>
                        <div id="cert-linea-${parseInt(l.id)}" style="font-size:0.72rem; color:#94a3b8;">Pendiente</div>
                    </div>
                </div>`).join('');
            }

            this._actualizarProgreso();
            det.style.display = 'block';
        } catch (err) {
            window.showToast('Error al cargar despacho: ' + (err.message || ''), 'error');
        }
    },

    escanearBulto: function () {
        const input = document.getElementById('cert-scan');
        const codigo = (input?.value || '').trim();
        if (!codigo) return;
        if (!this._despachoActivo) { window.showToast('Primero seleccione un despacho.', 'error'); return; }

        // Buscar la línea que coincida por EAN o código interno
        const linea = this._detalles.find(l => {
            const eans = l.eans || l.producto?.eans || [];
            const codigoInterno = l.codigo_interno || l.producto?.codigo_interno || '';
            return codigoInterno === codigo || eans.some(e => e.codigo_ean === codigo);
        });

        if (!linea) {
            window.showToast(`Código '${codigo}' no corresponde a ninguna línea del despacho.`, 'error');
            input.value = '';
            return;
        }

        const lineaId = linea.id;
        if (this._scaneados.has(lineaId)) {
            window.showToast('Esta línea ya fue certificada.', 'error');
            input.value = '';
            return;
        }

        this._scaneados.add(lineaId);
        const certEl = document.getElementById('cert-linea-' + parseInt(lineaId));
        const rowEl  = document.getElementById('linea-desp-' + parseInt(lineaId));
        if (certEl) { certEl.textContent = '✓ Certificado'; certEl.style.color = '#22c55e'; }
        if (rowEl)  { rowEl.style.background = '#f0fdf4'; }

        window.showToast('Línea certificada: ' + (linea.producto_nombre || linea.producto?.nombre || codigo), 'success');
        input.value = '';
        this._actualizarProgreso();
    },

    _actualizarProgreso: function () {
        const total      = this._detalles.length;
        const certificados = this._scaneados.size;
        const pct        = total > 0 ? Math.round((certificados / total) * 100) : 0;

        const countEl = document.getElementById('cert-count');
        const barEl   = document.getElementById('cert-progress-bar');
        const msgEl   = document.getElementById('cert-estado-msg');
        const btnEl   = document.getElementById('btn-cerrar-desp');
        const avisoEl = document.getElementById('cert-aviso');

        if (countEl) countEl.textContent = `${certificados} / ${total}`;
        if (barEl) {
            barEl.style.width = pct + '%';
            barEl.style.background = pct === 100 ? '#22c55e' : (pct >= 50 ? '#f59e0b' : '#ef4444');
        }
        if (msgEl) {
            msgEl.textContent = pct === 100
                ? '✓ Certificación completa — puede cerrar el despacho'
                : `${pct}% certificado`;
            msgEl.style.color = pct === 100 ? '#22c55e' : '#64748b';
        }

        // Habilitar botón solo al 100%
        if (btnEl) {
            const completo = (pct === 100 || total === 0);
            btnEl.disabled = !completo;
            btnEl.style.opacity = completo ? '1' : '0.5';
            btnEl.style.cursor  = completo ? 'pointer' : 'not-allowed';
        }
        if (avisoEl) {
            avisoEl.style.display = pct === 100 ? 'none' : 'block';
        }
    },

    cerrarDespacho: async function () {
        if (!this._despachoActivo) { window.showToast('No hay despacho activo.', 'error'); return; }
        if (this._detalles.length > 0 && this._scaneados.size < this._detalles.length) {
            window.showToast('Certifique todas las líneas antes de cerrar.', 'error');
            return;
        }

        const numero = this._despachoActivo.numero_despacho || ('DSP-' + this._despachoActivo.id);
        if (!confirm(`¿Confirma el cierre y despacho de ${numero}? Esta acción no se puede deshacer.`)) return;

        const btnEl = document.getElementById('btn-cerrar-desp');
        if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Procesando...'; }

        try {
            await window.api.post('/despachos/' + this._despachoActivo.id + '/cerrar', {
                certificado: true,
                lineas_certificadas: Array.from(this._scaneados),
            });
            window.showToast(`Despacho ${numero} cerrado exitosamente.`, 'success');
            this._despachoActivo = null;
            this._detalles       = [];
            this._scaneados      = new Set();
            document.getElementById('desp-detalle').style.display = 'none';
            document.getElementById('desp-active-sel').value = '';
            this.loadDespachos();
        } catch (err) {
            window.showToast(err.message || 'Error al cerrar despacho.', 'error');
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fa-solid fa-truck-fast"></i> Cerrar y Despachar';
            }
        }
    },
};
