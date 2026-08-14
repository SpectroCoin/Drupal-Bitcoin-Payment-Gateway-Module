#!/usr/bin/env bash
# ============================================================================
# Tier 1 smoke test — install the packaged module into a real Drupal with
# Drupal Commerce and prove it actually runs.
#
# Catches what unit tests cannot: an artifact shipped without its dependencies,
# a module Drupal refuses to enable, a payment gateway plugin the Commerce
# plugin manager never discovers, or a route that is declared but not
# registered. A route/parameter mismatch is precisely the kind of defect that
# source-level tests cannot see.
#
# Usage:
#   ./smoke.sh                    # package the working tree the way release.yml does
#   ./smoke.sh --artifact x.zip   # test an arbitrary zip (e.g. a CI artifact)
#   ./smoke.sh --keep             # leave the stack running for inspection
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
MODULE="commerce_spectrocoin"
ARTIFACT=""
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --artifact) ARTIFACT="${2:-}"; shift 2 ;;
    --keep)     KEEP=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; FAILED=$((FAILED+1)); }
FAILED=0

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --------------------------------------------------------------------------
# 1. Obtain the artifact a site builder would install.
# --------------------------------------------------------------------------
say "Packaging artifact"
if [ -n "$ARTIFACT" ]; then
  cp "$ARTIFACT" "$WORK/module.zip"
  echo "  using supplied artifact $ARTIFACT"
else
  # Mirror release.yml exactly.
  mkdir -p "$WORK/build/$MODULE"
  rsync -a --exclude='build' --exclude='.git*' --exclude='.github*' \
        --exclude='.vscode*' --exclude='tests' --exclude='*.md' \
        --exclude='.gitignore' "$ROOT/" "$WORK/build/$MODULE"
  ( cd "$WORK/build" && zip -qr "$WORK/module.zip" "$MODULE" )
  echo "  built from working tree ($(find "$WORK/build/$MODULE" -type f | wc -l | tr -d ' ') files)"
fi

unzip -qo "$WORK/module.zip" -d "$WORK/inspect"
[ -f "$WORK/inspect/$MODULE/$MODULE.info.yml" ] \
  && pass "artifact contains the module info file" \
  || fail "artifact is missing $MODULE.info.yml - Drupal will not see a module"

[ -f "$WORK/inspect/$MODULE/$MODULE.routing.yml" ] \
  && pass "artifact contains the routing file" \
  || fail "artifact is missing $MODULE.routing.yml"

# Drupal resolves dependencies through the site's composer, so the module ships
# no vendor tree of its own; assert the declared dependency instead.
grep -q 'commerce:commerce_payment' "$WORK/inspect/$MODULE/$MODULE.info.yml" \
  && pass "artifact declares its Commerce dependency" \
  || fail "artifact does not declare commerce_payment"

# --------------------------------------------------------------------------
# 2. Real Drupal + Drupal Commerce.
# --------------------------------------------------------------------------
say "Starting Drupal (installing Commerce via composer, this is slow)"
cd "$HERE"
docker compose down -v >/dev/null 2>&1 || true
docker compose up -d --build --wait >/dev/null 2>&1
dr() { docker compose exec -T drupal "$@"; }
drush() { dr /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@"; }

dr composer --working-dir=/opt/drupal require drush/drush drupal/commerce -q --no-interaction \
  > "$WORK/composer.log" 2>&1 \
  && pass "drush and Drupal Commerce installed" \
  || { fail "composer failed:"; tail -8 "$WORK/composer.log" | sed 's/^/        /'; }

drush site:install standard --yes --account-pass=admin \
  --db-url=mysql://root:root@db:3306/drupal --site-name=smoke \
  > "$WORK/install.log" 2>&1 \
  && pass "Drupal installed" \
  || { fail "site install failed:"; tail -8 "$WORK/install.log" | sed 's/^/        /'; }

drush en commerce_payment commerce_checkout --yes > /dev/null 2>&1 \
  && pass "Commerce payment enabled" || fail "could not enable Commerce payment"

# --------------------------------------------------------------------------
# 3. Install the artifact exactly as a site builder would.
# --------------------------------------------------------------------------
say "Installing the module"
docker compose cp "$WORK/module.zip" drupal:/tmp/module.zip >/dev/null
dr sh -c "mkdir -p /opt/drupal/web/modules/custom && rm -rf /opt/drupal/web/modules/custom/$MODULE \
          && unzip -qo /tmp/module.zip -d /opt/drupal/web/modules/custom" \
  && pass "module unpacked into modules/custom" || fail "module could not be unpacked"

drush en "$MODULE" --yes > "$WORK/enable.log" 2>&1 || true

# --------------------------------------------------------------------------
# 4. Assertions that only a real install can make.
# --------------------------------------------------------------------------
say "Verifying inside the running site"

if drush pm:list --status=enabled --field=name 2>/dev/null | grep -qi "spectrocoin"; then
  pass "module enabled"
else
  fail "module is NOT enabled. drush said:"; sed 's/^/        /' "$WORK/enable.log" | grep -v '^\s*$' | head -6
fi

# NB: drush php:eval does NOT propagate exit() codes - it returns 1 for any
# exit(), including exit(0). Assertions must print a sentinel instead.
# The gateway must be discovered by Commerce's plugin manager, not merely exist.
if drush php:eval '
  $m = \Drupal::service("plugin.manager.commerce_payment_gateway");
  print array_key_exists("spectrocoin", $m->getDefinitions()) ? "YES" : "NO";' 2>/dev/null | grep -q YES; then
  pass "payment gateway plugin discovered by Commerce"
else
  fail "payment gateway plugin NOT discovered - it would never be selectable"
fi

# Routes are the thing source-level tests cannot verify. Check each declared
# route is actually registered, and that the callback stayed POST-only.
for r in callback success failure; do
  if drush php:eval "
    try { \\Drupal::service('router.route_provider')->getRouteByName('${MODULE}.${r}'); print 'YES'; }
    catch (\\Throwable \$e) { print 'NO'; }" 2>/dev/null | grep -q YES; then
    pass "route ${MODULE}.${r} is registered"
  else
    fail "route ${MODULE}.${r} is NOT registered"
  fi
done

if drush php:eval "
  \$rt = \\Drupal::service('router.route_provider')->getRouteByName('${MODULE}.callback');
  print (in_array('POST', \$rt->getMethods(), true) && count(\$rt->getMethods()) === 1) ? 'YES' : 'NO';" 2>/dev/null | grep -q YES; then
  pass "callback route is POST-only"
else
  fail "callback route is not restricted to POST"
fi

# The client class must resolve through Drupal's autoloader.
if drush php:eval '
  print class_exists("Drupal\\commerce_spectrocoin\\SCMerchantClient\\SCMerchantClient") ? "YES" : "NO";' 2>/dev/null | grep -q YES; then
  pass "SCMerchantClient resolves via autoload"
else
  fail "SCMerchantClient does NOT resolve"
fi

# --------------------------------------------------------------------------
# 5. Nothing may have been logged as an error.
# --------------------------------------------------------------------------
say "Drupal log"
log=$(drush watchdog:show --count=100 --format=csv 2>/dev/null || true)
ours=$(printf '%s\n' "$log" | grep -iE "spectrocoin" | grep -iE "error|emergency|critical" || true)
[ -z "$ours" ] && pass "no errors attributable to the module" \
  || { fail "errors in watchdog:"; printf '%s\n' "$ours" | head -6; }

if [ "$KEEP" -eq 1 ]; then
  echo -e "\nstack left running: http://localhost:8083 (admin/admin)"
else
  docker compose down -v >/dev/null 2>&1 || true
fi

echo
[ "$FAILED" -eq 0 ] && echo "smoke test PASSED" || echo "smoke test FAILED ($FAILED check(s))"
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
