# Automatic Ubuntu/Debian create bootstrap — 3 November 2026

This change attaches owned monitoring installation source to the existing Arvan
server-create request for positively identified Ubuntu and Debian distributions.
It does not create a second VM, add provider calls for installation, change SSH or
credentials, expose an exporter publicly, or establish central enrollment/billing.
Publication is separate from production installation and first-boot acceptance.

## Provider contract and encoding evidence

The official v1 specification, retrieved on 3 November 2026, defines
`CreateServerRequest.init_script` as a string with Go name `InitScript`.
Spec SHA-256: `4601276cb83a2cf7c3a0c14df15b36312a2d9573989558a4dc60449f5917e801`.
It provides no encoding description, example, size ceiling or execution-user/stage
guarantee. The [official creation guide](https://docs.arvancloud.ir/fa/cloud-server/)
describes entering script text or a file and automatic execution once at creation.

The [official Terraform resource](https://github.com/arvancloud/terraform-provider-arvan/blob/80747ecaf2b34c143e245878a0b365c403173966/internal/service/iaas/resources/resource_abrak.go)
copies `init_script` directly into `ServerOpts.InitScript`.
The [request model](https://github.com/arvancloud/terraform-provider-arvan/blob/80747ecaf2b34c143e245878a0b365c403173966/internal/api/iaas/server.go)
and [transport](https://github.com/arvancloud/terraform-provider-arvan/blob/80747ecaf2b34c143e245878a0b365c403173966/internal/api/api.go)
serialize that string unchanged with JSON. This implementation follows that
passthrough and sends plain shell text in `init_script`; the embedded source bytes
inside that shell script use gzip/base64 with independent SHA checks. Raw shell
input is an inference from these primary sources, not a completed provider runtime
acceptance test. The v3 company SDK describes Bash on Linux images only; it is not
used to invent a Windows `user_data` or PowerShell contract.

## Actual create path

The existing regional image index now retains Ubuntu/Debian family evidence from
the provider response. Classification uses the resolved regional image ID, not
customer-provided image-family hints or script fields. Conflicting image metadata
or duplicate IDs cannot authorize a script. Windows, marketplace applications,
unknown images and other OS families retain their existing create behavior without
this Linux payload. The image-index cache key is versioned for the new evidence.

Only after the existing flavor/network/name reconciliation/security-group/image
guards does `ArvanClient.createServer()` construct the fixed script. Missing or
changed deployment source fails before the create-intent claim and paid request.
The existing unique create-intent insert and one POST remain unchanged; an existing
server is adopted and an ambiguous/timeout attempt is never bought again.
No bootstrap is sent during rebuild or reset-password operations.

`raw.guest_bootstrap` in a successful create result records **requested** source
hashes, execution=`unknown`, central enrollment=`not_performed` and billing=
`not_performed`. This is internal result metadata; it is not a durable local or
central installation receipt. The installer-owned manifest inside the guest only
exists after its actual local verification succeeds. The control plane cannot
infer that receipt from the provider accepting a create request.

## Owned source and guest behavior

The app resource copy of `install-linux-exporter.py` is byte-for-byte the reviewed
canonical installer, SHA-256
`ba2d8ef3c2fea7534e89ca163364c63d0161c1809c7452e3923cfbb0f90f6ab8`.
The PHP builder pins both installer and wrapper resources, verifies them before
creating the request, and emits at most 32 KiB of owned source. The launcher checks
the embedded pins again before executing source. Python runs with `-I -S` to ignore
user environment/import paths and site initialization. No runtime download of installer
code, provider secret or arbitrary customer initialization input is included.

The wrapper requires an actual root Ubuntu/Debian guest with Python 3.9+, systemd
and the existing installer's tools. It automatically inventories, computes the
installer's canonical inventory digest and applies once. Exact managed reruns
verify without reinstalling. Foreign exporters, occupied ports, multiple default
interfaces, changed managed files or untrusted candidate sources retain the
reviewed refusals. Missing real prerequisites produce a local refusal.

One bounded apt metadata refresh is allowed for missing/stale template metadata,
followed by a fresh inventory. It neither adds repositories/keys nor installs or
upgrades prerequisite packages. The reviewed installer's exact origin/site,
package identity/size/hash and unauthenticated-download checks remain in force.
No package maintainer scripts are run for exporter installation. Ordinary
configured root OS apt-update hooks may run as part of this explicitly authorized
metadata refresh; no new hooks are installed by the bootstrap.

The resulting service remains `DynamicUser=yes`, with the existing restricted
collectors and the selected default-route device, listening only on
`127.0.0.1:19100`. Local verification requires the active managed process to own
that listener, a successful netdev collector and valid RX/TX counters. The
installer's existing rollback owns only its newly created files/service.

## Acceptance, limits and rollback

The focused offline tests exercise the actual create method with fake HTTP/cache/
claim boundaries, plus the actual reviewed installer under fake guest commands.
They make no provider, VM, apt or host service calls. CI runs these tests on PHP 8.4
and Python in an empty network namespace; no production secrets are supplied.

Production acceptance still needs a real authorized newly sold supported image:
confirm the same request created only one VM, the supplied source executed with
the expected encoding, local manifest/process/listener/counters verify, and the
result is not confused with central private transport/enrollment or financial
settlement. The provider's script-size ceiling remains undocumented; 32 KiB is
this implementation's own bound. Guest-root telemetry is not tamper-proof.

Windows delivery, existing-server enrollment, central scraping/freshness, provider
stop confirmation and financial settlement remain independent work. Existing
unlimited or financially held services are not charged by this change.

Rollback before production is a Git revert. After deployment, removing this create
hook affects future requests; a script already submitted to a provider cannot be
withdrawn by reverting PHP. Guest cleanup must separately inspect and remove only
the verified owned installation, preserving customer services and credentials.
