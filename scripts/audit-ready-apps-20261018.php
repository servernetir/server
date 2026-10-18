<?php
// Read-only inventory; no customer rows, credentials, provisioning, or catalog sync.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::class;
$counts = $db::table('cloud_images')->select('provider', 'kind', 'is_active')
    ->selectRaw('COUNT(*) AS n')->groupBy('provider', 'kind', 'is_active')->orderBy('provider')->get();
$plans = App\Models\CloudPlan::query()->sellable()->select('provider')->selectRaw('COUNT(*) AS n')
    ->groupBy('provider')->get();
$apps = $db::table('cloud_images')->where('kind', 'app')->orderBy('provider')->orderBy('label')
    ->get(['provider','key','label','family','version','arch','is_active','min_ram_mb','min_disk_gb']);
$configured = array_keys(app(App\Services\Cloud\CloudManager::class)->configured());
$result = ['image_counts'=>$counts, 'sellable_plans'=>$plans, 'configured'=>$configured,
    'app_count'=>$apps->count(), 'app_sample'=>$apps->take(20)->all()];
file_put_contents(__DIR__.'/inventory.json', json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
chmod(__DIR__.'/inventory.json',0600);
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
