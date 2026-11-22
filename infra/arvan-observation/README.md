# Application-side observation transport

These are **user** systemd units for the existing `servernetcloud` account. They
reuse its separately approved SSH configuration, key and host-key pins. They do
not install credentials, change remote SSH permissions or expose a TCP listener.

The socket unit retains the account-private Unix listener while each accepted
connection starts one bounded SSH stdio forward to the existing query-only proxy.
An SSH/network failure ends that connection; the next poll tries a fresh one.
The Unix inode does not change merely because an SSH child failed, so the strict
application transport pin remains valid. A socket-unit restart/reboot can change
the inode and still requires explicit native operator review and approval.

Install only after confirming the exact existing SSH configuration, native host
pins, read-only proxy connectivity, account ownership and the absence of a live
old forward. Existing socket data must never be unlinked on an ambiguous error.
Retain the old stale inode privately for a guarded rollback. Install only these
two previously absent unit names, verify them with native systemd, and enable
only `sn-arvan-query.socket`. User lingering is needed to survive logout.

On the accepted host, the direct user-manager socket refused connections while
the same manager was running. The documented same-user host transport worked:
`systemctl --user --machine=servernetcloud@.host ...`. Verify that route before
moving an old socket. This does not grant another user's or root's permissions.
Do not restart the listener merely to reload its connection template; use a
daemon reload after reviewing the exact template bytes. A listener restart can
invalidate its application pin.

Before approving the new pin, verify the actual PHP reader over the newly created
socket with the existing enrolled identity. Record native acceptance privately,
then approve using the existing operator-only registry/file flow. Two increasing
natural samples establish observation; a listening socket or a polling heartbeat
alone does not establish guest metrics, enrollment coverage or billable traffic.
An old checkpoint separated by more than150seconds remains unknown even when
transport reads recover. Preserve it and arrange a separately reviewed new
observation period; do not clear its hash or auto-approve a baseline.

Rollback disables/stops these units and their own connection instances, removes
only unchanged installed unit bytes, and restores the saved socket inode only
after confirming the new listener is stopped. Restore the prior transport
descriptor through the same guarded operator flow. Guest machines, customer
credentials and financial checkpoints remain outside this transport change.
