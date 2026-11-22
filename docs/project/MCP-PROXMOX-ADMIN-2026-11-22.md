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

Status: source prepared; production grant and activation have not been performed.
