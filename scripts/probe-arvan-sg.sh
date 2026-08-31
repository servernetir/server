#!/usr/bin/env bash
#
# کاوشِ گروهِ امنیتیِ آروان — «Requested firewall was not found» از کجاست؟
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/probe-arvan-sg.sh)
#
# 🟢 فقط‌خواندنی. هیچ POSTی نمی‌زند، هیچ سروری نمی‌سازد، هیچ فایلی از اپ را
#    تغییر نمی‌دهد. تنها یک فایلِ موقت در $HOME می‌سازد و آخرش پاکش می‌کند.
#
# ═══ چرا این کاوش، و نه یک حدسِ دیگر ═══
#
# تا اینجا شکلِ `security_groups` را حدس زده‌ایم و هر بار همان پیام آمده. ولی
# درسِ ثبت‌شدهٔ همین پرونده می‌گوید **پیامِ خطای آروان به منبعِ واقعیِ خطا اشاره
# نمی‌کند** (ایمیجِ منطقهٔ اشتباه هم «firewall not found» می‌داد). پس به‌جای
# حدسِ نوبتِ بعد، حقیقت را از خودِ آروان می‌گیریم:
#
#   ۱) گروه‌های امنیتیِ **هر منطقه** با تمامِ فیلدها — تا ببینیم گروهِ
#      «servernet» واقعاً در کدام منطقه است و `real_name`ش دقیقاً چیست.
#      (اگر در ir-thr-si1 نباشد، کلِ معما همین است.)
#
#   ۲) 🔴 نکتهٔ اصلی: سرورهای **موجود** و فیلدِ security_groupsشان. هر سروری
#      که در پنلِ خودِ آروان ساخته شده، نمایشِ *پذیرفته‌شدهٔ* گروه را در خود
#      دارد. این تنها منبعی است که حدس نیست — خودِ آروان نوشته‌اش.
#
#   ۳) خروجیِ واقعیِ securityGroupIds() ما، با کشِ پاک‌شده — تا معلوم شود
#      کدام شاخه فعال است. مسیرِ fallbackِ «$wanted خام» نامِ نمایشی را
#      می‌فرستد نه real_name، و همین می‌تواند کلِ ماجرا باشد.
#
set -u

APP="$HOME/servernet_app"

# ═══ اثباتِ مقصد پیش از هر کاری ═══
# درسِ ثبت‌شده: اجرا با کاربرِ اشتباه (root) یعنی $HOME عوض می‌شود و کاوش روی
# نصبی می‌دود که سایت آن‌جا نیست — یا اصلاً هیچ نصبی نیست.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   کاربرِ درست: servernetcloud  ·  چاره:  su - servernetcloud"
  exit 1
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "🔴 php پیدا نشد"; exit 1; }

PROBE="$HOME/.arvan-probe-$$.php"
trap 'rm -f "$PROBE"' EXIT

cat > "$PROBE" << 'PHPEOF'
<?php
/*
| کاوشِ فقط‌خواندنیِ آروان. توکن هرگز چاپ نمی‌شود — فقط بود/نبودش.
*/
// ⚠️ __DIR__ اینجا $HOME است نه ریشهٔ اپ (فایلِ موقت بیرونِ اپ ساخته می‌شود)،
// پس مسیر از cwd می‌آید — اسکریپت پیش از اجرا داخلِ $APP می‌رود.
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const BASE = 'https://napi.arvancloud.ir';
const ECC  = '/ecc/v1';

$token = Setting::getSecret('arvan_api_token');
$wanted = trim((string) Setting::get('arvan_security_group', ''));

echo "═══ تنظیمات ═══\n";
echo 'توکن: '.(filled($token) ? 'هست ('.strlen((string) $token)." کاراکتر)\n" : "🔴 نیست\n");
echo 'arvan_security_group: '.($wanted === '' ? '(خالی)' : $wanted)."\n\n";

if (blank($token)) { exit(1); }

/** GET خام — بدنه را همان‌طور که هست برمی‌گرداند. */
function arvGet(string $token, string $path, array $q = []): array
{
    try {
        $res = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()->timeout(30)->connectTimeout(10)
            ->get(BASE.$path, $q);
    } catch (\Throwable $e) {
        return ['status' => 0, 'body' => ['transport' => $e->getMessage()]];
    }

    return ['status' => $res->status(), 'body' => (array) ($res->json() ?? [])];
}

function j($v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

// ── ۱) مناطق ───────────────────────────────────────────────────────────
$regions = [];

foreach ([ECC.'/regions', ECC.'/details', ECC.'/regions/details'] as $p) {
    $r = arvGet($token, $p);

    if ($r['status'] === 200) {
        $rows = (array) ($r['body']['data'] ?? $r['body']);

        foreach ($rows as $row) {
            if (is_array($row) && filled($row['code'] ?? null)) {
                $regions[] = (string) $row['code'];
            }
        }

        if ($regions !== []) {
            echo "═══ مناطق (از {$p}) ═══\n".implode('، ', $regions)."\n\n";
            break;
        }
    }
}

if ($regions === []) {
    echo "🔴 هیچ منطقه‌ای خوانده نشد — کاوش همین‌جا می‌ایستد.\n";
    exit(1);
}

// ── ۲) گروه‌های امنیتیِ هر منطقه، با تمامِ فیلدها ──────────────────────
echo "═══ گروه‌های امنیتی، منطقه به منطقه ═══\n";

foreach ($regions as $reg) {
    $hit = null;

    foreach (['/securities', '/security-groups', '/securitygroups', '/firewalls'] as $p) {
        $r = arvGet($token, ECC.'/regions/'.rawurlencode($reg).$p);

        if ($r['status'] === 200) {
            $rows = array_values(array_filter((array) ($r['body']['data'] ?? $r['body']), 'is_array'));

            if ($rows !== []) { $hit = [$p, $rows]; break; }
        }
    }

    if ($hit === null) { echo "  {$reg}: — هیچ گروهی خوانده نشد\n"; continue; }

    [$path, $rows] = $hit;
    echo "  ── {$reg}  (مسیر {$path}، ".count($rows)." گروه)\n";

    foreach ($rows as $g) {
        // rules حجیم است و به معما ربطی ندارد
        unset($g['rules'], $g['abraks']);
        echo '     '.j($g)."\n";
    }
}

echo "\n";

// ── ۳) 🔴 حقیقتِ اصلی: سرورهای موجود و نمایشِ پذیرفته‌شدهٔ گروهشان ─────
echo "═══ سرورهای موجود — آروان خودش security_groups را چطور نگه می‌دارد؟ ═══\n";
$sawServer = false;

foreach ($regions as $reg) {
    $r = arvGet($token, ECC.'/regions/'.rawurlencode($reg).'/servers');

    if ($r['status'] !== 200) { continue; }

    $rows = array_values(array_filter((array) ($r['body']['data'] ?? $r['body']), 'is_array'));

    if ($rows === []) { echo "  {$reg}: بدونِ سرور\n"; continue; }

    echo "  ── {$reg}: ".count($rows)." سرور\n";

    foreach (array_slice($rows, 0, 5) as $s) {
        $sawServer = true;
        echo '     نام='.(string) ($s['name'] ?? '?')
            .'  وضعیت='.(string) ($s['status'] ?? '?')
            .'  image_id='.(string) ($s['image']['id'] ?? $s['image_id'] ?? '?')."\n";
        echo '     security_groups → '.j($s['security_groups'] ?? $s['securityGroups'] ?? '(فیلد نیست)')."\n";
    }
}

if (! $sawServer) {
    echo "  ⚠️ هیچ سروری وجود ندارد ⇒ نمی‌توان نمایشِ پذیرفته‌شده را دید.\n";
    echo "     چاره: در پنلِ آروان دستی یک سرورِ کوچک بسازید و این کاوش را دوباره بزنید.\n";
}

echo "\n";

// ── ۴) خروجیِ خودمان، با کشِ پاک‌شده ────────────────────────────────────
echo "═══ securityGroupIds() ما چه می‌فرستد؟ ═══\n";
$client = new \App\Services\Cloud\ArvanClient();
$m = new \ReflectionMethod($client, 'securityGroupIds');
$m->setAccessible(true);

foreach ($regions as $reg) {
    // کشِ همین کلید را می‌سوزانیم تا خواندنِ تازه باشد — نه کلِ کش، که
    // سایتِ زنده را سرد می‌کند.
    Cache::forget('arvan.sg.'.$reg.'.'.md5($wanted));
    $out = $m->invoke($client, $reg);
    echo '  '.$reg.' → '.j($out).'   ⇒ payload: '
        .j(array_map(fn ($n) => ['name' => $n], $out))."\n";
}

echo "\n";

// ── ۵) شبکه و ایمیجِ منطقهٔ فروشی — تا مطمئن شویم بقیهٔ پیلود سالم است ──
echo "═══ سلامتِ بقیهٔ پیلود ═══\n";

foreach ($regions as $reg) {
    $n = arvGet($token, ECC.'/regions/'.rawurlencode($reg).'/networks');
    $nRows = $n['status'] === 200 ? array_values(array_filter((array) ($n['body']['data'] ?? $n['body']), 'is_array')) : [];

    $i = arvGet($token, ECC.'/regions/'.rawurlencode($reg).'/images', ['type' => 'distributions']);
    $iRows = $i['status'] === 200 ? (array) ($i['body']['data'] ?? $i['body']) : [];
    $iCount = 0;
    array_walk_recursive($iRows, function ($v, $k) use (&$iCount) { if ($k === 'id') { $iCount++; } });

    echo '  '.$reg.'  شبکه='.count($nRows).'  ایمیجِ توزیع='.$iCount."\n";
}

echo "\n✅ کاوشِ فقط‌خواندنی تمام شد. هیچ چیزی تغییر نکرد.\n";
PHPEOF

cd "$APP" || exit 1
"$PHPBIN" "$PROBE"
