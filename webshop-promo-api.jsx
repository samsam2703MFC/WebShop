/* =====================================================================
   webshop-promo-api.jsx : Campagnes « objectif d'achat cumulé → cadeau ».
   =====================================================================
   Exposé en global window.WSPromo AVANT webshop-full-bundle.jsx (comme les
   autres stubs). En mode live, api-config.js pose window.WSPromo.endpoint
   = BASE_URL + '/promo' ; sinon fixtures démo (localStorage).

   Backend (php-api/index.php) :
     GET  {endpoint}/active?shopId=            -> [Campaign]
     GET  {endpoint}/:id/progress?guestEmail=  -> Progress
     POST {endpoint}/:id/claim                 -> Progress   (idempotent)
     POST {endpoint}/redeem  {code,shopId}     -> { valid, reward? , reason? }

   Auth : identique aux autres services, jeton Bearer (ws_auth_token) pour
   le client connecté ; sinon guestEmail. credentials:'include' en secours.
   ===================================================================== */
(function () {
  const TOKEN_KEY = 'ws_auth_token';
  const authHeaders = () => {
    let t = null; try { t = localStorage.getItem(TOKEN_KEY); } catch (_) {}
    return t ? { 'Authorization': 'Bearer ' + t } : {};
  };
  const jsonHeaders = () => Object.assign({ 'Content-Type': 'application/json' }, authHeaders());

  // ─── Fixtures démo (github.io / endpoint non câblé) ──────────────────
  const DEMO_KEY = 'atelier_promo_demo';
  function demoState() {
    try { const r = JSON.parse(localStorage.getItem(DEMO_KEY)); if (r && typeof r === 'object') return r; } catch (_) {}
    // 75 € déjà dépensés sur un objectif de 100 € → bandeau « en cours ».
    return { accumulated: 75, unlockedAt: null, voucherCode: null, redeemedAt: null };
  }
  function demoSave(s) { try { localStorage.setItem(DEMO_KEY, JSON.stringify(s)); } catch (_) {} }
  const DEMO_CAMPAIGN = {
    id: 1, name: 'Été gourmand', idShop: null, threshold: 100, currency: 'EUR',
    startsAt: '2026-07-01 00:00:00', endsAt: '2026-08-31 23:59:59',
    rewardDeliveryDate: '2026-08-05',
    reward: { productId: 42, name: "Huile d'olive vierge extra 50cl", img: null },
  };
  function demoProgress() {
    const s = demoState();
    const status = s.unlockedAt ? 'unlocked' : (s.accumulated >= DEMO_CAMPAIGN.threshold ? 'unlocked' : 'in_progress');
    return {
      campaign: DEMO_CAMPAIGN,
      accumulated: s.accumulated,
      remaining: Math.max(0, DEMO_CAMPAIGN.threshold - s.accumulated),
      status,
      unlocked: !!s.unlockedAt,
      unlockedAt: s.unlockedAt,
      voucherCode: s.voucherCode,
      redeemedAt: s.redeemedAt,
      ordersCounted: s.accumulated > 0 ? 2 : 0,
    };
  }

  const api = {
    endpoint: null,

    async active({ shopId } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/active?shopId=${encodeURIComponent(shopId || '')}`,
            { credentials: 'include', headers: authHeaders() });
          if (r.ok) return await r.json();
        } catch (_) {}
        return [];
      }
      return [];  // go-live : plus de campagne démo « Été gourmand », vide si non câblé.
    },

    async progress(campaignId, { guestEmail } = {}) {
      if (api.endpoint) {
        try {
          const qs = guestEmail ? `?guestEmail=${encodeURIComponent(guestEmail)}` : '';
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(campaignId)}/progress${qs}`,
            { credentials: 'include', headers: authHeaders() });
          if (r.ok) return await r.json();
        } catch (_) {}
        return null;
      }
      return null;  // go-live : pas d'état de démo
    },

    async claim(campaignId, { guestEmail } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(campaignId)}/claim`,
            { method: 'POST', credentials: 'include', headers: jsonHeaders(),
              body: JSON.stringify({ guestEmail: guestEmail || null }) });
          if (r.ok) return await r.json();
        } catch (_) {}
        return null;
      }
      return null;  // go-live : pas de déblocage de démo
    },

    async redeem({ code, shopId, guestEmail } = {}) {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/redeem`,
            { method: 'POST', credentials: 'include', headers: jsonHeaders(),
              body: JSON.stringify({ code: code || '', shopId: shopId || null, guestEmail: guestEmail || null }) });
          if (r.ok) return await r.json();
        } catch (_) {}
        return { valid: false, reason: 'network' };
      }
      return { valid: false, reason: 'unknown_code' };  // go-live : plus de validation de démo
    },
  };

  window.WSPromo = api;
})();
