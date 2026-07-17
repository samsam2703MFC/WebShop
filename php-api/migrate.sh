#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# migrate.sh — applique les migrations SQL versionnées, une seule fois chacune.
#
# À exécuter SUR LE SERVEUR (le workflow le lance en SSH après le rsync de l'API).
# - Lit les identifiants DB depuis config.php (même source que l'API, aucun secret
#   supplémentaire).
# - Crée/maintient la table de suivi ws_schema_migrations.
# - Applique, dans l'ordre des noms de fichiers, chaque migrations/*.sql qui n'y
#   figure pas encore, puis l'y enregistre. Les migrations déjà appliquées sont
#   ignorées (jamais rejouées).
#
# Convention migrations : NNNN_description.sql, idempotentes (IF NOT EXISTS,
# IF EXISTS, etc.) — une migration doit pouvoir échouer/rejouer sans casser.
# ---------------------------------------------------------------------------
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -f "$DIR/config.php" ] || { echo "❌ config.php introuvable à côté de migrate.sh"; exit 1; }

# Identifiants depuis config.php (sous-tableau 'db'), échappés proprement.
eval "$(php -r '
  $c = require $argv[1];
  $d = $c["db"];
  foreach (["DBH"=>"host","DBP"=>"port","DBN"=>"name","DBU"=>"user","DBW"=>"pass"] as $k=>$kk) {
    printf("%s=%s\n", $k, escapeshellarg((string)$d[$kk]));
  }
' "$DIR/config.php")"

export MYSQL_PWD="$DBW"
run_mysql() { mysql --host="$DBH" --port="$DBP" --user="$DBU" "$DBN" "$@"; }

# Table de suivi
run_mysql -e "CREATE TABLE IF NOT EXISTS ws_schema_migrations (
  version    VARCHAR(255) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

shopt -s nullglob
applied=0
for f in "$DIR"/migrations/*.sql; do
  ver="$(basename "$f")"
  if [ -n "$(run_mysql -N -s -e "SELECT 1 FROM ws_schema_migrations WHERE version='$ver' LIMIT 1;")" ]; then
    echo "· déjà appliquée : $ver"
    continue
  fi
  echo "→ application : $ver"
  run_mysql < "$f"
  run_mysql -e "INSERT INTO ws_schema_migrations (version) VALUES ('$ver');"
  applied=$((applied + 1))
done
echo "✅ migrate.sh terminé — $applied nouvelle(s) migration(s) appliquée(s)."
