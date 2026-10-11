<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiNativeOperationsTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private function nativeModel(string $name='black-forest-labs/FLUX-1-schnell',string $category='image'): void
    {
        $contracts=json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')),true);
        $this->model->update(['upstream_model'=>$name,'category'=>$category,
            'capabilities'=>['native_tariff_hash'=>$contracts[$this->provider->slug.':'.$name]['tariff_hash']]]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(['fx_fee_bp'=>0],cached:false);
        Setting::put('ai_margin_pct','5');
        $this->nativeModel();
    }

    private function infer(string $key,array $body=[],array $headers=[])
    {
        return $this->withHeaders(['Authorization'=>'Bearer '.$key]+$headers)->postJson('/v1/inference',
            $body+['model'=>$this->model->slug,'prompt'=>'A blue square.','width'=>1024,'height'=>1024]);
    }

    public function test_official_gap_image_prices_compile_to_matching_decimal_contracts(): void
    {
        $rows=[];
        for($i=0;$i<20;$i++) $rows[]=['model_name'=>'chat-'.$i,'model_type'=>'text','pricing_type'=>'token','input_token_price'=>1,'output_token_price'=>2];
        foreach(['flux-1-schnell'=>0.0014,'gemini-2.5-flash-image'=>0.04,'gapgpt/qwen-image-2.1'=>0.005] as $name=>$price)
            $rows[]=['model_name'=>$name,'model_type'=>'image','pricing_type'=>'call','price_per_call'=>$price];
        $parsed=\App\Services\Ai\AdditionalProviderCatalog::gapRows(json_encode(['data'=>$rows],JSON_THROW_ON_ERROR));
        foreach(['flux-1-schnell'=>'0.14','gemini-2.5-flash-image'=>'4','gapgpt/qwen-image-2.1'=>'0.5'] as $name=>$cents){
            $this->assertSame($cents,$parsed[$name]['native_pricing']['cents_per_call']);
            $this->assertNotNull(AiNative::candidate('gapgpt',$name,$parsed[$name]['native_pricing']));
        }
    }

    public function test_image_cost_is_settled_without_invented_tokens_and_replayed_once(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/inference/*'=>Http::response('{"images":["data:image/png;base64,output"],"inference_status":{"status":"succeeded","cost":0.0005,"runtime_ms":900}}')]);
        $first=$this->infer($key,headers:['Idempotency-Key'=>'native-once'])->assertOk();
        $u=AiUsage::sole();
        $this->assertSame('settled',$u->status);
        $this->assertSame('native_provider',$u->usage_source);
        $this->assertNull($u->prompt_tokens);
        $this->assertNull($u->completion_tokens);
        $this->assertNull($u->input_price_id);
        $this->assertSame(500,(int)$u->provider_cost_micro);
        $this->assertSame(53,(int)$u->sell_irt);
        $this->assertSame(6,(int)$u->tax_irt);
        $this->assertSame(500,(int)$u->margin_bp);
        $this->assertSame(900,$u->native_usage['runtime_ms']);
        $this->assertSame(1000000-59,$this->balance($customer));
        $this->assertArrayNotHasKey('cost',$first->json('inference_status'));
        $this->infer($key,headers:['Idempotency-Key'=>'native-once'])->assertOk()->assertHeader('Idempotent-Replayed','true');
        $this->assertSame(1,AiUsage::count());
        $this->assertSame(1,AiCall::count());
        Http::assertSentCount(1);
        Http::assertSent(fn($r)=>!isset($r['model'])&&!isset($r['max_tokens'])&&$r['num_images']===1);
    }

    public function test_native_scope_wallet_and_project_budget_prevent_provider_spending(): void
    {
        Http::fake();
        [, $textKey]=$this->greenKey();$this->infer($textKey)->assertStatus(403);
        [, $emptyKey]=$this->greenKey(0,tokenOver:['abilities'=>['ai:native']]);$this->infer($emptyKey)->assertStatus(402);
        [, $budgetKey]=$this->greenKey(projectOver:['monthly_budget_irt'=>1,'budget_period'=>'monthly'],tokenOver:['abilities'=>['ai:native']]);
        $this->infer($budgetKey)->assertStatus(402)->assertJsonPath('code','budget_exceeded');
        Http::assertNothingSent();$this->assertSame(0,AiUsage::count());
    }

    public function test_callbacks_remote_media_premium_tier_and_unbounded_sizes_are_rejected(): void
    {
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);Http::fake();
        foreach ([['webhook'=>'https://example.invalid'],['service_tier'=>'priority'],['width'=>999999],
            ['num_images'=>2],['stream'=>true],['num_inference_steps'=>9999],['unknown'=>true]] as $invalid) $this->infer($key,$invalid)->assertStatus(422);
        $this->nativeModel('PrunaAI/p-image-Edit');
        $this->infer($key,['images'=>['http://127.0.0.1/private']])->assertStatus(422);
        Http::assertNothingSent();$this->assertSame(0,AiUsage::count());
    }

    public function test_price_drift_and_missing_native_contract_close_before_spending(): void
    {
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);Http::fake();
        $this->model->update(['capabilities'=>['native_tariff_hash'=>'changed']]);
        $this->infer($key)->assertStatus(503)->assertJsonPath('code','native_contract_unavailable');
        $this->nativeModel('openai/clip-vit-base-patch32','classification');
        $this->infer($key)->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_missing_or_failed_provider_cost_keeps_reservation_pending(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/inference/*'=>Http::response(['images'=>['output'],'inference_status'=>['status'=>'succeeded']])]);
        $this->infer($key)->assertStatus(502)->assertHeader('x-should-retry','false');
        $this->assertSame('unknown_pending',AiUsage::sole()->status);
        $this->assertSame(1000000,$this->balance($customer));
        $this->assertLessThan(1000000,$this->available($customer));
        $this->assertNull(AiNative::parse(['images'=>['x'],'inference_status'=>['status'=>'failed','cost'=>0]],'{"inference_status":{"cost":0}}'));
    }

    public function test_audio_bounds_measure_pcm_duration_and_unicode_characters(): void
    {
        $this->nativeModel('Qwen/Qwen3-TTS','audio');
        $prepared=app(AiNative::class)->prepare(['input'=>'سلام'],$this->model);
        $this->assertSame(80,$prepared->native['max_cost_micro']);
        $this->assertSame('default',$prepared->body()['service_tier']);
        $this->nativeModel('openai/whisper-large-v3-turbo','audio');
        $pcm=str_repeat("\0",32000);
        $wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
        $prepared=app(AiNative::class)->prepare(['audio'=>'data:audio/wav;base64,'.base64_encode($wav)],$this->model);
        $this->assertSame(333,$prepared->native['max_cost_micro']);
        $this->expectException(\App\Services\Ai\AiRequestException::class);
        app(AiNative::class)->prepare(['audio'=>'data:audio/wav;base64,'.base64_encode(substr($wav,0,60))],$this->model);
    }

    public function test_native_five_percent_markup_covers_fractional_cost_and_tax_is_separate(): void
    {
        $quote=app(AiPricing::class)->quote($this->model);
        foreach (['0.0000001','0.000333','0.0125','0.0225001','1.239999'] as $cost) {
            $charge=app(AiPricing::class)->chargeCost($quote,$cost,1000);
            $this->assertGreaterThanOrEqual($charge['cost_irt'],$charge['sell']);
            $this->assertGreaterThanOrEqual((int)ceil($charge['cost_irt']*105/100),$charge['sell']);
            $this->assertGreaterThanOrEqual((int)ceil($charge['cost_micro']*100000*1.05/1000000),$charge['sell']);
            $this->assertSame($charge['sell']+$charge['tax'],$charge['charged']);
        }
    }

    public function test_asr_observed_receipt_fits_the_family_reservation_ceiling(): void
    {
        $this->nativeModel('openai/whisper-large-v3','audio');
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        $pcm=str_repeat("\0",198000);
        $wav='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',strlen($pcm)).$pcm;
        $body=['model'=>$this->model->slug,'audio'=>'data:audio/wav;base64,'.base64_encode($wav)];
        $prepared=app(AiNative::class)->prepare($body,$this->model);
        $this->assertSame(3094,$prepared->native['max_cost_micro']);
        Http::fake(['*/inference/*'=>Http::response('{"text":"Hello. This is a clear voice test for ServerNet.","inference_status":{"cost":0.00309375,"runtime_ms":4934}}')]);
        $this->withHeaders(['Authorization'=>'Bearer '.$key])->postJson('/v1/inference',$body)->assertOk();
        $u=AiUsage::sole();$this->assertSame('settled',$u->status);$this->assertFalse($u->needs_review);
        $this->assertSame(3094,(int)$u->provider_cost_micro);
        $this->assertLessThanOrEqual((int)$u->hold_irt,(int)$u->charged_irt);
        $this->assertGreaterThanOrEqual((int)ceil($u->cost_irt*105/100),(int)$u->sell_irt);
    }

    public function test_native_results_are_private_to_customer_and_project(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/inference/*'=>Http::response('{"images":["output"],"inference_status":{"cost":0.0005}}')]);
        $this->infer($key)->assertOk();$id=AiUsage::sole()->public_id;
        $this->withHeaders(['Authorization'=>'Bearer '.$key])->getJson('/v1/inference/'.$id)->assertOk()->assertJsonPath('images.0','output');
        [, $other]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        $this->withHeaders(['Authorization'=>'Bearer '.$other])->getJson('/v1/inference/'.$id)->assertStatus(404);
    }

    public function test_video_receipt_processing_retry_and_final_result_charge_once(): void
    {
        $this->nativeModel('FastVideo/FastWan-QAD-FP8-1.3B','video');
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        $auth=\App\Services\Ai\AiAdmission::authorize(\App\Models\CustomerApiToken::findByPlain($key),'ai:native','127.0.0.1');
        Http::fake(['*/inference/*'=>Http::response('{"video_url":"data:video/mp4;base64,output","inference_status":{"cost":0.0125,"output_length":5}}')]);
        $caller=app(\App\Services\Ai\AiCaller::class);$body=['model'=>$this->model->slug,'prompt'=>'A blue cube rotates.'];
        $first=$caller->handle($auth,$body,'video-once','native',true);
        $this->assertSame(202,$first->status);
        $this->assertSame('reserved',AiUsage::sole()->status);
        $again=$caller->handle($auth,$body,'video-once','native',true);
        $this->assertSame($first->response['id'],$again->response['id']);
        $this->assertSame(202,$caller->nativeResult($auth,$first->response['id'])->status);
        $this->assertSame(1,AiUsage::count());Http::assertNothingSent();
        app()->terminate();
        $result=$caller->nativeResult($auth,$first->response['id']);
        $this->assertSame(200,$result->status);
        $u=AiUsage::sole();$this->assertSame('settled',$u->status);
        $this->assertSame(12500,(int)$u->provider_cost_micro);
        $this->assertSame(1000000-(int)$u->charged_irt,$this->balance($customer));
        Http::assertSentCount(1);
    }

    public function test_gap_image_tariff_is_not_misreported_as_an_upstream_cost_receipt(): void
    {
        $this->provider->update(['slug'=>'gapgpt']);
        Setting::put('ai_provider_gapgpt_base_url','https://api.gapgpt.app/v1');
        Setting::putSecret('ai_provider_gapgpt_key','test-only');
        $this->nativeModel('flux-1-schnell');
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/images/generations'=>Http::response(['data'=>[['url'=>'https://media.example.test/output.png']]])]);
        $this->withHeaders(['Authorization'=>'Bearer '.$key])->postJson('/v1/inference',[
            'model'=>$this->model->slug,'prompt'=>'A blue cube.'])->assertOk();
        $u=AiUsage::sole();$this->assertSame('settled',$u->status);
        $this->assertSame('native_tariff',$u->usage_source);
        $this->assertNull($u->provider_cost_micro);
        $this->assertSame(1400,(int)$u->cost_micro);
        $this->assertSame(147,(int)$u->sell_irt);
        Http::assertSent(fn($r)=>$r['model']==='flux-1-schnell'&&$r['size']==='1024x1024'&&$r['n']===1);
    }

    public function test_every_ready_contract_has_an_admissible_bounded_documented_request(): void
    {
        $contracts=json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')),true);
        $image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $pcm=str_repeat("\0",32000);
        $audio='data:audio/wav;base64,'.base64_encode('RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',strlen($pcm)).$pcm);
        $count=0;
        foreach ($contracts as $id=>$contract) {
            if (!$contract['supported']) continue;
            [$provider,$name]=explode(':',$id,2);$this->provider->update(['slug'=>$provider]);
            $this->nativeModel($name,match($contract['task']){'text-to-speech','automatic-speech-recognition'=>'audio','text-to-video'=>'video','text-to-music'=>'music',default=>'image'});
            $this->model->unsetRelation('provider');
            $body=$contract['defaults'];
            foreach ($contract['schema']['required']??[] as $key) {
                if (array_key_exists($key,$body))continue;
                $rule=$contract['schema']['properties'][$key];
                $value=$rule['default']??$rule['enum'][0]??match($rule['type']??'string') {
                    'integer','number'=>$rule['minimum']??1,'boolean'=>false,'array'=>['A blue cube.'],default=>'A blue cube.',
                };
                if(in_array($key,$contract['media_fields'],true)) {
                    $value=str_contains($key,'audio')?$audio:$image;
                    if(($rule['type']??null)==='array')$value=[$value];
                }
                $body[$key]=$value;
            }
            try {$prepared=app(AiNative::class)->prepare($body,$this->model);}
            catch(\Throwable $e){$this->fail($id.': '.$e->getMessage());}
            $this->assertGreaterThan(0,$prepared->native['max_cost_micro'],$id);
            $this->assertLessThanOrEqual(20000000,$prepared->native['max_cost_micro'],$id);
            $display=AiNative::unitPrice(app(AiPricing::class)->quote($this->model));
            $this->assertGreaterThan(0,$display['sell'],$id);
            $count++;
        }
        $this->assertSame(78,$count);
        Http::assertNothingSent();
    }
}
