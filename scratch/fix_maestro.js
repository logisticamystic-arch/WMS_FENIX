const fs = require('fs');
const path = 'public/assets/js/desktop/maestro.js';
let content = fs.readFileSync(path, 'utf8');

const startTag = 'async doImportGenerico(tipo) {';
const startIndex = content.indexOf(startTag);

if (startIndex === -1) {
    console.error('Could not find start of doImportGenerico');
    process.exit(1);
}

// Find the end of the function (it's the last function in the object before };)
const endTag = '  },\n};';
const endIndex = content.lastIndexOf(endTag);

if (endIndex === -1) {
    console.error('Could not find end of doImportGenerico');
    process.exit(1);
}

const newFunction = `  async doImportGenerico(tipo) {
    const file = document.getElementById('import-csv-file')?.files[0];
    if (!file) return;

    const btn = document.getElementById('btn-do-import');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...'; }

    const formData = new FormData();
    formData.append('file', file);
    const token = localStorage.getItem('wms_token') || '';

    try {
      const url = '/WMS_PROORIENTE/public/api/param/import-export/upload/' + tipo;
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
          rowsHtml = \`<div style="max-height:160px; overflow-y:auto;text-align:left;font-size:11px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-top:10px;">
                        <div style="font-weight:700;color:#dc2626;margin-bottom:6px;"><i class="fa-solid fa-circle-exclamation"></i> Novedades y Errores de Líneas (\${errsArray.length})</div>
                        <ul style="margin:0;padding-left:14px;color:#991b1b;">\`;
          errsArray.slice(0,30).forEach(e => { rowsHtml += \`<li>\${WMS.esc(e)}</li>\`; });
          if(errsArray.length > 30) rowsHtml += \`<li style="font-style:italic;margin-top:4px;">Y \${errsArray.length-30} novedades adicionales...</li>\`;
          rowsHtml += \`</ul></div>\`;
      }

      const modalHtml = \`
        <div style="text-align:left;font-size:13px;">
          <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
             <span style="font-weight:700;color:#1e293b;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">Módulo: \${tipo.toUpperCase()}</span>
             <span class="badge \${errsArray.length > 0 ? 'badge-warning' : 'badge-success'}" style="font-size:10px;">
                \${errsArray.length > 0 ? 'Con novedades' : 'Exitoso'}
             </span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:14px;">
            <div style="padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-align:center;">
              <div style="font-size:20px;font-weight:800;color:#475569;">\${total}</div>
              <div style="font-size:9px;color:#64748b;font-weight:700;">TOTAL</div>
            </div>
            <div style="padding:10px;background:#f0fdf4;border-radius:8px;text-align:center;border:1px solid #dcfce7;">
              <div style="font-size:20px;font-weight:800;color:#16a34a;">\${success}</div>
              <div style="font-size:9px;color:#16a34a;font-weight:700;">NUEVOS</div>
            </div>
            <div style="padding:10px;background:#eff6ff;border-radius:8px;text-align:center;border:1px solid #dbeafe;">
              <div style="font-size:20px;font-weight:800;color:#2563eb;">\${updated}</div>
              <div style="font-size:9px;color:#2563eb;font-weight:700;">ACTUALIZADOS</div>
            </div>
            <div style="padding:10px;background:#fef2f2;border-radius:8px;text-align:center;border:1px solid #fee2e2;">
              <div style="font-size:20px;font-weight:800;color:#dc2626;">\${errsArray.length + skipped}</div>
              <div style="font-size:9px;color:#dc2626;font-weight:700;">AVISOS</div>
            </div>
          </div>
          \${rowsHtml}
        </div>
      \`;

      WMS.showModal('Resultado de Importación', modalHtml, \`<button class="btn btn-primary" onclick="WMS.closeModal('generic-modal')">Entendido</button>\`);

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
  },`;

const newContent = content.substring(0, startIndex) + newFunction + content.substring(endIndex + 3);
fs.writeFileSync(path, newContent, 'utf8');
console.log('Successfully updated doImportGenerico');
