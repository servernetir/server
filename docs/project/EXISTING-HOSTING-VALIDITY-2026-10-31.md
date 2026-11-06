# Recorded validity for managed existing hosting

An existing hosting account with unverified billing terms can now display an owner-recorded validity date without inventing a renewal amount or period. The customer hosting card labels this date as service validity; the admin service table displays the same date. An unset date remains unknown. Ordinary locally billed services retain their existing due-date display.

`Service::managementValidUntil()` reads only `provision_meta.existing_hosting_adoption.valid_until` on version-1 adoption records with unverified billing terms. It accepts a real, zero-padded `YYYY-MM-DD` calendar date and returns UTC midnight. Invalid, missing or unrelated metadata returns null.

The metadata date is informational. It is not `next_due_at`, does not enable local billing, and is not consumed by renewal, dunning, suspension or provisioning jobs. The existing external-billing safeguards remain unchanged. Amount and renewal period remain pending records review.

An authorized, scoped operational update must preserve the existing metadata and record the source, exact source expiry timestamp and owner authorization under `valid_until_provenance`. No customer identifier or operational data change is included in this code change. A date from a domain record does not itself authorize a hosting extension; owner approval is required.

Validation is covered by the existing hosting adoption CI workflow on isolated SQLite and MariaDB databases. The focused additions check UTC date rendering, continued invoice/renewal refusal, invalid dates and ordinary service exclusion. The workflow also runs the existing admin-service and provisioning regressions. This document does not claim a production installation or metadata update; those require separate deployment and data receipts.

Runtime scope: the Service model, customer hosting card, admin customer view and the fa/en/tr UI labels. No schema, schedule, provider, domain, price, cycle or billing-date change is required. Production installation must preserve any concurrent view and language changes rather than replacing those files from an older repository baseline.
