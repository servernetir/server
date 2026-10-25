<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ChatLeadController;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\User;
use App\Services\Crm\ChatLeadCapture;
use App\Services\Crm\OutreachComposer;
use App\Services\Crm\OutreachMailer;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatLeadInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.chat_leads_token' => str_repeat('test-only-', 5)]);
        Http::preventStrayRequests(); Mail::fake();
    }

    private function payload(string $id = '1'): array
    {
        return ['external_id' => 'chat_leads:'.$id, 'ticket' => 'SN-123456',
            'name' => 'Chat Buyer', 'phone' => '09120000000', 'email' => 'buyer@example.test',
            'locale' => 'fa', 'summary' => 'نیاز به سرور برای ERP', 'occurred_at' => '2026-10-24T21:30:00Z'];
    }

    private function ingest(array $data)
    {
        return $this->withToken(config('services.n8n.chat_leads_token'))->postJson('/api/internal/chat-leads', $data);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::create(['name' => 'Internal Admin', 'email' => $role.'@example.test',
            'password' => bcrypt('test-only'), 'role' => $role]);
    }

    private function capture(string $id = '1'): CrmLead
    {
        return app(ChatLeadCapture::class)->capture($this->payload($id) + ['notify' => false])['lead'];
    }

    public function test_ingestion_requires_dedicated_credential_even_with_admin_session(): void
    {
        $this->postJson('/api/internal/chat-leads', $this->payload())->assertUnauthorized();
        $this->actingAs($this->admin())->postJson('/api/internal/chat-leads', $this->payload())->assertUnauthorized();
        $this->withToken('wrong-token')->postJson('/api/internal/chat-leads', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('crm_leads', 0);
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/internal/chat-leads', 'POST'));
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_registration_retry_keeps_one_record_and_one_alert_without_duplicate_email(): void
    {
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->once()
            ->with('سرنخ جدید از گفتگوی سایت', \Mockery::on(fn ($rows) => $rows['تماس'] === '09120000000'),
                \Mockery::on(fn ($url) => str_starts_with($url, 'https://console.servernet.cloud/admin/marketing/')));
        $first = $this->ingest($this->payload())->assertCreated()->assertJson(['ok' => true, 'created' => true]);
        $this->ingest($this->payload())->assertOk()->assertJson(['created' => false, 'reference' => $first->json('reference')]);
        $this->assertDatabaseCount('crm_leads', 1); $this->assertDatabaseCount('crm_messages', 1);
        $lead = CrmLead::first();
        $this->assertSame('site-chat', $lead->source); $this->assertSame('new', $lead->stage);
        $this->assertNull($lead->next_action_at); $this->assertNull($lead->observation);
        Mail::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_distinct_registrations_do_not_collapse_shared_mailboxes_or_overwrite_other_sources(): void
    {
        $original = CrmLead::create(['domain_hash' => CrmLead::hashFor('example.test'), 'company' => 'Existing', 'source' => 'manual']);
        $this->ingest($this->payload('1') + ['notify' => false])->assertCreated();
        $this->ingest($this->payload('2') + ['notify' => false])->assertCreated();
        $this->assertDatabaseCount('crm_leads', 3);
        $this->assertSame('Existing', $original->fresh()->company);
    }

    public function test_bale_alert_uses_all_existing_admin_destinations_without_email(): void
    {
        config(['servernet.contact.notify_phones' => [], 'servernet.contact.notify_chat_ids' => ['chat-a', 'chat-b'],
            'servernet.contact.notify_phone' => '', 'servernet.contact.notify_chat_id' => '']);
        $bale = $this->mock(\App\Services\Bale\BaleNotifier::class);
        foreach (['chat-a', 'chat-b'] as $chat) {
            $bale->shouldReceive('toAdminAt')->once()->with('', $chat,
                \Mockery::on(fn ($text) => str_contains($text, 'سرنخ جدید') && str_contains($text, '/admin/marketing/1')));
        }
        app(AdminNotifier::class)->baleEvent('سرنخ جدید', ['نام' => 'Test'], 'https://console.servernet.cloud/admin/marketing/1');
        Mail::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_bad_payload_and_missing_contact_are_rejected(): void
    {
        $data = $this->payload(); unset($data['phone'], $data['email']);
        $this->ingest($data)->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
        $this->ingest(array_replace($this->payload(), ['locale' => 'xx', 'external_id' => '../bad']))->assertUnprocessable();
        $this->ingest(array_replace($this->payload(), ['summary' => str_repeat('x', 10001)]))->assertUnprocessable();
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_import_does_not_alert_and_notification_failure_keeps_registered_lead(): void
    {
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->once()->andThrow(new \RuntimeException('fake channel outage'));
        $this->ingest($this->payload('old') + ['notify' => false])->assertCreated();
        $this->ingest($this->payload('new'))->assertCreated();
        $this->assertDatabaseCount('crm_leads', 2);
    }

    public function test_only_admin_can_read_or_change_private_chat_leads(): void
    {
        $lead = $this->capture();
        $this->get('/admin/marketing?tab=chat')->assertRedirect()->assertDontSee('buyer@example.test');
        foreach (['support', 'author'] as $role) {
            $this->actingAs($this->admin($role))->get('/admin/marketing?tab=chat')->assertForbidden();
            $this->get('/admin/marketing/'.$lead->id)->assertForbidden();
            $this->post('/admin/marketing/'.$lead->id.'/stage', ['stage' => 'reviewed', 'version' => 1])->assertForbidden();
        }
    }

    public function test_search_filter_pagination_and_counts_are_source_scoped(): void
    {
        for ($i = 1; $i <= 32; $i++) $this->capture((string) $i);
        CrmLead::create(['domain_hash' => hash('sha256', 'other'), 'company' => 'OTHER-SOURCE', 'source' => 'manual']);
        $this->actingAs($this->admin())->get('/admin/marketing?tab=chat&q=ERP&stage=new')->assertOk()
            ->assertViewHas('total', 32)->assertViewHas('leads', fn ($page) => $page->count() === 30 && str_contains($page->nextPageUrl(), 'tab=chat'))
            ->assertDontSee('OTHER-SOURCE')->assertSee('تهران');
        $this->get('/admin/marketing?tab=chat&q=ERP&stage=new&page=2')->assertOk()->assertViewHas('leads', fn ($page) => $page->count() === 2);
        $this->get('/admin/marketing?tab=chat&stage=won')->assertOk()->assertViewHas('total', 0);
        $this->from('/admin/marketing?tab=chat')->get('/admin/marketing?tab=chat&stage=invalid')->assertRedirect()->assertSessionHasErrors('stage');
    }

    public function test_status_notes_and_actor_are_logged_without_sending_and_stale_edits_conflict(): void
    {
        $lead = $this->capture(); $version = $lead->messages()->max('id');
        $this->actingAs($this->admin())->from('/admin/marketing/'.$lead->id)
            ->post('/admin/marketing/'.$lead->id.'/stage', ['stage' => 'reviewed', 'notes' => 'بررسی نیاز ERP', 'version' => $version])->assertRedirect();
        $this->assertSame('reviewed', $lead->fresh()->stage);
        $this->assertSame('بررسی نیاز ERP', $lead->fresh()->notes);
        $history = $lead->messages()->where('direction', 'internal')->firstOrFail();
        $entry = json_decode($history->body, true);
        $this->assertSame('Internal Admin', $entry['admin_name']); $this->assertSame('بررسی شده', $entry['to']);
        $this->assertNull($lead->fresh()->next_action_at);
        $this->post('/admin/marketing/'.$lead->id.'/stage', ['stage' => 'won', 'version' => $version])->assertConflict();
        $this->assertSame('reviewed', $lead->fresh()->stage);
        $this->assertSame(0, $lead->outbound()->count()); Mail::assertNothingSent(); Http::assertNothingSent();
        // Sync retries cannot reset the administrator's work.
        $this->ingest($this->payload() + ['notify' => false])->assertOk();
        $this->assertSame('reviewed', $lead->fresh()->stage);
    }

    public function test_closing_and_reopening_updates_dates_and_preserves_history(): void
    {
        $lead = $this->capture(); $this->actingAs($this->admin());
        foreach (['won', 'lost', 'new'] as $stage) {
            $this->post('/admin/marketing/'.$lead->id.'/stage', ['stage' => $stage, 'reason' => 'تصمیم مشتری', 'version' => $lead->messages()->max('id')])->assertRedirect();
            $lead->refresh(); $this->assertSame($stage, $lead->stage); $this->assertNull($lead->next_action_at);
            $this->assertSame($stage === 'won', $lead->won_at !== null); $this->assertSame($stage === 'lost', $lead->lost_at !== null);
        }
        $this->assertSame(3, $lead->messages()->where('direction', 'internal')->count());
    }

    public function test_untrusted_summary_and_internal_notes_are_escaped(): void
    {
        $data = $this->payload(); $data['summary'] = '<script>alert("lead")</script>';
        $lead = app(ChatLeadCapture::class)->capture($data + ['notify' => false])['lead'];
        $this->actingAs($this->admin())->get('/admin/marketing/'.$lead->id)->assertOk()->assertViewIs('admin.marketing.chat-lead')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("lead")', false)->assertDontSee('/compose', false);
        $this->get('/admin/marketing?tab=chat')->assertOk()->assertSee('&lt;script&gt;', false);
    }

    public function test_chat_leads_are_not_enriched_composed_or_sent_by_outreach(): void
    {
        $lead = $this->capture(); $lead->update(['website' => 'https://example.test', 'observation' => 'test observation', 'next_action_at' => now()->subDay()]);
        $this->assertFalse($lead->isContactable());
        $this->assertFalse(app(OutreachComposer::class)->enrich($lead));
        $this->assertNull(app(OutreachComposer::class)->compose($lead));
        $this->assertNull(app(OutreachComposer::class)->draftSocial($lead, 'linkedin'));
        $message = CrmMessage::create(['lead_id' => $lead->id, 'channel' => 'email', 'direction' => 'out', 'status' => 'queued', 'body' => 'MUST NOT SEND']);
        $this->assertFalse(app(OutreachMailer::class)->sendOne($message));
        $this->assertSame('skipped', $message->fresh()->status);
        $this->artisan('crm:queue')->assertSuccessful(); $this->artisan('crm:enrich')->assertSuccessful();
        Mail::assertNothingSent(); Http::assertNothingSent();
    }
}
