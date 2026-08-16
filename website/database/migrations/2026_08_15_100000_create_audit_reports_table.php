<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * گزارشِ ماندگارِ بررسی سئو — تا بشود لینکش را برای کسی فرستاد.
 *
 * تا امروز `SiteAudit::run()` نتیجه را فقط به مرورگر می‌داد و همان‌جا می‌مرد.
 * برای اینکه صاحبِ سایت بتواند «زنده» بیاید و وضعیتِ سایتش را ببیند، نتیجه باید
 * جایی بماند و نشانیِ عمومیِ خودش را داشته باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_reports', function (Blueprint $t) {
            $t->id();

            // نشانیِ عمومی. تصادفی و بلند، چون تنها چیزی است که از گزارش محافظت می‌کند.
            $t->string('token', 40)->unique();

            $t->string('url', 255);
            $t->string('host', 190)->index();
            $t->unsignedTinyInteger('score')->default(0);
            $t->string('grade', 4)->default('');

            // زبانی که گزارش در آن ساخته شد؛ صفحهٔ عمومی با همین رندر می‌شود.
            $t->string('locale', 5)->default('fa');

            // خروجیِ کاملِ SiteAudit::run — صفحهٔ گزارش دقیقاً همان چیزی را نشان
            // می‌دهد که ابزار نشان داده بود، بی‌اجرای دوباره.
            $t->longText('result');

            // tool = بازدیدکننده روی سایت · admin = از پنل · outreach = کمپین
            $t->string('source', 16)->default('tool')->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            // پاک‌سازیِ گزارش‌های کهنه به ترتیبِ زمان
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
    }
};
