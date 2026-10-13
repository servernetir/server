<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Official price feeds and authenticated model discovery. New models await a paid probe. */
final class AdditionalProviderCatalog
{
    public const GAPGPT_PRICES = 'https://gapgpt.app/api/v1/pricing';
    public const DEEPSEEK_PRICES = 'https://api-docs.deepseek.com/quick_start/pricing/';

    public function sync(string $slug): array
    {
        if (! in_array($slug, ['gapgpt', 'deepseek'], true)) {
            throw new \InvalidArgumentException('Unsupported catalogue provider.');
        }
        $provider = AiProvider::where('slug', $slug)->firstOrFail();
        if (blank($provider->apiKey())) {
            throw new \RuntimeException('Catalogue credentials are missing.');
        }
        return Cache::lock('ai:'.$slug.':catalogue', 180)->block(5, function () use ($provider, $slug) {
            // Fixed official hosts: credentials never go to an editable documentation URL.
            $base = $slug === 'gapgpt' ? 'https://api.gapgpt.app/v1' : 'https://api.deepseek.com';
            $models = Http::withToken($provider->apiKey())->connectTimeout(10)->timeout(45)
                ->get($base.'/models')->throw()->json('data');
            if (! is_array($models) || ! array_is_list($models) || $models === []) {
                throw new \RuntimeException('Incomplete model discovery.');
            }
            $ids = [];
            foreach ($models as $model) {
                $id = $model['id'] ?? null;
                if (! is_string($id) || $id === '' || strlen($id) > 120 || isset($ids[$id])) {
                    throw new \RuntimeException('Invalid model discovery.');
                }
                $ids[$id] = true;
            }
            $response = Http::withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->connectTimeout(10)->timeout(45)
                ->get($slug === 'gapgpt' ? self::GAPGPT_PRICES : self::DEEPSEEK_PRICES)->throw();
            $rows = $slug === 'gapgpt' ? self::gapRows($response->body()) : self::deepseekRows($response->body());
            return DB::transaction(fn () => $this->import($provider, $ids, $rows));
        });
    }

    /** Decode decimal rates from raw JSON, avoiding a binary float round trip. */
    public static function gapRows(string $raw): array
    {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['data'] ?? null;
        if (! is_array($data) || ! array_is_list($data) || count($data) < 20) {
            throw new \RuntimeException('Incomplete official price feed.');
        }
        preg_match_all('/\{[^{}]*"model_name"[^{}]*\}/s', $raw, $objects);
        if (count($objects[0]) !== count($data)) {
            throw new \RuntimeException('Changed price feed schema.');
        }
        $rows = [];
        foreach ($objects[0] as $object) {
            $row = json_decode($object, true, 512, JSON_THROW_ON_ERROR);
            $name = $row['model_name'] ?? null;
            if (! is_string($name) || $name === '' || strlen($name) > 120 || isset($rows[$name])) {
                throw new \RuntimeException('Invalid price model.');
            }
            $category = match ($row['model_type'] ?? null) {
                'text' => 'chat', 'embedding' => 'embedding', 'image' => 'image',
                'voice_to_text', 'text_to_voice' => 'audio', default => 'classification',
            };
            // Legacy prompt completions use the native operation endpoint rather than chat messages.
            if (in_array($name, ['babbage-002','davinci-002','text-moderation-latest','typesafe/jev-1.13'], true)) $category = 'classification';
            $rates = [];
            if (($row['pricing_type'] ?? null) === 'token' && in_array($category, ['chat', 'embedding'], true)
                && ! in_array($name, ['babbage-002', 'davinci-002', 'text-moderation-latest'], true)) {
                foreach (['input' => 'input_token_price', 'output' => 'output_token_price'] as $unit => $key) {
                    if ($category === 'embedding' && $unit === 'output') continue;
                    if (preg_match('/"'.preg_quote($key, '/').'"\s*:\s*([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s*[,}])/', $object, $m) !== 1) {
                        throw new \RuntimeException('Invalid decimal price.');
                    }
                    $rate = self::micros($m[1]);
                    if ($rate === null) { $rates = []; break; }
                    $rates[$unit] = $rate;
                }
            }
            // Cache-write/read semantics differ between vendors; full input is the safe fallback.
            $nativePrice=null;
            if ($category==='image' && ($row['pricing_type']??null)==='call'
                && in_array($name,['flux-1-schnell','gemini-2.5-flash-image','gapgpt/qwen-image-2.1'],true)
                && preg_match('/"price_per_call"\s*:\s*([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/',$object,$priceMatch)) {
                $cents=(string)BigDecimal::of($priceMatch[1])->multipliedBy(100);
                if (str_contains($cents,'.')) $cents=rtrim(rtrim($cents,'0'),'.');
                $nativePrice=['type'=>'call','cents_per_call'=>$cents];
            }
            $nativePrice ??= AiGapOperations::catalogPricing($name,$row,$object);
            $rows[$name] = ['category' => $category, 'vendor' => (string) ($row['provider'] ?? ''), 'rates' => $rates,'native_pricing'=>$nativePrice];
        }
        return $rows;
    }

    /** Parse the official two-model table, retaining peak rates as a conservative ceiling. */
    public static function deepseekRows(string $html): array
    {
        preg_match_all('~<table\b[^>]*>(.*?)</table>~si', $html, $tables);
        $rows = [];
        foreach ($tables[1] as $table) {
            preg_match_all('~<tr\b[^>]*>(.*?)</tr>~si', $table, $trs);
            $cells = [];
            foreach ($trs[1] as $tr) {
                preg_match_all('~<t[dh]\b[^>]*>(.*?)</t[dh]>~si', $tr, $tds);
                $cells[] = array_map(fn ($v) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', ' ', $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $tds[1]);
            }
            if (($cells[0][0] ?? null) !== 'MODEL' || ! str_starts_with($cells[0][1] ?? '', 'deepseek-flash')
                || ($cells[0][2] ?? null) !== 'deepseek-v4-pro') continue;
            $peak = [];
            $unit = null;
            foreach ($cells as $cell) {
                foreach ($cell as $label) {
                    $unit = match ($label) {
                        '1M INPUT TOKENS (CACHE HIT)' => 'cached_input',
                        '1M INPUT TOKENS (CACHE MISS)' => 'input',
                        '1M OUTPUT TOKENS' => 'output', default => $unit,
                    };
                }
                if (($cell[0] ?? '') === 'PEAK') {
                    if ($unit === null || isset($peak[$unit])) throw new \RuntimeException('Ambiguous DeepSeek pricing row.');
                    $peak[$unit] = $cell;
                }
            }
            if (count($peak) !== 3 || ! isset($peak['cached_input'], $peak['input'], $peak['output'])) {
                throw new \RuntimeException('Changed DeepSeek pricing table.');
            }
            foreach (['deepseek-flash', 'deepseek-v4-pro'] as $i => $id) {
                $rates = [];
                foreach (['cached_input', 'input', 'output'] as $unit) {
                    if (count($peak[$unit]) !== 3 || preg_match('/^\$([0-9]+(?:\.[0-9]+)?)$/', $peak[$unit][$i+1], $m) !== 1
                        || ($rate = self::micros($m[1])) === null) {
                        throw new \RuntimeException('Invalid DeepSeek peak rate.');
                    }
                    $rates[$unit] = $rate;
                }
                if ($rates['cached_input'] > $rates['input']) throw new \RuntimeException('Invalid cached rate.');
                $rows[$id] = ['category' => 'chat', 'vendor' => 'DeepSeek', 'rates' => $rates];
            }
        }
        if (count($rows) !== 2) throw new \RuntimeException('DeepSeek pricing table missing.');
        return $rows;
    }

    private static function micros(string $price): ?int
    {
        try { $rate = BigDecimal::of($price)->multipliedBy(1_000_000)->toScale(0, RoundingMode::Up)->toInt(); }
        catch (\Throwable) { return null; }
        return $rate >= 1 && $rate <= PriceBook::MAX_SELL_RATE_MICROS ? $rate : null;
    }

    private function import(AiProvider $provider, array $ids, array $rows): array
    {
        $seen = [];
        $counts = ['imported' => 0, 'execution_ready' => 0, 'pending' => 0];
        foreach ($rows as $id => $row) {
            $model = AiModel::firstOrNew(['ai_provider_id' => $provider->id, 'upstream_model' => $id]);
            $new = ! $model->exists;
            $ready = isset($ids[$id]) && $row['rates'] !== [];
            $nativePrice=$row['native_pricing']??null;
            if ($nativePrice) $ready=isset($ids[$id]) && AiNative::candidate($provider->slug,$id,$nativePrice)!==null;
            $caps = $model->capabilities ?? [];
            $model->fill([
                'name' => $id, 'vendor' => $row['vendor'], 'category' => $row['category'],
                'context_tokens' => $provider->slug === 'deepseek' ? 1_000_000 : 4096,
                'max_output_tokens' => $provider->slug === 'deepseek' ? 4096 : 1024,
                'docs_url' => $provider->slug === 'deepseek' ? self::DEEPSEEK_PRICES : 'https://gapgpt.app/platform-v2/pricing',
                'capabilities' => array_merge($caps, [
                    'catalogue_source' => $provider->slug, 'catalogue_at' => now()->toIso8601String(),
                    'execution_ready' => $ready, 'streaming' => $ready && $row['category'] === 'chat',
                    'pending_reason' => $ready ? null : (isset($ids[$id]) ? 'endpoint_or_metering_pending' : 'model_unavailable'),
                    'native_tariff_hash'=>$nativePrice ? AiNative::fingerprint($nativePrice) : null,
                ]),
            ]);
            if ($new) {
                $model->slug = substr($provider->slug.'-'.Str::slug($id), 0, 67).'-'.substr(hash('sha256', $id), 0, 10);
                $model->status = AiModel::STATUS_DISABLED;
            } elseif (! $ready) {
                $model->status = AiModel::STATUS_DISABLED;
            }
            $model->save();
            foreach ($ready ? $row['rates'] : [] as $unit => $rate) {
                $old = $model->activePrice($unit);
                if ($old === null || $old->price_micro_units !== $rate || $old->currency_code !== 'USD') {
                    app(PriceBook::class)->supersede($model, $unit, $rate, currencyCode: 'USD', note: $provider->slug.' official prices '.now()->toIso8601String());
                }
            }
            $seen[] = $model->id;
            $counts['imported']++;
            $counts[$ready ? 'execution_ready' : 'pending']++;
        }
        foreach ($provider->models()->whereNotIn('id', $seen)->get() as $missing) {
            if (($missing->capabilities['catalogue_source'] ?? null) === $provider->slug) {
                $missing->update(['status' => AiModel::STATUS_DISABLED, 'capabilities' => array_merge($missing->capabilities, ['execution_ready' => false, 'pending_reason' => 'removed'])]);
            }
        }
        return $counts;
    }
}
