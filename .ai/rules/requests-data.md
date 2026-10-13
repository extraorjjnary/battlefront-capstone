---
paths:
  - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/data/psgc-*.json'
---

# Requests Data

## Nationwide geography does not imply automatic delivery coverage
The approved nationwide enhancement supersedes the earlier 12-destination geography limit. Keep pinned local PSA-derived full-string codes and lazy parent lookups, with NCR/HUC/Manila/NIR exceptions; never call external PSGC services at checkout. DeliveryRules resolves city before province before region, and specific disabled/incomplete rules require manual quotation. Initially only the existing 12 routes have approved demo fees and GCash/Maya/COD eligibility. Preserve immutable historical addresses/fees and apply highest Standard/Fragile/Bulky surcharge once per order.
