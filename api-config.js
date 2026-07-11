/* =====================================================================
   api-config.js — Central API endpoint configuration
   =====================================================================
   Set BASE_URL to the PHP API host to switch all stubs from demo
   fixtures to live HTTP at once. Leave null to keep demo mode.

   Example (once php-api/ is deployed on the server):
     const BASE_URL = 'https://atelierby.online/api';

   Individual overrides are also supported — useful while endpoints are
   being built out one by one (comment out the ones not yet ready).
   ===================================================================== */

(function () {
  // ─── Change this one line to point at the PHP API (php-api/) ─────────
  //   e.g. 'https://atelierby.online/api'  — see DEPLOY.md, step 3.
  //   Leave null to stay in demo mode (in-memory fixtures).
  const BASE_URL = null;
  // ─────────────────────────────────────────────────────────────────────

  if (!BASE_URL) return; // demo mode — all stubs use in-memory fixtures

  /* Endpoints served by the PHP API (php-api/index.php). */
  if (window.WSShops)        window.WSShops.endpoint        = BASE_URL + '/shops';
  if (window.WSCatalog)      window.WSCatalog.endpoint      = BASE_URL + '/catalog';
  if (window.WSPricing)      window.WSPricing.endpoint      = BASE_URL + '/pricing';
  if (window.WSVouchers)     window.WSVouchers.endpoint     = BASE_URL + '/vouchers';
  if (window.WSDeliveryFees) window.WSDeliveryFees.endpoint = BASE_URL + '/delivery-fees';
  if (window.WSOffices)      window.WSOffices.endpoint      = BASE_URL + '/offices';
  if (window.WSTours)        window.WSTours.endpoint        = BASE_URL + '/tours';
  if (window.WSOrders)       window.WSOrders.endpoint       = BASE_URL + '/orders';
  if (window.WSCompanies)    window.WSCompanies.setEndpoint(BASE_URL + '/companies');
  if (window.WSPayments)     window.WSPayments.endpoint     = BASE_URL + '/payment-methods';
  if (window.WSAuth)         window.WSAuth.endpoint         = BASE_URL + '/auth';
  if (window.WSAvailability) window.WSAvailability.endpoint = BASE_URL + '/availability';
  if (window.WSCalendar)     window.WSCalendar.endpoint     = BASE_URL + '/calendar';
  if (window.WSBrand)        window.WSBrand.endpoint        = BASE_URL + '/brand';
  /* VIES: template endpoint — the stub fills {country}/{vat}. */
  if (window.WSVies)         window.WSVies.endpoint         = BASE_URL + '/vies/{country}/{vat}';

  /* Optional: CSRF token for mutations (set by your auth endpoint) */
  // document.addEventListener('wsauth:login', function (e) {
  //   const token = e.detail && e.detail.csrfToken;
  //   if (token) window._WS_CSRF = token;
  // });
})();
