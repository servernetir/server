<?php
// Owner-authorized one-shot data publication. No inquiry, wallet read or token modification.
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app=require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Models\{IdentityPrice,IdentityRequest,IdentityApplication,CustomerApiToken,User};
use App\Services\IdentityApi\{Catalog,Controls,Pricing,Provider};
umask(0077);
try {
 $stage=__DIR__; $backup='/home/servernetcloud/identity-api-20261018-expansion-backup';
 $plan=json_decode(file_get_contents($stage.'/plan.json'),true,512,JSON_THROW_ON_ERROR);
 $lock=fopen('/home/servernetcloud/identity-api-deploy.lock','a');
 if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('busy');
 $c=app(Controls::class);$catalog=app(Catalog::class);$pricing=app(Pricing::class);
 if(!$c->runtimeReady()||!$c->dispatchEnabled()||!$c->ownerManagedFunding()||!$c->fundingReady()||!app(Provider::class)->configured())throw new RuntimeException('not_ready');
 if(count($plan['rows'])!==30||count($plan['blocked'])!==5||$plan['validity_hours']!==24||$plan['source_currency']!=='IRR'||$plan['purchase_tax_bps']!==1000)throw new RuntimeException('plan');
 if(hash_file('sha256',resource_path('identity/catalog.json'))!==$plan['catalog_sha256'])throw new RuntimeException('catalog_drift');
 $m=json_decode(file_get_contents('/home/servernetcloud/identity-api-20261018-owner-funding-backup/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($m['files']as$r)if(hash_file('sha256',base_path($r['path']))!==$r['after'])throw new RuntimeException('runtime_drift');
 foreach(['provider_daily_cap_irt'=>100000,'internal_kyc_reserve_irt'=>200000,'provider_concurrency'=>1]as$k=>$v)if($c->get($k)!==$v)throw new RuntimeException('limits');
 $observed=Illuminate\Support\Carbon::parse($plan['observed_at']);
 if($observed->isFuture()||$observed->lt(now()->subHours(2)))throw new RuntimeException('observation_stale');
 $new=[];$expected=[];$seen=[];
 foreach($plan['rows']as$r){
  $s=$catalog->find($r['slug']);$expected[]=$r['slug'];
  if($s['contract_blocker']||isset($seen[$r['slug']])||$s['catalog_id']!==$r['catalog_id']||$r['published_base_irr']<1||$r['markup_bps']!==500)throw new RuntimeException('unsupported');
  $seen[$r['slug']]=true;
  $tax=intdiv($r['published_base_irr']*1000+9999,10000);
  if($r['purchase_tax_amount']!==$tax||$r['source_amount']!==$r['published_base_irr']+$tax)throw new RuntimeException('source_cost');
  if(!$r['already_enabled'])$new[]=$r;
 }
 if(count($new)!==28||count($seen)!==30)throw new RuntimeException('selection');
 $actorId=DB::table('identity_audits')->where('action','owner_managed_funding')->orderByDesc('id')->value('actor_id');
 $actor=User::whereKey($actorId)->where('role','admin')->firstOrFail();
 $guard=function()use($c,$catalog,$new){
  if((int)IdentityPrice::max('id')!==4||IdentityRequest::holding()->exists())throw new RuntimeException('state_drift');
  foreach($catalog->all()as$s)if($c->get('service:'.$s['slug'],false)!==in_array($s['slug'],['shahkar-inquiry','id-inquiry'],true))throw new RuntimeException('flag_drift');
  foreach($new as$r)if(IdentityPrice::where('service',$r['slug'])->exists())throw new RuntimeException('price_exists');
 };
 $guard();
 foreach($new as$r){
  $p=new IdentityPrice(['source_amount'=>$r['source_amount'],'source_currency'=>'IRR','purchase_tax_amount'=>$r['purchase_tax_amount'],'purchase_tax_included'=>true,'confirmed_zero_cost'=>false,'markup_bps'=>500,'daily_quota'=>1,'billing_policy'=>'valid_data_v1','evidence'=>$plan['reference'],'access_evidence'=>'owner-token-all-services-20261018','effective_at'=>$observed,'valid_until'=>$observed->copy()->addHours(24)]);
  $q=$pricing->quote($p);
  if($q['sell_irt']!==$r['expected_net_irt']||$q['tax_irt']!==$r['expected_tax_irt']||$q['total_irt']!==$r['expected_total_irt'])throw new RuntimeException('quote_drift');
 }
 if(($argv[1]??'')!=='--apply'){
  echo json_encode(['preflight'=>'ok','create_prices'=>28,'enable_services'=>28,'active_after'=>30,'retained_services'=>2,'blocked'=>5,'changes_applied'=>false,'upstream_calls'=>0,'paid_calls'=>0],JSON_THROW_ON_ERROR).PHP_EOL;exit;
 }
 if(file_exists($backup)||!mkdir($backup,0700))throw new RuntimeException('backup_exists');
 file_put_contents($backup.'/plan.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 file_put_contents($backup.'/controls-before.private.json',DB::table('identity_controls')->get()->toJson());
 file_put_contents($backup.'/prices-before.private.json',IdentityPrice::orderBy('id')->get()->toJson());
 $process=proc_open(['/opt/cpanel/ea-php84/root/usr/bin/php',$stage.'/identity-db-backup.php',$backup],[0=>['file','/dev/null','r'],1=>['file',$backup.'/backup-receipt.json','w'],2=>['file',$backup.'/errors.private.log','a']],$pipes,base_path());
 if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('backup_failed');
 $process=proc_open(['/usr/bin/gzip','-t',$backup.'/database-before.sql.gz'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$backup.'/errors.private.log','a']],$pipes);
 if(!is_resource($process)||proc_close($process)!==0||(fileperms($backup)&0077)!==0||(fileperms($backup.'/database-before.sql.gz')&0077)!==0)throw new RuntimeException('backup_invalid');
 $guard();
 $controlsHash=fn()=>hash('sha256',DB::table('identity_controls')->whereNotIn('key',array_map(fn($r)=>'service:'.$r['slug'],$new))->orderBy('key')->get(['key','value'])->toJson());
 $beforeHash=$controlsHash();
 $retailCounts=fn()=>[IdentityRequest::count(),IdentityApplication::count(),CustomerApiToken::whereNotNull('identity_application_id')->count(),DB::table('credit_ledger')->count()];
 $countsBefore=$retailCounts();
 $result=DB::transaction(function()use($guard,$c,$catalog,$new,$actor,$observed,$plan,$expected,$controlsHash,$beforeHash,$retailCounts,$countsBefore){
  DB::table('identity_controls')->where('key','provider_mutex')->lockForUpdate()->firstOrFail();$guard();$ids=[];
  foreach($new as$r){
   $p=IdentityPrice::create(['service'=>$r['slug'],'source_amount'=>$r['source_amount'],'source_currency'=>'IRR','purchase_tax_amount'=>$r['purchase_tax_amount'],'purchase_tax_included'=>true,'confirmed_zero_cost'=>false,'markup_bps'=>500,'daily_quota'=>1,'billing_policy'=>'valid_data_v1','evidence'=>$plan['reference'],'access_evidence'=>'owner-token-all-services-20261018','effective_at'=>$observed,'valid_until'=>$observed->copy()->addHours(24),'created_by'=>$actor->id]);
   $ids[]=$p->id;$c->audit($actor->id,'price_version',['id'=>$p->id,'service'=>$p->service,'reference'=>$plan['reference']]);
   $c->put('service:'.$r['slug'],true,$actor->id);
  }
  $c->audit($actor->id,'catalog_publication',['reference'=>$plan['reference'],'source'=>'owner_explicit_instruction','price_versions'=>$ids,'enabled_count'=>28,'upstream_negative_billing_verified'=>false,'per_service_purchase_tax_verified'=>false,'purchase_tax_basis'=>$plan['purchase_tax_basis'],'published_tariff_basis'=>true,'provider_resale_contract_verified'=>false,'token_permissions_changed_by'=>'owner','paid_tests'=>0,'blocked_count'=>5]);
  $active=array_values(array_filter($catalog->publicList(),fn($s)=>$s['active']));$slugs=array_column($active,'slug');sort($slugs);sort($expected);
  if($slugs!==$expected||$controlsHash()!==$beforeHash||$retailCounts()!==$countsBefore)throw new RuntimeException('validation');
  $quotes=[];foreach($plan['rows']as$r){$q=$catalog->checkoutQuote($r['slug']);if($q['subtotal_irt']!==$r['expected_net_irt']||$q['tax_irt']!==$r['expected_tax_irt']||$q['total_irt']!==$r['expected_total_irt'])throw new RuntimeException('quote_validation');$quotes[$r['slug']]=$q;}
  return ['at'=>now()->toIso8601String(),'new_price_ids'=>$ids,'active_count'=>count($active),'active_services'=>$slugs,'quotes'=>$quotes,'blocked'=>$plan['blocked'],'other_controls_unchanged'=>true,'retail_counts_unchanged'=>true,'balance_witness_unchanged'=>true,'upstream_calls'=>0,'paid_calls'=>0,'backup_crc_valid'=>true];
 },3);
 App\Http\Middleware\PageCache::purge();
 file_put_contents($backup.'/publication-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo json_encode(array_diff_key($result,array_flip(['quotes','active_services','blocked'])),JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){echo 'publication_stopped: '.(in_array($e->getMessage(),['busy','not_ready','plan','catalog_drift','runtime_drift','limits','observation_stale','unsupported','source_cost','selection','state_drift','flag_drift','price_exists','quote_drift','backup_exists','backup_failed','backup_invalid','validation','quote_validation'],true)?$e->getMessage():'private_error').PHP_EOL;exit(1);}
