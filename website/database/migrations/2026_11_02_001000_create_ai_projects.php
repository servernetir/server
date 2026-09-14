<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| پروژه‌های AI — ظرفِ پرداختِ آینده روی رجیستریِ M1
|--------------------------------------------------------------------------
|
| یک «پروژه» میزِ admission است، نه میزِ پول: توکنِ AI به پروژه گره می‌خورد،
| و فعال/غیرفعال بودنِ پروژه یعنی کلیدهایش خواندنی‌اند یا نه. بودجه هم همین
| ظاهر را دارد — فقط **ذخیره** می‌شود؛ M2 هیچ پولی جابه‌جا نمی‌کند.
|
| 🔴 بودجه روی همین جدول (نه جدولِ جدا) و **صحیح**: monthly_budget_irt به
|    تومانِ صحیح — هم‌سو با daily_spend_cap_irt توکن. ارزِ AI در مقیاسِ
|    میکرو-واحدِ M1 است و تبدیلش کارِ تسویه (M5)؛ این‌جا فقط سقفِ انتخابیِ
|    مشتری به تومان، بی‌هیچ float.
|
| 🔴 غیرفعال‌سازی/آرشیوِ پروژه هرگز توکن را از بین نمی‌برد ولی **هیچ کلید
|    فعالی قابلِ استفاده باقی نمی‌گذارد**: سیمِ admission (AiAdmission)
|    «پروژهِ غیرفعال» و «پروژهِ گم‌شده» را رد می‌کند — و آرشیو، انتهایی
|    است و توکنِ AIِ آن پروژه را نرم ابطال می‌کند تا حتی رابطِ صدور هم
|    به آن اعتماد نکند.
*/

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_projects')) {
            return;
        }

        Schema::create('ai_projects', function (Blueprint $table) {
            $table->id();
            /* مالک با همان قراردادِ مشتریِ پنل — cascade مثل بقیهٔ تِبِل‌های حساب */
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            /* شناسهٔ پایدار — یکتا **در حسابِ مشتری** (نه سراسری)، تا هر حساب
               بتواند «default» خودش را داشته باشد و /v1 آن را نشانی بدهد */
            $table->string('slug', 120);

            /* active = کلیدهای AI زنده · disable = برخاستنی · archive = پایانی */
            $table->string('status', 16)->default('active');

            /* ── بودجهٔ سطحِ پروژه (M2 فقط ذخیره؛ رسیدنِ پول M3+) ──
               null ماه‌بودجه = بی‌سقف؛ صفر عمداً هرگز همین‌جا نوشته نمی‌شود. */
            $table->unsignedBigInteger('monthly_budget_irt')->nullable();
            $table->string('budget_period', 12)->default('none');       // none|monthly
            $table->unsignedTinyInteger('budget_reset_day')->nullable(); // 1..28؛ null = پایانِ ماه
            /* فرازِ مِتا داده: بازهٔ محاسبه‌شدهٔ آخرین سیکل — موتور admission
               بدونِ وابستگی به درستیِ کرون، همین وکتورِ قطعی را می‌خواند. */
            $table->timestamp('budget_window_from')->nullable();
            $table->timestamp('budget_window_until')->nullable();
            $table->string('budget_window_key', 10)->nullable();         // «2026-11»؛ شاهِ قطعیت

            $table->timestamps();

            $table->unique(['customer_id', 'slug']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_projects');
    }
};
