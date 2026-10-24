# Hourly to periodic billing conversion — launch 2026-10-24

Customers with an eligible active Hetzner cloud server can now change its billing from hourly to monthly, quarterly, semiannual or yearly from the server management page. The operation keeps the existing instance and its data. The customer explicitly confirms the conversion.

Pricing uses the current plan and add-on quote. Sufficient wallet credit converts immediately; otherwise the existing top-up payment flow funds a time-limited conversion request. Outstanding hourly usage is settled at conversion, the server's maintenance reserve is released atomically, and the new due date starts at successful conversion.

The meter refreshes stale state and checks hourly billing during its atomic claim, preventing hourly billing or credit-out handling after conversion. Duplicate requests and expired quotes retain their existing safeguards. Other infrastructure providers remain outside the new customer conversion allowlist.

Discount labels now show only savings supported by the actual quote after procurement floors and rounding. The configured long-term percentage can exceed the actual savings and is therefore no longer displayed unconditionally.

## Validation

- Ten runtime files passed PHP lint and were installed with expected-content guards and rollback snapshots.
- 49 related tests and 173 assertions passed on an isolated source copy with an in-memory SQLite database, including conversion, payment, metering and the new procurement-floor discount regression.
- The existing test `CloudHourlyBillingTest::test_hourly_rate_is_monthly_over_720_rounded_up` failed identically on the unchanged baseline and was excluded from the final related run.
- Read-only rendering in Persian, English and Turkish verified the conversion form, translated headings and no raw translation keys. No SQL writes occurred during this rendering.
- Web code caches were refreshed and the public health check passed.
- The requested customer follow-up was posted through the normal ticket reply service. No customer conversion was performed as part of deployment.

Browser visual acceptance, a real bank payment and an actual customer financial conversion were not performed. Existing release snapshots must be reconciled with subsequent changes before rollback; restoring code does not reverse later completed financial conversions.

Source: feature commit `27edd6b1`; discount correction `ac38351b`; regression test `0567aa0f`.
