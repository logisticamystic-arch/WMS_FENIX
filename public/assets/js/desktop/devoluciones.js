// public/assets/js/desktop/devoluciones.js
'use strict';
WMS_MODULES.devoluciones = {

  _state: { lista: [], detalle: null, qrProd: null, items: [] },

  load(sub) {
    sub = sub || 'lista';
    if (sub === 'lista')  return this.showLista();
    if (sub === 'nueva')  return this.showNueva();
  },

  // ── LISTA ─────────────────────────────────────────────────────────────────

  async showLista(filtros = {}) {
    WMS.spinner();
    const qs = new URLSearchParams(filtros).toString();
    try {
      const r = await API.get('/devoluciones' + (qs ? '?' + qs : ''));
      this._state.lista = r.data || [];
      this._renderLista(this._state.lista);
    } catch(e) { WMS.toast('error', 'Error al cargar devoluciones'); }
  },

  _renderLista(rows) {
    const badgeColor = {
      PendienteAprobacion: '#f59e0b', Aprobada: '#3b82f6', Procesada: '#16a34a',
      Rechazada: '#dc2626', Anulada: '#94a3b8', Borrador: '#64748b',
    };
    const tipoLabel = {
      cliente: 'Cliente→WMS', proveedor: 'WMS→Proveedor', interna: 'Interna',
      AProveedorAveria: 'Proveedor (Avería)', AProveedorVencido: 'Proveedor (Vencido)',
      ReingresoBuenEstado: 'Reingreso', Borrador: 'Sin tipo',
    };

    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.showNueva()">
        <i class="fa-solid fa-plus"></i> Nueva Devolución
      </button>`);

    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
          <span class="card-title"><i class="fa-solid fa-rotate-left"></i> Devoluciones</span>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select id="dv-f-tipo" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los tipos</option>
              <option value="cliente">Cliente→WMS</option>
              <option value="proveedor">WMS→Proveedor</option>
              <option value="interna">Interna</option>
            </select>
            <select id="dv-f-estado" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los estados</option>
              <option value="PendienteAprobacion">Pendiente Aprobación</option>
              <option value="Aprobada">Aprobada</option>
              <option value="Procesada">Procesada</option>
              <option value="Rechazada">Rechazada</option>
              <option value="Anulada">Anulada</option>
            </select>
            <input type="date" id="dv-f-desde" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="date" id="dv-f-hasta" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="text" id="dv-f-q" class="form-control form-control-sm" placeholder="Buscar N°, referencia..." style="min-width:180px;"
              oninput="WMS_MODULES.devoluciones._aplicarFiltros()">
          </div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>N°</th><th>Tipo</th><th>Estado</th><th>Referencia ERP</th>
              <th class="text-center">Ítems</th><th>Fecha</th><th>Solicitado por</th><th>Acciones</th>
            </tr></thead>
            <tbody id="dv-tbody">
              ${rows.length ? rows.map(d => `
                <tr>
                  <td><strong>${WMS.esc(d.numero_devolucion)}</strong></td>
                  <td><span class="badge" style="background:#e0f2fe;color:#0369a1;">${WMS.esc(tipoLabel[d.tipo]||d.tipo)}</span></td>
                  <td><span class="badge" style="background:${badgeColor[d.estado]||'#94a3b8'}20;color:${badgeColor[d.estado]||'#94a3b8'};border:1px solid ${badgeColor[d.estado]||'#94a3b8'};">${WMS.esc(d.estado)}</span></td>
                  <td>${WMS.esc(d.referencia_externa||'-')}</td>
                  <td class="text-center">${(d.detalles||[]).length}</td>
                  <td style="font-size:11px;">${d.created_at ? d.created_at.substring(0,10) : '-'}</td>
                  <td style="font-size:11px;">${WMS.esc(d.solicitado_por_nombre||'-')}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
                      <i class="fa-solid fa-eye"></i> Ver
                    </button>
                  </td>
                </tr>`).join('') : '<tr><td colspan="8" class="table-empty">Sin devoluciones registradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _aplicarFiltros() {
    clearTimeout(this._filterTimer);
    this._filterTimer = setTimeout(() => {
      const f = {
        tipo:   document.getElementById('dv-f-tipo')?.value   || '',
        estado: document.getElementById('dv-f-estado')?.value || '',
        desde:  document.getElementById('dv-f-desde')?.value  || '',
        hasta:  document.getElementById('dv-f-hasta')?.value  || '',
        q:      document.getElementById('dv-f-q')?.value      || '',
      };
      Object.keys(f).forEach(k => { if (!f[k]) delete f[k]; });
      this.showLista(f);
    }, 400);
  },

  // ── DETALLE ───────────────────────────────────────────────────────────────

  async showDetalle(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/' + id);
      const d = r.data;
      this._state.detalle = d;
      const estado = d.estado;

      const canAprobar  = estado === 'PendienteAprobacion';
      const canProcesar = estado === 'Aprobada';
      const canAnular   = ['PendienteAprobacion','Borrador'].includes(estado);
      const rol = _wmsUser?.rol ?? '';
      const isSup = ['Admin','Supervisor','SuperAdmin','Jefe'].includes(rol);

      const badgeColor = {
        PendienteAprobacion:'#f59e0b',Aprobada:'#3b82f6',Procesada:'#16a34a',
        Rechazada:'#dc2626',Anulada:'#94a3b8',Borrador:'#64748b',
      };

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
          <i class="fa-solid fa-arrow-left"></i> Volver
        </button>
        ${isSup && canAprobar  ? `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.devoluciones.aprobar(${d.id})"><i class="fa-solid fa-check"></i> Aprobar</button>` : ''}
        ${isSup && canAprobar  ? `<button class="btn btn-danger btn-sm" onclick="WMS_MODULES.devoluciones.rechazar(${d.id})"><i class="fa-solid fa-times"></i> Rechazar</button>` : ''}
        ${canProcesar           ? `<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.abrirProcesar(${d.id})"><i class="fa-solid fa-gears"></i> Procesar</button>` : ''}
        ${isSup && canAnular    ? `<button class="btn btn-outline-danger btn-sm" onclick="WMS_MODULES.devoluciones.anular(${d.id})"><i class="fa-solid fa-ban"></i> Anular</button>` : ''}`);

      WMS.setContent(`
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title"><i class="fa-solid fa-rotate-left"></i> ${WMS.esc(d.numero_devolucion)}</span>
            <span class="badge" style="background:${badgeColor[estado]||'#94a3b8'}20;color:${badgeColor[estado]||'#94a3b8'};border:1px solid ${badgeColor[estado]||'#94a3b8'};font-size:13px;">${WMS.esc(estado)}</span>
          </div>
          <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:13px;">
            <div><div style="color:#64748b;font-size:11px;">Tipo</div><strong>${WMS.esc(d.tipo)}</strong></div>
            <div><div style="color:#64748b;font-size:11px;">Referencia ERP</div>${WMS.esc(d.referencia_externa||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Motivo</div>${WMS.esc(d.motivo_general)}</div>
            <div><div style="color:#64748b;font-size:11px;">Solicitado por</div>${WMS.esc(d.solicitado_por||d.auxiliar_id||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Aprobado por</div>${WMS.esc(d.aprobado_por||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Fecha</div>${d.created_at?d.created_at.substring(0,10):'-'}</div>
          </div>
          <div class="table-container">
            <table class="erp-table" style="font-size:12px;">
              <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th><th>Nota</th></tr></thead>
              <tbody>
                ${(d.detalles||[]).map(det => `<tr>
                  <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
                  <td><code>${WMS.esc(det.lote||'-')}</code></td>
                  <td style="font-size:11px;">${det.fecha_vencimiento||'-'}</td>
                  <td class="text-center fw-700">${WMS.formatNum(det.cantidad)}</td>
                  <td>${WMS.esc(det.condicion||'-')}</td>
                  <td>${det.destino ? `<span class="badge" style="background:#f0fdf4;color:#16a34a;">${WMS.esc(det.destino)}</span>` : '<span style="color:#94a3b8;">—</span>'}</td>
                  <td style="font-size:11px;color:#64748b;">${WMS.esc(det.detalle_motivo||det.motivo_item||'-')}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error al cargar detalle'); }
  },

  // ── ACCIONES ──────────────────────────────────────────────────────────────

  async aprobar(id) {
    if (!confirm('¿Aprobar esta devolución?')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/aprobar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución aprobada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al aprobar'); }
  },

  async rechazar(id) {
    const motivo = prompt('Motivo del rechazo (opcional):') ?? '';
    try {
      const r = await API.post('/devoluciones/' + id + '/rechazar', { motivo_rechazo: motivo });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución rechazada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al rechazar'); }
  },

  async anular(id) {
    if (!confirm('¿Anular esta devolución? Esta acción no se puede deshacer.')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/anular', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución anulada');
      this.showLista();
    } catch(e) { WMS.toast('error', 'Error al anular'); }
  },

  abrirProcesar(id) {
    const d = this._state.detalle;
    if (!d) return;
    const destOpts = `<option value="">-- Seleccionar --</option><option value="restock">Restock al inventario</option><option value="descarte">Descarte</option><option value="proveedor">→ Proveedor</option>`;
    const rows = (d.detalles||[]).map(det => `
      <tr>
        <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
        <td><code>${WMS.esc(det.lote||'-')}</code></td>
        <td class="text-center">${WMS.formatNum(det.cantidad)}</td>
        <td>${WMS.esc(det.condicion||'-')}</td>
        <td>
          <select class="form-control form-control-sm proc-dest" data-id="${det.id}" style="min-width:160px;">
            ${destOpts}
          </select>
        </td>
      </tr>`).join('');
    const html = `
      <div id="proc-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:24px 28px;min-width:600px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 16px;font-size:16px;"><i class="fa-solid fa-gears"></i> Procesar Devolución — ${WMS.esc(d.numero_devolucion)}</h3>
          <p style="font-size:12px;color:#64748b;margin-bottom:12px;">Asigna el destino de cada ítem antes de confirmar.</p>
          <table class="erp-table" style="font-size:12px;">
            <thead><tr><th>Producto</th><th>Lote</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('proc-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.confirmarProcesar(${id})">
              <i class="fa-solid fa-check"></i> Confirmar Procesamiento
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async confirmarProcesar(id) {
    const selects = document.querySelectorAll('.proc-dest');
    const items = [];
    let valid = true;
    selects.forEach(s => {
      if (!s.value) { valid = false; s.style.borderColor = '#dc2626'; }
      else { s.style.borderColor = ''; }
      items.push({ id: parseInt(s.dataset.id), destino: s.value });
    });
    if (!valid) { WMS.toast('error', 'Todos los ítems deben tener destino'); return; }

    document.getElementById('proc-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/devoluciones/' + id + '/procesar', { items });
      if (r.error) { WMS.toast('error', r.message); return; }
      let msg = 'Devolución procesada correctamente';
      if (r.data?.devolucion_proveedor_id) {
        msg += ` — Se creó automáticamente la devolución al proveedor.`;
      }
      WMS.toast('success', msg);
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al procesar'); }
  },

  // ── NUEVA DEVOLUCIÓN ──────────────────────────────────────────────────────

  showNueva() {
    this._state.items  = [];
    this._state.qrProd = null;
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>`);

    WMS.setContent(`
      <div class="card" style="max-width:860px;">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Nueva Devolución</span></div>
        <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
          <div>
            <label class="form-label">Tipo *</label>
            <select id="dv-new-tipo" class="form-control">
              <option value="cliente">Cliente → WMS</option>
              <option value="proveedor">WMS → Proveedor</option>
              <option value="interna">Interna</option>
            </select>
          </div>
          <div>
            <label class="form-label">Referencia ERP</label>
            <input type="text" id="dv-new-ref" class="form-control" placeholder="Ej: NC-12345">
          </div>
          <div style="grid-column:span 1;">
            <label class="form-label">Motivo general *</label>
            <input type="text" id="dv-new-motivo" class="form-control" placeholder="Razón de la devolución">
          </div>
        </div>

        <div style="padding:0 20px 20px;">
          <div class="card-header" style="border-radius:8px 8px 0 0;margin-bottom:0;">
            <span class="card-title" style="font-size:13px;"><i class="fa-solid fa-qrcode"></i> Agregar productos</span>
          </div>
          <div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:14px;">
            <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap;">
              <div style="flex:1;min-width:220px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-qrcode"></i> Escanear QR / EAN / Código interno
                </label>
                <input type="text" id="dv-qr-input" class="form-control" placeholder="Escanee QR o escriba código..."
                  onkeydown="if(event.key==='Enter'){WMS_MODULES.devoluciones.buscarQr();event.preventDefault();}">
              </div>
              <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.devoluciones.buscarQr()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
              </button>
            </div>
            <div id="dv-qr-found" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;">
              <strong id="dv-qr-nombre"></strong> — Lote: <span id="dv-qr-lote">-</span> / Vence: <span id="dv-qr-fv">-</span>
            </div>
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;">
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lote</label>
                <input type="text" id="dv-item-lote" class="form-control form-control-sm" placeholder="Lote">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Fecha Venc.</label>
                <input type="date" id="dv-item-fv" class="form-control form-control-sm">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Cantidad</label>
                <input type="number" id="dv-item-cant" class="form-control form-control-sm" min="0.001" step="0.001" placeholder="0">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Condición</label>
                <select id="dv-item-cond" class="form-control form-control-sm">
                  <option value="bueno">Bueno</option>
                  <option value="dañado">Dañado</option>
                  <option value="vencido">Vencido</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.agregarItem()" style="height:34px;">
                <i class="fa-solid fa-plus"></i>
              </button>
            </div>
          </div>
        </div>

        <div style="padding:0 20px 20px;">
          <div id="dv-items-table"></div>
        </div>

        <div style="padding:0 20px 24px;text-align:right;">
          <button class="btn btn-primary" onclick="WMS_MODULES.devoluciones.guardarNueva()">
            <i class="fa-solid fa-save"></i> Registrar Devolución
          </button>
        </div>
      </div>`);

    this._renderItemsTable();
  },

  async buscarQr() {
    const qr = document.getElementById('dv-qr-input')?.value?.trim();
    if (!qr) return;
    try {
      const r = await API.get('/recepciones/buscar-qr?q=' + encodeURIComponent(qr));
      if (r.error) { WMS.toast('error', r.message || 'Producto no encontrado'); return; }
      const p = r.data.producto;
      this._state.qrProd = { id: p.id, nombre: p.nombre, codigo: p.codigo_interno };
      document.getElementById('dv-qr-input').value = '';
      document.getElementById('dv-item-lote').value = r.data.lote_raw || '';
      document.getElementById('dv-item-fv').value   = r.data.fecha_vencimiento || '';
      document.getElementById('dv-qr-nombre').textContent = p.nombre;
      document.getElementById('dv-qr-lote').textContent   = r.data.lote_raw || '-';
      document.getElementById('dv-qr-fv').textContent     = r.data.fecha_vencimiento || '-';
      document.getElementById('dv-qr-found').style.display = 'block';
      document.getElementById('dv-item-cant').focus();
      WMS.toast('success', 'Producto: ' + p.nombre);
    } catch(e) { WMS.toast('error', 'Producto no encontrado'); }
  },

  agregarItem() {
    const prod = this._state.qrProd;
    if (!prod) { WMS.toast('error', 'Busque un producto primero'); return; }
    const cant = parseFloat(document.getElementById('dv-item-cant')?.value || 0);
    if (!cant || cant <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    this._state.items.push({
      producto_id:       prod.id,
      producto_nombre:   prod.nombre,
      codigo:            prod.codigo,
      lote:              document.getElementById('dv-item-lote')?.value || null,
      fecha_vencimiento: document.getElementById('dv-item-fv')?.value || null,
      cantidad:          cant,
      condicion:         document.getElementById('dv-item-cond')?.value || 'bueno',
    });
    this._state.qrProd = null;
    document.getElementById('dv-qr-found').style.display = 'none';
    document.getElementById('dv-qr-input').value = '';
    document.getElementById('dv-item-lote').value = '';
    document.getElementById('dv-item-fv').value   = '';
    document.getElementById('dv-item-cant').value = '';
    this._renderItemsTable();
  },

  _renderItemsTable() {
    const el = document.getElementById('dv-items-table');
    if (!el) return;
    if (!this._state.items.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:12px;">Sin ítems. Busque un producto arriba.</p>'; return; }
    el.innerHTML = `<table class="erp-table" style="font-size:12px;">
      <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th></th></tr></thead>
      <tbody>${this._state.items.map((it,i) => `<tr>
        <td>${WMS.esc(it.producto_nombre)}</td>
        <td><code>${WMS.esc(it.lote||'-')}</code></td>
        <td style="font-size:11px;">${it.fecha_vencimiento||'-'}</td>
        <td class="text-center fw-700">${WMS.formatNum(it.cantidad)}</td>
        <td>${WMS.esc(it.condicion)}</td>
        <td><button class="btn btn-danger" style="padding:2px 6px;font-size:10px;" onclick="WMS_MODULES.devoluciones._quitarItem(${i})">
          <i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('')}</tbody>
    </table>`;
  },

  _quitarItem(i) {
    this._state.items.splice(i, 1);
    this._renderItemsTable();
  },

  async guardarNueva() {
    const tipo   = document.getElementById('dv-new-tipo')?.value;
    const ref    = document.getElementById('dv-new-ref')?.value?.trim() || null;
    const motivo = document.getElementById('dv-new-motivo')?.value?.trim();
    if (!motivo) { WMS.toast('error', 'Ingrese el motivo general'); return; }
    if (!this._state.items.length) { WMS.toast('error', 'Agregue al menos un producto'); return; }
    WMS.spinner();
    try {
      const r = await API.post('/devoluciones', {
        tipo, referencia_externa: ref, motivo_general: motivo,
        detalles: this._state.items.map(it => ({
          producto_id: it.producto_id, lote: it.lote,
          fecha_vencimiento: it.fecha_vencimiento,
          cantidad: it.cantidad, condicion: it.condicion,
          motivo: 'Otro',
        })),
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución ' + r.data.numero + ' registrada. Pendiente de aprobación.');
      this.showDetalle(r.data.devolucion_id);
    } catch(e) { WMS.toast('error', 'Error al registrar'); }
  },

}; // end WMS_MODULES.devoluciones
