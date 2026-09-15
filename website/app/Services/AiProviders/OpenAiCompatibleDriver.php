<?php

namespace App\Services\AiProviders;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Setting;
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
 *        کلیدِ ‌سر ← Setting::getSecret('ai_provider_<slug>_key')
 *        آدرسِ  پای ← Setting::get('ai_provider_<slug>_base_url')
 *
 *    (مُدلِ `AiProvider::apiKey()` همین کلید را می‌خوانَد؛ درایور از
 *    همان چاه می‌آشامَد تا دو مسیرِ خواندنِ رمز نسازیم.)
 *
 * ═══ شکلِ سیم ═══
 *
 * POST {base_url}/chat/completions با بدنهٔ سازگارِ OpenAI
 * (`Authorization: Bearer <key>`). `base_url` خودش ریشهٔ سازگار است
 * (مثل `https://api.deepinfra.com/v1/openai`) پس `/chat/completions`
 * دُمش است — نه `/v1/...` دوباره.
 *
 * ═══ خطاها ═══
 *
 * هر شکستِ تماس فقط با `AiProviderCallException` و کدِ پایدارِ API
 * بالا می‌آید (`provider_unconfigured | upstream_unreachable |
 * upstream_error`) — AiCaller همین کدها را بی‌واسطه به پاسخِ نهایی
 * می‌برد؛ هیچ متنِ استثنا به بیرون نشت نمی‌کند.
 */
class OpenAiCompatibleDriver implements AiProviderDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    /** آدرسِ پای — از تنظیم‌های سرویس؛ نال یعنی ارائه‌دهنده پیکربندی نشده */
    public function baseUrl(): ?string
    {
        $url = Setting::get('ai_provider_'.$this->provider->slug.'_base_url');

        return filled($url) ? rtrim((string) $url, '/') : null;
    }

    /**
     * تماسِ زندهٔ chat — خروجی: بدنهٔ پاسخِ ارائه‌دهنده + کدِ HTTP.
     *
     * 🔴 این متد هیچ پولی نمی‌جابه‌جا و هیچ تصمیمی دربارهٔ پول نمی‌گیرد؛
     *    رزرو/تسویه/آزادسازی کارِ AiCaller رویِ AiReservations است.
     */
    public function chat(AiModel $model, array $payload): DriverCallResult
    {
        $baseUrl = $this->baseUrl();
        $apiKey = $this->provider->apiKey();

        if ($baseUrl === null || blank($apiKey)) {
            throw new AiProviderCallException('provider_unconfigured',
                'ارائه‌دهندهٔ «'.$this->provider->slug.'» آدرسِ پای یا کلیدِ سرویس ندارد.');
        }

        $body = array_merge($payload, [
            // اسلاگِ عمومیِ ما هرگز به بالادست نمی‌رود — نامِ واقعیِ مدل
            'model' => $model->upstream_model,
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->acceptJson()
                ->post($baseUrl.'/chat/completions', $body);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new AiProviderCallException('upstream_unreachable',
                'تماس با ارائه‌دهندهٔ «'.$this->provider->slug.'» برقرار نشد.');
        }

        if (! $response->successful()) {
            throw new AiProviderCallException('upstream_error',
                'ارائه‌دهنده با خطای HTTP '.$response->status().' پاسخ داد.');
        }

        return new DriverCallResult($response->status(), $response->json());
    }
}

/**
 * نتیجهٔ موفقِ درایور — بدنهٔ خامِ ارائه‌دهنده، بی‌تفسیرِ پولی.
 * `AiCaller` مالکِ تفسیرِ `usage` و صورت‌حساب است، نه درایور.
 */
final class DriverCallResult
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {}
}

/**
 * شکستِ تماس با کدِ پایدارِ API — تنها مسیرِ خطای درایور.
 * کد (نه پیامِ فارسی) قراردادِ بیرونی است؛ پیام فقط برای انسان.
 */
final class AiProviderCallException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
