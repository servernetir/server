<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vm_records', function (Blueprint $t) {
            if (!Schema::hasColumn('vm_records', 'exit_country')) {
                $t->string('exit_country', 2)->nullable()->after('os');
            }
        });
    }
    public function down(): void
    {
        Schema::table('vm_records', function (Blueprint $t) {
            $t->dropColumn('exit_country');
        });
    }
};
