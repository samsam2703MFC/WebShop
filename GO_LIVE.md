# 🚀 GO LIVE — Runbook de déploiement (L'Atelier By)

Architecture : **`ws_` (base Buddy) = maître** · **buddy-server (Node) = API** ·
**React (GitHub Pages) = vitrine** · **WooCommerce = moteur de vente (optionnel)**.

Légende : 🖥️ = sur **ton serveur** · 🗄️ = dans **phpMyAdmin** · ☁️ = **GitHub / Claude**

---

## Phase 1 — Base de données 🗄️

1. phpMyAdmin → base `test-webshop_db` → onglet **Importer** :
   - `backend/schema/ws_schema.sql`  → crée les 33 tables
   - `backend/schema/seed-shops.sql` → tes 5 boutiques (Halle=4, Corbais=2, …)
2. Importer ton catalogue (voir Phase 5 pour l'outil), ou coller `ws-import.sql`.
3. Compléter les données franchise : `ws_product_prices`, `ws_product_stock`,
   `ws_slots`, `ws_calendar_rules`, `ws_shop_availability`, `ws_offices`,
   `ws_tours`, `ws_delivery_fee_rules`, `ws_pricing_rules`, `ws_vouchers`.
   (Requêtes types dans `backend/schema/api-queries.sql`.)

## Phase 2 — Backend (buddy-server) 🖥️

```bash
git clone https://github.com/samsam2703MFC/WebShop.git
cd WebShop/backend
npm install
cp .env.example .env      # puis édite .env :
```
```ini
WEBSHOP_DB_HOST=localhost
WEBSHOP_DB_USER=test_webshop_user
WEBSHOP_DB_PASSWORD=•••          # ton mot de passe DB
WEBSHOP_DB_NAME=test-webshop_db
ADMIN_TOKEN=•••                  # un secret long (endpoints admin)
AUTH_SECRET=•••                  # un secret long (tokens de session)
STRIPE_SECRET_KEY=sk_live_•••    # (Phase 4)
CORS_ORIGINS=https://samsam2703mfc.github.io
```
Lancer et garder en vie :
```bash
npm install -g pm2
pm2 start deploy/ecosystem.config.cjs
pm2 save && pm2 startup           # survit au reboot
curl http://localhost:3002/shops  # doit lister tes 5 boutiques
```

## Phase 3 — Frontend (React) ☁️

Dans `api-config.js` :
```js
const BASE_URL = 'https://ton-serveur:3002';   // ← ton backend (HTTPS conseillé)
```
`git commit` + `git push` → **GitHub Pages déploie tout seul**.
Ouvre `https://samsam2703mfc.github.io/WebShop/` → il lit ta base.

## Phase 4 — Paiement (Stripe) 🖥️

1. Mets `STRIPE_SECRET_KEY` (et `STRIPE_WEBHOOK_SECRET`) dans `.env`, `pm2 restart buddy-api`.
2. Dans Stripe → Webhooks : URL `https://ton-serveur:3002/payments/webhook`, event `checkout.session.completed`.
3. Le paiement s'active automatiquement (sinon l'API renvoie 503, le reste marche).

## Phase 5 — WooCommerce (vitrine + synchro) 🖥️

1. Uploader le plugin : `atelier-webshop-bridge.zip` → WP admin → Extensions → Ajouter → Téléverser → Activer.
2. Secret partagé (les deux côtés identiques) :
   ```bash
   # côté Woo (WP-CLI)
   wp option update atelier_sync_token "MON-SECRET"
   ```
   ```sql
   -- côté Buddy
   UPDATE ws_shops SET sync_token='MON-SECRET', woo_base_url='https://atelierby.online' WHERE id=2;
   ```
3. Importer ton catalogue Woo → base :
   ```bash
   npm run import:csv -- wc-product-export.csv 2      # shop 2 = Corbais
   ```
4. Pousser prix/stock → Woo : `npm run sync:push`
5. Récupérer les commandes ← Woo : `npm run sync:pull`

## Phase 6 — Automatiser 🖥️

```bash
# adapte le chemin absolu dans le fichier, puis :
crontab backend/deploy/crontab.txt
```
→ push/pull toutes les 5 min + libération des réservations expirées chaque minute.

---

## ✅ Test final « de A à Z »

1. Ouvrir le site → choisir une boutique → le catalogue s'affiche (depuis `ws_`)
2. Créer un compte (`/auth/register`) → se connecter
3. Ajouter au panier → commander → payer (Stripe test)
4. Vérifier : `SELECT * FROM ws_orders ORDER BY id DESC LIMIT 1;`

## Aide-mémoire endpoints (buddy-server `:3002`)
```
/shops /brand /catalog/* /catalog/stock
/availability/* /calendar/*
/pricing/promos/* /vouchers/redeem
/tours /offices /delivery-fees/*
/orders            /auth/*            /payments/*
```
