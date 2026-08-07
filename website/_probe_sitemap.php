<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$res = $kernel->handle($req = Illuminate\Http\Request::create('http://localhost/sitemap.xml', 'GET'));
$xml = $res->getContent();
preg_match_all('~<loc>(.*?)</loc>~', $xml, $m);
$urls = $m[1];
echo 'TOTAL: '.count($urls)."\n";

$want = ['/cloud', '/en/cloud', '/tr/cloud', '/lookup', '/en/lookup', '/tr/lookup'];
foreach ($want as $w) {
    $full = 'http://localhost'.$w;
    echo str_pad($w, 14).' => '.(in_array($full, $urls, true) ? 'IN SITEMAP' : 'MISSING')."\n";
}
echo "--- any /cloud/ location urls in sitemap ---\n";
foreach ($urls as $u) {
    if (str_contains($u, '/cloud')) {
        echo "  $u\n";
    }
}
echo "--- lookup urls in sitemap (first 10) ---\n";
$n = 0;
foreach ($urls as $u) {
    if (str_contains($u, 'lookup') || str_contains($u, 'dns-lookup') || str_contains($u, 'network-scan')) {
        echo "  $u\n";
        if (++$n >= 12) {
            break;
        }
    }
}

echo "--- DB active cloud locations ---\n";
try {
    $locs = \App\Models\CloudLocation::where('is_active', true)->pluck('code')->all();
    echo '  count='.count($locs).' :: '.implode(', ', $locs)."\n";
} catch (\Throwable $e) {
    echo '  ERR '.$e->getMessage()."\n";
}

echo "--- status codes ---\n";
foreach (['/cloud', '/lookup', '/en/cloud', '/tr/lookup'] as $p) {
    $r = $kernel->handle(Illuminate\Http\Request::create('http://localhost'.$p, 'GET'));
    echo str_pad($p, 12).' => '.$r->getStatusCode()."\n";
}
