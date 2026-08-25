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
