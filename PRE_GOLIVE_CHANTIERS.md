# Avant le go-live : chantiers et processus

Ce document couvre ce qui **reste à faire** en dehors des tests
(voir `TESTS_FLOWS_LOGIN.md`) et des données à compléter
(voir `PRE_GOLIVE_DONNEES.md`).

---

## A. Développements restants

| # | Chantier | Ampleur | Bloquant ? |
|---|---|---|---|
| A1 | Écran **Profils tablette** (console marque) | petit — l'API existe | oui pour les comptes tablette |
| A2 | Écran **Comptes tablette** (BO franchisé) | petit — l'API existe | oui pour les comptes tablette |
| A3 | **Vue chauffeur** + endpoints de validation d'arrêt | moyen à gros — tout est à faire | non pour ouvrir au public ; oui pour la livraison bureau exploitée |

### A1 · Profils tablette (console marque)
La marque définit les profils et coche, pour chacun, les sections ouvertes.
API prête : `GET /franchisor/bo-roles`, `POST /franchisor/bo-role`,
`POST /franchisor/bo-role-delete`, `POST /franchisor/bo-roles-init`
(cette dernière crée les 5 profils standard — action explicite, pas de
remplissage automatique). Le catalogue des 37 sections vient de
`GET /franchisor/bo-sections`.

L'écran actuel « Comptes tablette boutique » est à **remplacer** : il crée encore
des comptes, ce qui n'est plus le rôle de la marque.

### A2 · Comptes tablette (BO franchisé)
Le franchisé saisit **nom + PIN** et choisit un profil publié par la marque.
API prête : `GET /franchisee/bo-users`, `POST /franchisee/bo-user`,
`POST /franchisee/bo-user-delete`, `GET /franchisee/bo-roles`.
Aucune case « section » dans cet écran : les droits viennent du profil.

Ces routes sont **réservées au jeton admin ERP** : une session PIN ne peut pas
les atteindre, donc un vendeur ne peut pas se créer un compte « admin boutique ».

### A3 · Vue chauffeur
Aujourd'hui la chaîne s'arrête au dispatch : le BO écrit dans `ws_tour_tracking`,
mais rien ne remonte du terrain. À construire : liste des arrêts de la tournée,
validation d'arrêt (QR / PIN / signature selon le site), remontée des positions,
et les endpoints correspondants. C'est ce qui rendra l'écran **Suivi temps réel**
réellement vivant.

---

## B. Processus de mise en ligne

### B1 · 🔐 Rotation du jeton admin — **à faire avant l'ouverture**
Le jeton (`admin_token` dans `api/config.php`) a transité par un log GitHub
Actions. Il donne **tous les droits** sur les deux back-offices.
Générer une nouvelle valeur, la poser dans `api/config.php`, puis rouvrir chaque
back-office une fois avec `?token=<nouveau>`. Les comptes tablette (PIN) ne sont
pas affectés.

### B2 · Vérifier les accès réseau sortants du serveur
| Service | Usage | Conséquence si injoignable |
|---|---|---|
| `ec.europa.eu` | vérification TVA (VIES) | ajout de société sans identité pré-remplie |
| SMTP | e-mails de commande et de réinitialisation | le client ne reçoit rien |
| Stripe | paiement en ligne | paiement impossible |

Ces trois points ne se voient pas en base : ils se testent en usage.

### B3 · Vérifier que les migrations sont appliquées
```sql
SELECT version, applied_at FROM atelierby_db.ws_schema_migrations
 ORDER BY version DESC LIMIT 10;
```
Doivent figurer au minimum jusqu'à `0050_bo_roles.sql`. Le déploiement les
applique automatiquement ; cette vérification sert à détecter un déploiement
partiel.

### B4 · Purger les données de test
Avant l'ouverture au public, décider du sort de :
- les **commandes de test** passées pendant la campagne (`ws_orders`) ;
- les **comptes de test** (`bb@gmail.com`, `cs@gmail.com`, et le compte utilisé
  pour les essais) ;
- les **bons de test** créés pour valider les écrans.

```sql
SELECT id, order_ref, mode, total, status, created_at
  FROM atelierby_db.ws_orders ORDER BY id DESC LIMIT 20;

SELECT id, email, source_channel, created_at
  FROM atelierby_db.client WHERE email LIKE '%@gmail.com' ORDER BY id DESC LIMIT 10;
```

⚠️ Ne **rien supprimer** sans sauvegarde : une commande référencée par une
facture doit rester lisible.

### B5 · Sauvegarde avant ouverture
Un dump complet de `atelierby_db` daté, conservé hors du serveur. C'est le seul
filet en cas de mauvaise manipulation le jour J.

### B6 · Premier chargement chez les clients existants
Le cache de l'application installée (PWA) a été purgé côté code, mais un
navigateur ayant visité l'ancienne version peut garder un service worker. Prévoir
un message « rechargez la page » en cas d'anomalie signalée les premiers jours.

---

## C. Points de vigilance connus

| Constat | Décision attendue |
|---|---|
| Boutique 2 : **68 produits retirés** de l'assortiment, tous non obligatoires | réduction volontaire du franchisé ? |
| `1150002` et `6700231` s'appellent tous deux **« Brookie »** | deux formats (pièce / Ø 28 cm) : vérifier que les deux ont un prix, sinon un seul sera visible et le client ne pourra pas les distinguer |
| Seuls **6 clients sur 10 708** ont un `office_id` | normal en démarrage B2B ? |
| **1 prospect** non rattaché (`client.id_main_shop = 0`) | à traiter dans l'écran Prospect |
| `ws_tour_tracking` vide | expliqué : aucune application chauffeur (chantier A3) |

---

## D. Ordre conseillé

1. **A1 + A2** (les deux écrans) → débloque le test 6, le plus gros bloc de code
   jamais exercé.
2. **Tests 1 → 5** (rattachement, livraison bureau, bon nominatif, paiement
   différé, réservation de stock) : ce sont les flows qui touchent à l'argent.
3. **Données** : prix ERP manquants en priorité (188 couples obligatoires sans
   prix), puis allergènes.
4. **B1 rotation du jeton**, **B5 sauvegarde**, **B4 purge** — juste avant
   l'ouverture.
5. **A3 vue chauffeur** : peut suivre l'ouverture si la livraison bureau démarre
   progressivement.
