---
paths:
  - 'app/Services/Order/**,app/Models/Shipment.php,resources/js/pages/**/Orders/**'
---

# Models Js Pages Orders

## Coordinate EXT-89 manual shipments through order processing
EXT-89 adds one-step delivery-only shipment progression, gated by verified payment and explicit Processing. OrderProcessingService locks Order then Shipment; Delivered alone atomically completes shipment/order and records the existing sale. Eligible order cancellation restores stock and terminates shipment even after handoff. Persist the operational ETA once at Preparing from saved day ranges and app timezone; never infer historical milestones. Customer web/API share read-only shipment presentation. Real references begin at handoff; no shipment notes or live courier/GPS data. Notifications remain EXT-90.
