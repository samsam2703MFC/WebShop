# Ce qu'il manque à l'ERP pour piloter le webshop en totalité

> Relevé du 24/08/2026, après la bascule assortiment/canaux/photos.
> Chaque manque est mesuré sur le code qui lit encore la base en direct
> (`AUDIT_API_VS_DB.md`) ou sur un endpoint sondé jeton en main.

## Déjà piloté par l'ERP (rien à demander)

| Donnée | Endpoint | Depuis |
|---|---|---|
| Assortiment, canaux (publié / C&C / bureau) | `shops/{id}/products/available` + `PATCH /products/{id}` | 24/08 |
| Noms produits & catégories traduits | `products/aliases`, `product-categories/aliases` | 23/08 |
| Photos produit | `recipes/{id}` (`shop_photo_path`) | 23/08 |
| Périodes de disponibilité | `available?include=availability_periods` | 23/08 |
| Prix des PORTIONS | `available?include=portions` | 23/08 |

---

## Les 6 manques, par ordre de blocage

### 1. Prix de vente de la PIÈCE ENTIÈRE — le seul vrai bloquant
`GET /shops/{id}/products/prices` (en lot), ou un champ `shop_price_gross`
dans `products/available`.

C'est **la dernière donnée décisionnelle encore locale** : `ws_products.price`.
Tant qu'elle manque, un produit créé dans Franchise Buddy naît invendable et
attend qu'un humain pose son prix dans la console marque. `/price` répond 404,
et `suggested_sale_price` est un conseil marque, pas le prix facturé (il
diffère de `portion_price` sur 524 des 573 produits observés — les confondre
vendrait au mauvais prix).

### 2. Clients finaux — création et lecture
Aucun endpoint. Le webshop **écrit directement dans la table `client`** de
l'ERP (`index.php:1973, 3032, 3240`) : inscription, mot de passe, société de
facturation. Ça marche parce que même base ; le jour où l'ERP migre ce schéma
— il vient de le faire pour `shop_product` — ces écritures cassent sans
préavis.

### 3. Bons & promotions — lecture et rachat
Aucun endpoint. Le webshop écrit dans `promotion`, `voucher_campaign`,
`voucher_code`, `voucher_redemption` (canal `'WS'`). Même risque que le point 2,
sur de l'argent.

### 4. Statut allergènes — « évalué sans allergène » vs « non renseigné »
`grouped_allergens` vide est **ambigu** aujourd'hui. Le webshop calcule donc la
distinction en SQL (recette → matières → allergènes) parce qu'en sécurité
alimentaire on n'affiche pas « aucun allergène » sans savoir que la recette a
été évaluée. Un champ `allergens_evaluated: true|false` suffirait.

### 5. Photos en lot ou à URL stable
`recipes/{id}` = un appel par produit, et l'URL R2 expire en 3 600 s. Tenable
pour une synchro (c'est ce qu'on fait), pas pour du temps réel. Un `photo_url`
dans `available`, ou un endpoint groupé, supprimerait la synchro.

### 6. Configuration boutique — les colonnes du webshop
`webshop_enabled`, `default_lang`, `languages`, `landing_config` n'existent
que dans la base du webshop. `GET /shops` de l'ERP ne peut pas les remplacer.
À trancher : soit l'ERP les porte (et pilote tout), soit elles restent la
part assumée du webshop.

---

## Le modèle CLIENT, en détail (relevé du 25/08)

`GET /shops/{id}/clients` rend **60 champs**. Ils se répartissent en quatre
familles, et la distinction compte : un champ vide n'est pas forcément un
défaut.

### 1. Servis et exploitables — 18 champs

`id`, `id_main_shop`, `active`, `create_timestamp`, `source_channel`,
`invoice_country`, `phone_prefix` (8154/8154) · `phone` (8123) · `name` (7373)
· `surname` (4231) · `company_name` + `is_b2b` (669) · `street`/`city`/`zip`
(~670) · `tax_number` (640) · `email` (575) · `can_deferral` (342) ·
`payment_terms` (234) · `updated_at` (224) · `personal_discount_percent` (7) ·
`peppol_identifier` (4).

C'est ce que l'index du webshop exploite : **un annuaire** — identité, contact,
société, TVA, adresse.

### 2. Vides PAR CONCEPTION — endpoints dédiés annoncés

- **Fidélité** : `fidelity_active`, `fidelity_linked_at`, `member_since`
- **Facturation** : `invoice_name`, `invoice_address`, `invoice_postal_code`,
  `invoice_city`, `iban`, `peppol_verified`, `billing_lines` — **la facturation
  est gérée par l'ERP**, sur son propre endpoint. Le webshop n'a donc pas à
  porter ces données : il transmet la commande, l'ERP facture.

Ces dix champs ne sont pas des anomalies : ils vivront ailleurs. Ce qui reste à
savoir, c'est **par quelle clé** ces endpoints rattacheront un client — voir §3.

### 3. La clé de rapprochement : le TÉLÉPHONE

`public_id` porte l'identifiant public côté ERP, et c'est **le numéro de
téléphone**. Les chiffres le confirment : **8123 fiches sur 8154 ont un
téléphone, 575 seulement un e-mail**. Un rapprochement par e-mail retrouverait
7 % des clients ; par téléphone, 99,6 %.

Conséquence pour les endpoints à venir (fidélité, facturation) : ils doivent
accepter **l'id ERP ou le téléphone**, pas l'e-mail. Le webshop sait faire les
deux (`erp_client_par_tel`, clé normalisée sur les 9 derniers chiffres).

`public_id` est lui-même **vide sur les 8154** — le webshop normalise donc
`phone` lui-même en attendant.

### 4. Ce que l'ERP ne portera pas — et c'est structurant

- **L'authentification** : `password_hash` (vide, et c'est sain), `preferred_auth_method`, `phone_e164`.
  Le compte de connexion webshop (e-mail + mot de passe) reste au webshop : l'ERP
  n'a pas d'identifiant de connexion pour ses 8154 fiches, dont 93 % n'ont pas
  d'e-mail.
- **Le lien BUREAU** : `office_id`, `office_delivery` sont vides sur les 8154.
  Le rattachement d'un client à un bureau (`pwa_client_office`) et l'autorisation
  de livraison — **validée à la main par le franchisé** — sont des notions
  purement webshop.

### 5. `preferred_shop_id` = `id_main_shop` — une redondance chez NOUS

Ce sont **deux noms pour la même notion** : la boutique du client. L'ERP n'en
garde qu'un (`id_main_shop`, rempli sur les 8154) et laisse l'autre vide.

Le webshop, lui, écrit **les deux avec la même valeur** à chaque inscription
(`$shopI` dans les 7 `INSERT INTO client`), et six lectures interrogent
`preferred_shop_id` — notamment `/webshop-link`, qui résout la boutique
préférée d'un client venu de la PWA. Ça marche, mais c'est deux colonnes pour
une seule idée : à unifier sur `id_main_shop` quand on y touchera, en vérifiant
les six lectures d'abord.

### 6. Vides et inexpliqués — les questions qui restent

`webshop_user`, `pwa_user` (le webshop écrit pourtant `webshop_user=1` à
l'inscription) · `blocked`, `status`, `verified_at`,
`needs_profile_completion`, `merged_into_id` · `locale` (ce serait le bon
endroit pour la langue du client) · `client_code`, `last_login_at`.

Et les 5 champs de conditions B2B — `b2b_segment`, `b2b_credit_ceiling`,
`b2b_payment_terms`, `b2b_web_discount`, `b2b_franco` : relèvent-ils de
l'endpoint facturation, ou du client ?

### 7. Ce qui est cassé

`GET` et `PATCH /shops/{id}/clients/{cid}` répondent **404
CLIENT_NOT_ASSIGNED_TO_SHOP sur les 8154 clients** (12 tirés au hasard, 5
boutiques, deux jetons). Cause identifiée : la création écrit une **assignation**
(`shop_assignment_created: true`) que les 8154 fiches historiques n'ont pas —
`id_main_shop` ne suffit pas à la fiche unitaire. C'est une **reprise de données
à faire côté ERP**, pas un correctif de code.

En attendant, le webshop lit ses clients dans la LISTE (index local, 1,8 Mo,
rafraîchi toutes les 15 min) — contournement documenté dans `erp_clients.php`.

---

## Ce qui restera au webshop de toute façon

Commandes (`ws_orders`), stock et réservations, créneaux et tournées, offices
B2B, cross-selling, traductions d'interface (`ws_i18n`), menus. Ce sont des
objets que l'ERP ne connaît pas — les demander serait lui faire porter un
métier qui n'est pas le sien.

## Si les 6 sont livrés

Le webshop n'écrit plus une ligne dans les tables ERP, et `ws_products` cesse
d'être décisionnelle pour devenir un pur cache de lecture — gardé, parce qu'il
donne la résilience (ERP en panne = vente qui continue), la vitesse (une
requête SQL locale au lieu d'un appel par visiteur) et la cohérence entre ce
qui s'affiche et ce qui se facture.
