#!/usr/bin/env bash
#
# آزمونِ واقعیِ تحویلِ آروان — یک سرورِ کوچک می‌سازد و **فوراً حذفش می‌کند**.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/test-arvan-delivery.sh)
#
# ⚠️ برخلافِ سه کاوشِ قبلی، این یکی **سرورِ واقعی می‌سازد** — یعنی خرج دارد.
#    با اجازهٔ صریحِ کارفرما (۳۱ اوت ۲۰۲۶) نوشته شده: «بساز و فوراً حذف کن».
#
# ═══ چرا لازم است ═══
#
# ثابت شد «Requested firewall was not found» ۴۰۴ِ **عمومیِ** آروان است: چهار
# خرابیِ متفاوت (flavor، image، network، firewall) هر چهار همان یک جمله را
# دادند. و اعتبارسنجیِ فیلد‌به‌فیلد نشان داد پیلودِ امروزِ ما در ir-thr-si1
# تماماً معتبر است و سهمیه هم مانع نیست. تنها چیزی که هیچ آزمایشِ بی‌هزینه‌ای
# نمی‌تواند بگوید این است که پیلودِ **سالم** می‌گذرد یا نه.
#
# ═══ چرا از createServer() خودمان استفاده می‌کنیم، نه پیلودِ دستی ═══
#
# پیلودِ دست‌ساز فقط ثابت می‌کند «آروان چیزی را می‌پذیرد»، نه اینکه **کدِ
# تحویلِ ما** درست کار می‌کند. سه بارِ گذشته دقیقاً همین‌جا گم شدیم. پس
# مسیرِ واقعیِ تولید صدا زده می‌شود — با همان کشف‌ها، همان ترجمهٔ ایمیج،
# همان انتخابِ شبکه و گروه.
#
# ═══ ایمنی ═══
#
#   · فقط کوچک‌ترین پلن: گاردِ سخت روی vcpu ≤ ۲ و disk ≤ ۳۰ گیگ.
#   · نامِ یکتا، تا با محافظِ idempotency سرورِ دیگری «به فرزندی» گرفته نشود.
#   · حذف در بلوکِ finally است: حتی اگر وسطِ کار استثنا رخ دهد، اجرا می‌شود.
#   · اگر حذف نشد، تا ۶ بار با فاصله تکرار می‌شود (سرورِ در حالِ ساخت گاهی
#     بلافاصله حذف نمی‌شود) و در پایان فهرستِ سرورهای منطقه چاپ می‌شود تا
#     هیچ ماشینِ یتیمی پنهان نمانَد.
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

PROBE="$HOME/.arvan-test-$$.php"
trap 'rm -f "$PROBE"' EXIT

cat > "$PROBE" << 'PHPEOF'
<?php
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Services\Cloud\ArvanClient;

const REGION = 'ir-thr-si1';

$client = new ArvanClient();

if (! $client->isConfigured()) { echo "🔴 توکنِ آروان تنظیم نشده\n"; exit(1); }

/*
| کوچک‌ترین پلنِ همین منطقه. اگر روزی کاتالوگ عوض شد و پلنِ بزرگ‌تری اول
| آمد، گاردِ زیر جلویش را می‌گیرد — این اسکریپت نباید هرگز چیزِ گران بخرد.
*/
$plan = CloudPlan::query()
    ->where('provider', 'arvan')
    ->where(fn ($q) => $q->where('provider_location', REGION)->orWhere('location_code', 'ir-tehran'))
    ->orderBy('vcpu')->orderBy('ram_mb')->orderBy('disk_gb')
    ->first();

if ($plan === null) { echo "🔴 پلنِ آروان برای این منطقه نیست\n"; exit(1); }

if ((int) $plan->vcpu > 2 || (int) $plan->disk_gb > 30) {
    echo "🔴 گاردِ ایمنی: کوچک‌ترین پلن هم بزرگ است (vcpu={$plan->vcpu} disk={$plan->disk_gb}) — رد شد.\n";
    exit(1);
}

$imageKey = (string) config('cloud.default_image', 'ubuntu-24.04');
$imageRef = (string) (CloudImage::refFor('arvan', $imageKey, $plan->arch) ?? '');

if ($imageRef === '') { echo "🔴 ایمیجِ پیش‌فرض پیدا نشد\n"; exit(1); }

// نامِ یکتا — تا findByName سرورِ دیگری را «به فرزندی» نگیرد
$name = 'snet-deltest-'.date('mdHis');

echo "═══ آزمونِ واقعیِ تحویل ═══\n";
echo "  پلن #{$plan->id} {$plan->slug}  vcpu={$plan->vcpu} ram={$plan->ram_mb}MB disk={$plan->disk_gb}GB\n";
echo "  منطقه=".REGION."  flavor={$plan->provider_ref}  image_key={$imageKey}\n";
echo "  نام={$name}\n\n";

$ref = null;

try {
    $res = $client->createServer([
        'name'         => $name,
        'plan_ref'     => (string) $plan->provider_ref,
        'location_ref' => REGION,
        'image_ref'    => $imageRef,
        'ssh_keys'     => [],
        'disk_gb'      => (int) $plan->disk_gb,
        'labels'       => ['snet-test' => '1'],
    ]);

    $ok = (bool) ($res['ok'] ?? false);
    $ref = $res['ref'] ?? null;

    echo ($ok ? "✅ ساخت موفق بود\n" : "🔴 ساخت شکست خورد\n");
    echo '   ref='.var_export($ref, true)."\n";
    echo '   وضعیت='.var_export($res['status'] ?? null, true)
        .'  ipv4='.var_export($res['ipv4'] ?? null, true)
        .'  رمزِ root '.(filled($res['root_password'] ?? null) ? 'برگشت' : 'برنگشت')."\n";

    if (! $ok) {
        echo '   پیام: '.(string) ($res['message'] ?? '')."\n";
        echo '   خام:  '.mb_substr((string) ($res['raw']['detail'] ?? ''), 0, 300)."\n";
    }
} catch (\Throwable $e) {
    echo '🔴 استثنا: '.$e->getMessage()."\n";
} finally {
    /*
    | 🟢 حذف در finally — حتی اگر بالا استثنا رخ دهد. سرورِ یتیم اجاره‌اش
    | تا ابد از حساب کم می‌شود و کسی هم خبردار نمی‌شود.
    */
    if (filled($ref)) {
        echo "\n── حذف ──\n";
        $done = false;

        for ($i = 1; $i <= 6; $i++) {
            $d = $client->deleteServer((string) $ref);
            echo "  تلاش {$i}: ".($d['ok'] ? '✅ حذف شد' : '⏳ '.(string) $d['message'])."\n";

            if ($d['ok']) { $done = true; break; }

            sleep(10);   // سرورِ در حالِ ساخت گاهی بلافاصله حذف نمی‌شود
        }

        if (! $done) {
            echo "  🔴🔴 حذف نشد! در پنلِ آروان دستی پاکش کنید — ref={$ref}\n";
        }
    } else {
        echo "\n(چیزی ساخته نشد ⇒ چیزی برای حذف نیست)\n";
    }
}

// ── تأییدِ نهایی: هیچ سرورِ یتیمی نمانده باشد ──
// (listServers بی‌آرگومان است و همهٔ مناطق را می‌دهد — همان که
//  می‌خواهیم: اگر جایی چیزی مانده، این‌جا دیده می‌شود.)
echo "\n── سرورهای باقی‌مانده در کلِ حساب ──\n";
$list = $client->listServers();
$rows = (array) ($list['servers'] ?? []);
echo '  تعداد: '.count($rows)."\n";

foreach (array_slice($rows, 0, 10) as $s) {
    echo '   · '.(string) ($s['name'] ?? '?').'  ref='.(string) ($s['ref'] ?? '?')
        .'  وضعیت='.(string) ($s['status'] ?? '?')."\n";
}

echo count($rows) === 0
    ? "  ✅ هیچ سروری نمانده — حساب مثلِ قبل تمیز است.\n"
    : "  ⚠️ بالا را نگاه کنید: اگر snet-deltest هست، دستی حذفش کنید.\n";
PHPEOF

cd "$APP" || exit 1
"$PHPBIN" "$PROBE"
