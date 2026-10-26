# Git deployment guide: tested source, publication pending

Recorded 26 October 2026. Run `growth-cycle-20261026-0202-git-guide`; task `git-deployment-guide-safe-example`.

## Exact outcome

[PR #132](https://github.com/servernetir/server/pull/132) proposes one existing article in Persian, English and Turkish. The tested feature head is `d2c391c5b6a2b82480032f84fc9b92e330659fc0`; the coordination base is `feb7787c806156f1960b8e7a4f248463bb1ff5d6`. **The implementation is committed on the feature branch but is not merged, installed or published.** This receipt and the backlog update on develop are documentation only.

The old public tutorial contains an unenforced IP allowlist, a public PHP shell-execution example and a query-string token suggestion. This is a defect in published guidance, not evidence of an installed vulnerable endpoint. The replacement removes runnable public deployment handlers, explains limited deployment identities, reviewed releases, staging isolation, signed webhooks, backups and bounded rollback. Existing titles and URLs are preserved, and each locale links contextually to its existing hourly VPS page. The source uses the existing guarded CMS command; it does not change routes or application business logic.

## Validation actually executed

[Actions run 40273727345](https://github.com/servernetir/server/actions/runs/40273727345), tested head above:

| Check | Actual result |
|---|---|
| Python source and disposable Git fixtures | 6 tests passed |
| PHP syntax | Passed |
| Direct PHPUnit, strict warning failure | 19 tests, 522 assertions passed |
| Existing live public pages | 15 passed; 0 failed; 0 unknown; no retries |

The application suites were `GitDeploymentArticleReviewTest`, `GrowthArticlePublishingTest` and `BlogSitemapLastmodTest`, with PHP 8.4.27, PHPUnit 12.5.31, locked dependencies and isolated SQLite fixtures. No production environment file or credentials were supplied. The exact command was `vendor/bin/phpunit --filter='GitDeploymentArticleReviewTest|GrowthArticlePublishingTest|BlogSitemapLastmodTest' --display-warnings --fail-on-warning --colors=never`.

The initial run 40273544782 used the artisan wrapper and reported 19 warnings, without assertion failures. Running the unchanged application/tests directly through PHPUnit with strict warning failure passed. This does not establish that an application bug was fixed. Composer still emitted unrelated PSR-4 fixture notices for `tests/Fixtures/whmcs-price-import.php`; they were not changed.

The public observation completed at `2026-10-26T05:21:09.005064+00:00`. It checked fa/en/tr versions of hourly VPS, GPU, Git deployment, Object/Block comparison and PHP upgrade: HTTP 200, `X-Cache: BYPASS`, one H1, correct language, title, self-canonical, alternate links, no noindex and parsable JSON-LD. The existing read-only checker used bounded GETs without credentials or redirects. These are observations of existing production pages, **not acceptance of the proposed article**, JavaScript/mobile tests, Google indexation or traffic evidence. See [machine-readable receipt](git-guide-validation.json).

## Diff and source checks

The PR adds four files only: the article JSON, its focused PHP regression tests, a Python source/CLI fixture test and a read-only test workflow. It does not update live CMS. The workflow uses standard hosted runners only for the public repository, read-only repository permission, non-persisted checkout credentials and commit-pinned actions. No production deployment step, paid browser automation, provider order or external message was used.

Primary references checked: [GitHub signature validation](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries), [GitHub webhook best practices](https://docs.github.com/en/webhooks/using-webhooks/best-practices-for-using-webhooks), [cPanel Git deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/), [Git fetch](https://git-scm.com/docs/git-fetch), [Git ignore](https://git-scm.com/docs/gitignore) and [GitHub deploy keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys).

Independent editorial/code review has not been obtained. CI is not an independent reviewer. In that review, clarify the Persian inspection wording: `git fetch` updates repository references/FETCH_HEAD although it does not install the candidate or modify the working tree; clean porcelain output also does not establish that ignored runtime files are absent. The fixtures explicitly demonstrate both distinctions. The reviewed release should preserve this precision.

## Remaining gates and rollback

Connected Browser access failed because the originating browser context was unavailable. The local runtime could not connect directly to the origin; no authenticated production execution path was established. Plugin discovery found no usable installed SSH/cPanel connector. A metered browser service was not used because paid operations are outside this cycle. Google access is separately deferred and is not a prerequisite for this article's development.

Next action: continue PR #132 rather than repeat research or create another copy. Obtain independent review, reconcile with fresh develop and rerun affected checks if the source changes. Merge only after that gate. Before publication, verify a secure execution path; take private backups of the current source and exactly this post's three translations; acquire a real shared deployment lock; compare live/source hashes and the fresh CMS snapshot. Install only the approved source with a manifest, then apply `content:apply-growth git-basics-for-deployment --apply --expect=<fresh snapshot>` in the existing transaction. Preserve original publication date/status/author; verify actual body, localized links and cache bypass afterward.

If rollback becomes necessary after a later release, restore only those source bytes and translation rows after checking that they still match the release being undone. Preserve subsequent editor/customer changes; do not restore a whole production database, run a broad seed/migration or clear all caches. No production manifest, backup or rollback execution is claimed for this uninstalled proposal.

The run reservation is released in the accompanying backlog update. GSC was not fetched again, the previous baseline is unchanged, and no ranking, visitor or revenue improvement is claimed.
