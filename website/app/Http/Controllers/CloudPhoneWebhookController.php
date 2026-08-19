<?php

namespace App\Http\Controllers;

use App\Services\CloudPhone\CallIngestor;
use App\Support\ErrorTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * وبهوکِ تلفن ابری «دفتر شما».
 *
 * ═══ 🔴 چرا رازِ ما در مسیرِ URL است — و چرا این‌بار قابلِ دفاع است ═══
 *
 * قاعدهٔ ثبت‌شدهٔ پروژه (CLAUDE.md §۸.۵) می‌گوید توکن هرگز نباید در مسیر URL
 * باشد؛ یک بار کلیدِ DeepSeek همین‌طور لو رفت. ولی فرمِ «دفتر شما» **فقط یک
 * آدرس** می‌گیرد — نه هدر، نه فیلدِ راز. با ۱۰ رویدادِ واقعی تأیید شد:
 *
 *     هدرهای دریافتی: host, x-forwarded-*, content-length, accept, content-type
 *     ❌ هیچ Authorization، هیچ امضا، هیچ x-signature
 *
 * پس مسیر تنها راه است، و ریسک باید **خنثی** شود نه پذیرفته. چهار لایه:
 *
 *   ۱) توکنِ پرآنتروپی در مسیر، با `hash_equals`
 *   ۲) IP allowlist — عملاً جای همان هدرِ گم‌شده را می‌گیرد
 *   ۳) 🔴 وبهوک «محرک» است نه «منبعِ حقیقت» (پایین)
 *   ۴) محدودیتِ نرخ روی روت
 *
 * ═══ ۳: چرا داده‌ی وبهوک قابلِ اعتماد نیست ═══
 *
 * هر کسی که مسیر را بداند می‌تواند رویدادِ جعلی بفرستد. پس هر چیزی که از
 * این‌جا می‌آید **ادعا** است، نه واقعیت. ذخیره‌اش می‌کنیم چون ارزانِ و
 * برگشت‌ناپذیرِ از دست دادنش گران است — ولی هیچ کارِ پول‌دار یا پیام‌فرستی
 * مستقیماً از رویِ آن انجام نمی‌شود. تأییدِ نهایی با `CustomerCallSearch` از
 * خودِ API گرفته می‌شود (فازِ بعد).
 *
 * ═══ چرا همیشه ۲۰۰ — و چرا این خطرناک است ═══
 *
 * وبهوکی که خطا برگرداند معمولاً از سمتِ فرستنده retry و بعد **غیرفعال**
 * می‌شود. پس حتی وقتی داخل خراب می‌شود ۲۰۰ می‌دهیم.
 *
 * 🔴 ولی این دقیقاً الگوی «۲۰۰ ولی نرفت» است که در این پروژه بارها گاز گرفته.
 * پس هر شکستِ داخلی **حتماً** در ErrorTracker می‌نشیند. اگر روزی رویدادها
 * ذخیره نشوند، ۲۰۰ آرام‌بخش نباید کسی را گمراه کند.
 */
class CloudPhoneWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, CallIngestor $ingestor): JsonResponse
    {
        $expected = (string) config('services.cloud_phone.webhook_token');

        /*
        | ⚠️ توکنِ خالی ⇒ رد. بدونِ این، نبودِ `CLOUD_PHONE_WEBHOOK_TOKEN` در
        | `.env` وبهوک را برای همه باز می‌کرد — و چون همه‌چیز کار می‌کرد،
        | هیچ‌کس نمی‌فهمید. پیکربندیِ جاافتاده باید ببندد، نه باز کند.
        */
        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['ok' => false], 404);
        }

        if (! $this->ipAllowed($request)) {
            ErrorTracker::noteOnce(
                'cloud-phone',
                'وبهوک تلفن ابری از IP غیرمجاز: '.(string) $request->ip(),
                900,
                ['ip' => (string) $request->ip()],
            );

            return response()->json(['ok' => false], 403);
        }

        // مهاجرت هنوز اجرا نشده — رویداد را دور نینداز، ولی نشکن
        if (! Schema::hasTable('phone_call_events')) {
            ErrorTracker::noteOnce('cloud-phone', 'جدول phone_call_events وجود ندارد', 3600);

            return response()->json(['ok' => true, 'stored' => false]);
        }

        try {
            $result = $ingestor->ingest((array) $request->json()->all());
        } catch (\Throwable $e) {
            ErrorTracker::note('cloud-phone', $e, ['path' => $request->path()]);

            return response()->json(['ok' => true, 'stored' => false]);
        }

        return response()->json([
            'ok' => true,
            'status' => $result['status'],
        ]);
    }

    /**
     * ⚠️ فهرست از config می‌آید و **آرایه** است، نه یک رشته.
     *
     * در نمونه‌های واقعی هر ۱۰ رویداد از `93.118.115.48` آمد، ولی «دفتر شما»
     * تأیید نکرده که فقط همین یکی را دارد. اگر روزی IP را عوض کنند و ما
     * تک‌مقداری سخت‌کد کرده باشیم، همهٔ رویدادها **بی‌صدا** ۴۰۳ می‌گیرند.
     * پس افزودنِ IP باید یک خطِ `.env` باشد، نه یک دیپلوی.
     *
     * فهرستِ خالی ⇒ بررسی خاموش. عمدی است: در محیطِ تست و در روزِ اولِ
     * راه‌اندازی نباید IP جلوی کار را بگیرد. لایهٔ توکن همچنان برقرار است.
     */
    private function ipAllowed(Request $request): bool
    {
        $allowed = (array) config('services.cloud_phone.webhook_ips', []);
        $allowed = array_values(array_filter(array_map('trim', $allowed)));

        if ($allowed === []) {
            return true;
        }

        return in_array((string) $request->ip(), $allowed, true);
    }
}
