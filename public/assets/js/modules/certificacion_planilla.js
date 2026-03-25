/**
 * Prooriente WMS — Certificación por Planilla
 * Dashboard admin + flujo de certificación auxiliar
 */
window.CertificacionPlanilla = {
    _certId: null,
    _detalles: [],

    getHTML() {
        return `
        <div style="padding:12px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <div>
                    <span style="font-weight:700;color:#0f172a;font-size:1.1rem;">Certificación por Planilla</span>
                    <p style="color:#64748b;font-size:0.78rem;margin:2px 0 0;">Importar planilla, certificar producto a producto</p>
                </div>
                <div style="display:flex;gap:8px;">
                    <button onclick="window.Picking.abrirImportarPlanilla()"
                        style="padding:8px 14px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.82rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-file-arrow-up"></i> Importar Planilla
                    </button>
                    <button onclick="window.CertificacionPlanilla.init()"
                        style="padding:8px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;font-size:0.82rem;cursor:pointer;">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>
            <!-- Dashboard KPIs (Admin/Supervisor) -->
            <div id="cp-dashboard" style="margin-bottom:20px;"></div>
            <!-- Archivos importados -->
            <div id="cp-archivos" style="margin-bottom:20px;"></div>
            <!-- Panel de certificación activa -->
            <div id="cp-cert-activa" style="display:none;"></div>
        </div>`;
    },

    async init() {
        await Promise.all([
            this._loadDashboard(),
            this._loadArchivos()
        ]);
    },

    /* ── Dashboard Admin ──────────────────────────────────────────────── */
    async _loadDashboard() {
        const el = document.getElementById('cp-dashboard');
        if (!el) return;
        try {
            const data = await window.api.get('/planillas/cert/dashboard');
            const d = data?.data || data || {};
            const resumen = d.resumen || d;
            const planillas = d.planillas || [];

            el.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;">
                ${[
                    { l:'Total Planillas', v:resumen.total||0, c:'#3b82f6', i:'fa-list-check' },
                    { l:'Pendientes', v:resumen.pendientes||0, c:'#f59e0b', i:'fa-clock' },
                    { l:'En Proceso', v:resumen.en_proceso||0, c:'#6366f1', i:'fa-spinner' },
                    { l:'Completadas', v:resumen.completadas||0, c:'#22c55e', i:'fa-circle-check' },
                ].map(c => `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:14px;text-align:center;">
                    <i class="fa-solid ${c.i}" style="font-size:1rem;color:${c.c};display:block;margin-bottom:6px;"></i>
                    <div style="font-size:1.6rem;font-weight:800;color:${c.c};">${c.v}</div>
                    <div style="font-size:0.7rem;color:#64748b;margin-top:2px;">${c.l}</div>
                </div>`).join('')}
            </div>
            ${resumen.con_novedad ? `
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:1.2rem;"></i>
                <span style="font-size:0.85rem;color:#991b1b;font-weight:600;">${resumen.con_novedad} planilla(s) con novedad</span>
            </div>` : ''}
            ${planillas.length ? `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:0.9rem;">
                    Detalle por Planilla
                </div>
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:8px 12px;text-align:left;font-weight:700;color:#475569;">Planilla</th>
                        <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;">Estado</th>
                        <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;">Auxiliar</th>
                        <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;">Novedades</th>
                        <th style="padding:8px 12px;text-align:center;font-weight:700;color:#475569;">Acción</th>
                    </tr></thead>
                    <tbody>${planillas.map((p, i) => {
                        const sc = { EnProceso:'#6366f1', Completada:'#22c55e', ConNovedad:'#ef4444', Pendiente:'#f59e0b' };
                        const est = p.estado || 'Pendiente';
                        const color = sc[est] || '#64748b';
                        return `<tr style="border-bottom:1px solid #f1f5f9;${i%2?'background:#fafafa;':''}">
                            <td style="padding:8px 12px;font-weight:600;color:#0f172a;">${escHTML(p.numero_planilla||p.planilla||'')}</td>
                            <td style="padding:8px 12px;text-align:center;"><span style="font-size:0.7rem;background:${color}20;color:${color};border-radius:999px;padding:2px 8px;font-weight:700;">${est}</span></td>
                            <td style="padding:8px 12px;text-align:center;color:#475569;">${escHTML(p.auxiliar_nombre||p.auxiliar||'—')}</td>
                            <td style="padding:8px 12px;text-align:center;color:${(p.novedades||0)>0?'#ef4444':'#22c55e'};font-weight:700;">${p.novedades||0}</td>
                            <td style="padding:8px 12px;text-align:center;">
                                ${p.cert_id ? `<button onclick="window.CertificacionPlanilla._verCert(${parseInt(p.cert_id)})"
                                    style="padding:4px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;">
                                    <i class="fa-solid fa-eye"></i> Ver
                                </button>` : '—'}
                            </td>
                        </tr>`;
                    }).join('')}</tbody>
                </table>
                </div>
            </div>` : ''}`;
        } catch(e) {
            el.innerHTML = '';
        }
    },

    /* ── Archivos importados ──────────────────────────────────────────── */
    async _loadArchivos() {
        const el = document.getElementById('cp-archivos');
        if (!el) return;
        el.innerHTML = '<div style="text-align:center;padding:20px;color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
        try {
            const data = await window.api.get('/planillas');
            const archivos = data?.data || data || [];
            if (!archivos.length) {
                el.innerHTML = `
                <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:40px;text-align:center;">
                    <i class="fa-solid fa-file-import" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
                    <p style="color:#94a3b8;font-size:0.9rem;">No hay archivos de planilla importados.</p>
                    <button onclick="window.Picking.abrirImportarPlanilla()"
                        style="margin-top:12px;padding:10px 20px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.88rem;cursor:pointer;font-weight:600;">
                        <i class="fa-solid fa-file-arrow-up"></i> Importar Primer Archivo
                    </button>
                </div>`;
                return;
            }
            const sc = { Importada:'#3b82f6', EnCertificacion:'#6366f1', Certificada:'#22c55e', Anulada:'#ef4444' };
            el.innerHTML = `
            <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:700;color:#0f172a;font-size:0.9rem;">Archivos Importados</span>
                    <span style="font-size:0.75rem;color:#94a3b8;">${archivos.length} archivo(s)</span>
                </div>
                ${archivos.map(a => {
                    const color = sc[a.estado]||'#64748b';
                    return `
                    <div style="padding:14px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;">
                        <div style="width:40px;height:40px;background:#f1f5f9;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="fa-solid fa-file-csv" style="color:#6366f1;font-size:1.1rem;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:600;color:#0f172a;font-size:0.88rem;">${escHTML(a.nombre_archivo)}</div>
                            <div style="font-size:0.75rem;color:#64748b;">
                                ${a.total_lineas||0} líneas · ${a.total_planillas||0} planillas ·
                                <span style="color:${color};font-weight:600;">${a.estado}</span>
                            </div>
                        </div>
                        <button onclick="window.CertificacionPlanilla._verArchivo(${parseInt(a.id)})"
                            style="padding:7px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:0.8rem;cursor:pointer;font-weight:600;flex-shrink:0;">
                            <i class="fa-solid fa-folder-open"></i> Abrir
                        </button>
                    </div>`;
                }).join('')}
            </div>`;
        } catch(e) {
            el.innerHTML = `<div style="color:#ef4444;padding:16px;text-align:center;">${escHTML(e.message)}</div>`;
        }
    },

    /* ── Ver archivo con planillas agrupadas ──────────────────────────── */
    async _verArchivo(archivoId) {
        try {
            const data = await window.api.get(`/planillas/${archivoId}`);
            const archivo = data?.data || data || {};
            const planillas = archivo.planillas || [];
            this._mostrarPlanillasArchivo(archivo, planillas);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    _mostrarPlanillasArchivo(archivo, planillas) {
        document.getElementById('cp-planillas-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'cp-planillas-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9990;display:flex;align-items:flex-start;justify-content:center;padding:16px;overflow-y:auto;';

        const sc = { EnProceso:'#6366f1', Completada:'#22c55e', ConNovedad:'#ef4444' };
        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:650px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0 0 4px;font-size:1rem;">${escHTML(archivo.nombre_archivo||'')}</h3>
                    <p style="margin:0;font-size:0.75rem;color:#94a3b8;">${planillas.length} planilla(s) encontradas</p>
                </div>
                <button onclick="document.getElementById('cp-planillas-modal').remove()"
                    style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:16px 20px;max-height:70vh;overflow-y:auto;">
                ${planillas.length ? planillas.map(p => {
                    const certColor = sc[p.cert_estado] || '#f59e0b';
                    const certLabel = p.cert_estado || 'Pendiente';
                    return `
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:10px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <span style="font-weight:700;color:#0f172a;font-size:0.95rem;">Planilla: ${escHTML(p.numero_planilla)}</span>
                            <span style="font-size:0.7rem;background:${certColor}20;color:${certColor};border-radius:999px;padding:2px 10px;font-weight:700;">${certLabel}</span>
                        </div>
                        <div style="font-size:0.82rem;color:#475569;margin-bottom:10px;">
                            ${p.total_productos||0} productos · ${p.total_lineas||0} líneas
                        </div>
                        <div style="display:flex;gap:8px;">
                            ${!p.cert_id ? `
                            <button onclick="window.CertificacionPlanilla._iniciarCert(${parseInt(archivo.id||archivo.archivo_id)}, '${escHTML(p.numero_planilla)}')"
                                style="flex:1;padding:8px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.82rem;cursor:pointer;font-weight:600;">
                                <i class="fa-solid fa-play"></i> Iniciar Certificación
                            </button>` : `
                            <button onclick="window.CertificacionPlanilla._abrirCert(${parseInt(p.cert_id)})"
                                style="flex:1;padding:8px;background:#0f172a;color:white;border:none;border-radius:8px;font-size:0.82rem;cursor:pointer;font-weight:600;">
                                <i class="fa-solid fa-clipboard-check"></i> ${p.cert_estado === 'EnProceso' ? 'Continuar' : 'Ver Detalle'}
                            </button>`}
                        </div>
                    </div>`;
                }).join('') : '<div style="text-align:center;padding:30px;color:#94a3b8;">Sin planillas</div>'}
            </div>
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    /* ── Iniciar certificación ────────────────────────────────────────── */
    async _iniciarCert(archivoId, numeroPlanilla) {
        try {
            window.showToast('Iniciando certificación...', 'info');
            const data = await window.api.post('/planillas/cert/iniciar', {
                archivo_id: archivoId,
                numero_planilla: numeroPlanilla
            });
            const cert = data?.data || data;
            window.showToast('Certificación iniciada', 'success');
            document.getElementById('cp-planillas-modal')?.remove();
            this._abrirCert(cert.id || cert.cert_id);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* ── Abrir certificación para contar ──────────────────────────────── */
    async _abrirCert(certId) {
        try {
            const data = await window.api.get(`/planillas/cert/${certId}`);
            const cert = data?.data || data;
            this._certId = certId;
            this._detalles = cert.detalles || [];
            this._renderCertificacion(cert);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    _verCert(certId) { this._abrirCert(certId); },

    _renderCertificacion(cert) {
        document.getElementById('cp-cert-modal')?.remove();
        const modal = document.createElement('div');
        modal.id = 'cp-cert-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9995;display:flex;align-items:flex-start;justify-content:center;padding:10px;overflow-y:auto;';

        const esProc = cert.estado === 'EnProceso';
        const detalles = cert.detalles || [];
        const total = detalles.length;
        const certificados = detalles.filter(d => d.cantidad_certificada > 0).length;
        const correctos = detalles.filter(d => d.es_correcto).length;
        const pct = total > 0 ? Math.round((certificados / total) * 100) : 0;

        modal.innerHTML = `
        <div style="background:white;border-radius:16px;width:100%;max-width:600px;margin:auto;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px;background:#0f172a;color:white;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 style="margin:0 0 4px;font-size:1rem;">Planilla: ${escHTML(cert.numero_planilla||'')}</h3>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <span style="font-size:0.72rem;color:#94a3b8;">Estado: ${cert.estado}</span>
                            <span style="font-size:0.72rem;color:#94a3b8;">${certificados}/${total} productos</span>
                        </div>
                    </div>
                    <button onclick="document.getElementById('cp-cert-modal').remove()"
                        style="width:32px;height:32px;background:#374151;border:none;border-radius:8px;color:white;cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div style="margin-top:10px;height:6px;background:#374151;border-radius:3px;overflow:hidden;">
                    <div style="height:100%;width:${pct}%;background:${pct===100?'#22c55e':'#6366f1'};border-radius:3px;transition:width .3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:#94a3b8;margin-top:4px;">
                    <span>Progreso: ${pct}%</span>
                    <span>Correctos: ${correctos}/${total}</span>
                </div>
            </div>
            <div style="padding:16px 20px;max-height:55vh;overflow-y:auto;">
                ${detalles.map((d, i) => {
                    const done = d.cantidad_certificada > 0;
                    const ok = d.es_correcto;
                    const borderColor = done ? (ok ? '#22c55e' : '#ef4444') : '#e2e8f0';
                    const bgColor = done ? (ok ? '#f0fdf4' : '#fef2f2') : '#ffffff';
                    const esperada = d.cantidad_esperada != null ? d.cantidad_esperada : null;
                    return `
                    <div id="cp-det-${parseInt(d.id)}" style="background:${bgColor};border:1px solid ${borderColor};border-radius:10px;padding:12px;margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:700;color:#0f172a;font-size:0.88rem;">${escHTML(d.producto_nombre||d.producto_codigo||'')}</div>
                                ${d.producto_codigo ? `<div style="font-size:0.72rem;color:#64748b;">${escHTML(d.producto_codigo)}</div>` : ''}
                            </div>
                            ${done ? `
                            <div style="text-align:right;">
                                <span style="font-size:0.72rem;background:${ok?'#dcfce7':'#fee2e2'};color:${ok?'#166534':'#991b1b'};border-radius:999px;padding:2px 10px;font-weight:700;">
                                    ${ok ? 'CORRECTO' : 'NOVEDAD'}
                                </span>
                                <div style="font-size:0.78rem;color:#475569;margin-top:4px;">Contó: ${d.cantidad_certificada}</div>
                                ${esperada != null ? `<div style="font-size:0.72rem;color:#94a3b8;">Esperado: ${esperada}</div>` : ''}
                            </div>` : `
                            <div style="font-size:0.72rem;color:#f59e0b;font-weight:700;">PENDIENTE</div>`}
                        </div>
                        ${esProc && !done ? `
                        <div style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                            <input type="number" id="cp-cant-${parseInt(d.id)}" placeholder="Cantidad contada" min="0" step="1"
                                style="flex:1;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:0.9rem;box-sizing:border-box;"
                                onkeydown="if(event.key==='Enter')window.CertificacionPlanilla._registrarLinea(${parseInt(d.id)})">
                            <button onclick="window.CertificacionPlanilla._registrarLinea(${parseInt(d.id)})"
                                style="padding:8px 14px;background:#6366f1;color:white;border:none;border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;flex-shrink:0;">
                                <i class="fa-solid fa-check"></i> Certificar
                            </button>
                        </div>` : ''}
                    </div>`;
                }).join('')}
            </div>
            ${esProc ? `
            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;">
                <button onclick="window.CertificacionPlanilla._finalizarCert(${parseInt(cert.id)})"
                    style="width:100%;padding:12px;background:#22c55e;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:pointer;font-weight:700;"
                    ${certificados < total ? 'disabled style="width:100%;padding:12px;background:#94a3b8;color:white;border:none;border-radius:10px;font-size:0.92rem;cursor:not-allowed;font-weight:700;"' : ''}>
                    <i class="fa-solid fa-flag-checkered"></i> Finalizar Certificación ${certificados < total ? `(${total - certificados} pendientes)` : ''}
                </button>
            </div>` : ''}
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);

        // Focus first pending input
        if (esProc) {
            const firstPending = detalles.find(d => !(d.cantidad_certificada > 0));
            if (firstPending) {
                setTimeout(() => document.getElementById(`cp-cant-${firstPending.id}`)?.focus(), 200);
            }
        }
    },

    /* ── Registrar línea de certificación ─────────────────────────────── */
    async _registrarLinea(detalleId) {
        const input = document.getElementById(`cp-cant-${detalleId}`);
        if (!input) return;
        const cantidad = parseFloat(input.value);
        if (isNaN(cantidad) || cantidad < 0) return window.showToast('Ingrese una cantidad válida', 'error');

        try {
            const data = await window.api.post(`/planillas/cert/${this._certId}/linea`, {
                detalle_id: detalleId,
                cantidad_certificada: cantidad
            });
            const result = data?.data || data;
            const esOk = result.es_correcto || result.correcto;

            // Update UI inline
            const detEl = document.getElementById(`cp-det-${detalleId}`);
            if (detEl) {
                detEl.style.background = esOk ? '#f0fdf4' : '#fef2f2';
                detEl.style.borderColor = esOk ? '#22c55e' : '#ef4444';
            }

            if (esOk) {
                window.showToast('CORRECTO - Cantidad coincide', 'success');
            } else {
                window.showToast('NOVEDAD - La cantidad NO coincide. Debe recontar.', 'error');
            }

            // Reload cert to refresh UI
            await this._abrirCert(this._certId);
        } catch(e) { window.showToast(e.message, 'error'); }
    },

    /* ── Finalizar certificación ──────────────────────────────────────── */
    async _finalizarCert(certId) {
        if (!confirm('¿Finalizar esta certificación?')) return;
        try {
            await window.api.post(`/planillas/cert/${certId}/finalizar`, {});
            window.showToast('Certificación finalizada', 'success');
            document.getElementById('cp-cert-modal')?.remove();
            document.getElementById('cp-planillas-modal')?.remove();
            this.init();
        } catch(e) { window.showToast(e.message, 'error'); }
    },
};
