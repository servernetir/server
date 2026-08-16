<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دو ستون تا دفترِ مالی بتواند هزینهٔ **تکرارشوندهٔ ماهانه** را هم idempotent
 * ثبت کند.
 *
 * ═══ چرا ایندکسِ موجود جواب نمی‌داد ═══
 *
 * `business_ledger` از قبل `unique(source_type, source_id, kind)` دارد و همان
 * است که تضمین می‌کند یک پرداختِ دوبار-settle‌شده، درآمد را دو بار ثبت نکند.
 * ولی «اجارهٔ سرورِ شمارهٔ ۱۲» یک رویدادِ **ماهانه** است: با آن ایندکس، فقط
 * یک ردیف تا ابد ساخته می‌شد و ماه‌های بعد بی‌صدا رد می‌شدند.
 *
 * 🔴 و راهِ وسوسه‌انگیز — «آن ایندکس را بردار و ماه را هم به کلید اضافه کن» —
 * **خطرناک** است: در MariaDB و SQLite ایندکسِ یکتا چند مقدارِ NULL را مجاز
 * می‌داند، پس ردیف‌های پرداخت که `period` ندارند دیگر هیچ حفاظتی نمی‌داشتند و
 * درآمدِ دوباره‌ثبت‌شده برمی‌گشت. یعنی برای درست‌کردنِ هزینه، تضمینِ درآمد را
 * می‌شکستیم.
 *
 * پس ایندکسِ قدیمی **دست نمی‌خورد** و یک ایندکسِ یکتای دوم اضافه می‌شود که
 * فقط ردیف‌های دوره‌ای را می‌گیرد. ردیف‌های دیگر در هر دو ستونِ تازه NULL‌اند
 * و همان قاعدهٔ «NULLها یکتا شمرده نمی‌شوند» آزادشان می‌گذارد.
 *
 * ⚠️ `period` عمداً رشتهٔ `YYYY-MM` است و نه تاریخ: کلیدِ طبیعیِ این رویداد
 * «ماه» است، نه یک روزِ مشخص. اگر روزِ صورت‌حسابِ سرور عوض شود، همان ماه نباید
 * دوباره ثبت شود.
 *
 * ⚠️ `ref_id` جدا از `source_id` است و عمداً کلیدِ خارجی **ندارد**: اگر سروری
 * حذف شود، سابقهٔ مالیِ ماه‌های گذشته‌اش باید سرِ جایش بماند. یک هزینهٔ
 * پرداخت‌شده با حذفِ ماشین از تاریخ پاک نمی‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_ledger')) {
            return;
        }

        Schema::table('business_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('business_ledger', 'period')) {
                $table->char('period', 7)->nullable()->after('category');   // 2026-08
            }

            if (! Schema::hasColumn('business_ledger', 'ref_id')) {
                $table->unsignedBigInteger('ref_id')->nullable()->after('period');
            }
        });

        // ایندکس جدا اضافه می‌شود تا اگر ستون‌ها از قبل بودند دوباره ساخته نشود
        if (! $this->hasIndex('business_ledger_period_unique')) {
            Schema::table('business_ledger', function (Blueprint $table) {
                $table->unique(['kind', 'category', 'period', 'ref_id'], 'business_ledger_period_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('business_ledger')) {
            return;
        }

        if ($this->hasIndex('business_ledger_period_unique')) {
            Schema::table('business_ledger', function (Blueprint $table) {
                $table->dropUnique('business_ledger_period_unique');
            });
        }

        Schema::table('business_ledger', function (Blueprint $table) {
            foreach (['period', 'ref_id'] as $col) {
                if (Schema::hasColumn('business_ledger', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * ⚠️ `Schema::hasIndex()` در همهٔ درایورها یکسان نیست، پس مستقیم از
     * فهرستِ ایندکس‌های خودِ درایور می‌پرسیم. اجرای دوبارهٔ مهاجرت روی سروری
     * که نیمه‌کاره مانده نباید با «ایندکس تکراری» بترکد.
     */
    private function hasIndex(string $name): bool
    {
        try {
            return collect(Schema::getIndexes('business_ledger'))
                ->contains(fn ($i) => ($i['name'] ?? '') === $name);
        } catch (\Throwable) {
            return false;
        }
    }
};
