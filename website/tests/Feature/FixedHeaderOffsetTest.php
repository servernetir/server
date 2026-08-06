<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔴 قاعدهٔ هدرِ ثابت — این تست وجود دارد تا آن باگ **دیگر برنگردد**.
 *
 * کارفرما چند بار گزارش داد «صفحهٔ تازه رفته زیرِ هدر»، و هر بار همان یک صفحه
 * وصله شد. علتِ ریشه‌ای این بود که هدر `position:fixed` است (۳۸ + ۷۲ + ۱ ≈ ۱۱۱
 * پیکسل) و هیچ‌جا آن فضا رزرو نمی‌شد — پس هر صفحهٔ جدید باید **یادش می‌ماند**
 * padding-top بگذارد. پیش‌فرضِ «فراموشی = محتوای پنهان».
 *
 * حالا `#main` همیشه فضا را رزرو می‌کند و صفحاتی که از قبل جبرانِ خودشان را
 * دارند همان‌قدر بالا کشیده می‌شوند. این تست هر سه پایهٔ آن قاعده را قفل می‌کند.
 */
class FixedHeaderOffsetTest extends TestCase
{
    /** کلاس‌هایی که عمداً خودشان جبران می‌کنند و بالا کشیده می‌شوند */
    private const PULLED_BACK = [
        '.hero', '.srv-hero', '.sd-wrap', '.wt-wrap', '.docs-wrap', '.bp-head',
    ];

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/site.css'));
    }

    // ═══════════════ پایه‌های قاعده ═══════════════

    public function test_the_header_height_lives_in_one_variable(): void
    {
        $this->assertMatchesRegularExpression('~--header-h:\s*\d+px~', $this->css(),
            'ارتفاعِ هدر باید یک متغیر باشد، نه عددِ پراکنده در ده جا');
    }

    /** 🔴 قلبِ قاعده: صفحهٔ تازه‌ای که هیچ‌چیز نگذارد باید درست باشد */
    public function test_main_reserves_the_fixed_header_space(): void
    {
        $this->assertMatchesRegularExpression(
            '~#main\{[^}]*padding-top:\s*var\(--header-h\)~',
            $this->css(),
            '#main فضای هدر را رزرو نمی‌کند — یعنی هر صفحهٔ جدید باید یادش بماند، '
            .'و همان باگی که چند بار گزارش شد برمی‌گردد'
        );
    }

    /** ارتفاعِ متغیر باید واقعاً از هدر بزرگ‌تر باشد (topbar ۳۸ + نوار ۷۲ + خط ۱) */
    public function test_the_reserved_height_actually_covers_the_header(): void
    {
        $css = $this->css();

        preg_match('~--header-h:\s*(\d+)px~', $css, $m);
        $reserved = (int) ($m[1] ?? 0);

        preg_match('~\.topbar \.container\{[^}]*height:\s*(\d+)px~', $css, $t);
        preg_match('~^header \.container\{[^}]*height:\s*(\d+)px~m', $css, $h);

        $real = (int) ($t[1] ?? 0) + (int) ($h[1] ?? 0);

        $this->assertGreaterThan(0, $real, 'ارتفاعِ واقعیِ هدر از CSS خوانده نشد');
        $this->assertGreaterThanOrEqual($real, $reserved,
            "فضای رزروشده ({$reserved}px) از هدرِ واقعی ({$real}px) کمتر است — محتوا زیرش می‌رود");
    }

    // ═══════════════ صفحاتِ جبران‌کننده ═══════════════

    /**
     * ⚠️ فهرستِ بالاکشیده‌ها نباید کهنه شود.
     *
     * اگر کلاسی از فهرست حذف یا تغییرِ نام داده شود، آن صفحه یک‌باره ۱۱۲ پیکسل
     * فاصلهٔ اضافه می‌گیرد — زشت ولی بی‌خطر. برعکسش خطرناک است، پس همین را
     * می‌سنجیم که فهرست با واقعیتِ CSS بخواند.
     */
    public function test_every_pulled_back_wrapper_is_still_registered(): void
    {
        $css = $this->css();

        // ⚠️ `assertTrue` و نه `assertStringContainsString`: دومی در پیامِ خطا
        //    کلِ ۲۵۰ کیلوبایت CSS را چاپ می‌کند و خروجیِ تست بی‌استفاده می‌شود.
        foreach (self::PULLED_BACK as $cls) {
            $this->assertTrue(str_contains($css, '#main > '.$cls),
                "کلاسِ {$cls} از فهرستِ بالاکشیده‌ها افتاده — آن صفحه فاصلهٔ دوبرابر می‌گیرد");
        }

        // `.section` بالا کشیده نمی‌شود؛ padding خودش بازنویسی می‌شود
        $this->assertMatchesRegularExpression(
            '~#main > \.section:first-child\{\s*padding-top:\s*\d+px~',
            $css,
            'صفحاتی که با .section شروع می‌شوند (cloud، cloud-location، solutions، tool) '
            .'باید فاصلهٔ تزئینیِ صریح بگیرند، نه ۱۱۰پیکسلِ تصادفیِ کلاسِ عمومی'
        );
    }

    /**
     * 🔴 هیچ قاعده‌ای نباید سلکتورِ **برهنهٔ** `header` را ثابت کند.
     *
     * `header{position:fixed;top:0;left:0;right:0;z-index:200}` هر تگِ
     * `<header>` را در هر جای سایت از جریان می‌کند و به گوشهٔ بالای صفحه
     * می‌چسباند. سه صفحه `<header>` معنایی داشتند و هر سه خراب بودند — از جمله
     * صفحهٔ کشورِ سرورِ مجازی، که `<header>` داخلِ هر کارتِ پلن دارد: عنوان و
     * «پردازندهٔ اختصاصی/سرور ابری» از کارت بیرون می‌پرید و روی هم در گوشه
     * تلنبار می‌شد. همان «متنِ شناور»ی که کارفرما دو بار گزارش کرد.
     *
     * ⚠️ استفاده از تگِ معنایی `<header>` داخلِ کارت **درست** است؛ چیزی که
     * غلط بود سلکتورِ سراسری بود.
     */
    public function test_no_rule_hijacks_every_header_element(): void
    {
        $css = $this->css();

        // اعلان‌ها را از کامنت‌ها جدا کن، وگرنه توضیحِ خودِ باگ تست را می‌شکند
        $bare = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';

        $this->assertSame(0, preg_match_all('~(?:^|[};])\s*header\s*[.{\s]~m', $bare),
            'سلکتورِ برهنهٔ header برگشته — هر <header> در سایت به گوشهٔ صفحه می‌چسبد');
    }

    /** نوارِ خودِ سایت باید همچنان استایل بگیرد — یعنی به `#header` وصل باشد */
    public function test_the_real_site_header_is_still_styled(): void
    {
        $css = $this->css();

        $this->assertTrue(str_contains($css, '#header{'), 'قاعدهٔ نوارِ سایت گم شد');
        $this->assertTrue(str_contains($css, '#header > .container{'),
            'کانتینرِ نوار باید فرزندِ مستقیم را هدف بگیرد، وگرنه منوی مگا می‌شکند');
        $this->assertTrue(str_contains($css, '#header.scrolled{'), 'حالتِ اسکرول‌شدهٔ نوار گم شد');
    }

    /**
     * 🔴 تستِ رگرسیونِ خودِ آن باگ.
     *
     * صفحهٔ جستجوی دامنه `.dsx{padding:56px …}` داشت در برابرِ هدرِ ۱۱۱ پیکسلی،
     * پس تیتر و بالای باکسِ جستجو زیرِ هدر بود. `.dsx` عمداً در فهرستِ
     * بالاکشیده‌ها **نیست** — یعنی جبران را از `#main` می‌گیرد. پس padding
     * خودش باید کوچک بماند، وگرنه فاصلهٔ دوبرابر می‌شود.
     */
    public function test_the_domain_page_does_not_compensate_twice(): void
    {
        $blade = file_get_contents(resource_path('views/pages/domain-search.blade.php'));

        preg_match('~\.dsx\{[^}]*padding:\s*(\d+)px~', $blade, $m);
        $own = (int) ($m[1] ?? 999);

        $this->assertLessThan(60, $own,
            '.dsx نباید جبرانِ هدر را تکرار کند — آن کارِ #main است');

        $this->assertFalse(str_contains($this->css(), '#main > .dsx'),
            '.dsx نباید در فهرستِ بالاکشیده‌ها باشد، وگرنه دوباره زیرِ هدر می‌رود');
    }

    /**
     * 🔴 نگهبانِ آینده: هیچ صفحه‌ای نباید هم‌زمان جبرانِ دستی داشته باشد و در
     * فهرستِ بالاکشیده‌ها نباشد.
     *
     * این‌جا صفحه‌به‌صفحه اولین بستهٔ در-جریانِ هر ویو را می‌گیریم و می‌سنجیم که
     * جمعِ جبران‌ها دقیقاً یک بار باشد — نه صفر (زیرِ هدر) و نه دو بار (فاصلهٔ
     * زیاد).
     */
    public function test_no_public_page_hides_its_top_under_the_header(): void
    {
        $css = $this->css();
        preg_match('~--header-h:\s*(\d+)px~', $css, $m);
        $headerH = (int) ($m[1] ?? 112);

        $offenders = [];

        foreach (glob(resource_path('views/pages/*.blade.php')) as $file) {
            $page = basename($file, '.blade.php');
            $html = file_get_contents($file);

            // اولین بستهٔ در-جریان. `.bp-progress` که position:fixed است رد می‌شود.
            preg_match_all('~<(?:section|div|article|main)[^>]*class="([^"]*)"~', $html, $all);
            $first = null;

            foreach ($all[1] ?? [] as $classAttr) {
                if (str_contains($classAttr, 'bp-progress')) {
                    continue;                              // خارج از جریان
                }
                $first = $classAttr;
                break;
            }

            if ($first === null) {
                continue;                                  // صفحهٔ بی‌بسته (نادر)
            }

            $classes = preg_split('~\s+~', trim($first)) ?: [];
            $pulled = false;

            foreach ($classes as $c) {
                if ($c !== '' && in_array('.'.$c, self::PULLED_BACK, true)) {
                    $pulled = true;
                    break;
                }
            }

            // صفحهٔ بالاکشیده باید جبرانِ خودش را داشته باشد؛ بقیه نباید داشته باشند
            $ownTop = $this->declaredTopPadding($classes, $css.$html);

            /*
            | ⚠️ استثنای `.section`: بالا کشیده نمی‌شود، ولی padding-topِ عمومیِ
            | ۱۱۰پیکسلی‌اش با یک قاعدهٔ صریح بازنویسی می‌شود. پس مقدارِ مؤثرش
            | همان بازنویسی است، نه عددِ کلاسِ عمومی — وگرنه این تست دربارهٔ
            | چیزی قضاوت می‌کرد که در مرورگر اعمال نمی‌شود.
            */
            if (in_array('section', $classes, true)
                && preg_match('~#main > \.section:first-child\{\s*padding-top:\s*(\d+)px~', $css, $ov)) {
                $ownTop = (int) $ov[1];
            }

            if ($pulled && $ownTop < $headerH) {
                $offenders[] = "$page: بالا کشیده می‌شود ولی جبرانِ خودش ({$ownTop}px) کمتر از هدر است ⇒ زیرِ هدر";
            }

            if (! $pulled && $ownTop >= $headerH) {
                $offenders[] = "$page: هم #main جبران می‌کند هم خودش ({$ownTop}px) ⇒ فاصلهٔ دوبرابر";
            }
        }

        $this->assertSame([], $offenders, "\n".implode("\n", $offenders));
    }

    /** بزرگ‌ترین padding-topِ اعلام‌شده برای این کلاس‌ها (دسکتاپ) */
    private function declaredTopPadding(array $classes, string $haystack): int
    {
        $max = 0;

        foreach ($classes as $c) {
            if ($c === '' || str_contains($c, '{')) {
                continue;                                  // کلاسِ Blade-دار
            }

            // `padding:Npx …` یا `padding-top:Npx`
            $re = '~\.'.preg_quote($c, '~').'\{[^}]*padding(?:-top)?:\s*(\d+)px~';

            if (preg_match_all($re, $haystack, $m)) {
                $max = max($max, ...array_map('intval', $m[1]));
            }
        }

        return $max;
    }
}
