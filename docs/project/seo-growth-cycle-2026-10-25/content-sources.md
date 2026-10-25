# Editorial refresh evidence — 25 October 2026

## Scope and current status

Two existing blog articles received reviewed source packages with complete Persian, English and Turkish content:

- `website/resources/content/growth/object-storage-vs-block-storage.json`
- `website/resources/content/growth/php-version-upgrade-guide.json`

This note records editorial preparation and source validation. It is **not a production publication receipt**. Use the cycle's release record for installation, CMS application and live acceptance status.

Base: `f396d1ebf7116df11000deeecaf9ebd83560bd71`, `servernetir/server` → `develop`. The author inspected the fresh, private CMS snapshot of all six original translations provided by the release owner. Existing posts are storage ID 67, published 2026-08-02, and PHP ID 42, published 2026-07-25. Original URL slugs and **all six original titles are preserved exactly**. The existing apply command preserves post status, category, author, publication date, cover and other post metadata. Excerpts and substantial article bodies change; the normal translation update time can therefore reflect a real edit.

The fresh GSC baseline is [gsc-baseline.json](gsc-baseline.json). English PHP is growing: 37 clicks / 1,738 impressions in 2026-09-25–2026-10-22 versus 8 / 297 in the prior 28-day window. Its 48 reported query rows contain no clicks and only a small part of page impressions; they cannot identify which queries generated those 37 clicks. The safe-upgrade intent and current successful title remain. No search-volume, conversion or traffic-recovery claim is made.

## What was retained and corrected

| Article | Useful coverage retained | Substantive correction or addition |
|---|---|---|
| Object vs block | Definitions, operating model, database vs uploads, hybrid architecture, cost components, migration pilot and troubleshooting | Remove universal latency, unlimited-capacity and automatic multi-datacenter claims. Replace public-read upload advice with explicit private/public policies. Add authorization, signed-link limits, whole-cost comparison, upload/read-back acceptance, byte comparison, reconciliation and bounded migration. |
| PHP upgrade | Supported-version choice, compatibility, isolated staging, functional tests, backup/rollback and controlled production switch | Remove PHP 8.1 as target, unsupported 30% improvement, ineffective CLI-only FPM rollback and all-databases restore example. Add current branch support, CLI/web/worker distinction, dependency lock/platform checks, compatibility matrix, reproducible staging, restore rehearsal, schema/write compatibility and meaningful acceptance. |

The storage example uses Bash and documented AWS CLI S3 calls against a **dedicated private test bucket**, with endpoint and region supplied by the chosen service. It creates a temporary local directory, stops on command failure, uses a new test key, downloads the object and compares bytes. It does not set public-read. Support for these API operations, policies and signed URLs on a particular ServerNet plan still requires service-specific confirmation.

The old Docker Compose snippet exposed a broad application directory and selected unsupported PHP 8.1 without installing the required database extensions. The replacement explains use of the application's reviewed container configuration, matching extensions, separate volumes/secrets and a loopback-bound test port; it does not present a bare image as a complete staging environment.

## Primary sources checked on 25 October 2026

The links below are embedded beside the corresponding claims in the public copy. Explanations are original summaries and operational reasoning; no long vendor passage was copied.

### Storage

| Source | What it supports |
|---|---|
| [Amazon S3 overview](https://docs.aws.amazon.com/AmazonS3/latest/userguide/Welcome.html) | Object/bucket/key model and object-storage examples; AWS guarantees are not transferred to ServerNet. |
| [Amazon EBS overview](https://docs.aws.amazon.com/ebs/latest/userguide/what-is-ebs.html) | Attached volume model and volume/snapshot distinction. |
| [S3 security best practices](https://docs.aws.amazon.com/AmazonS3/latest/userguide/security-best-practices.html) | Private access policy and keeping ACLs disabled for most current S3 use cases. |
| [Object Ownership errors](https://docs.aws.amazon.com/AmazonS3/latest/userguide/object-ownership-error-responses.html) | ACL restrictions with Bucket owner enforced and AccessControlListNotSupported. |
| [Presigned URLs](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-presigned-url.html) | Temporary bearer access, reuse until expiry and signing-credential expiry. |
| [CLI put-object](https://docs.aws.amazon.com/cli/latest/reference/s3api/put-object.html) | Upload command/options. |
| [CLI get-object](https://docs.aws.amazon.com/cli/latest/reference/s3api/get-object.html) | Read-back command/options. |

No fixed prices, latency thresholds, universal multi-attach behavior, unlimited capacity or unverified ServerNet replication guarantee is introduced. Cost formulas are a decision worksheet; rates and billing units must come from the selected plan.

### PHP

| Source | What it supports |
|---|---|
| [Supported Versions](https://www.php.net/supported-versions.php) | 8.4/8.5 active support and 8.2/8.3 security-only status at the stated review date. |
| [Unsupported Branches](https://www.php.net/eol.php) | PHP 8.1 end of support on 2025-12-31. |
| [Migration to PHP 8.4](https://www.php.net/manual/en/migration84.php) | Branch-specific incompatibility/deprecation review. |
| [Migration to PHP 8.5](https://www.php.net/manual/en/migration85.php) | Branch-specific incompatibility/deprecation review. |
| [Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md) | Installing reviewed lock versions vs separately updating dependencies. |
| [Composer check-platform-reqs](https://getcomposer.org/doc/03-cli.md#check-platform-reqs) | Actual runtime/extensions check and config.platform distinction. |
| [PHPCompatibility upstream](https://github.com/PHPCompatibility/PHPCompatibility) | Optional cross-version static analysis, target-version setting and incomplete coverage. |
| [cPanel MultiPHP Manager](https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/) | Per-domain installed PHP settings, subject to hosting configuration. |

No full compatibility promise, zero-risk upgrade, fixed speed increase or five-minute rollback guarantee is retained. The actual application's integration tests and restore results remain required.

## Internal-link validation and exclusions

All locale versions of the following final body destinations returned HTTP 200 at their own canonical URL, with one H1 and matching `html lang`, during this review:

| Article | Body destinations, with fa/en/tr prefixes |
|---|---|
| Object vs block | `/cloud/object-storage`, `/cloud/block-storage` |
| PHP upgrade | `/vps/hourly`, `/hosting/linux` |

There are 12 final article-body product links across six translations. The initial broader 18-destination check also found staging/backup guide URLs returning 200, but content review showed that they were not suitable to promote yet. HTTP success was not treated as editorial approval:

- `/en/blog/staging-environment-setup`: the technical reviewer found outdated action examples, unsupported managed-staging claims, robots.txt-as-noindex guidance and an overbroad application copy. No new package links to this guide.
- `/en/blog/website-backup-strategy`: direct body review found a root-password-in-command example, deletion of retained files without checking backup success, a truncated lftp example and a misleading database-size condition for consistent backup. No new package links to this guide.

Those existing URLs were not overwritten as part of these two packages; the findings belong in the next editorial backlog. The new articles themselves explain staging and restoration sufficiently to be useful without following those guides.

## Validation actually performed

- JSON parsed with complete locale order `fa`, `en`, `tr`; all required title/excerpt/content fields present.
- All six titles compared byte-for-byte with the fresh CMS snapshot and preserved.
- Six HTML fragments parsed; no H1, scripts, iframes, forms or inline click handlers in article bodies.
- Body links use the article's language prefix; excluded staging/backup links are absent.
- All nine displayed shell blocks pass `bash -n` (storage: one per locale; PHP: two per locale).
- `git diff --check` passed in the working checkout.
- No production CMS write, real storage upload, PHP upgrade, paid service, real payment or restore of customer data was performed by this editorial task. Bash syntax validation is not an API or operational acceptance test.

Whitespace-delimited body counts are editorial measurements, not an SEO word target:

| Article / locale | Original | Revised |
|---|---:|---:|
| Storage FA | 1,654 | 1,236 |
| Storage EN | 1,588 | 1,152 |
| Storage TR | 1,328 | 984 |
| PHP FA | 1,518 | 1,233 |
| PHP EN | 1,410 | 1,154 |
| PHP TR | 1,199 | 942 |

Repeated introductions, unsupported promises and unsafe command recipes were removed; the decision and operational coverage is retained or strengthened. This is not a shortened placeholder or a new set of thin pages.

## Release requirements

1. Back up the exact current rows of the two posts and their six translations privately; retain the original post metadata and content for rollback.
2. Install only the two reviewed JSON sources, guarding any existing files by current hash. Recheck the installed `content:apply-growth` implementation against the reviewed command.
3. Preview each existing slug and capture its fresh CMS snapshot. Abort if the slug is missing, belongs to another content type, has unreviewed editor changes or no longer matches the reviewed state. Do not use `--expect=absent` for these existing posts.
4. Apply each package only with the exact preview snapshot and verify both translation content and preserved metadata. On failure, use a guarded rollback that does not overwrite a later editor change.
5. Refresh the relevant blog/page/sitemap cache state using the release owner's scoped method. Verify all six live URLs, titles, language, self-canonical, translated alternates, structured data, publication dates, source-equivalent article body and real links.
6. Record publication time and evidence in the release record. Measure complete comparable GSC windows later; HTML publication is not Google indexing or traffic growth.

Source hashes at editorial handoff:

- Storage: `ee0cb60e302b965e088584817cea90d8f4efb9118938b03a1453f908c74603c5`
- PHP: `e79e3dff0a6c57dc4b63ed4b19a23de6e3b75f18139292cc9d918f5e6173d48e`
