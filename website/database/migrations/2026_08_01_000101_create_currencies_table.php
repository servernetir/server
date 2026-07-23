<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ارزها — منبع یگانهٔ حقیقت برای تفسیر مبالغ.
 *
 * قاعدهٔ مطلق پروژه: هر مبلغ یک BIGINT در «واحد فرعی» است.
 * exponent می‌گوید آن عدد را چطور بخوانیم:
 *   IRT exponent 0  →  490000 یعنی ۴۹۰٬۰۰۰ تومان
 *   EUR exponent 2  →  1290   یعنی €12.90
 * هیچ float و هیچ DECIMAL برای پول در کل سیستم نداریم.
 *
 * تومان است نه ریال — اشتباه ضربدر ۱۰ یک بار در این پروژه اتفاق افتاده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();          // IRT | EUR
            $table->unsignedTinyInteger('exponent');      // تعداد رقم اعشار واحد فرعی
            $table->unsignedInteger('rounding_step')->default(1);
            $table->string('symbol', 8)->default('');
            $table->boolean('symbol_before')->default(false);
            $table->boolean('is_base')->default(false);   // دقیقاً یکی per install
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
