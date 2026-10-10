# Running the application tests

Run tests in an isolated checkout, never in the production application directory.
Keep the repository layout, including `relay/` next to `website/`; the SMS tests
compare the PHP template allow-list with the checked-in n8n relay registry.
Do not copy production `.env`, cached configuration, credentials, uploaded customer
documents, or live databases into the test checkout. No provider power operation
or real customer payment is needed to run this suite.

Use PHP 8.3+ with the extensions required by Composer and these application test
dependencies: `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`, `pdo_sqlite`,
`sodium`, and `zip`. PHP 8.4.26 on Ubuntu was used for the October 10 regression
repair. On Windows, ensure these extensions are loaded in the CLI PHP runtime,
not just the web server. The test bootstrap checks prerequisites before running.

From `website/`, after installing dependencies with `composer install`, run:

```sh
php -d memory_limit=1024M vendor/phpunit/phpunit/phpunit --log-junit test-results.xml
```

The PHPUnit configuration fixes the test encryption key and Persian default locale,
and defaults to in-memory SQLite. Tests for English and Turkish explicitly select
their locale. Cached Laravel configuration is rejected because it can override
test database settings. Remove cached configuration only in the isolated checkout.
Existing explicit MariaDB stress-test opt-in remains available for a separate test
database. The ordinary SQLite suite does not prove MariaDB concurrency behavior.

## Previously skipped coverage

Enable the five-page panel preview while running the ordinary SQLite suite:

```sh
PANEL_PREVIEW=1 php -d memory_limit=1024M vendor/phpunit/phpunit/phpunit --log-junit sqlite.xml
```

Settings form cases now always run: each tab's actual submit destination is checked,
the guide is checked as a read-only page, and a synthetic connected Google Calendar
account exercises the external disconnect form. These cases need no real account.
The preview and form tests reject unexpected Laravel HTTP requests.

Run the MariaDB cases in a **separate process** using Docker and Python 3, from
the repository root in an isolated Linux checkout with Composer dependencies:

```sh
docker build -t servernet-tests:php84 website/tests/docker
python3 website/tests/docker/run-mariadb.py \
  --checkout "$PWD" --output "$PWD/test-results/mariadb"
```

The runner refuses `.env`/environment variants and cached application configuration
(`.env.example` is allowed). It mounts source read-only, uses temporary writable
storage/cache, starts MariaDB 11.8 with an empty in-memory data directory, and creates
the dedicated `servernet_stress` database. The `db` hostname exists only on a private
Docker network with no published ports or external egress. Random test credentials
are discarded with the containers and network in the cleanup step. No live database,
provider credentials, or customer services are involved. Docker image construction
requires package-download access; test execution does not.

The original six cases cover the environment gate, 100 reservation attempts, repeated
settlement, repeated release, competing settlement/release, and 100 mixed wallet
debits/reservations. A seventh case deliberately breaks child-process database login
to prove that failed workers cannot produce a successful stress result. Each case
rebuilds only the disposable schema. Reports include JUnit plus the per-case financial
checks; combine their case identities with SQLite results instead of counting the
MariaDB gates as untested work. See `SKIPPED-13-VERIFICATION-2026-10-10.md` in the
repository root for the verified run and fixes.

The full suite and destructive MariaDB schema resets must never share one database
or PHP test process. This is why the two environments are run separately.

When adding tests:

- Reuse providers seeded by migrations with `updateOrCreate`; preserve database
  uniqueness constraints. Mock external calls and use test credentials only.
- Freeze the clock for calendar boundaries and advance it explicitly between
  historical price versions. Two writes in the same second are not two days.
- Assert checkout URL parameters separately from display attributes such as
  `data-price`. Never accept a customer-supplied price as authoritative.
- A completed AI idempotency key replays the saved response without another
  provider call or ledger debit. An in-flight reservation without a saved response
  returns 409. Both paths must remain covered.
- Content-calendar weekend base quotas differ from quotas calibrated to finish
  the remaining publication queue. Test the relevant contract explicitly.

Service 20 is protected through `billing.internal_service_ids`. Keep its existing
billing and provisioning guard tests enabled. Use read-only production checks to
confirm it remains active; do not power it off to test an exclusion.
