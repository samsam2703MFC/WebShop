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
  const BASE_URL = null; // e.g. 'https://api.atelier.be'
  // ─────────────────────────────────────────────────────────────────────

  if (!BASE_URL) return; // demo mode — all stubs use in-memory fixtures

  /* Storefront data */
  if (window.WSShops)    window.WSShops.endpoint    = BASE_URL + '/shops';
  if (window.WSCatalog)  window.WSCatalog.endpoint  = BASE_URL + '/catalog';
  if (window.WSCalendar)      window.WSCalendar.endpoint      = BASE_URL + '/calendar';
  if (window.WSAvailability)    window.WSAvailability.endpoint    = BASE_URL + '/availability';
  if (window.WSDeliveryFees)    window.WSDeliveryFees.endpoint    = BASE_URL + '/delivery-fees';
  if (window.WSOffices)       window.WSOffices.endpoint       = BASE_URL + '/offices';
  if (window.WSBrand)    window.WSBrand.endpoint    = BASE_URL + '/brand';

  /* Pricing, promos, payment methods */
  if (window.WSPricing)  window.WSPricing.endpoint  = BASE_URL + '/pricing';

  /* Vouchers */
  if (window.WSVouchers) window.WSVouchers.endpoint = BASE_URL + '/vouchers';

  /* Auth / users */
  if (window.WSAuth)     window.WSAuth.endpoint     = BASE_URL + '/auth';

  /* Delivery tours */
  if (window.WSTours)    window.WSTours.endpoint    = BASE_URL + '/tours';

  /* Orders / checkout */
  if (window.WSOrders)   window.WSOrders.endpoint   = BASE_URL + '/orders';

  /* VAT / VIES proxy */
  if (window.WSVies)     window.WSVies.endpoint     = BASE_URL + '/vies';

  /* Optional: CSRF token for mutations (set by your auth endpoint) */
  // document.addEventListener('wsauth:login', function (e) {
  //   const token = e.detail && e.detail.csrfToken;
  //   if (token) window._WS_CSRF = token;
  // });
})();
