<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|==============================================================================
| داخلیِ تلفنِ هر کارکن
|==============================================================================
|
| برای Click-to-Call لازم است: سامانه اول **داخلیِ کارشناس** را زنگ می‌زند و
| بعد شمارهٔ مشتری را می‌گیرد. بی‌این ستون نمی‌دانیم تلفنِ چه کسی باید زنگ
| بخورد.
|
| ⚠️ عمداً روی `users` است نه در `config`. یک داخلیِ سراسری یعنی تماسِ همهٔ
| کارشناس‌ها از یک تلفن برقرار شود — و در گزارشِ عملکرد هم همه‌شان یک نفر
| به‌نظر برسند.
|
| ⚠️ nullable و بدونِ پیش‌فرض: کارمندی که داخلی ندارد باید دکمهٔ تماسش
| **غیرفعال** باشد، نه اینکه تماسش از داخلیِ یک نفرِ دیگر برود.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_extension', 16)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_extension');
        });
    }
};
