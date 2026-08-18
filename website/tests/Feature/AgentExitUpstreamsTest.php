<?php

namespace Tests\Feature;

use App\Models\ExitUpstream;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسیرِ token-authِ `/agent/exitupstreams` — میزبانِ ایران آپ‌استریم‌های اکسیت
 * (رله‌های SSH و نودهای VLESS) را از آن می‌کشد.
 *
 * 🔴 این پاسخ **مقدارِ خامِ اعتبارنامه** را دارد چون هاست بی‌آن نمی‌تواند dial کند؛
 * پس هم احرازِ توکن سخت‌گیرانه است، هم فقط آپ‌استریم‌های فعال می‌آیند.
 */
class AgentExitUpstreamsTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'agent-secret-token-xyz';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('agent_pull_token', $this->token);
    }

    private function mk(array $over = []): ExitUpstream
    {
        return ExitUpstream::create(array_merge([
            'name' => 'u'.random_int(1, 999999),
            'role' => 'relay', 'type' => 'ssh',
            'host' => '10.0.0.1', 'port' => 22, 'username' => 'root',
            'secret' => 'KEYDATA', 'enabled' => true, 'priority' => 100,
        ], $over));
    }

    private function pull(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/agent/exitupstreams', ['X-Agent-Token' => $this->token]);
    }

    // ═══════════════════ احراز ═══════════════════

    public function test_forbids_missing_or_wrong_token(): void
    {
        $this->getJson('/agent/exitupstreams')->assertStatus(403);
        $this->getJson('/agent/exitupstreams', ['X-Agent-Token' => 'wrong'])->assertStatus(403);
    }

    public function test_accepts_the_legacy_pf_token_header(): void
    {
        $this->mk();
        $this->getJson('/agent/exitupstreams', ['X-PF-Token' => $this->token])->assertOk();
    }

    // ═══════════════════ محتوا ═══════════════════

    public function test_groups_relays_and_country_exits_and_excludes_disabled(): void
    {
        $this->mk(['name' => 'relay-a', 'role' => 'relay']);
        $this->mk(['name' => 'de-live', 'role' => 'exit', 'type' => 'vless', 'country_code' => 'de', 'host' => '', 'port' => null, 'secret' => 'vless://a@1.1.1.1:443#DE']);
        $this->mk(['name' => 'de-off',  'role' => 'exit', 'type' => 'vless', 'country_code' => 'de', 'enabled' => false, 'secret' => 'vless://b@2.2.2.2:443#DE']);
        $this->mk(['name' => 'nl-live', 'role' => 'exit', 'type' => 'ssh', 'country_code' => 'nl', 'host' => '3.3.3.3', 'port' => 22]);

        $data = $this->pull()->assertOk()->json();

        $this->assertCount(1, $data['relays']);
        $this->assertSame('relay-a', $data['relays'][0]['name']);

        // اکسیت‌ها بر اساسِ کشور گروه شده‌اند؛ خاموش‌ها نیستند
        $this->assertCount(1, $data['exits']['de']);
        $this->assertSame('de-live', $data['exits']['de'][0]['name']);
        $this->assertCount(1, $data['exits']['nl']);
        $this->assertSame('nl', $data['exits']['nl'][0]['cc']);
    }

    public function test_secret_material_is_included_for_the_host(): void
    {
        $this->mk(['name' => 'r1', 'role' => 'relay', 'secret' => 'PRIVATEKEYABC']);

        $relay = $this->pull()->assertOk()->json('relays.0');

        // هاست بی‌کلید نمی‌تواند dial کند — پس اعتبارنامه باید در پاسخ باشد
        $this->assertSame('PRIVATEKEYABC', $relay['secret']);
        $this->assertSame('root', $relay['username']);
        $this->assertSame(22, $relay['port']);
    }

    public function test_sorted_by_priority_ascending(): void
    {
        $this->mk(['name' => 'p50', 'priority' => 50]);
        $this->mk(['name' => 'p10', 'priority' => 10]);

        $relays = $this->pull()->assertOk()->json('relays');

        $this->assertSame('p10', $relays[0]['name']);
        $this->assertSame('p50', $relays[1]['name']);
    }

    public function test_heartbeat_is_recorded(): void
    {
        $this->assertNull(Setting::get('agent_seen_exitupstreams'));

        $this->pull()->assertOk();

        $this->assertNotNull(Setting::get('agent_seen_exitupstreams'));
    }

    public function test_empty_exits_is_a_json_object_not_an_array(): void
    {
        $this->mk(['role' => 'relay']);   // فقط رله، هیچ اکسیتی

        // سمتِ هاست همیشه map انتظار دارد؛ آرایه‌ی تهی JS را می‌شکند
        $this->pull()->assertOk()->assertSee('"exits":{}', false);
    }

    public function test_response_is_not_cacheable(): void
    {
        $this->mk();

        // پاسخ اعتبارنامه دارد؛ هیچ واسطی نباید ذخیره‌اش کند. (لاراول ممکن است
        // «, private» هم بیفزاید — مهم حضورِ no-store است.)
        $cc = (string) $this->pull()->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cc);
    }
}
