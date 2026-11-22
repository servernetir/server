<?php

namespace Tests\Feature;

use App\Models\EmailMarketingCampaign;
use App\Models\EmailMarketingDelivery;
use App\Models\EmailMarketingRecipient;
use App\Models\ExternalEmailConsentReview;
use App\Models\ExternalEmailConsentReviewEvent;
use App\Models\User;
use App\Services\Marketing\EmailMarketingCampaigns;
use App\Services\Marketing\EmailMarketingPreferences;
use App\Services\Marketing\MissingEmailRecipientNames;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailMarketingPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'app.previous_keys' => [],
            'app.url' => 'https://console.example.test',
            'email-marketing.enabled' => true,
            'email-marketing.evidence_review_enabled' => true,
            'email-marketing.mailer' => 'campaign-test',
            'mail.mailers.campaign-test' => ['transport' => 'smtp'],
            'mail.default' => 'array',
            'crm.from.email' => 'legacy-crm@example.test',
            'crm.from.name' => 'Legacy CRM',
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

    private function evidence(string $email, User $admin): ExternalEmailConsentReview
    {
        $identity = app(EmailMarketingPreferences::class)->evidenceReviewIdentity($email);
        $review = ExternalEmailConsentReview::create([
            'email_digest' => $identity['digests'][0],
            'digest_key_id' => $identity['key_id'],
            'consent_date' => now()->subWeek()->toDateString(),
            'evidence_reference' => 'doctor-consent-source-001',
            'imported_by' => $admin->id,
        ]);
        ExternalEmailConsentReviewEvent::create([
            'review_id' => $review->id, 'decision' => 'verified', 'reason' => 'evidence_checked',
            'source_kind' => 'written_consent', 'review_reference' => 'doctor-written-document-001',
            'reviewed_by' => $admin->id, 'created_at' => now(),
        ]);

        return $review;
    }

    private function recipient(string $email, ?string $fullName, User $admin): EmailMarketingRecipient
    {
        $review = $this->evidence($email, $admin);
        $this->assertTrue($this->service()->restoreAddress($email, $admin->id, $fullName));

        return EmailMarketingRecipient::where('review_id', $review->id)->sole();
    }

    private function import(string $content, User $admin): array
    {
        $file = UploadedFile::fake()->createWithContent('doctor-contacts.csv', $content);

        return $this->service()->importCsv($file->getRealPath(), $admin->id);
    }

    public function test_restored_full_name_is_encrypted_and_not_erased_by_an_email_only_restore(): void
    {
        $admin = $this->admin();
        $recipient = $this->recipient('name-storage@external.example.test', 'مریم رضایی', $admin);

        $this->assertSame('مریم رضایی', $recipient->full_name);
        $this->assertNotSame('مریم رضایی', DB::table('email_marketing_recipients')->where('id', $recipient->id)->value('full_name'));
        $this->assertStringNotContainsString('مریم رضایی', json_encode($recipient->toArray(), JSON_UNESCAPED_UNICODE));

        $this->service()->restoreAddress('name-storage@external.example.test', $admin->id);
        $this->assertSame('مریم رضایی', $recipient->fresh()->full_name);
        $this->assertSame(1, ExternalEmailConsentReviewEvent::count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_csv_import_supports_the_three_person_name_headers(): void
    {
        $admin = $this->admin();
        foreach (['name', 'full_name', 'نام و نام خانوادگی'] as $index => $header) {
            $email = 'name-header-'.$index.'@external.example.test';
            $review = $this->evidence($email, $admin);
            $result = $this->import("email,".$header."\n".$email.",مریم رضایی\n", $admin);

            $this->assertSame(1, $result['restored']);
            $recipient = EmailMarketingRecipient::where('review_id', $review->id)->sole();
            $this->assertSame('مریم رضایی', $recipient->full_name);
            $this->assertNotSame('مریم رضایی', DB::table('email_marketing_recipients')->where('id', $recipient->id)->value('full_name'));
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_conflicting_names_for_one_normalized_email_reject_the_whole_csv(): void
    {
        $admin = $this->admin();
        $existing = $this->recipient('conflict@external.example.test', 'نام قبلی', $admin);
        $this->evidence('earlier-row@external.example.test', $admin);
        try {
            $this->import("email,full_name\nearlier-row@external.example.test,نام جدید\nconflict@external.example.test,مریم رضایی\nCONFLICT@external.example.test,سارا احمدی\n", $admin);
            $this->fail('Conflicting names must not partly update contacts.');
        } catch (DomainException) {
            $this->assertSame(1, EmailMarketingRecipient::count());
            $this->assertSame('نام قبلی', $existing->fresh()->full_name);
            $this->assertSame(0, EmailMarketingCampaign::count());
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_filling_a_name_does_not_reenable_a_previous_unsubscribe(): void
    {
        $admin = $this->admin();
        $recipient = $this->recipient('withdrawn-name@external.example.test', null, $admin);
        $this->assertTrue($this->service()->withdraw($this->service()->token($recipient)));

        $this->import("email,name\nwithdrawn-name@external.example.test,مریم رضایی\n", $admin);

        $recipient->refresh();
        $this->assertSame('مریم رضایی', $recipient->full_name);
        $this->assertNotNull($recipient->withdrawn_at);
        $this->assertFalse($this->service()->eligible($recipient));
        $this->assertSame(1, DB::table('crm_suppression')->where('email', 'withdrawn-name@external.example.test')->count());
        $this->assertSame(0, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_new_campaign_snapshots_the_company_sender_and_reply_to(): void
    {
        $admin = $this->admin();
        $campaign = $this->service()->createDraft('مطب هوشمندتر؛ زمان بیشتر برای بیماران شما', 'دکتر {{full_name}} گرامی،', 'external', $admin->id);

        $this->assertSame('info@servernet.cloud', $campaign->sender_email);
        $this->assertSame('ServerNet', $campaign->sender_name);
        $this->assertSame('info@servernet.cloud', $campaign->reply_to);
        config(['crm.from.email' => 'changed-crm@example.test']);
        $this->assertSame('info@servernet.cloud', $campaign->fresh()->sender_email);
        $this->assertSame('draft', $campaign->status);
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_unknown_template_tokens_cannot_be_saved_as_a_draft(): void
    {
        $admin = $this->admin();
        try {
            $this->service()->createDraft('Smart clinic', '{{unknown_person}}', 'external', $admin->id);
            $this->fail('Unknown placeholders must not be queued or saved.');
        } catch (DomainException) {
            $this->assertSame(0, EmailMarketingCampaign::count());
            $this->assertSame(0, EmailMarketingDelivery::count());
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_queue_reports_all_missing_names_and_leaves_no_partial_delivery_queue(): void
    {
        $admin = $this->admin();
        $this->recipient('named-one@external.example.test', 'مریم رضایی', $admin);
        $this->recipient('unnamed-one@external.example.test', null, $admin);
        $this->recipient('unnamed-two@external.example.test', null, $admin);
        $campaign = $this->service()->createDraft('Smart clinic', 'دکتر {{نام و نام خانوادگی}} گرامی،', 'external', $admin->id);
        try {
            $this->service()->queue($campaign->id, $admin->id);
            $this->fail('A personalized audience with missing names must not partly queue.');
        } catch (MissingEmailRecipientNames $error) {
            $this->assertSame(2, $error->count);
            $this->assertSame('draft', $campaign->fresh()->status);
            $this->assertSame(0, EmailMarketingDelivery::count());
        }
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_personalized_messages_are_sent_individually_with_unique_unsubscribe_links(): void
    {
        $admin = $this->admin();
        $first = $this->recipient('first-doctor@external.example.test', 'مریم رضایی', $admin);
        $second = $this->recipient('second-doctor@external.example.test', 'سارا احمدی', $admin);
        $subject = 'مطب هوشمندتر؛ زمان بیشتر برای بیماران شما';
        $body = "دکتر {{نام و نام خانوادگی}} گرامی،\n\nبا احترام،\nتیم سرورنت (ServerNet)\nhttps://servernet.cloud\n\nلغو دریافت پیام‌های تبلیغاتی: {{لینک لغو عضویت}}";
        $campaign = $this->service()->createDraft($subject, $body, 'external', $admin->id);
        $this->assertSame(2, $this->service()->queue($campaign->id, $admin->id));
        $captured = [];
        $transport = Mockery::mock();
        $transport->shouldReceive('raw')->twice()->withArgs(function ($text, $callback) use (&$captured): bool {
            $message = new Message(new Email);
            $callback($message);
            $captured[] = ['body' => $text, 'message' => $message->getSymfonyMessage()];

            return true;
        })->andReturn($this->receipt());
        Mail::shouldReceive('mailer')->twice()->with('campaign-test')->andReturn($transport);

        $result = $this->service()->tick(25);

        $this->assertSame(2, $result['sent']);
        $this->assertCount(2, $captured);
        $names = [$first->email => $first->full_name, $second->email => $second->full_name];
        $links = [];
        foreach ($captured as $sent) {
            $message = $sent['message'];
            $this->assertCount(1, $message->getTo());
            $this->assertSame([], $message->getCc());
            $this->assertSame([], $message->getBcc());
            $this->assertSame('info@servernet.cloud', $message->getFrom()[0]->getAddress());
            $this->assertSame('ServerNet', $message->getFrom()[0]->getName());
            $this->assertSame('info@servernet.cloud', $message->getReplyTo()[0]->getAddress());
            $this->assertSame($subject, $message->getSubject());
            $to = $message->getTo()[0]->getAddress();
            $this->assertStringContainsString('دکتر '.$names[$to].' گرامی،', $sent['body']);
            $this->assertStringNotContainsString('{{', $sent['body']);
            $link = trim($message->getHeaders()->get('List-Unsubscribe')->getBodyAsString(), '<>');
            $this->assertSame(1, substr_count($sent['body'], $link));
            $this->assertSame(1, substr_count($sent['body'], 'لغو دریافت پیام‌های تبلیغاتی:'));
            $this->assertStringEndsWith($link, $sent['body']);
            $this->assertStringNotContainsString($to, $link);
            $this->assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')->getBodyAsString());
            $links[] = $link;
        }
        $this->assertCount(2, array_unique($links));
        $this->assertSame(2, EmailMarketingDelivery::where('campaign_id', $campaign->id)->where('status', 'sent')->count());
        $this->assertSame('complete', $campaign->fresh()->status);
    }

    public function test_a_static_message_can_still_be_queued_without_a_recipient_name(): void
    {
        $admin = $this->admin();
        $this->recipient('static-name-optional@external.example.test', null, $admin);
        $campaign = $this->service()->createDraft('ServerNet update', 'A static message.', 'external', $admin->id);

        $this->assertSame(1, $this->service()->queue($campaign->id, $admin->id));
        $this->assertSame(1, EmailMarketingDelivery::count());
        Mail::shouldNotHaveReceived('mailer');
    }

    public function test_a_name_lost_after_queueing_skips_the_message_before_contacting_the_mailer(): void
    {
        $admin = $this->admin();
        $recipient = $this->recipient('name-lost-after-queue@external.example.test', 'مریم رضایی', $admin);
        $campaign = $this->service()->createDraft('Smart clinic', 'دکتر {{full_name}} گرامی،', 'external', $admin->id);
        $this->service()->queue($campaign->id, $admin->id);
        $recipient->update(['full_name' => null]);

        $result = $this->service()->tick(25);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['unknown']);
        $delivery = EmailMarketingDelivery::where('campaign_id', $campaign->id)->sole();
        $this->assertSame('skipped', $delivery->status);
        $this->assertSame('personalization_unavailable', $delivery->reason);
        Mail::shouldNotHaveReceived('mailer');
    }

    private function receipt(): SentMessage
    {
        $email = (new Email)->from('sender@example.test')->to('recipient@example.test')->subject('Accepted')->text('Accepted');

        return new SentMessage(new SymfonySentMessage($email, Envelope::create($email)));
    }
}
