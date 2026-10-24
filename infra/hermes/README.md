# Hermes private release tools

These helpers belong outside every public document root. They are not HTTP endpoints and do not contain credentials. The application source is under `website/`; private state, secrets, logs, encrypted conversation files, customer data, and the unused Python queue experiments are excluded from this preservation bundle.

`deploy_web.py` preserves the existing live MiniApp controller, owner authentication, encrypted store, relay, chat, memory, and reconciliation behavior. Its narrow release adds monitoring, local font routes, support review services, one hourly schedule, and outcome auditing. The preserved foundation files are available in Git for review; this helper refuses to select their replacement.

The helper defaults to a plan. Before any separately authorized installation, prepare a fresh private manifest with every selected target's exact SHA-256 or `absent` precondition and each staged source's SHA-256. Hold the same release coordination/lock from preflight through acceptance. A stale manifest, existing route cache, changed source, symlink, duplicated route/schedule, or changed view anchor is a stop condition. Do not alter a manifest to bypass a concurrent change.

```sh
python3 infra/hermes/deploy_web.py \
  --stage-dir /private/selected-stage \
  --app-root /absolute/laravel-root \
  --expected-manifest /private/expected.json
```

An authorized installation also supplies `--install`, `--backup-dir /private/new-release-backup`, and `--lock-file /private/hermes-release.lock`. The backup must be outside the application and document roots. It contains a private manifest, verified prior files, and `rollback.py`; rollback defaults to a plan and refuses newer live edits. Inspect `rollback.py` without `--install` first. The helper performs no credential, database, cache, artisan, worker, VM, firewall, or n8n operation. Installing source does not prove OPcache activation, route availability, scheduler execution, or successful ticket delivery. Use the existing approved web activation method and record actual final hashes and acceptance separately.

The helper intentionally stops when the monitoring patch already exists. It is not a general redeploy or copy-all command. Preserve unrelated production route and schedule changes; the Git versions of `website/routes/web.php` and `console.php` add only the two Hermes requires and the bounded hourly command to their exact `develop` baseline.

## Support activation

Run the activation helper with the same operating-system user and PHP runtime as the application scheduler. Supply both the real application root and actual document root; application `public/` may be different from the hosted webroot. First run the default preflight:

```sh
php infra/hermes/activate_support.php \
  --app-root=/absolute/laravel-root \
  --document-root=/actual/document-root
```

Preflight inspects the current owner binding, one real hourly schedule and overlap guard, support model routing readiness, encryption, supported atomic-delivery driver and foreign key, private state paths, exclusions, and error tracker compatibility. It does not call a model, process a ticket, send notifications, or change settings. Output contains only checks, aggregate activation status, and a correlation ID.

Explicitly authorized `--enable` sets support review and strict safe-question delivery on, with a cutoff at activation time. Repeating activation while already enabled preserves that cutoff. Reactivating a disabled reviewer starts a fresh cutoff, so the disabled interval is not replayed. Older tickets are not bulk processed. The helper initializes private directories only during explicit activation and never invokes `schedule:run`.

The reviewer runs hourly, at most five reviews by default and ten with its bounded limit, with a 45-minute overlap guard. It rechecks owner binding, current ticket/customer ownership, latest public message, risk classification, and final reply insertion under database locks. It only sends a narrowly allowed Persian clarification question on a low-risk Persian ticket. Financial, credential, security, shared-infrastructure, command, and other uncertain requests remain internal drafts or require review. It does not execute customer commands, repair infrastructure, change accounts, or claim a fix it did not perform.

Durable idempotency survives notification errors. `dispatching`, `message_written`, and uncertain delivery states are not blindly retried. Deactivate the reviewer through the approved settings path before investigating an incident; preserve its private state and committed ticket messages. Disabling future runs does not undo a reply already sent. Do not delete state as a rollback shortcut.

## Isolated checks

Run these from the repository root with a compatible local runtime. They use temporary files and doubles, without a Laravel boot, production keys, network, or actual ticket delivery:

```sh
python3 -m unittest discover -s website/tests/Standalone/Hermes/release -v
node --test website/tests/Standalone/Hermes/frontend/miniapp-monitor-client.test.cjs
node --check infra/cloudflare/servernet-ir-worker.mjs
node --test infra/cloudflare/tests/servernet-ir-worker.test.mjs
php website/tests/Standalone/Hermes/miniapp-monitor/run.php
php website/tests/Standalone/Hermes/miniapp-monitor/audit.php
php website/tests/Standalone/Hermes/support/run.php
php website/tests/Standalone/Hermes/support/delivery.php
php website/tests/Standalone/Hermes/support/draft.php
php website/tests/Standalone/Hermes/support/activation.php
```

The Node tests exercise the monitoring panel extracted from the actual canonical view, with a DOM double that refuses HTML injection. The release fixture is the pre-monitor source view, retained only to prove that the final font/monitor patch preserves the live chat and memory handlers. Do not deploy fixtures or tests.

## Domain Worker

`infra/cloudflare/servernet-ir-worker.mjs` preserves the existing `blue-heart-fbf3` source and adds only six exact Hermes path/method allowlist entries. Its adjacent release JSON records captured source hashes, the observed prior version prefix, and deployment/acceptance status. The regression test uses the hash-pinned prior source fixture and stubs every outbound fetch; it checks the exact six-entry difference, fixed destination, authentication-header forwarding, cookie removal, no-store, request/method/path guards, safe errors, and legacy redirects/ports.

This source and metadata are a reviewable preservation artifact, separate from the cPanel release helper. Before a separately authorized Worker deployment, verify the fresh active source against the recorded prior SHA and preserve concurrent edits. Keep the original Worker route bindings. Do not leave temporary diagnostic route exceptions. Rollback requires the same source/version checks; restoring Worker source must not roll back the already activated native support settings or destroy private conversation/support state.
