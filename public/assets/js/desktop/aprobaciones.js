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
      <div style="padding:20px;max-width:1400px;margin:0 auto;">
        <!-- Tabs de filtro por tipo de aprobación -->
        <div style="display:flex;gap:10px;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;">
          <button class="btn btn-sm ${sub==='todas'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','todas')">
            <i class="fa-solid fa-layer-group"></i> Todas las Pendientes
          </button>
          <button class="btn btn-sm ${sub==='vencimientos'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','vencimientos')">
            <i class="fa-solid fa-calendar-xmark"></i> Vencimientos <span id="badge-venc" class="badge badge-warning" style="margin-left:4px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='ajustes'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','ajustes')">
            <i class="fa-solid fa-location-crosshairs"></i> Ajustes x Ubicación <span id="badge-ajust" class="badge badge-info" style="margin-left:4px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='devoluciones'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','devoluciones')">
            <i class="fa-solid fa-rotate-left"></i> Devoluciones <span id="badge-dev" class="badge badge-danger" style="margin-left:4px;">0</span>
          </button>
        </div>

        <div id="aprobaciones-container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:20px;">
          <div style="text-align:center;padding:40px;color:#64748b;grid-column:1/-1;">
            <i class="fa-solid fa-circle-notch fa-spin fa-2x"></i><br><br>Cargando aprobaciones pendientes...
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
            <div style="background:#fff;border-radius:12px;border:1px solid #fed7aa;box-shadow:0 2px 8px rgba(245,158,11,.08);padding:16px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                <span class="badge badge-warning"><i class="fa-solid fa-calendar-xmark"></i> Vencimiento Corto</span>
                <small style="color:#64748b;font-size:.7rem;">ID #${v.id}</small>
              </div>
              <h4 style="margin:0 0 6px 0;font-size:.92rem;font-weight:700;color:#1e293b;">${WMS.esc(v.producto?.nombre || 'Producto')}</h4>
              <div style="font-size:.78rem;color:#475569;margin-bottom:12px;line-height:1.5;">
                • Lote: <b>${WMS.esc(v.lote || 'N/A')}</b><br>
                • Vencimiento recibido: <b style="color:#dc2626;">${v.fecha_vencimiento}</b><br>
                • Existente en bodega: <span>${v.fecha_existente_bodega || 'Ninguna'}</span><br>
                • Cantidad: <b>${v.cantidad_recibida} und</b>
              </div>
              <div style="display:flex;gap:8px;">
                <button class="btn btn-success btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Autorizar Ingreso
                </button>
                <button class="btn btn-danger btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'rechazar')">
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
            <div style="background:#fff;border-radius:12px;border:1px solid #bfdbfe;box-shadow:0 2px 8px rgba(59,130,246,.08);padding:16px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                <span class="badge badge-info"><i class="fa-solid fa-location-crosshairs"></i> Ajuste Ubicación</span>
                <small style="color:#64748b;font-size:.7rem;">${a.created_at || ''}</small>
              </div>
              <h4 style="margin:0 0 6px 0;font-size:.92rem;font-weight:700;color:#1e293b;">Ubicación: ${WMS.esc(a.ubicacion?.codigo || 'N/A')}</h4>
              <div style="font-size:.78rem;color:#475569;margin-bottom:12px;line-height:1.5;">
                • Tipo: <b>${WMS.esc(a.tipo)}</b><br>
                • Solicitado por: <b>${WMS.esc(a.usuario?.nombre || 'Auxiliar')}</b><br>
                • Referencias contadas: <b>${a.detalles_count || a.detalles?.length || 0} ítems</b>
              </div>
              <div style="display:flex;gap:8px;">
                <button class="btn btn-success btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar Ajuste
                </button>
                <button class="btn btn-danger btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'rechazar')">
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
            <div style="background:#fff;border-radius:12px;border:1px solid #fecaca;box-shadow:0 2px 8px rgba(220,38,38,.08);padding:16px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                <span class="badge badge-danger"><i class="fa-solid fa-rotate-left"></i> Devolución Proveedor</span>
                <small style="color:#64748b;font-size:.7rem;">#${d.id}</small>
              </div>
              <h4 style="margin:0 0 6px 0;font-size:.92rem;font-weight:700;color:#1e293b;">${WMS.esc(d.proveedor?.razon_social || 'Proveedor')}</h4>
              <div style="font-size:.78rem;color:#475569;margin-bottom:12px;line-height:1.5;">
                • Motivo: <b>${WMS.esc(d.motivo || 'N/A')}</b><br>
                • Documento: <b>${WMS.esc(d.numero_documento || 'Sin doc')}</b><br>
                • Registrado por: <b>${WMS.esc(d.usuario?.nombre || 'Sistema')}</b>
              </div>
              <div style="display:flex;gap:8px;">
                <button class="btn btn-success btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar Devolución
                </button>
                <button class="btn btn-danger btn-sm" style="flex:1;" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'rechazar')">
                  <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
              </div>
            </div>`;
        });
      }

      if (!html) {
        html = `
          <div class="m-empty" style="grid-column:1/-1;padding:60px 20px;text-align:center;background:#fff;border-radius:12px;border:1px dashed #cbd5e1;">
            <i class="fa-solid fa-circle-check" style="font-size:3rem;color:#10b981;margin-bottom:12px;"></i>
            <h3 style="font-weight:800;color:#1e293b;margin-bottom:6px;">¡Todo al día!</h3>
            <p style="color:#64748b;font-size:.85rem;margin:0;">No hay solicitudes pendientes de aprobación en este filtro.</p>
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
