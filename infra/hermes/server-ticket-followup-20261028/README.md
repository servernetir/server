# Server-side support follow-up

Reviewed snapshot of the production `app/Services/Hermes/HermesSupportInvestigation.php`.
The complete support runtime is outside this Git baseline, so this file stays out
of application autoload, matching the earlier support firewall snapshots.

The existing server-side `hermes:support-review` minute schedule owns execution.
It obtains service-bound read-only diagnostics and sends owner notices through the
existing Bale binding. No additional timer, poller, public endpoint, customer
delivery permission or infrastructure mutation was introduced.

Do not upload this folder blindly. Compare the current production source, preserve
later changes, use the matching private backup, lint, atomically activate and
invalidate only this file's OPcache. The deployment helper is receipt guarded and
must not be rerun without reconciliation.

See `docs/project/HERMES-SERVER-TICKET-FOLLOWUP-2026-10-28.md` for acceptance and
rollback. Ticket identifiers, messages, owner binding and private receipts are
excluded from this package.
