<?php

namespace App\Services\AiProviders;

use App\Models\AiModel;

/**
 * قراردادِ درایورِ تماسِ زنده — مرزِ فنیِ دروازهٔ AI.
 *
 * `ai_providers.driver` فقط شناسه‌ی درایور را نگه می‌دارد؛ ساختِ آبجکت
 * کارِ AiCaller است (رجیستری هرگز کلاس نمی‌سازد). پیاده‌سازی‌ها:
 *
 *   OpenAI-Compatible → OpenAiCompatibleDriver (POST /chat/completions)
 *
 * درایور فقط می‌راند و پاسخِ خام را برمی‌گرداند؛ admission، قیمت، رزرو و
 * تسویه هرگز داخلِ درایور نیستند.
 */
interface AiProviderDriver
{
    /**
     * یک تماسِ chat — خروجیِ بدنهٔ موفقِ ارائه‌دهنده.
     *
     * @throws AiProviderCallException هر شکستِ پیکربندی/شبکه/HTTP
     */
    public function chat(AiModel $model, array $payload): DriverCallResult;
}
