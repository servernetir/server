<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تیکت پشتیبانی و پیام‌هایش.
 *
 * ═══ چرا پیام‌ها جدا از تیکت ═══
 *
 * یک تیکت یک رشته گفتگوست، نه یک پیام. اگر متن روی خود تیکت بود، فقط اولین
 * پیام جا می‌شد. جدول جدا یعنی هر پاسخ یک ردیف، و ترتیب و نویسنده‌شان
 * روشن می‌ماند.
 *
 * ═══ author_role جدا از author_id ═══
 *
 * پیام یا از مشتری است یا از کارکنان — و این دو در دو جدول کاملاً جدا
 * می‌نشینند (customers و users). یک foreign key نمی‌تواند به هر دو اشاره
 * کند، پس نقش را صریح نگه می‌داریم و id را بدون constraint. این همان
 * تفکیکی است که در کل CMS رعایت شده: مشتری هرگز با کارمند قاطی نمی‌شود.
 *
 * ═══ وضعیت ═══
 *
 *   open      منتظر ماست — تیکت تازه یا مشتری پاسخ داده
 *   answered  پاسخ دادیم، منتظر مشتری
 *   closed    بسته
 *
 * صف پشتیبانی همان «open»هاست. با این سه، «چند تیکت منتظر پاسخ است» یک
 * شمارش ساده می‌شود، نه پیمایش کل گفتگوها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // شمارهٔ عمومی مثل TK-14050712-0007؛ id عددی به مشتری نشان داده نمی‌شود
            $table->string('number', 24)->unique();

            $table->string('subject', 200);
            $table->string('department', 24)->default('technical'); // technical|billing|sales
            $table->string('priority', 12)->default('normal');      // low|normal|high|urgent
            $table->string('status', 12)->default('open');          // open|answered|closed

            // نقش کسی که آخرین پاسخ را داد — برای اینکه فهرست بگوید نوبت کیست
            $table->string('last_reply_role', 12)->default('customer'); // customer|staff
            $table->timestamp('last_reply_at')->nullable();

            // به سرویس/فاکتور وصل می‌شود (اختیاری) تا تیکت در بستر خودش دیده شود
            $table->nullableMorphs('subject_ref');

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            // صف پشتیبانی: تیکت‌های باز به ترتیب قدیمی‌ترین پاسخ
            $table->index(['status', 'last_reply_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            // customer | staff — id بدون constraint چون به دو جدول متفاوت می‌رود
            $table->string('author_role', 12);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 120)->nullable(); // عکس لحظه‌ای، تا حذف کاربر تاریخ را خالی نکند

            $table->text('body');

            // یادداشت داخلی کارکنان — مشتری نمی‌بیند. برای هماهنگی بین تیم.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
