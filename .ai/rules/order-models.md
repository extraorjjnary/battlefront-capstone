---
paths:
    - 'app/Services/CustomerReturnEligibility.php,app/Actions/Inventory/HandleCustomerReturnRequest.php,app/Services/Order/**,app/Models/Order.php,config/battlefront.php'
---

# Order Models

## Anchor proposed return windows to actual customer receipt

The configurable seven-day (168-hour) discretionary policy remains disabled until store approval via BATTLEFRONT_RETURN_WINDOW_APPROVED. Once enabled only configured discretionary reasons expire, inclusively at receipt plus elapsed hours. Use shipments.delivered_at or immutable pickup_handed_over_at recorded at actual handover; never fabricate historical receipt dates. Other claims and missing timestamps require manual assessment. Snapshot eligibility on submission and preserve timely pending requests after expiry/configuration changes.
