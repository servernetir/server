<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_calls') && ! Schema::hasColumn('ai_calls', 'response_encrypted')) {
            Schema::table('ai_calls', fn (Blueprint $t) => $t->longText('response_encrypted')->nullable());
        }
    }
    public function down(): void {}
};
