/* =====================================================================
   WSCatalog — products / bundles / assortments API stub
   ---------------------------------------------------------------------
   The UI must NEVER hardcode catalog data. It calls these helpers,
   which default to in-memory seeds (window._CATALOG_SEED). To wire a
   real backend:
     window.WSCatalog.endpoint = 'https://your-host/catalog';
   Endpoints expected:
     GET  {endpoint}/products?shopId=&cat=     -> [Product]
     GET  {endpoint}/products/:id              -> Product
     GET  {endpoint}/bundles?productId=        -> [Bundle]
     GET  {endpoint}/assortments?shopId=       -> [Assortment]
     GET  {endpoint}/categories?shopId=        -> [Category]
     GET  {endpoint}/stock?shopId=&date=&mode= -> [StockEntry]
          StockEntry: { productId, qty_total, qty_reserved, qty_sold, qty_available }
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,
    async listCategories({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/categories?shopId=${encodeURIComponent(shopId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return (window._CATALOG_SEED && window._CATALOG_SEED.categories) || [];
    },
    async listProducts({ shopId, cat } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/products?shopId=${encodeURIComponent(shopId||'')}&cat=${encodeURIComponent(cat||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      const seed = (window._CATALOG_SEED && window._CATALOG_SEED.products) || [];
      if (!cat || cat === 'all') return seed;
      return seed.filter((p) => p.cat === cat);
    },
    async getProduct(id) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/products/${encodeURIComponent(id)}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      const seed = (window._CATALOG_SEED && window._CATALOG_SEED.products) || [];
      return seed.find((p) => String(p.id) === String(id)) || null;
    },
    async listBundles({ productId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/bundles?productId=${encodeURIComponent(productId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      const p = await api.getProduct(productId);
      return (p && p.bundles) || [];
    },
    async listAssortments({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/assortments?shopId=${encodeURIComponent(shopId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return (window._CATALOG_SEED && window._CATALOG_SEED.assortments) || [];
    },
    // Returns a map of productId -> { qty_total, qty_reserved, qty_sold, qty_available }
    // Falls back to delivery_stock on the product seed when no endpoint is configured.
    async getStock({ shopId, date, mode } = {}) {
      if (api.endpoint) {
        try {
          const iso = date instanceof Date ? date.toISOString().slice(0, 10) : (date || '');
          const r = await fetch(
            `${api.endpoint}/stock?shopId=${encodeURIComponent(shopId||'')}&date=${encodeURIComponent(iso)}&mode=${encodeURIComponent(mode||'')}`,
            { credentials: 'include' }
          );
          if (r.ok) {
            const rows = await r.json();
            const map = {};
            for (const row of rows) map[row.productId] = row;
            return map;
          }
        } catch (_) {}
      }
      // Seed fallback: build map from delivery_stock on each product
      const seed = (window._CATALOG_SEED && window._CATALOG_SEED.products) || [];
      const map = {};
      for (const p of seed) {
        if (typeof p.delivery_stock === 'number') {
          map[p.id] = {
            productId: p.id,
            qty_total: p.delivery_stock,
            qty_reserved: 0,
            qty_sold: 0,
            qty_available: p.delivery_stock,
          };
        }
      }
      return map;
    },
    // Reserve qty for a logged-in user's basket (15-min hold).
    // Only called when user is authenticated. No-op when no endpoint is set.
    // POST {endpoint}/stock/reserve { productId, shopId, date, mode, qty, customerId }
    // -> { ok, reservationId, expiresAt }
    async reserve({ productId, shopId, date, mode, qty, customerId } = {}) {
      if (!api.endpoint) return null;
      try {
        const iso = date instanceof Date ? date.toISOString().slice(0, 10) : (date || '');
        const r = await fetch(`${api.endpoint}/stock/reserve`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ productId, shopId, date: iso, mode, qty, customerId }),
        });
        if (r.ok) return await r.json();
      } catch (_) {}
      return null;
    },
    // Release one or all reservations for a customer.
    // POST {endpoint}/stock/release { customerId, reservationIds? }
    // reservationIds absent = release all for that customer.
    async release({ customerId, reservationIds } = {}) {
      if (!api.endpoint) return;
      try {
        await fetch(`${api.endpoint}/stock/release`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ customerId, reservationIds: reservationIds || null }),
        });
      } catch (_) {}
    },
  };
  window.WSCatalog = api;
})();
