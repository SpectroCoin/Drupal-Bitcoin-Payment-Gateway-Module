#!/usr/bin/env bash
# ============================================================================
# Tier 2 end-to-end test — configure the gateway, put a real Drupal Commerce
# order through it, deliver callbacks, and assert what the shop actually does.
#
# Tier 1 proves the module enables and its routes register. This proves it
# *works*: that the order we send SpectroCoin describes the shop's order, and
# that every status on the wire moves the order where it should — or
# deliberately leaves it alone.
#
# The SpectroCoin API is stood in for by a stub answering as spectrocoin.com
# inside the compose network, over TLS signed by a CA generated here. No
# credentials, no live orders, no calls to the real API — and because the alias
# does the redirection, the module's own Config URLs are exercised as they ship.
#
# The SpectroCoin order is created by the gateway plugin's own
# createSpectroCoinInvoice(), so the payload is assembled by the module rather
# than reproduced here.
#
# Usage:
#   ./tier2.sh          # run the full flow
#   ./tier2.sh --keep   # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
MODULE="commerce_spectrocoin"
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --keep) KEEP=1; shift ;;
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

# --------------------------------------------------------------------------
# 1. A CA and a certificate for spectrocoin.com.
# --------------------------------------------------------------------------
say "Generating certificates for the stub"
rm -rf .certs && mkdir -p .certs
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -keyout .certs/ca.key -out .certs/ca.crt \
  -subj "/CN=SpectroCoin Tier2 Test CA" >/dev/null 2>&1
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
say "Starting Drupal, Drupal Commerce and the API stub (composer, this is slow)"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1

dr()   { docker compose exec -T drupal "$@"; }
stub() { docker compose exec -T spectrocoin "$@"; }
# --uri matters: the module builds absolute callback and success URLs, and a
# CLI request has no host of its own.
# Run drush as www-data, not root. Drush rebuilds Drupal's twig/php storage,
# and anything it creates as root leaves Apache unable to write it - after
# which every web request, including the callback, answers 500.
drush() { docker compose exec -T -u www-data drupal \
           /opt/drupal/vendor/bin/drush --root=/opt/drupal/web --uri=http://shop.test "$@"; }

# Trust the test CA. Appended rather than replacing the bundle.
dr sh -c 'cat /certs/ca.crt >> /etc/ssl/certs/ca-certificates.crt' >/dev/null 2>&1 || true

dr composer --working-dir=/opt/drupal require drush/drush drupal/commerce -q --no-interaction \
  > "$WORK/composer.log" 2>&1 && dr sh -c 'chown -R www-data:www-data /opt/drupal' \
  && pass "drush and Drupal Commerce installed" \
  || { fail "composer failed:"; tail -6 "$WORK/composer.log" | sed 's/^/        /'; }

drush site:install standard --yes --account-pass=admin \
  --db-url=mysql://root:root@db/drupal > "$WORK/install.log" 2>&1 \
  && pass "Drupal installed" \
  || { fail "site install failed:"; tail -6 "$WORK/install.log" | sed 's/^/        /'; }

drush en commerce_payment commerce_checkout commerce_product commerce_order --yes \
  > /dev/null 2>&1 && pass "Drupal Commerce enabled" || fail "could not enable Commerce"

# --------------------------------------------------------------------------
# 3. The module.
# --------------------------------------------------------------------------
say "Installing and configuring the module"
dr sh -c "rm -rf /opt/drupal/web/modules/custom/$MODULE && mkdir -p /opt/drupal/web/modules/custom/$MODULE"
docker compose cp "$ROOT/." "drupal:/tmp/module" >/dev/null 2>&1
dr sh -c "rm -rf /tmp/module/tests /tmp/module/.git \
          && cp -a /tmp/module/. /opt/drupal/web/modules/custom/$MODULE/ \
          && chown -R www-data:www-data /opt/drupal/web/modules/custom"

drush en "$MODULE" --yes > "$WORK/enable.log" 2>&1 || true
# Rebuild the router here rather than on the first web request, so the
# module's fresh routes are resolvable straight away.
drush cr > /dev/null 2>&1 || true
if drush pm:list --status=enabled --field=name 2>/dev/null | grep -qi spectrocoin; then
  pass "module enabled"
else
  fail "module is NOT enabled. drush said:"; sed 's/^/        /' "$WORK/enable.log" | head -6
fi

# Configure the gateway as a site builder would through the admin UI.
# NB: drush php:eval does NOT propagate exit() codes, so every assertion below
# prints a sentinel and greps for it instead.
# Drupal Commerce ships no currencies: without importing one, any price
# operation dies with 'Could not load the "EUR" currency'.
drush php:eval '
  \Drupal::service("commerce_price.currency_importer")->import("EUR");
  print "CURRENCY";' > "$WORK/ccy.log" 2>&1 || true
grep -q CURRENCY "$WORK/ccy.log" \
  && pass "EUR currency imported" \
  || { fail "currency import failed:"; tail -4 "$WORK/ccy.log" | sed 's/^/        /'; }

drush php:eval '
  $gw = \Drupal\commerce_payment\Entity\PaymentGateway::create([
    "id" => "spectrocoin_tier2",
    "label" => "SpectroCoin",
    "plugin" => "spectrocoin",
    "configuration" => [
      "project_id" => "tier2-project",
      "client_id" => "tier2-client",
      "client_secret" => "tier2-secret",
      "mode" => "live",
    ],
  ]);
  $gw->save();
  print "SAVED";' > "$WORK/gw.log" 2>&1 || true
grep -q SAVED "$WORK/gw.log" \
  && pass "payment gateway configured" \
  || { fail "gateway could not be configured:"; tail -5 "$WORK/gw.log" | sed 's/^/        /'; }

# --------------------------------------------------------------------------
# 4. Place a real order and let the module create the SpectroCoin order.
# --------------------------------------------------------------------------
say "Placing an order through the gateway"
stub curl -fsS -X POST http://localhost/__test/reset >/dev/null 2>&1

drush php:eval '
  $store_storage = \Drupal::entityTypeManager()->getStorage("commerce_store");
  $stores = $store_storage->loadMultiple();
  if ($stores) {
    $store = reset($stores);
  }
  else {
    $store = $store_storage->create([
      "type" => "online",
      "name" => "Tier2",
      "mail" => "tier2@example.com",
      "address" => ["country_code" => "LT"],
      "default_currency" => "EUR",
      "billing_countries" => ["LT"],
    ]);
    $store->save();
  }

  $order = \Drupal\commerce_order\Entity\Order::create([
    "type" => "default",
    "store_id" => $store->id(),
    "state" => "draft",
    "mail" => "tier2@example.com",
  ]);
  $item = \Drupal\commerce_order\Entity\OrderItem::create([
    "type" => "default",
    "title" => "Tier 2 test item",
    "quantity" => 1,
    "unit_price" => new \Drupal\commerce_price\Price("12.34", "EUR"),
  ]);
  $item->save();
  $order->addItem($item);
  $order->save();

  // The gateway plugin creates the payment entity and the SpectroCoin order.
  $gw = \Drupal\commerce_payment\Entity\PaymentGateway::load("spectrocoin_tier2");
  $payment = \Drupal::entityTypeManager()->getStorage("commerce_payment")->create([
    "state" => "new",
    "amount" => $order->getTotalPrice(),
    "payment_gateway" => $gw->id(),
    "order_id" => $order->id(),
  ]);
  $response = $gw->getPlugin()->createSpectroCoinInvoice($payment, []);

  $payments = \Drupal::entityTypeManager()->getStorage("commerce_payment")
    ->loadByProperties(["order_id" => $order->id()]);
  $created = $payments ? end($payments) : NULL;

  print "ORDER=" . $order->id()
      . " PAYMENT=" . ($created ? $created->id() : 0)
      . " TOTAL=" . $order->getTotalPrice()->getNumber()
      . " CCY=" . $order->getTotalPrice()->getCurrencyCode();
' > "$WORK/place.log" 2>&1 || true

DR_ORDER=$(sed -n 's/.*ORDER=\([0-9]*\).*/\1/p' "$WORK/place.log" | head -1)
DR_PAYMENT=$(sed -n 's/.*PAYMENT=\([0-9]*\).*/\1/p' "$WORK/place.log" | head -1)
DR_CCY=$(sed -n 's/.*CCY=\([A-Z]*\).*/\1/p' "$WORK/place.log" | head -1)

if [ -n "${DR_ORDER:-}" ] && [ "${DR_ORDER:-0}" -gt 0 ] 2>/dev/null; then
  pass "Drupal Commerce order #$DR_ORDER created (payment #$DR_PAYMENT)"
else
  fail "no order was created:"; tail -8 "$WORK/place.log" | sed 's/^/        /'
fi

# --------------------------------------------------------------------------
# 5. What the module actually sent us.
# --------------------------------------------------------------------------
say "Inspecting the request the module sent"
stub curl -fsS http://localhost/__test/requests > "$WORK/requests.json" 2>/dev/null

created=$(python3 - "$WORK/requests.json" <<'PYEOF'
import json,sys
for r in json.load(open(sys.argv[1])):
    if r["path"].endswith("/orders/create"):
        print(json.dumps({**json.loads(r["body"] or "{}"), "_ua": r["user_agent"]}))
        break
PYEOF
)
[ -n "$created" ] || created='{}'
field() { printf '%s' "$created" | python3 -c "import json,sys;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }

if [ -n "$(field orderId)" ]; then
  pass "an order was sent to SpectroCoin"
else
  fail "no create-order request reached SpectroCoin:"; tail -6 "$WORK/place.log" | sed 's/^/        /'
fi

# Drupal pairs the order with its payment: "<order>-<payment>" is what the
# callback splits back apart, so both halves must be right.
[ "$(field orderId)" = "$DR_ORDER-$DR_PAYMENT" ] \
  && pass "orderId pairs the order with its payment ($DR_ORDER-$DR_PAYMENT)" \
  || fail "orderId was '$(field orderId)', expected '$DR_ORDER-$DR_PAYMENT'"

# Both being empty is a failure, not a match.
if [ -n "$DR_CCY" ] && [ "$(field receiveCurrencyCode)" = "$DR_CCY" ]; then
  pass "order was sent in the shop's currency ($DR_CCY)"
else
  fail "receiveCurrencyCode was '$(field receiveCurrencyCode)', order is in '${DR_CCY:-none}'"
fi

if python3 -c "
import sys
sys.exit(0 if abs(float('$(field receiveAmount)' or 'nan') - 12.34) < 0.005 else 1)" 2>/dev/null; then
  pass "order was sent for the shop's total (12.34)"
else
  fail "receiveAmount was '$(field receiveAmount)', order total is 12.34"
fi

case "$(field callbackUrl)" in
  *commerce-spectrocoin/callback*) pass "callbackUrl points at the module's endpoint" ;;
  *) fail "unexpected callbackUrl: '$(field callbackUrl)'" ;;
esac

[ "$(field projectId)" = "tier2-project" ] \
  && pass "projectId is the configured one" \
  || fail "projectId was '$(field projectId)'"

case "$(field _ua)" in
  SpectroCoin-Drupal/*) pass "identifies itself as $(field _ua)" ;;
  *) fail "User-Agent was '$(field _ua)', expected SpectroCoin-Drupal/<version>" ;;
esac

UUID=$(stub sh -c 'php -r "\$s=json_decode(file_get_contents(\"/tmp/stub-state.json\"),true); echo array_key_first(\$s[\"orders\"]);"' 2>/dev/null)
[ -n "$UUID" ] && pass "SpectroCoin order created (uuid ${UUID:0:8}…)" \
               || fail "no SpectroCoin order was created"

# --------------------------------------------------------------------------
# 6. Deliver callbacks and assert what the shop does with each status.
# --------------------------------------------------------------------------
say "Delivering callbacks for every status on the wire"

CB="http://shop.test/commerce-spectrocoin/callback"
# Delivered from the stub container: in production the callback comes from
# SpectroCoin's server, and only a container on this network resolves shop.test.
shopcurl() { docker compose exec -T spectrocoin curl "$@"; }

# Drupal serves 500s until its container and router caches are built, and the
# first request after an install is not the one that finishes the job. Wait for
# the callback route to actually resolve - a GET answering 405 means routing is
# live and the POST-only constraint is in force - rather than assuming.
ready=0
for _ in $(seq 1 60); do
  if [ "$(shopcurl -s -o /dev/null -w '%{http_code}' "$CB")" = "405" ]; then ready=1; break; fi
  sleep 3
done
[ "$ready" = "1" ] && pass "the site is serving and the callback route resolves" \
                   || fail "the callback route never became resolvable"

patch_order() {
  stub curl -fsS -X POST -H 'Content-Type: application/json' -d "$1" \
    http://localhost/__test/status >/dev/null 2>&1
}

order_state() {
  drush php:eval "
    \$o = \Drupal\commerce_order\Entity\Order::load($DR_ORDER);
    print 'STATE=' . (\$o ? \$o->getState()->getId() : 'gone');" 2>/dev/null \
    | sed -n 's/.*STATE=\([a-z_]*\).*/\1/p' | head -1
}

reset_order() {
  drush php:eval "
    \$o = \Drupal\commerce_order\Entity\Order::load($DR_ORDER);
    \$o->set('state', 'draft'); \$o->save(); print 'OK';" >/dev/null 2>&1
}

check_status() {
  local status="$1" want="$2" note="${3:-}"
  reset_order
  patch_order "{\"uuid\":\"$UUID\",\"status\":\"$status\"}"
  local code got
  code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
  got=$(order_state)
  if [ "$code" = "200" ] && [ "$got" = "$want" ]; then
    pass "$status -> $want${note:+ ($note)}"
  else
    fail "$status gave HTTP $code and state '$got', expected 200 and '$want'${note:+ ($note)}"
  fi
}

check_status NEW     draft "no change"
check_status PENDING draft "no change"
check_status PAID    completed
check_status FAILED          canceled
check_status CANCELLED       canceled
check_status REJECTED        canceled
check_status INVALID_PAYMENT canceled
check_status EXPIRED         expired

# Informational statuses report on a payment already under way. The order must
# be left exactly as it was: transitioning here would either fulfil an order
# that was not paid in full, or reverse one the merchant already settled.
for s in PARTIAL_PAYMENT UNDERPAID LATE_CRYPTO_PAYMENT PENDING_LATE_CRYPTO_PAYMENT \
         PROCESSING_REFUND REFUNDED REJECTED_REFUND TEST TEST_PAID TEST_EXPIRED; do
  check_status "$s" draft "informational, no change"
done

# A settled payment must also mark the payment entity, not just the order.
reset_order
patch_order "{\"uuid\":\"$UUID\",\"status\":\"PAID\"}"
shopcurl -s -o /dev/null -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB" >/dev/null 2>&1
pstate=$(drush php:eval "
  \$p = \Drupal::entityTypeManager()->getStorage('commerce_payment')->load($DR_PAYMENT);
  print 'PSTATE=' . (\$p ? \$p->getState()->getId() : 'gone');" 2>/dev/null \
  | sed -n 's/.*PSTATE=\([a-z_]*\).*/\1/p' | head -1)
[ "$pstate" = "completed" ] \
  && pass "the payment entity is marked completed" \
  || fail "payment #$DR_PAYMENT is in state '$pstate', expected completed"

# --------------------------------------------------------------------------
# 7. The callback endpoint is a public URL. It must refuse the obvious abuse.
# --------------------------------------------------------------------------
say "Callback endpoint guards"

code=$(shopcurl -s -o /dev/null -w '%{http_code}' "$CB")
[ "$code" = "405" ] && pass "GET is refused (405)" \
                    || fail "GET returned $code, expected 405 - the callback must be POST-only"

patch_order "{\"uuid\":\"$UUID\",\"orderId\":\"999999-1\"}"
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
if [ "$code" = "404" ] || [ "$code" = "400" ]; then
  pass "a callback for an unknown order is refused ($code)"
else
  fail "unknown order returned $code, expected 404 or 400"
fi

# Restore the mapping, then disagree about the currency.
patch_order "{\"uuid\":\"$UUID\",\"orderId\":\"$DR_ORDER-$DR_PAYMENT\",\"receiveCurrencyCode\":\"XXX\",\"status\":\"PAID\"}"
reset_order
code=$(shopcurl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
  -d "{\"id\":\"$UUID\",\"merchantApiId\":\"tier2-api\"}" "$CB")
now=$(order_state)
if [ "$code" = "400" ] && [ "$now" = "draft" ]; then
  pass "a settlement in the wrong currency is refused (400)"
else
  fail "currency mismatch returned $code and left the order '$now'"
fi

# --------------------------------------------------------------------------
# 8. Nothing may have been logged as an error by the module.
# --------------------------------------------------------------------------
say "Drupal log"
# Scoped to the module's own log channel. Matching on the message or the URL
# instead sweeps up Drupal's own note that the GET guard returned 405, and the
# container's inability to send mail - neither of which is a module fault.
log=$(drush watchdog:show --count=100 --type=commerce_spectrocoin --severity=Error \
        --format=csv 2>/dev/null || true)
# The guard tests deliberately provoke these.
ours=$(printf '%s\n' "$log" | grep -viE "unknown order|not found|does not match|no state change" \
  | grep -iE "spectrocoin" || true)
[ -z "$ours" ] && pass "no unexpected errors logged by the module" \
  || { fail "errors in the log:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: add '127.0.0.1 shop.test' to /etc/hosts, then"
  echo    "http://shop.test:8089 (admin/admin)"
else
  docker compose down -v >/dev/null 2>&1 || true
  rm -rf .certs
fi

echo
[ "$FAILED" -eq 0 ] && echo "tier 2 PASSED" || echo "tier 2 FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
