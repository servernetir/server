<?php
declare(strict_types=1);
header('Content-Type: application/json');
$release = 'studio-catalogue-union-20261124';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\A/home/servernetcloud/deploy-backups/studio-catalogue-union-20261124-[a-f0-9]{12}/prepared\.json\z~', $plan)
    || is_link($plan)) throw new RuntimeException('invalid_plan');
$rows = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR)['files'];
$phase = getenv('SERVERNET_STUDIO_PHASE');
if (!in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_phase');
$scope = ['servernet_app/app/Services/Studio/StudioCatalog.php', 'servernet_app/app/Http/Controllers/Ai/V1ModelsController.php'];
$core = array_values(array_filter($rows, fn ($row) => str_starts_with($row['destination'], 'servernet_app/')));
$paths = array_column($core, 'destination'); sort($paths); sort($scope);
if ($paths !== $scope) throw new RuntimeException('invalid_scope');
foreach ($core as $row) {
    $path = '/home/servernetcloud/'.$row['destination'];
    if (is_link($path) || hash_file('sha256', $path) !== $row[$phase]) throw new RuntimeException('source_drift');
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)
        && !opcache_invalidate($path, true)) throw new RuntimeException('invalidation_failed');
}
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$counts = []; $priced = 0;
$models = app(App\Services\Ai\AiProviderRouting::class)->catalogue(
    App\Models\AiModel::with('provider')->where('status', 'active')->get());
foreach ($models as $model) {
    $a = app(App\Services\Ai\AiAvailability::class)->inspect($model);
    if (!$a['ready']) continue;
    $counts[$model->category] = ($counts[$model->category] ?? 0) + 1;
    if (in_array($model->category, ['embedding','rerank'], true)) {
        $entry = App\Services\Studio\StudioCatalog::model($model, $a['quote'], 0, false);
        if (isset($entry['pricing']['input_irt']) && !isset($entry['pricing']['output_irt'])) $priced++;
    }
}
echo json_encode(['release'=>$release, 'phase'=>$phase, 'invalidated'=>count($core),
    'models'=>$counts, 'input_only_priced'=>$priced, 'general_cache_flush'=>false], JSON_THROW_ON_ERROR);
