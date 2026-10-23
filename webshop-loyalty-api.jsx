/* =====================================================================
   WSLoyalty : fidélité du client connecté (app fidélité dans le webshop)
   ---------------------------------------------------------------------
   Mêmes données que l'écran Fidélité de l'app (dépôt latelier-by-pwa) :
   carte d'identification (QR du code client), points, coefficients du jour,
   cartes produit à tampons, catalogue de récompenses, récompenses obtenues.
   Le serveur (php-api/loyalty.php) lit les tables partagées de l'app.

   Endpoints (api-config.js pose endpoint = BASE_URL + '/auth/loyalty') :
     GET  {endpoint}?shopId=            -> Loyalty | { enabled:false }
     POST {endpoint}/claim {catalogId, shopId} -> Loyalty (+ rewardId)

   Pas de données de démonstration : sans API, get() rend null et l'écran
   garde son état « bientôt » ; claim() jette.
   ===================================================================== */
(function () {
  const authHeaders = () => {
    if (window.WSAuth && typeof window.WSAuth.authHeaders === 'function') return window.WSAuth.authHeaders();
    try { const t = localStorage.getItem('ws_auth_token'); return t ? { 'Authorization': 'Bearer ' + t } : {}; }
    catch (_) { return {}; }
  };
  const errorOf = (j, r) => (j && typeof j.error === 'string' && j.error) ? j.error : `Erreur ${r.status}`;

  const api = {
    endpoint: null,

    /* Charge utile de l'écran Fidélité. null = service non joignable ou non
       connecté (l'écran ne montre alors pas de chiffre inventé). */
    async get({ shopId } = {}) {
      if (!api.endpoint) return null;
      const qs = shopId ? `?shopId=${encodeURIComponent(shopId)}` : '';
      const r = await fetch(api.endpoint + qs, { credentials: 'include', headers: { ...authHeaders() } });
      if (r.status === 401) return null;
      const j = await r.json().catch(() => null);
      if (!r.ok) throw new Error(errorOf(j, r));
      return j;
    },

    /* Échange de points contre une récompense du catalogue. */
    async claim({ catalogId, shopId } = {}) {
      if (!api.endpoint) throw new Error('Service fidélité indisponible.');
      const r = await fetch(api.endpoint + '/claim', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...authHeaders() },
        body: JSON.stringify({ catalogId, shopId: shopId || null }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(errorOf(j, r));
      return j;
    },
  };

  window.WSLoyalty = api;
})();
