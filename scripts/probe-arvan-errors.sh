#!/usr/bin/env bash
#
# واژه‌نامهٔ خطاهای آروان — «Requested firewall was not found» یعنی چه؟
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/probe-arvan-errors.sh)
#
# ═══ 🟢 چرا این آزمایش هیچ سروری نمی‌سازد و هیچ هزینه‌ای ندارد ═══
#
# هر یک از چهار درخواست **عمداً دقیقاً یک فیلدِ نامعتبر** دارد. یک POST با
# فیلدِ نامعتبر نمی‌تواند موفق شود، پس هیچ ماشینی ساخته نمی‌شود و هیچ ریالی
# خرج نمی‌شود. اسکریپت پیش از هر ارسال هم بررسی می‌کند که فیلدِ خراب واقعاً
# خراب باشد؛ اگر نبود، همان‌جا می‌ایستد. **هرگز پیلودِ کاملاً معتبر نمی‌فرستد.**
#
# ═══ چه چیزی را ثابت می‌کند ═══
#
# کاوشِ دوم نشان داد پیلودِ واقعیِ ما حالا در ir-thr-si1 **تماماً معتبر** است:
# flavor ✅ image ✅ (ترجمه‌شده) network ✅ security_group ✅. ولی سرویسِ ۹۳ با
# «Requested firewall was not found» شکست خورده بود — آن هم *پیش از* دیپلویِ
# ترجمهٔ ایمیجِ منطقه‌ای، یعنی با شناسهٔ ایمیجِ منطقهٔ دیگر.
#
# فرض: آن پیام اصلاً دربارهٔ فایروال نیست؛ پیامِ عمومیِ «چیزی در پیلودت پیدا
# نشد» است. اگر با flavorِ خراب یا imageِ خراب یا شبکهٔ خراب هم همان جملهٔ
# فایروال بیاید، فرض ثابت می‌شود — و آن‌وقت می‌دانیم پیلودِ درست‌شدهٔ امروز
# دیگر مشکلی ندارد و فقط باید یک بار واقعی امتحان شود.
#
# اگر برعکس، هر خرابی پیامِ مخصوصِ خودش را داشت، آن‌وقت «firewall not found»
# واقعاً دربارهٔ فایروال است و باید از پشتیبانیِ آروان پرسید.
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

PROBE="$HOME/.arvan-err-$$.php"
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
const ECC    = '/ecc/v1';
const REGION = 'ir-thr-si1';

// مقادیرِ معتبرِ امروز — همان‌ها که کاوشِ دوم تک‌تک تأییدشان کرد
const OK_FLAVOR  = 'eco-1-1-0';
const OK_IMAGE   = '22e2c810-7ddd-45cd-9f60-37d5553e8894';
const OK_NETWORK = 'ffc45924-fb78-475d-b315-f518c2153bf7';
const OK_GROUP   = 'servernet';

// شناسه‌هایی که قطعاً وجود ندارند
const BAD_UUID = '00000000-0000-4000-8000-000000000000';

$token = Setting::getSecret('arvan_api_token');

if (blank($token)) { echo "🔴 توکن نیست\n"; exit(1); }

function post(string $token, array $payload): array
{
    try {
        $res = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()->timeout(30)->connectTimeout(10)
            ->post(BASE.ECC.'/regions/'.REGION.'/servers', $payload);
    } catch (\Throwable $e) {
        return ['status' => 0, 'body' => 'transport: '.$e->getMessage()];
    }

    return ['status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 400)];
}

function base(): array
{
    return [
        // ⚠️ نام باید یکتا باشد تا با «از قبل هست» اشتباه نشود؛ عددِ ثابتِ
        // مبتنی بر ساعتِ روز کافی است و هیچ‌جا ذخیره نمی‌شود.
        'name'            => 'snet-probe-'.date('His'),
        'flavor_id'       => OK_FLAVOR,
        'image_id'        => OK_IMAGE,
        'network_ids'     => [OK_NETWORK],
        'security_groups' => [['name' => OK_GROUP]],
        'disk_size'       => 25,
        'count'           => 1,
        'ha_enabled'      => false,
    ];
}

/*
| ═══ 🔴 گاردِ ایمنی ═══
|
| هر مورد باید **دقیقاً یک** فیلدِ خرابِ اعلام‌شده داشته باشد. اگر روزی کسی
| موردی اضافه کند که با پایه یکسان است (یعنی پیلودِ معتبر)، این گارد جلویش
| را می‌گیرد — وگرنه اسکریپتِ «بی‌هزینه» بی‌صدا سرورِ واقعی می‌سازد.
*/
$cases = [
    'flavor خرابِ تنها'   => ['flavor_id' => 'no-such-flavor-xyz'],
    'image خرابِ تنها'    => ['image_id' => BAD_UUID],
    'network خرابِ تنها'  => ['network_ids' => [BAD_UUID]],
    'firewall خرابِ تنها' => ['security_groups' => [['name' => 'no-such-firewall-xyz']]],
];

echo "═══ واژه‌نامهٔ خطاهای آروان — منطقهٔ ".REGION." ═══\n";
echo "هر درخواست عمداً یک فیلدِ نامعتبر دارد ⇒ هیچ سروری ساخته نمی‌شود.\n\n";

foreach ($cases as $label => $override) {
    $payload = array_merge(base(), $override);

    // گارد: پیلود باید با پایه فرق داشته باشد و فرقش فقط همان کلیدها باشد
    $diff = array_keys($override);

    if ($diff === [] || $payload[$diff[0]] === base()[$diff[0]]) {
        echo "🔴 «{$label}» با پایه یکسان است — رد شد تا سرورِ واقعی ساخته نشود.\n";
        continue;
    }

    $r = post($token, $payload);
    echo '── '.$label."\n";
    echo '   HTTP '.$r['status'].'  →  '.preg_replace('/\s+/', ' ', $r['body'])."\n\n";
}

echo "✅ تمام. هیچ سروری ساخته نشد (هر چهار درخواست عمداً نامعتبر بود).\n";
PHPEOF

cd "$APP" || exit 1
"$PHPBIN" "$PROBE"
