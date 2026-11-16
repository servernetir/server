<?php

namespace Tests\Feature;

use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\DailyBlogPublisher;
use App\Services\Growth\DailyBlogSources;
use App\Services\Growth\GrowthJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->sellableCatalog(modelOver: ['max_output_tokens' => 16000]);
        [, $plain] = $this->greenKey();
        $actor = User::factory()->create(['role' => 'admin']);
        Setting::putSecret('growth_gateway_key', $plain);
        Setting::put('daily_blog_enabled', '1');
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
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode($data ?? $this->article(), JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 100, 'total_tokens' => 130]])]);
    }

    public function test_publishes_exactly_one_post_with_three_complete_translations_and_paid_receipt(): void
    {
        $publisher = app(DailyBlogPublisher::class);
        $first = $publisher->tick();
        $this->assertSame('published', $first['stage']);
        $this->assertSame($first, $publisher->tick());
        $post = Post::where('slug', 'daily-insight-2026-11-16')->sole();
        $this->assertSame('published', $post->status);
        $this->assertSame(['en', 'fa', 'tr'], $post->translations()->orderBy('locale')->pluck('locale')->all());
        foreach ($post->translations as $tr) {
            $this->assertTrue($tr->auto);
            $this->assertStringContainsString($this->source['url'], $tr->content);
            $this->assertStringContainsString('https://servernet.cloud'.($tr->locale === 'fa' ? '' : '/'.$tr->locale).'/gpu', $tr->content);
        }
        $this->assertNull(GrowthContent::sole()->approved_by);
        $this->assertGreaterThan(0, GrowthJob::sole()->actual_cost_irt);
        $this->assertDatabaseCount('growth_jobs', 1);
        Http::assertSentCount(1);
    }

    public function test_incomplete_language_never_publishes_any_locale_and_never_recharges(): void
    {
        $data = $this->article();
        unset($data['translations']['tr']);
        $this->fakeArticle($data);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        app(DailyBlogPublisher::class)->tick();
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(1);
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
        Http::fake(['*/chat/completions' => Http::failedConnection()]);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertSame('blocked', app(DailyBlogPublisher::class)->tick()['stage']);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('growth_jobs', 1);
        $this->assertSame(1, GrowthJob::sole()->attempts);
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
}
