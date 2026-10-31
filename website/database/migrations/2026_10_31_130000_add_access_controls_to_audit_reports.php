<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('created_by')->index();
            $table->timestamp('revoked_at')->nullable()->after('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            $table->dropIndex(['revoked_at']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'revoked_at']);
        });
    }
};
