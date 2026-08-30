<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * درایورِ SaladCloud — زیرساختِ **GPU**، و از هر پنجِ زیرساختِ دیگر متفاوت.
 *
 * نامِ مسیرها و فیلدها **حدسی نیست**: از اسپکِ رسمیِ OpenAPI خودشان درآمده
 * (`SaladTechnologies/salad-cloud-docs` → `api-specs/salad-cloud.yaml`).
 *
 * ═══ سه تفاوتِ بنیادی که شکلِ این فایل را تعیین کرده‌اند ═══
 *
 * ۱) 🔴 **این‌جا ماشینِ مجازی وجود ندارد — فقط کانتینر.**
 *    کلِ API آنها روی «گروهِ کانتینر» است. هیچ endpointی برای VM، سرورِ لخت،
 *    ایمیجِ سیستم‌عامل یا رمزِ root نیست (کلِ اسپک گشته شد: صفر مورد). پس این
 *    متدها **واقعاً** پیاده‌شدنی نیستند و `capabilities()` صریح false می‌گوید:
 *    `rebuild` · `reset_password` · `resize` · `console` · `extra_ip`.
 *    ⚠️ آنچه هست: نمونه‌ها `ssh_ip`/`ssh_port`/`ssh_host_key_fingerprint`
 *    دارند، پس مشتری واقعاً به جعبه SSH می‌زند.
 *
 * ۲) 🔴 **قطعِ شدنی است — حتی در بالاترین اولویت.**
 *    مستنداتِ خودشان: در بالاترین اولویت نمونه‌ها توسطِ بارهای کاریِ دیگر قطع
 *    نمی‌شوند «ولی باز هم قطع خواهند شد» — چون گره‌ها رایانه‌های خانگیِ بی‌کارند
 *    و صاحبشان دستگاه را پس می‌گیرد. پس `is_interruptible` روی پلن نوشته
 *    می‌شود، و این محصول **نباید** با زبانِ «سرورِ اختصاصیِ پایدار» فروخته شود:
 *    تعهدِ `/sla` پشتش نیست.
 *
 * ۳) 🔴 **بهایِ تمام‌شده سه تکه است، نه یکی** — گران‌ترین تلهٔ این درایور:
 *
 *        بها/ساعت = قیمتِ GPU(کلاس، اولویت) + vCPU×نرخ + گیگ‌رم×نرخ
 *
 *    و API **فقط تکهٔ اول** را می‌دهد؛ دو نرخِ دیگر تنها در مستنداتِ متنی‌اند.
 *    اگر تکهٔ GPU را تنها بگیریم، روی پیکربندیِ ۱۶ هسته/۶۰ گیگ حدودِ ۰٫۱۲ دلار
 *    در ساعت کم‌برآورد می‌کنیم — بیشتر از خودِ کارت‌های ارزان، یعنی **فروشِ
 *    زیرِ قیمتِ خرید روی هر ساعت**.
 *    پس دو نرخ `Setting`ِ صریح‌اند (مثلِ `aeza_price_divisor`)، نه عددِ سخت‌کد.
 */
class SaladClient implements CloudProvider
{
    use SaladOperations;

    private const BASE = 'https://api.salad.com/api/public';

    /** تبدیلِ «ساعتی» به «ماهانه»ی قرارداد (۳۰٫۴ روز) */
    public const HOURS_PER_MONTH = 730;

    /** یک گیبی‌بایت — واحدِ واقعیِ storage در APIِ زیرساخت (کمینه 1، بیشینه 250) */
    public const GIB = 1_073_741_824;

    /*
    | پیکربندیِ پیش‌فرض وقتی کلاسِ GPU سقفِ CPU/RAM/دیسک اعلام نمی‌کند
    | (اسپک صفر را مجاز می‌داند و پاسخِ واقعی همین بود). این همان چیزی است که
    | می‌فروشیم و قیمتش هم از همین ساخته می‌شود — تغییرش یعنی sync دوباره.
    */
    public const DEFAULT_VCPU = 8;
    public const DEFAULT_RAM_MB = 30720;

    /*
    | 🔴 ۱۵۰ گیگ، نه ۵۰ — و این تغییر **هیچ** هزینه‌ای اضافه نمی‌کند: صورت‌حسابِ
    | این زیرساخت فقط GPU + vCPU + رم است و storage جزوِ اجزای پولی نیست
    | (docs.salad.com/container-engine/…/billing — بررسیِ ۷ شهریور ۱۴۰۵).
    | ۵۰ گیگ برای فاین‌تیونِ واقعی کم بود (مدلِ ۱۵–۲۰ گیگی + checkpointها؛
    | درخواستِ صریحِ مشتری: «حداقل ۱۰۰»). سقفِ APIِ آن‌ها ۲۵۰ است؛ ۲۵۰ عمداً
    | نه: هر گیگِ بیشتر استخرِ نودهای واجدِ شرایط را کوچک‌تر و تخصیص را
    | کندتر می‌کند — ۱۵۰ تعادلِ نیاز و موجودی است.
    */
    public const DEFAULT_DISK_GB = 150;

    /**
     * نرخِ پیش‌فرضِ vCPU و رم بر ساعت به دلار — از `billing.mdx` خودشان:
     * «۱ vCPU × ۰٫۰۰۴ + ۲ گیگ × ۰٫۰۰۱ = ۰٫۰۰۶ دلار در ساعت».
     * ⚠️ در API نیستند؛ پیش‌فرض‌اند نه حقیقتِ زنده.
     */
    public const DEFAULT_VCPU_USD_HOUR = 0.004;

    public const DEFAULT_RAM_GB_USD_HOUR = 0.001;

    /**
     * مکانِ نمادین.
     *
     * ⚠️ این زیرساخت **دیتاسنتر ندارد**؛ گره‌هایش رایانه‌های خانگی در سراسر
     * دنیایند و تنها کنترلِ مکان، فهرستِ `country_codes` است. ساختنِ مکان‌های
     * جعلیِ کشوری یعنی وعدهٔ کنترلی که نداریم.
     */
    public const LOCATION = 'global-gpu';

    public function slug(): string
    {
        return 'salad';
    }

    private function key(): ?string
    {
        return Setting::getSecret('salad_api_key');
    }

    private function org(): string
    {
        return trim((string) Setting::get('salad_org', ''));
    }

    private function project(): string
    {
        $p = trim((string) Setting::get('salad_project', ''));

        return $p !== '' ? $p : 'default';
    }

    /**
     * ⚠️ هر دو لازم‌اند. کلیدِ تنها بی‌فایده است چون **هر** مسیرِ این API نامِ
     * سازمان را در خودش دارد؛ بی‌آن هیچ درخواستی حتی ساخته نمی‌شود.
     */
    public function isConfigured(): bool
    {
        return filled($this->key()) && $this->org() !== '';
    }

    public function capabilities(): array
    {
        return [
            // کنسولِ تحتِ وب ندارند؛ دسترسی از راهِ نشانیِ HTTPS و APIِ برنامه است.
            'console'        => false,
            // «نصبِ دوبارهٔ سیستم‌عامل» این‌جا وجود ندارد.
            'rebuild'        => false,
            // تغییرِ کلاسِ GPU نمونه را از نو جابه‌جا می‌کند و دادهٔ محلی
            // می‌رود. تا وقتی مسیرِ امنش ساخته نشده، صریح false — نه دکمه‌ای
            // که بی‌خبر داده را پاک کند.
            'resize'         => false,
            'snapshot'       => false,
            'metrics'        => false,
            // رمزِ root وجود ندارد؛ توکنِ برنامه هنگامِ ساخت تزریق و یک بار نشان داده می‌شود.
            'reset_password' => false,
            'ipv6'           => false,
            'rescue'         => false,
            // کلیدِ سطحِ حساب نداریم؛ هرچه لازم است هنگامِ ساخت تزریق می‌شود.
            'ssh_key'        => false,
            'extra_ip'       => false,
        ];
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        return ['ok' => false, 'message' => 'بارگذاریِ کلیدِ SSH برای این سرور در دسترس نیست.', 'ref' => null];
    }

    public function addExtraIps(string $ref, int $count): array
    {
        return ['ok' => false, 'message' => 'IP اضافه برای این سرور در دسترس نیست.', 'ips' => []];
    }

    public function rebuild(string $ref, string $imageRef, ?string $password = null): array
    {
        return ['ok' => false, 'message' => 'نصبِ دوبارهٔ سیستم‌عامل برای این سرور در دسترس نیست.', 'root_password' => null];
    }

    public function resetPassword(string $ref): array
    {
        return ['ok' => false, 'message' => 'این سرویس رمزِ root ندارد؛ دسترسی با نشانیِ HTTPS و توکنِ اختصاصی است که هنگامِ تحویل ساخته می‌شود.', 'root_password' => null];
    }

    public function console(string $ref): array
    {
        return ['ok' => false, 'message' => 'کنسولِ تحتِ وب برای این سرور در دسترس نیست.', 'url' => null, 'password' => null];
    }

    public function metrics(string $ref, string $window = '24h'): array
    {
        return ['ok' => false, 'message' => 'نمودارِ مصرف برای این سرور در دسترس نیست.', 'series' => []];
    }

    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array
    {
        return ['ok' => false, 'message' => 'تغییرِ پلن برای این سرور در دسترس نیست.'];
    }

    // ───────────────────────── لایهٔ تماس ─────────────────────────

    /**
     * ⚠️ برخلافِ زحل/OpenProvider/Cloudflare/Plesk، این API **کدِ HTTP را درست
     * می‌دهد**. پس کد معیارِ اصلی است و بدنه فقط برای پیام خوانده می‌شود.
     *
     * ⚠️ سقفِ نرخ ۲۴۰ درخواست در دقیقه به‌ازای هر کلید است (مستنداتِ خودشان)؛
     * ۴۲۹ صریح از «توکنِ باطل» جدا می‌شود تا عیب‌یاب دنبالِ کلید نگردد.
     *
     * @return array{ok:bool,status:int,body:mixed,message:string}
     */
    private function req(string $method, string $path, array $payload = [], array $query = []): array
    {
        $key = $this->key();

        if (! filled($key)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'message' => 'کلیدِ API این زیرساخت تنظیم نشده است.'];
        }

        try {
            $http = Http::withHeaders([
                'Salad-Api-Key' => $key,
                'Accept'        => 'application/json',
            ])->timeout(30)->connectTimeout(12);

            $opts = [];

            if ($payload !== []) {
                $opts['json'] = $payload;
            }

            if ($query !== []) {
                $opts['query'] = $query;
            }

            $r = $http->send(strtoupper($method), self::BASE.$path, $opts);
        } catch (\Throwable $e) {
            // ⚠️ نامِ کلاس، نه متنِ استثنا: متن می‌تواند خودِ کلید را در خود
            // داشته باشد و این پیام تا لاگ و پنل می‌رود.
            return ['ok' => false, 'status' => 0, 'body' => null,
                'message' => 'ارتباط برقرار نشد ('.class_basename($e).').'];
        }

        $body = null;

        try {
            $body = $r->json();
        } catch (\Throwable) {
        }

        if ($r->successful()) {
            return ['ok' => true, 'status' => $r->status(), 'body' => $body, 'message' => ''];
        }

        /*
        | خطاهای این زیرساخت به قالبِ problem+json می‌آیند (title/type/errors)
        | و مستنداتشان هشدار می‌دهد گاهی هم **سندِ HTML**. نسخهٔ اول فقط
        | message/error/detail را می‌خواند، پس ۴۰۰ واقعی به «خطای زیرساخت
        | (کدِ 400)» تخت می‌شد و عیب‌یابیِ پروداکشن کور بود.
        */
        $msg = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? $body['detail'] ?? $body['title'] ?? '')
            : trim(mb_substr(strip_tags((string) $r->body()), 0, 200));

        if (is_array($body) && isset($body['errors']) && is_array($body['errors'])) {
            $flat = [];

            array_walk_recursive($body['errors'], function ($v, $k) use (&$flat) {
                if (is_scalar($v) && count($flat) < 4) {
                    $flat[] = $k.': '.$v;
                }
            });

            if ($flat !== []) {
                $msg = trim($msg.' — '.implode(' · ', $flat));
            }
        }

        if ($msg === '') {
            $msg = match ($r->status()) {
                401, 403 => 'کلیدِ API پذیرفته نشد.',
                404      => 'پیدا نشد.',
                429      => 'سقفِ نرخِ زیرساخت پر شد؛ کمی بعد دوباره.',
                default  => 'خطای زیرساخت (کدِ '.$r->status().').',
            };
        }

        return ['ok' => false, 'status' => $r->status(), 'body' => $body, 'message' => $msg];
    }

    /** پیشوندِ مسیرهای پروژه‌ای */
    private function proj(string $tail = ''): string
    {
        return '/organizations/'.rawurlencode($this->org())
            .'/projects/'.rawurlencode($this->project())
            .'/containers'.$tail;
    }

    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'message' => 'کلیدِ API یا نامِ سازمان تنظیم نشده است.'];
        }

        // سبک‌ترین تماسِ خواندنی که هم کلید و هم نامِ سازمان را می‌سنجد.
        $r = $this->req('GET', '/organizations/'.rawurlencode($this->org()).'/gpu-classes');

        if (! $r['ok']) {
            return ['ok' => false, 'message' => 'سازمان یا کلید: '.$r['message']];
        }

        $classes = fa_num((string) count($this->items($r['body'])));

        /*
        | 🔴 نامِ **پروژه** را هم همین‌جا بسنج، وگرنه تنها جایی که امتحان
        |    می‌شود مسیرِ ساختِ کانتینر است — یعنی اولین سفارشِ واقعیِ مشتری.
        |    آن‌وقت پول گرفته شده و تحویل شکست می‌خورد، به‌خاطرِ یک غلطِ
        |    تایپی در فرمِ تنظیمات که همین‌جا در یک تماسِ خواندنی معلوم می‌شد.
        |    (نامِ سازمان در تماسِ بالا هست، نامِ پروژه فقط در این مسیر.)
        */
        $p = $this->req('GET', $this->proj());

        if (! $p['ok']) {
            return ['ok' => false, 'message' => 'کلید و سازمان درست‌اند ('.$classes
                .' کلاسِ GPU)، ولی پروژه خوانده نشد: '.$p['message']];
        }

        return ['ok' => true, 'message' => 'اتصال برقرار است — '.$classes
            .' کلاسِ GPU، و پروژه هم خوانده شد.'];
    }

    /** آرایهٔ ردیف‌ها از پاسخ، هرجا که باشد */
    private function items(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['items', 'data', 'instances', 'gpu_classes'] as $k) {
            if (isset($body[$k]) && is_array($body[$k])) {
                return array_values(array_filter($body[$k], 'is_array'));
            }
        }

        return array_is_list($body) ? array_values(array_filter($body, 'is_array')) : [];
    }
}
