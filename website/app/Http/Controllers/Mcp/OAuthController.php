<?php

namespace App\Http\Controllers\Mcp;

use App\Services\Mcp\AdminAccess;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;

final class OAuthController
{
    public function resource()
    {
        return response()->json([
            'resource' => config('mcp_admin.resource'),
            'authorization_servers' => [config('mcp_admin.origin')],
            'scopes_supported' => array_keys(AdminAccess::scopes()),
            'bearer_methods_supported' => ['header'],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function metadata()
    {
        $origin = config('mcp_admin.origin');
        return response()->json([
            'issuer' => $origin,
            'authorization_endpoint' => $origin.'/admin/mcp/oauth/authorize',
            'token_endpoint' => $origin.'/api/mcp/oauth/token',
            'registration_endpoint' => $origin.'/api/mcp/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_keys(AdminAccess::scopes()),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function register(Request $request, ClientRepository $clients)
    {
        abort_unless(AdminAccess::enabled(), 503);
        $data = $request->validate([
            'client_name' => ['nullable', 'string', 'max:100'],
            'redirect_uris' => ['required', 'array', 'min:1', 'max:2'],
            'redirect_uris.*' => ['required', 'string', 'max:512'],
            'token_endpoint_auth_method' => ['sometimes', 'in:none'],
            'grant_types' => ['sometimes', 'array'],
            'grant_types.*' => ['in:authorization_code,refresh_token'],
            'response_types' => ['sometimes', 'array'],
            'response_types.*' => ['in:code'],
        ]);
        foreach ($data['redirect_uris'] as $uri) {
            if (preg_match('~\Ahttps://chatgpt\.com/(?:connector/oauth/[A-Za-z0-9_-]+|connector_platform_oauth_redirect)\z~D', $uri) !== 1) {
                return response()->json(['error' => 'invalid_redirect_uri'], 400);
            }
        }
        $client = $clients->createAuthorizationCodeGrantClient(
            name: $data['client_name'] ?? 'ChatGPT — ServerNet Infrastructure',
            redirectUris: array_values(array_unique($data['redirect_uris'])),
            confidential: false,
        );
        $client->forceFill(['provider' => 'users'])->save();
        return response()->json([
            'client_id' => (string) $client->id,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', array_keys(AdminAccess::scopes())),
        ], 201, ['Cache-Control' => 'no-store']);
    }
}
