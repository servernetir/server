<?php
declare(strict_types=1);

try {
    $root = $argv[1];
    $plan = $argv[2];
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $rows = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    foreach ($rows as $index => $row) {
        if (!str_ends_with($row['destination'], '.blade.php')) continue;
        $compiled = dirname($plan).'/lint-'.$index.'.php';
        $source = (string) file_get_contents(dirname($plan).'/files/'.$index);
        file_put_contents($compiled, $app['blade.compiler']->compileString($source));
        $process = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'short_open_tag=1', '-l', $compiled]);
        $process->mustRun();
    }
    echo json_encode(['status'=>'blade_linted']), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Scoped Blade preflight failed.\n");
    exit(1);
}
