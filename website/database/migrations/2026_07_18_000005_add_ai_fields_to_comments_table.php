<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فیلدهای هوش مصنوعی برای کامنت‌ها:
 *  - داوری خودکار اسپم (approve / review / spam)
 *  - ترجمه‌ی خودکار متن کامنت به سه زبان
 *  - پاسخ هوشمند به پرسش‌های کاربران
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('locale', 5)->default('fa')->after('body');     // زبان اصلی کامنت
            $table->string('ai_verdict', 12)->nullable()->after('approved'); // approve|review|spam
            $table->unsignedTinyInteger('ai_score')->nullable()->after('ai_verdict'); // احتمال اسپم ۰..۱۰۰
            $table->string('ai_reason', 200)->nullable()->after('ai_score'); // دلیل کوتاه برای مدیر
            $table->json('translations')->nullable()->after('ai_reason');    // {en:…, tr:…, fa:…}
            $table->text('reply')->nullable()->after('translations');        // پاسخ هوشمند (زبان اصلی)
            $table->json('reply_translations')->nullable()->after('reply');
            $table->timestamp('replied_at')->nullable()->after('reply_translations');
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn([
                'locale', 'ai_verdict', 'ai_score', 'ai_reason',
                'translations', 'reply', 'reply_translations', 'replied_at',
            ]);
        });
    }
};
