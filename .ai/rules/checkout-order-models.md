---
paths:
    - 'app/Services/Order/**,app/Actions/{Checkout,Order}/**,app/Models/Product.php,config/battlefront.php'
---

# Checkout Order Models

## Keep EXT-86 delivery quotes configured and server-authoritative

Use DeliveryRules with battlefront.delivery for the approved Sagay-origin demo assumptions, never runtime mapping/geocoding/courier APIs or official LBC-rate claims. products.shipping_profile is the sole handling assignment source; select standard < fragile < bulky once per cart regardless of line count/quantity. Keep money as two-decimal BCMath strings, reject unsupported canonical destinations, and bypass delivery pricing for pickup. Checkout saves immutable server-calculated fee and relative ETA snapshots. Regional estimates include handling and weight estimates but require a manually confirmed arrangement; they do not create a Shipment at checkout.
