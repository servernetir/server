<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Runs only against a named, empty disposable MariaDB database supplied by CI. */
class MadarMariaDbSchemaTest extends TestCase
{
    public function test_additive_schema_roundtrip_with_legacy_integer_widths_and_unique_claims(): void
    {
        $database = getenv('MADAR_MARIADB_DATABASE');
        if (! $database) {
            $this->markTestSkipped('No disposable MariaDB database supplied.');
        }
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+_testing$/D', $database);
        config(['database.connections.madar_schema' => array_replace(config('database.connections.mysql'), [
            'host' => '127.0.0.1', 'port' => getenv('MADAR_MARIADB_PORT') ?: 3306,
            'database' => $database, 'username' => getenv('MADAR_MARIADB_USER') ?: 'root',
            'password' => getenv('MADAR_MARIADB_PASSWORD'), 'url' => null,
        ])]);
        $old = DB::getDefaultConnection();
        DB::setDefaultConnection('madar_schema');
        $migration = require database_path('migrations/2026_10_31_180000_create_madar_participation_hub.php');
        $fixed = require database_path('migrations/2026_10_31_190000_publish_fixed_madar_missions.php');
        try {
            $this->assertSame([], DB::select('SHOW TABLES'), 'Schema test refuses a nonempty database.');
            Schema::create('customers', function (Blueprint $t) {
                $t->increments('id');
                $t->string('code');
            });
            DB::table('customers')->insert(['id' => 1, 'code' => 'FIXTURE-ONLY']);
            $migration->up();
            $this->assertSame(5, DB::table('madar_campaigns')->count());
            $id = DB::table('madar_participations')->insertGetId(['public_id' => '00112233-4455-4677-8899-aabbccddeeff',
                'customer_id' => 1, 'campaign_key' => 'gpu-code', 'title' => 'تمرین واقعی', 'locale' => 'fa',
                'status' => 'requested', 'terms_version' => 'fixture-v1', 'terms_accepted_at' => now(),
                'reward_snapshot' => json_encode(['amount_irt' => 100_000])]);
            DB::table('madar_messages')->insert(['participation_id' => $id, 'sender_type' => 'customer', 'body' => 'مدرک آزمایشی']);
            DB::table('madar_artifacts')->insert(['hash' => str_repeat('a', 64), 'participation_id' => $id]);
            try {
                DB::table('madar_artifacts')->insert(['hash' => str_repeat('a', 64), 'participation_id' => $id]);
                $this->fail('Duplicate artifact must be rejected by MariaDB.');
            } catch (QueryException $e) {
                $this->assertSame('23000', (string) $e->getCode());
            }
            $this->assertSame(['amount_irt' => 100_000], json_decode(DB::table('madar_participations')->value('reward_snapshot'), true));
            $fixed->up();
            $this->assertSame(8, DB::table('madar_campaigns')->count());
            $this->assertSame(1, (int) DB::table('madar_participations')->where('id', $id)->value('flow_version'));
            $mission = ['campaign_key' => 'gpu-template', 'version' => 1, 'reward_campaign_id' => 99,
                'reward_amount_irt' => 800_000, 'snapshot' => json_encode(['terms_version' => 'fixture-v2']),
                'published_at' => now(), 'submissions_until' => now()->addDays(30), 'published_by' => 1];
            $mid = DB::table('madar_missions')->insertGetId($mission);
            DB::table('madar_participations')->where('id', $id)->update(['mission_id' => $mid, 'flow_version' => 2, 'submission_hash' => str_repeat('c', 64)]);
            DB::table('madar_messages')->where('participation_id', $id)->update(['distribution_url' => 'https://www.linkedin.com/posts/fixture']);
            $this->assertSame(800_000, (int) DB::table('madar_missions')->value('reward_amount_irt'));
            $this->assertSame('https://www.linkedin.com/posts/fixture', DB::table('madar_messages')->value('distribution_url'));
            try {
                DB::table('madar_missions')->insert($mission);
                $this->fail('Published mission versions must be unique.');
            } catch (QueryException $e) {
                $this->assertSame('23000', (string) $e->getCode());
            }
            $fixed->down();
            $this->assertFalse(Schema::hasTable('madar_missions'));
            $this->assertFalse(Schema::hasColumn('madar_participations', 'flow_version'));
            $this->assertSame(1, DB::table('madar_participations')->count());
            $this->assertSame(['amount_irt' => 100_000], json_decode(DB::table('madar_participations')->value('reward_snapshot'), true));
            $migration->down();
            $this->assertFalse(Schema::hasTable('madar_messages'));
            $this->assertFalse(Schema::hasTable('madar_participations'));
            $this->assertSame('FIXTURE-ONLY', DB::table('customers')->value('code'));
            Schema::drop('customers');
        } finally {
            DB::setDefaultConnection($old);
            DB::purge('madar_schema');
        }
    }
}
