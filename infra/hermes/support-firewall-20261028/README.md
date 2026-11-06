# Hermes support network release

These are reviewed snapshots of the existing production support runtime. They are
kept outside `website/app` because the complete legacy support runtime and its
dependencies are not tracked on this develop baseline. They do not install routes,
bootstrap a second Hermes implementation, or grant execution permissions.

The application driver change is maintained in the normal source path:
`website/app/Services/Cloud/ArvanClient.php`.

Production destinations for these snapshots are `app/Services/Hermes/<filename>`.
Read the fresh destination first, preserve later edits, compare hashes, lint and
invalidate only the changed OPcache entries. Do not upload this directory blindly.
The ownership resolver, read-only diagnostics, concise writer and classifier were
already installed during the preceding support review. The network playbook is
the subsequent owner-requested teaching change.

`knowledge-documents.json` is an allowlisted, non-customer-specific reference batch
for `HermesKnowledge::importReferences`. References remain dated, private-owner
context, never proof of current infrastructure state or permission to act. Support
drafts receive the fixed network playbook and fresh service-specific diagnostics;
the private owner reference library is not copied into customer messages.

Private API receipts, customer addresses and identifiers, ticket bodies and
approval state are intentionally excluded from this release directory.

See `docs/project/ARVAN-FIREWALL-SUPPORT-KNOWLEDGE-2026-10-28.md` for the current
policy, validation, deployment state and bounded rollback.
