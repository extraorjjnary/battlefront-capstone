---
paths:
    - 'app/Actions/Order/**,app/Http/Controllers/**/*Order*,resources/js/pages/**/Orders/**'
---

# Js Pages Orders

## Keep payment-proof resubmission isolated

Only the owning customer may replace proof for a rejected GCash/Maya payment while the order is not completed or cancelled. Store the replacement privately, reset only payment_status to pending, clear stale rejection feedback, and send it through the existing manual admin review again. Rejection or resubmission must never change order status or inventory.
