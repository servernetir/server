<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('crm_leads', function(Blueprint $t){ $t->unsignedBigInteger('customer_id')->nullable()->after('id')->index(); $t->string('session_key',64)->nullable()->unique()->after('domain_hash'); $t->string('page_url',500)->nullable(); $t->string('locale',5)->nullable(); $t->string('interest',120)->nullable(); $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete(); }); }
 public function down(): void { Schema::table('crm_leads', function(Blueprint $t){ $t->dropForeign(['assigned_user_id']); $t->dropUnique(['session_key']); $t->dropColumn(['customer_id','session_key','page_url','locale','interest','assigned_user_id']); }); }
};
