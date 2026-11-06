<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\BaleManagerDigestDelivery;
use App\Services\Bale\Management\ManagerTrafficSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BaleManagerDigestNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'digest-navigation-test';
    private const CHAT = '700700';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bale.token' => self::BOT, 'services.bale_safir.key' => null,
            'servernet.contact.email' => '']);
        Setting::put('bale_manager_policy_v2_enabled', '1');
        Http::preventStrayRequests();
        Http::fake([
            '*sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 10]]),
            '*answerCallbackQuery' => Http::response(['ok' => true]),
        ]);
        $this->mock(ManagerTrafficSnapshot::class)->shouldNotReceive('refresh');
        $delivery = $this->mock(BaleManagerDigestDelivery::class);
        $delivery->shouldNotReceive('send');
        $delivery->shouldNotReceive('sendSample');
    }

    private function bindOwner(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('test'), 'role' => 'admin']);
        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode(['chat_id' => self::CHAT,
            'user_id' => $owner->id, 'at' => now()->toIso8601String()]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
    }

    private function tap(string $topic, string $from = self::CHAT): void
    {
        $this->postJson('/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32), [
            'update_id' => random_int(1, 10000000),
            'callback_query' => ['id' => (string) Str::uuid(), 'data' => 'v1:md:'.$topic,
                'from' => ['id' => $from, 'is_bot' => false],
                'message' => ['message_id' => 55, 'text' => 'report',
                    'chat' => ['id' => self::CHAT, 'type' => 'private']]],
        ])->assertOk();
    }

    private function sent(): array
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_detail_keeps_the_bound_owner_and_has_refresh_overview_and_a_real_panel_url(): void
    {
        $this->bindOwner();
        $this->mock(BaleManagerDailyDigest::class)->shouldReceive('detail')->once()->with('traffic')->andReturn('fixture traffic detail');
        $this->tap('traffic');
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame(self::CHAT, $messages[0]['chat_id']);
        $this->assertSame('fixture traffic detail', $messages[0]['text']);
        $rows = $messages[0]['reply_markup']['inline_keyboard'];
        $this->assertSame('v1:md:traffic', $rows[0][0]['callback_data']);
        $this->assertSame('v1:md:overview', $rows[0][1]['callback_data']);
        $this->assertSame('https://console.servernet.cloud/admin/bale/reporting', $rows[1][0]['url']);
        $this->assertArrayNotHasKey('callback_data', $rows[1][0]);
        $this->assertCount(2, $rows);
    }

    public function test_overview_renders_a_read_only_report_and_keeps_topic_navigation(): void
    {
        $this->bindOwner();
        $digest = $this->mock(BaleManagerDailyDigest::class);
        $digest->shouldReceive('text')->once()->andReturn('fixture overview');
        $digest->shouldReceive('buttons')->once()->andReturn([[['text' => 'Calendar', 'data' => 'v1:md:calendar']]]);
        $digest->shouldNotReceive('detail');
        $this->tap('overview');
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertSame('fixture overview', $messages[0]['text']);
        $this->assertSame('v1:md:calendar', $messages[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_disabled_management_and_foreign_callbacks_cannot_read_report_sources(): void
    {
        $this->bindOwner();
        $digest = $this->mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('detail');
        $digest->shouldNotReceive('text');
        $this->tap('overview', '9999');
        $this->assertSame([], $this->sent());
        Setting::put('bale_manager_policy_v2_enabled', '0');
        $this->tap('overview');
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('هنوز فعال نیست', $messages[0]['text']);
    }

    public function test_unknown_topics_never_become_an_arbitrary_panel_link(): void
    {
        $this->bindOwner();
        $digest = $this->mock(BaleManagerDailyDigest::class);
        $digest->shouldNotReceive('detail');
        $digest->shouldNotReceive('text');
        $this->tap('https://example.test');
        $messages = $this->sent();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('شناخته نشد', $messages[0]['text']);
        $this->assertArrayNotHasKey('reply_markup', $messages[0]);
    }

    public function test_sender_supports_exclusive_console_links_and_preserves_existing_callback_buttons(): void
    {
        $sender = new BaleSender(self::BOT);
        $sender->sendButtons(self::CHAT, 'fixture buttons', [[
            ['text' => 'Refresh', 'data' => 'v1:md:calendar'],
            ['text' => 'Calendar', 'url' => 'https://console.servernet.cloud/admin/calendar'],
        ]]);
        $rows = $this->sent()[0]['reply_markup']['inline_keyboard'];
        $this->assertSame(['text' => 'Refresh', 'callback_data' => 'v1:md:calendar'], $rows[0][0]);
        $this->assertSame(['text' => 'Calendar', 'url' => 'https://console.servernet.cloud/admin/calendar'], $rows[0][1]);
    }

    public function test_sender_drops_ambiguous_unsafe_and_nonconsole_url_actions(): void
    {
        $buttons = [['text' => 'Valid', 'data' => 'v1:md:calendar']];
        foreach (['http://console.servernet.cloud/admin/calendar', 'https://example.test/admin/calendar',
            'https://console.servernet.cloud.evil.test/admin/calendar', 'https://console.servernet.cloud/account',
            'https://user@console.servernet.cloud/admin/calendar', 'https://console.servernet.cloud:443/admin/calendar',
            'https://console.servernet.cloud/admin/../account', 'https://console.servernet.cloud/admin/%2e%2e/account',
            'https://console.servernet.cloud/admin/..\\account',
            'javascript:alert(1)', "https://console.servernet.cloud/admin/calendar\n"] as $url) {
            $buttons[] = ['text' => 'Invalid', 'url' => $url];
        }
        $buttons[] = ['text' => 'Ambiguous', 'url' => 'https://console.servernet.cloud/admin/calendar', 'data' => 'v1:x'];
        (new BaleSender(self::BOT))->sendButtons(self::CHAT, 'fixture buttons', [$buttons]);
        $this->assertSame([[['text' => 'Valid', 'callback_data' => 'v1:md:calendar']]], $this->sent()[0]['reply_markup']['inline_keyboard']);
    }
}
