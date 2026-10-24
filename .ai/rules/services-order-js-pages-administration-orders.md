---
paths:
    - 'app/Services/Order/**,resources/js/pages/Administration/Orders/**'
---

# Services Order Js Pages Administration Orders

## Assign LBC tracking references once

The developer-approved refinement supersedes delivered-reference correction: once a nonblank LBC tracking_reference is saved, it is read-only and cannot be replaced or cleared. Enforce under Order-then-Shipment locks, including handoff entry. Allow first assignment after handoff for shipments still missing a reference; preserve handler/payment/cancellation gates.

## Require confirmed manual delivery before completing unconfigured orders

Delivery orders with saved pricing_status=not_configured must have a Manual Shipment at Delivered before completion; block generic completion even when no shipment exists. Preserve verified-wallet delivery completion and explicit post-delivery COD collection. Confirm delivery arrangement records an actual LBC arrangement with optional tracking and paired ETA days; a saved 0.00 delivery fee is not guaranteed free delivery. Preserve configured routes and original commercial snapshots.

## Delay wallet delivery arrangements until Processing

For unconfigured GCash/Maya deliveries, expose and accept Confirm delivery arrangement only when the order is Processing, including when payment is already verified while Pending. Enforce through shared canCreateManualShipment eligibility under the existing locks. Preserve Pending COD arrangement creation because COD requires an LBC shipment before Processing; retain all other eligibility, payment and completion gates.
