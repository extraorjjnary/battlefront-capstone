---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/data/psgc-*.json'
---

# Requests Data

## Nationwide geography does not imply automatic delivery coverage

Keep pinned local PSA-derived full-string codes and lazy parent lookups, with NCR/HUC/Manila/NIR exceptions; never call external PSGC services at checkout. DeliveryRules resolves city before province before region. Existing local rates and specific exclusions retain precedence. Other mapped regions use fixed Battlefront regional fee estimates; unmapped/invalid pricing fails closed. Preserve immutable historical addresses/fees and apply highest Standard/Fragile/Bulky surcharge once per order.
