<?php
/** Real hero Blade; only the network canvas is replaced by a static map. No app boot or traffic. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-studio-home-preview';
if (!in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Unsupported locale');
if (!is_dir($output)) mkdir($output, 0700, true);
$dictionary = [];
foreach (['studio_carousel', 'ui', 'atlas'] as $domain) $dictionary[$domain] = require "$root/lang/$locale/$domain.php";
function __($key) { global $dictionary; [$domain, $name] = explode('.', $key, 2); return $dictionary[$domain][$name] ?? $key; }
function app($class = null) { return new class { public function configured() { return true; } }; }
function asset_ver($path) { return $path; }
function lroute($name) { global $locale; return ($locale === 'fa' ? '' : '/'.$locale).'/studio/download'; }
require "$root/vendor/autoload.php";
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$__env = new class { use Illuminate\View\Concerns\ManagesLoops, Illuminate\View\Concerns\ManagesStacks; protected $renderCount = 1; };
$home = file_get_contents("$root/resources/views/pages/home.blade.php");
$start = strpos($home, '<div class="sn-atlas sn-home">');
$end = strpos($home, '<div class="sn-proof-rail">');
$template = substr($home, $start, $end - $start).'</div>';
$template = str_replace("@include('partials.studio-carousel')", file_get_contents("$root/resources/views/partials/studio-carousel.blade.php"), $template);
$template = str_replace("@include('partials.studio-carousel-controls')", file_get_contents("$root/resources/views/partials/studio-carousel-controls.blade.php"), $template);
$template = str_replace("@include('partials.atlas-network')", '<div class="sn-atlas-display"><img style="width:100%;display:block" src="assets/img/atlas-earth.svg" alt=""></div>', $template);
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
foreach (['css/site.css', 'css/atlas.css', 'css/studio-carousel.css', 'js/studio-carousel.js', 'img/atlas-earth.svg', 'img/studio-coffee-campaign.webp'] as $asset) {
    copy($root.'/public/assets/'.$asset, $output.'/assets/'.$asset);
}
foreach (glob($root.'/public/assets/font/woff2/IRANSans*web.woff2') as $font) copy($font, $output.'/assets/font/woff2/'.basename($font));
file_put_contents($output."/$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ServerNet · Glass carousel preview</title><link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/atlas.css"><link rel="stylesheet" href="assets/css/studio-carousel.css"></head><body>'.$icons.'<main id="main" style="padding-top:0">'.$partial.'</main><script src="assets/js/studio-carousel.js"></script></body></html>');
echo "Rendered carousel $locale\n";
