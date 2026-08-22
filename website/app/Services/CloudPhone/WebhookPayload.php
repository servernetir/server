<?php

namespace App\Services\CloudPhone;

use App\Support\IranianPhone;
use Carbon\CarbonImmutable;

/**
 * یک رویدادِ وبهوکِ تلفن ابری، پارس‌شده.
 *
 * ═══ چرا DTO و نه آرایهٔ خام ═══
 *
 * payloadِ «دفتر شما» سه تله دارد که هر سه **بی‌صدا** خراب می‌کنند. یک جا
 * پارس می‌شوند تا هر مصرف‌کننده‌ای مجبور نباشد یادش بماند:
 *
 *   ۱) نامِ رویدادها با مستنداتِ پنل فرق دارد (بخش ۱)
 *   ۲) دو فرمتِ تاریخِ متفاوت در یک API (بخش ۲)
 *   ۳) فیلدها بین رویدادها فرق می‌کنند — تقریباً همه‌چیز nullable است
 *
 * منبع: ۱۰ رویدادِ واقعیِ ثبت‌شده در ۱۸ آگوست ۲۰۲۶.
 */
final class WebhookPayload
{
    /*
    |==========================================================================
    | 🔴 نامِ رویدادها با چیزی که در پنل نوشته شده **فرق دارد**
    |==========================================================================
    |
    |   پنل می‌گوید:        Call.incoming.started
    |   واقعاً می‌آید:       CallIncomingStarted
    |
    | نه نقطه‌ای، نه حرفِ کوچکی. اگر روی نام‌های مستندات match می‌کردیم، هیچ
    | رویدادی تطبیق نمی‌خورد — و چون همیشه ۲۰۰ برمی‌گردانیم، از بیرون شبیه
    | موفقیت بود. همان «۲۰۰ ولی نرفت» که در CLAUDE.md ثبت است.
    */
    public const INCOMING_STARTED = 'CallIncomingStarted';

    public const INCOMING_TRANSFER_STARTED = 'CallIncomingTransferStarted';

    public const INCOMING_TRANSFER_COMPLETED = 'CallIncomingTransferCompleted';

    public const INCOMING_ENDED = 'CallIncomingEnded';

    public const OUTGOING_ENDED = 'CallOutgoingEnded';

    public const KNOWN_EVENTS = [
        self::INCOMING_STARTED,
        self::INCOMING_TRANSFER_STARTED,
        self::INCOMING_TRANSFER_COMPLETED,
        self::INCOMING_ENDED,
        self::OUTGOING_ENDED,
    ];

    private function __construct(
        public readonly string $eventType,
        public readonly string $callId,
        public readonly string $callReferenceId,
        public readonly ?string $eventId,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly ?string $callerNumber,
        public readonly ?string $calleeExtension,
        public readonly ?string $transferredToNumber,
        public readonly ?bool $result,
        public readonly ?string $callEntryType,
        public readonly ?string $finalHandler,
        public readonly ?string $menuName,
        public readonly ?string $menuInput,
        public readonly ?CarbonImmutable $startedAt,
        public readonly ?CarbonImmutable $endedAt,
        public readonly ?int $durationSeconds,
        public readonly ?string $initiationSource,
        public readonly array $raw,
    ) {}

    /**
     * `null` یعنی این اصلاً payloadِ تلفن ابری نیست (نه اینکه رویدادش ناشناخته
     * باشد — رویدادِ ناشناخته **پذیرفته** می‌شود و بعداً هشدار می‌گیرد).
     */
    public static function fromArray(array $body): ?self
    {
        $eventType = self::str($body, 'EventType');
        $callId = self::str($body, 'CallId');
        $callRef = self::str($body, 'CallReferenceId');

        if ($eventType === null) {
            return null;
        }

        /*
        | ⚠️ اگر `CallReferenceId` نبود، `CallId` جایش می‌نشیند.
        |
        | در هر ۱۰ نمونه هر دو بودند، ولی «همیشه بوده» تضمین نیست و بدونِ کلیدِ
        | مکالمه هیچ ردیفی در `phone_calls` ساخته نمی‌شود — یعنی رویداد ثبت
        | می‌شود ولی هیچ‌جا دیده نمی‌شود. بدترین نوعِ خرابی.
        */
        $callRef ??= $callId;

        if ($callRef === null) {
            return null;
        }

        return new self(
            eventType: $eventType,
            callId: $callId ?? $callRef,
            callReferenceId: $callRef,
            eventId: self::str($body, 'ClientReferenceId'),
            occurredAt: self::dateTime(self::str($body, 'DateTime')),
            callerNumber: self::str($body, 'CallerNumber'),
            calleeExtension: self::str($body, 'CalleeExtension'),
            transferredToNumber: self::str($body, 'TransferredToNumber'),
            result: array_key_exists('Result', $body) && is_bool($body['Result']) ? $body['Result'] : null,
            callEntryType: self::str($body, 'CallEntryType'),
            finalHandler: self::str($body, 'FinalHandler'),
            menuName: self::str($body, 'MenuName'),
            menuInput: self::str($body, 'MenuInput'),
            startedAt: self::dateTime(self::str($body, 'StartDateTime')),
            endedAt: self::dateTime(self::str($body, 'EndDateTime')),
            durationSeconds: isset($body['DurationInSeconds']) && is_numeric($body['DurationInSeconds'])
                ? (int) $body['DurationInSeconds']
                : null,
            initiationSource: self::str($body, 'CallInitiationSource'),
            raw: $body,
        );
    }

    public function isKnownEvent(): bool
    {
        return in_array($this->eventType, self::KNOWN_EVENTS, true);
    }

    public function direction(): string
    {
        if (str_starts_with($this->eventType, 'CallIncoming')) {
            return 'incoming';
        }

        if (str_starts_with($this->eventType, 'CallOutgoing')) {
            return 'outgoing';
        }

        return 'unknown';
    }

    public function isEnded(): bool
    {
        return str_ends_with($this->eventType, 'Ended');
    }

    public function isTransfer(): bool
    {
        return str_contains($this->eventType, 'Transfer');
    }

    /** شکلِ قابلِ مقایسهٔ شمارهٔ تماس‌گیرنده — یا `null` اگر نشد. */
    public function callerNumberNormalized(): ?string
    {
        return $this->callerNumber === null
            ? null
            : IranianPhone::normalize($this->callerNumber);
    }

    /**
     * کلیدِ idempotency.
     *
     * ⚠️ اگر `ClientReferenceId` نیامد، هشِ قطعیِ payload جایش می‌نشیند. پس
     * ستونِ unique هرگز null نمی‌گیرد و ارسالِ دوبارهٔ همان رویداد بی‌اثر است
     * — حتی وقتی تأمین‌کننده شناسه‌اش را جا بیندازد.
     */
    public function idempotencyKey(): string
    {
        if ($this->eventId !== null) {
            return $this->eventId;
        }

        return 'h:'.hash('sha256', json_encode(
            $this->raw,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: $this->callId.$this->eventType);
    }

    // ──────────────────────────────────────────────────────────────────────

    private static function str(array $body, string $key): ?string
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }

        $v = $body[$key];

        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }

        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /*
    |==========================================================================
    | 🔴 دو فرمتِ تاریخِ متفاوت در یک API
    |==========================================================================
    |
    |   CallIncomingStarted / Ended / CallOutgoingEnded
    |       "2026-08-18T11:41:10.1746124Z"     ISO 8601، صریحاً UTC
    |
    |   CallIncomingTransferStarted / TransferCompleted
    |       "08/18/2026 11:42:08"              🔴 MM/DD/YYYY، بدونِ منطقهٔ زمانی
    |
    | دو خطرِ جدا:
    |
    | ۱) فرمتِ آمریکایی. `08/18/2026` با فرضِ روز-اول یعنی ماهِ ۱۸. `Carbon::parse`
    |    اتفاقاً درست می‌خواند چون پیش‌فرضِ PHP آمریکایی است — ولی به شانس تکیه
    |    نمی‌کنیم؛ صریح می‌نویسیم.
    |
    | ۲) کسرِ ثانیهٔ **۷ رقمی** (فرمتِ .NET). `createFromFormat` با `u` که ۶ رقم
    |    می‌خواهد روی این می‌شکند. برای مسیرِ ISO از `parse` استفاده می‌کنیم که
    |    می‌خواندش، و تستی این را قفل می‌کند.
    |
    | منطقهٔ زمانی: رویدادهای انتقال بی‌timezone‌اند ولی UTC هستند — چون دقیقاً
    | بینِ دو رویدادِ ISO می‌نشینند (۱۱:۴۰:۱۷Z ← ۱۱:۴۰:۴۰ → ۱۱:۴۱:۰۳Z). اگر
    | تهران بودند می‌شدند ۱۵:۱۰.
    */
    public static function dateTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (preg_match('#^\d{2}/\d{2}/\d{4}[ T]\d{2}:\d{2}(:\d{2})?$#', $value) === 1) {
                $format = strlen($value) === 16 ? 'm/d/Y H:i' : 'm/d/Y H:i:s';

                $parsed = CarbonImmutable::createFromFormat($format, str_replace('T', ' ', $value), 'UTC');

                return $parsed === false ? null : $parsed->utc();
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            /*
            | 🔴 `null` و نه استثنا: یک تاریخِ بدشکل نباید کلِ رویداد را دور
            | بیندازد. payloadِ خام ذخیره می‌شود و بعداً قابلِ بازپردازش است —
            | ولی از دست دادنِ رویداد برگشت‌ناپذیر است.
            */
            return null;
        }
    }
}
