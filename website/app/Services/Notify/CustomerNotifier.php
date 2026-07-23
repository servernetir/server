<?php

namespace App\Services\Notify;

use App\Models\Customer;
use App\Services\Bale\BaleNotifier;
use App\Services\Sms\SmsDispatcher;

/**
 * تنها نقطه‌ای که برای «اعلان به مشتری» صدا زده می‌شود.
 *
 * قاعدهٔ کارفرما: هر چیزی که پیامک می‌شود، بله هم می‌شود. با یک هاب مرکزی
 * این قاعده در یک جا تضمین می‌شود، نه اینکه هر رویداد یادش باشد هر دو کانال
 * را بزند و یکی جا بیفتد.
 *
 *   پیامک → از طریق SmsDispatcher (صف، مسیر الگو، تحویل از ایران)
 *   بله   → از طریق BaleNotifier (آلمان اول، اگر نشد ایران)
 *
 * هر دو best-effort و مستقل‌اند: اگر یکی نشد، دیگری می‌رود. هیچ‌کدام جریان
 * اصلی را نمی‌شکند.
 */
class CustomerNotifier
{
    public function __construct(
        private SmsDispatcher $sms,
        private BaleNotifier $bale,
    ) {}

    /**
     * اعلان رویدادی به مشتری، هر دو کانال.
     *
     * @param  string  $event      نام رویداد برای الگوی پیامک (invoice، paid، ticket_reply…)
     * @param  array   $smsParams  متغیرهای الگوی پیامک
     * @param  string  $text       متن کامل — پشتیبان پیامک و متن بله
     */
    public function event(Customer $customer, string $event, array $smsParams, string $text): void
    {
        $mobile = (string) $customer->phone;

        if ($mobile === '') {
            return;
        }

        // پیامک — الگو اگر باشد، وگرنه متن آزاد
        try {
            $this->sms->event($mobile, $event, $smsParams, $text);
        } catch (\Throwable) {
            // اعلان هرگز نباید جریان اصلی را بشکند
        }

        // بله — همان متن، اگر کاربر وصل کرده باشد
        $this->bale->notify($mobile, $text);
    }

    /** پیام آزاد بدون الگو، هر دو کانال */
    public function message(Customer $customer, string $text): void
    {
        $this->event($customer, '__none__', [], $text);
    }
}
