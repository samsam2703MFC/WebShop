# Déploiement des médias + API vers l'hébergement

Script `deploy.sh` : envoie `public/assets/**` et `php-api/index.php` sur le
serveur mutualisé via **lftp** (FTP ou SFTP). Le SQL reste à passer dans
phpMyAdmin (voir plus bas).

## 1. Configurer (une seule fois)

```bash
cp deploy/deploy.env.example deploy/deploy.env
# édite deploy/deploy.env avec tes accès FTP/SFTP + REMOTE_WEBROOT
```
`deploy/deploy.env` est **gitignoré** — tes identifiants ne partent pas sur Git.

Installe lftp si besoin : `sudo apt install lftp` (Linux) / `brew install lftp` (macOS).

## 2. Déployer

```bash
./deploy/deploy.sh --dry-run   # simulation (rien n'est transféré)
./deploy/deploy.sh             # assets + API
./deploy/deploy.sh --assets    # assets seulement
./deploy/deploy.sh --api       # index.php seulement
```

Le miroir n'envoie que les fichiers **plus récents** (`--only-newer`) et ne
touche jamais `config.php` du serveur.

## 3. SQL (manuel, phpMyAdmin)

Dans l'ordre :
1. `backend/schema/set-asset-images.sql` — catégories + produits
2. `backend/schema/seed-seasons.sql` — saisons (UPDATE été + INSERT)

## 4. Vérifier

- `https://<domaine>/api/catalog/categories?shopId=2` → chaque `img` en `/webshop/assets/...`
- `https://<domaine>/api/catalog/assortments?shopId=2` → idem saisons
- Les images se chargent dans le navigateur.
