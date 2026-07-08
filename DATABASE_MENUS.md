# Structure de base de données — Menus & produits configurables

Modélise le système « menu » de la boutique (ex. **Sandwich Club** → formules
*Menu* / *Full Menu*, avec options pain/sauce, créneaux boisson/dessert, et
upsells). MySQL · InnoDB · utf8mb4 · clés étrangères. Correspond exactement aux
champs lus par le front (`options`, `available_bundles`, `upsells`).

## Vue d'ensemble

```
ws_products (has_menu_options = 1)
│
├─ ws_product_options ───< ws_product_option_choices      (pain, sauce…)
│     (single / multi)         (+ price_delta)
│
├─ ws_menus ──┬─────────────────────────────────────────  (Menu, Full Menu)
│  (bundles)  └─ ws_menu_slots ──< ws_menu_slot_choices    (boisson, dessert…)
│     (+ price_modifier)   (required)     (+ image, price_delta)
│
└─ ws_product_upsells                                       (salade, soupe…)
      (+ image, price_delta)
```

## 1. Options du produit (choix qui modifient le produit de base)

```sql
CREATE TABLE ws_product_options (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id  INT          NOT NULL,
  code        VARCHAR(40)  NOT NULL,                 -- 'bread', 'sauce'
  label       VARCHAR(120) NOT NULL,                 -- 'Choix de pain'
  kind        ENUM('single','multi') NOT NULL DEFAULT 'single',
  required    TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_option (product_id, code),
  KEY idx_option_product (product_id),
  CONSTRAINT fk_option_product FOREIGN KEY (product_id) REFERENCES ws_products(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ws_product_option_choices (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  option_id   VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,                 -- 'white', 'mayo'
  label       VARCHAR(120) NOT NULL,                 -- 'Mayonnaise'
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,       -- +€ ajouté au prix
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_choice_option (option_id),
  CONSTRAINT fk_choice_option FOREIGN KEY (option_id) REFERENCES ws_product_options(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 2. Menus / formules (« bundles »)

```sql
CREATE TABLE ws_menus (
  id             VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id     INT          NOT NULL,               -- produit de base (Sandwich Club)
  code           VARCHAR(40)  NOT NULL,               -- 'b-menu', 'b-full'
  name           VARCHAR(120) NOT NULL,               -- 'Menu', 'Full Menu'
  description    VARCHAR(250) NULL,                   -- '1 Sandwich + 1 boisson'
  price_modifier DECIMAL(8,2) NOT NULL DEFAULT 0,     -- +€ ajouté au prix de base
  recommended    TINYINT(1)   NOT NULL DEFAULT 0,
  advantages     JSON         NULL,                   -- ['Économisez 1,00 €', …]
  sort_order     INT          NOT NULL DEFAULT 0,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_menu (product_id, code),
  KEY idx_menu_product (product_id),
  CONSTRAINT fk_menu_product FOREIGN KEY (product_id) REFERENCES ws_products(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ws_menu_slots (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  menu_id     VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,                  -- 'drink', 'dessert'
  label       VARCHAR(120) NOT NULL,                  -- 'Boisson', 'Dessert'
  required    TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order  INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slot (menu_id, code),
  KEY idx_slot_menu (menu_id),
  CONSTRAINT fk_slot_menu FOREIGN KEY (menu_id) REFERENCES ws_menus(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ws_menu_slot_choices (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  slot_id     VARCHAR(36)  NOT NULL,
  code        VARCHAR(40)  NOT NULL,                  -- 'd1', 's2'
  label       VARCHAR(120) NOT NULL,                  -- 'Limonade maison'
  image       VARCHAR(250) NULL,
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,        -- +€ (ex. limonade +0.50)
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_slotchoice_slot (slot_id),
  CONSTRAINT fk_slotchoice_slot FOREIGN KEY (slot_id) REFERENCES ws_menu_slots(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 3. Upsells (suppléments optionnels)

```sql
CREATE TABLE ws_product_upsells (
  id          VARCHAR(36)  NOT NULL PRIMARY KEY,
  product_id  INT          NOT NULL,
  code        VARCHAR(40)  NOT NULL,                  -- 'salad', 'soup'
  label       VARCHAR(120) NOT NULL,                  -- 'Petite salade'
  image       VARCHAR(250) NULL,
  price_delta DECIMAL(8,2) NOT NULL DEFAULT 0,        -- +€ (ex. salade +4.50)
  sort_order  INT          NOT NULL DEFAULT 0,
  KEY idx_upsell_product (product_id),
  CONSTRAINT fk_upsell_product FOREIGN KEY (product_id) REFERENCES ws_products(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

*(`ws_products.has_menu_options TINYINT(1)` indique qu'un produit a des menus.)*

## 4. Ce qui est choisi est enregistré sur la ligne de commande

```sql
ALTER TABLE ws_order_lines
  ADD COLUMN menu_id          VARCHAR(36) NULL,   -- formule choisie (NULL = produit simple)
  ADD COLUMN selected_options JSON        NULL,   -- {"bread":"white","sauce":"mayo"}
  ADD COLUMN selected_slots   JSON        NULL,   -- {"drink":"d2","dessert":"s1"}
  ADD COLUMN selected_upsells JSON        NULL;   -- ["salad"]
```

## 5. Calcul du prix d'une ligne

```
prix_ligne = prix_produit
           + Σ price_delta(options choisies)
           + price_modifier(menu choisi, si menu)
           + Σ price_delta(choix de créneaux du menu)
           + Σ price_delta(upsells choisis)
```
Ex. Sandwich Club (9,50) → **Full Menu** (+5,50) + Mayonnaise (+1,00) +
Limonade (+0,50) = **16,50 €**.

## 6. Exemple (Sandwich Club)

```sql
-- Option "Sauce" avec 3 choix
INSERT INTO ws_product_options (id, product_id, code, label, kind, required)
  VALUES ('opt-club-sauce', 20, 'sauce', 'Sauce', 'single', 1);
INSERT INTO ws_product_option_choices (id, option_id, code, label, price_delta) VALUES
  ('ch-oil','opt-club-sauce','oil','Huile d''olive',0),
  ('ch-mayo','opt-club-sauce','mayo','Mayonnaise',1.00),
  ('ch-and','opt-club-sauce','andalouse','Andalouse',1.00);

-- Formule "Full Menu" (+5,50) recommandée, avec créneaux boisson + dessert
INSERT INTO ws_menus (id, product_id, code, name, description, price_modifier, recommended, advantages)
  VALUES ('b-full', 20, 'b-full', 'Full Menu', '1 Sandwich + 1 boisson + 1 dessert',
          5.50, 1, JSON_ARRAY('Économisez 2,50 €','Boisson + dessert inclus'));
INSERT INTO ws_menu_slots (id, menu_id, code, label, required)
  VALUES ('slot-full-drink','b-full','drink','Boisson',1),
         ('slot-full-dessert','b-full','dessert','Dessert',1);
INSERT INTO ws_menu_slot_choices (id, slot_id, code, label, image, price_delta) VALUES
  ('d2','slot-full-drink','d2','Limonade maison','img/lemonade-soda.png',0.50),
  ('s1','slot-full-dessert','s1','Cookie','img/cookies.png',0);
```

## 7. Correspondance avec les champs du front (DATA_SHAPES)

| Front (produit) | Tables |
|---|---|
| `options[]` (`id,label,required,kind,choices[]`) | `ws_product_options` + `ws_product_option_choices` |
| `available_bundles[]` (`id,name,description,price_modifier,advantages,recommended`) | `ws_menus` |
| bundle `slots[]` (`id,label,required,choices[]`) | `ws_menu_slots` + `ws_menu_slot_choices` |
| slot choice `img`, `delta` | `ws_menu_slot_choices.image`, `.price_delta` |
| `upsells[]` (`id,label,img,delta`) | `ws_product_upsells` |
| `has_menu_options` | `ws_products.has_menu_options` |

## Notes d'intégration

- **Source de vérité** : si les menus sont gérés dans **Franchise Buddy**, ces
  tables vivent dans l'ERP et sont synchronisées ; l'endpoint catalogue doit
  renvoyer `options` / `available_bundles` / `upsells` imbriqués par produit.
- **WooCommerce** n'a pas d'équivalent natif : ces structures se stockent en
  méta de produit (`_atelier_options`, `_atelier_menus`, `_atelier_upsells`)
  et le pont les ressert dans la forme attendue par le front.
- **Codes vs IDs** : `code` est l'identifiant stable côté métier (ex. `mayo`) ;
  l'`id` est la clé technique. Le front n'utilise que les `code`/`id` pour
  reconstruire la sélection sur la commande.
