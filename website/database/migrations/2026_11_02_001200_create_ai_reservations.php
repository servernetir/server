<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| رزروِ اعتبار برای دروازهٔ AI — حالتِ نگه‌داشته، پولِ واقعی نه (M3)
|--------------------------------------------------------------------------
|
| 🔴 خودِ رزرو **پول جابه‌جا نمی‌کند** — یک سطرِ «نگه‌داشته» است. موجودیِ
|    در دسترس = جمعِ دفتر − جمعِ رزروهایِ pendingِ زنده. تسویه است که
|    یک بار (و فقط یک بار) سطرِ منفیِ دفتر می‌نویسد.
|
| 🔴 جلوگیری از منفی‌شدن ساختاری است، نه شرطِ نرم: رزرو فقط وقتی
|    می‌نشیند که «جمعِ دفتر − نگه‌داشته‌ها» جوابش را بدهد، و این چک
|    داخلِ قفلِ ردیفِ مشتری انجام می‌شود — همان الگویِ `payCredit` و
|    سفارشِ نمایندگی، نه چکِ بیرونِ تراکنش که دو درخواستِ هم‌زمان
|    یک موجودی را دو بار خرج می‌کنند.
|
| حالت‌ها (کوچک‌ترین ماشین): pending → settled | released | expired.
| هر گذار شرطی و تکرار-آمیز است: settle دوباره دفتر را دو بار نمی‌خرد
| و release دوباره پول را دو بار آزاد نمی‌کند.
*/

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_reservations')) {
            return;
        }

        Schema::create('ai_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            /* دروازهٔ AI فقط تومان است (M1) — ستون برای آینده، مقدار همیشه IRT */
            $table->char('currency_code', 3)->default('IRT');
            $table->unsignedBigInteger('amount_irt');

            /* pending | settled | released | expired — کوچک‌ترین ماشینِ حالت */
            $table->string('status', 12)->default('pending');

            /* idempotency — قیدِ یکتای دیتابیس، نه `if` کوئری‌محور (درسِ نمایندگی) */
            $table->string('idempotency_key', 80)->nullable();
            $table->string('purpose', 32)->default('ai');        // ظرفِ آینده؛ فعلاً ai
            $table->string('reference', 120)->nullable();          // شناسهٔ پروژه/درخواستِ AI

            $table->timestamp('expires_at')->nullable();          // نال = بدونِ مهلت
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            /* درزِ تسویه/ممیزی (M5): سطرِ دفتری که این رزرو را خرج کرد */
            $table->foreignId('ledger_entry_id')->nullable()
                ->constrained('credit_ledger')->nullOnDelete();

            $table->timestamps();

            /* نال‌ها در یونیکِ MySQL چندتایی مجازند — کلیدِ نال از قید رد می‌شود */
            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'expires_at']);   // پویشِ انقضا
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reservations');
    }
};
