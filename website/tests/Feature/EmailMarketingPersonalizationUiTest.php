<?php

namespace Tests\Feature;

use App\Models\EmailMarketingDelivery;
use App\Models\EmailMarketingRecipient;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Models\User;
use App\Services\Marketing\EmailMarketingCampaigns;
use App\Services\Marketing\EmailMarketingPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailMarketingPersonalizationUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'app.previous_keys' => [],
            'email-marketing.enabled' => true,
            'email-marketing.evidence_review_enabled' => true,
            'email-marketing.mailer' => 'campaign-test',
            'mail.mailers.campaign-test' => ['transport' => 'smtp'],
        ]);
        Http::preventStrayRequests();
        Mail::spy();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin, 'web');
    }

    private function externalRecipient(?string $name = null): EmailMarketingRecipient
    {
        $email = 'doctor@personalization.example.test';
        $identity = app(EmailMarketingPreferences::class)->evidenceReviewIdentity($email);
        $review = ExternalEmailConsentReview::create([
            'email_digest' => $identity['digests'][0], 'digest_key_id' => $identity['key_id'],
            'consent_date' => now()->subWeek()->toDateString(), 'evidence_reference' => 'written-source-test',
            'imported_by' => $this->admin->id,
        ]);
        ExternalEmailConsentReviewEvent::create([
            'review_id' => $review->id, 'decision' => 'verified', 'reason' => 'evidence_checked',
            'source_kind' => 'written_consent', 'review_reference' => 'shared-written-test',
            'reviewed_by' => $this->admin->id, 'created_at' => now(),
        ]);
        app(EmailMarketingCampaigns::class)->restoreAddress($email, $this->admin->id);
        $recipient = EmailMarketingRecipient::sole();
        $recipient->forceFill(['full_name' => $name])->save();
        return $recipient;
    }

    public function test_name_import_remains_visible_after_addresses_are_complete(): void
    {
        $this->externalRecipient();
        $response = $this->get('/admin/email-marketing')->assertOk();
        $response->assertSee('data-campaign-metric="external_missing_names">1', false)
            ->assertSee(route('admin.email-marketing.recipients.import'), false)
            ->assertSee(__('email-campaigns.missing_names_note', ['count' => '1']))
            ->assertSee('value="info@servernet.cloud" readonly', false)
            ->assertSee('{{نام و نام خانوادگی}}')
            ->assertSee('{{لینک لغو عضویت}}');
        $this->assertSame(0, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('raw');
    }

    public function test_missing_names_are_reported_as_an_error_without_queuing(): void
    {
        $this->externalRecipient();
        $campaign = app(EmailMarketingCampaigns::class)->createDraft(
            'Smart clinic test', 'Dear {{full_name}}, {{unsubscribe_url}}', 'external', $this->admin->id
        );
        $this->post(route('admin.email-marketing.campaigns.send', ['campaign' => $campaign->id]), ['acknowledge' => '1'])
            ->assertRedirect()
            ->assertSessionHas('campaign_error', __('email-campaigns.missing_names', ['count' => 1]))
            ->assertSessionMissing('campaign_success');
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame(0, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('raw');
    }

    public function test_draft_keeps_sender_snapshot_and_escapes_template_preview(): void
    {
        $campaign = app(EmailMarketingCampaigns::class)->createDraft(
            'Preview test', 'Dear {{full_name}} <script>alert(1)</script> {{unsubscribe_url}}', 'external', $this->admin->id
        );
        $this->assertSame('info@servernet.cloud', $campaign->sender_email);
        $this->assertSame('info@servernet.cloud', $campaign->reply_to);
        $response = $this->get('/admin/email-marketing')->assertOk();
        $response->assertSee('Dear {{full_name}} &lt;script&gt;alert(1)&lt;/script&gt; {{unsubscribe_url}}', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee(__('email-campaigns.sender'));
        $this->assertSame(0, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('raw');
    }
}
