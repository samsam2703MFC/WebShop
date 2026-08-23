# Audit i18n / multilingue — dépôt `webshop`

> Audit **en lecture seule**. Aucun fichier existant n'a été modifié ; ce rapport est
> le seul fichier écrit. Périmètre : dépôt `webshop` entier.
> Date : 17/08/2026 · dernier commit audité : `45710dc`.

**RÈGLE retenue comme unique critère de jugement**
> Aucun texte visible par un client ne vit en dur dans le code. Tout libellé
> d'interface vient de la table `ws_i18n` (servie par `GET /i18n`) ; tout libellé de
> contenu (produit, catégorie) vient d'un alias ERP résolu côté serveur. Quand une
> traduction manque, le repli est le texte source réel — jamais une clé nue, jamais
> un champ vide, jamais une donnée inventée.

---

## 1. Synthèse

**Compteurs : 6 conformes · 9 à adapter · 6 manquants · 233 violations brutes**

- 233 littéraux français en dur dans le code servi au client, répartis sur 18 fichiers
  (`webshop-full-bundle.jsx:198` → `:5782`, plus 17 modules API). Mesure : commentaires
  exclus, chaînes purement techniques exclues.
- 400 clés en base, 304 référencées par le code, **79 jamais référencées**.
- Couverture NL des clés existantes : **100 %** (aucune clé sans valeur `nl`).

**Les 5 chantiers prioritaires**

| # | Chantier | Conséquence si on ne fait rien |
|---|---|---|
| 1 | Le catalogue reste 100 % français : `ws_param.erp_api_base` n'est pas renseigné (`php-api/erp_alias.php:24`) | Halle ouvre en NL avec **tous les noms de produits en français** — l'incohérence la plus visible pour le client |
| 2 | L'e-mail de confirmation de commande est en français en dur (`php-api/lib.php:108`) | Un client néerlandophone commande en NL et **reçoit un e-mail français** : le seul document qu'il garde |
| 3 | La langue n'accompagne pas la commande (`webshop-orders-api.jsx` — aucun `lang`) | `ws_orders.lang` vaut toujours `'fr'` (`php-api/index.php:2797`) : impossible de rattraper l'e-mail ou le ticket plus tard |
| 4 | ~90 messages d'erreur et de validation en dur dans le bundle (`webshop-full-bundle.jsx:2427` → `:5782`) | Le client néerlandophone bascule en français **dès qu'un problème survient** — au pire moment |
| 5 | Aucun garde-fou : ni test i18n, ni règle CI (`.github/workflows/tests.yml:1-140`) | Chaque contribution rajoute du texte en dur ; l'écart se rouvre en quelques semaines |

---

## 2. Comment ce repo est organisé (avec chemins)

1. **Front livré au client** : `src/main.jsx` importe 31 modules racine dans un ordre précis
   (`src/main.jsx:7-46`) ; c'est **la seule liste qui fait foi** pour savoir ce qui part en prod.
2. **Cœur applicatif** : `webshop-full-bundle.jsx` (~5 800 lignes) — toute la boutique, du
   bandeau au tunnel de commande, monté par `ReactDOM.createRoot` en fin de fichier.
3. **Modules API** : `webshop-*-api.jsx` — un par domaine (catalogue, auth, commandes,
   bons, tournées…), chacun expose `window.WSXxx` avec un `.endpoint`.
4. **Câblage des endpoints** : `api-config.js:30-53` calcule `BASE_URL` sur l'origine courante
   et pose `.endpoint` sur chaque module ; c'est aussi là que l'i18n est chargée (`:51`).
5. **i18n** : `webshop-i18n.jsx` (noyau, zéro chaîne) + `webshop-i18n-react.jsx`
   (hook `useT`, `LangChip`) + helpers `wsUseT`/`tRich` dans le bundle (`:261`, `:240`).
6. **API PHP** : `php-api/index.php` (~11 200 lignes, routeur `dispatch()`), `php-api/lib.php`
   (DB, mail), `php-api/erp_alias.php` (alias ERP).
7. **Traductions** : 11 migrations `php-api/migrations/00{86,88..97}*.sql` remplissent `ws_i18n`.
8. **Build** : Vite, base `/WebShop/` par défaut (`vite.config.js:8`), PWA via `vite-plugin-pwa`.
9. **CI** : `.github/workflows/tests.yml` (migrations + tests métier PHP/Node),
   `deploy-sftp.yml` (build, rsync, `migrate.sh`, vérifications post-déploiement).
10. **Hors périmètre de jugement** : `node_modules/`, `dist/`, `uploads/`, `fonts/`, `img/`,
    et les fichiers racine **non importés** par `src/main.jsx` (`app.jsx`, `admin-*.jsx`,
    `tournee-*.jsx`, `links.jsx`, `design-canvas.jsx`) — ils ne sont pas servis au client.

---

## 3. Signaux retenus pour cet audit

Ce qui constitue une violation **dans ce dépôt** :

| Signal | Comment il se repère |
|---|---|
| S1 | Littéral quoté contenant un caractère accentué ou un mot français courant, hors commentaire, dans un fichier importé par `src/main.jsx` |
| S2 | Attribut `placeholder=` / `title=` / `aria-label=` / `alt=` avec une valeur littérale |
| S3 | `toLocaleDateString(` / `toLocaleTimeString(` / `Intl.NumberFormat(` avec une locale littérale (`'fr-BE'`) au lieu de `wsLocale()` |
| S4 | Texte composé **côté serveur** (`php-api/`) et renvoyé au client sans passer par `ui_t()` |
| S5 | Clé appelée par le code mais absente des migrations → clé brute à l'écran |
| S6 | Clé définie en base et jamais référencée → mort probable |
| S7 | Même clé + même langue définie dans deux migrations → valeur finale dépendante de l'ordre |
| S8 | Valeur `fr` identique à la valeur `nl` → traduction possiblement oubliée |
| S9 | Besoin de traduction sans mécanisme du tout (e-mails, PDF, métadonnées, pages statiques) |

**Exclusions explicites, et pourquoi** : `node_modules/`, `dist/` (artefacts de build),
`uploads/`, `img/`, `fonts/` (binaires) ; les **migrations** ne sont pas jugées au titre de S1
— elles *sont* le magasin de traductions ; les fichiers racine non importés par `src/main.jsx`
(voir §2.10) ; `php-api/tests/` (jeux d'essai) ; les messages `console.*` purement techniques
sont relevés mais classés P3.

---

## 4. Tableau principal

| # | Module | Cas d'usage / besoin | Constat (fichier:ligne) | Verdict | Ce qu'il faut faire | Effort | Prio | Conséquence si on ne fait rien |
|---|---|---|---|---|---|---|---|---|
| 1 | Catalogue | Noms de produits et catégories dans la langue du client | `php-api/erp_alias.php:24` lit `ws_param.erp_api_base`, non renseigné en prod ; `php-api/index.php:620` (probe) répond `configure:false` | **À ADAPTER** | Renseigner `erp_api_base` + `erp_api_token` (API vérifiée joignable, schéma `Authorization: Bearer`) | S | **P1** | Boutique NL avec catalogue FR : incohérence visible sur chaque vignette |
| 2 | E-mail client | Confirmation de commande dans la langue du client | `php-api/lib.php:108` sujet et corps FR en dur ; `php-api/lib.php:103` ne reçoit pas de langue | **MANQUANT** | Passer la langue à `send_order_email()` et composer via `ui_t()` ou `ws_email_templates` (colonne `lang` déjà présente, `php-api/index.php:4599`) | M | **P1** | Le client NL reçoit son seul document écrit en français |
| 3 | Commande | Mémoriser la langue de la commande | `php-api/index.php:2797` écrit `'lang' => $b['lang'] ?? 'fr'` ; `webshop-orders-api.jsx` n'envoie jamais `lang` | **MANQUANT** | Ajouter `lang` au corps POST `/orders` | S | **P1** | `ws_orders.lang` toujours `'fr'` : aucun rattrapage possible (e-mail, ticket, facture) |
| 4 | Bundle storefront | Messages d'erreur et validations de formulaire | 90 littéraux, ex. `webshop-full-bundle.jsx:2427`, `:2459`, `:3048`, `:3104`, `:3192`, `:4448` | **À ADAPTER** | Clés `err.*` / `valid.*` + migration | M | **P1** | Retour au français dès qu'une erreur survient |
| 5 | Bundle storefront | Explications de bascule de mode (livraison) | `webshop-full-bundle.jsx:5627-5760` (12 toasts : cut-off, bureau non lié, panier vidé…) | **À ADAPTER** | Clés `toast.*`, phrases entières avec variables | M | **P1** | Le refus de la livraison n'est pas compris par un client NL |
| 6 | Modules API | Messages d'indisponibilité affichés au bandeau | 17 fichiers, ex. `webshop-auth-api.jsx:44-289` (27), `webshop-catalog-api.jsx:45`, `webshop-vies.jsx:47-63`, `webshop-tours-api.jsx:22-35` | **À ADAPTER** | Retourner un **code** (`err.network`) et traduire à l'affichage, pas une phrase FR | M | P2 | Bandeau d'erreur en français sur une boutique NL |
| 7 | Bundle storefront | Dates, heures et montants localisés | `webshop-full-bundle.jsx:2057` (`Intl.NumberFormat('fr-BE')`), `:2642`, `:3440` (`toLocaleDateString('fr-BE')`) | **À ADAPTER** | Utiliser `wsLocale()` (`webshop-full-bundle.jsx:234`), déjà en place ailleurs | S | P2 | « 16 août » et séparateurs FR au milieu d'un écran NL |
| 8 | VIES | Messages de validation TVA | `webshop-vies.jsx:47`, `:49`, `:51`, `:58`, `:63` | **À ADAPTER** | Codes + traduction à l'affichage | S | P2 | Écran société en français pour un client NL |
| 9 | Paiement | Libellés de moyens de paiement hors `/payment-methods` | `webshop-full-bundle.jsx:3648` (`Sur compte (différée)`, `Paiement direct`), `:4222`, `webshop-delivery-fees-api.jsx:115` | **À ADAPTER** | Réutiliser les clés `pay.*` (`php-api/migrations/0096_i18n_serveur.sql:15`) | S | P2 | Deux libellés différents pour le même moyen selon l'écran |
| 10 | Page & PWA | Titre, description, `lang`, manifeste | `index.html:2` `lang="fr"`, `:5` titre FR ; `webshop-full.html:7` description FR ; `vite.config.js:23-26` manifeste `lang: 'fr'` | **À ADAPTER** | `lang` piloté par `WSI18n` ; titre/description traduits ; manifeste neutre ou par langue | S | P3 | Partages, onglet et écran d'accueil PWA en français |
| 11 | Pages statiques | Landing « livraison au bureau », inscription | ouvertes depuis `webshop-full-bundle.jsx:562` et `:3516` ; `public/inscription.html` | **MANQUANT** | Version NL ou paramètre de langue | M | P2 | Le client NL clique et tombe sur une page française |
| 12 | Administration | Éditer les traductions sans SQL | aucun écran ; seule écriture = migrations `php-api/migrations/0086..0097` | **MANQUANT** | Écran ou endpoint d'édition `ws_i18n` | M | P2 | Toute correction de libellé exige un développeur et un déploiement |
| 13 | Qualité | Garde-fou contre le texte en dur | `.github/workflows/tests.yml:1-140` : aucun test i18n | **MANQUANT** | Test « aucune clé appelée absente » + compteur de littéraux FR | S | P2 | L'écart se rouvre à chaque contribution |
| 14 | Base de clés | Hygiène du magasin de traductions | 79 clés jamais référencées (`basket.*` 13, `checkout.*` 16, `pdm.* `11, `profile.*` 8…) ; 4 redéfinitions `nav.mode.*` entre `0086` et `0088` | **À ADAPTER** | Marquer/retirer les clés mortes ; une clé = une migration | S | P3 | Le traducteur travaille sur des clés invisibles ; valeur finale dépendante de l'ordre des migrations |
| 15 | Base de clés | Valeur FR = valeur NL (12 cas) | dont `pd.bestOption` **FR = « Best option »** (anglais) `php-api/migrations/0091_i18n_compte.sql:17` | **À ADAPTER** | Corriger `pd.bestOption` FR ; valider les 11 autres (`Gluten`, `Peppol`, `Formule`… souvent légitimes) | S | P3 | Un libellé anglais s'affiche en français |
| 16 | Noyau i18n | Charger les libellés depuis la table | `webshop-i18n.jsx:132-146` (chargement + cache local + `WSBug.note`) | **CONFORME** | — | — | — | — |
| 17 | Langue par boutique | Halle ouvre en néerlandais | `php-api/migrations/0087_shops_langue.sql`, servi par `php-api/index.php:560`, appliqué par `webshop-i18n.jsx:150` (`applyShop`) | **CONFORME** | — | — | — | — |
| 18 | Sélecteur client | Changer de langue depuis la boutique | `webshop-i18n-react.jsx:33-92`, visible en mobile depuis `webshop.css:209` | **CONFORME** | — | — | — | — |
| 19 | Storefront | Libellés des écrans (nav, panier, tunnel, compte, fiche, allergènes) | 304 clés référencées, 0 manquante (contrôle rejouable, §7) | **CONFORME** | — | — | — | — |
| 20 | Serveur | Libellés composés côté serveur (paiement, bons) | `php-api/index.php:10418` (`ui_t`), `:10439` (`payment_label`) | **CONFORME** | — | — | — | — |
| 21 | Réglementaire | 14 allergènes (règlement UE 1169/2011) | `webshop-allergens.jsx:49` (`tAllergen`), `php-api/migrations/0093_i18n_allergenes.sql` | **CONFORME** | — | — | — | — |

---

## 5. Violations à supprimer — un bloc par occurrence

### V1 — Locale figée `fr-BE` (3 occurrences)
- `webshop-full-bundle.jsx:2057` — `Intl.NumberFormat('fr-BE', …)` : formatage des montants
  du bandeau cadeau. Appelé par `GiftProgressBanner`.
- `webshop-full-bundle.jsx:2642` — `toLocaleDateString('fr-BE')` + `toLocaleTimeString('fr-BE')` :
  date des achats. Appelé par `AccountPurchases`.
- `webshop-full-bundle.jsx:3440` — `toLocaleDateString('fr-BE')` : date de liaison fidélité.
- Le helper correct existe déjà : `wsLocale()` `webshop-full-bundle.jsx:234`, utilisé en
  `:262` et `:4361`. **La règle est donc connue du dépôt et contournée à 3 endroits.**

### V2 — Libellé FR renseigné en anglais
- `php-api/migrations/0091_i18n_compte.sql:17` — `('ui','pd.bestOption','fr','Best option')`.
  Affiché par `webshop-full-bundle.jsx:1311` sur la formule recommandée. Un client
  francophone lit « Best option ».

### V3 — Redéfinition de clés entre migrations
- `nav.mode.collect` et `nav.mode.delivery`, `fr` et `nl`, définies dans
  `php-api/migrations/0086_ws_i18n.sql` **et** `php-api/migrations/0088_i18n_nav.sql:12-15`.
  `0088` utilise `ON DUPLICATE KEY UPDATE` : le résultat est correct **aujourd'hui**, mais
  la valeur finale dépend de l'ordre d'exécution — non garanti par le nom de fichier seul.

### V4 — Reste de démonstration
- `webshop-promo-api.jsx:34` — chaîne `'Été gourmand'`. À vérifier : nom de campagne
  résiduel dans un module servi au client (`src/main.jsx:20`).

---

## 6. Existant à adapter — un bloc par élément

### E1 — Messages des modules API (17 fichiers, ~70 chaînes)
- **Ce qui cloche** : chaque module renvoie une **phrase française** en cas d'échec, ex.
  `webshop-auth-api.jsx:56` « Réseau indisponible — connexion impossible. »,
  `webshop-catalog-api.jsx:45`, `webshop-orders-api.jsx:63`, `webshop-tours-api.jsx:22`.
  Ces phrases remontent au bandeau d'erreur (`webshop-bug-banner.jsx:63` (`note`)), **visible du client**.
- **Ce qui change** : le module renvoie un **code** (`{ code: 'err.network' }`) ; la traduction
  se fait à l'affichage via `t()`.
- **Qui est impacté** : tous les appelants qui affichent `e.message` — notamment
  `webshop-full-bundle.jsx:2454`, `:3056`, `:3192`.

### E2 — Messages d'erreur et validations du bundle (~90 chaînes)
- **Ce qui cloche** : validations de formulaire (`:2427`, `:2439`, `:2466`, `:3104`, `:3105`),
  échecs d'enregistrement (`:3192`, `:3195`), erreurs de bons/cadeaux (`:4435`-`:4448`).
- **Ce qui change** : clés `valid.*` / `err.*`, mêmes conventions que l'existant
  (pluriels en clés distinctes, variables `{…}` substituées après traduction).
- **Qui est impacté** : `LoginModal`, `AccountModal`, `CheckoutWizard`.

### E3 — Toasts de bascule de mode (12 messages)
- **Ce qui cloche** : `webshop-full-bundle.jsx:5627-5760` — ce sont les textes qui **expliquent
  un refus** (heure limite dépassée, bureau non validé, panier vidé). Les plus utiles à traduire.
- **Ce qui change** : clés `toast.*` avec titre + corps séparés.
- **Qui est impacté** : `handleMode` et les effets de changement de date/boutique.

### E4 — Clés mortes (79) et magasin de traductions
- **Ce qui cloche** : `basket.*` (13), `checkout.*` (16), `pdm.* `(11), `profile.*` (8) et
  `card.*` (5) viennent de `0086_ws_i18n.sql`, écrit **avant** le câblage réel ; les écrans
  utilisent d'autres préfixes (`cart.*`, `co.*`, `pd.*`, `acc.*`).
- **Ce qui change** : soit supprimer, soit marquer `scope='deprecated'` pour ne plus les
  présenter au traducteur.
- **Qui est impacté** : personne à l'écran — mais un traducteur perd 20 % de son temps.

### E5 — Métadonnées de page et manifeste PWA
- **Ce qui cloche** : `index.html:2` fixe `lang="fr"` alors que `webshop-i18n.jsx:186`
  positionne dynamiquement `document.documentElement.lang` — l'attribut initial est donc faux
  jusqu'au premier rendu. `vite.config.js:26` fixe `lang: 'fr'` dans le manifeste.
- **Ce qui change** : `lang` initial neutre, titre/description traduits au boot.
- **Qui est impacté** : SEO, partage de lien, écran d'accueil de la PWA installée.

---

## 7. Manques transverses (contrat, convention, doc, tests)

1. **Aucun test i18n** — `.github/workflows/tests.yml:134-140` couvre visibilité, totaux,
   promotions. Deux contrôles peu coûteux manquent, alors qu'ils sont **rejouables tels quels** :
   - toute clé appelée par le code existe en base (le contrôle a été exécuté pour cet audit :
     304 appelées, 0 manquante) ;
   - le nombre de littéraux français dans les fichiers de `src/main.jsx` ne **remonte** pas.
2. **Aucune convention écrite** — les préfixes (`cart.`, `co.`, `acc.`, `pd.`, `off.`) ne sont
   documentés nulle part ; `HANDOFF.md:26` décrit encore `webshop-i18n.jsx` comme portant les
   « translations », ce qui n'est plus vrai depuis que la table est la source (`webshop-i18n.jsx:14`).
3. **Contrat d'erreur non défini** — aucun format commun pour les échecs des modules API ;
   d'où E1. Rien dans `API.md` ne fixe ce contrat.
4. **Ajout d'une langue non documenté** — `webshop-i18n.jsx:19` fixe `SUPPORTED = ['fr','nl']`
   **en dur dans le code** : ajouter l'anglais demande une modification de code, alors que
   `shops.languages` (`0087_shops_langue.sql`) laisse croire que c'est un paramètre.
5. **Relecture native** — les valeurs `nl` de 10 des 11 migrations sont une traduction produite
   par l'assistant, non relue par un néerlandophone. Seule exception documentée : les
   dénominations d'allergènes, reprises du règlement UE (`0093_i18n_allergenes.sql:4-8`).
6. **Pas de versionnement des traductions** — `ws_i18n` n'a pas de colonne d'origine ni de
   statut (`0086_ws_i18n.sql:26-33` : `scope, k, lang, value, updated_at`) : impossible de
   distinguer une valeur relue d'une valeur provisoire.

---

## 8. Zones non couvertes par l'audit

- **Console franchisé et console marque** — hors périmètre choisi ; elles affichent pourtant
  des libellés issus des mêmes tables.
- **Contenu réel de la base de production** — je n'ai pas d'accès direct ; les compteurs de
  clés viennent des **migrations**, pas d'un `SELECT`. Une valeur éditée à la main en base
  n'apparaît donc pas ici.
- **Forme réelle des alias ERP** — `GET /api/v1/products/aliases` répond `401` sans jeton
  (vérifié) ; la structure du JSON n'a pas pu être observée. `php-api/erp_alias.php:113-175`
  reconnaît trois formes usuelles ; si l'API en renvoie une quatrième, la traduction du
  catalogue restera silencieusement vide (la sonde `/erp/probe` le dira).
- **PDF (tickets, factures)** — `php-api/pdf.php` n'a pas été analysé ligne à ligne.
- **`franchise-buddy/`, `backend/`, `deploy/`** — non importés par le front livré.
- **Qualité linguistique du néerlandais** — cet audit vérifie la *mécanique*, pas la justesse
  des traductions.
- **Accessibilité multilingue** (annonces lecteur d'écran, `aria-live` traduits) — non évaluée.
