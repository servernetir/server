# Personalized company email campaigns

New drafts snapshot `info@servernet.cloud` as sender and reply-to without changing the independent CRM sender. The campaign mailer uses the existing information mailbox credentials with the already configured SMTP host/port/TLS scheme. Explicit mailer configuration remains available. SMTP authentication and MAIL FROM acceptance were checked live without sending a customer message.

Bodies support literal `{{full_name}}` / `{{نام و نام خانوادگی}}` and `{{unsubscribe_url}}` / `{{لینک لغو عضویت}}` placeholders. The renderer never evaluates Blade, PHP or arbitrary expressions. Unknown tokens and invalid names are rejected. Each delivery has its own opaque unsubscribe URL and one To address; no CC/BCC recipient list is used. A template-provided unsubscribe footer replaces the legacy automatic footer rather than duplicating it.

Names are encrypted and excluded from model serialization. Existing evidence-matched CSV imports accept `email` with `name`, `full_name`, or `نام و نام خانوادگی`. Empty name cells preserve existing names; conflicting nonempty names for one normalized email reject the import atomically. Import never changes consent, withdrawal or suppression.

A named template cannot be queued while any eligible selected recipient has no valid name. The error reports the missing-name count and leaves the draft and delivery queue unchanged. The worker rechecks rendering immediately before SMTP, skipping any name lost after queueing. No names are inferred from addresses or clinic/company labels.

Validation: isolated PHP 8.4/SQLite suite passed 129 tests / 2,133 assertions, including 17 renderer cases, 10 personalization service cases and 3 HTTP/UI cases. Existing consent, queues, withdrawal and privacy tests remain covered. CI includes both SQLite and MariaDB.

Deployment uses a scoped hash-guarded installer and one additive migration for recipient name and campaign sender fields. It does not create or queue a campaign or send email. Production acceptance and the requested draft are recorded separately. When real names are unavailable, sending a named template waits for data; no generic salutation is substituted without the owner's instruction.
