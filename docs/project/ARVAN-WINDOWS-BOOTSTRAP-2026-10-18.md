# Arvan Windows unattended password — preparation and spending gate

## Status and scope

18 October 2026, follow-up to the [accepted defensive release](ARVAN-WINDOWS-PASSWORD-2026-10-18.md). The owner requests unattended password setup/delivery, a cost estimate and approval **before** another paid test, preserved sales/public consoles, no Arvan contact, and a push to `develop`.

**Not completed:** unattended guest setup, encrypted delivery from a new Windows VM, and real RDP acceptance of that automatically delivered credential. The earlier service501 console success involved manual password selection. That VM and its dedicated resources were subsequently deleted; do not reuse the historical success as automation evidence or recreate it implicitly.

This follow-up prepares a read-only guest diagnostic and synthetic tests. It does not install an agent, create a callback endpoint, generate/change a password, expose a credential, change the application, or spend money. Public documentation was read; no support message, authenticated provider API call or account operation was made. Sales/consoles were not toggled; their last live verification is the10:23:57UTC acceptance in the linked release report, not a new observation here.

## Why another unmodified stock-image purchase is not the solution

- Current `ArvanClient::createServer()` has no Windows password/bootstrap instruction. The initial response's optional `password` is not a contract for a later generated credential.
- The [official v3 schema](https://www.arvancloud.ir/api-docs/iaas-3.0.0.yaml) describes `initScript` for Linux. The [v1 schema](https://www.arvancloud.ir/api-docs/iaas-1.0.json) describes the reset endpoint for Linux; its optional password field does not establish a Windows ciphertext-retrieval API.
- The [Windows connection guide](https://docs.arvancloud.ir/fa/cloud-server/instance/connection/) documents console-first password selection. No claim is made that undocumented internal provider capabilities are impossible.
- [Cloudbase-Init](https://cloudbase-init.readthedocs.io/en/latest/plugins.html) separates applying a password in the guest from returning it through metadata. A successful plugin run does not prove delivery. Stable instance identity is also needed to preserve first-boot state across reboot.
- Its [OpenStack HTTP metadata service](https://cloudbase-init.readthedocs.io/en/latest/services.html) supports public keys and posting an encrypted password; ConfigDrive does not offer that return channel. A configured class name alone does not prove the provider implements the protocol.

Do not repurpose `infra/ansible/roles/windows_template/` for Arvan. That existing Proxmox-specific role assumes a config drive. Its script suppresses errors and disables NLA; its wrapper exits0 regardless of the script outcome. Those files were inspected but **not modified or deployed** in this work. This finding is not a statement about the current state of any live Proxmox guest.

## Candidate, not an accepted implementation

A possible future route is a licensed generalized image with Cloudbase-Init, a fresh RSA keypair for each create generation, only the public key associated with the regional instance, guest-generated password encryption, and authenticated retrieval of the ciphertext by ServerNet. The private key and final password would stay encrypted in the existing secret-storage boundary. No such key or image was created here.

The following must be observed before implementing/enabling that route:

1. The selected image actually receives the exact per-instance public key and stable instance identity through a trusted metadata channel.
2. The metadata service supports password posting, and the account API provides authenticated retrieval of that same instance's ciphertext. Neither endpoint is to be invented from an OpenStack implementation elsewhere.
3. Ciphertext belongs to the exact provider account/region/reference and immutable create/image-generation attempt. Ordinary status polling must not overwrite a customer-changed password or adopt a stale pre-rebuild credential.
4. Private-image mapping is explicit per region and immutable image ID. A missing mapping must not fall back silently to a stock Windows image.
5. Actual Windows logon and RDP with NLA/firewall retained succeed using the delivered password, followed by console/reboot/isolation checks.

If that metadata contract is unavailable, stop this route. A reusable image must never contain a fleet-wide enrollment secret. IP, hostname and provider UUID are identifiers, not proof of guest identity. A new callback endpoint would require a separately authenticated per-instance channel and its own reviewed security/release plan; it is not included here.

## Prepared diagnostic

[`scripts/arvan-windows-bootstrap/`](../../scripts/arvan-windows-bootstrap/) contains an explicitly invoked read-only collector and independently testable sanitizer. It is intended **only inside a future owner-approved disposable test VM**. No collection was run on this workstation or on a customer VM.

The diagnostic reports selected fixed configuration categories and access-security observations. It does not print raw configuration, logs, keys, passwords, URLs, machine names, addresses, account names or exception text. It performs no network request, metadata probe, service restart, registry/file mutation or Windows password operation. Unknown data remains unknown. Even apparently suitable configuration is always reported as **unproven for Arvan delivery**.

This is evidence-gathering preparation, not a provisioner or a way to enable automatic Windows delivery. Do not put it in SetupComplete, an image startup hook, a scheduled task, or the production deployment list.

## Cost estimate before approval

No new spending approval has been given for this follow-up. Earlier budgets do not authorize another test.

The18October same-account calculator previously observed448,500rial/day for ir-tbz-sh1 Eco-Small3,1CPU/3GB RAM/30GB disk/oneIPv4. For a conservative48hour planning window:

| Proposed stage | Before tax | With **assumed**10% tax |
| --- | ---: | ---: |
| One diagnostic VM; no image/snapshot/backup | 89,700toman | 98,670toman |
| One30GB private image retained48hours, if available/applicable | 30,240toman | 33,264toman |
| Example only: one builder + one validation VM + that image, each48hours | 209,640toman | 230,604toman |

The image rate is21toman/GB-hour on the [public price page](https://www.arvancloud.ir/fa/pricing/cloud-server). These are arithmetic estimates, **not an exact invoice or a guaranteed total**. Current tax, minimum Windows billing/retention, image size/quota, traffic and any license cost must be settled before purchasing the corresponding stage. The separately observed SKU estimate75,696toman before tax is lower than the full-resource calculator; do not substitute it without reconciling the included components.

The [default private-image quota](https://docs.arvancloud.ir/fa/cloud-server/quota/) and [import limits](https://docs.arvancloud.ir/fa/cloud-server/images/custom-os/) must also be checked before proposing a30GB stored image; an import size limit is not the same as account quota. No quota or paid plan upgrade is authorized.

Recommended approval request: **at most120,000toman for one diagnostic VM only**, contingent on a fresh itemized purchase preview within that ceiling. It buys investigation, not a promise of complete unattended provisioning. No snapshot/private image/clone/second VM under that approval. If the purchase preview, license terms or minimum bill cannot be bounded within the ceiling, stop before creation. A later custom-image/clone stage requires its own cost and approval.

## Acceptance and cleanup gates after future approval

- First record exact owner/service/create-attempt identity, native region/reference, resource list, itemized quote and provider creation time; preserve an accepted resource reference even when a password or transport step fails.
- Set up exact-resource cleanup and cost tracking before creation. No blind create retry after timeout. Check for an already accepted VM first.
- Capture sanitized bootstrap evidence before changing bootstrap configuration. If manual OOBE/password selection is necessary to run the collector, record that fact: this diagnostic VM then cannot count as untouched-first-boot automation evidence. Do not use a shared customer VM or service501 as a substitute.
- Treat generated, applied, delivered and real-RDP-verified as separate facts. Authenticate an actual RDP desktop with the same automatically delivered value; manual first-login setup does not pass untouched first boot.
- Verify owner-only reveal, no secret in status/cache/logs/URLs, NLA and firewall retained, reboot without resetting the password, and cross-instance isolation before general release.
- Delete the exact disposable VM as soon as permitted/finished. Confirm absence of dedicated disk/IP and any separately approved image/snapshot; a successful DELETE response alone is insufficient. Reconcile only its own local test service/instance and retain the audit record.

The one-VM diagnostic stage cannot prove clone isolation or acceptance of an untouched custom image. Those release-acceptance steps require a later viable implementation and separate approval of the necessary additional resources; they are not included in the proposed120,000toman ceiling.

## Validation / rollback boundary

Validation on base `f7ae576b3f17f468c49d7687d51f0d8aa01113ee` plus this preparation patch:

- `powershell.exe -NoProfile -NonInteractive -File .\scripts\arvan-windows-bootstrap\Test-Evidence.ps1`:51 synthetic assertions passed on Windows PowerShell5.1.
- `pwsh -NoProfile -NonInteractive -File .\scripts\arvan-windows-bootstrap\Test-Evidence.ps1`:51 synthetic assertions passed on PowerShell7.6.5.
- Coverage includes missing/invalid evidence, fixed-output secret redaction, current and legacy password-plugin class names, ambiguous duplicate/multiline configuration, insecure NLA/firewall observations, and default/dot-source collection guards. The collector command/method allowlists were also checked statically.
- Main and independent reviews inspected the scoped read-only operations. No host collection was executed, including on this workstation. The tests use synthetic values only.
- `git diff --check` passed. No application source changed, so no new Laravel runtime acceptance is claimed.

No actual Windows VM collection, metadata protocol exchange, automatic password application/delivery, RDP, or billing acceptance is claimed by these tests. The RDP/NLA observations do not evaluate effective Group Policy or prove an actual listener/logon.

Affected files are only standalone diagnostic tooling and documentation. Affected live machines, routes, NAT/firewall, DNS, Worker, scheduler, routing-agent source/state and persistence: **none**. No production deployment or operational rollback is required. If the preparation is later removed, use a reviewed Git revert of these files only; do not revert the accepted password guards, public console enablement or sales.
