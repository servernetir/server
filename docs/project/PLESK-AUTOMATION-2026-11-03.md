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

**Installed and accepted in production.** The per-server shared Windows hosting automation was enabled at `2026-11-03T06:57:26+00:00`, after actual customer-panel acceptance. The legacy global switch remains false. Fresh runtime verification selected the `plesk` driver, confirmed all five exact `sn_windows_1` through `sn_windows_5` plans, found no pending deliveries on the target, and reported three subscriptions against configured capacity four. The next paid shared Windows order can use the existing provisioning flow; per-server reseller delivery remains manual. No extra server or license was purchased.

PR240 was merged at `421dd9132582b2be062612c52ca59bd434cef5eb`. Its final five-file delta was installed after the owner restored the expired cPanel session. The first-stage seven destinations, final delta and subsequent session fix have private backup and hash receipts; there are **eight distinct installed destinations**. Each installation acquired the three shared release locks and checked every before/after hash and PHP8.4 syntax. No blanket deployment, cache flush, migration, scheduler, firewall or administrator-credential change was used.

Actual session diagnosis returned XML API error 1014 because session data omitted `source_server`. PR244 includes the required empty element shown in the official XML example and encodes the client IP in base64. The token charset correction was also installed, but it was not the observed cause of that rejection. Runtime source `6fea0e9ab0d2e77a14cb41b8ce356b1cf0bde2dc` was merged at `b0341f3357718585a8b04de3de6e3be73ef9fc26`.

[Plesk CI](https://github.com/servernetir/server/actions/runs/41592610918) passed on PHP8.4.27: **19 standalone scenarios / 198 assertions** and **47 Laravel tests / 264 assertions**. All six workflows triggered for that runtime commit completed successfully. The isolated standalone suite makes no network requests and does not bootstrap Laravel or use credentials/a database. Its session fixture now requires the encoded IP and required session-data element. Other coverage includes two-object delivery, replay, unknown reads, unbound logins, wrong customer/owner/plan, missing configuration, reconciled/unresolved timeouts, unreadable mutation responses, changed bindings, invalid/mixed XML, forbidden DTD, fixed session origin/admin restrictions, non-hexadecimal session IDs, ownership and suspended/binding denial, deep-link allowlists, trilingual cards, opt-in gates and DNS publication/evidence.

The owner's prioritized existing WIN-10 service was created on the real Plesk server with its exact purchased plan. Customer and subscription ownership were read back; the same local service was attached; invoice fingerprint and next due date remained unchanged. The old WHM account and files were retained. Customer credentials were verified against the actual API. Browser acceptance then used the portal's own login link and each file/database/email shortcut: every link reached the customer-scoped Plesk UI, showing only its owned host. No password appeared in a login URL; MFA behavior was unchanged.

The live schema was subsequently checked and does contain the optional service `server_ip` column. Its null value and legacy WHM IP metadata were corrected to the verified Plesk hosting IP, both in the column and durable `ip`/`server_ip` metadata. Readback confirmed the connection change and unchanged billing/due date. Legacy-account evidence was preserved. The metadata fallback remains necessary for compatible installations where that optional column is empty or absent.

Plesk's actual DNS template supplies `ns1windows.servernet.cloud` and `ns2windows.servernet.cloud`. A final read found that this customer already owned an active, verified managed-DNS zone in ServerNet and had delegated to that service. The final follow-up therefore updated only four host-related records through the existing ZoneService transaction, validator, versioning and publisher: apex A, FTP A, SPF and the Plesk default DKIM public key. The original zone/records were backed up privately. All eight record identities were preserved; CNAME, MX and DMARC records were retained. Desired/applied version 6 was published, and the customer UI reported both authoritative nodes current. Direct queries to both current public nameserver addresses returned the Plesk hosting IP with AA/NOERROR. Public resolver checks confirmed the current nameserver addresses. Legacy WHM DNS was read for diagnosis but not modified. The customer's current registrar delegation can remain on ServerNet managed DNS; Plesk's nameservers are an alternative, not a required switch for this setup. Hosting-card NS evidence remains strict and does not show the Plesk nameservers as configured when another DNS service is in use.

The existing delivery and earlier guidance replies were edited in place again to match this completed managed-DNS connection, while retaining their IDs, authors and original creation times. The total message count remains four and ticket state is unchanged; no new message or notification was sent. The final notice explains the managed DNS route, cache propagation, Plesk access and httpdocs. It accurately says SSL is in its final issuance stage rather than claiming it installed. Customer identifiers and message contents are excluded from this report.

The current certificate is not installed. The Plesk Let's Encrypt issuance form is prepared for the base domain, www and mail, including assignment to the mail domain, with HTTP-01 selected. The final Get it free submission explicitly accepts Let's Encrypt Terms of Service and has not been submitted pending the owner's confirmation. Existing HTTP probing at the verified hosting IP reached a 301 redirect; public HTTPS acceptance remains pending actual certificate issuance. Previously cached A records can retain the old value for their prior four-hour TTL. No certificate-validation success is claimed.

Operational closure also confirmed one existing OS scheduler entry, a heartbeat age of 26 seconds, the every-minute provision:run event with its overlap guard, and preservation of the live purchase-readiness guard. The failed-delivery verifier excludes Plesk, so it cannot mark a customer-only account as a completed shared host; uncertain Plesk creates remain held for reconciliation. No scheduler or runtime source change was needed in this final data-only follow-up.

## Installed source and rollback

Production `ProvisioningService` retains the already-live `IranPurchaseReadiness::holdDeliveryIfBlocked` guard. That concurrent guard was absent from the initial repository source and was deliberately preserved in the scoped live candidate. Never copy the repository file wholesale over it.

| Destination below servernet_app | Accepted SHA-256 |
| --- | --- |
| app/Services/Provisioning/PleskClient.php | `798c63ef94cde0638c16709986150872831bc9acff2ff4fc35ac94486c8fb8e5` |
| app/Services/Provisioning/PleskHostingProvisioner.php | `89b9ba4ce38176936515646bd88249cb0e364e5899c9fa2916a6149094e12a97` |
| app/Services/Provisioning/PleskProvisioner.php | `24dd62084ae60f05ff95cf6357ab5852fd3c88df3fb8118a15cc903a66b59f51` |
| app/Services/Provisioning/ProvisioningService.php | `b19d8a202653231a24ef98556c79b1830130a7950d7ab70cb1796bcd1f09c8d0` |
| app/Models/Server.php | `7a8eae6a1d99684e608f924cc0aac6d68af27d1c77cd07736fa13052dc285b5b` |
| app/Http/Controllers/Account/ServiceController.php | `e8c0f6bfdc3e1cd9b30380f2145ace542bf6632200c1fa2e6c221881c196d3af` |
| resources/views/account/partials/card-hosting.blade.php | `c1b39eebd9fc234b09e1db8e191c720cc69ca7709122c64d1bb8770690894ef4` |
| app/Services/Dns/HostingDomainConnection.php | `36d7df2b3dbcb23aef7a4037c2c3deb78d30898273fd2261cd140f1ed06176db` |

Private release state is under `/home/servernetcloud/plesk-connect-20261103/`, `/home/servernetcloud/plesk-connect-20261103-final/` and `/home/servernetcloud/plesk-session-20261103/`. Preserve install manifests, receipts and backups outside webroot. The final directory also retains scoped connection/server backups, automation acceptance and in-place ticket-edit receipts; do not copy private customer data into Git.

To roll back automation, remove the target server's per-server enable flag first. Under `.servernet-web-release.lock`, `servernet-release.lock` and `.codex-web-deploy.lock`, restore only files whose current hashes still equal that release's after hashes, in reverse release order. Preserve newer concurrent work and the live readiness guard. Source rollback does not remove the delivered host, revert its owner, change billing or delete the retained WHM account. Reversing a message edit requires the private original-body backup and an explicit owner request; it is separate from source rollback.

## Primary protocol references

- [Customer creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-customer-accounts/creating-customer-accounts.28789/)
- [Subscription creation](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-subscriptions/creating-a-subscription.33892/)
- [Secret-key authentication](https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-secret-keys.37121/)
- [Single-use login sessions](https://docs.plesk.com/en-US/obsidian/administrator-guide/80002/)
