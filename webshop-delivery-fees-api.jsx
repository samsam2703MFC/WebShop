/* =========================================================================
   webshop-delivery-fees-api.jsx — window.WSDeliveryFees
   =========================================================================
   Resolves the delivery fee that applies to a B2B office delivery order.

   Fee priority (highest to lowest):
     1. Delivery site rule  (specific to one delivery address)
     2. Office client rule  (fallback for any site of this office)
     3. Tournée rule        (fallback for all offices on a tour)
     4. Shop rule           (fallback for all deliveries from this shop)
     5. Global rule         (default for the whole platform)

   Fee modes
   ---------
   always_charge: true  → fee always applies, even above free_minimum
   always_charge: false → fee is 0 once subtotal >= free_minimum
   free_delivery: true  → no fee regardless of subtotal

   Payment type
   ------------
   payment_type: 'deferred'  → site pays on invoice; only "Paiement différé"
                               is presented as payment method in checkout
   payment_type: 'immediate' → normal payment methods (Bancontact, Visa, …)

   Go-live : plus de seed en memoire - endpoint obligatoire (api-config.js),
   sinon erreur explicite.
   ========================================================================= */

(function () {

  /* ----------------------------------------------------------------------
     Go-live : toutes les donnees de demo (sites ACME, regles de tournee,
     regle globale seed) ont ete purgees. Sans endpoint configure, les
     appels echouent explicitement - jamais de frais fictifs.
     ---------------------------------------------------------------------- */
  function noApi() { throw new Error('API frais de livraison indisponible.'); }

  /* ---------------------------------------------------------------------- */
  /* HTTP HELPERS                                                             */
  /* ---------------------------------------------------------------------- */

  function apiUrl(path) { return (window.WSDeliveryFees.endpoint || '').replace(/\/$/, '') + path; }

  async function apiFetch(path, body) {
    const resp = await fetch(apiUrl(path), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(body),
    });
    if (!resp.ok) throw new Error('WSDeliveryFees: HTTP ' + resp.status);
    return resp.json();
  }

  /* ---------------------------------------------------------------------- */
  /* PUBLIC API                                                               */
  /* ---------------------------------------------------------------------- */

  window.WSDeliveryFees = {

    endpoint: null, // set to BASE_URL + '/delivery-fees' in api-config.js

    /**
     * listSites({ officeClientId })
     * Returns all active delivery sites for one office client.
     */
    async listSites({ officeClientId }) {
      if (this.endpoint) return apiFetch('/sites', { officeClientId });
      noApi();
    },

    /**
     * getSite({ siteId })
     * Returns one delivery site by ID.
     */
    async getSite({ siteId }) {
      if (this.endpoint) return apiFetch('/sites/' + siteId, {});
      noApi();
    },

    /**
     * quote({ siteId, officeClientId, tourneeId, shopId, subtotal })
     * Resolves the applicable delivery fee for a given context and subtotal.
     *
     * Returns:
     *   fee_amount                number   — fee to charge (0 if free)
     *   free_delivery             boolean
     *   always_charge             boolean
     *   free_delivery_minimum     number   — threshold for free delivery
     *   amount_remaining_for_free number   — subtotal still needed to unlock free delivery
     *   payment_type              string   — 'immediate' | 'deferred'
     *   resolved_level            string   — 'site' | 'office' | 'tour' | 'shop' | 'global'
     *   site                      object|null — site record (when resolved at site level)
     */
    async quote({ siteId, officeClientId, tourneeId, shopId, subtotal }) {
      if (this.endpoint) return apiFetch('/quote', { siteId, officeClientId, tourneeId, shopId, subtotal });
      noApi();
    },

    /**
     * listPaymentMethodsForSite({ siteId, officeClientId, tourneeId, shopId, defaultMethods })
     * Returns the payment methods available for a delivery context.
     * If payment_type is 'deferred', returns only the "Paiement différé" method.
     */
    async listPaymentMethodsForSite({ siteId, officeClientId, tourneeId, shopId, defaultMethods }) {
      let paymentType = 'immediate';
      if (this.endpoint) {
        const q = await apiFetch('/quote', { siteId, officeClientId, tourneeId, shopId, subtotal: 0 });
        paymentType = q.payment_type || 'immediate';
      } else {
        noApi();
      }

      if (paymentType === 'deferred') {
        return [{ id: 'deferred', label: 'Paiement différé', sub: 'Facturation mensuelle · paiement sur facture' }];
      }
      return defaultMethods || [
        { id: 'bancontact', label: 'Bancontact',     sub: 'Paiement instantané' },
        { id: 'visa',       label: 'Carte bancaire', sub: 'Visa · Mastercard · Amex' },
        { id: 'apple',      label: 'Apple Pay',      sub: 'Touch ID / Face ID' },
      ];
    },
  };

})();
