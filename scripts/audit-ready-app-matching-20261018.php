<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (App\Models\CloudPlan::query()->sellable()->get()->groupBy('provider') as $provider=>$rows) {
    echo 'PLANS '.$provider.' '.$rows->count().' '.implode(',', $rows->pluck('location_code')->unique()->take(8)->all()).PHP_EOL;
}
foreach (App\Models\CloudPlan::query()->sellable()->whereIn('location_code',['de-falkenstein','ir-urmia'])->orderBy('cost_eur_cents')->get()->groupBy('provider') as $provider=>$rows) {
    $p=$rows->first();
    echo 'PLAN '.json_encode(['provider'=>$provider,'slug'=>$p->slug,'arch'=>$p->arch,'ram'=>$p->ram_mb,'disk'=>$p->disk_gb,'boot'=>$p->boot_disk_gb]).PHP_EOL;
    foreach (App\Models\CloudImage::query()->usable()->where('provider',$provider)->where('kind','app')->limit(5)->get() as $i) {
        echo 'APP '.json_encode(['key'=>$i->key,'label'=>$i->label,'arch'=>$i->arch,'ram'=>$i->min_ram_mb,'disk'=>$i->min_disk_gb,'matches'=>App\Models\CloudImage::compatibleRefFor($p,$i->key)!==null]).PHP_EOL;
    }
}
