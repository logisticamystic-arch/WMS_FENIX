/**
 * DataCache Integration Helpers
 * Provides async wrappers and caching patterns for WMS modules
 */

const CacheHelpers = {
  /**
   * Load ODCs with caching (1-minute auto-refresh)
   * @param {Object} params - Query parameters
   */
  async loadODCs(params = {}) {
    const key = `odc:list:${JSON.stringify(params)}`;
    return DataCache.get(key, async () => {
      const qs = new URLSearchParams(params).toString();
      return API.get('/odc', qs);
    });
  },
  
  /**
   * Load single ODC with caching
   * @param {number} id - ODC ID
   */
  async loadODC(id) {
    return DataCache.get(`odc:${id}`, async () => {
      return API.get(`/odc/${id}`);
    });
  },
  
  /**
   * Load recepciones with caching
   * @param {Object} params - Query filters
   */
  async loadRecepciones(params = {}) {
    const key = `recepciones:list:${JSON.stringify(params)}`;
    return DataCache.get(key, async () => {
      const qs = new URLSearchParams(params).toString();
      return API.get('/recepciones', qs);
    });
  },
  
  /**
   * Load ubicaciones with caching
   * @param {Object} params - Query filters
   */
  async loadUbicaciones(params = {}) {
    const key = `ubicaciones:list:${JSON.stringify(params)}`;
    return DataCache.get(key, async () => {
      const qs = new URLSearchParams(params).toString();
      return API.get('/param/ubicaciones', qs);
    });
  },
  
  /**
   * Load inventario with caching
   * @param {Object} params - Query filters
   */
  async loadInventario(params = {}) {
    const key = `inventario:list:${JSON.stringify(params)}`;
    return DataCache.get(key, async () => {
      const qs = new URLSearchParams(params).toString();
      return API.get('/inventario/stock', qs);
    });
  },
  
  /**
   * Load picking tasks with caching
   */
  async loadPickingTasks(params = {}) {
    const key = `picking:tasks:${JSON.stringify(params)}`;
    return DataCache.get(key, async () => {
      const qs = new URLSearchParams(params).toString();
      return API.get('/picking/tareas', qs);
    });
  },
  
  /**
   * Invalidate related caches after mutation
   * Useful to call after POST/PUT/DELETE operations
   * @param {string} type - Cache type to invalidate: 'odc', 'recepcion', 'picking', '*'
   */
  invalidateCache(type = '*') {
    const patterns = {
      'odc': 'odc:*',
      'recepcion': 'recepciones:*',
      'picking': 'picking:*',
      'ubicacion': 'ubicaciones:*',
      'inventario': 'inventario:*',
      '*': '*', // Invalidate all
    };
    DataCache.invalidate(patterns[type] || type);
  },
  
  /**
   * Watch for cache changes and trigger callback
   * @param {string} key - Cache key to watch
   * @param {Function} callback - Called with new data
   */
  watch(key, callback) {
    DataCache.onChange(key, callback);
  },
  
  /**
   * Unwatch cache changes
   * @param {string} key - Cache key
   * @param {Function} callback - Callback to remove
   */
  unwatch(key, callback) {
    DataCache.offChange(key, callback);
  },
};

/**
 * WMS Module Enhanced - Wraps WMS modules to integrate auto-refresh
 * Usage: 
 *   WMS_MODULES.recepcion.enableAutoRefresh('odc', () => recepcion.show_odc());
 */
const ModuleAutoRefresh = {
  _refreshCallbacks: new Map(),
  
  /**
   * Enable auto-refresh for a module component
   * @param {string} key - Cache key to watch
   * @param {Function} refreshFn - Function to call on cache invalidation
   */
  enable(key, refreshFn) {
    if (this._refreshCallbacks.has(key)) {
      this.disable(key);
    }
    this._refreshCallbacks.set(key, refreshFn);
    
    // Trigger refresh when cache is invalidated
    DataCache.onChange(key, () => {
      console.log(`🔄 Auto-refreshing ${key}`);
      refreshFn();
    });
  },
  
  /**
   * Disable auto-refresh for component
   */
  disable(key) {
    this._refreshCallbacks.delete(key);
  },
  
  /**
   * Disable all auto-refresh callbacks
   */
  disableAll() {
    this._refreshCallbacks.clear();
  },
};

console.log('✅ CacheHelpers & ModuleAutoRefresh loaded');
