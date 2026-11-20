<?php
declare(strict_types=1);

// Authorized scope: legal copy, registration consent and one refund-policy KB article.
// Run --prepare COMMIT, inspect manifest.json, then --apply COMMIT. No migration,
// payment, customer acceptance, notification or infrastructure action is executed.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Unexpected application root.');
}
umask(0077);
$mode = $argv[1] ?? '';
$commit = $argv[2] ?? '';
if (! in_array($mode, ['--prepare', '--apply'], true) || ! preg_match('/^[a-f0-9]{40}$/', $commit)) {
    throw new RuntimeException('Expected mode and pinned commit.');
}
$root = getcwd();
$dir = '/home/servernetcloud/deploy-backups/legal-credit-consent-20261120';
if (! is_dir($dir)) { mkdir($dir, 0700, true); }
if (is_link($dir) || realpath($dir) !== $dir) { throw new RuntimeException('Unsafe release directory.'); }
$lock = fopen($root.'/storage/framework/legal-credit-release.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release busy.'); }

function fetchSource(string $commit, string $path): string
{
    $curl = curl_init('https://raw.githubusercontent.com/servernetir/server/'.$commit.'/'.$path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status !== 200 || ! is_string($body)) { throw new RuntimeException('Source download failed: '.$path); }
    return $body;
}
function savePrivate(string $path, string $bytes): void
{
    if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Private write failed.'); }
    chmod($path, 0600);
}

if ($mode === '--prepare') {
    if (file_exists($dir.'/manifest.json')) { throw new RuntimeException('Existing packet: inspect before reusing.'); }
    $replacements = json_decode(fetchSource($commit, 'scripts/legal-policy-20261120-replacements.json'), true, 512, JSON_THROW_ON_ERROR);
    $full = [
        'app/Http/Controllers/Auth/RegisterController.php' => '619da9a8cfbafca55df7e98c024ac0c6e8cd7a88f1ea56474cb5f415eb8625a9',
        'resources/views/auth/register/finish.blade.php' => '77cc9ee37645300bbc84dae05815def0cdc5e7294f86e78000dd2e7aed910afb',
        'database/seeders/LegalDocumentSeeder.php' => 'd81746797a89921d2df5b0a69682797caefcae68ca0fb41163ab03085e5d4275',
        'app/Services/Legal/LegalDocuments.php' => null,
    ];
    // The start view is patched in place so unrelated live Google-flow edits survive.
    $replacements['resources/views/auth/register/start.blade.php'] = [[
        '  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ session(\'google_registration\') && !$iranian ? __(\'google.continue_registration\') : __(\'ui.auth_send_code\') }}</span></button>',
        '  <p class="auth-legal-notice" style="font-size:13px;line-height:1.9">{{ __(\'ui.auth_credit_policy\') }} <a href="{{ lroute(\'terms\') }}" target="_blank" rel="noopener">{{ __(\'ui.auth_terms\') }}</a></p>'."\n".
        '  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ session(\'google_registration\') && !$iranian ? __(\'google.continue_registration\') : __(\'ui.auth_send_code\') }}</span></button>',
    ]];
    $files = [];
    foreach (array_unique(array_merge(array_keys($replacements), array_keys($full))) as $path) {
        $file = $root.'/'.$path;
        if (is_link($file)) { throw new RuntimeException('Symlink destination: '.$path); }
        $before = is_file($file) ? file_get_contents($file) : null;
        if (array_key_exists($path, $full)) {
            $expected = $full[$path];
            if (($before === null ? null : hash('sha256', $before)) !== $expected) {
                throw new RuntimeException('Live source drift: '.$path);
            }
            $after = fetchSource($commit, 'website/'.$path);
        } else {
            if (! is_string($before)) { throw new RuntimeException('Missing patch destination.'); }
            $after = $before;
            foreach ($replacements[$path] as [$old, $new]) {
                if (substr_count($after, $old) !== 1) { throw new RuntimeException('Patch context drift: '.$path); }
                $after = str_replace($old, $new, $after);
            }
        }
        $key = hash('sha256', $path);
        if ($before !== null) { savePrivate($dir.'/'.$key.'.before', $before); }
        savePrivate($dir.'/'.$key.'.after', $after);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($dir.'/'.$key.'.after').' 2>&1', $lint, $code);
        if ($code !== 0) { throw new RuntimeException('Stage syntax failed: '.$path); }
        $files[] = ['path' => $path, 'key' => $key, 'before' => $before === null ? null : hash('sha256', $before), 'after' => hash('sha256', $after)];
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $post = Illuminate\Support\Facades\DB::table('posts')->where('slug', 'refund-policy-explained')->where('type', 'kb')->first();
    if (! $post) { throw new RuntimeException('Refund article missing.'); }
    $translations = Illuminate\Support\Facades\DB::table('post_translations')->where('post_id', $post->id)->get();
    if ($translations->pluck('locale')->sort()->values()->all() !== ['en', 'fa', 'tr']) {
        throw new RuntimeException('Unexpected article translations.');
    }
    savePrivate($dir.'/article-before.json', json_encode(['post' => $post, 'translations' => $translations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    savePrivate($dir.'/article-after.json', fetchSource($commit, 'scripts/legal-policy-20261120-article.json'));
    savePrivate($dir.'/manifest.json', json_encode(['commit' => $commit, 'files' => $files], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo 'PREPARED '.count($files).' files; 3 article translations; no live changes'.PHP_EOL;
    foreach ($files as $file) { echo $file['path'].' '.substr($file['after'], 0, 12).PHP_EOL; }
    exit;
}

if (file_exists($dir.'/applied.json')) { throw new RuntimeException('Already applied: inspect receipt.'); }
$manifest = json_decode(file_get_contents($dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['commit'] !== $commit) { throw new RuntimeException('Commit mismatch.'); }
foreach ($manifest['files'] as $file) {
    $path = $root.'/'.$file['path'];
    $current = is_file($path) ? hash_file('sha256', $path) : null;
    if (is_link($path) || $current !== $file['before'] || hash_file('sha256', $dir.'/'.$file['key'].'.after') !== $file['after']) {
        throw new RuntimeException('Concurrent file change: '.$file['path']);
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::class;
$oldDocuments = $db::table('legal_documents')->orderBy('id')->get();
savePrivate($dir.'/legal-documents-before.json', $oldDocuments->toJson(JSON_UNESCAPED_UNICODE));
$articleBefore = json_decode(file_get_contents($dir.'/article-before.json'), true, 512, JSON_THROW_ON_ERROR);
$articleAfter = json_decode(file_get_contents($dir.'/article-after.json'), true, 512, JSON_THROW_ON_ERROR);
$installed = [];
try {
    $db::transaction(function () use ($root, $dir, $manifest, $db, $articleBefore, $articleAfter, &$installed) {
        foreach ($articleBefore['translations'] as $old) {
            $row = $db::table('post_translations')->where('id', $old['id'])->lockForUpdate()->first();
            if (! $row || $row->content !== $old['content'] || $row->excerpt !== $old['excerpt'] || $row->updated_at !== $old['updated_at']) {
                throw new RuntimeException('Concurrent article edit.');
            }
        }
        foreach ($manifest['files'] as $file) {
            $path = $root.'/'.$file['path'];
            if (! is_dir(dirname($path))) { mkdir(dirname($path), 0755, true); }
            $temp = $path.'.legal-release-'.getmypid();
            $bytes = file_get_contents($dir.'/'.$file['key'].'.after');
            savePrivate($temp, $bytes);
            chmod($temp, 0644);
            if (! rename($temp, $path)) { throw new RuntimeException('Install failed.'); }
            $installed[] = $file;
        }
        // Explicitly reload only the two changed config arrays in this CLI process.
        config(['pages' => require $root.'/config/pages.php', 'hosting' => require $root.'/config/hosting.php']);
        foreach (['fa', 'en', 'tr'] as $locale) {
            app(App\Services\Legal\LegalDocuments::class)->publishCurrent($locale);
        }
        foreach ($articleBefore['translations'] as $old) {
            $new = $articleAfter[$old['locale']];
            $db::table('post_translations')->where('id', $old['id'])->update([
                'content' => $new['content'], 'excerpt' => $new['excerpt'], 'updated_at' => now(),
            ]);
        }
        $db::table('posts')->where('id', $articleBefore['post']['id'])->update(['updated_at' => now()]);
    });
} catch (Throwable $error) {
    foreach (array_reverse($installed) as $file) {
        $path = $root.'/'.$file['path'];
        if (hash_file('sha256', $path) !== $file['after']) { continue; }
        if ($file['before'] === null) { unlink($path); }
        else { copy($dir.'/'.$file['key'].'.before', $path); chmod($path, 0644); }
    }
    throw $error;
}
foreach ($manifest['files'] as $file) {
    if (hash_file('sha256', $root.'/'.$file['path']) !== $file['after']) { throw new RuntimeException('Readback failed.'); }
}
foreach ($oldDocuments as $document) {
    if ((array) $document !== (array) $db::table('legal_documents')->find($document->id)) {
        throw new RuntimeException('Historical legal document changed.');
    }
}
foreach (['auth/register/start', 'auth/register/finish'] as $view) {
    app('blade.compiler')->compile($root.'/resources/views/'.$view.'.blade.php');
}
foreach (['fa', 'en', 'tr'] as $locale) {
    Illuminate\Support\Facades\Cache::forget('docs.tree.'.$locale);
}
// Invalidate only the changed public pages, preserving shared locks/cache state.
$gen = (int) Illuminate\Support\Facades\Cache::get('page:gen', 0);
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
    foreach (['', 'en/', 'tr/'] as $prefix) {
        foreach (['terms', 'docs/refund-policy-explained', 'hosting', 'hosting/linux', 'hosting/windows'] as $path) {
            Illuminate\Support\Facades\Cache::forget('page:g'.$gen.':'.sha1($host.'||'.$prefix.$path));
        }
    }
}
savePrivate($dir.'/applied.json', json_encode(['commit' => $commit, 'files' => count($manifest['files']),
    'article_translations' => 3, 'historical_documents_preserved' => $oldDocuments->count(), 'time' => gmdate('c')], JSON_PRETTY_PRINT));
echo 'APPLIED '.count($manifest['files']).' files; 3 translations; historical legal documents preserved'.PHP_EOL;
