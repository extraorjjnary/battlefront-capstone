---
paths:
  - 'app/Services/Order/PsgcDeliveryLocations.php,app/Http/Requests/Settings/ProfileUpdateRequest.php,app/Models/User.php,resources/js/components/DeliveryAddressFields.vue,resources/js/pages/settings/Profile.vue,resources/js/pages/Checkout/Index.vue'
---

# Settings Js Pages Checkout

## Store optional structured profile defaults while preserving legacy addresses
Supersedes the earlier free-text-only profile default decision. Customer profile saves use the shared PSGC service and DeliveryAddressFields component, retaining readable default_delivery_address plus nullable JSON default_delivery_address_components with server-resolved city codes; keep/save/clear intent preserves ambiguous legacy text during unrelated updates. Saving requires all valid fields, clearing removes both representations, and migration never bulk-rewrites legacy text. Hide components from generic User serialization. Checkout prefers validated components, falls back only to conservative legacy suffix matching, and never writes profile defaults. API v1 legacy free-text profile updates remain compatible and replace stale components; any structured key requires complete validation unless explicit keep/clear.
