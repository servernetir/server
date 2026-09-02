<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 آدرسی که به مشتری می‌دهیم باید همانی باشد که کار می‌کند.
 *
 * ═══ تیکتِ واقعی (۱۱ شهریور ۱۴۰۵، مشتری SN-978603) ═══
 *
 *   «سلام، پیام اومد سرور تحویل داده شد ولی آیپی یک آیپی خصوصی هست»
 *
 * ماشین روی Proxmox ساخته شده بود و `ipv4`ش `10.10.10.64` — آدرسِ شبکهٔ
 * داخلی. دسترسیِ عمومی‌اش از یک پورت‌فوروارد روی IP مشترک می‌آمد
 * (`85.9.108.118:20001`) که `PullController::portForwards` تخصیص می‌دهد و در
 * `meta.public_port` می‌نشیند — ولی **هیچ‌جای رو به مشتری از آن خبر نداشت**:
 * پرتال `ssh root@10.10.10.64` چاپ می‌کرد و ایمیلِ تحویل همان IP خصوصی را.
 *
 * ⚠️ بدترین تکه: `ready_notified_at` همان لحظه قفل می‌شود. پس اعلانِ **غلط**
 * می‌رفت و اعلانِ **درست** دیگر هرگز نمی‌رفت.
 *
 * تشخیص عمداً روی خودِ آدرس است نه نامِ زیرساخت — شرطِ واقعی «این IP از
 * اینترنت قابلِ استفاده هست یا نه» است.
 */
class CloudNattedAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('public_ip', '85.9.108.118');
    }

    private function inst(array $over = []): CloudInstance
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'na'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'سرور', 'currency_code' => 'IRT',
            'price' => 550000, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done',
        ]);

        return CloudInstance::create(array_merge([
            'service_id' => $service->id, 'provider' => 'proxmox', 'provider_ref' => '117',
            'location_code' => 'ir-tehran', 'hostname' => 'sn-svc-'.$service->id,
            'ipv4' => '10.10.10.64', 'status' => 'running',
            'meta' => ['public_port' => 20001],
        ], $over));
    }

    // ═══════════ تشخیصِ آدرسِ خصوصی ═══════════

    public function test_a_private_ip_is_recognised_as_unusable(): void
    {
        $this->assertTrue($this->inst(['ipv4' => '10.10.10.64'])->hasPrivateIp());
        $this->assertTrue($this->inst(['ipv4' => '192.168.1.5'])->hasPrivateIp());
        $this->assertTrue($this->inst(['ipv4' => '172.16.0.9'])->hasPrivateIp());
    }

    public function test_a_public_ip_is_left_alone(): void
    {
        $inst = $this->inst(['ipv4' => '91.107.179.170', 'provider' => 'hetzner']);

        $this->assertFalse($inst->hasPrivateIp());
        $this->assertSame('91.107.179.170', $inst->address());
        $this->assertSame('ssh root@91.107.179.170', $inst->sshCommand(),
            'سرورِ IPعمومی نباید بی‌جهت `-p` بگیرد');
    }

    // ═══════════ آدرسِ رو به مشتری ═══════════

    /** ماشینِ پشتِ NAT آدرسِ عمومی + پورتِ فورواردشده می‌گیرد. */
    public function test_a_natted_instance_shows_the_forwarded_endpoint(): void
    {
        $inst = $this->inst();

        $this->assertSame('85.9.108.118:20001', $inst->address());
        $this->assertSame('ssh root@85.9.108.118 -p 20001', $inst->sshCommand());
    }

    /**
     * 🔴 و اگر فوروارد هنوز نیست، **هیچ آدرسی** نمی‌دهیم.
     *
     * چاپِ `10.10.10.64` وعدهٔ چیزی است که وجود ندارد؛ همان تیکت.
     */
    public function test_without_a_forward_there_is_no_address_at_all(): void
    {
        $inst = $this->inst(['meta' => []]);

        $this->assertNull($inst->address(), 'آدرسِ خصوصی نباید به مشتری نشان داده شود');
        $this->assertNull($inst->sshCommand());
    }

    /** IP عمومیِ تنظیم‌نشده هم یعنی آدرسی نداریم — نه اینکه پورتِ تنها را بدهیم. */
    public function test_without_a_public_ip_there_is_no_address_either(): void
    {
        Setting::put('public_ip', '');

        $this->assertNull($this->inst()->address());
    }

    /** نامِ دامنه اگر تنظیم شده باشد، جای IP را می‌گیرد — ولی پورت سرِ جایش. */
    public function test_a_public_host_name_replaces_the_ip_but_not_the_port(): void
    {
        Setting::put('public_host', 'ir1.servernet.cloud');

        $inst = $this->inst();

        $this->assertSame('ir1.servernet.cloud:20001', $inst->address());
        $this->assertSame('ssh root@ir1.servernet.cloud -p 20001', $inst->sshCommand());
    }

    // ═══════════ تنظیمات ═══════════

    /**
     * 🔴 `public_ip` باید از پنل قابلِ تنظیم باشد.
     *
     * تا امروز هیچ فرمی نداشت و پشتوانه‌اش `config('servernet.exit.public_ip')`
     * بود که آن کلید در config/servernet.php **اصلاً وجود ندارد**. یعنی مقدار
     * همیشه خالی می‌مانْد و راهی هم برای پرکردنش نبود — صفحهٔ اکسیت فقط شمارهٔ
     * پورت را نشان می‌داد و پرتالِ مشتری هیچ آدرسی. قابلیتی که از روزِ اول
     * نمی‌توانست کار کند.
     */
    public function test_the_public_ip_setting_is_saveable_from_the_panel(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/settings', [
            'tab'         => 'infra',
            'public_ip'   => '85.9.108.118',
            'public_host' => 'ir1.servernet.cloud',
        ])->assertRedirect();

        $this->assertSame('85.9.108.118', Setting::get('public_ip'));
        $this->assertSame('ir1.servernet.cloud', Setting::get('public_host'));
    }

    // ═══════════ اعلانِ تحویل ═══════════

    /**
     * 🔴 اعلانِ «سرورت آماده شد» بدونِ آدرسِ قابلِ استفاده نباید برود.
     *
     * `ready_notified_at` همان لحظه قفل می‌شود، پس اعلانِ غلط اعلانِ درست را
     * برای همیشه می‌کشد. باید صبر کند تا فوروارد ساخته شود.
     */
    public function test_the_ready_notice_waits_until_there_is_a_usable_address(): void
    {
        Http::fake();
        $inst = $this->inst(['meta' => []]);          // بی‌فوروارد

        app(\App\Services\Cloud\CloudProvisioner::class)->deliverOwedNotices();

        $this->assertNull($inst->fresh()->ready_notified_at,
            '🔴 اعلان با آدرسِ خصوصی رفت و حالا اعلانِ درست هرگز نمی‌رود');
    }

    /** و به‌محضِ ساخته‌شدنِ فوروارد، همان ردیف اعلانش را می‌گیرد. */
    public function test_the_notice_goes_out_once_the_forward_exists(): void
    {
        Http::fake();
        $inst = $this->inst();                        // با فوروارد

        app(\App\Services\Cloud\CloudProvisioner::class)->deliverOwedNotices();

        $this->assertNotNull($inst->fresh()->ready_notified_at,
            'تحویلِ سالم هم بسته شد — گارد بیش از اندازه سفت است');
    }
}
