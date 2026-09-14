<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Ai\AiReservations;
use App\Services\Finance\Wallet;
use Illuminate\Console\Command;

/**
 * harnessِ فشارِ هم‌زمانیِ رزرو — اثباتِ MariaDB، نه sqlite.
 *
 * 🔴 SQLite/تستِ تک‌رشته‌ای برایِ پذیرشِ M3 کافی نیست: قفلِ ردیفِ مشتری
 *    باید زیرِ صد فراخوانِ هم‌زمانِ واقعیِ MariaDB تاب بیاورد. این
 *    فرمان همان اثبات را می‌سازد:
 *
 *      php artisan ai:reservation-stress --workers=20 --per-worker=5 --amount=1000 --credit=10000
 *
 *   یعنی ۱۰۰ رزروِ هم‌زمانِ ۱۰۰۰تومانی روی کیفِ ۱۰٬۰۰۰تومانی — دقیقاً
 *   ۱۰ تا باید بِبَرند و ۹۰ تا «کافی نیست» بگیرند؛ دفتر نباید منفی شود
 *   و هیچ تومانی نباید گم شود یا دوبار خرج شود.
 *
 * ▸ چگونه کار می‌کند: فراخوانِ اصلی مشتریِ آزمون را می‌سازد و کیف را
 *   شارژ می‌کند، بعد N فرایندِ فرزند (php artisan همان فرمان با
 *   --worker) را هم‌زمان spawn می‌کند؛ هر فرزند M رزروِ واقعی می‌زند و
 *   خروجیِ JSON می‌دهد. والد خروجی‌ها را جمع می‌زند و ناوردایی را
 *   می‌سنجد — خارجِ تراکنشِ فرزندان، روی دیتابیسِ نهایی.
 *
 * حالت‌هایِ مسابقه (mode):
 *   reserve   — صد رزروِ رقابتی روی یک کیف (پیش‌فرض)
 *   settle    — K فرزند هم‌زمان روی «یک» رزرو settle می‌زنند → دقیقاً یک دفتر
 *   release   — K فرزند هم‌زمان روی «یک» رزرو release می‌زنند → یک بار آزاد
 *   mixed     — نصف settle، نصف release روی یک رزرو → یک برنده، حکمِ دیگری صادق
 *   wallet    — 🔴 M3-correct: هر فرزند رزرو و برداشتِ عادیِ Wallet را
 *               «هم‌زمان» قاطی می‌زند — اثباتِ اینکه خرجِ عادی هم
 *               فقط از «در دسترس» می‌خورد و رزروها را نمی‌بلعد.
 */
class AiReservationStress extends Command
{
    protected $signature = 'ai:reservation-stress
        {--mode=reserve : reserve|settle|release|mixed|wallet}
        {--workers=20 : تعدادِ فرایندهایِ فرزند}
        {--per-worker=5 : رزرو به ازای هر فرزند (حالتِ reserve)}
        {--amount=1000 : مبلغِ هر رزرو (تومانِ صحیح)}
        {--credit=10000 : شارژِ اولیهٔ کیف (تومانِ صحیح)}
        {--ttl=300 : مهلتِ رزروها (ثانیه)}
        {--worker : پرچمِ داخلی — این فرایندِ فرزند است}
        {--customer-id= : پرچمِ داخلی — شناسهٔ مشتریِ آزمون}
        {--reservation-id= : پرچمِ داخلی — شناسهٔ رزروِ مسابقه}';

    protected $description = 'فشارِ هم‌زمانیِ رزروِ AI روی MariaDB — اثباتِ عدمِ بیش‌خرج/تکرار';

    public function handle(AiReservations $reservations, Wallet $wallet): int
    {
        if ($this->option('worker')) {
            return $this->runWorker($reservations, $wallet);
        }

        return $this->runParent($reservations, $wallet);
    }

    // ═══════════════════════ فرزند ═══════════════════════

    /** @return array<string,mixed> */
    private function runWorker(AiReservations $reservations, Wallet $wallet): int
    {
        $customerId = (int) $this->option('customer-id');
        $mode = (string) $this->option('mode');
        $perWorker = (int) $this->option('per-worker');
        $amount = (int) $this->option('amount');
        $ttl = (int) $this->option('ttl');

        $out = ['ok' => 0, 'insufficient' => 0, 'other_fail' => 0, 'already' => 0,
            'reserve_ok' => 0, 'debit_ok' => 0, 'codes' => []];

        try {
            for ($i = 0; $i < $perWorker; $i++) {
                $key = 'stress-'.getmypid().'-'.$i;

                /*
                | 🔴 M3-correct، حالتِ wallet: قاطی‌کردنِ عمدیِ دو دشمن —
                | رزرو و برداشتِ عادی — روی یک کیف. نوبتی تا هر دو مسیر
                | زیرِ فشارِ واقعی امتحان شوند؛ گاردِ هر دو روی «در دسترس»
                | است و قفلِ ردیفِ مشتری آن‌ها را سریالی می‌کند.
                */
                if ($mode === 'wallet' && $i % 2 === 1) {
                    try {
                        $wallet->debit($customerId, 'IRT', $amount, 'stress_wallet_debit', null,
                            'برداشتِ عادیِ فشارِ M3-correct');
                        $out['ok']++;
                        $out['debit_ok']++;
                        $out['codes'][] = 'debit_ok';
                    } catch (\App\Services\Finance\WalletException $e) {
                        $out[$e->errorCode === 'insufficient_funds' ? 'insufficient' : 'other_fail']++;
                        $out['codes'][] = 'debit_'.$e->errorCode;
                    }

                    continue;
                }

                $r = $reservations->reserve($customerId, $amount, $key, now()->addSeconds($ttl));

                $out[$r->ok ? 'ok' : ($r->code === 'insufficient_funds' ? 'insufficient' : 'other_fail')]++;
                $out['codes'][] = $r->code.($r->already ? ':already' : '');

                if ($r->ok && ! $r->already) {
                    $out['reserve_ok']++;
                }
            }

            if (in_array($mode, ['settle', 'release', 'mixed'], true) && $this->option('reservation-id')) {
                /** @var \App\Models\AiReservation|null $res */
                $res = \App\Models\AiReservation::find((int) $this->option('reservation-id'));

                if ($res !== null) {
                    $act = $mode === 'mixed' ? (random_int(0, 1) ? 'settle' : 'release') : $mode;

                    $t = $act === 'settle' ? $reservations->settle($res) : $reservations->release($res);

                    $out['transition'] = $t->code.($t->already ? ':already' : '');
                    $out[$t->ok ? 'ok' : 'other_fail']++;
                }
            }

            $this->line(json_encode($out));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->line(json_encode($out + ['fatal' => $e->getMessage()]));

            return self::FAILURE;
        }
    }

    // ═══════════════════════ والد ═══════════════════════

    private function runParent(AiReservations $reservations, Wallet $wallet): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['reserve', 'settle', 'release', 'mixed', 'wallet'], true)) {
            $this->error('--mode باید reserve|settle|release|mixed|wallet باشد.');

            return self::FAILURE;
        }

        if (config('database.default') === 'sqlite' || str_contains((string) config('database.connections.'.config('database.default').'.driver', ''), 'sqlite')) {
            $this->error('🔴 این اثبات روی sqlite معنا ندارد — MariaDB لازم است (قفلِ ردیفِ واقعی).');

            return self::FAILURE;
        }

        $credit = (int) $this->option('credit');
        $amount = (int) $this->option('amount');
        $workers = (int) $this->option('workers');
        $perWorker = (int) $this->option('per-worker');
        $ttl = (int) $this->option('ttl');
        $totalAttempts = $workers * $perWorker;

        if ($amount <= 0 || $credit <= 0 || $workers < 2) {
            $this->error('--amount/--credit مثبت و --workers ≥ ۲ لازم است.');

            return self::FAILURE;
        }

        // ── صحنه: مشتریِ آزمون + شارژِ تازهٔ کیف ──
        $customer = Customer::create([
            'email' => 'ai-stress-'.now()->format('YmdHis').'-'.random_int(100, 999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ]);

        $wallet->credit($customer->id, 'IRT', $credit, 'adjustment', $customer, 'شارژِ آزمونِ فشارِ M3');

        // ── رزروِ قهرمان برای حالت‌هایِ گذار ──
        $raceReservationId = null;

        if (in_array($mode, ['settle', 'release', 'mixed'], true)) {
            $r = $reservations->reserve($customer->id, $amount, 'stress-race-seed', now()->addSeconds($ttl));

            if (! $r->ok || $r->reservation === null) {
                $this->error('ساختِ رزروِ مسابقه شکست خورد: '.$r->code);

                return self::FAILURE;
            }

            $raceReservationId = $r->reservation->id;
        }

        // ── spawn هم‌زمانِ فرزندان ──
        $cmd = [PHP_BINARY, defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan', 'ai:reservation-stress',
            '--mode='.$mode, '--worker', '--customer-id='.$customer->id,
            '--amount='.$amount, '--per-worker='.$perWorker, '--ttl='.$ttl,
            '--reservation-id='.$raceReservationId];

        $this->info("spawn {$workers} × {$perWorker} رزروِ {$amount}تومانی روی کیفِ ".number_format($credit).' (mode='.$mode.')');

        $procs = $pipes = [];
        foreach (range(1, $workers) as $i) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $cmdLine = implode(' ', array_map(fn ($p) => '"'.$p.'"', $cmd));

            $procs[$i] = proc_open($cmdLine, $descriptors, $pipes[$i]);
        }

        $results = [];
        foreach ($procs as $i => $proc) {
            $stdout = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][0]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);

            $code = proc_close($proc);
            $json = json_decode(trim((string) $stdout) ?: '{}', true);

            $results[] = is_array($json) ? $json : ['fatal' => 'bad-json', 'exit' => $code];
        }

        // ── سنجشِ ناوردایی‌ها روی وضعیتِ نهاییِ دیتابیس ──
        $okCount = (int) array_sum(array_map(fn ($r) => (int) ($r['ok'] ?? 0), $results));
        $already = (int) array_sum(array_map(fn ($r) => (int) ($r['already'] ?? 0), $results));
        $ledgerSum = $wallet->balanceOf($customer->id);
        $held = $reservations->heldOf($customer->id);

        $checks = [];

        if ($mode === 'wallet') {
            /*
            | 🔴 M3-correct: رزرو و برداشتِ عادی هم‌زمان روی یک کیف.
            | برداشتِ عادی دفتر می‌نویسد، رزرو نمی‌نویسد — پس جمعِ دفتر
            | دقیقاً «شارژ منهایِ برداشتهایِ برندهٔ عادی» است و نگه‌داشته
            | دقیقاً «رزروهایِ برنده».
            */
            $reserveWins = (int) array_sum(array_map(fn ($r) => (int) ($r['reserve_ok'] ?? 0), $results));
            $debitWins = (int) array_sum(array_map(fn ($r) => (int) ($r['debit_ok'] ?? 0), $results));
            $debitRows = (int) \App\Models\CreditEntry::where('customer_id', $customer->id)
                ->where('reason', 'stress_wallet_debit')->count();

            $checks['A_no_overspend'] = ($reserveWins + $debitWins) * $amount <= $credit;
            $checks['B_no_negative_balance'] = $ledgerSum >= 0;
            $checks['I_ledger_sum_matches_debits'] = $ledgerSum === $credit - $debitWins * $amount;
            $checks['J_held_matches'] = $held === $reserveWins * $amount;
            $checks['K_debit_rows_match_wins'] = $debitRows === $debitWins;   // هیچ کسرِ گمشده/تکراری
            $checks['no_lost_orphan_reservations'] = \App\Models\AiReservation::where('customer_id', $customer->id)->count() === $reserveWins;
        } elseif ($mode === 'reserve') {
            $expectedWins = intdiv($credit, $amount);

            $checks['A_no_overspend'] = $okCount * $amount <= $credit;
            $checks['B_no_negative_balance'] = $ledgerSum >= 0;
            $checks['I_ledger_sum_intact'] = $ledgerSum === $credit;   // رزرو دفتر نمی‌نویسد
            $checks['J_held_matches'] = $held === $okCount * $amount;
            $checks['wins_at_most_expected'] = $okCount <= $expectedWins;
            $checks['no_lost_orphan_reservations'] = \App\Models\AiReservation::where('customer_id', $customer->id)->count() === $okCount + ($raceReservationId !== null ? 1 : 0);
        } else {
            $res = \App\Models\AiReservation::find($raceReservationId);
            $settledDebits = (int) \App\Models\CreditEntry::where('customer_id', $customer->id)
                ->where('reason', 'ai_reservation')->count();

            $transitions = array_values(array_filter(array_map(fn ($r) => $r['transition'] ?? null, $results)));
            // گذارِ «مؤثر» = حکمِ ok که «قبلاً» نبود؛ ردهای رد (مثل
            // reservation_settled از بازندهٔ مسابقه) اثرِ مالی نداشته‌اند
            $effective = count(array_filter($transitions,
                fn ($t) => str_starts_with((string) $t, 'ok') && ! str_ends_with((string) $t, ':already')));

            $checks['C_no_lost_transition'] = count($transitions) === $workers; // هر فرزند یک حکم گرفت
            $checks['D_no_duplicate_debit'] = $settledDebits <= 1;
            $checks['E_F_duplicates_idempotent'] = $effective <= 1;           // فقط یک گذارِ مؤثر
            $checks['G_race_converges_one_terminal_state'] = $res !== null && in_array($res->status, ['settled', 'released'], true);
            $checks['I_ledger_matches_outcome'] = $ledgerSum === $credit - ($res !== null && $res->status === 'settled' ? $amount : 0);
        }

        $pass = ! in_array(false, $checks, true);

        $this->table(['check', 'pass'], array_map(fn ($k, $v) => [$k, $v ? '✅' : '❌'], array_keys($checks), $checks));
        $this->line("ok={$okCount} already={$already} ledger=".number_format($ledgerSum).' held='.number_format($held));

        if (! $pass) {
            $this->error('🔴 فشارِ هم‌زمانی ناوردایی را شکست.');

            return self::FAILURE;
        }

        $this->info('✅ همهٔ ناوردایی‌هایِ هم‌زمانی برقرارند.');

        return self::SUCCESS;
    }
}
