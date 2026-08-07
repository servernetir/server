<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notify\NotifyEvent;
use App\Services\Sms\SignedRelaySender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پنلِ مدیریت باید **راست** بگوید.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * `/admin/templates` از یک نقشهٔ **دستی** می‌خواند که کدام الگو مصرف می‌شود.
 * کامنتِ آن نقشه خودش می‌گفت «دستی نگه داشته می‌شود — هر بار که کلیدی را وصل
 * کردی، این‌جا هم اضافه‌اش کن». همان جمله تضمینِ کهنه‌شدن بود، و شد.
 *
 * دو دروغِ متقارن، و هر دو گران:
 *
 *   «مرده» ولی زنده بود   → مدیر متنِ ناقص را رها می‌کرد و همان متن به هر
 *                            ثبت‌نام و هر فاکتور می‌رفت
 *   «فقط بله» ولی ایمیل هم می‌رفت → مدیر متنی داخلی در ایمیل می‌نوشت با این
 *                            خیال که ارسال نمی‌شود
 *
 * ⚠️ صفحه‌ای که مدیر برای **راستی‌آزمایی** بازش می‌کند، اگر دروغ بگوید از
 * نبودنش بدتر است — چون تصمیمِ اشتباه را با اطمینان می‌گیرد.
 */
class NotifyPanelTruthTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ═══════════════ نقشه دیگر دستی نیست ═══════════════

    /**
     * 🔴 هر رویدادِ مشتری‌محورِ وصل‌شده باید «زنده» گزارش شود.
     *
     * `welcome` و `invoice` سال‌ها «به هیچ کدی وصل نیستند» علامت می‌خوردند در
     * حالی که هر ثبت‌نام و هر سفارش دقیقاً همان متن‌ها را می‌فرستاد.
     */
    public function test_every_wired_customer_event_is_reported_as_live(): void
    {
        $dead = [];

        foreach (NotifyEvent::ALL as $key => $e) {
            if (! $e['wired'] || ! NotifyEvent::notifiesCustomer($key)) {
                continue;
            }

            if (NotificationTemplate::channelsFor($key) === []) {
                $dead[] = $key.' — «'.$e['title'].'» زنده است ولی پنل «مرده» نشانش می‌دهد';
            }
        }

        $this->assertSame([], $dead, "\n".implode("\n", $dead));
    }

    /** و برعکس: رویدادِ وصل‌نشده نباید «زنده» جا بزند */
    public function test_an_unwired_event_is_not_advertised_as_live(): void
    {
        foreach (NotifyEvent::unwired() as $key) {
            $this->assertSame([], NotificationTemplate::channelsFor($key),
                "رویدادِ «{$key}» وصل نیست ولی پنل می‌گوید مصرف می‌شود");
        }
    }

    /**
     * 🔴 ستونِ پیامک باید با فهرستِ واقعیِ الگوها بخواند.
     *
     * صفحه به مدیر می‌گفت «برای عوض‌کردنِ متنِ پیامکِ یادآوری، الگویش را در پنلِ
     * اپراتور ویرایش کن». مدیر می‌رفت، ساعت‌ها روی متن کار می‌کرد، و هیچ‌وقت
     * هیچ پیامکی با آن الگو درخواست نمی‌شد.
     */
    public function test_the_sms_column_matches_the_real_pattern_list(): void
    {
        foreach (array_keys(NotifyEvent::ALL) as $key) {
            $shown = in_array('sms', NotificationTemplate::channelsFor($key), true);
            $real = in_array($key, SignedRelaySender::TEMPLATES, true)
                && NotifyEvent::ALL[$key]['wired']
                && NotifyEvent::notifiesCustomer($key);

            $this->assertSame($real, $shown,
                "ستونِ پیامکِ «{$key}» با واقعیت نمی‌خواند");
        }
    }

    // ═══════════════ کاتالوگ کامل است ═══════════════

    /**
     * ۱۱ پیامِ زندهٔ مشتری هیچ ردیفی در جدول نداشتند.
     *
     * مدیر می‌خواست متنِ «سرویس خاتمه یافت و داده‌ها حذف شد» را نرم‌تر کند،
     * ردیفی پیدا نمی‌کرد و نتیجه می‌گرفت چنین پیامی وجود ندارد — در حالی که
     * همان لحظه متنِ سخت‌کد به مشتری می‌رفت.
     */
    public function test_every_customer_facing_event_has_a_row_in_the_catalogue(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $have = NotificationTemplate::pluck('key')->all();
        $missing = [];

        foreach (NotifyEvent::ALL as $key => $e) {
            if ($e['wired'] && NotifyEvent::notifiesCustomer($key) && ! in_array($key, $have, true)) {
                $missing[] = $key.' — «'.$e['title'].'»';
            }
        }

        $this->assertSame([], $missing,
            "\nاین پیام‌ها به مشتری می‌روند ولی در /admin/templates ردیفی ندارند،\n"
            ."پس مدیر فکر می‌کند وجود ندارند:\n".implode("\n", $missing));
    }

    /** و صفحه واقعاً بالا می‌آید و ستون‌ها را نشان می‌دهد */
    public function test_the_templates_page_renders_with_the_derived_channels(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $html = $this->actingAs($this->admin())->get('/admin/templates')->assertOk()->getContent();

        $this->assertStringContainsString('تأیید پرداخت', $html);
        $this->assertStringContainsString('خاتمهٔ سرویس', $html, 'ردیف‌های تازه در صفحه دیده نمی‌شوند');
        $this->assertStringContainsString('پیامک', $html, 'ستونِ پیامک نمایش داده نمی‌شود');
    }

    /** صفحهٔ ویرایش هم نباید وعدهٔ کانالی بدهد که نمی‌رود */
    public function test_the_edit_page_does_not_promise_free_text_sms(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // `announce` عمداً الگوی پیامک ندارد
        $id = NotificationTemplate::where('key', 'announce')->value('id');
        $html = $this->actingAs($this->admin())->get('/admin/templates/'.$id)->assertOk()->getContent();

        $this->assertStringNotContainsString('به‌عنوان پیام آزاد می‌رود', $html,
            'صفحه هنوز وعدهٔ پیامکِ آزاد می‌دهد — درایورِ فعال آن را نمی‌فرستد');
        $this->assertStringContainsString('پیامکی ارسال نمی‌شود', $html);
    }

    // ═══════════════ صفحات خطا ═══════════════

    /**
     * ⚠️ ۴۲۹ در مسیرِ ورود دیده می‌شود — دقیقاً وقتی کاربر از قبل کلافه است.
     *
     * تا مرداد ۱۴۰۵ صفحهٔ خامِ انگلیسیِ لاراول می‌آمد: بی‌فارسی، بی‌راهِ برگشت.
     */
    public function test_the_rate_limit_page_speaks_persian_and_offers_a_way_back(): void
    {
        foreach (['429', '500', '503'] as $code) {
            $path = resource_path("views/errors/{$code}.blade.php");

            $this->assertFileExists($path, "صفحهٔ خطای {$code} وجود ندارد — کاربر صفحهٔ خامِ انگلیسی می‌بیند");

            $html = (string) file_get_contents($path);

            $this->assertStringContainsString('@extends(\'layouts.site\')', $html,
                "صفحهٔ {$code} از قالبِ سایت استفاده نمی‌کند");
            $this->assertStringContainsString('e404_home', $html,
                "صفحهٔ {$code} راهِ برگشتی به خانه ندارد");
        }
    }
}
