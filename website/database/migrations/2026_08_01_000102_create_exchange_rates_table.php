<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاریخچهٔ نرخ ارز.
 *
 * ⚠️ فقط برای گزارش حاشیهٔ سود و نمایش تقریبی. قیمتی که از مشتری گرفته
 * می‌شود هرگز با این محاسبه نمی‌شود — هر نصب قیمت‌های ارز پایهٔ خودش را دارد.
 * تنها استثنا: قیمت دامنه که کارفرما خواست از دلار زنده ساخته شود.
 *
 * rate یک نسبت است نه پول، پس DECIMAL اینجا مجاز است؛ ولی در PHP هرگز به
 * float کست نمی‌شود — با bcmath یا ریاضی صحیح کار می‌کنیم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);      // مثلاً USD
            $table->char('quote', 3);     // مثلاً IRT
            $table->decimal('rate', 24, 10);
            $table->string('source', 32); // alanchand | manual | ecb
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['base', 'quote', 'fetched_at']);
            $table->index(['base', 'quote', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
