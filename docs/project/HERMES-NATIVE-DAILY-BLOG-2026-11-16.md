# Native daily trilingual blog — 2026-11-16

Owner authorization: replace the ChatGPT publishing task with a server cron that researches and publishes automatically. The previous ChatGPT task has been disabled.

## Runtime
- Command: `php artisan growth:daily-blog`; read-only `--status` and `--discover` options.
- A dedicated cPanel cron calls this command every 15 minutes. The command gates work to one Tehran day after 08:00; successful or blocked days are terminal. Source outages retry hourly up to six attempts. No historical backfill. The existing application scheduler is unchanged.
- Shared `growth-center-run` cache lock serializes this worker with the existing Growth runner. Unique daily run receipts, stable daily post slug and transactional publication prevent duplicate/partial posts.
- Official RSS/Atom sources only: NVIDIA Developer, Cloudflare, Kubernetes, PyTorch. Reject external article hosts, credentials, query URLs, redirects, XML entities, stale/future dates and oversized responses. Rank fresh relevant items deterministically and retain a dated primary-source snapshot and SHA. This is not an unrestricted news search engine.
- Existing GrowthGateway/GrowthJobs provide model authorization, current quotes, daily/monthly budget reservations, actual-cost ledger and uncertain-call protection. A single bounded model request produces all three locales; no ChatGPT session, browser or new external API key is needed. Model provider API access is still required.
- `daily_blog_enabled=1` plus `daily_blog_actor_id` referencing an existing admin and enabled Growth controls authorize this explicit program. Revoking either prevents generation and publication. Budgets are not increased.
- Article validator checks structure, all three locales, length, script, headings, unsafe markup/links and business claims. Links and source attribution are added by the server. These automatic checks are not a guarantee of perfect factuality or rankings; the model must use the supplied dated source only.
- One Post and three translations are committed before published state. Translation `auto=true`; human reviewer/approved_by is not fabricated. Failed or uncertain generation is retained for receipt review, never automatically charged again.

## Operations
Read `growth_automation_runs` keys `daily-blog:YYYY-MM-DD`, Growth jobs/usage receipts and Growth audit records. CLI status omits article snapshots. On failure inspect receipt before any manual retry. Do not delete run/job records to force another paid call.

Pause: set `daily_blog_enabled` to `0`. This also stops queued daily-blog calls in the shared Growth worker. Do not alter unrelated campaign controls or budgets.

Rollback: pause first, then restore only the release manifest files from private preimages; retain published articles and cost/run receipts. No schema migration.

## Validation and release
Implementation prepared in an isolated worktree based on develop 39d648e2f2ed5b2a7108b3811a201b4b19fc25b9. Fake-provider tests cover trilingual atomic publication/idempotence, language/markup failures, budgets, delegation, lock, uncertain calls, source retries, stale/future dates, external URL and XML entity rejection. Existing review gates are included in CI. Deployment and first real publication are recorded separately after acceptance.
