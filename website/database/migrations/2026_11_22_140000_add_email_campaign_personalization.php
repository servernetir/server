<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_marketing_recipients', function (Blueprint $table) {
            $table->text('full_name')->nullable();
        });
        Schema::table('email_marketing_campaigns', function (Blueprint $table) {
            $table->string('sender_email', 190)->nullable();
            $table->string('sender_name', 190)->nullable();
            $table->string('reply_to', 190)->nullable();
        });
    }

    public function down(): void
    {
        // Disposable databases only; production rollback retains personalization history.
        Schema::table('email_marketing_campaigns', fn (Blueprint $table) => $table->dropColumn(['sender_email', 'sender_name', 'reply_to']));
        Schema::table('email_marketing_recipients', fn (Blueprint $table) => $table->dropColumn('full_name'));
    }
};
