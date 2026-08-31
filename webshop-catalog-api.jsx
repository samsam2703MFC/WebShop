/* =====================================================================
   WSCatalog — catalogue / assortiments / stock
   ---------------------------------------------------------------------
   GO-LIVE — SOURCE UNIQUE : l'API (tables ws_products, ws_categories,
   ws_product_shops, prix magasin ERP…). Toute la
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

  /* Réservation et libération portent l'identité du client. L'API l'établit
     désormais à partir du SEUL en-tête Authorization : le repli sur un
     `customerId` transmis dans le corps laissait réserver — ou libérer — du
     stock au nom de n'importe quel client. Sans cet en-tête, ces deux appels
     répondent 401. */
  const authHeaders = () =>
    (window.WSAuth && typeof window.WSAuth.authHeaders === 'function') ? window.WSAuth.authHeaders() : {};

  /* Date -> 'AAAA-MM-JJ' en heure LOCALE. toISOString() convertit en UTC : une
     Date construite à minuit local devient 22:00 la VEILLE en été (UTC+2), et
     la journée demandée au serveur était systématiquement la mauvaise — stock
     du jour précédent affiché en permanence. Même défaut que celui qui rendait
     le dernier jour du mois invisible au franchisé. */
  const pad2 = (n) => String(n).padStart(2, '0');
  const isoLocal = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
  const toIso = (d) => (d instanceof Date ? isoLocal(d) : (d || ''));

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

    async listCategories({ shopId, date } = {}) {
      const base = requireEndpoint();
      // `date` : même filtre saisonnier que listProducts — une catégorie
      // entièrement hors saison ne doit pas rester dans la barre de navigation.
      const dateQs = date ? `&date=${encodeURIComponent(date)}` : '';
      // `lang` : le SERVEUR résout les libellés traduits (alias ERP). Sans
      // alias pour cette langue, il renvoie le libellé source — le front ne
      // traduit rien lui-même et n'a donc jamais de trou à combler.
      const lg = (window.WSI18n && window.WSI18n.getLang && window.WSI18n.getLang()) || '';
      const langQs = lg ? `&lang=${encodeURIComponent(lg)}` : '';
      return await getJson(`${base}/categories?shopId=${encodeURIComponent(shopId || '')}${dateQs}${langQs}`, 'Catégories');
    },

    async listProducts({ shopId, cat, mode, date } = {}) {
      const base = requireEndpoint();
      // `mode=delivery` → l'API exclut serveur-side les produits non éligibles
      // à la livraison bureau (source unique du filtre, cf. /catalog/products).
      const modeQs = mode ? `&mode=${encodeURIComponent(mode)}` : '';
      // `date` → gammes saisonnières évaluées à la date de RETRAIT/LIVRAISON,
      // pas à celle de la commande : on commande le 28 novembre pour le
      // 2 décembre, et la gamme de Noël doit alors être visible.
      const dateQs = date ? `&date=${encodeURIComponent(date)}` : '';
      // `lang` : noms de produits traduits par le SERVEUR (alias ERP). Sans
      // alias dans cette langue, il renvoie le nom source.
      const lg = (window.WSI18n && window.WSI18n.getLang && window.WSI18n.getLang()) || '';
      const langQs = lg ? `&lang=${encodeURIComponent(lg)}` : '';
      return await getJson(
        `${base}/products?shopId=${encodeURIComponent(shopId || '')}&cat=${encodeURIComponent(cat || '')}${modeQs}${dateQs}${langQs}`,
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
      const iso = toIso(date);
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
      const iso = toIso(date);
      const r = await fetch(`${base}/stock/reserve`, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...authHeaders() },
        body: JSON.stringify({ productId, shopId, date: iso, mode, qty, customerId }),
      });
      // Le serveur dit PRECISEMENT ce qui bloque — « Stock insuffisant », avec
      // le disponible restant. Remplacer ce message par « HTTP 409 » privait le
      // client de la seule information utile : combien il peut encore prendre.
      if (!r.ok) {
        const j = await r.json().catch(() => null);
        const msg = (j && typeof j.error === 'string' && j.error) ? j.error : ('Réservation impossible (HTTP ' + r.status + ')');
        const dispo = (j && typeof j.available === 'number')
          ? (j.available > 0 ? ` — il reste ${j.available} pièce${j.available > 1 ? 's' : ''}` : ' — il n\'en reste aucune')
          : '';
        throw new Error(msg + dispo);
      }
      return await r.json();
    },

    // Libération de réservations. Nettoyage non bloquant : un échec est tracé
    // en console (la réservation expire d'elle-même côté serveur) mais ne
    // fabrique aucune donnée.
    // productId : ne relâche que CE produit (retrait d'une ligne du panier).
    // Sans lui, tout le panier du client était libéré d'un coup.
    async release({ customerId, productId, reservationIds } = {}) {
      if (!api.endpoint) { console.error('[stock] libération impossible : endpoint absent'); return null; }
      try {
        const r = await fetch(`${api.endpoint}/stock/release`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...authHeaders() },
          body: JSON.stringify({ customerId, productId: productId || null, reservationIds: reservationIds || null }),
        });
        // Un statut != 2xx n'est PAS une exception : sans ce test, un 404 ou un
        // 401 passait pour un succès et le stock restait gelé jusqu'à
        // expiration, sans la moindre trace. C'est le défaut qui avait déjà été
        // corrigé pour reserve() et oublié ici.
        if (!r.ok) { console.error('[stock] libération refusée — HTTP ' + r.status); return { ok: false, status: r.status }; }
        const j = await r.json().catch(() => null);
        console.info('[stock] réponse libération', j);
        return j;
      } catch (e) {
        console.error('[stock] libération des réservations impossible', e);
        return null;
      }
    },
  };
  window.WSCatalog = api;
})();
