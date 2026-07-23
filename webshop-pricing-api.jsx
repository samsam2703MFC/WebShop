/* =====================================================================
   WSPricing — basket pricing / promos / payment-methods API stub
   ---------------------------------------------------------------------
   The frontend MUST NOT contain pricing rules. Every rule below is a
   backend concern; this module is the seam.
   To wire a real backend, set:
     window.WSPricing.endpoint = 'https://your-host/pricing';
   Endpoints expected:
     POST {endpoint}/quote               -> { subtotal, discounts:[…], total, lines:[…] }
       body: { shopId, mode, basket:[{productId, qty, portion?, options?}], voucher? }
     GET  {endpoint}/payment-methods?shopId=&mode=  -> [{id,label,sub}]
     GET  {endpoint}/promos/cross-portion?shopId=   -> { x, y, threshold, label, eligibleCats:[…] }

   ─── FALLBACK BEHAVIOUR ─────────────────────────────────────────────
   The fallback below is intentionally a thin local mirror so the demo
   storefront still computes a sensible total when no backend is wired.
   It is NOT canonical. Production must use the API.
   ===================================================================== */
(function () {
  // Go-live : plus aucune regle de prix/promo/paiement seed cote client.
  // Sans reponse API : null / listes vides / erreur explicite.

  const api = {
    endpoint: null,

    async getCrossPortionRule({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/promos/cross-portion?shopId=${encodeURIComponent(shopId||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return null; // pas de regle serveur -> pas d'offre
    },

    async listPaymentMethods({ shopId, mode } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/payment-methods?shopId=${encodeURIComponent(shopId||'')}&mode=${encodeURIComponent(mode||'')}`, { credentials: 'include' });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      return []; // pas d'API -> aucun moyen de paiement propose
    },

    /* Quote a basket. Backend returns final totals + every applied
       discount; frontend just renders them. The fallback recomputes
       locally so the demo still adds up to the same numbers. */
    async quote({ shopId, mode, basket, voucher } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/quote`, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shopId, mode, basket, voucher }),
          });
          if (r.ok) return await r.json();
        } catch (_) {}
      }
      throw new Error('API tarification indisponible.');
    },
  };

  if (typeof window !== 'undefined') window.WSPricing = api;
})();
