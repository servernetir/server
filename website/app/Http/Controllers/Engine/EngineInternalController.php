<?php

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Controller;
use App\Models\EngineAgentCredential;
use App\Models\EngineNode;
use App\Services\Cloud\Engine\EngineConsoleService;
use App\Services\Cloud\Engine\EngineTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EngineInternalController extends Controller
{
    private function authenticate(Request $request, ?string $secret): void
    {
        $body = $request->getContent();
        $time = (string) $request->header('X-Engine-Time', '');
        $signature = (string) $request->header('X-Engine-Signature', '');
        abort_unless(strlen($body) <= 4096 && is_string($secret) && strlen($secret) >= 32
            && preg_match('/\A[0-9]{10}\z/D', $time) === 1 && abs(now()->timestamp - (int) $time) <= 30
            && preg_match('/\A[a-f0-9]{64}\z/D', $signature) === 1
            && hash_equals(hash_hmac('sha256', $time."\n".$body, $secret), $signature), 403);
    }

    public function traffic(Request $request, EngineNode $node, EngineTrafficService $traffic): JsonResponse
    {
        $credential = EngineAgentCredential::where('node_id', $node->id)->where('enabled', true)->first();
        $this->authenticate($request, $credential?->secret);
        try {
            return response()->json($traffic->ingest($node, $request->json()->all()))->header('Cache-Control', 'no-store');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
    }

    public function console(Request $request, EngineConsoleService $console): JsonResponse
    {
        $this->authenticate($request, config('cloud-engine.console.gateway_secret'));
        try {
            $result = $console->exchange((string) $request->json('token', ''), (string) $request->json('phase', ''));

            return response()->json($result)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 410)->header('Cache-Control', 'no-store');
        }
    }
}
