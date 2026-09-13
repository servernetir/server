<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Cloud\PublicPortAllocator;

/**
 * «حالتِ مطلوبِ» سیاستِ شبکهٔ داخلی + نسخه‌اش.
 *
 * ═══ چرا یک کلاسِ مشترک و نه محاسبه در دو جا ═══
 *
 * 🔴 هم مسیرِ عامل (`/agent/guestpolicy`) و هم صفحهٔ مدیر باید بدانند «نسخهٔ
 * مطلوبِ امروز چیست». اگر هرکدام خودش حساب کند، روزی یکی‌شان عوض می‌شود و
 * صفحه برای همیشه «در انتظار» یا برای همیشه «اعمال شد» نشان می‌دهد — بی‌هیچ
 * خطایی. همان تلهٔ «دو تعریف برای یک چیز» که این پروژه بارها خورده
 * (دورهٔ شش‌ماهه در ۷ جا، فیلترِ desired در پنل و کرون).
 *
 * ═══ نسخه چگونه ساخته می‌شود ═══
 *
 * هشِ محتوای **مرتب‌شده**. پس:
 *   • تغییرِ ترتیبِ ردیف‌ها در دیتابیس نسخه را عوض نمی‌کند (وگرنه هر بار
 *     «در انتظار» می‌شد و ack بی‌معنا).
 *   • تغییرِ هر سیاستی نسخه را عوض می‌کند.
 *   • افزوده/حذف‌شدنِ یک ماشین هم نسخه را عوض می‌کند.
 */
class GuestPolicySnapshot
{
    /** کلیدهای Settings — در یک جا تا تایپو دو رفتار نسازد. */
    public const ACK_REVISION = 'agent_guestpolicy_revision';

    public const ACK_AT = 'agent_guestpolicy_acked_at';

    public const ACK_ERROR = 'agent_guestpolicy_error';

    /** فاصلهٔ پیمایشِ پیشنهادی به عامل (ثانیه). */
    public const INTERVAL = 30;

    public function __construct(private PublicPortAllocator $ports) {}

    /**
     * ردیف‌های سیاست، مرتب بر اساسِ IP.
     *
     * @return array<int, array{ip:string, lan:bool}>
     */
    public function policies(): array
    {
        $rows = [];

        foreach ($this->ports->eligible() as $inst) {
            $ip = trim((string) $inst->ipv4);

            if ($ip === '') {
                continue;
            }

            $rows[$ip] = ['ip' => $ip, 'lan' => $inst->lanAccess()];
        }

        ksort($rows);          // ترتیبِ قطعی ⇒ نسخهٔ پایدار

        return array_values($rows);
    }

    /** نسخهٔ حالتِ مطلوب — هشِ محتوای مرتب‌شده. */
    public function revision(): string
    {
        return substr(sha1(json_encode($this->policies(), JSON_UNESCAPED_SLASHES)), 0, 12);
    }

    /**
     * بسته‌ای که به عامل داده می‌شود.
     *
     * ⚠️ `schema` عمداً در پاسخ هست: صفحهٔ خطای Cloudflare و صفحهٔ نگه‌داری با
     * کدِ ۲۰۰ می‌آیند. بی‌یک نشانهٔ صریح، عامل آن HTML را «پاسخِ معتبرِ خالی»
     * می‌خواند و **همهٔ قواعد را پاک می‌کند**. همان درسِ `SNET|1|` در ایجنتِ روتر.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        $policies = $this->policies();

        return [
            'schema'   => 'servernet.guestpolicy.v1',
            'revision' => substr(sha1(json_encode($policies, JSON_UNESCAPED_SLASHES)), 0, 12),
            'interval' => self::INTERVAL,
            'lan_cidr' => (string) config('servernet.exit.lan_cidr', '10.10.10.0/24'),
            'policies' => $policies,
        ];
    }

    /**
     * وضعیتِ اعمال از دیدِ پنل.
     *
     * 🔴 «ضربان» با «اعمال شد» یکی نیست. ضربان فقط می‌گوید عامل زنده است؛
     * این می‌گوید عامل **همین نسخه** را گرفته و بی‌خطا اجرا کرده. تا امروز
     * هیچ‌جای این سامانه تمایزشان را نمی‌گذاشت.
     *
     * @return array{state:string, revision:string, acked:?string, at:?string, error:?string}
     */
    public function status(): array
    {
        $want = $this->revision();
        $have = (string) (Setting::get(self::ACK_REVISION) ?: '');
        $err = Setting::get(self::ACK_ERROR);

        /*
         * ⚠️ ترتیب مهم است و یک بار اشتباه بود: «هرگز» پیش از «ناموفق» سنجیده
         * می‌شد، پس **اولین** شکستِ یک نصبِ تازه در پنل «هنوز هیچ عاملی تأیید
         * نکرده» دیده می‌شد — یعنی دقیقاً وقتی که عامل دارد فریاد می‌زند،
         * صفحه می‌گفت هنوز شروع نشده. شکست همیشه اول.
         */
        $state = match (true) {
            filled($err)    => 'failed',
            $have === ''    => 'never',     // عاملی هرگز چیزی تأیید نکرده
            $have === $want => 'applied',
            default         => 'pending',
        };

        return [
            'state'    => $state,
            'revision' => $want,
            'acked'    => $have !== '' ? $have : null,
            'at'       => Setting::get(self::ACK_AT) ?: null,
            'error'    => filled($err) ? (string) $err : null,
        ];
    }
}
