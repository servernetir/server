# Shared hosting upgrades — 5 November 2026

Base: `9c3d8732336f4c2ae6c7c016818dd5c1b975a511` (`develop`).
Local branch: `feature/hosting-self-upgrade`; remote PR branch:
`feature/hosting-self-upgrade-ready`. Integrated concurrent develop
`d81163c1ff0ec084b5e0634a3895541f2f8a5778`; both project-index entries and
concurrent Invoice/domain route/schedule changes were retained.
Status (7 November): **installed in production; real customer preview verified**.
See [the production release record](SUPPORT-HOSTING-RELEASE-2026-11-07.md).

## Follow-up — 7 November 2026

Integrated current develop `5a9d28c06c95f333d4bd560c2b51e1dd5866023c`
without replacing concurrent traffic, domain or social changes. The two remaining
payment-review findings are addressed:

- The gift-coupon endpoint checks the hosting-upgrade deadline again under the
  invoice row lock, before claiming or consuming a coupon. Wallet and gateway
  protections remain in place.
- After the deadline, partially paid upgrades enter explicit support review.
  The scheduler includes both `unpaid` with a positive paid amount (the actual
  settlement engine's partial-payment representation) and legacy `partial`
  invoices. It rechecks the invoice under a row lock. No panel mutation,
  cancellation, gift-to-wallet conversion or automatic refund occurs. Payment
  and coupon provenance and the open service slot remain until support resolves
  the received payment. Both customer pages show the amount, invoice number and
  a direct support action. This is an operator reconciliation path, not an
  automatic refund feature.
- Test tracker cleanup uses ordinary per-directory globs, preserving cleanup on
  PHP runtimes that do not expose the optional libc `GLOB_BRACE` constant.

Fresh local validation on PHP 8.4.12: **88 tests / 464 assertions passed**
(upgrade, Plesk, panel selection/account, admin deletion, gift coupon, discount
visibility and payment callbacks). The three-language preview fixture separately
passed. Local Chromium launch was attempted and failed at its singleton socket
with `Operation not permitted`; no local rendered-browser success is claimed.
The dedicated CI includes SQLite/MariaDB and its rendered Chromium matrix; its
new run must pass before merging this revision.

Production follow-through: PR #267 merged as
`95f9733bd95873ac9705d2a501711b885a751e7e`; all 15 associated workflow runs
passed. The hosting-specific CI passed SQLite, MariaDB and rendered fa/en/tr
desktop/mobile dark/light previews. The bounded 19-file runtime overlay and
single new-table migration were installed with private backups and drift
guards. The real customer hosting card, compatible plan list and prorated
quote render in the production browser. No real upgrade invoice or payment
was created for testing; the customer's actual paid upgrade remains their
next action. The operational record distinguishes provider reads from a real
paid fulfillment.

## Scope and customer contract

Ordinary Windows/Plesk and Linux/cPanel hosting can move to a higher compatible
catalog plan on the same server and account. No account recreation or file
migration. Resellers, DirectAdmin, cloud/storage, internal infrastructure,
external/unverified billing, non-IRT billing and cross-family/location changes
are excluded. The exact source package must resolve uniquely to a catalog
product. Destinations require higher catalog rank and cycle price, matching
category, currency, location, panel type and server pinning.

Authenticated fa/en/tr account routes:

- GET `/account/services/{service}/upgrade`: list and selected-plan preview.
- POST at the same path: create invoice and redirect to normal payment.

Both inherit normal account/IP/CSRF protection; ownership is checked before any
lookup or panel access. Responses are private/no-store. The UI shows full new
cycle price, elapsed/remaining days, unused old-plan credit, prorated new-plan
cost, difference, tax and total. Before invoice creation, remote preflight must
confirm the bound account, exact source plan and available destination plan.

The old rate is the service's contracted cycle price. The new rate uses existing
`Product::priceForCycle`, including cycle discount and location pricing. Prorated
components and tax use integer currency units, remaining seconds and half-up
rounding. There is no setup charge or extra full-cycle charge. Calendar steps
are replayed from activation using renewal's no-overflow behavior; inconsistent
manual anchors fail closed. The end is `RenewalTerms::serviceDeadline`, including
the full local paid due day in the configured billing timezone. Early renewal
is supported: the remaining current cycle and every fully prepaid future cycle
are prorated separately using their own calendar lengths. Displayed day
fractions are approximate; amounts do not use those rounded labels.

The complete preview is signed, stored in session, valid for 15 minutes and
revalidated against service/catalog state. Its exact reviewed amounts become
the invoice. Payment deadline: 24 hours or the existing service deadline,
whichever is earlier. Late or mismatched paid invoices require reconciliation.

## Financial isolation and fulfillment

The only new schema is `hosting_upgrades`: immutable quote, unique invoice,
nullable unique open-service slot, statuses and durable attempt/check/completion
timestamps. The service row lock and unique slot serialize duplicate requests.
Invoice kind is `hosting_upgrade`; `service_id` is deliberately null, so payment
settlement cannot extend/suspend the existing host. The upgrade record and invoice
line bind it to the service. Normal gateway/wallet settlement, cancellation,
revenue and invoice expiry remain in use. Upgrade lines are not marketing-
eligible. An expired upgrade invoice cannot start payment. The wallet endpoint repeats
this check under the invoice lock before debiting, even for a stale open form or
a partial invoice. Upgrade invoices and their customer audit records cannot be
hard-deleted; normal cancellation remains available.

`hosting:upgrades` runs each minute with scheduler/per-service cache locks;
unresolved checks are spaced five minutes apart. Only a fully paid, correctly
bound invoice reaches the provider. A changed fingerprint or outstanding service
invoice (including a concurrently created renewal) requires support review.

An attempt is committed BEFORE the external mutation. Subsequent crash/timeout
recovery is read-only: it may finish a confirmed remote change but never resend
the mutation. Unknown ownership/package, Plesk locked/unsynced or add-on ambiguity,
and unapplied settings cannot be successful. `review` retains the unique slot
and creates an internal error event. Attempted review records continue read-only
reconciliation. Do not clear `attempted_at` to force a retry.

Exact remote readback must confirm account/domain, target package, and Plesk
subscription owner/login/sync. Then one transaction updates package, display
name, recurring price and Plesk plan metadata, records activity and completion,
and releases the slot. Renewal date, domain, server and credentials stay fixed.
An upgrade invoice is never a reason to suspend a paid host.

Provider references:

- [Plesk plan switch](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/switching-a-subscription-to-a-different-service-plan.66391/)
- [Plesk subscription states](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/subscription-settings/subscription-statuses-and-associated-plans.66383/)
- [WHM API privileges](https://api.docs.cpanel.net/guides/guide-to-whm-plugins/guide-to-whm-plugins-acl-reference-chart)

## Validation and acceptance limits

Local: isolated PHP 8.4.27, existing Composer lock, SQLite in-memory and fake HTTP
resources. Final local regression: **67 tests / 312 assertions passed**. Seventeen changed
non-Blade PHP files passed syntax checks; the workflow YAML and browser-test
JavaScript parsed successfully.
Coverage includes arithmetic/discounts/calendar/Tehran due day, ownership and
scope, signed previews, wallet payment, duplicate callbacks, one remote change,
Plesk XML, timeout reconciliation, cancellation/late payments and renewal races.
New PHP is Pint-formatted; JS syntax, workflow YAML and Git whitespace checked.

Three failures in the historical domain `CreditInvoicePaymentTest` were
reproduced at the unchanged base. The new hosting invoice's real wallet endpoint
passes. This release does not alter that unrelated domain pricing baseline.

Local Chromium was unable to launch because the environment denied its singleton
socket. No local visual acceptance is claimed. The dedicated PR workflow runs
SQLite and MariaDB 11.4 acceptance plus actual rendered review-form checks in
fa/en/tr, dark/light and 390/1440px Chromium. A workflow definition alone is not
evidence of a successful run; consult the linked run for actual results. The
previous revision passed both database jobs and 12 rendered Chromium cases
(three locales, two themes, mobile/desktop); the final review fixes rerun these
checks. Native headless DOM export avoids a flaky debugging-socket startup.

The existing callback suite deliberately writes an invalid date into a datetime
column to simulate a settlement fault. It runs on SQLite only; MariaDB correctly
rejects that fixture at insertion. The new upgrade tests clear configured real
internal-service IDs so MariaDB's non-transactional sequence allocation cannot
accidentally designate a synthetic hosting row as internal infrastructure.

No production invoice, payment, reply or customer package mutation occurred.
The cPanel terminal selected for release was owned by another browser session;
it was not taken over or used for deployment.

## Limited installation and rollback

Follow `DEPLOYMENT.md`: exclusive coordination, fresh destination inventory,
private backups and before/after hash guards. Reconcile concurrent routes,
translations, Invoice model and Plesk client edits rather than replacing them.
App paths under `website/app`, `routes`, `lang`, `resources`, plus the ONE new
migration map to matching `servernet_app` paths. There are no public assets or
new dependencies. Tests/docs/CI are not deployment targets.

Install the new migration file and execute only its reviewed path through the
normal authorized migration procedure. No existing migration, relationship,
balance or invoice is migrated. Until the table exists, the button and command
fail closed and existing orders continue. Activate only the affected route and
three Blade views with the approved targeted procedure, without flushing shared
caches. Confirm the natural cron recognizes the schedule.

Live acceptance first requires read-only inspection of actual existing accounts
and destination packages and the real customer preview. Do not create/pay a
customer invoice or mutate a package as a smoke test. Full paid live acceptance
requires an authorized account/invoice scope and has not occurred here.

Rollback: stop new upgrade requests/button and command first. Preserve the table
and financial records. Restore only guarded release files after checking for
newer changes. Reconcile all paid/attempted upgrades before removing the processor.
Do not drop the table or downgrade remote accounts as a code rollback.
