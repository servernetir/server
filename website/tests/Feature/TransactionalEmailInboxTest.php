<?php

namespace Tests\Feature;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TransactionalEmailInboxTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Test Admin', 'email' => 'email-admin@example.test', 'password' => bcrypt('test-only-password'), 'role' => 'admin']);
    }

    private function lead(string $name, string $stage = 'replied', string $source = 'transactional-web'): CrmLead
    {
        return CrmLead::create(['domain_hash' => hash('sha256', $source.$name), 'company' => $name,
            'email' => strtolower($name).'@example.test', 'phone' => '+989120000000', 'website' => 'example.test',
            'source' => $source, 'stage' => $stage, 'offer' => 'managed-email']);
    }

    private function message(CrmLead $lead, string $body): CrmMessage
    {
        return CrmMessage::create(['lead_id' => $lead->id, 'channel' => 'web', 'direction' => 'in',
            'status' => 'received', 'subject' => 'Transactional quote', 'body' => $body]);
    }

    public function test_only_admin_can_read_inbound_contacts(): void
    {
        $lead = $this->lead('PrivateBuyer'); $this->message($lead, 'PRIVATE REQUEST');
        $this->get('/admin/marketing?tab=email')->assertRedirect()->assertDontSee('PRIVATE REQUEST');
        foreach (['support', 'author'] as $role) {
            $staff = User::create(['name' => $role, 'email' => $role.'@example.test', 'password' => bcrypt('test-only-password'), 'role' => $role]);
            $this->actingAs($staff)->get('/admin/marketing?tab=email')->assertForbidden();
            $this->get('/admin/marketing/'.$lead->id)->assertForbidden();
        }
        $this->actingAs($this->admin())->get('/admin/marketing?tab=email')->assertOk()->assertSee('PrivateBuyer');
    }

    public function test_inbox_is_source_scoped_and_read_only(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $wanted = $this->lead('Wanted'); $this->message($wanted, 'INBOUND-ONLY');
        $other = $this->lead('Other', source: 'manual'); $this->message($other, 'UNRELATED-SOURCE');
        CrmMessage::create(['lead_id' => $wanted->id, 'channel' => 'web', 'direction' => 'out', 'status' => 'draft', 'body' => 'OUTBOUND-DRAFT']);
        CrmMessage::create(['lead_id' => $wanted->id, 'channel' => 'email', 'direction' => 'in', 'status' => 'received', 'body' => 'OTHER-CHANNEL']);
        $this->actingAs($this->admin())->get('/admin/marketing?tab=email')->assertOk()
            ->assertSee('INBOUND-ONLY')->assertDontSee('UNRELATED-SOURCE')->assertDontSee('OUTBOUND-DRAFT')->assertDontSee('OTHER-CHANNEL')
            ->assertViewHas('requestCount', 1)->assertViewHas('contactCount', 1);
        $this->assertDatabaseCount('crm_messages', 4);
        $this->assertSame('replied', $wanted->fresh()->stage);
        $this->assertNull($wanted->fresh()->next_action_at);
        Mail::assertNothingSent(); Http::assertNothingSent();
    }

    public function test_search_stage_and_counts_use_the_same_filtered_requests(): void
    {
        $alpha = $this->lead('Alpha', 'proposal'); $this->message($alpha, 'ERP 50000'); $this->message($alpha, 'ERP 250000');
        $beta = $this->lead('Beta', 'won'); $this->message($beta, 'ERP 10000');
        $this->actingAs($this->admin())->get('/admin/marketing?tab=email')->assertOk()->assertViewHas('requestCount', 3)->assertViewHas('contactCount', 2);
        $this->get('/admin/marketing?tab=email&q=Alpha&stage=proposal')->assertOk()->assertViewHas('requestCount', 2)->assertViewHas('contactCount', 1)->assertDontSee('Beta');
        $this->get('/admin/marketing?tab=email&q=250000')->assertOk()->assertViewHas('requestCount', 1)->assertViewHas('contactCount', 1);
        $this->get('/admin/marketing?tab=email&stage=won')->assertOk()->assertViewHas('requestCount', 1)->assertSee('Beta')->assertDontSee('Alpha');
    }

    public function test_pagination_keeps_filters_and_does_not_drop_older_requests(): void
    {
        $lead = $this->lead('Paged', 'proposal');
        for ($i = 0; $i < 35; $i++) $this->message($lead, sprintf('ERP REQUEST-%02d', $i));
        $this->actingAs($this->admin())->get('/admin/marketing?tab=email&q=ERP&stage=proposal')->assertOk()
            ->assertViewHas('requestCount', 35)->assertViewHas('requests', fn ($page) => $page->count() === 30 && str_contains($page->nextPageUrl(), 'stage=proposal') && str_contains($page->nextPageUrl(), 'q=ERP'))
            ->assertSee('REQUEST-34')->assertDontSee('REQUEST-00');
        $this->get('/admin/marketing?tab=email&q=ERP&stage=proposal&email_page=2')->assertOk()->assertViewHas('requests', fn ($page) => $page->count() === 5)->assertSee('REQUEST-00')->assertDontSee('REQUEST-34');
    }

    public function test_request_profile_escapes_content_and_preserves_generic_crm_view(): void
    {
        $lead = $this->lead('Factory'); $this->message($lead, '<script>alert("request")</script>');
        $response = $this->actingAs($this->admin())->get('/admin/marketing/'.$lead->id)->assertOk()->assertViewIs('admin.marketing.email-lead')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("request")', false)->assertSee('name="offer" value="managed-email"', false);
        foreach (['/compose', '/enrich', '/social', 'name="value"'] as $unsafeAction) $response->assertDontSee($unsafeAction, false);
        $other = $this->lead('Existing', source: 'manual');
        $this->get('/admin/marketing/'.$other->id)->assertOk()->assertViewIs('admin.marketing.lead')->assertSee('/compose', false);
    }

    public function test_invalid_filters_are_rejected_and_display_timezone_is_explicit(): void
    {
        $lead = $this->lead('Clock'); $message = $this->message($lead, 'TIME CHECK');
        $message->forceFill(['created_at' => '2026-10-24 21:30:00'])->saveQuietly();
        $this->actingAs($this->admin())->from('/admin/marketing?tab=email')->get('/admin/marketing?tab=email&stage=not-a-stage')->assertRedirect()->assertSessionHasErrors('stage');
        $this->from('/admin/marketing?tab=email')->get('/admin/marketing?tab=email&q='.str_repeat('a', 191))->assertRedirect()->assertSessionHasErrors('q');
        $this->get('/admin/marketing?tab=email')->assertOk()->assertSee('2026-10-25 01:00')->assertSee('تهران');
    }
}
