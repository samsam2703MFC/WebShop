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

   All stubs fall back to in-memory seed data when `endpoint` is null.
   Set WSDeliveryFees.endpoint in api-config.js to activate live HTTP.
   ========================================================================= */

(function () {

  /* ---------------------------------------------------------------------- */
  /* SEED DATA                                                                */
  /* ---------------------------------------------------------------------- */

  /* Delivery sites — one office client can have multiple sites, each with
     its own fee config and payment type.                                     */
  const SEED_SITES = [
    {
      id:                         'site-acme-loi',
      office_client_id:           'off-acme',
      name:                       'ACME Avocats — Rue de la Loi',
      address:                    'Rue de la Loi 120, 1040 Bruxelles',
      floor_room:                 '4e étage, salle Themis',
      contact_name:               'Marie Dubois',
      contact_phone:              '+32 472 11 22 33',
      tournee_id:                 'tour-bxl-mid',
      tournee_stop_id:            'stop-acme-loi',
      shop_id:                    'chatelain',
      /* fee config */
      free_delivery:              false,
      always_charge:              false,
      fee_amount:                 4.50,
      free_delivery_minimum:      40.00,
      /* payment */
      payment_type:               'deferred',
      /* status */
      active:                     true,
    },
    {
      id:                         'site-acme-arts',
      office_client_id:           'off-acme',
      name:                       'ACME Avocats — Place des Arts',
      address:                    'Place des Arts 7, 1210 Saint-Josse',
      floor_room:                 'Réception',
      contact_name:               'Pierre Fontaine',
      contact_phone:              '+32 472 33 44 55',
      tournee_id:                 'tour-bxl-am',
      tournee_stop_id:            'stop-acme-arts',
      shop_id:                    'sablon',
      free_delivery:              true,
      always_charge:              false,
      fee_amount:                 0,
      free_delivery_minimum:      0,
      payment_type:               'immediate',
      active:                     true,
    },
  ];

  /* Office-level fallback rules (used when no site-specific rule matches).  */
  const SEED_OFFICE_RULES = [
    {
      id:           'rule-off-acme',
      office_client_id: 'off-acme',
      free_delivery: false,
      always_charge: false,
      fee_amount:    5.00,
      free_delivery_minimum: 50.00,
      payment_type: 'deferred',
    },
  ];

  /* Tournée-level fallback rules.                                            */
  const SEED_TOUR_RULES = [
    {
      id:         'rule-tour-bxl-mid',
      tournee_id: 'tour-bxl-mid',
      free_delivery: false,
      always_charge: false,
      fee_amount:    5.00,
      free_delivery_minimum: 45.00,
    },
    {
      id:         'rule-tour-bxl-am',
      tournee_id: 'tour-bxl-am',
      free_delivery: false,
      always_charge: false,
      fee_amount:    6.00,
      free_delivery_minimum: 50.00,
    },
    {
      id:         'rule-tour-lg',
      tournee_id: 'tour-lg',
      free_delivery: false,
      always_charge: false,
      fee_amount:    7.00,
      free_delivery_minimum: 55.00,
    },
  ];

  /* Per-shop fallback rules.                                                 */
  const SEED_SHOP_RULES = [
    { id: 'rule-shop-chatelain', shop_id: 'chatelain', free_delivery: false, always_charge: false, fee_amount: 6.00, free_delivery_minimum: 50.00 },
    { id: 'rule-shop-sablon',    shop_id: 'sablon',    free_delivery: false, always_charge: false, fee_amount: 6.00, free_delivery_minimum: 50.00 },
    { id: 'rule-shop-carre',     shop_id: 'carre',     free_delivery: false, always_charge: false, fee_amount: 6.50, free_delivery_minimum: 55.00 },
  ];

  /* Global fallback rule.                                                    */
  const SEED_GLOBAL_RULE = {
    free_delivery: false,
    always_charge: false,
    fee_amount:    7.00,
    free_delivery_minimum: 50.00,
  };

  /* ---------------------------------------------------------------------- */
  /* HELPERS                                                                  */
  /* ---------------------------------------------------------------------- */

  function computeFee(rule, subtotal) {
    if (!rule || rule.free_delivery) return 0;
    if (rule.always_charge) return rule.fee_amount || 0;
    if (subtotal >= (rule.free_delivery_minimum || 0)) return 0;
    return rule.fee_amount || 0;
  }

  /* Resolve the applicable rule for a given context, seed-mode.             */
  function resolveRuleSeed({ siteId, officeClientId, tourneeId, shopId }) {
    if (siteId) {
      const site = SEED_SITES.find((s) => s.id === siteId && s.active);
      if (site) return { rule: site, level: 'site', site };
    }
    if (officeClientId) {
      const r = SEED_OFFICE_RULES.find((r) => r.office_client_id === officeClientId);
      if (r) return { rule: r, level: 'office', site: null };
    }
    if (tourneeId) {
      const r = SEED_TOUR_RULES.find((r) => r.tournee_id === tourneeId);
      if (r) return { rule: r, level: 'tour', site: null };
    }
    if (shopId) {
      const r = SEED_SHOP_RULES.find((r) => r.shop_id === shopId);
      if (r) return { rule: r, level: 'shop', site: null };
    }
    return { rule: SEED_GLOBAL_RULE, level: 'global', site: null };
  }

  /* Build the full resolution result returned by quote() / resolve().       */
  function buildResult({ resolved, subtotal }) {
    const { rule, level, site } = resolved;
    const fee = computeFee(rule, subtotal);
    const free_minimum = (!rule.free_delivery && !rule.always_charge) ? (rule.free_delivery_minimum || 0) : 0;
    return {
      fee_amount:                  fee,
      free_delivery:               rule.free_delivery || false,
      always_charge:               rule.always_charge || false,
      free_delivery_minimum:       free_minimum,
      amount_remaining_for_free:   fee > 0 && free_minimum > 0 ? Math.max(0, free_minimum - subtotal) : 0,
      payment_type:                rule.payment_type || 'immediate',
      resolved_level:              level,
      site:                        site || null,
    };
  }

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
      return SEED_SITES.filter((s) => s.office_client_id === officeClientId && s.active);
    },

    /**
     * getSite({ siteId })
     * Returns one delivery site by ID.
     */
    async getSite({ siteId }) {
      if (this.endpoint) return apiFetch('/sites/' + siteId, {});
      return SEED_SITES.find((s) => s.id === siteId) || null;
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
      const resolved = resolveRuleSeed({ siteId, officeClientId, tourneeId, shopId });
      return buildResult({ resolved, subtotal });
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
        const resolved = resolveRuleSeed({ siteId, officeClientId, tourneeId, shopId });
        paymentType = resolved.rule.payment_type || 'immediate';
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
