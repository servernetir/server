# Targeted read-only Arvan finance evidence

This standalone CLI gathers evidence for one explicitly selected customer, service, local instance and Arvan reference. It does not settle traffic, change an exclusion/checkpoint, enroll monitoring, create a server, invoke a financial command, or write an application/database/file. Its private stdout receipt contains small financial projections and an application-encrypted evidence envelope.

## Explicit invocation

Stage a reviewed, commit-pinned copy outside the public webroot and verify its independently recorded SHA-256. From the trusted Laravel application directory, invoke native PHP with all four identity flags:

```sh
php /private/reviewed/inspect-traffic-finance.php --help
cd /absolute/path/to/the/laravel/application
/absolute/path/to/native/php /private/reviewed/inspect-traffic-finance.php \
  --inspect --customer=17 --service=18 --instance=19 \
  --provider-ref=ir-example-1:11111111-2222-3333-4444-555555555555
```

These identifiers are synthetic examples. Replace them with the exact authorized tuple. Flags can be reordered. Unknown, missing or repeated flags, zero, signs, leading zeroes, whitespace and integer overflow exit 64 before application filesystem/configuration access. `--help` must be used alone; there is no `--apply`, `--initialize`, `--self-test`, arbitrary URL, transport override or historical-owner mode.

Redirect stdout to a **new private file** in an existing protected directory, with restrictive permissions and shell noclobber enabled. It contains private monetary amounts, IDs, hashes and encrypted evidence. Print only the summary fields needed for review; do not print the ciphertext into a terminal transcript unnecessarily. Never publish the receipt or paste it into Git, CI output, tickets or public logs. Keep the receipt and its hash retrievable for later review. The ciphertext requires the application's existing encryption key; no key is emitted.

## Minimal bootstrap and read-only boundaries

The tool loads only trusted Laravel environment/configuration, plus framework database/encryption providers. It does not load `bootstrap/app.php`, boot application providers, use Eloquent/cached settings, instantiate the console kernel or run Artisan. Cache is configured as an in-memory array and logging as a null handler for this process.

MySQL and MariaDB are supported. A separate nonpersistent connection rejects split read/write configuration and non-allowlisted PDO options after database-URL parsing. Before each identity phase, the tool refuses an open transaction, establishes session READ ONLY, REPEATABLE READ and bounded timeouts, begins a new transaction and verifies the same PDO connection, active transaction, isolation and read-only state. These checks repeat before each phase ends; connection replacement during a read fails closed. The first transaction is rolled back before remote reads; the second begins afterward. SELECT-only query guards remain active. There is no commit.

The exact identity tuple and API-token ciphertext must still match after remote reads. Duplicate provider references, deleted instances and cancelled/terminated/expired services are rejected. This proves the current local binding only, not provider power state or permission to charge. Image key/family and encrypted VM-credential presence are projected for the selected instance only. VM credentials are never read or decrypted, and presence does not prove password validity or Windows login/console readiness.

## Fresh complete supplier report

Only verified HTTPS GET requests to the fixed `https://napi.arvancloud.ir/resid/v1/` origin are allowed: current cycle, cycle detail, paginated cycle usage, cycle detail again, and current-cycle identity again. Redirects and ambient proxies are disabled. Bounds are 104 requests, 90 seconds of provider work, two-second connection/four-second total request timeouts, 256 KiB per response, two MiB total response/source bytes, 100 pages and 2,000 usage rows. A failure or changed pagination/cycle returns unknown; there is no retry or partial-success interpretation.

The tool validates **all products and monetary totals** in the complete current-cycle snapshot with the existing pure `ArvanTrafficSource::validate` method. Its reviewed file SHA-256 is pinned to `f242b2e5b0c2256749415fac4f277506a2ae0d4204f5bc83da1df51554916db9`; changed or differently resolved code is refused. It never calls that class's `fetch`, or a meter/settlement `run`, `initialize` or `apply`. Older/closed cycles are not fetched.

Only the selected unique instance `cc_internet` component is projected. Its supplier account hash must match all known meter-account hashes. Missing/ambiguous items, unknown ownership/account or invalid dates remain unknown with null evidence. `fetched_at` and `usage_to`/age are separate: fresh HTTP data can describe old usage.

Amounts have explicit units. `meter_price_basis_irr` is `price - discount`, matching the existing meter's basis; `supplier_net_irr` is `price - discount - usedGift`. Nonzero discount/gift still requires review and is not authorization to charge. The supplier's numeric `reported_amount` has **unknown unit** here; it is not labelled GB and is never a cutoff proof. Stable cycle totals do not prove immutable per-resource slices or isolate late historical repricing. `current_cycle_cutoff_proven` is always false.

## Customer ledger and historical evidence

The real credit table is `credit_ledger`. Evidence includes all selected-customer credits (positive and negative), invoices, every item belonging to those invoices, and payments, rather than only rows matching traffic keywords. Optional refund, business-ledger and typed traffic-audit relationships are reported with explicit table coverage. Caps are 2,000 credits, 500 invoices, 3,000 items, 2,000 payments, 500 refund requests, 500 audit rows and 3,000 linked business rows. Before loading rows, bounded SELECT aggregates check row counts and TEXT/JSON byte sizes; a cumulative conservative budget allows six bytes for text escaping plus 2,048 bytes per row and reserves two MiB for supplier data. The byte budget can reject fewer rows than the row caps. Exceeding any budget aborts complete evidence collection; rows and text are never silently truncated.

Plaintext summaries contain allowlisted statuses, monetary strings, IDs, canonical row/set hashes, keyword indicators and database date strings whose timezone is not assumed. They omit freeform titles/notes/descriptions, authorization text, payment authority/reference values, bank/card data, network addresses, passwords and raw API bodies. Payment references are represented by hashes and a traffic-owner/meter/other classification. Typed audit attribution is limited to the known invoice-subject traffic actions and exact selected customer/service/reference lines; matching a numeric subject ID alone is not attribution.

The encrypted envelope preserves the validated supplier snapshot, target identity/current checkpoint, and collected relevant finance fields, including private descriptions needed for historical review. Its plaintext SHA-256 and byte count are reported; the envelope is capped at four MiB before encryption. It contains no provider token, VM password, application key, bank snapshot, payment authority or raw error body. No archive file is created by the tool: the operator preserves the private stdout receipt.

Ledger balance is the sum of credit rows by currency, **not spendable credit after reservations/holds**. A wallet settlement that created a negative balance is already a charge; lack of cash is not permission to charge it again. Refunds, reversals and compensation are separate outcomes. Keyword flags, hashes, HTTP 200 and a successful diagnostic do not resolve disputed history, prove a clean prospective cutoff, acknowledge old rows or authorize rebilling. `history_semantically_reconciled` is always false.

Later enrollment must independently review the retrievable evidence and revalidate exact row membership/content and ownership under the existing meter/customer/service/instance locks and current reads. New, deleted or changed financial rows must remain detectable even when their text changes outside a traffic filter. Neither a global reinitialization nor blind removal of an owner exclusion is part of this tool.

## Verification and acceptance

```sh
php -l scripts/arvan-monitoring/finance-inspection/inspect-traffic-finance.php
PHP_BINARY=php python3 -m unittest discover -s scripts/arvan-monitoring/finance-inspection -p 'test_finance_inspection.py' -v
```

Fixtures import the actual pure source validator without Composer/application bootstrap and use synthetic complete multi-page financial data, canary application directories and database/PDO stubs. The focused workflow runs checks with PHP 8.4 in an empty network namespace. There are no real provider or database requests in these tests. Native deployed SQL/API/encryption acceptance remains an operator task. Exit 0 means evidence was collected; exit 1 means incomplete/unknown. Neither means enrollment, settlement or credit enforcement occurred.
