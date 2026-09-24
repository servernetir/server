# AI gateway (M5) — where the plan lives

`code-map.md` — verified map of the gateway as it is today: the money path step by
step with file:line, every confirmed bug, the failing tests and their root causes,
what is missing for a sellable product, and the open design questions D1–D16.

`m5-spec.md` — the specification we build against. Produced by three independent
designs (money-safety / ship-fast / product), scored by three judges, and merged
from the winner with the best ideas of the other two grafted in. It carries:
decisions D1–D16, schema changes, the pricing formula with a worked example, the
settlement algorithm, failure and idempotency policy, ledger design, API surface,
the milestone plan M5.0–M5.7, and the questions only the owner can answer.

`design-*.json` — the three source proposals, kept so a later reader can see what
was rejected and why (§0 of the spec disposes of every flaw the judges raised).

## Owner decisions already made (2026-09-19)

- VAT: 10% added **on top** of the shown price; posted as tax, never as revenue.
- A call that times out after the provider did the work: charge the **real cost**
  the provider reports, capped at the reservation. Never absorb it.
- Streaming (SSE) is a **launch blocker** — nothing is sold before it works with
  exact billing.

## Still blocking the sale (nothing is sold until these are answered)

1. `ai_margin_pct` — the margin the owner types in. Unset means sales stay closed.
2. `fx_fee_bp` — the real cost of putting $1 into the provider (card/crypto fee,
   exchange spread). NULL means the provider is not sellable.
3. Legal: does the provider's contract allow reselling, and is the account-closure
   risk accepted? `agreement_status` and `resale_allowed` stay off until then.

## Progress

- **M5.0** — `ai_calls` repair (001400), admission reason, project budget persisted.
- **M5.1a** — `AiFx` (cache-only FX, max(override, scrape ≤24 h), stale buffer,
  3 %/day drop ratchet), `AiPricing` (§3 formula, integer-only, every step rounds up),
  `AiVat` (VAT unless a non-IR country is declared), `PriceBook::sellRates` (D13),
  admin: `ai_margin_pct` / `ai_sales_open` / `ai_canary_customer_ids`, override bounds
  20k–5M, provider `fx_fee_bp`, model `margin_bp`, price preview on `/admin/ai/pricing`,
  `php artisan ai:price-preview`. `/v1` untouched. Deploy: `scripts/deploy-ai-m5-1a-pricing.sh`.

### Deviations from `m5-spec.md` taken in M5.1a (read before M5.1b)

1. **One small migration in M5.1a**: `2026_11_03_000050_ai_pricing_inputs.php` adds only
   `ai_providers.fx_fee_bp` and `ai_models.margin_bp` — the preview needs them. M5.1b's
   `000100` must keep its `hasColumn` guards on those two columns.
2. **Migration name clash to avoid in M5.1b**: `feature/hourly-credit-transparency` already
   ships `2026_11_03_000100_add_hourly_hold_to_services.php`. Give the AI money core a
   different timestamp (e.g. `2026_11_03_000110_ai_money_core.php`).
3. **`customers.country_code` does not exist.** `AiVat` reads it if present; today every
   customer pays VAT (`fa_locale` / `no_country`). Adding the column is an owner decision (§9 Q1).
4. **Admin pages are Persian-only (hardcoded)**, so M5.1a adds no lang keys.
5. **Driver select and model create** were left for M5.1b: letting the admin switch the
   seeded driver to `OpenAI-Compatible` would open the old µUSD-as-Toman path (D16).
6. **FX drop ratchet = real 24 h window.** `Setting ai_fx_hw_{cur}` holds the max effective
   rate per UTC hour; floor = ⌈0.97 × max of hours started in the last 24 h⌉, so R falls at
   most 3 % inside *any* 24 h span (a single `{rate, at}` mark let it fall 5.9 % in minutes —
   caught in pre-deploy review). With no traffic for 24 h the latest bucket is the reference,
   so a long pause cannot swallow a big drop. A malformed row **closes sales** (with an alert)
   instead of silently disabling the guard. Clear with `ai:price-preview --reset-fx-hw=USD`.
7. **The M1 admin AI deploy was partial on prod**: routes and `AiGatewayController` are live,
   but the five `admin/ai/*` views were never uploaded (the pages 500). M5.1a ships all five.
   `models.blade.php` read a count that does not exist (`active_models`) and 500'd with any
   model row — fixed. Prod runs `validate_timestamps=0`: nothing is live until OPcache is reset.
8. **VAT row lookup**: an `IR` + `product_kind=ai` row is looked up explicitly before the
   generic IR row, because `TaxRate::resolve` does not rank by product kind.
