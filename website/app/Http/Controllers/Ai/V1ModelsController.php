<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Ai\AiAvailability;
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
        $data = AiModel::with('provider')->where('status', 'active')->where('category', 'chat')->orderBy('slug')->get()
            ->filter(fn ($m) => app(AiAvailability::class)->inspect($m)['ready'])
            ->map(fn ($m) => ['id' => $m->slug, 'object' => 'model', 'created' => $m->created_at->timestamp,
                'owned_by' => 'servernet', 'context_length' => $m->context_tokens,
                'max_output_tokens' => $m->max_output_tokens])->values();
        return response()->json(['object' => 'list', 'data' => $data], 200, ['Cache-Control' => 'no-store']);
    }
}
