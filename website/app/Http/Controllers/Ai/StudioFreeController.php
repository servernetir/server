<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use App\Services\Studio\StudioAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

final class StudioFreeController extends Controller
{
    public const MODEL = 'studio-free-chat';

    public static function available(): bool
    {
        return app(StudioAuthorization::class)->configured() && strlen((string) config('studio.free_openrouter_key')) >= 20;
    }

    public function chat(Request $request)
    {
        abort_unless(self::available(), 404);
        $token = CustomerApiToken::findByPlain((string) $request->bearerToken());
        abort_unless($token, 401);
        $auth = AiAdmission::authorize($token, 'ai:chat', $request->ip());
        if (! $auth->ok) return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));

        $messages = $request->json('messages');
        if ($request->json('model') !== self::MODEL || ! is_array($messages) || ! array_is_list($messages)
            || ! $messages || count($messages) > 24 || strlen($request->getContent()) > 24000) {
            return V1ChatController::error('invalid_payload', 'Free Chat accepts short text conversations.', 422);
        }
        foreach ($messages as $message) {
            if (! is_array($message) || ! in_array($message['role'] ?? '', ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null) || array_diff(array_keys($message), ['role', 'content'])) {
                return V1ChatController::error('invalid_payload', 'Free Chat accepts text messages.', 422);
            }
        }
        $limited = Cache::lock('studio:free-chat:admission', 5)->get(function () use ($auth) {
            $now = now('UTC');
            $day = $now->format('Y-m-d');
            $keys = ['studio:free:global:'.$day => 40, 'studio:free:customer:'.$auth->customer->id.':'.$day => 5];
            foreach ($keys as $key => $limit) if (RateLimiter::tooManyAttempts($key, $limit)) return false;
            foreach (array_keys($keys) as $key) RateLimiter::hit($key, 86400);
            return true;
        });
        if (! $limited) return V1ChatController::error('rate_limited', 'The daily free allowance is exhausted. Try again later.', 429);
        try {
            $response = Http::withToken((string) config('studio.free_openrouter_key'))->acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false])->connectTimeout(4)->timeout(35)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'openrouter/free', 'messages' => $messages, 'max_tokens' => 768, 'stream' => false,
                    'provider' => ['max_price' => ['prompt' => 0, 'completion' => 0, 'request' => 0]],
                    'usage' => ['include' => true],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return V1ChatController::error('free_unavailable', 'Free Chat is temporarily unavailable.', 503);
        }
        if ($response->status() === 429) return V1ChatController::error('rate_limited', 'The free provider is at capacity.', 429);
        $body = $response->json();
        $text = $body['choices'][0]['message']['content'] ?? null;
        $usage = $body['usage'] ?? null;
        $cost = $usage['cost'] ?? null;
        if (! $response->ok() || ! is_string($text) || strlen($text) > 48000 || ! is_array($usage)
            || ! is_numeric($cost) || (float) $cost !== 0.0) {
            return V1ChatController::error('free_unavailable', 'No verified free response was available.', 503);
        }
        return response()->json(['text' => $text, 'usage' => array_intersect_key($usage, array_flip(['prompt_tokens','completion_tokens','total_tokens'])),
            'charge_irt' => 0], 200, ['Cache-Control' => 'no-store']);
    }
}
