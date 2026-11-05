<?php

/*
 | پذیرشِ فقط‌خواندنیِ پنلِ DNS پس از انتشار (۳ نوامبر ۲۰۲۶).
 |
 | صفحه‌های واقعیِ /account/dns را داخلِ همان اپِ تولید با controller و view واقعی
 | برای یک مشتریِ دارای zone رندر می‌کند (بی‌middleware، پس بی‌هیچ نوشتنی).
 | فقط وضعیت، اندازه و نشانه‌های ساختاری چاپ می‌شوند — هیچ نام، دامنه یا رکوردی.
 |
 | 🔴 بی‌عارضه: نشست array، هیچ POST، مشتری با setUser() نه login().
 |
 | اجرا: php dns-panel-v2-render-check.php ~/servernet_app
 */

$appDir = rtrim($argv[1] ?? '', '/');
require $appDir.'/vendor/autoload.php';
$app = require $appDir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 🔴 بی‌عارضه: controller مستقیم صدا زده می‌شود، نه از HTTP kernel؛ پس هیچ middlewareِ
//    نشست/تولد/IP اجرا نمی‌شود و هیچ چیزی در دیتابیس یا sessions نوشته نمی‌شود.
config(['session.driver' => 'array']);

$zone = App\Models\CdnZone::query()->where('mode', 'ns')->whereIn('status', ['active', 'pending'])
    ->whereHas('records')->orderByDesc('id')->first();
if (! $zone) {
    echo "NO ZONE\n";
    exit(2);
}
$customer = App\Models\Customer::find($zone->customer_id);
echo 'flag/visible: '.(App\Services\Dns\DnsConfig::serviceOpen() ? 'open' : 'closed')."\n";

$markers = [
    'index'  => ['dns-hero', 'dns-stats', 'dns-zones', 'dns-how', 'assets/js/dns.js'],
    'zone'   => ['dns-hero', 'dns-rec', 'data-dns-search', 'dns-type-help', 'dns-nodes', 'dns-danger'],
    'import' => ['dns-tabs', 'dns-drop', 'dns-zonefile'],
];

$bad = 0;
foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $loc => $prefix) {
    foreach (['index', 'zone', 'import'] as $name) {
        $path = $prefix.'/account/dns'.($name === 'index' ? '' : '/'.$zone->id).($name === 'import' ? '/import' : '');
        $req = Illuminate\Http\Request::create('https://console.servernet.cloud'.$path, 'GET');
        $req->setLaravelSession($app['session']->driver());
        $app->instance('request', $req);
        Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
        app('url')->setRequest($req);
        app()->setLocale($loc);
        Illuminate\Support\Facades\Auth::forgetGuards();
        Illuminate\Support\Facades\Auth::guard('customer')->setUser($customer);
        app('view')->share('errors', new Illuminate\Support\ViewErrorBag);

        $c = app(App\Http\Controllers\Account\DnsController::class);
        try {
            $view = match ($name) {
                'index'  => $c->index(),
                'zone'   => $c->show($zone->id),
                'import' => $c->import($req, $zone->id),
            };
            $html = $view instanceof Illuminate\Contracts\View\View ? $view->render() : '';
            $status = $view instanceof Illuminate\Contracts\View\View ? 200 : 302;
        } catch (Throwable $e) {
            $html = ''; $status = 500;
            echo '  EXCEPTION '.get_class($e).': '.mb_substr($e->getMessage(), 0, 160).' @ '.basename($e->getFile()).':'.$e->getLine()."\n";
        }

        $missing = array_values(array_filter($markers[$name], fn ($m) => ! str_contains($html, $m)));
        $main = preg_match('~<main\b[^>]*>(.*?)</main>~si', $html, $m) ? $m[1] : $html;
        $text = html_entity_decode(strip_tags(preg_replace('~<script\b.*?</script>|<style\b.*?</style>|<!--.*?-->~si', '', $main)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawKeys = preg_match_all('~\bdns\.(ux|ui|zone|form|list|step|check|status|publish|node|delegation|import|claim)\.[a-z_]+~', $text);
        // ⚠️ متنِ فارسی در en/tr ممکن است از دادهٔ خودِ مشتری باشد (یادداشتِ رکورد)؛ فقط شمرده می‌شود.
        $persian = $loc === 'fa' ? 0 : preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $ok = $status === 200 && $missing === [] && $rawKeys === 0;
        $bad += $ok ? 0 : 1;
        printf("%-3s %-7s %d %6d bytes  missing=%s rawkeys=%d fa-chars=%d %s\n", $loc, $name, $status, strlen($html),
            $missing ? implode(',', $missing) : '-', $rawKeys, $persian, $ok ? 'OK' : 'BAD');
    }
}
echo $bad === 0 ? "RENDER OK\n" : "RENDER FAILED ($bad)\n";
exit($bad === 0 ? 0 : 1);
