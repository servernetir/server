<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\InfrastructureSettingsStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InfrastructureSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => '', 'username' => '', 'password' => '']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
    }

    private function page(): string
    {
        return $this->get('/admin/settings?tab=infra')->assertOk()->getContent();
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        return new \DOMXPath($document);
    }

    private function provider(string $slug): \DOMElement
    {
        return $this->document($this->page())->query('//details[@data-provider="'.$slug.'"]')->item(0);
    }

    private function state(string $slug): string
    {
        $document = $this->document($this->page());
        return $document->query('//details[@data-provider="'.$slug.'"]/summary/*[@data-state]')
            ->item(0)->getAttribute('data-state');
    }

    public function test_provider_rows_are_numbered_in_registry_order_and_every_accordion_starts_collapsed(): void
    {
        $html = $this->page();
        $document = $this->document($html);
        $rows = $document->query('//details[@data-provider]');
        $this->assertSame(array_keys(CloudManager::DRIVERS), array_map(
            fn ($row) => $row->getAttribute('data-provider'), iterator_to_array($rows)));
        foreach ($rows as $index => $row) {
            $summary = $document->query('./summary', $row)->item(0);
            $this->assertNotNull($summary);
            $this->assertStringContainsString(fa_num($index + 1), $summary->getAttribute('aria-label'));
            $this->assertStringContainsString(CloudManager::REAL_NAMES[$row->getAttribute('data-provider')], $summary->textContent);
        }
        foreach ($document->query('//details[contains(@class,"set-infra-item")]') as $row) {
            $this->assertFalse($row->hasAttribute('open'));
        }
        Http::assertNothingSent();
    }

    public function test_inline_connection_and_independent_pricing_forms_have_legal_distinct_owners(): void
    {
        $html = $this->page();
        $document = $this->document($html);
        preg_match_all('~<form\b|</form>~i', $html, $matches);
        $depth = 0;
        foreach ($matches[0] as $tag) {
            $depth += str_starts_with($tag, '</') ? -1 : 1;
            $this->assertLessThanOrEqual(1, $depth);
            $this->assertGreaterThanOrEqual(0, $depth);
        }
        $this->assertSame(0, $depth);
        $main = $document->query('//form[@id="infra-settings-form"]')->item(0);
        $this->assertSame('/admin/settings', $main->getAttribute('action'));
        $this->assertSame(1, $document->query('.//input[@name="_token"]', $main)->length);
        $this->assertSame(1, $document->query('.//input[@name="tab" and @value="infra"]', $main)->length);
        $legacy = array_keys(SettingsController::fieldsFor('infra'));
        foreach ($document->query('//main//input[@name] | //main//select[@name] | //main//textarea[@name]') as $field) {
            $owners = $field->hasAttribute('form')
                ? $document->query('//form[@id="'.$field->getAttribute('form').'"]')
                : $document->query('ancestor::form[1]', $field);
            $this->assertSame(1, $owners->length, 'Control has no unique form owner: '.$field->getAttribute('name'));
            if ($owners->item(0)->getAttribute('id') === 'infra-settings-form'
                && ! in_array($field->getAttribute('name'), ['tab', '_token'], true)) {
                $this->assertContains($field->getAttribute('name'), $legacy);
            }
        }
        foreach (['hetzner_api_token', 'aeza_api_token', 'arvan_api_token', 'ovh_region',
            'proxmox_node', 'salad_org', 'hetzner_robot_user', 'lightnode_manifest',
            'cloudflare_zone_id', 'agent_pull_token', 'npm_base_url', 'cloud_guard_daily_max',
            'domain_nameservers', 'cloud_traffic_unlimited'] as $name) {
            $field = $document->query('//*[@name="'.$name.'"]')->item(0);
            $this->assertNotNull($field, $name);
            $this->assertSame('infra-settings-form', $field->getAttribute('form'), $name);
        }
        $vpstcForms = $document->query('//details[@data-provider="vpstc"]//form');
        $this->assertSame(5, $vpstcForms->length);
        foreach ($vpstcForms as $form) {
            $this->assertSame(1, $document->query('.//input[@name="return_to" and @value="infra"]', $form)->length);
            $this->assertSame(1, $document->query('.//input[@name="_token"]', $form)->length);
        }
        foreach (['vpstc_currency', 'cloud_margin_pct_vpstc', 'vpstc_supplier_tax_pct',
            'pricing_fx_fee_pct_vpstc', 'vpstc_fx_rate_toman'] as $name) {
            $field = $document->query('//details[@data-provider="vpstc"]//*[@name="'.$name.'"]')->item(0);
            $this->assertNotNull($field, $name);
            $this->assertFalse($field->hasAttribute('form'));
        }
        $save = $document->query('//button[@type="submit" and @form="infra-settings-form"]')->item(0);
        $this->assertNotNull($save);
        Http::assertNothingSent();
    }

    public function test_reading_the_settings_neither_calls_providers_nor_changes_values_or_discloses_credentials(): void
    {
        foreach (['hetzner_api_token', 'aeza_api_token', 'arvan_api_token', 'salad_api_key',
            'ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key', 'proxmox_token_secret',
            'hetzner_robot_user', 'hetzner_robot_pass', 'lightnode_api_token',
            'cloud_vpstc_username', 'cloud_vpstc_password'] as $key) {
            Setting::putSecret($key, 'private-'.$key.'-fixture');
        }
        Setting::put('salad_org', 'fixture-org');
        Setting::put('cloud_vpstc_api_origin', 'https://portal.vps.tc');
        Setting::put('cloud_margin_pct_vpstc', '17');
        Setting::put('vpstc_sales_enabled', '0');
        $before = Setting::orderBy('key')->pluck('value', 'key')->all();
        $html = $this->page();
        $this->assertSame($before, Setting::orderBy('key')->pluck('value', 'key')->all());
        $this->assertStringNotContainsString('private-', $html);
        $document = $this->document($html);
        foreach ($document->query('//input[@type="password"] | //input[@name="username"]') as $input) {
            $this->assertSame('', $input->getAttribute('value'));
        }
        $this->assertSame('review', $this->state('hetzner'));
        $this->assertSame('inactive', $this->state('vpstc'));
        Http::assertNothingSent();
    }

    public function test_explicit_successful_connection_test_records_only_sanitized_evidence_and_enables_green_status(): void
    {
        Setting::putSecret('hetzner_api_token', 'fixture-provider-key');
        $this->assertSame('review', $this->state('hetzner'));
        Http::fake(['api.hetzner.cloud/v1/locations*' => Http::response(['locations' => [['id' => 1]]])]);
        $this->post('/admin/cloud/test')->assertRedirect();
        $evidence = json_decode(Setting::get('infra_connection_check_hetzner'), true);
        $this->assertSame(['version', 'ok', 'at', 'fingerprint'], array_keys($evidence));
        $this->assertTrue($evidence['ok']);
        $this->assertStringNotContainsString('fixture-provider-key', json_encode($evidence));
        $this->assertSame('active', $this->state('hetzner'));
        $this->assertStringContainsString('متصل و فعال', $this->provider('hetzner')->textContent);
        Http::assertSentCount(1);
    }

    public function test_failed_expired_or_changed_credential_evidence_never_claims_connected(): void
    {
        Setting::putSecret('hetzner_api_token', 'fixture-one');
        $status = app(InfrastructureSettingsStatus::class);
        $status->record('hetzner', false, $status->fingerprint('hetzner'));
        $this->assertSame('review', $this->state('hetzner'));
        $status->record('hetzner', true, $status->fingerprint('hetzner'));
        $this->assertSame('active', $this->state('hetzner'));
        $this->travel(InfrastructureSettingsStatus::MAX_AGE_HOURS + 1)->hours();
        $this->assertSame('review', $this->state('hetzner'));
        $this->travelBack();
        $status->record('hetzner', true, $status->fingerprint('hetzner'));
        Setting::putSecret('hetzner_api_token', 'fixture-two');
        $this->assertSame('review', $this->state('hetzner'));
        Http::assertNothingSent();
    }

    public function test_concurrent_credential_rotation_does_not_attach_old_test_success_to_the_new_key(): void
    {
        Setting::putSecret('hetzner_api_token', 'fixture-before');
        $status = app(InfrastructureSettingsStatus::class);
        $before = $status->fingerprint('hetzner');
        Setting::putSecret('hetzner_api_token', 'fixture-after');
        $status->record('hetzner', true, $before);
        $this->assertNull(Setting::get('infra_connection_check_hetzner'));
        $this->assertSame('review', $this->state('hetzner'));
        Http::assertNothingSent();
    }

    public function test_disabled_provider_remains_red_even_with_a_recent_successful_check(): void
    {
        Setting::putSecret('hetzner_api_token', 'fixture-key');
        $status = app(InfrastructureSettingsStatus::class);
        $status->record('hetzner', true, $status->fingerprint('hetzner'));
        Setting::put('cloud_disabled_providers', 'hetzner');
        $this->assertSame('inactive', $this->state('hetzner'));
        $this->assertStringContainsString('غیرفعال', $this->provider('hetzner')->textContent);
        Http::assertNothingSent();
    }

    public function test_inline_save_returns_to_infra_and_arbitrary_return_targets_are_ignored(): void
    {
        $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://portal.vps.tc',
            'username' => 'fixture-user', 'password' => 'fixture-secret', 'return_to' => 'infra'])
            ->assertRedirect('/admin/settings?tab=infra#infra-vpstc');
        $this->assertSame('fixture-user', Setting::getSecret('cloud_vpstc_username'));
        $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://portal.vps.tc', 'return_to' => 'https://evil.test'])
            ->assertRedirect('/admin/settings?tab=infra#infra-vpstc');
        Http::assertNothingSent();
    }

    public function test_inline_preflight_records_only_account_connection_evidence(): void
    {
        $preflight = $this->mock(\App\Services\Cloud\VpsTcPreflight::class);
        $preflight->shouldReceive('inspect')->once()->with(null, null)->andReturn([
            'ok' => false, 'connection_ok' => true, 'sales_ready' => false,
            'blockers' => ['account_currency_not_verified'],
        ]);
        $this->post('/admin/cloud/vpstc/preflight', ['return_to' => 'infra'])
            ->assertRedirect('/admin/settings?tab=infra#infra-vpstc');
        $row = json_decode(Setting::get('infra_connection_check_vpstc'), true);
        $this->assertTrue($row['ok']);
        $this->assertArrayNotHasKey('blockers', $row);
        $this->assertNotSame('1', Setting::get('vpstc_sales_enabled'));
        Http::assertNothingSent();
    }

    public function test_candidate_preview_prioritizes_vps_and_keeps_annual_products_out_of_the_preview_only(): void
    {
        $candidates = [];
        for ($i = 0; $i < 25; $i++) {
            $candidates[] = ['id' => (string) ($i + 1), 'name' => 'annual-domain-'.$i,
                'category_name' => 'Domains', 'monthly_price_raw' => null, 'order_checked' => false];
        }
        for ($i = 0; $i < 45; $i++) {
            $candidates[] = ['id' => (string) ($i + 26), 'name' => 'monthly-other-'.$i,
                'category_name' => 'Other', 'monthly_price_raw' => '2.00', 'order_checked' => false];
        }
        $candidates[] = ['id' => '99', 'name' => 'preferred-vps-product', 'category_name' => 'VPS Servers',
            'monthly_price_raw' => '125.55', 'order_checked' => true, 'order_monthly_raw' => '2.79', 'price_match' => false];
        $html = $this->withSession(['vpstc_report' => ['ok' => true, 'connection_ok' => true,
            'sales_ready' => false, 'blockers' => [], 'currency' => 'TRY', 'candidates' => $candidates]])->page();
        $this->assertStringContainsString('preferred-vps-product', $html);
        $this->assertStringNotContainsString('annual-domain-0', $html);
        $this->assertStringNotContainsString('monthly-other-44', $html);
        $this->assertStringContainsString('125.55', $html);
        $this->assertStringContainsString('2.79', $html);
        $this->assertStringContainsString(fa_num(46).' محصول', $html);
        Http::assertNothingSent();
    }

    public function test_preflight_shows_complete_form_scope_and_credit_covered_cost_without_claiming_free_service(): void
    {
        $html = $this->withSession(['vpstc_report' => ['connection_ok' => true,
            'order_forms_expected' => 16, 'order_forms_checked' => 16, 'order_forms_complete' => true,
            'quotes_expected' => 16, 'quotes_checked' => 16, 'quotes_arithmetic_verified' => 16,
            'candidates' => [['id' => '351', 'name' => 'Monthly VPS', 'category_name' => 'VPS',
                'monthly_price_raw' => '125.55', 'order_checked' => true, 'order_monthly_raw' => '2.79',
                'price_match' => false, 'quote_checked' => true, 'quote_diagnostics' => [
                    'gross_raw' => '3.348', 'arithmetic_match' => true,
                    'payment_coverage' => 'covered_by_credit', 'unit_verified' => false]]]]])->page();
        $this->assertStringContainsString('فرم‌های خوانده‌شده در محدودهٔ بررسی', $html);
        $this->assertStringContainsString(fa_num(16).' از '.fa_num(16).' — کامل', $html);
        $this->assertStringContainsString('3.348', $html);
        $this->assertStringContainsString('از اعتبار حساب پوشش داده می‌شود؛ رایگان نیست.', $html);
        $this->assertStringContainsString('واحد عدد خام تأیید نشده است.', $html);
        Http::assertNothingSent();
    }
}
