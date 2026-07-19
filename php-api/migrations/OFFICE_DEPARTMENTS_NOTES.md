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

## Onboarding « Créer un bureau » — parcours complet (prompt 2026-07-19, gros morceau)

> **Statut : NON COMMENCÉ.** À développer avec le back-office franchisé.

Le bouton **« Créer un bureau »** ne doit plus ouvrir un simple formulaire mais
lancer un **onboarding complet**, déclenché à chaque création de bureau, qui
couvre toute la chaîne de création **et de bon fonctionnement** d'un bureau.

### Étapes du parcours (assistant multi-étapes)
Chaque étape crée/relie une brique de la chaîne (réutiliser l'existant si déjà
créé, sinon créer à la volée) :
1. **Tournée** — choisir une tournée existante ou en créer une.
2. **Delivery site** — rattaché à la tournée (+ zones primaire/secondaire).
3. **Office** — le bureau lui-même, rattaché au delivery site ; `client_id`
   **obligatoire** (société B2B).
4. **Département(s)** — sélection parmi ceux de la société, avec **création à la
   volée** si nécessaire (`b2b_client_company_department`, `id_client` = société).
5. **Personne de contact** — nom, mail, téléphone (destinataire du récap).
6. **% sur le webshop** — remise boutique/office appliquée (cf. `shops.discount_*`
   / logique office).
7. **Voucher spécifique** — bon dédié à ce bureau → réutiliser le ciblage voucher
   déjà livré (`target_kind=OFFICE`, `target_id`=office) — cf. VOUCHER_UNIFY_NOTES.
8. **… (extensible)** — prévoir d'ajouter tout élément lié à la création et au
   bon fonctionnement d'un bureau (moyens de paiement, adresses de facturation,
   créneaux de livraison, etc.). Le parcours doit rester ouvert.

### Fin de parcours — mail récapitulatif
- Générer un **récap complet** (toutes les infos saisies : tournée, delivery
  site, office, départements, contact, %, voucher…).
- **Créer + envoyer** ce mail à la **personne de contact** (aperçu avant envoi ;
  réutiliser les modèles d'email transactionnels `ws_email_templates`).

### Gestion des mails du personnel de l'entreprise (adhésion collaborateurs)
- **Import CSV** des collaborateurs du bureau (mails, éventuellement nom/dépt).
- Chaque ligne envoie une **demande d'adhésion** au collaborateur.
- Le collaborateur entre **directement dans le process client**, mais avec :
  - son **office déjà lié**,
  - son **département déjà lié** le cas échéant,
  - un **voucher de bienvenue**.
- ⇒ prévoir : parsing/validation CSV, table/statut d'invitation (pending →
  accepted), lien d'inscription tokenisé pré-rempli (office/dépt), et le voucher
  de bienvenue (campagne dédiée, `target_kind=OFFICE` ou par collaborateur).

### Manques additionnels à obtenir avant ce chantier
- Schéma **création tournée / zones** (pas seulement lecture).
- Modèle **contact bureau** (table dédiée ? champ sur office ?).
- Flux **invitation collaborateur** (table invitations, tokens) — n'existe pas
  encore à ma connaissance.
- Confirmer le **canal d'envoi mail** (SMTP côté php-api ? service tiers ?).

## À ne pas oublier
- L'office B2B **doit** avoir un `client_id` (invariant à valider côté form).
- Départements **par société** → filtrer partout par `id_client`.
- Création **franchisé-only** → contrôle d'accès sur le POST.
- L'onboarding « Créer un bureau » **réutilise** le ciblage voucher `OFFICE`
  déjà livré et les modèles `ws_email_templates` — ne pas réinventer.
