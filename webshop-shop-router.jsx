// webshop-shop-router.jsx — Active-shop routing for the multi-shop storefront.
//
// One storefront serves every shop. The catalogue, cart, checkout and login
// are all served by our own API (php-api/), filtered by shopId. This module is
// the single source of truth for which shop is currently active:
//   • deep-link (?shop=…) wins
//   • else the last remembered shop (localStorage)
//   • else the caller's default
//
// It layers on top of window.WSShops (the shop directory).
(function () {
  const KEY = 'ws.activeShop';

  function deepLinkShop() {
    try {
      const p = new URLSearchParams(window.location.search).get('shop');
      return p && /^[a-z0-9-]+$/.test(p) ? p : null;
    } catch { return null; }
  }

  // Active shop resolution order: direct link wins, else last remembered.
  let activeShopId = deepLinkShop() || (function () {
    try { return window.localStorage.getItem(KEY) || null; } catch { return null; }
  })();

  function current() { return activeShopId; }

  function setActive(id) {
    if (!id) return;
    activeShopId = id;
    try { window.localStorage.setItem(KEY, id); } catch { /* private mode */ }
  }

  window.WSShopRouter = { current, setActive };
})();
