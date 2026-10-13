/* ============================================================
   WMS Desktop — Módulo PROGRAMACIÓN DE PERSONAL (vista calendario)
   Sub-vistas: horario | personal | tareas | filas
   Formato calendario mensual: 7 columnas (Lun-Dom), celdas por día con
   lista de asignaciones (Turno • Rol • Persona). Panel lateral de
   descansos. Zoom: semana / quincena / mes.
   ============================================================ */
WMS_MODULES.programacion = {
  _sub: null,
  _mes: null,            // "YYYY-MM"
  _matriz: null,
  _vista: 'mes',         // 'semana' | 'quincena' | 'mes'
  _offset: 0,            // página dentro del mes (0..N-1) para semana/quincena

  subLabel(sub) {
    return { horario: 'Horario', personal: 'Personal', tareas: 'Tareas', filas: 'Turnos / Rutas' }[sub] || sub;
  },

  load(sub) {
    this._sub = sub || 'horario';
    WMS.renderSidebar('programacion');
    WMS.setBreadcrumb('programacion', this.subLabel(this._sub));
    WMS.setToolbar('');
    if (this._sub === 'personal') return this.showPersonal();
    if (this._sub === 'tareas')   return this.showTareas();
    if (this._sub === 'filas')    return this.showFilas();
    this.showHorario();
  },

  // ── HORARIO (calendario mensual) ─────────────────────────────────────────
  async showHorario() {
    if (!this._mes) {
      const h = new Date();
      this._mes = `${h.getFullYear()}-${String(h.getMonth() + 1).padStart(2, '0')}`;
    }
    WMS.setContent(`
      <div style="padding:12px;">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
          <label style="font-weight:600;">Mes:</label>
          <input type="month" id="prog-mes" class="form-control" style="max-width:170px;" value="${this._mes}">
          <div class="prog-vista-tabs" style="display:flex;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;">
            <button class="prog-vtab" data-v="semana"   onclick="WMS_MODULES.programacion.cambiarVista('semana')">Semana</button>
            <button class="prog-vtab" data-v="quincena" onclick="WMS_MODULES.programacion.cambiarVista('quincena')">Quincena</button>
            <button class="prog-vtab" data-v="mes"      onclick="WMS_MODULES.programacion.cambiarVista('mes')">Mes</button>
          </div>
          <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.programacion.navegar(-1)" title="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
          <span id="prog-rango" style="font-weight:600;min-width:180px;text-align:center;"></span>
          <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.programacion.navegar(1)" title="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
          <span style="margin-left:auto;font-size:.8rem;color:#64748b;">
            <span style="display:inline-block;width:12px;height:12px;background:#fee2e2;border:1px solid #fca5a5;vertical-align:middle;margin-right:4px;"></span>Festivo
            <span style="display:inline-block;width:12px;height:12px;background:#f1f5f9;border:1px solid #cbd5e1;vertical-align:middle;margin-left:12px;margin-right:4px;"></span>Fin de semana
            <span style="margin-left:12px;color:#334155;"><i class="fa-solid fa-arrows-up-down-left-right"></i> Arrastra para mover · <kbd style="background:#f1f5f9;padding:0 4px;border:1px solid #cbd5e1;border-radius:3px;font-size:.72rem;">Ctrl</kbd>+arrastrar para copiar</span>
          </span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 280px;gap:12px;align-items:start;">
          <div id="prog-cal-wrap" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;"></div>
          <aside id="prog-descansos" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px;position:sticky;top:12px;max-height:calc(100vh - 160px);overflow:auto;"></aside>
        </div>
      </div>
      <style>
        .prog-vtab{padding:5px 12px;background:#fff;border:none;cursor:pointer;font-size:.82rem;border-right:1px solid #cbd5e1;}
        .prog-vtab:last-child{border-right:none;}
        .prog-vtab.active{background:#1e293b;color:#fff;}
        .prog-cal-head{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));background:#1e293b;color:#fff;font-weight:700;font-size:.78rem;text-align:center;}
        .prog-cal-head > div{padding:8px 4px;border-right:1px solid #334155;container-type:inline-size;overflow:hidden;white-space:nowrap;min-width:0;}
        .prog-cal-head > div:last-child{border-right:none;}
        .prog-cal-head .dow-long{display:none;}
        .prog-cal-head .dow-short{display:inline;}
        @container (min-width: 90px){
          .prog-cal-head .dow-long{display:inline;}
          .prog-cal-head .dow-short{display:none;}
        }
        .prog-cal-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-top:1px solid #e2e8f0;}
        .prog-cal-cell{min-width:0;overflow:hidden;min-height:120px;padding:6px;border-right:1px solid #e2e8f0;background:#fff;position:relative;display:flex;flex-direction:column;}
        .prog-cal-cell.drag-over{outline:2px dashed #2b5fc7;outline-offset:-3px;background:#eff6ff;}
        .prog-cal-cell:last-child{border-right:none;}
        .prog-cal-cell.other{background:#fafbfc;color:#94a3b8;}
        .prog-cal-cell.weekend{background:#f8fafc;}
        .prog-cal-cell.holiday{background:#fef2f2;}
        .prog-cal-cell.today{box-shadow:inset 0 0 0 2px #2b5fc7;}
        .prog-cal-date{display:flex;justify-content:space-between;align-items:center;font-size:.78rem;font-weight:700;margin-bottom:4px;}
        .prog-cal-date .num{color:#1e293b;}
        .prog-cal-cell.other .prog-cal-date .num{color:#94a3b8;}
        .prog-cal-holiday-tag{font-size:.6rem;background:#dc2626;color:#fff;padding:1px 5px;border-radius:3px;font-weight:600;}
        .prog-cal-add{position:absolute;top:4px;right:4px;width:18px;height:18px;border-radius:50%;background:#2b5fc7;color:#fff;border:none;font-size:.65rem;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:.6;}
        .prog-cal-add:hover{opacity:1;}
        .prog-asig{font-size:.68rem;padding:2px 4px;margin-bottom:2px;border-radius:3px;cursor:grab;display:flex;gap:4px;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;min-width:0;}
        .prog-asig:hover{background:#e0e7ff;}
        .prog-asig:active{cursor:grabbing;}
        .prog-asig.dragging{opacity:.4;}
        .prog-asig .turno{font-weight:700;color:#1e293b;}
        .prog-asig .rol{background:#fff;padding:0 4px;border-radius:2px;font-weight:600;}
        .prog-asig .pers{flex:1;white-space:nowrap;}
        .prog-desc-chip{display:inline-block;background:#e2e8f0;color:#334155;font-size:.65rem;padding:2px 5px;border-radius:3px;margin:2px 2px 0 0;}
      </style>
    `);
    document.getElementById('prog-mes').addEventListener('change', (e) => {
      this._mes = e.target.value;
      this._offset = 0;
      this.cargarMatriz();
    });
    this.cargarMatriz();
  },

  cambiarVista(v) {
    this._vista = v;
    this._offset = 0;
    this.renderCalendario();
  },

  navegar(delta) {
    const paginas = this.contarPaginas();
    this._offset += delta;
    if (this._offset < 0) this._offset = Math.max(0, paginas - 1);
    if (this._offset >= paginas) this._offset = 0;
    this.renderCalendario();
  },

  contarPaginas() {
    if (!this._matriz) return 1;
    const semanas = this.semanasDelMes();
    if (this._vista === 'semana')   return semanas.length;
    if (this._vista === 'quincena') return Math.ceil(semanas.length / 2);
    return 1;
  },

  async cargarMatriz() {
    if (!this._mes) return;
    const [anio, mes] = this._mes.split('-').map(Number);
    WMS.spinner();
    try {
      const r = await API.get(`/programacion/matriz?anio=${anio}&mes=${mes}`);
      this._matriz = r.data || r;
      this.renderCalendario();
    } catch (e) {
      WMS.toast('error', 'No se pudo cargar: ' + (e.message || e));
    }
    WMS.spinnerHide();
  },

  // ── Semanas del mes (Lun-Dom), incluye días del mes anterior/siguiente ──
  semanasDelMes() {
    const m = this._matriz; if (!m) return [];
    const anio = m.anio, mes = m.mes;
    const primero = new Date(anio, mes - 1, 1);
    // retroceder al lunes
    const dow1 = (primero.getDay() + 6) % 7; // 0=lun..6=dom
    const inicio = new Date(primero); inicio.setDate(1 - dow1);
    const ultimoMes = new Date(anio, mes, 0).getDate();
    const ultimo = new Date(anio, mes - 1, ultimoMes);
    const dowU = (ultimo.getDay() + 6) % 7;
    const fin = new Date(ultimo); fin.setDate(ultimo.getDate() + (6 - dowU));
    const semanas = [];
    let cur = new Date(inicio);
    while (cur <= fin) {
      const sem = [];
      for (let i = 0; i < 7; i++) {
        sem.push(new Date(cur));
        cur.setDate(cur.getDate() + 1);
      }
      semanas.push(sem);
    }
    return semanas;
  },

  renderCalendario() {
    const m = this._matriz; if (!m) return;
    // Actualizar tabs
    document.querySelectorAll('.prog-vtab').forEach(b => b.classList.toggle('active', b.dataset.v === this._vista));

    const semanas = this.semanasDelMes();
    let semanasVisibles;
    if (this._vista === 'semana')        semanasVisibles = [semanas[this._offset] || semanas[0]];
    else if (this._vista === 'quincena') semanasVisibles = semanas.slice(this._offset * 2, this._offset * 2 + 2);
    else                                 semanasVisibles = semanas;

    // Agrupar asignaciones por día (fecha YYYY-MM-DD), separando descansos
    const asigsPorDia = {};
    const descansosPorDia = {};  // día (dentro del mes) -> [persona]
    const filaDescanso = m.filas.find(f => f.codigo === 'DESCANSO');
    const descansoId = filaDescanso ? filaDescanso.id : null;

    for (const key in m.celdas) {
      const c = m.celdas[key];
      const [dia, filaId, slot] = key.split('-').map(Number);
      const fecha = `${m.anio}-${String(m.mes).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
      if (filaId === descansoId) {
        (descansosPorDia[dia] = descansosPorDia[dia] || []).push(c.personal_id);
      } else {
        (asigsPorDia[fecha] = asigsPorDia[fecha] || []).push({ ...c, dia, filaId, slot });
      }
    }

    const nombresCorto = ['LUN', 'MAR', 'MIÉ', 'JUE', 'VIE', 'SÁB', 'DOM'];
    const nombresLargo = ['LUNES', 'MARTES', 'MIÉRCOLES', 'JUEVES', 'VIERNES', 'SÁBADO', 'DOMINGO'];
    const fmtFecha = (d) => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const hoyStr = fmtFecha(new Date());
    const esFinde = (d) => d.getDay() === 0 || d.getDay() === 6;

    let html = `<div class="prog-cal-head">${nombresCorto.map((n, i) =>
      `<div><span class="dow-short">${n}</span><span class="dow-long">${nombresLargo[i]}</span></div>`
    ).join('')}</div>`;
    for (const sem of semanasVisibles) {
      html += `<div class="prog-cal-week">`;
      for (const d of sem) {
        const fs = fmtFecha(d);
        const inMonth = d.getMonth() === (m.mes - 1) && d.getFullYear() === m.anio;
        const festivo = m.festivos[fs];
        const classes = ['prog-cal-cell'];
        if (!inMonth) classes.push('other');
        else if (festivo) classes.push('holiday');
        else if (esFinde(d)) classes.push('weekend');
        if (fs === hoyStr) classes.push('today');

        const asigs = this._ordenarAsigs(asigsPorDia[fs] || []);
        const asigsHtml = asigs.map(a => this.renderAsigChip(a)).join('');
        const descansoChips = inMonth
          ? (descansosPorDia[d.getDate()] || [])
              .map(pid => (m.personal.find(p => p.id == pid) || {}).nombre || '?')
              .map(n => `<span class="prog-desc-chip" title="Descansa — ${WMS.esc(n)}">💤 ${WMS.esc(this._nombreCorto(n))}</span>`)
              .join('')
          : '';

        const dropHandlers = inMonth
          ? `ondragover="WMS_MODULES.programacion.onDragOver(event)"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="WMS_MODULES.programacion.onDrop(event,'${fs}')"`
          : '';
        html += `<div class="${classes.join(' ')}" data-fecha="${fs}" ${dropHandlers}>
          <div class="prog-cal-date">
            <span class="num">${d.getDate()}</span>
            ${festivo ? `<span class="prog-cal-holiday-tag" title="${WMS.esc(festivo)}">FESTIVO</span>` : ''}
          </div>
          ${inMonth ? `<button class="prog-cal-add" title="Agregar asignación" onclick="WMS_MODULES.programacion.abrirCelda('${fs}')">+</button>` : ''}
          <div style="flex:1;overflow:auto;min-width:0;">${asigsHtml}${descansoChips}</div>
        </div>`;
      }
      html += `</div>`;
    }
    document.getElementById('prog-cal-wrap').innerHTML = html + this.renderLeyenda();

    // Rango label
    const prim = semanasVisibles[0][0];
    const ult  = semanasVisibles[semanasVisibles.length - 1][6];
    const fmt  = (d) => `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}`;
    document.getElementById('prog-rango').textContent = `${fmt(prim)} — ${fmt(ult)}`;

    this.renderPanelDescansos();
  },

  renderAsigChip(a) {
    const m = this._matriz;
    const fila  = m.filas.find(f => f.id == a.filaId);
    const tarea = m.tareas.find(t => t.id == a.tarea_id);
    const pers  = m.personal.find(p => p.id == a.personal_id);
    const key = `${a.dia}-${a.filaId}-${a.slot}`;
    const bg = tarea ? tarea.color : '#f1f5f9';
    return `<div class="prog-asig" style="background:${bg};" draggable="true"
      data-key="${key}"
      ondragstart="WMS_MODULES.programacion.onDragStart(event,'${key}')"
      ondragend="WMS_MODULES.programacion.onDragEnd(event)"
      onclick="WMS_MODULES.programacion.editarAsig('${key}')"
      title="Arrastra para mover · Ctrl+arrastra para copiar">
      <span class="turno">${fila ? WMS.esc(fila.codigo.replace('TURNO-','T')) : '?'}</span>
      ${tarea ? `<span class="rol">${WMS.esc(tarea.codigo)}</span>` : ''}
      <span class="pers" title="${pers ? WMS.esc(pers.nombre) : ''}">${pers ? WMS.esc(this._nombreCorto(pers.nombre)) : '<i style="color:#dc2626;">Sin asignar</i>'}</span>
    </div>`;
  },

  // ── Panel lateral: Descansos (solo quienes descansan) ───────────────────
  renderPanelDescansos() {
    const m = this._matriz; if (!m) return;
    const el = document.getElementById('prog-descansos'); if (!el) return;

    const filaDescanso = m.filas.find(f => f.codigo === 'DESCANSO');
    if (!filaDescanso) { el.innerHTML = '<div style="color:#dc2626;">Fila DESCANSO no existe.</div>'; return; }

    // Agrupar: persona → [días] (solo quienes tienen al menos un descanso)
    const descansosPorPersona = {};
    for (const key in m.celdas) {
      const [dia, filaId] = key.split('-').map(Number);
      if (filaId !== filaDescanso.id) continue;
      const pid = m.celdas[key].personal_id;
      if (!pid) continue;
      (descansosPorPersona[pid] = descansosPorPersona[pid] || []).push(dia);
    }

    const dowDe = (dia) => this._nombresDiaLargo[this._dowDe(dia)];

    const filas = Object.entries(descansosPorPersona)
      .map(([pid, dias]) => {
        const p = m.personal.find(x => x.id == pid);
        return { pid: Number(pid), nombre: p ? p.nombre : `(ID ${pid})`, dias: dias.sort((a,b)=>a-b) };
      })
      .sort((a, b) => a.nombre.localeCompare(b.nombre));

    // ── Horarios de los turnos (encima de descansos) ──────────────────
    const turnos = m.filas
      .filter(f => f.codigo !== 'DESCANSO' && f.hora_inicio && f.hora_fin)
      .sort((a, b) => (a.orden || 0) - (b.orden || 0));
    let html = '';
    if (turnos.length) {
      html += `<div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;">
        <strong style="font-size:.9rem;display:block;margin-bottom:8px;"><i class="fa-solid fa-clock"></i> Horarios de turnos</strong>
        <div style="display:flex;flex-direction:column;gap:4px;">
          ${turnos.map(t => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 8px;background:#1e293b;color:#fff;border-radius:4px;font-size:.75rem;">
              <span style="font-weight:700;">${WMS.esc(t.codigo)}</span>
              <span>${t.hora_inicio.slice(0,5)} - ${t.hora_fin.slice(0,5)}</span>
            </div>`).join('')}
        </div>
      </div>`;
    }

    // ── Sección de descansos ──────────────────────────────────────────
    html += `<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <strong style="font-size:.9rem;"><i class="fa-solid fa-bed"></i> Descansos</strong>
      <button class="btn btn-primary btn-sm" style="padding:4px 10px;font-size:.75rem;" onclick="WMS_MODULES.programacion.agregarDescanso()" title="Agregar personal que descansa">
        <i class="fa-solid fa-plus"></i>
      </button>
    </div>`;

    if (!filas.length) {
      html += `<div style="color:#94a3b8;font-size:.78rem;text-align:center;padding:16px 8px;border:1px dashed #e2e8f0;border-radius:6px;">
        Nadie tiene descanso registrado este mes.<br><br>
        Use el botón <b>+</b> para agregar.
      </div>`;
    } else {
      html += '<div style="display:flex;flex-direction:column;gap:6px;">';
      for (const f of filas) {
        const chips = f.dias.map(d => `<span class="prog-desc-chip" title="${dowDe(d)} ${d}">${dowDe(d)} ${d}
          <button onclick="WMS_MODULES.programacion.quitarDescanso(${f.pid},${d})" title="Quitar" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0 0 0 3px;font-weight:700;">×</button>
        </span>`).join('');
        html += `<div style="padding:8px;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;">
          <div style="display:flex;justify-content:space-between;align-items:start;gap:4px;">
            <div style="font-weight:600;font-size:.8rem;flex:1;" title="${WMS.esc(f.nombre)}">${WMS.esc(this._nombreCorto(f.nombre))}</div>
            <button onclick="WMS_MODULES.programacion.agregarDescanso(${f.pid})" title="Agregar día" style="background:none;border:none;color:#2b5fc7;cursor:pointer;padding:0;"><i class="fa-solid fa-circle-plus"></i></button>
          </div>
          <div style="margin-top:4px;">${chips}</div>
        </div>`;
      }
      html += '</div>';
    }

    el.innerHTML = html;
  },

  // ── Nombres de día en español ───────────────────────────────────────────
  _nombresDiaCorto: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
  _nombresDiaLargo: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
  _dowDe(dia) {
    const m = this._matriz;
    return (new Date(m.anio, m.mes - 1, dia).getDay() + 6) % 7;
  },

  // Orden fijo de los chips dentro de una celda del cronograma, por pedido
  // explícito 2026-10-12: primero RUTAs (por su orden configurado), después
  // los demás por código de rol en este orden: REC, INV, ASE, LAV, DVO, ORG.
  _ordenRol: { REC: 1, INV: 2, ASE: 3, LAV: 4, DVO: 5, ORG: 6 },
  _ordenarAsigs(asigs) {
    const m = this._matriz;
    const ord = this._ordenRol;
    return asigs.slice().sort((a, b) => {
      const fa = m.filas.find(f => f.id == a.filaId);
      const fb = m.filas.find(f => f.id == b.filaId);
      const aRuta = fa && fa.codigo.startsWith('RUTA');
      const bRuta = fb && fb.codigo.startsWith('RUTA');
      if (aRuta !== bRuta) return aRuta ? -1 : 1;
      if (aRuta && bRuta) return (fa.orden || 0) - (fb.orden || 0);
      const ca = (m.tareas.find(t => t.id == a.tarea_id) || {}).codigo;
      const cb = (m.tareas.find(t => t.id == b.tarea_id) || {}).codigo;
      return (ord[ca] || 99) - (ord[cb] || 99);
    });
  },

  // "ANDRES VILLADA ESTEBAN DIAZ" → "Andres V."  (primer nombre + inicial del
  // primer apellido). Si solo hay una palabra, devuelve el nombre a secas.
  _nombreCorto(nombre) {
    if (!nombre) return '';
    const partes = String(nombre).trim().split(/\s+/);
    const titulo = (s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
    if (partes.length === 1) return titulo(partes[0]);
    return `${titulo(partes[0])} ${partes[1].charAt(0).toUpperCase()}.`;
  },

  // ── Agregar descanso (persona + día(s)) — calendario Lun-Dom ────────────
  agregarDescanso(pidPrefijado) {
    const m = this._matriz;
    const filaDescanso = m.filas.find(f => f.codigo === 'DESCANSO');
    if (!filaDescanso) return;
    if (!m.personal.length) { WMS.toast('error', 'No hay personal activo.'); return; }

    const diasEnMes = new Date(m.anio, m.mes, 0).getDate();
    const dowPrimero = this._dowDe(1); // 0=Lun..6=Dom
    const festivos = m.festivos || {};

    // Descansos actuales de esta persona (para pre-check)
    const yaDescansa = new Set();
    for (const key in m.celdas) {
      const [dia, filaId] = key.split('-').map(Number);
      if (filaId !== filaDescanso.id) continue;
      const c = m.celdas[key];
      if (pidPrefijado && c.personal_id == pidPrefijado) yaDescansa.add(dia);
    }

    const persOpts = m.personal.map(p => `<option value="${p.id}" ${pidPrefijado==p.id?'selected':''}>${WMS.esc(p.nombre)}</option>`).join('');

    // Grid tipo calendario (6 filas máx × 7 columnas)
    let cells = '';
    for (let i = 0; i < dowPrimero; i++) cells += `<div></div>`; // padding inicial
    for (let d = 1; d <= diasEnMes; d++) {
      const fs = `${m.anio}-${String(m.mes).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const festivo = festivos[fs];
      const dow = this._dowDe(d);
      const esFinde = dow === 5 || dow === 6;
      const bg = festivo ? '#fee2e2' : (esFinde ? '#f1f5f9' : '#fff');
      const checked = yaDescansa.has(d) ? 'checked' : '';
      cells += `<label title="${festivo ? WMS.esc(festivo) : ''}"
        style="position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 4px;border:1px solid #e2e8f0;border-radius:6px;background:${bg};cursor:pointer;font-size:.8rem;min-height:52px;transition:all .1s;"
        onmouseover="this.style.borderColor='#2b5fc7'" onmouseout="this.style.borderColor='#e2e8f0'">
        <input type="checkbox" data-dia="${d}" ${checked} style="position:absolute;top:4px;right:4px;cursor:pointer;">
        <div style="font-weight:700;font-size:.95rem;">${d}</div>
        ${festivo ? `<div style="font-size:.55rem;color:#dc2626;font-weight:700;line-height:1;">FESTIVO</div>` : ''}
      </label>`;
    }

    Swal.fire({
      title: 'Agregar descanso',
      html: `
        <div style="text-align:left;display:grid;gap:12px;">
          <div><label style="display:block;font-weight:600;margin-bottom:4px;">Personal</label>
            <select id="sw-pid" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:5px;font-size:.9rem;background:#fff;" ${pidPrefijado?'disabled':''}>${persOpts}</select></div>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <label style="font-weight:600;">Días que descansa</label>
              <small style="color:#64748b;">${m.anio}-${String(m.mes).padStart(2,'0')}</small>
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;margin-bottom:4px;">
              ${this._nombresDiaCorto.map(n => `<div style="text-align:center;font-weight:700;font-size:.72rem;color:#64748b;padding:4px 0;">${n}</div>`).join('')}
            </div>
            <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;">${cells}</div>
          </div>
          <small style="color:#64748b;">Marca uno o más días. Los días ya marcados se mantienen; destildarlos los quita.</small>
        </div>`,
      width: 620,
      showCancelButton: true,
      confirmButtonText: 'Guardar',
      preConfirm: () => {
        const pidEl = document.getElementById('sw-pid');
        const pid = Number(pidEl.value);
        if (!pid) { Swal.showValidationMessage('Seleccione un empleado.'); return false; }
        const chks = [...document.querySelectorAll('input[type=checkbox][data-dia]')];
        const dias = chks.filter(c => c.checked).map(c => Number(c.dataset.dia));
        const quitar = chks.filter(c => !c.checked && yaDescansa.has(Number(c.dataset.dia))).map(c => Number(c.dataset.dia));
        return { pid, dias, quitar };
      },
      didOpen: () => {
        // Click en la celda = toggle del checkbox (más amplio y natural)
        document.querySelectorAll('label[title]').forEach(l => {
          l.addEventListener('click', (e) => {
            if (e.target.tagName === 'INPUT') return;
            const chk = l.querySelector('input[type=checkbox]');
            if (chk) { chk.checked = !chk.checked; e.preventDefault(); }
          });
        });
      }
    }).then(async (r) => {
      if (!r.isConfirmed || !r.value) return;
      await this.aplicarDescansos(r.value.pid, r.value.dias, r.value.quitar, filaDescanso.id);
    });
  },

  async quitarDescanso(pid, dia) {
    const m = this._matriz;
    const filaDescanso = m.filas.find(f => f.codigo === 'DESCANSO');
    if (!filaDescanso) return;
    // Buscar el slot exacto donde está esa persona ese día
    let slot = null;
    for (let s = 0; s < (filaDescanso.slots || 20); s++) {
      const c = m.celdas[`${dia}-${filaDescanso.id}-${s}`];
      if (c && c.personal_id == pid) { slot = s; break; }
    }
    if (slot === null) return;
    await this.enviarCelda({ dia, fila_id: filaDescanso.id, slot, personal_id: null, tarea_id: null, descansa: false });
  },

  async aplicarDescansos(pid, diasAgregar, diasQuitar, filaDescansoId) {
    const m = this._matriz;
    const [anio, mes] = this._mes.split('-').map(Number);
    const celdas = [];

    // Quitar: buscar slot donde está esa persona y vaciarlo
    for (const dia of diasQuitar) {
      for (let s = 0; s < 20; s++) {
        const c = m.celdas[`${dia}-${filaDescansoId}-${s}`];
        if (c && c.personal_id == pid) {
          celdas.push({ dia, fila_id: filaDescansoId, slot: s, personal_id: null, tarea_id: null, descansa: false });
          break;
        }
      }
    }
    // Agregar: si ya existe (ya tiene descanso ese día), no duplicar; si no, primer slot libre
    for (const dia of diasAgregar) {
      let yaEsta = false;
      let slotLibre = -1;
      for (let s = 0; s < 20; s++) {
        const c = m.celdas[`${dia}-${filaDescansoId}-${s}`];
        if (c && c.personal_id == pid) { yaEsta = true; break; }
        if (!c && slotLibre < 0) slotLibre = s;
      }
      if (yaEsta) continue;
      if (slotLibre < 0) { WMS.toast('warning', `Día ${dia}: límite de descansos alcanzado.`); continue; }
      celdas.push({ dia, fila_id: filaDescansoId, slot: slotLibre, personal_id: pid, tarea_id: null, descansa: true });
    }

    if (!celdas.length) { WMS.toast('info', 'Sin cambios.'); return; }
    WMS.spinner();
    try {
      await API.post('/programacion/matriz', { anio, mes, celdas });
      await this.cargarMatriz();
      WMS.toast('success', 'Descansos actualizados');
    } catch (e) {
      WMS.toast('error', 'Error: ' + (e.message || e));
    }
    WMS.spinnerHide();
  },

  // ── Modal: agregar/editar asignación en una celda ───────────────────────
  async abrirCelda(fechaStr) {
    const dia = Number(fechaStr.split('-')[2]);
    this.modalAsig({ dia, fecha: fechaStr });
  },

  editarAsig(key) {
    const m = this._matriz;
    const [dia, filaId, slot] = key.split('-').map(Number);
    const c = m.celdas[key] || {};
    const fecha = `${m.anio}-${String(m.mes).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
    this.modalAsig({ dia, fecha, filaId, slot, personal_id: c.personal_id, tarea_id: c.tarea_id });
  },

  modalAsig(a) {
    const m = this._matriz;
    const esEdicion = !!a.filaId;

    // Excluir DESCANSO. Las RUTAS ya programadas ese día se ocultan (son únicas);
    // los TURNOS siempre quedan en el listado para permitir asignar la misma
    // franja horaria a varias personas. En edición, la fila actual siempre aparece.
    const filas = m.filas.filter(f => {
      if (f.codigo === 'DESCANSO') return false;
      if (esEdicion && f.id === a.filaId) return true;
      if (!f.codigo.startsWith('RUTA')) return true; // turnos / apoyo / otros: fijos
      // Ruta: ocultar si todos sus slots están ocupados ese día
      const maxSlots = f.slots || 1;
      for (let s = 0; s < maxSlots; s++) {
        if (!m.celdas[`${a.dia}-${f.id}-${s}`]) return true;
      }
      return false;
    });

    if (!filas.length) { WMS.toast('warning', 'Todos los turnos y rutas ya están programados este día.'); return; }
    if (!m.tareas.length) { WMS.toast('error', 'No hay tareas configuradas. Crea una en "Tareas".'); return; }
    if (!m.personal.length) { WMS.toast('error', 'No hay personal activo.'); return; }

    const selStyle = 'width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:5px;font-size:.9rem;background:#fff;';
    const filaSel  = (id) => filas.map(f => `<option value="${f.id}" ${f.id==id?'selected':''}>${WMS.esc(f.codigo)}${f.hora_inicio?` (${f.hora_inicio.slice(0,5)}-${f.hora_fin.slice(0,5)})`:''}</option>`).join('');
    const tareaSel = (id) => `<option value="">— Sin rol —</option>` + m.tareas.map(t => `<option value="${t.id}" ${t.id==id?'selected':''}>${WMS.esc(t.codigo)} · ${WMS.esc(t.nombre)}</option>`).join('');
    const persSel  = (id) => `<option value="">— Sin asignar —</option>` + m.personal.map(p => `<option value="${p.id}" ${p.id==id?'selected':''}>${WMS.esc(p.nombre)}</option>`).join('');

    Swal.fire({
      title: esEdicion ? `Editar asignación — ${a.fecha}` : `Nueva asignación — ${a.fecha}`,
      html: `
        <div style="text-align:left;display:grid;gap:12px;padding:4px 2px;">
          <div><label style="display:block;font-weight:600;margin-bottom:4px;">Turno / Ruta</label>
            <select id="sw-fila" style="${selStyle}">${filaSel(a.filaId)}</select></div>
          <div><label style="display:block;font-weight:600;margin-bottom:4px;">Rol / Tarea</label>
            <select id="sw-tarea" style="${selStyle}">${tareaSel(a.tarea_id)}</select></div>
          <div><label style="display:block;font-weight:600;margin-bottom:4px;">Personal</label>
            <select id="sw-pers" style="${selStyle}">${persSel(a.personal_id)}</select></div>
          <small style="color:#64748b;">Los descansos se gestionan en el panel lateral.</small>
        </div>`,
      showCancelButton: true,
      confirmButtonText: esEdicion ? 'Guardar' : 'Crear',
      showDenyButton: esEdicion,
      denyButtonText: 'Eliminar',
      preConfirm: () => {
        const filaEl = document.getElementById('sw-fila');
        const fid = Number(filaEl && filaEl.value);
        if (!fid) { Swal.showValidationMessage('Seleccione un turno / ruta.'); return false; }
        return {
          fila_id:     fid,
          tarea_id:    Number(document.getElementById('sw-tarea').value) || null,
          personal_id: Number(document.getElementById('sw-pers').value) || null,
        };
      }
    }).then(async (r) => {
      if (r.isDenied) {
        await this.enviarCelda({ dia: a.dia, fila_id: a.filaId, slot: a.slot, personal_id: null, tarea_id: null, descansa: false });
        return;
      }
      if (!r.isConfirmed || !r.value) return;
      const v = r.value;
      const fila = m.filas.find(f => f.id === v.fila_id);
      if (!fila) { WMS.toast('error', 'Turno inválido.'); return; }

      let slot = a.slot;
      // Rutas: respetan slots configurados (son únicas).
      // Turnos/apoyo/otros: sin límite — buscamos el primer hueco disponible.
      const esRuta = fila.codigo.startsWith('RUTA');
      const maxBuscar = esRuta ? (fila.slots || 1) : 9999;
      const buscarSlotLibre = (filaId) => {
        for (let s = 0; s < maxBuscar; s++) {
          if (!m.celdas[`${a.dia}-${filaId}-${s}`]) return s;
        }
        return -1;
      };

      if (!esEdicion) {
        slot = buscarSlotLibre(v.fila_id);
        if (slot < 0) { WMS.toast('warning', `Ruta ${fila.codigo} ya está asignada este día.`); return; }
      } else if (v.fila_id !== a.filaId) {
        await this.enviarCelda({ dia: a.dia, fila_id: a.filaId, slot: a.slot, personal_id: null, tarea_id: null, descansa: false });
        slot = buscarSlotLibre(v.fila_id);
        if (slot < 0) { WMS.toast('warning', `Ruta ${fila.codigo} ya está asignada este día.`); return; }
      }

      await this.enviarCelda({ dia: a.dia, fila_id: v.fila_id, slot, personal_id: v.personal_id, tarea_id: v.tarea_id, descansa: false });
    });
  },

  // ── Drag & Drop: mover/copiar asignación entre días ────────────────────
  onDragStart(ev, key) {
    ev.dataTransfer.setData('text/plain', key);
    ev.dataTransfer.effectAllowed = 'copyMove';
    ev.currentTarget.classList.add('dragging');
  },
  onDragEnd(ev) {
    ev.currentTarget.classList.remove('dragging');
    document.querySelectorAll('.prog-cal-cell.drag-over').forEach(c => c.classList.remove('drag-over'));
  },
  onDragOver(ev) {
    ev.preventDefault();
    ev.dataTransfer.dropEffect = ev.ctrlKey ? 'copy' : 'move';
    ev.currentTarget.classList.add('drag-over');
  },
  async onDrop(ev, fechaDestino) {
    ev.preventDefault();
    ev.currentTarget.classList.remove('drag-over');
    const keyOrigen = ev.dataTransfer.getData('text/plain');
    if (!keyOrigen) return;
    const copiar = ev.ctrlKey;
    const [diaOrig, filaId, slotOrig] = keyOrigen.split('-').map(Number);
    const diaDest = Number(fechaDestino.split('-')[2]);
    if (diaOrig === diaDest) return; // misma celda = sin cambios

    const m = this._matriz;
    const cell = m.celdas[keyOrigen];
    if (!cell) return;
    const fila = m.filas.find(f => f.id === filaId);
    if (!fila) return;

    // Buscar slot libre en el día destino (rutas respetan slots, turnos no)
    const esRuta = fila.codigo.startsWith('RUTA');
    const maxBuscar = esRuta ? (fila.slots || 1) : 9999;
    let slotDest = -1;
    for (let s = 0; s < maxBuscar; s++) {
      if (!m.celdas[`${diaDest}-${filaId}-${s}`]) { slotDest = s; break; }
    }
    if (slotDest < 0) {
      WMS.toast('warning', `${fila.codigo} ya está programado el día ${diaDest}.`);
      return;
    }

    const [anio, mes] = this._mes.split('-').map(Number);
    const celdas = [{
      dia: diaDest, fila_id: filaId, slot: slotDest,
      personal_id: cell.personal_id, tarea_id: cell.tarea_id, descansa: false,
    }];
    if (!copiar) {
      celdas.push({
        dia: diaOrig, fila_id: filaId, slot: slotOrig,
        personal_id: null, tarea_id: null, descansa: false,
      });
    }

    WMS.spinner();
    try {
      await API.post('/programacion/matriz', { anio, mes, celdas });
      await this.cargarMatriz();
      WMS.toast('success', copiar ? `Copiado al día ${diaDest}` : `Movido al día ${diaDest}`);
    } catch (e) {
      WMS.toast('error', 'Error: ' + (e.message || e));
    }
    WMS.spinnerHide();
  },

  async enviarCelda(celda) {
    const [anio, mes] = this._mes.split('-').map(Number);
    try {
      await API.post('/programacion/matriz', { anio, mes, celdas: [celda] });
      await this.cargarMatriz();
      WMS.toast('success', 'Guardado');
    } catch (e) {
      WMS.toast('error', 'Error: ' + (e.message || e));
    }
  },

  // ── Leyenda de ROL/tarea bajo el horario ────────────────────────────────
  renderLeyenda() {
    const m = this._matriz; if (!m) return '';
    if (!m.tareas || !m.tareas.length) return '';
    const chips = m.tareas.map(t => `
      <div style="display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;font-size:.78rem;">
        <span style="background:${t.color};padding:2px 7px;border-radius:3px;font-weight:700;color:#1e293b;">${WMS.esc(t.codigo)}</span>
        <span>${WMS.esc(t.nombre)}</span>
      </div>`).join('');
    return `<div style="margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
      <div style="font-weight:700;font-size:.82rem;margin-bottom:8px;color:#334155;">
        <i class="fa-solid fa-tags"></i> Convenciones de ROL
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">${chips}</div>
    </div>`;
  },

  // ── PERSONAL (lee de tabla `personal`, enlaza a maestro) ────────────────
  async showPersonal() {
    WMS.setContent(`
      <div style="padding:12px;">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;">
          <h3 style="margin:0;">Personal disponible para programación</h3>
          <button class="btn btn-secondary btn-sm" style="margin-left:auto;" onclick="WMS.nav('maestro','personal')">
            <i class="fa-solid fa-up-right-from-square"></i> Editar en Maestros
          </button>
        </div>
        <div style="font-size:.85rem;color:#64748b;margin-bottom:10px;">
          Solo el personal <b>activo</b> aparece en los selectores del horario.
        </div>
        <div id="prog-pers-list"></div>
      </div>
    `);
    const r = await API.get('/programacion/personal');
    const rows = r.data || [];
    document.getElementById('prog-pers-list').innerHTML = `<table class="data-table" style="width:100%;background:#fff;border-radius:6px;overflow:hidden;">
      <thead><tr style="background:#f1f5f9;"><th style="padding:8px;">Nombre</th><th>Documento</th><th>Rol</th><th>Estado</th></tr></thead>
      <tbody>${rows.map(p => `
        <tr><td style="padding:6px 8px;">${WMS.esc(p.nombre)}</td>
            <td>${WMS.esc(p.documento || '')}</td>
            <td>${WMS.esc(p.rol || '')}</td>
            <td>${p.activo ? '<span style="color:#15803d;">● Activo</span>' : '<span style="color:#dc2626;">● Inactivo</span>'}</td></tr>
      `).join('') || '<tr><td colspan="4" style="padding:14px;text-align:center;color:#64748b;">Sin personal.</td></tr>'}
      </tbody></table>`;
  },

  // ── TAREAS CRUD ─────────────────────────────────────────────────────────
  async showTareas() {
    WMS.setContent(`
      <div style="padding:12px;max-width:900px;">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;">
          <h3 style="margin:0;">Tareas / ROL</h3>
          <button class="btn btn-primary btn-sm" style="margin-left:auto;" onclick="WMS_MODULES.programacion.editarTarea()">
            <i class="fa-solid fa-plus"></i> Nueva tarea
          </button>
        </div>
        <div id="prog-tareas-list"></div>
      </div>`);
    this.renderTareas();
  },
  async renderTareas() {
    const r = await API.get('/programacion/tareas');
    const rows = r.data || [];
    document.getElementById('prog-tareas-list').innerHTML = `<table class="data-table" style="width:100%;background:#fff;border-radius:6px;">
      <thead><tr style="background:#f1f5f9;"><th style="padding:8px;">Código</th><th>Nombre</th><th>Color</th><th>Orden</th><th>Estado</th><th></th></tr></thead>
      <tbody>${rows.map(t => `
        <tr><td style="padding:6px 8px;font-weight:700;">${WMS.esc(t.codigo)}</td>
            <td>${WMS.esc(t.nombre)}</td>
            <td><span style="display:inline-block;width:40px;height:18px;background:${t.color};border:1px solid #cbd5e1;border-radius:3px;"></span> ${WMS.esc(t.color)}</td>
            <td>${t.orden}</td>
            <td>${t.activo ? 'Activa' : 'Inactiva'}</td>
            <td style="text-align:right;">
              <button class="btn btn-sm btn-secondary" onclick='WMS_MODULES.programacion.editarTarea(${JSON.stringify(t)})'><i class="fa-solid fa-pen"></i></button>
              <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.programacion.borrarTarea(${t.id},'${WMS.esc(t.codigo)}')"><i class="fa-solid fa-trash"></i></button>
            </td></tr>
      `).join('')}</tbody></table>`;
  },
  editarTarea(t) {
    t = t || { codigo:'', nombre:'', color:'#fde2e4', orden:0, activo:true };
    Swal.fire({
      title: t.id ? 'Editar tarea' : 'Nueva tarea',
      html: `<div style="text-align:left;display:grid;gap:8px;">
        <label>Código <input id="sw-cod" class="swal2-input" style="margin:0;text-transform:uppercase;" maxlength="20" value="${WMS.esc(t.codigo)}"></label>
        <label>Nombre <input id="sw-nom" class="swal2-input" style="margin:0;" value="${WMS.esc(t.nombre)}"></label>
        <label>Color <input id="sw-col" type="color" class="swal2-input" style="margin:0;height:40px;" value="${t.color}"></label>
        <label>Orden <input id="sw-ord" type="number" class="swal2-input" style="margin:0;" value="${t.orden}"></label>
        <label><input id="sw-act" type="checkbox" ${t.activo ? 'checked' : ''}> Activa</label></div>`,
      showCancelButton: true, confirmButtonText: 'Guardar',
      preConfirm: () => ({ id: t.id,
        codigo:  document.getElementById('sw-cod').value.trim(),
        nombre:  document.getElementById('sw-nom').value.trim(),
        color:   document.getElementById('sw-col').value,
        orden:   Number(document.getElementById('sw-ord').value) || 0,
        activo:  document.getElementById('sw-act').checked })
    }).then(async (r) => {
      if (!r.isConfirmed) return;
      try { await API.post('/programacion/tareas', r.value); this.renderTareas(); WMS.toast('success', 'Guardada'); }
      catch (e) { WMS.toast('error', e.message || e); }
    });
  },
  async borrarTarea(id, cod) {
    const r = await Swal.fire({ title:`¿Eliminar "${cod}"?`, icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar' });
    if (!r.isConfirmed) return;
    try { await API.delete(`/programacion/tareas/${id}`); this.renderTareas(); WMS.toast('success','Eliminada'); }
    catch (e) { WMS.toast('error', e.message || e); }
  },

  // ── FILAS CRUD ──────────────────────────────────────────────────────────
  async showFilas() {
    WMS.setContent(`
      <div style="padding:12px;max-width:1000px;">
        <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;">
          <h3 style="margin:0;">Turnos / Rutas / Apoyo</h3>
          <button class="btn btn-primary btn-sm" style="margin-left:auto;" onclick="WMS_MODULES.programacion.editarFila()">
            <i class="fa-solid fa-plus"></i> Nueva fila
          </button>
        </div>
        <div style="font-size:.85rem;color:#64748b;margin-bottom:10px;">
          <b>Slots</b> = cuántas personas caben por día. La fila <b>DESCANSO</b> es gestionada automáticamente desde el panel lateral del calendario.
        </div>
        <div id="prog-filas-list"></div>
      </div>`);
    this.renderFilas();
  },
  async renderFilas() {
    const r = await API.get('/programacion/filas');
    const rows = r.data || [];
    document.getElementById('prog-filas-list').innerHTML = `<table class="data-table" style="width:100%;background:#fff;border-radius:6px;">
      <thead><tr style="background:#f1f5f9;"><th style="padding:8px;">Código</th><th>Nombre</th><th>Horario</th><th>Slots</th><th>Orden</th><th>Estado</th><th></th></tr></thead>
      <tbody>${rows.map(f => `
        <tr><td style="padding:6px 8px;font-weight:700;">${WMS.esc(f.codigo)}</td>
            <td>${WMS.esc(f.nombre || '')}</td>
            <td>${f.hora_inicio ? `${f.hora_inicio.slice(0,5)} - ${f.hora_fin.slice(0,5)}` : '—'}</td>
            <td>${f.slots}</td>
            <td>${f.orden}</td>
            <td>${f.activo ? 'Activa' : 'Inactiva'}</td>
            <td style="text-align:right;">
              <button class="btn btn-sm btn-secondary" onclick='WMS_MODULES.programacion.editarFila(${JSON.stringify(f)})'><i class="fa-solid fa-pen"></i></button>
              <button class="btn btn-sm btn-danger" onclick="WMS_MODULES.programacion.borrarFila(${f.id},'${WMS.esc(f.codigo)}')"><i class="fa-solid fa-trash"></i></button>
            </td></tr>
      `).join('')}</tbody></table>`;
  },
  editarFila(f) {
    f = f || { codigo:'', nombre:'', hora_inicio:'', hora_fin:'', slots:1, orden:0, activo:true };
    Swal.fire({
      title: f.id ? 'Editar fila' : 'Nueva fila',
      html: `<div style="text-align:left;display:grid;gap:8px;">
        <label>Código <input id="sw-cod" class="swal2-input" style="margin:0;text-transform:uppercase;" maxlength="30" value="${WMS.esc(f.codigo)}"></label>
        <label>Nombre <input id="sw-nom" class="swal2-input" style="margin:0;" value="${WMS.esc(f.nombre || '')}"></label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          <label>Hora inicio <input id="sw-hi" type="time" class="swal2-input" style="margin:0;" value="${(f.hora_inicio || '').slice(0,5)}"></label>
          <label>Hora fin <input id="sw-hf" type="time" class="swal2-input" style="margin:0;" value="${(f.hora_fin || '').slice(0,5)}"></label>
        </div>
        <label>Slots <input id="sw-sl" type="number" min="1" max="20" class="swal2-input" style="margin:0;" value="${f.slots}"></label>
        <label>Orden <input id="sw-ord" type="number" class="swal2-input" style="margin:0;" value="${f.orden}"></label>
        <label><input id="sw-act" type="checkbox" ${f.activo ? 'checked' : ''}> Activa</label></div>`,
      showCancelButton: true, confirmButtonText: 'Guardar',
      preConfirm: () => ({ id: f.id,
        codigo:      document.getElementById('sw-cod').value.trim(),
        nombre:      document.getElementById('sw-nom').value.trim(),
        hora_inicio: document.getElementById('sw-hi').value || null,
        hora_fin:    document.getElementById('sw-hf').value || null,
        slots:       Math.max(1, Number(document.getElementById('sw-sl').value) || 1),
        orden:       Number(document.getElementById('sw-ord').value) || 0,
        activo:      document.getElementById('sw-act').checked })
    }).then(async (r) => {
      if (!r.isConfirmed) return;
      try { await API.post('/programacion/filas', r.value); this.renderFilas(); WMS.toast('success', 'Guardada'); }
      catch (e) { WMS.toast('error', e.message || e); }
    });
  },
  async borrarFila(id, cod) {
    const r = await Swal.fire({ title:`¿Eliminar "${cod}"?`, text:'Se borrarán también las asignaciones existentes en esta fila.', icon:'warning', showCancelButton:true, confirmButtonText:'Eliminar' });
    if (!r.isConfirmed) return;
    try { await API.delete(`/programacion/filas/${id}`); this.renderFilas(); WMS.toast('success','Eliminada'); }
    catch (e) { WMS.toast('error', e.message || e); }
  },
};
