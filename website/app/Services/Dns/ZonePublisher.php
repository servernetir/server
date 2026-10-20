<?php

namespace App\Services\Dns;

use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\DnsZoneNode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * انتشارِ وضعیتِ مطلوبِ یک zone روی primaryِ PowerDNS — قرارداد §4.
 *
 * ═══ سه قاعدهٔ اصلی ═══
 *
 * 1. **دیتابیسِ برنامه منبعِ حقیقت است.** هر اجرا کلِ وضعیتِ مطلوب را می‌سازد و
 *    با وضعیتِ زنده diff می‌گیرد (REPLACE تغییرکرده، DELETE نبوده). رکوردِ حذف‌شده
 *    هرگز «زنده نمی‌ماند» و ترتیبِ اجراها اهمیتی ندارد.
 * 2. **نسخهٔ کهنه هرگز «تازه‌ترین» علامت نمی‌خورد.** هر اجرا `desired_version`ِ
 *    لحظهٔ snapshot (V) را دارد و به‌روزرسانیِ پایانی شرطی است
 *    (`applied_version < V`)؛ تغییرِ هم‌زمان V را بالا می‌برد و zone «در انتظار» می‌مانَد.
 * 3. **موفقیتِ API ≠ انتشار.** این‌جا فقط primary سنجیده می‌شود؛ «منتشر شد» برای
 *    مشتری وقتی است که NodeVerifier serialِ همین انتشار را روی هر دو نیم‌سرور ببیند.
 *
 * پروژه worker صف ندارد: پس از commit یک تلاشِ فوری، و `dns:publish` هر دقیقه
 * بقیه را با backoff برمی‌دارد.
 */
class ZonePublisher
{
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly PowerDnsClient $pdns,
        private readonly NodeVerifier $nodes,
    ) {}

    /** پس از commitِ تراکنشِ جاری یک تلاشِ فوری (هرگز استثنا نمی‌دهد) */
    public function requestPublish(CdnZone $zone): void
    {
        $id = $zone->getKey();
        $run = function () use ($id) {
            try {
                if ($z = CdnZone::find($id)) {
                    $this->publish($z);
                }
            } catch (\Throwable) {
                // کرونِ dns:publish دوباره برمی‌دارد؛ درخواستِ کاربر نباید بشکند
            }
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($run) : $run();
    }

    public function publish(CdnZone $zone): PublishResult
    {
        if (! $this->pdns->isConfigured()) {
            return PublishResult::fail('not_configured');
        }

        $lock = Cache::lock('dns:publish:'.$zone->getKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return PublishResult::fail('locked');
        }

        try {
            $zone = $zone->fresh();
            if ($zone === null) {
                return PublishResult::fail('skipped');
            }

            return match (true) {
                $zone->status === 'deleting'  => $this->deleteRemote($zone, final: true),
                $zone->status === 'suspended' => $this->deleteRemote($zone, final: false),
                // ردیفِ v1 بی‌اثباتِ v2: هرگز روی نیم‌سرورهای ما نمی‌رود (ضدِ تصاحب)
                ! $zone->ownershipProven()    => PublishResult::fail('unproven'),
                default                       => $this->publishNow($zone),
            };
        } catch (\Throwable $e) {
            return $this->failed($zone, 'internal: '.mb_substr($e->getMessage(), 0, 180));
        } finally {
            $lock->release();
        }
    }

    /* ───────────────────────── انتشار ───────────────────────── */

    private function publishNow(CdnZone $zone): PublishResult
    {
        // snapshot: نسخه و رکوردها در یک خواندنِ سازگار
        [$version, $records] = DB::transaction(function () use ($zone) {
            $v = (int) CdnZone::query()->whereKey($zone->getKey())->value('desired_version');
            $r = CdnRecord::query()->where('cdn_zone_id', $zone->getKey())->where('disabled', false)->orderBy('id')->get();

            return [$v, $r];
        });

        $live = $this->pdns->getZone($zone->domain);
        $created = false;

        if (! $live['ok'] && $live['status'] === 404) {
            $make = $this->pdns->createZone($zone->domain, DnsConfig::nameserverHosts(), DnsConfig::soa(), DnsConfig::catalog());
            if (! $make['ok']) {
                return $this->failed($zone, 'create: '.$make['error']);
            }
            $created = true;
            $live = $this->pdns->getZone($zone->domain);
        }
        if (! $live['ok']) {
            // «نمی‌دانم» هرگز «نیست» خوانده نمی‌شود — وگرنه zoneِ زنده را از نو می‌سازیم
            return $this->failed($zone, 'read: '.$live['error']);
        }

        // عضویتِ catalog و کلیدِ انتقال: بدونِ این دو، zone هرگز به نیم‌سرورها نمی‌رسد
        $repair = $this->ensureTransferable($zone->domain, $live['data']);
        if ($repair !== null) {
            return $this->failed($zone, $repair);
        }

        $patch = $this->diff($this->desired($zone->domain, $records), (array) ($live['data']['rrsets'] ?? []));

        if ($patch !== []) {
            $res = $this->pdns->patchRrsets($zone->domain, $patch);
            if (! $res['ok']) {
                return $this->failed($zone, 'patch: '.$res['error']);
            }
        }

        $after = $this->pdns->getZone($zone->domain);
        $serial = $after['ok'] ? $this->serialOf($after['data']) : null;
        if ($serial === null) {
            return $this->failed($zone, 'serial: '.($after['error'] ?? 'missing'));
        }

        if ($patch !== [] || $created) {
            $this->pdns->notify($zone->domain);   // بهترین تلاش؛ catalog و refresh هم می‌رسانند
        }

        $affected = CdnZone::query()
            ->whereKey($zone->getKey())
            ->where('applied_version', '<', $version)
            ->update([
                'applied_version'    => $version,
                'publish_state'      => DB::raw("CASE WHEN desired_version = {$version} AND status <> 'deleting' THEN 'published' ELSE publish_state END"),
                'publish_attempts'   => 0,
                'next_publish_at'    => null,
                'last_publish_error' => null,
                'primary_serial'     => $serial,
                'published_at'       => now(),
            ]);

        if ($affected === 0) {
            // اجرای دیگری نسخهٔ برابر یا تازه‌تر را ثبت کرده؛ فقط serial را تازه کن
            CdnZone::query()->whereKey($zone->getKey())->where('applied_version', '=', $version)
                ->update(['primary_serial' => $serial, 'publish_state' => DB::raw("CASE WHEN desired_version = {$version} AND status <> 'deleting' THEN 'published' ELSE publish_state END")]);
        }

        if ($patch !== []) {
            CdnEvent::audit('published', $zone, ['version' => $version, 'changes' => count($patch), 'serial' => $serial], 'system');
        }

        return new PublishResult(true, $patch === [] && ! $created ? 'unchanged' : 'published', $version, $serial, null, count($patch));
    }

    private function ensureTransferable(string $domain, array $zoneData): ?string
    {
        $catalog = $this->pdns->fqdn(DnsConfig::catalog());
        if (strtolower((string) ($zoneData['catalog'] ?? '')) !== $catalog) {
            $r = $this->pdns->updateZone($domain, ['catalog' => $catalog]);
            if (! $r['ok']) {
                return 'catalog: '.$r['error'];
            }
        }

        $meta = $this->pdns->getMetadata($domain, 'TSIG-ALLOW-AXFR');
        if (! $meta['ok'] && $meta['status'] !== 404) {
            return 'metadata: '.$meta['error'];
        }
        $want = DnsConfig::tsigKey();
        $have = array_map(fn ($v) => rtrim(strtolower((string) $v), '.'), $meta['values'] ?? []);
        if (! in_array(strtolower($want), $have, true)) {
            $r = $this->pdns->setMetadata($domain, 'TSIG-ALLOW-AXFR', [$want]);
            if (! $r['ok']) {
                return 'metadata: '.$r['error'];
            }
        }

        return null;
    }

    /**
     * وضعیتِ مطلوب به شکلِ rrsetهای PowerDNS (بی‌changetype).
     *
     * @param  iterable<CdnRecord>  $records
     * @return array<string,array{name:string,type:string,ttl:int,records:list<array{content:string,disabled:bool}>}>
     */
    public function desired(string $domain, iterable $records): array
    {
        $apex = $this->pdns->fqdn($domain);
        $sets = [$apex.'|NS' => [
            'name' => $apex, 'type' => 'NS', 'ttl' => 86400,
            'records' => array_map(fn ($ns) => ['content' => $this->pdns->fqdn($ns), 'disabled' => false], DnsConfig::nameserverHosts()),
        ]];

        foreach ($records as $r) {
            $name = $this->pdns->fqdn($r->name);
            if ($name === $apex && $r->type === 'NS') {
                continue;                                   // NSِ apex فقط مالِ ماست
            }
            $key = $name.'|'.$r->type;
            $sets[$key] ??= ['name' => $name, 'type' => $r->type, 'ttl' => (int) $r->ttl, 'records' => []];
            $content = $this->content($r);
            if (! in_array($content, array_column($sets[$key]['records'], 'content'), true)) {
                $sets[$key]['records'][] = ['content' => $content, 'disabled' => false];
            }
        }

        return $sets;
    }

    /** قالبِ presentationِ PowerDNS از قالبِ ذخیره (§6.1) */
    private function content(CdnRecord $r): string
    {
        $c = trim((string) $r->content);

        return match ($r->type) {
            'CNAME', 'NS' => $this->pdns->fqdn($c),
            'MX'          => (int) $r->prio.' '.($c === '.' ? '.' : $this->pdns->fqdn($c)),
            'SRV'         => (int) $r->prio.' '.$this->srvTarget($c),
            default       => $c,                 // A/AAAA، TXT (presentationِ کانونی)، CAA
        };
    }

    private function srvTarget(string $c): string
    {
        $p = preg_split('~\s+~', $c) ?: [];
        $t = (string) array_pop($p);

        return implode(' ', $p).' '.($t === '.' ? '.' : $this->pdns->fqdn($t));
    }

    /**
     * تفاوتِ مطلوب و زنده — فقط چیزی که عوض شده.
     *
     * @param  array<string,array>  $desired
     * @param  list<array>  $live
     * @return list<array<string,mixed>>
     */
    public function diff(array $desired, array $live): array
    {
        $have = [];
        foreach ($live as $set) {
            $have[strtolower((string) ($set['name'] ?? '')).'|'.($set['type'] ?? '')] = $set;
        }

        $patch = [];
        foreach ($desired as $key => $set) {
            if (isset($have[$key]) && $this->same($have[$key], $set)) {
                continue;
            }
            $patch[] = $set + ['changetype' => 'REPLACE'];
        }

        foreach ($have as $key => $set) {
            $type = (string) ($set['type'] ?? '');
            if (isset($desired[$key]) || $type === 'SOA' || str_starts_with($key, '_acme-challenge.')) {
                continue;
            }
            $patch[] = ['name' => $set['name'], 'type' => $type, 'changetype' => 'DELETE'];
        }

        return $patch;
    }

    private function same(array $live, array $want): bool
    {
        if ((int) ($live['ttl'] ?? 0) !== (int) $want['ttl']) {
            return false;
        }
        $norm = function (array $rows, string $type): array {
            $out = [];
            foreach ($rows as $r) {
                if ($r['disabled'] ?? false) {
                    continue;
                }
                $c = (string) ($r['content'] ?? '');
                // TXT با محتوای منطقی مقایسه می‌شود (حساس به حروف)؛ بقیه بی‌حساسیت به حروف
                $out[] = $type === 'TXT' ? json_encode(TxtCodec::decode($c)) : strtolower($c);
            }
            sort($out);

            return $out;
        };

        return $norm((array) ($live['records'] ?? []), $want['type']) === $norm($want['records'], $want['type']);
    }

    private function serialOf(array $zoneData): ?int
    {
        if (isset($zoneData['serial']) && is_numeric($zoneData['serial'])) {
            return (int) $zoneData['serial'];
        }
        foreach ((array) ($zoneData['rrsets'] ?? []) as $set) {
            if (($set['type'] ?? '') === 'SOA' && isset($set['records'][0]['content'])) {
                $p = preg_split('~\s+~', (string) $set['records'][0]['content']);

                return isset($p[2]) && is_numeric($p[2]) ? (int) $p[2] : null;
            }
        }

        return null;
    }

    /* ───────────────────────── حذف و تعلیق ───────────────────────── */

    /**
     * حذف از primary؛ catalog آن را از نیم‌سرورها برمی‌دارد.
     *
     * $final=true (حذفِ مشتری/مدیر): ردیف فقط وقتی پاک می‌شود که **هر دو** نیم‌سرور
     * دیگر به zone پاسخِ authoritative ندهند — تا پیش از آن `deleting` می‌مانَد و
     * دامنه نه برمی‌گردد و نه کسِ دیگری می‌تواند وسطِ کار اضافه‌اش کند.
     */
    private function deleteRemote(CdnZone $zone, bool $final): PublishResult
    {
        $version = (int) $zone->desired_version;
        $r = $this->pdns->deleteZone($zone->domain);
        if (! $r['ok']) {
            return $this->failed($zone, 'delete: '.$r['error']);
        }

        if (! $final) {
            // ⚠️ applied_version هم باید جلو برود: وگرنه انتخابگرِ dns:publish
            // (`applied_version < desired_version`) هر دقیقه دوباره DELETE می‌فرستد و
            // zoneهای معلق کلِ سهمیهٔ هر اجرا را می‌خورند. شرطی، مثلِ گامِ پایانیِ انتشار.
            CdnZone::query()->whereKey($zone->getKey())->where('status', 'suspended')->update([
                'publish_state' => 'deleted', 'publish_attempts' => 0, 'next_publish_at' => null,
                'last_publish_error' => null, 'primary_serial' => null,
                'applied_version' => DB::raw('CASE WHEN applied_version < '.$version.' THEN '.$version.' ELSE applied_version END'),
            ]);

            return new PublishResult(true, 'suspended');
        }

        $states = $this->nodes->check($zone, expectAbsent: true);
        $gone = $states !== [] && collect($states)->every(fn ($s) => $s['state'] === DnsZoneNode::MISSING);

        if (! $gone) {
            // catalog در چرخهٔ بعد پاکش می‌کند؛ کمی بعد دوباره بسنج
            CdnZone::query()->whereKey($zone->getKey())->update([
                'publish_state' => 'deleting', 'next_publish_at' => now()->addSeconds(90), 'last_publish_error' => null,
            ]);

            return new PublishResult(true, 'deleting');
        }

        DB::transaction(function () use ($zone) {
            CdnEvent::audit('zone_deleted', $zone, ['customer_id' => $zone->customer_id], 'system');
            CdnRecord::query()->where('cdn_zone_id', $zone->getKey())->delete();
            DnsZoneNode::query()->where('cdn_zone_id', $zone->getKey())->delete();
            CdnZone::query()->whereKey($zone->getKey())->delete();
        });

        return new PublishResult(true, 'deleted');
    }

    private function failed(CdnZone $zone, string $error): PublishResult
    {
        $attempts = (int) CdnZone::query()->whereKey($zone->getKey())->value('publish_attempts') + 1;
        $delay = min(30 * (2 ** min($attempts - 1, 10)), 3600);

        CdnZone::query()->whereKey($zone->getKey())->update([
            'publish_attempts'   => $attempts,
            'publish_state'      => DB::raw("CASE WHEN status = 'deleting' THEN 'deleting' ELSE 'failed' END"),
            'next_publish_at'    => now()->addSeconds($delay),
            'last_publish_error' => mb_substr($error, 0, 255),
        ]);

        if ($attempts === 1 || $attempts % 5 === 0) {
            CdnEvent::audit('publish_failed', $zone, ['attempts' => $attempts], 'system');
        }

        return PublishResult::fail('failed', $error);
    }
}
