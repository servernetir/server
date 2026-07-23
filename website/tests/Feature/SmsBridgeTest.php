<?php

namespace Tests\Feature;

use App\Models\SmsOutbox;
use App\Services\Sms\QueuedSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پل پیامک: صف در آلمان، فرستنده در ایران.
 *
 * محورها:
 *   • بدون امضای درست، هیچ‌کس صف را نمی‌بیند (شماره و کد مشتری‌هاست)
 *   • یک پیام دو بار فرستاده نمی‌شود، حتی با دو فرستندهٔ هم‌زمان
 *   • کد منقضی هرگز فرستاده نمی‌شود
 */
class SmsBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-shared-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sms.relay_secret' => self::SECRET]);
    }

    private function signed(string $path, array $body = []): \Illuminate\Testing\TestResponse
    {
        $raw   = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(12));

        return $this->call('POST', "/api/sms/{$path}", [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_RELAY_TIMESTAMP' => $ts,
            'HTTP_X_RELAY_NONCE'     => $nonce,
            'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $ts."\n".$nonce."\n".$raw, self::SECRET),
        ], $raw);
    }

    private function queueOne(int $ttl = 4): SmsOutbox
    {
        (new QueuedSmsSender(['otp' => 'p-otp'], 'code'))->sendOtp('09121234567', '123456');

        return SmsOutbox::latest('id')->firstOrFail();
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_the_queue_is_not_readable_without_a_valid_signature(): void
    {
        $this->queueOne();

        $this->postJson('/api/sms/pull', [])->assertStatus(401);

        // امضای غلط هم همان‌قدر بی‌اثر
        $raw = json_encode([]);
        $ts  = (string) time();
        $this->call('POST', '/api/sms/pull', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_RELAY_TIMESTAMP' => $ts,
            'HTTP_X_RELAY_NONCE'     => bin2hex(random_bytes(12)),
            'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', 'wrong', 'wrong'),
        ], $raw)->assertStatus(401);
    }

    public function test_an_old_signature_is_rejected(): void
    {
        $raw   = json_encode([]);
        $ts    = (string) (time() - 600);
        $nonce = bin2hex(random_bytes(12));

        $this->call('POST', '/api/sms/pull', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_RELAY_TIMESTAMP' => $ts,
            'HTTP_X_RELAY_NONCE'     => $nonce,
            'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $ts."\n".$nonce."\n".$raw, self::SECRET),
        ], $raw)->assertStatus(401);
    }

    public function test_a_replayed_request_is_rejected(): void
    {
        $raw   = json_encode([]);
        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_RELAY_TIMESTAMP' => $ts,
            'HTTP_X_RELAY_NONCE'     => $nonce,
            'HTTP_X_RELAY_SIGNATURE' => hash_hmac('sha256', $ts."\n".$nonce."\n".$raw, self::SECRET),
        ];

        $this->call('POST', '/api/sms/pull', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/api/sms/pull', [], [], [], $headers, $raw)->assertStatus(409);
    }

    public function test_a_queued_message_is_handed_over_and_marked_sent(): void
    {
        $m = $this->queueOne();

        $res = $this->signed('pull')->assertOk();
        $msg = $res->json('messages.0');

        $this->assertSame($m->id, $msg['id']);
        $this->assertSame('09121234567', $msg['destination']);
        $this->assertSame('otp', $msg['event']);
        $this->assertSame(['code' => '123456'], $msg['params']);

        $this->signed('report', ['results' => [
            ['id' => $m->id, 'ok' => true, 'code' => '200-1', 'message' => 'انجام شد'],
        ]])->assertOk();

        $m->refresh();
        $this->assertSame('sent', $m->status);
        $this->assertNotNull($m->sent_at);
    }

    /** ⚠ دو اجرای هم‌زمان کران نباید یک پیام را دو بار بفرستد */
    public function test_a_second_puller_does_not_get_the_same_message(): void
    {
        $this->queueOne();

        $first  = $this->signed('pull')->json('messages');
        $second = $this->signed('pull')->json('messages');

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'پیام دو بار تحویل داده شد');
    }

    /** کد سه‌دقیقه‌ای نباید نیم‌ساعت بعد فرستاده شود */
    public function test_an_expired_message_is_never_delivered(): void
    {
        $m = $this->queueOne();
        $m->forceFill(['expires_at' => now()->subMinutes(10)])->save();

        $this->assertSame([], $this->signed('pull')->json('messages'));
        $this->assertSame('expired', $m->fresh()->status);
    }

    public function test_a_failed_send_is_retried_and_then_given_up_on(): void
    {
        $m = $this->queueOne();

        for ($i = 0; $i < 3; $i++) {
            $m->forceFill(['claim_token' => null, 'claimed_at' => null])->save();
            $this->signed('pull');
            $this->signed('report', ['results' => [
                ['id' => $m->id, 'ok' => false, 'code' => '400-2', 'message' => 'رد شد'],
            ]]);
        }

        $m->refresh();
        $this->assertSame('failed', $m->status);
        $this->assertSame(3, $m->attempts);
    }

    /** پیام آزاد (بدون الگو) هم باید منتقل شود */
    public function test_a_plain_message_carries_its_body(): void
    {
        (new QueuedSmsSender())->send('09121234567', 'سلام از سرورنت');

        $msg = $this->signed('pull')->json('messages.0');

        $this->assertSame('سلام از سرورنت', $msg['body']);
        $this->assertNull($msg['event']);
    }
}
