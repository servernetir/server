#!/bin/bash
# Read-only diagnosis of an empty multi-supplier catalogue comparison.
#
# The admin page builds its comparison from the snapshot written by
# DomainSupplierCatalogue::refresh(). DomainProcurement::compareCatalogues()
# then drops any offer that is not create / the requested period / non-premium
# / tax-included / still valid, so an empty table means either an empty
# snapshot or offers that fail one of those five tests. This prints which.
#
# Writes nothing, calls no supplier API, prints no credential and no identity
# HMAC. Run it from the cPanel terminal.
set -u

APP=${APP:-/home/servernetcloud/servernet_app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}

[ -d "$APP" ] || { echo "STOP: app root missing: $APP" >&2; exit 1; }
[ -x "$PHP" ] || { echo "STOP: php missing: $PHP" >&2; exit 1; }

echo "=== PHP LIMITS (a web catalogue refresh must finish inside these) ==="
echo "cli  max_execution_time=$($PHP -r 'echo ini_get("max_execution_time");') memory_limit=$($PHP -r 'echo ini_get("memory_limit");')"
for ini in /home/servernetcloud/public_html/.user.ini /opt/cpanel/ea-php84/root/etc/php.ini; do
  [ -f "$ini" ] && echo "$ini: $(grep -hE '^(max_execution_time|memory_limit|max_input_time)' "$ini" 2>/dev/null | tr '\n' ' ')"
done

echo
echo "=== CATALOGUE SNAPSHOT ==="
$PHP -d error_reporting=E_ALL -r '
$root = $argv[1];
$roots = [$root."/storage/app/private/pricing", $root."/storage/app/pricing"];
$file = "domain-suppliers-catalogue-v1.json"; $path = null;
foreach ($roots as $dir) { if (is_file($dir."/".$file)) { $path = $dir."/".$file; break; } }
if ($path === null) { echo "snapshot MISSING in:\n  ".implode("\n  ", $roots)."\n"; exit; }
echo "path : $path\n";
echo "size : ".number_format(filesize($path))." bytes\n";
echo "mtime: ".gmdate("c", filemtime($path))."\n";
$s = json_decode(file_get_contents($path), true);
if (!is_array($s)) { echo "UNREADABLE JSON\n"; exit; }
echo "version: ".($s["version"] ?? "-")."  fetched_at: ".($s["fetched_at"] ?? "-")."\n";
echo "discovered_suffixes: ".($s["discovered_suffixes"] ?? "-")."  excluded_accounts: ".($s["excluded_accounts"] ?? "-")."\n";
$age = isset($s["fetched_at"]) ? (time() - strtotime($s["fetched_at"])) : null;
if ($age !== null) echo "age: ".round($age/3600, 2)." h".($age > 86400 ? "  <-- OLDER THAN A DAY: readers treat it as absent" : "")."\n";
foreach (($s["accounts"] ?? []) as $id => $a) {
    $offers = is_array($a["offers"] ?? null) ? $a["offers"] : [];
    echo "\n-- account $id --  offers=".count($offers)."  suffixes=".count((array)($a["suffixes"] ?? []))."  source=".($a["source"] ?? "-")."\n";
    if (!$offers) { echo "   no offers stored\n"; continue; }
    // Exactly the five tests compareCatalogues() applies, counted one by one.
    $n = ["operation=create" => 0, "period_months=12" => 0, "premium===false" => 0,
          "tax_included===true" => 0, "valid_until in future" => 0, "ALL FIVE" => 0];
    $ops = []; $periods = []; $tax = []; $vu = ["missing" => 0, "past" => 0, "future" => 0];
    foreach ($offers as $o) {
        if (!is_array($o)) continue;
        $ops[(string)($o["operation"] ?? "null")] = 1 + ($ops[(string)($o["operation"] ?? "null")] ?? 0);
        $periods[(string)($o["period_months"] ?? "null")] = 1 + ($periods[(string)($o["period_months"] ?? "null")] ?? 0);
        $tax[var_export($o["tax_included"] ?? null, true)] = 1 + ($tax[var_export($o["tax_included"] ?? null, true)] ?? 0);
        $a1 = ($o["operation"] ?? null) === "create";          $n["operation=create"] += $a1 ? 1 : 0;
        $a2 = ($o["period_months"] ?? null) === 12;            $n["period_months=12"] += $a2 ? 1 : 0;
        $a3 = ($o["premium"] ?? null) === false;               $n["premium===false"] += $a3 ? 1 : 0;
        $a4 = ($o["tax_included"] ?? null) === true;           $n["tax_included===true"] += $a4 ? 1 : 0;
        $s5 = $o["valid_until"] ?? null;
        if (!is_string($s5)) { $vu["missing"]++; $a5 = false; }
        else { $a5 = strtotime($s5) > time(); $vu[$a5 ? "future" : "past"]++; }
        $n["valid_until in future"] += $a5 ? 1 : 0;
        $n["ALL FIVE"] += ($a1 && $a2 && $a3 && $a4 && $a5) ? 1 : 0;
    }
    foreach ($n as $k => $v) printf("   %-22s %7d / %d\n", $k, $v, count($offers));
    echo "   operation values : "; foreach ($ops as $k => $v) echo "$k=$v "; echo "\n";
    echo "   period_months    : "; $i=0; foreach ($periods as $k => $v) { if ($i++ < 8) echo "$k=$v "; } echo "\n";
    echo "   tax_included     : "; foreach ($tax as $k => $v) echo "$k=$v "; echo "\n";
    echo "   valid_until      : missing=".$vu["missing"]." past=".$vu["past"]." future=".$vu["future"]."\n";
    foreach ($offers as $o) {
        if (is_array($o)) { unset($o["promotion_evidence"]);
            echo "   sample offer     : ".json_encode($o, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n"; break; }
    }
}
' "$APP" 2>&1

echo
echo "=== RECENT SUPPLIER/CATALOGUE ERRORS IN THE LOG ==="
LOG=$(ls -t "$APP"/storage/logs/*.log 2>/dev/null | head -1)
if [ -z "${LOG:-}" ]; then
  echo "no log file found under $APP/storage/logs"
else
  echo "log: $LOG  ($(date -u -r "$LOG" +%FT%TZ))"
  # Only the headline of each recent error plus the first frames: enough to
  # identify the failure without dumping request bodies.
  tail -n 4000 "$LOG" \
    | grep -nE "^\[.*\] (production|local)\.(ERROR|CRITICAL|ALERT|EMERGENCY)" \
    | tail -8 | cut -c1-320
  echo "--- first frames of the most recent error ---"
  tail -n 4000 "$LOG" | awk '/^\[.*\] (production|local)\.(ERROR|CRITICAL)/{last=NR} {line[NR]=$0} END{for(i=last;i<=last+12 && i<=NR;i++) print substr(line[i],1,300)}'
fi

echo
echo "=== DONE (nothing was written) ==="
