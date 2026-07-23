<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قواعد مالیات — داده‌محور، نه کدمحور.
 *
 * تصمیم کارفرما: ایران ۱۰٪ · خارج ۰٪ — و صریحاً **مستقل از روش پرداخت**.
 * پیشنهاد اولیه «۰٪ فقط با کریپتو» رد شد چون مالیات بر ارزش افزوده بر اساس
 * محل و نوع مشتری تعیین می‌شود نه ابزار پرداخت، و در حسابرسی قابل دفاع نبود.
 *
 * نرخ در «صدم درصد» ذخیره می‌شود تا عدد صحیح بماند: ۱۰٪ = 1000
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);                    // «مالیات بر ارزش افزوده»
            $table->char('country', 2)->nullable();        // IR — null یعنی «هر کشور دیگر»
            $table->string('customer_type', 12)->nullable(); // individual|company — null یعنی هر دو
            $table->string('product_kind', 24)->nullable();   // null یعنی همهٔ محصولات
            $table->unsignedInteger('rate_bp');            // صدم درصد: 1000 = ۱۰٪
            $table->unsignedSmallInteger('priority')->default(0); // خاص‌تر = بالاتر
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
