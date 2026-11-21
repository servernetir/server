<?php

namespace Tests\Feature;

use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\DailyBlogPublisher;
use App\Services\Growth\DailyBlogSources;
use App\Services\Growth\GrowthJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class DailyBlogPublisherTest extends TestCase
{
    use AiGatewayFixture, RefreshDatabase;

    private array $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 16)->setTime(6, 0));
        $this->sellableCatalog(modelOver: ['max_output_tokens' => 4096]);
        [, $plain] = $this->greenKey();
        $actor = User::factory()->create(['role' => 'admin']);
        Setting::putSecret('growth_gateway_key', $plain);
        Setting::put('daily_blog_enabled', '1');
        Setting::put('daily_blog_quality_repair', '0');
        Setting::put('daily_blog_actor_id', (string) $actor->id);
        config(['ai_growth.autopilot_text_models' => [$this->model->slug]]);
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 50000, 'monthly_irt' => 1000000]);
        $this->source = ['url' => 'https://developer.nvidia.com/blog/new-gpu-inference/', 'title' => 'New GPU inference guide',
            'text' => str_repeat('Official GPU inference facts and practical limitations. ', 60), 'published_at' => now()->subHour()->toIso8601String(),
            'sha256' => str_repeat('a', 64), 'candidates_considered' => 3];
        $this->mock(DailyBlogSources::class)->shouldReceive('discover')->andReturn($this->source)->byDefault();
        Http::preventStrayRequests();
        $this->fakeArticle();
    }

    private function article(): array
    {
        $texts = ['fa' => 'این راهنما جزئیات فنی اجرای مدل را توضیح می‌دهد و محدودیت منابع و روش ارزیابی کاربردی برای پروژه آزمایشی را بررسی می‌کند. ',
            'en' => 'This guide explains model deployment details and evaluates resource limits and practical testing methods for a pilot project. ',
            'tr' => 'Bu rehber model dağıtım ayrıntılarını açıklar ve bir pilot proje için kaynak sınırlarını ve uygulamalı değerlendirme yöntemlerini inceler. '];
        $data = [];
        foreach ($texts as $l => $text) {
            $data[$l] = ['title' => $l === 'fa' ? 'راهنمای تازه اجرای مدل با منابع مناسب' : ($l === 'en' ? 'A practical guide to model deployment resources' : 'Model dağıtım kaynakları için uygulamalı rehber'),
                'excerpt' => mb_substr(str_repeat($text, 2), 0, 140),
                'body' => '## '.($l === 'fa' ? 'جزئیات تازه' : ($l === 'en' ? 'What changed' : 'Neler değişti'))."\n\n".str_repeat($text, 8)
                    ."\n\n## ".($l === 'fa' ? 'محدودیت‌ها' : ($l === 'en' ? 'Limitations' : 'Sınırlamalar'))."\n\n".str_repeat($text, 8)
                    ."\n\n## ".($l === 'fa' ? 'گام بعد' : ($l === 'en' ? 'Next steps' : 'Sonraki adımlar'))."\n\n".str_repeat($text, 8)];
        }

        return ['translations' => $data];
    }

    private function fakeArticle(?array $data = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode($data ?? $this->article(), JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 100, 'total_tokens' => 130]])]);
    }

    public function test_publishes_exactly_one_post_with_three_complete_translations_and_paid_receipt(): void
    {
        $publisher = app(DailyBlogPublisher::class);
        $first = $publisher->tick();
        $this->assertSame('published', $first['stage']);
        $this->assertSame($first, $publisher->tick());
        $post = Post::where('slug', 'daily-insight-2026-11-16-new-gpu-inference-guide')->sole();
        $this->assertSame('published', $post->status);
        $this->assertSame(['en', 'fa', 'tr'], $post->translations()->orderBy('locale')->pluck('locale')->all());
        foreach ($post->translations as $tr) {
            $this->assertTrue($tr->auto);
            $this->assertStringContainsString($this->source['url'], $tr->content);
            $this->assertStringContainsString('https://servernet.cloud'.($tr->locale === 'fa' ? '' : '/'.$tr->locale).'/gpu', $tr->content);
        }
        $this->assertNull(GrowthContent::where('locale', 'fa')->sole()->approved_by);
        $this->assertGreaterThan(0, GrowthJob::orderBy('id')->firstOrFail()->actual_cost_irt);
        $this->assertDatabaseCount('growth_jobs', 3);
        Http::assertSentCount(3);
    }

    public function test_incomplete_language_never_publishes_any_locale_and_never_recharges(): void
    {
        $data = $this->article();
        unset($data['translations']['tr']);
        $this->fakeArticle($data);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        app(DailyBlogPublisher::class)->tick();
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
    }

    public function test_budget_failure_never_sends_inference(): void
    {
        DB::table('growth_controls')->where('id', 1)->update(['daily_irt' => 1]);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertDatabaseCount('growth_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_pause_and_missing_admin_stop_work_and_existing_queued_job(): void
    {
        Setting::put('daily_blog_enabled', '0');
        $this->assertSame('paused_or_before_window', app(DailyBlogPublisher::class)->tick()['stage']);
        Setting::put('daily_blog_enabled', '1');
        Setting::put('daily_blog_actor_id', '0');
        $this->assertSame('paused_or_before_window', app(DailyBlogPublisher::class)->tick()['stage']);
        Http::assertNothingSent();
    }

    public function test_lock_prevents_parallel_generation(): void
    {
        $lock = Cache::lock('growth-center-run', 900);
        $lock->get();
        try {
            $this->assertSame('worker_busy', app(DailyBlogPublisher::class)->tick()['stage']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_uncertain_or_lost_call_is_never_replayed(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/chat/completions' => Http::failedConnection()]);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('growth_jobs', 3);
        $this->assertSame(1, GrowthJob::orderBy('id')->firstOrFail()->attempts);
        $this->assertSame(2, GrowthJob::where('status', 'cancelled')->where('actual_cost_irt', 0)->count());
    }

    public function test_missing_source_retries_only_after_backoff_without_inference(): void
    {
        $this->mock(DailyBlogSources::class)->shouldReceive('discover')->once()->andThrow(new \RuntimeException('no_fresh_official_source'));
        $publisher = app(DailyBlogPublisher::class);
        $this->assertSame('waiting_source', $publisher->tick()['stage']);
        $this->assertSame(1, $publisher->tick()['attempts']);
        $this->assertDatabaseCount('growth_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_unsafe_article_and_wrong_language_remain_unpublished(): void
    {
        $data = $this->article();
        $data['translations']['en']['body'] .= '\n<script>alert(1)</script>';
        $this->fakeArticle($data);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_english_body_cannot_be_reused_as_turkish(): void
    {
        $data = $this->article();
        $data['translations']['tr']['body'] = $data['translations']['en']['body'];
        $this->fakeArticle($data);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_revoked_program_cannot_be_generated_by_shared_growth_worker(): void
    {
        $actor = (int) Setting::get('daily_blog_actor_id');
        $item = app(ContentWorkflow::class)->create(['title' => 'Queued daily news', 'locale' => 'fa',
            'channel' => 'blog', 'format' => 'article', 'objective' => 'education', 'brief' => 'Public source facts'], $actor);
        $job = app(GrowthJobs::class)->enqueue($item->id, 'text', ['model' => $this->model->slug, 'max_tokens' => 100,
            'messages' => [['role' => 'user', 'content' => 'Public source facts']]], 500, $item->version,
            hash('sha256', 'daily-blog:trilingual-v1:'.$item->id), $actor);
        Setting::put('daily_blog_enabled', '0');
        app(GrowthJobs::class)->run($job);
        $this->assertSame('queued', $job->fresh()->status);
        $this->assertSame(0, $job->fresh()->attempts);
        Http::assertNothingSent();
    }

    public function test_translation_write_failure_rolls_back_post_and_preserves_cost_receipt(): void
    {
        PostTranslation::creating(function ($tr) {
            if ($tr->locale === 'tr') {
                throw new \RuntimeException('translation_write_failed');
            }
        });
        try {
            $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
            $this->assertDatabaseCount('posts', 0);
            $this->assertDatabaseCount('post_translations', 0);
            $this->assertSame('completed', GrowthJob::orderBy('id')->firstOrFail()->status);
            $this->assertGreaterThan(0, GrowthJob::orderBy('id')->firstOrFail()->actual_cost_irt);
        } finally {
            PostTranslation::flushEventListeners();
        }
    }

    public function test_reviewed_retry_keeps_paid_receipts_and_default_tick_never_retries(): void
    {
        $data = $this->article();
        unset($data['translations']['tr']);
        $this->fakeArticle($data);
        $publisher = app(DailyBlogPublisher::class);
        $old = $publisher->tick();
        $this->assertSame('blocked', $old['stage']);
        $this->assertSame(3, GrowthJob::count());
        $publisher->tick();
        $this->assertSame(3, GrowthJob::count());
        $this->fakeArticle();
        $new = $publisher->retryReviewed((int) Setting::get('daily_blog_actor_id'));
        $this->assertSame('queued', $new['stage']);
        $this->assertSame(6, GrowthJob::count());
        $this->assertSame('published', $publisher->tick()['stage']);
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(6, GrowthJob::where('status', 'completed')->count());
        $this->assertSame(array_values($old['locale_jobs']), $new['prior_job_ids']);
    }

    public function test_each_request_targets_one_language_within_actual_model_cap(): void
    {
        $this->assertSame('published', app(DailyBlogPublisher::class)->tick()['stage']);
        foreach (GrowthJob::orderBy('id')->get() as $job) {
            $locale = GrowthContent::findOrFail($job->content_id)->locale;
            $this->assertSame(4096, $job->payload['max_tokens']);
            $this->assertSame(['type' => 'json_object'], $job->payload['response_format']);
            $this->assertStringContainsString('Write ONLY the '.$locale.' version', $job->payload['messages'][0]['content']);
        }
    }

    public function test_metadata_and_existing_heading_styles_are_normalized_without_regeneration(): void
    {
        $data = $this->article();
        foreach (['fa', 'en', 'tr'] as $locale) {
            $data['translations'][$locale]['excerpt'] = str_repeat($data['translations'][$locale]['excerpt'].' ', 3);
        }
        $data['translations']['tr']['body'] = preg_replace('/^## (.+)$/mu', '**$1**', $data['translations']['tr']['body']);
        $this->fakeArticle($data);
        $state = app(DailyBlogPublisher::class)->tick();
        $this->assertSame('published', $state['stage']);
        foreach (Post::findOrFail($state['post_id'])->translations as $tr) {
            $this->assertLessThanOrEqual(158, mb_strlen($tr->excerpt));
            $this->assertGreaterThanOrEqual(3, substr_count($tr->content, '<h2'));
        }
        Http::assertSentCount(3);
    }

    public function test_repairs_only_the_invalid_locale_once_inside_existing_budget(): void
    {
        Setting::put('daily_blog_quality_repair', '1');
        $bad = $this->article();
        $bad['translations']['en']['body'] = str_replace('## Next steps', 'Next steps', $bad['translations']['en']['body']);
        $this->fakeArticle($bad);
        $publisher = app(DailyBlogPublisher::class);
        $state = $publisher->tick();
        $this->assertSame('quality_repair_queued', $state['code']);
        $this->assertSame(['en' => 'quality_en_section_headings'], $state['validation_failures']);
        $this->assertCount(1, $state['repair_jobs']);
        $this->assertDatabaseCount('growth_jobs', 4);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(3);
        $this->fakeArticle();
        $final = $publisher->tick();
        $this->assertSame('published', $final['stage']);
        $this->assertSame($state['locale_jobs']['fa'], $final['locale_jobs']['fa']);
        $this->assertSame($state['locale_jobs']['tr'], $final['locale_jobs']['tr']);
        $this->assertDatabaseCount('growth_jobs', 4);
        $this->assertDatabaseCount('posts', 1);
        Http::assertSentCount(1);
    }

    public function test_second_quality_failure_is_terminal_without_more_paid_calls(): void
    {
        Setting::put('daily_blog_quality_repair', '1');
        $bad = $this->article();
        $bad['translations']['en']['body'] = 'too short';
        $this->fakeArticle($bad);
        $publisher = app(DailyBlogPublisher::class);
        $this->assertSame('queued', $publisher->tick()['stage']);
        $this->assertSame('quality_repair_exhausted', $publisher->tick()['code']);
        $this->assertSame('blocked', $publisher->tick()['stage']);
        $this->assertDatabaseCount('growth_jobs', 4);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(4);
    }

    public function test_saved_resume_preserves_original_day_and_makes_no_model_calls(): void
    {
        $publisher = app(DailyBlogPublisher::class);
        $state = $publisher->tick();
        $post = Post::findOrFail($state['post_id']);
        $post->translations()->delete();
        $post->delete();
        GrowthContent::query()->update(['status' => 'draft', 'post_id' => null, 'publication_url' => null]);
        $state['stage'] = 'blocked';
        unset($state['post_id'], $state['urls']);
        DB::table('growth_automation_runs')->where('run_key', 'daily-blog:2026-11-16')->update(['summary' => json_encode($state)]);
        $this->travelTo(now()->addDay());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $final = $publisher->resumeSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'));
        $this->assertSame('published', $final['stage']);
        $this->assertStringContainsString('daily-insight-2026-11-16-', $final['urls'][0]);
        $this->assertArrayNotHasKey('text', $final['source']);
        $this->assertSame($final['post_id'], $publisher->resumeSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'))['post_id']);
        Http::assertNothingSent();
    }

    public function test_resume_does_not_overwrite_operator_edits(): void
    {
        $publisher = app(DailyBlogPublisher::class);
        $state = $publisher->tick();
        $state['stage'] = 'blocked';
        DB::table('growth_automation_runs')->where('run_key', 'daily-blog:2026-11-16')->update(['summary' => json_encode($state)]);
        GrowthContent::query()->increment('version');
        $this->expectExceptionMessage('completed_unchanged_bundle_required');
        $publisher->resumeSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'));
    }

    public function test_status_redacts_snapshots_and_admin_page_exposes_actual_daily_receipts(): void
    {
        app(DailyBlogPublisher::class)->tick();
        $status = app(DailyBlogPublisher::class)->status();
        $this->assertArrayNotHasKey('text', $status['today']['source']);
        $this->assertArrayNotHasKey('text', $status['recent'][0]['source']);
        $this->actingAs(User::findOrFail((int) Setting::get('daily_blog_actor_id')))
            ->get('/admin/ai-growth?tab=seo')->assertOk()->assertSee('daily-blog:2026-11-16')
            ->assertSee('daily-insight-2026-11-16-new-gpu-inference-guide');
    }

    public function test_repair_cannot_exceed_remaining_budget(): void
    {
        Setting::put('daily_blog_quality_repair', '1');
        $publisher = app(DailyBlogPublisher::class);
        $state = $publisher->tick();
        $state['stage'] = 'queued';
        $state['source'] = $this->source;
        GrowthContent::query()->update(['status' => 'draft']);
        DB::table('growth_automation_runs')->where('run_key', 'daily-blog:2026-11-16')->update(['summary' => json_encode($state)]);
        GrowthContent::where('locale', 'en')->update(['body' => json_encode(['translations' => ['en' => ['title' => 'Invalid draft for testing', 'excerpt' => str_repeat('E', 80), 'body' => 'Short']]])]);
        DB::table('growth_controls')->where('id', 1)->update(['daily_irt' => 1]);
        $this->assertSame('quality_repair_budget_unavailable', $publisher->tick()['code']);
        $this->assertDatabaseCount('growth_jobs', 3);
        Http::assertSentCount(3);
    }
    public function test_saved_malformed_locale_can_be_repaired_without_repeating_passing_calls(): void
    {
        $valid = json_encode($this->article(), JSON_UNESCAPED_UNICODE);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/chat/completions' => function ($request) use ($valid) {
            $fa = str_contains($request['messages'][0]['content'], 'Write ONLY the fa version');
            return Http::response(['choices' => [['message' => ['content' => $fa ? '{"translations":{"fa":{"body":"truncated' : $valid]]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 100, 'total_tokens' => 130]]);
        }]);
        $publisher = app(DailyBlogPublisher::class);
        $initial = $publisher->tick();
        $this->assertSame('blocked', $initial['stage']);
        $this->assertSame('incomplete_translations', $initial['validation_failures']['fa']);
        Http::assertSentCount(3);
        Setting::put('daily_blog_quality_repair', '1');
        $this->fakeArticle();
        $result = $publisher->repairSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'));
        $this->assertSame('published', $result['stage']);
        $this->assertSame($initial['locale_jobs']['en'], $result['locale_jobs']['en']);
        $this->assertSame($initial['locale_jobs']['tr'], $result['locale_jobs']['tr']);
        $this->assertDatabaseCount('growth_jobs', 4);
        $this->assertSame([], $result['validation_failures']);
        Http::assertSentCount(1);
    }

    public function test_historical_budgeted_repair_uses_the_saved_daily_key_and_is_idempotent(): void
    {
        $bad = $this->article();
        $bad['translations']['en']['body'] = 'short';
        $this->fakeArticle($bad);
        $publisher = app(DailyBlogPublisher::class);
        $this->assertSame('blocked', $publisher->tick()['stage']);
        $this->travelTo(now()->addDay());
        Setting::put('daily_blog_quality_repair', '1');
        $this->fakeArticle();
        $result = $publisher->repairSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'));
        $this->assertSame('published', $result['stage']);
        $this->assertStringContainsString('daily-insight-2026-11-16-', $result['urls'][0]);
        $this->assertSame($result['post_id'], $publisher->repairSaved('2026-11-16', (int) Setting::get('daily_blog_actor_id'))['post_id']);
        $this->assertDatabaseCount('growth_jobs', 4);
        Http::assertSentCount(1);
    }

}
