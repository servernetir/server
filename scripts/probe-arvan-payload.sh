#!/usr/bin/env bash
#
# کاوشِ فقط‌خواندنیِ **هر فیلدِ پیلودِ ساختِ سرورِ آروان**، جدا جدا.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/probe-arvan-payload.sh) [service_id]
#
# 🟢 فقط‌خواندنی. هیچ POSTی نمی‌زند، هیچ سروری نمی‌سازد، هیچ فایلی از اپ را
#    تغییر نمی‌دهد.
#
# ═══ چرا ═══
#
# کاوشِ اول ثابت کرد گروهِ امنیتی **درست** است: در ir-thr-si1 گروهی با
# real_name=servernet هست و پیلودِ ما دقیقاً [{"name":"servernet"}] می‌شود.
# پس پیامِ «Requested firewall was not found» — طبقِ درسِ ثبت‌شدهٔ همین پرونده —
# به منبعِ واقعیِ خطا اشاره نمی‌کند. (همان بار که ایمیجِ منطقهٔ اشتباه هم
# «firewall not found» می‌داد.)
#
# پس به‌جای حدسِ فرمتِ بعدی، **تک‌تکِ فیلدهای پیلود را در همان منطقه
# اعتبارسنجی می‌کنیم** و می‌بینیم کدام‌یک واقعاً در آن منطقه وجود ندارد:
#
#   flavor_id       → GET /regions/{r}/sizes         🔴 هرگز per-region ترجمه نشده
#   image_id        → GET /regions/{r}/images        (imageForRegion در سکوت
#                                                     همان refِ اشتباه را برمی‌گرداند
#                                                     اگر برچسب جور نشود)
#   network_ids     → GET /regions/{r}/networks      🔴 «اولین شبکه» ممکن است
#                                                     خصوصی باشد؛ si1 چهارده شبکه دارد
#   security_groups → GET /regions/{r}/securities    (کاوشِ اول: سالم)
#
set -u

APP="$HOME/servernet_app"
SVC="${1:-}"

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   کاربرِ درست: servernetcloud  ·  چاره:  su - servernetcloud"
  exit 1
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "🔴 php پیدا نشد"; exit 1; }

PROBE="$HOME/.arvan-payload-$$.php"
trap 'rm -f "$PROBE"' EXIT

cat > "$PROBE" << 'PHPEOF'
<?php
// __DIR__ اینجا $HOME است؛ ریشهٔ اپ از cwd می‌آید (اسکریپت اول cd می‌کند).
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

const BASE = 'https://napi.arvancloud.ir';
const ECC  = '/ecc/v1';

$token = Setting::getSecret('arvan_api_token');

if (blank($token)) { echo "🔴 توکن نیست\n"; exit(1); }

function arvGet(string $token, string $path, array $q = []): array
{
    try {
        $res = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()->timeout(30)->connectTimeout(10)->get(BASE.$path, $q);
    } catch (\Throwable $e) {
        return [];
    }

    if (! $res->successful()) { return []; }

    $b = (array) ($res->json() ?? []);

    return (array) ($b['data'] ?? $b);
}

/** همهٔ شناسه‌های یک فهرست — چه تخت، چه تودرتو (images دسته‌بندی‌شده می‌آید). */
function idsOf(array $rows): array
{
    $ids = [];
    array_walk_recursive($rows, function ($v, $k) use (&$ids) {
        if ($k === 'id' && is_scalar($v)) { $ids[(string) $v] = true; }
    });

    return $ids;
}

// ── سرویسِ هدف ─────────────────────────────────────────────────────────
$argSvc = (int) (getenv('PROBE_SVC') ?: 0);

$q = Service::query()->whereNotNull('cloud_plan_id');

$svc = $argSvc > 0
    ? $q->find($argSvc)
    : $q->whereHas('cloudPlan', fn ($p) => $p->where('provider', 'arvan'))
        ->orderByDesc('id')->first();

if ($svc === null) {
    echo "🔴 سرویسِ آروانی پیدا نشد. شناسه را به‌عنوان آرگومان بدهید.\n";
    exit(1);
}

$plan = CloudPlan::find($svc->cloud_plan_id);

if ($plan === null) { echo "🔴 پلنِ سرویس #{$svc->id} نیست\n"; exit(1); }

$region   = (string) ($plan->provider_location ?: $plan->location_code);
$flavorId = (string) $plan->provider_ref;
$imageKey = (string) ($svc->cloud_image_key ?: config('cloud.default_image', 'ubuntu-24.04'));
$imageRef = (string) (CloudImage::refFor($plan->provider, $imageKey, $plan->arch) ?? '');

echo "═══ سرویسِ هدف ═══\n";
echo "  service #{$svc->id}  ·  وضعیت={$svc->status}  ·  تحویل={$svc->provision_status}\n";
echo '  خطا: '.mb_substr((string) $svc->provision_error, 0, 300)."\n";
echo "  plan #{$plan->id}  slug={$plan->slug}  provider={$plan->provider}\n";
echo "  location_code={$plan->location_code}  provider_location=".(string) $plan->provider_location."\n";
echo "  ⇒ region = {$region}\n";
echo "  ⇒ flavor_id = {$flavorId}\n";
echo "  ⇒ image_key = {$imageKey}  ⇒ image_ref = {$imageRef}\n";
echo "  ⇒ disk_gb = ".(int) $plan->disk_gb."  arch={$plan->arch}\n\n";

// ── ۱) منطقه اصلاً وجود دارد؟ ─────────────────────────────────────────
$regions = [];

foreach ([ECC.'/regions', ECC.'/details'] as $p) {
    foreach (arvGet($token, $p) as $row) {
        if (is_array($row) && filled($row['code'] ?? null)) { $regions[] = (string) $row['code']; }
    }

    if ($regions !== []) { break; }
}

echo "═══ اعتبارسنجیِ فیلد به فیلد در منطقهٔ «{$region}» ═══\n";
echo '  region        '.(in_array($region, $regions, true) ? '✅ هست' : '🔴 در فهرستِ مناطق نیست')
    .'   (مناطق: '.implode('، ', $regions).")\n";

if (! in_array($region, $regions, true)) {
    echo "\n🔴 ریشه پیدا شد: منطقهٔ پلن اصلاً منطقهٔ آروان نیست.\n";
    exit(0);
}

$rp = ECC.'/regions/'.rawurlencode($region);

// ── ۲) flavor_id ───────────────────────────────────────────────────────
$sizes = arvGet($token, $rp.'/sizes');
$sizeIds = idsOf($sizes);
$flavorOk = isset($sizeIds[$flavorId]);

echo '  flavor_id     '.($flavorOk ? '✅ در این منطقه هست' : '🔴 در این منطقه نیست')
    .'   ('.count($sizeIds)." فلِیور در منطقه)\n";

if (! $flavorOk) {
    // آیا در منطقهٔ دیگری هست؟ اگر بله، ریشه همان «شناسهٔ منطقهٔ دیگر» است.
    $found = [];

    foreach ($regions as $r2) {
        if ($r2 === $region) { continue; }

        // ⚠️ isset() روی نتیجهٔ یک فراخوانی خطای کامپایل است — اول در متغیر
        $other = idsOf(arvGet($token, ECC.'/regions/'.rawurlencode($r2).'/sizes'));

        if (isset($other[$flavorId])) {
            $found[] = $r2;
        }
    }

    echo '                🔎 این flavor در: '.($found === [] ? 'هیچ منطقه‌ای' : implode('، ', $found))."\n";
}

// ── ۳) image_id — هم refِ خام، هم آنچه imageForRegion می‌سازد ──────────
$imgs = arvGet($token, $rp.'/images', ['type' => 'distributions']);
$imgIds = idsOf($imgs);

$client = new \App\Services\Cloud\ArvanClient();
$m = new \ReflectionMethod($client, 'imageForRegion');
$m->setAccessible(true);
$translated = (string) $m->invoke($client, $region, $imageRef);

echo '  image_ref     '.(isset($imgIds[$imageRef]) ? '✅ خودش در منطقه هست' : '⚠️ خودش در منطقه نیست')
    .'   ('.count($imgIds)." ایمیج در منطقه)\n";
echo '  image_id ارسالی '.(isset($imgIds[$translated]) ? '✅ معتبر' : '🔴 نامعتبر')
    .'  = '.$translated.($translated === $imageRef ? '   ⚠️ ترجمه نشد — همان refِ خام' : '   (ترجمه شد)')."\n";

if (! isset($imgIds[$translated])) {
    $label = (string) (CloudImage::query()->where('provider', 'arvan')
        ->where('provider_ref', $imageRef)->value('label') ?? '');
    echo '                🔎 برچسبِ ما: «'.$label."»\n";
    echo "                🔎 چند برچسبِ منطقه: ";
    $seen = 0;
    array_walk_recursive($imgs, function ($v, $k) use (&$seen) {
        if ($k === 'name' && $seen < 8) { echo $v.' · '; $seen++; }
    });
    echo "\n";
}

// ── ۴) network_ids ─────────────────────────────────────────────────────
$nets = arvGet($token, $rp.'/networks');
$mn = new \ReflectionMethod($client, 'publicNetworkId');
$mn->setAccessible(true);
\Illuminate\Support\Facades\Cache::forget('arvan.net.'.$region);
$netId = (string) ($mn->invoke($client, $region) ?? '');

$netRow = null;

foreach ($nets as $n) {
    if (is_array($n) && (string) ($n['network_id'] ?? $n['id'] ?? '') === $netId) { $netRow = $n; }
}

echo '  network_id    '.($netRow !== null ? '✅ هست' : '🔴 پیدا نشد').'  = '.$netId."\n";

if ($netRow !== null) {
    echo '                نام='.(string) ($netRow['name'] ?? '?')
        .'  نوع='.(string) ($netRow['type'] ?? '?')
        .'  gateway='.json_encode($netRow['enable_gateway'] ?? null)
        .'  عمومی؟='.json_encode($netRow['is_public'] ?? $netRow['public'] ?? null)."\n";
}

echo '                🔎 شبکه‌های منطقه ('.count($nets)."):\n";

foreach (array_slice(array_values(array_filter($nets, 'is_array')), 0, 20) as $n) {
    echo '                   '.str_pad((string) ($n['name'] ?? '?'), 22)
        .' type='.str_pad((string) ($n['type'] ?? '?'), 10)
        .' gw='.str_pad(json_encode($n['enable_gateway'] ?? null), 6)
        .' id='.(string) ($n['network_id'] ?? $n['id'] ?? '?')."\n";
}

// ── ۵) security_groups ─────────────────────────────────────────────────
$sg = arvGet($token, $rp.'/securities');
$ms = new \ReflectionMethod($client, 'securityGroupIds');
$ms->setAccessible(true);
$wanted = trim((string) Setting::get('arvan_security_group', ''));
\Illuminate\Support\Facades\Cache::forget('arvan.sg.'.$region.'.'.md5($wanted));
$names = (array) $ms->invoke($client, $region);

$real = [];

foreach ($sg as $g) {
    if (is_array($g)) { $real[(string) ($g['real_name'] ?? $g['name'] ?? '')] = true; }
}

$sgOk = $names !== [] && isset($real[(string) $names[0]]);
echo '  security_group '.($sgOk ? '✅ معتبر' : '🔴 نامعتبر').'  = '.json_encode($names, JSON_UNESCAPED_UNICODE)
    .'   (real_nameهای منطقه: '.implode('، ', array_keys($real)).")\n";

echo "\n═══ پیلودی که فرستاده می‌شود ═══\n";
echo json_encode([
    'name'            => 'snet-'.$svc->id.'-…',
    'flavor_id'       => $flavorId,
    'image_id'        => $translated,
    'network_ids'     => [$netId],
    'security_groups' => array_map(fn ($n) => ['name' => $n], $names),
    'disk_size'       => (int) $plan->disk_gb,
    'count'           => 1,
    'ha_enabled'      => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";

echo "\n✅ کاوشِ فقط‌خواندنی تمام شد. هیچ چیزی تغییر نکرد.\n";
PHPEOF

cd "$APP" || exit 1
PROBE_SVC="$SVC" "$PHPBIN" "$PROBE"
