# Menu builder — structure attendue en API

> Ce que l'ERP doit exposer pour que le composeur de produit du webshop
> fonctionne : options, formules, suppléments, portions, traductions.
>
> **Cette structure n'est pas une préférence : elle est déduite de ce que le front
> lit réellement**, `webshop-full-bundle.jsx` lignes 830-1130 (composeur) et
> 1195-1400 (rendu). Tout champ absent ici n'est lu par personne ; tout champ
> renommé casse silencieusement l'écran.
>
> Complète et corrige `FRANCHISE_BUDDY_MENUS_API.md` (qui décrit la même chose
> mais omet 5 points, cf. §6).

---

## 1. Où ça se lit

Les menus sont **imbriqués dans le produit** — un seul appel catalogue suffit :

```
GET /api/v1/products/            → liste des produits
GET /api/v1/products/{id}/       → un produit
```

Un produit configurable porte `has_menu_options: true`. **Sans ce drapeau, les
formules ne sont jamais affichées**, même si `available_bundles` est rempli
(`webshop-full-bundle.jsx:857`).

---

## 2. Structure complète

```jsonc
{
  "id": 20,                        // entier — clé de commande, doit être stable
  "sku": "SKU-CLUB-020",
  "name": "Sandwich Club",         // langue source ; la traduction passe par /products/aliases
  "description": "…",              // facultatif
  "price": 9.50,                   // prix de la pièce entière, TVAC
  "vat_rate": 6,
  "has_menu_options": true,        // OBLIGATOIRE pour activer le composeur

  // ── 1. OPTIONS — modifient le produit lui-même (pain, sauce, cuisson) ──
  "options": [
    {
      "id": "bread",               // string, unique DANS le produit
      "label": "Choix de pain",
      "kind": "single",            // "single" | "multi"
      "required": true,            // bloque l'ajout au panier tant que non choisi
      "choices": [
        { "id": "white", "label": "Pain blanc",   "delta": 0 },
        { "id": "brown", "label": "Pain complet", "delta": 0.50 }
      ]
    }
  ],

  // ── 2. FORMULES — le produit + des accompagnements ──
  "available_bundles": [
    {
      "id": "b-full",              // string, unique DANS le produit
      "name": "Full Menu",
      "description": "1 Sandwich Club + 1 boisson + 1 dessert",
      "price_modifier": 5.50,      // +€ AJOUTÉ au prix de base (jamais un prix absolu)
      "recommended": true,         // met la formule en avant ; UNE SEULE par produit
      "advantages": ["Économisez 2,50 €"],
      "included": [{ "label": "Sandwich Club" }],
      "slots": [
        {
          "id": "drink",
          "label": "Votre boisson",
          "required": true,
          "min_select": 1,         // minimum à choisir si required
          "max_select": 1,         // > 1 ⇒ le slot devient multi-choix
          "kind": "single",        // "single" | "multi" (redondant avec max_select)
          "choices": [
            { "id": "d1", "label": "Eau plate 33 cl", "img": "https://…/eau.png", "delta": 0 },
            { "id": "d2", "label": "Limonade",        "img": "https://…/lim.png", "delta": 0.50 }
          ]
        }
      ]
    }
  ],

  // ── 3. SUPPLÉMENTS — cases à cocher indépendantes ──
  "upsells": [
    { "id": "salad", "label": "Petite salade", "img": "https://…/salade.png", "delta": 4.50 }
  ],

  // ── 4. PORTIONS — parts d'une pièce entière (tartes, quiches) ──
  "portions": [
    { "id": 41, "type": "ONE_HALF",    "label": "1/2", "display_order": 1, "is_active": true },
    { "id": 42, "type": "ONE_QUARTER", "label": "1/4", "display_order": 2, "is_active": true }
  ]
}
```

---

## 3. Règles que la structure doit respecter

| # | Règle | Pourquoi |
|---|---|---|
| R1 | `delta` et `price_modifier` sont des **écarts**, jamais des prix absolus | Le front additionne : `prix = base + formule + slots + suppléments` (`webshop-full-bundle.jsx:963`) |
| R2 | `delta` ≥ 0 | Un `delta` négatif s'afficherait `+-0.50` ; les remises passent par les bons, pas par le menu |
| R3 | `id` **stable dans le temps**, string pour options/slots/choix, entier pour le produit | Ces identifiants sont écrits dans la commande (`bundleId`, `bundleSlots`, `webshop-full-bundle.jsx:1107`) : les changer casse l'historique |
| R4 | `id` unique **dans le produit**, pas globalement | Le front indexe par `sel[o.id]` et `bundleSlots[slot.id]` |
| R5 | `required: true` ⇒ `choices` non vide | Sinon le bouton d'ajout reste bloqué sans que le client puisse débloquer |
| R6 | Une seule formule `recommended: true` | Elle est présélectionnée à l'ouverture (`:892`) ; deux ⇒ la première gagne, arbitrairement |
| R7 | `max_select > 1` ⇒ `min_select` renseigné | Sert au compteur « 1/2 — choisissez 2 » (`:1336`) |
| R8 | `img` en **URL absolue** | La page est servie sous `/webshop/`, un chemin relatif ne résout pas |
| R9 | Pas de « À la carte » dans `available_bundles` | Le front l'ajoute lui-même en tête, traduit (`:859`) ; l'envoyer le dupliquerait |
| R10 | `portions[].type` dans `ONE_HALF` / `ONE_QUARTER` / `ONE_EIGHTH` | Seules valeurs reconnues (`php-api/index.php:135`) ; toute autre est ignorée |

---

## 4. Traductions

Les libellés ci-dessus sont dans **la langue source**. Le client les reçoit traduits
par la même mécanique que les noms de produits :

```
GET /api/v1/products/aliases?lang_code=nl           → noms de produits
GET /api/v1/product-categories/aliases?lang_code=nl → catégories
```

Forme réelle, **vérifiée jeton en main** sur `atelierby.tfbuddy.com` :

```jsonc
[
  { "fk_id": 5100001,                       // ⚠️ pas "id" : la clé est fk_id
    "base_value": "1 croissant + 1 café",   // libellé source (fr)
    "alias_value": "1 croissant + 1 koffie",// la traduction, ou null
    "effective_value": "1 croissant + 1 koffie", // alias_value ?? base_value
    "lang_code": "nl",                      // null quand il n'y a pas d'alias
    "alias_type": "default" }
]
```

Deux pièges à consommer ce format :

1. **`effective_value` n'est pas une traduction** : il retombe sur `base_value`
   quand l'alias manque. Le lire sans regarder `alias_value` ferait afficher du
   français en croyant servir du néerlandais.
2. **La couverture est partielle** : 609 produits traduits sur 762, 75 catégories
   sur 81. Un produit sans alias (ex. `6700237` « Abricot ») doit garder son nom
   source, pas disparaître ni s'afficher vide.

**Ce qui manque aujourd'hui** : aucun endpoint d'alias ne couvre les libellés
*internes au menu* — `options[].label`, `choices[].label`, `slots[].label`,
`available_bundles[].name`. Deux façons de le résoudre, au choix de l'ERP :

- **(a) alias dédiés** : `GET /api/v1/product-options/aliases` et
  `/api/v1/product-option-choices/aliases`, même forme que les autres ;
- **(b) libellés multilingues en ligne** : remplacer `"label": "Pain blanc"` par
  `"label": { "fr": "Pain blanc", "nl": "Wit brood" }`.

L'option (b) est plus simple à consommer (un seul appel) mais change la forme
existante ; l'option (a) respecte le motif déjà en place. **Sans l'une des deux,
un client néerlandophone verra la fiche produit en NL et les choix de menu en FR.**

---

## 5. Ce que le front renvoie à la commande

Pour que l'ERP puisse produire le bon, la ligne de panier porte les **identifiants**
et pas seulement les libellés (`webshop-full-bundle.jsx:1096-1125`) :

```jsonc
{
  "productId": 20,
  "name": "Sandwich Club",              // + « — 1/2 » si portion
  "qty": 1,
  "price": 15.00,                       // unitaire, tout compris
  "basePrice": 9.50,                    // prix de la pièce entière
  "bundleId": "b-full",                 // null si « à la carte »
  "bundleSlots": { "drink": "d2", "dessert": ["s1", "s2"] },
  "portion": "quart",                   // "demi" | "quart" | "huitieme" | null
  "cat": "sandwiches",
  "options": [{ "label": "Pain complet" }, { "label": "+ Petite salade" }]
}
```

⚠️ **Deux pièges pour qui consomme ce format :**

1. `bundleSlots` porte **une string en choix unique, un tableau en multi-choix**
   (`webshop-full-bundle.jsx:1012-1016`) — il faut gérer les deux.
2. **Les suppléments choisis ne sont PAS transmis par identifiant.** `upsellIds`
   reste dans le composeur (`:845`) ; les suppléments n'apparaissent que comme
   libellés préfixés `« + »` dans `options[]` (`:1090-1093`). L'ERP ne peut donc
   pas savoir *lequel* a été choisi autrement qu'en analysant du texte — et cette
   analyse casse dès que la langue change. **À corriger côté webshop** : ajouter
   `upsellIds: ["salad"]` à la ligne de panier, au même titre que `bundleSlots`.

## 6. Écarts entre ce document et `FRANCHISE_BUDDY_MENUS_API.md`

Cinq points que l'ancien contrat ne mentionne pas alors que le front les lit :

1. **`slots[].min_select` / `max_select`** (`:1336`, `:1350`) — sans eux, un slot
   multi-choix se comporte comme un choix unique.
2. **`slots[].required`** (`:979`) — conditionne le blocage de l'ajout au panier.
3. **`portions[]`** — servies aujourd'hui par les tables `product_portion` /
   `shop_product_portion_price`, pas par le contrat menus.
4. **La traduction des libellés de menu** (§4) — trou complet.
5. **Le format de retour à la commande** (§5) — l'ERP doit savoir ce qu'il recevra,
   y compris le trou sur les suppléments relevé ci-dessus.

---

## 7. Confronté à l'ERP réel, jeton en main

Vérifié sur `atelierby.tfbuddy.com` (compte consultant), boutique 2, produit
`6700237` « Abricot » :

| Endpoint | Réponse | Ce qu'on en tire |
|---|---|---|
| `GET /products/aliases?lang_code=nl` | 200, 762 lignes | forme `fk_id`/`alias_value` (§4), **pas** celle que ce document supposait |
| `GET /product-categories/aliases?lang_code=nl` | 200, 81 lignes | idem |
| `GET /products/{id}/aliases` | **404** | pas d'alias à l'unité : le dictionnaire se lit en bloc |
| `GET /products/{id}/portions` | 200, 3 lignes | `portion_type` ∈ `ONE_HALF`/`ONE_QUARTER`/`ONE_EIGHTH` + `portion_fraction{numerator,denominator}` — conforme à R10 |
| `GET /shops/{s}/products/{p}/portions/prices` | 200, 3 lignes | **c'est la table de prix** : `shop_price_gross` est le prix facturé, `has_shop_price` / `is_ready_for_sale` disent s'il est fixé |
| `GET /shops/{s}/products/{p}/price` | **404** | n'existe pas |

Trois constats qui commandent la suite :

1. **`shop_price_gross` est le prix de vente**, et il vit bien dans une table à
   part — exposée par le seul endpoint `portions/prices`. Sur les ~50 produits
   sondés de la boutique 2, `has_shop_price` est **faux partout** : la table est
   en place, pas encore remplie. La règle « pas de prix ⇒ pas vendable » suffit,
   il n'y a rien à deviner.
2. **Le prix de la PIÈCE ENTIÈRE n'a aucun endpoint.** Seules les portions en
   ont un ; `/price` répond 404. Tant que ce trou existe, le prix de la pièce
   entière reste servi par le SQL du webshop.
3. **Aucune lecture en lot.** `portions/prices` prend un produit à la fois : 573
   appels pour une page de catalogue. Utilisable sur la fiche produit
   (`php-api/erp_catalog.php:erp_portion_prices`), pas en liste.

**À demander à Franchise Buddy**, dans cet ordre d'importance :
`GET /shops/{id}/products/prices` (prix de vente en lot, pièce entière **et**
portions) ; puis les alias des libellés internes au menu (§4).

`is_divisible` n'est pas le drapeau des portions : `6700237` porte
`is_divisible: 0` et a pourtant trois portions actives, tandis que les 10
produits marqués `is_divisible: 1` de la boutique 2 rendent `[]`. **La présence
de lignes de portions est la seule source fiable** — s'appuyer sur
`is_divisible` afficherait des portions inexistantes et en cacherait de vraies.
