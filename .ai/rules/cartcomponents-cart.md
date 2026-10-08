---
paths:
    - 'resources/js/{pages/Cart,components/cart}/**/*.vue'
---

# Cartcomponents Cart

## Keep customer cart displays server-authoritative

Render cart quantities, prices, totals, and availability from CartController props produced through CartService. Vue components may format and submit Wayfinder forms, but must not duplicate catalog eligibility, inventory availability, or total calculations.

## Aggregate selected cart summaries from server line totals

EXT-91 cart summaries may reactively aggregate selected lines' server-provided quantities and line_total strings for display. Do not recompute effective prices, discounts, stock eligibility or delivery quotes in Vue. Empty selection displays zero products, units and subtotal; checkout and placement independently recalculate authoritative totals.
