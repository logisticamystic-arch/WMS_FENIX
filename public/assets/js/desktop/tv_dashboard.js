/**
 * TV Standalone Dashboard Module - Clon Exacto del Desktop
 */

const TV_REFRESH_MS = 60000; // 60s
let _catChart = null;
let _trendChart = null;

// ── PICKING ──────────────────────────────────────────────────
function agruparPorPlanilla(all) {
  const grupos = {};
  all.forEach(o => {
    const p = o.planilla_numero || o.numero_orden;
    if (!grupos[p]) {
      grupos[p] = { planilla: p, auxiliares: new Set(), lineas_totales: 0, lineas_picking: 0, estados: new Set() };
    }
    const detalles = o.detalles || [];
    grupos[p].lineas_totales += detalles.length || Number(o.total_lineas || 0);
    let picks = 0;
    detalles.forEach(d => {
      if (d.cantidad_pickeada > 0 || d.estado === 'Completado' || d.estado === 'Faltante') picks++;
      grupos[p].estados.add(d.estado);
    });
    grupos[p].lineas_picking += picks;
    if (o.auxiliar?.nombre) grupos[p].auxiliares.add(o.auxiliar.nombre);
  });
  return Object.values(grupos);
}

async function loadPicking() {
  try {
    const today = new Date().toISOString().split('T')[0];
    const query = `fecha_inicio=${today}&fecha_fin=${today}`;
    
    // Exactamente cómo lo hace picking.js
    const [dashRes, allRes] = await Promise.all([
      API.get('/picking/dashboard', query),
      API.get('/picking', query + '&limit=1000')
    ]);
    
    const d = dashRes.data || dashRes || {};
    const all = allRes.data || allRes || [];
    const grupos = agruparPorPlanilla(all);
    
    // KPIs Globales
    const totalL = all.reduce((a,p) => a + parseInt(p.total_lineas||0), 0);
    const pendL  = all.reduce((a,p) => a + parseInt(p.lineas_pendientes||0), 0);
    const okL    = totalL - pendL;
    const pctG   = totalL > 0 ? Math.round((okL / totalL) * 100) : 100;

    // SVG Circular (Progreso)
    const circ = document.getElementById('pick-svg-circ');
    const txt  = document.getElementById('pick-svg-txt');
    if (circ && txt) {
      circ.style.strokeDashoffset = 283 - (pctG/100)*283;
      circ.style.stroke = pctG === 100 ? '#10b981' : '#3b82f6';
      txt.innerText = pctG + '%';
      document.getElementById('pick-prog-txt').innerText = `${sysfmt(okL)} / ${sysfmt(totalL)}`;
    }

    // KPIs Restantes
    document.getElementById('pkpi-pend').innerText = d.pendientes || 0;
    document.getElementById('pkpi-proc').innerText = d.en_proceso || 0;
    document.getElementById('pkpi-done').innerText = d.completadas || 0;
    document.getElementById('pkpi-falt').innerText = (d.alertas_faltantes || []).length;

    // Tabla: Alertas Críticas Faltantes
    const tbFaltantes = document.querySelector('#tbl-faltantes tbody');
    if (d.alertas_faltantes && d.alertas_faltantes.length > 0) {
      tbFaltantes.innerHTML = d.alertas_faltantes.map(f => `
        <tr>
          <td><b style="color:#0f172a">${esc(f.producto)}</b></td>
          <td><span class="badge badge-danger">${f.solic}</span></td>
          <td>${f.hora_ini ? f.hora_ini.substr(11,5) : '-'}</td>
          <td><span class="badge" style="background:#e2e8f0;color:#334155">#${f.planilla}</span></td>
        </tr>
      `).join('');
    } else {
      tbFaltantes.innerHTML = `<tr><td colspan="4" align="center" style="color:#94a3b8;padding:20px;">Sin alertas de stock</td></tr>`;
    }

    // Tabla: Ranking Auxiliares (Matrices solicitadas)
    const tbRkg = document.querySelector('#tbl-ranking tbody');
    if (d.ranking_auxiliares && d.ranking_auxiliares.length > 0) {
      const maxL = d.ranking_auxiliares[0].lineas || 1;
      tbRkg.innerHTML = d.ranking_auxiliares.map((a,i) => {
        const pct = Math.round((a.lineas / maxL) * 100);
        return `<tr>
          <td><b>${i+1}</b></td>
          <td><b>${esc(a.nombre)}</b></td>
          <td>${a.pedidos}</td>
          <td>${a.lineas}</td>
          <td><span class="badge badge-info">${sysfmt(a.unidades)}</span></td>
          <td>
            <div style="background:#f1f5f9;height:6px;border-radius:99px;width:100%;overflow:hidden">
              <div style="background:#10b981;height:100%;width:${pct}%"></div>
            </div>
          </td>
        </tr>`;
      }).join('');
    } else {
      tbRkg.innerHTML = `<tr><td colspan="6" align="center" style="color:#94a3b8;padding:20px;">No hay actividad de auxiliares</td></tr>`;
    }

    // Tabla: Planillas (Sin cliente)
    const tbPlanillas = document.querySelector('#tbl-planillas tbody');
    const sortedGrupos = grupos.sort((a,b)=>b.lineas_totales - a.lineas_totales);
    if (sortedGrupos.length > 0) {
      tbPlanillas.innerHTML = sortedGrupos.map(g => {
        const pct = g.lineas_totales > 0 ? Math.round((g.lineas_picking / g.lineas_totales)*100) : 0;
        let cst = 'st-proc', tst = 'EN PROCESO';
        if (pct >= 100 || g.estados.has('Completado')) { cst = 'st-done'; tst = 'COMPLETADO'; }
        else if (pct === 0 && !g.estados.has('EnProceso')) { cst = 'st-pend'; tst = 'ASIGNADO'; }
        
        return `<tr>
          <td><b style="color:#3b82f6;">#${esc(g.planilla)}</b></td>
          <td>${esc([...g.auxiliares].join(', ') || '-')}</td>
          <td>${g.lineas_picking} / ${g.lineas_totales}</td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
               <div style="flex:1;background:#e2e8f0;height:6px;border-radius:99px;overflow:hidden">
                 <div style="background:#3b82f6;height:100%;width:${pct}%"></div>
               </div>
               <span style="font-size:11px;font-weight:800">${pct}%</span>
            </div>
          </td>
          <td><span class="status ${cst}">${tst}</span></td>
        </tr>`;
      }).join('');
    } else {
      tbPlanillas.innerHTML = `<tr><td colspan="5" align="center" style="color:#94a3b8;padding:20px;">No hay planillas activas hoy</td></tr>`;
    }
  } catch(e) {
    console.error("TV Picking Err", e);
  }
}

// ── RECEPCIÓN ────────────────────────────────────────────────
async function loadRecepcion() {
  try {
    const today = new Date().toISOString().split('T')[0];
    const query = `fecha_inicio=${today}&fecha_fin=${today}`;
    
    const [panelR, dashR] = await Promise.all([
      API.get('/recepcion/control-panel', query),
      API.get('/recepcion/dashboard')
    ]);
    
    const p = panelR.data || panelR || {};
    const d = dashR.data || dashR || {};
    const kpis = p.kpis || {};
    
    document.getElementById('rkpi-odcs').innerText = sysfmt(kpis.total_odcs || 0);
    document.getElementById('rkpi-lin').innerText  = sysfmt(kpis.total_lineas || 0);
    document.getElementById('rkpi-falt').innerText = sysfmt(kpis.total_lineas_faltantes || 0);
    document.getElementById('rkpi-pref').innerText = (kpis.pct_recibo_referencia || 0) + '%';
    document.getElementById('rkpi-pcan').innerText = (kpis.pct_recibo_cantidad || 0) + '%';
    document.getElementById('rkpi-venc').innerText = sysfmt(kpis.proximos_vencer || 0);

    // Chart: Categorías
    if (d.categorias_stats) renderCatChart(d.categorias_stats);
    if (d.tendencia) renderTrendChart(d.tendencia);

    // Lista: Líderes
    const rkg = Array.isArray(p.ranking_auxiliares) ? p.ranking_auxiliares : [];
    const tbLid = document.querySelector('#tbl-lideres tbody');
    if (rkg.length > 0) {
      tbLid.innerHTML = rkg.slice(0, 5).map((a,idx) => `
        <tr>
          <td><b style="color:#f59e0b">#${idx+1}</b></td>
          <td><b style="color:#0f172a">${esc(a.nombre)}</b></td>
          <td><span style="font-weight:900;color:#3b82f6">${sysfmt(a.total_unidades_recibidas)}</span> und</td>
        </tr>
      `).join('');
    } else {
      tbLid.innerHTML = `<tr><td colspan="3" align="center" style="color:#94a3b8;padding:20px;">Sin recepciones registradas</td></tr>`;
    }
  } catch(e) {
    console.error("TV Rec Err", e);
  }
}

function renderCatChart(stats) {
  const ctx = document.getElementById('chart-cat');
  if (!ctx) return;
  if (_catChart) _catChart.destroy();
  const topStats = stats.slice(0, 6); // Max 6 for space
  _catChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: topStats.map(s => s.categoria),
      datasets: [{ label: 'Unidades', data: topStats.map(s => s.total), backgroundColor: '#7c3aed', borderRadius: 4 }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { grid: { display: false } }, y: { grid: { display: false }, ticks: { font:{size:10} } } }
    }
  });
}

function renderTrendChart(tendencia) {
  const ctx = document.getElementById('chart-trend');
  if (!ctx) return;
  if (_trendChart) _trendChart.destroy();
  _trendChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: tendencia.map(item => item.fecha.substr(5)), // solo mes-dia
      datasets: [{
        label: 'Recepciones', data: tendencia.map(i => Number(i.total)||0),
        borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)',
        fill: true, tension: 0.35, pointRadius: 3
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, font:{size:10} } },
        x: { grid: { display: false }, ticks: { font:{size:10} } }
      }
    }
  });
}

// ── VENCIMIENTOS TICKER (SCROLL INFERIOR) ─────────────────────
async function loadVencimientosTicker() {
  try {
    // Pedir próximos a vencer a un margen amplio (180 días) para no dejar vacío el scroller
    const r = await API.get('/reportes/vencimientos', 'dias=180');
    const d = r.data || r || {};
    const stock = Array.isArray(d.detalle) ? d.detalle : (Array.isArray(d) ? d : []);
    const ticker = document.getElementById('tv-ticker-wrap');
    if (!ticker) return;

    if (stock.length === 0) {
      ticker.innerHTML = '<div class="ticker-item"><i class="fa-solid fa-shield-check" style="color:#10b981;"></i> Inventario libre de riesgos de vencimiento cercano.</div>';
      return;
    }

    let html = '';
    // Mostrar máximo los 30 productos más urgentes
    stock.slice(0, 30).forEach(s => {
      let dClass = s.dias_vencer <= 15 ? 't-err' : 't-warn';
      let diasTxt = s.dias_vencer < 0 ? `Vencido hace ${Math.abs(s.dias_vencer)}` : `Faltan ${s.dias_vencer}`;
      html += `<div class="ticker-item">
        <i class="fa-solid fa-box" style="color:#64748b;"></i> <span class="t-hl">${esc(s.producto || s.producto_nombre)}</span>
        <span style="color:#475569;">|</span> Ubicación: <span class="t-hl">${esc(s.ubicacion || s.ubicacion_codigo)}</span>
        <span style="color:#475569;">|</span> Cantidad: <span class="t-hl">${sysfmt(s.cantidad)}</span>
        <span style="color:#475569;">|</span> <span class="${dClass}">${diasTxt} días</span>
      </div>`;
    });

    // Ajustar duración dinámica según la cantidad de elementos a mostrar
    // para asegurar que siempre sea fácil de leer (promedio 4 segundos por producto).
    const dynamicSeconds = Math.max(80, stock.length * 4);
    ticker.style.animationDuration = `${dynamicSeconds}s`;
    ticker.innerHTML = html;
  } catch(e) {
    console.error("TV Ticker Err", e);
  }
}

// ── INIT LOOP ────────────────────────────────────────────────
async function sync() {
  await Promise.all([ loadPicking(), loadRecepcion(), loadVencimientosTicker() ]);
}

// Token Verification
if (!localStorage.getItem('wms_token')) {
  document.body.innerHTML = `
    <div style="display:flex;height:100vh;align-items:center;justify-content:center;background:#f8fafc;color:#0f172a;font-family:sans-serif;">
      <div style="text-align:center; background:#fff; padding:40px; border-radius:20px; box-shadow:0 10px 40px rgba(0,0,0,0.05); border:1px solid rgba(0,0,0,0.05);">
        <i class="fa-solid fa-lock" style="font-size:4rem;color:#ef4444;margin-bottom:20px;"></i>
        <h2 style="margin-bottom:10px; font-weight:900;">Acceso TV Restringido</h2>
        <p style="color:#64748b; margin-bottom:25px;">Por favor inicie sesión como supervisor/administrador en esta computadora.</p>
        <button onclick="window.location.href='index.html'" style="padding:12px 30px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;">Ingresar al Sistema</button>
      </div>
    </div>
  `;
} else {
  sync();
  setInterval(() => {
    API.get('/user/me')
      .then(r => { if (r.error && r.status === 401) window.location.reload(); else sync(); })
      .catch(e => sync());
  }, TV_REFRESH_MS);
}
