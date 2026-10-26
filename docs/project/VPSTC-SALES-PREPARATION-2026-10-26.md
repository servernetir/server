# VPS.TC reseller sales preparation — 26 October 2026

## Status

The owner requested continuation of the earlier connection through the signed-in Chrome portal, independent profit/tax/currency inputs, and the order-to-delivery lifecycle. The owner will set the financial inputs and test. Hourly sales must not be offered without supplier support.

This release implements the monthly integration and keeps new sales **closed by default**. An authenticated browser session is not an API credential. No supplier password has been extracted from the browser, no account credential was changed, and no paid supplier order was placed during development.

Observed on the signed-in supplier portal:

- The API origin is `https://portal.vps.tc`; the current official Swagger version is `4.20261019`.
- API authentication uses the client-area username/password, then a bearer token. A portal login alone does not configure the ServerNet backend.
- The portal describes auto-apply credit as a requirement and exposes a Credit Balance payment method. Runtime quotes independently verify the selected method and its fees.
- Visible shopping categories included Domains, LXC Container and Server Services. The observed LXC offering was annual-only. This is **not** a monthly VPS quote and is not imported or offered hourly. Account balances and private quotes are intentionally excluded from this public record.

Do not request a funding amount or advertise ready VPS sales from these public/portal prices. Read the authenticated account catalog and confirm the actual VPS products first.

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

- numeric product/payment IDs, `kind=vps`, `cycle=m`, exact CPU/RAM/disk/architecture/location and supported image/custom-option mappings;
- a SHA-256 of the current native forms; all selected options must have known zero additional prices/setup until paid options are separately modelled;
- an explicit native `availability.path` plus `boolean` or `positive_integer` interpretation, verified against the real response;
- enforceable network terms: `hard_cap` or verified `unmetered`, with no extra usage charges;
- verified `renewal_mode=manual_invoice_deduplicated` and `parent_cancel_stops_billing=true`. These require account-specific billing evidence; they are not substitute checkboxes for testing.

Changing the account, manifest, FX, tax, fee or profit invalidates VPS.TC's price snapshot. Global repricing cannot approve skipped supplier costs. Dedicated catalog refresh publishes proof only after its complete verified import succeeds; enabling sales also requires current prices, a valid mapping and a sellable in-stock plan.

## Remaining acceptance

1. The owner enters the VPS.TC password in ServerNet's secure connection form. The browser form was prepared with the verified API origin and account email; saving credentials remains incomplete until the owner supplies the password.
2. Read the actual authenticated catalog and resolve whether monthly VPS products are enabled for this account. Confirm invoice currency/tax/credit fields and native stock semantics.
3. Review and populate the account mapping, including traffic hard-cap/no-charge behavior, selected options, renewal-invoice deduplication and confirmed parent cancellation behavior.
4. Owner sets financial inputs; refresh only this supplier's catalog/prices. Determine required credit from the verified final test quote. No speculative top-up is required before these checks.
5. With an explicit test item and spending limit, test creation, exact resources, SSH/RDP access, power, renewal and billing cancellation. Verify supplier invoice/due dates and absence of continuing billing.

Lost supplier order responses without service/invoice receipts remain a manual reconciliation case because the order API does not establish an idempotency key or a unique hostname contract. This is intentional: missing evidence never authorizes another purchase. Current console access requires supplier browser-session context and is not exposed through a reusable white-label URL.

## Validation and publication

Development baseline: `8489da5b559ce02c7ce34f5ede5b0e086fb72850` (`develop`). Concurrent updates through `3857627` were inspected and must be preserved before publication.

PHP 8.4.27 and locked Composer dependencies were installed in an isolated checkout. All provider HTTP, mail, queue and billing test fixtures are isolated; real credentials were not loaded into the test runtime.

Final isolated acceptance: **157 tests / 1,056 assertions passed** on PHP 8.4.27, covering every `VpsTc*` test plus current Navasan snapshots, catalog repricing, payment callbacks and complimentary-service regressions. All 31 changed/new PHP source/test/view files passed lint. The file-release helper has 13 offline safety tests, with a separate complete 24-file candidate/lint check. Historical test results in `website/docs/cloud/vpstc.md` are not evidence for this release.

Known baseline: `CloudHourlyTermSupportTest` has four failures and two errors that reproduce at the initial base. The payment/lifecycle baseline batch has the same three CreditInvoicePayment failures and three BillingRenewalSafety errors on base and current code. They are not represented as passing by this work.

Deployment uses `scripts/deploy-vpstc.py`, exact source/target versions, private backups, hash guards, a shared release lock and drift-preserving patches. It does not change `.env`, dependencies, database schema, cron, credentials, account balance or supplier services. Existing source drift must be reconciled instead of overwriting whole application files. Sales stay closed until the account acceptance above is complete.
