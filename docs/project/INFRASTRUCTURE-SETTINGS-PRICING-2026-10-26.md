# Infrastructure settings, central TRY and equivalent-offer pricing — 26 October 2026

## Scope and state

The owner requested compact ordered infrastructure settings, VPS.tc in the same settings surface, competitive prices for equivalent servers, cost-aware customer operations and a central automatic Navasan TRY card. This record covers that follow-up. Source baseline is `984d7d04fa3a29816465ed63bb165a47f4cec380` on `develop`; it includes the already-installed three-file VPS.tc diagnostic release.

Implementation is validated in isolation. Production installation and live acceptance are recorded after the hash-guarded release below. No paid provider operation, new cron, migration, dependency update, environment edit or credential rotation is part of this release.

## Resulting behavior

- Nine infrastructure rows follow the stable provider registry numbers, display real internal names, and use native keyboard-accessible details elements. All start closed. Status is green only after a recent successful explicit test bound to the current credential fingerprint; missing/stale evidence is yellow and disabled/unconfigured providers are red. Opening settings makes no provider API request.
- VPS.tc shares its existing secure panel between the standalone page and the infrastructure tab. Its five protected forms remain separate from the legacy whole-tab settings form. Every legacy control and the sticky save button retain explicit form ownership; no nested forms or additional secret redisplay is introduced. Return destinations are fixed and allowlisted.
- TRY uses `try_hav` from the existing keyless Navasan `aed_based_rates.php` remittance feed. The source value is already toman, not rial. Four rates publish as one validated batch with bounded source/fetch age, provenance, currency-specific sanity bounds and the existing lock/rollback behavior. No paid API key or extra source request is needed.
- The TRY card sits beside USD/EUR/USDT at `/admin/settings?tab=pricing`. The existing `fx:dollar` six-hour schedule is reused. VPS.tc defaults to that central TRY rate; a manual supplier override is optional. Independent purchase tax, funding fee and profit remain owner-controlled. TRY-only changes/expiry invalidate only VPS.tc's supplier proof; repricing checks the resolved fingerprint before/after quote collection and publication.
- Equivalent offer selection compares actual customer recurring and first-payment prices in both supported retail currencies, rather than supplier cost alone. Location, architecture, disk, CPU/GPU, network/billing, usable OS/licence and capabilities must match before hiding a higher-priced offer. Setup/renewal tradeoffs remain visible. The existing store display pruner preserves explicit `?plan=` aliases, and hourly display uses the same representative as checkout. Historical incompatible rows sharing one persisted slug are not split into new checkout identities by this release; no new switch is made without equivalence proof.
- VPS.tc power requires reviewed account-bound `power_cost_mode=included`, the exact local plan/location and the matching creation journal. Unknown/paid/unproven policies and manual attachments fail before supplier mutation. Parent cancellation remains available independently. All other optional paid/unknown features remain off; see the [capability/cost matrix](VPSTC-CAPABILITIES-2026-10-26.md).

## Supplier acceptance remains separate

Authenticated reads confirmed 16 monthly VPS offers. New VPS.tc sales remain closed because the API returns empty account currency, literal `Array` payment fees and inconsistent native category/order amount units. Public USD/TRY display metadata is not a substitute for a verified account-currency contract. Profit/FX/tax settings or a top-up cannot repair these response ambiguities. No supplier purchase or free-operation promise is inferred from this release. See [account acceptance](VPSTC-SALES-PREPARATION-2026-10-26.md).

## Validation

PHP 8.4.27, locked dependencies, fake provider HTTP, isolated database/cache/mail. The broad selected batch contained 293 tests: 292 passed and the known pre-existing `NavasanRateConsumersTest::test_domain_cache_is_invalidated_by_a_changed_exchange_snapshot` failed. It expects the obsolete domain lookup callback although the unchanged current price book reads a durable snapshot; the same failure was reproduced at baseline `8489da5`. It was not changed to make this work green.

After the final cross-process policy-race regression was added, and with only that named baseline case excluded, **293 tests / 3,985 assertions passed** across VPS.tc, Navasan/FetchDollar, catalog repricing, store equivalence/deep links/hourly display, infrastructure/settings forms, payment callbacks and complimentary services. The large combined render batch required a 512MB local test limit; the initial 128MB attempt exhausted memory. Twenty runtime source/view files passed PHP lint. Eight focused offline wrapper tests passed, including unknown-new-file refusal, live-drift preservation, atomic writes/partial rollback and newer-file protection.

Independent reviews caught and corrected premature alias pruning, hourly display/checkout disagreement and the power-policy request-memo race. The final guard compares the validated policy with a fresh database value immediately before mutation; its direct-DB concurrent-edit test is included in the final result.

## Scoped release and rollback

`scripts/deploy-infrastructure-settings-20261026.py` pins the exact file-only core SHA-256 `00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de`, base `984d7d0...`, exactly 20 runtime paths and four explicitly new files. It preserves both shared release locks, source/target hashes, private backups, lint, plan SHA approval, per-file atomic replacement and guarded rollback. Live checkout/pricing changes must be merged through its three-way patch; a conflict stops without application writes. There is no public asset or route update.

Apply and rollback use the exact target, real application root, private release directory and plan SHA. Rollback refuses newer hashes. It restores files only; owner settings, rates, supplier state, invoices and credentials are not erased. Rate-refresh acceptance uses the existing application workflow separately from file installation and is recorded with its actual outcome.
