---
paths:
  - 'app/Actions/Cart/**,app/Services/Cart/**'
---

# Cart

## Centralize customer cart operations
Use CartService as the shared backend rule set for web and future API callers. Adds accumulate an existing line, updates replace quantity, and every mutation revalidates current product/category/inventory state without reserving stock. Calculate totals from current discount_price-or-price values with two-decimal BCMath arithmetic.
