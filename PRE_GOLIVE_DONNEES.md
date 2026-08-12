# Avant le go-live : données à compléter

Tout ce qui suit est de la **donnée**, pas du code. Les fonctionnalités
correspondantes sont câblées, testées, et se comportent correctement face à ces
manques : le produit concerné est masqué, la commande refusée, ou l'information
affichée comme « non renseignée ». **Rien n'est inventé pour masquer le trou** —
c'est la règle du projet.

Chiffres relevés sur la base de production (`atelierby_db`).

---

## 1. Prix ERP manquants ⚠️ bloquant commercial

Le webshop facture **exclusivement** le prix ERP `shop_product.portion_price`.
Sans prix strictement positif, le produit est **masqué du catalogue** et la
commande **refusée** (message nommant le produit).

| Boutique | Produits visibles | Sans ligne de prix | Lignes de prix à **0** |
|---|---|---|---|
| 2 · Corbais | 458 | 56 | 56 |
| 3 · Gosselies | 458 | 54 | 69 |
| 4 · Halle | 458 | 63 | 93 |
| 5 · Sombreffe | 458 | 39 | 108 |
| 10 · Gembloux (hors ligne) | — | — | 350 / 350 |

**Priorité absolue : les produits OBLIGATOIRES sans prix — 188 couples
produit × boutique.** La marque les impose, mais aucun prix ne les rend
vendables.

```sql
-- Liste de travail : obligatoires sans prix ERP, par boutique
SELECT s.id AS boutique, s.city,
       p.id AS produit, TRIM(REGEXP_REPLACE(p.name,'[[:space:]]+',' ')) AS nom
  FROM atelierby_db.shops s
  JOIN atelierby_db.ws_products p ON p.active = 1 AND p.brand_mandatory = 1
  LEFT JOIN atelierby_db.shop_product sp
         ON sp.id_product = p.id AND sp.id_shop = s.id AND sp.portion_price > 0
 WHERE s.active = 1 AND s.webshop_enabled = 1 AND sp.id_product IS NULL
 ORDER BY s.id, nom;
```

Les gammes les plus touchées : **Tartes Ø 28 cm** (`67002xx`), pâtisseries
individuelles (`153xxxx`), quelques plats traiteur. Deux cas faciles à traiter :
les « Plat du jour : 11,90 € » et « 12,90 € » de Gosselies, dont le prix est
écrit dans le nom.

**Un prix à 0 signifie « non fixé », pas « gratuit »** — c'est ce qui a été
corrigé côté code : auparavant une commande sur ces produits passait à 0,00 €.

---

## 2. Prix de référence marque non renseignés

`ws_products.price` est le **prix conseillé réseau**, éditable dans la console
marque (écran Prix de référence). Il vaut encore **1,00 €** (valeur de test) sur
une grande partie du catalogue, alors que les boutiques vendent jusqu'à 21,90 €.

**Aucun impact client** (le webshop ignore ce champ depuis l'unification des
prix), mais la marque pilote à l'aveugle : elle ne peut pas comparer son prix
conseillé au prix pratiqué.

```sql
SELECT id, TRIM(REGEXP_REPLACE(name,'[[:space:]]+',' ')) AS nom, price
  FROM atelierby_db.ws_products
 WHERE active = 1 AND price = 1.00
 ORDER BY nom;
```

---

## 3. Contours des codes postaux : granularité communale

La carte des zones de chalandise affiche de vrais polygones (contours communaux
officiels Statbel, 1145 codes postaux couverts), mais **782 codes postaux sur
1145 partagent le contour de leur commune** — seuls 363 ont un contour exact.
La carte l'annonce explicitement dans un bandeau.

**Pour obtenir le contour exact de chaque code postal** : télécharger les
secteurs statistiques (gratuit) sur
<https://statbel.fgov.be/fr/open-data/secteurs-statistiques> puis lancer

```bash
python3 php-api/tools/build_postcode_polygons.py \
  --sectors sh_statbel_geojson/<fichier>.geojson \
  --dict be-dictionary.csv --gzip \
  --out php-api/data/zipcodes_be_polygons.geojson
```

Le fichier écrase le précédent au même emplacement, **sans redéploiement**.
⚠️ Statbel est injoignable depuis le serveur (connexion réinitialisée) : le
téléchargement doit se faire depuis un poste, puis transfert par WinSCP.

---

## 4. Allergènes : recettes ERP non évaluées

Le catalogue distingue trois états : allergènes connus / recette évaluée sans
allergène / **non renseigné**. Ce dernier cas s'affiche « Allergènes non
renseignés — renseignez-vous en boutique » : plus aucun produit n'est présenté
comme « sans allergène » à tort (c'était le cas d'environ 100 produits, dont des
bûches, entremets, quiches et pains).

Le modèle ERP fonctionne (751 produits, 433 avec recette, 197 matières liées à
un allergène, 14 allergènes déclarés) : la couverture est simplement partielle.

```sql
-- Produits visibles sans allergène renseigné, par catégorie
SELECT c.label AS categorie, COUNT(*) AS nb
  FROM atelierby_db.ws_products p
  JOIN atelierby_db.ws_categories c ON c.id = p.cat_id
  LEFT JOIN atelierby_db.ws_product_shops ps ON ps.product_id = p.id AND ps.shop_id = 2
  JOIN atelierby_db.product e ON e.id = p.id
 WHERE p.active = 1 AND (ps.product_id IS NULL OR ps.active = 1)
   AND (e.id_recipe IS NULL OR e.id_recipe = 0
        OR NOT EXISTS (SELECT 1 FROM atelierby_db.flattened_recipe_ingredient fri
                        WHERE fri.id_recipe = e.id_recipe))
 GROUP BY c.label ORDER BY nb DESC;
```

**Enjeu réglementaire** : à traiter en priorité sur les catégories à risque
(pâtisserie, boulangerie, traiteur, quiches).

---

## 5. Paramètres de coût (rentabilité)

Les écrans de rentabilité affichent désormais **coût et marge à « non
calculable »** tant que les paramètres de coût sont absents, au lieu de les
compter à 0 (ce qui gonflait la marge).

Paramètres attendus dans `ws_param` : `cost_prep_per_order`,
`cost_packaging_unit`, `cost_fuel_per_km`, `cost_struct_per_tour`,
`cost_labor_per_tour`.

```sql
SELECT param_key, param_value FROM atelierby_db.ws_param
 WHERE param_key LIKE 'cost_%' ORDER BY param_key;
```

---

## 6. Rotation du jeton admin 🔐

Le jeton admin (`admin_token` dans `api/config.php`) a transité par un log
GitHub Actions lors de sa récupération. Il donne **tous les droits** sur les deux
back-offices.

**À faire avant l'ouverture au public** : générer une nouvelle valeur, la poser
dans `api/config.php`, puis rouvrir les back-offices une fois avec
`?token=<nouveau>`. Les comptes tablette (PIN) ne sont pas affectés.

---

## 7. Points à confirmer (comportement voulu ou non ?)

| Constat | Question |
|---|---|
| Boutique 2 : **68 produits retirés** de l'assortiment (`ws_product_shops.active=0`), tous **non obligatoires** | Réduction d'assortiment volontaire du franchisé ? (la signature va dans ce sens : aucun obligatoire touché) |
| `ws_tour_tracking` **vide** | Aucune tournée encore roulée, ou écriture des positions non branchée ? À trancher par le test du dispatch tablette |
| Produits en doublon de nom : `115xxxx` « Tartes » et `67xxxxx` « Tartes – Ø 28 cm » | Deux formats volontaires (confirmé) — vérifier que les deux ont bien un prix, sinon un seul format sera visible |
| Seuls **6 clients sur 10 708** ont un `office_id` | Normal en phase de démarrage B2B ? |
| **1 prospect** non rattaché (`client.id_main_shop = 0`) | À traiter dans l'écran Prospect |

---

## Ce qui ne bloque plus (corrigé pendant la campagne)

- prix de test à 1 € facturés au client → source unique ERP
- prix ERP à 0 facturés **gratuitement** → refus explicite
- « sans allergène » affirmé à tort → état « non renseigné »
- stock épuisé ne bloquant pas la vente → contrat d'API corrigé
- formules vides facturées avec supplément → non proposées
- boutiques de démonstration servies par le cache PWA → purge + fraîcheur garantie
- console marque déployant une version du 22 juillet → workflow corrigé
- KPIs affichant « 0 k€ » pour 343,58 € de CA réel → montants adaptatifs
- écrans muets en cas d'échec → bandeau « erreur, please debug » partout
