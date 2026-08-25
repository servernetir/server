<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ردیف‌های میراثیِ `cloud_locations` را غیرفعال می‌کند.
 *
 * ═══ چرا ═══
 *
 * سینکِ قدیمیِ Aeza نامِ گروهِ محصول را به‌جای شهر در کد می‌گذاشت
 * (ru-intel، de-shared، ws-dedicated…) — باگی که در AezaClient (بخشِ
 * locations) مستند و رفع شد. ولی `syncLocations` هرگز مکانِ ناپدیدشده را
 * غیرفعال نمی‌کند (پرچم مالِ مدیر است)، پس این ردیف‌ها فعال ماندند و
 * صفحاتِ `/cloud/{code}` با «۰ پلن، از —» در هر سه زبان رندر شدند.
 * Search Console همین‌ها را «Duplicate, Google chose different canonical»
 * گزارش کرد — ۲۱ صفحه، ممیزی ۲۴ اوت ۲۰۲۶.
 *
 * ═══ چرا الگو + شرطِ بی‌پلنی، نه فهرستِ خشک ═══
 *
 * شناسهٔ ساختگی همیشه «گروهِ محصول به‌جای شهر» است (‎-shared، ‎-dedicated،
 * ‎-intel، ‎-amd، ‎-promo، ‎-hi-cpu‏) یا کشورِ خیالیِ `ws-`. شهرِ واقعی با این
 * نام‌ها وجود ندارد (de-falkenstein، ir-tehran، us-ashburn-va…). شرطِ
 * «هیچ پلنِ قابل‌فروشی ندارد» هم ضامنِ دوم است: اگر روزی کدی با این الگو
 * واقعاً بفروشد، دست نمی‌خورد.
 *
 * ⚠️ برگشت‌پذیر از پنل: /admin/cloud/locations/{code}/toggle.
 */
return new class extends Migration
{
    private const LEGACY_SUFFIX = '(shared|dedicated|intel|amd|promo|hi-cpu)';

    public function up(): void
    {
        if (! Schema::hasTable('cloud_locations') || ! Schema::hasTable('cloud_plans')) {
            return;
        }

        $sellable = DB::table('cloud_plans')
            ->where('is_active', true)
            ->where('in_stock', true)
            ->where('price_irt', '>', 0)
            ->where('admin_disabled', false)
            ->distinct()
            ->pluck('location_code')
            ->all();

        DB::table('cloud_locations')
            ->where('is_active', true)
            ->where(function ($q) {
                // REGEXP در MariaDB و SQLite (نسخهٔ تست) هر دو هست؛ برای
                // اطمینان از SQLiteِ بدونِ REGEXP، با LIKE هم می‌پوشانیم.
                $q->where('code', 'like', '%-shared')
                    ->orWhere('code', 'like', '%-dedicated')
                    ->orWhere('code', 'like', '%-intel')
                    ->orWhere('code', 'like', '%-amd')
                    ->orWhere('code', 'like', '%-promo')
                    ->orWhere('code', 'like', '%-hi-cpu')
                    ->orWhere('code', 'like', 'ws-%');
            })
            ->when($sellable !== [], fn ($q) => $q->whereNotIn('code', $sellable))
            ->update(['is_active' => false]);
    }

    /**
     * برگشت عمداً چیزی را فعال نمی‌کند — نمی‌دانیم کدام ردیف‌ها را همین
     * مهاجرت خاموش کرد و کدام‌ها از قبل خاموش بودند.
     */
    public function down(): void
    {
    }
};
