<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Finance\Wallet;
use App\Services\Studio\StudioAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class StudioController extends Controller
{
    public function authorizePage(Request $request, StudioAuthorization $service)
    {
        $data = $service->validateRequest($request->query());
        return response()->view('studio.authorize', ['data' => $data], 200, [
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function authorizeSubmit(Request $request, StudioAuthorization $service)
    {
        $data = $service->validateRequest($request->all());
        $code = $service->issue(Auth::guard('customer')->user(), $data);
        return redirect()->away($data['redirect_uri'].'?'.http_build_query([
            'code' => $code, 'state' => $data['state'],
        ]))->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function token(Request $request, StudioAuthorization $service)
    {
        return response()->json($service->exchange($request->json()->all()), 200, ['Cache-Control' => 'no-store']);
    }

    public function account(Request $request)
    {
        [$token, $auth] = $this->authorizeKey($request);
        if (! $auth->ok) return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));
        return DB::transaction(function () use ($auth) {
            $customer = Customer::whereKey($auth->customer->id)->lockForUpdate()->firstOrFail();
            $wallet = app(Wallet::class);
            $balance = $wallet->balanceOf($customer->id, 'IRT', true);
            $reserved = $wallet->reservedOf($customer->id, 'IRT', currentRead: true);
            $usage = AiUsage::where('customer_id', $customer->id)->whereIn('status', AiUsage::CHARGED)
                ->where('created_at', '>=', now()->startOfMonth());
            return response()->json([
                'customer' => ['id' => (string) $customer->id, 'name' => $customer->displayName()],
                'wallet' => ['currency' => 'IRT', 'unit' => 'toman', 'balance' => $balance,
                    'reserved' => $reserved, 'available' => $balance - $reserved],
                'ai' => ['spent_month' => (int) (clone $usage)->sum(DB::raw('charged_irt - refunded_irt')),
                    'prompt_tokens_month' => (int) (clone $usage)->sum('prompt_tokens'),
                    'completion_tokens_month' => (int) (clone $usage)->sum('completion_tokens')],
                'console_url' => 'https://console.servernet.cloud/account/ai',
            ], 200, ['Cache-Control' => 'no-store']);
        });
    }

    public function revoke(Request $request)
    {
        [$token, $auth] = $this->authorizeKey($request);
        if (! $auth->ok) return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));
        $token->revoke();
        return response()->json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }

    private function authorizeKey(Request $request): array
    {
        abort_unless(app(StudioAuthorization::class)->configured(), 404);
        $token = CustomerApiToken::findByPlain((string) $request->bearerToken());
        abort_unless($token, 401);
        return [$token, AiAdmission::authorize($token, 'ai:wallet:read', $request->ip())];
    }
}
