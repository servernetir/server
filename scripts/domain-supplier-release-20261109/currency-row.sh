#!/bin/bash
# The currencies table is the single source of truth for reading every BIGINT
# minor amount. Its migration creates the table and seeds no row, so each code
# was added by hand; USD never was. DomainProcurement::readRate() requires the
# row and throws currency_not_configured without it, which makes every
# supplier offer unpriceable — the comparison table and procurement alike.
#
#   bash currency-row.sh show      read-only: the table, plus the currencies
#                                  the stored supplier offers actually use
#   bash currency-row.sh add-usd   insert USD only if it is absent
#
# add-usd writes exactly one row and never touches an existing one.
set -u

APP=${APP:-/home/servernetcloud/servernet_app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
MODE=${1:-show}
[ -d "$APP" ] || { echo "STOP: app root missing: $APP" >&2; exit 1; }
[ -x "$PHP" ] || { echo "STOP: php missing: $PHP" >&2; exit 1; }
case "$MODE" in show|add-usd) ;; *) echo "usage: bash $0 {show|add-usd}" >&2; exit 1 ;; esac

TMP=$(mktemp "$APP/.currency-row-XXXXXX.php") || { echo "STOP: cannot stage" >&2; exit 1; }
chmod 600 "$TMP"
trap 'rm -f "$TMP"' EXIT

cat > "$TMP" <<'PHP'
<?php
$root = __DIR__;
$mode = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function table_now() {
    echo "code  exponent  rounding_step  symbol  before  base  active\n";
    foreach (App\Models\Currency::orderBy('code')->get() as $c) {
        printf("%-5s %8s  %13s  %-6s  %-6s  %-4s  %s\n", $c->code, $c->exponent, $c->rounding_step,
            $c->symbol === '' ? '-' : $c->symbol, var_export((bool) $c->symbol_before, true),
            var_export((bool) $c->is_base, true), var_export((bool) $c->is_active, true));
    }
}

echo "=== currencies table ===\n";
table_now();

if ($mode === 'show') {
    echo "\n=== currencies the stored supplier offers use ===\n";
    $path = null;
    foreach ([$root.'/storage/app/private/pricing', $root.'/storage/app/pricing'] as $dir) {
        if (is_file($dir.'/domain-suppliers-catalogue-v1.json')) { $path = $dir.'/domain-suppliers-catalogue-v1.json'; break; }
    }
    if ($path === null) { echo "no snapshot\n"; return; }
    $snapshot = json_decode(file_get_contents($path), true);
    $seen = [];
    foreach (($snapshot['accounts'] ?? []) as $id => $stored) {
        foreach (($stored['offers'] ?? []) as $o) {
            if (is_array($o) && is_string($o['currency'] ?? null)) {
                $seen[$o['currency']] = 1 + ($seen[$o['currency']] ?? 0);
            }
        }
    }
    ksort($seen);
    foreach ($seen as $code => $count) {
        $row = App\Models\Currency::find($code);
        printf("%-5s %7d offers   row: %s\n", $code, $count,
            $row ? ('present, active='.var_export((bool) $row->is_active, true).', exponent='.$row->exponent)
                 : 'MISSING  <-- every offer in this currency is unpriceable');
    }
    return;
}

// add-usd
if (App\Models\Currency::find('USD')) {
    echo "\nUSD already present — nothing written.\n";
    return;
}
$base = App\Models\Currency::where('is_base', true)->first();
echo "\nbase currency stays: ".($base ? $base->code : 'none')."\n";
App\Models\Currency::create([
    'code' => 'USD',
    'exponent' => 2,          // supplier amount_minor values are cents
    'rounding_step' => 1,     // display only; Toman rounding uses the IRT step
    'symbol' => '$',
    'symbol_before' => true,
    'is_base' => false,       // exactly one base per install, and it is not this
    'is_active' => true,
]);
echo "inserted USD.\n\n=== currencies table after ===\n";
table_now();
PHP

cd "$APP" && $PHP "$TMP" "$MODE" 2>&1
echo
echo "=== DONE ($MODE) ==="
