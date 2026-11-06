<?php

namespace App\Services\Notify;

use App\Models\Service;
use App\Models\Domain;
use App\Models\Setting;
use App\Support\AdminAlerts;
use Illuminate\Support\Facades\Cache;

/** Owner's Bale inbox policy only. Customer delivery, email and service actions are separate. */
class BaleManagerPolicy
{
    public const SETTING = 'bale_manager_policy_v2_enabled';

    private const DAILY = [
        'admin.customer_new', 'admin.payment_ok', 'paid', 'admin.cloud_delivered', 'service_ready',
        'domain_registered', 'domain_renewed', 'domain_expired', 'admin.cloud_cost_up',
    ];

    private const SILENT = [
        'admin.order_new', 'admin.order_licence', 'admin.order_builder', 'admin.order_cloud_hourly',
        'service_ordered', 'invoice', 'payment_due', 'admin.cloud_ordered', 'renewed', 'suspended',
        'data_deletion_due', 'terminated', 'admin.manual_lifecycle', 'admin.dns_zone_failed',
        'ticket_new', 'ticket_customer_reply',
    ];

    private const INCIDENTS = [
        'service_failed' => ['⚠️ تحویل سرویس نیازمند رسیدگی است', 'علت تحویل را برطرف کن؛ نتیجه همان سفارش را پیش از تلاش دوباره تطبیق بده.'],
        'service_hold' => ['🛑 سفارش برای بازبینی نگه داشته شد', 'دلیل نگه‌داشتن سفارش و مالکیت مشتری را بررسی کن؛ محافظ سفارش بدون تصمیم بررسی‌کننده برداشته نشود.'],
        'admin.cloud_stuck' => ['⚠️ تحویل پرداخت‌شده متوقف است', 'وضعیت واقعی سفارش را نزد زیرساخت بررسی و همان پرونده را تکمیل کن.'],
        'admin.extra_ip_partial' => ['⚠️ IP اضافه کامل تحویل نشده', 'تعداد IP خریداری‌شده و تحویل‌شده را تطبیق بده؛ خرید دوباره پیش از تعیین نتیجه انجام نشود.'],
        'admin.cloud_quarantine' => ['🛑 فروش یک زیرساخت متوقف شد', 'علت توقف، دسترسی و موجودی حساب زیرساخت را بررسی کن؛ پس از رفع علت، پلن‌های همان زیرساخت بازبینی شوند.'],
        'admin.cloud_lingering' => ['🔴 توقف یا حذف زیرساخت تأیید نشده', 'نتیجه توقف/حذف را نزد زیرساخت تطبیق بده و رسید را در همان سرویس ثبت کن؛ احتمال هزینه ادامه‌دار است.'],
        'admin.domain_register_failed' => ['🔴 دامنه پرداخت‌شده ثبت نشده', 'نتیجه ثبت و موجودی حساب رجیسترار را بررسی کن؛ پس از رفع علت، همان سفارش تکمیل و تحویل تأیید شود.'],
        'admin.domain_renew_failed' => ['🔴 تمدید پرداخت‌شده دامنه انجام نشده', 'نتیجه تمدید و موجودی حساب رجیسترار را بررسی کن؛ پیش از تکرار، رسید سفارش قبلی تطبیق داده شود.'],
        'admin.domain_recover_failed' => ['🔴 بازیابی پرداخت‌شده دامنه انجام نشده', 'مهلت بازیابی، نتیجه سفارش و موجودی رجیسترار را بررسی و همان پرونده را تعیین تکلیف کن.'],
        'admin.domain_transfer_failed' => ['⚠️ انتقال دامنه نیازمند رسیدگی است', 'وضعیت انتقال و نتیجه بازگشت وجه را در همین پرونده تطبیق بده.'],
    ];

    public function __construct(private ?\Closure $contextReader = null) {}

    public static function enabled(): bool
    {
        try { return Setting::get(self::SETTING) === '1'; }
        catch (\Throwable) { return false; }
    }

    /** Stable caller identity is independent of editable presentation text. */
    public static function eventKey(?string $key, string $title): string
    {
        if ($key !== null && $key !== '') { return str_starts_with($key, 'event:') ? substr($key, 6) : $key; }
        if (str_starts_with($title, 'یادآوری تقویم')) { return 'calendar_daily'; }
        if (str_starts_with($title, 'AI Growth: ')) { return 'growth_daily'; }
        if ($title === __('identity.menu')) { return 'identity_daily'; }
        if (($known = AdminAlerts::keyFor($title)) !== null) { return $known; }
        foreach (NotifyEvent::ALL as $event => $definition) {
            if ($definition['title'] === $title) { return $event; }
        }
        return match ($title) {
            'سرویس به‌خاطرِ عدمِ تمدید غیرفعال شد' => 'suspended',
            'دامنه منقضی شد' => 'domain_expired',
            'وب‌سرویس احراز هویت' => 'identity_daily',
            '🔴 خودکارسازی مشکل دارد' => 'health_fail',
            '⚠️ هشدارِ سلامتِ سامانه' => 'health_warn',
            'سلامتِ سامانه به حالتِ عادی برگشت' => 'health_recovered',
            default => '',
        };
    }

    /** Pure classification apart from optional exact-service context; no actions or sends. */
    public function decide(?string $key, string $title, array $rows, ?string $url = null): array
    {
        $event = self::eventKey($key, $title);
        if ($event === '' && parse_url($url ?? '', PHP_URL_PATH) === '/admin/identity-api') { $event = 'identity_daily'; }
        $result = ['send' => true, 'mode' => 'immediate', 'event_key' => $event, 'text' => null, 'dedup_key' => null, 'dedup_group' => null];
        if (in_array($event, self::DAILY, true) || in_array($event, ['identity_daily', 'calendar_daily', 'growth_daily'], true)) {
            return array_replace($result, ['send' => false, 'mode' => 'daily']);
        }
        if (in_array($event, self::SILENT, true)) {
            return array_replace($result, ['send' => false, 'mode' => 'silent']);
        }
        if (in_array($event, ['health_fail', 'health_warn', 'health_recovered'], true)) {
            $lines = [$event === 'health_recovered' ? '✅ سلامت سامانه به حالت عادی برگشت'
                : ($event === 'health_fail' ? '🔴 خودکارسازی نیازمند رسیدگی است' : '⚠️ سلامت سامانه نیازمند بررسی است')];
            if ($event !== 'health_recovered') {
                foreach (array_slice($rows, 0, 3, true) as $label => $value) {
                    $lines[] = self::clean($label, 70).': '.self::clean($value, 180);
                }
                if (count($rows) > 3) { $lines[] = 'و '.fa_num(count($rows) - 3).' مورد دیگر در پرونده خطاها'; }
                $lines[] = 'اقدام: علت چک‌های ناموفق و آخرین اجرای واقعی را در پرونده خطاها بررسی کن.';
            }
            if ($url !== null && $url !== '') { $lines[] = $url; }
            return array_replace($result, ['text' => implode("\n", $lines)]);
        }
        if ($event === 'admin.hourly_underwater') {
            $lines = ['💸 خطر حاشیه فروش طبق نرخ فعلی زیرساخت'];
            foreach ($rows as $label => $value) {
                if (preg_match('/^#[0-9۰-۹]+$/u', (string) $label) && count($lines) < 4) {
                    $lines[] = $label.': '.self::clean($value, 180);
                }
            }
            if (isset($rows['و'])) { $lines[] = self::clean($rows['و'], 100); }
            $lines[] = 'اقدام: برآورد کاتالوگ و نرخ همان سرویس‌ها را با فاکتور تأمین‌کننده تطبیق بده و طرح اصلاح قیمت را بررسی کن.';
            return array_replace($result, ['text' => implode("\n", $lines)]);
        }
        if (! isset(self::INCIDENTS[$event])) { return $result; }
        [$headline, $action] = self::INCIDENTS[$event];
        $service = self::first($rows, ['سرویس', 'service']);
        $serviceId = self::serviceId($service);
        $context = $serviceId === null ? [] : $this->serviceContext($serviceId);
        $customer = self::first($rows, ['مشتری', 'customer']) ?: ($context['customer'] ?? '');
        $provider = self::first($rows, ['زیرساخت', 'provider', 'رجیسترار', 'registrar', 'سرور']) ?: ($context['provider'] ?? '');
        $cause = self::first($rows, ['خطا', 'علت', 'reason']);
        $domain = self::first($rows, ['دامنه', 'domain']);
        if ($provider === '' && $domain !== '') { $provider = $this->domainProvider($domain); }
        $lines = [$headline];
        if ($customer !== '') { $lines[] = 'مشتری: '.self::clean($customer, 110); }
        if ($service !== '') { $lines[] = 'سرویس: '.self::clean($service, 110); }
        if ($domain !== '') { $lines[] = 'دامنه: '.self::clean($domain, 110); }
        $lines[] = 'زیرساخت: '.($provider !== '' ? self::clean($provider, 110) : 'در پیام مبدأ ثبت نشده؛ پرونده بررسی شود');
        if ($cause !== '') { $lines[] = 'علت: '.self::clean($cause, 220); }
        foreach (['خریداری‌شده', 'تحویل‌شده', 'تعداد پلن', 'بازگشتِ وجه'] as $label) {
            if (isset($rows[$label])) { $lines[] = $label.': '.self::clean($rows[$label], 80); }
        }
        // Aggregate delivery incident rows carry their own customer and service identity.
        foreach ($rows as $label => $value) {
            if (preg_match('/^#[0-9۰-۹]+$/u', (string) $label)) {
                $lines[] = $label.': '.self::clean($value, 300);
                if (count($lines) > 7) { break; }
            }
        }
        if (isset($rows['و'])) { $lines[] = self::clean($rows['و'], 100); }
        $lines[] = 'اقدام: '.$action;
        $identity = $serviceId !== null ? 'service:'.$serviceId
            : (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/iD', $domain) && $customer !== ''
                ? 'domain:'.strtolower($domain).'|customer:'.$customer : null);
        // Coalesce only the exact, identified incident; do not hash arbitrary whole messages.
        $dedup = $identity !== null && $cause !== '' ? 'bale-manager:attempt:'.hash('sha256',
            $event.'|'.$identity.'|'.$provider.'|'.$cause) : null;
        $fundingGroup = $identity !== null && str_starts_with($event, 'admin.domain_') && self::isRegistrarFundingFailure($cause)
            ? self::fundingGroup($domain, $customer, $provider) : null;
        return array_replace($result, ['text' => implode("\n", $lines), 'dedup_key' => $dedup, 'dedup_group' => $fundingGroup]);
    }

    /** This is short attempt coalescing, not a delivery receipt. Cache faults fail open. */
    public function claim(array $decision): bool
    {
        if (! $decision['send']) { return false; }
        if (empty($decision['dedup_key'])) { return true; }
        if (! empty($decision['dedup_group'])) {
            try {
                // Last attempted cause only: a changed cause remains immediate.
                // This is best-effort coalescing, never evidence of delivery.
                if (Cache::get($decision['dedup_group']) === $decision['dedup_key']) { return false; }
                Cache::put($decision['dedup_group'], $decision['dedup_key'], now()->addHours(6));
                return true;
            } catch (\Throwable) { return true; }
        }
        try { return Cache::add($decision['dedup_key'], true, now()->addSeconds(90)); }
        catch (\Throwable) { return true; }
    }

    public static function isRegistrarFundingFailure(string $message): bool
    {
        // No numeric codes are guessed: this is notification classification only,
        // and never changes provider retry, payment or refund decisions.
        return (bool) preg_match('/\b(?:insufficient\s+(?:account\s+)?(?:balance|credit|funds)|not\s+enough\s+(?:balance|credit|funds)|(?:account\s+)?balance\s+(?:is\s+)?too\s+low|credit\s+limit\s+exceeded)\b/i', $message);
    }

    public static function clearDomainFundingAttempt(string $domain, string $customer, string $provider = 'Openprovider'): void
    {
        try { Cache::forget(self::fundingGroup($domain, $customer, $provider)); }
        catch (\Throwable) { /* Reporting state must never affect a verified delivery. */ }
    }

    private static function fundingGroup(string $domain, string $customer, string $provider): string
    {
        return 'bale-manager:funding-attempt:'.hash('sha256', strtolower($domain).'|'.$customer.'|'.$provider);
    }

    protected function serviceContext(int $id): array
    {
        try {
            if ($this->contextReader !== null) { return (array) ($this->contextReader)($id); }
            $service = Service::query()->find($id, ['id', 'customer_id', 'server_id', 'cloud_plan_id', 'name']);
            if (! $service) { return []; }
            $provider = $service->cloudInstance?->provider ?: ($service->cloudPlan?->provider ?? '');
            return ['customer' => $service->customer?->displayName().' ('.($service->customer?->code ?? '').')',
                'provider' => $provider !== '' ? (\App\Services\Cloud\CloudManager::REAL_NAMES[$provider] ?? $provider) : ($service->server?->name ?? '')];
        } catch (\Throwable) { return []; }
    }

    private function domainProvider(string $domain): string
    {
        if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/iD', $domain)) { return ''; }
        try {
            $matches = Domain::query()->where('domain', strtolower($domain))->limit(2)->get(['registrar']);
            return $matches->count() === 1 ? (string) $matches->first()->registrar : '';
        } catch (\Throwable) { return ''; }
    }

    private static function serviceId(string $value): ?int
    {
        $latin = strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), range(0, 9)));
        return preg_match('/#([1-9][0-9]*)\b/', $latin, $m) ? (int) $m[1] : null;
    }

    private static function first(array $rows, array $labels): string
    {
        foreach ($labels as $label) {
            if (isset($rows[$label]) && (is_scalar($rows[$label])) && trim((string) $rows[$label]) !== '') {
                return trim((string) $rows[$label]);
            }
        }
        return '';
    }

    private static function clean(mixed $value, int $length): string
    {
        if (! is_scalar($value)) { return ''; }
        $text = preg_replace('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s', '[حذف اطلاعات محرمانه]', (string) $value);
        $text = preg_replace('/\b(?:Bearer|Basic)\s+\S+|\bsk-[a-zA-Z0-9_-]{8,}\b/iu', '[حذف کلید]', $text);
        $text = preg_replace('/((?:password|passwd|secret|token|api[_ -]?key|رمز|توکن)[\'"]?\s*[=:]\s*)[\'"]?[^\'"\s,;}]+/iu', '$1[حذف]', $text);
        $text = preg_replace('/[\r\n\t]+/u', ' ', strip_tags($text));
        return mb_strimwidth(trim($text), 0, $length, '…', 'UTF-8');
    }
}
