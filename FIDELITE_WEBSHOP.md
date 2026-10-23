# Fidélité dans le webshop — récapitulatif et intégration

Ce document rassemble **tout ce qui existe sur l'app de fidélité**, ce qui a été
**intégré dans le webshop** (sa version PWA installée sur téléphone comme sa
version desktop), et ce qui reste dans l'app. Le panier caisse (« scan & go »)
a son propre contrat : [`SCAN_CAISSE.md`](SCAN_CAISSE.md).

---

## 1. Ce qui existait

### L'app de fidélité — dépôt `samsam2703MFC/latelier-by-pwa`

PWA vanilla JS + API PHP (`public/api/`), même base MySQL que le webshop. Le
client est **le même** des deux côtés : `pwa_clients` et `ws_customers` ont été
repliées dans `client` (docs/IDENTITY.md), toutes les tables `pwa_*.client_id`
pointent sur `client.id`. L'app passe déjà par l'API du webshop pour
l'identité (login, inscription, OTP, profil, `handoff` SSO vers le webshop).

Écrans de l'app :

| Écran | Contenu |
|-------|---------|
| **Fidélité** | tuile « offre du moment » (`lp_hero_slides`), bouton **QR d'identification** (le `client_code`, à présenter en caisse — le NFC a été abandonné), **mes points** (jauge vers la prochaine récompense, coefficient du jour, règles), **cartes produit à tampons** |
| **Mes récompenses** | catalogue de récompenses à échanger contre des points (2 taps), récompenses obtenues avec leur QR (`BON-…`, `PRD-…`, `ABO-…`) |
| **Niveaux** (icône croissant) | 5 niveaux selon la fréquence de visite, visites restantes pour le suivant, bundles débloqués par niveau |
| **Abonnements** | wallet en carrousel (Café Club…), réservation sur abonnement |
| **Café** | menu composer, commande sur place, panier café |
| **Commandes / Achats** | statut des commandes en boutique, tickets |
| **Compte** | profil, bureau, facturation (VIES), langue, préférence d'alerte (buzz/SMS) |
| **Fève d'Or** | le jeu d'atelierby.be |

Données fidélité (toutes **table-driven**, docs/LOYALTY.md) :

| Table | Rôle |
|-------|------|
| `pwa_client_loyalty` | solde de points, niveau, visites (écrit par l'ERP/caisse) |
| `pwa_loyalty_tiers`, `pwa_loyalty_promotion` | niveaux et bundles débloqués par niveau |
| `pwa_loyalty_config` | 1 point par 10 € (paramétrable), jauge points → bon |
| `pwa_loyalty_multiplier` | Happy Hour, week-end ×2, +1 le matin… (points seulement) |
| `pwa_loyalty_card` / `_stamp` / `_reward` | cartes à tampons par famille (10 pains = 1 offert), carnet rejoué depuis les tickets, récompense à code |
| `pwa_loyalty_reward_catalog` / `pwa_loyalty_voucher` | catalogue au choix (migration 028) et récompenses obtenues (027) |

### Dans le webshop, avant cette intégration

- Onglet **Fidélité** de Mon compte : une coquille « bientôt » (`FideliteShell`),
  masquable par `ws_param.fidelity_tab_enabled`.
- Ligne **« Application fidélité »** du profil : interrupteur + QR vers l'app
  (`user.fidelityApp`, `ws_param.pwa_url`) — l'activation se fait dans l'app.
- Bandeau « Tout est dans votre application » quand l'app est liée.
- Console marque : paramétrage des cartes à tampons et de la jauge
  (`/franchisor/loyalty-cards`, `/franchisor/loyalty-card`, `/franchisor/loyalty-config`).
- SSO app → webshop (`/auth/handoff-create`, `?handoff=`), annuaire des boutiques
  et « offre du moment » pour l'app (`/pwa/…`).
- Page de test `public/pos-test.html` : mode « En boutique » (bleu) avec un QR
  contenant la liste du panier — maquette, aucune caisse ne le lisait.

---

## 2. Ce qui est maintenant dans le webshop

### Serveur — `php-api/loyalty.php`

Portage **ligne à ligne** des fonctions de l'app (`loyalty_*` / `repo_loyalty_*` →
`loy_*`) : un client lit **les mêmes chiffres** dans l'app et dans le webshop. La
synchronisation du carnet est idempotente (clé unique `source/source_ref`) : l'app
et le webshop peuvent la jouer l'un après l'autre sans doublon ; un `FOR UPDATE`
évite qu'une série complète soit transformée deux fois en récompense si les deux
synchronisent au même instant.

| Route | Rôle |
|-------|------|
| `GET /auth/loyalty?shopId=` | carte d'identification (`member` : code client, niveau, visites restantes), points, coefficients du jour, cartes à tampons, catalogue, récompenses, `appUrl`. `{enabled:false}` si le schéma de l'app est absent. |
| `POST /auth/loyalty/claim` `{catalogId, shopId}` | échange de points (déduction conditionnelle, jamais de solde négatif) ; rend la charge à jour. |

Les images des cartes et du catalogue sont des chemins de l'app : le serveur les
rend absolues sur `ws_param.pwa_url`.

### Front — une source d'état, trois portes d'entrée

`useLoyalty(user, shopId)` (ShopFrame) charge `GET /auth/loyalty` à la connexion, au
changement de boutique, à l'ouverture du panneau et au retour sur l'app (onglet
redevenu visible : les points d'un passage en caisse arrivent avec le ticket).

| Où | Téléphone / PWA installée | Desktop |
|----|---------------------------|---------|
| **Accès** | **3e bouton de la barre d'onglets** : *Panier · ★ 128 pts · Profil* | **puce « ★ 128 pts »** dans l'en-tête, à côté du compte, avec une pastille verte quand une récompense attend d'être retirée |
| **Panneau « Ma fidélité »** | feuille plein écran, une colonne : la carte QR en premier | tiroir à droite (920 px), **deux colonnes** : à gauche la carte QR + les points (collants pendant le défilement), à droite les cartes à tampons (tampons sur une ligne), le catalogue et les récompenses (grille de 2) |
| **Mon compte → Fidélité** | même écran, une colonne (remplace la coquille « bientôt ») | idem |
| **Raccourci d'app** | appui long sur l'icône de l'app installée → « Ma carte de fidélité » (`?open=loyalty`) | lien direct `?open=loyalty` |

Contenu de l'écran (`LoyaltyView`) : carte d'identification avec **QR du code client**
et progression vers le niveau suivant · mes points + coefficient du jour + jauge +
règles dépliables · cartes produit à tampons (dépliées d'office quand une récompense
est prête, bouton « Retirer en boutique » → QR de la récompense) · « Échanger mes
points » (Échanger → Confirmer) · mes récompenses (touchées → QR à scanner) · lien
vers l'app pour le reste.

### Ce qui reste dans l'app (lien « Abonnements, café, avis : ouvrir l'app »)

Abonnements et réservations, commande café, avis, Fève d'Or, préférences d'alerte.
Ces écrans ont leurs propres tables et flux ; les porter est un chantier à part.

### Panier caisse (« scan & go »)

En mode **Retrait**, pour **aujourd'hui**, le panier propose **« Je suis en boutique ·
payer à la caisse »** : un QR que la caisse scanne, le panier tombe dans la caisse,
qui passe en auto-paiement ; le téléphone suit le statut et se vide une fois payé.
Si le client est connecté, la caisse reçoit son **code client** : pas besoin de
scanner en plus la carte de fidélité. Détails : [`SCAN_CAISSE.md`](SCAN_CAISSE.md).
La maquette `public/pos-test.html` reste en place ; le parcours réel est celui-ci, et
la caisse d'essai est `public/caisse-test.html`.

---

## 3. Mise en service

1. **Migrations** (automatiques au déploiement) : `0134_instore_basket.sql` (tables du
   panier caisse), `0135_i18n_fidelite_caisse.sql` (libellés FR/NL).
2. **Schéma de l'app fidélité** en base partagée (migrations 025/027/028 du dépôt
   `latelier-by-pwa`). Sans lui, l'écran garde « bientôt » — rien n'est inventé.
3. `ws_param.pwa_url` = adresse publique de l'app (images des cartes, lien « ouvrir l'app »).
4. Interrupteurs : `ws_param.fidelity_tab_enabled` (onglet Fidélité, existant),
   `instore_basket_enabled` (`'0'` coupe le panier caisse), `instore_basket_ttl_min`
   (validité du code, 120 min).
5. Caisse : un **jeton d'appareil** par boutique (`ws_shop_device_token`, déjà généré
   pour Kitchen) et l'intégration chez l'éditeur de caisse (SCAN_CAISSE.md §7).

## 4. Choix faits, à valider

- **« Auto-paiement »** est compris comme : la caisse, dès le scan, envoie le montant au
  terminal / monnayeur et le client règle seul. Le webshop n'encaisse pas. Si l'idée
  était plutôt de **payer dans le webshop** (Stripe) puis de juste retirer au comptoir,
  le serveur sait déjà marquer un panier payé : il faudra ajouter l'étape Stripe avant
  le QR.
- Le bouton est un **second bouton du panier**, pas un 3e mode du sélecteur comme dans
  la maquette `pos-test.html` : un 3e mode aurait touché toute la logique de mode
  (cut-offs, disponibilités, créneaux) protégée par CLAUDE.md.
- Un **invité** peut aussi créer un panier caisse (sans fidélité) ; l'écran l'invite
  à se connecter pour cumuler ses points.
- Un panier préparé pour une boutique est **refusé dans une autre** (prix par boutique).
- Le total affiché au téléphone est une **estimation** ; la caisse fait foi.
