<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\Setting;
use Brick\Math\BigInteger;
use Illuminate\Support\Collection;

/** Equivalent weights only; never collapse model versions, free variants or unverified names. */
final class AiProviderRouting
{
    private ?array $dailySpend = null;
    public function enabled(): bool
    {
        return Setting::get('ai_price_routing_enabled') === '1';
    }

    public function identity(AiModel $model): string
    {
        if (! in_array($model->provider?->slug, ['deepinfra', 'openrouter'], true)
            || ! in_array($model->category, ['chat', 'embedding'], true)) return 'row:'.$model->id;
        $identity = $model->provider->slug === 'openrouter'
            ? ($model->capabilities['catalogue_identity'] ?? $model->upstream_model) : $model->upstream_model;
        return $model->category.':'.strtolower($identity);
    }

    /** A single purchasable entry per exact model. Listing compares equal input/output quantities. */
    public function catalogue(Collection $models): Collection
    {
        if (! $this->enabled()) return $models;
        (new \Illuminate\Database\Eloquent\Collection($models->all()))->loadMissing('activeUnitPrices');
        return $models->groupBy(fn (AiModel $m) => $this->identity($m))->map(function (Collection $group) {
            if ($group->count() === 1) return $group->first();
            $best = null;
            $bestScore = null;
            foreach ($group->sortBy('id') as $model) {
                $score = $this->score($model, 1_000_000, $model->category === 'embedding' ? 0 : 1_000_000);
                if ($score !== null && ($bestScore === null || $score->isLessThan($bestScore))) {
                    $best = $model;
                    $bestScore = $score;
                }
            }
            return $best ?? $group->first();
        })->values();
    }

    /** Select before reserving money. Failed/unknown inference is never retried on another supplier. */
    public function forRequest(AiModel $requested, array $client, string $operation): AiModel
    {
        if (! $this->enabled() || ! in_array($operation, ['chat', 'embedding'], true)
            || ! in_array($requested->provider?->slug, ['deepinfra', 'openrouter'], true)) return $requested;
        $identity = $this->identity($requested);
        $upstreamIdentity = substr($identity, strlen($requested->category) + 1);
        $models = AiModel::with(['provider', 'activeUnitPrices'])->where('category', $requested->category)
            ->where('status', 'active')->whereNull('suspended_at')
            ->whereHas('provider', fn ($p) => $p->whereIn('slug', ['deepinfra', 'openrouter'])->where('enabled', true))
            ->where(fn ($q) => $q->whereRaw('LOWER(upstream_model) = ?', [$upstreamIdentity])
                ->orWhere('capabilities->catalogue_identity', $upstreamIdentity))
            ->get()->filter(fn (AiModel $m) => $this->identity($m) === $identity);
        $best = $requested;
        $bestScore = null;
        foreach ($models->sortBy('id') as $candidate) {
            try {
                if ($operation === 'chat' && isset($client['tools']) && ! $candidate->capability('tool_calling')) continue;
                $requiredOutput = min((int) ($client['max_completion_tokens'] ?? $client['max_tokens'] ?? $requested->max_output_tokens),
                    (int) $requested->max_output_tokens);
                if ($operation === 'chat' && $candidate->max_output_tokens < $requiredOutput) continue;
                $prepared = $operation === 'chat' ? app(AiPayload::class)->prepare($client, $candidate)
                    : app(AiPayload::class)->prepareInputOnly($client, $candidate, $operation);
                if ($prepared->maxInput + $prepared->maxOutput > $candidate->context_tokens) continue;
                $score = $this->score($candidate, $prepared->maxInput, $prepared->maxOutput);
                if ($score !== null && ($bestScore === null || $score->isLessThan($bestScore))) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            } catch (AiRequestException|AiPricingException) {
                continue;
            }
        }
        return $best;
    }

    /** Exact landed cost: token tariffs × FX × funding fee; no floating-point money. */
    private function score(AiModel $model, int $input, int $output): ?BigInteger
    {
        $state = app(AiAvailability::class)->inspect($model);
        if (! $state['ready'] || $state['quote']->native !== null) return null;
        $provider = $model->provider;
        if ((int) $provider->daily_cost_cap_micro > 0) {
            $this->dailySpend ??= \App\Models\AiUsage::where('sent_at', '>=', now()->utc()->startOfDay())
                ->select('ai_provider_id')->selectRaw('SUM(CASE WHEN provider_cost_micro > cost_micro THEN provider_cost_micro ELSE cost_micro END) AS spent')
                ->groupBy('ai_provider_id')->pluck('spent', 'ai_provider_id')->all();
            $spent = (int) ($this->dailySpend[$provider->id] ?? 0);
            if ($spent >= (int) $provider->daily_cost_cap_micro) return null;
        }
        $q = $state['quote'];
        return BigInteger::of($q->rIn)->multipliedBy($input)
            ->plus(BigInteger::of($q->rOut)->multipliedBy($output))
            ->multipliedBy($q->rate())->multipliedBy(10_000 + $q->feeBp);
    }

    public function recordRefresh(): array
    {
        $models = AiModel::with('provider')->where('status', 'active')
            ->whereHas('provider', fn ($p) => $p->whereIn('slug', ['deepinfra', 'openrouter']))->get();
        $chosen = $this->catalogue($models)->filter(fn (AiModel $m) => app(AiAvailability::class)->inspect($m)['ready']);
        $summary = ['at' => now()->toIso8601String(), 'models' => $chosen->count(),
            'selected' => $chosen->countBy(fn (AiModel $m) => $m->provider->slug)->all()];
        Setting::put('ai_price_routing_last_refresh', json_encode($summary, JSON_THROW_ON_ERROR));
        return $summary;
    }
}
