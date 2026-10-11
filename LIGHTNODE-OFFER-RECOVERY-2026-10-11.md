# LightNode held-offer recovery — 2026-10-11

The owner authorized investigation and activation of the four held supplier offers only if their current official price and configuration availability could be verified, with LightNode overhead0% and profit5%. Publication to production and GitHub develop was explicitly authorized.

## Root cause and correction

A read-only diagnostic selected the exact four historical DB identities from the previous refresh receipt, rather than treating a public slug or another supplier alias as proof. All four packages still existed in the account OpenAPI with the expected resources, fixed-bandwidth network model and owner-accepted Intel s2 binding. The quote transport returned HTTP429 followed by HTTP400. Laravel `withHeaders()` recursively appends an existing Authorization value during retry, producing multiple anonymous Basic certificates. This is a transport bug, not proof that a package was sold out.

The retry callback now uses `replaceHeaders()` for that one anonymous certificate. It still retries only connection errors,429and5xx, performs only read-only calculator/configuration requests, never reuses a saved amount and never sends an account credential to the public website. Unsupported/mismatched configurations still fail closed. The regression fake rejects stacked certificates, simulates429then503and requires three distinct single-value certificates; it failed before the correction and passed afterward.

## Published offers

The candidate was first checked privately against the real public calculator. Then the single production source file was patched atomically with a before hash guard, syntax validation and private backup at10:00:09UTC. A new production SDK process independently re-read the exact packages, reviewed Linux and Windows rates, and compatible regional images before activation. All four were committed together at10:02:54UTC.

| Location | CPU / RAM | Boot + data disk | Fixed bandwidth | Official USD/hour | Sale IRT/hour | Saved monthly IRT |
|---|---|---|---|---|---|---|
| Moscow | 8 / 16 GB | 50 + 500 GB | 15 Mbps | 0.332724000000 | 95,600 | 71,090,000 |
| Doha | 8 / 16 GB | 50 + 500 GB | 15 Mbps | 0.332724000000 | 95,600 | 71,090,000 |
| Santiago | 2 / 4 GB | 50 + 300 GB | 10 Mbps | 0.148271000000 | 42,600 | 31,700,000 |
| Manila | 4 / 8 GB | 50 + 300 GB | 15 Mbps | 0.255412000000 | 73,400 | 54,580,000 |

The Doha offer is **550GB total (50GB boot +500GB data)**. Any earlier50GB shorthand was incomplete; the exact live identity/specification was checked. Source configuration explicitly lists the region and zone asIN_SELL and admits the exact CPU, RAM, OS, disks and bandwidth; account OpenAPI lists the matching package. Neither source provides a numeric free-capacity counter. Availability can change before order, and the existing purchase-time fresh validation remains in force.

Pricing used one fresh publication FX snapshot: USD273380IRT,EUR305080IRT. No supplier VAT or additional funding overhead was added. Existing outward rounding and744-hour rolling-month reserve remain; the supplier672-hour calendar cap is not assumed for a customer period spanning two calendar months. Prices are a timestamped snapshot, not an indefinitely fixed exchange rate.

## Isolation and validation

The short DB transaction locked/catalog-guarded the target rows and compared every unrelated row before and after. Exactly four held rows became active/in-stock. All396otherLightNode rows and3303other-provider rows remained identical, including prices, availability and metadata. Customer-service hourly rates were unchanged. Global fee21%, LightNode fee0%, global profit5%, customer invoice tax and all three existing sales gates were preserved. No global reprice/import, image write, customer order, invoice/wallet mutation or financial notification was run by this change.

An initial private comparison used strict object identity rather than values for customer-rate readback. Its transaction rolled back all four changes. The harness was corrected to compare canonical scalar arrays; a fresh read/validation preceded the successful transaction. It did not expose customer data or leave a partial activation.

At10:06:41UTC an independent production SDK review again confirmed fresh Linux/Windows parity for all four. All16billing-cycle floor checks and12actual controller renders (four plans×FA/EN/TR) passed: the requested slug, hourly rate, Ubuntu/Windows compatibility, HTML rendering and suppression of private supplier references were verified. All3699unrelated catalog rows and customer hourly rates still matched their before snapshots. The final source batch passed226tests/1726assertions, including the stronger retry regression; the focused public-pricing suite passed8tests/44assertions. Public Manila, Doha, Santiago and Russia pages displayed the exact held public slug, proper boot/data separation, bandwidth and saved sale price. All four checked at390px without document horizontal overflow. No fresh paid guest was created: this recovery changes pricing/catalog availability and anonymous read retry only; earlier real Manila Linux/Windows lifecycle acceptance and guest cleanup remain historical evidence, not a new four-region delivery test.

## Persistence and guarded rollback

Affected machine: productioncoreLaravel app. One runtime file and four DB plan rows persist normally. Routes, NAT/firewall, DNS, VPN, country-routing/port-forward state agents, cron, infrastructure guests and existing services were untouched. Concurrent FX synchronization had completed before this activation; its source and updated prices were preserved.

Private0700folder: `/home/servernetcloud/lightnode-offer-recovery-20261011-01`; before/source/quote/DB receipts are outsidewebroot. Safe local evidence is under `output/lightnode-offer-recovery-20261011/`. No credentials, account headers, customer data or authentication identifiers are in Git or this record.

Code rollback requires matching installed SHA256`c1b6869b76f28cf2a44299218a8b8762298f1ba0378f250e51b00d363948f9bc` before restoring only`LightNodePublicPricing.before.php`. Restoring the old retry also restores its known429failure. Catalog rollback must select only the four exact target IDs in the private ready/before receipts and restore their original price/status fields only after checking they have not subsequently changed. Never restore the entire DB or other-provider rows. Clear country/menu caches after a scoped rollback. Completed helpers are single-use; do not rerun them blindly.
