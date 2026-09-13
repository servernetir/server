#!/usr/bin/env bash
# Deploy the managed rclone backup integration to the cPanel Laravel app.
# Run from the servernetcloud cPanel terminal:
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-rclone-backup.sh) <SHA>
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-rclone-backup.sh) <SHA>

set -u

DRY="${DRY:-0}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-rclone-backup"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
HIST=250
REPO_URL="https://github.com/servernetir/server.git"

fail() { echo "FATAL: $*"; exit 1; }
normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }

[ -f "$APP/artisan" ] && [ -d "$APP/vendor" ] \
  || fail "$APP is not the live Laravel installation; use the servernetcloud account"

FREE_MB="$(df -Pm "$HOME" | awk 'NR==2{print $4}')"
[ "${FREE_MB:-0}" -ge 500 ] || fail "less than 500 MB free in the cPanel account"
command -v git >/dev/null || fail "git is unavailable"

mkdir -p "$WORK" "$WORK/conflicts"
cd "$WORK" || exit 1
if [ -d repo/.git ]; then
  git -C repo fetch --prune --depth 800 origin || fail "git fetch failed"
else
  git clone --depth 800 "$REPO_URL" repo || fail "git clone failed"
fi
git -C repo fetch --depth 800 origin \
  +codex/rclone-managed-backup:refs/remotes/origin/codex/rclone-managed-backup \
  || fail "backup feature branch fetch failed"

MINE="${1:-origin/codex/rclone-managed-backup}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || fail "$MINE is not available in the repository"
MINE="$(git -C repo rev-parse "$MINE^{commit}")"

FILES="
app/Http/Controllers/Account/StoreController.php
app/Http/Controllers/Admin/ProductController.php
app/Http/Controllers/Admin/ServerController.php
app/Http/Controllers/Admin/ServiceController.php
app/Models/Product.php
app/Models/Server.php
app/Services/Provisioning/ProvisioningService.php
app/Services/Provisioning/RcloneStorageClient.php
app/Services/Provisioning/RcloneStorageCosts.php
app/Services/Provisioning/RcloneStorageProvisioner.php
config/provisioning.php
lang/en/ui.php
lang/fa/ui.php
lang/tr/ui.php
resources/views/account/partials/card-hosting.blade.php
resources/views/account/checkout.blade.php
resources/views/admin/partials/server-form.blade.php
"

echo "Target: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "Mode: $([ "$DRY" = 1 ] && echo DRY-RUN || echo APPLY)"

[ "$DRY" = 1 ] || mkdir -p "$BK"
CONFLICTS=""
CHANGED=""
CREATED=""

apply_one() {
  rel="$1"
  dest="$APP/$rel"
  key="$(printf '%s' "$rel" | tr '/.' '__')"
  mine_raw="$WORK/$key.mine.raw"
  mine="$WORK/$key.mine"
  live="$WORK/$key.live"
  base="$WORK/$key.base"
  merged="$WORK/$key.merged"

  git -C repo show "$MINE:website/$rel" > "$mine_raw" 2>/dev/null \
    || { echo "MISS  $rel (not in target)"; CONFLICTS="$CONFLICTS $rel"; return; }
  normalize "$mine_raw" "$mine"

  if [ ! -f "$dest" ]; then
    echo "NEW   $rel"
    if [ "$DRY" != 1 ]; then
      mkdir -p "$(dirname "$dest")"
      cp "$mine" "$dest"
      CREATED="$CREATED $rel"
      CHANGED="$CHANGED $rel"
    fi
    return
  fi

  normalize "$dest" "$live"
  if cmp -s "$live" "$mine"; then
    echo "OK    $rel"
    return
  fi

  best=""
  bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    cand="$WORK/$key.candidate"
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    normalize "$cand" "$cand.lf"
    if cmp -s "$live" "$cand.lf"; then best="$sha"; bestd=0; break; fi
    d="$(distance "$live" "$cand.lf")"
    if [ "$d" -lt "$bestd" ]; then bestd="$d"; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel (unknown live version; untouched)"
    CONFLICTS="$CONFLICTS $rel"
    cp "$live" "$WORK/conflicts/$key.server"
    cp "$mine" "$WORK/conflicts/$key.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    echo "UP    $rel (live matches $(git -C repo rev-parse --short "$best"))"
    if [ "$DRY" != 1 ]; then
      mkdir -p "$BK/$(dirname "$rel")"
      cp -p "$dest" "$BK/$rel"
      cp "$mine" "$dest"
      CHANGED="$CHANGED $rel"
    fi
    return
  fi

  git -C repo show "$best:website/$rel" > "$base"
  normalize "$base" "$base.lf"
  cp "$live" "$merged"
  if git merge-file -L live -L base -L target "$merged" "$base.lf" "$mine" >/dev/null 2>&1; then
    echo "MG    $rel (base $(git -C repo rev-parse --short "$best"), live distance $bestd)"
    if [ "$DRY" != 1 ]; then
      mkdir -p "$BK/$(dirname "$rel")"
      cp -p "$dest" "$BK/$rel"
      cp "$merged" "$dest"
      CHANGED="$CHANGED $rel"
    fi
  else
    echo "CF    $rel (real merge conflict; untouched)"
    CONFLICTS="$CONFLICTS $rel"
    cp "$live" "$WORK/conflicts/$key.server"
    cp "$base.lf" "$WORK/conflicts/$key.base"
    cp "$mine" "$WORK/conflicts/$key.new"
  fi
}

for rel in $FILES; do apply_one "$rel"; done

if [ -n "$CONFLICTS" ]; then
  echo "Conflicts:$CONFLICTS"
  echo "Copies are in $WORK/conflicts"
  if [ "$DRY" != 1 ]; then
    echo "A partial apply is not allowed; restoring every touched file"
    for rel in $CHANGED; do
      if [ -f "$BK/$rel" ]; then cp -p "$BK/$rel" "$APP/$rel"; fi
    done
    for rel in $CREATED; do rm -f "$APP/$rel"; done
  fi
  exit 2
fi

[ "$DRY" = 1 ] && { echo "DRY-RUN PASS: no production file changed"; exit 0; }

rollback() {
  echo "Validation failed; restoring $BK"
  for rel in $CHANGED; do
    if [ -f "$BK/$rel" ]; then cp -p "$BK/$rel" "$APP/$rel"; fi
  done
  for rel in $CREATED; do rm -f "$APP/$rel"; done
  exit 1
}

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN="$(command -v php 2>/dev/null || true)"
[ -n "$PHPBIN" ] || rollback

for rel in $FILES; do
  case "$rel" in *.php) "$PHPBIN" -l "$APP/$rel" >/dev/null || rollback ;; esac
done

grep -qF "rclone_storage" "$APP/app/Services/Provisioning/ProvisioningService.php" || rollback
grep -qF "RcloneStorageProvisioner" "$APP/app/Services/Provisioning/ProvisioningService.php" || rollback
grep -qF "RcloneStorageClient" "$APP/app/Services/Provisioning/RcloneStorageProvisioner.php" || rollback
grep -qF "s3_endpoint" "$APP/resources/views/account/partials/card-hosting.blade.php" || rollback
grep -qF "rclone_storage" "$APP/resources/views/admin/partials/server-form.blade.php" || rollback

cd "$APP" || rollback
"$PHPBIN" artisan config:clear >/dev/null || rollback
"$PHPBIN" artisan route:clear >/dev/null || rollback
"$PHPBIN" artisan view:clear >/dev/null || rollback

echo "DEPLOY PASS"
echo "Backup: $BK"
echo "Next: reset OPcache, add the rclone_storage server, then run one test order"
