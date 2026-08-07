<?php

namespace App\Services\Notify;

use App\Models\Customer;

/**
 * قیفِ واحدِ اطلاع‌رسانی — هر رویداد، هر گیرنده، هر کانال، از یک نقطه.
 *
 * ═══ مسئله‌ای که حل می‌کند ═══
 *
 * پیش از این، هر نقطهٔ کد خودش تصمیم می‌گرفت: بعضی فقط به مشتری خبر می‌دادند،
 * بعضی فقط به مدیر، بعضی هیچ‌کدام. کارفرما خواست **همهٔ** رویدادها به هر دو
 * برسد؛ ولی افزودنِ دو فراخوان به هر نقطه، همان الگویی است که تا حالا `welcome`
 * و `invoice` را بی‌صدا جا انداخت.
 *
 * این کلاس تصمیم را از نقطهٔ فراخوان می‌گیرد و به **کاتالوگ** می‌سپارد:
 * فراخوان فقط می‌گوید «این رویداد رخ داد»؛ اینکه به مشتری برود یا مدیر یا هر
 * دو، در `NotifyEvent::ALL` نوشته شده و با تست قفل است.
 *
 * ⚠️ هیچ‌وقت استثنا پرتاب نمی‌کند. اعلان نباید خریدِ مشتری یا کرونِ تمدید را
 * بشکند — همان قاعده‌ای که در تک‌تکِ `catch`های این پروژه نوشته شده. ولی
 * شکستِ **خاموش** هم نداریم: هر خطا در ردیابِ خطا می‌نشیند تا در
 * `/admin/errors` دیده شود.
 */
class Notifier
{
    public function __construct(
        private CustomerNotifier $customers,
        private AdminNotifier $admin,
    ) {}

    /**
     * شلیکِ یک رویداد.
     *
     * @param  string  $key      کلید از `NotifyEvent::ALL`
     * @param  array<string,string|int|null>  $vars  متغیرهای الگو
     * @param  string  $text     متنِ پشتیبان (پیامک/بله وقتی الگو نباشد)
     * @param  array<string,string|int|null>  $adminRows  سطرهای اضافه برای مدیر
     */
    public function fire(
        string $key,
        ?Customer $customer,
        array $vars,
        string $text,
        array $adminRows = [],
        ?string $url = null,
        string $emoji = '🔔',
    ): void {
        /*
        | 🔴 کلیدِ ناشناخته **بی‌صدا رد نمی‌شود**.
        |
        | اگر کسی کلیدی بفرستد که در کاتالوگ نیست، پیام به هیچ‌جا نمی‌رسد و
        | هیچ‌کس نمی‌فهمد. این‌جا صریح ثبت می‌شود تا در ردیاب دیده شود — چون
        | تنها نشانهٔ دیگرش، شکایتِ ماه‌ها بعدِ یک مشتری است.
        */
        if (! NotifyEvent::has($key)) {
            \App\Support\ErrorTracker::note('notify', 'رویدادِ ناشناخته: '.$key);

            return;
        }

        if ($customer !== null && NotifyEvent::notifiesCustomer($key)) {
            try {
                $this->customers->templated($customer, $key, $vars, $text);
            } catch (\Throwable $e) {
                \App\Support\ErrorTracker::note('notify', $e, ['event' => $key, 'to' => 'customer']);
            }
        }

        if (NotifyEvent::notifiesAdmin($key)) {
            try {
                $this->admin->event(
                    NotifyEvent::get($key)['title'] ?? $key,
                    $this->adminRows($key, $customer, $vars, $adminRows),
                    $url,
                    $emoji,
                );
            } catch (\Throwable $e) {
                \App\Support\ErrorTracker::note('notify', $e, ['event' => $key, 'to' => 'admin']);
            }
        }
    }

    /**
     * سطرهای اعلانِ مدیر.
     *
     * ⚠️ همیشه «چه کسی» را می‌آورد. اعلانی که می‌گوید «سرویس تحویل شد» ولی
     * نمی‌گوید مالِ چه کسی، مدیر را مجبور می‌کند پنل را باز کند — و اعلانی که
     * باید بازش کنی، عملاً خوانده نمی‌شود.
     *
     * @param  array<string,string|int|null>  $vars
     * @param  array<string,string|int|null>  $extra
     * @return array<string,string|int|null>
     */
    private function adminRows(string $key, ?Customer $customer, array $vars, array $extra): array
    {
        $rows = [];

        if ($customer !== null) {
            $rows['مشتری'] = trim(($customer->displayName() ?? '—').' ('.($customer->code ?? '—').')');
            $rows['تلفن'] = $customer->phone;
        }

        // متغیرهای خودِ رویداد، با برچسبِ خوانا
        foreach ($vars as $k => $v) {
            $rows[self::LABELS[$k] ?? $k] = $v;
        }

        return array_merge($rows, $extra);
    }

    /** برچسبِ فارسیِ متغیرهای پرتکرار — برای خوانا شدنِ اعلانِ مدیر */
    private const LABELS = [
        'service' => 'سرویس',
        'domain'  => 'دامنه',
        'amount'  => 'مبلغ',
        'number'  => 'شماره',
        'days'    => 'روز',
        'until'   => 'تا تاریخ',
        'ip'      => 'آی‌پی',
        'reason'  => 'علت',
        'subject' => 'موضوع',
        'status'  => 'وضعیت',
        'name'    => 'نام',
        'code'    => 'کد',
        'link'    => 'لینک',
    ];
}
