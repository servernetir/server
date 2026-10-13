<?php

namespace App\Services\Ai;

use App\Models\AiModel;

final class AiAvailability
{
    public function inspect(AiModel $model): array
    {
        $pricing = app(AiPricing::class);
        $gates = $pricing->saleGates($model);
        try {
            $quote = $pricing->quote($model);
        } catch (AiPricingException $e) {
            return ['ready' => false, 'quote' => null, 'gates' => [...$gates, $e->errorCode]];
        }
        return ['ready' => $gates === [], 'quote' => $quote, 'gates' => $gates];
    }
}
