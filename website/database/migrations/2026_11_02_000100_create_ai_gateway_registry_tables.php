<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| رجیستریِ AI — ارائه‌دهنده · مدل · قیمتِ واحد
|--------------------------------------------------------------------------
|
| پایهٔ M1 دروازهٔ AI. سه جدول، همهٔ **افزودنی** — هیچ جدولِ موجودی لمس
| نمی‌شود و هیچ وابستگی نمادین به سرویس‌های طلا/کیفِ پول وجود ندارد؛ M5
| دارند وصل می‌کنند.
|
| 🔴 واحدِ قیمت: عددِ صحیحِ «میکرو-واحد» (یک‌میلیونیوم از ارزِ خودِ
|    سطر، در `currency_code`) — نه «سنت» و نه ارزِ ثابتِ سِدشد.
|    DeepInfra قیمت را دلار اعلام می‌کند (مثل ۰٫۲۵ دلار به ازای ۱M
|    توکن ⇒ ۲۵۰٬۰۰۰ میکرو-دلار)؛ ارائه‌دهندهٔ آینده ممکن است یورو یا
|    ارزیِ دیگر اعلام کند. ارزِ هر سطرِ قیمتِ صریح است و تاریخِ قیمت، ارزِ
|    واقعیِ آن روز را نگه می‌دارد. ذخیره در سنت یعنی گردِ اول، خطا در
|    هر درخواست، و ضررِ بی‌صدا — میکرو-واحد این را می‌بلعد. برای
|    توکن‌ها یکای صورت‌حساب «یک میلیون توکن» است و در `billing_unit`
|    اعلام می‌شود. هیچ float و هیچ DECIMAL در محاسبه — فقط صحیحِ ۶۴بیتی.
|    (نرمال‌سازیِ ارز به پولِ داخلی — M5 معماریِ تسویه است، این‌جا نه.)
|
| 🔴 قیمت‌ها **جایگزین می‌شوند، نه ویرایش**. هر قیمتِ به کار رفته برای یک
| درخواست باید بعداً دقیقاً بازتولیدشودنی باشد؛ پس سطرِ قیمت هرگز عوض
| نمی‌شود — نسخهٔ تازه‌ای با `active=1` می‌آید و قبلی با
| `superseded_at` بسته می‌شود. این قفل در سرویس (`PriceBook`) و تست
| لنگر می‌افتد، نه فقط در رابطِ کاربری.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();          // deepinfra · openai · …
            $table->string('name', 80);                    // نامِ نمایشی
            $table->string('driver', 40);                  // App\Services\AiProviders\<Driver>
            $table->boolean('enabled')->default(false);           // درایور فعالِ فنی
            $table->boolean('commercial_enabled')->default(false); // مکانیِ فروش روشن
            $table->boolean('resale_allowed')->default(false);     // اجازهٔ فروشِ دوباره
            $table->string('agreement_status', 24)->default('none'); // none|requested|signed
            // ترتیبِ preferential بینِ ارائه‌دهنده‌های همردیف — کوچک‌تر جلوتر
            $table->unsignedSmallInteger('priority')->default(100);
            /*
            | پرچمِ تماسِ زنده — پیش‌فرض خاموش. M4 فقط با کانتینرِ ماکی تماس
            | می‌گیرد؛ روشن‌کردنش صریح و دستِ ادمین است (M4 مستند می‌کند). کلیدِ
            | API این‌جا ذخیره نمی‌شود — میراثِ `Setting::putSecret` است.
            */
            $table->boolean('live_calls_enabled')->default(false);
            /* ارزِ پیش‌فرضِ صورت‌حسابِ ارائه‌دهنده — چیزی که سندِ قیمتش
               اعلام می‌کند (deepinfra = USD). فقط پیش‌فرضِ آسایشِ ورودِ
               داده است؛ هر سطرِ قیمتِ خودش `currency_code` دارد و همان
               مرجعِ تاریخیِ درست است، نه این ستون. */
            $table->char('billing_currency_code', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
            $table->index('commercial_enabled');
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            /*
            | شناسهٔ عمومیِ مدل — یکتا در کلِ کش. اگر دو ارائه‌دهنده همان مدل
            | را بدهند، دو ردیف با دو اسلاگِ متفاوت‌اند (مثلِ `@` اسلاگِ
            | عرضه در `cloud_plans` که عمداً یکی نیست) و کانتگِ routing در الگوی
            | «ارزان‌ترِ فعال» در M4 انتخابش را می‌کند.
            */
            $table->string('slug', 80)->unique();
            $table->string('upstream_model', 120);         // شناسهٔ نزدِ ارائه‌دهنده
            $table->string('name', 120);                   // نامِ نمایشی
            $table->string('vendor', 60)->nullable();      // متا · آمازون · …
            $table->text('description')->nullable();

            // کاتگوریِ رِی‌بینش‌پذیر — فیلترینگِ اصلیِ ایندکس
            $table->string('category', 24)->default('chat'); // chat|embedding|image|audio|rerank
            $table->string('status', 24)->default('active');  // active|disabled|deprecated
            $table->foreignId('replacement_model_id')->nullable()
                ->constrained('ai_models')->nullOnDelete();

            $table->unsignedInteger('context_tokens')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();

            /*
            | قابلیت‌ها — JSON، چون فهرستِ پرچم‌ها در حالِ رشد است (M4/M8
            | وصل‌شان می‌کنند) و به ازای هر پرچم کلیک و مهاجرت نمی‌گیریم.
            | فیلدهای === فیلترینگیم => ستونِ صریحِ ایندکس‌دار بالاتر
            | (category · status) و در همین جا می‌مانند.
            */
            $table->json('capabilities')->nullable(); // {reasoning,vision,tool_calling,…}

            $table->unsignedSmallInteger('provider_priority')->default(100);
            $table->string('docs_url', 255)->nullable();
            $table->boolean('claude_code_compatible')->default(false);
            $table->timestamps();

            $table->unique(['ai_provider_id', 'upstream_model']); // هر ارائه‌دهنده هر مدلِ بومی یک بار
            $table->index(['status', 'category']);
            $table->index(['category', 'provider_priority']);
        });

        Schema::create('ai_model_unit_prices', function (Blueprint $table) {
            $table->id();
            /*
            | سلسله‌مراتبِ قیمت: سطر با `ai_model_id` پر = قیمتِ مدل؛
            | فقط `ai_provider_id` پر = پیش‌فرضِ ارائه‌دهنده؛ هر دو نال =
            | پیش‌فرضِ سراسری. `PriceBook` همین ترتیب را می‌سنجد.
            */
            $table->foreignId('ai_provider_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->cascadeOnDelete();
            /* سربارِ M2: نرخِ سطحِ مشتری — در M1 همیشه نال می‌ماند. حذفِ
               مشتری تاریخِ قیمت را نمی‌بلعد (nullOnDelete، نه cascade):
               سطرهای گذشته هم باید بمانند. */
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('unit', 32);       // input|output|cached_input|reasoning_input|image|audio|request|embedding|rerank
            $table->string('billing_unit', 24); // 1m_tokens|image|audio_minute|request
            /* قیمت به **میکرو-واحد** (یک‌میلیونیوم از `currency_code` همین
               سطر) به ازای **یک** `billing_unit`. مثال: ۰٫۲۵ دلار/۱M توکن
               ⇒ price_micro_units=250000، currency_code=USD. ارزِ صریح
               است تا بازتولیدِ تاریخی نرخِ واقعیِ همان روز را نگه دارد. */
            $table->bigInteger('price_micro_units');
            /* کدِ ISO-۴۲۱۷ِ ارزِ این قیمت — ذخیره در ۳ نویسهٔ بزرگِ
               لاتین. هر نسخهٔ قیمت ارزِ خودش را دارد؛ سطرِ تازه ممکن است
               ارزِ متفاوتی با نسخهٔ قبلی داشته باشد. */
            $table->char('currency_code', 3)->default('USD');

            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('superseded_at')->nullable();

            // ردِ حسابرسی — چه کسی / در چه زمینه‌ای نسخهٔ تازه ایجاد کرد
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index(['ai_model_id', 'unit', 'version']);
            $table->index(['ai_model_id', 'active']);
            $table->index(['ai_provider_id', 'active']);
        });

        /*
        | ارائه‌دهندهٔ اولِ برنامه — بدونِ هیچ قیمت، بدونِ هیچ کلید.
        | `seeder` مثلِ پانلِ مدیریت، امن‌کابه‌به‌روز است و هیچ ادعایی
        | از سینک دیتا ندارد؛ برای قیمت باید ادمین به‌صورت دستی ناقل شود.
        */
        $p = \DB::table('ai_providers')->where('slug', 'deepinfra')->first();
        if ($p === null) {
            \DB::table('ai_providers')->insert([
                'slug'       => 'deepinfra',
                'name'       => 'DeepInfra',
                'driver'     => 'DeepInfra',
                'enabled'    => false,
                'commercial_enabled' => false,
                'resale_allowed'     => false,
                'agreement_status'   => 'none',
                'priority'   => 100,
                'live_calls_enabled' => false,
                'notes'      => 'ارائه‌دهندهٔ اولِ دروازه. کلیدِ API به رازنگار Setting::putSecret سپرده می‌شود، نه به این‌جا.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_unit_prices');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
