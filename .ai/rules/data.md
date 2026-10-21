---
paths:
    - 'app/Services/Order/PsgcDeliveryLocations.php,app/Http/Requests/ValidateCheckoutRequest.php,app/Actions/Checkout/**,resources/js/pages/Checkout/**,app/Http/Resources/Api/V1/CheckoutResource.php,resources/data/psgc-delivery*.json'
---

# Data

## Use bundled PSGC geography with compatible checkout address snapshots

Keep versioned local JSON and full 10-digit string codes; barangay membership uses seven-digit locality prefixes, never the mirror's truncated parent fields. Bacolod is a HUC with no province parent. DeliveryRules remains the only coverage/fee authority and its 12 canonical keys stay unchanged.
Web delivery requires delivery_barangay_code and delivery_address_details. API v1 retains legacy delivery_address only when both new keys are absent; either key opts into complete structured validation. Compose and validate the <=255-character address server-side and retain existing order/shipment snapshots without geographic tables.
Profile addresses remain free-text checkout-only prefills: extract only exact recognizable suffixes, show ambiguous text as a reference, never duplicate location labels or update the profile during checkout. Pass local locations through existing Inertia/checkout API payloads; no runtime external PSGC fetch.
