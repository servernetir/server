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

The standalone test executes the actual client and hosting provisioner without bootstrapping Laravel or accessing any database, secret or network. On isolated PHP8.4 it passed **15 scenarios / 142 assertions**. It covers two-object delivery, replay, unknown reads, unbound logins, wrong customer/owner/plan, missing configuration, reconciled/unresolved customer and subscription timeouts, changed bindings, invalid/mixed XML, forbidden DTD and session origin/admin restrictions. Six Laravel integration tests and a targeted CI workflow are included; their result will be added after execution.

The owner's prioritized existing WIN-10 service was created on the real Plesk server with its exact purchased plan. Customer and subscription ownership were read back, the same local service was attached, and invoice fingerprint and next due date were unchanged. The old WHM account and its files were retained privately; no deletion or customer DNS change was performed. The service's old fixed IP was corrected to its Plesk IP. Private receipts identify the service; customer details are deliberately absent from this public report.

At this checkpoint, source installation, automatic enablement, browser SSO acceptance and the final customer delivery notice remain pending. A source commit is not a production release.

## Deployment and rollback

Install only the three Plesk classes, Server model, account ServiceController and hosting partial, with fresh before hashes, shared release locks, private backups, lint and targeted Blade invalidation. Preserve the already-live explicit-authentication change in PleskClient. No schema, financial, scheduler, firewall or administrator credential changes are required.

Enable the per-server flag only after live create/readback and customer access acceptance. Confirm configured capacity rather than increasing it automatically. To roll back automation, first remove the per-server enable flag; then restore the six source files only if their after hashes still match. Restoring source does not remove the real delivered hosting resource or revert its ownership; retain the service repair and customer data unless a separate migration is approved.

## Primary protocol references

- [Customer creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/creating-customer-accounts.28789/)
- [Subscription creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/creating-a-subscription.33892/)
- [Secret-key authentication](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-secret-keys.37121/)
- [Single-use login sessions](https://docs.plesk.com/en-US/obsidian/administrator-guide/80002/)
