<?php

namespace Tests\Feature;

use App\Console\Commands\ResolveStuckDomains;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * هیچ دامنه‌ای برای همیشه در صفِ دستی نمی‌مانَد.
 *
 * ═══ قاعده‌ای که کارفرما گذاشت ═══
 *
 * «نمی‌خوام هیچ کاری در صفِ ثبتِ دستی بمونه — یا کنسل بشه پولش به مشتری
 * برگرده، یا ثبت بشه.»
 *
 * تا امروز `provision_status='manual'` یک حالتِ **پایانی** بود: پول گرفته شده،
 * دامنه ثبت نشده، و منتظرِ کاری از یک آدم که ممکن است هرگز نیاید. مشتری نه
 * دامنه داشت نه پولش را، و از دستِ خودش هم کاری برنمی‌آمد.
 *
 * حالا `manual` گذراست، با دو خروجی و مهلتِ مشخص.
 *
 * ⚠️ این تنها کدِ پروژه است که **خودکار پول برمی‌گرداند**. هر ادعای این فایل
 * یک محافظِ مالی است، نه یک تستِ رفتاری.
 */
class DomainStuckResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // هیچ تماسِ واقعی‌ای در این مسیر نباید برود
        Http::fake(['*' => Http::response([], 500)]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'stk'.random_int(1000, 9999).'@example.test',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'),
            'status'   => 'active',
        ]);
    }

    private function profile(Customer $c, bool $complete): CustomerProfile
    {
        return CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'جعفر', 'last_name' => 'ابراهیمی',
            'email' => $c->email, 'country' => 'IR',
        ] + ($complete ? [
            'address' => 'خیابان آزادی', 'city' => 'تهران',
            'postal_code' => '1234567890', 'mobile' => '09121234567',
        ] : []));
    }

    /** دامنهٔ پارک‌شده + فاکتورِ پرداخت‌شده */
    private function parked(Customer $c, int $hoursAgo, int $paid = 209000): Domain
    {
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'zhina.shop',
            'sld' => 'zhina', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_tries' => 3, 'period_years' => 1, 'price_toman' => 190000,
            'provision_error' => 'مشخصاتِ مالک ناقص است (نام، نشانی، شهر، کدپستی، تلفن و ایمیل لازم است).',
        ]);

        Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 190000, 'tax' => 19000,
            'total' => $paid, 'paid' => $paid, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(),
        ]);

        // ⚠️ `updated_at` بعد از ساختِ فاکتور ست می‌شود، وگرنه ذخیرهٔ بعدی
        //    عقربه را به «الان» برمی‌گرداند و سنِ پارک‌شدن صفر می‌شود.
        $d->forceFill(['updated_at' => now()->subHours($hoursAgo)])->saveQuietly();

        return $d->refresh();
    }

    // ═══════════════ خروجی ۱: آزاد شدن ═══════════════

    public function test_a_domain_whose_blocker_is_gone_returns_to_the_queue(): void
    {
        $c = $this->customer();
        $d = $this->parked($c, 48);

        // مانع برطرف می‌شود (کاربر مشخصاتش را کامل کرد)
        $p = $this->profile($c, complete: false);
        $p->forceFill([
            'address' => 'خیابان آزادی', 'city' => 'تهران',
            'postal_code' => '1234567890', 'mobile' => '09121234567',
        ])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $d->refresh();
        $this->assertSame('pending', $d->provision_status, 'مانع برطرف شده بود ولی دامنه آزاد نشد');
        $this->assertSame(0, (int) $d->provision_tries);
        $this->assertSame(0, CreditEntry::count(), 'دامنهٔ قابلِ نجات نباید لغو و برگشت بخورد');
    }

    // ═══════════════ خروجی ۲: لغو و بازگشتِ پول ═══════════════

    public function test_a_domain_past_the_grace_window_is_cancelled_and_refunded(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $d = $this->parked($c, 48, paid: 209000);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $d->refresh();
        $this->assertSame('cancelled', $d->status);
        $this->assertSame('none', $d->provision_status);

        $entry = CreditEntry::where('reason', ResolveStuckDomains::REFUND_REASON)->first();
        $this->assertNotNull($entry, 'دامنه لغو شد ولی پولِ مشتری برنگشت');
        $this->assertSame(209000, (int) $entry->amount, 'مبلغِ برگشتی با پرداختِ واقعی نمی‌خوانَد');

        $this->assertSame('refunded', Invoice::first()->status);
    }

    /**
     * 🔴 مبلغ از **فاکتورِ پرداخت‌شده** می‌آید، نه از `price_toman`ِ دامنه.
     *
     * آن ستون قیمتِ لحظهٔ سفارش است و مالیات ندارد؛ برگرداندنش یعنی مشتری کمتر
     * از آنچه داده پس می‌گیرد و اختلافش در هیچ دفتری دیده نمی‌شود.
     */
    public function test_the_refund_matches_what_was_actually_paid_not_the_list_price(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $d = $this->parked($c, 48, paid: 209000);

        $this->assertSame(190000, (int) $d->price_toman);   // قیمتِ بی‌مالیات

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(
            209000,
            (int) CreditEntry::where('reason', ResolveStuckDomains::REFUND_REASON)->value('amount'),
            'مبلغ از price_toman خوانده شد نه از فاکتور — مشتری کمتر از پرداختش پس گرفت'
        );
    }

    /**
     * ⚠️ مهم‌ترین محافظِ مالیِ این فایل.
     *
     * فرمان ساعتی می‌دود. بی‌این محافظ، یک دامنهٔ لغوشده هر ساعت یک برگشتِ تازه
     * می‌خورد و اعتبارِ مشتری تا ابد بالا می‌رفت — با پولِ ما.
     */
    public function test_running_twice_never_refunds_twice(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $this->parked($c, 48);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();
        $this->artisan('domains:resolve-stuck')->assertSuccessful();
        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(1, CreditEntry::where('reason', ResolveStuckDomains::REFUND_REASON)->count(),
            'اجرای دوباره پولِ تکراری برگرداند');
    }

    // ═══════════════ مهلت ═══════════════

    public function test_a_freshly_parked_domain_is_left_alone(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $d = $this->parked($c, 2);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $d->refresh();
        $this->assertSame('manual', $d->provision_status, 'دامنهٔ تازه‌پارک‌شده نباید لغو شود');
        $this->assertSame('pending', $d->status);
        $this->assertSame(0, CreditEntry::count());
    }

    /** مهلت باید از پیکربندی بیاید، نه سخت‌کد */
    public function test_the_grace_window_is_configurable(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $d = $this->parked($c, 2);

        $this->artisan('domains:resolve-stuck', ['--hours' => 1])->assertSuccessful();

        $this->assertSame('cancelled', $d->refresh()->status);
    }

    // ═══════════════ ایمنی ═══════════════

    /** `--dry-run` نباید هیچ‌چیز را عوض کند — روی کدی که پول برمی‌گرداند حیاتی است */
    public function test_dry_run_changes_nothing_at_all(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $d = $this->parked($c, 48);

        $this->artisan('domains:resolve-stuck', ['--dry-run' => true])->assertSuccessful();

        $d->refresh();
        $this->assertSame('manual', $d->provision_status);
        $this->assertSame('pending', $d->status);
        $this->assertSame(0, CreditEntry::count());
    }

    /**
     * ⚠️ دامنهٔ **فعال** هرگز نباید لمس شود. اگر روزی شرطِ `status` از پرس‌وجو
     * بیفتد، این فرمان دامنهٔ ثبت‌شدهٔ مشتری را لغو و پولش را برمی‌گرداند —
     * فاجعه‌ای که با یک خط تغییر ممکن است.
     */
    public function test_an_active_domain_is_never_touched(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'live.shop',
            'sld' => 'live', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'active', 'provision_status' => 'manual',
            'period_years' => 1, 'price_toman' => 190000,
        ]);
        $d->forceFill(['updated_at' => now()->subHours(200)])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame('active', $d->refresh()->status);
        $this->assertSame(0, CreditEntry::count());
    }

    /** فاکتور حذف نمی‌شود — سابقهٔ مالی و مالیاتی باید بمانَد */
    public function test_the_invoice_is_marked_refunded_not_deleted(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);
        $this->parked($c, 48);

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame(1, Invoice::count(), 'فاکتور حذف شد — سابقهٔ مالی از بین رفت');
        $this->assertSame('refunded', Invoice::first()->status);
    }

    /** دامنهٔ بی‌فاکتورِ پرداخت‌شده لغو می‌شود ولی هیچ پولی ساخته نمی‌شود */
    public function test_an_unpaid_domain_is_cancelled_without_inventing_money(): void
    {
        $c = $this->customer();
        $this->profile($c, complete: false);

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'unpaid.shop',
            'sld' => 'unpaid', 'tld' => 'shop', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'period_years' => 1, 'price_toman' => 190000,
        ]);
        $d->forceFill(['updated_at' => now()->subHours(48)])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame('cancelled', $d->refresh()->status);
        $this->assertSame(0, CreditEntry::count(), 'برای فاکتورِ پرداخت‌نشده پول ساخته شد');
    }
}
