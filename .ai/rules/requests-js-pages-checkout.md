---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/js/pages/Checkout/**,docs/*API*,docs/PSGC_DELIVERY_DATA.md'
---

# Requests Js Pages Checkout

## Allow zero-fee checkout where delivery pricing is not configured

Historical not_configured orders retain their saved zero-fee presentation and manual shipment handling. New valid destinations in mapped regions receive a server-authored regional fee estimate plus the highest handling surcharge; snapshot pricing_status=regional_estimate, include the saved fee in the total, show no checkout ETA, and create no Shipment until the manual arrangement is confirmed. Preserve existing configured local rates and their workflow; explicit disabled or malformed rules still fail closed.
