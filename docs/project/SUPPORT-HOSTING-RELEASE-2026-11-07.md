# Hosting upgrade production release — 7 November 2026

## Production state

The owner requested ticket replies, repairs and completion of self-service
shared-hosting upgrades. PR [#267](https://github.com/servernetir/server/pull/267)
merged to develop as `95f9733bd95873ac9705d2a501711b885a751e7e`.
The upgrade runtime is **installed; its real customer preview is verified**.
No customer invoice or payment was created as a test.

Runtime base: `5a9d28c06c95f333d4bd560c2b51e1dd5866023c`.
The release overlaid 19 reviewed files onto the active `servernet_app`, using
three-way merges for six files containing live changes. It did not replace
the application checkout. All three existing release locks were held;
before/after hashes and a rollback receipt remain in the private release
directory `hosting-support-20261107/upgrade-release-v2`.
Plan SHA-256:
`51c09487eabfda1ead57b0bc255823f6fc8e7f986b266d8a855820e402e0dcce`.

Before installing, all non-Blade PHP files passed native PHP 8.4 lint. The
three changed Blade views were compiled and linted with the live compiler.
A private consistent database dump was gzip-verified. Only the reviewed
`2026_11_05_180000_create_hosting_upgrades_table` migration was run; no other
pending migration or seeder was executed. Routes and configuration were not
cached; no broad cache flush or manual scheduler execution was used.

Observed production acceptance:

- The real hosting card exposes the upgrade button. The customer-owned GET
  page lists compatible higher plans and renders a quote with used/remaining
  days, old-plan credit, new-plan debit, tax and final amount.
- Provider inspection confirms the existing bound Plesk subscription is in
  the verified source state for its requested next plan. The current account,
  original plan and renewal date remain unchanged; no test invoice exists.
- `hosting:upgrades` is registered every minute and the existing account
  crontab invokes the scheduler once per minute. No duplicate cron was added.
- Full production payment/fulfillment was not exercised. Idempotent payment,
  provider switching, expiry and partial-payment review were tested with
  fixtures; the customer can now choose and pay their actual upgrade.

## WHM Iran and Germany acceptance — 7 November

The owner explicitly requested parity for cPanel hosting on both delivery
servers. At 10:27–10:28 UTC, the active production application was inspected
through its normal Laravel bootstrap and existing encrypted WHM credentials.
Both nodes were active and returned successful native package inventories.
Every upgrade destination offered to an eligible ordinary shared-hosting
service existed on that service's own node; no missing target package was
found. No native package creation or configuration change was needed.

| Location | Native account preflight | Customer panel acceptance |
|---|---|---|
| Germany | Exact existing account/domain/source plan verified; LX-5 → LX-10 target present on the same node | Hosting-card upgrade action, higher-plan list and prorated annual quote verified |
| Iran | Exact existing account/domain/source plan verified; LX-2 → LX-5 target present on the same node | Hosting-card upgrade action, higher-plan list and prorated monthly quote verified |

These were read-only production previews. The final invoice/payment button
was not submitted; no real upgrade invoice, payment or package change was
created for testing. Each inspected service had zero upgrade records before
the preview. The normal impersonation flow was exited after each inspection.
The already-installed shared upgrade runtime covers both WHM nodes, so this
follow-up does not introduce another runtime overlay or migration.

The WHM fulfillment regression now runs independently for Iran and Germany
with distinct fixture API origins and node/location-pinned destinations. It
asserts that every provider read and package change uses the service's own
origin, exactly one change occurs, and the account and due date remain fixed.
Two additional cases verify that a missing destination on either node never
attempts a package change and leaves the paid upgrade awaiting verified
panel access. The existing exclusions for reseller, external/unverified
billing and catalog/panel mismatches remain in force.

Fresh local PHP 8.4.12 regression: **91 tests / 504 assertions passed** across
hosting upgrades, Plesk integration, panel selection/account, deletion, gifts,
discount visibility and payment callbacks. Pint and Git whitespace checks
passed. The associated PR's hosting CI provides the fresh MariaDB/rendered
results; local SQLite alone is not represented as a MariaDB run.

## Validation

- PHP 8.4.12 locally: 88 tests / 464 assertions passed across hosting upgrades,
  Plesk integration, panel selection/account, deletion, gifts, discounts and
  payment callbacks. A separate three-language preview fixture passed.
- All 15 workflows at feature head
  `05df5cb6e78305b865485dbbe76ff6d9903d599f` passed. The dedicated
  [hosting CI run](https://github.com/servernetir/server/actions/runs/42357126062)
  includes SQLite, MariaDB 11.4, callback fixtures and rendered Chromium
  fa/en/tr desktop/mobile dark/light previews.
- The scoped reseller-limit correction below passed 14 provisioning tests /
  44 assertions. The full 16-test historical file still has two ordering
  fixture errors (`No query results for model Service`); both were reproduced
  from the unchanged develop test file. They are not reported as passing.

## Reseller repair and prevention

The affected WHM reseller had an empty privilege line, and the configured
`servernet_reseller` ACL list did not exist on that delivery server. A bounded
38-privilege list was created and assigned to the exact affected account.
It excludes root/global administration, shell, dedicated-IP creation and
`edit-account`/quota privileges that could bypass the purchased limits.
The customer identity and working package-creation page were verified after
signing in as the reseller. Other accounts were not modified.

A second defect set the traffic companion quota to zero while WHM's joint
resource-limit switch was enabled. This can prevent account creation even
with sufficient disk space. The live affected account now retains its account
and disk limits with the large numeric traffic companion recommended by
[cPanel's documentation](https://docs.cpanel.net/whm/resellers/edit-reseller-nameservers-and-privileges/#limit-by-resource-usage).
`WhmClient::setResellerLimits` maps an unspecified companion resource to
`99999999` MB when the other resource is finite. It keeps a genuinely
configured finite limit, and does not enable the joint switch when neither
resource is limited. This is a technical large quota, not a literal unlimited
value accepted by WHM. No root/all ACL or resource-overselling workaround is
used.

The prevention mapping is also installed as a separate guarded one-file
runtime overlay, with native PHP lint and a private before copy. Installed
`WhmClient.php` SHA-256:
`0b68ecedf21069ad63d63be10e5e928c7f4bd3c039bdfbb225c2597381612e2b`.

## Independent support operations

- The four requested Plesk sites now have separate IIS pools, each verified
  `Started` in native IIS; the previously repaired API pool remains started.
  An IIS configuration backup was created first. The existing Windows plans
  2–5 now retain the per-site dedicated-pool default during forward upgrades.
  After comparing existing settings with the source plan, the affected
  subscription was unlocked **without synchronization**; application settings
  were not rolled back by an unlock-and-sync operation.
- A separately authorized full cPanel restore completed once. The requested
  archive and a fresh pre-restore backup both passed gzip integrity checks.
  Native restore reported success, all 68 dump tables exist and passed native
  database checks, 16,408 site files match archive bytes, and the restored
  homepage responds without a WordPress fatal/database-connection error.
  The one changed `.htaccess` is not claimed as byte-identical. Backup files
  and private operational logs remain on the owning server.
- Customer replies distinguish completed work from provider quotes or manual
  third-party subscription activation that still require follow-up. No
  customer credential, private ticket body or raw operational log is included
  in this public record.

## Rollback boundaries

Restore only matching installed file hashes from their private before copies;
preserve any later colleague edits. Keep the additive upgrade table and any
financial records that arrive after release. Do not restore the whole database
or auto-refund payments to roll back the UI.

The reseller and IIS ticket repairs are independent of the web release. Do
not undo those verified customer repairs merely when rolling back application
files. A reseller ACL rollback must target only that reseller, preserving
other reseller lines and quota changes. Native backups/receipts are private.
