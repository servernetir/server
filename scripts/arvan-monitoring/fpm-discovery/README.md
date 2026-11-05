# Read-only cPanel FPM layout discovery

This separate probe diagnoses unavailable expected pool filenames without changing the frozen activation package. It does not activate a release, bootstrap Laravel, connect to a socket/network service or write application/configuration files.

Run `inspect-fpm-layout.php` only as the existing cPanel account with cwd `/home/servernetcloud/servernet_app`, using the already observed CLI runtime. Its optional first argument is an EA version directory such as `ea-php84`, obtained from the current read-only UAPI response below. Without an argument it inspects the previously observed EA84 CLI installation only; that default is **not proof of a domain's FPM version**. Redirect output to an owner-only private file when executing through an explicitly approved oneoff Cron; preserve existing scheduled jobs.

The probe lists only ServerNet-matching basenames in the selected version's known `etc/php-fpm.d` directory and `/var/cpanel/userdata/servernetcloud`. It reports type, realpath, readability and owner/mode metadata. It reads at most 1 MiB of a stable pool candidate; in-directory aliases are accepted, out-of-directory symlinks refused. Selected pool settings are emitted only when the parsed pool user is exactly `servernetcloud`. Account userdata and the known user.ini path remain metadata-only.

It additionally lists actual ServerNet-matching `*.php_fpm.sock` entries already observed in `/home/servernetcloud`. It never invents a socket filename or connects to one. Home-socket domain hints explicitly have `mapping_evidence=observed_basename_only_not_pool_configuration`; a documented scrubbed-name match is an inference, not proof of a current domain-to-pool mapping. Unreadable configuration does not mean FPM is absent. Confirm version/FPM status through UAPI, reconcile actual configuration or account-owned observed socket evidence, and review the exact mapping before invoking the separate FPM caller. Effective runtime ini/OPcache behavior still needs the approved per-pool `observe` step.

## Read-only UAPI query

Use the already authorized cPanel HTTPS origin and existing transient token handling. Do not put a token in a URL, shell history or repository. The documented token header is `Authorization: cpanel servernetcloud:<existing token>` on the cPanel HTTPS service; a token call does not require a cpsess path.

- `GET /execute/LangPHP/php_get_vhost_versions?vhost=servernet.cloud`
- `GET /execute/LangPHP/php_get_vhost_versions?vhost=console.servernet.cloud`

`vhost` is the only query parameter and is optional. With it, `result.data` is one object; without it, data is a list. Require `result.status=1` and no errors. Relevant fields are `vhost`, `account`, `homedir`, `documentroot`, `version`, `php_fpm` (`1` enabled, `0` disabled), and `phpversion_source`. `version` can identify an EA or custom PHP package; do not convert an unrecognized package into a guessed directory. This API **does not return a socket path**. Do not invoke `php_set_vhost_versions` or rebuild/restart any service for discovery.

Primary references: [official operation](https://api.docs.cpanel.net/specifications/cpanel.openapi/php-settings/langphp-php_get_vhost_versions), [official machine-readable UAPI schema](https://api.docs.cpanel.net/page-data/shared/oas-specifications/cpanel.openapi.yaml.json) (`definition.paths`), [token authentication](https://api.docs.cpanel.net/cpanel/tokens), [domain pools and account-home socket example](https://docs.cpanel.net/knowledge-base/php-fpm/php-fpm-domain-pools/), [domain PHP-version lookup](https://docs.cpanel.net/ea4/php/how-to-locate-and-install-a-php-version-or-extension/).

## Isolated validation

`php -l inspect-fpm-layout.php` and `php test-layout-pure.php` passed locally and in independent review with PHP8.3. The test extracts only the probe's function declarations and uses newly created temporary fixtures: nine assertions cover account gating, duplicate-user refusal, selected-key filtering, nonexistent observed-socket metadata, in-directory and external symlink behavior, and oversized-file refusal. It never executes the guarded discovery entry point or touches production paths. No current live FPM mapping or production readiness is asserted by these tests.

Frozen probe SHA-256: `230d0fa6eb787b0ac5057fc97d96580501c230162202193c2b11d5863ccb8811`.
