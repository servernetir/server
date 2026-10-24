# VPS.TC reseller integration

Status: draft preparation only, 24 October 2026. Not installed in production and not available for customer sales.

The dedicated admin page stores reseller credentials encrypted and runs a read-only preflight. The API client remains separate from CloudProvider/CloudManager. Saving credentials cannot import plans, place an order, spend credit, or allow the provisioner to retry an ambiguous upstream order.

## Primary sources

- https://www.vps.tc/en/partner links the official reseller module and advertises REST, sandbox and webhooks.
- https://github.com/whitelabelservices/whmcs-reseller at 7872bebfde141cea58e5d9d03c3e0fa874af7754 describes the protocol. Its module is proprietary; this client is independently written.
- https://www.vps.tc/en/vps lists Istanbul and New York. Public prices are advertising, not authenticated reseller quotes.
- https://www.vps.tc/en/terms describes refund restrictions, SMTP restrictions, FUP, renewal price changes and service cancellation.

The placeholder hostname in the module must not be used. Obtain the intended HTTPS origin from VPS.TC. Current validation accepts only vps.tc and whitelabelservices.us or their subdomains; this is an origin allowlist, not confirmation that every such host operates the reseller API. Other official origins need review and a code change.

## Read-only protocol

| Purpose | Method and path | Expected keys |
| --- | --- | --- |
| Login | POST /api/login | token, refresh |
| Refresh | POST /api/token | token, refresh |
| Account balance | GET /api/balance | details.acc_credit, acc_balance, currency |
| Categories | GET /api/category | categories |
| Category products | GET /api/category/{id}/product | products; periods |
| Product order form | GET /api/order/{id} | product.config.forms |
| Service information | GET /api/service/{id} | service |
| Service VMs | GET /api/service/{id}/vms | vms |
| VM information | GET /api/service/{id}/vms/{id} | vm; optional bandwidth |
| Templates | GET /api/service/{id}/templates | templates |

Read methods for /api/service and /api/invoice are also prepared, but their top-level list keys and pagination must be confirmed live. Unsupported shapes fail closed. Counts are samples, never complete-inventory or orphan-free guarantees.

Login and refresh are the only POST operations implemented. There are no service order, payment, power, reinstall, upgrade, cancellation, deletion, SSO or webhook ingestion methods. Tokens remain in memory, redirects are disabled, TLS verification stays enabled and GET authentication recovery is bounded. Raw responses and exception text are excluded from preflight output.

## Configuration

After safe deployment, the administrator entry point is /admin/cloud/vpstc. This route is not yet installed on the live site. All three routes require web authentication and the administrator role. Save and preflight POST requests retain CSRF protection and have separate rate limits.

Names and passwords use Setting::putSecret/getSecret; inputs are never redisplayed or flashed on validation failure. Empty credential fields preserve saved values. A changed origin or initial configuration requires both credentials, persisted with the origin in one database transaction. Saved-origin credentials and environment credentials are never mixed.

Managed installations may instead use services.vpstc with VPSTC_API_BASE_URL, VPSTC_API_USERNAME and VPSTC_API_PASSWORD. Defaults are empty. Do not commit values, place them in command arguments, documentation, logs or tickets, or read/overwrite the existing production environment file.

The CLI is:

```text
php artisan cloud:vpstc-preflight
php artisan cloud:vpstc-preflight --category=1 --product=2
```

IDs above are illustrative. The command prints a sanitized report and succeeds only when its selected read checks complete. sales_ready and catalog_complete remain false even after successful reads. It does not set FX, sell prices, availability or supplier discounts.

## Required next acceptance

1. Obtain reseller API origin, credentials, sandbox access and account currency. The owner has an account; credentials and the origin were not supplied during this preparation.
2. Read authenticated monthly quotes including setup, OS licence, IP, tax, renewal, paid options and payment method. Match CPU/RAM/storage/location/image/stock to delivery. Do not parse resource descriptions or divide a prepaid monthly purchase into cancellable hourly retail.
3. Confirm enforceable traffic terms. Turkey's quota/2x wording conflicts with homepage unmeasured-traffic wording. Confirm ingress/egress counting, FUP, excess rates, automatic charges and hard spending/transfer controls. A warning counter does not prevent supplier charges.
4. Implement durable per-order request/receipt journals before paid calls. Validate fresh dynamic form/item IDs and final quote. After an ambiguous timeout reconcile orders/services/invoices before retrying. A deterministic hostname is not established supplier idempotency.
5. Implement/register the lifecycle adapter and verified monthly checkout, fresh-price import, renewal and reconciliation.
6. Test disposable server creation, exact resources, SSH/RDP, status, power, reinstall, access credentials, renewal, suspension and cancellation. The module's payment method 120 is not verified for our account.
7. Confirm that cancelling the parent service stops future billing. VM deletion alone is insufficient: the official module also cancels the service. Verify future invoice/renewal state rather than relying solely on an acknowledgement.
8. Enable sales only after the account-specific checks pass. No sandbox hostname, webhook signing, pagination, rate-limit or order-idempotency contract has been verified.

## Validation and deployment state

The initial isolated local run passed 29 tests / 253 assertions under PHP 8.4.26. A further account-details schema guard and test were added afterward; the final local run was started but its result could not be retrieved when the execution environment became unavailable. The draft now contains 30 focused tests. Preserve the distinction between mock protocol tests and real provider acceptance.

An unrelated pre-change LightNode baseline returned 8 errors and 13 failures across 54 tests; it is not corrected or represented as passing by this change.

Production cPanel access, provider registry and source snapshots were read successfully before the environment became unavailable. No release helper was uploaded, no live file changed, no supplier request used account credentials, and no paid guest was created. Browser rendering and live HTTP acceptance remain unverified.

Deploy only from fresh exact-byte production snapshots: SettingsController and routes/web.php have live drift. This feature adds separate classes and a marked route block; do not replace live routes wholesale from the repository. Install new classes and view before routes/link, use expected hash guards and private backups, invalidate affected opcodes and clear route cache. Cached configuration may need review for environment-based settings; the encrypted admin setting tuple does not depend on a new environment key.

## Persistence and rollback

The proposal uses application files and encrypted settings only, with no migration or scheduler. No customer plan, service, invoice or provider resource is created by preparation. Guests, network routes, NAT/firewall, DNS, host cron and routing/port-forward agent state were not changed.

For a future installed release, restore only its manifest files with installed-hash guards, remove only its dedicated routes/link and invalidate affected caches. Preserve unrelated application changes and existing customer services. Retain encrypted settings unless the administrator explicitly removes those three specific keys. Do not rotate APP_KEY or roll back network state.
