# Migrations SQL (automatiques, versionnées)

À chaque push sur `main`, le workflow `deploy-sftp.yml` exécute `php-api/migrate.sh`
sur le serveur (via SSH), qui applique **une seule fois** chaque migration non
encore enregistrée, dans l'ordre des noms de fichiers.

## Écrire une migration

1. Crée `NNNN_description.sql` (numéro croissant, ex. `0002_add_x.sql`).
2. Rends-la **idempotente** (`CREATE TABLE IF NOT EXISTS`, `DROP … IF EXISTS`,
   `ADD COLUMN IF NOT EXISTS` en MariaDB, ou garde via `information_schema`) : une
   migration doit pouvoir échouer puis rejouer sans casser.
3. Commit + push sur `main` → elle s'applique automatiquement au déploiement.

## Garde-fous

- **Jamais rejouée** : la table `ws_schema_migrations` mémorise les versions
  appliquées ; migrate.sh saute celles déjà présentes.
- **Ordre déterministe** : tri par nom de fichier.
- **Creds** : lues depuis `config.php` (aucun secret supplémentaire).
- **Non servies par le web** : `.htaccess` (`Require all denied`).
- **Arrêt sur erreur** : `set -e` — une migration en échec n'est pas enregistrée,
  et le déploiement remonte l'erreur (le workflow passe en rouge).

⚠️ Le DDL MySQL est auto-commit (pas de rollback transactionnel) : teste tes
migrations et écris-les idempotentes.
