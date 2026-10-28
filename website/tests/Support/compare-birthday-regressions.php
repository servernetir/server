<?php

// Existing cases are compared with the immutable develop baseline.
// New birthday tests run separately and must all pass.
function cases(string $path): array
{
    if (! is_file($path)) throw new RuntimeException('Missing JUnit result: '.$path);
    $xml = simplexml_load_file($path);
    if ($xml === false) throw new RuntimeException('Invalid JUnit result: '.$path);
    $result = [];
    foreach ($xml->xpath('//testcase') as $case) {
        $key = (string) $case['class'].'::'.(string) $case['name'];
        $result[$key] = isset($case->error) ? 'error' : (isset($case->failure) ? 'failure' : (isset($case->skipped) ? 'skipped' : 'passed'));
    }
    if (count($result) < 200) throw new RuntimeException('Regression suite did not complete: '.$path);
    return $result;
}
$base = cases($argv[1]);
$head = cases($argv[2]);
$regressions = [];
$known = [];
foreach ($base as $key => $state) {
    if (! isset($head[$key])) $regressions[] = 'Missing case: '.$key;
    elseif ($head[$key] !== 'passed' && $head[$key] !== $state) $regressions[] = $key.' ('.$state.' -> '.$head[$key].')';
    if ($state === 'failure' || $state === 'error') $known[] = $key;
}
foreach ($head as $key => $state) {
    if (! isset($base[$key]) && $state !== 'passed') $regressions[] = 'New failing case: '.$key;
}
echo 'Unchanged regression cases: '.count($base).PHP_EOL;
echo 'Pre-existing failing cases (not counted as passes): '.count($known).PHP_EOL;
foreach ($known as $key) echo 'BASELINE FAILURE: '.$key.PHP_EOL;
if ($regressions) {
    foreach ($regressions as $failure) fwrite(STDERR, 'REGRESSION: '.$failure.PHP_EOL);
    exit(1);
}
echo 'No new regression relative to immutable develop baseline.'.PHP_EOL;
