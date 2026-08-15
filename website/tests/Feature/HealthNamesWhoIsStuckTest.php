<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\User;
use App\Models\Service;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هشدارِ صفِ تحویل باید بگوید **کدام** مشتری، نه فقط چندتا.
 *
 * ═══ چرا ═══
 *
 * کارفرما: «نشون می‌ده صفِ تحویل ۲ سرویس منتظرِ تحویلِ دستی هستند ولی من
 * نمی‌دونم مربوط به کدوم مشتریاست؛ کاری کن بگه تا بتونم مدیریتش کنم.»
 *
 * و حق داشت. یک شمارنده می‌گوید مشکلی هست، ولی برای **اقدام** باید دانست
 * سراغِ چه کسی رفت. بی‌این، مدیر باید در `/admin/services` دنبالِ ردیفِ
 * گیرکرده بگردد — یعنی هشداری که کارِ پیداکردن را به خودِ آدم واگذار می‌کند و
 * برای همین دیر یا زود نادیده گرفته می‌شود.
 *
 * ⚠️ همان قاعده‌ای که در `SystemHealth` برای امضای وضعیت و در
 * `CloudProvisioner` برای گلوگاهِ هشدار نوشته شده: پیام باید شناسهٔ ردیف‌ها را
 * داشته باشد، وگرنه دو خرابیِ متفاوت یک متنِ یکسان می‌سازند و اعلانِ دومی
 * هرگز فرستاده نمی‌شود.
 */
class HealthNamesWhoIsStuckTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $email): Customer
    {
        return Customer::create([
            'email' => $email,
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function servicesCheck(): array
    {
        foreach (app(SystemHealth::class)->checks() as $row) {
            if (($row['key'] ?? null) === 'services') {
                return $row;
            }
        }

        $this->fail('چکِ صفِ تحویل پیدا نشد');
    }

    private function domainsCheck(): array
    {
        foreach (app(SystemHealth::class)->checks() as $row) {
            if (($row['key'] ?? null) === 'domains') {
                return $row;
            }
        }

        $this->fail('چکِ صفِ دامنه پیدا نشد');
    }

    // ═══════════════ سرویس ═══════════════

    public function test_a_manual_service_names_its_customer(): void
    {
        $c = $this->customer('stuck@example.test');
        $s = Service::create([
            'customer_id' => $c->id, 'status' => 'active',
            'provision_status' => 'manual', 'name' => 'هاست وردپرس',
        ]);

        $row = $this->servicesCheck();

        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('#'.fa_num($s->id), $row['detail'],
            'شناسهٔ سرویس باید در متن باشد تا مدیر مستقیم پیدایش کند');
        $this->assertStringContainsString((string) $c->code, $row['detail'],
            'کدِ مشتری باید در متن باشد');
    }

    /**
     * ⚠️ متنِ دو خرابیِ متفاوت نباید یکی باشد.
     *
     * امضای اعلان از همین متن ساخته می‌شود؛ اگر مشتریِ دوم که گیر می‌کند متنِ
     * یکسانی تولید کند، هیچ اعلانِ تازه‌ای فرستاده نمی‌شود.
     */
    public function test_two_different_stuck_customers_produce_different_text(): void
    {
        $a = $this->customer('a@example.test');
        Service::create(['customer_id' => $a->id, 'status' => 'active', 'provision_status' => 'manual', 'name' => 'سرویس الف']);
        $first = $this->servicesCheck()['detail'];

        $b = $this->customer('b@example.test');
        Service::create(['customer_id' => $b->id, 'status' => 'active', 'provision_status' => 'manual', 'name' => 'سرویس ب']);
        $second = $this->servicesCheck()['detail'];

        $this->assertNotSame($first, $second,
            'با گیرکردنِ مشتریِ دوم، متن عوض نشد — یعنی اعلانِ تازه‌ای نمی‌رود');
        $this->assertStringContainsString((string) $b->code, $second);
    }

    /** فهرست باید سقف داشته باشد، ولی مازاد را صریح بگوید. */
    public function test_a_long_queue_is_capped_but_says_how_many_are_hidden(): void
    {
        foreach (range(1, SystemHealth::NAME_LIMIT + 3) as $i) {
            $c = $this->customer("many{$i}@example.test");
            Service::create(['customer_id' => $c->id, 'status' => 'active', 'provision_status' => 'manual', 'name' => 'سرویس']);
        }

        $detail = $this->servicesCheck()['detail'];

        $this->assertStringContainsString('مورد دیگر', $detail,
            'مازادِ فهرست باید صریح گفته شود، نه بی‌صدا بریده');
    }

    public function test_a_healthy_queue_says_nothing_extra(): void
    {
        $row = $this->servicesCheck();

        $this->assertTrue($row['ok']);
        $this->assertStringNotContainsString('#', $row['detail']);
    }

    /** سرویسِ مرده (لغوشده) نباید برای همیشه قرمز نگه دارد. */
    public function test_a_dead_service_is_not_named(): void
    {
        $c = $this->customer('dead@example.test');
        Service::create([
            'customer_id' => $c->id,
            'status' => Service::DEAD_STATUSES[0],
            'provision_status' => 'manual', 'name' => 'سرویسِ مرده',
        ]);

        // ⚠️ شمارشِ `manual` خودش وضعیت را فیلتر نمی‌کند، ولی فهرستِ نام‌ها باید بکند
        $this->assertStringNotContainsString((string) $c->code, $this->servicesCheck()['detail']);
    }

    // ═══════════════ دامنه ═══════════════

    /**
     * همین شکاف روی دامنه هم بود — و دقیقاً همان‌جا گزارشِ واقعی رخ داد:
     * «دامنهٔ پرداخت‌شده به صفِ دستی رفت» بی‌آنکه بگوید کدام مشتری.
     */
    public function test_a_stuck_domain_names_itself(): void
    {
        $c = $this->customer('dom@example.test');
        Domain::create([
            'customer_id' => $c->id, 'domain' => 'partolastik.com', 'sld' => 'partolastik', 'tld' => 'com',
            'status' => 'pending', 'provision_status' => 'manual',
        ]);

        $row = $this->domainsCheck();

        $this->assertFalse($row['ok']);
        $this->assertStringContainsString('partolastik.com', $row['detail'],
            'نامِ دامنه باید در هشدار باشد — گویاتر از هر شناسه‌ای است');
    }

    // ═══════════════ میان‌برِ اقدام ═══════════════

    /**
     * 🔴 نام‌بردن نیمی از کار است — کارفرما گفت «بتونم مدیریتش کنم».
     *
     * اگر برای اقدام باز هم باید در پنل دنبالِ همان مشتری گشت، هشدار هنوز کارِ
     * پیداکردن را به آدم واگذار کرده و همان‌قدر نادیده می‌شود.
     */
    public function test_the_alert_links_straight_to_the_customer_file(): void
    {
        $c = $this->customer('link@example.test');
        Service::create([
            'customer_id' => $c->id, 'status' => 'active',
            'provision_status' => 'manual', 'name' => 'هاست',
        ]);

        $links = $this->servicesCheck()['links'];

        $this->assertNotEmpty($links, 'هشدار هیچ میان‌برِ اقدامی ندارد');
        $this->assertSame(route('admin.customer', $c->id), $links[0]['url'],
            'مقصد باید پروندهٔ همان مشتری باشد — دکمه‌های تحویل آن‌جایند');
    }

    /** ⚠️ لینکِ ۴۰۴ از نبودِ لینک بدتر است. */
    public function test_an_orphan_row_gets_no_broken_link(): void
    {
        $c = $this->customer('gone@example.test');
        Service::create([
            'customer_id' => $c->id, 'status' => 'active',
            'provision_status' => 'manual', 'name' => 'هاست',
        ]);
        Customer::query()->whereKey($c->id)->delete();

        $row = $this->servicesCheck();

        $this->assertFalse($row['ok'], 'ردیفِ یتیم هم باید هشدار بدهد');
        $this->assertSame([], $row['links']);
    }

    /** هر چکِ سالمی هم باید کلیدِ links را داشته باشد، وگرنه Blade می‌ترکد. */
    public function test_every_check_carries_the_links_key(): void
    {
        foreach (app(SystemHealth::class)->checks() as $row) {
            $this->assertArrayHasKey('links', $row, "چکِ {$row['key']} کلیدِ links ندارد");
            $this->assertIsArray($row['links']);
        }
    }

    /**
     * ⚠️ «کدِ ۲۰۰ یعنی هیچ» — پس خودِ لنگر در HTML سنجیده می‌شود.
     *
     * اگر حلقهٔ Blade جا بیفتد یا شرطش برعکس شود، صفحه همچنان ۲۰۰ می‌دهد و
     * مدیر دوباره همان هشدارِ بی‌اقدام را می‌بیند — بی‌هیچ خطایی هیچ‌جا.
     */
    public function test_the_errors_page_actually_renders_the_shortcut(): void
    {
        $c = $this->customer('render@example.test');
        Service::create([
            'customer_id' => $c->id, 'status' => 'active',
            'provision_status' => 'manual', 'name' => 'هاست',
        ]);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/errors')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('admin.customer', $c->id).'"', $html,
            'میان‌بر در HTML نیست — هشدار باز هم کارِ گشتن را به مدیر می‌دهد');
        $this->assertStringContainsString('hl-act', $html);
    }
}
