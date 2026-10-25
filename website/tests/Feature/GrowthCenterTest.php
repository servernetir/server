<?php

namespace Tests\Feature;

use App\Models\GrowthAsset;
use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiCallOutcome;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\ConversationImport;
use App\Services\Growth\GrowthCalendar;
use App\Services\Growth\GrowthDrive;
use App\Services\Growth\GrowthGateway;
use App\Services\Growth\GrowthJobs;
use App\Services\Growth\KnowledgeBrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GrowthCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function content(?User $user = null, array $extra = []): GrowthContent
    {
        $user ??= $this->admin();

        return app(ContentWorkflow::class)->create($extra + ['title' => 'GPU deployment guide', 'brief' => 'Explain GPU memory tradeoffs using public facts.',
            'locale' => 'fa', 'channel' => 'manual', 'format' => 'post', 'objective' => 'education', 'body' => 'A useful reviewed draft.'], $user->id);
    }

    private function enable(): void
    {
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 1000, 'monthly_irt' => 2000]);
    }

    public function test_anonymous_and_non_admin_cannot_access_growth_or_assets(): void
    {
        $this->get('/admin/ai-growth')->assertRedirect();
        $item = $this->content();
        $asset = GrowthAsset::create(['content_id' => $item->id, 'path' => 'fixture.png', 'mime' => 'image/png', 'sha256' => str_repeat('0', 64), 'bytes' => 32, 'rights' => 'Fixture']);
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/ai-growth')->assertForbidden();
            $this->get('/admin/ai-growth/assets/'.$asset->id)->assertForbidden();
            $this->post('/admin/ai-growth/settings', ['daily_irt' => 1, 'monthly_irt' => 2])->assertForbidden();
        }
    }

    public function test_all_sections_render_without_upstream_calls(): void
    {
        $this->actingAs($this->admin());
        foreach (['overview', 'sociality', 'calendar', 'creative', 'seo', 'knowledge', 'hermes', 'research', 'analytics', 'integrations', 'automation', 'costs', 'settings'] as $tab) {
            $this->get('/admin/ai-growth?tab='.$tab)->assertOk()->assertSee(__('growth.'.$tab));
        }
        Http::assertNothingSent();
    }

    public function test_content_editor_escapes_untrusted_output(): void
    {
        $item = $this->content(extra: ['body' => '<script>alert(1)</script>']);
        $this->actingAs($this->admin())->get('/admin/ai-growth/content/'.$item->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_content_creation_and_duplicate_topic_guard(): void
    {
        $this->actingAs($this->admin());
        $data = ['title' => 'An actual topic', 'brief' => 'Public brief', 'locale' => 'en', 'channel' => 'blog', 'format' => 'article', 'objective' => 'education'];
        $this->post('/admin/ai-growth/content', $data)->assertRedirect();
        $this->assertDatabaseCount('growth_contents', 1);
        $this->assertDatabaseCount('growth_revisions', 1);
        $this->post('/admin/ai-growth/content', $data)->assertSessionHasErrors('title');
        $this->assertDatabaseCount('growth_contents', 1);
    }

    public function test_stale_edits_fail_and_edit_revokes_approval(): void
    {
        $user = $this->admin();
        $item = $this->content($user);
        $wf = app(ContentWorkflow::class);
        $wf->transition($item->id, 'review', 1, $user->id);
        $wf->transition($item->id, 'approved', 1, $user->id);
        $this->actingAs($user)->post('/admin/ai-growth/content/'.$item->id.'/edit', ['version' => 1, 'body' => 'New text', 'brief' => 'New brief'])->assertRedirect();
        $this->assertSame('draft', $item->fresh()->status);
        $this->assertNull($item->fresh()->approved_by);
        $this->assertSame(2, $item->fresh()->version);
        $this->post('/admin/ai-growth/content/'.$item->id.'/edit', ['version' => 1, 'body' => 'stale', 'brief' => 'old'])->assertConflict();
    }

    public function test_approval_cannot_be_skipped_or_submitted_without_confirmation(): void
    {
        $user = $this->admin();
        $item = $this->content($user);
        $this->actingAs($user);
        $this->post('/admin/ai-growth/content/'.$item->id.'/transition', ['status' => 'scheduled', 'version' => 1, 'date' => '1405/09/01', 'time' => '12:00'])->assertConflict();
        $this->post('/admin/ai-growth/content/'.$item->id.'/transition', ['status' => 'review', 'version' => 1])->assertRedirect();
        $this->post('/admin/ai-growth/content/'.$item->id.'/transition', ['status' => 'approved', 'version' => 1])->assertSessionHasErrors('confirmed');
    }

    public function test_budget_guard_and_idempotent_enqueue(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user);
        $jobs = app(GrowthJobs::class);
        $job = $jobs->enqueue($item->id, 'text', ['model' => 'fixture'], 600, 1, str_repeat('a', 40), $user->id);
        $this->assertSame($job->id, $jobs->enqueue($item->id, 'text', ['model' => 'fixture'], 600, 1, str_repeat('a', 40), $user->id)->id);
        $other = $this->content($user, ['title' => 'Second topic']);
        try {
            $jobs->enqueue($other->id, 'text', ['model' => 'fixture'], 600, 1, str_repeat('b', 40), $user->id);
            $this->fail('Budget must reject');
        } catch (ValidationException) {
            $this->assertDatabaseCount('growth_jobs', 1);
        }
        $this->assertSame(600, $jobs->spend(now()->startOfDay()));
    }

    public function test_pending_jobs_block_approval_and_only_one_worker_calls_provider(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user);
        $jobs = app(GrowthJobs::class);
        $job = $jobs->enqueue($item->id, 'text', ['model' => 'fixture'], 50, 1, str_repeat('c', 40), $user->id);
        $this->actingAs($user)->post('/admin/ai-growth/content/'.$item->id.'/transition', ['status' => 'review', 'version' => 1])->assertConflict();
        $mock = \Mockery::mock(GrowthGateway::class);
        $mock->shouldReceive('estimate')->once()->andReturn(['max_cost_irt' => 40]);
        $mock->shouldReceive('execute')->once()->andReturn(new AiCallOutcome(true, 'ok', response: ['choices' => [['message' => ['content' => 'Generated public text.']]]]));
        $this->app->instance(GrowthGateway::class, $mock);
        $jobs->run($job);
        $jobs->run($job);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('Generated public text.', $item->fresh()->body);
        $this->assertSame(2, $item->fresh()->version);
    }

    public function test_increased_quote_stops_before_inference_without_retry(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user);
        $jobs = app(GrowthJobs::class);
        $job = $jobs->enqueue($item->id, 'text', ['model' => 'fixture'], 50, 1, str_repeat('d', 40), $user->id);
        $mock = \Mockery::mock(GrowthGateway::class);
        $mock->shouldReceive('estimate')->once()->andReturn(['max_cost_irt' => 51]);
        $mock->shouldNotReceive('execute');
        $this->app->instance(GrowthGateway::class, $mock);
        $jobs->run($job);
        $jobs->run($job);
        $this->assertSame('quote_increased', $job->fresh()->error_code);
        $this->assertSame(1, (int) $job->fresh()->attempts);
    }

    public function test_pause_prevents_inference(): void
    {
        $item = $this->content();
        $job = GrowthJob::create(['request_key' => str_repeat('e', 40), 'content_id' => $item->id, 'kind' => 'text', 'payload' => [], 'content_version' => 1]);
        app(GrowthJobs::class)->run($job);
        $this->assertSame('queued', $job->fresh()->status);
        $this->artisan('growth:run')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_due_approved_blog_publishes_once_with_real_author(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user, ['channel' => 'blog', 'locale' => 'en', 'body' => '<script>bad()</script>']);
        $wf = app(ContentWorkflow::class);
        $wf->transition($item->id, 'review', 1, $user->id);
        $wf->transition($item->id, 'approved', 1, $user->id);
        $wf->transition($item->id, 'scheduled', 1, $user->id, ['scheduled_at' => now()->subMinute(), 'timezone' => 'Asia/Tehran']);
        $this->assertTrue($wf->publishBlog($item->id));
        $this->assertFalse($wf->publishBlog($item->id));
        $post = Post::findOrFail($item->fresh()->post_id);
        $this->assertSame($user->id, $post->author_id);
        $this->assertStringContainsString('&lt;script&gt;', $post->tr('en')->content);
        $this->assertSame('published', $item->fresh()->status);
    }

    public function test_jalali_scheduling_stores_utc_and_timezone(): void
    {
        $user = $this->admin();
        $item = $this->content($user);
        $wf = app(ContentWorkflow::class);
        $wf->transition($item->id, 'review', 1, $user->id);
        $wf->transition($item->id, 'approved', 1, $user->id);
        $this->actingAs($user)->post('/admin/ai-growth/content/'.$item->id.'/transition', ['status' => 'scheduled', 'version' => 1, 'date' => '۱۴۰۵/۰۹/۰۱', 'time' => '12:00', 'timezone' => 'Asia/Tehran'])->assertRedirect();
        $this->assertSame('Asia/Tehran', $item->fresh()->timezone);
        $this->assertSame('08:30', $item->fresh()->scheduled_at->utc()->format('H:i'));
    }

    public function test_private_media_is_preserved_and_upload_revokes_approval(): void
    {
        $user = $this->admin();
        $item = $this->content($user);
        $wf = app(ContentWorkflow::class);
        $wf->transition($item->id, 'review', 1, $user->id);
        $wf->transition($item->id, 'approved', 1, $user->id);
        $this->actingAs($user)->post('/admin/ai-growth/content/'.$item->id.'/upload', ['image' => UploadedFile::fake()->image('creative.png', 500, 500), 'rights' => 'Owned fixture', 'version' => 1])->assertRedirect();
        $this->assertSame('draft', $item->fresh()->status);
        $asset = GrowthAsset::firstOrFail();
        Storage::disk('local')->assertExists($asset->path);
        $this->get('/admin/ai-growth/assets/'.$asset->id)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_retrieval_excludes_unverified_expired_restricted_and_wrong_scope(): void
    {
        $user = $this->admin();
        $brain = app(KnowledgeBrain::class);
        foreach (['good', 'candidate', 'expired', 'restricted', 'wrong'] as $key) {
            $doc = $brain->candidate(['title' => 'GPU '.$key, 'source' => 'https://example.org/docs', 'body' => 'GPU memory troubleshooting '.$key,
                'classification' => $key === 'restricted' ? 'restricted' : 'internal', 'scope' => $key === 'wrong' ? 'service:999' : 'general', 'review_at' => now()->addDays(7)], $user->id);
            if ($key !== 'candidate') {
                $brain->review($doc->id, 'verified', $user->id);
            }if ($key === 'expired') {
                $doc->update(['review_at' => now()->subDay()]);
            }
        }
        $results = $brain->search('GPU memory');
        $this->assertCount(1, $results);
        $this->assertSame('GPU good', $results[0]['title']);
        $this->assertStringContainsString('knowledge:', $results[0]['citation']);
    }

    public function test_hermes_reply_is_cited_reviewed_and_has_no_actions(): void
    {
        $user = $this->admin();
        $brain = app(KnowledgeBrain::class);
        $doc = $brain->candidate(['title' => 'GPU memory support', 'source' => 'approved-runbook', 'body' => 'GPU memory: check model size before choosing a larger plan.',
            'scope' => 'general', 'classification' => 'internal', 'review_at' => now()->addDays(30)], $user->id);
        $brain->review($doc->id, 'verified', $user->id);
        $reply = $brain->suggest('GPU memory firewall');
        $this->assertNotEmpty($reply['citations']);
        $this->assertTrue($reply['requires_human_review']);
        $this->assertTrue($reply['high_risk']);
        $this->assertSame([], $reply['executed_actions']);
        $this->assertStringContainsString('knowledge:'.$doc->id.':v1', $reply['draft']);
    }

    public function test_selective_import_redacts_secrets_and_never_auto_verifies(): void
    {
        $secret = 'sk-abcdefghijk123456789';
        $json = json_encode([['title' => 'ServerNet support', 'mapping' => ['a' => ['message' => ['author' => ['role' => 'user'], 'content' => ['parts' => ['api_key='.$secret.' private@example.com 09121234567']]]]]], ['name' => 'Private chat', 'chat_messages' => [['text' => 'Unselected private topic']]]]);
        $items = app(ConversationImport::class)->preview($json);
        $this->assertCount(2, $items);
        $this->assertStringNotContainsString($secret, $items[0]['body']);
        $this->assertStringNotContainsString('private@example.com', $items[0]['body']);
        $this->actingAs($this->admin())->post('/admin/ai-growth/knowledge/import/preview', ['export' => UploadedFile::fake()->createWithContent('export.json', $json)])->assertRedirect();
        $this->post('/admin/ai-growth/knowledge/import/commit', ['selected' => [0], 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('growth_documents', 1);
        $this->assertDatabaseHas('growth_documents', ['status' => 'candidate', 'classification' => 'internal']);
        $this->assertDatabaseMissing('growth_documents', ['title' => 'Private chat']);
        Http::assertNothingSent();
    }

    public function test_google_oauth_requests_minimal_scope_pkce_and_valid_state(): void
    {
        Setting::put('google_client_id', 'fixture.apps.googleusercontent.com');
        Setting::putSecret('google_client_secret', 'fixture-secret');
        $url = app(GrowthDrive::class)->authUrl('state-value', str_repeat('v', 64), 'https://console.servernet.cloud/admin/ai-growth/google/callback');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(GrowthDrive::SCOPES, $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringNotContainsString('fixture-secret', $url);
        $this->actingAs($this->admin())->get('/admin/ai-growth/google/callback?state=wrong&code=test')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_and_not_rendered(): void
    {
        $secret = str_repeat('s', 40);
        $this->actingAs($this->admin())->post('/admin/ai-growth/settings', ['daily_irt' => 100, 'monthly_irt' => 500, 'gateway_key' => $secret, 'hermes_key' => str_repeat('h', 40)])->assertRedirect();
        $this->assertSame($secret, Setting::getSecret('growth_gateway_key'));
        $this->assertNotSame($secret, DB::table('settings')->where('key', 'growth_gateway_key')->value('value'));
        $this->get('/admin/ai-growth?tab=settings')->assertOk()->assertDontSee($secret, false);
    }

    private function signed(string $action, array $data, string $nonce): array
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $ts = (string) now()->timestamp;
        $path = '/api/internal/growth/knowledge/'.$action;

        return [$path, $body, ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GROWTH_TIME' => $ts, 'HTTP_X_GROWTH_NONCE' => $nonce,
            'HTTP_X_GROWTH_SIGNATURE' => hash_hmac('sha256', $ts."\n".$nonce."\nPOST\n".$path."\n".hash('sha256', $body), str_repeat('k', 40))]];
    }

    public function test_hermes_rejects_unsigned_tampered_and_replayed_requests(): void
    {
        Setting::putSecret('growth_hermes_key', str_repeat('k', 40));
        $this->postJson('/api/internal/growth/knowledge/search', ['query' => 'GPU'])->assertUnauthorized();
        [$url,$body,$server] = $this->signed('suggest', ['query' => 'GPU'], str_repeat('n', 32));
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk()->assertJsonPath('data.requires_human_review', true)->assertJsonPath('executed_actions', []);
        $this->call('POST', $url, [], [], [], $server, $body)->assertConflict();
        [$url,$body,$server] = $this->signed('search', ['query' => 'GPU'], str_repeat('m', 32));
        $this->call('POST', $url, [], [], [], $server, str_replace('GPU', 'CPU', $body))->assertUnauthorized();
    }

    public function test_notebook_export_rejects_internal_knowledge(): void
    {
        $user = $this->admin();
        $brain = app(KnowledgeBrain::class);
        $doc = $brain->candidate(['title' => 'Internal runbook', 'source' => 'private-source', 'body' => 'Internal GPU knowledge', 'classification' => 'internal', 'scope' => 'general', 'review_at' => now()->addDays(30)], $user->id);
        $brain->review($doc->id, 'verified', $user->id);
        $this->actingAs($user)->get('/admin/ai-growth/knowledge/export?ids[]='.$doc->id)->assertUnprocessable();
    }

    public function test_late_queued_jobs_cannot_spend_yesterdays_budget(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user);
        $job = app(GrowthJobs::class)->enqueue($item->id, 'text', ['model' => 'fixture'], 50, 1, str_repeat('j', 40), $user->id);
        $job->update(['created_at' => now()->subDay()]);
        $mock = \Mockery::mock(GrowthGateway::class);
        $mock->shouldNotReceive('execute');
        $this->app->instance(GrowthGateway::class, $mock);
        app(GrowthJobs::class)->run($job);
        $this->assertSame('quote_day_expired', $job->fresh()->error_code);
        $this->assertSame(0, $job->fresh()->actual_cost_irt);
    }

    public function test_uncertain_charge_prevents_starting_another_generation(): void
    {
        $this->enable();
        $user = $this->admin();
        $item = $this->content($user);
        $job = app(GrowthJobs::class)->enqueue($item->id, 'text', ['model' => 'fixture'], 50, 1, str_repeat('z', 40), $user->id);
        $job->update(['status' => 'failed', 'error_code' => 'unknown_pending']);
        $this->expectException(HttpException::class);
        app(GrowthJobs::class)->enqueue($item->id, 'text', ['model' => 'fixture'], 50, 1, str_repeat('y', 40), $user->id);
    }

    public function test_expired_knowledge_can_only_return_after_human_review_with_new_date(): void
    {
        $user = $this->admin();
        $brain = app(KnowledgeBrain::class);
        $doc = $brain->candidate(['title' => 'GPU review', 'source' => 'runbook', 'body' => 'GPU current guidance', 'scope' => 'general', 'classification' => 'public', 'review_at' => now()->subDay()], $user->id);
        $this->assertSame([], $brain->search('GPU'));
        $brain->review($doc->id, 'verified', $user->id, 'Reviewed primary source.', now()->addDays(30));
        $this->assertCount(1, $brain->search('GPU'));
    }

    public function test_signed_query_tampering_is_rejected_and_future_timestamps_cannot_replay(): void
    {
        Setting::putSecret('growth_hermes_key', str_repeat('k', 40));
        [$url,$body,$server] = $this->signed('search', ['query' => 'GPU'], str_repeat('q', 32));
        $this->call('POST', $url.'?scope=service:99', [], [], [], $server, $body)->assertUnauthorized();
        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
        $this->travel(2)->minutes();
        $this->call('POST', $url, [], [], [], $server, $body)->assertConflict();
    }

    public function test_views_and_translation_keys_match_all_three_languages(): void
    {
        $keys = null;
        $this->actingAs($this->admin());
        foreach (['fa', 'en', 'tr'] as $locale) {
            $values = require base_path('lang/'.$locale.'/growth.php');
            $current = array_keys($values);
            sort($current);
            if ($keys !== null) {
                $this->assertSame($keys, $current);
            }$keys = $current;
            app()->setLocale($locale);
            $this->get('/admin/ai-growth?tab=settings')->assertOk()->assertSee($values['center'])->assertDontSee('growth.daily_limit', false);
        }
    }

    public function test_jalali_month_grid_uses_local_day_for_utc_scheduled_posts(): void
    {
        $user = $this->admin();
        $item = $this->content($user);
        $item->update(['scheduled_at' => '2026-10-22 21:30:00', 'status' => 'scheduled', 'timezone' => 'Asia/Tehran']);
        app()->setLocale('fa');
        $calendar = app(GrowthCalendar::class)->month('2026-10-25', 'Asia/Tehran');
        $this->assertSame('آبان 1405',$calendar['label']);
        $this->assertCount(30,$calendar['days']);
        $day = collect($calendar['days'])->firstWhere('date','2026-10-23');
        $this->assertSame(1,$day['number']);
        $this->assertSame($item->id,$day['items']->first()->id);
        $this->actingAs($user)->get('/admin/ai-growth?tab=calendar&calendar_date=1405%2F08%2F03')->assertOk()->assertSee('data-date="2026-10-23"',false);
    }

    public function test_new_schema_can_rollback_and_reinstall_without_dropping_existing_cms_data(): void
    {
        $author = $this->admin();
        $post = Post::create(['slug' => 'retained-cms-record', 'type' => 'blog', 'category' => 'tutorial', 'status' => 'draft', 'author_id' => $author->id]);
        $migration = require database_path('migrations/2026_10_25_160000_create_growth_center_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('growth_contents'));
        $this->assertDatabaseHas('posts',['id' => $post->id]);
        $migration->up();
        $this->assertDatabaseHas('growth_controls',['id' => 1, 'enabled' => 0]);
        $this->assertDatabaseHas('posts',['id' => $post->id]);
    }
}
