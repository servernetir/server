<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فیلدهای تحویل/فراهم‌سازی روی سرویس.
 *
 * تا امروز `services` فقط یک اشتراکِ مالی بود؛ حالا باید بداند روی کدام سرور
 * تحویل شود و نتیجهٔ ساختِ حساب (نام‌کاربری، دامنه، رمزِ کنترل‌پنل) را نگه دارد.
 * رمزِ کنترل‌پنل با cast=encrypted ذخیره می‌شود. همه nullable و idempotent تا
 * روی سرورِ ازقبل‌مهاجرت‌کرده امن باشد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'server_id')) {
                $table->foreignId('server_id')->nullable()->after('customer_id'); // بدونِ FKِ آبشاری — حذفِ سرور نباید سرویس را پاک کند
            }
            if (! Schema::hasColumn('services', 'plan')) {
                $table->string('plan', 80)->nullable()->after('cycle');            // نامِ پکیجِ WHM/کنترل‌پنل
            }
            if (! Schema::hasColumn('services', 'username')) {
                $table->string('username', 64)->nullable()->after('plan');         // نام‌کاربریِ حساب (cPanel و…)
            }
            if (! Schema::hasColumn('services', 'domain')) {
                $table->string('domain', 190)->nullable()->after('username');
            }
            if (! Schema::hasColumn('services', 'password')) {
                $table->text('password')->nullable()->after('domain');             // رمزِ کنترل‌پنل — رمزنگاری‌شده (cast)
            }
            if (! Schema::hasColumn('services', 'panel_url')) {
                $table->string('panel_url', 190)->nullable()->after('password');
            }
            if (! Schema::hasColumn('services', 'provision_status')) {
                $table->string('provision_status', 16)->nullable()->after('panel_url'); // null|pending|running|done|failed|manual|releasing|none
            }
            if (! Schema::hasColumn('services', 'provision_error')) {
                $table->string('provision_error', 300)->nullable()->after('provision_status');
            }
            if (! Schema::hasColumn('services', 'provisioned_at')) {
                $table->timestamp('provisioned_at')->nullable()->after('provision_error');
            }
            if (! Schema::hasColumn('services', 'provision_meta')) {
                $table->json('provision_meta')->nullable()->after('provisioned_at'); // خروجیِ خامِ درایور
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            foreach (['server_id', 'plan', 'username', 'domain', 'password', 'panel_url',
                'provision_status', 'provision_error', 'provisioned_at', 'provision_meta'] as $col) {
                if (Schema::hasColumn('services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
