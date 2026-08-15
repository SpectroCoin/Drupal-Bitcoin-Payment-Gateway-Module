#!/usr/bin/env bash
# ============================================================================
# Tier 3 end-to-end test — a real shopper, in a real browser, through a real
# Drupal Commerce checkout.
#
# Tier 2 calls the gateway plugin's createSpectroCoinInvoice() directly: it
# proves the payload and the callback contract, but never that a shopper can
# reach the gateway. SpectroCoin is an off-site Commerce payment gateway
# offered from a radio button in the checkout's "order_information" pane, so
# the only way to answer "can a shopper actually pay with this" is to walk a
# shopper there.
#
# Two stock "manual" gateways are seeded alongside SpectroCoin. Commerce hides
# the payment-method radios entirely when only one gateway is configured, so a
# fixture with just ours would make "the gateway is offered" untestable in
# general - and would make a negative run (ours disabled) collapse to a single
# remaining gateway with nothing left to control against.
#
# The SpectroCoin API is stood in for by a stub answering as spectrocoin.com
# inside the compose network, over TLS signed by a CA generated here. No
# credentials, no live orders, no calls to the real API - and because the alias
# does the redirection, the module's own Config URLs are exercised as they ship.
#
# Usage:
#   ./tier3.sh                      # run the full journey
#   ./tier3.sh --keep               # leave the stack running for inspection
#   ./tier3.sh --disable-spectrocoin
#       Configures the SpectroCoin gateway but leaves it disabled, so the
#       module-specific assertions are expected to fail while the stock-method
#       control still passes. Used to verify the test can actually fail.
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
MODULE="commerce_spectrocoin"
KEEP=0
DISABLE_SPECTROCOIN=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
    --disable-spectrocoin) DISABLE_SPECTROCOIN=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cd "$HERE"
rm -rf artifacts && mkdir -p artifacts

# --------------------------------------------------------------------------
# 1. A CA and a certificate for spectrocoin.com.
# --------------------------------------------------------------------------
say "Generating certificates for the stub"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier3 Test CA" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout .certs/server.key -out .certs/server.csr \
  -subj "/CN=spectrocoin.com" >/dev/null 2>&1
printf 'subjectAltName=DNS:spectrocoin.com\n' > .certs/ext
openssl x509 -req -in .certs/server.csr -CA .certs/ca.crt -CAkey .certs/ca.key \
  -CAcreateserial -out .certs/server.crt -days 3650 -extfile .certs/ext >/dev/null 2>&1
chmod 644 .certs/*
[ -s .certs/server.crt ] && pass "issued a certificate for spectrocoin.com" \
  || fail "certificate generation failed"

# --------------------------------------------------------------------------
# 2. The stack.
# --------------------------------------------------------------------------
say "Starting Drupal, Drupal Commerce, a browser and the API stub (composer, this is slow)"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

dr()   { docker compose exec -T drupal "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
pw()   { docker compose exec -T playwright "$@"; }
# --uri matters: the module builds absolute callback and success URLs, and a
# CLI request has no host of its own.
# Run drush as www-data, not root. Drush rebuilds Drupal's twig/php storage,
# and anything it creates as root leaves Apache unable to write it - after
# which every web request, including checkout, answers 500.
drush() { docker compose exec -T -u www-data drupal \
           /opt/drupal/vendor/bin/drush --root=/opt/drupal/web --uri=http://shop.test "$@"; }

# Trust the test CA. Appended rather than replacing the bundle.
dr sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true

dr composer --working-dir=/opt/drupal require drush/drush drupal/commerce -q --no-interaction \
  > "$WORK/composer.log" 2>&1 && dr sh -c 'chown -R www-data:www-data /opt/drupal' \
  && pass "drush and Drupal Commerce installed" \
  || { fail "composer failed:"; tail -6 "$WORK/composer.log" | sed 's/^/        /'; }

# The healthcheck above already made one HTTP request against install.php
# before Commerce existed, and the image's opcache-recommended.ini only
# re-checks file mtimes every 60 seconds (opcache.revalidate_freq=60). Left
# alone, Apache's workers keep serving that pre-Commerce autoloader map for up
# to a minute - long enough, on a fast run, to still be live when the shopper
# hits the product page, which then 500s on "Class CommerceGuys\Intl\...
# CurrencyRepository not found" with its body suppressed, indistinguishable
# from a page that simply has no add-to-cart form. A graceful restart forces
# fresh workers with a clean opcache instead of waiting out the window.
dr sh -c 'apache2ctl -k graceful' >/dev/null 2>&1 \
  && pass "Apache restarted so the new Commerce autoloader is actually live" \
  || fail "could not restart Apache after composer require"

drush site:install standard --yes --account-pass=admin \
  --db-url=mysql://root:root@db/drupal > "$WORK/install.log" 2>&1 \
  && pass "Drupal installed" \
  || { fail "site install failed:"; tail -6 "$WORK/install.log" | sed 's/^/        /'; }

drush en commerce_payment commerce_checkout commerce_product commerce_order commerce_cart --yes \
  > /dev/null 2>&1 && pass "Drupal Commerce enabled" || fail "could not enable Commerce"

if ! dr sh -c 'curl -fsS -o /dev/null https://spectrocoin.com/__test/requests'; then
  fail "the shop cannot reach the stub over TLS - checkout will fail with cURL error 60"
else
  pass "the shop trusts the stub's certificate"
fi

# --------------------------------------------------------------------------
# 3. The module, built the way release.yml builds it.
# --------------------------------------------------------------------------
say "Installing and configuring the module"
dr sh -c "rm -rf /opt/drupal/web/modules/custom/$MODULE && mkdir -p /opt/drupal/web/modules/custom/$MODULE"
docker compose cp "$ROOT/." "drupal:/tmp/module" >/dev/null 2>&1
dr sh -c "rm -rf /tmp/module/tests /tmp/module/.git \
          && cp -a /tmp/module/. /opt/drupal/web/modules/custom/$MODULE/ \
          && chown -R www-data:www-data /opt/drupal/web/modules/custom"

drush en "$MODULE" --yes > "$WORK/enable.log" 2>&1 || true
drush cr > /dev/null 2>&1 || true
if drush pm:list --status=enabled --field=name 2>/dev/null | grep -qi spectrocoin; then
  pass "module enabled"
else
  fail "module is NOT enabled. drush said:"; sed 's/^/        /' "$WORK/enable.log" | head -6
fi

# Drupal Commerce ships no currencies: without importing one, any price
# operation dies with 'Could not load the "EUR" currency'.
drush php:eval '
  \Drupal::service("commerce_price.currency_importer")->import("EUR");
  print "CURRENCY";' > "$WORK/ccy.log" 2>&1 || true
grep -q CURRENCY "$WORK/ccy.log" \
  && pass "EUR currency imported" \
  || { fail "currency import failed:"; tail -4 "$WORK/ccy.log" | sed 's/^/        /'; }

# Configure the gateway as a site builder would through the admin UI, plus two
# stock "manual" gateways - the control this tier's assertions need. Commerce
# hides the payment-method radios outright when only one gateway exists, so
# without a second (and, for the negative run, a third) option there would be
# nothing left to prove "ours is missing" against.
SC_STATUS='TRUE'
[ "$DISABLE_SPECTROCOIN" -eq 1 ] && SC_STATUS='FALSE'
drush php:eval "
  \$gw = \Drupal\commerce_payment\Entity\PaymentGateway::create([
    'id' => 'spectrocoin_tier3',
    'label' => 'SpectroCoin',
    'plugin' => 'spectrocoin',
    'status' => $SC_STATUS,
    'configuration' => [
      'project_id' => 'tier3-project',
      'client_id' => 'tier3-client',
      'client_secret' => 'tier3-secret',
      'mode' => 'live',
    ],
  ]);
  \$gw->save();
  \$manual = \Drupal\commerce_payment\Entity\PaymentGateway::create([
    'id' => 'manual_tier3',
    'label' => 'Cash on delivery',
    'plugin' => 'manual',
    'configuration' => [
      'display_label' => 'Cash on delivery',
      'instructions' => ['value' => 'Pay in cash on delivery.', 'format' => 'plain_text'],
    ],
  ]);
  \$manual->save();
  \$manual2 = \Drupal\commerce_payment\Entity\PaymentGateway::create([
    'id' => 'manual_tier3_b',
    'label' => 'Bank transfer',
    'plugin' => 'manual',
    'configuration' => [
      'display_label' => 'Bank transfer',
      'instructions' => ['value' => 'Pay by wire.', 'format' => 'plain_text'],
    ],
  ]);
  \$manual2->save();
  print 'SAVED=' . (\$gw->status() ? 'sc-on' : 'sc-off');
" > "$WORK/gw.log" 2>&1 || true
if [ "$DISABLE_SPECTROCOIN" -eq 1 ]; then
  grep -q 'SAVED=sc-off' "$WORK/gw.log" \
    && pass "gateways configured (SpectroCoin deliberately disabled)" \
    || { fail "gateway setup did not leave SpectroCoin disabled:"; tail -5 "$WORK/gw.log" | sed 's/^/        /'; }
else
  grep -q 'SAVED=sc-on' "$WORK/gw.log" \
    && pass "gateways configured" \
    || { fail "gateway setup failed:"; tail -5 "$WORK/gw.log" | sed 's/^/        /'; }
fi

# --------------------------------------------------------------------------
# 4. A store and something to buy.
# --------------------------------------------------------------------------
say "Setting up the shop"
drush php:eval '
  $store = \Drupal::entityTypeManager()->getStorage("commerce_store")->create([
    "type" => "online",
    "name" => "Tier3",
    "mail" => "tier3@example.com",
    "address" => ["country_code" => "LT"],
    "default_currency" => "EUR",
    "billing_countries" => ["LT"],
  ]);
  $store->save();
  print "STORE=" . $store->id();
' > "$WORK/store.log" 2>&1 || true
grep -q 'STORE=' "$WORK/store.log" && pass "store created" || fail "store creation failed"

drush php:eval '
  $variation = \Drupal\commerce_product\Entity\ProductVariation::create([
    "type" => "default",
    "sku" => "tier3-sku-1",
    "price" => new \Drupal\commerce_price\Price("12.34", "EUR"),
  ]);
  $variation->save();
  $product = \Drupal\commerce_product\Entity\Product::create([
    "type" => "default",
    "title" => "Tier 3 test item",
    "stores" => [1],
    "variations" => [$variation],
  ]);
  $product->save();
  print "PRODUCT=" . $product->id();
' > "$WORK/product.log" 2>&1 || true
PRODUCT_ID=$(sed -n 's/.*PRODUCT=\([0-9]*\).*/\1/p' "$WORK/product.log" | head -1)
[ -n "${PRODUCT_ID:-}" ] && pass "product created (#$PRODUCT_ID)" \
                         || { fail "no product could be created:"; tail -6 "$WORK/product.log" | sed 's/^/        /'; }

stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

# --------------------------------------------------------------------------
# 5. The shopper.
# --------------------------------------------------------------------------
say "Walking a shopper through checkout"
PRODUCT_URL="http://shop.test/product/${PRODUCT_ID:-1}"
# The image carries the browsers but not the client library; pin it to the
# image's own version so the two cannot drift apart.
pw sh -c 'cd /work && [ -d node_modules/playwright ] || npm --silent i playwright@1.50.0' \
  > "$WORK/npm.log" 2>&1 || true
pw sh -c 'node -e "require(\"playwright\")"' >/dev/null 2>&1 \
  && pass "browser client available" \
  || { fail "playwright module could not be installed:"; tail -4 "$WORK/npm.log" | sed 's/^/        /'; }

pw sh -c "SHOP_URL=http://shop.test PRODUCT_URL='$PRODUCT_URL' \
          GATEWAY_LABEL='Redirect to SpectroCoin' STOCK_LABEL='Cash on delivery' \
          node /work/checkout.mjs" > "$WORK/browser.log" 2>&1 || true

# A browser run that produces no verdicts at all is a failure in itself, not a
# silent pass - and `set -o pipefail` would otherwise abort the script here.
if ! grep -aqE '^(PASS|FAIL)' "$WORK/browser.log"; then
  fail "the browser run produced no verdicts:"
  tail -12 "$WORK/browser.log" | sed 's/^/        /'
fi

grep -aE '^(PASS|FAIL|INFO)' "$WORK/browser.log" 2>/dev/null | while read -r line; do
  case "$line" in
    PASS*) printf '  \033[32mPASS\033[0m  %s\n' "${line#PASS }" ;;
    FAIL*) printf '  \033[31mFAIL\033[0m  %s\n' "${line#FAIL }" ;;
    INFO*) printf '  \033[33mNOTE\033[0m  %s\n' "${line#INFO }" ;;
  esac
done
browser_failures=$(grep -ac '^FAIL' "$WORK/browser.log" 2>/dev/null || true)
browser_failures=${browser_failures:-0}
FAILED=$((FAILED + browser_failures))
if [ "$browser_failures" -gt 0 ]; then
  echo "        --- browser log tail ---"
  tail -16 "$WORK/browser.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 6. What the shop and SpectroCoin ended up with.
# --------------------------------------------------------------------------
say "Verifying the order that resulted"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/orders/create"):
        print(json.dumps(json.loads(r["body"] or "{}")))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ -n "$(field orderId)" ]; then
  pass "checkout produced a SpectroCoin order ($(field orderId))"
else
  fail "checkout never reached SpectroCoin - no create-order request arrived"
fi

if python3 -c "
import sys
sys.exit(0 if abs(float('$(field receiveAmount)' or 'nan') - 12.34) < 0.005 else 1)" 2>/dev/null; then
  pass "the order was sent for the cart total (12.34)"
else
  fail "receiveAmount was '$(field receiveAmount)', expected the cart total 12.34"
fi

case "$(field callbackUrl)" in
  *commerce-spectrocoin/callback*) pass "callbackUrl points at the module's endpoint" ;;
  *) fail "unexpected callbackUrl: '$(field callbackUrl)'" ;;
esac

say "Drupal log"
log=$(drush watchdog:show --count=100 --type="$MODULE" --severity=Error --format=csv 2>/dev/null || true)
[ -z "$log" ] && pass "no errors logged by the module" \
  || { fail "errors in the log:"; printf '%s\n' "$log" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8095 (admin/admin)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 3 PASSED" || echo "tier 3 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
