# Automatic Google discovery after trilingual blog publication

Base: develop e6538b2a3ca4640780a540f6a4e09c8bb986f5e3. Active app: website/.

This adds a persistent outbox after native daily publication, plus a five-minute recovery scanner for published posts changed since activation. Only complete, already-published fa/en/tr articles qualify. Public sitemap XML must contain all three URLs and nonfuture lastmod values before the fixed blog sitemap is submitted to the fixed servernet.cloud Search Console property. This is sitemap submission, not a direct indexing request or an indexing guarantee. Google limits its Indexing API to eligible job/livestream pages; URL Inspection is read-only.

A separate encrypted OAuth grant requests webmasters plus email identity. Existing read-only reporting, Calendar and Drive credentials remain separate. The existing registered Calendar redirect dispatches only exact session-bound state to this flow. PKCE, admin role, actor binding, ten-minute state expiry, property permission verification, credential binding and refresh concurrency checks apply. Connecting requires one-time consent by the site owner; no per-post browser session is needed afterward.

Admin page: /admin/ai-growth/google-discovery. Artisan: seo:google-discovery; --status is read-only, --post=ID explicitly backfills one eligible article. Persistent receipts use the existing growth_automation_runs table; no migration. Retries use bounded backoff; no indexing claim is made on submission. Inspections run at approximately 6, 24, 48 and 72 hours, retaining each locale result. A complete receipt may remain unindexed; the UI states that explicitly. Authorization loss pauses affected receipts without charging retry attempts. Persistent API/site errors stop after six attempts and remain visible.

Planned scoped runtime files: SearchConsoleClient, GoogleDiscovery, GoogleDiscoverBlog, SearchConsoleController, CalendarController callback dispatch, DailyBlogPublisher after-commit hook, ai-growth routes, growth index link, discovery view and three language files. Isolated CI tests use HTTP fakes only. Production installation must preserve shared release locks, backup preimages, compare hashes, lint PHP, compile only changed views, preserve other cron entries and verify queue state. Do not deploy by copying the whole repository or clearing shared application caches.

Cron target after verified installation: */5 * * * * cd /home/servernetcloud/servernet_app && /opt/cpanel/ea-php84/root/usr/bin/php artisan seo:google-discovery >> /home/servernetcloud/.google-discovery-20261116/cron.log 2>&1 # servernet-google-discovery-v1

Rollback: disable google_discovery_enabled first, remove only the marked cron entry with a crontab preimage retained, restore only unchanged release files under shared locks. Do not revoke the shared Google OAuth client. Existing receipts remain auditable.

Status at preparation: implemented; CI, production installation, OAuth consent and first real sitemap acceptance still pending. Update this section only from observed evidence.
