# Unknown traffic measurements — 3 November 2026

`CloudOperations::trafficUsage()` converted an absent/null `traffic_used_gb` from a successful provider-status response into zero. For a zero-quota catalogue row it then returned `unlimited=true`, despite having no consumption measurement. Arvan's current `serverStatus()` explicitly returns a null traffic value.

The method now accepts only finite, nonnegative numeric measurements. Missing, null, boolean, malformed, negative and nonfinite values return the existing neutral unavailable contract with null usage/quota/percentage/remaining values and `unlimited=false`. A real numeric zero remains valid; finite usage and explicit fair-use quotas retain their existing behavior. A valid power/IP observation is still synchronized before the independent traffic measurement is evaluated.

The current repository has no static controller/view call site for this service method; customer status polling separately passes through nullable provider traffic. This change fixes the service contract and adds no route or view integration. It does not establish exporter coverage, traffic financial settlement or monitoring enforcement.

Four focused feature tests cover the actual Arvan successful-status/no-traffic response, missing measurements, malformed values, and genuine zero/numeric values. Existing traffic tests cover finite quota percentages, explicit fair-use behavior and provider failures. All HTTP is faked. The existing isolated quota workflow now selects these traffic methods on SQLite and MariaDB alongside its existing regressions; no production credentials or external provider requests are used.

Local PHP syntax and diff checks passed. Actual CI totals are recorded on the pull request; deployed runtime acceptance is separate. No shared language/view file, financial state, cron or provider resource is changed. Rollback, if later installed, is limited to restoring the reviewed prior `CloudOperations.php` source after checking for newer edits; no data rollback is needed.
