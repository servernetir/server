<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * خوشهٔ محتوای هاست پایتون (بلاگ ۱۴۰۵، خوشهٔ ۳۳).
 *
 * ═══ چرا ═══
 *
 * `/hosting/python` پرنمایش‌ترین صفحهٔ محصولِ ماست (۲۰۵۴ نمایش در سه ماه، GSC)
 * و در رتبهٔ ۵۹.۷ مانده: گوگل آن را برای پرسش‌های **راهنمایی** نشان می‌دهد و
 * ما فقط صفحهٔ فروش داشتیم. ده مقاله همان پرسش‌ها را جواب می‌دهند و همه به
 * همان صفحه لینک می‌شوند.
 *
 * 🔴 مهم‌ترین ادعای این تست: **بریف‌ها مسیرِ ناموجود تجویز نکنند.**
 * روی `core` هیچ CloudLinux نصب نیست، پس «Setup Python App» وجود ندارد و
 * مسیرِ درست Application Manager + Passenger است؛ PostgreSQL و Redis هم نصب
 * نیستند. مقاله‌ای که این را نداند، خواننده را به بن‌بست می‌فرستد و تیکت
 * می‌سازد — یعنی دقیقاً برعکسِ هدفِ خوشه. (حافظهٔ ۲۹ اوت ۲۰۲۶)
 */
class PythonHostingClusterTest extends TestCase
{
    /** @var array<int, string> */
    private const CLUSTER = [
        'deploy-django-cpanel-passenger',
        'python-app-not-starting-passenger',
        'fastapi-on-wsgi-with-a2wsgi',
        'flask-deploy-shared-hosting',
        'python-venv-pip-shared-hosting',
        'django-static-files-cpanel',
        'django-mysql-instead-of-postgres',
        'python-scheduled-tasks-cpanel',
        'django-secrets-env-shared-hosting',
        'python-hosting-vs-vps-when-to-move',
    ];

    /** @return array<string, array<string, mixed>> اسلاگ ← ردیفِ برنامه */
    private function plan(): array
    {
        $rows = require base_path('resources/content/blog-1405.php');

        return collect($rows)->keyBy('slug')->all();
    }

    public function test_all_ten_topics_are_queued_with_near_dates(): void
    {
        $plan = $this->plan();

        foreach (self::CLUSTER as $slug) {
            $this->assertArrayHasKey($slug, $plan, "موضوعِ $slug در صفِ بلاگ نیست");
            $this->assertMatchesRegularExpression(
                '~^2026-(10|11)-\d\d$~',
                (string) $plan[$slug]['date'],
                "$slug: تاریخِ انتشار باید در مهر/آبان باشد"
            );
            $this->assertNotEmpty($plan[$slug]['keyword'], "$slug بدونِ کلیدواژه");
            $this->assertGreaterThan(80, mb_strlen($plan[$slug]['brief']), "$slug: بریفِ کوتاه = مقالهٔ عمومی");
        }
    }

    /** 🔴 بریف نباید مسیری را تجویز کند که روی سرورِ ما وجود ندارد */
    public function test_no_brief_sends_the_reader_down_a_path_this_server_does_not_have(): void
    {
        $plan = $this->plan();
        $all = '';

        foreach (self::CLUSTER as $slug) {
            $all .= ' '.$plan[$slug]['fa'].' '.$plan[$slug]['brief'];
        }

        // «Setup Python App» فقط با CloudLinux هست و ما نداریم؛ اگر نامش آمد،
        // باید صریح به‌عنوانِ چیزی که **وجود ندارد** آمده باشد.
        if (str_contains($all, 'Setup Python App')) {
            $this->assertMatchesRegularExpression(
                '~(نه|بدون|وجود ندارد|ندارد)[^.]{0,60}Setup Python App|Setup Python App[^.]{0,60}(وجود ندارد|ندارد)~u',
                $all,
                'نامِ Setup Python App فقط برای ردکردنش مجاز است'
            );
        }

        // مسیرِ درست باید دستِ‌کم یک بار صریح گفته شده باشد
        $this->assertStringContainsString('Application Manager', $all, 'مسیرِ واقعیِ دیپلوی در هیچ بریفی نیست');
        $this->assertStringContainsString('Passenger', $all);

        // PostgreSQL/Redis روی هاستِ اشتراکی نصب نیستند — تجویزشان تیکت می‌سازد
        foreach (['PostgreSQL', 'Redis'] as $missing) {
            if (str_contains($all, $missing)) {
                $this->assertMatchesRegularExpression(
                    '~'.$missing.'[^.]{0,40}(نیست|ندارد|نصب نیست)|(نیست|ندارد|بدون)[^.]{0,40}'.$missing.'~u',
                    $all,
                    "$missing فقط به‌عنوانِ چیزی که نداریم می‌تواند بیاید"
                );
            }
        }
    }

    public function test_every_article_points_at_the_page_it_is_written_for(): void
    {
        $python = lroute('hosting', 'python');

        foreach (self::CLUSTER as $slug) {
            $rel = blog_related_product('tutorial', $slug);
            $this->assertNotNull($rel, "$slug: پلِ محصول ندارد");

            if ($slug === 'python-hosting-vs-vps-when-to-move') {
                // موضوعش «کِی مهاجرت کنیم» است، پس عمداً به سرور مجازی می‌رود
                $this->assertSame(lroute('catalog', ['category' => 'vps', 'slug' => 'linux']), $rel['href'], $slug);

                continue;
            }

            $this->assertSame($python, $rel['href'], "$slug باید به /hosting/python برود");
        }
    }

    /** اسلاگِ تکراری یعنی یکی از دو مقاله هرگز ساخته نمی‌شود */
    public function test_the_cluster_slugs_are_unique_across_every_content_plan(): void
    {
        $seen = [];

        foreach (glob(base_path('resources/content/*.php')) as $file) {
            foreach ((array) require $file as $row) {
                $slug = is_array($row) ? ($row['slug'] ?? null) : null;

                if ($slug === null) {
                    continue;
                }

                $this->assertArrayNotHasKey($slug, $seen, "اسلاگِ تکراری «{$slug}» در ".basename($file).' و '.($seen[$slug] ?? ''));
                $seen[$slug] = basename($file);
            }
        }

        foreach (self::CLUSTER as $slug) {
            $this->assertSame('blog-1405.php', $seen[$slug] ?? null, "$slug باید فقط در برنامهٔ بلاگ باشد");
        }
    }
}
