#!/usr/bin/env bash
#
# سهمیه و اعتبارِ حسابِ آروان — آیا ۴۰۴ِ عمومی از «نداشتنِ ظرفیت» می‌آید؟
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/probe-arvan-quota.sh)
#
# 🟢 فقط‌خواندنی. فقط GET. هیچ POST/DELETEی، هیچ سروری، هیچ تغییری.
#
# ═══ چرا ═══
#
# ثابت شد «Requested firewall was not found» پیامِ **عمومیِ ۴۰۴** آروان است:
# flavorِ خراب، imageِ خراب، networkِ خراب و firewallِ خراب هر چهار همین یک
# جمله را می‌دهند. حالا پیلودِ امروزِ ما هر چهار مرجعش در ir-thr-si1 معتبر
# است — ولی نمی‌دانیم پیلودِ سالم می‌گذرد یا نه، چون امتحانش یعنی سفارشِ
# واقعی و خرج کردنِ پولِ کارفرما.
#
# پیش از آن، یک احتمالِ بی‌هزینه: اگر حساب **سهمیه یا اعتبار** نداشته باشد،
# همان ۴۰۴ِ عمومی می‌تواند از آن‌جا بیاید و هیچ ربطی به پیلود نداشته باشد.
# این را با GET می‌شود فهمید.
#
set -u

APP="$HOME/servernet_app"

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   کاربرِ درست: servernetcloud  ·  چاره:  su - servernetcloud"
  exit 1
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "🔴 php پیدا نشد"; exit 1; }

PROBE="$HOME/.arvan-quota-$$.php"
trap 'rm -f "$PROBE"' EXIT

cat > "$PROBE" << 'PHPEOF'
<?php
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

const BASE   = 'https://napi.arvancloud.ir';
const REGION = 'ir-thr-si1';

$token = Setting::getSecret('arvan_api_token');

if (blank($token)) { echo "🔴 توکن نیست\n"; exit(1); }

/** فقط GET — هیچ متدِ دیگری در این اسکریپت وجود ندارد. */
function g(string $token, string $path): array
{
    try {
        $res = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()->timeout(25)->connectTimeout(10)->get(BASE.$path);
    } catch (\Throwable $e) {
        return ['s' => 0, 'b' => 'transport: '.$e->getMessage()];
    }

    return ['s' => $res->status(), 'b' => preg_replace('/\s+/', ' ', mb_substr((string) $res->body(), 0, 300))];
}

/*
| مسیرِ سهمیه/اعتبار در APIِ آروان مستند نیست، پس چند کاندید را امتحان
| می‌کنیم. ۴۰۴ یعنی «این مسیر نیست»، نه «سهمیه ندارید» — تفکیکش با بدنه.
*/
$paths = [
    '/ecc/v1/quotas',
    '/ecc/v1/regions/'.REGION.'/quotas',
    '/ecc/v1/regions/'.REGION.'/quota',
    '/ecc/v1/limits',
    '/ecc/v1/regions/'.REGION.'/limits',
    '/ecc/v1/account',
    '/ecc/v1/balance',
    '/ecc/v1/regions/'.REGION.'/volumes',
    '/ecc/v1/regions/'.REGION.'/ssh-keys',
    '/ecc/v1/regions/'.REGION.'/floating-ips',
];

echo "═══ سهمیه/اعتبارِ حسابِ آروان (فقط GET) ═══\n\n";

foreach ($paths as $p) {
    $r = g($token, $p);
    echo str_pad($p, 44).' HTTP '.str_pad((string) $r['s'], 4).' '.$r['b']."\n";
}

echo "\n═══ چطور بخوانیم ═══\n";
echo "  اگر مسیرِ سهمیه پیدا شد و عددش صفر بود ⇒ ریشه همان است، نه پیلود.\n";
echo "  اگر همهٔ مسیرها ۴۰۴/۴۰۵ بودند ⇒ این API سهمیه را بیرون نمی‌دهد و\n";
echo "  تنها راهِ باقی‌مانده یک سفارشِ واقعیِ آزمایشی است (تصمیمِ مدیر).\n";
PHPEOF

cd "$APP" || exit 1
"$PHPBIN" "$PROBE"
