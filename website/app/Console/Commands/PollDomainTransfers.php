<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Domain\DomainTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `domains:transfer-poll` — پیگیریِ انتقال‌هایی که به رجیسترار رفته‌اند.
 *
 * ⚠️ این فرمان **هیچ‌چیز سفارش نمی‌دهد**. تنها تماسش خواندنی است و بدترین
 * حالتش یک استعلامِ بی‌فایده است. سفارشِ انتقال فقط از مسیرِ مشتری انجام
 * می‌شود، چون کدِ انتقال ذخیره نمی‌شود و کرون آن را در اختیار ندارد — همان
 * تصمیمِ عمدی که در `DomainTransfer` توضیح داده شده.
 *
 * ⚠️ صفش با هر سه صفِ دیگرِ دامنه **بی‌اشتراک** است:
 *   provision → status=pending  · order_type=register
 *   renew     → status=active
 *   transfer  → order_type=transfer · transfer_status=submitted
 * اگر روزی یکی از این شرط‌ها را برداشتی، یک انتقال می‌تواند به‌جای ثبت پردازش
 * شود و دامنه‌ای که مالِ شخصِ دیگری است «خریداری» شود.
 */
class PollDomainTransfers extends Command
{
    protected $signature = 'domains:transfer-poll
        {--limit=25 : بیشینهٔ دامنه در هر اجرا}
        {--minutes=180 : فاصلهٔ کمینه از آخرین استعلامِ همان دامنه}
        {--id= : فقط همین دامنه}';

    protected $description = 'استعلامِ وضعیتِ انتقال‌های در جریان از رجیسترار';

    public function handle(DomainTransfer $transfers): int
    {
        // روی نصبِ مهاجرت‌نخورده سبز برمی‌گردد تا کلِ schedule:run را نکشد —
        // همان قاعدهٔ `domains:reseller-tiers`.
        if (! Schema::hasTable('domains') || ! Schema::hasColumn('domains', 'transfer_status')) {
            return self::SUCCESS;
        }

        $q = Domain::query()
            ->awaitingTransferResult((int) $this->option('minutes'))
            ->with('customer');

        if ($id = $this->option('id')) {
            // ⚠️ با `--id` فاصلهٔ زمانی برداشته می‌شود: این حالت را یک آدم
            //    عمداً زده و انتظارِ سه ساعت معطلی ندارد.
            $q = Domain::query()->whereKey($id)
                ->where('order_type', 'transfer')
                ->where('transfer_status', 'submitted')
                ->with('customer');
        }

        $rows = $q->orderBy('transfer_submitted_at')->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->line('انتقالی در انتظارِ استعلام نیست.');

            return self::SUCCESS;
        }

        $done = ['completed' => 0, 'rejected' => 0, 'submitted' => 0, 'error' => 0];

        foreach ($rows as $domain) {
            $res = $transfers->poll($domain);

            if (! $res['ok']) {
                $done['error']++;
                $this->warn('… استعلام نشد: '.$domain->domain.' — '.$res['message']);

                continue;
            }

            $done[$res['state']] = ($done[$res['state']] ?? 0) + 1;

            match ($res['state']) {
                'completed' => $this->info('✓ منتقل شد: '.$domain->domain),
                'rejected'  => $this->error('✗ رد شد: '.$domain->domain),
                default     => $this->line('… هنوز در جریان: '.$domain->domain),
            };
        }

        $this->line("جمع: {$done['completed']} منتقل‌شده · {$done['rejected']} ردشده · "
            ."{$done['submitted']} در جریان · {$done['error']} بی‌پاسخ");

        return self::SUCCESS;
    }
}
