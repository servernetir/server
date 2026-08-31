#!/usr/bin/env bash
#
# ورود دومرحله‌ای با اپلیکیشن احراز هویت (TOTP) — اختیاری، مشتری و پنل.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA-این-اسکریپت>/scripts/deploy-two-factor-app.sh) [<SHA>]
#   ← SHA پیش‌فرض a9598b5 — همان کامیتِ خودِ قابلیت.
#
# ═══ چه چیزی منتشر می‌شود ═══
#
# /account/security  → بخشِ «ورود دومرحله‌ای با اپلیکیشن» (#sec-2fa)
# /admin/security    → همان برای هر کاربرِ پنل (نویسنده و پشتیبان هم)
# /login/2fa و /admin/login/totp → مرحلهٔ سومِ ورود، فقط برای حسابی که خودش
#                       روشنش کرده. حسابِ خاموش هیچ تغییری حس نمی‌کند.
#
# ═══ 🔴 چرا routes/web.php این‌جا merge نمی‌شود ═══
#
# `routes/web.php` روی سرور عمداً **زیرمجموعه‌ای** از develop است (CRM، میل‌باکس،
# exit-infra و … مرج شده‌اند ولی آگاهانه دیپلوی نشده‌اند). یک merge سه‌طرفهٔ
# بدشانس می‌تواند روتِ قابلیتی را بیاورد که کنترلرش روی سرور نیست — و ارجاع به
# کلاسِ نبود، **کلِ سایت** را ۵۰۰ می‌کند، نه فقط یک صفحه.
#
# پس این فایل با «درجِ لنگرگاهی» به‌روز می‌شود: پنج بلوکِ مشخص دقیقاً بعد از پنج
# خطِ شناخته‌شده اضافه می‌شوند، و اگر لنگری نبود همان بلوک رد می‌شود و گزارش
# می‌گیرد. هیچ خطی از سرور حذف نمی‌شود و هیچ روتِ غریبه‌ای وارد نمی‌شود.
# درج idempotent است: اجرای دوباره چیزی را تکرار نمی‌کند.
#
# ═══ گاردها ═══
#
# • اثباتِ مقصد پیش از هر نوشتن (درسِ «گارد روی سرورِ اشتباه سبز می‌شود»)
# • اعتبارسنجیِ نحوی روی هر فایلِ نوشته‌شده — و برای `.blade.php` با **کامپایلِ
#   واقعیِ Blade**، چون `php -l` دایرکتیوها را نمی‌بیند؛ خطا ⇒ بازگردانی از بکاپ
# • بعد از همه‌چیز، سه صفحهٔ زندهٔ کلیدی HTTP چک می‌شوند؛ اگر سایت ۵۰۰ شد،
#   **کلِ بکاپ خودکار برمی‌گردد** و کش پاک می‌شود.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-two-factor"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

# ═══ اثباتِ مقصد — پیش از هر نوشتنی ═══
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:  rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"
command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-a9598b5}"

# ⚠️ اگر هنوز به develop مرج نشده، کامیتِ هدف در کلونِ develop نیست. شاخهٔ
#    خودِ قابلیت را هم می‌آوریم تا اسکریپت پیش از مرج هم کار کند — هدف در هر
#    حال یک **SHAی پین‌شده** است، نه نوکِ شاخه، پس رفتار عوض نمی‌شود.
if ! git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1; then
  echo "── $MINE در develop نیست؛ شاخهٔ feature/totp-two-factor هم آورده می‌شود"
  git -C repo fetch --depth 400 origin feature/totp-two-factor >/dev/null 2>&1 || true
fi

git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || { echo "FATAL: $MINE در مخزن نیست (نه در develop، نه در feature/totp-two-factor)"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

CONFLICTS=""
UPD=0
LINT_FAIL=""

backup_of() {                       # $1 = مسیر نسبی
  [ -f "$APP/$1" ] || return 0
  mkdir -p "$BK/$(dirname "$1")"
  cp -p "$APP/$1" "$BK/$1"
}

# ── گاردِ نحوی: فایلِ خراب بدتر از فایلِ قدیمی است ───────────────────────
#
# 🔴 `php -l` روی `.blade.php` **بی‌اثر است**.
#
# دایرکتیوهای Blade (`@if`، `@endif`، …) بیرونِ تگِ `<?php` هستند، پس `php -l`
# آن‌ها را HTMLِ خام می‌بیند و رد می‌کند. یک `@if` بدونِ `@endif` — که صفحه را
# روی سایت ۵۰۰ می‌کند — «No syntax errors detected» می‌گیرد. محلی امتحانش کردم:
# یک Blade عمداً خراب را سالم گزارش کرد.
#
# پس Blade باید **کامپایل** شود و خروجیِ کامپایل‌شده lint شود.
cat > "$WORK/bladecheck.php" <<'PHPCHK'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tmp = sys_get_temp_dir().'/bladechk_'.getmypid().'.php';
file_put_contents($tmp, Illuminate\Support\Facades\Blade::compileString(file_get_contents($argv[2])));
exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $o, $rc);
unlink($tmp);
exit($rc);
PHPCHK

lint_or_restore() {                 # $1 = مسیر نسبی
  case "$1" in
    *.blade.php)
      "$PHPBIN" "$WORK/bladecheck.php" "$APP" "$APP/$1" >/dev/null 2>&1 && return 0
      ;;
    *.php)
      "$PHPBIN" -l "$APP/$1" >/dev/null 2>&1 && return 0
      ;;
    *)
      return 0
      ;;
  esac
  echo "      🔴 خطای نحوی بعد از نوشتن — از بکاپ برگردانده شد: $1"
  if [ -f "$BK/$1" ]; then cp -p "$BK/$1" "$APP/$1"; else rm -f "$APP/$1"; fi
  LINT_FAIL="$LINT_FAIL $1"
  return 1
}

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "FATAL: php پیدا نشد"; exit 1; }

# ═══ 🔴 نرمال‌سازیِ CRLF — پیش از هر مقایسه ═══
#
# بعضی فایل‌های روی سرور CRLF‌اند (یادگارِ نشست‌های آپلودِ مرورگری) و گیت همه‌چیز
# را LF نگه می‌دارد. بدونِ نرمال‌سازی، `cmp` هرگز برابر نمی‌شود و `diff` **هر
# خط** را تغییرکرده می‌شمارد ⇒ «نزدیک‌ترین پایه» یک حدسِ بی‌ربط می‌شود و merge
# روی کلِ فایل تداخل می‌گیرد. یعنی فایل بی‌دلیل دست‌نخورده می‌مانَد و قابلیت
# نیمه‌منتشر می‌شود.
#
# پس مقایسه‌ها روی نسخهٔ نرمال‌شده انجام می‌شوند و نتیجه با LF نوشته می‌شود.
norm() { tr -d '\r' < "$1" > "$2"; }

same() {                # دو فایل، صرفِ‌نظر از پایانِ خط
  norm "$1" "$WORK/n1.tmp"; norm "$2" "$WORK/n2.tmp"
  cmp -s "$WORK/n1.tmp" "$WORK/n2.tmp"
}

dist() {                # فاصلهٔ خطی، صرفِ‌نظر از پایانِ خط
  norm "$1" "$WORK/d1.tmp"; norm "$2" "$WORK/d2.tmp"
  diff "$WORK/d1.tmp" "$WORK/d2.tmp" 2>/dev/null | grep -c '^[<>]'
}

# ── فایل‌های تازه: هیچ تداخلی ممکن نیست ─────────────────────────────────
NEW_FILES="
app/Services/Security/Totp.php
app/Services/Security/QrCode.php
app/Models/Concerns/HasTwoFactor.php
app/Http/Controllers/Account/TwoFactorController.php
app/Http/Controllers/Admin/SecurityController.php
resources/views/auth/login-2fa.blade.php
resources/views/admin/login-totp.blade.php
resources/views/admin/security.blade.php
database/migrations/2026_09_28_000101_add_totp_two_factor_columns.php
"

# ── فایل‌های موجود: merge سه‌طرفه، تغییرِ دیگران حفظ می‌شود ──────────────
MERGE_FILES="
app/Models/Customer.php
app/Models/User.php
app/Http/Controllers/Auth/LoginController.php
app/Http/Controllers/Admin/AuthController.php
app/Http/Controllers/Account/SecurityController.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
resources/views/account/security.blade.php
resources/views/admin/layout.blade.php
"

echo "═══ ۱) فایل‌های تازه ═══"
for rel in $NEW_FILES; do
  src="$WORK/new.tmp"
  git -C repo show "$MINE:website/$rel" > "$src" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; continue; }
  backup_of "$rel"
  mkdir -p "$APP/$(dirname "$rel")"
  if [ -f "$APP/$rel" ] && same "$APP/$rel" "$src"; then echo "OK    $rel"; continue; fi
  cp "$src" "$APP/$rel"
  if lint_or_restore "$rel"; then
    echo "NEW   $rel"; UPD=$((UPD+1))
  fi
done

echo
echo "═══ ۲) فایل‌های موجود (merge سه‌طرفه) ═══"
for rel in $MERGE_FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; continue; }

  if [ ! -f "$dest" ]; then
    backup_of "$rel"; mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"
    lint_or_restore "$rel" && { echo "NEW   $rel"; UPD=$((UPD+1)); }
    continue
  fi

  backup_of "$rel"
  if same "$dest" "$mine_f"; then echo "OK    $rel"; continue; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if same "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    continue
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"
    lint_or_restore "$rel" && { echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); }
    continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  # ⚠️ merge روی نسخهٔ نرمال‌شده: اگر سرور CRLF باشد و پایه LF، merge-file هر
  #    خط را «تغییرکرده» می‌بیند و کلِ فایل تداخل می‌گیرد. خروجی LF می‌نشیند.
  m="$WORK/merged.tmp"; norm "$dest" "$m"
  norm "$base_f" "$WORK/base_n.tmp"
  if git merge-file -L server -L base -L new "$m" "$WORK/base_n.tmp" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    lint_or_restore "$rel" && { echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1)); }
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
done

# ═══ ۳) routes/web.php — درجِ لنگرگاهی، نه merge ═══════════════════════
echo
echo "═══ ۳) routes/web.php (درجِ لنگرگاهی) ═══"

R="$APP/routes/web.php"
backup_of "routes/web.php"

# درج بلوک بعد از خطِ لنگر. idempotent: اگر نشانه هست، رد می‌شود.
#
# 🔴 هیچ لنگری بک‌اسلش ندارد، و این عمدی است. `awk -v` روی مقدارِ متغیر
# «پردازشِ گریز» انجام می‌دهد، پس لنگری مثل
# `use App\Http\Controllers\...` داخلِ awk به `use AppHttpControllers…`
# تبدیل می‌شود، هیچ‌وقت تطبیق نمی‌خورد، و بلوک **بی‌صدا** درج نمی‌شود —
# در شبیه‌سازیِ محلی دقیقاً همین اتفاق افتاد و اسکریپت هم «ADD» چاپ کرد.
# برای همین لنگرها بدونِ بک‌اسلش‌اند و بعد از هر درج، نتیجه grep می‌شود.
insert_after() {        # $1=نشانهٔ یکتا  $2=الگوی لنگر (بدونِ بک‌اسلش)  $3=فایلِ بلوک
  if grep -qF "$1" "$R"; then echo "OK    $1 (از قبل هست)"; return 0; fi
  if ! grep -qF "$2" "$R"; then
    echo "🔴 CF  لنگر پیدا نشد: $2"
    echo "      ⇒ بلوکِ «$1» درج نشد. خروجی را بفرست تا لنگرِ درست را بدهم."
    CONFLICTS="$CONFLICTS routes/web.php($1)"
    return 1
  fi
  awk -v anchor="$2" -v blockfile="$3" '
    { print }
    index($0, anchor) && !done {
      while ((getline line < blockfile) > 0) print line
      close(blockfile); done = 1
    }
  ' "$R" > "$WORK/routes.tmp" && mv "$WORK/routes.tmp" "$R"

  # ⚠️ ادعا نکن، ثابت کن
  if grep -qF "$1" "$R"; then
    echo "ADD   $1"
    return 0
  fi
  echo "🔴 CF  درج انجام نشد (لنگر بود ولی awk تطبیق نداد): $1"
  CONFLICTS="$CONFLICTS routes/web.php($1)"
  return 1
}

cat > "$WORK/b_import.txt" <<'BLOCK'
use App\Http\Controllers\Admin\SecurityController as AdminSecurity;
BLOCK

cat > "$WORK/b_login.txt" <<'BLOCK'

        /*
        | مرحلهٔ سومِ ورود — کدِ اپلیکیشنِ احرازِ هویت، فقط برای حسابی که خودش
        | روشنش کرده. مثلِ مرحلهٔ دو بیرونِ `auth:customer` است چون کاربر در
        | این لحظه هنوز وارد **نشده**؛ گذارش فقط با کلیدِ نشست است.
        */
        Route::get('/login/2fa', [Auth\LoginController::class, 'twoFactor'])->name('login.2fa');
        Route::post('/login/2fa', [Auth\LoginController::class, 'twoFactorVerify'])->name('login.2fa.verify')->middleware('throttle:otp');
BLOCK

cat > "$WORK/b_account.txt" <<'BLOCK'

        /*
        | ورود دومرحله‌ای با اپلیکیشنِ احرازِ هویت (Google Authenticator).
        |
        | ⚠️ `throttle:otp` روی سه مسیری که کد می‌سنجند: بدونش، فرمِ
        | غیرفعال‌سازی یک اوراکلِ حدسِ شش‌رقمیِ بی‌سقف است.
        */
        Route::post('/security/2fa/start', [Account\TwoFactorController::class, 'start'])->name('security.2fa.start')->middleware('throttle:forms');
        Route::post('/security/2fa/confirm', [Account\TwoFactorController::class, 'confirm'])->name('security.2fa.confirm')->middleware('throttle:otp');
        Route::post('/security/2fa/cancel', [Account\TwoFactorController::class, 'cancel'])->name('security.2fa.cancel')->middleware('throttle:forms');
        Route::post('/security/2fa/recovery', [Account\TwoFactorController::class, 'recovery'])->name('security.2fa.recovery')->middleware('throttle:otp');
        Route::post('/security/2fa/disable', [Account\TwoFactorController::class, 'disable'])->name('security.2fa.disable')->middleware('throttle:otp');
BLOCK

cat > "$WORK/b_admin_login.txt" <<'BLOCK'

    /*
    | مرحلهٔ سه — کدِ اپلیکیشنِ احرازِ هویت. مثلِ دو تای قبل بیرونِ `auth:web`
    | است: کاربر تا وقتی این کد را ندهد وارد نشده.
    */
    Route::get('/login/totp', [AdminAuth::class, 'showTotp'])->name('admin.login.totp');
    Route::post('/login/totp', [AdminAuth::class, 'verifyTotp'])->middleware('throttle:otp');
BLOCK

cat > "$WORK/b_admin_sec.txt" <<'BLOCK'

            /*
            | امنیتِ حسابِ **خودِ کاربر** — دومرحله‌ای با اپلیکیشن.
            |
            | ⚠️ عمداً در فهرستِ سفیدِ غیرِمدیر است: این صفحه به هیچ دادهٔ
            | مدیریتی دست نمی‌زند و فقط حسابِ همان کاربر را سفت می‌کند.
            */
            Route::get('/security', [AdminSecurity::class, 'index'])->name('admin.security');
            Route::post('/security/2fa/start', [AdminSecurity::class, 'start'])->middleware('throttle:forms');
            Route::post('/security/2fa/confirm', [AdminSecurity::class, 'confirm'])->middleware('throttle:otp');
            Route::post('/security/2fa/cancel', [AdminSecurity::class, 'cancel'])->middleware('throttle:forms');
            Route::post('/security/2fa/recovery', [AdminSecurity::class, 'recovery'])->middleware('throttle:otp');
            Route::post('/security/2fa/disable', [AdminSecurity::class, 'disable'])->middleware('throttle:otp');
BLOCK

insert_after "as AdminSecurity"       "PostController as AdminPost"                                 "$WORK/b_import.txt"
insert_after "login.2fa.verify"       "'login.resend')"                                             "$WORK/b_login.txt"
insert_after "security.2fa.start"     "'security.token.delete')"                                    "$WORK/b_account.txt"
insert_after "admin.login.totp"       "'resendOtp'"                                                 "$WORK/b_admin_login.txt"
insert_after "admin.security"         "'dropReply'"                                                 "$WORK/b_admin_sec.txt"

if "$PHPBIN" -l "$R" >/dev/null 2>&1; then
  echo "      ✅ routes/web.php از نظر نحوی سالم است"
  UPD=$((UPD+1))
else
  echo "      🔴 routes/web.php خطای نحوی گرفت — از بکاپ برگردانده شد"
  cp -p "$BK/routes/web.php" "$R"
  LINT_FAIL="$LINT_FAIL routes/web.php"
fi

# ═══ ۴) مهاجرت ═══════════════════════════════════════════════════════════
echo
echo "═══ ۴) مهاجرتِ ستون‌های دومرحله‌ای ═══"
# ⚠️ بی‌این مهاجرت صفحه‌ها باز می‌شوند ولی «فعال‌سازی» ذخیره نمی‌شود:
#    ستونِ two_factor_* روی users وجود ندارد.
cd "$APP" && "$PHPBIN" artisan migrate --force \
  --path=database/migrations/2026_09_28_000101_add_totp_two_factor_columns.php \
  || echo "🔴 مهاجرت نخورد — «فعال‌سازی» کار نمی‌کند. خروجی را بفرست."

# ═══ ۵) کش‌ها ════════════════════════════════════════════════════════════
echo
"$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear

# ═══ ۶) ضمانتِ اتحاد — هر حلقهٔ گمشده یعنی صفحه ۵۰۰ ═══════════════════════
echo
echo "═══ ۶) ضمانتِ اتحاد ═══"
union_ok=1
need_file() { [ -f "$APP/$1" ] || { echo "🔴 روی سرور نیست: $1"; union_ok=0; }; }
need_grep() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

need_file app/Services/Security/Totp.php
need_file app/Services/Security/QrCode.php
need_file app/Models/Concerns/HasTwoFactor.php
need_file app/Http/Controllers/Account/TwoFactorController.php
need_file app/Http/Controllers/Admin/SecurityController.php
need_file resources/views/auth/login-2fa.blade.php
need_file resources/views/admin/login-totp.blade.php
need_file resources/views/admin/security.blade.php

need_grep app/Models/User.php     "HasTwoFactor"
need_grep app/Models/Customer.php "HasTwoFactor"
need_grep app/Http/Controllers/Auth/LoginController.php  "twoFactorVerify"
need_grep app/Http/Controllers/Admin/AuthController.php  "verifyTotp"
need_grep app/Http/Controllers/Account/SecurityController.php "tfaQr"
need_grep resources/views/account/security.blade.php "sec-2fa"
need_grep resources/views/admin/layout.blade.php    "/admin/security"
for l in fa en tr; do need_grep "lang/$l/ui.php" "'tfa_h'"; done
for r in "login.2fa" "admin.login.totp" "admin.security" "security.2fa.start"; do
  need_grep routes/web.php "$r"
done

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

# ═══ ۷) راستی‌آزماییِ زنده + بازگردانیِ خودکار ═══════════════════════════
echo
echo "═══ ۷) راستی‌آزماییِ زنده ═══"
BAD=0
check() {                          # $1=نشانی  $2=کدهای قابل‌قبول
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                      "200"
check "https://servernet.cloud/en"                    "200"
check "https://console.servernet.cloud/login"         "200"
check "https://console.servernet.cloud/admin/login"   "200"
check "https://console.servernet.cloud/account/security" "302 301"

if [ "$BAD" -eq 1 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده می‌شود …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  echo "   ↩️ برگشت انجام شد. سایت باید به حالتِ قبل برگشته باشد؛ دوباره چک کنید:"
  echo "      curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/"
  echo "   (مهاجرت برنمی‌گردد — ستون‌های nullable بی‌ضررند.)"
  exit 1
fi

# ═══ گزارش ═══════════════════════════════════════════════════════════════
echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
[ -n "$LINT_FAIL" ] && echo "🔴 خطای نحوی (برگردانده شد):$LINT_FAIL"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "حالا دستی امتحان کنید:"
echo "  console.servernet.cloud/account/security  → بخشِ «ورود دومرحله‌ای با اپلیکیشن»"
echo "  console.servernet.cloud/admin/security    → همان برای کاربرِ پنل"
echo "  «فعال‌سازی» بزنید، QR را با Google Authenticator اسکن کنید، کد را وارد کنید،"
echo "  کدهای بازیابی را ذخیره کنید، بعد یک بار خارج و دوباره وارد شوید."
