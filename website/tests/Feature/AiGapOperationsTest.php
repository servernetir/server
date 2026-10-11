<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AdditionalProviderCatalog;
use App\Services\Ai\AiGapOperations;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiPayload;
use App\Services\Ai\AiPricing;
use App\Services\AiProviders\OpenAiCompatibleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiGapOperationsTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(['fx_fee_bp'=>0],cached:false);
        Setting::put('ai_margin_pct','5');
        Setting::put('ai_provider_gapgpt_base_url','https://api.gapgpt.app/v1');
        Setting::putSecret('ai_provider_gapgpt_key','test-gap-key');
        $gap = AiProvider::firstOrCreate(['slug'=>'gapgpt'],['name'=>'GapGPT','driver'=>'OpenAI-Compatible']);
        $gap->update(['enabled'=>true,'live_calls_enabled'=>true,'commercial_enabled'=>true,'resale_allowed'=>true,
            'agreement_status'=>AiProvider::STATUS_SIGNED,'billing_currency_code'=>'USD','fx_fee_bp'=>0,'driver'=>'OpenAI-Compatible']);
        $this->provider=$gap;
        $this->model->update(['ai_provider_id'=>$gap->id]);
        $this->operationModel('gpt-image-1-mini');
    }

    private function operationModel(string $name): void
    {
        $contracts=json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')),true);
        $contract=$contracts[$name];
        $category=str_contains($name,'tts') ? 'audio' : (in_array($name,['babbage-002','davinci-002','typesafe/jev-1.13'],true) ? 'classification' : 'image');
        $this->model->update(['upstream_model'=>$name,'category'=>$category,'capabilities'=>[
            'native_tariff_hash'=>AiNative::fingerprint($contract['pricing']), 'catalogue_source'=>'gapgpt','catalogue_at'=>now()->toIso8601String(),'execution_ready'=>true]]);
        $this->model->refresh()->setRelation('provider',$this->provider);
    }

    private function inference(string $key,array $body,array $headers=[])
    {
        return $this->withHeaders(['Authorization'=>'Bearer '.$key]+$headers)->postJson('/v1/inference',['model'=>$this->model->slug]+$body);
    }

    private function png(int $width = 1024, int $height = 1024): string
    {
        $chunk = fn($type,$data) => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return base64_encode("\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,0,0,0,0))
            .$chunk('IDAT',gzcompress(str_repeat("\0",($width+1)*$height))).$chunk('IEND',''));
    }

    public function test_known_tariffs_prepare_and_changed_feed_closes_the_operation(): void
    {
        $contracts=json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')),true);
        $supported=array_filter($contracts,fn($c)=>$c['supported']);
        $this->assertCount(8,$supported);
        foreach($supported as $name=>$contract){
            $this->operationModel($name);
            $this->assertNotNull(AiNative::candidate('gapgpt',$name,$contract['pricing']));
            $client=str_contains($name,'tts') ? ['input'=>'Hello.'] : ['prompt'=>'A blue square.'];
            if ($name==='typesafe/jev-1.13') $client=['state'=>'Refund requested.','questions'=>['refund'=>['type'=>'noul','instructions'=>'Is a refund requested?']]];
            $prepared=app(AiNative::class)->prepare($client,$this->model);
            $this->assertGreaterThan(0,$prepared->native['max_cost_micro']);
            $price=AiNative::unitPrice(app(AiPricing::class)->quote($this->model));
            $this->assertSame('call',$price['unit']);
            $this->assertGreaterThan(0,$price['sell']);
            $changed=$contract['pricing'];$changed['output_usd_per_million']='99';
            $this->assertNull(AiNative::candidate('gapgpt',$name,$changed));
        }
        foreach(['tts-1','gpt-4o-mini-tts','whisper-1','gapgpt/whisper-1','text-moderation-latest'] as $name){
            $this->assertNull(AiGapOperations::catalogPricing($name,['pricing_type'=>'token'],'{"input_token_price":15,"output_token_price":15}'));
        }
    }

    public function test_raw_token_operation_rates_match_contract_without_float_loss(): void
    {
        $rows=[];for($i=0;$i<20;$i++)$rows[]=['model_name'=>'chat-'.$i,'model_type'=>'text','pricing_type'=>'token','input_token_price'=>1,'output_token_price'=>2];
        foreach(['gpt-image-1'=>[10,40],'gpt-image-1-mini'=>[2,4],'gpt-image-2'=>[8,30],
            'babbage-002'=>[0.4,0.4],'davinci-002'=>[2,2],'gemini-2.5-flash-preview-tts'=>[0.5,10],'gemini-2.5-pro-preview-tts'=>[2,40]] as $name=>$price){
            $rows[]=['model_name'=>$name,'model_type'=>str_contains($name,'tts')?'text_to_voice':(str_starts_with($name,'gpt-image')?'image':'text'),
                'pricing_type'=>'token','input_token_price'=>$price[0],'output_token_price'=>$price[1]];
        }
        $parsed=AdditionalProviderCatalog::gapRows(json_encode(['data'=>$rows],JSON_THROW_ON_ERROR));
        foreach(array_slice($rows,20) as $row){$this->assertNotNull(AiNative::candidate('gapgpt',$row['model_name'],$parsed[$row['model_name']]['native_pricing']));}
        $this->assertSame('classification',$parsed['babbage-002']['category']);
    }

    public function test_real_image_token_usage_settles_known_tariff_and_replay_costs_nothing(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/images/generations'=>Http::response(['data'=>[['b64_json'=>$this->png()]],
            'usage'=>['input_tokens'=>10,'output_tokens'=>272,'total_tokens'=>282]])]);
        $body=['prompt'=>'A blue square.'];
        $this->inference($key,$body,['Idempotency-Key'=>'gap-image-once'])->assertOk();
        $u=AiUsage::sole();
        $this->assertSame('settled',$u->status);$this->assertFalse($u->needs_review);
        $this->assertSame('native_tariff',$u->usage_source);$this->assertNull($u->provider_cost_micro);
        $this->assertSame(10,$u->prompt_tokens);$this->assertSame(272,$u->completion_tokens);
        $this->assertSame(1108,(int)$u->cost_micro);$this->assertSame(111,(int)$u->cost_irt);
        $this->assertSame(117,(int)$u->sell_irt);$this->assertSame(12,(int)$u->tax_irt);
        $this->assertSame(1_000_000-129,$this->balance($customer));
        $this->inference($key,$body,['Idempotency-Key'=>'gap-image-once'])->assertOk()->assertHeader('Idempotent-Replayed','true');
        Http::assertSentCount(1);$this->assertSame(1,AiUsage::count());
        Http::assertSent(fn($r)=>$r['model']==='gpt-image-1-mini'&&$r['n']===1&&$r['size']==='1024x1024'&&$r['quality']==='low');
    }

    public function test_normalized_image_aliases_require_consistent_pairs_totals_and_actual_dimensions(): void
    {
        $this->operationModel('gpt-image-1');
        $snapshot = app(AiNative::class)->prepare(['prompt'=>'Blue.'],$this->model)->native;
        $body = ['data'=>[['b64_json'=>$this->png()]], 'usage'=>[
            'prompt_tokens'=>14,'completion_tokens'=>272,'total_tokens'=>286,'cost'=>0.01095]];
        $parsed = AiGapOperations::parse($body,json_encode($body),$snapshot);
        $this->assertSame(14,$parsed['prompt']); $this->assertSame(272,$parsed['completion']);
        $this->assertSame('0.011020',$parsed['estimated_cost']); $this->assertTrue($parsed['tariff_cost']);
        $same = $body; $same['usage'] += ['input_tokens'=>14,'output_tokens'=>272];
        $this->assertNotNull(AiGapOperations::parse($same,'',$snapshot));
        foreach ([['input_tokens'=>0,'output_tokens'=>0],['input_tokens'=>14],['input_tokens'=>15,'output_tokens'=>272],
            ['total_tokens'=>285],['total_tokens'=>'286'],['completion_tokens'=>-1],['prompt_tokens'=>14.0]] as $bad) {
            $copy=$body; $copy['usage']=array_replace($copy['usage'],$bad);
            $this->assertNull(AiGapOperations::parse($copy,'',$snapshot));
        }
        foreach ([$this->png(512,512),'not-base64',base64_encode('not-an-image')] as $bad) {
            $copy=$body; $copy['data'][0]['b64_json']=$bad;
            $this->assertNull(AiGapOperations::parse($copy,'',$snapshot));
        }
        $url=$body; $url['data']=[['url'=>'https://output.invalid/image']];
        $this->assertNull(AiGapOperations::parse($url,'',$snapshot));
    }

    public function test_unknown_usage_binary_audio_remote_inputs_and_extra_generations_are_not_guessed(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);Http::fake(['*/images/generations'=>Http::response(['data'=>[['url'=>'https://output.invalid/image']]])]);
        foreach([['n'=>2],['size'=>'auto'],['image'=>'http://127.0.0.1'],['quality'=>'auto'],['quality'=>'high'],['quality'=>'medium'],['stream'=>true],['prompt'=>str_repeat('a',4097)]] as $invalid)
            $this->inference($key,$invalid+['prompt'=>'Blue.'])->assertStatus(422);
        Http::assertNothingSent();
        $this->inference($key,['prompt'=>'Blue.'])->assertStatus(502)->assertHeader('x-should-retry','false');
        $this->assertSame('unknown_pending',AiUsage::sole()->status);$this->assertSame(1_000_000,$this->balance($customer));
        $this->assertLessThan(1_000_000,$this->available($customer));
        $prepared=app(AiNative::class)->prepare(['prompt'=>'Blue.'],$this->model);
        $this->assertNull(AiGapOperations::parse(['data'=>[['b64_json'=>'x']],
            'usage'=>['input_tokens'=>10,'output_tokens'=>272,'total_tokens'=>999]],'',$prepared->native));
    }

    public function test_legacy_prompt_completion_has_one_bounded_generation_and_actual_usage(): void
    {
        $this->operationModel('babbage-002');
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/completions'=>Http::response(['choices'=>[['text'=>'OK']], 'usage'=>['prompt_tokens'=>2,'completion_tokens'=>3,'total_tokens'=>5]])]);
        $this->inference($key,['prompt'=>'Say OK.','max_tokens'=>8])->assertOk();
        $u=AiUsage::sole();$this->assertSame(2,(int)$u->cost_micro);$this->assertFalse($u->needs_review);$this->assertNull($u->provider_cost_micro);
        Http::assertSent(fn($r)=>$r->url()==='https://api.gapgpt.app/v1/completions'&&$r['max_tokens']===8&&$r['best_of']===1&&$r['n']===1&&!isset($r['messages']));
        $this->inference($key,['prompt'=>'OK','best_of'=>2])->assertStatus(422);
        $this->inference($key,['prompt'=>['OK']])->assertStatus(422);
    }

    public function test_gemini_tts_full_model_reservation_and_reported_tokens(): void
    {
        $this->operationModel('gemini-2.5-flash-preview-tts');
        $prepared=app(AiNative::class)->prepare(['input'=>'Hello.','max_output_tokens'=>32],$this->model);
        $this->assertSame(167936,$prepared->native['max_cost_micro']);
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/models/*:generateContent'=>Http::response(['candidates'=>[['content'=>['parts'=>[['inlineData'=>['mimeType'=>'audio/L16;codec=pcm;rate=24000','data'=>'AAAA']]]]]],
            'usageMetadata'=>['promptTokenCount'=>3,'candidatesTokenCount'=>139,'totalTokenCount'=>142]])]);
        $this->inference($key,['input'=>'Hello.'])->assertOk();
        $u=AiUsage::sole();$this->assertSame(1392,(int)$u->cost_micro);$this->assertSame(139,$u->completion_tokens);$this->assertNull($u->provider_cost_micro);
        Http::assertSent(fn($r)=>!isset($r['model'])&&$r['generationConfig']['responseModalities']===['AUDIO']
            &&$r['generationConfig']['candidateCount']===1&&$r['generationConfig']['maxOutputTokens']===2048);
        $c=$prepared->native;
        $this->assertNull(AiGapOperations::parse(['candidates'=>[['content'=>[]]],'usageMetadata'=>['promptTokenCount'=>3,'totalTokenCount'=>142,'candidatesTokenCount'=>139]],'', $c));
    }

    public function test_gap_compatibility_completion_cap_is_equal_on_both_fields(): void
    {
        Http::fake(['*/chat/completions'=>Http::response(['choices'=>[['message'=>['content'=>'OK']]],'usage'=>['prompt_tokens'=>3,'completion_tokens'=>8]])]);
        $this->model->update(['upstream_model'=>'deepseek-reasoner','category'=>'chat','context_tokens'=>4096,'max_output_tokens'=>1024]);
        $this->model->refresh()->setRelation('provider',$this->provider);
        $prepared=app(AiPayload::class)->prepare(['messages'=>[['role'=>'user','content'=>'OK']],'max_tokens'=>8],$this->model);
        (new OpenAiCompatibleDriver($this->provider))->chat($this->model,$prepared->body());
        Http::assertSent(fn($r)=>$r['max_tokens']===8&&$r['max_completion_tokens']===8&&$r['thinking']===['type'=>'disabled']&&$r['reasoning_effort']==='none');
    }
}
