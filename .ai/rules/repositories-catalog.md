---
paths:
  - 'app/Http/Requests/ProductCatalogIndexRequest.php,app/Repositories/Catalog/**'
---

# Repositories Catalog

## Portable effective-price comparisons
Catalog price ranges compare COALESCE(discount_price, price) against decimal-cast bound parameters. Keep explicit numeric casts: SQLite feature tests otherwise compare expression values against bound strings differently from MySQL. Preserve stable name/id tie breakers for paginated price ordering.

## Keep expanded catalog query inputs mobile-only
ProductCatalogIndexRequest and ProductCatalogRepository are shared by web and mobile. Enable category_ids, min_price, max_price, and sort only for api.v1.products.index in both validation and filter extraction. Web must keep ignoring these inputs and return only q/category_id/brand/tag_id filter props; preserve default featured/name/id ordering and Inertia scroll behavior. Keep effective-price query logic shared rather than duplicating it in controllers.
