# SEO discovery production release — 16 November 2026

**Status: installed and verified in production.** This receipt supersedes the unconfirmed deployment status in [the discovery correction plan](../project/SEO-DISCOVERY-2026-11-16.md) for the scope below. It does not close the broader SEO backlog.

## Code and scope

Reviewed code: PR [315](https://github.com/servernetir/server/pull/315), merge `9756109a001a9fb269dd5072615dd80c4ec45866`; original baseline `1f8b8e15399d91132253e6aeda9144662bd97f5c`. Six PR check workflows passed. The commercial SEO job on head `eb4fc661a4835ed8886d132b0d9915561213d4ad` passed PHP 8.4 lint, 104 tests / 9,753 assertions, a separate order-indexability test / 5 assertions, the tool CTA JavaScript check and offline opportunity checks. These are CI results for that head, not a new full suite on production.

Production was not overwritten with the repository controller snapshot. Its full file diverged, while the exact `llms()` method matched the reviewed baseline. The release replaced only that uniquely bounded method, proved the reverse-byte reconstruction, linted the actual candidate with production PHP 8.4.27 and preserved all other methods, including the independently maintained sitemap implementation. The article JSON matched its known original hash before replacement.

Only two runtime files were installed:

| Relative app path | Before SHA-256 | After SHA-256 |
| --- | --- | --- |
| `app/Http/Controllers/SiteController.php` | `777a552762b8e826f912168e2642e62e55b746a29c92571da325cb6987be70e7` | `38992d9d2aa6ddbb6513944df1969f08d7a56afe648ac9bb99bf1778b95c0d7b` |
| `resources/content/growth/php-version-upgrade-guide.json` | `e79e3dff0a6c57dc4b63ed4b19a23de6e3b75f18139292cc9d918f5e6173d48e` | `8ff10eb343bac36f9a553190a78825b7a690a281fecd0a5ab4d25e2b63c73824` |

The two files retained UID 1020, GID 1021 and mode 0644. Installation completed at `2026-11-16T09:33:29.181304+00:00`.

## Content and release guards

The database step changed only the title and excerpt for the existing published PHP guide in fa/en/tr. Before applying, all three bodies, tags and manual-translation states were checked against the reviewed content. A private snapshot covered the article identity, publication state, translations and content hashes. A transaction locked the post and its translation rows and rejected changes since preview. Verification confirmed all six metadata fields and preserved the bodies, tags and auto flags. No other posts or publication dates were changed.

The release acquired all three shared release locks in the established order, checked before/after hashes and preserved private preimages. Source writes were atomic per file with fsync and original ownership/mode. Source preconditions were checked again immediately before replacement. Metadata saves used the existing model invalidation behavior and the blog-index flush; there was no blanket Laravel cache flush, OPcache reset, worker restart, migration, seeder or financial/provider operation. HTTP subsequently demonstrated that the updated code was active.

Private release directory on the host: `/home/servernetcloud/.seo-discovery-20261116-r1`. It contains the source preimages/candidates, `plan.json`, `metadata.before.json`, `metadata.after.json`, `apply.py`, `metadata.php`, `installed.json` and final `verified.json`. Files are outside webroot; the directory is 0700 and private artifacts are 0600. No public launcher or endpoint was created. The final verification reacquired the shared locks and rechecked both installed source hashes.

## Live acceptance

Five primary GETs succeeded: `/llms.txt`, the three language variants of the PHP guide with a PageCache-bypass query, and `/sitemap-index.xml`.

- Discovery text has no order-form links or obsolete SSH promise; it describes application endpoints, temporary files and the distinction between GPU apps and hosted model APIs.
- Each guide returned HTTP 200, one H1, the intended title and description, a clean language-specific canonical without the QA query, and parseable JSON-LD. Browser review confirmed the actual English heading and excerpt. Guest HTTP validation is independent of the existing browser session.
- The sitemap index remained HTTP 200 and retained all seven child maps. No sitemap-generation method was replaced.

Nine additional regression GETs covered GPU and hourly VPS in fa/en/tr, embedding model discovery, a reported CPU part URL, and the normal cached English guide URL. Eight succeeded initially. `/tr/vps/hourly` returned a first-request 503 and succeeded on one separate retry. Preserve that initial failure: intermittent response-path/server errors remain unresolved and are not a regression proven to be caused by this narrow release. No blanket GSC issue validation was started.

## Rollback and remaining work

No rollback was needed. Before any later rollback, compare both live file hashes with this release's after hashes and verify that the CMS state still matches `metadata.after.json`. If newer edits exist, reconcile them first. The private metadata helper supports guarded `rollback` for the six fields. Restore source bytes only from the matching private preimages using the recorded ownership/mode, shared locks and atomic-write checks; do not restore an entire older repository snapshot. Revalidate public discovery, all three guide variants and sitemaps after rollback.

This receipt closes deployment of PR315 and the guide metadata update. It does not claim resolution of intermittent 5xx, mobile LCP, all canonical/indexing issues or the pending GTM growth events. No ranking or traffic improvement has yet been attributed to this release.
