/**
 * WMS Core — API Client
 * Maneja la comunicación con el backend de forma centralizada.
 */

const API_BASE = window.location.origin + '/WMS_PROORIENTE/public/api';
let _wmsToken = localStorage.getItem('wms_token') || '';

const API_CONFIG = {
  timeout: 12000,        // 12 segundos desktop
  mobileTimeout: 25000,  // 25 segundos mobile
  maxRetries: 2,
  retryDelay: 600,
  backoffFactor: 1.8,
};

const IS_MOBILE = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

async function apiCall(method, path, data = null, opts = {}) {
  const timeout = opts.timeout || (IS_MOBILE ? API_CONFIG.mobileTimeout : API_CONFIG.timeout);
  const maxRetries = opts.maxRetries !== undefined ? opts.maxRetries : API_CONFIG.maxRetries;
  
  let lastError;
  let notifiedUser = false;
  
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      const url = path.startsWith('http') ? path : API_BASE + path;
      const headers = { 'Content-Type': 'application/json' };
      if (_wmsToken) headers['Authorization'] = 'Bearer ' + _wmsToken;
      
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), timeout);
      
      const cfg = { 
        method, 
        headers,
        signal: controller.signal
      };
      if (data) cfg.body = JSON.stringify(data);
      
      const res = await fetch(url, cfg);
      clearTimeout(timeoutId);
      
      // 401 Unauthorized handling
      if (res.status === 401 && !path.includes('/auth/login')) {
        if (_wmsToken) {
          _wmsToken = '';
          localStorage.removeItem('wms_token');
          localStorage.removeItem('wms_user');
          if (window.WMS && WMS.logout) WMS.logout();
          else window.location.reload();
        }
        throw new Error('Sesión expirada. Inicie sesión nuevamente.');
      }
      
      let json;
      try {
        json = await res.json();
      } catch (err) {
        throw new Error(`Error de servidor (${res.status})`);
      }
      
      if (!res.ok) {
        const errorMsg = json?.message || `Error de servidor (${res.status})`;
        if (res.status >= 500 && res.status !== 501 && attempt < maxRetries) {
          const delay = API_CONFIG.retryDelay * Math.pow(API_CONFIG.backoffFactor, attempt - 1);
          if (!notifiedUser && window.WMS) {
            WMS.toast('warning', `Reintentando conexión...`);
            notifiedUser = true;
          }
          await new Promise(r => setTimeout(r, delay));
          continue;
        }
        throw new Error(errorMsg);
      }
      
      if (json && json.error) {
        throw new Error(json.message || 'Error de servidor');
      }
      
      return json;
    } catch (error) {
      lastError = error;
      if ((error.name === 'AbortError' || !navigator.onLine || error.message.includes('fetch')) && attempt < maxRetries) {
        const delay = API_CONFIG.retryDelay * Math.pow(API_CONFIG.backoffFactor, attempt - 1);
        await new Promise(r => setTimeout(r, delay));
        continue;
      }
      break;
    }
  }
  
  throw lastError || new Error('Error de comunicación');
}

const API = {
  get: (p, q = '', opts = {}) => apiCall('GET', p + (q ? '?' + q : ''), null, opts),
  post: (p, d, opts = {}) => apiCall('POST', p, d, opts),
  put: (p, d, opts = {}) => apiCall('PUT', p, d, opts),
  delete: (p, d, opts = {}) => apiCall('DELETE', p, d, opts),
};
