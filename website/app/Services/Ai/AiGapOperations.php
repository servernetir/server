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
            'gemini-2.5-flash-preview-tts','gemini-2.5-pro-preview-tts'], true) || ($row['pricing_type'] ?? null) !== 'token') return null;
        $pricing = ['type'=>'token_usage'];
        foreach (['input_token_price'=>'input_usd_per_million', 'output_token_price'=>'output_usd_per_million'] as $key=>$unit) {
            if (! preg_match('/"'.preg_quote($key,'/').'"\s*:\s*([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s*[,}])/', $rawObject, $match)) return null;
            try { $value = BigDecimal::of($match[1]); } catch (\Throwable) { return null; }
            if ($value->isLessThanOrEqualTo(0) || $value->isGreaterThan(500)) return null;
            $decimal = (string)$value;
            $pricing[$unit] = str_contains($decimal,'.') ? rtrim(rtrim($decimal,'0'),'.') : $decimal;
        }
        return $pricing;
    }

    public function prepare(array $client, AiModel $model, array $contract): AiPreparedRequest
    {
        if (($client['stream'] ?? false) !== false || strlen(json_encode($client,JSON_THROW_ON_ERROR)) > 32000)
            throw new AiRequestException('invalid_payload','This operation accepts bounded JSON without streaming.',422);
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
        } else return null;
        if (!self::count($input) || !self::count($output) || $output < 1) return null;
        $cost = self::costMicros($contract['pricing'],$input,$output);
        return ['prompt'=>$input,'completion'=>$output,'cached'=>null,'reasoning'=>self::count($reasoning) ? $reasoning : null,
            'estimated_cost'=>(string)BigDecimal::of($cost)->multipliedBy('0.000001'),
            'cost_only'=>true,'tariff_cost'=>true,'native_usage'=>$native + ['input_tokens'=>$input,'output_tokens'=>$output]];
    }

    private static function count(mixed $count): bool { return is_int($count) && $count >= 0 && $count <= 10_000_000; }

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
