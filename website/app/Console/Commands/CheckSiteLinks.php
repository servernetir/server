<?php

namespace App\Console\Commands;

use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * خزندهٔ لینکِ شکستهٔ **کلِ سایت** — دروازهٔ رگرسیونی که ممیزی ۳ گفت نداریم.
 *
 * ═══ چرا این فرمان وجود دارد ═══
 *
 * QA در ممیزی ۳: «لینکِ ۴۰۴ باید در یک cron هفتگی می‌مرد، نه در ممیزیِ
 * سه‌ماهه. تنها مکانیزمِ تأییدِ "انجام شد"، ممیزیِ بعدی است.» این فرمان همان
 * cron است: همهٔ صفحه‌های نقشهٔ سایت را رندر می‌کند، هر لینکِ داخلی‌ای که
 * قالب‌ها می‌سازند برمی‌دارد، و شکسته‌ها را با منبعشان گزارش می‌کند + یک
 * `noteOnce` تا در /admin/errors دیده شود — جایی که مدیر واقعاً نگاه می‌کند.
 *
 * ═══ چرا درون-پروسه‌ای و نه HTTP ═══
 *
 * از بیرون، Cloudflare خزندهٔ خودی را هم بات می‌بیند و ۴۰۳ می‌دهد (در بازسازیِ
 * خزندهٔ ممیزی دیده شد: ۱۴۹۱ مثبتِ کاذب). رندر از داخلِ خودِ اپ همان چیزی را
 * می‌سنجد که واقعاً مهم است: «آیا این مسیر در این اپ به صفحه می‌رسد؟» — بدونِ
 * شبکه، بدونِ نویزِ لبه. لینکِ بیرونی عمداً سنجیده نمی‌شود (همان قاعدهٔ
 * `links:content`: پاسخِ غیر۲۰۰ به ربات ≠ شکسته).
 *
 *     php artisan links:site               ← فقط صفحات فارسی (قالب‌ها مشترک‌اند)
 *     php artisan links:site --all-locales ← هر سه زبان
 *     php artisan links:site --limit=20    ← برای اجرای دستی/تست
 */
class CheckSiteLinks extends Command
{
    protected $signature = 'links:site
        {--limit=0 : سقفِ صفحه‌های رندرشده، ۰ یعنی همه}
        {--all-locales : en و tr هم بررسی شوند (پیش‌فرض فقط fa)}';

    protected $description = 'رندرِ همهٔ صفحات نقشهٔ سایت و گزارشِ هر لینکِ داخلیِ شکسته';

    public function handle(): int
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $known = CheckContentLinks::knownPaths();

        // فهرستِ صفحه‌ها از همان منبعی که به گوگل می‌دهیم — نه فهرستِ دستی
        $response = $kernel->handle(Request::create('/sitemap.xml', 'GET'));

        if ($response->getStatusCode() !== 200) {
            $this->error('sitemap.xml رندر نشد: '.$response->getStatusCode());

            return self::FAILURE;
        }

        preg_match_all('~<loc>([^<]+)</loc>~', (string) $response->getContent(), $m);

        $pages = [];

        foreach ($m[1] as $loc) {
            $path = CheckContentLinks::internalPath(html_entity_decode($loc, ENT_XML1));

            if ($path === null) {
                continue;
            }

            if (! $this->option('all-locales') && preg_match('~^/(en|tr)(/|$)~', $path)) {
                continue;
            }

            $pages[$path] = true;
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $pages = array_slice($pages, 0, $limit, true);
        }

        $this->info('صفحه برای رندر: '.count($pages));

        /*
        | گذرِ اول: رندرِ هر صفحه. خودِ صفحهٔ خراب (۴۰۴/۵۰۰ از نقشهٔ سایت!)
        | جدی‌ترین یافته است؛ hrefهایش هم برای گذرِ دوم جمع می‌شوند.
        */
        $links = [];          // path => اولین صفحهٔ منبع
        $broken = [];         // [منبع، لینک، وضعیت]

        foreach (array_keys($pages) as $path) {
            try {
                $r = $kernel->handle(Request::create($path, 'GET'));
            } catch (\Throwable $e) {
                $broken[] = [$path, '(خودِ صفحه)', get_class($e)];

                continue;
            }

            if ($r->getStatusCode() >= 400) {
                $broken[] = [$path, '(خودِ صفحه)', (string) $r->getStatusCode()];

                continue;
            }

            preg_match_all('~href=["\']([^"\']+)["\']~i', (string) $r->getContent(), $hm);

            foreach (array_unique($hm[1]) as $href) {
                $p = CheckContentLinks::internalPath($href);

                if ($p !== null && ! isset($pages[$p]) && ! isset($links[$p])) {
                    $links[$p] = $path;
                }
            }
        }

        $this->info('لینکِ داخلیِ یکتای خارج از نقشه: '.count($links));

        // گذرِ دوم: هر لینکِ داخلی که خودش صفحهٔ نقشه نبود
        foreach ($links as $p => $source) {
            if (! CheckContentLinks::resolves($p, $known)) {
                $broken[] = [$source, $p, '404'];
            }
        }

        if ($broken === []) {
            $this->info('✅ هیچ لینکِ داخلیِ شکسته‌ای نیست.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('شکسته ('.count($broken).'):');
        $this->table(['صفحهٔ منبع', 'لینک', 'وضعیت'], $broken);

        /*
        | فریاد در همان‌جایی که مدیر نگاه می‌کند. شمارش داخلِ متن است تا تغییرِ
        | تعداد، گلوگاهِ ۶ساعته را بشکند و پیامِ تازه برود (کلیدِ گلوگاه از md5ِ
        | متن ساخته می‌شود — قاعدهٔ ثبت‌شدهٔ noteOnce).
        */
        ErrorTracker::noteOnce('site', 'خزندهٔ هفتگی '.count($broken).' لینکِ داخلیِ شکسته یافت — php artisan links:site', 21600, [
            'first' => array_slice(array_map(fn ($b) => $b[1].' ← '.$b[0], $broken), 0, 10),
        ]);

        return self::FAILURE;
    }
}
