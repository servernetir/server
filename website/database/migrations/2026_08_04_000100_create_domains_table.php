<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دامنه‌های فروخته‌شده + handle مالکِ هر مشتری نزدِ رسیلری.
 *
 * چرا `domains` از `services` جداست: دامنه چیزهایی دارد که سرویس ندارد
 * (پسوند، nameserver، قفلِ انتقال، کدِ انتقال) و چیزهایی ندارد که سرویس دارد
 * (سرور، نام‌کاربری، پکیج). چپاندنشان در یک جدول یعنی نصفِ ستون‌ها همیشه خالی
 * و هر پرس‌وجو یک `where type=` اضافه. صورت‌حساب اما مشترک می‌مانَد.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | handle مالک در جدولِ **موجودِ** `registry_handles` می‌نشیند، نه جدولی
        | تازه. آن جدول از قبل `role`/`status`/`verified_at` دارد و عمداً
        | ایرنیک را هم پیش‌بینی کرده — دقیقاً همان چیزی که برای `.ir` لازم است.
        |
        | لنگرش `customer_profile_id` است و این **درست** است: مشخصاتِ حقیقی/
        | حقوقیِ مالک آن‌جاست، و WHOIS دقیقاً همان را می‌خواهد. نتیجهٔ عملی:
        | مشتریِ بدونِ پروفایل نمی‌تواند دامنه بخرد — که محدودیتِ ساختگی نیست،
        | واقعاً نمی‌شود بدونِ نام و نشانیِ مالک دامنه ثبت کرد.
        |
        | تنها چیزی که کم داشت: ردی از آنچه به رجیسترار فرستادیم، تا بفهمیم
        | handle کهنه شده و باید به‌روز شود. handle یک‌بار ساخته و بازاستفاده
        | می‌شود؛ اگر مشتری نشانی‌اش را عوض کند و ما handle را به‌روز نکنیم،
        | دامنه‌های قدیمی برای همیشه با دادهٔ غلط در WHOIS می‌مانند.
        */
        Schema::table('registry_handles', function (Blueprint $table) {
            $table->json('sent_data')->nullable()->after('meta');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('domain', 253);           // FQDN کامل: example.com
            $table->string('sld', 190);              // example
            $table->string('tld', 63);               // com
            $table->string('registrar', 24)->default('openprovider');

            // pending → صف تحویل | active → ثبت شد | failed → دستِ مدیر
            // expired/transferred/cancelled → پایانِ عمر
            $table->string('status', 24)->default('pending');

            /*
            | مسیرِ تحویل — دقیقاً همان الگوی `provision_status` سرویس‌ها، چون
            | همان درسِ گران‌قیمت این‌جا هم صدق می‌کند: قفلِ وضعیتیِ اتمی تنها
            | چیزی است که جلوی «دو بار خریدنِ یک دامنه» را می‌گیرد.
            |
            | none | pending | running | done | failed | manual
            */
            $table->string('provision_status', 16)->default('pending');
            $table->unsignedTinyInteger('provision_tries')->default(0);
            $table->text('provision_error')->nullable();

            $table->unsignedBigInteger('op_id')->nullable();      // شناسهٔ دامنه نزدِ رسیلری
            $table->string('owner_handle', 64)->nullable();

            $table->unsignedTinyInteger('period_years')->default(1);
            $table->boolean('auto_renew')->default(true);
            $table->boolean('is_locked')->default(true);          // قفلِ انتقال، پیش‌فرض روشن
            $table->boolean('whois_privacy')->default(false);

            $table->json('name_servers')->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // پول — تومان، عددِ صحیح. هیچ float و هیچ DECIMAL.
            $table->unsignedBigInteger('price_toman')->default(0);
            $table->unsignedBigInteger('renew_toman')->default(0);
            // بهایِ تمام‌شده برای سود و سود ناخالص؛ مثل CloudPlan از JSON پنهان می‌شود
            $table->unsignedBigInteger('cost_amount')->default(0);
            $table->char('cost_currency', 3)->default('EUR');

            $table->foreignId('quote_id')->nullable()->constrained('domain_quotes')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // 🔴 یک دامنه فقط یک بار می‌تواند نزدِ ما زنده باشد. بدونِ این، دو
            // سفارشِ هم‌زمان روی یک نام هر دو به ثبت می‌روند و دومی با خطای
            // رسیلری برمی‌گردد — ولی پولش گرفته شده.
            $table->unique(['domain', 'registrar']);
            $table->index(['customer_id', 'status']);
            $table->index(['provision_status', 'created_at']);
            $table->index('expires_at');
            $table->index('op_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');

        Schema::table('registry_handles', function (Blueprint $table) {
            $table->dropColumn('sent_data');
        });
    }
};
