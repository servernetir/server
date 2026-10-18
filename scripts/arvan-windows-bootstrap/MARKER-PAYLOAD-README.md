# Future Windows init-script marker (offline preparation)

This is **not automatic password delivery**, a provisioner, or evidence that
Arvan executes Windows user-data. It does not call an API, create a VM, install
an agent, contact support, or change sales/consoles. No new paid test is
authorized by these files. Do not run the generated payload on this PC or an
existing customer VM. The payload is only for a future separately approved,
disposable Windows test guest with a bounded cost and exact-resource cleanup.

## Generate text, not execution

Use a fresh **non-secret** UUID or 32-hex diagnostic identifier for each future
create generation. Do not use an API key, password, console ticket or customer
authentication UUID as the marker. For example, using a synthetic ID:

```powershell
powershell -NoProfile -File .\scripts\arvan-windows-bootstrap\New-MarkerPayload.ps1 -MarkerId 019a01e8-1234-4567-89ab-0123456789ab
```

The command prints script text on stdout only. It has no path, arbitrary script,
execution, approval, upload or create arguments. Importing the module is inert;
dot-sourcing the wrapper is inert. Do not pipe the result to an execution tool.
No existing create/lifecycle helper or production API payload is changed.

The text begins exactly with `#ps1_sysnative`. If a future authorized guest
actually executes it, it writes only:

`ProgramData\ServerNet\BootstrapProbe\<validated-marker>.json`

Its fixed record contains only `schema` and the non-secret `marker`. It does
not inspect accounts, credentials, services, metadata, logs or network state,
and does not change a password, NLA, firewall, registry or service. Existing
reparse directories are rejected. Exclusive `CreateNew` prevents overwrites;
existing files are preserved, including damaged ones. A failed/partial write
must fail independent validation; retrying does not repair or replace it.

`Test-ArvanWindowsMarkerRecord` checks a supplied text string only; it does not
read a guest or a file. It returns only a boolean and accepts the exact canonical
two-field record for the expected identifier. Extra fields (including any
credential field), duplicate properties, stale IDs and partial data fail.
It is not an attestation or a live collection method. A copied/manually created
file, a reused ID or a matching old record is not proof of fresh execution.
Reparse checks are best-effort path checks, not a race-proof sandbox; directory
ACLs are inherited. Never use this forgeable marker for authentication or for
admitting a guest to a password-return service.

## Offline verification and boundaries

```powershell
powershell -NoProfile -File .\scripts\arvan-windows-bootstrap\Test-MarkerPayload.ps1
pwsh -NoProfile -File .\scripts\arvan-windows-bootstrap\Test-MarkerPayload.ps1
```

Tests validate input rejection, deterministic serialization, PowerShell syntax,
the fixed command/method surface, exclusive-create/preservation guards,
credential-free record checking, wrapper guards and unchanged source-file
hashes. They **never execute the generated guest payload** or create a marker
on the test workstation. Idempotency/preservation checks are static code checks,
not a live Windows/Arvan filesystem acceptance result.

The [Arvan v1 API](https://www.arvancloud.ir/api-docs/iaas-1.0.json) exposes an
undescribed `init_script` string; the [v3 schema](https://www.arvancloud.ir/api-docs/iaas-3.0.0.yaml)
documents `initScript` for Linux. [Cloudbase-init](https://cloudbase-init.readthedocs.io/en/latest/userdata.html)
supports the PowerShell marker, but that does not establish Arvan's transport,
image agent, metadata service or enabled plugins. No categorical assertion is
made that Windows execution is technically impossible.

After separate approval, one distinct hypothesis is to supply this harmless
script through the documented field and inspect the exact new marker plus
sanitized guest-agent evidence. Absence of the marker alone does not identify
which component failed. Any later password implementation needs its own secure
per-instance input/return contract, plugin-order validation, owner-only reveal,
real RDP verification and reboot/isolation tests. The prior diagnostic516 did
not send this payload; its lost initial response and later empty GET password
do not answer this new hypothesis. Its resources are already deleted and must
not be recreated implicitly.
