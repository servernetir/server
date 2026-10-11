<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Native units stay separate from tokens. All monetary arithmetic is decimal/integer. */
final class AiNative
{
    private static ?array $contracts = null;

    public static function isNative(AiModel $model): bool
    {
        return in_array($model->category, ['image', 'audio', 'video', 'music', 'classification'], true);
    }

    public static function fingerprint(array $pricing): string
    {
        // The entire tariff, including tiers and cost-derived units, belongs to the contract.
        return hash('sha256', json_encode($pricing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function definition(AiModel $model): ?array
    {
        $path = resource_path('content/ai-native-contracts.json');
        if (! is_file($path)) return null;
        self::$contracts ??= json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $contract = self::$contracts[$model->provider?->slug.':'.$model->upstream_model] ?? null;
        if (! $contract || empty($contract['supported'])
            || ($model->capabilities['native_tariff_hash'] ?? null) !== $contract['tariff_hash']) {
            return null;
        }
        return $contract;
    }

    public static function candidate(string $provider, string $model, array $pricing): ?array
    {
        $temporary = new AiModel(['upstream_model'=>$model, 'capabilities'=>['native_tariff_hash'=>self::fingerprint($pricing)]]);
        $temporary->setRelation('provider', new \App\Models\AiProvider(['slug'=>$provider]));
        return self::definition($temporary);
    }

    public function prepare(array $client, AiModel $model): AiPreparedRequest
    {
        if (strlen(json_encode($client,JSON_THROW_ON_ERROR)) > 8_000_000) throw new AiRequestException('invalid_payload','Native request is too large.',413);
        $contract = self::definition($model);
        if (! $contract) throw new AiRequestException('native_contract_unavailable', 'Native metering contract is unavailable.', 503);
        if (($client['stream'] ?? false) !== false) throw new AiRequestException('invalid_payload', 'Native streaming is not supported.', 422);
        $schema = $contract['schema']; $properties = $schema['properties']; $forward = [];
        $allowed = array_diff(array_keys($properties), $contract['blocked_fields'] ?? []);
        foreach ($client as $key => $value) {
            if ($key === 'model' || $key === 'stream') continue;
            if (! in_array($key, $allowed, true) || in_array($key, ['webhook', 'service_tier', 'fail_fast'], true)) {
                throw new AiRequestException('invalid_payload', 'Unsupported native parameter: '.$key, 422);
            }
            $forward[$key] = $value;
        }
        $forward += $contract['defaults'] ?? [];
        foreach ($properties as $key => $rule) {
            if (! in_array($key,$allowed,true) || in_array($key, ['webhook', 'service_tier', 'fail_fast', 'stream'], true)) continue;
            $rule = $this->resolve($rule, $schema);
            if (! array_key_exists($key, $forward) && isset($rule['default'])) $forward[$key] = $rule['default'];
            if (! array_key_exists($key, $forward)) {
                if (in_array($key, $schema['required'] ?? [], true)) throw new AiRequestException('invalid_payload', 'Required native parameter: '.$key, 422);
                continue;
            }
            $this->validate($forward[$key], $rule, $schema, $key);
        }
        // No editable callback, premium service tier or remote input fetch is admitted.
        if (isset($properties['service_tier'])) $forward['service_tier'] = 'default';
        if (isset($properties['stream'])) $forward['stream'] = false;
        foreach ($contract['limits'] as $key => $limit) {
            if (isset($forward[$key]) && is_numeric($forward[$key]) && BigDecimal::of((string) $forward[$key])->isGreaterThan($limit)) {
                throw new AiRequestException('invalid_payload', 'Gateway native limit exceeded: '.$key, 422);
            }
        }
        foreach ($forward as $key => $value) {
            if (is_string($value) && ! str_starts_with($value, 'data:') && strlen($value) > 8000) throw new AiRequestException('invalid_payload', 'Native text is too large.', 422);
            if (in_array($key, $contract['media_fields'] ?? [], true)) {
                foreach (is_array($value) ? $value : [$value] as $media) {
                    if ($media !== null && $media !== '') $this->media($media, $key);
                }
            }
        }
        $micros = $this->bound($contract, $forward);
        if ($micros < 1 || $micros > 20_000_000) throw new AiRequestException('native_contract_unavailable', 'A bounded native tariff is required.', 503);
        $hash = hash('sha256', json_encode(['operation'=>'native', 'model'=>$model->slug, 'body'=>$forward], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return new AiPreparedRequest($forward, 0, 0, 0, false, [], $hash, false, 'native',
            ['contract'=>$contract, 'max_cost_micro'=>$micros]);
    }

    private function resolve(array $rule, array $schema): array
    {
        if (isset($rule['$ref'])) return $schema['definitions'][basename($rule['$ref'])] ?? [];
        if (isset($rule['anyOf'])) {
            foreach ($rule['anyOf'] as $option) if (($option['type'] ?? '') !== 'null') return $this->resolve($option, $schema) + $rule
                + ['nullable'=>count(array_filter($rule['anyOf'],fn($item)=>($item['type']??'')==='null'))>0];
        }
        return $rule;
    }

    private function validate(mixed $value, array $rule, array $schema, string $key): void
    {
        if ($value === null && ($rule['nullable'] ?? false)) return;
        $type = $rule['type'] ?? 'string';
        $ok = match ($type) {
            'string' => is_string($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value) && is_finite($value),
            'boolean' => is_bool($value), 'array' => is_array($value) && array_is_list($value), default => false,
        };
        if (! $ok || isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) throw new AiRequestException('invalid_payload', 'Invalid native parameter: '.$key, 422);
        if (is_string($value)) {
            $length = mb_strlen($value);
            if ($length < ($rule['minLength'] ?? 0) || $length > ($rule['maxLength'] ?? 8_000_000)) throw new AiRequestException('invalid_payload', 'Invalid native string length: '.$key, 422);
        }
        if (is_int($value) || is_float($value)) {
            $number = BigDecimal::of((string) $value);
            if (isset($rule['minimum']) && $number->isLessThan((string) $rule['minimum']) || isset($rule['maximum']) && $number->isGreaterThan((string) $rule['maximum'])) throw new AiRequestException('invalid_payload', 'Invalid native range: '.$key, 422);
            if (isset($rule['multipleOf']) && ! $number->remainder((string)$rule['multipleOf'])->isZero()) throw new AiRequestException('invalid_payload','Invalid native step: '.$key,422);
        }
        if (is_array($value)) {
            if (count($value) > min(8, $rule['maxItems'] ?? 8)) throw new AiRequestException('invalid_payload', 'Too many native input items.', 422);
            foreach ($value as $item) $this->validate($item, $this->resolve($rule['items'] ?? [], $schema), $schema, $key);
        }
    }

    private function media(mixed $value, string $key): void
    {
        if (! is_string($value) || strlen($value) > 8_000_000 || ! preg_match('~^data:(image/(?:png|jpeg|webp)|audio/wav);base64,([A-Za-z0-9+/=]+)$~D', $value, $m)) {
            throw new AiRequestException('invalid_payload', 'Native media must be an inline image or PCM WAV; remote URLs and video input are unavailable.', 422);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) throw new AiRequestException('invalid_payload', 'Invalid native media.', 422);
        if (str_starts_with($m[1], 'image/')) {
            $size = @getimagesizefromstring($bytes);
            if (! $size || $size[0] > 2048 || $size[1] > 2048 || $size[0] * $size[1] > 4_194_304) throw new AiRequestException('invalid_payload', 'Native input image dimensions exceed the gateway limit.', 422);
        } else {
            $this->wavSeconds($bytes);
        }
    }

    private function wavSeconds(string $bytes): string
    {
        if (strlen($bytes)<44 || substr($bytes,0,4) !== 'RIFF' || substr($bytes,8,4) !== 'WAVE' || unpack('V',substr($bytes,4,4))[1] + 8 !== strlen($bytes)) throw new AiRequestException('invalid_payload', 'A complete PCM WAV file is required.', 422);
        $pos=12; $rate=null; $data=0;
        while ($pos+8 <= strlen($bytes)) {
            $tag=substr($bytes,$pos,4);$length=unpack('V',substr($bytes,$pos+4,4))[1];$pos+=8;
            if ($length > strlen($bytes)-$pos) throw new AiRequestException('invalid_payload', 'Truncated PCM WAV.', 422);
            if ($tag === 'fmt ' && $length >= 16) {
                $fmt=unpack('vformat/vchannels/Vsample/Vrate/valign/vbits',substr($bytes,$pos,16));
                if ($fmt['format'] !== 1 || ! in_array($fmt['channels'],[1,2],true) || ! in_array($fmt['bits'],[8,16,24,32],true)
                    || $fmt['rate'] !== $fmt['sample']*$fmt['channels']*intdiv($fmt['bits'],8) || $fmt['rate'] < 1) throw new AiRequestException('invalid_payload', 'Unsupported PCM WAV.', 422);
                $rate=$fmt['rate'];
            }
            if ($tag === 'data') $data+=$length;
            $pos+=$length+($length%2);
        }
        if (! $rate || $data < 1 || $pos !== strlen($bytes) || $data > $rate*60) throw new AiRequestException('invalid_payload', 'Audio must contain at most 60 seconds of complete PCM.', 422);
        return (string) BigDecimal::of($data)->dividedBy($rate,6,RoundingMode::Up);
    }

    public function bound(array $contract, array $body): int
    {
        $pricing=$contract['pricing'];$type=$pricing['type'];$units='1';
        $rate=match($type){
            'input_character_length'=>$pricing['cents_per_input_chars'], 'input_length'=>$pricing['cents_per_input_sec'],
            'image_units'=>$pricing['cents_per_image_unit'], 'output_length'=>$pricing['cents_per_output_sec'],
            'frame_units'=>$pricing['cents_per_frame_unit'], 'call'=>$pricing['cents_per_call'],
            'input_tokens'=>$pricing['cents_per_input_token'],
            default=>null,
        };
        if ($rate === null) throw new AiRequestException('native_contract_unavailable','Unsupported native price contract.',503);
        if ($type === 'input_character_length') {
            $units=(string)mb_strlen((string)($body['text']??$body['input']??''));
            foreach (['instruct','instruction','voice_description'] as $field) $units=(string)BigDecimal::of($units)->plus(mb_strlen((string)($body[$field]??'')));
        }
        if ($type === 'input_length') {
            $audio=$body['audio']??''; $parts=explode(',',$audio,2);
            $units=$this->wavSeconds(base64_decode($parts[1]??'',true) ?: '');
        }
        if ($type === 'output_length') $units=(string)($body['duration']??$body['duration_seconds']??$body['seconds']??$contract['default_duration']);
        if ($type === 'frame_units') $units=(string)(($body['seconds']??5)*($contract['fps']??24)+1);
        if ($type === 'call') $units=$contract['call_units']??'1';
        $imageCount=0;
        foreach ($contract['media_fields'] as $field) {
            if (! isset($body[$field])) continue;
            foreach (is_array($body[$field]) ? $body[$field] : [$body[$field]] as $item) {
                if (is_string($item) && str_starts_with($item,'data:image/')) $imageCount++;
            }
        }
        if ($type === 'input_tokens') $units=(string)(strlen((string)($body['prompt']??''))+4096+$imageCount*1120+$contract['max_native_output_tokens']);
        if ($type === 'image_units') {
            $units=(string)($body['num_images']??$body['n']??1);
            if (($pricing['default_width']??0)>0 && ($pricing['default_height']??0)>0) {
                $units=(string) BigDecimal::of($units)->multipliedBy($body['width']??$pricing['default_width'])->multipliedBy($body['height']??$pricing['default_height'])
                    ->dividedBy($pricing['default_width']*$pricing['default_height'],8,RoundingMode::Up);
                if (($pricing['default_iterations']??0)>0) $units=(string)BigDecimal::of($units)->multipliedBy($body['num_inference_steps']??$body['steps']??$pricing['default_iterations'])
                    ->dividedBy($pricing['default_iterations'],8,RoundingMode::Up);
            }
        }
        // A tier ceiling is declared explicitly in the versioned contract, not inferred at admission.
        $rate=BigDecimal::of((string)$contract['ceiling_cents']);
        $cents=$rate->multipliedBy($units)->plus(BigDecimal::of($contract['additional_input_image_cents']??'0')->multipliedBy(max(0,$imageCount-1)));
        return $cents->multipliedBy(10000)->toScale(0,RoundingMode::Up)->toInt();
    }

    public static function unitPrice(AiPriceQuote $quote): ?array
    {
        if (! $quote->native) return null;
        $type=$quote->native['pricing']['type'];
        $quantity=$type==='input_character_length' ? 1000 : 1;
        $usd=(string)BigDecimal::of($quote->native['ceiling_cents'])->multipliedBy($quantity)->multipliedBy($quote->native['call_units']??'1')->multipliedBy('0.01');
        $price=app(AiPricing::class)->chargeCost($quote,$usd,0);
        return ['sell'=>$price['sell'],'quantity'=>$quantity,'unit'=>$type];
    }

    public static function parse(array $body, string $raw,?array $snapshot=null): ?array
    {
        if (($snapshot['contract']['protocol']??null)==='gap-images') {
            if (isset($body['error']) || !is_array($body['data']??null) || count($body['data'])!==1
                || empty($body['data'][0]['url']) && empty($body['data'][0]['b64_json'])) return null;
            return ['prompt'=>null,'completion'=>null,'cached'=>null,'reasoning'=>null,'cost_only'=>true,'tariff_cost'=>true,
                'estimated_cost'=>(string)BigDecimal::of($snapshot['max_cost_micro'])->dividedBy(1_000_000,6),
                'native_usage'=>['num_images'=>1,'width'=>1024,'height'=>1024]];
        }
        $state=$body['inference_status']??null;
        if (! is_array($state) || isset($state['status']) && ! in_array($state['status'],['succeeded','success'],true) || isset($body['error'])) return null;
        if (! preg_match('/"inference_status"\s*:\s*\{[^{}]*"cost"\s*:\s*"?([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)"?/', $raw,$m)) return null;
        $resultFields=['images','image','audio','video_url','video','results','text','output','result'];
        if (! array_filter($resultFields,fn($key)=>!empty($body[$key]))) return null;
        return ['prompt'=>null,'completion'=>null,'cached'=>null,'reasoning'=>null,'estimated_cost'=>$m[1],'cost_only'=>true,
            'native_usage'=>array_intersect_key($state,array_flip(['runtime_ms','output_length','tokens_input','tokens_generated']))
                + array_intersect_key($body,array_flip(['input_character_length','input_length','num_images','image_units','frame_units']))];
    }
}
