# billing

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

Billing is modelled as five layers that never leak into each other: an **honourable price** (`price_quotes`), an **intent** (`orders`/`order_items`, cart merged into orders), a **legal document** (`invoices`/`invoice_items`, `credit_notes`), a **money movement** (`payments`, `wallets`/`wallet_entries`, joined to documents only through `payment_allocations`), and a **subscription** (`services`, with `service_changes` for proration). Money is stored as `BIGINT` minor units plus an ISO code, with the exponent declared per currency in the `currencies` table — EUR exponent 2, IRT (Toman) exponent 0 — and every calculation is integer arithmetic with largest-remainder allocation, so a float never touches the system; on-chain crypto amounts are a separate concern and live in `DECIMAL(38,18)` columns inside the crypto tables only, never mixed with invoice money. The domain price bug the owner hit is solved structurally: nothing can be added to a cart or invoiced without a `price_quotes` row that carries the registrar's own quote reference, its class (standard/premium/special/restricted/unavailable), the **renewal** price alongside the first-term price, and a hard `expires_at` — an expired or consumed quote forces a re-quote and an explicit price-change confirmation before charge, and the sell price is always derived from the per-domain check response captured in that row, never from a TLD price list. Invoice numbers are gapless because they are allocated from a locked `invoice_sequences` row inside the same transaction as the invoice insert, at *issue* time (drafts carry `number = NULL`), never from auto-increment; the series is per install and per Jalali fiscal year on the Iranian side. The service lifecycle is `pending → active → suspended → terminated|cancelled` with provisioning tracked separately in `provision_status`, renewal invoices generated from `services.next_invoice_date` and made non-duplicable by a stored generated column with a unique index, and suspension/termination driven entirely by rows in `dunning_policies` so the owner changes the grace period without a deploy. Payment gateways are one `PaymentGateway` contract plus opt-in capability interfaces (`SupportsRefund`, `SupportsPolling`, `CryptoGateway`), registered as data in `gateways` with encrypted per-driver config, so a new Iranian PSP or a new crypto processor is a row plus one class and nothing else changes; the Iranian Rial-vs-Toman ×10 conversion happens only at the driver boundary and the converted figure is stored in `payments.gateway_amount` for reconciliation. Crypto is explicit about volatility: `crypto_payment_intents` locks a rate with a TTL, expects a computed `amount_expected`, counts confirmations from `crypto_assets.confirmations_required`, and resolves *every* over/under/late payment through the customer wallet rather than through bespoke invoice logic — funds received are always credited, an invoice is never marked paid for less than it is owed, and crypto is never auto-refunded. Customer-facing text is trilingual everywhere: short labels use `*_fa/_en/_tr` columns, and invoice lines freeze their description in all three locales at issue time because an issued invoice is a legal snapshot that must not re-render from a catalogue that has since changed.

## جدول‌ها

### `currencies`

Declares the minor-unit exponent and rounding behaviour for every money column in the system. Single source of truth for how a BIGINT is interpreted.

| ستون | نوع | توضیح |
|---|---|---|
| `code` | `CHAR(3) PRIMARY KEY` | 'IRT' (Toman), 'EUR', 'USD'. Not auto-increment — the code is the key. |
| `exponent` | `TINYINT UNSIGNED` | Minor-unit digits. IRT=0 (whole Toman), EUR=2 (cents). Every Money value is amount * 10^-exponent. |
| `symbol` | `VARCHAR(8)` | 'تومان', '€' |
| `name_fa` | `VARCHAR(64)` | Customer-facing name. |
| `name_en` | `VARCHAR(64)` |  |
| `name_tr` | `VARCHAR(64)` |  |
| `rounding_step` | `INT UNSIGNED DEFAULT 1` | Minor units to round invoice totals to. IRT=1000 (nearest 1,000 Toman, gateway-friendly); EUR=1. |
| `is_billing` | `BOOLEAN DEFAULT 0` | True for the one currency this install actually bills in. IR install: IRT. DE install: EUR. |
| `active` | `BOOLEAN DEFAULT 1` |  |
| `sort` | `SMALLINT DEFAULT 0` |  |

**ایندکس:** `PRIMARY KEY (code)` · `INDEX (is_billing, active)`

### `exchange_rates`

FX rates for DISPLAY and supplier-cost normalisation only (Hetzner bills EUR, the Iran install sells IRT). Never used to settle or convert an issued invoice.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `base` | `CHAR(3)` | FK currencies.code |
| `quote` | `CHAR(3)` | FK currencies.code |
| `rate` | `DECIMAL(24,12)` | 1 base = rate quote. Decimal, not float; not money so not minor units. |
| `source` | `VARCHAR(32)` | 'nobitex','tgju','ecb','manual' |
| `fetched_at` | `TIMESTAMP` |  |
| `expires_at` | `TIMESTAMP NULL` | Stale rates must not be shown as a price. |

**ایندکس:** `UNIQUE (base, quote, fetched_at)` · `INDEX (base, quote, fetched_at DESC)`

### `gateways`

Data-driven registry of payment methods. Admin adds a new Iranian PSP or crypto processor by inserting a row; only the driver class is code.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `driver` | `VARCHAR(40)` | Maps to a PaymentGateway implementation: 'zarinpal','zibal','btcpay','nowpayments','wallet','offline_wire'. |
| `install` | `CHAR(2)` | 'ir' \| 'de'. Kept even though DBs are separate — enables later consolidated accounting. |
| `name_fa` | `VARCHAR(64)` | Shown at checkout. |
| `name_en` | `VARCHAR(64)` |  |
| `name_tr` | `VARCHAR(64)` |  |
| `description_fa` | `VARCHAR(255) NULL` | e.g. 'پرداخت با کارت‌های شتاب' |
| `description_en` | `VARCHAR(255) NULL` |  |
| `description_tr` | `VARCHAR(255) NULL` |  |
| `type` | `ENUM('redirect','crypto','wallet','offline','manual')` | Drives checkout UI without asking the driver. |
| `currency` | `CHAR(3)` | FK currencies.code — what this gateway settles in. |
| `config` | `JSON` | JSON BY DESIGN: per-driver credentials/endpoints have genuinely different shapes. Laravel 'encrypted:array' cast. Never queried. |
| `fee_bp` | `INT DEFAULT 0` | Surcharge in basis points (250 = 2.5%). |
| `fee_fixed` | `BIGINT DEFAULT 0` | Minor units. |
| `min_amount` | `BIGINT NULL` | Minor units. Iranian gateways reject tiny amounts; crypto has dust limits. |
| `max_amount` | `BIGINT NULL` |  |
| `sandbox` | `BOOLEAN DEFAULT 0` |  |
| `active` | `BOOLEAN DEFAULT 1` |  |
| `sort` | `SMALLINT DEFAULT 0` |  |

**ایندکس:** `UNIQUE (driver, install)` · `INDEX (active, sort)`

### `tax_rates`

Data-driven tax. Iranian VAT (مالیات بر ارزش افزوده) is one row; EU/reverse-charge/export cases are more rows. No tax percentage is ever hardcoded.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `name_fa` | `VARCHAR(64)` | 'مالیات بر ارزش افزوده' — printed on the invoice. |
| `name_en` | `VARCHAR(64)` | 'VAT' |
| `name_tr` | `VARCHAR(64)` | 'KDV' |
| `country` | `CHAR(2) NULL` | NULL = default for this install. Matched against the customer's billing country. |
| `customer_type` | `ENUM('individual','company','any') DEFAULT 'any'` | Company with a valid tax id may be reverse-charge / zero-rated. |
| `rate_bp` | `INT UNSIGNED` | Basis points. 1000 = 10%. Integer — never a float percentage. |
| `applies_to` | `VARCHAR(32) DEFAULT 'all'` | 'all' or a service type ('domain','hosting','vps'). Domain registry fees are treated differently in some jurisdictions. |
| `inclusive` | `BOOLEAN DEFAULT 0` | Whether catalogue prices already contain this tax. Storage is always tax-exclusive; this only affects display. |
| `requires_tax_id` | `BOOLEAN DEFAULT 0` | Rate applies only when the customer supplied a validated VAT/tax id. |
| `priority` | `SMALLINT DEFAULT 0` | First match wins, most specific first. |
| `valid_from` | `DATE` | Rate changes are new rows, never UPDATEs — issued invoices must keep their historical rate. |
| `valid_to` | `DATE NULL` |  |
| `active` | `BOOLEAN DEFAULT 1` |  |

**ایندکس:** `INDEX (install, country, customer_type, valid_from)` · `INDEX (active, priority)`

### `price_quotes`

An immutable, expiring promise that a given item can be sold at a given price by a given supplier. THE fix for the '$20 domain shown at $2 that the registrar then refuses to sell' problem. Nothing enters a cart or an invoice without one.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `uuid` | `CHAR(36)` | Public reference handed to the front-end. |
| `install` | `CHAR(2)` |  |
| `subject_type` | `ENUM('domain_register','domain_transfer','domain_renew','product_plan','addon')` |  |
| `subject_key` | `VARCHAR(255)` | The domain name, or the plan slug. |
| `supplier` | `VARCHAR(40)` | 'openprovider','centralnic','irnic','hetzner','ovh','proxmox_ir'. |
| `supplier_account_id` | `BIGINT UNSIGNED NULL` | Which of several accounts at that supplier — arbitrage across accounts is real. |
| `supplier_currency` | `CHAR(3)` | What the supplier quoted in. |
| `supplier_cost_native` | `DECIMAL(20,6)` | Exactly as returned by the supplier, unconverted. Decimal not float. Evidence. |
| `supplier_cost` | `BIGINT` | Cost converted to our billing currency, minor units, at fx_rate below. |
| `supplier_renewal_cost` | `BIGINT NULL` | CRITICAL: without this, comparing registrars on first-year price alone picks the teaser rate. |
| `fx_rate` | `DECIMAL(24,12) NULL` | Rate used at quote time, frozen. |
| `currency` | `CHAR(3)` | Our sell currency. |
| `sell_amount` | `BIGINT` | First-term price we will honour, minor units. |
| `sell_renewal_amount` | `BIGINT NULL` | Renewal price shown to the customer at purchase and copied into services.recurring_amount. |
| `term_months` | `SMALLINT` | 12, 24 … quotes are per-term, and multi-year total is comparable across suppliers. |
| `class` | `ENUM('standard','premium','special','restricted','unavailable')` | Drives the three UI states. 'premium'/'special' MUST come from the per-domain check response, never from the TLD price list. |
| `availability` | `ENUM('available','taken','reserved','unknown')` | 'unknown' is never sellable. |
| `honour_ref` | `VARCHAR(191) NULL` | Registrar's own quote/session id that must be replayed at purchase for the price to stick. |
| `raw` | `JSON` | JSON BY DESIGN: verbatim supplier response, audit evidence only, never queried or read by business logic. |
| `quoted_at` | `TIMESTAMP` |  |
| `expires_at` | `TIMESTAMP` | Short (5–15 min for domains). Expired quote = re-quote + explicit price-change confirmation. |
| `consumed_at` | `TIMESTAMP NULL` | Set when turned into an order item. A quote is single-use. |
| `consumed_by_order_item_id` | `BIGINT UNSIGNED NULL` |  |

**ایندکس:** `UNIQUE (uuid)` · `INDEX (subject_type, subject_key, expires_at)` · `INDEX (supplier, quoted_at)` · `INDEX (consumed_at)`

### `orders`

Cart AND order — a cart is a draft order. Captures intent, fraud signals and locale before any financial document exists.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `uuid` | `CHAR(36)` | Public reference. |
| `install` | `CHAR(2)` |  |
| `user_id` | `BIGINT UNSIGNED NULL` | FK users. NULL while a guest cart. |
| `session_token` | `CHAR(64) NULL` | Guest cart identity; cleared on login when the cart is claimed. |
| `status` | `ENUM('cart','pending','processing','active','fraud','cancelled') DEFAULT 'cart'` | 'pending' = invoice issued, unpaid. 'processing' = paid, provisioning running. |
| `currency` | `CHAR(3)` |  |
| `subtotal` | `BIGINT DEFAULT 0` | Minor units. |
| `discount_total` | `BIGINT DEFAULT 0` |  |
| `tax_total` | `BIGINT DEFAULT 0` |  |
| `total` | `BIGINT DEFAULT 0` |  |
| `promotion_id` | `BIGINT UNSIGNED NULL` | FK promotions |
| `promo_code` | `VARCHAR(40) NULL` | Snapshot of what was typed. |
| `invoice_id` | `BIGINT UNSIGNED NULL` | FK invoices — set when the order is placed. |
| `locale` | `CHAR(2)` | fa\|en\|tr — which language the customer bought in; drives all subsequent emails. |
| `ip` | `VARCHAR(45)` |  |
| `country` | `CHAR(2) NULL` | Geo-detected. Feeds tax resolution and the 'wrong site' suggestion. |
| `user_agent` | `VARCHAR(255) NULL` |  |
| `fraud_score` | `TINYINT UNSIGNED NULL` | Instant VPS provisioning is a carding magnet. |
| `fraud_notes` | `TEXT NULL` |  |
| `notes` | `TEXT NULL` | Customer note. |
| `placed_at` | `TIMESTAMP NULL` |  |
| `expires_at` | `TIMESTAMP NULL` | Cart TTL; a cart holding domain quotes must die when they do. |
| `created_at` | `TIMESTAMP` |  |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (uuid)` · `INDEX (user_id, status)` · `INDEX (session_token)` · `INDEX (status, expires_at)`

### `order_items`

What was requested, with the honoured quote and the resolved configuration attached.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `order_id` | `BIGINT UNSIGNED` | FK orders ON DELETE CASCADE |
| `item_type` | `ENUM('product','domain_register','domain_transfer','domain_renew','addon','service_renew','service_upgrade','wallet_topup','manual')` |  |
| `product_id` | `BIGINT UNSIGNED NULL` | FK to catalogue area. |
| `plan_id` | `BIGINT UNSIGNED NULL` | FK to catalogue area. |
| `service_id` | `BIGINT UNSIGNED NULL` | FK services — for renew/upgrade of an existing service. |
| `price_quote_id` | `BIGINT UNSIGNED NULL` | FK price_quotes. NOT NULL enforced in application for every supplier-backed item. |
| `domain` | `VARCHAR(255) NULL` |  |
| `billing_cycle` | `VARCHAR(16)` | 'monthly','quarterly','annually','biennially','onetime' |
| `term_months` | `SMALLINT` | 0 for one-time. |
| `qty` | `INT DEFAULT 1` |  |
| `unit_amount` | `BIGINT` | Minor units, tax-exclusive. |
| `setup_amount` | `BIGINT DEFAULT 0` |  |
| `discount_amount` | `BIGINT DEFAULT 0` |  |
| `renewal_unit_amount` | `BIGINT NULL` | Shown at checkout ('renews at X') and copied to services.recurring_amount. Anti-teaser-rate. |
| `tax_rate_id` | `BIGINT UNSIGNED NULL` | FK tax_rates |
| `tax_rate_bp` | `INT DEFAULT 0` | Snapshot. |
| `tax_amount` | `BIGINT DEFAULT 0` |  |
| `total` | `BIGINT` |  |
| `config` | `JSON` | JSON BY DESIGN: chosen options (OS image, datacentre, extra IPs, cPanel package) whose schema is defined by the catalogue area per product. Validated against the catalogue on write. |
| `description_fa` | `VARCHAR(255)` | Resolved at add-to-cart, in all three locales. |
| `description_en` | `VARCHAR(255)` |  |
| `description_tr` | `VARCHAR(255)` |  |

**ایندکس:** `INDEX (order_id)` · `INDEX (service_id)` · `INDEX (price_quote_id)`

### `invoices`

The legal financial document. Immutable once issued (only status/payment totals and dunning fields change). Gapless numbering per install and fiscal year.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` | 'ir' \| 'de' |
| `series` | `VARCHAR(16)` | 'IR-1405', 'DE-2026'. Iranian series rolls over on 1 Farvardin, not 1 January. |
| `number` | `VARCHAR(32) NULL` | NULL while draft. Allocated from invoice_sequences under FOR UPDATE at issue time. Gapless by construction. |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `order_id` | `BIGINT UNSIGNED NULL` | FK orders |
| `billing_snapshot` | `JSON` | JSON BY DESIGN: frozen legal identity at issue time — name, حقیقی/حقوقی, کد ملی or شناسه ملی, شماره ثبت, address, tax id. Must not follow later profile edits. Never joined on. |
| `type` | `ENUM('new','renewal','upgrade','domain','credit_topup','manual')` |  |
| `status` | `ENUM('draft','issued','partially_paid','paid','cancelled','void','refunded')` | NO 'overdue' value — overdue is derived from due_date and balance_due, because it is orthogonal to partially_paid. |
| `currency` | `CHAR(3)` | One currency per invoice. No mixed-currency invoices, ever. |
| `subtotal` | `BIGINT DEFAULT 0` | Minor units. |
| `discount_total` | `BIGINT DEFAULT 0` |  |
| `tax_total` | `BIGINT DEFAULT 0` |  |
| `rounding_amount` | `BIGINT DEFAULT 0` | Residual from rounding the total to currencies.rounding_step. Shown as its own line so the invoice always adds up. |
| `total` | `BIGINT DEFAULT 0` |  |
| `paid_total` | `BIGINT DEFAULT 0` | Sum of payment_allocations of type payment/wallet. Maintained inside the allocation transaction. |
| `credited_total` | `BIGINT DEFAULT 0` | Credit notes / write-offs applied. |
| `balance_due` | `BIGINT GENERATED ALWAYS AS (total - paid_total - credited_total) STORED` | Generated column so it can be indexed and can never drift. |
| `issued_at` | `TIMESTAMP NULL` |  |
| `due_date` | `DATE NULL` |  |
| `payment_deadline_at` | `TIMESTAMP NULL` | Hard expiry for held resources (crypto address, domain quote). |
| `paid_at` | `TIMESTAMP NULL` |  |
| `cancelled_at` | `TIMESTAMP NULL` |  |
| `dunning_stage` | `TINYINT UNSIGNED DEFAULT 0` | Index into dunning_policies.stage already executed. |
| `next_dunning_at` | `TIMESTAMP NULL` | Driven by the cron; NULL once paid/cancelled. |
| `preferred_gateway_id` | `BIGINT UNSIGNED NULL` | FK gateways |
| `fiscal_uid` | `VARCHAR(40) NULL` | شماره منحصر به فرد مالیاتی from سامانه مؤدیان (IR install). |
| `fiscal_status` | `ENUM('na','pending','sent','accepted','rejected') DEFAULT 'na'` | Rejections arrive asynchronously, days later. |
| `fiscal_synced_at` | `TIMESTAMP NULL` |  |
| `fiscal_error` | `TEXT NULL` |  |
| `notes_fa` | `TEXT NULL` | Customer-facing footer text. |
| `notes_en` | `TEXT NULL` |  |
| `notes_tr` | `TEXT NULL` |  |
| `admin_notes` | `TEXT NULL` | Never rendered to the customer. |
| `created_at` | `TIMESTAMP` |  |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (series, number)` · `INDEX (user_id, status)` · `INDEX (status, due_date)` · `INDEX (status, next_dunning_at)` · `INDEX (fiscal_status)` · `INDEX (balance_due)`

### `invoice_items`

Immutable invoice lines with frozen trilingual descriptions. Also the enforcement point that stops duplicate renewal invoices.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `invoice_id` | `BIGINT UNSIGNED` | FK invoices ON DELETE CASCADE (drafts only in practice). |
| `order_item_id` | `BIGINT UNSIGNED NULL` | FK order_items |
| `service_id` | `BIGINT UNSIGNED NULL` | FK services |
| `type` | `ENUM('new','renewal','upgrade_charge','proration_credit','setup','addon','domain','late_fee','rounding','manual')` |  |
| `description_fa` | `VARCHAR(255)` | FROZEN at issue. An issued invoice must never re-render from a catalogue that has since changed. |
| `description_en` | `VARCHAR(255)` |  |
| `description_tr` | `VARCHAR(255)` |  |
| `descriptor` | `JSON` | JSON BY DESIGN: structured echo of the line (product_id, plan, domain, period, qty) for admin tooling and re-rendering in a locale that was empty. Descriptions above remain authoritative. |
| `qty` | `INT DEFAULT 1` |  |
| `unit_amount` | `BIGINT` | Minor units. NEGATIVE for proration_credit lines. |
| `discount_amount` | `BIGINT DEFAULT 0` |  |
| `tax_rate_id` | `BIGINT UNSIGNED NULL` |  |
| `tax_rate_bp` | `INT DEFAULT 0` | Snapshot of the rate, so a later VAT change cannot alter history. |
| `tax_amount` | `BIGINT DEFAULT 0` |  |
| `total` | `BIGINT` | qty*unit_amount - discount_amount + tax_amount |
| `period_start` | `DATE NULL` | Service period this line pays for. |
| `period_end` | `DATE NULL` |  |
| `renewal_key` | `VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN type='renewal' THEN CONCAT(service_id,':',period_start) END) STORED` | MySQL has no partial indexes; this generated column + UNIQUE gives one (NULLs repeat freely). Makes a double cron run physically unable to bill twice. |
| `sort` | `SMALLINT DEFAULT 0` |  |

**ایندکس:** `INDEX (invoice_id, sort)` · `UNIQUE (renewal_key)` · `INDEX (service_id, type)`

### `invoice_sequences`

Gapless counters. One row per (install, series, document kind). Locked with SELECT ... FOR UPDATE inside the invoice-insert transaction.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `kind` | `ENUM('invoice','credit_note','receipt')` | Credit notes need their own unbroken run. |
| `series` | `VARCHAR(16)` | 'IR-1405' |
| `prefix` | `VARCHAR(12)` | Rendered before the number, e.g. 'SN-'. |
| `padding` | `TINYINT DEFAULT 6` | Zero-pad width. |
| `next_value` | `BIGINT UNSIGNED DEFAULT 1` | Incremented under row lock. Never reset except at fiscal-year rollover, which creates a NEW series row. |
| `fiscal_year_start` | `DATE NULL` | For the IR install this is 1 Farvardin, not 1 January. |
| `fiscal_year_end` | `DATE NULL` |  |
| `active` | `BOOLEAN DEFAULT 1` | Exactly one active row per (install, kind). |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (install, kind, series)` · `INDEX (install, kind, active)`

### `services`

The subscription record — one row per thing the customer owns, whatever its type (shared hosting, VPS, domain, SSL, storage, email). Carries its own renewal date and status machine.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `order_id` | `BIGINT UNSIGNED NULL` | FK orders — the order that created it. |
| `product_id` | `BIGINT UNSIGNED NULL` | FK catalogue. |
| `plan_id` | `BIGINT UNSIGNED NULL` | FK catalogue. |
| `type` | `VARCHAR(32)` | 'shared_hosting','vps','dedicated','domain','ssl','storage','email','managed'. Type-specific detail lives in the owning area's table keyed by service_id — not here. |
| `label` | `VARCHAR(191)` | Primary identifier shown to the customer: domain, hostname, or IP. |
| `status` | `ENUM('pending','active','suspended','terminated','cancelled','fraud')` | pending→active→suspended→terminated. cancelled = ended by the customer. Terminated/cancelled are final. |
| `provision_status` | `ENUM('none','queued','running','ok','failed') DEFAULT 'none'` | Deliberately separate from status: 'active but provisioning failed' is a real, alertable state. |
| `provider` | `VARCHAR(40) NULL` | 'proxmox_ir','hetzner','ovh','cpanel_de1','openprovider'. |
| `provider_account_id` | `BIGINT UNSIGNED NULL` |  |
| `remote_id` | `VARCHAR(191) NULL` | VM id, cPanel username, registrar handle. Reconciliation key against the provider. |
| `currency` | `CHAR(3)` | Frozen at first invoice. |
| `recurring_amount` | `BIGINT` | Minor units, tax-exclusive. Seeded from price_quotes.sell_renewal_amount, NOT from the first-term price. |
| `setup_amount` | `BIGINT DEFAULT 0` |  |
| `billing_cycle` | `VARCHAR(16)` |  |
| `term_months` | `SMALLINT` |  |
| `registration_date` | `DATE` |  |
| `first_payment_date` | `DATE NULL` |  |
| `next_due_date` | `DATE NULL` | When the next period starts. Advanced only when the renewal invoice is PAID. |
| `next_invoice_date` | `DATE NULL` | next_due_date minus lead days. Domains need 45–60 days lead; hosting 7–14. The cron scans this column. |
| `invoice_lead_days` | `SMALLINT DEFAULT 10` | Per-service override. |
| `auto_renew` | `BOOLEAN DEFAULT 1` |  |
| `auto_suspend` | `BOOLEAN DEFAULT 1` |  |
| `auto_terminate` | `BOOLEAN DEFAULT 1` | MUST default to 0 for type='domain'. |
| `suspended_at` | `TIMESTAMP NULL` |  |
| `suspend_reason` | `VARCHAR(191) NULL` | 'overdue','abuse','manual' |
| `terminated_at` | `TIMESTAMP NULL` |  |
| `cancellation_requested_at` | `TIMESTAMP NULL` |  |
| `cancel_at` | `ENUM('end_of_term','immediate') NULL` |  |
| `promotion_id` | `BIGINT UNSIGNED NULL` | Recurring discount that survives renewal. |
| `promo_locked_until` | `DATE NULL` | Stops upgrade/downgrade being used to re-trigger a first-term promo price. |
| `config` | `JSON` | JSON BY DESIGN: resolved options snapshot (same shape as order_items.config), so a catalogue edit cannot silently change what the customer owns. |
| `created_at` | `TIMESTAMP` |  |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `INDEX (user_id, status)` · `INDEX (status, next_invoice_date)` · `INDEX (status, next_due_date)` · `INDEX (provider, remote_id)` · `INDEX (type, status)`

### `service_changes`

Upgrade/downgrade/cycle-change record with the full proration calculation preserved for audit and dispute.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `service_id` | `BIGINT UNSIGNED` | FK services |
| `invoice_id` | `BIGINT UNSIGNED NULL` | FK invoices — the upgrade invoice, if net amount was positive. |
| `kind` | `ENUM('upgrade','downgrade','cycle_change','addon_add','addon_remove','price_override')` |  |
| `requested_by` | `ENUM('customer','admin','system')` |  |
| `from_plan_id` | `BIGINT UNSIGNED NULL` |  |
| `to_plan_id` | `BIGINT UNSIGNED NULL` |  |
| `from_amount` | `BIGINT` | Old recurring, minor units. |
| `to_amount` | `BIGINT` | New recurring, minor units. |
| `period_start` | `DATE` | Current paid period. |
| `period_end` | `DATE` |  |
| `days_total` | `SMALLINT` | Days in the current period. |
| `days_remaining` | `SMALLINT` | Basis of the daily proration. |
| `credit_amount` | `BIGINT` | Unused portion of the old plan, tax-exclusive. |
| `charge_amount` | `BIGINT` | Pro-rated new plan for the remaining days, tax-exclusive. |
| `net_amount` | `BIGINT` | charge - credit. Negative goes to the wallet, never to a gateway refund. |
| `status` | `ENUM('pending','awaiting_payment','applied','failed','cancelled')` | Upgrade applies only after payment; downgrade applies immediately. |
| `effective_at` | `TIMESTAMP` |  |
| `applied_at` | `TIMESTAMP NULL` |  |
| `calc` | `JSON` | JSON BY DESIGN: full inputs/intermediates of the proration for dispute resolution. Read by humans, not by code. |
| `error` | `TEXT NULL` |  |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `INDEX (service_id, status)` · `INDEX (invoice_id)`

### `payments`

Every movement of money through a gateway, IN and OUT. Refunds are rows with direction='out' and parent_payment_id set — not a separate table.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `uuid` | `CHAR(36)` | Public reference; used as the gateway callback key. |
| `install` | `CHAR(2)` |  |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `direction` | `ENUM('in','out') DEFAULT 'in'` | 'out' = refund/payout. |
| `parent_payment_id` | `BIGINT UNSIGNED NULL` | FK payments — refund points at the original capture. |
| `gateway_id` | `BIGINT UNSIGNED` | FK gateways |
| `driver_snapshot` | `VARCHAR(40)` | Driver key at the time; survives the gateway row being edited. |
| `gateway_reference` | `VARCHAR(191) NULL` | Authority / RefID / txid. Uniqueness here makes replayed callbacks a no-op. |
| `currency` | `CHAR(3)` | OUR billing currency. |
| `amount` | `BIGINT` | Minor units in OUR currency. Always positive; direction carries the sign. |
| `fee_amount` | `BIGINT DEFAULT 0` | Gateway fee/surcharge. |
| `gateway_currency` | `CHAR(3) NULL` | What we actually sent to the PSP. |
| `gateway_amount` | `BIGINT NULL` | e.g. we bill 1,490,000 IRT and send 14,900,000 IRR to Zarinpal. The ×10 lives ONLY at the driver boundary; this column records it for reconciliation. |
| `status` | `ENUM('created','pending','succeeded','failed','expired','cancelled','disputed')` |  |
| `failure_code` | `VARCHAR(64) NULL` |  |
| `failure_message` | `VARCHAR(255) NULL` |  |
| `paid_at` | `TIMESTAMP NULL` | When the PSP confirmed. |
| `settled_at` | `TIMESTAMP NULL` | When the money actually landed in the bank (Shaparak T+1..T+3). |
| `settlement_ref` | `VARCHAR(191) NULL` | For bank-statement reconciliation. |
| `ip` | `VARCHAR(45) NULL` |  |
| `meta` | `JSON` | JSON BY DESIGN: verbatim gateway request/response snapshot. Evidence only. |
| `created_at` | `TIMESTAMP` |  |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (uuid)` · `UNIQUE (gateway_id, gateway_reference)` · `INDEX (user_id, status)` · `INDEX (status, created_at)` · `INDEX (settled_at)`

### `payment_allocations`

The join between money and documents. Makes partial payment, split payment (wallet + gateway), overpayment and credit-note application all one mechanism instead of four.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `type` | `ENUM('payment','wallet','credit_note','write_off','refund')` | Determines which source column is populated. |
| `invoice_id` | `BIGINT UNSIGNED NULL` | FK invoices — the document being settled. |
| `payment_id` | `BIGINT UNSIGNED NULL` | FK payments |
| `wallet_entry_id` | `BIGINT UNSIGNED NULL` | FK wallet_entries |
| `credit_note_id` | `BIGINT UNSIGNED NULL` | FK credit_notes |
| `amount` | `BIGINT` | Minor units, always positive; type gives the direction. |
| `currency` | `CHAR(3)` | Must equal the invoice currency. Enforced by CHECK/application. |
| `created_by` | `BIGINT UNSIGNED NULL` | Admin user for manual allocations. |
| `created_at` | `TIMESTAMP` | Append-only. Reversal is a new row, never a DELETE. |

**ایندکس:** `INDEX (invoice_id)` · `INDEX (payment_id)` · `INDEX (wallet_entry_id)` · `CHECK: exactly one of payment_id / wallet_entry_id / credit_note_id is non-null`

### `wallets`

Credit balance, one per (customer, currency). The cached balance is derived from the ledger and rebuildable.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `currency` | `CHAR(3)` | In practice one per install, but modelled properly. |
| `balance` | `BIGINT DEFAULT 0` | Minor units. Cache of SUM(ledger). Updated only inside the same transaction as the entry, under row lock. |
| `locked_amount` | `BIGINT DEFAULT 0` | Reserved against an in-flight checkout. |
| `last_seq` | `BIGINT UNSIGNED DEFAULT 0` | Last wallet_entries.seq — the lock/allocation point. |
| `created_at` | `TIMESTAMP` |  |
| `updated_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (user_id, currency)`

### `wallet_entries`

Append-only credit ledger. Never UPDATEd, never DELETEd; a mistake is corrected by an opposing entry. This is where crypto over/underpayment, proration credits and refunds-to-credit all land.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `wallet_id` | `BIGINT UNSIGNED` | FK wallets |
| `seq` | `BIGINT UNSIGNED` | Per-wallet monotonic counter. UNIQUE with wallet_id — a gap or duplicate means tampering or a bug. |
| `direction` | `ENUM('credit','debit')` |  |
| `amount` | `BIGINT` | Minor units, always positive. |
| `balance_after` | `BIGINT` | Running balance. Lets the whole ledger be verified in one pass. |
| `reason` | `ENUM('topup','refund_to_credit','proration_credit','crypto_overpayment','crypto_underpayment','crypto_late','invoice_payment','manual_adjust','promo','chargeback','withdrawal')` |  |
| `invoice_id` | `BIGINT UNSIGNED NULL` |  |
| `payment_id` | `BIGINT UNSIGNED NULL` |  |
| `credit_note_id` | `BIGINT UNSIGNED NULL` |  |
| `service_change_id` | `BIGINT UNSIGNED NULL` |  |
| `description_fa` | `VARCHAR(191) NULL` | Shown in the customer's wallet history. |
| `description_en` | `VARCHAR(191) NULL` |  |
| `description_tr` | `VARCHAR(191) NULL` |  |
| `created_by` | `BIGINT UNSIGNED NULL` | Admin id for manual adjustments. |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (wallet_id, seq)` · `INDEX (wallet_id, created_at)` · `INDEX (reason)`

### `credit_notes`

Reversal document with its own gapless number. Used instead of negative invoices, which break sequential accounting and Iranian tax reporting.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `series` | `VARCHAR(16)` |  |
| `number` | `VARCHAR(32) NULL` | NULL while draft; allocated from invoice_sequences kind='credit_note'. |
| `invoice_id` | `BIGINT UNSIGNED` | FK invoices — a credit note always references an invoice. |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `currency` | `CHAR(3)` |  |
| `subtotal` | `BIGINT` | Minor units, positive; the document type carries the sign. |
| `tax_total` | `BIGINT` | Tax reversed at the ORIGINAL invoice's rate, not today's. |
| `total` | `BIGINT` |  |
| `reason` | `ENUM('refund','downgrade','service_failure','billing_error','goodwill','cancellation','duplicate_payment')` |  |
| `reason_note` | `TEXT NULL` |  |
| `outcome` | `ENUM('wallet','gateway_refund','write_off')` | 'wallet' is the default; gateway_refund additionally creates a payments row with direction='out'. |
| `status` | `ENUM('draft','issued','applied')` |  |
| `fiscal_uid` | `VARCHAR(40) NULL` | Credit notes also go to سامانه مؤدیان. |
| `fiscal_status` | `ENUM('na','pending','sent','accepted','rejected') DEFAULT 'na'` |  |
| `issued_at` | `TIMESTAMP NULL` |  |
| `created_by` | `BIGINT UNSIGNED NULL` | Refunds are always attributable to a person. |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (series, number)` · `INDEX (invoice_id)` · `INDEX (user_id, status)`

### `credit_note_items`

Which invoice lines are being reversed, and by how much. Enables partial credit of a single line.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `credit_note_id` | `BIGINT UNSIGNED` | FK credit_notes ON DELETE CASCADE |
| `invoice_item_id` | `BIGINT UNSIGNED NULL` | FK invoice_items — real FK, not a JSON reference. |
| `description_fa` | `VARCHAR(255)` |  |
| `description_en` | `VARCHAR(255)` |  |
| `description_tr` | `VARCHAR(255)` |  |
| `qty` | `INT DEFAULT 1` |  |
| `unit_amount` | `BIGINT` | Minor units. |
| `tax_rate_bp` | `INT DEFAULT 0` | Copied from the original line. |
| `tax_amount` | `BIGINT DEFAULT 0` |  |
| `total` | `BIGINT` |  |

**ایندکس:** `INDEX (credit_note_id)` · `INDEX (invoice_item_id)`

### `crypto_assets`

Data-driven crypto configuration. Admin enables USDT-TRC20 or BTC by inserting a row — confirmations, decimals and tolerances are data, not constants in code.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `gateway_id` | `BIGINT UNSIGNED` | FK gateways (type='crypto') |
| `asset` | `VARCHAR(16)` | 'BTC','USDT','ETH','TRX' |
| `network` | `VARCHAR(24)` | 'bitcoin','tron','erc20','bep20'. Asset alone is NOT enough — same ticker, different chain, lost funds. |
| `decimals` | `TINYINT UNSIGNED` | 8 for BTC, 6 for USDT-TRC20, 18 for ETH. |
| `confirmations_required` | `SMALLINT UNSIGNED` | N confirmations before the payment counts. 2 for BTC, 20 for TRON, etc. |
| `min_amount` | `DECIMAL(38,18)` | Dust threshold — crypto amount, NOT money, so decimal not BIGINT. |
| `name_fa` | `VARCHAR(64)` | Shown at checkout. |
| `name_en` | `VARCHAR(64)` |  |
| `name_tr` | `VARCHAR(64)` |  |
| `rate_source` | `VARCHAR(32)` | 'binance','coingecko','gateway' |
| `rate_ttl_seconds` | `INT DEFAULT 900` | How long a quoted rate is honoured. 15 min is realistic for BTC; USDT can be much longer. |
| `underpay_tolerance_bp` | `INT DEFAULT 100` | 1% short still counts as paid (network fee deducted by some senders). Anything more becomes wallet credit. |
| `overpay_tolerance_bp` | `INT DEFAULT 0` | Any excess becomes wallet credit — never an automatic refund. |
| `explorer_tx_url` | `VARCHAR(255) NULL` | Template with {txid}; shown to the customer. |
| `active` | `BOOLEAN DEFAULT 1` |  |

**ایندکس:** `UNIQUE (gateway_id, asset, network)` · `INDEX (active)`

### `crypto_payment_intents`

One attempt to pay one invoice in crypto: the locked rate with its TTL, the expected on-chain amount, the address, and the over/under/late resolution.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `payment_id` | `BIGINT UNSIGNED` | FK payments — the money-side record. |
| `invoice_id` | `BIGINT UNSIGNED` | FK invoices |
| `crypto_asset_id` | `BIGINT UNSIGNED` | FK crypto_assets |
| `address` | `VARCHAR(128)` | Receiving address. |
| `address_index` | `INT UNSIGNED NULL` | HD derivation index when we generate addresses ourselves. |
| `memo_tag` | `VARCHAR(64) NULL` | Required by some chains; missing memo = lost funds. |
| `rate_minor_per_unit` | `DECIMAL(28,12)` | Invoice-currency MINOR units per 1 whole crypto unit. Frozen at quote. |
| `rate_source` | `VARCHAR(32)` |  |
| `rate_locked_at` | `TIMESTAMP` |  |
| `rate_expires_at` | `TIMESTAMP` | After this the quote is void; funds arriving later are revalued at spot. |
| `amount_due_minor` | `BIGINT` | Fiat side, minor units — what the invoice needs. |
| `amount_expected` | `DECIMAL(38,18)` | Crypto side. DECIMAL(38,18) because crypto is not money in our accounting sense and must not share the BIGINT scheme. |
| `amount_received` | `DECIMAL(38,18) DEFAULT 0` | Sum of confirmed crypto_transactions. |
| `confirmations_seen` | `SMALLINT UNSIGNED DEFAULT 0` | Minimum across contributing transactions. |
| `status` | `ENUM('awaiting','detected','confirming','confirmed','underpaid','overpaid','expired','abandoned')` | 'confirmed' only at >= confirmations_required AND amount within tolerance. |
| `settled_rate` | `DECIMAL(28,12) NULL` | Rate actually used to credit — the locked rate if inside TTL, otherwise spot at the confirming block. |
| `settled_minor` | `BIGINT NULL` | Fiat minor units actually credited. This, not amount_due_minor, is what hits the wallet/allocation. |
| `first_seen_at` | `TIMESTAMP NULL` |  |
| `confirmed_at` | `TIMESTAMP NULL` |  |
| `expires_at` | `TIMESTAMP` | Address is released for reuse only long after this. |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (crypto_asset_id, address, payment_id)` · `INDEX (status, expires_at)` · `INDEX (invoice_id)` · `INDEX (address)`

### `crypto_transactions`

Individual on-chain transactions credited to an intent. A customer may send in three chunks; each needs its own confirmation count.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `intent_id` | `BIGINT UNSIGNED` | FK crypto_payment_intents |
| `txid` | `VARCHAR(128)` |  |
| `vout` | `INT NULL` | UTXO index; NULL on account-model chains. |
| `amount` | `DECIMAL(38,18)` | Crypto units. |
| `confirmations` | `SMALLINT UNSIGNED DEFAULT 0` | Updated by the poller. |
| `block_height` | `BIGINT UNSIGNED NULL` |  |
| `status` | `ENUM('seen','confirming','confirmed','orphaned')` | 'orphaned' handles reorgs — it must be possible to un-credit a payment. |
| `first_seen_at` | `TIMESTAMP` |  |
| `confirmed_at` | `TIMESTAMP NULL` |  |
| `raw` | `JSON` | JSON BY DESIGN: node/explorer payload, evidence only. |

**ایندکس:** `UNIQUE (intent_id, txid, vout)` · `INDEX (status, confirmations)`

### `dunning_policies`

The overdue ladder as data. Changing the grace period from 3 to 7 days is a row edit, not a deploy. Different rules per service type so a domain is never suspended or auto-terminated.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `name` | `VARCHAR(64)` | Admin label. |
| `applies_to` | `VARCHAR(32) NULL` | services.type, or NULL for all. A row with applies_to='domain' and action='terminate' should never exist. |
| `stage` | `TINYINT UNSIGNED` | Execution order; matches invoices.dunning_stage. |
| `offset_days` | `SMALLINT` | Relative to invoices.due_date. Negative = before due (the 'renewal is coming' notice). |
| `action` | `ENUM('notify','late_fee','suspend','terminate','cancel_service')` | Each maps to a DunningAction implementation. |
| `notify_template` | `VARCHAR(64) NULL` | Mail template key; the template itself resolves fa/en/tr from the customer's locale. |
| `late_fee_bp` | `INT NULL` | Percentage late fee in basis points. |
| `late_fee_fixed` | `BIGINT NULL` | Minor units. |
| `active` | `BOOLEAN DEFAULT 1` |  |

**ایندکس:** `UNIQUE (install, applies_to, stage)` · `INDEX (active, offset_days)`

### `promotions`

Coupons and automatic discounts, including recurring ones that must survive renewal.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `code` | `VARCHAR(40) NULL` | NULL = applied automatically. |
| `name_fa` | `VARCHAR(96)` | Shown on the invoice discount line. |
| `name_en` | `VARCHAR(96)` |  |
| `name_tr` | `VARCHAR(96)` |  |
| `type` | `ENUM('percentage','fixed','free_setup','free_term')` |  |
| `value_bp` | `INT NULL` | For percentage. |
| `value_amount` | `BIGINT NULL` | For fixed; minor units. |
| `currency` | `CHAR(3) NULL` | Required for fixed. |
| `applies_to` | `ENUM('all','product','service_type','tld')` |  |
| `applies_ref` | `VARCHAR(64) NULL` | Product id, service type or TLD. |
| `cycles` | `ENUM('first','all','n') DEFAULT 'first'` | 'first' = first term only — and services.recurring_amount still holds the FULL price, so the renewal never surprises. |
| `cycles_n` | `TINYINT NULL` |  |
| `min_total` | `BIGINT NULL` |  |
| `max_uses` | `INT NULL` |  |
| `max_uses_per_user` | `INT NULL` |  |
| `used_count` | `INT DEFAULT 0` | Incremented under row lock; the authoritative count is promotion_redemptions. |
| `new_customers_only` | `BOOLEAN DEFAULT 0` |  |
| `starts_at` | `TIMESTAMP NULL` |  |
| `ends_at` | `TIMESTAMP NULL` |  |
| `active` | `BOOLEAN DEFAULT 1` |  |

**ایندکس:** `UNIQUE (install, code)` · `INDEX (active, starts_at, ends_at)`

### `promotion_redemptions`

Per-use record enforcing usage caps atomically.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `promotion_id` | `BIGINT UNSIGNED` | FK promotions |
| `user_id` | `BIGINT UNSIGNED` | FK users |
| `order_id` | `BIGINT UNSIGNED NULL` | FK orders |
| `invoice_id` | `BIGINT UNSIGNED NULL` | FK invoices |
| `service_id` | `BIGINT UNSIGNED NULL` | FK services — recurring discounts. |
| `amount` | `BIGINT` | Discount actually granted, minor units. |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (promotion_id, order_id)` · `INDEX (promotion_id, user_id)`

### `billing_events`

Append-only audit trail of every state change involving money or service status. The first thing you read when a customer says 'I paid and nothing happened'.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `install` | `CHAR(2)` |  |
| `event` | `VARCHAR(64)` | 'invoice.issued','payment.captured','crypto.underpaid','service.suspended','fiscal.rejected'. |
| `subject_type` | `VARCHAR(32)` | 'invoice','payment','service','order'. |
| `subject_id` | `BIGINT UNSIGNED` | Deliberately not a polymorphic FK — the log must survive the subject. |
| `user_id` | `BIGINT UNSIGNED NULL` |  |
| `actor_type` | `ENUM('customer','admin','system','gateway')` |  |
| `actor_id` | `BIGINT UNSIGNED NULL` |  |
| `amount` | `BIGINT NULL` | Minor units, when the event moves money. |
| `currency` | `CHAR(3) NULL` |  |
| `payload` | `JSON` | JSON BY DESIGN: heterogeneous per event type, read by humans and admin tooling only. |
| `ip` | `VARCHAR(45) NULL` |  |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `INDEX (subject_type, subject_id, created_at)` · `INDEX (event, created_at)` · `INDEX (user_id, created_at)`

### `idempotency_keys`

Stops double-charging: duplicate gateway callbacks, double-clicked checkout, and an overlapping renewal cron.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED PK` |  |
| `scope` | `VARCHAR(40)` | 'gateway_callback','checkout','cron_renewal','cron_dunning'. |
| `idem_key` | `VARCHAR(191)` | Client key, gateway reference, or 'renewal:{service_id}:{period_start}'. |
| `request_hash` | `CHAR(64)` | SHA-256 of the payload; a same-key/different-body request is an error, not a replay. |
| `status` | `ENUM('in_progress','done','failed')` |  |
| `response` | `JSON NULL` | JSON BY DESIGN: cached response replayed to duplicate requests. |
| `locked_at` | `TIMESTAMP NULL` |  |
| `expires_at` | `TIMESTAMP` | Pruned by cron; 30 days for gateway callbacks. |
| `created_at` | `TIMESTAMP` |  |

**ایندکس:** `UNIQUE (scope, idem_key)` · `INDEX (expires_at)`

## تصمیم‌های کلیدی

**Money is BIGINT minor units + a currency code, with the exponent declared per currency in the `currencies` table (IRT exponent 0 = whole Toman, EUR exponent 2 = cents). All arithmetic is integer, via a `Money` value object and a `MoneyCast`.**

Floats and even DECIMAL invite implicit casts through PHP floats somewhere in the stack. Integers cannot drift. Per-currency exponent avoids storing Toman with two fake decimal places that would only accumulate rounding noise, while keeping EUR exact to the cent. BIGINT holds ~9.2e18, far beyond any Toman invoice.

*رد شد:* DECIMAL(15,2) as used in the old `...\ServerNet\app\database\migrations` schema (PHP reads it back as a string that everyone eventually casts to float; and 2 decimals is meaningless for Toman). Also rejected a single global scale for all currencies, and rejected storing IRR (rial) — customers, gateways' display, and the owner all think in Toman, so store Toman and multiply by 10 only inside the gateway driver.

**Crypto on-chain amounts use DECIMAL(38,18) and live only in `crypto_assets`, `crypto_payment_intents`, `crypto_transactions`. They never appear in an invoice, payment, or wallet amount column.**

BTC needs 8 decimals, ETH 18; forcing them into the fiat minor-unit scheme would either lose precision or corrupt the meaning of every money column. The invoice is always denominated in EUR/IRT; crypto is a delivery mechanism whose conversion result (`settled_minor`) is what enters the accounting system.

*رد شد:* A shared 'amount' column with a scale big enough for both — it makes every fiat query and index carry 18 useless decimals and blurs the accounting boundary.

**The cart IS a draft order. No `carts` table; `orders.status = 'cart'` with a nullable `user_id` and a `session_token` for guests.**

Cart and order carry identical data (items, config, quotes, totals, promo). Two tables means duplicated item schema and a lossy copy step at checkout, which is exactly where price-drift bugs are born. One table means the quote a customer saw is literally the row that gets invoiced.

*رد شد:* Separate `carts` + `cart_items` (the WHMCS shape). Cost: a second item table and a copy operation that must be kept in sync forever.

**Nothing is priced from a TLD/product price list at add-to-cart or invoice time. A `price_quotes` row is created by querying the supplier, and it carries the supplier's `honour_ref`, its `class` (standard/premium/special/restricted/unavailable), a first-term price AND a renewal price, and a short `expires_at`. Order items reference the quote; an expired or already-consumed quote forces a re-quote and an explicit confirmation of the new price before charge.**

This is the direct fix for the '$20 domain shown at $2 that the registrar then refuses to sell'. Per-domain premium pricing exists only in the domain-check response, so the check response must be the pricing source of record and must be persisted as evidence. And storing `sell_renewal_amount` next to `sell_amount` is what stops the multi-registrar comparison from picking the cheap-first-year/expensive-renewal trap: comparison ranks on total cost over the intended term, not year one.

*رد شد:* Pricing from a cached TLD price table with a 'premium' flag patched on later — that reintroduces exactly the bug, because the TLD list has no per-domain price. Also rejected showing a price with a disclaimer that it may change at checkout: the owner's whole complaint is that this destroys trust.

**Invoice numbers are allocated at ISSUE time from a locked `invoice_sequences` row (`SELECT ... FOR UPDATE`) inside the same DB transaction as the invoice insert. Drafts carry `number = NULL`. Credit notes have their own separate sequence. The Iranian series rolls over on 1 Farvardin.**

Gapless is a hard accounting requirement and only a transactional counter can give it: if the transaction rolls back, the counter rolls back with it. Allocating at issue rather than at create means abandoned drafts burn no numbers.

*رد شد:* MySQL AUTO_INCREMENT (leaves gaps on any rollback or InnoDB restart, and is not resettable per fiscal year), and Redis INCR (fast but not transactional with the DB — a crash between INCR and COMMIT permanently loses a number).

**`overdue` is NOT an invoice status. Status is draft/issued/partially_paid/paid/cancelled/void/refunded; overdue is derived from `due_date < today AND balance_due > 0`, and the dunning ladder position is a separate `dunning_stage` integer.**

Overdue is orthogonal to how much has been paid — a partially-paid invoice can also be overdue, and a single enum forces a choice that loses information. Derivation cannot go stale.

*رد شد:* The WHMCS-style `Overdue` status (present in the old schema), which makes 'partially paid and overdue' unrepresentable.

**All money-to-document linkage goes through `payment_allocations`. Refunds are `payments` rows with `direction='out'` and `parent_payment_id`, not a separate refunds table.**

Partial payment, split payment (wallet + gateway), overpayment, credit-note application and write-off then become one mechanism with one set of invariants, instead of five special cases each with its own bug. A refund is a movement of money through a gateway — structurally identical to a capture with the sign flipped.

*رد شد:* `invoices.payment_id` (the old schema's shape — it makes partial and split payment impossible) and a dedicated `refunds` table (duplicates every gateway field).

**Every crypto over-, under-, and late payment resolves through the customer wallet: funds received are always credited at `settled_rate`, then allocated to the invoice. An invoice is never marked paid for less than it owes, and crypto is never automatically refunded.**

One rule covers every awkward case. Underpaid by 30%: credit 70%, invoice stays unpaid, customer tops up. Overpaid: excess sits as credit. Paid 40 minutes after the rate TTL expired: revalue at spot at the confirming block and credit that. The alternative is per-case logic on the invoice, which is where money gets lost. Auto-refunding crypto is an AML and theft risk — exchange-originated deposits often have no valid return address.

*رد شد:* Bespoke over/underpayment fields on the invoice, and automatic crypto refunds on overpayment.

**Proration is daily, on the current paid period, tax-exclusive: credit = old_recurring × days_remaining / days_total, charge = new_recurring × days_remaining / days_total, tax recomputed on the net. `next_due_date` is NOT reset by an upgrade. If net is negative (a downgrade), it becomes wallet credit, never a gateway refund.**

Keeping the cycle fixed means a customer cannot repeatedly upgrade/downgrade to shift their billing date or re-trigger a first-term promo price. Wallet credit for downgrades removes an entire fraud and chargeback surface, and it is what the customer usually wants anyway since they are staying.

*رد شد:* Resetting the billing cycle and charging full price on upgrade (confusing and gameable), and cash-refunding downgrade credit (turns a support ticket into a payout, and Iranian gateways make outbound refunds slow and manual).

**Duplicate renewal invoices are prevented by the database, not by application logic: a stored generated column `invoice_items.renewal_key = CONCAT(service_id,':',period_start)` for `type='renewal'` with a UNIQUE index, plus an `idempotency_keys` row per cron renewal.**

Two overlapping cron runs on a flaky cPanel scheduler is not hypothetical, and double-billing a customer is the single most damaging billing bug. A unique index makes it physically impossible rather than merely unlikely. MySQL lacks partial indexes, so the generated column (NULL for non-renewal rows, and NULLs repeat freely in a UNIQUE index) is the idiomatic equivalent.

*رد شد:* An application-level `where not exists` check — it races, and it is the check every billing system has and every billing system eventually loses.

**Tax is stored per invoice line as `tax_rate_id` + `tax_rate_bp` + `tax_amount` snapshots, with the rules in `tax_rates`. No separate `invoice_taxes` table. Prices are stored tax-exclusive everywhere; inclusive display is a rendering mode.**

Iranian VAT is effectively one national rate, so a per-line basis-points snapshot is sufficient and keeps the invoice self-contained forever — a VAT rate change next year cannot alter last year's documents. Basis points are integers, so no percentage float ever exists.

*رد شد:* A separate tax-lines table (needed only for multi-jurisdiction compound taxes we do not have), and storing tax-inclusive prices (makes proration, discounts and reverse-charge all require division, which is where rounding errors appear).

**Credit notes are their own document type with their own gapless sequence, rather than negative invoices.**

Sequential invoice numbering with negative amounts mixed in breaks accounting exports and Iranian tax reporting, and makes 'total invoiced revenue' a query nobody can write correctly. A credit note also naturally carries a `reason` and a `created_by`, which a refund must have.

*رد شد:* Negative invoices / negative line items on the original invoice (which would also mean mutating an issued legal document).

**The wallet is an append-only ledger (`wallet_entries` with per-wallet `seq` and `balance_after`) plus a cached `wallets.balance` updated only inside the same transaction under a row lock.**

A mutable balance column alone has no audit trail and silently absorbs bugs. `balance_after` on every entry means the whole ledger can be verified in one pass and any divergence is located exactly. The cache exists so checkout does not SUM the ledger.

*رد شد:* Balance-only (the old `wallet_transactions` shape, which has a status column on a ledger — a ledger entry is a fact, it cannot be 'pending').

**One `services` table for every product type. Type-specific detail (registrar and auth code for domains, VM specs and IPs for VPS, cPanel package for hosting) lives in the owning area's table keyed by `service_id`.**

Billing, renewal, dunning, suspension and proration are identical for all of them, and they must be listed, invoiced and aged together. Splitting by type would duplicate the entire lifecycle machinery per product family — precisely the 'admin must add products without code changes' requirement being violated.

*رد شد:* Per-type subscription tables, and a single table with a giant type-specific JSON blob (config JSON here holds only the customer's chosen options, validated against the catalogue — not the relational facts).

**Gateways are rows in `gateways` (driver key + encrypted config JSON) implementing one `PaymentGateway` contract with opt-in capability interfaces. Currency conversion for Iranian PSPs (Toman → Rial, ×10) happens only inside the driver, and the converted figure is recorded in `payments.gateway_amount`.**

Adding a new Iranian PSP or crypto processor becomes one class plus one row; checkout, reconciliation and refund code do not change. Keeping the ×10 at the boundary means exactly one place can get it wrong, and the recorded `gateway_amount` makes a mismatch detectable in reconciliation rather than in a customer complaint.

*رد شد:* An enum of payment methods in the payments table (the old schema's `enum('paypal','visa','zarinpal',...)`) — every new gateway becomes a migration and a switch statement in five places.

**The dunning ladder is rows in `dunning_policies` (offset days, action, per-service-type scoping), and the actions are `DunningAction` implementations resolved by key.**

Grace periods are a commercial decision the owner will change repeatedly, and they differ by product. Domains especially must never be suspended or auto-terminated — registry redemption fees are punitive — so scoping is a schema-level concern, not a code comment.

*رد شد:* Hardcoded day counts in a scheduled command, and a single global grace period for all products.

**An `install` CHAR(2) column is kept on invoices, payments, services, orders and events even though the two databases are fully independent.**

Costs two bytes and makes a future consolidated accounting export, or a merged reporting warehouse, a UNION instead of a migration project. Also prevents an accidental cross-install data restore from becoming silently ambiguous.

*رد شد:* Omitting it on the grounds that the DB identity is implicit.

**Invoice line descriptions are frozen at issue time into `description_fa/_en/_tr` columns rather than a sibling `invoice_item_translations` table.**

`invoice_items` will be the largest table in the system; a translations table triples its row count and adds a join to every invoice render. Lines are immutable once issued, so there is no update anomaly for a normalised table to protect against. Three columns is the right shape when the locale set is fixed at exactly three.

*رد شد:* The `post_translations` pattern used elsewhere in the codebase — correct there (long, editable, independently-authored content) and wrong here (short, immutable, always rendered together).

## ریسک‌ها

**Iranian electronic invoicing (سامانه مؤدیان / مالیات بر ارزش افزوده) is a full integration, not a 10% line item. Every sales invoice from the .ir install must be signed with a private key and submitted to the Tax Administration with a unique tax ID (شماره منحصر به فرد مالیاتی); rejections arrive asynchronously days later and must be corrected with a proper credit note. Getting this wrong is a legal exposure, not a bug. I believe this is the single most underestimated item in the whole billing area.**

→ fiscal_uid / fiscal_status / fiscal_synced_at / fiscal_error columns on both invoices and credit_notes, a queued FiscalReporter with retry and a status poller, and an admin queue of rejected documents. Bind a null implementation on the .de install. Treat the integration as a launch blocker for servernet.ir, and get the accountant to confirm the required fields BEFORE the invoice schema is frozen — retrofitting fields into issued invoices is not possible.

**The legal/tax basis of the German install is undefined. If the entity is German, EU B2C sales trigger VAT at the customer's country rate plus OSS filing; if the entity is Iranian, EU customers may need reverse-charge treatment or none at all. Writing tax code before this is decided guarantees a rewrite.**

→ tax_rates is deliberately general (country + customer_type + requires_tax_id + priority) and TaxResolver is a contract, so either answer is a data change. But the owner must get an answer from an accountant before the .de install takes its first EU consumer payment. Flag explicitly: this is a business decision blocking a technical one.

**Sanctions and PSP exposure on the German side. An Iranian-owned hosting business taking EUR from foreigners will very likely have Stripe/PayPal/mainstream PSP accounts frozen with funds held, and a crypto processor may also drop the account. This is an existential operational risk, not a billing edge case.**

→ The gateway abstraction means a PSP can be swapped by inserting a row and writing one driver. Do not architect the .de checkout around any single card processor; treat crypto as the primary rail and card as opportunistic. Never let float sit at a PSP: settle out frequently, and keep payments.settled_at/settlement_ref so held funds are visible immediately.

**Crypto: late payment after the rate TTL, wrong-network sends (USDT on BEP20 to a TRC20 address), missing memo tags, dust amounts, chain reorgs, and deposits sent from an exchange with no valid return address. Any of these can silently lose customer money and produce a support case with no clean resolution.**

→ Rate lock with rate_expires_at plus settled_rate/settled_minor so late funds are revalued at spot and still credited; per-asset confirmations_required and underpay tolerance in crypto_assets; crypto_transactions.status='orphaned' so a reorg can un-credit; memo_tag as a first-class column with the checkout refusing to display an address for a memo-chain without one. Everything resolves through the wallet, and crypto is never auto-refunded — refunds are manual and require a customer-supplied address.

**Iranian gateway behaviour: the API takes Rial while you price in Toman (a factor-of-ten bug that bills 10x or 1/10x), verification endpoints that succeed without checking the amount, callbacks replayed or arriving hours after the customer gave up, and Shaparak settlement lagging T+1..T+3 so the bank balance never matches the system.**

→ The x10 exists in exactly one method (PaymentGateway::toGatewayAmount) and the result is recorded in payments.gateway_amount for reconciliation. PaymentGateway::resolve() is contractually required to re-verify the amount server-side and to be idempotent, backed by UNIQUE(gateway_id, gateway_reference) so a replay physically cannot allocate twice. A payment confirmed after we expired the invoice is credited to the wallet rather than discarded — the customer's money is never lost, only re-homed. settled_at/settlement_ref support bank reconciliation.

**Fully automatic provisioning plus any automatic refund path is a carding and abuse magnet: instant VPS from a stolen card is the classic attack, and the Iranian gateway's chargeback story is weak.**

→ orders.fraud_score/fraud_notes and a 'fraud' order and service status; a first-order review threshold above a configurable amount; downgrade credit and overpayment go to the WALLET, never back out through a gateway, so the refund path cannot be used to launder. Rate-limit provisioning per user/IP in the provisioning area, and require verified identity (the KYC area) before high-value instant provisioning.

**Migrating the existing DECIMAL(15,2) money in the old schema (C:\Users\Administrator\Desktop\ServerNet\ServerNet\app\database\migrations) to BIGINT minor units, and moving SQLite to MySQL at the same time. A wrong exponent silently multiplies or divides every historical balance by 100.**

→ One-shot migration that multiplies by 10^exponent per currency with an explicit before/after reconciliation report (row counts and grand totals per currency must match), run with billing frozen. Do it as a separate deploy from any feature work. Note that SELECT ... FOR UPDATE — which gapless numbering and wallet locking both depend on — does not exist meaningfully in SQLite, so MySQL/MariaDB with InnoDB is a hard prerequisite, not a preference.

**Domains do not behave like hosting in dunning. Suspending a domain is meaningless, and auto-terminating one is destructive: past the registry expiry the domain enters redemption with an $80+ restore fee, or is lost outright. A generic overdue ladder will eventually delete a customer's domain.**

→ dunning_policies.applies_to scopes the ladder per service type, services.auto_terminate defaults to 0 for type='domain', and services.invoice_lead_days should be 45–60 for domains so the renewal invoice exists long before the registry deadline. Add a monitoring alert for any domain service whose registry expiry is inside 14 days with an unpaid invoice.

**Two independent databases means one human being can be two customers with two wallets, two credit balances and two invoice histories. Credit earned on servernet.ir cannot pay for a service on servernet.cloud. Customers will ask, and support will be tempted to fake it with manual adjustments.**

→ Accept it and make it explicit in the UI ('your ServerNet Cloud balance is separate'). Never build a cross-install wallet transfer — it would be an unreconcilable cross-border movement between two legal/tax regimes. If it is ever needed, it must be a real invoice from one entity to the other, not a ledger entry. Keeping the `install` column everywhere at least makes consolidated reporting possible later.

**Gapless invoice numbering serialises invoice issue behind a single row lock, and holding that lock while calling a payment gateway or a registrar over the network will stall every other checkout on the site.**

→ DocumentNumberAllocator::allocate throws if called outside a transaction, and the coding rule is absolute: no HTTP call inside a DB transaction. Quote suppliers and start gateway payments BEFORE opening the transaction; the transaction contains only the insert, the counter increment and the allocation. The lock is then held for microseconds. Enforce with a test that asserts no outbound HTTP inside a transaction.

**cPanel cron is unreliable and can overlap or silently stop. A renewal run that fires twice double-bills; a run that never fires means nothing renews and services silently expire, which is worse.**

→ idempotency_keys with scope 'cron_renewal' and key 'renewal:{service_id}:{period_start}', plus the UNIQUE(renewal_key) generated column on invoice_items so double-billing is impossible at the storage layer. Separately, a heartbeat: record each cron run in billing_events and alert if no successful renewal run has been recorded in 26 hours — a missing cron must page someone.

**Proration combined with promotions is a known revenue leak: customers upgrade or downgrade to re-trigger a first-term promotional price, or to shift their billing date.**

→ services.promo_locked_until, promotions.cycles ('first' vs 'all' vs n), and services.recurring_amount always holding the FULL renewal price rather than the promo price. The billing cycle is never reset by a service change, so the date cannot be shifted. Discounts are recomputed at change time against the promotion's cycles setting, not copied forward blindly.

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Billing;

/**
 * The only representation of money in the system.
 * amount is ALWAYS minor units for `currency`, per currencies.exponent.
 * IRT exponent 0 -> amount 1490000 means 1,490,000 Toman.
 * EUR exponent 2 -> amount 499     means EUR 4.99.
 * There is no float constructor and no float accessor. On purpose.
 */
final readonly class Money implements \JsonSerializable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function of(int $minorUnits, string $currency): self;
    public static function zero(string $currency): self;

    /** Parse human input ('۱٬۴۹۰٬۰۰۰' or '4.99') using currencies.exponent. Throws on ambiguity. */
    public static function parse(string $input, string $currency): self;

    public function plus(Money $other): self;              // throws CurrencyMismatch
    public function minus(Money $other): self;
    public function multipliedBy(int $factor): self;

    /** Percentage in basis points. 1000bp = 10%. Integer math only. */
    public function percentage(int $basisPoints, Rounding $mode = Rounding::HalfUp): self;

    /** ratio-of-total, e.g. proration: 12 days of 31. */
    public function ratio(int $numerator, int $denominator, Rounding $mode = Rounding::HalfUp): self;

    /**
     * Split across N buckets so the parts sum EXACTLY to $this (largest-remainder).
     * Used for distributing a discount or tax across invoice lines.
     * @return list<Money>
     */
    public function allocate(int ...$weights): array;

    /** Round to currencies.rounding_step (IRT: nearest 1000 Toman). Returns [rounded, residual]. */
    public function roundToStep(int $step, Rounding $mode = Rounding::HalfUp): array;

    public function isZero(): bool;
    public function isNegative(): bool;
    public function compareTo(Money $other): int;
    public function negated(): self;

    /** Locale-aware, RTL-safe. fa uses Persian digits and 'تومان'. */
    public function format(string $locale): string;

    public function jsonSerialize(): array;                // ['amount' => int, 'currency' => 'IRT']
}

enum Rounding { case HalfUp; case HalfEven; case Down; case Up; }

final class CurrencyMismatch extends \DomainException {}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Billing\Money;
use App\Models\Gateway;
use App\Models\Payment;

/**
 * Every payment method implements this and NOTHING ELSE in the codebase
 * switches on the gateway. A new PSP = one class + one `gateways` row.
 */
interface PaymentGateway
{
    /** Matches gateways.driver. */
    public function key(): string;

    /** Injected from the `gateways` row (config is the decrypted JSON). */
    public function boot(Gateway $gateway): void;

    /** Currency this driver settles in; may differ from the billing currency (IRT vs IRR). */
    public function gatewayCurrency(string $billingCurrency): string;

    /** Convert billing money -> the integer the PSP expects. The Toman->Rial x10 lives HERE and only here. */
    public function toGatewayAmount(Money $amount): int;

    public function supports(string $billingCurrency): bool;

    /**
     * Create the attempt. MUST NOT be called inside a DB transaction.
     * Implementations must be safe to call twice with the same Payment.
     */
    public function start(PaymentRequest $request): PaymentInstruction;

    /**
     * Resolve a callback, webhook or return-from-redirect into a definite outcome.
     * MUST re-verify the amount server-side against $message->payment.
     * MUST be idempotent: a replayed callback returns the same outcome without side effects.
     */
    public function resolve(GatewayMessage $message): PaymentOutcome;
}

/** Opt-in capabilities. Checked with instanceof, never with a config flag. */
interface SupportsRefund
{
    public function refund(Payment $original, Money $amount, string $reason): PaymentOutcome;
}

interface SupportsPolling
{
    /** For gateways where the customer may never return to our site. Called by cron. */
    public function poll(Payment $payment): PaymentOutcome;
}

interface SupportsSettlementReport
{
    /** Bank-statement reconciliation; Shaparak settles T+1..T+3. @return iterable<SettlementRow> */
    public function settlements(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}

final readonly class PaymentRequest
{
    public function __construct(
        public Payment $payment,
        public Money $amount,
        public string $description,   // already localised
        public string $locale,        // fa|en|tr
        public string $callbackUrl,
        public ?string $customerMobile = null,
        public ?string $customerEmail = null,
        public array $metadata = [],
    ) {}
}

/** What the checkout page must do next. */
final readonly class PaymentInstruction
{
    public function __construct(
        public InstructionType $type,        // Redirect | Display | Immediate
        public ?string $redirectUrl = null,
        public ?string $reference = null,    // stored in payments.gateway_reference
        public array $display = [],          // crypto: address, amount, qr, expires_at, confirmations
        public ?\DateTimeImmutable $expiresAt = null,
    ) {}
}

enum InstructionType { case Redirect; case Display; case Immediate; }

final readonly class GatewayMessage
{
    public function __construct(
        public Payment $payment,
        public array $input,          // query + body
        public array $headers,
        public ?string $rawBody = null,   // signature verification
    ) {}
}

final readonly class PaymentOutcome
{
    public function __construct(
        public OutcomeStatus $status,
        public ?Money $amountConfirmed = null,  // what the PSP actually took; may differ from requested
        public ?string $reference = null,
        public ?Money $fee = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $raw = [],
    ) {}
}

enum OutcomeStatus { case Pending; case Succeeded; case Failed; case Expired; case Cancelled; case AmountMismatch; }
```

```php
<?php

namespace App\Billing\Contracts;

use App\Billing\Money;
use App\Models\CryptoAsset;
use App\Models\CryptoPaymentIntent;
use App\Models\Payment;

/**
 * Crypto processors. The rate is quoted with a TTL, the amount is volatile,
 * and settlement is a function of confirmations - so this cannot be squeezed
 * into the redirect-gateway shape.
 */
interface CryptoGateway extends PaymentGateway
{
    /** @return list<CryptoAsset> assets/networks this driver can currently accept. */
    public function assets(): array;

    /**
     * Lock a rate. The returned quote is what gets written to
     * crypto_payment_intents.rate_minor_per_unit / rate_expires_at.
     */
    public function quote(Money $invoiceAmount, CryptoAsset $asset): CryptoQuote;

    /** Allocate (or derive) a receiving address for this payment. Must be unique per intent. */
    public function allocateAddress(Payment $payment, CryptoQuote $quote): CryptoAddress;

    /**
     * Current on-chain view. Called by webhook AND by cron poll - both paths
     * must converge on the same state. Reorgs must be reported as orphaned.
     * @return list<ObservedTransaction>
     */
    public function observe(CryptoPaymentIntent $intent): array;

    /** Spot rate, used when funds arrive after rate_expires_at. */
    public function spotRate(CryptoAsset $asset, string $billingCurrency): string; // decimal string
}

final readonly class CryptoQuote
{
    public function __construct(
        public CryptoAsset $asset,
        public string $rateMinorPerUnit,      // decimal STRING, never float. Billing minor units per 1 whole coin.
        public string $amountExpected,        // decimal STRING in whole coin units
        public Money $amountDue,
        public \DateTimeImmutable $lockedAt,
        public \DateTimeImmutable $expiresAt,
        public int $confirmationsRequired,
        public string $source,
    ) {}
}

final readonly class CryptoAddress
{
    public function __construct(
        public string $address,
        public ?int $derivationIndex = null,
        public ?string $memoTag = null,       // omitting this on chains that need it loses the funds
        public ?string $uri = null,           // BIP-21 style, for the QR code
    ) {}
}

final readonly class ObservedTransaction
{
    public function __construct(
        public string $txid,
        public ?int $vout,
        public string $amount,                // decimal STRING, whole coin units
        public int $confirmations,
        public ?int $blockHeight,
        public bool $orphaned = false,
        public array $raw = [],
    ) {}
}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Billing\Money;

/**
 * Resolves which tax_rates row applies. Iran: one 10% VAT row.
 * Germany install: depends on the legal entity + customer country + tax id.
 * No percentage is ever hardcoded by a caller.
 */
interface TaxResolver
{
    public function resolve(TaxContext $context): TaxDecision;
}

final readonly class TaxContext
{
    public function __construct(
        public string $install,            // 'ir' | 'de'
        public string $customerType,       // 'individual' | 'company'
        public ?string $country,           // billing country, ISO-2
        public ?string $taxId,             // VAT id / شناسه ملی, already validated
        public bool $taxIdValidated,
        public string $serviceType,        // 'hosting','vps','domain',...
        public \DateTimeImmutable $at,     // rate as of the issue date, not today
    ) {}
}

final readonly class TaxDecision
{
    public function __construct(
        public ?int $taxRateId,
        public int $rateBasisPoints,       // 1000 = 10%; 0 = exempt / reverse charge
        public string $nameFa,
        public string $nameEn,
        public string $nameTr,
        public bool $reverseCharge = false,
        public ?string $exemptionNote = null,   // printed on the invoice when rate is 0
    ) {}

    public function apply(Money $net): Money;   // $net->percentage($this->rateBasisPoints)
}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Billing\Money;
use App\Models\Service;

/**
 * Upgrade / downgrade maths. Daily basis, tax-exclusive, cycle NOT reset.
 * Every number it produces is persisted to service_changes for dispute resolution.
 */
interface ProrationCalculator
{
    public function calculate(
        Service $service,
        Money $newRecurringAmount,
        \DateTimeImmutable $effectiveAt,
    ): ProrationResult;
}

final readonly class ProrationResult
{
    public function __construct(
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public int $daysTotal,
        public int $daysRemaining,
        public Money $creditAmount,     // unused portion of the OLD plan, tax-exclusive, >= 0
        public Money $chargeAmount,     // remaining days on the NEW plan,  tax-exclusive, >= 0
        public Money $netAmount,        // charge - credit; negative => wallet credit, never a gateway refund
        public array $breakdown,        // -> service_changes.calc
    ) {}

    public function requiresPayment(): bool;   // netAmount > 0
    public function producesCredit(): bool;    // netAmount < 0
}
```

```php
<?php

namespace App\Billing\Contracts;

/**
 * Gapless sequential numbering. MUST be called inside the same DB transaction
 * that inserts the document, and MUST be the last thing before COMMIT - the
 * transaction must not contain any external HTTP call.
 */
interface DocumentNumberAllocator
{
    /**
     * Locks the invoice_sequences row (SELECT ... FOR UPDATE), increments, returns
     * the rendered number, e.g. 'SN-IR-1405-000173'.
     *
     * @param 'invoice'|'credit_note'|'receipt' $kind
     * @throws \RuntimeException if called outside a transaction
     */
    public function allocate(string $install, string $kind, \DateTimeImmutable $issuedAt): AllocatedNumber;

    /** Rolls the series at fiscal-year boundary (1 Farvardin for 'ir', 1 January for 'de'). */
    public function currentSeries(string $install, string $kind, \DateTimeImmutable $at): string;
}

final readonly class AllocatedNumber
{
    public function __construct(
        public string $series,
        public string $number,
        public int $sequenceValue,
    ) {}
}
```

```php
<?php

namespace App\Billing\Contracts;

/**
 * Anything that can be sold must be able to produce a price it will honour.
 * Registrar drivers, Proxmox, Hetzner, cPanel resellers all implement this.
 * The billing layer NEVER reads a price from anywhere else.
 */
interface PriceQuoteSource
{
    /** Matches price_quotes.supplier. */
    public function supplier(): string;

    /**
     * Ask the supplier for a real, purchasable price.
     * MUST populate renewal price and MUST set class from the per-item response
     * (a domain-check `with_price` payload), never from a cached TLD price list.
     *
     * @param list<QuoteRequest> $requests  batched where the supplier supports it
     * @return list<SuppliedQuote>
     */
    public function quote(array $requests): array;

    /** How long this supplier's prices can be trusted. Domains: minutes. */
    public function quoteTtlSeconds(): int;

    /**
     * Replay the quote at purchase time. If the supplier now refuses or has
     * moved the price, this MUST fail rather than silently buy at a new price.
     *
     * @throws QuoteNoLongerHonouredException
     */
    public function honour(SuppliedQuote $quote): PurchaseConfirmation;
}

final readonly class SuppliedQuote
{
    public function __construct(
        public string $subjectType,        // 'domain_register' | 'product_plan' | ...
        public string $subjectKey,         // 'example.com' | plan slug
        public string $supplierCurrency,
        public string $costNative,         // decimal STRING as returned; never float
        public ?string $renewalCostNative, // WITHOUT this the cheapest-first-year trap is unavoidable
        public int $termMonths,
        public QuoteClass $class,
        public Availability $availability,
        public ?string $honourRef,         // supplier quote/session id to replay at purchase
        public \DateTimeImmutable $expiresAt,
        public array $raw,                 // -> price_quotes.raw, evidence
    ) {}
}

/** Drives the three required UI states plus the reasons we must not sell. */
enum QuoteClass { case Standard; case Premium; case Special; case Restricted; case Unavailable; }
enum Availability { case Available; case Taken; case Reserved; case Unknown; }

final class QuoteNoLongerHonouredException extends \RuntimeException
{
    public function __construct(
        public readonly SuppliedQuote $quote,
        public readonly ?string $newCostNative,
        string $message = '',
    ) { parent::__construct($message); }
}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Models\DunningPolicy;
use App\Models\Invoice;

/**
 * One class per action key in dunning_policies.action.
 * Adding 'send_sms_final_warning' = one class + one row, no changes to the cron.
 */
interface DunningAction
{
    public function key(): string;                              // 'notify','late_fee','suspend','terminate'

    /** False => stage is skipped and marked done (e.g. never suspend a domain). */
    public function applies(Invoice $invoice, DunningPolicy $policy): bool;

    /** Must be idempotent: the cron may run twice. Writes a billing_events row. */
    public function execute(Invoice $invoice, DunningPolicy $policy): DunningActionResult;
}

final readonly class DunningActionResult
{
    public function __construct(
        public bool $performed,
        public ?string $note = null,
        public ?\DateTimeImmutable $retryAt = null,
    ) {}
}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Models\CreditNote;
use App\Models\Invoice;

/**
 * Electronic fiscal reporting. IR install: سامانه مؤدیان (Iranian Tax
 * Administration) - invoices must be signed and submitted, and acceptance
 * or rejection arrives asynchronously, sometimes days later.
 * A no-op implementation is bound on the DE install.
 */
interface FiscalReporter
{
    public function required(string $install): bool;

    /** Queued job. Never called inline during checkout. */
    public function submitInvoice(Invoice $invoice): FiscalSubmissionResult;

    public function submitCreditNote(CreditNote $note): FiscalSubmissionResult;

    /** Polled: the authority may reject days after acceptance of receipt. */
    public function checkStatus(string $fiscalUid): FiscalSubmissionResult;
}

final readonly class FiscalSubmissionResult
{
    public function __construct(
        public string $status,        // 'pending'|'sent'|'accepted'|'rejected'
        public ?string $fiscalUid,    // شماره منحصر به فرد مالیاتی
        public ?string $error = null,
        public array $raw = [],
    ) {}
}
```

```php
<?php

namespace App\Billing\Contracts;

use App\Billing\Money;
use App\Models\{CreditNote, Invoice, Payment, User, WalletEntry};

/**
 * The ONLY way money is allowed to move. No controller, job or driver writes
 * to invoices.paid_total, wallets.balance or payment_allocations directly.
 * Every method opens its own transaction, takes the necessary row locks in a
 * fixed order (wallet -> invoice -> payment) and appends to billing_events.
 */
interface BillingLedger
{
    /** Applies a succeeded payment to its invoice; any excess becomes wallet credit. */
    public function settlePayment(Payment $payment, Invoice $invoice, Money $received): SettlementResult;

    /** Spend wallet credit against an invoice. Fails (does not partially apply) if balance is short unless $partial. */
    public function payFromWallet(Invoice $invoice, Money $amount, bool $partial = false): SettlementResult;

    public function creditWallet(User $user, Money $amount, string $reason, array $links = []): WalletEntry;

    public function debitWallet(User $user, Money $amount, string $reason, array $links = []): WalletEntry;

    /** Issues a credit note, allocates it to the invoice, and applies the chosen outcome. */
    public function issueCreditNote(Invoice $invoice, array $lines, string $reason, CreditOutcome $outcome): CreditNote;

    /** Draft -> issued: allocates the gapless number, freezes totals, schedules dunning. */
    public function issueInvoice(Invoice $invoice): Invoice;

    /** Verifies wallets.balance against SUM(wallet_entries) and invoice paid_total against allocations. */
    public function audit(?User $user = null): AuditReport;
}

enum CreditOutcome { case ToWallet; case GatewayRefund; case WriteOff; }

final readonly class SettlementResult
{
    public function __construct(
        public Money $applied,
        public Money $excessToWallet,
        public Money $remainingDue,
        public bool $invoiceNowPaid,       // triggers provisioning / service activation
    ) {}
}
```

