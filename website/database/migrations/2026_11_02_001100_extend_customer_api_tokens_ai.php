<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| توکنِ API — گره‌خوردن با پروژهٔ AI (بخشی، سازگار با عقب)
|--------------------------------------------------------------------------
|
| 🔴 یک قاعدهٔ M2: **سیستمِ احرازِ هویتِ موازی ساخته نمی‌شود.** توکنِ AI همان
|    سطرِ `CustomerApiToken` است — همان هشِ SHA-۲۵۶، همان CIDR، همان انقضا،
|    همان ابطالِ نرم. تنها تازه این است که توکنِ AI **به یک پروژه گره** می‌خورد.
|
| ⬤ Backward compatibility — two guarantees of this migration:
|   1) Existing tokens: no column changes; behavior untouched.
|   2) AI access is never implied: a token without an explicit `ai:*` grant
|      and without a bound project is not AI-capable in the admission seam.
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            /*
            | پروژهٔ bound — null = توکنِ غیرِ AI. حذفِ **سطرِ پروژه** توکن را
            | حذف نمی‌کند (nullOnDelete): سطرِ توکن فقط صاحبِ زنجیرش را از دست
            | می‌دهد و به‌سویِ «غیرِ AI-ساز» می‌افتد — هیچ کلیدِ فعالِ یتیمی
            | که کار کند باقی نمی‌مانَد، چون admission بدونِ پروژه رد می‌کند.
            */
            $table->foreignId('ai_project_id')->nullable()->after('daily_spend_cap_irt')
                ->constrained('ai_projects')->nullOnDelete();
        });

        /* مانندِ تغذیهٔ M3/M4: سفارشِ با پروژه به دنبالِ خودش می‌آید و
           سقفِ رایج از توکن و مشتری می‌آید؛ این‌جا فقط فهرستِ همان پروژه */
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            $table->index(['ai_project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            $table->dropIndex(['ai_project_id']);
        });
        Schema::table('customer_api_tokens', function (Blueprint $table) {
            try {
                $table->dropForeign(['ai_project_id']);
            } catch (\Throwable) {
                // MariaDB/SQLite: قیدِ خود نامِ ستونش را می‌برد؛ در SQLite فقط ستون می‌افتد
            }
            $table->dropColumn('ai_project_id');
        });
    }
};
