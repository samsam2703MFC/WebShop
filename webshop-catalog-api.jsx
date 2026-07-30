/* =====================================================================
   WSCatalog — catalogue / assortiments / stock
   ---------------------------------------------------------------------
   GO-LIVE — SOURCE UNIQUE : l'API (tables ws_products, ws_categories,
   ws_product_shops, ws_product_prices, prix magasin ERP…). Toute la
   machinerie de seeds mémoire (window._CATALOG_SEED : produits, prix par
   boutique, assortiments, delivery_stock) a été SUPPRIMÉE, ainsi que les
   `catch` qui avalaient une panne serveur pour retomber dessus.

   Règle : soit la donnée réelle arrive, soit on lève une erreur que
   l'écran affiche. Jamais un catalogue inventé, jamais un catalogue vide
   silencieux qui ferait croire à une boutique sans produits.

   Endpoints attendus :
     GET  {endpoint}/products?shopId=&cat=&mode= -> [Product] (prix déjà résolu boutique)
     GET  {endpoint}/products/:id?shopId=        -> Product
     GET  {endpoint}/bundles?productId=          -> [Bundle]
     GET  {endpoint}/assortments?shopId=         -> [Assortment]
     GET  {endpoint}/categories?shopId=          -> [Category]
     GET  {endpoint}/stock?shopId=&date=&mode=   -> [StockEntry]
          StockEntry: { productId, qty_total, qty_reserved, qty_sold, qty_available }
     POST {endpoint}/stock/reserve               -> { ok, reservationId, expiresAt }
     POST {endpoint}/stock/release               -> { ok }
   ===================================================================== */
(function () {

  function requireEndpoint() {
    if (!api.endpoint) throw new Error('API catalogue non configurée.');
    return api.endpoint;
  }

  async function getJson(url, label) {
    const r = await fetch(url, { credentials: 'include' });
    if (!r.ok) throw new Error(label + ' indisponible (HTTP ' + r.status + ').');
    return await r.json();
  }

  const api = {
    endpoint: null,

    async listCategories({ shopId } = {}) {
      const base = requireEndpoint();
      return await getJson(`${base}/categories?shopId=${encodeURIComponent(shopId || '')}`, 'Catégories');
    },

    async listProducts({ shopId, cat, mode } = {}) {
      const base = requireEndpoint();
      // `mode=delivery` → l'API exclut serveur-side les produits non éligibles
      // à la livraison bureau (source unique du filtre, cf. /catalog/products).
      const modeQs = mode ? `&mode=${encodeURIComponent(mode)}` : '';
      return await getJson(
        `${base}/products?shopId=${encodeURIComponent(shopId || '')}&cat=${encodeURIComponent(cat || '')}${modeQs}`,
        'Catalogue'
      );
    },

    async getProduct(id, shopId) {
      const base = requireEndpoint();
      const qs = shopId ? `?shopId=${encodeURIComponent(shopId)}` : '';
      return await getJson(`${base}/products/${encodeURIComponent(id)}${qs}`, 'Produit');
    },

    async listBundles({ productId } = {}) {
      const base = requireEndpoint();
      return await getJson(`${base}/bundles?productId=${encodeURIComponent(productId || '')}`, 'Formules');
    },

    async listAssortments({ shopId } = {}) {
      const base = requireEndpoint();
      return await getJson(`${base}/assortments?shopId=${encodeURIComponent(shopId || '')}`, 'Assortiments');
    },

    // Map productId -> { qty_total, qty_reserved, qty_sold, qty_available }.
    async getStock({ shopId, date, mode } = {}) {
      const base = requireEndpoint();
      const iso = date instanceof Date ? date.toISOString().slice(0, 10) : (date || '');
      const rows = await getJson(
        `${base}/stock?shopId=${encodeURIComponent(shopId || '')}&date=${encodeURIComponent(iso)}&mode=${encodeURIComponent(mode || '')}`,
        'Stock'
      );
      const map = {};
      for (const row of (rows || [])) map[row.productId] = row;
      return map;
    },

    // Réservation de stock (maintien 15 min) pour un client authentifié.
    // Un échec DOIT remonter : sans réservation confirmée, le stock n'est pas
    // tenu et la boutique pourrait survendre.
    async reserve({ productId, shopId, date, mode, qty, customerId } = {}) {
      const base = requireEndpoint();
      const iso = date instanceof Date ? date.toISOString().slice(0, 10) : (date || '');
      const r = await fetch(`${base}/stock/reserve`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ productId, shopId, date: iso, mode, qty, customerId }),
      });
      if (!r.ok) throw new Error('Réservation de stock impossible (HTTP ' + r.status + ').');
      return await r.json();
    },

    // Libération de réservations. Nettoyage non bloquant : un échec est tracé
    // en console (la réservation expire d'elle-même côté serveur) mais ne
    // fabrique aucune donnée.
    // productId : ne relâche que CE produit (retrait d'une ligne du panier).
    // Sans lui, tout le panier du client était libéré d'un coup.
    async release({ customerId, productId, reservationIds } = {}) {
      if (!api.endpoint) return;
      try {
        await fetch(`${api.endpoint}/stock/release`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ customerId, productId: productId || null, reservationIds: reservationIds || null }),
        });
      } catch (e) {
        console.error('[ws] libération des réservations impossible', e);
      }
    },
  };
  window.WSCatalog = api;
})();
