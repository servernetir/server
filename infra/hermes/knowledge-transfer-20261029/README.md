# Owner-authorized business knowledge transfer, 2026-10-29

39 dated, non-customer-specific references consolidate the AI Gateway presales
discussion and prior business requirements. They contain no customer ticket bodies,
credentials, personal immigration/health/financial history, or grants of authority.
Requested work is not represented as deployed functionality. Prices are snapshots;
the reported price discrepancy, incomplete privacy terms and untested integrations
remain explicit. These references are owner-private, not a public customer corpus.

The existing `HermesKnowledge::importReferences` and encrypted
`HermesOperationsStore` are used without changing runtime source, tools, rules,
permissions, financial state, cron, provider services, or sending a message.

The native CLI importer runs three explicitly invoked phases: prepare (validate,
in-memory idempotency and 16 retrieval cases), apply (merge under the existing
state lock, preserve every old reference and unrelated field, encrypted private
backup), verify (persisted-content readback and 16 actual owner chat-context
retrieval cases). A repeat verified invocation is read-only verification.
No paid model inference is performed; retrieval success is not proof of a model's
answer quality, VM-local ingestion or model-weight training.

Only invoke through the private native deployment checkout after validating the
exact script, data hashes and branch. No web endpoint or recurring job is added.
Receipts and encrypted backup remain outside webroot under deploy-backups.
Rollback must remove only these new reference IDs under the same state lock;
never restore a whole-state snapshot over later tasks or messages. An interrupted
apply requires reconciliation rather than blindly repeating a write.
