---
paths:
  - 'database/seeders/DevelopmentSupplierSeeder.php,app/Actions/Inventory/HandleSupplierReturn.php,app/Repositories/Inventory/**,resources/js/pages/Administration/SupplierReturns.vue'
---

# Inventory Js Pages Administration

## Keep demonstration supplier assignments optional and preserve catalog history
The developer approved saved supplier-product links for development demos. Seed only the six existing catalog codes through product_supplier; never create products, refill stock, or treat demo suppliers as verified sourcing. Manual Supplier Returns filters and validates products for suppliers with assignments; suppliers without assignments retain the existing manual picker. Preserve historical return, damage/customer-return and follow-up workflows. Relationship seeding is local/testing only and additive; no operational backfill or supplier-assignment editor is approved.
