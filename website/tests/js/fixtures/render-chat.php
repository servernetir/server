<?php
// Local fixture: real Blade compiler, translations, and SVG sprite; no application/database boot.
$root = dirname(__DIR__, 3);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-chat-preview';
if (!is_dir($output)) mkdir($output, 0700, true);
$dict = ['ui' => require "$root/lang/$locale/ui.php", 'chat' => require "$root/lang/$locale/chat.php"];
function __($key) { global $dict; [$file, $name] = explode('.', $key, 2); return $dict[$file][$name] ?? $key; }
function app($name = null) { global $locale; return new class($locale) { public function __construct(private string $locale) {} public function getLocale() { return $this->locale; } }; }
function asset_ver($path) { return '/'.$path; }
function route($name) { return '/fixture-chat'; }
require "$root/vendor/autoload.php";
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$routePrefix = '';
ob_start();
eval('?>'.$compiler->compileString(file_get_contents("$root/resources/views/partials/chat.blade.php")));
$partial = ob_get_clean();
file_put_contents($output."/partial-$locale.html", $partial);
$dir = $locale === 'fa' ? 'rtl' : 'ltr';
$icons = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents("$root/resources/views/partials/icons.blade.php"));
file_put_contents($output."/$locale.html", '<!doctype html><html lang="'.$locale.'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture-only"><link rel="stylesheet" href="/assets/css/site.css"><title>ServerNet · Chat preview</title></head><body>'.$icons.'<main style="max-width:900px;margin:10vh auto;padding:24px"><p style="color:#8ca5bf;font-size:12px;letter-spacing:2px">SERVERNET / SUPPORT</p><h1 style="font-size:42px;margin-top:20px">'.__('chat.welcome').'</h1><p style="color:#8a94ac;margin-top:20px">'.__('chat.intro').'</p></main>'.$partial.'<script src="/assets/js/site.js" defer></script></body></html>');
echo "Rendered $locale\n";
