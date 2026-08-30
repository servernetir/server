<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «چرا سرورت را حذف کردی؟» — پرسشِ اختیاری، دادهٔ **شمردنی**.
 *
 * ═══ چرا این تست‌ها ═══
 *
 * دو راهِ خراب‌شدن دارد و هر دو ماه‌ها بی‌صدا می‌مانند:
 *
 *   ۱) پرسش **مانع** شود → مشتریِ ناراضی به دیوار می‌خورد، حذف انجام نمی‌شود،
 *      و به‌جای دادهٔ بازاریابی یک تیکتِ عصبانی می‌گیریم
 *   ۲) فقط **متنِ آزاد** ذخیره شود → شش ماه بعد کارفرما انبوهی جملهٔ دست‌نویس
 *      دارد که نمی‌شود شمرد، در حالی که کلِ هدف «چند نفر بابتِ قیمت رفتند» بود
 *
 * پس هر دو صریح سنجیده می‌شوند، و همین‌طور اینکه برچسبِ فارسی **یک** جا زندگی
 * کند: نقشهٔ مدل و فایلِ زبانِ فارسی نباید از هم جدا شوند، وگرنه گزارشِ مدیر با
 * چیزی که مشتری روی صفحه دیده بود نمی‌خواند.
 */
class ServiceDeleteReasonTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    public array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender
        {
            public function __construct(private ServiceDeleteReasonTest $t) {}

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake'; }

            public function send(string $m, string $text): bool { return true; }

            public function sendOtp(string $m, string $code): bool
            {
                $this->t->codes[$m] = $code;

                return true;
            }
        });
    }

    private function sentCode(): string
    {
        $this->assertNotEmpty($this->codes, 'هیچ کدی فرستاده نشد');

        return (string) end($this->codes);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'r'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function activeService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی زنده', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
        ], $over));
    }

    /** حذفِ کامل با پستِ دلخواه (کد خودکار برداشته می‌شود) */
    private function deleteWith(Customer $c, Service $s, array $payload = []): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        return $this->actingAs($c, 'customer')->post(
            "/account/services/{$s->id}/terminate",
            $payload + ['code' => $this->sentCode()],
        );
    }

    // ═══════════════ 🔴 اختیاری یعنی اختیاری ═══════════════

    public function test_deleting_works_with_no_reason_at_all(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s)->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status,
            '🔴 حذف بدونِ دلیل انجام نشد — پرسشِ بازاریابی به دیوار تبدیل شده');
        $this->assertNull($fresh->terminate_reason);
        $this->assertNull($fresh->terminate_reason_note);
    }

    /** فرستادنِ رشتهٔ خالی (همان چیزی که گزینهٔ «ترجیح می‌دهم نگویم» می‌فرستد) */
    public function test_an_empty_reason_is_treated_as_no_answer(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, ['reason' => '', 'reason_note' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status);
        $this->assertNull($fresh->terminate_reason);
        $this->assertNull($fresh->terminate_reason_note);
    }

    // ═══════════════ 🔴 کدِ پایدار، جدا از متنِ آزاد ═══════════════

    public function test_the_reason_code_is_stored_and_the_free_text_kept_separately(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, [
            'reason'      => 'too_expensive',
            'reason_note' => 'ماهی ۵۰۰ تومن برام زیاد بود',
        ])->assertRedirect();

        $fresh = $s->fresh();

        $this->assertSame('too_expensive', $fresh->terminate_reason,
            'کدِ پایدار باید در ستونِ خودش بنشیند — بی‌آن، آمار قابلِ شمارش نیست');
        $this->assertSame('ماهی ۵۰۰ تومن برام زیاد بود', $fresh->terminate_reason_note,
            'متنِ آزاد باید ستونِ جدا داشته باشد، نه چسبیده به کد');
    }

    /** فقط توضیحِ آزاد، بی‌انتخابِ گزینه — هنوز باید ذخیره شود */
    public function test_free_text_alone_is_accepted(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, ['reason_note' => 'فقط خواستم تست کنم'])->assertRedirect();

        $fresh = $s->fresh();
        $this->assertNull($fresh->terminate_reason);
        $this->assertSame('فقط خواستم تست کنم', $fresh->terminate_reason_note);
    }

    /**
     * 🔴 کدِ خارج از فهرست رد می‌شود.
     *
     * با `string`ِ آزاد، هر مقداری از مرورگر به ستون می‌رسید و گزارشِ مدیر پر
     * از کدهای بی‌معنی می‌شد — یعنی همان «دادهٔ غیرقابلِ شمارش» که این ستون برای
     * رفعش ساخته شد، از درِ دیگر برمی‌گشت.
     */
    public function test_a_reason_code_outside_the_list_is_rejected(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, ['reason' => 'competitor_is_better'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $s->fresh()->status,
            'ورودیِ نامعتبر نباید حذف کند — و نباید سرور را هم پاک کرده باشد');
    }

    /** توضیحِ بلندتر از سقف رد می‌شود و سرویس دست‌نخورده می‌مانَد */
    public function test_an_over_long_note_is_rejected(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, ['reason_note' => str_repeat('ا', 501)])
            ->assertSessionHasErrors('reason_note');

        $this->assertSame('active', $s->fresh()->status);
    }

    /** دلیل باید در تاریخچهٔ همان سرویس هم دیده شود، نه فقط در آمارِ کلی */
    public function test_the_reason_lands_in_the_service_history(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->deleteWith($c, $s, ['reason' => 'switched_provider']);

        $log = \App\Models\ActivityLog::where('service_id', $s->id)
            ->where('action', 'terminate')->latest('id')->first();

        $this->assertNotNull($log, 'رویدادِ حذف در لاگِ سرویس ثبت نشد');
        $this->assertStringContainsString('به سرویس دیگری منتقل شدم', (string) $log->description);
    }

    // ═══════════════ برچسب‌ها فقط یک جا زندگی می‌کنند ═══════════════

    /**
     * 🔴 مقدارِ فارسیِ هر کلیدِ زبان باید **دقیقاً** برابرِ نقشهٔ مدل باشد.
     *
     * پنلِ مشتری سه‌زبانه است پس از فایلِ زبان می‌خوانَد، و پنلِ مدیریت فارسیِ
     * تک‌زبانه است پس از مدل. اگر این دو واگرا شوند، کارفرما در گزارش برچسبی
     * می‌بیند که مشتری هرگز روی صفحه ندیده — و هیچ خطایی هم در کار نیست.
     */
    public function test_the_persian_labels_are_the_same_in_the_model_and_the_language_file(): void
    {
        $fa = (array) require lang_path('fa/ui.php');

        foreach (Service::TERMINATE_REASONS as $code => $label) {
            $key = 'svc_del_reason_'.$code;

            $this->assertArrayHasKey($key, $fa, "کلیدِ «{$key}» در فایلِ فارسی نیست");
            $this->assertSame($label, $fa[$key],
                "برچسبِ «{$code}» در مدل و فایلِ زبان یکی نیست — گزارشِ مدیر با فرمِ مشتری نمی‌خواند");
        }
    }

    /** ⚠️ کلیدِ جاافتاده در یکی از سه زبان یعنی مشتری کلیدِ خام می‌بیند */
    public function test_every_reason_key_exists_in_all_three_languages(): void
    {
        $keys = ['svc_del_reason_h', 'svc_del_reason_lead', 'svc_del_reason_skip', 'svc_del_reason_note'];

        foreach (Service::terminateReasonCodes() as $code) {
            $keys[] = 'svc_del_reason_'.$code;
        }

        foreach (['fa', 'en', 'tr'] as $locale) {
            $strings = (array) require lang_path($locale.'/ui.php');

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings, "کلیدِ «{$key}» در {$locale} نیست");
                $this->assertNotSame('', trim((string) $strings[$key]), "«{$key}» در {$locale} خالی است");
            }
        }
    }

    /** و هر سه فایل باید هم‌کلید بمانند — افزودن به یکی و فراموشیِ دو تای دیگر */
    public function test_the_three_language_files_stay_key_identical(): void
    {
        $fa = array_keys((array) require lang_path('fa/ui.php'));
        $en = array_keys((array) require lang_path('en/ui.php'));
        $tr = array_keys((array) require lang_path('tr/ui.php'));

        $this->assertSame([], array_diff($fa, $en), 'کلیدهایی که در en نیستند');
        $this->assertSame([], array_diff($fa, $tr), 'کلیدهایی که در tr نیستند');
        $this->assertSame([], array_diff($en, $fa));
        $this->assertSame([], array_diff($tr, $fa));
    }

    // ═══════════════ فرم و گزارش ═══════════════

    /** فرمِ تأیید باید متنِ دقیقِ کارفرما را نشان دهد، نه کلیدِ خام */
    public function test_the_confirm_step_shows_the_reason_picker(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('دلیل حذف سرور را انتخاب کنید', $html);
        $this->assertStringContainsString('برای بهبود سرویس و بررسی بازخورد کاربران', $html);
        $this->assertStringContainsString('در صورت تمایل، توضیح بیشتری بنویسید.', $html);
        $this->assertStringNotContainsString('ui.svc_del_reason', $html, 'کلیدِ خام نباید چاپ شود');

        // هر ۹ گزینه با **کدِ پایدار** در فرم باشند، به همان ترتیب
        foreach (Service::TERMINATE_REASONS as $code => $label) {
            $this->assertStringContainsString('value="'.$code.'"', $html, "گزینهٔ «{$code}» در فرم نیست");
            $this->assertStringContainsString($label, $html, "برچسبِ «{$label}» در فرم نیست");
        }
    }

    /** پیش از گرفتنِ کد، پرسش نباید دیده شود (فرمِ حذف اصلاً باز نیست) */
    public function test_the_picker_is_hidden_until_a_code_was_requested(): void
    {
        $c = $this->customer();
        $this->activeService($c);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('دلیل حذف سرور را انتخاب کنید', $html);
    }

    /**
     * 🔴 گزارش باید جایی باشد که مدیر واقعاً می‌بیند.
     *
     * صفحه‌ای که باز نمی‌شود، گزارشی است که وجود ندارد — پس این آمار روی
     * داشبوردِ مالی نشسته، نه در یک صفحهٔ تازه.
     */
    public function test_the_finance_page_shows_the_churn_breakdown(): void
    {
        $c = $this->customer();

        $this->activeService($c, ['status' => 'terminated', 'terminate_reason' => 'too_expensive', 'cancelled_at' => now()]);
        $this->activeService($c, ['status' => 'terminated', 'terminate_reason' => 'too_expensive', 'cancelled_at' => now()]);
        $this->activeService($c, ['status' => 'terminated', 'terminate_reason' => 'support', 'cancelled_at' => now()]);
        // بی‌پاسخ — باید صریح شمرده شود نه اینکه از نمودار غیب شود
        $this->activeService($c, ['status' => 'terminated', 'cancelled_at' => now()]);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/finance')->assertOk()->getContent();

        $this->assertStringContainsString('چرا مشتری‌ها سرورشان را حذف می‌کنند', $html);
        $this->assertStringContainsString('هزینه سرویس مناسب نبود', $html);
        $this->assertStringContainsString('از پشتیبانی رضایت نداشتم', $html);
        $this->assertStringContainsString('بی‌پاسخ', $html, 'نبودِ پاسخ هم یک عدد است و باید دیده شود');

        // ۲ از ۳ پاسخ = ۶۷٪ — درصد نسبت به **پاسخ‌ها** است نه کلِ حذف‌ها
        $this->assertStringContainsString(fa_num(67).'٪', $html);
    }

    /** توضیحِ آزادِ مشتری هم باید به چشمِ مدیر برسد، نه فقط عددها */
    public function test_the_finance_page_shows_recent_free_text(): void
    {
        $c = $this->customer();

        $this->activeService($c, [
            'status' => 'terminated', 'cancelled_at' => now(),
            'terminate_reason' => 'technical_issue',
            'terminate_reason_note' => 'دیسک هر هفته پر می‌شد',
        ]);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/finance')->assertOk()->getContent();

        $this->assertStringContainsString('دیسک هر هفته پر می‌شد', $html);
    }

    /** و صفحهٔ مالی وقتی هیچ حذفی نبوده هم سالم بالا می‌آید */
    public function test_the_finance_page_survives_with_no_terminations(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/finance')->assertOk();
    }
}
