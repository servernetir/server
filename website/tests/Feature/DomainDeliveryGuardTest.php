<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * محافظ‌های تحویلِ دامنه — همه از یک بازبینیِ خصمانه بیرون آمدند.
 *
 * 🔴 مهم‌ترینشان: فرمانِ `domains:provision` ساخته شده بود، `PaymentService`
 * پرچمش را می‌زد، کامنتش هم می‌گفت کرون کار را می‌کند — ولی آن کرون **هرگز
 * ثبت نشده بود**. مشتری پول می‌داد، فاکتور «پرداخت‌شده» می‌شد، و دامنه تا ابد
 * در صف می‌مانْد: بی‌خطا، بی‌لاگ، با کدِ ۲۰۰. هیچ تستی هم زمان‌بندی را
 * نمی‌سنجید، و دقیقاً برای همین از چشمِ همه گریخت.
 */
class DomainDeliveryGuardTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'g'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
        ]);

        return $c;
    }

    private function domain(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id' => $this->customer()->id, 'domain' => 'guard'.random_int(100, 9999).'.com',
            'sld' => 'guard', 'tld' => 'com', 'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'pending', 'period_years' => 1,
        ], $over));
    }

    // ═══════════════ زمان‌بندی ═══════════════

    /** 🔴 فرمانی که زمان‌بندی نشود، هرگز اجرا نمی‌شود */
    public function test_the_domain_provision_command_is_actually_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command)
            ->all();

        $found = collect($commands)->contains(fn ($c) => str_contains($c, 'domains:provision'));

        $this->assertTrue($found,
            "«domains:provision» در زمان‌بندی نیست. پرچمِ پرداخت زده می‌شود ولی هیچ‌کس نمی‌خواندش:\n"
            .implode("\n", $commands));
    }

    /** خواهرش هم باید باشد — تا حذفِ تصادفیِ یکی، دیگری را هم بی‌صدا نبرد */
    public function test_the_service_provision_command_is_still_scheduled(): void
    {
        $found = collect(app(Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'provision:run'));

        $this->assertTrue($found);
    }

    // ═══════════════ قفلِ رهاشده ═══════════════

    /**
     * 🔴 اجرایی که وسطِ کار می‌میرد (پایانِ زمانِ PHP، ری‌استارت) دامنه را برای
     * همیشه `running` می‌گذارد و هیچ اجرای بعدی برش نمی‌دارد.
     */
    public function test_a_stale_running_lock_is_reclaimed(): void
    {
        $d = $this->domain(['provision_status' => 'running']);

        DB::table('domains')->where('id', $d->id)
            ->update(['updated_at' => now()->subMinutes(Domain::STALE_LOCK_MINUTES + 1)]);

        $this->assertSame(1, Domain::query()->awaitingRegistration()->count());
    }

    /** ⚠️ ولی قفلِ تازه دست‌نخورده می‌مانَد، وگرنه دو اجرا یک دامنه را می‌خرند */
    public function test_a_fresh_running_lock_is_left_alone(): void
    {
        $this->domain(['provision_status' => 'running']);

        $this->assertSame(0, Domain::query()->awaitingRegistration()->count());
    }

    // ═══════════════ پرداختِ دوباره ═══════════════

    /**
     * 🔴 پرداختِ دوم (بیش‌پرداخت، وب‌هوکِ تکراری) نباید قفلِ در حالِ اجرا را باز
     * کند — وگرنه وسطِ ثبت، اجرای بعدی همان دامنه را **دوباره می‌خرد**.
     */
    public function test_a_second_payment_does_not_unlock_a_running_registration(): void
    {
        $d = $this->domain(['provision_status' => 'running']);

        // همان UPDATEی که PaymentService می‌زند
        DB::table('domains')->where('id', $d->id)
            ->whereNotIn('status', Domain::DEAD_STATUSES)
            ->where('provision_status', 'none')
            ->update(['provision_status' => 'pending']);

        $this->assertSame('running', $d->fresh()->provision_status);
    }

    /** و دامنهٔ در صفِ آدم را هم به کرون برنمی‌گرداند */
    public function test_a_second_payment_does_not_pull_a_domain_out_of_the_manual_queue(): void
    {
        $d = $this->domain(['provision_status' => 'manual']);

        DB::table('domains')->where('id', $d->id)
            ->whereNotIn('status', Domain::DEAD_STATUSES)
            ->where('provision_status', 'none')
            ->update(['provision_status' => 'pending']);

        $this->assertSame('manual', $d->fresh()->provision_status);
    }

    // ═══════════════ نام‌سرور ═══════════════

    /**
     * 🔴 ثبتِ بی‌نام‌سرور دامنه‌ای می‌سازد که به هیچ‌جا اشاره نمی‌کند: مشتری پول
     * داده، دامنه «فعال» است، و سایتش بالا نمی‌آید.
     */
    public function test_registration_is_refused_when_no_default_nameservers_exist(): void
    {
        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        config()->set('services.openprovider.nameservers', []);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/customers*'  => Http::response(['code' => 0, 'data' => ['handle' => 'AB1-NL']]),
        ]);

        $d = $this->domain(['name_servers' => []]);
        $res = app(DomainRegistrar::class)->register($d);

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['manual']);
        $this->assertStringContainsString('نام‌سرور', $res['message']);

        // و هیچ درخواستِ ثبتی نرفته باشد
        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH) ?? '', '/domains'));
    }

    /** پیش‌فرضِ config باید واقعاً وجود داشته باشد */
    public function test_the_shipped_config_has_default_nameservers(): void
    {
        $ns = (array) config('services.openprovider.nameservers');

        $this->assertGreaterThanOrEqual(2, count($ns),
            'بدونِ نام‌سرورِ پیش‌فرض، هر ثبتی به صفِ دستی می‌رود');
    }

    // ═══════════════ روتِ وضعیت ═══════════════

    /**
     * 🔴 `/api/domains/status` عمومی است. تا امروز `forceFresh` می‌زد، یعنی هر
     * بازدید یک **ورودِ تازه** به رجیسترار — و همین الگو یک بار حسابِ ما را
     * قفل کرد.
     */
    public function test_the_public_status_route_does_not_force_a_fresh_login(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/DomainSearchController.php'));

        // ⚠️ فراخوانیِ واقعی سنجیده می‌شود (`->token(forceFresh`)، نه صرفِ وجودِ
        // واژه — وگرنه کامنتی که همین تله را توضیح می‌دهد، تست را قرمز می‌کند.
        $this->assertStringNotContainsString('->token(forceFresh', $src,
            'روتِ عمومی نباید کشِ توکن را دور بزند');
    }
}
