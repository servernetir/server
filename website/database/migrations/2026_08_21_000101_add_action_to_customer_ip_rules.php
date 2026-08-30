<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * افزودنِ «نوعِ قاعده» به قوانین IP — تا کاربر بتواند هم IP مجاز (allow) و
 * هم IP مسدود (deny) تعریف کند. تا امروز جدول فقط whitelist بود.
 * idempotent و nullable-safe تا روی سرورِ ازقبل‌مهاجرت‌کرده هم امن باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_ip_rules')) {
            return;
        }

        Schema::table('customer_ip_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_ip_rules', 'action')) {
                $table->string('action', 5)->default('allow')->after('cidr'); // allow | deny
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_ip_rules') && Schema::hasColumn('customer_ip_rules', 'action')) {
            Schema::table('customer_ip_rules', function (Blueprint $table) {
                $table->dropColumn('action');
            });
        }
    }
};
