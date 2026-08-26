<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بهای **تمدید** نزدِ رجیسترار — ستونی که نبودش دو کفِ محافظ را کور کرده بود.
 *
 * ═══ چرا لازم شد (ممیزیِ شهریور ۱۴۰۵) ═══
 *
 * 🔴 `cost_amount` بهای **سالِ اول** است و سالِ اولِ بیشترِ پسوندها تبلیغاتی.
 * هر دو کفِ ارزی (تمدیدِ نمایندگی، و حالا تمدیدِ خرده‌فروشی) از همان
 * `cost_amount` حساب می‌شدند؛ برای `.shop` یعنی کفِ ~€2 در برابرِ بهای واقعیِ
 * تمدیدِ €14.90 — محافظی که هست ولی نمی‌گیرد.
 *
 * قیمتِ تمدیدِ رجیسترار در همان پاسخِ check هست و تا امروز «خوانده و دور
 * ریخته» می‌شد (بدهیِ دادهٔ ثبت‌شده در CLAUDE.md). حالا در quote و از آن‌جا
 * روی ردیفِ دامنه می‌نشیند.
 *
 * ⚠️ nullable و بدونِ backfill: ردیف‌های قدیمی این عدد را ندارند و جعل‌کردنش
 * بدتر از نبودنش است — کف برای آن‌ها مثلِ قبل از `cost_amount` حساب می‌شود.
 * واحد همان واحدِ فرعیِ `cost_currency` است (سنتِ یورو/دلار)، مثلِ خودِ
 * `cost_amount`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_renew_amount')->nullable()->after('cost_amount');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_renew_amount')->nullable()->after('cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('domain_quotes', function (Blueprint $table) {
            $table->dropColumn('cost_renew_amount');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('cost_renew_amount');
        });
    }
};
