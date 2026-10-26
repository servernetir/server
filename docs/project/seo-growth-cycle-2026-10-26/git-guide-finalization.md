# Git guide — review complete and merged; production pending

Recorded 26 October 2026, 06:36:47 UTC. Run `growth-cycle-20261026-0629-git-guide-finalize`; task `git-deployment-guide-safe-example`.

## Outcome and exact versions

[PR132](https://github.com/servernetir/server/pull/132) was merged into **develop** at `81c12f6650a7b44a5360feeda58eb89dc2699300` on **2026-10-26T06:35:51Z**. Tested and independently reviewed feature head: `6effc23d8026a589686dad1f3b70cb9a321fd56f`. The continuation started from develop `198199258bb761e0964a96d844d80711dc9f1d9d`; reservation commit `559e9c0f2685567e245b5599f514c9c874cee903` was recorded with an expected-SHA guard.

**Git: merged. Production files: not installed. Production CMS: unchanged by this run. New article public acceptance: not performed.** The existing database-backed article does not update merely because its reviewed source is merged.

## One coherent change

The existing `git-basics-for-deployment` article has a reviewed Persian, English and Turkish replacement. It removes the runnable public PHP deployment-handler example, explains release selection, least privilege, signed webhooks, staging isolation, backup and bounded rollback, and adds one relevant hourly-VPS link in each language. This corrects published guidance; it is not evidence of an installed vulnerable endpoint on ServerNet.

The final inspection paragraph now explicitly distinguishes Git repository writes from worktree deployment: fetch writes objects/references and normally FETCH_HEAD; clean porcelain status does not establish absence of ignored runtime files, secrets or external data. Original article titles and URLs are preserved. The existing guarded CMS publishing command preserves the original publication state/date/author when this source is eventually applied.

The implementation diff contains four added files only:

- `website/resources/content/growth/git-basics-for-deployment.json`
- `website/tests/Feature/GitDeploymentArticleReviewTest.php`
- `scripts/seo-growth-cycle/test_git_deployment_article.py`
- `.github/workflows/seo-git-guide-check.yml`

No application business logic, price, payment, provisioning, authentication, customer account, infrastructure or host cron changes belong to this PR. Concurrent team changes were preserved. Immediately before merge, PR133 advanced develop only in `NETWORK-CURRENT.md` and `SEO-DAILY-2026-10-26.md`; the post-merge comparison against the tested head contains only those two documentation changes. Runtime, article, test and workflow bytes match the tested revision.

## Evidence actually obtained for the final head

[Push run 40280377150](https://github.com/servernetir/server/actions/runs/40280377150) and [PR run 40280380793](https://github.com/servernetir/server/actions/runs/40280380793) completed successfully. The fetched push-job logs show:

| Gate | Result |
|---|---|
| Python source and disposable local Git fixtures | 6 tests passed |
| PHP syntax | Passed |
| Direct PHPUnit with strict warning failure | 19 tests, 522 assertions passed |
| Bounded existing production HTML observation | 15 passes, 0 failures, 0 unknowns, no retries |

PHP 8.4.27, PHPUnit 12.5.31, locked dependencies, isolated SQLite memory fixtures, no production environment file or credentials. The exact PHP command was `vendor/bin/phpunit --filter='GitDeploymentArticleReviewTest|GrowthArticlePublishingTest|BlogSitemapLastmodTest' --display-warnings --fail-on-warning --colors=never`. Unrelated Composer PSR-4 fixture notices remain unchanged; the earlier artisan-wrapper warnings are historical and are not presented as application bugs repaired here.

The [separate Codex code review](https://github.com/servernetir/server/pull/132#issuecomment-6409816508) completed on the exact final head at 06:34:12 UTC with no reported or unresolved findings. This is an independent automated review, not a human security audit or guarantee.

The public observation at `2026-10-26T06:32:16.305904+00:00` covered fa/en/tr versions of `/vps/hourly`, `/gpu`, `/blog/git-basics-for-deployment`, `/blog/object-storage-vs-block-storage` and `/blog/php-version-upgrade-guide`. All returned HTTP200 and `X-Cache: BYPASS`; title, one H1, language, self-canonical, alternates, no-noindex and JSON-LD parsing checks passed. These are prepublication checks of existing pages, not acceptance of the proposed article, JavaScript/mobile behavior, selected Google canonical, traffic or conversion results.

## Sources checked

Official [Git fetch](https://git-scm.com/docs/git-fetch), [Git status](https://git-scm.com/docs/git-status), [GitHub webhook validation](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries) and [cPanel Git deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/) were checked for the final clarification. The article also cites GitHub webhook best practices. No keyword volumes or ranking effects were invented.

## Remaining publication gate

The connected Browser returned an unavailable-originating-context error; local direct network/DNS access failed. No authorized private production execution path was established. This is separate from the owner's deferred Google login. No production source snapshot, CMS backup, release lock, manifest installation or rollback execution is claimed.

Continue from the merged source. Before any write, verify current production access, obtain the actual source hash and this post's three-translation CMS snapshot, take scoped private backups, and acquire the real shared deployment lock. Install only the reviewed JSON with before/after hashes and an exact file manifest; then apply the existing `content:apply-growth` transaction using the fresh expected snapshot. Preserve publication date/status/author and verify all three public bodies, localized links, actual cache bypass and sitemap dates. No broad seed, migration or global cache flush.

Rollback is limited to this release's source and translation rows, only while their current hash/snapshot still matches the release being undone. Preserve later editor/customer changes; never restore the whole production database for this article.

The accompanying backlog update releases only this run's reservation and records `merged_pending_deployment`. Do not redraft the same article or open a duplicate PR. If production access remains unavailable in the next cycle, retain this installation gate and select another bounded independent task rather than repeating this work. GSC was not fetched by this run; Google login and paid/external messaging operations were not attempted. Ranking, visitors and revenue effects remain unmeasured.
