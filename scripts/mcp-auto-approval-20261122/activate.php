<?php
declare(strict_types=1);
header('Content-Type: application/json');
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== 'mcp-auto-approval-20261122'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$records = json_decode((string) file_get_contents(getenv('SERVERNET_MCP_PLAN')), true, 32, JSON_THROW_ON_ERROR);
$paths = ['app/Http/Controllers/Mcp/AdminController.php', 'app/Mcp/AdminInfrastructureServer.php', 'app/Mcp/Tools/ExecuteSshCommand.php', 'app/Mcp/Tools/RequestSshCommand.php', 'app/Services/Mcp/AdminAccess.php', 'app/Services/Mcp/CommandApprovals.php', 'lang/en/mcp_admin.php', 'lang/fa/mcp_admin.php', 'lang/tr/mcp_admin.php', 'resources/views/mcp-admin/action.blade.php', 'resources/views/mcp-admin/index.blade.php', 'routes/mcp-admin.php'];
if (array_column($records, 'path') !== $paths) { throw new RuntimeException('invalid_scoped_plan'); }
foreach ($records as $row) {
    $path = $root.'/'.$row['path'];
    if (is_link($path) || hash_file('sha256', $path) !== $row['after']) {
        throw new RuntimeException('installed_source_changed');
    }
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
        && (! function_exists('opcache_invalidate') || ! opcache_invalidate($path, true))) {
        throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['mcp-admin.index', 'mcp-admin.action'] as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) { opcache_invalidate($compiled, true); }
}
if ($app->routesAreCached()) {
    if (Illuminate\Support\Facades\Artisan::call('route:cache') !== 0) { throw new RuntimeException('route_cache_failed'); }
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)) { opcache_invalidate($app->getCachedRoutesPath(), true); }
}
$owner = App\Models\User::find((int) App\Models\Setting::get('mcp_admin_owner_user_id', '0'));
$automatic = App\Services\Mcp\AdminAccess::autoApproveCommands($owner);
$version = (new ReflectionClass(App\Mcp\AdminInfrastructureServer::class))->getDefaultProperties()['version'];
if (! $automatic || $version !== '1.2.0') { throw new RuntimeException('automatic_policy_not_active'); }
echo json_encode(['release' => 'mcp-auto-approval-20261122', 'version' => $version,
    'automatic_approval' => $automatic, 'invalidated' => count($paths),
    'general_cache_flush' => false], JSON_THROW_ON_ERROR);
