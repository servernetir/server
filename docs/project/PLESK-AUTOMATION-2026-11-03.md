# Plesk hosting automation — 3 November 2026

Base: develop `31a311e904741f8b58f462824a83c9745731b23f`.

Windows hosting was previously delivered to WHM because catalog groups used the shared category. PR232 fixed panel selection. This follow-up implements actual shared hosting delivery on Plesk, rather than treating customer-account creation alone as a delivered website.

## Contract

- Retain the existing encrypted API credential. Explicit `servers.meta.plesk_auth_mode=key` sends the XML API `KEY` header; password authentication remains the legacy default. No authentication fallback replays a mutation.
- Use the documented customer `gen_info` schema. Require an exact service plan, explicit hosting IP, persisted service/customer/server/login/domain/plan binding, and known absence before creating anything.
- Create the customer and its owned physical-hosting subscription. Read back active customer identity, `gen_info.owner-id`, domain, FTP login, hosting IP and the purchased plan GUID. An account alone is not success.
- Persist uncertainty before each mutation. After a timeout, reconcile by reading. An unresolved customer or subscription create is held rather than repeated. An existing unbound login, changed binding, unknown read or mismatched owner/plan cannot be adopted automatically.
- The existing global switch keeps its old semantics. A validated server can additionally opt in using boolean `plesk_auto_enabled` AND `plesk_auto_ready`; unrelated servers remain unchanged. Reseller delivery stays manual for this per-server shared-hosting opt-in.
- Automated hosts receive customer-scoped, single-use XML API login sessions through the existing owned account route. The stored password never enters the login URL. Suspended hosts and mismatched delivery bindings cannot obtain a session. Legacy manually attached Plesk services retain their direct-login behavior.

## Validation and current production state

Final runtime source: `a97694c56d245fb8a198f6a7336e07a5a6194166` on PR240. [Plesk CI](https://github.com/servernetir/server/actions/runs/41589860258) succeeded on PHP8.4.27. The preceding runtime run measured **19 standalone scenarios / 196 assertions** and **47 Laravel tests / 264 assertions**; the final change uses the existing owner-session test to cover a mixed-case domain and does not add tests. The standalone suite does not bootstrap Laravel, use credentials, open a database or make network requests. It covers two-object delivery, replay, unknown reads, unbound logins, wrong customer/owner/plan, missing configuration, reconciled/unresolved timeouts, unreadable mutation responses, changed bindings, invalid/mixed XML, forbidden DTD, session origin/admin restrictions and non-hexadecimal session identifiers. Laravel tests cover ownership, suspended/binding denial, deep-link allowlists, trilingual cards, server opt-in, free-subdomain publication and DNS evidence when the optional IP column is empty.

The independent review's unreadable-create and connection-IP findings were addressed with explicit validated rejection evidence and durable verified IP metadata. The later mixed-case-domain finding was addressed by comparing the SSO binding with the same canonical domain used during provisioning.

The owner's prioritized existing WIN-10 service was created on the real Plesk server with its exact purchased plan. Customer and subscription ownership were read back; the same local service was attached; invoice fingerprint and next due date were unchanged. The old WHM account and its files were retained. A customer credential read against the actual Plesk API succeeded. Plesk's customer UI showed the owned physical host on the purchased 10GiB plan, its correct hosting IP and the httpdocs directory. No customer DNS was changed. The nameserver badge correctly requires setup rather than showing green for different delegation. The final Windows nameservers were read from Plesk's DNS template: `ns1windows.servernet.cloud` and `ns2windows.servernet.cloud`.

A delivery notice was sent and verified in the existing customer ticket, with the direct Plesk login, credentials available in the owned portal card, both nameservers and the httpdocs upload path. The ticket remains open for DNS/SSL completion. Customer identifiers and contents stay out of this public report.

**Production is partially installed; automation is not enabled.** Seven source destinations were installed from runtime `aa431f92f23da348ff31b14652c48c29c889ee49`, with seven successful lints, three shared release locks, private backups, exact before/after hashes and targeted Blade invalidation. The already-live Iran purchase-readiness guard in ProvisioningService was preserved in the live candidate; copying the repository file over it would lose that guard.

The browser rendered the actual customer's Plesk hosting card with correct nameservers, password masking and the login/file/database/email links. A customer one-click login attempt returned to the account page, so live SSO is **not accepted**. Subsequent source handles documented non-hexadecimal session IDs, but that correction has not been installed or diagnosed against the real response. The browser's cPanel session expired and its terminal became disabled during this final acceptance step. The owner was asked to sign in to cPanel again; no alternate route was used to bypass the sign-in wall.

The live service's `server_ip` attribute read as null; schema-column presence was not independently confirmed before cPanel access expired. The field-only correction command stopped at its expected-state guard, and the old WHM IP remains in legacy delivery metadata. Final source therefore also writes verified `ip`/`server_ip` metadata and uses it for DNS publication/evidence without requiring a schema change. This five-file final patch is prepared but not uploaded or installed. The last live inventory read showed three subscriptions against configured capacity four, no pending Plesk deliveries, and the per-server automatic flag still false. No extra server or license was purchased.

## Resume, deployment and rollback

The private first-stage state is under `/home/servernetcloud/plesk-connect-20261103/`: `manifest.json`, `installed.json`, `backup/`, the scoped delivery receipt, and read-only verification script. These contain the authoritative production hashes and private service reference. Keep backups and delivery evidence outside webroot.

After an authenticated cPanel session is available:

1. Refresh current source and hashes. Apply the final delta to PleskClient, PleskHostingProvisioner, ProvisioningService, the account ServiceController and HostingDomainConnection. Retain the existing purchase-readiness guard and concurrent changes. The local final staging archive/installer is only a candidate, not a server receipt.
2. Use the same three shared locks (`.servernet-web-release.lock`, `servernet-release.lock`, `.codex-web-deploy.lock`), new private backups, pinned before/after hashes and PHP8.4 lint. No schema, financial, scheduler, firewall or administrator-credential changes are required. The existing hosting partial, Server gate and Plesk wrapper are already installed and need no identical rewrite.
3. Correct only the repaired service's connection metadata to the Plesk hosting IP while retaining the legacy record and billing. Re-read it; do not rely on writing the absent optional column.
4. Diagnose a failed create_session with sanitized status/error/token-shape evidence, never the token or password. Complete actual portal-to-customer-Plesk login plus file/database/email shortcuts; retain MFA behavior and the fixed-origin redirect. Verify the recorded account, domain and plan.
5. Only after this acceptance, set boolean `servers.meta.plesk_auto_enabled=true` on the validated server, retain `plesk_auto_ready=true`, and record acceptance time. Verify the real driver selection, current capacity and unchanged billing. No global Plesk switch or cron change is needed.
6. Update this report with the final installation and acceptance receipts. Customer DNS delegation and SSL remain separate until the customer sets the supplied nameservers.

To roll back automation, remove the per-server enable flag first. Restore only changed files whose current hashes still equal that release's after hashes; preserve newer work and the live readiness guard. Restoring source does not remove the delivered hosting resource or revert ownership. The old WHM account is retained; deletion is outside this repair's completed scope.

## Primary protocol references

- [Customer creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/creating-customer-accounts.28789/)
- [Subscription creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/creating-a-subscription.33892/)
- [Secret-key authentication](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-secret-keys.37121/)
- [Single-use login sessions](https://docs.plesk.com/en-US/obsidian/administrator-guide/80002/)
