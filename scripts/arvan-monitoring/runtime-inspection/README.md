# Read-only Arvan runtime inspection

This standalone CLI tool reports the current local Arvan fleet, stored traffic-meter state, and bounded provider status evidence. It does not install monitoring, invoke a financial command, alter an exclusion, settle traffic, enforce a hold, or modify an application source file. There is no route, cron or scheduler integration.

## Run explicitly from the trusted application directory

```sh
php /private/reviewed/inspect-runtime.php --help
php /private/reviewed/inspect-runtime.php --self-test
cd /absolute/path/to/the/laravel/application
/absolute/path/to/native/php /private/reviewed/inspect-runtime.php --inspect --customer=<positive-id>
```

Replace the final placeholder with the requested customer ID. `--customer=17` is an example of the accepted syntax, not an existing account. Both `--inspect` and one positive integer customer ID are required. Either order is accepted. Zero, signs, leading zeroes, whitespace, overflow, repeated flags, unknown flags and combinations with `--help` or `--self-test` are rejected with exit 64 before filesystem access or application configuration loading. Help and the synthetic self-test also return before that loading.

The operator must select the real application directory; the tool requires `artisan`, `bootstrap/app.php` and `vendor/autoload.php` there. The repository's application directory is `website/`, while the deployed application can have a different path. Keep the executable and its output outside the public webroot, accessible only to the authorized operator. Runtime output includes private service/customer IDs and provider references and must never be committed, attached to a public issue or exposed through an HTTP route.

Fetch only a reviewed **commit SHA** of this single PHP file and verify its independently recorded SHA-256 before execution. A moving branch URL is not a release pin. Publication in Git, private staging, native PHP acceptance and production monitoring coverage are separate results. Do not use a diagnostic tool publication as evidence that servers were enrolled or traffic was charged.

## Read and transport boundaries

- The tool loads Laravel environment/configuration and registers only the framework database/encryption providers. It does not require `bootstrap/app.php`, boot application providers, construct a console kernel or run Artisan. Use only a trusted application/configuration tree.
- Cache is configured as a process-local array; no cached `Setting::get`, Eloquent model, query log or application error tracker is used. Logging is configured to a null handler in this process.
- A separate nonpersistent MySQL/MariaDB connection is used. Database URLs are parsed before an allowlist of PDO options is checked. Initialization commands and other unsupported options are rejected. Split read/write connections are rejected. The session is set to `TRANSACTION READ ONLY`, verified on the same connection, and a transaction is started before diagnostic reads. A query callback then accepts only `SELECT` statements. No commit is performed.
- Actual server flavor, rather than the configured Laravel driver name, selects the five-second statement timeout. Connection timeout is three seconds; lock wait timeout is three seconds. These are process/session settings, not persisted database configuration.
- At most 200 Arvan instance rows whose local status is not `deleted` are projected, including null/non-live/unknown statuses and invalid references. Finding a 201st row makes database completeness false. Duplicate references are marked ambiguous and not queried remotely. This is not an account-wide provider inventory or proof that orphan servers do not exist.
- Only fixed-origin HTTPS `GET /ecc/v1/regions/{region}/servers/{uuid}` requests to `napi.arvancloud.ir` are made, using the existing encrypted token in memory. The bounds are 50 requests, 60 seconds of provider work, two-second connection/four-second total timeouts and 256 KiB per response. TLS verification is required; redirects and ambient proxies are disabled. No retries, guest logins, SSH forwards, metrics endpoints, resource creation or provider mutations occur.
- Only response identity and an allowlisted normalized status leave the provider parser. Missing identity, non-200 responses, transport failure, exhausted budgets or unknown statuses remain unknown. No credentials, IP addresses, authorization text, API bodies, raw errors, SQL bindings or environment values are emitted.

## Interpret the evidence

`requested_customer_excluded` reflects the selected ID in the encrypted stored meter state. It does not change that state or resolve historical billing. A missing or unreadable setting is not proof that a customer is enrolled.

`last_success` is a meter receipt time, **not** evidence of fresh usage data. Stored financial coverage is projected only when all of these match: the encrypted state's last applied source hash, an `apply` receipt in `last-result.json`, and the SHA-256 of that receipt's bounded archived source file. A preview receipt, missing file, concurrent receipt change or hash mismatch produces unknown coverage. The tool does not fetch a new financial report or revalidate financial totals. It reports stored observation and per-resource current-cycle usage-window dates and their ages; missing resource evidence is null rather than zero. It does not decide an acceptable age or infer available credit.

Metadata flags indicate only whether monitoring/guard keys exist. They do not establish exporter installation, fresh scrapes, ownership binding, anti-tamper behavior or actual suspension. Provider-detail coverage, database-row coverage and account-inventory completeness are separate output fields. Even exit 0 (`diagnostic_complete_with_explicit_unknowns`) can contain unknown evidence. Exit 1 is an incomplete diagnostic; only a safe stage/class/code is returned.

No financial or control behavior is wired to this tool. Prospective enrollment, Windows metric mapping, provider-account inventory, source freshness policy, credit controls and historical reconciliation require separate reviewed implementation and acceptance.

## Offline verification

No Composer dependencies or application bootstrap are needed for these checks:

```sh
php -l scripts/arvan-monitoring/runtime-inspection/inspect-runtime.php
php scripts/arvan-monitoring/runtime-inspection/inspect-runtime.php --self-test
PHP_BINARY=php python3 -m unittest discover -s scripts/arvan-monitoring/runtime-inspection -p 'test_runtime_inspection.py' -v
```

The focused workflow executes these checks inside an empty network namespace after tool setup. Tests use synthetic values and temporary canary application directories; no database, provider credentials or production output is used. Native deployed PHP/Laravel acceptance remains an operator task.
