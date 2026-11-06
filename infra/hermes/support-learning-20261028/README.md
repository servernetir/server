# Support case memory and service investigation

Normal application sources: `HermesServiceInvestigation`, `HermesSupportExperience`,
`HermesSupportEvidenceGuard` and the updated `HermesTechnicalFollowup` under
`website/app/Services/Hermes/`.

This folder archives reviewed production snapshots of `HermesOperations`,
`HermesSupportDraftWriter` and `HermesSupportReviewService`; their destinations are
`app/Services/Hermes/<filename>`. The complete legacy runtime is outside this Git
baseline. `HermesOperationsStore.php` is an offline test dependency only and is not
a deployment target. No second bootstrap, public endpoint or scheduler is added.

Only the seven runtime files in the manifest were installed. Compare fresh source
hashes, preserve later edits, hold the review lock, stage and lint dependencies
before consumers, atomically replace each file and invalidate only its OPcache.
Enable the learning flag last. Revision helpers are receipt guarded; inspect the
installed state and matching backup instead of replaying them blindly.

References and published case outcomes guide reasoning; they never prove current
service health or create execution rights. Financial, power and network actions
remain outside this read-only investigation feature. Public customer delivery still
uses the existing exact-approval workflow.

See `docs/project/HERMES-SUPPORT-LEARNING-2026-10-28.md` and `release-manifest.json`
for installation, tests, native model acceptance and bounded rollback. Private
customer data, balances, ticket bodies, model replies and API receipts are excluded.
