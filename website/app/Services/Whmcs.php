<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * کلاینت API خود WHMCS (includes/api.php).
 *
 * تا وقتی WHMCS_FA_API_IDENTIFIER/SECRET در .env تنظیم نشده، enabled()=false
 * برمی‌گردد و سایت از داده‌های نمونه‌ی config استفاده می‌کند؛ به‌محض تنظیم،
 * قیمت TLDها و استعلام دامنه به‌صورت زنده از WHMCS خوانده می‌شود.
 */
class Whmcs
{
    /** هندل curl بازمصرف‌شونده — keep-alive، بدون اتصال‌های موازی که سرور drop می‌کند */
    private ?\CurlHandle $handle = null;

    public function __construct(
        private string $url,
        private ?string $identifier,
        private ?string $secret,
        private ?int $currencyId = null,
    ) {
    }

    public static function forLocale(?string $locale = null): self
    {
        $locale ??= app()->getLocale();
        // زبان‌هایی که نصب WHMCS اختصاصی ندارند (مثل tr) → نصب بین‌المللی (en)
        $cfg = config('servernet.whmcs_api.'.$locale)
            ?? config('servernet.whmcs_api.'.($locale === 'fa' ? 'fa' : 'en'));

        return new self(
            $cfg['url'],
            $cfg['identifier'] ?: null,
            $cfg['secret'] ?: null,
            $cfg['currency'] ? (int) $cfg['currency'] : null,
        );
    }

    public function enabled(): bool
    {
        return $this->identifier !== null && $this->secret !== null;
    }

    /** فراخوانی خام API — در خطا null برمی‌گرداند تا fallback فعال شود */
    public function call(string $action, array $params = [], int $timeout = 10): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $this->handle ??= curl_init();
        $ch = $this->handle;
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(array_merge([
                'action'       => $action,
                'identifier'   => $this->identifier,
                'secret'       => $this->secret,
                'responsetype' => 'json',
            ], $params)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);

        if ($raw === false) {
            Log::warning("WHMCS API {$action}: {$err}");

            return null;
        }

        $json = json_decode($raw, true);
        if (! is_array($json) || ($json['result'] ?? '') !== 'success') {
            Log::warning("WHMCS API {$action} failed", [
                'response' => is_array($json) ? ($json['message'] ?? 'unknown') : substr((string) $raw, 0, 200),
            ]);

            return null;
        }

        return $json;
    }

    /**
     * قیمت ثبت یک‌ساله‌ی همه‌ی پسوندها + ارز WHMCS.
     * خروجی: ['prices' => ['.com' => 2175000.0, ...], 'currency' => ['code','prefix','suffix']]
     * ۱۰ دقیقه کش می‌شود؛ پس تغییر قیمت در WHMCS حداکثر ۱۰ دقیقه بعد روی سایت است.
     */
    public function tldPricing(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = 'whmcs.tld_pricing.'.md5($this->url.'|'.$this->currencyId);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $params = $this->currencyId ? ['currencyid' => $this->currencyId] : [];
        $resp = $this->call('GetTLDPricing', $params);
        if ($resp === null) {
            return null; // خطا کش نمی‌شود تا دفعه بعد دوباره تلاش شود
        }

        $prices = [];
        foreach (($resp['pricing'] ?? []) as $tld => $info) {
            $price = $info['register'][1] ?? $info['register']['1'] ?? null;
            if ($price === null || (float) $price < 0) {
                continue;
            }
            $prices['.'.ltrim($tld, '.')] = (float) $price;
        }

        if ($prices === []) {
            return null;
        }

        $out = [
            'prices'   => $prices,
            'currency' => [
                'code'   => $resp['currency']['code'] ?? '',
                'prefix' => $resp['currency']['prefix'] ?? '',
                'suffix' => $resp['currency']['suffix'] ?? '',
            ],
        ];

        Cache::put($key, $out, 600);

        return $out;
    }

    /** استعلام Whois از WHMCS — true=آزاد، false=ثبت‌شده، null=نامشخص/خطا */
    public function domainAvailable(string $domain): ?bool
    {
        $resp = $this->call('DomainWhois', ['domain' => $domain]);

        return match ($resp['status'] ?? null) {
            'available'   => true,
            'unavailable' => false,
            default       => null,
        };
    }

    /**
     * همان استعلام Whois با کش ۵ دقیقه‌ای — جستجوهای تکراری آنی می‌شوند
     * و فشار روی WHMCS کم می‌ماند. نتیجه‌ی نامشخص (null) کش نمی‌شود.
     */
    public function domainAvailableCached(string $domain): ?bool
    {
        $key = 'whmcs.whois.'.md5($this->url.'|'.$domain);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'yes';
        }

        $result = $this->domainAvailable($domain);
        if ($result !== null) {
            Cache::put($key, $result ? 'yes' : 'no', 300);
        }

        return $result;
    }

}
