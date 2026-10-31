# Compact owner support review — 2026-10-31

This overlay is based on the authenticated runtime `HermesSupportOwnerReview.php`
whose SHA-256 is `8e461e32422cf37e9cc215f5b6d0fbe067c58d72464a7be76c25e7118c2867fb`.
It is a narrow presentation/owner-review delta, not a replacement for the support
processor, diagnostics, knowledge or delivery/financial controls.

When `bale_manager_policy_v2_enabled=1`, the first owner notification is a bounded
preview with the exact ticket/customer, scoped recorded service/provider, latest
customer question, review summary and draft excerpt. The existing diagnostic
pipeline runs before this notification; no customer text is delivered by it.

The preview **does not** call `HermesSupportApproval::markNotified`. It records a
separate owner-preview token/draft hash so it cannot authorize delivery of text
the owner has not seen. Its `hsv:<token>` callback invokes `showFull(token,from,chat)`
and displays the exact full stored draft (in bounded parts when necessary), then
records the original full-view approval receipt. Only then does the existing
`hsa:<token>` exact-draft/source/owner guard allow delivery. `hsr:<token>` can reject
a compact preview without authorizing any send. Repeated approval remains
idempotent in the unchanged approval/delivery services.

Details use the existing `t:<ticket>` callback; manual replies use `tw:<ticket>`
and the existing human reply/confirmation flow. Incomplete technical reviews get
a short cause/action card without an approval button. Exhausted draft-generation
failures use safe reason labels, not provider exception bodies. Existing per-item
owner notice receipts preserve deduplication.

Gate-off preserves the previous full-card behavior. An edited/new proposal is not
silenced by a previous preview because its token and full draft hash must match.
The formatter never infers a service/customer from ticket prose: it reads the
exact customer's current message and the same-message recorded dossier only.

Source targets: `website/app/Services/Hermes/HermesOwnerNotification.php` and this
overlay to `app/Services/Hermes/HermesSupportOwnerReview.php`. The Router `hsv`
handler is an independent coordinated change; preserve the native `hsa`/`hsr`
handlers when merging the production Router. No `.env`, approval state, draft,
knowledge reference or customer content belongs in the package.

Validation prepared: `website/tests/Unit/HermesOwnerNotificationTest.php` and
Composer-only `website/tests/standalone/hermes-owner-review-v2.php AUTOLOAD ROOT`.
The latter uses unchanged State/Approval fixtures and synthetic ticket/gate/
sender/delivery boundaries, without bootstrapping the live application or sending
to a customer. Test execution and release acceptance are recorded by the parent
release, not inferred from this README.

`HermesOperations.php` delegates its daily card to the consolidated manager
digest while retaining immediate, fresh critical findings. Native complete
inventory evidence can report a closed service's remaining resource or a missing
service resource. Business findings require the exact service's current audit
row and the native `critical` severity: metering gaps, negative estimated margin,
credit runway under two hours and traffic at/above its allowance. Normal catalog
price changes, unknown balances/coverage, unassigned resources and warning-level
credit runway remain in the daily view. Estimated margin never claims supplier
invoice cost or confirmed profit/loss.

Up to three new incidents share one bounded owner message with source date,
customer/service/provider, cause and next action. Owner-scoped durable per-incident
attempt receipts ignore changing counters/timestamps. A changed cause/provider/
risk band or a recurrence after a fresh explicit native resolution can alert
again; dispatching/uncertain attempts are not automatically repeated. Gate-off
preserves the native digest contract. No billing, resource or customer actions
are performed. The independent Composer-only fixture is
`website/tests/standalone/hermes-manager-critical.php AUTOLOAD ROOT`.

## Limited file release helper

`deploy.py` takes an external reviewed JSON manifest so runtime-only overlays and
three-way merged production files can be pinned without replacing unrelated
native code. The manifest uses an ordered list (dependencies before consumers):

```json
{
  "release": "bale-manager-20261031",
  "files": [
    {
      "source": "website/app/Services/Notify/BaleManagerPolicy.php",
      "target": "app/Services/Notify/BaleManagerPolicy.php",
      "before": null,
      "after": "REPLACE_WITH_EXACT_LOWERCASE_SHA256"
    }
  ]
}
```

`target` is relative to `servernet_app`. Only PHP files under `app`, `resources`,
`routes`, `lang` and `database/migrations` are accepted. Every row needs the exact
live before hash (or `null` for verified absence) and source after hash. The final
manifest must be built after source freezes and live drift reconciliation; the
example is deliberately not executable. No target deletion is supported in the
forward release. Relay/n8n has a separate destination and release.

The private PHP caller holds the relevant native worker locks throughout all
phases and supplies `BALE_MANAGER_LOCKS_HELD=1` to the subprocess. The CLI is
account-bound to non-root `/home/servernetcloud`:

```text
python3 deploy.py prepare SOURCE_ROOT MANIFEST_JSON
python3 deploy.py apply SOURCE_ROOT MANIFEST_JSON
python3 deploy.py verify SOURCE_ROOT MANIFEST_JSON
python3 deploy.py rollback SOURCE_ROOT MANIFEST_JSON
```

This marker is a caller contract, not a security boundary. The helper also holds
both existing filesystem release locks. Preparation validates the full package,
backs up exact old bytes and stages/lints all new files in a private directory.
Apply repeats all source, target, backup and staging preconditions before any
installation. Newly required parent directories are created as 0755; existing
file modes are retained. Backups and receipts are 0600 under 0700 directories;
an already less-private backup parent causes a stop rather than an implicit
permission change. Symlinks, path traversal, duplicate targets, unexpected file
types and code/mode drift cause a stop.

Failed partial installation attempts an exact rollback. Rollback checks the
whole target set and recovery material first; a later code or permission edit
prevents all rollback writes. Only byte-identical files introduced by this
release can be removed. Empty directories created for those files remain.
Repeated completed phases are idempotent. An interrupted apply requires explicit
reconciliation or rollback rather than blind resumption.

The helper observes and never edits crontab. Cron drift stops prepare/apply/
verification; rollback can restore this release's files while preserving an
unrelated later cron change. It does not run migrations, set feature flags,
bootstrap Laravel, flush caches, contact providers, send messages or claim web
acceptance. Migration, policy enablement, OPcache activation, read-only app
acceptance and natural schedule observations remain separate release steps.

Offline validation executed for this helper: `python3 -m unittest discover -s
infra/hermes/bale-manager-20261031 -p test_deploy.py -v` — 28 tests passed. These
use temporary fake homes and injected lint/cron boundaries, with no production
paths or external calls.

`native-release.php` is the account-bound CLI caller for the fixed private source
`/home/servernetcloud/bale-manager-tests-20261031`. Its reviewed manifest is
`release-manifest.json` in this package. Modes are `deploy`, `schema`, `enable`,
`accept`, `rollback` and the explicit read-only provider operation
`observe-balances`. The file installer and schema step keep the new policy off;
`enable` separately requires all five evidence columns, an active bound admin,
an enabled Bale sender and readable core daily-report sources. It records the
exact original absent/off flag before installing, so rollback restores that
state after safe file recovery. Schema, stored evidence and backups are retained.

The caller holds `hermes:support-review`, `hermes:operations-review` and
`hermes:business-review`. It also obtains the actual `bale:work` scheduler event's
`CacheEventMutex` with `create($event)`, releasing it with `forget($event)` in
`finally`; it verifies the existing five-minute overlap contract and never runs
the event. The subprocess budget is under that expiry. The separate `schema.php`
owns its narrowly reviewed backup/migration steps; the caller does not invoke
global migrations or seeders. No future natural scheduler run is inferred from
the configured event expression. Preview acceptance records only its hash,
length and source availability; customer text and balances are not printed.
The private native audit receipt is separate from the file installer's receipt.
Framework mutex APIs were checked against the official Laravel 13
[CacheEventMutex API](https://api.laravel.com/docs/13.x/Illuminate/Console/Scheduling/CacheEventMutex.html)
and [Event API](https://api.laravel.com/docs/13.x/Illuminate/Console/Scheduling/Event.html).
