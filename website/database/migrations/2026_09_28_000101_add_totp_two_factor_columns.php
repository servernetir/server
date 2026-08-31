<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ورودِ دومرحله‌ای با اپلیکیشنِ احرازِ هویت (Google Authenticator) — اختیاری،
 * برای هم مشتری و هم کارکنانِ پنل.
 *
 * جدولِ `customers` سه ستونِ اولش را از روزِ اول داشت (`create_customers_table`)
 * ولی هرگز استفاده نشد؛ این مهاجرت آن‌ها را برای `users` هم می‌سازد و ستونِ
 * چهارم را به هر دو اضافه می‌کند.
 *
 * ═══ 🔴 چرا `two_factor_last_step` لازم است ═══
 *
 * کدِ TOTP سی ثانیه معتبر است و در آن سی ثانیه **بارها** قابلِ استفاده است.
 * یعنی بدونِ این ستون، کدی که یک بار از روی شانه یا با یک صفحهٔ فیشینگ دیده
 * شود، تا پایانِ همان بازه برای مهاجم هم کار می‌کند — و چون پنجرهٔ پذیرش ±۱
 * بازه است، عملاً تا ۹۰ ثانیه.
 *
 * این ستون شمارهٔ آخرین بازهٔ **مصرف‌شده** را نگه می‌دارد و لایهٔ بالاتر هر
 * کدی با بازهٔ کوچک‌تر-یا-مساوی را رد می‌کند. یک عدد، و کدِ دزدیده‌شده دیگر
 * بارِ دوم کار نمی‌کند.
 *
 * ⚠️ `text` برای رازِ `users` و نه `binary`: مقدارِ ذخیره‌شده خروجیِ
 * `encrypted` castِ لاراول است که base64 است، نه بایتِ خام. جدولِ `customers`
 * از قبل `binary` دارد و دست نمی‌خورد — هر دو کار می‌کنند، ولی برای ستونِ
 * تازه دلیلی نیست نوعِ دودویی انتخاب کنیم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'two_factor_secret')) {
                    $table->text('two_factor_secret')->nullable()->after('password');
                }

                if (! Schema::hasColumn('users', 'two_factor_recovery')) {
                    $table->text('two_factor_recovery')->nullable()->after('two_factor_secret');
                }

                if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                    $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery');
                }

                if (! Schema::hasColumn('users', 'two_factor_last_step')) {
                    $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
                }
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'two_factor_last_step')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users')) {
            foreach (['two_factor_secret', 'two_factor_recovery', 'two_factor_confirmed_at', 'two_factor_last_step'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    Schema::table('users', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'two_factor_last_step')) {
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('two_factor_last_step'));
        }
    }
};
