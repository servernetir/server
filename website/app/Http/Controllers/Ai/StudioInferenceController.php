<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\AiUsage;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiCaller;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiPricingException;
use App\Services\Ai\AiRequestException;
use App\Services\Ai\AiVat;
use App\Services\Studio\StudioAuthorization;
use Illuminate\Http\Request;

final class StudioInferenceController extends Controller
{
    public function quote(Request $request)
    {
        $auth = $this->nativeAccess($request);
        if (! $auth->ok) return $this->failure($auth);
        $slug = $request->json('model');
        if (! is_string($slug) || strlen($slug) > 160) return V1ChatController::error('invalid_payload', 'Choose a model.', 422);
        $model = AiModel::with('provider')->where('slug', $slug)->first();
        if (! $model || ! AiNative::isNative($model)) return V1ChatController::error('model_not_found', 'Model not available.', 404);
        if (! AiCaller::salesOpenFor((int) $auth->customer->id) || ! app(AiAvailability::class)->inspect($model)['ready']) {
            return V1ChatController::error('model_unavailable', 'Model not available.', 503);
        }
        try {
            $prepared = app(AiNative::class)->prepare((array) $request->json()->all(), $model);
            $pricing = app(AiPricing::class);
            $quote = $pricing->quote($model);
            $vat = app(AiVat::class)->resolve($auth->customer);
            $amount = $pricing->holdNative($quote, $prepared->native['max_cost_micro'], $vat['bp']);
        } catch (AiRequestException $e) {
            return V1ChatController::error($e->errorCode, $e->getMessage(), $e->status);
        } catch (AiPricingException) {
            return V1ChatController::error('quote_unavailable', 'The current price is unavailable.', 503);
        }
        return response()->json(['max_hold_irt' => $amount['total']], 200, ['Cache-Control' => 'no-store']);
    }

    public function result(Request $request, string $job)
    {
        $auth = $this->nativeAccess($request);
        if (! $auth->ok) return $this->failure($auth);
        $usage = $this->usage($auth, $job);
        if (! $usage) return V1ChatController::error('request_not_found', 'Request not found.', 404);
        $outcome = app(AiCaller::class)->nativeResult($auth, $usage->public_id);
        if ($outcome->ok) return response()->json($outcome->response, $outcome->status, $outcome->headers);
        return response()->json([
            'status' => $usage->status === AiUsage::STATUS_RELEASED ? 'failed' : 'unknown',
            'code' => $outcome->code,
            'charge_irt' => in_array($usage->status, AiUsage::CHARGED, true) ? (int) $usage->charged_irt - (int) $usage->refunded_irt : null,
        ], 409, ['Cache-Control' => 'no-store']);
    }

    private function nativeAccess(Request $request): \App\Services\Ai\AiAuthContext
    {
        abort_unless(app(StudioAuthorization::class)->configured(), 404);
        $token = CustomerApiToken::findByPlain((string) $request->bearerToken());
        abort_unless($token, 401);
        return AiAdmission::authorize($token, 'ai:native', $request->ip());
    }

    private function failure(\App\Services\Ai\AiAuthContext $auth)
    {
        return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));
    }

    private function usage(\App\Services\Ai\AiAuthContext $auth, string $job): ?AiUsage
    {
        return AiUsage::where('customer_id', $auth->customer->id)
            ->where('ai_project_id', $auth->token->ai_project_id)->where('operation', 'native')
            ->where('idempotency_key', 'studio-media-'.$job)->first();
    }
}
