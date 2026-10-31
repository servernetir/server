<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\User;
use App\Services\Growth\HermesSectionOversight;
use App\Services\Madar\MadarHub;
use App\Services\Madar\MadarRewards;
use App\Services\Marketing\CampaignManager;
use App\Services\Marketing\DiscountEngine;
use App\Services\Marketing\DiscountEntryVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MadarHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', 'UTC'));
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['birthday.enabled' => false]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => Str::uuid().'@example.test', 'locale' => 'fa', 'status' => 'active']);
    }

    private function proposal(?Customer $customer = null, array $extra = []): MadarParticipation
    {
        return app(MadarHub::class)->apply($customer ?? $this->customer(), $extra + [
            'request_key' => (string) Str::uuid(), 'campaign' => 'gpu-video', 'title' => 'ComfyUI product video',
            'body' => 'A useful real project showing the workflow and actual GPU usage.']);
    }

    private function campaign(array $extra = []): MarketingCampaign
    {
        $manager = app(CampaignManager::class);
        $data = array_replace_recursive([
            'name' => 'Madar reward fixture', 'currency_code' => 'IRT', 'trigger' => 'manual', 'timezone' => 'Asia/Tehran',
            'budget' => 1_000_000, 'max_uses' => 10, 'max_per_customer' => 1,
            'rule' => ['type' => 'fixed', 'value' => 100_000, 'max_discount' => 100_000, 'min_purchase' => 500_000,
                'per_customer_cap' => 100_000, 'locales' => ['fa', 'en', 'tr'], 'operations' => ['purchase', 'renewal'],
                'families' => ['vps'], 'stackable' => false, 'min_margin_bp' => 500,
                'subsidy_approved' => false, 'subsidy_reason' => null],
            'audience' => [], 'automation' => ['valid_hours' => 2160, 'at' => '09:00', 'reminder_hours' => null],
            'message' => ['enabled' => false, 'template' => '', 'landing_path' => '/account/cloud-store',
                'service_name' => 'VPS', 'limit' => 0, 'budget' => 0],
        ], $extra);
        $c = $manager->save($data, $this->admin);
        $manager->transition($c, $this->admin, 'activate', 'Fixture approval');

        return $c->fresh();
    }

    private function offer(MadarParticipation $p, ?MarketingCampaign $c = null): MarketingCampaign
    {
        $c ??= $this->campaign();
        app(MadarRewards::class)->offer($p, $this->admin, $c->id, 7, 'Deliver a video and workflow. Reward applies to the stated eligible invoice.');

        return $c->fresh();
    }

    private function working(?Customer $customer = null): MadarParticipation
    {
        $p = $this->proposal($customer);
        $this->offer($p);
        app(MadarRewards::class)->accept($p);

        return $p->fresh();
    }

    private function proof(string $url = 'https://www.youtube.com/watch?v=madar-fixture'): array
    {
        return ['body' => 'Here is the real final output and a detailed workflow.', 'evidence_url' => $url,
            'rights_confirmed' => '1', 'disclosure_confirmed' => '1'];
    }

    private function invoice(Customer $customer, int $cost = 700_000, string $family = 'vps'): Invoice
    {
        $i = Invoice::create(['customer_id' => $customer->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'tax' => 100_000, 'total' => 1_100_000, 'paid' => 0, 'status' => 'unpaid']);
        $i->items()->create(['title' => 'Fixture service', 'quantity' => 1, 'unit_price' => 1_000_000,
            'line_total' => 1_000_000, 'tax_rate_bp' => 1000, 'tax_amount' => 100_000,
            'marketing_context' => ['eligible' => true, 'operation' => 'purchase', 'family' => $family,
                'cost_minor' => $cost, 'cost_currency' => 'IRT']]);

        return $i;
    }

    private function rejectValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_authentication_and_staff_boundaries_are_preserved(): void
    {
        $this->get('/account/madar')->assertRedirect();
        $this->get('/admin/madar')->assertRedirect();
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')->get('/admin/madar')->assertForbidden();
            $this->post('/admin/madar/settings', ['campaign' => 'gpu-video', 'open' => 0])->assertForbidden();
        }
        $this->actingAs($this->admin, 'web')->get('/admin/madar')->assertOk()->assertSee('مدار');
    }

    public function test_localized_hub_and_thread_render_with_real_forms_and_no_missing_keys(): void
    {
        $p = $this->proposal();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->actingAs($p->customer, 'customer')->get($prefix.'/account/madar');
            $response->assertOk()->assertSee('name="request_key"', false)->assertSee($prefix.'/account/madar', false);
            $this->assertStringNotContainsString('madar.campaigns.', $response->getContent());
            $thread = $this->get($prefix.'/account/madar/'.$p->public_id)->assertOk()->assertSee($p->title);
            if ($dir = getenv('MADAR_PREVIEW_DIR')) {
                file_put_contents($dir.'/'.$locale.'.html', str_replace('http://localhost', 'http://127.0.0.1:8767', $response->getContent()));
                file_put_contents($dir.'/'.$locale.'-thread.html', str_replace('http://localhost', 'http://127.0.0.1:8767', $thread->getContent()));
            }
        }
        $this->assertEquals(array_keys(require lang_path('en/madar.php')), array_keys(require lang_path('fa/madar.php')));
        $this->assertEquals(array_keys(require lang_path('tr/madar.php')), array_keys(require lang_path('fa/madar.php')));
    }

    public function test_proposal_requires_terms_and_retry_is_idempotent(): void
    {
        $customer = $this->customer();
        $data = ['request_key' => (string) Str::uuid(), 'campaign' => 'gpu-code', 'title' => 'My PyTorch notebook',
            'body' => 'I will build and document a reproducible PyTorch notebook.', 'terms_version' => config('madar.terms_version')];
        $this->actingAs($customer, 'customer')->post('/account/madar', $data)->assertSessionHasErrors('terms');
        $this->post('/account/madar', $data + ['terms' => 1])->assertRedirect();
        $this->post('/account/madar', $data + ['terms' => 1])->assertRedirect();
        $this->assertSame(1, MadarParticipation::count());
        $this->assertSame(1, MadarParticipation::first()->messages()->count());
        $this->assertSame(0, MarketingGrant::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_other_customers_cannot_read_or_change_any_case(): void
    {
        $p = $this->proposal();
        $other = $this->customer();
        $this->actingAs($other, 'customer')->get('/account/madar/'.$p->public_id)->assertNotFound();
        foreach (['message', 'accept', 'submit', 'cancel', 'redeem'] as $action) {
            $this->post('/account/madar/'.$p->public_id.'/'.$action, $this->proof())->assertNotFound();
        }
        $this->assertSame('requested', $p->fresh()->status);
    }

    public function test_budget_is_reserved_once_before_work_and_cannot_be_redeemed_early(): void
    {
        $p = $this->proposal();
        $c = $this->offer($p);
        $this->offer($p, $c);
        $this->assertSame(100_000, $c->fresh()->held);
        $this->assertSame(1, MarketingGrant::count());
        $this->assertSame(0, MarketingDelivery::count());
        $this->assertSame(0, CreditEntry::count());
        $i = $this->invoice($p->customer);
        $this->rejectValidation(fn () => app(DiscountEngine::class)->reserve($i, $p->customer, $p->fresh()->grant->code));
        $this->assertSame(0, MarketingRedemption::count());
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertSessionHasErrors('madar');
    }

    public function test_acceptance_submission_review_and_reward_redemption_are_one_flow(): void
    {
        $p = $this->proposal();
        $c = $this->campaign();
        DB::table('madar_campaigns')->where('campaign_key', $p->campaign_key)->update(['reward_campaign_id' => $c->id]);
        $this->actingAs($this->admin, 'web')->post('/admin/madar/'.$p->public_id.'/offer', ['work_days' => 7,
            'body' => 'Deliver a video plus a reproducible workflow; approved reward applies to eligible VPS invoices.'])->assertRedirect();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/accept', ['accept_offer' => 1])->assertRedirect();
        $this->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        $this->assertSame('submitted', $p->fresh()->status);
        $this->actingAs($this->admin, 'web')->get('/admin/madar/'.$p->public_id)->assertOk()->assertSee('چک‌لیست هرمس');
        $this->post('/admin/madar/'.$p->public_id.'/review', ['action' => 'approve', 'body' => 'Evidence reviewed and verified.'])->assertSessionHasErrors('human_reviewed');
        $review = ['action' => 'approve', 'body' => 'Evidence reviewed and verified.', 'human_reviewed' => 1];
        $this->post('/admin/madar/'.$p->public_id.'/review', $review)->assertRedirect();
        $this->post('/admin/madar/'.$p->public_id.'/review', $review)->assertRedirect();
        $this->assertSame('rewarded', $p->fresh()->status);
        $this->assertSame(1, $p->messages()->where('event', 'rewarded')->count());
        $this->assertSame(1, MarketingGrant::count());
        $i = $this->invoice($p->customer);
        $this->assertFalse(app(DiscountEntryVisibility::class)->enabled($p->customer));
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $i->id])->assertRedirect();
        $this->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $i->id])->assertRedirect();
        $this->assertSame(1, MarketingRedemption::count());
        $this->assertSame(100_000, $i->fresh()->discount);
        $this->assertSame(990_000, $i->fresh()->total);
        $this->assertSame(100_000, $c->fresh()->held);
        $this->assertFalse(app(DiscountEntryVisibility::class)->enabled($p->customer));
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_attachment_download_is_private_and_never_crosses_cases(): void
    {
        $p = $this->working();
        $file = UploadedFile::fake()->image('proof.jpg');
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit', $this->proof() + ['attachment' => $file])->assertRedirect();
        $message = $p->messages()->whereNotNull('attachment_path')->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
        $url = '/account/madar/'.$p->public_id.'/attachments/'.$message->id;
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->proposal();
        $this->actingAs($other->customer, 'customer')->get($url)->assertNotFound();
        $this->get('/account/madar/'.$other->public_id.'/attachments/'.$message->id)->assertNotFound();
        $this->actingAs($this->admin, 'web')->get('/admin/madar/'.$p->public_id.'/attachments/'.$message->id)->assertOk();
        $this->assertStringNotContainsString('/storage/', $url);
    }

    public function test_invalid_uploads_and_unsafe_links_are_rejected(): void
    {
        $p = $this->working();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/message', [
            'body' => 'An invalid upload.', 'attachment' => UploadedFile::fake()->create('malicious.svg', 1, 'image/svg+xml'),
        ])->assertSessionHasErrors('attachment');
        foreach (['http://youtube.com/a', 'https://127.0.0.1/a', 'https://name:secret@youtube.com/a', 'https://host.local/a', 'javascript:alert(1)'] as $url) {
            $this->post('/account/madar/'.$p->public_id.'/submit', $this->proof($url))->assertSessionHasErrors('madar');
        }
        $this->assertSame('working', $p->fresh()->status);
        $this->assertSame(0, $p->messages()->where('is_submission', true)->count());
    }

    public function test_duplicate_artifacts_cannot_earn_multiple_rewards_even_with_tracking_urls(): void
    {
        $one = $this->working();
        $two = $this->working();
        $this->actingAs($one->customer, 'customer')->post('/account/madar/'.$one->public_id.'/submit',
            $this->proof('https://www.youtube.com/watch?v=duplicate&utm_source=one'))->assertRedirect();
        $this->actingAs($two->customer, 'customer')->post('/account/madar/'.$two->public_id.'/submit',
            $this->proof('https://www.youtube.com/watch?utm_source=two&v=duplicate#section'))->assertSessionHasErrors('madar');
        $this->assertSame('working', $two->fresh()->status);
        $this->assertSame(1, DB::table('madar_artifacts')->count());
    }

    public function test_rights_disclosure_and_evidence_are_required_before_review(): void
    {
        $p = $this->working();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit',
            ['body' => 'This is my final result.', 'rights_confirmed' => 1])->assertSessionHasErrors('madar');
        $this->post('/account/madar/'.$p->public_id.'/submit', ['body' => 'This is my final result.',
            'rights_confirmed' => 1, 'disclosure_confirmed' => 1])->assertSessionHasErrors('madar');
        $this->assertSame('working', $p->fresh()->status);
    }

    public function test_deadlines_and_invalid_reward_programs_do_not_create_liabilities(): void
    {
        $p = $this->proposal();
        $c = $this->campaign(['automation' => ['valid_hours' => 24]]);
        $this->rejectValidation(fn () => $this->offer($p, $c));
        $this->assertSame(0, MarketingGrant::count());
        $this->assertSame(0, $c->fresh()->held);
        $this->offer($p);
        $this->travel(8)->days();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/accept', ['accept_offer' => 1])->assertSessionHasErrors('madar');
        $this->post('/account/madar/'.$p->public_id.'/message', ['body' => 'Could we discuss an extension?'])->assertRedirect();
    }

    public function test_cancellation_and_rejection_release_the_budget_exactly_once(): void
    {
        $p = $this->working();
        $grant = $p->grant;
        $campaign = MarketingCampaign::findOrFail($grant->campaign_id);
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/cancel', ['body' => 'I cannot complete this work.'])->assertRedirect();
        $this->post('/account/madar/'.$p->public_id.'/cancel', ['body' => 'I cannot complete this work.'])->assertRedirect();
        $this->assertSame(0, $campaign->fresh()->held);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame('active', $p->customer->fresh()->status);
        $this->assertSame(1, $p->messages()->where('event', 'cancelled')->count());
        $two = $this->working();
        $this->actingAs($this->admin, 'web')->post('/admin/madar/'.$two->public_id.'/reject', ['body' => 'This artifact does not meet the agreed criteria.'])->assertRedirect();
        $this->assertSame('rejected', $two->fresh()->status);
        $this->assertFalse($two->grant->fresh()->budget_held);
    }

    public function test_bounded_budget_cannot_be_overpromised_to_multiple_customers(): void
    {
        $c = $this->campaign(['budget' => 100_000]);
        $this->offer($this->proposal(), $c);
        $this->rejectValidation(fn () => $this->offer($this->proposal(), $c));
        $this->assertSame(1, MarketingGrant::count());
        $this->assertSame(100_000, $c->fresh()->held);
    }

    public function test_user_content_is_escaped_and_post_reward_conversation_stays_available(): void
    {
        $p = $this->working();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/message', ['body' => '<script>alert("not executable")</script>'])->assertRedirect();
        $this->get('/account/madar/'.$p->public_id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("not executable")</script>', false);
        $this->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        app(MadarRewards::class)->review($p->fresh(), $this->admin, 'approve', 'Reviewed and accepted the submitted artifact.');
        $this->post('/account/madar/'.$p->public_id.'/message', ['body' => 'I have a question about using my reward.'])->assertRedirect();
        $this->assertSame('rewarded', $p->fresh()->status);
    }

    public function test_reward_cannot_bypass_invoice_owner_product_scope_or_profit_margin(): void
    {
        $p = $this->working();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        app(MadarRewards::class)->review($p->fresh(), $this->admin, 'approve', 'Evidence verified; work accepted.');
        $outside = $this->invoice($this->customer());
        $this->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $outside->id])->assertNotFound();
        foreach ([$this->invoice($p->customer, 950_000), $this->invoice($p->customer, 700_000, 'domain')] as $i) {
            $this->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $i->id])->assertSessionHasErrors('code');
            $this->assertSame(0, $i->fresh()->discount);
        }
        $this->assertSame(0, MarketingRedemption::count());
    }

    public function test_reading_and_pagination_do_not_reorder_activity_and_private_content_is_not_cacheable(): void
    {
        $p = $this->proposal();
        $at = $p->updated_at->toIso8601String();
        for ($n = 0; $n < 45; $n++) {
            $p->messages()->create(['sender_type' => 'staff', 'sender_id' => $this->admin->id, 'body' => 'Message '.$n]);
        }
        $this->travel(1)->hours();
        $this->actingAs($p->customer, 'customer')->get('/account/madar/'.$p->public_id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('Message 44')->assertDontSee('Message 0');
        $this->get('/account/madar/'.$p->public_id.'?page=2')->assertOk()->assertSee('Message 0');
        $this->assertSame($at, $p->fresh()->updated_at->toIso8601String());
    }

    public function test_expired_terms_closed_campaign_and_excess_open_cases_are_rejected(): void
    {
        $customer = $this->customer();
        $data = ['request_key' => (string) Str::uuid(), 'campaign' => 'gpu-code', 'title' => 'A test collaboration',
            'body' => 'A useful notebook with a reproducible GPU workflow.', 'terms' => 1, 'terms_version' => 'old-version'];
        $this->actingAs($customer, 'customer')->post('/account/madar', $data)->assertSessionHasErrors('terms_version');
        DB::table('madar_campaigns')->where('campaign_key', 'gpu-code')->update(['open' => false]);
        $data['terms_version'] = config('madar.terms_version');
        $this->post('/account/madar', $data)->assertSessionHasErrors('madar');
        config(['madar.max_open_per_customer' => 1]);
        $this->proposal($customer);
        $this->rejectValidation(fn () => $this->proposal($customer));
        $this->assertSame(1, MadarParticipation::count());
    }

    public function test_revision_keeps_the_same_reserved_reward_and_hermes_observes_only_counts(): void
    {
        $p = $this->working();
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        $snapshot = app(HermesSectionOversight::class)->snapshot();
        $this->assertSame(1, $snapshot['sections']['marketing']['observation']['madar']['counts']['awaiting_review']);
        $this->assertFalse($snapshot['sections']['marketing']['observation']['madar']['financial_operations_enabled']);
        $this->assertStringNotContainsString($p->customer->email, json_encode($snapshot));
        $this->assertStringNotContainsString($p->title, json_encode($snapshot));
        app(MadarRewards::class)->review($p->fresh(), $this->admin, 'changes', 'Please include the real processing time and cost.');
        $this->assertSame('changes_requested', $p->fresh()->status);
        $this->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        app(MadarRewards::class)->review($p->fresh(), $this->admin, 'approve', 'The updated evidence now meets the agreed criteria.');
        $this->assertSame(1, MarketingGrant::count());
        $this->assertSame('rewarded', $p->fresh()->status);
    }

    public function test_post_requires_the_existing_csrf_protection_outside_the_testing_bypass(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer, 'customer');
        $this->app->instance('env', 'local');
        try {
            $this->post('/account/madar', ['title' => 'Forged cross-site proposal'])->assertStatus(419);
            $this->assertSame(0, MadarParticipation::count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_unmigrated_module_is_unavailable_without_breaking_the_account_shell(): void
    {
        $customer = $this->customer();
        $migration = require database_path('migrations/2026_10_31_180000_create_madar_participation_hub.php');
        $migration->down();
        $this->actingAs($customer, 'customer')->get('/account/madar')->assertOk()->assertSee('مدار در حال آماده‌سازی');
        $this->get('/account/madar/'.Str::uuid())->assertStatus(503);
        $this->get('/account/')->assertOk();
        $migration->up();
        $this->assertSame(5, DB::table('madar_campaigns')->count());
    }

    public function test_admin_can_extend_work_without_changing_the_reserved_reward(): void
    {
        $p = $this->working();
        $before = $p->reward_snapshot;
        $this->travel(8)->days();
        $this->actingAs($this->admin, 'web')->post('/admin/madar/'.$p->public_id.'/extend', [
            'work_days' => 7, 'body' => 'The agreed work deadline is extended by seven days.'])->assertRedirect();
        $this->assertSame($before, $p->fresh()->reward_snapshot);
        $this->assertSame(1, MarketingGrant::count());
        $this->assertTrue($p->fresh()->work_due_at->isFuture());
        $this->actingAs($p->customer, 'customer')->post('/account/madar/'.$p->public_id.'/submit', $this->proof())->assertRedirect();
        $this->assertSame('submitted', $p->fresh()->status);
    }
}
