<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * برنامهٔ نمایندگی دامنه — سطح‌بندی مبتنی بر حجم فروش + دفترِ حسابرسیِ API.
 *
 * ═══ چرا سطح در ستون ذخیره می‌شود و لحظه‌ای حساب نمی‌شود ═══
 *
 * قیمتی که API به نمایندهٔ ما می‌دهد باید **قابلِ توضیح** باشد. اگر سطح در
 * لحظهٔ هر درخواست از روی جدول فاکتورها حساب شود، دو چیزِ بد هم‌زمان رخ
 * می‌دهد: قیمتِ صبح با قیمتِ ظهر فرق می‌کند (چون یک فاکتور از پنجرهٔ ۱۲ ماهه
 * بیرون افتاده)، و هر استعلامِ قیمت یک `sum()` روی کلِ تاریخچه می‌زند.
 *
 * پس سطح یک **عکسِ روزانه** است: کرونِ `domains:reseller-tiers` آن را
 * به‌روز می‌کند و همه‌جا — API، پنل، فاکتور — از همین یک ستون می‌خوانند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // فعال‌بودنِ برنامه برای این مشتری. مدیر روشنش می‌کند، نه خودِ مشتری:
            // نمایندگی یک قرارداد است، نه یک چک‌باکس.
            $table->boolean('is_reseller')->default(false)->after('status');
            $table->timestamp('reseller_joined_at')->nullable()->after('is_reseller');

            /*
            | سطحِ فعلی — کلیدِ یکی از ردیف‌های `config/domain_reseller.levels`.
            |
            | ⚠️ کلیدِ متنی و نه شمارهٔ سطح: با شماره، افزودنِ یک پلهٔ میانی
            | سطحِ همهٔ نمایندگانِ موجود را جابه‌جا می‌کرد.
            */
            $table->string('reseller_level', 24)->nullable()->after('reseller_joined_at');

            // حجمِ اندازه‌گیری‌شده در پنجره (تعداد تراکنشِ دامنه) — همان عددی که
            // در پنلِ نماینده به‌عنوان «پیشرفت تا سطح بعد» نشان داده می‌شود.
            $table->unsignedInteger('reseller_volume')->default(0)->after('reseller_level');
            $table->timestamp('reseller_level_reviewed_at')->nullable()->after('reseller_volume');

            /*
            | 🔴 سقوطِ سطح تا این تاریخ ممنوع است.
            |
            | بی‌این، نماینده‌ای که یک ماهِ کم‌فروش داشته صبح از دست می‌دهد چیزی
            | را که یک سال ساخته — و همان روز سراغِ رقیب می‌رود. مهلت یعنی
            | «می‌بینیم، ولی هنوز فرصت داری».
            */
            $table->timestamp('reseller_level_locked_until')->nullable()->after('reseller_level_reviewed_at');

            /*
            | تخفیفِ دستیِ مذاکره‌شده، **روی** تخفیفِ سطح.
            |
            | ⚠️ این عدد از کفِ حاشیه عبور نمی‌کند — همان گیتی که تخفیفِ سطح
            | را می‌گیرد، این را هم می‌گیرد. وگرنه یک «۴۰٪ به این آقا بده»ی
            | شفاهی، پسوندهای کم‌حاشیه را زیرِ قیمتِ خرید می‌فروخت.
            */
            $table->unsignedSmallInteger('reseller_bonus_pct')->default(0)->after('reseller_level_locked_until');

            /*
            | سقفِ خرجِ روزانه از اعتبار، از راهِ API. صفر = پیش‌فرضِ config.
            |
            | 🔴 این محافظِ «توکن لو رفت» است. توکنِ نوشتنی روی سرورِ WHMCSِ
            | نماینده می‌نشیند — سروری که ما هیچ کنترلی رویش نداریم. بی‌سقف،
            | یک نشتِ توکن یعنی کلِ اعتبارِ نماینده در چند دقیقه به دامنه‌های
            | بی‌مصرف تبدیل می‌شود و پولش نزدِ رجیستری می‌مانَد، نه نزدِ ما.
            */
            $table->unsignedBigInteger('reseller_daily_cap_irt')->default(0)->after('reseller_bonus_pct');

            $table->index('is_reseller');
        });

        /*
        | دفترِ حسابرسیِ APIِ نمایندگی.
        |
        | ⚠️ عمداً جدولِ جدا و نه `activity_logs`: این ردیف‌ها ماشین‌تولیدند و
        | حجمشان چند مرتبه بیشتر است؛ ریختنشان در لاگِ فعالیت، تاریخچهٔ
        | انسانیِ سرویس‌ها را غیرقابلِ خواندن می‌کرد.
        |
        | 🔴 هیچ **بدنهٔ کاملی** ذخیره نمی‌شود. بدنهٔ ثبتِ دامنه شاملِ نام و
        | نشانی و تلفنِ مالک است؛ لاگی که آن را نگه دارد، خودش به یک نشتِ
        | داده تبدیل می‌شود — همان چیزی که قرار بود جلویش را بگیرد.
        */
        Schema::create('reseller_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()
                ->constrained('customer_api_tokens')->nullOnDelete();

            $table->string('action', 40);              // check | register | renew | ns | lock | …
            $table->string('domain', 253)->nullable();
            $table->boolean('ok')->default(false);
            $table->string('error_code', 40)->nullable();

            // مبلغِ کسرشده از اعتبار (تومان) — صفر برای عملیاتِ رایگان
            $table->unsignedBigInteger('amount_irt')->default(0);

            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();

            /*
            | کلیدِ یکتاسازیِ درخواست. نمایندهٔ محتاط `Idempotency-Key` می‌فرستد
            | و اگر همان کلید دوباره بیاید، همان پاسخِ قبلی برمی‌گردد بی‌آنکه
            | دامنهٔ دومی خریده شود.
            |
            | ⚠️ یکتایی **مرکب** با `customer_id` است نه سراسری: کلیدِ تصادفیِ
            | یک نماینده نباید بتواند درخواستِ نمایندهٔ دیگر را مسدود کند.
            */
            $table->string('idempotency_key', 80)->nullable();
            $table->json('response')->nullable();      // پاسخِ خلاصه‌شده، برای پخشِ دوباره

            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['customer_id', 'action']);
            $table->unique(['customer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_api_logs');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['is_reseller']);
            $table->dropColumn([
                'is_reseller', 'reseller_joined_at', 'reseller_level', 'reseller_volume',
                'reseller_level_reviewed_at', 'reseller_level_locked_until',
                'reseller_bonus_pct', 'reseller_daily_cap_irt',
            ]);
        });
    }
};
