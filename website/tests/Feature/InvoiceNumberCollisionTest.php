<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * برخوردِ شمارهٔ فاکتور نباید تسویهٔ مشتری را بشکند.
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * شمارهٔ فاکتور `INV-<تاریخ>-<۴ رقمِ تصادفی>` است و ستونش `unique`. توضیحِ خودِ
 * متد سال‌ها می‌گفت «برخورد با تلاش دوباره حل می‌شود، چون ستون unique است» —
 * جمله‌ای که دو چیزِ متفاوت را یکی گرفته بود: `unique` برخورد را **می‌گیرد**،
 * حلش نمی‌کند. هیچ تلاشِ دوباره‌ای در کد نبود، پس برخورد یعنی استثنا از دلِ
 * `DB::transaction` بیرون می‌زد، همه‌چیز برمی‌گشت، و مشتری وسطِ خرید یک صفحهٔ
 * خطا می‌دید — بی‌سرویس، بی‌فاکتور.
 *
 * و این باگ با رشدِ کسب‌وکار **حتمی‌تر** می‌شود نه کمیاب‌تر: چهار رقم یعنی
 * ۱۰٬۰۰۰ شمارهٔ ممکن در روز، پس با ۱۰۰ فاکتور در روز احتمالِ حداقل یک برخورد
 * حدودِ ۳۹٪ است و با ۲۰۰ تا حدودِ ۸۶٪.
 */
class InvoiceNumberCollisionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'inv'.random_int(1000, 9999).'@example.test',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'),
            'status'   => 'active',
        ]);
    }

    private function invoice(array $over = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id'   => $this->customer()->id,
            'currency_code' => 'IRT',
            'subtotal'      => 100000,
            'tax'           => 0,
            'total'         => 100000,
            'status'        => 'unpaid',
            'issued_at'     => now(),
        ], $over));
    }

    /**
     * قلبِ ماجرا: شماره‌ای که **از قبل گرفته شده** نباید خرید را بشکند.
     *
     * برخوردِ واقعی تحمیل می‌شود (نه شبیه‌سازی): اول یک فاکتور با شمارهٔ صریح
     * ساخته می‌شود، بعد `now()` قفل می‌شود و مولدِ تصادفی طوری دانه می‌گیرد که
     * همان شماره دوباره تولید شود.
     */
    public function test_a_taken_number_does_not_break_the_purchase(): void
    {
        $this->travelTo(now()->startOfDay());

        $taken = Invoice::nextNumber();
        $this->invoice(['number' => $taken]);

        /*
        | 🔴 دانهٔ ثابت یعنی فاکتورِ بعدی **حتماً** همان شماره را اول تولید
        | می‌کند. بی‌این، تست به شانسِ ۱ در ۱۰٬۰۰۰ بند بود — یعنی عملاً هرگز
        | باگ را نمی‌گرفت و فقط ظاهرِ محافظ را داشت.
        */
        mt_srand(1);
        $seeded = str_pad((string) (mt_rand(0, 9999)), 4, '0', STR_PAD_LEFT);
        $this->invoice(['number' => 'INV-'.now()->format('ymd').'-'.$seeded]);

        $before = Invoice::count();

        // این یکی باید با وجودِ دو شمارهٔ گرفته‌شده ساخته شود
        $fresh = $this->invoice();

        $this->assertSame($before + 1, Invoice::count(), 'فاکتورِ تازه ساخته نشد');
        $this->assertNotSame($taken, $fresh->number);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', (string) $fresh->number);
    }

    /**
     * فشارِ واقعی: ۴۰۰ فاکتور در یک روز، همه باید ساخته شوند.
     *
     * با ۱۰٬۰۰۰ شماره و ۴۰۰ فاکتور، احتمالِ حداقل یک برخورد ~۹۹٫۹۹٪ است — یعنی
     * پیش از این رفع، این تست عملاً همیشه قرمز می‌شد. حالا باید همیشه سبز باشد.
     */
    public function test_four_hundred_invoices_in_one_day_all_get_a_number(): void
    {
        $this->travelTo(now()->startOfDay());

        $customerId = $this->customer()->id;

        for ($i = 0; $i < 400; $i++) {
            Invoice::create([
                'customer_id'   => $customerId,
                'currency_code' => 'IRT',
                'subtotal'      => 1000,
                'tax'           => 0,
                'total'         => 1000,
                'status'        => 'unpaid',
                'issued_at'     => now(),
            ]);
        }

        $this->assertSame(400, Invoice::count());
        $this->assertSame(400, Invoice::distinct()->count('number'), 'شمارهٔ تکراری ثبت شد');
    }

    /**
     * ⚠️ نیمهٔ دومِ قاعده — بی‌این، حلقهٔ تلاشِ دوباره یک **پنهان‌کنندهٔ باگ**
     * است: هر نقضِ یکتاییِ دیگری هم شش بار بی‌صدا تکرار و بعد بلعیده می‌شد.
     */
    public function test_a_different_unique_violation_is_not_swallowed(): void
    {
        $customer = $this->customer();

        $this->expectException(UniqueConstraintViolationException::class);

        // `code` مشتری هم unique است — این نقض باید بالا برود، نه اینکه
        // به‌عنوان «برخوردِ شماره» تفسیر و شش بار تکرار شود
        Customer::create([
            'code'     => $customer->code,
            'email'    => 'other'.random_int(1000, 9999).'@example.test',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'),
            'status'   => 'active',
        ]);
    }
}
