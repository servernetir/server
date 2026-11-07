<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->string('plan', 20);
            $table->string('pending_plan', 20)->nullable();
            $table->json('quote');
            $table->json('pending_quote')->nullable();
            $table->unsignedBigInteger('pending_invoice_id')->nullable()->index();
            $table->unsignedBigInteger('upstream_user_id')->nullable()->unique();
            $table->string('username', 80)->unique();
            $table->text('identity');
            $table->text('initial_password');
            $table->string('state', 40)->default('unpaid')->index();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('consented_at');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->date('confirmed_until')->nullable();
            $table->decimal('credit_irr', 24, 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Operational and identity records must not be silently deleted by a rollback.
        throw new RuntimeException('Retain sms_subscriptions; use the documented disable-only rollback.');
    }
};
