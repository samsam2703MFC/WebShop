// webshop-shops-api.jsx — Shops directory accessor.
//
// Per profile spec: "No hardcoded shop data. Shops must come from API."
// This module is the seam — UI code calls window.WSShops.list() and never
// imports the W_SHOPS object directly. To swap in a real backend, set:
//   window.WSShops.endpoint = 'https://your-host/shops';
// Response must be an array (or {shops: [...]}) of {id,name,city,accent,address}.
//
// GO-LIVE : la liste vient UNIQUEMENT de l'API (/shops → table `shops`). Le
// repli sur la fixture mémoire window.W_SHOPS a été SUPPRIMÉ — c'est lui qui a
// re-servi en production des boutiques de démonstration qui n'existent plus ni
// dans le code ni en base. Sans endpoint, list() lève une erreur : l'écran
// affiche « Boutiques indisponibles », jamais une liste inventée.
(function () {
  let endpoint = null;            // backend URL when wired
  let cache = null;               // last successful fetch
  let inflight = null;            // promise dedupe

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
        if (!endpoint) throw new Error('API boutiques non configurée.');
        const shops = await fetchRemote(endpoint);
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
  // Vide tant que l'API n'a pas répondu (sert d'état initial React) — aucune
  // boutique n'est affichée avant d'avoir la vraie liste.
  function getCacheSync() { return cache ? cache.slice() : []; }

  window.WSShops = { list, get, setEndpoint, getCacheSync,
    get endpoint() { return endpoint; },
    set endpoint(v) { setEndpoint(v); } };
})();
