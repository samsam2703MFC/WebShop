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
  };
  window.WSCatalog = api;
})();
