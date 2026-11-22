# Full Proxmox management through the existing owner MCP connection

The owner explicitly requested full access after the Hermes dashboard lookup
was blocked by the deliberately unprivileged SSH account. The existing plugin,
App identity, OAuth owner checks, command registration, automatic approval policy,
expiry, execution journal and host-key pinning are retained.

Base: develop b6e063426db2b7da59b04658ea13dc3b9b06e495.
Scope: root SSH on the existing Proxmox node through the existing dedicated
MCP identity, from the already allowed gateway address. Root access permits
administration of the host and every guest. It is persistent until revoked.
This does not grant OS administrator access on separately hosted WHM/Plesk
machines or expand OAuth management scopes.

## Enrollment and activation

The live preflight confirmed: the gateway logs in as mcp-ops; the same key is
not currently accepted for root. Root's SSH policy allows public-key-only login.
Root's primary authorized_keys is the Proxmox-managed cluster symlink.

The reviewed infra/proxmox/mcp_admin_access.py contains three CLI operations:

- host-grant, run once as root on Proxmox: verifies the exact public-key
  fingerprint and unchanged restrict/from options in mcp-ops' authorized_keys;
  appends that one key to the configured local authorized_keys2 when the primary
  is Proxmox's cluster symlink. No cluster file, sshd policy or existing key is
  changed. No password, private key or token is printed.
- gateway-promote, run as the existing application account: verifies strict
  host-key-pinned root SSH and an actual qm status 127 response before atomically
  changing only proxmox-ir's user and label in the private target registry.
  Other targets, identity paths, pinned host keys and connection settings remain.
- rollback: checks the live after-hash and the private backup before restoring.
  It stops if any newer edit or backup change is detected.

Each write has a private backup/receipt, cooperative lock, immediate byte
precondition and atomic replacement. Run in a quiet administration window;
external editors do not participate in this lock. The owner must confirm the
concrete browser action immediately before persistent root access is granted.
Staging files and read-only preflight do not grant root.

No PHP runtime file, schema, production environment file, existing provider
provisioning token, customer service, network rule or scheduler is changed.
The plugin archive is App-backed and unchanged; this is an upgrade to its
existing separately hosted SSH connection.

## Acceptance and rollback

Run python -m unittest discover -s infra/proxmox -p test_mcp_admin_access.py -v
in an isolated checkout. The tests cover key/source binding, conflicting keys,
idempotency, the Proxmox cluster symlink, restrictive SSH policies, preservation
of unrelated targets, failure before root proof, concurrent changes, host-key
pinning and rollback.

After activation, use the real plugin's inventory, SSH diagnostic and one
registered read-only command to verify uid 0 and qm status 127. Guest-agent
execution and the Hermes dashboard are separate acceptance checks. Root on
the host does not establish that a guest agent is installed or working.

To revoke, restore the gateway receipt first while root access still works;
then restore the Proxmox key receipt from an existing administrator session.
Do not replay old actions; their target hashes bind to the previous identity.

## Production acceptance — 22 November 2026

PR 369 source 7e4b13c611c1d8f64cc23a8162ea7d3dd6147943 merged into
develop as c6d1b76059e411c3072d8ce85304cabb1e57e56f after the focused
CI passed and the independent code review completed without findings.
All eleven isolated behavioral tests passed locally and in CI.
The installer SHA-256 is
c4f2e98ac507df37175b16a88e3cf6c6bad85f74e6ef4779a72944a36e20afde;
the staged bytes on both destinations were verified against it.

After explicit owner confirmation at the browser action, host-grant completed
once in the existing root administrator session. It enrolled the dedicated
key in the already configured local authorized_keys2. The existing cluster
symlink, SSH policy and source-address restriction were preserved. The private
root receipt and any previous local keys remain on the host.

gateway-promote then verified pinned root SSH and qm status 127 before changing
the existing proxmox-ir target to root. It returned root_verified=true and
other_targets_preserved=true and retained its private backup/receipt. No runtime
PHP release or plugin archive update was needed.

Acceptance through the actual connected ChatGPT plugin succeeded: the inventory
reported Proxmox Iran administrator; the fixed SSH uptime diagnostic exited 0;
a newly registered automatically approved action returned uid 0, user root and
VM127 running. Guest-agent execution inside VM127 also returned uid 0 and exit 0.
Thus both host administration and real command execution in the Hermes guest
were confirmed without another Proxmox browser login.

Hermes inspection confirmed its three assistant/broker/owner services running
on 10.10.10.127. The documented dashboard/webapp defaults 9119 and 9120 had no
listener. Port 9090 was identified as Prometheus, not a Hermes GUI.
This access change did not start or publish a web dashboard. Other guests'
agent availability and separately hosted panel OS administration were not tested.

Final state: root access installed and production acceptance confirmed.
