<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MadarMission;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\User;
use App\Services\Madar\MadarClaims;
use App\Services\Madar\MadarHub;
use App\Services\Madar\MadarPublishing;
use App\Services\Madar\MadarRewards;
use App\Services\Marketing\CampaignManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MadarFixedMissionTest extends TestCase
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

    private function campaign(int $amount = 500_000, array $extra = []): MarketingCampaign
    {
        $manager = app(CampaignManager::class);
        $c = $manager->save(array_replace_recursive([
            'name' => 'Fixed public mission fixture', 'currency_code' => 'IRT', 'trigger' => 'manual', 'timezone' => 'Asia/Tehran',
            'budget' => 10_000_000, 'max_uses' => 20, 'max_per_customer' => 1,
            'rule' => ['type' => 'fixed', 'value' => $amount, 'max_discount' => $amount, 'min_purchase' => 2_000_000,
                'per_customer_cap' => $amount, 'locales' => ['fa', 'en', 'tr'], 'operations' => ['purchase', 'renewal'],
                'families' => ['vps'], 'stackable' => false, 'min_margin_bp' => 500, 'subsidy_approved' => false, 'subsidy_reason' => null],
            'audience' => [], 'automation' => ['valid_hours' => 2160, 'at' => '09:00', 'reminder_hours' => null],
            'message' => ['enabled' => false, 'template' => '', 'landing_path' => '/account/cloud-store',
                'service_name' => 'VPS', 'limit' => 0, 'budget' => 0],
        ], $extra), $this->admin);
        $manager->transition($c, $this->admin, 'activate', 'Synthetic fixed mission approval');

        return $c->fresh();
    }

    private function publish(string $key = 'gpu-video', ?MarketingCampaign $campaign = null): MadarMission
    {
        $amount = config('madar.campaigns.'.$key.'.reward_irt');

        return app(MadarPublishing::class)->publish($key, $campaign ?? $this->campaign($amount), $amount, $this->admin);
    }

    private function proof(MadarMission $mission, array $extra = []): array
    {
        return $extra + ['request_key' => (string) Str::uuid(), 'mission_id' => $mission->id, 'title' => 'Real reproducible GPU project',
            'body' => 'Published real output, panel workflow, measured cost and the relevant ServerNet service link.',
            'evidence_url' => 'https://www.youtube.com/watch?v='.Str::uuid(), 'distribution_url' => 'https://www.linkedin.com/posts/'.Str::uuid(),
            'rights_confirmed' => 1, 'disclosure_confirmed' => 1, 'terms' => 1, 'terms_version' => $mission->snapshot['terms_version']];
    }

    private function rejects(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_amounts_and_acceptance_criteria_are_visible_before_work_but_unfunded_claims_are_closed(): void
    {
        $customer = $this->customer();
        $r = $this->actingAs($customer, 'customer')->get('/account/madar')->assertOk();
        $r->assertDontSee('data-madar-claim', false);
        $this->assertCount(8, app(MadarHub::class)->campaigns($customer));
        foreach (app(MadarHub::class)->campaigns($customer) as $card) {
            $r->assertSee(invoice_money($card['amount_irt'], 'IRT'));
            $this->assertFalse((bool) $card['open']);
        }
        $mission = $this->publish();
        $r = $this->get('/account/madar')->assertOk()->assertSee('data-madar-claim', false)
            ->assertSee(__('madar.campaigns.gpu-video.requirements'))->assertSee(__('madar.minimum'));
        $this->assertSame(500_000, $mission->reward_amount_irt);
        $this->assertSame(0, MarketingGrant::count());
    }

    public function test_one_post_after_publication_reserves_fixed_reward_and_keeps_review_and_payment_in_the_same_case(): void
    {
        $customer = $this->customer();
        $mission = $this->publish();
        $data = $this->proof($mission);
        $this->actingAs($customer, 'customer')->post('/account/madar/claims', $data)->assertRedirect();
        $p = MadarParticipation::firstOrFail();
        $this->assertSame('submitted', $p->status);
        $this->assertSame(2, $p->flow_version);
        $this->assertSame(1, $p->messages()->count());
        $this->assertSame(0, $p->messages()->whereIn('event', ['offered', 'accepted'])->count());
        $this->assertSame(500_000, $p->grant->liability);
        $this->assertSame(500_000, MarketingCampaign::find($mission->reward_campaign_id)->held);
        $this->assertSame(0, CreditEntry::count());
        $i = Invoice::create(['customer_id' => $customer->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 2_000_000, 'tax' => 200_000, 'total' => 2_200_000, 'paid' => 0, 'status' => 'unpaid']);
        $i->items()->create(['title' => 'Eligible fixture service', 'quantity' => 1, 'unit_price' => 2_000_000,
            'line_total' => 2_000_000, 'tax_rate_bp' => 1000, 'tax_amount' => 200_000,
            'marketing_context' => ['eligible' => true, 'operation' => 'purchase', 'family' => 'vps', 'cost_minor' => 500_000, 'cost_currency' => 'IRT']]);
        $this->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $i->id])->assertStatus(422);
        $this->actingAs($this->admin, 'web')->post('/admin/madar/'.$p->public_id.'/review', [
            'action' => 'approve', 'body' => 'Verified original useful output, placement and disclosure.', 'human_reviewed' => 1])->assertRedirect();
        $this->actingAs($customer, 'customer')->post('/account/madar/'.$p->public_id.'/redeem', ['invoice_id' => $i->id])->assertRedirect();
        $this->assertSame('rewarded', $p->fresh()->status);
        $this->assertSame(500_000, $i->fresh()->discount);
        $this->assertSame(1, MarketingRedemption::count());
        $this->post('/account/madar/'.$p->public_id.'/message', ['body' => 'Thanks, my reward is on the invoice.'])->assertRedirect();
    }

    public function test_claim_terms_ownership_publication_and_distribution_are_required_before_any_budget_is_held(): void
    {
        $customer = $this->customer();
        $mission = $this->publish('gpu-code');
        foreach ([['terms' => 0], ['rights_confirmed' => 0], ['disclosure_confirmed' => 0], ['evidence_url' => ''],
            ['distribution_url' => ''], ['terms_version' => 'expired'], ['evidence_url' => 'https://maps.google.com/review/paid'],
            ['distribution_url' => 'https://www.trustpilot.com/review/servernet.cloud'], ['evidence_url' => 'https://127.0.0.1/proof']] as $invalid) {
            $this->travel(61)->seconds();
            $this->actingAs($customer, 'customer')->post('/account/madar/claims', $this->proof($mission, $invalid))->assertSessionHasErrors();
        }
        $data = $this->proof($mission);
        $data['distribution_url'] = $data['evidence_url'];
        $this->post('/account/madar/claims', $data)->assertSessionHasErrors();
        $this->assertSame(0, MarketingGrant::count());
        $this->assertSame(0, MadarParticipation::count());
        $this->assertSame(0, MarketingCampaign::find($mission->reward_campaign_id)->held);
    }

    public function test_exact_retry_is_idempotent_but_changed_payload_or_owner_cannot_reuse_the_reference(): void
    {
        $customer = $this->customer();
        $mission = $this->publish();
        $data = $this->proof($mission);
        $first = app(MadarClaims::class)->submit($customer, $data);
        $this->assertSame($first->id, app(MadarClaims::class)->submit($customer, $data)->id);
        $this->rejects(fn () => app(MadarClaims::class)->submit($customer, array_replace($data, ['title' => 'Changed title'])));
        $this->rejects(fn () => app(MadarClaims::class)->submit($this->customer(), $data));
        $this->assertSame(1, MarketingGrant::count());
        $this->assertSame(1, MadarParticipation::count());
    }

    public function test_duplicate_distribution_or_tracking_only_url_changes_cannot_claim_twice_and_budget_rolls_back(): void
    {
        $mission = $this->publish('gpu-code');
        $data = $this->proof($mission);
        app(MadarClaims::class)->submit($this->customer(), $data);
        $second = $this->proof($mission, ['evidence_url' => $data['evidence_url'].'&utm_source=other']);
        $this->rejects(fn () => app(MadarClaims::class)->submit($this->customer(), $second));
        $third = $this->proof($mission, ['distribution_url' => $data['distribution_url']]);
        $this->rejects(fn () => app(MadarClaims::class)->submit($this->customer(), $third));
        $this->assertSame(1, MadarParticipation::count());
        $this->assertSame(1, MarketingGrant::count());
        $this->assertSame(400_000, MarketingCampaign::find($mission->reward_campaign_id)->held);
    }

    public function test_public_versions_and_financial_terms_are_immutable_while_new_copy_creates_a_new_version(): void
    {
        $mission = $this->publish();
        $c = MarketingCampaign::findOrFail($mission->reward_campaign_id);
        $this->assertSame($mission->id, $this->publish('gpu-video', $c)->id);
        $this->rejects(fn () => app(CampaignManager::class)->save(['name' => 'Invisible changed deal'], $this->admin, $c));
        $original = $mission->snapshot;
        config(['madar.campaigns.gpu-video.distribution_required' => true, 'madar.terms_version' => 'new-fixture-version']);
        $new = $this->publish('gpu-video', $c);
        $this->assertSame(2, $new->version);
        $this->assertSame($original, $mission->fresh()->snapshot);
        $this->assertTrue($new->snapshot['mission']['distribution_required']);
        // A creator who worked against the previous published version can still submit before its deadline.
        $p = app(MadarClaims::class)->submit($this->customer(), $this->proof($mission, ['distribution_url' => null]));
        $this->assertSame($mission->id, $p->mission_id);
        $this->assertSame(500_000, $p->reward_snapshot['amount_irt']);
    }

    public function test_no_funds_or_wrong_amount_cannot_publish_or_accept_an_unfunded_claim(): void
    {
        $c = $this->campaign(500_000, ['budget' => 500_000, 'max_uses' => 1]);
        $this->rejects(fn () => app(MadarPublishing::class)->publish('gpu-video', $c, 400_000, $this->admin));
        $mission = $this->publish('gpu-video', $c);
        app(MadarClaims::class)->submit($this->customer(), $this->proof($mission));
        $this->rejects(fn () => app(MadarClaims::class)->submit($this->customer(), $this->proof($mission)));
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($this->customer())['gpu-video']['open']);
        $this->assertSame(500_000, $c->fresh()->held);
        $this->assertSame(1, MarketingGrant::count());
    }

    public function test_weekly_and_repeat_caps_are_visible_before_work_and_enforced_at_submission(): void
    {
        $customer = $this->customer();
        $missions = [];
        foreach (['gpu-video', 'gpu-code', 'gpu-guide', 'gpu-template'] as $key) {
            $missions[$key] = $this->publish($key);
        }
        app(MadarClaims::class)->submit($customer, $this->proof($missions['gpu-video']));
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($customer)['gpu-video']['open']);
        $this->rejects(fn () => app(MadarClaims::class)->submit($customer, $this->proof($missions['gpu-video'])));
        foreach (['gpu-code', 'gpu-guide'] as $key) {
            app(MadarClaims::class)->submit($customer, $this->proof($missions[$key]));
        }
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($customer)['gpu-template']['open']);
        $this->rejects(fn () => app(MadarClaims::class)->submit($customer, $this->proof($missions['gpu-template'])));
        $this->assertSame(3, MadarParticipation::count());
        $this->travelTo(Carbon::parse('2026-11-02 00:01:00', 'Asia/Tehran'));
        $this->assertTrue((bool) app(MadarHub::class)->campaigns($customer)['gpu-template']['open']);
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($customer)['gpu-video']['open']);
    }

    public function test_publication_deadline_is_bounded_and_pausing_stops_new_claims_without_revoking_accepted_work(): void
    {
        $c = $this->campaign(500_000, ['ends_at' => now()->addDays(100)->toDateTimeString()]);
        $mission = $this->publish('gpu-video', $c);
        $this->assertTrue($mission->submissions_until->equalTo(now()->addDays(10)));
        $p = app(MadarClaims::class)->submit($this->customer(), $this->proof($mission));
        DB::table('madar_campaigns')->where('campaign_key', 'gpu-video')->update(['open' => false]);
        $this->rejects(fn () => app(MadarClaims::class)->submit($this->customer(), $this->proof($mission)));
        app(MadarRewards::class)->review($p, $this->admin, 'approve', 'Verified original useful work and publication.');
        $this->assertSame('rewarded', $p->fresh()->status);
        $this->assertNull($p->grant->revoked_at);
    }

    public function test_previous_reward_capacity_is_checked_before_inviting_a_repeat_creator_to_start(): void
    {
        $customer = $this->customer();
        $first = $this->publish();
        app(MadarClaims::class)->submit($customer, $this->proof($first));
        $this->travel(31)->days();
        $new = $this->publish('gpu-video', MarketingCampaign::findOrFail($first->reward_campaign_id));
        $this->assertSame(2, $new->version);
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($customer)['gpu-video']['open']);
        $this->assertTrue((bool) app(MadarHub::class)->campaigns($this->customer())['gpu-video']['open']);
        $this->rejects(fn () => app(MadarClaims::class)->submit($customer, $this->proof($new)));
        $this->assertSame(1, MarketingGrant::count());
    }

    public function test_customer_locale_is_enforced_before_work_and_at_submission(): void
    {
        $customer = $this->customer();
        $customer->update(['locale' => 'tr']);
        $c = $this->campaign(500_000, ['rule' => ['locales' => ['fa']]]);
        // Recursive test fixture replacement leaves other locales; set the real fixture explicitly.
        $c->forceFill(['rule' => array_replace($c->rule, ['locales' => ['fa']])])->save();
        $mission = $this->publish('gpu-video', $c);
        $this->assertFalse((bool) app(MadarHub::class)->campaigns($customer)['gpu-video']['open']);
        $this->rejects(fn () => app(MadarClaims::class)->submit($customer, $this->proof($mission)));
        $this->assertSame(0, MarketingGrant::count());
        $customer->update(['locale' => 'fa']);
        $this->assertTrue((bool) app(MadarHub::class)->campaigns($customer)['gpu-video']['open']);
    }

    public function test_share_urls_do_not_leak_identity_and_are_not_a_claim_of_automatic_sales_attribution(): void
    {
        $customer = $this->customer();
        app()->setLocale('tr');
        $url = app(MadarHub::class)->shareUrl('gpu-template', $customer, 3);
        $this->assertStringStartsWith('https://servernet.cloud/tr/gpu?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('madar_gpu_template_v3', $params['utm_campaign']);
        $this->assertSame(20, strlen($params['utm_content']));
        $this->assertStringNotContainsString($customer->email, $url);
        $this->assertSame(0, MarketingGrant::count());
    }
}
