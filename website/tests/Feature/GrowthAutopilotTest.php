<?php

namespace Tests\Feature;

use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\GrowthAutopilot;
use App\Services\Growth\GrowthJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class GrowthAutopilotTest extends TestCase
{
    use AiGatewayFixture, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(6, 0));
        $this->sellableCatalog();
        [, $plain] = $this->greenKey(tokenOver: ['abilities' => ['ai:chat', 'ai:native']]);
        $this->operator = User::factory()->create(['role' => 'admin']);
        Setting::putSecret('growth_gateway_key', $plain);
        Setting::put('growth_autopilot_enabled', '1');
        Setting::put('growth_autopilot_actor_id', (string) $this->operator->id);
        config(['ai_growth.autopilot_text_models' => [$this->model->slug], 'ai_growth.autopilot_image_models' => []]);
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 50000, 'monthly_irt' => 1000000]);
        Http::preventStrayRequests();
        Http::fake(['https://servernet.cloud/*' => Http::response('<main>'.str_repeat('Public product facts and practical tradeoffs. ', 30).'</main>'),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'A useful sourced draft for human review.']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 5, 'total_tokens' => 45]])]);
    }

    public function test_autopilot_creates_and_generates_once_then_requests_review_without_publication(): void
    {
        $auto = app(GrowthAutopilot::class);
        $auto->prepare();
        $auto->prepare();
        $this->assertDatabaseCount('growth_contents', 1);
        $this->assertDatabaseCount('growth_jobs', 1);
        $job = GrowthJob::sole();
        app(GrowthJobs::class)->run($job);
        $auto->prepare();
        $auto->prepare();
        $this->assertSame('review', GrowthContent::sole()->status);
        $this->assertNull(GrowthContent::sole()->approved_by);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertGreaterThan(0, $job->fresh()->actual_cost_irt);
        $this->assertDatabaseCount('posts', 0);
        $this->assertSame('review_ready', $auto->status()['latest'][0]['summary']['stage']);
        Http::assertSentCount(2);
    }

    public function test_pause_and_revoked_admin_delegation_prevent_all_work(): void
    {
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => false]);
        app(GrowthAutopilot::class)->prepare();
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true]);
        $this->operator->update(['role' => 'support']);
        app(GrowthAutopilot::class)->prepare();
        $this->assertDatabaseCount('growth_contents', 0);
        Http::assertNothingSent();
    }

    public function test_budget_does_not_enqueue_an_unaffordable_call(): void
    {
        DB::table('growth_controls')->where('id', 1)->update(['daily_irt' => 1, 'monthly_irt' => 1]);
        app(GrowthAutopilot::class)->prepare();
        $this->assertDatabaseCount('growth_jobs', 0);
        $this->assertSame('blocked', app(GrowthAutopilot::class)->status()['latest'][0]['summary']['stage']);
        Http::assertSentCount(1);
    }

    public function test_seven_unreviewed_items_stop_new_spend_and_source_fetching(): void
    {
        for ($i = 0; $i < 7; $i++) {
            app(ContentWorkflow::class)->create(['title' => 'Existing draft '.$i, 'brief' => 'Public brief', 'locale' => 'fa',
                'channel' => 'blog', 'format' => 'article', 'objective' => 'education'], $this->operator->id);
        }
        app(GrowthAutopilot::class)->prepare();
        $this->assertDatabaseCount('growth_contents', 7);
        $this->assertDatabaseCount('growth_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_operator_edits_are_preserved_and_never_overwritten_by_automation(): void
    {
        app(GrowthAutopilot::class)->prepare();
        app(GrowthJobs::class)->run(GrowthJob::sole());
        $item = GrowthContent::sole();
        app(ContentWorkflow::class)->edit($item->id, ['body' => 'Operator revision'], $item->version, $this->operator->id);
        app(GrowthAutopilot::class)->prepare();
        $this->assertSame('Operator revision', $item->fresh()->body);
        $this->assertSame('draft', $item->fresh()->status);
        $this->assertSame('operator_changed_content', app(GrowthAutopilot::class)->status()['latest'][0]['summary']['code']);
    }

    public function test_uncertain_job_is_not_retried_or_replaced(): void
    {
        app(GrowthAutopilot::class)->prepare();
        GrowthJob::sole()->update(['status' => 'failed', 'actual_cost_irt' => null]);
        app(GrowthAutopilot::class)->prepare();
        app(GrowthAutopilot::class)->prepare();
        $this->assertDatabaseCount('growth_jobs', 1);
        $this->assertSame('failed_job_requires_review', app(GrowthAutopilot::class)->status()['latest'][0]['summary']['code']);
        Http::assertSentCount(1);
    }

    public function test_next_day_chooses_a_new_locale_and_never_duplicates_a_topic(): void
    {
        app(GrowthAutopilot::class)->prepare();
        $this->travel(1)->days();
        app(GrowthAutopilot::class)->prepare();
        $this->assertSame(['fa', 'en'], GrowthContent::orderBy('id')->pluck('locale')->all());
        $this->assertDatabaseCount('growth_jobs', 2);
    }

    public function test_text_then_image_reaches_review_once_without_auto_approval(): void
    {
        Setting::put('growth_autopilot_images', '1');
        app(GrowthAutopilot::class)->prepare();
        app(GrowthJobs::class)->run(GrowthJob::sole());
        $contracts = json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')), true);
        $name = 'black-forest-labs/FLUX-1-schnell';
        $imageModel = $this->model->replicate();
        $imageModel->fill(['slug' => 'autopilot-image', 'category' => 'image', 'upstream_model' => $name,
            'capabilities' => ['native_tariff_hash' => $contracts['deepinfra:'.$name]['tariff_hash']]])->save();
        config(['ai_growth.autopilot_image_models' => ['autopilot-image']]);
        Storage::fake('local');
        $image = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Http::fake(['*/inference/*' => Http::response(['images' => ['data:image/png;base64,'.base64_encode($bytes)],
            'inference_status' => ['status' => 'succeeded', 'cost' => 0.0005, 'runtime_ms' => 5]])]);
        app(GrowthAutopilot::class)->prepare();
        $this->assertDatabaseCount('growth_jobs', 2);
        $imageJob = GrowthJob::where('kind', 'image')->sole();
        app(GrowthJobs::class)->run($imageJob);
        $this->assertSame('completed', $imageJob->fresh()->status);
        app(GrowthAutopilot::class)->prepare();
        app(GrowthAutopilot::class)->prepare();
        $this->assertSame('review', GrowthContent::sole()->status);
        $this->assertNull(GrowthContent::sole()->approved_by);
        $this->assertDatabaseCount('growth_assets', 1);
        $this->assertDatabaseCount('growth_jobs', 2);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_disabling_autopilot_after_enqueue_prevents_the_paid_call(): void
    {
        app(GrowthAutopilot::class)->prepare();
        Setting::put('growth_autopilot_enabled', '0');
        app(GrowthJobs::class)->run(GrowthJob::sole());
        $this->assertSame('queued', GrowthJob::sole()->status);
        $this->assertSame(0, GrowthJob::sole()->attempts);
        Http::assertSentCount(1);
    }
}
