/**
 * Prooriente WMS - API Wrapper
 */
const api = {
    baseUrl: '/Prooriente/public/api',

    // Retrieves JWT token
    getToken() {
        return localStorage.getItem('jwt_token');
    },

    // Saves JWT token
    setToken(token) {
        localStorage.setItem('jwt_token', token);
    },

    clearAuth() {
        localStorage.removeItem('jwt_token');
        localStorage.removeItem('user_data');
    },

    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}${endpoint}`;
        
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            ...(options.headers || {})
        };

        const token = this.getToken();
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        const fetchOptions = {
            ...options,
            headers
        };

        try {
            const response = await fetch(url, fetchOptions);
            const data = await response.json();

            // Unauthorized - clear token and restart
            if (response.status === 401) {
                this.clearAuth();
                window.location.reload();
                throw new Error(data.message || 'Sesión expirada');
            }

            if (!response.ok || data.error) {
                throw new Error(data.message || 'Error en la petición');
            }

            return data;
        } catch (error) {
            console.error('API Request Error:', error);
            throw error;
        }
    },

    async post(endpoint, data) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(data)
        });
    },

    async get(endpoint) {
        return this.request(endpoint, {
            method: 'GET'
        });
    },

    async put(endpoint, data) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },

    async delete(endpoint) {
        return this.request(endpoint, {
            method: 'DELETE'
        });
    }
};

window.api = api;

// Shim window.Toast → usa window.showToast definido en auth.js
window.Toast = {
    success(msg) { if (window.showToast) window.showToast(msg, 'success'); },
    error(msg)   { if (window.showToast) window.showToast(msg, 'error'); },
    info(msg)    { if (window.showToast) window.showToast(msg, 'info'); },
};
