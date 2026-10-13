<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AdditionalProviderCatalog;
use App\Services\Ai\AiGapOperations;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiNativeResponseRecovery;
use App\Services\Ai\AiPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiGapDecisionsTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(['fx_fee_bp'=>0],cached:false);
        Setting::put('ai_margin_pct','5');
        Setting::put('ai_provider_gapgpt_base_url','https://api.gapgpt.app/v1');
        Setting::putSecret('ai_provider_gapgpt_key','test-gap-key');
        $this->provider=AiProvider::firstOrCreate(['slug'=>'gapgpt'],['name'=>'GapGPT','driver'=>'OpenAI-Compatible']);
        $this->provider->update(['enabled'=>true,'live_calls_enabled'=>true,'commercial_enabled'=>true,'resale_allowed'=>true,
            'agreement_status'=>AiProvider::STATUS_SIGNED,'billing_currency_code'=>'USD','fx_fee_bp'=>0]);
        $contract=json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')),true)['typesafe/jev-1.13'];
        $this->model->update(['ai_provider_id'=>$this->provider->id,'upstream_model'=>'typesafe/jev-1.13','category'=>'classification',
            'capabilities'=>['native_tariff_hash'=>AiNative::fingerprint($contract['pricing']),'catalogue_source'=>'gapgpt',
                'catalogue_at'=>now()->toIso8601String(),'execution_ready'=>true]]);
        $this->model->refresh()->setRelation('provider',$this->provider);
    }

    private function requestBody(): array
    {
        return ['state'=>['text'=>'The customer requests a refund for a duplicate payment.'],'questions'=>[
            'refund'=>['type'=>'noul','instructions'=>['question'=>'Is a refund requested?']],
            'team'=>['type'=>'choice','instructions'=>['Which team should handle this?'],
                'criteria'=>['billing'=>'Payments and refunds','technical'=>'Software errors']],
            'urgency'=>['type'=>'score','instructions'=>'How urgent is it?','criteria'=>['Routine','Urgent','Emergency']],
        ]];
    }

    private function receipt(): array
    {
        return ['model'=>'typesafe/jev-1.13-20260917','answers'=>[
            'refund'=>['type'=>'noul','noul'=>0.95],
            'team'=>['type'=>'choice','choice'=>'billing','probabilities'=>['technical'=>0.1,'billing'=>0.9],'confidence'=>0.8],
            'urgency'=>['type'=>'score','score'=>1.15,'legend'=>['Routine','Urgent','Emergency'],
                'probabilities'=>[0,0.85,0.15],'confidence'=>0.77],
        ],'usage'=>['input_tokens'=>357,'output_tokens'=>38,'total_tokens'=>395,'cost'=>0.000014994]];
    }

    private function inference(string $key, array $body, string $idempotency='gap-decision-once')
    {
        return $this->withHeaders(['Authorization'=>'Bearer '.$key,'Idempotency-Key'=>$idempotency])
            ->postJson('/v1/inference',['model'=>$this->model->slug]+$body);
    }

    public function test_input_only_tariff_is_exact_and_catalogue_does_not_misclassify_decisions_as_chat(): void
    {
        $rows=[]; for($i=0;$i<20;$i++)$rows[]=['model_name'=>'chat-'.$i,'model_type'=>'text','pricing_type'=>'token','input_token_price'=>1,'output_token_price'=>2];
        $rows[]=['model_name'=>'typesafe/jev-1.13','model_type'=>'text','pricing_type'=>'token','input_token_price'=>0.042,'output_token_price'=>0];
        $row=AdditionalProviderCatalog::gapRows(json_encode(['data'=>$rows],JSON_THROW_ON_ERROR))['typesafe/jev-1.13'];
        $this->assertSame('classification',$row['category']); $this->assertSame([],$row['rates']);
        $this->assertSame(['type'=>'token_usage','input_usd_per_million'=>'0.042','output_usd_per_million'=>'0'],$row['native_pricing']);
        $contract=AiNative::candidate('gapgpt','typesafe/jev-1.13',$row['native_pricing']); $this->assertNotNull($contract);
        $changed=$row['native_pricing']; $changed['output_usd_per_million']='0.01';
        $this->assertNull(AiNative::candidate('gapgpt','typesafe/jev-1.13',$changed));
        foreach (['gpt-image-1','gemini-2.5-flash-preview-tts','text-moderation-latest'] as $name)
            $this->assertNull(AiGapOperations::catalogPricing($name,['pricing_type'=>'token'],'{"input_token_price":0.042,"output_token_price":0}'));
        $prepared=app(AiNative::class)->prepare($this->requestBody(),$this->model);
        $this->assertSame(2688,$prepared->native['max_cost_micro']);
        $this->assertSame(2688,AiGapOperations::costMicros($contract['pricing'],64000,999999));
        $this->assertSame('call',AiNative::unitPrice(app(AiPricing::class)->quote($this->model))['unit']);
    }

    public function test_three_typed_answers_bill_actual_input_only_and_customer_replay_is_single_debit(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/systemone'=>Http::response($this->receipt())]);
        $response=$this->inference($key,$this->requestBody())->assertOk()->assertJsonPath('answers.refund.noul',0.95)
            ->assertJsonMissingPath('usage.cost');
        $u=AiUsage::sole(); $this->assertSame('settled',$u->status); $this->assertFalse($u->needs_review);
        $this->assertSame('native_tariff',$u->usage_source); $this->assertNull($u->provider_cost_micro);
        $this->assertSame(357,$u->prompt_tokens); $this->assertSame(38,$u->completion_tokens);
        $this->assertSame(15,(int)$u->cost_micro); $this->assertGreaterThanOrEqual((int)ceil($u->cost_irt*1.05),(int)$u->sell_irt);
        $this->assertSame(3,$u->native_usage['answer_count']);
        $this->assertSame(['type'=>'choice','keys'=>['billing','technical']],$u->native_snapshot['contract']['response_schema']['team']);
        $this->assertArrayNotHasKey('state',$u->native_snapshot['contract']);
        $this->assertStringNotContainsString('duplicate payment',json_encode($u->native_snapshot));
        $before=$this->balance($customer);
        $this->inference($key,$this->requestBody())->assertOk()->assertHeader('Idempotent-Replayed','true')
            ->assertExactJson($response->json());
        $this->assertSame($before,$this->balance($customer)); $this->assertSame(1,AiUsage::count()); Http::assertSentCount(1);
        Http::assertSent(fn($r)=>$r->url()==='https://api.gapgpt.app/v1/systemone'&&$r['model']==='typesafe/jev-1.13'
            &&$r['state']===$this->requestBody()['state']&&!isset($r['messages'])&&!isset($r['max_tokens']));
    }

    public function test_invalid_decision_inputs_never_reserve_or_contact_supplier(): void
    {
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]); Http::fake();
        $request=$this->requestBody();
        $invalid=[['state'=>str_repeat('a',4097)],['state'=>''],['questions'=>[]],['questions'=>array_fill(0,5,['type'=>'noul','instructions'=>'Yes?'])],
            ['questions'=>['refund'=>['type'=>'chat','instructions'=>'Yes?']]],['questions'=>['bad.id'=>['type'=>'noul','instructions'=>'Yes?']]],
            ['questions'=>['refund'=>['type'=>'noul','instructions'=>'Yes?','max_tokens'=>10]]],
            ['questions'=>['refund'=>['type'=>'noul','instructions'=>str_repeat('a',1025)]]],
            ['questions'=>['refund'=>['type'=>'noul','instructions'=>'Yes?','criteria'=>['other'=>'Other']]]],
            ['questions'=>['team'=>['type'=>'choice','instructions'=>'Team?','criteria'=>['only'=>'One']]]],
            ['questions'=>['team'=>['type'=>'choice','instructions'=>'Team?','criteria'=>array_combine(range('a','i'),range('a','i'))]]],
            ['questions'=>['urgency'=>['type'=>'score','instructions'=>'Urgent?','criteria'=>['one'=>'First','two'=>'Second']]]],
            ['stream'=>true],['temperature'=>1],['max_tokens'=>10],['webhook'=>'https://example.invalid/']];
        foreach($invalid as $body) $this->inference($key,array_replace($request,$body))->assertStatus(422);
        Http::assertNothingSent(); $this->assertSame(0,AiUsage::count());
    }

    public function test_ambiguous_usage_or_incorrect_answer_payload_is_not_success_and_output_is_free(): void
    {
        $snapshot=app(AiNative::class)->prepare($this->requestBody(),$this->model)->native; $valid=$this->receipt();
        $zero=$valid; $zero['usage']=['input_tokens'=>357,'output_tokens'=>0];
        $parsed=AiGapOperations::parse($zero,'',$snapshot); $this->assertSame(0,$parsed['completion']);
        $this->assertSame('0.000015',$parsed['estimated_cost']);
        $normalized=$valid; $normalized['usage']=['prompt_tokens'=>357,'completion_tokens'=>38];
        $this->assertNotNull(AiGapOperations::parse($normalized,'',$snapshot));
        foreach ([['input_tokens'=>0,'output_tokens'=>38],['input_tokens'=>'357','output_tokens'=>38],['input_tokens'=>357],
            ['input_tokens'=>357,'output_tokens'=>38,'total_tokens'=>394],['input_tokens'=>357,'output_tokens'=>38,'prompt_tokens'=>356,'completion_tokens'=>38]] as $usage) {
            $bad=$valid; $bad['usage']=$usage; $this->assertNull(AiGapOperations::parse($bad,'',$snapshot));
        }
        $badAnswers=[];
        $copy=$valid['answers']; unset($copy['refund']); $badAnswers[]=$copy;
        $copy=$valid['answers']; $copy['unexpected']=['type'=>'noul','noul'=>1]; $badAnswers[]=$copy;
        foreach([['refund','noul',1.1],['refund','noul','0.95'],['team','choice','other'],['team','confidence',-0.1],
            ['team','probabilities',['billing'=>0.1,'technical'=>0.1]],['urgency','score',3],['urgency','legend',[]],
            ['team','probabilities',['billing'=>0.1,'technical'=>0.9]],['urgency','score',0.2],
            ['urgency','probabilities',[0=>0.1,1=>0.8,3=>0.1]]] as [$id,$field,$value]) {
            $copy=$valid['answers']; $copy[$id][$field]=$value; $badAnswers[]=$copy;
        }
        foreach($badAnswers as $answers) { $bad=$valid; $bad['answers']=$answers; $this->assertNull(AiGapOperations::parse($bad,'',$snapshot)); }
        [, $key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/systemone'=>Http::response(['answers'=>[],'usage'=>['input_tokens'=>357,'output_tokens'=>0]])]);
        $this->inference($key,$this->requestBody())->assertStatus(502)->assertHeader('x-should-retry','false');
        $this->assertSame('unknown_pending',AiUsage::sole()->status); $this->assertFalse(AiCall::sole()->ok);
    }

    public function test_encrypted_late_recovery_uses_frozen_answer_shape_and_tariff_without_another_supplier_call(): void
    {
        [$customer,$key]=$this->greenKey(tokenOver:['abilities'=>['ai:native']]);
        Http::fake(['*/systemone'=>Http::response(['answers'=>[],'usage'=>['input_tokens'=>357,'output_tokens'=>38]])]);
        $this->inference($key,$this->requestBody())->assertStatus(502); $u=AiUsage::sole(); $call=AiCall::sole();
        $bad=$this->receipt(); $bad['answers']=['other'=>['type'=>'noul','noul'=>1]];
        $call->update(['response_encrypted'=>[AiNativeResponseRecovery::RAW_FIELD=>json_encode($bad)]]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame('unknown_pending',$u->fresh()->status); $this->assertFalse($call->fresh()->ok);
        $this->assertSame(1_000_000,$this->balance($customer));
        $call->update(['response_encrypted'=>[AiNativeResponseRecovery::RAW_FIELD=>json_encode($this->receipt())]]);
        $this->model->update(['capabilities'=>array_replace($this->model->capabilities,['native_tariff_hash'=>'changed-current-contract'])]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame('settled',$u->fresh()->status); $this->assertSame(15,(int)$u->fresh()->cost_micro);
        $this->assertTrue($call->fresh()->ok); $this->assertNull($u->fresh()->provider_cost_micro);
        $before=$this->balance($customer); $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame($before,$this->balance($customer)); Http::assertSentCount(1); $this->assertSame(1,AiUsage::count());
    }

    public function test_three_language_model_pages_show_valid_decision_example_and_hide_tax_and_media_download_claim(): void
    {
        Http::fake();
        foreach (['fa','en','tr'] as $locale) {
            app()->setLocale($locale);
            $quote=app(AiPricing::class)->quote($this->model);
            $page=app(\App\Services\Ai\AiModelPage::class)->build($this->model,['ready'=>true,'quote'=>$quote]);
            $this->assertSame('inference',$page['example']['path']);
            $this->assertSame('noul',$page['example']['body']['questions']['refund']['type']);
            $this->assertNotNull(app(AiNative::class)->prepare($page['example']['body'],$this->model));
            $this->assertSame('string | object | array',$page['gatewaySpec']['fields'][0]['type']);
            $this->assertStringNotContainsString(__('ai_model.native_file_download_ready'),$page['workflow']);
            $route=$locale==='fa' ? '/ai/models/' : '/'.$locale.'/ai/models/';
            $response=$this->get($route.$this->model->slug)->assertOk()->assertSee('questions')->assertSee('refund');
            $this->assertStringNotContainsString(__('ai.vat_notice'),$response->getContent());
        }
        Http::assertNothingSent();
    }
}
