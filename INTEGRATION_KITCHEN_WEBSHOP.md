# Brief pour l'agent de codage — mode WebShop dans la tablette Kitchen

**Dépôt à ouvrir : `samsam2703MFC/pwa_kitchen`**

Ce document est le contrat d'intégration. Il est maintenu dans le dépôt
`samsam2703MFC/WebShop`, à côté de l'API qu'il décrit : toute évolution des
endpoints doit être répercutée ici.

> **Révision du 2 août 2026.** Le mode tablette à code PIN a été **retiré** du
> back-office franchisé. La tablette est partagée par toute l'équipe du magasin :
> elle ne demande donc **aucune connexion personnelle**. Elle est configurée une
> fois avec une URL et un **jeton d'appareil**. Les sections « Authentification »
> et « Contrat d'API » ci-dessous ont été refaites en conséquence.

---

## 0. La pile en place (relevée dans le dépôt)

Ce **n'est pas** une PWA JavaScript : c'est une application **PHP** rendue côté
serveur.

| Élément | Où |
|---|---|
| Autoload | `App\Kitchen\` → `src/` (PSR-4, `composer.json`) |
| Routage | **FastRoute** — `src/core/Bootstrap/Routes/*.php` |
| Injection | **PHP-DI** |
| Vues | **Twig** — `src/app/Views/` |
| Menu latéral | `src/app/Views/page_components/sidebar.twig` |
| Barre du haut | `src/app/Views/page_components/navbar.twig` |
| Profil / appareil | `src/app/Http/Controllers/Me/ProfileController.php`, route `GET /me`, vue `me/about.twig` |
| Appareil | `src/app/Repositories/Me/DeviceRepository.php` → `GET /devices/me` |
| Client HTTP | `src/core/Http/ApiClient.php` |
| Interface | Bootstrap + i18n via `translations.*` (libellés actuellement en polonais) |

Les menus existants sont : Dashboard, Production *(désactivé, « en
construction »)*, Base de connaissances, Checklists, Commandes, Réclamations.

---

## 1. Ce qu'il faut faire

Ajouter, **dans les paramètres de la tablette**, un choix de mode parmi trois.
Le mode détermine les menus affichés dans `sidebar.twig`.

| Mode | Menus affichés |
|---|---|
| **Gestion** | contrôle qualité, checklists, base de connaissances, réclamations, dashboard et KPI |
| **Production** | les menus de production |
| **WebShop** | l'ensemble des menus WebShop (back-office franchisé) |

Le mode est un **réglage de l'appareil**, pas d'un utilisateur : la tablette de
la cuisine reste en Production, celle du comptoir en WebShop. Il doit survivre au
redémarrage et être modifiable sans réinstallation.

**Où le stocker** — par ordre de préférence :
1. côté ERP, sur l'appareil (`/devices/me` expose déjà un appareil ; ajouter un
   champ `mode` si l'API le permet) — c'est la seule option qui survit à un
   vidage du cache et se pilote à distance ;
2. à défaut, en session/cookie persistant côté Kitchen, avec le champ visible
   dans `GET /me`.

Ne pas inventer un troisième mécanisme : si l'ERP ne peut pas porter le champ,
le dire et prendre l'option 2 explicitement.

---

## 2. Mode WebShop : NE PAS reconstruire les écrans

⚠️ **Recommandation forte.** Le back-office franchisé existe, il est déployé et
testé, et compte **37 sections** en 6 groupes (tournées, stock, assortiment,
bons, clients B2B, disponibilité, réglages…). Le réécrire dans Kitchen
représenterait des semaines de travail et créerait **deux implémentations à
maintenir**, donc deux comportements qui divergeront.

**À faire : le mode WebShop ouvre l'application existante.**

```
http://<hôte>/webshop/backoffice_franchisee/?shop=<id de la boutique>
```

Trois façons, par ordre de préférence :

1. **Vue intégrée** — une route Kitchen (ex. `GET /webshop`) rendant une vue
   Twig qui contient une `iframe` plein écran. L'utilisateur ne quitte pas
   l'application, le menu Kitchen reste visible.
2. **Redirection** vers l'URL.
3. **Lien externe** — dernier recours, expérience dégradée sur tablette.

⚠️ En iframe, vérifier que le serveur n'envoie pas `X-Frame-Options: DENY` ni un
`Content-Security-Policy: frame-ancestors` restrictif sur
`/webshop/backoffice_franchisee/`. Si c'est le cas, l'ajustement se fait côté
serveur web du webshop — le signaler, ne pas contourner côté client.

---

## 3. Authentification : l'appareil, pas la personne

**Il n'y a aucune connexion personnelle.** La tablette est posée dans le magasin
et sert à toute l'équipe ; lui demander un identifiant à chaque geste n'aurait
pas de sens.

L'identité, c'est **l'appareil**. Le franchisé configure la tablette une fois
avec deux valeurs, disponibles dans son back-office sous
**Réglages › Tablette Kitchen** :

| Réglage | Exemple |
|---|---|
| **URL du back-office** | `https://<hôte>/webshop/backoffice_franchisee/?shop=2` |
| **Jeton d'appareil** | 64 caractères hexadécimaux |

Le jeton est transmis à chaque appel dans l'en-tête :

```http
X-Device-Token: <jeton>
```

### Ce que ce jeton est — et n'est pas

- Il vaut pour **une seule boutique**. Le paramètre `?shop=` ne peut pas
  l'élargir : le serveur impose la boutique du jeton.
- Sa portée est une **liste blanche de six écrans de comptoir** (voir §5). Tout
  le reste est refusé — y compris un endpoint ajouté plus tard, puisque ce qui
  n'est pas listé est fermé par défaut.
- **Ce n'est pas le jeton administrateur ERP.** Celui-ci donne accès aux marges,
  aux coûts et aux paramètres réseau : il n'a rien à faire sur une tablette de
  comptoir, sous aucune forme. Ne jamais le stocker dans Kitchen.
- Il est **révocable immédiatement** depuis le back-office. C'est le seul moyen
  de contrôle en cas de tablette perdue ou volée — d'où l'importance de ne pas
  le recopier ailleurs.

### Une conséquence à connaître

Un appareil partagé sans identification personnelle signifie qu'**aucune action
n'est attribuable à quelqu'un** : changer le statut d'une commande ou ajuster un
stock est tracé comme venant de la tablette, pas d'une personne. C'est le choix
assumé pour ce poste. Si un jour l'attribution devient nécessaire (litige,
inventaire), il faudra ajouter une identification — pas contourner celle-ci.

### Cas particulier de l'iframe

Si Kitchen est servi **depuis le même hôte** que le webshop, le back-office
ouvert en iframe fonctionne tel quel une fois le jeton configuré côté serveur.

Si Kitchen est sur **une autre origine**, le jeton doit accompagner les appels :
Kitchen fait alors les requêtes lui-même (§5) plutôt que de déléguer à l'iframe.

---

## 4. Réglage de mode — spécification

- Emplacement : **paramètres de la tablette** (`GET /me`, vue `me/about.twig`,
  ou une nouvelle route `GET /settings` si l'écran actuel n'est qu'un « à propos »).
- Trois valeurs exclusives : `gestion` · `production` · `webshop`.
- Défaut : **`production`** — ne pas changer le comportement des tablettes déjà
  en service.
- Changer de mode **ne doit pas** redémarrer l'application ni perdre l'état.
- Le mode `webshop` doit être **désactivable** : sans URL ni jeton configurés,
  l'option est grisée **avec la raison affichée** — pas d'écran blanc, pas de
  menu qui ne mène nulle part (le menu Production désactivé de `sidebar.twig`
  est un bon modèle visuel : `opacity:.5` + `pointer-events:none` + badge).

Ajouter donc deux réglages d'appareil : **« URL du back-office WebShop »** et
**« Jeton d'appareil »**. L'id de boutique est porté par l'URL et par le jeton ;
il n'y a rien de plus à saisir.

Ajouter les clés de traduction correspondantes (le menu est en polonais) :
`mode_gestion`, `mode_production`, `mode_webshop`, `webshop`,
`webshop_not_configured`, `device_token`, `backoffice_url`.

⚠️ Le jeton est un secret : ne jamais l'écrire dans un log, une URL, un
paramètre de requête, ni l'afficher en clair ailleurs que dans son champ de
réglage.

---

## 5. Contrat d'API — pour des écrans natifs Kitchen

Base : `<origine du webshop>/webshop/api`
En-tête sur **tous** les appels : `X-Device-Token: <jeton>`

Six endpoints sont ouverts au jeton d'appareil. Les autres répondent **403**.

| Endpoint | Contenu |
|---|---|
| `GET /franchisee/me` | identité de la boutique — à appeler au démarrage pour valider le jeton |
| `GET /franchisee/fr-orders` | commandes du jour et à venir |
| `POST /franchisee/order-status` | préparation → prête → livrée |
| `GET /franchisee/fr-stock-catalog` | stock du jour par catégorie |
| `POST /franchisee/stock-adjust` | ajuster une quantité |
| `GET /franchisee/kpis` | indicateurs du tableau de bord |

**À appeler au démarrage** : `GET /franchisee/me`. Un **403** signifie que le
jeton a été révoqué — la tablette doit alors afficher l'écran de configuration
et **cesser d'afficher les données en cache**, qui ne sont plus les siennes.

Deux règles appliquées **côté serveur**, à ne pas réimplémenter :

- le paramètre `?shop=` est **ignoré** — la boutique du jeton fait foi ;
- un endpoint hors de la liste blanche renvoie **403** avec le nom de l'écran
  refusé.

**Kitchen ne doit ni créer ni révoquer de jeton** : `/franchisee/device-token`
est réservé au jeton admin ERP. La tablette reçoit son jeton, elle ne se le
délivre pas.

---

## 6. Règle du projet, non négociable

> **Soit la fonctionnalité marche avec les vraies données, soit elle renvoie une
> erreur visible. Aucun seed, aucun mock, aucun repli qui fabrique de la donnée.**

Concrètement ici :
- pas de liste de commandes de démonstration si l'API ne répond pas — un message
  d'erreur explicite ;
- pas de « mode dégradé » affichant un menu WebShop vide : sans jeton valide, on
  montre l'écran de configuration ou la raison du refus ;
- un échec réseau dit « réseau », jamais « aucune donnée » ;
- un appel qui échoue doit **le dire** : ne jamais avaler une erreur HTTP. Un
  statut ≠ 2xx n'est pas une exception en JavaScript ni en PHP — il faut le
  tester explicitement. Plusieurs défauts sérieux du webshop venaient exactement
  de là.

---

## 7. Critères d'acceptation

- [ ] Les paramètres proposent les trois modes ; le choix survit au redémarrage.
- [ ] Les paramètres acceptent l'**URL** et le **jeton d'appareil**, et les
      conservent après redémarrage.
- [ ] Mode **Production** : comportement actuel **inchangé** (c'est le défaut).
- [ ] Mode **Gestion** : dashboard, KPI, checklists, connaissances, réclamations,
      contrôle qualité.
- [ ] Mode **WebShop** : ouvre le back-office franchisé de la boutique du jeton,
      **sans demander la moindre connexion**.
- [ ] Sans URL ou sans jeton, l'option WebShop est grisée **avec la raison
      affichée**.
- [ ] Un jeton **révoqué** renvoie la tablette à l'écran de configuration au
      démarrage suivant, et les données en cache ne sont plus affichées.
- [ ] Le **jeton admin ERP n'apparaît nulle part** dans Kitchen (ni code, ni
      config, ni log, ni stockage).
- [ ] Le **jeton d'appareil** n'apparaît ni dans une URL, ni dans un log.
- [ ] Changer de mode ne perd pas l'état de l'application.
- [ ] `sidebar.twig` n'affiche aucune entrée morte dans un mode donné.

---

## 8. Ce qu'il ne faut PAS faire

- Reconstruire les écrans du back-office — sauf demande explicite après
  chiffrage des 37 sections.
- Ajouter une connexion personnelle (PIN, mot de passe) : la tablette est
  partagée, c'est un choix assumé. Si l'attribution des actions devient
  nécessaire, c'est une décision produit, pas un ajout discret.
- Stocker le jeton admin ERP dans la tablette, sous quelque forme que ce soit.
- Réimplémenter la liste blanche côté Kitchen : elle est appliquée par le
  serveur ; un filtrage client seul serait contournable.
- Toucher aux menus Production existants : ils sont hors périmètre.
