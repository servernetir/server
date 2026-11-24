# Public mail-host canonical consolidation — 24 November 2026

Base: develop `12e2bc6868524b0eaed40ced57d4a9d47c46c994`. The earlier mail-host consolidation only listed catalogue/content prefixes. Public lookup tools, legacy utility paths and company/policy pages could still respond on the mail hostname with a self-canonical. Current public HTTP checks confirmed the behavior; old successful catalogue/blog examples did not establish site-wide consolidation.

`ConsoleHost::MAIL_PUBLIC_PATHS` now explicitly includes lookup, speed, dns-lookup, network-scan, privacy, terms, aup, sla, abuse, careers, contact and badge. GET and HEAD preserve the path, query string and optional language prefix while redirecting to the fixed HTTPS primary host. Existing boundary matching requires a complete path segment.

Webmail, mail discovery, accounts, login, admin, API, robots and unsafe methods retain their existing behavior. No DNS, mail transport, network configuration or authentication policy changes. This is a bounded application redirect, not a hostname-wide web-server rule.

Local PHP 8.4.28 acceptance: 28 tests, 375 assertions, no failures/errors/skips, covering the console host and session-cookie domain. Cases exercise all three locale prefixes, GET/HEAD, query preservation, similar-prefix isolation, mail endpoints, API paths and non-GET methods.

The one-file limited release uses the exact reviewed search-growth deployment engine from develop `12e2bc6868524b0eaed40ced57d4a9d47c46c994`, pinned by SHA-256. It preflights the live middleware hash, lint, private backup and shared release lock. Both already verified FPM pools check the actual routing contract after targeted OPcache invalidation; no general cache flush or view compilation is needed. Rollback uses the same guard and verifies the old contract.

Before hash: `4b601e7f515668911ad9ca6cbe725a702e73d5a0a37932556dcb1cb11e6408f0`.

Public acceptance must check redirects as well as the unaffected primary pages. Request Google validation only after confirming the sampled public duplicates are consolidated. Search Console's completion and rankings remain later results.

Status at this commit: implemented and tested; deployment and public acceptance are recorded separately.
