# Office Delivery — delivery sites & company departments (SPEC / à faire)

> **Statut : NON COMMENCÉ.** Note de conception à garder pour la suite. Le
> back-office **franchisé** n'a pas encore été attaqué. Ce document fige le
> besoin, le modèle de données connu, et surtout ce qu'il **manque** avant de
> pouvoir construire. Ne rien déployer tant que les schémas manquants ne sont
> pas fournis.

## Besoin (prompt utilisateur, 2026-07-19)

Dans **Office Delivery**, on choisit le bureau (`ws_office`). Si ce bureau a un
numéro de client (`client_id`, cas **B2B** — il doit **obligatoirement** en
avoir un), on s'appuie sur `b2b_client_company_department` pour rattacher
certains **départements** à un bureau, afin de faciliter la gestion.

Logique à couvrir :
- une **société** peut avoir **plusieurs delivery sites** ;
- chaque **delivery site** peut avoir **plusieurs départements**.

### Back-office webshop **franchisé** + landing
1. Dans le **formulaire d'édition client**, pouvoir **ajouter / éditer un
   delivery site** (relié à une **tournée**, etc.).
2. Pouvoir **ajouter des départements** à un delivery site (ex. : marketing,
   bureau, production). Chaque département est **propre à l'entreprise** → le
   formulaire prévoit un petit **« + »** pour créer un département au besoin.
3. Sur la **vue du delivery site** (back-office franchisé), afficher **toute la
   ligne logique complète** :
   `tournée → zone primaire → zone secondaire → delivery site → office → département`.

### Front-end **PWA** + **webshop**
- Si l'entreprise (`office`) a des sections / départements, cela devient un
  **paramètre à remplir dans le profil**, situé **juste en dessous de la
  liaison bureau**.
- **Seul le franchisé** peut **ajouter** un département (partie de l'onboarding
  B2B qu'il réalise avec chaque client `office`). Le client final ne fait que
  **choisir** parmi les départements existants.

## Modèle de données — connu

`b2b_client_company_department` (fournie, existe en prod) :
```
id         INT  PK AUTO_INCREMENT
id_client  INT  NOT NULL   -- la SOCIÉTÉ (client B2B), pas l'office
name       VARCHAR(255)    -- 'Marketing', 'Administration', 'Cuisine', ...
```
Exemple : id_client 4706 → Marketing / Administration / Cuisine.

→ Les départements sont définis **au niveau de la société** (`id_client`) et
sont **propres à l'entreprise**. Les départements disponibles pour un office se
déduisent donc de `WHERE id_client = office.client_id`.

`b2b_client_type` (fournie précédemment) : **≠ départements.** C'est le *type*
de client B2B (Club Sportif, Funérariums, Entreprise, Association, Agence de
communication, Traiteur). Sert au **ciblage `GROUP`** des bons (encore stubbé,
cf. VOUCHER_UNIFY_NOTES) — à ne pas confondre avec les départements internes.

## Ce qui MANQUE avant de construire (à demander)

Schémas / liens non encore fournis :
1. **`ws_office` / `office`** — colonnes, dont `client_id` (le lien société) et
   le lien vers le **delivery site**.
2. **`delivery site`** — table et colonnes (nom, adresse, lien tournée, lien
   office, lien société).
3. **`tournée`**, **`zone primaire`**, **`zone secondaire`** — tables + FK, pour
   pouvoir remonter la chaîne complète de l'ask #3.
4. **Rattachement département ↔ delivery site / office** : `b2b_client_company_department`
   ne porte que `id_client` (société). Si un département doit être **attaché à
   un delivery site précis** (pas juste « disponible pour la société »), il faut
   une **table de liaison** (`delivery_site_department` ou similaire) qui
   n'existe pas encore → à confirmer / créer.
5. Comment un **office** connaît son / ses **delivery site(s)** et sa société.

## Esquisse d'implémentation (une fois les schémas fournis)

- **API php-api (shared)** : endpoints franchisé
  - `GET  /office/:id/departments` → départements de la société de l'office.
  - `POST /office/:id/department` (franchisé only) → créer un département
    (`b2b_client_company_department`, `id_client = office.client_id`).
  - CRUD delivery site + rattachement tournée/zones.
- **Back-office franchisé** : formulaire client → sous-bloc *Delivery sites*
  (add/edit, lien tournée) → sous-bloc *Départements* avec bouton **+**. Vue
  delivery site = fil d'Ariane `tournée → zone1 → zone2 → delivery site →
  office → département`.
- **PWA / webshop** : sous la liaison bureau, si l'office a des départements,
  **sélecteur de département** (lecture seule côté client ; création = franchisé
  uniquement). Persister le département choisi sur la commande / le profil.
- **Migration** : la table existe déjà ; prévoir surtout un **index**
  `idx_dept_client (id_client)` et l'éventuelle table de liaison delivery-site.

## À ne pas oublier
- L'office B2B **doit** avoir un `client_id` (invariant à valider côté form).
- Départements **par société** → filtrer partout par `id_client`.
- Création **franchisé-only** → contrôle d'accès sur le POST.
