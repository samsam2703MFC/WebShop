/* =====================================================================
   WSOrders : order placement API stub
   ---------------------------------------------------------------------
   The checkout wizard calls WSOrders.place(payload) to confirm an order.
   To wire a real backend, set:
     window.WSOrders.endpoint = 'https://your-host/orders';
   Endpoints expected:
     POST {endpoint}                     -> { orderId, status, total, paymentUrl? }
     POST {payEndpoint} {orderId}        -> { ok, checkoutUrl }   (paiement hébergé)
     GET  {endpoint}/:id                 -> Order
     GET  {endpoint}/me                  -> Order[]  (current customer)
     POST {endpoint}/:id/cancel          -> { ok }

   Request body for POST {endpoint}:
   {
     shopId, mode,
     slot:     { date, slotId, label },
     basket:   [{ productId, qty, portion?, options?, bundleId?, bundleSlots? }],
     voucher:  "CODE" | null,
     customer: { id?, email, firstName, lastName, phone, officeId? },
     payment:  { method },
     delivery: { officeId?, tourId?, address? }   // delivery mode only
   }
   ===================================================================== */
(function () {
  /* Jeton porteur, source unique du module. Seul `place` le transmettait ; get,
     listMine et cancel appelaient des routes qui exigent d'être le PROPRIÉTAIRE
     connecté (GET /orders/:id le vérifie explicitement). Sans en-tête, elles
     répondent 401 : silencieusement converti en `null` ou `[]`, c'est-à-dire en
     « vous n'avez aucune commande ». */
  const authHeaders = () => {
    if (window.WSAuth && typeof window.WSAuth.authHeaders === 'function') return window.WSAuth.authHeaders();
    try { const t = localStorage.getItem('ws_auth_token'); return t ? { 'Authorization': 'Bearer ' + t } : {}; }
    catch (_) { return {}; }
  };

  const api = {
    endpoint: null,
    payEndpoint: null,   // POST /payments/checkout, session de paiement hébergée

    /* Place an order. Throws on network / server error. */
    async place(payload) {
      if (api.endpoint) {
        const r = await fetch(api.endpoint, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify(payload),
        });
        const j = await r.json().catch(() => ({}));
        if (r.ok) return { ok: true, ...j };
        // L'API renvoie {error:"…", detail:"…"}, on AFFICHE la cause réelle
        // (avant : « Erreur 500 » générique alors que le motif était dans la réponse).
        const base = (typeof j.error === 'string' && j.error) ? j.error : (j.error?.message || `Erreur ${r.status}`);
        // « Stock insuffisant » sans nom de produit est inutilisable sur un
        // panier de dix lignes : le client ne sait pas quoi retirer. Le serveur
        // renvoie product et available, on les affiche.
        const quoi = j.product ? ` : « ${j.product} »` : '';
        const reste = (typeof j.available === 'number')
          ? (j.available > 0 ? ` (il en reste ${j.available})` : ' (il n\'en reste aucune)')
          : '';
        throw new Error(base + quoi + reste + (j.detail ? (', ' + j.detail) : ''));
      }
      // Go-live : plus de simulation de commande. Sans API configurée, on
      // refuse : une commande ne peut jamais « réussir » à blanc.
      throw new Error('API commandes indisponible : commande non enregistrée.');
    },

    /* Démarre le PAIEMENT HÉBERGÉ (Stripe Checkout) d'une commande DÉJÀ
       enregistrée par place(). Rend { ok, orderId, checkoutUrl } ; jette avec
       le motif serveur sinon (503 Stripe non configuré, 502 échec Stripe).
       Étape séparée de place() : l'enregistrement et l'encaissement sont deux
       routes serveur distinctes, et seul le webhook Stripe marque « payé ». */
    async pay(orderId) {
      if (!orderId) throw new Error('orderId requis');
      if (!api.payEndpoint) throw new Error('API paiement indisponible.');
      const r = await fetch(api.payEndpoint, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...authHeaders() },
        body: JSON.stringify({ orderId }),
      });
      const j = await r.json().catch(() => ({}));
      if (r.ok && j && j.checkoutUrl) return j;
      throw new Error((typeof j.error === 'string' && j.error) || `Erreur ${r.status}`);
    },

    /* Fetch a single order (e.g. for the confirmation page). */
    async get(id) {
      if (!id) return null;
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}`, { credentials: 'include', headers: authHeaders() });
          if (r.ok) return await r.json();
          console.error('[commandes] lecture refusée, HTTP ' + r.status);
        } catch (e) { console.error('[commandes] lecture injoignable', e); }
      }
      return null;
    },

    /* List the current customer's orders. */
    async listMine() {
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/me`, { credentials: 'include', headers: authHeaders() });
          if (r.ok) return await r.json();
          // « Aucune commande » et « le serveur a refusé » ne doivent pas se
          // ressembler : les deux affichent une liste vide.
          console.error('[commandes] liste indisponible, HTTP ' + r.status);
        } catch (e) { console.error('[commandes] liste injoignable', e); }
      }
      return [];
    },

    /* Cancel an order (server enforces cutoff rules). */
    async cancel(id) {
      if (!id) return { ok: false };
      if (api.endpoint) {
        try {
          const r = await fetch(`${api.endpoint}/${encodeURIComponent(id)}/cancel`, {
            method: 'POST', credentials: 'include', headers: authHeaders(),
          });
          const j = await r.json().catch(() => ({}));
          return { ok: r.ok, ...j };
        } catch (_) {}
      }
      return { ok: false, error: 'API commandes indisponible' }; // Go-live : jamais de faux succès
    },
  };

  window.WSOrders = api;
})();
