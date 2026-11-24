<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Official, public tariffs. Importing never enables a provider or changes its credentials. */
final class OpenRouterCatalog
{
    public const BASE_URL = 'https://openrouter.ai/api/v1';

    /** Authenticated, zero-inference access check; never returns account data or the credential. */
    public function checkAccess(): string
    {
        $provider = AiProvider::where('slug', 'openrouter')->firstOrFail();
        if (blank($provider->apiKey())) return 'key_missing';
        $response = Http::withToken($provider->apiKey())->connectTimeout(10)->timeout(20)
            ->withoutRedirecting()->get(self::BASE_URL.'/auth/key');
        return $response->successful() && is_array($response->json('data')) ? 'verified' : 'unavailable';
    }

    public function sync(): array
    {
        return Cache::lock('ai:openrouter:catalogue', 180)->block(5, function () {
            $rows = [];
            foreach (['/models' => 20, '/embeddings/models' => 1] as $endpoint => $minimum) {
                $data = Http::connectTimeout(10)->timeout(45)->withoutRedirecting()
                    ->get(self::BASE_URL.$endpoint)->throw()->json('data');
                if (! is_array($data) || ! array_is_list($data) || count($data) < $minimum) {
                    throw new \RuntimeException('Incomplete OpenRouter catalogue; previous data preserved.');
                }
                foreach ($data as $row) {
                    $id = $row['id'] ?? null;
                    if (! is_string($id) || ! preg_match('#\A[a-zA-Z0-9~][a-zA-Z0-9_./:+~-]{0,119}\z#', $id)
                        || isset($rows[$id]) || ! is_array($row['pricing'] ?? null)) {
                        throw new \RuntimeException('Invalid OpenRouter catalogue; previous data preserved.');
                    }
                    $rows[$id] = $this->contract($row, $endpoint === '/embeddings/models');
                }
            }
            return DB::transaction(function () use ($rows) {
                $provider = AiProvider::where('slug', 'openrouter')->firstOrFail();
                $seen = [];
                $counts = ['imported' => 0, 'execution_ready' => 0, 'pending' => 0];
                foreach ($rows as $id => $row) {
                    $model = AiModel::firstOrNew(['ai_provider_id' => $provider->id, 'upstream_model' => $id]);
                    $new = ! $model->exists;
                    $caps = $model->capabilities ?? [];
                    $managed = $new || ($caps['catalogue_source'] ?? null) === 'openrouter';
                    $ready = $row['reason'] === null;
                    $model->fill([
                        'name' => $row['name'], 'vendor' => Str::before($id, '/'),
                        'description' => $row['description'], 'category' => $row['category'],
                        'context_tokens' => $row['context'], 'max_output_tokens' => $row['output'],
                        'docs_url' => 'https://openrouter.ai/'.$id,
                        'capabilities' => array_merge($caps, [
                            'catalogue_source' => 'openrouter', 'catalogue_at' => now()->toIso8601String(),
                            'execution_ready' => $ready, 'pending_reason' => $row['reason'],
                            'catalogue_identity' => $row['identity'],
                            'tool_calling' => $row['tools'], 'streaming' => $ready && $row['category'] === 'chat',
                            // Image/audio inputs need separate token bounds; this route serves text inputs.
                            'vision' => false, 'reasoning' => $row['reasoning'],
                            'pricing_context_limit' => $row['context'],
                            'openrouter_max_price' => $row['max_price'],
                        ]),
                    ]);
                    if ($new) {
                        $model->slug = substr('or-'.Str::slug($id), 0, 67).'-'.substr(hash('sha256', $id), 0, 10);
                        $model->status = $ready ? AiModel::STATUS_ACTIVE : AiModel::STATUS_DISABLED;
                    } elseif ($managed && ! $ready) {
                        $model->status = AiModel::STATUS_DISABLED;
                    }
                    // A manual disable or suspension is never undone by a feed refresh.
                    $model->save();
                    $seen[] = $model->id;
                    if ($ready) {
                        foreach ($row['rates'] as $unit => $rate) {
                            $old = $model->activePrice($unit);
                            if ($old === null || (int) $old->price_micro_units !== $rate || $old->currency_code !== 'USD') {
                                app(PriceBook::class)->supersede($model, $unit, $rate,
                                    note: 'OpenRouter official catalogue '.now()->toIso8601String(), currencyCode: 'USD');
                            }
                        }
                    }
                    $counts['imported']++;
                    $counts[$ready ? 'execution_ready' : 'pending']++;
                }
                foreach ($provider->models()->whereNotIn('id', $seen)->get() as $missing) {
                    if (($missing->capabilities['catalogue_source'] ?? null) === 'openrouter') {
                        $missing->update(['status' => 'disabled', 'capabilities' => array_merge($missing->capabilities,
                            ['execution_ready' => false, 'pending_reason' => 'removed'])]);
                    }
                }
                Setting::put('ai_openrouter_catalogue_at', now()->toIso8601String());
                return $counts;
            });
        });
    }

    private function contract(array $row, bool $embedding): array
    {
        $id = $row['id'];
        $outputs = $row['architecture']['output_modalities'] ?? [];
        $category = $embedding ? 'embedding' : (in_array('image', $outputs, true) ? 'image'
            : (in_array('video', $outputs, true) ? 'video' : (in_array('audio', $outputs, true) ? 'audio' : 'chat')));
        $context = max(1, min(2_000_000, (int) ($row['context_length'] ?? 4096)));
        $output = $embedding ? 0 : max(1, min(4096, (int) ($row['top_provider']['max_completion_tokens'] ?? 4096)));
        $reason = null;
        $rates = $maxPrice = [];
        $price = $row['pricing'];
        if (! in_array($category, ['chat', 'embedding'], true)
            || ($category === 'chat' && $outputs !== ['text'])
            || ($row['architecture']['input_modalities'] ?? []) === []
            || ! in_array('text', $row['architecture']['input_modalities'], true)) {
            $reason = 'endpoint_or_meter_pending';
        }
        // Free/batch/online variants have distinct contracts; never substitute them for paid standard models.
        if (str_contains($id, ':') || str_starts_with($id, 'openrouter/') || str_starts_with($id, '~')) $reason = 'variant_contract_pending';
        if (str_starts_with($id, 'perplexity/') && ! $embedding) $reason = 'search_meter_pending';
        if (! $embedding && isset($row['endpoints']) && ! in_array('/api/v1/chat/completions', $row['endpoints'], true)) {
            $reason = 'endpoint_or_meter_pending';
        }
        foreach ($price['overrides'] ?? [] as $tier) {
            if (! is_array($tier) || ! is_int($tier['min_prompt_tokens'] ?? null) || $tier['min_prompt_tokens'] < 1) {
                $reason = 'pricing_tier_pending';
                break;
            }
            $context = min($context, $tier['min_prompt_tokens'] - 1);
        }
        // Only ordinary token pricing is admitted; no paid search, server tools, cache creation or media.
        foreach (['request', 'per_request'] as $key) {
            if (isset($price[$key]) && ! self::zero($price[$key])) $reason = 'request_price_pending';
        }
        try {
            foreach (['input' => 'prompt'] + ($embedding ? [] : ['output' => 'completion']) as $unit => $key) {
                $value = $price[$key] ?? null;
                if (! is_string($value) || ! preg_match('/\A\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\z/', $value)) {
                    throw new \RuntimeException('Invalid token rate.');
                }
                $rate = BigDecimal::of($value)->multipliedBy('1000000000000')->toScale(0, RoundingMode::Up)->toInt();
                if ($rate < 1 || $rate > PriceBook::MAX_SELL_RATE_MICROS) throw new \RuntimeException('Invalid token rate.');
                $rates[$unit] = $rate;
                // provider.max_price is USD / million tokens. Keep the decimal as a string.
                $maxPrice[$key] = (string) BigDecimal::of($value)->multipliedBy(1_000_000);
            }
        } catch (\Throwable) {
            $reason = 'token_price_pending';
        }
        if (($row['expiration_date'] ?? null) !== null) {
            try { if (\Illuminate\Support\Carbon::parse($row['expiration_date'])->isPast()) $reason = 'expired'; }
            catch (\Throwable) { $reason = 'expiry_invalid'; }
        }
        $identity = $row['hugging_face_id'] ?? null;
        if (! is_string($identity) || $identity === '' || str_contains($id, ':')) $identity = $id;
        return [
            'name' => mb_substr((string) ($row['name'] ?? $id), 0, 120),
            'description' => mb_substr((string) ($row['description'] ?? ''), 0, 20000),
            'category' => $category, 'context' => $context, 'output' => $output, 'reason' => $reason,
            'identity' => strtolower($identity), 'rates' => $rates, 'max_price' => $maxPrice,
            'tools' => in_array('tools', $row['supported_parameters'] ?? [], true),
            'reasoning' => isset($row['reasoning']) || in_array('reasoning', $row['supported_parameters'] ?? [], true),
        ];
    }

    private static function zero(mixed $value): bool
    {
        try { return is_string($value) && BigDecimal::of($value)->isZero(); }
        catch (\Throwable) { return false; }
    }
}
