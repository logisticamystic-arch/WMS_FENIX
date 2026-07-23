/* ============================================================
   WMS Desktop - Módulo MAESTRO  (rev 2)
   Fixes: load() OR-bug, stubs implementados, EAN field, ubicaciones
   zona+tipo_ubicacion+capacidad_maxima, proveedores razon_social+
   contacto_nombre, botones editar en todas las tablas,
   permisos por usuario con modal individual.
   ============================================================ */
WMS_MODULES.maestro = {
  _sub: null,

  load(sub) {
    this._sub = sub;
    WMS.renderSidebar('maestro');
    
    if (!sub) {
      this.show_landing();
      return;
    }

    WMS.setBreadcrumb('maestro', this.subLabel(sub));

    // Lógica de carga específica para productos (Catalog-First)
    if (sub === 'productos') {
      this.consultar_productos();
    } else {
      const key = sub ? 'show_' + sub.replace(/-/g, '_') : null;
      const fn  = key ? this[key] : null;
      if (typeof fn === 'function') fn.call(this);
      else this.show_empresa();
    }
  },

  show_landing() {
    WMS.setBreadcrumb('maestro');
    WMS.setToolbar('');
    const cards = [
      { id: 'empresa', icon: 'fa-building', title: 'Empresa', desc: 'Configuración general de la organización y datos fiscales.' },
      { id: 'sucursales', icon: 'fa-store', title: 'Sucursales', desc: 'Gestión de puntos de venta, bodegas y centros de distribución.' },
      { id: 'personal', icon: 'fa-users', title: 'Personal', desc: 'Administración de usuarios, auxiliares y roles operativos.' },
      { id: 'productos', icon: 'fa-box', title: 'Productos', desc: 'Catálogo maestro, gestión de EANs, categorías y marcas.' },
      { id: 'clientes', icon: 'fa-user-tie', title: 'Clientes', desc: 'Base de datos de clientes, carteras y puntos de entrega.' },
      { id: 'ambientes', icon: 'fa-temperature-half', title: 'Ambientes', desc: 'Clasificación de temperatura: Seco, Refrigerado, Congelado.' },
      { id: 'ubicaciones', icon: 'fa-map-pin', title: 'Ubicaciones', desc: 'Maestro de posiciones en bodega, zonas y capacidad.' },
      { id: 'proveedores', icon: 'fa-truck', title: 'Proveedores', desc: 'Gestión de proveedores, contactos y tiempos de entrega.' },
      { id: 'causales-novedad', icon: 'fa-list-check', title: 'Causales de Novedad', desc: 'Causas de agotados en picking: área responsable y si afectan el nivel de servicio.' },
      { id: 'impresoras', icon: 'fa-print', title: 'Impresoras', desc: 'Configuración de impresoras IP para rótulos y documentos.' },
      { id: 'permisos', icon: 'fa-shield-halved', title: 'Seguridad', desc: 'Matriz de permisos, acceso por roles y auditoría.' },
    ];

    // Tarjeta de Diagnóstico — solo visible para Admin
    const user = WMS.getUser ? WMS.getUser() : (window._wmsUser || {});
    const isAdmin = (user.rol || user.role || '') === 'Admin';
    if (isAdmin) {
      cards.push({ id: 'sistema', icon: 'fa-stethoscope', title: 'Diagnóstico del Sistema', desc: 'Validación de controladores, rutas, integridad de archivos y estado del servidor.', _admin: true });
    }

    WMS.setContent(`
      <div style="padding:24px; max-width:1200px; margin:0 auto;">
         <div style="margin-bottom:24px;">
            <h2 style="font-weight:800; color:#1e293b; margin-bottom:4px;">Panel de Maestros</h2>
            <p style="color:#64748b; font-size:.85rem;">Seleccione una opción para gestionar la configuración base del sistema.</p>
         </div>
         <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
            ${cards.map(c => c._admin ? `
               <div class="landing-card" onclick="WMS.nav('maestro','${c.id}')"
                    style="background:linear-gradient(135deg,#0f172a,#1e3a5f); border:1px solid #334155; border-radius:4px; padding:20px; cursor:pointer; transition:all .2s ease; display:flex; flex-direction:column; gap:12px; box-shadow:0 4px 12px rgba(0,0,0,0.25);">
                  <div style="width:48px; height:48px; border-radius:12px; background:rgba(99,102,241,0.25); color:#818cf8; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                     <i class="fa-solid ${c.icon}"></i>
                  </div>
                  <div>
                     <h4 style="font-weight:800; color:#f1f5f9; margin-bottom:4px;">${c.title}</h4>
                     <p style="color:#94a3b8; font-size:.78rem; line-height:1.5;">${c.desc}</p>
                  </div>
                  <div style="margin-top:auto; padding-top:12px; display:flex; align-items:center; color:#818cf8; font-size:.75rem; font-weight:700; gap:6px;">
                     <i class="fa-solid fa-lock" style="font-size:.6rem;"></i> Solo Admin &nbsp;·&nbsp; Ejecutar diagnóstico <i class="fa-solid fa-arrow-right" style="font-size:.65rem;"></i>
                  </div>
               </div>
            ` : `
               <div class="landing-card" onclick="WMS.nav('maestro','${c.id}')" style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:20px; cursor:pointer; transition:all .2s ease; display:flex; flex-direction:column; gap:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                  <div style="width:48px; height:48px; border-radius:12px; background:var(--primary-soft); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                     <i class="fa-solid ${c.icon}"></i>
                  </div>
                  <div>
                     <h4 style="font-weight:800; color:#1e293b; margin-bottom:4px;">${c.title}</h4>
                     <p style="color:#64748b; font-size:.78rem; line-height:1.5;">${c.desc}</p>
                  </div>
                  <div style="margin-top:auto; padding-top:12px; display:flex; align-items:center; color:var(--primary); font-size:.75rem; font-weight:700; gap:6px;">
                     Gestionar <i class="fa-solid fa-arrow-right" style="font-size:.65rem;"></i>
                  </div>
               </div>
            `).join('')}
         </div>
      </div>
    `);
  },

  subLabel(s) {
    const m = {
      empresa: 'Empresa', sucursales: 'Sucursales', personal: 'Personal',
      categorias: 'Categorías', marcas: 'Marcas', productos: 'Catálogo de Productos',
      clientes: 'Clientes', ambientes: 'Ambientes', ubicaciones: 'Ubicaciones',
      proveedores: 'Proveedores', 'causales-novedad': 'Causales de Novedad', rutas: 'Rutas', permisos: 'Seguridad',
      impresoras: 'Impresoras IP',
      sistema: 'Diagnóstico del Sistema'
    };
    return m[s] || s || 'Panel';
  },

  // ── EMPRESA ──────────────────────────────────────────────────
  filtrarEmpresas(q) {
    if (!this._empresasData) return;
    const f = q.toLowerCase();
    this.renderEmpresas(f
      ? this._empresasData.filter(c => c.razon_social?.toLowerCase().includes(f) || c.nit?.includes(f))
      : this._empresasData);
  },

  async show_empresa() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-emp" placeholder="Buscar por NIT o Razón Social..." oninput="WMS_MODULES.maestro.filtrarEmpresas(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_empresa()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaEmpresa()"><i class="fa-solid fa-plus"></i> Nueva Empresa</button>
      </div>`);
    WMS.spinner();
    try {
      const r     = await API.get('/param/empresas');
      this._empresasData = r.data || r || [];
      this.renderEmpresas(this._empresasData);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  renderEmpresas(items) {
    WMS.setContent(`
        <div class="card">
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>NIT</th><th>Razón Social</th><th>Teléfono</th><th>Email</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody id="empresa-tbody">${items.length === 0
                ? '<tr><td colspan="6" class="table-empty" style="text-align:center; padding:30px;">Sin registros</td></tr>'
                : items.map(e => `<tr class="main-row" id="row-emp-${e.id}" onclick="WMS_MODULES.maestro.editEmpresa(${e.id})">
                    <td><span class="badge badge-info" style="border-radius:4px; font-family:monospace;">${WMS.esc(e.nit || '')}</span></td>
                    <td style="font-weight:600; color:#1e293b;">${WMS.esc(e.razon_social || '')}</td>
                    <td style="color:#64748b;">${WMS.esc(e.telefono || '-')}</td>
                    <td style="color:#64748b;">${WMS.esc(e.email || '-')}</td>
                    <td><span class="status-chip ${e.activo ? 'status-cerrada' : 'status-cancelada'}" style="border-radius:4px;">${e.activo ? 'Activa' : 'Inactiva'}</span></td>
                    <td onclick="event.stopPropagation()"><div class="actions">
                      ${(typeof _wmsUser !== 'undefined' && _wmsUser?.rol === 'SuperAdmin') ? `<button class="btn btn-sm btn-danger" style="border-radius:4px;" onclick="WMS_MODULES.maestro.deleteEmpresa(${e.id},'${WMS.esc(e.razon_social || '')}')"><i class="fa-solid fa-trash"></i></button>` : ''}
                    </div></td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
          
          <!-- Side Panel / Drawer -->
          <div id="empresa-drawer" class="md-drawer">
            <div class="drawer-header">
              <h3 class="drawer-title"><i class="fa-solid fa-building" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-empresa-title">Empresa</span></h3>
              <button class="drawer-close" onclick="WMS_MODULES.maestro.closeDrawerEmpresa()"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="drawer-body" id="drawer-empresa-content"></div>
            <div class="drawer-footer">
              <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.maestro.closeDrawerEmpresa()">Cancelar</button>
              <button id="btn-save-empresa" class="btn btn-primary" style="border-radius:4px;"><i class="fa-solid fa-save"></i> Guardar</button>
            </div>
          </div>
        </div>`);
  },
  
  closeDrawerEmpresa() {
    const drawer = document.getElementById('empresa-drawer');
    if (drawer) drawer.classList.remove('open');
    document.querySelectorAll('#empresa-tbody tr').forEach(r => r.style.background = '');
  },

  nuevaEmpresa() {
    this.closeDrawerEmpresa();
    const drawer = document.getElementById('empresa-drawer');
    const content = document.getElementById('drawer-empresa-content');
    const title = document.getElementById('drawer-empresa-title');
    const btnSave = document.getElementById('btn-save-empresa');
    if (!drawer) return;
    
    title.textContent = 'Nueva Empresa';
    content.innerHTML = `
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div class="form-group"><label class="form-label">NIT <span class="required" style="color:#ef4444;">*</span></label><input id="f-nit" class="form-control" placeholder="Ej: 900000001-1"></div>
        <div class="form-group"><label class="form-label">RAZÓN SOCIAL <span class="required" style="color:#ef4444;">*</span></label><input id="f-rs" class="form-control" placeholder="Nombre de la empresa"></div>
        <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-dir" class="form-control" placeholder="Dirección"></div>
        <div class="form-group"><label class="form-label">TELÉFONO</label><input id="f-tel" class="form-control" placeholder="Teléfono"></div>
        <div class="form-group"><label class="form-label">EMAIL</label><input id="f-email" class="form-control" type="email" placeholder="contacto@empresa.com"></div>
        <div class="form-group" style="display:none;"><input type="checkbox" id="f-eact" checked></div>
      </div>
    `;
    btnSave.onclick = () => WMS_MODULES.maestro.saveEmpresa(null);
    drawer.classList.add('open');
  },

  async saveEmpresa(id) {
    const data = {
      nit:          document.getElementById('f-nit')?.value.trim(),
      razon_social: document.getElementById('f-rs')?.value.trim(),
      direccion:    document.getElementById('f-dir')?.value.trim(),
      telefono:     document.getElementById('f-tel')?.value.trim(),
      email:        document.getElementById('f-email')?.value.trim(),
      activo:       document.getElementById('f-eact') ? (document.getElementById('f-eact').checked ? 1 : 0) : 1
    };
    if (!data.nit || !data.razon_social) { WMS.toast('warning', 'NIT y Razón Social son obligatorios'); return; }
    try {
      const r = id ? await API.put('/param/empresas/' + id, data) : await API.post('/param/empresas', data);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', id ? 'Empresa actualizada' : 'Empresa creada'); this.closeDrawerEmpresa(); this.show_empresa(); }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async editEmpresa(id) {
    this.closeDrawerEmpresa();
    const row = document.getElementById(`row-emp-${id}`);
    if (row) row.style.background = '#e0f2fe';
    
    const drawer = document.getElementById('empresa-drawer');
    const content = document.getElementById('drawer-empresa-content');
    const title = document.getElementById('drawer-empresa-title');
    const btnSave = document.getElementById('btn-save-empresa');
    if (!drawer) return;

    try {
      const r     = await API.get('/param/empresas');
      const items = r.data || r || [];
      const e     = items.find(x => x.id == id);
      if (!e) return;
      
      title.textContent = 'Editar Empresa';
      content.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div class="form-group"><label class="form-label">NIT <span class="required" style="color:#ef4444;">*</span></label><input id="f-nit" class="form-control" value="${WMS.esc(e.nit || '')}"></div>
          <div class="form-group"><label class="form-label">RAZÓN SOCIAL <span class="required" style="color:#ef4444;">*</span></label><input id="f-rs" class="form-control" value="${WMS.esc(e.razon_social || '')}"></div>
          <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-dir" class="form-control" value="${WMS.esc(e.direccion || '')}"></div>
          <div class="form-group"><label class="form-label">TELÉFONO</label><input id="f-tel" class="form-control" value="${WMS.esc(e.telefono || '')}"></div>
          <div class="form-group"><label class="form-label">EMAIL</label><input id="f-email" class="form-control" value="${WMS.esc(e.email || '')}"></div>
          <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
             <div>
               <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado Activo</span>
               <span style="font-size:0.8rem; color:#64748b;">Si está inactiva, sus sucursales y personal no operarán.</span>
             </div>
             <label class="wms-switch"><input type="checkbox" id="f-eact" ${e.activo ? 'checked' : ''}><span class="slider"></span></label>
          </div>
        </div>
      `;
      btnSave.onclick = () => WMS_MODULES.maestro.saveEmpresa(id);
      drawer.classList.add('open');
    } catch (ex) { WMS.toast('error', 'Error cargando datos'); }
  },

  deleteEmpresa(id, nombre) {
    WMS.confirm('Eliminar Empresa', `¿Está seguro de eliminar la empresa "<strong>${WMS.esc(nombre)}</strong>"? Esta acción no se puede deshacer.`, async () => {
      const r = await API.delete('/param/empresas/' + id);
      if (r.error) WMS.toast('error', r.message); else { WMS.toast('success', 'Empresa eliminada'); this.show_empresa(); }
    });
  },

  // ── SUCURSALES ───────────────────────────────────────────────
  filtrarSucursales(q) {
    if (!this._sucursalesData) return;
    const f = q.toLowerCase();
    this.renderSucursales(f
      ? this._sucursalesData.filter(s => s.nombre?.toLowerCase().includes(f) || s.codigo?.toLowerCase().includes(f))
      : this._sucursalesData);
  },

  async show_sucursales() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-suc" placeholder="Buscar por Código o Nombre..." oninput="WMS_MODULES.maestro.filtrarSucursales(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_sucursales()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaSucursal()"><i class="fa-solid fa-plus"></i> Nueva Sucursal</button>
      </div>`);
    WMS.spinner();
    const [rs, es] = await Promise.all([API.get('/param/sucursales'), API.get('/param/empresas')]);
    this._sucursalesData = rs.data || rs || [];
    this._sucEmpresasData = es.data || es || [];
    this.renderSucursales(this._sucursalesData);
  },

  renderSucursales(items) {
    const empresas = this._sucEmpresasData || [];
    WMS.setContent(`
      <div class="card">
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Código</th><th>Nombre</th><th>Empresa</th><th>Ciudad</th><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody id="sucursales-tbody">${items.length === 0
              ? '<tr><td colspan="6" class="table-empty" style="text-align:center; padding:30px;">Sin sucursales</td></tr>'
              : items.map(s => `<tr class="main-row" id="row-suc-${s.id}" onclick="WMS_MODULES.maestro.editSucursal(${s.id})">
                <td><span class="badge badge-gray" style="border-radius:4px; font-family:monospace;">${WMS.esc(s.codigo || '-')}</span></td>
                <td style="font-weight:600; color:#1e293b;">${WMS.esc(s.nombre || '')}</td>
                <td style="color:#64748b;">${WMS.esc(empresas.find(e => e.id == s.empresa_id)?.razon_social || s.empresa_id || '-')}</td>
                <td style="color:#64748b;">${WMS.esc(s.ciudad || '-')}</td>
                <td><span class="status-chip ${s.activo ? 'status-cerrada' : 'status-cancelada'}" style="border-radius:4px;">${s.activo ? 'Activa' : 'Inactiva'}</span></td>
                <td onclick="event.stopPropagation()"><div class="actions">
                  <button class="btn btn-sm ${s.activo ? 'btn-warning' : 'btn-success'}" style="border-radius:4px;" onclick="WMS_MODULES.maestro.toggleEstadoSucursal(${s.id}, ${s.activo ? 0 : 1})" title="${s.activo ? 'Desactivar' : 'Activar'}">
                    <i class="fa-solid ${s.activo ? 'fa-ban' : 'fa-check'}"></i>
                  </button>
                  <button class="btn btn-sm btn-danger" style="border-radius:4px;" onclick="WMS_MODULES.maestro.deleteSucursal(${s.id},'${WMS.esc(s.nombre || '')}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </div></td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
        
        <!-- Side Panel / Drawer -->
        <div id="sucursal-drawer" class="md-drawer">
          <div class="drawer-header">
            <h3 class="drawer-title"><i class="fa-solid fa-store" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-sucursal-title">Sucursal</span></h3>
            <button class="drawer-close" onclick="WMS_MODULES.maestro.closeDrawerSucursal()"><i class="fa-solid fa-times"></i></button>
          </div>
          <div class="drawer-body" id="drawer-sucursal-content"></div>
          <div class="drawer-footer">
            <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.maestro.closeDrawerSucursal()">Cancelar</button>
            <button id="btn-save-sucursal" class="btn btn-primary" style="border-radius:4px;"><i class="fa-solid fa-save"></i> Guardar</button>
          </div>
        </div>
      </div>`);
  },

  closeDrawerSucursal() {
    const drawer = document.getElementById('sucursal-drawer');
    if (drawer) drawer.classList.remove('open');
    document.querySelectorAll('#sucursales-tbody tr').forEach(r => r.style.background = '');
  },

  async toggleEstadoSucursal(id, nuevoEstado) {
    try {
      const r = await API.put('/param/sucursales/' + id, { activo: nuevoEstado });
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Estado actualizado'); this.show_sucursales(); }
    } catch (e) { WMS.toast('error', 'Error al actualizar estado'); }
  },

  async nuevaSucursal() {
    this.closeDrawerSucursal();
    const es      = await API.get('/param/empresas');
    const empresas = es.data || es || [];
    
    const drawer = document.getElementById('sucursal-drawer');
    const content = document.getElementById('drawer-sucursal-content');
    const title = document.getElementById('drawer-sucursal-title');
    const btnSave = document.getElementById('btn-save-sucursal');
    if (!drawer) return;

    title.textContent = 'Nueva Sucursal';
    content.innerHTML = `
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div class="form-group"><label class="form-label">CÓDIGO <span class="required" style="color:#ef4444;">*</span></label><input id="f-scod" class="form-control" placeholder="Ej: BOG01"></div>
        <div class="form-group"><label class="form-label">NOMBRE <span class="required" style="color:#ef4444;">*</span></label><input id="f-snom" class="form-control" placeholder="Nombre de la bodega"></div>
        <div class="form-group"><label class="form-label">EMPRESA <span class="required" style="color:#ef4444;">*</span></label>
          <select id="f-semp" class="form-control"><option value="">-- Seleccione --</option>
            ${empresas.map(e => `<option value="${e.id}">${WMS.esc(e.razon_social)}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">CIUDAD</label><input id="f-scity" class="form-control" placeholder="Ciudad"></div>
        <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-sdir" class="form-control" placeholder="Dirección física"></div>
        <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
           <div>
             <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado Activo</span>
             <span style="font-size:0.8rem; color:#64748b;">Si está inactiva, no se podrá operar.</span>
           </div>
           <label class="wms-switch"><input type="checkbox" id="f-sact" checked><span class="slider"></span></label>
        </div>
      </div>
    `;
    btnSave.onclick = () => WMS_MODULES.maestro.saveSucursal(null);
    drawer.classList.add('open');
  },

  async saveSucursal(id) {
    const data = {
      codigo:     document.getElementById('f-scod')?.value.trim(),
      nombre:     document.getElementById('f-snom')?.value.trim(),
      empresa_id: document.getElementById('f-semp')?.value,
      ciudad:     document.getElementById('f-scity')?.value.trim(),
      direccion:  document.getElementById('f-sdir')?.value.trim(),
      activo:     document.getElementById('f-sact')?.checked ? 1 : 0
    };
    if (!data.codigo || !data.nombre || !data.empresa_id) { WMS.toast('warning', 'Código, Nombre y Empresa son obligatorios'); return; }
    try {
      const r = id ? await API.put('/param/sucursales/' + id, data) : await API.post('/param/sucursales', data);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', id ? 'Sucursal actualizada' : 'Sucursal creada'); this.closeDrawerSucursal(); this.show_sucursales(); }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  // FIX: editSucursal era un stub; ahora carga datos y pre-llena el modal
  async editSucursal(id) {
    this.closeDrawerSucursal();
    const row = document.getElementById(`row-suc-${id}`);
    if (row) row.style.background = '#e0f2fe';
    
    const drawer = document.getElementById('sucursal-drawer');
    const content = document.getElementById('drawer-sucursal-content');
    const title = document.getElementById('drawer-sucursal-title');
    const btnSave = document.getElementById('btn-save-sucursal');
    if (!drawer) return;

    try {
      const [rs, es] = await Promise.all([API.get('/param/sucursales'), API.get('/param/empresas')]);
      const items    = rs.data || rs || [];
      const empresas = es.data || es || [];
      const s        = items.find(x => x.id == id);
      if (!s) { WMS.toast('error', 'Sucursal no encontrada'); return; }
      
      title.textContent = 'Editar Sucursal';
      content.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div class="form-group"><label class="form-label">CÓDIGO <span class="required" style="color:#ef4444;">*</span></label><input id="f-scod" class="form-control" value="${WMS.esc(s.codigo || '')}"></div>
          <div class="form-group"><label class="form-label">NOMBRE <span class="required" style="color:#ef4444;">*</span></label><input id="f-snom" class="form-control" value="${WMS.esc(s.nombre || '')}"></div>
          <div class="form-group"><label class="form-label">EMPRESA <span class="required" style="color:#ef4444;">*</span></label>
            <select id="f-semp" class="form-control">
              ${empresas.map(e => `<option value="${e.id}" ${e.id == s.empresa_id ? 'selected' : ''}>${WMS.esc(e.razon_social)}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">CIUDAD</label><input id="f-scity" class="form-control" value="${WMS.esc(s.ciudad || '')}"></div>
          <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-sdir" class="form-control" value="${WMS.esc(s.direccion || '')}"></div>
          <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
             <div>
               <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado Activo</span>
               <span style="font-size:0.8rem; color:#64748b;">Si está inactiva, no se podrá operar.</span>
             </div>
             <label class="wms-switch"><input type="checkbox" id="f-sact" ${s.activo ? 'checked' : ''}><span class="slider"></span></label>
          </div>
        </div>
      `;
      btnSave.onclick = () => WMS_MODULES.maestro.saveSucursal(id);
      drawer.classList.add('open');
    } catch (ex) { WMS.toast('error', 'Error cargando sucursal'); }
  },

  deleteSucursal(id, n) {
    WMS.confirm('Eliminar Sucursal', `¿Eliminar "<strong>${WMS.esc(n)}</strong>"?`, async () => {
      const r = await API.delete('/param/sucursales/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Sucursal eliminada'); this.show_sucursales(); }
    });
  },

  // ── PERSONAL ─────────────────────────────────────────────────
  async show_personal() {
    WMS.setToolbar(`
      <div style="display:flex;align-items:center;gap:12px;width:100%;">
        <div style="position:relative; flex:1; max-width: 400px;">
          <i class="fa-solid fa-search" style="position:absolute; left:10px; top:10px; color:#94a3b8;"></i>
          <input id="search-personal" class="form-control" style="padding-left:32px; border-radius:4px;" placeholder="Buscar personal..." oninput="WMS_MODULES.maestro.filtrarPersonal(this.value)">
        </div>
        <button class="btn btn-primary btn-sm" style="border-radius:4px;" onclick="WMS_MODULES.maestro.nuevoPersonal()"><i class="fa-solid fa-plus"></i> Nuevo Personal</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/personal');
      this._personalData = r.data || r || [];

      WMS.setContent(`
        <div class="card">
          <!-- Master View -->
          <div class="table-container">
            <table class="erp-table">
              <thead>
                <tr>
                  <th>Documento</th>
                  <th>Nombre</th>
                  <th>Rol</th>
                  <th>Sucursal</th>
                  <th>Último Login</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody id="personal-tbody">
                <!-- Contenido generado dinamicamente -->
              </tbody>
            </table>
          </div>

          <!-- Side Panel / Drawer View -->
          <div id="personal-drawer" class="md-drawer">
            <div class="drawer-header">
              <h3 class="drawer-title"><i class="fa-solid fa-user" style="color:#3b82f6; margin-right:8px;"></i> <span id="drawer-personal-title">Personal</span></h3>
              <button class="drawer-close" onclick="WMS_MODULES.maestro.closeDrawerPersonal()"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="drawer-body" id="drawer-personal-content">
              <!-- Formulario inyectado -->
            </div>
            <div class="drawer-footer" id="drawer-personal-actions">
               <button class="btn btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.maestro.closeDrawerPersonal()">Limpiar</button>
               <button class="btn btn-primary" style="border-radius:4px;" id="btn-save-personal"><i class="fa-solid fa-save"></i> Guardar Personal</button>
            </div>
          </div>
        </div>
      `);
      this.renderPersonal(this._personalData);
    } catch (e) { WMS.setContent('<div class="m-empty"><i class="fa-solid fa-wifi"></i><p>Error de conexión</p></div>'); }
  },

  renderPersonal(items) {
    const rolColors = { Admin: 'badge-danger', Supervisor: 'badge-warning', Auxiliar: 'badge-info', Montacarguista: 'badge-success', Analista: 'badge-purple' };
    const tbody = document.getElementById('personal-tbody');
    if (!tbody) return; // Puede que el HTML no se haya cargado todavía

    tbody.innerHTML = items.map(p => `
      <tr class="main-row" id="row-pers-${p.id}" onclick="WMS_MODULES.maestro.editPersonal(${p.id})">
        <td><span style="font-family:monospace; color:#475569; font-weight:500;">${WMS.esc(p.documento || '')}</span></td>
        <td style="font-weight:600; color:#1e293b;">${WMS.esc(p.nombre || '')}</td>
        <td><span class="badge ${rolColors[p.rol] || 'badge-gray'}" style="border-radius:4px;">${WMS.esc(p.rol || '')}</span></td>
        <td style="color:#64748b;">${WMS.esc(p.sucursal?.nombre || p.sucursal_id || '-')}</td>
        <td class="text-sm" style="color:#64748b;">${WMS.formatDateTime(p.ultimo_login) || '-'}</td>
        <td><span class="status-chip ${p.activo ? 'status-cerrada' : 'status-cancelada'}" style="border-radius:4px;">${p.activo ? 'Activo' : 'Inactivo'}</span></td>
        <td>
          <div class="actions" onclick="event.stopPropagation()">
            <button class="btn btn-sm btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.maestro.gestionarPermisos(${p.id},'${WMS.esc(p.nombre || '')}')" title="Permisos individuales"><i class="fa-solid fa-shield-halved"></i></button>
            <button class="btn btn-sm btn-danger" style="border-radius:4px;" onclick="WMS_MODULES.maestro.deletePersonal(${p.id},'${WMS.esc(p.nombre || '')}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
          </div>
        </td>
      </tr>
    `).join('') || '<tr><td colspan="7" class="table-empty">Sin personal registrado con los filtros aplicados</td></tr>';
  },

  closeDrawerPersonal() {
    const drawer = document.getElementById('personal-drawer');
    if (drawer) drawer.classList.remove('open');
    document.querySelectorAll('#personal-tbody tr').forEach(r => r.style.background = '');
  },

  filtrarPersonal(q) {
    if (!this._personalData) return;
    const f = q.toLowerCase();
    this.renderPersonal(f
      ? this._personalData.filter(p => p.nombre?.toLowerCase().includes(f) || p.documento?.includes(f) || p.rol?.toLowerCase().includes(f))
      : this._personalData);
  },

  async nuevoPersonal() {
    this.closeDrawerPersonal();
    const ss  = await API.get('/param/sucursales?activo=1');
    const suc = ss.data || ss || [];
    
    const drawer = document.getElementById('personal-drawer');
    const title = document.getElementById('drawer-personal-title');
    const content = document.getElementById('drawer-personal-content');
    const btnSave = document.getElementById('btn-save-personal');
    
    if (!drawer) return;

    title.textContent = 'Nuevo Personal';
    content.innerHTML = `
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div style="background:#f1f5f9; padding:12px; border-radius:4px; margin-bottom:8px;">
           <span style="font-size:0.85rem; color:#475569;">Complete la información obligatoria marcada con (*).</span>
        </div>
        <div class="form-group"><label class="form-label">DOCUMENTO <span class="required" style="color:#ef4444;">*</span></label><input id="f-pdoc" class="form-control" placeholder="Ej: 1012345678"></div>
        <div class="form-group"><label class="form-label">NOMBRE COMPLETO <span class="required" style="color:#ef4444;">*</span></label><input id="f-pnom" class="form-control" placeholder="Nombres y apellidos"></div>
        <div class="form-group"><label class="form-label">ROL <span class="required" style="color:#ef4444;">*</span></label>
          <select id="f-prol" class="form-control">
            <option value="">-- Seleccione --</option>
            ${['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'].map(r => `<option value="${r}">${r}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">SUCURSAL</label>
          <select id="f-psuc" class="form-control"><option value="">-- Sin asignar --</option>
            ${suc.map(s => `<option value="${s.id}">${WMS.esc(s.nombre)}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">PIN DE ACCESO (4-6 DÍGITOS) <span class="required" style="color:#ef4444;">*</span></label><input id="f-ppin" class="form-control" type="password" maxlength="6" placeholder="••••"></div>
        <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
           <div>
             <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado del Usuario</span>
             <span style="font-size:0.8rem; color:#64748b;">Si está inactivo, no podrá ingresar al sistema.</span>
           </div>
           <label class="wms-switch"><input type="checkbox" id="f-pact" checked><span class="slider"></span></label>
        </div>
      </div>
    `;
    
    btnSave.onclick = () => WMS_MODULES.maestro.savePersonal(null);
    drawer.classList.add('open');
  },

  async savePersonal(id) {
    const data = {
      documento:   document.getElementById('f-pdoc')?.value.trim(),
      nombre:      document.getElementById('f-pnom')?.value.trim(),
      rol:         document.getElementById('f-prol')?.value,
      sucursal_id: document.getElementById('f-psuc')?.value || null,
      pin:         document.getElementById('f-ppin')?.value,
      activo:      document.getElementById('f-pact')?.checked ? 1 : 0
    };
    if (!data.documento || !data.nombre || !data.rol) { WMS.toast('warning', 'Documento, Nombre y Rol son obligatorios'); return; }
    
    // El PIN es obligatorio solo para nuevos usuarios
    if (!id && !data.pin) { WMS.toast('warning', 'El PIN de acceso es obligatorio para nuevos usuarios'); return; }

    const btn = document.getElementById('btn-save-personal');
    if (btn) { btn.disabled = true; btn.innerHTML = '<div class="spinner sm"></div> Guardando...'; }

    const r = id ? await API.put('/param/personal/' + id, data) : await API.post('/param/personal', data);
    
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Guardar Personal'; }
    
    if (r.error) WMS.toast('error', r.message);
    else { 
      WMS.toast('success', id ? 'Personal actualizado correctamente' : 'Personal creado correctamente'); 
      this.closeDrawerPersonal(); 
      this.show_personal(); 
    }
  },

  // FIX: editPersonal carga datos reales y abre Drawer pre-llenado
  async editPersonal(id) {
    this.closeDrawerPersonal();
    
    document.querySelectorAll('#personal-tbody tr').forEach(r => r.style.background = '');
    const row = document.getElementById(`row-pers-${id}`);
    if (row) row.style.background = '#e0f2fe';

    const drawer = document.getElementById('personal-drawer');
    const title = document.getElementById('drawer-personal-title');
    const content = document.getElementById('drawer-personal-content');
    const btnSave = document.getElementById('btn-save-personal');

    if (!drawer) return;

    title.textContent = 'Editar Personal';
    content.innerHTML = `<div style="padding:40px; text-align:center;"><div class="spinner"></div><p style="margin-top:10px; color:#64748b;">Cargando datos...</p></div>`;
    drawer.classList.add('open');

    try {
      const [pr, ss] = await Promise.all([
        API.get('/param/personal'), 
        API.get('/param/sucursales?activo=1')
      ]);
      const personal = pr.data || pr || [];
      const suc      = ss.data || ss || [];
      const p        = personal.find(x => x.id == id);
      
      if (p && p.sucursal && !suc.find(s => s.id == p.sucursal_id)) {
        suc.push(p.sucursal);
      }
      if (!p) { 
        content.innerHTML = `<div class="text-danger" style="padding:20px;">Usuario no encontrado.</div>`;
        return; 
      }
      
      content.innerHTML = `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div style="background:#f1f5f9; padding:12px; border-radius:4px; margin-bottom:8px;">
             <span style="font-size:0.85rem; color:#475569;">Modifique los datos logísticos del usuario.</span>
          </div>
          <div class="form-group"><label class="form-label">DOCUMENTO <span class="required" style="color:#ef4444;">*</span></label><input id="f-pdoc" class="form-control" value="${WMS.esc(p.documento || '')}"></div>
          <div class="form-group"><label class="form-label">NOMBRE COMPLETO <span class="required" style="color:#ef4444;">*</span></label><input id="f-pnom" class="form-control" value="${WMS.esc(p.nombre || '')}"></div>
          <div class="form-group"><label class="form-label">ROL <span class="required" style="color:#ef4444;">*</span></label>
            <select id="f-prol" class="form-control">
              ${['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'].map(r => `<option value="${r}" ${p.rol === r ? 'selected' : ''}>${r}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">SUCURSAL</label>
            <select id="f-psuc" class="form-control"><option value="">-- Sin asignar --</option>
              ${suc.map(s => `<option value="${s.id}" ${s.id == p.sucursal_id ? 'selected' : ''}>${WMS.esc(s.nombre)}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">NUEVO PIN (dejar vacío para no cambiar)</label><input id="f-ppin" class="form-control" type="password" maxlength="6" placeholder="Dejar vacío para mantener actual"></div>
          <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
             <div>
               <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado del Usuario</span>
               <span style="font-size:0.8rem; color:#64748b;">Si está inactivo, no podrá ingresar al sistema.</span>
             </div>
             <label class="wms-switch"><input type="checkbox" id="f-pact" ${p.activo ? 'checked' : ''}><span class="slider"></span></label>
          </div>
        </div>
      `;
      
      btnSave.onclick = () => WMS_MODULES.maestro.savePersonal(id);
    } catch (ex) { 
      content.innerHTML = `<div class="text-danger" style="padding:20px;">Error cargando datos del personal</div>`;
    }
  },

  deletePersonal(id, n) {
    WMS.confirm('Eliminar Personal', `¿Está seguro de eliminar permanentemente a "<strong>${WMS.esc(n)}</strong>" de la base de datos? Esta acción no se puede deshacer.`, async () => {
      const r = await API.delete('/param/personal/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', r.message || 'Usuario eliminado'); this.show_personal(); }
    });
  },

  // Permisos individuales por usuario
  // Respuesta API: { personal, permisos_rol:[{modulo,submodulo,accion,concedido}], permisos_personal:[...] }
  async gestionarPermisos(personalId, nombre) {
    WMS.showModal(`Permisos de: ${WMS.esc(nombre)}`,
      `<div id="perms-user-body"><div class="spinner" style="margin:30px auto;"></div></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>
       <button class="btn btn-warning btn-sm" onclick="WMS_MODULES.maestro.resetPermisosUsuario(${personalId},'${WMS.esc(nombre)}')">
         <i class="fa-solid fa-rotate-left"></i> Resetear al rol
       </button>`);
    try {
      const r   = await API.get('/personal/' + personalId + '/permisos');
      const el  = document.getElementById('perms-user-body');
      if (!el) return;

      const rolPermisos = r.permisos_rol || [];
      const overrides   = r.permisos_personal || [];
      const persona     = r.personal || {};

      // Construir mapa de overrides: key = modulo|submodulo|accion
      const ovMap = {};
      overrides.forEach(o => { ovMap[`${o.modulo}|${o.submodulo}|${o.accion}`] = o.concedido; });

      // Unir: usar override si existe, si no el valor del rol
      const permisos = rolPermisos.map(p => {
        const key       = `${p.modulo}|${p.submodulo}|${p.accion}`;
        const concedido = key in ovMap ? ovMap[key] : p.concedido;
        const esOverride = key in ovMap;
        return { ...p, concedido, esOverride };
      });

      if (!permisos.length) {
        el.innerHTML = `<div class="m-empty" style="padding:20px;">
          <i class="fa-solid fa-shield-halved"></i>
          <p>Este usuario (rol: <strong>${WMS.esc(persona.rol || '-')}</strong>) no tiene permisos de rol configurados.</p>
        </div>`;
        return;
      }

      const grupos = {};
      permisos.forEach(p => { if (!grupos[p.modulo]) grupos[p.modulo] = []; grupos[p.modulo].push(p); });

      el.innerHTML = `
        <p class="text-sm text-muted" style="margin-bottom:12px;">
          Rol base: <strong>${WMS.esc(persona.rol || '-')}</strong>.
          Los toggles con <span style="color:#f59e0b;">⚡</span> tienen override individual activo.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;max-height:60vh;overflow-y:auto;padding-right:4px;">
          ${Object.entries(grupos).map(([mod, ps]) => `<div class="card">
            <div class="card-header" style="padding:8px 12px;">
              <span class="card-title" style="font-size:.85rem;"><i class="fa-solid fa-cube"></i> ${WMS.esc(mod)}</span>
            </div>
            <div style="padding:6px 12px;">
              ${ps.map(p => {
                const encData = encodeURIComponent(JSON.stringify({ modulo: p.modulo, submodulo: p.submodulo, accion: p.accion }));
                return `<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f1f5f9;">
                  <span style="font-size:.78rem;">${p.esOverride ? '⚡ ' : ''}${WMS.esc(p.accion || p.submodulo || 'ver')}</span>
                  <label class="wms-switch sm">
                    <input type="checkbox" ${p.concedido ? 'checked' : ''}
                      onchange="WMS_MODULES.maestro.togglePermUsuario(${personalId},decodeURIComponent('${encData}'),this.checked)">
                    <span class="slider"></span>
                  </label>
                </div>`;
              }).join('')}
            </div>
          </div>`).join('')}
        </div>`;
    } catch (ex) {
      const el = document.getElementById('perms-user-body');
      if (el) el.innerHTML = '<div class="m-empty" style="padding:20px;">Error cargando permisos del usuario</div>';
    }
  },

  // togglePermiso endpoint espera: { modulo, submodulo, accion, concedido }
  async togglePermUsuario(personalId, permisoJson, concedido) {
    try {
      const p = typeof permisoJson === 'string' ? JSON.parse(permisoJson) : permisoJson;
      await API.post('/personal/' + personalId + '/permisos/toggle', { ...p, concedido });
      WMS.toast('success', concedido ? 'Permiso habilitado' : 'Permiso deshabilitado');
    } catch (e) { WMS.toast('error', 'Error actualizando permiso'); }
  },

  resetPermisosUsuario(personalId, nombre) {
    WMS.confirm('Resetear Permisos', `¿Eliminar todos los overrides individuales de <strong>${WMS.esc(nombre)}</strong> y volver a los permisos del rol?`, async () => {
      const r = await API.delete('/personal/' + personalId + '/permisos');
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Permisos reseteados al rol'); WMS.closeModal('generic-modal'); }
    });
  },

  // ── CATEGORÍAS ───────────────────────────────────────────────
  async show_categorias() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-categorias" placeholder="Buscar..." oninput="WMS_MODULES.maestro.filtrarCategorias(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_categorias()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaCategoria()"><i class="fa-solid fa-plus"></i> Nueva Categoria</button>
      </div>`);
    WMS.spinner();
    try {
    const r     = await API.get('/param/categorias');
    this._categoriasData = r.data || r || [];
      this.renderCategorias(this._categoriasData);
    } catch (e) { WMS.setContent('<div class="m-empty">Error</div>'); }
  },

  renderCategorias(items) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-tags"></i> Categorías (${items.length})</span></div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Nombre</th><th>Descripción</th><th>Acciones</th></tr></thead>
            <tbody>${items.map(c => `<tr>
              <td><strong>${WMS.esc(c.nombre || c.marca || '')}</strong></td>
              <td>${WMS.esc(c.descripcion || '-')}</td>
              <td><div class="actions">
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editCategoria(${c.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteCategoria(${c.id})"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>`).join('') || '<tr><td colspan="3" class="table-empty">Sin categorías</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  nuevaCategoria() {
    WMS.showModal('Nueva Categoría', `
      <div class="form-grid">
        <div class="form-group"><label class="form-label">NOMBRE <span class="required">*</span></label><input id="f-cnom" class="form-control" placeholder="Nombre categoría / marca"></div>
        <div class="form-group"><label class="form-label">DESCRIPCIÓN</label><textarea id="f-cdesc" class="form-control" rows="2" placeholder="Opcional"></textarea></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveCategoria(null)"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveCategoria(id) {
    const data = {
      nombre:      document.getElementById('f-cnom')?.value.trim(),
      descripcion: document.getElementById('f-cdesc')?.value.trim()
    };
    if (!data.nombre) { WMS.toast('warning', 'El nombre es obligatorio'); return; }
    const r = id ? await API.put('/param/categorias/' + id, data) : await API.post('/param/categorias', data);
    if (r.error) WMS.toast('error', r.message);
    else { WMS.toast('success', 'Categoría guardada'); WMS.closeModal('generic-modal'); this.show_categorias(); }
  },

  // FIX: editCategoria era un stub; ahora carga datos y pre-llena modal
  async editCategoria(id) {
    try {
      const r     = await API.get('/param/categorias');
      const items = r.data || r || [];
      const c     = items.find(x => x.id == id);
      if (!c) { WMS.toast('error', 'Categoría no encontrada'); return; }
      WMS.showModal('Editar Categoría', `
        <div class="form-grid">
          <div class="form-group"><label class="form-label">NOMBRE <span class="required">*</span></label><input id="f-cnom" class="form-control" value="${WMS.esc(c.nombre || c.marca || '')}"></div>
          <div class="form-group"><label class="form-label">DESCRIPCIÓN</label><textarea id="f-cdesc" class="form-control" rows="2">${WMS.esc(c.descripcion || '')}</textarea></div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveCategoria(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch (ex) { WMS.toast('error', 'Error cargando categoría'); }
  },

  deleteCategoria(id) {
    WMS.confirm('Eliminar', '¿Eliminar esta categoría?', async () => {
      const r = await API.delete('/param/categorias/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Eliminada'); this.show_categorias(); }
    });
  },

  // ── MARCAS ───────────────────────────────────────────────────
  async show_marcas() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-marcas" placeholder="Buscar..." oninput="WMS_MODULES.maestro.filtrarMarcas(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_marcas()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaMarca()"><i class="fa-solid fa-plus"></i> Nueva Marca</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/marcas');
      this._marcasData = r.data || r || [];
      this.renderMarcas(this._marcasData);
    } catch (e) { WMS.setContent('<div class="m-empty">Error</div>'); }
  },

  renderMarcas(items) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-tag"></i> Marcas (${items.length})</span></div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Nombre</th><th>Descripción</th><th>Acciones</th></tr></thead>
            <tbody>${items.map(m => `<tr>
              <td><strong>${WMS.esc(m.nombre || m.marca || '')}</strong></td>
              <td>${WMS.esc(m.descripcion || m.proveedor || '-')}</td>
              <td><div class="actions">
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editMarca(${m.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteMarca(${m.id}, '${WMS.esc(m.nombre)}')"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>`).join('') || '<tr><td colspan="3" class="table-empty">Sin marcas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  nuevaMarca() {
    WMS.showModal('Nueva Marca', `
      <div class="form-grid">
        <div class="form-group"><label class="form-label">NOMBRE DE MARCA <span class="required">*</span></label><input id="f-mnom" class="form-control" placeholder="Nombre marca"></div>
        <div class="form-group"><label class="form-label">PROVEEDOR ASOCIADO (OPCIONAL)</label><input id="f-mprov" class="form-control" placeholder="Proveedor"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveMarca(null)"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveMarca(id) {
    const data = {
      nombre: document.getElementById('f-mnom')?.value.trim(),
      proveedor: document.getElementById('f-mprov')?.value.trim()
    };
    if (id) {
      data.activo = document.getElementById('f-mact')?.value === '1';
    }
    if (!data.nombre) { WMS.toast('warning', 'El nombre es obligatorio'); return; }
    try {
      const r = id ? await API.put('/param/marcas/' + id, data) : await API.post('/param/marcas', data);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', id ? 'Marca actualizada' : 'Marca creada'); WMS.closeModal('generic-modal'); this.show_marcas(); }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async editMarca(id) {
    try {
      const r = await API.get('/param/marcas');
      const items = r.data || r || [];
      const m = items.find(x => x.id == id);
      if (!m) return;
      WMS.showModal('Editar Marca', `
        <div class="form-grid">
          <div class="form-group"><label class="form-label">NOMBRE DE MARCA <span class="required">*</span></label><input id="f-mnom" class="form-control" value="${WMS.esc(m.nombre)}"></div>
          <div class="form-group"><label class="form-label">PROVEEDOR ASOCIADO (OPCIONAL)</label><input id="f-mprov" class="form-control" value="${WMS.esc(m.proveedor || '')}"></div>
          <div class="form-group"><label class="form-label">ESTADO</label>
            <select id="f-mact" class="form-control">
              <option value="1" ${m.activo ? 'selected' : ''}>Activa</option>
              <option value="0" ${!m.activo ? 'selected' : ''}>Inactiva</option>
            </select>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveMarca(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch (e) { WMS.toast('error', 'Error al cargar marca'); }
  },

  deleteMarca(id, nombre) {
    WMS.confirm('Eliminar Marca', `¿Desea eliminar la marca <strong>${WMS.esc(nombre)}</strong>?`, async () => {
      try {
        const r = await API.delete('/param/marcas/' + id);
        if (r.error) WMS.toast('error', r.message);
        else { WMS.toast('success', 'Marca eliminada'); this.show_marcas(); }
      } catch (e) { WMS.toast('error', 'Error al eliminar'); }
    });
  },

  // ── PRODUCTOS ────────────────────────────────────────────────
  async show_productos() {
    WMS.setToolbar(`
      <div class="actions" style="display:flex;gap:12px;align-items:center;">
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.show_productos()"><i class="fa-solid fa-plus"></i> Registrar Nuevo</button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.consultar_productos()"><i class="fa-solid fa-search"></i> Consultar Catálogo</button>
        <button class="btn btn-info btn-sm" onclick="WMS_MODULES.maestro.descargarPlantillaProductos()"><i class="fa-solid fa-file-csv"></i> Plantilla CSV</button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.importarGenerico('productos')"><i class="fa-solid fa-file-import"></i> Importar CSV</button>
        <button class="btn btn-success btn-sm" onclick="WMS_MODULES.maestro.exportarExcel()"><i class="fa-solid fa-file-excel"></i> Exportar Todo</button>
      </div>`);
    WMS.spinner();
    try {
      const [cr, mr, ambs] = await Promise.all([API.get('/param/categorias'), API.get('/param/marcas'), API.get('/param/ambientes')]);
      const cats     = cr.data || cr || [];
      const marcas   = mr.data || mr || [];
      const ambientes = ambs.data || ambs || [];

      WMS.setContent(`
        <div class="card" style="max-width:800px; margin: 0 auto;">
          <div class="card-header">
            <span class="card-title"><i class="fa-solid fa-plus-circle"></i> Registro Maestro de Producto</span>
            <span class="text-sm text-muted">Complete los datos logísticos obligatorios</span>
          </div>
          <div class="card-body">
            <div class="form-grid form-grid-2">
              <div class="form-group" style="grid-column:1/-1;"><label class="form-label">EAN / CÓDIGO INTERNO <span class="required">*</span></label><input id="f-pean" class="form-control" placeholder="770123..."></div>
              <div class="form-group" style="grid-column:1/-1;"><label class="form-label">NOMBRE DEL PRODUCTO <span class="required">*</span></label><input id="f-pdesc" class="form-control" placeholder="Ej: VINO TINTO MALBEC 750ML"></div>

              <div class="form-group"><label class="form-label">CATEGORÍA</label>
                <select id="f-pcat" class="form-control"><option value="">-- Seleccionar --</option>
                  ${cats.map(c => `<option value="${c.id}">${WMS.esc(c.nombre || '')}</option>`).join('')}
                </select></div>
              <div class="form-group"><label class="form-label">MARCA</label>
                <select id="f-pmar" class="form-control"><option value="">-- Seleccionar --</option>
                  ${marcas.map(m => `<option value="${m.id}">${WMS.esc(m.nombre)}</option>`).join('')}
                </select></div>
              <div class="form-group"><label class="form-label">AMBIENTE <span class="required">*</span></label>
                <select id="f-pamb" class="form-control"><option value="">-- Seleccionar --</option>
                  ${ambientes.map(a => `<option value="${a.id}">${WMS.esc(a.codigo)}${a.descripcion ? ' - ' + WMS.esc(a.descripcion) : ''}</option>`).join('')}
                </select></div>

              <div class="form-group"><label class="form-label">UNIDAD DE MEDIDA</label>
                <select id="f-pum" class="form-control"><option>UN</option><option>KG</option><option>LT</option><option>CJ</option><option>BL</option></select></div>
              <div class="form-group"><label class="form-label">U/E (Factor de empaque)</label><input id="f-puxc" class="form-control" type="number" value="1" min="1"></div>

              <div class="form-group"><label class="form-label">PESO BRUTO (kg)</label><input id="f-ppeso" class="form-control" type="number" step="0.01" value="0.00"></div>
              <div class="form-group"><label class="form-label">VOLUMEN (m³)</label><input id="f-pvol" class="form-control" type="number" step="0.0001" value="0.0000"></div>

              <div class="form-group">
                <label class="form-label" style="color:#7c3aed;font-weight:700;">FACTOR U/E <span style="font-size:10px;color:#94a3b8;">(cant. por unidad)</span></label>
                <input id="f-pfudm" class="form-control" type="number" step="0.0001" min="0" placeholder="Ej: 3000 (para 3000g/u)">
              </div>
              <div class="form-group">
                <label class="form-label" style="color:#7c3aed;font-weight:700;">UNIDAD CONTENIDO <span style="font-size:10px;color:#94a3b8;">(GR, ML, LT…)</span></label>
                <select id="f-pucont" class="form-control">
                  <option value="">-- Sin U/E --</option>
                  <option>GR</option><option>KG</option><option>ML</option><option>LT</option><option>CM</option><option>MT</option>
                </select>
              </div>
              
              <div style="grid-column:1/-1; display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; background:#f8fafc; padding:15px; border-radius:4px; border:1px solid #e2e8f0;">
                <div style="display:flex;flex-direction:column;gap:5px;">
                  <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Stock Mínimo</span>
                  <input id="f-pmin" class="form-control sm" type="number" step="0.01" value="0">
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding-left:10px;">
                  <div style="display:flex;flex-direction:column;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Maneja Lotes</span>
                    <span style="font-size:10px;color:#94a3b8;">Habilita trazabilidad</span>
                  </div>
                  <label class="wms-switch sm"><input type="checkbox" id="f-pmlot" checked><span class="slider"></span></label>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;padding-left:10px;">
                  <div style="display:flex;flex-direction:column;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Ctrl Vencimiento</span>
                    <span style="font-size:10px;color:#94a3b8;">Requiere captura fecha</span>
                  </div>
                  <label class="wms-switch sm"><input type="checkbox" id="f-pcvenc" checked><span class="slider"></span></label>
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer" style="background:#f1f5f9; padding:20px; text-align:right;">
            <button class="btn btn-secondary" onclick="WMS.nav('maestro')">Limpiar</button>
            <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveProducto(null)" style="padding-left:40px; padding-right:40px;">
              <i class="fa-solid fa-save"></i> GUARDAR PRODUCTO
            </button>
          </div>
        </div>`);
    } catch (e) { WMS.setContent('<div class="m-empty">Error cargando formulario de productos</div>'); }
  },

  async consultar_productos() {
    this._prodData = null; // Reset
    
    // Cargar Catálogos para los filtros
    const [cs, ms] = await Promise.all([API.get('/param/categorias'), API.get('/param/marcas')]);
    const cats     = cs.data || cs || [];
    const marcas   = ms.data || ms || [];

    WMS.setToolbar(`
      <div class="search-bar" style="width:300px;">
        <i class="fa-solid fa-search"></i>
        <input id="search-prod" placeholder="EAN, Ref o Desc..." oninput="WMS_MODULES.maestro._timerBuscar()">
      </div>
      <div class="filters-bar" style="display:flex; gap:8px;">
        <select id="filt-cat" class="form-control sm" style="width:140px; font-size:11px;" onchange="WMS_MODULES.maestro._timerBuscar()">
            <option value="">-- Categoría --</option>
            ${cats.map(c => `<option value="${c.id}">${WMS.esc(c.nombre)}</option>`).join('')}
        </select>
        <select id="filt-mar" class="form-control sm" style="width:140px; font-size:11px;" onchange="WMS_MODULES.maestro._timerBuscar()">
            <option value="">-- Marca --</option>
            ${marcas.map(m => `<option value="${m.id}">${WMS.esc(m.nombre)}</option>`).join('')}
        </select>
      </div>
      <div class="actions" style="display:flex; gap:8px; align-items:center;">
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro._cargarTodo()" title="Ver catálogo completo">
          <i class="fa-solid fa-list-ul"></i> Ver Todos
        </button>
        <div style="width:1px; height:20px; background:#e2e8f0; margin:0 4px;"></div>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.show_productos()">
          <i class="fa-solid fa-plus"></i> Nuevo
        </button>
        <button class="btn btn-info btn-sm" onclick="WMS_MODULES.maestro.descargarPlantillaProductos()" title="Descargar plantilla de importación">
          <i class="fa-solid fa-file-csv"></i> Plantilla
        </button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.importarGenerico('productos')" title="Importar desde CSV">
          <i class="fa-solid fa-file-import"></i> Importar
        </button>
        <button class="btn btn-success btn-sm" onclick="WMS_MODULES.maestro.exportarExcel()">
          <i class="fa-solid fa-file-excel"></i>
        </button>
      </div>`);
    
    // Vista inicial profesional sin textos innecesarios
    WMS.setContent(`
      <div style="padding:20px; max-width:1100px; margin:0 auto;">
         <div id="prod-results-container">
            <div style="text-align:center; padding:100px 40px; color:#94a3b8;">
               <i class="fa-solid fa-search" style="font-size:3rem; margin-bottom:20px; opacity:0.3;"></i>
               <p style="font-weight:700;">Ingresa un criterio en el buscador superior o selecciona una categoría.</p>
            </div>
         </div>
      </div>`);
  },

  async descargarPlantillaProductos() {
    WMS.spinner();
    try {
      const [cr, mr, ar] = await Promise.all([API.get('/param/categorias'), API.get('/param/marcas'), API.get('/param/ambientes')]);
      const cats   = cr.data || cr || [];
      const marcas = mr.data || mr || [];
      const ambientes = ar.data || ar || [];
      
      let html = `
        <div style="padding:10px;">
          <div class="alert alert-info" style="margin-bottom:15px; border-left:4px solid #3b82f6;">
            <strong>¡Atención!</strong> Para que la importación sea exitosa, los campos de <strong>categoria_id</strong> y <strong>marca_id</strong> deben contener el número de ID que aparece en las siguientes tablas, no el nombre en texto.
          </div>
          
          <div style="display:flex; gap:20px;">
            <!-- Categorias -->
            <div style="flex:1;">
              <h4 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">IDs de Categorías</h4>
              <div style="max-height:250px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:4px;">
                <table class="erp-table" style="font-size:0.85rem; width:100%;">
                  <thead style="background:#f1f5f9; position:sticky; top:0;">
                    <tr><th style="padding:6px 10px;">ID</th><th style="padding:6px 10px;">Categoría</th></tr>
                  </thead>
                  <tbody>
                    ${cats.map(c => `<tr><td style="padding:6px 10px; font-weight:700; color:#ef4444;">${c.id}</td><td style="padding:6px 10px;">${WMS.esc(c.nombre)}</td></tr>`).join('')}
                    ${cats.length === 0 ? '<tr><td colspan="2" style="padding:10px; text-align:center;">Sin categorías</td></tr>' : ''}
                  </tbody>
                </table>
              </div>
            </div>
            
            <!-- Marcas -->
            <div style="flex:1;">
              <h4 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">IDs de Marcas</h4>
              <div style="max-height:250px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:4px;">
                <table class="erp-table" style="font-size:0.85rem; width:100%;">
                  <thead style="background:#f1f5f9; position:sticky; top:0;">
                    <tr><th style="padding:6px 10px;">ID</th><th style="padding:6px 10px;">Marca</th></tr>
                  </thead>
                  <tbody>
                    ${marcas.map(m => `<tr><td style="padding:6px 10px; font-weight:700; color:#3b82f6;">${m.id}</td><td style="padding:6px 10px;">${WMS.esc(m.nombre)}</td></tr>`).join('')}
                    ${marcas.length === 0 ? '<tr><td colspan="2" style="padding:10px; text-align:center;">Sin marcas</td></tr>' : ''}
                  </tbody>
                </table>
              </div>
            </div>
            <!-- Ambientes -->
            <div style="flex:1;">
              <h4 style="font-size:0.95rem; font-weight:700; color:#1e293b; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">Ambientes (ID o Código)</h4>
              <div style="max-height:250px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:4px;">
                <table class="erp-table" style="font-size:0.85rem; width:100%;">
                  <thead style="background:#f1f5f9; position:sticky; top:0;">
                    <tr><th style="padding:6px 10px;">ID</th><th style="padding:6px 10px;">Código</th></tr>
                  </thead>
                  <tbody>
                    ${ambientes.map(a => `<tr><td style="padding:6px 10px; font-weight:700; color:#7c3aed;">${a.id}</td><td style="padding:6px 10px;">${WMS.esc(a.codigo)}</td></tr>`).join('')}
                    ${ambientes.length === 0 ? '<tr><td colspan="2" style="padding:10px; text-align:center;">Sin ambientes</td></tr>' : ''}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          
          <div style="margin-top:20px; text-align:center;">
             <button class="btn btn-success" style="border-radius:4px; padding:10px 20px; font-weight:700;" onclick="WMS_MODULES.maestro._generarCSVPlantilla()">
               <i class="fa-solid fa-download"></i> DESCARGAR PLANTILLA CSV DE EJEMPLO
             </button>
          </div>
        </div>
      `;
      
      this._plantillaCats = cats;
      this._plantillaMarcas = marcas;
      this._plantillaAmbientes = ambientes;
      WMS.showModal('Guía para Importar Productos', html);
    } catch(e) {
      WMS.toast('error', 'Error al cargar IDs para la plantilla');
    }
  },
  
  _generarCSVPlantilla() {
    const cats     = this._plantillaCats      || [];
    const marcas   = this._plantillaMarcas    || [];
    const ambientes = this._plantillaAmbientes || [];

    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "=== RELACION DE CATEGORIAS ===\n";
    csvContent += "ID;Nombre de Categoria\n";
    if (cats.length === 0) csvContent += "Sin categorias registradas\n";
    cats.forEach(c => { csvContent += `${c.id};${(c.nombre||'').replace(/;/g, '')}\n`; });
    csvContent += "\n";

    csvContent += "=== RELACION DE MARCAS ===\n";
    csvContent += "ID;Nombre de Marca\n";
    if (marcas.length === 0) csvContent += "Sin marcas registradas\n";
    marcas.forEach(m => { csvContent += `${m.id};${(m.nombre||'').replace(/;/g, '')}\n`; });
    csvContent += "\n";

    csvContent += "=== RELACION DE AMBIENTES ===\n";
    csvContent += "ID;Codigo de Ambiente\n";
    if (ambientes.length === 0) csvContent += "Sin ambientes registrados\n";
    ambientes.forEach(a => { csvContent += `${a.id};${(a.codigo||'').replace(/;/g, '')}\n`; });
    csvContent += "\n";

    csvContent += "=== PLANTILLA DE PRODUCTOS (Llene los datos debajo de las cabeceras) ===\n";

    const cabeceras = [
      "codigo_ean",
      "codigo_interno",
      "nombre",
      "categoria_id",
      "marca_id",
      "ambiente_id",
      "unidad_medida",
      "unidades_caja",
      "peso_unitario",
      "volumen_unitario",
      "stock_minimo",
      "controla_lote",
      "controla_vencimiento",
      "vida_util_dias",
      "temperatura_almacen",
    ];

    // Valores de ejemplo
    const catEj = cats[0]?.id     || "1";
    const marEj = marcas[0]?.id   || "1";
    const ambEj = ambientes[0]?.codigo || "SECO";

    const ejemplo1 = ["7701234567890", "REF001", "VINO TINTO MALBEC 750ML",       catEj, marEj, ambEj, "UN", "12", "1.50", "0.005", "10", "1", "1", "", "AMBIENTE"];
    const ejemplo2 = ["7709876543210", "REF002", "ACEITE DE OLIVA EXTRA VIRGEN",   catEj, marEj, ambEj, "UN", "24", "0.80", "0.002", "50", "1", "1", "", "REFRIGERADO"];
    const ejemplo3 = ["7705555555555", "REF003", "ARROZ BLANCO PREMIUM 1KG",       catEj, marEj, ambEj, "UN", "20", "1.00", "0.001", "100", "0", "0", "", "AMBIENTE"];

    csvContent += cabeceras.join(";") + "\n";
    csvContent += ejemplo1.join(";") + "\n";
    csvContent += ejemplo2.join(";") + "\n";
    csvContent += ejemplo3.join(";");
      
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "plantilla_productos_relacionada.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    WMS.toast('success', 'Plantilla descargada. Recuerde borrar las filas de ayuda antes de subir.');
  },

  _timerBuscar() {
    clearTimeout(this._searchTimer);
    this._searchTimer = setTimeout(() => this.buscarProductos(), 350);
  },

  async buscarProductos(force = false) {
    const q = document.getElementById('search-prod')?.value.trim();
    const cat = document.getElementById('filt-cat')?.value;
    const mar = document.getElementById('filt-mar')?.value;

    const resCont = document.getElementById('prod-results-container');
    if (!resCont) return;

    if (!force && !q && !cat && !mar) {
      resCont.innerHTML = `
        <div style="text-align:center; padding:100px 40px; color:#94a3b8;">
           <i class="fa-solid fa-search" style="font-size:3rem; margin-bottom:20px; opacity:0.3;"></i>
           <p style="font-weight:700;">Ingresa un criterio en el buscador superior o selecciona una categoría.</p>
        </div>`;
      return;
    }

    try {
      const params = new URLSearchParams();
      if (q) params.append('q', q);
      if (cat) params.append('categoria_id', cat);
      if (mar) params.append('marca_id', mar);
      params.append('limit', 50);

      const r = await API.get('/param/productos/buscar', params.toString());
      this.renderProductos(r.data || r || []);
    } catch (e) { resCont.innerHTML = '<div class="alert alert-danger">Error en la búsqueda</div>'; }
  },

  renderProductos(items) {
    if (!Array.isArray(items)) items = [];
    const container = document.getElementById('prod-results-container') || document.getElementById('content-body');
    
    let html = `
      <div class="card animate-fade-in">
        <div class="card-header" style="justify-content: space-between; border-bottom:1px solid #f1f5f9;">
          <div>
            <span class="card-title" style="font-size:.9rem;"><i class="fa-solid fa-box"></i> Resultados (${items.length})</span>
          </div>
        </div>
        <div class="table-container" style="max-height:600px; overflow-y:auto;">
          <table class="erp-table">
            <thead style="position:sticky; top:0; z-index:10; background:#f8fafc;">
               <tr><th>Referencia/EAN</th><th>Descripción</th><th>Categoría/Marca</th><th>Ambiente</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody>${items.map(p => `<tr>
              <td>
                <div style="font-weight:700; color:var(--primary);">${WMS.esc(p.codigo_interno)}</div>
                <div style="font-size:10px; color:#94a3b8; font-family:monospace;">EAN: ${WMS.esc(p.codigo_ean || '-')}</div>
              </td>
              <td>
                <div style="font-weight:600; color:#1e293b;">${WMS.esc(p.descripcion || '')}</div>
                <div style="font-size:10px; color:#64748b;">U/E: ${p.unidades_caja || 1} · UM: ${WMS.esc(p.unidad_medida || 'UN')}</div>
              </td>
              <td>
                 <div style="font-size:11px; font-weight:700; color:#475569;">${WMS.esc(p.categoria_nombre || '-')}</div>
                 <div style="font-size:10px; color:#94a3b8;">${WMS.esc(p.marca_nombre || '-')}</div>
              </td>
              <td>${p.ambiente_nombre && p.ambiente_nombre !== '-'
                ? `<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:.72rem;font-weight:700;color:${WMS.esc(p.ambiente_color||'#475569')};background:${WMS.esc(p.ambiente_color||'#475569')}15;border:1px solid ${WMS.esc(p.ambiente_color||'#475569')}40;">${WMS.esc(p.ambiente_nombre)}</span>`
                : '<span style="color:#94a3b8;font-size:.72rem;">Sin asignar</span>'}</td>
              <td><span class="status-chip ${p.activo ? 'status-cerrada' : 'status-cancelada'}">${p.activo ? 'Activo' : 'Inactivo'}</span></td>
              <td><div class="actions">
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editProducto(${p.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-sm ${p.activo ? 'btn-danger' : 'btn-success'}" onclick="WMS_MODULES.maestro.toggleEstadoProducto(${p.id})" title="${p.activo ? 'Desactivar' : 'Activar'}">
                   <i class="fa-solid ${p.activo ? 'fa-toggle-on' : 'fa-toggle-off'}"></i>
                </button>
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.verEans(${p.id})" title="EANs"><i class="fa-solid fa-barcode"></i></button>
                <button class="btn btn-sm btn-info" style="position:relative;" onclick="WMS_MODULES.maestro.verFotos(${p.id})" title="Fotos">
                   <i class="fa-solid fa-image"></i>
                   ${p.fotos && p.fotos.length ? `<span style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;border-radius:10px;font-size:9px;padding:2px 5px;font-weight:bold;">${p.fotos.length}</span>` : ''}
                </button>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteProducto(${p.id},'${WMS.esc(p.descripcion || '')}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>`).join('') || '<tr><td colspan="6" class="table-empty">Sin productos que coincidan</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`;
    
    if (container.id === 'prod-results-container') {
       container.innerHTML = html;
    } else {
       WMS.setContent(html);
    }
  },

  async _cargarTodo() {
    const qEl = document.getElementById('search-prod');
    if (qEl) qEl.value = '';
    // Forzamos la búsqueda para cargar los últimos productos por defecto
    this.buscarProductos(true);
  },

  exportarExcel() {
    const baseUrl = (typeof API_BASE !== 'undefined' ? API_BASE : '/api');
    const url = baseUrl + '/param/import-export/export/productos?token=' + encodeURIComponent(localStorage.getItem('wms_token'));
    window.open(url, '_blank');
  },


  async verFotos(id) {
    try {
      const r = await API.get('/param/productos/' + id);
      const p = r.data || r || null;
      if (!p) { WMS.toast('error', 'Producto no encontrado'); return; }
      
      const nombre = WMS.esc(p.descripcion || p.nombre || '');
      const fotos = p.fotos || [];
      const baseUrl = (typeof API_BASE !== 'undefined' ? API_BASE.replace('/api', '') : '');
      
      let html = '';
      if(fotos.length) {
        html = `<div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:15px; max-height:400px; overflow-y:auto; padding:10px; background:#f8fafc; border-radius:6px;">
          ${fotos.map(f => {
            const imgUrl = f.url.startsWith('http') ? f.url : (baseUrl + f.url);
            return `<div style="border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#fff; padding:4px;">
              <img src="${imgUrl}" style="height:150px; width:150px; object-fit:cover; cursor:pointer;" onclick="window.open('${imgUrl}','_blank')">
            </div>`;
          }).join('')}
        </div>`;
      } else {
        html = '<div class="alert alert-info" style="margin-top:15px;">No hay fotos registradas para este producto.</div>';
      }
      
      WMS.showModal('Fotos: ' + nombre, html, '<button class="btn btn-secondary" onclick="WMS.closeModal(\'generic-modal\')">Cerrar</button>');
    } catch(e) {
      WMS.toast('error', 'Error al cargar fotos');
    }
  },

  async verEans(id) {
    try {
      // Re-fetch the product to get its name
      const rp = await API.get('/param/productos/' + id);
      const p = rp.data || rp || {};
      const nombre = WMS.esc(p.descripcion || p.nombre || '');
      
      const r = await API.get('/param/productos/' + id + '/eans');
      const eans = r.data || r || [];
      
      let html = '<div style="margin-top:15px; max-height:400px; overflow-y:auto;">';
      if(eans.length) {
        html += '<table class="table table-sm table-striped"><thead><tr><th>Código EAN</th><th>Tipo</th><th>Principal</th></tr></thead><tbody>';
        html += eans.map(e => `
          <tr>
            <td style="font-weight:bold;">${WMS.esc(e.codigo_ean)}</td>
            <td>${WMS.esc(e.tipo)}</td>
            <td>${e.es_principal ? '<span class="badge bg-primary">Sí</span>' : '<span class="badge bg-secondary">No</span>'}</td>
          </tr>
        `).join('');
        html += '</tbody></table>';
      } else {
        html += '<div class="alert alert-info">No hay códigos adicionales registrados.</div>';
      }
      html += '</div>';
      
      WMS.showModal('EANs: ' + nombre, html, '<button class="btn btn-secondary" onclick="WMS.closeModal(\'generic-modal\')">Cerrar</button>');
    } catch(e) {
      WMS.toast('error', 'Error al cargar EANs');
    }
  },

  async toggleEstadoProducto(id) {
    try {
      const r = await API.put(`/param/productos/${id}/toggle-status`);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', r.message);
        this.buscarProductos(); // Refrescar con los filtros actuales
      }
    } catch (e) { WMS.toast('error', 'Error al cambiar estado'); }
  },

  async nuevoProducto() {
    const [cs, ms, ambs] = await Promise.all([API.get('/param/categorias'), API.get('/param/marcas'), API.get('/param/ambientes')]);
    const cats     = cs.data || cs || [];
    const marcas   = ms.data || ms || [];
    const ambientes = ambs.data || ambs || [];
    WMS.showModal('Nuevo Producto', `
      <div class="form-grid form-grid-2">
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">EAN PRINCIPAL / CÓDIGO <span class="required">*</span></label><input id="f-pean" class="form-control" placeholder="7701234567890"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">DESCRIPCIÓN <span class="required">*</span></label><input id="f-pdesc" class="form-control" placeholder="Nombre del producto"></div>
        <div class="form-group"><label class="form-label">CATEGORÍA</label>
          <select id="f-pcat" class="form-control"><option value="">-- Sin categoría --</option>
            ${cats.map(c => `<option value="${c.id}">${WMS.esc(c.nombre || c.marca || '')}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">MARCA</label>
          <select id="f-pmar" class="form-control"><option value="">-- Sin marca --</option>
            ${marcas.map(m => `<option value="${m.id}">${WMS.esc(m.nombre)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label class="form-label">AMBIENTE <span class="required">*</span></label>
          <select id="f-pamb" class="form-control"><option value="">-- Seleccione --</option>
            ${ambientes.map(a => `<option value="${a.id}">${WMS.esc(a.codigo)}${a.descripcion ? ' - ' + WMS.esc(a.descripcion) : ''}</option>`).join('')}
          </select></div>
        <div class="form-group"><label class="form-label">UNIDAD DE MEDIDA</label>
          <select id="f-pum" class="form-control"><option>UN</option><option>KG</option><option>LT</option><option>ML</option><option>GR</option><option>CJ</option><option>BL</option></select></div>
        <div class="form-group"><label class="form-label">PESO (kg)</label><input id="f-ppeso" class="form-control" type="number" step="0.01" placeholder="0.00"></div>
        <div class="form-group"><label class="form-label">VOLUMEN (m³)</label><input id="f-pvol" class="form-control" type="number" step="0.001" placeholder="0.000"></div>
        <div class="form-group"><label class="form-label">U/E (Factor de empaque)</label><input id="f-puxc" class="form-control" type="number" value="1" min="1"></div>

        <div style="grid-column:1/-1; display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; background:#f8fafc; padding:10px; border-radius:4px; margin-top:5px;">
           <div style="display:flex;flex-direction:column;gap:5px;">
              <span style="font-size:.78rem;font-weight:600;color:#475569;">Stock Mínimo</span>
              <input id="f-pmin" class="form-control sm" type="number" step="0.01" value="0">
           </div>
           <div style="display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:.78rem;font-weight:600;color:#475569;">Maneja Lotes</span>
              <label class="wms-switch sm"><input type="checkbox" id="f-pmlot" checked><span class="slider"></span></label>
           </div>
           <div style="display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:.78rem;font-weight:600;color:#475569;">Controla Venc.</span>
              <label class="wms-switch sm"><input type="checkbox" id="f-pcvenc" checked><span class="slider"></span></label>
           </div>
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveProducto(null)"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveProducto(id) {
    const parseVal = (str) => { if (!str) return 0; const v = parseFloat(str.replace(',', '.')); return isNaN(v) ? 0 : v; };
    const data = {
      codigo_interno:      document.getElementById('f-pean')?.value.trim(),
      codigo_ean:          document.getElementById('f-pean')?.value.trim(),
      nombre:              document.getElementById('f-pdesc')?.value.trim(),
      descripcion:         document.getElementById('f-pdesc')?.value.trim(),
      categoria_id:        document.getElementById('f-pcat')?.value || null,
      marca_id:            document.getElementById('f-pmar')?.value || null,
      ambiente_id:         document.getElementById('f-pamb')?.value || null,
      unidad_medida:       document.getElementById('f-pum')?.value,
      peso_unitario:       parseVal(document.getElementById('f-ppeso')?.value),
      volumen_unitario:    parseVal(document.getElementById('f-pvol')?.value),
      stock_minimo:        parseVal(document.getElementById('f-pmin')?.value),
      unidades_caja:       parseInt(document.getElementById('f-puxc')?.value || 1),
      factor_udm:          parseVal(document.getElementById('f-pfudm')?.value) || null,
      unidad_contenido:    document.getElementById('f-pucont')?.value || null,
      maneja_lotes:        document.getElementById('f-pmlot')?.checked ? 1 : 0,
      controla_vencimiento: document.getElementById('f-pcvenc')?.checked ? 1 : 0
    };
    if (!data.nombre || !data.codigo_interno) { WMS.toast('warning', 'EAN/Código y Nombre son obligatorios'); return; }
    try {
      const r = id ? await API.put('/param/productos/' + id, data) : await API.post('/param/productos', data);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Producto guardado'); WMS.closeModal('generic-modal'); this.show_productos(); }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async editProducto(id) {
    try {
      const [pr, cr, sr, ambs] = await Promise.all([
        API.get('/param/productos/' + id),
        API.get('/param/categorias'),
        API.get('/param/sucursales?activo=1'),
        API.get('/param/ambientes')
      ]);
      let p = pr.data || pr || null;
      if (!p) { WMS.toast('error', 'Producto no encontrado'); return; }
      const ambientes = ambs.data || ambs || [];
      const cs = cr.data || cr || [];
      WMS.showModal('Editar Producto', `
        <div class="form-grid form-grid-2">
          <div class="form-group" style="grid-column:1/-1;"><label class="form-label">EAN PRINCIPAL / CÓDIGO</label><input id="f-pean" class="form-control" value="${WMS.esc(p.ean_principal || p.codigo_ean || p.codigo_interno || '')}"></div>
          <div class="form-group" style="grid-column:1/-1;"><label class="form-label">DESCRIPCIÓN <span class="required">*</span></label><input id="f-pdesc" class="form-control" value="${WMS.esc(p.nombre || p.descripcion || '')}"></div>
          <div class="form-group"><label class="form-label">CATEGORÍA</label>
            <select id="f-pcat" class="form-control"><option value="">-- Sin categoría --</option>
              ${cs.map(c => `<option value="${c.id}" ${c.id == p.categoria_id ? 'selected' : ''}>${WMS.esc(c.nombre || c.marca || '')}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">MARCA</label>
            <select id="f-pmar" class="form-control"><option value="">-- Sin marca --</option>
              ${(await API.get('/param/marcas')).data?.map(m => `<option value="${m.id}" ${m.id == p.marca_id ? 'selected' : ''}>${WMS.esc(m.nombre)}</option>`).join('') || ''}
            </select></div>
          <div class="form-group"><label class="form-label">AMBIENTE <span class="required">*</span></label>
            <select id="f-pamb" class="form-control"><option value="">-- Seleccione --</option>
              ${ambientes.map(a => `<option value="${a.id}" ${a.id == p.ambiente_id ? 'selected' : ''}>${WMS.esc(a.codigo)}${a.descripcion ? ' - ' + WMS.esc(a.descripcion) : ''}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">UNIDAD DE MEDIDA</label>
            <select id="f-pum" class="form-control">
              ${['UN', 'KG', 'LT', 'ML', 'GR', 'CJ', 'BL', 'PQ', 'RO', 'PA'].map(u => `<option ${p.unidad_medida === u ? 'selected' : ''}>${u}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">PESO (kg)</label><input id="f-ppeso" class="form-control" type="number" step="0.01" value="${p.peso_unitario || ''}"></div>
          <div class="form-group"><label class="form-label">VOLUMEN (m³)</label><input id="f-pvol" class="form-control" type="number" step="0.001" value="${p.volumen_unitario || ''}"></div>
          <div class="form-group"><label class="form-label">U/E (Factor de empaque)</label><input id="f-puxc" class="form-control" type="number" value="${p.unidades_caja || 1}" min="1"></div>
          <div class="form-group">
            <label class="form-label" style="color:#7c3aed;font-weight:700;">FACTOR U/E <span style="font-size:10px;color:#94a3b8;">(cant. por unidad)</span></label>
            <input id="f-pfudm" class="form-control" type="number" step="0.0001" min="0" value="${p.factor_udm || ''}" placeholder="Ej: 3000">
          </div>
          <div class="form-group">
            <label class="form-label" style="color:#7c3aed;font-weight:700;">UNIDAD CONTENIDO</label>
            <select id="f-pucont" class="form-control">
              <option value="">-- Sin U/E --</option>
              ${['GR','KG','ML','LT','CM','MT'].map(u => `<option ${p.unidad_contenido === u ? 'selected' : ''}>${u}</option>`).join('')}
            </select>
          </div>

          <div style="grid-column:1/-1; display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; background:#f8fafc; padding:10px; border-radius:4px; margin-top:5px;">
             <div style="display:flex;flex-direction:column;gap:5px;">
                <span style="font-size:.78rem;font-weight:600;color:#475569;">Stock Mínimo</span>
                <input id="f-pmin" class="form-control sm" type="number" step="0.01" value="${p.stock_minimo || 0}">
             </div>
             <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:.78rem;font-weight:600;color:#475569;">Maneja Lotes</span>
                <label class="wms-switch sm"><input type="checkbox" id="f-pmlot" ${p.controla_lote ? 'checked' : ''}><span class="slider"></span></label>
             </div>
             <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:.78rem;font-weight:600;color:#475569;">Controla Venc.</span>
                <label class="wms-switch sm"><input type="checkbox" id="f-pcvenc" ${p.controla_vencimiento ? 'checked' : ''}><span class="slider"></span></label>
             </div>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveProducto(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch (ex) { WMS.toast('error', 'Error cargando producto: ' + ex.message); }
  },

  async verEans(prodId, nombre) {
    const r    = await API.get('/param/productos/' + prodId + '/eans');
    const eans = r.data || r || [];
    WMS.showModal(`EANs de: ${WMS.esc(nombre)}`, `
      <div style="margin-bottom:14px;"><strong>${eans.length}</strong> EAN(s) asociado(s)</div>
      <div class="table-container">
        <table class="erp-table">
          <thead><tr><th>EAN</th><th>Descripción</th><th>Acciones</th></tr></thead>
          <tbody>${eans.map(e => `<tr>
            <td style="font-family:monospace;">${WMS.esc(e.codigo_ean || e.ean || '')}</td>
            <td>${WMS.esc(e.descripcion || '-')}</td>
            <td><button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteEan(${prodId},${e.id})"><i class="fa-solid fa-trash"></i></button></td>
          </tr>`).join('') || '<tr><td colspan="3" class="table-empty">Sin EANs adicionales</td></tr>'}
          </tbody>
        </table>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;">
        <input id="f-new-ean" class="form-control" placeholder="Nuevo EAN (código de barras)">
        <button class="btn btn-primary" onclick="WMS_MODULES.maestro.addEan(${prodId})"><i class="fa-solid fa-plus"></i> Agregar</button>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cerrar</button>`);
  },

  // FIX: enviaba {ean} pero el controller espera {codigo_ean}
  async addEan(prodId) {
    const ean = document.getElementById('f-new-ean')?.value.trim();
    if (!ean) { WMS.toast('warning', 'Ingrese el EAN'); return; }
    const r = await API.post('/param/productos/' + prodId + '/eans', { codigo_ean: ean });
    if (r.error) WMS.toast('error', r.message);
    else {
      WMS.toast('success', 'EAN agregado');
      const p = this._prodData?.find(x => x.id == prodId);
      this.verEans(prodId, p?.descripcion || prodId);
    }
  },

  async deleteEan(prodId, eanId) {
    const r = await API.delete('/param/productos/' + prodId + '/eans/' + eanId);
    if (r.error) WMS.toast('error', r.message);
    else {
      WMS.toast('success', 'EAN eliminado');
      const p = this._prodData?.find(x => x.id == prodId);
      this.verEans(prodId, p?.descripcion || prodId);
    }
  },

  deleteProducto(id, n) {
    WMS.confirm('Eliminar Producto', `¿Eliminar "<strong>${WMS.esc(n)}</strong>"?`, async () => {
      const r = await API.delete('/param/productos/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Producto eliminado'); this.show_productos(); }
    });
  },

  // ── AMBIENTES ──────────────────────────────────────────────
  async show_ambientes() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-ambientes" placeholder="Buscar ambiente..." oninput="WMS_MODULES.maestro.filtrarAmbientes(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_ambientes()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.editAmbiente(null)"><i class="fa-solid fa-plus"></i> Nuevo Ambiente</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/ambientes');
      this._ambientesData = r.data || r || [];
      this.renderAmbientes(this._ambientesData);
    } catch (e) {
      WMS.setContent(`<div class="alert alert-danger m-3">Error cargando ambientes: ${e.message}</div>`);
    }
  },

  renderAmbientes(items) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-temperature-half"></i> Ambientes (${items.length})</span></div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>ID</th><th>CÓDIGO</th><th>DESCRIPCIÓN</th><th>COLOR</th><th>PRODUCTOS</th><th>ACCIONES</th></tr></thead>
            <tbody>
              ${items.map(a => `
              <tr>
                <td>${a.id}</td>
                <td style="font-weight:700;">${WMS.esc(a.codigo)}</td>
                <td>${WMS.esc(a.descripcion || '—')}</td>
                <td>${a.color ? `<span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:${WMS.esc(a.color)};border:1px solid #ccc;"></span> ${WMS.esc(a.color)}` : '—'}</td>
                <td style="text-align:center;">${a.productos_count ?? '—'}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editAmbiente(${a.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                  <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteAmbiente(${a.id}, '${WMS.esc(a.codigo)}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </div></td>
              </tr>
              `).join('') || '<tr><td colspan="6" class="table-empty">Sin ambientes registrados. Cree al menos: SECO, REFRIGERADO, CONGELADO.</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  filtrarAmbientes(q) {
    if (!this._ambientesData) return;
    const f = q.toLowerCase();
    this.renderAmbientes(f
      ? this._ambientesData.filter(a => a.codigo?.toLowerCase().includes(f) || a.descripcion?.toLowerCase().includes(f))
      : this._ambientesData);
  },

  async editAmbiente(id) {
    let a = null;
    if (id) {
      const r = await API.get('/param/ambientes');
      const all = r.data || r || [];
      a = all.find(x => x.id == id);
    }
    WMS.showModal(id ? 'Editar Ambiente' : 'Nuevo Ambiente', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">CÓDIGO <span class="required">*</span></label>
          <input id="f-amb-cod" class="form-control" value="${WMS.esc(a?.codigo || '')}" placeholder="Ej: SECO, REFRIGERADO, CONGELADO" style="text-transform:uppercase;"></div>
        <div class="form-group"><label class="form-label">DESCRIPCIÓN</label>
          <input id="f-amb-desc" class="form-control" value="${WMS.esc(a?.descripcion || '')}" placeholder="Descripción opcional"></div>
        <div class="form-group"><label class="form-label">COLOR (hex)</label>
          <input id="f-amb-color" class="form-control" type="color" value="${a?.color || '#92400e'}" style="height:38px;"></div>
        <div class="form-group"><label class="form-label">ICONO (FA class)</label>
          <input id="f-amb-icono" class="form-control" value="${WMS.esc(a?.icono || '')}" placeholder="fa-sun, fa-snowflake..."></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveAmbiente(${id || 'null'})"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveAmbiente(id) {
    const data = {
      codigo:      document.getElementById('f-amb-cod')?.value.trim().toUpperCase(),
      descripcion: document.getElementById('f-amb-desc')?.value.trim(),
      color:       document.getElementById('f-amb-color')?.value,
      icono:       document.getElementById('f-amb-icono')?.value.trim(),
    };
    if (!data.codigo) { WMS.toast('warning', 'El código es obligatorio'); return; }
    try {
      const r = id ? await API.put('/param/ambientes/' + id, data) : await API.post('/param/ambientes', data);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', id ? 'Ambiente actualizado' : 'Ambiente creado: ' + data.codigo);
        WMS.closeModal('generic-modal');
        this.show_ambientes();
      }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async deleteAmbiente(id, codigo) {
    if (!confirm('¿Seguro que desea eliminar el ambiente ' + codigo + '?')) return;
    try {
      const r = await API.delete('/param/ambientes/' + id);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', 'Ambiente eliminado');
        this.show_ambientes();
      }
    } catch (e) { WMS.toast('error', 'Error al eliminar'); }
  },

  // ── ZONAS ──────────────────────────────────────────────
  async show_zonas() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-zonas" placeholder="Buscar zona..." oninput="WMS_MODULES.maestro.filtrarZonas(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_zonas()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.editZona(null)"><i class="fa-solid fa-plus"></i> Nueva Zona</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/zonas');
      this._zonasData = r.data || r || [];
      this.renderZonas(this._zonasData);
    } catch (e) {
      WMS.setContent(`<div class="alert alert-danger m-3">Error cargando zonas: ${e.message}</div>`);
    }
  },

  renderZonas(items) {
    const html = `
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-map"></i> Zonas (${items.length})</span></div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>ID</th><th>CÓDIGO (ZONA)</th><th>DESCRIPCIÓN</th><th>ACCIONES</th></tr></thead>
            <tbody>
              ${items.map(z => `
              <tr>
                <td>${z.id}</td>
                <td style="font-weight:700;">${WMS.esc(z.codigo)}</td>
                <td>${WMS.esc(z.descripcion || '—')}</td>
                <td><div class="actions">
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editZona(${z.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                  <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteZona(${z.id}, '${WMS.esc(z.codigo)}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </div></td>
              </tr>
              `).join('') || '<tr><td colspan="4" class="table-empty">Sin zonas registradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`;
    WMS.setContent(html);
  },

  filtrarZonas(q) {
    if (!this._zonasData) return;
    const f = q.toLowerCase();
    this.renderZonas(f
      ? this._zonasData.filter(z => z.codigo?.toLowerCase().includes(f) || z.descripcion?.toLowerCase().includes(f))
      : this._zonasData);
  },

  // ── UBICACIONES ──────────────────────────────────────────────
  async show_ubicaciones(forceLoad = false) {
    if (forceLoad || (this._ubiData && this._ubiData.length > 0)) {
       if (!forceLoad) return this._renderUbiShell(this._ubiData);
       WMS.spinner();
       try {
         const r = await API.get('/param/ubicaciones', 'activo=all');
         this._ubiData = r.data || r || [];
         return this._renderUbiShell(this._ubiData);
       } catch(e) { WMS.toast('error','Error al cargar ubicaciones'); }
    }
    
    // Si no hay datos y no se forzó carga, mostrar vacío con mensaje
    this._ubiData = []; 
    this._renderUbiShell([]);
  },

  _renderUbiShell(items) {
    const zonas    = [...new Set(items.map(u => u.zona).filter(Boolean))].sort();

    WMS.setToolbar(`
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <select id="f-ubi-zona" class="form-control" style="width:150px;font-weight:600;" onchange="WMS_MODULES.maestro._updateUbiDynamicFilters(); WMS_MODULES.maestro.filterUbicaciones()">
          <option value="">Todas las zonas</option>
          ${zonas.map(z => `<option value="${WMS.esc(z)}">${WMS.esc(z)}</option>`).join('')}
        </select>
        <div class="search-bar" style="min-width:200px;"><i class="fa-solid fa-search"></i>
          <input id="f-ubi-search" placeholder="Búsqueda inteligente..." oninput="WMS_MODULES.maestro.filterUbicaciones()">
        </div>
        <select id="f-ubi-pas" class="form-control" style="width:110px;" onchange="WMS_MODULES.maestro.filterUbicaciones()">
          <option value="">Pasillo...</option>
        </select>
        <select id="f-ubi-mod" class="form-control" style="width:110px;" onchange="WMS_MODULES.maestro.filterUbicaciones()">
          <option value="">Módulo...</option>
        </select>
        <select id="f-ubi-niv" class="form-control" style="width:100px;" onchange="WMS_MODULES.maestro.filterUbicaciones()">
          <option value="">Nivel...</option>
        </select>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.importarUbicaciones()"><i class="fa-solid fa-file-import"></i> Importar</button>
        <button id="btn-load-ubis" class="btn btn-info-soft btn-sm" onclick="WMS_MODULES.maestro.show_ubicaciones(true)"><i class="fa-solid fa-sync"></i> Cargar Todo</button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaUbicacion()"><i class="fa-solid fa-plus"></i> Nueva</button>
      </div>`);

    this._updateUbiDynamicFilters();

    const tipoColor = { Picking: 'badge-success', Almacenamiento: 'badge-info', Muelle: 'badge-warning', Carro: 'badge-purple', Patio: 'badge-gray' };
    const estadoClass = { Libre: 'status-cerrada', Ocupada: 'status-cancelada', Parcial: 'status-pendiente', Locked: 'status-cancelada' };
    
    const hasData = items && items.length > 0;

    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-map-pin"></i> Ubicaciones (<span id="ubi-count">${items.length}</span>)</span></div>
        <div class="table-container">
          <table class="erp-table" id="ubi-table">
            <thead><tr><th>Código</th><th>Zona</th><th style="width:80px;">Pasillo</th><th style="width:80px;">Mód.</th><th style="width:80px;">Niv.</th><th>Tipo</th><th>Clase</th><th>M3</th><th>Cant. Máx.</th><th>Activo</th><th>Acciones</th></tr></thead>
            <tbody id="ubi-tbody">
              ${hasData ? this._renderUbiRows(items, tipoColor, estadoClass) : '<tr><td colspan="11" class="table-empty"><div class="py-20 text-center"><i class="fa-solid fa-magnifying-glass fa-2x mb-12" style="opacity:.3"></i><p>Ingrese un criterio de búsqueda o haga clic en "Cargar Todo" para ver las ubicaciones.</p></div></td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _renderUbiRows(items, tipoColor, estadoClass) {
    if (!tipoColor) tipoColor = { Picking: 'badge-success', Almacenamiento: 'badge-info', Muelle: 'badge-warning', Carro: 'badge-purple', Patio: 'badge-gray' };
    if (!estadoClass) estadoClass = { Libre: 'status-cerrada', Ocupada: 'status-cancelada', Parcial: 'status-pendiente', Locked: 'status-cancelada' };

    return items.map(u => `<tr class="ubi-row" data-id="${u.id}">
      <td><strong>${WMS.esc(u.codigo || [u.pasillo, u.modulo, u.nivel].filter(Boolean).join('-') || '')}</strong></td>
      <td><span class="badge badge-gray">${WMS.esc(u.zona || '-')}</span></td>
      <td style="text-align:center;">${WMS.esc(u.pasillo || '-')}</td>
      <td style="text-align:center;">${WMS.esc(u.modulo || '-')}</td>
      <td style="text-align:center;">${WMS.esc(u.nivel || '-')}</td>
      <td><span class="badge ${tipoColor[u.tipo_ubicacion] || 'badge-gray'}">${WMS.esc(u.tipo_ubicacion || '-')}</span></td>
      <td><span class="badge badge-outline">${WMS.esc(u.clase || 'Normal')}</span></td>
      <td>${u.m3 ? u.m3 + ' m³' : '-'}</td>
      <td>${u.capacidad_maxima ? u.capacidad_maxima + ' u.' : '-'}</td>
      <td style="text-align:center; cursor:pointer;" onclick="WMS_MODULES.maestro.toggleUbiStatus(${u.id})" title="Alternar estado">
        ${u.activo ? '<i class="fa-solid fa-circle-check" style="color:#16a34a;" title="Activa"></i>' : '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;" title="Inactiva"></i>'}
      </td>
      <td><div class="actions">
        <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editUbicacion(${u.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
        <button class="btn btn-sm ${u.activo ? 'btn-outline-danger' : 'btn-outline-success'}" onclick="WMS_MODULES.maestro.toggleUbiStatus(${u.id})" title="${u.activo ? 'Bloquear Ubicación' : 'Activar Ubicación'}">
          <i class="fa-solid ${u.activo ? 'fa-lock' : 'fa-lock-open'}"></i>
        </button>
        <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteUbi(${u.id})" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
      </div></td>
    </tr>`).join('') || '<tr><td colspan="11" class="table-empty">Sin ubicaciones</td></tr>';
  },

  filterUbicaciones() {
    const search = document.getElementById('f-ubi-search')?.value.toLowerCase().trim();
    const pas    = document.getElementById('f-ubi-pas')?.value;
    const mod    = document.getElementById('f-ubi-mod')?.value;
    const niv    = document.getElementById('f-ubi-niv')?.value;

    // Si el usuario intenta filtrar y no hay datos cargados, cargarlos automáticamente
    if (!this._ubiData || this._ubiData.length === 0) {
       if (search.length > 1 || pas || mod || niv) {
          this.show_ubicaciones(true);
          return;
       }
    }

    const rows = document.querySelectorAll('#ubi-tbody .ubi-row');
    let visibleCount = 0;

    rows.forEach(r => {
      const id = parseInt(r.getAttribute('data-id'));
      const u  = this._ubiData.find(x => x.id === id);
      if (!u) return;

      let match = true;
      if (pas && u.pasillo !== pas) match = false;
      if (mod && u.modulo !== mod)   match = false;
      if (niv && u.nivel !== niv)    match = false;
      
      if (match && search) {
         const content = [u.codigo, u.zona, u.pasillo, u.modulo, u.nivel, u.tipo_ubicacion].join(' ').toLowerCase();
         if (!content.includes(search)) match = false;
      }

      r.style.display = match ? '' : 'none';
      if (match) visibleCount++;
    });

    const countEl = document.getElementById('ubi-count');
    if (countEl) countEl.textContent = visibleCount;
  },

  _updateUbiDynamicFilters() {
    const zonaVal = document.getElementById('f-ubi-zona')?.value || '';
    const data = zonaVal
      ? (this._ubiData || []).filter(u => u.zona === zonaVal)
      : (this._ubiData || []);

    const pasillos = [...new Set(data.map(u => u.pasillo).filter(Boolean))].sort();
    const modulos  = [...new Set(data.map(u => u.modulo).filter(Boolean))].sort();
    const niveles  = [...new Set(data.map(u => u.nivel).filter(Boolean))].sort();

    const populate = (id, label, values) => {
      const el = document.getElementById(id);
      if (!el) return;
      const cur = el.value;
      el.innerHTML = `<option value="">${label}</option>` +
        values.map(v => `<option value="${WMS.esc(v)}"${v === cur ? ' selected' : ''}>${WMS.esc(v)}</option>`).join('');
    };

    populate('f-ubi-pas', 'Pasillo...', pasillos);
    populate('f-ubi-mod', 'Módulo...', modulos);
    populate('f-ubi-niv', 'Nivel...', niveles);
  },

  // FIX: formulario corregido con campos reales de la BD (zona NOT NULL, tipo_ubicacion ENUM, capacidad_maxima)
  async nuevaUbicacion() {
    const [ss, zs] = await Promise.all([
      API.get('/param/sucursales?activo=1'),
      API.get('/param/zonas')
    ]);
    const suc = ss.data || ss || [];
    const zonas = zs.data || zs || [];
    WMS.showModal('Nueva Ubicación', `
      <div class="form-grid form-grid-3">
        <div class="form-group"><label class="form-label">ZONA <span class="required">*</span></label>
          <div style="display: flex; gap: 5px;">
            <select id="f-uzona" class="form-control" style="flex: 1;">
              <option value="">-- Seleccione zona --</option>
              ${zonas.map(z => `<option value="${WMS.esc(z.codigo)}">${WMS.esc(z.codigo)} ${z.descripcion ? '- ' + WMS.esc(z.descripcion) : ''}</option>`).join('')}
            </select>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.maestro.nuevaZona()" title="Agregar nueva zona">
              <i class="fa-solid fa-plus"></i>
            </button>
          </div>
        </div>
        <div class="form-group"><label class="form-label">PASILLO <span class="required">*</span></label><input id="f-upas" class="form-control" placeholder="01"></div>
        <div class="form-group"><label class="form-label">MÓDULO</label><input id="f-umod" class="form-control" placeholder="A"></div>
        <div class="form-group"><label class="form-label">NIVEL</label><input id="f-univ" class="form-control" placeholder="1"></div>
        <div class="form-group"><label class="form-label">TIPO <span class="required">*</span></label>
          <select id="f-utip" class="form-control">
            <option value="Almacenamiento">Almacenamiento</option>
            <option value="Picking">Picking</option>
            <option value="Muelle">Muelle</option>
            <option value="Carro">Carro</option>
            <option value="Patio">Patio</option>
          </select></div>
        <div class="form-group"><label class="form-label">CLASE</label>
          <select id="f-ucla" class="form-control">
            <option value="Normal">Normal</option>
            <option value="Vencidos">Vencidos</option>
            <option value="Averias">Averias</option>
            <option value="Muestras">Muestras</option>
          </select></div>
        <div class="form-group"><label class="form-label">CAPACIDAD MÁX. (uds)</label><input id="f-ucap" class="form-control" type="number" placeholder="100"></div>
        <div class="form-group"><label class="form-label">CAPACIDAD VOL. (m3)</label><input id="f-um3" class="form-control" type="number" step="0.001" placeholder="0.000"></div>
        <div class="form-group" style="grid-column:1/-1;"><label class="form-label">SUCURSAL <span class="required">*</span></label>
          <select id="f-usuc" class="form-control"><option value="">-- Seleccione --</option>
            ${suc.map(s => `<option value="${s.id}">${WMS.esc(s.nombre)}</option>`).join('')}
          </select></div>
        <div class="form-group" style="grid-column:1/-1; margin-top:10px;">
           <label class="form-label" style="display:flex;align-items:center;cursor:pointer;gap:10px;">
              <input type="checkbox" id="f-uact" checked style="width:18px;height:18px;">
              <span>Ubicación Activa (Habilitada para operaciones)</span>
           </label>
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveUbicacion(null)"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  // FIX: campos corregidos para coincidir exactamente con la BD y el controller
  async saveUbicacion(id) {
    const zona = document.getElementById('f-uzona')?.value.trim();
    const pas  = document.getElementById('f-upas')?.value.trim();
    const mod  = document.getElementById('f-umod')?.value.trim();
    const niv  = document.getElementById('f-univ')?.value.trim();
    const data = {
      zona:            zona,
      pasillo:         pas,
      modulo:          mod,
      nivel:           niv,
      codigo:          zona + '/' + [pas, mod, niv].filter(Boolean).join('-'),
      tipo_ubicacion:  document.getElementById('f-utip')?.value,
      clase:           document.getElementById('f-ucla')?.value,
      capacidad_maxima: parseInt(document.getElementById('f-ucap')?.value) || 0,
      m3:              parseFloat(document.getElementById('f-um3')?.value) || 0,
      sucursal_id:     document.getElementById('f-usuc')?.value,
      activo:          document.getElementById('f-uact')?.checked ? 1 : 0
    };
    if (!zona) { WMS.toast('warning', 'Debe seleccionar una zona'); return; }
    if (!pas)  { WMS.toast('warning', 'El pasillo es obligatorio'); return; }
    if (!data.sucursal_id) { WMS.toast('warning', 'Debe seleccionar una sucursal'); return; }
    const r = id ? await API.put('/param/ubicaciones/' + id, data) : await API.post('/param/ubicaciones', data);
    if (r.error) WMS.toast('error', r.message);
    else { WMS.toast('success', 'Ubicación guardada'); WMS.closeModal('generic-modal'); this.show_ubicaciones(); }
  },

  _importFile: null,

  importarUbicaciones() {
    WMS.showModal('Importar Ubicaciones', `
      <div class="alert alert-info" style="margin-bottom:15px; font-size:.85rem;">
        <i class="fa-solid fa-info-circle"></i> <strong>Instrucciones:</strong><br>
        1. El archivo debe ser CSV separado por punto y coma (;) o coma (,).<br>
        2. Columnas soportadas: <strong>zona, pasillo, modulo, nivel, posicion, tipo_ubicacion, capacidad_maxima, codigo</strong><br>
        3. Si no incluye "codigo", se genera automáticamente: zona/pasillo-modulo-nivel<br>
        4. Primero se muestra una vista previa para verificar antes de importar.
      </div>
      <div class="form-group">
        <label class="form-label">Seleccione el archivo CSV</label>
        <input type="file" id="f-import-csv" class="form-control" accept=".csv,.txt">
      </div>
      <div id="import-progress" style="display:none; margin-top:15px;">
        <div class="spinner sm"></div> <span id="import-progress-text">Analizando archivo...</span>
      </div>
      <div id="import-preview-area" style="display:none; margin-top:15px;"></div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" id="btn-import-preview" onclick="WMS_MODULES.maestro.previewImportUbicaciones()"><i class="fa-solid fa-eye"></i> Vista Previa</button>
       <button class="btn btn-success" id="btn-import-confirm" style="display:none;" onclick="WMS_MODULES.maestro.doImportUbicaciones()"><i class="fa-solid fa-upload"></i> Confirmar Importación</button>`);
  },

  async previewImportUbicaciones() {
    const file = document.getElementById('f-import-csv')?.files[0];
    if (!file) { WMS.toast('warning', 'Seleccione un archivo'); return; }
    this._importFile = file;

    const progress = document.getElementById('import-progress');
    const progressText = document.getElementById('import-progress-text');
    progress.style.display = 'flex';
    progressText.textContent = 'Analizando archivo...';

    const formData = new FormData();
    formData.append('file', file);

    try {
      const r = await fetch(API_BASE + '/param/import-export/preview/ubicaciones', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('wms_token') },
        body: formData
      });
      const res = await r.json();
      progress.style.display = 'none';

      if (res.error) { WMS.toast('error', res.message); return; }

      const area = document.getElementById('import-preview-area');
      const stats = res.stats;
      const preview = res.preview || [];
      const headers = res.headers || [];
      const errors = res.errors || [];

      let html = `
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:15px;">
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:10px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:900;color:#1d4ed8;">${stats.total}</div>
            <div style="font-size:.75rem;color:#64748b;">Total filas</div>
          </div>
          <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:4px;padding:10px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:900;color:#059669;">${stats.nuevos}</div>
            <div style="font-size:.75rem;color:#64748b;">Nuevas</div>
          </div>
          <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:4px;padding:10px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:900;color:#ea580c;">${stats.existentes}</div>
            <div style="font-size:.75rem;color:#64748b;">Actualizarán</div>
          </div>
          <div style="background:${stats.errores ? '#fef2f2' : '#f8fafc'};border:1px solid ${stats.errores ? '#fecaca' : '#e2e8f0'};border-radius:4px;padding:10px;text-align:center;">
            <div style="font-size:1.4rem;font-weight:900;color:${stats.errores ? '#dc2626' : '#94a3b8'};">${stats.errores}</div>
            <div style="font-size:.75rem;color:#64748b;">Con errores</div>
          </div>
        </div>`;

      if (errors.length) {
        html += `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:10px;margin-bottom:12px;max-height:120px;overflow-y:auto;">
          <div style="font-weight:700;color:#dc2626;font-size:.82rem;margin-bottom:6px;"><i class="fa-solid fa-triangle-exclamation"></i> Errores detectados:</div>
          ${errors.map(e => `<div style="font-size:.78rem;color:#991b1b;padding:2px 0;">• ${WMS.esc(e)}</div>`).join('')}
        </div>`;
      }

      html += `<div style="font-weight:700;font-size:.82rem;color:#475569;margin-bottom:8px;"><i class="fa-solid fa-table"></i> Vista previa de datos (máx. 500 filas)</div>`;
      html += `<div style="max-height:350px;overflow:auto;border:1px solid #e2e8f0;border-radius:4px;">
        <table class="table" style="font-size:.75rem;margin:0;">
          <thead style="position:sticky;top:0;background:#f1f5f9;z-index:1;">
            <tr>
              <th style="padding:6px 8px;white-space:nowrap;">Línea</th>
              <th style="padding:6px 8px;white-space:nowrap;">Estado</th>
              ${headers.map(h => `<th style="padding:6px 8px;white-space:nowrap;">${WMS.esc(h)}</th>`).join('')}
              <th style="padding:6px 8px;">Observaciones</th>
            </tr>
          </thead>
          <tbody>`;

      preview.forEach(row => {
        const bg = row.estado === 'error' ? '#fef2f2' : row.estado === 'existente' ? '#fff7ed' : '';
        const badge = row.estado === 'nuevo'
          ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;">NUEVO</span>'
          : row.estado === 'existente'
          ? '<span style="background:#ffedd5;color:#9a3412;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;">ACTUALIZAR</span>'
          : '<span style="background:#fecaca;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;">ERROR</span>';

        html += `<tr style="background:${bg};">
          <td style="padding:4px 8px;text-align:center;">${row.linea}</td>
          <td style="padding:4px 8px;text-align:center;">${badge}</td>`;
        headers.forEach(h => {
          html += `<td style="padding:4px 8px;white-space:nowrap;">${WMS.esc(row.datos[h] || '')}</td>`;
        });
        html += `<td style="padding:4px 8px;color:#dc2626;font-size:.72rem;">${row.errores.map(e => WMS.esc(e)).join(', ')}</td></tr>`;
      });

      html += `</tbody></table></div>`;

      if (stats.errores === stats.total) {
        html += `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:12px;margin-top:12px;text-align:center;color:#dc2626;font-weight:700;">
          Todas las filas tienen errores. Corrija el archivo y vuelva a intentar.
        </div>`;
      }

      area.innerHTML = html;
      area.style.display = 'block';

      document.getElementById('btn-import-preview').style.display = 'none';
      if (stats.errores < stats.total) {
        document.getElementById('btn-import-confirm').style.display = '';
      }
    } catch (e) {
      progress.style.display = 'none';
      WMS.toast('error', 'Error al analizar archivo');
    }
  },

  async doImportUbicaciones() {
    if (!this._importFile) { WMS.toast('warning', 'No hay archivo cargado'); return; }

    const btnConfirm = document.getElementById('btn-import-confirm');
    if (btnConfirm) { btnConfirm.disabled = true; btnConfirm.innerHTML = '<div class="spinner sm"></div> Importando...'; }

    const formData = new FormData();
    formData.append('file', this._importFile);

    try {
      const r = await fetch(API_BASE + '/param/import-export/upload/ubicaciones', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('wms_token') },
        body: formData
      });
      const res = await r.json();

      if (res.error) {
        WMS.toast('error', res.message);
        if (btnConfirm) { btnConfirm.disabled = false; btnConfirm.innerHTML = '<i class="fa-solid fa-upload"></i> Confirmar Importación'; }
        return;
      }

      const s = res.data || {};
      const area = document.getElementById('import-preview-area');
      area.innerHTML = `
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:20px;text-align:center;">
          <div style="font-size:2rem;margin-bottom:8px;"><i class="fa-solid fa-circle-check" style="color:#059669;"></i></div>
          <div style="font-size:1.1rem;font-weight:700;color:#065f46;margin-bottom:12px;">Importación completada</div>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;max-width:500px;margin:0 auto;">
            <div style="background:#fff;border-radius:4px;padding:8px;">
              <div style="font-weight:900;font-size:1.2rem;color:#1e293b;">${s.total || 0}</div>
              <div style="font-size:.72rem;color:#64748b;">Total</div>
            </div>
            <div style="background:#fff;border-radius:4px;padding:8px;">
              <div style="font-weight:900;font-size:1.2rem;color:#059669;">${s.creados || 0}</div>
              <div style="font-size:.72rem;color:#64748b;">Creadas</div>
            </div>
            <div style="background:#fff;border-radius:4px;padding:8px;">
              <div style="font-weight:900;font-size:1.2rem;color:#ea580c;">${s.actualizados || 0}</div>
              <div style="font-size:.72rem;color:#64748b;">Actualizadas</div>
            </div>
            <div style="background:#fff;border-radius:4px;padding:8px;">
              <div style="font-weight:900;font-size:1.2rem;color:#dc2626;">${s.omitiendo || 0}</div>
              <div style="font-size:.72rem;color:#64748b;">Omitidas</div>
            </div>
          </div>
          ${(s.errors && s.errors.length) ? `
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:10px;margin-top:12px;text-align:left;max-height:150px;overflow-y:auto;">
              <div style="font-weight:700;color:#dc2626;font-size:.8rem;margin-bottom:4px;">Detalle de errores:</div>
              ${s.errors.map(e => `<div style="font-size:.75rem;color:#991b1b;padding:2px 0;">• ${WMS.esc(e)}</div>`).join('')}
            </div>` : ''}
        </div>`;

      if (btnConfirm) btnConfirm.style.display = 'none';
      this._importFile = null;

      setTimeout(() => this.show_ubicaciones(), 3000);
    } catch (e) {
      WMS.toast('error', 'Error al importar');
      if (btnConfirm) { btnConfirm.disabled = false; btnConfirm.innerHTML = '<i class="fa-solid fa-upload"></i> Confirmar Importación'; }
    }
  },

  // FIX: editUbicacion nuevo — carga datos reales y pre-llena modal
  async editUbicacion(id) {
    try {
      const [ur, ss, zs] = await Promise.all([
        API.get('/param/ubicaciones'), 
        API.get('/param/sucursales?activo=1'),
        API.get('/param/zonas')
      ]);
      const items    = ur.data || ur || [];
      let   suc      = ss.data || ss || [];
      const zonas    = zs.data || zs || [];
      const u        = items.find(x => x.id == id);
      if (!u) { WMS.toast('error', 'Ubicación no encontrada'); return; }

      // Asegurar que la sucursal actual de la ubicación esté en la lista (si estaba inactiva)
      if (u.sucursal_id) {
        const fullSuc = await API.get('/param/sucursales'); // Opcional: optimizable
        const sActual = (fullSuc.data || fullSuc || []).find(s => s.id == u.sucursal_id);
        if (sActual && !suc.find(x => x.id == sActual.id)) suc.push(sActual);
      }
      WMS.showModal('Editar Ubicación', `
        <div class="form-grid form-grid-3">
          <div class="form-group"><label class="form-label">ZONA <span class="required">*</span></label>
            <div style="display: flex; gap: 5px;">
              <select id="f-uzona" class="form-control" style="flex: 1;">
                <option value="">-- Seleccione zona --</option>
                ${zonas.map(z => `<option value="${WMS.esc(z.codigo)}" ${u.zona === z.codigo ? 'selected' : ''}>${WMS.esc(z.codigo)} ${z.descripcion ? '- ' + WMS.esc(z.descripcion) : ''}</option>`).join('')}
              </select>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.maestro.nuevaZona()" title="Agregar nueva zona">
                <i class="fa-solid fa-plus"></i>
              </button>
            </div>
          </div>
          <div class="form-group"><label class="form-label">PASILLO <span class="required">*</span></label><input id="f-upas" class="form-control" value="${WMS.esc(u.pasillo || '')}"></div>
          <div class="form-group"><label class="form-label">MÓDULO</label><input id="f-umod" class="form-control" value="${WMS.esc(u.modulo || '')}"></div>
          <div class="form-group"><label class="form-label">NIVEL</label><input id="f-univ" class="form-control" value="${WMS.esc(u.nivel || '')}"></div>
          <div class="form-group"><label class="form-label">TIPO <span class="required">*</span></label>
            <select id="f-utip" class="form-control">
              ${['Almacenamiento', 'Picking', 'Muelle', 'Carro', 'Patio'].map(t => `<option ${u.tipo_ubicacion === t ? 'selected' : ''}>${t}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">CLASE</label>
            <select id="f-ucla" class="form-control">
              ${['Normal', 'Vencidos', 'Averias', 'Muestras'].map(cl => `<option ${u.clase === cl ? 'selected' : ''} value="${cl}">${cl}</option>`).join('')}
            </select></div>
          <div class="form-group"><label class="form-label">CAPACIDAD MÁX. (uds)</label><input id="f-ucap" class="form-control" type="number" value="${u.capacidad_maxima || 0}"></div>
          <div class="form-group"><label class="form-label">CAPACIDAD VOL. (m3)</label><input id="f-um3" class="form-control" type="number" step="0.001" value="${u.m3 || 0}"></div>
          <div class="form-group" style="grid-column:1/-1;"><label class="form-label">SUCURSAL <span class="required">*</span></label>
            <select id="f-usuc" class="form-control">
              ${suc.map(s => `<option value="${s.id}" ${s.id == u.sucursal_id ? 'selected' : ''}>${WMS.esc(s.nombre)}</option>`).join('')}
            </select></div>
          <div class="form-group" style="grid-column:1/-1; margin-top:10px;">
             <label class="form-label" style="display:flex;align-items:center;cursor:pointer;gap:10px;">
                <input type="checkbox" id="f-uact" ${u.activo ? 'checked' : ''} style="width:18px;height:18px;">
                <span>Ubicación Activa (Habilitada para operaciones)</span>
             </label>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveUbicacion(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch (ex) { WMS.toast('error', 'Error cargando ubicación'); }
  },

  deleteUbi(id) {
    WMS.confirm('Eliminar Ubicación', '¿Eliminar esta ubicación?', async () => {
      const r = await API.delete('/param/ubicaciones/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Eliminada'); this.show_ubicaciones(); }
    });
  },

  async toggleUbiStatus(id) {
    const r = await API.patch(`/param/ubicaciones/${id}/toggle`);
    if (r.error) WMS.toast('error', r.message);
    else {
      WMS.toast('success', r.message);
      this.show_ubicaciones();
    }
  },

  nuevaZona() {
    this.editZona(null, 'ubicacion');
  },

  async editZona(id, origin = 'maestro') {
    let z = null;
    if (id) {
      try {
        const r = await API.get('/param/zonas');
        const zonas = r.data || r || [];
        z = zonas.find(x => x.id == id);
      } catch (e) { return WMS.toast('error', 'Error al obtener zona'); }
    }
    WMS.showModal(id ? 'Editar Zona' : 'Nueva Zona', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">CÓDIGO <span class="required">*</span></label><input id="f-zcod" class="form-control" value="${WMS.esc(z?.codigo || '')}" placeholder="Ej: CEDI, A, FRIA..." maxlength="20" style="text-transform:uppercase;" required></div>
        <div class="form-group"><label class="form-label">DESCRIPCIÓN</label><input id="f-zdesc" class="form-control" value="${WMS.esc(z?.descripcion || '')}" placeholder="Descripción opcional"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveZona(${id || 'null'}, '${origin}')"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveZona(id, origin = 'maestro') {
    const data = {
      codigo: document.getElementById('f-zcod')?.value.trim().toUpperCase(),
      descripcion: document.getElementById('f-zdesc')?.value.trim(),
    };
    if (!data.codigo) { WMS.toast('warning', 'El código es obligatorio'); return; }
    try {
      const r = id ? await API.put('/param/zonas/' + id, data) : await API.post('/param/zonas', data);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', id ? 'Zona actualizada' : 'Zona creada: ' + data.codigo);
        WMS.closeModal('generic-modal');
        if (origin === 'ubicacion') {
           this.nuevaUbicacion();
        } else {
           this.show_zonas();
        }
      }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async deleteZona(id, codigo) {
    if (!confirm('¿Seguro que desea eliminar la zona ' + codigo + '?')) return;
    try {
      const r = await API.delete('/param/zonas/' + id);
      if (r.error) WMS.toast('error', r.message);
      else {
        WMS.toast('success', 'Zona eliminada');
        this.show_zonas();
      }
    } catch (e) { WMS.toast('error', 'Error al eliminar'); }
  },
  // ── PROVEEDORES ──────────────────────────────────────────────
  filtrarProveedores(q) {
    if (!this._proveedoresData) return;
    const f = q.toLowerCase();
    this.renderProveedores(f
      ? this._proveedoresData.filter(c => c.razon_social?.toLowerCase().includes(f) || c.nit?.includes(f))
      : this._proveedoresData);
  },

  async show_proveedores() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-proveedores" placeholder="Buscar por NIT o Razón Social..." oninput="WMS_MODULES.maestro.filtrarProveedores(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_proveedores()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevoProveedor()"><i class="fa-solid fa-plus"></i> Nuevo Proveedor</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/proveedores');
      this._proveedoresData = r.data || r || [];
      this.renderProveedores(this._proveedoresData);
    } catch (e) { WMS.setContent('<div class="m-empty">Error de conexión</div>'); }
  },

  renderProveedores(items) {
    WMS.setContent(`
        <div class="card">
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>NIT</th><th>Razón Social</th><th>Teléfono</th><th>Email</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>${items.length === 0
                ? '<tr><td colspan="6" class="table-empty" style="text-align:center; padding:30px;">Sin proveedores registrados</td></tr>'
                : items.map(e => `<tr class="main-row">
                    <td><span class="badge badge-info" style="border-radius:4px; font-family:monospace;">${WMS.esc(e.nit || '')}</span></td>
                    <td style="font-weight:600; color:#1e293b;">${WMS.esc(e.razon_social || '')}</td>
                    <td style="color:#64748b;">${WMS.esc(e.telefono || '-')}</td>
                    <td style="color:#64748b;">${WMS.esc(e.email || '-')}</td>
                    <td><span class="status-chip ${e.activo ? 'status-cerrada' : 'status-cancelada'}" style="border-radius:4px;">${e.activo ? 'Activo' : 'Inactivo'}</span></td>
                    <td><div class="actions">
                      <button class="btn btn-sm btn-secondary" style="border-radius:4px;" onclick="WMS_MODULES.maestro.editProveedor(${e.id})"><i class="fa-solid fa-pen"></i></button>
                      ${(typeof _wmsUser !== 'undefined' && _wmsUser?.rol === 'SuperAdmin') ? `<button class="btn btn-sm btn-danger" style="border-radius:4px;" onclick="WMS_MODULES.maestro.deleteProveedor(${e.id},'${WMS.esc(e.razon_social || '')}')"><i class="fa-solid fa-trash"></i></button>` : ''}
                    </div></td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>`);
  },

  nuevoProveedor() {
    WMS.showModal('Nuevo Proveedor', `
      <div style="display:flex; flex-direction:column; gap:16px;">
        <div class="form-group"><label class="form-label">NIT <span class="required" style="color:#ef4444;">*</span></label><input id="f-pnit" class="form-control" placeholder="Ej: 900000001-1"></div>
        <div class="form-group"><label class="form-label">RAZÓN SOCIAL <span class="required" style="color:#ef4444;">*</span></label><input id="f-prs" class="form-control" placeholder="Nombre del proveedor"></div>
        <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-pdir" class="form-control" placeholder="Dirección"></div>
        <div class="form-group"><label class="form-label">TELÉFONO</label><input id="f-ptel" class="form-control" placeholder="Teléfono"></div>
        <div class="form-group"><label class="form-label">EMAIL</label><input id="f-pemail" class="form-control" type="email" placeholder="contacto@proveedor.com"></div>
        <div class="form-group" style="display:none;"><input type="checkbox" id="f-pact" checked></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveProveedor(null)"><i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async saveProveedor(id) {
    const data = {
      nit:          document.getElementById('f-pnit')?.value.trim(),
      razon_social: document.getElementById('f-prs')?.value.trim(),
      direccion:    document.getElementById('f-pdir')?.value.trim(),
      telefono:     document.getElementById('f-ptel')?.value.trim(),
      email:        document.getElementById('f-pemail')?.value.trim(),
      activo:       document.getElementById('f-pact') ? (document.getElementById('f-pact').checked ? 1 : 0) : 1
    };
    if (!data.nit || !data.razon_social) { WMS.toast('warning', 'NIT y Razón Social son obligatorios'); return; }
    try {
      const r = id ? await API.put('/param/proveedores/' + id, data) : await API.post('/param/proveedores', data);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', id ? 'Proveedor actualizado' : 'Proveedor creado'); WMS.closeModal('generic-modal'); this.show_proveedores(); }
    } catch (e) { WMS.toast('error', 'Error de conexión'); }
  },

  async editProveedor(id) {
    try {
      const e = this._proveedoresData?.find(x => x.id == id);
      if (!e) return;
      
      WMS.showModal('Editar Proveedor', `
        <div style="display:flex; flex-direction:column; gap:16px;">
          <div class="form-group"><label class="form-label">NIT <span class="required" style="color:#ef4444;">*</span></label><input id="f-pnit" class="form-control" value="${WMS.esc(e.nit || '')}"></div>
          <div class="form-group"><label class="form-label">RAZÓN SOCIAL <span class="required" style="color:#ef4444;">*</span></label><input id="f-prs" class="form-control" value="${WMS.esc(e.razon_social || '')}"></div>
          <div class="form-group"><label class="form-label">DIRECCIÓN</label><input id="f-pdir" class="form-control" value="${WMS.esc(e.direccion || '')}"></div>
          <div class="form-group"><label class="form-label">TELÉFONO</label><input id="f-ptel" class="form-control" value="${WMS.esc(e.telefono || '')}"></div>
          <div class="form-group"><label class="form-label">EMAIL</label><input id="f-pemail" class="form-control" value="${WMS.esc(e.email || '')}"></div>
          <div class="form-group" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:4px; margin-top:10px;">
             <div>
               <span style="font-weight:600; color:#334155; font-size:0.9rem; display:block;">Estado Activo</span>
             </div>
             <label class="wms-switch"><input type="checkbox" id="f-pact" ${e.activo ? 'checked' : ''}><span class="slider"></span></label>
          </div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveProveedor(${id})"><i class="fa-solid fa-save"></i> Guardar</button>`);
    } catch (ex) { WMS.toast('error', 'Error cargando datos'); }
  },

  deleteProveedor(id, n) {
    WMS.confirm('Eliminar Proveedor', `¿Seguro que desea eliminar a "${WMS.esc(n)}"?`, async () => {
      try {
        const r = await API.delete('/param/proveedores/' + id);
        if (r.error) WMS.toast('error', r.message);
        else { WMS.toast('success', 'Proveedor eliminado'); this.show_proveedores(); }
      } catch(e) { WMS.toast('error', 'Error al eliminar'); }
    });
  },

  // ── RUTAS ────────────────────────────────────────────────────
  filtrarRutas(q) {
    if (!this._rutasData) return;
    const f = q.toLowerCase();
    this.renderRutas(f
      ? this._rutasData.filter(r => r.nombre?.toLowerCase().includes(f) || r.comercial?.toLowerCase().includes(f))
      : this._rutasData);
  },

  async show_rutas() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-rutas" placeholder="Buscar ruta o comercial..." oninput="WMS_MODULES.maestro.filtrarRutas(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_rutas()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevaRuta()"><i class="fa-solid fa-plus"></i> Nueva Ruta</button>
      </div>`);
    WMS.spinner();
    try {
      const r = await API.get('/param/rutas');
      this._rutasData = r.data || r || [];
      this.renderRutas(this._rutasData);
    } catch (e) { WMS.setContent('<div class="m-empty">Error cargando rutas</div>'); }
  },

  renderRutas(items) {
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-route"></i> Rutas (${items.length})</span></div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr><th>Nombre</th><th>Comercial</th><th>Clientes</th><th>Acciones</th></tr></thead>
            <tbody>${items.map(r => `<tr>
              <td><strong>${WMS.esc(r.nombre || '')}</strong></td>
              <td style="font-size:.82rem;">${WMS.esc(r.comercial || '-')}</td>
              <td><span class="badge badge-gray">${r.clientes_count ?? '—'}</span></td>
              <td><div class="actions">
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editRuta(${r.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteRuta(${r.id},'${WMS.esc(r.nombre || '')}')"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>`).join('') || '<tr><td colspan="4" class="table-empty">Sin rutas registradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  nuevaRuta() {
    WMS.showModal('Nueva Ruta', `
      <div class="form-grid form-grid-2">
        <div class="form-group"><label class="form-label">NOMBRE <span class="required">*</span></label>
          <input id="f-rnom" class="form-control" placeholder="RUTA-01 o Norte"></div>
        <div class="form-group"><label class="form-label">COMERCIAL</label>
          <input id="f-rcom" class="form-control" placeholder="Asesor responsable"></div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveRuta(null)"><i class="fa-solid fa-save"></i> Guardar Ruta</button>`);
  },

  async saveRuta(id) {
    const nombre = document.getElementById('f-rnom')?.value.trim();
    const comerc = document.getElementById('f-rcom')?.value.trim() || '';
    if (!nombre) { WMS.toast('warning','El nombre es obligatorio'); return; }
    try {
      const r = id
        ? await API.put('/param/rutas/'+id, { nombre, comercial: comerc })
        : await API.post('/param/rutas', { nombre, comercial: comerc });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success','Ruta guardada');
      WMS.closeModal('generic-modal');
      this.show_rutas();
    } catch(e) { WMS.toast('error','Error guardando ruta'); }
  },

  async editRuta(id) {
    try {
      const r  = await API.get('/param/rutas');
      const rt = (r.data||r||[]).find(x => x.id == id);
      if (!rt) { WMS.toast('error','Ruta no encontrada'); return; }
      WMS.showModal('Editar Ruta', `
        <div class="form-grid form-grid-2">
          <div class="form-group"><label class="form-label">NOMBRE <span class="required">*</span></label>
            <input id="f-rnom" class="form-control" value="${WMS.esc(rt.nombre||'')}"></div>
          <div class="form-group"><label class="form-label">COMERCIAL</label>
            <input id="f-rcom" class="form-control" value="${WMS.esc(rt.comercial||'')}"></div>
        </div>`,
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveRuta(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch(e) { WMS.toast('error','Error cargando ruta'); }
  },

  deleteRuta(id, n) {
    WMS.confirm('Eliminar Ruta', `¿Eliminar "${WMS.esc(n)}"? Los clientes asociados quedarán sin ruta.`, async () => {
      try {
        const r = await API.delete('/param/rutas/'+id);
        if (r.error) WMS.toast('error', r.message);
        else { WMS.toast('success','Ruta eliminada'); this.show_rutas(); }
      } catch(e) { WMS.toast('error','Error eliminando ruta'); }
    });
  },

  // Helpers compartidos de frecuencia (usados por formulario de clientes)
  _switchFrecTipo(tipo) {
    document.getElementById('f-cfrec-tipo').value = tipo;
    const btnD = document.getElementById('btn-cfrec-diario');
    const btnP = document.getElementById('btn-cfrec-parcial');
    if (btnD) { btnD.style.background = tipo==='Diario'?'#1e40af':'#f8fafc'; btnD.style.color = tipo==='Diario'?'#fff':'#374151'; btnD.style.fontWeight = tipo==='Diario'?'700':'400'; }
    if (btnP) { btnP.style.background = tipo==='Parcial'?'#d97706':'#f8fafc'; btnP.style.color = tipo==='Parcial'?'#fff':'#374151'; btnP.style.fontWeight = tipo==='Parcial'?'700':'400'; }
    const pd = document.getElementById('cpanel-frec-diario');
    const pp = document.getElementById('cpanel-frec-parcial');
    if (pd) pd.style.display = tipo==='Diario'  ? '' : 'none';
    if (pp) pp.style.display = tipo==='Parcial' ? '' : 'none';
  },

  _switchSubtipo(sub) {
    document.getElementById('f-cfrec-subtipo').value = sub;
    ['diario','semanal','quincenal','mensual'].forEach(s => {
      const btn = document.getElementById('cbtn-sub-'+s);
      if (!btn) return;
      const active = s === sub.toLowerCase();
      const colMap = {diario:'#f59e0b',semanal:'#3b82f6',quincenal:'#8b5cf6',mensual:'#10b981'};
      const col = colMap[s];
      btn.style.borderColor = active ? col : '#e2e8f0';
      btn.style.background  = active ? col+'22' : '#fff';
      btn.style.color       = active ? col : '#374151';
      btn.style.fontWeight  = active ? '700' : '400';
    });
    ['semanal','mensual','quincenal'].forEach(s => {
      const el = document.getElementById('csub-panel-'+s);
      if (el) el.style.display = (s === sub.toLowerCase()) ? '' : 'none';
    });
  },

  // ── PERMISOS POR ROL ─────────────────────────────────────────
  async show_permisos() {
    WMS.setBreadcrumb('maestro', 'Permisos por Rol');
    WMS.setToolbar('');
    WMS.spinner();
    const roles = ['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'];
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-shield-halved"></i> Gestión de Permisos por Rol</span></div>
        <div class="card-body">
          <div class="view-tabs" id="perm-tabs">
            ${roles.map((r, i) => `<button class="view-tab${i === 0 ? ' active' : ''}" onclick="WMS_MODULES.maestro.loadPermisosRol('${r}',this)">${r}</button>`).join('')}
          </div>
          <div id="perm-matrix">Seleccione un rol para ver permisos...</div>
        </div>
      </div>`);
    this.loadPermisosRol('Admin', document.querySelector('.view-tab'));
  },

  async loadPermisosRol(rol, btn) {
    document.querySelectorAll('.view-tab').forEach(t => t.classList.remove('active'));
    btn?.classList.add('active');
    const el = document.getElementById('perm-matrix');
    if (!el) return;
    el.innerHTML = '<div class="spinner" style="margin:20px auto;"></div>';
    try {
      const r       = await API.get('/param/permisos-matriz/' + rol);
      const permisos = r.data || r || [];
      const grupos  = {};
      permisos.forEach(p => { if (!grupos[p.modulo]) grupos[p.modulo] = []; grupos[p.modulo].push(p); });
      el.innerHTML = `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:16px;">
        ${Object.entries(grupos).map(([mod, ps]) => `<div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-cube"></i> ${WMS.esc(mod)}</span></div>
          <div style="padding:8px 12px;">
            ${ps.map(p => `<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;">
              <span style="font-size:.8rem;">${WMS.esc(p.accion || p.submodulo || 'ver')}</span>
              <label class="wms-switch">
                <input type="checkbox" ${p.concedido ? 'checked' : ''} onchange="WMS_MODULES.maestro.togglePerm('${rol}',${p.permiso_id || p.id},this.checked,this)">
                <span class="slider"></span>
              </label>
            </div>`).join('')}
          </div>
        </div>`).join('')}
      </div>`;
    } catch (e) { el.innerHTML = '<div class="m-empty">Error cargando permisos</div>'; }
  },

  async togglePerm(rol, permisoId, concedido, inputEl) {
    try {
      const r = await API.post('/param/permisos-toggle', { rol, permiso_id: permisoId, concedido });
      if (r.error) {
        WMS.toast('error', r.message);
        if (inputEl) inputEl.checked = !concedido;
      } else {
        WMS.toast('success', concedido ? 'Permiso habilitado' : 'Permiso deshabilitado');
      }
    } catch (e) {
      WMS.toast('error', 'Error al guardar permiso');
      if (inputEl) inputEl.checked = !concedido;
    }
  },

  // FIX: show_permisos_usuario ahora muestra instrucción clara y enlaza correctamente
  // (se accede desde Personal → botón escudo, que llama a gestionarPermisos() directamente)
  show_permisos_usuario() {
    WMS.setBreadcrumb('maestro', 'Permisos por Usuario');
    WMS.setToolbar('');
    WMS.setContent(`
      <div class="m-empty">
        <i class="fa-solid fa-user-lock" style="font-size:2.5rem;margin-bottom:16px;color:#64748b;"></i>
        <p style="font-size:1rem;font-weight:600;">Gestión de Permisos Individuales</p>
        <p style="max-width:420px;text-align:center;color:#64748b;margin-bottom:16px;">
          Para gestionar los permisos de un usuario específico, vaya al módulo de Personal
          y haga clic en el ícono <i class="fa-solid fa-shield-halved"></i> de la persona correspondiente.
        </p>
        <button class="btn btn-primary" onclick="WMS.nav('maestro','personal')">
          <i class="fa-solid fa-users"></i> Ir a Personal
        </button>
      </div>`);
  },

  // ── CLIENTES ────────────────────────────────────────────────
  async show_clientes() {
    WMS.setToolbar(`
      <div class="search-bar"><i class="fa-solid fa-search"></i><input id="search-cli" placeholder="Buscar por NIT, razón social o ruta..." oninput="WMS_MODULES.maestro.filtrarClientes(this.value)"></div>
      <div class="actions" style="display:flex;gap:8px;">
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.show_clientes()" title="Actualizar"><i class="fa-solid fa-rotate"></i></button>
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro.importarGenerico('clientes')"><i class="fa-solid fa-file-import"></i> Importar</button>
        <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.nuevoCliente()"><i class="fa-solid fa-plus"></i> Nuevo Cliente</button>
      </div>`);
    WMS.spinner();
    try {
      const [rc, rr] = await Promise.all([
        API.get('/param/clientes'),
        API.get('/param/rutas'),
      ]);
      this._clientesData = rc.data || rc || [];
      this._rutasData    = rr.data || rr || [];
      this.renderClientes(this._clientesData);
    } catch (e) { WMS.setContent('<div class="m-empty">Error de conexión</div>'); }
  },

  renderClientes(items) {
    const mapsBtn = (c) => {
      if (!c.latitud || !c.longitud) return '';
      return `<a href="https://maps.google.com/?q=${c.latitud},${c.longitud}" target="_blank" class="btn btn-sm" style="background:#1a73e8;color:#fff;padding:3px 7px;" title="Ver en Google Maps"><i class="fa-solid fa-map-location-dot"></i></a>`;
    };
    const frecBadge = (c) => {
      if (!c.frecuencia) return '<span style="color:#94a3b8;font-size:.73rem;">—</span>';
      const color = c.frecuencia_tipo==='Diario' ? '#1e40af' : '#d97706';
      return `<span style="font-size:.72rem;font-weight:600;color:${color};white-space:nowrap;">${WMS.esc(c.frecuencia)}</span>`;
    };
    WMS.setContent(`
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-user-tie"></i> Clientes (${items.length})</span></div>
        <div class="table-container" style="overflow-x:auto;">
          <table class="erp-table">
            <thead><tr>
              <th>NIT</th><th>Razón Social</th><th>Ruta</th><th>Frecuencia</th>
              <th>Horario</th><th>Coords</th><th>Estado</th><th>Acciones</th>
            </tr></thead>
            <tbody>${items.map(c => `<tr>
              <td><span class="badge badge-gray" style="font-family:monospace;">${WMS.esc(c.nit||'')}</span></td>
              <td>
                <strong>${WMS.esc(c.razon_social||'')}</strong>
                ${c.contacto_nombre?`<br><span style="font-size:.73rem;color:#64748b;">${WMS.esc(c.contacto_nombre)}</span>`:''}
              </td>
              <td>${c.ruta?`<span class="badge badge-primary">${WMS.esc(c.ruta.nombre||'')}</span>`:'<span style="color:#f59e0b;font-size:.75rem;"><i class="fa-solid fa-triangle-exclamation"></i> Sin ruta</span>'}</td>
              <td>${frecBadge(c)}</td>
              <td style="font-size:.78rem;">${c.horario?`<span style="color:#1e40af;"><i class="fa-regular fa-clock"></i> ${WMS.esc(c.horario)}</span>`:'<span style="color:#94a3b8;">—</span>'}</td>
              <td>${c.latitud&&c.longitud?`<span style="font-size:.73rem;color:#16a34a;white-space:nowrap;"><i class="fa-solid fa-location-dot"></i> ${parseFloat(c.latitud).toFixed(4)}, ${parseFloat(c.longitud).toFixed(4)}</span>`:'<span style="color:#94a3b8;font-size:.73rem;">—</span>'}</td>
              <td><span class="status-chip ${c.activo?'status-cerrada':'status-cancelada'}">${c.activo?'Activo':'Inactivo'}</span></td>
              <td><div class="actions" style="gap:3px;">
                ${mapsBtn(c)}
                <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.editCliente(${c.id})" title="Editar"><i class="fa-solid fa-pen"></i></button>
                <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.deleteCliente(${c.id},'${WMS.esc(c.razon_social||'')}')"><i class="fa-solid fa-trash"></i></button>
              </div></td>
            </tr>`).join('')||'<tr><td colspan="8" class="table-empty">Sin clientes registrados</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  filtrarClientes(q) {
    if (!this._clientesData) return;
    const f = q.toLowerCase();
    this.renderClientes(f
      ? this._clientesData.filter(c =>
          c.razon_social?.toLowerCase().includes(f) ||
          c.nit?.includes(f) ||
          c.ruta?.nombre?.toLowerCase().includes(f))
      : this._clientesData);
  },

  _clienteModalBody(c = null, rutas = []) {
    const rutaOpts = rutas.map(r =>
      `<option value="${r.id}" ${c?.ruta_id==r.id?'selected':''}>${WMS.esc(r.nombre)}</option>`
    ).join('');
    const v = (field, def='') => c ? WMS.esc(c[field]||def) : def;

    // Frecuencia guardada del cliente
    const tipo    = c?.frecuencia_tipo || 'Diario';
    const cfg     = c?.frecuencia_config
      ? (typeof c.frecuencia_config === 'string' ? (() => { try { return JSON.parse(c.frecuencia_config); } catch(_){return {};} })() : c.frecuencia_config)
      : {};
    const DIAS_SEM = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    const diasSel  = cfg.dias || [];
    const diaSem   = cfg.dia  || '';
    const diaMes   = cfg.dia_mes || '';
    const subtipo  = cfg.subtipo || 'Diario';

    const checkDias = DIAS_SEM.map(d =>
      `<label style="display:flex;align-items:center;gap:5px;cursor:pointer;background:${diasSel.includes(d)?'#dbeafe':'#f8fafc'};border:1px solid ${diasSel.includes(d)?'#93c5fd':'#e2e8f0'};border-radius:4px;padding:5px 10px;font-size:.8rem;">
        <input type="checkbox" name="cfrec-dias" value="${d}" ${diasSel.includes(d)?'checked':''}
          onchange="this.parentElement.style.background=this.checked?'#dbeafe':'#f8fafc';this.parentElement.style.borderColor=this.checked?'#93c5fd':'#e2e8f0';"> ${d}
      </label>`
    ).join('');

    const radioSemana = DIAS_SEM.map(d =>
      `<label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.82rem;">
        <input type="radio" name="cfrec-dia-sem" value="${d}" ${diaSem===d?'checked':''}> ${d}
      </label>`
    ).join('');

    const numDias = Array.from({length:31},(_,i)=>i+1).map(n =>
      `<label style="width:34px;height:34px;border:1px solid ${diaMes==n?'#3b82f6':'#e2e8f0'};border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.75rem;font-weight:${diaMes==n?'700':'400'};background:${diaMes==n?'#eff6ff':'#fff'};">
        <input type="radio" name="cfrec-dia-mes" value="${n}" ${diaMes==n?'checked':''} style="display:none;"
          onchange="document.querySelectorAll('input[name=cfrec-dia-mes]').forEach(x=>{x.parentElement.style.background=x.checked?'#eff6ff':'#fff';x.parentElement.style.borderColor=x.checked?'#3b82f6':'#e2e8f0';x.parentElement.style.fontWeight=x.checked?'700':'400';})"> ${n}
      </label>`
    ).join('');

    return `<div class="form-grid form-grid-2">

      <div class="form-group"><label class="form-label">NIT <span class="required">*</span></label>
        <input id="f-cnit" class="form-control" placeholder="900.000.000-1" maxlength="70" value="${v('nit')}"></div>

      <div class="form-group"><label class="form-label">RAZÓN SOCIAL <span class="required">*</span></label>
        <input id="f-crs" class="form-control" placeholder="Nombre completo" value="${v('razon_social')}"></div>

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label"><i class="fa-solid fa-route" style="color:#1e40af;"></i> RUTA</label>
        <select id="f-cruta" class="form-control">
          <option value="">-- Sin ruta asignada --</option>
          ${rutaOpts}
        </select>
      </div>

      <!-- ── FRECUENCIA DE VISITA (por cliente) ────────────────── -->
      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label"><i class="fa-solid fa-calendar-days" style="color:#1e40af;"></i> FRECUENCIA DE VISITA <span class="required">*</span></label>
        <div style="display:flex;gap:0;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;margin-bottom:10px;">
          <button type="button" id="btn-cfrec-diario" onclick="WMS_MODULES.maestro._switchFrecTipo('Diario')"
            class="btn" style="flex:1;border-radius:0;border:none;padding:9px;font-size:.85rem;background:${tipo==='Diario'?'#1e40af':'#f8fafc'};color:${tipo==='Diario'?'#fff':'#374151'};font-weight:${tipo==='Diario'?'700':'400'};">
            <i class="fa-solid fa-calendar-days"></i> Diario
          </button>
          <button type="button" id="btn-cfrec-parcial" onclick="WMS_MODULES.maestro._switchFrecTipo('Parcial')"
            class="btn" style="flex:1;border-radius:0;border-left:1px solid #e2e8f0;border-right:none;border-top:none;border-bottom:none;padding:9px;font-size:.85rem;background:${tipo==='Parcial'?'#d97706':'#f8fafc'};color:${tipo==='Parcial'?'#fff':'#374151'};font-weight:${tipo==='Parcial'?'700':'400'};">
            <i class="fa-solid fa-calendar-week"></i> Parcial
          </button>
        </div>
        <input type="hidden" id="f-cfrec-tipo" value="${tipo}">

        <!-- Panel Diario: checkboxes días semana -->
        <div id="cpanel-frec-diario" style="${tipo==='Diario'?'':'display:none;'}">
          <div style="display:flex;flex-wrap:wrap;gap:6px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px;">
            ${checkDias}
          </div>
          <p style="font-size:.72rem;color:#64748b;margin-top:4px;"><i class="fa-solid fa-info-circle"></i> Días en que se visita este cliente semanalmente.</p>
        </div>

        <!-- Panel Parcial -->
        <div id="cpanel-frec-parcial" style="${tipo==='Parcial'?'':'display:none;'}">
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px;">
            ${[['Diario','fa-sun','#f59e0b'],['Semanal','fa-calendar-week','#3b82f6'],['Quincenal','fa-calendar-alt','#8b5cf6'],['Mensual','fa-calendar','#10b981']].map(([s,ico,col])=>`
            <button type="button" id="cbtn-sub-${s.toLowerCase()}" onclick="WMS_MODULES.maestro._switchSubtipo('${s}')"
              class="btn" style="border:2px solid ${subtipo===s?col:'#e2e8f0'};background:${subtipo===s?col+'22':'#fff'};color:${subtipo===s?col:'#374151'};padding:8px 4px;border-radius:6px;font-size:.78rem;font-weight:${subtipo===s?'700':'400'};">
              <i class="fa-solid ${ico}" style="display:block;font-size:1.1em;margin-bottom:3px;"></i>${s}
            </button>`).join('')}
          </div>
          <input type="hidden" id="f-cfrec-subtipo" value="${subtipo}">
          <!-- Semanal -->
          <div id="csub-panel-semanal" style="${subtipo==='Semanal'?'':'display:none;'}">
            <label class="form-label" style="font-size:.8rem;">Día de la semana</label>
            <div style="display:flex;flex-wrap:wrap;gap:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;">${radioSemana}</div>
          </div>
          <!-- Mensual -->
          <div id="csub-panel-mensual" style="${subtipo==='Mensual'?'':'display:none;'}">
            <label class="form-label" style="font-size:.8rem;">Día del mes en que se visita</label>
            <div style="display:flex;flex-wrap:wrap;gap:4px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;">${numDias}</div>
          </div>
          <!-- Quincenal info -->
          <div id="csub-panel-quincenal" style="${subtipo==='Quincenal'?'':'display:none;'}">
            <div style="background:#f3e8ff;border:1px solid #d8b4fe;border-radius:6px;padding:10px;font-size:.82rem;color:#6d28d9;">
              <i class="fa-solid fa-info-circle"></i> Quincenal: el cliente se visita cada 15 días.
            </div>
          </div>
        </div>
      </div>

      <div class="form-group"><label class="form-label">CIUDAD</label>
        <input id="f-ccity" class="form-control" placeholder="Ciudad" value="${v('ciudad')}"></div>

      <div class="form-group"><label class="form-label">DIRECCIÓN</label>
        <input id="f-cdir" class="form-control" placeholder="Dirección completa" value="${v('direccion')}"></div>

      <div class="form-group"><label class="form-label">TELÉFONO</label>
        <input id="f-ctel" class="form-control" placeholder="300 000 0000" value="${v('telefono')}"></div>

      <div class="form-group"><label class="form-label">NOMBRE CONTACTO</label>
        <input id="f-ccon" class="form-control" placeholder="Persona encargada" value="${v('contacto_nombre')}"></div>

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label">EMAIL</label>
        <input id="f-cmail" class="form-control" placeholder="email@ejemplo.com" value="${v('email')}"></div>

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label"><i class="fa-regular fa-clock" style="color:#1e40af;"></i> HORARIO DE ATENCIÓN</label>
        <input id="f-chorario" class="form-control" placeholder="Ej: Lun-Vie 8:00am-5:00pm · Sáb 8:00am-12:00pm" value="${v('horario')}">
        <span class="form-hint">Horario en que el cliente recibe visitas / pedidos</span>
      </div>

      <div class="form-group" style="grid-column:1/-1;">
        <label class="form-label"><i class="fa-solid fa-location-dot" style="color:#dc2626;"></i> COORDENADAS (Google Maps)</label>
        <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end;">
          <input id="f-ccoordenadas" class="form-control" placeholder="4.7109886, -74.0721372" value="${c?.latitud&&c?.longitud ? c.latitud+', '+c.longitud : ''}">
          <button type="button" class="btn btn-secondary" style="padding:8px 12px;" onclick="WMS_MODULES.maestro._previewMaps()" title="Previsualizar en Maps">
            <i class="fa-solid fa-map-location-dot"></i>
          </button>
        </div>
        <span class="form-hint"><i class="fa-solid fa-info-circle"></i> Tip: En Google Maps, clic derecho → "¿Qué hay aquí?" para obtener las coordenadas.</span>
      </div>

    </div>`;
  },

  _previewMaps() {
    const raw = document.getElementById('f-ccoordenadas')?.value?.trim();
    if (!raw) { WMS.toast('warning','Ingrese coordenadas'); return; }
    const parts = raw.split(',');
    if (parts.length < 2) { WMS.toast('warning','Formato: latitud, longitud'); return; }
    const lat = parts[0].trim(), lng = parts[1].trim();
    if (!lat || !lng) { WMS.toast('warning','Formato: latitud, longitud'); return; }
    window.open(`https://maps.google.com/?q=${lat},${lng}`, '_blank');
  },

  async nuevoCliente() {
    const rutas = this._rutasData || [];
    WMS.showModal('Registrar Nuevo Cliente', this._clienteModalBody(null, rutas),
      `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveCliente(null)"><i class="fa-solid fa-plus"></i> Guardar Cliente</button>`);
  },

  async editCliente(id) {
    try {
      const rc = await API.get('/param/clientes');
      const c  = (rc.data || rc || []).find(x => x.id == id);
      if (!c) { WMS.toast('error','Cliente no encontrado'); return; }
      const rutas = this._rutasData || [];
      WMS.showModal('Editar Cliente', this._clienteModalBody(c, rutas),
        `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
         <button class="btn btn-primary" onclick="WMS_MODULES.maestro.saveCliente(${id})"><i class="fa-solid fa-save"></i> Actualizar</button>`);
    } catch(e) { WMS.toast('error','Error cargando cliente'); }
  },

  async saveCliente(id = null) {
    const nit          = document.getElementById('f-cnit')?.value?.trim();
    const razon_social = document.getElementById('f-crs')?.value?.trim();
    const ruta_id      = document.getElementById('f-cruta')?.value || '';
    if (!nit || !razon_social) { WMS.toast('warning','NIT y Razón Social son requeridos'); return; }

    // Recoger frecuencia
    const frecTipo = document.getElementById('f-cfrec-tipo')?.value || 'Diario';
    let frecConfig = {};
    if (frecTipo === 'Diario') {
      const dias = Array.from(document.querySelectorAll('input[name="cfrec-dias"]:checked')).map(i=>i.value);
      if (!dias.length) { WMS.toast('warning','Seleccione al menos un día de visita'); return; }
      frecConfig = { dias };
    } else {
      const subtipo = document.getElementById('f-cfrec-subtipo')?.value || 'Diario';
      frecConfig = { subtipo };
      if (subtipo === 'Semanal') {
        const dia = document.querySelector('input[name="cfrec-dia-sem"]:checked')?.value;
        if (!dia) { WMS.toast('warning','Seleccione el día de la semana'); return; }
        frecConfig.dia = dia;
      } else if (subtipo === 'Mensual') {
        const diaMes = document.querySelector('input[name="cfrec-dia-mes"]:checked')?.value;
        if (!diaMes) { WMS.toast('warning','Seleccione el día del mes'); return; }
        frecConfig.dia_mes = parseInt(diaMes);
      }
    }

    const body = {
      nit, razon_social, ruta_id,
      ciudad:          document.getElementById('f-ccity')?.value?.trim(),
      direccion:       document.getElementById('f-cdir')?.value?.trim(),
      telefono:        document.getElementById('f-ctel')?.value?.trim(),
      contacto_nombre: document.getElementById('f-ccon')?.value?.trim(),
      email:           document.getElementById('f-cmail')?.value?.trim(),
      horario:         document.getElementById('f-chorario')?.value?.trim(),
      ...(() => {
        const raw = document.getElementById('f-ccoordenadas')?.value?.trim();
        if (!raw) return { latitud: '', longitud: '' };
        const p = raw.split(',');
        return { latitud: p[0]?.trim() || '', longitud: p[1]?.trim() || '' };
      })(),
      frecuencia_tipo:   frecTipo,
      frecuencia_config: JSON.stringify(frecConfig),
    };
    try {
      const r = id ? await API.put('/param/clientes/'+id, body) : await API.post('/param/clientes', body);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success','Cliente guardado');
      WMS.closeModal('generic-modal');
      this.show_clientes();
    } catch(e) { WMS.toast('error','Error guardando cliente'); }
  },

  deleteCliente(id, n) {
    WMS.confirm('Eliminar Cliente', `¿Eliminar el cliente "${WMS.esc(n)}"?`, async () => {
      try {
        const r = await API.delete('/param/clientes/'+id);
        if (r.error) WMS.toast('error', r.message);
        else { WMS.toast('success','Cliente eliminado'); this.show_clientes(); }
      } catch(e) { WMS.toast('error','Error eliminando cliente'); }
    });
  },

  // ══════════════════════════════════════════════════════════════════════════
  // DIAGNÓSTICO DEL SISTEMA — Solo Admin
  // ══════════════════════════════════════════════════════════════════════════
  async show_sistema() {
    WMS.setBreadcrumb('maestro', 'sistema');
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" id="btn-run-diag" onclick="WMS_MODULES.maestro._runDiagnostico()">
        <i class="fa-solid fa-stethoscope"></i> Ejecutar Diagnóstico
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro._opcacheReset()">
        <i class="fa-solid fa-rotate"></i> Limpiar OPcache
      </button>
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.maestro._limpiarLogs()">
        <i class="fa-solid fa-broom"></i> Limpiar Logs
      </button>
    `);

    WMS.setContent(`
      <div style="padding:24px; max-width:1100px; margin:0 auto;">

        <!-- Header -->
        <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px; padding:20px 24px;
                    background:linear-gradient(135deg,#0f172a,#1e3a5f); border-radius:4px; box-shadow:0 4px 16px rgba(0,0,0,0.2);">
          <div style="width:52px;height:52px;border-radius:14px;background:rgba(99,102,241,0.3);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#818cf8;">
            <i class="fa-solid fa-stethoscope"></i>
          </div>
          <div>
            <h2 style="font-weight:800;color:#f1f5f9;margin:0 0 4px;">Diagnóstico del Sistema WMS</h2>
            <p style="color:#94a3b8;font-size:.85rem;margin:0;">Valida controladores, rutas, integridad de archivos y estado del servidor en tiempo real.</p>
          </div>
        </div>

        <!-- Resumen KPIs -->
        <div id="diag-kpis" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
          ${['Controladores OK','Errores','Advertencias','Rutas OK','Rutas Errores'].map((label,i) => `
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:4px;padding:16px 18px;text-align:center;">
              <div id="diag-kpi-${i}" style="font-size:2rem;font-weight:900;color:#94a3b8;">—</div>
              <div style="font-size:11px;color:#64748b;margin-top:4px;">${label}</div>
            </div>
          `).join('')}
        </div>

        <!-- Estado global -->
        <div id="diag-estado-global" style="display:none;padding:14px 20px;border-radius:12px;margin-bottom:20px;font-size:14px;font-weight:700;"></div>

        <!-- Resultados por controlador -->
        <div id="diag-results" style="display:none;">

          <!-- Entorno -->
          <div style="margin-bottom:20px;">
            <div style="font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
              <i class="fa-solid fa-server" style="margin-right:6px;color:#6366f1;"></i>Entorno del Servidor
            </div>
            <div id="diag-entorno" style="display:flex;gap:10px;flex-wrap:wrap;"></div>
          </div>

          <!-- Controladores -->
          <div style="margin-bottom:20px;">
            <div style="font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
              <i class="fa-solid fa-file-code" style="margin-right:6px;color:#0ea5e9;"></i>Controladores PHP
            </div>
            <div id="diag-ctrl-table" style="border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;"></div>
          </div>

          <!-- Rutas -->
          <div>
            <div style="font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
              <i class="fa-solid fa-route" style="margin-right:6px;color:#f59e0b;"></i>Validación de Rutas
            </div>
            <div id="diag-routes-box" style="border:1px solid #e2e8f0;border-radius:4px;padding:16px;background:#fff;"></div>
          </div>
        </div>

        <!-- Idle state -->
        <div id="diag-idle" style="text-align:center;padding:60px 20px;color:#94a3b8;">
          <i class="fa-solid fa-stethoscope" style="font-size:3rem;margin-bottom:16px;display:block;color:#cbd5e1;"></i>
          <div style="font-size:15px;font-weight:600;color:#64748b;">Haga clic en <strong>Ejecutar Diagnóstico</strong> para analizar el sistema</div>
          <div style="font-size:12px;margin-top:8px;">El proceso verifica los 25 controladores y todas las rutas registradas en segundos.</div>
        </div>
      </div>
    `);
  },

  async _runDiagnostico() {
    const btn = document.getElementById('btn-run-diag');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analizando...'; }
    document.getElementById('diag-idle').style.display = 'none';
    document.getElementById('diag-results').style.display = 'none';
    document.getElementById('diag-estado-global').style.display = 'none';

    try {
      const r = await API.get('/sistema/validar');
      if (r.error) { WMS.toast('error', r.message || 'Error en diagnóstico'); return; }

      const s = r.summary;
      const d = r.data;

      // KPIs
      const kpiData = [s.ok, s.errores, s.advertencias, s.rutas_total - s.rutas_errores, s.rutas_errores];
      const kpiColors = ['#10b981','#ef4444','#f59e0b','#10b981','#ef4444'];
      kpiData.forEach((val,i) => {
        const el = document.getElementById('diag-kpi-' + i);
        if (el) { el.textContent = val; el.style.color = val > 0 && (i===1||i===4) ? '#ef4444' : (i===2 && val>0 ? '#f59e0b' : '#1e293b'); }
      });

      // Estado global
      const eg = document.getElementById('diag-estado-global');
      if (eg) {
        const ok = s.estado_global === 'saludable';
        eg.style.display = 'block';
        eg.style.background = ok ? '#f0fdf4' : '#fef2f2';
        eg.style.border = '1px solid ' + (ok ? '#a7f3d0' : '#fecaca');
        eg.style.color  = ok ? '#065f46' : '#991b1b';
        eg.innerHTML = ok
          ? '<i class="fa-solid fa-circle-check" style="margin-right:8px;"></i>Sistema saludable — todos los controladores y rutas están correctos'
          : '<i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;"></i>Se encontraron ' + s.errores + ' error(es) en controladores y ' + s.rutas_errores + ' en rutas — revise los detalles abajo';
      }

      // Entorno
      const env = d.entorno || {};
      const entornoEl = document.getElementById('diag-entorno');
      if (entornoEl) {
        const chips = [
          { label: 'PHP ' + env.php_version, icon: 'fa-code', color: '#6366f1' },
          { label: 'ENV: ' + env.app_env,     icon: 'fa-gear',  color: env.app_env === 'development' ? '#10b981' : '#f59e0b' },
          { label: 'DEBUG: ' + env.app_debug, icon: 'fa-bug',   color: env.app_debug === 'true' ? '#10b981' : '#94a3b8' },
          { label: 'OPcache: ' + (env.opcache_activo ? 'Activo' : 'Inactivo'), icon: 'fa-memory', color: env.opcache_activo ? '#0ea5e9' : '#94a3b8' },
          { label: 'Log: ' + env.log_size_kb + ' KB', icon: 'fa-file-lines', color: env.log_size_kb > 500 ? '#f59e0b' : '#10b981' },
          { label: env.fecha_hora,             icon: 'fa-clock', color: '#64748b' },
        ];
        entornoEl.innerHTML = chips.map(c => `
          <span style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:${c.color}15;border:1px solid ${c.color}30;border-radius:20px;font-size:12px;font-weight:600;color:${c.color};">
            <i class="fa-solid ${c.icon}"></i>${c.label}
          </span>
        `).join('');
      }

      // Tabla de controladores
      const ctrlEl = document.getElementById('diag-ctrl-table');
      if (ctrlEl && d.controladores) {
        const rows = Object.entries(d.controladores).map(([name, info]) => {
          const statusIcon = info.status === 'ok'
            ? '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i>'
            : info.status === 'error'
              ? '<i class="fa-solid fa-circle-xmark" style="color:#ef4444;"></i>'
              : '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>';

          const issuesList = info.issues.length
            ? '<ul style="margin:6px 0 0;padding-left:16px;font-size:11px;">' +
              info.issues.map(is => `<li style="color:${is.nivel==='error'?'#ef4444':'#f59e0b'};margin-bottom:2px;">${is.mensaje}</li>`).join('') +
              '</ul>'
            : '';

          return `<tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:10px 14px;">${statusIcon} <span style="font-size:12px;font-weight:600;color:#1e293b;">${name}</span>${issuesList}</td>
            <td style="padding:10px 14px;text-align:center;font-size:11px;color:#64748b;">${info.lineas}</td>
            <td style="padding:10px 14px;text-align:center;font-size:11px;color:#64748b;">${info.metodos}</td>
          </tr>`;
        }).join('');

        ctrlEl.innerHTML = `<table style="width:100%;border-collapse:collapse;">
          <thead style="background:#f8fafc;">
            <tr>
              <th style="padding:10px 14px;text-align:left;font-size:11px;color:#475569;font-weight:700;">Controlador</th>
              <th style="padding:10px 14px;text-align:center;font-size:11px;color:#475569;font-weight:700;">Líneas</th>
              <th style="padding:10px 14px;text-align:center;font-size:11px;color:#475569;font-weight:700;">Métodos</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>`;
      }

      // Rutas
      const routesEl = document.getElementById('diag-routes-box');
      if (routesEl && d.rutas) {
        const rt = d.rutas;
        if (rt.status === 'ok') {
          routesEl.innerHTML = `<div style="display:flex;align-items:center;gap:10px;color:#065f46;">
            <i class="fa-solid fa-circle-check" style="font-size:1.2rem;color:#10b981;"></i>
            <span><strong>${rt.total}</strong> rutas registradas — todas apuntan a métodos existentes</span>
          </div>`;
        } else {
          const errList = rt.errores.map(e =>
            `<li style="color:#991b1b;margin-bottom:4px;font-size:12px;">${e.mensaje}</li>`
          ).join('');
          routesEl.innerHTML = `
            <div style="color:#991b1b;font-weight:700;margin-bottom:8px;">
              <i class="fa-solid fa-triangle-exclamation"></i> ${rt.errores.length} ruta(s) con error de ${rt.total} totales
            </div>
            <ul style="margin:0;padding-left:18px;">${errList}</ul>`;
        }
      }

      document.getElementById('diag-results').style.display = 'block';
      WMS.toast(s.estado_global === 'saludable' ? 'success' : 'error',
        s.estado_global === 'saludable' ? 'Sistema saludable ✓' : s.errores + ' error(es) encontrado(s)');

    } catch(e) {
      WMS.toast('error', 'Error ejecutando diagnóstico: ' + e.message);
      document.getElementById('diag-idle').style.display = 'block';
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-stethoscope"></i> Ejecutar Diagnóstico'; }
    }
  },

  async _opcacheReset() {
    try {
      const r = await API.post('/sistema/opcache-reset');
      WMS.toast(r.error ? 'error' : 'success',
        r.error ? 'Error al resetear OPcache' : 'OPcache reseteado correctamente');
    } catch(e) {
      WMS.toast('error', 'Error: ' + e.message);
    }
  },

  
  importarGenerico(tipo) {
    WMS.showModal('Importación Masiva — ' + tipo.toUpperCase(), `
      <div class="alert alert-info" style="margin-bottom:15px; font-size:.85rem; border-radius:8px;">
        <i class="fa-solid fa-info-circle"></i> Suba su archivo CSV/Excel para importar <b>${tipo}</b>.<br>
        Puede descargar la plantilla usando el botón de exportación estando la tabla vacía.
      </div>
      
      <div id="import-dropzone" style="border:2px dashed #94a3b8; border-radius:12px; padding:30px; text-align:center; background:#f8fafc; cursor:pointer; transition:all 0.2s;" onclick="document.getElementById('import-csv-file').click()" onmouseover="this.style.borderColor='#3b82f6';this.style.background='#eff6ff'" onmouseout="this.style.borderColor='#94a3b8';this.style.background='#f8fafc'">
        <i class="fa-solid fa-cloud-arrow-up" style="font-size:40px; color:#94a3b8; margin-bottom:10px; display:block;"></i>
        <div style="font-size:16px; font-weight:800; color:#1e293b; margin-bottom:6px;">Haga clic para seleccionar archivo</div>
        <div style="font-size:12px; color:#64748b;">CSV, XLS o XLSX (Máx 10MB)</div>
      </div>
      <input type="file" id="import-csv-file" style="display:none;" accept=".csv,.txt" onchange="WMS_MODULES.maestro._onFileSelect('${tipo}')">

      <div id="import-file-info" style="display:none;margin-top:10px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <i class="fa-solid fa-file-csv" style="font-size:20px;color:#16a34a;"></i>
          <div style="flex:1;">
            <div id="import-file-name" style="font-size:13px;font-weight:600;color:#166534;"></div>
            <div id="import-file-meta" style="font-size:11px;color:#4ade80;"></div>
          </div>
          <button class="btn btn-xs btn-secondary" onclick="document.getElementById('import-csv-file').value='';document.getElementById('import-file-info').style.display='none';document.getElementById('import-preview-section').style.display='none';document.getElementById('import-dropzone').style.display='block';">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>

      <div id="import-preview-section" style="display:none; margin-top:20px;">
        <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">
          <i class="fa-solid fa-table" style="margin-right:6px;color:#0ea5e9;"></i>Vista Previa (primeras 5 filas)
        </div>
        <div id="import-preview-table" style="max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;"></div>
        <div id="import-preview-summary" style="margin-top:10px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:12px;color:#1e40af;"></div>
      </div>
    `,
    `<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
     <button class="btn btn-primary" id="btn-do-import" onclick="WMS_MODULES.maestro.doImportGenerico('${tipo}')" disabled><i class="fa-solid fa-upload"></i> Procesar Importación</button>`,
    { width: '700px' });
  },

  // Helper local de escape de HTML seguro
  _esc(v) {
    if (typeof WMS.esc === 'function') return WMS.esc(v);
    return String(v||"").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
  },

  _onFileSelect(tipo) {
    const file = document.getElementById('import-csv-file')?.files[0];
    if (!file) return;

    document.getElementById('import-dropzone').style.display = 'none';
    document.getElementById('import-file-info').style.display = 'block';
    document.getElementById('import-file-name').textContent = file.name;
    document.getElementById('import-file-meta').textContent = `${(file.size/1024).toFixed(1)} KB — ${file.type || 'archivo de texto'}`;

    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const lines = text.split(/\r?\n/).filter(l => l.trim());
      if (lines.length <= 1) {
        WMS.toast('warning', 'El archivo no contiene suficientes datos (mínimo cabecera y una fila)');
        return;
      }
      
      // Lógica de detección de cabecera inteligente (igual al backend)
      const fillable = ['codigo_interno', 'nombre', 'descripcion', 'unidad_medida', 'peso_unitario', 'volumen_unitario', 'stock_minimo', 'unidades_caja', 'nit', 'razon_social'];
      const aliases = {
        'codigo_interno': ['codigo', 'ref', 'cod_interno', 'codigo_producto', 'identificador'],
        'nombre': ['nombre', 'producto', 'descripcion', 'detalle'],
        'codigo_ean': ['ean', 'barcode', 'codigo_ean', 'codigo_barras'],
        'nit': ['nit', 'id', 'cedula', 'identificacion']
      };

      let headerRowIndex = 0;
      let headers = [];
      let sep = ',';

      for (let i = 0; i < Math.min(lines.length, 15); i++) {
        const line = lines[i];
        const s = line.includes(';') ? ';' : ',';
        const cols = line.split(s).map(c => c.trim().toLowerCase().replace(/^"|"$/g, ''));
        let valid = 0;
        cols.forEach(c => {
          if (fillable.includes(c) || c === 'codigo_ean') valid++;
          else {
            for (let k in aliases) if (aliases[k].includes(c)) { valid++; break; }
          }
        });
        if (valid >= 2) {
          headerRowIndex = i;
          headers = line.split(s).map(c => c.trim().replace(/^"|"$/g, ''));
          sep = s;
          break;
        }
      }

      if (headers.length === 0) {
          sep = lines[0].includes(';') ? ';' : ',';
          headers = lines[0].split(sep).map(h => h.trim());
      }
      
      let html = '<table style="width:100%;border-collapse:collapse;font-size:11px;"><thead><tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;">';
      html += headers.map(h => `<th style="padding:6px;text-align:left;color:#475569;">${this._esc(h)}</th>`).join('');
      html += '</tr></thead><tbody>';

      const limit = Math.min(headerRowIndex + 6, lines.length);
      for (let i = headerRowIndex + 1; i < limit; i++) {
        const cols = lines[i].split(sep).map(c => c.trim().replace(/^"|"$/g, ''));
        html += `<tr style="border-bottom:1px solid #e2e8f0;">`;
        for (let j = 0; j < headers.length; j++) {
           html += `<td style="padding:6px;color:#1e293b;">${this._esc(cols[j] || '')}</td>`;
        }
        html += `</tr>`;
      }
      html += '</tbody></table>';

      document.getElementById('import-preview-table').innerHTML = html;
      document.getElementById('import-preview-summary').innerHTML = `<i class="fa-solid fa-check-circle" style="color:#2563eb;margin-right:6px;"></i>Se detectaron <strong>${lines.length - (headerRowIndex + 1)} registros</strong> para procesar de <strong>${tipo}</strong>.`;
      document.getElementById('import-preview-section').style.display = 'block';
      document.getElementById('btn-do-import').disabled = false;
    };
    reader.readAsText(file);
  },

    async doImportGenerico(tipo) {
    const file = document.getElementById('import-csv-file')?.files[0];
    if (!file) return;

    const btn = document.getElementById('btn-do-import');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...'; }

    const formData = new FormData();
    formData.append('file', file);
    const token = localStorage.getItem('wms_token') || '';

    try {
      const url = '/WMS_FENIX/public/api/param/import-export/upload/' + tipo;
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token },
        body: formData
      });
      const res = await r.json();
      
      if (!r.ok || res.error) {
        throw new Error(res.message || 'Error en la importación');
      }

      console.log("Respuesta WS IMPORT:", res);
      const total = res.total || res.data?.total || 0;
      const success = (res.data?.creados !== undefined) ? res.data.creados : (res.success || 0);
      const updated = (res.data?.actualizados !== undefined) ? res.data.actualizados : (res.updated || 0);
      const skipped = res.skipped || res.data?.omitiendo || 0;
      const errsArray = res.errors || res.data?.errors || [];

      let rowsHtml = '';
      if (errsArray.length > 0) {
          rowsHtml = `<div style="max-height:160px; overflow-y:auto;text-align:left;font-size:11px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-top:10px;">
                        <div style="font-weight:700;color:#dc2626;margin-bottom:6px;"><i class="fa-solid fa-circle-exclamation"></i> Novedades y Errores de Líneas (${errsArray.length})</div>
                        <ul style="margin:0;padding-left:14px;color:#991b1b;">`;
          errsArray.slice(0,30).forEach(e => { rowsHtml += `<li>${WMS.esc(e)}</li>`; });
          if(errsArray.length > 30) rowsHtml += `<li style="font-style:italic;margin-top:4px;">Y ${errsArray.length-30} novedades adicionales...</li>`;
          rowsHtml += `</ul></div>`;
      }

      const modalHtml = `
        <div style="text-align:left;font-size:13px;">
          <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
             <span style="font-weight:700;color:#1e293b;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">Módulo: ${tipo.toUpperCase()}</span>
             <span class="badge ${errsArray.length > 0 ? 'badge-warning' : 'badge-success'}" style="font-size:10px;">
                ${errsArray.length > 0 ? 'Con novedades' : 'Exitoso'}
             </span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:14px;">
            <div style="padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;text-align:center;">
              <div style="font-size:20px;font-weight:800;color:#475569;">${total}</div>
              <div style="font-size:9px;color:#64748b;font-weight:700;">TOTAL</div>
            </div>
            <div style="padding:10px;background:#f0fdf4;border-radius:4px;text-align:center;border:1px solid #dcfce7;">
              <div style="font-size:20px;font-weight:800;color:#16a34a;">${success}</div>
              <div style="font-size:9px;color:#16a34a;font-weight:700;">NUEVOS</div>
            </div>
            <div style="padding:10px;background:#eff6ff;border-radius:4px;text-align:center;border:1px solid #dbeafe;">
              <div style="font-size:20px;font-weight:800;color:#2563eb;">${updated}</div>
              <div style="font-size:9px;color:#2563eb;font-weight:700;">ACTUALIZADOS</div>
            </div>
            <div style="padding:10px;background:#fef2f2;border-radius:4px;text-align:center;border:1px solid #fee2e2;">
              <div style="font-size:20px;font-weight:800;color:#dc2626;">${errsArray.length + skipped}</div>
              <div style="font-size:9px;color:#dc2626;font-weight:700;">AVISOS</div>
            </div>
          </div>
          ${rowsHtml}
        </div>
      `;

      WMS.showModal('Resultado de Importación', modalHtml, `<button class="btn btn-primary" onclick="WMS.closeModal('generic-modal')">Entendido</button>`);

      if (this.show_productos && tipo === 'productos') this.show_productos();
      if (this.show_clientes && tipo === 'clientes') this.show_clientes();
      if (this.show_proveedores && tipo === 'proveedores') this.show_proveedores();

    } catch (e) {
      console.error("Error Import:", e);
      WMS.toast('error', e.message);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-upload"></i> Procesar Importación';
      }
    }
  },

  // ── IMPRESORAS ──────────────────────────────────────────────
  async show_impresoras() {
    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.maestro.formImpresora()"><i class="fa-solid fa-plus"></i> Nueva Impresora</button>
    `);
    WMS.spinner();
    try {
      const r = await API.get('/impresoras');
      const items = r.data || r || [];
      WMS.setContent(`
        <div class="card">
          <div class="card-header"><span class="card-title"><i class="fa-solid fa-print"></i> Maestro de Impresoras IP</span></div>
          <div class="table-container">
            <table class="erp-table">
              <thead><tr><th>Nombre</th><th>Dirección IP</th><th>Puerto</th><th>Tipo</th><th>Módulos Asignados</th><th>Estado</th><th>Acciones</th></tr></thead>
              <tbody>${items.map(i => `<tr>
                <td><strong>${WMS.esc(i.nombre)}</strong></td>
                <td><code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">${WMS.esc(i.ip)}</code></td>
                <td>${i.puerto}</td>
                <td><span class="badge ${i.tipo==='Rotulos'?'badge-info':i.tipo==='Despacho'?'badge-success':'badge-light'}">${WMS.esc(i.tipo)}</span></td>
                <td><div style="display:flex;gap:4px;flex-wrap:wrap;">${(i.modulos||'').split(',').filter(Boolean).map(m => `<span class="badge badge-primary" style="font-size:10px;">${WMS.esc(m)}</span>`).join('') || '<span class="text-muted" style="font-size:10px;">Ninguno</span>'}</div></td>
                <td><span class="status-badge ${i.activo?'success':'danger'}">${i.activo?'Activa':'Inactiva'}</span></td>
                <td><div class="actions" style="display:flex;gap:4px;">
                  <button class="btn btn-sm btn-info" onclick="WMS_MODULES.maestro.testImpresora(${i.id})" title="Prueba de Impresión" style="display:flex;align-items:center;gap:4px;">🖨️ <i class="fa-solid fa-print"></i> <span>Probar</span></button>
                  <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro.formImpresora(${JSON.stringify(i).replace(/"/g, '&quot;')})" style="display:flex;align-items:center;gap:4px;">✏️ <i class="fa-solid fa-edit"></i> <span>Editar</span></button>
                  <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.maestro.eliminarImpresora(${i.id})" style="display:flex;align-items:center;gap:4px;">🗑️ <i class="fa-solid fa-trash"></i> <span>Borrar</span></button>
                </div></td>
              </tr>`).join('') || '<tr><td colspan="6" class="table-empty">Sin impresoras configuradas</td></tr>'}</tbody>
            </table>
          </div>
        </div>
      `);
    } catch(e) { WMS.toast('error', 'Error cargando impresoras'); }
  },

  formImpresora(data = null) {
    WMS.showRightPanel(data ? 'Editar Impresora' : 'Nueva Impresora', `
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Nombre <span class="required">*</span></label><input id="imp-nombre" class="form-control" value="${WMS.esc(data?.nombre||'')}"></div>
        <div class="form-group"><label class="form-label">Dirección IP <span class="required">*</span></label><input id="imp-ip" class="form-control" placeholder="192.168.1.50" value="${WMS.esc(data?.ip||'')}"></div>
        <div class="form-group"><label class="form-label">Puerto</label><input id="imp-puerto" type="number" class="form-control" value="${data?.puerto||9100}"></div>
        <div class="form-group"><label class="form-label">Propósito</label>
          <select id="imp-tipo" class="form-control">
            <option value="General"  ${data?.tipo==='General' ?'selected':''}>General</option>
            <option value="Rotulos"  ${data?.tipo==='Rotulos' ?'selected':''}>Rótulos / Etiquetas</option>
            <option value="Despacho" ${data?.tipo==='Despacho'?'selected':''}>Documentos Despacho</option>
          </select></div>
        <div class="form-group"><label class="form-label">Lenguaje / Marca</label>
          <select id="imp-lenguaje" class="form-control">
            <option value="ZPL" ${(data?.lenguaje||'ZPL')==='ZPL'?'selected':''}>ZPL — Zebra</option>
            <option value="TSC" ${data?.lenguaje==='TSC'           ?'selected':''}>TSPL — TSC / Argox</option>
            <option value="EPL" ${data?.lenguaje==='EPL'           ?'selected':''}>EPL — Eltron legacy</option>
          </select></div>
        <div class="form-group" style="grid-column: span 2;">
          <label class="form-label">Asignar a Módulos</label>
          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0;">
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer"><input type="checkbox" name="imp-mod" value="rotulos" ${(data?.modulos||'').includes('rotulos')?'checked':''}> Módulo Rótulos</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer"><input type="checkbox" name="imp-mod" value="certificacion" ${(data?.modulos||'').includes('certificacion')?'checked':''}> Módulo Certificación</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer"><input type="checkbox" name="imp-mod" value="inventario" ${(data?.modulos||'').includes('inventario')?'checked':''}> Módulo Inventario</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer"><input type="checkbox" name="imp-mod" value="picking" ${(data?.modulos||'').includes('picking')?'checked':''}> Módulo Picking</label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="imp-activo" ${data === null || data.activo ? 'checked' : ''}> Impresora Activa
          </label>
        </div>
        <div class="form-group" style="grid-column: span 2;">
          <label class="form-label">Tipos de trabajo</label>
          <div style="display:flex;gap:16px;">
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
              <input type="checkbox" id="imp-tipo-sticker" value="sticker_packing"
                ${(data?.tipos_trabajo||[]).includes('sticker_packing') ? 'checked' : ''}>
              Stickers de packing
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
              <input type="checkbox" id="imp-tipo-doc" value="documento_packing"
                ${(data?.tipos_trabajo||[]).includes('documento_packing') ? 'checked' : ''}>
              Documento de packing
            </label>
          </div>
        </div>
      </div>`,
      `<button class="btn btn-secondary" onclick="WMS.closeRightPanel()">✖ Cancelar</button>
       ${data ? `<button class="btn btn-info" onclick="WMS_MODULES.maestro.testImpresora(${data.id})">🖨️ <i class="fa-solid fa-print"></i> Probar</button>` : ''}
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.guardarImpresora(${data?.id||null})">💾 <i class="fa-solid fa-save"></i> Guardar</button>`);
  },

  async guardarImpresora(id) {
    const mods = Array.from(document.querySelectorAll('input[name="imp-mod"]:checked')).map(cb => cb.value).join(',');
    const payload = {
      id,
      nombre: document.getElementById('imp-nombre').value.trim(),
      ip: document.getElementById('imp-ip').value.trim(),
      puerto: document.getElementById('imp-puerto').value,
      tipo:     document.getElementById('imp-tipo').value,
      lenguaje: document.getElementById('imp-lenguaje').value,
      modulos: mods,
      tipos_trabajo: [
        ...( document.getElementById('imp-tipo-sticker')?.checked ? ['sticker_packing'] : [] ),
        ...( document.getElementById('imp-tipo-doc')?.checked     ? ['documento_packing'] : [] ),
      ],
      activo: document.getElementById('imp-activo').checked
    };
    if (!payload.nombre || !payload.ip) return WMS.toast('warn', 'Nombre e IP son requeridos');
    try {
      const r = await API.post('/impresoras', payload);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Impresora guardada'); WMS.closeRightPanel(); this.show_impresoras(); }
    } catch(e) { 
      WMS.toast('error', e.message || 'Error guardando impresora'); 
    }
  },

  async eliminarImpresora(id) {
    if (!confirm('¿Eliminar esta configuración de impresora?')) return;
    try {
      const r = await API.delete('/impresoras/' + id);
      if (r.error) WMS.toast('error', r.message);
      else { WMS.toast('success', 'Impresora eliminada'); this.show_impresoras(); }
    } catch(e) { WMS.toast('error', 'Error eliminando impresora'); }
  },

  async testImpresora(id) {
    WMS.toast('info', 'Enviando prueba...');
    try {
      const r = await API.post(`/impresoras/${id}/test-print`);
      if (r.error) WMS.toast('error', r.message);
      else WMS.toast('success', 'Prueba enviada correctamente');
    } catch(e) { WMS.toast('error', 'Error de conexión con el servidor'); }
  },

  // ── CAUSALES DE NOVEDAD ─────────────────────────────────────
  show_causales_novedad() {
    WMS.setToolbar(`
      <div class="actions">
        <button class="btn btn-primary" onclick="WMS_MODULES.maestro._nuevaCausal()">
          <i class="fa-solid fa-plus"></i> Nueva Causal
        </button>
      </div>
    `);
    WMS.setContent(`<div id="causales-container" style="padding:20px;">
      <div style="text-align:center;color:#64748b;padding:40px;">Cargando...</div>
    </div>`);
    this._loadCausales();
  },

  async _loadCausales() {
    try {
      const r = await API.get('/causales-novedad?incluir_inactivas=1');
      const causales = r.data || r || [];
      this._renderCausales(causales);
    } catch(e) {
      const c = document.getElementById('causales-container');
      if (c) c.innerHTML = `<div style="color:#dc2626;padding:20px;">Error: ${WMS.esc(e.message)}</div>`;
    }
  },

  _renderCausales(causales) {
    const AREAS_CON_NS = ['CDP', 'Logistica'];
    const rows = causales.map(c => {
      const afecta = c.afecta_nivel_servicio || AREAS_CON_NS.includes(c.area_responsable);
      const nsBadge = afecta
        ? `<span class="badge badge-success" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">Sí — CDP/Logística</span>`
        : `<span class="badge badge-light" style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;">No afecta KPI</span>`;
      const activoBadge = c.activo
        ? `<span class="status-badge success">Activa</span>`
        : `<span class="status-badge danger">Inactiva</span>`;
      return `<tr>
        <td><strong>${WMS.esc(c.nombre)}</strong></td>
        <td>${WMS.esc(c.area_responsable)}</td>
        <td>${nsBadge}</td>
        <td>${activoBadge}</td>
        <td>
          <button class="btn btn-sm btn-secondary" onclick="WMS_MODULES.maestro._editarCausal(${c.id})">
            <i class="fa-solid fa-edit"></i> Editar
          </button>
        </td>
      </tr>`;
    }).join('') || '<tr><td colspan="5" class="table-empty" style="text-align:center;padding:30px;">Sin causales registradas</td></tr>';

    const container = document.getElementById('causales-container');
    if (!container) return;
    container.innerHTML = `
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-list-check"></i> Causales de Novedad</span>
          <span style="font-size:.78rem;color:#64748b;">${causales.length} causal(es)</span>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Área Responsable</th>
                <th>Afecta Nivel de Servicio</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;
  },

  async _nuevaCausal() {
    const { value: formValues, isConfirmed } = await Swal.fire({
      title: 'Nueva Causal de Novedad',
      html: `
        <div style="text-align:left;">
          <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
            Nombre <span style="color:#dc2626;">*</span>
          </label>
          <input id="causal-nombre" class="swal2-input" placeholder="Ej: Faltante en bodega..." style="margin:0 0 12px;width:100%;box-sizing:border-box;">
          <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
            Área Responsable <span style="color:#dc2626;">*</span>
          </label>
          <select id="causal-area" class="swal2-select" style="width:100%;margin:0;">
            <option value="">-- Seleccionar área --</option>
            <option value="CDP">CDP</option>
            <option value="Logistica">Logística</option>
            <option value="Comercial">Comercial</option>
            <option value="Operaciones">Operaciones</option>
            <option value="Otro">Otro</option>
          </select>
        </div>`,
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-save"></i> Guardar',
      cancelButtonText: 'Cancelar',
      focusConfirm: false,
      preConfirm: () => {
        const nombre = document.getElementById('causal-nombre')?.value?.trim();
        const area = document.getElementById('causal-area')?.value;
        if (!nombre) { Swal.showValidationMessage('El nombre es obligatorio'); return false; }
        if (!area) { Swal.showValidationMessage('Selecciona el área responsable'); return false; }
        return { nombre, area_responsable: area };
      }
    });
    if (!isConfirmed || !formValues) return;
    try {
      const r = await API.post('/causales-novedad', formValues);
      if (r.error) { WMS.toast('error', r.message || 'Error al crear causal'); return; }
      WMS.toast('success', 'Causal creada correctamente');
      this._loadCausales();
    } catch(e) { WMS.toast('error', e.message || 'Error al crear causal'); }
  },

  async _editarCausal(id) {
    // Buscar en cache si ya tenemos el listado, sino hacer GET individual
    let causal = null;
    try {
      const r = await API.get('/causales-novedad?incluir_inactivas=1');
      const lista = r.data || r || [];
      causal = lista.find(c => c.id == id);
    } catch(_) {}
    if (!causal) { WMS.toast('error', 'No se encontró la causal'); return; }

    const AREAS_CON_NS = ['CDP', 'Logistica'];
    const afecta = causal.afecta_nivel_servicio || AREAS_CON_NS.includes(causal.area_responsable);

    const { value: formValues, isConfirmed } = await Swal.fire({
      title: 'Editar Causal de Novedad',
      html: `
        <div style="text-align:left;">
          <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
            Nombre <span style="color:#dc2626;">*</span>
          </label>
          <input id="causal-nombre" class="swal2-input" value="${WMS.esc(causal.nombre)}" style="margin:0 0 12px;width:100%;box-sizing:border-box;">
          <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">
            Área Responsable <span style="color:#dc2626;">*</span>
          </label>
          <select id="causal-area" class="swal2-select" style="width:100%;margin:0 0 12px;">
            <option value="">-- Seleccionar área --</option>
            <option value="CDP" ${causal.area_responsable==='CDP'?'selected':''}>CDP</option>
            <option value="Logistica" ${causal.area_responsable==='Logistica'?'selected':''}>Logística</option>
            <option value="Comercial" ${causal.area_responsable==='Comercial'?'selected':''}>Comercial</option>
            <option value="Operaciones" ${causal.area_responsable==='Operaciones'?'selected':''}>Operaciones</option>
            <option value="Otro" ${causal.area_responsable==='Otro'?'selected':''}>Otro</option>
          </select>
          <label style="font-size:.82rem;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Estado</label>
          <select id="causal-activo" class="swal2-select" style="width:100%;margin:0;">
            <option value="1" ${causal.activo?'selected':''}>Activa</option>
            <option value="0" ${!causal.activo?'selected':''}>Inactiva</option>
          </select>
          ${afecta ? '<p style="margin-top:10px;font-size:.75rem;color:#166534;background:#dcfce7;padding:6px 10px;border-radius:4px;"><i class="fa-solid fa-circle-check"></i> Esta área afecta el KPI de nivel de servicio</p>' : ''}
        </div>`,
      showCancelButton: true,
      confirmButtonText: '<i class="fa-solid fa-save"></i> Guardar Cambios',
      cancelButtonText: 'Cancelar',
      focusConfirm: false,
      preConfirm: () => {
        const nombre = document.getElementById('causal-nombre')?.value?.trim();
        const area = document.getElementById('causal-area')?.value;
        const activo = document.getElementById('causal-activo')?.value === '1';
        if (!nombre) { Swal.showValidationMessage('El nombre es obligatorio'); return false; }
        if (!area) { Swal.showValidationMessage('Selecciona el área responsable'); return false; }
        return { nombre, area_responsable: area, activo };
      }
    });
    if (!isConfirmed || !formValues) return;
    try {
      const r = await API.put(`/causales-novedad/${id}`, formValues);
      if (r.error) { WMS.toast('error', r.message || 'Error al actualizar causal'); return; }
      WMS.toast('success', 'Causal actualizada correctamente');
      this._loadCausales();
    } catch(e) { WMS.toast('error', e.message || 'Error al actualizar causal'); }
  },
};
