const fs = require('fs');
let txt = fs.readFileSync('public/assets/js/desktop/maestro.js', 'utf8');

// Eliminar el importarGenerico inyectado previamente (desde "importarGenerico(tipo) {" hacia abajo).
const startIdx = txt.indexOf('importarGenerico(tipo) {');
if (startIdx !== -1) {
    txt = txt.substring(0, startIdx);
} else {
    // Si no lo encuentra, quita la llave de cierre final
    const closeIdx = txt.lastIndexOf('}');
    if (closeIdx !== -1) txt = txt.substring(0, closeIdx);
}

const inject = `
  importarGenerico(tipo) {
    WMS.showModal('Importación Masiva — ' + tipo.toUpperCase(), \`
      <div class="alert alert-info" style="margin-bottom:15px; font-size:.85rem; border-radius:8px;">
        <i class="fa-solid fa-info-circle"></i> Suba su archivo CSV/Excel para importar <b>\${tipo}</b>.<br>
        Puede descargar la plantilla usando el botón de exportación estando la tabla vacía.
      </div>
      
      <div id="import-dropzone" style="border:2px dashed #94a3b8; border-radius:12px; padding:30px; text-align:center; background:#f8fafc; cursor:pointer; transition:all 0.2s;" onclick="document.getElementById('import-csv-file').click()" onmouseover="this.style.borderColor='#3b82f6';this.style.background='#eff6ff'" onmouseout="this.style.borderColor='#94a3b8';this.style.background='#f8fafc'">
        <i class="fa-solid fa-cloud-arrow-up" style="font-size:40px; color:#94a3b8; margin-bottom:10px; display:block;"></i>
        <div style="font-size:16px; font-weight:800; color:#1e293b; margin-bottom:6px;">Haga clic para seleccionar archivo</div>
        <div style="font-size:12px; color:#64748b;">CSV, XLS o XLSX (Máx 10MB)</div>
      </div>
      <input type="file" id="import-csv-file" style="display:none;" accept=".csv,.txt" onchange="WMS_MODULES.maestro._onFileSelect('\${tipo}')">

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
    \`,
    \`<button class="btn btn-secondary" onclick="WMS.closeModal('generic-modal')">Cancelar</button>
     <button class="btn btn-primary" id="btn-do-import" onclick="WMS_MODULES.maestro.doImportGenerico('\${tipo}')" disabled><i class="fa-solid fa-upload"></i> Procesar Importación</button>\`,
    { width: '700px' });
  },

  _onFileSelect(tipo) {
    const file = document.getElementById('import-csv-file')?.files[0];
    if (!file) return;

    document.getElementById('import-dropzone').style.display = 'none';
    document.getElementById('import-file-info').style.display = 'block';
    document.getElementById('import-file-name').textContent = file.name;
    document.getElementById('import-file-meta').textContent = \`\${(file.size/1024).toFixed(1)} KB — \${file.type || 'archivo de texto'}\`;

    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target.result;
      const lines = text.split(/\\r?\\n/).filter(l => l.trim());
      if (lines.length <= 1) {
        WMS.toast('warning', 'El archivo no contiene suficientes datos (mínimo cabecera y una fila)');
        return;
      }
      const sep = lines[0].includes(';') ? ';' : ',';
      const headers = lines[0].split(sep).map(h => h.trim());
      
      let html = '<table style="width:100%;border-collapse:collapse;font-size:11px;"><thead><tr style="background:#f1f5f9;border-bottom:2px solid #cbd5e1;">';
      html += headers.map(h => \`<th style="padding:6px;text-align:left;color:#475569;">\${WMS.esc(h)}</th>\`).join('');
      html += '</tr></thead><tbody>';

      const limit = Math.min(6, lines.length);
      for (let i = 1; i < limit; i++) {
        const cols = lines[i].split(sep).map(c => c.trim().replace(/^"|"$/g, ''));
        html += \`<tr style="border-bottom:1px solid #e2e8f0;">\`;
        for (let j = 0; j < headers.length; j++) {
           html += \`<td style="padding:6px;color:#1e293b;">\${WMS.esc(cols[j] || '')}</td>\`;
        }
        html += \`</tr>\`;
      }
      html += '</tbody></table>';

      document.getElementById('import-preview-table').innerHTML = html;
      document.getElementById('import-preview-summary').innerHTML = \`<i class="fa-solid fa-check-circle" style="color:#2563eb;margin-right:6px;"></i>Se detectaron <strong>\${lines.length - 1} registros</strong> para procesar de <strong>\${tipo}</strong>.\`;
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
      const success = res.success || res.data?.creados || res.data?.success || 0;
      const updated = res.updated || res.data?.actualizados || res.data?.updated || 0;
      const skipped = res.skipped || res.data?.omitiendo || res.data?.skipped || 0;
      const errsArray = res.errors || res.data?.errors || [];

      WMS.closeModal();
      
      let rowsHtml = '';
      if (errsArray.length > 0) {
          rowsHtml = \`<div style="max-height:160px; overflow-y:auto;text-align:left;font-size:11px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;margin-top:10px;">
                        <div style="font-weight:700;color:#dc2626;margin-bottom:6px;"><i class="fa-solid fa-circle-exclamation"></i> Novedades y Errores de Líneas</div>
                        <ul style="margin:0;padding-left:14px;color:#991b1b;">\`;
          errsArray.slice(0,25).forEach(e => { rowsHtml += \`<li>\${WMS.esc(e)}</li>\`; });
          if(errsArray.length > 25) rowsHtml += \`<li style="font-style:italic">Y \${errsArray.length-25} errores adicionales...</li>\`;
          rowsHtml += \`</ul></div>\`;
      }

      await Swal.fire({
          icon: errsArray.length > 0 ? 'warning' : 'success',
          title: 'Resumen de Importación',
          width: 580,
          html: \`
            <div style="text-align:left;font-size:13px;">
              <div style="margin-bottom:8px;font-weight:700;color:#1e293b;">Módulo: \${tipo.toUpperCase()}</div>
              <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:8px;margin-bottom:14px;">
                <div style="padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#475569;">\${total}</div>
                  <div style="font-size:10px;color:#64748b;font-weight:700;">PROCESADOS</div>
                </div>
                <div style="padding:10px;background:#f0fdf4;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#16a34a;">\${success}</div>
                  <div style="font-size:10px;color:#4ade80;font-weight:700;">NUEVOS</div>
                </div>
                <div style="padding:10px;background:#eff6ff;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#2563eb;">\${updated}</div>
                  <div style="font-size:10px;color:#60a5fa;font-weight:700;">ACTUALIZADOS</div>
                </div>
                <div style="padding:10px;background:#fef2f2;border-radius:8px;text-align:center;">
                  <div style="font-size:22px;font-weight:800;color:#dc2626;">\${errsArray.length+skipped}</div>
                  <div style="font-size:10px;color:#f87171;font-weight:700;">OMITIDOS/ERROR</div>
                </div>
              </div>
              \${rowsHtml}
            </div>
          \`
      });

      if (this.show_productos && tipo === 'productos') this.show_productos();
      if (this.show_clientes && tipo === 'clientes') this.show_clientes();
      if (this.show_proveedores && tipo === 'proveedores') this.show_proveedores();
      
    } catch(e) {
      WMS.toast('error', e.message);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload"></i> Procesar Importación'; }
    }
  },
};
`;

fs.writeFileSync('public/assets/js/desktop/maestro.js', txt + inject);
console.log('Parcheado Correctamente!!');
