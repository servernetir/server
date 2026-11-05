<?php
namespace Tests\Feature;

use App\Models\{Customer,Server,Service};
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\{Http,Mail};
use Tests\TestCase;

class PleskPanelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['email'=>'p'.random_int(1000,99999).'@example.test',
            'phone'=>'0912'.random_int(1000000,9999999),'password'=>'fixture-only','status'=>'active','locale'=>'fa']);
    }
    private function service(): Service
    {
        $server=Server::create(['name'=>'Plesk fixture','type'=>'plesk','hostname'=>'panel.example.test',
            'username'=>'admin','api_token'=>'fake-key','verify_tls'=>true,'country'=>'IR','status'=>'active',
            'max_accounts'=>100,'active_accounts'=>0,'nameservers'=>'ns1.example.test,ns2.example.test',
            'meta'=>['plesk_auto_enabled'=>true,'plesk_auto_ready'=>true,'plesk_auth_mode'=>'key','plesk_hosting_ip'=>'192.0.2.10']]);
        return Service::create(['customer_id'=>$this->customer()->id,'server_id'=>$server->id,'name'=>'Windows fixture',
            'domain'=>'fixture.example.test','username'=>'fixture0732','password'=>'fixture-secret','plan'=>'WIN-10',
            'cycle'=>'monthly','currency_code'=>'IRT','price'=>1000,'status'=>'active','provision_status'=>'done',
            'provision_meta'=>['plesk'=>['server_id'=>$server->id,'customer_id'=>7,'subscription_id'=>12,
                'login'=>'fixture0732','domain'=>'fixture.example.test','plan'=>'WIN-10']]]);
    }
    public function test_per_server_enable_requires_acceptance_and_does_not_enable_other_servers(): void
    {
        config(['provisioning.plesk_auto'=>false]);$s=$this->service()->server;
        $this->assertTrue($s->isAutoProvisioned());
        $s->meta=['plesk_auto_enabled'=>true];$this->assertFalse($s->isAutoProvisioned());
        $s->meta=['plesk_auto_ready'=>true];$this->assertFalse($s->isAutoProvisioned());
        $s->type='whm';$this->assertTrue($s->isAutoProvisioned());
    }
    public function test_owner_gets_a_single_use_customer_session_with_no_password_in_url(): void
    {
        $s=$this->service();$s->update(['domain'=>'Fixture.Example.Test']);
        Http::swap(new Factory);Http::preventStrayRequests();
        Http::fake(fn()=>Http::response('<packet><server><create_session><result><status>ok</status><id>'.str_repeat('a',32).'</id></result></create_session></server></packet>',200));
        $r=$this->actingAs($s->customer,'customer')->get('/account/services/'.$s->id.'/cpanel');
        $r->assertRedirect('https://panel.example.test:8443/enterprise/rsession_init.php?PLESKSESSID='.str_repeat('a',32));
        $r->assertHeader('Referrer-Policy','no-referrer');
        $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
        Http::assertSent(fn($request)=>str_contains($request->body(),'<login>fixture0732</login>')
            && $request->hasHeader('KEY','fake-key') && !str_contains($request->body(),'<passwd>'));
    }
    public function test_deep_links_use_a_fixed_plesk_path_and_cannot_redirect_elsewhere(): void
    {
        $s=$this->service();Http::swap(new Factory);Http::preventStrayRequests();
        Http::fake(fn()=>Http::response('<packet><server><create_session><result><status>ok</status><id>'.str_repeat('a',32).'</id></result></create_session></server></packet>',200));
        $base='https://panel.example.test:8443/enterprise/rsession_init.php?PLESKSESSID='.str_repeat('a',32);
        $this->actingAs($s->customer,'customer')->get('/account/services/'.$s->id.'/cpanel?app=files')
            ->assertRedirect($base.'&success_redirect_url='.rawurlencode('/smb/file-manager'));
        $this->get('/account/services/'.$s->id.'/cpanel?app=https%3A%2F%2Fevil.example.test')->assertRedirect($base);
    }
    public function test_plesk_free_subdomain_uses_the_service_connection_ip(): void
    {
        $s=$this->service();$s->update(['domain'=>'fixture.servernet.cloud','server_ip'=>null,
            'provision_meta'=>array_merge($s->provision_meta,['server_ip'=>'192.0.2.10'])]);
        $s->server->update(['server_ip'=>'192.0.2.99']);
        $dns=\Mockery::mock(\App\Services\Dns\CloudflareDns::class);
        $dns->shouldReceive('isConfigured')->once()->andReturn(true);
        $dns->shouldReceive('pointSubdomain')->once()->with('fixture.servernet.cloud','192.0.2.10')->andReturn(['ok'=>true]);
        $this->app->instance(\App\Services\Dns\CloudflareDns::class,$dns);
        $method=new \ReflectionMethod(ProvisioningService::class,'pointFreeSubdomain');
        $method->invoke(app(ProvisioningService::class),$s->fresh(),$s->server->fresh());
        $this->assertStringContainsString('192.0.2.10',$s->fresh()->provision_meta['dns']);
    }
    public function test_dns_checks_use_the_verified_metadata_ip_when_the_optional_column_is_empty(): void
    {
        $s=$this->service();$s->update(['server_ip'=>null,'provision_meta'=>array_merge($s->provision_meta,['server_ip'=>'192.0.2.10'])]);
        $s->server->update(['server_ip'=>'192.0.2.99']);
        $wire=\Mockery::mock(\App\Services\Dns\Wire\DnsWireClient::class);
        $wire->shouldReceive('query')->times(6)->andReturnUsing(function($server,$port,$domain,$type){
            $values=match($type){'NS'=>['ns1.example.test','ns2.example.test'],'A'=>['192.0.2.10'],default=>[]};
            $rows=array_map(fn($v)=>['name'=>$domain,'type'=>$type,'data'=>$v],$values);
            return new \App\Services\Dns\Wire\DnsResponse(1,0,true,false,false,true,true,false,false,0,null,$rows,[],[],null);
        });
        $result=(new \App\Services\Dns\HostingDomainConnection($wire))->inspect($s->fresh());
        $this->assertSame('connected',$result['state']);
    }
    public function test_other_customer_cannot_request_a_session(): void
    {
        $s=$this->service();Http::swap(new Factory);Http::fake();
        $this->actingAs($this->customer(),'customer')->get('/account/services/'.$s->id.'/cpanel')->assertNotFound();
        Http::assertNothingSent();
    }
    public function test_suspended_host_cannot_request_a_session(): void
    {
        $s=$this->service();$s->update(['status'=>'suspended']);Http::swap(new Factory);Http::fake();
        $this->actingAs($s->customer,'customer')->from('/account')->get('/account/services/'.$s->id.'/cpanel')->assertSessionHasErrors();
        Http::assertNothingSent();
    }
    public function test_mismatched_binding_cannot_request_a_session(): void
    {
        $s=$this->service();$m=$s->provision_meta;$m['plesk']['login']='someoneelse';$s->update(['provision_meta'=>$m]);Http::swap(new Factory);Http::fake();
        $this->actingAs($s->customer,'customer')->from('/account')->get('/account/services/'.$s->id.'/cpanel')->assertSessionHasErrors();
        Http::assertNothingSent();
    }
    public function test_the_three_language_cards_link_through_the_owned_sso_route(): void
    {
        $s=$this->service();$this->actingAs($s->customer,'customer');
        foreach(['fa','en','tr'] as $locale){app()->setLocale($locale);$html=view('account.partials.card-hosting',['s'=>$s])->render();
            $this->assertStringContainsString('/account/services/'.$s->id.'/cpanel',$html);
            $this->assertStringNotContainsString('ui.hst_login_panel',$html);
            $this->assertStringContainsString('ns1.example.test',$html);
        }
    }
}
