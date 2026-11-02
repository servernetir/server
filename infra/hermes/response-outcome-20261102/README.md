# Hermes completion outcome guard — 2026-11-02

These are the exact two runtime files installed on VM127, not a replacement for the website app or the Hermes upstream distribution. Destination paths and pre/post SHA-256 values are in release-manifest.json. No secrets, databases, raw transcripts or production configuration are included.

The owner ingress now requires a nonempty text result with finish_reason=stop and no Hermes failure/partial flags. Exact internal failed-turn notices are rejected even if a model repeats one as ordinary text. Failed results are persisted as failed_or_uncertain with a fixed Persian explanation; no automatic retry occurs. Status polling can retrieve that safe result after owner/channel validation. Existing website clients that consume replies only on completed retain their uncertain state and never accept the notice as success.

Run the isolated tests from this directory:

    python3 -m unittest -v test_response_outcome test_owner_ingress

All inference/delivery calls are mocked and databases are temporary. The files are test artifacts unless a separately authorized installation uses a lock, an idle queue, current destination hashes, private backups, syntax validation, preserved permissions and a restart of owner ingress only. Do not copy them blindly over later runtime changes. The one-time session rotation is an operational recovery receipt; do not repeat it during source deployment.

Rollback: when owner ingress is idle, acquire the deployment lock, compare both installed hashes to this manifest, stop only owner ingress, restore only the two matching private source backups and start it again. Preserve current jobs.db, messages.db, runtime sessions, .env and all receipts. Restore an earlier session binding only by reviewing the specific session-rotation receipt; do not restore database snapshots wholesale or replay uncertain commands.

Details: [production recovery record](../../../docs/project/HERMES-RESPONSE-RECOVERY-2026-11-02.md).
