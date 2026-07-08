/* =====================================================================
   api-config.js — Central API endpoint configuration
   =====================================================================
   Set BASE_URL to your backend host to switch all stubs from demo
   fixtures to live HTTP at once. Leave null to keep demo mode.

   Example:
     const BASE_URL = 'https://api.atelier.be';

   Individual overrides are also supported — useful while endpoints are
   being built out one by one (comment out the ones not yet ready).
   ===================================================================== */

(function () {
  // ─── Change this one line to point at your backend ───────────────────
  // Node reference backend:  'https://api.atelier.be'
  // WooCommerce bridge:      'https://shop.atelier.be/wp-json/atelier/v1'
  //   (the Atelier Webshop Bridge plugin exposes the same contracts on
  //    WooCommerce — see woocommerce-bridge/ and WOOCOMMERCE.md)
  const BASE_URL = 'https://atelierby.online/wp-json/atelier/v1';
  // ─────────────────────────────────────────────────────────────────────

  if (!BASE_URL) return; // demo mode — all stubs use in-memory fixtures

  /* Endpoints served by the Atelier Webshop Bridge (WooCommerce) plugin. */
  if (window.WSShops)        window.WSShops.endpoint        = BASE_URL + '/shops';
  if (window.WSCatalog)      window.WSCatalog.endpoint      = BASE_URL + '/catalog';
  if (window.WSPricing)      window.WSPricing.endpoint      = BASE_URL + '/pricing';
  if (window.WSVouchers)     window.WSVouchers.endpoint     = BASE_URL + '/vouchers';
  if (window.WSDeliveryFees) window.WSDeliveryFees.endpoint = BASE_URL + '/delivery-fees';
  if (window.WSOffices)      window.WSOffices.endpoint      = BASE_URL + '/offices';
  if (window.WSTours)        window.WSTours.endpoint        = BASE_URL + '/tours';
  if (window.WSOrders)       window.WSOrders.endpoint       = BASE_URL + '/orders';

  /* Not yet implemented by the bridge → these stay on demo fallback:
     WSAuth, WSCalendar, WSAvailability, WSBrand, WSVies. */

  /* Optional: CSRF token for mutations (set by your auth endpoint) */
  // document.addEventListener('wsauth:login', function (e) {
  //   const token = e.detail && e.detail.csrfToken;
  //   if (token) window._WS_CSRF = token;
  // });
})();
