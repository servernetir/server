<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{CloudImage, CloudPlan, CloudLocation, Customer};
use App\Services\Cloud\{CloudChoiceAvailability, CloudPricingSnapshot};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudChoiceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store(['USD'=>100000,'EUR'=>100000,'USDT'=>100000,'TRY'=>5000]);
        $this->mock(CloudPricingSnapshot::class, function($m){$m->shouldReceive('current')->andReturn(true);$m->shouldReceive('excludedProviders')->andReturn([]);});
        $this->actingAs(Customer::create(['email'=>'choices@example.test','phone'=>'09121112222','password'=>'fixture-secret','status'=>'active','locale'=>'fa']), 'customer');
        CloudLocation::create(['code'=>'tr-istanbul','country'=>'TR','city'=>'Istanbul','is_active'=>true]);
    }

    private function plan(array $extra=[]): CloudPlan
    {
        return CloudPlan::create($extra+['provider'=>'aeza','provider_ref'=>'fixture','provider_location'=>'istanbul','location_code'=>'tr-istanbul','public_name'=>'CV-1-1','slug'=>'tiny','vcpu'=>1,'ram_mb'=>1024,'disk_gb'=>100,'boot_disk_gb'=>20,'data_disk_gb'=>80,'disk_type'=>'ssd','arch'=>'x86','cost_eur_cents'=>100,'price_eur_cents'=>150,'price_irt'=>150000,'is_active'=>true,'in_stock'=>true,'admin_disabled'=>false]);
    }

    private function image(array $extra=[]): CloudImage
    {
        return CloudImage::create($extra+['provider'=>'aeza','provider_ref'=>'ubuntu','key'=>'ubuntu-24.04','kind'=>'os','family'=>'ubuntu','version'=>'24.04','label'=>'Ubuntu 24.04','arch'=>'x86','min_ram_mb'=>512,'min_disk_gb'=>10,'is_active'=>true]);
    }

    public function test_unavailable_windows_remains_visible_with_ram_reason_and_disabled_radio_in_three_languages(): void
    {
        $plan=$this->plan();$this->image();$this->image(['provider_ref'=>'windows','key'=>'windows-2025','family'=>'windows','label'=>'Windows Server 2025','min_ram_mb'=>4096]);
        foreach(['fa','en','tr'] as $lang){
            app()->setLocale($lang);
            $view=app(CloudStoreController::class)->index(\Illuminate\Http\Request::create('/account/cloud-store?location=tr-istanbul'));
            $html=$view->with('errors',new \Illuminate\Support\ViewErrorBag)->render();
            $this->assertStringContainsString('Windows Server 2025',$html);
            $this->assertSame(1,preg_match('/<label[^>]*data-key="windows-2025"[^>]*aria-disabled="true"[^>]*>.*?<input[^>]*disabled.*?4096|<label[^>]*data-key="windows-2025"[^>]*aria-disabled="true"[^>]*>.*?<input[^>]*disabled/s',$html));
            $this->assertStringContainsString(__('ui.choice_ram',['amount'=>fa_num(4096)]),$html);
            $this->assertNotContains('windows-2025',CloudStoreController::imageKeysFor($plan));
            $this->assertStringNotContainsString('ui.choice_',$html);
        }
        Http::assertNothingSent();$this->assertDatabaseCount('services',0);
    }

    public function test_reason_distinguishes_provider_arch_ram_boot_disk_and_cpu_without_leaking_supplier(): void
    {
        app()->setLocale('en');$p=$this->plan();$i=$this->image();$s=app(CloudChoiceAvailability::class);
        $i->provider='hetzner';$i->min_ram_mb=8192;
        $this->assertSame(__('ui.choice_image_unavailable'),$s->imageReason($p,collect([$i]),collect([$p])));
        $i->provider='aeza';$i->arch='arm';
        $this->assertSame(__('ui.choice_arch'),$s->imageReason($p,collect([$i]),collect([$p])));
        $i->arch='x86';$i->min_ram_mb=4096;
        $this->assertSame(__('ui.choice_ram',['amount'=>fa_num(4096)]),$s->imageReason($p,collect([$i]),collect([$p])));
        $i->min_ram_mb=512;$i->min_disk_gb=40;
        $this->assertSame(__('ui.choice_disk',['amount'=>fa_num(40)]),$s->imageReason($p,collect([$i]),collect([$p])));
        $p->provider='lightnode';$p->provider_ref='r|z|p';$p->ram_mb=4096;$i->provider='lightnode';$i->provider_ref='r|image';$i->family='windows';$i->min_disk_gb=10;
        $this->assertSame(__('ui.choice_cpu',['amount'=>fa_num(2)]),$s->imageReason($p,collect([$i]),collect([$p])));
        $i->provider_ref='other-region|image';
        $this->assertSame(__('ui.choice_image_unavailable'),$s->imageReason($p,collect([$i]),collect([$p])));
    }

    public function test_monthly_only_country_is_visible_disabled_on_hourly_page_without_fake_hourly_price(): void
    {
        $this->plan();$this->image();
        $view=app(\App\Http\Controllers\HourlyVpsController::class)->show();
        $this->assertCount(0,$view->getData()['countries']);
        $this->assertSame('TR',$view->getData()['unavailableCountries'][0]['iso']);
        $html=$view->with('errors',new \Illuminate\Support\ViewErrorBag)->render();
        $this->assertStringContainsString('aria-disabled="true"',$html);
        $this->assertStringContainsString(__('ui.choice_country_hourly'),$html);
        $this->assertSame(0,CloudPlan::sellable()->hourlyCapable()->count());
    }
    public function test_forged_disabled_image_and_hourly_submissions_create_no_service_or_invoice(): void
    {
        $this->plan();$this->image();$this->image(['provider_ref'=>'windows','key'=>'windows-2025','family'=>'windows','label'=>'Windows Server 2025','min_ram_mb'=>4096]);
        $payload=['location'=>'tr-istanbul','plan'=>'tiny','image'=>'windows-2025','cycle'=>'monthly'];
        $this->post(route('account.cloud.store.place',[],false),$payload)->assertSessionHasErrors('image');
        $payload['image']='ubuntu-24.04';$payload['billing_mode']='hourly';
        $this->post(route('account.cloud.store.place',[],false),$payload)->assertSessionHasErrors('billing_mode');
        $this->assertDatabaseCount('services',0);$this->assertDatabaseCount('invoices',0);Http::assertNothingSent();
    }
}
