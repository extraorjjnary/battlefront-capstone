---
paths:
  - 'app/Models/**,database/migrations/**'
---

# Models Migrations

## Keep customer carts isolated and inventory-eligible
Each customer may own at most one persisted cart, and carts may not belong to administrators. Cart items require an active product in an active category with inventory quantity above zero; low-stock products remain eligible. Cart mutation workflows and requested-quantity stock checks belong to EXT-28.
