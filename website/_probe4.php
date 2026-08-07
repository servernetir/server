<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::create('http://localhost/', 'GET'));

echo "--- plans per active location (what the page would show) ---\n";
foreach (\App\Models\CloudLocation::where('is_active', true)->get() as $loc) {
    $offers = \App\Models\CloudPlan::offers($loc->code);
    echo str_pad($loc->code, 18).' country='.str_pad((string) $loc->country, 4)
        .' offers='.$offers->count()."\n";
}

echo "\n--- pairwise similarity of rendered location pages ---\n";
$html = [];
foreach (\App\Models\CloudLocation::where('is_active', true)->pluck('code') as $c) {
    $h = $kernel->handle(Illuminate\Http\Request::create('http://localhost/cloud/'.$c, 'GET'))->getContent();
    // strip the URL itself so the comparison is about CONTENT
    $html[$c] = preg_replace('~/cloud/[a-z0-9-]+~', '/cloud/X', $h);
}
$codes = array_keys($html);
foreach ($codes as $i => $x) {
    foreach ($codes as $j => $y) {
        if ($j <= $i) {
            continue;
        }
        similar_text($html[$x], $html[$y], $pct);
        if ($pct > 90) {
            printf("  %-16s vs %-16s  %.2f%% identical\n", $x, $y, $pct);
        }
    }
}

echo "\n--- unique 'why' paragraph per Iranian location ---\n";
foreach (['ir-tehran', 'ir-shiraz', 'ir-isfahan', 'ir-ahvaz', 'ir-urmia'] as $c) {
    $h = $html[$c];
    preg_match('~<title>(.*?)</title>~s', $h, $t);
    echo '  '.str_pad($c, 12).' title='.trim($t[1] ?? '?')."\n";
}
