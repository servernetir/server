<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), __DIR__.'/compiled');
@mkdir(__DIR__.'/compiled', 0700, true);
$manifest = json_decode(file_get_contents(__DIR__.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$php = '/opt/cpanel/ea-php84/root/usr/bin/php';
foreach ($manifest as $item) {
    $path = __DIR__.'/files/'.$item['path'];
    if (str_ends_with($path, '.blade.php')) {
        $destination = __DIR__.'/compiled/'.hash('sha256', $item['path']).'.php';
        file_put_contents($destination, $compiler->compileString(file_get_contents($path)));
    } elseif (str_ends_with($path, '.php')) {
        $destination = $path;
    } else { continue; }
    exec(escapeshellarg($php).' -d short_open_tag=1 -l '.escapeshellarg($destination).' 2>&1', $output, $code);
    if ($code !== 0) { fwrite(STDERR, implode("\n", $output)); exit(1); }
}
echo "ATLAS_PHP_BLADE_LINT_OK\n";
