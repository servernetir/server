# catalog-pricing

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

The catalog is built around one sellable row — `products` — whose customer-facing text lives in `product_translations` (fa/en/tr, unique per locale, same shape as the existing `post_translations`), whose money lives in `product_prices` (one row per product × currency × billing period), and whose delivery is expressed as a triple: `provisioning_module_id` (which integration), `target_selection` + `provisioning_target_id`/`product_targets` (which physical server, pool or provider account), and `provisioning_profile` (a deliberately-JSON, module-defined plan payload such as a WHM package name, a Proxmox template + cores/ram/disk, or a Hetzner `cx22` server type). Configurable options are a first-class three-table model — reusable `option_groups` (kind = select/radio/checkbox/quantity/text, carrying a `provisioning_key` like `os`, `datacenter`, `extra_ipv4`), concrete `options` (each with a machine `value`, an optional real FK to a `provisioning_target` so "pick a datacenter" literally selects the node, and an optional FK to an `ip_pool` so "N extra IPs" cannot oversell), and `option_prices` keyed by option × product × currency × period so the same "extra IPv4" group can cost different amounts on different product lines without duplicating rows. All money is `BIGINT` in the currency's minor units with the exponent declared once in `currencies` (IRT exponent 0, EUR exponent 2) — there is no float and no DECIMAL anywhere, and sale prices are never derived by FX; each install simply reads the rows in its own base currency. `product_prices.renewal_price` is a first-class column, not a promotion, because the "cheap first year, expensive renewal" trap the owner already hit is a permanent structural fact, not a time-bounded sale; promotions are separate, time-bounded, and scoped by two thin pivots. Nothing is ever shown or charged unless it came out of a `price_quotes` row with an `honour_until` window — that single rule is what structurally kills the "$20 → $2 → registrar refuses to sell" bug, because the resolver returns `orderable=false` with a reason rather than a price it cannot honour, and domain prices come from a live `DomainPriceSource::check()` (which carries the premium price and the renewal price), never from the TLD list that the current `Whmcs::tldPricing()` reads out of `register[1]`. Stock and capacity are enforced in three layers — `products.stock_qty`, `options.stock_qty`, and `provisioning_targets` capacity counters reconciled by `ReportsCapacity` — with `stock_holds` rows preventing two customers from buying the last IP. Domains are deliberately NOT rows in `products`: they get `domain_tlds` plus display-only `domain_tld_prices` and `domain_pricing_rules` that guarantee a margin over whatever the winning registrar actually quotes. The honest boundary on "no code changes": adding a product, a plan, an option, a price, a promo, a datacenter or a server is pure data; adding a genuinely new *provider* is exactly one class implementing `ProvisioningModule`, and because that class declares its own `profileSchema()`, `targetSchema()` and `actions()`, the admin forms and the customer's power/reboot/console panel render themselves with no Blade and no migration.

## جدول‌ها

### `currencies`

Declares the money representation. Two rows per install lifetime (IRT, EUR). Everything that stores an amount joins here to know how to interpret the integer.

| ستون | نوع | توضیح |
|---|---|---|
| `code` | `CHAR(3) PRIMARY KEY` | 'IRT' or 'EUR'. Toman, never Rial — WHMCS returns Rial and the x10 confusion has already bitten this project. |
| `exponent` | `TINYINT UNSIGNED NOT NULL` | Minor-unit decimals. IRT=0 (490000 means 490,000 Toman), EUR=2 (1290 means EUR 12.90). |
| `rounding_step` | `INT UNSIGNED NOT NULL DEFAULT 1` | Computed prices (percent discounts, proration, markup) snap to this. IRT=10000, EUR=1. Mirrors the existing site_price_yearly() round(-4). |
| `symbol` | `VARCHAR(8) NOT NULL` | 'EUR' sign / empty for IRT. The word 'تومان' comes from __('ui.cur_IRT'), not from the DB — currency labels are UI strings. |
| `symbol_before` | `TINYINT(1) NOT NULL DEFAULT 0` | EUR before the number, Toman after. RTL-safe rendering is the view's job. |
| `is_base` | `TINYINT(1) NOT NULL DEFAULT 0` | Exactly one row true per install. The storefront only ever shows price rows in the base currency. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | Inactive currencies still resolve historical amounts. |

**ایندکس:** `PRIMARY KEY (code)`

### `fx_rates`

Rate history for MARGIN REPORTING and optional 'approx' display only. Never used to compute a price a customer is charged.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `base` | `CHAR(3) NOT NULL FK currencies(code)` | e.g. EUR |
| `quote` | `CHAR(3) NOT NULL FK currencies(code)` | e.g. IRT |
| `rate` | `DECIMAL(24,10) NOT NULL` | DECIMAL is acceptable here because it is a ratio, not money; it is never cast to float in PHP — bcmath or integer math only. |
| `source` | `VARCHAR(24) NOT NULL` | 'manual' \| 'tgju' \| 'ecb' — Iranian rates need a local source and manual override. |
| `fetched_at` | `TIMESTAMP NOT NULL` | History is kept forever; margin reports must be reproducible. |

**ایندکس:** `UNIQUE (base, quote, fetched_at)` · `INDEX (base, quote, fetched_at)`

### `product_groups`

Display/navigation grouping for the storefront (Hosting, VPS, Iran, Dedicated…). Self-nesting, and a product may live in several groups.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `parent_id` | `BIGINT UNSIGNED NULL FK product_groups(id)` | Allows 'Hosting > cPanel Hosting'. Depth is capped at 2 in the admin UI. |
| `slug` | `VARCHAR(64) NOT NULL UNIQUE` | URL segment; feeds the existing $site route closure so all three locales get it. |
| `icon` | `VARCHAR(24) NOT NULL DEFAULT 'box'` | Same icon vocabulary already used in config/hosting.php. |
| `page_route` | `VARCHAR(64) NULL` | Optional bare route name for a hand-built marketing page, resolved with lroute(). |
| `kind_hint` | `VARCHAR(24) NULL` | Optional filter for the admin UI ('vps'), purely cosmetic. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `is_visible` | `TINYINT(1) NOT NULL DEFAULT 1` | Hidden groups still resolve for direct links. |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (slug)` · `INDEX (parent_id, sort)`

### `product_group_translations`

fa/en/tr text for a group. Same contract as post_translations.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_group_id` | `BIGINT UNSIGNED NOT NULL FK product_groups(id) CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr — matches the existing post_translations column width. |
| `name` | `VARCHAR(120) NOT NULL` |  |
| `tagline` | `VARCHAR(191) NULL` | The 'tag' field the current config files already use. |
| `description` | `TEXT NULL` |  |
| `meta_title` | `VARCHAR(191) NULL` |  |
| `meta_description` | `VARCHAR(255) NULL` |  |

**ایندکس:** `UNIQUE (product_group_id, locale)`

### `products`

The sellable SKU. One row per orderable thing (a hosting plan, a VPS size, an SSL product, a storage box, a managed-service tier, an addon). Domains are deliberately NOT here.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `kind` | `VARCHAR(24) NOT NULL` | Fixed code-known set: shared_hosting \| reseller \| vps \| dedicated \| storage \| ssl \| email \| managed \| license \| addon. Drives which customer panel is rendered; deliberately NOT a table (see decisions). |
| `slug` | `VARCHAR(96) NOT NULL UNIQUE` | Order/product URL segment, locale-independent. |
| `sku` | `VARCHAR(64) NULL UNIQUE` | Admin's own code, printed on invoices. |
| `external_key` | `VARCHAR(64) NULL UNIQUE` | Same logical product across the two independent installs (.ir and .cloud). Enables catalog:export/import of structure without prices, and cross-install reporting. |
| `status` | `VARCHAR(12) NOT NULL DEFAULT 'draft'` | draft \| active \| hidden (orderable by direct link only) \| retired (no new orders, existing services renew) \| discontinued (no renew either). Four states because 'hide but keep renewing' is a real need. |
| `provisioning_module_id` | `BIGINT UNSIGNED NULL FK provisioning_modules(id)` | NULL = manually delivered. |
| `target_selection` | `VARCHAR(8) NOT NULL DEFAULT 'fixed'` | fixed (use provisioning_target_id) \| option (an option row carries the target FK) \| auto (allocator picks from product_targets). |
| `provisioning_target_id` | `BIGINT UNSIGNED NULL FK provisioning_targets(id)` | Only meaningful when target_selection='fixed'. |
| `provisioning_profile` | `JSON NULL` | DELIBERATE JSON. Module-defined plan payload: {"package":"biz-10g"} for WHM, {"template":9000,"cores":4,"ram_mb":8192,"disk_gb":120,"storage":"nvme"} for Proxmox, {"server_type":"cx22"} for Hetzner. Validated against ProvisioningModule::profileSchema(). |
| `auto_setup` | `VARCHAR(18) NOT NULL DEFAULT 'on_payment'` | on_order \| on_payment \| on_first_payment \| manual. |
| `requires_domain` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` | 0 none \| 1 customer's own domain \| 2 subdomain of ours \| 3 register/transfer at checkout. |
| `subdomain_zone` | `VARCHAR(96) NULL` | e.g. 'servernet.ir' when requires_domain=2. |
| `stock_mode` | `VARCHAR(10) NOT NULL DEFAULT 'unlimited'` | unlimited \| tracked (decrement stock_qty) \| capacity (ask the provisioning target). |
| `stock_qty` | `INT NULL` | Only when stock_mode='tracked'. Decremented inside the order transaction, never optimistically. |
| `stock_low_threshold` | `INT NULL` | Admin alert trigger. |
| `max_per_customer` | `SMALLINT UNSIGNED NULL` | Abuse control — important for anything with trial_days on the crypto-paying .cloud install. |
| `is_addon` | `TINYINT(1) NOT NULL DEFAULT 0` | Addons are not standalone orderable; they appear via product_addon. |
| `trial_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | 0 = no trial. See risks — trials on VPS are a mining-fraud vector. |
| `setup_pricing` | `VARCHAR(10) NOT NULL DEFAULT 'once'` | once \| per_cycle — whether the setup fee is re-charged when the customer changes billing cycle. |
| `tax_class` | `VARCHAR(24) NOT NULL DEFAULT 'standard'` | String key, not an FK — rates and jurisdictions are owned by the billing area. |
| `panel_view` | `VARCHAR(64) NULL` | Escape hatch: override the generic action-driven service panel with a bespoke Blade view. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `retired_at` | `TIMESTAMP NULL` |  |
| `created_at / updated_at / deleted_at` | `TIMESTAMP NULL` | Soft delete only — a product with live services must never vanish. |

**ایندکس:** `UNIQUE (slug)` · `UNIQUE (sku)` · `UNIQUE (external_key)` · `INDEX (status, kind, sort)` · `INDEX (provisioning_module_id)` · `INDEX (provisioning_target_id)`

### `product_translations`

fa/en/tr customer-facing text for a product. All three rows must exist for status='active' (enforced by catalog:lint).

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK products(id) CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `name` | `VARCHAR(120) NOT NULL` | Plan name. Latin plan codes like 'IR-2' may legitimately be identical in all three. |
| `tagline` | `VARCHAR(191) NULL` |  |
| `short_description` | `VARCHAR(255) NULL` | Card text. |
| `description` | `TEXT NULL` | Product page body. |
| `meta_title` | `VARCHAR(191) NULL` |  |
| `meta_description` | `VARCHAR(255) NULL` |  |

**ایندکس:** `UNIQUE (product_id, locale)`

### `product_group_product`

Many-to-many display membership. 'Iran VPS' appears under both the VPS group and the Iran group — the current config already groups by both type and location.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_group_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `sort` | `INT NOT NULL DEFAULT 0` | Order within this group specifically. |
| `is_featured` | `TINYINT(1) NOT NULL DEFAULT 0` | The 'popular' badge already used in config/catalog/*.php — per-group, because the popular plan differs by page. |

**ایندکس:** `UNIQUE (product_group_id, product_id)` · `INDEX (product_id)`

### `product_prices`

All base money for a product. One row per currency × billing period. This is the only place a base price exists.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK products(id) CASCADE` |  |
| `currency_code` | `CHAR(3) NOT NULL FK currencies(code)` | The storefront only reads the install's base currency; other rows exist for cross-install export and reporting. |
| `period_unit` | `VARCHAR(6) NOT NULL` | hour \| day \| month \| year \| once. No billing_cycles lookup table — two columns express every cycle and renewal date math needs no join. |
| `period_count` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` | monthly=(month,1), quarterly=(month,3), semiannual=(month,6), annual=(year,1), biennial=(year,2), onetime=(once,0). |
| `price` | `BIGINT NOT NULL` | Minor units, TAX-EXCLUSIVE, first term. Signed so credits/negative adjustments share the type. |
| `renewal_price` | `BIGINT NULL` | NULL = same as price. A REAL COLUMN, not a promotion — permanent intro pricing is not time-bounded. The resolver always returns a renewal figure so the UI can show real multi-year cost. |
| `setup_fee` | `BIGINT NOT NULL DEFAULT 0` | Minor units. |
| `cost_price` | `BIGINT NULL` | What ServerNet pays upstream for one term. Feeds catalog:margin-audit. |
| `cost_currency_code` | `CHAR(3) NULL FK currencies(code)` | Hetzner costs EUR even on the Iran install — this is why cost carries its own currency and fx_rates exists. |
| `min_quantity` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` |  |
| `max_quantity` | `SMALLINT UNSIGNED NULL` |  |
| `is_default` | `TINYINT(1) NOT NULL DEFAULT 0` | Preselected cycle on the product page. Exactly one per (product, currency), enforced in the model. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | Deactivating a cycle stops new orders on it; existing services keep renewing on their stored snapshot. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (product_id, currency_code, period_unit, period_count)` · `INDEX (product_id, is_active)`

### `feature_items`

The reusable feature pool that config/hosting.php already has ('nvme', 'litespeed', 'ssl', 'backup'…), moved into data so admin can add one without a code change.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `slug` | `VARCHAR(48) NOT NULL UNIQUE` | 'nvme', 'litespeed' — seeded from the existing feature_pool. |
| `icon` | `VARCHAR(24) NOT NULL DEFAULT 'check'` | Existing icon vocabulary. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |

**ایندکس:** `UNIQUE (slug)`

### `feature_item_translations`

fa/en/tr title + description for a pooled feature.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `feature_item_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `title` | `VARCHAR(120) NOT NULL` | Maps to the existing 't' key. |
| `description` | `VARCHAR(255) NULL` | Maps to the existing 'd' key. |

**ایندکس:** `UNIQUE (feature_item_id, locale)`

### `product_feature`

Which pooled features a product shows, with an optional per-product numeric value so one translated feature serves 40 plans.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `feature_item_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `value` | `VARCHAR(80) NULL` | LOCALE-NEUTRAL technical string only ('50 GB NVMe', '4 vCPU / 8 GB RAM') — exactly the convention config/catalog/vps.php already uses. Digits are localised at render with fa_num(). Anything needing real translation must be a feature_item, not a value. |
| `is_highlight` | `TINYINT(1) NOT NULL DEFAULT 0` | Shown on the compact plan card vs the full list. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |

**ایندکس:** `UNIQUE (product_id, feature_item_id)` · `INDEX (product_id, sort)`

### `option_groups`

A reusable configurable-option question: 'choose an OS', 'pick a datacenter', 'extra IPv4', 'control panel', 'backup'. Shared across many products.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `slug` | `VARCHAR(48) NOT NULL UNIQUE` | 'os', 'datacenter', 'extra_ipv4', 'control_panel', 'backup_plan'. |
| `kind` | `VARCHAR(10) NOT NULL` | select \| radio \| checkbox \| quantity \| text. 'quantity' groups MUST have exactly one option row (the unit) — this keeps every priced thing an option row. |
| `provisioning_key` | `VARCHAR(48) NOT NULL` | The key handed to the provisioning module. Must appear in ProvisioningModule::supportedOptionKeys() for every module the group is attached to; catalog:lint enforces it. |
| `is_required` | `TINYINT(1) NOT NULL DEFAULT 0` | Default; overridable per product. |
| `min_qty` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | quantity kind only — 'add N extra IPs' is min 0. |
| `max_qty` | `SMALLINT UNSIGNED NULL` | Hard cap independent of stock. |
| `qty_step` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` | e.g. RAM sold in 1024 MB steps. |
| `changeable_after_order` | `TINYINT(1) NOT NULL DEFAULT 0` | OS is not changeable post-order (it's a reinstall); extra IPs are. Drives the upgrade/downgrade UI. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (slug)` · `INDEX (is_active, sort)`

### `option_group_translations`

fa/en/tr label and help text for the question itself.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `option_group_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `label` | `VARCHAR(120) NOT NULL` | 'سیستم‌عامل' / 'Operating system' / 'İşletim sistemi' |
| `help_text` | `VARCHAR(255) NULL` |  |

**ایندکس:** `UNIQUE (option_group_id, locale)`

### `options`

One concrete choice inside a group. Every priced configurable thing is one of these rows — including the single unit row of a quantity group.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `option_group_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `slug` | `VARCHAR(48) NOT NULL` | Unique within the group. |
| `value` | `VARCHAR(96) NOT NULL` | The machine value passed to the provisioning module: 'ubuntu-24.04', 'hel1', 'cpanel', 'almalinux-9'. |
| `provisioning_target_id` | `BIGINT UNSIGNED NULL FK provisioning_targets(id)` | Set when THIS option is the location/node choice — a real FK, so 'pick a datacenter' literally selects the server. Used when products.target_selection='option'. |
| `ip_pool_id` | `BIGINT UNSIGNED NULL FK ip_pools(id)` | Set when this option consumes IPs (extra IPv4). Stock is then the count of free addresses — a real FK rather than a polymorphic stock source. |
| `stock_mode` | `VARCHAR(10) NOT NULL DEFAULT 'unlimited'` | unlimited \| tracked (stock_qty) \| derived (ip_pool free count, or the target's capacity). |
| `stock_qty` | `INT NULL` | e.g. 12 Windows licences left. |
| `icon` | `VARCHAR(24) NULL` |  |
| `image` | `VARCHAR(191) NULL` | OS logo path. |
| `is_default` | `TINYINT(1) NOT NULL DEFAULT 0` | Preselected. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |
| `sort` | `INT NOT NULL DEFAULT 0` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (option_group_id, slug)` · `INDEX (option_group_id, is_active, sort)` · `INDEX (provisioning_target_id)` · `INDEX (ip_pool_id)`

### `option_translations`

fa/en/tr label for a choice. 'Ubuntu 24.04' is identical in all three, but 'Daily backup, 7 copies' is not.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `option_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `label` | `VARCHAR(120) NOT NULL` |  |
| `description` | `VARCHAR(255) NULL` |  |

**ایندکس:** `UNIQUE (option_id, locale)`

### `product_option_group`

Attaches a reusable option group to a product, with per-product overrides.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `option_group_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `is_required` | `TINYINT(1) NULL` | NULL = inherit the group's default. Lets one shared 'OS' group be optional on one line and mandatory on another. |
| `is_hidden` | `TINYINT(1) NOT NULL DEFAULT 0` | Hidden but still applied with its default — e.g. a control panel forced on a managed plan. |
| `sort` | `INT NOT NULL DEFAULT 0` | Order of questions in the configure step, per product. |

**ایندکس:** `UNIQUE (product_id, option_group_id)` · `INDEX (option_group_id)`

### `option_prices`

Money for options. Absolute per-unit deltas, per currency and period, with an optional per-product override.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `option_id` | `BIGINT UNSIGNED NOT NULL FK options(id) CASCADE` |  |
| `product_id` | `BIGINT UNSIGNED NULL FK products(id) CASCADE` | NULL = the default price for this option on every product. A row with a product_id overrides it — 'extra IP is EUR 2 normally but 20,000 Toman on the Iran VPS line' without cloning the option. |
| `product_key` | `BIGINT UNSIGNED AS (COALESCE(product_id,0)) STORED` | Generated column, exists ONLY so the unique index works — MySQL/MariaDB treat NULLs as distinct in unique indexes, which would otherwise allow two conflicting global rows. Requires MariaDB 10.2+/MySQL 5.7+. |
| `currency_code` | `CHAR(3) NOT NULL FK currencies(code)` |  |
| `period_unit` | `VARCHAR(6) NOT NULL` | Must match a period the product actually sells; catalog:lint reports gaps. |
| `period_count` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` |  |
| `price` | `BIGINT NOT NULL DEFAULT 0` | Absolute amount ADDED per unit per period. Never a percentage of the base — see decisions. |
| `renewal_price` | `BIGINT NULL` | NULL = same as price. |
| `setup_fee` | `BIGINT NOT NULL DEFAULT 0` | One-off per unit — e.g. a per-IP setup charge. |
| `cost_price` | `BIGINT NULL` | Upstream cost of this option (Hetzner charges for extra IPv4). |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |

**ایندکس:** `UNIQUE (option_id, product_key, currency_code, period_unit, period_count)` · `INDEX (option_id, currency_code)`

### `option_rules`

Pairwise constraints between options: cPanel requires AlmaLinux; Plesk conflicts with the Tehran node; Windows conflicts with the 2 GB plan's OS list.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `option_id` | `BIGINT UNSIGNED NOT NULL FK options(id) CASCADE` | The constrained option. |
| `related_option_id` | `BIGINT UNSIGNED NOT NULL FK options(id) CASCADE` | The option it depends on / conflicts with. |
| `mode` | `VARCHAR(9) NOT NULL` | requires \| conflicts. Deliberately only two verbs — a JSON rule DSL would be unauditable and unrenderable in the admin UI. |
| `product_id` | `BIGINT UNSIGNED NULL FK products(id) CASCADE` | NULL = global rule; set = scoped to one product. |
| `product_key` | `BIGINT UNSIGNED AS (COALESCE(product_id,0)) STORED` | Same NULL-uniqueness workaround as option_prices. |

**ایندکس:** `UNIQUE (option_id, related_option_id, product_key)` · `INDEX (related_option_id)`

### `product_addon`

Cross-sell / bundling: attach an SSL, a cPanel licence, a backup service or managed support to a parent product.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` | The parent. |
| `addon_product_id` | `BIGINT UNSIGNED NOT NULL FK products(id) CASCADE` | Must have is_addon=1. |
| `is_required` | `TINYINT(1) NOT NULL DEFAULT 0` | Forced into the cart (managed support on a managed plan). |
| `is_free` | `TINYINT(1) NOT NULL DEFAULT 0` | Included at zero — still creates a service row so it can be provisioned and later charged. |
| `sort` | `INT NOT NULL DEFAULT 0` |  |

**ایندکس:** `UNIQUE (product_id, addon_product_id)` · `INDEX (addon_product_id)`

### `promotions`

Time-bounded discounts, with or without a coupon code. Structurally separate from renewal_price.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `code` | `VARCHAR(40) NULL UNIQUE` | NULL = automatic sale (no code needed). Case-folded and trimmed on write. |
| `kind` | `VARCHAR(10) NOT NULL` | percent \| fixed \| override (set an exact price) \| free_setup. |
| `percent_bp` | `INT NULL` | Basis points — 2500 = 25.00%. Integer, never a float percentage. |
| `amount` | `BIGINT NULL` | Minor units, for kind=fixed/override. |
| `currency_code` | `CHAR(3) NULL FK currencies(code)` | Required for fixed/override; NULL for percent (percent is currency-agnostic). |
| `applies_to` | `VARCHAR(9) NOT NULL DEFAULT 'recurring'` | recurring \| setup \| both. |
| `recurring_terms` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` | How many billing terms it survives. 1 = first term only; 0 = for the life of the service. This is what makes the renewal figure honest. |
| `period_unit` | `VARCHAR(6) NULL` | Restrict to one cycle, e.g. annual-only promos. |
| `period_count` | `SMALLINT UNSIGNED NULL` |  |
| `first_order_only` | `TINYINT(1) NOT NULL DEFAULT 0` |  |
| `new_customers_only` | `TINYINT(1) NOT NULL DEFAULT 0` |  |
| `min_order_amount` | `BIGINT NULL` | Minor units, in currency_code or the install base currency. |
| `max_redemptions` | `INT NULL` |  |
| `redemptions` | `INT NOT NULL DEFAULT 0` | Incremented inside the order transaction with a row lock, not with an UPDATE-then-check. |
| `max_per_customer` | `SMALLINT UNSIGNED NULL` |  |
| `starts_at` | `TIMESTAMP NULL` | UTC — config/app.php pins the app to UTC. |
| `ends_at` | `TIMESTAMP NULL` | UTC. Nowruz campaigns must be entered as UTC instants. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (code)` · `INDEX (is_active, starts_at, ends_at)`

### `promotion_translations`

The label that lands on the cart line and the invoice — must be trilingual because invoices are customer documents.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `promotion_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `label` | `VARCHAR(120) NOT NULL` | 'تخفیف نوروزی' / 'Nowruz sale' / 'Nevruz indirimi'. |

**ایندکس:** `UNIQUE (promotion_id, locale)`

### `promotion_product`

Scope a promotion to specific products. Empty scope on BOTH pivots = applies to everything.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `promotion_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |

**ایندکس:** `UNIQUE (promotion_id, product_id)` · `INDEX (product_id)`

### `promotion_product_group`

Scope a promotion to whole groups ('20% off all Iran VPS'). Two thin explicit pivots instead of one polymorphic scope table.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `promotion_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `product_group_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |

**ایندکس:** `UNIQUE (promotion_id, product_group_id)` · `INDEX (product_group_id)`

### `provisioning_modules`

Admin-visible registry of integrations. Holds NO class name — the FQCN lives in config/provisioning.php keyed by this slug.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `slug` | `VARCHAR(32) NOT NULL UNIQUE` | 'cpanel_whm', 'plesk', 'directadmin', 'proxmox', 'hetzner', 'ovh', 'virtualizor', 'ssl_sectigo', 'manual'. MUST resolve in config/provisioning.php or the module is treated as missing. |
| `name` | `VARCHAR(80) NOT NULL` | Admin label only — never customer-facing, so no translation table. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | Disabling blocks new orders on every product using it; existing services keep working. |
| `notes` | `VARCHAR(255) NULL` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (slug)`

### `provisioning_targets`

A concrete endpoint the product lands on: one WHM server, one Plesk node, one Proxmox cluster, one Hetzner API account, one DirectAdmin box.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `provisioning_module_id` | `BIGINT UNSIGNED NOT NULL FK` |  |
| `slug` | `VARCHAR(48) NOT NULL UNIQUE` | 'whm-de-01', 'pve-tehran-a'. |
| `name` | `VARCHAR(120) NOT NULL` | Admin label. |
| `hostname` | `VARCHAR(191) NULL` | Or API base URL. |
| `port` | `SMALLINT UNSIGNED NULL` |  |
| `country` | `CHAR(2) NULL` | ISO-3166 — feeds the datacenter option and geo-suggestion. |
| `region` | `VARCHAR(32) NULL` | Provider region code ('hel1', 'fsn1', 'tehran-irancell'). |
| `credentials` | `TEXT NOT NULL` | Laravel 'encrypted:array' cast — JSON inside, at rest encrypted with APP_KEY. Cannot be columns; must never be logged. Rotation is an admin action, not a migration. |
| `settings` | `JSON NULL` | DELIBERATE JSON — module-defined non-secret config (nameservers, default storage name, bridge, template id, ip pool default). Validated against ProvisioningModule::targetSchema(). |
| `status` | `VARCHAR(12) NOT NULL DEFAULT 'draft'` | draft \| active \| maintenance \| full \| disabled. 'full' is set automatically by the capacity reconciler. |
| `capacity_mode` | `VARCHAR(10) NOT NULL DEFAULT 'none'` | none \| accounts (shared hosting) \| resources (VPS). |
| `max_accounts` | `INT NULL` |  |
| `used_accounts` | `INT NOT NULL DEFAULT 0` | Denormalised counter. Advisory only — the last slot is always verified live before selling. |
| `max_ram_mb / used_ram_mb` | `INT NULL / INT NOT NULL DEFAULT 0` | Proxmox resource accounting. |
| `max_disk_gb / used_disk_gb` | `INT NULL / INT NOT NULL DEFAULT 0` |  |
| `max_vcpu / used_vcpu` | `INT NULL / INT NOT NULL DEFAULT 0` | vCPU is legitimately overcommitted; see overcommit_bp. |
| `overcommit_bp` | `INT NOT NULL DEFAULT 10000` | Basis points. 10000 = 1.0x, 40000 = 4x vCPU overcommit. Integer, no float ratios. |
| `weight` | `SMALLINT NOT NULL DEFAULT 100` | Allocator preference among eligible targets. |
| `is_test` | `TINYINT(1) NOT NULL DEFAULT 0` | Lets a staging product point at a sandbox node without a second install. |
| `capacity_checked_at` | `TIMESTAMP NULL` | Set by the ReportsCapacity reconciliation cron. |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (slug)` · `INDEX (provisioning_module_id, status)` · `INDEX (country, status)`

### `product_targets`

The eligible target set when products.target_selection='auto', and the validation whitelist when it is 'option'.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `product_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `provisioning_target_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `weight` | `SMALLINT NOT NULL DEFAULT 100` | Per-product override of the target weight — fill the cheap node first. |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | Drain a node without deleting rows. |

**ایندکس:** `UNIQUE (product_id, provisioning_target_id)` · `INDEX (provisioning_target_id)`

### `ip_pools`

Static IP inventory behind a target. Shared with the provisioning area; listed here because option stock for 'extra IPv4' derives from it and a product cannot be sold if the pool is dry.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `provisioning_target_id` | `BIGINT UNSIGNED NOT NULL FK` | IPs belong to a node/cluster, not to a product. |
| `name` | `VARCHAR(80) NOT NULL` |  |
| `version` | `TINYINT UNSIGNED NOT NULL DEFAULT 4` | 4 \| 6 |
| `cidr` | `VARCHAR(43) NOT NULL` | '185.x.y.0/24' |
| `prefix` | `TINYINT UNSIGNED NOT NULL` |  |
| `gateway` | `VARCHAR(45) NULL` |  |
| `vlan` | `SMALLINT UNSIGNED NULL` |  |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `INDEX (provisioning_target_id, is_active)`

### `ip_addresses`

Individual addresses. The free count is the real stock behind the 'extra IP' option — this is what stops overselling the Iran Proxmox pool.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `ip_pool_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `address` | `VARBINARY(16) NOT NULL` | Packed form (inet_pton) — sorts and range-queries correctly for both v4 and v6. |
| `address_text` | `VARCHAR(45) NOT NULL` | Human/display form, so admin search does not need a function index. |
| `status` | `VARCHAR(10) NOT NULL DEFAULT 'free'` | free \| held \| assigned \| blocked (abuse/blacklisted). |
| `service_id` | `BIGINT UNSIGNED NULL` | FK added by the services area; nullable here to avoid a migration ordering deadlock. |
| `assigned_at` | `TIMESTAMP NULL` |  |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (ip_pool_id, address)` · `INDEX (ip_pool_id, status)` · `INDEX (service_id)`

### `domain_tlds`

Which TLDs ServerNet sells and their registry rules. Domains are NOT products — this is their catalog.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `tld` | `VARCHAR(24) NOT NULL UNIQUE` | Stored WITH the dot ('.com', '.ir', '.co.uk') — matches how the existing config and Whmcs service key them. |
| `registry` | `VARCHAR(48) NULL` | 'verisign', 'irnic' — informational. |
| `requires_handle` | `TINYINT(1) NOT NULL DEFAULT 0` | IRNIC .ir requires a registered handle before the domain can even be ordered. The resolver returns orderable=false with reason 'handle_required' if the customer has none. |
| `min_years / max_years` | `TINYINT UNSIGNED NOT NULL` | Registry limits, e.g. .ir is 1–5. |
| `whois_privacy` | `TINYINT(1) NOT NULL DEFAULT 0` | Not offered on .ir. |
| `transfer_supported` | `TINYINT(1) NOT NULL DEFAULT 1` |  |
| `idn` | `TINYINT(1) NOT NULL DEFAULT 0` | Persian IDN support. |
| `grace_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | Renewal grace before redemption. |
| `redemption_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | Drives the expiry warning schedule. |
| `is_active / is_featured / sort` | `TINYINT(1) / TINYINT(1) / INT` | is_featured drives the TLD strip on the search page. |
| `created_at / updated_at` | `TIMESTAMP NULL` |  |

**ایندکس:** `UNIQUE (tld)` · `INDEX (is_active, is_featured, sort)`

### `domain_tld_translations`

fa/en/tr marketing text for a TLD ('.shop — for online stores').

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `domain_tld_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `locale` | `VARCHAR(5) NOT NULL` | fa \| en \| tr |
| `headline` | `VARCHAR(120) NULL` |  |
| `description` | `VARCHAR(255) NULL` |  |

**ایندکس:** `UNIQUE (domain_tld_id, locale)`

### `domain_tld_prices`

INDICATIVE list prices for marketing pages and the TLD strip. is_display_only defaults to 1 — these are never what a customer is charged.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `domain_tld_id` | `BIGINT UNSIGNED NOT NULL FK CASCADE` |  |
| `currency_code` | `CHAR(3) NOT NULL FK` |  |
| `years` | `TINYINT UNSIGNED NOT NULL DEFAULT 1` |  |
| `register_price` | `BIGINT NULL` | Minor units. 'from' price shown on the TLD strip. |
| `renew_price` | `BIGINT NULL` | Displayed next to the register price by default — the site must always show renewal cost, because hiding it is the trap the owner already identified. |
| `transfer_price / restore_price` | `BIGINT NULL` |  |
| `is_display_only` | `TINYINT(1) NOT NULL DEFAULT 1` | Hard rule: if 1, the checkout refuses to charge from this row. Only a live DomainCheck can produce a chargeable domain quote. |
| `source` | `VARCHAR(10) NOT NULL DEFAULT 'manual'` | manual \| derived (computed nightly from the cheapest registrar cost + domain_pricing_rules). |
| `updated_at` | `TIMESTAMP NULL` | Stale display prices are flagged in admin after N days. |

**ایندکس:** `UNIQUE (domain_tld_id, currency_code, years)`

### `domain_pricing_rules`

How a live registrar cost becomes a ServerNet sell price with a guaranteed margin — including premiums, which are the arbitrage danger zone.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `domain_tld_id` | `BIGINT UNSIGNED NULL FK` | NULL = applies to all TLDs. Most specific rule wins, then priority. |
| `registrar_account_id` | `BIGINT UNSIGNED NULL` | FK owned by the domains area. NULL = any registrar. |
| `currency_code` | `CHAR(3) NOT NULL FK` |  |
| `markup_bp` | `INT NOT NULL DEFAULT 0` | Basis points on registration cost. 2000 = +20%. |
| `markup_fixed` | `BIGINT NOT NULL DEFAULT 0` | Flat minor-unit addition, applied after markup_bp. |
| `renewal_markup_bp` | `INT NOT NULL DEFAULT 0` | Separate, because a registrar's cheap first year must NOT propagate into our renewal price. |
| `min_margin` | `BIGINT NOT NULL DEFAULT 0` | Absolute floor in minor units. If cost+markup does not clear this, the price is raised to meet it. |
| `premium_markup_bp` | `INT NOT NULL DEFAULT 0` | Premium domains carry a different (usually thinner) markup. |
| `premium_max` | `BIGINT NULL` | Refuse to auto-sell a premium above this amount — it becomes a 'contact us' lead instead of an automated sale that might fail. |
| `priority` | `SMALLINT NOT NULL DEFAULT 0` |  |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` |  |

**ایندکس:** `INDEX (domain_tld_id, currency_code, is_active, priority)`

### `price_quotes`

The single gate between the catalog and money. Nothing is displayed as a buyable price and nothing is charged unless it came from a live, unexpired row here. This is the structural fix for the '$20 shown, registrar refuses to sell' bug.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `uuid` | `CHAR(36) NOT NULL UNIQUE` | What the cart/checkout passes around — never the numeric id. |
| `customer_id` | `BIGINT UNSIGNED NULL` | FK added by the customers area. NULL for anonymous browsing. |
| `session_key` | `VARCHAR(64) NULL` | Hashed session id for anonymous carts. |
| `kind` | `VARCHAR(12) NOT NULL` | product \| domain \| renewal \| upgrade. |
| `product_id` | `BIGINT UNSIGNED NULL FK products(id)` |  |
| `domain_tld_id` | `BIGINT UNSIGNED NULL FK domain_tlds(id)` |  |
| `domain` | `VARCHAR(255) NULL` | The exact domain quoted (punycode form). |
| `currency_code` | `CHAR(3) NOT NULL FK` |  |
| `period_unit / period_count` | `VARCHAR(6) / SMALLINT UNSIGNED` | For domains this is (year, N). |
| `quantity` | `SMALLINT UNSIGNED NOT NULL DEFAULT 1` |  |
| `setup_amount` | `BIGINT NOT NULL DEFAULT 0` | Minor units, tax-exclusive. |
| `recurring_amount` | `BIGINT NOT NULL` | First term, after promotions. |
| `renewal_amount` | `BIGINT NOT NULL` | NEVER NULL. What the next term costs. Displayed alongside the price on every product and domain card. |
| `total_amount` | `BIGINT NOT NULL` | setup + recurring × quantity. |
| `promotion_id` | `BIGINT UNSIGNED NULL FK promotions(id)` |  |
| `payload` | `JSON NOT NULL` | DELIBERATE JSON — the immutable resolved snapshot: every option id/qty/unit price, the module slug, the chosen target id, the feature list, the promo label in all three locales. Normalising it would let a later catalog edit silently rewrite history. |
| `source` | `VARCHAR(20) NOT NULL` | catalog \| registrar_live. Domain quotes with source='catalog' are never chargeable. |
| `upstream_ref` | `VARCHAR(191) NULL` | The registrar's own quote/idempotency token, replayed at purchase so we buy exactly what was quoted. |
| `honour_until` | `TIMESTAMP NOT NULL` | min(config quote TTL, registrar's honour window). Past this the cart re-quotes and visibly tells the customer if the price changed. |
| `consumed_at` | `TIMESTAMP NULL` | Set when an order line is created from it. A quote is single-use. |
| `order_id` | `BIGINT UNSIGNED NULL` | FK added by the billing area. |
| `created_at` | `TIMESTAMP NOT NULL` | Pruned after 30 days unless consumed. |

**ایندکس:** `UNIQUE (uuid)` · `INDEX (session_key, created_at)` · `INDEX (customer_id, created_at)` · `INDEX (honour_until)` · `INDEX (domain)`

### `stock_holds`

Short-lived reservations so two customers cannot buy the last IP, the last Windows licence or the last slot on a full node. Three nullable real FKs instead of a polymorphic reservable.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` |  |
| `price_quote_id` | `BIGINT UNSIGNED NULL FK price_quotes(id) CASCADE` | Holds are created when a quote is placed in the cart, not when it is browsed. |
| `order_id` | `BIGINT UNSIGNED NULL` | Promoted from quote to order; released on provision success or failure. |
| `product_id` | `BIGINT UNSIGNED NULL FK products(id)` | For stock_mode='tracked' products. |
| `option_id` | `BIGINT UNSIGNED NULL FK options(id)` | For tracked options. |
| `ip_address_id` | `BIGINT UNSIGNED NULL FK ip_addresses(id)` | Reserves a specific address; sets ip_addresses.status='held'. |
| `provisioning_target_id` | `BIGINT UNSIGNED NULL FK provisioning_targets(id)` | Reserves a capacity slot on a node. |
| `qty` | `INT NOT NULL DEFAULT 1` |  |
| `expires_at` | `TIMESTAMP NOT NULL` | Swept by a scheduled command; expiry releases the hold and the IP. |
| `released_at` | `TIMESTAMP NULL` |  |
| `created_at` | `TIMESTAMP NOT NULL` |  |

**ایندکس:** `INDEX (expires_at, released_at)` · `INDEX (price_quote_id)` · `INDEX (product_id)` · `INDEX (option_id)` · `INDEX (ip_address_id)`

## تصمیم‌های کلیدی

**Money is BIGINT in minor units plus a currency code, with the decimal exponent declared once per currency (IRT=0, EUR=2). Every amount column in every table is BIGINT. A `Money` value object is the only way to touch one, and it cannot be constructed from a float.**

Integer arithmetic is exact and Toman genuinely has no subunit — storing 490000.00 is noise that invites float casts. A single exponent lookup makes the same code correct on both installs. BIGINT also allows negative amounts for credits and refunds without a second type.

*رد شد:* DECIMAL(20,4) everywhere (PHP reads it back as a string and someone will (float) it — this project already has `(float) $price` in Whmcs.php), and 'always 2 decimals' normalisation (meaningless for Toman and makes the 10,000-Toman rounding rule unrepresentable).

**Sale prices are per-currency rows entered by the admin. FX rates are used ONLY for margin reporting against upstream cost and optional 'approx' display. No price a customer pays is ever computed from a rate.**

Iranian pricing is set against IranServer/ParsPack, not against the euro. A 20% rial move must not silently reprice the shop, and the owner must never discover a sale happened at a rate he did not approve. Cost, however, genuinely is in EUR for Hetzner/OVH even on the Iran install, so cost_price carries its own currency and fx_rates exists for the margin audit.

*رد شد:* A single master price list plus FX at checkout — cheaper to maintain, but it makes every rate spike a pricing incident and destroys the ability to price the two markets differently, which is the whole point of the two-server architecture.

**`products.kind` is a short string from a fixed, code-known set — not a `product_types` table.**

`kind` decides which control surface the customer gets: a VPS needs power/console/rescue, an SSL needs a CSR form, a domain needs DNS records. That is code no matter how it is stored. A types table would advertise a freedom that does not exist. What IS fully data-driven is the provisioning module, the option schema and the panel actions — the module declares its own `actions()`, so a new provider adds buttons without a new kind and without touching Blade.

*رد شد:* A `product_types` lookup table with a JSON field schema — it looks more flexible but every new type still needs a controller, a panel view and validation, so the table is a lie that hides where the real work is.

**`provisioning_modules` stores only a slug. The fully-qualified class name lives in `config/provisioning.php`.**

A class name in a database row is a remote-code-execution primitive: anyone with write access to one admin field, one SQL injection or one bad backup restore can instantiate arbitrary classes. Keeping the map in a git-tracked config file means adding a provider is a deploy, which it has to be anyway because the class must exist.

*رد شد:* A `class` VARCHAR column on the modules table (the WHMCS-style 'module name = filename' approach) — convenient, and exactly how module-loading vulnerabilities happen.

**JSON is used in exactly four places and nowhere else: `products.provisioning_profile`, `provisioning_targets.settings`, `provisioning_targets.credentials` (encrypted), and `price_quotes.payload`. Everything else — options, prices, cycles, stock, group membership, promotions, features — is real columns and real foreign keys.**

The first three are module-defined key sets validated by `profileSchema()`/`targetSchema()`; normalising them would mean a table per provider, i.e. a migration for every new provider, which is precisely what the design must avoid. Credentials cannot be columns because they are encrypted blobs. `price_quotes.payload` is an immutable historical snapshot — normalising it would let a later catalog edit rewrite what a customer was quoted. Every other candidate for JSON is queried, filtered, priced or joined, so it gets columns.

*رد شد:* A generic `attributes` JSON column on products (the standard shortcut) — it kills the ability to query 'which products use the Tehran node', 'which options are out of stock', and it makes the admin UI unbuildable without hand-written per-product forms.

**Every priced configurable thing is a row in `options`. A `quantity`-kind group has exactly one option row (the unit) and the number is captured per order line.**

It gives one uniform pricing path: `option_prices` always keys on `option_id`. 'Choose one OS from this list' is N option rows in a select group; 'add N extra IPs at X each' is one option row in a quantity group with min/max/step; 'pick a datacenter' is N option rows each carrying a real FK to a provisioning_target.

*رد شد:* A separate per-unit price on `option_groups` for quantity questions — it creates two code paths, two places to look for a price, and a class of bug where a group has both.

**Option prices are absolute per-unit amounts added to the base. There is no percentage-of-base option pricing.**

An invoice line must be reconstructible from stored numbers years later. Percentages compound unpredictably with promotions and with per-product base overrides, and the resulting invoice cannot be explained to a customer or an auditor. If the admin wants '+20%', the admin types the resulting number.

*رد شد:* Percent and multiplier price effects (WHMCS supports them) — they save typing once and cost clarity forever.

**`product_prices.renewal_price` is a first-class nullable column, and every `Quote` carries a non-null `renewal` amount that the UI must display.**

This is the direct fix for the commercial trap the owner identified. Permanent intro pricing ('cheap first year') is structural, not a time-bounded sale, so modelling it as a promotion is wrong: promotions expire, this does not. Making renewal mandatory in the Quote object means no template can accidentally omit it, and it forces the registrar comparison to compare total cost of ownership.

*رد شد:* Expressing intro pricing as a promotion with `recurring_terms = 1` — it works numerically but conflates two concepts, and a promo that is 'always on' is a lie in the admin UI.

**Prices are never derived across billing cycles at runtime. If there is no row for (product, currency, year, 1), that cycle is simply not sold. An artisan command `catalog:fill-prices --from=1_month --to=1_year --multiplier=10 --round` writes real rows the admin can then edit.**

Runtime derivation silently invents prices nobody approved, and the multiplier is a business decision (10× for a 2-month discount) that differs per product line. Materialised rows are auditable, individually overridable, and make the missing-price case an explicit 'not orderable' rather than a wrong number.

*رد شد:* Deriving annual as monthly × 12 × (1 − yearly_discount) at render time — which is what the current `site_price_yearly()` helper does, and which is fine for a marketing page but unacceptable once it charges a card.

**Nothing is displayed as a buyable price or charged unless it came from a `price_quotes` row that is unexpired and unconsumed. The resolver returns `orderable = false` with a machine-readable reason instead of a price it cannot honour.**

This is the structural kill for the '$20 → $2 → registrar refuses' bug. If the registrar will not bind (`DomainCheck::$bindable === false`), if the renewal cost is unknown, if the premium exceeds `premium_max`, if the node has no capacity, or if the IP pool is dry — the customer sees 'unavailable' or 'contact us', never a number. Quotes carry the registrar's own `upstream_ref`, which is replayed at purchase, so we buy exactly what was quoted or fail loudly.

*رد شد:* Re-pricing at checkout (the usual approach) — it turns every upstream price change into either an angry customer or a silent margin loss, and it is what allows a shown price to differ from a charged price.

**Domains are not rows in `products`. They get `domain_tlds` + display-only `domain_tld_prices` + `domain_pricing_rules`, and are priced live per-domain.**

A product row per TLD means ~500 rows × 3 translations × N price rows describing a price we do not control anyway, and it would tempt the checkout to charge from the TLD list — the exact premium-domain bug. The order-line schema (billing area) must therefore accept a `domain_tld_id` line as well as a `product_id` line; that is a one-column concession in exchange for never having a stale domain price in the catalog.

*رد شد:* Modelling each TLD as a product with `kind='domain'` (the WHMCS shape) — it makes the storefront uniform at the cost of institutionalising the bug being fixed.

**One translation table per translatable entity, each with `UNIQUE (parent_id, locale)` and the same fa→en→fa fallback the existing `lc()` helper uses, plus a `catalog:lint` command that fails if any active row is missing a locale.**

It keeps real foreign keys, real indexes, real column types and real validation, and it matches the `post_translations` pattern already proven in this codebase. The count of tables goes up; the count of surprises goes down.

*رد شد:* A single polymorphic `translations(translatable_type, translatable_id, locale, field, value)` EAV table — one table instead of eight, but it destroys type safety, makes 'list active products ordered by Persian name' a nightmare, and cannot be validated at the database level.

**`options.provisioning_target_id` and `options.ip_pool_id` are real foreign keys rather than a generic (`stock_source_type`, `stock_source_id`) pair.**

'Pick a datacenter' and 'add extra IPs' are the two option types that consume physical inventory, and both are known now. Real FKs give cascade behaviour, referential integrity and a joinable stock query. A third inventory type later costs one nullable column, which is cheaper than a polymorphic indirection that nothing can join through.

*رد شد:* A polymorphic stock source — flexible on paper, unqueryable in practice.

**Products are soft-deleted and have a five-state lifecycle (draft / active / hidden / retired / discontinued); tax rates live in the billing area and the catalog stores only `products.tax_class` as a string.**

'Stop selling this but keep renewing the 300 customers on it' is a weekly operational need that a boolean `is_active` cannot express, and hard-deleting a product with live services corrupts history. Tax jurisdictions (Iranian VAT vs EU VAT/OSS/reverse charge) are a genuinely separate problem with its own tables; a string key is the right seam.

*رد شد:* A single `is_active` boolean plus hard delete, and owning tax rates inside the catalog.

## ریسک‌ها

**'Admin can add any service type without code' is only partly true, and the owner may be planning around the wrong boundary. Adding a product, plan, option, price, promo, datacenter or server is pure data. Adding a genuinely new PROVIDER (a panel or cloud API nobody has integrated) is one new PHP class plus a deploy — it can never be a form in the admin panel, because it means writing HTTP calls against someone else's API.**

→ Make the class the only thing needed: `ProvisioningModule::profileSchema()`/`targetSchema()` generate the admin forms, `actions()` generates the customer's power/reboot/console panel, so the new provider touches no migration, no Blade, no controller. Ship a `make:provisioning-module` scaffolder and a contract test suite so a new provider is a half-day job. Be explicit with the owner that 'no code changes' means 'no code changes to the system', not 'no code at all'.

**Renewal-aware registrar comparison depends on renewal prices that registrar APIs expose inconsistently or not at all. If renewal is unknown, first-year comparison silently returns — and the trap the owner explicitly identified comes straight back.**

→ `DomainCheck::$renewCost` is nullable and `RegistrarSelector::pick()` treats a null as INELIGIBLE, not as zero. `Quote::$blockedReason = 'renewal_unknown'` means the domain is simply not offered from that registrar. Total cost of ownership is compared over a configurable horizon (default 3 years), and the renewal figure is stored on the quote so a later registry price rise is visibly our loss, not a surprise bill to the customer.

**Catalog prices are static while upstream costs are not. Hetzner raises prices, the rial moves 40% in a fortnight, a registry doubles a TLD — and every affected product quietly sells below cost until someone notices at month end. With a low-margin, compete-on-price strategy this is the most likely way to lose real money.**

→ `cost_price` + `cost_currency_code` on both `product_prices` and `option_prices`, `fx_rates` history, and a scheduled `catalog:margin-audit` that emails and dashboards any SKU whose margin drops below a per-product threshold — plus an optional hard mode that flips such products to `status='hidden'` automatically rather than selling at a loss overnight.

**Capacity counters on `provisioning_targets` drift from reality (a service deleted directly on the node, a failed terminate, a manual account). Selling the last slot on a 'full' node fails the order after payment, which is a refund, a ticket and a bad review.**

→ `ReportsCapacity` reconciliation on a schedule writes `capacity_checked_at` and auto-sets `status='full'`. The denormalised counter is advisory: when remaining capacity is at or below a configured buffer, the allocator queries the module live before allowing the sale. Provisioning failure after payment must automatically issue account credit and open a ticket — never leave the customer to notice.

**Toman inflation forces periodic mass repricing, and Rial/Toman confusion is already latent in this codebase (`Whmcs::tldPricing()` returns Rial and `whmcs_price()` renders 'ریال', while `site_price()` renders 'تومان'). A ×10 error during migration is a business-ending invoice.**

→ The `currencies` row is 'IRT', exponent 0, meaning Toman — documented in the migration and asserted in a test. Any importer from WHMCS must divide by 10 explicitly and is covered by a fixture test using a known real invoice. `catalog:reprice --group=… --percent=…` plus `rounding_step` handles bulk increases so nobody hand-edits 400 rows.

**Option combinatorics. 40 VPS products × 12 OS × 6 datacenters × 8 addons is a temptation to precompute a SKU per combination, which explodes the catalog and makes a price change a 30,000-row update.**

→ Prices are always resolved, never precomputed: base row + Σ(option row × qty), snapped once at the end. The only materialised artefact is the `price_quotes` snapshot, which is per-customer-intent and pruned after 30 days.

**SQLite cannot carry this. The design uses generated columns in unique indexes (`option_prices.product_key`), `SELECT … FOR UPDATE` for stock holds and promotion redemptions, and concurrent writes during checkout. CLAUDE.md already records `database is locked` under load with `busy_timeout` unset.**

→ MySQL 8 or MariaDB 10.6+ is a prerequisite, not an option — decide before the first catalog migration runs, as CLAUDE.md itself warns. Use `utf8mb4` with `utf8mb4_unicode_ci`; verify Persian product-name sorting on real data before committing to a collation. Move cache/session/queue off the database at the same time.

**Free trials plus crypto payment on the .cloud install is a mining and abuse farm. `trial_days` on a VPS product with an anonymous crypto payer is free compute for anyone with a script.**

→ `max_per_customer` on every product, `trial_days = 0` by default and blocked entirely for `kind='vps'` unless the customer is KYC-verified and paid by card, and `first_order_only`/`new_customers_only` on promotions enforced against the verified identity, not the email address. Flag to the owner that trial VPS on the crypto install should probably never be enabled.

**The public site already advertises hourly billing ('پرداخت ساعتی — delete the server and billing stops'). The catalog can express `period_unit='hour'`, but honouring that promise needs continuous usage metering, hourly aggregation and a credit-balance model — none of which is a catalog feature, and all of which is significant billing work.**

→ Either scope hourly down to 'delete anytime, unused time returned as account credit' (which the catalog and a prorated-credit rule already support), or remove the claim from the marketing pages until real metering exists. Raise this with the owner explicitly — the promise is live on the site today.

**Editing a product or price must never change what an existing customer pays, but the natural implementation (join to `product_prices` at renewal time) does exactly that.**

→ Services store their own immutable price snapshot at order time, copied from the `price_quotes` payload; renewal reads the snapshot, not the catalog. A deliberate 'apply new pricing to existing services' admin action exists, is scoped, requires confirmation, and notifies affected customers — it is never a side effect of editing a price.

**Two independent databases mean the catalog will drift: a product exists on .ir but not on .cloud, options diverge, and cross-install reporting becomes guesswork.**

→ `products.external_key` gives the same logical product a shared identity across installs, and `catalog:export` / `catalog:import` move structure and translations (never prices, never credentials) so the German install can be seeded from the Iranian one and diffed against it. A `catalog:diff` report against the other install's export is a cheap early-warning.

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Contracts\Provisioning;

use App\Models\ProvisioningTarget;
use App\Models\Service;

/**
 * THE contract. Adding a new hosting panel, cloud provider or registrar means writing
 * exactly one class implementing this — no migration, no Blade, no change to the
 * catalog, cart, billing or customer panel.
 *
 * The class is registered in config/provisioning.php:
 *     'proxmox' => \App\Provisioning\ProxmoxModule::class,
 * The database (provisioning_modules.slug) only ever stores the slug. A class name
 * is NEVER read from the database.
 */
interface ProvisioningModule
{
    public static function slug(): string;

    /**
     * Field descriptor for products.provisioning_profile so the admin product form
     * renders itself with zero code:
     *   [['key'=>'package','type'=>'remote_select','source'=>'packages','required'=>true],
     *    ['key'=>'cores','type'=>'int','min'=>1,'max'=>64,'required'=>true]]
     * Types: string|int|bool|select|remote_select|secret|json.
     */
    public function profileSchema(): array;

    /** Same descriptor language, for provisioning_targets.settings + credentials. */
    public function targetSchema(): array;

    /** Live values for a 'remote_select' field: WHM packages, Proxmox templates, Hetzner server types. */
    public function remoteOptions(ProvisioningTarget $target, string $source): array;

    /**
     * option_groups.provisioning_key values this module understands,
     * e.g. ['os','datacenter','extra_ipv4','control_panel','backup','ssh_key'].
     * catalog:lint fails the build if a product attaches a group this module ignores.
     */
    public function supportedOptionKeys(): array;

    public function test(ProvisioningTarget $target): HealthReport;

    public function create(ProvisionRequest $request): ProvisionResult;

    public function suspend(Service $service, string $reason): ProvisionResult;

    public function unsuspend(Service $service): ProvisionResult;

    public function terminate(Service $service): ProvisionResult;

    public function changePlan(Service $service, ProvisionRequest $target): ProvisionResult;

    /**
     * Everything the customer may do to this service, declared by the module.
     * The panel renders buttons from this array — power on/off, reboot, reinstall,
     * console, rescue mode, snapshot, rDNS, change password. A new provider with a
     * new capability needs no view change.
     *
     * @return ServiceAction[]
     */
    public function actions(Service $service): array;

    public function invoke(Service $service, string $actionId, array $params): ActionResult;

    public function usage(Service $service): ?UsageReport;
}
```

```php
<?php

namespace App\Contracts\Provisioning;

/**
 * Declared by the module; consumed by the catalog (stock), the allocator (placement)
 * and the customer panel (rendering). labelKey MUST exist in all three lang/{fa,en,tr}/ui.php.
 */
final readonly class ServiceAction
{
    public function __construct(
        public string $id,                    // 'power_on', 'reinstall', 'console'
        public string $labelKey,              // 'ui.svc_power_on'
        public string $icon = 'play',
        public bool $danger = false,          // red styling + typed confirmation
        public bool $confirm = false,
        public array $params = [],            // same descriptor language as profileSchema()
        public bool $available = true,
        public ?string $unavailableReasonKey = null, // 'ui.svc_unavailable_suspended'
    ) {}
}

final readonly class ProvisionRequest
{
    public function __construct(
        public int $productId,
        public int $targetId,
        public array $profile,       // products.provisioning_profile, already validated
        public array $options,       // provisioning_key => ['value' => string, 'qty' => int]
        public ?int $customerId,
        public ?string $domain,
        public string $idempotencyKey, // replay-safe; providers get charged for duplicates
    ) {}
}

final readonly class ProvisionResult
{
    public function __construct(
        public bool $ok,
        public ?string $remoteId = null,     // provider's own id, stored on the service
        public array $credentials = [],      // encrypted at rest by the service layer
        public array $meta = [],
        public ?string $errorCode = null,    // machine-readable: 'no_capacity','quota','auth','upstream'
        public ?string $errorMessage = null, // operator-facing, never shown raw to the customer
        public bool $retryable = false,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Provisioning;

use App\Models\ProvisioningTarget;

/**
 * Optional. Implemented by modules that can tell us how full a node is.
 * Without it, provisioning_targets capacity counters are maintained only by our own
 * bookkeeping and will drift — see risks.
 */
interface ReportsCapacity
{
    public function capacity(ProvisioningTarget $target): CapacityReport;
}

final readonly class CapacityReport
{
    public function __construct(
        public ?int $accountsUsed = null,
        public ?int $accountsTotal = null,
        public ?int $ramUsedMb = null,
        public ?int $ramTotalMb = null,
        public ?int $diskUsedGb = null,
        public ?int $diskTotalGb = null,
        public ?int $vcpuAllocated = null,
        public ?int $vcpuTotal = null,
        public ?int $freeIpv4 = null,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Provisioning;

use App\Models\ProvisioningTarget;
use App\Models\Product;

interface TargetAllocator
{
    /**
     * Pick the node/pool a service will land on.
     * MUST NOT return an over-committed target. Throws NoCapacityException, which the
     * PriceResolver turns into Quote::$orderable = false rather than a failed sale.
     *
     * @param  array<string,array{value:string,qty:int}>  $selectedOptions keyed by provisioning_key
     */
    public function allocate(Product $product, array $selectedOptions): ProvisioningTarget;

    /** @return ProvisioningTarget[] Eligible, non-full targets — used for stock display. */
    public function eligible(Product $product, array $selectedOptions): array;
}
```

```php
<?php

namespace App\Support;

/**
 * The ONLY money type in the system. Integer minor units + currency code.
 * There is no float and no DECIMAL money anywhere. Constructing from a float is
 * impossible by design.
 */
final readonly class Money implements \JsonSerializable
{
    private function __construct(
        public int $minor,        // 490000 IRT = 490,000 Toman ; 1290 EUR = 12.90
        public string $currency,  // 'IRT' | 'EUR'
    ) {}

    public static function of(int $minor, string $currency): self;

    public static function zero(string $currency): self;

    /** @throws \InvalidArgumentException on currency mismatch — never silently converts. */
    public function plus(self $other): self;

    public function minus(self $other): self;

    public function times(int $quantity): self;

    /** Basis points, integer half-up. percent(2500) = 25.00%. */
    public function percent(int $basisPoints): self;

    /** Snap to currencies.rounding_step (IRT 10,000 / EUR 1), half-up. */
    public function snap(int $step): self;

    public function isZero(): bool;

    public function isNegative(): bool;

    /** fa -> fa_num() + __('ui.cur_IRT'); en/tr -> symbol per currencies.symbol_before. */
    public function format(?string $locale = null): string;

    /** @return array{minor:int,currency:string} — the only serialised form. */
    public function jsonSerialize(): array;
}
```

```php
<?php

namespace App\Contracts\Catalog;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The catalog's public face. Every price shown anywhere on the site — product card,
 * cart, upgrade estimate, domain result — comes from here and is persisted as a
 * price_quotes row. Checkout may only charge from an unexpired, unconsumed quote.
 */
interface PriceResolver
{
    /** Never throws for a business reason; returns an unorderable Quote with a reason instead. */
    public function quote(PriceRequest $request): Quote;

    /** Cheapest orderable quote across cycles — for 'from X /mo' badges. Still persisted. */
    public function cheapest(int $productId, string $currency): ?Quote;

    /** Re-resolve an existing quote; used when honour_until lapses in the cart. */
    public function requote(string $uuid): Quote;
}

final class PriceRequest
{
    public function __construct(
        public readonly ?int $productId,
        public readonly string $currency,
        public readonly string $periodUnit,   // hour|day|month|year|once
        public readonly int $periodCount,
        /** @var array<int,int> option_id => quantity */
        public readonly array $options = [],
        public readonly int $quantity = 1,
        public readonly ?string $promoCode = null,
        public readonly ?int $customerId = null,
        public readonly ?string $domain = null,   // kind=domain
        public readonly ?int $domainTldId = null,
    ) {}
}

final readonly class Quote
{
    public function __construct(
        public string $uuid,
        public bool $orderable,
        /** null when orderable; otherwise: out_of_stock|no_capacity|no_price|cycle_unavailable|
         *  option_conflict|registrar_refused|premium_too_high|handle_required|renewal_unknown */
        public ?string $blockedReason,
        public Money $setup,
        public Money $recurring,      // first term, after promotion
        /** ALWAYS present. What the next term costs. Rendered next to every price. */
        public Money $renewal,
        public Money $total,
        /** @var QuoteLine[] base line + one per option + promo line, all auditable */
        public array $lines,
        public CarbonImmutable $honourUntil,
        public ?int $targetId = null,
        public ?string $upstreamRef = null,
    ) {}
}

final readonly class QuoteLine
{
    public function __construct(
        public string $type,          // base|option|promo|setup
        public ?int $optionId,
        public int $quantity,
        public Money $unitPrice,
        public Money $unitRenewalPrice,
        public Money $lineTotal,
        /** @var array<string,string> locale => label, so the invoice can be rendered in fa/en/tr later */
        public array $labels,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Domains;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Implemented once per registrar (OpenProvider, CentralNic, IRNIC…).
 *
 * HARD RULE encoded in this interface: the price a customer is shown and charged
 * comes from check(), never from tldPrice(). tldPrice() is marketing copy.
 * The current Whmcs::tldPricing() reads register[1] from a TLD list — doing that for
 * a premium domain is exactly the bug that must not be reintroduced.
 */
interface DomainPriceSource
{
    public function slug(): string;

    /** Authoritative, per-domain, binding-if-$bindable answer. */
    public function check(string $domain, int $years = 1): DomainCheck;

    /** Indicative list price for a whole TLD. Display only — never chargeable. */
    public function tldPrice(string $tld, int $years = 1): ?DomainTldPrice;

    /** Replays a previous quote. MUST fail loudly if the registrar will not honour $upstreamRef. */
    public function register(string $domain, int $years, array $contacts, ?string $upstreamRef): DomainOrderResult;
}

final readonly class DomainCheck
{
    public function __construct(
        public string $domain,
        /** available | premium | taken | reserved | restricted | error
         *  The UI has exactly three states: available / premium-or-special / not available.
         *  'error' maps to 'not available' — we never guess. */
        public string $status,
        /** Registrar COST including any premium. Our sell price = cost + domain_pricing_rules. */
        public ?Money $registerCost,
        /** Registrar renewal cost. If null, the comparison MUST treat this registrar as
         *  ineligible — comparing first-year price alone is the commercial trap. */
        public ?Money $renewCost,
        public ?Money $transferCost,
        public int $minYears,
        public int $maxYears,
        /** false => registrar quoted a price it will not actually sell at. We do not list it. */
        public bool $bindable,
        public ?string $upstreamRef,
        public CarbonImmutable $honourUntil,
        /** e.g. ['irnic_handle'] for .ir — blocks the order before payment, not after. */
        public array $requiredContactFields = [],
    ) {}
}
```

```php
<?php

namespace App\Contracts\Domains;

use App\Support\Money;

/**
 * Picks which registrar to sell a domain through. Deliberately takes the whole set of
 * DomainCheck results so it can compare TOTAL cost of ownership, not first-year price.
 */
interface RegistrarSelector
{
    /**
     * @param  DomainCheck[]  $candidates  one per registrar that answered
     * @param  int  $horizonYears  compare over this many years (config, default 3)
     * @return DomainCheck|null  null => do not offer this domain for sale at any price
     */
    public function pick(array $candidates, int $horizonYears = 3): ?DomainCheck;

    /** register + (horizon-1) x renew, per candidate — what 'cheapest' actually means. */
    public function totalCostOfOwnership(DomainCheck $check, int $horizonYears): ?Money;
}
```

