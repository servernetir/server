<?php

namespace App\Services\Dns;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;

/**
 * «آیا دامنهٔ این مشتری واقعاً به هاستِ ما وصل است؟»
 *
 * مشتری هاست می‌خرد، نیم‌سرور را عوض می‌کند و بعد نمی‌داند کار کرده یا نه —
 * پس تیکت می‌زند. این کلاس همان جواب را می‌سازد تا کارتِ هاست خودش بگوید.
 *
 * ═══ تصمیمِ اصلی: «نیم‌سرورِ ما نیست» ≠ «خراب است» ═══
 *
 * مشتری‌ای که دامنه‌اش را روی Cloudflare گذاشته و از آن‌جا به IPِ ما اشاره
 * می‌دهد، سایتش **کاملاً بالاست** — ولی نیم‌سرورهایش مالِ ما نیست. اگر فقط
 * نیم‌سرور را بسنجیم، به او برچسبِ قرمز می‌دهیم و همان تیکتی را می‌سازیم که
 * قرار بود حذف کنیم، این بار با اضطراب. پس وقتی نیم‌سرور مالِ ما نبود،
 * **رکوردِ A** را هم می‌سنجیم و اگر به سرورِ ما رسید، سبز می‌ماند با متنِ خودش.
 *
 * ⚠️ و «نتوانستم بپرسم» هرگز به قرمز ترجمه نمی‌شود. روی همین ماشینِ توسعه
 * `gethostbyname('igniran.ir')` شکست می‌خورد در حالی که دامنه سالم است
 * (resolverِ محلی ‎.ir را حل نمی‌کند). یک DNSِ کندِ لحظه‌ای نباید به مشتری
 * بگوید سایتت خراب است.
 */
class HostingDnsStatus
{
    /** وضعیت‌هایی که سبزند — یعنی مشتری کاری برای انجام‌دادن ندارد */
    public const HEALTHY = ['ok', 'ok_external', 'managed'];

    public function __construct(private DnsLookup $dns) {}

    /**
     * آیا این نیم‌سرورها مالِ یک سرویسِ پروکسی/CDN هستند؟
     *
     * مقایسه با پسوند است تا `derek.ns.cloudflare.com` هم بیفتد.
     *
     * @param  list<string>  $found
     */
    private function isProxied(array $found): bool
    {
        $proxies = array_map('strtolower', (array) config('provisioning.dns_proxies', []));

        foreach ($found as $ns) {
            foreach ($proxies as $p) {
                if ($ns === $p || str_ends_with($ns, '.'.$p)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{state:string, expected:list<string>, found:list<string>, ip:?string, domain:?string}
     */
    public function check(Service $service): array
    {
        $server   = $service->server;
        $domain   = strtolower(trim((string) $service->domain, '. '));
        $expected = $server ? $server->nameserverList() : [];
        $serverIp = $server?->publicIp();

        $base = ['expected' => $expected, 'found' => [], 'ip' => null, 'domain' => $domain ?: null];

        if ($domain === '' || $server === null) {
            return $base + ['state' => 'unknown'];
        }

        /*
        | زیردامنهٔ رایگانِ خودمان: DNSش دستِ ماست و مشتری هیچ نیم‌سروری برای
        | عوض‌کردن ندارد. اگر همان مسیرِ عمومی را برایش برویم، وضعیتش به
        | نیم‌سرورهای Cloudflareِ ما می‌افتد و «نیم‌سرورت اشتباه است» می‌گیرد —
        | برای کاری که اصلاً به او مربوط نیست.
        */
        $zone = (string) config('servernet.subdomain_zone', 'servernet.cloud');
        if (str_ends_with($domain, '.'.$zone)) {
            return $base + ['state' => 'managed'];
        }

        // ⚠️ کش به‌ازای دامنه است نه سرویس: دو سرویسِ یک دامنه (مهاجرت) همان
        // یک جواب را می‌گیرند، و کش با حذفِ سرویس هم بی‌معنا نمی‌شود.
        return Cache::remember(
            'hosting-dns:'.md5($domain.'|'.implode(',', $expected).'|'.$serverIp),
            now()->addMinutes(10),
            function () use ($domain, $expected, $serverIp, $base) {
                $found = $this->dns->nameservers($domain);

                if ($found === []) {
                    // هیچ نیم‌سروری برنگشت: یا تازه ثبت شده و هنوز منتشر نشده،
                    // یا ما نتوانستیم بپرسیم. هیچ‌کدام «خراب» نیست.
                    return $base + ['state' => 'pending', 'found' => []];
                }

                $ours = array_values(array_intersect($found, $expected));
                $out  = $base + ['found' => $found];

                if (count($ours) === count($found)) {
                    return $out + ['state' => 'ok'];
                }

                if ($ours !== []) {
                    // بخشی مالِ ما، بخشی نه — دامنه گاهی این‌جا حل می‌شود و گاهی
                    // آن‌جا. کار می‌کند تا روزی که نکند؛ همان «خرابیِ متناوب»ی که
                    // ردگیری‌اش برای پشتیبانی از همه سخت‌تر است.
                    return $out + ['state' => 'partial'];
                }

                // نیم‌سرور مالِ ما نیست ⇒ شاید DNS جای دیگری است ولی به ما اشاره
                // می‌دهد (Cloudflare و امثالش). آن هم یعنی سایت بالاست.
                $ip = $this->dns->ip($domain);

                if ($ip !== null && $serverIp !== null && $ip === $serverIp) {
                    return $out + ['state' => 'ok_external', 'ip' => $ip];
                }

                if ($ip === null) {
                    // نیم‌سرور خوانده شد ولی A نه — نیمه‌کاره، نه قطعاً خراب.
                    return $out + ['state' => 'pending'];
                }

                /*
                | 🔴 دامنهٔ پشتِ CDN را نمی‌شود از روی DNS قضاوت کرد.
                |
                | کلادفلر (و امثالش) IPِ **خودش** را در رکوردِ A می‌گذارد و
                | ترافیک را به مبدأ می‌بَرد. اگر مبدأ ما باشیم سایت کاملاً بالاست،
                | ولی DNS هیچ‌وقت این را نمی‌گوید. پس این‌جا صادقانه «نمی‌دانم»
                | می‌گوییم نه «خراب است».
                */
                if ($this->isProxied($found)) {
                    return $out + ['state' => 'proxied', 'ip' => $ip];
                }

                return $out + ['state' => 'mismatch', 'ip' => $ip];
            }
        );
    }
}
