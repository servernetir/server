<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('madar_missions', function (Blueprint $t) {
            $t->id();
            $t->string('campaign_key', 60);
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('reward_campaign_id');
            $t->unsignedBigInteger('reward_amount_irt');
            $t->json('snapshot');
            $t->timestamp('published_at');
            $t->timestamp('submissions_until');
            $t->unsignedBigInteger('published_by');
            $t->timestamps();
            $t->unique(['campaign_key', 'version']);
        });
        Schema::table('madar_campaigns', fn (Blueprint $t) => $t->unsignedBigInteger('active_mission_id')->nullable());
        Schema::table('madar_participations', function (Blueprint $t) {
            $t->unsignedTinyInteger('flow_version')->default(1);
            $t->unsignedBigInteger('mission_id')->nullable()->index();
            $t->string('submission_hash', 64)->nullable();
        });
        Schema::table('madar_messages', fn (Blueprint $t) => $t->string('distribution_url', 1500)->nullable());
        foreach (['gpu-template', 'gpu-benchmark', 'gpu-workshop'] as $key) {
            DB::table('madar_campaigns')->insertOrIgnore(['campaign_key' => $key, 'open' => false,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('madar_messages', fn (Blueprint $t) => $t->dropColumn('distribution_url'));
        Schema::table('madar_participations', fn (Blueprint $t) => $t->dropColumn(['flow_version', 'mission_id', 'submission_hash']));
        Schema::table('madar_campaigns', fn (Blueprint $t) => $t->dropColumn('active_mission_id'));
        Schema::dropIfExists('madar_missions');
    }
};
