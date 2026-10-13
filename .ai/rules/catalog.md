---
paths:
    - resources/js/components/catalog/StockAvailability.vue
---

# Catalog

## Keep customer stock labels qualitative

Customer catalog cards and product details must display only In stock, Low stock, or Out of stock. Do not expose exact inventory quantities there; preserve the backend inventory status and low-stock calculation as the authority.

## Show authoritative customer stock quantities
The approved exact-stock UX supersedes the earlier qualitative-only labels. Catalog cards and details show current inventory.quantity alongside the server inventory.status; preserve inclusive low-stock boundaries and customerAvailable filtering. Missing inventory must never appear as an available count. Cart counts come from CartService availability, are never persisted as snapshots, and do not reserve stock; cart mutations and checkout must revalidate availability.
