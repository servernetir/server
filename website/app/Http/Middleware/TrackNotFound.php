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

        // ۴۰۴های واقعی (لینکِ خرابِ خودمان) ثبت می‌شوند؛ کاوشِ ربات‌ها/اسکنرها
        // (xmlrpc، wp-login، .env، .git و…) نه — تا لاگ سیگنال بماند نه نویز.
        if ($response->getStatusCode() === 404 && ! $this->isProbe($request)) {
            ErrorTracker::notFound($request);
        }

        return $response;
    }

    /** آیا این ۴۰۴ کاوشِ خودکارِ رباتی است (نه لینکِ خرابِ واقعی)؟ */
    private function isProbe(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        return (bool) preg_match(
            '~(xmlrpc\.php|wp-login|wp-admin|wp-includes|wp-content|wordpress'
            .'|/\.env|/\.git|/\.aws|/\.ssh|/\.svn|/\.hg|/\.vscode|/\.idea|/\.DS_Store'
            .'|phpmyadmin|/pma|adminer|eval-stdin|boaform|hnap1|/cgi-bin|/actuator|/solr'
            .'|config\.json|\.gitlab-ci|id_rsa|/\.well-known/(?!security\.txt|acme))~i',
            $path
        );
    }
}
