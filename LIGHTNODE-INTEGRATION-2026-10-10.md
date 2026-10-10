# LightNode integration — acceptance and release record

Date: 2026-10-10. Branch: `codex/lightnode-integration`, synchronized with `origin/develop` at `89a59d0c` (earlier validation used `394da6eb` and `1615b347`). Status: review implementation; production deployment and public sales remain disabled.

## Verified provider contract

The [official OpenAPI index](https://apidoc.lightnode.com/en/llms.txt) lists 18 English API documents, all read for this integration. Requests use the fixed origin `https://openapi.lightnode.com` and `x-open-token`. Redirects and automatic mutation retries are disabled. Console endpoints, another provider's authentication, and undocumented price fields are not used.

| Operation | Official endpoint / essential fields |
| --- | --- |
| Regions, packages, images | `/region/list`, `/package/list` with region/zone, `/image/list` with region and `imageType=System` |
| Inventory, detail | `/instance/list` with region **and zone**, paginated; `/instance/detail` with resource reference |
| Create | POST `/instance/create`, nested `packageConfig`: package, region, zone, name, regional image UUID, generated password; optional validated regional firewall UUID |
| Power | POST `/instance/start`, `/instance/stop`, `/instance/restart` |
| Reset password | POST `/instance/modifyInstancePassword`; fully stopped instance required |
| Reinstall | POST `/instance/reinstallSystem`, resource, regional image, region and password; fully stopped instance required |
| Release | POST `/instance/release`; task success and complete inventory absence required |
| Task completion | `/asynctask/getResult`; `processResult=SUCCESS` is required, HTTP 202 alone is insufficient |

The account's API key was verified active. Its value is held in a private encrypted local credential store and in the private acceptance database, never in this repository or this record. Production credentials have not been installed.

New purchase journals also retain expected resources. Delivery checks CPU, RAM, boot/data disk sizes, region/zone, initial image UUID and network contract against the actual receipt. Traffic packages additionally require `limitFlow=TRUE` before delivery; an uncontrolled traffic package is held for administrative review. Customer password reveal stays blocked until delivery is confirmed.

An encrypted durable operation journal is written **before** every purchase or management mutation. Lost responses cannot trigger a duplicate purchase. Conflicting operations remain blocked until reconciled. A same-named machine without a purchase journal requires an ownership review; it is not silently adopted. Completed password/reinstall tasks retain their journal until the instance password, image and service username are committed together in a database transaction.

Initial purchase reconciliation and release checks use the complete recorded region/zone inventory, including all pages. General administrative inventory still traverses all regions/zones and fails on partial results. The recorded region is retained even when a region disappears from the current sales list.

No documented public API was found for VNC/console URLs, metrics, resize, snapshots, uploading SSH keys, additional IPs or firewall rule creation. These capabilities are disabled. Existing regional firewall groups can be selected only after their exact UUID, region and AVAILABLE state are verified. No group or firewall rule was changed in these tests.

## Regions and catalog

The live API returned 39 regions on 2026-10-10. Explicit country/city mappings are in `website/config/lightnode.php`, and can be supplemented by the reviewed account manifest. Provider IDs are opaque: `bg-dhaka-1` is Bangladesh, whereas `bg-sofia-1` is Bulgaria. Taiwan and Hong Kong have their own customer location entries. Multiple zones remain distinct provider plan references.

The console advertised 46 locations, with seven marked Sold Out: Muscat, Johannesburg, Lima, Karachi II, Yangon, Jeddah and Almaty. No live API mapping or purchasable plan is invented for those locations. Baghdad also has a current provider internet shutdown notice spanning September 26–October 12; stock acceptance must consider that notice.

Image UUIDs are region-specific. Windows requires at least **2 vCPU / 4 GB RAM**, as confirmed by the official create form; this is enforced in the catalog, customer image selection, purchase and reinstall paths. System disk requirements apply to the boot disk, not the sum of boot and data disks. Additional data storage is displayed separately; formatting/mounting an additional volume was not tested.

The complete live package read returned 880 rows / 858 unique packages across 39 zones. Quito returned the same 22 packages twice; identical duplicates are removed and conflicting specifications for one package identity reject the catalog and purchase.

The live package API omits bandwidth for traffic packages and traffic quota for bandwidth packages. Those omissions are preserved rather than converted into misleading zero or unlimited promises. Network/storage contracts participate in the plan slug, and offers with different contracts cannot dominate or replace one another.

## Pricing and billing

The [official billing guide](https://doc.lightnode.com/Financial/resourcebilling.html) rounds usage up to whole hours, continues charging stopped machines, and stops instance charges upon release. It describes a 672-hour monthly cap for unchanged configuration and prorates monthly traffic for hourly usage. Excess traffic can incur a per-GB charge; capped traffic is throttled. PayByBandwidth and PayByTraffic are different contracts. The [instance composition guide](https://doc.lightnode.com/Instance/composition.html) gives all instance components the same lifecycle; boot and data disk capacities are separate, but releasing the instance releases its components.

**The public package API has no prices or stock counts.** A reviewed account-specific manifest must provide both hourly and monthly USD prices, exact package specification, traffic-policy verification and a timestamp no older than seven days. No monthly/720 hourly estimate is used. Converted costs use the valid USD and EUR rates, the existing provider-specific funding fee and the site's existing profit margin/rounding rules. Global FX and profit settings are preserved.

The test console quoted Manila CPU Shared, 1 vCPU / 2 GB, 50 GB boot, no data disk, 1000 GB traffic at $0.012/hour and approximately $7.71/month. The valid Windows test package was 2 vCPU / 4 GB, 50 GB boot plus 100 GB data and 2000 GB traffic, quoted at $0.043/hour and approximately $28.68/month. These are **observed rounded console quotes**, not a verified invoice or a complete price feed. Account billing precision, overage rates, traffic enforcement and the complete region/package price matrix remain release requirements. A read-only detail request for the account's pre-existing instance returned `limitFlow=FALSE`; it was not modified. The documented API exposes this field for reading but no setter was found. The new traffic-cap delivery guard passes automated tests but cannot yet be accepted live against a provider-capped API-created instance. Provider clarification/configuration is required before traffic plans can launch. The console temporarily stopped calculating during review, then recovered. Forty exact-spec quote candidates were collected across all 39 regions and kept in a private local price workbook; no candidate was approved for public sale. Quito duplicate rows were reconciled, Kuwait was priced as a distinct 2 Mbps bandwidth package, and larger exact API packages were selected where the console's selected 1 vCPU / 4 GB configuration had no API equivalent. The complete 858-package price matrix is still unavailable.

The [Terms of Service](https://www.lightnode.com/termsService), section 3.4, addresses resale and end users; the account holder remains responsible for their end users' compliance. Free-bandwidth promotions have separate resale restrictions in section 4.6. Such promotions are not treated as a free commercial traffic allowance.

## Provider clarification request

On 2026-10-10, the user explicitly approved sending the prepared support request. It was submitted once through the account's LightNode ticket form and the resulting ticket detail confirmed **Processing**, with the complete message and public draft PR link retained. No substantive provider reply was present. The request covers official prices/stock by region/package, CPU-series mapping, a supported way to enable `limitFlow=TRUE`, billing precision and recovery of lost tasks. The form limits messages to 250 characters, so the approved concise request links to PR #27 for the full release blockers. No key, password, attachment or private resource identifier was transmitted.

The private ticket receipt and screenshot are retained outside the Git repository. Submission does not resolve any pricing, capacity, billing or traffic-policy release requirement; sales remain disabled. No production machine, route, NAT/firewall, persistence setting or reconciliation-agent state changed. Existing activation and rollback requirements still apply.

## Real acceptance tests

Tests used only a private local Laravel database, synthetic `example.test` customer records, array mail/cache drivers and two explicitly named disposable LightNode instances. No production customer or website configuration was altered.

| Test | Evidence / outcome |
| --- | --- |
| Ubuntu 24.04 creation | Actual driver create → asynchronous SUCCESS → running with public IPv4 |
| Linux login/resources | Root SSH authenticated; Ubuntu 24.04.2 LTS, one CPU, about 2 GiB RAM and a 50 GiB boot disk inspected |
| Linux power/reset/reinstall | Stop confirmed; password reset and reinstall each completed; SSH authenticated again using the newly persisted password; reinstall changed the SSH host key |
| Linux release | Release SUCCESS and absence from complete inventory confirmed |
| Initial Windows request | 1 vCPU / 2 GB was below the console's Windows minimum. Response was inconclusive; no instance with that test name was found in complete inventory. Its private journal remains blocked and the POST was never repeated |
| Valid Windows creation | Separate valid 2 vCPU / 4 GB package created; asynchronous SUCCESS and running IPv4 confirmed |
| Windows access/reset | Administrator RDP authenticated; after reset the new password authenticated and a real Server Manager desktop was received |
| Windows reinstall/release | Reinstall SUCCESS confirmed; Administrator RDP authenticated using the newly committed password; release SUCCESS and absence from complete recorded region/zone inventory confirmed |

A final complete account inventory returned exactly one instance and zero acceptance-test names: both test instances were absent and the existing account-owned Manila Windows instance was preserved. No production VM, route, NAT, country-routing state, port-forward source, host firewall, DNS record or relay configuration changed.

Automated checks cover regional images, exact specification/rate matching, pagination/partial inventory, omitted live fields, network contract persistence, sale gates, duplicate/lost-response protection, conflicting lifecycle actions, task failure, Linux/Windows provisioning and atomic credential reconciliation. Initial focused validation passed **97 tests / 591 assertions**. After synchronizing with `89a59d0c`, the driver, catalog, customer pages/password, pricing, settings, billing safety, release retry and GPU delivery regression batch passed **239 tests / 1265 assertions**. Two additional LightNode power-confirmation cases passed **2 tests / 20 assertions**, totaling **241 distinct tests / 1285 assertions**. The two locale/number-format failures previously reproduced on `394da6eb` now pass with the upstream test setup. This is focused integration coverage, not a new run of the entire repository suite.

Merge reconciliation preserves upstream GPU image compatibility and observed-state billing safeguards, together with LightNode's regional images, boot-disk requirements and Windows Administrator username. An accepted pending power command remains `building` and returns unconfirmed to billing; later retries observe task completion without another power POST. Both stop and start are covered through the actual adapter and provisioner with fake HTTP, without touching provider resources. The latest ticket check still showed only the automatic receipt and **Processing**; no follow-up message was warranted yet. Sales, credentials and production state were not changed.

## Activation and rollback

1. Review this PR and outstanding pricing/traffic limitations before any deployment. Keep `LIGHTNODE_SALES_ENABLED=false` while importing the key and running catalog checks.
2. Run the additive database migration before catalog sync. Store the API token through the existing encrypted admin infrastructure settings. Install reviewed rates through the LightNode manifest field; never put credentials in that JSON.
3. Reconcile every ambiguous operation and confirm the live region/package price matrix, exact invoice rates, funding charges and a policy that prevents unpaid traffic overage. Review per-package capacity and delivery coverage. A Manila test is not proof of delivery in every region.
4. Only approved packages may get `delivery_verified=true`; enable the global sales gate and sync after the user's final approval. Existing administrative disabled-provider and plan switches still apply.
5. Roll back sales immediately by disabling the global gate and provider switch. Preserve the encrypted journal and application key while outstanding provider machines exist. Reverting code or dropping a table does **not** release paid provider instances. Reconcile/release them before any migration rollback.

Example reviewed manifest shape (illustrative amounts and package name; **not a live catalog**):

```json
{
  "regions": {
    "ph-manila-1": {"country": "PH", "city": "Manila", "location_code": "ph-manila"}
  },
  "packages": {
    "ph-manila-1|ph-manila-1-a|reviewed-package-code": {
      "hour_usd": "0.050", "month_usd": "30.00", "verified_at": 1791590400,
      "traffic_policy_verified": false, "delivery_verified": false,
      "spec": {"cpu": 2, "memory": 4, "systemDiskSize": 50, "dataDiskSize": null,
        "freeFlow": 1000, "bandwidth": null, "publicIpChargeMode": "PayByTraffic"}
    }
  }
}
```

Use the exact live `packageSpec()` output, including nulls for omitted fields, and a current verification timestamp. Static verified region mappings remain available when the settings manifest only supplies package rates. A stale or missing rate removes that plan from sale on sync and rejects new provider purchases.
