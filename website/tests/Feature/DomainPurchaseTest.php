<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * خریدِ دامنه — از استعلام تا صفِ ثبت.
 *
 * سه ادعا که اگر بشکنند پولِ واقعی جابه‌جا می‌شود:
 *   ۱) قیمت از **استعلامِ ذخیره‌شده** می‌آید، نه از فرم
 *   ۲) استعلامِ منقضی خرید نمی‌شود
 *   ۳) تا فاکتور پرداخت نشده، هیچ دامنه‌ای به صفِ ثبت نمی‌رود
 */
class DomainPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function customer(bool $withProfile = true): Customer
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($withProfile) {
            CustomerProfile::create([
                'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
                'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
                'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
                'postal_code' => '1234567890', 'first_name' => 'احسان', 'last_name' => 'ابراهیمی',
            ]);
        }

        return $c;
    }

    private function quote(array $over = []): DomainQuote
    {
        return DomainQuote::create(array_merge([
            'domain' => 'example.com', 'tld' => 'com', 'registrar' => 'openprovider',
            'is_premium' => false, 'cost_amount' => 950, 'cost_currency' => 'EUR',
            'sell_toman' => 2500000, 'renew_toman' => 2500000,
            'honour_until' => now()->addMinutes(15),
        ], $over));
    }

    private function order(Customer $c, DomainQuote $q, array $payload = [])
    {
        return $this->actingAs($c, 'customer')
            ->post(route('account.domains.order'), array_merge(['quote_id' => $q->id], $payload));
    }

    // ═══════════════ خرید ═══════════════

    public function test_ordering_creates_a_pending_domain_and_an_unpaid_invoice(): void
    {
        $c = $this->customer();
        $q = $this->quote();

        $this->order($c, $q)->assertRedirect();

        $d = Domain::first();
        $this->assertSame('example.com', $d->domain);
        $this->assertSame('pending', $d->status);
        $this->assertSame((int) $c->id, (int) $d->customer_id);

        $inv = Invoice::first();
        $this->assertSame('unpaid', $inv->status);
        $this->assertSame((int) $d->id, (int) $inv->domain_id);
        $this->assertSame(2500000, (int) $inv->subtotal);
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل: تا پرداخت نشده، کرون نباید دامنه را بردارد.
     *
     * اگر `provision_status` از ابتدا `pending` باشد، `domains:provision` همان
     * دقیقه دامنه را **می‌خرد** — پیش از آنکه یک ریال پول گرفته باشیم.
     */
    public function test_an_unpaid_domain_is_never_queued_for_registration(): void
    {
        $c = $this->customer();
        $this->order($c, $this->quote());

        $this->assertSame('none', Domain::first()->provision_status);
        $this->assertSame(0, Domain::query()->awaitingRegistration()->count());
    }

    /** 🔴 قیمت از استعلام می‌آید، نه از ورودیِ کاربر */
    public function test_the_price_comes_from_the_stored_quote_not_the_request(): void
    {
        $c = $this->customer();
        $q = $this->quote(['sell_toman' => 2500000]);

        $this->order($c, $q, ['sell_toman' => 1000, 'total' => 1000, 'price' => 1000]);

        $this->assertSame(2500000, (int) Invoice::first()->subtotal);
    }

    /** 🔴 استعلامِ منقضی: قیمتِ دیروز با نرخِ ارزِ دیروز */
    public function test_an_expired_quote_cannot_be_ordered(): void
    {
        $c = $this->customer();
        $q = $this->quote(['honour_until' => now()->subMinute()]);

        $this->order($c, $q)->assertSessionHasErrors();

        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
    }

    /** قیمتِ صفر یعنی نرخِ ارز نداشتیم — نباید فروخته شود */
    public function test_a_quote_without_a_usable_price_cannot_be_ordered(): void
    {
        $c = $this->customer();

        $this->order($c, $this->quote(['sell_toman' => 0]))->assertSessionHasErrors();

        $this->assertSame(0, Domain::count());
    }

    /**
     * ⚠️ بدونِ مشخصاتِ مالک، ثبتِ دامنه نزدِ هیچ رجیستراری ممکن نیست و WHOIS
     * هم قانوناً آن را می‌خواهد. پس **پیش از** گرفتنِ پول جلویش گرفته می‌شود.
     */
    public function test_a_customer_without_a_profile_is_sent_to_complete_it(): void
    {
        $c = $this->customer(withProfile: false);

        $this->order($c, $this->quote())
            ->assertRedirect(route('account.profile'))
            ->assertSessionHasErrors();

        $this->assertSame(0, Invoice::count());
    }

    /** دامنه‌ای که از قبل نزدِ ما زنده است دوباره فروخته نمی‌شود */
    public function test_a_domain_already_registered_here_cannot_be_ordered_again(): void
    {
        $c = $this->customer();
        $q = $this->quote();

        Domain::create([
            'customer_id' => $c->id, 'domain' => 'example.com', 'sld' => 'example',
            'tld' => 'com', 'registrar' => 'openprovider', 'status' => 'active',
            'provision_status' => 'done',
        ]);

        $this->order($c, $q)->assertSessionHasErrors();
        $this->assertSame(0, Invoice::count());
    }

    // ═══════════════ پس از پرداخت ═══════════════

    /**
     * 🔴 پرداختِ فاکتور باید دامنه را به صفِ ثبت ببرد — وگرنه مشتری پول داده و
     * دامنه‌اش هرگز ثبت نمی‌شود، بی‌هیچ خطایی.
     */
    public function test_paying_the_invoice_queues_the_domain_for_registration(): void
    {
        $c = $this->customer();
        $this->order($c, $this->quote());

        $d = Domain::first();
        $inv = Invoice::first();

        // همان کاری که PaymentService پس از تسویه می‌کند
        $inv->update(['status' => 'paid', 'paid' => $inv->total, 'paid_at' => now()]);
        DB::table('domains')->where('id', $d->id)
            ->whereNotIn('status', Domain::DEAD_STATUSES)
            ->where('provision_status', '!=', 'done')
            ->update(['provision_status' => 'pending']);

        $this->assertSame(1, Domain::query()->awaitingRegistration()->count());
    }

    /** دامنهٔ ازقبل‌ثبت‌شده نباید با فاکتورِ تمدید دوباره به صفِ ثبت برود */
    public function test_a_renewal_payment_does_not_re_queue_an_already_registered_domain(): void
    {
        $c = $this->customer();
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'live.com', 'sld' => 'live', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'active', 'provision_status' => 'done',
        ]);

        DB::table('domains')->where('id', $d->id)
            ->whereNotIn('status', Domain::DEAD_STATUSES)
            ->where('provision_status', '!=', 'done')
            ->update(['provision_status' => 'pending']);

        $this->assertSame('done', $d->fresh()->provision_status);
    }

    // ═══════════════ مالکیت ═══════════════

    public function test_a_customer_cannot_open_someone_elses_domain(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $d = Domain::create([
            'customer_id' => $theirs->id, 'domain' => 'other.com', 'sld' => 'other',
            'tld' => 'com', 'registrar' => 'openprovider', 'status' => 'active',
            'provision_status' => 'done',
        ]);

        $this->actingAs($mine, 'customer')->get(route('account.domain', $d))->assertNotFound();
    }

    public function test_the_domain_list_only_shows_my_domains(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        foreach ([[$mine, 'mine.com'], [$theirs, 'theirs.com']] as [$owner, $name]) {
            Domain::create([
                'customer_id' => $owner->id, 'domain' => $name,
                'sld' => explode('.', $name)[0], 'tld' => 'com',
                'registrar' => 'openprovider', 'status' => 'active', 'provision_status' => 'done',
            ]);
        }

        $html = $this->actingAs($mine, 'customer')->get(route('account.domains'))->assertOk()->getContent();

        $this->assertStringContainsString('mine.com', $html);
        $this->assertStringNotContainsString('theirs.com', $html);
    }
}
