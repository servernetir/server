<?php
/** Real hero Blade; only the network canvas is replaced by a static map. No app boot or traffic. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-studio-home-preview';
if (!in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Unsupported locale');
if (!is_dir($output)) mkdir($output, 0700, true);
$dictionary = [];
foreach (['studio_carousel', 'home_slides', 'ui', 'atlas', 'transactional-email'] as $domain) $dictionary[$domain] = require "$root/lang/$locale/$domain.php";
function __($key) { global $dictionary; [$domain, $name] = explode('.', $key, 2); return $dictionary[$domain][$name] ?? $key; }
function trans($key) { return __($key); }
function app($class = null) { return new class { public function configured() { return true; } public function getLocale() { global $locale; return $locale; } }; }
function asset_ver($path) { return $path; }
function lroute($name, $slug = null) { global $locale; return ($locale === 'fa' ? '' : '/'.$locale).match ($name) { 'cloud.location' => '/cloud/'.$slug, 'hosting' => '/hosting/'.$slug, 'contact' => '/contact', 'gpu' => '/gpu', 'email.transactional' => '/transactional-email', default => '/studio/download' }; }
function fa_num($value) { return $value; }
require "$root/vendor/autoload.php";
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$__env = new class { use Illuminate\View\Concerns\ManagesLoops, Illuminate\View\Concerns\ManagesStacks; protected $renderCount = 1; };
$home = file_get_contents("$root/resources/views/pages/home.blade.php");
$start = strpos($home, '<div class="sn-atlas sn-home">');
$end = strpos($home, '<section class="sn-domain sn-domain-discovery"');
$template = substr($home, $start, $end - $start).'</div>';
$template = str_replace("@include('partials.studio-carousel')", file_get_contents("$root/resources/views/partials/studio-carousel.blade.php"), $template);
$template = str_replace("@include('partials.studio-carousel-controls')", file_get_contents("$root/resources/views/partials/studio-carousel-controls.blade.php"), $template);
$template = str_replace("@include('partials.home-gpu-slide')", file_get_contents("$root/resources/views/partials/home-gpu-slide.blade.php"), $template);
$template = str_replace("@include('partials.home-service-slides')", file_get_contents("$root/resources/views/partials/home-service-slides.blade.php"), $template);
$template = str_replace("@include('partials.home-email-slide')", file_get_contents("$root/resources/views/partials/home-email-slide.blade.php"), $template);
$template = str_replace("@include('partials.transactional-email-visual')", file_get_contents("$root/resources/views/partials/transactional-email-visual.blade.php"), $template);
$template = str_replace("@include('partials.atlas-network')", '<div class="sn-atlas-display"><div class="sn-globe"><img class="sn-globe-poster" style="object-fit:contain" src="assets/img/atlas-earth.svg" alt=""></div><div class="sn-region-detail"><strong>'.__('atlas.iran').'</strong><p>'.__('atlas.region_iran').'</p><a href="#">'.__('atlas.view_region').'</a></div><div class="sn-region-picker"><button type="button" data-sn-region="IR">'.__('atlas.iran').'</button><button type="button" data-sn-region="TR">'.__('atlas.turkey').'</button></div></div>', $template);
$studioCarouselAvailable = true;
$atlasLead = __('ui.hero_lead');
$atlasLeadBreak = match ($locale) { 'en' => ' for enterprises', 'tr' => ' özel sunucu', default => ' برای سازمان‌ها' };
$atlasLeadParts = explode($atlasLeadBreak, $atlasLead, 2);
$rotators = [__('ui.hero_rot1')];
ob_start();
eval('?>'.$compiler->compileString($template));
$partial = ob_get_clean();
file_put_contents($output."/partial-$locale.html", $partial);
$dir = $locale === 'fa' ? 'rtl' : 'ltr';
$icons = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents("$root/resources/views/partials/icons.blade.php"));
foreach (['css', 'js', 'img', 'font/woff2'] as $directory) {
    if (!is_dir($output.'/assets/'.$directory)) mkdir($output.'/assets/'.$directory, 0700, true);
}
foreach (['css/site.css', 'css/atlas.css', 'css/atlas-public.css', 'css/studio-carousel.css', 'css/home-gpu-slide.css', 'css/email-showcase.css', 'js/studio-carousel.js', 'img/atlas-earth.svg', 'img/studio-coffee-campaign.webp'] as $asset) {
    copy($root.'/public/assets/'.$asset, $output.'/assets/'.$asset);
}
foreach (glob($root.'/public/assets/font/woff2/IRANSans*web.woff2') as $font) copy($font, $output.'/assets/font/woff2/'.basename($font));
file_put_contents($output."/$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ServerNet · Six services preview</title><link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/atlas.css"><link rel="stylesheet" href="assets/css/atlas-public.css"><link rel="stylesheet" href="assets/css/studio-carousel.css"><link rel="stylesheet" href="assets/css/home-gpu-slide.css"><link rel="stylesheet" href="assets/css/email-showcase.css"></head><body class="sn-public">'.$icons.'<div style="position:fixed;inset:0 0 auto;height:var(--header-h);display:flex;align-items:center;padding-inline:3vw;background:var(--bg);border-bottom:1px solid var(--line);z-index:10">ServerNet · '.strtoupper($locale).'</div><main id="main">'.$partial.'</main><script src="assets/js/studio-carousel.js"></script></body></html>');
echo "Rendered carousel $locale\n";
