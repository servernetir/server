<?php

namespace App\Services\Sms;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * پیامکِ بین‌المللی از راهِ Amazon SNS — برای مشتریِ غیرایرانی.
 *
 * ═══ چرا ═══
 *
 * اپراتورهای ایرانی (و رلهٔ n8n) فقط شمارهٔ ۰۹ می‌فرستند؛ مشتریِ خارجی تا
 * امروز اصلاً شماره نمی‌داد. حالا ثبت‌نامِ en/tr موبایل می‌خواهد و کدِ
 * تأییدش از SNS می‌رود (تصمیمِ کارفرما — ۵ شهریور ۱۴۰۵).
 *
 * ═══ قراردادها ═══
 *
 * - فقط شمارهٔ E.164 (`+…`) — مسیریابی در OtpService است: ۰۹ به درایورِ
 *   ایرانی، `+` به این‌جا. این کلاس شمارهٔ بی‌`+` را نمی‌پذیرد.
 * - امضای SigV4 دستی است (SDK نصب نیست و برای چند Action ارزشش را ندارد) —
 *   همان الگوی OVH: بدنه باید **بایت‌به‌بایت** همان باشد که امضا شده.
 * - SMSType=Transactional: مسیرِ OTPِ آمازون، اولویتِ تحویل دارد و از سقفِ
 *   promotional جدا است.
 * - کلیدها در Setting (رمزنگاری‌شده) — نه .env؛ مدیر در تنظیمات → عمومی
 *   واردشان می‌کند. نبودشان ⇒ enabled()=false ⇒ صادرکنندهٔ کد خطای روشن
 *   می‌دهد، نه شکستِ خاموش.
 * - پاسخِ موفق XML است و MessageId دارد؛ کدِ ۲۰۰ به‌تنهایی ملاک نیست
 *   (قاعدهٔ ثبت‌شدهٔ این پروژه: «۲۰۰ ولی نرفت»).
 *
 * ═══ SMS Sandbox (۶ شهریور ۱۴۰۵) ═══
 *
 * حسابِ AWS تا تأییدِ کیسِ «SMS Production Access» در سندباکس است: Publish
 * به شمارهٔ تأییدنشده ۲۰۰ و MessageId می‌دهد ولی **هرگز تحویل نمی‌شود**
 * (در کنسول: Sent 3 / Failed 3). تنها استثنا شماره‌هایی است که در خودِ
 * سندباکس تأیید شده باشند (سقفِ ~۱۰ شماره).
 *
 * راهِ دررو تا خروج از سندباکس: به‌جای Publishِ کدِ خودمان،
 * CreateSMSSandboxPhoneNumber را صدا می‌زنیم — **خودِ آمازون** یک کدِ تأیید
 * به مشتری پیامک می‌کند (این پیام در سندباکس هم تحویل می‌شود)؛ مشتری همان
 * کد را وارد می‌کند و ما با VerifySMSSandboxPhoneNumber می‌سنجیم. موفق =
 * مالکیتِ شماره ثابت شده و از آن پس Publishِ عادی هم به همان شماره می‌رسد.
 *
 * حالتِ سندباکس یک تیکِ صریحِ مدیر است (aws_sns_sandbox) نه حدسِ کد — بعد
 * از تأییدِ کیس، مدیر تیک را برمی‌دارد و مسیرِ عادی برمی‌گردد.
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

    // ───────────────────────── SMS Sandbox ─────────────────────────

    /** آیا مدیر گفته حساب هنوز در SMS Sandbox است؟ */
    public function sandboxMode(): bool
    {
        try {
            return (string) Setting::get('aws_sns_sandbox') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * وضعیتِ شماره در سندباکس: 'Verified' | 'Pending' | null (نیست/ناخوانا).
     *
     * ⚠️ null دو معنی دارد (شماره در فهرست نیست، یا خودِ فهرست خوانده نشد) و
     * عمداً یکی‌اند: در هر دو حالت قدمِ بعدی «تلاش برای Create» است، که خودش
     * جوابِ قطعی می‌دهد. جدا کردنشان هیچ تصمیمی را عوض نمی‌کرد.
     */
    public function sandboxStatus(string $mobile): ?string
    {
        if (! $this->enabled() || ! str_starts_with($mobile, '+')) {
            return null;
        }

        $res = $this->call([
            'Action' => 'ListSMSSandboxPhoneNumbers',
            'MaxResults' => '100',
        ]);

        if ($res === null || ! $res->successful()) {
            return null;
        }

        /*
        | XML: <member><PhoneNumber>+90…</PhoneNumber><Status>Verified</Status></member>
        | شماره را escape می‌کنیم چون + در regex معنی دارد.
        */
        $pattern = '~<PhoneNumber>'.preg_quote($mobile, '~').'</PhoneNumber>\s*<Status>([A-Za-z]+)</Status>~';

        return preg_match($pattern, $res->body(), $m) ? $m[1] : null;
    }

    /**
     * افزودنِ شماره به سندباکس — خودِ AWS کدِ تأیید را پیامک می‌کند.
     * صدازدنِ دوباره برای شمارهٔ Pending همان کد را دوباره می‌فرستد (resend).
     */
    public function sandboxAdd(string $mobile): bool
    {
        if (! $this->enabled() || ! str_starts_with($mobile, '+')) {
            return false;
        }

        $res = $this->call([
            'Action' => 'CreateSMSSandboxPhoneNumber',
            'PhoneNumber' => $mobile,
            'LanguageCode' => 'en-US',
        ]);

        if ($res !== null && $res->successful()) {
            return true;
        }

        $this->noteRefusal('sandbox-add', $res);

        return false;
    }

    /** سنجیدنِ کدی که AWS فرستاده. موفق = شماره برای همیشه Verified. */
    public function sandboxVerify(string $mobile, string $code): bool
    {
        if (! $this->enabled() || ! str_starts_with($mobile, '+')) {
            return false;
        }

        $res = $this->call([
            'Action' => 'VerifySMSSandboxPhoneNumber',
            'PhoneNumber' => $mobile,
            'OneTimePassword' => trim($code),
        ]);

        // کدِ غلط خطای ۴xx می‌دهد؛ آن یک خرابی نیست و ثبت نمی‌شود —
        // کاربر فقط دوباره تلاش می‌کند.
        return $res !== null && $res->successful();
    }

    // ───────────────────────── هستهٔ امضاشده ─────────────────────────

    private function publish(string $mobile, string $text): bool
    {
        if (! $this->enabled() || ! str_starts_with($mobile, '+')) {
            return false;
        }

        $res = $this->call([
            'Action' => 'Publish',
            'PhoneNumber' => $mobile,
            'Message' => $text,
            'MessageAttributes.entry.1.Name' => 'AWS.SNS.SMS.SMSType',
            'MessageAttributes.entry.1.Value.DataType' => 'String',
            'MessageAttributes.entry.1.Value.StringValue' => 'Transactional',
        ]);

        if ($res === null) {
            return false;
        }

        if ($res->successful() && str_contains($res->body(), '<MessageId>')) {
            return true;
        }

        $this->noteRefusal('publish', $res);

        return false;
    }

    /** یک تماسِ امضاشدهٔ SigV4 با SNS؛ null یعنی اصلاً نرسیدیم (شبکه). */
    private function call(array $params): ?Response
    {
        $region = trim((string) Setting::get('aws_sns_region'));
        $host = 'sns.'.$region.'.amazonaws.com';

        $params['Version'] = '2010-03-31';
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
            return Http::timeout(12)
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

            return null;
        }
    }

    /*
    | خطای SNS در XML است (<Code>…</Code>) — برای عیب‌یابیِ مدیر لازم است
    | (sandbox؟ سقفِ خرج؟ کشورِ بسته؟) ولی متنِ کامل ممکن است شماره داشته
    | باشد؛ فقط کد و وضعیت ثبت می‌شود.
    */
    private function noteRefusal(string $where, ?Response $res): void
    {
        if ($res === null) {
            return;     // «نرسیدیم» را خودِ call ثبت کرده
        }

        preg_match('~<Code>([^<]{1,80})</Code>~', $res->body(), $m);
        \App\Support\ErrorTracker::noteOnce('sms', new \RuntimeException(
            'SNS refused ('.$where.'): HTTP '.$res->status().' '.($m[1] ?? 'unknown')
        ));
    }
}
