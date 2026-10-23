/* =====================================================================
   WSInstore : panier caisse (« scan & go »)
   ---------------------------------------------------------------------
   En boutique, le client compose son panier puis touche « Payer à la
   caisse » : le serveur fige le panier aux prix de la boutique et rend un
   code court (« PC-7K3Q9X ») affiché en QR. La caisse le scanne, reprend
   les lignes et passe directement en auto-paiement ; le téléphone suit le
   statut (open → at_till → paid). Voir php-api/instore.php, SCAN_CAISSE.md.

   Endpoints (api-config.js pose endpoint = BASE_URL + '/instore/baskets') :
     POST   {endpoint}                    {shopId, basket[]} -> Basket + token
     GET    {endpoint}/:code?token=       -> statut
     DELETE {endpoint}/:code?token=       -> annulation (tant que la caisse ne l'a pas pris)

   Le jeton rendu à la création prouve que le téléphone est bien celui qui a
   créé le panier : il n'est jamais transmis à la caisse.
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

    /* Fige le panier aux prix de la boutique. Même forme de lignes que la
       commande (productId, qty, portion, bundleId, bundleSlots, bundleOfferId). */
    async create({ shopId, basket }) {
      if (!api.endpoint) throw new Error('Panier caisse indisponible.');
      const r = await fetch(api.endpoint, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...authHeaders() },
        body: JSON.stringify({
          shopId,
          basket: (basket || []).map((l) => ({
            productId: l.productId, qty: l.qty, portion: l.portion || null,
            bundleId: l.bundleId || null, bundleOfferId: l.bundleOfferId || null, bundleSlots: l.bundleSlots || {},
          })),
        }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(errorOf(j, r));
      return j;
    },

    async status({ code, token }) {
      if (!api.endpoint) throw new Error('Panier caisse indisponible.');
      const r = await fetch(`${api.endpoint}/${encodeURIComponent(code)}?token=${encodeURIComponent(token)}`, { credentials: 'include' });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(errorOf(j, r));
      return j;
    },

    async cancel({ code, token }) {
      if (!api.endpoint) throw new Error('Panier caisse indisponible.');
      const r = await fetch(`${api.endpoint}/${encodeURIComponent(code)}?token=${encodeURIComponent(token)}`, { method: 'DELETE', credentials: 'include' });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(errorOf(j, r));
      return j;
    },
  };

  window.WSInstore = api;
})();
