<?php
// Narrow catalog-only operation. No server, invoice, wallet, plan or price writes.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Services\Cloud\ArvanApplicationImages;
use Illuminate\Support\Facades\DB;
function scope() { return CloudImage::query()->where('provider','arvan')->where('provider_ref','like','app:%'); }
function snapshot(): array { return scope()->orderBy('id')->get()->map(fn($r)=>$r->getAttributes())->all(); }
function savePrivate(string $name, array $data): void {
    if (file_exists(__DIR__.'/'.$name)) { throw new RuntimeException('Receipt already exists: '.$name); }
    file_put_contents(__DIR__.'/'.$name,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    chmod(__DIR__.'/'.$name,0600);
}
$mode=$argv[1]??'';
if ($mode==='--prepare') {
    if (file_exists(__DIR__.'/catalog-before.json') || file_exists(__DIR__.'/catalog-applied.json')) { throw new RuntimeException('Already prepared'); }
    $before=snapshot();
    // First publication only; never replace prior owner-managed app rows.
    if ($before!==[]) { throw new RuntimeException('Unexpected existing regional app catalog'); }
    $driver=app(App\Services\Cloud\ArvanClient::class);
    $fetch=new ReflectionMethod($driver,'regionApplications');
    $resolve=new ReflectionMethod($driver,'imageForRegion');
    $regions=CloudPlan::query()->sellable()->where('provider','arvan')->distinct()->pluck('provider_location')->sort()->values()->all();
    if (count($regions)!==5) { throw new RuntimeException('Regional inventory changed; review first'); }
    $rows=[];$summary=[];
    foreach ($regions as $region) {
        $images=$fetch->invoke($driver,$region);$keys=[];
        foreach ($images as $image) {
            if (isset($keys[$image['key']])) { continue; }
            $keys[$image['key']]=true;
            if (! str_starts_with($image['key'],'app-ir-') || ArvanApplicationImages::imageId($region,$image['provider_ref'])===null) { throw new RuntimeException('Invalid image'); }
            $rows[]=$image;
        }
        if (count($keys)<6) { throw new RuntimeException('Incomplete free application inventory'); }
        $first=$images[0];
        if ($resolve->invoke($driver,$region,$first['provider_ref'])!==ArvanApplicationImages::imageId($region,$first['provider_ref'])) { throw new RuntimeException('Native ID resolution failed'); }
        $summary[$region]=count($keys);
    }
    try { $resolve->invoke($driver,'unrelated-region',$rows[0]['provider_ref']); throw new LogicException('Cross-region accepted'); }
    catch (UnexpectedValueException) { /* expected, no billable call */ }
    savePrivate('catalog-before.json',$before);savePrivate('catalog-candidate.json',$rows);
    echo 'CATALOG_PREPARED '.json_encode($summary).' total='.count($rows)." resolver_get_checks=pass\n";
    exit;
}
if ($mode==='--rollback') {
    $receipt=json_decode(file_get_contents(__DIR__.'/catalog-applied.json'),true,512,JSON_THROW_ON_ERROR);
    DB::transaction(function() use($receipt) {
        scope()->lockForUpdate()->get();
        if (hash('sha256',json_encode(snapshot()))!==$receipt['after_hash']) { throw new RuntimeException('Catalog changed since publication; review rollback'); }
        // Retain references in case an order was placed after publication.
        scope()->whereIn('id',$receipt['ids'])->update(['is_active'=>false]);
    });
    savePrivate('catalog-rolled-back.json',['deactivated'=>$receipt['ids']]);echo "CATALOG_DEACTIVATED\n";exit;
}
if ($mode==='--apply') {
    if (file_exists(__DIR__.'/catalog-applied.json')) { throw new RuntimeException('Already applied'); }
    $before=json_decode(file_get_contents(__DIR__.'/catalog-before.json'),true,512,JSON_THROW_ON_ERROR);
    $rows=json_decode(file_get_contents(__DIR__.'/catalog-candidate.json'),true,512,JSON_THROW_ON_ERROR);
    $ids=DB::transaction(function() use($before,$rows) {
        scope()->lockForUpdate()->get();
        if (snapshot()!==$before) { throw new RuntimeException('Concurrent catalog change'); }
        $ids=[];
        foreach($rows as $r) {
            $image=CloudImage::create(['provider'=>'arvan','is_active'=>true]+$r);
            $ids[]=$image->id;
        }
        return $ids;
    });
    savePrivate('catalog-applied.json',['ids'=>$ids,'after_hash'=>hash('sha256',json_encode(snapshot()))]);
    echo 'CATALOG_APPLIED '.count($ids)." rows\n";
}
// Read-only public-key compatibility checks after apply, or standalone --verify.
if (in_array($mode,['--verify','--apply'],true)) {
    $checked=0;
    foreach (CloudPlan::query()->sellable()->where('provider','arvan')->get()->groupBy('provider_location') as $region=>$plans) {
        $low=$plans->sortBy('ram_mb')->first();$high=$plans->sortByDesc('ram_mb')->first();
        foreach ([$low,$high] as $plan) {
            $keys=App\Http\Controllers\Account\CloudStoreController::imageKeysFor($plan,'app');$valid=0;
            foreach($keys as $key) {
                if (! str_starts_with($key,'app-ir-')) { continue; }
                $ref=CloudImage::compatibleRefFor($plan,$key);
                if ($ref===null || ArvanApplicationImages::imageId($region,$ref)===null) { throw new RuntimeException('Visible app cannot be delivered'); }
                $valid++;$checked++;
            }
            echo 'COMPAT '.json_encode(['region'=>$region,'cpu'=>$plan->vcpu,'ram'=>$plan->ram_mb,'disk'=>$plan->disk_gb,'apps'=>$valid]).PHP_EOL;
        }
    }
    echo 'CATALOG_VERIFIED '.$checked." compatible selections; no server created\n";
}
