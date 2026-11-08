<?php
declare(strict_types=1);
// Composer autoload only: no application bootstrap, model, provider or transport.
if (PHP_SAPI !== 'cli' || count($argv) !== 5) { exit(1); }
require $argv[1].'/vendor/autoload.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(
    new Illuminate\Filesystem\Filesystem(), dirname($argv[3]).'/unused-blade-cache'
);
$compiler->setPath($argv[4]);
$compiled = $compiler->compileString(file_get_contents($argv[2]));
token_get_all($compiled, TOKEN_PARSE);
if (file_exists($argv[3]) || file_put_contents($argv[3], $compiled, LOCK_EX) !== strlen($compiled)
    || !chmod($argv[3], 0600)) { exit(1); }
