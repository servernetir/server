<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\Service;
use App\Services\AiContent;

/**
 * خلاصهٔ یک‌نگاهیِ پروندهٔ مشتری، با هوشِ مصنوعی.
 *
 * کاربردش دقیقاً یک لحظه است: تیکتی رسیده، کارفرما در گوشی است و پیش از
 * پاسخ‌دادن می‌خواهد در ده ثانیه بداند طرفِ مقابل کیست — چند سرویس، چقدر
 * بدهی، چند تیکت، تازه‌وارد یا قدیمی.
 *
 * 🔴 **هیچ تصمیمی نمی‌گیرد و هیچ‌چیزی نمی‌نویسد.** خروجی فقط متن است.
 *
 * ═══ چه چیزی به مدل داده می‌شود — و چه چیزی نه ═══
 *
 * ⚠️ ارقام **این‌جا** در PHP شمرده می‌شوند و فقط عددها به مدل می‌روند. مدل
 * هیچ پرس‌وجویی نمی‌کند و هیچ عددی از خودش نمی‌سازد؛ کارش فقط جمله‌بندی است.
 * وگرنه یک «۳ فاکتورِ پرداخت‌نشده»ی توهمی، پایهٔ یک پیامِ اشتباه به مشتری
 * می‌شد.
 *
 * 🔴 **نام، ایمیل، موبایل، دامنه و کدِ ملی هرگز فرستاده نمی‌شوند.** این متن
 * به یک ارائه‌دهندهٔ بیرونی می‌رود؛ برای «این مشتری وضعش چطور است» هیچ‌کدام
 * لازم نیست. همان قاعدهٔ `AdminBaleScreens` که ایمیل و تلفن را حتی به چتِ
 * خودِ کارفرما هم چاپ نمی‌کند.
 */
class CustomerBriefWriter extends AiContent
{
    public function __construct()
    {
        $this->purpose = 'support';
    }

    /** خلاصه، یا null اگر مدل جواب نداد */
    public function brief(Customer $c): ?string
    {
        $facts = $this->facts($c);

        $sys = <<<'TXT'
You brief the owner of ServerNet (a small Iranian hosting company) about one
of their customers, in PERSIAN, before they answer a support ticket.

You receive ONLY pre-computed numbers. Never invent a fact, a name, a date, a
domain, or an amount that is not in the input. If a number is zero, you may
simply omit it rather than saying "zero".

Write 2 to 4 short lines, no bullet characters, no markdown, no greeting.
Line 1: who this customer is in one clause (how long a customer, how active).
Then: anything the owner should know BEFORE replying — unpaid invoices, a
suspended service, a stuck delivery, many recent tickets, or that they are
brand new.
End with nothing if there is nothing notable. Do not give advice about what to
reply. Do not flatter. No exclamation marks.
TXT;

        try {
            $raw = $this->call(
                $sys,
                json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                500,
                45,
            );
        } catch (\Throwable) {
            return null;
        }

        $text = trim((string) $raw);
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $text) ?? $text);
        $text = trim($text, "\"'«» \n\r\t");

        return $text !== '' ? mb_substr($text, 0, 1200) : null;
    }

    /**
     * ارقامِ پرونده — همه شمرده، هیچ‌کدام شناساننده.
     *
     * ⚠️ عمداً `public` است: این **تنها** چیزی است که به ارائه‌دهندهٔ بیرونی
     * می‌رود، و تستِ محافظ باید بتواند بی‌تماسِ شبکه بسنجدش. اگر روزی کسی
     * `displayName()` یا ایمیل را به این آرایه اضافه کند، همان تست قرمز
     * می‌شود — نه ماه‌ها بعد که داده رفته باشد.
     *
     * @return array<string,mixed>
     */
    public function facts(Customer $c): array
    {
        $safe = function (callable $fn, $fallback = 0) {
            try {
                return $fn();
            } catch (\Throwable) {
                return $fallback;
            }
        };

        return [
            'customer_since_days' => $safe(fn () => $c->created_at
                ? (int) $c->created_at->diffInDays(now()) : 0),
            'status'              => (string) $c->status,
            'services_alive'      => $safe(fn () => $c->services()
                ->whereNotIn('status', Service::DEAD_STATUSES)->count()),
            'services_suspended'  => $safe(fn () => $c->services()
                ->where('status', 'suspended')->count()),
            'services_pending_delivery' => $safe(fn () => $c->services()
                ->whereIn('provision_status', ['pending', 'running', 'failed', 'manual'])->count()),
            'invoices_unpaid'     => $safe(fn () => $c->invoices()->where('status', 'unpaid')->count()),
            'unpaid_total_toman'  => $safe(fn () => (int) $c->invoices()
                ->where('status', 'unpaid')->where('currency_code', 'IRT')->sum('total')),
            'paid_invoices_total' => $safe(fn () => $c->invoices()->where('status', 'paid')->count()),
            'credit_toman'        => $safe(fn () => $c->creditBalance('IRT')),
            'tickets_open'        => $safe(fn () => $c->tickets()->where('status', 'open')->count()),
            'tickets_last_30d'    => $safe(fn () => $c->tickets()
                ->where('created_at', '>=', now()->subDays(30))->count()),
            'identity_verified'   => $safe(fn () => $c->isVerified(), false),
        ];
    }
}
