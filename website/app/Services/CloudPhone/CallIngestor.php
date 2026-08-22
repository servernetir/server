<?php

namespace App\Services\CloudPhone;

use App\Models\PhoneCall;
use App\Models\PhoneCallEvent;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

/**
 * ثبتِ یک رویدادِ وبهوک و بازسازیِ مکالمه‌اش.
 *
 * ═══ سه قاعده‌ای که کلِ این کلاس رویشان بنا شده ═══
 *
 * ۱) **رویدادها تضمین‌شده نیستند.** در نمونهٔ واقعی، یکی از پاها هیچ‌وقت
 *    `Ended` نگرفت. پس هیچ‌جا فرض نمی‌کنیم جریان کامل است، و همگام‌سازیِ
 *    دوره‌ای با CDR تورِ ایمنی می‌ماند.
 *
 * ۲) **ترتیبِ رسیدن تضمین‌شده نیست.** در مکالمهٔ اول، `Ended`ِ پایِ انتقال
 *    (۱۱:۴۱:۰۳) **قبل از** `Ended`ِ پایِ اصلی (۱۱:۴۱:۰۶) رسید. پس جمع‌بندی
 *    همیشه از **کلِ** رویدادها ساخته می‌شود، نه به‌صورتِ افزایشی.
 *
 * ۳) **یک مکالمه چند `Ended` می‌دهد** — یکی به ازای هر پا.
 */
final class CallIngestor
{
    public const STORED = 'stored';

    public const DUPLICATE = 'duplicate';

    public const INVALID = 'invalid';

    public const UNKNOWN_EVENT = 'unknown_event';

    public function __construct(private readonly CustomerMatcher $matcher) {}

    /**
     * @return array{status: string, call_reference_id: ?string}
     */
    public function ingest(array $body): array
    {
        $payload = WebhookPayload::fromArray($body);

        if ($payload === null) {
            return ['status' => self::INVALID, 'call_reference_id' => null];
        }

        /*
        | رویدادِ ناشناخته **رد نمی‌شود** — ذخیره می‌شود و هشدار می‌گیرد.
        |
        | 🔴 اگر روزی رویدادِ تازه‌ای اضافه کنند (مثلاً CallOutgoingStarted که
        | امروز نداریم)، دور انداختنش یعنی داده‌ای که هرگز برنمی‌گردد. و
        | هشدارِ خاموش یعنی ماه‌ها بعد بفهمیم.
        */
        if (! $payload->isKnownEvent()) {
            ErrorTracker::noteOnce(
                'cloud-phone',
                'رویداد ناشناختهٔ تلفن ابری: '.$payload->eventType,
                3600,
                ['event_type' => $payload->eventType],
            );
        }

        $created = false;

        $event = PhoneCallEvent::query()->firstOrCreate(
            ['event_id' => $payload->idempotencyKey()],
            $this->eventAttributes($payload),
        );

        $created = $event->wasRecentlyCreated;

        if (! $created) {
            // ارسالِ دوباره — هیچ کاری لازم نیست، ولی ۲۰۰ می‌گیرد
            return ['status' => self::DUPLICATE, 'call_reference_id' => $payload->callReferenceId];
        }

        $this->rebuild($payload->callReferenceId);

        return [
            'status' => $payload->isKnownEvent() ? self::STORED : self::UNKNOWN_EVENT,
            'call_reference_id' => $payload->callReferenceId,
        ];
    }

    /**
     * جمع‌بندیِ یک مکالمه را از **کلِ** رویدادهایش می‌سازد.
     *
     * ⚠️ افزایشی نیست و عمداً هم نیست: رویدادها بی‌ترتیب می‌رسند و جمع‌بندیِ
     * افزایشی روی ورودیِ بی‌ترتیب، بی‌صدا غلط می‌شود.
     */
    public function rebuild(string $callReferenceId): ?PhoneCall
    {
        $events = PhoneCallEvent::query()
            ->where('call_reference_id', $callReferenceId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $first = $events->first();
        $ended = $events->filter(fn ($e) => str_ends_with((string) $e->event_type, 'Ended'));

        // ── زمان‌ها ────────────────────────────────────────────────────────
        $startedAt = $events->pluck('started_at')->filter()->min() ?? $first->occurred_at;
        $endedAt = $ended->pluck('ended_at')->filter()->max();

        /*
        | مدتِ مکالمه.
        |
        | ⚠️ `DurationInSeconds` فقط در تماسِ **خروجی** می‌آید. برای ورودی
        | خودمان حساب می‌کنیم. اگر هیچ‌کدام نبود null می‌ماند — «صفر» ننویس،
        | چون صفر یعنی «تماسِ بی‌مکالمه» و آمار را خراب می‌کند.
        */
        $duration = $ended->pluck('duration_seconds')->filter()->max();

        if ($duration === null && $startedAt !== null && $endedAt !== null) {
            $duration = max(0, $endedAt->diffInSeconds($startedAt, absolute: true));
        }

        // ── پاسخ داده شد؟ ──────────────────────────────────────────────────
        /*
        | 🟡 استنتاج از `Result` — مستند نشده. شواهدِ نمونه‌ها:
        |     انتقالِ موفق  ⇒ true/true/true
        |     بی‌پاسخ       ⇒ true/false/false
        |     خروجیِ ۲ثانیه ⇒ false
        |
        | «هر پایی که پاسخ داده شد» را می‌گیریم، نه «آخرین پا» — چون یک مکالمه
        | چند پا دارد و کافی است **یکی** جواب داده باشد تا مشتری با ما حرف زده
        | باشد. آخرین پا ممکن است انتقالِ ناموفق به داخلیِ سوم باشد در حالی که
        | داخلیِ اول جواب داده بود.
        |
        | ⚠️ اگر هیچ `Ended`ی نرسیده، `null` می‌ماند — «نمی‌دانیم»، نه «نه».
        */
        $answered = null;

        if ($ended->isNotEmpty()) {
            $results = $ended->pluck('result')->filter(fn ($v) => $v !== null);
            $answered = $results->isEmpty() ? null : $results->contains(true);
        }

        // ── شمارهٔ تماس‌گیرنده و تطبیقِ مشتری ───────────────────────────────
        $callerNumber = $events->pluck('caller_number')->filter()->first();
        $normalized = $events->pluck('caller_number_norm')->filter()->first();

        $match = $this->matcher->match($callerNumber);

        $attributes = [
            'direction' => $this->directionOf($first->event_type),
            'caller_number' => $callerNumber,
            'callee_extension' => $events->pluck('callee_extension')->filter()->first(),
            'transferred_to_number' => $events->pluck('transferred_to_number')->filter()->last(),
            'caller_number_norm' => $normalized,
            'customer_id' => $match['customer_id'],
            'match_confidence' => $match['confidence'],
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'answered' => $answered,
            'was_transferred' => $events->contains(fn ($e) => str_contains((string) $e->event_type, 'Transfer')),
            'legs' => min(255, $events->pluck('call_id')->unique()->count()),
            'entry_type' => $events->pluck('call_entry_type')->filter()->first(),
            'final_handler' => $events->pluck('final_handler')->filter()->last(),
            'menu_name' => $events->pluck('menu_name')->filter()->last(),
            'menu_input' => $events->pluck('menu_input')->filter()->last(),
            'initiation_source' => $events->pluck('initiation_source')->filter()->first(),
            'last_event_at' => $events->pluck('occurred_at')->filter()->max(),
            'event_count' => $events->count(),
        ];

        return DB::transaction(function () use ($callReferenceId, $attributes) {
            return PhoneCall::query()->updateOrCreate(
                ['call_reference_id' => $callReferenceId],
                $attributes,
            );
        });
    }

    // ──────────────────────────────────────────────────────────────────────

    private function eventAttributes(WebhookPayload $p): array
    {
        return [
            'call_reference_id' => $p->callReferenceId,
            'call_id' => $p->callId,
            'event_type' => $p->eventType,
            /*
            | ⚠️ اگر `DateTime` بدشکل بود، `occurred_at` را از لحظهٔ دریافت
            | می‌گیریم. ستون nullable نیست چون کلِ مرتب‌سازیِ بازسازی رویش است
            | و یک null آن را بی‌صدا به‌هم می‌ریزد.
            */
            'occurred_at' => $p->occurredAt ?? now(),
            'caller_number' => $p->callerNumber,
            'callee_extension' => $p->calleeExtension,
            'transferred_to_number' => $p->transferredToNumber,
            'caller_number_norm' => $p->callerNumberNormalized(),
            'result' => $p->result,
            'call_entry_type' => $p->callEntryType,
            'final_handler' => $p->finalHandler,
            'menu_name' => $p->menuName,
            'menu_input' => $p->menuInput,
            'started_at' => $p->startedAt,
            'ended_at' => $p->endedAt,
            'duration_seconds' => $p->durationSeconds,
            'initiation_source' => $p->initiationSource,
            'payload' => $p->raw,
            'received_at' => now(),
        ];
    }

    private function directionOf(string $eventType): string
    {
        if (str_starts_with($eventType, 'CallIncoming')) {
            return 'incoming';
        }

        if (str_starts_with($eventType, 'CallOutgoing')) {
            return 'outgoing';
        }

        return 'unknown';
    }
}
