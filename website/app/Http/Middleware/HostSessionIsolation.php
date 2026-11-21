<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class HostSessionIsolation
{
    public static function applies(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'servernet.cloud' || str_ends_with($host, '.servernet.cloud');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request->getHost())) {
            return $next($request);
        }

        config([
            'session.domain' => null,
            'session.path' => '/',
            'session.secure' => true,
            'session.cookie' => '__Host-servernet_session',
            'session.host_isolated' => true,
        ]);
        app('cookie')->setDefaultPathAndDomain('/', null, true, config('session.same_site'));

        $response = $next($request);
        // Retire parent-domain cookies without reading their values or reusing old sessions.
        foreach (array_keys($request->cookies->all()) as $name) {
            if (in_array($name, ['snet_session', 'XSRF-TOKEN'], true) || str_starts_with($name, 'remember_')) {
                $response->headers->setCookie(new Cookie($name, '', 1, '/', '.servernet.cloud', true, true, false, 'lax'));
            }
        }

        return $response;
    }
}
