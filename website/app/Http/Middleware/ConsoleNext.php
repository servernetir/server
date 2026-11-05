<?php

namespace App\Http\Middleware;

use App\Support\ConsoleNext as Context;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in customer interface using the existing authenticated account actions. */
class ConsoleNext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Context::enabled()) {
            return $this->privateResponse(new Response('', 404));
        }

        $hadContext = $request->attributes->has(Context::ATTRIBUTE);
        $previousContext = $request->attributes->get(Context::ATTRIBUTE);
        $previousLocale = app()->getLocale();
        $request->attributes->set(Context::ATTRIBUTE, true);
        // Authentication is prioritized before the normal locale middleware.
        // Prepare only this interface's URL locale so its existing guest handler
        // keeps localized login and intended-URL behavior, including JSON callers.
        $locale = preg_match('~^/(en|tr)/account/next(?:/|$)~', $request->getPathInfo(), $parts)
            ? $parts[1] : 'fa';
        app()->setLocale($locale);

        try {
            $response = $next($request);
            if ($response->isRedirection() && $response->headers->has('Location')) {
                $response->headers->set('Location', Context::candidateUrl(
                    (string) $response->headers->get('Location'), $request,
                ));
            }

            return $this->privateResponse($response);
        } finally {
            app()->setLocale($previousLocale);
            if ($hadContext) {
                $request->attributes->set(Context::ATTRIBUTE, $previousContext);
            } else {
                $request->attributes->remove(Context::ATTRIBUTE);
            }
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
