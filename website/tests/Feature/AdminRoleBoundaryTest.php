<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 مرزِ نقشِ `author` در پنلِ مدیریت.
 *
 * ═══ ارتقای دسترسی‌ای که این تست برای بازنگشتنش نوشته شد ═══
 *
 * `EnsureAdmin` ساخته شده بود تا نویسندهٔ محتوا به بخش‌های حساس نرسد، ولی
 * باید روی **تک‌تکِ** روت‌ها نوشته می‌شد — و سه بار جا افتاد. نتیجه‌اش این بود
 * که یک حسابِ `author` (کم‌ارزش‌ترین حسابِ سامانه، برای بلاگ‌نویس) می‌توانست:
 *
 *   • اسکنِ کارتِ ملی و اساسنامهٔ **هر** مشتری را دانلود کند
 *   • رمزِ **هر** مشتری را عوض کند ⇒ ورود به پنلش ⇒ رمزِ root سرورهای ابری،
 *     اطلاعاتِ ورودِ cPanel، فاکتورها، توکنِ API
 *   • کاربرِ تازه بسازد
 *   • به همهٔ مشتریان پیامکِ انبوه بفرستد
 *
 * حالا پیش‌فرضِ گروه `admin` است و فهرستِ سفید صریح. این تست هر دو نیمه را
 * قفل می‌کند: نویسنده به حساس‌ها **نرسد**، و به محتوا **برسد** — چون قفلی که
 * کارِ روزمره را بخواباند، فردا برداشته می‌شود.
 */
class AdminRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function author(): User
    {
        return User::factory()->create(['role' => 'author']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'b'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    // ═══════════════ آنچه نویسنده نباید ببیند ═══════════════

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function sensitiveRoutes(): array
    {
        return [
            'فهرستِ مشتریان'      => ['get',  '/admin/customers'],
            'مدارکِ احراز هویت'   => ['get',  '/admin/verifications'],
            'ساختِ کاربر'          => ['get',  '/admin/users'],
            'پیامکِ انبوه'         => ['get',  '/admin/broadcasts'],
            'ردیابِ خطا'           => ['get',  '/admin/errors'],
            'تیکت‌ها'              => ['get',  '/admin/tickets'],
            'مالی'                 => ['get',  '/admin/finance'],
            'تراکنش‌ها'            => ['get',  '/admin/transactions'],
            // 🔴 تنها جایی که دسترسیِ مدیر به یک چتِ بله **داده** می‌شود.
            // نویسنده‌ای که به تیکت‌ها راه ندارد، به‌طریقِ اولی نباید بتواند
            // کدِ اتصال بسازد و کلِ کنسول را برای خودش باز کند.
            // ⚠️ صفحهٔ اختصاصی به تبِ تنظیمات منتقل شد؛ `/admin/bale` حالا فقط
            // ریدایرکت است، پس ادعا روی خانهٔ تازه‌اش بسته می‌شود.
            'کنسولِ بله'           => ['get',  '/admin/settings?tab=bale'],
        ];
    }

    public function test_an_author_cannot_reach_any_sensitive_admin_page(): void
    {
        $author = $this->author();
        $leaked = [];

        foreach (self::sensitiveRoutes() as $label => [$verb, $url]) {
            if ($this->actingAs($author, 'web')->{$verb}($url)->getStatusCode() !== 403) {
                $leaked[] = $label.' → '.$url;
            }
        }

        $this->assertSame([], $leaked, "\nنویسنده به این‌ها می‌رسد:\n".implode("\n", $leaked));
    }

    /** 🔴 تصاحبِ حساب: نویسنده نباید بتواند رمزِ مشتری را عوض کند */
    public function test_an_author_cannot_set_a_customer_password(): void
    {
        $c = $this->customer();

        $this->actingAs($this->author(), 'web')
            ->post('/admin/customers/'.$c->id.'/password', ['password' => 'NewPass!234'])
            ->assertForbidden();
    }

    /** 🔴 دادهٔ هویتی: نویسنده نباید بتواند مدرکِ مشتری را دانلود کند */
    public function test_an_author_cannot_download_a_kyc_document(): void
    {
        $c = $this->customer();

        $profile = CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'pending', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'first_name' => 'الف', 'last_name' => 'ب',
        ]);

        /*
        | ⚠️ ۴۰۳ **یا** ۴۰۴ هر دو قبول است، و این تسامح نیست.
        |
        | `SubstituteBindings` در گروهِ `web` پیش از میدل‌ورِ روت می‌دود، پس
        | مدرکِ ناموجود اول ۴۰۴ می‌دهد. آنچه واقعاً باید تضمین شود این است که
        | **فایلی تحویل نشود** — و هر دو کد همین را می‌گویند. سنجشِ سخت‌گیرانهٔ
        | ۴۰۳ این تست را به ترتیبِ میدل‌ورهای لاراول گره می‌زد، نه به رفتاری
        | که برایمان مهم است.
        */
        $status = $this->actingAs($this->author(), 'web')
            ->get('/admin/verifications/'.$profile->id.'/doc/1')
            ->getStatusCode();

        $this->assertContains($status, [403, 404],
            'نویسنده مدرکِ هویتیِ مشتری را گرفت — کدِ '.$status);
    }

    // ═══════════════ آنچه نویسنده باید ببیند ═══════════════

    /**
     * ⚠️ نیمهٔ دومِ قاعده، و به‌اندازهٔ نیمهٔ اول مهم.
     *
     * قفلی که کارِ روزمرهٔ نویسنده را بخواباند، فردا با یک «موقتاً برش دار»
     * برداشته می‌شود و دیگر برنمی‌گردد. پس فهرستِ سفید باید واقعاً کار کند.
     */
    public function test_an_author_can_still_do_content_work(): void
    {
        $author = $this->author();

        foreach (['/admin', '/admin/posts', '/admin/posts/new', '/admin/comments'] as $url) {
            $this->actingAs($author, 'web')->get($url)->assertOk();
        }
    }

    /** مدیر همچنان همه‌جا می‌رود — وگرنه فقط پنل را خراب کرده‌ایم */
    public function test_an_admin_still_reaches_everything(): void
    {
        $admin = $this->admin();

        foreach (array_values(self::sensitiveRoutes()) as [$verb, $url]) {
            $this->actingAs($admin, 'web')->{$verb}($url)->assertSuccessful();
        }
    }

    /** مهمان هرگز — نه ۴۰۳، بلکه هدایت به ورود */
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/admin/customers')->assertRedirect();
    }
}
