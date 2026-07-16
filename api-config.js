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
  // ─── API endpoint resolution ─────────────────────────────────────────
  //   • On GitHub Pages (*.github.io) → demo mode (in-memory fixtures).
  //   • Anywhere else (the client's own hosting) → the PHP API on the SAME
  //     origin, under the SAME base path as the app, at "api". Works whether the
  //     site is served at the domain root (→ /api) OR in a subfolder like
  //     /webshop/ (→ /webshop/api). Deploy php-api/ to <web-root>/api.
  //
  //   To force a specific URL (e.g. API on a different host), replace the
  //   line below with:  const BASE_URL = 'https://api.example.com';
  const onGitHubPages = /\.github\.io$/i.test(location.hostname);
  // Base path where the app is served: strip the file part of the pathname
  // ('/webshop/index.html' → '/webshop/', '/' → '/').
  const basePath = location.pathname.replace(/[^/]*$/, '');
  const BASE_URL = onGitHubPages ? null : (location.origin + basePath + 'api');
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
  if (window.WSSlots)        window.WSSlots.endpoint        = BASE_URL + '/slots';
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
