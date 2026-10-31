<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_vouchers', function (Blueprint $table) {
            $table->foreignId('related_voucher_id')->nullable()->index()->constrained('finance_vouchers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('finance_vouchers')->whereNotNull('related_voucher_id')->exists()) {
            throw new RuntimeException('Linked financial evidence must be retained; roll back application files without dropping voucher relationships.');
        }
        Schema::table('finance_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_voucher_id');
        });
    }
};
