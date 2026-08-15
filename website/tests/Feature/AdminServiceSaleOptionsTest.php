<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فروشِ سرویس از پنلِ مدیر: تاریخِ صدورِ گذشته و درصدِ تخفیف.
 *
 * ⚠️ مهم‌ترین ادعای این فایل ایمنی است، نه قابلیت: **سررسیدِ سرویس با تاریخِ
 * فاکتور عقب نمی‌رود.** اگر برود، زنجیرهٔ کرون بی‌رحم است —
 * `services:renew-due` فاکتورِ تمدید می‌سازد و نیم‌ساعت بعد
 * `services:lifecycle` همان فاکتورِ پرداخت‌نشده را می‌بیند و سرویس را **تعلیق**
 * می‌کند، با پیامکِ «سرویس شما غیرفعال شد» برای مشتری‌ای که تازه خریده.
 *
 * همان تله یک بار در `/admin/cloud/attach` رخ داد و در CLAUDE.md ثبت شد.
 */
class AdminServiceSaleOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 9999).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'), 'status' => 'active',
        ]);
    }

    private function sell(array $over = []): Customer
    {
        $c = $this->customer();

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$c->id.'/services', array_merge([
                'name' => 'هاست حرفه‌ای', 'price' => 1000000,
                'tax_percent' => 10, 'cycle' => 'monthly',
            ], $over))
            ->assertRedirect();

        return $c;
    }

    // ═══════════════ تاریخ صدور ═══════════════

    public function test_the_invoice_can_be_dated_in_the_past(): void
    {
        /*
        | 🔴 این تست روزی ۳٫۵ ساعت قرمز می‌شد و هیچ‌کس ندید — چون فقط بینِ
        | ۲۰:۳۰ و ۲۴:۰۰ به وقتِ UTC می‌شکست.
        |
        | علت: فرم **روزِ شمسیِ تهران** می‌گیرد (`Jalali::ofMoment(..., Tehran)`)
        | ولی ادعا با `toDateString()`ِ **UTC** سنجیده می‌شد. تهران +۳:۳۰ است،
        | پس در آن پنجره روزِ تهران یک روز از روزِ UTC جلوتر است و دو طرفِ
        | مقایسه دربارهٔ دو روزِ متفاوت حرف می‌زدند.
        |
        | ⚠️ باگ در **تست** بود نه در کد: همان قاعدهٔ ثبت‌شدهٔ پروژه که «روزِ
        | شمسی با ساعتِ تهران تعیین می‌شود، نه UTC». پس مقایسه هم باید در همان
        | منطقهٔ زمانی باشد که تبدیل در آن انجام شده.
        |
        | و بهایش از یک قرمزِ گاه‌به‌گاه بیشتر است: در سوئیتی با ۲۰۰۰ تست، قرمزِ
        | تصادفی یاد می‌دهد قرمز را نادیده بگیرند.
        */
        $tz = config('calendar.display_timezone', 'Asia/Tehran');

        $j = \App\Support\Jalali::ofMoment(now()->subDays(3), $tz);
        $this->sell(['issued_jy' => $j[0], 'issued_jm' => $j[1], 'issued_jd' => $j[2]]);

        $inv = Invoice::firstOrFail();

        $this->assertSame(
            now()->subDays(3)->timezone($tz)->toDateString(),
            $inv->issued_at->timezone($tz)->toDateString(),
            'تاریخِ صدورِ گذشته اعمال نشد'
        );
    }

    /** 🔴 مهم‌ترین محافظ: سررسید با تاریخِ فاکتور عقب نمی‌رود */
    public function test_a_backdated_invoice_never_backdates_the_service_due_date(): void
    {
        $j = \App\Support\Jalali::ofMoment(now()->subDays(30), config('calendar.display_timezone', 'Asia/Tehran'));
        $this->sell(['issued_jy' => $j[0], 'issued_jm' => $j[1], 'issued_jd' => $j[2]]);

        $service = Service::firstOrFail();

        $this->assertTrue(
            $service->next_due_at === null || $service->next_due_at->gte(now()->startOfDay()),
            'سررسیدِ سرویس با تاریخِ فاکتور عقب رفت — کرونِ چرخهٔ عمر همان روز تعلیقش می‌کند'
        );
    }

    /** تاریخِ آینده رد می‌شود: سندی که هنوز صادر نشده نباید در دفتر باشد */
    public function test_a_future_issue_date_is_rejected(): void
    {
        $c = $this->customer();
        $fj = \App\Support\Jalali::ofMoment(now()->addDays(2), config('calendar.display_timezone', 'Asia/Tehran'));

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$c->id.'/services', [
                'name' => 'ه', 'price' => 1000, 'cycle' => 'monthly',
                'issued_jy' => $fj[0], 'issued_jm' => $fj[1], 'issued_jd' => $fj[2],
            ])
            ->assertSessionHasErrors('issued_jd');

        $this->assertSame(0, Invoice::count());
    }

    // ═══════════════ تخفیف ═══════════════

    public function test_a_percentage_discount_lowers_the_price(): void
    {
        $this->sell(['price' => 1000000, 'discount_pct' => 20]);

        $this->assertSame(800000, (int) Service::firstOrFail()->price);
        $this->assertSame(800000, (int) Invoice::firstOrFail()->subtotal);
    }

    /**
     * 🔴 تخفیف باید در **تمدید** هم بمانَد.
     *
     * اگر فقط از فاکتورِ اول کم شود، مشتری دورهٔ دوم بی‌خبر قیمتِ کامل
     * می‌گیرد — چیزی که هیچ‌جا به او اعلام نکرده‌ایم.
     */
    public function test_the_discount_survives_into_renewals(): void
    {
        $this->sell(['price' => 1000000, 'discount_pct' => 25]);

        $service = Service::firstOrFail();

        // فاکتورِ دورهٔ بعد از همین قیمتِ ذخیره‌شده ساخته می‌شود
        app(\App\Http\Controllers\Admin\ServiceController::class)->issueInvoice($service);

        $this->assertSame(750000, (int) Invoice::latest('id')->first()->subtotal,
            'تمدید به قیمتِ کامل فاکتور شد — تخفیف فقط روی دورهٔ اول نشسته بود');
    }

    /** مالیات روی مبلغِ **بعد از** تخفیف حساب شود، نه قبلش */
    public function test_tax_is_computed_on_the_discounted_amount(): void
    {
        $this->sell(['price' => 1000000, 'tax_percent' => 10, 'discount_pct' => 20]);

        $inv = Invoice::firstOrFail();

        $this->assertSame(800000, (int) $inv->subtotal);
        $this->assertSame(80000, (int) $inv->tax, 'مالیات روی قیمتِ پیش از تخفیف حساب شد');
    }

    /** بی‌تخفیف، رفتار دقیقاً مثلِ قبل بماند */
    public function test_no_discount_leaves_the_price_untouched(): void
    {
        $this->sell(['price' => 1000000]);

        $this->assertSame(1000000, (int) Service::firstOrFail()->price);
    }
}
