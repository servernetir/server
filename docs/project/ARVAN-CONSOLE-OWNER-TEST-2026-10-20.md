# Single owner console diagnostic — 20 October 2026

Subsequent state: at08:03:27UTC the owner explicitly approved
[public reopening](ARVAN-CONSOLE-PUBLIC-2026-10-20.md): public1/pilot0, sales
unchanged. Public0/pilot1 below is the historical test checkpoint, not current
production. Test cleanup and unresolved password/cost boundaries remain valid.

Completed: the sole VM was attached to service535/instance338 for owner1.
Real customer-button sessions passed live guest image, mouse/keyboard, concurrent
tabs and fresh reconnect. Exact early cleanup was independently verified at
07:50:27UTC; the VM/dedicated resources are absent and service535 is closed.
Only the dedicated test cron was removed, with the19 other lines preserved.
Public console remains0, owner pilot1 and hourly sales1. Actual provider charges
and automatic Windows password delivery remain unverified.

## Scope and quote

The owner explicitly requested one new test VM, real testing, then deletion.
The existing120,000toman limit is retained for this continuation. Only the owner
account1/codeSN-457136 may use this test. Public console remains0, owner pilot1,
hourly sales1, and the Windows allowlist is unchanged. Do not contact support,
change DNS/the portal Worker, or create an image/snapshot/second VM.

Fresh price observations on20October:

- 07:29:58UTC regional API: `eco-3-1-0`,1vCPU/3GiB/30GiB,
 15,770rial/hour,378,480rial/day. This SKU alone is not the all-in quote.
- Same authenticated account calculator, Shahriyar,1basicCPU/3GiB basicRAM/
 30GiB SSD/oneIPv4:448,500rial/day. Traffic allowance is account-aggregated;
 avoid downloads and do not assume a new per-VM free quota.
- [Official financial FAQ](https://www.arvancloud.ir/fa/faq) freshly confirms10%VAT.
 Conservative48-hour estimate: **98,670toman including tax**, within the retained
120,000toman cap. This is an estimate, not the eventual provider invoice.
- Native creation form again warns of a48-hour deletion restriction and that
 Windows2025Evaluation (180days) requires choosing a password in the console.
 Previous early deletion does not prove that minimum billing is waived.

Private screenshots are under `output/arvan-console-test-20261020/`, outside Git.

## Preparation checkpoint

Base checkout: `36683ac2f00f65c67469597c49a1f2002ef75ca0` from develop.
The new fixed identity is `sn-owner1-windiag-20261020`, `ir-tbz-sh1`.
The lifecycle helper was copied into a **new** private directory; only three
fixed date/name/path occurrences changed from the reviewed18October helper.
The old encrypted state, manifest, journal and completed test were not reset.

New source: `scripts/arvan-windows-bootstrap/owner-test-20261020.php`.
SHA256: `87f4de8ab87bb808f80fccf6292ccf5a23905542f5237880bb70ba269fd8db43`.
PHP8.4 lint and the new-identity suite passed **79 synthetic assertions**;
inverse substitution confirmed identical lifecycle code. These tests do not
exercise the actual provider or guest. Run the pure suite with:
`php scripts/arvan-windows-bootstrap/Test-OwnerLifecycle-20261020.php`.

07:34:37UTC read-only production preflight verified the owner identity,
zero owner active Arvan services, public0/pilot1/hourly1, matching nativeWindows
image, and no existing new test identity. The one unrelated regional server and
port are preserved; both independently read volume lists were valid and empty.
Plan435 is only the shape/identity anchor, not a public catalogue activation.
The native image remains `d7875b4d-ae60-4841-b762-7728fdf9bd8f`, local key2025.

At07:37:48UTC, the private helper and manifest were installed with directory0700
and files0600 under:
`/home/servernetcloud/servernet_app/storage/app/private/arvan-owner-windiag-20261020/`.
The old helper hash and new expected hash matched; native driver/attach/manager
files and the critical termination method sources were checked before spending.
Manifest hash:
`6b8a074412d27006d59f0587563ec6303702694def07da3723b70a67f6336d53`.
Quote expiry is08:04:37UTC; an expired quote must block create, never cleanup.

**At this checkpoint no VM has been created.** Before any paid call, require
successful native preflight, the exact dedicated cron and a real scheduled
heartbeat. Persist intent before the single create call. Any timeout requires
reconciliation, not retry. Preserve an accepted ref and encrypt any response.

## Acceptance, cleanup and persistence

The fixture uses the existing native attachment route with zero customer charge;
it is not a storefront financial or automatic-password delivery acceptance test.
Verify a fresh customer link, live guest image, reversible mouse/keyboard,
reconnect and tab isolation. A manual password setup remains manual evidence.
Do not put any credential, ticket, full console URL or customer logs in Git.

Request exact early cleanup immediately after testing. Fallback cleanup is
scheduled at verified provider creation+48hours. A DELETE acknowledgment is not
completion: independently verify exact VM404/list absence, dedicated port/IP and
billable disk absence, then the attached service closed and instance deleted,
absence marker/audit present and release_pending/error empty. Preserve unrelated
resources and financial history. Remove only the dedicated cron after success;
retain encrypted state and audit rather than resetting them.

Persistence is limited to this VM/dedicated resources, its owner fixture/audit,
private helper/state and exact cleanup hook. No shared route/NAT/firewall/DNS,
proxy, routing-agent state or other customer guest is part of the test. Resource
cleanup is its rollback; no sales/console flag rollback or recreation is allowed.
Actual results and exact identity/deletion evidence are recorded below.

## Creation checkpoints (historical)

Native prepare and live lint passed at07:38:33UTC with no create attempted.
At07:39:44UTC only the exact new diagnostic cron was appended; all19 other
nonempty cron lines were preserved with full readback and a private before-copy.
The real scheduled heartbeat was07:40:03UTC, verified by read-only status at
07:40:30UTC. The single `create` command was then submitted. At that checkpoint
its result and resource identity were pending; no duplicate create was sent.

Create intent was persisted at **07:41:15UTC**. The original driver receipt at
**07:41:49UTC** had transport status0; automatic reconciliation initially could
not yet locate the VM. No second create was sent. The scheduled reconciliation
then preserved the exact reference; explicit reconciliation at **07:42:56UTC**
attached the same machine as **service535 / CloudInstance338 / owner1**, zero
customer charge. The07:44:44UTC independent read counted exactly one persistent
create attempt and verified the attached owner/ref/name/status.

- Exact ref: `ir-tbz-sh1:fc88ac3f-8b7a-4e9e-9a3a-95538c73898f`.
- Dedicated IPv4: `37.32.5.165`.
- Verified provider creation: **2026-10-20T07:41:51Z**.
- Fallback cleanup deadline: **2026-10-22T07:41:51Z** (11:11:51Tehran).
- Original response outcome remains `transport_unknown`, even after discovery.
 It cannot establish whether the original successful response carried a password.
- Public0/pilot1/hourly1 persisted; no sales or public-console activation occurred.

The authenticated customer browser showed codeSN-457136, the new service in its
server list, Administrator and the truthful missing-password notice. Its real
console button opened a branded tab with `متصل` and a live Windows setup image;
the address fragment was consumed/cleared. A second real customer-button launch
also reached `متصل` while the first connection remained alive. The guest then
rebooted during its own first-boot setup; no reboot action was sent by the agent.
Mouse/keyboard, fresh reconnection and deletion acceptance follow below.

## Real console acceptance on the sole VM

The real guest finished first boot and displayed Administrator's mandatory
initial password-change notice. Using only the branded console:

- Mouse **Cancel** returned Windows to its lock screen; the other connected tab
 showed that same guest transition.
- The actual Ctrl+Alt+Del toolbar action changed the lock screen to Sign In.
- With the canvas focused, **Enter** activated Sign In and Windows displayed the
 mandatory password-change notice again. **Escape** dismissed it to the lock
 screen. No password was entered or changed.
- Two separately issued customer-button sessions stayed connected simultaneously.
 Closing the first browser tab left the second connected with its live image.
- A third fresh customer-button session connected successfully and its
 Ctrl+Alt+Del action again controlled the same guest, while the second stayed
 connected. Thus actual image, mouse/keyboard and fresh reconnect passed for
 this one VM/region and current browser path.
- The rendered body's computed font family starts withIRANSans. This is a CSS
 observation; the sandbox returned an empty iterable forFontFaceSet, so that
 alone is not a separate font-download proof. The earlier loader release already
 recorded real successful font responses.

Private screenshots include `windows-connected.jpg`,
`keyboard-enter-confirmed.jpg`, `second-tab-preserved.jpg` and
`reconnected-control.jpg`. All saved console addresses were token-free.

This does not accept other regions, a long soak, desktop login, RDP or automatic
password delivery. The original create response was lost to transport timeout;
manual first-boot password setup is still required by the native image/form.
No password was set and no real RDP login was attempted in this console-only test.
The current user request did not authorize reopening public consoles; public0
and sales1 remain the intended state. The exact early-cleanup command was sent
immediately after this acceptance; the pending result was resolved below.

## Final cleanup acceptance

Early cleanup was requested at **07:48:59UTC**. The immediate helper result at
07:49:18UTC was pending and was not reported as deletion success. Both live
console sessions subsequently disconnected and showed the bounded fresh-link
guidance; no blank page or raw provider error was displayed.

At **07:50:27UTC**, an independent exact-resource query, separate from the
termination result, verified:

- Exact VM detail404; valid regional server list200 with one unrelated server
  and zero test matches. The baseline customer's exact server remained present.
- Valid ports list200, one unrelated port and zero matches for the saved test
  instance/port/IP identities.
- Both `/volumes` and `/volumes/os-volumes` returned valid200 lists with zero
  rows, establishing no separately billable volume remains in those inventories.
- Service535 `cancelled`, provisioning`none`; instance338 `deleted`, provider
  absence marker present, release_pending empty and last_error empty.
- Helper `cleanup_complete=true`, public0/pilot1/hourly1.

The real customer service page was reloaded and displayed **“این سفارش بسته
شده است”**, with no console action. The fixture's financial history/provider
reference and private encrypted receipt/journal were retained.

At **07:51:53UTC**, only the exact20October diagnostic cron was removed after
checking completed state and exact service/instance ownership. All19 other
nonempty lines and their full content were preserved and readback matched.
A private before-remove copy and `cron-cleanup-receipt.json` were retained.
The final receipt confirmed the cleanup audit event and exactly one persistent
create attempt. No secondary app monitor was needed or created because cleanup
completed in this turn; historical automations were untouched.

Private evidence: `cleanup-provider-verified.jpg`, `cleanup-local-verified.jpg`,
`service535-closed.jpg` and the final cron receipt screenshot. No test resources
remain. The fallback22October deadline is historical and its cron is removed.
Early removal proves stopped resource existence; it **does not prove** avoidance
of a48-hour minimum charge. No invoice line was read for this new test, so report
98,670toman only as the conservative quote, not as an actual charge.

Acceptance applies to the one owner VM inir-tbz-sh1 on the tested browser path.
Public consoles remain closed pending the owner's separate decision. Desktop/RDP
and automatic password delivery remain outside this completed console test.
