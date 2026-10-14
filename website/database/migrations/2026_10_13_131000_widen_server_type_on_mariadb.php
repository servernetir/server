<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE servers MODIFY type VARCHAR(40) NOT NULL DEFAULT \'whm\'');
        }
    }

    public function down(): void {}
};
