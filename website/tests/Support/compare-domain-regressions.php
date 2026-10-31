<?php

/** Compare existing tests with the pinned develop base; never call baseline failures passes. */
function domainRegressionCases(string $path): array
{
    if (! is_file($path)) throw new RuntimeException('Missing JUnit report: '.$path);
    $xml = simplexml_load_file($path);
    if ($xml === false) throw new RuntimeException('Invalid JUnit report: '.$path);
    $cases = [];
    foreach ($xml->xpath('//testcase') as $case) {
        $key = (string) $case['class'].'::'.(string) $case['name'];
        if (isset($cases[$key])) throw new RuntimeException('Duplicate JUnit testcase: '.$key);
        $cases[$key] = isset($case->error) ? 'error' : (isset($case->failure) ? 'failure'
            : (isset($case->skipped) ? 'skipped' : 'passed'));
    }
    if (count($cases) < 150 || count(array_filter($cases, fn ($state) => $state === 'passed')) < 30) {
        throw new RuntimeException('Domain regression run incomplete or initialization failed: '.$path);
    }
    return $cases;
}
$baseline = domainRegressionCases($argv[1]);
$head = domainRegressionCases($argv[2]);
$regressions = [];
$known = [];
foreach ($baseline as $key => $state) {
    if (! isset($head[$key])) $regressions[] = 'Missing testcase: '.$key;
    elseif ($head[$key] !== 'passed' && $head[$key] !== $state) $regressions[] = $key.' ('.$state.' -> '.$head[$key].')';
    if (in_array($state, ['failure', 'error'], true)) $known[] = $key;
}
foreach ($head as $key => $state) {
    if (! isset($baseline[$key]) && $state !== 'passed') $regressions[] = 'New unsuccessful testcase: '.$key;
}
echo 'Compared existing domain cases: '.count($baseline).PHP_EOL;
echo 'Pre-existing failures/errors, not counted as passes: '.count($known).PHP_EOL;
foreach ($known as $key) echo 'BASELINE FAILURE: '.$key.PHP_EOL;
if ($regressions !== []) {
    foreach ($regressions as $case) fwrite(STDERR, 'REGRESSION: '.$case.PHP_EOL);
    exit(1);
}
echo 'No new domain regression relative to pinned develop baseline.'.PHP_EOL;
