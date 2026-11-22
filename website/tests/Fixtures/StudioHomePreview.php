<?php
/** Real Blade, synthetic public content, no Laravel bootstrap or external traffic. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-studio-home-preview';
if (!in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Unsupported locale');
if (!is_dir($output)) mkdir($output, 0700, true);
$dictionary = require "$root/lang/$locale/studio_home.php";
function __($key) { global $dictionary; return $dictionary[substr($key, strlen('studio_home.'))] ?? $key; }
function app($class = null) { return new class { public function configured() { return true; } }; }
function asset_ver($path) { return '/'.$path; }
function lroute($name) { global $locale; return ($locale === 'fa' ? '' : '/'.$locale).'/studio/download'; }
require "$root/vendor/autoload.php";
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$__env = new class { use Illuminate\View\Concerns\ManagesLoops, Illuminate\View\Concerns\ManagesStacks; protected $renderCount = 1; };
ob_start();
eval('?>'.$compiler->compileString(file_get_contents("$root/resources/views/partials/studio-home.blade.php")));
$partial = ob_get_clean();
file_put_contents($output."/partial-$locale.html", $partial);
$dir = $locale === 'fa' ? 'rtl' : 'ltr';
$icons = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents("$root/resources/views/partials/icons.blade.php"));
foreach (['css', 'js', 'img', 'font/woff2'] as $directory) {
    if (!is_dir($output.'/assets/'.$directory)) mkdir($output.'/assets/'.$directory, 0700, true);
}
foreach (['css/site.css', 'css/atlas.css', 'css/studio-home.css', 'js/studio-home.js', 'img/studio-imagination.svg'] as $asset) {
    copy($root.'/public/assets/'.$asset, $output.'/assets/'.$asset);
}
foreach (glob($root.'/public/assets/font/woff2/IRANSans*web.woff2') as $font) copy($font, $output.'/assets/font/woff2/'.basename($font));
file_put_contents($output."/$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ServerNet Studio · Homepage preview</title><link rel="stylesheet" href="assets/css/site.css"><link rel="stylesheet" href="assets/css/atlas.css"><link rel="stylesheet" href="assets/css/studio-home.css"></head><body>'.$icons.'<main id="main" style="padding-top:0"><div class="sn-atlas sn-home">'.$partial.'</div></main><script src="assets/js/studio-home.js"></script></body></html>');
echo "Rendered Studio homepage $locale\n";
