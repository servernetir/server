<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$res = $kernel->handle(Illuminate\Http\Request::create('http://localhost/sitemap.xml', 'GET'));
preg_match_all('~<loc>(.*?)</loc>~', $res->getContent(), $m);
$urls = $m[1];

echo "--- country marketing pages in sitemap? ---\n";
foreach (['germany', 'iran', 'england', 'austria', 'singapore', 'netherlands'] as $s) {
    echo str_pad('/vps/'.$s, 20).' => '.(in_array('http://localhost/vps/'.$s, $urls, true) ? 'IN' : 'MISSING')."\n";
}

echo "--- location pages: status + in sitemap ---\n";
foreach (\App\Models\CloudLocation::where('is_active', true)->pluck('code') as $c) {
    $r = $kernel->handle(Illuminate\Http\Request::create('http://localhost/cloud/'.$c, 'GET'));
    echo str_pad('/cloud/'.$c, 24).' status='.$r->getStatusCode()
        .'  sitemap='.(in_array('http://localhost/cloud/'.$c, $urls, true) ? 'IN' : 'MISSING')."\n";
}

echo "--- is /cloud or /lookup linked in header/footer HTML of homepage? ---\n";
$home = $kernel->handle(Illuminate\Http\Request::create('http://localhost/', 'GET'))->getContent();
foreach (['href="http://localhost/cloud"', 'href="http://localhost/lookup"', '/cloud"', '/lookup"'] as $needle) {
    echo str_pad($needle, 34).' => '.(substr_count($home, $needle))." occurrences\n";
}
preg_match_all('~href="[^"]*?/(cloud|lookup)(/[a-z0-9-]+)?"~', $home, $mm);
echo '  matched hrefs: '.implode(' | ', array_unique($mm[0]))."\n";
