<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::create('http://localhost/', 'GET'));

echo "--- offers per active location ---\n";
foreach (\App\Models\CloudLocation::where('is_active', true)->get() as $loc) {
    echo str_pad($loc->code, 18).' country='.str_pad((string) $loc->country, 4)
        .' offers='.\App\Models\CloudPlan::offers($loc->code)->count()."\n";
}

echo "\n--- rendered page: size + title + how many plan rows ---\n";
$texts = [];
foreach (\App\Models\CloudLocation::where('is_active', true)->pluck('code') as $c) {
    $h = $kernel->handle(Illuminate\Http\Request::create('http://localhost/cloud/'.$c, 'GET'))->getContent();
    preg_match('~<title>(.*?)</title>~s', $h, $t);
    // body text only, URL-independent
    $body = preg_replace('~<script\b.*?</script>~s', ' ', $h);
    $body = preg_replace('~<style\b.*?</style>~s', ' ', $body);
    $body = preg_replace('~<[^>]+>~', ' ', $body);
    $body = preg_replace('~\s+~u', ' ', trim($body));
    $texts[$c] = $body;
    echo str_pad($c, 18).' bytes='.str_pad((string) strlen($h), 7)
        .' textlen='.str_pad((string) mb_strlen($body), 6)
        .' title='.trim($t[1] ?? '?')."\n";
}

echo "\n--- shared-word overlap between location page TEXTS (Jaccard on words) ---\n";
$codes = array_keys($texts);
foreach ($codes as $i => $x) {
    foreach ($codes as $j => $y) {
        if ($j <= $i) {
            continue;
        }
        $wx = array_unique(preg_split('~\s+~u', $texts[$x]));
        $wy = array_unique(preg_split('~\s+~u', $texts[$y]));
        $inter = count(array_intersect($wx, $wy));
        $union = count(array_unique(array_merge($wx, $wy)));
        printf("  %-16s vs %-16s  %.1f%% shared vocabulary\n", $x, $y, 100 * $inter / max(1, $union));
    }
}
