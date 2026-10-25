<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AdminServiceInfrastructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminCustomerInfrastructureAndTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function staff(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'پشتیبان', 'email' => uniqid('staff').'@example.test',
            'password' => bcrypt('test-only-password'), 'role' => $role,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => uniqid('customer').'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('test-only-password'), 'status' => 'active',
        ]);
    }

    private function service(Customer $customer, array $attributes = []): Service
    {
        return Service::create($attributes + [
            'customer_id' => $customer->id, 'name' => 'سرور آزمایشی', 'currency_code' => 'IRT',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active',
        ]);
    }

    private function cloudInstanceFixture(Service $service, string $provider, string $ref): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => $provider, 'provider_ref' => $ref,
            'location_code' => 'de-frankfurt', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'test-only', 'ipv4' => '203.0.113.55', 'status' => 'running',
        ]);
    }

    public function test_delivered_provider_wins_over_ordered_plan_and_arvan_link_uses_recorded_region(): void
    {
        $customer = $this->customer();
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'plan-not-resource',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-1-2', 'slug' => 'test-plan',
        ]);
        $service = $this->service($customer, ['cloud_plan_id' => $plan->id]);
        $this->cloudInstanceFixture($service, 'arvan', 'ir-thr-c2:resource-123');

        $html = $this->actingAs($this->staff())->get('/admin/customers/'.$customer->id)->assertOk()->getContent();
        $this->assertStringContainsString('ArvanCloud (ابرآروان)', $html);
        $this->assertStringContainsString('https://panel.arvancloud.ir/ecc/ir-thr-c2/servers/resource-123/details', $html);
        $this->assertStringNotContainsString('Hetzner Cloud', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $html);
    }

    public function test_unprovisioned_plan_never_uses_a_plan_id_as_a_server_id(): void
    {
        $plan = new CloudPlan(['provider' => 'aeza', 'provider_ref' => '12345', 'location_code' => 'de-frankfurt']);
        $service = (new Service)->setRelations(['cloudPlan' => $plan, 'cloudInstance' => null, 'server' => null]);
        $result = app(AdminServiceInfrastructure::class)->describe($service);
        $this->assertSame('Aeza', $result['label']);
        $this->assertSame('https://my.aeza.net/services', $result['url']);
        $this->assertNull($result['reference']);
        $this->assertTrue($result['awaiting_resource']);
        $this->assertFalse($result['direct']);
    }

    public function test_aeza_order_references_are_not_linked_as_delivered_resources(): void
    {
        $service = (new Service)->setRelations([
            'cloudPlan' => null, 'server' => null,
            'cloudInstance' => new CloudInstance(['provider' => 'aeza', 'provider_ref' => 'order:123']),
        ]);
        $result = app(AdminServiceInfrastructure::class)->describe($service);
        $this->assertFalse($result['direct']);
        $this->assertSame('https://my.aeza.net/services', $result['url']);
        $service->cloudInstance->provider_ref = '67890';
        $this->assertSame('https://my.aeza.net/services/67890', app(AdminServiceInfrastructure::class)->describe($service)['url']);
    }

    public function test_salad_link_encodes_each_component_without_exposing_app_access_tokens(): void
    {
        Setting::put('salad_org', 'test-org');
        Setting::put('salad_project', 'default');
        $service = (new Service)->setRelations([
            'cloudPlan' => null, 'server' => null,
            'cloudInstance' => new CloudInstance([
                'provider' => 'salad', 'provider_ref' => 'group/a?token=no',
                'meta' => ['access_token' => 'do-not-show-this'],
            ]),
        ]);
        $result = app(AdminServiceInfrastructure::class)->describe($service);
        $this->assertSame('https://portal.salad.com/organizations/test-org/projects/default/containers/group%2Fa%3Ftoken%3Dno', $result['url']);
        $this->assertStringNotContainsString('do-not-show-this', json_encode($result));
    }

    public function test_hosting_panel_uses_the_registered_server_and_rejects_credentials_or_query_strings(): void
    {
        $server = new Server(['name' => 'هاست آلمان', 'type' => 'whm', 'hostname' => 'host.example.test', 'vendor' => 'Hetzner']);
        $service = (new Service)->setRelations(['cloudPlan' => null, 'cloudInstance' => null, 'server' => $server]);
        $presenter = app(AdminServiceInfrastructure::class);
        $this->assertSame('https://host.example.test:2087/', $presenter->describe($service)['url']);
        foreach (['https://admin:secret@host.example.test/', 'https://host.example.test/?token=secret', 'javascript:alert(1)'] as $bad) {
            $server->hostname = $bad;
            $this->assertNull($presenter->describe($service)['url']);
        }
    }

    public function test_hetzner_cloud_and_robot_have_separate_fallback_panels(): void
    {
        foreach (['hetzner' => 'https://console.hetzner.com/', 'hetzner-robot' => 'https://robot.hetzner.com/'] as $provider => $url) {
            $service = (new Service)->setRelations([
                'server' => null, 'cloudPlan' => null,
                'cloudInstance' => new CloudInstance(['provider' => $provider, 'provider_ref' => '789']),
            ]);
            $result = app(AdminServiceInfrastructure::class)->describe($service);
            $this->assertSame($url, $result['url']);
            $this->assertFalse($result['direct']);
            $this->assertSame('789', $result['reference']);
        }
    }

    public function test_terminated_services_keep_provider_tracking_and_hidden_model_fields_stay_hidden(): void
    {
        $customer = $this->customer();
        $service = $this->service($customer, ['status' => 'terminated']);
        $instance = $this->cloudInstanceFixture($service, 'aeza', '54321');
        $html = $this->actingAs($this->staff())->get('/admin/customers/'.$customer->id)->assertOk()->getContent();
        $this->assertStringContainsString('https://my.aeza.net/services/54321', $html);
        $this->assertStringContainsString('زیرساخت و رهگیری', $html);
        $this->assertArrayNotHasKey('provider', $instance->toArray());
        $this->assertArrayNotHasKey('provider_ref', $instance->toArray());
        $this->assertArrayNotHasKey('root_password_enc', $instance->toArray());
    }

    public function test_unknown_service_has_an_explicit_label_without_an_invented_link(): void
    {
        $service = (new Service)->setRelations(['server' => null, 'cloudPlan' => null, 'cloudInstance' => null]);
        $result = app(AdminServiceInfrastructure::class)->describe($service);
        $this->assertSame('ثبت نشده', $result['label']);
        $this->assertNull($result['url']);
    }

    public function test_support_can_compose_a_ticket_on_the_customer_profile_without_sending_on_get(): void
    {
        $customer = $this->customer();
        $html = $this->actingAs($this->staff('support'))->get('/admin/customers/'.$customer->id)->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($doc);
        $this->assertSame(1, $xpath->query('//details[@id="customer-ticket-compose"]//form[@action="/admin/tickets/create"]')->length);
        $this->assertSame((string) $customer->id, $xpath->query('//details[@id="customer-ticket-compose"]//input[@name="customer_ids[]"]')->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//details[@id="customer-ticket-compose"]//input[@name="_token"]')->length);
        $this->assertSame(0, Ticket::count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_profile_form_creates_a_staff_ticket_for_exactly_that_customer(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $staff = $this->staff('support');
        $this->actingAs($staff)->from('/admin/customers/'.$customer->id)->post('/admin/tickets/create', [
            '_customer_ticket_form' => $customer->id, 'customer_ids' => [$customer->id],
            'subject' => 'بررسی سرویس شما', 'body' => 'لطفاً نتیجه بررسی را در همین تیکت ارسال کنید.',
            'department' => 'technical', 'priority' => 'normal',
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/tickets/'.Ticket::sole()->id);
        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame('answered', $ticket->status);
        $this->assertSame('staff', $ticket->messages()->sole()->author_role);
        $this->assertSame($staff->id, $ticket->messages()->sole()->author_id);
        $this->assertSame(0, Ticket::where('customer_id', $other->id)->count());
    }

    public function test_invalid_profile_ticket_reopens_the_form_and_preserves_the_draft(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->staff('support'))->from('/admin/customers/'.$customer->id)->post('/admin/tickets/create', [
            '_customer_ticket_form' => $customer->id, 'customer_ids' => [$customer->id],
            'subject' => '', 'body' => 'پیش‌نویس حفظ شود', 'department' => 'billing', 'priority' => 'high',
        ])->assertRedirect('/admin/customers/'.$customer->id)->assertSessionHasErrors('subject');
        $html = $this->get('/admin/customers/'.$customer->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<details id="customer-ticket-compose"[^>]* open/', $html);
        $this->assertStringContainsString('پیش‌نویس حفظ شود', $html);
        $this->assertSame(0, Ticket::count());
    }

    public function test_nonstaff_cannot_view_customer_profile_or_send_a_staff_ticket(): void
    {
        $customer = $this->customer();
        $author = $this->staff('author');
        $this->actingAs($author)->get('/admin/customers/'.$customer->id)->assertForbidden();
        $this->post('/admin/tickets/create', [
            'customer_ids' => [$customer->id], 'subject' => 'blocked', 'body' => 'blocked',
            'department' => 'technical', 'priority' => 'normal',
        ])->assertForbidden();
        $this->assertSame(0, Ticket::count());
    }
}
