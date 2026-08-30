<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * استعلامِ دامنه — مالکیت و هرس.
 *
 * ═══ دو یافتهٔ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 (۱) `/account/domains/checkout/{quote}` شناسهٔ عددیِ ترتیبی داشت،
 * بدونِ مالکیت و بدونِ throttle: هر مشتریِ واردشده می‌توانست ۱..N را
 * بپیماید و همهٔ دامنه‌هایی را که بقیه جستجو کرده‌اند ببیند — نامِ دامنه
 * خودش افشای نیتِ تجاری است.
 *
 * 🔴 (۲) جدولِ `domain_quotes` هیچ هرسی نداشت: هر جستجو تا ۶۴ ردیف با
 * کلِ JSONِ خامِ رجیسترار، برای همیشه.
 */
class DomainQuotePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'qp'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function quote(array $over = []): DomainQuote
    {
        return DomainQuote::create(array_merge([
            'domain' => 'qp'.random_int(100000, 999999).'.com',
            'tld' => 'com', 'registrar' => 'openprovider', 'is_premium' => false,
            'cost_amount' => 1000, 'cost_currency' => 'EUR',
            'sell_toman' => 1_290_000, 'renew_toman' => 1_400_000,
            'honour_until' => now()->addMinutes(15), 'raw' => [],
        ], $over));
    }

    // ═══════════════ مالکیت ═══════════════

    public function test_the_first_customer_to_open_a_quote_claims_it(): void
    {
        $a = $this->customer();
        $q = $this->quote();

        $this->actingAs($a, 'customer')
            ->get('/account/domains/checkout/'.$q->id)
            ->assertOk();

        $this->assertSame($a->id, (int) $q->fresh()->customer_id,
            'اولین بیننده باید مالک شود، وگرنه گارد هیچ‌کس را نمی‌گیرد');
    }

    public function test_someone_elses_quote_is_a_404_on_checkout(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $q = $this->quote(['customer_id' => $a->id]);

        $this->actingAs($b, 'customer')
            ->get('/account/domains/checkout/'.$q->id)
            ->assertNotFound();
    }

    public function test_someone_elses_quote_cannot_be_ordered(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $q = $this->quote(['customer_id' => $a->id]);

        $this->actingAs($b, 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 1])
            ->assertSessionHasErrors();

        $this->assertSame(0, Invoice::count(), 'برای استعلامِ دیگری فاکتور ساخته شد');
        $this->assertSame(0, Domain::count());
    }

    // ═══════════════ هرس ═══════════════

    public function test_prune_removes_expired_unreferenced_quotes_and_keeps_the_evidence(): void
    {
        // ۱) منقضی و بی‌ارجاع → باید برود
        $dead = $this->quote();
        $dead->forceFill(['honour_until' => now()->subDays(3), 'created_at' => now()->subDays(3)])->saveQuietly();

        // ۲) منقضی ولی سندِ یک فروشِ واقعی → باید بماند
        $sold = $this->quote();
        $sold->forceFill(['honour_until' => now()->subDays(3), 'created_at' => now()->subDays(3)])->saveQuietly();

        Domain::create([
            'customer_id' => $this->customer()->id, 'domain' => $sold->domain,
            'sld' => 'x', 'tld' => 'com', 'status' => 'active',
            'provision_status' => 'done', 'period_years' => 1, 'quote_id' => $sold->id,
        ]);

        // ۳) تازه → باید بماند
        $fresh = $this->quote();

        $this->artisan('domains:prune-quotes')->assertExitCode(0);

        $this->assertNull(DomainQuote::find($dead->id), 'استعلامِ منقضیِ بی‌ارجاع پاک نشد');
        $this->assertNotNull(DomainQuote::find($sold->id),
            'سندِ قیمتِ لحظهٔ فروش پاک شد — مرجعِ مالیِ فاکتور از بین رفت');
        $this->assertNotNull(DomainQuote::find($fresh->id), 'استعلامِ زنده پاک شد');
    }

    /** فرمانِ ثبت‌نشده اجرا نمی‌شود — همان تلهٔ سه‌بار-تکرارشده */
    public function test_the_prune_command_is_actually_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command);

        $this->assertTrue(
            $commands->contains(fn ($c) => str_contains($c, 'domains:prune-quotes')),
            'domains:prune-quotes در زمان‌بند ثبت نشده — جدول برای همیشه رشد می‌کند'
        );
    }
}
