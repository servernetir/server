<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\WalletRefundRequest;
use App\Services\Bale\BaleNotifier;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\BaleManagerPolicy;
use App\Services\Notify\CustomerNotifier;
use App\Services\Notify\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BaleManagerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private \ArrayObject $emails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emails = new \ArrayObject;
        Mail::shouldReceive('mailer')->with('smtp')->andReturnSelf();
        Mail::shouldReceive('raw')->andReturnUsing(function ($text, $callback) { $this->emails[] = $text; });
        config()->set('servernet.contact.notify_phone', '09120000000');
        config()->set('servernet.contact.notify_chat_id', '100001');
        config()->set('servernet.contact.email', 'manager@example.invalid');
    }

    private function capture(): \ArrayObject
    {
        $messages = new \ArrayObject;
        $bale = \Mockery::mock(BaleNotifier::class);
        $bale->shouldReceive('toAdminButtonsAt')->andReturnUsing(function ($phone, $chat, $text, $buttons) use ($messages) {
            $messages[] = ['text' => $text, 'buttons' => $buttons];
            return true;
        });
        $bale->shouldReceive('toAdminAt')->andReturnUsing(function ($phone, $chat, $text) use ($messages) {
            $messages[] = ['text' => $text, 'buttons' => []];
        });
        $this->app->instance(BaleNotifier::class, $bale);
        return $messages;
    }

    public function test_default_is_legacy_and_existing_notice_still_reaches_bale(): void
    {
        $messages = $this->capture();
        $this->assertFalse(BaleManagerPolicy::enabled());
        app(AdminNotifier::class)->event('مشتریِ جدید ثبت‌نام کرد', ['نام' => 'fixture']);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('fixture', $messages[0]['text']);
    }

    public function test_new_policy_silences_low_value_manager_bale_but_preserves_email(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        app(AdminNotifier::class)->event('مشتریِ جدید ثبت‌نام کرد', ['نام' => 'fixture']);
        app(AdminNotifier::class)->event('پرداختِ موفق', ['مبلغ' => '100']);
        $this->assertCount(0, $messages);
        $this->assertCount(2, $this->emails);
    }

    public function test_customer_notifications_survive_manager_suppression(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        $customer = Customer::create(['email' => 'customer@example.invalid', 'phone' => '09121111111',
            'password' => bcrypt('fixture'), 'status' => 'active', 'locale' => 'fa']);
        $delivery = \Mockery::mock(CustomerNotifier::class);
        $delivery->shouldReceive('templated')->once()->with($customer, 'invoice', ['number' => 'FIX-1', 'amount' => '100', 'link' => '/fixture'], 'customer-original');
        $this->app->instance(CustomerNotifier::class, $delivery);
        app(Notifier::class)->fire('invoice', $customer, ['number' => 'FIX-1', 'amount' => '100', 'link' => '/fixture'], 'customer-original');
        $this->assertCount(0, $messages);
        $this->assertCount(1, $this->emails);
    }

    public function test_critical_receipts_kyc_refunds_security_and_contracts_keep_original_buttons(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        $buttons = [[['text' => 'بررسی', 'data' => 'fixture-only']]];
        foreach (['کاربر نیازمندِ تأییدِ هویت', 'رسیدِ واریز، در انتظارِ تأیید', 'درخواست عودت موجودی کیف پول',
            'کدِ تأییدِ نادرست در رباتِ بله', 'قراردادِ رجیستریِ امضانشده — پیش از فروش رسیدگی کنید'] as $title) {
            app(AdminNotifier::class)->event($title, ['پرونده' => 'fixture-only'], null, '⚠️', $buttons);
        }
        $this->assertCount(5, $messages);
        foreach ($messages as $message) { $this->assertSame($buttons, $message['buttons']); }
    }

    public function test_policy_covers_both_explicit_keys_and_legacy_titles(): void
    {
        $policy = new BaleManagerPolicy;
        foreach (['admin.order_new', 'admin.order_licence', 'admin.order_builder', 'admin.order_cloud_hourly',
            'admin.cloud_ordered', 'admin.manual_lifecycle', 'admin.dns_zone_failed', 'event:invoice', 'event:service_ordered',
            'event:payment_due', 'event:renewed', 'event:suspended', 'event:terminated', 'event:data_deletion_due',
            'event:ticket_new', 'event:ticket_customer_reply'] as $key) {
            $this->assertFalse($policy->decide($key, 'editable-title', [])['send'], $key);
        }
        $this->assertFalse($policy->decide(null, 'سرویس به‌خاطرِ عدمِ تمدید غیرفعال شد', [])['send']);
        $this->assertFalse($policy->decide(null, 'دامنه منقضی شد', [])['send']);
        $this->assertSame('daily', $policy->decide('admin.customer_new', 'editable-title', [])['mode']);
        $this->assertSame('daily', $policy->decide(null, 'وب‌سرویس احراز هویت', [])['mode']);
        $this->assertTrue($policy->decide(null, 'unknown-new-security-event', [])['send']);
    }

    public function test_catalog_price_increases_are_daily_while_margin_risk_stays_immediate(): void
    {
        $policy = new BaleManagerPolicy;
        $this->assertSame('daily', $policy->decide('admin.cloud_cost_up', 'editable title', [])['mode']);
        $this->assertSame('daily', $policy->decide(null, 'بهایِ زیرساخت گران شد', [])['mode']);
        $risk = $policy->decide('admin.hourly_underwater', 'editable title', ['#88' => 'SN-TEST · Hetzner · هزینه فعلی']);
        $this->assertTrue($risk['send']);
        foreach (['#88', 'Hetzner', 'SN-TEST', 'برآورد کاتالوگ', 'فاکتور تأمین‌کننده'] as $part) {
            $this->assertStringContainsString($part, $risk['text']);
        }
    }

    public function test_dynamic_calendar_growth_and_translated_identity_move_to_daily_without_losing_emails(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        foreach (['یادآوری تقویم — ۳ مورد پیشِ رو', 'AI Growth: محتوا آماده بازبینی است',
            'AI Growth: خطای پردازش؛ بررسی رسید لازم است', 'وب‌سرویس احراز هویت'] as $title) {
            app(AdminNotifier::class)->event($title, ['تعداد' => 3]);
        }
        app()->setLocale('en');
        $this->assertSame('daily', (new BaleManagerPolicy)->decide(null, __('identity.menu'), [])['mode']);
        $this->assertSame('daily', (new BaleManagerPolicy)->decide(null, 'changed identity title', [], 'https://console.servernet.cloud/admin/identity-api')['mode']);
        $this->assertCount(0, $messages);
        $this->assertCount(4, $this->emails);
    }

    public function test_source_key_does_not_change_existing_admin_template_or_email_preferences(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        NotificationTemplate::create(['key' => 'admin.payment_ok', 'title' => 'fixture', 'group' => 'admin_money',
            'audience' => 'admin', 'is_active' => false]);
        app(AdminNotifier::class)->event('پرداختِ موفق', ['مبلغ' => '100'], key: 'event:paid');
        $this->assertCount(0, $messages);
        $this->assertCount(0, $this->emails);
    }

    public function test_health_incident_is_short_keeps_actual_reasons_and_has_next_action(): void
    {
        $decision = (new BaleManagerPolicy)->decide(null, '🔴 خودکارسازی مشکل دارد', [
            'صف تحویل' => 'fixture queue stalled password=fixture-private', 'دیتابیس' => 'fixture database timeout',
            'کرون' => 'fixture heartbeat stale', 'ایمیل' => 'fixture SMTP unavailable'], '/admin/errors');
        $this->assertTrue($decision['send']);
        $this->assertStringContainsString('fixture queue stalled', $decision['text']);
        $this->assertStringContainsString('اقدام:', $decision['text']);
        $this->assertStringContainsString('/admin/errors', $decision['text']);
        $this->assertStringNotContainsString('fixture-private', $decision['text']);
        $this->assertStringNotContainsString('fixture SMTP unavailable', $decision['text']);
    }

    public function test_chat_lead_review_button_is_gated_and_requires_an_exact_numeric_card_route(): void
    {
        $messages = $this->capture();
        $notifier = app(AdminNotifier::class);
        $notifier->baleEvent('سرنخ', ['نام' => 'fixture'], 'https://console.servernet.cloud/admin/marketing/123');
        $this->assertSame([], $messages[0]['buttons']);
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $notifier->baleEvent('سرنخ', ['نام' => 'fixture'], 'https://console.servernet.cloud/admin/marketing/123');
        $this->assertSame('v1:ld:123', $messages[1]['buttons'][0][0]['data']);
        $this->assertStringContainsString('/admin/marketing/123', $messages[1]['text']);
        $notifier->baleEvent('سرنخ', ['نام' => 'fixture'], 'https://console.servernet.cloud/admin/marketing/not-a-card');
        $this->assertSame([], $messages[2]['buttons']);
    }

    public function test_refund_push_opens_the_exact_existing_read_only_card_and_does_not_approve(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        $customer = Customer::create(['email' => 'refund@example.invalid', 'phone' => '09122222222',
            'password' => bcrypt('fixture'), 'status' => 'active', 'locale' => 'fa']);
        $request = WalletRefundRequest::create(['public_id' => '11111111-1111-4111-8111-111111111111',
            'payload_hash' => str_repeat('a', 64), 'customer_id' => $customer->id, 'bank_snapshot' => [],
            'amount_irt' => 100, 'status' => 'requested', 'customer_note' => 'fixture']);
        app(AdminNotifier::class)->event('درخواست عودت موجودی کیف پول', ['درخواست' => $request->public_id],
            'https://console.servernet.cloud/admin/wallet-refunds/'.$request->public_id);
        $this->assertSame('v1:rf:'.$request->id, $messages[0]['buttons'][0][0]['data']);
        $this->assertSame('requested', $request->fresh()->status);
        app(AdminNotifier::class)->event('درخواست عودت موجودی کیف پول', [],
            'https://console.servernet.cloud/admin/wallet-refunds/22222222-2222-4222-8222-222222222222');
        $this->assertSame([], $messages[1]['buttons']);
    }

    public function test_bank_receipt_push_has_review_evidence_and_customer_cards_only_with_valid_source_ids(): void
    {
        Setting::put(BaleManagerPolicy::SETTING, '1');
        $messages = $this->capture();
        $notifier = app(AdminNotifier::class);
        $notifier->event('رسیدِ واریز، در انتظارِ تأیید', ['رسید' => 123, 'شناسه مشتری' => 456], key: 'event:bank_receipt');
        $this->assertSame('v1:r:123', $messages[0]['buttons'][0][0]['data']);
        $this->assertSame('v1:rd:123', $messages[0]['buttons'][0][1]['data']);
        $this->assertSame('v1:c:456', $messages[0]['buttons'][1][0]['data']);
        foreach ([null, 0, -1, true, '123:456', '99999999999999999999999999999'] as $invalid) {
            $notifier->event('رسیدِ واریز، در انتظارِ تأیید', ['رسید' => $invalid, 'شناسه مشتری' => 456], key: 'event:bank_receipt');
        }
        foreach (array_slice($messages->getArrayCopy(), 1) as $message) { $this->assertSame([], $message['buttons']); }
        $this->assertCount(7, $this->emails);
    }

    public function test_paid_domain_failure_stays_urgent_and_mentions_registrar_funding_check(): void
    {
        $decision = (new BaleManagerPolicy)->decide('admin.domain_register_failed', 'old-title', [
            'دامنه' => 'fixture.example', 'مشتری' => 'fixture SN-100', 'علت' => 'insufficient balance', 'رجیسترار' => 'fixture-registrar']);
        $this->assertTrue($decision['send']);
        $this->assertStringContainsString('موجودی حساب رجیسترار', $decision['text']);
        $this->assertStringContainsString('fixture.example', $decision['text']);
        $this->assertStringContainsString('fixture-registrar', $decision['text']);
    }

    public function test_delivery_context_uses_exact_service_only_and_omits_secrets(): void
    {
        $seen = [];
        $policy = new BaleManagerPolicy(function ($id) use (&$seen) {
            $seen[] = $id;
            return ['customer' => 'fixture SN-100', 'provider' => 'fixture-provider'];
        });
        $decision = $policy->decide('event:service_failed', 'old-title', ['سرویس' => '#۶۵۸ — fixture',
            'علت' => "connection failed password=fixture-private Bearer fixture-token\nsecond-line {\"api_key\":\"fixture-json-key\"}"]);
        $this->assertSame([658], $seen);
        $this->assertStringContainsString('SN-100', $decision['text']);
        $this->assertStringContainsString('fixture-provider', $decision['text']);
        $this->assertStringNotContainsString('fixture-private', $decision['text']);
        $this->assertStringNotContainsString('fixture-token', $decision['text']);
        $this->assertStringNotContainsString('fixture-json-key', $decision['text']);
        $this->assertStringContainsString('اقدام:', $decision['text']);
    }

    public function test_exact_incident_is_coalesced_but_different_service_provider_and_cause_are_immediate(): void
    {
        Cache::flush();
        $policy = new BaleManagerPolicy(fn ($id) => []);
        $rows = ['سرویس' => '#658', 'زیرساخت' => 'fixture-A', 'علت' => 'timeout'];
        $first = $policy->decide('event:service_failed', 'title', $rows);
        $this->assertTrue($policy->claim($first));
        $this->assertFalse($policy->claim($first));
        foreach ([['سرویس' => '#659'], ['زیرساخت' => 'fixture-B'], ['علت' => 'capacity']] as $different) {
            $this->assertTrue($policy->claim($policy->decide('event:service_failed', 'title', array_replace($rows, $different))));
        }
        $longCause = str_repeat('same prefix ', 30);
        $this->assertTrue($policy->claim($policy->decide('event:service_failed', 'title', array_replace($rows, ['علت' => $longCause.'cause-A']))));
        $this->assertTrue($policy->claim($policy->decide('event:service_failed', 'title', array_replace($rows, ['علت' => $longCause.'cause-B']))));
        $this->travel(91)->seconds();
        $this->assertTrue($policy->claim($first));
    }

    public function test_unidentified_or_aggregate_incidents_are_not_coalesced(): void
    {
        $policy = new BaleManagerPolicy;
        $decision = $policy->decide('admin.cloud_stuck', 'title', ['#658' => 'fixture customer; timeout', '#659' => 'another customer; timeout']);
        $this->assertNull($decision['dedup_key']);
        $this->assertTrue($policy->claim($decision));
        $this->assertTrue($policy->claim($decision));
        $this->assertStringContainsString('#658', $decision['text']);
        $this->assertStringContainsString('#659', $decision['text']);
    }

    public function test_registrar_funding_attempts_wait_six_hours_but_changed_causes_and_verified_recovery_are_immediate(): void
    {
        Cache::flush();
        $policy = new BaleManagerPolicy;
        $rows = ['دامنه' => 'fixture.example', 'مشتری' => 'fixture SN-100', 'رجیسترار' => 'Openprovider', 'علت' => 'insufficient credit'];
        $first = $policy->decide('admin.domain_register_failed', 'title', $rows);
        $this->assertTrue($policy->claim($first));
        $this->travel(91)->seconds();
        $this->assertFalse($policy->claim($first));
        $changed = $policy->decide('admin.domain_register_failed', 'title', array_replace($rows, ['علت' => 'balance too low']));
        $this->assertTrue($policy->claim($changed));
        $this->assertFalse($policy->claim($changed));
        BaleManagerPolicy::clearDomainFundingAttempt('fixture.example', 'fixture SN-100');
        $this->assertTrue($policy->claim($changed));
        $this->travel(6)->hours();
        $this->assertTrue($policy->claim($changed));
    }
}
