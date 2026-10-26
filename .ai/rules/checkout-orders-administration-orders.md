---
paths:
    - 'app/Services/Order/**,app/Enums/PaymentMethod.php,app/Models/Order.php,app/Http/Controllers/Administration/Order*,resources/js/pages/{Checkout,Orders,Administration/Orders}/**'
---

# Checkout Orders Administration Orders

## Keep COD unpaid until separately reconciled remittance

The approved COD refinement supersedes prepaid-only delivery and universal advance-verification rules for COD only. COD is proof-free and delivery-only; Pending payment allows Processing and sequential shipment progression. Delivered leaves COD Processing/unpaid with no sale. The assigned handler records the saved-total collection after delivery, then separately reconciles full remittance and records its reference. Only remittance verification marks payment Verified, completes the order and records one sale atomically. Generic payment decisions cannot handle COD. Collection/remittance evidence is immutable; preserve cancellation and stock rules. Shipment status alone never establishes collection, remittance, payment, return, or refund.

## Confirm Cash/Card payment and product handover together at pickup

The approved pay-at-pickup refinement supersedes advance verification for pickup cash/card_at_store only. Pending payment allows Processing, but generic payment decisions and direct completion are blocked. Only administrator Confirm Payment & Complete Pickup, with separate accepted payment and handover confirmations, may lock Order then Shipment, verify payment, complete and record one sale atomically. Legacy verified Processing pickups use the same handover action without publishing duplicate payment verification. Preserve wallet/COD, cancellation, stock, and after-commit notification rules; keep stored payment values unchanged and card payment on a physical store terminal.
