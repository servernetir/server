<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اعمالِ کوپنِ هدیه روی فاکتور.
 *
 * 🔴 کوپن روی ریلِ **پرداخت** می‌نشیند، نه روی قیمت. پس ادعای مرکزیِ این
 * فایل این است که `subtotal`/`tax`/`total` بعد از اعمال **دست‌نخورده**
 * می‌مانند و فقط `paid` بالا می‌رود. اگر روزی کسی این را به «تخفیف روی
 * قیمت» تغییر دهد، پایهٔ ارزش‌افزوده هم کم می‌شود — عددی که اظهارنامه‌اش
 * جای دیگری می‌رود.
 */
class GiftCouponOnInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 9999999).'@t.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function invoice(Customer $c, int $total = 2_000_000): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(100000, 999999),
            'kind' => 'service', 'status' => 'unpaid', 'currency_code' => 'IRT',
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'paid' => 0,
            'issued_at' => now(),
        ]);
    }

    private function coupon(Customer $c, array $over = []): GiftCoupon
    {
        /*
        | 🔴 `$over + [...]`، نه `[...] + $over`.
        |
        | در عملگرِ `+` آرایهٔ PHP کلیدِ **سمتِ چپ** برنده است. نسخهٔ اول
        | پیش‌فرض‌ها را چپ گذاشته بود، پس `expires_at` و `min_invoice`ِ هر
        | تست بی‌صدا نادیده گرفته می‌شد: تستِ «کدِ منقضی» یک کوپنِ **سالم**
        | می‌ساخت و بعد تعجب می‌کرد که رد نشده. سه تست قرمز شدند و علتشان
        | نه در کنترلر بود نه در کوپن — در ترتیبِ دو عملوند.
        */
        return GiftCoupon::create($over + [
            'customer_id' => $c->id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 500_000, 'min_invoice' => 0,
            'reason' => 'birthday', 'expires_at' => now()->addHours(24),
        ]);
    }

    private function apply(Customer $c, Invoice $inv, string $code)
    {
        return $this->actingAs($c, 'customer')
            ->post("/account/invoices/{$inv->id}/coupon", ['code' => $code]);
    }

    // ───────────────────────── مسیرِ موفق ─────────────────────────

    public function test_the_coupon_reduces_what_is_owed_without_touching_the_tax_base(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);
        $coupon = $this->coupon($c);

        $this->apply($c, $inv, $coupon->code)->assertRedirect();

        $fresh = $inv->fresh();

        $this->assertSame(2_000_000, (int) $fresh->subtotal, 'پایهٔ مالیات نباید دست بخورد');
        $this->assertSame(2_000_000, (int) $fresh->total, 'مبلغِ فاکتور نباید دست بخورد');
        $this->assertSame(500_000, (int) $fresh->paid, 'کوپن باید به‌عنوان پرداخت بنشیند');
        $this->assertSame(1_500_000, $fresh->due());
    }

    public function test_the_coupon_is_marked_used_and_bound_to_that_invoice(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);
        $coupon = $this->coupon($c);

        $this->apply($c, $inv, $coupon->code);

        $fresh = $coupon->fresh();

        $this->assertNotNull($fresh->used_at);
        $this->assertSame($inv->id, (int) $fresh->used_invoice_id);
    }

    /** ارقامِ فارسی و حروفِ کوچک هم باید کار کنند */
    public function test_a_persian_typed_code_is_accepted(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);
        $coupon = $this->coupon($c);

        $fa = strtr(strtolower($coupon->code), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);

        $this->apply($c, $inv, ' '.$fa.' ');

        $this->assertNotNull($coupon->fresh()->used_at);
    }

    // ───────────────────────── چه چیزی رد می‌شود ─────────────────────────

    /**
     * 🔴 مهم‌ترین گاردِ امنیتیِ این مسیر.
     *
     * کدِ کسِ دیگری باید **پیدا نشود**، نه اینکه پیدا شود و رد شود. پیامِ
     * «این کد مالِ شما نیست» تأیید می‌کند که کد معتبر است، و آن‌وقت
     * حدس‌زدنِ کدها ارزش پیدا می‌کند.
     */
    public function test_someone_elses_code_never_works(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $inv = $this->invoice($mine);
        $coupon = $this->coupon($theirs);

        $this->apply($mine, $inv, $coupon->code)->assertSessionHasErrors();

        $this->assertNull($coupon->fresh()->used_at, 'کوپنِ مشتریِ دیگر نباید مصرف شود');
        $this->assertSame(0, (int) $inv->fresh()->paid);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);
        $coupon = $this->coupon($c, ['expires_at' => now()->subMinute()]);

        $this->apply($c, $inv, $coupon->code)->assertSessionHasErrors();

        $this->assertSame(0, (int) $inv->fresh()->paid);
    }

    public function test_a_used_code_is_refused(): void
    {
        $c = $this->customer();
        $first = $this->invoice($c);
        $second = $this->invoice($c);
        $coupon = $this->coupon($c);

        $this->apply($c, $first, $coupon->code);
        $this->apply($c, $second, $coupon->code)->assertSessionHasErrors();

        $this->assertSame(500_000, (int) $first->fresh()->paid);
        $this->assertSame(0, (int) $second->fresh()->paid, 'یک کوپن نباید دو فاکتور را ببندد');
    }

    /**
     * 🔴 خطِ قرمزِ «هرگز زیر بها نفروش».
     *
     * کوپنِ ۵۰۰ هزاری روی فاکتورِ ۶۰۰ هزاری یعنی ۱۰۰ هزار می‌گیریم برای چیزی
     * که چند صد هزار خرج دارد — و چون تحویل موفق می‌شود، هیچ خطایی هیچ‌جا
     * ثبت نمی‌شود.
     */
    public function test_a_small_invoice_is_below_the_floor(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 600_000);
        $coupon = $this->coupon($c, ['min_invoice' => 1_500_000]);

        $this->apply($c, $inv, $coupon->code)->assertSessionHasErrors();

        $this->assertSame(0, (int) $inv->fresh()->paid);
        $this->assertNull($coupon->fresh()->used_at, 'کوپنِ ردشده نباید سوخته حساب شود');
    }

    /**
     * ⚠️ کفِ فاکتور از **خودِ کوپن** خوانده می‌شود، نه از تنظیمات.
     *
     * وگرنه تغییرِ بعدیِ تنظیمات شرطِ کوپنی را عوض می‌کرد که مشتری از قبل در
     * دست دارد — و او شرطی می‌دید که موقعِ صدور اعلام نشده بود.
     */
    public function test_the_floor_comes_from_the_coupon_not_todays_setting(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 1_000_000);

        // کوپن با کفِ سهل صادر شده …
        $coupon = $this->coupon($c, ['min_invoice' => 0]);

        // … و بعد کارفرما کف را بالا برده
        \App\Models\Setting::put('birthday_min_invoice', '5000000');

        $this->apply($c, $inv, $coupon->code)->assertRedirect();

        $this->assertSame(500_000, (int) $inv->fresh()->paid,
            'شرطِ کوپنِ صادرشده نباید با تغییرِ تنظیمات عوض شود');
    }

    /** مبلغِ کوپن اگر از مانده بیشتر باشد بریده می‌شود — موجودیِ منفی معنی ندارد */
    public function test_the_amount_is_capped_at_what_is_owed(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c, 300_000);
        $coupon = $this->coupon($c, ['amount' => 500_000, 'min_invoice' => 0]);

        $this->apply($c, $inv, $coupon->code)->assertRedirect();

        $fresh = $inv->fresh();

        $this->assertSame(300_000, (int) $fresh->paid);
        $this->assertSame(0, $fresh->due());
        $this->assertSame('paid', $fresh->status);
    }

    public function test_a_topup_invoice_cannot_use_a_coupon(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);
        $inv->update(['kind' => 'topup']);
        $coupon = $this->coupon($c);

        $this->apply($c, $inv, $coupon->code);

        $this->assertNull($coupon->fresh()->used_at,
            'وگرنه کوپن به اعتبارِ نقدِ بی‌انقضا تبدیل می‌شد و کلِ پنجرهٔ ۲۴ ساعته بی‌معنا');
    }
}
