# support-ops (ticketing, notifications, audit, RBAC, reporting, automation/cron)

> بخشی از معماری CMS اختصاصی سرورنت. برای نمای کلی `00-overview.md` را ببینید.

## خلاصه

This area is the operational spine: a WHMCS-class helpdesk, a single notification outbox, an append-only audit trail, staff RBAC, pre-aggregated reporting, and a scheduler framework that every other area plugs its recurring work into. Money is never a float and never a DECIMAL: every monetary value is a `bigint` of minor units plus a `char(3)` currency code, with the exponent coming from a currency registry (IRT exponent 0 — 1 Toman = 1 minor unit; EUR/USD exponent 2), so `value_minor` in `report_snapshots` and `cost_minor` in `notifications` are directly summable within a currency and never across currencies. Trilingual text is handled with three deliberate mechanisms rather than one: fixed enumerations (ticket status, priority, event keys, permission keys, audit verbs) are machine strings in the DB rendered through `__('ui.*')`, so lang/{fa,en,tr}/ui.php stays the single key-identical source of truth; short admin-created labels (departments, tags, roles, SLA policies) get inline `_fa/_en/_tr` columns; and long admin-authored bodies (canned replies, notification templates) get real sibling translation tables keyed on `locale`, matching the existing PostTranslation pattern. Customer-authored text (ticket subject and message bodies) is stored as written, with `tickets.locale` recording which of fa/en/tr the customer is operating in so every outbound notification, canned reply and SLA email renders in their language. Internal staff notes live in the same `ticket_messages` table behind a `visibility` column with a default global scope that must be explicitly bypassed, because splitting them into a second table destroys the chronological thread and doubles every query — but that choice makes the "internal note leaked to customer" bug a one-line mistake, so the scope is a contract, not a convention. Email-to-ticket ingestion is idempotent by construction: `inbound_emails.message_id` is uniquely indexed, threading resolves VERP plus-token first, then In-Reply-To/References, then a subject tag, and auto-submitted mail never triggers an auto-reply. The automation layer is a `ScheduledTask` interface driven by one artisan dispatcher — every run takes a DB lock, writes a `job_runs` row with a resumable `checkpoint`, processes in chunks with per-item error isolation, and refuses to proceed if a destructive job's blast radius exceeds a configured percentage of the estate, which is the guard that stops a payment-gateway outage from suspending every customer at 02:00. Reporting is nightly rollups into `report_snapshots` rather than live aggregate queries, so MRR, churn, revenue-by-product and expiring-services dashboards stay O(1) on cheap cPanel hardware and survive the SQLite-to-MySQL move.

## جدول‌ها

### `ticket_departments`

Support queues (sales, technical, billing, abuse, .ir domains). Data-driven: admin adds a department without code.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK auto_increment` | — |
| `key` | `varchar(40)` | machine key: sales\|technical\|billing\|abuse\|ir_domains. Stable, used in permissions and event payloads. |
| `name_fa` | `varchar(80)` | inline i18n: short label, no join needed |
| `name_en` | `varchar(80)` | — |
| `name_tr` | `varchar(80)` | — |
| `desc_fa` | `varchar(255) null` | shown under the department picker in the client area |
| `desc_en` | `varchar(255) null` | — |
| `desc_tr` | `varchar(255) null` | — |
| `site_scope` | `varchar(10) default 'both'` | both\|ir\|cloud — lets one codebase ship both installs; .ir-domain department only appears on servernet.ir |
| `is_public` | `boolean default 1` | 0 = staff-only queue (e.g. abuse, internal escalation) |
| `email_address` | `varchar(190) null unique` | support@servernet.ir — the From/Reply-To for this queue |
| `mail_inbox_id` | `bigint unsigned null FK mail_inboxes.id` | where inbound mail for this queue is polled from |
| `sla_policy_id` | `bigint unsigned null FK sla_policies.id` | default SLA for tickets landing here |
| `assign_strategy` | `varchar(20) default 'manual'` | manual\|round_robin\|least_open — resolved by an AssignmentStrategy contract |
| `auto_close_days` | `smallint unsigned default 7` | resolved -> closed after N days of customer silence |
| `require_service` | `boolean default 0` | force the customer to pick a service when opening (technical queue) |
| `sort` | `smallint default 0` | — |
| `is_active` | `boolean default 1` | never delete a department that has tickets; deactivate |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)` · `unique (email_address)` · `index (is_active, sort)`

### `department_user`

Which staff members belong to which queue. Support staff only see tickets in their departments.

| ستون | نوع | توضیح |
|---|---|---|
| `department_id` | `bigint unsigned FK ticket_departments.id cascade` | — |
| `user_id` | `bigint unsigned FK users.id cascade` | staff live in the existing users table |
| `can_assign` | `boolean default 0` | may reassign other people's tickets in this queue |
| `is_default_assignee` | `boolean default 0` | round-robin pool membership |

**ایندکس:** `primary (department_id, user_id)` · `index (user_id)`

### `tickets`

One row per conversation. Carries SLA state, routing, rating and links to the commercial object the ticket is about.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `uuid` | `char(36)` | used in VERP reply addresses and public URLs; never expose the numeric id |
| `number` | `varchar(20)` | human reference, e.g. SN-260722-0042. Generated from a daily counter, printed in subjects. |
| `site` | `varchar(10)` | ir\|cloud — constant per install, but present so the federated staff console can merge two DBs safely |
| `customer_id` | `bigint unsigned null FK customers.id` | null for email from an unknown sender (guest ticket) until matched |
| `requester_email` | `varchar(190) null` | snapshot for guest/email tickets |
| `requester_name` | `varchar(120) null` | — |
| `department_id` | `bigint unsigned FK ticket_departments.id` | restrict on delete |
| `sla_policy_id` | `bigint unsigned null FK sla_policies.id` | snapshotted at creation so later policy edits don't rewrite history |
| `subject` | `varchar(200)` | customer-authored; stored as written, not translated |
| `locale` | `char(2)` | fa\|en\|tr — the language every notification and canned reply for this ticket renders in |
| `channel` | `varchar(12) default 'web'` | web\|email\|api\|phone |
| `status` | `varchar(16) default 'open'` | open\|awaiting_staff\|answered\|on_hold\|resolved\|closed — machine key, label via __('ui.ticket_status_*') |
| `priority` | `varchar(10) default 'normal'` | low\|normal\|high\|urgent — machine key, label via ui.php |
| `assigned_user_id` | `bigint unsigned null FK users.id` | set null on staff deletion |
| `service_id` | `bigint unsigned null FK services.id` | real FK, not a polymorphic blob — 'tickets about this server' is a first-class query |
| `invoice_id` | `bigint unsigned null FK invoices.id` | billing disputes |
| `order_id` | `bigint unsigned null FK orders.id` | pre-sale / failed provisioning |
| `domain_id` | `bigint unsigned null FK domains.id` | IRNIC transfers etc. |
| `first_response_due_at` | `timestamp null` | computed at creation by BusinessClock; indexed so the breach scan is a range query |
| `resolution_due_at` | `timestamp null` | — |
| `first_response_at` | `timestamp null` | first PUBLIC staff message; internal notes never satisfy SLA |
| `first_response_breached` | `boolean default 0` | set by the SLA scan, kept for reporting even after the reply lands |
| `resolution_breached` | `boolean default 0` | — |
| `sla_paused_at` | `timestamp null` | set when status becomes answered/on_hold — waiting on the customer must not burn our clock |
| `sla_paused_seconds` | `int unsigned default 0` | accumulated pause, so due dates are shifted rather than recomputed from scratch |
| `last_customer_reply_at` | `timestamp null` | drives staff inbox sorting |
| `last_staff_reply_at` | `timestamp null` | — |
| `customer_last_read_at` | `timestamp null` | unread badge in the client area |
| `reopen_count` | `smallint unsigned default 0` | high values flag a quality problem in reporting |
| `auto_close_at` | `timestamp null` | populated when resolved; the auto-close job scans this column |
| `rating` | `tinyint unsigned null` | CSAT 1..5 |
| `rating_comment` | `varchar(500) null` | customer-authored, any language |
| `rated_at` | `timestamp null` | — |
| `merged_into_id` | `bigint unsigned null FK tickets.id` | duplicate handling; merged tickets keep their rows for audit |
| `created_by_type` | `varchar(10) default 'customer'` | customer\|staff\|system — staff-opened tickets (proactive outage notice) don't count against response SLA |
| `closed_at` | `timestamp null` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (uuid)` · `unique (number)` · `index (status, department_id, priority)` · `index (assigned_user_id, status)` · `index (customer_id, created_at)` · `index (first_response_due_at)` · `index (resolution_due_at)` · `index (auto_close_at)` · `index (service_id)` · `fulltext (subject) [MySQL only]`

### `ticket_messages`

Every message in the thread — customer replies, staff replies, internal notes and system events — in one chronological table.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `ticket_id` | `bigint unsigned FK tickets.id cascade` | — |
| `author_type` | `varchar(10)` | customer\|staff\|system |
| `author_id` | `bigint unsigned null` | customers.id or users.id depending on author_type; deliberately not a FK because it points at two tables |
| `author_name` | `varchar(120)` | snapshot — staff leave, customers rename; the thread must stay readable |
| `author_email` | `varchar(190) null` | snapshot |
| `visibility` | `varchar(10) default 'public'` | public\|internal. A default global scope hides 'internal' unless the caller holds tickets.view_internal. |
| `body` | `mediumtext` | sanitized HTML subset produced by the existing HtmlSanitizer at write time; never re-sanitize on read |
| `body_plain` | `mediumtext null` | plain-text form for SMS previews, search and outbound text/plain part |
| `source` | `varchar(12) default 'web'` | web\|email\|api\|system |
| `system_event` | `varchar(40) null` | for author_type=system: status_changed\|assigned\|merged\|sla_breached — rendered via __('ui.ticket_sys_*') with params from meta, so it is trilingual with zero stored text |
| `meta` | `json null` | JSON justified: parameters for the system_event label (old/new status, staff name). Never queried by SQL. |
| `email_message_id` | `varchar(255) null` | RFC Message-ID of the mail this message came from or was sent as; the anchor for threading |
| `email_in_reply_to` | `varchar(255) null` | — |
| `inbound_email_id` | `bigint unsigned null FK inbound_emails.id` | traceability back to the raw .eml |
| `time_spent_minutes` | `smallint unsigned null` | staff time tracking; feeds cost-per-ticket reporting |
| `is_first_response` | `boolean default 0` | denormalized so the SLA report doesn't need a window function |
| `edited_at` | `timestamp null` | staff may correct a reply; the original goes to activity_log |
| `edited_by_user_id` | `bigint unsigned null` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `index (ticket_id, created_at)` · `index (ticket_id, visibility)` · `unique (email_message_id)` · `index (author_type, author_id)`

### `attachments`

One polymorphic file table for ticket messages, KYC documents, invoices and anything else. Genuinely polymorphic — files attach to many owners.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `attachable_type` | `varchar(60)` | morph alias, e.g. 'ticket_message', 'customer_document' — aliases, never class names, so refactors don't break rows |
| `attachable_id` | `bigint unsigned` | — |
| `disk` | `varchar(20) default 'private'` | never the public disk; served only through a signed, permission-checked controller |
| `path` | `varchar(255)` | random path, original name never used on disk |
| `original_name` | `varchar(190)` | display name, may be Persian/Turkish — utf8mb4 |
| `mime` | `varchar(100)` | detected server-side, not trusted from the client |
| `size_bytes` | `int unsigned` | — |
| `sha256` | `char(64)` | dedupe + tamper evidence |
| `scan_status` | `varchar(12) default 'pending'` | pending\|clean\|infected\|skipped — customer download blocked unless clean\|skipped |
| `scanned_at` | `timestamp null` | — |
| `uploaded_by_type` | `varchar(10)` | customer\|staff\|system |
| `uploaded_by_id` | `bigint unsigned null` | — |
| `is_customer_visible` | `boolean default 1` | 0 for files attached to internal notes |
| `created_at` | `timestamp null` | — |

**ایندکس:** `index (attachable_type, attachable_id)` · `index (sha256)` · `index (scan_status)`

### `ticket_participants`

CC recipients and watchers, needed because email ingestion sees CC headers and staff need to loop in a colleague.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `ticket_id` | `bigint unsigned FK tickets.id cascade` | — |
| `customer_id` | `bigint unsigned null FK customers.id` | set when the address matches a known contact |
| `user_id` | `bigint unsigned null FK users.id` | staff watcher |
| `email` | `varchar(190)` | — |
| `name` | `varchar(120) null` | — |
| `role` | `varchar(10) default 'cc'` | cc\|watcher — watchers get notifications but are not on the outbound To/Cc |
| `added_by_type` | `varchar(10)` | customer\|staff\|email |
| `created_at` | `timestamp null` | — |

**ایندکس:** `unique (ticket_id, email)` · `index (user_id)`

### `ticket_tags`

Cause/topic tagging so the owner can report on WHY tickets happen, not just how many.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(40)` | machine key: disk_full\|dns\|payment_failed\|provisioning_failed |
| `label_fa` | `varchar(60)` | inline i18n — single short label, a sibling table would be overkill |
| `label_en` | `varchar(60)` | — |
| `label_tr` | `varchar(60)` | — |
| `color` | `char(7) default '#64748b'` | — |
| `is_active` | `boolean default 1` | — |

**ایندکس:** `unique (key)`

### `ticket_tag_links`

Ticket↔tag pivot.

| ستون | نوع | توضیح |
|---|---|---|
| `ticket_id` | `bigint unsigned FK tickets.id cascade` | — |
| `tag_id` | `bigint unsigned FK ticket_tags.id cascade` | — |

**ایندکس:** `primary (ticket_id, tag_id)` · `index (tag_id)`

### `ticket_reads`

Per-staff read state so the queue shows real unread counts without a per-user column on tickets.

| ستون | نوع | توضیح |
|---|---|---|
| `ticket_id` | `bigint unsigned FK tickets.id cascade` | — |
| `user_id` | `bigint unsigned FK users.id cascade` | — |
| `read_at` | `timestamp` | unread = tickets.last_customer_reply_at > read_at |

**ایندکس:** `primary (ticket_id, user_id)` · `index (user_id, read_at)`

### `canned_replies`

Saved staff responses. Container row; the text lives in the translation table because it is long-form and independently editable per language.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(60)` | machine key, referenced by automation (e.g. auto-reply on abuse tickets) |
| `department_id` | `bigint unsigned null FK ticket_departments.id` | null = available everywhere |
| `category` | `varchar(40) null` | grouping in the reply picker |
| `is_active` | `boolean default 1` | — |
| `sort` | `smallint default 0` | — |
| `used_count` | `int unsigned default 0` | surfaces the replies that matter; a high count is a signal to write a KB article instead |
| `created_by_user_id` | `bigint unsigned null` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)` · `index (department_id, is_active, sort)`

### `canned_reply_translations`

fa/en/tr bodies for a canned reply. Sibling-table pattern, mirroring the existing PostTranslation.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `canned_reply_id` | `bigint unsigned FK canned_replies.id cascade` | — |
| `locale` | `char(2)` | fa\|en\|tr |
| `title` | `varchar(140)` | shown in the picker |
| `body` | `mediumtext` | supports the same {{variable}} placeholders as notification templates |

**ایندکس:** `unique (canned_reply_id, locale)`

### `mail_inboxes`

Mailboxes polled for email-to-ticket. Data-driven so a new queue address needs no deploy.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(40)` | — |
| `department_id` | `bigint unsigned null FK ticket_departments.id` | where new tickets from this mailbox land |
| `driver` | `varchar(20) default 'imap'` | imap\|pop3\|webhook — resolved through the MailboxDriver contract |
| `host` | `varchar(190) null` | — |
| `port` | `smallint unsigned null` | — |
| `encryption` | `varchar(8) null` | ssl\|tls\|none |
| `username` | `varchar(190) null` | — |
| `password_encrypted` | `text null` | Laravel encrypted cast; never logged, never rendered |
| `folder` | `varchar(64) default 'INBOX'` | — |
| `webhook_secret` | `varchar(64) null` | for driver=webhook (inbound parse from an ESP) |
| `delete_after_fetch` | `boolean default 0` | prefer 0 + move-to-processed so a parser bug is recoverable |
| `last_uid` | `varchar(64) null` | IMAP UIDVALIDITY-aware cursor |
| `last_polled_at` | `timestamp null` | a stale value triggers an ops alert |
| `poll_every_minutes` | `smallint unsigned default 5` | — |
| `allow_unknown_sender` | `boolean default 1` | 0 = auto-reject mail from addresses with no customer record |
| `default_priority` | `varchar(10) default 'normal'` | — |
| `is_active` | `boolean default 1` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)` · `index (is_active, last_polled_at)`

### `inbound_emails`

Raw inbound mail ledger. The unique Message-ID is the idempotency guarantee that a re-run of the poller cannot duplicate a ticket.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `inbox_id` | `bigint unsigned FK mail_inboxes.id` | — |
| `message_id` | `varchar(255)` | UNIQUE — insert-first, parse-second. Duplicate insert = already handled, skip. |
| `in_reply_to` | `varchar(255) null` | threading anchor #2 |
| `references_tail` | `varchar(255) null` | last entry of References; threading anchor #3 |
| `to_token` | `varchar(64) null` | the VERP plus-token, e.g. support+t-<uuid>@ — threading anchor #1, most reliable |
| `from_email` | `varchar(190)` | — |
| `from_name` | `varchar(120) null` | — |
| `to_email` | `varchar(190)` | — |
| `cc` | `json null` | JSON justified: an unbounded address list only read at parse time, never joined |
| `subject` | `varchar(255)` | — |
| `raw_path` | `varchar(255)` | the full .eml on the private disk; deleted after N days once ticketed |
| `size_bytes` | `int unsigned` | — |
| `auth_result` | `varchar(24) null` | spf/dkim/dmarc summary — a fail raises the spam score |
| `is_auto_submitted` | `boolean default 0` | Auto-Submitted / Precedence: bulk / X-Autoreply. NEVER auto-reply to these — this is the mail-loop killer. |
| `status` | `varchar(12) default 'pending'` | pending\|ticketed\|appended\|ignored\|spam\|failed |
| `ticket_id` | `bigint unsigned null FK tickets.id` | — |
| `ticket_message_id` | `bigint unsigned null FK ticket_messages.id` | — |
| `attempts` | `tinyint unsigned default 0` | parser retries; 3 strikes then status=failed + ops alert |
| `error` | `text null` | — |
| `received_at` | `timestamp` | header Date, clamped to now if absurd |
| `processed_at` | `timestamp null` | — |

**ایندکس:** `unique (message_id)` · `index (status, received_at)` · `index (in_reply_to)` · `index (to_token)` · `index (from_email, received_at)`

### `sla_policies`

Named SLA with its clock definition. One policy per plan tier / department.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(40)` | standard\|priority\|enterprise |
| `name_fa / name_en / name_tr` | `varchar(80)` | inline i18n, short label |
| `clock` | `varchar(16) default '24x7'` | 24x7\|business_hours |
| `timezone` | `varchar(40) default 'Asia/Tehran'` | business hours are meaningless without one; German install uses Europe/Berlin |
| `business_hours` | `json null` | JSON justified: {"sat":["09:00","18:00"],...} is a schedule blob that is never filtered or joined in SQL, only loaded whole by BusinessClock |
| `holiday_calendar` | `varchar(20) null` | 'ir' or 'de' — joins calendar_holidays |
| `is_default` | `boolean default 0` | exactly one default enforced in the app layer |
| `is_active` | `boolean default 1` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)`

### `sla_targets`

Per-priority targets. A real child table, not a JSON map, because targets are edited individually and reported on by priority.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `sla_policy_id` | `bigint unsigned FK sla_policies.id cascade` | — |
| `priority` | `varchar(10)` | low\|normal\|high\|urgent |
| `first_response_minutes` | `int unsigned` | business/clock minutes, resolved by BusinessClock |
| `next_response_minutes` | `int unsigned null` | target for each subsequent staff reply; null = not tracked |
| `resolution_minutes` | `int unsigned null` | null = no resolution SLA (realistic for technical queues) |
| `escalate_after_minutes` | `int unsigned null` | notify the department lead before the breach, not after |

**ایندکس:** `unique (sla_policy_id, priority)`

### `calendar_holidays`

Non-working days per country. Iranian holidays are numerous and move with the lunar calendar; hardcoding them is not viable.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `calendar` | `varchar(20)` | ir\|de |
| `date` | `date` | Gregorian; the admin UI shows Jalali via the existing blog_date helper |
| `label_fa / label_en / label_tr` | `varchar(80)` | inline i18n |
| `is_half_day` | `boolean default 0` | — |

**ایندکس:** `unique (calendar, date)`

### `notification_templates`

One row per (event, channel). The container; text lives in the translation table. Admin edits copy without a deploy.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `event_key` | `varchar(60)` | invoice.created\|invoice.overdue\|service.suspended\|ticket.replied\|domain.expiring\|ops.job_failed — code-owned constants from a registry |
| `channel` | `varchar(10)` | email\|sms\|inapp\|push\|webhook |
| `is_active` | `boolean default 1` | — |
| `is_mandatory` | `boolean default 0` | 1 = customer cannot opt out (invoices, suspension, security). Mirrored from the event class so the UI can grey out the toggle. |
| `from_name` | `varchar(80) null` | override; null = site default |
| `from_email` | `varchar(190) null` | — |
| `reply_to` | `varchar(190) null` | for ticket events this is the VERP address |
| `sms_sender` | `varchar(20) null` | Iranian SMS line number |
| `cc_role_key` | `varchar(40) null` | also send to every staff member holding this role (e.g. billing on failed payment) |
| `throttle_minutes` | `int unsigned null` | collapse repeats within the window — stops a flapping monitor from sending 400 SMS |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (event_key, channel)`

### `notification_template_translations`

fa/en/tr subject and body per template. All three must exist; a missing locale falls back en -> fa and raises an admin warning.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `template_id` | `bigint unsigned FK notification_templates.id cascade` | — |
| `locale` | `char(2)` | fa\|en\|tr |
| `subject` | `varchar(200)` | — |
| `body` | `mediumtext` | restricted {{var}} syntax over a whitelisted variable set — never raw Blade, because admin-editable Blade is remote code execution |
| `body_text` | `mediumtext null` | text/plain alternative; also the SMS body when channel=sms |
| `direction` | `char(3) default 'auto'` | rtl\|ltr\|auto — the email layout must flip for fa |

**ایندکس:** `unique (template_id, locale)`

### `notifications`

Single outbox for email, SMS, in-app and push. Every message ever sent is a row here — this is the delivery log, the in-app inbox and the idempotency guard in one table.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `uuid` | `char(36)` | tracking pixel / unsubscribe token reference |
| `event_key` | `varchar(60)` | — |
| `channel` | `varchar(10)` | email\|sms\|inapp\|push\|webhook |
| `template_id` | `bigint unsigned null FK notification_templates.id` | null for system/ops messages with no admin template |
| `recipient_type` | `varchar(10)` | customer\|staff\|address |
| `recipient_id` | `bigint unsigned null` | customers.id or users.id |
| `locale` | `char(2)` | the locale actually rendered — recorded so a complaint 'I got it in English' is answerable |
| `to_address` | `varchar(190)` | email or E.164 phone |
| `subject` | `varchar(255) null` | rendered |
| `body` | `mediumtext null` | rendered; retained for N days then truncated to keep the table small |
| `status` | `varchar(12) default 'queued'` | queued\|sending\|sent\|failed\|suppressed\|bounced\|complained |
| `attempts` | `tinyint unsigned default 0` | — |
| `provider_key` | `varchar(40) null` | which messaging_providers row delivered it |
| `provider_message_id` | `varchar(190) null` | for bounce/delivery webhook correlation |
| `error` | `varchar(500) null` | — |
| `dedupe_key` | `varchar(120) null` | UNIQUE. e.g. 'invoice.overdue:inv:8123:d7'. THE mechanism that makes an overlapping or re-run cron unable to double-send. |
| `cost_minor` | `bigint null` | SMS costs real money; bigint minor units, never float |
| `cost_currency` | `char(3) null` | IRT\|EUR |
| `related_type` | `varchar(60) null` | morph alias for deep-linking from the in-app inbox |
| `related_id` | `bigint unsigned null` | — |
| `scheduled_at` | `timestamp null` | future sends (renewal reminders) queued ahead of time |
| `sent_at` | `timestamp null` | — |
| `read_at` | `timestamp null` | in-app only |
| `created_at` | `timestamp null` | — |

**ایندکس:** `unique (dedupe_key)` · `unique (uuid)` · `index (status, scheduled_at)` · `index (recipient_type, recipient_id, read_at)` · `index (event_key, created_at)` · `index (provider_message_id)`

### `notification_preferences`

Per-recipient opt-outs for non-mandatory events. Covers both customers and staff via an owner morph.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `owner_type` | `varchar(10)` | customer\|staff — avoids two nullable FKs and a NULL-in-unique-index problem |
| `owner_id` | `bigint unsigned` | — |
| `event_key` | `varchar(60)` | or a group key like 'marketing.*' |
| `channel` | `varchar(10)` | — |
| `enabled` | `boolean` | absence of a row = template default |
| `updated_at` | `timestamp null` | — |

**ایندکس:** `unique (owner_type, owner_id, event_key, channel)`

### `messaging_providers`

Pluggable delivery backends (SMTP, ESP, Kavenegar/SMS.ir for Iran, an international SMS gateway, push). Priority + country scope let one code path serve two legal environments.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(40)` | smtp_de\|kavenegar\|smsir\|apns\|fcm |
| `channel` | `varchar(10)` | email\|sms\|push\|webhook |
| `driver` | `varchar(40)` | the NotificationChannelDriver implementation key |
| `config` | `json` | JSON justified: driver-specific credentials with no shared shape. Encrypted cast, never logged, schema validated by the driver's configSchema(). |
| `country_scope` | `varchar(40) default '*'` | 'ir' \| 'intl' \| '*' — Iranian numbers must go through an Iranian gateway |
| `priority` | `smallint default 100` | lower wins; the next provider is the automatic failover |
| `rate_limit_per_minute` | `smallint unsigned null` | — |
| `balance_minor` | `bigint null` | prepaid SMS credit, minor units |
| `balance_currency` | `char(3) null` | — |
| `balance_checked_at` | `timestamp null` | a low balance is an ops alert — running out of SMS credit silently breaks 2FA |
| `is_active` | `boolean default 1` | — |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)` · `index (channel, country_scope, priority, is_active)`

### `activity_log`

Append-only audit trail for the whole system: who did what to which object, from where. Written by every area, not just support-ops.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `occurred_at` | `timestamp(3)` | millisecond precision so ordering within a request is deterministic |
| `actor_type` | `varchar(10)` | staff\|customer\|system\|cron\|api |
| `actor_id` | `bigint unsigned null` | — |
| `actor_label` | `varchar(120)` | snapshot of the name/email at the time — the row must stay readable after the account is deleted |
| `impersonator_user_id` | `bigint unsigned null` | staff acting as a customer. Non-negotiable: every impersonated action names the real human. |
| `ip` | `varchar(45) null` | v4/v6 text; also the input to the customer IP-restriction feature's audit |
| `user_agent` | `varchar(255) null` | — |
| `request_id` | `char(26) null` | ULID correlating every row produced by one HTTP request or job run |
| `event` | `varchar(60)` | machine verb: service.suspended\|invoice.paid\|ticket.internal_note_added\|staff.permission_changed. Rendered as __('audit.'.$event, $properties) so the log is trilingual with zero stored prose. |
| `subject_type` | `varchar(60) null` | morph alias |
| `subject_id` | `bigint unsigned null` | — |
| `properties` | `json null` | JSON justified: {"old":{...},"new":{...}} of changed attributes, shape differs per model, only ever read whole. Secrets and full card/ID numbers are stripped by a redaction list before write. |
| `severity` | `varchar(10) default 'info'` | info\|notice\|warning\|critical |
| `is_financial` | `boolean default 0` | financial rows are exempt from PII purge and retained for the statutory period |
| `archived` | `boolean default 0` | set by the monthly archiver after export to cold storage |

**ایندکس:** `index (subject_type, subject_id, occurred_at)` · `index (actor_type, actor_id, occurred_at)` · `index (event, occurred_at)` · `index (occurred_at)` · `index (request_id)`

### `roles`

Staff roles. Permission keys are code-owned constants, so the grant set is a JSON array of keys rather than a join table to a lookup that would duplicate config.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(40)` | superadmin\|billing\|support\|support_lead\|provisioning\|readonly |
| `name_fa / name_en / name_tr` | `varchar(80)` | inline i18n |
| `permissions` | `json` | JSON justified: an array of keys drawn from config/permissions.php, which is code, not data. There is no permissions table to be a FK to. A boot-time validator rejects unknown keys and a test asserts every stored key still exists. |
| `is_system` | `boolean default 0` | superadmin cannot be edited or deleted, and always resolves to all permissions |
| `created_at / updated_at` | `timestamp null` | — |

**ایندکس:** `unique (key)`

### `role_user`

Staff↔role assignment (many-to-many; a person can be billing + support).

| ستون | نوع | توضیح |
|---|---|---|
| `role_id` | `bigint unsigned FK roles.id cascade` | — |
| `user_id` | `bigint unsigned FK users.id cascade` | — |
| `granted_by_user_id` | `bigint unsigned null` | — |
| `granted_at` | `timestamp null` | — |

**ایندکس:** `primary (role_id, user_id)` · `index (user_id)`

### `user_permission_overrides`

Per-person exceptions without inventing a bespoke role for one grant.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `user_id` | `bigint unsigned FK users.id cascade` | — |
| `permission` | `varchar(60)` | — |
| `effect` | `varchar(5)` | allow\|deny. DENY ALWAYS WINS over any role grant. |
| `expires_at` | `timestamp null` | temporary elevation that self-revokes — a cron sweeps expired rows |
| `reason` | `varchar(190) null` | — |
| `created_at` | `timestamp null` | — |

**ایندکس:** `unique (user_id, permission)`

### `users (ALTER — existing table)`

Staff accounts. Extended for RBAC, security and trilingual staff signatures. Customers get their own table in the customers area; staff and customers must not share this table.

| ستون | نوع | توضیح |
|---|---|---|
| `is_active` | `boolean default 1` | disable instead of delete — tickets reference the id |
| `locale` | `char(2) default 'fa'` | admin panel language per staff member |
| `timezone` | `varchar(40) default 'Asia/Tehran'` | — |
| `phone` | `varchar(20) null` | E.164; SMS ops alerts |
| `two_factor_secret` | `text null` | encrypted. MUST be mandatory for any role holding a destructive permission. |
| `two_factor_confirmed_at` | `timestamp null` | — |
| `allowed_ips` | `json null` | JSON justified: a short list of CIDR strings evaluated in PHP on login; never queried by SQL |
| `last_login_at` | `timestamp null` | — |
| `last_login_ip` | `varchar(45) null` | — |
| `signature_fa / signature_en / signature_tr` | `varchar(500) null` | appended to replies in the ticket's locale, not the staff member's |
| `max_open_tickets` | `smallint unsigned null` | caps round-robin assignment |

**ایندکس:** `index (is_active)`

### `report_snapshots`

Nightly pre-aggregated business metrics. Every dashboard number the owner sees comes from here, so dashboards are index lookups instead of full scans over invoices and services.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `metric` | `varchar(40)` | mrr\|mrr_new\|mrr_churned\|mrr_expansion\|mrr_contraction\|revenue\|churn_rate\|active_services\|expiring_services\|tickets_opened\|first_response_p50\|first_response_p90\|sla_compliance\|csat\|provisioning_failures\|dunning_recovered |
| `period` | `varchar(6)` | day\|month |
| `period_start` | `date` | — |
| `dim_key` | `varchar(30) default ''` | '' \| product \| product_category \| department \| provider \| country \| gateway. Empty string, not NULL, so the unique index actually works in MySQL. |
| `dim_value` | `varchar(60) default ''` | — |
| `currency` | `char(3) default 'XXX'` | 'XXX' for non-monetary metrics. Money is NEVER summed across currencies in this table. |
| `value_minor` | `bigint null` | monetary value in minor units of `currency` (IRT exponent 0, EUR exponent 2) |
| `value_count` | `int null` | counts (tickets, services, customers) |
| `value_ratio` | `decimal(9,6) null` | rates and percentages — decimal, not float |
| `base_currency` | `char(3) null` | optional converted view for the owner's combined picture |
| `base_value_minor` | `bigint null` | — |
| `fx_rate` | `decimal(18,8) null` | the rate actually used, stamped so the number is reproducible later |
| `computed_at` | `timestamp` | a re-run overwrites in place (idempotent upsert) |

**ایندکس:** `unique (metric, period, period_start, dim_key, dim_value, currency)` · `index (metric, period_start)`

### `job_runs`

Execution ledger for every scheduled task. Answers 'did dunning run last night, how far did it get, and why did it stop', and is the source for heartbeat alerting.

| ستون | نوع | توضیح |
|---|---|---|
| `id` | `bigint unsigned PK` | — |
| `key` | `varchar(60)` | billing.generate_renewal_invoices\|billing.dunning\|billing.suspend_overdue\|domains.sync_expiry\|provisioning.retry\|services.reconcile\|tickets.sla_scan\|tickets.auto_close\|mail.ingest\|notify.flush\|reports.rollup |
| `started_at` | `timestamp(3)` | — |
| `finished_at` | `timestamp(3) null` | still NULL past the lock TTL = crashed mid-run; the watchdog alerts and marks it aborted |
| `status` | `varchar(14) default 'running'` | running\|success\|partial\|failed\|skipped_locked\|skipped_disabled\|aborted_guard |
| `items_total` | `int unsigned default 0` | — |
| `items_ok` | `int unsigned default 0` | — |
| `items_failed` | `int unsigned default 0` | partial = ok>0 and failed>0; one bad row never kills the batch |
| `checkpoint` | `varchar(120) null` | resume cursor (last processed id / timestamp). The next run of the same key reads the last successful run's checkpoint — this is how a job survives being killed mid-way on cPanel. |
| `dry_run` | `boolean default 0` | every destructive job supports --dry-run and records the result here |
| `host` | `varchar(60) null` | — |
| `pid` | `int unsigned null` | — |
| `duration_ms` | `int unsigned null` | trend it; a job that doubles in runtime is a capacity warning |
| `error` | `text null` | — |
| `triggered_by` | `varchar(10) default 'cron'` | cron\|manual\|api — manual runs name the staff member (see prod-migrations rule: the owner clicks, the agent does not) |
| `triggered_by_user_id` | `bigint unsigned null` | — |
| `request_id` | `char(26) null` | joins this run to its activity_log rows |

**ایندکس:** `index (key, started_at)` · `index (status, started_at)` · `index (finished_at)`

### `system_settings`

Runtime kill switches, thresholds and feature flags the owner can flip without a deploy. Deliberately tiny and deliberately not a config file, because config files are not editable on cPanel at 3am.

| ستون | نوع | توضیح |
|---|---|---|
| `key` | `varchar(80) PK` | automation.enabled\|automation.suspensions_enabled\|automation.max_suspend_pct\|support.auto_close_days\|notify.sms_enabled\|ops.alert_emails |
| `value` | `json` | JSON justified: a single heterogeneous scalar/array per key; a typed column per setting would mean a migration per setting |
| `type` | `varchar(20)` | bool\|int\|string\|array — drives the admin form widget and validation |
| `is_secret` | `boolean default 0` | encrypted at rest and masked in the UI |
| `updated_by_user_id` | `bigint unsigned null` | every change also writes an activity_log row |
| `updated_at` | `timestamp null` | — |

**ایندکس:** `primary (key)`

## تصمیم‌های کلیدی

**All money is bigint minor units + char(3) currency, with the decimal exponent taken from a currency registry (IRT = exponent 0, EUR/USD = exponent 2). Applies to report_snapshots.value_minor and notifications.cost_minor.**

Integers are exact, sum correctly, and survive SQLite→MySQL and JSON round-trips unchanged. Hardcoding 'divide by 100' breaks Toman, which has no subunit in practice; carrying the exponent in a registry means IRT and EUR share one code path. A Money value object is the only thing allowed to format or convert.

*رد شد:* DECIMAL(15,2) columns (PHP reads them back as float strings and developers inevitably do arithmetic in PHP floats; also wrong shape for a zero-decimal currency), and any float/double.

**Three explicit i18n mechanisms instead of one: fixed enums → machine keys rendered via __('ui.*'); short admin labels → inline _fa/_en/_tr columns; long admin-authored bodies → sibling *_translations tables with a locale column.**

Statuses and event names are code concepts — putting them in a translations table invites an admin to rename 'open' and break every query. Short labels do not justify a join for a 3-row-per-entity table. Long bodies genuinely need independent per-locale editing, versioning and a 'missing translation' warning, which is exactly what a sibling table gives — and it matches the existing PostTranslation convention.

*رد شد:* (a) One polymorphic `translations` table for everything — an EAV blob that no FK can protect and that makes every list query a triple join. (b) JSON i18n columns like {"fa":"..."} everywhere — unqueryable, unindexable, and impossible to validate for the 'all three files key-identical' rule. Cost accepted: adding a 4th locale means an ALTER on the label tables; with exactly three pinned locales that is the cheaper trade.

**Permissions are code-owned constants in config/permissions.php; roles.permissions is a JSON array of those keys, plus a user_permission_overrides table where deny always beats allow.**

There is no real permissions entity to be a foreign key to — the list is defined by the code that checks it, so a permissions table would be a lookup that must be re-seeded on every deploy and can silently drift from reality. A boot-time validator plus a unit test asserting every stored key exists gives the integrity a FK would have. Overrides stay a real table because they are per-person, auditable and expiring.

*رد شد:* spatie/laravel-permission's 5-table model — too many tables and a cache layer for a company with under ~15 staff, and it still cannot express 'deny wins' or time-limited elevation without extension.

**One `notifications` outbox table serving email, SMS, in-app and push, with a UNIQUE dedupe_key as the idempotency mechanism.**

The owner needs one place to answer 'was the customer told?' — split tables mean three places to look and three retention policies. The unique dedupe_key ('invoice.overdue:inv:8123:d7') makes duplicate sending a database-level impossibility rather than a hope: an overlapping or manually re-run cron gets an integrity violation and moves on. It also makes SMS cost attribution trivial.

*رد شد:* Laravel's built-in `notifications` table (in-app only, opaque JSON payload, no delivery state) plus a separate mail log; and a check-then-insert dedupe, which races under concurrency exactly when it matters.

**Internal notes are ticket_messages rows with visibility='internal', protected by a default global scope that only tickets.view_internal can bypass, and hard-excluded from every outbound template.**

The thread is chronological by nature; a separate notes table means merging two sorted sets in PHP for every ticket view and doubles the write paths. The risk is leakage, so the mitigation is structural: the scope is on by default, outbound rendering takes a whitelist of public message ids, and inbound email parsing strips quoted text before appending.

*رد شد:* A separate ticket_notes table — safer by construction but it breaks chronology, duplicates the attachment relation, and still leaks the moment someone writes a custom query.

**SLA due timestamps are computed once and stored on the ticket (first_response_due_at, resolution_due_at) with pause accounting in sla_paused_at / sla_paused_seconds.**

A breach scan must be an indexed range query (WHERE first_response_due_at < now AND first_response_at IS NULL), which is impossible if the due time is computed on read. Storing paused seconds rather than recomputing means editing business hours next year does not retroactively rewrite last year's compliance numbers.

*رد شد:* Computing due dates on the fly from sla_targets at query time — cannot be indexed, cannot be sorted on, and makes 'tickets about to breach' an O(n) scan on every dashboard load.

**Email threading resolution order: VERP plus-token in the To/Delivered-To address → In-Reply-To/References match against ticket_messages.email_message_id → [SN-xxxxxx] tag in the subject. Unmatched mail opens a new ticket.**

The plus-token is under our control and survives clients that mangle headers; header matching handles forwards; the subject tag is the last resort because subjects get edited, translated and prefixed with Re:/RE:/پاسخ:. Getting this order wrong is how helpdesks staple a customer's new question onto a three-month-old closed ticket.

*رد شد:* Subject-token-only matching (WHMCS's historical approach) — trivially spoofable, and 'Fwd: [SN-000123] ...' from a third party silently injects a stranger into a customer's thread.

**A single append-only activity_log with a morph subject and machine event verbs, not human sentences.**

Storing 'Ali suspended service #42' means the audit trail exists in one language forever. Storing event='service.suspended' + properties JSON lets it render in fa/en/tr through __('audit.*') and stay searchable by event type. Append-only (no UPDATE/DELETE grants for the app DB user on this table) is what makes it evidence rather than notes.

*رد شد:* Per-entity history tables (service_history, invoice_history…) — n tables, n reporting queries, and no way to answer 'everything this staff member did on Tuesday'.

**Reporting reads nightly rollups from report_snapshots; no dashboard runs a live aggregate over invoices or services.**

The owner explicitly wants low operating cost — this runs on cPanel-grade hardware. Rollups make MRR/churn/expiry dashboards index lookups, keep month-end numbers frozen (an MRR figure that changes when you reload it destroys trust), and give a cheap cross-DB story: the German install exports its snapshot rows nightly for a combined view.

*رد شد:* Live SQL aggregation with query caching (fine at 100 customers, unusable at 10,000 and the numbers still drift between reloads); and any external BI tool (cost, plus it cannot reach the Iranian DB).

**Every scheduled job is a class implementing ScheduledTask, executed by one `ops:run {key}` dispatcher that takes a lock, opens a job_runs row, chunks with a resumable checkpoint, isolates per-item failures, and enforces a blast-radius guard on destructive jobs.**

cPanel gives one cron line and kills long processes; jobs WILL die mid-run. Checkpointing makes the next run resume instead of restart, per-item try/catch means one corrupt service does not stop 400 renewals, and job_runs turns 'is the automation alive' into a query. The blast-radius guard (abort if a run would suspend more than automation.max_suspend_pct of active services) is the single most valuable line of code here: it converts a payment-gateway outage from a company-ending mass suspension into an alert.

*رد شد:* The current pattern of closures in routes/console.php (no run history, no checkpoint, no dry-run, no guard); a separate OS cron entry per job (cPanel cron sprawl, no shared locking); and relying on withoutOverlapping alone, whose cache lock evaporates if the cache store is reset.

**Tickets, notifications and audit rows stay in the DB of the site the customer belongs to. Staff work in one console that federates the two installs over a signed internal read/write API; only report_snapshots are copied nightly.**

It preserves the already-decided 'two independent DBs, no replication' rule and keeps each install legally self-contained. Copying tickets would mean bidirectional sync of a table with attachments and SLA clocks — the hardest possible thing to replicate correctly.

*رد شد:* A third central helpdesk DB (creates a cross-border dependency for support, and a customer's ticket then lives apart from their invoices), and DB-level replication (explicitly ruled out, and sanctions/latency make it fragile).

**Attachments live in one polymorphic table on a private disk, served only through a signed, permission-checked controller, and blocked from download until scan_status is clean.**

Files attach to ticket messages, KYC documents, invoices and abuse reports — that is a genuine morph, not laziness. Customers upload national ID images and arbitrary files; serving them from the public disk is a data-breach and a malware-distribution vector in one.

*رد شد:* Per-owner attachment tables (four near-identical schemas), and storing files under public/ with an unguessable name (unguessable is not access control, and search engines eventually find them).

**All transactional email for BOTH sites is sent from the German egress (or a reputable ESP reached from it), with per-site From/Reply-To domains; SMS is routed by country_scope to an Iranian gateway for Iranian numbers.**

Mail from Iranian IP ranges is very widely blocklisted at Gmail/Outlook — invoices and password resets silently vanishing is a business-ending failure mode that looks like nothing at all. Splitting egress by channel (email abroad, SMS domestic) gets deliverability where it matters without breaking Iranian SMS regulation.

*رد شد:* Sending .ir mail from the Iranian server 'because it's closer' — cheaper, and it does not arrive.

## ریسک‌ها

**Two independent databases mean two helpdesks, two audit logs and two sets of numbers. An Iranian customer with a German server can end up with their ticket in the Iran DB and the failing machine's provisioning log in the Germany DB. The owner has almost certainly underestimated the day-to-day cost of this: staff will otherwise keep two browser tabs open and answer the same customer twice.**

→ One staff console that federates both installs over a signed, mutually-authenticated internal API (list/read/reply), with `tickets.site` on every row so the merged queue is unambiguous. Nightly push of report_snapshots from .cloud to .ir gives one combined MRR view. Accept eventual consistency; never attempt bidirectional ticket sync.

**Transactional email from Iranian IP space is broadly blocklisted, and most reputable ESPs (SendGrid, Mailgun, Postmark, SES) will not accept an Iranian entity because of sanctions. Password resets, invoices and suspension warnings will silently not arrive — a failure that produces no error anywhere and looks exactly like customers ignoring you.**

→ Route all outbound email through the German egress with per-site From domains, correct SPF/DKIM/DMARC for both servernet.ir and servernet.cloud, and a dedicated IP warmed slowly. Treat bounce/complaint webhooks as first-class (notifications.status = bounced/complained) and alert when the bounce rate for any event crosses a threshold. Keep SMS as the mandatory fallback channel for suspension and security events to Iranian customers.

**Automated mass suspension. If the Iranian gateway or a currency-conversion job misbehaves overnight, the dunning/suspension cron can suspend a large fraction of active services before anyone wakes up. This single failure mode has destroyed hosting companies.**

→ TaskContext::guard() aborts any destructive run exceeding system_settings['automation.max_suspend_pct'] (start at 2%/run) and raises ops.blast_radius. Separate kill switches for automation.enabled and automation.suspensions_enabled. Mandatory --dry-run output recorded in job_runs for the first month. Suspension requires the invoice to be overdue AND no successful payment attempt in the last 24h AND the gateway to have been healthy during the window.

**Mail loops. A customer's out-of-office replies to our ticket notification, we auto-acknowledge, their autoresponder replies again — thousands of messages and a blocklisting within hours. Every helpdesk hits this.**

→ inbound_emails.is_auto_submitted set from Auto-Submitted / Precedence / X-Autoreply / List-* headers; never auto-reply to those. Set Auto-Submitted: auto-generated on our own outbound. Per-sender rate limit (max N inbound per address per hour, then status=ignored + staff alert), and never auto-reply more than once per ticket per 24h regardless.

**Internal notes leaking to customers. The single most damaging routine bug in helpdesk software — internal note gets quoted into an outbound email, or a staff member replies from their mail client and the quoted trail includes it.**

→ Global scope hiding visibility='internal' by default; outbound rendering takes an explicit list of public message ids rather than the ticket relation; quoted-text stripping on ingest (delimiter line + common client quote patterns); attachments on internal notes get is_customer_visible=0; and a feature test that asserts a rendered customer email never contains internal body text.

**cPanel is a hostile host for background work: one cron line, aggressive process killing, no supervisor, low memory limits, and PHP-CLI limits that differ from PHP-FPM. Queue workers will die and nobody will notice until invoices stop generating.**

→ Single `* * * * * php artisan schedule:run` entry. Queue drained by `queue:work --stop-when-empty --max-time=55` scheduled every minute rather than a long-lived worker. A watchdog task compares each expected job key against job_runs and alerts when a key has not succeeded within its expected interval — heartbeat monitoring, not hope. Every job resumable via checkpoint so a mid-run kill costs seconds, not a whole batch.

**Customer-uploaded files are a live attack surface: ticket attachments plus KYC national-ID images and company documents. A stored XSS in an attachment served inline, or a web shell dropped in a web-reachable directory, is a company-ending event under Iranian and EU data rules alike.**

→ Private disk outside the webroot, random storage paths, server-side MIME sniffing, ClamAV scan gating download (scan_status), Content-Disposition: attachment with a strict Content-Type, per-download authorization plus short-lived signed URLs, EXIF stripping on images, and a hard size/type allowlist. Never render customer HTML attachments in-browser.

**Audit trail versus erasure. servernet.cloud is subject to GDPR erasure requests; Iranian tax/telecom rules require multi-year retention of transaction records. activity_log is append-only, so a naive erasure either breaks the audit chain or is not performed at all.**

→ Split by is_financial: financial rows are retained and only pseudonymized (actor_label and PII in properties replaced with a stable hash, subject FKs kept intact); non-financial rows older than the retention window are archived to cold storage and purged. Redaction happens on write for anything that never belonged in the log (passwords, tokens, full national ID, card data). Document the retention policy per site before launch, not after the first request.

**Trilingual template drift. lang/{fa,en,tr}/ui.php are enforced key-identical by convention, but notification_template_translations and canned_reply_translations are admin-editable data with no such guard. In practice Turkish will be forgotten, and a Turkish customer will receive a Persian suspension notice or a blank email.**

→ A CI/console check (`ops:i18n-audit`) that fails when any active template lacks a translation for fa/en/tr or when a body references a placeholder outside its event's variableSchema(); an admin dashboard badge for incomplete templates; and a defined render-time fallback chain (requested -> en -> fa) that logs a warning rather than sending an empty body. The same audit asserts the three ui.php files remain key-identical.

**Permission keys in a JSON column have no referential integrity: renaming a permission in code silently strips access, or worse, silently grants nothing while the UI still shows the role as configured.**

→ A boot-time validator plus a test that asserts every key stored in roles.permissions and user_permission_overrides exists in the PermissionRegistry; a migration helper for renames; deny-by-default resolution; and 2FA plus a critical activity_log entry required for every permission in PermissionRegistry::destructive().

**SLA promises made in marketing that the business cannot staff. A 24x7 15-minute first-response target with a small team means a permanently red SLA dashboard, which trains everyone to ignore the dashboard.**

→ Ship business-hours policies (Asia/Tehran, Iranian holiday calendar) as the default for non-enterprise tiers and reserve 24x7 for a paid tier; track first_response_p50/p90 and sla_compliance in report_snapshots from day one so the published targets are set from measured reality; escalate_after_minutes notifies the lead before the breach, not after.

**Reporting across two currencies. MRR in IRT and MRR in EUR are not addable, and the Toman/EUR rate is volatile and politically driven. A single 'total MRR' number computed with today's rate will make last quarter's growth look like whatever the rate did.**

→ report_snapshots is currency-scoped by design; the combined view is opt-in and always stamps the fx_rate used and the rate's as-of date, stored alongside the converted value. Default all dashboards to per-currency, and never let a converted figure be the source of truth for a commercial decision.

## قراردادها (PHP interfaces)

```php
<?php

namespace App\Contracts\Notifications;

/**
 * A delivery backend (SMTP, ESP, Kavenegar, SMS.ir, FCM, APNs, webhook).
 * Adding a provider = one class + one messaging_providers row. No other file changes.
 */
interface NotificationChannelDriver
{
    /** Stable key matching messaging_providers.driver */
    public static function driverKey(): string;

    /** 'email' | 'sms' | 'push' | 'webhook' */
    public function channel(): string;

    /** Validation rules for messaging_providers.config, used by the admin form. */
    public function configSchema(): array;

    /** Can this provider reach this recipient (E.164 country prefix, domain, device token)? */
    public function supports(RenderedMessage $message): bool;

    /** MUST be idempotent-safe: the caller has already claimed the notifications row. */
    public function send(RenderedMessage $message): DeliveryResult;

    /** Prepaid credit in minor units + currency, or null if not applicable. */
    public function balance(): ?Money;

    /** Translate a provider bounce/delivery webhook into a status update. */
    public function parseWebhook(array $payload, string $rawBody): ?DeliveryReceipt;
}

final readonly class RenderedMessage
{
    public function __construct(
        public string $notificationUuid,
        public string $channel,
        public string $locale,      // fa|en|tr
        public string $direction,   // rtl|ltr
        public string $toAddress,   // email or E.164
        public ?string $toName,
        public ?string $subject,
        public string $body,        // html for email, text for sms
        public ?string $bodyText,
        public array $headers = [], // Reply-To (VERP), In-Reply-To, References, Auto-Submitted
        public array $attachments = [],
    ) {}
}

final readonly class DeliveryResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?Money $cost = null,
        public ?string $error = null,
        public bool $retryable = true,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Notifications;

/**
 * Every business event that can notify someone. Implementations are code-owned
 * (the key list is the registry the admin template editor is built from);
 * the COPY is data (notification_templates + translations).
 */
interface NotificationEvent
{
    /** e.g. 'invoice.overdue', 'service.suspended', 'ticket.replied', 'ops.job_failed' */
    public static function key(): string;

    /** Customer cannot opt out (invoices, suspension, security, legal). */
    public static function isMandatory(): bool;

    /** Channels attempted when no template row exists yet. */
    public static function defaultChannels(): array;

    /**
     * Whitelisted placeholders shown in the admin editor and the ONLY names the
     * template renderer will resolve: ['invoice.number' => 'string', ...].
     */
    public static function variableSchema(): array;

    /** @return iterable<NotificationRecipient> */
    public function recipients(): iterable;

    /** Values for variableSchema(), already formatted per recipient locale. */
    public function variables(NotificationRecipient $to): array;

    /**
     * Stable natural key written to notifications.dedupe_key (UNIQUE).
     * Return null only for events that may legitimately repeat.
     */
    public function dedupeKey(NotificationRecipient $to, string $channel): ?string;

    public function relatedSubject(): ?object; // model for deep-linking
}

interface NotificationRecipient
{
    /** 'customer' | 'staff' | 'address' */
    public function recipientType(): string;
    public function recipientId(): ?int;
    /** fa|en|tr — decides which template translation is used. */
    public function locale(): string;
    public function timezone(): string;
    public function emailAddress(): ?string;
    /** E.164, so the SMS router can pick an Iranian vs international gateway. */
    public function phoneNumber(): ?string;
    public function acceptsEvent(string $eventKey, string $channel): bool;
}
```

```php
<?php

namespace App\Contracts\Support;

/**
 * Inbound mail source. IMAP, POP3 and ESP inbound-parse webhooks all look the same
 * to the ingestion pipeline. Adding a source = one class + one mail_inboxes row.
 */
interface MailboxDriver
{
    public static function driverKey(): string; // imap|pop3|webhook

    /** @return iterable<InboundMessage> newest-last, bounded by $limit */
    public function fetch(MailInbox $inbox, int $limit = 50): iterable;

    /** Called ONLY after the row is safely committed to inbound_emails. */
    public function acknowledge(MailInbox $inbox, InboundMessage $message): void;

    public function healthCheck(MailInbox $inbox): bool;
}

final readonly class InboundMessage
{
    public function __construct(
        public string $messageId,      // UNIQUE key for idempotency
        public ?string $inReplyTo,
        public array $references,
        public ?string $toToken,       // VERP: support+t-<uuid>@servernet.ir
        public string $fromEmail,
        public ?string $fromName,
        public string $toEmail,
        public array $cc,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public array $attachments,
        public bool $isAutoSubmitted,  // Auto-Submitted / Precedence: bulk / X-Autoreply
        public ?string $authResult,    // spf/dkim/dmarc summary
        public \DateTimeImmutable $receivedAt,
        public string $rawPath,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Support;

use Carbon\CarbonImmutable;

/**
 * SLA time maths. 24x7 and business-hours policies share one call site,
 * and holidays come from calendar_holidays so Iranian holidays are data.
 */
interface BusinessClock
{
    /** Deadline $minutes of *policy* time after $from. */
    public function dueAt(CarbonImmutable $from, int $minutes, SlaPolicy $policy): CarbonImmutable;

    /** Policy minutes actually elapsed between two instants (excludes closed hours/holidays). */
    public function elapsedMinutes(CarbonImmutable $from, CarbonImmutable $to, SlaPolicy $policy): int;

    /** Shift a stored deadline forward by accumulated pause (time spent awaiting the customer). */
    public function shiftForPause(CarbonImmutable $due, int $pausedSeconds, SlaPolicy $policy): CarbonImmutable;

    public function isOpenAt(CarbonImmutable $at, SlaPolicy $policy): bool;
}
```

```php
<?php

namespace App\Contracts\Ops;

use Carbon\CarbonImmutable;

/**
 * Every recurring job in the system implements this. One dispatcher
 * (`php artisan ops:run {key} [--dry-run] [--limit=]`) provides locking,
 * job_runs bookkeeping, checkpoint resume and the blast-radius guard,
 * so no job author can forget them.
 */
interface ScheduledTask
{
    /** e.g. 'billing.suspend_overdue' — matches job_runs.key */
    public function key(): string;

    /** Lock TTL in seconds; the watchdog treats a `running` row older than this as crashed. */
    public function lockSeconds(): int;

    /** Consults system_settings kill switches; false => status 'skipped_disabled'. */
    public function isEnabled(): bool;

    /** True if a partially-completed run is safe to simply re-run from its checkpoint. */
    public function isResumable(): bool;

    public function run(TaskContext $ctx): TaskResult;
}

final class TaskContext
{
    public function __construct(
        public readonly CarbonImmutable $now,   // captured ONCE per run; jobs never call now() themselves
        public readonly bool $dryRun,
        public readonly ?string $checkpoint,    // from the last successful run of this key
        public readonly ?int $limit,
        public readonly string $requestId,      // ULID correlating job_runs <-> activity_log
    ) {}

    /** Persist progress so a kill -9 does not lose the batch. */
    public function checkpoint(string $cursor): void {}

    public function ok(int $n = 1): void {}
    public function failed(int $n = 1, ?\Throwable $e = null): void {}

    /**
     * Blast-radius guard. Throws BlastRadiusExceeded (job_runs.status = 'aborted_guard',
     * ops.blast_radius alert) if $affected exceeds $maxPercent of $population.
     * Called BEFORE any destructive batch — this is what stops a gateway outage
     * from suspending the whole customer base at 02:00.
     */
    public function guard(string $name, int $affected, int $population, float $maxPercent): void {}
}

final readonly class TaskResult
{
    public function __construct(
        public string $status,   // success|partial|failed|skipped_disabled|aborted_guard
        public int $total,
        public int $ok,
        public int $failed,
        public ?string $checkpoint = null,
        public ?string $error = null,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Ops;

use Carbon\CarbonImmutable;

/**
 * A business metric. Adding a KPI = one class; the rollup job, the dashboard
 * and the CSV export all discover it through the registry. No dashboard edits.
 */
interface ReportMetric
{
    /** matches report_snapshots.metric */
    public function key(): string;

    /** 'money' | 'count' | 'ratio' — decides which value_* column is written */
    public function valueType(): string;

    /** Dimensions supported, e.g. ['product', 'product_category', 'department'] */
    public function dimensions(): array;

    /** true if the metric is per-currency and must never be cross-summed */
    public function isCurrencyScoped(): bool;

    /**
     * MUST be deterministic and idempotent: re-running for the same period
     * upserts identical rows.
     *
     * @return iterable<MetricPoint>
     */
    public function compute(CarbonImmutable $periodStart, string $period): iterable;
}

final readonly class MetricPoint
{
    public function __construct(
        public string $dimKey,      // '' when undimensioned
        public string $dimValue,    // ''
        public string $currency,    // 'XXX' for non-money
        public ?int $valueMinor,    // money: minor units, NEVER a float
        public ?int $valueCount,
        public ?string $valueRatio, // decimal as string, cast to DECIMAL(9,6)
    ) {}
}
```

```php
<?php

namespace App\Contracts\Ops;

use Illuminate\Database\Eloquent\Model;

/**
 * The only sanctioned way to write activity_log. Append-only:
 * the implementation has no update() or delete().
 */
interface AuditLogger
{
    /**
     * @param string $event machine verb, rendered as __('audit.'.$event, $properties)
     * @param array  $properties ['old'=>[], 'new'=>[]] — passed through a redaction
     *               list that strips passwords, tokens, full national IDs and card data
     */
    public function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        string $severity = 'info',
        bool $isFinancial = false,
    ): void;

    /** Run a closure with an explicit actor (cron, API client, impersonation). */
    public function as(Actor $actor, callable $fn): mixed;
}

final readonly class Actor
{
    public function __construct(
        public string $type,          // staff|customer|system|cron|api
        public ?int $id,
        public string $label,         // snapshot; survives account deletion
        public ?int $impersonatorUserId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
```

```php
<?php

namespace App\Contracts\Access;

/**
 * Permission keys are CODE, not rows. config/permissions.php returns
 * ['tickets' => ['tickets.view', 'tickets.reply', 'tickets.view_internal', ...],
 *  'billing' => ['billing.invoice.view', 'billing.invoice.void', 'billing.refund', ...], ...]
 * roles.permissions stores keys from this list; a boot-time validator rejects unknown keys.
 */
interface PermissionRegistry
{
    /** @return array<string, string[]> group => permission keys */
    public function groups(): array;

    /** @return string[] flat list */
    public function all(): array;

    public function exists(string $permission): bool;

    /** Permissions that force 2FA and always write a critical audit row. */
    public function destructive(): array;
}

interface StaffAuthorization
{
    /** Resolution order: superadmin => true; deny override => false; allow override => true; role union. */
    public function allows(int $userId, string $permission): bool;

    /** Department scoping for support staff: null = all departments. */
    public function visibleDepartmentIds(int $userId): ?array;
}
```

```php
<?php

namespace App\Contracts\Notifications;

/**
 * Renders admin-editable template bodies. Deliberately NOT Blade:
 * admin-editable Blade is remote code execution. Only {{ dotted.keys }},
 * {% if key %}...{% endif %} and a fixed filter list are supported, and any
 * key outside NotificationEvent::variableSchema() throws in dev / renders empty in prod.
 */
interface TemplateRenderer
{
    public function render(string $template, array $variables, string $locale): string;

    /** Placeholders used by a body, for the admin editor's live validation. */
    public function extractVariables(string $template): array;

    /** @return string[] human-readable problems (unknown key, unclosed block) */
    public function validate(string $template, array $allowedKeys): array;
}
```

