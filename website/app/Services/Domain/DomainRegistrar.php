<?php

namespace App\Services\Domain;

use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\RegistryHandle;
use App\Support\ErrorTracker;
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

    /**
     * 🔴 «قراردادِ رجیستری را امضا نکرده‌اید» — خطایی که تلاشِ دوباره **هرگز**
     * حلش نمی‌کند.
     *
     * ═══ رخداد (مرداد ۱۴۰۵) ═══
     *
     * مشتری وسطِ ثبتِ دامنه این را در پنلِ مدیریت دید:
     *   «You have not signed the last version of the contract for registering
     *    this domain»
     *
     * این پیام از رجیسترار می‌آید و معنایش دربارهٔ **حسابِ ما**ست، نه دربارهٔ
     * مشتری و نه دربارهٔ آن دامنه: OpenProvider برای هر پسوند یک قراردادِ
     * رجیستری دارد که فروشنده باید یک‌بار در پنلِ خودش امضا کند
     * (Account → Contracts). تا امضا نشود، **هیچ** دامنه‌ای با آن پسوند ثبت
     * نمی‌شود و هیچ فیلدی در API از رویش رد نمی‌کند — یک امضای حقوقی است، نه
     * یک پارامتر.
     *
     * ═══ چرا این ثابت لازم است ═══
     *
     * بی‌آن، این خطا مثلِ هر شکستِ گذرای دیگری رفتار می‌کرد: سه بار تلاشِ
     * دوباره، یعنی سه تماسِ واقعیِ دیگر با رجیستراری که حسابِ ما **قبلاً یک بار
     * به‌خاطرِ تماسِ زیاد از آی‌پیِ ایران علامت خورده** — و هر سه قطعاً همان
     * جواب را می‌دهند. بعد هم دامنه به صفِ دستی می‌رفت با یک پیامِ خامِ انگلیسی
     * که به مدیر نمی‌گفت باید چه کار کند.
     *
     * ⚠️ **تشخیص با کدِ عددی است، نه با متنِ انگلیسی.** رجیسترار متن را هر وقت
     * بخواهد عوض می‌کند («last version» / «latest») و تطبیقِ رشته‌ای همان روز
     * بی‌صدا می‌شکند.
     *
     * ۳۰۹   = "You have not signed the latest contract"
     * ۱۷۰۰۱ = "You must sign a contract"
     */
    public const CONTRACT_CODES = [309, 17001];

    public static function isUnsignedContract(int $code): bool
    {
        return in_array($code, self::CONTRACT_CODES, true);
    }

    /** نشانیِ صفحهٔ امضای قراردادها در پنلِ رجیسترار */
    public const CONTRACTS_URL = 'https://cp.openprovider.eu/documentation/contracts.php';

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
    /**
     * آیا مالکِ ثابتِ شرکت پیکربندی شده است؟
     *
     * فیلدهایی که رجیسترار **اجباری** می‌داند. `postal_code` و `company` عمداً
     * نیستند: برای بعضی پسوندها لازم‌اند و برای بعضی نه، و نبودشان نباید کلِ
     * قابلیت را خاموش کند.
     */
    public function companyRegistrant(): ?array
    {
        $c = (array) config('services.openprovider.registrant', []);

        foreach (['first_name', 'last_name', 'email', 'address', 'city', 'phone'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') {
                return null;
            }
        }

        return $c;
    }

    /**
     * شناسهٔ مالکِ ثابتِ شرکت — یک بار ساخته و برای همیشه استفاده می‌شود.
     *
     * ⚠️ در `Setting` می‌نشیند و نه در `registry_handles`: آن جدول کلیدِ
     * `customer_profile_id` دارد و مالکِ شرکت به هیچ مشتری‌ای وصل نیست.
     * ساختنِ یک ردیفِ قلابی با پروفایلِ الکی، همان داده را به یک مشتریِ
     * تصادفی می‌چسباند.
     *
     * @return array{ok:bool, handle:?string, message:string}
     */
    private function companyHandle(array $reg): array
    {
        $cached = trim((string) (\App\Models\Setting::get('openprovider_company_handle') ?? ''));

        if ($cached !== '') {
            return ['ok' => true, 'handle' => $cached, 'message' => ''];
        }

        $phone = $this->splitPhone((string) $reg['phone'], (string) ($reg['country'] ?: 'IR'));

        if ($phone === null) {
            return ['ok' => false, 'handle' => null,
                'message' => 'شمارهٔ تلفنِ مالکِ شرکت در .env معتبر نیست (DOMAIN_OWNER_PHONE).'];
        }

        $payload = [
            'name' => [
                'first_name' => trim((string) $reg['first_name']),
                'last_name'  => trim((string) $reg['last_name']),
            ],
            'address' => [
                'street'  => trim((string) $reg['address']),
                'city'    => trim((string) $reg['city']),
                'state'   => trim((string) ($reg['province'] ?? '')),
                'zipcode' => trim((string) ($reg['postal_code'] ?? '')),
                'country' => strtoupper((string) ($reg['country'] ?: 'IR')),
                'number'  => '1',
            ],
            'phone' => $phone,
            'email' => trim((string) $reg['email']),
        ];

        if (trim((string) ($reg['company'] ?? '')) !== '') {
            $payload['company_name'] = trim((string) $reg['company']);
        }

        $res = $this->op->createCustomer($payload);

        if (! $res['ok'] || blank($res['handle'])) {
            return ['ok' => false, 'handle' => null,
                'message' => $res['message'] ?: 'ساختِ شناسهٔ مالکِ شرکت نزدِ رجیسترار ناموفق بود.'];
        }

        // ⚠️ ذخیره لازم است: بی‌آن، هر ثبت یک مخاطبِ تازه نزدِ رجیسترار می‌سازد
        //    و حسابِ ما پر از مخاطبِ تکراری می‌شود.
        \App\Models\Setting::put('openprovider_company_handle', $res['handle']);

        return ['ok' => true, 'handle' => $res['handle'], 'message' => ''];
    }

    public function handleFor(CustomerProfile $profile): array
    {
        /*
        | 🔴 اگر مالکِ ثابتِ شرکت پیکربندی شده باشد، **همیشه** همان می‌رود.
        |
        | این تصمیمِ کارفراست و ریشهٔ صفِ دستی را می‌خشکاند: ثبت دیگر به
        | کامل‌بودنِ پروفایلِ مشتری بند نیست. مالکیتِ واقعیِ مشتری در جدولِ
        | `domains` خودمان ثبت و نگه داشته می‌شود.
        */
        if (($reg = $this->companyRegistrant()) !== null) {
            return $this->companyHandle($reg);
        }

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
    /**
     * کدام فیلدهای مالک کم‌اند؟ — `[]` یعنی کامل.
     *
     * 🔴 عمداً کنارِ `profileToCustomer()` و از **همان شرط‌ها** ساخته می‌شود.
     * فهرستِ دستیِ موازی در کنترلر یعنی روزی رجیسترار فیلدی اضافه کند، فرمِ
     * خرید بی‌صدا کهنه شود و دوباره دامنهٔ پرداخت‌شده در صفِ دستی پارک شود —
     * دقیقاً همان چیزی که این تغییر برای رفعش آمد.
     *
     * ⚠️ `postal_code` این‌جا هست ولی در گیتِ `profileToCustomer()` نیست:
     * رجیسترار برای بعضی پسوندها می‌خواهدش و برای بعضی نه. پس در فرم
     * **خواسته** می‌شود ولی خالی‌بودنش جلوی فروش را نمی‌گیرد — وگرنه مشتری را
     * سرِ چیزی که شاید لازم نباشد از خرید بازمی‌داریم.
     *
     * @return array<int,string>
     */
    public function missingOwnerFields(CustomerProfile $profile): array
    {
        $missing = [];

        foreach (['first_name', 'last_name', 'email', 'address', 'city'] as $f) {
            if (trim((string) $profile->{$f}) === '') {
                $missing[] = $f;
            }
        }

        if ($this->splitPhone((string) $profile->mobile, (string) $profile->country) === null) {
            $missing[] = 'mobile';
        }

        return $missing;
    }

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

        /*
        |----------------------------------------------------------------------
        | 🔴 اگر خودِ شماره با `+` آمده، همان کدِ کشور معتبر است
        |----------------------------------------------------------------------
        |
        | نسخهٔ قبلی کدِ کشور را **فقط** از `$country` می‌ساخت و برای هر کشوری
        | جز ایران به پیش‌فرضِ `default_cc` (۹۸) می‌افتاد. یعنی مالکی که
        | کشورش `TR` است و شماره‌اش `+1716…` (آمریکا)، این‌طور به رجیسترار
        | می‌رفت:
        |
        |     +98 171 6666425      ← شماره‌ای که وجود ندارد
        |
        | و نتیجه‌اش دقیقاً همان چیزی است که می‌خواستیم از بین ببریم: رجیسترار
        | مخاطب را رد می‌کند و دامنه دوباره در صفِ دستی پارک می‌شود.
        |
        | ⚠️ کشور و کدِ تلفن دو چیزِ متفاوت‌اند و لازم نیست بخوانند: شرکتی که
        | نشانی‌اش ترکیه است می‌تواند شمارهٔ آمریکا داشته باشد. پس ورودیِ صریحِ
        | کاربر (`+`) بر هر استنتاجی مقدم است.
        */
        $raw = trim($this->toLatinDigits($raw));

        if (str_starts_with($raw, '+') && strlen($digits) >= 8) {
            // کدِ کشور: ۱ رقمی (NANP) یا ۲–۳ رقمی. طولانی‌ترینِ شناخته‌شده اول.
            $cc = null;

            foreach (['998', '996', '995', '994', '993', '992', '971', '968', '966', '965', '964', '963', '962', '961',
                '90', '98', '49', '44', '39', '34', '33', '31', '30', '20', '86', '81', '82', '91', '61', '55', '52', '7'] as $code) {
                if (str_starts_with($digits, $code)) {
                    $cc = $code;

                    break;
                }
            }

            // NANP و هر چیزِ ناشناخته: رقمِ اول کدِ کشور است
            $cc ??= substr($digits, 0, 1);

            $national = substr($digits, strlen($cc));

            if (strlen($national) >= 6) {
                return [
                    'country_code'      => '+'.$cc,
                    'area_code'         => substr($national, 0, 3),
                    'subscriber_number' => substr($national, 3),
                ];
            }
        }

        $cc = match (strtoupper($country)) {
            'IR' => '+98',
            'TR' => '+90',
            'DE' => '+49',
            'NL' => '+31',
            'GB', 'UK' => '+44',
            'US', 'CA' => '+1',
            default => '+'.((string) config('services.openprovider.default_cc', '98')),
        };

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
    /**
     * چه چیزی جلوی ثبتِ این دامنه را می‌گیرد؟ — `null` یعنی راه باز است.
     *
     * ═══ چرا این متد ساخته شد ═══
     *
     * 🔴 `domains:resolve-stuck` باید بپرسد «مانع هنوز هست؟» و تا شهریور ۱۴۰۵
     * فقط **کامل‌بودنِ پروفایل** را می‌پرسید — سؤالِ اشتباه، با دو خرابیِ متضاد:
     *
     *   • مانعِ واقعی چیزِ دیگری بود (قراردادِ امضانشده، رجیسترارِ خاموش):
     *     پروفایلِ کامل ⇒ «آزاد» ⇒ ثبتِ دوباره ⇒ شکستِ همان ⇒ دوباره manual —
     *     حلقهٔ ساعتی که هر دور `updated_at` را تازه می‌کرد و **مهلتِ ۲۴ساعتهٔ
     *     بازگشتِ وجه هرگز فرا نمی‌رسید**. پولِ مشتری در برزخ، برای همیشه.
     *
     *   • مالکِ ثابتِ شرکت پیکربندی بود (DOMAIN_OWNER_*): ناقص‌بودنِ پروفایلِ
     *     مشتری اصلاً مانعِ ثبت نیست، ولی «مانع» شمرده می‌شد ⇒ بعد از ۲۴ ساعت
     *     دامنه‌ای که ثبت می‌شد **لغو و رفاند** می‌شد.
     *
     * این متد همان گیت‌های `doRegister()` را می‌پرسد — به همان ترتیب و از
     * همان توابع — فقط **بدونِ هیچ تماسِ API**. شرطِ دست‌نویسِ موازی در
     * فرمانِ دیگر، همان دردی است که کامنتِ خودِ آن فرمان هشدار داده بود.
     *
     * ⚠️ `TldGate` هم این‌جاست با اینکه در `doRegister()` نیست: دروازهٔ بسته
     * یعنی «می‌دانیم شکست می‌خورد»؛ آزادکردنِ ردیف به‌سمتِ شکستِ حتمی فقط
     * تماسِ بی‌فایده با حسابِ علامت‌خورده است. بعد از امضا، مدیر با «ارسال
     * دوباره به صف» مسیر را باز می‌کند و ثبتِ موفق دروازه را خودکار می‌گشاید.
     */
    public function registrationBlocker(Domain $domain): ?string
    {
        if (! $this->op->enabled()) {
            return 'اتصالِ رجیسترار پیکربندی نشده است.';
        }

        if (TldGate::isBlocked((string) $domain->tld)) {
            return 'قراردادِ رجیستریِ پسوندِ «.'.$domain->tld.'» هنوز امضا نشده است.';
        }

        // مالکِ ثابتِ شرکت که باشد، پروفایلِ مشتری هیچ نقشی در ثبت ندارد.
        if ($this->companyRegistrant() === null) {
            $profile = $domain->customer?->defaultProfile();

            if ($profile === null || $this->profileToCustomer($profile) === null) {
                return 'مشخصاتِ مالک ناقص است (نام، نشانی، شهر، تلفن و ایمیل لازم است).';
            }
        }

        if (count($domain->effectiveNameServers()) < 2) {
            return 'نام‌سرورِ پیش‌فرضِ شرکت تنظیم نشده است (تنظیماتِ domain_nameservers).';
        }

        return null;
    }

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

            /*
            | 🔴 قراردادِ امضانشده ⇒ **بی‌درنگ** به صفِ آدم، بی‌تلاشِ دوباره.
            |
            | این تنها جایی است که «سه بار تلاش کن» غلط است: پاسخ تا وقتی یک
            | انسان در پنلِ رجیسترار امضا نکند عوض نمی‌شود، پس هر تلاشِ اضافه
            | فقط یک تماسِ بی‌فایده با حسابی است که قبلاً علامت خورده.
            | توضیحِ کامل بالای `CONTRACT_CODES`.
            */
            if (self::isUnsignedContract((int) $res['code'])) {
                /*
                | 🔴 و **کلِ پسوند** را از فروش بردار.
                |
                | این شکست مالِ این دامنه نیست، مالِ پسوند است: تا امضا نشود
                | هیچ `.{$domain->tld}`ای ثبت نمی‌شود. بی‌این خط، مشتریِ بعدی
                | دقیقاً همان مسیر را می‌رفت — پول می‌داد، ثبت نمی‌شد، چند روز
                | بعد پولش برمی‌گشت. توضیحِ کامل در `TldGate`.
                */
                TldGate::block((string) $domain->tld, 'قراردادِ رجیستری امضا نشده است.');

                return $this->fail($domain, $this->contractMessage($domain, $res['message']), manual: true);
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
        /*
        | ✅ خودشفایی: یک ثبتِ **واقعاً موفق** ثابت می‌کند قرارداد امضا شده،
        | پس دروازهٔ آن پسوند خودش باز می‌شود و مدیر کارِ دستیِ دومی ندارد.
        |
        | ⚠️ چرا این‌جا بی‌خطر است در حالی که معادلش در `cloud:reopen` عمداً
        | دستی ماند: آن‌جا اثباتِ «دیگر شکست نمی‌خورد» یک سفارشِ تازه و پولِ
        | واقعی لازم داشت. این‌جا دامنهٔ پارک‌شده از قبل پرداخت شده است، پس
        | تلاشِ دوباره‌اش هیچ پولِ تازه‌ای خرج نمی‌کند و خودش همان اثبات است.
        */
        TldGate::clear((string) $domain->tld);

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

    /**
     * پیامِ **قابلِ اقدام** برای مدیر — نه ترجمهٔ خامِ رجیسترار.
     *
     * ⚠️ پسوند در متن می‌آید چون قرارداد **per-TLD** است: امضای `.com` مشکلِ
     * `.shop` را حل نمی‌کند. بی‌نامِ پسوند، مدیر باید حدس بزند کدام را امضا کند.
     *
     * ⚠️ پیامِ خامِ رجیسترار عمداً ته متن می‌مانَد: این ستون فقط برای مدیر است
     * (مشتری نمی‌بیندش) و اگر روزی معنای کد عوض شود، تنها ردِ واقعیت همان است.
     */
    private function contractMessage(Domain $domain, string $raw): string
    {
        return 'قراردادِ رجیستریِ پسوندِ «.'.$domain->tld.'» در حسابِ رجیسترار امضا نشده است. '
            .'تا امضا نشود هیچ دامنه‌ای با این پسوند ثبت نمی‌شود — این تنظیمِ حسابِ ماست، '
            .'نه اشکالِ مشتری یا این دامنه. '
            .'امضا: پنلِ رجیسترار ← Account ← Contracts ('.self::CONTRACTS_URL.') '
            .'و بعد در همین صفحه «ارسال دوباره به صف» را بزنید. '
            .'پیامِ رجیسترار: '.$raw;
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

        /*
        |----------------------------------------------------------------------
        | 🔴 پارک‌شدنِ یک دامنهٔ **پرداخت‌شده** نباید بی‌صدا باشد
        |----------------------------------------------------------------------
        |
        | تا امروز این متد فقط یک ستون می‌نوشت. یعنی وقتی `zhina.shop` به صفِ
        | دستی رفت، `/admin/errors` خالی ماند — هیچ `ErrorTracker::*` صدا
        | نمی‌شد — و هیچ اعلانِ مستقیمی نرفت.
        |
        | ⚠️ `SystemHealth::stuckDomains()` **این را می‌بیند** (ردیف‌های
        | `manual` را جدا می‌شمارد). پس سامانه کاملاً کور نبود. ولی آن چک هر
        | ۱۵ دقیقه می‌دود، فقط روی **تغییرِ وضعیت** خبر می‌دهد، و پیامش عددی
        | است: «۱ دامنه منتظرِ بررسیِ دستیِ شماست» — نه نامِ دامنه، نه علت. یعنی
        | مدیر می‌فهمید چیزی هست، ولی نه کدام و نه چرا.
        |
        | این‌جا در **لحظهٔ** شکست، با نام و علت. آن یکی شمارشگرِ دائمی است و
        | این یکی رویداد؛ هیچ‌کدام جای دیگری را نمی‌گیرد.
        |
        | ⚠️ فقط برای `manual` داد می‌زند: `pending` یعنی کرون دوباره تلاش
        | می‌کند و هر تلاشِ گذرا یک هشدار نیست — همان سیلی که یک بار پنجرهٔ
        | ۴۰۰ خطیِ ردیاب را شست.
        */
        if ($manual) {
            try {
                ErrorTracker::noteOnce('domain', 'دامنهٔ پرداخت‌شده به صفِ دستی رفت: '.$domain->domain, 900, [
                    'domain' => $domain->domain,
                    'reason' => mb_substr($message, 0, 160),
                    'tries'  => (int) $domain->provision_tries,
                ]);

                app(\App\Services\Notify\AdminNotifier::class)->event(
                    'ثبتِ دامنه خودکار انجام نشد',
                    ['دامنه' => $domain->domain, 'علت' => mb_substr($message, 0, 160)],
                    url('/admin/domains'),
                    '🌐',
                );
            } catch (\Throwable $e) {
                // هشدار هرگز نباید مسیرِ ثبت را بشکند — ولی خودش هم گم نشود
                Log::warning('اعلانِ پارک‌شدنِ دامنه نرفت', ['err' => $e->getMessage()]);
            }
        }

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
            // ⚠️ همان دیوارِ قراردادِ امضانشده در مسیرِ تمدید هم هست، و آن‌جا
            // گران‌تر است: دامنهٔ **زندهٔ** مشتری منقضی می‌شود. پس پیام باید
            // همان‌قدر قابلِ اقدام باشد، نه متنِ خامِ انگلیسی.
            return [
                'ok'      => false,
                'message' => self::isUnsignedContract((int) $res['code'])
                    ? $this->contractMessage($domain, $res['message'])
                    : ($res['message'] ?: 'تمدید ناموفق بود.'),
            ];
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
        // ⚠️ `renew_invoice_id` هم پاک می‌شود: کارش تمام شد. اگر بماند و
        //    تمدیدِ سالِ بعد شکست بخورد، فرمانِ بازگشتِ وجه فاکتورِ **پارسال**
        //    را برمی‌گردانْد.
        $domain->putMeta(['exp_stage' => null, 'renew_invoice_id' => null, 'renewed_at' => now()->toDateTimeString()]);

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

        /*
        | 🔴 پارک‌شدنِ تمدیدِ **پرداخت‌شده** نباید بی‌صدا باشد — همان قاعدهٔ
        | `fail()` در مسیرِ ثبت. تا امروز شکستِ ثبت اعلانِ مدیر داشت و شکستِ
        | تمدید نه؛ در حالی که این‌جا گران‌تر است: دامنهٔ **زندهٔ** مشتری دارد
        | به‌سمتِ انقضا می‌رود و پولش هم گرفته شده.
        */
        if ($manual) {
            try {
                app(\App\Services\Notify\AdminNotifier::class)->event(
                    'تمدیدِ دامنه خودکار انجام نشد',
                    ['دامنه' => $domain->domain, 'علت' => mb_substr($message, 0, 160)],
                    url('/admin/domains'),
                    '⏳',
                );
            } catch (\Throwable $e) {
                Log::warning('اعلانِ شکستِ تمدید نرفت', ['err' => $e->getMessage()]);
            }
        }

        return ['ok' => false, 'manual' => $manual, 'message' => $message];
    }
}
