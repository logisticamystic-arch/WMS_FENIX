/* ============================================================
   WMS Desktop — Módulo DESPACHO & TMS
   Sub-vistas: certificacion | cargue | dashboard | tms
   ============================================================ */
WMS_MODULES.despacho = {
  load(sub) {
    WMS.setBreadcrumb('despacho', this.subLabel(sub));
    WMS.renderSidebar('despacho');
    const s = sub || 'certificacion';
    const fn = {
      certificacion: this.show_certificacion, cargue: this.show_cargue,
      dashboard: this.show_dashboard, tms: this.show_tms,
    };
    (fn[s]?.bind(this) || fn.certificacion.bind(this))();
    // Certificación es proceso crítico: auto-refresh activo
    if (s === 'certificacion') this.startAutoRefresh();
    else this.stopAutoRefresh();
  },

  // ── Auto-refresh certificación (proceso crítico, máx 5 usuarios) ──────────
  _certInterval: null,
  startAutoRefresh() {
    this.stopAutoRefresh();
    this._certInterval = setInterval(() => {
      if (WMS.currentModule !== 'despacho') { this.stopAutoRefresh(); return; }
      if (WMS.currentSubModule === 'certificacion') this.show_certificacion(true);
      else this.stopAutoRefresh();
    }, 30000);
    this._updateAutoRefreshBadge(true);
  },
  stopAutoRefresh() {
    if (this._certInterval) { clearInterval(this._certInterval); this._certInterval = null; }
    this._updateAutoRefreshBadge(false);
  },
  _updateAutoRefreshBadge(active) {
    const badge = document.getElementById('cert-refresh-badge');
    if (badge) badge.style.display = active ? 'inline-flex' : 'none';
  },

  subLabel(s) {
    const m = { certificacion:'Certificación de Pedidos', cargue:'Planilla de Cargue',
      dashboard:'Dashboard Certificación', tms:'Integración TMS' };
    return m[s] || s || 'Panel';
  },

  // ── CERTIFICACIÓN (POR SUCURSAL) ───────────────────────────────
  async show_certificacion(silent = false) {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
      <span id="cert-refresh-badge" style="display:inline-flex;align-items:center;gap:5px;background:#198754;color:#fff;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:600;">
        <span style="width:7px;height:7px;border-radius:50%;background:#fff;animation:pulse-dot 1.2s infinite;display:inline-block;"></span> Auto 30s
      </span>`);
    if (!silent) WMS.spinner();
    try {
      const r = await API.get('/picking/certificacion/pendientes');
      const items = r.data || r || [];
      
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar sucursal..." oninput="WMS_MODULES.despacho.filterTable(this.value,'cert-table')">
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Pendientes por Certificar (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="cert-table">
              <thead><tr><th>Sucursal de Entrega</th><th class="text-center">Pedidos</th><th class="text-center">Líneas Totales</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(s => `<tr>
                <td><strong>${WMS.esc(s.sucursal_entrega || 'Sin Sucursal')}</strong></td>
                <td class="text-center">${s.total_pedidos}</td>
                <td class="text-center">${s.total_lineas}</td>
                <td><span class="status-chip status-creada">Listo para Certificar</span></td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.despacho.iniciarCertificacion('${WMS.esc(s.sucursal_entrega)}')">
                    <i class="fa-solid fa-barcode"></i> Iniciar Certificación
                  </button>
                </div></td>
              </tr>`).join('') || '<tr><td colspan="5" class="table-empty">Sin sucursales pendientes de certificación</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  async iniciarCertificacion(sucursal) {
    WMS.spinner();
    try {
      const imps = (await API.get('/impresoras')).data || [];
      this._showPackingDialog(sucursal, imps);
    } catch(e) { WMS.toast('error', 'Error al cargar impresoras'); }
  },

  _showPackingDialog(sucursal, impresoras) {
    const mkOpts = (tipo) => impresoras
      .filter(i => !i.tipos_trabajo?.length || i.tipos_trabajo.includes(tipo))
      .map(i => `<option value="${i.id}">${WMS.esc(i.nombre)}</option>`)
      .join('');

    const html = `
      <div id="packing-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px 32px;min-width:420px;max-width:500px;box-shadow:0 8px 40px rgba(0,0,0,.25);">
          <h3 style="margin:0 0 20px;color:#1e293b;font-size:17px;">
            <i class="fa-solid fa-boxes-packing"></i> Iniciar Packing — <span style="color:#1e40af;">${WMS.esc(sucursal)}</span>
          </h3>
          <div style="margin-bottom:16px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:8px;">Tipo de empaque</label>
            <div style="display:flex;gap:16px;">
              ${['canasta','caja','paquete'].map((t,i) => `
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                  <input type="radio" name="pk-tipo" value="${t}" ${i===0?'checked':''}> ${t.charAt(0).toUpperCase()+t.slice(1)}
                </label>`).join('')}
            </div>
          </div>
          <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora stickers</label>
            <select id="pd-imp-sticker" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('sticker_packing')}
            </select>
          </div>
          <div style="margin-bottom:22px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora documento</label>
            <select id="pd-imp-doc" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('documento_packing')}
            </select>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('packing-dialog-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" data-sucursal="${WMS.esc(sucursal)}" onclick="WMS_MODULES.despacho._confirmarDialogPacking(this.dataset.sucursal)">
              <i class="fa-solid fa-play"></i> Iniciar
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async _confirmarDialogPacking(sucursal) {
    const tipo       = document.querySelector('input[name="pk-tipo"]:checked')?.value || 'caja';
    const impSticker = document.getElementById('pd-imp-sticker')?.value || null;
    const impDoc     = document.getElementById('pd-imp-doc')?.value || null;
    document.getElementById('packing-dialog-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion', {
        sucursal_entrega:     sucursal,
        tipo_empaque:         tipo,
        impresora_sticker_id: impSticker || null,
        impresora_doc_id:     impDoc || null,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      await this.show_packing(r.data.sesion_id);
    } catch(e) { WMS.toast('error', 'Error al iniciar sesión de packing'); }
  },

  _renderCertInterface(sucursal, lineas) {
    const totalLines = lineas.length;
    const certLines  = lineas.filter(l => l.cantidad_certificada > 0).length;
    const progress   = totalLines > 0 ? Math.round((certLines / totalLines) * 100) : 0;

    WMS.setContent(`
      <div class="cert-workflow-container">
        <div class="cert-header">
          <div class="cert-header-left">
            <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
              <i class="fa-solid fa-arrow-left"></i> Volver
            </button>
            <h2 class="cert-title">Certificando: <strong>${WMS.esc(sucursal)}</strong></h2>
          </div>
          <div class="cert-progress-box">
             <div class="cert-progress-info">
               <span>Progreso: <strong>${certLines} / ${totalLines}</strong> líneas</span>
               <span>${progress}%</span>
             </div>
             <div class="pro-progress-bar-bg"><div class="pro-progress-bar-fill ${progress>=100?'green':''}" style="width:${progress}%"></div></div>
          </div>
        </div>

        <div class="cert-body">
          <div class="cert-scanner-box">
            <div class="scanner-input-wrap">
              <i class="fa-solid fa-barcode"></i>
              <input type="text" id="cert-scanner" placeholder="Escanee producto o ingrese código..." 
                     onkeyup="if(event.key==='Enter') WMS_MODULES.despacho.procesarEscaneo('${WMS.esc(sucursal)}')">
              <button class="btn btn-primary" onclick="WMS_MODULES.despacho.procesarEscaneo('${WMS.esc(sucursal)}')">Validar</button>
            </div>
            <p class="text-muted text-sm" style="margin-top:8px;"><i class="fa-solid fa-keyboard"></i> También puede seleccionar un producto de la lista para certificarlo manualmente.</p>
          </div>

          <div class="card" style="margin-top:20px;">
            <div class="table-container" style="max-height:calc(100vh - 350px); overflow-y:auto;">
              <table class="erp-table" id="table-cert-lines">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-center">EAN/Código</th>
                    <th class="text-center">Pickeado</th>
                    <th class="text-center">Certificado</th>
                    <th class="text-center">Diferencia</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acción</th>
                  </tr>
                </thead>
                <tbody>
                  ${lineas.map(l => {
                    const diff = l.cantidad_pickeada - l.cantidad_certificada;
                    const st = l.cantidad_certificada === 0 ? 'pendiente' : (diff === 0 ? 'ok' : 'error');
                    return `
                    <tr id="cert-row-${l.producto_id}" class="cert-row-${st}" data-ean="${WMS.esc(l.ean)}" data-codigo="${WMS.esc(l.codigo)}">
                      <td>
                        <div class="fw-700">${WMS.esc(l.nombre)}</div>
                      </td>
                      <td class="text-center"><code style="font-size:11px;">${WMS.esc(l.ean)}</code></td>
                      <td class="text-center fw-700" style="font-size:1.1rem;">${WMS.formatNum(l.cantidad_pickeada)}</td>
                      <td class="text-center fw-700" style="font-size:1.1rem; color:var(--primary);">${WMS.formatNum(l.cantidad_certificada)}</td>
                      <td class="text-center">
                         ${l.cantidad_certificada > 0 ? (diff === 0 ? '<span class="status-badge success"><i class="fa-solid fa-check"></i></span>' : `<span class="badge badge-danger">${diff > 0 ? '-' : '+'}${WMS.formatNum(Math.abs(diff))}</span>`) : '—'}
                      </td>
                      <td class="text-center">
                         <span class="pro-badge ${st === 'ok' ? 'ok' : st === 'error' ? 'warn' : 'info'}">
                           ${st === 'ok' ? 'Correcto' : st === 'error' ? 'Diferencia' : 'Pendiente'}
                         </span>
                      </td>
                      <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho.manualCert('${WMS.esc(sucursal)}', ${l.producto_id}, '${WMS.esc(l.nombre)}', ${l.cantidad_pickeada}, ${l.cantidad_certificada})">
                          <i class="fa-solid fa-edit"></i>
                        </button>
                      </td>
                    </tr>`;
                  }).join('')}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="cert-footer">
          <div class="cert-footer-left">
            <span class="text-muted">Sucursal: ${WMS.esc(sucursal)}</span>
          </div>
          <div class="cert-footer-actions">
            <button class="btn btn-danger btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">Cancelar Proceso</button>
            <button class="btn btn-success" onclick="WMS_MODULES.despacho.finalizarCertificacion('${WMS.esc(sucursal)}')" ${progress < 100 ? 'disabled title="Certifique todas las líneas antes de finalizar"' : ''}>
              <i class="fa-solid fa-check-double"></i> Finalizar y Generar PDF
            </button>
          </div>
        </div>
      </div>
    `);
    
    // Auto-focus scanner
    setTimeout(() => document.getElementById('cert-scanner')?.focus(), 200);
  },

  async procesarEscaneo(sucursal) {
    const input = document.getElementById('cert-scanner');
    const val   = input.value.trim();
    if (!val) return;
    
    // Buscar en la tabla por EAN o Código
    const rows = document.querySelectorAll('#table-cert-lines tbody tr');
    let match = null;
    rows.forEach(r => {
        if (r.dataset.ean === val || r.dataset.codigo === val) match = r;
    });

    if (match) {
        const pid = match.id.replace('cert-row-', '');
        // For simplicity, we ask for quantity even on scan if it's not a single unit scan flow
        // Or we can just cert the whole picked qty
        const nombre = match.querySelector('div.fw-700').textContent;
        const pick   = parseFloat(match.cells[2].textContent);
        const cert   = parseFloat(match.cells[3].textContent);
        
        input.value = '';
        this.manualCert(sucursal, pid, nombre, pick, cert);
    } else {
        WMS.toast('error', 'Producto no encontrado en este despacho');
        input.select();
    }
  },

  manualCert(sucursal, pid, nombre, pick, actual) {
    const nueva = prompt(`Certificando: ${nombre}\n\nCantidad Pickeada: ${pick}\nIngrese la cantidad encontrada:`, actual || pick);
    if (nueva === null || nueva === "" || isNaN(nueva)) return;

    this.confirmarLineaCert(sucursal, pid, parseFloat(nueva));
  },

  async confirmarLineaCert(sucursal, pid, cantidad) {
    WMS.spinner();
    try {
        const r = await API.post('/picking/certificacion/confirmar', {
            sucursal_entrega: sucursal,
            producto_id: pid,
            cantidad: cantidad
        });
        if (r.error) WMS.toast('error', r.message);
        else {
            WMS.toast('success', 'Línea certificada');
            this.iniciarCertificacion(sucursal); // Refresh
        }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async finalizarCertificacion(sucursal) {
    if (!confirm('¿Desea finalizar la certificación de ' + sucursal + '? Se generarán las novedades si existen diferencias.')) return;
    WMS.spinner();
    try {
        const r = await API.post('/picking/certificacion/finalizar', { sucursal_entrega: sucursal });
        if (r.error) WMS.toast('error', r.message);
        else {
            WMS.toast('success', 'Certificación finalizada exitosamente');
            
            // Intentar imprimir automáticamente
            try {
                const rp = await API.get('/picking/certificacion/imprimir/' + encodeURIComponent(sucursal));
                if (rp.error) WMS.toast('warning', 'Certificado finalizado pero error en impresión: ' + rp.message);
                else {
                    const labelMsg = rp.label?.error ? 'Error Etiqueta: ' + rp.label.message : 'Etiqueta impresa OK';
                    const docMsg   = rp.document?.error ? 'Error Documento: ' + rp.document.message : 'Documento impreso OK';
                    WMS.toast('success', `Impresión: ${labelMsg} | ${docMsg}`);
                }
            } catch(e) { WMS.toast('warning', 'Error al intentar imprimir'); }

            this.show_certificacion();
        }
    } catch(e) { WMS.toast('error', 'Error finalizando'); }
  },

  async verPlanilla(id) {
    WMS.spinner();
    try {
      const [r, ra] = await Promise.all([
        API.get('/planillas/cert/' + id),
        API.get('/planillas/cert/' + id + '/analytics')
      ]);
      const p = r.data || r;
      const analytics = ra.data || ra;
      const lineas = p.detalles || [];
      
      const stats = analytics.overview || {};
      const kpis  = analytics.kpis || {};

      WMS.showRightPanel('Análisis de Planilla #' + (p.numero_planilla || id), `
        <div class="inv-commander-root" style="padding:0; background:transparent;">
          <div class="kpi-dashboard-row" style="grid-template-columns: repeat(3, 1fr); gap:12px; margin-bottom:15px;">
            <div class="kpi-dashboard-card gold" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Eficiencia</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${kpis.lines_per_minute || 0} L/min</span>
              </div>
            </div>
            <div class="kpi-dashboard-card blue" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Exactitud</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${stats.accuracy || 0}%</span>
              </div>
            </div>
            <div class="kpi-dashboard-card green" style="padding:12px;">
              <div class="kpi-dash-info">
                 <span class="kpi-dash-label">Progreso</span>
                 <span class="kpi-dash-value" style="font-size:1.1rem;">${kpis.progress_pct || 0}%</span>
              </div>
            </div>
          </div>

          <div class="table-container" style="max-height:400px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:4px;">
            <table class="erp-table">
              <thead style="position:sticky; top:0; background:#f8fafc; z-index:10;">
                <tr>
                  <th>Producto / SKU</th>
                  <th class="text-center">Sist.</th>
                  <th class="text-center">Cert.</th>
                  <th class="text-center">Diff.</th>
                  <th class="text-center">Estado</th>
                  <th class="text-center">Acción</th>
                </tr>
              </thead>
              <tbody>${lineas.map(l => {
                const diff = (l.cantidad_esperada||0) - (l.cantidad_certificada||0);
                return `
                <tr class="${diff !== 0 && l.cantidad_certificada > 0 ? 'diff-detected' : ''}">
                  <td>
                    <div class="fw-600">${WMS.esc(l.producto_nombre)}</div>
                    <div class="text-muted text-sm" style="font-family:monospace;">${WMS.esc(l.producto_codigo)}</div>
                  </td>
                  <td class="text-center fw-700">${WMS.formatNum(l.cantidad_esperada)}</td>
                  <td class="text-center fw-700" style="color:#1a56db;">${WMS.formatNum(l.cantidad_certificada)}</td>
                  <td class="text-center">
                    ${diff === 0 ? '<span class="status-badge success"><i class="fa-solid fa-check"></i></span>' : `<span class="badge badge-danger">${diff > 0 ? '-' : '+'}${WMS.formatNum(Math.abs(diff))}</span>`}
                  </td>
                  <td class="text-center">
                    <span class="badge ${l.es_correcto ? 'badge-success' : 'badge-warning'}">${l.es_correcto ? 'Validado' : 'Pendiente'}</span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho.adminOverride(${p.id}, ${l.id}, '${WMS.esc(l.producto_nombre)}', ${l.cantidad_certificada})">
                      <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                  </td>
                </tr>`;
              }).join('') || '<tr><td colspan="6" class="table-empty">Sin líneas en este documento</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cerrar Ventana</button>
         ${p.estado === 'ConNovedad' ? `<button class="btn btn-warning" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-triangle-exclamation"></i> Forzar Salida</button>` : ''}
         ${p.estado === 'Completada' ? `<button class="btn btn-primary" onclick="WMS_MODULES.despacho.generarCargue(${id})"><i class="fa-solid fa-truck-loading"></i> Proceder a Cargue</button>` : ''}`);
    } catch(e) { 
        console.error(e);
        WMS.toast('error', 'Error cargando analítica de planilla'); 
    }
  },

  async adminOverride(certId, detId, nombre, actual) {
    const nueva = prompt(`[ADMIN OVERRIDE] Corregir cantidad para:\n${nombre}\n\nCantidad actual registrada: ${actual}\nIngrese la cantidad real:`, actual);
    if (nueva === null || nueva === "" || isNaN(nueva)) return;
    
    WMS.spinner();
    try {
        const r = await API.post('/planillas/cert/' + certId + '/editar', {
            detalle_id: detId,
            cantidad: parseFloat(nueva)
        });
        if (r.error) WMS.toast('error', r.message);
        else {
            WMS.toast('success', 'Cantidad corregida exitosamente');
            this.verPlanilla(certId); // Refresh modal
        }
    } catch(e) { WMS.toast('error', 'Error en el override'); }
  },

  async asignarCert(planillaId) {
    let personal = [];
    try {
      const r = await API.get('/param/personal', 'activo=1&limit=100');
      personal = r.data || r || [];
    } catch(e) {}
    WMS.showRightPanel('Asignar Certificador', `
      <div class="form-group"><label class="form-label">Certificador <span class="required">*</span></label>
        <select id="cert-personal" class="form-control">
          <option value="">Seleccionar...</option>
          ${personal.map(p => `<option value="${p.id}">${WMS.esc(p.nombre||'')} — ${WMS.esc(p.rol||'')}</option>`).join('')}
        </select></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.confirmarAsigCert(${planillaId})"><i class="fa-solid fa-user-check"></i> Asignar</button>`);
  },

  async confirmarAsigCert(id) {
    const pid = document.getElementById('cert-personal')?.value;
    if (!pid) { WMS.toast('warning', 'Seleccione un certificador'); return; }
    try {
      const r = await API.post('/planillas/asignar', { planilla_id: id, personal_id: parseInt(pid) });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Certificador asignado'); WMS.closeRightPanel(); this.show_certificacion(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  async importarPlanillas() {
    WMS.showModal('Importar Planillas de Certificación', `
      <p class="text-muted" style="margin-bottom:12px;">Suba un CSV con: numero_planilla, cliente, ruta, codigo_ean, cantidad</p>
      <div class="form-group">
        <a href="/WMS_FENIX/public/api/param/import-export/template/planillas" target="_blank" class="btn btn-secondary btn-sm"><i class="fa-solid fa-download"></i> Descargar Plantilla</a>
      </div>
      <div class="form-group"><label class="form-label">Archivo CSV</label>
        <input type="file" id="plan-csv" class="form-control" accept=".csv"></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.uploadPlanillas()"><i class="fa-solid fa-upload"></i> Importar</button>`);
  },

  async uploadPlanillas() {
    const file = document.getElementById('plan-csv')?.files[0];
    if (!file) { WMS.toast('warning', 'Seleccione un archivo CSV'); return; }
    const fd = new FormData(); fd.append('file', file);
    try {
      const r = await fetch('/WMS_FENIX/public/api/planillas/importar', {
        method: 'POST', headers: { Authorization: 'Bearer ' + localStorage.getItem('wms_token') }, body: fd
      });
      const j = await r.json();
      if (j.error) WMS.toast('error', j.message);
      else { WMS.toast('success', 'Importación: ' + (j.importadas||0) + ' planilla(s)'); WMS.closeModal('generic-modal'); this.show_certificacion(); }
    } catch(e) { WMS.toast('error', 'Error importando'); }
  },

  // ── PLANILLA DE CARGUE ────────────────────────────────────────
  async show_cargue() {
    WMS.setToolbar(`<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.nuevoPlanillaCargue()"><i class="fa-solid fa-plus"></i> Nuevo Cargue</button>`);
    WMS.spinner();
    try {
      const r = await API.get('/despachos', 'limit=100');
      const items = r.data || r || [];
      const stChip = s => {
        const m = { Pendiente:'status-creada', 'En Cargue':'status-en-proceso', Despachado:'status-cerrada', Cancelado:'status-cancelada' };
        return `<span class="status-chip ${m[s]||'status-creada'}">${WMS.esc(s)}</span>`;
      };
      WMS.setContent(`
        <div class="filter-bar">
          <div class="search-bar"><i class="fa-solid fa-search"></i>
            <input placeholder="Buscar placa, conductor, ruta..." oninput="WMS_MODULES.despacho.filterTable(this.value,'cargue-table')">
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-truck-loading"></i> Planillas de Cargue (${items.length})</span></div>
          <div class="table-container">
            <table class="erp-table" id="cargue-table">
              <thead><tr><th>N° Planilla</th><th>Placa</th><th>Conductor</th><th>Ruta</th><th>Planillas Cert.</th><th>Estado</th><th>Fecha</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(d => `<tr>
                <td><span class="badge badge-info">${WMS.esc(d.planilla_numero||d.numero||('#'+d.id))}</span></td>
                <td><strong>${WMS.esc(d.placa||'-')}</strong></td>
                <td>${WMS.esc(d.conductor||'-')}</td>
                <td>${WMS.esc(d.ruta||'-')}</td>
                <td class="text-center">${d.total_planillas||d.planillas||0}</td>
                <td>${stChip(d.estado||'Pendiente')}</td>
                <td>${WMS.formatDate(d.created_at)||'-'}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho.verCargue(${d.id})"><i class="fa-solid fa-eye"></i></button>
                  ${d.estado==='En Cargue'||d.estado==='Pendiente' ? `<button class="btn btn-sm btn-success" onclick="WMS_MODULES.despacho.despacharCargue(${d.id})"><i class="fa-solid fa-truck"></i> Despachar</button>` : ''}
                </div></td>
              </tr>`).join('') || '<tr><td colspan="8" class="table-empty">Sin planillas de cargue</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  nuevoPlanillaCargue() {
    WMS.showRightPanel('Nueva Planilla de Cargue', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">Placa del Vehículo <span class="required">*</span></label><input id="car-placa" class="form-control" placeholder="ABC-123"></div>
        <div class="form-group"><label class="form-label">Conductor <span class="required">*</span></label><input id="car-conductor" class="form-control" placeholder="Nombre del conductor"></div>
        <div class="form-group"><label class="form-label">Ruta <span class="required">*</span></label><input id="car-ruta" class="form-control" placeholder="Ej: Bogotá Norte"></div>
        <div class="form-group"><label class="form-label">N° Precinto</label><input id="car-precinto" class="form-control" placeholder="PRE-001"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Observaciones</label><input id="car-obs" class="form-control" placeholder="Notas adicionales"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho.saveCargue()"><i class="fa-solid fa-save"></i> Crear Cargue</button>`);
  },

  async saveCargue() {
    const placa = document.getElementById('car-placa')?.value.trim();
    const conductor = document.getElementById('car-conductor')?.value.trim();
    const ruta = document.getElementById('car-ruta')?.value.trim();
    if (!placa || !conductor || !ruta) { WMS.toast('warning', 'Placa, Conductor y Ruta son requeridos'); return; }
    try {
      const r = await API.post('/despachos', {
        placa, conductor, ruta,
        numero_precinto: document.getElementById('car-precinto')?.value.trim()||null,
        observaciones: document.getElementById('car-obs')?.value.trim()||null,
      });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Cargue creado'); WMS.closeRightPanel(); this.show_cargue(); }
    } catch(e) { WMS.toast('error', 'Error guardando'); }
  },

  async verCargue(id) {
    try {
      const r = await API.get('/despachos/' + id);
      const d = r.data || r;
      const planillas = d.planillas || d.detalles || [];
      WMS.showRightPanel('Cargue #' + (d.planilla_numero || id), `
        <div class="form-grid form-grid-2" style="margin-bottom:16px;">
          <div><label class="form-label">Placa</label><p>${WMS.esc(d.placa||'-')}</p></div>
          <div><label class="form-label">Conductor</label><p>${WMS.esc(d.conductor||'-')}</p></div>
          <div><label class="form-label">Ruta</label><p>${WMS.esc(d.ruta||'-')}</p></div>
          <div><label class="form-label">Estado</label><p><span class="badge badge-info">${WMS.esc(d.estado||'')}</span></p></div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Planilla</th><th>Cliente</th><th>Ruta</th><th>Estado</th></tr></thead>
            <tbody>${planillas.map(p => `<tr>
              <td>${WMS.esc(p.numero_planilla||('-'))}</td>
              <td>${WMS.esc(p.cliente||'-')}</td>
              <td>${WMS.esc(p.ruta||'-')}</td>
              <td><span class="badge badge-success">${WMS.esc(p.estado||'')}</span></td>
            </tr>`).join('') || '<tr><td colspan="4" class="table-empty">Sin planillas</td></tr>'}
            </tbody>
          </table>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">Cerrar</button>`);
    } catch(e) { WMS.toast('error', 'Error cargando detalle'); }
  },

  generarCargue(planillaId) {
    this.nuevoPlanillaCargue();
    // Pre-populate could be done here in a real flow
  },

  async despacharCargue(id) {
    if (!confirm('¿Confirmar despacho? El vehículo saldrá con las planillas asignadas.')) return;
    try {
      const r = await API.post('/despachos/' + id + '/cerrar', {});
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Despacho confirmado'); this.show_cargue(); }
    } catch(e) { WMS.toast('error', 'Error'); }
  },

  // ── DASHBOARD CERTIFICACIÓN — Professional Command Center ───────────────────
  async show_dashboard() {
    WMS.setToolbar(`
      <button class="pro-btn-refresh" onclick="WMS_MODULES.despacho.show_dashboard()">
        <span class="spin"><i class="fa-solid fa-rotate-right"></i></span> Actualizar
      </button>
    `);
    WMS.spinner();
    try {
      const [certDash, planillas] = await Promise.all([
        API.get('/planillas/cert/dashboard'),
        API.get('/planillas/progreso'),
      ]);
      const d       = certDash.data || certDash || {};
      const progreso = planillas.data || planillas || [];

      const totalP    = d.total      || progreso.length || 0;
      const accurateP = d.completadas || progreso.filter(p => p.archivo?.estado==='Certificada').length || 0;
      const iraCert   = totalP > 0 ? Math.round((accurateP / totalP) * 100) : 100;
      const iraColor  = iraCert >= 95 ? 'accent-green' : iraCert >= 80 ? 'accent-amber' : 'accent-red';

      WMS.setContent(`
<div class="pro-dashboard">

  <!-- KPIs -->
  <div class="pro-kpi-grid" style="grid-template-columns:repeat(4,1fr)">

    <div class="pro-kpi-card accent-blue">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-clipboard-check"></i></div>
        <span class="pro-kpi-trend neu">Total</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(totalP)}</div>
      <div class="pro-kpi-label">Planillas en ciclo</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-file-lines" style="color:#0070f2;margin-right:4px"></i>Documentos activos</div>
    </div>

    <div class="pro-kpi-card accent-amber">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-spinner"></i></div>
        <span class="pro-kpi-trend neu">Ahora</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(d.en_proceso||0)}</div>
      <div class="pro-kpi-label">En certificación</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-bolt" style="color:#e8a000;margin-right:4px"></i>Operación activa</div>
    </div>

    <div class="pro-kpi-card ${iraColor}">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-bullseye"></i></div>
        <span class="pro-kpi-trend ${iraCert>=95?'up':iraCert>=80?'neu':'down'}">${iraCert>=95?'Excelente':iraCert>=80?'Normal':'Bajo'}</span>
      </div>
      <div class="pro-kpi-value">${iraCert}%</div>
      <div class="pro-kpi-label">IRA Certificación</div>
      <div class="pro-kpi-sub">
        <div class="pro-progress-bar-bg" style="margin-top:4px">
          <div class="pro-progress-bar-fill ${iraCert>=95?'green':iraCert>=80?'':'red'}" style="width:${iraCert}%"></div>
        </div>
      </div>
    </div>

    <div class="pro-kpi-card accent-red">
      <div class="pro-kpi-header">
        <div class="pro-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <span class="pro-kpi-trend ${(d.con_novedad||0)>0?'down':'up'}">${(d.con_novedad||0)>0?'Alerta':'OK'}</span>
      </div>
      <div class="pro-kpi-value">${WMS.formatNum(d.con_novedad||0)}</div>
      <div class="pro-kpi-label">Con novedades</div>
      <div class="pro-kpi-sub"><i class="fa-solid fa-bell" style="color:#e03030;margin-right:4px"></i>Requieren atención</div>
    </div>
  </div>

  <!-- Tabla progreso planillas (expandible) -->
  <div class="pro-table-card" id="cert-table-card">
    <div class="pro-table-header" onclick="document.getElementById('cert-table-card').classList.toggle('collapsed')">
      <div class="pro-table-header-left">
        <span class="pro-table-title"><i class="fa-solid fa-microchip" style="margin-right:8px;color:#7c3aed"></i>Monitoreo de Procesos en Tiempo Real</span>
        <span class="pro-table-count">${progreso.length}</span>
      </div>
      <span class="pro-table-toggle"><i class="fa-solid fa-chevron-down"></i></span>
    </div>
    <div class="pro-table-body">
      <div class="pro-table-toolbar">
        <input class="pro-table-search" placeholder="Buscar planilla, documento…"
               oninput="WMS_MODULES.despacho._filterCertTable(this.value)">
        <select class="pro-table-filter-select" onchange="WMS_MODULES.despacho._filterCertEstado(this.value)">
          <option value="">Todos los estados</option>
          <option value="Certificada">Certificada</option>
          <option value="En Proceso">En Proceso</option>
          <option value="Pendiente">Pendiente</option>
        </select>
      </div>
      <div class="pro-table-wrap">
        <table class="erp-table" id="cert-table">
          <thead><tr>
            <th>Planilla / Documento</th>
            <th>Estado</th>
            <th style="text-align:center">Líneas</th>
            <th style="text-align:center">Unidades</th>
            <th style="min-width:160px">Progreso</th>
            <th style="text-align:center">Acción</th>
          </tr></thead>
          <tbody id="cert-tbody">
            ${progreso.map(p => {
              const pct     = p.pct_archivo || 0;
              const estado  = p.archivo?.estado || 'Pendiente';
              const fillCls = pct>=100?'green':pct>=70?'':'amber';
              const stCls   = estado==='Certificada'?'ok':estado==='En Proceso'?'warn':'info';
              return `<tr data-estado="${WMS.esc(estado)}">
                <td>
                  <div style="font-weight:700">${WMS.esc(p.archivo?.nombre_archivo||'Documento')}</div>
                  <div class="muted" style="font-size:.72rem">ID ${p.archivo?.id||'–'} · ${WMS.formatDate(p.archivo?.created_at)||'–'}</div>
                </td>
                <td><span class="pro-badge ${stCls}">${WMS.esc(estado)}</span></td>
                <td style="text-align:center;font-weight:700">${WMS.formatNum(p.total_lineas||0)}</td>
                <td style="text-align:center;font-weight:700">${WMS.formatNum(p.total_unidades||0)}</td>
                <td>
                  <div class="pro-progress-wrap">
                    <div class="pro-progress-bar-bg">
                      <div class="pro-progress-bar-fill ${fillCls}" style="width:${pct}%"></div>
                    </div>
                    <span class="pro-progress-label">${pct}%</span>
                  </div>
                </td>
                <td style="text-align:center">
                  <button class="btn btn-sm btn-secondary"
                          onclick="WMS_MODULES.despacho.verPlanilla(${p.archivo?.id||0})">
                    <i class="fa-solid fa-magnifying-glass-chart"></i> Detalle
                  </button>
                </td>
              </tr>`;
            }).join('') || '<tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No hay procesos activos en este momento</td></tr>'}
          </tbody>
        </table>
      </div>
      <div class="pro-table-footer">
        <span>${progreso.length} documentos</span>
        <span>IRA Global: <strong>${iraCert}%</strong></span>
      </div>
    </div>
  </div>

</div>`);
    } catch(e) {
      console.error(e);
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error cargando dashboard de certificación</p></div>');
    }
  },

  _filterCertTable(q) {
    const rows = Array.from(document.querySelectorAll('#cert-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !q || tr.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
    });
  },

  _filterCertEstado(val) {
    const rows = Array.from(document.querySelectorAll('#cert-tbody tr'));
    rows.forEach(tr => {
      tr.style.display = !val || tr.dataset.estado === val ? '' : 'none';
    });
  },

  // ── INTEGRACIÓN TMS ───────────────────────────────────────────

  async show_tms() {
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_tms()">
        <i class="fa-solid fa-rotate"></i> Actualizar
      </button>
      <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.despacho.guiaTms()">
        <i class="fa-solid fa-book"></i> Guía de Conexión
      </button>
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.gestionarApiKeys()">
        <i class="fa-solid fa-key"></i> API Keys
      </button>`);
    WMS.spinner();
    try {
      const [stockR, despR] = await Promise.all([
        API.get('/tms/stock?per_page=1'),
        API.get('/tms/despachos'),
      ]);
      const stk  = stockR.meta?.total ?? (stockR.data?.length ?? 0);
      const desp = Array.isArray(despR.data) ? despR.data : [];

      WMS.setContent(`
        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#dcfce7;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:18px;"><i class="fa-solid fa-satellite-dish"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;">Estado API</div>
              <div style="font-size:16px;font-weight:800;color:#166534;">Endpoints activos</div>
            </div>
          </div>
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#dbeafe;display:flex;align-items:center;justify-content:center;color:#1d4ed8;font-size:18px;"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.5px;">Ítems en stock</div>
              <div style="font-size:22px;font-weight:900;color:#1e3a5f;">${WMS.formatNum(stk)}</div>
            </div>
          </div>
          <div style="background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:16px;display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:4px;background:#fef08a;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:18px;"><i class="fa-solid fa-truck-fast"></i></div>
            <div>
              <div style="font-size:11px;font-weight:700;color:#d97706;text-transform:uppercase;letter-spacing:.5px;">Despachos hoy</div>
              <div style="font-size:22px;font-weight:900;color:#1e3a5f;">${WMS.formatNum(desp.length)}</div>
            </div>
          </div>
        </div>

        <!-- Despachos -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
          <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:800;color:#1e3a5f;font-size:13px;">
            <i class="fa-solid fa-truck-fast" style="color:#d97706;margin-right:6px;"></i>Despachos del día
          </div>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead><tr style="background:#f8fafc;">
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">N° Despacho</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Cliente</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Operador</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Estado</th>
                <th style="padding:8px 12px;text-align:left;color:#64748b;font-weight:700;">Tracking</th>
                <th style="padding:8px 12px;text-align:center;color:#64748b;font-weight:700;">Acción</th>
              </tr></thead>
              <tbody>
                ${desp.length ? desp.slice(0,30).map(d => {
                  const enTransito = d.tms_estado === 'EnTransito' || d.estado === 'En Tránsito';
                  const entregado  = d.tms_estado === 'Entregado'  || d.estado === 'Entregado';
                  const badge = entregado
                    ? 'background:#dcfce7;color:#166534'
                    : enTransito
                      ? 'background:#dbeafe;color:#1e40af'
                      : 'background:#fef9c3;color:#854d0e';
                  const label = entregado ? 'Entregado' : enTransito ? 'En Tránsito' : (d.estado||'Pendiente');
                  return `<tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:8px 12px;font-weight:700;color:#0F4C81;">${WMS.esc(d.numero_despacho||'-')}</td>
                    <td style="padding:8px 12px;">${WMS.esc(d.cliente_nombre||d.cliente||'-')}</td>
                    <td style="padding:8px 12px;">${WMS.esc(d.operador||'-')}</td>
                    <td style="padding:8px 12px;"><span style="${badge};padding:2px 8px;border-radius:3px;font-size:.72rem;font-weight:600;">${WMS.esc(label)}</span></td>
                    <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#64748b;">${WMS.esc(d.tms_tracking_code||'—')}</td>
                    <td style="padding:8px 12px;text-align:center;">
                      ${d.estado === 'Cerrado' && !enTransito && !entregado
                        ? `<button class="btn btn-sm btn-primary" style="font-size:.7rem;" onclick="WMS_MODULES.despacho.marcarEnTransito(${d.id})">
                             <i class="fa-solid fa-truck-moving"></i> En Tránsito
                           </button>`
                        : `<span style="color:#94a3b8;font-size:11px;">—</span>`}
                    </td>
                  </tr>`;
                }).join('') : '<tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">Sin despachos registrados hoy</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.setContent('<div class="m-empty"><i class="fa-solid fa-plug-circle-xmark"></i><p>Error conectando con el módulo TMS</p></div>');
    }
  },

  async marcarEnTransito(id) {
    WMS.showModal('Marcar En Tránsito', `
      <div class="form-group">
        <label class="form-label">Transportista</label>
        <input id="tms-trans" class="form-control" placeholder="Nombre del transportista o empresa">
      </div>
      <div class="form-group">
        <label class="form-label">Código de Tracking</label>
        <input id="tms-track" class="form-control" placeholder="Ej: TRACK-2026-001">
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.despacho._confirmarEnTransito(${id})">
         <i class="fa-solid fa-truck-moving"></i> Confirmar
       </button>`);
  },

  async _confirmarEnTransito(id) {
    const transportista = document.getElementById('tms-trans')?.value.trim();
    const tracking      = document.getElementById('tms-track')?.value.trim();
    try {
      const r = await API.post('/tms/despacho/' + id + '/transportar', { transportista, tracking_code: tracking });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.closeModal('generic-modal');
      WMS.toast('success', 'Despacho marcado como En Tránsito');
      this.show_tms();
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', 'Error al sincronizar con TMS');
    }
  },

  async gestionarApiKeys() {
    try {
      const r    = await API.get('/tms/keys');
      const keys = Array.isArray(r.data) ? r.data : [];
      WMS.showModal(
        '<i class="fa-solid fa-key" style="margin-right:6px;color:#1d4ed8;"></i>API Keys TMS',
        `<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
           <p style="margin:0;font-size:12px;color:#64748b;">Cada key autoriza al TMS externo a consultar los endpoints del WMS.</p>
           <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho.crearApiKey()">
             <i class="fa-solid fa-plus"></i> Nueva Key
           </button>
         </div>
         <div style="overflow-x:auto;">
           <table style="width:100%;border-collapse:collapse;font-size:12px;">
             <thead><tr style="background:#f8fafc;">
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Nombre</th>
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Key (hash parcial)</th>
               <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:700;">Último uso</th>
               <th style="padding:7px 10px;text-align:center;color:#64748b;font-weight:700;width:80px;"></th>
             </tr></thead>
             <tbody id="tms-keys-tbody">
               ${keys.length ? keys.map(k => `
                 <tr style="border-bottom:1px solid #f1f5f9;" id="tms-key-row-${k.id}">
                   <td style="padding:8px 10px;font-weight:600;">${WMS.esc(k.nombre||'-')}</td>
                   <td style="padding:8px 10px;font-family:monospace;font-size:11px;color:#64748b;">${(k.key_hash||'').substring(0,12)}…</td>
                   <td style="padding:8px 10px;color:#64748b;">${k.ultimo_uso ? WMS.formatDate(k.ultimo_uso) : '<span style="color:#94a3b8">Nunca</span>'}</td>
                   <td style="padding:8px 10px;text-align:center;">
                     <button class="btn btn-xs" style="background:#fee2e2;color:#991b1b;border:none;border-radius:3px;padding:3px 8px;cursor:pointer;"
                             onclick="WMS_MODULES.despacho.revocarKey(${k.id})">
                       <i class="fa-solid fa-ban"></i> Revocar
                     </button>
                   </td>
                 </tr>`).join('')
               : '<tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic;">Sin API Keys activas</td></tr>'}
             </tbody>
           </table>
         </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', 'Error cargando API Keys');
    }
  },

  async crearApiKey() {
    WMS.showModal(
      '<i class="fa-solid fa-plus" style="margin-right:6px;color:#16a34a;"></i>Nueva API Key TMS',
      `<p style="font-size:12px;color:#64748b;margin-bottom:14px;">
         La clave se mostrará <strong>una sola vez</strong>. Cópiala al servidor TMS antes de cerrar este cuadro.
       </p>
       <div class="form-group">
         <label class="form-label">Nombre identificador <span style="color:#dc2626;">*</span></label>
         <input id="tms-key-nombre" class="form-control" placeholder="Ej: TMS-Hostinger-Prod" autofocus>
       </div>
       <div id="tms-key-result" style="display:none;margin-top:14px;">
         <label class="form-label" style="color:#16a34a;font-weight:700;">
           <i class="fa-solid fa-circle-check"></i> Key generada — cópiala ahora
         </label>
         <div style="display:flex;gap:8px;align-items:center;">
           <input id="tms-key-value" class="form-control" readonly
                  style="font-family:monospace;font-size:12px;background:#f0fdf4;border-color:#86efac;">
           <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.despacho._copiarKey()"
                   style="white-space:nowrap;flex-shrink:0;">
             <i class="fa-solid fa-copy"></i> Copiar
           </button>
         </div>
         <p style="font-size:11px;color:#dc2626;margin-top:6px;">
           <i class="fa-solid fa-triangle-exclamation"></i> No se almacena en texto plano. Si la pierdes deberás crear una nueva.
         </p>
       </div>`,
      `<button id="tms-btn-crear" class="btn btn-primary" onclick="WMS_MODULES.despacho._submitCrearKey()">
         <i class="fa-solid fa-key"></i> Generar Key
       </button>
       <button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal');WMS_MODULES.despacho.gestionarApiKeys()">
         Cerrar
       </button>`);
  },

  async _submitCrearKey() {
    const nombre = document.getElementById('tms-key-nombre')?.value.trim();
    if (!nombre) { WMS.toast('warning', 'Ingresa un nombre para la key'); return; }
    const btn = document.getElementById('tms-btn-crear');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generando...'; }
    try {
      const r = await API.post('/tms/keys', { nombre, permisos: ['read', 'write'] });
      const plainKey = r.data?.api_key || r.api_key || '';
      document.getElementById('tms-key-value').value = plainKey;
      document.getElementById('tms-key-result').style.display = 'block';
      document.getElementById('tms-key-nombre').disabled = true;
      if (btn) { btn.style.display = 'none'; }
    } catch(e) {
      if (e.isSessionExpired) return;
      WMS.toast('error', e.message || 'Error generando API Key');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-key"></i> Generar Key'; }
    }
  },

  _copiarKey() {
    const val = document.getElementById('tms-key-value')?.value;
    if (!val) return;
    navigator.clipboard?.writeText(val).then(() => WMS.toast('success', 'Key copiada al portapapeles'))
      .catch(() => { document.getElementById('tms-key-value').select(); document.execCommand('copy'); WMS.toast('success', 'Key copiada'); });
  },

  async revocarKey(id) {
    WMS.confirm('Revocar API Key', '¿Revocar esta API Key? El TMS perderá acceso inmediatamente.', async () => {
      try {
        await API.delete('/tms/keys/' + id);
        WMS.toast('success', 'API Key revocada');
        WMS.closeModal('generic-modal');
        this.gestionarApiKeys();
      } catch(e) {
        if (e.isSessionExpired) return;
        WMS.toast('error', 'Error revocando API Key');
      }
    });
  },

  guiaTms() {
    const base = (window.location.origin + '/WMS_FENIX/public/api/tms').replace(/([^:])\/\//g, '$1/');
    const endpoints = [
      { method:'GET',  path:'/stock',                   desc:'Inventario disponible (paginado)',         params:'?page=1&per_page=100&codigo=ABC' },
      { method:'GET',  path:'/ordenes',                 desc:'Órdenes de picking activas',               params:'?estado=EnProceso' },
      { method:'GET',  path:'/despachos',               desc:'Despachos del período',                    params:'?fecha_inicio=2026-05-01&fecha_fin=2026-05-31' },
      { method:'POST', path:'/despacho/{id}/transportar',desc:'Marcar despacho en tránsito',             params:'Body: {"tracking_code":"T001","transportista":"TransCo"}' },
      { method:'POST', path:'/webhook',                  desc:'Receptor de eventos del TMS',             params:'Body: {"evento":"ENTREGA_CONFIRMADA","payload":{...}}' },
    ];
    const methodColor = { GET:'#16a34a', POST:'#1d4ed8', DELETE:'#dc2626' };
    WMS.showModal(
      '<i class="fa-solid fa-book" style="margin-right:6px;color:#7c3aed;"></i>Guía de Conexión — TMS',
      `<!-- URL base -->
       <div style="margin-bottom:18px;">
         <label class="form-label" style="color:#7c3aed;font-weight:700;">URL Base del WMS</label>
         <div style="display:flex;gap:8px;align-items:center;">
           <input id="tms-base-url" class="form-control" readonly value="${base}"
                  style="font-family:monospace;font-size:12px;background:#f5f3ff;border-color:#c4b5fd;">
           <button class="btn btn-sm btn-secondary" onclick="navigator.clipboard?.writeText(document.getElementById('tms-base-url').value).then(()=>WMS.toast('success','URL copiada'))" style="white-space:nowrap;flex-shrink:0;">
             <i class="fa-solid fa-copy"></i> Copiar
           </button>
         </div>
       </div>

       <!-- Auth -->
       <div style="background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:12px 14px;margin-bottom:18px;font-size:12px;">
         <div style="font-weight:700;color:#78350f;margin-bottom:6px;"><i class="fa-solid fa-shield-halved"></i> Autenticación</div>
         <p style="margin:0 0 6px;color:#713f12;">Incluye el header en cada request:</p>
         <code style="display:block;background:#fef08a;padding:6px 10px;border-radius:3px;font-size:11px;">X-API-Key: wms_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code>
         <p style="margin:6px 0 0;color:#713f12;font-size:11px;">Genera la key en el botón <strong>API Keys</strong> de este panel.</p>
       </div>

       <!-- Endpoints -->
       <div style="font-weight:700;color:#1e3a5f;font-size:13px;margin-bottom:10px;">Endpoints disponibles</div>
       <div style="display:flex;flex-direction:column;gap:8px;">
         ${endpoints.map(ep => `
           <div style="border:1px solid #e2e8f0;border-radius:4px;padding:10px 12px;background:#f8fafc;">
             <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
               <span style="background:${methodColor[ep.method]||'#64748b'};color:#fff;padding:1px 7px;border-radius:3px;font-size:10px;font-weight:800;font-family:monospace;">${ep.method}</span>
               <code style="font-size:12px;color:#1e293b;font-weight:600;">${ep.path}</code>
             </div>
             <div style="font-size:11px;color:#64748b;margin-bottom:3px;">${ep.desc}</div>
             <code style="font-size:10px;color:#94a3b8;word-break:break-all;">${ep.params}</code>
           </div>`).join('')}
       </div>

       <!-- Ejemplo cURL -->
       <div style="margin-top:18px;">
         <div style="font-weight:700;color:#1e3a5f;font-size:12px;margin-bottom:6px;">Ejemplo PHP/cURL (servidor TMS)</div>
         <pre style="background:#1e293b;color:#e2e8f0;padding:12px;border-radius:4px;font-size:11px;overflow-x:auto;line-height:1.6;">\$ch = curl_init('${base}/stock');\ncurl_setopt_array(\$ch, [\n  CURLOPT_RETURNTRANSFER =&gt; true,\n  CURLOPT_HTTPHEADER =&gt; ['X-API-Key: wms_TU_KEY_AQUI']\n]);\n\$json = json_decode(curl_exec(\$ch));\n// \$json-&gt;ok === true\n// \$json-&gt;data = [...items de inventario]</pre>
       </div>

       <!-- Conexión Hostinger → Local -->
       <div style="margin-top:18px;background:#fff7ed;border:1px solid #fed7aa;border-radius:4px;padding:14px;">
         <div style="font-weight:700;color:#c2410c;margin-bottom:8px;font-size:12px;">
           <i class="fa-solid fa-cloud-arrow-up"></i> Conectar servidor Hostinger → WMS local (XAMPP)
         </div>
         <p style="font-size:12px;color:#7c2d12;margin:0 0 10px;">
           Hostinger está en internet público; tu XAMPP está en red privada. El servidor TMS no puede acceder a <code>localhost</code> directamente.
           Usa <strong>ngrok</strong> para crear un túnel seguro.
         </p>
         <div style="font-weight:600;color:#9a3412;font-size:12px;margin-bottom:6px;">Pasos con ngrok (gratis):</div>
         <ol style="font-size:12px;color:#7c2d12;margin:0 0 10px;padding-left:18px;line-height:1.9;">
           <li>Descarga ngrok: <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">ngrok.com/download</code></li>
           <li>Inicia el túnel en tu máquina: <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">ngrok http 80</code></li>
           <li>Ngrok genera una URL pública como <code style="background:#fef3c7;padding:1px 5px;border-radius:2px;">https://abc123.ngrok-free.app</code></li>
           <li>Desde Hostinger usa esa URL como base:
             <code style="display:block;background:#fef9c3;padding:4px 8px;border-radius:3px;margin-top:4px;word-break:break-all;">https://abc123.ngrok-free.app/WMS_FENIX/public/api/tms/stock</code>
           </li>
         </ol>
         <div style="font-size:11px;color:#9a3412;background:#fef3c7;padding:8px;border-radius:3px;">
           <i class="fa-solid fa-circle-info"></i>
           <strong>Para producción</strong>: el WMS debe estar en un servidor público (Hostinger, VPS, etc.) con dominio propio.
           ngrok es solo para desarrollo y pruebas — la URL cambia en cada reinicio (plan gratuito).
         </div>
       </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
  },

  // ── PACKING SCREEN ─────────────────────────────────────────────────────────
  _packingState: { sesionId: null, sesionData: null, unitsWithItems: {} },

  async show_packing(sesionId) {
    WMS.spinner();
    this._packingState = { sesionId: null, sesionData: null, unitsWithItems: {} };
    try {
      const r = await API.get('/packing/sesion/' + sesionId);
      if (r.error) { WMS.toast('error', r.message); return; }
      this._packingState.sesionId  = sesionId;
      this._packingState.sesionData = r.data;
      // Seed closed units' items from API response
      (r.data.unidades || []).forEach(u => {
        if (u.estado === 'Cerrada') this._packingState.unitsWithItems[u.id] = u.items || [];
      });
      this._renderPackingScreen(r.data);
    } catch(e) { WMS.toast('error', 'Error al cargar sesión de packing'); }
  },

  _renderPackingScreen(data) {
    const { sesion, totales, productos, unidades, unidad_abierta } = data;
    const tipo      = sesion.tipo_empaque;
    const tipoUp    = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const unitAb    = unidades.find(u => u.id === unidad_abierta);
    const consec    = unitAb ? String(unitAb.consecutivo).padStart(3,'0') : '---';
    const pendiente = totales.pendiente;
    const btnFin    = pendiente > 0 ? 'disabled' : '';

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>`);

    WMS.setContent(`
      <div id="packing-wrap">
        <!-- TOP BAR -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
          <div style="flex:1;display:flex;gap:20px;font-size:13px;">
            <span>Pendiente: <strong id="pk-stat-pend" style="color:${pendiente>0?'#dc2626':'#16a34a'};">${WMS.formatNum(pendiente)}</strong></span>
            <span>Empacado: <strong id="pk-stat-emp">${WMS.formatNum(totales.total_empacado)}</strong></span>
            <span>Unidades: <strong id="pk-stat-units">${totales.num_unidades}</strong></span>
          </div>
          <button id="pk-btn-finalizar" class="btn btn-success btn-sm" ${btnFin}
            onclick="WMS_MODULES.despacho.finalizarPacking(${sesion.id})">
            <i class="fa-solid fa-flag-checkered"></i> Finalizar Certificación
          </button>
        </div>

        <!-- TWO PANELS -->
        <div style="display:grid;grid-template-columns:1fr 400px;gap:14px;align-items:start;">
          <!-- LEFT: productos pendientes -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
              <span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Productos Pendientes</span>
              <span style="font-size:12px;color:#64748b;">${tipoUp} actual: <strong>#${consec}</strong></span>
            </div>
            <div id="pk-left-content" style="padding:0 8px 8px;">
              ${this._buildProductosList(productos, sesion.id)}
            </div>
          </div>

          <!-- RIGHT: unidad actual -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;" id="pk-right-header">
              <span class="card-title"><i class="fa-solid fa-box"></i> ${tipoUp} #${consec}</span>
              <span class="status-chip status-creada">Abierta</span>
            </div>
            <div id="pk-right-content" style="padding:0 8px;">
              ${this._buildItemsTable(unitAb?.items || [])}
            </div>
            <div style="padding:10px 12px;border-top:1px solid #e2e8f0;">
              <button class="btn btn-warning btn-sm" style="width:100%;"
                onclick="WMS_MODULES.despacho.cerrarUnidadPacking(${unidad_abierta})">
                <i class="fa-solid fa-box-archive"></i> Cerrar unidad e imprimir sticker
              </button>
            </div>
          </div>
        </div>

        <!-- UNIDADES CERRADAS -->
        <div id="pk-closed-list" style="margin-top:14px;">
          ${this._buildClosedList(unidades, tipoUp, sesion.id)}
        </div>
      </div>`);
  },

  _buildProductosList(productos, sesionId) {
    if (!productos.length) return '<p class="table-empty">Sin productos</p>';
    return productos.map(p => `
      <div class="pk-prod-row" style="padding:8px;border-bottom:1px solid #f1f5f9;" id="pk-prod-${p.producto_id}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <div>
            <div style="font-weight:600;font-size:13px;">${WMS.esc(p.nombre)}</div>
            <div style="font-size:11px;color:#64748b;">${WMS.esc(p.codigo||'-')}</div>
          </div>
          <div style="text-align:right;font-size:12px;">
            <div>Pick: <strong>${WMS.formatNum(p.total_pickeado)}</strong></div>
            <div>Emp: ${WMS.formatNum(p.total_empacado)}</div>
            <div style="color:${p.pendiente>0?'#dc2626':'#16a34a'};font-weight:700;">Pend: ${WMS.formatNum(p.pendiente)}</div>
          </div>
        </div>
        ${p.pendiente > 0 ? `
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="number" id="pk-qty-${p.producto_id}" min="0.001" max="${p.pendiente}" step="0.001"
            value="${p.pendiente}" style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;">
          <button class="btn btn-primary btn-sm" style="font-size:11px;"
            data-sesion="${sesionId}" data-producto="${p.producto_id}"
            onclick="WMS_MODULES.despacho.agregarItemPacking(+this.dataset.sesion, +this.dataset.producto)">
            <i class="fa-solid fa-plus"></i> Agregar
          </button>
        </div>` : '<span style="font-size:11px;color:#16a34a;"><i class="fa-solid fa-check"></i> Completado</span>'}
      </div>`).join('');
  },

  _buildItemsTable(items) {
    if (!items.length) return '<p style="padding:12px;color:#94a3b8;font-size:12px;text-align:center;">Unidad vacía</p>';
    return `<table class="erp-table" style="font-size:11px;">
      <thead><tr><th>Ref.</th><th>Producto</th><th class="text-center">Cant.</th><th>Lote</th><th></th></tr></thead>
      <tbody>${items.map(i => `<tr>
        <td><code>${WMS.esc(i.codigo||'-')}</code></td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td class="text-center fw-700">${WMS.formatNum(i.cantidad)}</td>
        <td style="font-size:10px;">${WMS.esc(i.lote||'-')}</td>
        <td><button class="btn btn-danger" style="padding:2px 6px;font-size:10px;"
          onclick="WMS_MODULES.despacho.eliminarItemPacking(${i.id})">
          <i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('')}</tbody>
    </table>`;
  },

  _buildClosedList(unidades, tipoUp, sesionId) {
    const closed = unidades.filter(u => u.estado === 'Cerrada');
    if (!closed.length) return '';
    return `<div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-layer-group"></i> Unidades Cerradas (${closed.length})</span>
        <button class="btn btn-sm btn-outline-primary" data-tipo="${tipoUp}" onclick="WMS_MODULES.despacho._imprimirTodasPacking(this.dataset.tipo)">
          <i class="fa-solid fa-print"></i> Imprimir Todas</button>
      </div>
      <div class="table-container">
        <table class="erp-table" style="font-size:12px;">
          <thead><tr><th>Unidad</th><th class="text-center">Ítems</th><th class="text-center">Total Uds.</th><th>Hora cierre</th><th>Acciones</th></tr></thead>
          <tbody>${closed.map(u => `<tr>
            <td><strong>${tipoUp} #${String(u.consecutivo).padStart(3,'0')}</strong></td>
            <td class="text-center">${(u.items||[]).length}</td>
            <td class="text-center fw-700">${WMS.formatNum(u.total_unidades)}</td>
            <td style="font-size:11px;">${u.closed_at ? u.closed_at.substring(11,16) : '-'}</td>
            <td><button class="btn btn-sm btn-outline-secondary"
              onclick="WMS_MODULES.despacho._imprimirStickerUnidad(${u.id}, 'letter')">
              <i class="fa-solid fa-print"></i> Sticker</button></td>
          </tr>`).join('')}</tbody>
        </table>
      </div>
    </div>`;
  },

  async agregarItemPacking(sesionId, productoId) {
    const qty = parseFloat(document.getElementById('pk-qty-' + productoId)?.value || 0);
    if (!qty || qty <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/item', {
        producto_id: productoId,
        cantidad:    qty,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Ítem agregado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al agregar'); }
  },

  async eliminarItemPacking(itemId) {
    const { sesionId } = this._packingState;
    try {
      const r = await API.delete('/packing/item/' + itemId);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Ítem eliminado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al eliminar'); }
  },

  async cerrarUnidadPacking(unidadId) {
    const { sesionId, sesionData } = this._packingState;
    // Save current items before closing (for sticker generation)
    const currentUnit = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (currentUnit) {
      this._packingState.unitsWithItems[unidadId] = currentUnit.items || [];
    }
    try {
      const r = await API.post('/packing/unidad/' + unidadId + '/cerrar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      // Auto-print sticker for closed unit
      this._imprimirStickerUnidad(unidadId, 'letter');
      WMS.toast('success', r.message || 'Unidad cerrada');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al cerrar unidad'); }
  },

  _imprimirStickerUnidad(unidadId, size) {
    const { sesionData, unitsWithItems } = this._packingState;
    const unidad = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (!unidad) { WMS.toast('error', 'Unidad no encontrada'); return; }
    const items = unitsWithItems[unidadId] || unidad.items || [];
    const html  = this._buildStickerHtml(unidad, sesionData.sesion, items, size);
    const win   = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

  _imprimirTodasPacking(tipoUp) {
    const { sesionData, unitsWithItems } = this._packingState;
    const closed = (sesionData.unidades || []).filter(u => u.estado === 'Cerrada');
    const parts  = closed.map(u => {
      const items = unitsWithItems[u.id] || u.items || [];
      return this._buildStickerBlock(u, sesionData.sesion, items)
           + '<div style="page-break-after:always;"></div>';
    }).join('');
    const html = this._wrapPrintPage(parts, 'letter');
    const win  = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

  _buildStickerHtml(unidad, sesion, items, size) {
    return this._wrapPrintPage(this._buildStickerBlock(unidad, sesion, items), size, true);
  },

  _buildStickerBlock(unidad, sesion, items) {
    const tipo    = sesion.tipo_empaque;
    const tipoUp  = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const consec  = String(unidad.consecutivo).padStart(3, '0');
    const cert    = WMS.esc(sesion.certificador_nombre || '-');
    const fecha   = new Date().toLocaleDateString('es-CO');
    const hora    = new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    const total   = (unidad.total_unidades || items.reduce((s, i) => s + (parseFloat(i.cantidad)||0), 0)).toFixed(2);
    const rows    = items.map(i => `
      <tr>
        <td>${WMS.esc(i.codigo||'-')}</td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td style="text-align:right;font-weight:700;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
        <td>${WMS.esc(i.lote||'-')}</td>
        <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'2-digit'}) : '-'}</td>
      </tr>`).join('');

    return `<div class="sticker">
      <div class="st-header">
        <span class="st-tipo">${tipoUp} #${consec}</span>
        <span>WMS Fénix</span>
      </div>
      <div class="st-suc">Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></div>
      <table>
        <thead><tr><th>Ref.</th><th>Descripción</th><th>Cant.</th><th>Lote</th><th>Vence</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="st-footer">
        <div class="st-total">Total unidades: ${total}</div>
        <div>Certificador: ${cert}</div>
        <div>Fecha: ${fecha} &nbsp; Hora: ${hora}</div>
      </div>
    </div>`;
  },

  _wrapPrintPage(content, size, autoprint) {
    const sizes = { media_carta: '5.5in 8.5in', a5: 'A5', letter: 'letter' };
    const margins = { letter: '12mm' };
    const pageSize = sizes[size] || 'letter';
    const margin   = margins[size] || '8mm';
    const script   = autoprint !== false ? '<script>window.onload=()=>window.print();<\/script>' : '';
    return `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@page { size: ${pageSize}; margin: ${margin}; }
@media print { .no-print { display:none; } }
body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
.sticker { border: 2px solid #1e293b; padding: 8px; margin-bottom: 8px; }
.st-header { display:flex; justify-content:space-between; font-weight:bold; font-size:13px; border-bottom:1px solid #334155; padding-bottom:5px; margin-bottom:5px; }
.st-tipo { font-size:15px; color:#1e40af; }
.st-suc { font-size:12px; color:#475569; margin-bottom:4px; }
table { width:100%; border-collapse:collapse; margin:5px 0; }
th { background:#f1f5f9; font-size:10px; padding:3px 4px; text-align:left; }
td { padding:2px 4px; border-bottom:1px dotted #e2e8f0; font-size:10px; }
.st-footer { border-top:1px solid #334155; padding-top:4px; margin-top:4px; font-size:10px; color:#475569; }
.st-total { font-size:13px; font-weight:bold; color:#1e293b; margin-bottom:2px; }
.no-print { margin:8px 0; text-align:center; }
</style>${script}
</head><body>${content}
<div class="no-print"><button onclick="window.print()">Imprimir</button></div>
</body></html>`;
  },

  async finalizarPacking(sesionId) {
    if (!confirm('¿Finalizar la certificación de packing? Esta acción no se puede deshacer.')) return;
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/finalizar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Certificación finalizada — ' + r.data.total_unidades + ' unidades de empaque');
      // Refresh packing screen to show document panel
      const sr = await API.get('/packing/sesion/' + sesionId);
      if (!sr.error) {
        this._packingState.sesionData = sr.data;
        this._mostrarPanelDocumento(sr.data);
      } else {
        this.show_certificacion();
      }
    } catch(e) { WMS.toast('error', 'Error al finalizar'); }
  },

  _mostrarPanelDocumento(data) {
    const { sesion, totales } = data;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    WMS.setContent(`
      <div class="card" style="max-width:700px;margin:0 auto;">
        <div class="card-header" style="background:#16a34a;color:#fff;">
          <span class="card-title"><i class="fa-solid fa-circle-check"></i> Packing Completado</span>
        </div>
        <div style="padding:24px;text-align:center;">
          <div style="font-size:48px;color:#16a34a;margin-bottom:12px;">✓</div>
          <h3 style="margin:0 0 6px;">Certificación Finalizada</h3>
          <p style="color:#475569;margin:0 0 20px;">
            <strong>${WMS.esc(sesion.sucursal_entrega)}</strong> — ${totales.num_unidades} ${tipoUp}(s)
          </p>
          <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <button class="btn btn-primary" onclick="WMS_MODULES.despacho._abrirDocumento(${sesion.id})">
              <i class="fa-solid fa-file-alt"></i> Ver Documento de Packing
            </button>
            <button class="btn btn-outline-primary" data-tipo="${tipoUp}" onclick="WMS_MODULES.despacho._imprimirTodasPacking(this.dataset.tipo)">
              <i class="fa-solid fa-print"></i> Imprimir Todos los Stickers
            </button>
            <button class="btn btn-secondary" onclick="WMS_MODULES.despacho.show_certificacion()">
              <i class="fa-solid fa-arrow-left"></i> Volver a Certificación
            </button>
          </div>
        </div>
      </div>`);
  },

  _abrirDocumento(sesionId) {
    const { sesionData, unitsWithItems } = this._packingState;
    const { sesion, unidades } = sesionData;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    const cert   = WMS.esc(sesion.certificador_nombre || '-');
    const emp    = WMS.esc(sesion.empresa_nombre || 'WMS Fénix');
    const fecha  = new Date().toLocaleString('es-CO');

    const closed = (unidades || []).filter(u => u.estado === 'Cerrada');

    // Collect unique separadores
    const seps = new Set();
    closed.forEach(u => {
      (unitsWithItems[u.id] || u.items || []).forEach(i => {
        if (i.separador_nombre?.trim()) seps.add(i.separador_nombre.trim());
      });
    });
    const sepStr = [...seps].join(', ') || 'N/A';

    // Build table rows
    let prevConsec = null;
    let rowClass   = 'even';
    const rows = closed.flatMap(u => {
      if (u.consecutivo !== prevConsec) {
        rowClass   = rowClass === 'even' ? 'odd' : 'even';
        prevConsec = u.consecutivo;
      }
      const consec = String(u.consecutivo).padStart(3,'0');
      return (unitsWithItems[u.id] || u.items || []).map(i => `
        <tr class="${rowClass}">
          <td>#${consec}</td><td>${tipoUp}</td>
          <td>${WMS.esc(i.codigo||'-')}</td>
          <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
          <td style="text-align:right;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
          <td>${WMS.esc(i.lote||'-')}</td>
          <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'numeric'}) : '-'}</td>
        </tr>`);
    }).join('');

    const totalUnidades = closed.length;
    const totalProd     = closed.reduce((s, u) => s + (u.total_unidades || 0), 0).toFixed(2);
    const allCodigos    = new Set(closed.flatMap(u => (unitsWithItems[u.id] || u.items || []).map(i => i.codigo)));
    const totalRefs     = allCodigos.size;

    const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@page { size: letter; margin: 12mm; }
@media print { .no-print { display:none; } }
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; }
.doc-header { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 12px; display:flex; justify-content:space-between; align-items:flex-start; }
.doc-title { font-size:16px; font-weight:bold; color:#1e40af; margin:0 0 4px; }
.doc-meta { font-size:10px; color:#475569; margin-top:3px; }
.doc-meta span { display:inline-block; margin-right:12px; }
table { width:100%; border-collapse:collapse; margin:10px 0; }
th { background:#1e40af; color:#fff; font-size:10px; padding:4px 6px; text-align:left; }
td { padding:3px 6px; font-size:10px; border-bottom:1px solid #e2e8f0; }
tr.even td { background:#f8faff; } tr.odd td { background:#fff; }
.doc-footer { border-top:2px solid #1e40af; margin-top:12px; padding-top:8px; display:flex; gap:16px; flex-wrap:wrap; }
.foot-box .label { font-size:9px; color:#64748b; }
.foot-box .val   { font-weight:bold; font-size:13px; }
.no-print { margin:12px 0; text-align:center; }
</style>
<script>
function toggleLandscape() {
  const rule = Array.from(document.styleSheets[0].cssRules).find(r => r.cssText?.includes('@page'));
  if (rule) rule.style.cssText = rule.style.cssText.includes('landscape')
    ? rule.style.cssText.replace('landscape','portrait')
    : rule.style.cssText.replace('portrait','landscape');
}
<\/script>
</head><body>
<div class="doc-header">
  <div>
    <div class="doc-title">DOCUMENTO DE PACKING</div>
    <div class="doc-meta"><span><strong>${emp}</strong></span><span>Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></span><span>Tipo: <strong>${tipoUp}</strong></span></div>
    <div class="doc-meta"><span>Fecha/Hora: ${fecha}</span><span>Certificador: <strong>${cert}</strong></span><span>Separadores: ${WMS.esc(sepStr)}</span></div>
  </div>
  <div class="no-print">
    <button onclick="toggleLandscape()" style="margin-right:6px;">Girar</button>
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>
<table>
  <thead><tr><th>Unidad</th><th>Tipo</th><th>Referencia</th><th>Descripción</th><th>Cantidad</th><th>Lote</th><th>Vence</th></tr></thead>
  <tbody>${rows}</tbody>
</table>
<div class="doc-footer">
  <div class="foot-box"><div class="label">Unidades de empaque</div><div class="val">${totalUnidades}</div></div>
  <div class="foot-box"><div class="label">Total uds. producto</div><div class="val">${totalProd}</div></div>
  <div class="foot-box"><div class="label">Referencias distintas</div><div class="val">${totalRefs}</div></div>
  <div class="foot-box"><div class="label">Separó</div><div class="val" style="font-size:11px;">${WMS.esc(sepStr)}</div></div>
  <div class="foot-box"><div class="label">Certificó</div><div class="val" style="font-size:11px;">${cert}</div></div>
</div>
</body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    if (win) { win.document.write(html); win.document.close(); }
    else { WMS.toast('error', 'El navegador bloqueó la ventana emergente. Permita popups para este sitio.'); }
  },

};
