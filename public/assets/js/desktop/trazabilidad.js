if (!window.WMS_MODULES) window.WMS_MODULES = {};

WMS_MODULES['trazabilidad'] = (function () {
  let _state = { tab: 'producto', productoId: null, ubicacionId: null };

  const TIPO_CFG = {
    'Entrada':          { icon: 'fa-arrow-down',    color: '#22c55e', bg: '#f0fdf4', label: 'Entrada' },
    'Salida':           { icon: 'fa-arrow-up',      color: '#ef4444', bg: '#fef2f2', label: 'Salida' },
    'Picking':          { icon: 'fa-boxes-stacked', color: '#3b82f6', bg: '#eff6ff', label: 'Picking' },
    'Traslado':         { icon: 'fa-right-left',    color: '#f59e0b', bg: '#fffbeb', label: 'Traslado' },
    'Devolucion':       { icon: 'fa-rotate-left',   color: '#8b5cf6', bg: '#f5f3ff', label: 'Devolución' },
    'AjustePositivo':   { icon: 'fa-circle-plus',   color: '#0ea5e9', bg: '#f0f9ff', label: 'Ajuste +' },
    'AjusteNegativo':   { icon: 'fa-circle-minus',  color: '#f97316', bg: '#fff7ed', label: 'Ajuste -' },
    'Reabastecimiento': { icon: 'fa-rotate-right',  color: '#06b6d4', bg: '#ecfeff', label: 'Reabast.' },
    'CorreccionAdmin':  { icon: 'fa-user-shield',   color: '#6b7280', bg: '#f9fafb', label: 'Corrección' },
    'InvInicial':       { icon: 'fa-database',      color: '#1d4ed8', bg: '#eff6ff', label: 'Inv. Inicial' },
  };

  function tipoHtml(tipo) {
    const c = TIPO_CFG[tipo] || { icon: 'fa-circle', color: '#64748b', bg: '#f8fafc', label: tipo || '—' };
    return `<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;background:${c.bg};color:${c.color};font-size:11px;font-weight:700;white-space:nowrap;">
      <i class="fa-solid ${c.icon}" style="font-size:10px;"></i>${c.label}
    </span>`;
  }

  function docHtml(doc) {
    if (!doc) return '<span style="color:#94a3b8;font-size:11px;">—</span>';
    if (doc.tipo === 'Despacho')
      return `<div style="font-size:11px;"><b style="color:#1d4ed8;">${WMS.esc(doc.numero || '')}</b><br>
              <span style="color:#64748b;">${WMS.esc(doc.cliente || '')}${doc.ruta ? ' · Ruta ' + WMS.esc(doc.ruta) : ''}</span></div>`;
    if (doc.tipo === 'Devolución')
      return `<div style="font-size:11px;"><b style="color:#8b5cf6;">${WMS.esc(doc.numero || '')}</b><br>
              <span style="color:#64748b;">${WMS.esc(doc.subtipo || '')} · ${WMS.esc(doc.motivo || '')}</span></div>`;
    if (doc.tipo === 'Recepción')
      return `<div style="font-size:11px;"><b style="color:#22c55e;">${WMS.esc(doc.numero || '')}</b>${doc.odc_id ? ` <span style="color:#64748b;">ODC#${doc.odc_id}</span>` : ''}</div>`;
    if (doc.tipo === 'Picking' && doc.pedidos && doc.pedidos.length)
      return `<div style="font-size:11px;">${doc.pedidos.slice(0, 3).map(p =>
        `<div><b style="color:#3b82f6;">${WMS.esc(p.numero_orden || '')}</b> <span style="color:#64748b;">${WMS.esc(p.cliente || p.sucursal_entrega || '')}</span></div>`
      ).join('')}${doc.pedidos.length > 3 ? `<div style="color:#94a3b8;font-size:10px;">+${doc.pedidos.length - 3} más</div>` : ''}</div>`;
    return `<span style="font-size:11px;color:#64748b;">${WMS.esc(doc.tipo || '')}</span>`;
  }

  function ubicRow(origen, destino, tipo) {
    if (!origen && !destino) return '<span style="color:#94a3b8;">—</span>';
    if (tipo === 'Traslado')
      return `<span style="font-size:11px;"><b>${WMS.esc(origen || '?')}</b> <i class="fa-solid fa-arrow-right" style="color:#f59e0b;font-size:9px;margin:0 3px;"></i> <b style="color:#f59e0b;">${WMS.esc(destino || '?')}</b></span>`;
    if (['Entrada', 'InvInicial', 'Reabastecimiento', 'AjustePositivo'].includes(tipo))
      return `<span style="font-size:11px;color:#22c55e;"><i class="fa-solid fa-arrow-right" style="font-size:9px;margin-right:3px;"></i>${WMS.esc(destino || origen || '—')}</span>`;
    return `<span style="font-size:11px;color:#ef4444;"><i class="fa-solid fa-arrow-right" style="font-size:9px;margin-right:3px;"></i>${WMS.esc(origen || destino || '—')}</span>`;
  }

  function renderMovTable(movimientos, showProducto) {
    if (!movimientos || !movimientos.length)
      return '<div class="m-empty"><i class="fa-solid fa-inbox"></i><p>Sin movimientos en el período seleccionado</p></div>';

    const prodTh = showProducto ? '<th style="min-width:160px;">Referencia</th>' : '';
    const rows = movimientos.map(m => {
      const upc      = m.unidades_caja || 1;
      const cj       = m.cantidad_cajas != null ? WMS.formatNum(m.cantidad_cajas) : null;
      const und      = WMS.formatNum(m.cantidad || 0);
      const cantHtml = cj != null
        ? `<b>${cj} cj</b>${upc > 1 ? `<div style="font-size:10px;color:#64748b;">${und} und</div>` : ''}`
        : `<b>${und}</b>`;

      const prodTd = showProducto
        ? `<td><div style="font-weight:700;font-size:12px;">${WMS.esc(m.producto_nombre || '')}</div>
               <div style="font-size:10px;color:#64748b;">${WMS.esc(m.producto_codigo || '')}</div></td>`
        : '';

      return `<tr>
        <td style="white-space:nowrap;font-size:11px;">
          <b>${WMS.formatDate(m.fecha_movimiento)}</b>
          <div style="color:#64748b;font-size:10px;">${m.hora_inicio || m.hora_fin || ''}</div>
        </td>
        <td>${tipoHtml(m.tipo_movimiento)}</td>
        ${prodTd}
        <td style="text-align:center;">${cantHtml}</td>
        <td style="font-size:11px;">${WMS.esc(m.lote || '—')}</td>
        <td style="font-size:11px;">${m.fecha_vencimiento ? WMS.formatDate(m.fecha_vencimiento) : '—'}</td>
        <td>${ubicRow(m.ubicacion_origen, m.ubicacion_destino, m.tipo_movimiento)}</td>
        <td style="font-size:11px;"><b>${WMS.esc(m.responsable || '—')}</b><div style="color:#64748b;font-size:10px;">${WMS.esc(m.responsable_doc || '')}</div></td>
        <td>${docHtml(m.documento)}</td>
        <td style="font-size:11px;color:#94a3b8;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${WMS.esc(m.observaciones || '')}">${WMS.esc(m.observaciones || '')}</td>
      </tr>`;
    }).join('');

    return `<div class="table-container" style="overflow-x:auto;">
      <table class="erp-table" style="font-size:12px;min-width:900px;">
        <thead><tr>
          <th style="min-width:90px;">Fecha / Hora</th>
          <th style="min-width:100px;">Tipo</th>
          ${prodTh}
          <th style="text-align:center;min-width:80px;">Cantidad</th>
          <th>Lote</th>
          <th>F. Vencimiento</th>
          <th style="min-width:160px;">Ubicación</th>
          <th>Responsable</th>
          <th style="min-width:160px;">Documento</th>
          <th>Observaciones</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
  }

  function renderStockActual(stock, titulo) {
    if (!stock || !stock.length) return '';
    const rows = stock.map(s => {
      const upc = s.unidades_caja || 1;
      const cj  = upc > 1 ? (s.cantidad / upc).toFixed(2) : null;
      const res = s.cantidad_reservada > 0
        ? `<div style="font-size:10px;color:#f59e0b;">Res: ${WMS.formatNum(s.cantidad_reservada)}</div>` : '';
      const badge = s.estado === 'Disponible'
        ? `<span class="badge badge-success" style="font-size:10px;">${s.estado}</span>`
        : `<span class="badge badge-warning" style="font-size:10px;">${WMS.esc(s.estado || '')}</span>`;
      return `<tr>
        <td><b style="font-size:12px;">${WMS.esc(s.ubicacion || '—')}</b>
            ${s.ubicacion_nombre ? `<div style="font-size:10px;color:#64748b;">${WMS.esc(s.ubicacion_nombre)}</div>` : ''}</td>
        <td style="font-size:11px;">${WMS.esc(s.lote || '—')}</td>
        <td style="font-size:11px;">${s.fecha_vencimiento ? WMS.formatDate(s.fecha_vencimiento) : '—'}</td>
        <td style="text-align:center;">
          ${cj ? `<b>${cj} cj</b><div style="font-size:10px;color:#64748b;">${WMS.formatNum(s.cantidad)} und</div>` : `<b>${WMS.formatNum(s.cantidad)}</b>`}
          ${res}
        </td>
        <td>${badge}</td>
        <td style="font-size:11px;">${WMS.esc(s.numero_pallet || '—')}</td>
      </tr>`;
    }).join('');
    return `<div class="card" style="margin-top:20px;">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-cubes" style="color:#10b981;margin-right:6px;"></i>${titulo || 'Stock Actual'}</span></div>
      <div class="table-container"><table class="erp-table" style="font-size:12px;">
        <thead><tr><th>Ubicación</th><th>Lote</th><th>F. Vencimiento</th><th style="text-align:center;">Stock</th><th>Estado</th><th>Pallet</th></tr></thead>
        <tbody>${rows}</tbody>
      </table></div>
    </div>`;
  }

  function _kpi(label, value, icon, color) {
    return `<div class="pro-kpi-card" style="padding:14px 16px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;border-radius:50%;background:${color}22;display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid ${icon}" style="color:${color};font-size:15px;"></i>
        </div>
        <div>
          <div style="font-size:18px;font-weight:800;color:#0f172a;">${value}</div>
          <div style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">${label}</div>
        </div>
      </div>
    </div>`;
  }

  function _filtersHtml(fi, showLote) {
    const fIni = fi.fIni || fi.f_ini || '';
    const fFin = fi.fFin || fi.f_fin || '';
    const lote = fi.lote || '';
    const fn   = showLote ? '_buscarProducto' : '_buscarUbicacion';
    return `<div class="filter-bar" style="background:#fff;padding:14px 18px;border-radius:4px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.04);display:flex;flex-wrap:wrap;gap:12px;align-items:center;border:1px solid #f1f5f9;">
      <label style="font-size:11px;font-weight:700;color:#64748b;">DESDE</label>
      <input type="date" id="trz-f-ini" class="form-control" style="width:130px;" value="${WMS.esc(fIni)}">
      <label style="font-size:11px;font-weight:700;color:#64748b;">HASTA</label>
      <input type="date" id="trz-f-fin" class="form-control" style="width:130px;" value="${WMS.esc(fFin)}">
      ${showLote ? `<input id="trz-lote" class="form-control" style="width:120px;" placeholder="Lote (opc.)" value="${WMS.esc(lote)}">` : ''}
      <button class="btn btn-primary btn-sm" style="height:34px;" onclick="WMS_MODULES['trazabilidad'].${fn}()">
        <i class="fa-solid fa-filter"></i> Filtrar
      </button>
      <button class="btn btn-secondary btn-sm" style="height:34px;" onclick="WMS_MODULES['trazabilidad'].load()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>
    </div>`;
  }

  function _tabBtn(tab, label, icon, activeTab) {
    const active = tab === activeTab;
    return `<button class="trz-tab-btn" data-tab="${tab}" style="padding:10px 22px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;border-bottom:3px solid ${active ? '#1d4ed8' : 'transparent'};color:${active ? '#1d4ed8' : '#64748b'};transition:all .2s;">
      <i class="fa-solid ${icon}" style="margin-right:6px;"></i>${label}
    </button>`;
  }

  function _toDate(offsetDays) {
    const d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return d.toISOString().slice(0, 10);
  }

  function _loadingHtml(msg) {
    return `<div style="display:flex;align-items:center;justify-content:center;height:220px;color:#64748b;gap:12px;">
      <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;color:#1d4ed8;"></i>
      <span style="font-size:14px;">${msg}</span>
    </div>`;
  }

  function _setupTypeahead(inputId, resultId, endpoint) {
    let timer;
    const input  = document.getElementById(inputId);
    const result = document.getElementById(resultId);
    if (!input || !result) return;

    // fixed positioning escapa overflow:hidden de cualquier ancestro (cards, panels)
    Object.assign(result.style, {
      position:  'fixed',
      zIndex:    '99999',
      background:'#fff',
      border:    '1px solid #e2e8f0',
      borderRadius:'6px',
      boxShadow: '0 8px 24px rgba(0,0,0,.15)',
      maxHeight: '320px',
      overflowY: 'auto',
      display:   'none',
      minWidth:  '260px',
    });

    function _reposition() {
      const r = input.getBoundingClientRect();
      result.style.top   = (r.bottom + 4) + 'px';
      result.style.left  = r.left + 'px';
      result.style.width = r.width + 'px';
    }

    result.addEventListener('mouseover', e => {
      const row = e.target.closest('[data-trz-item]');
      if (row) row.style.background = '#eff6ff';
    });
    result.addEventListener('mouseout', e => {
      const row = e.target.closest('[data-trz-item]');
      if (row) row.style.background = '';
    });
    result.addEventListener('click', e => {
      const row = e.target.closest('[data-trz-item]');
      if (!row) return;
      result.style.display = 'none';
      try { WMS_MODULES['trazabilidad']._onSelect(JSON.parse(row.dataset.trzItem)); } catch {}
    });

    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { result.style.display = 'none'; return; }
      timer = setTimeout(async () => {
        try {
          const r     = await API.get(endpoint, 'q=' + encodeURIComponent(q));
          const items = Array.isArray(r.data) ? r.data : (Array.isArray(r) ? r : []);
          result.innerHTML = items.length
            ? items.map(item => {
                const encoded = WMS.esc(JSON.stringify(item));
                const label   = item.codigo_interno || item.codigo || String(item.id);
                return `<div data-trz-item="${encoded}"
                   style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;">
                  <b style="color:#1e293b;">${WMS.esc(label)}</b>
                  ${item.nombre ? ` <span style="color:#64748b;">— ${WMS.esc(item.nombre)}</span>` : ''}
                  ${item.tipo   ? `<div style="font-size:11px;color:#94a3b8;margin-top:2px;">${WMS.esc(item.tipo)}${item.zona ? ' · ' + WMS.esc(item.zona) : ''}</div>` : ''}
                </div>`;
              }).join('')
            : '<div style="padding:12px 14px;color:#94a3b8;font-size:13px;"><i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>Sin resultados</div>';
          _reposition();
          result.style.display = 'block';
        } catch(e) {
          result.innerHTML = `<div style="padding:12px 14px;color:#ef4444;font-size:12px;"><i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>Error: ${e.message || 'intente de nuevo'}</div>`;
          _reposition();
          result.style.display = 'block';
        }
      }, 300);
    });

    window.addEventListener('scroll', () => { if (result.style.display !== 'none') _reposition(); }, true);
    window.addEventListener('resize', () => { if (result.style.display !== 'none') _reposition(); });
    document.addEventListener('click', e => {
      if (!result.contains(e.target) && e.target !== input) result.style.display = 'none';
    });
  }

  return {
    _onSelect(item) {
      if (_state.tab === 'producto') {
        _state.productoId = item.id;
        const inp = document.getElementById('trz-prod-input');
        const res = document.getElementById('trz-prod-res');
        if (inp) inp.value = `${item.codigo_interno || ''} — ${item.nombre || ''}`;
        if (res) res.style.display = 'none';
        this._buscarProducto();
      } else {
        _state.ubicacionId = item.id;
        const inp = document.getElementById('trz-ub-input');
        const res = document.getElementById('trz-ub-res');
        if (inp) inp.value = item.codigo || '';
        if (res) res.style.display = 'none';
        this._buscarUbicacion();
      }
    },

    async _buscarProducto() {
      if (!_state.productoId) { WMS.toast('warning', 'Selecciona un producto del listado'); return; }
      const fIni   = document.getElementById('trz-f-ini')?.value || '';
      const fFin   = document.getElementById('trz-f-fin')?.value || '';
      const lote   = document.getElementById('trz-lote')?.value  || '';
      const params = new URLSearchParams({ f_ini: fIni, f_fin: fFin });
      if (lote) params.set('lote', lote);
      WMS.setContent(_loadingHtml('Cargando trazabilidad de referencia...'));
      try {
        const r    = await API.get(`/trazabilidad/producto/${_state.productoId}`, params.toString());
        const data = r.data || r;
        this._renderProducto(data);
      } catch (e) { WMS.toast('error', e.message || 'Error cargando trazabilidad'); this.load(); }
    },

    async _buscarUbicacion() {
      if (!_state.ubicacionId) { WMS.toast('warning', 'Selecciona una ubicación del listado'); return; }
      const fIni   = document.getElementById('trz-ub-f-ini')?.value || document.getElementById('trz-f-ini')?.value || '';
      const fFin   = document.getElementById('trz-ub-f-fin')?.value || document.getElementById('trz-f-fin')?.value || '';
      const params = new URLSearchParams({ f_ini: fIni, f_fin: fFin });
      WMS.setContent(_loadingHtml('Cargando trazabilidad de ubicación...'));
      try {
        const r    = await API.get(`/trazabilidad/ubicacion/${_state.ubicacionId}`, params.toString());
        const data = r.data || r;
        this._renderUbicacion(data);
      } catch (e) { WMS.toast('error', e.message || 'Error cargando trazabilidad'); this.load(); }
    },

    _exportarProducto() {
      if (!_state.productoId) { WMS.toast('warning', 'Selecciona un producto del listado'); return; }
      const fIni   = document.getElementById('trz-f-ini')?.value || '';
      const fFin   = document.getElementById('trz-f-fin')?.value || '';
      const lote   = document.getElementById('trz-lote')?.value  || '';
      const token  = localStorage.getItem('wms_token') || '';
      const params = new URLSearchParams({ f_ini: fIni, f_fin: fFin, export: 'excel', token });
      if (lote) params.set('lote', lote);
      WMS.toast('info', 'Generando reporte...');
      window.open(`${API_BASE}/trazabilidad/producto/${_state.productoId}?${params.toString()}`, '_blank');
    },

    _exportarUbicacion() {
      if (!_state.ubicacionId) { WMS.toast('warning', 'Selecciona una ubicación del listado'); return; }
      const fIni   = document.getElementById('trz-ub-f-ini')?.value || document.getElementById('trz-f-ini')?.value || '';
      const fFin   = document.getElementById('trz-ub-f-fin')?.value || document.getElementById('trz-f-fin')?.value || '';
      const token  = localStorage.getItem('wms_token') || '';
      const params = new URLSearchParams({ f_ini: fIni, f_fin: fFin, export: 'excel', token });
      WMS.toast('info', 'Generando reporte...');
      window.open(`${API_BASE}/trazabilidad/ubicacion/${_state.ubicacionId}?${params.toString()}`, '_blank');
    },

    _renderProducto(data) {
      const p   = data.producto    || {};
      const mov = data.movimientos || [];
      const st  = data.stock_actual || [];
      const fi  = data.filtros     || {};
      const upc = p.unidades_caja  || 1;

      const totalEnt = mov.filter(m => ['Entrada','InvInicial','Reabastecimiento'].includes(m.tipo_movimiento))
                          .reduce((s, m) => s + (m.cantidad_cajas != null ? +m.cantidad_cajas : +m.cantidad || 0), 0);
      const totalSal = mov.filter(m => ['Salida','Picking','AjusteNegativo'].includes(m.tipo_movimiento))
                          .reduce((s, m) => s + (m.cantidad_cajas != null ? +m.cantidad_cajas : +m.cantidad || 0), 0);
      const totalDev = mov.filter(m => m.tipo_movimiento === 'Devolucion')
                          .reduce((s, m) => s + (m.cantidad_cajas != null ? +m.cantidad_cajas : +m.cantidad || 0), 0);
      const totalStk = st.reduce((s, x) => s + (+x.cantidad || 0), 0);

      WMS.setContent(`<div style="padding:0 4px;">
        ${_filtersHtml(fi, true)}
        <div class="pro-kpi-grid" style="grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;">
          ${_kpi('Movimientos', mov.length, 'fa-list', '#3b82f6')}
          ${_kpi('Entradas (cj)', totalEnt.toFixed(2), 'fa-arrow-down', '#22c55e')}
          ${_kpi('Salidas (cj)', totalSal.toFixed(2), 'fa-arrow-up', '#ef4444')}
          ${_kpi('Devoluciones (cj)', totalDev.toFixed(2), 'fa-rotate-left', '#8b5cf6')}
          ${_kpi('Stock actual (und)', WMS.formatNum(totalStk), 'fa-cubes', '#0ea5e9')}
        </div>
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <div style="width:48px;height:48px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;">
              <i class="fa-solid fa-barcode" style="font-size:22px;color:#1d4ed8;"></i>
            </div>
            <div>
              <div style="font-size:18px;font-weight:800;color:#0f172a;">${WMS.esc(p.nombre || '')}</div>
              <div style="font-size:12px;color:#64748b;">Cód: <b>${WMS.esc(p.codigo_interno || '')}</b>${upc > 1 ? ` · <b>${upc}</b> und/caja` : ''}</div>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;">
              <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES['trazabilidad'].load()"><i class="fa-solid fa-arrow-left"></i> Volver</button>
              <button class="btn btn-sm btn-primary" onclick="WMS_MODULES['trazabilidad']._buscarProducto()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
              <button class="btn btn-sm btn-success" onclick="WMS_MODULES['trazabilidad']._exportarProducto()"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
            </div>
          </div>
        </div>
        <div class="card" style="margin-bottom:16px;">
          <div class="card-header"><span class="card-title">
            <i class="fa-solid fa-timeline" style="color:#3b82f6;margin-right:6px;"></i>
            Historial de Movimientos
            <span style="font-weight:400;color:#64748b;font-size:12px;margin-left:6px;">(${mov.length} registros)</span>
          </span></div>
          ${renderMovTable(mov, false)}
        </div>
        ${renderStockActual(st, 'Stock Actual por Ubicación')}
      </div>`);

      ['trz-f-ini', 'trz-f-fin'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => WMS_MODULES['trazabilidad']._buscarProducto());
      });
    },

    _renderUbicacion(data) {
      const ub  = data.ubicacion         || {};
      const mov = data.movimientos       || [];
      const inv = data.inventario_actual || [];
      const fi  = data.filtros           || {};

      const prods     = [...new Set(mov.map(m => m.producto_nombre).filter(Boolean))].length;
      const traslados = mov.filter(m => m.tipo_movimiento === 'Traslado').length;

      WMS.setContent(`<div style="padding:0 4px;">
        ${_filtersHtml(fi, false)}
        <div class="pro-kpi-grid" style="grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
          ${_kpi('Movimientos', mov.length, 'fa-list', '#3b82f6')}
          ${_kpi('Refs. distintas', prods, 'fa-barcode', '#f59e0b')}
          ${_kpi('Traslados', traslados, 'fa-right-left', '#8b5cf6')}
          ${_kpi('Items en stock', inv.length, 'fa-cubes', '#10b981')}
        </div>
        <div class="card" style="margin-bottom:16px;">
          <div style="padding:14px 18px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <div style="width:48px;height:48px;border-radius:50%;background:#fffbeb;display:flex;align-items:center;justify-content:center;">
              <i class="fa-solid fa-location-dot" style="font-size:22px;color:#f59e0b;"></i>
            </div>
            <div>
              <div style="font-size:18px;font-weight:800;color:#0f172a;">${WMS.esc(ub.codigo || '')}</div>
              <div style="font-size:12px;color:#64748b;">
                ${ub.tipo ? `Tipo: <b>${WMS.esc(ub.tipo)}</b>` : ''}${ub.zona ? ` · Zona: <b>${WMS.esc(ub.zona)}</b>` : ''}
              </div>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;">
              <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES['trazabilidad'].load()"><i class="fa-solid fa-arrow-left"></i> Volver</button>
              <button class="btn btn-sm btn-primary" onclick="WMS_MODULES['trazabilidad']._buscarUbicacion()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
              <button class="btn btn-sm btn-success" onclick="WMS_MODULES['trazabilidad']._exportarUbicacion()"><i class="fa-solid fa-file-excel"></i> Exportar Excel</button>
            </div>
          </div>
        </div>
        ${inv.length ? `<div class="card" style="margin-bottom:16px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-cubes" style="color:#10b981;margin-right:6px;"></i>Inventario Actual en Ubicación</span></div>
          <div class="table-container"><table class="erp-table" style="font-size:12px;">
            <thead><tr><th>Referencia</th><th>Lote</th><th>F. Vencimiento</th><th style="text-align:center;">Cant.</th><th style="text-align:center;">Reservado</th><th>Estado</th><th>Pallet</th></tr></thead>
            <tbody>${inv.map(s => {
              const upc2 = s.unidades_caja || 1;
              const cj2  = upc2 > 1 ? (s.cantidad / upc2).toFixed(2) + ' cj' : '';
              return `<tr>
                <td><b style="font-size:12px;">${WMS.esc(s.codigo_interno || '')}</b><div style="font-size:10px;color:#64748b;">${WMS.esc(s.producto_nombre || '')}</div></td>
                <td style="font-size:11px;">${WMS.esc(s.lote || '—')}</td>
                <td style="font-size:11px;">${s.fecha_vencimiento ? WMS.formatDate(s.fecha_vencimiento) : '—'}</td>
                <td style="text-align:center;">${cj2 ? `<b>${cj2}</b><div style="font-size:10px;color:#64748b;">${WMS.formatNum(s.cantidad)} und</div>` : `<b>${WMS.formatNum(s.cantidad)}</b>`}</td>
                <td style="text-align:center;font-size:11px;color:${s.cantidad_reservada > 0 ? '#f59e0b' : '#94a3b8'};">${WMS.formatNum(s.cantidad_reservada || 0)}</td>
                <td><span class="badge ${s.estado === 'Disponible' ? 'badge-success' : 'badge-warning'}" style="font-size:10px;">${WMS.esc(s.estado || '')}</span></td>
                <td style="font-size:11px;">${WMS.esc(s.numero_pallet || '—')}</td>
              </tr>`;
            }).join('')}</tbody>
          </table></div>
        </div>` : ''}
        <div class="card">
          <div class="card-header"><span class="card-title">
            <i class="fa-solid fa-timeline" style="color:#3b82f6;margin-right:6px;"></i>
            Movimientos en Ubicación
            <span style="font-weight:400;color:#64748b;font-size:12px;margin-left:6px;">(${mov.length} registros)</span>
          </span></div>
          ${renderMovTable(mov, true)}
        </div>
      </div>`);

      ['trz-f-ini', 'trz-f-fin'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => WMS_MODULES['trazabilidad']._buscarUbicacion());
      });
    },

    load(sub) {
      _state = { tab: sub || 'producto', productoId: null, ubicacionId: null };
      const fIni = _toDate(-90);
      const fFin = _toDate(0);

      WMS.setContent(`<div style="padding:0 4px;">
        <div style="display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;">
          ${_tabBtn('producto',  'Trazabilidad de Referencia', 'fa-barcode',      _state.tab)}
          ${_tabBtn('ubicacion', 'Trazabilidad de Ubicación',  'fa-location-dot', _state.tab)}
        </div>

        <div id="trz-panel-producto" style="display:${_state.tab === 'producto' ? 'block' : 'none'};">
          <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-barcode" style="color:#1d4ed8;margin-right:6px;"></i>Buscar Referencia</span></div>
            <div style="padding:18px;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
              <div style="flex:2;min-width:220px;position:relative;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">REFERENCIA / PRODUCTO</label>
                <input id="trz-prod-input" class="form-control" placeholder="Código o nombre del producto..." autocomplete="off">
                <div id="trz-prod-res"></div>
              </div>
              <div style="min-width:130px;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">DESDE</label>
                <input type="date" id="trz-f-ini" class="form-control" value="${fIni}">
              </div>
              <div style="min-width:130px;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">HASTA</label>
                <input type="date" id="trz-f-fin" class="form-control" value="${fFin}">
              </div>
              <div style="min-width:120px;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">LOTE (opc.)</label>
                <input id="trz-lote" class="form-control" placeholder="Lote...">
              </div>
              <button class="btn btn-primary" style="height:38px;padding:0 22px;" onclick="WMS_MODULES['trazabilidad']._buscarProducto()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
              </button>
            </div>
          </div>
        </div>

        <div id="trz-panel-ubicacion" style="display:${_state.tab === 'ubicacion' ? 'block' : 'none'};">
          <div class="card" style="margin-bottom:20px;">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-location-dot" style="color:#f59e0b;margin-right:6px;"></i>Buscar Ubicación</span></div>
            <div style="padding:18px;display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
              <div style="flex:2;min-width:220px;position:relative;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">CÓDIGO / NOMBRE UBICACIÓN</label>
                <input id="trz-ub-input" class="form-control" placeholder="Ej: A-01-01 o Recibo..." autocomplete="off">
                <div id="trz-ub-res"></div>
              </div>
              <div style="min-width:130px;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">DESDE</label>
                <input type="date" id="trz-ub-f-ini" class="form-control" value="${fIni}">
              </div>
              <div style="min-width:130px;">
                <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:#64748b;">HASTA</label>
                <input type="date" id="trz-ub-f-fin" class="form-control" value="${fFin}">
              </div>
              <button class="btn btn-primary" style="height:38px;padding:0 22px;" onclick="WMS_MODULES['trazabilidad']._buscarUbicacion()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
              </button>
            </div>
          </div>
        </div>
      </div>`);

      document.querySelectorAll('.trz-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const t = btn.dataset.tab;
          _state.tab = t;
          document.querySelectorAll('.trz-tab-btn').forEach(b => {
            b.style.borderBottom = b.dataset.tab === t ? '3px solid #1d4ed8' : '3px solid transparent';
            b.style.color        = b.dataset.tab === t ? '#1d4ed8' : '#64748b';
          });
          document.getElementById('trz-panel-producto').style.display  = t === 'producto'  ? 'block' : 'none';
          document.getElementById('trz-panel-ubicacion').style.display = t === 'ubicacion' ? 'block' : 'none';
          _state.productoId  = null;
          _state.ubicacionId = null;
        });
      });

      _setupTypeahead('trz-prod-input', 'trz-prod-res', '/trazabilidad/buscar-producto');
      _setupTypeahead('trz-ub-input',   'trz-ub-res',   '/trazabilidad/buscar-ubicacion');

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES['trazabilidad'].load('producto')">
          <i class="fa-solid fa-barcode"></i> Por Referencia
        </button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES['trazabilidad'].load('ubicacion')">
          <i class="fa-solid fa-location-dot"></i> Por Ubicación
        </button>`);
    }
  };
})();
