<?php

namespace App\Console\Commands;

use App\Models\PostTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * لینک‌های داخلیِ **متنِ مقاله‌ها** را می‌سنجد.
 *
 * ═══ چرا جدا از تستِ لینک ═══
 *
 * `InternalLinksResolveTest` لینک‌هایی را می‌گیرد که **قالب** می‌سازد — منو،
 * فوتر، کارت‌ها. ولی لینکی که نویسنده داخلِ متنِ یک پست نوشته در دیتابیس است،
 * نه در کد، و هیچ تستی روی مخزن نمی‌بیندش.
 *
 * ممیزیِ بیرونی دقیقاً یکی از همین‌ها را پیدا کرد: `/راهنمای-خرید-لپ-تاپ` که
 * ۴۰۴ می‌دهد و هیچ‌جای مخزن نیست. (⚠️ همان ممیزی «۵۸ لینکِ شکسته» گزارش کرد و
 * بخشِ عمده‌اش نقطه‌های اشتراک‌گذاریِ تلگرام و لینکدین بود که به ربات پاسخِ
 * غیر‌۲۰۰ می‌دهند — سالم‌اند. برای همین این فرمان **فقط لینکِ داخلی** را
 * می‌سنجد و به بیرونی دست نمی‌زند.)
 *
 * روی سرور اجرا می‌شود چون فقط آن‌جا متنِ واقعیِ پست‌ها هست:
 *
 *     php artisan links:content
 *     php artisan links:content --fix-report   (فقط فهرست، برای ویرایشِ دستی)
 */
class CheckContentLinks extends Command
{
    protected $signature = 'links:content {--limit=0 : سقفِ پست‌ها، ۰ یعنی همه}';

    protected $description = 'لینک‌های داخلیِ شکسته در متنِ مقاله‌ها و اسناد';

    public function handle(): int
    {
        if (! Schema::hasTable('post_translations')) {
            $this->warn('جدولِ post_translations نیست — روی این نصب چیزی برای بررسی نداریم.');

            return self::SUCCESS;
        }

        $known = $this->knownPaths();
        $limit = (int) $this->option('limit');

        $rows = PostTranslation::query()
            ->select(['id', 'post_id', 'locale', 'slug', 'body'])
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get();

        $broken = [];
        $checked = 0;

        foreach ($rows as $t) {
            preg_match_all('~href=["\']([^"\']+)["\']~i', (string) $t->body, $m);

            foreach (array_unique($m[1]) as $href) {
                $path = $this->internalPath($href);
                if ($path === null) {
                    continue;
                }
                $checked++;

                if (! $this->resolves($path, $known)) {
                    $broken[] = [$t->locale, $t->slug, $path];
                }
            }
        }

        $this->info("پست/سند بررسی‌شده: {$rows->count()} · لینکِ داخلی: {$checked}");

        if ($broken === []) {
            $this->info('✅ هیچ لینکِ داخلیِ شکسته‌ای در متن‌ها نیست.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('لینکِ داخلیِ شکسته ('.count($broken).'):');
        $this->table(['زبان', 'اسلاگِ پست', 'لینکِ شکسته'], $broken);
        $this->newLine();
        $this->line('این‌ها در **متنِ** پست‌اند، نه در کد — از /admin/posts ویرایش می‌شوند.');

        return self::FAILURE;
    }

    /**
     * مسیرهای ثابتِ ثبت‌شده در روتر — برای تشخیصِ سریع بی‌درخواستِ HTTP.
     *
     * ⚠️ static public است چون `links:site` (خزندهٔ هفتگیِ کلِ سایت — ممیزی ۳)
     * دقیقاً همین منطق را لازم دارد و نسخهٔ دوم یعنی روزی یکی‌شان کهنه شود.
     */
    public static function knownPaths(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{')) {
                $paths['/'.trim($route->uri(), '/')] = true;
            }
        }

        return $paths;
    }

    /**
     * ⚠️ مسیرِ پارامتردار (مثلِ `/blog/{slug}`) در فهرستِ بالا نیست، پس برای
     * آن‌ها واقعاً درخواست زده می‌شود. بی‌این، هر لینکِ پست‌به‌پست «شکسته»
     * گزارش می‌شد — همان مثبتِ کاذبی که این فرمان قرار است نسازد.
     */
    public static function resolves(string $path, array $known): bool
    {
        if (isset($known[rtrim($path, '/') ?: '/'])) {
            return true;
        }

        try {
            $request = \Illuminate\Http\Request::create($path, 'GET');
            $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

            return $response->getStatusCode() < 400;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function internalPath(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || str_starts_with($href, '#')
            || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            $host = parse_url($href, PHP_URL_HOST);
            if ($host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                return null;                    // بیرونی — قضاوتش کارِ این فرمان نیست
            }
            $href = (string) parse_url($href, PHP_URL_PATH);
        }

        $href = (string) strtok($href, '#');
        $href = (string) strtok($href, '?');

        return str_starts_with($href, '/') ? (rtrim($href, '/') ?: '/') : null;
    }
}
