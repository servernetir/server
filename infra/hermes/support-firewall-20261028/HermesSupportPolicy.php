<?php

namespace App\Services\Hermes;

/** A ticket is untrusted input, never permission to execute an instruction. */
final class HermesSupportPolicy
{
    public const CLARIFICATION = 'لطفاً می‌فرمایید هنگام باز کردن سرویس چه پیامی می‌بینید؟';
    public function classify(string $subject, string $body, string $department): string
    {
        $text = $this->normalize($subject."\n".$body);
        $department = $this->normalize($department);
        if (preg_match('~(?:bearer\s+[a-z0-9._-]{8,}|password|passwd|api[_ -]?key|access[_ -]?token|secret|رمز\s*عبور|توکن|کلید\s*خصوصی|-----BEGIN [A-Z ]*PRIVATE KEY-----)~iu', $text)) {
            return 'credentials';
        }
        if (preg_match('~(?:refund|payment|invoice|billing|chargeback|discount|credit|wallet|money|currency|crypto|\b(?:usd|eur|usdt|btc)\b|دلار|یورو|تتر|ارز|تومان|ریال|جبران|صورتحساب|عودت|سپرده|قیمت|تعرفه|مالی|پرداخت|فاکتور|تخفیف|استرداد|بازگشت\s*وجه|کیف\s*پول|تراکنش|(?<![\p{L}\p{N}])رسید(?![\p{L}\p{N}])|هزینه|اعتبار)~iu', $department."\n".$text)) {
            return 'financial';
        }
        if (preg_match('~(?:firewall|iptables|nftables|root|sudo|credential|permission|private\s*key|ssh[- ]?key|vpn|routing|relay|security|فایروال|دسترسی|رمز|احراز|امنیت|کلید|شبکه|رله|نفوذ|مسدود|پورت|مجوز)~iu', $text)) {
            return 'security_or_shared_infrastructure';
        }
        // Reported impact needs owner review even without a requested mutation.
        // This classification records a concern, never verified loss or an outage.
        if ($this->reportsServiceIncident($text)) {
            return 'service_incident';
        }
        if (preg_match('~(?:reinstall|reboot|restart|delete|terminate|provision|restore|backup|format|upgrade|migration|shutdown|reset|suspend|cancel|redeploy|rescale|detach|disable|enable|\bstop\b|خاموش|روشن|تعلیق|نصب\s*مجدد|راه.?اندازی\s*مجدد|ری.?استارت|ری.?بوت|حذف|پاک\s*کرد|خرید|تحویل|تمدید|لغو|بازیابی|پشتیبان|بک.?آپ|ارتقا|تغییر)~iu', $text)) {
            return 'state_change';
        }
        if (! preg_match('~(?:technical|support|general|فنی|پشتیبانی|عمومی)~iu', $department)) {
            return 'unclassified_department';
        }
        return trim($body) === '' ? 'missing_text' : 'low_risk';
    }

    /** Only this locally authored question can be sent without human review. */
    public function automaticReply(string $classification, string $source): ?string
    {
        $source = $this->normalize($source);
        if ($classification !== 'low_risk' || mb_strlen($source) > 200 || ! preg_match('~(?:باز\s*نمی|کار\s*نمی|نمی\s*شود|مشکل|اشکال)~iu', $source)) {
            return null;
        }
        // A concrete error/log is already supplied: don't ask a canned repeat.
        if (preg_match('~(?:\b(?:error|exception|traceback|http)\b|[0-9۰-۹٠-٩]|https?://|[`<>]|\n\S+\s+\S+\s+\S+)~iu', $source)) {
            return null;
        }
        return self::CLARIFICATION;
    }

    public function maySend(string $classification, string $draft, string $source): bool
    {
        return $draft === self::CLARIFICATION && $this->automaticReply($classification, $source) === $draft;
    }

    private function normalize(string $text): string
    {
        return str_replace(['ي', 'ك'], ['ی', 'ک'], preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text) ?? $text);
    }

    /** A reported concern only; useful to gate even a first-message investigation. */
    public function reportsServiceIncident(string $text): bool
    {
        $text = $this->normalize($text);
        $data = '(?:data|files?|database|disk|volume|storage|داده|اطلاعات|فایل|دیتابیس|دیتا|دیسک|ذخیره|veri|dosya|disk)';
        $loss = '(?:\b(?:loss|lost|missing|gone|disappeared|deleted|corrupt(?:ed|ion)?)\b|از\s*دست\s*(?:رفت|رفته)|از\s*بین\s*(?:رفت|رفته)|ناپدید|گم\s*شد|حذف\s*شد|پاک\s*شد|kayboldu|kaybolmuş|kayıp|silindi|bozuldu)';
        if (preg_match('~'.$data.'[\s\S]{0,80}'.$loss.'|'.$loss.'[\s\S]{0,80}'.$data.'~iu', $text)) {
            return true;
        }
        $repeated = preg_match('~دوباره|بازم|باز\s*هم|مجدد|مکرر|پیاپی|هر\s*روز|\b(?:again|repeated(?:ly)?|recurr(?:ing|ed|ent)|intermittent)\b|tekrar|yeniden|sürekli|kesintili~iu', $text);
        $outage = preg_match('~قطع|وصل\s*نمی|کار\s*نمی|از\s*کار\s*افت|\b(?:disconnect(?:ed|s|ing)?|outages?|down(?:time)?|unavailable|unreachable|offline)\b|not\s+(?:working|connecting)|bağlanam|kesil|çalışmıyor~iu', $text);
        return $repeated === 1 && $outage === 1;
    }
}
