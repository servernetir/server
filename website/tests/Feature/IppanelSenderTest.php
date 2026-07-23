<?php

namespace Tests\Feature;

use App\Services\Sms\IppanelSender;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قرارداد سیمی آی‌پی‌پنل.
 *
 * این تست‌ها شکل دقیق درخواست را قفل می‌کنند — چون سه چیز در این API آسان
 * اشتباه می‌شود و هیچ‌کدام پیام خطای گویا نمی‌دهند:
 *   هدر بدون Bearer، شماره‌ها E.164، و جای متفاوتِ recipients در دو حالت.
 */
class IppanelSenderTest extends TestCase
{
    private function sender(?string $pattern = null): IppanelSender
    {
        return new IppanelSender('tok-123456', '+983000505', $pattern, 'code');
    }

    private function okResponse(): array
    {
        return [
            'data' => ['message_outbox_ids' => [1123594208]],
            'meta' => ['status' => true, 'message' => 'انجام شد', 'message_code' => '200-1'],
        ];
    }

    public function test_authorization_header_has_no_bearer_prefix(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response($this->okResponse())]);

        $this->sender()->send('09121234567', 'سلام');

        Http::assertSent(function ($request) {
            $header = $request->header('Authorization')[0] ?? '';

            $this->assertSame('tok-123456', $header, 'هدر نباید Bearer داشته باشد');

            return true;
        });
    }

    public function test_webservice_send_puts_recipients_inside_params(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response($this->okResponse())]);

        $this->assertTrue($this->sender()->send('09121234567', 'متن آزمایشی'));

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('https://edge.ippanel.com/v1/api/send', $request->url());
            $this->assertSame('webservice', $body['sending_type']);
            $this->assertSame('+983000505', $body['from_number']);
            $this->assertSame('متن آزمایشی', $body['message']);
            $this->assertSame(['+989121234567'], $body['params']['recipients']);
            $this->assertArrayNotHasKey('recipients', $body, 'در حالت webservice نباید بیرون params باشد');

            return true;
        });
    }

    public function test_pattern_send_puts_recipients_at_top_level(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response($this->okResponse())]);

        $this->assertTrue($this->sender('otp-pattern')->sendOtp('09121234567', '654321'));

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('pattern', $body['sending_type']);
            $this->assertSame('otp-pattern', $body['code']);
            $this->assertSame(['+989121234567'], $body['recipients']);
            $this->assertSame(['code' => '654321'], $body['params']);

            return true;
        });
    }

    public function test_without_a_pattern_the_otp_falls_back_to_a_plain_message(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response($this->okResponse())]);

        $this->assertTrue($this->sender()->sendOtp('09121234567', '654321'));

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('webservice', $body['sending_type']);
            $this->assertStringContainsString('654321', $body['message']);

            return true;
        });
    }

    /**
     * مهم‌ترین تست: آی‌پی‌پنل می‌تواند HTTP 200 بدهد و در بدنه بگوید نشد.
     * اگر فقط کد HTTP را نگاه کنیم، کد یک‌بارمصرفی که هرگز نرفته را
     * «فرستاده شد» گزارش می‌کنیم و کاربر بی‌دلیل منتظر می‌ماند.
     */
    public function test_http_200_with_status_false_is_treated_as_failure(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response([
            'meta' => ['status' => false, 'message' => 'اعتبار کافی نیست', 'message_code' => '400-2'],
        ], 200)]);

        $this->assertFalse($this->sender()->send('09121234567', 'سلام'));
    }

    public function test_invalid_token_response_is_a_failure(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response([
            'meta' => ['status' => false, 'message' => 'توکن نامعتبر', 'message_code' => '400-1'],
        ], 401)]);

        $this->assertFalse($this->sender()->send('09121234567', 'سلام'));
        $this->assertNotNull(IppanelSender::explain('400-1'));
    }

    public function test_network_failure_does_not_throw(): void
    {
        Http::fake(fn () => throw new \RuntimeException('اتصال قطع شد'));

        // ثبت‌نام نباید با ۵۰۰ بشکند فقط چون اپراتور پاسخ نداد
        $this->assertFalse($this->sender()->send('09121234567', 'سلام'));
    }

    public function test_missing_configuration_disables_the_driver(): void
    {
        $this->assertFalse((new IppanelSender(null, '+983000505'))->enabled());
        $this->assertFalse((new IppanelSender('tok', null))->enabled());
        $this->assertTrue((new IppanelSender('tok', '+983000505'))->enabled());
    }

    /** شکل‌های مختلفی که کاربر ممکن است شماره را وارد کند، همه یک خروجی */
    public function test_mobile_numbers_are_converted_to_e164(): void
    {
        Http::fake(['edge.ippanel.com/*' => Http::response($this->okResponse())]);

        foreach (['09121234567', '9121234567', '989121234567', '+989121234567', '00989121234567'] as $input) {
            $this->sender()->send($input, 'x');
        }

        Http::assertSentCount(5);

        Http::assertSent(function ($request) {
            $this->assertSame(['+989121234567'], $request->data()['params']['recipients']);

            return true;
        });
    }
}
