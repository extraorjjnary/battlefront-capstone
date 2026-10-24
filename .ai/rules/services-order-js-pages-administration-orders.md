---
paths:
  - 'app/Services/Order/**,resources/js/pages/Administration/Orders/**'
---

# Services Order Js Pages Administration Orders

## Assign LBC tracking references once
The developer-approved refinement supersedes delivered-reference correction: once a nonblank LBC tracking_reference is saved, it is read-only and cannot be replaced or cleared. Enforce under Order-then-Shipment locks, including handoff entry. Allow first assignment after handoff for shipments still missing a reference; preserve handler/payment/cancellation gates.
