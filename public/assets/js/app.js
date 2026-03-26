/**
 * Prooriente WMS - Main App Logic
 */

document.addEventListener('DOMContentLoaded', () => {

    // 1. Service Worker Registration
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('SW Registrado:', reg.scope))
                .catch(err => console.error('Error registrando SW:', err));
        });
    }

    // 2. App Initialization after Login
    window.addEventListener('app:ready', () => {
        initDashboard();
    });

    // 3. Logout handling
    document.getElementById('btn-logout').addEventListener('click', () => {
        window.api.clearAuth();
        window.location.reload();
    });

    // ----- Dynamic Module Render -----
    function initDashboard() {
        const permsStr = localStorage.getItem('user_permissions');
        let permissions = [];
        try {
            permissions = permsStr ? JSON.parse(permsStr) : [];
        } catch (e) {}

        const container = document.getElementById('modules-container');
        if (!container) return;
        container.innerHTML = ''; 

        const availableModules = [
            { id: 'recepcion', name: 'Recepción', icon: 'fa-truck-ramp-box', reqPerm: 'recepcion', colorClass: 'color-inbound' },
            { id: 'almacenamiento', name: 'Almacenar', icon: 'fa-dolly', reqPerm: 'almacenamiento', colorClass: 'color-almacen' },
            { id: 'inventario', name: 'Conteo & Inv', icon: 'fa-boxes-packing', reqPerm: 'inventario', colorClass: 'color-inventory' },
            { id: 'picking', name: 'Picking', icon: 'fa-cart-flatbed', reqPerm: 'picking', colorClass: 'color-picking' },
            { id: 'despacho', name: 'Despacho', icon: 'fa-truck-fast', reqPerm: 'despacho', colorClass: 'color-outbound' },
            { id: 'devoluciones', name: 'Devoluciones', icon: 'fa-rotate-left', reqPerm: 'recepcion', colorClass: 'color-return' },
            { id: 'maestros', name: 'Maestros', icon: 'fa-database', reqPerm: 'admin', colorClass: 'color-admin' },
            { id: 'reportes', name: 'Reportes', icon: 'fa-chart-bar', reqPerm: 'reportes', colorClass: 'color-inventory' },
            { id: 'dashboard_supervisor', name: 'Dashboard', icon: 'fa-gauge-high', reqPerm: 'supervisor', colorClass: 'color-picking' }
        ];

        let added = 0;

        // Admin override check (if permissions list contains *.* or user role is admin)
        const userDataStr = localStorage.getItem('user_data');
        const user = userDataStr ? JSON.parse(userDataStr) : null;
        const isAdmin = permissions.some(p => p === '*.*' || (p.modulo === '*' && p.accion === '*')) || (user && user.rol.toLowerCase() === 'admin');

        availableModules.forEach(mod => {
            const hasAccess = isAdmin || permissions.includes(`${mod.reqPerm}.ver`);
            
            if (hasAccess) {
                added++;
                const card = document.createElement('div');
                card.className = 'module-card card-main fade-in';
                card.style.animationDelay = `${added * 0.05}s`;
                card.innerHTML = `
                    <div class="module-icon-wrap ${mod.colorClass}">
                        <i class="fa-solid ${mod.icon}"></i>
                    </div>
                    <h3 class="module-title">${mod.name}</h3>
                `;
                card.addEventListener('click', () => {
                    if (navigator.vibrate) navigator.vibrate(20);
                    openView(mod.id, mod.name);
                });
                container.appendChild(card);
            }
        });

        if (added === 0) {
            container.innerHTML = `<p style="color: #64748b; text-align: center; grid-column: span 2;">No tienes permisos asignados.</p>`;
        }
    }

    // Wiring Bottom Nav
    window.goToHome = function() {
        if(window.closeView) {
            window.closeView('view-level-2');
            setTimeout(() => { window.closeView('view-level-1'); }, 100);
        }
        document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
        if(document.querySelector('.nav-item')) document.querySelector('.nav-item').classList.add('active'); 
        if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
    };

    window.openAlertas = function() {
        const items = document.querySelectorAll('.nav-item');
        items.forEach(el => el.classList.remove('active'));
        if(items[1]) items[1].classList.add('active');
        
        openView('alertas', 'Centro de Notificaciones');
    };

    window.openAjustes = function() {
        const items = document.querySelectorAll('.nav-item');
        items.forEach(el => el.classList.remove('active'));
        if(items[2]) items[2].classList.add('active');
        
        openView('ajustes', 'Configuración del Sistema');
    };

    // ----- App Shell View Navigation System -----
    const viewContainer = document.getElementById('view-container');
    
    // Submenu Configurations
    const subMenus = {
        'maestros': [
            { id: 'empresas', title: 'Empresas', icon: 'fa-building', colorClass: 'color-admin' },
            { id: 'sucursales', title: 'Sucursales', icon: 'fa-warehouse', colorClass: 'color-picking' },
            { id: 'categorias', title: 'Categorías', icon: 'fa-layer-group', colorClass: 'color-almacen' },
            { id: 'marcas', title: 'Marcas', icon: 'fa-tags', colorClass: 'color-inventory' },
            { id: 'productos', title: 'Productos', icon: 'fa-box', colorClass: 'color-inbound' },
            { id: 'personal', title: 'Personal', icon: 'fa-users', colorClass: 'color-outbound' },
            { id: 'clientes', title: 'Clientes', icon: 'fa-users-rectangle', colorClass: 'color-inbound' },
            { id: 'ubicaciones', title: 'Ubicaciones', icon: 'fa-map-location-dot', colorClass: 'color-almacen' },
            { id: 'proveedores', title: 'Proveedores', icon: 'fa-truck-field', colorClass: 'color-picking' },
            { id: 'rutas', title: 'Rutas', icon: 'fa-route', colorClass: 'color-inbound' },
            { id: 'sistema_migraciones', title: 'BD Migraciones', icon: 'fa-database', colorClass: 'color-admin' }
        ],
        'recepcion': [
            { id: 'citas', title: 'Gestión de Citas', icon: 'fa-calendar-check', colorClass: 'color-inbound' },
            { id: 'odc', title: 'Orden de Compra', icon: 'fa-file-invoice', colorClass: 'color-admin' },
            { id: 'recepcion_nueva', title: 'Nueva Recepción', icon: 'fa-boxes-packing', colorClass: 'color-inbound' }
        ],
        'almacenamiento': [
            { id: 'putaway', title: 'Putaway (Acomodo)', icon: 'fa-pallet', colorClass: 'color-almacen' },
            { id: 'traslado', title: 'Traslado Interno', icon: 'fa-people-carry-box', colorClass: 'color-almacen' },
            { id: 'mapa_bodega', title: 'Mapa 3D Bodega', icon: 'fa-cubes', colorClass: 'color-inventory' }
        ],
        'picking': [
            { id: 'picking_ordenes', title: 'Gestionar Picking', icon: 'fa-cart-flatbed', colorClass: 'color-picking' },
            { id: 'picking_rutas', title: 'Rutas FEFO', icon: 'fa-route', colorClass: 'color-inbound' },
            { id: 'picking_asignacion', title: 'Asignación', icon: 'fa-users-gear', colorClass: 'color-almacen' },
            { id: 'picking_consolidado', title: 'Picking Consolidado', icon: 'fa-layer-group', colorClass: 'color-picking' },
            { id: 'picking_planilla', title: 'Importar Planilla', icon: 'fa-file-arrow-up', colorClass: 'color-admin' },
            { id: 'certificacion_planilla', title: 'Certificación Planillas', icon: 'fa-clipboard-check', colorClass: 'color-outbound' },
            { id: 'picking_dashboard', title: 'Tablero de Control', icon: 'fa-gauge-high', colorClass: 'color-inventory' }
        ],
        'despacho': [
            { id: 'certificacion_consolidada', title: 'Certif. Consolidada', icon: 'fa-cubes-stacked', colorClass: 'color-outbound' },
            { id: 'certificacion_detalle', title: 'Certif. por Cliente', icon: 'fa-clipboard-user', colorClass: 'color-inventory' }
        ],
        'ajustes': [
            { id: 'permisos', title: 'Gestión de Permisos', icon: 'fa-shield-halved', colorClass: 'color-admin' },
            { id: 'mi_perfil', title: 'Mi Perfil', icon: 'fa-user-gear', colorClass: 'color-almacen' },
            { id: 'empresa_config', title: 'Datos Empresa', icon: 'fa-hotel', colorClass: 'color-picking' }
        ],
        'inventario': [
            { id: 'conteo_nuevo', title: 'Nuevo Conteo', icon: 'fa-clipboard-list', colorClass: 'color-inventory' },
            { id: 'conteos_historial', title: 'Historial Conteos', icon: 'fa-clock-rotate-left', colorClass: 'color-inventory' }
        ],
        'devoluciones': [
            { id: 'recepcion_devolucion', title: 'Nueva Devolución', icon: 'fa-rotate-left', colorClass: 'color-return' },
            { id: 'devoluciones_historial', title: 'Historial', icon: 'fa-clock-rotate-left', colorClass: 'color-inventory' }
        ],
        'alertas': [],         // Specialized render
        'reportes': [],        // Handled by Reportes module
        'dashboard_supervisor': [] // Handled by DashboardSupervisor module
    };

    window.openView = function(viewId, viewName) {
        if(!viewContainer) return;

        let contentHtml = '';

        if (viewId === 'reportes') {
            // Delegado al módulo Reportes
            contentHtml = `<div id="reportes-root"></div>`;
        } else if (viewId === 'dashboard_supervisor') {
            contentHtml = `<div id="dashboard-sup-root" style="padding:12px;"></div>`;
        } else if (subMenus[viewId] && subMenus[viewId].length > 0) {
            // Render specific Submenu Grid identically to main dashboard
            let cardsHtml = '';
            subMenus[viewId].forEach((sub, idx) => {
                cardsHtml += `
                    <div class="module-card card-sub fade-in" style="animation-delay: ${idx * 0.05}s;" onclick="openSubView('${sub.id}', '${sub.title}')">
                        <div class="module-icon-wrap ${sub.colorClass}">
                            <i class="fa-solid ${sub.icon}"></i>
                        </div>
                        <h3 class="module-title">${sub.title}</h3>
                    </div>
                `;
            });
            contentHtml = `<div class="module-grid">${cardsHtml}</div>`;
        } else if (viewId === 'alertas') {
            contentHtml = `
                <div id="alertas-panel" style="padding:10px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <span style="font-weight:700; color:#0f172a;">Alertas Activas</span>
                        <button onclick="window._loadAlertas()" style="padding:6px 12px; background:#f97316; color:white; border:none; border-radius:8px; font-size:0.8rem; cursor:pointer;">
                            <i class="fa-solid fa-rotate"></i> Re-escanear
                        </button>
                    </div>
                    <div id="alertas-list"><div style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
                </div>`;
        } else {
            // Generic construction notice
            contentHtml = `<div style="text-align:center; padding:60px 20px; color:#94a3b8;">
                <i class="fa-solid fa-person-digging" style="font-size:3rem; margin-bottom:16px; color:#cbd5e1;"></i>
                <h4>El módulo ${viewName} se está construyendo...</h4>
            </div>`;
        }

        const viewHtml = `
            <div class="view-panel active" id="view-level-1">
                <div class="view-header">
                    <button class="btn-back" onclick="closeView('view-level-1')"><i class="fa-solid fa-arrow-left"></i></button>
                    <h3>${viewName}</h3>
                </div>
                <div class="view-content">
                    ${contentHtml}
                </div>
            </div>
            <!-- Container for level 2 views (CRUDs) -->
            <div id="subview-container"></div>
        `;

        viewContainer.innerHTML = viewHtml;

        // Post-render hooks
        if (viewId === 'reportes' && window.Reportes) {
            setTimeout(() => {
                const root = document.getElementById('reportes-root');
                if (root) {
                    // Render reportes panel directly into the view
                    const { ini, fin } = window.Reportes._defaultRange();
                    root.innerHTML = window.Reportes._buildPanelHTML ? window.Reportes._buildPanelHTML(ini, fin) : '';
                    // Fallback: delegate to Reportes.abrir-like logic inline
                }
                window.Reportes.abrir();
            }, 100);
        } else if (viewId === 'dashboard_supervisor' && window.DashboardSupervisor) {
            setTimeout(() => window.DashboardSupervisor.init('dashboard-sup-root'), 100);
        } else if (viewId === 'alertas') {
            window._loadAlertas = async function() {
                const list = document.getElementById('alertas-list');
                if (!list) return;
                list.innerHTML = '<div style="text-align:center; padding:20px; color:#94a3b8;"><i class="fa-solid fa-spinner fa-spin"></i></div>';
                try {
                    const data = await window.api.get('/alertas');
                    const alertas = data?.alertas || [];
                    const res = data?.resumen || {};
                    const colorMap = { Vencido:'#dc2626', ProximoVencer:'#f59e0b', Agotado:'#ef4444', BajoMinimo:'#f97316', SobreMaximo:'#8b5cf6' };
                    if (!alertas.length) {
                        list.innerHTML = '<div style="text-align:center; padding:30px; color:#22c55e;"><i class="fa-solid fa-circle-check" style="font-size:2rem; margin-bottom:8px; display:block;"></i>Sin alertas activas</div>';
                        return;
                    }
                    const summary = `<div style="display:grid; grid-template-columns:repeat(2,1fr); gap:8px; margin-bottom:12px;">
                        ${[['Vencidos', res.vencidos||0, '#dc2626'],['Próx. Vencer', res.proximos_vencer||0,'#f59e0b'],['Agotados',res.agotados||0,'#ef4444'],['Bajo Mínimo',res.bajo_minimo||0,'#f97316']]
                        .map(([l,v,c]) => `<div style="background:white; border:1px solid #e2e8f0; border-left:3px solid ${c}; border-radius:8px; padding:10px; text-align:center;"><div style="font-size:1.4rem; font-weight:800; color:${c};">${v}</div><div style="font-size:0.72rem; color:#64748b;">${l}</div></div>`).join('')}
                    </div>`;
                    list.innerHTML = summary + alertas.map(a => {
                        const color = colorMap[a.tipo] || '#64748b';
                        return `<div style="background:white; border-radius:10px; padding:12px; border-left:3px solid ${color}; margin-bottom:8px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <strong style="color:#0f172a; font-size:0.9rem;">${a.producto_nombre || 'Producto #'+a.producto_id}</strong>
                                <span style="font-size:0.7rem; background:${color}20; color:${color}; border-radius:999px; padding:2px 8px; font-weight:700; white-space:nowrap;">${a.tipo}</span>
                            </div>
                            <p style="margin:4px 0 8px; font-size:0.8rem; color:#475569;">
                                Stock: ${a.stock_actual ?? '—'}${a.stock_minimo ? ' / Mín: '+a.stock_minimo : ''}${a.fecha_vencimiento ? ' · Vence: '+a.fecha_vencimiento : ''}${a.dias_para_vencer != null ? ' ('+a.dias_para_vencer+' días)' : ''}
                            </p>
                            <div style="display:flex; gap:8px;">
                                <button onclick="window._resolverAlerta(${a.id})" style="flex:1; padding:6px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:0.75rem; color:#166534; cursor:pointer; font-weight:600;">
                                    <i class="fa-solid fa-check"></i> Resolver
                                </button>
                                <button onclick="window._ignorarAlerta(${a.id})" style="padding:6px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; font-size:0.75rem; color:#64748b; cursor:pointer;">
                                    Ignorar
                                </button>
                            </div>
                        </div>`;
                    }).join('');
                } catch (err) {
                    list.innerHTML = `<div style="color:#ef4444; padding:20px; text-align:center;">${err.message || 'Error al cargar alertas'}</div>`;
                }
            };
            window._resolverAlerta = async (id) => {
                try { await window.api.post(`/alertas/${id}/resolver`, {}); window.Toast?.success('Resuelta'); window._loadAlertas(); } catch(e) { window.Toast?.error(e.message); }
            };
            window._ignorarAlerta = async (id) => {
                try { await window.api.post(`/alertas/${id}/ignorar`, {}); window._loadAlertas(); } catch(e) { window.Toast?.error(e.message); }
            };
            setTimeout(() => window._loadAlertas(), 200);
        }
    }

    window.closeView = function(elementId) {
        if (navigator.vibrate) navigator.vibrate(10);
        const panel = document.getElementById(elementId);
        if (panel) {
            panel.classList.remove('active');
            setTimeout(() => { panel.remove(); }, 300);
        }
    }

    // --- Level 2 View Logic (Action / CRUD level) ---
    window.openSubView = function(subId, subTitle) {
        const subviewContainer = document.getElementById('subview-container');
        if (!subviewContainer) return;

        if (navigator.vibrate) navigator.vibrate(20);

        let contentHtml = '';

        // Master parameterization routing
        if (subId === 'empresas' && window.Maestros) {
            contentHtml = window.Maestros.getEmpresasHTML();
            setTimeout(() => { window.Maestros.loadEmpresas(); }, 400); 
        } else if (subId === 'sucursales' && window.Maestros) {
            contentHtml = window.Maestros.getSucursalesHTML();
            setTimeout(() => { window.Maestros.loadSucursales(); }, 400);
        } else if (subId === 'categorias' && window.Maestros) {
            contentHtml = window.Maestros.getCategoriasHTML();
            setTimeout(() => { window.Maestros.loadCategorias(); }, 400);
        } else if (subId === 'marcas' && window.Maestros) {
            contentHtml = window.Maestros.getMarcasHTML();
            setTimeout(() => { window.Maestros.loadMarcas(); }, 400);
        } else if (subId === 'productos' && window.Maestros) {
            contentHtml = window.Maestros.getProductosHTML();
            setTimeout(() => { window.Maestros.loadProductos(); }, 400);
        } else if (subId === 'personal' && window.Maestros) {
            contentHtml = window.Maestros.getPersonalHTML();
            setTimeout(() => { window.Maestros.loadPersonal(); }, 400);
        } else if (subId === 'clientes' && window.Maestros) {
            contentHtml = window.Maestros.getClientesHTML();
            setTimeout(() => { window.Maestros.loadClientes(); }, 400);
        } else if (subId === 'ubicaciones' && window.Maestros) {
            contentHtml = window.Maestros.getUbicacionesHTML();
            setTimeout(() => { window.Maestros.loadUbicaciones(); }, 400);
        } else if (subId === 'proveedores' && window.Maestros) {
            contentHtml = window.Maestros.getProveedoresHTML();
            setTimeout(() => { window.Maestros.loadProveedores(); }, 400);
        } else if (subId === 'rutas' && window.Maestros) {
            contentHtml = window.Maestros.getRutasHTML();
            setTimeout(() => { window.Maestros.loadRutas(); }, 400);
        } else if (subId === 'sistema_migraciones') {
            contentHtml = `<div style="padding:24px; max-width:600px; margin:0 auto;">
                <div style="background:white; border-radius:12px; padding:24px; box-shadow:0 2px 8px rgba(0,0,0,0.08); border-left:4px solid #6366f1;">
                    <h3 style="margin:0 0 8px; color:#1e293b;"><i class="fa-solid fa-database" style="color:#6366f1; margin-right:8px;"></i>Base de Datos — Migraciones Pendientes</h3>
                    <p style="color:#64748b; font-size:0.85rem; margin:0 0 20px;">Ejecuta todas las migraciones pendientes para crear/actualizar tablas en <strong>WMS_PROORIENTE</strong>. Incluye las tablas de planillas de certificación.</p>
                    <button id="btn-run-migrate" onclick="window._runMigrations()" style="width:100%; padding:14px; background:#6366f1; color:white; border:none; border-radius:10px; font-size:1rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fa-solid fa-play"></i> Ejecutar Migraciones Pendientes
                    </button>
                    <div id="migrate-result" style="margin-top:16px; display:none;"></div>
                </div>
            </div>`;
            setTimeout(() => {
                window._runMigrations = async function() {
                    const btn = document.getElementById('btn-run-migrate');
                    const result = document.getElementById('migrate-result');
                    if (!btn || !result) return;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Ejecutando...';
                    result.style.display = 'none';
                    try {
                        const data = await window.api.post('/system/migrate', {});
                        result.style.display = 'block';
                        if (data.migrated && data.migrated.length > 0) {
                            result.innerHTML = '<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:16px;">' +
                                '<div style="color:#166534; font-weight:700; margin-bottom:8px;"><i class="fa-solid fa-circle-check"></i> ' + data.migrated.length + ' migración(es) ejecutadas:</div>' +
                                data.migrated.map(m => '<div style="color:#15803d; font-size:0.85rem; padding:2px 0;">✓ ' + m + '</div>').join('') +
                                (data.errors && data.errors.length ? '<div style="color:#dc2626; margin-top:8px;">' + data.errors.join('<br>') + '</div>' : '') +
                                '</div>';
                        } else {
                            result.innerHTML = '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; color:#1d4ed8; font-size:0.9rem;"><i class="fa-solid fa-info-circle"></i> No hay migraciones pendientes. Todo está actualizado.</div>';
                        }
                        btn.innerHTML = '<i class="fa-solid fa-check"></i> Completado';
                        btn.style.background = '#22c55e';
                    } catch(e) {
                        result.style.display = 'block';
                        result.innerHTML = '<div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px; color:#dc2626; font-size:0.85rem;"><i class="fa-solid fa-triangle-exclamation"></i> Error: ' + e.message + '</div>';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-play"></i> Reintentar';
                    }
                };
            }, 200);
        } else if (subId === 'odc' && window.ODC) {
            contentHtml = window.ODC.getODCHTML();
            setTimeout(() => { window.ODC.loadODCs(); }, 400);
        } else if (subId === 'certificacion_consolidada' && window.Certificacion) {
            contentHtml = window.Certificacion.getCertificacionHTML('Consolidado');
        } else if (subId === 'certificacion_detalle' && window.Certificacion) {
            contentHtml = window.Certificacion.getCertificacionHTML('Detalle');
        } else if (subId === 'conteo_nuevo' && window.Inventario) {
            contentHtml = window.Inventario.getNuevoConteoHTML();
            setTimeout(() => { window.Inventario.initNuevoConteo(); }, 400);
        } else if (subId === 'conteos_historial' && window.Inventario) {
            contentHtml = window.Inventario.getHistorialConteosHTML();
            setTimeout(() => { window.Inventario.loadHistorialConteos(); }, 400);
        } else if (subId === 'permisos' && window.Permisos) {
            contentHtml = window.Permisos.getPermisosHTML();
            setTimeout(() => { window.Permisos.init(); }, 100);
        } else if (subId === 'mi_perfil' && window.Ajustes) {
            contentHtml = window.Ajustes.getProfileHTML();
        } else if (subId === 'empresa_config' && window.Ajustes) {
            contentHtml = window.Ajustes.getCompanyConfigHTML();
        } else if (subId === 'citas' && window.Recepcion) {
            contentHtml = window.Recepcion.getCitasHTML();
            setTimeout(() => { window.Recepcion.loadCitas(); }, 400);
        } else if (subId === 'recepcion_nueva' && window.Recepcion) {
            contentHtml = window.Recepcion.getRecepcionNuevaHTML();
        } else if (subId === 'putaway' && window.Almacenamiento) {
            contentHtml = window.Almacenamiento.getPutawayHTML();
            setTimeout(() => { window.Almacenamiento.cargarPatio(); }, 400);
        } else if (subId === 'traslado' && window.Almacenamiento) {
            contentHtml = window.Almacenamiento.getTrasladoHTML();
        } else if (subId === 'mapa_bodega' && window.MapaBodega) {
            contentHtml = window.MapaBodega.getHTML();
            setTimeout(() => { window.MapaBodega.init(); }, 300);
        } else if (subId === 'certificacion_consolidada' && window.Despacho) {
            contentHtml = window.Despacho.getCertificacionHTML();
            setTimeout(() => { window.Despacho.loadDespachos(); }, 400);
        } else if (subId === 'certificacion_detalle' && window.Despacho) {
            contentHtml = window.Despacho.getCertificacionHTML();
            setTimeout(() => { window.Despacho.loadDespachos(); }, 400);
        } else if (subId === 'picking_ordenes' && window.Picking) {
            contentHtml = window.Picking.getPickingHTML();
            setTimeout(() => { window.Picking.init(); }, 400);
        } else if (subId === 'picking_rutas' && window.Picking) {
            contentHtml = window.Picking.getPickingRutasHTML();
            setTimeout(() => { window.Picking.loadPickingRutas(); }, 400);
        } else if (subId === 'picking_asignacion' && window.Picking) {
            contentHtml = window.Picking.getAsignacionHTML();
            setTimeout(() => { window.Picking.loadAsignacion(); }, 400);
        } else if (subId === 'picking_consolidado' && window.Picking) {
            contentHtml = window.Picking.getConsolidadoHTML();
            setTimeout(() => { window.Picking.loadConsolidados(); }, 400);
        } else if (subId === 'picking_planilla' && window.Picking) {
            setTimeout(() => { window.Picking.abrirImportarPlanilla(); }, 200);
            contentHtml = '<div style="text-align:center;padding:60px 20px;color:#94a3b8;"><i class="fa-solid fa-file-arrow-up" style="font-size:3rem;margin-bottom:16px;color:#6366f1;display:block;"></i><h4>Importar archivo de planilla desde el modal</h4></div>';
        } else if (subId === 'certificacion_planilla' && window.CertificacionPlanilla) {
            contentHtml = window.CertificacionPlanilla.getHTML();
            setTimeout(() => { window.CertificacionPlanilla.init(); }, 400);
        } else if (subId === 'picking_dashboard' && window.Picking) {
            contentHtml = window.Picking.getDashboardHTML();
            setTimeout(() => { window.Picking.loadDashboardCompleto(); }, 400);
        } else if (subId === 'certificacion' && window.Certificacion) {
            contentHtml = window.Certificacion.getCertificacionHTML('Consolidado');
        } else if (subId === 'recepcion_devolucion' && window.Devoluciones) {
             contentHtml = window.Devoluciones.getDevolucionesHTML();
        } else if (subId === 'devoluciones_historial' && window.Devoluciones) {
             contentHtml = window.Devoluciones.getHistorialHTML();
             setTimeout(() => { window.Devoluciones.loadHistorial(); }, 300);
        // Block removed (duplicated)
        /* } else if (subId === 'permisos' && window.Permisos) {
             contentHtml = window.Permisos.getPermisosHTML();
             setTimeout(() => { window.Permisos.init(); }, 100); */
        } else {
             contentHtml = `<div style="text-align:center; padding:60px 20px; color:#94a3b8;">
                <i class="fa-solid fa-hammer" style="font-size:3rem; margin-bottom:16px; color:#cbd5e1;"></i>
                <h4>Funcionalidad '${subTitle}' pronto</h4>
            </div>`;
        }

        const viewHtml = `
            <div class="view-panel active" id="view-level-2" style="z-index:60;">
                <div class="view-header">
                    <button class="btn-back" onclick="closeView('view-level-2')"><i class="fa-solid fa-arrow-left"></i></button>
                    <h3>${subTitle}</h3>
                </div>
                <div class="view-content" style="background:#f8fafc;">
                    ${contentHtml}
                </div>
            </div>
        `;
        subviewContainer.innerHTML = viewHtml;
    }
});
