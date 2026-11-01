# Private BlogRepository release — prepared, not executed

Reviewed source change: commit `6527322d`, base `891a9235`; exactly `app/Services/BlogRepository.php`, eager-loading author IDs and names in `index()`. Before SHA256 `78d4310b9262476e25fb7d77df174c0c38a6fc0872d5bbad9aaa03ac0d2e0117`; after `8769aff87efe64a7871a0f399d6e806d6d1027337fdf973c71df2c5a67a765df`. No prices, routes, metadata contents, eligibility or database migrations change.

## Private staging

Inspect any existing `/home/servernetcloud/.seo-blog-authors-20261101` before acting. Do not overwrite an existing stage or rerun a release blindly. Create this directory only if absent, mode 0700, owned by the cPanel account. Transfer the package files there, mode 0600, without extracting into the app or public directory. Compare every byte hash with `manifest.json` before execution. Candidate is `candidate.php`; the installer is `release.php`.

The installer takes, in existing-core order, `.servernet-web-release.lock`, `servernet-release.lock` and `.codex-web-deploy.lock` in the account home. Every lock is nonblocking. Keep unrelated deployment work paused/coordinated; hash checks still refuse newer source or mode changes. Installer execution only supports CLI PHP; use `/opt/cpanel/ea-php84/root/usr/bin/php`.

## Source preparation and installation

1. Run private `release.php preview`. It checks the exact live/candidate hashes, lints the candidate, creates the private source backup and obtains isolated CLI cold/warm metadata samples. Inspect `preview.json`. Only array cache is used for the probe; shared cache is not flushed. Only metadata hashes and counts leave the probe, never author names or article text.
2. After review, run private `release.php apply` once. It rechecks the before-state, persists `intent.json` before the first source mutation, atomically writes the single PHP file while preserving its mode and runs a separate CLI process against the installed source. Exact per-locale metadata hashes must match; warm cache must use zero SQL and author queries may not increase or exceed one. The applied status remains **pending web acceptance**.
3. A failed post-write acceptance still has the intent and backup. Inspect state and run the guarded `release.php rollback`; do not rerun apply. Rollback refuses an unknown newer source hash or permission change. It also requires web activation afterward. No broad cache/OPcache reset is provided or authorized by these scripts.

Preview/apply/rollback are single-use steps. The backup, preview and intent remain private for recovery. Array cache/session/mail and a Null logger are configured immediately after LoadConfiguration, before provider registration; ErrorTracker is redirected to the private stage before bootstrap. Database and HTTP resolving guards are installed before providers can use them. SQL guards also refuse output files, locking reads, sleep/benchmark and file-read functions. The installer itself does not bootstrap the application.

## Same-account web OPcache and execution acceptance

The current pool settings must be observed; old documentation is not proof. CLI OPcache is independent of PHP-FPM. The package contains a narrow adaptation of the existing same-account FastCGI activation pattern:

1. Create `/home/servernetcloud/public_html/_seo_blog_authors_20261101` only if absent, owned by this account and mode 0700. Install only `.htaccess` containing exactly `Require all denied` plus LF and `activate.php` copied byte-for-byte from private `blog-fpm-activation.php`; both files mode 0600. If the directory already exists, stop and inspect. Confirm anonymous HTTP is denied on both website and console before invoking it. There is no HTTP token or public authentication bypass.
2. Use private `blog-fpm-activate.py --socket <explicitly observed same-account socket> --host <servernet.cloud or console.servernet.cloud> --action observe --phase before` while the source is still at the baseline; use `--phase after` once installed. The caller checks account, socket ownership, private primary permissions and reviewed source hash. It has a bounded 45-second FastCGI deadline and rejects malformed, truncated, oversized or stderr output.
3. After the CLI apply process ends and releases all locks, invoke `--action activate --phase after` separately against each confirmed pool. Only the exact BlogRepository target is invalidated if cached, then explicitly compiled into that pool's OPcache. No global reset, unrelated cache or worker restart occurs. Observe records include `validate_timestamps` and `revalidate_freq`.
4. Invoke `--action verify --phase after` in a fresh request for each pool. This actually boots the app with isolated array cache and executes the public metadata index, with SQL-write and HTTP guards. Compare the emitted per-locale metadata hashes and query counts; this is stronger than inspecting a disk hash. It emits no metadata plaintext. A changing CMS catalogue may legitimately fail the exact comparison; stop and inspect instead of relaxing the guard. This verification proves the query pattern for those reads, not purchase tracking, LCP or performance stability.
5. If source rollback is needed, release.php restores only its own file after a drift guard. Re-run target-only FPM `activate` then `verify` with `--phase before` for each pool. This permits the original query pattern while still requiring exact baseline metadata.
6. After acceptance, run private `blog-fpm-cleanup.py` to atomically move the byte-pinned temporary directory (only primary and exact denial file) into the private stage's previously absent `retired-primary` directory. New/unexpected files stop retirement; files are retained for recovery. Verify public HTTP 404 separately on both domains; the script does not claim it. Retain private backups and receipts.

Save FastCGI JSON outputs privately as `fpm-*.json` files, without overwriting earlier receipts. Run private `blog-summary.py` afterward to collect known CLI and FastCGI receipts into `release-summary.json` for File Manager inspection. It only reads private JSON and writes that private summary.

## Local validation of preparation

Three PHP files lint. Six offline installer fixtures passed, including exact install/rollback, single use, candidate/backup drift, refusal to overwrite newer source and recovery after a failing post-install probe. These fixtures substitute a synthetic probe and do not claim production application acceptance. Six existing-pattern FastCGI wire tests passed (lengths, fragmented responses, deadlines, stderr, truncation and output bounds). Python syntax was parsed without network access. No production installer, OPcache activation, HTTP endpoint, SQL mutation or Google request was executed during this preparation.

The source regression suite remains the separately recorded 31 tests / 124 assertions. Test counts from repeated runs are not added together. LCP impact and real-user Core Web Vitals remain unmeasured.
