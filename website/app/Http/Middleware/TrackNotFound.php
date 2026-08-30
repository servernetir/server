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

    /**
     * آیا این ۴۰۴ کاوشِ خودکارِ رباتی است (نه لینکِ خرابِ واقعی)؟
     *
     * 🔴 قاعدهٔ اول و مؤثرترین: **هر مسیرِ `.php`**.
     *
     * این سایت لاراول است و حتی یک روتِ `.php` ندارد؛ `public/index.php` هم
     * از بیرون صدا زده نمی‌شود. پس هر ۴۰۴ی که به `.php` ختم شود، بی‌استثنا
     * اسکنرِ وب‌شل است. روی همین نصب، ۱۴۴ ردیفِ ۴۰۴ ثبت شده بود که تقریباً
     * همه‌شان همین بودند (`xxx.php`، `w3lls.php`، `sql.php`، …) و لاگ را
     * چنان پر می‌کردند که خطای واقعی گم می‌شد.
     *
     * فهرستِ نامیِ پایین می‌مانَد چون مسیرهای بی‌پسوند را هم می‌گیرد
     * (`/minishell`، `/actuator`، `/cgi-bin`).
     */
    private function isProbe(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        // پسوندهایی که این سایت اصلاً سرو نمی‌کند ⇒ همیشه اسکنر
        if (preg_match('~\.(php\d?|phtml|asp|aspx|jsp|cgi|pl)$~i', $path)) {
            return true;
        }

        // نقشهٔ سایتِ وردپرسی: بازماندهٔ دامنهٔ قدیمی، نه لینکِ خرابِ ما
        if (preg_match('~^/wp-sitemap[\w-]*\.xml$~i', $path)) {
            return true;
        }

        /*
        | 🔴 خانوادهٔ REST وردپرس — `/wp-json/...`
        |
        | فهرستِ قبلی `wp-login` و `wp-admin` را داشت ولی **`wp-json` را نه**، و
        | همان یک قلم بیشترین ردیف‌های تازهٔ لاگ را می‌ساخت
        | (`/wp-json/batch/v1` که مسیرِ سوءاستفادهٔ شناخته‌شدهٔ افزونه‌هاست).
        | این سایت وردپرس نیست، پس هیچ‌کدامشان لینکِ خرابِ ما نیستند.
        */
        if (str_starts_with($path, '/wp-json')) {
            return true;
        }

        /*
        | اسکنرهای سرویسِ ویندوزی/سازمانی — نه لینکِ خراب، نه بازدیدکنندهٔ واقعی.
        | `/RDWeb/...` کاوشِ Remote Desktop Gateway است و روی همین نصب دیده شد.
        */
        if (preg_match('~^/(RDWeb|owa|autodiscover|ecp|_ignition|telescope|server-status)(/|$)~i', $path)) {
            return true;
        }

        return (bool) preg_match(
            '~(xmlrpc\.php|wp-login|wp-admin|wp-includes|wp-content|wordpress'
            .'|/\.env|/\.git|/\.aws|/\.ssh|/\.svn|/\.hg|/\.vscode|/\.idea|/\.DS_Store'
            .'|phpmyadmin|/pma|adminer|eval-stdin|boaform|hnap1|/cgi-bin|/actuator|/solr'
            // نامِ وب‌شل‌های بی‌پسوند — از لاگِ واقعیِ همین سایت
            .'|minishell|/shell|/webshell|/backdoor'
            .'|config\.json|\.gitlab-ci|id_rsa|/\.well-known/(?!security\.txt|acme))~i',
            $path
        );
    }
}
