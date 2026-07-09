# API PHP — L'Atelier By (pour hébergement mutualisé)

Même API que le `buddy-server` Node, en **PHP pur** (PDO + MySQL local). Tourne sur
n'importe quel hébergement PHP 8+ avec la base `ws_` en local. **Aucun Node, aucun VPS.**

## Déploiement (3 minutes)

1. **Uploade** le dossier `php-api/` sur ton hébergement (FTP / gestionnaire de fichiers),
   par ex. dans `public_html/api/`.
2. **Édite `config.php`** avec tes identifiants MySQL (host `localhost`, ta base, user, pass)
   et un `auth_secret` long et aléatoire.
3. C'est prêt : `https://ton-domaine/api/shops` doit lister tes boutiques.

> Prérequis : le schéma `ws_` déjà importé (`backend/schema/ws_schema.sql`) + tes boutiques
> (`backend/schema/seed-shops.sql`), via phpMyAdmin.

## Brancher le frontend

Dans `api-config.js` : `const BASE_URL = 'https://ton-domaine/api';`

## Endpoints (identiques au Node)

```
GET  /shops                         GET  /brand?shopId=
GET  /catalog/categories?shopId=    GET  /catalog/products?shopId=
GET  /catalog/stock?shopId=&mode=
GET  /availability/settings|days    GET  /calendar/slots|cutoff|exceptions
GET  /pricing/promos/cross-portion  POST /vouchers/redeem
GET  /tours  /offices  /offices/:id POST /delivery-fees/quote
POST /orders                        GET  /orders/:id
POST /auth/register|login   GET/PATCH /auth/me
POST /payments/checkout   (Stripe via cURL — colle sk_… dans config.php)
```

## Notes

- **Mots de passe** : `password_hash`/`password_verify` natifs PHP (bcrypt) — compatibles
  avec les hash `$2y$` de `ws_customers`.
- **Sessions** : jeton signé HMAC (`Authorization: Bearer …`), sans table de session.
- **Paiement** : appel direct à l'API Stripe en cURL (pas de composer/SDK). Sans clé → 503,
  le reste de l'API fonctionne.
- **Sécurité** : `config.php` est protégé par `.htaccess` ; garde tes secrets hors du repo.
