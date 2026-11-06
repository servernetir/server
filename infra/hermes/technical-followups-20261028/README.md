# All technical-ticket follow-ups

Production code destinations:

- `website/app/Services/Hermes/HermesTechnicalFollowup.php` maps to the same app-relative path.
- This folder's `HermesSupportReviewService.php` maps to `app/Services/Hermes/HermesSupportReviewService.php`.

`HermesSupportState.php` and `HermesSupportProcessor.php` are exact reviewed offline
test dependencies from the existing runtime; they are **not deployment files**.
The complete legacy support runtime remains outside this Git baseline. No new
bootstrap, HTTP route or scheduler is introduced by these snapshots.

The existing minute review obtains service-bound diagnostics, records the private
investigation and notifies only the bound owner through the existing Bale path.
The new flag gates only technical continuation reporting. Existing sensitive-data,
ownership, stale-message and customer-send protections remain in place.

Use `release-manifest.json` and the project report for source hashes, acceptance
and rollback. Read current production files first, preserve later edits, hold the
review lock, stage/lint both files, install the dependency before the coordinator,
invalidate only their OPcache entries, and enable the flag last. Never upload the
whole directory or replay a receipt-guarded release helper blindly.

Private state, ticket identifiers and bodies, addresses, owner binding and API
receipts are excluded from this package.
