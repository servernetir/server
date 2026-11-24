<?php
/** Real component/translation fixture, with no app boot, account data or network. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa'; $output = $argv[2] ?? sys_get_temp_dir().'/hosting-domain-preview';
if (! in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Invalid locale');
if (! is_dir($output)) mkdir($output, 0700, true);
$dictionary = require $root.'/lang/'.$locale.'/hosting-domains.php';
function __($key) { global $dictionary; return $key === 'hosting-domains' ? $dictionary : ($dictionary[substr($key, strlen('hosting-domains.'))] ?? $key); }
function app() { global $locale; return new class($locale) { public function __construct(private string $locale) {} public function getLocale() { return $this->locale; } }; }
function cloud_eur_rate() { return 100000; }
function lroute($name) { global $locale; return ($locale === 'fa' ? '' : '/'.$locale).($name === 'account.profile' ? '/account/profile' : '/account/order-domain-quote'); }
require $root.'/vendor/autoload.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $output);
$template = file_get_contents($root.'/resources/views/account/partials/hosting-domain-purchase.blade.php');
ob_start(); eval('?>'.$compiler->compileString($template)); $html = ob_get_clean();
file_put_contents($output.'/hosting-domain-'.$locale.'.html', $html);
