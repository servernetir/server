<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Domain\DomainRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `domains:renew` — تمدیدِ دامنه‌هایی که فاکتورِ تمدیدشان پرداخت شده.
 *
 * خواهرِ `domains:provision` است و همان قاعده: تماسِ شبکه‌ای **بیرونِ** وب‌هوکِ
 * درگاه. یک رجیسترارِ کُند نباید وب‌هوکِ پرداخت را timeout کند، وگرنه درگاه
 * پرداخت را ناموفق فرض می‌کند و پول برمی‌گردد در حالی که دامنه تمدید شده.
 *
 * ⚠️ صفِ این فرمان با صفِ `domains:provision` **بی‌اشتراک** است:
 * `awaitingRenewal` روی `status='active'` و `awaitingRegistration` روی
 * `status='pending'`. اگر روزی یکی از این دو شرط را برداشتی، یک ثبتِ تازه
 * می‌تواند به‌جای تمدید پردازش شود (یا برعکس) و دامنه دوباره خریده شود.
 */
class RunDomainRenewal extends Command
{
    protected $signature = 'domains:renew {--limit=10 : بیشینهٔ دامنه در هر اجرا} {--id= : فقط همین دامنه}';

    protected $description = 'تمدیدِ دامنه‌های پرداخت‌شده نزدِ رجیسترار';

    public function handle(DomainRegistrar $registrar): int
    {
        if (! Schema::hasTable('domains')) {
            return self::SUCCESS;
        }

        $q = Domain::query()->awaitingRenewal()->with('customer');

        if ($id = $this->option('id')) {
            $q->whereKey($id);
        }

        $domains = $q->orderBy('expires_at')->limit((int) $this->option('limit'))->get();

        /*
        | 🔴 صفِ **بازیابی** هم همین‌جا مصرف می‌شود (شهریور ۱۴۰۵): دامنهٔ
        | منقضی که نجاتش پرداخت شده. اسکوپش روی `status='expired'` سوار است —
        | بی‌اشتراک با تمدید (`active`) و ثبت (`pending`)، پس هیچ ردیفی
        | دوبار برداشته نمی‌شود.
        */
        $restores = Domain::query()->awaitingRestore()->with('customer')
            ->when($this->option('id'), fn ($w, $id) => $w->whereKey($id))
            ->orderBy('expires_at')->limit((int) $this->option('limit'))->get();

        if ($domains->isEmpty() && $restores->isEmpty()) {
            $this->line('چیزی در صفِ تمدید نیست.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($restores as $domain) {
            $res = $registrar->restorePaid($domain);

            if ($res['ok']) {
                $ok++;
                $this->info('✓ بازیابی: '.$domain->domain);

                continue;
            }

            $failed++;
            $this->{$res['manual'] ? 'error' : 'warn'}(
                ($res['manual'] ? '✗ بازیابی، نیازِ بررسیِ دستی: ' : '… بازیابی، تلاشِ دوباره: ').$domain->domain.' — '.$res['message']
            );
        }

        foreach ($domains as $domain) {
            $res = $registrar->renewPaid($domain);

            if ($res['ok']) {
                $ok++;
                $this->info('✓ '.$domain->domain.' تا '.($domain->fresh()?->expires_at?->toDateString() ?? '—'));

                continue;
            }

            $failed++;
            $this->{$res['manual'] ? 'error' : 'warn'}(
                ($res['manual'] ? '✗ نیازِ بررسیِ دستی: ' : '… تلاشِ دوباره: ').$domain->domain.' — '.$res['message']
            );
        }

        $this->line("جمع: {$ok} تمدیدشده · {$failed} ناموفق");

        return self::SUCCESS;
    }
}
