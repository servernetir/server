<?php

namespace App\Console\Commands;

use App\Models\DomainQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `domains:prune-quotes` — جدولِ استعلام‌ها بی‌سقف بزرگ نشود.
 *
 * ═══ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 هیچ هرسی وجود نداشت. هر جستجوی موفق تا ۶۴ ردیف می‌سازد — هرکدام با
 * **کلِ JSONِ خامِ پاسخِ رجیسترار** — و جستجوی پنل throttle هم ندارد. حتی
 * کرونِ گرم‌کردنِ دفترچهٔ قیمت هر بار برای نامِ کاوشیِ خودش ردیف می‌سازد.
 * روی MariaDBی که سشن و کش هم رویش بوده‌اند، این یک بدهیِ رشدِ واقعی است.
 *
 * قاعدهٔ نگه‌داری:
 *   • استعلامی که ردیفِ `domains` به آن اشاره می‌کند (quote_id) **هرگز** پاک
 *     نمی‌شود — سندِ قیمتِ لحظهٔ فروش است.
 *   • بقیه، ۲ روز بعد از پایانِ پنجرهٔ اعتبارشان پاک می‌شوند: پنجره ۱۵ دقیقه
 *     است، پس ۲ روز یعنی هیچ مسیرِ فروشِ زنده‌ای وسطِ کار بی‌سند نمی‌ماند.
 *
 * ⚠️ حذف تکه‌تکه (chunk) تا قفلِ طولانی روی جدولی که مسیرِ فروش رویش
 * می‌نویسد نگیریم.
 */
class PruneDomainQuotes extends Command
{
    protected $signature = 'domains:prune-quotes {--days=2 : چند روز پس از انقضا نگه داشته شود}';

    protected $description = 'حذفِ استعلام‌های منقضیِ بدونِ ارجاع از domain_quotes';

    public function handle(): int
    {
        if (! Schema::hasTable('domain_quotes')) {
            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $total = 0;

        do {
            $deleted = DomainQuote::query()
                ->where(fn ($w) => $w
                    ->where('honour_until', '<', $cutoff)
                    ->orWhereNull('honour_until'))
                ->where('created_at', '<', $cutoff)
                ->whereNotExists(fn ($q) => $q->from('domains')
                    ->whereColumn('domains.quote_id', 'domain_quotes.id'))
                ->limit(500)
                ->delete();

            $total += $deleted;
        } while ($deleted === 500);

        $this->info('استعلام‌های هرس‌شده: '.$total);

        return self::SUCCESS;
    }
}
