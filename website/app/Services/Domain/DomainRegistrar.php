<?php

namespace App\Services\Domain;

use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\RegistryHandle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ثبتِ واقعیِ دامنه نزدِ رجیسترار.
 *
 * این کلاس روی مسیرِ پول است و سه خاصیت را تضمین می‌کند:
 *
 *  ۱) **بدونِ پرداخت هیچ ثبتی نمی‌شود.** این کلاس هرگز خودش تصمیم نمی‌گیرد؛
 *     فقط دامنه‌ای را ثبت می‌کند که قبلاً `provision_status='pending'` شده،
 *     و آن را تنها `PaymentService` بعد از پرداختِ موفق می‌گذارد.
 *
 *  ۲) **یک دامنه دو بار خریده نمی‌شود.** قفلِ وضعیتیِ اتمی + استعلامِ
 *     «قبلاً ثبت شده؟» پیش از هر تلاشِ ثبت.
 *
 *  ۳) **شکست، پول را بی‌صدا نمی‌بلعد.** خطا در `provision_error` می‌نشیند و
 *     دامنه به صفِ دستیِ مدیر می‌رود، نه اینکه در سکوت گم شود.
 */
class DomainRegistrar
{
    /** بعد از این تعداد تلاشِ ناموفق، تصمیم با آدم است نه کرون */
    private const MAX_TRIES = 3;

    public function __construct(private OpenProviderClient $op) {}

    // ═══════════════════════ handle مالک ═══════════════════════

    /**
     * handle مالک برای این پروفایل — موجود را برمی‌دارد، نبود می‌سازد.
     *
     * 🔴 چرا بازاستفاده حیاتی است: WHOIS باید مالکِ واقعی را نشان دهد. اگر برای
     * هر ثبت handle تازه بسازیم، وقتی مشتری نشانی‌اش را عوض کند فقط دامنه‌های
     * بعدی درست می‌شوند و قدیمی‌ها با دادهٔ کهنه می‌مانند — تخلفِ قواعدِ ICANN
     * و در بدترین حالت تعلیقِ دامنه.
     *
     * @return array{ok:bool, handle:?string, message:string}
     */
    public function handleFor(CustomerProfile $profile): array
    {
        $existing = RegistryHandle::where('customer_profile_id', $profile->id)
            ->where('registry', 'openprovider')
            ->where('role', 'registrant')
            ->first();

        if ($existing && filled($existing->handle)) {
            return ['ok' => true, 'handle' => $existing->handle, 'message' => ''];
        }

        $payload = $this->profileToCustomer($profile);

        if ($payload === null) {
            return ['ok' => false, 'handle' => null,
                'message' => 'مشخصاتِ مالک ناقص است (نام، نشانی، شهر، کدپستی، تلفن و ایمیل لازم است).'];
        }

        $res = $this->op->createCustomer($payload);

        if (! $res['ok'] || blank($res['handle'])) {
            return ['ok' => false, 'handle' => null,
                'message' => $res['message'] ?: 'ساختِ شناسهٔ مالک نزدِ رجیسترار ناموفق بود.'];
        }

        RegistryHandle::create([
            'customer_profile_id' => $profile->id,
            'registry'            => 'openprovider',
            'handle'              => $res['handle'],
            'role'                => 'registrant',
            'status'              => 'active',
            'sent_data'           => $payload,
        ]);

        return ['ok' => true, 'handle' => $res['handle'], 'message' => ''];
    }

    /**
     * پروفایلِ ما → بدنهٔ رسمیِ مشتریِ اوپن‌پروایدر.
     *
     * `null` یعنی دادهٔ اجباری کم است. عمداً این‌جا رد می‌شود نه در رجیسترار:
     * خطای «field X is required» رجیسترار به فارسی ترجمه نمی‌شود و مشتری
     * نمی‌فهمد چه چیزی را باید پر کند.
     *
     * @return array<string,mixed>|null
     */
    public function profileToCustomer(CustomerProfile $profile): ?array
    {
        $first = trim((string) $profile->first_name);
        $last  = trim((string) $profile->last_name);
        $email = trim((string) $profile->email);
        $addr  = trim((string) $profile->address);
        $city  = trim((string) $profile->city);
        $zip   = trim((string) $profile->postal_code);

        $phone = $this->splitPhone((string) $profile->mobile, (string) $profile->country);

        if ($first === '' || $last === '' || $email === '' || $addr === '' || $city === '' || $phone === null) {
            return null;
        }

        $data = [
            'name' => [
                'first_name' => $first,
                'last_name'  => $last,
                'full_name'  => trim($first.' '.$last),
            ],
            'address' => [
                // ⚠️ اوپن‌پروایدر خیابان و پلاک را جدا می‌خواهد، ولی نشانیِ
                // ایرانی یک رشتهٔ آزاد است. کلِ نشانی در `street` می‌رود و
                // `number` یک مقدارِ حداقلی می‌گیرد — تجزیهٔ حدسیِ نشانیِ فارسی
                // بدتر از این است: پلاکِ اشتباه یعنی WHOIS غلط.
                'street'  => mb_substr($addr, 0, 250),
                'number'  => '-',
                'city'    => $city,
                'state'   => (string) ($profile->province ?? ''),
                'zipcode' => $zip !== '' ? $zip : '0000000000',
                'country' => strtoupper((string) ($profile->country ?: 'IR')),
            ],
            'email' => $email,
            'phone' => $phone,
        ];

        if ($profile->type === 'company' && filled($profile->company_name)) {
            $data['company_name'] = (string) $profile->company_name;
        }

        return $data;
    }

    /**
     * «۰۹۱۲۳۴۵۶۷۸۹» → country_code/area_code/subscriber_number.
     *
     * ⚠️ رجیسترار این سه تکه را جدا می‌خواهد و شمارهٔ چسبیده را رد می‌کند.
     */
    private function splitPhone(string $raw, string $country): ?array
    {
        $digits = preg_replace('/\D+/', '', $this->toLatinDigits($raw)) ?? '';

        if ($digits === '') {
            return null;
        }

        $cc = strtoupper($country) === 'IR' ? '+98' : '+'.((string) config('services.openprovider.default_cc', '98'));

        // صفرِ ابتدایی داخلی است و با کدِ کشور نمی‌آید
        $national = ltrim($digits, '0');

        if (strlen($national) < 6) {
            return null;
        }

        return [
            'country_code'      => $cc,
            'area_code'         => substr($national, 0, 3),
            'subscriber_number' => substr($national, 3),
        ];
    }

    /** رقمِ فارسی/عربی → لاتین (کاربر شماره را با کیبورد فارسی می‌زند) */
    private function toLatinDigits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    // ═══════════════════════ ثبت ═══════════════════════

    /**
     * ثبتِ یک دامنهٔ پرداخت‌شده.
     *
     * @return array{ok:bool, message:string, manual:bool}
     */
    public function register(Domain $domain): array
    {
        // ── قفلِ اتمی: فقط یک اجرا می‌تواند این دامنه را بردارد ──
        //
        // 🔴 بدونِ این، کرونِ هر-دقیقه و کلیکِ دستیِ مدیر می‌توانند هم‌زمان
        // شروع کنند و دامنه **دو بار** خریداری شود. `provision:run` دقیقاً
        // همین درس را یک بار به این پروژه داده است.
        $claimed = DB::table('domains')
            ->where('id', $domain->id)
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                // قفلِ رهاشده: اجرایی که وسطِ کار مرد (پایانِ زمانِ PHP،
                // ری‌استارت). بی‌این، دامنه برای همیشه `running` می‌مانَد و
                // هیچ اجرایی برش نمی‌دارد — مشتری پول داده و دامنه‌ای ندارد.
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(Domain::STALE_LOCK_MINUTES))))
            ->update(['provision_status' => 'running', 'updated_at' => now()]);

        if ($claimed === 0) {
            return ['ok' => false, 'manual' => false, 'message' => 'در حالِ پردازش توسطِ اجرای دیگری است.'];
        }

        $domain->refresh();

        try {
            return $this->doRegister($domain);
        } catch (\Throwable $e) {
            Log::error('domain register crashed', ['domain' => $domain->domain, 'err' => $e->getMessage()]);

            return $this->fail($domain, 'خطای غیرمنتظره: '.$e->getMessage());
        }
    }

    /** @return array{ok:bool, message:string, manual:bool} */
    private function doRegister(Domain $domain): array
    {
        if (! $this->op->enabled()) {
            return $this->fail($domain, 'اتصالِ رجیسترار پیکربندی نشده است.', manual: true);
        }

        $profile = $domain->customer?->defaultProfile();

        if (! $profile) {
            return $this->fail($domain, 'مشتری پروفایلِ مالک ندارد — بدونِ آن ثبتِ دامنه ممکن نیست.', manual: true);
        }

        $handle = $this->handleFor($profile);

        if (! $handle['ok']) {
            return $this->fail($domain, $handle['message'], manual: true);
        }

        /*
        | 🔴 بدونِ نام‌سرور ثبت نکن.
        |
        | `Domain::defaultNameServers()` از تنظیمات یا config می‌خواند و اگر هیچ‌کدام
        | ست نشده باشد **آرایهٔ خالی** می‌دهد. رجیسترار ثبتِ بی‌نام‌سرور را
        | می‌پذیرد، ولی نتیجه‌اش دامنه‌ای است که به هیچ‌جا اشاره نمی‌کند: مشتری
        | پول داده، دامنه «فعال» است، و سایتش بالا نمی‌آید — و علتش هیچ‌جا
        | نوشته نشده. برگرداندنش هم دستی و زمان‌بر است.
        |
        | پس به صفِ آدم می‌رود، نه به ثبتِ ناقص.
        */
        $ns = $domain->effectiveNameServers();

        if (count($ns) < 2) {
            return $this->fail($domain,
                'نام‌سرورِ پیش‌فرضِ شرکت تنظیم نشده است (تنظیماتِ domain_nameservers). '
                .'ثبت انجام نشد تا دامنهٔ بی‌مقصد ساخته نشود.',
                manual: true);
        }

        // ── ۱) آیا از قبل ثبت شده؟ ──
        //
        // 🔴 این قدم شبیهِ کارِ اضافه است ولی نیست: اگر تلاشِ قبلی timeout
        // خورده باشد در حالی که رجیسترار واقعاً ثبت کرده، بدونِ این استعلام
        // دوباره می‌خریم. پول دو بار می‌رود و دامنهٔ دوم به هیچ‌کس نمی‌خورد.
        $found = $this->op->findDomain($domain->sld, $domain->tld);

        if ($found['ok'] && ($found['found'] ?? false)) {
            return $this->succeed($domain, $found['data'], $handle['handle']);
        }

        // ── ۲) ثبت ──
        $res = $this->op->registerDomain(
            name: $domain->sld,
            extension: $domain->tld,
            handle: $handle['handle'],
            nameServers: $ns,
            period: max(1, (int) $domain->period_years),
            // ⚠️ تمدیدِ خودکار نزدِ رجیسترار **خاموش**: تمدید را ما می‌فروشیم.
            // اگر رجیسترار خودش تمدید کند، برای دامنه‌ای که مشتری پولش را
            // نداده هم ما پول می‌دهیم و راهی برای پس‌گرفتنش نیست.
            autoRenew: false,
        );

        if (! $res['ok']) {
            // شاید هم‌زمان ثبت شده باشد — یک بار دیگر بپرس پیش از اعلامِ شکست
            $again = $this->op->findDomain($domain->sld, $domain->tld);

            if ($again['ok'] && ($again['found'] ?? false)) {
                return $this->succeed($domain, $again['data'], $handle['handle']);
            }

            $tries = (int) $domain->provision_tries + 1;
            $manual = $tries >= self::MAX_TRIES;

            return $this->fail($domain, $res['message'] ?: 'ثبت ناموفق بود.', manual: $manual, tries: $tries);
        }

        $detail = $res['id'] ? ($this->op->getDomain((int) $res['id'])['data'] ?? []) : [];

        return $this->succeed($domain, $detail ?: ['id' => $res['id']], $handle['handle']);
    }

    /** @param array<string,mixed> $remote */
    private function succeed(Domain $domain, array $remote, string $handle): array
    {
        $expires = data_get($remote, 'expiration_date') ?: data_get($remote, 'expiration_date_time');

        $domain->forceFill([
            'status'           => 'active',
            'provision_status' => 'done',
            'provision_error'  => null,
            'op_id'            => data_get($remote, 'id') ?: $domain->op_id,
            'owner_handle'     => $handle,
            'registered_at'    => $domain->registered_at ?: now(),
            'expires_at'       => $this->parseDate($expires) ?: $domain->expires_at
                ?: now()->addYears(max(1, (int) $domain->period_years)),
        ])->save();

        $this->announce('domain_registered', $domain,
            'دامنهٔ «'.$domain->domain.'» با موفقیت ثبت شد و تا '
            .sdate($domain->fresh()?->expires_at).' اعتبار دارد.');

        return ['ok' => true, 'manual' => false, 'message' => ''];
    }

    /**
     * اعلانِ رویداد به مشتری و مدیر.
     *
     * ⚠️ در try/catch و بی‌استثنا: ثبتِ موفقِ دامنه نباید به‌خاطرِ یک SMTPِ
     * خواب «ناموفق» گزارش شود — همان قاعدهٔ بگیر-و-ادامه‌بده که در تک‌تکِ
     * catchهای این پروژه نوشته شده. ولی شکستِ خاموش نداریم: خطا در ردیاب
     * می‌نشیند.
     */
    private function announce(string $event, Domain $domain, string $text): void
    {
        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                $event,
                $domain->customer,
                [
                    'domain' => $domain->domain,
                    'until'  => sdate($domain->fresh()?->expires_at) ?: '—',
                ],
                $text,
                [],
                url('/admin/domains'),
                '🌐',
            );
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['event' => $event, 'domain' => $domain->domain]);
        }
    }

    private function fail(Domain $domain, string $message, bool $manual = false, ?int $tries = null): array
    {
        $domain->forceFill([
            // ⚠️ `manual` یعنی کرون دیگر برنمی‌داردش و منتظرِ آدم می‌ماند.
            // `pending` یعنی دوباره تلاش می‌شود.
            'provision_status' => $manual ? 'manual' : 'pending',
            'provision_tries'  => $tries ?? ((int) $domain->provision_tries + 1),
            'provision_error'  => mb_substr($message, 0, 500),
        ])->save();

        return ['ok' => false, 'manual' => $manual, 'message' => $message];
    }

    private function parseDate(mixed $v): ?\Illuminate\Support\Carbon
    {
        if (blank($v)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }

    // ═══════════════════════ تمدید ═══════════════════════

    /** @return array{ok:bool, message:string} */
    public function renew(Domain $domain, int $years = 1): array
    {
        if (! $domain->op_id) {
            return ['ok' => false, 'message' => 'این دامنه شناسهٔ رجیسترار ندارد.'];
        }

        $res = $this->op->renewDomain((int) $domain->op_id, $years);

        if (! $res['ok']) {
            return ['ok' => false, 'message' => $res['message'] ?: 'تمدید ناموفق بود.'];
        }

        // تاریخِ تازه را از خودِ رجیسترار می‌خوانیم، نه با جمعِ محلی —
        // چون دورهٔ واقعی ممکن است با آنچه خواستیم فرق کند.
        $detail = $this->op->getDomain((int) $domain->op_id);
        $expires = $this->parseDate(data_get($detail, 'data.expiration_date'));

        $domain->forceFill([
            'status'     => 'active',
            'expires_at' => $expires ?: ($domain->expires_at?->copy()->addYears($years) ?? now()->addYears($years)),
        ])->save();

        return ['ok' => true, 'message' => ''];
    }

    /**
     * تمدیدِ یک دامنهٔ **پرداخت‌شده** — با همان انضباطِ قفلِ ثبت.
     *
     * 🔴 چرا قفلِ اتمی این‌جا هم لازم است: کرونِ تمدید هر دقیقه می‌دود و مدیر
     * هم می‌تواند دستی بزند. بی‌قفل، دو اجرا هم‌زمان `renewDomain` صدا می‌زنند
     * و دامنه **دو سال** تمدید می‌شود — یعنی یک سالش را ما از جیبِ خودمان به
     * رجیسترار داده‌ایم و هیچ‌جا هم دیده نمی‌شود، چون هر دو تماس «موفق»اند.
     *
     * ⚠️ ادعایِ قفل روی `status='active'` بسته شده تا با `register()` تصادم
     * نکند: آن یکی فقط `status='pending'` را برمی‌دارد. دو مجموعه بی‌اشتراک.
     *
     * ⚠️ موفقیت `provision_status` را به `done` برمی‌گرداند، نه به چیزِ تازه —
     * وگرنه دامنه در صفِ تمدید می‌مانْد و اجرای بعدی دوباره تمدیدش می‌کرد.
     *
     * @return array{ok:bool, message:string, manual:bool}
     */
    public function renewPaid(Domain $domain): array
    {
        $claimed = DB::table('domains')
            ->where('id', $domain->id)
            ->where('status', 'active')
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(Domain::STALE_LOCK_MINUTES))))
            ->update(['provision_status' => 'running', 'updated_at' => now()]);

        if ($claimed === 0) {
            return ['ok' => false, 'manual' => false, 'message' => 'در حالِ پردازش توسطِ اجرای دیگری است.'];
        }

        $domain->refresh();

        if (! $this->op->enabled()) {
            return $this->failRenewal($domain, 'اتصالِ رجیسترار پیکربندی نشده است.', manual: true);
        }

        try {
            $res = $this->renew($domain, $domain->renewYears());
        } catch (\Throwable $e) {
            Log::error('domain renew crashed', ['domain' => $domain->domain, 'err' => $e->getMessage()]);

            return $this->failRenewal($domain, 'خطای غیرمنتظره: '.$e->getMessage());
        }

        if (! $res['ok']) {
            return $this->failRenewal($domain, $res['message']);
        }

        // 🔴 `exp_stage` صفر می‌شود وگرنه دورهٔ بعد هیچ یادآوری‌ای نمی‌رود:
        //    مرحلهٔ ۱ ثبت شده می‌مانْد و شرطِ «قبلاً رفته» همه را رد می‌کرد.
        $domain->putMeta(['exp_stage' => null, 'renewed_at' => now()->toDateTimeString()]);

        $domain->forceFill(['provision_status' => 'done', 'provision_error' => null])->save();

        $this->announce('domain_renewed', $domain,
            'دامنهٔ «'.$domain->domain.'» تمدید شد و تا '
            .sdate($domain->fresh()?->expires_at).' اعتبار دارد.');

        return ['ok' => true, 'manual' => false, 'message' => ''];
    }

    /**
     * شکستِ تمدید.
     *
     * ⚠️ `status` دست‌نخورده `active` می‌مانَد: دامنه هنوز مالِ مشتری است و تا
     * تاریخِ انقضا کار می‌کند. عوض‌کردنش یعنی دامنهٔ سالم از پنلِ مشتری غیب شود
     * چون یک تماسِ API شکست خورد.
     *
     * @return array{ok:bool, message:string, manual:bool}
     */
    private function failRenewal(Domain $domain, string $message, bool $manual = false): array
    {
        $tries = (int) $domain->provision_tries + 1;

        // پس از چند تلاش دستِ آدم — تمدیدِ بی‌پایانِ ناموفق یعنی هر دقیقه یک
        // تماسِ ناموفق به رجیستراری که حسابش قبلاً علامت خورده.
        $manual = $manual || $tries >= 5;

        $domain->forceFill([
            'provision_status' => $manual ? 'manual' : 'pending',
            'provision_tries'  => $tries,
            'provision_error'  => mb_substr('تمدید: '.$message, 0, 300),
        ])->save();

        \App\Support\ErrorTracker::note('provision', 'تمدیدِ دامنه ناموفق: '.$message, [
            'domain' => $domain->domain,
            'tries'  => $tries,
        ]);

        return ['ok' => false, 'manual' => $manual, 'message' => $message];
    }
}
