---
paths:
    - 'app/Services/Order/**,app/Services/CustomerOrderPresenter.php,app/Http/Requests/ValidateCheckoutRequest.php,config/battlefront.php,resources/js/pages/Checkout/**,docs/*API*,docs/PSGC_DELIVERY_DATA.md'
---

# Http Requests Js Pages Checkout

## Offer COD on regional estimate routes

Preserve COD eligibility for newly regional-priced routes. COD remains proof-free and uses the saved total, including the Battlefront delivery estimate; delivery arrangement and ETA are confirmed manually, and existing explicit COD collection/remittance confirmation remains required. Do not create a Shipment at checkout or infer LBC collection from the estimate. Historical not_configured orders retain their original product-subtotal-only totals.
