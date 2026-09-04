/* ============================================================
   WMS Desktop — Módulo CALIDAD
   Sub-vistas: dashboard | matriz
   Consolida, sin duplicar datos, lo que ya vive en Recepción
   (recepcion_calidad) y en Preoperacional — solo lectura.
   ============================================================ */
WMS_MODULES.calidad = {
  _sub: null,
  _dashFilters: { ini: '', fin: '' },
  _matrizFilters: { ini: '', fin: '', tipo: '', soloNc: false },

  load(sub) {
    this._sub = sub || 'dashboard';
    WMS.renderSidebar('calidad');

    if (this._sub === 'preoperacional-nueva' || this._sub === 'preoperacional-historial') {
      const subPreop = this._sub === 'preoperacional-historial' ? 'historial' : 'nueva';
      WMS.setBreadcrumb('calidad', subPreop === 'historial' ? 'Historial Inspecciones Preoperacionales' : 'Registro Preoperacional');
      WMS.setToolbar('');
      WMS.loadScript('assets/js/desktop/preoperacional.js', () => {
        if (WMS_MODULES.preoperacional) {
          WMS_MODULES.preoperacional.load(subPreop);
        }
      });
      return;
    }

    if (this._sub === 'devoluciones-consulta') {
      WMS.setBreadcrumb('calidad', 'Consulta por Consecutivo');
      WMS.setToolbar('');
      return this.show_devolucionesConsulta();
    }

    if (this._sub === 'devoluciones-informe') {
      WMS.setBreadcrumb('calidad', 'Devoluciones y Trazabilidad');
      WMS.setToolbar('');
      return this.show_devolucionesInforme();
    }

    WMS.setBreadcrumb('calidad', this._sub === 'matriz' ? 'Matriz de Registros' : 'Tablero de Control');
    WMS.setToolbar('');
    if (this._sub === 'matriz') this.show_matriz();
    else this.show_dashboard();
  },

  // ── Tablero de control ───────────────────────────────────────────────────
  async show_dashboard(filters = null) {
    if (filters) Object.assign(this._dashFilters, filters);
    const f = this._dashFilters;
    if (!f.ini) f.ini = WMS.getPastDate(30);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.spinner();
    try {
      const r = await API.get('/calidad/dashboard', `fecha_inicio=${f.ini}&fecha_fin=${f.fin}`);
      const d = r.data || {};
      const rc = d.recepcion || {};
      const po = d.preoperacional || {};

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:16px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="cd-ini" class="form-control form-control-sm" value="${f.ini}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="cd-fin" class="form-control form-control-sm" value="${f.fin}"></div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.calidad._aplicarFiltrosDash()"><i class="fa-solid fa-filter"></i> Filtrar</button>
              <button class="btn btn-outline-secondary btn-sm" onclick="WMS.nav('calidad','matriz')"><i class="fa-solid fa-table-list"></i> Ver matriz completa</button>
            </div>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px;">
            ${this._kpiCard('fa-truck-ramp-box', '#0F4C81', 'Auditorías de Recepción', rc.total_registros ?? 0, `${rc.conformes ?? 0} conformes / ${rc.no_conformes ?? 0} inconformes`)}
            ${this._kpiCard('fa-clipboard-check', rc.pct_conforme == null ? '#94a3b8' : (rc.pct_conforme >= 90 ? '#16a34a' : '#dc2626'), '% Conforme (auditado)', rc.pct_conforme == null ? '—' : `${rc.pct_conforme}%`, `${rc.sin_auditoria ?? 0} recepciones sin auditoría manual`)}
            ${this._kpiCard('fa-flask-vial', '#0891b2', 'Líneas de producto con NC', rc.lineas_con_nc ?? 0, `de ${rc.lineas_producto ?? 0} líneas evaluadas`)}
            ${this._kpiCard('fa-truck-field', '#7c3aed', 'Preoperacionales', po.total_registros ?? 0, `${po.con_nc ?? 0} con novedades / ${po.sin_nc ?? 0} sin novedades`)}
          </div>

          <div style="display:grid;grid-template-columns:1fr;gap:14px;">
            <div class="card">
              <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-line"></i> Tendencia diaria</span></div>
              <div class="card-body" style="padding:16px;height:280px;">
                <canvas id="cd-chart-tendencia"></canvas>
              </div>
            </div>
          </div>

          <p style="font-size:.72rem;color:#94a3b8;margin-top:10px;">
            <i class="fa-solid fa-circle-info"></i> Las recepciones cerradas sin auditoría manual de calidad se contabilizan aparte ("sin auditoría") y no afectan el % Conforme.
          </p>
        </div>
      `);

      this._renderTendencia(d.serie_recepcion || {}, d.serie_preoperacional || {}, f.ini, f.fin);
    } catch (e) {
      WMS.toast('error', 'Error cargando el tablero de calidad');
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

  _renderTendencia(serieRecep, seriePreop, ini, fin) {
    const ctx = document.getElementById('cd-chart-tendencia');
    if (!ctx || !window.Chart) return;

    const dias = [];
    for (let d = new Date(ini + 'T00:00:00'); d <= new Date(fin + 'T00:00:00'); d.setDate(d.getDate() + 1)) {
      dias.push(d.toISOString().slice(0, 10));
    }
    const totalRecep   = dias.map(d => serieRecep[d] ? parseInt(serieRecep[d].total) || 0 : 0);
    const noConfRecep  = dias.map(d => serieRecep[d] ? parseInt(serieRecep[d].no_conformes) || 0 : 0);
    const totalPreop   = dias.map(d => seriePreop[d] ? parseInt(seriePreop[d].total) || 0 : 0);
    const labels       = dias.map(d => WMS.formatDate(d));

    new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Recepciones auditadas', data: totalRecep, borderColor: '#0F4C81', backgroundColor: '#0F4C8120', borderWidth: 2, tension: 0.25, fill: false },
          { label: 'Recepciones inconformes', data: noConfRecep, borderColor: '#dc2626', backgroundColor: '#dc262620', borderWidth: 2, tension: 0.25, fill: false },
          { label: 'Preoperacionales', data: totalPreop, borderColor: '#7c3aed', backgroundColor: '#7c3aed20', borderWidth: 2, tension: 0.25, fill: false },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true, font: { size: 10 } } } },
        scales: { y: { beginAtZero: true, grid: { borderDash: [2, 4] } }, x: { grid: { display: false } } },
      },
    });
  },

  _aplicarFiltrosDash() {
    const ini = document.getElementById('cd-ini')?.value || '';
    const fin = document.getElementById('cd-fin')?.value || '';
    this.show_dashboard({ ini, fin });
  },

  // ── Matriz de registros ──────────────────────────────────────────────────
  async show_matriz(filters = null) {
    if (filters) Object.assign(this._matrizFilters, filters);
    const f = this._matrizFilters;
    if (!f.ini) f.ini = WMS.getPastDate(30);
    if (!f.fin) f.fin = WMS.getToday();

    WMS.setToolbar(`
      <button class="btn btn-outline-secondary btn-sm" onclick="WMS.nav('calidad','dashboard')">
        <i class="fa-solid fa-gauge"></i> Ver tablero
      </button>
    `);

    WMS.spinner();
    try {
      const qs = `fecha_inicio=${f.ini}&fecha_fin=${f.fin}&tipo=${f.tipo || ''}&solo_nc=${f.soloNc ? '1' : '0'}`;
      const r = await API.get('/calidad/matriz', qs);
      const rows = r.data || [];

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:14px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="cm-ini" class="form-control form-control-sm" value="${f.ini}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="cm-fin" class="form-control form-control-sm" value="${f.fin}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">TIPO</label>
                <select id="cm-tipo" class="form-control form-control-sm">
                  <option value="" ${!f.tipo ? 'selected' : ''}>Todos</option>
                  <option value="recepcion" ${f.tipo === 'recepcion' ? 'selected' : ''}>Recepción</option>
                  <option value="preoperacional" ${f.tipo === 'preoperacional' ? 'selected' : ''}>Preoperacional</option>
                </select></div>
              <div style="display:flex;align-items:center;gap:6px;padding-bottom:5px;">
                <input type="checkbox" id="cm-nc" ${f.soloNc ? 'checked' : ''}> <label for="cm-nc" style="font-size:11px;font-weight:700;color:#64748b;margin:0;">Solo inconformes</label>
              </div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.calidad._aplicarFiltrosMatriz()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
          </div>

          <table class="data-table">
            <thead><tr>
              <th>Tipo</th><th>Fecha</th><th>Referencia</th><th>Detalle</th><th>Resultado</th><th></th>
            </tr></thead>
            <tbody>
              ${rows.length ? rows.map(row => `
                <tr style="cursor:pointer;" onclick="WMS_MODULES.calidad._verDetalle('${row.tipo}',${row.id})">
                  <td><span class="badge" style="background:${row.tipo === 'recepcion' ? '#e0f2fe' : '#ede9fe'};color:${row.tipo === 'recepcion' ? '#0369a1' : '#6d28d9'};">${WMS.esc(row.tipo_label)}</span></td>
                  <td>${WMS.formatDate(row.fecha)}</td>
                  <td style="font-weight:700;">${WMS.esc(row.referencia || '-')}</td>
                  <td style="font-size:.8rem;color:#64748b;">${WMS.esc(row.detalle || '-')}</td>
                  <td>${this._badgeResultado(row.resultado, row.es_conforme)}</td>
                  <td><i class="fa-solid fa-chevron-right" style="color:#94a3b8;"></i></td>
                </tr>`).join('')
              : `<tr><td colspan="6" class="table-empty">Sin registros en el rango seleccionado</td></tr>`}
            </tbody>
          </table>
        </div>
      `);
    } catch (e) {
      WMS.toast('error', 'Error cargando la matriz de calidad');
    } finally {
      WMS.spinnerHide();
    }
  },

  _badgeResultado(resultado, esConforme) {
    let bg = '#f1f5f9', color = '#64748b';
    if (esConforme === true) { bg = '#dcfce7'; color = '#16a34a'; }
    else if (esConforme === false) { bg = '#fee2e2'; color = '#dc2626'; }
    return `<span class="badge" style="background:${bg};color:${color};">${WMS.esc(resultado)}</span>`;
  },

  _aplicarFiltrosMatriz() {
    const ini  = document.getElementById('cm-ini')?.value || '';
    const fin  = document.getElementById('cm-fin')?.value || '';
    const tipo = document.getElementById('cm-tipo')?.value || '';
    const nc   = !!document.getElementById('cm-nc')?.checked;
    this.show_matriz({ ini, fin, tipo, soloNc: nc });
  },

  async _verDetalle(tipo, id) {
    if (tipo === 'preoperacional') return this._verDetallePreoperacional(id);
    return this._verDetalleRecepcion(id);
  },

  async _verDetalleRecepcion(id) {
    WMS.spinner();
    try {
      const r = await API.get(`/calidad/recepcion/${id}`);
      const e = r.data.encabezado;
      const detalles = r.data.detalles || [];

      const badgeCNC = v => v ? `<span class="badge" style="background:${v === 'C' ? '#dcfce7' : '#fee2e2'};color:${v === 'C' ? '#16a34a' : '#dc2626'};">${v}</span>` : '-';

      const html = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;font-size:.85rem;">
          <div><b>Recepción:</b> ${WMS.esc(e.numero_recepcion || '-')}</div>
          <div><b>Fecha:</b> ${WMS.formatDate(e.fecha_movimiento || e.created_at)}</div>
          <div><b>Placa transporte:</b> ${WMS.esc(e.trans_placa || '-')}</div>
          <div><b>Factura:</b> ${WMS.esc(e.factura || '-')}</div>
        </div>
        <table class="data-table compact" style="margin-bottom:12px;">
          <thead><tr><th>Temperatura</th><th>Limpieza</th><th>Concepto sanitario</th><th>Carnet manipulación</th></tr></thead>
          <tbody><tr>
            <td>${badgeCNC(e.trans_temperatura)}</td>
            <td>${badgeCNC(e.trans_limpieza)}</td>
            <td>${badgeCNC(e.trans_concepto_sanitario)}</td>
            <td>${badgeCNC(e.trans_carnet_manipulacion)}</td>
          </tr></tbody>
        </table>
        ${detalles.length ? `
        <table class="data-table compact" style="margin-bottom:12px;">
          <thead><tr><th>Producto</th><th>Olor</th><th>Color</th><th>Textura</th><th>Temp.</th><th>Empaque</th><th>Rotulado</th></tr></thead>
          <tbody>
            ${detalles.map(d => `
              <tr>
                <td>${WMS.esc(d.producto_nombre || d.producto_codigo || '-')}</td>
                <td>${badgeCNC(d.olor)}</td><td>${badgeCNC(d.color)}</td><td>${badgeCNC(d.textura)}</td>
                <td>${badgeCNC(d.temperatura)}</td><td>${badgeCNC(d.empaque)}</td><td>${badgeCNC(d.rotulado)}</td>
              </tr>`).join('')}
          </tbody>
        </table>` : ''}
        ${e.foto_evidencia ? `<div><b>Foto de evidencia:</b><br><a href="${WMS.esc(e.foto_evidencia)}" target="_blank"><img src="${WMS.esc(e.foto_evidencia)}" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;margin-top:6px;"></a></div>` : ''}
      `;
      WMS.showModal('Auditoría de Calidad — Recepción', html, '', 'lg');
    } catch (e) {
      WMS.toast('error', 'Error cargando el detalle de la recepción');
    } finally {
      WMS.spinnerHide();
    }
  },

  async _verDetallePreoperacional(id) {
    WMS.spinner();
    try {
      const r = await API.get(`/preoperacional/${id}`);
      const p = r.data;
      const items = p.items || [];

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
                  <span class="badge" style="background:${it.calificacion === 'C' ? '#dcfce7' : '#fee2e2'};color:${it.calificacion === 'C' ? '#16a34a' : '#dc2626'};">
                    ${it.calificacion === 'C' ? 'Cumple' : 'No Cumple'}
                  </span>
                </td>
                <td>${it.foto_url ? `<a href="${WMS.esc(it.foto_url)}" target="_blank"><i class="fa-solid fa-image"></i> Ver foto</a>` : '-'}</td>
              </tr>`).join('')}
          </tbody>
        </table>
        ${p.observaciones ? `<div style="margin-bottom:12px;"><b>Observaciones:</b><p style="margin-top:4px;color:#475569;">${WMS.esc(p.observaciones)}</p></div>` : ''}
        ${(p.fotos && p.fotos.length) ? `
        <div>
          <b>Fotos de la inspección:</b>
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
            ${p.fotos.map(f => `<a href="${WMS.esc(f.url)}" target="_blank"><img src="${WMS.esc(f.url)}" style="width:90px;height:90px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;"></a>`).join('')}
          </div>
        </div>` : ''}
      `;
      WMS.showModal('Detalle Preoperacional', html, '', 'lg');
    } catch (e) {
      WMS.toast('error', 'Error cargando el detalle del preoperacional');
    } finally {
      WMS.spinnerHide();
    }
  },

  // ── Consulta de Devolución por Consecutivo ──────────────────────────────
  show_devolucionesConsulta() {
    WMS.setContent(`
      <div class="op-view" style="max-width:1000px;">
        <div class="card" style="padding:24px;margin-bottom:20px;">
          <div class="card-title" style="margin-bottom:12px;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-magnifying-glass" style="color:#0F4C81;"></i> Consulta de Devolución por Consecutivo
          </div>
          <p style="font-size:13px;color:#64748b;margin-bottom:16px;">
            Ingresa o escanea el número consecutivo (ej. 1, 2, 47...) asignado al producto devuelto para ver su trazabilidad y registro fotográfico.
          </p>
          <div style="display:flex;gap:12px;max-width:500px;">
            <input type="text" id="cq-consecutivo-input" class="form-control form-control-lg" placeholder="Número consecutivo (ej. 47)"
              onkeydown="if(event.key==='Enter'){WMS_MODULES.calidad._buscarConsecutivo();event.preventDefault();}">
            <button class="btn btn-primary" onclick="WMS_MODULES.calidad._buscarConsecutivo()">
              <i class="fa-solid fa-search"></i> Consultar
            </button>
          </div>
        </div>

        <div id="cq-resultado-container"></div>
      </div>
    `);
    document.getElementById('cq-consecutivo-input')?.focus();
  },

  async _buscarConsecutivo() {
    const val = document.getElementById('cq-consecutivo-input')?.value?.trim();
    if (!val) { WMS.toast('error', 'Ingrese el consecutivo a buscar'); return; }

    const numClean = val.replace(/^#/, '').trim();
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/buscar-consecutivo?consecutivo=' + encodeURIComponent(numClean));
      const d = r.data;
      this._renderTrazabilidadConsecutivo(d);
    } catch(e) {
      document.getElementById('cq-resultado-container').innerHTML = `
        <div class="card" style="padding:30px;text-align:center;color:#dc2626;">
          <i class="fa-solid fa-triangle-exclamation" style="font-size:36px;margin-bottom:12px;"></i>
          <h4 style="margin:0 0 6px;">Consecutivo #${WMS.esc(numClean)} no encontrado</h4>
          <p style="margin:0;font-size:13px;color:#64748b;">No se encontró ningún registro de devolución coincidente con este consecutivo.</p>
        </div>`;
    } finally {
      WMS.spinnerHide();
    }
  },

  _renderTrazabilidadConsecutivo(d) {
    const el = document.getElementById('cq-resultado-container');
    if (!el) return;

    const badgeColor = {
      PendienteAprobacion:'#f59e0b', Aprobada:'#3b82f6', Procesada:'#16a34a',
      Rechazada:'#dc2626', Anulada:'#94a3b8', Borrador:'#64748b',
    };

    const fotosList = Array.isArray(d.fotos_json)
      ? d.fotos_json
      : (typeof d.fotos_json === 'string' ? JSON.parse(d.fotos_json || '[]') : []);

    const consecTxt = d.consecutivo_devolucion ? `#${d.consecutivo_devolucion}` : 'Sin consecutivo';

    el.innerHTML = `
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;background:#f0f9ff;border-bottom:2px solid #bae6fd;">
          <div style="display:flex;align-items:center;gap:14px;">
            <div style="background:#0F4C81;color:#fff;font-size:1.6rem;font-weight:900;padding:6px 18px;border-radius:10px;">
              ${WMS.esc(consecTxt)}
            </div>
            <div>
              <div style="font-size:14px;font-weight:700;color:#0369a1;">N° Registro Interno: ${WMS.esc(d.numero_devolucion)}</div>
              <div style="font-size:11px;color:#64748b;">Fecha movimiento: ${d.created_at ? d.created_at.substring(0,10) : '-'}</div>
            </div>
          </div>
          <span class="badge" style="background:${badgeColor[d.estado]||'#94a3b8'}20;color:${badgeColor[d.estado]||'#94a3b8'};border:1.5px solid ${badgeColor[d.estado]||'#94a3b8'};font-size:14px;padding:6px 14px;">
            ${WMS.esc(d.estado)}
          </span>
        </div>

        <!-- Timeline de Trazabilidad -->
        <div style="padding:20px;border-bottom:1px solid #e2e8f0;">
          <div style="font-size:12px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:14px;">
            <i class="fa-solid fa-timeline" style="color:#0F4C81;"></i> Línea del Tiempo y Trazabilidad
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;font-size:12px;">
            <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
              <div style="color:#64748b;font-size:10px;font-weight:700;">1. REGISTRO & ORIGEN</div>
              <div style="font-weight:700;color:#1e293b;margin-top:2px;">${WMS.esc(d.auxiliar?.nombre || d.solicitado_por_nombre || 'Auxiliar')}</div>
              <div style="font-size:11px;color:#0F4C81;margin-top:2px;"><i class="fa-solid fa-store"></i> ${WMS.esc(d.sucursal_origen?.nombre || 'Sucursal Actual')}</div>
            </div>

            <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
              <div style="color:#64748b;font-size:10px;font-weight:700;">2. CAUSAL & MOTIVO</div>
              <div style="font-weight:700;color:#1e293b;margin-top:2px;">${WMS.esc(d.causal?.causal || d.motivo_general || '-')}</div>
              <div style="font-size:11px;color:#64748b;margin-top:2px;">Resp: ${WMS.esc(d.responsable_devolucion || d.causal?.responsable || '-')}</div>
            </div>

            <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
              <div style="color:#64748b;font-size:10px;font-weight:700;">3. APROBACIÓN</div>
              <div style="font-weight:700;color:#1e293b;margin-top:2px;">${d.aprobador?.nombre ? WMS.esc(d.aprobador.nombre) : (d.aprobado_at ? 'Aprobado' : 'Pendiente')}</div>
              <div style="font-size:11px;color:#64748b;margin-top:2px;">${d.aprobado_at ? WMS.formatDate(d.aprobado_at) : 'En espera'}</div>
            </div>

            <div style="background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
              <div style="color:#64748b;font-size:10px;font-weight:700;">4. PROCESAMIENTO</div>
              <div style="font-weight:700;color:#1e293b;margin-top:2px;">${d.procesador?.nombre ? WMS.esc(d.procesador.nombre) : (d.procesado_at ? 'Procesada' : 'Pendiente')}</div>
              <div style="font-size:11px;color:#64748b;margin-top:2px;">${d.procesado_at ? WMS.formatDate(d.procesado_at) : 'Sin procesar'}</div>
            </div>
          </div>
        </div>

        <!-- Tabla de ítems con Regla Sagrada (Total = Cajas x U/E + Saldos) -->
        <div style="padding:20px;border-bottom:1px solid #e2e8f0;">
          <div style="font-size:12px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">
            <i class="fa-solid fa-boxes-stacked" style="color:#0F4C81;"></i> Detalle de Productos e Inventario
          </div>
          <div class="table-container">
            <table class="erp-table" style="font-size:12px;">
              <thead>
                <tr>
                  <th>Producto</th><th>Lote</th><th>Vence</th>
                  <th class="text-center">Cajas</th><th class="text-center">U/E</th><th class="text-center">Sueltos</th>
                  <th class="text-center" style="background:#e0f2fe;color:#0369a1;">Total Unidades Reales</th>
                  <th>Condición</th><th>Destino</th>
                </tr>
              </thead>
              <tbody>
                ${(d.detalles||[]).map(det => {
                  const ue = parseInt(det.producto?.unidades_caja || 1);
                  const cantTotal = parseFloat(det.cantidad || 0);
                  const cajas = det.cantidad_cajas != null ? parseFloat(det.cantidad_cajas) : Math.floor(cantTotal / ue);
                  const saldos = det.cantidad_saldo != null ? parseFloat(det.cantidad_saldo) : (cantTotal % ue);
                  const calcTotal = (cajas * ue) + saldos;
                  return `
                  <tr>
                    <td><strong>${WMS.esc(det.producto?.nombre||det.producto_id)}</strong> <br><span style="font-size:10px;color:#94a3b8;">[${WMS.esc(det.producto?.codigo_interno||'')}]</span></td>
                    <td><code>${WMS.esc(det.lote||'-')}</code></td>
                    <td style="font-size:11px;">${det.fecha_vencimiento ? WMS.formatDate(det.fecha_vencimiento) : '-'}</td>
                    <td class="text-center">${WMS.formatNum(cajas)}</td>
                    <td class="text-center" style="color:#64748b;">${ue}</td>
                    <td class="text-center">${WMS.formatNum(saldos)}</td>
                    <td class="text-center fw-700" style="background:#f0f9ff;color:#0369a1;">${WMS.formatNum(calcTotal)} und</td>
                    <td><span class="badge" style="background:#f1f5f9;color:#475569;">${WMS.esc(det.condicion||'-')}</span></td>
                    <td>${det.destino ? `<span class="badge" style="background:#f0fdf4;color:#16a34a;">${WMS.esc(det.destino)}</span>` : '<span style="color:#94a3b8;">—</span>'}</td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>

        <!-- Galería Fotográfica -->
        <div style="padding:20px;">
          <div style="font-size:12px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;">
            <span><i class="fa-solid fa-camera" style="color:#8b5cf6;"></i> Registro Fotográfico de Evidencia</span>
          </div>
          ${fotosList.length ? `
            <div style="display:flex;flex-wrap:wrap;gap:14px;">
              ${fotosList.map(f => `
                <a href="${WMS.esc(f)}" target="_blank" style="display:block;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;box-shadow:0 3px 8px rgba(0,0,0,0.1);transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
                  <img src="${WMS.esc(f)}" style="width:140px;height:140px;object-fit:cover;display:block;" alt="Evidencia">
                </a>`).join('')}
            </div>
          ` : '<p style="font-size:12px;color:#94a3b8;font-style:italic;">No hay registro fotográfico cargado para esta devolución.</p>'}
        </div>
      </div>
    `;
  },

  // ── Informe Fotográfico de Devoluciones (Calidad) ───────────────────────
  async show_devolucionesInforme() {
    WMS.spinner();
    try {
      const hoy   = WMS.getToday();
      const desde = WMS.getPastDate(30);

      WMS.setContent(`
        <div class="op-view">
          <div class="card" style="padding:14px 18px;margin-bottom:16px;">
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">DESDE</label>
                <input type="date" id="df-ini" class="form-control form-control-sm" value="${desde}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">HASTA</label>
                <input type="date" id="df-fin" class="form-control form-control-sm" value="${hoy}"></div>
              <div><label style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:3px;">ESTADO</label>
                <select id="df-estado" class="form-control form-control-sm">
                  <option value="">Todos los estados</option>
                  <option value="PendienteAprobacion">Pendiente Aprobación</option>
                  <option value="Aprobada">Aprobada</option>
                  <option value="Procesada">Procesada</option>
                  <option value="Rechazada">Rechazada</option>
                </select></div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.calidad._cargarDashboardDevoluciones()"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
          </div>

          <div id="df-dashboard-container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));gap:16px;margin-bottom:16px;"></div>

          <div id="df-tabla-container" class="card">
            <div style="padding:20px;text-align:center;color:#64748b;">Cargando informe...</div>
          </div>
        </div>
      `);

      await this._cargarDashboardDevoluciones();
    } catch(e) {
      WMS.toast('error', 'Error cargando informe');
    } finally {
      WMS.spinnerHide();
    }
  },

  async _cargarDashboardDevoluciones() {
    const ini = document.getElementById('df-ini')?.value || WMS.getPastDate(30);
    const fin = document.getElementById('df-fin')?.value || WMS.getToday();
    const est = document.getElementById('df-estado')?.value || '';

    const dbContainer = document.getElementById('df-dashboard-container');
    const tblContainer = document.getElementById('df-tabla-container');
    if (!dbContainer || !tblContainer) return;

    try {
      const rList = await API.get(`/devoluciones?desde=${ini}&hasta=${fin}${est ? '&estado='+est : ''}`);
      const rows = rList.data || [];
      
      let stats = null;
      try {
        const rStats = await API.get(`/devoluciones/dashboard-stats`);
        stats = rStats.data;
      } catch(e) { console.error('Error stats:', e); }

      if (stats && stats.causales) {
        dbContainer.innerHTML = `
          <div class="card" style="padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-size:12px;font-weight:700;color:#1e3a5f;margin-bottom:12px;text-transform:uppercase;"><i class="fa-solid fa-chart-pie"></i> Devoluciones por Causal</div>
            <div style="position:relative;height:200px;"><canvas id="chartDevCausales"></canvas></div>
          </div>
          <div class="card" style="padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
             <div style="font-size:12px;font-weight:700;color:#1e3a5f;margin-bottom:12px;text-transform:uppercase;"><i class="fa-solid fa-ranking-star"></i> Top 5 Productos Devueltos</div>
             <table class="erp-table" style="font-size:11px;">
               <thead><tr><th>Cód</th><th>Producto</th><th class="text-right">Cant</th></tr></thead>
               <tbody>
                 ${(stats.top_productos||[]).slice(0,5).map(p => `<tr><td>${WMS.esc(p.codigo_interno)}</td><td>${WMS.esc(p.nombre)}</td><td class="text-right" style="font-weight:700;color:#b91c1c;">${p.cantidad_total}</td></tr>`).join('')}
               </tbody>
             </table>
          </div>
        `;

        if (window.Chart) {
          const lbls = stats.causales.map(c => c.causal);
          const data = stats.causales.map(c => c.total);
          new Chart(document.getElementById('chartDevCausales'), {
            type: 'doughnut',
            data: { labels: lbls, datasets: [{ data, backgroundColor: ['#0F4C81','#ef4444','#f59e0b','#10b981','#8b5cf6','#64748b','#ec4899','#14b8a6'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 10 } } } } }
          });
        }
      } else {
        dbContainer.innerHTML = '';
      }

      if (!rows.length) {
        tblContainer.innerHTML = '<p style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">Sin registros de devoluciones en el rango seleccionado.</p>';
        return;
      }

      tblContainer.innerHTML = `
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span class="card-title"><i class="fa-solid fa-list-check"></i> Historial de Devoluciones (${rows.length})</span>
        </div>
        <div class="table-container">
          <table class="erp-table" style="font-size:12px;">
            <thead>
              <tr>
                <th>Consecutivo</th>
                <th>N° Interno</th>
                <th>Fecha</th>
                <th>Sucursal Origen</th>
                <th>Causal</th>
                <th>Responsable</th>
                <th class="text-center">Ítems</th>
                <th class="text-center">Fotos</th>
                <th>Estado</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              ${rows.map(d => {
                const fotosList = Array.isArray(d.fotos_json) ? d.fotos_json : (typeof d.fotos_json === 'string' ? JSON.parse(d.fotos_json || '[]') : []);
                const cons = d.consecutivo_devolucion ? `#${d.consecutivo_devolucion}` : '-';
                return `
                <tr>
                  <td><span class="badge" style="background:#0F4C81;color:#fff;font-size:12px;font-weight:700;">${WMS.esc(cons)}</span></td>
                  <td><strong>${WMS.esc(d.numero_devolucion)}</strong></td>
                  <td>${d.created_at ? d.created_at.substring(0,10) : '-'}</td>
                  <td>${WMS.esc(d.sucursal_origen?.nombre || '-')}</td>
                  <td>${WMS.esc(d.causal?.causal || d.causal_nombre || d.motivo_general || '-')}</td>
                  <td>${WMS.esc(d.responsable_devolucion || '-')}</td>
                  <td class="text-center">${(d.detalles||[]).length}</td>
                  <td class="text-center">
                    ${fotosList.length > 0
                      ? `<span class="badge" style="background:#fdf4ff;color:#c026d3;border:1px solid #f5d0fe;"><i class="fa-solid fa-camera"></i> ${fotosList.length}</span>`
                      : '<span style="color:#94a3b8;">0</span>'}
                  </td>
                  <td><span class="badge" style="background:#f1f5f9;color:#475569;">${WMS.esc(d.estado)}</span></td>
                  <td>
                    <button class="btn btn-sm btn-primary" onclick="WMS_MODULES.calidad._verDetalleModal(${d.id})">
                      <i class="fa-solid fa-magnifying-glass"></i> Ver Detalle
                    </button>
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      `;
    } catch(e) {
      tblContainer.innerHTML = '<p style="padding:20px;color:#dc2626;">Error cargando registros fotográficos.</p>';
    }
  },

  async _verDetalleModal(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/' + id);
      const d = r.data;
      const fotosList = Array.isArray(d.fotos_json) ? d.fotos_json : (typeof d.fotos_json === 'string' ? JSON.parse(d.fotos_json || '[]') : []);
      const consecTxt = d.consecutivo_devolucion ? `#${d.consecutivo_devolucion}` : d.numero_devolucion;

      const itemsHtml = (d.detalles || []).map(dt => `
        <tr>
           <td>${WMS.esc(dt.producto?.codigo_interno || '-')}</td>
           <td>${WMS.esc(dt.producto?.nombre || '-')}</td>
           <td class="text-right" style="font-weight:700;">${dt.cantidad}</td>
        </tr>
      `).join('');

      const html = `
        <div style="margin-bottom:16px;">
          <div style="font-size:13px;color:#64748b;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <div><strong>Devolución ${WMS.esc(consecTxt)}</strong> &mdash; Suc. Origen: <strong>${WMS.esc(d.sucursal_origen?.nombre || 'Misma sucursal')}</strong></div>
            <div><span class="badge" style="background:#1e3a5f;color:#fff;">${WMS.esc(d.estado)}</span></div>
          </div>
          
          <div style="font-size:11px;font-weight:700;color:#1e3a5f;text-transform:uppercase;margin-bottom:8px;"><i class="fa-solid fa-boxes-stacked"></i> Productos Devueltos</div>
          <table class="erp-table" style="font-size:11px;margin-bottom:16px;">
            <thead><tr><th>Código</th><th>Producto</th><th class="text-right">Cant</th></tr></thead>
            <tbody>${itemsHtml || '<tr><td colspan="3" class="text-center">No hay productos</td></tr>'}</tbody>
          </table>

          <div style="font-size:11px;font-weight:700;color:#1e3a5f;text-transform:uppercase;margin-bottom:8px;"><i class="fa-solid fa-camera"></i> Registro Fotográfico</div>
          ${fotosList.length ? `
            <div style="display:flex;flex-wrap:wrap;gap:12px;max-height:40vh;overflow-y:auto;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
              ${fotosList.map(f => `
                <a href="${WMS.esc(f)}" target="_blank" style="display:block;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                  <img src="${WMS.esc(f)}" style="width:140px;height:140px;object-fit:cover;display:block;">
                </a>`).join('')}
            </div>
          ` : '<p style="color:#94a3b8;font-style:italic;">No hay registro fotográfico cargado para esta devolución.</p>'}
        </div>
      `;
      WMS.showModal(`Detalle Devolución &mdash; ${WMS.esc(consecTxt)}`, html, '', 'lg');
    } catch(e) {
      WMS.toast('error', 'Error al cargar fotos');
    } finally {
      WMS.spinnerHide();
    }
  },
};
