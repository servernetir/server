<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Dedicated server credential; a browser/admin session never grants ingestion. */
class ChatLeadToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.n8n.chat_leads_token', ''));
        if ($expected === '') {
            $file = storage_path('app/private/chat-leads.token');
            $expected = is_readable($file) ? trim((string) file_get_contents($file)) : '';
        }
        if (strlen($expected) < 32) {
            return response()->json(['ok' => false, 'code' => 'not_configured'], 503);
        }
        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['ok' => false, 'code' => 'unauthorized'], 401);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
