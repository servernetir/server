# Owner-only Windows diagnostic — 18 October 2026

## Current state

The sole approved diagnostic VM has been created and explicitly reconciled to
**service516 / CloudInstance327 / customer1**. There was only one create attempt;
the transport timed out, but the VM subsequently appeared. No duplicate request
was sent. Automatic password delivery and actual RDP acceptance remain **UNPROVEN**.
Early deletion of the exact VM and dedicated resources has been independently
verified, the same local service is closed and its instance is deleted with audit.
Final encrypted-state and unchanged-flag checks passed. Only the diagnostic cron
was removed, and its secondary monitor was paused. The actual provider charge
remains unverified.

The owner approved up to **120,000 IRT for this one diagnostic VM**. This is not
authorization for an image, snapshot, second VM, licence upgrade or support
contact. Sales and console stay open. This is a zero-customer-charge diagnostic
fixture attached through the native attachment path, **not a storefront order**
or proof of the end-to-end storefront password workflow.

- Fixed test name: `sn-owner1-windiag-20261018`.
- Owner: customer 1 / `SN-457136`, Jafar Ebrahimi, verified against the live profile.
- Region: `ir-tbz-sh1`; selected Eco-Small3, 1 vCPU / 3072 MiB / 30 GiB SSD, one IPv4.
- Live provider size: `eco-3-1-0`; native Windows 2025 image
  `d7875b4d-ae60-4841-b762-7728fdf9bd8f`, local image 160 / key `2025`.
- Local plan 435 remains inactive; it is an identity/shape anchor only. No catalogue
  activation or public-sales mutation is authorized by this diagnostic.
- Exact provider reference: `ir-tbz-sh1:86eb231d-bb15-4f60-9ff1-f436abb72ac6`;
  IPv4 `188.121.106.162`.
- Verified provider creation: **2026-10-18T11:34:11Z**.
- Original 48-hour cleanup deadline: **2026-10-20T11:34:11Z** (15:04:11 Tehran).
  Early cleanup was requested at **11:46:52 UTC on 18 October**; independent
  absence was verified at **11:48:03 UTC**, before that deadline. This does not
  establish whether the provider charged a 48-hour minimum.
- Public console flag and hourly-sales flag both remain 1; Windows allowlist hash
  remains unchanged from the accepted password-guard release.

## Create, reconciliation and observed Windows behavior

- **11:33:32 UTC:** persistent create intent saved before the sole create call.
- **11:34:06 UTC:** encrypted driver receipt saved with transport status0,
  `ok=false`, `transient=true`, no accepted reference and one create-attempt row.
  Status0 means no HTTP response was obtained, **not a provider rejection**.
  Initial inventory reconciliation did not yet find the new exact name; the
  intent was retained and create was not retried.
- The VM subsequently appeared. **11:39:22 UTC:** explicit reconciliation verified
  its exact name/reference/provider creation time and ownership, then attached it
  as service516 / instance327. The cleanup deadline was pinned to actual provider
  creation, rather than the earlier local intent time.
- A later exact provider GET returned200 with matching identity and `ACTIVE`.
  The `password` key was present with an empty string; `root_password` was absent.
  No credential value was printed. This describes that later read only: because
  the original create transport timed out, it does **not** establish what an
  initial successful create response would have contained.
- The real customer service516 page displays the missing-password notice,
  `Administrator` and a console button. It does not display a delivered password.
- The official provider console connected. Ctrl+Alt+Del followed by Sign In
  showed **“The user's password must be changed before signing in.”** The agent
  did not set or change the Windows password. The owner initially reported a
  desktop, but the subsequent observed screen was locked; do not treat that
  earlier statement as accepted current desktop/RDP evidence.
- The owner subsequently reported that the white-label VNC page did not load.
  Initial navigation to the branded tab timed out, while the official console
  worked. This is a current observation, not a proved Worker/broker root cause or
  evidence that all customers/regions are affected. No DNS or Worker change was
  made in response.
- A fresh official console tab was shown to the owner for manual first entry and
  the read-only guest diagnostic, but the observed screen remained locked and no
  guest evidence was collected. The owner was then told not to enter another
  password before exact early cleanup was invoked at **11:46:52 UTC**. A manual
  password selection, if separately evidenced, must be recorded as manual and
  cannot pass untouched-first-boot automation. The guest evidence collector was
  **not executed**, and the agent entered no Windows credential.

## Early cleanup evidence

- **11:46:52 UTC:** requested early cleanup for only the pinned diagnostic identity.
  The immediate result was pending; it was not treated as successful deletion.
- **11:48:03 UTC:** independent exact GET returned404. Service516 was `cancelled`
  with provisioning `none`; instance327 was `deleted`, provider absence was
  verified, `release_pending` was null and the last error was null.
- Four independent inventory reads returned valid HTTP200 lists: servers2 with
  no target, ports2 with no target, `/volumes` empty and `/volumes/os-volumes`
  empty. The unrelated customer resources which appeared during the test were
  preserved; the initial inventory count was not used to delete or reject them.
- **11:49:22 UTC:** final helper status showed `cleanup_complete=true`, audit
  present and exactly one create attempt. Its saved `verified_at` was
  `1792324032` (**11:47:12 UTC**); the separate later provider reads above supply
  independent post-cleanup evidence. Public console and hourly sales remained1,
  and the Windows allowlist hash remained
  `cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845`.
- **11:50:02 UTC:** removed only the exact dedicated test cron. All19 unrelated
  nonempty lines were preserved and the full readback matched; the private
  before-copy was retained. `automation-3` was confirmed `PAUSED` around11:50 UTC;
  historical `automation-2` was not changed. The original receipt and ledger
  references remain.
- The actual customer service516 page was reloaded around **11:50 UTC** and showed
  **“This order is closed.”** No guest collector or actual RDP login test ran.
- Actual provider billing is not yet verified. Early deletion proves resource
  removal, **not** that a minimum 48-hour charge was avoided.

## Fresh cost evidence

Authenticated create form: 15,770 IRR/hour / 378,480 IRR/day for the selected size;
Windows 2025 Evaluation (180 days), no backup, extra disk, snapshot or paid image.
The same form explicitly says initial Windows password must be set in the console;
password and SSH-key access options are disabled for Windows. This is evidence
against assuming automatic password delivery, not proof of guest configuration.
At preflight the form displayed a 48-hour deletion restriction. Requesting early
deletion was not a promise that the provider would waive it; actual early removal
was subsequently observed above, without any conclusion about minimum billing.

Fresh authenticated calculator, same region, 1 basic CPU / 3 GiB basic RAM / 30 GiB
SSD / one IPv4: **448,500 IRR/day**. Its itemization includes CPU, RAM, disk, IPv4
and 250 GiB receive traffic at zero in that calculator. The region's traffic allowance
is account-aggregated; do not treat it as a guaranteed free per-VM allowance.

- 48-hour resource estimate: 89,700 IRT before VAT.
- Official FAQ confirms 10% VAT: **98,670 IRT including VAT**, leaving 21,330 IRT
  within the approved cap for variance. This is an estimate, not a final invoice.
- Lower create-form bundle rate is not used to omit separately priced IPv4.
- Avoid large downloads; late deletion or chargeable traffic can consume the reserve.

Sources: [Arvan tariffs](https://www.arvancloud.ir/fa/pricing/cloud-server),
[Arvan financial FAQ](https://www.arvancloud.ir/fa/faq),
[Microsoft Evaluation](https://www.microsoft.com/en-us/evalcenter/evaluate-windows-server-2025).
Local screenshots (not committed) are under `output/arvan-windiag-20261018/`.

## Preflight and persistence

Before creation, read-only live inventory: servers 1, ports 1, both `/volumes` and
`/volumes/os-volumes` empty. All four endpoints returned HTTP 200, JSON `data`
lists, no pagination metadata or nonempty Link/pagination headers. The existing
customer resource is not part of this test. Port IP entries are strings.

The initial helper under `scripts/arvan-windows-bootstrap/owner-test-20261018.php`
passed independent review, lint and **55 pure assertions**. It was installed privately;
`prepare` passed live at **11:31:34 UTC**, with no create attempt. Private directory:
`/home/servernetcloud/servernet_app/storage/app/private/arvan-owner-windiag-20261018/`.
Initial installed SHA256: `74bbd37840650d6a3d9093f9da52c291ea07e3a81a32d7024b88a98731d00c72`.
Directory0700 / helper and manifest0600; encrypted state persists preparation.
The initial preflight safely refused Laravel's `mariadb` driver name before any
state/create; a reviewed exact mysql/mariadb compatibility change preserves all
REPEATABLE-READ, InnoDB and ownership-index guards. All critical native termination
methods, constants and CloudManager hashes match the reviewed checkout; the full
CloudProvisioner retains unrelated live differences and was not overwritten.

Require fresh bounded quote, private encrypted state, single-create intent,
actual scheduled cleanup heartbeat and exact ownership checks before any paid call.
Never rerun create after an ambiguous response; reconcile only.

App heartbeat `automation-3` was armed every 30 minutes for this new identity only
and was paused after verified cleanup. Historical `automation-2` remains paused
and was not changed. The app heartbeat was secondary monitoring, not a substitute
for verified server-side cleanup. A dedicated per-minute cron was
installed with all 19 existing nonempty lines preserved and a private before-copy.
Actual scheduled heartbeat was verified at11:33:02 UTC, read at11:33:08;
it was not simulated. The sole create was then submitted and later reconciled
as recorded above. Only this dedicated cron was removed after cleanup as recorded
above. The encrypted journal/receipt and create-attempt row remain; they must
never be reset to permit another create.

At **11:46:02 UTC**, a separately reviewed **status-presentation-only** update
was verified installed in the same private location; the live status included
the new fixed evidence enums and its verified hash was:
`75975672483ddba5413c071388180d822ec539b7d5a8c80ed94036167ebac786`.
Lint and **79 pure assertions** pass. Independent review reconstructed the exact
prior hash after removing only the new receipt-evidence method and public-summary
merge, proving the lifecycle, quote, create and cleanup bytes were unchanged.
No production application source was replaced by this helper update.

The original receipt therefore continues to report
`receipt_outcome=transport_unknown` and
`password_evidence=unknown_without_successful_response`, even after the VM was
discovered and attached. The historical boolean `password_returned=false` must
not be paraphrased as “the provider omitted the password.” All status summaries
remain credential-free and `arvan_delivery=unproven`.

## Acceptance, limitations and rollback

Automatic password delivery is **unproven**. Source/unit tests do not prove initial
guest setup, password application, actual RDP login or panel delivery. A manual
first-boot password step must be recorded as manual, not counted as automatic success.
No password or credential belongs in documentation, screenshots or Git.

Exact VM + dedicated port/IP + billable volume absence and the same local service
closed / instance deleted with no release_pending were independently checked as
above. Final lifecycle/audit/flag checks passed; only the exact diagnostic cron
was removed and `automation-3` paused. Ledger and provider references remain.

Affected resources/persistence are only this approved new VM and its dedicated
resources, its zero-price owner fixture/audit, private helper/encrypted journal
and dedicated cleanup cron/secondary monitor. No DNS, Worker, shared route/NAT,
firewall configuration, country-routing/port-forward source state, existing
customer guest or production application code has changed in this diagnostic turn.
Rollback is limited to the completed exact-resource cleanup and removing only
diagnostic execution hooks; retain the private helper/state as evidence and do
not recreate the VM. Keep evidence/audit and ledger references; do not roll
back sales, console or unrelated services. Actual final provider charges remain
unverified; cleanup acceptance relies on the independent absence checks, not a
successful DELETE alone.
