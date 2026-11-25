# Hetzner server and Primary IP inspection

Date: 25 November 2026. Development base: `19481868e6c505d341622f25a211b0925c757a24`.

Two internal read-only methods in `website/app/Services/Cloud/HetznerClient.php` expose provider allocation metadata through the already-configured private HTTP client. No credential is returned, no public route is introduced, and no mutation is performed.

- `inspectServerAllocation($ref)` reads one exact server and returns its two Primary IP references and addresses.
- `inspectPrimaryIp($ref)` reads one exact Primary IP and returns its existence, assignment and auto-delete setting.
- Only an HTTP 404 confirms absence (`ok=true, exists=false`). Transport, access and malformed-response failures remain unknown (`ok=false, exists=null`).
- Preserve the exact Primary IP references before terminating a server; afterward inspect the server and each IP separately. This feature itself never terminates a server or IP.
- Numeric references are validated before any request. Responses expose only the documented inspection fields.
- Ordinary server lifecycle, billing, inventory pagination, routes and customer-facing behavior are unchanged.

The existing pagination helper has a safety cap and must not be used as definitive proof that an allocation is absent. These methods use exact-ID GET requests.

## Validation and status

The dedicated PHPUnit suite uses only synthetic IDs, documentation IP ranges, an isolated SQLite database, fake HTTP responses, and a test-only credential. It covers successful allocation reads, explicit null addresses, 404 versus authorization/server/transport errors, malformed and truncated HTTP 200 bodies, incorrect field types, explicit null assignment, rejected references and GET-only requests. Required metadata is validated before reporting a successful inspection; arrays and other malformed values remain unknown without string-conversion warnings.

A dedicated pull-request workflow installs the locked dependencies and runs PHP 8.4 syntax and focused PHPUnit checks in an isolated runner without production credentials. Local PHP and Composer are unavailable in the authoring workspace; test execution must be reported from the actual CI result.

Status: development branch only. No production deployment or live provider operation is implied. Production may contain additive changes absent from this base; deployment must insert only the reviewed new method block and preserve other live code.
