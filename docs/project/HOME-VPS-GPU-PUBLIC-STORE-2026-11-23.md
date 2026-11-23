# VPS/GPU homepage and public server configurator — 23 November 2026

Base: `49d4928d808c190bb8ca1581e419cdcd19a58fae` (develop, including concurrent managed-n8n work). Source candidate; production acceptance is pending.

The owner requested a VPS-specific first hero, a dedicated GPU second hero, Studio/n8n/hosting afterwards, six-second rotation, and browsing the real console server configurator before login.

## Behavior

- The original opening headline remains; the accent becomes “قدرتِ سرور، به فرمانِ تو.” Copy and both CTAs are specific to VPS. The primary opens the localized console `/account/cloud-store`; the second preselects hourly billing.
- Five-slide order: VPS globe → cloud GPU → Studio → n8n → hosting, every 6 seconds. Existing reduced-motion, pause/focus/manual controls remain, with the actual production globe pointer target included in pause handling.
- The GPU slide uses a brand-blue glass compute core, content/model cards and restrained animation. It links to the localized `/gpu` landing page. Existing first-fold statistics and glass/globe work are retained.
- Only GET server browsing and POST configurator continuation remove `auth:customer`. Existing account-active/IP controls remain. The real order POST, private account/service endpoints and separate managed-n8n checkout retain authentication.
- Guest rendering uses a public layout and the same real catalog/template. No account sidebar, saved SSH keys, wallet balance or customer hourly commitments are read for a guest. Existing catalog availability, Iran verification/purchase rules and financial checkout are preserved.
- Continuation validates/retains only public configuration fields in the same-host session for 30 minutes. Identity, SSH keys, posted prices and redirect destinations are discarded. Login/registration use the existing intended destination. The authenticated resume restores choices and requires explicit normal order confirmation; it never orders automatically. Server names stay out of the resume URL.
- CSRF and throttles remain. Configurator responses are private/no-store.

## Validation

Focused rendering/auth/order tests, real-Blade carousel DOM tests, a baseline comparison of the older cloud-store financial suite, and scoped-release guard tests are run for this change. Final counts and actual limitations are recorded with acceptance.

## Limited release

`scripts/home-vps-gpu-public-store-20261123/blueprint.json` defines the exact destinations. Existing live route/controller/store/home templates differ from develop, so `edits.json` is applied through unique exact anchors with before/after hash guards; no full live controller/view replacement occurs. Shared release lock, private exact-byte backups and atomic per-file renames are used. New dependencies precede source changes. Both owned FPM pools invalidate only changed source, rebuild the route cache only when already cached, compile six views, verify GET/POST auth contracts for all locales, and forget only homepage cache keys.

Activation failure restores previous sources/routes before retiring dependencies. No general cache flush, migration, financial/customer data change, service provisioning or external purchase is part of installation.

Production install and browser acceptance: pending.
