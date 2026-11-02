# اتصال محدود OTP ارومیه‌سیتی

Urmia City uses SMS proof followed by the existing paid Shahkar API. Its private OTP bridge uses the existing `OtpService` with an additive `smsOnly` argument and a separate `urmia_city_login` purpose. Existing four-argument callers retain their parallel delivery behavior. IP quotas for the new purpose are isolated from ServerNet login/register.

Two server-to-server POST routes are added under `/api/internal/urmia-auth/v1/otp`: `start` and `verify`. Authentication is HMAC-SHA256 over version, method, path, timestamp, nonce, and the SHA256 of the raw body. Clock tolerance is 90 seconds; nonce lifetime is 300 seconds. The independent signing key is read from `storage/app/private/urmia-city-otp.key` and must never be committed or returned by the API. Missing configuration fails closed.

`start` binds request UUID, mobile, client IP, and an opaque city-session binding. A dedicated `urmia_auth_requests` database ledger atomically claims requests before sending, independent of cache eviction. Lost responses reuse the same request/challenge. Unknown sends stay unknown until the actual OTP expiry, or a conservative deadline when no OTP row can be identified. A later explicit request may then start again. `verify` requires the same binding and the exact latest OTP id; a successful response may be recovered for 120 seconds using the same code proof. No raw code is stored in this ledger or returned by the bridge.

The bridge cannot send arbitrary SMS text, grant a ServerNet customer session, or run a Shahkar inquiry. Shahkar remains on the existing Identity API with its price quote, idempotency, wallet, eligibility and reconciliation controls. No customer wallet, service price, identity provider setting, or funding policy is changed by this code.

The new migration creates only the dedicated ledger. Deploy that migration before exposing the new routes. Keep the key outside the public directory, limit filesystem access, and configure a dedicated identity-scoped key in the city application's private runtime settings. Do not clear shared cache or replace the full route file on production; preserve concurrent changes and apply the single require addition with a hash guard.

Validation on 2026-11-02: the isolated component harness passed 20 checks on SQLite and 20 on MariaDB using the installed approved framework dependencies, a fake SMS sender and disposable databases. It tests HMAC rejection, nonce replay, cache-clear idempotency, binding, exact code proof, unknown/expired sends, existing-call compatibility and separate IP quotas. No production application bootstrap, live SMS, paid inquiry, or customer state is used by the harness. Independent source review found no remaining material blocker after these fixes. Production installation and real delivery require a separate receipt.

Harness: `tests/urmia-auth-isolated.php`; pass the approved vendor autoloader as its sole argument. It defaults to in-memory SQLite; optional `URMIA_TEST_DB_*` variables select a disposable MySQL/MariaDB schema. Never point it at production.

Rollback: disable/remove the dedicated signing key and remove the single route require before restoring the previous OTP service. Leave the additive ledger in place until retention and reconciliation are decided; a code rollback does not require dropping it.
