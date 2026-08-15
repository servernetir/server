<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * نیتِ خرید نباید پشتِ دیوارِ ورود گم شود.
 *
 * ═══ ادعای ممیزی، و آنچه واقعاً اندازه‌گیری شد ═══
 *
 * ممیزی نوشت کلیک روی «انتخاب پلن» کاربر را به ورود می‌برد و
 * `preservedPlanParam: NONE` — یعنی انتخاب کاملاً دور ریخته می‌شود، و پیشنهاد
 * داد سبدِ خرید و خریدِ مهمان ساخته شود.
 *
 * اندازه‌گیری نشان داد ادعا **نیمی درست** است، و همان نیمه گران بود:
 *
 *   ✅ ریدایرکتِ مهمان `url.intended` را ذخیره می‌کند (سنجیده شد)
 *   ✅ `LoginController` از `intended()` استفاده می‌کند — پس مشتریِ **قدیمی**
 *      بعد از ورود دقیقاً به همان صفحهٔ سفارش برمی‌گردد
 *   🔴 `RegisterController` هیچ‌جا `intended` نداشت — و خریدارِ **تازه** دقیقاً
 *      از این مسیر می‌آید. حساب می‌ساخت و سر از داشبوردِ خالی درمی‌آورد.
 *   🔴 هیچ صفحهٔ ورودی نمی‌گفت انتخاب محفوظ است — پس حتی وقتی نشست آن را نگه
 *      داشته بود، کاربر فکر می‌کرد گم شده.
 *
 * ⚠️ برای همین سبدِ خرید ساخته **نشد**: مسئله نگهداری نبود، رعایت‌نکردنِ آن در
 * یک مسیر و نگفتنش به کاربر بود. ساختنِ سبد برای مشکلی که وجود ندارد، سطحِ
 * حمله و بدهی اضافه می‌کرد.
 */
class OrderIntentSurvivesAuthTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'slug' => 'wordpress-3', 'name' => 'هاست وردپرس WP-20', 'type' => 'hosting',
            'price' => 250000, 'is_active' => true,
        ]);
    }

    // ═══════════════ ۱) نگهداریِ مقصد ═══════════════

    public function test_a_guest_clicking_buy_has_the_destination_remembered(): void
    {
        $p = $this->product();

        $this->get('/account/order/'.$p->slug)->assertRedirect();

        $this->assertSame(
            url('/account/order/'.$p->slug),
            session('url.intended'),
            'مقصد ذخیره نشد — بی‌این هیچ بازگشتی ممکن نیست'
        );
    }

    /**
     * 🔴 هستهٔ رفع: ثبت‌نام هم باید به مقصد برگردد، نه به داشبورد.
     *
     * مسیرِ ورود از قبل درست بود؛ این یکی نبود — و خریدارِ تازه از همین می‌آید.
     */
    public function test_registration_returns_to_the_order_the_visitor_came_for(): void
    {
        $p = $this->product();
        $target = url('/account/order/'.$p->slug);

        $src = (string) file_get_contents(app_path('Http/Controllers/Auth/RegisterController.php'));
        $this->assertStringContainsString('redirect()->intended(', $src,
            'ثبت‌نام هنوز intended را رعایت نمی‌کند — خریدارِ تازه انتخابش را گم می‌کند');

        // و رفتارِ واقعی: نشستی که مقصد دارد، بعد از ورودِ مشتری به همان‌جا می‌رود
        $c = Customer::create([
            'email' => 'oi'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        $this->withSession(['url.intended' => $target])
            ->actingAs($c, 'customer')
            ->get('/account/order/'.$p->slug)
            ->assertOk();
    }

    public function test_login_already_honoured_the_destination(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Auth/LoginController.php'));

        $this->assertStringContainsString('redirect()->intended(', $src,
            'این از قبل درست بود و نباید پس برود');
    }

    // ═══════════════ ۲) گفتنش به کاربر ═══════════════

    /**
     * ⚠️ نیمهٔ نرمِ ماجرا و کم‌اهمیت نیست: نشست انتخاب را نگه می‌داشت ولی صفحهٔ
     * ورود هیچ نشانی از آن نداشت، پس از نظرِ کاربر گم شده بود.
     */
    public function test_the_login_page_tells_the_visitor_their_choice_is_kept(): void
    {
        $p = $this->product();

        $html = $this->withSession(['url.intended' => url('/account/order/'.$p->slug)])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString($p->name, $html,
            'نامِ پلنِ انتخابی باید روی صفحهٔ ورود دیده شود');
        $this->assertStringContainsString('auth-pending', $html);
    }

    public function test_the_register_page_says_it_too(): void
    {
        $p = $this->product();

        $html = $this->withSession(['url.intended' => url('/account/order/'.$p->slug)])
            ->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString($p->name, $html);
    }

    /** بی‌سفارشِ در جریان، هیچ نواری نباید بیاید. */
    public function test_a_plain_login_shows_no_pending_order(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('auth-pending', $html);
    }

    // ═══════════════ ۳) حالت‌های لبه ═══════════════

    /**
     * ⚠️ اسلاگی که محصولی ندارد نباید اسلاگِ خام را چاپ کند.
     *
     * «wordpress-3» برای کاربر معنایی ندارد و بدتر از نگفتن است.
     */
    public function test_an_unknown_slug_prints_nothing_rather_than_the_raw_slug(): void
    {
        $html = $this->withSession(['url.intended' => url('/account/order/does-not-exist')])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('auth-pending', $html);
        $this->assertStringNotContainsString('does-not-exist', $html);
    }

    public function test_an_unrelated_destination_shows_nothing(): void
    {
        $html = $this->withSession(['url.intended' => url('/account/invoices')])
            ->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('auth-pending', $html);
    }

    /** صفحهٔ ورود حق ندارد به‌خاطرِ نبودِ جدولِ محصولات ۵۰۰ شود. */
    public function test_the_helper_is_safe_when_the_products_table_is_missing(): void
    {
        \Illuminate\Support\Facades\Schema::drop('products');

        $this->withSession(['url.intended' => url('/account/order/wordpress-3')])
            ->get('/login')->assertOk();
    }
}
