/**
 * fenix WMS — Módulo Centro de Aprobaciones (Desktop)
 * Panel unificado para Supervisores y Administradores para gestionar
 * todas las autorizaciones pendientes en un solo lugar.
 */

window.WMS_MODULES = window.WMS_MODULES || {};

WMS_MODULES.aprobaciones = {
  async load(sub = 'todas') {
    WMS.setBreadcrumb('aprobaciones', sub);
    WMS.setTitle('<i class="fa-solid fa-stamp"></i> Centro de Aprobaciones WMS');
    
    const user = WMS.user || {};
    const rol = (user.rol || '').toLowerCase();
    const esPrivilegiado = ['admin', 'supervisor', 'superadmin', 'jefe'].includes(rol);

    if (!esPrivilegiado) {
      WMS.setContent(`
        <div class="m-empty" style="padding:60px 20px;text-align:center;">
          <i class="fa-solid fa-shield-cat" style="font-size:3.5rem;color:#f59e0b;margin-bottom:16px;"></i>
          <h3 style="font-weight:800;color:#1e293b;margin-bottom:8px;">Acceso Restringido a Supervisión</h3>
          <p style="color:#64748b;font-size:.9rem;max-width:480px;margin:0 auto 20px;">
            El Centro de Aprobaciones está reservado para usuarios con rol <b>Supervisor</b> o <b>Administrador</b>.
            Tu usuario actual es <b>${WMS.esc(user.nombre || 'Operador')}</b> (<i>${WMS.esc(user.rol || 'Auxiliar')}</i>).
          </p>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px;display:inline-block;font-size:.8rem;color:#92400e;">
            <i class="fa-solid fa-circle-info"></i> Si necesitas aprobar excepciones o cierres, solicita a tu Administrador actualizar tu rol en <b>Maestros → Personal / Usuarios</b>.
          </div>
        </div>
      `);
      return;
    }

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.aprobaciones.load('${sub}')">
        <i class="fa-solid fa-arrows-rotate"></i> Actualizar Pendientes
      </button>
    `);

    WMS.setContent(`
      <div style="padding:20px;max-width:1400px;margin:0 auto;background:#f8fafc;min-height:100vh;">
        <!-- Cabecera de Módulo -->
        <div style="background:linear-gradient(135deg, #1e293b, #0f172a);border-radius:16px;padding:24px;margin-bottom:24px;color:#fff;box-shadow:0 10px 25px -5px rgba(0,0,0,0.2);display:flex;justify-content:space-between;align-items:center;">
          <div>
            <h2 style="margin:0 0 4px 0;font-size:1.5rem;font-weight:800;display:flex;align-items:center;gap:10px;">
              <i class="fa-solid fa-stamp" style="color:#3b82f6;"></i> Centro de Aprobaciones
            </h2>
            <p style="margin:0;color:#94a3b8;font-size:.9rem;">Gestiona las autorizaciones pendientes en tiempo real</p>
          </div>
          <div>
            <button class="btn btn-primary" onclick="WMS_MODULES.aprobaciones.load('${sub}')" style="border-radius:8px;font-weight:700;">
              <i class="fa-solid fa-arrows-rotate"></i> Actualizar
            </button>
          </div>
        </div>

        <!-- Tabs de filtro por tipo de aprobación -->
        <div style="display:flex;gap:12px;margin-bottom:24px;padding-bottom:16px;overflow-x:auto;">
          <button class="btn btn-sm ${sub==='todas'?'btn-primary':'btn-light'}" onclick="WMS.nav('aprobaciones','todas')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-layer-group"></i> Todas las Pendientes
          </button>
          <button class="btn btn-sm ${sub==='vencimientos'?'btn-primary':'btn-light'}" onclick="WMS.nav('aprobaciones','vencimientos')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-calendar-xmark" style="color:#f59e0b;"></i> Vencimientos 
            <span id="badge-venc" class="badge" style="background:#fef3c7;color:#b45309;margin-left:6px;border-radius:10px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='ajustes'?'btn-primary':'btn-light'}" onclick="WMS.nav('aprobaciones','ajustes')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-location-crosshairs" style="color:#3b82f6;"></i> Ajustes x Ubicación 
            <span id="badge-ajust" class="badge" style="background:#dbeafe;color:#1e3a8a;margin-left:6px;border-radius:10px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='devoluciones'?'btn-primary':'btn-light'}" onclick="WMS.nav('aprobaciones','devoluciones')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-rotate-left" style="color:#ef4444;"></i> Devoluciones 
            <span id="badge-dev" class="badge" style="background:#fee2e2;color:#991b1b;margin-left:6px;border-radius:10px;">0</span>
          </button>
        </div>

        <div id="aprobaciones-container" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:24px;">
          <div style="text-align:center;padding:60px;color:#64748b;grid-column:1/-1;background:#fff;border-radius:16px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);">
            <i class="fa-solid fa-circle-notch fa-spin fa-3x" style="color:#3b82f6;"></i><br><br>
            <span style="font-weight:600;font-size:1.1rem;">Obteniendo autorizaciones...</span>
          </div>
        </div>
      </div>
    `);

    this.fetchData(sub);
  },

  async fetchData(sub) {
    const container = document.getElementById('aprobaciones-container');
    if (!container) return;

    try {
      const [rVenc, rAjust, rDev] = await Promise.allSettled([
        API.get('/aprobaciones/vencimiento/pendientes'),
        API.get('/inventario/ajuste-ubicacion'),
        API.get('/devoluciones?estado=PendienteAprobacion&por_pagina=200')
      ]);

      const vencimientos = (rVenc.status === 'fulfilled' && !rVenc.value.error && Array.isArray(rVenc.value.data)) ? rVenc.value.data : [];
      const ajustes = (rAjust.status === 'fulfilled' && !rAjust.value.error && Array.isArray(rAjust.value.data)) 
        ? rAjust.value.data.filter(x => x.estado === 'Pendiente') : [];
      const devoluciones = (rDev.status === 'fulfilled' && !rDev.value.error && Array.isArray(rDev.value.data)) ? rDev.value.data : [];

      document.getElementById('badge-venc').textContent  = vencimientos.length;
      document.getElementById('badge-ajust').textContent = ajustes.length;
      document.getElementById('badge-dev').textContent   = devoluciones.length;

      let html = '';

      // 1. Fechas de Vencimiento
      if (sub === 'todas' || sub === 'vencimientos') {
        vencimientos.forEach(v => {
          html += `
            <div style="background:#fff;border-radius:16px;border-top:4px solid #f59e0b;box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:#fef3c7;color:#b45309;padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-calendar-xmark"></i> Vencimiento Corto</span>
                <span style="background:#f1f5f9;color:#64748b;padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;font-family:monospace;">ID #${v.id}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:#1e293b;flex-grow:1;">${WMS.esc(v.producto?.nombre || 'Producto')}</h4>
              
              <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:#475569;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Lote:</span> <b style="color:#0f172a;">${WMS.esc(v.lote || 'N/A')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Vencimiento:</span> <b style="color:#ef4444;background:#fee2e2;padding:2px 6px;border-radius:4px;">${WMS.formatDate(v.fecha_vencimiento)}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Existencia:</span> <b>${v.fecha_existente_bodega ? WMS.formatDate(v.fecha_existente_bodega) : 'Ninguna'}</b>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px dashed #cbd5e1;padding-top:6px;margin-top:6px;">
                  <span>Cantidad:</span> <b style="font-size:1rem;color:#0f172a;">${v.cantidad_recibida} und</b>
                </div>
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm" style="flex:1;background:linear-gradient(to right, #10b981, #059669);color:#fff;border:none;border-radius:8px;font-weight:700;box-shadow:0 4px 6px rgba(16, 185, 129, 0.2);" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Autorizar
                </button>
                <button class="btn btn-sm" style="flex:1;background:#fff;color:#ef4444;border:1px solid #fca5a5;border-radius:8px;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'rechazar')">
                  <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
              </div>
            </div>`;
        });
      }

      // 2. Ajustes x Ubicación
      if (sub === 'todas' || sub === 'ajustes') {
        ajustes.forEach(a => {
          html += `
            <div style="background:#fff;border-radius:16px;border-top:4px solid #3b82f6;box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:#dbeafe;color:#1e3a8a;padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-location-crosshairs"></i> Ajuste Ubicación</span>
                <span style="background:#f1f5f9;color:#64748b;padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;">${(a.created_at || '').substring(0,10)}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:#1e293b;flex-grow:1;">
                <i class="fa-solid fa-warehouse" style="color:#94a3b8;margin-right:6px;"></i> ${WMS.esc(a.ubicacion?.codigo || 'N/A')}
              </h4>
              
              <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:#475569;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Tipo:</span> <b style="color:#0f172a;background:#e2e8f0;padding:2px 6px;border-radius:4px;">${WMS.esc(a.tipo)}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Solicitado por:</span> <b>${WMS.esc(a.usuario?.nombre || 'Auxiliar')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px dashed #cbd5e1;padding-top:6px;margin-top:6px;">
                  <span>Refs. Contadas:</span> <b style="font-size:1rem;color:#0f172a;">${a.detalles_count || a.detalles?.length || 0} ítems</b>
                </div>
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm" style="flex:1;background:linear-gradient(to right, #3b82f6, #2563eb);color:#fff;border:none;border-radius:8px;font-weight:700;box-shadow:0 4px 6px rgba(59, 130, 246, 0.2);" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar Ajuste
                </button>
                <button class="btn btn-sm" style="flex:1;background:#fff;color:#ef4444;border:1px solid #fca5a5;border-radius:8px;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'rechazar')">
                  <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
              </div>
            </div>`;
        });
      }

      // 3. Devoluciones
      if (sub === 'todas' || sub === 'devoluciones') {
        devoluciones.forEach(d => {
          html += `
            <div style="background:#fff;border-radius:16px;border-top:4px solid #ef4444;box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Devolución </span>
                <span style="background:#f1f5f9;color:#64748b;padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;font-family:monospace;">#${d.id}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:#1e293b;flex-grow:1;">
                ${WMS.esc(d.proveedor?.razon_social || d.tercero_nombre || 'Proveedor')}
              </h4>
              
              <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:#475569;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Motivo:</span> <b style="color:#0f172a;">${WMS.esc(d.motivo_general || d.motivo || 'N/A')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Documento:</span> <b>${WMS.esc(d.numero_documento || 'Sin doc')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px dashed #cbd5e1;padding-top:6px;margin-top:6px;">
                  <span>Registrado por:</span> <b style="color:#0f172a;">${WMS.esc(d.responsable_devolucion || d.usuario?.nombre || 'Sistema')}</b>
                </div>
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm" style="flex:1;background:linear-gradient(to right, #ef4444, #dc2626);color:#fff;border:none;border-radius:8px;font-weight:700;box-shadow:0 4px 6px rgba(239, 68, 68, 0.2);" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar
                </button>
                <button class="btn btn-sm" style="flex:1;background:#fff;color:#64748b;border:1px solid #cbd5e1;border-radius:8px;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'rechazar')">
                  <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
              </div>
            </div>`;
        });
      }

      if (!html) {
        html = `
          <div class="m-empty" style="grid-column:1/-1;padding:80px 20px;text-align:center;background:#fff;border-radius:24px;border:2px dashed #e2e8f0;">
            <div style="width:80px;height:80px;background:#f0fdf4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
              <i class="fa-solid fa-check-double" style="font-size:2.5rem;color:#22c55e;"></i>
            </div>
            <h3 style="font-weight:800;color:#0f172a;margin-bottom:8px;font-size:1.5rem;">¡Bandeja al día!</h3>
            <p style="color:#64748b;font-size:1rem;margin:0;">No hay solicitudes pendientes de aprobación para esta categoría.</p>
          </div>`;
      }

      container.innerHTML = html;

    } catch(e) {
      container.innerHTML = `<div style="grid-column:1/-1;" class="alert alert-danger">Error al cargar aprobaciones: ${WMS.esc(e.message)}</div>`;
    }
  },

  async resolverVencimiento(id, decision) {
    if (!confirm(`¿Confirma ${decision.toUpperCase()} el vencimiento #${id}?`)) return;
    try {
      const r = await API.post(`/aprobaciones/${id}/resolver`, { decision });
      if (r.error) return WMS.toast('error', r.message);
      WMS.toast('success', `Solicitud ${decision === 'aprobar' ? 'aprobada' : 'rechazada'} correctamente.`);
      this.load(WMS._sub);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async resolverAjuste(id, decision) {
    if (!confirm(`¿Confirma ${decision.toUpperCase()} el ajuste de ubicación #${id}?`)) return;
    try {
      const endpoint = decision === 'aprobar' ? `/inventario/ajuste-ubicacion/${id}/aprobar` : `/inventario/ajuste-ubicacion/${id}/rechazar`;
      const r = await API.post(endpoint, {});
      if (r.error) return WMS.toast('error', r.message);
      WMS.toast('success', `Ajuste de ubicación ${decision === 'aprobar' ? 'aprobado' : 'rechazado'}.`);
      this.load(WMS._sub);
    } catch(e) { WMS.toast('error', e.message); }
  },

  async resolverDevolucion(id, decision) {
    if (!confirm(`¿Confirma ${decision.toUpperCase()} la devolución #${id}?`)) return;
    try {
      const endpoint = decision === 'aprobar' ? `/devoluciones/${id}/aprobar` : `/devoluciones/${id}/rechazar`;
      const r = await API.post(endpoint, {});
      if (r.error) return WMS.toast('error', r.message);
      WMS.toast('success', `Devolución ${decision === 'aprobar' ? 'aprobada' : 'rechazada'}.`);
      this.load(WMS._sub);
    } catch(e) { WMS.toast('error', e.message); }
  },

  subLabel(sub) {
    const labels = {
      todas: 'Todas las Pendientes',
      vencimientos: 'Fechas de Vencimiento',
      ajustes: 'Ajustes x Ubicación',
      devoluciones: 'Devoluciones Proveedor'
    };
    return labels[sub] || sub;
  }
};
