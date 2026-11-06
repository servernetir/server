<?php
// Isolated component acceptance harness: no production bootstrap or .env.
// Usage: php this.php /path/to/approved/vendor/autoload.php
require $argv[1];
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Translation\Translator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use App\Services\Sms\SmsSender;
use App\Services\Otp\OtpService;
use App\Http\Controllers\Api\UrmiaAuthController;

$root = dirname(__DIR__);
require $root.'/website/app/Services/Otp/OtpService.php';
require $root.'/website/app/Http/Middleware/IdentityPrivacy.php';
require $root.'/website/app/Http/Controllers/Api/UrmiaAuthController.php';
$app = new Application($root);
Container::setInstance($app);
$app->instance('config', new Repository([
    'app'=>['key'=>'isolated-otp-test-key-with-no-production-authority','locale'=>'en'],
    'cache'=>['default'=>'array','stores'=>['array'=>['driver'=>'array','serialize'=>false]],'prefix'=>'urmia-auth-tests'],
]));
$app->instance('cache',new CacheManager($app));
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class extends \Illuminate\Routing\ResponseFactory { public function __construct(){} });
$translator=new Translator(new ArrayLoader(),'en');$app->instance('translator',$translator);
$app->instance('validator',new ValidationFactory($translator,$app));
$db = new Capsule($app);
$driver=getenv('URMIA_TEST_DB_DRIVER')?:'sqlite';
$db->addConnection($driver==='sqlite'?['driver'=>'sqlite','database'=>':memory:','prefix'=>'']:['driver'=>'mysql','host'=>'127.0.0.1','port'=>3306,'database'=>getenv('URMIA_TEST_DB_NAME'),'username'=>getenv('URMIA_TEST_DB_USER'),'password'=>getenv('URMIA_TEST_DB_PASS'),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'']);
$db->setAsGlobal();$db->bootEloquent();$app->instance('db',$db->getDatabaseManager());$app->instance('db.schema',$db->schema());
Facade::setFacadeApplication($app);
$db->schema()->create('otp_challenges', function ($t) {
    $t->id();$t->string('channel');$t->string('destination');$t->string('purpose');
    $t->string('code_hash');$t->integer('attempts')->default(0);$t->integer('resends')->default(0);
    $t->string('ip')->nullable();$t->timestamp('expires_at');$t->timestamp('verified_at')->nullable();$t->timestamps();
});
final class FakeUrmiaSms implements SmsSender {
    public array $codes=[]; public bool $throw=false; public bool $negative=false; public bool $associationBeforeSend=true; public bool $checkAssociation=true;
    public function enabled():bool{return true;}
    public function name():string{return 'isolated-fixture';}
    public function send(string $mobile,string $text):bool{throw new RuntimeException('free-text forbidden');}
    public function sendOtp(string $mobile,string $code):bool{if($this->checkAssociation){$last=\App\Models\OtpChallenge::where('destination',$mobile)->latest('id')->first();$found=false;foreach(\Illuminate\Support\Facades\DB::table('urmia_auth_requests')->get() as $row){$entry=json_decode($row->payload,true);if(($entry['otp_id']??null)===$last->id)$found=true;}$this->associationBeforeSend=$this->associationBeforeSend&&$found;}$this->codes[]=$code;if($this->throw)throw new RuntimeException('unknown network outcome');return !$this->negative;}
}
final class FixtureUrmiaController extends UrmiaAuthController {
    public bool $failReceipt=false; public bool $failFinalSend=false;
    protected function ledgerPut(string $key,array $entry):void {
        if(($this->failReceipt&&isset($entry['verified_hash']))||($this->failFinalSend&&($entry['status']??'')==='sent'))throw new RuntimeException('isolated ledger write failure');
        parent::ledgerPut($key,$entry);
    }
    protected function signingKey():string{return str_repeat('isolated-key-',5);}
}
$migration=require $root.'/website/database/migrations/2026_11_02_154500_create_urmia_auth_requests_table.php';$migration->up();
$sms=new FakeUrmiaSms();$otp=new OtpService($sms);$controller=new FixtureUrmiaController();$checks=0;
function expect($value,string $label):void{global $checks;if(!$value)throw new RuntimeException('FAILED: '.$label);$checks++;}
function callUrmia(string $action,array $body,?string $nonce=null,bool $bad=false,bool $privacy=true):array{
    global $controller,$otp;
    $path='/api/internal/urmia-auth/v1/otp/'.$action;$raw=json_encode($body,JSON_THROW_ON_ERROR);$time=(string)time();
    $nonce=$nonce??(string)\Illuminate\Support\Str::uuid();
    $request=Request::create('https://servernet.cloud'.$path,'POST',[],[],[],['CONTENT_TYPE'=>'application/json'],$raw);
    $canonical=implode("\n",['v1','POST',$path,$time,$nonce,hash('sha256',$raw)]);
    $request->headers->set('X-Urmia-Timestamp',$time);$request->headers->set('X-Urmia-Nonce',$nonce);
    $request->headers->set('X-Urmia-Signature',$bad?str_repeat('0',64):hash_hmac('sha256',$canonical,str_repeat('isolated-key-',5)));
    $r=$privacy?(new \App\Http\Middleware\IdentityPrivacy())->handle($request,fn($r)=>$controller->$action($r,$otp)):$controller->$action($request,$otp);
    return [$r->getStatusCode(),$r->getData(true)];
}
$privateRequest=Request::create('https://servernet.cloud/api/internal/urmia-auth/v1/otp/start','POST');
expect(\App\Http\Middleware\IdentityPrivacy::sensitive($privateRequest),'bridge is covered by global private error redaction');
expect(\App\Http\Middleware\IdentityPrivacy::sensitive(Request::create('https://servernet.cloud/api/v1/identity/services')),'existing identity privacy classifier is preserved');
$limited=(new \App\Http\Middleware\IdentityPrivacy())->handle($privateRequest,function(){throw new \Symfony\Component\HttpKernel\Exception\HttpException(429,'private fixture details',null,['Retry-After'=>'60']);});
expect($limited->getStatusCode()===429,'throttle HTTP exception keeps 429');
expect($limited->headers->get('Retry-After')==='60','throttle retry deadline is preserved');
expect(!str_contains($limited->getContent(),'private fixture details'),'HTTP exception message remains private');
$body=['request_id'=>(string)\Illuminate\Support\Str::uuid(),'mobile'=>'09140000000','client_ip'=>'192.0.2.10','session_binding'=>str_repeat('a',64)];
[$status]=callUrmia('start',$body,bad:true);expect($status===403,'bad signature rejected');expect(count($sms->codes)===0,'bad signature cannot send SMS');
[$status]=callUrmia('start',[...$body,'mobile'=>'invalid']);expect($status===422,'actual privacy middleware preserves validation status');
$nonce=(string)\Illuminate\Support\Str::uuid();[$status,$sent]=callUrmia('start',$body,$nonce);
expect($status===200&&$sent['accepted']===true,'signed send accepted');expect(count($sms->codes)===1,'one central SMS send');expect(!isset($sent['code']),'no raw OTP in response');
[$status]=callUrmia('start',$body,$nonce);expect($status===409,'nonce replay rejected');
\Illuminate\Support\Facades\Cache::flush();
[$status,$again]=callUrmia('start',$body);expect($again['challenge_id']===$sent['challenge_id'],'request idempotency survives cache clear');expect(count($sms->codes)===1,'retry does not send SMS again');
[$status]=callUrmia('start',[...$body,'session_binding'=>str_repeat('b',64)]);expect($status===409,'request cannot change session binding');
$verify=['challenge_id'=>$sent['challenge_id'],'code'=>$sms->codes[0],'session_binding'=>str_repeat('b',64)];
[$status]=callUrmia('verify',$verify);expect($status===403,'other session cannot verify');
$verify['session_binding']=str_repeat('a',64);[$status,$verified]=callUrmia('verify',$verify);expect($verified['verified']===true,'correct SMS proof accepted');
[$status,$verified]=callUrmia('verify',$verify);expect($verified['verified']===true,'lost response can be recovered idempotently');
[$status,$wrong]=callUrmia('verify',[...$verify,'code'=>$sms->codes[0]==='000000'?'111111':'000000']);expect($wrong['verified']===false,'verified receipt is not reusable with a different code');
$sms->throw=true;$unknown=[...$body,'request_id'=>(string)\Illuminate\Support\Str::uuid(),'mobile'=>'09140000001'];
[$status,$result]=callUrmia('start',$unknown);expect($status===202&&$result['status']==='unknown','uncertain send stays unknown');
$count=count($sms->codes);[$status]=callUrmia('start',$unknown);expect(count($sms->codes)===$count,'uncertain send is never executed twice');
expect(\App\Models\OtpChallenge::where('purpose','urmia_city_login')->count()===2,'dedicated purpose isolates ServerNet login challenges');
\Illuminate\Support\Facades\DB::table('urmia_auth_requests')->where('request_key','urmia-auth:request:'.$unknown['request_id'])->update(['payload'=>json_encode(['status'=>'unknown','fingerprint'=>hash('sha256',json_encode([$unknown['mobile'],$unknown['client_ip'],$unknown['session_binding']],JSON_THROW_ON_ERROR)),'mobile'=>$unknown['mobile'],'binding'=>$unknown['session_binding'],'deadline'=>time()-1])]);
[$status,$expired]=callUrmia('start',$unknown);expect($expired['status']==='expired','uncertain expired challenge can be explicitly restarted');
$sms->throw=false;
// A real relay converts timeout/rejection into false: retain the code without another send.
$sms->negative=true;$uncertain=[...$body,'request_id'=>(string)\Illuminate\Support\Str::uuid(),'mobile'=>'09140000005'];
[$status,$receipt]=callUrmia('start',$uncertain);expect($status===202&&$receipt['accepted']===true&&$receipt['status']==='unknown','boolean false remains an uncertain, verifiable challenge');
$code=end($sms->codes);$before=count($sms->codes);
[$status,$receiptAgain]=callUrmia('start',$uncertain);expect(count($sms->codes)===$before&&$receiptAgain['challenge_id']===$receipt['challenge_id'],'uncertain boolean result is not resent');
\App\Models\OtpChallenge::where('destination',$uncertain['mobile'])->update(['updated_at'=>now()->subSeconds(90)]);
[$status,$blocked]=callUrmia('start',[...$uncertain,'request_id'=>(string)\Illuminate\Support\Str::uuid()]);expect(count($sms->codes)===$before&&$blocked['accepted']===false,'fresh UUID cannot resend an uncertain SMS during its full TTL');
[$status,$proof]=callUrmia('verify',['challenge_id'=>$receipt['challenge_id'],'code'=>$code,'session_binding'=>$uncertain['session_binding']]);expect($proof['verified']===true,'possibly delivered code remains usable');
$ledgerKey='urmia-auth:request:'.$uncertain['request_id'];$entry=json_decode(\Illuminate\Support\Facades\DB::table('urmia_auth_requests')->where('request_key',$ledgerKey)->value('payload'),true);$entry['deadline']=time()-1;
\Illuminate\Support\Facades\DB::table('urmia_auth_requests')->where('request_key',$ledgerKey)->update(['payload'=>json_encode($entry)]);
[$status,$expiredReceipt]=callUrmia('start',$uncertain);expect($expiredReceipt['status']==='expired','start retry expires an uncertain send at its original deadline');
[$status,$replayedProof]=callUrmia('verify',['challenge_id'=>$receipt['challenge_id'],'code'=>$code,'session_binding'=>$uncertain['session_binding']]);expect($replayedProof['verified']===true,'start expiry cannot erase the bounded successful verification receipt');

$sms->negative=false;
expect($sms->associationBeforeSend,'durable request-to-OTP association exists before delivery');
$lost=[...$body,'request_id'=>(string)\Illuminate\Support\Str::uuid(),'mobile'=>'09140000006'];
$controller->failFinalSend=true;$failed=false;try{callUrmia('start',$lost,privacy:false);}catch(RuntimeException){$failed=true;}
expect($failed,'final send receipt write failure is injected');$controller->failFinalSend=false;
$before=count($sms->codes);$lastCode=end($sms->codes);$raw=\Illuminate\Support\Facades\DB::table('urmia_auth_requests')->where('request_key','urmia-auth:request:'.$lost['request_id'])->value('payload');
expect(isset(json_decode($raw,true)['otp_id']),'failed final write retains pre-delivery association');
[$status,$recovered]=callUrmia('start',$lost);expect($recovered['accepted']===true&&count($sms->codes)===$before,'same request recovers a delivered OTP without resending');
[$status,$proof]=callUrmia('verify',['challenge_id'=>$recovered['challenge_id'],'code'=>$lastCode,'session_binding'=>$lost['session_binding']]);expect($proof['verified']===true,'recovered send remains verifiable');
$atomic=[...$body,'request_id'=>(string)\Illuminate\Support\Str::uuid(),'mobile'=>'09140000007'];[$status,$sentAtomic]=callUrmia('start',$atomic);$lastCode=end($sms->codes);
$atomicVerify=['challenge_id'=>$sentAtomic['challenge_id'],'code'=>$lastCode,'session_binding'=>$atomic['session_binding']];
$controller->failReceipt=true;$failed=false;try{callUrmia('verify',$atomicVerify,privacy:false);}catch(RuntimeException){$failed=true;}
expect($failed,'verification receipt write failure is injected');$controller->failReceipt=false;
expect(\App\Models\OtpChallenge::where('destination',$atomic['mobile'])->latest('id')->first()->verified_at===null,'receipt failure rolls back OTP consumption');
[$status,$proof]=callUrmia('verify',$atomicVerify);expect($proof['verified']===true,'verification can safely retry after receipt rollback');
$sms->checkAssociation=false;
$bale=new class {public int $calls=0;public function otp(string $mobile,string $code):void{$this->calls++;}};
$app->instance(\App\Services\Bale\BaleNotifier::class,$bale);
$legacy=$otp->issue('sms','09140000002','login','192.0.2.88');
expect($legacy->ok && $bale->calls===1,'legacy four-argument calls retain parallel delivery behavior');
for($i=0;$i<20;$i++)\App\Models\OtpChallenge::create(['channel'=>'sms','destination'=>'09140000999','purpose'=>'urmia_city_login','code_hash'=>str_repeat('a',64),'attempts'=>0,'resends'=>0,'ip'=>'192.0.2.99','expires_at'=>now()->addMinutes(3)]);
expect($otp->issue('sms','09140000003','login','192.0.2.99')->ok,'Urmia traffic does not exhaust ServerNet login IP quota');
expect(!$otp->issue('sms','09140000004','urmia_city_login','192.0.2.99',smsOnly:true)->ok,'Urmia keeps its own central IP quota');
echo json_encode(['result'=>'ISOLATED_OTP_TESTS_OK','checks'=>$checks,'database'=>$driver,'real_sms'=>0,'production_bootstrap'=>false]).PHP_EOL;
