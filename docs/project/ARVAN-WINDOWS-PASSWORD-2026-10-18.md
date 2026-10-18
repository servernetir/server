# Arvan Windows password handling — 18 October 2026

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
