<?php

namespace Tests\Feature;

use App\Models\AiReservation;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Services\Ai\AiReservations;
use App\Services\Ai\ReservationReconciler;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M3 — چرخهٔ عمرِ رزرو: نگه‌داشته → تسویه/آزادسازی/انقضا.
 *
 * SQLite تک‌رشته‌ای معنایِ «هم‌زمانی» را اثبات نمی‌کند (اثباتِ MariaDB
 * کارِ AiReservationMariaDbStressTest و فرمانِ ai:reservation-stress
 * است) — این‌جا **معنایِ پول** سنجیده می‌شود: هر گذار یک‌بار، دفتر
 * دقیقاً یک بار، پولِ تسویه‌شده هرگز آزاد نمی‌شود.
 */
class AiReservationsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'r'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function funded(int $credit = 100_000): array
    {
        $c = $this->customer();
        app(Wallet::class)->credit($c->id, 'IRT', $credit, 'topup', $c, 'شارژِ آزمون');

        return [$c, $credit];
    }

    private function svc(): AiReservations
    {
        return app(AiReservations::class);
    }

    // ═══════════════ رزرو ═══════════════

    public function test_reserve_success_holds_funds(): void
    {
        [$c, $credit] = $this->funded(100_000);
        $svc = $this->svc();

        $r = $svc->reserve($c->id, 30_000, 'k1', now()->addMinutes(5));

        $this->assertTrue($r->ok);
        $this->assertSame(AiReservation::STATUS_PENDING, $r->reservation->status);

        // رزرو دفتر نمی‌نویسد؛ فقط در دسترس را کم می‌کند
        $this->assertSame($credit, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(30_000, $svc->heldOf($c->id));
        $this->assertSame(70_000, $svc->availableOf($c->id));
    }

    public function test_reserve_insufficient_balance(): void
    {
        [$c] = $this->funded(10_000);

        $r = $this->svc()->reserve($c->id, 10_001, 'k1');

        $this->assertFalse($r->ok);
        $this->assertSame('insufficient_funds', $r->code);
        $this->assertSame(0, AiReservation::count());
    }

    public function test_two_competing_reservations_share_one_balance(): void
    {
        [$c] = $this->funded(10_000);
        $svc = $this->svc();

        $first = $svc->reserve($c->id, 6_000, 'k1');
        $second = $svc->reserve($c->id, 6_000, 'k2');

        $this->assertTrue($first->ok, 'اولین باید می‌بُرد');
        $this->assertFalse($second->ok, 'دومی باید روی «در دسترسِ» منهایِ اولی رد شود');
        $this->assertSame('insufficient_funds', $second->code);
        $this->assertSame(6_000, $svc->heldOf($c->id));
    }

    public function test_duplicate_reserve_is_idempotent(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();

        $first = $svc->reserve($c->id, 10_000, 'same-key');
        $dup = $svc->reserve($c->id, 10_000, 'same-key');

        $this->assertTrue($dup->ok);
        $this->assertTrue($dup->already, 'کلیدِ تکراری همانِ قبلی را می‌دهد');
        $this->assertSame($first->reservation->id, $dup->reservation->id);
        $this->assertSame(10_000, $svc->heldOf($c->id), 'یک کلید = یک نگه‌داشته');
    }

    public function test_reserve_rejects_non_positive(): void
    {
        [$c] = $this->funded();

        $this->assertFalse($this->svc()->reserve($c->id, 0, 'k0')->ok);
        $this->assertSame('invalid_amount', $this->svc()->reserve($c->id, -5, 'k0')->code);
    }

    // ═══════════════ تسویه ═══════════════

    public function test_settle_once_debits_ledger_exactly_once(): void
    {
        [$c, $credit] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;

        $t = $svc->settle($res);

        $this->assertTrue($t->ok);
        $this->assertSame($credit - 30_000, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(0, $svc->heldOf($c->id), 'تسویه نگه‌داشته را آزاد می‌کند');

        $entry = CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->first();
        $this->assertNotNull($entry);
        $this->assertSame(-30_000, $entry->amount);
        $this->assertSame($entry->id, $res->fresh()->ledger_entry_id, 'ردِ دفتر برای ممیزی ذخیره شد');
    }

    public function test_settle_twice_is_idempotent(): void
    {
        [$c, $credit] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;

        $svc->settle($res);
        $again = $svc->settle($res);

        $this->assertTrue($again->ok);
        $this->assertTrue($again->already, 'تکرارِ تسویه «قبلاً» است');
        $this->assertSame($credit - 30_000, app(Wallet::class)->balanceOf($c->id), 'دفتر دو بار خرج نشد');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->count());
    }

    public function test_settle_after_release_fails(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;
        $svc->release($res);

        $t = $svc->settle($res);

        $this->assertFalse($t->ok);
        $this->assertSame('reservation_released', $t->code);
        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->count());
    }

    // ═══════════════ آزادسازی ═══════════════

    public function test_release_once_restores_availability_without_ledger(): void
    {
        [$c, $credit] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;

        $t = $svc->release($res);

        $this->assertTrue($t->ok);
        $this->assertSame(AiReservation::STATUS_RELEASED, $res->fresh()->status);
        $this->assertSame($credit, app(Wallet::class)->balanceOf($c->id), 'آزادسازی دفتر نمی‌نویسد');
        $this->assertSame($credit, $svc->availableOf($c->id), 'پول به در دسترس برگشت');
    }

    public function test_release_twice_is_idempotent(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;

        $svc->release($res);
        $again = $svc->release($res);

        $this->assertTrue($again->ok);
        $this->assertTrue($again->already);
        $this->assertSame(100_000, $svc->availableOf($c->id), 'پول دوباره آزاد نشد');
    }

    public function test_release_after_settle_fails(): void
    {
        [$c, $credit] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 30_000, 'k1')->reservation;
        $svc->settle($res);

        $t = $svc->release($res);

        $this->assertFalse($t->ok);
        $this->assertSame('reservation_settled', $t->code);
        $this->assertSame($credit - 30_000, app(Wallet::class)->balanceOf($c->id), 'پولِ خرج‌شده برنگشت');
    }

    // ═══════════════ انقضا ═══════════════

    public function test_expired_reservation_frees_funds_and_cannot_settle(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();

        $res = $svc->reserve($c->id, 30_000, 'k1', now()->subMinute())->reservation;

        // مهلت گذشته → از «در دسترس» بیرون است، حتی پیش از علامت‌خوردن
        $this->assertSame(100_000, $svc->availableOf($c->id));

        // تسویهٔ دیرهنگام رد می‌شود — پولش آزاد شده و رزروِ دیگری می‌تواند بنشیند
        $t = $svc->settle($res);
        $this->assertFalse($t->ok);
        $this->assertSame('reservation_expired', $t->code);
        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->count());

        // رزروِ تازه روی همان موجودی می‌نشیند
        $fresh = $svc->reserve($c->id, 100_000, 'k2');
        $this->assertTrue($fresh->ok, 'پولِ رزروِ منقضی واقعاً آزاد است');
    }

    public function test_expire_pending_marks_only_stale_pending(): void
    {
        [$c] = $this->funded(1_000_000);
        $svc = $this->svc();

        $stale = $svc->reserve($c->id, 10_000, 'stale', now()->subMinute())->reservation;
        $live = $svc->reserve($c->id, 10_000, 'live', now()->addHour())->reservation;
        // 🔴 مهلتِ زنده — تسویهٔ pendingِ مهلت‌گذشته طبقِ قراردادِ M3 رد
        // می‌شود (reservation_expired)؛ این رزرو قرار است «تسویه‌شده» بماند.
        $settled = $svc->reserve($c->id, 10_000, 'paid', now()->addHour())->reservation;
        $svc->settle($settled);

        $n = app(ReservationReconciler::class)->sweepExpired();

        $this->assertSame(1, $n, 'فقط pendingِ مهلت‌گذشته');
        $this->assertSame(AiReservation::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(AiReservation::STATUS_PENDING, $live->fresh()->status);

        // 🔴 انقضا پولِ تسویه‌شده را آزاد نمی‌کند: settled ماند و دفترش ماند
        $this->assertSame(AiReservation::STATUS_SETTLED, $settled->fresh()->status);
        $this->assertNotNull($settled->fresh()->ledger_entry_id);
    }

    public function test_expire_is_idempotent(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();
        $svc->reserve($c->id, 10_000, 'stale', now()->subMinute());

        $this->assertSame(1, $svc->expirePending());
        $this->assertSame(0, $svc->expirePending(), 'انقضایِ دوم هیچ ردیفی نمی‌گیرد');
    }

    public function test_release_of_expired_fails_cleanly(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();
        $res = $svc->reserve($c->id, 10_000, 'k1', now()->subMinute())->reservation;
        $svc->expirePending();

        $t = $svc->release($res);

        $this->assertFalse($t->ok);
        $this->assertSame('reservation_expired', $t->code);
    }

    // ═══════════════ درزِ ممیزی ═══════════════

    public function test_reconciler_drift_report_is_clean_on_happy_paths(): void
    {
        [$c] = $this->funded(1_000_000);
        $svc = $this->svc();

        $a = $svc->reserve($c->id, 10_000, 'a')->reservation;
        $svc->settle($a);
        $b = $svc->reserve($c->id, 10_000, 'b')->reservation;
        $svc->release($b);

        $report = app(ReservationReconciler::class)->driftReport();

        $this->assertSame(0, $report['settled_without_ledger']);
        $this->assertSame(0, $report['terminal_without_stamp']);
    }

    // ═══════════════ مرزهایِ دقیق ═══════════════

    public function test_available_boundary_is_exact(): void
    {
        [$c] = $this->funded(100_000);
        $svc = $this->svc();

        $this->assertTrue($svc->reserve($c->id, 100_000, 'exact')->ok, 'برابرِ در دسترس مجاز است');
        $this->assertSame(0, $svc->availableOf($c->id));
        $this->assertFalse($svc->reserve($c->id, 1, 'over')->ok, 'یک تومان بالاتر رد می‌شود');
    }
}
