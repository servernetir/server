<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_controls', function (Blueprint $t) {
            $t->id();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('daily_irt')->default(0);
            $t->unsignedBigInteger('monthly_irt')->default(0);
            $t->boolean('notify')->default(false);
            $t->boolean('daily_tasks')->default(false);
            $t->timestamps();
        });
        DB::table('growth_controls')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('growth_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->string('objective', 40);
            $t->string('utm_campaign', 120)->unique();
            $t->timestamps();
        });
        Schema::create('growth_contents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->nullable()->constrained('growth_campaigns')->nullOnDelete();
            $t->string('title', 200);
            $t->string('locale', 2);
            $t->string('channel', 30);
            $t->string('format', 30);
            $t->string('objective', 40);
            $t->string('target_url', 500)->nullable();
            $t->text('brief');
            $t->longText('body')->nullable();
            $t->string('status', 20)->default('draft')->index();
            $t->unsignedInteger('version')->default(1);
            $t->string('topic_hash', 64)->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('scheduled_at')->nullable()->index();
            $t->string('timezone', 64)->default('Asia/Tehran');
            $t->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->string('publication_url', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('growth_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_id')->constrained('growth_contents')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamp('created_at');
            $t->unique(['content_id', 'version']);
        });
        Schema::create('growth_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('request_key', 64)->unique();
            $t->foreignId('content_id')->constrained('growth_contents')->cascadeOnDelete();
            $t->unsignedBigInteger('gateway_token_id')->nullable();
            $t->string('kind', 24);
            $t->string('model_slug', 200)->nullable();
            $t->string('status', 24)->default('queued')->index();
            $t->json('payload');
            $t->unsignedInteger('content_version');
            $t->unsignedBigInteger('max_cost_irt')->default(0);
            $t->unsignedBigInteger('actual_cost_irt')->nullable();
            $t->unsignedBigInteger('ai_usage_id')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('growth_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_id')->constrained('growth_contents')->cascadeOnDelete();
            $t->unsignedBigInteger('job_id')->nullable()->unique();
            $t->string('path', 240);
            $t->string('mime', 80);
            $t->string('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->string('rights', 240);
            $t->string('drive_id', 200)->nullable();
            $t->timestamps();
        });
        Schema::create('growth_documents', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->string('source', 500);
            $t->string('kind', 30)->default('runbook');
            $t->string('locale', 2)->default('fa');
            $t->string('classification', 20)->default('internal');
            $t->string('scope', 160)->default('general');
            $t->longText('body');
            $t->string('sha256', 64)->unique();
            $t->string('status', 20)->default('candidate')->index();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('review_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
        Schema::create('growth_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('growth_documents')->cascadeOnDelete();
            $t->unsignedInteger('position');
            $t->text('body');
            $t->unique(['document_id', 'position']);
        });
        Schema::create('growth_audits', function (Blueprint $t) {
            $t->id();
            $t->string('action', 80);
            $t->string('subject', 120);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('growth_automation_runs', function (Blueprint $t) {
            $t->id();
            $t->string('run_key', 100)->unique();
            $t->json('summary');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['growth_automation_runs', 'growth_audits', 'growth_chunks', 'growth_documents', 'growth_assets', 'growth_jobs', 'growth_revisions', 'growth_contents', 'growth_campaigns', 'growth_controls'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
