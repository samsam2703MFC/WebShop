# Franchise Buddy — API Menus (contrat d'intégration)

Ce que l'ERP doit exposer pour que la boutique affiche les produits
configurables (menus). L'API PHP lit ces données et les ressert au
front **quasi sans transformation** — donc la forme ci-dessous suit exactement
ce que le front attend (`options`, `available_bundles`, `upsells`).

## Endpoint

Les menus sont **imbriqués dans le produit** (un seul appel catalogue suffit) :

```
GET /api/v1/products/            → liste des produits (avec menus imbriqués)
GET /api/v1/products/{id}/       → un produit
```

Chaque produit configurable porte `has_menu_options: true` et les 3 blocs
`options`, `available_bundles`, `upsells`.

## Forme JSON (exemple : Sandwich Club)

```json
{
  "id": 20,
  "sku": "SKU-CLUB-020",
  "name": "Sandwich Club",
  "price": 9.50,
  "vat_rate": 6,
  "has_menu_options": true,

  "options": [
    {
      "id": "bread",
      "label": "Choix de pain",
      "kind": "single",
      "required": true,
      "choices": [
        { "id": "white", "label": "Pain blanc",  "delta": 0 },
        { "id": "brown", "label": "Pain complet", "delta": 0 }
      ]
    },
    {
      "id": "sauce", "label": "Sauce", "kind": "single", "required": true,
      "choices": [
        { "id": "oil",  "label": "Huile d'olive", "delta": 0 },
        { "id": "mayo", "label": "Mayonnaise",    "delta": 1.0 }
      ]
    }
  ],

  "available_bundles": [
    {
      "id": "b-full",
      "name": "Full Menu",
      "description": "1 Sandwich Club + 1 boisson + 1 dessert",
      "price_modifier": 5.50,
      "recommended": true,
      "advantages": ["Économisez 2,50 €", "Boisson + dessert inclus"],
      "included": [{ "label": "Sandwich Club" }],
      "slots": [
        {
          "id": "drink", "label": "Boisson", "required": true,
          "choices": [
            { "id": "d1", "label": "Eau plate 33cl",  "img": "…/cold-drink.png",  "delta": 0 },
            { "id": "d2", "label": "Limonade maison", "img": "…/lemonade.png",     "delta": 0.5 }
          ]
        },
        {
          "id": "dessert", "label": "Dessert", "required": true,
          "choices": [
            { "id": "s1", "label": "Cookie",    "img": "…/cookies.png",  "delta": 0 },
            { "id": "s2", "label": "Cupcake",   "img": "…/cupcake.png",   "delta": 0 }
          ]
        }
      ]
    }
  ],

  "upsells": [
    { "id": "salad", "label": "Petite salade", "img": "…/salads.png", "delta": 4.5 }
  ]
}
```

## Règles de champ

| Champ | Type | Sens |
|---|---|---|
| `options[].kind` | `"single"` \| `"multi"` | choix unique ou multiple |
| `options[].required` | bool | option obligatoire à la commande |
| `*.delta` | number | **+€** ajouté au prix (peut être 0) |
| `available_bundles[].price_modifier` | number | **+€** ajouté au prix de base pour la formule |
| `available_bundles[].recommended` | bool | met la formule en avant |
| `available_bundles[].advantages` | string[] | puces d'arguments (affichage) |
| `slots[].choices[].img` | string (URL) | image du choix (boisson/dessert) |

⚠️ **Noms attendus par le front** : `id`, `delta`, `img` (et non `code` /
`price_delta` / `image`). Si tes colonnes DB s'appellent `code`/`price_delta`/
`image` (cf. `DATABASE_MENUS.md`), c'est le **sérialiseur** qui fait la
correspondance (`code → id`, `price_delta → delta`, `image → img`).

## Prix d'une ligne (calculé côté serveur, jamais côté client)

```
prix = price
     + Σ delta(options choisies)
     + price_modifier(bundle choisi)
     + Σ delta(choix de slots)
     + Σ delta(upsells choisis)
```

## Sélection renvoyée à la commande

Quand le client commande, le front envoie la sélection ; l'ERP/commande la
stocke ainsi :

```json
{
  "productId": 20,
  "qty": 1,
  "bundleId": "b-full",
  "options": { "bread": "white", "sauce": "mayo" },
  "bundleSlots": { "drink": "d2", "dessert": "s1" },
  "upsells": ["salad"]
}
```

## Authentification

Si l'API exige une clé, le pont l'envoie en `Authorization: Bearer <clé>`
(clé stockée dans les réglages du pont, jamais en clair). Sinon endpoint public
en lecture.
