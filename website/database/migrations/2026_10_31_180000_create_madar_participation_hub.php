<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('madar_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('campaign_key', 60)->unique();
            $t->boolean('open')->default(true);
            // Existing production tables use different integer widths; no inferred FKs.
            $t->unsignedBigInteger('reward_campaign_id')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        foreach (['gpu-video', 'gpu-code', 'gpu-guide', 'referral', 'migration-story'] as $key) {
            DB::table('madar_campaigns')->insert(['campaign_key' => $key, 'open' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('madar_participations', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('campaign_key', 60)->index();
            $t->string('title', 180);
            $t->string('locale', 2);
            $t->string('status', 30)->default('requested')->index();
            $t->string('terms_version', 40);
            $t->timestamp('terms_accepted_at');
            $t->unsignedBigInteger('grant_id')->nullable()->unique();
            $t->json('reward_snapshot')->nullable();
            $t->timestamp('work_due_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('customer_read_at')->nullable();
            $t->timestamp('staff_read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('madar_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('participation_id')->constrained('madar_participations')->cascadeOnDelete();
            $t->string('sender_type', 12);
            $t->unsignedBigInteger('sender_id')->nullable();
            $t->text('body')->nullable();
            $t->string('event', 40)->nullable();
            $t->string('evidence_url', 1500)->nullable();
            $t->string('artifact_hash', 64)->nullable()->index();
            $t->boolean('is_submission')->default(false);
            $t->boolean('rights_confirmed')->default(false);
            $t->boolean('disclosure_confirmed')->default(false);
            $t->string('attachment_path', 240)->nullable();
            $t->string('attachment_mime', 40)->nullable();
            $t->unsignedInteger('attachment_bytes')->nullable();
            $t->string('attachment_sha256', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('madar_artifacts', function (Blueprint $t) {
            $t->string('hash', 64)->primary();
            $t->foreignId('participation_id')->constrained('madar_participations')->cascadeOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('madar_artifacts');
        Schema::dropIfExists('madar_messages');
        Schema::dropIfExists('madar_participations');
        Schema::dropIfExists('madar_campaigns');
    }
};
