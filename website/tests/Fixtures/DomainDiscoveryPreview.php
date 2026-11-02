<?php
/** Isolated real-Blade fixture; synthetic prices, no app boot, database or provider traffic. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-domain-discovery-preview';
if (!in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Unsupported fixture locale');
if (!is_dir($output)) mkdir($output, 0700, true);
$dict = ['ui' => require "$root/lang/$locale/ui.php", 'domain-discovery' => require "$root/lang/$locale/domain-discovery.php"];
function __($key, array $replace = []) {
    global $dict;
    if (!str_contains($key, '.')) return $dict[$key] ?? $key;
    [$file, $name] = explode('.', $key, 2);
    $value = $dict[$file][$name] ?? $key;
    foreach ($replace as $k => $v) $value = str_replace(':'.$k, (string) $v, $value);
    return $value;
}
function app($name = null) { global $locale; return new class($locale) { public function __construct(private string $locale) {} public function getLocale() { return $this->locale; } }; }
function config($key = null, $default = null) { return $default ?? []; }
function asset_ver($path) { return '/'.$path; }
function route($name) { global $locale; return ($locale === 'fa' ? '' : '/'.$locale).'/api/domain-check'; }
function site_price($tld) { return app()->getLocale() === 'fa' ? '۱٬۲۵۰٬۰۰۰ تومان' : '€12.50'; }
function cloud_eur_rate() { return 100000; }
function lroute($name, $parameters = []) { global $locale; $prefix = $locale === 'fa' ? '' : '/'.$locale; return $prefix.match ($name) { 'domain.search.check' => '/api/domains/search', 'account.domains' => '/account/domains', default => '/domains' }; }
function fa_num($value) { global $locale; return $locale === 'fa' ? strtr((string) $value, array_combine(range(0, 9), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY))) : (string) $value; }
require "$root/vendor/autoload.php";
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$__env = new class { use Illuminate\View\Concerns\ManagesLoops, Illuminate\View\Concerns\ManagesStacks; protected $renderCount = 1; };
$template = file_get_contents("$root/resources/views/pages/domain-search.blade.php");
$template = preg_replace('/^@(?:extends|section)\([^\n]+\)\s*$/m', '', $template);
$template = str_replace('@endsection', '', $template);
ob_start();
eval('?>'.$compiler->compileString($template));
$partial = ob_get_clean();
file_put_contents($output."/partial-$locale.html", $partial);
$dir = $locale === 'fa' ? 'rtl' : 'ltr';
$icons = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents("$root/resources/views/partials/icons.blade.php"));
$styles = str_replace("../font/", "assets/font/", file_get_contents("$root/public/assets/css/site.css"));
$fontTarget = $output.'/assets/font/woff2';
if (!is_dir($fontTarget)) mkdir($fontTarget, 0700, true);
foreach (glob($root.'/public/assets/font/woff2/IRANSans*web.woff2') as $font) copy($font, $fontTarget.'/'.basename($font));
$demo = <<<'JS'
<script>
// Synthetic preview only. Never forwards a request to the network.
window.fetch = async function (_url, options) {
  const data = JSON.parse(options.body), base = data.q.replace(/\.[a-z.]+$/i, '');
  const names = data.tlds.slice(0, 7);
  const results = names.map(function (tld, i) {
    const domain = base + '.' + tld, deal = i % 3 === 0;
    return {domain:domain,tld:tld,primary:domain === data.q || i === 0,state:i === 2 ? 'taken' : 'free',available:i !== 2,orderable:i !== 2,price_toman:1250000+i*100000,quote_id:'synthetic-preview-'+i,period_years:1,is_promotion:deal,regular_price_toman:deal ? 2500000+i*100000 : null,discount_percent:deal ? 50 : null,promotion_ends_at:deal ? new Date(Date.now()+86400000).toISOString() : null};
  });
  return {ok:true,json:async()=>({ok:true,lookup_ok:true,results:results,suggestion_names:[{name:base+'studio.com'},{name:'get'+base+'.com'},{name:base+'hub.net'}]})};
};
</script>
JS;
file_put_contents($output."/$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="synthetic-fixture"><title>ServerNet · Domain discovery preview</title><style>'.$styles.'</style></head><body>'.$icons.'<main id="main" style="padding-top:0">'.$demo.$partial.'</main></body></html>');
// Render only the real homepage domain section; all other homepage content is outside this fixture.
$home = file_get_contents("$root/resources/views/pages/home.blade.php");
$start = strpos($home, '<section class="sn-domain');
$end = strpos($home, '</section>', $start) + strlen('</section>');
$routePrefix = $locale === 'fa' ? '' : $locale.'.';
$tlds = [['tld' => '.com', 'min_years' => 1], ['tld' => '.net', 'min_years' => 1], ['tld' => '.org', 'min_years' => 1]];
ob_start();
eval('?>'.$compiler->compileString(substr($home, $start, $end-$start)));
$homePartial = ob_get_clean();
file_put_contents($output."/home-partial-$locale.html", $homePartial);
$homeCss = file_get_contents("$root/public/assets/css/domain-home-discovery.css");
$homeJs = file_get_contents("$root/public/assets/js/domain-home-discovery.js");
$homeDemo = <<<'JS'
<script>
window.fetch = async function (_url, options) {
  const domain = JSON.parse(options.body).domain;
  return {ok:true,json:async()=>({result:{domain:domain,state:'free',available:true,price:'12.50',cart_url:'/domains?q='+encodeURIComponent(domain)},suggestions:[],lookup_ok:true})};
};
</script>
JS;
file_put_contents($output."/home-$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="synthetic-fixture"><title>ServerNet · Home domain preview</title><style>'.$styles.$homeCss.'</style></head><body>'.$icons.'<main id="main" style="padding-top:0">'.$homePartial.'</main>'.$homeDemo.'<script>'.$homeJs.'</script></body></html>');
echo "Rendered domain discovery and home $locale\n";
