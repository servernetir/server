<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Public catalogue import. Never changes credentials or commercial permissions. */
final class DeepInfraCatalog
{
    public const URL = 'https://api.deepinfra.com/models/list';
    public const MAX_AGE_HOURS = 6;

    public function sync(): array
    {
        return Cache::lock('ai:deepinfra:catalogue', 180)->block(5, function () {
            $response = Http::connectTimeout(10)->timeout(45)->get(self::URL)->throw();
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 20) {
                throw new \RuntimeException('DeepInfra returned an incomplete catalogue; previous data preserved.');
            }
            // Validate the whole feed before touching historical model/price data.
            $names = [];
            foreach ($rows as $row) {
                $name = $row['model_name'] ?? null;
                if (! is_string($name) || strlen($name) > 120 || isset($names[$name]) || empty($row['type'])) {
                    throw new \RuntimeException('Invalid or duplicate DeepInfra model; previous data preserved.');
                }
                $names[$name] = true;
            }
            return DB::transaction(fn () => $this->import($rows));
        });
    }

    private function import(array $rows): array
    {
        $provider = AiProvider::where('slug', 'deepinfra')->firstOrFail();
        $book = app(PriceBook::class);
        $seen = [];
        $counts = ['imported' => 0, 'chat_ready' => 0, 'pending' => 0, 'deprecated' => 0];
        foreach ($rows as $row) {
            if (! empty($row['private'])) {
                continue;
            }
            $upstream = $row['model_name'];
            $model = AiModel::firstOrNew(['ai_provider_id' => $provider->id, 'upstream_model' => $upstream]);
            $new = ! $model->exists;
            $category = match ($row['type']) {
                'text-generation' => 'chat', 'embeddings' => 'embedding',
                'text-to-image' => 'image', 'text-to-video' => 'video',
                'text-to-speech', 'automatic-speech-recognition' => 'audio',
                'reranker' => 'rerank', 'text-to-music' => 'music',
                default => 'classification',
            };
            $pricing = $row['pricing'] ?? [];
            $rates = in_array($category, ['embedding', 'rerank'], true)
                ? $this->inputRates($pricing) : $this->chatRates($pricing);
            $tierLimit = $category === 'chat' ? $this->tierLimit($upstream, $pricing) : null;
            if ($tierLimit !== null) {
                $rates = $this->chatRates(array_merge($pricing, ['full' => null]));
            }
            $deprecated = ! empty($row['deprecated']) && (int) $row['deprecated'] <= now()->timestamp;
            $ready = ($category === 'chat' && in_array('openai', $row['tags'] ?? [], true)
                || in_array($category, ['embedding', 'rerank'], true)) && $rates !== null && ! $deprecated;
            $metadata = $model->capabilities ?? [];
            $managed = $new || ($metadata['catalogue_source'] ?? null) === 'deepinfra';
            $model->fill([
                'name' => Str::after($upstream, '/'), 'vendor' => Str::before($upstream, '/'),
                'description' => mb_substr((string) ($row['description'] ?? ''), 0, 20000),
                'category' => $category, 'context_tokens' => $tierLimit ?? max(1, (int) ($row['max_tokens'] ?? 4096)),
                // Conservative generation cap; never claim the context window as output capacity.
                'max_output_tokens' => min(4096, max(1, (int) ($row['max_tokens'] ?? 4096))),
                'docs_url' => 'https://deepinfra.com/'.rawurlencode(Str::before($upstream, '/')).'/'.rawurlencode(Str::after($upstream, '/')),
                'capabilities' => array_merge($metadata, [
                    'reasoning' => in_array('reasoning', $row['tags'] ?? [], true),
                    'tool_calling' => in_array('tools', $row['tags'] ?? [], true),
                    'vision' => in_array('multimodal', $row['tags'] ?? [], true),
                    'streaming' => $ready && $category === 'chat', 'catalogue_source' => 'deepinfra',
                    'catalogue_type' => $row['type'], 'catalogue_at' => now()->toIso8601String(),
                    'execution_ready' => $ready,
                    'pending_reason' => $ready ? null : ($deprecated ? 'deprecated' : ($category !== 'chat' ? 'endpoint_pending' : 'pricing_or_api_pending')),
                    'replacement_upstream' => $row['replaced_by'] ?? null,
                    'pricing_context_limit' => $tierLimit,
                ]),
            ]);
            if ($new) {
                $model->slug = substr('di-'.Str::slug($upstream), 0, 67).'-'.substr(hash('sha256', $upstream), 0, 10);
            }
            // Manual disable/suspension stays disabled. Feed changes may only remove readiness.
            if ($new) {
                $model->status = $deprecated ? 'deprecated' : ($ready ? 'active' : 'disabled');
            } elseif ($managed && ! $ready) {
                $model->status = $deprecated ? 'deprecated' : 'disabled';
            }
            $model->save();
            $seen[] = $model->id;
            if ($ready) {
                foreach ($rates as $unit => $micros) {
                    $old = $model->activePrice($unit);
                    if ($old === null || (int) $old->price_micro_units !== $micros || $old->currency_code !== 'USD') {
                        $book->supersede($model, $unit, $micros, note: 'DeepInfra public catalogue '.now()->toIso8601String(), currencyCode: 'USD');
                    }
                }
            }
            $counts['imported']++;
            $counts[$deprecated ? 'deprecated' : ($ready ? 'chat_ready' : 'pending')]++;
        }
        // Disappeared upstream models must not continue accepting paid requests.
        foreach ($provider->models()->whereNotIn('id', $seen)->get() as $missing) {
            if (($missing->capabilities['catalogue_source'] ?? null) === 'deepinfra') {
                $missing->update(['status' => 'disabled', 'capabilities' => array_merge($missing->capabilities, ['execution_ready' => false, 'pending_reason' => 'removed'])]);
            }
        }
        Setting::put('ai_deepinfra_catalogue_at', now()->toIso8601String());
        return $counts;
    }

    private function chatRates(array $price): ?array
    {
        // Tiered/request/image/audio pricing needs its own metering contract.
        if (($price['type'] ?? null) !== 'tokens' || ! empty($price['table']) || ! empty($price['full'])) {
            return null;
        }
        $rates = [];
        foreach ([AiModelUnitPrice::UNIT_INPUT => 'cents_per_input_token', AiModelUnitPrice::UNIT_OUTPUT => 'cents_per_output_token'] as $unit => $key) {
            try {
                $rate = BigDecimal::of((string) ($price[$key] ?? ''))->multipliedBy('10000000000')->toScale(0, RoundingMode::Up)->toInt();
            } catch (\Throwable) {
                return null;
            }
            if ($rate < 1 || $rate > PriceBook::MAX_SELL_RATE_MICROS) {
                return null;
            }
            $rates[$unit] = $rate;
        }
        // Cache ratio schema is not a currency rate: use input price until separately verified.
        return $rates;
    }

    private function inputRates(array $price): ?array
    {
        if (($price['type'] ?? null) !== 'input_tokens' || ! empty($price['table']) || ! empty($price['full'])) {
            return null;
        }
        try {
            $rate = BigDecimal::of((string) ($price['cents_per_input_token'] ?? ''))
                ->multipliedBy('10000000000')->toScale(0, RoundingMode::Up)->toInt();
        } catch (\Throwable) {
            return null;
        }
        return $rate >= 1 && $rate <= PriceBook::MAX_SELL_RATE_MICROS ? [AiModelUnitPrice::UNIT_INPUT => $rate] : null;
    }

    /** Verified first-tier contracts. A changed upstream description closes the route. */
    private function tierLimit(string $upstream, array $price): ?int
    {
        $contracts = [
            'Qwen/Qwen3.7-Max' => ['$2.50 in $7.50 out $0.50 cached <= 32K, $5.0 in $15 out $1.0 cached <= 128K, $6.25 in $18.50 out $1.25 cached > 128K', 32000],
            'ByteDance/Seed-2.0-code' => ['$0.50 in $3 out $0.10 cached <= 128K, $1 in $6 out $0.20 cached', 128000],
            'ByteDance/Seed-2.0-pro' => ['$0.50 in $3 out $0.10 cached <= 128K, $1 in $6 out $0.20 cached', 128000],
            'ByteDance/Seed-1.8' => ['$0.25 in $2 out $0.05 cached <= 128K, $0.5 in $4 out $0.1 cached', 128000],
            'ByteDance/Seed-2.0-mini' => ['$0.10 in $0.40 out $0.02 cached <= 128K, $0.2 in $0.80 out $0.2 cached', 128000],
        ];
        $contract = $contracts[$upstream] ?? null;
        if ($contract === null || ($price['full'] ?? null) !== $contract[0] || ! empty($price['table'])) {
            return null;
        }
        preg_match('/^\$([0-9.]+) in \$([0-9.]+) out/', $contract[0], $matches);
        $rates = $this->chatRates(array_merge($price, ['full' => null]));
        return $rates !== null
            && $rates[AiModelUnitPrice::UNIT_INPUT] === BigDecimal::of($matches[1])->multipliedBy(1000000)->toInt()
            && $rates[AiModelUnitPrice::UNIT_OUTPUT] === BigDecimal::of($matches[2])->multipliedBy(1000000)->toInt()
            ? $contract[1] : null;
    }

    public static function isFresh(AiModel $model): bool
    {
        $caps = $model->capabilities ?? [];
        if (! in_array($caps['catalogue_source'] ?? null, ['deepinfra', 'deepseek', 'gapgpt'], true)) {
            return true;
        }
        try {
            $at = \Illuminate\Support\Carbon::parse($caps['catalogue_at'] ?? '1970-01-01');
            return ($caps['execution_ready'] ?? false) === true && $at->between(now()->subHours(self::MAX_AGE_HOURS), now()->addMinute());
        } catch (\Throwable) {
            return false;
        }
    }
}
