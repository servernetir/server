<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فروشِ ساعتیِ سرورِ ابری — پیش‌پرداخت از کیفِ پول.
 *
 * چرا جدا از «چرخه» (cycle): چرخه فاکتور-محور و ماهانه/فصلی است؛ ساعتی
 * متر-محور است و هر ساعت از اعتبارِ مشتری کم می‌شود (مثلِ خودِ زیرساخت‌ها که
 * ساعتی از اعتبارِ ما کم می‌کنند). پس یک `billing_mode` جدا داریم.
 *
 * قاعده‌ها (تأییدِ کارفرما): حداقلِ اعتبار برای شروع = ۲۴ ساعت؛ **بدونِ حداقلِ
 * مصرف** — مشتری می‌تواند بعد از ۱ ساعت هم لغو کند و اعتبارِ استفاده‌نشده در
 * کیفش می‌ماند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            // cycle = ماهانه/فصلی/… (فاکتور-محور) | hourly = ساعتی (متر-محور)
            $table->string('billing_mode', 10)->default('cycle')->after('cycle');
            // نرخِ ساعتی که در لحظهٔ خرید قفل می‌شود (تومان و سنتِ یورو)
            $table->unsignedBigInteger('hourly_rate_irt')->nullable()->after('billing_mode');
            $table->unsignedInteger('hourly_rate_eur')->nullable()->after('hourly_rate_irt');
            // آخرین ساعتی که کسر شد — پایهٔ idempotency (در یک ساعت دوبار کسر نکن)
            $table->timestamp('last_metered_at')->nullable()->after('hourly_rate_eur');
            // رفتارِ پایانِ اعتبار (انتخابِ مشتری): تعلیق / تبدیل‌به‌ماهانه / حذف
            $table->string('on_credit_out', 12)->default('suspend')->after('last_metered_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'hourly_rate_irt', 'hourly_rate_eur', 'last_metered_at', 'on_credit_out']);
        });
    }
};
