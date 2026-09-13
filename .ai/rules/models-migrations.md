---
paths:
  - 'app/Models/**,database/migrations/**'
  - 'app/Models/{Order,OrderItem}.php,database/migrations/*_create_order*_table.php'
---

# Models Migrations

## Keep customer carts isolated and inventory-eligible
Each customer may own at most one persisted cart, and carts may not belong to administrators. Cart items require an active product in an active category with inventory quantity above zero; low-stock products remain eligible. Cart mutation workflows and requested-quantity stock checks belong to EXT-28.

## Persist approved order snapshots and manual payments
Orders belong to customers through user_id and snapshot required recipient_name, contact_number, and fulfillment_method. Fulfillment is pickup or delivery; pickup keeps delivery_address null, while delivery address remains nullable until checkout validation. Payment methods are cash, card_at_store, gcash, and maya; payment proof is a nullable private path for GCash/Maya. Order items retain product_id, positive quantity, and non-negative DECIMAL(12,2) price_at_time. Checkout, order transactions, verification, and stock deduction are separate work.
