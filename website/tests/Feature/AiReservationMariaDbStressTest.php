<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * M3 — اثباتِ هم‌زمانیِ واقعی روی MariaDB.
 *
 * 🔴 این تست عمداً gated است: روی sqlite (سوئیتِ عادی) skip می‌شود، چون
 *    قفلِ ردیفِ sqlite اثباتِ MariaDB نیست. پذیرشِ M3 بدونِ سبز شدنِ
 *    همین تست روی MariaDB کامل نیست.
 *
 * 🔴 بدونِ `RefreshDatabase` — عمداً. آن trait روی درایورِ mysql/mariadb
 *    هر تست را داخلِ یک تراکنشِ بازِ connection-local می‌پیچد؛ ولی
 *    harness فرزندان را با `proc_open` (اتصال‌های دیتابیسِ جدا) spawn
 *    می‌کند. فرزند هیچ‌وقت ردیفِ commit-نشدهٔ والد را نمی‌بیند و روی
 *    قفلِ ردیفِ مشتری که والد در دست دارد تا timeout می‌ایستاد — همان
 *    شکستِ ساختاریِ C_no_lost_transition که در اجرای اول دیده شد.
 *    به‌جایش هر تست، اول از همه، با `migrate:fresh` خارج از هر
 *    تراکنش، اسکیما را از صفر می‌سازد؛ هر نوشتنِ والد پس از آن
 *    commit شدهٔ عادی است و برای همهٔ فرزندان دیدنی.
 *
 * ▸ اجرا (محیطِ CI/دسترس با PHP ≥ ۸.۴ و MariaDB — دیتابیسِ
 *  یک‌بارمصرفِ آزمون؛ تست خودش migrate:fresh می‌زند):
 *
 *      export DB_CONNECTION=mariadb
 *      export DB_HOST=db DB_PORT=3306 DB_DATABASE=servernet_stress \
 *             DB_USERNAME=... DB_PASSWORD=...
 *      export AI_RESERVATION_STRESS=1
 *      php artisan test tests/Feature/AiReservationMariaDbStressTest.php
 *
 * ▸ معادلِ دستی (بدونِ phpunit — همان اثبات، خروجیِ جدولی):
 *
 *      php artisan ai:reservation-stress --workers=20 --per-worker=5 \
 *           --amount=1000 --credit=10000
 *      php artisan ai:reservation-stress --mode=settle  --workers=15 --amount=1000 --credit=1000
 *      php artisan ai:reservation-stress --mode=release --workers=15 --amount=1000 --credit=1000
 *      php artisan ai:reservation-stress --mode=mixed   --workers=15 --amount=1000 --credit=1000
 *      php artisan ai:reservation-stress --mode=wallet  --workers=20 --per-worker=5 \
 *           --amount=1000 --credit=10000
 */
class AiReservationMariaDbStressTest extends TestCase
{
    public function test_gate_requires_mariadb_and_opt_in(): void
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        if (! in_array($driver, ['mariadb', 'mysql'], true) || ! getenv('AI_RESERVATION_STRESS')) {
            $this->markTestSkipped(
                'اثباتِ هم‌زمانیِ M3 فقط روی MariaDB اجرا می‌شود. '
                .'فرمان: DB_CONNECTION=mariadb AI_RESERVATION_STRESS=1 php artisan test tests/Feature/AiReservationMariaDbStressTest.php '
                .'(یا مستقیم: php artisan ai:reservation-stress)'
            );
        }

        $this->assertTrue(true);   // گیت پاس شد — تست‌هایِ زیر قابلِ اجراند
    }

    public function test_hundred_concurrent_reservations_cannot_overspend(): void
    {
        if (! $this->stressEnabled()) {
            $this->markTestSkipped('gated — نیازمندِ MariaDB');
        }

        $this->resetStressDatabase();

        // ۱۰۰ رزروِ هم‌زمانِ ۱۰۰۰تومانی روی کیفِ ۱۰٬۰۰۰تومانی
        $exit = Artisan::call('ai:reservation-stress', [
            '--mode' => 'reserve', '--workers' => 20, '--per-worker' => 5,
            '--amount' => 1000, '--credit' => 10000,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
    }

    public function test_concurrent_settle_is_idempotent(): void
    {
        if (! $this->stressEnabled()) {
            $this->markTestSkipped('gated — نیازمندِ MariaDB');
        }

        $this->resetStressDatabase();

        $this->assertSame(0, Artisan::call('ai:reservation-stress', [
            '--mode' => 'settle', '--workers' => 15, '--amount' => 1000, '--credit' => 1000,
        ]), Artisan::output());
    }

    public function test_concurrent_release_is_idempotent(): void
    {
        if (! $this->stressEnabled()) {
            $this->markTestSkipped('gated — نیازمندِ MariaDB');
        }

        $this->resetStressDatabase();

        $this->assertSame(0, Artisan::call('ai:reservation-stress', [
            '--mode' => 'release', '--workers' => 15, '--amount' => 1000, '--credit' => 1000,
        ]), Artisan::output());
    }

    public function test_settle_vs_release_race_converges(): void
    {
        if (! $this->stressEnabled()) {
            $this->markTestSkipped('gated — نیازمندِ MariaDB');
        }

        $this->resetStressDatabase();

        $this->assertSame(0, Artisan::call('ai:reservation-stress', [
            '--mode' => 'mixed', '--workers' => 15, '--amount' => 1000, '--credit' => 1000,
        ]), Artisan::output());
    }

    /**
     * 🔴 M3-correct: رزرو و برداشتِ عادیِ Wallet هم‌زمان — همان مسابقه‌ای
     * که در M3 رد شد. خرجِ عادی فقط از «در دسترس» می‌خورد؛ هیچ رزروی
     * بلعیده نمی‌شود، هیچ تومانی گم/دوبار خرج نمی‌شود.
     */
    public function test_mixed_reservations_and_ordinary_debits_cannot_overspend(): void
    {
        if (! $this->stressEnabled()) {
            $this->markTestSkipped('gated — نیازمندِ MariaDB');
        }

        $this->resetStressDatabase();

        // ۱۰۰ تلاشِ قاطیِ رزرو/برداشتِ ۱۰۰۰تومانی روی کیفِ ۱۰٬۰۰۰تومانی
        $this->assertSame(0, Artisan::call('ai:reservation-stress', [
            '--mode' => 'wallet', '--workers' => 20, '--per-worker' => 5,
            '--amount' => 1000, '--credit' => 10000,
        ]), Artisan::output());
    }

    private function stressEnabled(): bool
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        return in_array($driver, ['mariadb', 'mysql'], true) && (bool) getenv('AI_RESERVATION_STRESS');
    }

    /**
     * اسکیمایِ تازهٔ commit-شده برای هر تست — خارج از هر تراکنش.
     *
     * 🔴 گاردهای ایمنی سخت: `migrate:fresh` فقط و فقط روی دیتابیسِ
     *    یک‌بارمصرفِ آزمونِ فشار مجاز است — هیچ‌وقت روی یک
     *    دیتابیسِ دلخواهِ پیکربندی‌شده اجرا نشود.
     */
    private function resetStressDatabase(): void
    {
        $this->assertSame('testing', (string) app()->environment(),
            'migrate:fresh فقط در APP_ENV=testing مجاز است.');

        $name = (string) config('database.default');
        $conn = (array) config('database.connections.'.$name);

        $this->assertContains((string) ($conn['driver'] ?? ''), ['mariadb', 'mysql'],
            'این سوئیت فقط روی درایورِ mariadb/mysql اجرا می‌شود.');

        $this->assertSame('db', (string) ($conn['host'] ?? ''),
            'میزبانِ مجاز فقط کانتینرِ یک‌بارمصرفِ آزمون است (host=db).');

        $this->assertSame('servernet_stress', (string) ($conn['database'] ?? ''),
            'دیتابیسِ مجاز فقط servernet_stress (یک‌بارمصرفِ آزمون) است.');

        $this->assertTrue((bool) getenv('AI_RESERVATION_STRESS'),
            'migrate:fresh فقط با پرچمِ رضایتِ AI_RESERVATION_STRESS اجرا می‌شود.');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
