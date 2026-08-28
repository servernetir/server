<?php

namespace App\Services\Sms;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * پیامکِ بین‌المللی از راهِ Amazon SNS — برای مشتریِ غیرایرانی.
 *
 * ═══ چرا ═══
 *
 * اپراتورهای ایرانی (و رلهٔ n8n) فقط شمارهٔ ۰۹ می‌فرستند؛ مشتریِ خارجی تا
 * امروز اصلاً شماره نمی‌داد. حالا ثبت‌نامِ en/tr موبایل می‌خواهد و کدِ
 * تأییدش از SNS می‌رود (تصمیمِ کارفرما — ۵ شهریور ۱۴۰۵؛ حسابش production
 * access دارد).
 *
 * ═══ قراردادها ═══
 *
 * - فقط شمارهٔ E.164 (`+…`) — مسیریابی در OtpService است: ۰۹ به درایورِ
 *   ایرانی، `+` به این‌جا. این کلاس شمارهٔ بی‌`+` را نمی‌پذیرد.
 * - امضای SigV4 دستی است (SDK نصب نیست و برای یک Publish ارزشش را ندارد) —
 *   همان الگوی OVH: بدنه باید **بایت‌به‌بایت** همان باشد که امضا شده.
 * - SMSType=Transactional: مسیرِ OTPِ آمازون، اولویتِ تحویل دارد و از سقفِ
 *   promotional جدا است.
 * - کلیدها در Setting (رمزنگاری‌شده) — نه .env؛ مدیر در تنظیمات → عمومی
 *   واردشان می‌کند. نبودشان ⇒ enabled()=false ⇒ صادرکنندهٔ کد خطای روشن
 *   می‌دهد، نه شکستِ خاموش.
 * - پاسخِ موفق XML است و MessageId دارد؛ کدِ ۲۰۰ به‌تنهایی ملاک نیست
 *   (قاعدهٔ ثبت‌شدهٔ این پروژه: «۲۰۰ ولی نرفت»).
 */
class SnsSender implements SmsSender
{
    public function enabled(): bool
    {
        return filled(Setting::get('aws_sns_key'))
            && filled(Setting::getSecret('aws_sns_secret'))
            && filled(Setting::get('aws_sns_region'));
    }

    public function name(): string
    {
        return 'aws-sns';
    }

    public function send(string $mobile, string $text): bool
    {
        return $this->publish($mobile, $text);
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        // متن انگلیسی: مخاطبِ این مسیر تعریفاً غیرفارسی‌زبان است، و بعضی
        // اپراتورها یونیکد را به چند بخش می‌شکنند (هزینهٔ چندبرابر).
        return $this->publish($mobile, 'Your ServerNet verification code: '.$code);
    }

    private function publish(string $mobile, string $text): bool
    {
        if (! $this->enabled() || ! str_starts_with($mobile, '+')) {
            return false;
        }

        $region = trim((string) Setting::get('aws_sns_region'));
        $host = 'sns.'.$region.'.amazonaws.com';

        $params = [
            'Action' => 'Publish',
            'PhoneNumber' => $mobile,
            'Message' => $text,
            'MessageAttributes.entry.1.Name' => 'AWS.SNS.SMS.SMSType',
            'MessageAttributes.entry.1.Value.DataType' => 'String',
            'MessageAttributes.entry.1.Value.StringValue' => 'Transactional',
            'Version' => '2010-03-31',
        ];

        ksort($params);
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $headers = [
            'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';

        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k.':'.$v."\n";
        }

        $canonical = implode("\n", [
            'POST', '/', '', $canonicalHeaders, $signedHeaders, hash('sha256', $body),
        ]);

        $scope = $date.'/'.$region.'/sns/aws4_request';
        $toSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical),
        ]);

        $secret = (string) Setting::getSecret('aws_sns_secret');
        $kDate = hash_hmac('sha256', $date, 'AWS4'.$secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 'sns', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $toSign, $kSigning);

        $auth = 'AWS4-HMAC-SHA256 Credential='.trim((string) Setting::get('aws_sns_key')).'/'.$scope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;

        try {
            $res = Http::timeout(12)
                ->withHeaders([
                    'Authorization' => $auth,
                    'X-Amz-Date' => $amzDate,
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                ])
                ->withBody($body, 'application/x-www-form-urlencoded; charset=utf-8')
                ->post('https://'.$host.'/');
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::noteOnce('sms', new \RuntimeException(
                'SNS unreachable: '.class_basename($e)
            ));

            return false;
        }

        if ($res->successful() && str_contains($res->body(), '<MessageId>')) {
            return true;
        }

        /*
        | خطای SNS در XML است (<Code>…</Code>) — برای عیب‌یابیِ مدیر لازم است
        | (sandbox؟ سقفِ خرج؟ کشورِ بسته؟) ولی متنِ کامل ممکن است شماره داشته
        | باشد؛ فقط کد و وضعیت ثبت می‌شود.
        */
        preg_match('~<Code>([^<]{1,80})</Code>~', $res->body(), $m);
        \App\Support\ErrorTracker::noteOnce('sms', new \RuntimeException(
            'SNS refused: HTTP '.$res->status().' '.($m[1] ?? 'unknown')
        ));

        return false;
    }
}
