<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Services\Domain\DomainTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * انتقالِ دامنه — و مهم‌تر از خودِ انتقال، چیزهایی که **نباید** اتفاق بیفتد.
 *
 * ادعاها روی رفتارِ پولی و دادهٔ حساس‌اند، نه روی «صفحه ۲۰۰ داد».
 */
class DomainTransferTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'public_id' => 'SN-'.random_int(100000, 999999),
            'email'     => 'transfer'.random_int(1, 99999).'@example.test',
            'phone'     => '0912'.random_int(1000000, 9999999),
            'password'  => bcrypt('x'),
            'status'    => 'active',
        ]);
    }

    private function transferRow(Customer $c, array $extra = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id'      => $c->id,
            'domain'           => 'moved.com',
            'sld'              => 'moved',
            'tld'              => 'com',
            'registrar'        => 'openprovider',
            'order_type'       => 'transfer',
            'status'           => Domain::STATUS_TRANSFERRING,
            'transfer_status'  => 'pending',
            'provision_status' => 'pending',
            'period_years'     => 1,
            'price_toman'      => 1_500_000,
        ], $extra));
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل.
     *
     * صفِ ثبت هر ردیفِ `pending` را برمی‌دارد و `registerDomain()` می‌زند. یک
     * ردیفِ انتقال که به آن صف بیفتد یعنی تلاش برای **خریدنِ نامی که مالِ شخصِ
     * دیگری است** — رجیسترار ردش می‌کند، ردیف `failed` می‌شود، و مشتری پیامِ
     * «ثبت ناموفق» می‌گیرد برای کاری که اصلاً ثبت نبود.
     *
     * ⚠️ همان خانوادهٔ باگی که CLAUDE.md برای صفِ تمدید ثبت کرده است.
     */
    public function test_a_transfer_never_enters_the_registration_queue(): void
    {
        $c = $this->customer();
        $this->transferRow($c);

        // دقیقاً همان اسکوپی که کرونِ `domains:provision` مصرف می‌کند
        $queued = Domain::query()->awaitingRegistration()->pluck('domain')->all();

        $this->assertSame([], $queued,
            'ردیفِ انتقال در صفِ ثبت افتاد — یعنی دامنهٔ شخصِ دیگری «خریداری» می‌شود.');
    }

    /** و برعکس: ردیفِ ثبتِ عادی نباید در صفِ انتقال بیفتد */
    public function test_a_registration_never_enters_the_transfer_queue(): void
    {
        $c = $this->customer();

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'fresh.com', 'sld' => 'fresh', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'pending', 'period_years' => 1,
        ]);

        $this->assertSame([], Domain::query()->awaitingTransferSubmit()->pluck('domain')->all());
        $this->assertSame([], Domain::query()->awaitingTransferResult(0)->pluck('domain')->all());
    }

    /**
     * 🔴 کدِ انتقال هیچ‌جا نوشته نمی‌شود.
     *
     * این کد کلیدِ مالکیتِ دامنه است. اگر در ستون، در `meta`، یا در پیامِ خطا
     * بنشیند، در هر بکاپ و هر دامپِ عیب‌یابی هم می‌نشیند — و از آن‌جا هرکسی
     * می‌تواند دامنه را از خودِ ما هم ببرد.
     *
     * تست عمداً کلِ ردیف را به‌صورت متن می‌سنجد، نه ستون‌های نام‌برده: ستونِ
     * تازه‌ای که فردا اضافه شود هم باید در همین دام بیفتد.
     */
    public function test_the_auth_code_is_never_persisted_anywhere_on_the_row(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*'             => Http::response(['code' => 0, 'data' => ['id' => 42]], 200),
        ]);

        $c = $this->customer();
        $d = $this->transferRow($c);

        $secret = 'SUPER-SECRET-EPP-9Z8Y7X';

        // بدونِ اعتبارنامه، سرویس پیش از هر تماسی رد می‌کند — همان مسیر هم
        // نباید کد را جایی بنویسد.
        app(DomainTransfer::class)->submit($d, $secret);

        $row = json_encode(Domain::query()->whereKey($d->id)->first()?->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($secret, (string) $row,
            'کدِ انتقال روی ردیفِ دامنه ذخیره شد — کلیدِ مالکیت نباید در دیتابیس بنشیند.');
    }

    /**
     * دامنهٔ آزاد قابلِ انتقال نیست و **پیش از فاکتور** رد می‌شود.
     *
     * گیتی که بعد از گرفتنِ پول بنشیند فقط جای شکست را عوض می‌کند.
     */
    public function test_an_available_domain_is_rejected_before_any_invoice(): void
    {
        // ⚠️ کارخانهٔ نو: یک `Http::fake()`ِ همه‌گیرِ قبلی هر استابِ بعدی را
        //    بی‌اثر می‌کند — همان تلهٔ ثبت‌شده در CLAUDE.md §۸.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response([
                'code' => 0, 'data' => ['results' => [['status' => 'free']]],
            ], 200),
        ]);

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');

        $gate = app(DomainTransfer::class)->eligibility('totally-free-name', 'com');

        $this->assertFalse($gate['ok']);
        $this->assertSame('not_registered', $gate['reason']);
    }

    /**
     * 🔴 شکستِ استعلام هرگز «آزاد است» خوانده نمی‌شود.
     *
     * توکنِ منقضی و قطعیِ گذرا هر دو پاسخِ خالی می‌دهند. اگر «آزاد» بخوانیمشان،
     * انتقالِ کاملاً معتبرِ مشتری رد می‌شود و به او می‌گوییم دامنه‌اش مالِ کسی
     * نیست — همان تلهٔ ثبت‌شدهٔ `CloudInventory`.
     */
    public function test_a_failed_lookup_is_never_read_as_available(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains*'    => Http::response(['code' => 196, 'desc' => 'auth failed'], 500),
        ]);

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');

        $gate = app(DomainTransfer::class)->eligibility('some-name', 'com');

        $this->assertFalse($gate['ok']);
        $this->assertSame('lookup_failed', $gate['reason'],
            'استعلامِ شکست‌خورده «دامنه ثبت نشده» خوانده شد.');
    }

    /** پسوندی که نمی‌فروشیم، منتقل هم نمی‌شود */
    public function test_an_unsold_tld_cannot_be_transferred(): void
    {
        $gate = app(DomainTransfer::class)->eligibility('example', 'ir');

        $this->assertFalse($gate['ok']);
        $this->assertSame('tld_not_sold', $gate['reason']);
    }

    /**
     * انتقالِ ردشده پول را برمی‌گرداند.
     *
     * ⚠️ ادعا روی **جمعِ دفترِ اعتبار** است نه روی وجودِ یک ردیف: موجودی در این
     * پروژه جمعِ سطرهاست و تستی که فقط `count()` بسنجد، مبلغِ غلط را نمی‌بیند.
     */
    public function test_a_rejected_transfer_refunds_the_customer(): void
    {
        $c = $this->customer();
        $d = $this->transferRow($c, ['transfer_status' => 'submitted']);

        app(DomainTransfer::class)->reject($d, 'رجیسترارِ فعلی رد کرد.');

        $balance = (int) \App\Models\CreditEntry::where('customer_id', $c->id)
            ->where('currency_code', 'IRT')->sum('amount');

        $this->assertSame(1_500_000, $balance, 'مبلغِ انتقالِ ردشده به اعتبار برنگشت.');
        $this->assertSame('rejected', $d->fresh()?->transfer_status);
        $this->assertSame('cancelled', $d->fresh()?->status);
    }

    /**
     * کرونِ پیگیری روی نصبِ مهاجرت‌نخورده **سبز** برمی‌گردد.
     *
     * ⚠️ اگر بترکد، کلِ `schedule:run` آن دقیقه می‌میرد و تحویلِ سرور و فاکتورِ
     * تمدید هم با آن می‌ایستد — همان درسِ ثبت‌شدهٔ `domains:reseller-tiers`.
     */
    public function test_the_poll_command_survives_a_missing_column(): void
    {
        // ⚠️ ایندکس اول برداشته می‌شود؛ SQLite ستونِ ایندکس‌دار را نمی‌گذارد برود
        \Illuminate\Support\Facades\Schema::table('domains', function ($t) {
            $t->dropIndex(['transfer_status', 'transfer_checked_at']);
            $t->dropColumn('transfer_status');
        });

        $this->artisan('domains:transfer-poll')->assertSuccessful();
    }
}
