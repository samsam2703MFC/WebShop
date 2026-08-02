# Tests restants avant le go-live

Mis à jour après la campagne d'audit du code. Tout ce qui suit demande **des
clics dans l'interface** : ces flows ne peuvent pas être validés par requête SQL
seule.

**Environnement**
- Webshop : `http://185.180.206.46/webshop/`
- BO franchisé : `http://185.180.206.46/webshop/backoffice_franchisee/?shop=2`
- Console marque : `http://185.180.206.46/webshop/backoffice_franchisor/`
- Base : `atelierby_db` (⚠️ une base `scheme_cmp` existe aussi : **toujours
  préfixer** `atelierby_db.`)
- Les back-offices exigent le **jeton admin** la première fois :
  `?shop=2&token=<jeton>` (ensuite mémorisé par le navigateur).

**Règle du projet à respecter dans tout correctif**
> Soit la fonctionnalité marche avec les vraies données, soit elle renvoie une
> erreur visible. Aucun seed, aucun mock, aucun repli qui fabrique de la donnée.

**Lecture des statuts**

| Statut | Sens |
|---|---|
| 🔴 **jamais exercé** | le code n'a jamais tourné en conditions réelles |
| 🟠 **corrigé, non testé** | un défaut réel a été trouvé et réparé ; reste à confirmer |
| ⛔ **bloqué** | il manque un développement pour que ce soit testable |

---

## 1. 🟠 Rattachement à un bureau

**Quatre verrous empilés ont été trouvés et corrigés** — la fonctionnalité
n'avait jamais pu marcher :

1. le parcours était **inatteignable** : la demande de rattachement n'était
   offerte qu'**après avoir délié un bureau existant** (il fallait donc déjà en
   avoir un pour en demander un) ;
2. l'endpoint `POST /offices/contact` **n'existait pas** (404 avalé côté client) ;
3. côté franchisé, « Lier » ne faisait que changer un statut : `client.office_id`
   n'était **jamais écrit** ;
4. `address_raw` étant `NOT NULL`, une demande sans adresse aurait été **refusée
   par la base**.

**Étapes**
1. Webshop, **boutique Corbais**, connecté → **Compte → Mon bureau** →
   « Me rattacher à une entreprise / demander l'ajout de mon bureau ».
2. Soit sélectionner un bureau validé → **Confirmer** (rattachement direct) ;
   soit « Mon bureau n'est pas dans la liste » → nom + un contact → **Envoyer**
   (doit afficher « Demande envoyée ✓ »).
3. BO franchisé → **Demandes de rattachement** : la demande porte le **nom du
   client** et la pastille verte « Bureau trouvé » → **Lier**.
4. Le journal doit afficher `client #… → bureau #…`.

**Vérification**
```sql
SELECT id, client_id, shop_id, office_name_raw, contact_email, contact_phone,
       status, resolved_office_id, resolved_at, created_at
  FROM atelierby_db.ws_office_join_requests ORDER BY id DESC LIMIT 5;

SELECT id, email, office_id FROM atelierby_db.client WHERE office_id IS NOT NULL;
```

**Bureaux validés disponibles** : SA IBA (4), XLG FACILITY S.A (7),
COMMUNE MONT ST GUIBERT (8) pour la boutique 2 ; TechnoParc SPRL (3) pour la
boutique 3 ; Colruyt Group HQ (1) et Fiduciaire Lemaire (2) pour la boutique 4.

---

## 2. 🔴 Livraison au bureau, de bout en bout ⚠️ priorité

**Précondition** : le test 1 réussi (`client.office_id` renseigné).

**Étapes** : basculer en **Livraison au bureau** → vérifier que le catalogue
**exclut** les produits `office_delivery = 0` (filtre serveur, résiste au
rechargement) → choisir un site puis un créneau → commander → la commande doit
apparaître dans **Commandes du jour** ET dans **Suivi / dispatch**, avec son
bureau et sa tournée.

**Vérification**
```sql
SELECT id, order_ref, mode, customer_id, office_client_id, tour_id,
       office_delivery_site_id, delivery_date, slot_label, total, status
  FROM atelierby_db.ws_orders WHERE mode = 'delivery' ORDER BY id DESC LIMIT 5;
```

**Point de vigilance** : le calcul des frais de livraison échouait **en
silence** — frais facturés 0 €, paiement différé ignoré, site et tournée issus du
repli. L'échec est désormais **affiché** et la commande **refusée** tant qu'il
n'est pas résolu. Si « Frais de livraison indisponibles » apparaît, c'est un vrai
problème serveur, pas un défaut d'affichage.

---

## 3. 🔴 Bon nominatif de bout en bout

**Précondition** : bon `B2-KGZ46X` (Corbais, cible **bureau 4 SA IBA**,
« Cougnou - Sucre offert », −100 % sur 1 pièce, limite **1 par client**).

**Déjà vérifié sans connexion** : ciblage et limite par client via
`/vouchers/available` ; aperçu et facturation appliquent la **même règle** (mêmes
pièces retenues, même plafond, mêmes formules, prix ERP des deux côtés).

**Reste à confirmer** : que la remise vaille **le prix du cougnou et pas plus**,
et que le **deuxième usage soit refusé**.

**Étapes** : connecté avec un client du bureau 4 → cougnou + un autre produit →
étape Paiement → le bon apparaît dans « Vos bons disponibles » → Appliquer →
commander → refaire une commande avec le même bon (doit être **refusé** et
**disparaître** de la liste) → se connecter avec un client hors bureau 4 (le bon
ne doit **jamais** apparaître).

```sql
SELECT vco.code, vco.usage_count, vc.usage_limit_per_customer,
       COUNT(vr.id) AS redemptions, GROUP_CONCAT(vr.id_customer) AS clients
  FROM atelierby_db.voucher_code vco
  JOIN atelierby_db.voucher_campaign vc ON vc.id = vco.id_voucher_campaign
  LEFT JOIN atelierby_db.voucher_redemption vr ON vr.id_voucher_code = vco.id
 WHERE vco.code = 'B2-KGZ46X'
 GROUP BY vco.code, vco.usage_count, vc.usage_limit_per_customer;
```

---

## 4. 🔴 Paiement différé (facturation mensuelle)

**Précondition** : un bureau avec `ws_offices.deferred_billing_enabled = 1`, et
le test 1 réussi.

**Attendu** : l'étape Paiement propose **« Paiement différé »** et **uniquement**
les moyens autorisés pour ce profil ; la commande est enregistrée sans paiement
immédiat.

**Point de vigilance** : `payment_type` retombait sur « immédiat » dès que le
calcul des frais échouait — un bureau en facturation différée se voyait donc
demander un paiement immédiat. Corrigé : l'échec bloque désormais la commande.

---

## 5. 🟠 Réservation de stock (maintien 15 min)

**La fonctionnalité n'existait pas.** `qty_reserved` était bien soustrait du
disponible partout, mais **rien ne l'alimentait** : les endpoints
`/catalog/stock/reserve` et `/catalog/stock/release` n'existaient pas, et les
appels du webshop partaient en 404 avalés. Deux clients pouvaient donc mettre la
**même dernière pièce** au panier, l'arbitrage n'arrivant qu'au paiement.

Trois défauts client corrigés au passage : échec silencieux ; **date calculée en
UTC** (après 22 h en heure belge d'été, réservation sur le **jour précédent**) ;
retrait d'une ligne qui **relâchait tout le panier**.

⚠️ **Le maintien ne concerne QUE les clients connectés** — décision assumée : un
panier invité ne tient pas de stock, l'arbitrage a lieu au paiement. Un test fait
en visiteur anonyme ne créera donc AUCUNE ligne, et la console l'annonce
(« pas de maintien : client non connecté »). Ce n'est pas une panne.

**Étapes** : connecté, boutique Corbais → ajouter **Brookie (1150002)** en Click
& Collect — il n'y a qu'**1 pièce**, l'effet est immédiat → vérifier la
réservation → retirer du panier → vérifier la libération. Puis, depuis un autre
navigateur et un autre compte, vérifier que la pièce **n'est plus disponible**
pendant le maintien.

```sql
SELECT id, product_id, shop_id, date, mode, qty, customer_id, expires_at, released_at
  FROM atelierby_db.ws_stock_reservation ORDER BY id DESC LIMIT 10;

SELECT product_id, mode, qty_total, qty_reserved, qty_sold
  FROM atelierby_db.ws_product_stock
 WHERE shop_id = 2 AND date = CURDATE() AND product_id = 1150002;
```

---

## 6. 🟠 Comptes tablette par PIN ⛔ deux écrans à finir

**Trois verrous empilés**, chacun suffisant à tout bloquer :

1. les routes `/bo/pin-*` étaient **inatteignables** : une règle de routage
   capturait tout `/bo/…`, donc **n'importe quel code** renvoyait « Not found » ;
2. une session PIN ne donnait accès à **aucune donnée** (`/franchisee/*`
   n'acceptait que le jeton admin) : menu filtré, mais tous les écrans en 401 ;
3. la couche données du BO envoyait **le jeton admin en dur** : sur une tablette,
   les appels partaient **sans authentification**.

**Modèle revu** : la **marque définit les profils** et leurs sections ; le
**franchisé crée ses comptes** (nom + PIN) et leur attribue un profil. Il ne
coche aucune section — elles découlent du profil, donc il ne peut pas s'octroyer
un accès non prévu.

**Reste à développer** : l'écran « Profils tablette » (console marque) et l'écran
« Comptes tablette » (BO franchisé). L'API est en place.

**Étapes (une fois les écrans faits)**
1. Console marque → **Profils tablette** → « Créer les profils standard ».
2. BO franchisé (avec jeton admin) → **Comptes tablette** → « Julie D. »,
   profil **Vendeur**, PIN **1234**.
3. BO franchisé en **navigation privée** → `?shop=2` → pavé PIN → `1234` →
   **4 sections** seulement, **avec des données dedans**.
4. Forcer `?shop=3` dans l'URL → doit **rester sur Corbais**.
5. Désactiver le compte → recharger → accès **fermé** immédiatement.
6. Retirer une section du profil côté marque → recharger → elle **disparaît**
   (les droits sont relus à chaque requête).
7. 9 mauvais PIN → blocage (8 essais / 5 min). Bouton **Quitter** → retour au pavé.

```sql
SELECT id, role_key, label, active, sections FROM atelierby_db.bo_role;

SELECT u.id, u.display_name, u.active, r.label AS profil,
       (u.pin_hash IS NOT NULL) AS a_pin, u.last_login_at
  FROM atelierby_db.bo_users u
  LEFT JOIN atelierby_db.bo_role r ON r.id = u.role_id;
```

---

## 7. 🔴 Création de compte + connexion

Partiellement fait : le compte `11861` (`maxime.decnop@outlook.com`) existe,
créé **côté PWA** (`source_channel = 'pwa'`) et **sans mot de passe**.

**Reste à tester** : le parcours PWA → webshop, c'est-à-dire le panneau
**« Définir votre mot de passe »**, puis la connexion par email **et** par
téléphone, puis la réinitialisation.

**Corrigé sans test** : `/auth/login` combinait email et téléphone avec un
`LIMIT 1` sans `ORDER BY`. Deux comptes partageant un numéro — cas réel en base —
pouvaient faire refuser un **mot de passe pourtant correct**. L'email prime
désormais, et un numéro ambigu renvoie une erreur explicite.

```sql
SELECT id, email, phone, zip, office_id, preferred_shop_id, source_channel,
       webshop_user, (password_hash IS NOT NULL) AS a_mdp
  FROM atelierby_db.client ORDER BY id DESC LIMIT 5;
```

---

## 8. 🔴 Fidélité + « Mes achats »

Code sain à l'audit. Si l'onglet Fidélité n'apparaît pas, c'est que le serveur ne
l'active pas (`ws_param('fidelity_tab_enabled')`) — ce n'est pas un bug
d'affichage. La demande de facture renvoie une erreur explicite si
`pwa_purchases.to_invoice` n'est pas migrée.

---

## 9. 🔴 Société de facturation + VIES

Code sain : vraie API REST `ec.europa.eu`, distinction **invalide** /
**indisponible**, mock supprimé.

**À vérifier côté infrastructure** : que `ec.europa.eu` soit **joignable depuis
le serveur**. Si la vérification échoue systématiquement, c'est le réseau, pas le
code.

---

## 10. 🔴 Handoff PWA → webshop (SSO par jeton)

Câblé des deux côtés : jeton à usage unique, expiration, ouverture de session.
Ouvrir le webshop depuis la PWA via `?handoff=<jeton>` — la session doit être
reprise sans nouvelle connexion.

---

## 11. ⛔ Dispatch tablette chauffeur — **l'application n'existe pas**

Le BO écrit bien dans `ws_tour_tracking` (chauffeur, nombre d'arrêts, horodatage
d'envoi). Mais **rien ne renvoie l'information** : `stops_done` n'est incrémenté
par aucun code, `driver_validated_at` n'est écrit nulle part, et **aucun endpoint
côté chauffeur n'existe**. Les fichiers `tournee-*.jsx` sont des maquettes admin
aux données vides.

Ce n'est donc pas un test mais **un développement** : vue chauffeur (liste des
arrêts, validation QR/PIN/signature, remontée des positions) + endpoints. C'est
aussi la réponse à la question restée ouverte « pourquoi `ws_tour_tracking`
est-elle vide ».

---

## 12. 🔴 Matrice complète des paiements ⚠️ priorité

Les moyens de paiement **réels** sont au nombre de trois — les libellés
« Bancontact », « Visa », « Apple Pay » qui traînaient dans l'interface ne
correspondaient à rien côté serveur :

| Méthode | Libellé client | Comportement attendu |
|---|---|---|
| `stripe` | Carte / Bancontact (en ligne) | redirection Stripe, retour sur la confirmation, `payment_status` mis à jour |
| `shop` | Paiement en boutique | commande enregistrée **sans** paiement, à régler au retrait |
| `deferred` | Sur compte (facturation) | commande enregistrée sans paiement, facturée au bureau |

La liste proposée dépend de **la boutique × le profil** (`ws_shop_payment_options`,
profils `guest` / `registered` / `company`). Sans configuration, le défaut est
`stripe` + `shop` (et `stripe` + `deferred` pour une société).

**Deux défauts corrigés en préparant ce test**
- la confirmation affichait **« Bancontact »** pour toute méthode non reconnue —
  donc aussi pour un paiement **en boutique** ou **sur compte**, que le client
  n'avait pas fait. Le libellé vient maintenant de la liste serveur.
- la sélection initiale valait `bancontact`, un identifiant **qui n'existe pas** ;
  elle est désormais posée par la réponse de `/payment-methods`.

### Configuration à vérifier AVANT le test
```sql
SELECT shop_id, profile_type, method, active
  FROM atelierby_db.ws_shop_payment_options
 WHERE shop_id = 2 ORDER BY profile_type, method;
```
Table vide = comportement par défaut. Si une méthode manque à l'écran, c'est
cette table qu'il faut corriger, pas le code.

### Matrice à parcourir

| # | Profil | Mode | Méthode | Attendu |
|---|---|---|---|---|
| 12.1 | Invité | Click & Collect | `stripe` | redirection Stripe → paiement → confirmation → `payment_status` payé |
| 12.2 | Invité | Click & Collect | `shop` | commande créée, `payment_status = pending`, aucune redirection |
| 12.3 | Invité | Click & Collect | `deferred` | **ne doit pas être proposé** |
| 12.4 | Connecté | Click & Collect | `stripe` | idem 12.1 + commande rattachée au client |
| 12.5 | Connecté | Click & Collect | `shop` | idem 12.2 + visible dans « Mes achats » |
| 12.6 | Connecté | Livraison bureau | `stripe` | + frais de livraison au bon montant |
| 12.7 | Connecté | Livraison bureau | `deferred` | proposé **uniquement** si `deferred_billing_enabled = 1` |
| 12.8 | Société | Click & Collect | `stripe` | facture avec identité société |
| 12.9 | Société | Livraison bureau | `deferred` | **seule** méthode proposée si le site est en différé |
| 12.10 | Tous | — | abandon du paiement Stripe | commande **non** confirmée, stock **libéré** |
| 12.11 | Tous | — | échec de paiement Stripe | message d'erreur, panier conservé |
| 12.12 | Connecté | — | avec bon de réduction | montant envoyé à Stripe = total **après** remise |
| 12.13 | Tous | — | aucun moyen configuré | écran d'erreur explicite, commande impossible |

**À vérifier sur chaque ligne** : le libellé affiché dans la confirmation
correspond bien à la méthode choisie (c'est le défaut qui vient d'être corrigé).

```sql
SELECT id, order_ref, mode, payment_method, payment_status, payment_type,
       total, voucher_code, voucher_discount, created_at
  FROM atelierby_db.ws_orders ORDER BY id DESC LIMIT 15;
```

**Point de vigilance** : 12.10 et 12.11 sont les cas les plus rarement testés et
les plus coûteux — un abandon qui laisse du stock réservé bloque la vente pour
tout le monde jusqu'à l'expiration du maintien.

---

# Déjà validé — ne pas refaire

| Sujet | Résultat |
|---|---|
| Checkout invité complet | commande créée, email de contact, confirmation |
| Code promo `BIENVENUE10` | aperçu = montant facturé |
| Créneaux Click & Collect | générés depuis `ws_shop_availability`, jamais inventés |
| Portions ERP | prix par portion réels, plus de facteurs inventés |
| Règle « obligatoire ⇒ vendable » | trigger vérifié |
| Assortiment (activation / retrait) | écriture réelle `ws_product_shops` |
| Hygiène des noms produits | normalisés + garde permanente |
| Prix : source unique ERP | produit sans prix masqué et refusé |
| Prix ERP à 0 | traité comme « non fixé », plus de facturation à 0 € |
| Allergènes | 3 états distincts, plus de faux « sans allergène » |
| Stock du jour (BO → base → API → blocage) | chaîne complète |
| Compteurs de bons + limite par client | vérifiés via `/vouchers/available` |
| Zones de chalandise | polygones réels affichés |
| Écrans marque | KPIs, Bons, Catalogue, Audit, Menu Builder, Gouvernance |
| Formules vides | plus proposées au client |
