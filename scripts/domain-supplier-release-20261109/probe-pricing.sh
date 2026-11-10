#!/bin/bash
# Read-only: why does the supplier comparison price zero offers?
#
# compareCatalogues() wraps every offer in `catch (\Throwable)` so an
# unpriceable offer is absent rather than zero. That is right for the page and
# useless for debugging: if costedOffer() throws for every offer — a missing
# currency row, no FX, or an `at`/`source_at` older than fx_max_age_seconds —
# the table is empty with nothing logged. This calls the same code on one real
# offer and prints the exception instead of swallowing it.
#
# Boots the app read-only. Writes nothing, contacts no supplier, prints no key.
set -u

APP=${APP:-/home/servernetcloud/servernet_app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
[ -d "$APP" ] || { echo "STOP: app root missing: $APP" >&2; exit 1; }
[ -x "$PHP" ] || { echo "STOP: php missing: $PHP" >&2; exit 1; }

TMP=$(mktemp "$APP/.probe-pricing-XXXXXX.php") || { echo "STOP: cannot stage probe" >&2; exit 1; }
chmod 600 "$TMP"
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<'PHP'
<?php
$root = __DIR__;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$line = function ($k, $v) { printf("%-26s %s\n", $k.':', $v); };
$show = function ($v) { return is_bool($v) ? var_export($v, true) : (is_null($v) ? 'null' : (string) $v); };

echo "=== CURRENCY ROWS ===\n";
foreach (['USD', 'IRT'] as $code) {
    $c = App\Models\Currency::find($code);
    $line($code, $c ? ('exists active='.$show($c->is_active).' exponent='.$show($c->exponent)
        .' rounding_step='.$show($c->rounding_step ?? null)) : 'MISSING  <-- currency_not_configured');
}

echo "\n=== FX FOR USD (both at and source_at must be newer than fx_max_age_seconds) ===\n";
$maxAge = (int) config('domain_suppliers.fx_max_age_seconds', 172800);
$line('fx_max_age_seconds', $maxAge.'  ('.round($maxAge / 3600, 1).' h)');
try {
    $row = app(App\Services\ExchangeRate::class)->current('USD');
    if (! $row) {
        $line('current(USD)', 'NULL  <-- fx_unavailable');
    } else {
        $line('rate_toman', $show($row['rate_toman'] ?? null).(is_int($row['rate_toman'] ?? null) ? '' : '  <-- not an int: fx_unavailable'));
        foreach (['at', 'source_at'] as $f) {
            $v = $row[$f] ?? null;
            if (! is_string($v)) { $line($f, $show($v).'  <-- missing: fx_unavailable'); continue; }
            $age = time() - strtotime($v);
            $line($f, $v.'   age='.round($age / 3600, 1).' h'
                .($age > $maxAge ? '  <-- OLDER THAN THE LIMIT: fx_expired' : ($age < -300 ? '  <-- IN THE FUTURE: fx_expired' : '  ok')));
        }
        $line('other keys', implode(', ', array_keys(array_diff_key($row, array_flip(['rate_toman', 'at', 'source_at'])))));
    }
} catch (\Throwable $e) {
    $line('current(USD) threw', get_class($e).': '.$e->getMessage());
}

echo "\n=== MARGIN AND ROUNDING ===\n";
$line('domain_margin_pct', $show(App\Models\Setting::get('domain_margin_pct')));
$line('min_margin_pct', $show(config('domain_reseller.min_margin_pct', 0)));

echo "\n=== PRICING ONE REAL OFFER FROM THE SNAPSHOT ===\n";
$path = null;
foreach ([$root.'/storage/app/private/pricing', $root.'/storage/app/pricing'] as $dir) {
    if (is_file($dir.'/domain-suppliers-catalogue-v1.json')) { $path = $dir.'/domain-suppliers-catalogue-v1.json'; break; }
}
if ($path === null) { echo "snapshot missing\n"; return; }
$snapshot = json_decode(file_get_contents($path), true);
$procurement = app(App\Services\Domain\DomainProcurement::class);
$method = new ReflectionMethod($procurement, 'costedOffer');
$method->setAccessible(true);

foreach (($snapshot['accounts'] ?? []) as $id => $stored) {
    $picked = null;
    foreach (($stored['offers'] ?? []) as $o) {
        if (is_array($o) && ($o['operation'] ?? null) === 'create' && ($o['period_months'] ?? null) === 12
            && ($o['premium'] ?? null) === false && ($o['tax_included'] ?? null) === true
            && is_string($o['valid_until'] ?? null) && strtotime($o['valid_until']) > time()) { $picked = $o; break; }
    }
    if ($picked === null) { echo "account $id: no offer passes the five filters\n"; continue; }
    $tld = (string) $picked['tld'];
    echo "account $id, tld '$tld':\n";
    $line('  DomainSearch::sells', $show(App\Services\Domain\DomainSearch::sells($tld)));
    try {
        $priced = $method->invoke($procurement, $tld, $picked);
        $line('  wholesale_toman', $show($priced['wholesale_toman'] ?? null));
        $line('  sell_toman', $show($priced['sell_toman'] ?? null));
        echo "  PRICED OK — this offer would appear in the table\n";
    } catch (\Throwable $e) {
        echo "  THREW ".get_class($e).': '.$e->getMessage()."   <-- this is why the table is empty\n";
    }
}
PHP

cd "$APP" && $PHP "$TMP" 2>&1
echo
echo "=== DONE (probe removed, nothing written) ==="
