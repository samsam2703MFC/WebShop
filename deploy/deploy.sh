#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# deploy.sh — pousse les assets + l'API PHP vers l'hébergement (FTP/SFTP via lftp)
#
#   ./deploy/deploy.sh              # déploie (assets + php-api/index.php)
#   ./deploy/deploy.sh --dry-run    # simulation, ne transfère rien
#   ./deploy/deploy.sh --assets     # assets uniquement
#   ./deploy/deploy.sh --api        # API uniquement
#
# Config : copie deploy/deploy.env.example -> deploy/deploy.env et remplis-le.
# Prérequis : lftp  (Debian/Ubuntu: sudo apt install lftp ; macOS: brew install lftp)
#
# NB : le SQL (set-asset-images.sql, seed-seasons.sql) n'est PAS exécuté ici —
#      lance-le dans phpMyAdmin. config.php du serveur n'est jamais écrasé.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/deploy/deploy.env"

# ── args ────────────────────────────────────────────────────────────────────
DRY=0; DO_ASSETS=1; DO_API=1
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    --assets)  DO_ASSETS=1; DO_API=0 ;;
    --api)     DO_ASSETS=0; DO_API=1 ;;
    -h|--help) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Option inconnue : $a" >&2; exit 2 ;;
  esac
done

# ── pré-requis ──────────────────────────────────────────────────────────────
command -v lftp >/dev/null || { echo "❌ lftp introuvable. Installe-le (apt/brew install lftp)."; exit 1; }
[ -f "$ENV_FILE" ] || { echo "❌ $ENV_FILE manquant. Copie deploy/deploy.env.example -> deploy/deploy.env et remplis-le."; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${FTP_HOST:?}"; : "${FTP_USER:?}"; : "${FTP_PASS:?}"; : "${REMOTE_WEBROOT:?}"
FTP_PROTO="${FTP_PROTO:-ftp}"; FTP_PORT="${FTP_PORT:-21}"; FTP_TLS="${FTP_TLS:-no}"

MIRROR_FLAGS="--reverse --only-newer --verbose --parallel=4 --exclude-glob .gitkeep --exclude-glob README.md"
[ "$DRY" -eq 1 ] && MIRROR_FLAGS="$MIRROR_FLAGS --dry-run"

echo "▶ Cible   : $FTP_PROTO://$FTP_HOST:$FTP_PORT  →  $REMOTE_WEBROOT"
echo "▶ Assets  : $([ $DO_ASSETS -eq 1 ] && echo oui || echo non)   API : $([ $DO_API -eq 1 ] && echo oui || echo non)   Dry-run : $([ $DRY -eq 1 ] && echo OUI || echo non)"

# ── build du script lftp ────────────────────────────────────────────────────
CMDS="set cmd:fail-exit yes;"
CMDS="$CMDS set ssl:verify-certificate no;"
if [ "$FTP_PROTO" = "ftp" ]; then
  [ "$FTP_TLS" = "yes" ] && CMDS="$CMDS set ftp:ssl-force yes; set ftp:ssl-protect-data yes;" || CMDS="$CMDS set ftp:ssl-allow no;"
fi
CMDS="$CMDS open -u \"$FTP_USER\",\"$FTP_PASS\" -p $FTP_PORT $FTP_PROTO://$FTP_HOST;"

if [ "$DO_ASSETS" -eq 1 ]; then
  # Miroir des 3 sous-dossiers d'assets vers /webshop/assets
  CMDS="$CMDS mirror $MIRROR_FLAGS \"$ROOT/public/assets/\" \"$REMOTE_WEBROOT/assets/\";"
fi
if [ "$DO_API" -eq 1 ]; then
  # Seulement index.php (ne JAMAIS écraser config.php du serveur)
  if [ "$DRY" -eq 1 ]; then
    CMDS="$CMDS echo '[dry-run] put php-api/index.php -> $REMOTE_WEBROOT/api/index.php';"
  else
    CMDS="$CMDS put -O \"$REMOTE_WEBROOT/api\" \"$ROOT/php-api/index.php\";"
  fi
fi
CMDS="$CMDS bye;"

lftp -c "$CMDS"
echo "✅ Terminé. Vérifie : https://$FTP_HOST.../api/catalog/categories?shopId=2  (chaque img en /webshop/assets/...)"
