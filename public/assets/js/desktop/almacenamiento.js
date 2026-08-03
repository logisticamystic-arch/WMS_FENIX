/* ============================================================
   WMS Desktop — Módulo ALMACENAMIENTO  (rev 2)
   Fixes:
   - Mapa: filtros por zona/tipo/estado, capacidad_maxima en vez de capacidad,
     tipo_ubicacion==='Picking' en vez de en_picking,
     celdas ocupadas muestran producto/info
   - Transferir: lote y fecha_vencimiento dinámicos desde stock de origen
   ============================================================ */
WMS_MODULES.almacenamiento = {
  load(sub) {
    WMS.setBreadcrumb('almacenamiento', this.subLabel(sub));
    WMS.renderSidebar('almacenamiento');
    const s  = sub || 'ubicar';
    const fn = { ubicar: this.show_ubicar, transferir: this.show_transferir, traspaso: this.show_traspaso, mapa: this.show_mapa, bloqueos: this.show_bloqueos, informe: this.show_informe };
    (fn[s]?.bind(this) || fn.ubicar.bind(this))();
  },

  subLabel(s) {
    const m = { ubicar: 'Ubicar Mercancía', transferir: 'Transferencia', traspaso: 'Traspaso a Cliente', mapa: 'Ubicación Detalle', bloqueos: 'Bloqueo de Productos', informe: 'Informe de Traslados' };
    return m[s] || s || 'Panel';
  },

  // ── UBICAR (PUTAWAY) ─────────────────────────────────────────
  _patioItems: [],

  async show_ubicar() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_ubicar()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const r     = await API.get('/putaway/patio');
      this._patioItems = r.data || r || [];
      const items = this._patioItems;

      // Agrupar por numero_pallet
      const groups = {};
      items.forEach((item, idx) => {
        const key = item.numero_pallet != null ? String(item.numero_pallet) : '__sin_pallet__';
        if (!groups[key]) groups[key] = { pallet: item.numero_pallet, items: [], idxs: [], total: 0 };
        groups[key].items.push(item);
        groups[key].idxs.push(idx);
        groups[key].total += parseFloat(item.cantidad || 0);
      });

      const groupHtml = Object.entries(groups).map(([key, g]) => {
        const palletLabel = key !== '__sin_pallet__' ? `Pallet #${key}` : 'Artículos sin pallet';
        const refs = g.items.length;
        const rowsHtml = g.idxs.map(idx => {
          const item = items[idx];
          return `<tr data-pallet="${key}">
            <td style="font-family:monospace;font-size:.75rem;padding-left:28px;">${WMS.esc(item.codigo_interno || '-')}</td>
            <td><strong>${WMS.esc(item.producto_nombre || '-')}</strong></td>
            <td class="text-center fw-700">${WMS.formatNum(item.cantidad || 0)}</td>
            <td>${WMS.esc(item.unidad_medida || '-')}</td>
            <td>${WMS.esc(item.lote || 'N/A')}</td>
            <td>${item.fecha_vencimiento ? WMS.formatDate(item.fecha_vencimiento) : '-'}</td>
            <td style="font-size:.72rem;color:#64748b;">${WMS.esc(item.ubicacion_codigo || 'Patio')}</td>
            <td style="white-space:nowrap;">
              <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.almacenamiento.asignarUbicacion(${idx})">
                <i class="fa-solid fa-map-pin"></i> Ubicar
              </button>
            </td>
          </tr>`;
        }).join('');

        return `
          <tr class="pallet-group-header" style="background:#1e3a5f;color:#fff;cursor:pointer;" onclick="WMS_MODULES.almacenamiento.togglePalletGroup('${key}')">
            <td colspan="5" style="padding:10px 12px;font-weight:700;font-size:.85rem;">
              <i class="fa-solid fa-box-archive" style="margin-right:6px;"></i>
              ${WMS.esc(palletLabel)}
              <span style="background:rgba(255,255,255,.15);padding:1px 8px;border-radius:99px;font-size:.72rem;margin-left:8px;">${refs} ref${refs !== 1 ? 's' : ''} · ${WMS.formatNum(g.total)} und</span>
            </td>
            <td colspan="3" style="text-align:right;padding:8px 12px;">
              <button class="btn btn-sm" style="background:#fff;color:#1e3a5f;font-weight:700;font-size:.75rem;padding:4px 12px;"
                onclick="event.stopPropagation();WMS_MODULES.almacenamiento.ubicarTodoPallet('${key}')">
                <i class="fa-solid fa-layer-group"></i> Ubicar Todo el Pallet
              </button>
              <i class="fa-solid fa-chevron-down toggle-pg-icon" style="margin-left:8px;transition:transform .2s;"></i>
            </td>
          </tr>
          ${rowsHtml}`;
      }).join('');

      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar producto, lote, pallet..." oninput="WMS_MODULES.almacenamiento.filterUbicar(this.value)">
          </div>
          <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.almacenamiento.escanearUbicar()"><i class="fa-solid fa-barcode"></i> Escanear EAN</button>
        </div>
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Mercancía Pendiente de Ubicar (${items.length} artículos · ${Object.keys(groups).length} pallet${Object.keys(groups).length !== 1 ? 's' : ''})</span>
          </div>
          <div class="table-container">
            <table class="erp-table" id="ub-table">
              <thead><tr><th>Código</th><th>Producto</th><th>Cantidad</th><th>Unidad</th><th>Lote</th><th>F. Venc.</th><th>Patio</th><th>Acción</th></tr></thead>
              <tbody>
                ${groupHtml || '<tr><td colspan="8" class="table-empty"><i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sin mercancía pendiente de ubicar</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  togglePalletGroup(key) {
    const rows = document.querySelectorAll(`#ub-table tr[data-pallet="${key}"]`);
    rows.forEach(r => { r.style.display = r.style.display === 'none' ? '' : 'none'; });
    const headers = document.querySelectorAll('#ub-table .pallet-group-header');
    headers.forEach(h => {
      if (h.textContent.includes(key === '__sin_pallet__' ? 'sin pallet' : `#${key}`)) {
        const icon = h.querySelector('.toggle-pg-icon');
        if (icon) icon.style.transform = icon.style.transform === 'rotate(180deg)' ? '' : 'rotate(180deg)';
      }
    });
  },

  filterUbicar(q) {
    const rows = document.querySelectorAll('#ub-table tbody tr:not(.pallet-group-header)');
    const f = q.toLowerCase();
    const visiblePallets = new Set();
    rows.forEach(r => {
      const match = r.textContent.toLowerCase().includes(f);
      r.style.display = match ? '' : 'none';
      if (match) {
        const pkey = r.getAttribute('data-pallet');
        if (pkey) visiblePallets.add(pkey);
      }
    });
    // Show/hide pallet headers based on whether they have visible rows
    document.querySelectorAll('#ub-table .pallet-group-header').forEach(h => {
      const txt = h.textContent;
      const hasRows = [...visiblePallets].some(pk => txt.includes(pk === '__sin_pallet__' ? 'sin pallet' : `#${pk}`));
      h.style.display = (!f || hasRows) ? '' : 'none';
    });
  },

  async ubicarTodoPallet(palletKey) {
    const items = this._patioItems.filter((item, idx) => {
      const k = item.numero_pallet != null ? String(item.numero_pallet) : '__sin_pallet__';
      return k === palletKey;
    });
    if (!items.length) return WMS.toast('warning', 'No se encontraron artículos para este pallet');

    const label = palletKey !== '__sin_pallet__' ? `Pallet #${palletKey}` : 'artículos sin pallet';
    let ubis = [];
    try {
      // Antes filtraba por tipo_ubicacion=Almacenamiento (exact match en el backend) —
      // esta sucursal no tiene ninguna ubicación de ese tipo (solo Patio y Picking), así
      // que el combo de destino siempre salía vacío. Se trae todo y se excluye Patio aquí.
      const ru = await API.get('/param/ubicaciones', 'activo=1&limit=500');
      ubis = (ru.data || ru || []).filter(u => u.tipo_ubicacion !== 'Patio');
    } catch (e) {}

    const itemsHtml = items.map(i =>
      `<tr><td style="padding:4px 8px;font-size:.78rem;font-weight:600;">${WMS.esc(i.producto_nombre)}</td>
       <td style="padding:4px 8px;font-size:.78rem;text-align:center;">${WMS.formatNum(i.cantidad)} ${WMS.esc(i.unidad_medida||'')}</td>
       <td style="padding:4px 8px;font-size:.78rem;color:#64748b;">${WMS.esc(i.lote||'N/A')}</td></tr>`
    ).join('');

    this._palletMoveItems = items;

    WMS.showModal(`Ubicar ${label}`, `
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px;margin-bottom:16px;">
        <div style="font-weight:700;color:#1e40af;margin-bottom:8px;"><i class="fa-solid fa-box-archive"></i> Referencias en este pallet</div>
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#dbeafe;"><th style="padding:4px 8px;font-size:.72rem;text-align:left;">Producto</th><th style="padding:4px 8px;font-size:.72rem;">Cantidad</th><th style="padding:4px 8px;font-size:.72rem;text-align:left;">Lote</th></tr></thead>
          <tbody>${itemsHtml}</tbody>
        </table>
      </div>
      <div class="form-group">
        <label class="form-label">Ubicación Destino <span class="required">*</span></label>
        ${this._buildUbicDestino('ubp-ubicacion', ubis)}
        <div style="font-size:.7rem;color:#64748b;margin-top:4px;"><i class="fa-solid fa-barcode"></i> Escanee el código o escriba para filtrar · Enter confirma coincidencia única</div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.confirmarUbicacionPallet()">
         <i class="fa-solid fa-layer-group"></i> Ubicar Todo el Pallet
       </button>`);
  },

  async confirmarUbicacionPallet() {
    const items = this._palletMoveItems;
    if (!items?.length) return WMS.toast('error', 'No hay artículos seleccionados');
    const ubi_id = document.getElementById('ubp-ubicacion-id')?.value;
    if (!ubi_id) return WMS.toast('warning', 'Seleccione una ubicación de destino');

    try {
      for (const item of items) {
        await API.post('/inventario/traslado', {
          producto_id:          item.producto_id,
          ubicacion_origen_id:  item.ubicacion_id,
          ubicacion_destino_id: parseInt(ubi_id),
          cantidad:             item.cantidad,
          lote:                 item.lote || null,
          fecha_vencimiento:    item.fecha_vencimiento || null,
          numero_pallet:        item.numero_pallet || null,
        });
      }
      this._palletMoveItems = null;
      WMS.closeModal('generic-modal');
      WMS.toast('success', `${items.length} referencia${items.length !== 1 ? 's' : ''} ubicadas correctamente`);
      this.show_ubicar();
    } catch (e) { WMS.toast('error', e.message || 'Error al ubicar pallet'); }
  },

  filterTable(q, tableId) {
    const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
    const f    = q.toLowerCase();
    rows.forEach(r => { r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none'; });
  },

  escanearUbicar() {
    WMS.showModal('Escanear Producto para Ubicar', `
      <div style="text-align:center;padding:20px 0;">
        <i class="fa-solid fa-barcode" style="font-size:3rem;color:#3b82f6;opacity:.6;margin-bottom:16px;display:block;"></i>
        <p class="text-muted" style="margin-bottom:16px;">Ingrese o escanee el código EAN del producto</p>
        <input id="scan-ean-ub" class="form-control" style="max-width:300px;margin:0 auto;text-align:center;font-size:1.2rem;letter-spacing:4px;" placeholder="0000000000000" autofocus>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.resolverEAN()"><i class="fa-solid fa-search"></i> Buscar</button>`);
    const inp = document.getElementById('scan-ean-ub');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') this.resolverEAN(); });
  },

  async resolverEAN() {
    const ean = document.getElementById('scan-ean-ub')?.value.trim();
    if (!ean) return;
    try {
      const r = await API.get('/putaway/resolver-ean', 'ean=' + encodeURIComponent(ean));
      if (r.error || !r.producto) { WMS.toast('warning', 'EAN no encontrado: ' + ean); return; }
      const prodId = r.producto.id;
      const idx = this._patioItems.findIndex(i => i.producto_id == prodId);
      WMS.closeModal('generic-modal');
      if (idx < 0) { WMS.toast('warning', 'Producto encontrado pero no está en patio'); return; }
      this.asignarUbicacion(idx);
    } catch (e) { WMS.toast('error', 'Error resolviendo EAN'); }
  },

  _currentPutawayItem: null,
  _ubData: {},

  // ── UBICACIÓN DESTINO: scan/search combo ─────────────────────
  _buildUbicDestino(inputId, ubis) {
    this._ubData[inputId] = ubis;
    const hidId = `${inputId}-id`;
    return `
      <div style="position:relative;" id="${inputId}-wrap">
        <div style="position:relative;">
          <i class="fa-solid fa-barcode" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#3b82f6;z-index:1;pointer-events:none;font-size:.9rem;"></i>
          <input id="${inputId}" type="text" class="form-control" style="padding-left:34px;"
            placeholder="Escanee código o escriba para buscar..." autocomplete="off"
            oninput="WMS_MODULES.almacenamiento._filterUbicSuggestions('${inputId}','${hidId}')"
            onkeydown="WMS_MODULES.almacenamiento._ubKeydown(event,'${inputId}','${hidId}')"
            onfocus="WMS_MODULES.almacenamiento._filterUbicSuggestions('${inputId}','${hidId}')">
          <input type="hidden" id="${hidId}">
        </div>
        <div id="${inputId}-drop" style="display:none;position:absolute;z-index:9999;width:100%;background:#fff;border:1px solid #cbd5e1;border-top:none;border-radius:0 0 6px 6px;max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);"></div>
        <div id="${inputId}-sel" style="display:none;margin-top:6px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;font-size:.85rem;justify-content:space-between;align-items:center;">
          <span><i class="fa-solid fa-map-pin" style="color:#3b82f6;margin-right:6px;"></i><strong id="${inputId}-lbl"></strong></span>
          <button type="button" onclick="WMS_MODULES.almacenamiento._clearUbic('${inputId}','${hidId}')"
            style="background:none;border:none;color:#64748b;cursor:pointer;font-size:.75rem;padding:0 4px;">
            <i class="fa-solid fa-times"></i> Cambiar
          </button>
        </div>
      </div>`;
  },

  _filterUbicSuggestions(inputId, hidId) {
    if (document.getElementById(hidId)?.value) return;
    const q = document.getElementById(inputId)?.value?.toLowerCase() || '';
    const drop = document.getElementById(`${inputId}-drop`);
    if (!drop) return;
    const ubis = this._ubData[inputId] || [];
    const matches = q
      ? ubis.filter(u => (`${u.codigo||''} ${u.zona||''} ${u.tipo_ubicacion||''}`).toLowerCase().includes(q)).slice(0, 25)
      : ubis.slice(0, 25);
    if (!matches.length) {
      drop.innerHTML = `<div style="padding:10px 14px;color:#94a3b8;font-size:.82rem;"><i class="fa-solid fa-magnifying-glass"></i> Sin resultados</div>`;
      drop.style.display = 'block';
      return;
    }
    drop.innerHTML = matches.map(u => {
      const lbl = `${u.codigo||''} — ${u.zona||''} (${u.tipo_ubicacion||''})`;
      return `<div style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.83rem;"
        data-ub-id="${u.id}" data-ub-cod="${WMS.esc(u.codigo||'')}" data-ub-lbl="${WMS.esc(lbl)}"
        onmousedown="WMS_MODULES.almacenamiento._selectUbicEl(this,'${inputId}','${hidId}')"
        onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background=''">
        <i class="fa-solid fa-map-pin" style="color:#3b82f6;margin-right:6px;font-size:.72rem;"></i>
        <strong>${WMS.esc(u.codigo||'')}</strong>
        <span style="color:#64748b;margin-left:6px;">${WMS.esc(u.zona||'')} · ${WMS.esc(u.tipo_ubicacion||'')}</span>
      </div>`;
    }).join('');
    drop.style.display = 'block';
  },

  _selectUbicEl(el, inputId, hidId) {
    this._selectUbic(inputId, hidId, el.dataset.ubId, el.dataset.ubCod, el.dataset.ubLbl);
  },

  _selectUbic(inputId, hidId, id, codigo, label) {
    document.getElementById(hidId).value = id;
    const inp = document.getElementById(inputId);
    if (inp) { inp.value = codigo; inp.blur(); }
    const drop = document.getElementById(`${inputId}-drop`);
    if (drop) drop.style.display = 'none';
    const sel = document.getElementById(`${inputId}-sel`);
    const lbl = document.getElementById(`${inputId}-lbl`);
    if (sel && lbl) { lbl.textContent = label; sel.style.display = 'flex'; }
  },

  _clearUbic(inputId, hidId) {
    document.getElementById(hidId).value = '';
    const inp = document.getElementById(inputId);
    if (inp) { inp.value = ''; setTimeout(() => inp.focus(), 50); }
    const sel = document.getElementById(`${inputId}-sel`);
    if (sel) sel.style.display = 'none';
    const drop = document.getElementById(`${inputId}-drop`);
    if (drop) drop.style.display = 'none';
  },

  _ubKeydown(event, inputId, hidId) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const q = document.getElementById(inputId)?.value?.toLowerCase() || '';
    if (!q) return;
    const ubis = this._ubData[inputId] || [];
    const matches = ubis.filter(u => (`${u.codigo||''} ${u.zona||''} ${u.tipo_ubicacion||''}`).toLowerCase().includes(q));
    if (matches.length === 1) {
      const u = matches[0];
      this._selectUbic(inputId, hidId, u.id, u.codigo || '', `${u.codigo||''} — ${u.zona||''} (${u.tipo_ubicacion||''})`);
    } else {
      const drop = document.getElementById(`${inputId}-drop`);
      if (drop && drop.firstElementChild) drop.firstElementChild.focus();
    }
  },

  async asignarUbicacion(idx) {
    const item = this._patioItems[idx];
    if (!item) return WMS.toast('error', 'Item no encontrado');
    this._currentPutawayItem = item;

    let ubis = [];
    try {
      // Antes filtraba por tipo_ubicacion=Almacenamiento (exact match en el backend) —
      // esta sucursal no tiene ninguna ubicación de ese tipo (solo Patio y Picking), así
      // que el combo de destino siempre salía vacío. Se trae todo y se excluye Patio aquí.
      const ru = await API.get('/param/ubicaciones', 'activo=1&limit=500');
      ubis = (ru.data || ru || []).filter(u => u.tipo_ubicacion !== 'Patio');
    } catch (e) {}

    const palletInfo = item.numero_pallet ? `Pallet #${item.numero_pallet} · ` : '';
    // Disponible real = cantidad - cantidad_reservada. Antes se mostraba y se
    // usaba como máximo la cantidad cruda, así que un item con todo (o parte)
    // reservado mostraba "Disponible: 42000" pero el traslado fallaba con
    // "Disponible: 0" al validar contra el stock realmente libre.
    const reservada  = parseFloat(item.cantidad_reservada || 0);
    const disponible = Math.max(0, parseFloat(item.cantidad || 0) - reservada);
    this._currentPutawayItem = { ...item, _disponible: disponible };
    WMS.showModal(`Ubicar: ${WMS.esc(item.producto_nombre || '-')}`, `
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px;margin-bottom:16px;font-size:13px;">
        <div style="font-weight:700;color:#1e40af;margin-bottom:4px;">${palletInfo}${WMS.esc(item.codigo_interno || '')} — ${WMS.esc(item.producto_nombre)}</div>
        <div style="display:flex;gap:20px;color:#1d4ed8;flex-wrap:wrap;">
          <span><i class="fa-solid fa-layer-group"></i> Disponible: <strong>${WMS.formatNum(disponible)}</strong> ${WMS.esc(item.unidad_medida||'und')}</span>
          ${reservada > 0 ? `<span style="color:#d97706;"><i class="fa-solid fa-lock"></i> Reservado: <strong>${WMS.formatNum(reservada)}</strong></span>` : ''}
          <span><i class="fa-solid fa-tag"></i> Lote: <strong>${WMS.esc(item.lote||'N/A')}</strong></span>
          <span><i class="fa-solid fa-location-dot"></i> Origen: <strong>${WMS.esc(item.ubicacion_codigo||'Patio')}</strong></span>
        </div>
      </div>
      <div class="form-grid form-grid-2">
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">Ubicación Destino <span class="required">*</span></label>
          ${this._buildUbicDestino('ub-ubicacion', ubis)}
          <div style="font-size:.7rem;color:#64748b;margin-top:4px;"><i class="fa-solid fa-barcode"></i> Escanee el código o escriba para filtrar · Enter confirma coincidencia única</div>
        </div>
        <div class="form-group">
          <label class="form-label">Cantidad a Ubicar <span class="required">*</span></label>
          <input id="ub-cantidad" type="number" class="form-control" min="1" max="${disponible}" value="${disponible}" placeholder="0" ${disponible<=0?'disabled':''}>
          <div style="font-size:.7rem;color:#64748b;margin-top:3px;">Máximo: ${WMS.formatNum(disponible)} ${WMS.esc(item.unidad_medida||'und')}</div>
        </div>
        <div class="form-group">
          <label class="form-label">Fecha Vencimiento</label>
          <input id="ub-fv" type="date" class="form-control" value="${item.fecha_vencimiento||''}">
        </div>
      </div>
      ${disponible<=0 ? `<div style="margin-top:10px;padding:10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#991b1b;font-size:.8rem;"><i class="fa-solid fa-triangle-exclamation"></i> Todo el stock de esta partida está reservado — no hay cantidad disponible para trasladar.</div>` : ''}`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.confirmarUbicacion()" ${disponible<=0?'disabled':''}><i class="fa-solid fa-map-pin"></i> Confirmar Ubicación</button>`);
  },

  async confirmarUbicacion() {
    const item    = this._currentPutawayItem;
    if (!item) return WMS.toast('error', 'No hay item seleccionado');

    const ubi_id  = document.getElementById('ub-ubicacion-id')?.value;
    const cantidad = parseFloat(document.getElementById('ub-cantidad')?.value || 0);
    const fv       = document.getElementById('ub-fv')?.value;
    const disponible = item._disponible ?? item.cantidad;

    if (!ubi_id) return WMS.toast('warning', 'Seleccione una ubicación de destino');
    if (cantidad <= 0) return WMS.toast('warning', 'La cantidad debe ser mayor a cero');
    if (cantidad > disponible) return WMS.toast('warning', `La cantidad no puede superar ${WMS.formatNum(disponible)} (stock reservado excluido)`);
    if (!item.ubicacion_id) return WMS.toast('error', 'El ítem no tiene ubicación de origen registrada');

    try {
      const r = await API.post('/inventario/traslado', {
        producto_id:          item.producto_id,
        ubicacion_origen_id:  item.ubicacion_id,
        ubicacion_destino_id: parseInt(ubi_id),
        cantidad,
        lote:                 item.lote || null,
        fecha_vencimiento:    fv || item.fecha_vencimiento || null,
        numero_pallet:        item.numero_pallet || null,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      const restante = item.cantidad - cantidad;
      WMS.closeModal('generic-modal');
      if (restante > 0) {
        WMS.toast('success', `${WMS.formatNum(cantidad)} und ubicadas. Quedan ${WMS.formatNum(restante)} und en patio.`);
      } else {
        WMS.toast('success', 'Mercancía ubicada completamente');
      }
      this.show_ubicar();
    } catch (e) { WMS.toast('error', e.message || 'Error al ubicar'); }
  },

  // ── TRANSFERIR ───────────────────────────────────────────────
  _trProd: null,          // producto seleccionado en traslado
  _trStockOrigen: 0,      // stock total disponible en origen

  async show_transferir() {
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_transferir()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const [stock, ubis] = await Promise.all([
        API.get('/inventario/stock', 'limit=200'),
        API.get('/param/ubicaciones', 'activo=1&limit=5000'),
      ]);
      const items      = stock.data || stock || [];
      const listaUbis  = ubis.data || ubis || [];
      const ubisOptions = listaUbis.map(u => `<option value="${u.id}">${WMS.esc(u.codigo || '')} — ${WMS.esc(u.zona || '')} (${WMS.esc(u.tipo_ubicacion || '')})</option>`).join('');

      WMS.setContent(`
        <div class="card">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Transferencia de Mercancía</span>
          </div>
          <div class="card-body">
            <div class="form-grid form-grid-2">
              <div class="form-group">
                <label class="form-label">Producto / EAN <span class="required">*</span></label>
                <input id="tr-prod-ac" class="form-control" placeholder="Nombre o EAN...">
                <input type="hidden" id="tr-prod-id">
                <input type="hidden" id="tr-prod-upc" value="1">
              </div>
              <!-- Campos de cantidad: se reemplazan dinámicamente según UPC del producto -->
              <div id="tr-cantidad-wrap" class="form-group">
                <label class="form-label">Cantidad <span class="required">*</span></label>
                <input id="tr-cantidad" type="number" class="form-control" min="1" step="1" placeholder="0">
              </div>
              <div class="form-group">
                <label class="form-label">Ubicación Origen</label>
                <select id="tr-origen" class="form-control" onchange="WMS_MODULES.almacenamiento.cargarLotesOrigen()">
                  <option value="">Seleccionar origen (opcional)...</option>
                  ${ubisOptions}
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Ubicación Destino <span class="required">*</span></label>
                <select id="tr-destino" class="form-control">
                  <option value="">Seleccionar destino...</option>
                  ${ubisOptions}
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Lote <span class="text-muted" style="font-weight:400;">(lista del origen al seleccionar)</span></label>
                <select id="tr-lote" class="form-control">
                  <option value="">Sin lote / Todos</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Fecha Vencimiento <span class="text-muted" style="font-weight:400;">(del origen)</span></label>
                <select id="tr-fv" class="form-control">
                  <option value="">Sin fecha específica</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Observaciones</label>
                <input id="tr-obs" class="form-control" placeholder="Motivo del traslado...">
              </div>
            </div>
            <!-- Preview UND/TOTAL — visible solo cuando hay producto con UPC > 1 -->
            <div id="tr-preview" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:9px 14px;margin-bottom:12px;font-size:.84rem;"></div>
            <div style="text-align:right;margin-top:16px;">
              <button class="btn btn-primary" onclick="WMS_MODULES.almacenamiento.ejecutarTraslado()">
                <i class="fa-solid fa-arrow-right-arrow-left"></i> Ejecutar Traslado
              </button>
            </div>
          </div>
        </div>
        <div class="card mt-16">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Stock Disponible para Traslado</span></div>
          <div class="filter-bar"><div class="search-bar"><i class="fa-solid fa-search"></i><input placeholder="Buscar..." oninput="WMS_MODULES.almacenamiento.filterTable(this.value,'tr-stock-table')"></div></div>
          <div class="table-container">
            <table class="erp-table" id="tr-stock-table">
              <thead><tr><th>Producto</th><th>Ubicación</th><th>Cantidad</th><th>Lote</th><th>Vencimiento</th></tr></thead>
              <tbody>${items.slice(0, 50).map(s => `<tr style="cursor:pointer;" title="Haga clic para seleccionar este producto y ubicación" onclick="WMS_MODULES.almacenamiento.seleccionarStockTraslado(${s.producto_id || 0}, ${s.ubicacion_id || 0}, '${WMS.esc(s.descripcion || s.producto || '').replace(/'/g, "\\'")}', '${WMS.esc(s.lote || '')}', '${s.fecha_vencimiento || ''}')">
                <td><i class="fa-solid fa-hand-pointer text-muted" style="font-size:10px;margin-right:4px;"></i> ${WMS.esc(s.descripcion || s.producto || '-')}</td>
                <td><span class="badge badge-info">${WMS.esc(s.ubicacion || s.ubicacion_codigo || '-')}</span></td>
                <td class="text-center fw-600">${WMS.formatNum(s.cantidad || 0)}</td>
                <td>${WMS.esc(s.lote || '-')}</td>
                <td>${WMS.formatDate(s.fecha_vencimiento) || '-'}</td>
              </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin stock disponible</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);

      this._trProd = null;
      this._trStockOrigen = 0;

      WMS.initProductAutocomplete(document.getElementById('tr-prod-ac'), p => {
        document.getElementById('tr-prod-id').value = p.id;
        const upc = Math.max(1, parseInt(p.unidades_caja) || 1);
        document.getElementById('tr-prod-upc').value = upc;
        WMS_MODULES.almacenamiento._trProd = p;
        WMS_MODULES.almacenamiento._trRenderCantidadInputs(upc);
        WMS_MODULES.almacenamiento.cargarLotesOrigen();
      });
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  seleccionarStockTraslado(prodId, ubiId, prodNombre, lote, fv) {
    if (!prodId || !ubiId) return;
    const ac = document.getElementById('tr-prod-ac');
    const idEl = document.getElementById('tr-prod-id');
    const origEl = document.getElementById('tr-origen');
    if (ac) ac.value = prodNombre;
    if (idEl) idEl.value = prodId;
    if (origEl) origEl.value = ubiId;

    this.cargarLotesOrigen().then(() => {
      if (lote) {
        const loteEl = document.getElementById('tr-lote');
        if (loteEl) loteEl.value = lote;
      }
      if (fv) {
        const fvEl = document.getElementById('tr-fv');
        if (fvEl) fvEl.value = fv;
      }
    });

    WMS.toast('info', 'Origen y producto cargados en el formulario');
  },

  /** Reemplaza el bloque de inputs de cantidad según UPC del producto */
  _trRenderCantidadInputs(upc) {
    const wrap = document.getElementById('tr-cantidad-wrap');
    if (!wrap) return;
    if (upc > 1) {
      wrap.innerHTML = `
        <label class="form-label">Cantidad <span class="required">*</span></label>
        <div style="display:flex;gap:8px;">
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Cajas</label>
            <input id="tr-cajas" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.almacenamiento._trCalcPreview()" placeholder="0">
          </div>
          <div style="flex:1;">
            <label style="font-size:.75rem;color:#64748b;margin-bottom:2px;display:block;">Sueltos</label>
            <input id="tr-saldos" type="number" class="form-control" min="0" step="1" value="0"
              oninput="WMS_MODULES.almacenamiento._trCalcPreview()" placeholder="0">
          </div>
        </div>
        <input type="hidden" id="tr-cantidad" value="0">`;
    } else {
      wrap.innerHTML = `
        <label class="form-label">Cantidad <span class="required">*</span></label>
        <input id="tr-cantidad" type="number" class="form-control" min="1" step="1" placeholder="0"
          oninput="WMS_MODULES.almacenamiento._trCalcPreview()">
        <input type="hidden" id="tr-cajas" value="0">
        <input type="hidden" id="tr-saldos" value="0">`;
    }
    this._trCalcPreview();
  },

  /** Recalcula UND/TOTAL y actualiza el preview + campo oculto tr-cantidad */
  _trCalcPreview() {
    const p   = this._trProd;
    const upc = Math.max(1, parseInt(document.getElementById('tr-prod-upc')?.value || '1') || 1);
    const preview = document.getElementById('tr-preview');

    if (upc > 1) {
      const cajas  = parseFloat(document.getElementById('tr-cajas')?.value  || '0') || 0;
      const saldos = parseFloat(document.getElementById('tr-saldos')?.value || '0') || 0;
      const total  = cajas * upc + saldos;
      // Actualizar campo oculto tr-cantidad con el total calculado
      const cantEl = document.getElementById('tr-cantidad');
      if (cantEl) cantEl.value = total;

      if (preview) {
        const maxInfo = this._trStockOrigen > 0
          ? ` · <span style="color:#64748b;">Disponibles: <b>${WMS.formatNum(this._trStockOrigen)}</b> und en origen</span>`
          : '';
        preview.style.display = 'block';
        preview.innerHTML = `<b>UND/TOTAL:</b> ${cajas} cajas × ${upc} u/caja + ${saldos} sueltos = `
          + `<b style="color:#1e40af;font-size:1.05em;">${total.toFixed(2)}</b>${maxInfo}`;
        preview.style.borderColor = (this._trStockOrigen > 0 && total > this._trStockOrigen) ? '#fca5a5' : '#bfdbfe';
        preview.style.background  = (this._trStockOrigen > 0 && total > this._trStockOrigen) ? '#fef2f2' : '#eff6ff';
      }
    } else {
      const total = parseFloat(document.getElementById('tr-cantidad')?.value || '0') || 0;
      if (preview && this._trStockOrigen > 0) {
        preview.style.display = 'block';
        preview.innerHTML = `<span style="color:#64748b;">Disponibles en origen: <b>${WMS.formatNum(this._trStockOrigen)}</b> und</span>`;
        preview.style.borderColor = total > this._trStockOrigen ? '#fca5a5' : '#bfdbfe';
        preview.style.background  = total > this._trStockOrigen ? '#fef2f2' : '#eff6ff';
      } else if (preview) {
        preview.style.display = 'none';
      }
    }
  },

  // Carga lotes y fechas de vencimiento disponibles desde el stock de origen
  async cargarLotesOrigen() {
    const prod_id = document.getElementById('tr-prod-id')?.value;
    const orig_id = document.getElementById('tr-origen')?.value;
    const loteEl  = document.getElementById('tr-lote');
    const fvEl    = document.getElementById('tr-fv');
    if (!loteEl || !fvEl) return;

    if (!prod_id || !orig_id) {
      loteEl.innerHTML = '<option value="">Sin lote / Todos</option>';
      fvEl.innerHTML   = '<option value="">Sin fecha específica</option>';
      this._trStockOrigen = 0;
      this._trCalcPreview();
      return;
    }

    try {
      const r     = await API.get('/inventario/stock', `producto_id=${prod_id}&ubicacion_id=${orig_id}&limit=50`);
      const items = r.data || r || [];
      if (!items.length) {
        loteEl.innerHTML = '<option value="">⚠ Sin stock en esta ubicación</option>';
        fvEl.innerHTML   = '<option value="">-</option>';
        this._trStockOrigen = 0;
        this._trCalcPreview();
        WMS.toast('warning', 'No hay stock de ese producto en la ubicación seleccionada');
        return;
      }

      // Total stock disponible en origen (para validación max)
      this._trStockOrigen = items.reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
      this._trCalcPreview();

      // Poblar lotes (únicos)
      const lotes = [...new Set(items.map(s => s.lote || '').filter(Boolean))];
      loteEl.innerHTML = '<option value="">Sin lote específico</option>' +
        lotes.map(l => {
          const totalLote = items.filter(s => s.lote === l).reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
          return `<option value="${WMS.esc(l)}">${WMS.esc(l)} (${WMS.formatNum(totalLote)} uds)</option>`;
        }).join('');

      // Poblar fechas de vencimiento (únicas, ordenadas)
      const fvs = [...new Set(items.map(s => s.fecha_vencimiento || '').filter(Boolean))].sort();
      fvEl.innerHTML = '<option value="">Sin fecha específica</option>' +
        fvs.map(f => {
          const totalFv = items.filter(s => s.fecha_vencimiento === f).reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
          return `<option value="${WMS.esc(f)}">${WMS.formatDate(f)} (${WMS.formatNum(totalFv)} uds)</option>`;
        }).join('');
    } catch (e) {
      loteEl.innerHTML = '<option value="">Error cargando lotes</option>';
      fvEl.innerHTML   = '<option value="">Error cargando fechas</option>';
    }
  },

  async ejecutarTraslado() {
    const prod_id  = document.getElementById('tr-prod-id')?.value;
    const upc      = Math.max(1, parseInt(document.getElementById('tr-prod-upc')?.value || '1') || 1);
    const dest_id  = document.getElementById('tr-destino')?.value;

    // Leer cajas / saldos / cantidad según modo
    let cajas, saldos, cantidad;
    if (upc > 1) {
      cajas  = Math.floor(parseFloat(document.getElementById('tr-cajas')?.value  || '0') || 0);
      saldos = parseFloat(document.getElementById('tr-saldos')?.value || '0') || 0;
      cantidad = cajas * upc + saldos;
    } else {
      cajas  = 0;
      saldos = 0;
      cantidad = parseFloat(document.getElementById('tr-cantidad')?.value || '0') || 0;
    }

    if (!prod_id)                { WMS.toast('warning', 'Seleccione un producto'); return; }
    if (!cantidad || cantidad <= 0) { WMS.toast('warning', 'Ingrese una cantidad válida'); return; }
    if (!dest_id)                { WMS.toast('warning', 'Seleccione la ubicación destino'); return; }

    // Validar stock máximo disponible en origen
    if (this._trStockOrigen > 0 && cantidad > this._trStockOrigen) {
      WMS.toast('warning', `La cantidad (${WMS.formatNum(cantidad)}) supera el stock disponible en origen (${WMS.formatNum(this._trStockOrigen)})`);
      return;
    }

    const orig_id = document.getElementById('tr-origen')?.value;
    const lote    = document.getElementById('tr-lote')?.value || null;
    const fv      = document.getElementById('tr-fv')?.value || null;
    try {
      const r = await API.post('/inventario/traslado', {
        producto_id:          parseInt(prod_id),
        ubicacion_origen_id:  orig_id ? parseInt(orig_id) : null,
        ubicacion_destino_id: parseInt(dest_id),
        cantidad,
        cantidad_cajas:       cajas,
        saldos,
        lote,
        fecha_vencimiento:    fv,
        observaciones:        document.getElementById('tr-obs')?.value.trim() || null,
        tipo:                 'Traslado',
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Traslado ejecutado'); this.show_transferir(); }
    } catch (e) { WMS.toast('error', 'Error ejecutando traslado'); }
  },

  // ── UBICACIÓN DETALLE (TABLA) ──────────────────────────
  _mapaData: null,

  async show_mapa() {
    WMS.setToolbar(`
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:180px;">
          <i class="fa-solid fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;font-size:.85rem;"></i>
          <input id="mapa-prod-ac" class="form-control" style="padding-left:32px;" placeholder="Buscar ubicación, zona o producto..." oninput="WMS_MODULES.almacenamiento.filterMapaTable(this.value)">
        </div>
        <select id="mapa-zona-f" class="form-control" style="max-width:130px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">Todas las zonas</option>
        </select>
        <select id="mapa-tipo-f" class="form-control" style="max-width:148px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">Todos los tipos</option>
          <option value="Almacenamiento">Almacenamiento</option>
          <option value="Picking">Picking</option>
        </select>
        <select id="mapa-estado-f" class="form-control" style="max-width:138px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">Todos los estados</option>
          <option value="Libre">🟢 Libre</option>
          <option value="Parcial">🟡 Parcial</option>
          <option value="Ocupada">🔵 Ocupada</option>
          <option value="Locked">🔴 Bloqueada</option>
        </select>
        <select id="mapa-ocup-f" class="form-control" style="max-width:155px;" onchange="WMS_MODULES.almacenamiento.renderMapa()">
          <option value="">Todas las ocupaciones</option>
          <option value="empty">Vacías (0%)</option>
          <option value="partial">Parciales (1–99%)</option>
          <option value="full">Llenas (≥100%)</option>
          <option value="reservado">Con stock reservado</option>
        </select>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.limpiarFiltrosMapa()"><i class="fa-solid fa-eraser"></i> Limpiar</button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_mapa()"><i class="fa-solid fa-rotate"></i></button>
      </div>`);
    
    WMS.spinner();
    try {
      const prodId = document.getElementById('mapa-prod-id')?.value || '';
      const r = await API.get('/inventario/mapa-detallado', prodId ? `producto_id=${prodId}` : '');
      this._mapaData = r.data || r || [];

      // Poblar filtro de zonas con valores reales de los datos
      const zonaF = document.getElementById('mapa-zona-f');
      if (zonaF) {
        const zonas = [...new Set(this._mapaData.map(i => i.zona).filter(z => z))].sort();
        zonas.forEach(z => {
          const o = document.createElement('option');
          o.value = z; o.textContent = `Zona ${z}`;
          zonaF.appendChild(o);
        });
      }

      WMS.setContent(`
        <div class="md-container">
          <!-- Master View -->
          <div class="md-master erp-card">
            <table class="erp-table" id="mapa-table">
              <thead>
                <tr>
                  <th>Ubicación</th>
                  <th>Zona</th>
                  <th>Tipo</th>
                  <th>Estado</th>
                  <th style="text-align:center;">Refs</th>
                  <th>% Ocupación</th>
                  <th style="text-align:center;">Und. / Reservado</th>
                  <th>Próx. Venc.</th>
                  <th style="text-align:center;">Días sin mov.</th>
                </tr>
              </thead>
              <tbody id="mapa-tbody">
                <!-- Se llena en renderMapa -->
              </tbody>
            </table>
          </div>

          <!-- Side Panel / Drawer View -->
          <div id="ubicacion-drawer" class="md-drawer">
            <div class="drawer-header">
              <h3 class="drawer-title"><i class="fa-solid fa-map-pin" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-ubi-code">Detalle de Ubicación</span></h3>
              <button class="drawer-close" onclick="WMS_MODULES.almacenamiento.closeDrawer()"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="drawer-body" id="drawer-content">
              <div class="text-center text-muted" style="margin-top: 40px;">Seleccione una ubicación para ver el detalle</div>
            </div>
            <div class="drawer-footer" id="drawer-actions" style="display:none;">
               <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.almacenamiento.closeDrawer()">Cerrar</button>
               <button class="btn btn-primary" style="border-radius:4px;" id="btn-trasladar-drawer"><i class="fa-solid fa-arrow-right-arrow-left"></i> Trasladar Stock</button>
            </div>
          </div>
        </div>
      `);

      this.renderMapa();

    } catch (e) {
      WMS.toast('error', 'Error cargando ubicación detalle');
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>');
    }
  },

  limpiarFiltrosMapa() {
    ['mapa-prod-ac','mapa-prod-id'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['mapa-zona-f','mapa-tipo-f','mapa-estado-f','mapa-ocup-f'].forEach(id => {
      const el = document.getElementById(id); if (el) el.value = '';
    });
    this.show_mapa();
  },

  filterMapaTable(q) {
    const rows = document.querySelectorAll('#mapa-tbody tr.main-row');
    const f = q.toLowerCase();
    rows.forEach(r => {
      r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none';
    });
  },

  renderMapa() {
    const tbody = document.getElementById('mapa-tbody');
    if (!tbody || !this._mapaData) return;

    const tipoF   = document.getElementById('mapa-tipo-f')?.value;
    const ocupF   = document.getElementById('mapa-ocup-f')?.value;
    const zonaF   = document.getElementById('mapa-zona-f')?.value;
    const estadoF = document.getElementById('mapa-estado-f')?.value;

    let items = this._mapaData;

    if (zonaF)   items = items.filter(i => i.zona === zonaF);
    if (tipoF)   items = items.filter(i => i.tipo === tipoF);
    if (estadoF) items = items.filter(i => (i.estado_ubi || 'Libre') === estadoF);
    if (ocupF === 'empty')     items = items.filter(i => i.ocupacion_pct == 0);
    if (ocupF === 'partial')   items = items.filter(i => i.ocupacion_pct > 0 && i.ocupacion_pct < 100);
    if (ocupF === 'full')      items = items.filter(i => i.ocupacion_pct >= 100);
    if (ocupF === 'reservado') items = items.filter(i => (i.total_reservado || 0) > 0);

    const _estadoBadge = (e) => {
      const map = {
        'Libre'  : ['#dcfce7','#16a34a','Libre'],
        'Parcial': ['#fef9c3','#ca8a04','Parcial'],
        'Ocupada': ['#dbeafe','#1d4ed8','Ocupada'],
        'Locked' : ['#fee2e2','#dc2626','Bloqueada'],
      };
      const [bg, fg, label] = map[e] || ['#f1f5f9','#64748b', e || 'Libre'];
      return `<span style="display:inline-block;background:${bg};color:${fg};font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:99px;letter-spacing:.03em;">${label}</span>`;
    };

    const _vencBadge = (fv) => {
      if (!fv) return '—';
      const dias = Math.ceil((new Date(fv) - new Date()) / 86400000);
      const bg   = dias <= 30 ? '#fee2e2' : dias <= 90 ? '#fef9c3' : '#dbeafe';
      const fg   = dias <= 30 ? '#dc2626' : dias <= 90 ? '#b45309' : '#1d4ed8';
      const icon = dias <= 30 ? '⚠ ' : '';
      return `<span style="background:${bg};color:${fg};font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:4px;white-space:nowrap;">${icon}${WMS.formatDate(fv)}</span>`;
    };

    const _diasLabel = (d) => {
      if (d === 'N/A' || d === null || d === undefined) return '<span style="color:#94a3b8;font-size:.75rem;">Sin historial</span>';
      const n = parseInt(d);
      if (n === 0) return '<span style="color:#16a34a;font-weight:600;font-size:.8rem;">Hoy</span>';
      const color = n > 90 ? '#dc2626' : n > 30 ? '#b45309' : '#1e293b';
      return `<span style="color:${color};font-weight:${n > 30 ? 700 : 400};font-size:.8rem;">${n}d</span>`;
    };

    tbody.innerHTML = items.map(i => {
      const pct   = Math.min(parseFloat(i.ocupacion_pct) || 0, 100);
      const color = pct >= 100 ? '#ef4444' : pct >= 75 ? '#f59e0b' : pct > 0 ? '#3b82f6' : '#e2e8f0';
      const barFg = pct >= 100 ? '#ef4444' : pct >= 75 ? '#f59e0b' : pct > 0 ? '#3b82f6' : '#10b981';
      const res   = parseFloat(i.total_reservado || 0);
      const refs  = parseInt(i.total_refs || 0);

      return `
        <tr class="main-row" id="row-${i.id}" onclick="WMS_MODULES.almacenamiento.openDrawer(${i.id}, '${WMS.esc(i.ubicacion)}')" style="cursor:pointer;">
          <td style="font-weight:700;color:#1e40af;font-family:monospace;">${WMS.esc(i.ubicacion)}</td>
          <td style="font-size:.8rem;color:#64748b;font-weight:600;">${WMS.esc(i.zona || '—')}</td>
          <td><span class="badge ${i.tipo === 'Picking' ? 'badge-success' : 'badge-info'}">${WMS.esc(i.tipo)}</span></td>
          <td>${_estadoBadge(i.estado_ubi)}</td>
          <td style="text-align:center;">
            ${refs > 0
              ? `<span style="font-weight:700;color:#0f172a;">${refs}</span><span style="font-size:.7rem;color:#94a3b8;margin-left:2px;">ref${refs !== 1 ? 's' : ''}</span>`
              : '<span style="color:#cbd5e1;font-size:.8rem;">—</span>'}
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:6px;">
              <div style="flex:1;height:7px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                <div style="width:${pct}%;height:100%;background:${barFg};transition:width .3s;"></div>
              </div>
              <span style="min-width:38px;text-align:right;font-size:.78rem;font-weight:700;color:${barFg};">${i.ocupacion_pct}%</span>
            </div>
          </td>
          <td style="text-align:center;">
            <div style="font-weight:700;color:#1e40af;font-size:.9rem;">${WMS.formatNum(i.total_productos)}</div>
            ${res > 0 ? `<div style="font-size:.7rem;color:#dc2626;font-weight:600;"><i class="fa-solid fa-lock" style="font-size:.6rem;"></i> ${WMS.formatNum(res)} res.</div>` : ''}
          </td>
          <td>${_vencBadge(i.proximo_vencimiento)}</td>
          <td style="text-align:center;">${_diasLabel(i.dias_sin_mov)}</td>
        </tr>
      `;
    }).join('') || '<tr><td colspan="9" class="table-empty">No se encontraron ubicaciones con los filtros aplicados.</td></tr>';
  },

  closeDrawer() {
    const drawer = document.getElementById('ubicacion-drawer');
    if (drawer) {
      drawer.classList.remove('open');
      // Limpiar selección
      document.querySelectorAll('#mapa-tbody tr').forEach(r => r.style.background = '');
    }
  },

  async openDrawer(id, codigo) {
    const drawer = document.getElementById('ubicacion-drawer');
    const content = document.getElementById('drawer-content');
    const title = document.getElementById('drawer-ubi-code');
    const actions = document.getElementById('drawer-actions');
    const btnTrasladar = document.getElementById('btn-trasladar-drawer');
    
    if (!drawer || !content) return;

    // Highlight row
    document.querySelectorAll('#mapa-tbody tr').forEach(r => r.style.background = '');
    const row = document.getElementById(`row-${id}`);
    if (row) row.style.background = '#e0f2fe';

    // Show Drawer
    title.textContent = codigo;
    drawer.classList.add('open');
    content.innerHTML = `<div style="padding:40px; text-align:center;"><div class="spinner"></div><p style="margin-top:10px; color:#64748b;">Cargando inventario...</p></div>`;
    actions.style.display = 'none';

    try {
      const r = await API.get('/inventario/stock', `ubicacion_id=${id}`);
      const stockItems = r.data || r || [];
      
      if (stockItems.length === 0) {
        content.innerHTML = `<div style="padding:30px; text-align:center; color:#64748b; background:#f1f5f9; border-radius:4px;"><i class="fa-solid fa-box-open" style="font-size:2rem; margin-bottom:10px;"></i><br>Ubicación vacía.</div>`;
        return;
      }

      // Calcular totales para el resumen del encabezado
      const totalUnd = stockItems.reduce((a, s) => a + parseFloat(s.cantidad || 0), 0);
      const totalRes = stockItems.reduce((a, s) => a + parseFloat(s.cantidad_reservada || 0), 0);
      const totalDisp = Math.max(0, totalUnd - totalRes);

      content.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px;">
          <div style="background:#eff6ff;border-radius:6px;padding:10px;text-align:center;">
            <div style="font-size:.68rem;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Total</div>
            <div style="font-size:1.25rem;font-weight:800;color:#1e40af;">${WMS.formatNum(totalUnd)}</div>
            <div style="font-size:.68rem;color:#64748b;">und.</div>
          </div>
          <div style="background:${totalRes > 0 ? '#fef2f2' : '#f0fdf4'};border-radius:6px;padding:10px;text-align:center;">
            <div style="font-size:.68rem;color:${totalRes > 0 ? '#dc2626' : '#16a34a'};font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Reservado</div>
            <div style="font-size:1.25rem;font-weight:800;color:${totalRes > 0 ? '#dc2626' : '#16a34a'};">${WMS.formatNum(totalRes)}</div>
            <div style="font-size:.68rem;color:#64748b;">und.</div>
          </div>
          <div style="background:#f0fdf4;border-radius:6px;padding:10px;text-align:center;">
            <div style="font-size:.68rem;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Disponible</div>
            <div style="font-size:1.25rem;font-weight:800;color:#15803d;">${WMS.formatNum(totalDisp)}</div>
            <div style="font-size:.68rem;color:#64748b;">und.</div>
          </div>
        </div>
        <div style="font-size:.75rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;">${stockItems.length} referencia${stockItems.length !== 1 ? 's' : ''} en esta ubicación</div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          ${stockItems.map(s => {
            const units  = parseFloat(s.cantidad || 0);
            const resv   = parseFloat(s.cantidad_reservada || 0);
            const disp   = Math.max(0, units - resv);
            const factor = parseInt(s.unidades_caja || 1) || 1;
            const cajas  = units / factor;
            const fechaRef   = s.last_movement_at || s.created_at;
            const diasEnLoc  = fechaRef ? Math.floor((Date.now() - new Date(fechaRef).getTime()) / 86400000) : null;
            const fv = s.fecha_vencimiento;
            const diasVenc   = fv ? Math.ceil((new Date(fv) - new Date()) / 86400000) : null;
            const vencColor  = diasVenc !== null ? (diasVenc <= 30 ? '#dc2626' : diasVenc <= 90 ? '#b45309' : '#1e293b') : '#94a3b8';
            const vencIcon   = diasVenc !== null && diasVenc <= 30 ? '⚠ ' : '';
            return `
              <div style="border:1px solid ${resv > 0 ? '#fecaca' : '#e2e8f0'};border-radius:6px;padding:12px;background:${resv > 0 ? '#fff8f8' : '#fff'};">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#0f172a;font-size:.88rem;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${WMS.esc(s.producto_nombre || '—')}</div>
                    <div style="font-family:monospace;font-size:.75rem;color:#64748b;margin-top:1px;">${WMS.esc(s.codigo_interno || '')}</div>
                  </div>
                  <div style="text-align:right;flex-shrink:0;">
                    <div style="font-weight:800;color:#1e40af;font-size:1rem;">${WMS.formatNum(units)}<span style="font-size:.65rem;color:#94a3b8;font-weight:400;margin-left:2px;">und</span></div>
                    ${factor > 1 ? `<div style="font-size:.75rem;color:#059669;font-weight:600;">${cajas % 1 === 0 ? cajas : cajas.toFixed(1)} cx</div>` : ''}
                    ${resv > 0 ? `<div style="font-size:.7rem;color:#dc2626;font-weight:700;"><i class="fa-solid fa-lock" style="font-size:.6rem;"></i> ${WMS.formatNum(resv)} res.</div>` : ''}
                  </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:10px;background:#f8fafc;border-radius:4px;padding:8px;font-size:.8rem;">
                  <div><span style="color:#94a3b8;">Lote</span><br><span style="font-family:monospace;font-weight:600;color:#1e293b;">${WMS.esc(s.lote || 'N/A')}</span></div>
                  <div><span style="color:#94a3b8;">Vencimiento</span><br><span style="color:${vencColor};font-weight:700;">${fv ? vencIcon + WMS.formatDate(fv) : '—'}</span></div>
                  <div><span style="color:#94a3b8;">Disponible</span><br><span style="font-weight:700;color:#15803d;">${WMS.formatNum(disp)} und</span></div>
                  <div><span style="color:#94a3b8;">Días en loc.</span><br><span style="font-weight:600;color:${diasEnLoc > 90 ? '#dc2626' : '#1e293b'};">${diasEnLoc !== null ? diasEnLoc + 'd' : '—'}</span></div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      `;

      btnTrasladar.onclick = () => WMS_MODULES.almacenamiento.trasladarTodo(id, codigo);
      actions.style.display = 'flex';

    } catch (e) {
      content.innerHTML = `<div class="text-danger" style="padding:20px;"><i class="fa-solid fa-triangle-exclamation"></i> Error al cargar productos.</div>`;
    }
  },

  trasladarTodo(id, codigo) {
    // Redirigir a la pestaña de transferir con el origen preseleccionado
    this.load('transferir');
    setTimeout(() => {
      const select = document.getElementById('tr-origen');
      if (select) {
        select.value = id;
        this.cargarLotesOrigen();
      }
    }, 100);
  },

  // ── INFORME TRASLADOS ─────────────────────────────────────────
  async show_informe() {
    const hoy  = WMS.getToday();
    const hace = WMS.getPastDate(30);
    WMS.setToolbar(`<button class="btn btn-success btn-sm" onclick="WMS_MODULES.almacenamiento.exportarInforme()"><i class="fa-solid fa-file-excel"></i> Exportar</button>`);
    WMS.spinner();
    try {
      const r     = await API.get('/inventario/kardex', `tipo=Traslado&desde=${hace}&hasta=${hoy}&limit=200`);
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar en traslados..." oninput="WMS_MODULES.almacenamiento.filterTable(this.value,'tr-inf-table')">
          </div>
          <input type="date" class="form-control" id="tr-inf-desde" style="max-width:160px;" value="${hace}"
            onchange="WMS_MODULES.almacenamiento.recargarInformeTraslados()">
          <input type="date" class="form-control" id="tr-inf-hasta" style="max-width:160px;" value="${hoy}"
            onchange="WMS_MODULES.almacenamiento.recargarInformeTraslados()">
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-lines"></i> Informe de Traslados (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="tr-inf-table">
              <thead><tr><th>Fecha</th><th>Tipo</th><th>Producto</th><th>Cant.</th><th>Origen</th><th>Destino</th><th>Lote</th><th>Observaciones</th><th>Usuario</th></tr></thead>
              <tbody>${items.map(m => `<tr>
                <td style="font-size:.78rem;white-space:nowrap;">${WMS.formatDateTime(m.created_at)}</td>
                <td><span class="badge badge-info">${WMS.esc(m.tipo_movimiento || '-')}</span></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(m.descripcion || m.producto || '-')}</td>
                <td class="text-center fw-600">${WMS.formatNum(m.cantidad || 0)}</td>
                <td style="font-family:monospace;font-size:.78rem;">${WMS.esc(m.ubicacion_origen || '—')}</td>
                <td style="font-family:monospace;font-size:.78rem;">${WMS.esc(m.ubicacion || m.ubicacion_destino || '—')}</td>
                <td style="font-family:monospace;font-size:.78rem;color:#475569;">${WMS.esc(m.lote || '—')}</td>
                <td style="font-size:.78rem;color:#64748b;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(m.observaciones || '—')}</td>
                <td style="font-size:.78rem;">${WMS.esc(m.usuario || '-')}</td>
              </tr>`).join('') || '<tr><td colspan="9" class="table-empty">Sin traslados registrados</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  async recargarInformeTraslados() {
    const desde = document.getElementById('tr-inf-desde')?.value;
    const hasta = document.getElementById('tr-inf-hasta')?.value;
    if (!desde || !hasta) return;
    try {
      const r     = await API.get('/inventario/kardex', `tipo=Traslado&desde=${desde}&hasta=${hasta}&limit=200`);
      const items = r.data || r || [];
      const tbody = document.querySelector('#tr-inf-table tbody');
      if (tbody) {
        tbody.innerHTML = items.map(m => `<tr>
          <td style="font-size:.78rem;white-space:nowrap;">${WMS.formatDateTime(m.created_at)}</td>
          <td><span class="badge badge-info">${WMS.esc(m.tipo_movimiento || '-')}</span></td>
          <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(m.descripcion || m.producto || '-')}</td>
          <td class="text-center fw-600">${WMS.formatNum(m.cantidad || 0)}</td>
          <td style="font-family:monospace;font-size:.78rem;">${WMS.esc(m.ubicacion_origen || '—')}</td>
          <td style="font-family:monospace;font-size:.78rem;">${WMS.esc(m.ubicacion || m.ubicacion_destino || '—')}</td>
          <td style="font-family:monospace;font-size:.78rem;color:#475569;">${WMS.esc(m.lote || '—')}</td>
          <td style="font-size:.78rem;color:#64748b;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${WMS.esc(m.observaciones || '—')}</td>
          <td style="font-size:.78rem;">${WMS.esc(m.usuario || '-')}</td>
        </tr>`).join('') || '<tr><td colspan="9" class="table-empty">Sin traslados registrados</td></tr>';
      }
    } catch (e) { WMS.toast('error', 'Error recargando'); }
  },

  async exportarInforme() {
    const desde = document.getElementById('tr-inf-desde')?.value || '';
    const hasta = document.getElementById('tr-inf-hasta')?.value || '';
    WMS.toast('info', 'Generando reporte...');
    try {
      const params = `tipo=Traslado&format=xlsx${desde ? '&desde=' + desde : ''}${hasta ? '&hasta=' + hasta : ''}`;
      const r = await API.get('/reportes/kardex', params);
      if (r.url) window.open(r.url, '_blank');
      else WMS.toast('info', 'Reporte generado');
    } catch (e) { WMS.toast('error', 'Error exportando'); }
  },

  // ══════════════════════════════════════════════════════════════
  //  TRASPASO A CLIENTE
  // ══════════════════════════════════════════════════════════════

  _trpTimer: null,
  _trpResults: [],
  _trpDItems: [],
  _trpDCanvas: null,
  _trpDCtx: null,
  _trpDDrawing: false,

  async show_traspaso() {
    WMS.setBreadcrumb('almacenamiento', 'Traspaso a Cliente');
    this._trpDItems = [];
    let motivos = [];
    let clientes = [];
    try {
      const [mr, cr] = await Promise.all([
        API.get('/traspasos/motivos').catch(() => ({ data: [] })),
        API.get('/param/clientes').catch(() => ({ data: [] }))
      ]);
      motivos  = mr.data || [];
      clientes = cr.data || cr || [];
    } catch(e) {
      motivos  = ['Traspaso a cliente','Muestra comercial','Otro'];
      clientes = [];
    }

    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-file-signature" style="color:#ef4444;"></i> Documento de Traspaso</h5>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">

          <!-- Encabezado -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
            <h6 style="margin:0 0 12px;color:#1e293b;font-weight:700;"><i class="fa-solid fa-1"></i> Encabezado</h6>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:14px;">
              <div>
                <label class="form-label">Cliente *</label>
                <select id="trp-d-cliente-id" class="form-control" onchange="const opt=this.options[this.selectedIndex]; document.getElementById('trp-d-cliente-nom').value = opt ? opt.text : '';">
                  <option value="">-- Seleccionar Cliente --</option>
                  ${clientes.map(c => `<option value="${c.id}">${WMS.esc(c.razon_social || c.nombre || '')} (${WMS.esc(c.nit || c.documento || 'NIT')})</option>`).join('')}
                </select>
                <input type="hidden" id="trp-d-cliente-nom">
              </div>
              <div>
                <label class="form-label">Quien recibe *</label>
                <input type="text" id="trp-d-quien-recibe" class="form-control" placeholder="Nombre completo">
              </div>
              <div>
                <label class="form-label">Motivo *</label>
                <select id="trp-d-motivo" class="form-control">${motivos.map(m => `<option value="${WMS.esc(m)}">${WMS.esc(m)}</option>`).join('')}</select>
              </div>
            </div>
          </div>

          <!-- Productos -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
            <h6 style="margin:0 0 12px;color:#1e293b;font-weight:700;"><i class="fa-solid fa-2"></i> Agregar Productos</h6>
            
            <div style="display:flex;gap:10px;margin-bottom:10px;">
              <input id="trp-d-buscar" class="form-control" placeholder="Buscar por código, nombre o ubicación..."
                     oninput="clearTimeout(WMS_MODULES.almacenamiento._trpTimer);WMS_MODULES.almacenamiento._trpTimer=setTimeout(()=>WMS_MODULES.almacenamiento._trpDBuscar(),400)"
                     style="flex:1;">
            </div>
            <div id="trp-d-resultados" style="max-height:200px;overflow-y:auto;margin-bottom:10px;"></div>

            <div id="trp-d-seleccion" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px;margin-bottom:10px;">
              <div id="trp-d-sel-info" style="font-size:.85rem;margin-bottom:8px;"></div>
              <div style="display:flex;gap:10px;align-items:center;">
                <label style="margin:0;font-weight:700;">Cantidad *</label>
                <input id="trp-d-cant" type="number" class="form-control" min="0.01" step="0.01" style="width:120px;">
                <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento._trpDCancelarItem()">Cancelar</button>
                <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.almacenamiento._trpDAgregarItem()"><i class="fa-solid fa-plus"></i> Agregar</button>
              </div>
            </div>

            <h6 style="font-size:.85rem;color:#64748b;margin-bottom:6px;">Items Agregados:</h6>
            <div id="trp-d-items-agregados" style="min-height:50px;border:1px dashed #cbd5e1;border-radius:6px;padding:10px;">
              <div style="text-align:center;color:#94a3b8;font-size:.85rem;">No hay productos agregados</div>
            </div>
          </div>

          <!-- Firma y Cierre -->
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">
            <h6 style="margin:0 0 12px;color:#1e293b;font-weight:700;"><i class="fa-solid fa-3"></i> Confirmación y Firma</h6>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
              <div>
                <label class="form-label">Observaciones</label>
                <textarea id="trp-d-obs" class="form-control" rows="4" style="resize:none;"></textarea>
              </div>
              <div>
                <label class="form-label">Firma de Conformidad *</label>
                <div style="border:1px solid #cbd5e1;border-radius:8px;background:#fff;overflow:hidden;margin-bottom:8px;">
                  <canvas id="trp-d-firma-canvas" width="400" height="120" style="width:100%;display:block;cursor:crosshair;"></canvas>
                </div>
                <button class="btn btn-light btn-sm" style="width:100%;border:1px solid #e2e8f0;" onclick="WMS_MODULES.almacenamiento._trpDLimpiarFirma()">
                  <i class="fa-solid fa-eraser"></i> Limpiar Firma
                </button>
              </div>
            </div>

            <button id="trp-d-btn-submit" class="btn btn-danger" style="width:100%;margin-top:16px;padding:12px;font-size:1.05rem;" onclick="WMS_MODULES.almacenamiento._trpDConfirmarDoc()">
              <i class="fa-solid fa-check-double"></i> Generar Documento de Traspaso
            </button>
          </div>

          <div style="margin-top:20px;"><h6 style="font-size:.9rem;color:#64748b;font-weight:700;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">Últimos Documentos Generados</h6>
            <div id="trp-d-historial"></div>
          </div>
        </div>
      </div>`);
    this._trpDCargarHistorial();
    
    setTimeout(() => {
      this._trpDInitCanvas();
    }, 100);
  },

  _trpDInitCanvas() {
    this._trpDCanvas = document.getElementById('trp-d-firma-canvas');
    if (!this._trpDCanvas) return;
    this._trpDCtx = this._trpDCanvas.getContext('2d');
    this._trpDCtx.strokeStyle = '#000000';
    this._trpDCtx.lineWidth = 2;
    this._trpDCtx.lineCap = 'round';
    
    const rect = this._trpDCanvas.getBoundingClientRect();
    this._trpDCanvas.width = rect.width || 400;
    this._trpDCanvas.height = 120;

    const startPos = (e) => {
      e.preventDefault();
      this._trpDDrawing = true;
      this._trpDDraw(e);
    };
    const endPos = (e) => {
      e.preventDefault();
      this._trpDDrawing = false;
      this._trpDCtx.beginPath();
    };
    const getPos = (e) => {
      const r = this._trpDCanvas.getBoundingClientRect();
      const x = (e.clientX || (e.touches ? e.touches[0].clientX : 0)) - r.left;
      const y = (e.clientY || (e.touches ? e.touches[0].clientY : 0)) - r.top;
      return {x,y};
    };
    
    this._trpDDraw = (e) => {
      if (!this._trpDDrawing) return;
      e.preventDefault();
      const {x, y} = getPos(e);
      this._trpDCtx.lineTo(x, y);
      this._trpDCtx.stroke();
      this._trpDCtx.beginPath();
      this._trpDCtx.moveTo(x, y);
    };

    this._trpDCanvas.addEventListener('mousedown', startPos);
    this._trpDCanvas.addEventListener('mouseup', endPos);
    this._trpDCanvas.addEventListener('mousemove', this._trpDDraw);
    this._trpDCanvas.addEventListener('mouseleave', endPos);
  },

  _trpDLimpiarFirma() {
    if (this._trpDCanvas && this._trpDCtx) {
      this._trpDCtx.clearRect(0, 0, this._trpDCanvas.width, this._trpDCanvas.height);
      this._trpDCtx.beginPath();
    }
  },

  _trpDIsFirmaEmpty() {
    if (!this._trpDCanvas) return true;
    const blank = document.createElement('canvas');
    blank.width = this._trpDCanvas.width;
    blank.height = this._trpDCanvas.height;
    return this._trpDCanvas.toDataURL() === blank.toDataURL();
  },

  async _trpDBuscar() {
    const q = document.getElementById('trp-d-buscar')?.value.trim();
    const div = document.getElementById('trp-d-resultados');
    if (!q || q.length < 2) { div.innerHTML = ''; return; }
    try {
      const r = await API.get('/traspasos/buscar-stock', 'q=' + encodeURIComponent(q));
      this._trpResults = r.data || [];
      div.innerHTML = this._trpResults.length ? this._trpResults.map((s, i) => `
        <div style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:4px;margin-bottom:4px;cursor:pointer;
          ${s.bloqueado ? 'background:#fef2f2;opacity:.7;' : 'background:#fff;'}
          display:flex;justify-content:space-between;align-items:center;"
          onclick="${s.bloqueado ? "WMS.toast('error','Producto bloqueado')" : 'WMS_MODULES.almacenamiento._trpDSeleccionar('+i+')'}">
          <div>
            <div style="font-weight:700;font-size:.85rem;">${WMS.esc(s.nombre)} ${s.bloqueado ? '<span style="color:#dc2626;font-size:.7rem;font-weight:600;">BLOQUEADO</span>' : ''}</div>
            <div style="font-size:.75rem;color:#64748b;">Cod: ${WMS.esc(s.codigo_interno)} | Ubic: <strong>${WMS.esc(s.ubicacion_codigo)}</strong> | Lote: <strong>${WMS.esc(s.lote||'S/L')}</strong>${s.fecha_vencimiento ? ' | Venc: '+s.fecha_vencimiento : ''}</div>
          </div>
          <div style="font-weight:900;font-size:1.1rem;color:#059669;">${s.cantidad_disponible}</div>
        </div>`).join('') : '<div style="text-align:center;color:#94a3b8;padding:12px;">Sin resultados</div>';
    } catch(e) { div.innerHTML = ''; }
  },

  _trpDSelected: null,
  _trpDSeleccionar(idx) {
    const s = this._trpResults[idx];
    if (!s) return;
    this._trpDSelected = s;
    document.getElementById('trp-d-resultados').innerHTML = '';
    document.getElementById('trp-d-buscar').value = '';
    const sel = document.getElementById('trp-d-seleccion');
    sel.style.display = 'block';
    sel.querySelector('#trp-d-sel-info').innerHTML = `
      <div style="font-weight:700;color:#1d4ed8;">${WMS.esc(s.nombre)} — ${WMS.esc(s.codigo_interno)}</div>
      <div style="font-size:.82rem;color:#475569;margin-top:4px;">
        Ubicación: <strong>${WMS.esc(s.ubicacion_codigo)}</strong> |
        Lote: <strong>${WMS.esc(s.lote||'Sin lote')}</strong> |
        Disponible: <strong style="color:#059669;">${s.cantidad_disponible}</strong>
      </div>`;
    const cantEl = document.getElementById('trp-d-cant');
    cantEl.value = '';
    cantEl.max = s.cantidad_disponible;
    cantEl.focus();
  },

  _trpDCancelarItem() {
    this._trpDSelected = null;
    document.getElementById('trp-d-seleccion').style.display = 'none';
  },

  _trpDAgregarItem() {
    if (!this._trpDSelected) return;
    const cant = parseFloat(document.getElementById('trp-d-cant').value) || 0;
    if (cant <= 0 || cant > this._trpDSelected.cantidad_disponible) {
      WMS.toast('error', 'Cantidad inválida o excede el disponible');
      return;
    }
    this._trpDItems.push({
      ...this._trpDSelected,
      cantidad: cant
    });
    this._trpDRenderItems();
    this._trpDCancelarItem();
  },

  _trpDRenderItems() {
    const el = document.getElementById('trp-d-items-agregados');
    if (!this._trpDItems.length) {
      el.innerHTML = `<div style="text-align:center;color:#94a3b8;font-size:.85rem;padding:10px;">No hay productos agregados</div>`;
      return;
    }
    el.innerHTML = `
      <table class="table table-sm" style="font-size:.85rem;margin:0;">
        <thead style="background:#f1f5f9;">
          <tr><th>Producto</th><th>Ubicación</th><th>Lote</th><th style="text-align:right;">Cant.</th><th style="width:40px;"></th></tr>
        </thead>
        <tbody>
          ${this._trpDItems.map((it, i) => `
            <tr>
              <td><strong>${WMS.esc(it.nombre)}</strong><br><span style="color:#64748b;font-size:.75rem;">${WMS.esc(it.codigo_interno)}</span></td>
              <td>${WMS.esc(it.ubicacion_codigo)}</td>
              <td>${WMS.esc(it.lote||'S/L')}</td>
              <td style="text-align:right;font-weight:700;">${it.cantidad}</td>
              <td style="text-align:center;">
                <button class="btn btn-sm" style="color:#ef4444;padding:2px 6px;" onclick="WMS_MODULES.almacenamiento._trpDItems.splice(${i},1);WMS_MODULES.almacenamiento._trpDRenderItems()">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>`;
  },

  async _trpDConfirmarDoc() {
    if (!this._trpDItems.length) { WMS.toast('warning', 'Agrega al menos un producto'); return; }
    
    const cliente_id = document.getElementById('trp-d-cliente-id')?.value;
    const quien_recibe = document.getElementById('trp-d-quien-recibe')?.value.trim();
    if (!quien_recibe) { WMS.toast('warning', 'El campo Quien recibe es obligatorio'); return; }
    if (this._trpDIsFirmaEmpty()) { WMS.toast('warning', 'Falta la firma del destinatario'); return; }

    const payload = {
      cliente_id: cliente_id || null,
      cliente_nombre: document.getElementById('trp-d-cliente-nom')?.value || null,
      quien_recibe: quien_recibe,
      motivo: document.getElementById('trp-d-motivo')?.value,
      observaciones: document.getElementById('trp-d-obs')?.value.trim() || null,
      firma_base64: this._trpDCanvas.toDataURL('image/png'),
      detalles: this._trpDItems.map(i => ({
        producto_id: i.producto_id,
        ubicacion_id: i.ubicacion_id,
        lote: i.lote,
        fecha_vencimiento: i.fecha_vencimiento,
        cantidad: i.cantidad
      }))
    };

    WMS.confirm('Generar Documento', `¿Generar documento con <strong>${this._trpDItems.length}</strong> items?`, async () => {
      const btn = document.getElementById('trp-d-btn-submit');
      btn.disabled = true;
      WMS.spinner();
      try {
        const r = await API.post('/traspasos', payload);
        if (r.error) {
          WMS.toast('error', r.message);
          btn.disabled = false;
        } else {
          WMS.toast('success', r.message || 'Documento generado exitosamente');
          this.show_traspaso();
        }
      } catch(e) { 
        WMS.toast('error', 'Error guardando traspaso'); 
        btn.disabled = false;
      }
    });
  },

  async _trpDCargarHistorial() {
    try {
      const r = await API.get('/traspasos');
      const items = (r.data || []).slice(0, 10);
      const div = document.getElementById('trp-d-historial');
      if (!div) return;
      div.innerHTML = items.length ? `<table class="table" style="font-size:.82rem;">
        <thead style="background:#f8fafc;">
          <tr><th>N° Doc</th><th>Cliente</th><th>Motivo</th><th>Recibió</th><th>Items</th><th>Fecha</th></tr>
        </thead>
        <tbody>${items.map(t => {
          const c = (t.detalles || []).length;
          return `<tr>
            <td style="font-weight:700;color:#0F4C81;">${WMS.esc(t.numero_documento||'')}</td>
            <td>${WMS.esc(t.cliente_nombre||'-')}</td>
            <td>${WMS.esc(t.motivo||'')}</td>
            <td>${WMS.esc(t.quien_recibe||'')}</td>
            <td style="font-weight:600;">${c} refs</td>
            <td style="color:#64748b;">${t.created_at ? new Date(t.created_at).toLocaleDateString('es-CO') : ''}</td>
          </tr>`;
        }).join('')}</tbody></table>` : '<div style="text-align:center;color:#94a3b8;padding:20px;background:#f8fafc;border-radius:8px;">Sin documentos registrados</div>';
    } catch(e) {}
  },

  // ══════════════════════════════════════════════════════════════
  //  BLOQUEO DE PRODUCTOS
  // ══════════════════════════════════════════════════════════════

  async show_bloqueos() {
    WMS.setBreadcrumb('almacenamiento', 'Bloqueo de Productos');
    WMS.setToolbar(`<button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.almacenamiento.show_bloqueos()"><i class="fa-solid fa-rotate"></i> Actualizar</button>`);
    WMS.spinner();
    try {
      const [bloqueos, invBloq] = await Promise.all([
        API.get('/bloqueos'),
        API.get('/bloqueos/inventario'),
      ]);
      const prods = bloqueos.data?.productos || [];
      const lotes = bloqueos.data?.lotes || [];
      const inv   = invBloq.data || [];

      WMS.setContent(`
        <div class="card animate-fade-in" style="margin-bottom:16px;">
          <div class="card-header">
            <h5 class="card-title"><i class="fa-solid fa-ban" style="color:#dc2626;"></i> Bloquear Producto / Lote</h5>
          </div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
              <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:14px;">
                <h6 style="font-size:.85rem;font-weight:700;color:#dc2626;margin-bottom:10px;"><i class="fa-solid fa-box"></i> Bloquear Referencia Completa</h6>
                <div class="form-group"><label class="form-label">Buscar producto</label>
                  <input id="blq-prod-buscar" class="form-control" placeholder="Código o nombre..."
                         oninput="clearTimeout(WMS_MODULES.almacenamiento._blqTimer);WMS_MODULES.almacenamiento._blqTimer=setTimeout(()=>WMS_MODULES.almacenamiento._blqBuscarProd(),400)">
                  <div id="blq-prod-res" style="max-height:120px;overflow-y:auto;"></div>
                  <input type="hidden" id="blq-prod-id">
                </div>
                <div class="form-group"><label class="form-label">Motivo</label>
                  <input id="blq-prod-motivo" class="form-control" placeholder="Ej: Problema de calidad"></div>
                <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.almacenamiento._blqBloquearProd()">
                  <i class="fa-solid fa-ban"></i> Bloquear Producto</button>
              </div>

              <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:4px;padding:14px;">
                <h6 style="font-size:.85rem;font-weight:700;color:#ea580c;margin-bottom:10px;"><i class="fa-solid fa-tag"></i> Bloquear Lote Específico</h6>
                <div class="form-group"><label class="form-label">Buscar producto</label>
                  <input id="blq-lote-prod-buscar" class="form-control" placeholder="Código o nombre..."
                         oninput="clearTimeout(WMS_MODULES.almacenamiento._blqLTimer);WMS_MODULES.almacenamiento._blqLTimer=setTimeout(()=>WMS_MODULES.almacenamiento._blqBuscarProdLote(),400)">
                  <div id="blq-lote-prod-res" style="max-height:120px;overflow-y:auto;"></div>
                  <input type="hidden" id="blq-lote-prod-id">
                </div>
                <div class="form-group"><label class="form-label">Lote</label>
                  <input id="blq-lote-val" class="form-control" placeholder="Número de lote"></div>
                <div class="form-group"><label class="form-label">Motivo</label>
                  <input id="blq-lote-motivo" class="form-control" placeholder="Ej: Lote vencido"></div>
                <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.almacenamiento._blqBloquearLote()">
                  <i class="fa-solid fa-ban"></i> Bloquear Lote</button>
              </div>
            </div>
          </div>
        </div>

        <div class="card animate-fade-in" style="margin-bottom:16px;">
          <div class="card-header"><h5 class="card-title"><i class="fa-solid fa-lock"></i> Productos Bloqueados (${prods.length})</h5></div>
          <div class="card-body">
            <table class="table" style="font-size:.82rem;">
              <thead><tr><th>Código</th><th>Producto</th><th>Motivo</th><th>Acción</th></tr></thead>
              <tbody>${prods.map(p => `<tr>
                <td style="font-weight:600;">${WMS.esc(p.codigo_interno||'')}</td>
                <td>${WMS.esc(p.nombre||'')}</td>
                <td style="color:#dc2626;">${WMS.esc(p.bloqueo_motivo||'')}</td>
                <td><button class="btn btn-sm btn-outline-success" onclick="WMS_MODULES.almacenamiento._blqDesbloquearProd(${p.id})"><i class="fa-solid fa-unlock"></i> Desbloquear</button></td>
              </tr>`).join('') || '<tr><td colspan="4" class="table-empty">Sin productos bloqueados</td></tr>'}</tbody>
            </table>
          </div>
        </div>

        <div class="card animate-fade-in" style="margin-bottom:16px;">
          <div class="card-header"><h5 class="card-title"><i class="fa-solid fa-tags"></i> Lotes Bloqueados (${lotes.length})</h5></div>
          <div class="card-body">
            <table class="table" style="font-size:.82rem;">
              <thead><tr><th>Producto</th><th>Lote</th><th>Motivo</th><th>Acción</th></tr></thead>
              <tbody>${lotes.map(l => `<tr>
                <td>${WMS.esc(l.producto?.nombre||l.producto?.codigo_interno||'')}</td>
                <td style="font-weight:600;">${WMS.esc(l.lote)}</td>
                <td style="color:#ea580c;">${WMS.esc(l.motivo||'')}</td>
                <td><button class="btn btn-sm btn-outline-success" onclick="WMS_MODULES.almacenamiento._blqDesbloquearLote(${l.id})"><i class="fa-solid fa-unlock"></i> Desbloquear</button></td>
              </tr>`).join('') || '<tr><td colspan="4" class="table-empty">Sin lotes bloqueados</td></tr>'}</tbody>
            </table>
          </div>
        </div>

        <div class="card animate-fade-in">
          <div class="card-header"><h5 class="card-title"><i class="fa-solid fa-warehouse"></i> Inventario Bloqueado — Detalle (${inv.length} registros)</h5></div>
          <div class="card-body">
            <table class="table" style="font-size:.82rem;">
              <thead><tr><th>Producto</th><th>Ubicación</th><th>Zona</th><th>Lote</th><th>Vencimiento</th><th>Cantidad</th><th>Estado</th></tr></thead>
              <tbody>${inv.map(i => `<tr style="background:#fef2f2;">
                <td style="font-weight:600;">${WMS.esc(i.producto?.nombre||'')}</td>
                <td>${WMS.esc(i.ubicacion?.codigo||'')}</td>
                <td>${WMS.esc(i.ubicacion?.zona||'')}</td>
                <td>${WMS.esc(i.lote||'S/L')}</td>
                <td>${i.fecha_vencimiento||'-'}</td>
                <td style="text-align:right;font-weight:700;">${i.cantidad}</td>
                <td><span style="background:#fecaca;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600;">BLOQUEADO</span></td>
              </tr>`).join('') || '<tr><td colspan="7" class="table-empty">Sin inventario bloqueado</td></tr>'}</tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error cargando datos de bloqueo'); }
  },

  _blqTimer: null,
  _blqLTimer: null,

  async _blqBuscarProd() {
    const q = document.getElementById('blq-prod-buscar')?.value.trim();
    const div = document.getElementById('blq-prod-res');
    if (!q || q.length < 2) { div.innerHTML = ''; return; }
    try {
      const r = await API.get('/param/productos/buscar', 'q=' + encodeURIComponent(q) + '&limit=8');
      const items = r.data || r || [];
      div.innerHTML = items.map(p => `
        <div style="padding:6px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:.82rem;"
             onclick="document.getElementById('blq-prod-id').value='${p.id}';document.getElementById('blq-prod-buscar').value='${WMS.esc(p.nombre||'')}';document.getElementById('blq-prod-res').innerHTML='';">
          ${WMS.esc(p.codigo_interno||'')} — ${WMS.esc(p.nombre||'')}
        </div>`).join('');
    } catch(e) {}
  },

  async _blqBuscarProdLote() {
    const q = document.getElementById('blq-lote-prod-buscar')?.value.trim();
    const div = document.getElementById('blq-lote-prod-res');
    if (!q || q.length < 2) { div.innerHTML = ''; return; }
    try {
      const r = await API.get('/param/productos/buscar', 'q=' + encodeURIComponent(q) + '&limit=8');
      const items = r.data || r || [];
      div.innerHTML = items.map(p => `
        <div style="padding:6px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:.82rem;"
             onclick="document.getElementById('blq-lote-prod-id').value='${p.id}';document.getElementById('blq-lote-prod-buscar').value='${WMS.esc(p.nombre||'')}';document.getElementById('blq-lote-prod-res').innerHTML='';">
          ${WMS.esc(p.codigo_interno||'')} — ${WMS.esc(p.nombre||'')}
        </div>`).join('');
    } catch(e) {}
  },

  async _blqBloquearProd() {
    const id = document.getElementById('blq-prod-id')?.value;
    if (!id) { WMS.toast('warning', 'Seleccione un producto'); return; }
    const motivo = document.getElementById('blq-prod-motivo')?.value.trim() || 'Bloqueado por calidad';
    try {
      const r = await API.post('/bloqueos/producto/' + id, { motivo });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', r.message); this.show_bloqueos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async _blqBloquearLote() {
    const prodId = document.getElementById('blq-lote-prod-id')?.value;
    const lote   = document.getElementById('blq-lote-val')?.value.trim();
    if (!prodId || !lote) { WMS.toast('warning', 'Seleccione producto e ingrese lote'); return; }
    const motivo = document.getElementById('blq-lote-motivo')?.value.trim() || 'Bloqueado por calidad';
    try {
      const r = await API.post('/bloqueos/lote', { producto_id: prodId, lote, motivo });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', r.message); this.show_bloqueos(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async _blqDesbloquearProd(id) {
    WMS.confirm('Desbloquear Producto', '¿Desbloquear este producto? Volverá a estar disponible para picking.', async () => {
      const r = await API.delete('/bloqueos/producto/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', r.message); this.show_bloqueos(); }
    });
  },

  async _blqDesbloquearLote(id) {
    WMS.confirm('Desbloquear Lote', '¿Desbloquear este lote?', async () => {
      const r = await API.delete('/bloqueos/lote/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', r.message); this.show_bloqueos(); }
    });
  },
};
