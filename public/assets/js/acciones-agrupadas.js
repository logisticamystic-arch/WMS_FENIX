/* ============================================================
   Acciones agrupadas por fila (estilo bee) — 2026-10-04
   En cualquier tabla del WMS cuya columna se llame "Acción/Acciones",
   si una fila tiene 2+ botones visibles, se agrupan tras un único botón
   ⋮ que despliega el menú con todas las acciones de esa línea.
   - Los botones ORIGINALES se mueven al menú (no se clonan): conservan
     onclick, listeners, ids y data-*. El menú vive DENTRO de la misma
     celda, así que this.closest('tr') sigue funcionando.
   - No toca módulos: observa #main-content y procesa lo que se pinte.
   - Opt-out: agregar data-no-agrupar a la <table> o a la <td>.
   ============================================================ */
(function () {
  const HEADER_RE = /^acci[oó]n(es)?$/i;
  const ICON_LABEL = [
    [/fa-(pen|pencil|edit|pen-to-square)\b/, 'Editar'], [/fa-trash/, 'Eliminar'],
    [/fa-(eye)\b/, 'Ver detalle'], [/fa-eye-slash/, 'Ocultar'], [/fa-barcode/, 'Código de barras'],
    [/fa-(image|images|camera)/, 'Imagen / fotos'], [/fa-print/, 'Imprimir'], [/fa-file-pdf/, 'PDF'],
    [/fa-(file-excel|file-csv|download)/, 'Descargar'], [/fa-(copy|clone)/, 'Duplicar'],
    [/fa-(check|circle-check|check-double)/, 'Aprobar / confirmar'], [/fa-(xmark|times|circle-xmark)/, 'Cancelar / rechazar'],
    [/fa-ban/, 'Desactivar'], [/fa-toggle-(on|off)|fa-power-off/, 'Activar / desactivar'],
    [/fa-(key)\b/, 'Cambiar PIN / clave'], [/fa-lock-open|fa-unlock/, 'Desbloquear'], [/fa-lock\b/, 'Bloquear'],
    [/fa-(rotate|arrows-rotate|rotate-left|rotate-right)/, 'Reabrir / actualizar'], [/fa-(stamp)/, 'Certificar'],
    [/fa-(user-plus|user-check|user-tag)/, 'Asignar'], [/fa-(location-dot|map-pin|map-location)/, 'Ubicación'],
    [/fa-(calendar)/, 'Fecha'], [/fa-(truck)/, 'Despacho'], [/fa-(list|list-check|table-list)/, 'Ver líneas'],
    [/fa-(paper-plane|share)/, 'Enviar'], [/fa-(plus)\b/, 'Agregar'], [/fa-(clock-rotate-left|history)/, 'Historial'],
    [/fa-(magnifying-glass|search)/, 'Consultar'], [/fa-(box|boxes)/, 'Producto'], [/fa-(signature)/, 'Firma'],
  ];
  let openMenu = null;

  const visible = el => el.style.display !== 'none' && !el.hidden && el.getAttribute('aria-hidden') !== 'true';
  const isDanger = el => /btn-(danger|outline-danger|soft-danger)|text-danger/.test(el.className) ||
    /(dc2626|ef4444|var\(--danger\)|220,\s*38,\s*38)/i.test(el.getAttribute('style') || '');

  function labelFor(btn) {
    const txt = (btn.textContent || '').replace(/\s+/g, ' ').trim();
    if (txt.length > 1) return null;                              // ya tiene texto propio
    const t = btn.getAttribute('title') || btn.getAttribute('aria-label') || btn.dataset.title || '';
    if (t) return t;
    const icon = btn.querySelector('i, svg');
    const cls = icon ? (icon.getAttribute('class') || '') : '';
    for (const [re, l] of ICON_LABEL) if (re.test(cls)) return l;
    return 'Acción';
  }

  function actionColumns(table) {
    const head = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
    if (!head) return [];
    const cols = [];
    const cells = Array.from(head.cells);
    cells.forEach((th, i) => {
      const txt = (th.textContent || '').trim();
      // "Acción/Acciones", o la ÚLTIMA columna sin título (patrón habitual de acciones)
      if (HEADER_RE.test(txt) || (!txt && i === cells.length - 1 && !th.querySelector('input'))) cols.push(th.cellIndex);
    });
    return cols;
  }

  function group(td) {
    if (td.dataset.raDone || td.hasAttribute('data-no-agrupar')) return;
    if (td.querySelector('input:not([type=hidden]), select, textarea')) return;   // celdas con captura: no
    const btns = Array.from(td.querySelectorAll('button, a.btn, a[onclick]'))
      .filter(b => !b.closest('.ra-wrap'));
    if (btns.filter(visible).length < 2) return;
    td.dataset.raDone = '1';

    const wrap = document.createElement('div');
    wrap.className = 'ra-wrap';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ra-trigger';
    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.title = 'Acciones';
    trigger.innerHTML = '<i class="fa-solid fa-ellipsis-vertical"></i><span class="ra-count"></span>';
    const menu = document.createElement('div');
    menu.className = 'ra-menu';
    menu.setAttribute('role', 'menu');

    btns.forEach(b => {
      const l = labelFor(b);
      if (l) { const s = document.createElement('span'); s.className = 'ra-label'; s.textContent = l; b.appendChild(s); }
      b.classList.add('ra-item');
      if (isDanger(b)) b.classList.add('ra-danger');
      b.setAttribute('role', 'menuitem');
      menu.appendChild(b);                                      // mover el original (conserva handlers)
    });
    // limpiar contenedores que quedaron vacíos (divs flex de botones)
    Array.from(td.querySelectorAll('div, span')).forEach(el => { if (!el.closest('.ra-wrap') && !el.textContent.trim() && !el.querySelector('img,i,svg')) el.remove(); });
    wrap.append(trigger, menu);
    td.appendChild(wrap);
    td.classList.add('ra-cell');
    refreshCount(wrap);
  }

  function refreshCount(wrap) {
    const n = wrap.querySelectorAll('.ra-menu > .ra-item').length;
    const vis = Array.from(wrap.querySelectorAll('.ra-menu > .ra-item')).filter(visible).length;
    const c = wrap.querySelector('.ra-count');
    if (c) c.textContent = vis || n;
  }

  function scan(root) {
    (root || document).querySelectorAll('#main-content table, #right-panel table, .modal table').forEach(table => {
      if (table.hasAttribute('data-no-agrupar')) return;
      const cols = actionColumns(table);
      if (!cols.length) return;
      Array.from(table.tBodies).forEach(tb => Array.from(tb.rows).forEach(tr => {
        cols.forEach(ci => { const td = tr.cells[ci]; if (td && td.tagName === 'TD') group(td); });
      }));
    });
  }

  function close() {
    if (!openMenu) return;
    openMenu.classList.remove('open');
    const t = openMenu.parentElement.querySelector('.ra-trigger');
    if (t) t.setAttribute('aria-expanded', 'false');
    openMenu.closest('tr')?.classList.remove('ra-row-open');
    openMenu = null;
  }

  function open(trigger) {
    const menu = trigger.nextElementSibling;
    if (openMenu === menu) { close(); return; }
    close();
    refreshCount(trigger.parentElement);
    menu.classList.add('open');
    trigger.setAttribute('aria-expanded', 'true');
    trigger.closest('tr')?.classList.add('ra-row-open');
    // position:fixed para escapar de contenedores con overflow (tablas con scroll)
    const r = trigger.getBoundingClientRect();
    const mw = menu.offsetWidth, mh = menu.offsetHeight;
    let left = r.right - mw; if (left < 8) left = Math.min(r.left, innerWidth - mw - 8);
    let top = r.bottom + 4; if (top + mh > innerHeight - 8) top = Math.max(8, r.top - mh - 4);
    menu.style.left = left + 'px'; menu.style.top = top + 'px';
    // Si un ancestro tiene transform/filter (animaciones de entrada), position:fixed
    // se mide desde ese ancestro: corregir con la diferencia real medida.
    const real = menu.getBoundingClientRect();
    if (Math.abs(real.left - left) > 1 || Math.abs(real.top - top) > 1) {
      menu.style.left = (2 * left - real.left) + 'px';
      menu.style.top = (2 * top - real.top) + 'px';
    }
    openMenu = menu;
    const first = Array.from(menu.querySelectorAll('.ra-item')).find(visible);
    first && first.focus({ preventScroll: true });
  }

  document.addEventListener('click', e => {
    const trig = e.target.closest('.ra-trigger');
    if (trig) { e.preventDefault(); e.stopPropagation(); open(trig); return; }
    if (openMenu && e.target.closest('.ra-item')) { setTimeout(close, 0); return; }   // deja correr el onclick original
    if (openMenu && !e.target.closest('.ra-menu')) close();
  }, true);
  document.addEventListener('keydown', e => {
    if (!openMenu) return;
    if (e.key === 'Escape') { const t = openMenu.parentElement.querySelector('.ra-trigger'); close(); t && t.focus(); }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const items = Array.from(openMenu.querySelectorAll('.ra-item')).filter(visible);
      const i = items.indexOf(document.activeElement);
      const n = items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
      n && n.focus(); e.preventDefault();
    }
  });
  window.addEventListener('resize', close);
  document.addEventListener('scroll', close, true);

  let pending = false;
  const schedule = () => { if (pending) return; pending = true; requestAnimationFrame(() => { pending = false; scan(); }); };
  const start = () => {
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    scan();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.WMS_ACCIONES_AGRUPADAS = { scan, close };
})();
