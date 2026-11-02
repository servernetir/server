# Arvan traffic meter source restoration and lock review

Status: review candidate; not deployed. This change restores three previously
live-only runtime sources to Git and adds one narrowly scoped financial guard.
It does not initialize or run a production meter, change a customer exclusion,
create a baseline, debit credit, enroll exporters or modify a schedule.

The recovered files were read from the authorized private application directory
and their complete byte hashes were verified before copying. Independent source
inspection found no embedded credentials or customer records: authentication is
resolved from the existing encrypted setting and reports/state remain private.

| Restored source | Original SHA-256 | Bytes |
| --- | --- | --- |
| `website/app/Services/Finance/ArvanTrafficMeter.php` | `464b2db7f0bda854d6473b06e46da630cdc325c82bb22894088af8bcfaba4f3f` | 18287 |
| `website/app/Services/Finance/ArvanTrafficSource.php` | `f242b2e5b0c2256749415fac4f277506a2ae0d4204f5bc83da1df51554916db9` | 9821 |
| `website/app/Console/Commands/MeterArvanTraffic.php` | `a8bd652b5155a7125240f485c87a9ee1eb18f9fb2a22c54e8dcc9c710869c5f6` | 4134 |

The source and command stay byte-identical. The meter alone adds a second
`hasUnreviewedCharge()` check after acquiring the ordered customer locks and
before writing any invoice or checkpoint. The locked recheck uses current
reads for payments, invoices and credit entries; another plain SELECT would
retain the old snapshot under InnoDB REPEATABLE READ. Previously a manual traffic debit
could become visible between the initial check and customer-lock acquisition;
the subsequent meter would not reconsider it. The new check refuses the whole
transaction for reconciliation when this happens. Existing global state,
customer/service/instance locks, invoice math, exclusions, paid baselines,
checkpoint commit, markup, tax and debt policy remain unchanged.

The existing `finance:meter-arvan-traffic --apply` scheduler entry remains the
only one: every two hours in `Asia/Tehran`, with overlap prevention and background
execution. Restoring its command registration is tested; no new cron or route
is added. A normal invocation still defaults to preview. Supplier requests in
tests use synthetic responses and all stray HTTP is prohibited.

The new suite covers preview preservation, incremental settlement and retry,
owner exclusion, legacy/manual charges, deterministic lock visibility
interleaving, changed ownership, decreasing counters, invalid supplier totals,
complete fake supplier reads, default command preview and the actual registered
schedule. The deterministic interleaving is not advertised as a separate-process
concurrency proof. A separate MariaDB test uses two real connections: the first
establishes its old repeatable-read snapshot; immediately before its customer
lock, the second locks that customer, inserts a manual debit and commits. The
meter must abort while preserving that independently committed debit. Its
explicit opt-in/environment/loopback/database checks run before the migration
trait can touch a database; the default SQLite suite skips this MariaDB-only
case without migrations. CI runs
the main suite on both SQLite and disposable MariaDB. One negative control
removes only the new guard; a second MariaDB negative control retains the guard
but changes its recheck to a plain read. Both must fail for their intended
assertion, demonstrating both the original window and the isolation requirement. The existing reviewed-settlement tests run
in both matrices as regression coverage.

Local PHP 8.3 lint was performed; local Laravel execution is unavailable because
the locked dependencies require PHP 8.4. CI uses PHP 8.4, no production secrets,
and blocks external application network traffic while permitting only its
disposable database. Actual CI counts/results must be read from the draft PR;
they are not inferred from lint or old reports.

Open operational limits remain explicit: financial receipts do not establish
supplier usage freshness, exporter coverage or network enforcement. Existing
historical/manual disputes require their own reconciliation and prospective
baseline; this change does not remove any exclusion or silently waive a cost.
State-retention/capacity, command-error health reporting, monitoring enrollment
and confirmed suspension controls are separate work.

Any future production installation requires a fresh comparison with the live
three sources and a guarded release of the exact reviewed change. Reverting Git
does not reverse settled invoices or credit entries; none are created here.
