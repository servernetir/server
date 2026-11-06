<?php

/** Actual solution Blade and app partial, with a synthetic shell and no application boot. */
$root = dirname(__DIR__, 2);
$locale = $argv[1] ?? 'fa';
$output = $argv[2] ?? sys_get_temp_dir().'/servernet-cloud-phone-voice-preview';
if (! in_array($locale, ['fa', 'en', 'tr'], true)) throw new InvalidArgumentException('Unsupported fixture locale');
if (! is_dir($output)) mkdir($output, 0700, true);
$dict = require "$root/lang/$locale/ui.php";
$fixtureConfig = ['solutions' => require "$root/config/solutions.php"];

function __($key, array $replace = []) {
    global $dict;
    $value = str_starts_with($key, 'ui.') ? ($dict[substr($key, 3)] ?? $key) : $key;
    foreach ($replace as $name => $replacement) $value = str_replace(':'.$name, (string) $replacement, $value);
    return $value;
}
function app($abstract = null, array $parameters = []) { global $fixtureApp; return $abstract ? $fixtureApp->make($abstract, $parameters) : $fixtureApp; }
function lc(array $values) { global $locale; return $values[$locale] ?? $values['fa'] ?? []; }
function config($key = null, $default = null) {
    global $fixtureConfig;
    $value = $fixtureConfig;
    foreach (explode('.', (string) $key) as $part) {
        if (! is_array($value) || ! array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}
function asset($path) { return 'https://servernet.cloud/'.ltrim($path, '/'); }
function asset_ver($path) { return asset($path); }
function lroute($name, $parameters = []) {
    global $locale;
    $prefix = 'https://servernet.cloud'.($locale === 'fa' ? '' : '/'.$locale);
    return $prefix.match ($name) {
        'solution' => '/solutions/'.(is_array($parameters) ? ($parameters['slug'] ?? reset($parameters)) : $parameters),
        'contact' => '/contact', default => '/'.str_replace('.', '/', $name),
    };
}
function fa_num($value) {
    global $locale;
    return $locale === 'fa' ? strtr((string) $value, array_combine(range(0, 9), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY))) : (string) $value;
}
function schema_ld(array $data, string $type): string {
    return json_encode(['@'.'context' => 'https://schema.org', '@'.'type' => $type] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

require "$root/vendor/autoload.php";
$fixtureApp = new class($locale) extends Illuminate\Container\Container {
    public function __construct(private string $locale) {}
    public function getLocale(): string { return $this->locale; }
};
Illuminate\Container\Container::setInstance($fixtureApp);
$filesystem = new Illuminate\Filesystem\Filesystem;
$privateViews = $output.'/views-'.$locale;
foreach (['layouts', 'partials', 'compiled'] as $directory) {
    if (! is_dir($privateViews.'/'.$directory)) mkdir($privateViews.'/'.$directory, 0700, true);
}
// Navigation and the database-backed related-blog panel are outside this isolated presentation test.
file_put_contents($privateViews.'/layouts/site.blade.php', '<!doctype html><html lang="{{ app()->getLocale() }}" dir="{{ $isFa ? \'rtl\' : \'ltr\' }}" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield(\'title\')</title><meta name="description" content="@yield(\'description\')">@stack(\'head\')</head><body><main id="main">@yield(\'content\')</main>@stack(\'scripts\')</body></html>');
file_put_contents($privateViews.'/partials/product-guides.blade.php', '<!-- Synthetic related-articles boundary: no database access. -->');
$compiler = new Illuminate\View\Compilers\BladeCompiler($filesystem, $privateViews.'/compiled');
$engines = new Illuminate\View\Engines\EngineResolver;
$engines->register('blade', fn () => new Illuminate\View\Engines\CompilerEngine($compiler, $filesystem));
$finder = new Illuminate\View\FileViewFinder($filesystem, [$privateViews, $root.'/resources/views']);
$views = new Illuminate\View\Factory($engines, $finder, new Illuminate\Events\Dispatcher($fixtureApp));
$views->setContainer($fixtureApp);
$fixtureApp->instance('view', $views);
$views->share(['isFa' => $locale === 'fa', 'lang' => $locale]);

foreach (['cloud-phone', 'managed'] as $slug) {
    $html = $views->make('pages.solution', ['slug' => $slug, 'sol' => $fixtureConfig['solutions'][$slug], 'release' => null])->render();
    file_put_contents($output.'/'.$slug.'-'.$locale.'.html', $html);
}
echo "Rendered actual cloud-phone and managed Blade: $locale\n";
