# Account credit refunds and registration consent — 20 November 2026

Base: develop `43fde3d6fd127192d700c89f7eddafc0c15eadc6`.

Owner approved discretionary refunds as account credit, preserving mandatory statutory monetary refunds. New-order terms, hosting FAQ, selected public notices and the refund-policy KB article agree on that distinction. The existing 14-day eligible Iran-hosting cancellation review window remains, with approved discretionary refunds issued as credit for new orders. Earlier accepted commitments remain unchanged.

Registration already required `terms=accepted` at its final step. Live preflight found 18 legal documents and 1,636 acceptance records; these are counts, not a claim that all customers accepted the current version. A short notice is now shown at the contact step; the final checkbox explicitly includes the credit policy, starts unchecked and is validated by the browser and server. Ordinary and Google registration share this final controller.

`LegalDocuments` obtains the exact localized terms/privacy text from public config, derives the version from SHA-256 and appends missing immutable versions. Registration activates the account and records both acceptances in the same transaction; recording failure cannot leave an active account. Rerunning the legal seeder preserves historic document timestamps and text. No historical customer receives a new acceptance record from publication.

## Validation

PHP 8.4.24, isolated local checkout with Composer lock dependencies and SQLite memory database, no production environment or credentials. Real network requests are forbidden by the new tests. Related suites: RegistrationLegalPolicyTest, LegalAcceptanceTest, RegistrationFlowTest, GoogleAuthTest, OrderIntentSurvivesAuthTest and RefundPolicyScopeTest: **86 tests, 985 assertions passed**. An initial full discovery attempt hit the CLI default 128 MB memory limit; the selected suite completed with a 1 GB limit.

## Publication

The dedicated CLI release script supports separate `--prepare COMMIT` and `--apply COMMIT` operations from the production app root. Sources are pinned to a commit. Ten files are staged with before/after hashes; differing live comments or unrelated edits in shared text files are preserved by single-match replacements. Controller, final view and seeder must match the verified preflight base. The three KB translations are backed up and guarded against concurrent edits. Private backup and receipt: `deploy-backups/legal-credit-consent-20261120/` outside the webroot. No migration, payment, infrastructure operation, notification or synthetic customer acceptance is part of deployment.

At this revision, implementation and tests are complete; production installation and public acceptance remain pending. Record the pinned publication commit, actual receipts and fresh-page evidence when completed.

## Rollback

Restore only manifest-listed files from their `.before` snapshots after confirming that installed hashes still match. New legal versions should remain as historical records once any customer has accepted them; never delete or rewrite customer acceptances. Restore article fields from the private article snapshot only after guarding against newer edits. Account transactions and historical legal records require no rollback because publication does not change them.
