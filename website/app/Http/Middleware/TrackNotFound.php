<?php

namespace App\Http\Middleware;

use App\Support\ErrorTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ۴۰۴ را از روی وضعیت پاسخ ثبت می‌کند — مستقل از اینکه چطور پیش آمده.
 *
 * ۴۰۴ استثنایی است که لاراول گزارش نمی‌کند، پس از مسیر report() نمی‌شود
 * گرفتش. اینجا بعد از تولید پاسخ، اگر ۴۰۴ بود آدرس گمشده را ثبت می‌کنیم —
 * همان چیزی که می‌خواهیم بدانیم «کاربر دنبال چه چیزی بود که نبود».
 */
class TrackNotFound
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === 404) {
            ErrorTracker::notFound($request);
        }

        return $response;
    }
}
