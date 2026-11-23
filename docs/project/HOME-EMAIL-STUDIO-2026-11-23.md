# Homepage, AI Studio and transactional email presentation — 23 November 2026

Base: `fc744ea4db7cb211da6daa8985cd4ec6e10d07ff` (develop). Requested refinement of the existing public site; production acceptance follows below.

- VPS: maintain the original opening and use a professional global infrastructure accent and formal VPS-specific copy. Region card uses an 18%–3.5% dark tint (27%–8% light) with 3px blur; the globe remains visible below it.
- Six services: VPS, GPU, AI Studio, n8n, hosting and transactional email. Keep 6000ms timing and accessible manual controls. Replace the moving wipe, shifts and blur with an opacity fade; retain reduced-motion and pause behavior. Six compact destinations fit the mobile control grid.
- Customer-visible product name is “استودیوی هوش مصنوعی سرورنت” / “ServerNet AI Studio” / “ServerNet Yapay Zekâ Stüdyosu”. OAuth IDs, scopes, API, URLs, external application and downloadable filenames are unchanged.
- Transactional email has a new glass envelope illustration in its homepage slide and the existing canonical `/transactional-email` product page. Explain authenticated identity, scalable sending and deliverability. Use ServerNet as the service brand, visibly identify Amazon SES as the underlying platform, and avoid claiming an unverified certification, formal partnership badge, guaranteed 100% inbox placement or invented performance. Existing price estimator, SMTP/API setup, quote submission and mailbox product are preserved. The `/hosting/email` page presents the two choices immediately before the real mailbox plans.

## Validation and release

The current isolated 114-test PHP selection gives 111 passes and the same three pre-existing AI gateway failures as the immutable baseline selection (89 tests, 86 passing). No new failed names: StudioSsoTest cost settlement and V1ChatTest worked-example charge/idempotent replay. The only SSO/auth test changes are assertions of the localized visible title. No billing behavior is changed. All 19 real-Blade carousel and email estimator/receipt DOM tests pass. Four disposable release and rollback tests pass.

Limited release in `scripts/home-email-studio-20261123/`: 32 destinations, unique anchors for existing production files, exact-byte private backup, shared release lock, both public asset roots, scoped FPM activation and targeted cache keys. Production's account AI view has no Studio launch label; the localized label change is retained in Git but no unrelated live account view is overwritten. No route, migration, finance, sending, CRM data or infrastructure mutation. The quote form is tested only with isolated data.

Delivery reference: AWS SES documentation, [How email sending works](https://docs.aws.amazon.com/ses/latest/dg/send-email-concepts-process.html) and [Delivery problems](https://docs.aws.amazon.com/ses/latest/dg/troubleshoot-delivery.html). A fast first attempt/recipient-server acceptance is not guaranteed inbox placement.

Pre-release browser QA using real Blade and a static globe confirmed the email glass scene, pure opacity transition (no slide transform), Persian 1024×600 and English light 1280×720 with the stats rail at the viewport bottom, and Turkish light 390×844 with no horizontal overflow and all six controls in two rows. Narrow desktop thresholds tolerate fractional zoom dimensions; mobile service copy starts at the top of the stage.

## Production acceptance

PR #402 merged into develop at `7e67c999edbe76d68369dd5e63cbd654f878c000`. All 13 triggered GitHub workflows passed, including Studio SSO and Studio homepage presentation. The clean focused selection passed 36 PHP tests / 856 assertions, all 19 DOM tests and the four rollback guards. The larger local selection and its three unchanged baseline failures are recorded above.

The prepared, immutable PR head `aa9c083f37dd6900c942571f2565d6d6d506f186` is an ancestor of develop; every changed path was verified byte-identical to the merged branch before deployment. Installed and verified all 32 manifest destinations. Both application FPM pools compiled nine views and reported the after phase with no route cache rebuild or general cache flush.

Live acceptance on the main site:

- All 12 localized homepage, transactional email, mailbox hosting and Studio download URLs returned HTTP 200. Every homepage has exactly six matching slides and selectors, 6000ms interval, and the new localized brand. Each canonical transactional email page has the new artwork, deliverability section, existing quote form and estimator. Each mailbox page has the two-product choice before pricing and retains the actual mailbox plans.
- Actual homepage browser at 1633×828 CSS pixels: initial infrastructure slide and stats rail fit (bottom 827.78px, scroll 0); region background is 18%–3.5% tint with 3px blur and the native globe behind it. Studio and email manually selected without moving the viewport. Computed transition properties are opacity and visibility only.
- Actual mailbox-to-transactional CTA followed successfully. Changing the live estimate from 50,000 to 100,000 monthly recipients updated the displayed estimate from 4,092,000 to 6,838,000 toman at the rate visible during acceptance. No quote submission, outgoing message, authentication or payment was performed.
- Both noindex design-review folders were removed through their exact-hash cleanup helpers.

Private deployment stage and scoped rollback:

```sh
python3 /home/servernetcloud/deploy-backups/home-email-studio-20261123-aa9c083f37dd/release.py rollback aa9c083f37dd6900c942571f2565d6d6d506f186
```

Production screenshots:

- [Global infrastructure and transparent region card](home-email-studio-2026-11-23/vps-home.jpg)
- [AI Studio](home-email-studio-2026-11-23/ai-studio-home.jpg)
- [Transactional email homepage hero](home-email-studio-2026-11-23/email-home.jpg)
- [Transactional email product page](home-email-studio-2026-11-23/email-product.jpg)

[Machine-readable acceptance](home-email-studio-2026-11-23/acceptance.json).
