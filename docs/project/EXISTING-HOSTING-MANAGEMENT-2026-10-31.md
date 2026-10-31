# Existing hosting adoption with unverified billing terms

Source inspected at `14a88e98f85c840fdbef63bdc059549a084a5e74`; runtime sources
unchanged at fresh branch base `c444fd7051281b359d5c95cdef91f02a605f2a4e`.

Existing WHM accounts can be attached to an explicitly authorized customer for
management without pretending they were purchased today or are free. The
dedicated adoption service reads `accountsummary` for the exact username and
primary domain, requires a current administrator and active owner/server, locks
the owner/server rows, refuses conflicting domain or account bindings, and
creates the local service and durable audit event atomically. Repeating the same
verified adoption reuses its service rather than creating another row.

The service is active and already provisioned, with `billing_mode=external`,
`cycle=external` and `provision_meta.existing_hosting_adoption.billing_terms`
set to `unverified`. Price/tax zero are structural storage defaults only; amount
and period are returned as null in the customer API and shown as awaiting records
review. Activation, original provisioning date and due date remain unknown/null.
No password is read, changed or invented. Existing cPanel SSO and usage remain
available to the account owner; no reseller/WHM privileges are granted.

No invoice, payment, wallet entry, account creation, DNS change, password change,
capacity-counter increment or upstream mutation is performed by adoption.
Unrelated services and their invoices are not converted or rewritten.

## Financial and lifecycle boundaries

Model instance and SQL-scope predicates both fail closed for either external
mode, external cycle or the unverified adoption marker. Both recurring jobs,
backfill, calendar and forecast exclude these records. Invoice creation, direct
issuers, renewal/deadline endpoints and billing suspension defer or refuse until
terms are explicitly reconciled. Customer cancellation/termination and admin
reprovision retry cannot delete or recreate an adopted account through this
management-only path. Separate deliberate administrator abuse/security actions
keep their existing authorization.

Do not add external mode/cycle to the ordinary sales form. Transitioning to local
billing is separate financial work requiring actual agreed price, tax, period,
due date and historical records; all three protection markers must be reconciled
together. This change does not guess those terms or implement a conversion UI.

## Validation and release

`ExistingHostingAdoptionTest` uses synthetic accounts, isolated databases and
HTTP fakes with stray-request blocking. Dedicated PHP 8.4 CI runs it on SQLite and
MariaDB, followed by normal invoicing/provisioning regressions. Execution results
are pending; no test or production installation is claimed yet.

Deploy the Service model helper before consumers, preserve fresh production
changes, and validate all guarded entry points before attaching any record.
Three runtime files are known to differ from the inspected repository baseline;
their additive release merge must preserve those changes and record its hashes.

Rollback must refuse to remove these guards while any management-only/unverified
adoption records remain. Do not turn those records into free/one-off services or
run financial/provisioning queues to test production. Any scoped local detachment
requires a separately reviewed plan and leaves the actual WHM account intact.
