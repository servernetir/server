# LightNode delivery and price audit — 2026-10-13

## Status

Seven runtime files were installed with private backups and hash guards at 08:08:37 UTC. The relevant isolated suite passed 207 tests / 1498 assertions, with no errors, failures or skips. Real acceptance in Bulgaria (Linux) and Nepal (Windows) is pending; no new QA guest or supplier charge has been created at this checkpoint. Approval is limited to USD 0.20 total supplier spend and two disposable hourly guests. Do not represent the earlier Manila acceptance as acceptance of these new countries or of checkout through the owner's account.

## Production incident and correction

The owner's October 12 Windows/Manila order failed before supplier allocation because the deployed image resolver could select an image from another region. Live CloudImage had lost the existing LightNode region/resource and Salad GPU compatibility checks during an independent deployment. Merge those checks with the live Arvan Windows access gate rather than replacing one set with the other. Reject malformed plan/location/image bindings, apply Windows minimum 2 vCPU / 4 GiB, and compare image minimum disk with the boot disk rather than total boot + data storage.

Fresh supplier quotes also differed from stored costs. Previously an increase could be rejected by the provisioning driver after the customer order was created. LightNodeOrderReview now validates the selected package, image, network contract and live price before invoice, wallet or queue effects. It locks the selected offer and relevant financial settings, preserves stock/admin gates, and requires a new customer submission after a price change. Quote failure or a concurrent setting/resource change fails closed. Unsupported automatic SSH-key delivery is rejected; password delivery remains available. The failed original order has not been retried, cancelled or refunded by this audit, and its pre-existing resources and financial history are preserved.

## Price reconciliation

The live audit found LightNode profit 5% and its supplier/FX fee override 0%. Other providers' fee overrides are independent and were not changed. Customer invoice tax remains 10%, separate from supplier VAT and profit. Audit exchange rates were USD 268460 IRT and EUR 298820 IRT, from the last recorded exchange update; these are a timestamped observation, not permanent conversion rates.

Monthly supplier costing uses hourly USD × 744, converted to EUR. Sale prices apply 5% and round upward by EUR 0.10 and IRT 10000. Hourly IRT rounds upward by IRT 100; hourly EUR rounds upward by EUR 0.01. Consequently the effective displayed markup can exceed 5%, particularly for cheap hourly EUR prices. The supplier's public calculator uses 672 hours for its calendar-month estimate. 744/672 adds a 10.714% duration reserve; with 5% profit the difference is 16.25% before rounding and customer tax. A rolling customer month can span supplier calendar months and is not guaranteed the single-calendar-month cap. No profit, reserve, rounding or tax policy was silently changed.

Official billing references: https://doc.lightnode.com/Financial/resourcebilling.html and https://doc.lightnode.com/Instance/Billingdescription.html. Partial hours round to a full supplier hour; stopped resources continue charging until release. Only owner-approved fixed-bandwidth products are eligible; do not enable traffic-priced products without a supported, verified excess-traffic limit.

## Validation and deployment

- Regression coverage: regional Linux/Windows selection, resource constraints, malformed bindings, Arvan Windows access gating, fresh quote and price-race handling, no-charge checkout rejection in Persian/English/Turkish, LightNode lifecycle and power journals, rolling/hourly billing, storefront/city/location selection, and Salad GPU compatibility.
- Runtime changes: `app/Models/CloudImage.php`, `app/Services/Cloud/LightNodeClient.php`, new `app/Services/Cloud/LightNodeOrderReview.php`, checkout controller and three language files. Contextual controller/translation edits preserve concurrent live changes. All seven PHP files passed syntax validation and the live Salad compatibility dependency was checked.
- Installation receipt: `output/lightnode-delivery-audit-20261013/bundle-install.json`, reporting unchanged financial/catalog digest during installation. Private backup/journal directory: `/home/servernetcloud/lightnode-delivery-audit-20261013-01`, outside the webroot with mode 0700. Do not repeat the completed installer.
- The read-only pre-test safeguard currently rejects a candidate and is being diagnosed before any purchase. Latest cPanel login is established; no credentials or authentication identifiers belong in this record.

No routes, VPN, NAT/firewall, DNS, host cron or routing/forward-agent state were changed. No existing guest was restarted, rebuilt or removed. Long-lived queue workers must be identified and gracefully refreshed if they cache the old code before acceptance.

## Remaining acceptance and rollback

Purchase exactly one Windows and one Linux guest through the owner's account in different countries, confirm fresh quote/resources, actual access delivery, SSH/RDP, reset, reinstall, off/on and release. Use an owned-guest watchdog with a 50-minute deadline; confirm complete inventory contains neither QA guest and still contains every original guest. No bank/OTP flow or all-country runtime guarantee is implied by these two tests.

Rollback only the seven files from their private before versions if their current hashes still equal the recorded after hashes; remove the newly added review file only with the same guard. Refresh appropriate PHP/view/worker caches without touching unrelated jobs. Do not restore a complete database, catalogue, wallet or pricing settings. Any selected-offer price rollback needs its own comparison against later changes. Preserve the combined LightNode/Salad/Arvan guards in every future deployment.
