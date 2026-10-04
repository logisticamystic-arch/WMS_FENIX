/**
 * fenix WMS — Módulo Centro de Aprobaciones (Desktop)
 * Panel unificado para Supervisores y Administradores para gestionar
 * todas las autorizaciones pendientes en un solo lugar.
 */

window.WMS_MODULES = window.WMS_MODULES || {};

WMS_MODULES.aprobaciones = {
  async load(sub = 'todas') {
    WMS.setBreadcrumb('aprobaciones', sub);

    const user = WMS.user || {};
    const rol = (user.rol || '').toLowerCase();
    const esPrivilegiado = ['admin', 'supervisor', 'superadmin', 'jefe'].includes(rol);

    if (!esPrivilegiado) {
      WMS.setContent(`
        <div class="m-empty" style="padding:60px 20px;text-align:center;">
          <i class="fa-solid fa-shield-cat" style="font-size:3.5rem;color:var(--warn);margin-bottom:16px;"></i>
          <h3 style="font-weight:800;color:var(--text);margin-bottom:8px;">Acceso Restringido a Supervisión</h3>
          <p style="color:var(--muted);font-size:.9rem;max-width:480px;margin:0 auto 20px;">
            El Centro de Aprobaciones está reservado para usuarios con rol <b>Supervisor</b> o <b>Administrador</b>.
            Tu usuario actual es <b>${WMS.esc(user.nombre || 'Operador')}</b> (<i>${WMS.esc(user.rol || 'Auxiliar')}</i>).
          </p>
          <div style="background:var(--warn-bg);border:1px solid var(--warn-bg);border-radius:8px;padding:12px;display:inline-block;font-size:.8rem;color:var(--warn);">
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
      <div style="padding:20px;max-width:1400px;margin:0 auto;min-height:100vh;">
        <!-- Cabecera de Módulo -->
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-card);padding:24px;margin-bottom:24px;color:var(--text);box-shadow:var(--shadow);display:flex;justify-content:space-between;align-items:center;">
          <div>
            <h2 style="margin:0 0 4px 0;font-size:1.5rem;font-weight:800;display:flex;align-items:center;gap:10px;">
              <i class="fa-solid fa-stamp" style="color:var(--info);"></i> Centro de Aprobaciones
            </h2>
            <p style="margin:0;color:var(--muted);font-size:.9rem;">Gestiona las autorizaciones pendientes en tiempo real</p>
          </div>
          <div>
            <button class="btn btn-primary" onclick="WMS_MODULES.aprobaciones.load('${sub}')" style="border-radius:8px;font-weight:700;">
              <i class="fa-solid fa-arrows-rotate"></i> Actualizar
            </button>
          </div>
        </div>

        <!-- Tabs de filtro por tipo de aprobación -->
        <div style="display:flex;gap:12px;margin-bottom:24px;padding-bottom:16px;overflow-x:auto;">
          <button class="btn btn-sm ${sub==='todas'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','todas')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-layer-group"></i> Todas las Pendientes
          </button>
          <button class="btn btn-sm ${sub==='vencimientos'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','vencimientos')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-calendar-xmark" style="color:var(--warn);"></i> Vencimientos 
            <span id="badge-venc" class="badge" style="background:var(--warn-bg);color:var(--warn);margin-left:6px;border-radius:10px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='ajustes'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','ajustes')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-location-crosshairs" style="color:var(--info);"></i> Ajustes x Ubicación 
            <span id="badge-ajust" class="badge" style="background:var(--info-bg);color:var(--accent);margin-left:6px;border-radius:10px;">0</span>
          </button>
          <button class="btn btn-sm ${sub==='devoluciones'?'btn-primary':'btn-secondary'}" onclick="WMS.nav('aprobaciones','devoluciones')" style="border-radius:20px;padding:8px 16px;font-weight:600;box-shadow:0 2px 4px rgba(0,0,0,.05);">
            <i class="fa-solid fa-rotate-left" style="color:var(--danger);"></i> Devoluciones 
            <span id="badge-dev" class="badge" style="background:var(--danger-bg);color:var(--danger);margin-left:6px;border-radius:10px;">0</span>
          </button>
        </div>

        <div id="aprobaciones-container" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(340px, 1fr));gap:24px;">
          <div style="text-align:center;padding:60px;color:var(--muted);grid-column:1/-1;background:var(--surface);border-radius:16px;box-shadow:0 4px 6px -1px rgba(0,0,0,.05);">
            <i class="fa-solid fa-circle-notch fa-spin fa-3x" style="color:var(--info);"></i><br><br>
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
      const ajustesResumen = (rAjust.status === 'fulfilled' && !rAjust.value.error && Array.isArray(rAjust.value.data))
        ? rAjust.value.data.filter(x => x.estado === 'Pendiente') : [];
      const devoluciones = (rDev.status === 'fulfilled' && !rDev.value.error && Array.isArray(rDev.value.data)) ? rDev.value.data : [];

      // Detalle línea-a-línea de cada ajuste pendiente — a pedido explícito
      // de Camilo (2026-09-16): "que el módulo de aprobación muestre el
      // detalle del movimiento que se va a realizar". La lista base no trae
      // las líneas; se completa con una consulta por ajuste (son pocos
      // pendientes a la vez, así que el costo es despreciable).
      const ajustes = (sub === 'todas' || sub === 'ajustes') && ajustesResumen.length
        ? await Promise.all(ajustesResumen.map(async (a) => {
            try {
              const rd = await API.get(`/inventario/ajuste-ubicacion/${a.id}`);
              const body = rd.data ?? rd;
              return { ...a, _detalles: body?.ajuste?.detalles || [], _invActual: body?.inv_actual || [] };
            } catch (e) {
              return { ...a, _detalles: [], _invActual: [] };
            }
          }))
        : ajustesResumen;

      document.getElementById('badge-venc').textContent  = vencimientos.length;
      document.getElementById('badge-ajust').textContent = ajustes.length;
      document.getElementById('badge-dev').textContent   = devoluciones.length;

      let html = '';

      // 1. Fechas de Vencimiento
      if (sub === 'todas' || sub === 'vencimientos') {
        vencimientos.forEach(v => {
          html += `
            <div style="background:var(--surface);border-radius:16px;border-top:4px solid var(--warn);box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:var(--warn-bg);color:var(--warn);padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-calendar-xmark"></i> Vencimiento Corto</span>
                <span style="background:var(--btn-neutral);color:var(--muted);padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;font-family:monospace;">ID #${v.id}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:var(--text);flex-grow:1;">${WMS.esc(v.producto?.nombre || 'Producto')}</h4>
              
              <div style="background:var(--table-header-bg);border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:var(--text2);">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Lote:</span> <b style="color:var(--text);">${WMS.esc(v.lote || 'N/A')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Vencimiento:</span> <b style="color:var(--danger);background:var(--danger-bg);padding:2px 6px;border-radius:4px;">${WMS.formatDate(v.fecha_vencimiento)}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Existencia:</span> <b>${v.fecha_existente_bodega ? WMS.formatDate(v.fecha_existente_bodega) : 'Ninguna'}</b>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px dashed var(--border);padding-top:6px;margin-top:6px;">
                  <span>Cantidad:</span> <b style="font-size:1rem;color:var(--text);">${v.cantidad_recibida} und</b>
                </div>
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm btn-success" style="flex:1;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Autorizar
                </button>
                <button class="btn btn-sm btn-secondary" style="flex:1;color:var(--danger);font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverVencimiento(${v.id}, 'rechazar')">
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
            <div style="background:var(--surface);border-radius:16px;border-top:4px solid var(--info);box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:var(--info-bg);color:var(--accent);padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-location-crosshairs"></i> Ajuste Ubicación</span>
                <span style="background:var(--btn-neutral);color:var(--muted);padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;">${(a.created_at || '').substring(0,10)}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:var(--text);flex-grow:1;">
                <i class="fa-solid fa-warehouse" style="color:var(--text-muted);margin-right:6px;"></i> ${WMS.esc(a.ubicacion?.codigo || 'N/A')}
              </h4>
              
              <div style="background:var(--table-header-bg);border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:var(--text2);">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Tipo:</span> <b style="color:var(--text);background:var(--btn-neutral);padding:2px 6px;border-radius:4px;">${WMS.esc(a.tipo)}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Solicitado por:</span> <b>${WMS.esc(a.auxiliar?.nombre || 'Auxiliar')}</b>
                </div>
                ${this._ajusteDetalleHtml(a)}
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm btn-primary" style="flex:1;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar Ajuste
                </button>
                <button class="btn btn-sm btn-secondary" style="flex:1;color:var(--danger);font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverAjuste(${a.id}, 'rechazar')">
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
            <div style="background:var(--surface);border-radius:16px;border-top:4px solid var(--danger);box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);padding:20px;display:flex;flex-direction:column;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <span class="badge" style="background:var(--danger-bg);color:var(--danger);padding:6px 12px;border-radius:8px;font-weight:700;"><i class="fa-solid fa-rotate-left"></i> Devolución </span>
                <span style="background:var(--btn-neutral);color:var(--muted);padding:4px 8px;border-radius:6px;font-size:.7rem;font-weight:600;font-family:monospace;">#${d.id}</span>
              </div>
              <h4 style="margin:0 0 12px 0;font-size:1.05rem;font-weight:800;color:var(--text);flex-grow:1;">
                ${WMS.esc(d.proveedor?.razon_social || d.tercero_nombre || 'Proveedor')}
              </h4>
              
              <div style="background:var(--table-header-bg);border-radius:8px;padding:12px;margin-bottom:20px;font-size:.8rem;color:var(--text2);">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Motivo:</span> <b style="color:var(--text);">${WMS.esc(d.motivo_general || d.motivo || 'N/A')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span>Documento:</span> <b>${WMS.esc(d.numero_documento || 'Sin doc')}</b>
                </div>
                <div style="display:flex;justify-content:space-between;border-top:1px dashed var(--border);padding-top:6px;margin-top:6px;">
                  <span>Registrado por:</span> <b style="color:var(--text);">${WMS.esc(d.responsable_devolucion || d.usuario?.nombre || 'Sistema')}</b>
                </div>
              </div>

              <div style="display:flex;gap:10px;">
                <button class="btn btn-sm btn-danger" style="flex:1;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'aprobar')">
                  <i class="fa-solid fa-check"></i> Aprobar
                </button>
                <button class="btn btn-sm btn-secondary" style="flex:1;font-weight:700;" onclick="WMS_MODULES.aprobaciones.resolverDevolucion(${d.id}, 'rechazar')">
                  <i class="fa-solid fa-xmark"></i> Rechazar
                </button>
              </div>
            </div>`;
        });
      }

      if (!html) {
        html = `
          <div class="m-empty" style="grid-column:1/-1;padding:80px 20px;text-align:center;background:var(--surface);border-radius:24px;border:2px dashed var(--border);">
            <div style="width:80px;height:80px;background:var(--ok-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;">
              <i class="fa-solid fa-check-double" style="font-size:2.5rem;color:var(--ok);"></i>
            </div>
            <h3 style="font-weight:800;color:var(--text);margin-bottom:8px;font-size:1.5rem;">¡Bandeja al día!</h3>
            <p style="color:var(--muted);font-size:1rem;margin:0;">No hay solicitudes pendientes de aprobación para esta categoría.</p>
          </div>`;
      }

      container.innerHTML = html;

    } catch(e) {
      container.innerHTML = `<div style="grid-column:1/-1;" class="alert alert-danger">Error al cargar aprobaciones: ${WMS.esc(e.message)}</div>`;
    }
  },

  // Detalle línea-a-línea del movimiento que se va a realizar al aprobar —
  // distinto según el modo del ajuste. A pedido explícito de Camilo
  // (2026-09-16): el card de aprobación no mostraba nada de esto.
  _ajusteDetalleHtml(a) {
    const detalles   = a._detalles  || [];
    const invActual  = a._invActual || [];
    const linea = (nombre, codigo, cant) => `
      <div style="display:flex;justify-content:space-between;gap:8px;padding:2px 0;font-size:.72rem;">
        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(nombre || '-')} <small style="color:var(--text-muted);">${WMS.esc(codigo || '')}</small></span>
        <b style="flex-shrink:0;">${WMS.formatNum(cant)}</b>
      </div>`;
    const wrap = (inner) => `<div style="max-height:160px;overflow-y:auto;margin:4px 0 8px;">${inner}</div>`;
    const vacio = '<div style="color:var(--text-muted);font-size:.7rem;font-style:italic;">— ninguna —</div>';

    if (a.tipo === 'AgregarInventario') {
      return `
        <div style="border-top:1px dashed var(--border);padding-top:8px;margin-top:6px;">
          <div style="font-size:.68rem;font-weight:800;color:var(--ok);text-transform:uppercase;"><i class="fa-solid fa-plus"></i> Se sumará al stock existente (${detalles.length})</div>
          ${wrap(detalles.length ? detalles.map(d => linea(d.producto?.nombre, d.producto?.codigo_interno, d.cantidad)).join('') : vacio)}
        </div>`;
    }

    if (a.tipo === 'AjustarCantidad') {
      const filaCambio = (d) => {
        const match = invActual.find(i =>
          i.producto_id === d.producto_id &&
          (i.lote || null) === (d.lote || null) &&
          (i.fecha_vencimiento || '').substring(0,10) === (d.fecha_vencimiento || '').substring(0,10)
        );
        const actual = match ? +match.cantidad : 0;
        const nueva  = +d.cantidad;
        const delta  = nueva - actual;
        const color  = delta > 0 ? 'var(--ok)' : delta < 0 ? 'var(--danger)' : 'var(--muted)';
        const signo  = delta > 0 ? '+' : '';
        return `<div style="display:flex;justify-content:space-between;gap:8px;padding:2px 0;font-size:.72rem;">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(d.producto?.nombre || '-')}</span>
          <span style="flex-shrink:0;">${WMS.formatNum(actual)} → <b>${WMS.formatNum(nueva)}</b> <b style="color:${color};">(${signo}${WMS.formatNum(delta)})</b></span>
        </div>`;
      };
      return `
        <div style="border-top:1px dashed var(--border);padding-top:8px;margin-top:6px;">
          <div style="font-size:.68rem;font-weight:800;color:var(--info);text-transform:uppercase;"><i class="fa-solid fa-pen-to-square"></i> Cambios de cantidad (${detalles.length})</div>
          ${wrap(detalles.length ? detalles.map(filaCambio).join('') : vacio)}
        </div>`;
    }

    if (a.tipo === 'AjusteCero') {
      return `
        <div style="border-top:1px dashed var(--border);padding-top:8px;margin-top:6px;">
          <div style="font-size:.68rem;font-weight:800;color:var(--danger);text-transform:uppercase;"><i class="fa-solid fa-ban"></i> Se pondrá en CERO — sin inventario físico (${invActual.length})</div>
          ${wrap(invActual.length ? invActual.map(i => linea(i.producto?.nombre, i.producto?.codigo_interno, i.cantidad)).join('') : vacio)}
          <div style="font-size:.68rem;color:var(--muted);font-style:italic;margin-top:2px;">No afecta el inventario de estas referencias en otras ubicaciones.</div>
        </div>`;
    }

    // AjusteCompleto (default): se elimina TODO lo actual, se crea lo contado.
    return `
      <div style="border-top:1px dashed var(--border);padding-top:8px;margin-top:6px;">
        <div style="font-size:.68rem;font-weight:800;color:var(--danger);text-transform:uppercase;"><i class="fa-solid fa-trash"></i> Se eliminará (${invActual.length})</div>
        ${wrap(invActual.length ? invActual.map(i => linea(i.producto?.nombre, i.producto?.codigo_interno, i.cantidad)).join('') : vacio)}
        <div style="font-size:.68rem;font-weight:800;color:var(--ok);text-transform:uppercase;">
          <i class="fa-solid fa-plus"></i> Se agregará (${detalles.length})
        </div>
        ${wrap(detalles.length ? detalles.map(d => linea(d.producto?.nombre, d.producto?.codigo_interno, d.cantidad)).join('') : vacio)}
      </div>`;
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
