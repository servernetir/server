# Windows bootstrap evidence preparation (not deployment)

This folder prepares a future investigation. It does not implement automatic
Windows passwords, enroll a guest, upload an image, call Arvan, enable RDP,
change NLA/firewalls, or establish production readiness. Sales and console
settings are not read or changed. There is no deploy entry point.

`Evidence.psm1` contains pure whitelist conversion/assessment functions.
`Test-Evidence.ps1` uses synthetic inputs only. Importing the module does not
inspect the host; tests exercise the entry guards but never run host collection.
Run the offline tests with:

```powershell
powershell -NoProfile -File .\scripts\arvan-windows-bootstrap\Test-Evidence.ps1
```

`Collect-Evidence.ps1` is for a future **explicitly approved disposable Windows
test guest**, after the complete test/image-storage cost is approved. Do not run
it on the operator's PC or an existing customer server. It reads the standard
Cloudbase-Init config candidate, the selected service state, two selected local
registry values, active firewall profile flags, and presence of a local SID-500
account. It does not enumerate installed products, read logs or metadata over
the network, generate/apply a password, write files/registry/services, or send
anything. Its only output is sanitized JSON on standard output.

Default invocation does **not** collect anything. Dot-sourcing does nothing,
even if the switch is supplied. Only direct invocation with the explicit
`-CollectApprovedTestGuest` switch enables reads. This is an operator safety
acknowledgment, not proof the machine is a disposable guest; independently
verify the intended guest before using it. No approved guest collection has
been performed as part of this preparation.

The fixed output schema includes only whitelisted category names and enum
values. Unknown config values, usernames, passwords, SIDs, UUIDs, IP addresses,
keys, paths and exception text are never emitted. Missing/inaccessible values
remain `unknown`; an explicit false setting is distinguished from missing
evidence. Duplicate selected config keys and multiline values are ambiguous;
the parser does not pretend a truncated plugin list is complete. Only `[DEFAULT]` is
considered, and the standard file is **not proof of the service's effective
configuration**. Other/custom config paths are deliberately not followed.

A configured password plugin is not proof that it ran, received authenticated
metadata, applied a password, returned it safely, or allowed RDP. Likewise RDP
and firewall flags do not prove connectivity. The RDP/NLA values are local
registry observations, not evaluation of effective Group Policy or an actual
listener/logon test. Every output retains
`arvan_delivery: unproven` and `production_readiness: not_established`, including
an apparently good candidate configuration. The collector does not fix findings.

The read-only collector still needs an approved guest test before its observed
Windows behavior can be accepted. Subsequent work requires a trustworthy
per-instance bootstrap contract, licensing/image decision, approved cost,
generation-safe credential handling, actual untouched-first-boot/RDP/console
tests and verified cleanup. A shared secret in a reusable image or an
IP/hostname-only enrollment callback is not supplied or endorsed here.
