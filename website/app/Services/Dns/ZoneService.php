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
     * zone برای مالکِ اثبات‌شده: ساخت، یا انتقال از حسابِ دیگر (§5.2). null = سقف یا حذفِ در جریان.
     */
    public function adoptVerified(Customer $customer, string $domain, DnsOwnershipClaim $claim, string $via, ?string $ip = null): ?CdnZone
    {
        $existing = CdnZone::query()->where('domain', $domain)->lockForUpdate()->first();

        if ($existing && $existing->status === 'deleting') {
            return null;
        }

        if ($existing && (int) $existing->customer_id === (int) $customer->id) {
            $existing->forceFill(['claim_id' => $claim->id])->save();
            $claim->forceFill(['zone_id' => $existing->id])->save();

            return $existing;
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

        if (CdnZone::query()->where('customer_id', $customer->id)->count() >= DnsConfig::limit('zones_per_customer')) {
            return null;
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
            if ((int) $record->cdn_zone_id !== (int) $z->id || $z->status === 'deleting') {
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

        $existing = $zone->records()->get()->map(fn ($r) => $r->only(['id', 'type', 'name', 'content', 'prio', 'ttl']))->all();
        $accepted = [];
        $rows = [];
        $counts = ['new' => 0, 'duplicate' => 0, 'conflict' => 0, 'invalid' => 0, 'skipped' => 0, 'unsupported' => 0];

        foreach ($parsed as $row) {
            $out = ['line' => $row['line'], 'type' => $row['type'], 'name' => $row['name'], 'status' => $row['status'], 'code' => $row['code'], 'data' => null];

            if ($row['status'] === 'parsed') {
                $v = $this->validator->validate($zone->domain, $row['input']);
                if (! $v['ok']) {
                    $first = reset($v['errors']);
                    $out['status'] = 'invalid';
                    $out['code'] = $first['code'];
                } else {
                    $out['data'] = $v['data'];
                    $out['name'] = $v['data']['name'];
                    $c = $this->validator->conflicts($v['data'], array_merge($existing, $accepted));
                    if ($c === null) {
                        $out['status'] = 'new';
                        $accepted[] = $v['data'] + ['id' => null];
                    } else {
                        $out['status'] = $c['code'] === 'duplicate' ? 'duplicate' : 'conflict';
                        $out['code'] = $c['code'];
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
            if ($z->status === 'deleting') {
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

        if ($probeCurrent && $zone->delegation_state !== 'delegated') {
            foreach ($this->emailGaps($zone) as $gap) {
                $items[] = $gap;
            }
        }

        $blocking = collect($items)->contains(fn ($i) => $i['state'] === 'block');

        return ['items' => $items, 'blocking' => $blocking];
    }

    /**
     * رکوردهای ایمیل/گواهی که الان در DNSِ فعلیِ دامنه هست و این‌جا نیست.
     *
     * @return list<array{key:string, state:string, params:array}>
     */
    private function emailGaps(CdnZone $zone): array
    {
        $have = $zone->records()->get();
        $d = $zone->domain;
        $checks = [
            'mx'    => [$d, 'MX', fn () => $have->where('type', 'MX')->where('name', $d)->isNotEmpty()],
            'spf'   => [$d, 'TXT', fn () => $have->where('type', 'TXT')->where('name', $d)->contains(fn ($r) => str_contains(strtolower($r->content), 'v=spf1'))],
            'dmarc' => ['_dmarc.'.$d, 'TXT', fn () => $have->where('type', 'TXT')->where('name', '_dmarc.'.$d)->isNotEmpty()],
            'caa'   => [$d, 'CAA', fn () => $have->where('type', 'CAA')->where('name', $d)->isNotEmpty()],
        ];

        $out = [];
        foreach ($checks as $key => [$name, $type, $present]) {
            $r = $this->inspector->queryAuthoritative($d, $name, $type);
            if (! $r['ok']) {
                $out[] = ['key' => 'current_'.$key, 'state' => 'unknown', 'params' => []];

                continue;
            }
            $records = array_filter($r['records'], function ($rr) use ($type, $key) {
                if ($rr['type'] !== $type) {
                    return false;
                }

                return $key !== 'spf' || str_contains(strtolower(implode('', (array) $rr['data'])), 'v=spf1');
            });
            if ($records !== [] && ! $present()) {
                $out[] = ['key' => 'current_'.$key, 'state' => 'warn', 'params' => ['count' => count($records)]];
            }
        }

        return $out;
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
