<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_providers')->insertOrIgnore([
            'slug' => 'openai',
            'name' => 'OpenAI',
            'driver' => 'OpenAI-Compatible',
            'billing_currency_code' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve the provider, price versions and historical billing relations on rollback.
    }
};
