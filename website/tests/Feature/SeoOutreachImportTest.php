<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SeoOutreachController;
use App\Models\OutreachContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خواندنِ فهرستِ هدف از متنِ **چسبانده‌شده** — «/admin/seo».
 *
 * چرا این تست جدا از `AuditReportShareTest` است: آن‌جا دربارهٔ ارسال و لغوِ
 * اشتراک است، این‌جا فقط دربارهٔ **تشخیص**. و تشخیص جایی است که خرابی‌اش
 * خاموش‌ترین است: پارسر هیچ‌وقت خطا نمی‌دهد، فقط سطر را دور می‌ریزد یا — بدتر —
 * سطر را به سایتِ **اشتباه** می‌چسباند و گزارشِ یک نفر برای یک نفرِ دیگر می‌رود.
 */
class SeoOutreachImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0:\Illuminate\Testing\TestResponse,1:\Illuminate\Support\Collection} */
    private function import(string $list): array
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/admin/seo/list', ['list' => $list])
            ->assertOk();

        return [$res, OutreachContact::orderBy('id')->get()];
    }

    // ═══════════════ قالبِ قبلی نباید بشکند ═══════════════

    /**
     * فایلِ CSVی که تا امروز تحویل داده‌ایم دقیقاً همین شکل است. اگر پارسرِ تازه
     * این را نخواند، بازنویسی یک پسرفت است نه بهبود.
     */
    public function test_the_original_two_column_format_still_works(): void
    {
        [$res, $rows] = $this->import(implode("\n", [
            'example.com, info@example.com',
            'another.ir; hello@another.ir',
            "third.co\tsales@third.co",
        ]));

        $res->assertJsonPath('added', 3);
        $this->assertSame(
            ['example.com', 'another.ir', 'third.co'],
            $rows->pluck('host')->all()
        );
    }

    public function test_a_url_with_scheme_www_and_path_becomes_a_bare_host(): void
    {
        [, $rows] = $this->import('https://www.Example.com/fa/contact?x=1, info@example.com');

        $this->assertSame('example.com', $rows->first()->host);
    }

    // ═══════════════ ورودیِ کمینه: فقط ایمیل ═══════════════

    /**
     * 🔴 قلبِ این تغییر. نشانیِ واقعیِ `info@ariansanat.com` را **خودشان** منتشر
     * کرده‌اند، پس دامنه‌اش قطعاً سایتِ همان کسب‌وکار است. این حدس‌زدنِ ایمیل
     * نیست، عکسِ آن است — و بی‌آن، رسیدن به ۲۵۰ ردیف یعنی ۲۵۰ بار ویرایشِ دستی.
     */
    public function test_a_bare_email_column_derives_each_site_from_its_own_domain(): void
    {
        [$res, $rows] = $this->import("info@ariansanat.com\nsales@bearingiran.ir");

        $res->assertJsonPath('added', 2);
        $this->assertSame(['ariansanat.com', 'bearingiran.ir'], $rows->pluck('host')->all());
    }

    /**
     * 🔴 و مرزش: نشانیِ رایگان دربارهٔ سایت **هیچ** نمی‌گوید. ساختنِ
     * `gmail.com` به‌عنوان سایت یعنی بررسیِ سایتِ گوگل و فرستادنش برای یک
     * کارخانهٔ ایرانی.
     */
    public function test_a_free_mail_address_alone_never_becomes_a_site(): void
    {
        [$res, $rows] = $this->import("someone@gmail.com\nother@yahoo.com");

        $res->assertJsonPath('added', 0);
        $this->assertCount(0, $rows);
        $this->assertSame(
            ['nosite', 'nosite'],
            collect($res->json('skipped'))->pluck('why')->all()
        );
    }

    // ═══════════════ رکوردِ چندخطی ═══════════════

    /** چیدمانِ واقعیِ فهرست‌های شرکتی: چند خط، جداشده با خطِ خالی. */
    public function test_a_multi_line_record_pairs_the_site_with_the_email_in_the_same_block(): void
    {
        [$res, $rows] = $this->import(<<<'TXT'
        شرکت آریان صنعت
        تلفن: 021-88123456
        www.ariansanat.com
        info@gmail.com

        صنایع مارال
        maralsanat.com
        maralsanatmoein99@gmail.com
        TXT);

        $res->assertJsonPath('added', 2);
        $this->assertSame(['ariansanat.com', 'maralsanat.com'], $rows->pluck('host')->all());
    }

    /** ترتیب نباید مهم باشد — بعضی فهرست‌ها اول ایمیل می‌نویسند بعد سایت. */
    public function test_the_email_may_come_before_the_site_inside_a_record(): void
    {
        [, $rows] = $this->import("شرکت نمونه\nbuyer@gmail.com\nnamuneh.com");

        $this->assertSame('namuneh.com', $rows->first()?->host);
    }

    /**
     * 🔴 خطرناک‌ترین حالتِ ممکنِ این صفحه.
     *
     * یک فهرستِ بدونِ خطِ خالی «یک رکورد» نیست. اگر رکوردش بخوانیم، سایتِ ردیفِ
     * اول به تک‌تکِ ایمیل‌های رایگانِ بعدی می‌چسبد و ده‌ها نفر گزارشِ سایتِ یک
     * نفرِ دیگر را می‌گیرند — با کدِ ۲۰۰ و بی‌هیچ خطایی.
     */
    public function test_one_site_never_sticks_to_a_long_run_of_unrelated_free_mail_addresses(): void
    {
        $lines = ['firstcompany.com'];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = "person{$i}@gmail.com";
        }

        [$res, $rows] = $this->import(implode("\n", $lines));

        $this->assertSame(0, $res->json('added'), 'هیچ‌کدام نباید به سایتِ ردیفِ اول بچسبد');
        $this->assertCount(0, $rows->where('host', 'firstcompany.com'));
        $this->assertCount(20, collect($res->json('skipped'))->where('why', 'nosite'));
    }

    /**
     * 🔴 و همان خرابی در اندازهٔ **کوچک** — تستِ بالا به‌تنهایی گولمان می‌زد.
     *
     * اولین پیاده‌سازی «رکورد بودن» را از تعدادِ **خط** می‌فهمید، پس این فهرستِ
     * سه‌ردیفه زیرِ سقف می‌مانْد و `first.com` به `lost@gmail.com` می‌چسبید.
     * تشخیصِ درست تعدادِ **ایمیل** است: بلوکی با چند نشانی، چند شرکت است هرچقدر
     * هم کوتاه باشد.
     */
    public function test_a_short_list_is_not_mistaken_for_one_company_record(): void
    {
        [$res, $rows] = $this->import(implode("\n", [
            'first.com, info@first.com',
            'second.ir, info@second.ir',
            'lost@gmail.com',
        ]));

        $this->assertSame(2, $res->json('added'));
        $this->assertSame(['first.com', 'second.ir'], $rows->pluck('host')->all());
        $this->assertSame(['nosite'], collect($res->json('skipped'))->pluck('why')->all());
    }

    // ═══════════════ چیزهایی که نباید سایت خوانده شوند ═══════════════

    public function test_a_file_name_in_the_pasted_text_is_not_treated_as_a_site(): void
    {
        [$res, $rows] = $this->import("logo.png\nbrochure.pdf\nindex.html\nsomeone@gmail.com");

        $this->assertSame(0, $res->json('added'));
        $this->assertCount(0, $rows);
    }

    /** 🔴 ایمیل هرگز ساخته نمی‌شود: سایتِ بی‌ایمیل هیچ ردیفی تولید نمی‌کند. */
    public function test_a_site_with_no_email_anywhere_adds_nothing(): void
    {
        [$res, $rows] = $this->import("example.com\nanother.ir\nwww.third.co");

        $this->assertSame(0, $res->json('added'));
        $this->assertCount(0, $rows);
        $this->assertSame([], $res->json('skipped'));
    }

    /**
     * دامنهٔ داخلِ خودِ نشانی نباید یک بار دیگر به‌عنوانِ «سایتِ همان خط» شمرده
     * شود؛ وگرنه هر خطِ فقط‌ایمیل ظاهراً سایت هم دارد و منطقِ پشتیبان بی‌اثر
     * می‌شود.
     */
    public function test_the_domain_inside_an_address_is_not_double_counted_as_a_site(): void
    {
        [, $rows] = $this->import("acme.com\nbuyer@gmail.com");

        $this->assertSame('acme.com', $rows->first()?->host);
        $this->assertCount(1, $rows);
    }

    // ═══════════════ گزارشِ ردشده‌ها ═══════════════

    public function test_duplicates_inside_one_paste_are_collapsed(): void
    {
        [$res, $rows] = $this->import("x.com, info@x.com\nx.com, info@x.com\ninfo@x.com");

        $this->assertSame(1, $res->json('added'));
        $this->assertCount(1, $rows);
    }

    /**
     * 🔴 دلیلِ ردشدن باید **ساختاری** برگردد. تا امروز فقط تعداد چاپ می‌شد و
     * مدیر نمی‌فهمید چرا از ۲۵۰ سطر ۱۶۰ تا وارد شده.
     */
    public function test_every_skipped_row_comes_back_with_a_machine_readable_reason(): void
    {
        OutreachContact::create(['host' => 'dup.com', 'email' => 'a@dup.com', 'batch' => 'x']);
        OutreachContact::create(['host' => 'u.com', 'email' => 'gone@u.com', 'batch' => 'x']);
        OutreachContact::suppress('gone@u.com');   // ← روی **نشانی**، نه روی ردیف

        $res = $this->actingAs($this->admin())->postJson('/admin/seo/list', ['list' => implode("\n", [
            'dup.com, a@dup.com',
            'u.com, gone@u.com',
            'lost@gmail.com',
            'fresh.com, hi@fresh.com',
        ])])->assertOk();

        $res->assertJsonPath('added', 1);
        $this->assertSame(
            ['dup', 'nosite', 'unsub'],
            collect($res->json('skipped'))->pluck('why')->sort()->values()->all()
        );
    }

    /** رسیدن به سقف باید **گفته** شود، نه اینکه بی‌صدا بریده شود. */
    public function test_hitting_the_cap_is_reported_rather_than_silently_truncated(): void
    {
        $lines = [];
        for ($i = 1; $i <= SeoOutreachController::MAX_LIST + 5; $i++) {
            $lines[] = "info@site{$i}.com";
        }

        $res = $this->actingAs($this->admin())
            ->postJson('/admin/seo/list', ['list' => implode("\n", $lines)])
            ->assertOk();

        $res->assertJsonPath('over', true)
            ->assertJsonPath('added', SeoOutreachController::MAX_LIST)
            ->assertJsonPath('max', SeoOutreachController::MAX_LIST);
    }

    /**
     * سقف باید از نرخِ واقعیِ شکست بزرگ‌تر باشد. حدودِ یک‌پنجمِ سایت‌های یک
     * فهرستِ واقعی بالا نمی‌آیند، پس ۲۰۰ **هدفِ قابلِ‌استفاده** حدودِ ۲۵۰ ردیفِ
     * ورودی می‌خواهد — سقفِ ۲۰۰ دقیقاً همان‌جا می‌بُرید.
     */
    public function test_the_cap_leaves_room_for_the_sites_that_will_not_load(): void
    {
        $this->assertGreaterThanOrEqual(250, SeoOutreachController::MAX_LIST);
    }

    // ═══════════════ صفحه ═══════════════

    /**
     * ⚠️ «کدِ ۲۰۰ یعنی هیچ» (§۸ پروژه). ظرفِ گزارشِ ردشده‌ها اگر در HTML نباشد،
     * `showSkips()` بی‌صدا `return` می‌کند و مدیر هرگز نمی‌فهمد چرا سطرها افتاده‌اند
     * — دقیقاً همان سکوتی که این تغییر برای رفعش نوشته شد.
     */
    public function test_the_page_carries_the_container_the_skip_report_writes_into(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/seo')->assertOk()->getContent();

        $this->assertStringContainsString('id="sx-import-detail"', $html);
        $this->assertStringContainsString('admin-seo.js', $html);
    }

    /**
     * ⚠️ تلهٔ ثبت‌شدهٔ پروژه: کلاسِ CSSِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود.
     * سه کلاسی که فقط جاوااسکریپت می‌سازدشان، هیچ‌وقت در Blade دیده نمی‌شوند و
     * یک grep دستی پیدایشان نمی‌کند.
     */
    public function test_the_classes_the_skip_report_builds_actually_exist_in_the_stylesheet(): void
    {
        $css = (string) file_get_contents(base_path('public/assets/css/admin.css'));

        foreach (['.sx-skips', '.sx-skip-g', '.sx-skip-l'] as $class) {
            $this->assertStringContainsString($class, $css, $class.' در admin.css تعریف نشده');
        }
    }
}
