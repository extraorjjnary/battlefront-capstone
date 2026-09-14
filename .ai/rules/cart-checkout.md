---
paths:
    - 'app/Actions/Cart/**,app/Http/Controllers/*Checkout*,app/Http/Requests/ValidateCheckoutRequest.php,resources/js/pages/{Cart,Checkout}/**/*.vue'
---

# Cart Checkout

## Keep checkout validation separate from order placement

EXT-32 checkout is a validate-only customer flow. Recheck the authenticated customer's cart through ManageCart, allow all manual methods for pickup but only GCash/Maya for delivery, and require image proof for GCash/Maya. Do not create orders, persist proof, deduct inventory, or clear carts until the later atomic order-placement workflow.
