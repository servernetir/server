<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * «پول گرفته‌ایم و مشتری سرور ندارد» — یک تعریف، برای همهٔ ناظرها.
 *
 * ═══ 🔴 چرا این کلاس وجود دارد ═══
 *
 * کارفرما یک سرورِ ساعتی فروخت. پرداخت انجام شد، سفارش نزدِ زیرساخت **موفق**
 * بود (ماشین در پنلِ آنها ACTIVE است و اجاره‌اش را ما می‌دهیم)، ولی مشتری نه
 * سرور گرفت نه ایمیل. و در `/admin/errors` **صفر** خطای تحویل ثبت شده بود.
 * کارفرما فقط چون خودش پنلِ زیرساخت را باز کرد فهمید.
 *
 * علتِ سکوت این بود که ناظرها از چیزی می‌پرسیدند که خراب نبود:
 *
 *   `CloudProvisioner::finalize()` همان لحظه‌ای که زیرساخت **سفارش** را
 *   می‌پذیرد `provision_status='done'` و `status='active'` می‌نویسد — پیش از
 *   اینکه شناسهٔ واقعیِ سرور، IP، یا ایمیلی وجود داشته باشد.
 *
 * و `SystemHealth::stuckServices()` — تنها چکی که بالای `/admin/errors`
 * دیده می‌شود و روی تغییرِ وضعیت به مدیر **پیام می‌فرستد** — فقط
 * `pending/running/failed/manual` را می‌شمارد. یک سرویسِ `done` برایش وجود
 * ندارد. پس صف سبز بود در حالی که مشتری دستِ خالی مانده بود.
 *
 * دقیقاً همان الگویی که CLAUDE.md ثبتش کرده: کرونِ تحویل یک‌بار
 * `whereNotNull('server_id')` داشت و هر سرویسِ ابری را بی‌صدا رد می‌کرد.
 * **پرس‌وجوی ناظر باید همان خرابی را ببیند، نه یک همسایه‌اش.**
 *
 * ═══ تعریف ═══
 *
 * تحویل وقتی «انجام شده» است که مشتری بتواند از سرور استفاده کند:
 * ردیفِ نمونه وجود داشته باشد، شناسهٔ واقعیِ زیرساخت داشته باشد (نه خالی، نه
 * `order:…`)، IP داشته باشد، و ایمیلِ تحویلش رفته باشد. هر چیزِ کمتر از این،
 * هر برچسبی که در `provision_status` خورده باشد، «تحویل‌نشده» است.
 */
class CloudDeliveryWatch
{
    /** پس از این دقیقه، تحویلِ ناتمام دیگر «در حالِ انجام» نیست، «گیر کرده» است */
    public const STALLED_MINUTES = 20;

    /**
     * سرویس‌های ابریِ پرداخت‌شده‌ای که تحویلشان ناتمام مانده است.
     *
     * ⚠️ به‌جای `Service` خودِ ردیفِ سرویس برمی‌گردد تا فراخوان بتواند به مشتری
     * و شناسه دسترسی داشته باشد؛ نمونه‌اش با `cloudInstance` قابلِ گرفتن است.
     *
     * @return \Illuminate\Support\Collection<int, Service>
     */
    public static function stalled(?int $minutes = null): \Illuminate\Support\Collection
    {
        $minutes ??= self::STALLED_MINUTES;

        if (! Schema::hasTable('services') || ! Schema::hasTable('cloud_instances')) {
            return collect();
        }

        /*
        | سرویسِ ابری = سرویسی که پلنِ ابری دارد یا ردیفِ نمونه دارد.
        |
        | ⚠️ هر دو شرط لازم است: `cloud_plan_id` **در لحظهٔ finalize** نوشته
        | می‌شود، پس سرویسی که پیش از آن گیر کرده باشد فقط از راهِ نمونه پیدا
        | می‌شود؛ و برعکس، سرویسی که ردیفِ نمونه‌اش اصلاً ساخته نشده فقط از راهِ
        | پلن. دیدنِ یکی و ندیدنِ دیگری همان کوریِ اولیه است از درِ دیگر.
        */
        $cutoff = now()->subMinutes($minutes);

        $rows = Service::query()
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->where(function ($q) {
                $q->whereNotNull('cloud_plan_id')
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')
                        ->from('cloud_instances')
                        ->whereColumn('cloud_instances.service_id', 'services.id'));
            })
            /*
            | `none` یعنی «هیچ صفی این را نمی‌خواهد» (سفارشِ رهاشده / آزادشده).
            |
            | ⚠️ `whereNotIn` تنهایی کافی **نیست**: ستون nullable است و در SQL
            | مقایسهٔ NULL با هر فهرستی NULL می‌دهد، یعنی ردیفِ NULL بی‌صدا از
            | پرس‌وجو بیرون می‌افتد. دقیقاً همان جنسِ کوری که این کلاس برای
            | شکستنش نوشته شد — این‌بار در خودِ ناظر.
            */
            ->where(fn ($q) => $q->whereNull('provision_status')->orWhereNotIn('provision_status', ['none']))
            // پیش‌فیلترِ درشت فقط برای اینکه کلِ جدول به حافظه نیاید؛ سنجشِ
            // دقیقِ سن پایین‌تر انجام می‌شود.
            ->where(function ($q) use ($cutoff) {
                $q->where('created_at', '<', $cutoff)
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')
                        ->from('cloud_instances')
                        ->whereColumn('cloud_instances.service_id', 'services.id')
                        ->where('cloud_instances.created_at', '<', $cutoff));
            })
            ->with('cloudInstance')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return $rows
            ->filter(fn (Service $s) => self::waitingSince($s)?->lt($cutoff) === true)
            ->filter(fn (Service $s) => self::reasonFor($s) !== null)
            ->values();
    }

    /**
     * از کی مشتری منتظرِ **همین** تحویل است؟
     *
     * ⚠️ نه «سنِ سرویس» و نه «سنِ نمونه» به‌تنهایی:
     *
     *   · نمونه هست  ⇒ ساعتِ همین تلاشِ تحویل از ساختِ نمونه شروع می‌شود.
     *     (سرویسِ سه‌ماهه‌ای که همین دقیقه دوباره تحویل می‌شود نباید فوراً
     *     هشدار بدهد؛ ساختِ سرور واقعاً چند دقیقه طول می‌کشد.)
     *   · نمونه نیست ⇒ اصلاً تلاشی ثبت نشده، پس مبنا خودِ خرید است.
     */
    private static function waitingSince(Service $service): ?\Illuminate\Support\Carbon
    {
        $instance = $service->relationLoaded('cloudInstance')
            ? $service->cloudInstance
            : $service->cloudInstance()->first();

        $at = $instance?->created_at ?? $service->created_at;

        return $at instanceof \Illuminate\Support\Carbon ? $at : null;
    }

    /**
     * چرا این سرویس تحویل‌شده حساب نمی‌شود؟ `null` یعنی سالم است.
     *
     * ⚠️ متنِ فارسیِ کوتاه، چون مستقیم در اعلانِ مدیر و در `/admin/errors`
     * نشان داده می‌شود. نامِ زیرساخت عمداً نمی‌آید (قاعدهٔ سفیدبرچسبی).
     */
    public static function reasonFor(Service $service): ?string
    {
        if (in_array($service->status, Service::DEAD_STATUSES, true)) {
            return null;
        }

        if (in_array($service->provision_status, ['manual', 'failed'], true)) {
            // این دو را `SystemHealth::stuckServices()` از قبل می‌بیند؛ دوباره
            // شمردنشان فقط همان هشدار را دو بار می‌سازد.
            return null;
        }

        /** @var CloudInstance|null $instance */
        $instance = $service->relationLoaded('cloudInstance')
            ? $service->cloudInstance
            : $service->cloudInstance()->first();

        if ($instance === null) {
            return $service->provision_status === 'done'
                ? 'سرویس «تحویل‌شده» ثبت شده ولی هیچ سروری برایش ساخته نشده.'
                : 'هنوز هیچ سروری برایش ساخته نشده.';
        }

        if ($instance->status === 'deleted') {
            return null;                       // سرور آگاهانه حذف شده
        }

        $ref = (string) $instance->provider_ref;

        if ($ref === '') {
            return 'سرور سفارش داده شد ولی شناسه‌اش نزدِ زیرساخت معلوم نیست — '
                .'ممکن است ماشینی خریده و بی‌صاحب مانده باشد.';
        }

        if (str_starts_with($ref, 'order:')) {
            return 'سفارش نزدِ زیرساخت ثبت شد ولی هرگز به سرورِ واقعی تبدیل نشد.';
        }

        if (blank($instance->ipv4)) {
            return 'سرور ساخته شد ولی هنوز IP ندارد.';
        }

        if ($instance->owesReadyNotice()) {
            return 'سرور آماده است ولی ایمیلِ تحویلش نرفته.';
        }

        return null;
    }
}
