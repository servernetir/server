# GPU capacity scheduler repair — 13 October 2026

## Published state

With the owner's explicit instruction to repair, test and publish without new paid servers, the live application on `core` was updated at **13:47:30 UTC**. Independent verification at **13:48:37 UTC** confirmed exactly one `cloud:advance-gpu-capacity` event, every minute, with a 15-minute non-overlap lock. All **60 pre-existing scheduled events** retained their commands, cadence, overlap settings and background behavior. The existing host cron already invokes Laravel `schedule:run` each minute with PHP 8.4 and was unchanged.

This supersedes the open scheduler defect recorded at 13:06/13:07 UTC in `GPU-CAPACITY-WAITLIST-2026-10-13.md`. The only changed production file is `/home/servernetcloud/servernet_app/routes/console.php`. Eighteen protected capacity/control files were independently hash-checked unchanged, including the five Windows/provider control files and the GPU wait/download flow. The live object-gateway lifecycle event, absent from the current develop baseline, was preserved; it belongs to separate work and was not copied or removed by this repair.

The develop change also incorporates the previously deployed GPU capacity delta and its dependencies, which were missing from that branch. Fourteen existing files and three new capacity service/controller/command files carry this previously live behavior. The two overlapping server-detail files were reconciled against fresh live sources; the Windows no-SSH/RDP behavior, OTP deletion, closed-order state, OS compatibility, provider controls, Proxmox reinstall gate and Arvan external console remain intact. Git changes are not a wholesale copy of the dirty shared checkout.

## Validation and cost protection

- Both isolated source sets, the exact live source plus scheduler repair and the develop source plus reconciled capacity dependencies, passed **85 tests / 842 assertions** each, with zero failures, errors or skips. These are the same suite on two source sets, not 170 distinct tests.
- The suite covers actual Schedule registration and command discovery; duplicate prevention; a held mutex blocking concurrent dispatch and expiring; empty queue with no supplier request; existing delivery/provision cadence; capacity/price/credit checks; unpaid waiting and replay; readiness-gated first-hour charge; cancellation/timeout; translated waiting/download views; server controls and OTP termination.
- Tests use private source directories, an in-memory SQLite database, placeholder settings, mocked supplier requests and an empty private environment file. No production environment or database was copied. The initial 128 MiB PHP test process stopped; rerunning with 1 GiB for the test process only passed at 163 MiB. A test's initial event matcher was corrected to support Artisan's quoted command format. Neither change altered production PHP settings.
- Pre-install and post-install queries found zero capacity candidates. No live advance command, purchase, wallet adjustment or new VM was triggered manually. Services 412, 416, 417 and 418 remained cancelled, provision status `none`, instance `deleted`. This repair did not contact suppliers to recreate or remove guests.
- Host cron, routes at the network layer, NAT/firewall, DNS, VPN, routing/forward-agent state, catalogue, pricing, balances, migrations and existing guests were unchanged.

## Evidence and deployment guard

Local receipts: `output/gpu-scheduler-repair-20261013/record/{baseline,applied,verified,manifest}.json`, `runtime-tests.xml`, `git-tests.xml` and corresponding logs. `published-proof.png` shows independent acceptance. These artifacts are private local evidence, not committed archives.

The private journal and backup are outside webroot in `/home/servernetcloud/gpu-scheduler-repair-20261013` (directory mode 0700). The installer checked the complete console before hash, all 18 protected file hashes, both successful test receipts, payload hash and PHP syntax before an atomic replacement. A separate PHP process then validated actual Schedule metadata and preservation of every prior event. Re-running installation is rejected by its completion receipt; use read-only verification instead.

Before SHA256: `8085372bffddf59f4988972dc18c2c47526f86c85282391b207547d15543f425`.
After SHA256: `26bf2311feb8c31e240c2e4424b1ce4c18fd3b62b6f6e368cd5e509ee03c6530`.

Both authorized peer chats were informed before installation. The GPU chat confirmed no concurrent changes to this scope. The homepage redesign chat explicitly excluded server-control logic and GPU scheduling from its appearance-only release. Future releases must take a fresh live snapshot, reconcile develop with any later live delta, and protect `routes/console.php` as well as the five control files; the Schedule regression test must pass. An old release helper or full shared checkout must not overwrite these files.

## Rollback and limits

Rollback is a targeted restoration of `console.install.before.php` only if the current console hash still equals the recorded after hash and the protected files are unchanged; stage on the same filesystem, syntax-check and atomically replace. If hashes drift, reconcile the newer work before restoring. Restoration removes automatic capacity advancement, so record the queue consequence and update the current-state index. Do not restore a whole database, financial state, old source archive or deleted test VM.

No new paid lifecycle/Jupyter/CUDA acceptance is claimed. This change proves scheduling, isolated workflow behavior and preservation of the running application's tasks and control sources; the existing GPU runtime acceptance limits still apply.


Git publication: [PR #56](https://github.com/servernetir/server/pull/56) merged into develop as 25a959e63fc4eb017a173fe6910bd7b914b7c380; the merged source was fetched and its ancestry verified.
