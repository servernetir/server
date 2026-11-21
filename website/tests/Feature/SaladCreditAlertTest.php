<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\Setting;
use App\Models\SmsOutbox;
use App\Services\Bale\BaleNotifier;
use App\Services\Cloud\SaladClient;
use App\Services\Notify\SaladCreditAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaladCreditAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        Setting::putSecret('salad_api_key', 'private-provider-key');
        Setting::put('salad_org', 'test-org');
        Setting::put(SaladCreditAlert::EMAIL_SETTING, 'owner@example.test');
        config()->set('servernet.contact.email', 'support@example.test');
        config()->set('servernet.contact.notify_phone', '');
        config()->set('servernet.contact.notify_phones', []);
        config()->set('servernet.contact.notify_chat_ids', []);
        config()->set('servernet.contact.notify_chat_id', '900900');
        config()->set('services.bale.token', 'private-bot-key');
        config()->set('services.bale_safir.key', 'paid-channel-must-not-run');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Mail::fake();
        $this->fakeProvider();
    }

    private function fakeProvider(mixed $body = null, int $status = 400, bool $baleOk = true): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'api.salad.com/*' => Http::response($body ?? ['type' => 'no_credits_available', 'title' => 'Entitlement check failed.'], $status),
            '*sendMessage' => Http::response(['ok' => $baleOk], $baleOk ? 200 : 503),
        ]);
    }

    private function start(string $ref = 'sn-svc-123'): array
    {
        return app(SaladClient::class)->power($ref, 'on');
    }

    private function baleMessages(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage'))->map(fn ($pair) => $pair[0]['text'])->values()->all();
    }

    public function test_real_client_failure_sends_personal_email_and_free_bale_without_repeating_start(): void
    {
        $this->assertSame(['ok' => false, 'message' => 'Entitlement check failed.'], $this->start());
        Mail::assertSent(TemplateMail::class, fn ($m) => $m->hasTo('owner@example.test') && ! $m->hasTo('support@example.test') && str_contains($m->bodyHtml, '#123'));
        $this->assertCount(1, $this->baleMessages());
        $this->assertStringContainsString('no_credits_available', $this->baleMessages()[0]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'safir'));
    }

    public function test_repeated_failures_for_other_services_in_the_same_org_are_coalesced(): void
    {
        $this->start();
        $this->start('sn-svc-124');
        Mail::assertSentCount(1);
        $this->assertCount(1, $this->baleMessages());
        $this->travel(16)->minutes();
        $this->start();
        Mail::assertSentCount(2);
        $this->assertCount(2, $this->baleMessages());
    }

    public function test_generic_error_or_credit_words_do_not_falsely_raise_a_credit_incident(): void
    {
        $this->fakeProvider(['type' => 'invalid_request', 'title' => 'no credits available'], 400);
        $this->assertFalse($this->start()['ok']);
        Mail::assertNothingSent();
        $this->assertSame([], $this->baleMessages());
    }

    public function test_success_does_not_alert_even_with_an_unexpected_credit_type_in_the_body(): void
    {
        $this->fakeProvider(['type' => 'no_credits_available'], 202);
        $this->assertTrue($this->start()['ok']);
        Mail::assertNothingSent();
        Http::assertSentCount(1);
    }

    public function test_html_error_and_transport_failure_do_not_raise_a_false_credit_alert(): void
    {
        $this->fakeProvider('<html>no_credits_available</html>', 403);
        $this->assertFalse($this->start()['ok']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.salad.com/*' => Http::failedConnection()]);
        $this->assertFalse($this->start()['ok']);
        Mail::assertNothingSent();
        $this->assertSame([], $this->baleMessages());
    }

    public function test_smtp_failure_does_not_block_bale_or_change_the_provider_failure(): void
    {
        Mail::shouldReceive('mailer')->once()->with('smtp')->andThrow(new \RuntimeException('secret-smtp-password'));
        $this->assertFalse($this->start()['ok']);
        $this->assertCount(1, $this->baleMessages());
        Mail::swap(new MailManager($this->app));
        Mail::fake();
        $this->travel(61)->seconds();
        $this->start();
        Mail::assertSentCount(1);
        $this->assertCount(1, $this->baleMessages(), 'Successful Bale channel must stay throttled when SMTP retries.');
    }

    public function test_bale_failure_is_retried_independently_of_successful_email(): void
    {
        $this->fakeProvider(baleOk: false);
        $this->assertFalse($this->start()['ok']);
        Mail::assertSentCount(1);
        $this->assertCount(1, $this->baleMessages());
        $this->travel(61)->seconds();
        $this->fakeProvider();
        $this->start();
        Mail::assertSentCount(1);
        $this->assertCount(1, $this->baleMessages());
    }

    public function test_empty_personal_email_does_not_disable_bale_or_fall_back_to_public_contact(): void
    {
        Setting::put(SaladCreditAlert::EMAIL_SETTING, '');
        $this->start();
        Mail::assertNothingSent();
        $this->assertCount(1, $this->baleMessages());
    }

    public function test_missing_bale_target_does_not_disable_email(): void
    {
        config()->set('servernet.contact.notify_chat_id', '');
        $this->start();
        Mail::assertSentCount(1);
        Http::assertSentCount(1);
    }

    public function test_each_bale_target_has_an_independent_receipt_and_no_duplicate_destinations(): void
    {
        config()->set('servernet.contact.notify_chat_ids', ['900900', '900901', '900900']);
        $this->start();
        $this->start();
        $this->assertCount(2, $this->baleMessages());
        Mail::assertSentCount(1);
    }

    public function test_raw_provider_payload_credentials_and_untrusted_ref_never_enter_alerts(): void
    {
        $this->fakeProvider(['type' => 'no_credits_available', 'title' => 'private-provider-key', 'detail' => 'customer-password']);
        $this->start('private-customer-domain?token=customer-password');
        Mail::assertSent(TemplateMail::class, function ($mail): bool {
            foreach (['private-provider-key', 'private-bot-key', 'customer-password', 'private-customer-domain'] as $secret) {
                $this->assertStringNotContainsString($secret, $mail->bodyHtml);
            }

            return true;
        });
        foreach (['private-provider-key', 'private-bot-key', 'customer-password', 'private-customer-domain'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->baleMessages()[0]);
        }
    }

    public function test_explicit_delivery_test_is_labeled_and_does_not_suppress_a_real_incident(): void
    {
        $result = app(SaladCreditAlert::class)->testDelivery();
        $this->assertSame(['email' => 'accepted', 'bale_0' => 'accepted'], $result);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.salad.com'));
        $this->assertStringContainsString('آزمایشی', $this->baleMessages()[0]);
        $this->start();
        Mail::assertSentCount(2);
        $this->assertCount(2, $this->baleMessages());
    }

    public function test_admin_delivery_receipt_keeps_the_existing_free_iran_fallback(): void
    {
        $this->fakeProvider(baleOk: false);
        $this->assertTrue(app(BaleNotifier::class)->deliverToAdminAt('09121110000', '900900', 'Test critical alert'));
        $this->assertDatabaseHas('sms_outbox', ['destination' => '09121110000', 'event' => 'bale_only', 'status' => 'queued']);
        $this->assertSame(1, SmsOutbox::where('event', 'bale_only')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'safir'));
    }
}
