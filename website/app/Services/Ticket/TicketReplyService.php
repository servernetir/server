<?php

namespace App\Services\Ticket;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Notify\Notifier;
use Illuminate\Support\Facades\App;

/**
 * «کارمند به تیکت پاسخ داد» — یک پیاده‌سازی، چند فراخوان.
 *
 * ═══ چرا استخراج شد ═══
 *
 * تا امروز کلِ این منطق داخلِ `Admin\TicketController::reply()` بود. حالا رباتِ
 * بله هم باید همان کار را بکند، و «همان کار» چیزِ کوچکی نیست: نوشتنِ پیام،
 * بستن در همان حرکت، و **سه اعلان با ترتیبِ معنادار**. اگر ربات نسخهٔ خودش را
 * می‌نوشت، دو پیاده‌سازی می‌شد و دیر یا زود یکی کهنه — همان تلهٔ «دورهٔ
 * شش‌ماهه در ۷ جا» که کرونِ تمدید را کور کرد.
 *
 * ═══ سه چیزی که ترتیبشان اتفاقی نیست ═══
 *
 * ۱) 🔴 **بستن باید بلافاصله بعد از `addMessage()` بیاید.** `Ticket::addMessage`
 *    برای هر پاسخِ غیرداخلیِ کارمند `closed_at` را **نال** می‌کند (Ticket.php:95)
 *    تا پاسخ روی تیکتِ بسته دوباره بازش کند. پس اگر «پاسخ» و «بستن» را از هم
 *    جدا کنی، تیکت باز می‌مانَد و مشتری فکر می‌کند هنوز منتظرِ ماست.
 *
 * ۲) 🔴 **`ticket_survey` بعد از `ticket_closed` می‌رود، نه به‌جایش.** مشتری
 *    اول باید بداند مشکلش بسته شده، بعد ازش نظر بخواهیم. برعکسش یعنی
 *    نظرسنجی برای کسی می‌رود که هنوز فکر می‌کند تیکتش باز است.
 *
 * ۳) 🔴 **یادداشتِ داخلی هیچ اعلانی ندارد و ردیفِ تیکت را هم تکان نمی‌دهد.**
 *    مشتری نمی‌بیندش، پس نه باید خبردار شود و نه «پاسخ داده شد» بخورد.
 *
 * ═══ زبانِ مشتری، نه زبانِ اپ ═══
 *
 * 🔴 این یک نقصِ **از قبل موجود** را هم می‌بندد: `config/app.php` مقدارِ
 * `APP_LOCALE` را می‌خواند و روت‌های `/admin/*` بیرونِ closureِ `$site` هستند،
 * پس هیچ middlewareِ `locale` رویشان نمی‌دود. یعنی پاسخِ پنل به مشتریِ فارسی،
 * شمارهٔ تیکت را با رقمِ انگلیسی و لینک را با پیشوندِ `/en` می‌فرستاد
 * (`fa_num()` فقط در locale فارسی رقم را تبدیل می‌کند، و `console_lroute()`
 * پیشوند را از `app()->getLocale()` می‌سازد). حالا بلوکِ اعلان داخلِ زبانِ
 * **خودِ مشتری** اجرا می‌شود — چه از پنل بیاید چه از ربات.
 */
class TicketReplyService
{
    /**
     * پاسخِ کارمند (یا یادداشتِ داخلی) + بستنِ اختیاری + اعلان‌ها.
     *
     * @param  callable|null  $afterMessage  قلّابِ بلافاصله پس از ساختِ پیام و
     *                                       **پیش از** بستن — پنل پیوست‌ها را
     *                                       این‌جا ذخیره می‌کند تا ترتیبِ
     *                                       قبلی‌اش مو به مو حفظ شود.
     */
    public function post(
        Ticket $ticket,
        ?int $authorId,
        ?string $authorName,
        string $body,
        bool $internal = false,
        bool $close = false,
        ?callable $afterMessage = null,
    ): TicketMessage {
        $message = $ticket->addMessage('staff', $authorId, $authorName, $body, internal: $internal);

        if ($afterMessage !== null) {
            $afterMessage($message);
        }

        // «پاسخ و بستن» در یک حرکت — کار رایج پشتیبانی.
        // ⚠️ باید همین‌جا باشد؛ چرایش در docblockِ کلاس (بندِ ۱).
        if ($close && ! $internal) {
            $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        }

        // یادداشت داخلی اعلان ندارد — مشتری نمی‌بیندش، پس نباید خبردار شود.
        if (! $internal && $ticket->customer) {
            $this->inCustomerLocale($ticket, function () use ($ticket, $close) {
                $notifier = app(Notifier::class);
                $link     = console_lroute('account.tickets');
                $num      = fa_num((string) $ticket->number);

                $notifier->fire('ticket_reply', $ticket->customer,
                    ['number' => (string) $ticket->number],
                    'پاسخ جدیدی به تیکتِ '.$num.' شما داده شد: '.$link);

                if ($close) {
                    $notifier->fire('ticket_closed', $ticket->customer,
                        ['number' => (string) $ticket->number],
                        '✅ تیکتِ '.$num.' بسته شد. '
                        .'اگر مشکل برطرف نشده، همان‌جا پاسخ بدهید تا دوباره باز شود: '.$link);

                    $notifier->fire('ticket_survey', $ticket->customer,
                        ['number' => (string) $ticket->number, 'link' => $link],
                        'از پشتیبانیِ تیکتِ '.$num.' راضی بودید؟ '
                        .'نظرتان کمکمان می‌کند بهتر شویم: '.$link);
                }
            });
        }

        return $message;
    }

    /**
     * بستنِ تیکت **بدونِ** گفتنِ حرفی.
     *
     * ⚠️ عمداً هیچ ردیفی در `ticket_messages` نمی‌سازد: چیزی گفته نشده، پس
     * `ticket_reply` هم نمی‌رود. فقط «بسته شد» و نظرسنجی.
     *
     * `$notify` عمداً پارامتر است و پیش‌فرضش هم `true`: مشتری‌ای که منتظرِ جواب
     * نشسته باید بداند گفتگو تمام شد. حالتِ بی‌صدا برای تیکت‌های اسپم است.
     */
    public function closeOnly(Ticket $ticket, bool $notify = true): void
    {
        if ($ticket->isClosed()) {
            return;                       // idempotent: بستنِ دوباره اعلانِ دوباره نسازد
        }

        $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        if (! $notify || ! $ticket->customer) {
            return;
        }

        $this->inCustomerLocale($ticket, function () use ($ticket) {
            $notifier = app(Notifier::class);
            $link     = console_lroute('account.tickets');
            $num      = fa_num((string) $ticket->number);

            $notifier->fire('ticket_closed', $ticket->customer,
                ['number' => (string) $ticket->number],
                '✅ تیکتِ '.$num.' بسته شد. '
                .'اگر مشکل برطرف نشده، همان‌جا پاسخ بدهید تا دوباره باز شود: '.$link);

            $notifier->fire('ticket_survey', $ticket->customer,
                ['number' => (string) $ticket->number, 'link' => $link],
                'از پشتیبانیِ تیکتِ '.$num.' راضی بودید؟ '
                .'نظرتان کمکمان می‌کند بهتر شویم: '.$link);
        });
    }

    /**
     * بلوک را در زبانِ مشتری اجرا کن و زبان را حتماً برگردان.
     *
     * ⚠️ `finally` لازم است نه اختیاری: اگر یک اعلان استثنا بدهد و زبان روی
     * فارسی جا بماند، بقیهٔ همان درخواست (پاسخِ پنل، اعلانِ بعدی) با زبانِ
     * اشتباه رندر می‌شود.
     */
    private function inCustomerLocale(Ticket $ticket, callable $fn): void
    {
        $old = App::getLocale();

        try {
            App::setLocale($ticket->customer?->locale ?: 'fa');
            $fn();
        } finally {
            App::setLocale($old);
        }
    }
}
