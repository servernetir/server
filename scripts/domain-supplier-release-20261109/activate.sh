#!/bin/bash
# Turn multi-supplier domain selling on (or back off) in one pass.
#
#   bash activate.sh on     flag on, enable sales on every ready account,
#                           rebuild the catalogue and purge the page cache
#   bash activate.sh off    rollback: sales off, flag off, rebuild the legacy
#                           OpenProvider book and purge the page cache
#
# Why one script: DomainSupplierController::sales() refuses to enable an
# account while readinessIssues() is non-empty, and "feature_disabled" is one
# of those issues. So sales cannot be armed before the flag, and between the
# flag going on and the first account being armed, every public domain price
# comes from an empty catalogue. Doing both here keeps that gap to seconds
# instead of however long it takes a person to click twice.
#
# It does not bypass the controller's guard: it asks the model for
# readinessIssues() with the flag already on, and refuses any account that
# still reports one.
set -u

APP=${APP:-/home/servernetcloud/servernet_app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
MODE=${1:-}
case "$MODE" in on|off) ;; *) echo "usage: bash $0 {on|off}" >&2; exit 1 ;; esac
[ -d "$APP" ] || { echo "STOP: app root missing: $APP" >&2; exit 1; }
[ -x "$PHP" ] || { echo "STOP: php missing: $PHP" >&2; exit 1; }
[ -f "$APP/.env" ] || { echo "STOP: .env missing" >&2; exit 1; }

WANT=$([ "$MODE" = on ] && echo true || echo false)
KEY=DOMAIN_MULTI_PROVIDER_ENABLED

# PR #298 raised the health window to 900s. Without it, arming sales walks
# straight into the activation traps that release was written to close.
AGE=$($PHP -r '$c = require $argv[1]."/config/domain_suppliers.php"; echo (int) ($c["health_max_age_seconds"] ?? 0);' "$APP")
[ "$AGE" = 900 ] || { echo "STOP: config/domain_suppliers.php reports health_max_age_seconds=$AGE, expected 900 — install PR #298 first" >&2; exit 1; }

echo "=== 1. $KEY -> $WANT ==="
BACKUP="$APP/.env.bak-$(date -u +%Y%m%dT%H%M%SZ)"
cp -p "$APP/.env" "$BACKUP" || { echo "STOP: cannot back up .env" >&2; exit 1; }
echo "backup: $BACKUP"
if grep -q "^$KEY=" "$APP/.env"; then
  sed -i "s/^$KEY=.*/$KEY=$WANT/" "$APP/.env"
else
  printf '\n%s=%s\n' "$KEY" "$WANT" >> "$APP/.env"
fi
COUNT=$(grep -c "^$KEY=" "$APP/.env")
[ "$COUNT" = 1 ] || { echo "STOP: $KEY appears $COUNT times in .env — restore $BACKUP and fix by hand" >&2; exit 1; }
grep -n "^$KEY=" "$APP/.env"

TMP=$(mktemp "$APP/.activate-XXXXXX.php") || { echo "STOP: cannot stage" >&2; exit 1; }
chmod 600 "$TMP"
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<'PHP'
<?php
$root = __DIR__;
$mode = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

printf("config domain_suppliers.enabled = %s\n", var_export(config('domain_suppliers.enabled'), true));
if (config('domain_suppliers.enabled') !== ($mode === 'on')) {
    fwrite(STDERR, "STOP: the app did not pick up the .env change (a cached config?)\n");
    exit(1);
}

$accounts = App\Models\DomainSupplierAccount::where('active', true)->where('environment', 'production')->orderBy('id')->get();
foreach ($accounts as $a) {
    if ($mode === 'off') {
        $a->forceFill(['sales_enabled' => false])->save();
        printf("account %d %-34s sales -> false\n", $a->id, $a->label);
        continue;
    }
    $issues = $a->readinessIssues();
    if ($issues !== []) {
        printf("account %d %-34s NOT ARMED — %s\n", $a->id, $a->label, implode(', ', $issues));
        continue;
    }
    $a->forceFill(['sales_enabled' => true])->save();
    printf("account %d %-34s sales -> true\n", $a->id, $a->label);
}
PHP

echo
echo "=== 2. arming accounts ==="
cd "$APP" && $PHP "$TMP" "$MODE" || { echo "STOP: arming failed; .env backup is $BACKUP" >&2; exit 1; }

echo
echo "=== 3. rebuild catalogue and purge the page cache (CLI: no 30s web limit) ==="
cd "$APP" && $PHP artisan domains:price-book

echo
echo "=== 4. resulting state ==="
cat > "$TMP" <<'PHP'
<?php
$root = __DIR__;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
printf("multi-supplier selling: %s\n", var_export(config('domain_suppliers.enabled'), true));
foreach (App\Models\DomainSupplierAccount::orderBy('id')->get() as $a) {
    printf("account %d %-34s sales=%-5s eligible=%-5s issues=%s\n", $a->id, $a->label,
        var_export((bool) $a->sales_enabled, true), var_export($a->saleEligible(), true),
        $a->readinessIssues() === [] ? '-' : implode(',', $a->readinessIssues()));
}
// An offer is also dropped when the account balance cannot cover its debit,
// so a thin balance quietly shortens the public catalogue.
foreach (App\Models\DomainSupplierAccount::orderBy('id')->get() as $a) {
    printf("balance %-34s %s %s  (offers dearer than this are not offered)\n", $a->label,
        number_format((int) $a->balance_minor), (string) $a->balance_currency);
}
$prices = app(App\Services\Domain\DomainSupplierCatalogue::class)->prices();
printf("\npublic catalogue: %d priced suffixes\n", count($prices));
foreach (['com', 'net', 'org', 'io', 'store', 'site', 'ai'] as $tld) {
    if (! isset($prices[$tld])) { printf("  .%-6s  —\n", $tld); continue; }
    $p = $prices[$tld];
    printf("  .%-6s  register %12s   renew %12s%s\n", $tld,
        number_format((int) $p['register']),
        $p['renew'] === null ? '—' : number_format((int) $p['renew']),
        ! empty($p['is_promotion']) ? '   (promotional first term)' : '');
}
PHP
cd "$APP" && $PHP "$TMP"

echo
echo "=== DONE ($MODE).  .env backup: $BACKUP ==="
[ "$MODE" = on ] && echo "Rollback: bash $0 off"
exit 0
