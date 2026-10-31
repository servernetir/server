<?php

namespace App\Services\Hermes;

/** Bounded owner-only previews. The original draft/hash remains the approval source. */
final class HermesOwnerNotification
{
    public static function proposal(array $context, string $draft, string $analysis): string
    {
        $preview = self::excerpt($draft, 430);

        return self::header($context, '🎫 پاسخ پیشنهادی هرمس')
            ."\nدرخواست مشتری: ".self::excerpt((string) ($context['question'] ?? ''), 180)
            ."\nجمع‌بندی بررسی: ".self::excerpt($analysis, 190)
            ."\n\nپیش‌نمایش پاسخ:\n".$preview
            ."\n\nهنوز برای مشتری ارسال نشده. «متن کامل» پیش‌نویس ذخیره‌شده را نشان می‌دهد؛ تأیید فقط همان متن کامل را می‌فرستد.";
    }

    public static function investigation(array $context, string $analysis): string
    {
        return self::header($context, '⚠️ تیکت نیازمند بررسی فنی')
            ."\nدرخواست مشتری: ".self::excerpt((string) ($context['question'] ?? ''), 180)
            ."\nعلت/شاهد: ".self::excerpt($analysis, 430)
            ."\nاقدام بعدی: جزئیات بررسی را ببین؛ پاسخ دستی بنویس یا بررسی را تکمیل کن. پاسخ آماده‌ای برای ارسال تأیید نشده است.";
    }

    public static function failure(array $context, string $reason): string
    {
        $label = [
            'writer_unavailable' => 'مدل پیش‌نویس معتبر برنگرداند',
            'draft_unavailable' => 'مدل پس از تلاش‌های مجاز، پیش‌نویس معتبر برنگرداند',
            'draft_or_note_failed' => 'تولید پیش‌نویس یا ثبت یادداشت داخلی کامل نشد',
            'draft_contains_credentials' => 'خروجی مدل اطلاعات حساس داشت و برای ارسال مسدود شد',
            'credentials' => 'متن مشتری اطلاعات حساس دارد؛ بررسی امن در پنل لازم است',
            'owner_exclusion' => 'این تیکت طبق تصمیم مدیر از پاسخ خودکار مستثنا شده است',
            'review_exception' => 'اجرای بررسی فنی هرمس کامل نشد',
            'ticket_review_failed' => 'بررسی فنی این تیکت پس از تلاش‌های مجاز کامل نشد',
            'owner_binding_unavailable' => 'اتصال مدیر برای دریافت پیشنهاد قابل تأیید نیست',
            'delivery_uncertain' => 'نتیجه ارسال قبلی نامشخص است؛ ارسال دوباره مجاز نیست',
            'sent_notification_error' => 'پاسخ در تیکت ثبت شده ولی اعلان مشتری ناموفق بوده است',
        ][$reason] ?? 'بررسی خودکار هرمس به نتیجه قابل اتکا نرسید';

        $title = in_array($reason, ['credentials', 'owner_exclusion'], true) ? '⚠️ تیکت نیازمند پاسخ دستی' : '🔴 اختلال بررسی هرمس';
        return self::header($context, $title)
            ."\nعلت: ".$label
            ."\nاقدام بعدی: جزئیات همین تیکت و خطای ثبت‌شده را بررسی کن؛ در صورت نیاز پاسخ دستی بده.";
    }

    private static function header(array $context, string $title): string
    {
        $ticket = self::excerpt((string) ($context['ticket_number'] ?? ''), 35);
        if ($ticket === 'ثبت نشده') { $ticket = '#'.max(0, (int) ($context['ticket_id'] ?? 0)); }
        $name = self::excerpt((string) ($context['customer_name'] ?? ''), 65);
        $code = self::excerpt((string) ($context['customer_code'] ?? ''), 20);
        $serviceId = (int) ($context['service_id'] ?? 0);
        $service = $serviceId > 0 ? '#'.$serviceId : 'تعیین نشده';
        $provider = self::excerpt((string) ($context['provider_label'] ?? ''), 50);
        $ip = filter_var($context['ipv4'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        return $title.' — '.$ticket."\nمشتری: ".$name.($code !== 'ثبت نشده' ? ' · '.$code : '')
            ."\nسرویس: ".$service.' · زیرساخت: '.$provider.($ip ? ' · IP: '.$ip : '');
    }

    public static function excerpt(string $text, int $limit): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = preg_replace('~(?:Bearer\s+|sk-[A-Za-z0-9_-]{8,})\S*~i', '[محرمانه]', $text) ?? '';
        $text = preg_replace('~((?:password|passwd|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|secret|رمز(?: عبور)?|توکن)\s*[=:]\s*)[^\s,;]+~iu', '$1[محرمانه]', $text) ?? '';
        $text = preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~iu', '$1', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') { return 'ثبت نشده'; }

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, max(1, $limit - 1))).'…' : $text;
    }
}
