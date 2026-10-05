/* ============================================================
   Encabezados fijos en todas las consultas (2026-10-04)
   Cada tabla de #main-content se desplaza DENTRO de su contenedor, con una
   altura que llega justo al borde inferior de la pantalla: así los filtros
   (arriba de la tabla) nunca salen de la vista y el <thead> queda fijo
   (position: sticky) mientras se recorren las filas.
   - No toca módulos: observa lo que se pinta en #main-content.
   - Solo usa el contenedor directo de la tabla cuando la tabla es su único
     hijo (no envuelve ni mueve nodos).
   - Opt-out: data-sin-encabezado-fijo en la <table> o en su contenedor.
   ============================================================ */
(function () {
  const MIN_ALTO = 260;     // nunca más bajo que esto (pantallas pequeñas)
  const MARGEN_INF = 20;    // aire bajo la tabla

  // Tablas de CAPTURA (líneas con campos para escribir/elegir, p.ej. Nuevo Pedido
  // Manual) NO se tocan: sus listas desplegables de búsqueda se salen del
  // contenedor y un scroll propio las recortaba (2026-10-05).
  const CAMPO_CAPTURA = 'tbody input:not([type=checkbox]):not([type=radio]):not([type=hidden]), tbody select, tbody textarea';

  function contenedorDe(table) {
    const p = table.parentElement;
    if (!p || p.children.length !== 1 || p.tagName === 'TD') return null;
    if (table.hasAttribute('data-sin-encabezado-fijo') || p.hasAttribute('data-sin-encabezado-fijo')) return null;
    if (table.closest('.modal, .swal2-popup, .ra-menu') || table.parentElement.closest('table')) return null;
    if (/overflow\s*:\s*visible/i.test(p.getAttribute('style') || '')) return null;   // el módulo pidió no recortar
    // solo campos de ESTA tabla (no los de tablas internas, p.ej. detalle desplegado)
    if ([...table.querySelectorAll(CAMPO_CAPTURA)].some(el => el.closest('table') === table)) return null;
    return p;
  }

  function soltar(box) {
    box.classList.remove('tbl-fija');
    box.style.maxHeight = '';
    box.querySelectorAll(':scope > table > thead > tr > th').forEach(th => { th.style.top = ''; });
  }

  function ajustar() {
    const sc = document.getElementById('content-body');
    // Si una tabla ya fijada pasó a ser de captura (se agregaron líneas con campos), soltarla
    document.querySelectorAll('#main-content .tbl-fija').forEach(box => {
      const t = box.querySelector(':scope > table');
      if (!t || contenedorDe(t) !== box) soltar(box);
    });
    document.querySelectorAll('#main-content table').forEach(table => {
      if (!table.tHead || !table.tHead.rows.length) return;
      const box = contenedorDe(table);
      if (!box || box.offsetParent === null) return;          // oculto (pestaña no visible)
      box.classList.add('tbl-fija');
      // Alto disponible desde el borde superior del contenedor hasta el fondo de la pantalla
      const top = box.getBoundingClientRect().top;
      const scTop = sc ? sc.getBoundingClientRect().top : 0;
      const desde = Math.max(top, scTop);                       // si la página ya bajó, medir desde el área visible
      const alto = Math.max(MIN_ALTO, Math.floor(window.innerHeight - desde - MARGEN_INF));
      box.style.maxHeight = alto + 'px';
      // Encabezados de varias filas: cada fila se fija debajo de la anterior
      let acum = 0;
      Array.from(table.tHead.rows).forEach(tr => {
        Array.from(tr.cells).forEach(th => { th.style.top = acum + 'px'; });
        acum += tr.getBoundingClientRect().height;
      });
    });
  }

  let pendiente = false;
  const programar = () => {
    if (pendiente) return;
    pendiente = true;
    requestAnimationFrame(() => { pendiente = false; ajustar(); });
  };
  const iniciar = () => {
    const main = document.getElementById('main-content') || document.body;
    new MutationObserver(programar).observe(main, { childList: true, subtree: true });
    window.addEventListener('resize', programar);
    ajustar();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
  window.WMS_ENCABEZADOS_FIJOS = { ajustar };
})();
