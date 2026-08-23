#!/bin/sh
# Garantit pdo_mysql dans le PHP CLI — requis par sync_product_photos.php et
# sync_product_images.php (constaté en production : php8.3 CLI sans MySQL, les
# deux synchros inertes à chaque déploiement, aucun run rouge).
#
# Idempotent. Tolère un apt occupé : le premier essai réel est tombé sur
# « Could not get lock /var/lib/apt/lists/lock » (mises à jour automatiques) —
# on attend le verrou (DPkg::Lock::Timeout) et on réessaie plutôt que d'abandonner.
# Sortie 0 même en échec d'installation : le déploiement reste valide, le
# message dit quoi faire.
set -u
if php -m 2>/dev/null | grep -qi pdo_mysql; then
  echo "OK pdo_mysql déjà présent dans le PHP CLI"
  exit 0
fi
PV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo '')
[ -n "$PV" ] || { echo "ATTENTION php CLI introuvable — rien à faire"; exit 0; }
PKG="php$PV-mysql"
echo "pdo_mysql absent du PHP CLI $PV — installation de $PKG…"
export DEBIAN_FRONTEND=noninteractive
APT="apt-get -y -qq -o DPkg::Lock::Timeout=120"
n=0
while [ $n -lt 5 ]; do
  n=$((n + 1))
  # Install direct d'abord : les listes de paquets existent déjà en général,
  # et c'est le `update` qui prend le verrou le plus disputé.
  if $APT install "$PKG" 2>/tmp/ensure_php_mysql.err; then break; fi
  if grep -qiE 'Unable to locate package|has no installation candidate' /tmp/ensure_php_mysql.err; then
    echo "  paquet inconnu — apt-get update puis nouvel essai ($n/5)"
    $APT update 2>/dev/null || true
  else
    echo "  apt occupé ou en échec — nouvel essai dans 20 s ($n/5) :"
    sed 's/^/    /' /tmp/ensure_php_mysql.err | head -3
    sleep 20
  fi
done
if php -m 2>/dev/null | grep -qi pdo_mysql; then
  echo "OK extension installée — les synchros photos sont opérationnelles"
else
  echo "ATTENTION pdo_mysql toujours absent après $n essai(s) — installer à la main :"
  echo "  apt-get install -y $PKG    puis relancer un déploiement."
fi
exit 0
