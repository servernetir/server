<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محتوای غنیِ صفحهٔ سرورِ فیزیکی — بدنهٔ بلندِ چندپاراگرافی و تحلیلِ نقاطِ
 * قوت/ضعف. تا امروز فقط یک پاراگرافِ `description` بود؛ برای صفحاتِ سئوشدهٔ
 * نسل ۸/۹ به متنِ خواندنی‌تر و تحلیلی نیاز داریم.
 *
 * همه سه‌زبانه‌اند؛ body متنِ چندپاراگرافی (با \n\n) و strengths/weaknesses
 * فهرستِ نکته‌ها {fa:[…],en:[…],tr:[…]}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('physical_servers', function (Blueprint $table) {
            $table->json('body')->nullable()->after('description');
            $table->json('strengths')->nullable()->after('body');
            $table->json('weaknesses')->nullable()->after('strengths');
        });
    }

    public function down(): void
    {
        Schema::table('physical_servers', function (Blueprint $table) {
            $table->dropColumn(['body', 'strengths', 'weaknesses']);
        });
    }
};
