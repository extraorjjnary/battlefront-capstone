---
paths:
    - 'app/Repositories/Reporting/**,app/Services/{Reporting,Dashboard}/**,app/Actions/Order/RecordCompletedOrderSale.php,resources/js/pages/{Administration/Reports,Dashboard}/**,resources/views/reports/**'
---

# Reports

## Report product revenue without rewriting historical sales

Sales reporting/dashboard/export revenue excludes third-party delivery charges. Prefer saved order product_subtotal, then purchase-time quantity × price_at_time, then saved total_amount minus delivery_fee for itemless legacy orders. Never subtract fees from sales.amount: historical amounts may include them and new completed-sale amounts exclude them. Preserve historical rows, full payable order totals, filtered item revenue, sale counts and forecasting quantities; no reset/backfill is needed.
