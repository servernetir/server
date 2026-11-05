# Versioned image preparation

`catalog.json` evaluates all requested image families. Every candidate is disabled.
The current network policy does not include their distribution/checksum hosts;
no image artifact or trusted checksum was fetched during software development.
The `source_index` values are authoritative discovery references, not pinned
download URLs or proof that an image boots on this node.

1. On an authorized build machine, obtain a dated immutable artifact from the
   official distribution or the customer's licensed Windows media entitlement.
   Verify the vendor checksum/signature using its documented signing keys. Record
   the dated checksum URL, signing-key fingerprint and verification transcript in
   a private provenance record. Never replace a checksum after a mismatch.
2. Create a private single-image JSON manifest with `key`, `family`, `version`,
   `architecture: amd64`, immutable `source_url`, lowercase `sha256` and nonempty
   `checksum_provenance`. Windows additionally requires `license_evidence`, which
   references the approved hosting/distribution entitlement. A license key must
   not be stored in this manifest or repository.
3. Verify the local disk and render an inert import plan:

   ```sh
   python3 infra/cloud-engine/images/verify_import.py \
     /private/debian13-pinned.json /private/debian13-pinned.qcow2 \
     --vmid 900013 --storage local-zfs --bridge vmbr1 \
     --output /private/debian13-import-plan.json
   ```

   The script hashes the artifact, rejects moving paths, unsafe identifiers and
   missing license evidence, and executes no node command. Inspect each command
   and the exact volume returned by `qm importdisk` before attaching its disk.
4. Complete a **separate record for each image version on each node**: clone,
   hostname, unique machine-id/SSH host keys, configured CPU/RAM/disk expansion,
   static IPv4/default route/DNS, cloud-init completion, first-login credentials,
   guest-agent power/reset behavior, clean reboot, console, network isolation,
   reinstall, backup and restore. Linux cloud-init and Windows Cloudbase-init
   are different acceptance paths. A configured password is not evidence of a
   successful guest login.
5. Sanitize templates and record template VMID/artifact SHA/acceptance evidence
   through the infrastructure image inventory. The base OS version and template
   revision must remain discoverable after upgrades. Do not mutate an accepted
   template in place; import a new version and retain the previous rollback copy.

Windows Server 2019/2022/2025 remain unavailable until valid commercial licensing,
VirtIO/guest-agent compatibility, initialization and individual real-node tests
are documented. Evaluation media does not establish a hosting license. The
existing `infra/ansible/roles/windows_template` only stages customization files;
it is not proof of a licensed, bootable Windows image.

## Conditional Windows firstboot marker

The trusted image builder may configure `windows-firstboot-ready.ps1` as a
post-CloudbaseInit finalizer under SYSTEM/Administrators. It accepts the initial
password as an **in-process SecureString**, never as a plain command-line value.
The finalizer checks Windows Server build (2019/17763, 2022/20348, 2025/26100),
actual expected hostname, completed one-shot CloudbaseInit service, static IPv4
and gateway, and a real local-account credential authentication. Only then does
it atomically write `C:\ProgramData\ServerNet\cloud-engine-ready.json`, with
SYSTEM/Administrators-only ACLs. A previous marker is removed before checking.
Do not install it as a local-script plugin waiting for its own service to stop;
use a reviewed post-service task with protected in-process metadata credentials.

The marker contract is schema 1 with `image_key`, `os_build`, `hostname`, boolean
`initialized` and `credential_setup`, `ipv4`, and fresh UTC `written_at`
(`completed_at_utc` is an equivalent operator timestamp). It contains
no credential. The worker reads this fixed path through a fixed QGA PowerShell
command, compares it to the reserved VM/image identity, and requires the separately
accepted licensed template. The worker requires a timestamp at or after this
create/rebuild intent, preventing baked or previous-install markers from passing.
The operator-side verifier additionally rejects stale
markers and secret-bearing output. An absent/mismatched marker is pending or
failed readiness, not permission to ignore initialization.

PowerShell/CloudbaseInit execution is unavailable in the Linux development
environment. Python marker-contract tests and mocked QGA fixtures do not validate
the PowerShell script, image licensing, actual logon or firstboot task setup.
Every Windows candidate remains disabled until its real-node test record proves
these steps. No licensed Windows disk is distributed with this repository.

Administrator image acceptance records explicit guest-agent evidence for every
accepted image. Windows additionally requires license acceptance and
`windows_ready_receipt_verified` evidence. Registration-time `guest_agent` is
only a declared capability; it cannot imply verified guest operations. The
accepted exact Windows base `version` is `2019`, `2022` or `2025`; put the immutable
template revision in the image key (for example `windows-2022-rev1`).
