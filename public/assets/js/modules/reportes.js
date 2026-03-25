/**
 * Prooriente WMS — Centro de Reportes
 * Todos los reportes con filtros de fecha y exportación a Excel (CSV).
 */
window.Reportes = {

    // ── Inicialización ────────────────────────────────────────────────────────
    init() {
        console.log('Reportes inicializado');
    },

    // ── Rango de fechas por defecto (último mes) ──────────────────────────────
    _defaultRange() {
        const fin = new Date();
        const ini = new Date();
        ini.setDate(ini.getDate() - 30);
        return {
            ini: ini.toISOString().substring(0, 10),
            fin: fin.toISOString().substring(0, 10),
        };
    },

    // ── Construir query string con fechas ─────────────────────────────────────
    _dateParams(ini, fin, extra = '') {
        return `?fecha_inicio=${ini}&fecha_fin=${fin}${extra}`;
    },

    // ── Abrir panel de reportes ───────────────────────────────────────────────
    abrir() {
        const { ini, fin } = this._defaultRange();
        const html = `
        <div style="padding:16px; max-width:720px; margin:0 auto;">
            <h2 style="font-size:1.2rem; font-weight:700; color:#0f172a; margin-bottom:4px;">
                <i class="fa-solid fa-chart-bar" style="color:#3b82f6;"></i> Centro de Reportes
            </h2>
            <p style="color:#64748b; font-size:0.85rem; margin-bottom:20px;">
                Filtra por rango de fecha y exporta a Excel con un clic.
            </p>

            <!-- Filtro global de fechas -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div style="flex:1; min-width:130px;">
                    <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">DESDE</label>
                    <input type="date" id="rpt-ini" value="${ini}"
                        style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem; background:white;">
                </div>
                <div style="flex:1; min-width:130px;">
                    <label style="font-size:0.75rem; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">HASTA</label>
                    <input type="date" id="rpt-fin" value="${fin}"
                        style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:0.9rem; background:white;">
                </div>
            </div>

            <!-- Grilla de reportes -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                ${this._card('fa-boxes-stacked','#3b82f6','Stock Actual','Inventario en tiempo real con días para vencer','stockActual')}
                ${this._card('fa-timeline','#8b5cf6','Kárdex','Movimientos de entrada y salida por producto','kardex')}
                ${this._card('fa-truck-ramp-box','#22c55e','Recepciones','ODC recibidas con detalle de líneas','recepciones')}
                ${this._card('fa-truck','#f59e0b','Despachos','Salidas certificadas al cliente','despachos')}
                ${this._card('fa-rotate-left','#ef4444','Devoluciones','Mercancía devuelta y motivos','devoluciones')}
                ${this._card('fa-hand-holding-box','#06b6d4','Picking','Órdenes preparadas y tiempos','picking')}
                ${this._card('fa-clipboard-list','#a855f7','Conteos','Diferencias de inventario físico','conteos')}
                ${this._card('fa-file-invoice','#64748b','Órdenes de Compra','ODC emitidas a proveedores','odc')}
                ${this._card('fa-triangle-exclamation','#f97316','Vencimientos','Productos próximos a vencer','vencimientos')}
                ${this._card('fa-battery-empty','#dc2626','Agotados / Bajo Mínimo','Productos en riesgo de desabasto','agotados')}
                ${this._card('fa-truck-field','#10b981','Evaluación Proveedores','Cumplimiento citas, ODC y novedades','evaluacionProveedores')}
                ${this._card('fa-gauge-high','#0ea5e9','Dashboard Gerencial','KPIs consolidados de operación','dashboardGerencial', 'supervisor')}
                ${this._card('fa-scroll','#6b7280','Log de Auditoría','Registro completo de cambios (Admin)','auditLog', 'admin')}
            </div>
        </div>`;

        // Render dentro del contenedor actual (view-level-1 ya está abierto por app.js)
        const root = document.getElementById('reportes-root') || document.querySelector('.view-content');
        if (root) root.innerHTML = html;
    },

    // ── Tarjeta de reporte ────────────────────────────────────────────────────
    _card(icon, color, title, desc, key, badge = null) {
        const badgeHtml = badge
            ? `<span style="font-size:0.65rem; background:${badge==='admin'?'#dc2626':'#f59e0b'}; color:white; border-radius:999px; padding:2px 7px; font-weight:700; text-transform:uppercase;">${badge}</span>`
            : '';
        return `
        <div style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:16px; cursor:pointer; transition:box-shadow 0.2s;"
            onclick="window.Reportes.ver('${key}')"
            onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)'"
            onmouseout="this.style.boxShadow='none'">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <div style="width:36px; height:36px; background:${color}20; color:${color}; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1rem; flex-shrink:0;">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; font-size:0.9rem; color:#0f172a;">${title}</div>
                    ${badgeHtml}
                </div>
            </div>
            <p style="color:#64748b; font-size:0.78rem; margin:0; line-height:1.4;">${desc}</p>
            <div style="display:flex; gap:8px; margin-top:12px;">
                <button onclick="event.stopPropagation(); window.Reportes.ver('${key}')"
                    style="flex:1; padding:6px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:0.78rem; color:#334155; cursor:pointer;">
                    <i class="fa-solid fa-eye"></i> Ver
                </button>
                <button onclick="event.stopPropagation(); window.Reportes.exportar('${key}')"
                    style="flex:1; padding:6px; background:${color}15; border:1px solid ${color}40; border-radius:6px; font-size:0.78rem; color:${color}; cursor:pointer; font-weight:600;">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </button>
            </div>
        </div>`;
    },

    // ── Obtener fechas seleccionadas ──────────────────────────────────────────
    _getFechas() {
        const ini = document.getElementById('rpt-ini')?.value || this._defaultRange().ini;
        const fin = document.getElementById('rpt-fin')?.value || this._defaultRange().fin;
        return { ini, fin };
    },

    // ── Ver reporte en modal ──────────────────────────────────────────────────
    async ver(key) {
        const { ini, fin } = this._getFechas();
        const endpoints = {
            stockActual:       `/reportes/stock`,
            kardex:            `/reportes/kardex${this._dateParams(ini, fin)}`,
            recepciones:       `/reportes/recepciones${this._dateParams(ini, fin)}`,
            despachos:         `/reportes/despachos${this._dateParams(ini, fin)}`,
            devoluciones:      `/reportes/devoluciones${this._dateParams(ini, fin)}`,
            picking:           `/reportes/picking${this._dateParams(ini, fin)}`,
            conteos:           `/reportes/conteos${this._dateParams(ini, fin)}`,
            odc:               `/reportes/odc${this._dateParams(ini, fin)}`,
            vencimientos:      `/reportes/vencimientos`,
            agotados:          `/reportes/agotados`,
            evaluacionProveedores: `/reportes/evaluacion-proveedores${this._dateParams(ini, fin)}`,
            dashboardGerencial:`/reportes/dashboard-gerencial${this._dateParams(ini, fin)}`,
            auditLog:          `/reportes/audit-log${this._dateParams(ini, fin)}`,
        };

        const url = endpoints[key];
        if (!url) return;

        try {
            const data = await window.api.get(url);
            this._mostrarTabla(key, data, ini, fin);
        } catch (err) {
            window.Toast?.error(err.message || 'Error al cargar reporte');
        }
    },

    // ── Exportar a Excel (CSV) ────────────────────────────────────────────────
    exportar(key) {
        const { ini, fin } = this._getFechas();
        const exportUrls = {
            stockActual:       `/reportes/stock?export=excel`,
            kardex:            `/reportes/kardex${this._dateParams(ini, fin, '&export=excel')}`,
            recepciones:       `/reportes/recepciones${this._dateParams(ini, fin, '&export=excel')}`,
            despachos:         `/reportes/despachos${this._dateParams(ini, fin, '&export=excel')}`,
            devoluciones:      `/reportes/devoluciones${this._dateParams(ini, fin, '&export=excel')}`,
            picking:           `/reportes/picking${this._dateParams(ini, fin, '&export=excel')}`,
            conteos:           `/reportes/conteos${this._dateParams(ini, fin, '&export=excel')}`,
            odc:               `/reportes/odc${this._dateParams(ini, fin, '&export=excel')}`,
            vencimientos:      `/reportes/vencimientos?export=excel`,
            agotados:          `/reportes/agotados?export=excel`,
            evaluacionProveedores: `/reportes/evaluacion-proveedores${this._dateParams(ini, fin, '&export=excel')}`,
            dashboardGerencial:`/reportes/dashboard-gerencial${this._dateParams(ini, fin, '&export=excel')}`,
            auditLog:          `/reportes/audit-log${this._dateParams(ini, fin, '&export=excel')}`,
        };

        const path = exportUrls[key];
        if (!path) return;

        const token = window.api?.getToken ? window.api.getToken() : localStorage.getItem('jwt_token');
        const base  = window.api?.baseUrl || '/api';
        const a     = document.createElement('a');
        a.href      = `${base}${path}`;
        a.setAttribute('download', '');

        // Fetch con token para obtener el blob
        fetch(a.href, { headers: { Authorization: `Bearer ${token}` } })
            .then(r => {
                if (!r.ok) throw new Error('Error al exportar');
                const disp = r.headers.get('Content-Disposition') || '';
                const match = disp.match(/filename="?([^"]+)"?/);
                const fname = match ? match[1] : `reporte_${key}_${ini}.csv`;
                return r.blob().then(b => ({ b, fname }));
            })
            .then(({ b, fname }) => {
                const url = URL.createObjectURL(b);
                const link = document.createElement('a');
                link.href = url;
                link.download = fname;
                link.click();
                URL.revokeObjectURL(url);
            })
            .catch(err => window.Toast?.error(err.message || 'Error al exportar'));
    },

    // ── Renderizar tabla de resultados ────────────────────────────────────────
    _mostrarTabla(key, data, ini, fin) {
        const titles = {
            stockActual:        'Stock Actual',
            kardex:             'Kárdex de Movimientos',
            recepciones:        'Recepciones',
            despachos:          'Despachos',
            devoluciones:       'Devoluciones',
            picking:            'Órdenes de Picking',
            conteos:            'Conteos de Inventario',
            odc:                'Órdenes de Compra',
            vencimientos:       'Productos por Vencer',
            agotados:           'Agotados / Bajo Mínimo',
            evaluacionProveedores: 'Evaluación de Proveedores',
            dashboardGerencial: 'Dashboard Gerencial',
            auditLog:           'Log de Auditoría',
        };

        // Normalizar datos a array
        let rows = [];
        if (Array.isArray(data))                    rows = data;
        else if (data?.data && Array.isArray(data.data)) rows = data.data;
        else if (data?.items && Array.isArray(data.items)) rows = data.items;
        else if (typeof data === 'object')          rows = [data];

        const total = rows.length;

        let tableHtml = '';
        if (total === 0) {
            tableHtml = `<div style="text-align:center; padding:40px; color:#94a3b8;">
                <i class="fa-solid fa-inbox" style="font-size:2rem; margin-bottom:10px; display:block;"></i>
                Sin registros para el período seleccionado.
            </div>`;
        } else {
            const cols = Object.keys(rows[0]);
            tableHtml = `
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                            ${cols.map(c => `<th style="padding:10px 12px; text-align:left; font-weight:700; color:#475569; white-space:nowrap;">${escHTML(c)}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.slice(0, 200).map((row, i) => `
                        <tr style="border-bottom:1px solid #f1f5f9; ${i % 2 === 1 ? 'background:#fafafa;' : ''}">
                            ${cols.map(c => `<td style="padding:8px 12px; color:#334155; white-space:nowrap;">${row[c] != null ? escHTML(String(row[c])) : '—'}</td>`).join('')}
                        </tr>`).join('')}
                    </tbody>
                </table>
                ${total > 200 ? `<p style="text-align:center; color:#94a3b8; font-size:0.8rem; padding:8px;">Mostrando 200 de ${total} registros. Exporta a Excel para ver todos.</p>` : ''}
            </div>`;
        }

        const modal = document.createElement('div');
        modal.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:flex-start; justify-content:center; padding:20px; overflow-y:auto;';
        modal.innerHTML = `
        <div style="background:white; border-radius:16px; width:100%; max-width:900px; margin:auto; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
            <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#0f172a; color:white;">
                <div>
                    <h3 style="margin:0; font-size:1rem;">${titles[key] || key}</h3>
                    <p style="margin:0; font-size:0.75rem; color:#94a3b8;">${ini} → ${fin} · ${total} registros</p>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button onclick="window.Reportes.exportar('${key}')"
                        style="padding:7px 14px; background:#22c55e; color:white; border:none; border-radius:8px; font-size:0.8rem; cursor:pointer; font-weight:600;">
                        <i class="fa-solid fa-file-excel"></i> Exportar Excel
                    </button>
                    <button onclick="this.closest('[style*=fixed]').remove()"
                        style="width:32px; height:32px; background:#374151; border:none; border-radius:8px; color:white; cursor:pointer; font-size:1rem;">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div style="padding:16px; max-height:70vh; overflow-y:auto;">
                ${tableHtml}
            </div>
        </div>`;

        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    },

    // ── Atajos directos ───────────────────────────────────────────────────────
    abrirKardex()  { this.abrir(); setTimeout(() => this.ver('kardex'), 100); },
    abrirStock()   { this.abrir(); setTimeout(() => this.ver('stockActual'), 100); },
};
