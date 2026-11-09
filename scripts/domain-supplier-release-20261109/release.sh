#!/bin/bash
# Scoped release of PR #298 (commit 6a93883c) — "Let the multi-supplier flag go
# on without wiping domain prices".
#
# Thirteen runtime files, no new files, no migrations, no schema change.
# Every file is pinned by SHA-256 before and after, so a drifted or concurrently
# edited target stops the run instead of being overwritten.
#
#   bash release.sh check      read-only precondition report
#   bash release.sh apply      backup, install, verify, activate
#   bash release.sh verify     re-check installed hashes
#   bash release.sh rollback   restore the preimages of a given stage
#
# Activation of multi-supplier selling is NOT part of this script: the feature
# flag, capability profiles and per-account sales stay exactly as they are.
set -u

TARGET_SHA=6a93883c2b915de50bff78fef5ce07df90456c10
BASE_SHA=889c8d09f82beb46992f43aa20019845bf625213
RAW=https://raw.githubusercontent.com/servernetir/server/$TARGET_SHA/website

APP=${APP:-/home/servernetcloud/servernet_app}
PHP=${PHP:-/opt/cpanel/ea-php84/root/usr/bin/php}
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
STAGE=${STAGE:-$HOME/.domain-supplier-release-20261109-$STAMP}
LOCKS=("$HOME/.servernet-web-release.lock" "$HOME/servernet-release.lock" "$HOME/.codex-web-deploy.lock")

# path|sha256 before (pre-PR298)|sha256 after (PR298)
MANIFEST='app/Http/Controllers/Account/DomainController.php|ae8b934264ea1af57c2dd5f1304a110fd009682484ad61fd226d3a2e71e40bd5|008f0efd78a1fa0a66fb5d3f838e295f8c889ab2a76f3132aa3fdfd44c322771
app/Http/Controllers/Admin/DomainSupplierController.php|31cddc4df1d6ae82a5e09c5dc19fd265acabba11bcbdc58552f196fde3dddac0|0169f0c473c4cec05af0ed300cc17c075cbe20b73ded3e1c862e76d41e7a97c4
app/Models/DomainSupplierAccount.php|a26a6e73ccf4cae2def7994ed19bfb4e363c47e9dd7e7af32868137432dc4336|cbbcafbc144f6f1564eff04153752b8a6d0e7466bc3f0f7ccdaf6ee7e7a90dd5
app/Services/Domain/DomainProcurement.php|d39f554e2f9489a7c92d9b048b95bf0cd976237ab726f1d5f7bf03e79c382b20|01b7922d6c3a5cf2418f364f7275b259fa38d83acf2f65b1fd81d736b376aa37
app/Services/Domain/DomainSupplierCatalogue.php|3a2e9cd9613025b93b73fae6cab1c4d2a237aac395df3ac69b42f8105f179589|17518ce26587595730e0982302d77e621b18cf71a804a72348eee8314942ed97
app/Services/Domain/DomainSupplierContactProvisioner.php|60ff9bdf226acb628560eafe25fc3ffc6ab42a4a1f687fccdf7eac694e0fe033|9e20b17c6df68d1b3ec51346c6ab63e1681ab7d9b649d9def4a0cf75f17835ee
config/domain_suppliers.php|365a87ca78e6ebdd9a1bf079e27603101f6e008117f07f95e97b6f24a9a36199|6f27ee3f7e32c5cc37fba4c0d708a0256828136231bab45baba6e03ce042f600
lang/en/domain-suppliers.php|af3bff1f5b4a5df129c39032e3e51d2abb2c5c70b491208e505021ed69066a95|dcb967553b50d7acea890ba6d5400ada2a14d6d07a2a6f89f2babab959811d23
lang/fa/domain-suppliers.php|9837220d127745b264066320df319095e3576d13db8540a2bec33f71d6092a8d|62b304d5d3ea57abccbbb01fc62d1abb0c36c46599d3a7bf3458c77f20f387ef
lang/tr/domain-suppliers.php|de590dccf6f41f7220f6bcbb78c010bdea4a53339684d2d7cec518ac5bb125a0|5946e8670d3f6d061afee4a779594e42dc5e244023a557130f4db23e03dede59
resources/views/admin/domain-suppliers/form.blade.php|872e5ff8745db08ff7c7b7fcfcf86e850866892086a53dbfeea930ce76371b9c|8c0da56ef2548fd03fef7ff7cf5a295c1bfcb5647978c1e79459e2f9368f5e4d
resources/views/admin/domain-suppliers/index.blade.php|72b496827a498dda288a6ec9a83daafe06534fc17dee2a3425fefbdd4b6a8a9c|0b3ecf028e1333c9ce8f268b565c207403c37ddc9ca9f25bc251a3178b7da85a
routes/domain-suppliers.php|f0cbaa8dd87a016f52fc4857cf4790aab32ddd356c1bda83ccbc4c15705b1752|1df742210c90e7f2837390e1cf556ec3588aaf66a390d4a023fd73ae1dbf6cdf'

die() { echo "STOP: $*" >&2; exit 1; }
hash_of() { sha256sum "$1" | cut -d' ' -f1; }

preflight() {
  [ -d "$APP" ] || die "app root missing: $APP"
  [ -x "$PHP" ] || die "php 8.4 missing: $PHP"
  command -v curl >/dev/null || die "curl missing"
  command -v sha256sum >/dev/null || die "sha256sum missing"
  echo "app   : $APP"
  echo "php   : $($PHP -r 'echo PHP_VERSION;')"
  echo "target: $TARGET_SHA (base $BASE_SHA)"
}

# Classify every target: BASE (ready), TARGET (already installed), DRIFT, MISSING.
survey() {
  n_base=0; n_target=0; n_drift=0; n_missing=0
  while IFS='|' read -r path before after; do
    local file="$APP/$path" live
    if [ ! -f "$file" ]; then
      echo "MISSING   $path"; n_missing=$((n_missing + 1)); continue
    fi
    live=$(hash_of "$file")
    if [ "$live" = "$before" ]; then
      echo "BASE      $path"; n_base=$((n_base + 1))
    elif [ "$live" = "$after" ]; then
      echo "TARGET    $path"; n_target=$((n_target + 1))
    else
      echo "DRIFT     $path"; echo "          live=$live"; n_drift=$((n_drift + 1))
    fi
  done <<< "$MANIFEST"
  echo "--- BASE=$n_base TARGET=$n_target DRIFT=$n_drift MISSING=$n_missing ---"
}

# The project's shared release protocol (scripts/seo-release-20261108/release.py)
# is an advisory flock held on three persistent files, acquired in this exact
# order. The files are created once and never removed, so their presence proves
# nothing and deleting one would silently break mutual exclusion for a release
# that is holding it right now. The lock dies with this process, nothing to clean.
take_locks() {
  command -v flock >/dev/null || die "flock missing — the shared release protocol needs it"
  local index=0
  for lock in "${LOCKS[@]}"; do
    [ -L "$lock" ] && die "release lock is a symlink, refusing to follow it: $lock"
    case $index in
      0) exec 200>>"$lock" || die "cannot open lock: $lock"; flock -n 200 || die "shared release lock busy, another release is running: $lock" ;;
      1) exec 201>>"$lock" || die "cannot open lock: $lock"; flock -n 201 || die "shared release lock busy, another release is running: $lock" ;;
      2) exec 202>>"$lock" || die "cannot open lock: $lock"; flock -n 202 || die "shared release lock busy, another release is running: $lock" ;;
    esac
    { [ -f "$lock" ] && [ -O "$lock" ]; } || die "lock is not a regular file owned by this user: $lock"
    index=$((index + 1))
  done
  echo "locks: three shared release locks held (advisory flock; files are kept, never removed)"
}

fetch_candidates() {
  mkdir -p "$STAGE/candidate" "$STAGE/preimage" || die "cannot create stage $STAGE"
  chmod 700 "$STAGE"
  while IFS='|' read -r path before after; do
    local dest="$STAGE/candidate/$path"
    mkdir -p "$(dirname "$dest")"
    curl -fsSL --retry 3 -o "$dest" "$RAW/$path" || die "download failed: $path"
    local got; got=$(hash_of "$dest")
    [ "$got" = "$after" ] || die "candidate hash mismatch for $path (got $got, want $after)"
    case "$path" in
      *.blade.php) : ;;  # Blade is not plain PHP; the byte-exact hash is the guard.
      *) $PHP -l "$dest" >/dev/null || die "php lint failed: $path" ;;
    esac
  done <<< "$MANIFEST"
  echo "candidates: 13 downloaded, hash-verified, linted"
}

install_files() {
  local installed=0
  while IFS='|' read -r path before after; do
    local file="$APP/$path" src="$STAGE/candidate/$path" live
    live=$(hash_of "$file")
    [ "$live" = "$after" ] && { echo "skip (already target) $path"; continue; }
    # Drift control immediately before the write, not only in the survey.
    [ "$live" = "$before" ] || die "target changed since survey: $path"
    cp -p "$file" "$STAGE/preimage/$(echo "$path" | tr '/' '_')" || die "backup failed: $path"
    local mode; mode=$(stat -c '%a' "$file")
    cp "$src" "$file.release-$$" || die "stage copy failed: $path"
    chmod "$mode" "$file.release-$$"
    mv -f "$file.release-$$" "$file" || die "install failed: $path"
    [ "$(hash_of "$file")" = "$after" ] || die "post-write hash mismatch: $path"
    installed=$((installed + 1))
    echo "installed $path"
  done <<< "$MANIFEST"
  echo "installed: $installed file(s); preimages in $STAGE/preimage"
}

activate() {
  cd "$APP" || die "cannot enter $APP"
  # Blade recompiles by itself once the source mtime is newer, so a site-wide
  # view:clear is never needed here and would make every other view recompile
  # at once on a live site. Drop only the compiled copies of the two changed
  # views, found by the absolute source path Blade stamps into each of them.
  local dropped=0
  for blade in resources/views/admin/domain-suppliers/form.blade.php \
               resources/views/admin/domain-suppliers/index.blade.php; do
    while IFS= read -r compiled; do
      [ -n "$compiled" ] || continue
      rm -f "$compiled" && dropped=$((dropped + 1))
    done <<< "$(grep -rl -F -- "$APP/$blade ENDPATH" "$APP/storage/framework/views" 2>/dev/null)"
  done
  echo "activate: dropped $dropped compiled copy/copies of the two changed admin views (other views untouched)"
  # Only regenerate caches that actually exist; creating them is a separate decision.
  if [ -f "$APP/bootstrap/cache/config.php" ]; then
    $PHP artisan config:cache >/dev/null 2>&1 && echo "activate: config cache rebuilt (health_max_age_seconds 300 -> 900)" \
      || echo "WARN: config cache rebuild failed — config/domain_suppliers.php change is NOT live"
  else
    echo "activate: no config cache (config read from file; nothing to rebuild)"
  fi
  if [ -f "$APP/bootstrap/cache/routes-v7.php" ]; then
    $PHP artisan route:cache >/dev/null 2>&1 && echo "activate: route cache rebuilt (catalogue/refresh route)" \
      || echo "WARN: route cache rebuild failed — the new admin route is NOT live"
  else
    echo "activate: no route cache (routes read from file)"
  fi
  echo "opcache(CLI validate_timestamps): $($PHP -r 'echo ini_get("opcache.validate_timestamps") === false ? "n/a" : var_export(ini_get("opcache.validate_timestamps"), true);')"
  echo "NOTE: CLI OPcache is not the web pool. Only act on the web pool if stale code is actually observed."
}

accept() {
  echo "=== HTTP acceptance ==="
  for probe in \
    "405|https://console.servernet.cloud/admin/domain-suppliers/catalogue/refresh" \
    "200|https://servernet.cloud/domains" \
    "200|https://servernet.cloud/en/domains" \
    "200|https://servernet.cloud/"
  do
    local want=${probe%%|*} url=${probe#*|} got
    got=$(curl -s -o /dev/null -w '%{http_code}' -m 25 "$url")
    [ "$got" = "$want" ] && echo "OK   $got  $url" || echo "CHECK $got (want $want)  $url"
  done
  echo "A 405 on catalogue/refresh means the new POST-only route is live (404 means it is not)."
}

case "${1:-check}" in
  check)
    preflight; echo "=== PRECONDITIONS ==="; survey
    [ "$n_drift" -gt 0 ] && echo "DRIFT present: reconcile before apply. Do not overwrite a colleague's file."
    [ "$n_missing" -gt 0 ] && echo "MISSING present: the supplier feature is not fully installed; stop and review."
    echo "=== CACHE ARTIFACTS ==="
    for c in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
      echo "$c: $([ -f "$APP/$c" ] && echo PRESENT || echo absent)"
    done
    echo "=== SUPPLIER FLAGS (.env, no secrets: these keys are flags and ids only) ==="
    grep -E '^(DOMAIN_MULTI_PROVIDER|DOMAIN_SUPPLIER)' "$APP/.env" 2>/dev/null \
      || echo "none set — defaults apply: multi-provider OFF, authorization_mode=pilot"
    echo "=== SUPPLIER SCHEMA (PR #298 adds none; these come from the earlier install) ==="
    ls -1 "$APP/database/migrations" 2>/dev/null | grep -E 'domain_supplier' || echo "no supplier migration files found"
    accept
    ;;
  apply)
    preflight; echo "=== PRECONDITIONS ==="; survey
    [ "$n_drift" -eq 0 ] || die "drift on $n_drift file(s) — reconcile first"
    [ "$n_missing" -eq 0 ] || die "$n_missing file(s) missing — feature not installed as expected"
    [ "$n_base" -gt 0 ] || { echo "nothing to do: all files already at target"; exit 0; }
    take_locks
    echo "=== STAGE $STAGE ==="; fetch_candidates
    echo "=== INSTALL ==="; install_files
    echo "=== ACTIVATE ==="; activate
    accept
    echo "=== RESULT ==="; echo "installed_and_activated stage=$STAGE"
    echo "Rollback: bash $0 rollback $STAGE"
    ;;
  verify)
    preflight; survey
    [ "$n_target" -eq 13 ] && echo "VERIFIED: all 13 files at PR #298" || echo "NOT fully at target"
    accept
    ;;
  rollback)
    stage=${2:-} ; [ -n "$stage" ] && [ -d "$stage/preimage" ] || die "usage: bash $0 rollback <stage dir>"
    preflight; take_locks
    while IFS='|' read -r path before after; do
      local_backup="$stage/preimage/$(echo "$path" | tr '/' '_')"
      [ -f "$local_backup" ] || { echo "no preimage, leaving as is: $path"; continue; }
      live=$(hash_of "$APP/$path")
      [ "$live" = "$after" ] || { echo "SKIP changed since install: $path"; continue; }
      cp -p "$local_backup" "$APP/$path.rollback-$$" && mv -f "$APP/$path.rollback-$$" "$APP/$path" \
        && echo "restored $path" || echo "FAILED  $path"
    done <<< "$MANIFEST"
    activate; accept
    echo "Rollback restored source only; confirm the pages above actually serve the old behaviour."
    ;;
  *) die "usage: bash $0 {check|apply|verify|rollback <stage>}" ;;
esac
