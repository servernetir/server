<?php
namespace Tests\Feature;
use App\Models\{CloudLocation,CloudPlan,CreditEntry,Customer,Invoice,Service};
use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpuRequestFundingTest extends TestCase
{
    use RefreshDatabase;

    private function requestFixture(int $balance = 0): array
    {
        Http::preventStrayRequests();
        $customer = Customer::create(['email' => uniqid('gpu').'@example.test',
            'phone' => '0912'.random_int(1000000,9999999), 'password' => bcrypt('test'),
            'status' => 'active', 'locale' => 'fa']);
        CloudLocation::firstOrCreate(['code' => 'global-gpu'],
            ['country' => 'XX', 'is_active' => true, 'sort' => 1]);
        $plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => uniqid('gpu-'),
            'provider_location' => 'global', 'location_code' => 'global-gpu',
            'public_name' => 'RTX 4090', 'slug' => uniqid('gpu-'), 'vcpu' => 8,
            'ram_mb' => 30720, 'disk_gb' => 100, 'disk_type' => 'ssd', 'traffic_gb' => 0,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 400,
            'price_eur_cents' => 600, 'price_irt' => 720000, 'is_active' => true, 'in_stock' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'GPU request',
            'currency_code' => 'IRT', 'price' => 0, 'tax_percent' => 0, 'cycle' => 'monthly',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 178100, 'on_credit_out' => 'suspend',
            'status' => 'awaiting_provision', 'provision_status' => 'gpu_wait',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'gpu-jupyter',
            'provision_meta' => ['gpu_capacity_order' => ['version' => 1, 'state' => 'available',
                'charge_pending' => true, 'requested_at' => now()->toIso8601String()]]]);
        if ($balance) {
            CreditEntry::create(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                'amount' => $balance, 'balance_after' => $balance, 'reason' => 'test_credit']);
        }
        return [$customer, $service];
    }

    public function test_one_click_uses_live_shortfall_and_reuses_the_unpaid_invoice(): void
    {
        [$c,$s] = $this->requestFixture(1000000);
        $this->actingAs($c,'customer')->post('/account/topup',
            ['gpu_service_id' => $s->id, 'amount' => 1])->assertRedirect();
        $invoice = Invoice::where('customer_id',$c->id)->where('kind','topup')->sole();
        $this->assertSame(3274400,(int)$invoice->total);
        $this->assertSame(0,(int)$invoice->tax);
        $this->post('/account/topup',['gpu_service_id' => $s->id])->assertRedirect();
        $this->assertSame(1,Invoice::where('customer_id',$c->id)->count());
        $this->assertSame(1000000,(int)CreditEntry::where('customer_id',$c->id)->sum('amount'));
    }

    public function test_non_persian_request_keeps_the_exact_irt_shortfall(): void
    {
        [$c,$s] = $this->requestFixture();
        $this->actingAs($c,'customer')->post('/en/account/topup',
            ['gpu_service_id' => $s->id])->assertRedirect();
        $this->assertSame(4274400,(int)Invoice::where('customer_id',$c->id)->sole()->total);
    }

    public function test_another_customers_service_cannot_be_funded(): void
    {
        [$a,$s] = $this->requestFixture();
        [$b] = $this->requestFixture();
        $this->actingAs($b,'customer')->post('/account/topup',
            ['gpu_service_id' => $s->id])->assertNotFound();
        $this->assertSame(0,Invoice::count());
    }

    public function test_funded_request_does_not_create_another_invoice(): void
    {
        [$c,$s] = $this->requestFixture(5000000);
        $this->actingAs($c,'customer')->post('/account/topup',
            ['gpu_service_id' => $s->id])->assertRedirect();
        $this->assertSame(0,Invoice::count());
    }

    public function test_expired_request_cannot_open_a_funding_invoice(): void
    {
        [$c,$s] = $this->requestFixture();
        $this->travel(2)->days();
        $this->actingAs($c,'customer')->post('/account/topup',
            ['gpu_service_id' => $s->id])->assertStatus(409);
        $this->assertSame(0,Invoice::count());
    }

    public function test_unapproved_price_cannot_open_a_funding_invoice(): void
    {
        [$c,$s] = $this->requestFixture();
        $meta=$s->provision_meta;$meta['gpu_capacity_order']['state']='price_changed';
        $s->update(['provision_meta'=>$meta]);
        $this->actingAs($c,'customer')->post('/account/topup',
            ['gpu_service_id' => $s->id])->assertStatus(409);
        $this->assertSame(0,Invoice::count());
    }

    public function test_waiting_page_offers_funding_before_the_worker_marks_insufficient_credit(): void
    {
        [$c,$s] = $this->requestFixture();
        $this->actingAs($c,'customer');
        app()->setLocale('fa');
        $html=view('account.partials.gpu-capacity-wait',[
            'service'=>$s,'capacityOrder'=>data_get($s->provision_meta,'gpu_capacity_order'),
            'capacityDisplayState'=>'awaiting_allocation',
        ])->render();
        $this->assertStringContainsString('name="gpu_service_id"',$html);
        $this->assertStringContainsString(__('ui.gpu_fund_request'),$html);
        $this->assertStringContainsString('/account/topup',$html);
        $this->assertSame(4274400,GpuCapacityOrders::fundingWindow($s)['topup_amount']);
    }
}
