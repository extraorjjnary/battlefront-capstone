---
paths:
    - 'app/Services/Order/**,app/Actions/Checkout/**,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/js/pages/Checkout/**,docs/*API*,docs/PSGC_DELIVERY_DATA.md'
---

# Requests Js Pages Checkout

## Allow zero-fee checkout where delivery pricing is not configured

Supersedes the earlier manual-quotation requirement for valid unconfigured nationwide destinations. Return a server-authored 0.00 delivery fee and product-subtotal total, allow GCash/Maya only, expose no ETA/carrier, create no LBC Shipment, and snapshot pricing_status=not_configured plus the no-fee/logistics assumption. Keep the 12 configured routes and their GCash/Maya/COD demo rates/workflow unchanged; explicit disabled or malformed rules still fail closed.
