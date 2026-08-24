<?php

namespace Tests\Feature;

use App\Services\Bale\Admin\AdminBaleCommands;
use Tests\TestCase;

/**
 * هر فرمانی که بات قبول می‌کند باید راهِ **کشف** داشته باشد.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 «ثبت هزینه» و «تصحیح نگارش با AI» هر دو ساخته شدند، تست داشتند، سبز
 * بودند، و روی سرور هم نشستند — ولی **نه دکمه‌ای در منوی اصلی داشتند نه خطی
 * در کارتِ راهنما**. یعنی تنها راهِ استفاده‌شان این بود که کارفرما اتفاقی
 * کلمهٔ «هزینه» را تایپ کند.
 *
 * کارفرما گفت «تغییری روی ربات نمی‌بینم» — و درست می‌گفت. قابلیتی که راهِ
 * کشف ندارد، برای کاربر **وجود ندارد**، هرچقدر هم کدش درست باشد.
 *
 * ⚠️ هیچ تستی این را نمی‌گرفت، چون همهٔ تست‌ها فرمان را مستقیم صدا می‌زدند —
 * یعنی دقیقاً همان کاری که کاربرِ واقعی نمی‌تواند بکند. ادعا باید دربارهٔ
 * **کشف‌پذیری** باشد، نه دربارهٔ کارکرد.
 */
class BaleCommandsAreDiscoverableTest extends TestCase
{
    /**
     * فرمان‌های فارسیِ کاربرپسند که کارفرما باید بتواند پیدایشان کند.
     *
     * ⚠️ عمداً فقط فارسی: `/expense` و `/polish` مترادفِ فنی‌اند و کسی در بله
     * دنبالِ اسلش‌فرمانِ انگلیسی نمی‌گردد. معیار این است که یک آدمِ فارسی‌زبان
     * با نگاه به منو و راهنما بفهمد این کار ممکن است.
     *
     * @return array<string, string>  فرمان => توضیحِ کوتاه برای پیامِ خطا
     */
    private function discoverable(): array
    {
        return [
            'تیکت‌ها' => 'صف پشتیبانی',
            'مشتری'   => 'جستجوی مشتری',
            'ایمیل‌ها' => 'صندوق ایمیل',
            'سلامت'   => 'وضعیت سامانه',
            'تماس'    => 'تماس تلفنی',
            'یادداشت' => 'یادداشت داخلی',
            'بستن'    => 'بستن تیکت',
            'پاسخ'    => 'پاسخ به تیکت',
            'هزینه'   => 'ثبت هزینهٔ شرکت',
            'تصحیح'   => 'تصحیح نگارش با هوش مصنوعی',
        ];
    }

    /**
     * 🔴 هستهٔ تست: متنِ راهنما باید هر فرمان را نام ببرد.
     *
     * راهنما تنها جایی است که کارفرما می‌تواند فهرستِ کامل را ببیند؛ منو
     * جا برای همه ندارد.
     */
    public function test_every_command_is_named_in_the_help_card(): void
    {
        $help = app(AdminBaleCommands::class)->panel();

        $this->assertNotSame('', trim($help), 'کارتِ راهنما خالی است — پیش‌فرضِ تست عوض شده');

        foreach ($this->discoverable() as $command => $what) {
            $this->assertStringContainsString(
                $command,
                $help,
                "فرمانِ «{$command}» ({$what}) در راهنما معرفی نشده — کارفرما هیچ‌وقت پیدایش نمی‌کند"
            );
        }
    }

    /**
     * ⚠️ کارهای پرتکرار باید **دکمه** داشته باشند، نه فقط خطی در راهنما.
     *
     * ثبت هزینه کاری است که هفته‌ای چند بار تکرار می‌شود؛ اگر هر بار لازم
     * باشد کلمه‌اش تایپ شود، در عمل استفاده نمی‌شود.
     *
     * ادعا روی خودِ سورسِ منو است تا با هر ویرایشِ آینده هم زنده بمانَد.
     */
    public function test_frequent_actions_have_a_button_in_the_main_menu(): void
    {
        $src = file_get_contents(app_path('Services/Bale/Admin/AdminBaleRouter.php'));

        $menu = $this->menuBody($src);

        foreach (['xp' => 'ثبت هزینه', 'q' => 'تیکت‌ها', 'm' => 'ایمیل‌ها', 'h' => 'سلامت'] as $verb => $label) {
            $this->assertStringContainsString(
                "CB_PREFIX.'".$verb."'",
                $menu,
                "دکمهٔ «{$label}» در منوی اصلی نیست"
            );
        }
    }

    /**
     * ⚠️ دکمه‌ای که فعلِ ناشناخته بفرستد، بی‌صدا هیچ کاری نمی‌کند.
     *
     * کاربر می‌زند، چیزی نمی‌شود، و هیچ خطایی هم در لاگ نیست — بدترین حالت.
     */
    public function test_every_menu_button_maps_to_a_verb_the_router_handles(): void
    {
        $src = file_get_contents(app_path('Services/Bale/Admin/AdminBaleRouter.php'));

        preg_match_all("/CB_PREFIX\.'([a-z?]+)'/", $this->menuBody($src), $m);
        $verbs = array_unique($m[1]);

        $this->assertNotEmpty($verbs, 'هیچ دکمه‌ای پیدا نشد — خودِ تست شکسته است');

        foreach ($verbs as $verb) {
            $this->assertMatchesRegularExpression(
                "/'".preg_quote($verb, '/')."'\s*=>/",
                $src,
                "دکمهٔ منو فعلِ «{$verb}» را می‌فرستد ولی روتر آن را نمی‌شناسد — کلیک بی‌اثر می‌مانَد"
            );
        }
    }

    /** بدنهٔ `menu()` — از خودِ سورس، تا ادعا به ویرایشِ آینده هم بچسبد. */
    private function menuBody(string $src): string
    {
        $start = strpos($src, 'private function menu(): void');
        $this->assertNotFalse($start, 'متدِ menu() پیدا نشد — ساختار عوض شده');

        return substr($src, $start, 1800);
    }
}
