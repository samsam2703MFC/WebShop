// webshop-shops-api.jsx — Shops directory accessor.
//
// Per profile spec: "No hardcoded shop data. Shops must come from API."
// This module is the seam — UI code calls window.WSShops.list() and never
// imports the W_SHOPS object directly. To swap in a real backend, set:
//   window.WSShops.endpoint = 'https://your-host/shops';
// Response must be an array (or {shops: [...]}) of {id,name,city,accent,address}.
//
// While endpoint is null we resolve from window.W_SHOPS (which the bundle
// happens to expose as a fixture). The contract for the rest of the app is
// the SAME either way: an async list, a get(id), and a memoised cache.
(function () {
  let endpoint = null;            // backend URL when wired
  let cache = null;               // last successful fetch
  let inflight = null;            // promise dedupe

  function fromFixture() {
    // Soft-fall to the in-memory fixture exposed by the bundle.
    if (typeof window !== 'undefined' && window.W_SHOPS) {
      return Object.values(window.W_SHOPS);
    }
    return [];
  }

  async function fetchRemote(url) {
    const r = await fetch(url, { credentials: 'include' });
    if (!r.ok) throw new Error('shops_http_' + r.status);
    const j = await r.json();
    return Array.isArray(j) ? j : (j.shops || []);
  }

  async function list({ force = false } = {}) {
    if (!force && cache) return cache;
    if (inflight) return inflight;
    inflight = (async () => {
      try {
        const shops = endpoint ? await fetchRemote(endpoint) : fromFixture();
        cache = shops.slice();
        return cache;
      } finally {
        inflight = null;
      }
    })();
    return inflight;
  }

  async function get(id) {
    const shops = await list();
    return shops.find((s) => s.id === id) || null;
  }

  function setEndpoint(url) { endpoint = url || null; cache = null; }
  function getCacheSync() { return cache ? cache.slice() : fromFixture(); }

  window.WSShops = { list, get, setEndpoint, getCacheSync,
    get endpoint() { return endpoint; },
    set endpoint(v) { setEndpoint(v); } };
})();
