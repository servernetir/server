# domains

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

The subsystem is built around one hard rule: a price is only displayed if it came from a live check response that we have persisted as a quote row, and a customer is only charged after the registrar confirms the registration. Search normalises the query to punycode, fans out a bulk `check` with prices to the registrar accounts allowed to sell each TLD (from `registrar_tlds`, the routing table), and writes one short-TTL row in `domain_quotes` for every purchasable result shown — the Buy button carries a quote ULID and nothing else. Registrar selection is a TCO comparison over a configurable horizon (default 2 years: first-year cost plus renewal cost × (H−1), FX-normalised with a margin buffer), never first-year price alone, and the winner is written to `domains.registrar_account_id` because renewal must go back to the same place. Premium pricing is taken exclusively from the check response and the quote carries a `price_source` column that is CHECK-constrained so a premium quote can never be priced from the TLD list. The "advertised discount then refused" failure is handled at four layers: a promo cost is only honoured if the check response echoes it back, a re-check runs within seconds of the create call, a refusal releases the payment authorisation instead of refunding, and repeated refusals quarantine that (registrar, TLD) pair via counters on `registrar_tlds` plus a 24h negative availability cache for the domain. Payment is authorise-then-capture — for Iranian gateways this maps naturally onto request→callback→**verify**, where verify is simply not called until the registrar confirms — and a registrar timeout produces `unknown`, not `failed`, entering a 24h reconciliation poll because timeouts frequently mean the registration actually succeeded. Registrant identity is modelled as `domain_contacts` handles scoped per registrar/registry namespace, with real columns for the universal ICANN contact set and a JSON `extra` only for registry extension fields; .ir is fenced off by `tlds.registry_scheme='irnic'` and a router guard so it can only ever route to an IRNIC account. All money is BIGINT in minor units with the exponent defined per currency (EUR=2, IRT=0) — no floats and no DECIMAL anywhere except FX rates, which are not money. Customer-facing text lives in `tld_translations` (locale rows, same shape as the existing `post_translations`) and in event/message *keys* resolved through `__('ui.*')`, so no raw registrar string ever reaches a customer in any of the three languages.

## جدول‌ها

### `tlds`

One row per sellable TLD: registry-level facts, lifecycle policy, IDN and identity requirements, and pricing mode. Admin adds a TLD here with no code change.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `tld` | `VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | lowercase, NO leading dot, punycode for IDN TLDs (e.g. 'com', 'ir', 'xn--mgba3a4f16a'). ascii collation keeps the unique index inside MySQL key limits. |
| `tld_unicode` | `VARCHAR(63) NOT NULL` | utf8mb4 display form, e.g. 'ایران'. Display only, never joined. |
| `type` | `ENUM('gtld','new_gtld','cctld') NOT NULL` | drives default policy hints only |
| `registry_scheme` | `ENUM('icann','irnic','custom') NOT NULL DEFAULT 'icann'` | THE routing fence. 'irnic' rows may only ever route to a driver whose code is 'irnic'. Enforced in the router and asserted in a test. |
| `is_active` | `BOOLEAN NOT NULL DEFAULT 0` | per-install. The Iran DB and the Germany DB independently enable what they are legally able to sell. |
| `is_featured` | `BOOLEAN NOT NULL DEFAULT 0` | shown in the search suggestion strip |
| `sort_order` | `SMALLINT UNSIGNED NOT NULL DEFAULT 100` | — |
| `min_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | registry minimum registration term |
| `max_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 10` | e.g. .ir is 5 |
| `idn_supported` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `idn_scripts` | `JSON NULL` | JSON ON PURPOSE: short unordered list of allowed Unicode scripts ['Arab','Latn'], never joined, never filtered on. |
| `min_label_length` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | validated before any API call is spent |
| `max_label_length` | `TINYINT UNSIGNED NOT NULL DEFAULT 63` | — |
| `whois_privacy_supported` | `BOOLEAN NOT NULL DEFAULT 1` | must be 0 for .ir — registry publishes registrant |
| `dnssec_supported` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `requires_registrant_type` | `ENUM('any','individual','company') NOT NULL DEFAULT 'any'` | links to the customer KYC type |
| `requires_registry_handle` | `BOOLEAN NOT NULL DEFAULT 0` | 1 for .ir — customer must own an IRNIC handle before purchase is even offered |
| `kyc_profile` | `VARCHAR(40) NULL` | key into config('domains.kyc_profiles') listing which KYC fields/documents the registry demands. Config not DB, because the validation rules are code. |
| `transfer_supported` | `BOOLEAN NOT NULL DEFAULT 1` | 0 for .ir (handle-based ownership change, not EPP transfer) |
| `transfer_lock_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 60` | ICANN 60-day lock after registration/owner change |
| `renew_grace_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 30` | auto-renew grace after expiry |
| `redemption_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 30` | — |
| `pending_delete_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 5` | — |
| `pricing_mode` | `ENUM('manual','auto_markup') NOT NULL DEFAULT 'auto_markup'` | auto_markup lets admin add a TLD without typing a price in every currency |
| `markup_percent` | `SMALLINT UNSIGNED NOT NULL DEFAULT 25` | applied to the winning registrar cost when pricing_mode='auto_markup' |
| `premium_markup_percent` | `SMALLINT UNSIGNED NOT NULL DEFAULT 15` | premium domains have no list price; sell = check-response cost × this, rounded up |
| `comparison_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 2` | TCO horizon for registrar comparison. 1 here would reintroduce the cheap-first-year trap; a CHECK forbids 0. |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (tld)` · `INDEX (is_active, is_featured, sort_order)` · `CHECK (comparison_years >= 1)` · `CHECK (max_years >= min_years)`

### `tld_translations`

All customer-facing text for a TLD in fa/en/tr. Same shape as the existing post_translations so the convention is not reinvented.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) CASCADE` | — |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `name` | `VARCHAR(120) NOT NULL` | e.g. «دامنه آی‌آر» / '.ir domain' |
| `tagline` | `VARCHAR(255) NULL` | one-liner on the TLD card |
| `description` | `TEXT NULL` | landing-page body |
| `requirements_note` | `TEXT NULL` | customer-visible eligibility text, e.g. «ثبت .ir نیازمند شناسه ایرنیک و کد ملی است». THIS is what stops support tickets. |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (tld_id, locale)` · `INDEX (locale)`

### `tld_prices`

Our RETAIL price list — what the customer pays. Deliberately separate from cost. Only years=1 is mandatory; other years are optional overrides.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) CASCADE` | — |
| `currency_code` | `CHAR(3) CHARACTER SET ascii NOT NULL` | 'EUR' \| 'IRT' \| 'USD'. IRT is our own code for Toman, exponent 0. IRR must never appear — mixing IRR and IRT is a 10× pricing bug. |
| `action` | `ENUM('register','renew','transfer','restore') NOT NULL` | renew is a first-class row, not a derived value |
| `years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | price for the WHOLE term. Absent term N ⇒ years=1 price × N. |
| `price_minor` | `BIGINT UNSIGNED NOT NULL` | integer minor units of currency_code |
| `promo_price_minor` | `BIGINT UNSIGNED NULL` | our own promotion, unrelated to registrar promos |
| `promo_starts_at` | `TIMESTAMP NULL` | — |
| `promo_ends_at` | `TIMESTAMP NULL` | NULL promo_price with non-null dates is invalid; enforced in the model |
| `promo_max_per_customer` | `SMALLINT UNSIGNED NULL` | stops one customer draining a loss-leader promo |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (tld_id, currency_code, action, years)` · `INDEX (currency_code, action)`

### `registrar_accounts`

One row per registrar ACCOUNT (not per brand) — an OpenProvider reseller account, a CentralNic account, the IRNIC account. Credentials, health and circuit-breaker state live here.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `code` | `VARCHAR(40) CHARACTER SET ascii NOT NULL` | stable slug used in logs/idempotency keys, e.g. 'openprovider-main' |
| `driver` | `VARCHAR(40) CHARACTER SET ascii NOT NULL` | key into config('domains.drivers') => FQCN implementing RegistrarDriver. Adding a registrar = new class + one config line + one row here. |
| `label` | `VARCHAR(120) NOT NULL` | ADMIN-ONLY. Never rendered to customers — the offering is white-labelled, so this needs no translation. |
| `environment` | `ENUM('live','sandbox') NOT NULL DEFAULT 'live'` | sandbox accounts are excluded from routing by the router, not by an admin remembering |
| `credentials` | `TEXT NOT NULL` | JSON ON PURPOSE, encrypted with Laravel's encrypter. Shape differs per driver (username/password/hash vs api-key vs certificate). Never queried, never indexed. |
| `api_endpoint` | `VARCHAR(255) NULL` | override for OTE/regional endpoints |
| `call_via` | `ENUM('direct','proxy') NOT NULL DEFAULT 'direct'` | CRITICAL for the Iran install: registrar APIs geoblock Iranian IPs. 'proxy' routes the HTTP call through the Germany node while the record stays in the Iran DB. |
| `billing_currency` | `CHAR(3) CHARACTER SET ascii NOT NULL` | currency the registrar charges US in |
| `balance_minor` | `BIGINT NOT NULL DEFAULT 0` | signed — accounts can go negative. Last known prepaid balance. |
| `balance_min_minor` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | routing floor: below this the account is skipped and the owner is alerted |
| `balance_checked_at` | `TIMESTAMP NULL` | — |
| `health_state` | `ENUM('ok','degraded','down') NOT NULL DEFAULT 'ok'` | circuit breaker. 'down' ⇒ excluded from routing AND its check results map to state 'unknown', never 'available'. |
| `consecutive_failures` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | — |
| `health_checked_at` | `TIMESTAMP NULL` | — |
| `rate_limit_per_minute` | `SMALLINT UNSIGNED NOT NULL DEFAULT 60` | feeds a per-account throttle so a domain-search page cannot get the account banned |
| `check_batch_size` | `SMALLINT UNSIGNED NOT NULL DEFAULT 20` | max domains per bulk check call |
| `priority` | `SMALLINT UNSIGNED NOT NULL DEFAULT 100` | tie-break only — never a substitute for TCO |
| `is_active` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (code)` · `INDEX (is_active, health_state)` · `INDEX (driver)`

### `registrar_tlds`

THE routing + cost table: which registrar account may sell which TLD, at what wholesale cost for register / renew / transfer / restore, plus the anti-trap quarantine counters. This is the table the comparison reads.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `registrar_account_id` | `BIGINT UNSIGNED NOT NULL FK registrar_accounts(id) CASCADE` | — |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) CASCADE` | — |
| `can_register` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `can_renew` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `can_transfer` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `can_restore` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `supports_premium` | `BOOLEAN NOT NULL DEFAULT 0` | if 0, a check result flagged premium from this account is discarded rather than priced |
| `min_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | registrar-side term limits, can be narrower than the registry's |
| `max_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 10` | — |
| `cost_currency` | `CHAR(3) CHARACTER SET ascii NOT NULL` | — |
| `cost_register_minor` | `BIGINT UNSIGNED NULL` | standard (non-promo) 1-year wholesale cost, minor units |
| `cost_renew_minor` | `BIGINT UNSIGNED NULL` | THE column that makes comparison honest. A row with NULL here is excluded from routing entirely — we will not sell what we cannot price the renewal of. |
| `cost_transfer_minor` | `BIGINT UNSIGNED NULL` | — |
| `cost_restore_minor` | `BIGINT UNSIGNED NULL` | redemption fees are 10–20×; needed for the customer-facing restore quote |
| `promo_register_minor` | `BIGINT UNSIGNED NULL` | registrar's advertised discount. USED ONLY IF the live check response echoes the same figure — see the routing rule. |
| `promo_starts_at` | `TIMESTAMP NULL` | — |
| `promo_ends_at` | `TIMESTAMP NULL` | nightly job nulls out expired promos; an expired promo silently in play is exactly the $20→$2 bug |
| `promo_max_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | promos almost always apply to year 1 only |
| `price_source` | `ENUM('api','manual') NOT NULL DEFAULT 'api'` | 'manual' rows are never overwritten by cost sync |
| `price_synced_at` | `TIMESTAMP NULL` | a row not synced in 48h is treated as stale and deprioritised |
| `refusal_count` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | rolling count of 'quoted then refused' incidents |
| `refusal_window_started_at` | `TIMESTAMP NULL` | counter resets after config('domains.refusal_window') (default 24h) |
| `last_refusal_code` | `VARCHAR(60) NULL` | normalised code, e.g. 'not_free', 'price_mismatch', 'premium_undisclosed' |
| `quarantined_until` | `TIMESTAMP NULL` | set automatically at N refusals. While set, this (registrar,TLD) is excluded from routing — the customer silently gets the next-best registrar instead of a failed purchase. |
| `is_enabled` | `BOOLEAN NOT NULL DEFAULT 1` | admin kill switch |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (registrar_account_id, tld_id)` · `INDEX (tld_id, is_enabled, can_register)` · `INDEX (quarantined_until)`

### `domain_quotes`

Short-TTL binding price. Every Buy button in the UI is backed by exactly one row here; nothing else can start a purchase. This is where the three UI states and premium-from-check-response are enforced.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `ulid` | `CHAR(26) CHARACTER SET ascii NOT NULL` | public token handed to the browser; non-enumerable |
| `customer_id` | `BIGINT UNSIGNED NULL FK customers(id) SET NULL` | null for anonymous search; bound at cart-add |
| `cart_id` | `BIGINT UNSIGNED NULL` | FK to billing's cart, nullable |
| `session_fingerprint` | `CHAR(64) CHARACTER SET ascii NULL` | hash of session id — ties an anonymous quote to the browser that made it |
| `ip` | `VARBINARY(16) NULL` | abuse throttling; stored packed, not as text |
| `locale` | `VARCHAR(5) NOT NULL DEFAULT 'fa'` | which language the price was presented in |
| `domain` | `VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | punycode/ASCII, lowercase. ascii charset keeps the index inside MySQL's 3072-byte limit. |
| `domain_unicode` | `VARCHAR(255) NOT NULL` | utf8mb4 display form for IDN |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) RESTRICT` | — |
| `action` | `ENUM('register','transfer','renew','restore') NOT NULL` | — |
| `years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | — |
| `state` | `ENUM('available','premium','unavailable','unknown') NOT NULL` | THE three UI states plus 'unknown'. 'unknown' renders 'we could not check' with NO price and NO buy button — never fall back to 'available'. |
| `unavailable_reason` | `ENUM('taken','reserved','registry_blocked','not_sellable','policy','invalid') NULL` | lets the UI say «ثبت‌شده» vs «قابل عرضه نیست» vs «محدود — تماس بگیرید». Rendered via __('ui.domain.reason.*') in all three languages. |
| `is_premium` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `premium_class` | `VARCHAR(40) NULL` | registry's premium tier label if given |
| `registrar_account_id` | `BIGINT UNSIGNED NULL FK registrar_accounts(id) RESTRICT` | the WINNER of the TCO comparison. NULL only when state is unavailable/unknown. |
| `cost_currency` | `CHAR(3) CHARACTER SET ascii NULL` | — |
| `cost_first_minor` | `BIGINT UNSIGNED NULL` | wholesale cost of the first term as returned by THIS check |
| `cost_renew_minor` | `BIGINT UNSIGNED NULL` | wholesale renewal cost per year — for premium this comes from the check response and is the number that matters |
| `sell_currency` | `CHAR(3) CHARACTER SET ascii NOT NULL` | IRT on the .ir install, EUR on .cloud |
| `sell_first_minor` | `BIGINT UNSIGNED NULL` | what the customer pays now, whole term |
| `sell_renew_minor` | `BIGINT UNSIGNED NULL` | what they will pay per year at renewal. MUST be displayed next to the buy button for premiums. |
| `price_source` | `ENUM('check_response','tld_price_list','manual') NOT NULL` | THE guard. CHECK (is_premium = 0 OR price_source = 'check_response') — a premium can never be priced from the TLD list at the database level. |
| `registrar_quote_ref` | `VARCHAR(120) NULL` | opaque token/price-id some registrars return with the check and accept on create; replayed verbatim to bind the price |
| `check_hash` | `CHAR(64) CHARACTER SET ascii NULL` | sha256 of the normalised check response; the pre-create re-check must reproduce it or the purchase aborts |
| `api_call_id` | `BIGINT UNSIGNED NULL FK registrar_api_calls(id) SET NULL` | the raw evidence for this price |
| `expires_at` | `TIMESTAMP NOT NULL` | 300s for premium, 900s standard (config). Hard-expired at checkout, never silently extended. |
| `consumed_at` | `TIMESTAMP NULL` | set when an order item is created; a consumed quote can never be reused |
| `order_item_id` | `BIGINT UNSIGNED NULL` | FK to billing |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (ulid)` · `INDEX (domain, action, expires_at)` · `INDEX (customer_id, consumed_at)` · `INDEX (expires_at)  -- prune job` · `CHECK (is_premium = 0 OR price_source = 'check_response')` · `CHECK (state <> 'available' OR registrar_account_id IS NOT NULL)`

### `domain_quote_options`

The losing (and winning) registrar alternatives for a quote, with the TCO figures used. This is the audit trail that answers 'why did we buy from there' and the data for the owner's margin analysis.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `quote_id` | `BIGINT UNSIGNED NOT NULL FK domain_quotes(id) CASCADE` | real FK, not a JSON blob, precisely because it points at a registrar row |
| `registrar_account_id` | `BIGINT UNSIGNED NOT NULL FK registrar_accounts(id) CASCADE` | — |
| `state` | `ENUM('available','premium','unavailable','unknown','error') NOT NULL` | registrars disagree constantly; the disagreement itself is signal |
| `cost_currency` | `CHAR(3) CHARACTER SET ascii NULL` | — |
| `cost_first_minor` | `BIGINT UNSIGNED NULL` | promo applied only if echoed by the check |
| `cost_renew_minor` | `BIGINT UNSIGNED NULL` | — |
| `promo_applied` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `tco_years` | `TINYINT UNSIGNED NOT NULL` | horizon used, copied from tlds.comparison_years |
| `tco_base_minor` | `BIGINT UNSIGNED NULL` | first + renew×(H−1), converted to config('domains.base_currency') at the FX rate below |
| `fx_rate_id` | `BIGINT UNSIGNED NULL FK fx_rates(id) SET NULL` | the exact rate used, so the comparison is reproducible months later |
| `rank` | `TINYINT UNSIGNED NULL` | 1 = winner |
| `rejected_reason` | `ENUM('not_routable','quarantined','unhealthy','low_balance','no_renew_price','margin_floor','term_unsupported','premium_unsupported','costlier') NULL` | 'margin_floor' = selling would lose money after FX; 'no_renew_price' = we refused to guess a renewal |
| `latency_ms` | `SMALLINT UNSIGNED NULL` | feeds the health score |
| `created_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (quote_id, registrar_account_id)` · `INDEX (registrar_account_id, created_at)`

### `domains`

The owned asset. One row per domain under management, including the registrar it must renew at and the renewal price we have committed to.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK customers(id) RESTRICT` | — |
| `domain` | `VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | punycode, lowercase, no trailing dot |
| `domain_unicode` | `VARCHAR(255) NOT NULL` | — |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) RESTRICT` | — |
| `registrar_account_id` | `BIGINT UNSIGNED NOT NULL FK registrar_accounts(id) RESTRICT` | RENEWAL GOES HERE. Changing it is only legal via a completed transfer, enforced in the model. |
| `registrar_domain_ref` | `VARCHAR(120) NULL` | registrar's internal id/handle for the domain |
| `status` | `ENUM('pending','active','expired','grace','redemption','pending_delete','transferred_away','cancelled','failed') NOT NULL DEFAULT 'pending'` | 'pending' = paid/authorised but registrar has not confirmed |
| `quote_id` | `BIGINT UNSIGNED NULL FK domain_quotes(id) SET NULL` | the price we promised, kept for dispute resolution |
| `order_item_id` | `BIGINT UNSIGNED NULL` | FK to billing |
| `registered_at` | `TIMESTAMP NULL` | — |
| `registry_expires_at` | `TIMESTAMP NULL` | THE REGISTRY'S truth, refreshed by the sync job |
| `paid_through_at` | `TIMESTAMP NULL` | OUR truth — what the customer has paid for. These two diverging is a real financial event and the sync job alerts on it. |
| `auto_renew` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `auto_renew_years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | — |
| `renew_offset_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 25` | days before expiry we actually execute the renewal |
| `is_premium` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `renew_currency` | `CHAR(3) CHARACTER SET ascii NULL` | — |
| `renew_price_minor` | `BIGINT UNSIGNED NULL` | the retail renewal price snapshotted at purchase. For premiums this is mandatory (CHECK) — the whole point of the premium trap. |
| `renew_cost_minor` | `BIGINT UNSIGNED NULL` | expected wholesale, so the renewal job can detect a cost blow-up before charging |
| `transfer_lock` | `BOOLEAN NOT NULL DEFAULT 1` | — |
| `auth_code` | `TEXT NULL` | encrypted at rest; redacted from every log. Nulled after a completed outbound transfer. |
| `auth_code_updated_at` | `TIMESTAMP NULL` | — |
| `whois_privacy` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `dnssec_enabled` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `registrant_contact_id` | `BIGINT UNSIGNED NULL FK domain_contacts(id) RESTRICT` | — |
| `admin_contact_id` | `BIGINT UNSIGNED NULL FK domain_contacts(id) RESTRICT` | — |
| `tech_contact_id` | `BIGINT UNSIGNED NULL FK domain_contacts(id) RESTRICT` | — |
| `billing_contact_id` | `BIGINT UNSIGNED NULL FK domain_contacts(id) RESTRICT` | — |
| `registrant_verified_at` | `TIMESTAMP NULL` | ICANN RAA registrant email verification. NULL past the deadline ⇒ registry suspends the domain. |
| `registrant_verify_deadline` | `TIMESTAMP NULL` | 15 days from registration/contact change |
| `last_synced_at` | `TIMESTAMP NULL` | — |
| `sync_drift` | `JSON NULL` | JSON ON PURPOSE: the diff between our record and the registry on last sync (fields differ per registrar). Reviewed by a human, never queried. |
| `created_at / updated_at / deleted_at` | `TIMESTAMP NULL` | soft delete — never hard-delete a domain record |

**ایندکس:** `UNIQUE (domain)` · `INDEX (customer_id, status)` · `INDEX (status, registry_expires_at)  -- expiry/dunning sweeps` · `INDEX (registrar_account_id, status)` · `INDEX (auto_renew, paid_through_at)` · `CHECK (is_premium = 0 OR renew_price_minor IS NOT NULL)`

### `domain_contacts`

Registrant/admin/tech/billing handles, scoped to the registrar or registry that issued them. A customer has one handle per registrar namespace, reused across their domains.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK customers(id) CASCADE` | — |
| `customer_identity_id` | `BIGINT UNSIGNED NULL FK customer_identities(id) RESTRICT` | the verified KYC record this contact was derived from. National ID / company registration number live THERE, not duplicated here — one copy of the PII. |
| `handle_namespace` | `VARCHAR(40) CHARACTER SET ascii NOT NULL` | 'openprovider-main' \| 'irnic' \| 'centralnic'. Handles are not portable across registrars, so the namespace is part of identity. |
| `registrar_account_id` | `BIGINT UNSIGNED NULL FK registrar_accounts(id) SET NULL` | NULL for registry-owned handles (IRNIC handles belong to the customer, not to us) |
| `handle` | `VARCHAR(120) CHARACTER SET ascii NULL` | remote id, e.g. 'ab1234-irnic'. NULL until created remotely. |
| `role` | `ENUM('registrant','admin','tech','billing','all') NOT NULL DEFAULT 'all'` | most registrars accept one object for all roles |
| `entity_type` | `ENUM('individual','company') NOT NULL` | mirrors the two KYC types |
| `first_name` | `VARCHAR(80) NOT NULL` | — |
| `last_name` | `VARCHAR(80) NOT NULL` | — |
| `first_name_latin` | `VARCHAR(80) NULL` | IRNIC and several registries demand BOTH a Persian and a Latin name. Real columns because they are mandatory API fields. |
| `last_name_latin` | `VARCHAR(80) NULL` | — |
| `org_name` | `VARCHAR(160) NULL` | required when entity_type='company' |
| `org_name_latin` | `VARCHAR(160) NULL` | — |
| `job_title` | `VARCHAR(80) NULL` | representative's position — required by IRNIC for legal entities |
| `email` | `VARCHAR(190) NOT NULL` | the address ICANN verification is sent to |
| `phone_cc` | `VARCHAR(5) CHARACTER SET ascii NOT NULL` | e.g. '98'. Split from the number because registrars want E.164 parts. |
| `phone` | `VARCHAR(24) CHARACTER SET ascii NOT NULL` | — |
| `fax_cc / fax` | `VARCHAR(5) / VARCHAR(24) NULL` | still mandatory at a few registries |
| `address1` | `VARCHAR(190) NOT NULL` | — |
| `address2` | `VARCHAR(190) NULL` | — |
| `city` | `VARCHAR(80) NOT NULL` | — |
| `state` | `VARCHAR(80) NULL` | — |
| `postal_code` | `VARCHAR(20) CHARACTER SET ascii NOT NULL` | — |
| `country_code` | `CHAR(2) CHARACTER SET ascii NOT NULL` | ISO 3166-1 alpha-2 |
| `extra` | `JSON NULL` | JSON ON PURPOSE: registry EXTENSION fields only — .eu citizenship, .us nexus category, .de abuse contact, IRNIC-specific flags. Long tail, per-registry, never joined or filtered. Everything universal above is a real column. |
| `verification_state` | `ENUM('none','pending','verified','failed') NOT NULL DEFAULT 'none'` | — |
| `verified_at` | `TIMESTAMP NULL` | — |
| `sync_state` | `ENUM('local','synced','stale','error') NOT NULL DEFAULT 'local'` | 'stale' ⇒ a contact update job is queued for every domain using it |
| `last_error_code` | `VARCHAR(60) NULL` | normalised, mapped to a ui.* key for display |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (handle_namespace, handle)` · `INDEX (customer_id, handle_namespace)` · `INDEX (verification_state, verified_at)`

### `domain_nameservers`

Delegation for a domain. A real table, not a JSON column, because glue records are edited individually and we query 'which domains still point at the old NS'.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `domain_id` | `BIGINT UNSIGNED NOT NULL FK domains(id) CASCADE` | — |
| `position` | `TINYINT UNSIGNED NOT NULL` | 1..13, ordering matters to some registries |
| `host` | `VARCHAR(255) CHARACTER SET ascii NOT NULL` | punycode, lowercase |
| `glue_ipv4` | `VARBINARY(4) NULL` | only for in-bailiwick child nameservers |
| `glue_ipv6` | `VARBINARY(16) NULL` | — |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (domain_id, position)` · `INDEX (host)`

### `domain_operations`

The state machine and idempotency ledger for every registrar-side action. Nothing calls a registrar outside a row here. This is where the four-valued outcome (confirmed/pending/refused/unknown) lives.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `type` | `ENUM('register','renew','restore','transfer_in','transfer_out','update_ns','update_contacts','set_lock','set_privacy','set_autorenew','fetch_auth_code','sync','delete') NOT NULL` | — |
| `idempotency_key` | `VARCHAR(80) CHARACTER SET ascii NOT NULL` | UNIQUE. e.g. sha256('register\|order_item:123\|example.com'). The single most important column here — a double-submitted register is a non-refundable double charge from the registrar. |
| `domain_id` | `BIGINT UNSIGNED NULL FK domains(id) CASCADE` | NULL for a register that has not created the domain row yet |
| `domain` | `VARCHAR(255) CHARACTER SET ascii NOT NULL` | denormalised so a failed register is still traceable |
| `quote_id` | `BIGINT UNSIGNED NULL FK domain_quotes(id) SET NULL` | — |
| `order_item_id` | `BIGINT UNSIGNED NULL` | FK to billing |
| `payment_authorization_id` | `BIGINT UNSIGNED NULL` | FK to billing's authorisation/hold. Captured on 'succeeded', voided on 'refused', LEFT ALONE on 'unknown'. |
| `registrar_account_id` | `BIGINT UNSIGNED NOT NULL FK registrar_accounts(id) RESTRICT` | — |
| `state` | `ENUM('queued','running','awaiting_registry','awaiting_customer','succeeded','refused','failed','needs_manual','cancelled') NOT NULL DEFAULT 'queued'` | 'awaiting_registry' is the timeout/UNKNOWN state — the reconciler owns it. 'needs_manual' is where semi-automated IRNIC steps land. |
| `attempts` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` | — |
| `max_attempts` | `TINYINT UNSIGNED NOT NULL DEFAULT 5` | — |
| `scheduled_at` | `TIMESTAMP NULL` | drives auto-renew scheduling and reconcile backoff |
| `started_at / finished_at` | `TIMESTAMP NULL` | — |
| `request_payload` | `JSON NULL` | JSON ON PURPOSE: driver-specific request shape, audit only, redacted of credentials and auth codes. |
| `response_payload` | `JSON NULL` | JSON ON PURPOSE: same rationale. |
| `error_code` | `VARCHAR(60) NULL` | NORMALISED code from the driver ('not_free','price_mismatch','insufficient_funds','registry_timeout','contact_invalid'). Registrar raw strings are never shown to a customer. |
| `error_message_raw` | `TEXT NULL` | admin/debug only |
| `customer_message_key` | `VARCHAR(80) NULL` | a ui.* key, e.g. 'ui.domain.err.not_free'. Resolved at render time so fa/en/tr all work with no per-locale storage. |
| `customer_message_params` | `JSON NULL` | JSON ON PURPOSE: substitution values for the key above |
| `cost_variance_minor` | `BIGINT NULL` | signed. What the registrar actually charged minus the quoted cost. Non-zero means we were lied to — an owner alert, not a customer-facing event. |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (idempotency_key)` · `INDEX (state, scheduled_at)` · `INDEX (domain_id, type, created_at)` · `INDEX (registrar_account_id, state)` · `INDEX (type, error_code, created_at)  -- refusal analytics`

### `domain_transfers`

Inbound and outbound transfers. Separate from domain_operations because a transfer is a multi-day, customer-visible process with its own states and deadlines, not a single API call.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK customers(id) RESTRICT` | — |
| `domain_id` | `BIGINT UNSIGNED NULL FK domains(id) SET NULL` | created only once an inbound transfer completes |
| `domain` | `VARCHAR(255) CHARACTER SET ascii NOT NULL` | — |
| `tld_id` | `BIGINT UNSIGNED NOT NULL FK tlds(id) RESTRICT` | — |
| `direction` | `ENUM('in','out') NOT NULL` | — |
| `registrar_account_id` | `BIGINT UNSIGNED NULL FK registrar_accounts(id) RESTRICT` | the gaining account for 'in'; the losing account for 'out' |
| `losing_registrar_name` | `VARCHAR(120) NULL` | free text from WHOIS, admin-facing only |
| `auth_code` | `TEXT NULL` | encrypted; wiped on completion or expiry |
| `quote_id` | `BIGINT UNSIGNED NULL FK domain_quotes(id) SET NULL` | transfers are quoted through the same path (action='transfer') |
| `order_item_id` | `BIGINT UNSIGNED NULL` | — |
| `state` | `ENUM('draft','awaiting_auth_code','submitted','awaiting_foa','awaiting_registry','completed','rejected','cancelled','expired') NOT NULL DEFAULT 'draft'` | — |
| `registry_status` | `VARCHAR(60) NULL` | raw EPP status, admin-facing |
| `reason_code` | `VARCHAR(60) NULL` | normalised rejection reason → ui.* key |
| `foa_sent_at` | `TIMESTAMP NULL` | Form of Authorization |
| `submitted_at` | `TIMESTAMP NULL` | — |
| `deadline_at` | `TIMESTAMP NULL` | the 5-day auto-approve window; a job nudges the customer before it lapses |
| `completed_at` | `TIMESTAMP NULL` | — |
| `adds_year` | `BOOLEAN NOT NULL DEFAULT 1` | most gTLD transfers add a year; .ir does not — affects what we charge |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `INDEX (customer_id, state)` · `INDEX (state, deadline_at)` · `UNIQUE (domain, direction, state) WHERE state IN ('submitted','awaiting_foa','awaiting_registry')  -- express as a partial/unique-guard in the model on MySQL`

### `domain_events`

Customer-visible timeline for a domain. Stores a translation KEY plus parameters, never a rendered sentence — so one row renders correctly in fa, en and tr.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `domain_id` | `BIGINT UNSIGNED NOT NULL FK domains(id) CASCADE` | — |
| `event_key` | `VARCHAR(80) CHARACTER SET ascii NOT NULL` | e.g. 'domain.registered', 'domain.ns_changed', 'domain.renew_failed'. Must exist as ui.* in ALL THREE lang files. |
| `params` | `JSON NULL` | JSON ON PURPOSE: substitution values (old/new NS, dates, amounts). Presentation data, never queried. |
| `visibility` | `ENUM('customer','admin') NOT NULL DEFAULT 'customer'` | cost/registrar details stay admin-only — white-label |
| `actor_type` | `ENUM('customer','admin','system','registrar') NOT NULL` | — |
| `actor_id` | `BIGINT UNSIGNED NULL` | — |
| `operation_id` | `BIGINT UNSIGNED NULL FK domain_operations(id) SET NULL` | — |
| `created_at` | `TIMESTAMP NOT NULL` | no updated_at — events are immutable |

**ایندکس:** `INDEX (domain_id, created_at)` · `INDEX (event_key, created_at)`

### `registrar_api_calls`

Raw request/response log. This is the evidence that the registrar advertised a price and then refused it — without it, the owner's complaint is unprovable.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `registrar_account_id` | `BIGINT UNSIGNED NOT NULL FK registrar_accounts(id) CASCADE` | — |
| `operation_id` | `BIGINT UNSIGNED NULL` | no FK — this table is pruned on a different schedule |
| `kind` | `ENUM('check','register','renew','transfer','contact','ns','sync','catalog','balance','other') NOT NULL` | 'check' rows are pruned aggressively; 'register'/'renew' kept 24 months |
| `domain` | `VARCHAR(255) CHARACTER SET ascii NULL` | — |
| `endpoint` | `VARCHAR(255) NOT NULL` | — |
| `http_status` | `SMALLINT UNSIGNED NULL` | — |
| `ok` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `duration_ms` | `MEDIUMINT UNSIGNED NULL` | feeds latency-based health |
| `request_body` | `MEDIUMTEXT NULL` | credentials, auth codes and national IDs redacted by a driver-declared redaction list before write |
| `response_body` | `MEDIUMTEXT NULL` | — |
| `created_at` | `TIMESTAMP NOT NULL` | — |

**ایندکس:** `INDEX (registrar_account_id, kind, created_at)` · `INDEX (domain, created_at)` · `INDEX (created_at)  -- prune`

### `dns_zones`

Authoritative DNS we operate for a domain (ours or not). Provider-agnostic so PowerDNS today and Cloudflare tomorrow need no schema change.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `customer_id` | `BIGINT UNSIGNED NOT NULL FK customers(id) RESTRICT` | — |
| `domain_id` | `BIGINT UNSIGNED NULL FK domains(id) SET NULL` | NULL when we host DNS for a domain registered elsewhere |
| `name` | `VARCHAR(255) CHARACTER SET ascii NOT NULL` | punycode zone apex, no trailing dot |
| `provider` | `VARCHAR(40) CHARACTER SET ascii NOT NULL` | key into config('domains.dns_providers') => FQCN implementing DnsProvider |
| `provider_zone_ref` | `VARCHAR(120) NULL` | — |
| `status` | `ENUM('pending','active','suspended','error') NOT NULL DEFAULT 'pending'` | — |
| `serial` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | — |
| `default_ttl` | `MEDIUMINT UNSIGNED NOT NULL DEFAULT 3600` | — |
| `dnssec_enabled` | `BOOLEAN NOT NULL DEFAULT 0` | — |
| `dnssec_ds` | `JSON NULL` | JSON ON PURPOSE: the DS record set as published, opaque and pushed verbatim to the registry. |
| `last_synced_at` | `TIMESTAMP NULL` | — |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `UNIQUE (name)` · `INDEX (customer_id, status)` · `INDEX (provider, status)`

### `dns_records`

Individual DNS records. Real columns for priority/weight/port because they are edited and validated per type.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `zone_id` | `BIGINT UNSIGNED NOT NULL FK dns_zones(id) CASCADE` | — |
| `type` | `ENUM('A','AAAA','CNAME','MX','TXT','NS','SRV','CAA','PTR','ALIAS') NOT NULL` | — |
| `name` | `VARCHAR(255) CHARACTER SET ascii NOT NULL` | relative label or '@' |
| `content` | `VARCHAR(2048) NOT NULL` | TXT for DKIM keys is long; 2048 is deliberate |
| `ttl` | `MEDIUMINT UNSIGNED NOT NULL DEFAULT 3600` | — |
| `priority` | `SMALLINT UNSIGNED NULL` | MX/SRV |
| `weight / port` | `SMALLINT UNSIGNED NULL` | SRV |
| `caa_flags` | `TINYINT UNSIGNED NULL` | — |
| `caa_tag` | `VARCHAR(20) CHARACTER SET ascii NULL` | — |
| `provider_record_ref` | `VARCHAR(120) NULL` | — |
| `is_locked` | `BOOLEAN NOT NULL DEFAULT 0` | system-managed records (e.g. provisioning A record) the customer must not delete |
| `created_at / updated_at` | `TIMESTAMP NULL` | — |

**ایندکس:** `INDEX (zone_id, type, name)` · `UNIQUE (zone_id, type, name, content(255))`

### `fx_rates`

Exchange rates used to compare registrar costs quoted in different currencies and to enforce the margin floor. SHARED with billing — listed here because the domain comparison is meaningless without it.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | — |
| `base` | `CHAR(3) CHARACTER SET ascii NOT NULL` | — |
| `quote` | `CHAR(3) CHARACTER SET ascii NOT NULL` | — |
| `rate` | `DECIMAL(20,10) NOT NULL` | THE ONLY non-integer money-adjacent column in the subsystem. A rate is not an amount; DECIMAL is exact, never FLOAT. |
| `source` | `VARCHAR(40) NOT NULL` | 'manual' \| 'tgju' \| 'ecb' — the Toman rate is realistically manual/scraped |
| `effective_at` | `TIMESTAMP NOT NULL` | — |
| `created_at` | `TIMESTAMP NOT NULL` | — |

**ایندکس:** `INDEX (base, quote, effective_at DESC)` · `UNIQUE (base, quote, source, effective_at)`

## تصمیم‌های کلیدی

**All money is BIGINT UNSIGNED in integer minor units, always paired with a CHAR(3) currency column; the minor-unit exponent lives in a `currencies` table (EUR=2, USD=2, IRT=0). The only exception is `fx_rates.rate` (DECIMAL(20,10)), because a rate is not an amount. 'IRT' is our own code for Toman and IRR is banned from the schema.**

Integers make every arithmetic operation exact and every comparison trivially correct. A single representation with no exceptions means no developer ever has to ask which column is which. Banning IRR removes an entire class of 10× bugs, which in Toman pricing is the difference between 1,290,000 and 12,900,000.

*رد شد:* DECIMAL(12,2) everywhere — rejected because Toman prices exceed practical scale and the trailing '.00' invites accidental float casts in PHP/JSON. Also rejected: a single global 'store everything in EUR cents' base currency, which would force lossy round-tripping on every Toman price the owner types.

**Searching creates quote rows. Every purchasable result rendered in the UI is backed by exactly one `domain_quotes` row with a short TTL, and the Buy button carries only the quote ULID.**

It is the only structural way to guarantee 'we never show a price we cannot honour'. If the price lives only in a JSON response, there is nothing to validate at checkout and nothing to compare against later. Volume is bounded — a search renders ≤ ~8 purchasable rows, and expired quotes are pruned nightly.

*رد شد:* Pricing from a cache and creating a quote only at add-to-cart. Rejected because the displayed price and the quoted price would then be two different numbers computed by two code paths — precisely how the $20→$2 discrepancy survives.

**Premium pricing comes exclusively from the check response. `domain_quotes.price_source` is constrained: CHECK (is_premium = 0 OR price_source = 'check_response').**

The correction was already identified, so it should be enforced by the database rather than by developer discipline. A future refactor that accidentally falls back to the TLD list for a premium domain now fails to insert instead of silently underpricing a $3,000 domain at $12.

*رد شد:* Enforcing it only in the pricing service. Rejected because a service-layer rule is one careless `firstOrCreate` away from being bypassed.

**Registrar comparison is TCO over `tlds.comparison_years` (default 2): first-term cost + renewal cost × (H−1), FX-normalised to a base currency, with a CHECK forbidding a horizon of 0. A `registrar_tlds` row with a NULL `cost_renew_minor` is excluded from routing entirely.**

This is the direct fix for the cheap-first-year trap and it is deliberately blunt: if we do not know what renewal costs at a registrar, we do not sell through that registrar. A registrar offering $2 year one and $45 renewals loses to one offering $9/$11 at any horizon ≥ 2 — which is the correct commercial answer.

*رد شد:* A weighted score mixing price, latency and reliability. Rejected because it is unauditable — the owner needs to be able to point at two numbers and say why one registrar won. Reliability instead acts as a hard filter (quarantine/health), not a soft weight.

**A registrar promotional cost (`registrar_tlds.promo_register_minor`) is used ONLY if the live check response echoes back the same figure for that specific domain. Otherwise the standard cost is used for comparison and the standard retail price is shown.**

This is the exact mechanism of the owner's real failure: the promo is in the TLD price list but does not apply to this particular name (premium, reserved, or a restricted promotional pool). Treating the price list as advertising and the check response as truth removes the failure at the source rather than handling it after the customer is angry.

*رد شد:* Trusting the price list and reconciling losses afterwards. Rejected — the loss is not money, it is a customer who was shown a price and then refused.

**Authorise-then-capture, with the Iranian gateway's request→callback→verify flow used AS the authorise/capture pair: `verify` is simply not called until the registrar confirms. Refusal voids by never verifying; the gateway auto-releases.**

It maps the requirement onto what Iranian gateways actually do instead of pretending they support card holds. The customer's money is never taken for a domain we failed to buy, so there is no refund queue, no wallet credit the customer did not ask for, and no support ticket. Registration latency (typically < 30s) fits comfortably inside the gateway's unverified-transaction window.

*رد شد:* Charge-then-refund. Rejected because Iranian refunds are slow and manual, and because a refund for a failed .com purchase converts a technical failure into a trust failure. Also rejected: reserving from wallet balance only, which would exclude first-time gateway customers.

**A registrar timeout produces the outcome `unknown`, which is a distinct state (`domain_operations.state='awaiting_registry'`) from `refused`. Payment authorisation is left untouched and a reconciler polls the registrar by domain name for up to 24h before any human involvement.**

A timed-out register very often succeeded at the registry. Treating it as a failure and retrying causes a second registration attempt (double wholesale charge, non-refundable) or releases payment on a domain we now own. The four-valued outcome — confirmed / pending / refused / unknown — is the single most important contract in the driver interface.

*رد شد:* Boolean success/failure from the driver. Rejected outright; it is the standard way provisioning systems lose money.

**Repeated 'quoted then refused' incidents auto-quarantine the (registrar, TLD) pair via counters on `registrar_tlds` (`refusal_count`, `quarantined_until`), and the specific domain gets a 24h negative availability cache. No separate incidents table.**

The routing engine already reads `registrar_tlds` on every quote, so putting the circuit-breaker state there costs zero extra joins on the hot path. Full per-incident detail already exists in `domain_operations` (normalised error_code) and `registrar_api_calls` (raw evidence), so a third table would only duplicate it.

*رد شد:* A `registrar_price_incidents` table. Rejected as a thin table whose only unique content is a foreign key and a timestamp that two existing tables already carry.

**Registrant handles are modelled per `handle_namespace` (per registrar/registry), with real columns for the universal ICANN contact set plus Latin-transliterated name fields, and a JSON `extra` restricted to registry EXTENSION fields. National IDs and company registration numbers are NOT stored here — only a FK to the customer's verified KYC record.**

Handles genuinely are not portable between registrars, so the namespace belongs in the identity of the row. Keeping the universal 20 fields as real columns means validation, indexing and admin editing all work normally, while the genuinely unbounded per-registry tail (.us nexus, .eu citizenship, IRNIC flags) stays flexible. Keeping national IDs in one place limits PII blast radius.

*رد شد:* One contact row per domain (duplicating PII per purchase, and making a contact update an N-row rewrite), and a fully generic key/value `contact_fields` table (unqueryable, unvalidatable, and slow).

**.ir is fenced structurally: `tlds.registry_scheme='irnic'`, `requires_registry_handle=1`, `whois_privacy_supported=0`, `transfer_supported=0`, and a router assertion that a row with scheme 'irnic' can only resolve to a driver whose code is 'irnic'.**

'.ir ONLY via IRNIC' must be impossible to violate by a config mistake, not merely documented. Making it a data property plus a guarded invariant means adding a new gTLD registrar can never accidentally make .ir routable through it.

*رد شد:* Relying on `registrar_tlds` simply not having a row. Rejected because a cost-sync job that imports a registrar's full TLD catalogue would happily create that row at 3am.

**Customer-facing text is `tld_translations` rows (fa/en/tr, unique per tld+locale) for marketing/eligibility copy, and translation KEYS plus JSON params for everything event-like (`domain_events.event_key`, `domain_operations.customer_message_key`). No registrar string ever reaches a customer.**

It matches the existing `post_translations` convention so nothing new must be learned, and it makes the trilingual requirement automatic for the high-volume tables: one event row renders in all three languages with no per-locale storage. Registrar errors are English, inconsistent, and sometimes leak wholesale pricing — mapping them to normalised codes fixes translation, white-labelling and information leakage in one move.

*رد شد:* Storing rendered messages per locale on every event (3× rows, and a wording fix would require a backfill), and storing the registrar's raw error as the customer message.

**Retail price is computed from OUR `tld_prices` list (or an auto-markup on cost when `pricing_mode='auto_markup'`), independently of which registrar won. Cost decides WHO we buy from; it does not decide what the customer pays — except for premiums, where no list price can exist and sell = check-response cost × `premium_markup_percent`.**

Decoupling means switching registrar for arbitrage never changes the customer's price, so the arbitrage margin actually reaches the owner instead of being competed away. A `margin_floor` rejection reason additionally blocks any quote where cost after FX exceeds the retail price, which protects Toman pricing against a rial devaluation overnight.

*رد شد:* Cost-plus pricing on every quote. Rejected because the displayed price would then fluctuate with registrar routing and FX, which looks erratic to customers and makes published TLD price tables impossible.

**The punycode/ASCII form of every domain-like column uses `CHARACTER SET ascii` with a parallel utf8mb4 `*_unicode` display column.**

Under utf8mb4 a VARCHAR(255) unique index is 1020 bytes and multi-column unique indexes on domain names hit MySQL's 3072-byte limit; ascii makes them 255 bytes and the indexes simply work. It also makes lookups case- and normalisation-safe, since punycode is by definition ASCII.

*رد شد:* utf8mb4 everywhere with prefix indexes. Rejected because a prefix index on a domain name silently allows near-duplicate rows and breaks the UNIQUE guarantee that `domains.domain` depends on.

## ریسک‌ها

**IRNIC/nic.ir has no general-purpose reseller API comparable to OpenProvider. Registration under a customer's own IRNIC handle typically requires the customer to authenticate at nic.ir themselves. The stated goal of 'customer buys, service delivered automatically, no human in the loop' is probably not achievable for .ir — and .ir is likely a large share of Iranian volume.**

→ Model it honestly instead of pretending: `domain_operations.state='needs_manual'` plus an admin work queue with SLA timers, and a customer-facing flow that says «شناسه ایرنیک خود را وارد کنید» before payment is taken. Verify the exact API the owner has access to BEFORE writing the IRNIC driver — this is the single biggest unknown in the whole subsystem and should be spiked first.

**Sanctions and geoblocking. OpenProvider/CentralNic may refuse or later terminate an account whose registrants carry Iranian addresses, and their APIs may block Iranian source IPs outright. Losing the account means losing control of every domain registered through it.**

→ `registrar_accounts.call_via='proxy'` routes API traffic through the Germany node while the record stays in the Iran DB (matching the owner's architecture). Keep at least two accounts per major TLD group with real routing weight so no single account is a single point of failure, and export a full domain+auth-code register weekly to offline storage so a bulk transfer-out is always executable.

**Premium renewal shock. A premium domain renews at premium price forever. If the UI shows only the first year, either the customer refuses to renew (and blames ServerNet) or the renewal is auto-charged at 20× the original and becomes a chargeback.**

→ `domains.renew_price_minor` is NOT NULL for premiums at the database level, `domain_quotes.sell_renew_minor` must be rendered adjacent to the buy button, and premiums above a configurable threshold require an explicit checkbox acknowledging the renewal price. Auto-renew is defaulted OFF for premiums.

**IRT/EUR FX exposure. Costs are in EUR/USD, list prices in Toman. A rial move overnight can turn every .com sale into a loss while the price table still says 1,290,000.**

→ `fx_rates` with a configurable `fx_margin_percent` buffer, and a hard `margin_floor` rejection at quote time that refuses to produce a sellable quote when cost after FX exceeds the retail price. Daily alert listing every TLD whose margin fell below a floor percentage.

**Registrar prepaid balance depletion at 3am. Registrations start failing silently, and because the payment is only authorised (not captured) the customer just sees 'failed' with no money taken — quiet revenue loss that nobody notices for days.**

→ `balance_min_minor` excludes a low-balance account from routing before it starts failing, plus a balance poll every 15 minutes and an alert at 2× the floor. `domain_operations` error_code='insufficient_funds' should page, not log.

**ICANN registrant email verification. A registrant email that is not verified within 15 days causes the registry to suspend the domain — the site goes down and it looks like a ServerNet outage.**

→ `domain_contacts.verification_state` and `domains.registrant_verify_deadline` with a dunning job at day 3/7/12, escalating to a phone call. Reuse a single verified contact handle per customer per namespace so verification happens once, not per domain.

**Even with a pre-create re-check, the quote→purchase race cannot be fully closed at registrars without a price-lock API. Between the re-check and the create call, the registry can reclassify the name.**

→ Use `SupportsPriceLock::registerWithMaxPrice()` wherever the registrar supports it. Where it does not, accept the residual risk explicitly: compare `RegistrarOutcome::chargedCost` against the quote and write `domain_operations.cost_variance_minor`. A non-zero variance honours the customer's price and alerts the owner — the loss is bounded and visible rather than hidden.

**Auto-renew failure loses the customer's domain. If the renewal charge fails and the dunning is weak, the domain enters redemption where recovery costs 10–20× — a cost the customer will refuse to pay and will blame ServerNet for.**

→ Renew at `expires_at − 25 days`, not on the expiry date; notify at 60/30/14/7/3/1 days before and 1/7/15 days after; and make an explicit business decision (a config flag) about whether to renew at ServerNet's own expense for good-standing customers. `registry_expires_at` vs `paid_through_at` diverging must raise an alert, not be quietly reconciled.

**Domain search is an unauthenticated, expensive, rate-limited endpoint. A crawler or a keystroke-per-check UI will exhaust the registrar rate limit or get the account banned, and it also writes a quote row per result.**

→ `registrar_accounts.rate_limit_per_minute` behind a per-account throttle, 400ms debounce plus minimum-3-characters on the client, per-IP quota on `domain_quotes` creation (the packed `ip` column exists for this), aggressive cache for repeat queries, and a nightly prune of expired quotes.

**SQLite cannot carry this. Quotes, operations and API logs are write-heavy and concurrent; the current setup already has cache, session and queue in the same SQLite file with busy_timeout unset.**

→ MySQL/MariaDB is a hard prerequisite for this subsystem, and Redis should be added for the availability cache and the rate limiters (DB-backed cache would put search traffic straight back on the write path). Also add a queue worker — the register/renew flow is not viable on the sync queue driver.

**Two independent databases mean a domain can be registered twice — once from the .ir install and once from the .cloud install — through two different registrar accounts, or an Iranian customer can appear in both DBs with conflicting IRNIC handles.**

→ UNIQUE(domain) is per-database and therefore not sufficient. Before the create call, the driver's own `getRemoteDomain()` check plus the registrar's registry-level uniqueness catches it in practice; but the routing config should ensure the two installs never share a registrar account for the same TLD, and .ir should be `is_active=0` on the .cloud install entirely.

**Raw registrar responses contain wholesale prices and, in error strings, sometimes the competing registrar's identity. Leaking these through an error page destroys the white-label and shows customers the margin.**

→ `domain_operations.customer_message_key` is the only field the customer UI may read; `error_message_raw` and `registrar_api_calls` are admin-visibility-gated. `domain_events.visibility='admin'` covers any event carrying cost. A test should assert that no customer-facing view references a raw error column.

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Domains\Contracts;

/**
 * قرارداد اصلی رجیسترار.
 * افزودن رجیسترار جدید = یک کلاس + یک سطر در config('domains.drivers') + یک ردیف
 * در registrar_accounts. هیچ کد دیگری در سیستم تغییر نمی‌کند.
 */
interface RegistrarDriver
{
    /** کد پایدار درایور: 'openprovider' | 'centralnic' | 'irnic' */
    public static function code(): string;

    public function forAccount(RegistrarAccountConfig $account): static;

    public function capabilities(): Capabilities;

    /**
     * استعلام دسته‌ای با قیمت. **قیمت پرمیوم فقط از همین پاسخ می‌آید.**
     * پیاده‌سازی موظف است برای هر دامنه یکی از حالت‌های CheckState را برگرداند و
     * هرگز در خطا/تایم‌اوت AVAILABLE ندهد — باید UNKNOWN بدهد.
     *
     * @param  list<string>  $asciiDomains  حداکثر capabilities()->checkBatchSize
     * @return list<CheckResult>
     */
    public function check(array $asciiDomains, CheckOptions $options): array;

    /** همگام‌سازی شبانه‌ی هزینه‌ی ثبت/تمدید/انتقال همه‌ی پسوندها → registrar_tlds */
    public function tldCatalog(): TldCostCollection;

    public function register(RegisterRequest $request): RegistrarOutcome;

    public function renew(RenewRequest $request): RegistrarOutcome;

    /** برای reconcile پس از تایم‌اوت: آیا دامنه واقعاً ثبت شده؟ */
    public function getRemoteDomain(string $asciiDomain): ?RemoteDomain;

    /** موجودی حساب پیش‌پرداخت؛ null یعنی درایور مفهوم موجودی ندارد */
    public function accountBalance(): ?Money;

    /** کلیدهایی که قبل از نوشتن در registrar_api_calls باید حذف شوند */
    public function redactionKeys(): array;
}
```

```php
<?php

namespace App\Domains\Contracts;

/** قابلیت‌های اختیاری — تفکیک اینترفیس، تا درایور IRNIC مجبور به پیاده‌سازی EPP نباشد. */
interface SupportsTransferIn   { public function transferIn(TransferRequest $r): RegistrarOutcome;
                                 public function transferStatus(string $ascii): TransferStatus; }
interface SupportsTransferOut  { public function releaseDomain(string $ascii): RegistrarOutcome; }
interface SupportsRestore      { public function restore(string $ascii): RegistrarOutcome; }
interface SupportsAuthCode     { public function authCode(string $ascii): ?string; }
interface SupportsTransferLock { public function setTransferLock(string $ascii, bool $locked): RegistrarOutcome; }
interface SupportsWhoisPrivacy { public function setWhoisPrivacy(string $ascii, bool $on): RegistrarOutcome; }
interface SupportsNameservers  { /** @param list<NameserverSpec> $ns */
                                 public function updateNameservers(string $ascii, array $ns): RegistrarOutcome; }
interface SupportsDnssec       { public function updateDs(string $ascii, array $ds): RegistrarOutcome; }
interface SupportsAutoRenew    { public function setAutoRenew(string $ascii, bool $on): RegistrarOutcome; }

/** هندل‌های تماس — رجیسترارهایی که شیء contact جدا می‌سازند */
interface SupportsContactHandles
{
    public function createContact(ContactData $data): string;      // برمی‌گرداند: handle
    public function updateContact(string $handle, ContactData $data): RegistrarOutcome;
    public function updateDomainContacts(string $ascii, ContactSet $set): RegistrarOutcome;
    /** فیلدهای افزوده‌ی رجیستری که این درایور لازم دارد (برای ساخت خودکار فرم) */
    public function requiredExtraFields(string $tld): array;
}

/**
 * رجیسترارهایی که اجازه می‌دهند قیمت مورد انتظار را همراه سفارش بفرستیم.
 * وقتی این را داشته باشیم، مسابقه‌ی quote↔purchase در سمت رجیسترار بسته می‌شود.
 */
interface SupportsPriceLock
{
    public function registerWithMaxPrice(RegisterRequest $r, Money $maxCost): RegistrarOutcome;
}
```

```php
<?php

namespace App\Domains\Contracts;

enum CheckState: string {
    case AVAILABLE   = 'available';    // آزاد، با قیمت لیست
    case PREMIUM     = 'premium';      // آزاد، ولی قیمت اختصاصی از همین پاسخ
    case UNAVAILABLE = 'unavailable';  // ثبت‌شده / رزرو / ممنوع
    case UNKNOWN     = 'unknown';      // خطا یا تایم‌اوت — هرگز به AVAILABLE تنزل نکن
}

/** خروجی یک استعلام؛ قیمت‌ها در واحد فرعی صحیح، هرگز float */
final readonly class CheckResult
{
    public function __construct(
        public string      $asciiDomain,
        public CheckState  $state,
        public ?Money      $costFirstTerm = null,   // هزینه‌ی عمده‌فروشی دوره‌ی اول
        public ?Money      $costRenewYear = null,   // هزینه‌ی تمدید سالانه — برای پرمیوم حیاتی
        public ?Money      $costTransfer  = null,
        public bool        $isPremium     = false,
        public ?string     $premiumClass  = null,
        public bool        $promoApplied  = false,  // آیا تخفیف واقعاً روی همین دامنه اعمال شد؟
        public ?string     $quoteRef      = null,   // توکن قیمت که موقع ثبت بازپخش می‌شود
        public ?string     $unavailableReason = null,
        public ?string     $rawHash       = null,
    ) {}
}

enum OutcomeStatus: string {
    case CONFIRMED = 'confirmed';  // رجیسترار تأیید کرد → حالا capture کن
    case PENDING   = 'pending';    // رجیستری در حال پردازش (مثلاً transfer)
    case REFUSED   = 'refused';    // صریحاً رد شد → authorization را void کن، پول نگیر
    case UNKNOWN   = 'unknown';    // تایم‌اوت → نه capture نه void؛ برو سراغ reconcile
}

final readonly class RegistrarOutcome
{
    public function __construct(
        public OutcomeStatus $status,
        public ?string $remoteRef    = null,
        public ?Money  $chargedCost  = null,   // مقایسه با هزینه‌ی quote → cost_variance_minor
        public ?\DateTimeImmutable $expiresAt = null,
        public ?string $errorCode    = null,   // نرمال‌شده: not_free | price_mismatch | ...
        public ?string $errorRaw     = null,
        public array   $raw          = [],
    ) {}
}

/** مبلغ = عدد صحیح در واحد فرعی + ارز. هیچ‌جا float. */
final readonly class Money
{
    public function __construct(public int $minor, public string $currency) {}
}
```

```php
<?php

namespace App\Domains\Contracts;

/**
 * انتخاب رجیسترار بر پایه‌ی هزینه‌ی کل مالکیت (TCO)، نه قیمت سال اول.
 * پیاده‌سازی موظف است هر گزینه را در domain_quote_options بنویسد.
 */
interface RegistrarRouter
{
    /** @return list<RegistrarAccountConfig> حساب‌های مجاز برای این پسوند و این عمل */
    public function candidatesFor(int $tldId, string $action): array;

    /**
     * @param  list<CheckResult>  $results  کلید = registrar_account_id
     * @throws NoRoutableRegistrarException وقتی هیچ گزینه‌ای نمی‌ماند → state=unavailable/not_sellable
     */
    public function choose(int $tldId, string $action, int $years, array $results): RoutingDecision;

    /** پس از «قیمت داد ولی نفروخت»: شمارنده، قرنطینه، کش منفی و انتخاب گزینه‌ی بعدی */
    public function recordRefusal(int $registrarAccountId, int $tldId, string $asciiDomain, string $code): void;
}

interface DomainPriceEngine
{
    /** قیمت فروش دوره‌ی اول + قیمت تمدید سالانه. برای پرمیوم فقط از CheckResult. */
    public function sellPrice(TldModel $tld, CheckResult $check, string $action, int $years, string $currency): SellPrice;

    /** آیا با نرخ ارز فعلی، فروش زیر قیمت تمام‌شده است؟ */
    public function breachesMarginFloor(Money $cost, Money $sell): bool;
}

/** ساخت و اعتبارسنجی نقل‌قول کوتاه‌مدت — تنها راه رسیدن به دکمه‌ی خرید */
interface QuoteService
{
    public function quote(DomainQuery $q, ?int $customerId): QuoteResult;

    /** در checkout: منقضی/تغییرکرده؟ تفاوت را برگردان تا کاربر دوباره تأیید کند. */
    public function revalidate(string $ulid): QuoteValidation;

    /** درست پیش از فراخوانی register — باید check_hash قبلی را بازتولید کند */
    public function assertStillHonourable(string $ulid): void;
}

/** سیاست هر رجیستری: اعتبارسنجی هویت و فیلدهای اجباری، بدون if/else در سرویس اصلی */
interface RegistryPolicy
{
    public function scheme(): string;                    // 'icann' | 'irnic'
    public function validateRegistrant(ContactData $c, TldModel $tld): PolicyResult;
    public function requiresHandleBeforePurchase(): bool; // IRNIC: true
    public function normaliseLabel(string $label, TldModel $tld): string;
}

/** DNS مستقل از ارائه‌دهنده */
interface DnsProvider
{
    public static function code(): string;
    public function createZone(string $name, array $records): string;
    public function deleteZone(string $zoneRef): void;
    public function syncRecords(string $zoneRef, array $records): void;
    public function enableDnssec(string $zoneRef): array;   // مجموعه‌ی DS
}
```

