<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('ref_id', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Refuse a rollback that would truncate an already recorded BSC hash.
        if (DB::table('payments')->whereRaw('LENGTH(ref_id) > 64')->exists()) {
            throw new RuntimeException('Payment references exceed 64 characters; keep the expanded column.');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->string('ref_id', 64)->nullable()->change();
        });
    }
};
