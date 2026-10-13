# AI gateway codebase map: `feature/ai-gateway-money` @ d5d8f478

**Scope.** Commit d5d8f478 is origin/develop ba710796 plus one commit that only changes tests (the AiPricingTest fix, not pushed). The money-path reader read ba710796 and the other five read d5d8f478. The app code is the same, so this is not a disagreement. Paths are relative to `C:/wt-ai/website`.

**Notation.**
- In this codebase `IRT` means **Toman**.
- µX = 10⁻⁶ of currency X.
- R = Toman per 1 USD.

**Checked by the synthesizer against the code:**
- `throttle:ai` is 12 requests per minute, keyed by user or IP (`AppServiceProvider.php:467,476-481`; `routes/web.php:3367`).
- AiCallerTest has 7 tests, and 6 of them call `provider()`.
- `ExchangeRate.php:116-117` returns 10 for IRR.

---

## 1. How a call flows today

1. **Route.** `routes/web.php:3357-3367` is `POST /v1/chat/completions`. Session, cookie and CSRF middleware are removed. It uses `throttle:ai`: **12 requests per 1 minute**, keyed `ai|` + (user id ?? IP) (`AppServiceProvider.php:467,480`). /v1 has no user, so the key is **per IP**, and the bucket is shared with the site's chat, builder and domain-ideas routes. The comment at `web.php:3353-3355` says this is a deliberate stop-loss until "M5".
2. **Per-token limiter.** `V1ChatController.php:72`: **240 requests/min** keyed `ai-v1-token|sha256(bearer)`. It is checked before the token lookup and returns 429 `rate_limited`. Step 1 means one IP can never reach it.
3. **Token lookup.** `V1ChatController.php:101`: bearer token → `CustomerApiToken::findByPlain` (sha256 compared with `token_hash`). Missing or unknown gives 401. Revoked and expired rows are still returned so admission can report the exact reason.
4. **Admission.** `AiAdmission::authorize`, `AiAdmission.php:33`, checks in this order:
   1. `ai:` scope prefix.
   2. Revoked or expired token.
   3. Token CIDR list (`:56`). The account-level EnforceCustomerIp rules are not applied.
   4. `can('ai:chat')`, which needs the exact ability plus a non-null `ai_project_id` (`CustomerApiToken.php:165`).
   5. Customer active.
   6. Project bound and active.
   7. `budgetWindowCovers(now)` (`:83`). This compares **timestamps only**. `monthly_budget_irt` (Toman) is never compared with spend.
   8. `use_count++` (`:94`, a count). It runs **before** the model, price and funds gates. `last_used_at` and `last_used_ip` are never written.
5. **Payload.** `V1ChatController.php:122-136`: `model` is required (422) and is removed from the payload. The raw `Idempotency-Key` header is read with no length check, although the columns are `varchar(80)`. Then `AiCaller::handle`.
6. **Gates.** `AiCaller.php:91-123`, in order:
   - messages must be non-empty
   - `registry->model(slug)` (404)
   - model status active
   - `provider->isLiveCapable()` = `enabled && live_calls_enabled` (`:110`). **`commercial_enabled`, `resale_allowed` and `agreement_status` are not checked.**
   - the `ai:chat` scope is checked only when the category is chat (`:115`), so non-chat models get through
   - `makeDriver` matches only `'OpenAI-Compatible'` (`:263-266`). The seeded provider has `driver='DeepInfra'`, so every call on it returns 503 `driver_unsupported`.
7. **Price lookup.** `AiCaller.php:133` resolves only `UNIT_INPUT` and `UNIT_OUTPUT`, through `PriceBook::resolve` (`PriceBook.php:31`). The lookup order is customer (a stub that returns null, `:174`) → model → provider default → global.
   - Rate = `price_micro_units` BIGINT = **µ(`currency_code`) per `billing_unit`**. The currency defaults to USD, and the UI offers USD or EUR. For text the billing unit is 1M tokens (`…000100_create_ai_gateway_registry_tables.php:129,133`).
   - The migration comment defines this number as the **provider's list price, which is our cost**. There is no sell price.
   - A null rate gives 503 `not_priced`. The cached-input and reasoning units exist but are never resolved.
8. **Token estimate.**
   - Input = `max(1, ceil(Σ strlen(messages[*].content)/4))` (`AiCaller.php:273-282`), i.e. **estimated tokens from UTF-8 bytes**. It ignores tools, tool_calls, images, response_format and chat-template overhead. Array-shaped content causes an ErrorException and a 500 (`:278`).
   - Output = `max(1, min((int)max_tokens ?? cap, cap))` **tokens**, where cap = `max_output_tokens ?: 4096` (`:288-294`). `max_completion_tokens` and `n` are ignored.
9. **Amount.** `chargeMicros = ceil(rate × tokens / 1e6)` (`PriceBook.php:208`, `MicroMath.php:22,46`) is integer maths, rounded up once per unit type, and gives **µ of that rate's currency**. `reservedMicros = input + output` (`AiCaller.php:151`) is therefore **µUSD**, or a µUSD + µEUR sum when the rows differ. The comment at `:145-150` defers currency conversion to "M5".
10. **Reserve.** `AiCaller.php:158-165` calls `AiReservations::reserve(customer, int $amountIrt = reservedMicros, expires_at = now + 90 s, purpose 'ai', reference = model slug, key)`. The TTL constant is at `:69`; the signature is `AiReservations.php:68-70`.
    - An existing (customer, key) row of **any status** is looked up outside the lock (`:88-89`).
    - The customer row is locked FOR UPDATE.
    - The check is `availableOf('IRT')` = Σ`credit_ledger.amount` (Toman) − Σholding `amount_irt` (numbers that are really µUSD) (`Wallet.php:59,73-77`), compared with the µUSD figure (`:104-109`).
    - A row is inserted in `ai_reservations` with `currency_code='IRT'`, `amount_irt=<µUSD number>` (`:111-120`).
    - **The wallet is not debited.** A same-key race hits the unique index and returns the other row as a duplicate (`:124`). No project or token id is stored.
11. **Upstream call.** `OpenAiCompatibleDriver.php:67-87` POSTs to `{base_url}/chat/completions`.
    - The body is the client payload with only `model` replaced by `upstream_model`. `stream`, `n`, `max_tokens`, `max_completion_tokens` and `tools` pass through unchanged. Timeout is **60 s**.
    - A ConnectionException (a connect failure *or* a read timeout) becomes `upstream_unreachable`. A non-2xx response becomes `upstream_error` (`:77`).
    - A 2xx response is wrapped as `new DriverCallResult(int, array $response->json())` (`:87,99`). A non-JSON or SSE body gives null and a **TypeError**.
12. **Failure branch.** `AiCaller.php:201-209` catches only `AiProviderCallException`, calls `release()` and ignores its outcome. Release (`AiReservations.php:203-225`) sets status `released` and **writes no ledger row**. Any other Throwable propagates as a 500 and leaves the row `pending`. Such a row stops holding funds once `expires_at` passes (`AiReservation.php:56-60`).
13. **Settle.** `AiCaller.php:221` settles the **full reservation** on any 2xx. The `usage` field is never read; the docblock at `:49-56` calls this deliberate ("M4-a").
    - `transition()` (`AiReservations.php:147`) opens a transaction, locks the customer, then locks the reservation. It is idempotent if already settled. It refuses a released or expired row, **and a pending row whose `expires_at` has passed**.
    - It calls `Wallet::debit(customer, 'IRT', amount_irt, 'ai_reservation', source AiReservation, requireAvailable, excludingReservationId)` (`:183-186` → `Wallet.php:139,156-165`). This writes **one `credit_ledger` row, `amount = −amount_irt`, booked as Toman**. `balance_after` is advisory.
    - The reservation becomes `settled` with `ledger_entry_id` (`:188-192`).
    - Only `WalletException` is caught (`:284`). Nothing is written to BusinessLedger: no cost and no VAT.
14. **Call log.** `AiCaller.php:234-249` writes an `ai_calls` row **only if a key was sent and settle succeeded**. It stores `request_body` and `response_body` (full JSON, never purged) and `upstream_status`. Insert failures are swallowed (`:246`). The table has no token, cost, price, FX, project or token columns (`…001300_create_ai_calls.php:32-51`).
15. **Response.** On success the upstream JSON is returned verbatim, including the provider id, its real model name and the `usage` token counts, with `Cache-Control: no-store` (`V1ChatController.php:151`). Otherwise the client gets `{code,message}` with Persian-only messages, using the status map at `:168`. `settle_failed` is a 500 and the upstream body is dropped (`:161`).
16. **Replay.** For the same (customer, key):
    - If an ok `ai_calls` row exists, the stored body is returned with 200, with no upstream call and no charge (`AiCaller.php:181-194`).
    - Otherwise the answer is 409 `duplicate_request` forever (`:195-199`).
    - Both paths put `amount_irt` into the `reservedMicros` field (`:193,198`). The key is not tied to the request body.
17. **Background.** `ReservationReconciler::sweepExpired` has no scheduler entry (`ReservationReconciler.php:30`). `holding()` frees expired funds anyway, but the rows stay `pending`, and `driftReport` does not list them.

**Worked numbers** (R = 100,000 is illustrative):

| Case | Reserved | Debited | True cost at R |
|---|---|---|---|
| Test fixture (`AiCallerTest.php:111-138`): usage 10 + 5 tokens | 43 µUSD | **43 Toman** | 8.75 → 9 µUSD ≈ 0.9 Toman |
| Input 250,000 µUSD/1M, output 750,000 µUSD/1M, 4,000-byte prompt (1,000 tokens), no max_tokens, cap 4,096 | 250 + 3,072 = 3,322 µUSD | **3,322 Toman** | worst case 332 Toman; a 50-token answer = 288 µUSD ≈ 29 Toman. The customer pays about **115×** cost: 10× from the currency bug times 11.5× from charging the full reservation |

---

## 2. Money bugs

All six readers independently **confirm** suspected bugs 1–4. None of the four was refuted. Readers disagreed only on severity and on one statement of direction; each disagreement is resolved inline.

Tags: **[loss now]** means we lose money today; **[latent]** means it starts losing money once a trigger arrives, such as an FX fix or a new unit type.

### 2.1 The four suspected bugs (all confirmed)

**B1. µUSD is debited as Toman. Blocker.**
- **Evidence:**
  - `AiCaller.php:151` computes µ-currency and passes it at `:158-165` as `int $amountIrt` (`AiReservations.php:70`).
  - `AiReservations.php:111-114` stores it with `currency_code='IRT'`, and `:183` debits it.
  - The balance check at `:104-109` compares Toman with µUSD.
  - `AiPriceRate->currencyCode` is never read, and ExchangeRate is never called.
  - The bug is pinned by `AiCallerTest.php:132-138`.
- **Effect:** charged = true price × 1e6/R.
- **Disagreement:** migrations-prod calls it "an overcharge". The money-path, pricing and ledger readers say it is an overcharge below R = 1,000,000 and an undercharge above.
  - **Resolved:** the error factor is 1e6/R, so both are possible. The scraper accepts 20,000–5,000,000 (`ExchangeRate.php:40-41`), a range that includes 1e6.
  - At any rate below 1,000,000 Toman/USD it overcharges; at 100,000 that is 10×.
- **Fix:**
  - At `AiCaller.php:145-165`, convert **each rate part separately** to Toman using one shared FX function (§4.2), then apply the fee and margin and round up to whole Toman.
  - Change `reserve()` to take Toman.
  - Add snapshot columns to `ai_reservations`: source currency, cost_micros, fx_rate, fee_bp, margin_bp, price row id and version.
  - Fix the replay fields at `AiCaller.php:193,198`.

**B2. There is no margin, and the price row is our cost. Blocker for launch.**
- **Evidence:**
  - The registry migration (`:16-25,125-129`) has a single price column, defined as the provider's list price.
  - `PriceBook.php:31` returns that raw row; `:208-211` only multiplies and divides.
  - A grep for `margin` or `markup` finds nothing in the AI code. Only `domain_margin_pct` and `cloud_margin_pct` exist. There is no FX or transfer fee either.
- **Severity disagreement:** high (money-path, api-admission) versus blocker (pricing, migrations).
  - **Resolved: blocker for launch.** No sale can be above cost until a margin exists.
  - It is not a loss today only because B1 overcharges.
- **Fix:** §4.1. The call site is `AiCaller.php:151`.

**B3. The customer is charged the full worst-case reservation; usage is ignored. High.**
- **Evidence:**
  - `AiCaller.php:49-56` and `:221`; `AiReservations.php:183`.
  - `usage` appears only in comments (`AiCaller.php:42,52,271`; driver `:93`, which wrongly says AiCaller "owns usage interpretation").
  - The customer sees the provider's `usage` in the response (`V1ChatController.php:151`), which disagrees with the debit.
  - The cached-token discount is never applied.
- **Fix:**
  - At `AiCaller.php:221`, parse `usage.prompt_tokens`, `completion_tokens` and `prompt_tokens_details.cached_tokens`.
  - Add `AiReservations::settleActual($r, $chargeToman)`: debit min(actual, amount_irt) and close the remainder in the **same** transition.
  - Add `reserved_irt` and `charged_irt` columns.
  - Policy for missing usage: §5 D4.

**B4. No revenue, cost or VAT reaches BusinessLedger. Blocker for launch (finance), not a cash leak.**
- **Evidence:**
  - Settle writes only a `credit_ledger` row (`AiReservations.php:183`).
  - Top-ups are deliberately not revenue (`BusinessLedger.php:98-102`).
  - `recordCreditSale` needs an Invoice (`:321-342`; its only caller is `ResellerOrderService.php:446`), and AI creates none.
  - There is no AI expense category (`:32-35`).
  - `hourlyCloudIncome` whitelists only the `cloud_hourly*` reasons (`BusinessReport.php:193-196`).
  - The top-up invoice has tax 0 on the promise that VAT is charged at consumption (`PaymentController.php:561`). For AI that never happens.
- **Resolved severity:** ledger reader says blocker, the others say high. Result: blocker for launch. /admin/finance, the dashboard and reports all under-state revenue, and the no-loss rule cannot be audited.
- **Do not** sum the existing `ai_reservation` credit rows as revenue: their amounts are in the wrong unit (B1).
- **Fix:** §4.6.

### 2.2 Other paths that lose money

**B5. `stream:true` is a free call. Blocker. [loss now]**
- **Agreed by:** money-path, api-admission and ledger.
- **How it fails:**
  - The driver passes the payload through (`:67`).
  - Laravel's HTTP client buffers the whole SSE stream, so the provider generates and bills the full answer.
  - `json()` returns null, and `DriverCallResult(array)` throws a TypeError (`:87,99`).
  - `AiCaller.php:203` does not catch it, so the client gets a 500.
  - The reservation stays pending and lapses after 90 s. **The customer pays 0.**
- **Exposure:** any key holder can repeat it, and many SDKs send `stream:true` by default. The controller is declared to return `JsonResponse` (`V1ChatController.php:80`), so SSE is impossible without a signature change.
- **Fix:**
  - At `V1ChatController.php:122`, return 400 for `stream:true` **before** `AiCaller::handle`.
  - At driver `:87`, throw an `AiProviderCallException` (`upstream_bad_body`) when the body is not an array.
  - In `AiCaller.php:201-209`, catch Throwable after the request has been sent and **settle** (not release) under the §5 D7 policy.

**B6. The reservation is not a ceiling. High. [latent: sells below cost once B1 is fixed]**
- **Evidence:** output is capped only in the estimate (`AiCaller.php:288-294`); the forwarded payload is never rewritten (driver `:67-70`). Ways real cost can exceed the reservation:
  - `max_tokens` above the model cap
  - `max_tokens` omitted while the model cap is null: 4,096 is reserved, but the provider default may be larger
  - `max_completion_tokens`
  - `n>1`
  - tools, images and response_format missing from the input estimate
- **Fix (before `AiCaller.php:202`):**
  - Force the forwarded `max_tokens` to the reserved output-token count.
  - Map or strip `max_completion_tokens`.
  - Reject `n>1`, or multiply the reservation by n.
  - Reject tools and images, or add their JSON length to the input estimate.
  - After usage-based settlement: charge ≤ reservation, and alert whenever usage exceeds it.
- **Persian-estimate disagreement:** pricing and tests say bytes/4 **doubles** the Persian estimate. api-admission says bytes/4 **under-counts** some scripts.
  - **Resolved:** both are relative claims. Against a chars/4 rule Persian is 2×; against a real tokenizer the direction depends on the model and has not been measured.
  - After B3 is fixed this is moot for the charge, because the estimate only sizes the hold.
  - Calibrate from the stored `usage.prompt_tokens`.

**B7. Provider sales gates are ignored. High.**
- **Evidence:**
  - `AiCaller.php:110` checks only `isLiveCapable()`.
  - `AiModel::isSellable` (`AiModel.php:82-87`), `AiProvider::isCommerciallySellable` (`AiProvider.php:58-61`) and `AiModelRegistry::sellableModels` are never used.
  - The fixtures (`V1ChatTest.php:84-88`) sell through a provider with `commercial_enabled=false`.
- **Severity:** api-admission says high, tests say medium. **Resolved: high**, because it is a resale-contract exposure.
- **Fix:** at `AiCaller.php:99-110`, require `isSellable()` and a valid agreement.

**B8. A read timeout releases the whole reservation. Medium. [loss now]**
- **How it fails:** a cURL timeout (errno 28) becomes a ConnectionException and then `upstream_unreachable` (driver `:77`), and `AiCaller.php:209` releases the reservation even though the provider may already have billed us. A process kill between the upstream response and settle has the same effect.
- **Fix:**
  - Give read timeouts their own code in the driver.
  - Apply the §5 D7 policy.
  - Scale the timeout with `max_tokens`.
  - Reconcile against the provider's invoice.

**B9. A settle failure after a successful upstream call. Medium. [loss now]**
- **How it fails:**
  - `AiCaller.php:223` returns `settle_failed`. The TTL is 90 s against a 60 s HTTP timeout, which leaves 30 s or less for settle including lock waits. Other causes are `insufficient_funds` and `reservation_missing`.
  - A deadlock or lock-wait QueryException escapes `transition()` (`AiReservations.php:257-287`, which catches only WalletException at `:284`).
  - The body is dropped (`V1ChatController.php:161`), no `ai_calls` row is written, and a retry gets 409.
- **Fix:**
  - Retry `transition()` on deadlock or lock wait.
  - Let settle accept a still-`pending` reservation past `expires_at` when the originating request settles it, or set TTL = timeout + margin.
  - Write a `settle_failed` usage row for reconciliation.

**B10. A mixed-currency sum. Medium. [latent]**
- **Evidence:** `AiCaller.php:151` adds the two parts. `PriceBook::supersede` lets each row have its own currency (`PriceBook.php:84-90`).
- **Fix:** convert each part separately (done by the B1 fix), or force every row's currency to the provider's `billing_currency_code`.

**B11. `chargeMicros` divides by 1e6 for every unit. Medium. [latent]**
- **Evidence:** image, audio_minute and request are billing units (`PriceBook.php:125-139`), but `:208` always divides by 1e6. A $0.04 image is charged 1 µUSD, which is 10⁶× too cheap. These units can already be priced in the admin UI.
- **Fix:** divisor per billing unit in `AiPriceRate::chargeMicros`.

**B12. The idempotency key is used up by a failed attempt and is not tied to the request. Medium.**
- **Evidence:** `AiReservations.php:88-89` returns a row of any status, so a retry after a release gets 409 forever. The same key with a different prompt replays the old answer.
- **Fix:**
  - Make released keys reusable. This means reworking the unique index, for example by nulling the key on release.
  - Store sha256 of the body and answer 422 on a mismatch.

**B13. Nothing is stored to audit a charge. Medium.**
- **Evidence:** `AiPriceRate` carries `priceId` and `version` (`PriceBook.php:189-194`), but they are never persisted. Neither table has currency, FX, token, usage or cost columns.
- **Fix:** §4.5.

**B14. Project budget and daily cap are never enforced, and spend is not attributable. Medium.**
- **Evidence:**
  - `AiAdmission.php:83` checks only the window dates.
  - `CustomerApiToken::dailySpendCap()` (`:238-251`) has no callers.
  - `reference` holds the model slug (`AiCaller.php:164`), so no project or token is recorded.
- **Severity:** api-admission says high, money-path says low. **Resolved: medium.** The budget chip (`security.blade.php:213`) is a promise to the customer, but the customer's overspend is not our loss.
- **Fix:**
  - Add `ai_project_id` and `customer_api_token_id` to reservations.
  - Enforce Σ(settled + held in window) + new ≤ budget inside the `reserve()` lock.

**B15. The PriceBook fallback can sell a model at a generic rate. Low today.**
- **Severity:** api-admission says medium. **Resolved: low for now**, because no provider-level or global rows can be written:
  - `supersede` always sets `ai_model_id` (`PriceBook.php:108-120`).
  - `resolveAt` (`:50`) cannot reproduce such rows anyway.
- **Fix:** require a model-level row before a model can be sold, and make `resolveAt` follow the same fallbacks as `resolve`.

**Low-severity items:**
- **B16:** `forModel` and the `supersede` close step ignore `customer_id` (`PriceBook.php:96-101,143`). Once customer prices exist, they would leak to everyone.
- **B17:** `toToman('IRR')` returns 10 (**verified** at `ExchangeRate.php:116-117`). The correct value is 0.1, and an int return cannot express it. Fix: accept only USD and EUR in AI pricing (`AiGatewayController.php:187`, `PriceBook.php:88`).
- **B18:** `MicroMath.php:46` has no overflow guard, so an overflowing product becomes a TypeError and a 500.
- **B19:** the manual FX override accepts up to 1e8 with no 20k–5M bounds (`SettingsController.php:148`). The scraper has no check against the previous rate (`ExchangeRate.php:280-307`).
- **B20:** `DomainSearch::rateFor('USD')` ignores `pricing_usd_rate_override` (`:801-812`). Do not copy it.

### 2.3 Defects found along the way that are not money bugs (launch blockers marked)

| Defect | Evidence | Severity (resolved) | Fix location |
|---|---|---|---|
| Seeded DeepInfra driver is `'DeepInfra'`, so every call gets `driver_unsupported` | registry migration `:161`; `AiCaller.php:263-266`; `AiGatewayController.php:53-62` cannot edit the driver | tests: high, migrations: medium, pricing: low → **high / launch blocker** (the only provider cannot serve a call; no money at risk) | data migration plus a driver field in updateProvider |
| Prod `ai_calls` half-built but recorded as migrated | §4.10 | **blocker** | repair migration 001400 |
| hasTable early return plus non-transactional DDL turns a failure into "done" | 001000:32, 001200:31, 001300:28 | high | per-step guards |
| 12/min per-IP shared bucket | `web.php:3367`, `AppServiceProvider.php:467` (verified) | **launch blocker** for an API product (deliberate stop-loss per `web.php:3353`) | dedicated per-key limiter |
| Project-create form silently drops the budget | `SecurityController.php:328-340`; form `security.blade.php:238-256` | medium | infer a monthly period, as update does (`:376-383`) |
| Budget window never rolls over, so all keys lock out at month end | `AiProject.php:129`; refreshed only at `SecurityController.php:342,383` | medium (dormant until the row above is fixed) | compute the window on read, or schedule a refresh |
| Array content gives 500 | `AiCaller.php:278` | medium | count text parts; reject images or price them |
| Key longer than 80 chars gives 500 (strict mode) or truncation collisions | `V1ChatController.php:136`; 001200:47 | low | validate ≤ 80 |
| Provider model name, id and possibly `usage.estimated_cost` leak | `V1ChatController.php:151` (the cost field is **unverified**) | medium | rewrite `model` and strip provider fields |
| Full prompts and responses kept forever | 001300:43; `AiCaller.php:242-243` | low/medium (GDPR for en/tr) | separate table plus retention |
| Same key, low balance: 402 instead of duplicate | `AiReservations.php:104` | low | re-check the key under the lock |
| `release()` outcome ignored; sweep never scheduled | `AiCaller.php:209`; `ReservationReconciler.php:30` | low | log it; schedule the sweep |
| use_count counted before gates; last_used never written | `AiAdmission.php:94` | low | move the increment; write last_used |
| Non-chat models callable through chat | `AiCaller.php:115` | low | 404 unless category is chat |
| Stale copy says "no money moves yet"; budget in Toman for en | `lang/en/ui.php:3181-3188` (same in fa/tr) | low | lang keys |

### 2.4 Refuted or contradicted

- **"Concurrency can double-reserve or double-charge." REFUTED.**
  - Locks are taken customer first, then reservation. The balance is checked inside the lock. State transitions are conditional. No transaction is held open during the HTTP call.
  - UNIQUE(customer_id, idempotency_key) plus the catch at `AiReservations.php:124` handles same-key races.
  - **Caveat:** on prod, `ai_calls` is missing its unique index (§4.10), and `ai_reservations`' unique index has not been verified.
- **"Other wallet writers can spend held funds." REFUTED.** Every `Wallet::debit` call uses `requireAvailable=true`. The direct `CreditEntry::create` writers (ServiceController, CloudProvisioner, UndeliveredRefund, DomainTransfer, ResolveStuckDomains) only write positive credits.
- **"Orphaned reservations lock funds forever." REFUTED.** `holding()` excludes rows past `expires_at` (`AiReservation.php:56-60`). Only the status is stale.
- **"The AiPricingTest fix is uncommitted or the maths is wrong." REFUTED.** It is committed in d5d8f478 and passes 29/29. The corrected vector checks out: 245,000 × 1,234,567 = 302,468,915,000, which rounds up to 302,469.
- **"The branch base is stale." REFUTED.** d5d8f478 = ba710796 + 1 commit that only touches tests.
- **"errno 150 comes from a type, collation, engine or NOT NULL mismatch." REFUTED.** Both sides are BIGINT UNSIGNED, the child column is nullable, the parent is the primary key, and the customer FK created one statement earlier succeeded, so the engine is InnoDB.
- **"`ai_reservations` does not exist on prod" (commit 703c68cd). CONTRADICTED.** `/system/tables` lists 001200 as run. The claim in 703c68cd was an inference. Confirm with SQL (§4.10).
- **"Keyless calls are recorded" (`AiCall.php:13` docblock). FALSE.** This is a documentation bug.
- **`blindSpots()` saying fees, domain wholesale and refunds are entered by hand. STALE.** They are automated now (`BusinessLedger.php:145,381`; `UndeliveredRefund.php:106`, and others).

---

## 3. Failing tests

**The run:** `php artisan test --filter='Ai|V1Chat'`. The regex also matched Domain\*, Mail\* and other classes.
- 1045 tests: 1021 passed, **3 failed, 15 errors**, 6 skipped. Exit code 2, 8m39s.
- The 6 skips are all of `AiReservationMariaDbStressTest`, which needs MariaDB and `AI_RESERVATION_STRESS`.
- All 18 non-passes are in 3 AI classes.
- Green: AiPricingTest 29/29, AiRegistryTest 15/15, AiReservationsTest 17/17, AiAdminPagesTest 10/10. AiProjectsAndAdmissionTest passes 27/30.

**Count disagreement.** The tests reader said both "all 6 AiCallerTest tests" error and "6/7 pass after the fix". **Resolved:** the class has 7 tests (verified). `test_denied_admission_passes_through_without_side_effects` never calls `provider()`, so it passed.

| Test | Result | Cause | Which side is wrong | Fix |
|---|---|---|---|---|
| AiCallerTest, 6 tests using `provider()` | error: UNIQUE `ai_providers.slug` | fixture (`AiCallerTest.php:48`) creates `deepinfra`, which the registry migration already seeds (`:156-172`); committed without ever running (dbe2a342) | **Test**, but it hides a **code** bug: the seeded driver is `'DeepInfra'`, so 5 of 7 tests get `driver_unsupported` when the seeded row is used | data migration setting driver = `'OpenAI-Compatible'`; fixture loads the seeded row with `where('slug','deepinfra')->firstOrFail()->update([...])`; AiRegistryTest asserts the seeded driver resolves to a class |
| V1ChatTest, all 9 | error: same | `catalog()` at `V1ChatTest.php:84` | **Test** (same hidden code bug) | same |
| AiCallerTest::test_duplicate_idempotency_key_never_calls_upstream_twice (`:257`) | fails once the fixture is fixed: `assertFalse($dup->ok)` gets true | M4-c replay (`AiCaller.php:181-194`) returns the stored answer as ok | **Test** (replay is intended) | assert ok, same body and `Http::assertSentCount(1)`; add a body-mismatch case after B12 |
| V1ChatTest::test_duplicate_idempotency_key_calls_upstream_once_and_409 (`:306`) | fails once the fixture is fixed: 409 expected, 200 received | same | **Test** | expect 200 with an identical body and rename the test; update the stale comments at `AiCaller.php:58-64`, `V1ChatController.php:38,55-61,185`, `V1ChatTest.php:26-27,301-307` |
| AiProjectsAndAdmissionTest::test_project_create_budget_monthly (`:68`) | fail: null !== 500000 | `SecurityController.php:328-340` defaults the period to `'none'` when `budget_period` is missing; the real form never sends it | **Code** | infer monthly when `monthly_budget > 0`; ship the budget-window rollover fix in the **same** change, because this fix activates the month-end lockout |
| …::test_admission_denies_archived_and_missing_project (`:346`) | fail: returns `insufficient_scope`, test accepts `project_missing` or `not_ai_key` | the nullOnDelete FK (`001100:33-34`) nulls `ai_project_id`, so `can()` is false (`CustomerApiToken.php:165-168`) while `isAiKey()` is still true, and `AiAdmission.php:60-61` returns first | **Code** (reason code only; access is still denied) | in AiAdmission, return `project_missing` when `isAiKey() && ai_project_id === null`, before `can()` |
| …::test_budget_window_boundaries (`:425`) | fail: false is not true | `refreshBudgetWindow()` uses now() (`AiProject.php:107-125`); the test only passes between 2026-11-15 and 2026-12-14 | **Test** | `Carbon::setTestNow('2026-11-20 10:00')` before creating and refreshing; reset in tearDown |
| AiCallerTest::test_success_settles_full_reservation (`:130-138`) | **green, but pins B1 and B3** (43 µUSD debited as 43 Toman; real usage 9 µUSD) | expectation encodes the bugs | **Expectation** | rewrite: with a fixed rate override, debit = ceil(usage cost × (1+fee) × (1+margin) × R / 1e6) Toman; it is expected to go red when B1 and B3 land |
| V1ChatTest and AiCallerTest fixtures with `commercial_enabled=false` | **green, but pins B7** | fixtures sell through a provider not enabled for sale | **Expectation** | set the commercial, resale and agreement flags in fixtures; add a test that a non-commercial provider is refused |

**Tests that do not exist yet:**
- `stream:true` is rejected before reserve.
- A non-JSON 2xx body.
- The forwarded `max_tokens` is clamped (`Http::assertSent`).
- `n>1`.
- The per-key 429.
- The replay path.
- Settlement from usage.
- FX missing → 503.
- Margin floor.
- Budget enforcement.
- BusinessLedger posting.
- The repair migration on a half-built schema. The stress suite uses `migrate:fresh` (`AiReservationMariaDbStressTest.php:171`), so it cannot reproduce that state.

---

## 4. Missing for a sellable product

**4.1 Margin setting.**
- Add `ai_margin_pct` to `SettingsController` FIELDS['pricing'] (`:146`). Give it a floor above 0 (see D3) and max 500; `savePricing` then saves it automatically (`:730`).
- Add `pricing_fx_fee_pct_deepinfra` to the same list. Only the hetzner, aeza and salad fee keys are listed today, so any other key is silently dropped.
- Add both to `pricingData()` (`:416`), together with the live **USD** rate; only EUR is shown today.
- Add both inputs to the "margin per product" panel in `resources/views/admin/settings/pricing.blade.php:66`.
- Read them the way `CloudPricing::marginPct()` (`CloudPricing.php:36`) and `fxFeePctFor('deepinfra')` do. `Setting::put` clears the 300 s cache (`Setting.php:66`).

**4.2 IRT pricing.**
- New `App\Services\Ai\AiPricing::sellToman(rate, units)` = ceil(cost_µ × (1+fee) × (1+margin) × R / 1e6), called from `AiCaller.php:145-165`.
- Add a shared `CloudPricing::usdToToman()`: `pricing_usd_rate_override` → cached ExchangeRate → null. It mirrors `SaladOperations::usdToman` (`:29`) and `eurToToman`.
- **Never** call `toToman()` on the /v1 hot path. On a cold cache it scrapes live with a 30 s timeout and 2 retries (`ExchangeRate.php:111`). Read the cache or Setting only; if the rate is missing or too stale, return 503 `fx_unavailable`.
- New migration on `ai_reservations` for the snapshot columns listed in B1.

**4.3 EUR for en/tr.**
- The wallet stays IRT: EUR top-ups are converted (`PaymentController.php:536`), so € is display only.
- `cloud_price()` rounds to €0.01 (`helpers.php:847,858`), so per-call amounts would show €0.00. Use `cloud_hourly_price` precision (`:874`) or a new `ai_price()` helper that shows € per 1M tokens, with the rate from `cloud_eur_rate()` (`:917`).
- Fix the copy at `lang/{fa,en,tr}/ui.php:3181-3188`. Ship it with `scripts/lang-apply-keys.php`, not as whole-file merges.

**4.4 Settlement correctness.**
- B3: `settleActual` at `AiCaller.php:221` and `AiReservations`.
- B6: rewrite the forwarded payload before `AiCaller.php:202`.
- B5: reject `stream` at `V1ChatController.php:122`.
- B8 and B9: failure policy in `AiCaller.php:201-223` and `AiReservations.php:257-287`.

**4.5 Per-call usage record.** Either extend `ai_calls` or add `ai_usage` (the ledger reader recommends the new table).
- Write it as `pending` **before** the HTTP call and complete it afterwards.
- Record **every** call, keyed or not, including failures.
- Columns:
  - `ai_project_id`, `customer_api_token_id`, model and provider ids with slug snapshots, upstream request id
  - prompt, cached, completion and reasoning tokens
  - price row ids and versions, `cost_micros` and its currency, fx_rate, fee_bp, margin_bp
  - `reserved_irt`, `charged_irt`, `tax_irt`, `revenue_irt`, `cost_irt`, `credit_ledger` id
  - status and error code, latency, Tehran `day`
- Move request and response bodies to a separate table with a retention prune.
- At settle, check `charged_irt ≥ cost_irt` and alert or refuse.
- Fix the docblock at `AiCall.php:13`.

**4.6 Revenue and cost ledger.**
- New rollup table `ai_usage_daily` (Tehran day × provider).
- New `BusinessLedger::recordAiDay()` using the rollup row as `source_type`/`source_id`, so the existing unique (source_type, source_id, kind) keeps posting idempotent. `period` is char(7) (`2026_09_29_000101:46`) and cannot hold a day.
- Each rollup posts:
  - revenue with category `ai`; today `post()` writes a null category (`:113,334`)
  - `tax_collected` when VAT applies
  - an expense in a new `ai_upstream` category, added to EXPENSE_CATEGORIES and CATEGORY_LABELS (`:32-35`)
- `summary()` (`:535`) needs a `revenue_by_category` breakdown.
- New command `ai:post-daily`, modelled on PostServerRent (`:178`: skip when the rate is missing, put the rate in the note, support `--dry`).
- Schedule it in `routes/console.php`, and count the `Schedule::command` entries before and after the deploy (prod console.php has been overwritten before).

**4.7 Customer usage report.**
- New route in the account group, near the AI project routes (`routes/web.php:373`, `:578`), with a view showing:
  - totals per day, project, model and key: calls, input, cached and output tokens, spend
  - month-to-date spend against `monthly_budget_irt`
  - CSV export
  - recent calls, without prompts
- On `security.blade.php:375-380`, add the /v1 base URL and the model list.
- Show **available** balance as well as the ledger sum: today only the ledger sum is shown (`CustomerApiController.php:74`, `PaymentController.php:47-55`).

**4.8 Admin views.**
- **/admin/ai** (`AiGatewayController`, `routes/web.php:2708`):
  - a usage, revenue, cost and margin page
  - stuck reservations: pending past TTL, `settle_failed`, and the TypeError orphans
  - **model create**: nothing outside tests creates `ai_models` rows (`AiGatewayController.php:127` can only edit)
  - driver and billing-currency editing (`:53-62`)
- **Pricing page** (`pricing.blade.php:11,51`): show the Toman and € sell price next to the cost, a margin preview and a below-floor warning; allow only USD and EUR.
- **/admin/finance** (`FinanceController.php:37`): show revenue by category.
- **/admin/reports**: add AI to `blindSpots()` (`BusinessReport.php:604,658`) and remove the stale lines. Do **not** add `ai_reservation` to the hourly side block (`reports.blade.php:157`).
- **Dashboard** `fin` (`DashboardController.php:46`) inherits the above.
- **Transactions** `reasonLabel` (`transactions.blade.php:14`): add `ai_reservation` and `cloud_hourly`.
- **Customer page** credit list shows the last 50 rows (`CustomerController.php:340`). Group AI rows per day, or every AI call pushes older entries off the list.

**4.9 /ai product page and public API.** None of this exists today. The only AI endpoint is POST `/v1/chat/completions`. There is no `/v1/models`, although the `ai:models:read` ability is offered (`CustomerApiToken.php:68-76`). `sellableModels` is unused (`AiModelRegistry.php:74`).
- Add a trilingual route in the locale group of `routes/web.php`, a new controller, and `resources/views/ai/*.blade.php`. The page lists sellable models with the sell price per 1M input and output tokens (Toman for fa, € for en/tr), context size, a quickstart (curl, OpenAI SDK `base_url`) and a link to `/account/security#sec-ai`. It follows the site layout rules: `#main` already reserves 110px, so the page adds no padding-top; IRANSans is the font.
- Add `GET /v1/models` inside `Route::prefix('v1')` (`web.php:3357`), gated by the `ai:models:read` ability.
- Use the OpenAI-style error envelope `{error:{message,type,code}}` with English messages; `V1ChatController.php:168` returns Persian-only `{code,message}` today.
- Replace `throttle:ai` with a limiter keyed by token.

**4.10 Prod migration fix.**
- **What happened:**
  - 001300 failed at 2026-09-15 09:26:25 UTC with errno 150 on the `ai_reservation_id` FK.
  - MariaDB DDL is not transactional (`Grammar.php:31`, `Migrator.php:448-449`), so `ai_calls` stayed with all its columns and the customer FK. It is missing the reservation FK, UNIQUE(customer_id, idempotency_key) and INDEX(customer_id, created_at).
  - A later migrate hit the `hasTable` early return (001300:28-30) and recorded 001300 as run.
  - On 2026-09-19, `/system/db-status` showed `pending: []`, and `/system/tables` listed all five AI migrations as run.
- **Cause:** `ai_reservations` did not exist when 001300 ran, which means the 001200 file was not deployed at that moment. This matches commit 703c68cd finding `Wallet.php` NEW on prod: M3 was only partly deployed.
- **Steps:**
  1. Run the read-only SQL: batch numbers from `migrations`, engines from `information_schema.tables`, FKs from `referential_constraints`, indexes from `statistics`. If 001200 and 001300 share a batch number, the cause is proven.
  2. Add a new migration `2026_11_02_001400_repair_ai_gateway_fks.php` with a guard on each step:
     - check that the tables are InnoDB
     - create `ai_reservations` if it is missing
     - `ensureFk`, `ensureUnique` and `ensureIndex` for both tables (`Schema::hasForeignKey` and `hasIndex`, `Builder.php:444,469`)
     - before adding the SET NULL FK, null orphaned references
     - duplicate `ai_calls` keys: keep MIN(id) and null the rest
     - duplicate `ai_reservations` keys: **throw**, for human review
     - `down()` does nothing
  3. Ship it with the 001200 and 001300 files, the full M3/M4 code set (Wallet.php and the rest), and the DeepInfra driver data migration. Prod is a subset of develop, so send the exact file set.
  4. The owner runs `php artisan migrate --force --path=database/migrations/2026_11_02_001400_repair_ai_gateway_fks.php`; Claude only does the dry run.
  5. Longer term: replace the early-return guards in 001000, 001200 and 001300 with per-step guards. 000100 and 001100 have no guard at all. Also remove the duplicate index at `001100:39-41` (low).

---

## 5. Design decisions that need a choice

**D1. When to convert USD to Toman.**
- **(a) Per call at reserve time**, with the rate snapshotted on the row.
  - For: always current, and every charge can be audited exactly.
  - Against: the published Toman price changes every hour (fx:dollar runs hourly), and a usable rate must be present on the hot path.
- **(b) Per price version:** a stored Toman sell price, re-priced by a job when FX moves more than X%.
  - For: stable published prices that `resolveAt` can reproduce.
  - Against: FX risk between re-prices has to be covered by the fee buffer; needs a cost/sell split in the schema and a re-price job.
- **(c) One frozen rate per Tehran day.**
  - For: prices are stable for a day, and one rate per daily rollup matches the ledger.
  - Against: up to 24 h of FX drift.
- **Lean:** (c) with the rate snapshotted per call, or (a) if prices are always shown "at today's rate".

**D2. Whose rate, and what happens when it is stale.**
- Source options: override → scraped free-market rate (alanchand; 20k–5M bounds; no check against the previous value). Or our real cost of buying USD, modelled as the fee %.
- The 48 h stale fallback (`ExchangeRate.php:192`) is too loose for per-call billing. Options:
  - refuse when the rate is older than N hours: safe, but can cause outages
  - accept it and add an automatic extra buffer
- Also add range and delta checks to the manual override.

**D3. Margin structure and floor.**
- Granularity: global %, per provider (like `pricing_fx_fee_pct_{slug}`), per model, or per customer (`forCustomer` is a stub).
- Order of fee and margin does not matter mathematically because they multiply. It only matters where rounding happens, or if a fixed per-call fee is added.
- Floor options:
  - enforce margin > 0 plus a mandatory fee in validation (the no-loss red line)
  - allow 0, as `cloud_margin_pct` does (min 0)

**D4. Settlement basis.**
- **(a) Settle usage × sell price and release the remainder in the same transition.** One ledger row per call; needs `settleActual`.
- **(b) Settle the full amount, then post a refund credit.** Keeps the current code, but gives two rows per call, and the refunds look like refunds in reports.
- Missing or malformed `usage`:
  - charge the full reservation: safe for us, hostile to the customer
  - charge the input estimate plus output estimated from the completion length
  - absorb it and flag it
- Usage above the reservation: impossible if `max_tokens` is forced (B6). Otherwise either cap the charge at the reservation (we absorb the rest) or allow an overdraft.
- Cached and reasoning tokens:
  - price them as their own units (those units exist)
  - or charge cached tokens as normal input and keep the provider's discount as margin
- Whether DeepInfra returns `cached_tokens` or a cost field is **unverified**; check a live response first.

**D5. Rounding.**
- Sell price is always rounded up; cost is kept exact in µ units, so sell ≥ cost holds per call. **Never** use the IRT `rounding_step` (1000) per call.
- **(a) Round up to whole Toman per call (minimum 1 Toman).** Simple and fits the integer ledger. The bias is under 1 Toman per call: +0.7% on a 28.8-Toman call, but about +54% on a 1.3-Toman embedding call.
- **(b) Accumulate micro-Toman and debit once a day.** Exact, but needs an accumulator column, makes the balance check fuzzy, and loses the per-call debit.
- **(c) Minimum charge per request** to cover overhead.
- Also decide whether to keep the per-unit round-up in µ-currency (`PriceBook:208`) before the Toman round-up, which rounds twice.

**D6. Streaming.**
- **Reject with 400 now.** Immediate stop-loss; costs SDK compatibility.
- **SSE support:** `StreamedResponse`, `stream_options.include_usage`, settle at the end of the stream, and charge for what was generated when the client disconnects. Needs the controller signature changed from `JsonResponse`.

**D7. Failures after the upstream did the work** (read timeout, settle failure, crash).
- Options: charge the full reservation; charge the input estimate only; or absorb the cost and reconcile monthly against the provider's invoice.
- Also decide whether a late settle may take the wallet below zero after the hold has lapsed, or must be refused.

**D8. Idempotency semantics.**
- A failed attempt either burns the key (today) or frees it for retries (the standard client pattern).
- Should the key be bound to a hash of the request body?
- How long is the replay window? That decides how long bodies must be kept.

**D9. EUR for en/tr.**
- IRT wallet with € display (the current pattern), or a separate EUR wallet (`credit_ledger` already has `currency_code`).
- Display rate: Toman ÷ EUR rate (two scraped numbers), or a direct USD→EUR cross rate.
- Receipts and invoices in € or not.

**D10. VAT and revenue recognition.**
- fa prices: 10% VAT included, split into revenue + `tax_collected`, or added on top. en/tr at 0%? How is the customer's country known at call time?
- Granularity: per call (floods the ledger; no schema change) or a daily rollup per provider (recommended).
- Cost: accrue `ai_upstream` per call, **or** book the provider's invoice or top-ups. Never both. Reconcile Σcost_micros against the invoice monthly.

**D11. Hourly cloud's missing revenue.** The same problem as B4. Fix it with the same rollup in the same change, and remove the BusinessReport hourly side block at the same time to avoid double counting. Or keep it separate.

**D12. Budgets.**
- Hard-enforce at reserve time, or alert only.
- Denominated in Toman for everyone, or in the viewer's display currency.
- Should `daily_spend_cap_irt` apply to AI?
- Should the window roll over on read or by a job?

**D13. Price schema.**
- Cost-only rows with a sell price computed by formula: one margin setting, and FX/margin moves are automatic.
- Explicit sell rows per model: manual work, but full control.
- Both, as sell overrides with a floor check.

**D14. Privacy and white-labelling.**
- Store full bodies at all? For how long, and in which table?
- Rewrite `model` to our slug and strip provider ids and any `usage.estimated_cost` (unverified)?

**D15. Rate limits and scope.**
- Requests per minute or tokens per minute, per key, project or plan, replacing the shared 12/min IP bucket.
- Are `/v1/models` and an Anthropic-style `/v1/messages` in scope for launch? The `claude_code_compatible` flag exists (`AiModel.php:47`).

**D16. Seeded driver.**
- A data migration setting driver = `'OpenAI-Compatible'`: explicit, but the value must be kept in sync by hand.
- Or make `makeDriver` accept `'DeepInfra'` as an alias: no migration, but it makes the driver enum looser.