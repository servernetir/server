<?php

namespace App\Services\Ticket;

use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Schema;

/**
 * تیکتِ خودکارِ سفارشِ لایسنس — همان تیکتی که صفحهٔ محصول وعده‌اش را می‌دهد.
 *
 * ═══ چرا لازم است ═══
 *
 * صفحهٔ `/services/licenses` می‌گوید «نتیجه در تیکت اختصاصیِ همان سفارش به شما
 * اعلام می‌شود». بی‌این کلاس آن جمله دروغ بود: سرویسِ لایسنس نه سرور دارد نه
 * دامنه، پس هیچ صفی برنمی‌داشتش و هیچ تیکتی هم ساخته نمی‌شد — مشتری پول
 * می‌داد و در سکوت می‌مانْد.
 *
 * تیکت هم‌زمان دو کار می‌کند:
 *  • برای **مشتری**: جایی که می‌داند باید منتظرِ خبر باشد.
 *  • برای **اپراتور**: کارِ روشن با IP و نامِ پکیج در متن، بی‌آنکه لازم باشد
 *    جای دیگری دنبالش بگردد.
 *
 * ⚠️ idempotent: کلیدش `subject_ref` روی همان سرویس است. پرداختِ دوباره،
 * رفرشِ صفحهٔ درگاه، یا رویدادِ تکراریِ وب‌هوک نباید تیکتِ دوم بسازد — وگرنه
 * اپراتور یک لایسنس را دو بار ثبت می‌کند.
 *
 * ⚠️ هرگز استثنا پرت نمی‌کند: این بعد از **پرداختِ موفق** صدا زده می‌شود و
 * یک خطای تیکت نباید تسویه‌ای که انجام شده را خراب کند.
 */
class LicenseOrderTicket
{
    /** @return Ticket|null تیکتِ ساخته‌شده، یا null اگر لازم/ممکن نبود */
    public static function openFor(Service $service): ?Ticket
    {
        try {
            if (! self::applies($service)) {
                return null;
            }

            // idempotency — تیکتِ همین سرویس از قبل هست؟
            $existing = Ticket::query()
                ->where('subject_ref_type', 'service')
                ->where('subject_ref_id', $service->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $ticket = Ticket::create([
                'customer_id'      => $service->customer_id,
                'number'           => self::number(),
                'subject'          => 'فعال‌سازی لایسنس — '.$service->name,
                'department'       => 'technical',
                'priority'         => 'high',
                /*
                | ⚠️ `status='open'` عمدی است، با اینکه آخرین پیام از سمتِ
                | ماست. `Ticket::scopeOpen()` روی همین ستون است و صفِ کارِ
                | مدیر از آن می‌آید — اگر `answered` بگذاریم، تیکت از صفِ
                | «نیاز به اقدام» بیرون می‌افتد و کسی لایسنس را ثبت نمی‌کند.
                */
                'status'           => 'open',
                'last_reply_role'  => 'staff',
                'last_reply_at'    => now(),
                'subject_ref_type' => 'service',
                'subject_ref_id'   => $service->id,
            ]);

            TicketMessage::create([
                'ticket_id'   => $ticket->id,
                // ⚠️ `author_role` فقط customer|staff را می‌شناسد
                // (`TicketMessage::fromStaff()`). مقدارِ سومی مثلِ `system` در
                // ویو به‌عنوانِ پیامِ **خودِ مشتری** رندر می‌شود — یعنی مشتری
                // پیامی می‌بیند که انگار خودش نوشته.
                'author_role' => 'staff',
                'author_name' => 'سرورنت',
                'is_internal' => false,
                'body'        => self::body($service),
            ]);

            return $ticket;
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('ticket', $e, [
                'step' => 'license-ticket', 'service' => $service->id,
            ]);

            return null;
        }
    }

    /**
     * فقط سفارشِ لایسنس.
     *
     * ═══ چرا نشانه «دامنه‌ای که IP است» ═══
     *
     * `StoreController::orderLicense()` عمداً IP را در ستونِ `domain` می‌نشانَد
     * تا `applyPaid` («دامنهٔ پرشده + بی‌سرور» ⇒ صفِ دستی) بدونِ هیچ تغییری
     * کار کند. پس همان ستون هم‌زمان دامنهٔ واقعی و IP نگه می‌دارد.
     *
     * 🔴 شرطِ «دامنه دارد و سرور ندارد» به‌تنهایی **خیلی شل است**: هر سفارشِ
     * هاستی که هنوز سرورش انتخاب نشده هم در آن می‌افتد و برایش تیکتِ
     * «فعال‌سازی لایسنس» باز می‌شود — پیامی بی‌ربط به مشتری، و کارِ الکی در
     * صفِ اپراتور.
     *
     * `FILTER_VALIDATE_IP` دقیق و خودتوضیح است: سرویسی که «دامنه»‌اش یک نشانیِ
     * IP است، لایسنس است. عمداً به `category` یا نامِ پکیج گره نخورده — آن‌ها
     * متنِ بازاریابی‌اند و فردا عوض می‌شوند.
     */
    public static function applies(Service $service): bool
    {
        return filled($service->domain)
            && filter_var((string) $service->domain, FILTER_VALIDATE_IP) !== false
            && $service->server_id === null
            && ! $service->isCloud()
            && Schema::hasTable('tickets')
            && Schema::hasTable('ticket_messages');
    }

    private static function body(Service $service): string
    {
        return implode("\n", [
            'سفارش شما ثبت و پرداخت شد. ✅',
            '',
            'پکیج: '.$service->name,
            'IP سرور برای فعال‌سازی: '.$service->domain,
            '',
            'لایسنس روی همین IP فعال می‌شود و نتیجه را در همین تیکت به شما اعلام می‌کنیم.',
            'اگر IP را اشتباه وارد کرده‌اید، همین‌جا پاسخ دهید تا پیش از ثبت اصلاحش کنیم.',
        ]);
    }

    /**
     * شمارهٔ تیکت به همان قالبِ بقیهٔ سیستم.
     *
     * ⚠️ اگر ستونِ `number` یکتا باشد و تصادف بخورد، `openFor` استثنا را
     * می‌گیرد و پرداخت سالم می‌مانَد — ولی تیکت ساخته نمی‌شود. پس فضای
     * تصادف عمداً بزرگ است.
     */
    private static function number(): string
    {
        return 'TK-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}
