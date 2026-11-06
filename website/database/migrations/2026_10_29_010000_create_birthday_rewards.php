<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('cycle_year');
            $table->string('calendar', 12);
            $table->string('profile_fingerprint', 64);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('sms_status', 24)->default('pending_pattern')->index();
            $table->string('sms_pattern_reference', 100)->nullable();
            $table->timestamp('sms_attempted_at')->nullable();
            $table->timestamp('sms_accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'cycle_year']);
        });
        Schema::create('birthday_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('birthday_reward_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->string('status', 24)->default('applied')->index();
            $table->bigInteger('amount');
            $table->json('snapshot');
            $table->timestamp('expires_at')->index();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('birthday_product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('rule_key', 120)->unique();
            $table->string('cycle', 24);
            $table->string('component', 16)->default('service');
            $table->string('currency_code', 3);
            $table->bigInteger('amount');
            $table->string('reference', 255);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->timestamp('valid_until')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_redemptions');
        Schema::dropIfExists('birthday_product_costs');
        Schema::dropIfExists('birthday_rewards');
    }
};
