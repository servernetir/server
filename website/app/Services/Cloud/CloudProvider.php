<?php

namespace App\Services\Cloud;

/**
 * قراردادِ ارائه‌دهندهٔ سرورِ ابری — هتزنر، آیزا و هر ارائه‌دهندهٔ بعدی.
 *
 * ⚠️ این لایه عمداً «سفیدبرچسب» است: هیچ‌جای بالاتر از این کلاس‌ها نباید نامِ
 * ارائه‌دهنده را به مشتری برساند. کنترلرها با پلن و مکانِ **خودمان** کار می‌کنند و
 * فقط `CloudManager` می‌داند کدام درایور را صدا بزند.
 *
 * ⚠️ همهٔ متدها **باید** خطا را به‌جای throw، در نتیجهٔ برگشتی بگویند
 * (`ok=false`). یک ارائه‌دهندهٔ از کار افتاده نباید صفحهٔ پنلِ مشتری را ۵۰۰ کند.
 *
 * قاعدهٔ idempotency: `createServer` با یک `name` تکراری نباید سرورِ دوم بسازد؛
 * اگر ارائه‌دهنده خطای «نامِ تکراری» داد، همان سرورِ موجود برگردانده می‌شود.
 * (کرونِ تحویل ممکن است دو بار روی یک سرویس بدود.)
 */
interface CloudProvider
{
    /** شناسهٔ داخلیِ درایور: hetzner | aeza — هرگز به مشتری نشان داده نمی‌شود */
    public function slug(): string;

    /** آیا توکنِ این ارائه‌دهنده در تنظیمات وارد شده؟ */
    public function isConfigured(): bool;

    /**
     * آزمونِ اتصال برای دکمهٔ «تست» در پنلِ مدیریت.
     *
     * @return array{ok:bool,message:string,meta?:array}
     */
    public function testConnection(): array;

    /**
     * کشیدنِ کاتالوگ: مکان‌ها، پلن‌ها و ایمیج‌ها در یک رفت‌وبرگشت.
     *
     * قیمت‌ها همیشه به **سنتِ یورو** برگردانده می‌شوند (بهایِ تمام‌شدهٔ ماهانه)،
     * حتی اگر ارائه‌دهنده به روبل بفروشد؛ تبدیل داخلِ درایور انجام می‌شود تا
     * لایه‌های بالاتر فقط یک واحد بشناسند.
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   locations: array<int, array{code:string,country:string,city:?string,provider_location:string,latitude:?float,longitude:?float}>,
     *   plans: array<int, array{provider_ref:string,provider_location:string,location_code:string,vcpu:int,ram_mb:int,disk_gb:int,disk_type:string,traffic_gb:int,cpu_kind:string,arch:string,cost_eur_cents:int,in_stock:bool,name:string}>,
     *   images: array<int, array{provider_ref:string,key:string,kind:string,family:?string,version:?string,label:string,arch:string,min_disk_gb:int}>
     * }
     */
    public function fetchCatalog(): array;

    /**
     * ساختِ سرور. idempotent روی `name`.
     *
     * @param  array{name:string,plan_ref:string,location_ref:string,image_ref:string,ssh_keys?:array<int,string>,user_data?:?string,labels?:array<string,string>}  $spec
     * @return array{ok:bool,message:string,ref:?string,ipv4:?string,ipv6:?string,root_password:?string,status:string,raw?:array}
     */
    public function createServer(array $spec): array;

    /**
     * وضعیتِ زندهٔ سرور — برای صفحهٔ مدیریتِ مشتری و همگام‌سازیِ دوره‌ای.
     *
     * @return array{ok:bool,message:string,status:string,ipv4:?string,ipv6:?string,traffic_used_gb:?float,raw?:array}
     */
    public function serverStatus(string $ref): array;

    /**
     * روشن/خاموش/راه‌اندازیِ دوباره.
     *
     * @param  'on'|'off'|'reboot'|'reset'|'shutdown'  $action
     * @return array{ok:bool,message:string}
     */
    public function power(string $ref, string $action): array;

    /**
     * نصبِ دوبارهٔ سیستم‌عامل (پاک‌کنندهٔ داده — بالاتر باید تأیید بگیرد).
     *
     * @return array{ok:bool,message:string,root_password:?string}
     */
    public function rebuild(string $ref, string $imageRef, ?string $password = null): array;

    /** @return array{ok:bool,message:string,root_password:?string} */
    public function resetPassword(string $ref): array;

    /**
     * کنسولِ تحتِ وب (VNC/noVNC).
     *
     * @return array{ok:bool,message:string,url:?string,password:?string}
     */
    public function console(string $ref): array;

    /**
     * نمودارِ مصرف. کلیدها: cpu، net_in، net_out، disk_read، disk_write.
     *
     * @return array{ok:bool,message:string,series:array<string,array<int,array{0:int,1:float}>>}
     */
    public function metrics(string $ref, string $window = '24h'): array;

    /** حذفِ کاملِ سرور (پس از خاتمهٔ سرویس) */
    public function deleteServer(string $ref): array;

    /**
     * تغییرِ پلن (ارتقا/تنزل). ممکن است بعضی ارائه‌دهنده‌ها پشتیبانی نکنند —
     * در آن صورت ok=false با پیامِ روشن.
     *
     * @return array{ok:bool,message:string}
     */
    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array;

    /**
     * بارگذاریِ کلیدِ عمومیِ SSH در حسابِ ما نزدِ ارائه‌دهنده.
     *
     * ⚠️ چرا لازم است: ارائه‌دهنده‌ها سرِ ساختِ سرور فقط **اشاره** به کلیدِ
     * ازقبل‌بارگذاری‌شده می‌پذیرند، نه متنِ کلید. پس یک‌بار بارگذاری می‌شود و
     * شناسه‌اش نگه داشته می‌شود.
     *
     * idempotent: اگر کلید (با همان اثرِ انگشت) از قبل باشد، همان شناسهٔ موجود
     * برگردانده می‌شود نه خطا — وگرنه دومین سرورِ همان مشتری تحویل نمی‌شد.
     *
     * @return array{ok:bool,message:string,ref:?string}
     */
    public function uploadSshKey(string $name, string $publicKey): array;

    /**
     * افزودنِ IPv4 اضافه به یک سرورِ ساخته‌شده.
     *
     * @return array{ok:bool,message:string,ips:array<int,string>}
     */
    public function addExtraIps(string $ref, int $count): array;

    /**
     * توانایی‌های این ارائه‌دهنده تا رابطِ کاربری دکمهٔ بی‌فایده نشان ندهد.
     *
     * @return array<string,bool> کلیدها: console, rebuild, resize, snapshot,
     *                            metrics, reset_password, ipv6, ssh_key, extra_ip
     */
    public function capabilities(): array;
}
