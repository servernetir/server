<?php
// Provider GETs only. No create, reinstall, sync, customer rows, or raw credentials.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$regions=App\Models\CloudPlan::query()->sellable()->where('provider','arvan')
    ->distinct()->pluck('provider_location')->filter()->sort()->values()->all();
$driver=app(App\Services\Cloud\ArvanClient::class);
$method=new ReflectionMethod($driver,'req');
$out=[];
foreach ($regions as $region) {
    $r=$method->invoke($driver,'GET','/ecc/v1/regions/'.rawurlencode($region).'/images/marketplace');
    $rows=[];
    if ($r['ok']??false) foreach (($r['data']??[]) as $row) {
        if (!is_array($row)) continue;
        $rows[]=array_intersect_key($row,array_flip(['id','name','distribution_name','distrubation_name',
          'base_os_distro','base_os_version','disk','ram','ssh_key','ssh_password','image_id',
          'image_name','image_version','image_build_number','image_latest_update','image_size','price','status']));
    }
    $out[$region]=['ok'=>(bool)($r['ok']??false),'status'=>$r['status']??null,'rows'=>$rows];
    echo $region.' '.json_encode(['ok'=>$out[$region]['ok'],'count'=>count($rows)]).PHP_EOL;
}
file_put_contents(__DIR__.'/arvan-marketplace.json',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
chmod(__DIR__.'/arvan-marketplace.json',0600);
echo 'ARVAN_MARKETPLACE_PROBE_SAVED'.PHP_EOL;
