/* =====================================================================
   WSPayments : allowed payment methods per shop AND per profile.
     window.WSPayments.endpoint = BASE_URL + '/payment-methods';

   GET {endpoint}?shopId=&profile=guest|registered|company&companyId=&mode=
     -> [{ method, label, family: 'stripe'|'shop'|'deferred' }]

   `family` est calculée par le SERVEUR (payment_family) : c'est elle qui
   dit si le moyen passe par la page de paiement hébergée. Le front ne
   tient pas sa propre liste de méthodes carte.

   `mode` est indispensable : en livraison, le serveur écarte « paiement en
   boutique » : le client ne s'y rend jamais. Sans ce paramètre il était
   proposé, et la commande partait au bureau sans encaissement possible.

   Returns [] when no endpoint (demo mode); the UI then falls back to its
   built-in list.
   ===================================================================== */
(function () {
  const api = {
    endpoint: null,
    async list({ shopId, profile = 'guest', companyId, mode } = {}) {
      if (!api.endpoint || !shopId) return [];
      try {
        let u = `${api.endpoint}?shopId=${encodeURIComponent(shopId)}&profile=${encodeURIComponent(profile)}`;
        if (companyId) u += `&companyId=${encodeURIComponent(companyId)}`;
        if (mode) u += `&mode=${encodeURIComponent(mode)}`;
        // `lang` : le libellé du moyen de paiement est traduit par le SERVEUR
        // (clés pay.* de ws_i18n), comme les catégories et les produits.
        const lg = (window.WSI18n && window.WSI18n.getLang && window.WSI18n.getLang()) || '';
        if (lg) u += `&lang=${encodeURIComponent(lg)}`;
        const r = await fetch(u, { credentials: 'include' });
        if (!r.ok) return [];
        const j = await r.json();
        return Array.isArray(j) ? j : [];
      } catch (_) { return []; }
    },
  };
  window.WSPayments = api;
})();
