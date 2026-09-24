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
