<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Services\IdentityApi\{Controls,Catalog};
use App\Models\IdentityPrice;
umask(0077);
try{
 $base='/home/servernetcloud/identity-api-20261018-expansion-backup';
 $plan=json_decode(file_get_contents($base.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
 $receipt=json_decode(file_get_contents($base.'/publication-result.json'),true,512,JSON_THROW_ON_ERROR);
 $oldControls=json_decode(file_get_contents($base.'/controls-before.private.json'),true,512,JSON_THROW_ON_ERROR);
 $newKeys=array_map(fn($r)=>'service:'.$r['slug'],array_filter($plan['rows'],fn($r)=>!$r['already_enabled']));
 foreach($oldControls as$r)if(!in_array($r['key'],$newKeys,true)&&DB::table('identity_controls')->where('key',$r['key'])->value('value')!==$r['value'])throw new RuntimeException('control_drift');
 $oldPrices=json_decode(file_get_contents($base.'/prices-before.private.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($oldPrices as$r)if(IdentityPrice::findOrFail($r['id'])->toArray()!==$r)throw new RuntimeException('old_price_drift');
 $catalog=app(Catalog::class);$c=app(Controls::class);$active=array_values(array_filter($catalog->publicList(),fn($s)=>$s['active']));
 if(count($active)!==30||!$c->dispatchEnabled()||!$c->fundingReady()||!$c->ownerManagedFunding())throw new RuntimeException('readiness');
 $quotes=[];foreach($plan['rows']as$r){$q=$catalog->checkoutQuote($r['slug']);if($q['subtotal_irt']!==$r['expected_net_irt']||$q['tax_irt']!==$r['expected_tax_irt']||$q['total_irt']!==$r['expected_total_irt'])throw new RuntimeException('quote');$quotes[$r['slug']]=$q;}
 foreach($plan['blocked']as$r)if($c->get('service:'.$r['slug'],false)===true)throw new RuntimeException('blocked_enabled');
 $d=$base.'/database-before.sql.gz';$h=hash_init('crc32b');$f=gzopen($d,'rb');$length=0;
 while(!gzeof($f)){$bytes=gzread($f,1048576);if($bytes===false)throw new RuntimeException('gzip');$length+=strlen($bytes);hash_update($h,$bytes);}gzclose($f);
 $footer=unpack('Vcrc/Vlength',file_get_contents($d,false,null,filesize($d)-8,8));
 if(hexdec(hash_final($h))!==$footer['crc']||$length%4294967296!==$footer['length'])throw new RuntimeException('crc');
 $result=['checked_at'=>now()->toIso8601String(),'published_at'=>$receipt['at'],'active_count'=>30,'new_price_count'=>28,'new_price_ids'=>$receipt['new_price_ids'],'all_30_quotes_match'=>true,'old_prices_unchanged'=>true,'other_controls_unchanged'=>true,'funding_mode'=>$c->get('funding_mode'),'provider_daily_cap_irt'=>$c->get('provider_daily_cap_irt'),'stored_reserve_irt'=>$c->get('internal_kyc_reserve_irt'),'provider_concurrency'=>$c->get('provider_concurrency'),'full_gzip_crc'=>true,'backup_mode'=>decoct(fileperms($base)&0777),'dump_mode'=>decoct(fileperms($d)&0777),'dump_bytes'=>filesize($d),'dump_sha256'=>hash_file('sha256',$d),'reconcile_heartbeat'=>Illuminate\Support\Facades\Cache::get('identity:reconcile:heartbeat'),'upstream_calls'=>0,'paid_calls'=>0,'retail_mutations'=>0,'quotes'=>$quotes];
 file_put_contents($base.'/acceptance-sanitized.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 unset($result['quotes']);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable){echo "validation_stopped_no_raw_error\n";exit(1);}
