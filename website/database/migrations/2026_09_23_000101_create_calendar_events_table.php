<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * رویدادهای **دستیِ** تقویمِ کسب‌وکار.
 *
 * این جدول فقط چیزهایی را نگه می‌دارد که مدیر خودش می‌نویسد (یادآوری، کار،
 * قرار). تمدیدِ دامنه و سررسیدِ فاکتور این‌جا **کپی نمی‌شوند** — آن‌ها در
 * لحظهٔ نمایش از جدول‌های اصلی خوانده می‌شوند (`app/Services/Calendar/Providers`).
 *
 * 🔴 چرا کپی نمی‌کنیم: یک کپیِ روزانه یعنی دو منبعِ حقیقت. فاکتوری که پرداخت
 * می‌شود یا دامنه‌ای که تمدید می‌شود، ردیفِ کپی‌شده‌اش کهنه می‌مانَد و تقویم
 * سررسیدی را نشان می‌دهد که دیگر وجود ندارد — همان الگوی «ناظری که از ستونِ
 * همسایه می‌پرسد نه از خودِ خرابی» که در CLAUDE.md گران تمام شده.
 *
 * ⚠️ `type` عمداً `enum` نیست بلکه `string` است — دقیقاً مثلِ `posts.status` و
 * `invoices.status` در همین پروژه. دلیلش پرتابل‌بودن (SQLite محلی، MariaDB
 * پروداکشن) و این است که افزودنِ یک لایهٔ تازه نباید مهاجرتِ `ALTER` بخواهد.
 * مقادیرِ مجاز در `config/calendar.php` تعریف و در کنترلر با `Rule::in`
 * اعتبارسنجی می‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();

            // domain_renewal | hosting_renewal | payment_due | social_post | task
            $table->string('type', 24)->index();

            $table->string('title', 200);
            $table->text('description')->nullable();

            // روزِ تقویمی (میلادیِ ذخیره‌شده؛ نمایشِ شمسی کارِ لایهٔ نمایش است)
            $table->date('event_date');

            // شناسه‌های مرتبط، مبلغ، و هر زمینه‌ای که رویداد را قابلِ پیگیری کند
            $table->json('meta')->nullable();

            // pending | done | cancelled
            $table->string('status', 12)->default('pending');

            /*
             * سازندهٔ رویداد. **نال‌پذیر و nullOnDelete** است، نه cascade:
             * تقویم کسب‌وکار مشترک است، پس رفتنِ یک همکار نباید یادآوری‌های
             * تیم را با خودش ببرد. تنها چیزی که از دست می‌رود «چه کسی نوشت» است.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // پرس‌وجوی همیشگی: «رویدادهای این بازه، از این لایه‌ها»
            $table->index(['event_date', 'type']);
            $table->index(['status', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
