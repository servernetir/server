# Arvan Windows password handling — 18 October 2026

## Fresh-session preflight: staged, activation approval pending

This supersedes the earlier expired-session blocker below. The owner supplied a fresh cPanel Terminal. At09:49:48UTC the two live application hashes still matched the original baseline. Preparation succeeded at10:05:26UTC in the private owner-only release directory `/home/servernetcloud/servernet_app/storage/app/arvan-password-guard-20261018-Xqq99a`; **no --apply was run and no application source was installed**.

The live provisioner already contained older Windows exclusions in create/adoption. The first preparation stopped safely on this context difference. The revised surgical helper preserves the live create exclusion and strengthens adoption to the explicit, unambiguous Linux-family guard. Both prepared diffs were inspected. An independent offline check reproduced a7fda484 from the corresponding legacy adoption context with both LF/CRLF (four file comparisons passed); it is not a claim that the whole live file equals Git.

Scoped PHP-FPM inspection through the owner-accessible sockets returned primary-script ENOENT before executing the helper. The host configuration contains a forced document root, but the exact visibility cause was not proven. Two harmless public probe directories contained only a deny-all .htaccess and probe.txt; origin requests returned404, not a proven Apache403. No public PHP entry was created. At10:09:35UTC both exact probe directories were verified and moved into the private release directory as recoverable archives; none remains in public_html.

At10:10:01UTC, the application files still had the original hashes, public console=1, hourly sales=1, and the Windows image allowlist remained nonempty with SHA-256 `cc9e1285e69a6a37cde1b11b419c860293fae75e3f311bce3ac146744206b845`. Effective CLI configuration reported file cache and file sessions. No flags were rewritten.

The existing authenticated POST /system/opcache also clears shared application cache. An explicit owner approval is pending before using it: pending console tickets/grants can expire and require a fresh launch, and rate-limit/status caches are affected. Its returned cleared-list does not independently prove successful exit status for every clear command. Do not call it or claim web activation until approval and a fresh acceptance check. The unsuccessful FPM prototype is not a deployed/tested activation mechanism.

The15 targeted tests/91 assertions passed again locally; the adapted deployment helper passes PHP lint. Known Linux-family aliases are defensive parser compatibility, not proof that every alias exists in the live Arvan catalog. No provider/support call, paid test, guest/credential modification, VM/disk/IP change, DNS/Worker/sales change or routing/NAT/firewall/persistence-agent change occurred. The source patch is already in develop as a7fda484; this follow-up is preparation/documentation only. Before resuming, recheck source hashes and flags, obtain activation approval, inspect the prepared manifest, then apply only the two files and verify. Keep the private before-files for exact rollback; never overwrite a concurrent change.

## Follow-up: production deployment authorized, blocked before installation

The owner subsequently authorized publishing the defensive patch while preserving open sales/consoles, forbidding new test servers and further Arvan contact. At09:04:30UTC, production PHP8.4.26, the application root and both expected source hashes were verified. At09:06:08UTC, public console=1 and hourly sales=1 were read; the Windows allowlist was nonempty and unchanged. No provider endpoint or support channel was contacted.

The 15 targeted tests / 91 assertions passed again locally. A two-file surgical deployment helper was reviewed; its transformation exactly reproduces a7fda484 from its Git parent with both LF and CRLF. Production differs from that parent, so the prepared live diff and hashes still require inspection before apply. The helper has private backups, hash guards, lint, a lock and guarded partial-install rollback.

The existing web `/system/opcache` route also clears shared application cache and could discard console grants. It was not called. Apache/PHP-FPM and owner-accessible local sockets were observed; a private, same-owner Unix-socket checker is prepared to inspect the actual site pool and invalidate only the two files, but it has not been uploaded or executed. No new public endpoint or credential was created.

Before any live file writes, the terminal stopped responding. Reload explicitly returned **“Your IP address has changed. Please log in again.”** A fresh owner-authenticated cPanel Terminal was requested. Status: **not installed; no production changes made in this follow-up**. The next step is fresh login, repeat source/flag checks, prove the scoped FPM path, then prepare/review/apply/activate/verify. Do not treat the prepared maintenance helpers as production acceptance or run them blindly.

## Current decision and limits

The owner requested automatic Windows password delivery, a price quote before any new paid test, and a push to develop while leaving sales and customer consoles open. Later in this same turn the owner said not to pursue Arvan further because the current service works. No further support message, new VM, paid resource, password change or production deployment was performed. Existing customer resources were not used as test fixtures.

This is a defensive code change for review/release, **not** acceptance of unattended Windows setup. The previously successful service501 console test required the owner to set the Windows password manually. Service501 and its dedicated resources were already deleted and independently verified absent; do not recreate it or use another customer's VM without new authorization.

## Findings verified this turn

- The current create payload sends no Windows password/bootstrap instruction. It reads only the provider's optional create-response `password`.
- The generic missing-password fallback invoked `reset-root-password` during create/adoption, although the provider's v1 API documents that operation for Linux.
- The legacy service record could use `root` for Arvan Windows even though the customer cloud view uses Administrator.
- Arvan's [Windows connection guide](https://docs.arvancloud.ir/fa/cloud-server/instance/connection/) describes console-first password selection and, if necessary, enabling RDP. That matches the observed stock-image behavior.
- The current [official OpenAPI v3.0.7](https://www.arvancloud.ir/api-docs/iaas-3.0.0.yaml), read on18October, describes `CreateServer.initScript` as bash/Linux-only. A generic string field in the [v1 API](https://www.arvancloud.ir/api-docs/iaas-1.0.json) or an installed Cloudbase-init service is not evidence that Windows bootstrap runs.
- Read ticket `2B6-325CA968-00BC`. The last visible reply (17October17:15 provider UI time) refers to an owner/enterprise-team conversation; no written Windows metadata/bootstrap contract or complete billing answer was added. No new reply was sent. The owner's later request not to pursue Arvan was honored.

## Narrow patch

1. Do not automatically call the Linux-only reset endpoint for Arvan Windows or missing/ambiguous OS metadata. Known Linux families retain their reset path.
2. Arvan/LightNode Windows use Administrator in the service record; other providers retain their prior behavior.
3. Validate an optional initial create-response password as a nonempty bounded string without control characters.
4. Preserve existing create-result bookkeeping and credential storage behavior. A proposed lifecycle helper was removed during review because rejecting a concurrent cancellation/reassignment must not lose the identity of an accepted, billable VM. This patch does not claim to redesign that lifecycle.
5. Do not introduce password recovery from delayed GET, adoption or ordinary polling. A proposed asynchronous recovery was removed during review because an old response could race with a same-image rebuild without a durable image-generation contract.
6. Do not add plaintext passwords to generic status/cache/JSON, log a candidate password, mark a generated value applied, or treat a successful API response as real RDP acceptance.

No automatic guest-password generator, Windows init-script integration, new customer endpoint, schema change, sales flag, console switch, Worker or DNS change is included.

## Read-only live verification

At08:40:57UTC, regional sizes GET returned200. At08:46:12UTC the images GET also returned200, with stock2025/2022/2019/2016 Windows images advertising2048MB minimum RAM. Public console=1, hourly sales=1 and the existing Windows image allowlist was nonempty. These settings were observed, not rewritten.

Production source SHA-256 observed (not replaced):
- `app/Services/Cloud/ArvanClient.php`: `3c887daabc8ed845bf2c80bb40560f77eab9bee62b1bd631e005196ae396b73e`
- `app/Services/Cloud/CloudProvisioner.php`: `1477ba7a7006d4540ae0b3b8635eab48b2edcfb609570e1995c75565c4f159c7`

## Price before any future test

Reference candidate only: ir-tbz-sh1, Eco-Small3, 1CPU / 3GB RAM / 30GB disk / one IPv4; no backup, snapshot or private image. No purchase was made or authorized by this quote.

| Evidence | Amount |
| --- | ---: |
| Live regional SKU quote:15,770rial/hour ×48, divided by10 | 75,696toman before tax |
| Same-account calculator:448,500rial/day ×2, divided by10; includes CPU/RAM/disk/IPv4 | 89,700toman before tax |
| Calculator estimate with **assumed**, not provider-confirmed,10%VAT | 98,670toman |

The calculator is an estimate, not an invoice or a guarantee that all costs/commitments are settled. The API SKU quote and full-resource calculator are recorded separately; neither silently substitutes for the other. The current tax and minimum retention terms still lack a written answer. Earlier875rial was a provisional disk-only line, not the full old-test cost or proof that a48hour minimum is absent.

The [official price page](https://www.arvancloud.ir/fa/pricing/cloud-server) charges private images/snapshots by used storage. A30GB image kept48hours would add30,240toman before tax at21toman/GB-hour, if that price applies. Image preparation, a second validation VM, licensing and additional traffic are outside the single-VM estimate and need a separately approved budget. A seven-day wallet prerequisite is not the same as a seven-day consumption charge.

Local credential-free calculator evidence: `C:/Users/admin/Desktop/servernet/output/arvan-windows-cost-20261018.png`.

## Required future acceptance

Do not spend on another stock-image test merely to repeat manual setup. First establish a viable Windows bootstrap/custom-image contract and licensing; then obtain a fresh total-cost approval. Prepare independent, exact-resource cleanup before creation and preserve the create-attempt journal across timeout/reconciliation.

Acceptance must demonstrate untouched first boot, a unique credential actually applied in the guest, encrypted owner-only panel delivery, real RDP desktop using that credential with NLA/firewall retained, fresh customer console, reboot without resetting the password, and verified absence of VM/dedicated disk/IP after approved cleanup. A browser shell, API201, ACTIVE status, script completion marker or owner's general “works” statement does not replace these tests.

## Release and rollback

Base: `f8e578c41098f4b42a49f49bc08e296174092f70` on origin/develop, incorporating concurrent documentation changes without force. Status: **Git-only; not installed, not production-accepted as automatic password delivery**.

Validation with local PHP 8.4, synthetic credentials and mocked provider calls:

- `ArvanWindowsPasswordTest` + `CloudRootPasswordRevealTest`: 15 tests / 91 assertions passed; independently rerun after review and after the baseline comparison. Includes Linux family aliases, unknown/conflicting metadata, unchanged flags, stored credential preservation and status/cache non-disclosure.
- Syntax checks for both changed production files and the new test passed. `git diff --check` passed.
- Broader `CloudArvanTest` + `CloudProvisionTest`: 79 tests, 62 passed, 11 failures and 6 errors, 213 assertions. Temporarily restored only these two locally edited production files to exact base (verified empty diff), reran the same suites, and observed the same 17 failing/error test names and outcomes. Restored the reviewed patch afterward. Existing issues include missing exchange-rate fixtures, the absent `arvan_create_attempts` test table and two provider-fallback expectations. They were not repaired in this narrow password patch; no claim that the whole suite is green.
- No real first-boot password, RDP, guest reboot or new-console acceptance was performed in this turn. Public console acceptance remains the separately recorded earlier service501 test.

Affected production machine conceptually: core application only, but no installation occurred. No route, NAT/firewall, DNS, local proxy, Worker, scheduler or routing-agent source changed; no new persistence or migration. Production rollback is unnecessary because nothing was installed. A later release must use fresh hashes, private backups and its own acceptance record. Code rollback, if requested, is a normal reviewed revert of this patch only; do not revert public console activation or sales.
