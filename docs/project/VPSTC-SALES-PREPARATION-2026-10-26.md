# VPS.TC reseller sales preparation — 26 October 2026

## Status

The owner requested continuation of the earlier connection through the signed-in Chrome portal, independent profit/tax/currency inputs, and the order-to-delivery lifecycle. The owner will set the financial inputs and test. Hourly sales must not be offered without supplier support.

This release implements the monthly integration and keeps new sales **closed by default**. An authenticated browser session is not an API credential. No supplier password has been extracted from the browser, no account credential was changed, and no paid supplier order was placed during development.

Production status: **24 runtime files installed and the new admin page verified in the authenticated browser**. Source runtime: `bbac6e628759dd980a689206d1c7ccd8cc151644` on `develop`; release overlays: `a9ae2398b84ba6329cf55f6d0df1aa30401f0285`. This is installed preparation, not accepted live supplier sales. See the [installed manifest and receipt](vpstc-sales-20261026/installed-manifest.json).

Observed on the signed-in supplier portal:

- The API origin is `https://portal.vps.tc`; the current official Swagger version is `4.20261019`.
- API authentication uses the client-area username/password, then a bearer token. A portal login alone does not configure the ServerNet backend.
- The portal describes auto-apply credit as a requirement and exposes a Credit Balance payment method. Runtime quotes independently verify the selected method and its fees.
- Visible shopping categories included Domains, LXC Container and Server Services. The observed LXC offering was annual-only. This is **not** a monthly VPS quote and is not imported or offered hourly. Account balances and private quotes are intentionally excluded from this public record.

Do not request a funding amount or advertise ready VPS sales from these public/portal prices. Read the authenticated account catalog and confirm the actual VPS products first.

## Authenticated account follow-up

After the owner saved credentials, read-only production requests on 26 October confirmed authentication and the nested VPS category. It contains 16 Regular monthly VPS offers across Istanbul and New York. The annual-only LXC products are a separate category. A Cloud offer is `Metered`, despite a cycle value of `m`, and is not a fixed monthly tariff.

Three supplier response problems prevent a safe live sale:

- `balance.details.currency` is the empty string. It cannot establish the unit of available credit or procurement quotes.
- Every payment fee response contains the literal string `Array` for both fee fields. Unknown fees cannot be treated as zero.
- For the same monthly VPS, the category amount is `125.55`, while the order form's raw monthly amount is `2.79`; both format the price as `₺125.55`. Official public HTML also exposes separate USD/TRY prices, which supports a base/display-currency explanation but does not supply an authenticated API currency contract. No conversion factor is inferred or applied.

The follow-up changes reject Metered and non-PrePay orders, require a reviewed numeric category ID, and compare category, order, recurring and cycle amounts before import or purchase. A read-only preview now traverses nested categories with request/data limits, displays observed products without importing them, and reports currency, fee and price disagreements. Existing encrypted credentials, financial inputs and the sales flag are unchanged.

These changes use the three-file `deploy-vpstc-live-contract.py` wrapper, based on installed runtime `bbac6e628759dd980a689206d1c7ccd8cc151644`. It retains the pinned core's locks, private backups, hash preconditions, lint, plan/apply/rollback and refusal to overwrite later changes. Source validation and production acceptance of this follow-up are recorded separately below; the original 24-file release remains historical evidence.

Follow-up isolated validation: **173 tests / 1,207 assertions passed** on PHP 8.4.27 (all `VpsTc*`, Navasan snapshot, catalog repricing, payment callback and complimentary-service suites). Six changed PHP source/test/view files linted successfully. Seven focused offline tests passed for the three-file deployment wrapper. This test result does not prove a paid supplier lifecycle or authorize sales.

## Implemented behavior

- Encrypted credential intake remains at `/admin/cloud/vpstc`; credentials are neither redisplayed nor flashed after validation errors.
- Separate inputs: supplier currency, native-currency-to-toman conversion, supplier purchase tax, funding/FX fee and profit. TRY requires a manual toman-per-lira value. USD/EUR/USDT can use valid current Navasan rates when the manual rate is blank. Invoice customer VAT remains separate.
- Exact decimal procurement calculation, upward rounding, explicit zero percentages and no inherited global margin/FX fee for incomplete VPS.TC inputs. Setup uses this supplier's margin as well.
- Reviewed account-bound catalog mappings retain product resources, location, image selection, traffic and billing contracts. They are not inferred from marketing descriptions. Fresh API forms, selected option costs, monthly prices/setup, currency, payment fees and native availability are verified before import or purchase.
- Monthly-only checkout and provider enforcement. Annual-only products are rejected; the global monthly/720 fallback and forged hourly cost never authorize VPS.TC hourly sales. Other suppliers keep their existing supported periods.
- Product-scoped OS references, separate monthly/network offer identity, and hard traffic labels unaffected by the global unlimited-traffic display option.
- Durable encrypted operations are written before supplier order, credit, power, cancellation or renewal mutations. Ambiguous responses are reconciled without replaying a paid mutation.
- Delivery requires the exact active parent, verified paid linked invoice, a built/ready VM, address and usable access credentials. Passwords are stored encrypted; status-cache responses do not include them. Polling preserves access metadata and the immutable supplier due-date anchor.
- Parent cancellation must be confirmed Cancelled/Terminated. Deleting a VM is not accepted as stopping parent billing. Unsupported console, resize, snapshots and password reset are not advertised as supported.
- A paid retail monthly renewal queues a supplier operation after the outer transaction commits. The local period advances only after linked invoice payment and the supplier due-date change are verified. Existing supplier-period advances and uncertain receipts stop for review instead of buying an extra month.
- The existing lifecycle schedule reconciles pending renewals; no new cron or general queue execution is needed.

## Catalog acceptance contract

`vpstc_catalog_manifest` is an internal reviewed mapping; it is not a user-facing product form or an automatically trusted upstream instruction. The envelope contains the current `VpsTcApi::accountFingerprint()` and a nonempty list of products. Every row must have:

- numeric product/category/payment IDs, `kind=vps`, `cycle=m`, exact CPU/RAM/disk/architecture/location and supported image/custom-option mappings;
- a SHA-256 of the current native forms; all selected options must have known zero additional prices/setup until paid options are separately modelled;
- an explicit native `availability.path` plus `boolean` or `positive_integer` interpretation, verified against the real response;
- enforceable network terms: `hard_cap` or verified `unmetered`, with no extra usage charges;
- verified `renewal_mode=manual_invoice_deduplicated` and `parent_cancel_stops_billing=true`. These require account-specific billing evidence; they are not substitute checkboxes for testing.

Changing the account, manifest, FX, tax, fee or profit invalidates VPS.TC's price snapshot. Global repricing cannot approve skipped supplier costs. Dedicated catalog refresh publishes proof only after its complete verified import succeeds; enabling sales also requires current prices, a valid mapping and a sellable in-stock plan.

## Remaining acceptance

1. Credentials were saved by the owner and authenticated reads succeeded. No credential extraction is required.
2. Resolve the supplier's empty account currency, malformed payment fees and inconsistent category/order amount units. The authenticated catalog confirms monthly VPS products; this alone does not verify safe procurement prices. Confirm invoice currency/tax/credit fields and native stock semantics.
3. Review and populate the account mapping, including traffic hard-cap/no-charge behavior, selected options, renewal-invoice deduplication and confirmed parent cancellation behavior.
4. Owner sets financial inputs; refresh only this supplier's catalog/prices. Determine required credit from the verified final test quote. No speculative top-up is required before these checks.
5. With an explicit test item and spending limit, test creation, exact resources, SSH/RDP access, power, renewal and billing cancellation. Verify supplier invoice/due dates and absence of continuing billing.

Lost supplier order responses without service/invoice receipts remain a manual reconciliation case because the order API does not establish an idempotency key or a unique hostname contract. This is intentional: missing evidence never authorizes another purchase. Current console access requires supplier browser-session context and is not exposed through a reusable white-label URL.

## Production acceptance and rollback

The ordinary plan first stopped with zero application writes when it encountered live checkout changes. Exact live controller/view snapshots were reviewed; `deploy-vpstc-reviewed-overlay.py` adds only the VPS.TC rules and verifies both original/candidate hashes plus reversible replacements. Existing procurement floors, add-on billing, Iran-sales controls and the newer UI remain intact. Four files contain preserved production drift: PaymentService, CloudStoreController, the customer cloud-store view and routes/web.php.

The final hash-guarded plan installed 24 files with private backups. No migration, seeder, cron, global cache clear, supplier order, credit payment or service action ran. The existing provider-operations table was verified before installation. The helper itself performs no application/cache activation; subsequent web requests verified that the new code/routes/view were loaded normally.

Fresh acceptance after installation:

- Authenticated `/admin/cloud/vpstc` rendered the independent pricing, catalog refresh and guarded sales controls.
- `/account/cloud-store` rendered the retained customer UI and produced no captured JavaScript errors.
- Public `/up` returned HTTP 200 on both servernet.cloud and console.servernet.cloud.
- Runtime loaded the registered VPS.TC driver; credential configuration, pricing configuration and sales were all false. No VPS.TC plan, instance or operation was created.
- The isolated production overlays passed 71 tests / 331 assertions, full Blade compilation, three-language billing controls and JavaScript parsing. Six additional focused overlay checks passed against the exact runtime source.

Rollback is file-only and uses the private release directory matching `vpstc-release-bbac6e628759-*`, its `plan.json`/`rollback-plan.json` and the saved receipt. Run the same overlay helper with the exact target, root, private release directory and `--rollback <plan_sha256>`. It refuses to overwrite newer file hashes. Preserve the owner's credentials and settings if they are supplied after this release; no financial/provider state is undone by restoring files.

## Validation and publication

Development baseline: `8489da5b559ce02c7ce34f5ede5b0e086fb72850` (`develop`). Concurrent updates through `3857627` were inspected and must be preserved before publication.

PHP 8.4.27 and locked Composer dependencies were installed in an isolated checkout. All provider HTTP, mail, queue and billing test fixtures are isolated; real credentials were not loaded into the test runtime.

Final isolated acceptance: **157 tests / 1,056 assertions passed** on PHP 8.4.27, covering every `VpsTc*` test plus current Navasan snapshots, catalog repricing, payment callbacks and complimentary-service regressions. All 31 changed/new PHP source/test/view files passed lint. The file-release helper has 13 offline safety tests, with a separate complete 24-file candidate/lint check. Historical test results in `website/docs/cloud/vpstc.md` are not evidence for this release.

Known baseline: `CloudHourlyTermSupportTest` has four failures and two errors that reproduce at the initial base. The payment/lifecycle baseline batch has the same three CreditInvoicePayment failures and three BillingRenewalSafety errors on base and current code. They are not represented as passing by this work.

Deployment uses `scripts/deploy-vpstc.py`, exact source/target versions, private backups, hash guards, a shared release lock and drift-preserving patches. It does not change `.env`, dependencies, database schema, cron, credentials, account balance or supplier services. Existing source drift must be reconciled instead of overwriting whole application files. Sales stay closed until the account acceptance above is complete.
