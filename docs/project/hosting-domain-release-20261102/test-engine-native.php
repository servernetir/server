<?php

declare(strict_types=1);

// Native guard rehearsal with a synthetic compiler/router. No Laravel/production data is loaded.
$checks = 0; $fixtures = [];
final class HostingReleaseProbe {
    public static array $events = [];
    public static function record(string $event, string $path): void { self::$events[] = [$event, $path]; }
}
function eventIndex(array $events, string $event, string $path, int $after = -1): int {
    foreach ($events as $i => $row) if ($i > $after && $row === [$event, $path]) return $i;
    throw new RuntimeException('Missing ordered event '.$event.':'.$path);
}
function expect(bool $ok, string $why): void { global $checks; $checks++; if (! $ok) throw new RuntimeException($why); }
function dirFixture(string $path): void { if (! is_dir($path)) mkdir($path, 0700, true); chmod($path, 0700); }
function putFixture(string $path, string $bytes, int $mode = 0644): void { dirFixture(dirname($path)); file_put_contents($path, $bytes); chmod($path, $mode); }
function fixture(bool $reversePayload = false): array {
    global $fixtures;
    $id = bin2hex(random_bytes(6)); $root = sys_get_temp_dir().'/hosting-release-native-'.$id; $fixtures[] = $root;
    $home = $root.'/home'; $stage = $home.'/hosting-domain-release-20261102'; dirFixture($stage);
    $uid = fileowner($home);
    $namespace = 'ServerNet\\HostingFixture'.$id;
    $source = file_get_contents(__DIR__.'/engine.php');
    // Root-only sandbox cannot setuid; permit uid 0 in this throwaway copy, never in the production engine.
    if ($uid === 0) $source = str_replace('if ($this->uid < 1 ||', 'if ($this->uid < 0 ||', $source);
    $source = str_replace('namespace ServerNet\\HostingDomainRelease20261102;', 'namespace '.$namespace.';', $source);
    $source = str_replace("'/home/servernetcloud'", var_export($home, true), $source);
    $source = str_replace('/home/servernetcloud/servernet_app/', $home.'/servernet_app/', $source);
    $source = str_replace("private const PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';", 'private const PHP = '.var_export(PHP_BINARY, true).';', $source);
    // Observe only the throwaway engine copy, without introducing production test hooks.
    $source = str_replace("private function opcache(array \$paths): void\n    {", "private function opcache(array \$paths): void\n    {\n        foreach (\$paths as \$probePath) \\HostingReleaseProbe::record('activate', \$probePath);", $source);
    $source = str_replace("        \$tmp = dirname(\$path).", "        \\HostingReleaseProbe::record('atomic', \$path);\n        \$tmp = dirname(\$path).", $source);
    $source = str_replace("    private function restoreOne(string \$path, string \$backup, string \$before, ?int \$mode, string \$expected, ?int \$expectedMode): void\n    {", "    private function restoreOne(string \$path, string \$backup, string \$before, ?int \$mode, string \$expected, ?int \$expectedMode): void\n    {\n        \\HostingReleaseProbe::record('restore', \$path);", $source);
    putFixture($stage.'/engine.php', $source, 0600); require $stage.'/engine.php';
    $class = $namespace.'\\Engine'; $reflection = new ReflectionClass($class);
    $allowed = $reflection->getConstant('ALLOWED'); $views = $reflection->getConstant('VIEWS');
    $compiledRoot = $home.'/servernet_app/storage/framework/views'; dirFixture($compiledRoot);
    $compiler = new class($compiledRoot) {
        public function __construct(private string $root) {}
        public function getCompiledPath(string $path): string { return $this->root.'/'.sha1($path).'.php'; }
        public function setPath(string $path): void {}
        public function compileString(string $source): string { return $source; }
    };
    $app = new class($compiler) {
        public bool $cached = false;
        public function __construct(private object $compiler) {}
        public function make(string $name): object { if ($name !== 'blade.compiler') throw new RuntimeException('unexpected service'); return $this->compiler; }
        public function configurationIsCached(): bool { return $this->cached; }
        public function routesAreCached(): bool { return false; }
    };
    putFixture($home.'/servernet_app/vendor/autoload.php', '<?php // synthetic autoload');
    putFixture($home.'/servernet_app/bootstrap/app.php', <<<'PHP'
<?php
return new class {
    public function configurationIsCached(): bool { return false; }
    public function routesAreCached(): bool { return false; }
    public function make(string $name): object {
        return new class {
            public function bootstrap(): void {}
            public function getRoutes(): array { return [new class {
                public function getName(): string { return 'account.services.domain-connection'; }
                public function getActionName(): string { return 'App\\Http\\Controllers\\Account\\HostingDomainConnectionController'; }
                public function methods(): array { return ['GET']; }
            }]; }
        };
    }
};
PHP);
    $entries = [];
    foreach ($allowed as $i => $relative) {
        dirFixture(dirname($home.'/'.$relative));
        $view = in_array($relative, $views, true); $before = $relative === $views[1] ? '<section>Old hosting card</section>'
            : ($relative === 'servernet_app/app/Http/Controllers/Account/StoreController.php' ? '<?php // Old private controller' : null);
        $after = $view ? '<section>New hosting partial '.$i.'</section>' : (str_ends_with($relative, '.php') ? '<?php return true;' : '/* synthetic asset */');
        if ($before !== null) {
            putFixture($home.'/'.$relative, $before, 0600);
            if ($view) putFixture($compiler->getCompiledPath($home.'/'.$relative), $before."\n<?php /**PATH {$home}/{$relative} ENDPATH**/ ?>", 0755);
        }
        $entries[] = ['destination' => $relative, 'before_sha256' => $before === null ? 'absent' : hash('sha256', $before), 'after_sha256' => hash('sha256', $after), 'before_mode' => $before === null ? null : 0600, 'mode' => $before === null ? 0644 : 0600, 'content' => $after];
    }
    $protected = 'servernet_app/app/Models/Service.php'; putFixture($home.'/'.$protected, '<?php // untouched');
    if ($reversePayload) $entries = array_reverse($entries);
    $plan = ['release' => 'hosting-domain-20261102', 'source_sha' => str_repeat('a', 40), 'account_uid' => $uid, 'entries' => $entries, 'protected' => [['destination' => $protected, 'sha256' => hash_file('sha256', $home.'/'.$protected)]]];
    $json = json_encode($plan, JSON_THROW_ON_ERROR); putFixture($stage.'/payload.json', $json, 0600);
    return compact('home','stage','class','reflection','app','plan','compiler') + ['pin' => hash('sha256', $json)];
}
function phase(array $f, string $phase): array { return $f['class']::run($f['app'], $phase, $f['pin']); }
function successful(array $f, string $phase, string $state): array { $out = phase($f, $phase); expect(($out['ok'] ?? false) && ($out['state'] ?? null) === $state, $phase.': '.json_encode($out)); return $out; }
try {
    $f = fixture(true);
    expect(($f['class']::inspect($f['pin'])['ok'] ?? false), 'inventory');
    expect(count($f['class']::inspect($f['pin'])['files']) === 14, 'fourteen exact files');
    $f['app']->cached = true;
    expect((phase($f, 'preview')['reason'] ?? '') === 'cached_application_requires_separate_plan', 'cached configuration stops release');
    $f['app']->cached = false;
    successful($f, 'preview', 'previewed');
    expect(! file_exists($f['home'].'/public_html/assets/js/hosting-connection.js'), 'preview does not install');
    HostingReleaseProbe::$events = [];
    successful($f, 'apply', 'applied');
    $cardSource = $f['home'].'/servernet_app/resources/views/account/partials/card-hosting.blade.php';
    $partialSource = $f['home'].'/servernet_app/resources/views/account/partials/hosting-domain-connection.blade.php';
    $cardCompiled = $f['compiler']->getCompiledPath($cardSource);
    $partialCompiled = $f['compiler']->getCompiledPath($partialSource);
    $events = HostingReleaseProbe::$events;
    expect(eventIndex($events, 'atomic', $partialSource) < eventIndex($events, 'atomic', $cardSource), 'apply installs partial source before card despite reversed payload');
    expect(eventIndex($events, 'atomic', $partialCompiled) < eventIndex($events, 'atomic', $cardCompiled), 'apply installs partial compiled before card despite reversed payload');
    expect((phase($f, 'apply')['reason'] ?? '') === 'duplicate_or_unprepared_apply', 'duplicate apply rejected');
    successful($f, 'verify', 'verified');
    foreach ($f['plan']['entries'] as $entry) expect(hash_file('sha256', $f['home'].'/'.$entry['destination']) === $entry['after_sha256'], 'exact after hash');
    $cardPath = $f['home'].'/servernet_app/resources/views/account/partials/card-hosting.blade.php';
    expect((fileperms($cardPath) & 0777) === 0600, 'private card mode retained');
    $storePath = $f['home'].'/servernet_app/app/Http/Controllers/Account/StoreController.php';
    expect((fileperms($storePath) & 0777) === 0600, 'private StoreController mode retained during install');
    HostingReleaseProbe::$events = [];
    successful($f, 'rollback', 'rolled_back');
    $events = HostingReleaseProbe::$events;
    $cardSourceRestore = eventIndex($events, 'restore', $cardSource);
    $cardCompiledRestore = eventIndex($events, 'restore', $cardCompiled);
    $cardSourceActivation = eventIndex($events, 'activate', $cardSource, $cardCompiledRestore);
    $cardCompiledActivation = eventIndex($events, 'activate', $cardCompiled, $cardCompiledRestore);
    $partialSourceRestore = eventIndex($events, 'restore', $partialSource);
    $partialCompiledRestore = eventIndex($events, 'restore', $partialCompiled);
    expect($cardSourceRestore < $cardCompiledRestore && $cardCompiledRestore < $cardSourceActivation && $cardCompiledRestore < $cardCompiledActivation, 'rollback restores both old card representations then invalidates them');
    expect($cardSourceActivation < $partialSourceRestore && $cardCompiledActivation < $partialSourceRestore && $partialSourceRestore < $partialCompiledRestore, 'rollback activates old card before deleting new partial source or compiled');
    expect((fileperms($cardPath) & 0777) === 0600 && file_get_contents($cardPath) === '<section>Old hosting card</section>', 'old source restored');
    expect(! file_exists($f['home'].'/public_html/assets/js/hosting-connection.js'), 'new asset removed on explicit rollback');
    expect((fileperms($storePath) & 0777) === 0600 && file_get_contents($storePath) === '<?php // Old private controller', 'private StoreController bytes and mode restored');
    $g = fixture(); successful($g, 'preview', 'previewed');
    $target = $g['home'].'/servernet_app/resources/views/account/partials/card-hosting.blade.php'; file_put_contents($target, 'newer unrelated edit');
    expect((phase($g, 'apply')['reason'] ?? '') === 'destination_hash_drift', 'concurrent source edit rejected');
    expect(file_get_contents($target) === 'newer unrelated edit', 'newer edit preserved');
    $r = fixture(); successful($r, 'preview', 'previewed');
    $bootstrap = $r['home'].'/servernet_app/bootstrap/app.php';
    file_put_contents($bootstrap, str_replace('account.services.domain-connection', 'missing.route', file_get_contents($bootstrap)));
    expect((phase($r, 'apply')['reason'] ?? '') === 'apply_failed_rolled_back', 'fresh missing route triggers guarded rollback');
    expect(file_get_contents($r['home'].'/servernet_app/resources/views/account/partials/card-hosting.blade.php') === '<section>Old hosting card</section>', 'route failure restored prior card');
    expect(! file_exists($r['home'].'/public_html/assets/js/hosting-connection.js'), 'route failure removed only release additions');
    $h = fixture(); $new = $h['home'].'/public_html/assets/js/hosting-connection.js';
    $outside = dirname($h['home']).'/outside'; putFixture($outside, 'preserve outside'); symlink($outside, $new);
    expect((phase($h, 'preview')['reason'] ?? '') === 'symlink_rejected', 'symlink rejected');
    expect(file_get_contents($outside) === 'preserve outside', 'symlink outside untouched');
    expect((phase($h, 'shell')['reason'] ?? '') === 'invalid_phase', 'unlisted phase rejected');
    expect(! ($h['class']::inspect('arbitrary')['ok'] ?? true), 'unpinned payload rejected');
    echo json_encode(['passed' => true, 'checks' => $checks, 'synthetic_only' => true, 'real_blade_compiler' => false, 'production_paths_used' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (array_reverse($fixtures) as $root) {
        if (! str_starts_with($root, sys_get_temp_dir().'/hosting-release-native-')) throw new RuntimeException('unsafe fixture cleanup');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $p) { if ($p->isDir() && ! $p->isLink()) rmdir($p->getPathname()); else unlink($p->getPathname()); }
        rmdir($root);
    }
}
