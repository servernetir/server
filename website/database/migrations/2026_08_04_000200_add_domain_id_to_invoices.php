<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیوندِ فاکتور به دامنه — دقیقاً موازیِ `invoices.service_id`.
 *
 * چرا ستونِ جدا و نه استفاده از `service_id`: دامنه سرویس نیست. اگر شناسهٔ
 * دامنه را در `service_id` می‌گذاشتیم، هر پرس‌وجویی که روی سرویس‌ها join
 * می‌زند به ردیفِ ناموجود می‌خورد و — بدتر — منطقِ فعال‌سازیِ سرویس در
 * `PaymentService` روی یک شناسهٔ بی‌ربط اجرا می‌شد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->after('service_id')
                ->constrained('domains')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('domain_id');
        });
    }
};
