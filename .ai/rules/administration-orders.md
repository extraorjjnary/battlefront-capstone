---
paths:
    - 'app/Actions/{Inventory,Order}/**,app/Services/Order/**,app/Http/Controllers/Administration/Order*,resources/js/pages/Administration/Orders/**'
---

# Administration Orders

## Keep administrator order processing manual and stock-neutral

Administrator order status changes allow only pending to processing/cancelled and processing to completed/cancelled; completed/cancelled are terminal. Payment decisions are manual pending-to-verified/rejected decisions, with an explicit platform/account cross-check before verification and private proof served only through authorized admin routes. These workflows must not mutate inventory; EXT-33 remains the only initial stock deduction.

## Require verified payment before order completion

An order may transition from processing to completed only while payment_status is verified. Pending or rejected payment blocks completion, and payment cannot be rejected after an order is completed. Rejecting payment changes only payment_status and must never cancel the order or mutate inventory.

## Require verified payment before order fulfillment transitions

Require verified payment before ordinary prepaid fulfillment. Approved Pending COD delivery and pay-at-pickup orders may enter Processing unpaid through their dedicated workflows; COD completes only after separate full remittance reconciliation, and pickup requires the combined payment-and-handover action. Pending or rejected prepaid payment blocks fulfillment; cancellation remains governed by the shared cancellation rules, and status processing does not mutate inventory.

## Restore stock only on explicit eligible cancellation

EXT-83 supersedes the earlier stock-neutral cancellation rule. Explicit administrator transitions from pending or processing to cancelled must restore each order item's purchased quantity in the same transaction as the status change; completed/cancelled orders remain terminal, retries must not double-restock, and payment rejection alone remains stock-neutral.
