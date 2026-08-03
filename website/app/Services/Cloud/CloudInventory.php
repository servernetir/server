<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;

/**
 * تطبیقِ «سرورهایی که نزدِ زیرساخت داریم» با «سرورهایی که در سامانه ثبت‌اند».
 *
 * دو سؤال را جواب می‌دهد که هر دو مستقیماً پول‌اند:
 *
 * ۱. **کدام سرورِ زیرساخت به هیچ مشتری‌ای وصل نیست؟** («یتیم»)
 *    اجارهٔ آن را ما می‌دهیم و هیچ‌کس بابتش پول نمی‌دهد. سروری که دستی برای
 *    مشتری ساخته شده ولی در سامانه ثبت نشده، سرورِ آزمایشیِ فراموش‌شده، یا
 *    سرورِ مشتریِ رفته که خاتمه‌اش نزدِ زیرساخت انجام نشده — همه این‌جا می‌افتند.
 *
 * ۲. **کدام سرویسِ سامانه سروری ندارد که ادعایش را می‌کند؟** («شبح»)
 *    ردیفِ `cloud_instances` هست ولی زیرساخت چنین سروری نمی‌شناسد. یعنی مشتری
 *    در پنلش دکمه‌هایی می‌بیند که همه خطا می‌دهند و ما هر ماه بابتِ چیزی که
 *    وجود ندارد فاکتور می‌فرستیم.
 *
 * ⚠️ **قاعدهٔ ایمنی:** اگر پرسیدن از یک زیرساخت شکست بخورد، سرورهای آن زیرساخت
 * **هرگز** یتیم شمرده نمی‌شوند. توکنِ منقضی، فهرستِ خالی برمی‌گرداند و فهرستِ
 * خالی یعنی «همهٔ سرویس‌های ما شبح‌اند» — گزارشی که مدیر را وامی‌دارد چیزی را
 * پاک کند که سالم است. خطا صریح جدا گزارش می‌شود.
 */
class CloudInventory
{
    public function __construct(private CloudManager $manager) {}

    /**
     * @param  array<int,string>|null  $providers  خالی = همهٔ زیرساخت‌های تنظیم‌شده
     * @return array{
     *   orphans: array<int,array>,
     *   ghosts: array<int,array>,
     *   attached: array<int,array>,
     *   errors: array<string,string>,
     *   checked: array<int,string>
     * }
     */
    public function reconcile(?array $providers = null): array
    {
        $known = CloudInstance::query()
            ->with(['service:id,name,customer_id,status', 'service.customer:id,code,email'])
            ->get(['id', 'service_id', 'provider', 'provider_ref', 'hostname', 'ipv4', 'status']);

        // نگاشتِ provider|ref → نمونه. کلیدِ ترکیبی لازم است چون شناسهٔ عددیِ
        // «۹۹۹» می‌تواند هم‌زمان نزدِ دو زیرساخت وجود داشته باشد.
        $byRef = [];
        $byHost = [];

        foreach ($known as $ci) {
            $byRef[$ci->provider.'|'.$ci->provider_ref] = $ci;
            $byHost[$ci->provider.'|'.strtolower((string) $ci->hostname)] = $ci;
        }

        $orphans = [];
        $attached = [];
        $errors = [];
        $checked = [];
        $seenRefs = [];

        foreach ($this->targets($providers) as $slug) {
            $driver = $this->manager->driver($slug);

            if ($driver === null) {
                continue;
            }

            $res = $driver->listServers();
            $checked[] = $slug;

            if (! ($res['ok'] ?? false)) {
                $errors[$slug] = (string) ($res['message'] ?? 'پاسخی نیامد');

                continue;
            }

            if (filled($res['message'] ?? null)) {
                // ok=true ولی ناقص (مثلِ آروان که یک منطقه‌اش نخوانده) — باید
                // دیده شود، وگرنه نبودِ یک سرور در فهرست، «حذف‌شده» تعبیر می‌شود.
                $errors[$slug] = (string) $res['message'];
            }

            foreach ((array) ($res['servers'] ?? []) as $srv) {
                $ref = (string) ($srv['ref'] ?? '');

                if ($ref === '') {
                    continue;
                }

                $key = $slug.'|'.$ref;
                $seenRefs[$key] = true;
                $ci = $byRef[$key] ?? null;

                // 🔴 پشتیبان: نامِ سرور. تحویل نامِ **قطعیِ** `sn-svc-{id}` را
                // می‌گذارد، و همان تنها راهِ شناختنِ سروری است که شناسه‌اش هنوز
                // نهایی نشده. زیرساختِ ۲ دومرحله‌ای است و اگر شناسهٔ واقعی در
                // پنجرهٔ کوتاهِ نظرسنجی برنگردد، `provider_ref` به‌صورتِ
                // `order:۸۸۱۲۳` ذخیره می‌شود. بی‌این تطبیق، همان یک ماشین
                // هم‌زمان «یتیم» (یعنی: حذفش کن) و «شبح» (یعنی: سرویسش را ببند)
                // گزارش می‌شد — دو توصیهٔ ویرانگر دربارهٔ سرورِ زندهٔ یک مشتری.
                if ($ci === null) {
                    $ci = $byHost[$slug.'|'.strtolower((string) ($srv['name'] ?? ''))] ?? null;

                    if ($ci !== null) {
                        $seenRefs[$slug.'|'.$ci->provider_ref] = true;
                    }
                }

                $row = $srv + [
                    'provider'       => $slug,
                    'provider_label' => $this->manager->label($slug),
                ];

                if ($ci === null) {
                    $orphans[] = $row;

                    continue;
                }

                $attached[] = $row + [
                    'service_id'    => $ci->service_id,
                    'service_name'  => $ci->service?->name,
                    'customer_code' => $ci->service?->customer?->code,
                    // مغایرتِ IP یعنی سرور را جای دیگری عوض کرده‌اند و پنلِ
                    // مشتری IPِ مرده نشان می‌دهد.
                    'ip_mismatch'   => filled($srv['ipv4'] ?? null)
                        && filled($ci->ipv4) && $srv['ipv4'] !== $ci->ipv4,
                ];
            }
        }

        // شبح‌ها: فقط از زیرساخت‌هایی که واقعاً و بی‌خطا پرسیده شدند
        $trusted = array_values(array_diff($checked, array_keys($errors)));
        $ghosts = [];

        foreach ($known as $ci) {
            if (! in_array($ci->provider, $trusted, true)) {
                continue;
            }

            if (isset($seenRefs[$ci->provider.'|'.$ci->provider_ref])) {
                continue;
            }

            // سرویسِ بسته‌شده «شبح» نیست — نبودنِ سرورش دقیقاً همان چیزی است که
            // انتظار داریم. بی‌این شرط، هر خاتمهٔ موفق برای همیشه یک ردیفِ
            // هشدارِ دروغین در گزارش می‌گذاشت و گزارش را بی‌اعتبار می‌کرد.
            if ($ci->service === null || $ci->service->isDead()) {
                continue;
            }

            // شناسهٔ نهایی‌نشده (`order:…`) یا خالی: هنوز نمی‌دانیم سرورش کدام
            // است، پس «نبود» نتیجه‌گیریِ درستی نیست. ردیفِ نمونه پیش از تماسِ API
            // ساخته می‌شود، پس این حالت در چند دقیقهٔ اولِ هر تحویل طبیعی است.
            if (blank($ci->provider_ref) || str_starts_with((string) $ci->provider_ref, 'order:')) {
                continue;
            }

            $ghosts[] = [
                'provider'       => $ci->provider,
                'provider_label' => $this->manager->label($ci->provider),
                'ref'            => $ci->provider_ref,
                'service_id'     => $ci->service_id,
                'service_name'   => $ci->service?->name,
                'service_status' => $ci->service?->status,
                'customer_code'  => $ci->service?->customer?->code,
                'ipv4'           => $ci->ipv4,
            ];
        }

        return compact('orphans', 'ghosts', 'attached', 'errors', 'checked');
    }

    /**
     * فقط زیرساخت‌های **تنظیم‌شده**. زیرساختِ بی‌توکن اصلاً پرسیده نمی‌شود و در
     * `checked` هم نمی‌آید، پس سرویس‌هایش هرگز شبح شمرده نمی‌شوند.
     *
     * @return array<int,string>
     */
    private function targets(?array $providers): array
    {
        $all = array_keys($this->manager->configured());

        if (blank($providers)) {
            return $all;
        }

        return array_values(array_intersect($all, $providers));
    }
}
