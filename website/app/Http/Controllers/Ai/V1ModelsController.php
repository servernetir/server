<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiPricingException;
use App\Services\Ai\AiVat;
use App\Services\Studio\StudioCatalog;
use Illuminate\Http\Request;

class V1ModelsController extends Controller
{
    public function index(Request $request)
    {
        $token = CustomerApiToken::findByPlain((string) $request->bearerToken());
        if ($token === null) {
            return V1ChatController::error('invalid_token', 'Invalid API key.', 401);
        }
        $auth = AiAdmission::authorize($token, 'ai:models:read', $request->ip());
        if (! $auth->ok) {
            return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));
        }
        $studio = $request->boolean('studio');
        $vat = $studio ? app(AiVat::class)->resolve($auth->customer)['bp'] : 0;
        $data = [];
        $catalogue = app(\App\Services\Ai\AiProviderRouting::class)->catalogue(
            AiModel::with('provider')->where('status', 'active')->orderBy('slug')->get());
        foreach ($catalogue as $model) {
            $availability = app(AiAvailability::class)->inspect($model);
            if (! $availability['ready']) continue;
            $native = AiNative::isNative($model);
            $entry = ['id' => $model->slug, 'object' => 'model', 'created' => $model->created_at->timestamp,
                'owned_by' => 'servernet', 'category' => $model->category] + ($native
                    ? ['endpoint' => '/v1/inference', 'scope' => 'ai:native', 'billing_unit' => $availability['quote']->native['pricing']['type']]
                    : ['context_length' => $model->context_tokens, 'max_output_tokens' => $model->max_output_tokens]);
            if ($studio) {
                try {
                    $entry += StudioCatalog::model($model, $availability['quote'], $vat, $token->can('ai:native'));
                } catch (AiPricingException) {
                    continue;
                }
            }
            $data[] = $entry;
        }
        if ($studio && $token->can('ai:chat') && StudioFreeController::available()) {
            array_unshift($data, ['id'=>StudioFreeController::MODEL,'object'=>'model','owned_by'=>'servernet',
                'category'=>'chat','name'=>'Free Chat','endpoint'=>'/v1/studio/free/chat','vision'=>false,
                'pricing'=>['unit'=>'tokens','quantity'=>1_000_000,'input_irt'=>0,'output_irt'=>0], 'free'=>true]);
        }
        return response()->json(['object' => 'list', 'data' => $data], 200, ['Cache-Control' => 'no-store']);
    }
}
