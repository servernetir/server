<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->text('bank_details')->nullable();
            $table->char('bank_iban_fingerprint', 64)->nullable()->unique();
            $table->string('partner_key', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('finance_accounts')->whereNotNull('bank_details')->orWhereNotNull('partner_key')->orWhereNotNull('bank_iban_fingerprint')->exists()) {
            throw new RuntimeException('Account identity data must be preserved before rolling back this migration.');
        }
        Schema::table('finance_accounts', function (Blueprint $table) {
            $table->dropUnique(['bank_iban_fingerprint']);
            $table->dropIndex(['partner_key']);
            $table->dropColumn(['bank_details', 'bank_iban_fingerprint', 'partner_key']);
        });
    }
};
