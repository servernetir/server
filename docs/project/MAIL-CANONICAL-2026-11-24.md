# Public mail-host canonical consolidation — 24 November 2026

Base: develop `12e2bc6868524b0eaed40ced57d4a9d47c46c994`. The earlier mail-host consolidation only listed catalogue/content prefixes. Public lookup tools, legacy utility paths and company/policy pages could still respond on the mail hostname with a self-canonical. Current public HTTP checks confirmed the behavior; old successful catalogue/blog examples did not establish site-wide consolidation.

`ConsoleHost::MAIL_PUBLIC_PATHS` now explicitly includes lookup, speed, dns-lookup, network-scan, privacy, terms, aup, sla, abuse, careers, contact and badge. GET and HEAD preserve the path, query string and optional language prefix while redirecting to the fixed HTTPS primary host. Existing boundary matching requires a complete path segment.

Webmail, mail discovery, accounts, login, admin, API, robots and unsafe methods retain their existing behavior. No DNS, mail transport, network configuration or authentication policy changes. This is a bounded application redirect, not a hostname-wide web-server rule.

Local PHP 8.4.28 acceptance: 28 tests, 375 assertions, no failures/errors/skips, covering the console host and session-cookie domain. Cases exercise all three locale prefixes, GET/HEAD, query preservation, similar-prefix isolation, mail endpoints, API paths and non-GET methods.

The two-file limited release uses the exact reviewed search-growth deployment engine from develop `12e2bc6868524b0eaed40ced57d4a9d47c46c994`, pinned by SHA-256. It preflights both live source hashes, lint, private backup and shared release lock. Both already verified FPM pools check the actual routing contract after targeted OPcache invalidation; no general cache flush or view compilation is needed. Rollback uses the same guard and verifies the old contract.

Before hash: `4b601e7f515668911ad9ca6cbe725a702e73d5a0a37932556dcb1cb11e6408f0`.

Public acceptance must check redirects as well as the unaffected primary pages. Request Google validation only after confirming the sampled public duplicates are consolidated. Search Console's completion and rankings remain later results.

Font-bundle/GPU/search-guide acceptance: 28 tests, 1,296 assertions passed.

Production: PR436 merged as `a0bfe990f0230a747993e4f59ca5da3c4c5e83a2` after all eight triggered CI workflows succeeded. Two runtime targets installed and verified. Both FPM pools confirmed the redirect/private-route contracts and three served embedded fonts, with zero compiled views and no general cache flush.

Public acceptance: 25 of 25 historical sampled mail-host URLs returned 301 to the exact HTTPS primary path; all 25 primary destinations returned 200. Search Console showed Validation started, dated 24 November 2026. These samples do not prove all 454 historical group URLs are resolved, and Google's final verdict remains pending.

The first post-release mobile PageSpeed run removed the render-blocking CSS warning but reported a 0.088 layout shift attributed to the GPU hero and the remaining medium font. The guarded inline bundle now embeds the exact served 500-weight font as well as 400/700, so pricing text does not wait for an optional external font. CSS rules, glyphs and metrics are unchanged; unknown or invalid fonts still trigger the complete external fallback. The public-style tests verify exact served font bytes and fallback behavior. A fresh measurement is required after publication; the first run alone did not establish a speed improvement.

A later fresh GPU run still showed hero CLS 0.089 after the medium-font change. The separate gpu-rendering-20261124 release therefore retains external full styles specifically for GPU. The main-host routing fix is unchanged.
