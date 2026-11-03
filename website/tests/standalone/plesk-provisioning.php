<?php
// No Laravel bootstrap, credentials, database, or network. Exercises the actual driver/client.
namespace App\Models {
    class Server { public int $id=4; public string $type='plesk'; public string $hostname='panel.example.test'; public string $username='admin'; public string $api_token='fake-key'; public bool $verify_tls=true; public array $meta=['plesk_auth_mode'=>'key','plesk_hosting_ip'=>'192.0.2.10']; public function effectivePort():int{return 8443;} }
    class Service { public int $id=732; public int $customer_id=681; public bool $is_reseller=false; public string $username='fixture0732'; public string $domain='fixture.example.test'; public string $plan='WIN-10'; public string $password='fake-secret'; public array $provision_meta=[]; public Server $server; public object $customer; public function __construct(){ $this->server=new Server; $this->customer=new class { public string $email='fixture@example.test'; public function displayName():string{return 'Fixture & Customer';} }; } public function forceFill(array $a):self{foreach($a as $k=>$v)$this->$k=$v;return $this;} public function save():bool{return true;} }
}
namespace Illuminate\Support\Facades {
    class Http { public static mixed $responder; public static array $headers=[]; public static function withHeaders(array $h):object{ self::$headers=$h; return new class { public string $body=''; public function connectTimeout($t):self{return $this;} public function timeout($t):self{return $this;} public function withoutVerifying():self{return $this;} public function withBody($b,$t):self{$this->body=$b;return $this;} public function post($u):object{$b=(Http::$responder)($this->body);return new class($b) {public function __construct(private string $b){} public function body():string{return $this->b;} };} }; } }
}
namespace {
    function filled($v):bool{return $v!==null&&$v!==''&&$v!==[];}
    function blank($v):bool{return !filled($v);}
    function app():object{return new class {public function runningInConsole():bool{return true;}};}
    require __DIR__.'/../../app/Services/Provisioning/ProvisionResult.php';
    require __DIR__.'/../../app/Services/Provisioning/PleskClient.php';
    require __DIR__.'/../../app/Services/Provisioning/PleskHostingProvisioner.php';
    use App\Models\Service;
    use App\Services\Provisioning\PleskClient;
    use App\Services\Provisioning\PleskHostingProvisioner;
    use Illuminate\Support\Facades\Http;
    $checks=0;$cases=0;
    function check(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
    function sample(string $operator,string $action,string $body):string{return '<packet><'.$operator.'><'.$action.'><result>'.$body.'</result></'.$action.'></'.$operator.'></packet>';}
    function absent(string $operator):string{return sample($operator,'get','<status>error</status><errcode>1013</errcode><errtext>not found</errtext>');}
    function fixture():array {
        $s=new Service;
        $f=(object)['customer'=>false,'subscription'=>false,'plan'=>true,'owner'=>7,'planId'=>'plan-fixture-guid','email'=>'fixture@example.test','customerUnknown'=>false,'customerTimeout'=>false,'subscriptionTimeout'=>false,'timeoutCreates'=>true,'creates'=>0,'calls'=>[]];
        Http::$responder=function(string $xml)use($f){
            $f->calls[]=$xml;$x=simplexml_load_string($xml);check($x!==false,'request is valid XML');
            if(isset($x->{'service-plan'}))return $f->plan?sample('service-plan','get','<status>ok</status><id>8</id><guid>plan-fixture-guid</guid><name>WIN-10</name>'):absent('service-plan');
            if(isset($x->customer->get)){
                check(isset($x->customer->get->dataset->gen_info),'customer uses documented gen_info');
                if($f->customerUnknown)return '<html>unavailable</html>';
                return $f->customer?sample('customer','get','<status>ok</status><id>7</id><data><gen_info><login>fixture0732</login><email>'.$f->email.'</email><status>0</status></gen_info></data>'):absent('customer');
            }
            if(isset($x->customer->add)){
                check(isset($x->customer->add->gen_info),'create uses gen_info');$f->creates++;
                $f->customer=!$f->customerTimeout||$f->timeoutCreates;
                if($f->customerTimeout)throw new RuntimeException('simulated timeout');
                return sample('customer','add','<status>ok</status><id>7</id>');
            }
            if(isset($x->webspace->get))return $f->subscription?sample('webspace','get','<status>ok</status><id>12</id><data><gen_info><name>fixture.example.test</name><owner-id>'.$f->owner.'</owner-id><htype>vrt_hst</htype><status>0</status></gen_info><hosting><vrt_hst><property><name>ftp_login</name><value>fixture0732</value></property><ip_address>192.0.2.10</ip_address></vrt_hst></hosting><subscriptions><subscription><plan><plan-guid>'.$f->planId.'</plan-guid></plan></subscription></subscriptions></data>'):absent('webspace');
            if(isset($x->webspace->add)){
                check((string)$x->webspace->add->gen_setup->{'owner-id'}==='7','subscription has exact customer owner');
                check((string)$x->webspace->add->{'plan-name'}==='WIN-10','exact sold plan sent');$f->creates++;
                $f->subscription=!$f->subscriptionTimeout||$f->timeoutCreates;
                if($f->subscriptionTimeout)throw new RuntimeException('simulated timeout');
                return sample('webspace','add','<status>ok</status><id>12</id>');
            }
            throw new RuntimeException('Unexpected API operation');
        };
        return [$s,$f,new PleskHostingProvisioner(new PleskClient($s->server))];
    }
    function run(string $name,callable $test):void{global $cases;$test();$cases++;echo 'ok '.$name.PHP_EOL;}
    run('customer AND subscription verified, repeat does not create',function(){[$s,$f,$p]=fixture();$r=$p->create($s);check($r->ok,'delivery succeeded');check($f->creates===2,'one customer and one subscription');$r=$p->create($s);check($r->ok&&$f->creates===2,'replay has no creates');check(isset(Http::$headers['KEY'])&&!isset(Http::$headers['HTTP_AUTH_PASSWD']),'key mode used');});
    run('unknown customer never creates',function(){[$s,$f,$p]=fixture();$f->customerUnknown=true;check($p->create($s)->manual&&$f->creates===0,'unknown fails closed');});
    run('unbound existing login is not adopted',function(){[$s,$f,$p]=fixture();$f->customer=true;check($p->create($s)->manual&&$f->creates===0,'unbound login refused');});
    run('wrong customer email is rejected',function(){[$s,$f,$p]=fixture();$f->email='other@example.test';check($p->create($s)->manual&&!$f->subscription,'foreign email refused');});
    run('wrong subscription owner is rejected',function(){[$s,$f,$p]=fixture();$f->owner=99;check($p->create($s)->manual,'foreign owner refused');});
    run('wrong subscription plan is rejected',function(){[$s,$f,$p]=fixture();$f->planId=99;check($p->create($s)->manual,'wrong plan refused');});
    run('missing purchased plan creates nothing',function(){[$s,$f,$p]=fixture();$f->plan=false;check($p->create($s)->manual&&$f->creates===0,'no fallback plan');});
    run('missing hosting IP creates nothing',function(){[$s,$f,$p]=fixture();$s->server->meta['plesk_hosting_ip']='';check($p->create($s)->manual&&count($f->calls)===0,'no guessed IP');});
    run('customer timeout reconciles visible success',function(){[$s,$f,$p]=fixture();$f->customerTimeout=true;check($p->create($s)->ok&&$f->creates===2,'visible customer reused');});
    run('unresolved customer timeout is not reissued',function(){[$s,$f,$p]=fixture();$f->customerTimeout=true;$f->timeoutCreates=false;check($p->create($s)->manual,'timeout held');check($p->create($s)->manual&&$f->creates===1,'no second customer create');});
    run('subscription timeout reconciles visible success',function(){[$s,$f,$p]=fixture();$f->subscriptionTimeout=true;check($p->create($s)->ok&&$f->creates===2,'visible subscription accepted');});
    run('unresolved subscription timeout is not reissued',function(){[$s,$f,$p]=fixture();$f->subscriptionTimeout=true;$f->timeoutCreates=false;check($p->create($s)->manual,'timeout held');check($p->create($s)->manual&&$f->creates===2,'no second subscription create');});
    run('changed order binding is refused',function(){[$s,$f,$p]=fixture();check($p->create($s)->ok,'initial delivery');$s->domain='other.example.test';check($p->create($s)->manual&&$f->creates===2,'no rebind');});
    run('malformed and mixed-result responses fail',function(){[$s]=fixture();$c=new PleskClient($s->server);foreach(['<html>blocked</html>','<packet><customer><get><result><status>ok</status></result><result><status>error</status></result></get></customer></packet>','<!DOCTYPE packet [<!ENTITY x SYSTEM "file:///etc/passwd">]><packet><system><status>ok</status></system></packet>'] as $body){Http::$responder=fn($xml)=>$body;check(!$c->call('<packet/>')['ok'],'invalid response not accepted');}});
    run('SSO stays on configured origin and refuses administrator',function(){[$s]=fixture();$c=new PleskClient($s->server);Http::$responder=fn($xml)=>sample('server','create_session','<status>ok</status><id>'.str_repeat('a',32).'</id>');$u=$c->createUserSession($s->username,'192.0.2.1');check(str_starts_with($u??'','https://panel.example.test:8443/enterprise/rsession_init.php?PLESKSESSID='),'fixed origin');check(!str_contains($u??'',$s->password),'no password in URL');check($c->createUserSession('admin','192.0.2.1')===null,'admin never impersonated');});
    echo $cases.' scenarios, '.$checks.' assertions; zero network requests.'.PHP_EOL;
}
