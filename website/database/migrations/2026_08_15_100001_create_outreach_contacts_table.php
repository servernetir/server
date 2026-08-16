<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهرستِ تماسِ کمپینِ بررسی سایت.
 *
 * مدیر «دامنه + ایمیل» را وارد می‌کند، سیستم هر سایت را بررسی می‌کند، و بعد از
 * **تأییدِ صریحِ مدیر** ایمیل می‌رود. هیچ ایمیلی از روی Whois یا خزشِ خودکار
 * ساخته نمی‌شود.
 *
 * 🔴 چرا `unsubscribed_at` از روزِ اول این‌جاست و کارِ «بعداً» نیست:
 * این پیام به کسی می‌رود که خودش درخواستش نکرده. تنها چیزی که چنین پیامی را از
 * اسپم جدا می‌کند این است که گیرنده بتواند با یک کلیک جلویش را بگیرد — و آن کلیک
 * باید **همیشه** کار کند، نه فقط وقتی یادمان بیفتد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_contacts', function (Blueprint $t) {
            $t->id();

            $t->string('host', 190)->index();
            $t->string('email', 190)->index();

            // گزارشی که برایش ساخته شد. اگر بررسی شکست بخورد نال می‌مانَد و
            // ردیف هرگز ارسال نمی‌شود — ایمیل بی‌گزارش بی‌معنی است.
            $t->foreignId('audit_report_id')->nullable()->constrained('audit_reports')->nullOnDelete();

            // pending → sent | failed | skipped
            $t->string('status', 16)->default('pending')->index();
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();

            // لینکِ لغوِ اشتراک. یکتا و غیرقابلِ حدس.
            $t->string('unsubscribe_token', 40)->unique();
            $t->timestamp('unsubscribed_at')->nullable();

            // برچسبِ دسته، تا مدیر بتواند یک کمپین را جدا ببیند
            $t->string('batch', 40)->nullable()->index();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_contacts');
    }
};
