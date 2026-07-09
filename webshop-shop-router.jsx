// webshop-shop-router.jsx — Active-shop routing for the multi-Woo storefront.
//
// Each shop is its own WooCommerce site (WordPress Multisite). The catalogue
// is read from the Buddy API (filtered by shopId), but cart / checkout / login
// live in *that shop's* Woo. This module is the single source of truth for:
//   • which shop is active (deep-link ?shop= → localStorage → default)
//   • which Woo base URL the transactional calls must target
//
// It layers on top of window.WSShops (the shop directory). UI code never
// hardcodes a Woo URL — it asks WSShopRouter.wooBase().
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

  // Resolve the active shop's Woo base URL (for cart/checkout/login).
  // Throws 'shop_not_routable' when the shop has no Woo site yet — callers
  // catch it to keep browsing (catalogue) working and gate only checkout.
  async function wooBase(id) {
    const shopId = id || activeShopId;
    const shop = window.WSShops ? await window.WSShops.get(shopId) : null;
    if (!shop || !shop.woo_base_url) throw new Error('shop_not_routable');
    return String(shop.woo_base_url).replace(/\/+$/, '');
  }

  // True when the active shop can transact (has a Woo site wired).
  async function isRoutable(id) {
    try { await wooBase(id); return true; } catch { return false; }
  }

  window.WSShopRouter = { current, setActive, wooBase, isRoutable };
})();
