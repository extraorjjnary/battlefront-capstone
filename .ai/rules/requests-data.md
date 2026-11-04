---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/data/psgc-*.json'
---

# Requests Data

## Use regional base fees independently of local fulfillment

Developer-approved nationwide-demo-v7 supersedes local city/distance-band price precedence. regional_estimates.fees is the only base-fee source for new quotes (Visayas/NIR 150, Luzon 200, Mindanao 250), plus existing weight and highest handling surcharge. Specific route precedence still controls exclusions, payments and transit; local Shipment creation and fallback manual arrangements remain unchanged. Preserve saved order fees/versions; missing regional pricing fails closed.

## Nationwide geography does not imply automatic delivery coverage

Keep pinned local PSA-derived full-string codes and lazy parent lookups, with NCR/HUC/Manila/NIR exceptions; never call external PSGC services at checkout. DeliveryRules resolves city before province before region for exclusions, payment eligibility and transit, never base-fee precedence. Use regional_estimates.fees for every new delivery base fee, plus package-weight and the highest Standard/Fragile/Bulky handling surcharge once per order. Missing or invalid regional pricing rejects new delivery; preserve immutable historical addresses, fees and versions.

## Apply configured demonstration fulfillment nationwide for new orders
Developer-approved nationwide-demo-v8 supersedes the earlier nationwide fallback-only shipment boundary: all 18 bundled PSGC regions have configured rules, so new delivery orders create AwaitingPreparation shipments with provisional ETAs. Preserve city/province/region precedence and Negros overrides; other Visayas/NIR transit is 3–7 calendar days, Luzon/Mindanao 5–10, plus existing preparation. Keep regional fees, weight/handling, payment/COD and local-only simulation gates. These are Battlefront demo estimates, not LBC serviceability or booking. Never backfill/reprice historical regional_estimate/not_configured orders; preserve their manual arrangement path.
