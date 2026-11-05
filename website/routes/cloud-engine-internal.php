<?php

use App\Http\Controllers\Engine\EngineInternalController;
use App\Models\EngineNode;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Keep node samples and gateway sessions in separate buckets. At a ten-second
// cadence, one hundred guests produce six hundred samples/validations per minute.
RateLimiter::for('engine-telemetry', function (Request $request) {
    $node = $request->route('node');
    $identity = $node instanceof EngineNode ? $node->getKey() : (string) $node;

    return Limit::perMinute(6000)->by('traffic:'.$identity.':'.$request->ip());
});
RateLimiter::for('engine-console-gateway', fn (Request $request) => Limit::perMinute(3000)->by('console:'.$request->ip()));

// HMAC authentication inside each controller. No browser/session credentials accepted.
Route::prefix('api/v1/cloud-engine/internal')->group(function () {
    Route::post('/nodes/{node}/traffic', [EngineInternalController::class, 'traffic'])->middleware('throttle:engine-telemetry')->name('engine.internal.traffic');
    Route::post('/console', [EngineInternalController::class, 'console'])->middleware('throttle:engine-console-gateway')->name('engine.internal.console');
});
