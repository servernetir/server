#!/usr/bin/env bash
# UI-only traffic report release. Never invokes a meter, debit, provider or notification.
set -euo pipefail
arvan_mode="${1:---preview}"
if [[ "$#" -gt 1 ]]; then exit 2; fi
case "$arvan_mode" in
  --install-report) arvan_install_mode=--apply ;;
  --preview|--rollback) arvan_install_mode="$arvan_mode" ;;
  *) exit 2 ;;
esac
arvan_php=/opt/cpanel/ea-php84/root/usr/bin/php
arvan_base=https://raw.githubusercontent.com/servernetir/server/44eaaa00731cb83ca922c35cf82851999bd9d421
arvan_stage=$(mktemp -d /tmp/servernet-traffic-health.XXXXXX)
arvan_cleanup() {
  rm -f -- "$arvan_stage/ArvanTrafficHealth.php" "$arvan_stage/traffic-health.test.php" "$arvan_stage/install-traffic-health.php" "$arvan_stage/traffic-scope-details.php"
  rmdir -- "$arvan_stage"
}
trap arvan_cleanup EXIT
arvan_fetch() {
  curl --fail --silent --show-error --connect-timeout 10 --max-time 45 "$arvan_base/$1" -o "$arvan_stage/$2"
}
arvan_verify() {
  "$arvan_php" -r '$bytes=file_get_contents($argv[1]); if(!is_string($bytes)||sha1("blob ".strlen($bytes)."\0".$bytes)!==$argv[2]) exit(3);' "$arvan_stage/$1" "$2"
}
arvan_fetch website/app/Services/Finance/ArvanTrafficHealth.php ArvanTrafficHealth.php
arvan_fetch scripts/arvan-acceptance/traffic-health.test.php traffic-health.test.php
arvan_fetch scripts/arvan-acceptance/install-traffic-health-20261028.php install-traffic-health.php
arvan_fetch scripts/arvan-acceptance/traffic-scope-details-20261028.php traffic-scope-details.php
arvan_verify ArvanTrafficHealth.php 4a09ce097c1b523f880f351073c412ea4bdb342e
arvan_verify traffic-health.test.php 1c4ef49415a69f6607542701792baafe6bfc2f77
arvan_verify install-traffic-health.php 82f29e8df80cea7834889e4920ce7b38f0e45555
arvan_verify traffic-scope-details.php 78c44e7c68aedd15c8baa2c7aea56e35f3ebe998
for arvan_file in "$arvan_stage"/*.php; do
  "$arvan_php" -l "$arvan_file"
done
"$arvan_php" "$arvan_stage/install-traffic-health.php" "$arvan_install_mode"
if [[ "$arvan_mode" != --rollback ]]; then
  arvan_scope_exit=0
  "$arvan_php" "$arvan_stage/traffic-scope-details.php" || arvan_scope_exit=$?
  printf '{"scope_probe_exit_code":%d}\n' "$arvan_scope_exit"
fi
