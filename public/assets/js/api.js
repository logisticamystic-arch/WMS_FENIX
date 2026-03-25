/**
 * Prooriente WMS - API Wrapper
 * Features: JWT auth, smart cache for static data, retry on network failure,
 *           request deduplication (in-flight queue).
 */

/**
 * Escape HTML special characters to prevent XSS when rendering
 * server data inside innerHTML / template literals.
 * @param {*} s - Value to escape
 * @returns {string}
 */
window.escHTML = function(s) {
    if (s === null || s === undefined) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const api = {
    baseUrl: '/WMS_PROORIENTE/public/api',

    // ── Auth helpers ──────────────────────────────────────────────────────────

    getToken() {
        return localStorage.getItem('jwt_token');
    },

    setToken(token) {
        localStorage.setItem('jwt_token', token);
    },

    clearAuth() {
        localStorage.removeItem('jwt_token');
        localStorage.removeItem('user_data');
    },

    // ── Static data cache (proveedores, productos, sucursales — rarely change) ─

    _cache: {},
    _cacheMaxAge: 5 * 60 * 1000, // 5 minutes

    _cacheGet(key) {
        const entry = this._cache[key];
        if (!entry) return null;
        if (Date.now() - entry.ts > this._cacheMaxAge) {
            delete this._cache[key];
            return null;
        }
        return entry.data;
    },

    _cacheSet(key, data) {
        this._cache[key] = { data, ts: Date.now() };
    },

    cacheClear(key) {
        if (key) delete this._cache[key];
        else this._cache = {};
    },

    // ── In-flight deduplication ───────────────────────────────────────────────
    // Prevents duplicate concurrent GET requests to the same URL.

    _inflight: {},

    // ── Core request ─────────────────────────────────────────────────────────

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;

        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...(options.headers || {}),
        };

        const token = this.getToken();
        if (token) headers['Authorization'] = `Bearer ${token}`;

        const fetchOptions = { ...options, headers };

        // Retry logic — only on network errors, not HTTP errors
        const MAX_RETRIES = 3;
        const RETRY_BASE  = 1000; // 1s, 2s, 4s

        let lastError;
        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            try {
                const response = await fetch(url, fetchOptions);
                let data;
                const contentType = response.headers.get('Content-Type') || '';
                if (contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    // Binary response (e.g. CSV export)
                    return response;
                }

                if (response.status === 401) {
                    this.clearAuth();
                    window.location.reload();
                    throw new Error(data.message || 'Sesión expirada');
                }

                if (!response.ok || data.error) {
                    throw new Error(data.message || `Error ${response.status}`);
                }

                return data;

            } catch (err) {
                lastError = err;

                // Don't retry on HTTP application errors (4xx) — only network failures
                const isNetworkError = err instanceof TypeError && err.message === 'Failed to fetch';
                if (!isNetworkError || attempt === MAX_RETRIES) break;

                const delay = RETRY_BASE * Math.pow(2, attempt);
                console.warn(`API retry ${attempt + 1}/${MAX_RETRIES} for ${endpoint} in ${delay}ms`);
                await new Promise(r => setTimeout(r, delay));
            }
        }

        console.error('API Error:', lastError);
        throw lastError;
    },

    // ── HTTP methods ──────────────────────────────────────────────────────────

    async get(endpoint, { cache = false } = {}) {
        // Return cached value if requested
        if (cache) {
            const cached = this._cacheGet(endpoint);
            if (cached !== null) return cached;
        }

        // Deduplicate concurrent requests for the same endpoint
        if (this._inflight[endpoint]) {
            return this._inflight[endpoint];
        }

        const promise = this.request(endpoint, { method: 'GET' })
            .then(data => {
                delete this._inflight[endpoint];
                if (cache) this._cacheSet(endpoint, data);
                return data;
            })
            .catch(err => {
                delete this._inflight[endpoint];
                throw err;
            });

        this._inflight[endpoint] = promise;
        return promise;
    },

    async post(endpoint, data) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(data),
        });
    },

    async put(endpoint, data) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data),
        });
    },

    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    },

    // ── Cached GET helpers for common static data ─────────────────────────────

    getProductos()    { return this.get('/param/productos',    { cache: true }); },
    getProveedores()  { return this.get('/param/proveedores',  { cache: true }); },
    getSucursales()   { return this.get('/param/sucursales',   { cache: true }); },
    getUbicaciones()  { return this.get('/param/ubicaciones',  { cache: true }); },
    getClientes()     { return this.get('/param/clientes',     { cache: true }); },
};

window.api = api;

// ── Toast shim — bridges window.Toast calls to window.showToast (defined in auth.js) ──
window.Toast = {
    success(msg) { if (window.showToast) window.showToast(msg, 'success'); },
    error(msg)   { if (window.showToast) window.showToast(msg, 'error'); },
    info(msg)    { if (window.showToast) window.showToast(msg, 'info'); },
};
