# Arvan provider report diagnostics — 3 November 2026

This draft adds an unwired, disabled source for one read-only provider chart.
It does not establish traffic billing, source freshness, guest-independent
collection or an automatic spending limit. Existing financial settlement and
guest-counter observation remain separate.

## Verified contract and remaining unknowns

The public official [IaaS v1 specification](https://www.arvancloud.ir/api-docs/iaas-1.0.json)
was retrieved on 3 November 2026: 188439 bytes, SHA-256
`4601276cb83a2cf7c3a0c14df15b36312a2d9573989558a4dc60449f5917e801`.

| Contract | Evidence |
|---|---|
| GET `/ecc/v1/regions/:region/reports/:id` | `GetServerReports` in the official v1 specification |
| Period examples `1m`, `1h`, `1d` | Specification declares the period as a path parameter despite omitting it from the route; historical application audit observed HTTP 200 with query `?period=1d` |
| `ServerReports.charts.network` | A `Chart` with string `title`, string-array `categories` and `ChartDataset[]` `series` |
| Dataset | String `name`, array of double numbers `data` |
| Authentication | Official [API usage guide](https://docs.arvancloud.ir/fa/developer-tools/api/api-usage/) uses the machine-user key as the Authorization value; the existing stored value is preserved verbatim |
| Units, direction viewpoint, cumulative/rate semantics, aggregation, timestamp timezone/format, maximum lag, guest tamper resistance | Not specified by this schema; not assumed by this implementation |

The official [v3 YAML](https://www.arvancloud.ir/api-docs/iaas-3.0.0.yaml)
was retrieved as 189973 bytes, SHA-256
`988b037a4527cbe7dd83680fe96a1f88001b2a253b11cedb288b697642139342`.
A case-insensitive text search for report, metric, monitor and bandwidth found
no matches. This is not a claim that the provider offers no other monitoring API.

Historical repository evidence is in
[the revenue audit](ARVAN-REVENUE-COVERAGE-AUDIT-2026-10-25.md) and
[the sales acceptance report](ARVAN-SALES-MONITORING-ACCEPTANCE-2026-10-27.md).
They recorded `charts.network` incoming/outgoing values with time categories,
without establishing units or billable volume. No raw historical chart fixture
was available in this checkout. The tests are explicitly synthetic and are not
a production response acceptance test.

## Bounded implementation

`ArvanProviderReportSource` defaults to `enabled=false`; a disabled read does
not consult identity, credentials or transport. There is no container binding,
scheduled command, endpoint, UI, settings write, migration or automatic activation.
`ArvanClient.metrics()` and its advertised capability remain unchanged.

An explicitly enabled caller must supply an independent current identity reader.
The exact service ID, local instance ID, customer ID, provider=`arvan` and region:UUID must match
before and after the request. The caller must re-read the authoritative current
mapping, reject duplicate references and avoid stale models. A single fixed-origin
HTTPS GET requests `period=1d`, preserves the stored Authorization prefix, refuses
redirects, explicitly requires TLS verification and uses bounded time/body reads. Errors return fixed reason codes;
response envelopes, statistics, authorization and exception messages are not logged
or projected. The injected transport is for trusted tests, not request input.
An explicit approved read-only credential reader is also required. This source
does not call `Setting::getSecret()`, which may populate shared cache on a miss.
An empty read before EOF is rejected even after a complete-looking JSON prefix.

`ArvanProviderReportObservation` accepts a raw or unambiguously data-wrapped
network chart with at most 2048 categories and eight series inside 256 KiB.
It rejects missing points, null/non-numeric/nonfinite values, duplicate series,
invalid labels and malformed data. It preserves finite chart numbers and labels
without treating them as byte counters or interpreting signs as direction.
The observation is tied to the requested reference; the schema supplies no
instance identity echo. Local identity checks are explicitly distinguished from
provider identity verification.

Every accepted result is `diagnostic_only`: `source_freshness=unknown`,
`sample_at=null`, `unit=null`, `traffic_used_gb=null`. Collection time is labeled
as local fetch time. Re-fetching the same old chart cannot produce a fresh sample.
The chart digest is only a diagnostic fingerprint. There is no saved checkpoint,
rate integration, monthly sum, financial action or lifecycle action. These fields
must not be used to satisfy a traffic guard or a delivery acceptance condition.

## Next acceptance before operational wiring

Collect a minimal private sample of title/series/category shape from the same
documented endpoint and obtain provider confirmation of units, direction,
aggregation, source sample time, maximum reporting delay and collection origin.
Provider API access alone does not prove a root customer cannot alter the source.
Do not derive GB or money by adding chart points. Do not use poll time as freshness
or reuse the guest exporter's 150-second rule for an undocumented provider feed.

Wiring a current identity reader, durable operational state, polling, alerts and
stale-data policy needs a separate reviewed change. Any future power control also
needs provider-confirmed final state and protection against bypass through ordinary
power-on paths. Global provider/collector outages must not silently become zero use
or mass customer suspension. Official finance usage items remain the settlement
source, with their own idempotency, owner exclusion and reconciliation rules.

## Validation and rollback

Run `php scripts/arvan-monitoring/test-provider-reports.php` from the repository
root. The suite loads only these two classes and an in-memory HTTP facade, with
no Composer, Laravel boot, database, secrets, provider or guest requests. The
focused workflow repeats lint and these tests on PHP 8.4 with tests in an empty
network namespace. It does not install or activate monitoring.

Before this source is wired, rollback is removal/reversion of the five new files;
no state, financial entries, services or scheduled jobs are changed by this patch.
Git publication and passing synthetic tests do not mean production installation.
