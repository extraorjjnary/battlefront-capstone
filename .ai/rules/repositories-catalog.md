---
paths:
  - 'app/Repositories/Catalog/**'
---

# Repositories Catalog

## Portable effective-price comparisons
Catalog price ranges compare COALESCE(discount_price, price) against decimal-cast bound parameters. Keep explicit numeric casts: SQLite feature tests otherwise compare expression values against bound strings differently from MySQL. Preserve stable name/id tie breakers for paginated price ordering.
