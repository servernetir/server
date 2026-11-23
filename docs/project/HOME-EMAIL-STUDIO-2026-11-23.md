# Homepage, AI Studio and transactional email presentation — 23 November 2026

Base: `fc744ea4db7cb211da6daa8985cd4ec6e10d07ff` (develop). Requested refinement of the existing public site; production acceptance follows below.

- VPS: maintain the original opening and use a professional global infrastructure accent and formal VPS-specific copy. Region card uses an 18%–3.5% dark tint (27%–8% light) with 3px blur; the globe remains visible below it.
- Six services: VPS, GPU, AI Studio, n8n, hosting and transactional email. Keep 6000ms timing and accessible manual controls. Replace the moving wipe, shifts and blur with an opacity fade; retain reduced-motion and pause behavior. Six compact destinations fit the mobile control grid.
- Customer-visible product name is “استودیوی هوش مصنوعی سرورنت” / “ServerNet AI Studio” / “ServerNet Yapay Zekâ Stüdyosu”. OAuth IDs, scopes, API, URLs, external application and downloadable filenames are unchanged.
- Transactional email has a new glass envelope illustration in its homepage slide and the existing canonical `/transactional-email` product page. Explain authenticated identity, scalable sending and deliverability. Use ServerNet as the service brand, visibly identify Amazon SES as the underlying platform, and avoid claiming an unverified certification, formal partnership badge, guaranteed 100% inbox placement or invented performance. Existing price estimator, SMTP/API setup, quote submission and mailbox product are preserved. The `/hosting/email` page presents the two choices immediately before the real mailbox plans.

## Validation and release

The current isolated 114-test PHP selection gives 111 passes and the same three pre-existing AI gateway failures as the immutable baseline selection (89 tests, 86 passing). No new failed names: StudioSsoTest cost settlement and V1ChatTest worked-example charge/idempotent replay. The only SSO/auth test changes are assertions of the localized visible title. No billing behavior is changed. All 19 real-Blade carousel and email estimator/receipt DOM tests pass. Four disposable release and rollback tests pass.

Limited release in `scripts/home-email-studio-20261123/`: 33 destinations, unique anchors for existing production files, exact-byte private backup, shared release lock, both public asset roots, scoped FPM activation and targeted cache keys. No route, migration, finance, sending, CRM data or infrastructure mutation. The quote form is tested only with isolated data.

Delivery reference: AWS SES documentation, [How email sending works](https://docs.aws.amazon.com/ses/latest/dg/send-email-concepts-process.html) and [Delivery problems](https://docs.aws.amazon.com/ses/latest/dg/troubleshoot-delivery.html). A fast first attempt/recipient-server acceptance is not guaranteed inbox placement.

Production installation and visual acceptance pending.
