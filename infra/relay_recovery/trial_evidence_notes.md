# Read-only trial evidence bridge

`TrialEvidenceBridge` translates an existing, authenticated isolated acceptance
into the two existing driver/restore trial contracts. It does not install or
activate the missing production coordinator. `build_integrations()` and the
global integration guard are unchanged.

The local API is:

```python
bridge = TrialEvidenceBridge(controller_config, pinned_isolated_publication_ref)
trial_observation = bridge.observe(original_trial_permit, original_driver_context)
workflow_observation = bridge.trial_network(original_restore_workflow_context)
```

The reference is the existing private
`servernet.relay.isolated-publication.v1` enrollment used by
`IranEffectPublisher.publish_isolated_accept()`, with its exact private path
and SHA256. A request or a report cannot choose another enrollment, executable,
key, SSH command or endpoint. Missing or inconsistent evidence raises the fixed
`trial_evidence_unavailable` error; it never returns a partial successful proof.

## Exact existing chain

1. The original `production-driver.sqlite3` `TRIAL_STAGE` step and immutable
   `hook_dispatches` entry must contain the exact same permit and context. The
   stage was claimed during its original, at most 30-second mutation lease.
2. `controller.sqlite3` must still contain that exact CREATE action and the
   same validating source/candidate, incident, project, generation, configuration
   and candidate address. The candidate has no restore epoch. This component
   cannot relabel restored acceptance as the original pre-restore trial.
3. The stage publication, original manifest history and terminal outcome in
   `controller-evidence.sqlite3` must match that claim, target, resource and
   original permit expiry. A stage acknowledgement alone never passes.
4. The isolated accept publication must retain the exact original stage
   publication/manifest and SSH binding hashes, plus the same private enrollment,
   producer, verifier, proxy, finite dependency catalogue and baseline hashes.
   The latest acceptance for the claim must have succeeded; pending, failed,
   differently profiled or conflicting later work cannot fall back to an older
   success. Any retained conflicting acceptance blocks the read.
5. The terminal receipt hash, original receiver time, manifest scope and typed
   `isolated_candidate_v1` / `candidate_socks_v1` proof must match the already
   authenticated collector outcome. Boolean acknowledgements, historical
   readback, default-path probes and altered typed identity fields do not pass.
6. Public SSH data must also match the existing immutable bootstrap
   `scope.json`, `trial.json`, `candidate.json`, `known_hosts` and public host-key
   file for the original CREATE intent. The private host key and cloud-init
   user-data are never opened. Missing bootstrap metadata is a HOLD.

The collector journal is the local authentication boundary: it is populated
transactionally after the gateway checks the signed agent report. The bridge
does not reauthenticate a raw guest response and does not consume its nonce a
second time. It also does not ingest an inbox or manufacture a replacement
outcome if a transaction is missing.

## Freshness and read effects

The original overall, CT, guest and registry proof clocks and original receiver
time each remain bounded by 30 seconds. The reader exposes the original oldest
proof time and its derived deadline; it never stamps an old proof with the time
of this read. The fixed 30-second isolated contract applies even when the
collector's general receipt age is 90 seconds. Final checks run after pin reads
and all database snapshot guards, and again after workflow validation. An
original mutation may already be expired: observing an existing fresh read-only
proof does not renew it or authorize a write.

The original single verifier measured and accepted the dependency before/after
evidence. This reader uses the retained typed summary and proof digests; it does
not reconstruct or replace raw evidence absent from that summary. Dependency
coverage means the exact privately pinned finite isolated catalogue. It is not
whole-infrastructure discovery, default-path acceptance or global cutover.

All three databases are opened with SQLite `mode=ro&immutable=1` and
`query_only`. Existing WAL/SHM/journal files, inode replacement or a change in
file identity during the read cause a HOLD; the reader does not ignore a WAL,
checkpoint it, create sidecars, locks, directories or schemas. It never calls a
publisher, provider, guest, key generator, lifecycle client or replay store.

The existing driver's `TRIAL_STAGE` cache also re-reads its read-only reconciler
for an already `VERIFIED` step. It requires an exact integer original
`proof_expires_at`, bounded by `observed_at+30`, and rechecks expiry after the
first journal verification. Legacy observations without this deadline hold.
Neither a missing/new proof nor expiry re-enters apply, renews the original
permit, changes its dispatch claim, or overwrites the first terminal journal
result. A newer actual read observation remains in the collector's own history.
The cache behavior for other hook kinds is unchanged.

`RestoreWorkflow._bound()` also honors this explicit isolated profile and its
original deadline instead of the general callback age. Immediately before
`FENCE_SOURCE`, the workflow stores the exact freshly checked trial proof and
caps the existing lifecycle permit to that deadline. The action journal retains
that exact proof; the lifecycle authorizer checks it again against the workflow,
current scope, policy and original permit immediately before a write. An
expired/mismatched proof cannot receive another lease. Existing callbacks for
other contracts retain their separate rules and are not relabeled as isolated
acceptance.

## Deliberately remaining integration

This component can be developed and tested independently of the pending PVE
DNS-only module update. That update does not enroll the isolated trial recipes,
the Windows candidate transport, bootstrap state or the private dependency
baseline needed by this reader. Actual fresh signed stage/accept outcomes and
their existing private enrollment are still required before live use.

A reviewed coordinator still has to publish the original authorized stage,
arrange an explicit fresh read-only isolated accept, and select this reader for
the trial hook/callback. Other registry, pre-fence, restore, cutover, retirement,
backup and drill gates remain separate. Neither API advances those phases or
enables the factory. Driver observations retain
`default_path_verified=False` and `global_cutover_performed=False`.

## Offline validation

`tests/test_trial_evidence.py` drives the real existing signed
Gateway→Agent→IranAdapter→CLI→producer→CT/guest→single final verifier path with
the existing OS/network fixtures, then reads the actual durable journals. It
checks driver consumption, unchanged files/nonce counts and clocks, exact
current scope/private/public SSH bindings, ambiguity/profile conflicts and
expiry during final pin reads, snapshot teardown and callback validation.
Bootstrap public metadata uses the existing output schemas; no private key or
cloud-init file is present in the reader fixture. These are source-level offline
results, with no installation or live acceptance claim.
The existing driver tests additionally verify expired/missing/extended trial
deadlines, a fresh read after verification, unchanged terminal history and no
mutation redispatch. The signed route test also exercises the reader through
the actual driver before and after the original proof deadline.
The restore tests cover callback-to-consumer expiry, the actual original
deadline on a FENCE permit, expiry at the lifecycle authorizer, unchanged
dispatch history and rejection after scope, identity, incident or policy drift.
The signed route also exercises the actual restore bound consumer at expiry.

Validation is performed on an isolated candidate based on `ed2ceec`. Reader,
publisher, driver, producer, restore, hook and continuation tests overlap; their
counts are not additive and do not replace the later full CI or live enrollment
acceptance. Independent review replays the original expiry findings at both
consumers as well as at the reader's final return boundary.

The final related seven-module run passed 179 tests. Independent review passed
123 overlapping reader/restore/driver/hooks/continuation tests and closed all
three reproduced expiry gaps. These are offline source results; fresh full CI
and installation/enrollment acceptance remain separate.
