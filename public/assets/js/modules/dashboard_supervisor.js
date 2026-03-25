/**
 * Prooriente WMS — Dashboard Supervisor / Gerencial
 * KPIs en tiempo real: alertas, picking, vencimientos, stock crítico.
 */
window.DashboardSupervisor = {

    _timer: null,

    // ── Inicializar panel ─────────────────────────────────────────────────────
    init(containerId = 'dashboard-sup-root') {
        this._containerId = containerId;
        this.renderPanel();
        this.cargarKPIs();
        // Actualizar cada 60 segundos
        this._timer = setInterval(() => this.cargarKPIs(), 60000);
    },

    destroy() {
        if (this._timer) clearInterval(this._timer);
    },

    // ── Estructura HTML ───────────────────────────────────────────────────────
    renderPanel() {
        const container = document.getElementById(this._containerId || 'dashboard-sup-root');
        if (!container) return;

        container.innerHTML = `
        <div style="padding:0; width:100%;">
            <!-- KPI Cards -->
            <div id="kpi-grid" style="display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:20px;">
                ${this._kpiCard('kpi-alertas-vencidos','fa-skull-crossbones','#dc2626','Vencidos','—')}
                ${this._kpiCard('kpi-alertas-proximos','fa-clock','#f59e0b','Próx. Vencer','—')}
                ${this._kpiCard('kpi-alertas-agotados','fa-battery-empty','#ef4444','Agotados','—')}
                ${this._kpiCard('kpi-alertas-bajo','fa-arrow-trend-down','#f97316','Bajo Mínimo','—')}
                ${this._kpiCard('kpi-picking-pendientes','fa-hand-holding-box','#3b82f6','Pick Pendientes','—')}
                ${this._kpiCard('kpi-picking-hoy','fa-check-circle','#22c55e','Pick Completados Hoy','—')}
                ${this._kpiCard('kpi-recepciones-hoy','fa-truck-ramp-box','#8b5cf6','Recepciones Hoy','—')}
                ${this._kpiCard('kpi-despachos-hoy','fa-truck','#06b6d4','Despachos Hoy','—')}
            </div>

            <!-- Accesos rápidos -->
            <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                <h3 style="font-size:0.85rem; font-weight:700; color:#64748b; margin:0 0 12px; text-transform:uppercase; letter-spacing:0.05em;">
                    Acciones Rápidas
                </h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <button onclick="window.Reportes?.abrir()"
                        style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:0.85rem; color:#334155; cursor:pointer; text-align:center;">
                        <i class="fa-solid fa-chart-bar" style="display:block; font-size:1.4rem; color:#3b82f6; margin-bottom:6px;"></i>
                        Reportes
                    </button>
                    <button onclick="window.DashboardSupervisor.abrirAlertas()"
                        style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:0.85rem; color:#334155; cursor:pointer; text-align:center;">
                        <i class="fa-solid fa-bell" style="display:block; font-size:1.4rem; color:#f59e0b; margin-bottom:6px;"></i>
                        Alertas
                    </button>
                    <button onclick="window.Reportes?.exportar('vencimientos')"
                        style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:0.85rem; color:#334155; cursor:pointer; text-align:center;">
                        <i class="fa-solid fa-file-excel" style="display:block; font-size:1.4rem; color:#22c55e; margin-bottom:6px;"></i>
                        Vencimientos
                    </button>
                    <button onclick="window.Reportes?.exportar('agotados')"
                        style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:0.85rem; color:#334155; cursor:pointer; text-align:center;">
                        <i class="fa-solid fa-file-excel" style="display:block; font-size:1.4rem; color:#ef4444; margin-bottom:6px;"></i>
                        Agotados
                    </button>
                </div>
            </div>

            <!-- Alertas activas preview -->
            <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                <div style="padding:14px 16px; background:#fff7ed; border-bottom:1px solid #fed7aa; display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-weight:700; font-size:0.9rem; color:#c2410c;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Alertas Activas
                    </span>
                    <button onclick="window.DashboardSupervisor.generarAlertas()"
                        style="padding:5px 12px; background:#f97316; color:white; border:none; border-radius:6px; font-size:0.75rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-rotate"></i> Re-escanear
                    </button>
                </div>
                <div id="alertas-preview" style="padding:12px; max-height:250px; overflow-y:auto;">
                    <div style="text-align:center; color:#94a3b8; padding:20px;">
                        <i class="fa-solid fa-spinner fa-spin"></i> Cargando...
                    </div>
                </div>
            </div>
        </div>`;
    },

    // ── Tarjeta KPI ───────────────────────────────────────────────────────────
    _kpiCard(id, icon, color, label, value) {
        return `
        <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                <span style="font-size:0.72rem; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.04em;">${label}</span>
                <div style="width:30px; height:30px; background:${color}20; color:${color}; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:0.9rem;">
                    <i class="fa-solid ${icon}"></i>
                </div>
            </div>
            <div id="${id}" style="font-size:1.8rem; font-weight:800; color:${color}; line-height:1;">${value}</div>
        </div>`;
    },

    // ── Cargar KPIs desde API ─────────────────────────────────────────────────
    async cargarKPIs() {
        try {
            // Alertas
            const alertas = await window.api.get('/alertas');
            const res = alertas?.resumen || {};
            this._set('kpi-alertas-vencidos',  res.vencidos        ?? 0);
            this._set('kpi-alertas-proximos',  res.proximos_vencer ?? 0);
            this._set('kpi-alertas-agotados',  res.agotados        ?? 0);
            this._set('kpi-alertas-bajo',      res.bajo_minimo     ?? 0);

            // Preview de alertas
            this._renderAlertasPreview(alertas?.alertas ?? []);
        } catch (_) {}

        try {
            // Dashboard general
            const dash = await window.api.get('/dashboard');
            this._set('kpi-picking-pendientes', dash?.picking_pendientes ?? dash?.ordenes_pendientes ?? '—');
            this._set('kpi-picking-hoy',        dash?.picking_completados_hoy ?? '—');
            this._set('kpi-recepciones-hoy',    dash?.recepciones_hoy ?? '—');
            this._set('kpi-despachos-hoy',      dash?.despachos_hoy ?? '—');
        } catch (_) {}
    },

    _set(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    },

    // ── Preview de alertas ────────────────────────────────────────────────────
    _renderAlertasPreview(alertas) {
        const el = document.getElementById('alertas-preview');
        if (!el) return;

        if (!alertas || alertas.length === 0) {
            el.innerHTML = `<div style="text-align:center; color:#22c55e; padding:20px;">
                <i class="fa-solid fa-circle-check" style="font-size:1.5rem; margin-bottom:8px; display:block;"></i>
                Sin alertas activas
            </div>`;
            return;
        }

        const colorMap = {
            Vencido:       '#dc2626',
            ProximoVencer: '#f59e0b',
            Agotado:       '#ef4444',
            BajoMinimo:    '#f97316',
            SobreMaximo:   '#8b5cf6',
        };

        el.innerHTML = alertas.slice(0, 20).map(a => {
            const color = colorMap[a.tipo] || '#64748b';
            return `
            <div style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #f1f5f9;">
                <div style="width:8px; height:8px; background:${color}; border-radius:50%; flex-shrink:0;"></div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:0.82rem; font-weight:600; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escHTML(a.producto_nombre || String(a.producto_id))}</div>
                    <div style="font-size:0.72rem; color:#64748b;">${escHTML(a.tipo)} · Stock: ${escHTML(String(a.stock_actual ?? '—'))}${a.fecha_vencimiento ? ' · Vence: ' + escHTML(a.fecha_vencimiento) : ''}</div>
                </div>
                <button data-alerta-id="${parseInt(a.id)}"
                    onclick="window.DashboardSupervisor.resolverAlerta(parseInt(this.dataset.alertaId))"
                    style="padding:4px 8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:0.7rem; color:#334155; cursor:pointer; white-space:nowrap;">
                    Resolver
                </button>
            </div>`;
        }).join('');

        if (alertas.length > 20) {
            el.innerHTML += `<p style="text-align:center; color:#94a3b8; font-size:0.75rem; padding:8px;">
                ... y ${alertas.length - 20} alertas más. <a href="#" onclick="window.DashboardSupervisor.abrirAlertas(); return false;" style="color:#3b82f6;">Ver todas</a>
            </p>`;
        }
    },

    // ── Resolver alerta ───────────────────────────────────────────────────────
    async resolverAlerta(id) {
        try {
            await window.api.post(`/alertas/${id}/resolver`, {});
            window.Toast?.success('Alerta resuelta');
            this.cargarKPIs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error');
        }
    },

    // ── Re-escanear alertas ───────────────────────────────────────────────────
    async generarAlertas() {
        try {
            const r = await window.api.post('/alertas/generar', {});
            window.Toast?.success(`Escaneo completado: ${r?.alertas_procesadas ?? 0} alertas`);
            this.cargarKPIs();
        } catch (err) {
            window.Toast?.error(err.message || 'Error al generar alertas');
        }
    },

    // ── Abrir panel de alertas ────────────────────────────────────────────────
    abrirAlertas() {
        window.openAlertas?.();
    },
};
