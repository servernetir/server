<?php
declare(strict_types=1);

/** Private CLI/FPM probe library; only public blog metadata is read. */
function snetBlogMetadataProbe(string $root, string $expected): array
{
    $target = $root.'/app/Services/BlogRepository.php';
    clearstatcache(true, $target);
    if (is_link($target) || str_replace('\\','/',(string)realpath($target)) !== $target
        || !hash_equals($expected, (string)hash_file('sha256', $target))) {
        throw new RuntimeException('probe_source_drift');
    }
    require_once $root.'/vendor/autoload.php';
    \App\Support\ErrorTracker::useDirectory(__DIR__.'/isolated-tracker');
    $app = require $root.'/bootstrap/app.php';
    $app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function($app): void {
        $app['config']->set(['cache.default'=>'array','session.driver'=>'array','mail.default'=>'array',
            'logging.default'=>'seo_probe_null','logging.channels.seo_probe_null'=>[
                'driver'=>'monolog','handler'=>\Monolog\Handler\NullHandler::class]]);
    });
    $guardSql=static function(string $sql): void {
        if (!preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)
            ||preg_match('/\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|GET_LOCK\s*\(|RELEASE_LOCK\s*\(|SLEEP\s*\(|BENCHMARK\s*\(|LOAD_FILE\s*\()/i',$sql)) {
            throw new RuntimeException('probe_database_write_or_unsafe_read_refused');
        }
    };
    // Resolving guards run before a provider can use either service in register().
    $guardDatabase=static function($database) use($guardSql): void { $database->connection()->beforeExecuting($guardSql); };
    $guardHttp=static function($http): void { $http->preventStrayRequests(); };
    $app->resolving('db',$guardDatabase);
    $app->resolving(\Illuminate\Http\Client\Factory::class,$guardHttp);
    if ($app->resolved('db')) $guardDatabase($app->make('db'));
    if ($app->resolved(\Illuminate\Http\Client\Factory::class)) $guardHttp($app->make(\Illuminate\Http\Client\Factory::class));
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('cache.default') !== 'array') {
        throw new RuntimeException('probe_cache_not_isolated');
    }
    $db = \Illuminate\Support\Facades\DB::connection();
    $results = [];
    foreach (['fa','en','tr'] as $locale) {
        $app->setLocale($locale);
        \Illuminate\Support\Facades\Cache::forget('blog.index.blog.'.$locale);
        $samples = [];
        foreach (['cold','warm'] as $temperature) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            try {
                $rows = $app->make(\App\Services\BlogRepository::class)->index();
                $queries = $db->getQueryLog();
            } finally {
                $db->disableQueryLog();
            }
            if ($rows === []) {
                throw new RuntimeException('probe_no_public_rows');
            }
            $authorQueries = count(array_filter($queries, static fn(array $q): bool =>
                (bool)preg_match('/\bfrom\s+["`]?users\b/i', $q['query'])));
            $samples[$temperature] = ['rows'=>count($rows), 'sql_queries'=>count($queries),
                'author_queries'=>$authorQueries,
                'metadata_sha256'=>hash('sha256', json_encode($rows,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
        }
        if ($samples['warm']['sql_queries'] !== 0
            || $samples['cold']['metadata_sha256'] !== $samples['warm']['metadata_sha256']) {
            throw new RuntimeException('probe_warm_cache_mismatch');
        }
        $results[$locale] = $samples;
    }
    return ['source_sha256'=>$expected, 'locales'=>$results, 'cache'=>'array',
        'external_http_requests'=>0, 'database_mutation'=>false,
        'metadata_plaintext_emitted'=>false, 'sapi'=>PHP_SAPI, 'php'=>PHP_VERSION];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (count($argv) !== 3 || !preg_match('/^[a-f0-9]{64}$/D', $argv[2])) {
            throw new RuntimeException('probe_arguments_invalid');
        }
        echo json_encode(['status'=>'ok', 'probe'=>snetBlogMetadataProbe($argv[1],$argv[2])], JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable $e) {
        echo json_encode(['status'=>'stopped', 'reason'=>$e instanceof RuntimeException
            && preg_match('/^[a-z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'inspect_private_probe_status']), "\n";
        exit(1);
    }
}
