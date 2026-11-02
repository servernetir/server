<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArvanWindowsConsoleOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('arvan_console_worker_enabled', '1');
        Setting::putSecret('arvan_console_worker_key', str_repeat('a', 64));
    }

    private function fixture(string $provider = 'arvan'): array
    {
        $customer = Customer::create([
            'code' => 'SN-123456', 'email' => 'windows-console@example.test',
            'phone' => '09120000000', 'password' => 'synthetic-only', 'status' => 'active',
        ]);
        CloudImage::create([
            'provider' => $provider, 'provider_ref' => 'synthetic-windows', 'key' => '2025',
            'kind' => 'os', 'family' => 'windows', 'label' => 'Windows Server 2025',
            'arch' => 'x86', 'is_active' => true,
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'Windows VPS',
            'currency_code' => 'IRT', 'price' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'cloud_image_key' => '2025',
        ]);
        $instance = CloudInstance::create([
            'service_id' => $service->id, 'provider' => $provider,
            'provider_ref' => 'ir-tbz-sh1:00000000-0000-0000-0000-000000000001',
            'image_key' => '2025', 'hostname' => 'synthetic-windows',
            'ipv4' => '203.0.113.9', 'status' => 'running',
        ]);

        return [$customer, $service, $instance];
    }

    private function page(Customer $customer, Service $service, string $locale = 'fa')
    {
        return $this->actingAs($customer, 'customer')
            ->get(($locale === 'fa' ? '' : '/'.$locale).'/account/cloud/'.$service->id)
            ->assertOk();
    }

    public function test_eligible_console_first_setup_is_localized_and_only_launches_on_post(): void
    {
        [$customer, $service] = $this->fixture();
        foreach (['fa', 'en', 'tr'] as $locale) {
            $html = $this->page($customer, $service, $locale)->getContent();
            $this->assertStringContainsString('data-windows-console-setup', $html);
            $this->assertStringContainsString(e(trans('ui.cs_windows_console_setup_h', [], $locale)), $html);
            $this->assertStringContainsString('Ctrl+Alt+Del', $html);
            $this->assertStringContainsString('Administrator', $html);
            $doc = new \DOMDocument;
            @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($doc);
            $forms = $xpath->query('//*[@data-windows-console-setup]//form');
            $this->assertCount(1, $forms);
            $form = $forms->item(0);
            $this->assertSame('post', strtolower($form->getAttribute('method')));
            $this->assertStringContainsString('/account/cloud/'.$service->id.'/console', $form->getAttribute('action'));
            $this->assertSame('_blank', $form->getAttribute('target'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_console_preserves_missing_access_warning(): void
    {
        [$customer, $service] = $this->fixture();
        Setting::put('arvan_console_worker_enabled', '0');
        $html = $this->page($customer, $service)->getContent();
        $this->assertStringNotContainsString('data-windows-console-setup', $html);
        $this->assertStringContainsString(e(__('ui.cs_windows_pw_unavailable')), $html);
        $this->assertStringNotContainsString(e(__('ui.cs_console_unavailable_rdp')), $html);
    }

    public function test_invalid_instance_reference_does_not_offer_setup_despite_enabled_capability(): void
    {
        [$customer, $service, $instance] = $this->fixture();
        $instance->update(['provider_ref' => 'invalid-reference']);
        $html = $this->page($customer, $service)->getContent();
        $this->assertStringNotContainsString('data-windows-console-setup', $html);
        $this->assertStringContainsString(e(__('ui.cs_windows_pw_unavailable')), $html);
    }

    public function test_saved_password_keeps_explicit_reveal_without_first_boot_instructions(): void
    {
        [$customer, $service, $instance] = $this->fixture();
        $instance->setPassword('Synthetic-password-only!');
        $instance->save();
        $html = $this->page($customer, $service)->getContent();
        $this->assertStringNotContainsString('data-windows-console-setup', $html);
        $this->assertStringContainsString(e(__('ui.cs_pw_reveal')), $html);
        $this->assertStringNotContainsString('Synthetic-password-only!', $html);
        $this->assertFalse($instance->fresh()->password_seen);
    }

    public function test_non_arvan_windows_is_not_given_arvan_first_boot_instructions(): void
    {
        [$customer, $service] = $this->fixture('hetzner');
        $this->assertStringNotContainsString('data-windows-console-setup', $this->page($customer, $service)->getContent());
    }

    public function test_suspended_service_does_not_offer_a_console_setup_action(): void
    {
        [$customer, $service] = $this->fixture();
        $service->update(['status' => 'suspended']);
        $this->assertStringNotContainsString('data-windows-console-setup', $this->page($customer, $service)->getContent());
    }

    public function test_windows_ready_notice_requires_readable_password_or_eligible_console(): void
    {
        [, , $instance] = $this->fixture();
        $this->assertTrue($instance->readyForNotice());
        Setting::put('arvan_console_worker_enabled', '0');
        $this->assertFalse($instance->readyForNotice());
        $this->assertTrue($instance->isDelivered(), 'Keep the page accessible for support and console recovery.');

        $instance->root_password_enc = 'unreadable-synthetic-ciphertext';
        $this->assertFalse($instance->readyForNotice(), 'Ciphertext presence alone is not a usable credential.');
        $instance->setPassword('Synthetic-password-only!');
        $instance->save();
        $this->assertTrue($instance->readyForNotice());

        $instance->status = 'off';
        $this->assertFalse($instance->readyForNotice());
        $instance->status = 'running';
        $instance->ipv4 = null;
        $this->assertFalse($instance->readyForNotice());
    }

    public function test_windows_notice_does_not_accept_console_with_invalid_reference_or_missing_key(): void
    {
        [, , $instance] = $this->fixture();
        Setting::putSecret('arvan_console_worker_key', 'invalid-synthetic-key');
        $this->assertFalse($instance->readyForNotice());
        Setting::putSecret('arvan_console_worker_key', str_repeat('a', 64));
        $instance->update(['provider_ref' => 'invalid-reference']);
        $this->assertFalse($instance->arvanWindowsConsoleAvailable());
        $this->assertFalse($instance->readyForNotice());
    }

    public function test_other_providers_and_arvan_linux_keep_existing_readiness_rules(): void
    {
        [, , $instance] = $this->fixture('hetzner');
        Setting::put('arvan_console_worker_enabled', '0');
        $this->assertTrue($instance->readyForNotice());
        $this->assertFalse($instance->arvanWindowsConsoleAvailable());
        $instance->update(['provider' => 'arvan', 'image_key' => 'ubuntu-24.04']);
        CloudImage::create([
            'provider' => 'arvan', 'provider_ref' => 'synthetic-linux', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Ubuntu', 'is_active' => true,
        ]);
        $this->assertTrue($instance->readyForNotice());
    }

    public function test_stale_instance_cannot_borrow_console_access_after_reference_or_image_changes(): void
    {
        [, , $instance] = $this->fixture();
        CloudInstance::whereKey($instance->id)->update([
            'provider_ref' => 'ir-tbz-sh1:00000000-0000-0000-0000-000000000002',
        ]);
        $this->assertFalse($instance->arvanWindowsConsoleAvailable());
        $this->assertFalse($instance->readyForNotice());
        $instance->refresh();
        $this->assertTrue($instance->arvanWindowsConsoleAvailable());

        CloudImage::create([
            'provider' => 'arvan', 'provider_ref' => 'synthetic-windows-rebuilt', 'key' => '2022',
            'kind' => 'os', 'family' => 'windows', 'label' => 'Windows Server 2022', 'is_active' => true,
        ]);
        CloudInstance::whereKey($instance->id)->update(['image_key' => '2022']);
        $this->assertFalse($instance->arvanWindowsConsoleAvailable());
        $this->assertFalse($instance->readyForNotice());
        $this->assertTrue($instance->fresh()->arvanWindowsConsoleAvailable());
    }

    public function test_stale_deleted_instance_cannot_borrow_console_access_from_replacement(): void
    {
        [, , $instance] = $this->fixture();
        $replacement = $instance->replicate();
        CloudInstance::whereKey($instance->id)->delete();
        $replacement->save();
        $this->assertNotSame($instance->id, $replacement->id);
        $this->assertTrue($replacement->arvanWindowsConsoleAvailable());
        $this->assertFalse($instance->arvanWindowsConsoleAvailable());
        $this->assertFalse($instance->readyForNotice());
    }
}
