<?php

namespace Tests\Feature;

use App\Models\GrowthDocument;
use App\Models\GrowthJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\HermesSupervisor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HermesSupervisorTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private int $fixture = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(6, 15));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        $this->operator = User::factory()->create(['role' => 'admin']);
        Setting::put('growth_autopilot_actor_id', (string) $this->operator->id);
        Setting::put('hermes_supervisor_enabled', '1');
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true]);
    }

    private function document(string $status, mixed $reviewAt, array $extra = []): GrowthDocument
    {
        $id = ++$this->fixture;

        return GrowthDocument::create($extra + ['title' => 'Runbook '.$id, 'source' => 'Synthetic fixture', 'body' => 'Internal test guidance '.$id,
            'sha256' => hash('sha256', 'supervisor-fixture-'.$id), 'status' => $status, 'review_at' => $reviewAt,
            'classification' => 'internal', 'scope' => 'general', 'verified_by' => $status === 'verified' ? $this->operator->id : null,
            'verified_at' => $status === 'verified' ? now()->subDay() : null]);
    }

    private function job(array $extra = []): GrowthJob
    {
        $id = ++$this->fixture;
        $item = app(ContentWorkflow::class)->create(['title' => 'Supervisor content '.$id, 'brief' => 'Synthetic public fixture',
            'locale' => 'en', 'channel' => 'manual', 'format' => 'post', 'objective' => 'education'], $this->operator->id);

        return GrowthJob::create($extra + ['request_key' => hash('sha256', 'supervisor-job-'.$id), 'content_id' => $item->id,
            'kind' => 'text', 'payload' => ['messages' => [['content' => 'Private payload must never leave supervision']]],
            'content_version' => 1, 'max_cost_irt' => 100, 'actual_cost_irt' => null, 'status' => 'failed', 'attempts' => 1]);
    }

    /** @return list<string> */
    private function observeWrites(callable $operation): array
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $operation();

        return $writes;
    }

    public function test_dry_run_and_status_do_not_write_or_expire_knowledge(): void
    {
        $expired = $this->document('verified', now()->subMinute());
        $writes = $this->observeWrites(function () {
            $supervisor = app(HermesSupervisor::class);
            $status = $supervisor->status();
            $receipt = $supervisor->tick(true);
            $this->assertTrue($status['active']);
            $this->assertTrue($receipt['dry_run']);
            $this->assertSame([], $receipt['executed_actions']);
        });
        $this->assertSame([], $writes);
        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 0);
        $this->assertDatabaseCount('growth_audits', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_receipt_contains_program_boundaries_without_granting_dispatch_rights(): void
    {
        $receipt = app(HermesSupervisor::class)->tick();
        $this->assertSame('hermes-growth:2026-10-26', $receipt['campaign_policy']['daily_key']);
        $this->assertSame(5, $receipt['campaign_policy']['new_contact_cap']);
        $this->assertSame(2, $receipt['campaign_policy']['followup_cap']);
        $this->assertSame(6, $receipt['section_oversight']['coverage_count']);
        $this->assertFalse($receipt['section_oversight']['learning_assessed']);
        $this->assertFalse($receipt['campaign_policy']['human_review_may_be_impersonated']);
        $this->assertSame('knowledge_expiry_and_aggregate_supervision_only', $receipt['scope']);
        Mail::assertNothingSent();
    }

    public function test_only_expired_verified_documents_change_and_action_is_verified(): void
    {
        $expired = $this->document('verified', now()->subMinute());
        $candidate = $this->document('candidate', now()->subDay());
        $current = $this->document('verified', now()->addDay());
        $undated = $this->document('verified', null);
        $rejected = $this->document('rejected', now()->subDay());
        $outdated = $this->document('outdated', now()->subDay());
        $receipt = app(HermesSupervisor::class)->tick();

        $this->assertSame('outdated', $expired->fresh()->status);
        $this->assertSame('candidate', $candidate->fresh()->status);
        $this->assertSame('verified', $current->fresh()->status);
        $this->assertSame('verified', $undated->fresh()->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('outdated', $outdated->fresh()->status);
        $this->assertSame([['action' => 'knowledge_expire', 'count' => 1]], $receipt['executed_actions']);
        $this->assertSame(0, $receipt['verification']['knowledge_expired_remaining']);
        $this->assertTrue($receipt['verification']['count_matches']);
        $this->assertNotNull($expired->fresh()->verified_by);
        $this->assertDatabaseCount('growth_automation_runs', 1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_same_hour_replay_preserves_receipt_and_does_not_repeat_effects(): void
    {
        $this->document('verified', now()->subMinute());
        $supervisor = app(HermesSupervisor::class);
        $first = $supervisor->tick();
        $stored = DB::table('growth_automation_runs')->where('run_key', $first['run_key'])->first();
        $auditCount = DB::table('growth_audits')->count();
        $second = $supervisor->tick();

        $this->assertSame('already_processed', $second['status']);
        $this->assertSame($first['run_key'], $second['run_key']);
        $this->assertSame($first['status'], $second['prior_status']);
        $this->assertDatabaseCount('growth_automation_runs', 1);
        $this->assertSame($auditCount, DB::table('growth_audits')->count());
        $this->assertEquals($stored, DB::table('growth_automation_runs')->where('run_key', $first['run_key'])->first());
    }

    public function test_large_expiry_backlog_is_bounded_and_resumes_on_a_later_hour(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->document('verified', now()->subMinute());
        }
        $supervisor = app(HermesSupervisor::class);
        $first = $supervisor->tick();

        $this->assertSame([['action' => 'knowledge_expire', 'count' => 100]], $first['executed_actions']);
        $this->assertSame(1, $first['verification']['knowledge_expired_remaining']);
        $this->assertTrue($first['verification']['count_matches']);
        $this->assertSame(100, GrowthDocument::where('status', 'outdated')->count());
        $this->assertSame('already_processed', $supervisor->tick()['status']);
        $this->assertSame(1, GrowthDocument::where('status', 'verified')->count());

        $this->travel(1)->hours();
        $next = $supervisor->tick();

        $this->assertSame([['action' => 'knowledge_expire', 'count' => 1]], $next['executed_actions']);
        $this->assertSame(0, GrowthDocument::where('status', 'verified')->count());
        $this->assertDatabaseCount('growth_automation_runs', 2);
    }

    public function test_main_pause_prevents_receipts_and_all_automatic_mutations(): void
    {
        $expired = $this->document('verified', now()->subMinute());
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => false]);
        $receipt = app(HermesSupervisor::class)->tick();

        $this->assertSame('paused', $receipt['status']);
        $this->assertSame([], $receipt['executed_actions']);
        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 0);
        $this->assertDatabaseCount('growth_audits', 0);
    }

    public function test_independent_switch_defaults_to_disabled(): void
    {
        Setting::where('key', 'hermes_supervisor_enabled')->delete();
        $expired = $this->document('verified', now()->subMinute());

        $this->assertSame('paused', app(HermesSupervisor::class)->tick()['status']);
        $this->assertFalse(app(HermesSupervisor::class)->status()['enabled']);
        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 0);
    }

    public function test_missing_or_revoked_delegation_cannot_run_next_hour(): void
    {
        $supervisor = app(HermesSupervisor::class);
        $supervisor->tick();
        $this->travel(1)->hours();
        $expired = $this->document('verified', now()->subMinute());
        $this->operator->update(['role' => 'support']);

        $this->assertSame('delegation_missing', $supervisor->tick()['status']);
        $this->assertFalse($supervisor->status()['delegated_actor_valid']);
        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 1);
        Setting::put('growth_autopilot_actor_id', '999999');
        $this->assertSame('delegation_missing', $supervisor->tick()['status']);
        $this->assertDatabaseCount('growth_automation_runs', 1);
    }

    public function test_revoking_supervisor_switch_stops_work_on_next_hour(): void
    {
        $supervisor = app(HermesSupervisor::class);
        $supervisor->tick();
        $this->travel(1)->hours();
        $expired = $this->document('verified', now()->subMinute());
        Setting::put('hermes_supervisor_enabled', '0');

        $this->assertSame('paused', $supervisor->tick()['status']);
        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 1);
    }

    public function test_failed_unknown_cost_and_stalled_jobs_are_observed_without_retry_or_refund(): void
    {
        $failed = $this->job(['status' => 'failed', 'error_code' => 'worker_lost_review_receipt']);
        $stalled = $this->job(['status' => 'processing', 'started_at' => now()->subHour()]);
        $completedUnknown = $this->job(['status' => 'completed', 'actual_cost_irt' => null]);
        $settled = $this->job(['status' => 'completed', 'actual_cost_irt' => 75]);
        $before = GrowthJob::orderBy('id')->get()->toArray();
        $receipt = app(HermesSupervisor::class)->tick();

        $this->assertSame('needs_attention', $receipt['status']);
        $this->assertGreaterThanOrEqual(1, $receipt['observed_after']['job_failed']);
        $this->assertSame(3, $receipt['observed_after']['job_uncertain_cost']);
        $this->assertGreaterThanOrEqual(1, $receipt['observed_after']['job_stalled']);
        $this->assertSame($before, GrowthJob::orderBy('id')->get()->toArray());
        $this->assertSame(1, $failed->fresh()->attempts);
        $this->assertSame('processing', $stalled->fresh()->status);
        $this->assertSame('completed', $completedUnknown->fresh()->status);
        $this->assertNull($completedUnknown->fresh()->actual_cost_irt);
        $this->assertSame(1, $completedUnknown->fresh()->attempts);
        $this->assertSame(75, $settled->fresh()->actual_cost_irt);
        $this->assertDatabaseCount('growth_jobs', 4);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_supervisor_outputs_only_aggregates_without_private_payloads_or_credentials(): void
    {
        $email = 'private-customer@example.invalid';
        $secret = str_repeat('fixture-credential-', 3);
        $this->document('candidate', now()->addDay(), ['body' => $email, 'source' => 'https://example.invalid/private?token='.$secret]);
        $this->job(['payload' => ['api_key' => $secret, 'customer_email' => $email]]);
        Setting::putSecret('growth_hermes_key', $secret);
        Setting::putSecret('growth_gateway_key', $secret);
        config(['crm.inbox.pass' => $secret, 'crm.from.email' => $email]);

        $supervisor = app(HermesSupervisor::class);
        $receipt = $supervisor->tick();
        $json = json_encode([$supervisor->status(), $receipt, DB::table('growth_automation_runs')->get(), DB::table('growth_audits')->get()], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString($email, $json);
        $this->assertStringNotContainsString('Private payload must never leave supervision', $json);
        $this->assertSame([], $supervisor->status()['executed_actions']);
    }

    public function test_only_authenticated_admin_can_change_supervisor_policy(): void
    {
        Setting::put('hermes_supervisor_enabled', '0');
        $this->post('/admin/ai-growth/supervisor', ['enabled' => true])->assertRedirect();
        $this->assertSame('0', Setting::get('hermes_supervisor_enabled'));

        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post('/admin/ai-growth/supervisor', ['enabled' => true])->assertForbidden();
            $this->assertSame('0', Setting::get('hermes_supervisor_enabled'));
        }

        $this->assertDatabaseCount('growth_audits', 0);
        $this->assertDatabaseCount('growth_automation_runs', 0);
    }

    public function test_admin_policy_toggle_is_audited_without_mutating_budgets_or_delegation(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        DB::table('growth_controls')->where('id', 1)->update(['daily_irt' => 50000, 'monthly_irt' => 1000000, 'notify' => false]);
        Setting::put('growth_autopilot_enabled', '0');
        Setting::put('growth_autopilot_images', '1');
        $controlsBefore = (array) DB::table('growth_controls')->where('id', 1)->first();
        $this->actingAs($otherAdmin);

        $this->post('/admin/ai-growth/supervisor', ['enabled' => false, 'daily_irt' => 999999999,
            'monthly_irt' => 999999999, 'growth_autopilot_actor_id' => $otherAdmin->id, 'autopilot_enabled' => true])->assertRedirect();
        $this->assertSame('0', Setting::get('hermes_supervisor_enabled'));
        $this->assertSame((string) $this->operator->id, Setting::get('growth_autopilot_actor_id'));
        $this->assertSame('0', Setting::get('growth_autopilot_enabled'));
        $this->assertSame('1', Setting::get('growth_autopilot_images'));
        $this->assertSame($controlsBefore, (array) DB::table('growth_controls')->where('id', 1)->first());
        $this->assertDatabaseHas('growth_audits', ['action' => 'supervisor.policy_updated', 'actor_id' => $otherAdmin->id]);

        $this->post('/admin/ai-growth/supervisor', ['enabled' => true])->assertRedirect();
        $this->assertSame('1', Setting::get('hermes_supervisor_enabled'));
        $this->assertSame((string) $this->operator->id, Setting::get('growth_autopilot_actor_id'));
        $this->assertSame($controlsBefore, (array) DB::table('growth_controls')->where('id', 1)->first());
        $this->assertDatabaseCount('growth_audits', 2);
        $this->assertDatabaseCount('growth_automation_runs', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_invalid_policy_input_cannot_partially_enable_supervision(): void
    {
        Setting::put('hermes_supervisor_enabled', '0');
        $this->actingAs($this->operator);

        $this->post('/admin/ai-growth/supervisor', ['enabled' => 'yes please'])->assertSessionHasErrors('enabled');
        $this->assertSame('0', Setting::get('hermes_supervisor_enabled'));
        $this->post('/admin/ai-growth/supervisor', [])->assertSessionHasErrors('enabled');
        $this->assertSame('0', Setting::get('hermes_supervisor_enabled'));
        $this->assertDatabaseCount('growth_audits', 0);
        $this->assertDatabaseCount('growth_automation_runs', 0);
    }

    public function test_supervisor_pages_render_in_all_locales_without_running_the_supervisor(): void
    {
        $expired = $this->document('verified', now()->subMinute());
        $this->actingAs($this->operator);
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $values = require base_path('lang/'.$locale.'/growth.php');
            foreach (['hermes', 'automation'] as $tab) {
                $this->get('/admin/ai-growth?tab='.$tab)->assertOk()
                    ->assertSee($values['supervisor_title'])->assertSee($values['supervisor_scope'])
                    ->assertSee($values['supervisor_state_ready'])->assertSee($values['supervisor_no_run'])
                    ->assertSee($values['supervisor_pause'])->assertSee($values['supervisor_limits'])
                    ->assertDontSee('growth.supervisor_', false);
            }
        }

        $this->assertSame('verified', $expired->fresh()->status);
        $this->assertDatabaseCount('growth_automation_runs', 0);
        $this->assertDatabaseCount('growth_audits', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_supervisor_receipt_display_escapes_untrusted_metadata(): void
    {
        $receipt = app(HermesSupervisor::class)->tick();
        $receipt['findings'] = [['code' => '<script>alert(1)</script>', 'count' => 1,
            'proposed_action' => '<img src=x onerror=alert(2)>', 'verification' => 'Fixture only']];
        DB::table('growth_automation_runs')->where('run_key', $receipt['run_key'])
            ->update(['summary' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
        $this->actingAs($this->operator)->get('/admin/ai-growth?tab=hermes')->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }
}
