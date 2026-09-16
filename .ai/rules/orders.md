---
paths:
    - 'app/Http/Controllers/OrderController.php,resources/js/pages/Orders/**'
---

# Orders

## Render confirmations from owned persisted orders

After atomic placement, redirect to the GET-only orders.show page. Resolve confirmation data through the authenticated customer's orders so foreign IDs return 404, map persisted order and item fields explicitly, and never expose the private payment-proof path.

## Keep customer order history server-authoritative
Paginate customer order history newest-first through the authenticated user's orders relationship. Shape status, payment, fulfillment labels, aggregate counts, totals, and payment guidance on the server; Vue renders those values and navigates through Wayfinder. Conceal foreign customer order IDs with a 404.

## Label customer order status by fulfillment
Keep persisted order status values unchanged. Customer order history/detail label processing as Preparing for pickup/delivery and completed as Picked up / Completed or Delivered / Completed based on fulfillment_method. Admin labels and status transitions remain generic and unchanged.
