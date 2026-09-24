# ServerNet M5 — AI Token Resale: Final Merged Specification

**Base proposal:** `money-safety` (weighted judge totals — no_loss ×2: money-safety **150**, ship-fast **136**, product **129**; unanimous winner).
**Grafts:** `product` (streaming, api host, transparency, MariaDB traps, balance-aware caps, review queue), `ship-fast` (milestone slicing, fail-closed margin, content-verified deploy checks).
**Worktree root:** `C:\wt-ai\website` (branch `feature/ai-gateway-money`, rebased on current `origin/develop`). All file paths below are repo-relative because the deploy scripts are; prefix them with `C:\wt-ai\` to resolve locally.

Facts verified in the tree before writing (not taken from the proposals):

| Claim | Verified at |
|---|---|
| `brick/math` 0.18.0 already a dependency | `C:\wt-ai\website\composer.lock:10-11` |
| B1 live: µUSD passed as the Toman reservation amount | `C:\wt-ai\website\app\Services\Ai\AiCaller.php:151,158-160` |
| `settle()` refuses any reservation past `expires_at` | `C:\wt-ai\website\app\Services\Ai\AiReservations.php:172-177` |
| `Wallet::reservedOf()` delegates to `AiReservation::scopeHolding()` — so the hold semantics can change **without touching `Wallet.php`** | `app\Services\Finance\Wallet.php:59-70`, `app\Models\AiReservation.php:56-60` |
| `Wallet::debit(..., bool $requireAvailable = true, ?int $excludingReservationId = null)` already exists | `app\Services\Finance\Wallet.php:102-110` |
| `001200` and `001300` both open with `if (Schema::hasTable(...)) return;` — the root cause of the half-build | both migration files, line 1 of `up()` |
| `001400_repair_ai_gateway_fks.php` **already exists on the branch** and is per-step guarded | `website\database\migrations\2026_11_02_001400_repair_ai_gateway_fks.php` |
| `ai_model_unit_prices` has `price_micro_units`, `currency_code`, `unit`, `billing_unit`, `version`, `active`, nullable provider/model/customer scope | `2026_11_02_000100_...:110-148` |
| `ai_providers` has `commercial_enabled`, `resale_allowed`, `agreement_status`, `live_calls_enabled`, `billing_currency_code` | same file, lines 39-64 |
| `scripts/lang-apply-keys.php` exists **only** in `C:\wt-credit\scripts` — absent from `wt-ai` and `wt-legacy` | directory listing |

---

## §0 Fatal-flaw disposition (every flaw any judge raised)

| # | Flaw (judge) | Disposition |
|---|---|---|
| **MS-1** | C6 cap path sells below cost (J1) | **Fixed.** Charge is no longer capped at `H`. Excess over the hold is charged as a second debit from available balance (`ai_usage_overage`), the model auto-suspends, and only a genuinely uncollectable balance becomes `uncollected_irt`. **Rebuttal on the residue:** the red line is a *pricing* rule — `S ≥ cost_irt` holds unconditionally by the proof in §3. An uncollectable receivable from an abandoned wallet is a credit event, not a below-cost sale. Output cannot overrun because `max_tokens` is forced; only input tokenisation can, and §3's byte bound makes that a provable ceiling. |
| **MS-2** | `expires_at=NULL` ⇒ if `ai:reconcile` dies, funds lock forever (J1, J2) | **Fixed, two independent mechanisms.** (a) **Read-side backstop in `AiReservation::scopeHolding()`** (no cron, no `Wallet.php` change — verified the scope is the only input to `reservedOf`): a NULL-expiry pending row stops holding money after `ai.hold_backstop_h` (24 h). (b) **Opportunistic inline sweep**: every reserve settles/releases that same customer's own rows past `decide_by` inside the lock it already holds, so the hot path itself reconciles. The cron becomes an optimisation, not a dependency. Alerts fire at `decide_by + 10 min`, 23 h before the backstop. |
| **MS-3** | D16's stated rationale is wrong — the seeded provider is disabled, so the driver fix alone cannot switch the path on (J1) | **Rebuttal accepted; rationale corrected.** Verified: `2026_11_02_000100_...:43,54` seeds `enabled=false`, `live_calls_enabled=false`. The driver migration is still shipped **with** M5.1b, but for the correct reason: `AiCaller` and the driver contract change in that commit, and a driver string pointing at a class whose signature changed is a half-ship. |
| **MS-4** | Harshest unknown-outcome policy; a timeout can bill ~15× the call's value (J1, J3) | **Fixed within the owner's override.** Owner mandates charging real cost, cap at the reservation. We add **late-usage recovery** (§4.G): the hold is held for `ai.unknown_recovery_window_min` (15 min) while up to 3 probes run; only then is the cap charged, and a 7-day recovery pass **auto-refunds down to real cost** if usage is later obtained. Plus `/admin/ai/review` one-click refund (grafted from `product`). Drain-on-disconnect (§4.S) means the common streaming case yields *exact* usage, not a cap. |
| **MS-5** | No streaming — breaks the OpenAI SDKs, LangChain, Open WebUI, Cursor (all three judges) | **Fixed.** Owner override: streaming is a launch blocker. Full SSE design in §4.S / §5 / §7; milestone **M5.3** ships before the first sale, gated on a live Apache stream probe. |
| **MS-6** | Byte-bound hold too large; small wallets get 402 on everything (J2) | **Fixed.** Grafted `product` P3 **balance-aware `max_tokens`**: when the client omitted `max_tokens` and the hold exceeds available balance, `O` is lowered to what the wallet affords, floor 256 tokens, else 402. The 402 body states the required amount. |
| **MS-7** | ~60-column `ai_usage` in one hand-run migration; M5.1 too large (J2, J3) | **Fixed.** M5.1 is split into **M5.1a** (pricing/FX services + admin preview, no hot-path change) and **M5.1b** (schema + hold/settle). `ai_usage` is trimmed to 47 columns (3 boolean flags collapsed to `needs_review` + `review_reason`). **Rebuttal on row size:** InnoDB DYNAMIC's 8126-byte limit is not near — 34 integers + 4 timestamps + `char(26)`+`char(64)` + varchars ≤120 is under 900 bytes. |
| **MS-8** | Global in-flight cap of 8 is a business ceiling (~10-20 calls/min company-wide) (J3) | **Fixed.** Grafted `product` P1: `api.servernet.cloud` on its own PHP-FPM pool (M5.3). `ai.inflight_global` becomes config, default 8 until the pool exists, then set to `max_children − 4`. Streaming also removes the read-timeout worker pile-up that made 8 necessary. |
| **P-1** | (Risk on a grafted idea) The api host needs hand-built infra; `SetEnvIf` has silently no-opped here before | **Mitigated.** The `.htaccess` line is hand-added by the owner via cPanel File Manager (**never deploy `public/` root files** — memory), and the launch is **gated** on `curl -N -D-` against `/admin/ai/stream-probe` proving no `Content-Encoding` and ~1 s inter-event timing. If it buffers, launch waits for a WHM `flushpackets` include. |
| — | `product`'s `allowOverdraft` on the shared wallet (all judges) | **Not grafted.** The one IRT wallet also pays domains, servers and reseller credit; its non-negativity is structural. Overage is a separate `requireAvailable` debit that can fail cleanly. |
| — | `product`'s stored sell prices + hourly `ai:reprice` (all judges) | **Not grafted.** Repeats `hourly-locked-rate-underwater` and `empty-queue-looks-like-done`. D1 keeps one formula, no job. |
| — | `product`'s `bytes/2` estimator (J1, J2, J3) | **Not grafted.** Not a bound. §3 uses ≤1 token per UTF-8 byte. |
| — | `ship-fast`'s 300 s TTL with late settle into `uncollected_irt`; no in-flight cap; cost rounded to whole µUSD before margin | **Not grafted.** D7 keeps holds until decided; D15 keeps caps; §3 computes `cost_irt` from the exact `N`. |

---

## §1 Decisions D1–D16

| # | Choice | Why |
|---|---|---|
| **D1** | **Convert per call, at reserve time.** From the live FX rate compute integer Toman per 1M tokens (`P_in`, `P_cached`, `P_out`) and freeze them on the `ai_usage` row with `R`, `fee_bp`, `margin_bp`, `vat_bp` and the cost price-row ids. The same `AiPricing` function renders `/ai`, model pages, `/v1/models`, the portal **and** prices the call. No stored sell-price table, no re-price job. | One code path, one snapshot: every charge is recomputable (`ai:explain`), price and cost cannot drift apart, and there is no scheduled job whose silent death leaves published prices below cost (`hourly-locked-rate-underwater`). The customer can verify any bill as `tokens × P ÷ 1e6`. |
| **D2** | **`AiFx::quote(C)` reads cache only** — never `toToman()`/`refresh()`. `R = max(owner override, scraped rate ≤24 h old)`; 6-24 h old adds `fx_stale_buffer_bp` (200) + `noteOnce`; nothing usable ⇒ **503 `fx_unavailable`** and selling stops. Ratchet: `R` may not fall more than `fx_max_daily_drop_bp` (300) below the 24 h high-water mark in `Setting ai_fx_hw_{cur}`. Override validation tightened to 20,000–5,000,000. | `max()` means a forgotten low override can never underprice. Only a *drop* is damped, because only a drop can push price below cost — so a mis-parsed or manipulated scrape can only ever overcharge, bounded at 3 %/day. Cache-only keeps a live scrape off the paid hot path. |
| **D3** | **Margin is a number the owner types**: `Setting ai_margin_pct` (required, >0, ≤500, 2 decimals parsed as a string into bp — no float), optional per-model `ai_models.margin_bp`. Per-provider overhead lives in a **column**, `ai_providers.fx_fee_bp` (0-2500); `NULL` ⇒ provider not sellable. **No cloud-style default of 45** — unset margin ⇒ 503 `pricing_incomplete`. | The owner has refused hard floors. A column, not a Setting key, because `SettingsController::FIELDS` is a per-tab whitelist that silently drops unlisted keys. Failing closed means an unset setting can never become a sale at cost (`ship-fast`). |
| **D4** | **Settle real usage × frozen `P` in one transition**; the unused hold closes in the same commit. Cached tokens billed at their own `P_cached` when a `cached_input` row exists (validated `≤ P_in`), else at `P_in`. Reasoning tokens are inside `completion_tokens` — recorded for display, never charged twice. Usage above the bound ⇒ charge the real amount, excess as a second `ai_usage_overage` debit, model auto-suspends. Usage unknown ⇒ recovery then cap (D7). Cost basis `= max(book, provider-reported)`. | One ledger row per call equals the usage the customer sees. Charging the real amount on overrun honours "never absorb"; auto-suspension bounds exposure to one model. `max(book, reported)` catches a stale price row, the likeliest silent loss. |
| **D5** | **Integers only, every rounding UP**, via `Brick\Math\BigInteger` with a checked `toInt()`. `P` ceils to 1 Toman per 1M; the per-call amount ceils to whole Toman (no minimum, zero usage charges 0); VAT ceils. The IRT `rounding_step` is never applied per call. `PriceBook::chargeMicros` leaves the money path. | Every step moves the charge up, giving the provable floor `S_book ≥ S_floor ≥ cost_irt`. Bias is under 1 Toman per call. brick/math 0.18 is already in `composer.lock`, so no bcmath and no overflow reasoning. |
| **D6** | **Streaming ships at launch** (owner override). `stream:true` returns a `StreamedResponse`; `stream_options.include_usage` is forced upstream; settlement happens at end of stream before the usage chunk and `[DONE]`; on client disconnect we keep draining upstream and charge **exact** generated tokens. `n>1`, `best_of`, non-text content parts still 400. | Streaming is the default call shape of the OpenAI SDKs, LangChain, Open WebUI, Cursor and Continue; it is also the only way a long generation survives the Apache timeout. Draining on disconnect is both exact and no-loss, because the provider bills the full generation regardless. |
| **D7** | **Failure is classified by whether the provider may have done billable work.** Never sent ⇒ release. Provider 4xx/5xx ⇒ release (401/402/403 also auto-pause the provider). Sent, outcome unknown ⇒ **hold is kept**, up to 3 recovery probes over 15 min, then charge up to the cap (`unknown_charged`), with a 7-day recovery pass that **auto-refunds down to real cost**. M5 holds carry `expires_at = NULL` and are driven by `decide_by`, with a 24 h read-side backstop and an inline sweep. | The owner forbids absorbing upstream cost, and the hold is the provable upper bound of it. Keeping the hold until a decision removes the window in which a lapsed hold is spent elsewhere, so the wallet can never go negative and a settle can never be refused as "expired". |
| **D8** | **`Idempotency-Key`** optional, ≤80 chars of `[A-Za-z0-9._:-]`, bound to `sha256` of the canonical request JSON (recursively key-sorted). Same key+body after success ⇒ replay stored response 24 h, no upstream call, no charge. In flight ⇒ 409. Released/failed before send ⇒ key freed. Different body ⇒ 422. Expired ⇒ 409. `unknown_charged` ⇒ 409 `upstream_outcome_unknown`. | The standard client retry contract. It also covers Cloudflare's 100 s 524: a retry with the same key returns the paid answer instead of buying it twice. |
| **D9** | **One IRT wallet; € is display only.** `€ = Toman ÷ R_eur`, where `R_eur` is the same rate EUR top-ups convert at, read from cache only. Ceil to 4 decimals per 1M and 6 decimals per call. Hidden when unknown — never guessed. No EUR wallet, no € receipts. | An en/tr customer's euros become Toman at exactly that rate, so the € shown is what the call really costs them. A second wallet would break the one-wallet requirement and the reservation arithmetic. |
| **D10** | **VAT is added ON TOP of the published ex-VAT price, per call** (owner override). `vat_bp` is snapshotted with a `vat_basis`. **Liability rule: VAT applies unless `customers.country_code` holds a non-IR ISO code.** Locale never *creates* an exemption. `T = ceil(S·t/1e4)`; `charged = S + T`. VAT posts to the business ledger as `tax_collected`, never as revenue. | Under-collecting VAT is the liability; over-collecting is refundable. Absence of a declared country cannot manufacture an exemption, and an Iranian customer browsing in English still pays. The cost-floor assertion uses `S`, net of VAT, so a thin margin is never eaten by tax. |
| **D11** | **Hourly cloud stays out of M5.** The `BusinessReport` hourly side block is untouched and AI rows are never added to it. | Keeps the blast radius small and avoids the double-count that `credit-spend-records-no-revenue` warns about. |
| **D12** | **Budgets hard-enforced inside the reserve lock**: settled `charged_irt` in the window + pending holds + the new hold ≤ `monthly_budget_irt`, else 402. Window computed on read via `AiProject::budgetWindowFor(now)` (the stored-window admission gate is dropped, removing the month-end lockout). Token `daily_spend_cap_irt` per Tehran day; the reseller-cap fallback is not used for AI. Budgets stored in Toman for everyone. | The budget chip is a promise, and for keys leaked into client apps it is the main protection. Counting holds means concurrent calls cannot overshoot. Computing on read needs no cron to stay correct. |
| **D13** | **Price rows stay cost-only**; the sell price is always derived. A model is sellable only with **model-level** active `input` and `output` rows (no provider/global fallback), `basis = 1m_tokens`, `currency ∈ {USD,EUR}` **and equal to the provider's `billing_currency_code`**, cached rate ≤ input rate, rate capped at 1e9 µ. No manual sell rows; per-customer prices deferred. | One typed number moves every price with FX and cost automatically, and no stored sell price can go stale below cost. The row restrictions close the fallback/currency/divisor bugs on the sell path without having to fix them all first. |
| **D14** | **Prompts are never stored.** `ai_calls` becomes a 24 h replay store: response only, **encrypted into a `LONGTEXT` column** (MariaDB's `JSON` type carries a `json_valid` CHECK that rejects ciphertext), keyed successful calls only, pruned hourly, existing bodies purged at deploy. `ai_usage` keeps metadata and money permanently. Responses are white-labelled (`model` → our slug, `id` → `chatcmpl-{public_id}`, `estimated_cost` stripped, provider headers never relayed); the client's `user` field is stripped before the upstream call. | Minimal GDPR exposure for en/tr, the supplier stays hidden, and the customer cannot route around us. |
| **D15** | **`throttle:ai` removed from `/v1`.** Replaced by: 60 rpm per key hash; ≤`ai.inflight_per_customer` (4) in-flight holds, checked in the reserve lock; ≤`ai.inflight_global` (8, raised once the api pool exists) calls in `sending`/`streaming`; 30 failed authentications per IP per minute. `/v1` excluded from `PageCache`. `GET /v1/models` in scope. TPM limits and `/v1/messages` deferred. | The shared 12/min IP bucket makes the product unusable and one IP can never reach the per-key limit anyway. On shared cPanel the scarce resource is FPM workers held by long streams, so the cap that matters is in-flight, not rpm. |
| **D16** | **Data migration sets the seeded `deepinfra` driver to `OpenAI-Compatible`**, shipped **inside M5.1b** alongside the money engine. The admin driver field becomes a select from the known-driver whitelist; a test asserts every provider's driver resolves to a class. | Verified: the seeded provider is `enabled=false, live_calls_enabled=false`, so the driver string alone cannot switch the call path on — the earlier rationale was wrong. It ships with M5.1b because `AiCaller` and the driver contract change in that commit, and a driver name pointing at a changed class is a half-ship. |

**Grafts adopted (judge-named, from the other two proposals)**

| G | From | Adopted as |
|---|---|---|
| G1 | product | `api.servernet.cloud`, own PHP-FPM pool, Cloudflare DNS-only, AutoSSL (M5.3) |
| G2 | product | Balance-aware `max_tokens`, floor 256 |
| G3 | product | `usage.cost_irt` in the body + on the final stream chunk; `X-Request-Id`, `X-ServerNet-Charge-Irt` headers |
| G4 | product | Encrypted replay store as `LONGTEXT`, not `json` |
| G5 | product | Never 5xx after upstream success; `x-should-retry: false` on any charged error |
| G6 | product | `/v1` excluded from `PageCache`; `ConsoleHost`/`LegacyDomain` must never 301 the api host (a 301 on POST breaks SDKs) |
| G7 | product | `set_time_limit` skipped under `app()->runningUnitTests()` (`set-time-limit-kills-the-suite`) |
| G8 | product | Replace the `hasTable(...) return;` early guards in `001000/001200/001300` with per-step guards |
| G9 | product | `/admin/ai/review` needs-review queue with one-click refund; budget 80/100 % + low-balance alerts |
| G10 | ship-fast | Milestone slicing with one migration per deploy; content-verified post-deploy checks (curl the error envelope, `schedule:list`, `grep ERROR laravel.log`); fail-closed margin; step-0 SQL gate |

---

## §2 Schema changes

All migrations: every step individually guarded (`hasTable`/`hasColumn`/`hasIndex`/`hasForeignKey`), re-runnable, `down()` a no-op, **no global early return**. MariaDB DDL is not transactional, so a half-run must be completable by re-running. The owner runs each one alone with `--path`.

### 2.0 The `ai_calls` repair (M5.0) — file already exists, hardened

`website/database/migrations/2026_11_02_001400_repair_ai_gateway_fks.php` is already on the branch and already does, per-step: null orphan `ai_reservation_id`; collapse duplicate `(customer_id, idempotency_key)` keeping `MIN(id)`; add `unique(customer_id, idempotency_key)`; add `index(customer_id, created_at)`; add the FK `ai_calls.ai_reservation_id → ai_reservations.id ON DELETE SET NULL` (MySQL only). **Four additions required:**

```php
// ── 0) engine assertion: an FK onto a MyISAM parent silently no-ops ──
if ($mysql) {
    $engines = DB::table('information_schema.TABLES')
        ->whereRaw('table_schema = DATABASE()')
        ->whereIn('table_name', ['ai_calls','ai_reservations','customers','credit_ledger'])
        ->pluck('engine','table_name');
    foreach ($engines as $t => $e) {
        if (strtoupper((string) $e) !== 'INNODB') {
            throw new RuntimeException("ترمیم رد شد: جدول {$t} موتورش {$e} است، نه InnoDB.");
        }
    }
}

// ── 0b) والدِ غایب: بدونِ ai_reservations هیچ کلیدِ خارجی‌ای نمی‌نشیند ──
if (! Schema::hasTable('ai_reservations')) {
    // exact 001200 body, inlined — prod may have 001300 without 001200
}

// ── 6) یونیکِ ai_reservations هم باید تأیید شود، نه فرض ──
if (! Schema::hasIndex('ai_reservations', ['customer_id','idempotency_key'])) {
    $dupes = DB::table('ai_reservations')->select('customer_id','idempotency_key')
        ->whereNotNull('idempotency_key')->groupBy('customer_id','idempotency_key')
        ->havingRaw('count(*) > 1')->count();
    if ($dupes > 0) {
        throw new RuntimeException("{$dupes} کلیدِ هم‌ارزیِ تکراری در ai_reservations — پول در میان است، بررسیِ انسانی لازم است.");
    }
    Schema::table('ai_reservations', fn (Blueprint $t) => $t->unique(['customer_id','idempotency_key']));
}
```

Duplicate reservation keys **throw** (they are money) while duplicate `ai_calls` keys are collapsed (they are cached responses). Also in M5.0, the `hasTable(...) return;` guards in `001000`, `001200`, `001300` are rewritten per-step (G8) — those rows are already `Ran` on prod, so this only protects fresh installs and CI.

### 2.1 `ai_usage` — NEW, `2026_11_03_000100_ai_money_core.php` (M5.1b)

One row per `/v1` attempt that passed the gates. **No foreign keys, by design**: money history must survive a customer delete, and it avoids another errno 150 on this server.

```
id                     bigint unsigned PK
public_id              char(26) NOT NULL UNIQUE          -- ULID, shown to customer & API
customer_id            bigint unsigned NOT NULL
ai_reservation_id      bigint unsigned NULL UNIQUE
ai_project_id          bigint unsigned NULL
customer_api_token_id  bigint unsigned NULL
ai_provider_id         bigint unsigned NOT NULL
ai_model_id            bigint unsigned NOT NULL
model_slug             varchar(80)  NOT NULL
upstream_model         varchar(120) NOT NULL
idempotency_key        varchar(80)  NULL
request_sha256         char(64)     NOT NULL
stream                 tinyint(1)   NOT NULL DEFAULT 0
status                 varchar(16)  NOT NULL   -- reserved|sending|streaming|settle_pending|settled|unknown_pending|unknown_charged|released
error_code             varchar(40)  NULL
upstream_status        smallint unsigned NULL
upstream_request_id    varchar(120) NULL
max_input_tokens       int unsigned NOT NULL   -- byte bound I
max_output_tokens      int unsigned NOT NULL   -- forced max_tokens O
prompt_tokens          int unsigned NULL
cached_tokens          int unsigned NULL
completion_tokens      int unsigned NULL
reasoning_tokens       int unsigned NULL
delta_count            int unsigned NULL       -- SSE evidence only, never a charge basis
usage_source           varchar(12)  NULL       -- provider|recovered|cap|none
price_currency         char(3)      NOT NULL
input_price_id         bigint unsigned NOT NULL
cached_price_id        bigint unsigned NULL
output_price_id        bigint unsigned NOT NULL
input_rate_micro       bigint unsigned NOT NULL
cached_rate_micro      bigint unsigned NULL
output_rate_micro      bigint unsigned NOT NULL
fx_rate_toman          int unsigned NOT NULL
fx_source              varchar(24)  NOT NULL   -- scraped|override|ratchet|scraped+stale
fx_at                  timestamp    NULL
fee_bp                 smallint unsigned NOT NULL
margin_bp              int unsigned NOT NULL
vat_bp                 smallint unsigned NOT NULL
vat_basis              varchar(20)  NOT NULL   -- ir_country|fa_locale|no_country|foreign_country
p_input_irt_m          bigint unsigned NOT NULL
p_cached_irt_m         bigint unsigned NOT NULL
p_output_irt_m         bigint unsigned NOT NULL
hold_sell_irt          bigint unsigned NOT NULL
hold_tax_irt           bigint unsigned NOT NULL
hold_irt               bigint unsigned NOT NULL
cost_micro             bigint unsigned NULL
provider_cost_micro    bigint unsigned NULL
cost_irt               bigint unsigned NULL
sell_irt               bigint unsigned NULL    -- revenue, excl. VAT
tax_irt                bigint unsigned NULL
charged_irt            bigint unsigned NULL
refunded_irt           bigint unsigned NOT NULL DEFAULT 0
uncollected_irt        bigint unsigned NOT NULL DEFAULT 0
needs_review           tinyint(1) NOT NULL DEFAULT 0
review_reason          varchar(32) NULL        -- over_bound|cost_drift|below_cost|unknown|recovered_refund
recover_attempts       tinyint unsigned NOT NULL DEFAULT 0
credit_ledger_id       bigint unsigned NULL UNIQUE
day                    date NULL               -- Asia/Tehran date of settled_at = rollup bucket
latency_ms             int unsigned NULL
ttft_ms                int unsigned NULL
decide_by              timestamp NOT NULL
sent_at                timestamp NULL
settled_at             timestamp NULL
created_at/updated_at  timestamp
```

**47 columns.** Indexes: `(customer_id, created_at)`, `(customer_id, day)`, `(ai_project_id, settled_at)`, `(customer_api_token_id, settled_at)`, `(day, ai_provider_id, status)`, `(status, decide_by)`, `(customer_id, idempotency_key)`, `(ai_provider_id, sent_at)`, `(needs_review, created_at)`.

### 2.2 Column additions, same migration `000100` (each behind `hasColumn`)

```
ai_reservations.pricing_version      tinyint unsigned NOT NULL DEFAULT 0   -- existing rows = 0 (legacy µUSD); M5 writes 1
ai_reservations.ai_project_id        bigint unsigned NULL  + INDEX(ai_project_id, status)
ai_reservations.customer_api_token_id bigint unsigned NULL + INDEX(customer_api_token_id, status)
ai_reservations.charged_irt          bigint unsigned NULL
ai_providers.fx_fee_bp               smallint unsigned NULL     -- NULL = not sellable
ai_providers.daily_cost_cap_micro    bigint unsigned NULL
ai_providers.paused_at               timestamp NULL
ai_providers.paused_reason           varchar(120) NULL
ai_providers.usage_lookup_url        varchar(255) NULL          -- late-usage recovery; NULL = capability absent
ai_models.margin_bp                  int unsigned NULL
ai_models.suspended_at               timestamp NULL
ai_models.suspended_reason           varchar(120) NULL
```

`ai_reservations.expires_at` is unchanged as a column; M5 rows are **written with `NULL`**. `amount_irt` keeps its name and now genuinely means Toman.

### 2.3 `2026_11_03_000200_deepinfra_driver_openai_compatible.php` (M5.1b, ships with 000100)

```sql
UPDATE ai_providers SET driver = 'OpenAI-Compatible'
 WHERE slug = 'deepinfra' AND driver = 'DeepInfra';
```
No change to any enabled/commercial/resale flag. Seeds `Setting ai_provider_deepinfra_base_url = https://api.deepinfra.com/v1/openai` only when blank.

### 2.4 `2026_11_03_000300_ai_replay_store.php` (M5.2)

```
ai_calls.ai_usage_id     bigint unsigned NULL UNIQUE
ai_calls.request_sha256  char(64) NULL
ai_calls.response_enc    LONGTEXT NULL          -- encrypted:array cast (NOT json: json_valid CHECK rejects ciphertext)
ai_calls.expires_at      timestamp NULL + INDEX(expires_at)
-- data: UPDATE ai_calls SET request_body = NULL, response_body = NULL;   (privacy purge at deploy)
```
`request_body` is never written again. `response_body` stays as a dead column (dropping a column on a live MariaDB table is a rebuild; not worth it).

### 2.5 `2026_11_03_000400_ai_ledger_rollup.php` (M5.4)

```
ai_usage_daily: id; day date; ai_provider_id bigint unsigned; price_currency char(3);
  calls, unknown_calls, review_calls int unsigned;
  prompt_tokens, cached_tokens, completion_tokens bigint unsigned;
  sell_irt, tax_irt, charged_irt, refunded_irt, cost_irt, uncollected_irt bigint unsigned;
  cost_micro bigint unsigned; fx_min, fx_max int unsigned;
  checksum char(64); status varchar(12) (computed|posted); posted_at timestamp NULL; timestamps;
  UNIQUE(day, ai_provider_id)

ai_provider_invoices: id; ai_provider_id; period char(7) (provider's UTC month); invoice_ref varchar(80);
  invoice_micro bigint unsigned; currency char(3); paid_irt bigint unsigned NULL;
  accrued_micro, accrued_irt bigint unsigned; variance_micro bigint (signed); variance_bp int;
  status varchar(12) (matched|over|under); note varchar(255) NULL; created_by bigint unsigned NULL;
  timestamps; UNIQUE(ai_provider_id, period)
```

### 2.6 `2026_11_03_000500_ai_model_pages.php` (M5.6)

```
ai_models.public_page tinyint(1) NOT NULL DEFAULT 0
ai_models.sort        smallint unsigned NOT NULL DEFAULT 100
ai_models.content     json NULL      -- {fa|en|tr:{title,summary,body,faq[]}} — plain JSON, no ciphertext
```

### 2.7 No DDL

- `credit_ledger`: new reasons `ai_usage` (debit), `ai_usage_overage` (debit), `ai_refund` (credit), source `AiUsage`. Legacy `ai_reservation` rows are never summed as revenue.
- `business_ledger`: new revenue category `ai`, new expense category `ai_upstream` (label `هزینهٔ ارائه‌دهندهٔ هوش مصنوعی`) added to `EXPENSE_CATEGORIES`/`CATEGORY_LABELS`. Daily rows idempotent via the existing `UNIQUE(source_type, source_id, kind)` with `source = ai_usage_daily`; monthly true-ups via `business_ledger_period_unique`.
- `settings` keys: `ai_margin_pct`, `ai_sales_open`, `ai_canary_customer_ids`, `ai_fx_hw_usd`, `ai_fx_hw_eur`.
- `tax_rates`: optional owner-created row `country=IR, product_kind=ai, rate_bp=1000`.

### 2.8 `website/config/ai.php` (engineering constants, not money policy)

```php
'default_max_output' => 4096,   'hold_buffer_bp' => 1000,   'min_balance_aware_output' => 256,
'http_connect_s' => 10,         'http_timeout_base_s' => 30, 'http_timeout_per_20_out' => 1,
'http_timeout_min_s' => 60,     'http_timeout_max_s' => 240,
'stream_idle_s' => 60,          'stream_wall_s' => 600,
'fx_stale_after_h' => 6,        'fx_max_age_h' => 24, 'fx_stale_buffer_bp' => 200, 'fx_max_daily_drop_bp' => 300,
'rpm_per_key' => 60,            'inflight_per_customer' => 4, 'inflight_global' => 8, 'auth_fail_per_ip_min' => 30,
'replay_ttl_h' => 24,           'decide_by_grace_s' => 300,
'unknown_recovery_window_min' => 15, 'unknown_recovery_days' => 7, 'unknown_recovery_max_attempts' => 3,
'hold_backstop_h' => 24,
```

### 2.9 The one model change that replaces a cron dependency (MS-2)

`app/Models/AiReservation.php` — verified as the sole input to `Wallet::reservedOf()`, so `Wallet.php` is untouched:

```php
/** رزروهای نگه‌دارندهٔ پول. NULL = بی‌مهلت (M5) ولی نه برای همیشه:
 *  اگر ریکانسایلر ۲۴ ساعت نمرده باشد هرگز به این پشتوانه نمی‌رسیم، و اگر مرده باشد
 *  آزادکردنِ پولِ مشتری درست‌ترین شکستِ ممکن است. هشدارِ سطرِ گیرکرده ۲۳ ساعت زودتر رفته. */
public function scopeHolding(Builder $q): Builder
{
    $backstop = now()->subHours((int) config('ai.hold_backstop_h', 24));

    return $q->where('status', self::STATUS_PENDING)
        ->where(fn ($w) => $w
            ->where(fn ($n) => $n->whereNull('expires_at')->where('created_at', '>', $backstop))
            ->orWhere('expires_at', '>', now()));
}
```

---

## §3 Pricing formula

### Inputs (all integers)

| Symbol | Source |
|---|---|
| `r_in, r_c, r_out` | `ai_model_unit_prices.price_micro_units` — µC per 1M tokens, `C ∈ {USD, EUR}`, model-level active rows only |
| `R` | Toman per 1 C, from `AiFx::quote(C)` (D2) |
| `f` | `ai_providers.fx_fee_bp` — the *real* overhead of putting 1 C into the provider (card/crypto fee + exchange spread) |
| `m` | `ai_models.margin_bp ?? ai_margin_pct × 100`, ≥1 |
| `t` | `vat_bp` from `AiVat::resolve()` (D10) |
| `b` | `ai.hold_buffer_bp` = 1000 |
| `K` | `(10^4 + f) · (10^4 + m)` |

### Published & billed price per 1M tokens (the same number)

```
P_x = ceil( r_x · R · K / 10^14 )            [whole Toman per 1,000,000 tokens]
```
No significant-figure rounding: the published number **is** the billing multiplier, so a customer can reproduce any bill as `Σ nₓ·Pₓ ÷ 1e6`.

### Per call

```
u = prompt_tokens − cached_tokens        (cached clamped to ≤ prompt)
c = cached_tokens,  o = completion_tokens
N = u·r_in + c·r_c + o·r_out                       -- exact, µC·tokens
N_b = max(N, E·10^6)  where E = ceil(usage.estimated_cost · 10^6) if present

S_book  = ceil( (u·P_in + c·P_cached + o·P_out) / 10^6 )
S_floor = ceil( N_b · R · K / 10^20 )
S       = max(S_book, S_floor)                      -- revenue, ex-VAT
T       = ceil( S · t / 10^4 )                      -- VAT, ON TOP
charged = S + T
cost_irt = ceil( N_b · R · (10^4 + f) / 10^16 )     -- landed cost, for the expense ledger
```

### Hold at reserve

```
I = strlen(json_encode(forwarded body without max_tokens, JSON_UNESCAPED_UNICODE))
    + 16 · count(messages) + 256                    -- ≤1 token per UTF-8 byte for byte-level BPE
O = min(client max_tokens ?? cap, cap),  cap = model.max_output_tokens ?: 4096
H_s = ceil( (I·P_in + O·P_out) · (10^4 + b) / 10^10 )
H_t = ceil( H_s · t / 10^4 )
H   = H_s + H_t
```

### Red-line proof

1. `P_x ≥ r_x·R·K/10^14` exactly ⇒ `Σ nₓ·Pₓ ≥ N·R·K/10^14` ⇒ (÷10^6, ceil monotone) **`S_book ≥ S_floor`**.
2. `K = (10^4+f)(10^4+m) ≥ (10^4+f)·10^4` for `m ≥ 0` ⇒ `N·R·K/10^20 ≥ N·R·(10^4+f)/10^16` ⇒ **`S_floor ≥ cost_irt`**.
3. Therefore **`S ≥ cost_irt` on every call, unconditionally.**
4. Strict profit: exact margin in Toman is `N·R·(10^4+f)·m / 10^20`. With the worked parameters this reaches 1 Toman at roughly **160 input tokens**; below that the call is charged *at or above* cost, never below. (Stated exactly rather than claiming strict inequality everywhere — a 1-token call has `S = cost_irt = 1`.)
5. VAT is on top and never enters the comparison.

### Worked example — DeepInfra-like (Llama-3.3-70B class)

`r_in = 230,000` µUSD/1M (**$0.23**) · `r_c = 115,000` (**$0.115**) · `r_out = 400,000` (**$0.40**)
`R = 100,000` Toman/USD · `f = 800` (8 %) · `m = 2500` (25 %) · `t = 1000` (10 %)

```
K = 10,800 × 12,500 = 135,000,000

P_in     = ceil(230,000 · 100,000 · 135,000,000 / 10^14) = 31,050 Toman / 1M
P_cached = ceil(115,000 · 100,000 · 135,000,000 / 10^14) = 15,525 Toman / 1M
P_out    = ceil(400,000 · 100,000 · 135,000,000 / 10^14) = 54,000 Toman / 1M
```

**Call:** 12,000 prompt tokens of which 8,000 cached, 900 completion. `u = 4,000`.

```
S_book  = ceil((4,000·31,050 + 8,000·15,525 + 900·54,000) / 10^6)
        = ceil((124,200,000 + 124,200,000 + 48,600,000) / 10^6) = ceil(297.0) = 297
N       = 4,000·230,000 + 8,000·115,000 + 900·400,000 = 2,200,000,000
S_floor = ceil(2.2e9 · 1e5 · 1.35e8 / 10^20) = 297
S       = 297 Toman                                   (ex-VAT, this is the revenue)
cost_micro = ceil(2.2e9 / 1e6) = 2,200 µUSD  ($0.0022)
cost_irt   = ceil(2.2e9 · 1e5 · 10,800 / 10^16) = ceil(237.6) = 238 Toman
```

**Iranian customer** (`vat_basis = fa_locale`, no non-IR country on file ⇒ VAT applies):
```
T       = ceil(297 · 1000 / 10,000) = ceil(29.7) = 30
charged = 297 + 30 = 327 Toman        → wallet debit 327
ledger  : revenue 297 (category ai) · tax_collected 30 · expense 238 (ai_upstream)
gross   : 297 − 238 = 59 Toman = 19.9 % of revenue · 297 ≥ 238 ✓
```

**Foreign customer** (`customers.country_code = 'DE'` ⇒ `vat_basis = foreign_country`, `t = 0`):
```
T = 0 · charged = 297 Toman · revenue 297 · tax_collected 0 · expense 238
```

**Hold** for a 6,000-byte, 5-message request with no `max_tokens` (`I = 6,000 + 80 + 256 = 6,336`, `O = 4,096`):
```
H_s = ceil((6,336·31,050 + 4,096·54,000) · 11,000 / 10^10) = ceil(459.708…) = 460
H_t = ceil(460 · 1000 / 10,000) = 46
H   = 506 Toman held · 327 charged · 179 freed at settle
```
Balance-aware (G2): if available were 400 Toman and the client omitted `max_tokens`, `O` is lowered to `floor(((400·10^4/(10^4+t))·10^6 − I·P_in) / P_out)` = 1,626 tokens ⇒ `H = 400`; if that fell below 256 tokens, 402 with the required amount in the message.

**EUR display** (`R_eur = 110,000`, en/tr, display only, ceil to 4 decimals):
```
€_in     = ceil(31,050 · 10^4 / 110,000)/10^4 = 2,823/10^4 = €0.2823 / 1M
€_cached = €0.1412 / 1M     €_out = €0.4910 / 1M
per call : ceil(297 · 10^6 / 110,000) = 2,700 µ€ = €0.0027
```
`R_eur` unknown ⇒ € hidden, Toman only, never a guess.

---

## §4 Settlement algorithm

Notation: `⌈x/y⌉` is integer ceil division; every product runs through `BigInteger` and returns via a checked `toInt()` (overflow at reserve ⇒ **413 `request_too_large`**). No float anywhere.

### A. Gates and hold (`AiCaller::handle` — no transaction open)

```
A1. Gates, in this order. Any failure returns a specific 4xx/503; nothing is held.
    - sales gate: Setting ai_sales_open = 1, or customer ∈ ai_canary_customer_ids
    - AiPayload::sanitize  → 400 on n>1 | best_of | non-text content parts | bad Idempotency-Key
    - model: active, category=chat, suspended_at IS NULL
    - provider: enabled, live_calls_enabled, commercial_enabled, resale_allowed,
                agreement_status='signed', paused_at IS NULL, fx_fee_bp NOT NULL,
                daily_cost_cap_micro not reached
    - model-level active input+output price rows (cached optional, rate ≤ input),
      basis=1m_tokens, currency = provider.billing_currency_code ∈ {USD,EUR}
    - AiFx::quote(C) is not null
    - m ≥ 1 ; t resolved via AiVat::resolve(customer)

A2. O = min(max_tokens ?? max_completion_tokens ?? cap, cap);  cap = model.max_output_tokens ?: 4096; O ≥ 1
    Forwarded body: max_tokens := O. Removed: max_completion_tokens, user, n, metadata, store,
    service_tier, and every key not on the allowlist (§7). If stream: stream_options.include_usage := true.

A3. I = strlen(json_encode(forwarded body without max_tokens, JSON_UNESCAPED_UNICODE))
        + 16·count(messages) + 256

A4. P_in, P_cached, P_out per §3.  (no cached row ⇒ P_cached := P_in and r_c := r_in)

A5. H_s, H_t, H per §3.

A6. Balance-aware trim (G2), only when the client omitted max_tokens AND H > available:
      net_cap = ⌊ available · 10^4 / (10^4 + t) ⌋
      O' = ⌊ (net_cap·10^6·10^4/(10^4+b) − I·P_in) / P_out ⌋
      if O' ≥ ai.min_balance_aware_output (256):  O := O'; recompute A5
      else: 402 insufficient_funds, message states H and available

A7. DB::transaction(attempts 3) {
      lock customer FOR UPDATE
      INLINE SWEEP (MS-2): settle/release this customer's own ai_usage rows past decide_by
      if Idempotency-Key: resolve existing reservation/usage under the lock (§5)
      require available('IRT') ≥ H
      require pending holds of this customer < ai.inflight_per_customer
      require global count(status IN ('sending','streaming')) < ai.inflight_global
      if project budget:  Σ charged_irt(settled_at ∈ budgetWindowFor(now))
                        + Σ pending amount_irt(project) + H ≤ monthly_budget_irt   else 402
      if token daily_spend_cap_irt > 0: same sum over the Tehran day               else 402
      INSERT ai_reservations (amount_irt=H, pricing_version=1, expires_at=NULL, project, token, key)
      INSERT ai_usage        (status='reserved', every snapshot of §2.1,
                              decide_by = now + T_http + ai.decide_by_grace_s)
    }
```

### B. Send

```
B1. UPDATE ai_usage SET status='sending', sent_at=now()
     WHERE id=? AND status='reserved';
    affected = 0  ⇒ a sweeper already released this row: DO NOT SEND, return 503 retryable.
B2. ignore_user_abort(true);
    if (! app()->runningUnitTests()) set_time_limit(T_http + 60);     // G7
    connect_timeout = ai.http_connect_s (10)
    T_http = clamp(30 + ⌈O/20⌉, 60, 240)
    on_stats records whether the request body was written (not_sent vs sent)
B3. Classify: RELEASE | USAGE | UNKNOWN (§5).
```

### C. Settle from usage (`p=prompt`, `c=min(cached,p)`, `o=completion`, `u=p−c`)

```
C1. p or o missing / non-integer / negative      ⇒ UNKNOWN mode.
    p = o = 0                                     ⇒ RELEASE (charge 0).

C2. N   = u·r_in + c·r_c + o·r_out
    cost_micro = ⌈N / 10^6⌉
    if usage.estimated_cost present:
        E   = ⌈estimated_cost · 10^6⌉        (parsed from the STRING via BigDecimal)
        N_b = max(N, E · 10^6)
        cost_drift = (E·100 > cost_micro·101)      ⇒ needs_review='cost_drift'
    else N_b = N

C3. cost_irt = ⌈ N_b · R · (10^4 + f) / 10^16 ⌉

C4. S_book  = ⌈ (u·P_in + c·P_cached + o·P_out) / 10^6 ⌉
    S_floor = ⌈ N_b · R · K / 10^20 ⌉
    S       = max(S_book, S_floor)

C5. T = ⌈ S · t / 10^4 ⌉ ;  charged = S + T

C6. OVERAGE (charged > H — only reachable if p > I, o > O, or drift > b):
      base    = H                          (consumed from the reservation)
      overage = charged − H
      try Wallet::debit(customer,'IRT',overage,'ai_usage_overage',source=AiUsage,requireAvailable:true)
        success ⇒ uncollected_irt = 0
        WalletException insufficient ⇒ debit whatever available allows; remainder → uncollected_irt
      needs_review='over_bound'; ai_models.suspended_at := now(); AdminAlerts.
      (MS-1: we charge the real amount, we never cap the CHARGE at the hold. Only an
       uncollectable wallet leaves a residue, and that is a credit event, not a below-cost sale.)

C7. RED-LINE ASSERTION: S ≥ cost_irt. If false ⇒ needs_review='below_cost',
    suspend the model, AdminAlerts. By §3 this is unreachable; the assert exists to prove it.

C8. Persist FIRST, outside the money transaction:
    UPDATE ai_usage SET status='settle_pending', <all computed fields>
     WHERE id=? AND status IN ('sending','streaming','unknown_pending');

C9. DB::transaction(attempts 3, retry on 1213 / 1205 / SQLSTATE 40001) {
      lock customer;  lock reservation (pending AND pricing_version=1);  lock usage (settle_pending)
      if charged > 0:
        Wallet::debit(customer,'IRT', min(charged,H), 'ai_usage', source=AiUsage,
                      requireAvailable:true, excludingReservationId: r.id)
      reservation: status=settled (or released when charged=0, key nulled),
                   charged_irt, settled_at, ledger_entry_id
      usage: status=settled | unknown_charged, credit_ledger_id, settled_at,
             day = Asia/Tehran date(settled_at)
    }
    This debit cannot fail for funds: min(charged,H) ≤ H, H was guaranteed available at reserve,
    the hold never lapses before the 24 h backstop, and every other writer respects holds.

C10. If C9 still throws after its retries: the row stays settle_pending with funds still held,
     and the client STILL receives its response body (G5 — never a 5xx after upstream success).
     The reconciler completes C9 from the persisted values.
```

### G. Unknown outcome — late-usage recovery (owner override)

```
G1. Entering UNKNOWN (read timeout after upload, reset, unparseable 2xx, stream cut
    before usage, crash after the 'sending' mark):
      UPDATE ai_usage SET status='unknown_pending', recover_attempts=0
      The reservation is NOT settled. The hold stays. Client gets 504/502 with x-should-retry:false.

G2. ai:recover-usage (every 5 min) for status='unknown_pending':
      source 1 — provider lookup: if ai_providers.usage_lookup_url IS NOT NULL and
                 upstream_request_id known, GET it and parse a usage object.
                 (For DeepInfra this endpoint is UNVERIFIED — column stays NULL until the
                  canary proves it, and then the fast path simply skips to G3.)
      source 2 — response replay: for keyed calls, re-parse ai_calls.response_enc for a
                 usage object we failed to persist (covers a crash between read and write).
      source 3 — stream evidence: delta_count is recorded but is a LOWER bound and is
                 never used as a charge basis; it only proves work happened.
      recover_attempts++ each pass.

G3. After ai.unknown_recovery_window_min (15) or unknown_recovery_max_attempts (3):
      settle in CAP mode:
        S = H_s ; T = H_t ; charged = H ; usage_source='cap'
        cost_irt = ⌈ (I·r_in + O·r_out) · R · (10^4+f) / 10^16 ⌉      -- worst-case cost
        status = 'unknown_charged' ; needs_review='unknown'
      This is the owner's "charge up to the reservation cap; never absorb upstream cost".

G4. Late pass, ai:recover-usage --late (daily, rows with usage_source='cap' within
    ai.unknown_recovery_days = 7): if source 1 or 2 now yields real usage, recompute
    S/T/charged per C2-C5 and post an AUTOMATIC PARTIAL REFUND of (charged_old − charged_new):
      credit_ledger  reason 'ai_refund',  source AiUsage, amount = +diff
      ai_usage.refunded_irt += diff ; usage_source='recovered' ; review_reason='recovered_refund'
      business_ledger refund row on the refund date (never a retro-edit of a posted day)
    Never an additional DEBIT: the customer authorised H and no more.
```

### S. Streaming settlement (`stream:true` — launch path)

```
S1. Gates + A2-A7 run BEFORE any byte is written, so every pre-flight failure is a normal
    JSON error with its proper status. Only then is the StreamedResponse returned.

S2. Headers before the first write:
      Content-Type: text/event-stream; charset=utf-8
      Cache-Control: no-cache, no-store   Connection: keep-alive   X-Accel-Buffering: no
      X-Request-Id: {public_id}
    Buffering off: while (ob_get_level() > 0) ob_end_flush();  ini_set('zlib.output_compression','0');
    Apache: the owner hand-adds to public_html/.htaccess (NEVER deployed from the repo):
      SetEnvIf Request_URI "^/v1/" no-gzip dont-vary
    verified by curl, not assumed (memory: SetEnvIf has silently no-opped here before).

S3. UPDATE ai_usage SET status='streaming', sent_at=now() WHERE id=? AND status='reserved'
    (same conditional-UPDATE guard as B1).

S4. Upstream: Http::withOptions(['stream' => true]); read the PSR-7 body in ≤8 KiB reads.
    SseRelay parses a byte buffer into lines: tolerates split reads, CRLF, multi-line data,
    ': ' comment/keep-alive lines, and 'data: [DONE]'. A data line that is not JSON ⇒ UNKNOWN.
    Each chunk is rewritten (model → our slug, id → chatcmpl-{public_id}, provider fields
    stripped), written downstream, flushed, and delta_count++ for a content delta.
    ttft_ms recorded on the first content delta.

S5. Usage capture: from whichever chunk carries a non-null `usage` — DeepInfra puts it on the
    final finish_reason chunk before [DONE]; OpenAI sends a separate usage-only chunk with
    empty choices. Both accepted.

S6. Client disconnect (connection_aborted() after a failed flush, with ignore_user_abort(true)):
    STOP writing downstream, KEEP READING upstream until [DONE] / usage / a cap.
    ⇒ exact generated tokens, charged per C2-C5. Not an estimate.

S7. Caps: idle ai.stream_idle_s (60) between chunks, wall ai.stream_wall_s (600).
    Hitting a cap with no usage ⇒ UNKNOWN (§G).

S8. Order at end of stream:
      (a) run C2-C9 and settle
      (b) if the client asked for stream_options.include_usage, emit our rewritten usage chunk
          with usage.cost_irt = charged_irt (G3); if the client did NOT ask, swallow it
      (c) emit `data: [DONE]`
    If C9 throws, still emit [DONE] — the client keeps its content — and leave the row
    settle_pending for the reconciler (G5).

S9. Mid-stream upstream error: emit `data: {"error":{...}}` then `data: [DONE]`; apply §5.
S10. Replay of a keyed stream: synthesise SSE from the stored response — role delta, one
     content delta with the full text, finish chunk, optional usage chunk, [DONE];
     header Idempotent-Replayed: true; no upstream call, no charge.
```

### D. Reconciler (`ai:reconcile`, every minute, `withoutOverlapping`)

Every transition is a conditional UPDATE, so it is idempotent and cannot race the request path.

```
D1. reserved  AND decide_by < now  ⇒ release. (B1/S3 then prevent a late sender from sending.)
D2. sending|streaming AND decide_by < now ⇒ status='unknown_pending' (enters §G).
D3. unknown_pending ⇒ §G2/G3.
D4. settle_pending ⇒ re-run C9 from the persisted values.
D5. pricing_version = 0 (legacy µUSD rows) ⇒ release or expire ONLY, never settle.
D6. AdminAlerts when any non-terminal row is older than decide_by + 10 min, or when the
    schedule heartbeat is missing (/system/ai-status).
```

Result: no double debit (row locks + conditional status + `UNIQUE credit_ledger_id` + `UNIQUE ai_reservation_id`), no hold lapses before a decision inside 24 h, and the wallet never goes negative.

---

## §5 Failure & timeout policy, idempotency semantics

**Rule:** the charge depends on whether the provider may have done billable work — never on whether the customer received a body.

### Before any money moves (nothing held)
`sales_closed` · `fx_unavailable` · `pricing_incomplete` · `model_suspended` · `provider_paused` · `budget_exceeded` · `daily_cap_exceeded` · `insufficient_funds` · `too_many_inflight` · `invalid_request` · `stream` gate failures. All return 4xx/503, hold nothing.

### RELEASE — charge 0, no ledger row, key freed
| Case | Response |
|---|---|
| Reconciler released the row before B1/S3 | 503, retryable |
| DNS / TCP connect / TLS failure, connect timeout (errno 6/7/35; 28 with upload not started) | 502 `upstream_unreachable` |
| Provider 400/404/409/413/422/429 | 502 `upstream_error` (sanitized reason passed through for 400/413/422) |
| Provider 401/402/403 | release, **auto-pause the provider**, alert, 503 `provider_paused` |
| Provider 5xx | release; counted toward the monthly invoice variance. If reconciliation ever shows 5xx calls being billed, the policy moves to UNKNOWN — a code change, not a guess |
| 2xx with `p = o = 0` | settled with charge 0 |

### UNKNOWN — hold kept, recovery, then cap (§4.G)
Read timeout · reset after upload started · 2xx whose body is not a JSON object or has no usable `usage` · stream cut before the usage chunk · idle/wall cap hit · PHP fatal or kill after the `sending`/`streaming` mark. Client gets **504 `upstream_timeout`** / **502 `upstream_bad_body`** with `X-Request-Id`, `X-ServerNet-Charge-Irt` (once settled) and **`x-should-retry: false`** (G5 — the OpenAI SDKs auto-retry 5xx/409, and a retry here would buy the same answer twice). The row appears in `/admin/ai/review` with one-click refund and in the customer portal with its reason.

### Settle trouble (the service WAS delivered)
Deadlock / lock wait ⇒ 3 retries inside `DB::transaction`. Still failing ⇒ the persisted `settle_pending` row is completed by the reconciler; **the client still gets 200 and its body**. Funds stay held. `settle_failed` disappears from the API entirely. **We never return a 5xx after upstream success.**

### Anomalies (needs_review + model auto-suspend)
`over_bound` (usage beyond the bound) · `cost_drift` (provider cost > book + 10 %) · `below_cost` (the C7 assert). An admin must unsuspend after fixing the price row.

### Timeouts
`connect 10 s` · `T_http = clamp(30 + ⌈O/20⌉, 60, 240) s` non-stream · stream `idle 60 s / wall 600 s` · `decide_by = sent + T_http + 300 s` · `ignore_user_abort(true)` always, so PHP always reaches settle. A client cut by Cloudflare's 100 s limit retries with the same key and gets its paid answer (which is also why streaming — which starts bytes immediately — is the launch-critical path).

### Idempotency semantics (D8)

| Situation | Result |
|---|---|
| No key | Normal call. No replay is ever possible; nothing is stored. |
| Key, first use | Bound to `request_sha256`. Response stored encrypted for `ai.replay_ttl_h` (24 h) **only on success**. |
| Same key + same body, completed | **200 replay** from `ai_calls.response_enc`, header `Idempotent-Replayed: true`, no upstream call, no charge. Streams are synthesised (S10). |
| Same key, still in flight | **409 `request_in_progress`**, `Retry-After: 5` |
| Same key, different body | **422 `idempotency_key_reused`** |
| Released / failed before send | Key freed (nulled on the reservation; the audit copy stays on `ai_usage`), retry allowed |
| Key older than 24 h (pruned) | **409 `idempotency_key_expired`** |
| Row is `unknown_charged` | **409 `upstream_outcome_unknown`** — the charge stands, a retry is a new call |
| Key >80 chars or outside `[A-Za-z0-9._:-]` | **400 `invalid_idempotency_key`** |

The key is re-checked **under the customer lock** (A7), so two concurrent requests with one key cannot both reserve.

---

## §6 Ledger & reporting

### 6.1 Customer wallet (`credit_ledger`) — per call

- Exactly one row per settled call, only when `charged > 0`: reason `ai_usage`, `amount = −min(charged, H)`, source `AiUsage`, note `AI {model} {public_id}`.
- An overage (C6) adds one further row, reason `ai_usage_overage`.
- Written inside the settle transaction; `ai_usage.credit_ledger_id` is UNIQUE and `ai_usage.ai_reservation_id` is UNIQUE.
- The unused hold is never a row — the reservation simply closes.
- Refunds (manual or auto-recovered) are `+` rows, reason `ai_refund`, source `AiUsage`.
- Legacy `ai_reservation` rows (µUSD debited as Toman, pre-M5) are excluded from every revenue computation and listed in the audit.

### 6.2 Business ledger — daily

`ai:post-daily` at **00:30 UTC (04:00 Tehran)**; Iran has had a fixed +03:30 offset since 2022.

- Posts Tehran day `D` only once `D` ended ≥30 min ago. Rows bucket by `settled_at`, which is always `now()` at settle, so a posted day can never gain rows; a late settle lands on its own day.
- Per `(D, provider)`: aggregate `ai_usage` where `status ∈ (settled, unknown_charged)`; upsert `ai_usage_daily` with `checksum = sha256` of the ordered `(id, charged, sell, tax, cost)` tuples.
- Then, in one transaction, `BusinessLedger::recordAiDay(row)` posts:
  - **revenue** `Σ sell_irt`, category `ai`
  - **tax_collected** `Σ tax_irt` (never counted as revenue)
  - **expense** `Σ cost_irt`, category `ai_upstream` (fee included = replacement cost)
  - `occurred_at = D` (Tehran noon), source = the `ai_usage_daily` row ⇒ idempotent via the existing `UNIQUE(source_type, source_id, kind)`.
- Re-run with the same checksum is a no-op; a different checksum **alerts and never reposts**.
- Flags `--day`, `--from` (backfill), `--dry`.
- Day-level red line: `Σ sell ≥ Σ cost`, else alert.
- **DeepInfra top-ups are prepayments, never an expense** — the cost is accrued per call.

### 6.3 Provider invoice reconciliation — monthly (`/admin/ai/reconcile`)

Admin enters period (the provider's UTC month), `invoice_ref`, `invoice_micro`, and optionally `paid_irt` (Toman actually spent). System computes `accrued_micro = Σ cost_micro` and `accrued_irt = Σ cost_irt` for that UTC month of `sent_at`. Variance ≤1 % ⇒ `matched`. `paid_irt > accrued_irt` ⇒ post a positive expense true-up (category `ai_upstream`, period, `ref_id = provider_id`, idempotent via `business_ledger_period_unique`) and alert *"real cost above accrued: check the fee % or the price rows."* A negative variance is reported, never posted. **The invoice itself is never booked as an expense.**

### 6.4 Invariants — `ai:audit`, nightly, exit 1 + `AdminAlerts` on any violation

`I1` `charged_irt ≤ hold_irt + overage` · `I2` no settled row with `sell_irt < cost_irt` unless `review_reason='below_cost'`, and those must be 0 after investigation · `I3` exactly one `ai_usage` ledger row per charged usage, none for charge 0 · `I4` `Σ credit_ledger(ai_usage, day) = −Σ charged_irt(day)` · `I5` recomputing yesterday's rows from their own snapshots via `AiPricing` reproduces `S`, `T` and `cost_irt` **bit for bit** · `I6` no non-terminal row past `decide_by + 10 min` · `I7` checksums of posted `ai_usage_daily` rows unchanged · `I8` no reservation held longer than `ai.hold_backstop_h`.
`ai:explain {public_id}` prints the full derivation for any single call and must print PASS.

### 6.5 Admin

- `BusinessLedger::summary()` gains `revenue_by_category` (`ai`); `EXPENSE_CATEGORIES`/`CATEGORY_LABELS` gain `ai_upstream`; `/admin/finance` and the dashboard show the AI line.
- `BusinessReport::blindSpots` lists AI as covered and drops its stale lines. **Nothing is added to the hourly side block** (D11).
- `transactions` `reasonLabel` gains `ai_usage`, `ai_usage_overage`, `ai_refund`, `cloud_hourly`.
- The admin customer page groups `ai_usage` rows per day so the last-50 list is not flooded.
- `/admin/ai/usage`: per day × provider × model — calls, tokens (in/cached/out/reasoning), sell, VAT, cost, gross margin %, `unknown_charged`, `below_cost` (must be 0), uncollected, refunded, FX min/max.
- `/admin/ai/review`: `needs_review`, `settle_pending`, `unknown_pending`, stuck rows — with **one-click refund** (whole call or the difference) and a manual settle.
- `/admin/ai/reconcile`: the monthly invoice form.

### 6.6 Customer portal (`/account/ai`)

Overview (available vs held balance, MTD spend vs budget, base URL, quickstart) · usage by Tehran day × project × model × key with input / cached / output / reasoning tokens and spend (Toman for fa; € for en/tr from each row's snapshot) · recent calls with `public_id`, status and charge — **never prompts** · CSV export (UTF-8 BOM) · budget alerts at 80 % and 100 % once per window and a low-balance nudge (≤once per 24 h) by email and Bale (G9) · a monthly Toman usage statement (net, VAT, total per model). Tenant isolation is a named test.

---

## §7 API surface changes

### `POST /v1/chat/completions`

- **Middleware:** `throttle:ai` removed. `RateLimiter 'ai-v1'`: 60 rpm per `sha256(bearer)`; 30 failed authentications per IP per minute (checked *before* the token lookup); in-flight caps enforced in the reserve lock (4 per customer, `ai.inflight_global` globally).
- **Controller return type:** `Symfony\Component\HttpFoundation\Response` (covers `JsonResponse` and `StreamedResponse`).
- **Allowlisted and forwarded:** `messages`, `temperature`, `top_p`, `top_k`, `min_p`, `stop`, `presence_penalty`, `frequency_penalty`, `repetition_penalty`, `seed`, `response_format`, `tools`, `tool_choice`, `parallel_tool_calls`, `logprobs`, `top_logprobs`, `stream`.
- `max_tokens` / `max_completion_tokens` clamped to `O` and forwarded as `max_tokens`. `stream_options.include_usage` is **forced true** upstream regardless of the client value.
- **400:** `n>1`, `best_of`, `audio`, `modalities`, `prediction`, any non-text content part, bad `Idempotency-Key`.
- **Silently dropped** (listed in `X-ServerNet-Dropped-Params`): `user`, `metadata`, `store`, `service_tier`, unknown keys.
- **Success (non-stream):** `model` = our slug; `id` = `chatcmpl-{public_id}`; `usage` limited to `prompt_tokens`, `completion_tokens`, `total_tokens`, `prompt_tokens_details.cached_tokens`, `completion_tokens_details.reasoning_tokens`, **plus `usage.cost_irt`, `usage.cost_irt_net`, `usage.cost_irt_vat`** (G3); `estimated_cost` and the real model name stripped. Headers: `X-Request-Id`, `X-ServerNet-Charge-Irt`, `Cache-Control: no-store`.
- **Success (stream):** as §4.S. Final usage chunk carries `usage.cost_irt`.
- **Errors:** OpenAI envelope `{"error":{"message" (English),"type","code","param"}}` — replacing the Persian `{code,message}` — plus `x-should-retry: false` on every error where money was charged (G5).

| Status | Codes |
|---|---|
| 400 | `invalid_request`, `unsupported_parameter`, `invalid_idempotency_key` |
| 401 | `invalid_token`, `token_expired`, `token_revoked` |
| 402 | `insufficient_funds` (message carries hold + available), `budget_exceeded`, `daily_cap_exceeded` |
| 403 | scope / IP / project codes; `project_missing` checked **before** `can()` |
| 404 | `model_not_found` (also for non-chat models) |
| 409 | `request_in_progress` (Retry-After 5), `idempotency_key_expired`, `upstream_outcome_unknown` |
| 413 | `request_too_large` |
| 422 | `idempotency_key_reused` |
| 429 | `rate_limited`, `too_many_inflight` (Retry-After) |
| 502 | `upstream_unreachable`, `upstream_error`, `upstream_bad_body` |
| 503 | `sales_closed`, `fx_unavailable`, `pricing_incomplete`, `model_suspended`, `provider_paused`, `driver_unsupported` |
| 504 | `upstream_timeout` (charged, with request id) |

`settle_failed` is gone.

### `GET /v1/models` (new, M5.2)

Ability `ai:models:read` or `ai:chat`; same limiter; **excluded from `PageCache`** (G6). Shape:
```json
{"object":"list","data":[{"id":"<slug>","object":"model","owned_by":"servernet",
 "context_length":…,"max_output_tokens":…,
 "pricing":{"currency":"IRT","unit":"1M tokens","input":31050,"cached_input":15525,
            "output":54000,"vat_bp":1000,"vat_note":"added on top where applicable",
            "eur_approx":{"input":0.2823,"output":0.4910},"fx_at":"…"}}]}
```
Only sellable models. `GET /v1/models/{id}` likewise.

### Host

`api.servernet.cloud` serves the same routes from the same docroot on its own PHP-FPM pool, Cloudflare **DNS-only** (G1). `ConsoleHost` and `LegacyDomain` must be proven never to 301 it — **a 301 on POST breaks every SDK** (G6); a named test pins this. `https://servernet.cloud/v1` keeps working.

### Account (web)

`GET /account/ai`, `/account/ai/usage`, `/account/ai/usage.csv`, `/account/ai/requests`. The security page shows the base URL, the model list, quickstart, and **available vs held** balance.

### Admin (web; added **last** in `routes/web.php`; every shared reference wrapped in `Route::has`)

`/admin/ai/providers/{id}` (fx_fee_bp, driver **select** from the whitelist, commercial/resale/agreement flags, daily cost cap, pause/unpause, usage_lookup_url) · `/admin/ai/models` (create/edit, margin_bp, max_output_tokens, suspend/unsuspend, live price preview) · `/admin/ai/usage` · `/admin/ai/review` + `POST /admin/ai/usage/{id}/refund` · `/admin/ai/reconcile` · `/admin/ai/stream-probe` (admin-only SSE emitter for the buffering check). The pricing settings panel gains `ai_margin_pct`, `ai_sales_open`, `ai_canary_customer_ids`. **Admin JSON routes do their own validation** (memory: `validate()` on `/admin` returns 302, not 422).

### Public (locale group)

`/ai`, `/ai/models/{slug}`, `/ai/docs`, plus `/en` and `/tr` prefixes. Not money-critical; prices from `AiPricing::sheet()` cached 5 minutes; `#main` already reserves 110 px so **no `padding-top`**; IRANSans, self-hosted.

### Artisan

`ai:reconcile` (每 minute) · `ai:recover-usage` (5 min) + `--late` (daily) · `ai:post-daily` (00:30 UTC) · `ai:audit` (daily) · `ai:prune-bodies` (hourly) · `ai:explain {public_id}` · `ai:price-preview` · `ai:status`.

---

## §8 Build plan

**Conventions for every milestone.** Branch `feature/ai-gateway-money`, rebased on current `origin/develop` (verify `HEAD` before every commit; never `reset --hard` — shared worktree). Merge to `develop` only after the owner's OK. Each milestone has its own `scripts/deploy-ai-m5X-*.sh`: per-file three-way merge pinned to one commit SHA, CRLF normalised, **a file reported `NEW` that should be `UP` aborts the script**, `routes/web.php` last, helpers before views. I run every script **DRY**; the owner runs the real deploy and every migration (`--pretend` first, which I review, then `--force --path=` one file at a time). Lang keys **only** via `scripts/lang-apply-keys.php` — which lives only in `C:\wt-credit\scripts` and **must be copied into this branch in M5.0**. Never deploy `public/` root files. Count `Schedule::command` in prod `routes/console.php` **before and after** every deploy; a shrink aborts. OPcache reset after every deploy; every new route name referenced from a shared view guarded with `Route::has`. Money tests run filtered (`--filter='Ai|V1'`) and the exit status is read from the summary line, because Windows `set_time_limit` can end a full run mid-way with exit 0.

---

### M5.0 — Prod schema repair + green baseline · *ships alone*

**Goal.** Repair the half-built prod schema and make the existing suite green. No money behaviour changes; prod still cannot call upstream (driver is still `DeepInfra`).

**Files** (all four migrations **ship together** — the repair is meaningless if its parent is absent):
`website/database/migrations/2026_11_02_001400_repair_ai_gateway_fks.php` (harden per §2.0) · `…001000_create_ai_projects.php`, `…001200_create_ai_reservations.php`, `…001300_create_ai_calls.php` (per-step guards, G8) · `website/app/Models/AiReservation.php` (backstop scope, §2.9) · `website/app/Services/Ai/AiAdmission.php` (`project_missing` before `can()`; window on read) · `website/app/Models/AiProject.php` (`budgetWindowFor`, create infers monthly) · `scripts/ai-prod-audit.sql` · `scripts/lang-apply-keys.php` (copied in) · `scripts/deploy-ai-m5-0-repair.sh` · tests below.

**Tests.** `AiRepairMigrationTest::test_repairs_half_built_ai_calls` · `::test_is_noop_on_complete_schema` · `::test_creates_ai_reservations_when_missing_before_adding_fk` · `::test_nulls_orphan_reservation_refs_before_fk` · `::test_duplicate_ai_calls_keys_keep_min_id` · `::test_duplicate_reservation_keys_throw_for_review` · `::test_non_innodb_engine_throws` · `AiHoldBackstopTest::test_null_expiry_hold_counts_before_backstop_and_not_after` · `AiRegistryTest::test_every_provider_driver_resolves` · `AiProjectsAndAdmissionTest::test_project_missing_before_can` · `::test_budget_window_boundaries` (with `Carbon::setTestNow`) · `::test_project_create_budget_monthly` · opt-in MariaDB variant of the repair fixtures on real InnoDB.

**Deploy.** Step 0 audit first (below). Then the script; the owner runs `migrate --pretend --path=…001400…`, I review, he runs `--force --path=…001400…`. Re-run the FK/index SQL to confirm `ai_calls_ai_reservation_id_foreign`, both uniques and `(customer_id, created_at)` now exist.

**Step 0 — read-only audit** (owner pastes the output of `scripts/ai-prod-audit.sql` from phpMyAdmin):
(a) `SELECT migration,batch FROM migrations WHERE migration LIKE '2026_11_02_%'` — `001200` and `001300` sharing a batch proves the cause. (b) engines for `ai_%`, `customers`, `credit_ledger`. (c) `REFERENTIAL_CONSTRAINTS` + `STATISTICS` for `ai_calls` and `ai_reservations`. (d) `SELECT slug,driver,enabled,live_calls_enabled,commercial_enabled,resale_allowed,agreement_status FROM ai_providers` — expected driver `DeepInfra` proves no call ever reached upstream. (e) `SELECT status,COUNT(*),SUM(amount_irt) FROM ai_reservations GROUP BY status`. (f) `SELECT reason,COUNT(*),SUM(amount) FROM credit_ledger WHERE reason LIKE 'ai%' GROUP BY reason` — **any legacy `ai_reservation` debit is a µUSD number charged as Toman; list those rows for the owner's refund decision before launch.** (g) duplicate `(customer_id, idempotency_key)` counts in both tables. (h) `SELECT COUNT(*) FROM ai_calls WHERE request_body IS NOT NULL`. (i) `SELECT VERSION()`. (j) confirm `vendor/brick/math` exists **on the server** (prod `vendor/` is not this box's).

---

### M5.1a — Pricing & FX services · *ships alone, zero hot-path change*

**Goal.** Land the formula, FX quote, admin settings and the price preview with no change to `/v1`. Nothing can move money.

**Files.** `website/config/ai.php` · `app/Services/Ai/AiFx.php` · `app/Services/Ai/AiPricing.php` · `app/Services/Ai/AiVat.php` · `app/Services/Ai/PriceBook.php` (sellability restrictions, D13) · `app/Console/Commands/AiPricePreview.php` · `app/Http/Controllers/Admin/SettingsController.php` (+`ai_margin_pct`, `ai_sales_open`, `ai_canary_customer_ids`; override bounds 20 k–5 M) · `resources/views/admin/settings/pricing.blade.php` · `app/Http/Controllers/Admin/AiGatewayController.php` + `resources/views/admin/ai/*.blade.php` (model create/edit, driver select, fee, preview) · `lang/{fa,en,tr}/ui.php` via `scripts/lang-apply-keys.php`.

**Tests.** `AiPricingFormulaTest` — exact vectors (`31,050` / `15,525` / `54,000`; one vector with a remainder); a single ceil per stage; overflow ⇒ `request_too_large`; EUR rows use the EUR rate; a currency ≠ provider currency refused; cached > input refused; margin 0 refused; `'12.5'` parses to `1250` bp with no float · `AiPricingPropertyTest` — 10,000 seeded random cases (`R` 20 k–5 M, `f` 0–2500, `m` 1–50000, `t` 0–2000) assert `S ≥ cost_irt`, `S_book ≥ S_floor`, `charged ≤ H` whenever `p ≤ I` and `o ≤ O` · `AiFxTest` — `max(override, scrape)`; a lower override loses; >24 h with no override ⇒ null; 6–24 h adds the buffer + `noteOnce`; the ratchet caps a drop at 3 %/24 h; `Http::preventStrayRequests` proves **no live scrape on the hot path** · `AiVatTest` — `country_code='DE'` ⇒ 0 bp; absent country + `en` locale ⇒ 1000 bp with `vat_basis='no_country'`; `fa` ⇒ 1000 bp; a `tax_rates` `ai` row overrides · `AiSellabilityTest` — provider/global-only price rows refused; NULL `fx_fee_bp` refused; unset `ai_margin_pct` ⇒ `pricing_incomplete` (no cloud default of 45).

**Deploy.** Code only, no migration. Verify with `php artisan ai:price-preview` on prod: FX source + age, fee, margin, `P` values.

---

### M5.1b — Money engine: hold, settlement, failure policy · *ships alone; sales stay CLOSED*

**Goal.** Fix B1/B2/B3/B6/B7/B8/B9/B10/B13/B15/B17/B18/B19 and switch the hot path to Toman holds and real-usage settlement. `ai_sales_open` is absent ⇒ every `/v1` call returns 503 before any reservation.

**Ship together:** `000100` + `000200` (the driver switch must not land without the engine, and vice versa — D16).

**Files.** `database/migrations/2026_11_03_000100_ai_money_core.php` · `…000200_deepinfra_driver_openai_compatible.php` · `app/Services/Ai/AiPayload.php` · `app/Services/Ai/AiUsageParser.php` · `app/Services/Ai/AiSettlement.php` · `app/Services/Ai/AiCaller.php` · `app/Services/Ai/AiReservations.php` · `app/Services/Ai/AiAdmission.php` · `app/Services/Ai/ReservationReconciler.php` · `app/Services/AiProviders/OpenAiCompatibleDriver.php` · `app/Http/Controllers/Ai/V1ChatController.php` · `app/Models/AiUsage.php`, `AiReservation.php`, `AiProvider.php`, `AiModel.php`, `AiModelUnitPrice.php` · `app/Console/Commands/AiReconcile.php`, `AiRecoverUsage.php`, `AiExplain.php` · `routes/console.php` (**+2** `Schedule::command`) · `lang/{fa,en,tr}/ui.php` via the script · existing `AiCallerTest`, `V1ChatTest`, `AiProjectsAndAdmissionTest`, `AiRegistryTest` rewritten.

**Tests.** `AiSettlementTest` — the §3 call debits exactly **327 Toman** (`sell 297`, `tax 30`, `cost 238`), available after = before − 327; cached uses `P_cached` when a row exists and `P_in` otherwise; reasoning not double-charged; zero usage releases with no ledger row; over-bound charges the overage as a second debit, suspends the model and records `uncollected_irt` only when the wallet is short; `estimated_cost` above book uses `max` and flags drift; one `ai_usage` ledger row per usage (UNIQUE) · `AiFailurePolicyTest` — connect refused and provider 4xx release with no ledger row; 402 upstream releases and pauses the provider; 5xx releases; read timeout after upload ⇒ `unknown_pending` + 504 with `x-should-retry: false`; non-JSON 2xx ⇒ 502 + `unknown_pending`; a `Throwable` after the `sending` mark leaves the row in `sending` and the reconciler moves it to `unknown_pending` at `decide_by` · `AiUnknownRecoveryTest` — 3 probes then cap-settle at `H`; a `usage_lookup_url` hit inside the window settles on **real** usage and never charges the cap; the `--late` pass auto-refunds `charged_old − charged_new` with one `ai_refund` row and never a second debit · `AiSettleRetryTest` — a simulated deadlock retries; persistent failure leaves `settle_pending`, the body is still returned, and the reconciler settles once from the persisted values · `AiReconcilerTest` — `reserved` past `decide_by` released; a later B1 update affects 0 rows and `Http::assertNothingSent`; `pricing_version=0` rows are never settled · `AiInlineSweepTest` — with the schedule never run, a second reserve by the same customer settles their stale row inside the lock · `AiHoldNeverLapsesTest` — an M5 hold still counts in `availableOf` after 1 h; legacy expiring rows still lapse; at 25 h the backstop frees it · `AiPayloadTest` — `n=2`, `best_of`, image parts ⇒ 400 with **no** `ai_reservations` row; forwarded `max_tokens` clamped (`Http::assertSent`); `max_completion_tokens` mapped; `I` counts tools JSON bytes; `user` stripped; the hash is key-order independent · `AiBalanceAwareTest` — omitted `max_tokens` + small wallet lowers `O`; below 256 ⇒ 402 naming the required amount · `AiSalesGateTest` — sales closed ⇒ 503 before reserve; canary passes; non-commercial / resale-disallowed / unsigned / NULL-fee / suspended all refused · `AiRegistryTest::test_every_provider_driver_resolves`.

**Deploy.** Full file set including any M3/M4 dependency still missing on prod (match drift by **byte size across `--all`** — prod drift may already be deployed). Owner runs `000100` then `000200`, then resets OPcache. `Schedule::command` count +2. Verify `ai:price-preview`, `/system/ai-status` (counts and booleans only: heartbeat, stuck rows, sales gate), and `grep ERROR storage/logs/laravel.log`.

---

### M5.2 — API contract, idempotency v2, limits, budgets, `/v1/models` · *ships alone*

**Goal.** The public contract a developer actually meets.

**Ship together:** `V1ChatController` + `AiErrors` + `AiResponseRewriter` + `AiIdempotency` (a half-shipped envelope is a broken API).

**Files.** `database/migrations/2026_11_03_000300_ai_replay_store.php` · `app/Services/Ai/AiIdempotency.php`, `AiResponseRewriter.php`, `AiErrors.php` · `app/Http/Controllers/Ai/V1ChatController.php`, `V1ModelsController.php` · `app/Providers/AppServiceProvider.php` (limiters) · `app/Http/Middleware/PageCache.php` (`/v1` exclusion, G6) · `app/Models/AiCall.php` (encrypted `response_enc`, docblock fixed), `AiProject.php` · `app/Console/Commands/AiPruneBodies.php` · `app/Http/Controllers/Account/SecurityController.php` · `routes/console.php` (**+1**) · `routes/web.php` (**last**).

**Tests.** `AiIdempotencyTest` (every row of the §5 table) · `AiBudgetTest` — settled + holds + new hold > budget ⇒ 402; concurrent holds cannot overshoot; the window rolls over on read at `23:59 → 00:01`; token daily cap uses the Tehran day; the reseller fallback is ignored · `AiRateLimitTest` — the 61st request in a minute ⇒ 429 for that key only; two keys behind one IP are independent; the 5th in-flight hold ⇒ 429 `too_many_inflight`; 31 bad tokens per IP per minute ⇒ 429 · `V1ErrorEnvelopeTest` — every code returns `{error:{message,type,code}}` with the mapped status; `x-should-retry: false` on charged errors; **no 5xx after an upstream 200** · `AiResponseRewriteTest` — our slug, `chatcmpl-{public_id}`, `estimated_cost` stripped, `usage.cost_irt` present, both headers present · `V1ModelsTest` — sellable only, prices equal `AiPricing`, ability required, **not HTML-cached** · `AiBodyPruneTest` — rows past `expires_at` deleted; `request_body` never written; `response_enc` round-trips through the `encrypted:array` cast · `V1HostTest` — `POST` to `api.servernet.cloud` is served, never 301'd.

**Deploy.** Owner runs `000300` (which also purges old prompt bodies). `Schedule::command` +1. Verify: `curl -X POST https://api.servernet.cloud/v1/chat/completions` returns 401 with the **envelope shape** (content-verified, not just a status), `schedule:list` shows `ai:prune-bodies`.

---

### M5.3 — Streaming (SSE) + dedicated API host · **LAUNCH BLOCKER** · *ships alone*

**Goal.** `stream:true` works end to end with exact billing. Nothing is sold before this milestone passes its live probe.

**Ship together:** `SseRelay` + `OpenAiCompatibleDriver` + `V1ChatController` + `AiCaller` (the stream path is one contract across four files — `pin-bump-needs-full-file-set`).

**Files.** `app/Services/Ai/SseRelay.php` · `app/Services/AiProviders/OpenAiCompatibleDriver.php` (`chatStream`) · `app/Services/Ai/AiCaller.php` · `app/Http/Controllers/Ai/V1ChatController.php` · `app/Http/Controllers/Admin/AiGatewayController.php` (`stream-probe`) · `routes/web.php`.

**Tests.** `V1StreamTest` — a faked SSE upstream is relayed in order with `model`/`id` rewritten and ends with `[DONE]`; usage on the **finish chunk** (DeepInfra shape) settles exactly; a **separate usage-only chunk** (OpenAI shape) also settles; the debit equals the non-stream debit for identical usage; `usage.cost_irt` appears only when the client asked for `include_usage` · `V1StreamDisconnectTest` — a simulated client abort keeps draining upstream, captures the usage chunk and charges **exact** generated tokens (not the cap), with `delta_count` recorded and never used as the basis · `V1StreamFailureTest` — a pre-first-byte gate failure returns a JSON error with its proper status and **no** SSE; a mid-stream upstream error emits an SSE `error` event then `[DONE]`; a stream cut before usage ⇒ `unknown_pending` ⇒ recovery ⇒ cap; an idle-cap hit ⇒ `unknown_pending` · `SseRelayParserTest` — data split across reads, CRLF, multi-line `data:`, `: keep-alive` comments, a non-JSON data line ⇒ `upstream_bad_body` · `V1StreamReplayTest` — a keyed stream replay synthesises SSE with identical content, `Idempotent-Replayed: true`, and no charge · `V1StreamSettleOrderTest` — settle completes **before** the usage chunk and `[DONE]`; a settle exception still emits `[DONE]` and leaves `settle_pending` · `StreamProbeTest` — admin-only; a non-admin gets 403/404 · `SetTimeLimitGuardTest` — `set_time_limit` is not called under `runningUnitTests()`.

**Deploy + owner infra (one time).** cPanel subdomain `api.servernet.cloud`, docroot = `public_html`, **its own PHP-FPM pool** (MultiPHP Manager, own `max_children`; then set `ai.inflight_global = max_children − 4`), Cloudflare **DNS-only**, AutoSSL. Owner hand-adds to `public_html/.htaccess` (never from the repo): `SetEnvIf Request_URI "^/v1/" no-gzip dont-vary`. **Launch gate:** `curl -N -D- https://api.servernet.cloud/admin/ai/stream-probe` must show no `Content-Encoding` and ~5 events about 1 s apart. If it buffers, launch waits for a WHM `flushpackets` include for `mod_proxy_fcgi`. Verify `ConsoleHost`/`LegacyDomain` never 301 the api host with a real `curl -X POST`.

---

### M5.4 — Revenue/cost ledger, reconciliation, admin reporting · *ships alone*

**Ship together:** `BusinessLedger` + `AiPostDaily` + `FinanceController` + `finance.blade.php` (the category label and the view that reads it).

**Files.** `database/migrations/2026_11_03_000400_ai_ledger_rollup.php` · `app/Models/AiUsageDaily.php`, `AiProviderInvoice.php` · `app/Services/Finance/BusinessLedger.php` · `app/Console/Commands/AiPostDaily.php`, `AiAudit.php` · `app/Http/Controllers/Admin/AiGatewayController.php` · `resources/views/admin/ai/{usage,review,reconcile}.blade.php` · `app/Http/Controllers/Admin/FinanceController.php` · `resources/views/admin/finance.blade.php` · `app/Services/Reports/BusinessReport.php` · `resources/views/admin/transactions.blade.php` · `app/Http/Controllers/Admin/CustomerController.php` · `routes/console.php` (**+2**) · `routes/web.php` (last).

**Tests.** `AiDailyPostingTest` — sums per provider match the fixtures; posts `revenue(ai)`, `tax_collected`, `expense(ai_upstream)` once and a re-run is a no-op; an open day is not posted; a settle after midnight lands on D+1; a changed checksum alerts and does not repost; `occurred_at` is the Tehran date even at 00:10 UTC; `--dry` writes nothing; `Σ sell < Σ cost` alerts · `AiAuditTest` — each seeded violation (I1–I8) exits 1; a clean fixture exits 0 · `AiReconciliationTest` — ≤1 % matched; `paid_irt > accrued_irt` posts one true-up (period-unique, re-save idempotent); a negative variance is reported not posted; the invoice is never an expense · `BusinessLedgerSummaryTest` — `revenue_by_category` contains `ai`; legacy `ai_reservation` rows never appear · `AiAdminRefundTest` — one `+ai_refund` credit row and one business refund row per usage; a second refund refused · `AiAdminPagesTest` — usage/review/reconcile render; every linked route exists (dead-link check: GET-less admin routes have returned 200 before); support role denied where required.

**Deploy.** Owner runs `000400`. `Schedule::command` +2 (`ai:post-daily`, `ai:audit`).

---

### M5.5 — Customer usage portal · *ships alone*

**Files.** `app/Http/Controllers/Account/AiUsageController.php` · `resources/views/account/ai/{index,usage,requests}.blade.php` · `resources/views/account/security.blade.php` · `app/Services/Ai/AiBudget.php` · `app/Notifications/AiBudgetAlert.php`, `AiLowBalance.php` · `app/Http/Controllers/Account/CustomerApiController.php`, `PaymentController.php` (show available vs held) · `app/helpers.php` (`ai_price()`, `ai_money()`, cache-only EUR rate) · `routes/web.php` · `lang/{fa,en,tr}/ui.php` via the script (fixes the stale "no money moves yet" copy).

**Tests.** `AiCustomerUsageTest` — aggregates equal the `ai_usage` sums per day/project/model/key; another customer's rows never appear; CSV columns and totals match; **no prompt or response text anywhere** in the HTML or CSV · `AiMoneyDisplayTest` — `€/1M = ceil(P·1e4/R_eur)/1e4`; unknown `R_eur` hides €; fa shows Toman in Persian digits; `Http::preventStrayRequests` proves no live scrape on render · `AiBalanceDisplayTest` — available = ledger − holds shown beside the ledger sum · `BudgetAlertTest` — 80 % and 100 % once per window, re-sent next window · `LowBalanceNudgeTest` — ≤once per 24 h, never without AI usage.

---

### M5.6 — Public `/ai`, per-model pages, docs, menu · *ships alone*

**Files.** `database/migrations/2026_11_03_000500_ai_model_pages.php` · `app/Http/Controllers/AiProductController.php` · `resources/views/ai/{index,model,docs}.blade.php` · `routes/web.php` · `app/Http/Controllers/SiteController.php` (sitemap, `Route::has`) · `app/Services/MenuManager.php` (menu entry «هوش مصنوعی» next to GPU, `Route::has`) · `lang/{fa,en,tr}/ui.php` via the script.

**Tests.** `AiPublicPagesTest` — `/ai`, `/en/ai`, `/tr/ai` return 200 with prices equal to the `AiPricing` `P` values; fa shows Toman with «+۱۰٪ ارزش افزوده», en/tr show €; an unsellable or suspended model 404s; FX unavailable shows "price temporarily unavailable", never 0 and never a guess; JSON-LD parses; hreflang and canonical correct; the sitemap works while the route name is still absent (`Route::has`) · `MenuGuardTest` — the header renders when the route is missing.

---

### M5.7 — Canary → open sales · *configuration only*

1. Owner sets `ai_margin_pct`, DeepInfra `fx_fee_bp`, model list + USD cost rows (input, output, cached where published), `commercial_enabled` / `resale_allowed` / `agreement_status='signed'` (only after the legal answer), optional `tax_rates` `ai` row, `ai_canary_customer_ids` = his own account. `ai_sales_open` stays **0**.
2. ~12 canary calls with his own key: non-stream, **stream**, stream with a client disconnect, tool call, cached prompt, varying `max_tokens`, small-wallet (balance-aware), bad key, forced timeout on a test model, two with the same `Idempotency-Key`.
3. Every call: `ai:explain {public_id}` must print **PASS**, and `charged_irt` must equal `price × usage + VAT` with exactly one `credit_ledger` row.
4. **Record the raw DeepInfra shape into memory**: does it return `prompt_tokens_details.cached_tokens`? `estimated_cost`? a request id? a usage-lookup endpoint (→ `usage_lookup_url`)? All four are currently **unverified**.
5. Next day: `ai:post-daily --dry`, then the real run; `ai:audit` must exit 0; `/admin/finance` must show the AI line; `/admin/ai/review` must be empty.
6. `ai_sales_open = 1` after one clean canary day. Watch `/admin/errors`, `/admin/ai/review` and the alerts for 48 h.

**Rollback at any point:** `ai_sales_open = 0` stops every new hold instantly; in-flight holds are finished by the reconciler. All migrations are additive with a no-op `down()`.

---

### Post-launch backlog (not M5)

Second provider with a sanctions fallback · `/v1/embeddings` · `/v1/messages` (Anthropic shape, for `claude_code_compatible`) · per-customer prices · tokens-per-minute limiter · internal upstream streaming for non-stream clients (removes read-timeout cap charges entirely) · hourly-cloud revenue rollup (D11) with removal of the `BusinessReport` hourly block in the same change · `credit_ledger` balance checkpoints (a heavy customer past ~100 k rows will slow every reserve) · portal playground.

---

## §9 Questions for the owner

Each is business, tax or legal only. The **safe default** is what the code does until he answers.

1. **VAT and foreign customers.** The rule I implemented: 10 % VAT is added on top **unless `customers.country_code` holds a non-IR ISO code**. Language never creates an exemption, so an Iranian browsing the English site still pays. Do you want en/tr customers exempt, and if so, what proves non-Iranian — a declared country, the phone country code, or something your accountant requires?
   **Default until you answer:** VAT charged to everyone without a declared non-IR country; `vat_basis` is recorded on every call so any refund is traceable.
2. **Margin %.** What global `ai_margin_pct`? Should any model carry its own (a thinner margin on popular cheap models to beat competitors)?
   **Default:** none set ⇒ `pricing_incomplete` ⇒ **sales stay closed**. Nothing is ever sold at a guessed margin.
3. **Real cost of putting $1 into DeepInfra** — card or crypto fee, exchange spread, any foreign tax on their invoice. This becomes `fx_fee_bp`.
   **Default:** `NULL` ⇒ the provider is not sellable. If you want a canary before answering, I will set **1500 bp (15 %)**, deliberately high, so the canary can only over-price.
4. **Legal / sanctions.** Do DeepInfra's terms permit reselling, and do you accept the account-closure risk of serving Iranian end users through a US provider? Should we plan a second provider now?
   **Default:** `agreement_status='none'`, `resale_allowed=0` ⇒ no sales. I will not flip either flag.
5. **Tax invoices (سامانه مؤدیان).** Does AI usage need a formal tax invoice — say one consolidated invoice per customer per month — or is a portal statement enough?
   **Default:** a monthly Toman usage statement in the portal (net, 10 % VAT, total, per model). No formal invoice issued.
6. **Accounting treatment.** The system books the per-call cost daily as `ai_upstream` expense at our FX + your overhead %, and trues it up monthly against the DeepInfra invoice and the Toman you actually paid. That means **your DeepInfra top-ups must be recorded as prepaid money, not as an expense**, or the cost is counted twice. Confirm with your accountant. Do top-ups paid from your personal account need a reimbursement document?
   **Default:** accrue per call; never book a top-up as an expense; post only a **positive** monthly true-up.
7. **Unknown-outcome charges.** When a call times out after the request reached DeepInfra, we hold the money for 15 minutes while we try to recover the real usage, then charge up to the reserved maximum, and auto-refund the difference for 7 days if the truth arrives. Rare, visible in the customer's portal, refundable by you in one click.
   **Default:** exactly as described (this implements your "never absorb upstream cost").
8. **Privacy and terms.** Customer prompts go to a US provider. We store **no prompts**; we keep an encrypted copy of the **response** for 24 hours only for retry-protected requests; usage and billing data are kept permanently. Approve adding this to the terms and privacy policy, plus an AI acceptable-use clause (GDPR matters for EU customers).
   **Default:** the disclosure text ships with M5.6 but **sales do not open** until you approve it.
9. **Price volatility.** Toman prices follow the dollar and can change as often as the rate does. Every call is priced exactly, with no currency risk to you. Acceptable, or do you want a daily price freeze? A freeze needs a larger currency buffer, so published prices would be higher.
   **Default:** per-call pricing; prices move with the rate; no customer email on a price change.
10. **Free starter credit.** Should a new AI customer get free credit to try it, and on what condition (verified phone / KYC)?
    **Default:** none. A customer must top up before the first call.

---

### Unverified assumptions carried into the canary (flagged, not assumed away)

`prompt_tokens_details.cached_tokens`, `usage.estimated_cost`, an upstream request id, and any usage-lookup endpoint are **all unconfirmed for DeepInfra**. The design works without every one of them: no cached row ⇒ cached tokens bill at the full input rate (never below cost); no `estimated_cost` ⇒ the per-call drift check disappears and a stale price row is caught by the monthly invoice reconciliation instead; no lookup URL ⇒ `ai_providers.usage_lookup_url` stays NULL and the unknown path goes straight to the cap with a 7-day replay-only recovery. M5.7 step 4 records the real shape into memory before sales open.