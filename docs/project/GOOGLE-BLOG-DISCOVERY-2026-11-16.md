# Automatic Google discovery after trilingual blog publication

Base: develop e6538b2a3ca4640780a540f6a4e09c8bb986f5e3. Active app: website/.

This adds a persistent outbox after native daily publication, plus a five-minute recovery scanner for published posts changed since activation. Only complete, already-published fa/en/tr articles qualify. Public sitemap XML must contain all three URLs and nonfuture lastmod values before the fixed blog sitemap is submitted to the fixed servernet.cloud Search Console property. This is sitemap submission, not a direct indexing request or an indexing guarantee. Google limits its Indexing API to eligible job/livestream pages; URL Inspection is read-only.

A separate encrypted OAuth grant requests webmasters plus email identity. Existing read-only reporting, Calendar and Drive credentials remain separate. The existing registered Calendar redirect dispatches only exact session-bound state to this flow. PKCE, admin role, actor binding, ten-minute state expiry, property permission verification, credential binding and refresh concurrency checks apply. Connecting requires one-time consent by the site owner; no per-post browser session is needed afterward.

Admin page: /admin/ai-growth/google-discovery. Artisan: seo:google-discovery; --status is read-only, --post=ID explicitly backfills one eligible article. Persistent receipts use the existing growth_automation_runs table; no migration. Retries use bounded backoff; no indexing claim is made on submission. Inspections run at approximately 6, 24, 48 and 72 hours, retaining each locale result. A complete receipt may remain unindexed; the UI states that explicitly. Authorization loss pauses affected receipts without charging retry attempts. Persistent API/site errors stop after six attempts and remain visible.

Planned scoped runtime files: SearchConsoleClient, GoogleDiscovery, GoogleDiscoverBlog, SearchConsoleController, CalendarController callback dispatch, DailyBlogPublisher after-commit hook, ai-growth routes, growth index link, discovery view and three language files. Isolated CI tests use HTTP fakes only. Production installation must preserve shared release locks, backup preimages, compare hashes, lint PHP, compile only changed views, preserve other cron entries and verify queue state. Do not deploy by copying the whole repository or clearing shared application caches.

Cron target after verified installation: */5 * * * * cd /home/servernetcloud/servernet_app && /opt/cpanel/ea-php84/root/usr/bin/php artisan seo:google-discovery >> /home/servernetcloud/.google-discovery-20261116/cron.log 2>&1 # servernet-google-discovery-v1

Rollback: disable google_discovery_enabled first, remove only the marked cron entry with a crontab preimage retained, restore only unchanged release files under shared locks. Do not revoke the shared Google OAuth client. Existing receipts remain auditable.

## Verified Git delivery, 16 November 2026

PR #322 merged into develop without conflicts as 8fa30688c36323b6f472d13f2f6b304c047ae6c4. Tested head: 6ba73753050a5f4230930cfa87d7abf7b7af1518. PHP 8.4.27 isolated CI: GoogleDiscoveryTest, DailyBlog, AdminCalendarGoogleTest and ManagerGoogleReportingTest passed 79 tests / 499 assertions (run 43859480269); native publishing and growth review gates separately passed 49 tests / 627 assertions (run 43859480319). Route source integrity and customer cloud API contract also passed. Other repository regression workflows were still running at merge; this report does not claim full repository health.

Production status: NOT INSTALLED. No feature setting, token, production file or cron entry was changed for this discovery feature. The previously authorized ServerNet cPanel terminal stopped responding. Another open cPanel account was identified as a different customer and was not modified. Automatic access review rejected opening a broader WHM administration session; no root workaround was attempted after rejection. Resume only through an active servernetcloud account session.

Remaining acceptance: scoped installation with live baseline checks and preimages; enable feature and preserve existing cron entries; queue existing published post 560; complete one-time site-owner Google webmasters consent; observe actual empty-success sitemap PUT and the persisted accepted receipt. Until then, no automatic submission or indexing is claimed. The publication cron from the earlier native-blog release is a separate existing feature.
