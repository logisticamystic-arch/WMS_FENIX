/**
 * DataCache - Centralized data caching and auto-refresh system for WMS
 * Provides 1-minute global refresh interval and event-driven cache invalidation
 */

const DataCache = {
  // Cache storage - key: cacheKey, value: {data, timestamp, ttl}
  _cache: new Map(),
  _timers: new Map(),
  _listeners: new Map(),
  _refreshInterval: 60000, // 1 minute default
  _globalRefreshTimer: null,
  
  /**
   * Configure cache settings
   * @param {Object} options - Configuration options
   */
  config({ refreshInterval = 60000 } = {}) {
    this._refreshInterval = refreshInterval;
    console.log(`🔄 DataCache configured: ${refreshInterval}ms refresh interval`);
  },
  
  /**
   * Start global auto-refresh timer
   */
  startAutoRefresh() {
    if (this._globalRefreshTimer) return;
    this._globalRefreshTimer = setInterval(() => this.invalidateAll(), this._refreshInterval);
    console.log(`⏱️  Global auto-refresh started (${this._refreshInterval}ms)`);
  },
  
  /**
   * Stop global auto-refresh timer
   */
  stopAutoRefresh() {
    if (this._globalRefreshTimer) {
      clearInterval(this._globalRefreshTimer);
      this._globalRefreshTimer = null;
      console.log('⏱️  Global auto-refresh stopped');
    }
  },
  
  /**
   * Get cached data or fetch if expired
   * @param {string} key - Cache key
   * @param {Function} fetchFn - Async function to fetch data
   * @param {number} ttl - Time-to-live in ms (optional, default = 1 refresh interval)
   * @returns {Promise} Cached or fresh data
   */
  async get(key, fetchFn, ttl = this._refreshInterval) {
    const cached = this._cache.get(key);
    
    // Return cached data if still valid
    if (cached && (Date.now() - cached.timestamp < cached.ttl)) {
      return cached.data;
    }
    
    // Fetch fresh data
    try {
      const data = await fetchFn();
      this._cache.set(key, { data, timestamp: Date.now(), ttl });
      this._notifyListeners(key, data);
      return data;
    } catch (err) {
      console.error(`❌ DataCache fetch failed for ${key}:`, err.message);
      // Return stale data if available, otherwise rethrow
      if (cached) return cached.data;
      throw err;
    }
  },
  
  /**
   * Set cache data directly (useful for POST operations)
   * @param {string} key - Cache key
   * @param {*} data - Data to cache
   * @param {number} ttl - Time-to-live in ms
   */
  set(key, data, ttl = this._refreshInterval) {
    this._cache.set(key, { data, timestamp: Date.now(), ttl });
    this._notifyListeners(key, data);
  },
  
  /**
   * Invalidate single cache entry
   * @param {string} key - Cache key(s) to invalidate (supports wildcards: "odc:*")
   */
  invalidate(key) {
    if (key.includes('*')) {
      // Invalidate all keys matching pattern
      const pattern = key.replace('*', '');
      for (const k of this._cache.keys()) {
        if (k.startsWith(pattern)) {
          this._cache.delete(k);
          console.log(`🗑️  Cache invalidated: ${k}`);
        }
      }
    } else {
      this._cache.delete(key);
      console.log(`🗑️  Cache invalidated: ${key}`);
    }
  },
  
  /**
   * Invalidate all cache entries
   */
  invalidateAll() {
    const count = this._cache.size;
    this._cache.clear();
    if (count > 0) console.log(`🗑️  All ${count} cache entries invalidated`);
  },
  
  /**
   * Register listener for cache updates
   * @param {string} key - Cache key
   * @param {Function} callback - Called when data changes: callback(data)
   */
  onChange(key, callback) {
    if (!this._listeners.has(key)) this._listeners.set(key, []);
    this._listeners.get(key).push(callback);
  },
  
  /**
   * Unregister listener
   * @param {string} key - Cache key
   * @param {Function} callback - Callback to remove
   */
  offChange(key, callback) {
    const listeners = this._listeners.get(key);
    if (listeners) {
      const idx = listeners.indexOf(callback);
      if (idx > -1) listeners.splice(idx, 1);
    }
  },
  
  /**
   * Notify registered listeners when cache updates
   * @private
   */
  _notifyListeners(key, data) {
    const listeners = this._listeners.get(key) || [];
    listeners.forEach(cb => {
      try { cb(data); } catch (e) { console.error('Listener error:', e); }
    });
  },
  
  /**
   * Get cache statistics
   */
  stats() {
    const entries = Array.from(this._cache.entries()).map(([k, v]) => ({
      key: k,
      age: Date.now() - v.timestamp,
      ttl: v.ttl,
      expired: Date.now() - v.timestamp >= v.ttl,
    }));
    return {
      totalEntries: this._cache.size,
      entries,
      refreshInterval: this._refreshInterval,
      isAutoRefreshing: !!this._globalRefreshTimer,
    };
  },
  
  /**
   * Clear all cache and listeners
   */
  clear() {
    this._cache.clear();
    this._listeners.clear();
    this.stopAutoRefresh();
    console.log('DataCache cleared');
  },
};

// ── MODO MANUAL: auto-refresh global desactivado temporalmente ─────────────────
// Solo picking y certificación tienen auto-refresh propio (30s).
// Los demás módulos usan el botón "Actualizar" en toolbar.
document.addEventListener('DOMContentLoaded', () => {
  DataCache.config({ refreshInterval: 300000 }); // TTL caché = 5 min
  // DataCache.startAutoRefresh() ← desactivado intencionalmente
  // Para reactivar: DataCache.startAutoRefresh();
});
