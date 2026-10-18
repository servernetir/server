# Exact owner diagnostic lifecycle — offline preparation

`owner-test-20261018.php` is **not a general deployment or storefront tool**.
Its fixed authorization scope is one new Windows diagnostic named
`sn-owner1-windiag-20261018`, region `ir-tbz-sh1`, customer1/code `SN-457136`,
maximum120,000toman. It never targets historical456/491/501, changes sales or
console flags, runs the full provisioner delivery, contacts support, creates
an image/snapshot/second VM, or claims automatic password acceptance.

No production execution, cron installation, or resource operation has been
performed by the helper's authoring/tests. Running the PHP file from the checkout
fails its private-path guard. Including it defines classes only: no Laravel,
host reads, filesystem writes, or network calls. Pure test:

```powershell
php scripts/arvan-windows-bootstrap/Test-OwnerLifecycle.php
```

## Private installation contract (owner/operator action)

The application root is fixed to `/home/servernetcloud/servernet_app`. Install
the reviewed helper as `storage/app/private/arvan-owner-windiag-20261018/owner-test-20261018.php`.
The dedicated directory must be0700; helper and `manifest.json` must be0600.
Do not place either in `public_html`, add a web route or relax those permissions.
Encrypted state is `state.enc`; `lifecycle.lock` serializes all helper modes.
The manifest is pinned by SHA256 after `prepare` and is not subsequently editable.
An expired pre-create quote therefore requires a reviewed reset of an **unspent**
preparation, not a silent manifest replacement. Never reset state/intent after a
create attempt.

Manifest fields (values below are schema descriptions, not an executable quote):

- `name`, `region`, `owner_id`, `owner_code`: fixed values above.
- `plan_id`: exact current local CloudPlan integer; `plan_ref`: exact native size ID.
- `image_id`: native regional image UUID, **not** local CloudImage ID.
- `image_key`: local key and exact native image name, currently `2025`.
- `shape`: integer `vcpu`, `ram_mb`, `disk_gb`, and `cpu_kind: shared`.
- `quote`: integer UTC Unix `observed_at`, `expires_at` (at most30minutes later),
  integer `all_in_48h_irt` (positive and ≤120000), integer
  `provider_hourly_rial` pinned to the current provider SKU,
  `currency: IRT`, `hours: 48`; all of `tax_confirmed`, `disk_included`,
  `ipv4_included`, `license_included_or_free`, `minimum_bill_bounded` must be true
  **only after evidence confirms them**. A tax assumption is not confirmation.
- `volume_schema: unverified` is safe when both independent volume lists are
  empty. Nonempty lists fail closed. Only after actually observing their schema
  may a reviewed manifest use `instance_id` or `attachments.server_id` with
  `volume_schema_observed: true`; arbitrary provider fields are never guessed.

`prepare` and `create` separately read fresh native sizes and distribution images
and require exact plan/shape/hourlyprice/image/group/minimums. An inactive local
plan is only an identity anchor for this approved diagnostic; it is **not enabled**.
Other customer resources may exist in the region and are preserved. The existing
`arvan_create_attempts` table and unique operation-key index must already exist;
the helper performs no migration.

## Modes and scheduler

Default `status` is read-only, emits only an allowlisted summary and never prints
the native reference, IP, receipt/password or raw exceptions. `prepare` performs
read-only provider preflight and persists the private manifest fingerprint.
No paid request is made. A second prepare is refused.

Status distinguishes the **original create response** from later resource
discovery. `receipt_outcome` is one of `not_attempted`, `response_missing`,
`transport_unknown`, `accepted_response`, `rejected_response`, or
`unresolved_response`. In particular, a transport timeout remains
`transport_unknown` even if reconciliation later discovers and attaches the VM.
It is not evidence that the provider rejected the create or omitted a password.
`password_evidence` is `unknown_without_successful_response`,
`absent_from_validated_response`, `returned_unverified`, or `invalid_in_response`.
The historical `password_returned` boolean is preserved for compatibility; false
must not be interpreted as provider omission without the separate evidence field.
These derived presentation fields do not mutate the encrypted receipt, journal,
creation intent or cleanup lifecycle. No status can mark Arvan delivery proven.

Before `create`, install this exact **dedicated** same-user cron line without
changing other jobs (the helper itself does not edit crontab):

```text
* * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/servernetcloud/servernet_app/storage/app/private/arvan-owner-windiag-20261018/owner-test-20261018.php cleanup --cron >/dev/null 2>&1
```

Wait for an actual scheduled `cleanup --cron` heartbeat, then verify it with
`status`. Do not manually simulate scheduler verification. `create` checks the
exact crontab line and a heartbeat no older than180seconds; a configured line
alone cannot establish that cron is executing. The operator must also monitor
status/errors: a cron entry cannot guarantee availability during outages.

`create` durably saves intent **before** the only possible driver create call.
The existing Arvan driver also has its persistent operation-key guard. The full
response is immediately encrypted before any later validation or attachment.
After any timeout/crash/malformed response, use `reconcile`, never another create;
if exact resource identity cannot be proven, stop for review. Do not delete a
create journal to retry. Reconciliation requires unique exact name, matching
detail/list UUID and provider creation time within the recorded attempt window.

Verified resources attach using the existing native admin attachment controller
inside a transaction, to an owner-only zero-price monthly service with due date
outside the renewal lead window. An exact diagnostic marker and audit are saved.
This avoids real customer billing and full-provisioner catalog switching or sales
quarantine side effects. Any returned password remains in the encrypted diagnostic
receipt; **it is not marked delivered, applied, or RDP-verified**. Native attachment
does not promise a password and does not overwrite one.

`request-early-cleanup` sets the irreversible early-cleanup request and immediately
tries cleanup; cron retries the exact resource thereafter. Otherwise cleanup is
due at exact provider creation+48hours, regardless of test completion. Expired
quotes or retired catalog rows do not block cleanup. Budget risk is raised if
cleanup is not complete by that deadline; the quote is a bound, not an actual bill.

Cleanup uses native `ProvisioningService::terminate()` or exact-service
`releaseAndTrack()`, under service→instance row locks and refreshed identity,
zero-price/owner/noninternal/blank gateway-domain guards. It never invokes the
global retry command. The unattached accepted-receipt fallback uses only the
same exact driver ref after verified name/time and an ownership gap lock; it
accepts Laravel's `mysql` or `mariadb` driver names only, and fails closed without
the same required InnoDB/REPEATABLE-READ/index proof for either driver. The driver
name alone is never sufficient.

After deletion, complete means exact VM detail404 **and** valid regional list
absence; valid ports list contains no saved port/instance/IP; both independent
volume lists contain no target/saved dedicated volumes. Port-detail404 is not
used. Unrelated rows stay untouched; shared/reassigned resources or unknown
attachment schemas stop the helper. No separate port/volume deletion is guessed.
Attached service must also be closed, provisioning`none`, instance`deleted`,
absence marker present, errors/release-pending empty and audit persisted. An
unattached imported DB orphan prevents complete rather than being silently ignored.

Once independently verified complete, remove **only this exact cron line** and
retain the encrypted audit/state privately. The helper never removes cron by
itself. An unsuccessful attachment, provider deletion restriction or unreadable
inventory must be reported promptly; neither a DELETE acknowledgment nor a local
closed flag is proof that spending stopped.

## Test boundary

Pure tests validate budget/identity/quote/schema gates, fresh image/size matching,
name/timestamp reconciliation, unrelated-resource preservation, unknown or
reassigned resource rejection, deadline/early-cleanup and output redaction.
They do not exercise Laravel/controller transactions, cron, encryption, actual
API calls, deletion consistency or Windows/RDP. Independent source review and
fresh production preflight remain required before the approved single create.
