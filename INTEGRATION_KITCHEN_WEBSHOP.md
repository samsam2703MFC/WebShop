# Brief pour l'agent de codage — mode WebShop dans la tablette Kitchen

**Dépôt à ouvrir : `samsam2703MFC/pwa_kitchen`**

Ce document est le contrat d'intégration. Il est maintenu dans le dépôt
`samsam2703MFC/WebShop`, à côté de l'API qu'il décrit : toute évolution des
endpoints doit être répercutée ici.

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

Le mode est un **réglage de l'appareil**, pas de l'utilisateur : la tablette de
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

Le back-office gère déjà **tout** ce qui suit, il n'y a rien à réécrire : pavé
PIN, filtrage du menu par profil, cloisonnement à la boutique, révocation
immédiate, anti-brute-force.

---

## 3. Authentification — le point critique

Le back-office franchisé n'accepte que deux identités :

| Identité | En-tête HTTP | Portée |
|---|---|---|
| **Jeton admin ERP** | `X-Admin-Token: <jeton>` | tout, toutes les boutiques |
| **Session tablette (PIN)** | `X-Pin-Token: <jeton de session>` | **une** boutique, **les sections de son profil** |

**La tablette doit utiliser une session PIN, jamais le jeton admin.** Ce jeton
donne accès aux marges, aux coûts et aux paramètres réseau : il n'a rien à faire
sur une tablette de comptoir, sous aucune forme.

### Deux cas selon l'hébergement

**Cas A — Kitchen est servi depuis le même hôte que le webshop.**
Rien à faire : le back-office ouvert en `iframe` gère lui-même sa session PIN et
la stocke dans `localStorage` (`boPinSession`). Kitchen n'a pas à connaître le
PIN.

**Cas B — Kitchen est sur une autre origine.**
`localStorage` n'est pas partagé :
- **B1 (simple, recommandé)** : laisser le back-office demander le PIN dans
  l'iframe. Une saisie par service (session de 12 h).
- **B2 (intégré)** : Kitchen fait lui-même le `pin-login` et transmet le jeton.
  Voir §5.

⚠️ En iframe, vérifier que le serveur n'envoie pas `X-Frame-Options: DENY` ni un
`Content-Security-Policy: frame-ancestors` restrictif sur
`/webshop/backoffice_franchisee/`. Si c'est le cas, l'ajustement se fait côté
serveur web du webshop — le signaler, ne pas contourner côté client.

---

## 4. Réglage de mode — spécification

- Emplacement : **paramètres de la tablette** (`GET /me`, vue `me/about.twig`,
  ou une nouvelle route `GET /settings` si l'écran actuel n'est qu'un « à propos »).
- Trois valeurs exclusives : `gestion` · `production` · `webshop`.
- Défaut : **`production`** — ne pas changer le comportement des tablettes déjà
  en service.
- Changer de mode **ne doit pas** déconnecter l'utilisateur Kitchen.
- Le mode `webshop` doit être **désactivable** : sans URL de back-office ou sans
  id de boutique configuré, l'option est grisée **avec la raison affichée** —
  pas d'écran blanc, pas de menu qui ne mène nulle part (le menu Production
  désactivé de `sidebar.twig` est un bon modèle visuel : `opacity:.5` +
  `pointer-events:none` + badge).

Ajouter deux réglages d'appareil : **« URL du back-office WebShop »** et
**« Boutique (id) »**. L'id de boutique est indispensable : il détermine ce que
la tablette voit.

Ajouter les clés de traduction correspondantes (le menu est en polonais) :
`mode_gestion`, `mode_production`, `mode_webshop`, `webshop`,
`webshop_not_configured`.

---

## 5. Contrat d'API — seulement pour B2 ou des écrans natifs

Base : `<origine du webshop>/webshop/api`

### Ouvrir une session PIN

```http
POST /bo/pin-login
Content-Type: application/json

{ "shopId": 2, "pin": "1234" }
```

Réponse `200` :
```json
{ "ok": true, "token": "…64 caractères…", "nom": "Julie D.",
  "shopId": 2, "sections": ["tdb","commandes","stockJour","creneaux"],
  "expireDans": 43200 }
```

Erreurs : `401 PIN incorrect` · `403 Aucun profil actif sur ce compte` ·
`503 Comptes tablette non configurés` · **8 tentatives / 5 min par IP**.

### Vérifier la session

```http
GET /bo/pin-me
X-Pin-Token: <token>
```

Renvoie `nom`, `shopId`, `profil`, `sections`. **À appeler au démarrage** :
c'est ce qui ferme la tablette quand un compte est désactivé côté marque, sans
attendre les 12 h. Un `401` ⇒ retour au pavé PIN.

### Fermer la session

```http
POST /bo/pin-logout
X-Pin-Token: <token>
```

### Lire les données métier

Toutes les routes `/franchisee/*` acceptent `X-Pin-Token`. Deux règles
appliquées **côté serveur**, à ne pas réimplémenter :

- le paramètre `?shop=` est **ignoré** pour une session PIN — la boutique de la
  session fait foi ; inutile de le forger ;
- un endpoint hors des sections du profil renvoie **403** avec le nom de la
  section manquante.

Endpoints utiles pour un écran comptoir :

| Endpoint | Section requise | Contenu |
|---|---|---|
| `GET /franchisee/fr-orders` | `commandes` | commandes du jour et à venir |
| `POST /franchisee/order-status` | `commandes` | préparation → prête → livrée |
| `GET /franchisee/fr-stock-catalog` | `stockJour` | stock du jour par catégorie |
| `POST /franchisee/stock-adjust` | `stockJour` | ajuster une quantité |
| `GET /franchisee/kpis` | `tdb` | indicateurs du tableau de bord |
| `GET /franchisee/me` | libre | identité de la boutique |

### Profils et sections

Les **profils** (`bo_role`) sont définis par la marque dans sa console ; le
**franchisé** les attribue à ses comptes tablette. Les 37 sections sont
groupées en : Pilotage · Vente · Logistique · Clients B2B · Disponibilité ·
Réglages.

**Kitchen ne doit ni créer ni modifier de profil ou de compte** : ces routes sont
réservées au jeton admin ERP.

---

## 6. Règle du projet, non négociable

> **Soit la fonctionnalité marche avec les vraies données, soit elle renvoie une
> erreur visible. Aucun seed, aucun mock, aucun repli qui fabrique de la donnée.**

Concrètement ici :
- pas de liste de commandes de démonstration si l'API ne répond pas — un message
  d'erreur explicite ;
- pas de « mode dégradé » affichant un menu WebShop vide : sans session PIN, on
  montre le pavé PIN ou la raison du refus ;
- un échec réseau dit « réseau », jamais « aucune donnée ».

---

## 7. Critères d'acceptation

- [ ] Les paramètres proposent les trois modes ; le choix survit au redémarrage.
- [ ] Mode **Production** : comportement actuel **inchangé** (c'est le défaut).
- [ ] Mode **Gestion** : dashboard, KPI, checklists, connaissances, réclamations,
      contrôle qualité.
- [ ] Mode **WebShop** : ouvre le back-office franchisé de **la boutique
      configurée**.
- [ ] Sans URL ou sans id de boutique, l'option WebShop est grisée **avec la
      raison affichée**.
- [ ] Un compte au profil **Vendeur** ne voit que ses 4 sections.
- [ ] Désactiver ce compte côté back-office ferme la tablette au rechargement.
- [ ] Le **jeton admin ERP n'apparaît nulle part** dans Kitchen (ni code, ni
      config, ni log, ni stockage).
- [ ] Changer de mode ne déconnecte pas l'utilisateur Kitchen.
- [ ] `sidebar.twig` n'affiche aucune entrée morte dans un mode donné.

---

## 8. Ce qu'il ne faut PAS faire

- Reconstruire les écrans du back-office — sauf demande explicite après
  chiffrage des 37 sections.
- Stocker le jeton admin ERP dans la tablette, sous quelque forme que ce soit.
- Réimplémenter le filtrage par sections côté Kitchen : il est appliqué par le
  serveur ; un filtrage client seul serait contournable.
- Écrire le PIN dans un log, une URL ou un stockage local.
- Toucher aux menus Production existants : ils sont hors périmètre.
