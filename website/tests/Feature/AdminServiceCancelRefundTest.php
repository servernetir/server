<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لغوِ سرویس + بازگشتِ وجه به کیف پول — از پروفایلِ مشتری در پنل.
 *
 * ═══ چرا این تست سخت‌گیر است ═══
 *
 * این مسیر **پولِ واقعی** جابه‌جا می‌کند. سه خرابیِ ممکنش هر سه خاموش‌اند:
 * سقفِ شل (یک صفرِ اضافه = بازگشتِ ده‌برابری)، دوباره‌پرداخت (دو کلیک = دو
 * اعتبار)، و لغوی که فقط status می‌نویسد و زیرساخت را جا می‌گذارد (اجاره‌اش
 * تا ابد پای ماست).
 */
class AdminServiceCancelRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ خاتمه ممکن است با زیرساخت تماس بگیرد؛ هیچ درخواستِ واقعی نرود
        Http::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** @return array{0: Customer, 1: Service} سرویسی با ۵۰۰هزار تومان پرداختی */
    private function paidService(int $paid = 500_000): array
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'r'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'هاست آزمایشی', 'currency_code' => 'IRT',
            'price' => $paid, 'cycle' => 'monthly', 'status' => 'active',
        ]);

        Invoice::create([
            'customer_id' => $c->id, 'service_id' => $s->id, 'currency_code' => 'IRT',
            'number' => 'INV-'.random_int(100000, 999999),
            'subtotal' => $paid, 'tax' => 0, 'total' => $paid, 'paid' => $paid,
            'status' => 'paid',
        ]);

        return [$c, $s];
    }

    /** 🔴 مسیرِ خوش: لغو + اعتبارِ دقیقاً همان مبلغ در کیف پول. */
    public function test_cancel_credits_the_wallet_and_kills_the_service(): void
    {
        [$c, $s] = $this->paidService(500_000);
        $this->assertSame(0, $c->creditBalance('IRT'), 'پیش‌شرط: کیف پول خالی');

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 500_000])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($s->fresh()->isDead(), 'سرویس باید مرده باشد');
        $this->assertSame(500_000, $c->fresh()->creditBalance('IRT'), 'کلِ مبلغ باید به کیف پول برگردد');

        $entry = CreditEntry::where('customer_id', $c->id)->first();
        $this->assertSame('refund', $entry->reason);
        $this->assertSame($s->id, (int) $entry->source_id);
    }

    /**
     * 🔴 سقف = جمعِ پرداختی. یک تومان بیشتر ⇒ خطا و **هیچ‌چیز عوض نمی‌شود** —
     * نه سرویس لغو می‌شود نه پولی می‌رود. یک صفرِ اضافه پولِ واقعی است.
     */
    public function test_more_than_the_paid_total_is_rejected_and_nothing_happens(): void
    {
        [$c, $s] = $this->paidService(500_000);

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 500_001])
            ->assertSessionHasErrors('amount');

        $this->assertFalse($s->fresh()->isDead(), 'با مبلغِ نامعتبر، سرویس نباید لغو شود');
        $this->assertSame(0, $c->fresh()->creditBalance('IRT'));
    }

    /** 🔴 گاردِ دوباره‌پرداخت: بارِ دوم خطا می‌دهد و موجودی تکان نمی‌خورد. */
    public function test_a_second_refund_for_the_same_service_is_blocked(): void
    {
        [$c, $s] = $this->paidService(500_000);

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 200_000]);
        $this->assertSame(200_000, $c->fresh()->creditBalance('IRT'));

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 200_000])
            ->assertSessionHasErrors();

        $this->assertSame(200_000, $c->fresh()->creditBalance('IRT'), 'بازگشتِ دوم نباید پرداخت شود');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    /** مبلغِ صفر = فقط لغو؛ هیچ ردیفِ اعتباری ساخته نمی‌شود. */
    public function test_zero_amount_cancels_without_touching_the_wallet(): void
    {
        [$c, $s] = $this->paidService();

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($s->fresh()->isDead());
        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)->count());
    }

    /** سرویسِ از قبل مرده + بازگشتِ دیرهنگام: اعتبار می‌نشیند، بی‌خطا. */
    public function test_a_late_refund_on_an_already_dead_service_still_credits(): void
    {
        [$c, $s] = $this->paidService(300_000);
        $s->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->actingAs($this->admin)
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 300_000])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(300_000, $c->fresh()->creditBalance('IRT'));
    }

    /** فقط admin — نویسنده ۴۰۳ می‌گیرد و هیچ پولی جابه‌جا نمی‌شود. */
    public function test_an_author_cannot_move_money(): void
    {
        [$c, $s] = $this->paidService();

        $this->actingAs(User::factory()->create(['role' => 'author']))
            ->post('/admin/services/'.$s->id.'/cancel-refund', ['amount' => 100_000])
            ->assertForbidden();

        $this->assertFalse($s->fresh()->isDead());
        $this->assertSame(0, $c->fresh()->creditBalance('IRT'));
    }

    /** فرمِ لغو+بازگشت باید روی پروفایلِ مشتری رندر شود، با سقفِ پیش‌پرشده. */
    public function test_the_profile_renders_the_form_with_the_cap_prefilled(): void
    {
        [$c, $s] = $this->paidService(500_000);

        $html = $this->actingAs($this->admin)
            ->get('/admin/customers/'.$c->id)->assertOk()->getContent();

        $this->assertStringContainsString('/admin/services/'.$s->id.'/cancel-refund', $html, 'فرم روی پروفایل نیست');
        $this->assertStringContainsString('max="500000"', $html, 'سقفِ فیلدِ مبلغ با جمعِ پرداختی یکی نیست');
        $this->assertStringContainsString('value="500000"', $html, 'مبلغ با جمعِ پرداختی پیش‌پر نشده');
        $this->assertStringContainsString('data-confirm', $html, 'اقدامِ برگشت‌ناپذیرِ پولی بدونِ تأیید است');
    }
}
