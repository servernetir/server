# Cloud sales FX snapshot recovery — 28 October 2026

## Confirmed access and initial state

The owner explicitly requested another working connection method (token/remote). Existing cPanel token authentication succeeded over HTTPS; no credential, remote-access agent, public execution route or new persistent access was created. The current `CloudChoiceAvailability.php` hash remained `f1b0fa53c3b75dd67cd10557adbfa65449de19ed47c16fc1d1e9ea9183208f53`, matching the already deployed availability UI release. Existing temporary availability jobs were absent.

Fresh public GETs returned HTTP 200 but displayed no purchasable Istanbul plan or hourly country rows. A private, read-only application diagnostic at **20:21:02 UTC** confirmed all seven enabled suppliers had zero sellable rows. Existing active/stock records and sale switches remained present; Proxmox and OVH remained disabled.

The cause of the global sales gate was a numeric FX signature mismatch:

| Currency | Published cloud snapshot | Current valid Navasan rate, toman |
|---|---:|---:|
| USD | 276360 | 276140 |
| EUR | 306360 | 306400 |
| USDT | 269900 | 271400 |

The previous cloud snapshot was dated 12:07:56 UTC. Current TRY was 5570 toman; VPS.tc also needed its own fresh proof. The underlying reason that the earlier matching price publication had not completed was **not isolated** in this turn; this receipt proves state recovery, not a permanent repair of every supplier/network failure scenario.

## Recovery performed

Current runtime sources were read: `CloudCatalogSync::reprice()` acquires the shared catalog lock and invokes the existing validated repricer. One price-only refresh ran from **20:24:02 to 20:31:57 UTC** using the already valid stored rates. It did not fetch another FX batch, run a full inventory import, run the entire scheduler, or refresh domain prices.

The normal repricer re-read native non-EUR costs, retained settings/rate/product concurrency checks, calculated prices with existing configured fees/margins and published product prices plus the new snapshot transactionally. No stale-price snapshot was manually blessed, no quarantine was removed by hand, and no sale switch was forced. The existing Arvan repricer can close exactly verified unavailable flavors; no claim is made that every inventory field was unchanged.

Result: **1,904 existing price rows updated**, global snapshot current, no provider exclusions, **10 plan IDs still quarantined**. Current sellable counts:

| Infrastructure | Sellable plans |
|---|---:|
| Hetzner Cloud | 62 |
| Aeza | 102 |
| ArvanCloud | 278 |
| LightNode | 331 |
| VPS.tc | 1 |
| SaladCloud | 54 |
| Hetzner Robot | 182 |
| **Total enabled suppliers** | **1010** |
| Proxmox | 0, remains disabled |
| OVH | 0, remains disabled |

Only existing catalog/quote reads and normal price publication were invoked; no supplier purchase, VM creation/deletion, renewal, credit payment or customer billing mutation was requested.

## Independent acceptance and limits

An independent reviewer checked current source refresh/lock contracts and separately verified normal and cache-bypassed (`?qa=1`) public responses after completion. All four GETs returned 200 with the same business result: Istanbul has one CV-1-1 monthly purchase link at **1,450,000 toman/month**, a native disabled hourly button and the localized reason. The hourly page shows **35 active countries** and a gray Turkey row without a purchase link, retaining its reason. No raw `ui.choice_` keys appeared. Browser inspection independently confirmed the current Istanbul card.

The existing catalog lock prevented overlapping refreshes. Private one-shot scripts had a start-once marker and a bounded start window; their temporary cron entries were removed after start, and no persistent schedule was added. Private receipts contain the operational proof without publishing credentials/vendor bodies/customer data. No application source was changed by this recovery, so no development test suite was rerun; the evidence here is fresh production state and read-only UI acceptance, not a copied historical test count.

Operational knowledge: a current FX cache alone does not prove current cloud prices; compare the published numeric signature and supplier proof. HTTP 200 alone does not prove sales availability. Use the existing locked price refresh when rates are valid; do not clear pricing gates, manually publish a rate-only snapshot, restart unrelated jobs, or remove financial protection to reopen sales. Investigate a repeated failed publication through controlled error/worker evidence before claiming the scheduling path permanently fixed.
