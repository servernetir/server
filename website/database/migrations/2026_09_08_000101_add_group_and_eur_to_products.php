<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * گروهِ پکیج + قیمتِ یورویی.
 *
 * چرا «گروه»: مدیر می‌خواهد قیمتِ «همهٔ هاست‌های وردپرس» را یک‌جا کم/زیاد کند.
 * تا امروز گروه فقط به‌صورت ضمنی در اسلاگ بود (wordpress-3) که برای پرس‌وجو و
 * جابه‌جاییِ یک پکیج بین گروه‌ها بی‌فایده است.
 *
 * چرا «قیمتِ یورویی»: نسخهٔ انگلیسی/ترکی قیمتِ یورو نشان می‌دهد و تا امروز آن
 * عدد فقط در config/hosting.php بود؛ یعنی دو منبعِ حقیقت برای قیمت — تغییرِ
 * قیمت در پنل، صفحاتِ بازاریابی را عوض نمی‌کرد. با این ستون، جدولِ products
 * تنها منبعِ حقیقت می‌شود.
 *
 * price_eur به **سنت** ذخیره می‌شود (یورو exponent=2) تا قاعدهٔ پول پروژه
 * (عدد صحیح در واحدِ فرعی، بی‌هیچ float) نشکند.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'group')) {
                $table->string('group', 60)->nullable()->after('category')->index();
            }
            if (! Schema::hasColumn('products', 'price_eur')) {
                $table->unsignedBigInteger('price_eur')->nullable()->after('price');
            }
        });

        // پرکردنِ گروه از اسلاگ: «wordpress-3» → «wordpress»، «reseller-linux-2»
        // → «reseller-linux». فقط شمارهٔ آخر بریده می‌شود.
        //
        // قیمتِ یورو هم از config/hosting.php برداشته می‌شود تا ردیف‌های موجودِ
        // پروداکشن بدونِ اجرای دوبارهٔ seeder کامل شوند.
        $catalog = (array) config('hosting.products', []);

        foreach (DB::table('products')->select('id', 'slug', 'price_eur')->get() as $row) {
            $slug  = (string) $row->slug;
            $group = preg_replace('/-\d+$/', '', $slug);
            $update = [];

            if ($group !== '' && $group !== $slug) {
                $update['group'] = $group;

                // شمارهٔ پلن از انتهای اسلاگ: «wordpress-3» → ایندکسِ ۲
                if ($row->price_eur === null && preg_match('/-(\d+)$/', $slug, $m)) {
                    $eur = $catalog[$group]['plans'][((int) $m[1]) - 1]['eur'] ?? null;

                    if ($eur !== null) {
                        $update['price_eur'] = (int) round(((float) $eur) * 100);
                    }
                }
            }

            if ($update !== []) {
                DB::table('products')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['group', 'price_eur'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
