<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$a = $kernel->handle(Illuminate\Http\Request::create('http://localhost/lookup', 'GET'))->getContent();
$b = $kernel->handle(Illuminate\Http\Request::create('http://localhost/lookup/a', 'GET'))->getContent();

echo 'len(/lookup)   = '.strlen($a)."\n";
echo 'len(/lookup/a) = '.strlen($b)."\n";
echo 'identical      = '.($a === $b ? 'YES' : 'NO')."\n";

// strip the canonical/og:url lines then compare
$norm = fn ($h) => preg_replace('~http://localhost/lookup(/a)?~', 'X', $h);
echo 'identical after normalising the URL itself = '.($norm($a) === $norm($b) ? 'YES' : 'NO')."\n";

preg_match('~<title>(.*?)</title>~s', $a, $t1);
preg_match('~<title>(.*?)</title>~s', $b, $t2);
echo "title(/lookup)   = ".trim($t1[1] ?? '?')."\n";
echo "title(/lookup/a) = ".trim($t2[1] ?? '?')."\n";

preg_match('~<link rel="canonical" href="([^"]+)"~', $a, $c1);
preg_match('~<link rel="canonical" href="([^"]+)"~', $b, $c2);
echo 'canonical(/lookup)   = '.($c1[1] ?? '?')."\n";
echo 'canonical(/lookup/a) = '.($c2[1] ?? '?')."\n";

if ($a !== $b) {
    // show first difference
    $len = min(strlen($a), strlen($b));
    for ($i = 0; $i < $len; $i++) {
        if ($a[$i] !== $b[$i]) {
            echo "first diff at byte $i:\n";
            echo '  A: '.substr($a, max(0, $i - 90), 200)."\n";
            echo '  B: '.substr($b, max(0, $i - 90), 200)."\n";
            break;
        }
    }
}
