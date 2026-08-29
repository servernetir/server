<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| نامِ لاتینِ کارکنان — برای مشتریِ سایتِ انگلیسی/ترکی.
|
| چرا لازم شد (خواستِ کارفرما، ۷ شهریور ۱۴۰۵): نامِ کارشناس در پیام‌های
| پشتیبانی باید به زبانِ خودِ مشتری بیفتد — «احسان ابراهیمی» برای فارسی و
| «Ehsan Ebrahimi» برای en/tr. یک ستونِ `name` نمی‌تواند هر دو باشد.
|
| ⚠️ backfill فقط ردیفی را می‌گیرد که نامش هنوز دقیقاً `ebrahimi` است —
| idempotent، و اگر مدیر قبلاً خودش عوض کرده باشد دست نمی‌زند.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'name_latin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name_latin', 80)->nullable()->after('name');
            });
        }

        DB::table('users')->where('name', 'ebrahimi')->update([
            'name'       => 'احسان ابراهیمی',
            'name_latin' => 'Ehsan Ebrahimi',
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'name_latin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('name_latin');
            });
        }
    }
};
