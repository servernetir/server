<?php

namespace App\Http\Middleware;

use App\Services\Cloud\CloudStoreDraft;
use Closure;
use Illuminate\Http\Request;

class CloudStoreBrowse
{
    public function handle(Request $request, Closure $next)
    {
        // The separate managed-application checkout keeps its existing login contract.
        if (auth('customer')->guest() && $request->query('application') === 'n8n') {
            return redirect()->guest(lroute('login'))->header('Cache-Control', 'private, no-store');
        }
        if (auth('customer')->check() && $request->query('resume') === '1') {
            if ($data = app(CloudStoreDraft::class)->take($request)) {
                // Restore public choices, then require the normal explicit order submit.
                $request->session()->flashInput($data);
                $query = array_diff_key($data, ['label' => true]);

                return redirect(lroute('account.cloud.store', $query))->header('Cache-Control', 'private, no-store');
            }
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
