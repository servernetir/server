<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailMarketingCampaign;
use App\Models\EmailMarketingDelivery;
use App\Models\EmailMarketingRecipient;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Models\User;
use App\Services\Marketing\EmailMarketingCampaigns;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailMarketingCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'app.previous_keys' => [],
            'app.url' => 'https://console.example.test',
            'email-marketing.enabled' => true,
            'email-marketing.evidence_review_enabled' => true,
            'email-marketing.mailer' => 'campaign-test',
            'mail.mailers.campaign-test' => ['transport' => 'smtp'],
            'mail.default' => 'array',
            'birthday.enabled' => false,
            'marketing.sms_enabled' => false,
            'pagecache.enabled' => false,
        ]);
        Mail::spy();
        Http::preventStrayRequests();
    }

    private function service(): EmailMarketingCampaigns
    {
        return app(EmailMarketingCampaigns::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function customer(string $email): Customer
    {
        $customer = Customer::create(['email' => $email, 'status' => 'active', 'locale' => 'fa']);
        $customer->forceFill(['email_verified_at' => now()->subDay()])->save();

        return $customer;
    }

    private function evidence(string $email, User $admin): ExternalEmailConsentReview
    {
        $identity = app(EmailMarketingPreferences::class)->evidenceReviewIdentity($email);

        return ExternalEmailConsentReview::create([
            'email_digest' => $identity['digests'][0],
            'digest_key_id' => $identity['key_id'],
            'consent_date' => now()->subWeek()->toDateString(),
            'evidence_reference' => 'source-written-001',
            'imported_by' => $admin->id,
        ]);
    }

    private function decision(ExternalEmailConsentReview $review, string $decision, User $admin): void
    {
        ExternalEmailConsentReviewEvent::create([
            'review_id' => $review->id,
            'decision' => $decision,
            'reason' => $decision === 'verified' ? 'evidence_checked' : 'administrative_prevent',
            'source_kind' => $decision === 'verified' ? 'written_consent' : null,
            'review_reference' => 'written-document-2026-001',
            'reviewed_by' => $admin->id,
            'created_at' => now(),
        ]);
    }

    public function test_bulk_approval_records_one_shared_document_and_never_sends_mail(): void
    {
        $admin = $this->admin();
        $first = $this->evidence('one@external.example.test', $admin);
        $second = $this->evidence('two@external.example.test', $admin);
        // More than one table page: "all pending" must include the complete list.
        for ($number = 3; $number <= 664; $number++) {
            $this->evidence('bulk-'.$number.'@external.example.test', $admin);
        }
        $result = $this->service()->approve(null, 'written-document-2026-001', $admin->id);

        $this->assertSame(664, $result['approved']);
        $this->assertSame(664, ExternalEmailConsentReviewEvent::where('decision', 'verified')->count());
        foreach ([$first, $second] as $review) {
            $event = $review->decisions()->sole();
            $this->assertSame('written_consent', $event->source_kind);
            $this->assertSame('written-document-2026-001', $event->review_reference);
            $this->assertSame($admin->id, (int) $event->reviewed_by);
        }
        $this->assertSame(0, EmailMarketingRecipient::count());
        Mail::shouldNotHaveReceived('mailer');
        Mail::shouldNotHaveReceived('raw');
    }

    public function test_all_pending_approval_skips_previously_rejected_or_revoked_evidence(): void
    {
        $admin = $this->admin();
        $pending = $this->evidence('pending@external.example.test', $admin);
        $rejected = $this->evidence('rejected@external.example.test', $admin);
        $revoked = $this->evidence('revoked@external.example.test', $admin);
        $this->decision($rejected, 'rejected', $admin);
        $this->decision($revoked, 'revoked', $admin);

        $this->service()->approve(null, 'written-document-2026-001', $admin->id);
        $this->assertSame('verified', $pending->decisions()->latest('id')->first()->decision);
        $this->assertSame('rejected', $rejected->decisions()->latest('id')->first()->decision);
        $this->assertSame('revoked', $revoked->decisions()->latest('id')->first()->decision);
        $this->assertSame(3, ExternalEmailConsentReviewEvent::count());

        try {
            $this->service()->approve(null, 'written-document-2026-001', $admin->id);
        } catch (DomainException) {
            // A replay may be rejected as stale, but must never append a second decision.
        }
        $this->assertSame(3, ExternalEmailConsentReviewEvent::count());
    }

    public function test_approval_cannot_reconstruct_a_sendable_address_from_a_digest(): void
    {
        $admin = $this->admin();
        $this->evidence('restored@external.example.test', $admin);
        $this->service()->approve(null, 'written-document-2026-001', $admin->id);
        $summary = $this->service()->summary();
        $this->assertSame(0, $summary['external_ready']);
        $this->assertSame(1, $summary['external_missing']);

        $this->assertFalse($this->service()->restoreAddress('unmatched@external.example.test', $admin->id));
        $this->assertTrue($this->service()->restoreAddress('RESTORED@external.example.test', $admin->id));
        $this->assertTrue($this->service()->restoreAddress('restored@external.example.test', $admin->id));
        $this->assertSame(1, EmailMarketingRecipient::count());
        $this->assertSame(1, $this->service()->summary()['external_ready']);
        $this->assertStringNotContainsString('restored@external.example.test', (string) DB::table('email_marketing_recipients')->sole()->email);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_registration_is_default_eligible_but_withdrawal_remains_effective(): void
    {
        $admin = $this->admin();
        $allowed = $this->customer('registered@example.test');
        $withdrawn = $this->customer('withdrawn@example.test');
        Auth::guard('web')->logout();
        $this->actingAs($withdrawn, 'customer');
        app(EmailMarketingPreferences::class)->setConsent($withdrawn, false);
        Auth::guard('customer')->logout();
        $this->actingAs($admin, 'web');

        $this->service()->syncRegistered();
        $this->assertTrue($this->service()->eligible(EmailMarketingRecipient::where('customer_id', $allowed->id)->sole()));
        $this->assertFalse($this->service()->eligible(EmailMarketingRecipient::where('customer_id', $withdrawn->id)->sole()));
    }

    public function test_default_registration_policy_includes_valid_active_addresses_without_separate_email_verification(): void
    {
        $this->admin();
        $unverified = Customer::create(['email' => 'registration-only@example.test', 'status' => 'active', 'locale' => 'fa']);
        $closed = Customer::create(['email' => 'closed@example.test', 'status' => 'closed', 'locale' => 'fa']);
        $invalid = Customer::create(['email' => 'invalid address', 'status' => 'active', 'locale' => 'fa']);
        $this->service()->syncRegistered();

        $this->assertNull($unverified->email_verified_at);
        $this->assertTrue($this->service()->eligible(EmailMarketingRecipient::where('customer_id', $unverified->id)->sole()));
        $this->assertSame(0, EmailMarketingRecipient::whereIn('customer_id', [$closed->id, $invalid->id])->count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_registration_never_overrides_legacy_address_or_domain_suppression(): void
    {
        $this->admin();
        $blocked = $this->customer('blocked@blocked.example.test');
        $clear = $this->customer('clear@example.test');
        DB::table('crm_suppression')->insert([
            'email' => '', 'domain' => 'BLOCKED.example.test', 'reason' => 'unsubscribe',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service()->syncRegistered();

        $this->assertFalse($this->service()->eligible(EmailMarketingRecipient::where('customer_id', $blocked->id)->sole()));
        $this->assertTrue($this->service()->eligible(EmailMarketingRecipient::where('customer_id', $clear->id)->sole()));
    }

    public function test_losing_the_historical_digest_key_never_reenables_a_withdrawn_address(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('historical-stop@example.test');
        Auth::guard('web')->logout();
        $this->actingAs($customer, 'customer');
        app(EmailMarketingPreferences::class)->setConsent($customer, false);
        Auth::guard('customer')->logout();
        $this->actingAs($admin, 'web');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => []]);

        try {
            $this->service()->syncRegistered();
        } catch (DomainException) {
            // Rejecting enrollment globally is acceptable when historic stop identities cannot be checked.
        }
        foreach (EmailMarketingRecipient::all() as $recipient) {
            $this->assertFalse($this->service()->eligible($recipient));
        }
        $this->assertDatabaseHas('email_marketing_preferences', ['customer_id' => $customer->id, 'state' => 'withdrawn']);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_saving_draft_does_not_send_and_queuing_is_idempotent(): void
    {
        $admin = $this->admin();
        $this->customer('campaign@example.test');
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'customers', $admin->id);
        $this->assertSame('draft', $campaign->status);
        $this->assertSame(0, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('mailer');

        $this->service()->queue($campaign->id, $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $this->assertSame(1, EmailMarketingDelivery::where('campaign_id', $campaign->id)->count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_selected_approval_only_changes_selected_pending_records(): void
    {
        $admin = $this->admin();
        $selected = $this->evidence('selected@external.example.test', $admin);
        $untouched = $this->evidence('untouched@external.example.test', $admin);
        $this->service()->approve([$selected->id], 'written-document-2026-001', $admin->id);

        $this->assertSame('verified', $selected->decisions()->sole()->decision);
        $this->assertSame(0, $untouched->decisions()->count());
    }

    public function test_a_stale_all_pending_count_rejects_the_whole_batch(): void
    {
        $admin = $this->admin();
        $this->evidence('stale-one@external.example.test', $admin);
        $this->evidence('stale-two@external.example.test', $admin);
        try {
            $this->service()->approve(null, 'written-document-2026-001', $admin->id, 1);
            $this->fail('Stale batch must not be approved.');
        } catch (DomainException) {
            $this->assertSame(0, ExternalEmailConsentReviewEvent::count());
        }
    }

    public function test_mixed_audience_queues_only_one_delivery_per_normalized_address(): void
    {
        $admin = $this->admin();
        $this->customer('shared@example.test');
        $this->evidence('SHARED@example.test', $admin);
        $this->service()->approve(null, 'written-document-2026-001', $admin->id);
        $this->service()->restoreAddress('shared@example.test', $admin->id);
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'all', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);

        $this->assertSame(1, EmailMarketingDelivery::where('campaign_id', $campaign->id)->count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_revoked_evidence_is_checked_again_before_a_queued_delivery(): void
    {
        $admin = $this->admin();
        $review = $this->evidence('revoked-after-queue@external.example.test', $admin);
        $this->service()->approve(null, 'written-document-2026-001', $admin->id);
        $this->service()->restoreAddress('revoked-after-queue@external.example.test', $admin->id);
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'external', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $this->decision($review, 'revoked', $admin);
        $this->service()->tick(25);

        $this->assertSame('skipped', EmailMarketingDelivery::where('campaign_id', $campaign->id)->sole()->status);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_worker_records_provider_acceptance_and_includes_one_click_unsubscribe_headers(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('accepted@example.test');
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'customers', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $transport = Mockery::mock();
        $capturedBody = null;
        $capturedMessage = null;
        $transport->shouldReceive('raw')->once()->withArgs(function ($body, $callback) use (&$capturedBody, &$capturedMessage) {
            $message = new Message(new Email);
            $callback($message);
            $capturedBody = $body;
            $capturedMessage = $message->getSymfonyMessage();

            return true;
        })->andReturn($this->providerReceipt());
        Mail::shouldReceive('mailer')->once()->with('campaign-test')->andReturn($transport);

        $result = $this->service()->tick(25);
        $this->assertSame(1, $result['sent']);
        $this->assertInstanceOf(Email::class, $capturedMessage);
        $this->assertSame($customer->email, $capturedMessage->getTo()[0]->getAddress());
        $this->assertSame('ServerNet update', $capturedMessage->getSubject());
        $link = $capturedMessage->getHeaders()->get('List-Unsubscribe')->getBodyAsString();
        $this->assertStringContainsString('/email-marketing/unsubscribe/campaign/', $link);
        $this->assertStringNotContainsString($customer->email, $link);
        $this->assertStringContainsString(trim($link, '<>'), $capturedBody);
        $this->assertSame('List-Unsubscribe=One-Click', $capturedMessage->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
        $delivery = EmailMarketingDelivery::where('campaign_id', $campaign->id)->sole();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame('complete', $campaign->fresh()->status);
    }

    public function test_provider_exception_stays_unknown_and_is_never_automatically_resent(): void
    {
        $admin = $this->admin();
        $this->customer('ambiguous@example.test');
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'customers', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $transport = Mockery::mock();
        $transport->shouldReceive('raw')->once()->andThrow(new RuntimeException('ambiguous provider timeout'));
        Mail::shouldReceive('mailer')->once()->with('campaign-test')->andReturn($transport);

        $result = $this->service()->tick(25);
        $this->assertSame(1, $result['unknown']);
        $this->assertSame('unknown', EmailMarketingDelivery::where('campaign_id', $campaign->id)->sole()->status);
        $this->service()->tick(25);
        $this->service()->queue($campaign->id, $admin->id);
        $this->assertSame(1, EmailMarketingDelivery::where('campaign_id', $campaign->id)->count());
    }

    public function test_log_transport_cannot_be_reported_as_queued_for_real_delivery(): void
    {
        $admin = $this->admin();
        $this->customer('log-transport@example.test');
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'customers', $admin->id);
        config(['email-marketing.mailer' => 'log']);
        try {
            $this->service()->queue($campaign->id, $admin->id);
            $this->fail('A log mailer must not accept real delivery.');
        } catch (DomainException) {
            $this->assertSame('draft', $campaign->fresh()->status);
            $this->assertSame(0, EmailMarketingDelivery::count());
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    private function providerReceipt(): SentMessage
    {
        $email = (new Email)->from('servernet@example.test')->to('receipt@example.test')->subject('Receipt')->text('Accepted');

        return new SentMessage(new SymfonySentMessage($email, Envelope::create($email)));
    }

    public function test_unsubscribe_token_is_opaque_bound_to_a_recipient_and_idempotent(): void
    {
        $this->admin();
        $first = $this->customer('first-token@example.test');
        $second = $this->customer('second-token@example.test');
        $this->service()->syncRegistered();
        $one = EmailMarketingRecipient::where('customer_id', $first->id)->sole();
        $two = EmailMarketingRecipient::where('customer_id', $second->id)->sole();
        $token = $this->service()->token($one);
        $this->assertStringNotContainsString($first->email, $token);
        $this->assertFalse($this->service()->withdraw($token.'forged'));
        $this->assertTrue($this->service()->eligible($one));
        $this->assertTrue($this->service()->withdraw($token));
        $this->assertTrue($this->service()->withdraw($token));
        $this->service()->syncRegistered();
        $this->assertFalse($this->service()->eligible($one->fresh()));
        $this->assertTrue($this->service()->eligible($two->fresh()));
    }

    public function test_disabling_enrollment_does_not_disable_unsubscribe_or_shared_suppression(): void
    {
        $this->admin();
        $customer = $this->customer('disabled-enrollment@example.test');
        $this->service()->syncRegistered();
        $recipient = EmailMarketingRecipient::where('customer_id', $customer->id)->sole();
        $token = $this->service()->token($recipient);
        config(['email-marketing.enabled' => false, 'email-marketing.evidence_review_enabled' => false]);

        $this->assertTrue($this->service()->withdraw($token));
        $this->assertTrue($this->service()->withdraw($token));
        $this->assertFalse($this->service()->eligible($recipient->fresh()));
        $this->assertSame(1, DB::table('crm_suppression')->where('email', $customer->email)->whereNull('domain')->count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_admin_routes_require_an_admin_session(): void
    {
        $customer = $this->customer('no-admin@example.test');
        $this->actingAs($customer, 'customer');
        $this->post('/admin/email-marketing/bulk-approve', [
            'selection' => 'all_pending', 'document_reference' => 'document-2026-001', 'acknowledge' => '1',
        ])->assertRedirect();
        $this->assertSame(0, ExternalEmailConsentReviewEvent::count());
        $this->assertSame(0, EmailMarketingCampaign::count());
    }

    public function test_admin_page_exposes_bulk_approval_registration_policy_and_draft_composer(): void
    {
        $admin = $this->admin();
        $this->customer('visible-count@example.test');
        $this->evidence('pending-ui@external.example.test', $admin);
        $campaign = $this->service()->createDraft('<script>subject</script>', '<script>body</script>', 'all', $admin->id);
        $response = $this->get('/admin/email-marketing');
        $response->assertOk()
            ->assertSee(__('email-campaigns.registration_note'))
            ->assertSee(__('email-campaigns.compose'))
            ->assertSee('name="document_reference"', false)
            ->assertSee('value="all_pending"', false)
            ->assertSee('name="subject"', false)
            ->assertSee('name="body"', false)
            ->assertSee(e($campaign->subject), false)
            ->assertSee(e($campaign->body), false)
            ->assertDontSee($campaign->subject, false)
            ->assertDontSee($campaign->body, false);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_http_bulk_approval_requires_acknowledgement_and_records_the_shared_document(): void
    {
        $admin = $this->admin();
        $first = $this->evidence('http-one@external.example.test', $admin);
        $second = $this->evidence('http-two@external.example.test', $admin);
        $payload = [
            'selection' => 'all_pending', 'document_reference' => 'signed-2026-001', 'expected_count' => 2,
        ];
        $this->post('/admin/email-marketing/bulk-approve', $payload)->assertRedirect();
        $this->assertSame(0, ExternalEmailConsentReviewEvent::count());
        $this->post('/admin/email-marketing/bulk-approve', $payload + ['acknowledge' => '1'])->assertRedirect();

        foreach ([$first, $second] as $review) {
            $this->assertSame('verified', $review->decisions()->sole()->decision);
            $this->assertSame('signed-2026-001', $review->decisions()->sole()->review_reference);
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_http_csv_restoration_requires_acknowledgement_and_only_matches_existing_evidence(): void
    {
        $admin = $this->admin();
        $this->evidence('restore-http@external.example.test', $admin);
        $this->service()->approve(null, 'signed-2026-001', $admin->id);
        $contents = "email\nrestore-http@external.example.test\nunmatched@external.example.test\n";
        $this->post('/admin/email-marketing/recipients/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $contents),
        ])->assertRedirect();
        $this->assertSame(0, EmailMarketingRecipient::count());
        $this->post('/admin/email-marketing/recipients/import', [
            'file' => UploadedFile::fake()->createWithContent('contacts.csv', $contents), 'acknowledge' => '1',
        ])->assertRedirect();
        $this->assertSame(1, EmailMarketingRecipient::count());
        $this->assertSame('restore-http@external.example.test', EmailMarketingRecipient::sole()->email);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_public_unsubscribe_preview_does_not_withdraw_and_post_stops_queued_delivery(): void
    {
        $admin = $this->admin();
        $customer = $this->customer('unsubscribe-http@example.test');
        $campaign = $this->service()->createDraft('ServerNet update', 'A useful update.', 'customers', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $recipient = EmailMarketingRecipient::where('customer_id', $customer->id)->sole();
        $url = '/email-marketing/unsubscribe/campaign/'.$this->service()->token($recipient);
        Auth::guard('web')->logout();
        $this->get($url)->assertOk()->assertSee(__('email-campaigns.unsubscribe_button'));
        $this->assertNull($recipient->fresh()->withdrawn_at);
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee(__('email-campaigns.unsubscribe_done'));
        $this->assertNotNull($recipient->fresh()->withdrawn_at);
        $this->assertFalse($this->service()->eligible($recipient->fresh()));
        $this->assertSame('skipped', EmailMarketingDelivery::where('campaign_id', $campaign->id)->sole()->status);
        Mail::shouldNotHaveReceived('mailer');
    }
}
