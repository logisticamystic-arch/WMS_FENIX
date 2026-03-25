/**
 * Prooriente WMS — Mapa 3D Interactivo de Bodega
 * Visualización CSS 3D de ubicaciones con nivel de ocupación en tiempo real.
 * Sin dependencias externas — solo CSS transforms y SVG.
 */
window.MapaBodega = {

    _ubicaciones:  [],
    _stockMap:     {},   // ubicacion_id → cantidad
    _selected:     null,
    _rotX:         20,
    _rotY:         -30,
    _dragging:     false,
    _lastMouse:    { x: 0, y: 0 },
    _zoom:         1,

    getHTML: function () {
        return `
        <div style="padding:12px;">
            <!-- Toolbar -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                <div>
                    <span style="font-weight:700; color:#0f172a; font-size:1rem;"><i class="fa-solid fa-cubes" style="margin-right:6px; color:#3b82f6;"></i>Mapa 3D de Bodega</span>
                    <p style="color:#64748b; font-size:0.78rem; margin:2px 0 0;">Arrastre para rotar · Rueda para zoom</p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button onclick="window.MapaBodega._resetView()"
                        style="padding:6px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:0.78rem; color:#475569; cursor:pointer;">
                        <i class="fa-solid fa-rotate"></i> Resetear vista
                    </button>
                    <button onclick="window.MapaBodega.init()"
                        style="padding:6px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:0.78rem; color:#475569; cursor:pointer;">
                        <i class="fa-solid fa-rotate-right"></i> Actualizar
                    </button>
                </div>
            </div>

            <!-- Leyenda -->
            <div style="display:flex; gap:12px; margin-bottom:12px; flex-wrap:wrap; font-size:0.75rem; color:#475569;">
                <span><span style="display:inline-block; width:12px; height:12px; background:#22c55e; border-radius:2px; margin-right:4px;"></span>Libre (0–39%)</span>
                <span><span style="display:inline-block; width:12px; height:12px; background:#f59e0b; border-radius:2px; margin-right:4px;"></span>Medio (40–79%)</span>
                <span><span style="display:inline-block; width:12px; height:12px; background:#ef4444; border-radius:2px; margin-right:4px;"></span>Lleno (80–100%)</span>
                <span><span style="display:inline-block; width:12px; height:12px; background:#6366f1; border-radius:2px; margin-right:4px;"></span>Patio</span>
                <span><span style="display:inline-block; width:12px; height:12px; background:#cbd5e1; border-radius:2px; margin-right:4px;"></span>Sin stock</span>
            </div>

            <!-- Filtro por zona -->
            <div style="display:flex; gap:8px; margin-bottom:12px; align-items:center;">
                <label style="font-size:0.78rem; color:#475569; white-space:nowrap;">Filtrar zona:</label>
                <select id="mapa-filtro-zona" onchange="window.MapaBodega._renderMapa()"
                    style="padding:6px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.82rem; background:white; max-width:160px;">
                    <option value="">Todas las zonas</option>
                </select>
                <span id="mapa-stats" style="font-size:0.75rem; color:#64748b; margin-left:auto;"></span>
            </div>

            <!-- Canvas 3D -->
            <div id="mapa-viewport"
                style="width:100%; height:460px; background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%); border-radius:14px; overflow:hidden; position:relative; cursor:grab; user-select:none;"
                onmousedown="window.MapaBodega._onMouseDown(event)"
                onmousemove="window.MapaBodega._onMouseMove(event)"
                onmouseup="window.MapaBodega._onMouseUp()"
                onmouseleave="window.MapaBodega._onMouseUp()"
                ontouchstart="window.MapaBodega._onTouchStart(event)"
                ontouchmove="window.MapaBodega._onTouchMove(event)"
                ontouchend="window.MapaBodega._onMouseUp()"
                onwheel="window.MapaBodega._onWheel(event)">

                <div id="mapa-scene-wrap" style="width:100%; height:100%; display:flex; align-items:center; justify-content:center;">
                    <div id="mapa-scene" style="transform-style:preserve-3d; transition:transform .12s ease;">
                        <div id="mapa-cargando" style="color:#94a3b8; font-size:0.9rem; text-align:center; padding:40px;">
                            <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem; margin-bottom:12px; display:block;"></i>
                            Cargando mapa de bodega...
                        </div>
                    </div>
                </div>

                <!-- Zoom buttons -->
                <div style="position:absolute; bottom:12px; right:12px; display:flex; flex-direction:column; gap:4px;">
                    <button onclick="window.MapaBodega._zoomIn()"
                        style="width:32px; height:32px; background:rgba(255,255,255,0.15); color:white; border:none; border-radius:6px; font-size:1rem; cursor:pointer; line-height:1;">+</button>
                    <button onclick="window.MapaBodega._zoomOut()"
                        style="width:32px; height:32px; background:rgba(255,255,255,0.15); color:white; border:none; border-radius:6px; font-size:1rem; cursor:pointer; line-height:1;">−</button>
                </div>
            </div>

            <!-- Tooltip de ubicación seleccionada -->
            <div id="mapa-tooltip" style="display:none; margin-top:14px; background:white; border:1px solid #e2e8f0; border-radius:12px; padding:16px; box-shadow:0 2px 12px rgba(0,0,0,0.08);">
                <div style="font-weight:700; color:#0f172a; font-size:1rem; margin-bottom:4px;" id="mapa-tt-codigo">—</div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:10px; font-size:0.82rem;">
                    <div><span style="color:#64748b; display:block;">Tipo</span><strong id="mapa-tt-tipo">—</strong></div>
                    <div><span style="color:#64748b; display:block;">Stock actual</span><strong id="mapa-tt-stock" style="color:#ef4444;">—</strong></div>
                    <div><span style="color:#64748b; display:block;">Capacidad</span><strong id="mapa-tt-cap">—</strong></div>
                </div>
                <div id="mapa-tt-bar" style="margin-top:10px;">
                    <div style="height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                        <div id="mapa-tt-progress" style="height:100%; border-radius:4px; transition:width .3s;"></div>
                    </div>
                    <div style="font-size:0.72rem; color:#64748b; margin-top:4px; text-align:right;" id="mapa-tt-pct">0%</div>
                </div>
            </div>
        </div>`;
    },

    init: async function () {
        try {
            const [ubicRes, stockRes] = await Promise.all([
                window.api.get('/param/ubicaciones'),
                window.api.get('/inventario/stock?limit=2000'),
            ]);

            this._ubicaciones = ubicRes.data || [];
            const stockItems  = stockRes.data || [];

            // Build stock map: ubicacion_id → total cantidad
            this._stockMap = {};
            stockItems.forEach(s => {
                if (s.ubicacion_id) {
                    this._stockMap[s.ubicacion_id] = (this._stockMap[s.ubicacion_id] || 0) + (s.cantidad || 0);
                }
            });

            // Populate zone filter
            const zonas  = [...new Set(this._ubicaciones.map(u => u.zona).filter(Boolean))].sort();
            const selZona = document.getElementById('mapa-filtro-zona');
            if (selZona) {
                selZona.innerHTML = '<option value="">Todas las zonas</option>' +
                    zonas.map(z => `<option value="${escHTML(z)}">${escHTML(z)}</option>`).join('');
            }

            this._updateStats();
            this._renderMapa();
        } catch (err) {
            const scene = document.getElementById('mapa-scene');
            if (scene) scene.innerHTML = `<div style="color:#ef4444; font-size:0.85rem; padding:30px; text-align:center;">Error al cargar el mapa.<br>${escHTML(err.message || '')}</div>`;
        }
    },

    _updateStats: function () {
        const el = document.getElementById('mapa-stats');
        if (!el) return;
        const total     = this._ubicaciones.length;
        const conStock  = this._ubicaciones.filter(u => (this._stockMap[u.id] || 0) > 0).length;
        el.textContent  = `${conStock} / ${total} ubicaciones con stock`;
    },

    _renderMapa: function () {
        const scene   = document.getElementById('mapa-scene');
        if (!scene) return;

        const zonaFiltro = (document.getElementById('mapa-filtro-zona')?.value || '').trim();
        let ubicaciones  = this._ubicaciones;
        if (zonaFiltro) ubicaciones = ubicaciones.filter(u => u.zona === zonaFiltro);

        if (!ubicaciones.length) {
            scene.innerHTML = `<div style="color:#94a3b8; font-size:0.85rem; padding:40px; text-align:center;">Sin ubicaciones para mostrar.</div>`;
            return;
        }

        // Group by: zona → pasillo → modulo → nivel (stacked)
        const grupos = {};
        ubicaciones.forEach(u => {
            const zona    = u.zona    || 'Z';
            const pasillo = u.pasillo || 'A';
            const modulo  = u.modulo  || '00';
            const key = `${zona}__${pasillo}__${modulo}`;
            if (!grupos[key]) grupos[key] = { zona, pasillo, modulo, niveles: [] };
            grupos[key].niveles.push(u);
        });

        // Sort niveles de abajo a arriba
        Object.values(grupos).forEach(g => {
            g.niveles.sort((a, b) => String(a.nivel).localeCompare(String(b.nivel)));
        });

        const grupoList = Object.values(grupos).sort((a, b) => {
            if (a.zona !== b.zona)    return a.zona.localeCompare(b.zona);
            if (a.pasillo !== b.pasillo) return a.pasillo.localeCompare(b.pasillo);
            return String(a.modulo).localeCompare(String(b.modulo));
        });

        // Layout: arrange columns (módulos) left→right, with pasillo separators
        const RACK_W  = 52;   // px per rack column
        const RACK_H  = 34;   // px per shelf level
        const RACK_D  = 20;   // px depth (3D)
        const GAP     = 10;
        const AISLE_W = 36;   // extra gap between pasillos

        let html      = '';
        let colIndex  = 0;
        let lastPasillo = null;

        // Group by pasillo for aisle spacing
        const porPasillo = {};
        grupoList.forEach(g => {
            if (!porPasillo[g.pasillo]) porPasillo[g.pasillo] = [];
            porPasillo[g.pasillo].push(g);
        });

        const pasillos = Object.keys(porPasillo).sort();
        let totalWidth  = 0;
        const colPositions = [];

        pasillos.forEach((pas, pi) => {
            if (pi > 0) totalWidth += AISLE_W;
            porPasillo[pas].forEach((g, gi) => {
                colPositions.push({ g, x: totalWidth });
                totalWidth += RACK_W + GAP;
            });
        });

        const maxNiveles = Math.max(...grupoList.map(g => g.niveles.length), 1);
        const totalHeight = maxNiveles * (RACK_H + 4) + 20;

        // Render floor
        html += `<div style="position:absolute; left:0; top:${totalHeight + 10}px; width:${totalWidth}px; height:${RACK_D}px; background:rgba(255,255,255,0.04); transform:rotateX(-90deg) translateZ(${RACK_D/2}px); border:1px solid rgba(255,255,255,0.06);"></div>`;

        // Pasillo labels
        pasillos.forEach((pas, pi) => {
            const firstCol = colPositions.find(cp => cp.g.pasillo === pas);
            const lastCol  = [...colPositions].reverse().find(cp => cp.g.pasillo === pas);
            if (!firstCol) return;
            const cx = firstCol.x + (lastCol.x - firstCol.x + RACK_W) / 2;
            html += `<div style="position:absolute; left:${cx - 20}px; top:${totalHeight + RACK_D + 6}px; width:40px; text-align:center; font-size:10px; color:rgba(255,255,255,0.5); font-weight:700;">Pas. ${escHTML(pas)}</div>`;
        });

        // Render rack columns
        colPositions.forEach(({ g, x }) => {
            const maxN = g.niveles.length;
            g.niveles.forEach((u, ni) => {
                const stock    = this._stockMap[u.id] || 0;
                const capMax   = u.capacidad_maxima || 0;
                const pct      = capMax > 0 ? Math.min(100, Math.round((stock / capMax) * 100)) : (stock > 0 ? 100 : 0);
                const color    = u.tipo_ubicacion === 'Patio' ? '#6366f1'
                    : pct === 0     ? '#334155'
                    : pct < 40      ? '#22c55e'
                    : pct < 80      ? '#f59e0b'
                    : '#ef4444';
                const y        = totalHeight - (ni + 1) * (RACK_H + 4);

                // Front face (main visible rack)
                html += `
                <div onclick="window.MapaBodega._selectUbicacion(${u.id})"
                    title="${escHTML(u.codigo)} — ${stock} uds."
                    style="position:absolute; left:${x}px; top:${y}px; width:${RACK_W}px; height:${RACK_H}px;
                           background:${color}; border-radius:3px 3px 0 0; cursor:pointer;
                           border:1px solid rgba(255,255,255,0.15); box-sizing:border-box;
                           transition:filter .15s; display:flex; align-items:center; justify-content:center;"
                    onmouseover="this.style.filter='brightness(1.3)'"
                    onmouseout="this.style.filter=''"
                    data-ubic-id="${u.id}">
                    <span style="font-size:9px; color:rgba(255,255,255,0.9); font-weight:700; text-align:center; line-height:1.2; padding:2px;">
                        ${escHTML(u.codigo)}<br>
                        ${pct > 0 ? pct + '%' : '—'}
                    </span>
                </div>
                <!-- Top face (3D depth) -->
                <div style="position:absolute; left:${x}px; top:${y}px; width:${RACK_W}px; height:${RACK_D}px;
                     background:${color}; opacity:0.55; border-radius:0 3px 0 0;
                     transform-origin:top center; transform:rotateX(-90deg) translateY(-${RACK_D/2}px);
                     pointer-events:none; border:1px solid rgba(255,255,255,0.08);"></div>
                <!-- Right face (3D side) -->
                <div style="position:absolute; left:${x + RACK_W - 1}px; top:${y}px; width:${RACK_D}px; height:${RACK_H}px;
                     background:${color}; opacity:0.35; border-radius:0 3px 3px 0;
                     transform-origin:left center; transform:rotateY(90deg) translateX(${RACK_D/2}px);
                     pointer-events:none; border:1px solid rgba(255,255,255,0.08);"></div>`;
            });

            // Rack column frame
            html += `<div style="position:absolute; left:${x - 2}px; top:${totalHeight - maxN*(RACK_H+4)}px;
                width:${RACK_W + 4}px; height:${maxN*(RACK_H+4)}px;
                border-left:2px solid rgba(255,255,255,0.12); border-right:2px solid rgba(255,255,255,0.08);
                pointer-events:none;"></div>`;
        });

        // Wrap in positioned container
        scene.innerHTML = `<div style="position:relative; width:${totalWidth}px; height:${totalHeight + RACK_D + 30}px; transform-style:preserve-3d;">${html}</div>`;

        this._applyTransform();
    },

    _applyTransform: function () {
        const scene = document.getElementById('mapa-scene');
        if (!scene) return;
        scene.style.transform = `scale(${this._zoom}) rotateX(${this._rotX}deg) rotateY(${this._rotY}deg)`;
    },

    _selectUbicacion: function (id) {
        const u  = this._ubicaciones.find(x => x.id === id);
        if (!u) return;

        // Highlight
        document.querySelectorAll('[data-ubic-id]').forEach(el => el.style.outline = 'none');
        const el = document.querySelector(`[data-ubic-id="${id}"]`);
        if (el) el.style.outline = '2px solid white';

        const stock  = this._stockMap[id] || 0;
        const capMax = u.capacidad_maxima || 0;
        const pct    = capMax > 0 ? Math.min(100, Math.round((stock / capMax) * 100)) : (stock > 0 ? 100 : 0);
        const color  = u.tipo_ubicacion === 'Patio' ? '#6366f1'
            : pct < 40 ? '#22c55e' : pct < 80 ? '#f59e0b' : '#ef4444';

        document.getElementById('mapa-tt-codigo').textContent  = u.codigo || '—';
        document.getElementById('mapa-tt-tipo').textContent    = u.tipo_ubicacion || '—';
        document.getElementById('mapa-tt-stock').textContent   = stock + ' uds.';
        document.getElementById('mapa-tt-stock').style.color   = stock > 0 ? color : '#64748b';
        document.getElementById('mapa-tt-cap').textContent     = capMax > 0 ? capMax + ' uds.' : 'Sin límite';
        document.getElementById('mapa-tt-progress').style.width      = pct + '%';
        document.getElementById('mapa-tt-progress').style.background = color;
        document.getElementById('mapa-tt-pct').textContent    = capMax > 0 ? pct + '%' : '—';

        document.getElementById('mapa-tooltip').style.display = 'block';
        this._selected = id;
    },

    _resetView: function () {
        this._rotX  = 20;
        this._rotY  = -30;
        this._zoom  = 1;
        this._applyTransform();
    },

    _zoomIn:  function () { this._zoom = Math.min(2.5, this._zoom + 0.15); this._applyTransform(); },
    _zoomOut: function () { this._zoom = Math.max(0.3, this._zoom - 0.15); this._applyTransform(); },

    _onWheel: function (e) {
        e.preventDefault();
        if (e.deltaY < 0) this._zoomIn(); else this._zoomOut();
    },

    _onMouseDown: function (e) {
        this._dragging  = true;
        this._lastMouse = { x: e.clientX, y: e.clientY };
        const vp = document.getElementById('mapa-viewport');
        if (vp) vp.style.cursor = 'grabbing';
    },

    _onMouseMove: function (e) {
        if (!this._dragging) return;
        const dx     = e.clientX - this._lastMouse.x;
        const dy     = e.clientY - this._lastMouse.y;
        this._rotY  += dx * 0.4;
        this._rotX  -= dy * 0.4;
        this._rotX   = Math.max(-80, Math.min(80, this._rotX));
        this._lastMouse = { x: e.clientX, y: e.clientY };
        this._applyTransform();
    },

    _onMouseUp: function () {
        this._dragging = false;
        const vp = document.getElementById('mapa-viewport');
        if (vp) vp.style.cursor = 'grab';
    },

    // Touch support
    _touchStart: null,
    _onTouchStart: function (e) {
        if (e.touches.length === 1) {
            this._dragging   = true;
            this._lastMouse  = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
    },
    _onTouchMove: function (e) {
        if (!this._dragging || e.touches.length !== 1) return;
        e.preventDefault();
        const dx    = e.touches[0].clientX - this._lastMouse.x;
        const dy    = e.touches[0].clientY - this._lastMouse.y;
        this._rotY += dx * 0.4;
        this._rotX -= dy * 0.4;
        this._rotX  = Math.max(-80, Math.min(80, this._rotX));
        this._lastMouse = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        this._applyTransform();
    },
};
