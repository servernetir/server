<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** Separate cloud read/power budgets after bearer authentication has resolved the customer. */
class CustomerCloudApiThrottle
{
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        // Do not inherit ThrottleRequests: Laravel's priority sorter would move it
        // ahead of CustomerApiToken, accidentally sharing a budget by client IP.
        $customer = $request->attributes->get('api_customer');
        abort_unless($customer instanceof Customer && $request->user() === $customer, 401);

        $limit = match ($bucket) {
            'read' => 120,
            'power' => 20,
            default => throw new \InvalidArgumentException('Unknown cloud API rate-limit bucket.'),
        };

        try {
            return app(ThrottleRequests::class)->handle(
                $request, $next, $limit, 1, 'customer-cloud-api-'.$bucket.':',
            );
        } catch (ThrottleRequestsException $exception) {
            $headers = $exception->getHeaders();

            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => 'Too Many Attempts.',
                'rate_limit_scope' => 'cloud:'.$bucket,
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers + [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
    }
}
