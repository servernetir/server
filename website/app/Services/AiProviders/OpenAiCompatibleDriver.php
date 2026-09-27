<?php

namespace App\Services\AiProviders;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Setting;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * درایورِ سازگار با OpenAI — تنها تماس‌گیرِ واقعیِ HTTP در دروازهٔ AI.
 *
 * ═══ اعتبارنامه‌ها ═══
 *
 * 🔴 هیچ کلیدِ API هرگز هاردکد نمی‌شود و هرگز در ستون‌های جدولِ
 *    `ai_providers` نمی‌نشیند. همان قراردادِ یکتایِ ServerNet برای رمزِ
 *    سرویس — `Setting::putSecret/getSecret` (الگویِ `salad_api_key`):
 *
 *        کلیدِ سرویس ← Setting::getSecret('ai_provider_<slug>_key')
 *        آدرسِ پایه ← Setting::get('ai_provider_<slug>_base_url')
 *
 * ═══ شکلِ سیم ═══
 *
 * POST {base_url}/chat/completions با بدنهٔ سازگارِ OpenAI
 * (`Authorization: Bearer <key>`). `base_url` خودش ریشهٔ سازگار است
 * (مثل `https://api.deepinfra.com/v1/openai`).
 *
 * ═══ 🔴 طبقه‌بندیِ شکست — مالکِ تصمیمِ پول (m5-spec §5) ═══
 *
 * «آیا ارائه‌دهنده ممکن است کارِ پول‌دار انجام داده باشد؟» تنها پرسش است:
 *
 *   نرسید (DNS/اتصال/TLS، مهلتِ اتصال)      ⇒ `sent=false`  ⇒ آزادسازی
 *   رسید و کدِ غیرِ ۲xx برگشت                ⇒ `upstream_http` با وضعیت ⇒ آزادسازی (۴۰۱/۴۰۲/۴۰۳ مکثِ ارائه‌دهنده)
 *   رسید و پاسخ نیامد (مهلتِ خواندن، قطع)    ⇒ `upstream_timeout`, sent=true ⇒ نامعلوم: نگه‌داشتن، بازیابی، سقف
 *   ۲xx ولی بدنهٔ غیرِ JSON                  ⇒ `upstream_bad_body`, sent=true ⇒ نامعلوم
 *
 * نسخهٔ قبل مهلتِ خواندن را هم «نرسید» می‌خواند و رزرو را آزاد می‌کرد در حالی که
 * ارائه‌دهنده ممکن بود پولش را از ما گرفته باشد (B8). «فرستاده شد» از آمارِ cURL
 * (`size_upload`) خوانده می‌شود و اگر نبود، از errno ِ پیام.
 */
class OpenAiCompatibleDriver implements AiProviderDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    /** آدرسِ پایه — از تنظیم‌های سرویس؛ نال یعنی ارائه‌دهنده پیکربندی نشده */
    public function baseUrl(): ?string
    {
        $url = Setting::get('ai_provider_'.$this->provider->slug.'_base_url');

        return filled($url) ? rtrim((string) $url, '/') : null;
    }

    /**
     * تماسِ زندهٔ chat.
     *
     * 🔴 این متد هیچ پولی جابه‌جا نمی‌کند و هیچ تصمیمی دربارهٔ پول نمی‌گیرد.
     *
     * @param  int  $timeout  مهلتِ کلِ پاسخ (ثانیه) — AiCaller از O حسابش می‌کند
     */
    public function chat(AiModel $model, array $payload, int $timeout = 60): DriverCallResult
    {
        $baseUrl = $this->baseUrl();
        $apiKey = $this->provider->apiKey();

        if ($baseUrl === null || blank($apiKey)) {
            throw new AiProviderCallException('provider_unconfigured',
                'ارائه‌دهندهٔ «'.$this->provider->slug.'» آدرسِ پایه یا کلیدِ سرویس ندارد.', sent: false);
        }

        $body = array_merge($payload, [
            // اسلاگِ عمومیِ ما هرگز به بالادست نمی‌رود — نامِ واقعیِ مدل
            'model' => $model->upstream_model,
        ]);

        $uploaded = null;

        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout((int) config('ai.http_connect_s', 10))
                ->timeout($timeout)
                ->acceptJson()
                ->withOptions(['on_stats' => function (TransferStats $s) use (&$uploaded) {
                    $uploaded = (int) ($s->getHandlerStats()['size_upload'] ?? 0);
                }])
                ->post($baseUrl.'/chat/completions', $body);
        } catch (ConnectionException $e) {
            $sent = $uploaded !== null ? $uploaded > 0 : self::sentFromMessage($e->getMessage());

            throw $sent
                ? new AiProviderCallException('upstream_timeout',
                    'درخواست به «'.$this->provider->slug.'» رسید ولی پاسخ در مهلت نیامد.', sent: true)
                : new AiProviderCallException('upstream_unreachable',
                    'تماس با ارائه‌دهندهٔ «'.$this->provider->slug.'» برقرار نشد.', sent: false);
        }

        $requestId = $response->header('x-request-id') ?: $response->header('x-deepinfra-request-id') ?: null;

        if (! $response->successful()) {
            throw new AiProviderCallException('upstream_http',
                'ارائه‌دهنده با خطای HTTP '.$response->status().' پاسخ داد.',
                sent: true, status: $response->status(), detail: self::errorDetail($response->json()));
        }

        $json = $response->json();

        if (! is_array($json) || array_is_list($json)) {
            throw new AiProviderCallException('upstream_bad_body',
                'پاسخِ ارائه‌دهنده JSON ِ معتبر نبود.', sent: true, status: $response->status());
        }

        return new DriverCallResult($response->status(), $json, $response->body(),
            $requestId ?: (is_string($json['id'] ?? null) ? $json['id'] : null));
    }

    /**
     * «آیا بدنه فرستاده شد؟» از پیامِ cURL، وقتی آمار نیامده (مثلاً در تست).
     *
     * ۶ (نامِ میزبان)، ۷ (اتصال)، ۳۵ (TLS) و «Connection/Resolving timed out» یعنی
     * اصلاً نرسید. بقیه — به‌ویژه ۲۸ ِ «Operation timed out ... bytes received»، ۵۲
     * (پاسخِ خالی)، ۵۶ (قطعِ دریافت) — یعنی رسید و ممکن است کار انجام شده باشد.
     * ناشناخته ⇒ «رسید»: هزینهٔ بالادست را هرگز جذب نمی‌کنیم (تصمیمِ مالک).
     */
    public static function sentFromMessage(string $message): bool
    {
        if (preg_match('/cURL error (\d+)/', $message, $m) === 1) {
            $errno = (int) $m[1];
            if (in_array($errno, [6, 7, 35], true)) {
                return false;
            }
            if ($errno === 28 && preg_match('/(Connection|Resolving) timed out/i', $message) === 1) {
                return false;
            }

            return true;
        }

        return ! (bool) preg_match('/could not resolve|failed to connect|connection refused/i', $message);
    }

    /** دلیلِ کوتاهِ خطای بالادست برای ۴۰۰/۴۱۳/۴۲۲ — بی نامِ مدلِ واقعی */
    private static function errorDetail(mixed $json): ?string
    {
        $msg = is_array($json) ? ($json['error']['message'] ?? $json['detail'] ?? $json['message'] ?? null) : null;

        return is_string($msg) ? mb_substr($msg, 0, 300) : null;
    }
}
