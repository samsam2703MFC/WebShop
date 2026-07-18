#!/usr/bin/env bash
# ============================================================================
# test-isolation.sh — Prouve que les DEUX sessions BO sont étanches.
#
# Prérequis :
#   - l'API tourne (ex. local :  php -S 127.0.0.1:8080 index.php  depuis php-api/)
#   - config.php contient la section 'bo' avec 'cookie_secure' => false pour un
#     test en http (sinon les cookies Secure ne repartent pas sur http)
#   - comptes de démo seedés (seed-bo-users.example.sql), mdp Test1234!
#
# Usage :   BASE=http://127.0.0.1:8080 ./test-isolation.sh
# ============================================================================
set -euo pipefail
BASE="${BASE:-http://127.0.0.1:8080}"
JA="$(mktemp)"; JB="$(mktemp)"          # bocaux à cookies : A=franchisé, B=franchiseur
trap 'rm -f "$JA" "$JB"' EXIT
pass=0; fail=0
ok(){ echo "  ✅ $1"; pass=$((pass+1)); }
ko(){ echo "  ❌ $1"; fail=$((fail+1)); }
code(){ # code <jar> <method> <path> [csrf]
  local jar="$1" m="$2" path="$3" csrf="${4:-}"
  curl -s -o /dev/null -w '%{http_code}' -X "$m" \
    ${jar:+-b "$jar" -c "$jar"} \
    ${csrf:+-H "X-CSRF-Token: $csrf"} \
    "$BASE$path"
}

echo "▶ 1. Login des deux BO (cookies distincts)"
CA=$(curl -s -c "$JA" -X POST "$BASE/bo/franchisee/login" \
      -H 'Content-Type: application/json' \
      -d '{"email":"franchise@atelierby.be","password":"Test1234!"}')
CB=$(curl -s -c "$JB" -X POST "$BASE/bo/franchisor/login" \
      -H 'Content-Type: application/json' \
      -d '{"email":"siege@atelierby.be","password":"Test1234!"}')
CSRF_A=$(printf '%s' "$CA" | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p')
CSRF_B=$(printf '%s' "$CB" | sed -n 's/.*"csrf":"\([^"]*\)".*/\1/p')
[ -n "$CSRF_A" ] && ok "login franchisé OK"      || ko "login franchisé KO ($CA)"
[ -n "$CSRF_B" ] && ok "login franchiseur OK"    || ko "login franchiseur KO ($CB)"
grep -q fb_franchisee_session "$JA" && ok "cookie fb_franchisee_session posé" || ko "cookie franchisé absent"
grep -q fb_franchisor_session "$JB" && ok "cookie fb_franchisor_session posé" || ko "cookie franchiseur absent"

echo "▶ 2. Chacun accède à SON /me"
[ "$(code "$JA" GET /bo/franchisee/me)" = 200 ] && ok "franchisé → /franchisee/me 200"   || ko "franchisé /me"
[ "$(code "$JB" GET /bo/franchisor/me)" = 200 ] && ok "franchiseur → /franchisor/me 200" || ko "franchiseur /me"

echo "▶ 3. FUITE CROISÉE INTERDITE (cookie d'un BO sur l'autre → 401)"
[ "$(code "$JA" GET /bo/franchisor/me)" = 401 ] && ok "cookie franchisé sur BO franchiseur → 401" || ko "FUITE ! franchisé a atteint franchiseur"
[ "$(code "$JB" GET /bo/franchisee/me)" = 401 ] && ok "cookie franchiseur sur BO franchisé → 401" || ko "FUITE ! franchiseur a atteint franchisé"
[ "$(code "$JA" GET /bo/franchisor/scope)" = 401 ] && ok "franchisé sur route données franchiseur → 401" || ko "FUITE données !"

echo "▶ 4. Non authentifié → 401 + login_url de SON BO"
U=$(curl -s "$BASE/bo/franchisor/scope")
printf '%s' "$U" | grep -q '/bo/franchisor/login' && ok "401 renvoie le login franchiseur" || ko "mauvais login_url ($U)"

echo "▶ 5. CSRF exigé sur mutation (logout sans jeton → 419)"
[ "$(code "$JA" POST /bo/franchisee/logout)" = 419 ] && ok "logout sans CSRF → 419" || ko "CSRF non exigé"

echo "▶ 6. Logout franchisé n'affecte PAS le franchiseur"
[ "$(code "$JA" POST /bo/franchisee/logout "$CSRF_A")" = 200 ] && ok "logout franchisé OK" || ko "logout franchisé"
[ "$(code "$JA" GET /bo/franchisee/me)" = 401 ] && ok "session franchisé bien fermée" || ko "session franchisé encore active"
[ "$(code "$JB" GET /bo/franchisor/me)" = 200 ] && ok "session franchiseur toujours active" || ko "logout a cassé l'autre BO !"

echo ""
echo "Résultat : $pass OK / $fail KO"
[ "$fail" -eq 0 ]
