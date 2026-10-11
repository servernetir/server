<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use App\Models\Setting;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiNativeResponseRecovery;
use App\Services\Ai\AiSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiNativeResponseRecoveryTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(['fx_fee_bp' => 0], cached: false);
        Setting::put('ai_margin_pct', '5');
        Setting::put('ai_provider_gapgpt_base_url', 'https://api.gapgpt.app/v1');
        Setting::putSecret('ai_provider_gapgpt_key', 'test-gap-key');
        $this->provider = AiProvider::firstOrCreate(['slug' => 'gapgpt'], ['name' => 'GapGPT', 'driver' => 'OpenAI-Compatible']);
        $this->provider->update(['enabled' => true, 'live_calls_enabled' => true, 'commercial_enabled' => true,
            'resale_allowed' => true, 'agreement_status' => AiProvider::STATUS_SIGNED, 'billing_currency_code' => 'USD', 'fx_fee_bp' => 0]);
        $contract = json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')), true)['gpt-image-1-mini'];
        $this->model->update(['ai_provider_id' => $this->provider->id, 'upstream_model' => 'gpt-image-1-mini', 'category' => 'image',
            'capabilities' => ['native_tariff_hash' => AiNative::fingerprint($contract['pricing']), 'catalogue_source' => 'gapgpt',
                'catalogue_at' => now()->toIso8601String(), 'execution_ready' => true]]);
    }

    private function unknown(string $key): AiUsage
    {
        Http::fake(['*' => Http::response(['data' => [['url' => 'https://output.invalid/image']]])]);
        $this->inference($key)->assertStatus(502);
        return AiUsage::sole();
    }

    private function inference(string $key, string $idempotencyKey = 'recover-native-once')
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Idempotency-Key' => $idempotencyKey])
            ->postJson('/v1/inference', ['model' => $this->model->slug, 'prompt' => 'private-request-text']);
    }

    private function poll(string $key, AiUsage $u)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key])->getJson('/v1/inference/'.$u->public_id);
    }

    /** Historical evidence retained by a parser version which did not understand this shape. */
    private function historicalRaw(string $raw): void
    {
        AiCall::sole()->update(['response_encrypted' => [AiNativeResponseRecovery::RAW_FIELD => $raw]]);
    }

    private function imageReceipt(): string
    {
        return json_encode(['request_id'=>'private-provider-id','data'=>[['b64_json'=>$this->png()]],
            'usage'=>['input_tokens'=>10,'output_tokens'=>272,'total_tokens'=>282,'estimated_cost'=>99]],JSON_THROW_ON_ERROR);
    }

    private function png(): string
    {
        $chunk = fn($type,$data) => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return base64_encode("\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1024,1024,8,0,0,0,0))
            .$chunk('IDAT',gzcompress(str_repeat("\0",1025*1024))).$chunk('IEND',''));
    }

    public function test_bad_native_response_is_encrypted_without_request_and_malformed_receipt_is_not_promoted(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key);
        $call = AiCall::sole();
        $this->assertFalse($call->ok);
        $this->assertNull($call->request_body); $this->assertNull($call->response_body);
        $this->assertSame('https://output.invalid/image', json_decode($call->response_encrypted[AiNativeResponseRecovery::RAW_FIELD], true)['data'][0]['url']);
        $ciphertext = DB::table('ai_calls')->value('response_encrypted');
        $this->assertStringNotContainsString('output.invalid', $ciphertext);
        $this->assertStringNotContainsString('private-request-text', $ciphertext);
        $this->historicalRaw('{"inference_status":{"cost":0.01234567890123456789},');
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, $u->fresh()->status);
        $this->assertFalse($call->fresh()->ok); $this->assertSame(1_000_000, $this->balance($customer));
        $this->poll($key, $u)->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_stored_receipt_uses_frozen_tariff_before_lookup_and_replay_never_debits_again(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key); $this->historicalRaw($this->imageReceipt());
        $u->update(['upstream_request_id' => 'provider-request']);
        $this->provider->update(['usage_lookup_url' => 'https://provider.invalid/usage/{id}']);
        Setting::put('pricing_usd_rate_override', '900000'); Setting::put('ai_margin_pct', '99');
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $u->refresh();
        $this->assertSame(AiUsage::STATUS_SETTLED, $u->status);
        $this->assertSame('native_tariff', $u->usage_source); $this->assertNull($u->provider_cost_micro);
        $this->assertSame(1108, (int) $u->cost_micro); $this->assertSame(129, (int) $u->charged_irt);
        $this->assertSame(1_000_000 - 129, $this->balance($customer));
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        $this->assertTrue(AiCall::sole()->ok);
        $this->assertArrayNotHasKey(AiNativeResponseRecovery::RAW_FIELD, AiCall::sole()->response_encrypted);
        $this->poll($key, $u)->assertOk()->assertJsonPath('model', $this->model->slug)
            ->assertJsonMissingPath('request_id')->assertJsonMissingPath('usage.estimated_cost');
        $this->inference($key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        Http::assertSentCount(1); $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        [, $otherKey] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $this->poll($otherKey, $u)->assertNotFound();
    }

    public function test_late_tariff_recovery_refunds_once_preserves_null_provider_receipt_and_can_replay(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key);
        app(AiSettlement::class)->settleCap($u);
        $hold = (int) $u->fresh()->charged_irt;
        $this->historicalRaw($this->imageReceipt());
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $u->refresh();
        $this->assertSame('native_tariff', $u->usage_source); $this->assertNull($u->provider_cost_micro);
        $this->assertSame(10, $u->prompt_tokens); $this->assertSame(272, $u->completion_tokens);
        $this->assertSame(10, $u->native_usage['input_tokens']);
        $this->assertSame($hold - 129, (int) $u->refunded_irt);
        $this->assertSame(1_000_000 - 129, $this->balance($customer));
        $this->assertSame(1, CreditEntry::where('reason', 'ai_refund')->count());
        $this->poll($key, $u)->assertOk()->assertHeader('X-ServerNet-Charge-Irt', '129');
        $this->inference($key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        Http::assertSentCount(1); $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
    }

    public function test_retained_normalized_image_receipt_recovers_delivery_once_without_a_new_supplier_call(): void
    {
        $contract=json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')),true)['gpt-image-1'];
        $capabilities=$this->model->capabilities; $capabilities['native_tariff_hash']=AiNative::fingerprint($contract['pricing']);
        $this->model->update(['upstream_model'=>'gpt-image-1','capabilities'=>$capabilities]);
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        $u=$this->unknown($key);
        app(AiSettlement::class)->settleCap($u);
        $receipt=['data'=>[['b64_json'=>$this->png()]],'usage'=>[
            'prompt_tokens'=>14,'completion_tokens'=>272,'total_tokens'=>286,
            'cost'=>0.01095,'cost_details'=>['upstream_inference_cost'=>0.01095]]];
        $this->historicalRaw(json_encode($receipt,JSON_THROW_ON_ERROR));
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $u->refresh();
        $this->assertSame(11020,(int)$u->cost_micro); $this->assertSame('native_tariff',$u->usage_source);
        $this->assertNull($u->provider_cost_micro); $this->assertSame(14,$u->prompt_tokens); $this->assertSame(272,$u->completion_tokens);
        $this->assertTrue(AiCall::sole()->ok); $this->assertSame(0,(int)$u->uncollected_irt);
        $net=(int)$u->charged_irt-(int)$u->refunded_irt;
        $this->assertSame(1_000_000-$net,$this->balance($customer));
        $this->poll($key,$u)->assertOk()->assertJsonPath('data.0.b64_json',$this->png())
            ->assertJsonMissingPath('usage.cost')->assertJsonMissingPath('usage.cost_details');
        $this->inference($key)->assertOk()->assertHeader('Idempotent-Replayed','true');
        Http::assertSentCount(1); $this->assertSame(1,CreditEntry::where('reason','ai_usage')->count());
        $this->assertSame(1,CreditEntry::where('reason','ai_refund')->count());
    }

    public function test_original_numeric_provider_receipt_is_preserved_and_read_without_float_roundtrip(): void
    {
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key);
        $snapshot = $u->native_snapshot; $snapshot['contract']['protocol'] = 'deepinfra-inference';
        $u->update(['native_snapshot' => $snapshot]);
        // A float roundtrip would collapse this to .012345 and understate one microdollar.
        $raw = '{"status":"ok","image_url":"https://output.invalid/flux","inference_status":{"cost":0.012345000000000000000000001,"runtime_ms":10}}';
        $this->historicalRaw($raw);
        $this->assertSame($raw, AiCall::sole()->response_encrypted[AiNativeResponseRecovery::RAW_FIELD]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $u->refresh();
        $this->assertSame('native_provider', $u->usage_source);
        $this->assertSame(12346, (int) $u->provider_cost_micro);
        $this->poll($key, $u)->assertOk()->assertJsonMissingPath('inference_status.cost');
    }

    public function test_late_above_cap_receipt_records_credit_risk_and_closes_model_without_another_debit(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key);
        app(AiSettlement::class)->settleCap($u);
        $hold = (int) $u->fresh()->charged_irt;
        $balance = $this->balance($customer);
        $this->historicalRaw(json_encode(['data'=>[['b64_json'=>$this->png()]],'usage'=>[
            'input_tokens'=>10,'output_tokens'=>100000,'total_tokens'=>100010]],JSON_THROW_ON_ERROR));
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $u->refresh();
        $this->assertSame('native_tariff', $u->usage_source); $this->assertNull($u->provider_cost_micro);
        $this->assertSame(400020, (int) $u->cost_micro);
        $this->assertSame($hold, (int) $u->charged_irt); $this->assertSame(0, (int) $u->refunded_irt);
        $this->assertSame((int) $u->sell_irt + (int) $u->tax_irt - $hold, (int) $u->uncollected_irt);
        $this->assertGreaterThan(0, (int) $u->uncollected_irt);
        $this->assertTrue($u->needs_review); $this->assertSame('over_bound', $u->review_reason);
        $this->assertNotNull($this->model->fresh()->suspended_at);
        $this->assertSame($balance, $this->balance($customer));
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        $this->assertSame(0, CreditEntry::where('reason', 'ai_refund')->count());
        $this->poll($key, $u)->assertOk();
        $this->inference($key)->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_retention_bounds_are_enforced_on_capture_recovery_poll_and_prune(): void
    {
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $u = $this->unknown($key); $this->historicalRaw($this->imageReceipt());
        AiCall::sole()->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->assertFalse(app(AiNativeResponseRecovery::class)->recover($u));
        $this->artisan('ai:prune-replay')->assertSuccessful();
        $this->assertNull(AiCall::sole()->response_encrypted);
        AiCall::sole()->delete();
        AiNativeResponseRecovery::capture($u, 'oversized', str_repeat('x', AiNativeResponseRecovery::MAX_BYTES + 1), 200);
        AiNativeResponseRecovery::capture($u, 'not-success', '{}', 500);
        $this->assertSame(0, AiCall::count());
        AiNativeResponseRecovery::capture($u, 'recover-native-once', $this->imageReceipt(), 200);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->poll($key, $u)->assertOk();
        AiCall::sole()->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->poll($key, $u)->assertStatus(409);
        $this->inference($key)->assertStatus(409)->assertJsonPath('code', 'idempotency_key_expired');
        $this->artisan('ai:prune-replay')->assertSuccessful();
        $this->assertNull(AiCall::sole()->response_encrypted);
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        Http::assertSentCount(1);
    }

    public function test_old_capped_unparseable_responses_cannot_starve_new_unknown_requests(): void
    {
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:native']]);
        $old = $this->unknown($key);
        app(AiSettlement::class)->settleCap($old);
        $old->refresh(); $oldCall = AiCall::sole();
        for ($i = 1; $i < 200; $i++) {
            $reservation = $old->reservation->replicate();
            $reservation->forceFill(['ledger_entry_id' => null, 'idempotency_key' => 'old-capped-'.$i])->save();
            $copy = $old->replicate();
            $copy->forceFill(['public_id' => null, 'ai_reservation_id' => $reservation->id,
                'idempotency_key' => 'old-capped-'.$i, 'credit_ledger_id' => null, 'overage_ledger_id' => null])->save();
            $call = $oldCall->replicate();
            $call->forceFill(['ai_reservation_id' => $reservation->id, 'idempotency_key' => 'old-capped-'.$i])->save();
        }
        $this->inference($key, 'new-after-200-caps')->assertStatus(502);
        $new = AiUsage::latest('id')->first();
        AiCall::where('ai_reservation_id', $new->ai_reservation_id)->first()
            ->update(['response_encrypted' => [AiNativeResponseRecovery::RAW_FIELD => $this->imageReceipt()]]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame(AiUsage::STATUS_SETTLED, $new->fresh()->status);
        $this->assertSame(200, AiUsage::where('status', AiUsage::STATUS_UNKNOWN_CHARGED)->count());
        $this->assertSame(200, AiCall::where('ok', false)->count());
        Http::assertSentCount(2);
    }
}
