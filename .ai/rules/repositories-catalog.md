---
paths:
    - 'app/Http/Requests/ProductCatalogIndexRequest.php,app/Repositories/Catalog/**'
---

# Repositories Catalog

## Portable effective-price comparisons

Catalog price ranges compare COALESCE(discount_price, price) against decimal-cast bound parameters. Keep explicit numeric casts: SQLite feature tests otherwise compare expression values against bound strings differently from MySQL. Preserve stable name/id tie breakers for paginated price ordering.

## Keep expanded catalog query inputs mobile-only

ProductCatalogIndexRequest and ProductCatalogRepository are shared by web and mobile. Enable category_ids, min_price, max_price, and sort only for api.v1.products.index in both validation and filter extraction. Web must keep ignoring these inputs and return only q/category_id/brand/tag_id filter props; preserve default featured/name/id ordering and Inertia scroll behavior. Keep effective-price query logic shared rather than duplicating it in controllers.

## Allow approved web sorting while retaining mobile-only range and category inputs

Supersedes 'Keep expanded catalog query inputs mobile-only' for sort only. Web and mobile accept featured/price_asc/price_desc using shared effective-price ordering and stable name/id ties; category_ids/min_price/max_price remain API-only. Web discovery visibility checks only q/category_id/brand/tag_id, never sort. The web Featured showcase contains up to four manually selected customerAvailable products; never auto-feature or backfill it. Sort changes reset Inertia products to page one and preserve search/filter state.

## Keep featured discovery in the main catalog grid

The approved showcase removal supersedes the four-item standalone Featured showcase instruction above. Preserve administrator-controlled is_featured, Featured badges, default featured/name/id ordering, web price sorting, filters and Inertia scroll resets. Do not expose a featured_products prop or run a dedicated showcase query. Catalog recommendations use the full behavioral feed independently of main-grid featured products; remove showcase-based deduplication only, without changing recommendation ranking or API contracts.
