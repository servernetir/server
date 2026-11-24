<?php
declare(strict_types=1);
header('Content-Type: application/json');
$release = 'ai-provider-routing-20261124';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\A/home/servernetcloud/deploy-backups/ai-provider-routing-20261124-[a-f0-9]{12}/prepared\.json\z~', $plan) || is_link($plan)) {
    throw new RuntimeException('invalid_plan');
}
$records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
$allowed = [
    'app/Services/Ai/OpenRouterCatalog.php', 'app/Services/Ai/AiProviderRouting.php',
    'app/Console/Commands/AiSyncCatalog.php', 'app/Http/Controllers/Admin/AiGatewayController.php',
    'app/Http/Controllers/Ai/V1ModelsController.php', 'app/Http/Controllers/AiStorefrontController.php',
    'app/Models/AiProvider.php', 'app/Services/Ai/AiCaller.php', 'app/Services/Ai/AiPayload.php',
    'app/Services/Ai/AiPricing.php', 'app/Services/Ai/AiUsageParser.php', 'app/Services/Ai/DeepInfraCatalog.php',
    'app/Services/Ai/PriceBook.php', 'app/Services/AiProviders/OpenAiCompatibleDriver.php',
    'resources/views/admin/ai/pricing.blade.php', 'resources/views/admin/ai/provider-edit.blade.php',
    'resources/views/admin/ai/providers.blade.php',
];
$expected = array_map(fn ($path) => 'servernet_app/'.$path, $allowed);
$actual = array_column($records, 'destination'); sort($expected); sort($actual);
if ($expected !== $actual) throw new RuntimeException('invalid_scope');
$phase = getenv('SERVERNET_STUDIO_PHASE');
if (!in_array($phase, ['before', 'after'], true)) throw new RuntimeException('invalid_phase');
foreach ($records as $row) {
    $path = '/home/servernetcloud/'.$row['destination'];
    $hash = $phase === 'before' && $row['before'] === null ? $row['after'] : $row[$phase];
    if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) throw new RuntimeException('installed_source_changed');
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)) {
        if (!opcache_invalidate($path, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$views = ['admin.ai.providers', 'admin.ai.provider-edit', 'admin.ai.pricing'];
foreach ($views as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
}
echo json_encode(['release' => $release, 'compiled_views' => count($views), 'phase' => $phase,
    'general_cache_flush' => false, 'openrouter_supported' => class_exists(App\Services\Ai\OpenRouterCatalog::class)], JSON_THROW_ON_ERROR);
