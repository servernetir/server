<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهرستِ نامه‌های صندوق‌های مدیریتی.
 *
 * ⚠️ **متنِ کاملِ نامه اینجا ذخیره نمی‌شود** و این یک تصمیم است، نه فراموشی.
 * صندوقِ support@ پر از دادهٔ مشتری است؛ کپی‌کردنش در دیتابیسِ سایت یعنی همان
 * داده در هر بکاپ و هر دامپِ عیب‌یابی تکثیر می‌شود. فقط سرآیند + ۶۰۰ نویسه
 * پیش‌نمایش نگه داشته می‌شود — برای شمردن، دسته‌بندی و گزارش کافی است، و برای
 * خواندنِ کامل لینکِ وب‌میل هست.
 *
 * 🔴 `uid_hash` یکتاست و کلیدِ ضدِ تکرار است: کرون ممکن است چند بار همان بازه
 * را بخواند (عمداً، تا عقب‌ماندگی جبران شود) و نباید یک نامه دو بار در گزارشِ
 * بله بیاید.
 *
 * ستون‌های ایندکس‌دار همه ≤۱۹۰ نویسه‌اند: در utf8mb4 هر نویسه تا ۴ بایت است و
 * سقفِ ایندکسِ InnoDB روی این سرور ۷۶۷ بایت (۱۹۰×۴=۷۶۰).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailbox_messages')) {
            return;
        }

        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->id();

            // کدامیک از صندوق‌ها — کلیدِ config، نه نشانی (نشانی ممکن است عوض شود)
            $table->string('account', 40);

            // sha256(account|message_id) — یکتا، کلیدِ ضدِ تکرار
            $table->string('uid_hash', 64)->unique();
            $table->string('message_id', 190)->nullable();

            $table->string('from_email', 190)->nullable();
            $table->string('from_name', 160)->nullable();
            $table->string('subject', 190)->nullable();
            $table->text('snippet')->nullable();
            $table->timestamp('received_at')->nullable();

            /*
            | نامه‌ای که خودِ سیستم فرستاده و یک‌بار در بله گفته شده.
            | در پنل شمرده می‌شود، در گزارشِ بله هرگز نمی‌آید.
            */
            $table->boolean('is_system')->default(false);

            // خروجیِ عاملِ هوشمند — تا وقتی دسته‌بندی نشده null است
            $table->string('category', 24)->nullable();
            $table->unsignedTinyInteger('importance')->nullable();   // ۱ تا ۵
            $table->boolean('needs_reply')->default(false);
            $table->string('summary', 300)->nullable();

            // یک‌بار در بله گفته شد → دیگر هرگز
            $table->timestamp('reported_at')->nullable();

            // «رسیدگی شد» — دستی از پنل
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // پرسشِ پنل: «در این صندوق چه چیزی تازه است؟»
            $table->index(['account', 'received_at']);

            // پرسشِ گزارش: «چه چیزی هنوز گفته نشده؟»
            $table->index(['is_system', 'reported_at']);

            // پرسشِ نوارِ بالای پنل: «چند تا رسیدگی‌نشده مانده؟»
            $table->index(['handled_at', 'needs_reply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
