<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportConsultationController;
use App\Models\AuditReport;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\User;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportConsultationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->zeroOrMoreTimes();
    }

    private function report(string $locale = 'fa'): AuditReport
    {
        return AuditReport::fromAudit([
            'ok' => true, 'url' => 'https://shop.example.test', 'host' => 'shop.example.test',
            'overall' => 63, 'grade' => 'C', 'scores' => ['seo' => 60], 'weights' => ['seo' => 1],
            'meta' => ['title' => 'Example'], 'checks' => ['seo' => [['key' => 'title', 'status' => 'fail']]],
            'plan' => [], 'counts' => ['fail' => 3, 'warn' => 4, 'pass' => 20], 'vitals' => null,
        ], 'admin', null, $locale);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['consultation_name' => 'Site Owner', 'consultation_email' => 'owner@example.test',
            'consultation_phone' => '+989120000000', 'consultation_message' => 'Help me prioritize the report findings.',
            'consultation_consent' => '1', 'consultation_website' => ''];
    }

    private function endpoint(AuditReport $report, string $locale = 'fa'): string
    {
        return ($locale === 'fa' ? '' : '/'.$locale).'/report/'.$report->token.'/consultation';
    }

    public function test_reports_render_localized_consultation_form_without_analytics_or_chat(): void
    {
        config(['services.gtm.enabled' => true, 'services.gtm.id' => 'GTM-SECRET123']);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $report = $this->report($locale);
            $html = $this->get(($locale === 'fa' ? '' : '/'.$locale).'/report/'.$report->token)
                ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
            $this->assertStringContainsString('name="consultation_email"', $html);
            $this->assertStringContainsString('name="consultation_consent"', $html);
            $this->assertStringContainsString('name="_token"', $html);
            $this->assertStringContainsString($this->endpoint($report, $locale), $html);
            $this->assertStringContainsString(trans('conversion.report_submit', [], $locale), $html);
            $this->assertStringNotContainsString('GTM-SECRET123', $html);
            $this->assertStringNotContainsString('googletagmanager.com', $html);
            $this->assertStringNotContainsString('id="chat-panel"', $html);
            $this->assertStringNotContainsString('assets/js/chat-morph.js', $html);
            $this->assertStringContainsString('no-store', $this->get(($locale === 'fa' ? '' : '/'.$locale).'/report/'.$report->token)->headers->get('Cache-Control'));
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_request_creates_isolated_inbound_lead_with_authoritative_report_facts_only(): void
    {
        $report = $this->report();
        $this->post($this->endpoint($report), $this->payload(['score' => 1, 'host' => 'forged.test',
            'source' => 'manual', 'stage' => 'won', 'campaign_id' => 999, 'notify' => false]))
            ->assertRedirect($report->url().'#consultation')->assertSessionHas('report_consultation_success', true);
        $lead = CrmLead::sole();
        $this->assertSame('site-chat', $lead->source);
        $this->assertSame('new', $lead->stage);
        $this->assertSame('owner@example.test', $lead->email);
        $this->assertNull($lead->next_action_at);
        $this->assertNull($lead->observation);
        $this->assertFalse($lead->isContactable());
        $message = CrmMessage::sole();
        $data = json_decode($message->body, true);
        $this->assertSame('in', $message->direction);
        $this->assertSame('received', $message->status);
        $this->assertStringContainsString('report_id='.$report->id, $data['summary']);
        $this->assertStringContainsString('host=shop.example.test', $data['summary']);
        $this->assertStringContainsString('score=63', $data['summary']);
        $this->assertStringContainsString('consultation-reply-v1', $data['summary']);
        $this->assertStringNotContainsString($report->token, $message->body);
        $this->assertStringNotContainsString('forged.test', $message->body);
        foreach (['invoices', 'payments', 'services', 'credit_ledger', 'marketing_preferences', 'marketing_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_case_normalized_replay_creates_one_lead_message_and_staff_alert(): void
    {
        $report = $this->report();
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->once();
        $this->post($this->endpoint($report), $this->payload(['consultation_email' => ' OWNER@EXAMPLE.TEST ']))->assertRedirect();
        $this->post($this->endpoint($report), $this->payload())->assertRedirect();
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_distinct_followup_appends_to_the_same_lead_and_retry_does_not_repeat_alert(): void
    {
        $report = $this->report();
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->twice();
        $this->post($this->endpoint($report), $this->payload())->assertRedirect();
        $lead = CrmLead::sole();
        $originalPhone = $lead->phone;
        $followup = $this->payload(['consultation_name' => 'Updated Contact', 'consultation_phone' => '+989120000001',
            'consultation_message' => 'A distinct follow-up about the migration steps.', 'message_key' => 'forged-client-key']);
        $this->post($this->endpoint($report), $followup)->assertRedirect();
        $this->post($this->endpoint($report), $followup)->assertRedirect();
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_messages', 2);
        $this->assertSame('Site Owner', $lead->fresh()->contact_name);
        $this->assertSame($originalPhone, $lead->fresh()->phone);
        $messages = $lead->messages()->orderBy('id')->get();
        $this->assertSame(['received', 'received'], $messages->pluck('status')->all());
        $this->assertSame(['in', 'in'], $messages->pluck('direction')->all());
        $this->assertNotSame($messages[0]->provider_id, $messages[1]->provider_id);
        $this->assertLessThanOrEqual(190, strlen($messages[1]->provider_id));
        $this->assertStringNotContainsString('forged-client-key', $messages[1]->provider_id);
        $this->assertStringNotContainsString('Updated Contact', $messages[1]->provider_id);
        $this->assertStringContainsString('A distinct follow-up', $messages[1]->body);
        $this->assertStringContainsString('Updated Contact', $messages[1]->body);
        $this->assertStringContainsString('Help me prioritize', $messages[0]->body);
        $this->assertNull($lead->fresh()->next_action_at);
        $this->assertFalse($lead->fresh()->isContactable());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_same_email_on_different_reports_retains_report_attribution(): void
    {
        $first = $this->report();
        $second = $this->report();
        $this->post($this->endpoint($first), $this->payload())->assertRedirect();
        $this->post($this->endpoint($second), $this->payload())->assertRedirect();
        $this->assertDatabaseCount('crm_leads', 2);
        $this->assertDatabaseCount('crm_messages', 2);
        $this->assertNotSame(CrmLead::first()->domain_hash, CrmLead::latest('id')->first()->domain_hash);
    }

    public function test_permission_to_reply_is_required_and_does_not_accept_a_marketing_flag_instead(): void
    {
        $report = $this->report();
        $this->post($this->endpoint($report), $this->payload(['consultation_consent' => '0', 'marketing_consent' => '1']))
            ->assertSessionHasErrors(['consultation_consent'], null, 'reportConsultation');
        $this->assertDatabaseCount('crm_leads', 0);
        $this->assertDatabaseCount('crm_messages', 0);
    }

    public function test_invalid_fields_and_honeypot_create_nothing(): void
    {
        $report = $this->report();
        $this->post($this->endpoint($report), $this->payload(['consultation_email' => 'invalid',
            'consultation_phone' => str_repeat('9', 41), 'consultation_website' => 'https://bot.test']))
            ->assertRedirect($report->url().'#consultation')
            ->assertSessionHasErrors(['consultation_email', 'consultation_phone', 'consultation_website'], null, 'reportConsultation');
        $this->assertDatabaseCount('crm_leads', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_stale_report_refuses_capture_and_displays_refresh_action(): void
    {
        $report = $this->report();
        $report->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->post($this->endpoint($report), $this->payload())
            ->assertSessionHasErrors(['consultation'], null, 'reportConsultation');
        $html = $this->get('/report/'.$report->token)->assertOk()->getContent();
        $this->assertStringContainsString(__('conversion.report_refresh'), $html);
        $this->assertStringNotContainsString('name="consultation_email"', $html);
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_unknown_report_token_is_not_a_lead_or_arbitrary_site_audit(): void
    {
        $this->post('/report/'.str_repeat('a', 32).'/consultation', $this->payload())->assertNotFound();
        $this->assertDatabaseCount('crm_leads', 0);
        Http::assertNothingSent();
    }

    public function test_request_route_has_web_csrf_and_rate_limit_middleware(): void
    {
        $this->get('/report/'.$this->report()->token)->assertOk();
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->getName() === 'report.consultation');
        $this->assertNotNull($route);
        $this->assertSame(ReportConsultationController::class.'@store', $route->getActionName());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:3,1', $route->gatherMiddleware());
        $expanded = app('router')->gatherRouteMiddleware($route);
        $this->assertTrue(collect($expanded)->contains(fn ($middleware) => str_contains($middleware, 'PreventRequestForgery') || str_contains($middleware, 'VerifyCsrfToken')), json_encode($expanded));
    }

    public function test_rate_limit_does_not_create_additional_requests(): void
    {
        $report = $this->report();
        for ($i = 0; $i < 3; $i++) {
            $this->post($this->endpoint($report), $this->payload())->assertRedirect();
        }
        $this->post($this->endpoint($report), $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
    }

    public function test_staff_can_manage_request_through_existing_inbound_inbox(): void
    {
        $report = $this->report();
        $this->post($this->endpoint($report), $this->payload())->assertRedirect();
        $lead = CrmLead::sole();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/marketing/'.$lead->id)->assertOk()->assertSee('SEO consultation');
        $this->post('/admin/marketing/'.$lead->id.'/stage', ['stage' => 'reviewed', 'notes' => 'Read the report', 'version' => $lead->messages()->max('id')])
            ->assertRedirect();
        $this->assertSame('reviewed', $lead->fresh()->stage);
        $this->assertNull($lead->fresh()->next_action_at);
        $this->assertFalse($lead->fresh()->isContactable());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_reply_notification_failure_does_not_erase_a_committed_request(): void
    {
        $report = $this->report();
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->once()->andThrow(new \RuntimeException('Synthetic outage'));
        $this->post($this->endpoint($report), $this->payload())->assertRedirect()->assertSessionHas('report_consultation_success');
        $this->assertDatabaseCount('crm_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
    }

    public function test_optional_phone_and_locale_are_recorded_without_forced_marketing_preferences(): void
    {
        $report = $this->report('tr');
        $data = $this->payload();
        unset($data['consultation_phone']);
        $this->post($this->endpoint($report, 'tr'), $data)->assertRedirect();
        $this->assertSame('tr', json_decode(CrmMessage::sole()->body, true)['locale']);
        $this->assertDatabaseCount('marketing_preferences', 0);
    }

    public function test_missing_crm_schema_fails_closed_instead_of_trying_a_send(): void
    {
        $report = $this->report();
        Schema::drop('crm_messages');
        $this->post($this->endpoint($report), $this->payload())->assertServiceUnavailable();
        $this->assertDatabaseCount('crm_leads', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
