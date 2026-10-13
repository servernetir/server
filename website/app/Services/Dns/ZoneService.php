<?php

namespace App\Services\Dns;

use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\Domain;
use App\Services\NetworkTools;
use Illuminate\Support\Facades\DB;

/**
 * منطقِ کسب‌وکارِ DNSِ مدیریت‌شده — یک جا، برای پنلِ مشتری، پنلِ مدیریت و کرون.
 *
 * ═══ 🔴 مسئلهٔ اصلیِ امنیتی: «رزروِ zone» ═══
 *
 * نیم‌سرورهای ما بینِ همهٔ مشتریان مشترک‌اند. پس اگر کسی zoneِ `victim.com`
 * را زودتر از صاحبش بسازد، روزی که صاحبِ واقعی نیم‌سرورهایش را به ما بدهد،
 * **رکوردهای مهاجم** سرو می‌شوند: ایمیل، سایت و گواهیِ SSL همه دستِ او.
 * (همان آسیب‌پذیریِ شناخته‌شدهٔ ارائه‌دهندگانِ DNSِ مشترک.)
 *
 * سه قفل، با هم:
 *  1. zone فقط روی **دامنهٔ قابلِ ثبت** — نه زیردامنهٔ دامنهٔ کسِ دیگر.
 *  2. zoneِ «در انتظار» که مالکیتش **اثبات نشده**، قابلِ پس‌گرفتن است:
 *     هر کس رکوردِ TXTِ مخصوصِ خودش را روی DNSِ **فعلیِ** دامنه بگذارد
 *     (که فقط صاحبِ واقعی می‌تواند)، zone به او منتقل می‌شود.
 *  3. zoneِ در انتظار و اثبات‌نشده پس از `edge.pending_days` حذف می‌شود.
 *
 * دامنه‌ای که همین مشتری نزدِ ما ثبت کرده، از اول «اثبات‌شده» است.
 */
class ZoneService
{
    public function __construct(
        private readonly PowerDnsClient $pdns,
        private readonly RecordValidator $validator,
        private readonly NetworkTools $net,
    ) {}

    /* ───────────────────────── افزودنِ دامنه ───────────────────────── */

    /**
     * @return array{ok:bool, zone:?CdnZone, error:?string, claim:bool}
     *         claim=true یعنی دامنه در انتظارِ دیگری است و این مشتری می‌تواند با TXT پسش بگیرد
     */
    public function create(Customer $customer, string $raw): array
    {
        $domain = CdnZone::normalizeDomain($raw);

        if (function_exists('idn_to_ascii') && preg_match('~[^\x20-\x7e]~', $domain)) {
            $domain = (string) (idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain);
        }

        if (! preg_match('~^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+(xn--[a-z0-9-]{1,59}|[a-z]{2,63})$~', $domain)) {
            return $this->createFail('نامِ دامنه معتبر نیست. فقط نامِ دامنه را بنویسید، مثل example.com');
        }

        $root = CdnZone::registrable($domain);

        if ($root === null) {
            return $this->createFail('این یک پسوندِ دامنه است، نه دامنه. نامِ کاملِ دامنه را بنویسید.');
        }

        if ($root !== $domain) {
            // زیردامنه zoneِ جدا نمی‌گیرد؛ رکوردی است داخلِ zoneِ دامنهٔ اصلی
            return $this->createFail('فقط دامنهٔ اصلی ('.$root.') اضافه می‌شود. زیردامنه‌ها را بعداً به‌صورتِ رکورد داخلِ همان دامنه بسازید.');
        }

        $existing = CdnZone::where('domain', $domain)->first();

        if ($existing) {
            if ((int) $existing->customer_id === (int) $customer->id) {
                return $this->createFail('این دامنه از قبل در حسابِ شماست.') + ['zone' => $existing];
            }

            if ($existing->isPending() && ! $existing->ownershipProven()) {
                // پیام عمداً نمی‌گوید «مالِ کیست» — فقط راهِ اثبات را نشان می‌دهد
                return ['ok' => false, 'zone' => null, 'error' => null, 'claim' => true];
            }

            return $this->createFail('این دامنه در حسابِ دیگری فعال است. اگر مالکِ آن هستید، با پشتیبانی تماس بگیرید.');
        }

        $max = (int) config('edge.limits.zones_per_customer', 50);
        if (CdnZone::forCustomer($customer->id)->count() >= $max) {
            return $this->createFail('به سقفِ '.fa_num($max).' دامنه رسیده‌اید. برای افزایش با پشتیبانی تماس بگیرید.');
        }

        $zone = CdnZone::create([
            'customer_id'  => $customer->id,
            'domain'       => $domain,
            'mode'         => 'ns',
            'status'       => 'pending',
            'verify_token' => CdnZone::freshToken(),
            'ownership'    => $this->registeredHere($customer, $domain) ? 'registered' : null,
            'default_ttl'  => (int) config('edge.default_ttl', 3600),
            'waf_level'    => 'off',
            'waf_action'   => (string) config('edge.waf.default_action', 'block'),
            'cache_enabled' => false,
            'dirty'        => true,
        ]);

        CdnEvent::note('dns', $zone->id, 'دامنه افزوده شد', ['ownership' => $zone->ownership]);

        /*
         * zoneِ در انتظار **همین حالا** روی PowerDNS منتشر می‌شود، نه بعد از
         * تأیید. تفویض در لحظه‌ای رخ می‌دهد که مشتری در پنلِ رجیسترار «ذخیره»
         * می‌زند؛ اگر zone آن لحظه روی سرور نباشد، تا کرونِ بعدی سایت و ایمیلش
         * SERVFAIL می‌گیرند. قفل‌های امنیتی بالا همین انتشارِ زودهنگام را امن می‌کنند.
         */
        $this->sync($zone);

        return ['ok' => true, 'zone' => $zone, 'error' => null, 'claim' => false];
    }

    /** دامنه‌ای که همین مشتری نزدِ ما ثبت کرده = مالکیتِ اثبات‌شده */
    private function registeredHere(Customer $customer, string $domain): bool
    {
        try {
            return Domain::where('customer_id', $customer->id)
                ->where('domain', $domain)
                // فقط دامنه‌ای که واقعاً ثبت شده؛ سفارشِ پرداخت‌نشده یا ردشده مالکیت نیست
                ->where('status', 'active')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /* ───────────────────────── پس‌گرفتنِ zone با TXT ───────────────────────── */

    /**
     * رکوردی که **این مشتری** باید روی DNSِ فعلیِ دامنه بگذارد.
     *
     * ⚠️ مقدار از کلیدِ برنامه و شناسهٔ مشتری مشتق می‌شود، نه ذخیره: مهاجمِ
     * «رزروکننده» نمی‌تواند مقدارِ مشتریِ دیگری را حدس بزند، و هیچ ردیفی هم
     * برای ادعاهای نیمه‌کاره در دیتابیس جمع نمی‌شود.
     *
     * @return array{name:string, type:string, content:string}
     */
    public function claimRecord(Customer $customer, string $domain): array
    {
        $domain = CdnZone::normalizeDomain($domain);
        $mac = substr(hash_hmac('sha256', 'dns-claim|'.$customer->id.'|'.$domain, (string) config('app.key')), 0, 32);

        return [
            'name'    => '_servernet-claim.'.$domain,
            'type'    => 'TXT',
            'content' => 'servernet-claim='.$mac,
        ];
    }

    /** @return array{ok:bool, zone:?CdnZone, error:?string} */
    public function claim(Customer $customer, string $raw): array
    {
        $domain = CdnZone::normalizeDomain($raw);
        $existing = CdnZone::where('domain', $domain)->first();

        if (! $existing || (int) $existing->customer_id === (int) $customer->id) {
            return ['ok' => false, 'zone' => null, 'error' => 'نیازی به اثباتِ مالکیت نیست؛ دامنه را مستقیم اضافه کنید.'];
        }

        if (! $existing->isPending() || $existing->ownershipProven()) {
            return ['ok' => false, 'zone' => null, 'error' => 'این دامنه در حسابِ دیگری فعال است. برای بررسی با پشتیبانی تماس بگیرید.'];
        }

        $rec = $this->claimRecord($customer, $domain);
        $res = $this->net->dns($rec['name'], 'TXT');

        if (($res['ok'] ?? false) !== true) {
            return ['ok' => false, 'zone' => null, 'error' => 'استعلامِ DNS در این لحظه پاسخ نداد. چند دقیقهٔ دیگر دوباره امتحان کنید.'];
        }

        $found = array_map(fn ($r) => trim((string) ($r['data'] ?? ''), '" '), $res['records'] ?? []);

        if (! in_array($rec['content'], $found, true)) {
            return ['ok' => false, 'zone' => null, 'error' => $found === []
                ? 'رکوردِ TXT هنوز دیده نمی‌شود. اگر تازه ساخته‌اید، چند دقیقه صبر کنید.'
                : 'رکوردِ TXT هست ولی مقدارش با کدِ حسابِ شما نمی‌خوانَد.'];
        }

        $zone = DB::transaction(function () use ($existing, $customer) {
            $old = $existing->customer_id;
            $existing->records()->delete();

            /*
             * ⚠️ همان ردیف منتقل می‌شود، نه حذف و ساختِ دوباره: ایندکسِ یکتای
             * `domain` بینِ حذف و ساخت پنجره‌ای باز می‌کرد که ادعای سوم وارد شود.
             */
            $existing->forceFill([
                'customer_id'  => $customer->id,
                'ownership'    => 'txt',
                'verify_token' => CdnZone::freshToken(),
                'verify_error' => null,
                'dirty'        => true,
            ])->save();

            CdnEvent::note('dns', $existing->id, 'مالکیت با TXT اثبات و zone منتقل شد', ['from_customer' => $old]);

            return $existing;
        });

        $this->sync($zone);

        return ['ok' => true, 'zone' => $zone, 'error' => null];
    }

    /* ───────────────────────── تأییدِ تفویض ───────────────────────── */

    /** @return array{ok:bool, reason:?string, found:array<int,string>} */
    public function verify(CdnZone $zone): array
    {
        $res = app(DelegationCheck::class)->check($zone);

        $zone->forceFill(['checked_at' => now()]);

        if ($res['ok']) {
            $wasPending = $zone->isPending();

            $zone->forceFill([
                'verified_at'  => $zone->verified_at ?? now(),
                'verify_error' => null,
                // تعلیقِ مدیر با تأییدِ دوباره برداشته نمی‌شود
                'status'       => $zone->status === 'suspended' ? 'suspended' : 'active',
                'ownership'    => $zone->ownership ?? 'delegated',
            ])->save();

            if ($wasPending) {
                CdnEvent::note('dns', $zone->id, 'تفویض تأیید شد — دامنه فعال است');
            }

            return $res;
        }

        // 🔴 شکستِ استعلام «تفویض ندارد» نیست؛ خطای قبلی دست نمی‌خورد
        if ($res['reason'] !== 'lookup_failed') {
            $zone->forceFill(['verify_error' => $res['reason']]);

            /*
             * zoneِ فعالی که تفویضش را از دست داده (مشتری نیم‌سرور را برگردانده)
             * فعال می‌ماند — پاک‌کردنش یعنی اگر اشتباهی بوده، رکوردهایش رفته‌اند.
             * فقط `verify_error` نشان می‌دهد که دیگر از ما سرو نمی‌شود.
             */
        }

        $zone->save();

        return $res;
    }

    /* ───────────────────────── رکوردها ───────────────────────── */

    /** @return array{ok:bool, errors:array<string,string>, record:?CdnRecord, sync:?array} */
    public function addRecord(CdnZone $zone, array $input): array
    {
        if ($zone->records()->count() >= (int) config('edge.limits.records_per_zone', 250)) {
            return $this->recFail(['record' => 'به سقفِ '.fa_num(config('edge.limits.records_per_zone', 250)).' رکورد رسیده‌اید.']);
        }

        $v = $this->validator->validate($zone->domain, $input);
        if (! $v['ok']) {
            return $this->recFail($v['errors']);
        }

        if ($c = $this->validator->conflict($v['data'], $zone->records()->get())) {
            return $this->recFail(['name' => $c]);
        }

        $record = $zone->records()->create($v['data']);
        CdnEvent::note('dns', $zone->id, 'رکورد افزوده شد: '.$record->type.' '.$record->name);

        return ['ok' => true, 'errors' => [], 'record' => $record, 'sync' => $this->touch($zone)];
    }

    /** @return array{ok:bool, errors:array<string,string>, record:?CdnRecord, sync:?array} */
    public function updateRecord(CdnZone $zone, CdnRecord $record, array $input): array
    {
        // نوعِ رکورد از دیتابیس می‌آید، نه از فرم: `type` در ورودی نباید بتواند
        // اعتبارسنجیِ نوعِ دیگری را روی این رکورد اجرا کند
        $v = $this->validator->validate($zone->domain, ['type' => $record->type] + $input);
        if (! $v['ok']) {
            return $this->recFail($v['errors']);
        }

        // نوع عوض نمی‌شود: A→CNAME در واقع «حذف و ساخت» است و قواعدِ هم‌زیستیِ دیگری دارد
        $v['data']['type'] = $record->type;

        $others = $zone->records()->whereKeyNot($record->id)->get();
        if ($c = $this->validator->conflict($v['data'], $others)) {
            return $this->recFail(['name' => $c]);
        }

        $record->fill($v['data'])->save();
        CdnEvent::note('dns', $zone->id, 'رکورد ویرایش شد: '.$record->type.' '.$record->name);

        return ['ok' => true, 'errors' => [], 'record' => $record, 'sync' => $this->touch($zone)];
    }

    /** @return array{ok:bool, sync:?array} */
    public function deleteRecord(CdnZone $zone, CdnRecord $record): array
    {
        $label = $record->type.' '.$record->name;
        $record->delete();
        CdnEvent::note('dns', $zone->id, 'رکورد حذف شد: '.$label);

        return ['ok' => true, 'sync' => $this->touch($zone)];
    }

    /** @return array{ok:bool, sync:?array} */
    public function toggleRecord(CdnZone $zone, CdnRecord $record): array
    {
        $record->forceFill(['disabled' => ! $record->disabled])->save();

        return ['ok' => true, 'sync' => $this->touch($zone)];
    }

    /**
     * واردکردنِ ردیف‌هایی که کاربر در پیش‌نمایش دیده و تیک زده.
     *
     * ⚠️ هر ردیف از همان اعتبارسنجِ فرم رد می‌شود. واردکننده‌ای که مستقیم
     * می‌نوشت، یک TXTِ فارسی یا MXِ با IP را وارد می‌کرد و PowerDNS **کلِ**
     * PATCH را رد می‌کرد — یعنی zone تا ابد همگام نمی‌شد.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{added:int, skipped:array<int,string>, sync:?array}
     */
    public function import(CdnZone $zone, array $rows): array
    {
        $added = 0;
        $skipped = [];
        $limit = (int) config('edge.limits.records_per_zone', 250);

        foreach ($rows as $row) {
            if (! is_array($row) || $zone->records()->count() >= $limit) {
                continue;
            }

            $type = strtoupper((string) ($row['type'] ?? ''));
            $input = $row;

            // CAA از استعلام به شکلِ «0 issue "letsencrypt.org"» می‌آید
            if ($type === 'CAA' && preg_match('~^(\d+)\s+(\w+)\s+"?([^"]*)"?$~', (string) ($row['content'] ?? ''), $m)) {
                $input += ['flags' => $m[1], 'tag' => $m[2], 'value' => $m[3]];
            }

            $v = $this->validator->validate($zone->domain, $input);

            if (! $v['ok'] || $this->validator->conflict($v['data'], $zone->records()->get())) {
                $skipped[] = $type.' '.($row['name'] ?? '@');

                continue;
            }

            $zone->records()->create($v['data']);
            $added++;
        }

        CdnEvent::note('dns', $zone->id, 'ورودِ رکورد', ['added' => $added, 'skipped' => count($skipped)]);

        return ['added' => $added, 'skipped' => $skipped, 'sync' => $added > 0 ? $this->touch($zone) : null];
    }

    /* ───────────────────────── همگام‌سازی و حذف ───────────────────────── */

    private function touch(CdnZone $zone): array
    {
        $zone->markDirty();

        return $this->sync($zone);
    }

    /**
     * آینه‌کردنِ zone روی PowerDNS و ثبتِ نتیجه روی خودِ zone.
     *
     * zoneِ معلق از سرورِ DNS **برداشته** می‌شود (تعلیق باید اثر داشته باشد)
     * ولی ردیف‌ها می‌مانند، پس رفعِ تعلیق یک همگام‌سازیِ ساده است.
     *
     * @return array{ok:bool, reason:?string}
     */
    public function sync(CdnZone $zone): array
    {
        $res = in_array($zone->status, ['pending', 'active'], true)
            ? $this->pdns->syncZone($zone)
            : $this->pdns->deleteZone($zone->domain);

        $zone->forceFill($res['ok']
            ? ['synced_at' => now(), 'sync_error' => null, 'dirty' => false]
            : ['sync_error' => mb_substr((string) $res['reason'], 0, 250), 'dirty' => true]
        )->save();

        if (! $res['ok']) {
            CdnEvent::note('dns', $zone->id, 'همگام‌سازی با سرورِ DNS ناموفق', ['reason' => $res['reason']]);
        }

        return ['ok' => $res['ok'], 'reason' => $res['reason']];
    }

    /**
     * حذفِ کاملِ zone.
     *
     * 🔴 ترتیب: اول سرورِ DNS، بعد دیتابیس. اگر برعکس باشد و حذف از PowerDNS
     * شکست بخورد، zone روی نیم‌سرورها زنده می‌مانَد بی‌آنکه ردیفی در پنل
     * داشته باشد — یتیمی که هیچ‌کس نمی‌بیند و هنوز جواب می‌دهد.
     *
     * @return array{ok:bool, reason:?string}
     */
    public function remove(CdnZone $zone, string $why = 'حذف توسطِ مشتری'): array
    {
        if ($this->pdns->isConfigured()) {
            $res = $this->pdns->deleteZone($zone->domain);

            if (! $res['ok']) {
                return ['ok' => false, 'reason' => 'حذف از سرورِ DNS ناموفق بود: '.$res['reason']];
            }
        }

        DB::transaction(function () use ($zone, $why) {
            CdnEvent::note('dns', null, $why.': '.$zone->domain, ['zone_id' => $zone->id, 'customer_id' => $zone->customer_id]);
            $zone->records()->delete();
            $zone->delete();
        });

        return ['ok' => true, 'reason' => null];
    }

    /**
     * zoneهای در انتظار و اثبات‌نشدهٔ قدیمی — قفلِ سومِ «رزروِ zone».
     *
     * @return int تعدادِ حذف‌شده
     */
    public function expirePending(): int
    {
        $cut = now()->subDays((int) config('edge.pending_days', 14));
        $n = 0;

        CdnZone::where('status', 'pending')
            ->whereNull('ownership')
            ->where('created_at', '<', $cut)
            ->orderBy('id')
            ->each(function (CdnZone $zone) use (&$n) {
                if ($this->remove($zone, 'انقضای zoneِ تفویض‌نشده')['ok']) {
                    $n++;
                }
            });

        return $n;
    }

    /* ───────────────────────── خروجی ───────────────────────── */

    /** فایلِ zone به قالبِ BIND — برای بکاپِ مشتری و بردن به هر ارائه‌دهندهٔ دیگری */
    public function exportBind(CdnZone $zone): string
    {
        $origin = $zone->domain.'.';
        $out = [
            '; zone file for '.$zone->domain,
            '; exported from ServerNet DNS at '.now()->toIso8601String(),
            '$ORIGIN '.$origin,
            '$TTL '.(int) $zone->default_ttl,
            '',
        ];

        foreach ($zone->nameservers() as $ns) {
            $out[] = sprintf("%-32s %-6d IN  NS     %s", '@', 86400, rtrim($ns, '.').'.');
        }

        $records = $zone->records()->orderByRaw("CASE type WHEN 'A' THEN 1 WHEN 'AAAA' THEN 2 WHEN 'CNAME' THEN 3 WHEN 'MX' THEN 4 WHEN 'TXT' THEN 5 ELSE 9 END")
            ->orderBy('name')->get();

        foreach ($records as $r) {
            $name = $r->shortName($zone->domain);
            $value = match ($r->type) {
                'MX'          => ((int) $r->prio).' '.rtrim($r->content, '.').'.',
                'CNAME', 'NS' => rtrim($r->content, '.').'.',
                'SRV'         => $this->srvBind($r),
                'TXT'         => $this->pdns->quoteTxt($r->content),
                default       => $r->content,
            };

            $out[] = ($r->disabled ? '; (disabled) ' : '')
                .sprintf("%-32s %-6d IN  %-6s %s", $name, (int) $r->ttl, $r->type, $value);
        }

        return implode("\n", $out)."\n";
    }

    private function srvBind(CdnRecord $r): string
    {
        $parts = preg_split('~\s+~', trim($r->content)) ?: [];
        $target = end($parts) ?: '.';
        $parts[count($parts) - 1] = $target === '.' ? '.' : rtrim($target, '.').'.';

        return ((int) $r->prio).' '.implode(' ', $parts);
    }

    /* ───────────────────────── کمکی ───────────────────────── */

    private function createFail(string $error): array
    {
        return ['ok' => false, 'zone' => null, 'error' => $error, 'claim' => false];
    }

    private function recFail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'record' => null, 'sync' => null];
    }
}
