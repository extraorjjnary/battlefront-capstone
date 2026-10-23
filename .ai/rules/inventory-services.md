---
paths:
    - 'app/Actions/Inventory/HandleCustomerReturn*.php,app/Services/CustomerReturn*.php'
---

# Inventory Services

## Separate customer requests from physical return receipt

Customer requests reserve purchased quantities but never quarantine stock or create refunds. Allow one open request per order item until receipt/review completes; preserve receiving employee as customer_returns.requester_id for independent approval. Submission, withdrawal and both receipt paths serialize on Order before transactional snapshot reads, then OrderItem/request; conversion excludes its own reservation. Customer refund completion requires latest verified payment reviews covering a positive approved amount, never paid_amount or approval alone.
