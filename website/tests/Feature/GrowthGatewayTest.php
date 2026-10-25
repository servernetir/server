<?php

namespace Tests\Feature;

use App\Models\GrowthAsset;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\GrowthGateway;
use App\Services\Growth\GrowthJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class GrowthGatewayTest extends TestCase
{
    use AiGatewayFixture,RefreshDatabase;

    public function test_real_gateway_pipeline_preserves_receipt_cost_and_never_charges_twice(): void
    {
        $this->sellableCatalog();
        [$customer,$plain] = $this->greenKey();
        Setting::putSecret('growth_gateway_key', $plain);
        Http::preventStrayRequests();
        $this->fakeUsage(prompt: 40, completion: 5, cached: 0);
        $actor = User::factory()->create(['role' => 'admin']);
        $item = app(ContentWorkflow::class)->create([
            'title' => 'VPS memory guide', 'brief' => 'Discuss public VPS memory planning.', 'locale' => 'fa', 'channel' => 'manual', 'format' => 'post', 'objective' => 'education'], $actor->id);
        $gateway = app(GrowthGateway::class);
        $payload = $gateway->payload($item, 'text', $this->model->slug);
        $quote = $gateway->estimate($payload, 'text');
        $this->assertGreaterThan(0, $quote['max_cost_irt']);
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 1000000, 'monthly_irt' => 2000000]);
        $jobs = app(GrowthJobs::class);
        $job = $jobs->enqueue($item->id, 'text', $payload, $quote['max_cost_irt'], 1, str_repeat('g', 40), $actor->id);
        $before = $this->balance($customer);
        $jobs->run($job);
        $jobs->run($job);
        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->ai_usage_id);
        $this->assertGreaterThan(0, $job->actual_cost_irt);
        $this->assertSame($before - $this->balance($customer), $job->actual_cost_irt);
        $this->assertSame('سلام!', $item->fresh()->body);
        Http::assertSentCount(1);
    }

    public function test_real_image_pipeline_saves_provider_bytes_and_watermark_privately(): void
    {
        $this->sellableCatalog(['fx_fee_bp' => 0], cached: false);
        $contracts = json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')), true);
        $name = 'black-forest-labs/FLUX-1-schnell';
        $this->model->update(['upstream_model' => $name, 'category' => 'image', 'capabilities' => ['native_tariff_hash' => $contracts['deepinfra:'.$name]['tariff_hash']]]);
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        Setting::putSecret('growth_gateway_key', $key);
        Storage::fake('local');
        $img = imagecreatetruecolor(300, 300);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        Http::fake(['*/inference/*' => Http::response(['images' => ['data:image/png;base64,'.base64_encode($bytes)],
            'inference_status' => ['status' => 'succeeded', 'cost' => 0.0005, 'runtime_ms' => 5]])]);
        $actor = User::factory()->create(['role' => 'admin']);
        $item = app(ContentWorkflow::class)->create([
            'title' => 'Cloud visual', 'brief' => 'Conceptual cloud graphic.', 'locale' => 'en', 'channel' => 'instagram', 'format' => 'post', 'objective' => 'awareness'], $actor->id);
        $gateway = app(GrowthGateway::class);
        $payload = $gateway->payload($item, 'image', $this->model->slug);
        $quote = $gateway->estimate($payload, 'image');
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 1000000, 'monthly_irt' => 2000000]);
        $job = app(GrowthJobs::class)->enqueue($item->id, 'image', $payload, $quote['max_cost_irt'], 1, str_repeat('i', 40), $actor->id);
        app(GrowthJobs::class)->run($job);
        $this->assertSame('completed', $job->fresh()->status);
        $asset = GrowthAsset::sole();
        Storage::disk('local')->assertExists($asset->path);
        $stored = Storage::disk('local')->get($asset->path);
        $this->assertNotSame(hash('sha256', $bytes), hash('sha256', $stored));
        $this->assertSame(2, $item->fresh()->version);
        Http::assertSentCount(1);
    }

    public function test_signed_quote_requires_explicit_cost_consent_and_replays_one_job(): void
    {
        $this->sellableCatalog();
        [, $plain] = $this->greenKey();
        Setting::putSecret('growth_gateway_key', $plain);
        $actor = User::factory()->create(['role' => 'admin']);
        $item = app(ContentWorkflow::class)->create([
            'title' => 'Caption quote test', 'brief' => 'Only public marketing facts.', 'locale' => 'en', 'channel' => 'linkedin', 'format' => 'post', 'objective' => 'signup'], $actor->id);
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 1000000, 'monthly_irt' => 2000000]);
        $this->actingAs($actor)->post('/admin/ai-growth/content/'.$item->id.'/quote', ['kind' => 'text', 'model' => $this->model->slug])->assertRedirect()->assertSessionHas('growth_quote');
        $quote = session('growth_quote')['token'];
        $this->post('/admin/ai-growth/content/'.$item->id.'/generate', ['quote' => $quote])->assertSessionHasErrors('confirmed');
        $this->post('/admin/ai-growth/content/'.$item->id.'/generate', ['quote' => $quote, 'confirmed' => 1])->assertRedirect();
        $this->post('/admin/ai-growth/content/'.$item->id.'/generate', ['quote' => $quote, 'confirmed' => 1])->assertRedirect();
        $this->assertDatabaseCount('growth_jobs', 1);
        Http::assertNothingSent();
    }

    public function test_rotating_gateway_key_requires_a_new_quote_before_any_spending(): void
    {
        $this->sellableCatalog();
        [, $plain, $token] = $this->greenKey();
        Setting::putSecret('growth_gateway_key', $plain);
        $actor = User::factory()->create(['role' => 'admin']);
        $item = app(ContentWorkflow::class)->create([
            'title' => 'Key rotation', 'brief' => 'Public brief', 'locale' => 'fa', 'channel' => 'manual', 'format' => 'post', 'objective' => 'education'], $actor->id);
        $gateway = app(GrowthGateway::class);
        $payload = $gateway->payload($item, 'text', $this->model->slug);
        $quote = $gateway->estimate($payload, 'text');
        DB::table('growth_controls')->where('id', 1)->update(['enabled' => true, 'daily_irt' => 1000000, 'monthly_irt' => 2000000]);
        $job = app(GrowthJobs::class)->enqueue($item->id, 'text', $payload, $quote['max_cost_irt'], 1, str_repeat('r', 40), $actor->id, $token->id);
        [, $otherKey] = $this->greenKey();
        Setting::putSecret('growth_gateway_key', $otherKey);
        Http::preventStrayRequests();
        app(GrowthJobs::class)->run($job);
        $this->assertSame('gateway_key_changed', $job->fresh()->error_code);
        $this->assertSame(0, $job->fresh()->actual_cost_irt);
        Http::assertNothingSent();
    }
}
