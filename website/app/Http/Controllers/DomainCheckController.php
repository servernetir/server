<?php

namespace App\Http\Controllers;

use App\Services\Whmcs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * استعلام آنی دامنه.
 *
 * منبع اصلی: WHMCS API (اکشن DomainWhois + GetTLDPricing) — وقتی
 * WHMCS_FA_API_* در .env تنظیم شده باشد. در نبود API، بررسی DNS
 * به‌عنوان جایگزین موقت استفاده می‌شود (قیمت‌ها از config).
 */
class DomainCheckController extends Controller
{
    private const MAX_SUGGESTIONS = 3;

    private Whmcs $whmcs;

    private ?array $pricing;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['domain' => 'required|string|max:100']);

        $this->whmcs = Whmcs::forLocale();
        $this->pricing = $this->whmcs->tldPricing();

        $query = strtolower(trim($data['domain']));
        $query = preg_replace('~^https?://~', '', $query);
        $query = preg_replace('~^www\.~', '', $query);
        $query = trim($query, "./ \t");

        $tld = null;
        $sld = $query;
        if (str_contains($query, '.')) {
            [$sld, $rest] = explode('.', $query, 2);
            $tld = '.'.$rest;
        }
        $sld = preg_replace('/[^a-z0-9\x{0600}-\x{06FF}-]/u', '', $sld);

        if ($sld === '' || $sld === null) {
            return response()->json(['message' => 'invalid'], 422);
        }

        $primaryTld = $tld ?? '.com';
        $primaryDomain = $sld.$primaryTld;

        // ۱) استعلام قطعی دامنه‌ی اصلی از Whois خود WHMCS (با کش ۵ دقیقه)
        $result = [
            'domain'    => $primaryDomain,
            'available' => $this->whmcs->domainAvailableCached($primaryDomain) ?? $this->isAvailableFast($primaryDomain),
            'price'     => $this->price($primaryTld),
            'cart_url'  => whmcs_url('cart.php?a=add&domain=register&query='.urlencode($primaryDomain)),
        ];

        // ۲) نامزدهای پیشنهاد: فیلتر اولیه‌ی سریع با DNS (فقط برای کم‌کردن تعداد استعلام‌ها)
        $candidates = [];
        $scanned = 0;
        if (! $result['available']) {
            foreach ($this->knownTlds() as $t) {
                if ($t === $primaryTld) {
                    continue;
                }
                if (count($candidates) >= 4 || $scanned >= 8) {
                    break;
                }
                $scanned++;
                if ($this->isAvailableFast($sld.$t)) {
                    $candidates[$t] = $sld.$t;
                }
            }
        }

        // ۳) تأیید قطعی نامزدها از Whois خود WHMCS — هیچ پیشنهادی بدون تأیید WHMCS
        //    نمایش داده نمی‌شود (دقتِ همان صفحه‌ی خرید). استعلام‌ها کش ۵ دقیقه دارند.
        $suggestions = [];
        if (! $result['available']) {
            $whmcsEnabled = $this->whmcs->enabled();
            foreach ($candidates as $t => $domain) {
                if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                    break;
                }
                $confirmed = $whmcsEnabled
                    ? $this->whmcs->domainAvailableCached($domain) === true
                    : true; // بدون WHMCS همان نتیجه‌ی DNS (بهترین تلاش)
                if ($confirmed) {
                    $suggestions[] = [
                        'domain'    => $domain,
                        'available' => true,
                        'price'     => $this->price($t),
                        'cart_url'  => whmcs_url('cart.php?a=add&domain=register&query='.urlencode($domain)),
                    ];
                }
            }
        }

        return response()->json([
            'result'      => $result,
            'suggestions' => $suggestions,
            'more_url'    => whmcs_url('domainchecker.php'),
        ]);
    }

    /** لیست پسوندها برای پیشنهاد — منتخب‌هایی که در WHMCS قیمت دارند */
    private function knownTlds(): array
    {
        $featured = config('servernet.featured_tlds', []);

        if ($this->pricing !== null) {
            $known = array_filter($featured, fn (string $t) => isset($this->pricing['prices'][$t]));

            return $known !== [] ? array_values($known) : array_slice(array_keys($this->pricing['prices']), 0, 10);
        }

        return array_column(config('servernet.tlds'), 'tld');
    }

    /** قیمت ثبت سالانه‌ی پسوند — اول WHMCS، بعد config */
    private function price(string $tld): ?string
    {
        if ($this->pricing !== null && isset($this->pricing['prices'][$tld])) {
            return whmcs_price($this->pricing['prices'][$tld], $this->pricing['currency']);
        }

        $info = collect(config('servernet.tlds'))->firstWhere('tld', $tld);

        return $info ? site_price($info) : null;
    }

    /** بررسی سریع بدون WHMCS — برای فیلتر اولیه نامزدها و fallback */
    private function isAvailableFast(string $domain): bool
    {
        $ascii = function_exists('idn_to_ascii') ? (idn_to_ascii($domain) ?: $domain) : $domain;

        // ۱) کوئری خام UDP DNS (در همه‌ی محیط‌ها کار می‌کند)
        $rcode = $this->rawDnsRcode($ascii);
        if ($rcode !== null) {
            return $rcode === 3; // NXDOMAIN → ثبت نشده
        }

        // ۲) DNS-over-HTTPS
        $status = $this->dohStatus($ascii);
        if ($status !== null) {
            return $status === 3;
        }

        // ۳) توابع DNS خود PHP
        return ! checkdnsrr($ascii.'.', 'NS');
    }

    /**
     * کوئری مستقیم UDP به ریزالورهای عمومی و خواندن RCODE پاسخ.
     * 0 = موجود، 3 = NXDOMAIN (آزاد برای ثبت)، null = عدم دسترسی.
     */
    private function rawDnsRcode(string $domain): ?int
    {
        foreach (['1.1.1.1', '8.8.8.8'] as $server) {
            $query = pack('n6', random_int(0, 0xffff), 0x0100, 1, 0, 0, 0);
            foreach (explode('.', $domain) as $label) {
                if ($label === '' || strlen($label) > 63) {
                    return null;
                }
                $query .= chr(strlen($label)).$label;
            }
            $query .= "\0".pack('n2', 2, 1); // NS / IN

            $socket = @fsockopen('udp://'.$server, 53, $errno, $error, 2);
            if (! $socket) {
                continue;
            }
            stream_set_timeout($socket, 2);
            fwrite($socket, $query);
            $response = fread($socket, 512);
            fclose($socket);

            if (is_string($response) && strlen($response) >= 12) {
                $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($response, 0, 12));

                return $header['flags'] & 0x0F;
            }
        }

        return null;
    }

    /** استعلام NS از DNS-over-HTTPS */
    private function dohStatus(string $domain): ?int
    {
        $endpoints = [
            'https://cloudflare-dns.com/dns-query',
            'https://dns.google/resolve',
        ];

        foreach ($endpoints as $endpoint) {
            $url = $endpoint.'?name='.urlencode($domain).'&type=NS';
            $ctx = stream_context_create(['http' => [
                'timeout' => 4,
                'header'  => "Accept: application/dns-json\r\n",
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
            $json = $raw ? json_decode($raw, true) : null;
            if (is_array($json) && isset($json['Status'])) {
                return (int) $json['Status'];
            }
        }

        return null;
    }
}
