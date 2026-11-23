# VPS/GPU homepage and public server configurator — 23 November 2026

Base: `49d4928d808c190bb8ca1581e419cdcd19a58fae` (develop, including concurrent managed-n8n work). Homepage and guest configurator installed and verified in production at `9656515e3f6cd50224b1bc93055a674c5b9b4820` (PRs #393 and #395).

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

- 46 focused PHP tests / 745 assertions pass, including 11 public-store/auth/draft cases, actual explicit authenticated order confirmation with server-owned pricing, three-locale homepage rendering and concurrent managed-n8n coverage.
- 15 real-Blade DOM tests pass: exact six-second transitions, five-slide order/loop, manual controls, Studio examples, focus/visibility/reduced motion and actual production globe pointer pause.
- Four disposable release tests pass: exact preservation/rollback, activation-failure rollback ordering and refusal of concurrent edits.
- The legacy 60-test store/GPU/auth regression selection has 34 passing and 26 existing failures/errors on both immutable base and this change; there are no new failing test names. Those older catalog-pricing fixtures are not represented as a clean baseline or fixed as part of this request.
- Browser source-fixture review: brand glass GPU artwork/copy in Persian/English, light/dark and desktop 1024×600 and 1280×720 with statistics at the viewport bottom and zero horizontal overflow. The native production globe integration is retained and is verified after installation; the preproduction fixture intentionally uses its static illustration.

## Limited release

`scripts/home-vps-gpu-public-store-20261123/blueprint.json` defines the exact destinations. Existing live route/controller/store/home templates differ from develop, so `edits.json` is applied through unique exact anchors with before/after hash guards; no full live controller/view replacement occurs. Shared release lock, private exact-byte backups and atomic per-file renames are used. New dependencies precede source changes. Both owned FPM pools invalidate only changed source, rebuild the route cache only when already cached, compile six views, verify GET/POST auth contracts for all locales, and forget only homepage cache keys.

Activation failure restores previous sources/routes before retiring dependencies. No general cache flush, migration, financial/customer data change, service provisioning or external purchase is part of installation.

## Production acceptance

The 24-file release passed exact-byte verification and both owned PHP-FPM pools reported six compiled views, the expected public GET/private POST contract and no general cache flush. Source release: `9656515e3f6cd50224b1bc93055a674c5b9b4820`; private rollback is in the matching `home-vps-gpu-public-store-20261123-9656515e3f6c` deployment stage.

A first activation audit incorrectly bootstrapped only the console kernel when resolving HTTP middleware aliases. The release automatically restored existing source. The recovery audit initialized the HTTP kernel, confirmed the old authenticated contract in both pools and retired only the twelve hash-matching new dependencies. PR #395 fixed that audit before the successful release. No purchase, migration, financial mutation or provisioning occurred.

Production browser acceptance confirmed the new VPS copy/links, five controls in the requested order and a 6000 ms interval. In the actual anonymous console configurator, resources and OS selection worked, the form posted to `/account/cloud-store/continue`, and final confirmation led to the normal login page without placing an order. No credentials or OTP were entered.

## Public catalog render performance follow-up

The guest acceptance uncovered an existing catalog bottleneck: an actual anonymous application GET returned the correct configurator but took 28.39 seconds and 5,605 SQL queries (5,596 to `cloud_plans`). Sampled application call sites isolated repeated regional OS eligibility `exists` queries in `deliverable()`.

`CloudImageRegionLookup` reuses only identical `(slug, provider, region)` eligibility predicates inside one index render. The original SQL sellable scope and regional predicates are unchanged. No price, order result or state is cached across requests, and normal order/image eligibility calls start fresh. This avoids changing database collation or prefix semantics by replacing SQL with string membership. Both true and false results are reused within the render.

Current isolated verification: 49 focused PHP tests / 768 assertions pass, including 13 public-store cases, 60 same-region OS images requiring one regional query, a fresh query on the next request, stock removal and independent provider/slug/region checks. Four disposable release safety cases pass for the two-file performance release. Performance installation and measured result are recorded after acceptance below.

The existing live controller has production-only delivery-access and catalog-pricing changes; the follow-up applies five unique exact source anchors and preserves those lines. Its deployment is limited to the new lookup and the existing controller through `scripts/cloud-store-catalog-performance-20261123/`.

