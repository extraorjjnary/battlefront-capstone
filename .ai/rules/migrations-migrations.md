---
paths:
  - 'app/Models/{Order,Shipment}.php,app/Services/Order/**,database/factories/{Order,Shipment}Factory.php,database/migrations/*delivery_snapshots*,database/migrations/*shipments*'
---

# Migrations Migrations

## Preserve EXT-87 snapshot ownership and persistence boundary
Order owns immutable commercial quote values, its selected shipping profile and total_amount; Shipment owns carrier and relative ETA, reading the profile through Order without another copy. Use executeWithDeliveryQuote with a canonical destination for atomic quoted delivery placement; execute remains the approved web/API compatibility path until EXT-88. Initial ShipmentStatus is awaiting_preparation and implies record creation only; EXT-89 owns preparing and later transitions. Preserve nullable legacy snapshots, pickup zero fees/no Shipment, and existing payment/cancellation/sales boundaries. Do not bypass Eloquent snapshot guards with query-builder updates or reconstruct historical quotes from current configuration.
