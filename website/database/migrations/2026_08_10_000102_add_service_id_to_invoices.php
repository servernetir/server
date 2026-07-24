<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیوند فاکتور به سرویس.
 *
 * هر دورهٔ پرداختِ یک سرویس (اولین صدور یا تمدید) یک فاکتور جداست. با این
 * ستون، وقتی فاکتور پرداخت شد می‌فهمیم کدام سرویس را باید فعال/تمدید کنیم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('service_id');
        });
    }
};
