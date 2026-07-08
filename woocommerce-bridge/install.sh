#!/usr/bin/env bash
#
# install.sh — one-command setup of the full L'Atelier By webshop on a
# WooCommerce site. Idempotent: safe to re-run (skips what already exists).
#
# Run ON THE HOST where WordPress + WP-CLI live, e.g.:
#   WP_CLI_FLAGS="--path=/var/www/html" bash woocommerce-bridge/install.sh
#   # or, as root:  WP_CLI_FLAGS="--allow-root --path=/var/www/html"
#
# It NEVER asks for passwords or keys. Stripe test/live keys are entered by
# you in WooCommerce → Settings → Payments → Stripe (or via GitHub Secrets in
# the deploy workflow) — never in this script or the chat.
#
# What it does:
#   1. Installs + activates WooCommerce (if missing)
#   2. Configures Belgium / EUR / prices-include-tax + 6% & 21% tax rates
#   3. Installs + activates the Atelier Webshop Bridge plugin (this folder)
#   4. Installs + activates the official WooCommerce Stripe Gateway (keys: yours)
#   5. Seeds a demo catalog + coupon (only if the shop has no products yet)
#   6. Sets pretty permalinks + the CORS storefront origin
#
set -euo pipefail

WP="wp ${WP_CLI_FLAGS:-}"
ADMIN_USER="${ADMIN_USER:-1}"
STOREFRONT_ORIGIN="${STOREFRONT_ORIGIN:-https://samsam2703mfc.github.io}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say() { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }

# ── 0. sanity ────────────────────────────────────────────────────────
$WP core is-installed 2>/dev/null || { echo "✗ No WordPress found. Set WP_CLI_FLAGS=\"--path=/path/to/wp\"."; exit 1; }
say "WordPress detected: $($WP option get blogname 2>/dev/null)"

# ── 1. WooCommerce ───────────────────────────────────────────────────
if ! $WP plugin is-active woocommerce 2>/dev/null; then
  say "Installing WooCommerce"
  $WP plugin install woocommerce --activate
else
  say "WooCommerce already active ($($WP plugin get woocommerce --field=version))"
fi

# ── 2. Belgium / EUR / tax ───────────────────────────────────────────
say "Configuring shop base (Belgium, EUR, TTC pricing)"
$WP option update woocommerce_default_country "BE:BXL"      >/dev/null
$WP option update woocommerce_currency "EUR"                >/dev/null
$WP option update woocommerce_prices_include_tax "yes"      >/dev/null
$WP option update woocommerce_calc_taxes "yes"              >/dev/null

# reduced 6% tax class
$WP wc tax_class list --user="$ADMIN_USER" --field=slug 2>/dev/null | grep -qx "reduit-6" \
  || $WP wc tax_class create --name="Réduit 6%" --user="$ADMIN_USER" >/dev/null
# rates (only if none defined yet)
if [ "$($WP wc tax list --user="$ADMIN_USER" --field=id 2>/dev/null | grep -c . || true)" -eq 0 ]; then
  say "Creating tax rates 21% (standard) + 6% (reduit-6)"
  $WP wc tax create --rate=21.0000 --country=BE --name="TVA Standard" --class=standard --user="$ADMIN_USER" >/dev/null
  $WP wc tax create --rate=6.0000  --country=BE --name="TVA Réduit"   --class=reduit-6 --user="$ADMIN_USER" >/dev/null
else
  say "Tax rates already present — skipping"
fi

# ── 3. The bridge plugin ─────────────────────────────────────────────
PLUGINS_DIR="$($WP eval 'echo defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : WP_CONTENT_DIR."/plugins";')"
DEST="$PLUGINS_DIR/atelier-webshop-bridge"
say "Installing bridge plugin → $DEST"
mkdir -p "$DEST/includes"
cp "$SRC/atelier-webshop-bridge.php" "$DEST/"
cp "$SRC/includes/"*.php "$DEST/includes/"
$WP plugin activate atelier-webshop-bridge

# ── 4. WooCommerce Stripe Gateway (keys stay yours) ──────────────────
if ! $WP plugin is-active woocommerce-gateway-stripe 2>/dev/null; then
  say "Installing WooCommerce Stripe Gateway (add your keys in WooCommerce → Payments → Stripe)"
  $WP plugin install woocommerce-gateway-stripe --activate 2>/dev/null || \
    echo "  ⚠ Could not auto-install; add 'WooCommerce Stripe Gateway' from the Plugins screen."
else
  say "WooCommerce Stripe Gateway already active"
fi

# ── 5. Demo catalog + coupon (only if empty) ─────────────────────────
if [ "$($WP wc product list --user="$ADMIN_USER" --field=id 2>/dev/null | grep -c . || true)" -eq 0 ]; then
  say "Seeding demo catalog"
  cat_id() {
    local slug="$1" name="$2" id
    id="$($WP term list product_cat --slug="$slug" --field=term_id 2>/dev/null | head -1)"
    [ -n "$id" ] || id="$($WP wc product_cat create --name="$name" --slug="$slug" --user="$ADMIN_USER" --porcelain)"
    echo "$id"
  }
  C_VIEN="$(cat_id viennoiseries Viennoiseries)"
  C_BREAD="$(cat_id breads Pains)"
  C_SWEET="$(cat_id sweet Douceurs)"
  C_SAV="$(cat_id savory Salé)"
  C_DRINK="$(cat_id drinks Boissons)"
  prod() { # name sku price taxclass catid no_delivery lead_time
    $WP wc product create --name="$1" --sku="$2" --regular_price="$3" --tax_class="$4" \
      --status=publish --categories="[{\"id\":$5}]" \
      --meta_data="[{\"key\":\"_atelier_no_delivery\",\"value\":\"$6\"},{\"key\":\"_atelier_lead_time\",\"value\":\"$7\"}]" \
      --user="$ADMIN_USER" --porcelain >/dev/null
  }
  prod "Croissant pur beurre"          SKU-CROIS-001 1.40 reduit-6 "$C_VIEN"  0 0
  prod "Pain de campagne au levain"    SKU-CAMP-008  4.20 reduit-6 "$C_BREAD" 0 1
  prod "Macarons (×8)"                 SKU-MACAR-017 14.00 reduit-6 "$C_SWEET" 0 2
  prod "Quiche lorraine"               SKU-QUICHE-021 6.80 reduit-6 "$C_SAV"  1 0
  prod "Vin blanc — Côtes de Gascogne" SKU-VIN-031   12.90 standard "$C_DRINK" 0 0
else
  say "Products already present — skipping demo catalog"
fi
# WooCommerce stores coupon codes lowercase → match case-insensitively.
if ! $WP wc shop_coupon list --user="$ADMIN_USER" --field=code 2>/dev/null | grep -qix "bienvenue10"; then
  $WP wc shop_coupon create --code="BIENVENUE10" --discount_type=percent --amount=10 --minimum_amount=20 --user="$ADMIN_USER" >/dev/null || true
fi

# ── 6. Permalinks + CORS ─────────────────────────────────────────────
say "Setting permalinks + CORS origin ($STOREFRONT_ORIGIN)"
$WP rewrite structure '/%postname%/' >/dev/null
$WP rewrite flush >/dev/null
$WP option update atelier_storefront_origins "$STOREFRONT_ORIGIN" >/dev/null

SITE_URL="$($WP option get siteurl)"
say "Done. API base for api-config.js:"
echo "    $SITE_URL/wp-json/atelier/v1"
echo
echo "Next: add your Stripe test keys in WooCommerce → Settings → Payments → Stripe,"
echo "enable Card + Bancontact, then set BASE_URL in api-config.js to the URL above."
