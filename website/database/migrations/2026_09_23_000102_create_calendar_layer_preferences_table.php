<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «کدام لایه‌های تقویم را می‌بینم» — ترجیحِ شخصیِ هر کاربرِ پنل.
 *
 * برخلافِ `calendar_events` که مشترک است، این جدول کاملاً شخصی است: مدیرِ مالی
 * می‌خواهد سررسیدِ فاکتور را ببیند و نویسندهٔ محتوا تقویمِ انتشار را. اگر ترجیح
 * را در `settings` یا `localStorage` می‌گذاشتیم، اولی همه را به هم گره می‌زد و
 * دومی با هر مرورگرِ تازه پاک می‌شد.
 *
 * ⚠️ نبودِ ردیف یعنی «پیش‌فرض: دیده شود» — نه «پنهان». پس کاربرِ تازه همهٔ
 * لایه‌ها را می‌بیند و لازم نیست هیچ ردیفی از قبل seed شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_layer_preferences', function (Blueprint $table) {
            $table->id();

            // رفتنِ کاربر یعنی رفتنِ ترجیحش — این‌جا cascade درست است، چون
            // ردیف بدونِ صاحبش هیچ معنایی ندارد.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('layer_type', 24);
            $table->boolean('visible')->default(true);

            $table->timestamps();

            // یک ترجیح برای هر لایه به ازای هر کاربر — تکیه‌گاهِ `updateOrCreate`
            $table->unique(['user_id', 'layer_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_layer_preferences');
    }
};
