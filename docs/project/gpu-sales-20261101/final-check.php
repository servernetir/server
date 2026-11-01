<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$live='/home/servernetcloud/servernet_app';
require $live.'/vendor/autoload.php';
$app=require $live.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=app(App\Services\Cloud\VpsTcApi::class)->balance();
$code=(string)($r['code']??'');
echo json_encode(['vpstc_ok'=>$r['ok'],'vpstc_code'=>$code],JSON_THROW_ON_ERROR).PHP_EOL;
if(! $r['ok'] && (preg_match('/^upstream_http_5[0-9]{2}$/D',$code)
    || in_array($code,['connection_failed','authentication_failed','rate_limited','access_denied'],true))) {
    app(App\Services\Cloud\CloudPricingSnapshot::class)->excludeProvider('vpstc',$code);
}
Illuminate\Support\Facades\Artisan::call('cloud:watch-sales');
echo Illuminate\Support\Facades\Artisan::output();
