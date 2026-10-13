<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** GapGPT operations with priced, reported token quantities; binary audio is never guessed. */
final class AiGapOperations
{
    private static ?array $contracts = null;

    public static function definition(AiModel $model): ?array
    {
        if ($model->provider?->slug !== 'gapgpt') return null;
        self::$contracts ??= json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')), true, flags: JSON_THROW_ON_ERROR);
        $contract = self::$contracts[$model->upstream_model] ?? null;
        return $contract && ($contract['supported'] ?? false)
            && ($model->capabilities['native_tariff_hash'] ?? null) === AiNative::fingerprint($contract['pricing']) ? $contract : null;
    }

    public static function candidate(string $provider, string $model, array $pricing): ?array
    {
        $temporary = new AiModel(['upstream_model'=>$model, 'capabilities'=>['native_tariff_hash'=>AiNative::fingerprint($pricing)]]);
        $temporary->setRelation('provider', new AiProvider(['slug'=>$provider]));
        return self::definition($temporary);
    }

    /** Preserve literal decimals from GapGPT's public price feed, including exponent notation. */
    public static function catalogPricing(string $name, array $row, string $rawObject): ?array
    {
        if (! in_array($name, ['gpt-image-1','gpt-image-1-mini','gpt-image-2','babbage-002','davinci-002',
            'gemini-2.5-flash-preview-tts','gemini-2.5-pro-preview-tts','typesafe/jev-1.13'], true) || ($row['pricing_type'] ?? null) !== 'token') return null;
        $pricing = ['type'=>'token_usage'];
        foreach (['input_token_price'=>'input_usd_per_million', 'output_token_price'=>'output_usd_per_million'] as $key=>$unit) {
            if (! preg_match('/"'.preg_quote($key,'/').'"\s*:\s*([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s*[,}])/', $rawObject, $match)) return null;
            try { $value = BigDecimal::of($match[1]); } catch (\Throwable) { return null; }
            $freeOutput = $name === 'typesafe/jev-1.13' && $key === 'output_token_price' && $value->isZero();
            if ((! $freeOutput && $value->isLessThanOrEqualTo(0)) || $value->isGreaterThan(500)) return null;
            $decimal = (string)$value;
            $pricing[$unit] = str_contains($decimal,'.') ? rtrim(rtrim($decimal,'0'),'.') : $decimal;
        }
        return $pricing;
    }

    public function prepare(array $client, AiModel $model, array $contract): AiPreparedRequest
    {
        if (($client['stream'] ?? false) !== false || strlen(json_encode($client,JSON_THROW_ON_ERROR)) > 32000)
            throw new AiRequestException('invalid_payload','This operation accepts bounded JSON without streaming.',422);
        if ($contract['protocol'] === 'gap-systemone') return $this->prepareDecisions($client,$model,$contract);
        $properties = $contract['schema']['properties'];
        foreach ($client as $key=>$value) {
            if (in_array($key,['model','stream'],true)) continue;
            if (! isset($properties[$key])) throw new AiRequestException('invalid_payload','Unsupported operation parameter: '.$key,422);
            $rule = $properties[$key];
            if (($rule['type'] ?? null) === 'string' && (!is_string($value) || strlen($value) > ($rule['maxBytes'] ?? 4096)
                || mb_strlen($value) < ($rule['minLength'] ?? 0))) throw new AiRequestException('invalid_payload','Invalid operation text: '.$key,422);
            if (($rule['type'] ?? null) === 'integer' && (!is_int($value) || $value < ($rule['minimum'] ?? 1) || $value > ($rule['maximum'] ?? 1)))
                throw new AiRequestException('invalid_payload','Invalid operation count: '.$key,422);
            if (isset($rule['enum']) && !in_array($value,$rule['enum'],true)) throw new AiRequestException('invalid_payload','Invalid operation option: '.$key,422);
        }
        $parameters = array_intersect_key($client,$properties) + $contract['defaults'];
        foreach ($contract['schema']['required'] as $field) {
            if (!isset($parameters[$field]) || $parameters[$field] === '') throw new AiRequestException('invalid_payload','Required operation parameter: '.$field,422);
        }
        $forward = match ($contract['protocol']) {
            'gap-token-images' => $parameters + ['n'=>1,'size'=>'1024x1024','quality'=>'low','output_format'=>'png'],
            'gap-legacy-completions' => $parameters + ['n'=>1,'best_of'=>1,'stream'=>false],
            'gap-gemini-tts' => ['contents'=>[['role'=>'user','parts'=>[['text'=>$parameters['input']]]]],
                'generationConfig'=>['responseModalities'=>['AUDIO'],'candidateCount'=>1,'maxOutputTokens'=>$parameters['max_output_tokens'],
                    'speechConfig'=>['voiceConfig'=>['prebuiltVoiceConfig'=>['voiceName'=>$parameters['voice']]]]]],
            default => throw new AiRequestException('native_contract_unavailable','Operation protocol is unavailable.',503),
        };
        $micros = self::bound($contract,$forward);
        if ($micros < 1 || $micros > 20_000_000) throw new AiRequestException('native_contract_unavailable','Operation tariff must have a finite ceiling.',503);
        $hash = AiPayload::hash(['operation'=>'native','model'=>$model->slug,'body'=>$forward]);
        return new AiPreparedRequest($forward,0,0,0,false,[],$hash,false,'native',['contract'=>$contract,'max_cost_micro'=>$micros]);
    }

    public static function bound(array $contract, array $body): int
    {
        // Jev's documented whole-model input limit covers the state and all questions, including template overhead.
        if ($contract['protocol'] === 'gap-systemone') return self::costMicros($contract['pricing'],$contract['input_ceiling_tokens'],0);
        $text = $contract['protocol'] === 'gap-gemini-tts' ? ($body['contents'][0]['parts'][0]['text'] ?? '') : ($body['prompt'] ?? '');
        $input = $contract['protocol'] === 'gap-gemini-tts' ? $contract['input_ceiling_tokens']
            : strlen((string)$text) + $contract['input_overhead_tokens'];
        // Speech reserves the documented entire model output limit. A shorter requested cap is not assumed to bound an audio provider.
        $output = $contract['protocol'] === 'gap-legacy-completions' ? ($body['max_tokens'] ?? 256) : $contract['output_ceiling_tokens'];
        return self::costMicros($contract['pricing'],$input,$output);
    }

    public static function unitPrice(AiPriceQuote $quote): ?array
    {
        if (($quote->native['pricing']['type'] ?? null) !== 'token_usage') return null;
        $price = app(AiPricing::class)->chargeCost($quote,(string)BigDecimal::of($quote->native['ceiling_cents'])->multipliedBy('0.01'),0);
        return ['sell'=>$price['sell'],'quantity'=>1,'unit'=>'call'];
    }

    public static function parse(array $body, string $raw, ?array $snapshot): ?array
    {
        $contract = $snapshot['contract'] ?? null;
        if (!$contract || ($contract['pricing']['type'] ?? null) !== 'token_usage' || isset($body['error'])) return null;
        $protocol = $contract['protocol'];
        if ($protocol === 'gap-token-images') {
            if (!is_array($body['data'] ?? null) || count($body['data']) !== 1 || !array_is_list($body['data'])
                || !self::squareImage($body['data'][0])) return null;
            $tokens = self::imageTokens($body['usage'] ?? null);
            if ($tokens === null) return null;
            [$input, $output] = $tokens;
            $reasoning = null;
            $native = ['num_images'=>1,'width'=>1024,'height'=>1024];
        } elseif ($protocol === 'gap-legacy-completions') {
            if (!is_array($body['choices'] ?? null) || count($body['choices']) !== 1 || !is_string($body['choices'][0]['text'] ?? null)
                || $body['choices'][0]['text'] === '') return null;
            $input = $body['usage']['prompt_tokens'] ?? null;
            $output = $body['usage']['completion_tokens'] ?? null;
            $reasoning = null;
            $native = ['completion_count'=>1];
        } elseif ($protocol === 'gap-gemini-tts') {
            $parts = $body['candidates'][0]['content']['parts'] ?? [];
            if (count($body['candidates'] ?? []) !== 1 || !array_filter($parts,fn($p)=>is_string($p['inlineData']['data'] ?? null)
                && $p['inlineData']['data'] !== '' && str_starts_with($p['inlineData']['mimeType'] ?? '', 'audio/'))) return null;
            $input = $body['usageMetadata']['promptTokenCount'] ?? null;
            $total = $body['usageMetadata']['totalTokenCount'] ?? null;
            $candidate = $body['usageMetadata']['candidatesTokenCount'] ?? null;
            if (!self::count($input) || !self::count($total) || !self::count($candidate) || $total < $input + $candidate) return null;
            $output = $total - $input; // Includes thoughts/other billed output, never double-counts candidates.
            $reasoning = $body['usageMetadata']['thoughtsTokenCount'] ?? null;
            $native = ['candidate_tokens'=>$candidate,'total_tokens'=>$total];
        } elseif ($protocol === 'gap-systemone') {
            if (!self::decisionAnswers($body['answers'] ?? null,$contract['response_schema'] ?? null)) return null;
            $tokens = self::imageTokens($body['usage'] ?? null);
            if ($tokens === null) return null;
            [$input,$output] = $tokens;
            $reasoning = null;
            $native = ['answer_count'=>count($body['answers'])];
        } else return null;
        if (!self::count($input) || !self::count($output) || ($protocol === 'gap-systemone' ? $input < 1 : $output < 1)) return null;
        $cost = self::costMicros($contract['pricing'],$input,$output);
        return ['prompt'=>$input,'completion'=>$output,'cached'=>null,'reasoning'=>self::count($reasoning) ? $reasoning : null,
            'estimated_cost'=>(string)BigDecimal::of($cost)->multipliedBy('0.000001'),
            'cost_only'=>true,'tariff_cost'=>true,'native_usage'=>$native + ['input_tokens'=>$input,'output_tokens'=>$output]];
    }

    private static function count(mixed $count): bool { return is_int($count) && $count >= 0 && $count <= 10_000_000; }

    /** A deliberately small subset of the documented System One request; no generative output fee is invented. */
    private function prepareDecisions(array $client, AiModel $model, array $contract): AiPreparedRequest
    {
        foreach ($client as $key=>$value) if (!in_array($key,['model','stream','state','questions'],true))
            throw new AiRequestException('invalid_payload','Unsupported decision parameter: '.$key,422);
        if (!self::decisionText($client['state'] ?? null,4096))
            throw new AiRequestException('invalid_payload','Decision state must be bounded text or structured JSON.',422);
        $questions = $client['questions'] ?? null;
        if (!is_array($questions) || array_is_list($questions) || count($questions)<1 || count($questions)>4)
            throw new AiRequestException('invalid_payload','Decision requests accept one to four named questions.',422);
        $resultSchema=[];
        foreach ($questions as $id=>$question) {
            if (!is_string($id) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D',$id) || !is_array($question)
                || array_diff(array_keys($question),['type','instructions','criteria']) !== []
                || !in_array($question['type'] ?? null,['noul','choice','score'],true)
                || !self::decisionText($question['instructions'] ?? null,1024))
                throw new AiRequestException('invalid_payload','Invalid decision question.',422);
            $type=$question['type']; $criteria=$question['criteria'] ?? null;
            if ($type === 'noul') {
                if (array_key_exists('criteria',$question) && (!is_array($criteria) || array_is_list($criteria)
                    || $criteria===[] || array_diff(array_keys($criteria),['true','false']) !== []
                    || array_filter($criteria,fn($v)=>!self::decisionText($v,256)) !== []))
                    throw new AiRequestException('invalid_payload','Noul criteria accept true/false descriptions.',422);
                $resultSchema[$id]=['type'=>'noul'];
            } elseif ($type === 'choice') {
                if (!is_array($criteria) || array_is_list($criteria) || count($criteria)<2 || count($criteria)>8)
                    throw new AiRequestException('invalid_payload','Choice requires two to eight named options.',422);
                foreach ($criteria as $option=>$description) if (!is_string($option) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D',$option)
                    || ($description!==null && !self::decisionText($description,256)))
                    throw new AiRequestException('invalid_payload','Invalid choice option.',422);
                $resultSchema[$id]=['type'=>'choice','keys'=>array_keys($criteria)];
            } else {
                if (!is_array($criteria) || !array_is_list($criteria) || count($criteria)<2 || count($criteria)>8
                    || array_filter($criteria,fn($v)=>!self::decisionText($v,256)) !== [])
                    throw new AiRequestException('invalid_payload','Score requires two to eight ordered descriptions.',422);
                $resultSchema[$id]=['type'=>'score','keys'=>array_keys($criteria)];
            }
        }
        $forward=['state'=>$client['state'],'questions'=>$questions];
        $contract['response_schema']=$resultSchema;
        $micros=self::bound($contract,$forward);
        if ($micros<1 || $micros>20_000_000) throw new AiRequestException('native_contract_unavailable','Decision tariff requires a finite ceiling.',503);
        return new AiPreparedRequest($forward,0,0,0,false,[],AiPayload::hash(['operation'=>'native','model'=>$model->slug,'body'=>$forward]),
            false,'native',['contract'=>$contract,'max_cost_micro'=>$micros]);
    }

    private static function decisionText(mixed $text, int $maxBytes, int $depth=0): bool
    {
        if ($depth>4 || (!is_string($text) && !is_array($text))) return false;
        if (is_string($text)) return $text!=='' && mb_check_encoding($text,'UTF-8') && strlen($text)<=$maxBytes;
        if ($text===[] || count($text)>32 || strlen(json_encode($text,JSON_THROW_ON_ERROR))>$maxBytes) return false;
        foreach ($text as $value) {
            if (is_array($value) || is_string($value)) { if (!self::decisionText($value,$maxBytes,$depth+1)) return false; }
            elseif ($value!==null && !is_bool($value) && !is_int($value) && !(is_float($value) && is_finite($value))) return false;
        }
        return true;
    }

    /** Require the actual requested answer set and typed probability payload, including during encrypted recovery. */
    private static function decisionAnswers(mixed $answers, mixed $schema): bool
    {
        if (!is_array($answers) || !is_array($schema) || $schema===[] || array_is_list($schema)
            || count($answers)!==count($schema) || array_diff_key($answers,$schema)!==[]) return false;
        foreach ($schema as $id=>$rule) {
            $answer=$answers[$id] ?? null; $type=$rule['type'];
            if (!is_array($answer) || ($answer['type'] ?? null)!==$type) return false;
            if ($type === 'noul') { if (!self::probability($answer['noul'] ?? null)) return false; continue; }
            if (!self::probability($answer['confidence'] ?? null) || !is_array($answer['probabilities'] ?? null)
                || count($answer['probabilities'])!==count($rule['keys'])
                || array_diff(array_keys($answer['probabilities']),$rule['keys'])!==[]
                || array_filter($answer['probabilities'],fn($p)=>!self::probability($p))!==[]
                || abs(array_sum($answer['probabilities'])-1)>0.00001) return false;
            if ($type === 'choice') {
                if (!is_string($answer['choice'] ?? null) || !in_array($answer['choice'],$rule['keys'],true)
                    || $answer['probabilities'][$answer['choice']]+0.00001<max($answer['probabilities'])) return false;
            } elseif ($type === 'score') {
                if ((!is_int($answer['score'] ?? null) && !is_float($answer['score'] ?? null)) || !is_finite($answer['score'])
                    || $answer['score']<0 || $answer['score']>count($rule['keys'])-1
                    || !is_array($answer['legend'] ?? null) || count($answer['legend'])!==count($rule['keys'])
                    || array_diff(array_keys($answer['legend']),$rule['keys'])!==[]
                    || array_filter($answer['legend'],fn($v)=>!is_string($v) || $v==='')!==[]) return false;
                $weighted=0; foreach ($answer['probabilities'] as $level=>$probability) $weighted+=(int)$level*$probability;
                if (abs($weighted-$answer['score'])>0.00001) return false;
            } else return false;
        }
        return true;
    }

    private static function probability(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && $value>=0 && $value<=1;
    }

    /** GapGPT also normalizes image receipts to the OpenAI chat token names. */
    private static function imageTokens(mixed $usage): ?array
    {
        if (!is_array($usage)) return null;
        $selected = null;
        foreach ([['input_tokens','output_tokens'], ['prompt_tokens','completion_tokens']] as [$inputKey,$outputKey]) {
            if (!array_key_exists($inputKey,$usage) && !array_key_exists($outputKey,$usage)) continue;
            $input = $usage[$inputKey] ?? null; $output = $usage[$outputKey] ?? null;
            // Partial pairs, zero placeholders beside a nonzero pair, or contradictory aliases are ambiguous.
            if (!self::count($input) || !self::count($output) || $selected !== null && $selected !== [$input,$output]) return null;
            $selected = [$input,$output];
        }
        if ($selected === null) return null;
        if (array_key_exists('total_tokens',$usage) && (!self::count($usage['total_tokens'])
            || $usage['total_tokens'] !== $selected[0] + $selected[1])) return null;
        return $selected;
    }

    /** A URL cannot establish that the supplier honored the fixed image dimensions. */
    private static function squareImage(mixed $image): bool
    {
        $encoded = is_array($image) ? ($image['b64_json'] ?? null) : null;
        if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 4_194_304) return false;
        $bytes = base64_decode($encoded,true);
        if ($bytes === false) return false;
        $dimensions = @getimagesizefromstring($bytes);
        return is_array($dimensions) && $dimensions[0] === 1024 && $dimensions[1] === 1024
            && in_array($dimensions[2], [IMAGETYPE_PNG,IMAGETYPE_JPEG,IMAGETYPE_WEBP],true);
    }

    public static function costMicros(array $pricing, int $input, int $output): int
    {
        // USD/1M-token multiplied by tokens is microUSD; round the total once.
        return BigDecimal::of($pricing['input_usd_per_million'])->multipliedBy($input)
            ->plus(BigDecimal::of($pricing['output_usd_per_million'])->multipliedBy($output))->toScale(0,RoundingMode::Up)->toInt();
    }
}
