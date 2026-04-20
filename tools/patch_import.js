const fs = require('fs');
let txt = fs.readFileSync('public/assets/js/desktop/maestro.js', 'utf8');
const closeIndex = txt.lastIndexOf('}');
if (closeIndex !== -1) {
  const inject = `
  importarGenerico(tipo) {
    WMS.showModal('Importar ' + tipo.toUpperCase(), \`
      <div class="alert alert-info" style="margin-bottom:15px; font-size:.85rem;">
        <i class="fa-solid fa-info-circle"></i> Suba su archivo CSV/Excel para importar <b>\${tipo}</b>.<br>
        <b>Nota:</b> Puede descargar la plantilla usando el botón de exportación estando la tabla vacía.
      </div>
      <div class="form-group">
        <label class="form-label">Seleccione el archivo de subida</label>
        <input type="file" id="f-import-csv-generico" class="form-control" accept=".csv,.xlsx,.xls">
      </div>
      <div id="import-progress-generico" style="display:none; margin-top:15px;">
        <div class="spinner sm"></div> Procesando y validando...
      </div>\`,
      \`<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
       <button class="btn btn-primary" onclick="WMS_MODULES.maestro.doImportGenerico('\${tipo}')"><i class="fa-solid fa-upload"></i> Procesar</button>\`);
  },

  async doImportGenerico(tipo) {
    const file = document.getElementById('f-import-csv-generico')?.files[0];
    if (!file) { WMS.toast('warning', 'Seleccione un archivo válido'); return; }
    
    const progress = document.getElementById('import-progress-generico');
    progress.style.display = 'block';

    const formData = new FormData();
    formData.append('file', file);

    try {
      const r = await fetch(API.url + '/param/import-export/upload/' + tipo, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + API.token },
        body: formData
      });
      const res = await r.json();
      if (!r.ok) throw new Error(res.error || 'Error en la importación');
      
      WMS.closeModal();
      WMS.toast('success', res.message || 'Importación exitosa. Creados: ' + (res.data?.creados||0) + ' / Act: ' + (res.data?.actualizados||0));
      
      if (this.show_productos && tipo === 'productos') this.show_productos();
      if (this.show_clientes && tipo === 'clientes') this.show_clientes();
      if (this.show_proveedores && tipo === 'proveedores') this.show_proveedores();
      
    } catch(e) {
      WMS.toast('error', e.message);
      progress.style.display = 'none';
    }
  },
`;
  txt = txt.substring(0, closeIndex) + inject + '};\n';
  fs.writeFileSync('public/assets/js/desktop/maestro.js', txt);
  console.log('Injected successfully');
}
