---
paths:
  - 'app/Services/Order/**,app/Services/CustomerOrderPresenter.php,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/js/pages/Checkout/**,docs/*API*,docs/PSGC_DELIVERY_DATA.md'
---

# Http Requests Js Pages Checkout

## Offer product-subtotal COD on unconfigured nationwide routes
Supersedes the earlier GCash/Maya-only eligibility for valid routes without configured pricing. Include COD in the server quote and permit proof-free placement with delivery_fee=0.00 and total equal to product subtotal; snapshot that no delivery fee or LBC collection arrangement is represented. Do not create a Shipment or change existing COD progression/collection-confirmation rules; configured-route COD remains unchanged.
