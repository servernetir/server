> **Update after requested manual acceptance:** v2 generates one bounded JSON response per locale because the live Gemini registry caps a request at 4096 output tokens including thinking. All three calls are quoted and reserved together, receipts remain separate, and the post still publishes atomically only after all three bodies pass quality checks. Default cron never retries a blocked/uncertain call. `--retry-reviewed --actor=ID` is an explicit operator action that preserves prior receipts, refuses active work, is idempotent while queued/published, and allows at most two reviewed retries. Historical single-call references below describe the pre-acceptance version. No global model limits or customer budget settings are changed.

# Native daily trilingual blog — 2026-11-16

Owner authorization: replace the ChatGPT publishing task with a server cron that researches and publishes automatically. The previous ChatGPT task has been disabled.

## Runtime
- Command: `php artisan growth:daily-blog`; read-only `--status` and `--discover` options.
- A dedicated cPanel cron calls this command every 15 minutes. The command gates work to one Tehran day after 08:00; successful or blocked days are terminal. Source outages retry hourly up to six attempts. No historical backfill. The existing application scheduler is unchanged.
- Shared `growth-center-run` cache lock serializes this worker with the existing Growth runner. Unique daily run receipts, stable daily post slug and transactional publication prevent duplicate/partial posts.
- Official RSS/Atom sources only: NVIDIA Developer, Cloudflare, Kubernetes, PyTorch. Reject external article hosts, credentials, query URLs, redirects, XML entities, stale/future dates and oversized responses. Rank fresh relevant items deterministically and retain a dated primary-source snapshot and SHA. This is not an unrestricted news search engine.
- Gemini Flash is preferred for latency, with a 9,000-token output bound; other existing models are considered only if authorized and affordable before inference. Existing GrowthGateway/GrowthJobs provide model authorization, current quotes, daily/monthly budget reservations, actual-cost ledger and uncertain-call protection. A single bounded model request produces all three locales; no ChatGPT session, browser or new external API key is needed. Model provider API access is still required.
- `daily_blog_enabled=1` plus `daily_blog_actor_id` referencing an existing admin and enabled Growth controls authorize this explicit program. Revoking either prevents generation and publication. Budgets are not increased.
- Article validator checks structure, all three locales, length, script, headings, unsafe markup/links and business claims. Links and source attribution are added by the server. These automatic checks are not a guarantee of perfect factuality or rankings; the model must use the supplied dated source only.
- One Post and three translations are committed before published state. Translation `auto=true`; human reviewer/approved_by is not fabricated. Failed or uncertain generation is retained for receipt review, never automatically charged again.

## Operations
Read `growth_automation_runs` keys `daily-blog:YYYY-MM-DD`, Growth jobs/usage receipts and Growth audit records. CLI status omits article snapshots. On failure inspect receipt before any manual retry. Do not delete run/job records to force another paid call.

Pause: set `daily_blog_enabled` to `0`. This also stops queued daily-blog calls in the shared Growth worker. Do not alter unrelated campaign controls or budgets.

Rollback: pause first, then restore only the release manifest files from private preimages; retain published articles and cost/run receipts. No schema migration.

## Validation and release
Implementation prepared in an isolated worktree based on develop 39d648e2f2ed5b2a7108b3811a201b4b19fc25b9. Fake-provider tests cover trilingual atomic publication/idempotence, language/markup failures, budgets, delegation, lock, uncertain calls, source retries, stale/future dates, external URL and XML entity rejection. Existing review gates are included in CI. Deployment and first real publication are recorded separately after acceptance.

## Production release evidence
- Initial code PR [318](https://github.com/servernetir/server/pull/318), implementation fc7cf0f2e04f437dd7e5b902717c26171644a4cd, merged as 283703c7de4279d9d797eef276f94c7e6272af03.
- Latency refinement PR [319](https://github.com/servernetir/server/pull/319), implementation 8b48eef58bb5e184519adf326dfc3d72ad22153b, merged as af6dd1b8887c64f3a453b10c975693272707ca84.
- Six runtime files installed with baseline hash guards, PHP 8.4 lint, shared release locks and private preimages. The two-file latency refinement was separately backed up, installed and hash verified. No migration, full application pull, credential change or budget increase.
- Production CLI confirmed enabled admin delegation, verified knowledge:19 replacing the ChatGPT policy, the preferred Gemini model and 9,000-token bound. The former ChatGPT task is disabled.
- Exactly one dedicated cron entry was installed, preserving all existing entries. It is independent of ChatGPT, Chrome, cPanel login sessions and the Hermes VM.

```cron
*/15 * * * * cd /home/servernetcloud/servernet_app && /opt/cpanel/ea-php84/root/usr/bin/php artisan growth:daily-blog >> /home/servernetcloud/.native-blog-20261116/cron.log 2>&1 # servernet-native-daily-blog-v1
```

The 15-minute cadence is for resuming source backoff and work. The native daily gate starts after 08:00 Tehran; it does not publish 96 articles a day.

### First live run: blocked, not published
The source probe fetched a dated NVIDIA Developer article and compared nine relevant fresh feed candidates. Live Gateway quotes were within the existing caps. The initial cheapest Qwen trilingual generation timed out at the Gateway's 240-second boundary and entered unknown_pending. The program correctly kept the cost ceiling reserved, left the article unpublished and did not send it again. The refinement prefers the existing lower-latency Gemini Flash model for future days; the unknown operation was not cleared, replayed or replaced. A subsequent production tick retained the same single attempt. Final status was read at 2026-11-16T10:29:58Z.

**No successful live trilingual publication or public-page acceptance is claimed yet.** The first post-timeout Gemini execution is still pending a future scheduled day. The regular Gateway recovery/reconciliation process retains ownership of the uncertain receipt.

### Tests and rollback
- Isolated PHP 8.4.27 / SQLite CI passed 46 tests and 593 assertions, including existing Growth review gates and new source, publication, rollback, budget and uncertain-call cases. The latency refinement's native regression job also passed; other triggered regression workflows passed. No paid provider was called by tests.
- Runtime dependencies remain the site's existing model Gateway, wallet/budget controls and public official feeds. Provider outages, uncertain paid receipts and failed automatic quality checks can prevent a day's publication; they must not be reported as success.
- Private deployment evidence and rollback preimages: /home/servernetcloud/.native-blog-20261116/. Pause daily_blog_enabled before rollback. Restore only manifest-owned files, retain cost/run receipts and existing posts. Remove only the cron entry bearing servernet-native-daily-blog-v1 if retiring the program.
