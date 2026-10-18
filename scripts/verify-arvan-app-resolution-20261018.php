<?php
// Read-only integration check: provider GETs; no create/reinstall/POST.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
if (isset($argv[1])) { require $argv[1]; }
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver=app(App\Services\Cloud\ArvanClient::class);
$fetch=new ReflectionMethod($driver,'regionApplications');
$resolve=new ReflectionMethod($driver,'imageForRegion');
$region=(string) App\Models\CloudPlan::query()->sellable()->where('provider','arvan')->value('provider_location');
$images=$fetch->invoke($driver,$region);
$first=$images[0];$high=['vcpu'=>128,'ram_mb'=>1048576,'disk_gb'=>4096];
if ($resolve->invoke($driver,$region,$first['provider_ref'],$high)!==App\Services\Cloud\ArvanApplicationImages::imageId($region,$first['provider_ref'])) { throw new RuntimeException('Native ID mismatch'); }
$cases=[['other-region',$first['provider_ref'],$high],[$region,$first['provider_ref'],['vcpu'=>1,'ram_mb'=>1,'disk_gb'=>1]]];
foreach ($images as $image) {
    if ($image['family']==='gitlab') { $cases[]=[$region,$image['provider_ref'],array_replace($high,['vcpu'=>1])];break; }
}
foreach($cases as $args) {
    try { $resolve->invokeArgs($driver,$args);throw new LogicException('Invalid regional/resource request accepted'); }
    catch (UnexpectedValueException) { /* Expected, before create intent or POST. */ }
}
echo 'LIVE_RESOLUTION_PASS native_id=pass cross_region=blocked ram_disk=blocked cpu=blocked no_server_created'.PHP_EOL;
