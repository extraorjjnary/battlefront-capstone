---
paths:
    - 'app/Services/Order/**,app/Actions/Order/**,app/Models/Order.php,resources/js/pages/**/Orders/**'
---

# Order Models Js Pages Orders

## Review submitted-wallet cancellation before restoring stock

Approved refinement: one customer request per own Pending GCash/Maya order with proof, before shipment preparation, regardless of incoming payment review status. Serialize on Order before current locking reads; pending review blocks ordinary order/payment/shipment writes and proof replacement. Manager/Owner with orders.view and returns.approve checks actual account receipt; rejection resumes handling. Approval atomically cancels/restores once and owes min(verified receipt, original delivery-inclusive total), preserving original payment/proof/snapshots. No receipt means no refund; excess receipt is separate manual handling.
