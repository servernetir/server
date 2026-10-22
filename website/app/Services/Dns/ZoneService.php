<?php

namespace App\Services\Dns;

use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\DnsOwnershipClaim;
use App\Models\DnsZoneNode;
use App\Models\Service;
use App\Services\Dns\ZoneFile\ZoneFileParser;
use App\Services\Dns\ZoneFile\ZoneFileWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * منطقِ کسب‌وکارِ DNSِ مدیریت‌شده — یک جا برای پنلِ مشتری، مدیریت و کرون (قرارداد §4–§8).
 *
 * هر تغییر:  تراکنش + قفلِ ردیفِ zone → اعتبارسنجی/تعارض → نوشتن → `bumpVersion()`
 *            → audit → پس از commit یک تلاشِ فوریِ انتشار (ZonePublisher).
 * پاسخِ «ذخیره شد» هرگز «منتشر شد» نیست؛ انتشار را NodeVerifier تأیید می‌کند.
 *
 * مالکیت (zone و رکورد) همین‌جا هم دوباره سنجیده می‌شود، نه فقط در کنترلر: رکوردی
 * که به zoneِ داده‌شده تعلق ندارد هرگز ویرایش نمی‌شود (IDOR از راهِ سرویس هم بسته).
 */
class ZoneService
{
    public function __construct(
        private readonly RecordValidator $validator,
        private readonly ZonePublisher $publisher,
        private readonly NodeVerifier $nodes,
        private readonly DelegationInspector $inspector,
    ) {}

    /* ═════════════════════ zone ═════════════════════ */

    /** zoneی برای این دامنه در حالِ حذف است؟ (تا تأییدِ حذف، دامنه دوباره افزودنی نیست) */
    public function busy(string $domain): bool
    {
        return CdnZone::query()->where('domain', $domain)->where('status', 'deleting')->exists();
    }

    /**
     * zone برای مالکِ اثبات‌شده: ساخت، یا انتقال از حسابِ دیگر (§5.2).
     *
     * @throws AdoptRefused zone_deleting|zone_limit — فراخوان باید تراکنش را برگرداند
     */
    public function adoptVerified(Customer $customer, string $domain, DnsOwnershipClaim $claim, string $via, ?string $ip = null): CdnZone
    {
        $existing = CdnZone::query()->where('domain', $domain)->lockForUpdate()->first();

        if ($existing && $existing->status === 'deleting') {
            throw new AdoptRefused('zone_deleting');
        }

        if ($existing && (int) $existing->customer_id === (int) $customer->id) {
            $existing->forceFill(['claim_id' => $claim->id])->save();
            $claim->forceFill(['zone_id' => $existing->id])->save();

            return $existing;
        }

        if ($existing && $via !== 'txt') {
            // انتقالِ zoneِ زندهٔ دیگری فقط با اثباتِ DNS؛ ردیفِ Domainِ محلی کافی نیست
            throw new AdoptRefused('needs_txt');
        }

        if ($existing) {
            // انتقال: کنترلِ فعلیِ DNS برنده است؛ رکوردها می‌مانند تا سایت قطع نشود
            $from = $existing->customer_id;
            $existing->forceFill([
                'customer_id' => $customer->id, 'ownership' => 'transfer', 'claim_id' => $claim->id,
                'records_reviewed_at' => null,
            ])->save();
            $claim->forceFill(['zone_id' => $existing->id])->save();
            CdnEvent::audit('zone_transferred_out', $existing, ['to_customer' => $customer->id], 'customer:'.$from, $ip);
            CdnEvent::audit('zone_transferred_in', $existing, ['from_customer' => $from, 'via' => $via], 'customer:'.$customer->id, $ip);

            return $existing;
        }

        // zoneِ در حالِ حذف سهمیه نمی‌خورد
        if (CdnZone::query()->where('customer_id', $customer->id)->where('status', '!=', 'deleting')->count() >= DnsConfig::limit('zones_per_customer')) {
            throw new AdoptRefused('zone_limit');
        }

        $zone = CdnZone::create([
            'customer_id'  => $customer->id,
            'domain'       => $domain,
            'mode'         => 'ns',
            'status'       => 'pending',
            'verify_token' => $claim->token,
            'ownership'    => $via,
            'claim_id'     => $claim->id,
            'default_ttl'  => (int) config('edge.default_ttl', 3600),
            'waf_level'    => 'off',
            'cache_enabled' => false,
        ]);
        $claim->forceFill(['zone_id' => $zone->id])->save();
        CdnEvent::audit('zone_created', $zone, ['via' => $via], 'customer:'.$customer->id, $ip);

        // zone پیش از تغییرِ NS روی هر دو نود آماده شود (§5.3)
        $this->publisher->requestPublish($zone);

        return $zone;
    }

    public function requestDelete(CdnZone $zone, string $actor, ?string $ip = null): void
    {
        DB::transaction(function () use ($zone, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            $z->forceFill(['status' => 'deleting'])->save();
            $z->bumpVersion();
            CdnEvent::audit('zone_delete_requested', $z, [], $actor, $ip);
        });
        $this->publisher->requestPublish($zone);
    }

    public function setSuspended(CdnZone $zone, bool $suspended, string $actor, ?string $ip = null): void
    {
        DB::transaction(function () use ($zone, $suspended, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            if ($z->status === 'deleting') {
                return;
            }
            $to = $suspended ? 'suspended' : ($z->delegation_state === 'delegated' && $z->verified_at ? 'active' : 'pending');
            $z->forceFill(['status' => $to])->save();
            $z->bumpVersion();
            CdnEvent::audit($suspended ? 'zone_suspended' : 'zone_unsuspended', $z, [], $actor, $ip);
        });
        $this->publisher->requestPublish($zone);
    }

    /** انتشارِ دوباره (مدیر): فقط نسخه بالا می‌رود؛ ناشر خودش diff می‌گیرد */
    public function republish(CdnZone $zone, string $actor, ?string $ip = null): void
    {
        DB::transaction(function () use ($zone, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            $z->forceFill(['publish_attempts' => 0])->save();
            $z->bumpVersion();
            CdnEvent::audit('zone_republish', $z, [], $actor, $ip);
        });
        $this->publisher->requestPublish($zone);
    }

    public function markReviewed(CdnZone $zone, string $actor, ?string $ip = null): void
    {
        $zone->forceFill(['records_reviewed_at' => now()])->save();
        CdnEvent::audit('records_reviewed', $zone, [], $actor, $ip);
    }

    /* ═════════════════════ تفویض و فعال‌سازی ═════════════════════ */

    /**
     * تفویض از والد + هم‌گامیِ نودها → فعال‌سازی (§5.3). هرگز چیزی را پاک یا غیرفعال نمی‌کند.
     *
     * @return array{state:string, active:bool, nodes:array, error:?string}
     */
    public function checkDelegation(CdnZone $zone): array
    {
        $d = $this->inspector->checkDelegation($zone);
        $zone->refresh();
        $nodes = $zone->primary_serial !== null ? $this->nodes->check($zone) : [];
        $zone->refresh();

        if ($zone->status === 'pending' && $d['state'] === 'delegated' && $zone->isPublishedEverywhere()) {
            $zone->forceFill(['status' => 'active', 'verified_at' => $zone->verified_at ?? now()])->save();
            CdnEvent::audit('zone_activated', $zone, [], 'system');
        }

        return ['state' => $d['state'], 'active' => $zone->status === 'active', 'nodes' => $nodes, 'error' => $d['error']];
    }

    /* ═════════════════════ رکوردها ═════════════════════ */

    /**
     * @return array{ok:bool, errors:array<string,array{code:string,params:array}>, record:?CdnRecord, ttl_aligned:int}
     */
    public function addRecord(CdnZone $zone, array $input, string $actor, ?string $ip = null): array
    {
        return $this->write($zone, null, $input, $actor, $ip);
    }

    public function updateRecord(CdnZone $zone, CdnRecord $record, array $input, string $actor, ?string $ip = null): array
    {
        return $this->write($zone, $record, $input, $actor, $ip);
    }

    private function write(CdnZone $zone, ?CdnRecord $record, array $input, string $actor, ?string $ip): array
    {
        $result = DB::transaction(function () use ($zone, $record, $input, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($z->status, ['deleting'], true)) {
                return $this->recFail('zone', 'zone_deleting');
            }
            // تعلیقِ مدیر قفلِ ویرایش هم هست — وگرنه مشتری رکوردها را عوض می‌کرد و پس از رفعِ تعلیق منتشر می‌شد
            if ($z->status === 'suspended') {
                return $this->recFail('zone', 'zone_suspended');
            }
            if ($record !== null && (int) $record->cdn_zone_id !== (int) $z->id) {
                return $this->recFail('record', 'record_not_found');
            }
            if ($record === null && $z->records()->count() >= DnsConfig::limit('records_per_zone')) {
                return $this->recFail('record', 'record_limit', ['max' => DnsConfig::limit('records_per_zone')]);
            }

            $v = $this->validator->validate($z->domain, $input);
            if (! $v['ok']) {
                return ['ok' => false, 'errors' => $v['errors'], 'record' => null, 'ttl_aligned' => 0];
            }
            $existing = $z->records()->get();
            if ($c = $this->validator->conflicts($v['data'], $existing, $record?->id)) {
                return ['ok' => false, 'errors' => ['name' => $c], 'record' => null, 'ttl_aligned' => 0];
            }

            $siblings = $this->validator->ttlSiblings($v['data'], $existing, $record?->id);
            if ($siblings !== []) {
                CdnRecord::query()->whereIn('id', $siblings)->update(['ttl' => $v['data']['ttl']]);
            }

            $before = $record?->only(['type', 'name', 'content', 'ttl', 'prio']);
            if ($record) {
                $record->fill($v['data'])->save();
            } else {
                $record = $z->records()->create($v['data'] + ['proxied' => false, 'disabled' => false]);
            }
            $z->bumpVersion();
            CdnEvent::audit($before ? 'record_updated' : 'record_added', $z,
                ['record_id' => $record->id, 'type' => $record->type, 'name' => $record->name, 'before' => $before], $actor, $ip);

            return ['ok' => true, 'errors' => [], 'record' => $record, 'ttl_aligned' => count($siblings)];
        });

        if ($result['ok']) {
            $this->publisher->requestPublish($zone);
        }

        return $result;
    }

    public function deleteRecord(CdnZone $zone, CdnRecord $record, string $actor, ?string $ip = null): bool
    {
        $done = DB::transaction(function () use ($zone, $record, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $record->cdn_zone_id !== (int) $z->id || in_array($z->status, ['deleting', 'suspended'], true)) {
                return false;
            }
            $snapshot = $record->only(['type', 'name', 'content', 'ttl', 'prio']);
            $record->delete();
            $z->bumpVersion();
            CdnEvent::audit('record_deleted', $z, ['record_id' => $record->id] + $snapshot, $actor, $ip);

            return true;
        });

        if ($done) {
            $this->publisher->requestPublish($zone);
        }

        return $done;
    }

    /** @return array{ok:bool, code:?string} */
    public function toggleRecord(CdnZone $zone, CdnRecord $record, string $actor, ?string $ip = null): array
    {
        $r = DB::transaction(function () use ($zone, $record, $actor, $ip) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $record->cdn_zone_id !== (int) $z->id || $z->status === 'deleting') {
                return ['ok' => false, 'code' => 'record_not_found'];
            }
            if ($z->status === 'suspended') {
                return ['ok' => false, 'code' => 'zone_suspended'];
            }
            $enable = (bool) $record->disabled;
            if ($enable) {
                // رکوردِ خاموش ممکن است در این فاصله با رکوردِ تازه‌ای تعارض پیدا کرده باشد
                $data = $record->only(['type', 'name', 'content', 'ttl', 'prio']);
                $others = $z->records()->where('disabled', false)->whereKeyNot($record->id)->get();
                if ($c = $this->validator->conflicts($data, $others, $record->id)) {
                    return ['ok' => false, 'code' => $c['code']];
                }
            }
            $record->forceFill(['disabled' => ! $enable])->save();
            $z->bumpVersion();
            CdnEvent::audit($enable ? 'record_enabled' : 'record_disabled', $z, ['record_id' => $record->id], $actor, $ip);

            return ['ok' => true, 'code' => null];
        });

        if ($r['ok']) {
            $this->publisher->requestPublish($zone);
        }

        return $r;
    }

    /* ═════════════════════ واردکردن ═════════════════════ */

    /**
     * پیش‌نمایشِ فایلِ zone یا ردیف‌های اسکن: هر ردیف با همان اعتبارسنجِ فرم، در
     * برابرِ رکوردهای فعلی **و** ردیف‌های پیشینِ همان فایل.
     *
     * @param  list<array>|null  $scanRows  ردیف‌های اسکن (input آرایه) به‌جای فایل
     * @return array{rows:list<array>, counts:array<string,int>, fatal:?string, truncated:bool}
     */
    public function importPreview(CdnZone $zone, ?string $text, ?array $scanRows = null): array
    {
        if ($scanRows !== null) {
            $parsed = array_values(array_map(fn ($r, $i) => [
                'line' => $i + 1, 'name' => null, 'type' => strtoupper((string) ($r['type'] ?? '')), 'ttl' => null,
                'input' => $r, 'status' => 'parsed', 'code' => null,
            ], $scanRows, array_keys($scanRows)));
            $fatal = null;
            $truncated = false;
        } else {
            $pz = (new ZoneFileParser(DnsConfig::limit('import_max_bytes'), DnsConfig::limit('import_max_lines')))->parse((string) $text, $zone->domain);
            $parsed = $pz->rows;
            $fatal = $pz->fatal;
            $truncated = $pz->truncated;
        }

        $existing = $zone->records()->orderBy('id')->get()->map(fn ($r) => $r->only(['id', 'type', 'name', 'content', 'prio', 'ttl']))->all();
        // نمایه به‌جای conflicts() روی همهٔ ردیف‌ها — پیش‌نمایش خطی است (RecordSetIndex)
        $index = new RecordSetIndex($existing);
        $capacity = max(0, DnsConfig::limit('records_per_zone') - count($existing));
        $accepted = 0;
        $rows = [];
        $counts = ['new' => 0, 'duplicate' => 0, 'conflict' => 0, 'invalid' => 0, 'skipped' => 0, 'unsupported' => 0];

        // TTL مالِ RRset است (§6.2): ردیفِ تازه TTLِ RRsetِ موجود (یا اولین ردیفِ پذیرفته‌شدهٔ
        // همین فایل) را می‌گیرد؛ رکوردِ موجود هرگز عوض نمی‌شود. همان ترتیبِ id که desired() می‌خواند.
        $rrsetTtl = [];
        foreach ($existing as $e) {
            $rrsetTtl[$e['type'].'|'.$e['name']] ??= (int) $e['ttl'];
        }

        foreach ($parsed as $row) {
            $out = ['line' => $row['line'], 'type' => $row['type'], 'name' => $row['name'], 'status' => $row['status'], 'code' => $row['code'], 'params' => [], 'data' => null, 'ttl_from' => null];

            if ($row['status'] === 'parsed') {
                $v = $this->validator->validate($zone->domain, $row['input']);
                if (! $v['ok']) {
                    $first = reset($v['errors']);
                    $out['status'] = 'invalid';
                    $out['code'] = $first['code'];
                    $out['params'] = $this->scalarParams($first['params'] ?? []);
                } else {
                    $out['data'] = $v['data'];
                    $out['name'] = $v['data']['name'];
                    $c = $index->conflict($v['data']);
                    if ($c === null && $accepted >= $capacity) {
                        // پس از پرشدنِ سقف، ردیفِ تازه پذیرفته نمی‌شود (و کارِ بیشتری هم نمی‌سازد)
                        $c = ['code' => 'record_limit', 'params' => ['max' => DnsConfig::limit('records_per_zone')]];
                    }
                    if ($c === null) {
                        $key = $v['data']['type'].'|'.$v['data']['name'];
                        if (isset($rrsetTtl[$key]) && $rrsetTtl[$key] !== (int) $v['data']['ttl']) {
                            $out['ttl_from'] = (int) $v['data']['ttl'];
                            $out['data']['ttl'] = $rrsetTtl[$key];
                        }
                        $rrsetTtl[$key] ??= (int) $out['data']['ttl'];
                        $out['status'] = 'new';
                        $index->add($out['data']);
                        $accepted++;
                    } else {
                        $out['status'] = match ($c['code']) { 'duplicate' => 'duplicate', 'record_limit' => 'invalid', default => 'conflict' };
                        $out['code'] = $c['code'];
                        $out['params'] = $this->scalarParams($c['params'] ?? []);
                    }
                }
            }
            $counts[$out['status']] = ($counts[$out['status']] ?? 0) + 1;
            $rows[] = $out;
        }

        return ['rows' => $rows, 'counts' => $counts, 'fatal' => $fatal, 'truncated' => $truncated];
    }

    /**
     * اعمالِ ردیف‌های انتخاب‌شده — ورودی دوباره روی سرور تجزیه و سنجیده می‌شود
     * (هیچ دادهٔ آماده‌ای از مرورگر پذیرفته نمی‌شود)، همه در یک تراکنش و یک نسخه.
     * رکوردِ موجود هرگز بازنویسی نمی‌شود.
     *
     * @param  list<int>  $lines  شمارهٔ خطِ ردیف‌هایی که مشتری تیک زده
     * @return array{added:int, counts:array<string,int>, skipped:int}
     */
    public function importApply(CdnZone $zone, ?string $text, array $lines, string $actor, ?string $ip = null, ?array $scanRows = null): array
    {
        $added = 0;
        $skipped = 0;

        $counts = DB::transaction(function () use ($zone, $text, $lines, $actor, $ip, $scanRows, &$added, &$skipped) {
            $z = CdnZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($z->status, ['deleting', 'suspended'], true)) {
                return [];
            }
            $preview = $this->importPreview($z, $text, $scanRows);
            $want = array_flip(array_map('intval', $lines));
            $limit = DnsConfig::limit('records_per_zone') - $z->records()->count();

            foreach ($preview['rows'] as $row) {
                if (! isset($want[$row['line']])) {
                    continue;
                }
                if ($row['status'] !== 'new' || $added >= $limit) {
                    $skipped++;

                    continue;
                }
                $z->records()->create($row['data'] + ['proxied' => false, 'disabled' => false]);
                $added++;
            }

            if ($added > 0) {
                $z->bumpVersion();
            }
            CdnEvent::audit('records_imported', $z, ['added' => $added, 'skipped' => $skipped, 'source' => $scanRows !== null ? 'scan' : 'file'], $actor, $ip);

            return $preview['counts'];
        });

        if ($added > 0) {
            $this->publisher->requestPublish($zone);
        }

        return ['added' => $added, 'counts' => $counts, 'skipped' => $skipped];
    }

    /** پارامترهای پیام برای __() — فقط مقدارهای ساده (پیش‌نمایش در session هم می‌ماند) */
    private function scalarParams(array $params): array
    {
        return array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $params);
    }

    public function exportZoneFile(CdnZone $zone): string
    {
        return (new ZoneFileWriter)->write(
            $zone->domain,
            $zone->records()->orderBy('id')->get(),
            DnsConfig::nameserverHosts(),
            (int) ($zone->default_ttl ?: 3600),
            now()->toIso8601String(),
        );
    }

    /* ═════════════════════ زمینه برای رابط ═════════════════════ */

    /**
     * سرویسِ هاستِ همین مشتری روی همین دامنه (§8) — برای اطلاع‌رسانیِ تقدم، نه همگام‌سازی.
     *
     * @return list<array{service_id:int, ip:?string}>
     */
    public function hostingContext(CdnZone $zone): array
    {
        try {
            return Service::query()
                ->where('customer_id', $zone->customer_id)
                ->where('domain', $zone->domain)
                ->whereIn('status', Service::PANEL_STATUSES)
                ->get()
                ->filter(fn (Service $s) => $s->kind() === 'hosting')
                ->map(fn (Service $s) => ['service_id' => (int) $s->id, 'ip' => $s->server_ip ?: null])
                ->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * چک‌لیستِ مهاجرت پیش از راهنمای تغییرِ NS (§7).
     *
     * @return array{items:list<array{key:string, state:string, params:array}>, blocking:bool}
     *         state: ok|warn|block|todo|unknown
     */
    public function checklist(CdnZone $zone, bool $probeCurrent = true): array
    {
        $items = [];
        $items[] = ['key' => 'ownership', 'state' => $zone->ownership ? 'ok' : 'todo', 'params' => ['via' => $zone->ownership]];

        $published = $zone->isPublishedEverywhere();
        $items[] = ['key' => 'published', 'state' => $published ? 'ok' : 'todo', 'params' => []];
        $items[] = ['key' => 'reviewed', 'state' => $zone->records_reviewed_at ? 'ok' : 'todo', 'params' => []];

        $ds = (array) ($zone->parent_ds ?? []);
        $items[] = ['key' => 'dnssec', 'state' => $ds !== [] ? 'block' : ($zone->delegation_checked_at ? 'ok' : 'unknown'), 'params' => ['count' => count($ds)]];

        // پیش از تفویض: رکوردهای ایمیل/گواهیِ DNSِ فعلی که این‌جا نیست (§7).
        // $probeCurrent=false (بارگذاریِ صفحه) فقط نتیجهٔ ذخیره‌شده را می‌خواند؛
        // پرس‌وجوی تازه با دکمهٔ «بررسی» (throttle) و کرونِ dns:check-delegation است.
        if ($zone->delegation_state !== 'delegated') {
            $gaps = $probeCurrent ? $this->probeEmailGaps($zone) : ($this->cachedEmailGaps($zone) ?? []);
            foreach ($gaps as $gap) {
                $items[] = $gap;
            }
        }

        $blocking = collect($items)->contains(fn ($i) => $i['state'] === 'block');

        return ['items' => $items, 'blocking' => $blocking];
    }

    public const GAPS_CACHE = 'dns:email-gaps:';

    /** پرس‌وجوی تازه + ذخیره برای ۱۲ ساعت. پس از تفویض چیزی برای مقایسه نیست. */
    public function probeEmailGaps(CdnZone $zone): array
    {
        $items = $zone->delegation_state === 'delegated' ? [] : $this->emailGaps($zone);
        Cache::put(self::GAPS_CACHE.$zone->getKey(), ['at' => now()->toIso8601String(), 'items' => $items], now()->addHours(12));

        return $items;
    }

    /** @return ?list<array> null = هنوز سنجیده نشده */
    public function cachedEmailGaps(CdnZone $zone): ?array
    {
        $c = Cache::get(self::GAPS_CACHE.$zone->getKey());

        return is_array($c) && is_array($c['items'] ?? null) ? $c['items'] : null;
    }

    /**
     * رکوردهای ایمیل/گواهی که الان در DNSِ فعلیِ دامنه هست و این‌جا نیست.
     *
     * تفویضِ والد یک بار خوانده می‌شود و همهٔ پرس‌وجوها مستقیم به همان
     * نیم‌سرورهای authoritative می‌روند (نه resolver). خطای خواندن = «نامعلوم»، نه «نیست».
     *
     * @return list<array{key:string, state:string, params:array}>
     */
    private function emailGaps(CdnZone $zone): array
    {
        return $this->inspector->batch(fn (): array => $this->emailGapsNow($zone));
    }

    private function emailGapsNow(CdnZone $zone): array
    {
        $have = $zone->records()->get();
        $d = $zone->domain;
        $keys = ['mx', 'spf', 'dmarc', 'dkim', 'caa'];

        $del = $this->inspector->parentDelegation($d);
        if (($del['servers'] ?? []) === []) {
            return array_map(fn ($k) => ['key' => 'current_'.$k, 'state' => 'unknown', 'params' => []], $keys);
        }

        $checks = [
            'mx'    => [$d, 'MX', fn () => $have->where('type', 'MX')->where('name', $d)->isNotEmpty()],
            'spf'   => [$d, 'TXT', fn () => $have->where('type', 'TXT')->where('name', $d)->contains(fn ($r) => str_contains(strtolower($r->content), 'v=spf1'))],
            'dmarc' => ['_dmarc.'.$d, 'TXT', fn () => $have->where('type', 'TXT')->where('name', '_dmarc.'.$d)->isNotEmpty()],
            'caa'   => [$d, 'CAA', fn () => $have->where('type', 'CAA')->where('name', $d)->isNotEmpty()],
        ];

        $out = [];
        foreach ($checks as $key => [$name, $type, $present]) {
            $r = $this->inspector->queryAuthoritative($d, $name, $type, $del);
            if (! $r['ok']) {
                $out[$key] = ['key' => 'current_'.$key, 'state' => 'unknown', 'params' => []];

                continue;
            }
            $records = array_filter($r['records'], function ($rr) use ($type, $key) {
                if ($rr['type'] !== $type) {
                    return false;
                }

                return $key !== 'spf' || str_contains(strtolower(implode('', (array) $rr['data'])), 'v=spf1');
            });
            if ($records !== [] && ! $present()) {
                $out[$key] = ['key' => 'current_'.$key, 'state' => 'warn', 'params' => ['count' => count($records)]];
            }
        }

        // DKIM: selectorهای رایج (همان فهرستِ اسکن). TXT یا CNAME در DNSِ فعلی،
        // بی‌هیچ‌کدام این‌جا ⇒ امضای ایمیل پس از تغییرِ NS می‌شکند.
        $missing = 0;
        $unknown = false;
        foreach (ZoneImporter::DKIM_SELECTORS as $sel) {
            $name = $sel.'._domainkey.'.$d;
            $here = $have->whereIn('type', ['TXT', 'CNAME'])->where('name', $name)->isNotEmpty();
            foreach (['TXT', 'CNAME'] as $type) {
                $r = $this->inspector->queryAuthoritative($d, $name, $type, $del);
                if (! $r['ok']) {
                    $unknown = true;

                    continue;
                }
                if (collect($r['records'])->contains(fn ($rr) => $rr['type'] === $type)) {
                    $missing += $here ? 0 : 1;

                    break;
                }
            }
        }
        if ($missing > 0) {
            $out['dkim'] = ['key' => 'current_dkim', 'state' => 'warn', 'params' => ['count' => $missing]];
        } elseif ($unknown) {
            $out['dkim'] = ['key' => 'current_dkim', 'state' => 'unknown', 'params' => []];
        }

        return array_values(array_intersect_key(array_merge(array_flip($keys), $out), $out));
    }

    /* ═════════════════════ کمکی ═════════════════════ */

    private function recFail(string $field, string $code, array $params = []): array
    {
        return ['ok' => false, 'errors' => [$field => ['code' => $code, 'params' => $params]], 'record' => null, 'ttl_aligned' => 0];
    }

    /** وضعیتِ نودها برای نمایش (بدونِ پرس‌وجوی تازه) */
    public function nodeStates(CdnZone $zone): array
    {
        $rows = DnsZoneNode::query()->where('cdn_zone_id', $zone->id)->get()->keyBy('node');
        $out = [];
        foreach (DnsConfig::nameserverHosts() as $h) {
            $r = $rows[$h] ?? null;
            $out[$h] = ['state' => $r?->state ?? DnsZoneNode::UNKNOWN, 'serial' => $r?->serial, 'checked_at' => $r?->checked_at];
        }

        return $out;
    }
}
