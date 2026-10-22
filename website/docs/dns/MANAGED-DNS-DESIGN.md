# Managed DNS v2 — binding design contract

Status: design for branch `feature/managed-dns-v2` (base `origin/develop` 92c02953, 2026-10-17).
Scope: **Managed Authoritative DNS only.** CDN / reverse proxy / cache / WAF are a later phase:
their tables (`cdn_origins`, `cdn_cache_rules`, `cdn_waf_rules`, `cdn_certificates`, `cdn_usage`, `cdn_nodes`)
stay untouched and no UI for them is shown. `cdn_records.proxied` stays `false` and no code path may set it.

Every implementation step must follow this document. When code and this document disagree, fix the code
or update this document in the same commit with the reason.

---

## 0. Verified facts this design rests on

| Fact | Evidence |
|---|---|
| Managed DNS v1 is merged on `develop` but **not on production** | `console.servernet.cloud/account/dns` → 404 while `/account/domains` → 302 (2026-10-17) |
| v1 migration `2026_11_03_000100_create_cdn_tables` never ran on production | same — feature absent; still treat it as released code: **do not edit it**, add new migrations |
| The project runs **no queue workers** (`ShouldQueue` appears nowhere in `app/`) | grep on develop. Async work = scheduled artisan commands via `schedule:run` |
| PowerDNS Authoritative 5.0.2 behaviour (catalog, TSIG, serial, deletion) | WSL lab, `infra/dns/lab/lab-up.sh`, results in §9 |
| Catalog consumers do **not** apply TSIG to member zones; un-transferred zones are invisible to `pdnsutil`/`pdns_control` listing | lab; fixed by `infra/dns/secondary/pdns-tsig-reconcile.sh` |
| `ns1/ns2.servernet.cloud` → 65.109.176.14 (WHM DE, cPanel PowerDNS on :53); `ns1/ns2.servernet.ir` → 85.9.108.116 (WHM IR) | public DNS 2026-10-17 |
| `servernet.cloud` and `servernet.ir` zones are on Cloudflare; `servernet.cloud` has a proxied wildcard | public DNS |
| Iran host public /29 fully allocated (.113 gw, .114 host, .115 Plesk, .116 WHM, .117 customer VM, .118 host/SNAT) | Proxmox API |

Unknown / not verifiable from this workstation (no shell access granted): host listeners, NAT/firewall rules,
policy routing, country-routing agent state on the Iran host; port-53 binding details of cPanel PowerDNS on DE.
Infrastructure steps that depend on them are **out of the code path** and documented in `infra/dns/README.md`.

---

## 1. Architecture

```
Laravel (DB = desired state, source of truth)
   │  HTTPS, API key, single trusted endpoint from settings
   ▼
hidden PRIMARY  PowerDNS  (gsqlite3, API, catalog PRODUCER, NOTIFY, TSIG-only AXFR)
   │  NOTIFY + AXFR/IXFR signed with TSIG  (TCP/UDP 53, firewall-limited to secondaries)
   ├──────────────► SECONDARY "ns-A"  (public :53, gsqlite3, catalog CONSUMER, tsig-reconcile timer)
   └──────────────► SECONDARY "ns-B"  (public :53, same)
```

Decision: **primary/secondary with catalog zones + TSIG zone transfer**, not independent dual-write.
Reasons (recorded for review):
1. One write path and one credential. The app never needs to reach the public nameservers' APIs (none exist on them).
2. Each public node has its **own local copy** and answers without the app, the primary or the other node.
   It keeps answering after losing the primary until SOA **expire = 2419200s (28 days)**; after that it returns
   SERVFAIL for that zone. Two nodes do **not** guarantee unlimited service during an outage.
3. Catalog membership propagates creation **and deletion**; plain autosecondary never deletes.
4. Transfer is authenticated (TSIG) and additionally firewall-limited; recursion is off everywhere.
5. Survives cross-border link loss: a lagging node catches up on its own (lab: 3 s after restart).

The primary's location is a deployment decision (§10). The code only needs: one API URL, and a list of public
nameserver endpoints `{host, ipv4, ipv6, port}` used for direct verification queries.

---

## 2. Configuration (all from trusted server-side settings, never from customer input)

`config/edge.php` → new key `dns`:

| Key | Source (Setting overrides config) | Notes |
|---|---|---|
| `enabled` | Setting `dns_managed_enabled` ('1'/'0'), default off | **feature flag / kill switch**: off ⇒ menu hidden and **every** customer DNS route 404s (zones already delegated keep being served; nothing is deleted). On but connection incomplete (no API key/URL or fewer than two nameservers) ⇒ menu hidden, existing zones still viewable and editable (the publisher retries with backoff), adding domains and verifying claims closed |
| `api_url` | Setting `pdns_api_url` | must be `https://` unless host is loopback/private **and** `DNS_ALLOW_INSECURE_API=true` (lab/dev only) |
| `api_key` | `Setting::getSecret('pdns_api_key')` | never rendered, logged or returned |
| `server_id` | config `localhost` | |
| `catalog` | Setting `dns_catalog_zone`, default `catalog.servernet.invalid` | producer zone on primary |
| `tsig_key` | Setting `dns_xfr_tsig_key`, default `xfr-servernet` | **name only**; the secret lives on the servers |
| `nameservers` | Setting `dns_nameservers` JSON `[{host,ipv4,ipv6,port}]` | ≥2 required; hosts must not be `ns1/ns2.servernet.cloud|ir` |
| `soa` | config: email `hostmaster.servernet.cloud`, refresh 3600, retry 600, expire 2419200, minimum 300 | |
| limits | config: zones/customer 50, records/zone 500, pending claims/customer 20 | |

`CdnZone::serviceOpen()` = enabled ∧ api_url ∧ api_key ∧ ≥2 nameservers with an IPv4.

`App\Services\Dns\DnsConfig` (new, final class) is the **only** reader of these values. Everything else asks it.
Methods: `enabled(): bool`, `apiUrl(): ?string`, `apiKey(): ?string`, `apiUrlIsAcceptable(): bool`, `catalog(): string`,
`tsigKey(): string`, `nameservers(): array<int,array{host:string,ipv4:?string,ipv6:?string,port:int}>`,
`nameserverHosts(): array<int,string>` (lowercase, no trailing dot), `soa(): array`, `limit(string $k): int`,
`serviceOpen(): bool`, `reserved(): array` (`ns1/ns2.servernet.cloud`, `ns1/ns2.servernet.ir`).
IPv6 addresses are only used when configured; the UI warns that IPv6 must be tested before publishing.

---

## 3. Data model

v1 tables stay. New migration `2026_11_03_000200_managed_dns_v2.php` (additive only; every column nullable or defaulted; guarded with `Schema::hasTable/hasColumn`).

### 3.1 `dns_ownership_claims` (new)
`id`, `customer_id` (idx), `domain` (ascii, normalized), `token` (char 32, random hex), `status` (`pending|verified|expired|cancelled`), `expires_at`, `verified_at` null, `verified_via` null (`txt|registered`), `attempts` uint default 0, `last_checked_at` null, `last_result` string(40) null (a result **code**, see §5), `zone_id` null, timestamps. Index `(domain,status)`, `(customer_id,status)`.
- A claim never publishes anything and never blocks anyone. Many accounts may hold pending claims for the same domain.
- TTL 7 days. Pending claims per customer ≤ 20.

### 3.2 `cdn_zones` additions
| column | type | meaning |
|---|---|---|
| `desired_version` | ubigint default 1 | bumped in the same transaction as any record/zone change |
| `applied_version` | ubigint default 0 | highest version confirmed on the **primary** |
| `publish_state` | string(20) default `pending` | `pending|published|failed|deleting|deleted` |
| `publish_attempts` | uint default 0 | consecutive failures |
| `next_publish_at` | timestamp null | backoff gate |
| `last_publish_error` | string(255) null | sanitized **internal** text (admin only) |
| `primary_serial` | ubigint null | SOA serial read back from primary after last successful publish |
| `published_at` | timestamp null | |
| `delegation_state` | string(20) default `unknown` | `unknown|not_delegated|partial|delegated|lookup_failed` |
| `delegation_checked_at` | timestamp null | |
| `delegation_ns` | json null | NS set seen **at the parent** |
| `parent_ds` | json null | DS records seen at the parent (non-empty ⇒ DNSSEC risk) |
| `records_reviewed_at` | timestamp null | customer confirmed the imported records |
| `claim_id` | ubigint null | claim that proved ownership |

`status` meaning in v2: `pending` (owned, not yet delegated), `active` (delegation verified **and** published and all nodes in sync at activation time), `suspended` (admin), `deleting`.
Existing columns `verify_token/verified_at/verify_error/checked_at/ownership/synced_at/sync_error/dirty` remain for compatibility; v2 code writes `ownership` (`registered|txt|transfer`) and `verified_at` (= delegation first verified) and stops using `dirty/synced_at/sync_error` (keep them updated as mirrors only if cheap; never read them).

### 3.3 `dns_zone_nodes` (new)
`id`, `cdn_zone_id` (idx), `node` (host string), `serial` ubigint null, `state` (`unknown|in_sync|lagging|missing|unreachable`), `checked_at`, `last_error` string(120) null, timestamps; unique `(cdn_zone_id,node)`.

### 3.4 `cdn_events` additions
`actor` string(40) null (`customer:{id}`, `admin:{id}`, `system`), `ip` string(45) null. Audit = `CdnEvent::note()` with actor+ip for every customer/admin mutation (zone add/claim/verify/transfer, record add/update/delete/toggle, import apply, delete, suspend, settings change). Messages are **codes + params**, not prose (rendered via lang).

---

## 4. Publishing

### 4.1 Trigger
Every mutation runs inside `DB::transaction` and calls `$zone->bumpVersion()` (atomic `increment('desired_version')` + `publish_state='pending'`, `next_publish_at=null`). After commit, `ZonePublisher::requestPublish($zone)` is registered with `DB::afterCommit` and tries **one immediate synchronous publish** with bounded timeouts. The HTTP response never waits more than the API timeout and never reports DNS success based on it.

Scheduled `dns:publish` (every minute, `withoutOverlapping`) picks zones where (`publish_state` in `pending|failed|deleting`) and (`next_publish_at` null or ≤ now), oldest first, limit 100.

### 4.2 `ZonePublisher::publish(CdnZone $zone): PublishResult`
1. `Cache::lock("dns:publish:{$zone->id}", 120)->get()`; not acquired ⇒ return `locked` (scheduler retries). No two publishers of one zone run concurrently.
2. Reload zone. If `status=deleting` ⇒ §4.4.
3. Snapshot: inside a transaction read `desired_version` V and the enabled records. (A write after the snapshot bumps V' > V; this run marks only V applied, so the zone stays `pending` and is published again — an old snapshot can never be marked as the newest.)
4. Ensure zone on primary: if GET → 404 create with `kind=Primary`, `catalog=<catalog>.`, `nameservers`=configured hosts, SOA from config (`expire 2419200`), `soa_edit_api=DEFAULT`; then set metadata `TSIG-ALLOW-AXFR=<tsig_key>`. GET errors other than 404 ⇒ failure (never recreate on "unknown").
5. Build desired rrsets (apex NS from config always; SOA never sent), diff against live rrsets (REPLACE changed, DELETE absent; never touch SOA), PATCH once. There is **no** `_acme-challenge.*` exemption: customers create those names themselves, so an exemption kept deleted records (and a previous owner's acme-dns CNAME after a transfer) live and able to obtain certificates. A future ACME issuer must write through the DB like everything else.
6. GET zone → `serial` ⇒ `primary_serial`. Also ensure `catalog` field matches (repair if not) and metadata present (repair if not).
7. `PUT /zones/{z}/notify` (best effort).
8. Conditional update: `UPDATE cdn_zones SET applied_version=V, publish_state = CASE WHEN desired_version=V THEN 'published' ELSE 'pending' END, publish_attempts=0, next_publish_at=NULL, last_publish_error=NULL, primary_serial=?, published_at=now WHERE id=? AND applied_version < V`.
9. Failure: `publish_attempts+1`, `publish_state='failed'`, `next_publish_at = now + min(30s·2^(attempts-1), 1h)`, `last_publish_error` = sanitized message (strip API key/URL credentials, ≤255).
10. Release lock. Return a value object with `ok`, `code`, `version`, `serial`.

HTTP: `connectTimeout 4s`, `timeout 8s`, no automatic retries inside a run.

### 4.3 Node verification — `NodeVerifier::check(CdnZone $zone): array`
For each configured nameserver: direct, non-recursive SOA query (UDP, TCP on truncation) to `ipv4:port` (and `ipv6` if configured). Result per node into `dns_zone_nodes`:
`in_sync` (AA set, serial ≥ `primary_serial` per RFC 1982), `lagging` (AA, lower serial), `missing` (REFUSED/NOTAUTH/NXDOMAIN or no AA), `unreachable` (timeout/socket error **and SERVFAIL or any other rcode**, so a node whose backend is temporarily broken can never confirm a deletion). `dns:check-nodes` runs every 2 minutes for zones published in the last 24 h or not fully in sync, and hourly for the rest; customer/admin "check now" buttons call it for one zone (throttled).
"Published" shown to the customer **only** when `publish_state=published` and every node `in_sync` for the current `primary_serial`.

### 4.4 Deletion
Customer/admin delete ⇒ `status=deleting`, `publish_state=deleting`, bump version, audit. Publisher: `DELETE /zones/{z}` (404 = done) ⇒ then verifies nodes (`missing` on all ⇒ hard-delete zone, records, node rows; audit `zone_deleted`). Until confirmed the row stays (`deleting`) so the zone cannot silently come back and the domain cannot be re-added by someone else in between. A code rollback never deletes zones: deletion only happens through these explicit paths.

### 4.5 Reconciliation / drift — `dns:reconcile` (hourly)
For every zone with `publish_state=published`: GET live rrsets from primary, diff with desired; non-empty diff ⇒ audit `drift_detected` (count) + bump version (republish). A zone missing on the primary (404) is drift too: it is re-queued, not counted as a failure. Zones present on the primary but not in the DB ⇒ **orphans**. The last run's report (time, drift, read failures, up to 100 orphans) is stored in Setting `dns_reconcile_report` and shown on `/admin/dns`. Orphans are never auto-deleted. Findings never make the scheduled command fail: exit 0 (project rule `ScheduledFindingIsNotAFailureTest`), and read failures are visible in the report.

### 4.6 Suspension
`suspended` ⇒ removed from primary (same as delete on the server side, row kept). Suspension is also an edit lock: the customer can view and export, but record writes, import and **delete** are refused (`zone_suspended`) — otherwise deleting the already-removed zone completed almost at once and re-adding the domain produced a fresh, unsuspended zone. Re-adding by the same customer lands on the suspended zone; a TXT transfer to another account keeps the suspension; only an admin can unsuspend or delete. The removal sets `applied_version` to the snapshot version, so the per-minute publisher does not send DELETE again. Unsuspend ⇒ `pending` publish.

### 4.7 Legacy v1 rows
Only zones with v2 proof (`ownership ∈ registered|txt|transfer`, `CdnZone::PROVEN`) are ever published. v1 rows with `ownership` NULL or `delegated` (NS pointing to us is not proof, §5.1) are skipped by `dns:publish` and return `unproven`. They can still be suspended or deleted. Production has no v1 rows (§0); this only protects dev and staging databases.

---

## 5. Ownership and delegation

### 5.1 Normalization — `DomainName::normalize(string): ?string`
Trim, strip scheme/path/port, lowercase, strip one trailing dot, IDNA UTS#46 → ASCII (`idn_to_ascii` with `IDNA_NONTRANSITIONAL_TO_ASCII`), validate labels (1–63, LDH, no leading/trailing hyphen except `xn--`), total ≤ 253. `www.` is **not** silently removed: registrable check (`CdnZone::registrable`) rejects subdomains with a suggestion of the registrable domain.

### 5.2 Claim flow — `OwnershipService`
- `start(Customer, raw)`: normalize; reject reserved/our own infrastructure domains (`servernet.cloud`, `servernet.ir`, configured NS hosts' parents); if this customer already owns the zone ⇒ return it; if `Domain` row (status `active`) belongs to this customer **and no zone exists for the domain** ⇒ verified via `registered` immediately (the local `Domain` row is never revalidated — a domain transferred to another registrar stays `active` until expiry — so it may only *create* a zone; an existing zone of another account moves only with TXT proof, enforced again inside the lock: `AdoptRefused('needs_txt')`); else create or reuse this customer's pending claim (new random token, 7-day expiry).
- `challenge(claim)`: `{name: "_servernet-challenge.<domain>", type: "TXT", value: "servernet-domain-verification=<token>"}`.
- `verify(claim)`: `DelegationInspector::parentDelegation(domain)` → current NS set **from the parent**; query each of those servers **directly** (RD=0) for the challenge TXT; success iff at least one authoritative answer (AA) contains the exact value. If the domain's parent NS are **our** nameservers, the TXT can only come from a zone already served by us (owned by someone) — that is the intended semantics; zones of unverified claims are never published, so a claim can never prove itself.
  Result codes: `verified`, `txt_missing`, `txt_mismatch`, `lookup_failed` (timeout/SERVFAIL/no parent answer — **never** treated as failure of ownership), `expired`, `zone_deleting` (a previous zone for the domain is still being removed), `zone_limit`. For the last two the transaction is rolled back and the claim **stays pending**, so the published TXT keeps working on the next check. Zones in `deleting` do not count toward the quota.
- On `verified` (under `Cache::lock("dns:domain:{domain}")` + transaction): if a zone exists for another customer ⇒ **transfer** (customer_id changes, records kept, `ownership=transfer`, audit for both accounts); else create zone (`status=pending`, version 1, `ownership=txt|registered`) and publish. Other customers' claims stay pending (they would need to prove control again).
- `dns:expire-claims` daily marks old pending claims `expired`.

### 5.3 Delegation — `DelegationInspector` (uses `DnsWireClient`)
- `parentDelegation(domain)`: iterative walk from root hints (hard-coded IPv4 of a–m root servers), RD=0, follow referrals using glue; NS hostnames without glue are resolved through the same iterative walk (bounded) and, as last resort, a public resolver (address only). Stop when a referral or authoritative answer names `domain` itself. Returns `{ok, ns[], ds[], servers[{host,ip}], error}`; `ds` = DS rrset fetched from a parent server (RD=0).
- `checkDelegation(zone)`: compare parent NS set to `DnsConfig::nameserverHosts()` → `delegated` (equal sets), `partial` (intersection non-empty, not equal), `not_delegated` (no intersection), `lookup_failed`. Never deactivate or delete on `lookup_failed`. Write `delegation_state/_checked_at/_ns/parent_ds`.
- Activation: `pending` → `active` when `delegated` ∧ `publish_state=published` ∧ all nodes `in_sync`. Active zone losing delegation keeps `active` + warning (`delegation_state`), never auto-deleted.
- `dns:check-delegation` hourly for `pending` (and daily for `active`), limit 200, oldest check first.

### 5.4 DNSSEC
Out of scope for this phase (we do not sign). If `parent_ds` is non-empty: the migration checklist **blocks** the "change nameservers" step with an explicit instruction to remove DS at the current registrar first; for an already-delegated zone with DS, customer and admin see a critical warning. We never change DS at any registrar.

---

## 6. Records

### 6.1 Storage format (`cdn_records`)
| type | `content` | `prio` |
|---|---|---|
| A / AAAA | canonical IP (AAAA compressed lowercase) | null |
| CNAME / NS | FQDN without trailing dot | null |
| MX | exchange FQDN (or `.` null MX) | preference |
| SRV | `weight port target` (`target` FQDN or `.`) | priority |
| CAA | `flags tag "value"` (value in presentation escaping) | null |
| TXT | **presentation format**: one or more quoted strings, e.g. `"v=spf1 -all"` or `"part1" "part2"` | null |

`App\Services\Dns\TxtCodec`: `fromUserInput(string): array{ok,value?,code?}` (plain text ⇒ split into ≤255-**byte** strings on UTF-8 character boundaries; input starting with `"` ⇒ parsed as presentation, string boundaries preserved; adjacent quoted strings are re-joined with one space), `decode(string): string[]` (logical strings), `display(string): string` (joined, for UI), `canonical(string[]): string` (each string `"…"` with only `"`→`\"`, joined by single spaces). Max total 4096 bytes, ≤255 bytes per string.

**Allowed TXT content = valid UTF-8 printable characters (and space) plus `"`. Backslash and control bytes (<0x20, 0x7f) are rejected** with code `txt_backslash` / `txt_control`. Reason (lab, PowerDNS 5.0.2 API): `"a\"b\\c"` is stored as wire `a"bc` (backslash silently dropped) and `"\200"` as bytes `C2 80`; raw UTF-8 and `\"` round-trip exactly, multi-string boundaries are preserved, and `"a""b"` (no space) is rejected by the API. With this restriction the canonical string we send equals what the API returns, so the diff never sees phantom drift. Diff still compares TXT via `decode()` on both sides as a safety net.

### 6.2 `RecordValidator` v2 (`validate(zone, input): result`, `conflicts(data, existing, ignoreId): ?error`)
Name: `@`/empty ⇒ apex; relative ⇒ appended; FQDN accepted only inside zone; wildcard only leftmost; labels LDH plus `_` (underscore not allowed in A/AAAA/MX owner names except under `_`-prefixed parents); ≤253.
Types A, AAAA (global unicast; private/reserved rejected for A), CNAME, MX, TXT, SRV (`_service._proto` owner), CAA (`issue|issuewild|iodef`, flags 0/128), NS (only below apex). TTL 60–86400.
Conflicts: exact duplicate; CNAME with any other type at same name (and two CNAMEs); apex CNAME; apex NS; **delegation occlusion**: no non-NS record at a delegated name and no record below it except glue A/AAAA whose name is an NS target inside that subtree; adding NS where non-glue records exist ⇒ error.
**RRset TTL:** TTL belongs to the RRset. Add/update with a different TTL updates the TTL of all siblings in the same transaction; the UI says so. Import never changes existing records, so an imported row takes the TTL of the existing RRset, or of the first accepted row of the same name and type in the file. The preview shows this as "TTL changed from X to Y".
Edit may change name, type, value, TTL; full-state publishing removes the old RRset member and adds the new one without touching siblings.

### 6.3 Errors are codes
Validator and services return `['field' => 'dns.err.<code>']` + params; controllers translate with `__()`.

---

## 7. Import / export

- `ZoneFileParser::parse(string $text, string $origin): ParsedZone` — `$ORIGIN`, `$TTL`, `@`, relative/absolute owners, blank owner = previous owner, optional TTL and class in either order, class `IN` only, parentheses spanning lines, `;` comments outside quotes, quoted strings with `\"`, `\\`, `\DDD`. Types A, AAAA, CNAME, MX, TXT, SRV, CAA, NS supported; SOA (skipped), apex NS (skipped: managed), DNSKEY/RRSIG/NSEC*/DS/CDS/CDNSKEY (skipped: DNSSEC not in scope), SPF type (unsupported → suggest TXT), others unsupported. Output rows `{line, name, type, ttl, content, prio, status, code}`; never throws on bad input; limit 5000 lines / 1 MB.
- Preview statuses: `new`, `duplicate` (identical exists), `conflict` (validator conflict with existing or earlier row), `invalid`, `skipped`, `unsupported`. Apply = only selected `new` rows, all in **one** transaction with one version bump; returns counts per status. Never overwrites existing records.
- `ZoneFileWriter::write(CdnZone): string` — `$ORIGIN`, `$TTL`, apex NS (configured), records in deterministic order, TXT in presentation format. Round-trip property: `parse(write(z))` yields the same record set.
- Scan (auxiliary): common names queried **directly at the domain's current authoritative servers** (from `parentDelegation`), A/AAAA/CNAME/MX/TXT/CAA at apex, `www mail webmail ftp autodiscover autoconfig smtp imap pop cpanel webmail blog shop api m`, TXT `_dmarc`, DKIM selectors `default google selector1 selector2 k1 mail dkim s1 s2`. UI states clearly that scanning cannot find arbitrary names and recommends exporting a zone file from the current provider. The whole scan runs under **one** inspector budget (20 s / 80 queries, `DelegationInspector::batch`) and stops at `budget_exhausted` with `partial=true` (shown to the customer): the nameservers being scanned are chosen by the customer, so per-query budgets let one request hold a PHP worker for ~20 minutes.
- Preview cost is linear: conflicts are checked against a one-pass `RecordSetIndex` (same rules as `RecordValidator::conflicts`), and rows beyond the zone's remaining record capacity become `record_limit` without further work.
- Migration checklist (shown before NS change): ownership ✓, published & all nodes in sync ✓, records reviewed ✓ (button), MX/SPF/DMARC/DKIM/CAA present at current provider but missing here ⇒ warnings, parent DS ⇒ blocking. The email/CAA probe runs only before delegation: on the customer's throttled "Check nameservers" button, and hourly in `dns:check-delegation` when no result is cached. All of its queries share one budget (20 s / 80 queries, `DelegationInspector::batch`). The result is cached for 12 h, and page loads only read the cache, never DNS. DKIM uses the scan's selector list (TXT or CNAME). A read failure shows as "could not be read", never as "missing".

---

## 8. Product boundaries & compatibility

- Domain registration/transfer, `DomainZoneProvisioner`, hosting provisioning and billing are **not** modified. Registering a domain never creates a managed zone or changes its NS.
- If the customer has a hosting service whose `domain` equals the zone, the zone page shows: authority follows the parent delegation; while NS point to `ns1/ns2.servernet.*` the cPanel zone answers; after switching to managed NS, cPanel zone edits have **no public effect** and managed records must contain the hosting records (import offered). There is no automatic sync in either direction.
- Customer UI is fully trilingual (`lang/{fa,en,tr}/dns.php`), RTL/LTR correct, mobile-checked. No PowerDNS URL, key, server names of the primary, or raw provider errors are ever shown to customers.
- Rate limits: add/claim/verify 10/min, record writes 60/min, import 6/min, check-now 10/min. Quotas from config.

---

## 9. Lab results (PowerDNS 5.0.2, WSL, `infra/dns/lab/lab-up.sh`)

| Check | Result |
|---|---|
| New member zone on both secondaries (catalog + TSIG AXFR + reconciler) | 7 s |
| AA flag, UDP and TCP answers | yes |
| Update on both secondaries, identical serial | 4 s |
| Secondary down: other answers; after restart caught up | 3 s |
| Delete via API → removed from both (catalog) | 1 s |
| Unsigned AXFR to primary | refused |
| Recursion for foreign name on secondary | REFUSED |
| Catalog member without reconciler | never transfers (TSIG not inherited) |

The Laravel live test group `pdns-lab` repeats these through the real code when `DNS_LAB=1`.

---

## 10. Deployment decisions still open (owner)

1. Nameserver hostnames + the dedicated domain that hosts them (glue at registrar) — not invented here.
2. Where the hidden primary runs and where the two public secondaries run (Iran VM via DNAT on .118 needs live host review first; DE: cPanel PowerDNS occupies :53 on 65.109.176.14 and must not be reconfigured).
3. Permission for any production network change, registrar change or purchase.
