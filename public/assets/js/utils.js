/**
 * WMS Core — Utils
 * Funciones de ayuda compartidas.
 */

window.WMS = window.WMS || {};

Object.assign(window.WMS, {
  esc(text) {
    if (text === null || text === undefined) return '';
    const m = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, s => m[s]);
  },

  formatNum(n) {
    return new Intl.NumberFormat('es-CO').format(parseFloat(n) || 0);
  },

  getToday() {
    return new Date().toISOString().split('T')[0];
  },

  spinner(container = 'content-body') {
    const el = document.getElementById(container);
    if (el) {
      el.innerHTML = '<div class="m-empty" style="padding:60px;"><div class="spinner spinner-lg" style="margin:0 auto;"></div></div>';
    }
  },

  toast(type = 'info', msg, title = '') {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      document.body.appendChild(container);
    }

    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const titles = { success: 'Éxito', error: 'Error', warning: 'Atención', info: 'Info' };

    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `
      <i class="fa-solid ${icons[type] || icons.info} toast-icon"></i>
      <div class="toast-content">
        <div class="toast-title">${title || titles[type] || 'Info'}</div>
        <div class="toast-msg">${msg}</div>
      </div>
    `;

    container.appendChild(el);
    setTimeout(() => el.classList.add('show'), 10);
    setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => el.remove(), 400);
    }, 4000);
  }
});
