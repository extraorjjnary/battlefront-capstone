---
paths:
    - 'resources/js/{pages/Cart,components/cart}/**/*.vue'
---

# Cartcomponents Cart

## Keep customer cart displays server-authoritative

Render cart quantities, prices, totals, and availability from CartController props produced through ManageCart. Vue components may format and submit Wayfinder forms, but must not duplicate catalog eligibility, inventory availability, or total calculations.
