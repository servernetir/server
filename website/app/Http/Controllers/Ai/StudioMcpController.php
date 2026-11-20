<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\CustomerApiToken;
use App\Services\Ai\AiAdmission;
use Illuminate\Http\Request;

final class StudioMcpController extends Controller
{
    private const VERSION = '2025-11-25';

    public function __invoke(Request $request)
    {
        $origin = $request->header('Origin');
        abort_if($origin !== null && ! in_array($origin, ['https://studio.servernet.cloud','https://servernet.cloud'], true), 403);
        $token = CustomerApiToken::findByPlain((string) $request->bearerToken());
        if (! $token) return V1ChatController::error('invalid_token', 'A ServerNet API key is required.', 401);
        $auth = AiAdmission::authorize($token, 'ai:models:read', $request->ip());
        if (! $auth->ok) return V1ChatController::error($auth->code, $auth->message, V1ChatController::statusFor($auth->code));
        if (! $request->isMethod('POST')) return response('', 405, ['Allow'=>'POST','Cache-Control'=>'no-store']);
        if (strlen($request->getContent()) > 8192) return V1ChatController::error('invalid_payload', 'MCP request is too large.', 413);

        $body = $request->json()->all();
        $id = $body['id'] ?? null;
        if (($body['jsonrpc'] ?? null) !== '2.0' || ! is_string($body['method'] ?? null)
            || ($id !== null && ! is_int($id) && ! is_string($id))) {
            return $this->reply(null, 'error', ['code' => -32600, 'message' => 'Invalid Request']);
        }
        $method = $body['method'];
        if ($id === null && str_starts_with($method, 'notifications/')) return response('', 202, ['Cache-Control'=>'no-store']);
        if ($id === null) return $this->reply(null, 'error', ['code' => -32600, 'message' => 'A request id is required']);
        if ($method === 'initialize') {
            $version = $body['params']['protocolVersion'] ?? null;
            return $this->reply($id, 'result', ['protocolVersion' => in_array($version, ['2025-06-18', self::VERSION], true) ? $version : self::VERSION,
                'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'servernet-studio', 'version' => '1.0.0']]);
        }
        if ($method === 'ping') return $this->reply($id, 'result', (object) []);
        if ($method === 'tools/list') {
            return $this->reply($id, 'result', ['tools' => [[
                'name' => 'list_models', 'description' => 'Read the available ServerNet models and their API model IDs.',
                'inputSchema' => ['type'=>'object','properties'=>['category'=>['type'=>'string','enum'=>['chat','image','video','audio','music','embedding','rerank','classification']]],
                    'additionalProperties'=>false], 'annotations' => ['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false],
            ]]]);
        }
        if ($method === 'tools/call' && ($body['params']['name'] ?? '') === 'list_models') {
            $args = $body['params']['arguments'] ?? [];
            $category = is_array($args) ? ($args['category'] ?? null) : '';
            if (! is_array($args) || array_diff(array_keys($args), ['category'])
                || ($category !== null && ! in_array($category, ['chat','image','video','audio','music','embedding','rerank','classification'], true))) {
                return $this->reply($id, 'error', ['code' => -32602, 'message' => 'Invalid model category']);
            }
            $response = app(V1ModelsController::class)->index($request);
            if ($response->getStatusCode() >= 400) return $response;
            $models = array_values(array_filter($response->getData(true)['data'],
                fn ($model) => $category === null || $model['category'] === $category));
            return $this->reply($id, 'result', ['content' => [['type'=>'text','text'=>json_encode($models, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]);
        }
        return $this->reply($id, 'error', ['code' => -32601, 'message' => 'Method or tool not found']);
    }

    private function reply(int|string|null $id, string $type, mixed $body)
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, $type => $body], 200, ['Cache-Control' => 'no-store']);
    }
}
