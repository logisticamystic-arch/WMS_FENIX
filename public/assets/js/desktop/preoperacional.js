/* ============================================================
   WMS Desktop — Módulo PREOPERACIONAL DE VEHÍCULOS
   Sub-vistas: nueva | historial
   ============================================================ */
WMS_MODULES.preoperacional = {
  _sub: null,
  _items: [],           // catálogo fijo cargado del backend
  _calificaciones: {},   // clave -> 'C'|'NC'
  _fotoTemperatura: null,
  _histFilters: { ini: '', fin: '', vehiculo: '', conductor: '', soloNc: false },

  _dashFilters: { ini: '', fin: '' },
  _matrizFilters: { ini: '', fin: '' },

  load(sub) {
    this._sub = sub || 'nueva';
    WMS.renderSidebar('preoperacional');
    const labels = { historial: 'Historial', dashboard: 'Dashboard Preoperacional', matriz: 'Matriz por Vehículo' };
    WMS.setBreadcrumb('preoperacional', labels[this._sub] || 'Registrar Preoperacional');
    WMS.setToolbar('');
    if (this._sub === 'historial') this.show_historial();
    else if (this._sub === 'dashboard') this.show_dashboard();
    else if (this._sub === 'matriz') this.show_matriz();
    else this.show_nueva();
  },

  // ── Registrar nuevo ──────────────────────────────────────────────────────
  async show_nueva() {
    this._calificaciones = {};
    this._fotoTemperatura = null;
    this._fotosInspeccion = [];
    WMS.spinner();
    try {
      const r = await API.get('/preoperacional/items');
      this._items = r.data || [];
    } catch (e) {
      this._items = [];
      WMS.toast('error', 'No se pudo cargar la lista de chequeo');
    }
    WMS.spinnerHide();

    const hoy = new Date().toISOString().slice(0, 10);

    WMS.setContent(`
      <div class="op-view" style="max-width:820px;margin:0 auto;">

        <div style="background:linear-gradient(135deg,#0F4C81,#1a6bb0);color:#fff;border-radius:12px;padding:16px 20px;margin-bottom:18px;">
          <div style="font-weight:800;font-size:.95rem;margin-bottom:4px;"><i class="fa-solid fa-truck-field"></i> Preoperacional del vehículo</div>
          <div style="font-size:.8rem;opacity:.92;">Este chequeo está enfocado en la <b>limpieza y el orden del vehículo</b> antes de salir a ruta. Califique cada punto como Cumple o No Cumple con base en lo que observa físicamente — no complete de memoria.</div>
        </div>

        <div class="card" style="margin-bottom:14px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-list"></i> Datos del vehículo</span></div>
          <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px;">
            <div class="form-group">
              <label class="form-label" style="font-weight:600;">Fecha <span style="color:#dc2626;">*</span></label>
              <input type="date" id="pv-fecha" class="form-control" value="${hoy}">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-weight:600;">Vehículo (placa) <span style="color:#dc2626;">*</span></label>
              <input id="pv-vehiculo" class="form-control" placeholder="Ej: ABC-123" style="text-transform:uppercase;">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-weight:600;">Conductor <span style="color:#dc2626;">*</span></label>
              <input id="pv-conductor" class="form-control" placeholder="Nombre del conductor">
            </div>
            <div class="form-group">
              <label class="form-label" style="font-weight:600;">Ruta</label>
              <input id="pv-ruta" class="form-control" placeholder="Ej: Bogotá - Medellín">
            </div>
          </div>
        </div>

        <div class="card" style="margin-bottom:14px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-list-check"></i> Limpieza Vehículo</span></div>
          <div class="card-body" style="padding:0;">
            <table class="data-table" style="width:100%;">
              <thead><tr>
                <th style="padding:8px 14px;">Ítem</th>
                <th style="text-align:center;width:200px;">Calificación</th>
              </tr></thead>
              <tbody id="pv-items-body">
                ${this._items.map(it => this._renderItemRow(it)).join('')}
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" style="margin-bottom:18px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-note-sticky"></i> Observaciones</span></div>
          <div class="card-body" style="padding:14px;">
            <textarea id="pv-observaciones" class="form-control" rows="3" placeholder="Anote cualquier novedad encontrada durante la inspección..."></textarea>
          </div>
        </div>

        <div class="card" style="margin-bottom:18px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-camera"></i> Fotos de la inspección</span></div>
          <div class="card-body" style="padding:14px;">
            <p style="font-size:.78rem;color:#64748b;margin:0 0 10px;">Puede adjuntar varias fotos de evidencia de la inspección (además de la foto puntual de Temperatura).</p>
            <input type="file" id="pv-fotos-input" accept="image/*" multiple style="display:none;" onchange="WMS_MODULES.preoperacional._onFotosInspeccion(this)">
            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('pv-fotos-input').click()">
              <i class="fa-solid fa-plus"></i> Agregar fotos
            </button>
            <div id="pv-fotos-preview" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;"></div>
          </div>
        </div>

        <div class="card" style="margin-bottom:18px;">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-signature"></i> Firma del Conductor</span></div>
          <div class="card-body" style="padding:14px;">
            <p style="font-size:.78rem;color:#64748b;margin:0 0 10px;">Firme dentro del recuadro con el mouse o la pantalla táctil. <span style="color:#dc2626;">*</span></p>
            <canvas id="pv-firma-canvas" style="width:100%;max-width:100%;height:180px;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;touch-action:none;display:block;"></canvas>
            <button type="button" class="btn btn-sm btn-outline-secondary" style="margin-top:10px;" onclick="WMS_MODULES.preoperacional._limpiarFirma()">
              <i class="fa-solid fa-eraser"></i> Limpiar firma
            </button>
          </div>
        </div>

        <button class="btn btn-primary btn-lg" style="width:100%;font-weight:700;" onclick="WMS_MODULES.preoperacional._guardar()">
          <i class="fa-solid fa-check"></i> Registrar Preoperacional
        </button>
      </div>
    `);
    this._initFirmaCanvas();
  },

  _initFirmaCanvas() {
    const canvas = document.getElementById('pv-firma-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.max(1, rect.width) * ratio;
    canvas.height = Math.max(1, rect.height) * ratio;
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1e293b';
    this._firmaTieneTrazo = false;
    let drawing = false;
    const pos = (e) => {
      const r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    };
    canvas.onpointerdown = (e) => { drawing = true; this._firmaTieneTrazo = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); };
    canvas.onpointermove = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); };
    canvas.onpointerup = () => { drawing = false; };
    canvas.onpointerleave = () => { drawing = false; };
  },

  _limpiarFirma() {
    const canvas = document.getElementById('pv-firma-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    this._firmaTieneTrazo = false;
  },

  _canvasABlob(canvas) {
    return new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
  },

  _onFotosInspeccion(input) {
    if (!this._fotosInspeccion) this._fotosInspeccion = [];
    const nuevas = Array.from(input.files || []);
    this._fotosInspeccion.push(...nuevas);
    input.value = '';
    this._renderFotosPreview();
  },

  _quitarFotoInspeccion(idx) {
    this._fotosInspeccion.splice(idx, 1);
    this._renderFotosPreview();
  },

  _renderFotosPreview() {
    const cont = document.getElementById('pv-fotos-preview');
    if (!cont) return;
    cont.innerHTML = (this._fotosInspeccion || []).map((file, idx) => `
      <div style="position:relative;width:84px;height:84px;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
        <img src="${URL.createObjectURL(file)}" style="width:100%;height:100%;object-fit:cover;">
        <button type="button" onclick="WMS_MODULES.preoperacional._quitarFotoInspeccion(${idx})"
          style="position:absolute;top:2px;right:2px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;line-height:1;">✕</button>
      </div>`).join('') || '<span style="font-size:.75rem;color:#94a3b8;">Sin fotos adjuntas todavía</span>';
  },

  _renderItemRow(it) {
    const esTemp = it.clave === 'temperatura';
    const esDesinfeccion = it.clave === 'desinfeccion_vehiculo';
    return `
      <tr id="pv-row-${it.clave}" style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:10px 14px;font-weight:600;">
          ${WMS.esc(it.nombre)}
          ${esTemp ? `
          <div style="margin-top:6px;display:flex;align-items:center;gap:8px;">
            <input type="file" id="pv-foto-temperatura" accept="image/*" style="display:none;" onchange="WMS_MODULES.preoperacional._onFotoTemperatura(this)">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('pv-foto-temperatura').click()">
              <i class="fa-solid fa-camera"></i> Foto del termómetro
            </button>
            <span id="pv-foto-temperatura-nombre" style="font-size:.72rem;color:#64748b;"></span>
          </div>` : ''}
          ${esDesinfeccion ? `
          <div id="pv-producto-${it.clave}" style="margin-top:6px;display:none;">
            <select id="pv-producto-select-${it.clave}" class="form-control form-control-sm" style="max-width:260px;">
              <option value="">Seleccione el producto usado...</option>
              <option value="Acido Peracetico">Ácido Peracético</option>
              <option value="Amonio Cuaternario">Amonio Cuaternario</option>
            </select>
          </div>` : ''}
        </td>
        <td style="text-align:center;">
          <div style="display:inline-flex;gap:6px;">
            <button type="button" id="pv-btn-${it.clave}-C" class="btn btn-sm" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;font-weight:700;min-width:64px;" onclick="WMS_MODULES.preoperacional._calificar('${it.clave}','C')">Cumple</button>
            <button type="button" id="pv-btn-${it.clave}-NC" class="btn btn-sm" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;font-weight:700;min-width:64px;" onclick="WMS_MODULES.preoperacional._calificar('${it.clave}','NC')">No Cumple</button>
          </div>
        </td>
      </tr>`;
  },

  _calificar(clave, valor) {
    this._calificaciones[clave] = valor;
    const btnC  = document.getElementById(`pv-btn-${clave}-C`);
    const btnNc = document.getElementById(`pv-btn-${clave}-NC`);
    if (btnC)  { btnC.style.background  = (valor === 'C')  ? '#059669' : '#f1f5f9'; btnC.style.color  = (valor === 'C')  ? '#fff' : '#64748b'; }
    if (btnNc) { btnNc.style.background = (valor === 'NC') ? '#dc2626' : '#f1f5f9'; btnNc.style.color = (valor === 'NC') ? '#fff' : '#64748b'; }
    if (clave === 'desinfeccion_vehiculo') {
      const cont = document.getElementById(`pv-producto-${clave}`);
      if (cont) cont.style.display = (valor === 'C') ? 'block' : 'none';
      if (valor !== 'C') { const sel = document.getElementById(`pv-producto-select-${clave}`); if (sel) sel.value = ''; }
    }
  },

  _onFotoTemperatura(input) {
    this._fotoTemperatura = (input.files && input.files[0]) || null;
    const lbl = document.getElementById('pv-foto-temperatura-nombre');
    if (lbl) lbl.textContent = this._fotoTemperatura ? this._fotoTemperatura.name : '';
  },

  async _guardar() {
    const fecha     = document.getElementById('pv-fecha')?.value || '';
    const vehiculo  = (document.getElementById('pv-vehiculo')?.value || '').trim();
    const conductor = (document.getElementById('pv-conductor')?.value || '').trim();
    const ruta      = (document.getElementById('pv-ruta')?.value || '').trim();
    const obs       = (document.getElementById('pv-observaciones')?.value || '').trim();

    if (!fecha || !vehiculo || !conductor) {
      return WMS.toast('error', 'Fecha, vehículo y conductor son obligatorios');
    }
    const faltantes = this._items.filter(it => !this._calificaciones[it.clave]).map(it => it.nombre);
    if (faltantes.length) {
      return WMS.toast('error', 'Falta calificar: ' + faltantes.join(', '));
    }

    const productoDesinfeccion = document.getElementById('pv-producto-select-desinfeccion_vehiculo')?.value || '';
    if (this._calificaciones['desinfeccion_vehiculo'] === 'C' && !productoDesinfeccion) {
      return WMS.toast('error', 'Debe seleccionar el producto usado en la desinfección del vehículo');
    }

    const canvasFirma = document.getElementById('pv-firma-canvas');
    if (!this._firmaTieneTrazo) {
      return WMS.toast('error', 'La firma del conductor es obligatoria');
    }

    const items = this._items.map(it => ({
      clave: it.clave,
      calificacion: this._calificaciones[it.clave],
      producto_desinfeccion: it.clave === 'desinfeccion_vehiculo' ? productoDesinfeccion : '',
    }));

    const formData = new FormData();
    formData.append('fecha', fecha);
    formData.append('vehiculo', vehiculo);
    formData.append('conductor', conductor);
    formData.append('ruta', ruta);
    formData.append('observaciones', obs);
    formData.append('items', JSON.stringify(items));
    if (this._fotoTemperatura) formData.append('foto_temperatura', this._fotoTemperatura);
    (this._fotosInspeccion || []).forEach(file => formData.append('fotos[]', file));
    const blobFirma = await this._canvasABlob(canvasFirma);
    formData.append('firma', blobFirma, 'firma.png');

    WMS.spinner();
    try {
      const token   = localStorage.getItem('wms_token') || sessionStorage.getItem('wms_token') || localStorage.getItem('token') || '';
      const baseUrl = (typeof API_BASE !== 'undefined' ? API_BASE : (API.BASE_URL || '/WMS_FENIX/public/api'));
      const res  = await fetch(`${baseUrl}/preoperacional`, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: formData,
      });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.message || 'Error al registrar');

      WMS.toast('success', 'Preoperacional registrado correctamente');
      WMS.nav('preoperacional', 'historial');
    } catch (e) {
      WMS.toast('error', e.message || 'Error al registrar el preoperacional');
    } finally {
      WMS.spinnerHide();
    }
  },

  // ── Historial ─────────────────────────────────────────────────────────────
  async show_historial(filters = null) {
    if (filters) Object.assign(this._histFilters, filters);
    const f = this._histFilters;
    if (!f.ini) f.ini = WMS.getPastDate(30);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS.nav('preoperacional','nueva')">
        <i class="fa-solid fa-plus"></i> Nuevo Preoperacional
      </button>
    `);

    WMS.spinner();
    try {
      const qs = `fecha_inicio=${f.ini}&fecha_fin=${f.fin}&vehiculo=${encodeURIComponent(f.vehiculo||'')}&conductor=${encodeURIComponent(f.conductor||'')}&solo_nc=${f.soloNc?'1':'0'}`;
      const r = await API.get('/preoperacional', qs);
      const rows = r.data || [];

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:14px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="pv-h-ini" class="form-control form-control-sm" value="${f.ini}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="pv-h-fin" class="form-control form-control-sm" value="${f.fin}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">VEHÍCULO</label>
                <input id="pv-h-veh" class="form-control form-control-sm" value="${WMS.esc(f.vehiculo||'')}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">CONDUCTOR</label>
                <input id="pv-h-cond" class="form-control form-control-sm" value="${WMS.esc(f.conductor||'')}"></div>
              <div style="display:flex;align-items:center;gap:6px;padding-bottom:5px;">
                <input type="checkbox" id="pv-h-nc" ${f.soloNc?'checked':''}> <label for="pv-h-nc" style="font-size:11px;font-weight:700;color:#64748b;margin:0;">Solo con No Cumple</label>
              </div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.preoperacional._aplicarFiltrosHist()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
          </div>

          <table class="data-table">
            <thead><tr>
              <th>Fecha</th><th>Vehículo</th><th>Conductor</th><th>Ruta</th><th>Registró</th>
              <th style="text-align:center;">No Cumple</th><th></th>
            </tr></thead>
            <tbody>
              ${rows.length ? rows.map(p => `
                <tr style="cursor:pointer;" onclick="WMS_MODULES.preoperacional._verDetalle(${p.id})">
                  <td>${WMS.formatDate(p.fecha)}</td>
                  <td style="font-weight:700;">${WMS.esc(p.vehiculo)}</td>
                  <td>${WMS.esc(p.conductor)}</td>
                  <td>${WMS.esc(p.ruta || '-')}</td>
                  <td style="font-size:.75rem;color:#64748b;">${WMS.esc(p.creador?.nombre || '-')}</td>
                  <td style="text-align:center;">
                    ${p.nc_count > 0
                      ? `<span class="badge" style="background:#fee2e2;color:#dc2626;">${p.nc_count}</span>`
                      : `<span class="badge" style="background:#dcfce7;color:#16a34a;">0</span>`}
                  </td>
                  <td><i class="fa-solid fa-chevron-right" style="color:#94a3b8;"></i></td>
                </tr>`).join('')
              : `<tr><td colspan="7" class="table-empty">Sin registros en el rango seleccionado</td></tr>`}
            </tbody>
          </table>
        </div>
      `);
    } catch (e) {
      WMS.toast('error', 'Error cargando historial');
    } finally {
      WMS.spinnerHide();
    }
  },

  _aplicarFiltrosHist() {
    const ini  = document.getElementById('pv-h-ini')?.value || '';
    const fin  = document.getElementById('pv-h-fin')?.value || '';
    const veh  = document.getElementById('pv-h-veh')?.value || '';
    const cond = document.getElementById('pv-h-cond')?.value || '';
    const nc   = !!document.getElementById('pv-h-nc')?.checked;
    this.show_historial({ ini, fin, vehiculo: veh, conductor: cond, soloNc: nc });
  },

  async _verDetalle(id) {
    WMS.spinner();
    try {
      const r = await API.get(`/preoperacional/${id}`);
      const p = r.data;
      const items = p.items || [];
      // Las fotos vienen del backend como ruta raíz ('/uploads/preoperacional/...'),
      // sin el prefijo de la app — al usarlas tal cual en href/src, el navegador
      // las resolvía contra el dominio (localhost/uploads/...) en vez de
      // localhost/WMS_FENIX/public/uploads/..., dando 404. Mismo patrón que ya usan
      // otros módulos (recepcion.js) para anteponer el base path de la app.
      const up = (u) => u ? (window.location.origin + '/WMS_FENIX/public' + u) : u;

      const html = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;font-size:.85rem;">
          <div><b>Fecha:</b> ${WMS.formatDate(p.fecha)}</div>
          <div><b>Vehículo:</b> ${WMS.esc(p.vehiculo)}</div>
          <div><b>Conductor:</b> ${WMS.esc(p.conductor)}</div>
          <div><b>Ruta:</b> ${WMS.esc(p.ruta || '-')}</div>
        </div>
        <table class="data-table compact" style="margin-bottom:12px;">
          <thead><tr><th>Ítem</th><th style="text-align:center;">Calificación</th><th>Evidencia</th></tr></thead>
          <tbody>
            ${items.map(it => `
              <tr>
                <td>${WMS.esc(it.item_nombre)}</td>
                <td style="text-align:center;">
                  <span class="badge" style="background:${it.calificacion==='C'?'#dcfce7':'#fee2e2'};color:${it.calificacion==='C'?'#16a34a':'#dc2626'};">
                    ${it.calificacion === 'C' ? 'Cumple' : 'No Cumple'}
                  </span>
                </td>
                <td>${it.foto_url ? `<a href="${WMS.esc(up(it.foto_url))}" target="_blank"><i class="fa-solid fa-image"></i> Ver foto</a>` : '-'}</td>
              </tr>
              ${(it.calificacion === 'C' && it.producto_desinfeccion) ? `
              <tr>
                <td colspan="3" style="background:#f0fdf4;font-size:.8rem;color:#166534;"><b>Producto usado:</b> ${WMS.esc(it.producto_desinfeccion === 'Acido Peracetico' ? 'Ácido Peracético' : it.producto_desinfeccion)}</td>
              </tr>` : ''}
              ${it.calificacion === 'NC' ? `
              <tr>
                <td colspan="3" style="background:#fef2f2;">
                  <div style="font-size:.8rem;color:#991b1b;"><b>Detalle:</b> ${WMS.esc(it.detalle_no_conformidad || '-')}</div>
                  <div style="font-size:.8rem;color:#991b1b;margin-top:2px;"><b>Acción correctiva:</b> ${WMS.esc(it.accion_correctiva || '-')}</div>
                  ${(it.fotos && it.fotos.length) ? `
                  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                    ${it.fotos.map(f => `<a href="${WMS.esc(up(f.url))}" target="_blank"><img src="${WMS.esc(up(f.url))}" style="width:70px;height:70px;object-fit:cover;border-radius:6px;border:1px solid #fecaca;"></a>`).join('')}
                  </div>` : ''}
                </td>
              </tr>` : ''}`).join('')}
          </tbody>
        </table>
        ${p.observaciones ? `<div style="margin-bottom:12px;"><b>Observaciones:</b><p style="margin-top:4px;color:#475569;">${WMS.esc(p.observaciones)}</p></div>` : ''}
        ${(p.fotos && p.fotos.length) ? `
        <div style="margin-bottom:12px;">
          <b>Fotos de la inspección:</b>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
            ${p.fotos.map(f => `<a href="${WMS.esc(up(f.url))}" target="_blank"><img src="${WMS.esc(up(f.url))}" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;"></a>`).join('')}
          </div>
        </div>` : ''}
        ${p.firma_url ? `
        <div>
          <b>Firma del conductor:</b>
          <div style="margin-top:8px;"><img src="${WMS.esc(up(p.firma_url))}" style="max-width:220px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;"></div>
        </div>` : ''}
      `;

      if (typeof WMS.showModal === 'function') {
        WMS.showModal('Detalle Preoperacional', html);
      } else {
        WMS.setContent(`<div class="op-view"><div class="card"><div class="card-body" style="padding:20px;">${html}</div></div></div>`);
      }
    } catch (e) {
      WMS.toast('error', 'Error cargando el detalle');
    } finally {
      WMS.spinnerHide();
    }
  },

  // ── Dashboard Preoperacional (2026-09-17) ────────────────────────────────
  async show_dashboard(filters = null) {
    if (filters) Object.assign(this._dashFilters, filters);
    const f = this._dashFilters;
    if (!f.ini) f.ini = WMS.getPastDate(30);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-outline-secondary btn-sm" onclick="WMS.nav('preoperacional','matriz')">
        <i class="fa-solid fa-table-list"></i> Matriz por vehículo
      </button>
    `);

    WMS.spinner();
    try {
      const r = await API.get('/preoperacional/dashboard', `fecha_inicio=${f.ini}&fecha_fin=${f.fin}`);
      const d = r.data || {};
      const kpi = d.kpis || {};
      const topItems = d.top_items_nc || [];
      const topVeh = d.top_vehiculos || [];

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:16px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="pvd-ini" class="form-control form-control-sm" value="${f.ini}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="pvd-fin" class="form-control form-control-sm" value="${f.fin}"></div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.preoperacional._aplicarFiltrosDashPreop()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;">
            ${this._kpiCard('fa-clipboard-list', '#0F4C81', 'Inspecciones', kpi.total_inspecciones ?? 0, `${kpi.vehiculos_inspeccionados ?? 0} vehículos inspeccionados`)}
            ${this._kpiCard('fa-triangle-exclamation', '#dc2626', 'No Conformidades', kpi.total_nc ?? 0, `de ${kpi.total_items ?? 0} ítems evaluados`)}
            ${this._kpiCard('fa-gauge-high', kpi.pct_cumplimiento >= 90 ? '#16a34a' : '#dc2626', '% Cumplimiento', `${kpi.pct_cumplimiento ?? 100}%`, 'ítems Cumple sobre el total evaluado')}
          </div>

          <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:14px;">
            <div class="card">
              <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-line"></i> Tendencia diaria</span></div>
              <div class="card-body" style="padding:16px;height:280px;">
                <canvas id="pvd-chart-tendencia"></canvas>
              </div>
            </div>
            <div class="card">
              <div class="card-header"><span class="card-title"><i class="fa-solid fa-ranking-star"></i> Ítems con más No Cumple</span></div>
              <div class="card-body" style="padding:10px 16px;">
                ${topItems.length ? `<table class="data-table compact"><tbody>
                  ${topItems.map(it => `<tr><td>${WMS.esc(it.item_nombre)}</td><td style="text-align:right;font-weight:700;color:#dc2626;">${it.veces_nc}</td></tr>`).join('')}
                </tbody></table>` : '<p class="table-empty">Sin novedades en el rango seleccionado</p>'}
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:14px;">
            <div class="card-header"><span class="card-title"><i class="fa-solid fa-truck"></i> Vehículos con más No Cumple</span></div>
            <div class="card-body" style="padding:10px 16px;">
              ${topVeh.length ? `<table class="data-table compact">
                <thead><tr><th>Vehículo</th><th style="text-align:right;">No Cumple</th></tr></thead>
                <tbody>
                  ${topVeh.map(v => `<tr style="cursor:pointer;" onclick="WMS.nav('preoperacional','matriz')"><td>${WMS.esc(v.vehiculo)}</td><td style="text-align:right;font-weight:700;color:#dc2626;">${v.total_nc}</td></tr>`).join('')}
                </tbody>
              </table>` : '<p class="table-empty">Sin novedades en el rango seleccionado</p>'}
            </div>
          </div>
        </div>
      `);

      this._renderTendenciaPreop(d.tendencia || [], f.ini, f.fin);
    } catch (e) {
      WMS.toast('error', 'Error cargando el dashboard de preoperacional');
    } finally {
      WMS.spinnerHide();
    }
  },

  _kpiCard(icon, color, label, value, sub) {
    return `
      <div class="card" style="padding:16px;">
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="width:42px;height:42px;border-radius:10px;background:${color}1a;color:${color};display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
            <i class="fa-solid ${icon}"></i>
          </div>
          <div>
            <div style="font-size:1.4rem;font-weight:800;color:#1e293b;line-height:1.1;">${value}</div>
            <div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">${WMS.esc(label)}</div>
          </div>
        </div>
        <div style="font-size:.72rem;color:#94a3b8;margin-top:8px;">${WMS.esc(sub)}</div>
      </div>`;
  },

  _renderTendenciaPreop(tendencia, ini, fin) {
    const ctx = document.getElementById('pvd-chart-tendencia');
    if (!ctx || !window.Chart) return;

    const porFecha = {};
    tendencia.forEach(t => { porFecha[t.fecha] = t; });
    const dias = [];
    for (let d = new Date(ini + 'T00:00:00'); d <= new Date(fin + 'T00:00:00'); d.setDate(d.getDate() + 1)) {
      dias.push(d.toISOString().slice(0, 10));
    }
    const labels = dias.map(d => WMS.formatDate(d));
    const inspecciones = dias.map(d => porFecha[d] ? parseInt(porFecha[d].inspecciones) || 0 : 0);
    const noCumple = dias.map(d => porFecha[d] ? parseInt(porFecha[d].no_cumple) || 0 : 0);

    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Inspecciones', data: inspecciones, borderColor: '#0F4C81', backgroundColor: '#0F4C8120', borderWidth: 2, tension: 0.25, fill: false },
          { label: 'No Cumple', data: noCumple, borderColor: '#dc2626', backgroundColor: '#dc262620', borderWidth: 2, tension: 0.25, fill: false },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, font: { size: 10 } } } },
        scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } }, x: { grid: { display: false } } },
      },
    });
  },

  _aplicarFiltrosDashPreop() {
    const ini = document.getElementById('pvd-ini')?.value || '';
    const fin = document.getElementById('pvd-fin')?.value || '';
    this.show_dashboard({ ini, fin });
  },

  // ── Matriz de no conformidades por vehículo ──────────────────────────────
  async show_matriz(filters = null) {
    if (filters) Object.assign(this._matrizFilters, filters);
    const f = this._matrizFilters;
    if (!f.ini) f.ini = WMS.getPastDate(30);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-outline-secondary btn-sm" onclick="WMS.nav('preoperacional','dashboard')">
        <i class="fa-solid fa-gauge"></i> Ver dashboard
      </button>
    `);

    WMS.spinner();
    try {
      const r = await API.get('/preoperacional/matriz-vehiculos', `fecha_inicio=${f.ini}&fecha_fin=${f.fin}`);
      const d = r.data || {};
      this._matrizNc = d.no_conformidades || [];
      const matriz = d.matriz || [];

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:14px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="pvm-ini" class="form-control form-control-sm" value="${f.ini}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="pvm-fin" class="form-control form-control-sm" value="${f.fin}"></div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.preoperacional._aplicarFiltrosMatriz()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
          </div>

          <table class="data-table">
            <thead><tr>
              <th>Vehículo</th><th>Tipo</th><th style="text-align:center;">Inspecciones</th>
              <th style="text-align:center;">No Cumple</th><th>Última inspección</th><th></th>
            </tr></thead>
            <tbody>
              ${matriz.length ? matriz.map(v => `
                <tr style="cursor:pointer;" onclick="WMS_MODULES.preoperacional._verNcVehiculo('${WMS.esc(v.vehiculo)}')">
                  <td style="font-weight:700;">${WMS.esc(v.vehiculo)}</td>
                  <td>${WMS.esc(v.tipo_vehiculo || '-')}</td>
                  <td style="text-align:center;">${v.total_inspecciones}</td>
                  <td style="text-align:center;">
                    ${v.total_nc > 0
                      ? `<span class="badge" style="background:#fee2e2;color:#dc2626;">${v.total_nc}</span>`
                      : `<span class="badge" style="background:#dcfce7;color:#16a34a;">0</span>`}
                  </td>
                  <td>${WMS.formatDate(v.ultima_inspeccion)}</td>
                  <td><i class="fa-solid fa-chevron-right" style="color:#94a3b8;"></i></td>
                </tr>`).join('')
              : `<tr><td colspan="6" class="table-empty">Sin registros en el rango seleccionado</td></tr>`}
            </tbody>
          </table>
        </div>
      `);
    } catch (e) {
      WMS.toast('error', 'Error cargando la matriz de vehículos');
    } finally {
      WMS.spinnerHide();
    }
  },

  _aplicarFiltrosMatriz() {
    const ini = document.getElementById('pvm-ini')?.value || '';
    const fin = document.getElementById('pvm-fin')?.value || '';
    this.show_matriz({ ini, fin });
  },

  _verNcVehiculo(vehiculo) {
    const up = (u) => u ? (window.location.origin + '/WMS_FENIX/public' + u) : u;
    const rows = (this._matrizNc || []).filter(nc => nc.vehiculo === vehiculo);
    const html = `
      <div style="max-height:60vh;overflow-y:auto;">
        ${rows.length ? rows.map(nc => `
          <div class="card" style="padding:12px 14px;margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
              <b style="font-size:.85rem;">${WMS.esc(nc.item_nombre)}</b>
              <span style="font-size:.72rem;color:#64748b;">${WMS.formatDate(nc.fecha)} · ${WMS.esc(nc.conductor)}</span>
            </div>
            <div style="font-size:.8rem;color:#991b1b;"><b>Detalle:</b> ${WMS.esc(nc.detalle_no_conformidad || '-')}</div>
            <div style="font-size:.8rem;color:#991b1b;margin-top:2px;"><b>Acción correctiva:</b> ${WMS.esc(nc.accion_correctiva || '-')}</div>
            ${(nc.fotos && nc.fotos.length) ? `
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">
              ${nc.fotos.map(url => `<a href="${WMS.esc(up(url))}" target="_blank"><img src="${WMS.esc(up(url))}" style="width:70px;height:70px;object-fit:cover;border-radius:6px;border:1px solid #fecaca;"></a>`).join('')}
            </div>` : ''}
          </div>`).join('') : '<p class="table-empty">Sin no conformidades registradas</p>'}
      </div>`;
    if (typeof WMS.showModal === 'function') {
      WMS.showModal(`No Conformidades — ${vehiculo}`, html, '', 'lg');
    }
  },
};
