# Back-offices Franchise Buddy — deux sessions isolées

Câblage de **deux back-offices** sur l'API PHP (`php-api`) avec des **sessions
totalement étanches** :

- **Franchisé** (`/bo/franchisee/*`) — provider `bo_users` role `franchise`, borné à ses boutiques (`bo_user_shops`).
- **Franchiseur** (`/bo/franchisor/*`) — provider `bo_users` role `siege`, portée réseau.

C'est l'équivalent, sur ce stack, des deux *guards* Laravel demandés
(`franchisee` / `franchisor`) — sans Laravel, avec les primitifs réels de l'API.

## Isolation — comment la fuite est rendue impossible

| Barrière | Effet |
|---|---|
| **Secret HMAC distinct par BO** | un jeton signé côté franchisé a une signature invalide côté franchiseur |
| **Scope `bo` dans le payload** | re-vérifié à chaque requête : `bo` ≠ attendu → rejet |
| **Cookies de noms distincts** | `fb_franchisee_session` vs `fb_franchisor_session` — jamais partagés |
| **Guard sur CHAQUE route** | `require_bo('franchisee'|'franchisor')` en tête, aucune route protégée sans lui |
| **Rôle re-vérifié en base** (`/me`) | compte désactivé / rôle changé ⇒ session invalidée |
| **CSRF double-submit** | header `X-CSRF-Token` exigé sur toute méthode non-GET (+ `SameSite=Lax`) |
| **Cookie HttpOnly + Secure** | pas d'accès JS, HTTPS obligatoire en prod |

Un jeton franchisé présenté au BO franchiseur échoue donc **deux fois** (signature
+ scope), et réciproquement. Un logout d'un BO n'efface que **son** cookie.

## Endpoints

```
POST /bo/<role>/login      {email,password}     → {user, csrf}  + cookie de session
POST /bo/<role>/logout     (cookie + X-CSRF-Token)              → efface le cookie du BO
GET  /bo/<role>/me         (cookie)             → {user, csrf}  (rôle revérifié)
POST /bo/<role>/password   {current,new}        → change son mot de passe
GET  /bo/<role>/shops      (cookie)             → boutiques (réseau ou périmètre franchisé)
GET  /bo/<role>/orders     ?date=&shopId=       → commandes (franchisé : bornées au périmètre)
GET  /bo/<role>/scope      (cookie)             → {bo, role, shops}   (shops=null ⇒ réseau)
```
`<role>` ∈ `franchisee` | `franchisor`. Tout autre segment → 404.

## Mise en service

1. **Schéma** : `bo_users` / `bo_user_shops` / `bo_audit` (déjà dans
   `backend/schema/ws_schema.sql`, ou via `backend/schema/alter-bo-brand-comms.sql`).
2. **Config** : copier la section `bo` de `config.example.php` dans `config.php`
   et y mettre **deux secrets longs et différents** (`openssl rand -hex 32`).
   En prod : `cookie_secure => true` (HTTPS).
3. **Comptes** : `bo/seed-bo-users.example.sql` (démo, mdp `Test1234!`) — remplace
   par tes comptes ; hash via `php -r 'echo password_hash("…",PASSWORD_BCRYPT);'`.

## Tester

**Crypto (sans base)** — prouve le rejet croisé des jetons :
```bash
php bo/test-crypto.php
```

**Bout-en-bout (deux sessions en parallèle)** — login des deux BO, vérifie le
401 croisé et que le logout d'un côté ne casse pas l'autre :
```bash
# 1) config.php : 'cookie_secure' => false (test http) + section 'bo'
# 2) démarrer :   php -S 127.0.0.1:8080 index.php     (depuis php-api/)
# 3) seeder les 2 comptes de démo
BASE=http://127.0.0.1:8080 ./bo/test-isolation.sh
```

**Deux onglets (manuel)** : onglet A → `/bo/franchisee/login` ; onglet B →
`/bo/franchisor/login`. Chaque onglet garde SA session (cookies distincts) ;
se déconnecter dans A ne touche pas B ; réutiliser le cookie de A sur une route
`/bo/franchisor/*` renvoie 401.

## Front-ends

Les deux SPA `back_office_ws_franchisee` / `back_office_ws_franchisor`
consomment ces endpoints (fetch avec `credentials: 'include'` pour les cookies,
et header `X-CSRF-Token` renvoyé au login sur les mutations). Sur un 401, chaque
SPA redirige vers **son** `login_url`.

## Compatibilité

Additif : tant qu'aucune requête `/bo/…` n'arrive, l'API existante et l'ancien
back-office `admin_token` (`/admin/*`) sont inchangés.
