---
paths:
    - 'routes/web.php,app/Providers/AppServiceProvider.php,resources/js/pages/Administration/Products/**,resources/js/components/CatalogNavigation.vue'
---

# Products Js Components

## Separate product browsing from product changes

Staff, Manager, and Owner receive products.view for the full administration catalog, including inactive records. Staff may browse only; products.manage controls edits and activation, while products.price and catalog.delete remain owner-only. Protect GET index/show and mutation routes separately on the server, and hide unavailable actions in the UI.

## Use the storefront product catalog for Staff, Manager, and Owner

Supersedes the earlier Staff full-administration-catalog browsing rule in this file. Staff, Manager, and Owner navigate to the public /products catalog; it shows only customer-available products. Staff cannot open administration product index/show. Manager and Owner retain their existing products.manage administration access; IT remains technical-only. Administrator catalog browsing does not grant customer cart, recommendations, or chatbot access.
