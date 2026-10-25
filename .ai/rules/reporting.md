---
paths:
    - 'app/Services/CustomerRefund*,app/Actions/Inventory/HandleCustomerReturn.php,app/Services/OrderCancellationPresenter.php,app/Services/Reporting/RefundPeriodReport.php'
---

# Reporting

## Share refund payments without treating cancellations as product returns

customer_refunds has exactly one physical-return or cancellation-request source. Shared CustomerRefundPaymentService preserves original-approver confirmation, private customer-visible proof, UUID idempotency and legacy independent reviews. Cancellation payouts require GCash/Maya, an outgoing reference, actual-success confirmation and one image; cap recorded payments under locks and deduplicate reference per refund. Completion uses latest confirmed review totals, never approval or paid_amount alone. Do not change original incoming payment verification; exclude pre-sale cancellation refunds from sales-return reporting. Preserve Order-first current reads under MySQL REPEATABLE READ.
