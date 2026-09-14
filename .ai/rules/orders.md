---
paths:
    - 'app/Http/Controllers/OrderController.php,resources/js/pages/Orders/**'
---

# Orders

## Render confirmations from owned persisted orders

After atomic placement, redirect to the GET-only orders.show page. Resolve confirmation data through the authenticated customer's orders so foreign IDs return 404, map persisted order and item fields explicitly, and never expose the private payment-proof path.
