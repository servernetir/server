# Account credit refunds and registration consent — 20 November 2026

Base: develop `43fde3d6fd127192d700c89f7eddafc0c15eadc6`.

Owner approved discretionary refunds as account credit, preserving mandatory statutory monetary refunds. New-order terms, hosting FAQ, selected public notices and the refund-policy KB article agree on that distinction. The existing 14-day eligible Iran-hosting cancellation review window remains, with approved discretionary refunds issued as credit for new orders. Earlier accepted commitments remain unchanged.

Registration already required `terms=accepted` at its final step. Live preflight found 18 legal documents and 1,636 acceptance records; these are counts, not a claim that all customers accepted the current version. A short notice is now shown at the contact step; the final checkbox explicitly includes the credit policy, starts unchecked and is validated by the browser and server. Ordinary and Google registration share this final controller.

`LegalDocuments` obtains the exact localized terms/privacy text from public config, derives the version from SHA-256 and appends missing immutable versions. Registration activates the account and records both acceptances in the same transaction; recording failure cannot leave an active account. Rerunning the legal seeder preserves historic document timestamps and text. No historical customer receives a new acceptance record from publication.

## Validation

PHP 8.4.24, isolated local checkout with Composer lock dependencies and SQLite memory database, no production environment or credentials. Real network requests are forbidden by the new tests. Related suites: RegistrationLegalPolicyTest, LegalAcceptanceTest, RegistrationFlowTest, GoogleAuthTest, OrderIntentSurvivesAuthTest and RefundPolicyScopeTest: **86 tests, 985 assertions passed**. An initial full discovery attempt hit the CLI default 128 MB memory limit; the selected suite completed with a 1 GB limit.

## Publication

The dedicated CLI release script supports separate `--prepare COMMIT` and `--apply COMMIT` operations from the production app root. Sources are pinned to a commit. Ten files are staged with before/after hashes; differing live comments or unrelated edits in shared text files are preserved by single-match replacements. Controller, final view and seeder must match the verified preflight base. The three KB translations are backed up and guarded against concurrent edits. Private backup and receipt: `deploy-backups/legal-credit-consent-20261120/` outside the webroot. No migration, payment, infrastructure operation, notification or synthetic customer acceptance is part of deployment.

Published on 20 November 2026. PR #335 was merged into develop as `2339074a591ce227f343f78ced12fd1c6e552c69`; the pinned deployment source is `b321ed5f4507a1275496b0416727400cb663f152`. The owner executed the prepared command in the authenticated production terminal and supplied `PREPARED 10 files; 3 article translations; no live changes` followed by `APPLIED 10 files; 3 translations; historical legal documents preserved`. The script's source and database readback guards completed successfully according to that execution output. Independently fetched fresh public pages (`?qa=legal-20261120`) returned HTTP 200 and displayed the new policy and statutory exceptions for `/terms`, `/docs/refund-policy-explained` and the console `/register` in all three locales (9 pages / 18 content checks). Normal visitor URLs were checked separately for cache freshness. A live end-to-end signup involving real OTP/KYC was not performed; final-step consent and transactional acceptance were covered by the isolated automated tests above. Publication did not backfill customer acceptances.

## Rollback

Restore only manifest-listed files from their `.before` snapshots after confirming that installed hashes still match. New legal versions should remain as historical records once any customer has accepted them; never delete or rewrite customer acceptances. Restore article fields from the private article snapshot only after guarding against newer edits. Account transactions and historical legal records require no rollback because publication does not change them.

## Registration wording correction

The owner requested a short linked registration notice only: “ثبت‌نام در سایت به‌منزله پذیرش قوانین سایت است.” The contact form uses a complete localized sentence with a link on “قوانین سایت”; the explicit refund explanation and additional refund-policy checkbox sentence are removed from the finish form. The ordinary required terms/privacy checkbox, validation and acceptance recording remain. All policy details stay on the terms page. Three locale files are supplied under `lang/*/auth_notice.php`.

Validation: 19 registration/consent tests, 140 assertions passed. Production installation of this wording correction is pending.
