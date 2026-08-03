<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Provisioning\WhmInventory;
use Illuminate\Console\Command;

/**
 * `whm:scan` — گزارشِ **فقط‌خواندنیِ** تطبیقِ یک سرورِ WHM با سامانه.
 *
 * هیچ‌چیز نمی‌نویسد. عمداً قدمِ اولِ «افزودنِ مشتریانِ قدیمی» است: پیش از اینکه
 * به واردکنندهٔ خودکار اعتماد کنیم باید ببینیم چه چیزی روی سرور هست، کدام
 * حساب‌ها مشتریِ متناظر دارند، و کدام‌ها ایمیلِ قابلِ استفاده ندارند.
 *
 * ⚠️ قیمت و دورهٔ صورت‌حساب را WHM **نمی‌داند**. آن‌ها ورودیِ تجاری‌اند و باید
 * حسابِ‌به‌حساب تصمیم گرفته شوند — به همین دلیل این فرمان وارد نمی‌کند.
 */
class ScanWhmAccounts extends Command
{
    protected $signature = 'whm:scan {server? : شناسه یا نامِ سرور}';

    protected $description = 'گزارشِ تطبیقِ حساب‌های WHM با سرویس‌های ثبت‌شده (بدونِ تغییر)';

    public function handle(WhmInventory $inventory): int
    {
        $servers = $this->targets();

        if ($servers->isEmpty()) {
            $this->error('سرورِ WHMی پیدا نشد.');

            return self::FAILURE;
        }

        foreach ($servers as $server) {
            $this->line('');
            $this->info("── {$server->name} ({$server->hostname}) ──");

            $r = $inventory->reconcile($server);

            if (! $r['ok']) {
                // «نتوانستیم بپرسیم» با «چیزی نیست» یکی نیست
                $this->error('خوانده نشد: '.$r['message']);

                continue;
            }

            $this->reportOrphans($r['orphans']);
            $this->reportGhosts($r['ghosts']);
            $this->reportDrift($r['matched']);

            $this->line(sprintf(
                'جمع: %d حسابِ روی سرور · %d وصل · %d بی‌سرویس · %d سرویسِ بی‌حساب',
                count($r['orphans']) + count($r['matched']),
                count($r['matched']),
                count($r['orphans']),
                count($r['ghosts']),
            ));

            if ($r['orphans'] !== []) {
                $this->warnAboutBilling();
            }
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Server> */
    private function targets()
    {
        $arg = $this->argument('server');

        return Server::where('type', 'whm')
            ->when($arg, fn ($q) => $q->where(fn ($w) => $w->where('id', $arg)->orWhere('name', $arg)))
            ->get();
    }

    private function reportOrphans(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->warn('حساب‌هایی که در سامانه سرویس ندارند ('.count($rows).'):');

        $this->table(
            ['کاربر', 'دامنه', 'ایمیل', 'پکیج', 'ساخته‌شده', 'معلق', 'مشتریِ متناظر'],
            array_map(fn ($a) => [
                $a['user'], $a['domain'], $a['email'] ?: '—', $a['plan'],
                // ⚠️ تاریخِ ساخت تنها چیزی است که WHM دربارهٔ **زمان** می‌داند
                // و لنگرِ صورت‌حساب از رویش تخمین زده می‌شود. قبلاً خوانده
                // می‌شد و چاپ نمی‌شد — یعنی تصمیمی که این گزارش قرار بود
                // ممکن کند، بی‌داده می‌مانْد.
                $this->started($a['started'] ?? null),
                $a['suspended'] ? 'بله' : '—',
                // ایمیلِ ناقص یعنی نمی‌شود وارد کرد: ستونِ ایمیلِ مشتری یکتا و
                // ناتهی است و آدرسِ ساختگی فضای نامِ ورود را آلوده می‌کند.
                $a['customer_id'] ? '#'.$a['customer_id'] : ($a['email_usable'] ? 'مشتری ندارد' : '⚠️ ایمیلِ نامعتبر'),
            ], $rows)
        );
    }

    /** تاریخِ ساختِ حساب (WHM یا timestamp می‌دهد یا رشته) */
    private function started(mixed $v): string
    {
        if (blank($v)) {
            return '—';
        }

        try {
            return sdate(is_numeric($v)
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $v)
                : \Illuminate\Support\Carbon::parse((string) $v));
        } catch (\Throwable) {
            return (string) $v;
        }
    }

    /**
     * 🔴 هشدارِ زنجیرهٔ کرون — دقیقاً همان چیزی که این پروژه یک‌بار ازش سوخت.
     *
     * این گزارش فهرستِ نامزدهای واردکردن را می‌دهد، ولی خودِ واردکردن یک تلهٔ
     * شناخته‌شده دارد: سرویسی که با سررسیدِ گذشته (یا حتی چند روز آینده) ثبت
     * شود، ۰۷:۰۰ فاکتور می‌خورد و ۰۷:۳۰ همان صبح واقعاً تعلیق می‌شود — یعنی
     * سایتِ زندهٔ مشتریِ قدیمی می‌خوابد. بی‌این هشدار، گزارش آدم را به سمتِ
     * همان اشتباه هُل می‌دهد.
     */
    private function warnAboutBilling(): void
    {
        $this->line('');
        $this->warn('⚠️ پیش از واردکردنِ هر حساب:');
        $this->line('   • قیمت و دوره را WHM نمی‌داند — باید حساب‌به‌حساب تعیین شوند.');
        $this->line('   • سررسیدِ تمدید باید دستِ‌کم ۶ روز آینده باشد. کرونِ `services:renew-due`');
        $this->line('     هر سرویسِ فعالی را که تا ۵ روزِ آینده سررسید دارد فاکتور می‌کند و');
        $this->line('     `services:lifecycle` بابتِ همان فاکتورِ پرداخت‌نشده سرور را خاموش می‌کند.');
        $this->line('   • فهرستِ بالا فقط حساب‌هایی است که **این توکن** می‌بیند. توکنِ نمایندگی');
        $this->line('     همهٔ حساب‌های سرور را نشان نمی‌دهد.');
    }
    private function reportGhosts(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->warn('سرویس‌هایی که حسابشان روی سرور نیست ('.count($rows).'):');

        $this->table(
            ['سرویس', 'کاربر', 'دامنه', 'وضعیتِ ما', 'مشتری'],
            array_map(fn ($g) => [
                '#'.$g['service_id'].' '.$g['service_name'],
                $g['user'] ?: '—', $g['domain'] ?: '—', $g['our_status'], $g['customer_code'] ?: '—',
            ], $rows)
        );
    }

    private function reportDrift(array $rows): void
    {
        $drift = array_values(array_filter($rows, fn ($m) => $m['status_drift']));

        if ($drift === []) {
            return;
        }

        $this->warn('اختلافِ وضعیت — پنل و سرور یکی نمی‌گویند ('.count($drift).'):');

        $this->table(
            ['سرویس', 'کاربر', 'روی سرور', 'در پنل'],
            array_map(fn ($m) => [
                '#'.$m['service_id'].' '.$m['service_name'], $m['user'],
                $m['suspended'] ? 'معلق' : 'فعال', $m['our_status'],
            ], $drift)
        );
    }
}
