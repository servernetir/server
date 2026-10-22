<?php

namespace App\Services\Dns;

use App\Models\CdnEvent;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\DnsOwnershipClaim;
use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * اثباتِ مالکیتِ دامنه — قرارداد §5.1/§5.2.
 *
 * ═══ 🔴 اصلاحِ تحلیلِ قبلی ═══
 * اشارهٔ NSِ دامنه به نیم‌سرورهای **مشترکِ** ما ثابت نمی‌کند کدام حساب مالک است.
 * پس مالکیت فقط با یکی از این دو اثبات می‌شود:
 *   • `registered` — دامنه نزدِ خودمان، به نامِ همین مشتری، فعال ثبت شده؛
 *   • `txt` — توکنِ تصادفی و زمان‌دارِ **همین حساب و همین دامنه** در DNSِ
 *     authoritativeِ فعلیِ دامنه (یافته از والد، پرس‌وجوی مستقیم بی‌کش).
 *
 * ادعا هیچ چیزی منتشر نمی‌کند و جلوی کسی را نمی‌گیرد؛ چند حساب هم‌زمان می‌توانند
 * ادعای در انتظار داشته باشند. zone فقط پس از اثبات ساخته (یا منتقل) می‌شود، پس
 * پاسخی که سامانه برای zoneِ تأییدنشده بسازد وجود ندارد که بتواند خودش را اثبات کند.
 */
class OwnershipService
{
    public const TXT_LABEL = '_servernet-challenge';
    public const TXT_PREFIX = 'servernet-domain-verification=';

    /** نتیجه‌های verify (کلیدِ dns.claim.<code>) */
    public const RESULTS = ['verified', 'txt_missing', 'txt_mismatch', 'lookup_failed', 'expired', 'not_pending', 'zone_limit', 'zone_deleting'];

    public function __construct(
        private readonly DelegationInspector $inspector,
        private readonly ZoneService $zones,
    ) {}

    /**
     * شروع: یا zoneِ همین مشتری، یا zoneِ تازه از راهِ `registered`، یا ادعای در انتظار.
     *
     * @return array{ok:bool, code:string, params:array, zone:?CdnZone, claim:?DnsOwnershipClaim}
     *         code: zone_exists|zone_created|claim_pending|<خطا>
     */
    public function start(Customer $customer, string $raw, ?string $ip = null): array
    {
        $domain = DomainName::normalize($raw);
        if ($domain === null) {
            return $this->out(false, 'domain_invalid');
        }

        $root = CdnZone::registrable($domain);
        if ($root === null) {
            return $this->out(false, 'domain_is_suffix');
        }
        if ($root !== $domain) {
            return $this->out(false, 'domain_not_registrable', ['root' => $root]);
        }
        if ($this->reserved($domain)) {
            return $this->out(false, 'domain_reserved');
        }

        // پیش از «zoneِ خودت»: zoneِ در حالِ حذفِ خودِ مشتری صفحه‌ای ندارد (۴۰۴)
        if ($this->zones->busy($domain)) {
            return $this->out(false, 'zone_deleting');
        }

        $mine = CdnZone::query()->where('domain', $domain)->where('customer_id', $customer->id)->first();
        if ($mine) {
            return $this->out(true, 'zone_exists', [], $mine);
        }

        // 🔴 «ثبت‌شده نزدِ ما» فقط zoneِ **تازه** می‌سازد. ردیفِ Domain هرگز دوباره سنجیده
        // نمی‌شود (دامنهٔ منتقل‌شده به رجیسترارِ دیگر تا انقضا `active` می‌مانَد)، پس
        // zoneِ زندهٔ حسابِ دیگر را فقط کنترلِ واقعیِ DNS (TXT) منتقل می‌کند.
        if ($this->registeredHere($customer, $domain) && ! CdnZone::query()->where('domain', $domain)->exists()) {
            $lock = Cache::lock('dns:domain:'.$domain, 30);
            if (! $lock->block(10)) {
                return $this->out(false, 'lookup_failed');
            }
            $refused = null;
            try {
                [$zone, $claim] = DB::transaction(function () use ($customer, $domain, $ip) {
                    $claim = $this->newClaim($customer, $domain);
                    $claim->forceFill(['status' => DnsOwnershipClaim::VERIFIED, 'verified_at' => now(), 'verified_via' => 'registered', 'last_result' => 'verified'])->save();

                    return [$this->zones->adoptVerified($customer, $domain, $claim, 'registered', $ip), $claim];
                });

                return $this->out(true, 'zone_created', [], $zone, $claim);
            } catch (AdoptRefused $e) {
                $refused = $e->reason;
            } finally {
                $lock->release();
            }
            // هم‌زمان کسِ دیگری zone ساخت: مثلِ هر مشتریِ دیگر ادعای TXT
            if ($refused !== 'needs_txt') {
                return $this->out(false, $refused);
            }
        }

        $pending = DnsOwnershipClaim::query()->pending()->forCustomer($customer->id)->where('domain', $domain)->latest('id')->first();
        if ($pending) {
            return $this->out(true, 'claim_pending', [], null, $pending);
        }

        $max = DnsConfig::limit('pending_claims_per_customer');
        if (DnsOwnershipClaim::query()->pending()->forCustomer($customer->id)->count() >= $max) {
            return $this->out(false, 'claim_limit', ['max' => $max]);
        }

        $claim = $this->newClaim($customer, $domain);
        CdnEvent::audit('claim_started', null, ['domain' => $domain, 'claim_id' => $claim->id], 'customer:'.$customer->id, $ip);

        return $this->out(true, 'claim_pending', [], null, $claim);
    }

    /** @return array{name:string, type:string, value:string} */
    public function challenge(DnsOwnershipClaim $claim): array
    {
        return [
            'name'  => self::TXT_LABEL.'.'.$claim->domain,
            'type'  => 'TXT',
            'value' => self::TXT_PREFIX.$claim->token,
        ];
    }

    /**
     * سنجشِ TXT در DNSِ authoritativeِ فعلیِ دامنه.
     *
     * 🔴 `lookup_failed` (timeout، SERVFAIL، پاسخِ رهگیرشده) هرگز «مالک نیست» نیست:
     * ادعا در انتظار می‌مانَد و پیام می‌گوید «نتوانستیم بپرسیم».
     *
     * @return array{ok:bool, code:string, params:array, zone:?CdnZone, claim:DnsOwnershipClaim}
     */
    public function verify(DnsOwnershipClaim $claim, ?string $ip = null): array
    {
        if (! $claim->isPending()) {
            return $this->out(false, $claim->isExpired() ? 'expired' : 'not_pending', [], null, $claim);
        }

        $c = $this->challenge($claim);
        $r = $this->inspector->authoritativeTxt($claim->domain, $c['name']);

        $claim->forceFill(['attempts' => $claim->attempts + 1, 'last_checked_at' => now()]);

        if (! $r['ok']) {
            $claim->forceFill(['last_result' => 'lookup_failed'])->save();

            return $this->out(false, 'lookup_failed', [], null, $claim);
        }

        $values = array_map('trim', $r['values']);
        if (! in_array($c['value'], $values, true)) {
            $code = $values === [] ? 'txt_missing' : 'txt_mismatch';
            $claim->forceFill(['last_result' => $code])->save();

            return $this->out(false, $code, [], null, $claim);
        }

        $customer = Customer::find($claim->customer_id);
        if ($customer === null) {
            return $this->out(false, 'not_pending', [], null, $claim);
        }

        // یک برنده برای هر دامنه، حتی اگر دو حساب هم‌زمان اثبات کنند
        $lock = Cache::lock('dns:domain:'.$claim->domain, 30);
        if (! $lock->block(10)) {
            return $this->out(false, 'lookup_failed', [], null, $claim);
        }

        $attempts = (int) $claim->attempts;
        try {
            $zone = DB::transaction(function () use ($claim, $customer, $ip) {
                $claim->forceFill(['status' => DnsOwnershipClaim::VERIFIED, 'verified_at' => now(), 'verified_via' => 'txt', 'last_result' => 'verified'])->save();

                return $this->zones->adoptVerified($customer, $claim->domain, $claim, 'txt', $ip);
            });
        } catch (AdoptRefused $e) {
            // تراکنش برگشت: ادعا «در انتظار» می‌مانَد و همان TXT بعداً هنوز کار می‌کند
            $claim->refresh()->forceFill(['attempts' => $attempts, 'last_checked_at' => now(), 'last_result' => $e->reason])->save();

            return $this->out(false, $e->reason, [], null, $claim);
        } finally {
            $lock->release();
        }

        return $this->out(true, 'verified', [], $zone, $claim);
    }

    public function cancel(DnsOwnershipClaim $claim): void
    {
        if ($claim->isPending()) {
            $claim->forceFill(['status' => DnsOwnershipClaim::CANCELLED])->save();
        }
    }

    /** @return int تعدادِ ادعاهای منقضی‌شده */
    public function expireStale(): int
    {
        return DnsOwnershipClaim::query()
            ->where('status', DnsOwnershipClaim::PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => DnsOwnershipClaim::EXPIRED, 'last_result' => 'expired', 'updated_at' => now()]);
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    private function newClaim(Customer $customer, string $domain): DnsOwnershipClaim
    {
        return DnsOwnershipClaim::create([
            'customer_id' => $customer->id,
            'domain'      => $domain,
            'token'       => DnsOwnershipClaim::freshToken(),
            'status'      => DnsOwnershipClaim::PENDING,
            'expires_at'  => now()->addDays(DnsConfig::limit('claim_ttl_days')),
        ]);
    }

    /** دامنه‌ای که همین مشتری نزدِ ما ثبت کرده و فعال است */
    private function registeredHere(Customer $customer, string $domain): bool
    {
        try {
            return Domain::query()->where('customer_id', $customer->id)->where('domain', $domain)->where('status', 'active')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** دامنه‌های زیرساختِ خودمان و دامنهٔ میزبانِ نیم‌سرورها */
    private function reserved(string $domain): bool
    {
        $own = ['servernet.cloud', 'servernet.ir'];
        foreach (DnsConfig::nameserverHosts() as $h) {
            if ($r = CdnZone::registrable($h)) {
                $own[] = $r;
            }
        }
        foreach (DnsConfig::reserved() as $h) {
            if ($r = CdnZone::registrable(rtrim($h, '.'))) {
                $own[] = $r;
            }
        }

        return in_array($domain, array_unique($own), true);
    }

    private function out(bool $ok, string $code, array $params = [], ?CdnZone $zone = null, ?DnsOwnershipClaim $claim = null): array
    {
        return compact('ok', 'code', 'params', 'zone', 'claim');
    }
}
