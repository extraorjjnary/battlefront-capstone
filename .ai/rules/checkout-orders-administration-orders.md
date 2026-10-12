---
paths:
  - 'app/Services/Order/**,app/Enums/PaymentMethod.php,app/Models/Order.php,app/Http/Controllers/Administration/Order*,resources/js/pages/{Checkout,Orders,Administration/Orders}/**'
---

# Checkout Orders Administration Orders

## Keep COD fulfillment unpaid until explicit delivered collection confirmation
The approved COD refinement supersedes prepaid-only delivery and universal advance-verification rules for COD only. COD is proof-free, quoted LBC delivery-only; Pending payment allows Processing and sequential shipment progression. Delivered leaves COD Processing/unpaid with no sale. Only the administrator Confirm COD Collection action, with an explicit manual confirmation, may lock Order then Shipment, verify payment, complete the order and record one sale atomically; generic payment decisions cannot handle COD. Preserve cancellation cutoffs and stock restoration rules; never infer collection, returns, refunds or carrier settlement from shipment status.

## Confirm Cash/Card payment and product handover together at pickup
The approved pay-at-pickup refinement supersedes advance verification for pickup cash/card_at_store only. Pending payment allows Processing, but generic payment decisions and direct completion are blocked. Only administrator Confirm Payment & Complete Pickup, with separate accepted payment and handover confirmations, may lock Order then Shipment, verify payment, complete and record one sale atomically. Legacy verified Processing pickups use the same handover action without publishing duplicate payment verification. Preserve wallet/COD, cancellation, stock, and after-commit notification rules; keep stored payment values unchanged and card payment on a physical store terminal.
