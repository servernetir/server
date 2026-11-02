<?php

declare(strict_types=1);

/** Native PHP/Laravel Blade rehearsal. All mutable paths are unique throwaway fixtures. */
require dirname(__DIR__, 3).'/website/vendor/autoload.php';

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Compilers\BladeCompiler;

$checks = 0; $fixtures = [];
function expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (! $condition) throw new RuntimeException($message);
}
function privateDir(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0700, true)) throw new RuntimeException('fixture mkdir');
    chmod($path, 0700);
}
function putFixture(string $path, string $bytes, int $mode = 0644): void
{
    privateDir(dirname($path)); file_put_contents($path, $bytes); chmod($path, $mode);
}
function fixture(): array
{
    global $fixtures;
    $id = bin2hex(random_bytes(8)); $root = sys_get_temp_dir().'/domain-ui-native-'.$id;
    privateDir($root); $fixtures[] = $root; $home = $root.'/home'; $stage = $home.'/domain-ui-release-20261102';
    privateDir($home); privateDir($stage); privateDir($home.'/servernet_app/storage/framework/views');
    $uid = fileowner($home);
    if ($uid < 1) throw new RuntimeException('Native fixture must run as an unprivileged CI user');
    $namespace = 'ServerNet\\DomainUiReleaseNative'.$id;
    $source = (string) file_get_contents(__DIR__.'/engine.php');
    $source = str_replace('namespace ServerNet\\DomainUiRelease20261102;', 'namespace '.$namespace.';', $source, $count);
    expect($count === 1, 'test namespace substitution must be unique');
    $source = str_replace("private const HOME = '/home/servernetcloud';", "private const HOME = ".var_export($home, true).';', $source, $count);
    expect($count === 1, 'test HOME substitution must be unique');
    $source = str_replace("private const PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';", 'private const PHP = '.var_export(PHP_BINARY, true).';', $source, $count);
    expect($count === 1, 'test PHP substitution must be unique');
    putFixture($stage.'/engine.php', $source, 0600); require $stage.'/engine.php';
    $class = $namespace.'\\Engine'; $reflection = new ReflectionClass($class);
    $allowed = $reflection->getConstant('ALLOWED'); $views = $reflection->getConstant('VIEWS');
    $compiler = new BladeCompiler(new Filesystem, $home.'/servernet_app/storage/framework/views');
    $app = new class($compiler) {
        public function __construct(private BladeCompiler $compiler) {}
        public function make(string $name): BladeCompiler { if ($name !== 'blade.compiler') throw new RuntimeException('unexpected service'); return $this->compiler; }
    };
    $entries = []; $oldCompiled = [];
    foreach ($allowed as $i => $relative) {
        privateDir(dirname($home.'/'.$relative));
        $view = in_array($relative, $views, true);
        $mode = $relative === $views[1] ? 0600 : 0644;
        $before = $view ? '<section>Old view '.$i.'</section>' : null;
        $after = $view ? '<section>@if(true)<h1>New view '.$i.'</h1>@endif</section>'
            : (str_ends_with($relative, '.php') ? '<?php return ["title" => "Synthetic title"];' : '/* synthetic UI asset '.$i.' */');
        if ($before !== null) {
            putFixture($home.'/'.$relative, $before, $mode);
            $compiler->setPath($home.'/'.$relative);
            $compiledPath = $compiler->getCompiledPath($home.'/'.$relative);
            $compiled = $compiler->compileString($before)."\n<?php /**PATH {$home}/{$relative} ENDPATH**/ ?>";
            putFixture($compiledPath, $compiled);
            $oldCompiled[$compiledPath] = hash('sha256', $compiled);
        }
        $entries[] = ['destination' => $relative, 'before_sha256' => $before === null ? 'absent' : hash('sha256', $before),
            'after_sha256' => hash('sha256', $after), 'before_mode' => $before === null ? null : $mode, 'mode' => $mode, 'content' => $after];
    }
    $protected = 'servernet_app/app/Services/Domain/DomainSearch.php';
    putFixture($home.'/'.$protected, '<?php // synthetic unchanged backend');
    $plan = ['release' => 'domain-ui-20261102', 'source_sha' => str_repeat('a', 40), 'account_uid' => $uid,
        'entries' => $entries, 'protected' => [['destination' => $protected, 'sha256' => hash_file('sha256', $home.'/'.$protected)]]];
    $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); putFixture($stage.'/payload.json', $json, 0600);
    $store = new class($home, $oldCompiled) {
        public array $observations = [];
        public function __construct(private string $home, private array $oldCompiled) {}
        public function store(): self { return $this; }
        public function get(string $key, mixed $default = null): mixed { if ($key !== 'page:gen') throw new RuntimeException('unexpected cache read'); return 3; }
        public function forget(string $key): bool {
            if (! str_starts_with($key, 'page:g3:')) throw new RuntimeException('unexpected cache mutation');
            $old = true;
            foreach ($this->oldCompiled as $path => $hash) $old = $old && is_file($path) && hash_file('sha256', $path) === $hash;
            $this->observations[] = ['key' => $key, 'old_compiled' => $old,
                'deps_present' => is_file($this->home.'/public_html/assets/js/domain-home-discovery.js')];
            return true;
        }
    };
    Cache::swap($store);
    return compact('home', 'stage', 'uid', 'class', 'reflection', 'app', 'plan', 'store', 'oldCompiled') + ['pin' => hash('sha256', $json)];
}
function phase(array $fixture, string $name): array { return $fixture['class']::run($fixture['app'], $name, $fixture['pin']); }
function assertPhase(array $fixture, string $name, string $state): array
{
    $result = phase($fixture, $name);
    expect(($result['ok'] ?? false) === true && ($result['state'] ?? null) === $state, $name.' failed: '.json_encode($result));
    return $result;
}

try {
    $f = fixture();
    $inventory = $f['class']::inspect($f['pin']);
    expect($inventory['ok'] === true && count($inventory['files']) === 9, 'fixed raw inventory');
    expect(count($inventory['protected_files']) === 1
        && $inventory['protected_files'][0]['destination'] === $f['plan']['protected'][0]['destination']
        && $inventory['protected_files'][0]['sha256'] === hash_file('sha256', $f['home'].'/'.$f['plan']['protected'][0]['destination']),
        'only selected protected file raw hash is exposed');
    expect(! file_exists($f['stage'].'/state.json'), 'GET inspector must not create state');
    assertPhase($f, 'preview', 'previewed');
    expect(! file_exists($f['home'].'/public_html/assets/js/domain-home-discovery.js'), 'preview must not install dependencies');
    foreach ($f['plan']['entries'] as $entry) if ($entry['before_sha256'] !== 'absent') {
        expect(hash_file('sha256', $f['home'].'/'.$entry['destination']) === $entry['before_sha256'], 'preview changed source');
    }
    assertPhase($f, 'apply', 'applied');
    expect((phase($f, 'apply')['reason'] ?? '') === 'duplicate_or_unprepared_apply', 'duplicate apply guard');
    assertPhase($f, 'verify', 'verified');
    $beforeRollback = count($f['store']->observations);
    assertPhase($f, 'rollback', 'rolled_back');
    $rollbackObservations = array_slice($f['store']->observations, $beforeRollback);
    expect(count($rollbackObservations) === 24, 'targeted page cache count on rollback');
    expect($rollbackObservations[0]['old_compiled'] && $rollbackObservations[0]['deps_present'], 'old compiled restoration must precede dependency removal');
    expect($rollbackObservations[23]['old_compiled'] && ! $rollbackObservations[23]['deps_present'], 'rollback removed only new dependencies after old views active');
    foreach ($f['plan']['entries'] as $entry) {
        $path = $f['home'].'/'.$entry['destination'];
        expect($entry['before_sha256'] === 'absent' ? ! file_exists($path) : hash_file('sha256', $path) === $entry['before_sha256'], 'rollback source mismatch');
    }
    expect((fileperms($f['home'].'/servernet_app/resources/views/pages/domain-search.blade.php') & 0777) === 0600, 'private old view mode preserved');

    $g = fixture(); assertPhase($g, 'preview', 'previewed');
    $target = $g['home'].'/'.$g['plan']['entries'][0]['destination'];
    $original = (string) file_get_contents($target); file_put_contents($target, 'unrelated newer edit');
    expect((phase($g, 'apply')['reason'] ?? '') === 'destination_hash_drift', 'preflight must reject concurrent edit');
    expect(file_get_contents($target) === 'unrelated newer edit', 'preflight must preserve concurrent edit');
    file_put_contents($target, $original); assertPhase($g, 'apply', 'applied');
    file_put_contents($target, 'unrelated after scan');
    // Simulate mutation after restore's initial scan: the immediate per-file guard must still reject it.
    $engine = $g['reflection']->newInstanceWithoutConstructor();
    $uidProperty = $g['reflection']->getProperty('uid'); $uidProperty->setValue($engine, $g['uid']);
    $restore = $g['reflection']->getMethod('restoreOne');
    try {
        $restore->invoke($engine, $target, $g['stage'].'/before/file-0', $g['plan']['entries'][0]['before_sha256'],
            $g['plan']['entries'][0]['before_mode'], $g['plan']['entries'][0]['after_sha256']);
        throw new RuntimeException('immediate CAS failed to reject newer edit');
    } catch (RuntimeException $e) { expect($e->getMessage() === 'rollback_concurrent_drift', 'immediate rollback guard'); }
    expect((phase($g, 'rollback')['reason'] ?? '') === 'rollback_newer_file_rejected', 'complete rollback precheck must reject unknown hashes');
    expect(file_get_contents($target) === 'unrelated after scan', 'rollback must preserve newer work');
    expect(is_file($g['home'].'/public_html/assets/js/domain-home-discovery.js'), 'rejected rollback must not delete dependencies');

    $h = fixture(); $new = $h['home'].'/public_html/assets/js/domain-home-discovery.js';
    $outside = dirname($h['home']).'/untouched'; putFixture($outside, 'do not overwrite'); symlink($outside, $new);
    expect((phase($h, 'preview')['reason'] ?? '') === 'symlink_rejected', 'symlink target must be rejected');
    expect(file_get_contents($outside) === 'do not overwrite', 'symlink referent unchanged');
    expect(($h['class']::run($h['app'], 'exec', $h['pin'])['reason'] ?? '') === 'invalid_phase', 'no arbitrary phase');
    expect(($h['class']::inspect('untrusted')['ok'] ?? true) === false, 'unpinned payload rejected');
    echo json_encode(['checks' => $checks, 'passed' => true, 'synthetic_only' => true, 'production_paths_used' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (array_reverse($fixtures) as $root) {
        if (! str_starts_with($root, sys_get_temp_dir().'/domain-ui-native-')) throw new RuntimeException('cleanup scope');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) { if ($path->isDir() && ! $path->isLink()) rmdir($path->getPathname()); else unlink($path->getPathname()); }
        rmdir($root);
    }
}
